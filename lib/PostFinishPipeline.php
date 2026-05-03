<?php
declare(strict_types=1);

/**
 * PostFinishPipeline — etapa de fechamento de post pós-Sonnet/Haiku.
 *
 * Resolve 2 problemas comuns dos geradores ad-hoc:
 *   1. <%leiamais%> placeholder vazio — substitui por posts relacionados
 *      buscados via Wordpress::buscarRelacionados($keyword)
 *   2. Featured image ausente — extrai og:image da fonte primária
 *      (Tier S/A) e faz sideload via Wordpress::uploadImagemPorUrl
 *
 * Uso:
 *   $r = PostFinishPipeline::aplicar($wp, $html, [
 *       'keyword'         => 'enem 2026 isenção',
 *       'fontes_urls'     => [...]  // pra extrair og:image
 *       'wp_url'          => 'https://...',
 *       'titulo'          => '...',  // pra alt da imagem
 *       'post_id'         => 4588,   // pra setar featured_media depois
 *   ]);
 *   $html = $r['html'];
 *   $featuredId = $r['featured_id'] ?? null;
 *
 * Retorna {html, leiamais_aplicado, featured_id, debug}
 */
class PostFinishPipeline
{
    public static function aplicar(Wordpress $wp, string $html, array $opts): array
    {
        $debug = [];
        $featuredId = null;

        // 1. Substituir <%leiamais%> ou bloco <ul id='leiamais'> vazio
        $temPlaceholder = strpos($html, '<%leiamais%>') !== false;
        $temUlVazio = (bool)preg_match("#<ul[^>]*id=['\"]?leiamais['\"]?[^>]*>\s*</ul>#i", $html);

        if (($temPlaceholder || $temUlVazio) && !empty($opts['keyword'])) {
            $relacionados = $wp->buscarRelacionados((string)$opts['keyword'], 6, (int)($opts['post_id'] ?? 0));
            if (!empty($relacionados)) {
                $liHtml = '';
                foreach (array_slice($relacionados, 0, 6) as $r) {
                    $titulo = (string)($r['title'] ?? $r['title']['rendered'] ?? '');
                    $link   = (string)($r['link'] ?? '');
                    if ($titulo === '' || $link === '') continue;
                    $tEsc = htmlspecialchars(strip_tags($titulo), ENT_QUOTES, 'UTF-8');
                    $liHtml .= "<li><a href='{$link}'>{$tEsc}</a></li>";
                }
                if ($liHtml !== '') {
                    if ($temPlaceholder) {
                        $html = str_replace('<%leiamais%>', $liHtml, $html);
                    } else {
                        // Substitui o <ul vazio inteiro
                        $html = preg_replace(
                            "#(<ul[^>]*id=['\"]?leiamais['\"]?[^>]*>)\s*(</ul>)#i",
                            "\$1\n{$liHtml}\n\$2",
                            $html
                        ) ?? $html;
                    }
                    $debug[] = "leiamais: " . count($relacionados) . " posts relacionados injetados";
                }
            } else {
                $debug[] = "leiamais: 0 posts relacionados encontrados (keyword='{$opts['keyword']}')";
                // Remove o placeholder/ul vazio pra não ficar visível
                if ($temPlaceholder) {
                    $html = str_replace('<%leiamais%>', '', $html);
                }
            }
        }

        // 2. Featured image — sideload da og:image da fonte primária
        if (empty($opts['post_id']) || empty($opts['fontes_urls'])) {
            return ['html' => $html, 'featured_id' => null, 'debug' => $debug];
        }

        $imageUrl = self::extrairOgImage((array)$opts['fontes_urls']);
        if ($imageUrl !== null) {
            try {
                $alt = (string)($opts['titulo'] ?? 'Imagem');
                $featuredId = $wp->uploadImagemPorUrl($imageUrl, $alt);
                if ($featuredId) {
                    $debug[] = "featured: sideload ok id={$featuredId} url={$imageUrl}";
                }
            } catch (Throwable $e) {
                $debug[] = "featured: falhou — " . $e->getMessage();
            }
        } else {
            $debug[] = "featured: nenhuma og:image extraída das fontes";
        }

        return ['html' => $html, 'featured_id' => $featuredId, 'debug' => $debug];
    }

    /**
     * Extrai og:image (ou twitter:image) da primeira fonte que tiver.
     * Faz curl + parse meta tags. Retorna URL absoluta ou null.
     */
    private static function extrairOgImage(array $urls): ?string
    {
        foreach ($urls as $url) {
            if (!is_string($url) || $url === '') continue;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 PostFinishBot',
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $html = curl_exec($ch);
            curl_close($ch);
            if (!$html) continue;

            // Tenta og:image primeiro, depois twitter:image, depois link rel=image_src
            $patterns = [
                '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i',
                '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']#i',
                '#<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']#i',
                '#<link[^>]+rel=["\']image_src["\'][^>]+href=["\']([^"\']+)["\']#i',
            ];
            foreach ($patterns as $pat) {
                if (preg_match($pat, $html, $m)) {
                    $img = trim($m[1]);
                    // Resolve relative URLs
                    if (str_starts_with($img, '//')) {
                        $img = 'https:' . $img;
                    } elseif (str_starts_with($img, '/')) {
                        $parsed = parse_url($url);
                        $img = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . $img;
                    }
                    if (str_starts_with($img, 'http')) return $img;
                }
            }
        }
        return null;
    }
}
