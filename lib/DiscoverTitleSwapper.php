<?php
/**
 * DiscoverTitleSwapper — Title A/B sequencial via swap automático.
 *
 * Fluxo:
 *   T0:    publica com Titulo A (escolhido pelo Sonnet)
 *   T0+7d: gsc_aprender vê CTR baixo → swap pra Titulo B
 *   T+14d: gsc_aprender mede B → se B > A, fixa B; se B < A, swap pra C
 *   T+21d: idem
 *
 * Critérios de elegibilidade pra swap (TODOS devem ser verdadeiros):
 *   1. Trend tem `titulo_variantes` (gerado por DiscoverTitleVariantes na publicação)
 *   2. Pelo menos 1 variante AINDA NÃO foi testada
 *   3. Idade do título atual >= MIN_DIAS_TESTE (default 7d)
 *   4. CTR do título atual < CTR_MAX_SWAP (default 1%)
 *   5. Posição média top 10 (senão problema é outro, não título)
 *   6. Total de swaps < MAX_SWAPS (default 2 = original + B + C)
 *
 * Swap é barato: WP REST atualizarPost com novo title. URL e conteúdo preservados
 * → PageRank/links/backlinks intactos. Google reindexa em 24-48h.
 *
 * Histórico salvo no payload do trend:
 *   title_swap_history: [{de, para, em, ctr_anterior, posicao_anterior, impressions}]
 *
 * Uso (em gsc_aprender, antes do Reviewer):
 *   $r = DiscoverTitleSwapper::tentarSwap($trend, $stats, $cfg, $db, $wp);
 *   if ($r['acao'] === 'swap') {
 *       // Title trocado. Pula Reviewer (mais caro).
 *   }
 */

class DiscoverTitleSwapper
{
    public const MIN_DIAS_TESTE = 7;
    public const CTR_MAX_SWAP   = 1.0;   // %
    public const POS_MAX_SWAP   = 10.0;  // posição média
    public const IMP_MIN_SWAP   = 50;    // impressões mínimas pra ter sinal
    public const MAX_SWAPS      = 2;

    /**
     * Avalia + executa swap. Retorna metadados pra log.
     *
     * @param array $trend record completo do DB
     * @param array $stats {ctr_pct, impressions, clicks, position}
     * @param array $cfg cfg do site
     * @param object $db DiscoverDb
     * @param object $wp Wordpress
     * @return array {acao: 'swap'|'skip', motivo: string, ...}
     */
    public static function tentarSwap(array $trend, array $stats, array $cfg, $db, $wp): array
    {
        $trendId = (int)($trend['id'] ?? 0);
        $postId  = (int)($trend['post_id'] ?? 0);
        $tituloAtual = (string)($trend['titulo'] ?? '');
        $variantes = $trend['titulo_variantes'] ?? [];
        $historico = $trend['title_swap_history'] ?? [];

        if ($trendId === 0 || $postId === 0 || $tituloAtual === '') {
            return ['acao' => 'skip', 'motivo' => 'trend incompleto'];
        }
        if (!is_array($variantes) || empty($variantes)) {
            return ['acao' => 'skip', 'motivo' => 'sem variantes alternativas'];
        }
        if (count($historico) >= self::MAX_SWAPS) {
            return ['acao' => 'skip', 'motivo' => 'max swaps atingido (' . self::MAX_SWAPS . ')'];
        }

        // Idade do título atual: data do último swap, ou publicação se nunca houve swap
        $tsTituloDesde = self::tsTituloAtual($trend, $historico);
        $diasTitulo = $tsTituloDesde > 0 ? (int)floor((time() - $tsTituloDesde) / 86400) : 999;
        if ($diasTitulo < self::MIN_DIAS_TESTE) {
            return ['acao' => 'skip', 'motivo' => "título atual só tem {$diasTitulo}d (mín " . self::MIN_DIAS_TESTE . ')'];
        }

        // Critérios de "underperformance": precisa de sinal estatístico mínimo
        $imp = (int)($stats['impressions'] ?? 0);
        $ctr = (float)($stats['ctr_pct'] ?? 0);
        $pos = (float)($stats['position'] ?? 99);
        if ($imp < self::IMP_MIN_SWAP) {
            return ['acao' => 'skip', 'motivo' => "impressions={$imp} insuficientes"];
        }
        if ($pos > self::POS_MAX_SWAP) {
            return ['acao' => 'skip', 'motivo' => "posição {$pos} fora top 10 (problema não é título)"];
        }
        if ($ctr >= self::CTR_MAX_SWAP) {
            return ['acao' => 'skip', 'motivo' => "CTR {$ctr}% acima do threshold"];
        }

        // Escolhe próxima variante NÃO testada
        $jaTestadas = [];
        foreach ($historico as $h) {
            $jaTestadas[] = mb_strtolower((string)($h['para'] ?? ''), 'UTF-8');
        }
        $jaTestadas[] = mb_strtolower($tituloAtual, 'UTF-8');

        $proxima = null;
        foreach ($variantes as $v) {
            $v = trim((string)$v);
            if ($v === '') continue;
            if (in_array(mb_strtolower($v, 'UTF-8'), $jaTestadas, true)) continue;
            $proxima = $v;
            break;
        }
        if ($proxima === null) {
            return ['acao' => 'skip', 'motivo' => 'todas variantes já testadas'];
        }

        // Executa swap (WP REST). Idempotente — atualizar com mesmo title é no-op.
        // Se cfg tem cloudflare_zone_id + .env tem CLOUDFLARE_API_TOKEN, purga URL
        // no edge automaticamente (mudança visível em segundos, não em horas).
        try {
            if (method_exists($wp, 'atualizarPost')) {
                $wp->atualizarPost($postId, ['title' => $proxima], $cfg);
            } elseif (method_exists($wp, 'updatePost')) {
                $wp->updatePost($postId, ['title' => $proxima]);
            } else {
                return ['acao' => 'skip', 'motivo' => 'wp sem método atualizarPost'];
            }
        } catch (Throwable $e) {
            return ['acao' => 'skip', 'motivo' => 'erro WP: ' . $e->getMessage()];
        }

        // Persiste histórico e novo título atual
        $entradaHistorico = [
            'de'                => $tituloAtual,
            'para'              => $proxima,
            'em'                => date('Y-m-d H:i:s'),
            'ctr_anterior'      => round($ctr, 3),
            'posicao_anterior'  => round($pos, 2),
            'impressions'       => $imp,
            'clicks'            => (int)($stats['clicks'] ?? 0),
            'dias_testado'      => $diasTitulo,
        ];
        $novoHistorico = array_merge(is_array($historico) ? $historico : [], [$entradaHistorico]);

        try {
            $db->updateStatus($trendId, (string)($trend['status'] ?? 'publicado'), [
                'titulo'             => $proxima,
                'title_swap_history' => $novoHistorico,
                'title_swap_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) { /* DB não persistiu mas WP já fez swap; segue */ }

        return [
            'acao'         => 'swap',
            'motivo'       => "ctr {$ctr}% < " . self::CTR_MAX_SWAP . '% após ' . $diasTitulo . 'd',
            'titulo_de'    => $tituloAtual,
            'titulo_para'  => $proxima,
            'historico_n'  => count($novoHistorico),
        ];
    }

    /** Timestamp do último swap, ou da publicação se nunca houve swap. */
    private static function tsTituloAtual(array $trend, array $historico): int
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
