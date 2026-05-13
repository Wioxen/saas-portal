<?php
/**
 * ComoComprar Theme Functions
 *
 * @package ComoComprar
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

define('CC_VERSION', '1.0.0');
define('CC_DIR', get_template_directory());
define('CC_URI', get_template_directory_uri());

// ─── THEME SETUP ───────────────────────────────────────────
function cc_setup() {
    // Translations
    load_theme_textdomain('comocomprar', CC_DIR . '/languages');

    // Theme supports
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('automatic-feed-links');
    add_theme_support('responsive-embeds');
    add_theme_support('custom-logo', [
        'height'      => 64,
        'width'       => 200,
        'flex-height' => true,
        'flex-width'  => true,
    ]);
    add_theme_support('editor-styles');
    add_theme_support('wp-block-styles');
    add_theme_support('align-wide');

    // Image sizes (Discover optimized)
    add_image_size('cc-hero', 1200, 630, true);       // 16:9 OG/Discover
    add_image_size('cc-card', 600, 338, true);         // 16:9 cards
    add_image_size('cc-thumb', 300, 225, true);        // 4:3 sidebar

    // Nav menus
    register_nav_menus([
        'primary' => __('Menu Principal', 'comocomprar'),
        'footer'  => __('Menu Rodapé', 'comocomprar'),
    ]);
}
add_action('after_setup_theme', 'cc_setup');

// ─── CONTENT WIDTH ─────────────────────────────────────────
function cc_content_width() {
    $GLOBALS['content_width'] = 740;
}
add_action('after_setup_theme', 'cc_content_width', 0);

// ─── ENQUEUE STYLES & SCRIPTS ──────────────────────────────
function cc_enqueue_assets() {
    // Main stylesheet — non-blocking (critical CSS is inlined in header.php)
    wp_enqueue_style(
        'cc-style',
        get_stylesheet_uri(),
        [],
        CC_VERSION
    );

    // Minimal JS (deferred)
    wp_enqueue_script(
        'cc-main',
        CC_URI . '/js/main.js',
        [],
        CC_VERSION,
        true
    );

    // Comment reply script
    if (is_singular() && comments_open() && get_option('thread_comments')) {
        wp_enqueue_script('comment-reply');
    }
}
add_action('wp_enqueue_scripts', 'cc_enqueue_assets');

// ─── PRELOAD CRITICAL RESOURCES ────────────────────────────
// Preconnect removed — fonts load via JS after render
// This eliminates the fonts.googleapis.com from critical chain

// ─── GOOGLE FONTS: Load via JS after render (zero CLS) ──────
// Fonts are loaded after DOMContentLoaded to guarantee zero layout shift.
// The font-face fallbacks with size-adjust in CSS ensure text looks correct
// with system fonts, then silently upgrades when web fonts are cached.
function cc_load_fonts_via_js() {
    echo "<script>addEventListener('DOMContentLoaded',function(){var l=document.createElement('link');l.rel='stylesheet';l.href='https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=Source+Sans+3:wght@400;600;700&display=optional';document.head.appendChild(l)});</script>\n";
}
add_action('wp_footer', 'cc_load_fonts_via_js', 5);

// ─── REMOVE UNNECESSARY WP HEAD ITEMS ─────────────────────
function cc_cleanup_head() {
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wp_shortlink_wp_head');
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');
}
add_action('init', 'cc_cleanup_head');

// Remove jQuery migrate and move jQuery to footer
function cc_remove_jquery_migrate($scripts) {
    if (!is_admin() && isset($scripts->registered['jquery'])) {
        $script = $scripts->registered['jquery'];
        if ($script->deps) {
            $script->deps = array_diff($script->deps, ['jquery-migrate']);
        }
    }
    // Move jQuery to footer to prevent render blocking
    if (!is_admin() && isset($scripts->registered['jquery-core'])) {
        $scripts->registered['jquery-core']->args = 1; // 1 = footer
    }
}
add_action('wp_default_scripts', 'cc_remove_jquery_migrate');

// ─── WIDGET AREAS ──────────────────────────────────────────
function cc_widgets_init() {
    register_sidebar([
        'name'          => __('Sidebar Principal', 'comocomprar'),
        'id'            => 'sidebar-main',
        'before_widget' => '<div id="%1$s" class="cc-widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="cc-widget__title">',
        'after_title'   => '</h3>',
    ]);

    register_sidebar([
        'name'          => __('Rodapé 1', 'comocomprar'),
        'id'            => 'footer-1',
        'before_widget' => '<div id="%1$s" class="cc-footer__widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<p class="cc-footer__col-title" role="heading" aria-level="2">',
        'after_title'   => '</p>',
    ]);
}
add_action('widgets_init', 'cc_widgets_init');

// ─── READING TIME ──────────────────────────────────────────
function cc_reading_time($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    $content = get_post_field('post_content', $post_id);
    $word_count = str_word_count(strip_tags($content));
    $reading_time = max(1, ceil($word_count / 200));
    return $reading_time;
}

// ─── EXCERPT LENGTH ────────────────────────────────────────
function cc_excerpt_length($length) {
    return 25;
}
add_filter('excerpt_length', 'cc_excerpt_length');

function cc_excerpt_more($more) {
    return '…';
}
add_filter('excerpt_more', 'cc_excerpt_more');

// ─── BREADCRUMBS ───────────────────────────────────────────
// Visual breadcrumbs only (no microdata).
// Schema BreadcrumbList is handled by RankMath/Yoast via JSON-LD.
function cc_breadcrumbs() {
    if (is_front_page()) return;

    echo '<nav aria-label="' . esc_attr__('Breadcrumb', 'comocomprar') . '">';
    echo '<ol class="cc-article__breadcrumb">';

    // Home
    echo '<li><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Início', 'comocomprar') . '</a></li>';

    if (is_category() || is_single()) {
        $categories = get_the_category();
        if ($categories) {
            $cat = $categories[0];
            echo '<li><a href="' . esc_url(get_category_link($cat)) . '">' . esc_html($cat->name) . '</a></li>';
        }
    }

    if (is_single()) {
        echo '<li><span>' . esc_html(wp_trim_words(get_the_title(), 8)) . '</span></li>';
    } elseif (is_category()) {
        echo '<li><span>' . esc_html(single_cat_title('', false)) . '</span></li>';
    } elseif (is_search()) {
        echo '<li>' . esc_html__('Busca', 'comocomprar') . '</li>';
    } elseif (is_404()) {
        echo '<li>' . esc_html__('Página não encontrada', 'comocomprar') . '</li>';
    }

    echo '</ol></nav>';
}

// ─── SCHEMA MARKUP ─────────────────────────────────────────
// Schema is fully handled by RankMath or Yoast SEO plugin.
// DO NOT add manual schema here — it causes duplicates in
// Google Search Console (Article vs NewsArticle conflict).
// The theme only outputs schema as fallback if no SEO plugin is active.
// If you need schema without a plugin, uncomment the functions below.

/*
function cc_schema_article() {
    if (class_exists('RankMath') || defined('WPSEO_VERSION')) return;
    if (!is_single()) return;
    // ... schema code removed to prevent duplication
}
add_action('wp_head', 'cc_schema_article');

function cc_schema_website() {
    if (class_exists('RankMath') || defined('WPSEO_VERSION')) return;
    // ... schema code removed to prevent duplication
}
add_action('wp_head', 'cc_schema_website');
*/

