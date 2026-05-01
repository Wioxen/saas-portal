<?php
/**
 * Plugin Name: CC Click Logger
 * Description: Logger de clicks por post_id origem. Hook em template_redirect captura
 *              clicks em /go/* (Pretty Links), grava em wp_cc_click_events e expõe via REST.
 *              Sem isso, attribution post→click→sale fica cega.
 * Version: 1.0
 * Author: Clonais Work
 *
 * Como funciona:
 *   1. Posts gerados pelo SaaS contêm links /go/X?p=POST_ID (attribution via query).
 *   2. Quando alguém clica, antes do PrettyLinks redirecionar, este hook grava:
 *        (link_slug, post_id_origem, ts, ip_hash, ua_hash, referer_hash)
 *      em tabela própria wp_cc_click_events (não toca em wp_prli_clicks).
 *   3. SaaS faz pull diário via /wp-json/cc/v1/clicks/recent?since=ID
 *
 * Privacidade:
 *   - IP/UA são SHA1-truncados (8 chars) — analytics agregada, não rastreio individual.
 *   - LGPD: sem cookies, sem fingerprint, sem persistência identificável.
 *
 * Endpoint REST:
 *   GET /wp-json/cc/v1/clicks/recent?since=123&limit=500
 *   Auth: Application Password (manage_options)
 *   Response: {events: [{id, slug, post_id, ts, ip_hash, ua_hash, referer_hash}], next_since: int}
 *
 * Instalar: copia em wp-content/plugins/, ativa no admin.
 *           Tabela é criada automaticamente no activation hook.
 *
 * Uninstall (manual): DROP TABLE wp_cc_click_events.
 */

if (!defined('ABSPATH')) exit;

define('CC_CLICK_LOGGER_TABLE', 'cc_click_events');
define('CC_CLICK_LOGGER_VERSION', '1.1');
define('CC_CLICK_LOGGER_TTL_DAYS', 90); // dias de retenção; > 90d são apagados pelo cron

// ─────────── ACTIVATION: cria tabela + agenda cron ───────────
register_activation_hook(__FILE__, 'cc_click_logger_activate');
function cc_click_logger_activate() {
    global $wpdb;
    $table = $wpdb->prefix . CC_CLICK_LOGGER_TABLE;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(190) NOT NULL,
        post_id BIGINT UNSIGNED DEFAULT NULL,
        ts INT UNSIGNED NOT NULL,
        ip_hash VARCHAR(16) DEFAULT NULL,
        ua_hash VARCHAR(16) DEFAULT NULL,
        referer_hash VARCHAR(16) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_slug (slug),
        KEY idx_post_id (post_id),
        KEY idx_ts (ts)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('cc_click_logger_version', CC_CLICK_LOGGER_VERSION);

    // Agenda cron diário de TTL cleanup (sem isso, tabela cresce indefinidamente —
    // 100k clicks/mês × 12 = 1.2M rows/ano por site = MySQL fica lento)
    if (!wp_next_scheduled('cc_click_logger_ttl_cleanup')) {
        wp_schedule_event(time() + 3600, 'daily', 'cc_click_logger_ttl_cleanup');
    }
}

// ─────────── DEACTIVATION: tira o cron ───────────
register_deactivation_hook(__FILE__, 'cc_click_logger_deactivate');
function cc_click_logger_deactivate() {
    $next = wp_next_scheduled('cc_click_logger_ttl_cleanup');
    if ($next) wp_unschedule_event($next, 'cc_click_logger_ttl_cleanup');
}

// ─────────── CRON: TTL cleanup diário ───────────
add_action('cc_click_logger_ttl_cleanup', 'cc_click_logger_cleanup');
function cc_click_logger_cleanup() {
    global $wpdb;
    $table = $wpdb->prefix . CC_CLICK_LOGGER_TABLE;
    $cutoff = time() - (CC_CLICK_LOGGER_TTL_DAYS * 86400);
    // DELETE em batch pra não travar a tabela: max 50k rows por execução
    $apagados = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE ts < %d LIMIT 50000", $cutoff
    ));
    if (is_int($apagados) && $apagados > 0) {
        // log via WP error log pra debug; admin pode olhar em Tools > Site Health > Info
        error_log("[cc-click-logger] TTL cleanup: {$apagados} rows >{$cutoff} apagadas");
    }
    // Se chegou no cap (50k), agenda outro cleanup em 1h pra continuar
    if (is_int($apagados) && $apagados >= 50000) {
        wp_schedule_single_event(time() + 3600, 'cc_click_logger_ttl_cleanup');
    }
}

// ─────────── HOOK: captura click ANTES do PrettyLinks redirecionar ───────────
// PrettyLinks usa template_redirect priority 5 ou similar. Priority 1 garante que rodamos antes.
add_action('template_redirect', 'cc_click_logger_capture', 1);

