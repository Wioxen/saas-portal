<?php
/**
 * WordPress Customizer Settings
 * 
 * @package LeaoDaBarra
 */

defined('ABSPATH') || exit;

function ldb_customize_register($wp_customize) {
    // ============================================================
    // SEÇÃO: Redes Sociais
    // ============================================================
    $wp_customize->add_section('ldb_social', [
        'title'    => __('Redes Sociais', 'leao-da-barra'),
        'priority' => 30,
    ]);

    $social_networks = [
        'facebook'  => 'Facebook',
        'twitter'   => 'Twitter / X',
        'instagram' => 'Instagram',
        'youtube'   => 'YouTube',
        'tiktok'    => 'TikTok',
        'telegram'  => 'Telegram',
    ];

    foreach ($social_networks as $key => $label) {
        $wp_customize->add_setting("ldb_{$key}_url", [
            'default'           => '',
            'sanitize_callback' => 'esc_url_raw',
        ]);
        $wp_customize->add_control("ldb_{$key}_url", [
            'label'   => $label,
            'section' => 'ldb_social',
            'type'    => 'url',
        ]);
    }

    // ============================================================
    // SEÇÃO: Hero / Destaque
    // ============================================================
    $wp_customize->add_section('ldb_hero', [
        'title'    => __('Hero / Destaque', 'leao-da-barra'),
        'priority' => 35,
    ]);

    $wp_customize->add_setting('ldb_hero_style', [
        'default'           => 'latest',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    $wp_customize->add_control('ldb_hero_style', [
        'label'   => __('Estilo do Hero', 'leao-da-barra'),
        'section' => 'ldb_hero',
        'type'    => 'select',
        'choices' => [
            'latest'   => __('Último post', 'leao-da-barra'),
            'featured' => __('Post em destaque', 'leao-da-barra'),
            'custom'   => __('Conteúdo customizado', 'leao-da-barra'),
        ],
    ]);

    $wp_customize->add_setting('ldb_hero_title', [
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    $wp_customize->add_control('ldb_hero_title', [
        'label'       => __('Título customizado', 'leao-da-barra'),
        'section'     => 'ldb_hero',
        'type'        => 'text',
        'description' => __('Usado quando estilo = customizado', 'leao-da-barra'),
    ]);

    // ============================================================
    // SEÇÃO: Campeonatos na Home
    // ============================================================
    $wp_customize->add_section('ldb_campeonatos', [
        'title'    => __('Campeonatos', 'leao-da-barra'),
        'priority' => 40,
    ]);

    $wp_customize->add_setting('ldb_main_campeonato_id', [
        'default'           => 10,
        'sanitize_callback' => 'absint',
    ]);
    $wp_customize->add_control('ldb_main_campeonato_id', [
        'label'       => __('ID Campeonato Principal', 'leao-da-barra'),
        'section'     => 'ldb_campeonatos',
        'type'        => 'number',
        'description' => __('ID do Brasileirão ou campeonato principal', 'leao-da-barra'),
    ]);

    // ============================================================
    // SEÇÃO: Cores
    // ============================================================
    $wp_customize->add_setting('ldb_accent_color', [
        'default'           => '#C41E2A',
        'sanitize_callback' => 'sanitize_hex_color',
    ]);
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'ldb_accent_color', [
        'label'   => __('Cor de Destaque', 'leao-da-barra'),
        'section' => 'colors',
    ]));

    // ============================================================
    // SEÇÃO: Analytics & Ads
    // ============================================================
    $wp_customize->add_section('ldb_analytics', [
        'title'    => __('Analytics & Anúncios', 'leao-da-barra'),
        'priority' => 160,
    ]);

    $wp_customize->add_setting('ldb_ga_id', [
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    $wp_customize->add_control('ldb_ga_id', [
        'label'       => __('Google Analytics ID', 'leao-da-barra'),
        'section'     => 'ldb_analytics',
        'type'        => 'text',
        'description' => 'Ex: G-XXXXXXXXXX',
    ]);

    $wp_customize->add_setting('ldb_adsense_id', [
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    $wp_customize->add_control('ldb_adsense_id', [
        'label'       => __('Google AdSense ID', 'leao-da-barra'),
        'section'     => 'ldb_analytics',
        'type'        => 'text',
        'description' => 'Ex: ca-pub-XXXXXXXXXXXXXXXX',
    ]);
}
add_action('customize_register', 'ldb_customize_register');

// ============================================================
// GA4 SCRIPT
// ============================================================
function ldb_google_analytics() {
    $ga_id = get_theme_mod('ldb_ga_id', '');
    if (empty($ga_id) || is_admin() || is_customize_preview()) return;
    ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($ga_id); ?>"></script>
    <script>
    window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
    gtag('js',new Date());gtag('config','<?php echo esc_js($ga_id); ?>');
    </script>
    <?php
}
add_action('wp_head', 'ldb_google_analytics', 999);