// ─── OPEN GRAPH META ───────────────────────────────────────
function cc_open_graph() {
    // Skip if Yoast/RankMath is active
    if (defined('WPSEO_VERSION') || class_exists('RankMath')) return;

    echo '<meta property="og:locale" content="pt_BR">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";

    if (is_single() || is_page()) {
        $image = get_the_post_thumbnail_url(get_the_ID(), 'cc-hero');
        echo '<meta property="og:type" content="article">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr(get_the_title()) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr(wp_trim_words(get_the_excerpt(), 30)) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url(get_permalink()) . '">' . "\n";
        if ($image) {
            echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
            echo '<meta property="og:image:width" content="1200">' . "\n";
            echo '<meta property="og:image:height" content="630">' . "\n";
        }
        echo '<meta property="article:published_time" content="' . esc_attr(get_the_date('c')) . '">' . "\n";
        echo '<meta property="article:modified_time" content="' . esc_attr(get_the_modified_date('c')) . '">' . "\n";

        // Twitter card
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr(get_the_title()) . '">' . "\n";
    } else {
        echo '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr(get_bloginfo('description')) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url(home_url('/')) . '">' . "\n";
    }
}
add_action('wp_head', 'cc_open_graph', 5);

// ─── HELPER: LOGO URL ─────────────────────────────────────
function cc_get_logo_url() {
    $custom_logo_id = get_theme_mod('custom_logo');
    if ($custom_logo_id) {
        return wp_get_attachment_image_url($custom_logo_id, 'full');
    }
    return CC_URI . '/img/logo.svg';
}

