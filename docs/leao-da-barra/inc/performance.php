<?php
/**
 * Performance Optimization
 * 
 * Core Web Vitals: LCP, FID, CLS
 * PageSpeed Insights optimization
 * 
 * @package LeaoDaBarra
 */

defined('ABSPATH') || exit;

// ============================================================
// CSS CRÍTICO INLINE (Evita render-blocking)
// ============================================================
function ldb_critical_css() {
    ?>
    <style id="ldb-critical-css">
    @font-face{font-family:'Oswald';font-style:normal;font-weight:400 700;font-display:optional;src:local('Oswald')}
    @font-face{font-family:'Source Sans 3';font-style:normal;font-weight:400 600;font-display:optional;src:local('Source Sans 3')}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--ldb-red:#C41E2A;--ldb-black:#1A1A1A;--ldb-bg:#FFFFFF;--ldb-border:#E5E5E5;--ldb-text:#1A1A1A;--ldb-font-display:'Oswald',system-ui,sans-serif;--ldb-font-body:'Source Sans 3',system-ui,sans-serif;--ldb-max-width:1080px}
    body{font-family:var(--ldb-font-body);background:#fff;color:#333;-webkit-font-smoothing:antialiased;line-height:1.6}
    img,video{max-width:100%;height:auto;display:block}
    a{color:inherit;text-decoration:none}
    .ldb-header{background:#fff;border-bottom:3px solid var(--ldb-red);position:sticky;top:0;z-index:1000}
    .ldb-header-inner{max-width:var(--ldb-max-width);margin:0 auto;display:flex;align-items:center;justify-content:space-between;height:52px;padding:0 16px}
    .ldb-logo{display:flex;align-items:center;gap:8px}
    .ldb-logo-icon{width:32px;height:32px;background:var(--ldb-red);border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:var(--ldb-font-display);font-weight:700;color:#fff;font-size:12px}
    .ldb-logo-text{font-family:var(--ldb-font-display);font-weight:700;color:var(--ldb-text);font-size:20px;text-transform:uppercase}
    .ldb-logo-text span{color:var(--ldb-red)}
    .ldb-nav-list{display:flex;gap:2px;list-style:none}
    .ldb-nav-list a{font-family:var(--ldb-font-display);font-size:13px;font-weight:500;text-transform:uppercase;color:#555;padding:6px 10px;border-radius:3px;display:block}
    .ldb-container,.g1-container{max-width:var(--ldb-max-width);margin:0 auto;padding:0 16px}
    .g1-carousel{position:relative;overflow:hidden;background:var(--ldb-black);max-width:var(--ldb-max-width);margin:0 auto;aspect-ratio:16/9}
    .g1-carousel-track{display:flex;will-change:transform;height:100%}
    .g1-carousel-slide{min-width:100%;flex-shrink:0;position:relative;display:block;color:#fff;overflow:hidden}
    .g1-carousel-img{position:absolute;inset:0}
    .g1-carousel-img img{width:100%;height:100%;object-fit:cover}
    .g1-carousel-gradient{position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.85) 0%,rgba(0,0,0,.4) 40%,transparent 70%)}
    .g1-carousel-caption{position:absolute;bottom:0;left:0;right:0;padding:20px 16px;z-index:2}
    .g1-carousel-cat{display:inline-block;font-family:var(--ldb-font-display);font-size:11px;font-weight:600;text-transform:uppercase;padding:3px 10px;border-radius:2px;margin-bottom:8px;background:var(--ldb-red);color:#fff}
    .g1-carousel-title{font-family:var(--ldb-font-display);font-size:clamp(20px,4vw,32px);font-weight:700;color:#fff;line-height:1.15;text-transform:uppercase;margin-bottom:4px}
    .g1-carousel-excerpt{font-size:14px;color:rgba(255,255,255,.8);line-height:1.4;max-width:600px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .g1-carousel-time{font-size:12px;color:rgba(255,255,255,.5);margin-top:6px;display:block}
    .g1-fullcard-link{display:flex;gap:14px;padding:14px 0;color:inherit;align-items:flex-start}
    .g1-fullcard-img{width:180px;min-width:180px;aspect-ratio:16/10;border-radius:6px;overflow:hidden;background:#f0f0f0;flex-shrink:0}
    .g1-fullcard-img img{width:100%;height:100%;object-fit:cover}
    .g1-fullcard-body{flex:1;min-width:0}
    .g1-fullcard{border-bottom:1px solid #E5E5E5}
    .ldb-mobile-toggle{display:none}
    .ldb-hamburger{display:flex;flex-direction:column;gap:4px;width:22px}
    .ldb-hamburger span{display:block;height:2px;background:var(--ldb-text);border-radius:1px}
    @media(max-width:900px){.ldb-nav-desktop{display:none}.ldb-mobile-toggle{display:block;background:none;border:none;cursor:pointer;padding:8px}}
    </style>
    <?php
}
add_action('wp_head', 'ldb_critical_css', 0);

// ============================================================
// PRECONNECT / PRELOAD
// ============================================================
function ldb_resource_hints() {
    // Preconnect Google Fonts
    echo '<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin />' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />' . "\n";

    // DNS prefetch for API (lazy loaded, not preconnect)
    echo '<link rel="dns-prefetch" href="//api.api-futebol.com.br" />' . "\n";
    echo '<link rel="dns-prefetch" href="//cdn.api-futebol.com.br" />' . "\n";
}
add_action('wp_head', 'ldb_resource_hints', 0);

// ============================================================
// PRELOAD LCP IMAGE
// ============================================================
function ldb_preload_lcp() {
    // Single post - preload featured image
    if (is_singular() && has_post_thumbnail()) {
        $img = wp_get_attachment_image_src(get_post_thumbnail_id(), 'ldb-hero');
        if ($img) {
            echo '<link rel="preload" as="image" href="' . esc_url($img[0]) . '" />' . "\n";
        }
        return;
    }

    // Front page - preload first post thumbnail (carousel LCP)
    if (is_front_page() || is_home()) {
        $first = get_posts(['posts_per_page' => 1, 'post_status' => 'publish']);
        if ($first && has_post_thumbnail($first[0]->ID)) {
            $img = wp_get_attachment_image_src(get_post_thumbnail_id($first[0]->ID), 'ldb-card-medium');
            if ($img) {
                echo '<link rel="preload" as="image" href="' . esc_url($img[0]) . '" />' . "\n";
            }
        }
    }
}
add_action('wp_head', 'ldb_preload_lcp', 1);

// ============================================================
// LAZY LOADING & FETCHPRIORITY
// ============================================================
function ldb_image_attributes($attr, $attachment, $size) {
    // Primeira imagem visível (LCP) não deve ter lazy loading
    static $first_image = true;

    if ($first_image && in_array($size, ['ldb-hero', 'ldb-card-large', 'full'])) {
        $attr['loading']       = 'eager';
        $attr['fetchpriority'] = 'high';
        $attr['decoding']      = 'async';
        $first_image = false;
    } else {
        $attr['loading']  = 'lazy';
        $attr['decoding'] = 'async';
    }

    return $attr;
}
add_filter('wp_get_attachment_image_attributes', 'ldb_image_attributes', 10, 3);

// ============================================================
// REMOVE BLOAT (Reduzir render-blocking)
// ============================================================
function ldb_remove_bloat() {
    // Remover emojis do WordPress
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');

    // Remover WP embed
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');

    // Remover RSD e WLW
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');

    // Remover versão do WP
    remove_action('wp_head', 'wp_generator');

    // Remover shortlink
    remove_action('wp_head', 'wp_shortlink_wp_head');

    // Remover REST API link no head
    remove_action('wp_head', 'rest_output_link_wp_head');

    // Remover feed links extras
    remove_action('wp_head', 'feed_links_extra', 3);

    // Desativar Global Styles inline (block themes)
    remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
    remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');
}
add_action('after_setup_theme', 'ldb_remove_bloat');

// ============================================================
// DEFER / ASYNC SCRIPTS
// ============================================================
function ldb_script_loader_tag($tag, $handle, $src) {
    // Scripts que devem ser defer
    $defer_scripts = ['ldb-main', 'ldb-api'];

    if (in_array($handle, $defer_scripts)) {
        return str_replace(' src', ' defer src', $tag);
    }

    return $tag;
}
add_filter('script_loader_tag', 'ldb_script_loader_tag', 10, 3);

// ============================================================
// FONT DISPLAY SWAP
// ============================================================
function ldb_font_display_swap($html, $handle, $href) {
    // Async load Google Fonts only (not main.css)
    if (strpos($href, 'fonts.googleapis.com') !== false) {
        if (strpos($href, 'display=swap') === false) {
            $href .= '&display=swap';
        }
        $html = str_replace("rel='stylesheet'", "rel='preload' as='style' onload=\"this.onload=null;this.rel='stylesheet'\"", $html);
        $html .= '<noscript><link rel="stylesheet" href="' . esc_url($href) . '"></noscript>';
    }

    return $html;
}
add_filter('style_loader_tag', 'ldb_font_display_swap', 10, 3);

// ============================================================
// WEBP SUPPORT CHECK
// ============================================================
function ldb_supports_webp() {
    return isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
}

// ============================================================
// CLS PREVENTION: Aspect ratios para containers de imagem
// ============================================================
function ldb_image_container_style() {
    ?>
    <style>
    .ldb-img-container{position:relative;overflow:hidden;background:var(--ldb-border)}
    .ldb-img-container img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover}
    .ldb-ratio-16-9{aspect-ratio:16/9}
    .ldb-ratio-4-3{aspect-ratio:4/3}
    .ldb-ratio-1-1{aspect-ratio:1/1}
    .ldb-ratio-hero{aspect-ratio:2/1}
    </style>
    <?php
}
add_action('wp_head', 'ldb_image_container_style', 0);

// ============================================================
// MINIFY INLINE HTML (produção)
// ============================================================
function ldb_minify_html($html) {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return $html;

    // Remover comentários HTML (exceto condicionais IE)
    $html = preg_replace('/<!--(?!\[if).*?-->/', '', $html);
    // Remover espaços entre tags
    $html = preg_replace('/>\s+</', '><', $html);

    return $html;
}
// Descomentar para produção:
// add_action('template_redirect', function() { ob_start('ldb_minify_html'); });

// ============================================================
// DISABLE UNUSED BLOCK STYLES
// ============================================================
function ldb_remove_block_css() {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('wc-blocks-style');
    wp_dequeue_style('global-styles');
    wp_dequeue_style('classic-theme-styles');
}
add_action('wp_enqueue_scripts', 'ldb_remove_block_css', 100);

// ============================================================
// CACHE HEADERS
// ============================================================
function ldb_cache_headers() {
    if (is_admin() || is_user_logged_in()) return;

    if (is_singular()) {
        header('Cache-Control: public, max-age=3600, s-maxage=86400');
    } elseif (is_home() || is_front_page()) {
        header('Cache-Control: public, max-age=300, s-maxage=600');
    } elseif (is_archive()) {
        header('Cache-Control: public, max-age=1800, s-maxage=3600');
    }
}
add_action('template_redirect', 'ldb_cache_headers');

// ============================================================
// LIMIT POST REVISIONS
// ============================================================
// Nota: para limitar revisões, adicione no wp-config.php:
// define('WP_POST_REVISIONS', 5);
