<?php
/**
 * Leão da Barra - Theme Functions
 * 
 * Tema otimizado para Core Web Vitals, Google Discover e SEO
 * Integração com API Futebol (api-futebol.com.br)
 * 
 * @package LeaoDaBarra
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

// ============================================================
// CONSTANTES
// ============================================================
define('LDB_VERSION', '1.2.0');
define('LDB_DIR', get_template_directory());
define('LDB_URI', get_template_directory_uri());
define('LDB_API_BASE', 'https://api.api-futebol.com.br/v1');
define('LDB_VITORIA_ID', 50); // ID do Vitória na API

/**
 * Retorna a chave API de forma segura
 */
function ldb_get_api_key() {
    static $key = null;
    if ($key === null) {
        $key = get_option('ldb_api_key', 'live_89a828b63fb3af951cc97c11ab816e');
    }
    return $key;
}

// ============================================================
// INCLUDES
// ============================================================
require_once LDB_DIR . '/inc/api-futebol.php';
require_once LDB_DIR . '/inc/seo.php';
require_once LDB_DIR . '/inc/performance.php';
require_once LDB_DIR . '/inc/customizer.php';
require_once LDB_DIR . '/inc/widgets.php';
require_once LDB_DIR . '/inc/shortcodes.php';
require_once LDB_DIR . '/inc/quiz.php';

// ============================================================
// THEME SETUP
// ============================================================
function ldb_setup() {
    // Suporte a tradução
    load_theme_textdomain('leao-da-barra', LDB_DIR . '/languages');

    // Suporte a funcionalidades do WordPress
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', [
        'search-form', 'comment-form', 'comment-list',
        'gallery', 'caption', 'style', 'script',
    ]);
    add_theme_support('custom-logo', [
        'height'      => 80,
        'width'       => 250,
        'flex-height' => true,
        'flex-width'  => true,
    ]);
    add_theme_support('responsive-embeds');
    add_theme_support('wp-block-styles');
    add_theme_support('editor-styles');
    add_theme_support('automatic-feed-links');

    // Tamanhos de imagem otimizados para Discover e performance
    add_image_size('ldb-hero', 1200, 630, true);        // Open Graph / Discover
    add_image_size('ldb-card-large', 800, 450, true);    // Card grande
    add_image_size('ldb-card-medium', 600, 340, true);   // Card médio
    add_image_size('ldb-card-small', 300, 200, true);    // Card pequeno / thumb
    add_image_size('ldb-square', 400, 400, true);        // Avatar / quadrado

    // Menus
    register_nav_menus([
        'primary'   => __('Menu Principal', 'leao-da-barra'),
        'footer'    => __('Menu Rodapé', 'leao-da-barra'),
        'mobile'    => __('Menu Mobile', 'leao-da-barra'),
    ]);
}
add_action('after_setup_theme', 'ldb_setup');

// Flush rewrite rules on theme activation (needed for Rank Math sitemap, CPTs, etc.)
function ldb_flush_rewrites() {
    ldb_register_post_types();
    ldb_register_taxonomies();
    flush_rewrite_rules();
}
add_action('after_switch_theme', 'ldb_flush_rewrites');

