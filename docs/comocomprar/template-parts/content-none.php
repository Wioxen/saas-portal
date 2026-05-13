<?php
/**
 * No content found
 *
 * @package ComoComprar
 */
?>
<div style="text-align:center;padding:3rem 1rem;">
    <h2 style="font-size:1.25rem;margin-bottom:.75rem;"><?php esc_html_e('Nenhum conteúdo encontrado', 'comocomprar'); ?></h2>
    <p style="color:var(--cc-gray-500);"><?php esc_html_e('Não encontramos publicações nesta seção.', 'comocomprar'); ?></p>
    <a href="<?php echo esc_url(home_url('/')); ?>" class="cc-btn cc-btn--outline cc-btn--sm" style="margin-top:1rem;">
        <?php esc_html_e('Voltar ao Início', 'comocomprar'); ?>
    </a>
</div>
