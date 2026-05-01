<?php
/**
 * DiscoverTrustBlocks — sinais visuais de E-E-A-T no fim do post.
 *
 *   1. Caixa "Sobre o autor" — bio rica + especialidade + sameAs (LinkedIn/Twitter)
 *   2. "Fontes consultadas" — extrai URLs oficiais (.gov.br/.edu.br/.jus.br) citadas no post
 *   3. Affiliate disclosure FTC — quando há link de afiliado
 *
 * Por que: Google E-E-A-T avalia sinais visuais de transparência editorial.
 * Schema NewsArticle/Person (G1) já entrega o DADO; isso entrega o SINAL VISUAL pro leitor +
 * crawler do Discover (que rasteja o HTML renderizado).
 *
 * Ordem de injeção (após bloco principal, antes do "Continue lendo"):
 *   [conteúdo]
 *   [Affiliate Disclosure (se aplicável)]
 *   [Fontes Consultadas]
 *   [Sobre o Autor]
 *   [Continue Lendo (G4)]
 *   [Hub Link (G4)]
 *   [Post Share]
 */

class DiscoverTrustBlocks
{
    /**
     * Aplica os 3 blocos no HTML.
     * Retorna HTML modificado (idempotente — markers evitam duplicação).
     */
    public static function injetar(string $html, array $meta, array $trend, array $cfg): string
    {
        if (trim($html) === '') return $html;

        // Ordem importa: disclosure ANTES do conteúdo (sinaliza relação financeira upfront)
        $html = self::injetarAffiliateDisclosure($html, $cfg);
        // Fontes + autor depois do conteúdo principal
        $html = self::injetarFontesConsultadas($html, $trend, $cfg);
        $html = self::injetarCaixaAutor($html, $trend, $cfg);

        return $html;
    }

    // ─────────── 1. CAIXA AUTOR (E-E-A-T) ───────────

    private static function injetarCaixaAutor(string $html, array $trend, array $cfg): string
    {
        if (strpos($html, 'data-cc-author-box') !== false) return $html; // idempotente

        $persona = $cfg['persona'] ?? [];
        if (empty($persona) || empty($persona['autor'])) return $html;

        $autor = (string)$persona['autor'];
        $especialidade = (string)($persona['especialidade'] ?? '');
        $audiencia     = (string)($persona['audiencia']    ?? '');
        $voz           = (string)($persona['voz']          ?? '');
        $siteUrl       = rtrim((string)($cfg['wp_url'] ?? ''), '/');
        $autorUrl      = $siteUrl !== '' ? $siteUrl . '/author/admin/' : '';

        // Bio editorial: combina especialidade + audiência + frase E-E-A-T
        $bioPartes = [];
        if ($especialidade !== '') {
            $esp = htmlspecialchars(rtrim($especialidade, '.'), ENT_QUOTES, 'UTF-8');
            $bioPartes[] = ucfirst($esp) . '.';
        }
        if ($audiencia !== '')     $bioPartes[] = 'Foco em ' . htmlspecialchars(rtrim($audiencia, '.'), ENT_QUOTES, 'UTF-8') . '.';
        $bioPartes[] = 'Cada matéria passa por <strong>verificação cruzada em fontes oficiais</strong> (gov.br, MEC, Inep, Caixa) antes da publicação.';
        $bio = implode(' ', $bioPartes);

        // SameAs links (LinkedIn/Twitter) — placeholder se não setado
        $sameAsHtml = '';
        if (!empty($persona['sameAs']) && is_array($persona['sameAs'])) {
            $links = [];
            foreach ($persona['sameAs'] as $link) {
                if (!preg_match('#^https?://#', (string)$link)) continue;
                $linkEsc = htmlspecialchars((string)$link, ENT_QUOTES, 'UTF-8');
                $rotulo = self::rotuloSameAs((string)$link);
                $links[] = '<a href="' . $linkEsc . '" rel="author noopener" target="_blank" '
                         . 'style="display:inline-block;margin:0 6px 0 0;padding:4px 10px;background:#0f172a;color:#fff;border-radius:4px;text-decoration:none;font-size:12px">'
                         . htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8')
                         . '</a>';
            }
            if (!empty($links)) {
                $sameAsHtml = '<div style="margin-top:10px">' . implode('', $links) . '</div>';
            }
        }

        $autorEsc = htmlspecialchars($autor, ENT_QUOTES, 'UTF-8');
        $autorLink = $autorUrl !== '' ? htmlspecialchars($autorUrl, ENT_QUOTES, 'UTF-8') : '';

        $bloco = '<aside data-cc-author-box="1" '
               . 'style="margin:30px 0;padding:18px 22px;background:#fafafa;border:1px solid #e5e7eb;border-radius:8px;font-family:sans-serif">'
               . '<p style="margin:0 0 8px;font-size:13px;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;font-weight:700">Sobre o autor</p>'
               . '<p style="margin:0 0 6px;font-size:16px;font-weight:700;color:#0f172a">'
               .   ($autorLink !== '' ? '<a href="' . $autorLink . '" rel="author" style="color:inherit;text-decoration:none">' . $autorEsc . '</a>' : $autorEsc)
               . '</p>'
               . '<p style="margin:0;font-size:14px;line-height:1.5;color:#475569">' . $bio . '</p>'
               . $sameAsHtml
               . '</aside>';

        // Insere antes do continue-lendo, hub, share — depois do conteúdo principal
        return self::insereAntesDeMarker($html, $bloco, ['data-cc-continue-lendo', 'data-cc-hub-link', 'data-post-share']);
    }