// ============================================================
// ENQUEUE ASSETS (Otimizado para Core Web Vitals)
// ============================================================
function ldb_enqueue_assets() {
    // CSS Crítico inline no head (via performance.php)
    // CSS principal com preload
    wp_enqueue_style(
        'ldb-main',
        LDB_URI . '/assets/css/main.css',
        [],
        LDB_VERSION
    );

    // Google Fonts com preconnect (Oswald + Source Sans 3)
    wp_enqueue_style(
        'ldb-fonts',
        'https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Source+Sans+3:wght@400;500;600&display=swap',
        [],
        null
    );

    // JS principal (defer por padrão no WP 6.3+)
    wp_enqueue_script(
        'ldb-main',
        LDB_URI . '/assets/js/main.js',
        [],
        LDB_VERSION,
        ['strategy' => 'defer', 'in_footer' => true]
    );

    // JS da API (carregamento dinâmico)
    wp_enqueue_script(
        'ldb-api',
        LDB_URI . '/assets/js/api-futebol.js',
        [],
        LDB_VERSION,
        ['strategy' => 'defer', 'in_footer' => true]
    );

    // Escale o Vitória (só na página com template)
    if (is_page_template('templates/page-escale.php')) {
        wp_enqueue_script(
            'ldb-escale',
            LDB_URI . '/assets/js/escale-vitoria.js',
            [],
            LDB_VERSION,
            ['strategy' => 'defer', 'in_footer' => true]
        );
    }

    // Quiz do Vitória (só na página com template)
    if (is_page_template('templates/page-quiz.php')) {
        wp_enqueue_script(
            'ldb-quiz',
            LDB_URI . '/assets/js/quiz-vitoria.js',
            [],
            LDB_VERSION,
            ['strategy' => 'defer', 'in_footer' => true]
        );
    }

    // Localize para o JS
    wp_localize_script('ldb-api', 'ldbConfig', [
        'ajaxUrl'   => admin_url('admin-ajax.php'),
        'nonce'     => wp_create_nonce('ldb_api_nonce'),
        'restUrl'   => rest_url('ldb/v1/'),
        'restNonce' => wp_create_nonce('wp_rest'),
        'vitoriaId' => LDB_VITORIA_ID,
    ]);

    // Remove jQuery do front se não for necessário
    if (!is_admin() && !is_customize_preview()) {
        wp_deregister_script('jquery');
    }
}
add_action('wp_enqueue_scripts', 'ldb_enqueue_assets');

// ============================================================
// CUSTOM POST TYPES
// ============================================================
function ldb_register_post_types() {
    // Bastidores
    register_post_type('bastidores', [
        'labels' => [
            'name'          => __('Bastidores', 'leao-da-barra'),
            'singular_name' => __('Bastidor', 'leao-da-barra'),
            'add_new_item'  => __('Novo Bastidor', 'leao-da-barra'),
        ],
        'public'       => true,
        'has_archive'  => true,
        'rewrite'      => ['slug' => 'bastidores'],
        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt', 'author', 'comments'],
        'menu_icon'    => 'dashicons-megaphone',
        'show_in_rest' => true,
        'taxonomies'   => ['category', 'post_tag'],
    ]);

    // História
    register_post_type('historia', [
        'labels' => [
            'name'          => __('História', 'leao-da-barra'),
            'singular_name' => __('História', 'leao-da-barra'),
        ],
        'public'       => true,
        'has_archive'  => true,
        'rewrite'      => ['slug' => 'historia'],
        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt', 'page-attributes'],
        'menu_icon'    => 'dashicons-book-alt',
        'show_in_rest' => true,
        'hierarchical' => true,
    ]);
}
add_action('init', 'ldb_register_post_types');

// ============================================================
// CUSTOM TAXONOMIES
// ============================================================
function ldb_register_taxonomies() {
    // Campeonato
    register_taxonomy('campeonato', ['post', 'bastidores'], [
        'labels' => [
            'name'          => __('Campeonatos', 'leao-da-barra'),
            'singular_name' => __('Campeonato', 'leao-da-barra'),
        ],
        'public'       => true,
        'hierarchical' => true,
        'rewrite'      => ['slug' => 'campeonato'],
        'show_in_rest' => true,
    ]);

    // Time
    register_taxonomy('time', ['post', 'bastidores'], [
        'labels' => [
            'name'          => __('Times', 'leao-da-barra'),
            'singular_name' => __('Time', 'leao-da-barra'),
        ],
        'public'       => true,
        'hierarchical' => false,
        'rewrite'      => ['slug' => 'time'],
        'show_in_rest' => true,
    ]);

    // Escopo (Vitória, Nacional, Internacional)
    register_taxonomy('escopo', ['post', 'bastidores'], [
        'labels' => [
            'name'          => __('Escopo', 'leao-da-barra'),
            'singular_name' => __('Escopo', 'leao-da-barra'),
        ],
        'public'       => true,
        'hierarchical' => true,
        'rewrite'      => ['slug' => 'escopo'],
        'show_in_rest' => true,
    ]);
}
add_action('init', 'ldb_register_taxonomies');

