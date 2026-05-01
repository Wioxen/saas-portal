<?php
/**
 * DiscoverAfiliadoBR — detecta URLs marketplace originais que NÃO atribuem comissão.
 *
 * IMPORTANTE: anexar `?tag=`, `?partner_id=`, `?matt_word=`, `?af_siteid=` em URL
 * original NÃO atribui comissão na maioria dos programas BR. Atribuição requer:
 *   - Amazon: link gerado via API/SiteStripe (cookie + tag) OU link curto amzn.to
 *   - Magalu: subloja Magazine Você (magazinevoce.com.br/{user}/produto)
 *   - Mercado Livre: deeplink gerado pelo programa (Awin/Lomadee/ML Afiliados)
 *   - Shopee: smart-link gerado no dashboard (s.shopee.com.br/{hash})
 *
 * Como NÃO temos API de afiliado pra essas redes, **fluxo correto é**:
 *   1. Operador gera deeplink real na plataforma do programa (1× por produto)
 *   2. Cadastra no PrettyLinks com slug bonito (`/go/iphone-15`)
 *   3. Sonnet menciona produto e recebe `<a href="/go/iphone-15">`
 *   4. AfiliadoLinkBuilder anexa `?p={post_id}` (já existe)
 *
 * Este módulo cumpre 2 papéis:
 *   1. DETECTAR URL marketplace ORIGINAL no HTML (Sonnet inventou link sem PrettyLink)
 *   2. LOGAR warning em data/afiliado_warnings.log pra revisão periódica
 *      (sintoma de "produto que vale a pena cadastrar PrettyLink")
 *   3. Opcional (cfg `desfazer_links_inventados=true`): converte `<a href=URL_MARKETPLACE>texto</a>`
 *      em texto puro — evita link quebrado sem comissão atribuída
 *
 * Uso (em DiscoverPostProcess):
 *   $html = DiscoverAfiliadoBR::aplicar($html, $cfg, $postId);
 */

class DiscoverAfiliadoBR
{
    private const LOG_FILE = '/../data/afiliado_warnings.log';

    /**
     * Detecta URLs marketplace e loga warning. Não modifica HTML por padrão.
     *
     * @param string $html
     * @param array  $cfg ['desfazer_links_inventados' => bool, '_site_slug' => string]
     * @param int    $postId pra contexto no log
     * @return string HTML possivelmente desfeito (se cfg pediu)
     */
    public static function aplicar(string $html, array $cfg = [], int $postId = 0): string
    {
        if ($html === '') return $html;

        $detectados = self::detectar($html);
        if (empty($detectados)) return $html;

        // Loga cada warning (best-effort)
        $site = (string)($cfg['_site_slug'] ?? '');
        try {
            self::logar($detectados, $site, $postId);
        } catch (Throwable $e) { /* log é opcional */ }

        if (!empty($cfg['desfazer_links_inventados'])) {
            $html = self::desfazerLinks($html, $detectados);
        }
        return $html;
    }

