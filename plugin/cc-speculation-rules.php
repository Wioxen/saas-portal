<?php
/**
 * Plugin Name: CC Speculation Rules
 * Description: Injeta Speculation Rules API no <head> pra pré-renderizar links internos no hover do mouse. Navegação interna vira instantânea (0ms perceptual). Suporte: Chromium 121+ (Chrome/Edge/Opera).
 * Version: 1.0
 * Author: Clonais
 *
 * Como funciona: quando user passa mouse sobre um link interno (eagerness=moderate),
 * o browser baixa+renderiza a página em background. Quando o user clica, é INSTANT —
 * página já está pronta. LCP real cai pra ~0ms na navegação interna.
 *
 * Conservador por design:
 *   - Apenas links INTERNOS do mesmo domínio
 *   - Exclui /wp-admin/, /wp-login.php, query strings (?), feeds RSS, fontes JSON
 *   - eagerness=moderate: dispara em hover/touch (não pré-renderiza tudo da página
 *     de uma vez, o que mataria mobile com pouca RAM)
 *   - Selectors `.no-prerender`, `[rel~=nofollow]` — opt-out manual
 *
 * Limite browser: navegadores limitam pre-renders simultâneos (típico 3). Spec garante
 * que se memória apertar, browser para de pre-renderizar — fail-safe natural.
 *
 * Após ativar: nada pra configurar. Funciona automático no front-end.
 *
 * Validação:
 *   1. Abrir post no Chrome
 *   2. DevTools > Application > Speculation Rules — deve mostrar "Ready" pros links
 *   3. Network tab: ver requisições com `Sec-Purpose: prefetch;prerender`
 */

if (!defined('ABSPATH')) { exit; }

add_action('wp_head', function () {
    // Só pra páginas user-facing — não em admin/login/feeds
    if (is_admin() || is_feed() || is_robots() || is_404()) return;

    // Pega host do site pra restringir só a links internos
    $host = parse_url(home_url(), PHP_URL_HOST);
    if (!$host) return;

    $rules = [
        'prerender' => [
            [
                'source' => 'document',
                'where' => [
                    'and' => [
                        // Só links internos do mesmo host
                        ['href_matches' => '/*'],
                        // Exclusões — admin, login, query strings (search), feeds, REST
                        ['not' => ['href_matches' => '/wp-admin/*']],
                        ['not' => ['href_matches' => '/wp-login.php*']],
                        ['not' => ['href_matches' => '/wp-json/*']],
                        ['not' => ['href_matches' => '/feed/*']],
                        ['not' => ['href_matches' => '/*?*']],
                        // Opt-out manual: classe .no-prerender ou rel=nofollow
                        ['not' => ['selector_matches' => '.no-prerender']],
                        ['not' => ['selector_matches' => 'a[rel~="nofollow"]']],
                    ],
                ],
                // moderate = dispara em hover (~200ms) ou touchstart no mobile
                // Equilíbrio entre velocidade percebida e custo de RAM/dados.
                'eagerness' => 'moderate',
            ],
        ],
        // Prefetch (apenas baixa o HTML, sem render) com eagerness mais alto
        // pra links que aparentem alta probabilidade de clique. Gasto mínimo.
        'prefetch' => [
            [
                'source' => 'document',
                'where' => [
                    'and' => [
                        ['href_matches' => '/*'],
                        ['not' => ['href_matches' => '/wp-admin/*']],
                        ['not' => ['href_matches' => '/wp-login.php*']],
                        ['not' => ['selector_matches' => '.no-prerender']],
                    ],
                ],
                'eagerness' => 'eager',
            ],
        ],
    ];

    $json = wp_json_encode($rules, JSON_UNESCAPED_SLASHES);
    if ($json === false) return;

    // Speculation Rules requer <script type="speculationrules">
    echo "\n<!-- cc-speculation-rules v1 -->\n";
    echo '<script type="speculationrules">' . $json . '</script>' . "\n";
}, 5); // prioridade 5 — antes de outros wp_head crowders

/**
 * Health check pra integração com nosso check_post_deploy / smoke remoto.
 *
 * GET /wp-json/cc-speculation-rules/v1/health
 *   → {ok: true, plugin: "cc-speculation-rules", version: "1.0"}
 */
add_action('rest_api_init', function () {
    register_rest_route('cc-speculation-rules/v1', '/health', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            return [
                'ok'      => true,
                'plugin'  => 'cc-speculation-rules',
                'version' => '1.0',
                'ts'      => current_time('mysql'),
            ];
        },
    ]);
});
