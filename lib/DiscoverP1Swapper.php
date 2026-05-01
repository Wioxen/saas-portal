<?php
/**
 * DiscoverP1Swapper — A/B sequencial do PRIMEIRO PARÁGRAFO via swap.
 *
 * Mesma lógica do TitleSwapper mas troca o 1º `<p>` do conteúdo via WP REST.
 * Discover usa o P1 como SNIPPET (preview do card). Quando título empata, o P1
 * decide o clique.
 *
 * Critérios pra elegibilidade (TODOS verdadeiros):
 *   1. Trend tem `p1_variantes`
 *   2. Pelo menos 1 variante AINDA NÃO testada
 *   3. Idade do P1 atual >= MIN_DIAS_TESTE (7d)
 *   4. CTR atual < CTR_MAX_SWAP (1%)
 *   5. Posição top 10 (problema é preview, não ranking)
 *   6. Total de swaps < MAX_SWAPS (2)
 *   7. **Title Swap NÃO disponível ou já esgotado** (ordem: title primeiro, P1 depois)
 *
 * Esse último critério é importante: trocar título tem impacto MAIOR no CTR e
 * é mais barato (só title vs HTML inteiro). P1 swap é "última cartada".
 *
 * Histórico em `payload.p1_swap_history`.
 *
 * Uso (em gsc_aprender, depois do TitleSwapper):
 *   $r = DiscoverP1Swapper::tentarSwap($trend, $stats, $cfg, $db, $wp);
 */

class DiscoverP1Swapper
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
        $variantes = $trend['p1_variantes'] ?? [];
        $historico = $trend['p1_swap_history'] ?? [];

        if ($trendId === 0 || $postId === 0) {
            return ['acao' => 'skip', 'motivo' => 'trend incompleto'];
        }
        if (!is_array($variantes) || empty($variantes)) {
            return ['acao' => 'skip', 'motivo' => 'sem variantes de P1'];
        }
        if (count($historico) >= self::MAX_SWAPS) {
            return ['acao' => 'skip', 'motivo' => 'max P1 swaps atingido'];
        }

        // Title Swap em curso (variantes ainda disponíveis) → espera
        $titleVariantes = $trend['titulo_variantes'] ?? [];
        $titleHist = $trend['title_swap_history'] ?? [];
        if (is_array($titleVariantes) && count($titleVariantes) > count($titleHist)) {
            return ['acao' => 'skip', 'motivo' => 'title swap ainda tem variantes — prioridade'];
        }

        // Stats mínimos pra decidir
        $imp = (int)($stats['impressions'] ?? 0);
        $ctr = (float)($stats['ctr_pct'] ?? 0);
        $pos = (float)($stats['position'] ?? 99);
        if ($imp < self::IMP_MIN_SWAP)  return ['acao' => 'skip', 'motivo' => "impressions={$imp} insuficientes"];
        if ($pos > self::POS_MAX_SWAP)  return ['acao' => 'skip', 'motivo' => "posição {$pos} fora top 10"];
        if ($ctr >= self::CTR_MAX_SWAP) return ['acao' => 'skip', 'motivo' => "CTR {$ctr}% acima do threshold"];

        // Idade desde último swap (ou publicação)
        $tsP1 = self::tsP1Atual($trend, $historico);
        $diasP1 = $tsP1 > 0 ? (int)floor((time() - $tsP1) / 86400) : 999;
        if ($diasP1 < self::MIN_DIAS_TESTE) {
            return ['acao' => 'skip', 'motivo' => "P1 atual só tem {$diasP1}d (mín " . self::MIN_DIAS_TESTE . ')'];
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
            return ['acao' => 'skip', 'motivo' => 'todas variantes de P1 já testadas'];
        }

        // Pega o post atual, troca o 1º <p>, atualiza
        try {
            $post = method_exists($wp, 'getPost') ? $wp->getPost($postId) : null;
            if (!$post || empty($post['content']['rendered']) && empty($post['content']['raw'])) {
                // Tenta interface alternativa
                if (method_exists($wp, 'lerPost')) $post = $wp->lerPost($postId);
            }
            $contentAtual = (string)($post['content']['raw']
                ?? $post['content']['rendered']
                ?? $post['content']
                ?? '');
            if ($contentAtual === '') {
                return ['acao' => 'skip', 'motivo' => 'não foi possível ler conteúdo do post'];
            }
        } catch (Throwable $e) {
            return ['acao' => 'skip', 'motivo' => 'erro ao ler post: ' . $e->getMessage()];
        }

        $contentNovo = self::substituirPrimeiroParagrafo($contentAtual, $proxima);
        if ($contentNovo === $contentAtual) {
            return ['acao' => 'skip', 'motivo' => 'não foi possível substituir P1 (estrutura inesperada)'];
        }

        try {
            if (method_exists($wp, 'atualizarPost')) {
                // Cloudflare purge auto se cfg tem cloudflare_zone_id
                $wp->atualizarPost($postId, ['content' => $contentNovo], $cfg);
            } elseif (method_exists($wp, 'updatePost')) {
                $wp->updatePost($postId, ['content' => $contentNovo]);
            } else {
                return ['acao' => 'skip', 'motivo' => 'wp sem método atualizarPost'];
            }
        } catch (Throwable $e) {
            return ['acao' => 'skip', 'motivo' => 'erro WP: ' . $e->getMessage()];
        }

        $entrada = [
            'em'                => date('Y-m-d H:i:s'),
            'para_first50'      => mb_substr($proxima, 0, 50, 'UTF-8'),
            'ctr_anterior'      => round($ctr, 3),
            'posicao_anterior'  => round($pos, 2),
            'impressions'       => $imp,
            'clicks'            => (int)($stats['clicks'] ?? 0),
            'dias_testado'      => $diasP1,
        ];
        $novoHistorico = array_merge(is_array($historico) ? $historico : [], [$entrada]);

        try {
            $db->updateStatus($trendId, (string)($trend['status'] ?? 'publicado'), [
                'p1_swap_history' => $novoHistorico,
                'p1_swap_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) { /* DB falhou mas WP já fez swap */ }

        return [
            'acao'        => 'swap',
            'motivo'      => "ctr {$ctr}% < " . self::CTR_MAX_SWAP . '% após ' . $diasP1 . 'd',
            'p1_para'     => $proxima,
            'historico_n' => count($novoHistorico),
        ];
    }

    /**
     * Substitui o conteúdo do 1º `<p>...</p>` por novo P1.
     * Preserva atributos do <p> original (class, id) e tudo após o 1º </p>.
     * Retorna HTML novo, ou idêntico ao original se não conseguiu localizar.
     */
    public static function substituirPrimeiroParagrafo(string $html, string $novoP1): string
    {
        $novoP1Esc = htmlspecialchars($novoP1, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
        // Match do 1º <p ...> ... </p> (não-greedy)
        if (!preg_match('/<p\b[^>]*>([\s\S]*?)<\/p>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $tagOpenInicio = (int)$m[0][1];
        $tagOpenLen    = strlen($m[0][0]);
        // Reconstrói: tudo antes do <p>, novo <p ...>NOVO</p>, tudo depois
        // Preserva os atributos: pega só a tag de abertura
        if (!preg_match('/<p\b([^>]*)>/i', $m[0][0], $a)) return $html;
        $atributos = $a[1];
        $novoBloco = '<p' . $atributos . '>' . $novoP1Esc . '</p>';

        return substr($html, 0, $tagOpenInicio) . $novoBloco . substr($html, $tagOpenInicio + $tagOpenLen);
    }

    private static function tsP1Atual(array $trend, array $historico): int
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
