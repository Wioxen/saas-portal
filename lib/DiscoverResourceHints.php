<?php
/**
 * DiscoverResourceHints — injeta <link rel="preconnect"> e <link rel="dns-prefetch">
 * pra terceiros que aparecem no HTML do post.
 *
 * Por que: cada domínio externo (Pexels CDN, fonts, scripts de afiliado) custa
 * ~100-300ms de DNS lookup + TLS handshake na 1ª requisição. Resource hints
 * pré-conectam a esses domínios em paralelo ao parse do HTML → latency mobile
 * cai 100-200ms (Discover ranking factor via Core Web Vitals).
 *
 * Onde injetar: idealmente <head>, mas conteúdo de post fica dentro de <body>.
 * Browsers modernos honram <link> em qualquer posição (incluindo body) — a
 * vantagem temporal é menor mas ainda real (~50-100ms ao invés de 200ms+).
 *
 * Whitelist conservadora: só domínios bem-conhecidos (Pexels/Cloudinary/Google
 * Fonts/Cloudflare). Não injeta hint pra cada link <a href> de marketplace —
 * isso polui prefetch table dos browsers.
 *
 * Idempotente via marker `data-cc-resource-hints="1"`.
 *
 * Uso (em DiscoverPostProcess):
 *   $html = DiscoverResourceHints::aplicar($html);
 */

class DiscoverResourceHints
{
    private const MARKER = 'data-cc-resource-hints="1"';

    /**
     * Domínios CDN/external conhecidos que valem hint quando aparecem no post.
     * Mapeado por host pattern → tipo de hint preferido.
     *   - 'preconnect': abre conexão completa (DNS + TLS + TCP) — usar pra recursos críticos
     *   - 'dns-prefetch': só DNS — usar pra terceiros eventuais
     */
    private const HINTS_CONHECIDOS = [
        // CDNs de imagens
        '/(?:^|\.)pexels\.com$/i'        => ['type' => 'preconnect', 'crossorigin' => true],
        '/(?:^|\.)pexelscdn\.com$/i'     => ['type' => 'preconnect', 'crossorigin' => true],
        '/(?:^|\.)cloudinary\.com$/i'    => ['type' => 'preconnect', 'crossorigin' => true],
        '/(?:^|\.)imgur\.com$/i'         => ['type' => 'preconnect', 'crossorigin' => true],

        // Fonts
        '/^fonts\.googleapis\.com$/i'    => ['type' => 'preconnect', 'crossorigin' => true],
        '/^fonts\.gstatic\.com$/i'       => ['type' => 'preconnect', 'crossorigin' => true],

        // Analytics + tagging (entram via plugins/scripts)
        '/^www\.googletagmanager\.com$/i'=> ['type' => 'dns-prefetch'],
        '/^www\.google-analytics\.com$/i'=> ['type' => 'dns-prefetch'],
        '/(?:^|\.)googlesyndication\.com$/i' => ['type' => 'dns-prefetch'],

        // Marketplace tracking (links <a> levam pra cá)
        '/(?:^|\.)amazon\.com\.br$/i'    => ['type' => 'dns-prefetch'],
        '/^amzn\.to$/i'                  => ['type' => 'dns-prefetch'],
        '/(?:^|\.)mercadolivre\.com\.br$/i' => ['type' => 'dns-prefetch'],
        '/(?:^|\.)magazineluiza\.com\.br$/i' => ['type' => 'dns-prefetch'],
        '/(?:^|\.)shopee\.com\.br$/i'    => ['type' => 'dns-prefetch'],
        '/^shope\.ee$/i'                 => ['type' => 'dns-prefetch'],
    ];

    /**
     * Detecta domínios externos + injeta hints no INÍCIO do HTML.
     */
    public static function aplicar(string $html): string
    {
        if ($html === '' || strpos($html, self::MARKER) !== false) return $html;

        $hints = self::detectarHints($html);
        if (empty($hints)) return $html;

        $bloco = self::montarBloco($hints);
        if ($bloco === '') return $html;

        // Injeta no INÍCIO do conteúdo (browsers honram <link> em qualquer lugar)
        return $bloco . $html;
    }

    /**
     * Extrai todos os domínios externos do HTML, dedup, mapeia pra hint apropriado.
     * Retorna array {host: hint_config}.
     */
    public static function detectarHints(string $html): array
    {
        if (!preg_match_all('#https?://([^/\s\'"<>]+)#i', $html, $ms)) return [];

        $hosts = array_unique($ms[1]);
        $matches = [];
        foreach ($hosts as $host) {
            $host = strtolower(trim($host));
            if ($host === '') continue;
            // Strip port if present
            $host = preg_replace('/:\d+$/', '', $host);
            foreach (self::HINTS_CONHECIDOS as $pattern => $cfg) {
                if (preg_match($pattern, $host)) {
                    // Idempotência por host (mesmo que apareça com www e sem)
                    $matches[$host] = $cfg + ['host' => $host];
                    break;
                }
            }
        }
        // Cap em 8 hints (browser tem limite prático)
        return array_slice($matches, 0, 8, true);
    }

    private static function montarBloco(array $hints): string
    {
        $links = [];
        foreach ($hints as $host => $cfg) {
            $type = $cfg['type'];
            $href = '//' . $host;
            $crossOrigin = !empty($cfg['crossorigin']) ? ' crossorigin' : '';
            $links[] = "<link rel=\"{$type}\" href=\"{$href}\"{$crossOrigin}>";
        }
        if (empty($links)) return '';

        // Comentário marker pra idempotência + debug
        return "<!-- " . self::MARKER . " -->\n" . implode("\n", $links) . "\n";
    }
}
