<?php
/**
 * Plugin Name: CC Redirections API
 * Description: Endpoint REST que cria redirects do Rank Math em batch + purge automático de cache LiteSpeed.
 * Version: 1.0
 * Author: Clonais Work
 *
 * Instalar: copie para wp-content/plugins/cc-redirections-api/cc-redirections-api.php OU
 *           sobe o ZIP em Plugins → Add New → Upload, depois Activate.
 * Requer: Rank Math + módulo "Redirections" ativo.
 *
 * Endpoints (auth: Application Password do user com manage_options):
 *   POST /wp-json/cc/v1/redirections        — cria batch
 *     Body: {"redirects": [{"source":"/x/","destination":"/y/","type":"301"}, ...]}
 *
 *   GET  /wp-json/cc/v1/redirections        — lista (limit 100, mais recentes primeiro)
 *
 *   POST /wp-json/cc/v1/redirections/purge  — purge cache LiteSpeed/WP
 *
 *   POST /wp-json/cc/v1/redirections/test   — verifica disponibilidade do módulo Rank Math
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    $perm = function () { return current_user_can('manage_options'); };

    register_rest_route('cc/v1', '/redirections', [
        'methods'  => 'POST',
        'callback' => 'cc_redirections_add_batch',
        'permission_callback' => $perm,
    ]);

    register_rest_route('cc/v1', '/redirections', [
        'methods'  => 'GET',
        'callback' => 'cc_redirections_list',
        'permission_callback' => $perm,
    ]);

    register_rest_route('cc/v1', '/redirections/purge', [
        'methods'  => 'POST',
        'callback' => 'cc_redirections_purge_cache',
        'permission_callback' => $perm,
    ]);

    register_rest_route('cc/v1', '/redirections/test', [
        'methods'  => 'GET',
        'callback' => 'cc_redirections_test',
        'permission_callback' => $perm,
    ]);
});

/**
 * Verifica disponibilidade do módulo Redirections do Rank Math.
 */
function cc_redirections_test() {
    return rest_ensure_response([
        'rankmath_class_exists'      => class_exists('\RankMath\Redirections\DB'),
        'litespeed_active'           => defined('LSCWP_V'),
        'redirections_table_exists'  => cc_redirections_table_exists(),
        'plugin_version'             => '1.0',
    ]);
}

function cc_redirections_table_exists() {
    global $wpdb;
    $t = $wpdb->prefix . 'rank_math_redirections';
    return (bool) $wpdb->get_var("SHOW TABLES LIKE '{$t}'");
}

/**
 * Cria batch de redirects via API interna do Rank Math.
 * Body: {"redirects": [{"source":"/x/","destination":"/y/","type":"301"}], "purge_cache": true}
 */
function cc_redirections_add_batch($request) {
    if (!class_exists('\RankMath\Redirections\DB')) {
        return new WP_Error('rankmath_missing',
            'Rank Math Redirections module não está ativo. Ative em Rank Math → Dashboard → Modules → Redirections.',
            ['status' => 500]);
    }

    $body = $request->get_json_params();
    $redirects = $body['redirects'] ?? [];
    $purgeCache = !empty($body['purge_cache']);

    if (empty($redirects) || !is_array($redirects)) {
        return new WP_Error('invalid_payload', 'Body precisa ter array "redirects"', ['status' => 400]);
    }

    $resultado = [];
    foreach ($redirects as $i => $r) {
        $source      = trim((string)($r['source'] ?? ''));
        $destination = trim((string)($r['destination'] ?? ''));
        $type        = (string)($r['type'] ?? '301');
        $matchType   = (string)($r['match'] ?? 'exact'); // exact|contains|start|end|regex

        if ($source === '' || $destination === '') {
            $resultado[] = ['ok' => false, 'index' => $i, 'source' => $source, 'error' => 'source/destination vazios'];
            continue;
        }
        if (!in_array($type, ['301','302','307','410','451'], true)) {
            $resultado[] = ['ok' => false, 'index' => $i, 'source' => $source, 'error' => 'type inválido'];
            continue;
        }

        // Source no Rank Math: pattern relativo (sem domínio). Garante leading "/" e trailing "/"
        $sourcePath = $source;
        if (preg_match('#^https?://#', $sourcePath)) {
            $sourcePath = parse_url($sourcePath, PHP_URL_PATH) ?: $sourcePath;
        }
        if ($sourcePath !== '' && $sourcePath[0] !== '/') $sourcePath = '/' . $sourcePath;

        $args = [
            'sources' => [[
                'pattern'    => $sourcePath,
                'comparison' => $matchType,
                'ignore'     => null,
            ]],
            'url_to'      => $destination,
            'header_code' => $type,
            'status'      => 'active',
        ];

        try {
            $id = \RankMath\Redirections\DB::add($args);
            if ($id && $id > 0) {
                $resultado[] = [
                    'ok'          => true,
                    'index'       => $i,
                    'id'          => (int)$id,
                    'source'      => $sourcePath,
                    'destination' => $destination,
                    'type'        => $type,
                ];
            } else {
                $resultado[] = ['ok' => false, 'index' => $i, 'source' => $sourcePath, 'error' => 'DB::add retornou 0/false (provável duplicata?)'];
            }
        } catch (Throwable $e) {
            $resultado[] = ['ok' => false, 'index' => $i, 'source' => $sourcePath, 'error' => $e->getMessage()];
        }
    }

    $cachePurged = false;
    if ($purgeCache) {
        $cachePurged = cc_redirections_do_purge();
    }

    $criados = count(array_filter($resultado, fn($r) => $r['ok']));
    $falhas  = count($resultado) - $criados;

    return rest_ensure_response([
        'success'      => true,
        'total'        => count($redirects),
        'criados'      => $criados,
        'falhas'       => $falhas,
        'cache_purged' => $cachePurged,
        'resultados'   => $resultado,
    ]);
}

/**
 * Lista redirects (mais recentes primeiro). Limit 100.
 */
function cc_redirections_list() {
    global $wpdb;
    $tabela = $wpdb->prefix . 'rank_math_redirections';
    if (!cc_redirections_table_exists()) {
        return new WP_Error('table_missing', 'Tabela rank_math_redirections não existe — módulo desativado?', ['status' => 500]);
    }
    $rows = $wpdb->get_results("SELECT id, sources, url_to, header_code, status, hits, created, updated FROM {$tabela} ORDER BY id DESC LIMIT 100", ARRAY_A);
    foreach ($rows as &$r) {
        $r['sources'] = maybe_unserialize($r['sources']);
    }
    return rest_ensure_response($rows);
}

/**
 * Purga cache LiteSpeed e WP.
 */
function cc_redirections_purge_cache() {
    $purged = cc_redirections_do_purge();
    return rest_ensure_response([
        'success'  => $purged !== false,
        'method'   => $purged,
    ]);
}

function cc_redirections_do_purge() {
    if (defined('LSCWP_V')) {
        do_action('litespeed_purge_all');
        return 'litespeed_purge_all';
    }
    if (class_exists('LiteSpeed\Purge')) {
        try { \LiteSpeed\Purge::purge_all(); return 'litespeed_purge_all_class'; } catch (Throwable $e) {}
    }
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
        return 'wp_cache_flush';
    }
    return false;
}
