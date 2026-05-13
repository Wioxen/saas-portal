<?php
/**
 * Template Part: Home Hero
 * 
 * @package LeaoDaBarra
 */

$hero_style = get_theme_mod('ldb_hero_style', 'latest');

if ($hero_style === 'custom') {
    $hero_title = get_theme_mod('ldb_hero_title', 'Bem-vindo ao Leão da Barra');
    $hero_desc  = get_theme_mod('ldb_hero_desc', 'Acompanhe todas as notícias do Esporte Clube Vitória');
} else {
    $hero_query = new WP_Query([
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'meta_key'       => $hero_style === 'featured' ? '_is_featured' : '',
        'meta_value'     => $hero_style === 'featured' ? '1' : '',
    ]);
    
    if ($hero_query->have_posts()) {
        $hero_query->the_post();
        $hero_title = get_the_title();
        $hero_desc  = wp_trim_words(get_the_excerpt(), 20);
        $hero_link  = get_permalink();
        $hero_img   = get_the_post_thumbnail_url(get_the_ID(), 'ldb-hero');
        wp_reset_postdata();
    }
}
?>

<section class="ldb-hero" <?php if (!empty($hero_img)) echo 'style="background-image: linear-gradient(135deg, rgba(26,26,26,0.95) 0%, rgba(45,16,18,0.9) 50%, rgba(155,22,32,0.85) 100%), url(' . esc_url($hero_img) . ');"'; ?>>
    <div class="ldb-hero-inner">
        <div class="ldb-hero-badge">
            <span class="ldb-pulse"></span>
            Última Hora
        </div>
        <h1 class="ldb-hero-title">
            <?php if (!empty($hero_link)) : ?>
                <a href="<?php echo esc_url($hero_link); ?>"><?php echo esc_html($hero_title); ?></a>
            <?php else : ?>
                <?php echo esc_html($hero_title); ?>
            <?php endif; ?>
        </h1>
        <p class="ldb-hero-desc"><?php echo esc_html($hero_desc ?? ''); ?></p>
    </div>
</section>