// ============================================================
// SIDEBAR / WIDGET AREAS
// ============================================================
function ldb_widgets_init() {
    register_sidebar([
        'name'          => __('Sidebar Principal', 'leao-da-barra'),
        'id'            => 'sidebar-main',
        'description'   => __('Sidebar das páginas de notícias', 'leao-da-barra'),
        'before_widget' => '<div id="%1$s" class="ldb-widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="ldb-widget-title">',
        'after_title'   => '</h3>',
    ]);

    register_sidebar([
        'name'          => __('Sidebar Tabela', 'leao-da-barra'),
        'id'            => 'sidebar-tabela',
        'description'   => __('Widget para tabela de classificação', 'leao-da-barra'),
        'before_widget' => '<div id="%1$s" class="ldb-widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="ldb-widget-title">',
        'after_title'   => '</h3>',
    ]);

    register_sidebar([
        'name'          => __('Footer Coluna 1', 'leao-da-barra'),
        'id'            => 'footer-1',
        'before_widget' => '<div id="%1$s" class="ldb-footer-widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h4 class="ldb-footer-title">',
        'after_title'   => '</h4>',
    ]);

    register_sidebar([
        'name'          => __('Footer Coluna 2', 'leao-da-barra'),
        'id'            => 'footer-2',
        'before_widget' => '<div id="%1$s" class="ldb-footer-widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h4 class="ldb-footer-title">',
        'after_title'   => '</h4>',
    ]);
}
add_action('widgets_init', 'ldb_widgets_init');

// ============================================================
// REST API ENDPOINTS (proxy para API Futebol)
// ============================================================
function ldb_register_rest_routes() {
    register_rest_route('ldb/v1', '/campeonatos', [
        'methods'             => 'GET',
        'callback'            => 'ldb_rest_campeonatos',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('ldb/v1', '/tabela/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'ldb_rest_tabela',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => ['validate_callback' => fn($p) => is_numeric($p)],
        ],
    ]);

    register_rest_route('ldb/v1', '/ao-vivo', [
        'methods'             => 'GET',
        'callback'            => 'ldb_rest_ao_vivo',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('ldb/v1', '/partidas/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'ldb_rest_partida',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => ['validate_callback' => fn($p) => is_numeric($p)],
        ],
    ]);

    register_rest_route('ldb/v1', '/time/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'ldb_rest_time',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => ['validate_callback' => fn($p) => is_numeric($p)],
        ],
    ]);

    register_rest_route('ldb/v1', '/rodadas/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'ldb_rest_rodadas',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => ['validate_callback' => fn($p) => is_numeric($p)],
        ],
    ]);

    register_rest_route('ldb/v1', '/rodadas/(?P<id>\d+)/(?P<rodada>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'ldb_rest_rodada_num',
        'permission_callback' => '__return_true',
    ]);
}
add_action('rest_api_init', 'ldb_register_rest_routes');

function ldb_rest_campeonatos() {
    $data = ldb_api_get('/campeonatos');
    return rest_ensure_response($data);
}

function ldb_rest_tabela($request) {
    $id = $request->get_param('id');
    $data = ldb_api_get("/campeonatos/{$id}/tabela");
    return rest_ensure_response($data);
}

function ldb_rest_rodadas($request) {
    $id = $request->get_param('id');
    $data = ldb_api_get("/campeonatos/{$id}/rodadas", 300);
    return rest_ensure_response($data);
}

function ldb_rest_rodada_num($request) {
    $id = $request->get_param('id');
    $rodada = $request->get_param('rodada');
    $data = ldb_api_get("/campeonatos/{$id}/rodadas/{$rodada}", 300);
    return rest_ensure_response($data);
}

function ldb_rest_ao_vivo() {
    $data = ldb_api_get('/ao-vivo', 60);
    return rest_ensure_response($data);
}

function ldb_rest_partida($request) {
    $id = $request->get_param('id');
    $data = ldb_api_get("/partidas/{$id}");
    return rest_ensure_response($data);
}

