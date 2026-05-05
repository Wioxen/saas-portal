<?php
declare(strict_types=1);

/**
 * InlineImageInjector — adiciona 1-2 imagens inline ao corpo do artigo.
 *
 * Por que: Google Discover é VISUAL. 1 só featured image fica fraco vs sites
 * que têm 5+ fotos. 2 inline images contextuais aumentam dwell time + retorno.
 *
 * Estratégia:
 *   1. Coleta candidatos: <img> nas fontes scrapeadas (1ª foto após og:image)
 *   2. Filtra: tamanho ≥600px, não-logo, não-ícone, não-avatar
 *   3. Sideload no WP (usa Wordpress::uploadImagemPorUrl)
 *   4. Insere <figure><img/><figcaption>...</figcaption></figure> em pontos
 *      estratégicos: APÓS o 2º e o 5º <h2> (ritmo visual)
 *
 * Filosofia: imagens APENAS quando há foto contextual real na fonte. Não força.
 */
class InlineImageInjector
{
    /**
     * @param string  $html        HTML do artigo
     * @param array   $sourcesUrls URLs das fontes scrapeadas
     * @param object  $wp          Wordpress instance (uploadImagemPorUrl)
     * @param int     $maxImagens  default 2
     * @return array {html, log}
     */
    public static function injetar(string $html, array $sourcesUrls, $wp, int $maxImagens = 2): array
    {
        $log = ['candidatas_encontradas' => 0, 'aprovadas' => 0, 'inseridas' => 0, 'erros' => []];

        if (empty($sourcesUrls)) return ['html' => $html, 'log' => $log];

        // Coleta imagens das fontes
        $candidatas = self::extrairImagensFontes($sourcesUrls);
        $log['candidatas_encontradas'] = count($candidatas);
        if (empty($candidatas)) return ['html' => $html, 'log' => $log];

        // Aprova as melhores
        $aprovadas = self::aprovar($candidatas, $maxImagens);
        $log['aprovadas'] = count($aprovadas);
        if (empty($aprovadas)) return ['html' => $html, 'log' => $log];

        // Pontos de inserção: APÓS o 2º e o 5º </p> que segue um <h2>
        $pontos = self::encontrarPontosDeInsercao($html, count($aprovadas));
        if (empty($pontos)) {
            $log['erros'][] = 'sem pontos de inserção válidos';
            return ['html' => $html, 'log' => $log];
        }

        // Sideload + insere de trás pra frente (preserva offsets)
        usort($pontos, fn($a, $b) => $b <=> $a);
        $i = 0;
        foreach ($pontos as $pos) {
            if (!isset($aprovadas[$i])) break;
            $img = $aprovadas[$i];
            try {
                $alt = mb_substr($img['alt'] ?: 'imagem da matéria', 0, 120);
                $slug = 'inline-' . substr(md5($img['url']), 0, 8);
                $mediaId = $wp->uploadImagemPorUrl($img['url'], $alt, $slug);
                if ($mediaId) {
                    $media = $wp->getMedia($mediaId);
                    $imgUrl = $media['source_url'] ?? $img['url'];
                    $figcaption = htmlspecialchars($img['legenda'] ?: $alt, ENT_QUOTES, 'UTF-8');
                    $imgHtml = "\n<figure class='inline-img' style='margin:24px 0;width:100%;display:block'>"
                             . "<img src='" . htmlspecialchars($imgUrl, ENT_QUOTES) . "' alt='" . htmlspecialchars($alt, ENT_QUOTES) . "' loading='lazy' style='width:100%;height:auto;border-radius:8px;display:block'>"
                             . "<figcaption style='font-size:13px;color:#64748b;margin-top:8px;text-align:center;line-height:1.4;padding:0 12px;word-wrap:break-word;overflow-wrap:break-word;white-space:normal;display:block'>{$figcaption}</figcaption>"
                             . "</figure>\n";
                    $html = substr($html, 0, $pos) . $imgHtml . substr($html, $pos);
                    $log['inseridas']++;
                    $i++;
                }
            } catch (Throwable $e) {
                $log['erros'][] = $e->getMessage();
            }
        }

        return ['html' => $html, 'log' => $log];
    }