    // ─────────── 2. FONTES CONSULTADAS ───────────

    private static function injetarFontesConsultadas(string $html, array $trend, array $cfg): string
    {
        if (strpos($html, 'data-cc-fontes') !== false) return $html; // idempotente

        // Extrai URLs oficiais do HTML (gov.br, edu.br, jus.br, mil.br, org.br, gob.br)
        $regex = '#<a\s+[^>]*href=[\'"](https?://[a-z0-9.-]+\.(?:gov|edu|jus|mil|gob)\.br[^\'"]*)[\'"][^>]*>([\s\S]*?)</a>#iu';
        if (!preg_match_all($regex, $html, $m)) return $html;

        $vistas = [];
        $fontes = [];
        foreach ($m[1] as $idx => $href) {
            $hostHref = parse_url($href, PHP_URL_HOST) ?: '';
            $hostKey = mb_strtolower($hostHref, 'UTF-8');
            if ($hostKey === '' || isset($vistas[$hostKey])) continue;
            $vistas[$hostKey] = true;
            $rotulo = self::rotuloFonte($hostKey);
            $fontes[] = ['url' => 'https://' . $hostKey, 'rotulo' => $rotulo];
        }
        if (count($fontes) < 1) return $html;
        if (count($fontes) > 8) $fontes = array_slice($fontes, 0, 8);

        $itemsHtml = '';
        foreach ($fontes as $f) {
            $urlEsc = htmlspecialchars($f['url'], ENT_QUOTES, 'UTF-8');
            $rotEsc = htmlspecialchars($f['rotulo'], ENT_QUOTES, 'UTF-8');
            $itemsHtml .= '<li style="display:inline-block;margin:0 8px 6px 0">'
                       . '<a href="' . $urlEsc . '" target="_blank" rel="noopener nofollow" '
                       .   'style="display:inline-block;padding:5px 11px;background:#eff6ff;color:#1e40af;border-radius:4px;text-decoration:none;font-size:13px">'
                       . '🏛️ ' . $rotEsc
                       . '</a></li>';
        }

        $bloco = '<aside data-cc-fontes="1" '
               . 'style="margin:24px 0;padding:14px 18px;background:#f9fafb;border-left:4px solid #1e40af;border-radius:6px;font-family:sans-serif">'
               . '<p style="margin:0 0 8px;font-size:13px;color:#475569;font-weight:700">📚 Fontes oficiais consultadas</p>'
               . '<ul style="list-style:none;padding:0;margin:0">' . $itemsHtml . '</ul>'
               . '</aside>';

        return self::insereAntesDeMarker($html, $bloco, ['data-cc-author-box', 'data-cc-continue-lendo', 'data-cc-hub-link', 'data-post-share']);
    }

    // ─────────── 3. AFFILIATE DISCLOSURE (FTC) ───────────

