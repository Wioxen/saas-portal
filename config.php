<?php
/**
 * Configurações globais — wrapper do .env.
 *
 * As chaves sensíveis vivem em `.env` (nunca commitado).
 * Este arquivo é apenas o adaptador: lê do .env e retorna o array
 * que o resto do projeto já espera (formato legado preservado).
 *
 * Setup inicial:
 *   1. cp .env.example .env
 *   2. preencha as chaves reais no .env
 *   3. pronto — config.php resolve tudo sozinho
 */

require_once __DIR__ . '/lib/Env.php';
Env::load(__DIR__ . '/.env');

// Timezone canônica do projeto: tudo (logs, prompts LLM, "hoje" no artigo, agendamento)
// roda em horário Brasília. Sem isso, XAMPP/Windows herda Europe/Berlin (UTC+2) e o
// pipeline vira o dia 5h antes da meia-noite real BR — LLM gera "hoje, 29 de abril"
// quando ainda é 28 em Brasília. Override por env só se realmente precisar.
date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));

return [
    // Serper
    'serper_api_key'    => Env::get('SERPER_API_KEY', ''),

    // Pexels (featured image cascade)
    'pexels_api_key'    => Env::get('PEXELS_API_KEY', ''),
    'imagem_featured_estrategia'      => Env::get('IMAGEM_FEATURED_ESTRATEGIA', 'pexels_first'),
    'imagem_featured_dalle_fallback'  => (bool)Env::get('IMAGEM_FEATURED_DALLE_FALLBACK', 1),

    // OpenAI
    'openai_api_key'    => Env::get('OPENAI_API_KEY', ''),
    'openai_model'      => Env::get('OPENAI_MODEL', 'gpt-4o-mini'),

    // Anthropic
    'anthropic_api_key' => Env::get('ANTHROPIC_API_KEY', ''),
    'anthropic_model'   => Env::get('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),

    // LLM padrão: claude | openai (determina qual provedor roda primeiro no pipeline).
    'default_llm'       => Env::get('DEFAULT_LLM', 'claude'),

    // WordPress
    'wp_url'            => Env::get('WP_URL', ''),
    'wp_user'           => Env::get('WP_USER', ''),
    'wp_app_password'   => Env::get('WP_APP_PASSWORD', ''),
    'wp_default_status' => Env::get('WP_DEFAULT_STATUS', 'draft'),

    // Pretty Links
    'pretty_links'        => true,
    'pretty_links_prefix' => 'go',

    // Web Stories — plugin wp-web-stories-ai (gera stories compatíveis com Google Web Stories plugin)
    'webstory_enabled'    => (int)Env::get('WEBSTORY_ENABLED', 1),
    'webstory_min_scenes' => (int)Env::get('WEBSTORY_MIN_SCENES', 5),
    'webstory_max_scenes' => (int)Env::get('WEBSTORY_MAX_SCENES', 9),
    'webstory_roi_min'    => (float)Env::get('WEBSTORY_ROI_MIN', 5.0),

    // OneSignal push notifications (cursosenacgratuito)
    'onesignal_app_id'         => Env::get('ONESIGNAL_APP_ID', ''),
    'onesignal_rest_api_key'   => Env::get('ONESIGNAL_REST_API_KEY', ''),
    'onesignal_enabled'        => (int)Env::get('ONESIGNAL_ENABLED', 0),
    'onesignal_roi_min'        => (float)Env::get('ONESIGNAL_ROI_MIN', 5.0),
    'onesignal_site_target'    => Env::get('ONESIGNAL_SITE_TARGET', ''),

    // Amazon afiliado default
    'amazon_affiliate_url' => Env::get('AMAZON_AFFILIATE_URL', ''),

    // WhatsApp
    'whatsapp_number'    => Env::get('WHATSAPP_NUMBER', ''),
    'whatsapp_group_url' => Env::get('WHATSAPP_GROUP_URL', ''),
    'whatsapp_cta_text'  => Env::get('WHATSAPP_CTA_TEXT', 'Receba ofertas relâmpago no WhatsApp'),

    // Site local
    'site_name' => Env::get('SITE_NAME', 'Como Comprar'),
    'site_url'  => Env::get('SITE_URL', 'http://localhost/apiclaudephp'),
    'autor'     => Env::get('AUTOR', 'Equipe Como Comprar'),
    'pages_dir' => __DIR__ . '/pages',
    'pages_url' => Env::get('SITE_URL', 'http://localhost/apiclaudephp') . '/pages',

    // Pipeline
    'scrape_top_n'   => 5,
    'scrape_max_try' => 10,
    'scrape_timeout' => 15,
    'user_agent'     => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
];
