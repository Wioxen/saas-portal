<?php
/**
 * Template Part: No Content Found
 * 
 * @package LeaoDaBarra
 */
?>
<section class="ldb-no-content">
    <div class="ldb-no-content-inner">
        <span class="ldb-no-content-icon">⚽</span>
        <?php if (is_search()) : ?>
            <h2 class="ldb-no-content-title">Nenhum resultado encontrado</h2>
            <p class="ldb-no-content-text">
                Nenhum conteúdo corresponde à sua busca por "<strong><?php echo get_search_query(); ?></strong>". 
                Tente outros termos ou navegue pelas categorias.
            </p>
        <?php else : ?>
            <h2 class="ldb-no-content-title">Nenhum conteúdo por aqui</h2>
            <p class="ldb-no-content-text">
                Parece que ainda não temos conteúdo nesta seção. 
                Volte à <a href="<?php echo esc_url(home_url('/')); ?>">página inicial</a> ou navegue pelas categorias.
            </p>
        <?php endif; ?>
        
        <?php get_search_form(); ?>
    </div>
</section>
