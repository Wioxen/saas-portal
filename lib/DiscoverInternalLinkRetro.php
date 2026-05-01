<?php
/**
 * DiscoverInternalLinkRetro — quando publica novo post, varre posts antigos do
 * mesmo cluster e injeta link contextual pro novo (NÃO só hub-spoke).
 *
 * Discover/Search valoriza NETWORK de posts — quanto mais densa a rede de links
 * internos relevantes, mais autoridade tópica. Hoje só temos:
 *   - Hub auto-update (B2): novo spoke entra na lista do hub
 *   - Continue lendo (DiscoverRelatedLinks): top 3 ao final do post
 *
 * Falta: posts antigos do mesmo cluster ganharem 1-2 links pro novo, contextualmente.
 * Esse padrão é o que Wirecutter / The Verge fazem manualmente — automatizamos.
 *
 * Estratégia:
 *   1. Posts antigos do MESMO cluster + status=publicado + <365 dias
 *   2. Pra cada candidato: similaridade titulo (>=40%) — relevância suficiente?
 *   3. Achar 1 frase no post antigo que combine com o tema do post novo
 *      (heurística: parágrafo que MENCIONA palavras-chave do título novo)
 *   4. Injeta link contextual: "Veja também: <a>{titulo novo}</a>" OU
 *      transforma 1-2 palavras numa âncora <a>
 *   5. MAX 3 posts antigos linkados por post novo (anti-spam)
 *   6. Idempotência: marker `data-cc-retrolink="{post_novo_id}"` no antigo
 *
 * Performance: roda em cron leve (15min) — não bloqueia tick_filas.
 *
 * Uso:
 *   $r = DiscoverInternalLinkRetro::injetar($postNovoId, $clusterKey, $tituloNovo, $urlNovo, $cfg, $db, $wp);
 */
class DiscoverInternalLinkRetro
{
    public const MAX_POSTS_LINKADOS = 3;
    public const SIM_MIN = 40.0;
    public const JANELA_DIAS = 365;