// ─── RELATED POSTS ─────────────────────────────────────────
function cc_get_related_posts($post_id = null, $count = 3) {
    $post_id = $post_id ?: get_the_ID();
    $categories = wp_get_post_categories($post_id);
    $tags = wp_get_post_tags($post_id, ['fields' => 'ids']);

    $args = [
        'post__not_in'        => [$post_id],
        'posts_per_page'      => $count,
        'ignore_sticky_posts' => 1,
        'orderby'             => 'date',
        'order'               => 'DESC',
        'no_found_rows'       => true,
    ];

    if ($tags) {
        $args['tag__in'] = $tags;
    } elseif ($categories) {
        $args['category__in'] = $categories;
    }

    return new WP_Query($args);
}

// ─── POPULAR POSTS (COMMENT COUNT FALLBACK) ────────────────
function cc_get_popular_posts($count = 5) {
    return new WP_Query([
        'posts_per_page'      => $count,
        'orderby'             => 'comment_count',
        'order'               => 'DESC',
        'ignore_sticky_posts' => 1,
        'no_found_rows'       => true,
    ]);
}

// ─── UPDATED DATE BADGE ────────────────────────────────────
function cc_updated_badge() {
    $published = get_the_date('U');
    $modified  = get_the_modified_date('U');

    if ($modified - $published > 86400) {
        echo '<span class="cc-updated-badge">';
        echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H4.598a.75.75 0 00-.75.75v3.634a.75.75 0 001.5 0v-2.033l.312.311a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm-1.624-7.848a7 7 0 00-11.712 3.138.75.75 0 001.449.39 5.5 5.5 0 019.201-2.466l.312.311H10.5a.75.75 0 000 1.5h3.634a.75.75 0 00.75-.75V2.065a.75.75 0 00-1.5 0v2.033l-.312-.311z" clip-rule="evenodd"/></svg>';
        printf(
            esc_html__('Atualizado em %s', 'comocomprar'),
            get_the_modified_date()
        );
        echo '</span>';
    }
}

// ─── LAZY LOAD ATTRIBUTES ──────────────────────────────────
// Respects explicit loading="eager" from templates.
// Also removes lazy loading from the first image on single posts (LCP).
function cc_lazy_load_attributes($attr, $attachment, $size) {
    if (is_admin()) return $attr;

    // Never override explicit eager
    if (isset($attr['loading']) && $attr['loading'] === 'eager') {
        return $attr;
    }

    // Default: lazy for everything else
    if (!isset($attr['loading'])) {
        $attr['loading'] = 'lazy';
    }
    if (!isset($attr['decoding'])) {
        $attr['decoding'] = 'async';
    }

    return $attr;
}
add_filter('wp_get_attachment_image_attributes', 'cc_lazy_load_attributes', 10, 3);

// Force remove lazy loading from post thumbnails on single/page views
// WordPress 5.9+ adds loading="lazy" by default to all images.
// This ensures the hero/LCP image is NEVER lazy loaded.
function cc_remove_lazy_from_thumbnail($attr, $attachment, $size) {
    if ((is_single() || is_page()) && $size === 'cc-hero') {
        $attr['loading'] = 'eager';
        $attr['fetchpriority'] = 'high';
        unset($attr['loading']); // Remove loading entirely — browser defaults to eager
    }
    return $attr;
}
add_filter('wp_get_attachment_image_attributes', 'cc_remove_lazy_from_thumbnail', 99, 3);

// Disable WordPress core lazy loading on the featured image
function cc_disable_core_lazy_lcp($value, $tag_name, $context) {
    if (is_single() || is_page()) {
        // WP counts images and lazy-loads starting from #1.
        // Returning false for the first image disables lazy on it.
        static $count = 0;
        $count++;
        if ($count <= 1) {
            return false;
        }
    }
    return $value;
}
add_filter('wp_lazy_loading_enabled', 'cc_disable_core_lazy_lcp', 10, 3);

// ─── RESPONSIVE IMAGES: Add srcset/sizes ──────────────────
function cc_responsive_content_images($content) {
    if (empty($content)) return $content;

    $content = preg_replace_callback(
        '/<img([^>]+)>/i',
        function ($matches) {
            $img = $matches[0];
            if (strpos($img, 'sizes=') !== false) return $img;
            $sizes = 'sizes="(max-width: 768px) 100vw, (max-width: 1024px) 80vw, 820px"';
            return str_replace('<img', '<img ' . $sizes, $img);
        },
        $content
    );

    return $content;
}
add_filter('the_content', 'cc_responsive_content_images', 20);

