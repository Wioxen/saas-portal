<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    // LCP image preload — must be the FIRST resource hint in <head>
    $lcp_thumb_id = null;
    $lcp_sizes_attr = '(max-width: 768px) 100vw, (max-width: 1024px) 80vw, 820px';
    if (is_single() || is_page()) {
        $lcp_thumb_id = get_post_thumbnail_id();
    } elseif (is_home() && !is_paged()) {
        // Home bento: preload thumb do 1º post (hero card já tem fetchpriority=high no index.php)
        $first = get_posts(['numberposts' => 1, 'post_status' => 'publish', 'no_found_rows' => true, 'fields' => 'ids']);
        if (!empty($first)) {
            $lcp_thumb_id = get_post_thumbnail_id($first[0]);
            $lcp_sizes_attr = '(max-width: 768px) 100vw, 62vw';
        }
    } elseif (is_archive() || is_category() || is_search()) {
        global $wp_query;
        if (!empty($wp_query->posts)) {
            $lcp_thumb_id = get_post_thumbnail_id($wp_query->posts[0]->ID);
        }
    }
    if ($lcp_thumb_id) {
        $lcp_url = wp_get_attachment_image_url($lcp_thumb_id, 'cc-hero');
        $lcp_srcset = wp_get_attachment_image_srcset($lcp_thumb_id, 'cc-hero');
        if ($lcp_url) {
            echo '<link rel="preload" as="image" href="' . esc_url($lcp_url) . '"';
            if ($lcp_srcset) {
                echo ' imagesrcset="' . esc_attr($lcp_srcset) . '"';
                echo ' imagesizes="' . esc_attr($lcp_sizes_attr) . '"';
            }
            echo ' fetchpriority="high">' . "\n";
        }
    }
    ?>
    <meta name="theme-color" content="#1E3A8A">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="cc-skip-link" href="#main-content"><?php esc_html_e('Pular para o conteúdo', 'comocomprar'); ?></a>

<?php
// Breaking News bar
$breaking_enabled = get_theme_mod('cc_breaking_enabled', false);
$breaking_text    = get_theme_mod('cc_breaking_text', '');
$breaking_url     = get_theme_mod('cc_breaking_url', '');

if ($breaking_enabled && $breaking_text) : ?>
    <div class="cc-breaking" role="alert">
        <div class="cc-breaking__inner">
            <span class="cc-breaking__label"><?php esc_html_e('Urgente', 'comocomprar'); ?></span>
            <span class="cc-breaking__text">
                <?php if ($breaking_url) : ?>
                    <a href="<?php echo esc_url($breaking_url); ?>"><?php echo esc_html($breaking_text); ?></a>
                <?php else : ?>
                    <?php echo esc_html($breaking_text); ?>
                <?php endif; ?>
            </span>
        </div>
    </div>
<?php endif; ?>

<header class="cc-header" role="banner">
    <?php if (is_single()) : ?>
        <div class="cc-progress" id="cc-progress" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="cc-header__inner">
        <!-- Logo -->
        <a href="<?php echo esc_url(home_url('/')); ?>" class="cc-logo" rel="home" aria-label="<?php bloginfo('name'); ?>">
            <?php if (has_custom_logo()) : ?>
                <?php
                $logo_id = get_theme_mod('custom_logo');
                $logo_url = wp_get_attachment_image_url($logo_id, 'full');
                ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php bloginfo('name'); ?>" width="140" height="32">
            <?php else : ?>
                como<span>comprar</span>
            <?php endif; ?>
        </a>

        <!-- Desktop Navigation (dynamic categories) -->
        <nav class="cc-nav-wrap" aria-label="<?php esc_attr_e('Navegação principal', 'comocomprar'); ?>">
            <ul class="cc-nav" id="cc-nav-desktop" role="menubar">
                <?php echo cc_dynamic_category_nav(); ?>
            </ul>
        </nav>

        <!-- Actions -->
        <div class="cc-header__actions">
            <!-- Search -->
            <button class="cc-search-toggle" id="cc-search-open" aria-label="<?php esc_attr_e('Buscar', 'comocomprar'); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd"/></svg>
            </button>

            <!-- Mobile menu -->
            <button class="cc-menu-toggle" id="cc-menu-toggle" aria-label="<?php esc_attr_e('Menu', 'comocomprar'); ?>" aria-expanded="false">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 4.75A.75.75 0 012.75 4h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 4.75zm0 10.5a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75a.75.75 0 01-.75-.75zM2 10a.75.75 0 01.75-.75h7.5a.75.75 0 010 1.5h-7.5A.75.75 0 012 10z" clip-rule="evenodd"/></svg>
            </button>
        </div>
    </div>
</header>

<!-- Mobile nav -->
<div class="cc-mobile-nav" id="cc-mobile-nav" aria-hidden="true">
    <ul class="cc-mobile-nav__list">
        <?php echo cc_dynamic_category_nav('mobile'); ?>
    </ul>
</div>

<!-- Search overlay -->
<div class="cc-search-overlay" id="cc-search-overlay" role="dialog" aria-label="<?php esc_attr_e('Busca', 'comocomprar'); ?>">
    <div class="cc-search-overlay__inner">
        <form role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
            <label for="cc-search-input" class="screen-reader-text"><?php esc_html_e('Buscar', 'comocomprar'); ?></label>
            <input type="search" id="cc-search-input" name="s" placeholder="<?php esc_attr_e('O que você procura?', 'comocomprar'); ?>" autocomplete="off" autofocus>
        </form>
    </div>
</div>

<main id="main-content" role="main">