function ldb_rest_time($request) {
    $id = $request->get_param('id');
    $data = ldb_api_get("/times/{$id}");
    return rest_ensure_response($data);
}

// ============================================================
// ADMIN SETTINGS PAGE
// ============================================================
function ldb_admin_menu() {
    add_menu_page(
        __('Leão da Barra', 'leao-da-barra'),
        __('Leão da Barra', 'leao-da-barra'),
        'manage_options',
        'ldb-settings',
        'ldb_settings_page',
        'dashicons-shield',
        30
    );
}
add_action('admin_menu', 'ldb_admin_menu', 5);

function ldb_settings_init() {
    register_setting('ldb_settings', 'ldb_api_key', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);

    register_setting('ldb_settings', 'ldb_vitoria_id', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 50,
    ]);

    register_setting('ldb_settings', 'ldb_google_client_id', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);

    register_setting('ldb_settings', 'ldb_quiz_reward', [
        'type'              => 'string',
        'sanitize_callback' => 'wp_kses_post',
        'default'           => '',
    ]);

    // API Section
    add_settings_section(
        'ldb_api_section',
        __('API Futebol', 'leao-da-barra'),
        function() { echo '<p>' . esc_html__('Configure sua chave de API do api-futebol.com.br', 'leao-da-barra') . '</p>'; },
        'ldb-settings'
    );

    add_settings_field('ldb_api_key', __('Chave API', 'leao-da-barra'), function() {
        $value = get_option('ldb_api_key', '');
        echo '<input type="text" name="ldb_api_key" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Obtenha em <a href="https://api-futebol.com.br" target="_blank">api-futebol.com.br</a></p>';
    }, 'ldb-settings', 'ldb_api_section');

    add_settings_field('ldb_vitoria_id', __('ID do Vitória na API', 'leao-da-barra'), function() {
        $value = get_option('ldb_vitoria_id', 50);
        echo '<input type="number" name="ldb_vitoria_id" value="' . esc_attr($value) . '" class="small-text" />';
    }, 'ldb-settings', 'ldb_api_section');

    // Quiz Section
    add_settings_section(
        'ldb_quiz_section',
        __('Quiz do Vitória', 'leao-da-barra'),
        function() { echo '<p>' . esc_html__('Configurações do Quiz interativo', 'leao-da-barra') . '</p>'; },
        'ldb-settings'
    );

    add_settings_field('ldb_google_client_id', __('Google Client ID', 'leao-da-barra'), function() {
        $value = get_option('ldb_google_client_id', '');
        echo '<input type="text" name="ldb_google_client_id" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Crie em <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a> → Credenciais → ID do cliente OAuth 2.0</p>';
    }, 'ldb-settings', 'ldb_quiz_section');

    add_settings_field('ldb_quiz_reward', __('Recompensa (100%)', 'leao-da-barra'), function() {
        $value = get_option('ldb_quiz_reward', '');
        echo '<textarea name="ldb_quiz_reward" rows="4" class="large-text">' . esc_textarea($value) . '</textarea>';
        echo '<p class="description">HTML exibido para quem acertar 100%. Pode ser cupom, link, imagem, etc.</p>';
    }, 'ldb-settings', 'ldb_quiz_section');
}
add_action('admin_init', 'ldb_settings_init');

