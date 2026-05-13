<?php
/**
 * API Futebol Integration
 * 
 * Integração com api-futebol.com.br com cache via transients
 * 
 * @package LeaoDaBarra
 */

defined('ABSPATH') || exit;

/**
 * Faz requisição à API Futebol com cache
 *
 * @param string $endpoint  Endpoint da API (ex: /campeonatos)
 * @param int    $cache_ttl Tempo de cache em segundos (0 = sem cache)
 * @return array|WP_Error
 */
function ldb_api_get($endpoint, $cache_ttl = 300) {
    $cache_key = 'ldb_api_' . md5($endpoint);

    // Tentar cache primeiro
    if ($cache_ttl > 0) {
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
    }

    $api_key = function_exists('ldb_get_api_key') ? ldb_get_api_key() : get_option('ldb_api_key', '');

    if (empty($api_key)) {
        return new WP_Error('no_api_key', __('Chave API não configurada', 'leao-da-barra'));
    }

    $url = LDB_API_BASE . $endpoint;

    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Accept'        => 'application/json',
        ],
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        // Tentar retornar cache expirado em caso de erro
        $stale = get_option('ldb_stale_' . $cache_key);
        if ($stale) {
            return $stale;
        }
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code !== 200 || !$data) {
        $stale = get_option('ldb_stale_' . $cache_key);
        if ($stale) {
            return $stale;
        }
        return new WP_Error('api_error', sprintf(__('Erro na API: %d', 'leao-da-barra'), $code));
    }

    // Salvar cache
    if ($cache_ttl > 0) {
        set_transient($cache_key, $data, $cache_ttl);
        update_option('ldb_stale_' . $cache_key, $data, false);
    }

    return $data;
}

/**
 * Buscar campeonatos disponíveis
 */
function ldb_get_campeonatos() {
    return ldb_api_get('/campeonatos', 3600); // cache 1h
}

/**
 * Buscar tabela de classificação de um campeonato
 */
function ldb_get_tabela($campeonato_id) {
    return ldb_api_get("/campeonatos/{$campeonato_id}/tabela", 600); // cache 10min
}

/**
 * Buscar rodada de um campeonato
 */
function ldb_get_rodada($campeonato_id, $rodada = null) {
    $endpoint = "/campeonatos/{$campeonato_id}/rodadas";
    if ($rodada) {
        $endpoint .= "/{$rodada}";
    }
    return ldb_api_get($endpoint, 300);
}

/**
 * Buscar partidas ao vivo
 */
function ldb_get_ao_vivo() {
    return ldb_api_get('/ao-vivo', 60); // cache 1min
}

/**
 * Buscar detalhes de uma partida
 */
function ldb_get_partida($partida_id) {
    return ldb_api_get("/partidas/{$partida_id}", 120);
}

/**
 * Buscar dados de um time
 */
function ldb_get_time($time_id) {
    return ldb_api_get("/times/{$time_id}", 86400); // cache 24h
}

/**
 * Buscar fases de um campeonato
 */
function ldb_get_fases($campeonato_id) {
    return ldb_api_get("/campeonatos/{$campeonato_id}/fases", 3600);
}

/**
 * Buscar artilharia de um campeonato
 */
function ldb_get_artilharia($campeonato_id) {
    return ldb_api_get("/campeonatos/{$campeonato_id}/artilharia", 1800);
}

/**
 * Limpar todos os caches da API
 */
function ldb_clear_api_cache() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%ldb_api_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_ldb_api_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_timeout_ldb_api_%'");
}

// Admin bar para limpar cache
function ldb_admin_bar_cache($admin_bar) {
    if (!current_user_can('manage_options')) return;

    $admin_bar->add_node([
        'id'    => 'ldb-clear-cache',
        'title' => '⚽ Limpar Cache API',
        'href'  => wp_nonce_url(admin_url('admin-post.php?action=ldb_clear_cache'), 'ldb_clear_cache'),
    ]);
}
add_action('admin_bar_menu', 'ldb_admin_bar_cache', 100);

function ldb_handle_clear_cache() {
    if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ldb_clear_cache')) {
        wp_die(__('Não autorizado', 'leao-da-barra'));
    }
    ldb_clear_api_cache();
    wp_redirect(wp_get_referer() ?: admin_url());
    exit;
}
add_action('admin_post_ldb_clear_cache', 'ldb_handle_clear_cache');

/**
 * CRON: Atualizar dados do Vitória periodicamente
 */
function ldb_schedule_vitoria_update() {
    if (!wp_next_scheduled('ldb_update_vitoria_data')) {
        wp_schedule_event(time(), 'hourly', 'ldb_update_vitoria_data');
    }
}
add_action('wp', 'ldb_schedule_vitoria_update');

function ldb_update_vitoria_data() {
    $vitoria_id = get_option('ldb_vitoria_id', LDB_VITORIA_ID);
    ldb_get_time($vitoria_id);
    // Atualizar campeonatos ativos
    $campeonatos = ldb_get_campeonatos();
    if (!is_wp_error($campeonatos) && is_array($campeonatos)) {
        foreach ($campeonatos as $camp) {
            if (($camp['status'] ?? '') === 'andamento') {
                ldb_get_tabela($camp['campeonato_id']);
            }
        }
    }
}
add_action('ldb_update_vitoria_data', 'ldb_update_vitoria_data');
