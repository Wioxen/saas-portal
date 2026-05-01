<?php
/**
 * Plugin Name: CC Pretty Links API
 * Description: Endpoint REST para criar Pretty Links via aplicação externa. Usado pelo sistema ComoComprar.
 * Version: 1.0
 * Author: Como Comprar
 *
 * Instalar: copie este arquivo para wp-content/plugins/ e ative no WP admin.
 *
 * Endpoint: POST /wp-json/cc/v1/pretty-link
 * Auth: Application Password (mesmo do WP REST API)
 * Body JSON: {"target_url": "...", "slug": "go/produto", "name": "...", "nofollow": 1, "redirect_type": "301"}
 * Response: {"success": true, "url": "https://site.com/go/produto", "id": 123}
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('cc/v1', '/pretty-link', [
        'methods'  => 'POST',
        'callback' => 'cc_create_pretty_link',
        'permission_callback' => function ($request) {
            return current_user_can('manage_options');
        },
    ]);

    register_rest_route('cc/v1', '/pretty-link/(?P<slug>.+)', [
        'methods'  => 'GET',
        'callback' => 'cc_get_pretty_link',
        'permission_callback' => function ($request) {
            return current_user_can('manage_options');
        },
    ]);
});

function cc_create_pretty_link($request) {
    $target_url    = sanitize_url($request->get_param('target_url'));
    $slug          = sanitize_text_field($request->get_param('slug'));
    $name          = sanitize_text_field($request->get_param('name') ?: $slug);
    $description   = sanitize_text_field($request->get_param('description') ?: '');
    $nofollow      = (int)($request->get_param('nofollow') ?? 1);
    $redirect_type = sanitize_text_field($request->get_param('redirect_type') ?: '301');
    $group_id      = (int)($request->get_param('group_id') ?? 0);

    if (!$target_url || !$slug) {
        return new WP_Error('missing_params', 'target_url e slug são obrigatórios', ['status' => 400]);
    }

    // Verifica se função do Pretty Links existe
    if (!function_exists('prli_create_pretty_link')) {
        return new WP_Error('plugin_missing', 'Pretty Links não está ativo', ['status' => 500]);
    }

    // Verifica se slug já existe
    global $wpdb;
    $table = $wpdb->prefix . 'prli_links';
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, slug, url FROM {$table} WHERE slug = %s", $slug
    ));

    if ($existing) {
        return rest_ensure_response([
            'success'  => true,
            'exists'   => true,
            'id'       => (int)$existing->id,
            'slug'     => $existing->slug,
            'url'      => home_url('/' . $existing->slug),
            'target'   => $existing->url,
        ]);
    }

    // Cria o Pretty Link
    $result = prli_create_pretty_link(
        $target_url,
        $slug,
        $name,
        $description,
        $group_id,
        0,              // no prettybar
        0,              // no ultra cloak
        1,              // track clicks
        $nofollow,
        $redirect_type
    );

    if ($result && !is_wp_error($result)) {
        // $result pode ser a URL ou o ID
        $pretty_url = is_numeric($result)
            ? home_url('/' . $slug)
            : $result;

        return rest_ensure_response([
            'success' => true,
            'exists'  => false,
            'id'      => is_numeric($result) ? (int)$result : null,
            'slug'    => $slug,
            'url'     => $pretty_url,
            'target'  => $target_url,
        ]);
    }

    return new WP_Error('create_failed', 'Falha ao criar Pretty Link', ['status' => 500]);
}

function cc_get_pretty_link($request) {
    $slug = $request->get_param('slug');

    if (!$slug) {
        return new WP_Error('missing_slug', 'Slug é obrigatório', ['status' => 400]);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'prli_links';
    $link = $wpdb->get_row($wpdb->prepare(
        "SELECT id, slug, url, name, redirect_type, nofollow FROM {$table} WHERE slug = %s", $slug
    ));

    if (!$link) {
        return new WP_Error('not_found', 'Pretty Link não encontrado', ['status' => 404]);
    }

    return rest_ensure_response([
        'id'            => (int)$link->id,
        'slug'          => $link->slug,
        'url'           => home_url('/' . $link->slug),
        'target'        => $link->url,
        'name'          => $link->name,
        'redirect_type' => $link->redirect_type,
        'nofollow'      => (bool)$link->nofollow,
    ]);
}