    private static function injetarAffiliateDisclosure(string $html, array $cfg): string
    {
        if (strpos($html, 'data-cc-disclosure') !== false) return $html; // idempotente

        // Detecta se há link de afiliado: amzn.to, /go/, ?tag=, hotmart.com, awin1.com
        $temAfiliado = preg_match('#<a\s+[^>]*href=[\'"][^\'"]*(?:amzn\.to|/go/|hotmart\.com|awin1\.com|shopee\.com\.br|magazinevoce\.com\.br|mercadolivre\.com)[^\'"]*[\'"][^>]*>#iu', $html);
        // Detecta também tabela ProductRanker
        $temRanker = strpos($html, 'discover-product-ranker') !== false;
        if (!$temAfiliado && !$temRanker) return $html;

        $bloco = '<div data-cc-disclosure="1" '
               . 'style="margin:0 0 18px;padding:10px 14px;background:#fef3c7;border-left:3px solid #f59e0b;border-radius:4px;font-family:sans-serif;font-size:12px;color:#78350f;line-height:1.5">'
               . '<strong>Aviso de afiliado:</strong> esta página pode conter links de afiliados. '
               . 'Se você comprar através desses links, podemos receber uma pequena comissão sem custo adicional pra você. '
               . 'Recomendamos apenas produtos que consideramos relevantes pro tema.'
               . '</div>';

        // Insere ANTES do conteúdo principal (boa prática FTC: divulgação UPFRONT)
        // Posiciona logo após o primeiro h1 OU depois de breadcrumb se existir
        if (preg_match('#</nav>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $pos) . "\n" . $bloco . "\n" . substr($html, $pos);
        }
        if (preg_match('#</h1>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $pos) . "\n" . $bloco . "\n" . substr($html, $pos);
        }
        return $bloco . "\n" . $html;
    }

    // ─────────── HELPERS ───────────

    private static function insereAntesDeMarker(string $html, string $bloco, array $markers): string
    {
        foreach ($markers as $marker) {
            if (preg_match('#<(?:aside|div)[^>]*' . preg_quote($marker, '#') . '=#i', $html, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                return substr($html, 0, $pos) . $bloco . "\n" . substr($html, $pos);
            }
        }
        return $html . "\n" . $bloco;
    }

    /** Rótulo legível pra fonte oficial. */
    private static function rotuloFonte(string $host): string
    {
        $mapa = [
            'www.gov.br'             => 'Portal Gov.br',
            'gov.br'                 => 'Portal Gov.br',
            'www.inep.gov.br'        => 'Inep',
            'enem.inep.gov.br'       => 'Inep / Enem',
            'www.mec.gov.br'         => 'MEC',
            'acessounico.mec.gov.br' => 'MEC / Acesso Único',
            'www.caixa.gov.br'       => 'Caixa Econômica Federal',
            'caixa.gov.br'           => 'Caixa Econômica Federal',
            'www.bb.com.br'          => 'Banco do Brasil',
            'meu.inss.gov.br'        => 'Meu INSS',
            'www.gov.br/inss'        => 'INSS',
            'www.bcb.gov.br'         => 'Banco Central',
            'portal.stf.jus.br'      => 'STF',
            'www.tse.jus.br'         => 'TSE',
            'www.gov.br/anvisa'      => 'Anvisa',
            'www.anvisa.gov.br'      => 'Anvisa',
            'www.ibge.gov.br'        => 'IBGE',
            'cadastrounico.dataprev.gov.br' => 'CadÚnico',
        ];
        if (isset($mapa[$host])) return $mapa[$host];
        // Fallback: capitaliza primeiro segmento (tira www., subdomínio compacto)
        $h = preg_replace('/^www\./', '', $host);
        $h = preg_replace('/\.(gov|edu|jus|mil|gob)\.br$/', '', $h);
        return strtoupper($h);
    }

    /** Rótulo legível pra sameAs (Person schema). */
    private static function rotuloSameAs(string $url): string
    {
        $host = mb_strtolower(parse_url($url, PHP_URL_HOST) ?: '', 'UTF-8');
        if (strpos($host, 'linkedin.com') !== false) return 'LinkedIn';
        if (strpos($host, 'twitter.com') !== false || strpos($host, 'x.com') !== false) return 'Twitter';
        if (strpos($host, 'instagram.com') !== false) return 'Instagram';
        if (strpos($host, 'facebook.com') !== false) return 'Facebook';
        if (strpos($host, 'youtube.com') !== false) return 'YouTube';
        if (strpos($host, 'github.com') !== false) return 'GitHub';
        return 'Site';
    }
}
