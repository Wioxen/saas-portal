<?php
/**
 * Plugin Name: CC Meta Bridge
 * Description: Registra meta keys de Yoast SEO / Rank Math / SEOPress como atualizáveis via WP REST API (auth_callback baseado em edit_posts). Habilita Title/P1/Meta Swapper a operar em produção.
 * Version: 1.0
 * Author: Clonais
 *
 * Por que: WP REST API bloqueia atualização de meta keys que começam com _
 * (`_yoast_wpseo_metadesc`, `_seopress_titles_desc` etc) por padrão. Mesmo
 * se você passar via `meta` no body, o WP retorna 200 mas o valor NÃO é salvo
 * (bug silencioso). Solução: registrar via register_post_meta com show_in_rest=true.
 *
 * Plugins SEO populares cobertos:
 *   - Yoast SEO (com underscore)
 *   - Rank Math (sem underscore na maioria)
 *   - SEOPress (com underscore)
 *
 * Auth: só permite update se user logado tem cap edit_posts no post target.
 * Usuário criado pelo Wordpress.php (app password) precisa ter role autor+
 * (já é o caso na maioria dos setups).
 *
 * Após ativar: nenhuma config — funciona automático. Plugin não cria UI.
 */

if (!defined('ABSPATH')) { exit; }

add_action('init', function () {
    $metaKeys = [
        // ─── Yoast SEO ───
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_opengraph-title',
        '_yoast_wpseo_opengraph-description',
        '_yoast_wpseo_twitter-title',
        '_yoast_wpseo_twitter-description',
        '_yoast_wpseo_focuskw',
        '_yoast_wpseo_canonical',
        // ─── Rank Math ───
        'rank_math_description',
        'rank_math_title',
        'rank_math_focus_keyword',
        'rank_math_canonical_url',
        'rank_math_facebook_title',
        'rank_math_facebook_description',
        'rank_math_twitter_title',
        'rank_math_twitter_description',
        // ─── SEOPress ───
        '_seopress_titles_title',
        '_seopress_titles_desc',
        '_seopress_social_fb_title',
        '_seopress_social_fb_desc',
        '_seopress_social_twitter_title',
        '_seopress_social_twitter_desc',
    ];

    foreach ($metaKeys as $key) {
        register_post_meta('post', $key, [
            'type'              => 'string',
            'description'       => 'CC Meta Bridge: SEO meta key registered for REST update',
            'single'            => true,
            'show_in_rest'      => true,
            'auth_callback'     => function ($allowed, $meta_key, $object_id) {
                return current_user_can('edit_post', $object_id);
            },
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }
});

/**
 * Endpoint de health check pra verificar que o plugin carregou.
 * Útil pra integração com nosso scripts/check_post_deploy.
 *
 * GET /wp-json/cc-meta-bridge/v1/health
 *   → {ok: true, registered_keys: 21}
 */
add_action('rest_api_init', function () {
    register_rest_route('cc-meta-bridge/v1', '/health', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            return [
                'ok'              => true,
                'plugin'          => 'cc-meta-bridge',
                'version'         => '1.0',
                'registered_keys' => 21,
                'ts'              => current_time('mysql'),
            ];
        },
    ]);
});