function ldb_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('ldb_settings');
            do_settings_sections('ldb-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// ============================================================
// HELPERS
// ============================================================

/**
 * Retorna tempo relativo em português
 */
function ldb_time_ago($date) {
    $diff = time() - strtotime($date);
    if ($diff < 60) return 'agora';
    if ($diff < 3600) return floor($diff / 60) . ' min atrás';
    if ($diff < 86400) return floor($diff / 3600) . 'h atrás';
    if ($diff < 604800) return floor($diff / 86400) . 'd atrás';
    return date_i18n('d/m/Y', strtotime($date));
}

/**
 * Retorna a cor da categoria/escopo
 */
function ldb_escopo_class($escopo) {
    return match (strtolower($escopo)) {
        'vitória', 'vitoria' => 'cat-vitoria',
        'nacional'           => 'cat-nacional',
        'internacional'      => 'cat-internacional',
        default              => 'cat-default',
    };
}

/**
 * Breadcrumb JSON-LD para SEO
 */
function ldb_breadcrumb_schema() {
    if (is_front_page()) return;

    $items = [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => home_url('/')],
    ];

    $pos = 2;

    if (is_category()) {
        $cat = get_queried_object();
        $items[] = ['@type' => 'ListItem', 'position' => $pos, 'name' => $cat->name];
    } elseif (is_single()) {
        $cats = get_the_category();
        if ($cats) {
            $items[] = ['@type' => 'ListItem', 'position' => $pos++, 'name' => $cats[0]->name, 'item' => get_category_link($cats[0]->term_id)];
        }
        $items[] = ['@type' => 'ListItem', 'position' => $pos, 'name' => get_the_title()];
    }

    $schema = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    ];

    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
add_action('wp_head', 'ldb_breadcrumb_schema');

// ============================================================
// INFINITE SCROLL - AJAX ENDPOINT
// ============================================================
function ldb_infinite_scroll_handler() {
    check_ajax_referer('ldb_api_nonce', 'nonce');

    $per_page = absint($_POST['per_page'] ?? 8);
    $category = sanitize_text_field($_POST['category'] ?? '');

    // Parse exclude IDs - all posts already shown on the page
    $raw_exclude = $_POST['exclude'] ?? [];
    $exclude = [];
    if (is_string($raw_exclude)) {
        $exclude = array_filter(array_map('absint', explode(',', $raw_exclude)));
    } elseif (is_array($raw_exclude)) {
        foreach ($raw_exclude as $val) {
            $ids = array_filter(array_map('absint', explode(',', strval($val))));
            $exclude = array_merge($exclude, $ids);
        }
    }
    $exclude = array_unique(array_filter($exclude));

    $args = [
        'posts_per_page'   => $per_page,
        'post_status'      => 'publish',
        'post__not_in'     => $exclude,
        'orderby'          => 'date',
        'order'            => 'DESC',
        'no_found_rows'    => false,
    ];

    if ($category && $category !== 'todas') {
        $args['category_name'] = $category;
    }

    // DISTINCT to avoid dupes from multi-category posts
    add_filter('posts_distinct', function() { return 'DISTINCT'; });

    $query = new WP_Query($args);
    $posts = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $pid = get_the_ID();

            $cats = get_the_category();
            $cat_name = $cats ? $cats[0]->name : '';
            $cat_slug = $cats ? $cats[0]->slug : '';

            $thumb = '';
            if (has_post_thumbnail()) {
                $img_data = wp_get_attachment_image_src(get_post_thumbnail_id(), 'ldb-card-medium');
                $thumb = $img_data ? $img_data[0] : '';
            }

            $posts[] = [
                'id'        => $pid,
                'title'     => get_the_title(),
                'excerpt'   => wp_trim_words(get_the_excerpt(), 18),
                'url'       => get_permalink(),
                'thumbnail' => $thumb,
                'category'  => $cat_name,
                'cat_slug'  => $cat_slug,
                'date'      => get_the_date('d/m/Y'),
                'time_ago'  => ldb_time_ago(get_the_date('Y-m-d H:i:s')),
                'author'    => get_the_author(),
            ];
        }
        wp_reset_postdata();
    }

    // has_more = we got a full batch, so there might be more
    $has_more = count($posts) >= $per_page;

    wp_send_json_success([
        'posts'    => $posts,
        'has_more' => $has_more,
    ]);
}
add_action('wp_ajax_ldb_load_more', 'ldb_infinite_scroll_handler');
add_action('wp_ajax_nopriv_ldb_load_more', 'ldb_infinite_scroll_handler');

/**
 * Paginação customizada
 */
function ldb_pagination() {
    echo '<nav class="ldb-pagination" aria-label="Paginação">';
    the_posts_pagination([
        'prev_text' => '← Anterior',
        'next_text' => 'Próxima →',
        'mid_size'  => 2,
    ]);
    echo '</nav>';
}
