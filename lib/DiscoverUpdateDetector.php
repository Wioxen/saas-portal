<?php
/**
 * DiscoverUpdateDetector — detecta se trend novo deve ATUALIZAR post existente vs criar novo.
 *
 * Hoje: novo trend = novo post, mesmo se temos post nosso sobre o mesmo tema.
 * Risco: cada post novo começa do zero (PageRank, idade, backlinks). Atualizar
 * post existente PRESERVA tudo + sinaliza freshness pro Discover.
 *
 * Lógica:
 *   1. Pra trend novo, calcula similar_text com posts publicados <90d do mesmo site
 *   2. Se algum post tem similaridade >= 70% E foi publicado <90d
 *      → recomenda UPDATE (chama DiscoverReviewer no post existente)
 *   3. Se < 70% → CREATE NEW (caminho normal)
 *
 * Não toca DB direto — só recomenda. DiscoverGerador decide o que fazer.
 *
 * Uso (em DiscoverGerador antes do Sonnet):
 *   $rec = DiscoverUpdateDetector::analisar($termo, $site, $db);
 *   if ($rec['acao'] === 'update' && $rec['post_existente']) {
 *       // chama DiscoverReviewer pra atualizar post_id existente
 *       // pula geração nova
 *   }
 */

class DiscoverUpdateDetector
{
    /** Threshold de similaridade pra recomendar UPDATE em vez de CREATE. */
    public const SIM_UPDATE_THRESHOLD = 70.0;

    /** Janela em dias — só atualiza posts publicados nos últimos N dias. */
    public const JANELA_DIAS = 90;

    /**
     * @return array {acao: 'create'|'update', post_existente?: array, similaridade?: float, motivo: string}
     */
    public static function analisar(string $termoNovo, string $site, $db): array
    {
        $termoNovo = trim($termoNovo);
        if ($termoNovo === '' || $site === '') {
            return ['acao' => 'create', 'motivo' => 'parametros incompletos'];
        }

        // Push janela pro DB — usa idx_site_status + idx_publicado_em em vez de scan
        $cutoff = strtotime('-' . self::JANELA_DIAS . ' days');
        $publicados = $db->all([
            'site'           => $site,
            'status'         => 'publicado',
            'publicado_apos' => $cutoff,
            'order_by'       => 'publicado_desc',
        ]);
        if (empty($publicados)) {
            return ['acao' => 'create', 'motivo' => 'site sem histórico recente'];
        }

        $termoNovoLow = mb_strtolower($termoNovo, 'UTF-8');
        $termoNovoBag = self::normalizarBag($termoNovoLow);

        $melhor = null;
        $melhorSim = 0;
        foreach ($publicados as $p) {
            $termoExistente = (string)($p['termo'] ?? '');
            $tituloExistente = (string)($p['titulo'] ?? '');

            // 4 estratégias: similar direto + word-bag (insensível a ordem)
            similar_text($termoNovoLow, mb_strtolower($termoExistente, 'UTF-8'), $simT);
            similar_text($termoNovoLow, mb_strtolower($tituloExistente, 'UTF-8'), $simTit);
            similar_text($termoNovoBag, self::normalizarBag(mb_strtolower($termoExistente, 'UTF-8')), $simBagT);
            similar_text($termoNovoBag, self::normalizarBag(mb_strtolower($tituloExistente, 'UTF-8')), $simBagTit);

            $sim = max($simT, $simTit, $simBagT, $simBagTit);

            if ($sim > $melhorSim) {
                $melhorSim = $sim;
                $melhor = $p;
            }
            if ($melhorSim >= 95) break;
        }

        if ($melhor === null || $melhorSim < self::SIM_UPDATE_THRESHOLD) {
            return [
                'acao'   => 'create',
                'motivo' => 'sem post similar suficiente (max sim=' . round($melhorSim, 1) . '%)',
            ];
        }

        // Recomenda UPDATE
        $diasDesdePub = $melhor && !empty($melhor['publicado_em'])
            ? (int)floor((time() - strtotime($melhor['publicado_em'])) / 86400)
            : 0;

        return [
            'acao'             => 'update',
            'post_existente'   => [
                'id'           => (int)($melhor['id'] ?? 0),
                'post_id'      => (int)($melhor['post_id'] ?? 0),
                'termo'        => (string)($melhor['termo'] ?? ''),
                'titulo'       => (string)($melhor['titulo'] ?? ''),
                'url_post'     => (string)($melhor['url_post'] ?? ''),
                'publicado_em' => (string)($melhor['publicado_em'] ?? ''),
                'dias_desde'   => $diasDesdePub,
            ],
            'similaridade'     => round($melhorSim, 1),
            'motivo'           => sprintf(
                "post #%d ('%s', %dd) tem %s%% sim com novo termo — atualizar preserva PageRank + sinaliza freshness",
                (int)($melhor['post_id'] ?? 0),
                mb_substr((string)($melhor['titulo'] ?? ''), 0, 60),
                $diasDesdePub,
                round($melhorSim, 1)
            ),
        ];
    }

    /**
     * Word-bag normalizada: lower + remove acentos + ordena palavras alfabeticamente.
     * Permite comparar "X Y Z" vs "Z Y X" como similares (similar_text é sensível à ordem).
     */
    private static function normalizarBag(string $s): string
    {
        // Remove acentos
        $de = ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ'];
        $pa = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'];
        $s = str_replace($de, $pa, $s);
        // Tira pontuação
        $s = preg_replace('/[^\w\s]+/u', ' ', $s) ?? $s;
        // Tokeniza + ordena alfabeticamente
        $palavras = preg_split('/\s+/u', trim($s)) ?: [];
        $palavras = array_values(array_filter($palavras, fn($p) => $p !== ''));
        sort($palavras);
        return implode(' ', $palavras);
    }
}