    /**
     * Detecta URLs marketplace ORIGINAIS (não passadas por PrettyLink).
     * Retorna lista de {rede, url, contexto} — contexto = 80 chars antes/depois pra debug.
     */
    public static function detectar(string $html): array
    {
        // Regex genérica pra TODOS os 4 marketplaces
        $pattern = '#https?://(?:[\w-]+\.)?'
                 . '(?:amazon\.com\.br|amazon\.com|amzn\.to'
                 . '|magazineluiza\.com\.br|magalu\.com|magazinevoce\.com\.br'
                 . '|mercadolivre\.com\.br|mercadolivre\.com'
                 . '|shopee\.com\.br|shope\.ee'
                 . ')/[^\s\'"<>]+#i';

        if (!preg_match_all($pattern, $html, $ms, PREG_OFFSET_CAPTURE)) return [];

        $out = [];
        foreach ($ms[0] as $m) {
            $url = (string)$m[0];
            $pos = (int)$m[1];

            // Skipa URLs já mascaradas (s.shopee.com.br/, amzn.to/, magazinevoce.com.br/{user}/)
            // Estas são deeplinks reais — funcionam.
            if (self::ehDeeplinkValido($url)) continue;

            $rede = self::detectarRede($url);
            if ($rede === null) continue;

            $contextoIni = max(0, $pos - 80);
            $contexto = mb_substr($html, $contextoIni, 200, 'UTF-8');

            $out[] = [
                'rede'    => $rede,
                'url'     => $url,
                'pos'     => $pos,
                'contexto'=> trim(preg_replace('/\s+/u', ' ', strip_tags($contexto)) ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Heurística: URL é deeplink REAL (gerado na plataforma do programa)?
     * Esses passam livre porque atribuem comissão.
     */
    private static function ehDeeplinkValido(string $url): bool
    {
        // amzn.to/* — link curto Amazon (tag controlada na configuração)
        if (preg_match('#^https?://amzn\.to/#i', $url)) return true;
        // s.shopee.com.br/* OU shope.ee/* — smart-link Shopee Affiliate
        if (preg_match('#^https?://(?:s\.shopee\.com\.br|shope\.ee)/#i', $url)) return true;
        // magazinevoce.com.br/{username}/... — subloja Magalu Você (parceiro)
        if (preg_match('#^https?://(?:www\.)?magazinevoce\.com\.br/[^/]+/#i', $url)) return true;
        // mercadolivre.com.br/sec/{hash} — deeplink ML
        if (preg_match('#^https?://(?:www\.)?mercadolivre\.com\.br/sec/#i', $url)) return true;
        return false;
    }

    /** Detecta qual marketplace (público — outros módulos podem usar). */
    public static function detectarRede(string $url): ?string
    {
        if (preg_match('#(?:amazon\.com\.br|amazon\.com|amzn\.to)#i', $url)) return 'amazon';
        if (preg_match('#(?:magazineluiza\.com\.br|magalu\.com|magazinevoce\.com\.br)#i', $url)) return 'magalu';
        if (preg_match('#mercadolivre\.com#i', $url)) return 'mercadolivre';
        if (preg_match('#(?:shopee\.com\.br|shope\.ee)#i', $url)) return 'shopee';
        return null;
    }

    /**
     * Desfaz `<a href="URL_MARKETPLACE">texto</a>` → `texto` (preservando texto puro).
     * Útil quando operador prefere arriscar perder click do que ter link sem comissão.
     */
    private static function desfazerLinks(string $html, array $detectados): string
    {
        // Pra cada URL detectada, remove a tag <a> que a envolve preservando o texto
        $urls = array_unique(array_column($detectados, 'url'));
        foreach ($urls as $url) {
            $urlEsc = preg_quote($url, '#');
            $html = preg_replace(
                '#<a\s+[^>]*href=([\'"])' . $urlEsc . '\\1[^>]*>([\s\S]*?)</a>#i',
                '$2',
                $html
            ) ?? $html;
        }
        return $html;
    }

    /**
     * Loga warnings em data/afiliado_warnings.log (append-only).
     * Periódico: operador revisa, identifica produtos recorrentes, cadastra PrettyLinks.
     */
    private static function logar(array $detectados, string $site, int $postId): void
    {
        $logFile = __DIR__ . self::LOG_FILE;
        $dir = dirname($logFile);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);

        $linhas = [];
        $ts = date('Y-m-d H:i:s');
        foreach ($detectados as $d) {
            $linhas[] = sprintf("[%s] site=%s post=%d rede=%s url=%s ctx=\"%s\"",
                $ts, $site, $postId, $d['rede'], $d['url'],
                mb_substr($d['contexto'] ?? '', 0, 120, 'UTF-8')
            );
        }
        @file_put_contents($logFile, implode("\n", $linhas) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Resumo do log pra dashboard/admin: top URLs/redes nos últimos N dias.
     */
    public static function resumoWarnings(int $diasLookback = 7): array
    {
        $logFile = __DIR__ . self::LOG_FILE;
        if (!is_file($logFile)) return ['total' => 0, 'por_rede' => [], 'top_urls' => []];

        $cutoff = time() - ($diasLookback * 86400);
        $porRede = [];
        $porUrl = [];
        $total = 0;

        $fp = @fopen($logFile, 'rb');
        if (!$fp) return ['total' => 0, 'por_rede' => [], 'top_urls' => []];
        while (($linha = fgets($fp)) !== false) {
            if (!preg_match('/^\[([^\]]+)\] .* rede=(\w+) url=(\S+)/', trim($linha), $m)) continue;
            $ts = strtotime($m[1]) ?: 0;
            if ($ts < $cutoff) continue;
            $rede = $m[2];
            $url  = $m[3];
            $porRede[$rede] = ($porRede[$rede] ?? 0) + 1;
            $porUrl[$url] = ($porUrl[$url] ?? 0) + 1;
            $total++;
        }
        @fclose($fp);

        arsort($porUrl);
        $top = array_slice($porUrl, 0, 10, true);

        return [
            'total'      => $total,
            'por_rede'   => $porRede,
            'top_urls'   => $top,
            'lookback_d' => $diasLookback,
        ];
    }
}