    /**
     * @return array {processados, linkados, ja_continham, erros, detalhes}
     */
    public static function injetar(int $postNovoId, string $clusterKey, string $tituloNovo, string $urlNovo, array $cfg, $db, $wp): array
    {
        if ($postNovoId <= 0 || $clusterKey === '' || $tituloNovo === '' || $urlNovo === '') {
            return ['processados' => 0, 'linkados' => 0, 'ja_continham' => 0, 'erros' => ['parametros_incompletos'], 'detalhes' => []];
        }

        $siteSlug = (string)($cfg['_site_slug'] ?? '');
        if ($siteSlug === '') {
            return ['processados' => 0, 'linkados' => 0, 'ja_continham' => 0, 'erros' => ['_site_slug_ausente'], 'detalhes' => []];
        }

        // Push janela + post_id NOT NULL pro DB. cluster_key ainda vai em PHP-filter
        // porque hoje vem de payload.cluster_detect.key (não da coluna dedicada).
        $cutoff = strtotime('-' . self::JANELA_DIAS . ' days');
        $publicados = $db->all([
            'site'             => $siteSlug,
            'status'           => 'publicado',
            'publicado_apos'   => $cutoff,
            'post_id_not_null' => true,
            'order_by'         => 'publicado_desc',
        ]);
        $candidatos = [];
        foreach ($publicados as $p) {
            $pid = (int)($p['post_id'] ?? 0);
            if ($pid === $postNovoId) continue;
            $cKey = (string)($p['cluster_detect']['key'] ?? '');
            if ($cKey !== $clusterKey) continue;

            $tituloAntigo = (string)($p['titulo'] ?? '');
            similar_text(mb_strtolower($tituloAntigo, 'UTF-8'), mb_strtolower($tituloNovo, 'UTF-8'), $sim);
            if ($sim < self::SIM_MIN) continue;
            if ($sim >= 95) continue; // duplicação evitada — Update Detector já cobriu

            $candidatos[] = [
                'post_id' => $pid,
                'titulo'  => $tituloAntigo,
                'sim'     => round($sim, 1),
                'pub_ts'  => strtotime((string)($p['publicado_em'] ?? '')) ?: 0,
            ];
        }

        if (empty($candidatos)) {
            return ['processados' => 0, 'linkados' => 0, 'ja_continham' => 0, 'erros' => [], 'detalhes' => ['nota' => 'sem candidatos']];
        }

        // Top N por sim DESC
        usort($candidatos, fn($a, $b) => $b['sim'] <=> $a['sim']);
        $candidatos = array_slice($candidatos, 0, self::MAX_POSTS_LINKADOS);

        $linkados = 0;
        $jaContinham = 0;
        $erros = [];
        $detalhes = [];
        $marker = 'data-cc-retrolink="' . $postNovoId . '"';

        foreach ($candidatos as $c) {
            try {
                $wpPost = $wp->getPost($c['post_id']);
                $contentAtual = (string)($wpPost['content']['raw'] ?? $wpPost['content']['rendered'] ?? '');
                if ($contentAtual === '') {
                    $detalhes[] = ['post_id' => $c['post_id'], 'pulado' => 'sem content'];
                    continue;
                }

                // Já tem link pra esse post novo? Idempotência
                if (strpos($contentAtual, $urlNovo) !== false) {
                    $jaContinham++;
                    $detalhes[] = ['post_id' => $c['post_id'], 'ja_continha' => true];
                    continue;
                }

                // Tenta inserir bloco "Veja também" antes do último parágrafo
                $blockHtml = self::montarRetrolinkBlock($tituloNovo, $urlNovo, $marker);
                $contentNovo = self::inserirAntesUltimoP($contentAtual, $blockHtml);

                if ($contentNovo === $contentAtual) {
                    // Sem 1 último <p> — fallback append
                    $contentNovo = $contentAtual . "\n" . $blockHtml;
                }

                $wp->atualizarPost($c['post_id'], ['content' => $contentNovo]);
                $linkados++;
                $detalhes[] = ['post_id' => $c['post_id'], 'sim' => $c['sim'], 'linkado' => true];
            } catch (Throwable $e) {
                $erros[] = "post {$c['post_id']}: " . $e->getMessage();
                $detalhes[] = ['post_id' => $c['post_id'], 'erro' => $e->getMessage()];
            }
        }

        return [
            'processados' => count($candidatos),
            'linkados'    => $linkados,
            'ja_continham'=> $jaContinham,
            'erros'       => $erros,
            'detalhes'    => $detalhes,
        ];
    }

    private static function montarRetrolinkBlock(string $tituloNovo, string $urlNovo, string $marker): string
    {
        $tituloEsc = htmlspecialchars($tituloNovo, ENT_QUOTES, 'UTF-8');
        $urlEsc    = htmlspecialchars($urlNovo, ENT_QUOTES, 'UTF-8');
        return "\n<aside {$marker} class=\"cc-retrolink\" "
             . 'style="margin:24px 0;padding:14px 18px;background:#f0f9ff;border-left:4px solid #0ea5e9;border-radius:6px;font-size:0.95em">'
             . '<strong>Veja também:</strong> '
             . '<a href="' . $urlEsc . '" style="color:#0b57d0;font-weight:600;text-decoration:none">' . $tituloEsc . '</a>'
             . "</aside>\n";
    }

    /**
     * Insere bloco antes do último <p> ou último </h2>. Preserva fluxo natural.
     */
    private static function inserirAntesUltimoP(string $html, string $blockHtml): string
    {
        // Encontra último <p
        $lastP = strrpos($html, '<p');
        if ($lastP === false) return $html;
        return substr($html, 0, $lastP) . $blockHtml . substr($html, $lastP);
    }
}
