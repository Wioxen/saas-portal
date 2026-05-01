<?php
/**
 * AfiliadoLinkBuilder — anexa attribution `?p={post_id}` em URLs Pretty Links.
 *
 * Sem isso, plugin cc-click-logger captura click mas não sabe DE QUAL POST veio →
 * attribution post→sale fica cega. Com `?p=POST_ID`, attribution é exata e direta.
 *
 * Uso (no momento de injetar URL no HTML):
 *   $url = AfiliadoLinkBuilder::comAttribution('https://site.com/go/produto', 1234);
 *   // → 'https://site.com/go/produto?p=1234'
 *
 * Caso já haja query (`?utm_source=...`), preserva e adiciona `&p=...`.
 * Se já tem `?p=`, NÃO duplica (idempotente).
 *
 * Atribuição também aplicável a:
 *   - Tabela ProductRanker (cada `<a href="{pretty}">`)
 *   - In-feed CTA (Maquina::injetarInfeed)
 *   - Trust block "Vale a pena?"
 *
 * Idempotência: post_id=0 ou ausente → URL passa intocada (sem attribution útil).
 */
class AfiliadoLinkBuilder
{
    /**
     * Adiciona `?p={postId}` (ou `&p=...` se já há query) em URL pretty links.
     * Retorna URL inalterada se postId <= 0 ou URL inválida.
     */
    public static function comAttribution(string $url, int $postId): string
    {
        if ($postId <= 0 || $url === '') return $url;
        // Aceita absoluta (https://...) ou relativa (/go/...). Rejeita só não-http óbvios
        // (mailto:, tel:, javascript:, ftp: etc) que não são links de tráfego web.
        if (!preg_match('~^(?:https?://|/)~i', $url)) return $url;

        // Idempotência: já tem ?p= ou &p= → não duplica
        if (preg_match('/[?&]p=\d+(?:&|$|#)/', $url)) {
            return $url;
        }

        // Separa fragment (#) — sempre por último na URL
        $fragment = '';
        if (($hashPos = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }

        $sep = (strpos($url, '?') !== false) ? '&' : '?';
        return $url . $sep . 'p=' . $postId . $fragment;
    }

    /**
     * Aplica attribution em massa: todas tags `<a href>` que apontem pra Pretty Links
     * (start with /go/ ou /ir/ depois do host, OU URL absoluta com mesmo path) recebem
     * `?p={postId}`.
     *
     * Usa DOMDocument pra evitar regex no HTML (mais robusto, não quebra atributos).
     *
     * @param string $html      HTML do post
     * @param int    $postId    ID do post no WP
     * @param array  $prefixes  prefixos PrettyLinks ['go', 'ir'] (default ['go'])
     * @return string HTML atualizado
     */
    public static function aplicarEmHtml(string $html, int $postId, array $prefixes = ['go']): string
    {
        if ($postId <= 0 || $html === '') return $html;
        // Regex em href= é mais seguro que DOMDocument pra fragmentos (preserva 100% do
        // resto do HTML). Match em href=" ou href=' apontando a path /go/X (absoluto ou relativo).
        return preg_replace_callback(
            '~(href=)(["\'])([^"\']+)\\2~i',
            function ($m) use ($postId, $prefixes) {
                $href = $m[3];
                if (!self::ehPrettyLink($href, $prefixes)) return $m[0];
                $novo = self::comAttribution($href, $postId);
                if ($novo === $href) return $m[0];
                return $m[1] . $m[2] . $novo . $m[2];
            },
            $html
        ) ?? $html;
    }

    /**
     * Detecta se URL é Pretty Link (path com prefixo conhecido).
     * Aceita absoluto (https://site/go/x) ou relativo (/go/x).
     */
    public static function ehPrettyLink(string $url, array $prefixes = ['go']): bool
    {
        if ($url === '') return false;
        $path = $url;
        if (preg_match('~^https?://[^/]+(.*)$~i', $url, $m)) {
            $path = $m[1];
        }
        if ($path === '' || $path[0] !== '/') return false;
        foreach ($prefixes as $p) {
            $p = trim($p, '/');
            if ($p === '') continue;
            // Delimitador ~ pra evitar conflito com `#` (fragment) dentro do char class
            if (preg_match('~^/' . preg_quote($p, '~') . '/[^/?#]+~i', $path)) {
                return true;
            }
        }
        return false;
    }
}
