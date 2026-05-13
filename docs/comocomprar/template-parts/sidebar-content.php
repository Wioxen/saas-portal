<?php
/**
 * Sidebar Content
 *
 * @package ComoComprar
 */
?>

<!-- Popular Posts -->
<div class="cc-widget">
    <h3 class="cc-widget__title"><?php esc_html_e('Mais Lidos', 'comocomprar'); ?></h3>
    <ol class="cc-popular-list">
        <?php
        $popular = cc_get_popular_posts(5);
        $num = 1;
        if ($popular->have_posts()) :
            while ($popular->have_posts()) : $popular->the_post(); ?>
                <li>
                    <span class="cc-popular-list__num"><?php echo $num++; ?></span>
                    <div class="cc-popular-list__title">
                        <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                    </div>
                </li>
            <?php endwhile;
            wp_reset_postdata();
        endif;
        ?>
    </ol>
</div>

<!-- Newsletter -->
<div class="cc-widget cc-newsletter">
    <h3 class="cc-widget__title"><?php esc_html_e('Newsletter', 'comocomprar'); ?></h3>
    <p><?php esc_html_e('Receba as melhores dicas de compra direto no seu e-mail.', 'comocomprar'); ?></p>
    <input type="email" placeholder="<?php esc_attr_e('Seu melhor e-mail', 'comocomprar'); ?>">
    <button class="cc-btn cc-btn--primary cc-btn--full cc-btn--sm"><?php esc_html_e('Inscrever-se', 'comocomprar'); ?></button>
</div>

<!-- Categories -->
<div class="cc-widget">
    <h3 class="cc-widget__title"><?php esc_html_e('Categorias', 'comocomprar'); ?></h3>
    <ul style="list-style:none;padding:0;">
        <?php
        $cats = get_categories(['orderby' => 'count', 'order' => 'DESC', 'number' => 8]);
        foreach ($cats as $cat) :
        ?>
            <li style="display:flex;justify-content:space-between;padding:.375rem 0;border-bottom:1px solid var(--cc-gray-100);font-size:.875rem;">
                <a href="<?php echo esc_url(get_category_link($cat)); ?>" style="color:var(--cc-gray-700);"><?php echo esc_html($cat->name); ?></a>
                <span style="color:var(--cc-gray-400);font-size:.75rem;"><?php echo esc_html($cat->count); ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<!-- Dynamic sidebar widgets -->
<?php if (is_active_sidebar('sidebar-main')) : ?>
    <?php dynamic_sidebar('sidebar-main'); ?>
<?php endif; ?>
