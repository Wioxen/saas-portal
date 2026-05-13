<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#FFFFFF">
    <link rel="manifest" href="<?php echo LDB_URI; ?>/manifest.json">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<!-- HEADER -->
<header class="ldb-header" role="banner">
    <div class="ldb-header-inner ldb-container">
        <!-- Logo -->
        <a href="<?php echo esc_url(home_url('/')); ?>" class="ldb-logo" rel="home">
            <?php if (has_custom_logo()) : ?>
                <?php the_custom_logo(); ?>
            <?php else : ?>
                <span class="ldb-logo-icon">LB</span>
                <span class="ldb-logo-text">Leão<span>da</span>Barra</span>
            <?php endif; ?>
        </a>

        <!-- Nav Desktop -->
        <nav class="ldb-nav-desktop" role="navigation" aria-label="<?php esc_attr_e('Menu principal', 'leao-da-barra'); ?>">
            <?php
            wp_nav_menu([
                'theme_location' => 'primary',
                'container'      => false,
                'menu_class'     => 'ldb-nav-list',
                'depth'          => 2,
                'fallback_cb'    => 'ldb_default_menu',
            ]);
            ?>
        </nav>

        <!-- Mobile Toggle -->
        <button class="ldb-mobile-toggle" aria-label="<?php esc_attr_e('Abrir menu', 'leao-da-barra'); ?>" aria-expanded="false">
            <span class="ldb-hamburger">
                <span></span>
                <span></span>
                <span></span>
            </span>
        </button>
    </div>
</header>

<!-- Mobile Nav Overlay -->
<div class="ldb-mobile-nav" id="ldb-mobile-nav" inert>
    <div class="ldb-mobile-nav-inner">
        <div class="ldb-mobile-nav-header">
            <span class="ldb-logo-text">Leão<span>da</span>Barra</span>
            <button class="ldb-mobile-close" aria-label="<?php esc_attr_e('Fechar menu', 'leao-da-barra'); ?>">✕</button>
        </div>
        <?php
        wp_nav_menu([
            'theme_location' => 'mobile',
            'container'      => false,
            'menu_class'     => 'ldb-mobile-list',
            'depth'          => 2,
            'fallback_cb'    => 'ldb_default_menu_mobile',
        ]);
        ?>
    </div>
</div>

<main id="main" class="ldb-main" role="main">

<?php
/**
 * Fallback menu desktop
 */
function ldb_default_menu() {
    echo '<ul class="ldb-nav-list">';
    echo '<li><a href="' . esc_url(home_url('/')) . '">Início</a></li>';
    echo '<li><a href="' . esc_url(home_url('/category/vitoria/')) . '">Vitória</a></li>';
    echo '<li><a href="' . esc_url(home_url('/category/nacional/')) . '">Nacional</a></li>';
    echo '<li><a href="' . esc_url(home_url('/category/internacional/')) . '">Internacional</a></li>';
    echo '<li><a href="' . esc_url(home_url('/bastidores/')) . '">Bastidores</a></li>';
    echo '<li><a href="' . esc_url(home_url('/historia/')) . '">História</a></li>';
    echo '</ul>';
}

/**
 * Fallback menu mobile
 */
function ldb_default_menu_mobile() {
    echo '<ul class="ldb-mobile-list">';
    echo '<li><a href="' . esc_url(home_url('/')) . '">Início</a></li>';
    echo '<li><a href="' . esc_url(home_url('/category/vitoria/')) . '">Vitória</a></li>';
    echo '<li><a href="' . esc_url(home_url('/category/nacional/')) . '">Nacional</a></li>';
    echo '<li><a href="' . esc_url(home_url('/category/internacional/')) . '">Internacional</a></li>';
    echo '<li><a href="' . esc_url(home_url('/bastidores/')) . '">Bastidores</a></li>';
    echo '<li><a href="' . esc_url(home_url('/historia/')) . '">História</a></li>';
    echo '</ul>';
}
?>