// ─── PRELOAD LCP IMAGE ───────────────────────────────────
// Handled directly in header.php before wp_head() for maximum priority

// ─── DEFER/ASYNC SCRIPTS ──────────────────────────────────
// Defer ALL frontend scripts to eliminate render-blocking
function cc_defer_scripts($tag, $handle, $src) {
    if (is_admin()) return $tag;

    // Skip scripts that break when deferred
    $skip = ['wp-mediaelement'];
    if (in_array($handle, $skip)) return $tag;

    // Don't double-add defer
    if (strpos($tag, 'defer') !== false || strpos($tag, 'async') !== false) {
        return $tag;
    }

    return str_replace(' src=', ' defer src=', $tag);
}
add_filter('script_loader_tag', 'cc_defer_scripts', 10, 3);

// ─── CUSTOMIZER ────────────────────────────────────────────
function cc_customizer_register($wp_customize) {
    // Breaking news
    $wp_customize->add_section('cc_breaking_news', [
        'title'    => __('Breaking News', 'comocomprar'),
        'priority' => 25,
    ]);

    $wp_customize->add_setting('cc_breaking_enabled', ['default' => false, 'sanitize_callback' => 'wp_validate_boolean']);
    $wp_customize->add_control('cc_breaking_enabled', [
        'label'   => __('Ativar Breaking News', 'comocomprar'),
        'section' => 'cc_breaking_news',
        'type'    => 'checkbox',
    ]);

    $wp_customize->add_setting('cc_breaking_text', ['default' => '', 'sanitize_callback' => 'sanitize_text_field']);
    $wp_customize->add_control('cc_breaking_text', [
        'label'   => __('Texto da Breaking News', 'comocomprar'),
        'section' => 'cc_breaking_news',
        'type'    => 'text',
    ]);

    $wp_customize->add_setting('cc_breaking_url', ['default' => '', 'sanitize_callback' => 'esc_url_raw']);
    $wp_customize->add_control('cc_breaking_url', [
        'label'   => __('Link da Breaking News', 'comocomprar'),
        'section' => 'cc_breaking_news',
        'type'    => 'url',
    ]);

    // Social
    $wp_customize->add_section('cc_social', [
        'title'    => __('Redes Sociais', 'comocomprar'),
        'priority' => 30,
    ]);

    foreach (['instagram', 'twitter', 'facebook', 'youtube'] as $social) {
        $wp_customize->add_setting("cc_social_{$social}", ['default' => '', 'sanitize_callback' => 'esc_url_raw']);
        $wp_customize->add_control("cc_social_{$social}", [
            'label'   => ucfirst($social),
            'section' => 'cc_social',
            'type'    => 'url',
        ]);
    }
}
add_action('customize_register', 'cc_customizer_register');

// ─── LOAD TEMPLATE PARTS ──────────────────────────────────
require_once CC_DIR . '/inc/template-tags.php';

// ─── DISABLE GLOBAL STYLES (PERFORMANCE) ──────────────────
function cc_remove_global_styles() {
    wp_dequeue_style('global-styles');
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('classic-theme-styles');
    // dashicons só é usado pela admin-bar quando logado; user não-logado economiza ~36KB gz
    if (!is_user_logged_in()) {
        wp_dequeue_style('dashicons');
        wp_deregister_style('dashicons');
    }
}
add_action('wp_enqueue_scripts', 'cc_remove_global_styles', 100);

// ─── AJAX LOAD MORE ───────────────────────────────────────
function cc_load_more_posts() {
    check_ajax_referer('cc_loadmore', 'nonce');

    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 6,
        'paged'          => intval($_POST['page'] ?? 2),
    ];

    if (!empty($_POST['category'])) {
        $args['cat'] = intval($_POST['category']);
    }

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            get_template_part('template-parts/card', 'vertical');
        }
    }

    wp_reset_postdata();
    wp_die();
}
add_action('wp_ajax_cc_load_more', 'cc_load_more_posts');
add_action('wp_ajax_nopriv_cc_load_more', 'cc_load_more_posts');

function cc_localize_scripts() {
    wp_localize_script('cc-main', 'ccAjax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('cc_loadmore'),
    ]);
}
add_action('wp_enqueue_scripts', 'cc_localize_scripts');
