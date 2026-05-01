<?php
/**
 * Plugin Name: CC Instant Indexing API
 * Description: Endpoint REST que aciona o Instant Indexing do Rank Math para URLs específicas.
 * Version: 1.0
 * Author: Como Comprar
 *
 * Instalar: copie para wp-content/plugins/ e ative no WP admin.
 * Requer: Rank Math + módulo "Instant Indexing" configurado (Google Indexing API key setada).
 *
 * Endpoint: POST /wp-json/cc/v1/indexar
 * Auth: Application Password
 * Body JSON: {"url": "https://site.com/slug/", "action": "URL_UPDATED"}  // action opcional; default URL_UPDATED
 * Response: {"success": true, "url": "...", "method": "rank_math|indexnow|wp_ping"}
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('cc/v1', '/indexar', [
        'methods'  => 'POST',
        'callback' => 'cc_indexar_url',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);

    register_rest_route('cc/v1', '/indexar-key', [
        'methods'  => 'GET',
        'callback' => function () {
            $arquivo = cc_indexnow_escrever_arquivo();
            $key     = cc_indexnow_key_get_or_create();
            return rest_ensure_response([
                'key'          => $key,
                'key_location' => home_url('/' . $key . '.txt'),
                'file_path'    => $arquivo,
                'file_exists'  => file_exists($arquivo),
                'file_size'    => file_exists($arquivo) ? filesize($arquivo) : 0,
            ]);
        },
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);

    // Força uma chave específica (resolve conflito "UserForbiddedToAccessSite")
    // Body: {"key": "xxxxxxxxxxxxxxxx"}  (alfanumérico, 8-128 chars)
    register_rest_route('cc/v1', '/indexar-key', [
        'methods'  => 'POST',
        'callback' => function ($request) {
            $key = preg_replace('/[^a-zA-Z0-9]/', '', (string)$request->get_param('key'));
            if (strlen($key) < 8 || strlen($key) > 128) {
                return new WP_Error('invalid_key', 'Chave deve ter 8-128 caracteres alfanuméricos', ['status' => 400]);
            }
            // Remove arquivo antigo se existir
            $antiga = get_option('cc_indexnow_key');
            if ($antiga && $antiga !== $key) {
                $antigo = ABSPATH . $antiga . '.txt';
                if (file_exists($antigo)) @unlink($antigo);
            }
            update_option('cc_indexnow_key', $key);
            $arquivo = cc_indexnow_escrever_arquivo();
            return rest_ensure_response([
                'key'          => $key,
                'key_location' => home_url('/' . $key . '.txt'),
                'file_path'    => $arquivo,
                'file_exists'  => file_exists($arquivo),
            ]);
        },
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);
});

/**
 * Garante o arquivo {key}.txt na raiz do site.
 * Estratégia dupla:
 *  1. Escreve arquivo físico em ABSPATH . {key}.txt (preferido — cacheável, sem WP)
 *  2. Fallback via hook init servindo o conteúdo dinamicamente (caso FS seja read-only)
 */
/**
 * Retorna (ou cria) a chave IndexNow usada por este site.
 * Prioridade: chave já gravada > chave do Rank Math (se instalado) > nova gerada.
 *
 * IMPORTANTE: o Bing "casa" um host com UMA única chave. Se o site já foi
 * verificado antes com outra chave (ex.: Rank Math), uma chave nova causa
 * erro 403 UserForbiddedToAccessSite. Por isso reusamos a chave existente.
 */
function cc_indexnow_key_get_or_create() {
    $key = get_option('cc_indexnow_key');
    if ($key) return $key;

    // Procura chave existente de outros plugins conhecidos (Rank Math, etc.)
    $candidatos = [
        'rank_math_instant_indexing_bing_api_key',
        'rank_math_instant_indexing_api_key',
        'rank_math_indexnow_api_key',
        'indexnow_api_key',
        'indexnow_key',
    ];
    foreach ($candidatos as $opt) {
        $v = get_option($opt);
        if (is_string($v) && strlen(trim($v)) >= 8) {
            $key = trim($v);
            update_option('cc_indexnow_key', $key);
            return $key;
        }
    }

    // Nada encontrado — gera uma nova
    $key = bin2hex(random_bytes(16));
    update_option('cc_indexnow_key', $key);
    return $key;
}