function cc_click_logger_capture() {
    // Detecta se URI é candidata a click (start with /go/ ou outro prefix Pretty Links)
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if ($uri === '') return;

    // Match comum: /go/SLUG ou /ir/SLUG (configurável). Default só /go/.
    $prefixes = apply_filters('cc_click_prefixes', ['go', 'ir']);
    $matched = false;
    $slug = '';
    foreach ($prefixes as $pref) {
        if (preg_match('#^/' . preg_quote($pref, '#') . '/([^/?#]+)#', $uri, $m)) {
            $slug = $pref . '/' . $m[1];
            $matched = true;
            break;
        }
    }
    if (!$matched) return;

    // Filtra bots óbvios (User-Agent vazio ou contém crawler/bot/spider)
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawl|spider|preview|fetch|curl|wget/i', $ua)) {
        return;
    }

    // Extrai post_id de attribution (?p=ID ou _ccp=ID)
    $postId = null;
    if (isset($_GET['p']) && ctype_digit((string)$_GET['p'])) {
        $postId = (int)$_GET['p'];
    } elseif (isset($_GET['_ccp']) && ctype_digit((string)$_GET['_ccp'])) {
        $postId = (int)$_GET['_ccp'];
    } else {
        // Fallback: tenta pelo HTTP_REFERER (pega slug do post no mesmo site)
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref !== '') {
            $refPath = parse_url($ref, PHP_URL_PATH);
            if ($refPath && strlen($refPath) > 1) {
                // Tenta resolver post pelo path (lento mas funciona — só roda em fallback)
                $refPost = @get_page_by_path(trim($refPath, '/'), OBJECT, 'post');
                if ($refPost && $refPost instanceof WP_Post) {
                    $postId = (int)$refPost->ID;
                }
            }
        }
    }

    // Hashes truncados (privacy)
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $ipHash = $ip !== '' ? substr(sha1($ip . wp_salt('auth')), 0, 16) : null;
    $uaHash = substr(sha1($ua . wp_salt('auth')), 0, 16);
    $refHash = $referer !== '' ? substr(sha1($referer . wp_salt('auth')), 0, 16) : null;

    global $wpdb;
    $table = $wpdb->prefix . CC_CLICK_LOGGER_TABLE;
    $wpdb->insert($table, [
        'slug'         => substr($slug, 0, 190),
        'post_id'      => $postId,
        'ts'           => time(),
        'ip_hash'      => $ipHash,
        'ua_hash'      => $uaHash,
        'referer_hash' => $refHash,
    ], ['%s', '%d', '%d', '%s', '%s', '%s']);
}

// ─────────── REST endpoint pra puxar events ───────────
add_action('rest_api_init', function () {
    register_rest_route('cc/v1', '/clicks/recent', [
        'methods'  => 'GET',
        'callback' => 'cc_click_logger_recent',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'args' => [
            'since' => ['type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'],
            'limit' => ['type' => 'integer', 'default' => 500, 'sanitize_callback' => 'absint'],
        ],
    ]);

    register_rest_route('cc/v1', '/clicks/stats', [
        'methods'  => 'GET',
        'callback' => 'cc_click_logger_stats',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'args' => [
            'since_ts' => ['type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'],
        ],
    ]);
});

function cc_click_logger_recent($request) {
    global $wpdb;
    $table = $wpdb->prefix . CC_CLICK_LOGGER_TABLE;
    $since = max(0, (int)$request->get_param('since'));
    $limit = max(1, min(5000, (int)$request->get_param('limit')));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, slug, post_id, ts, ip_hash, ua_hash, referer_hash
         FROM {$table}
         WHERE id > %d
         ORDER BY id ASC
         LIMIT %d",
        $since, $limit
    ), ARRAY_A);

    $events = [];
    foreach (($rows ?: []) as $r) {
        $events[] = [
            'id'           => (int)$r['id'],
            'slug'         => (string)$r['slug'],
            'post_id'      => $r['post_id'] !== null ? (int)$r['post_id'] : null,
            'ts'           => (int)$r['ts'],
            'ip_hash'      => $r['ip_hash'],
            'ua_hash'      => $r['ua_hash'],
            'referer_hash' => $r['referer_hash'],
        ];
    }
    $nextSince = !empty($events) ? end($events)['id'] : $since;

    return rest_ensure_response([
        'events'     => $events,
        'count'      => count($events),
        'next_since' => $nextSince,
        'has_more'   => count($events) >= $limit,
    ]);
}

function cc_click_logger_stats($request) {
    global $wpdb;
    $table = $wpdb->prefix . CC_CLICK_LOGGER_TABLE;
    $sinceTs = max(0, (int)$request->get_param('since_ts'));

    $totals = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) as total,
                COUNT(DISTINCT post_id) as posts_unicos,
                COUNT(DISTINCT slug) as slugs_unicos,
                COUNT(DISTINCT ip_hash) as ips_unicos
         FROM {$table}
         WHERE ts >= %d", $sinceTs
    ), ARRAY_A);

    $topPosts = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, COUNT(*) as clicks
         FROM {$table}
         WHERE ts >= %d AND post_id IS NOT NULL
         GROUP BY post_id
         ORDER BY clicks DESC
         LIMIT 10", $sinceTs
    ), ARRAY_A);

    return rest_ensure_response([
        'totals'    => $totals,
        'top_posts' => $topPosts,
        'since_ts'  => $sinceTs,
    ]);
}
