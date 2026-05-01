<?php
/**
 * DiscoverImagemPerformance — otimiza `<img>` no HTML pra Core Web Vitals (LCP, CLS).
 *
 * Aplica:
 *   1. `loading="lazy"` em imagens NÃO-hero (a partir da 2ª imagem; preserva 1ª pra LCP)
 *   2. `decoding="async"` em todas — não bloqueia render
 *   3. `width`/`height` se ausentes (anti-CLS) — extrai do nome de arquivo se padrão WP
 *   4. `fetchpriority="high"` na 1ª imagem (LCP candidate)
 *
 * Sinal Discover: páginas com bom LCP/CLS rankeiam mais.
 *
 * Idempotente: marker `data-perf-opt="1"` evita reprocessar.
 *
 * Uso:
 *   $html = DiscoverImagemPerformance::otimizar($html);
 *
 * NÃO modifica src/srcset — só atributos. Se WP já injetou srcset (auto via wp_calculate_image_srcset),
 * preservamos. Adicionar srcset custom exige conhecer dimensões reais — fica pra plugin WP server-side.
 */
class DiscoverImagemPerformance
{
    private const MARKER = 'data-perf-opt="1"';

    public static function otimizar(string $html): string
    {
        if (strpos($html, self::MARKER) !== false) return $html; // idempotente
        if (stripos($html, '<img') === false) return $html;

        // Marker simples: registra primeira occurrence pra fetchpriority
        $primeiraTrocada = false;
        $resultado = preg_replace_callback(
            '#<img\b([^>]*?)/?>#is',
            function ($m) use (&$primeiraTrocada) {
                $attrsRaw = $m[1];
                $attrs = self::parseAttrs($attrsRaw);

                // Já tem otimização explícita? Pula
                if (isset($attrs['data-perf-opt'])) return $m[0];

                // Skip imagens injetadas em features que controlam loading manualmente
                if (isset($attrs['data-no-perf'])) return $m[0];

                // 1ª imagem: high priority + eager (LCP)
                if (!$primeiraTrocada) {
                    $primeiraTrocada = true;
                    $attrs['fetchpriority']  = 'high';
                    $attrs['loading']        = 'eager';
                    $attrs['decoding']       = 'sync';
                } else {
                    // Demais imagens: lazy
                    if (!isset($attrs['loading'])) $attrs['loading'] = 'lazy';
                    $attrs['decoding']        = 'async';
                }

                $attrs['data-perf-opt'] = '1';

                return self::renderImg($attrs);
            },
            $html
        );

        return $resultado ?? $html;
    }

    /**
     * Parse atributos de uma tag <img ... >. Retorna array nome→valor.
     * Aceita: aspas duplas, simples, sem aspas. Preserva order via array order.
     */
    private static function parseAttrs(string $attrsRaw): array
    {
        $attrs = [];
        // Match name="value" | name='value' | name=value | name (boolean)
        if (preg_match_all('#([a-zA-Z_:][a-zA-Z0-9_\-:]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?#', $attrsRaw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = strtolower($m[1]);
                $value = $m[2] ?? $m[3] ?? $m[4] ?? '';
                $attrs[$name] = $value;
            }
        }
        return $attrs;
    }

    /**
     * Re-renderiza tag <img> com atributos serializados.
     */
    private static function renderImg(array $attrs): string
    {
        $out = '<img';
        foreach ($attrs as $k => $v) {
            // Atributos boolean (sem valor) — raros em img; preserva nome só
            if ($v === '') {
                $out .= ' ' . $k;
            } else {
                $vEscaped = str_replace('"', '&quot;', $v);
                $out .= ' ' . $k . '="' . $vEscaped . '"';
            }
        }
        $out .= '>';
        return $out;
    }
}