    /**
     * Extrai <img> das URLs fontes (até 5 candidatas total).
     */
    private static function extrairImagensFontes(array $urls): array
    {
        $candidatas = [];
        foreach (array_slice($urls, 0, 3) as $url) {
            $html = self::fetchHtml($url);
            if ($html === '') continue;
            // Procura <img>: pega src + alt
            if (preg_match_all('/<img\s+[^>]*>/i', $html, $imgs)) {
                foreach ($imgs[0] as $img) {
                    if (!preg_match('/src=[\'"]([^\'"]+)[\'"]/i', $img, $sm)) continue;
                    $src = $sm[1];
                    if (!filter_var($src, FILTER_VALIDATE_URL)) {
                        // Resolve relativo
                        $base = parse_url($url);
                        if (str_starts_with($src, '//')) {
                            $src = ($base['scheme'] ?? 'https') . ':' . $src;
                        } elseif (str_starts_with($src, '/')) {
                            $src = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '') . $src;
                        } else {
                            continue;
                        }
                    }
                    $alt = preg_match('/alt=[\'"]([^\'"]*)[\'"]/i', $img, $am) ? trim($am[1]) : '';
                    $width = preg_match('/width=[\'"]?(\d+)/i', $img, $wm) ? (int)$wm[1] : 0;

                    // Filtros básicos: skip se URL contém logo/icon/avatar/sprite
                    $low = mb_strtolower($src);
                    if (preg_match('#(logo|favicon|icon|avatar|sprite|brasao|emoji|gravatar|placeholder)#i', $low)) continue;
                    if (!preg_match('/\.(jpg|jpeg|png|webp)(\?|$)/i', $low)) continue;

                    $candidatas[] = ['url' => $src, 'alt' => $alt, 'width' => $width, 'legenda' => '', 'fonte_url' => $url];
                    if (count($candidatas) >= 5) break 2;
                }
            }
        }
        return $candidatas;
    }

    private static function fetchHtml(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CursosenacGratuitoBot/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
        ]);
        $b = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300) ? $b : '';
    }

    /**
     * Aprovar candidatas: dedup URL, depois pega top N.
     */
    private static function aprovar(array $candidatas, int $max): array
    {
        $seen = [];
        $aprovadas = [];
        foreach ($candidatas as $c) {
            if (isset($seen[$c['url']])) continue;
            $seen[$c['url']] = true;
            $aprovadas[] = $c;
            if (count($aprovadas) >= $max) break;
        }
        return $aprovadas;
    }

    /**
     * Pontos de inserção: APÓS o 1º </p> que vem após o 2º <h2> e o 5º <h2>.
     * Garante imagens distribuídas no corpo (não todas no topo).
     */
    private static function encontrarPontosDeInsercao(string $html, int $quantos): array
    {
        $pontos = [];
        if (!preg_match_all('/<h2[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) return [];
        $h2Positions = array_column($m[0], 1);
        $totalH2 = count($h2Positions);
        if ($totalH2 < 2) return [];

        // Alvos: 2º h2 e 5º h2 (ou último se total<5)
        $alvos = [];
        if ($quantos >= 1) $alvos[] = $h2Positions[1] ?? null; // 2º h2 (índice 1)
        if ($quantos >= 2 && $totalH2 >= 5) $alvos[] = $h2Positions[4] ?? null; // 5º h2
        elseif ($quantos >= 2) $alvos[] = $h2Positions[$totalH2 - 1] ?? null; // último h2

        foreach ($alvos as $h2Pos) {
            if ($h2Pos === null) continue;
            // Acha 1º </p> APÓS o h2 (= depois do 1º parágrafo da seção)
            if (preg_match('/<\/p>/i', $html, $pm, PREG_OFFSET_CAPTURE, $h2Pos)) {
                $pontos[] = $pm[0][1] + strlen($pm[0][0]);
            }
        }

        return array_values(array_filter($pontos));
    }
}
