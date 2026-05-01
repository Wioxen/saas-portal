<?php
/**
 * DiscoverMetaSwapper — A/B sequencial de meta_description via swap (Yoast/RankMath/SEOPress).
 *
 * Hierarquia em gsc_aprender (cheap → expensive):
 *   1) Title Swap   (apenas title)
 *   2) P1 Swap      (apenas 1º <p> do content)
 *   3) Meta Swap    (apenas meta_description — não muda content)   ← AQUI
 *   4) Reviewer     (Sonnet reescreve tudo)
 *
 * Por que Meta vem DEPOIS de Title/P1: title é o sinal #1 de CTR, P1 é #2 (snippet
 * fallback). Meta_description só é EXIBIDA quando Google não usa o P1 — cobre os
 * casos restantes.
 *
 * Critérios de elegibilidade (TODOS verdadeiros):
 *   1. Trend tem `meta_tags.meta_description_variantes` (>=1 variante)
 *   2. Pelo menos 1 variante AINDA NÃO testada
 *   3. Idade da meta atual >= MIN_DIAS_TESTE (7d)
 *   4. CTR atual < CTR_MAX_SWAP (1%)
 *   5. Posição top 10
 *   6. Title Swap E P1 Swap esgotados (esperam concluir antes)
 *   7. Total de meta swaps < MAX_SWAPS (2)
 *
 * Histórico em `payload.meta_swap_history`.
 */

class DiscoverMetaSwapper
{
    public const MIN_DIAS_TESTE = 7;
    public const CTR_MAX_SWAP   = 1.0;
    public const POS_MAX_SWAP   = 10.0;
    public const IMP_MIN_SWAP   = 50;
    public const MAX_SWAPS      = 2;

    public static function tentarSwap(array $trend, array $stats, array $cfg, $db, $wp): array
    {
        $trendId = (int)($trend['id'] ?? 0);
        $postId  = (int)($trend['post_id'] ?? 0);
        $metaTags = $trend['meta_tags'] ?? [];
        $variantes = is_array($metaTags) ? ($metaTags['meta_description_variantes'] ?? []) : [];
        $historico = $trend['meta_swap_history'] ?? [];

        if ($trendId === 0 || $postId === 0) {
            return ['acao' => 'skip', 'motivo' => 'trend incompleto'];
        }
        if (!is_array($variantes) || empty($variantes)) {
            return ['acao' => 'skip', 'motivo' => 'sem variantes de meta_description'];
        }
        if (count($historico) >= self::MAX_SWAPS) {
            return ['acao' => 'skip', 'motivo' => 'max meta swaps atingido'];
        }

        // Title E P1 swaps em curso → meta espera
        $tVars = $trend['titulo_variantes']    ?? [];
        $tHist = $trend['title_swap_history']  ?? [];
        if (is_array($tVars) && count($tVars) > count($tHist)) {
            return ['acao' => 'skip', 'motivo' => 'title swap pendente — prioridade'];
        }
        $pVars = $trend['p1_variantes']    ?? [];
        $pHist = $trend['p1_swap_history'] ?? [];
        if (is_array($pVars) && count($pVars) > count($pHist)) {
            return ['acao' => 'skip', 'motivo' => 'p1 swap pendente — prioridade'];
        }

        // Stats mínimos
        $imp = (int)($stats['impressions'] ?? 0);
        $ctr = (float)($stats['ctr_pct'] ?? 0);
        $pos = (float)($stats['position'] ?? 99);
        if ($imp < self::IMP_MIN_SWAP)  return ['acao' => 'skip', 'motivo' => "impressions={$imp} insuficientes"];
        if ($pos > self::POS_MAX_SWAP)  return ['acao' => 'skip', 'motivo' => "posição {$pos} fora top 10"];
        if ($ctr >= self::CTR_MAX_SWAP) return ['acao' => 'skip', 'motivo' => "CTR {$ctr}% acima do threshold"];

        // Idade desde último swap (ou publicação)
        $tsMeta = self::tsMetaAtual($trend, $historico);
        $diasMeta = $tsMeta > 0 ? (int)floor((time() - $tsMeta) / 86400) : 999;
        if ($diasMeta < self::MIN_DIAS_TESTE) {
            return ['acao' => 'skip', 'motivo' => "meta atual só tem {$diasMeta}d (mín " . self::MIN_DIAS_TESTE . ')'];
        }

        // Próxima variante NÃO testada
        $jaTestadas = [];
        foreach ($historico as $h) $jaTestadas[] = mb_strtolower((string)($h['para_first50'] ?? ''), 'UTF-8');

        $proxima = null;
        foreach ($variantes as $v) {
            $v = trim((string)$v);
            if ($v === '') continue;
            $first50 = mb_strtolower(mb_substr($v, 0, 50, 'UTF-8'), 'UTF-8');
            if (in_array($first50, $jaTestadas, true)) continue;
            $proxima = $v;
            break;
        }
        if ($proxima === null) {
            return ['acao' => 'skip', 'motivo' => 'todas variantes de meta já testadas'];
        }

        // Aplica via Yoast/RankMath/SEOPress meta keys (purga Cloudflare se cfg tem zone_id)
        $novaTags = is_array($metaTags) ? $metaTags : [];
        $novaTags['meta_description'] = $proxima;
        require_once __DIR__ . '/DiscoverMetaTags.php';
        $okWp = DiscoverMetaTags::aplicarNoWp($wp, $postId, $novaTags, $cfg);
        if (!$okWp) {
            return ['acao' => 'skip', 'motivo' => 'WP rejeitou update de meta'];
        }

        $entrada = [
            'em'                => date('Y-m-d H:i:s'),
            'para_first50'      => mb_substr($proxima, 0, 50, 'UTF-8'),
            'ctr_anterior'      => round($ctr, 3),
            'posicao_anterior'  => round($pos, 2),
            'impressions'       => $imp,
            'clicks'            => (int)($stats['clicks'] ?? 0),
            'dias_testado'      => $diasMeta,
        ];
        $novoHistorico = array_merge(is_array($historico) ? $historico : [], [$entrada]);

        try {
            $db->updateStatus($trendId, (string)($trend['status'] ?? 'publicado'), [
                'meta_tags'         => $novaTags,
                'meta_swap_history' => $novoHistorico,
                'meta_swap_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) { /* DB falhou mas WP já fez swap */ }

        return [
            'acao'        => 'swap',
            'motivo'      => "ctr {$ctr}% < " . self::CTR_MAX_SWAP . '% após ' . $diasMeta . 'd',
            'meta_para'   => $proxima,
            'historico_n' => count($novoHistorico),
        ];
    }

    private static function tsMetaAtual(array $trend, array $historico): int
    {
        if (!empty($historico)) {
            $ult = end($historico);
            if (is_array($ult) && !empty($ult['em'])) {
                $ts = strtotime((string)$ult['em']);
                if ($ts) return $ts;
            }
        }
        $tsPub = strtotime((string)($trend['publicado_em'] ?? ''));
        return $tsPub ?: 0;
    }
}