function cc_indexnow_escrever_arquivo() {
    $key = cc_indexnow_key_get_or_create();
    $arquivo = ABSPATH . $key . '.txt';
    if (!file_exists($arquivo) || trim((string)@file_get_contents($arquivo)) !== $key) {
        @file_put_contents($arquivo, $key);
    }
    return $arquivo;
}

// Escreve o arquivo físico ao ativar o plugin
register_activation_hook(__FILE__, 'cc_indexnow_escrever_arquivo');

// Fallback: se o arquivo físico não existir (FS read-only), serve via init hook
add_action('init', function () {
    $key = get_option('cc_indexnow_key');
    if (!$key) return;

    // Tenta garantir que o arquivo exista (1x por requisição é barato)
    $arquivo = ABSPATH . $key . '.txt';
    if (!file_exists($arquivo)) {
        @file_put_contents($arquivo, $key);
    }

    // Se mesmo assim não existe (FS bloqueado), serve via PHP
    $req  = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($req, PHP_URL_PATH) ?: '';
    if (rtrim($path, '/') === '/' . $key . '.txt' && !file_exists($arquivo)) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo $key;
        exit;
    }
}, 1);

function cc_indexar_url($request) {
    $url    = esc_url_raw($request->get_param('url'));
    $action = sanitize_text_field($request->get_param('action') ?: 'URL_UPDATED');

    if (!$url) {
        return new WP_Error('missing_url', 'URL obrigatória', ['status' => 400]);
    }

    $method = null;
    $result = null;
    $error  = '';

    // 1ª opção: Rank Math Instant Indexing (usa Google Indexing API)
    if (class_exists('\\RankMath\\Instant_Indexing\\Api')) {
        try {
            $api = \RankMath\Instant_Indexing\Api::get();
            if (method_exists($api, 'publish')) {
                $result = $api->publish($url, $action);
                $method = 'rank_math_publish';
            }
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }

    // 2ª opção: action hook do Rank Math (versões mais novas)
    if ($result === null && has_action('rank_math/instant_indexing/send')) {
        try {
            do_action('rank_math/instant_indexing/send', $url, $action);
            $result = true;
            $method = 'rank_math_action';
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }

    // 3ª opção: IndexNow (Bing/Yandex) — fallback universal
    if ($result === null) {
        cc_indexnow_escrever_arquivo(); // garante arquivo físico
        $key = cc_indexnow_key_get_or_create();
        $host = parse_url(home_url(), PHP_URL_HOST);
        $keyLocation = home_url('/' . $key . '.txt');

        // Verifica se o arquivo de chave está acessível (senão IndexNow retorna 403)
        $check = wp_remote_get($keyLocation, ['timeout' => 8, 'sslverify' => false]);
        $keyOk = !is_wp_error($check)
            && wp_remote_retrieve_response_code($check) === 200
            && trim((string)wp_remote_retrieve_body($check)) === $key;

        if (!$keyOk) {
            $error = "IndexNow: arquivo de chave não acessível em {$keyLocation} — ative/atualize o plugin e tente de novo";
        } else {
            // Endpoints em ordem: api.indexnow.org → Bing direto
            $endpoints = [
                'https://api.indexnow.org/indexnow',
                'https://www.bing.com/indexnow',
            ];
            foreach ($endpoints as $ep) {
                $resp = wp_remote_post($ep, [
                    'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                    'body'    => wp_json_encode([
                        'host'        => $host,
                        'key'         => $key,
                        'keyLocation' => $keyLocation,
                        'urlList'     => [$url],
                    ]),
                    'timeout' => 15,
                    'sslverify' => false,
                ]);
                if (is_wp_error($resp)) { $error = $resp->get_error_message(); continue; }
                $code = wp_remote_retrieve_response_code($resp);
                if ($code >= 200 && $code < 300) {
                    $result = true;
                    $method = 'indexnow';
                    $error  = '';
                    break;
                }
                $error = "IndexNow HTTP {$code} em {$ep} — " . substr((string)wp_remote_retrieve_body($resp), 0, 120);
            }
        }
    }

    // 4ª opção: ping genérico (pouco eficaz, mas não falha)
    if ($result === null) {
        wp_remote_get('https://www.google.com/ping?sitemap=' . urlencode(home_url('/sitemap_index.xml')), ['timeout' => 10]);
        $method = 'wp_ping_sitemap';
        $result = true;
    }

    return rest_ensure_response([
        'success' => (bool)$result,
        'url'     => $url,
        'method'  => $method,
        'error'   => $error ?: null,
    ]);
}
