<?php
/**
 * Search Form
 *
 * @package ComoComprar
 */
?>
<form role="search" method="get" class="search-form" action="<?php echo esc_url(home_url('/')); ?>">
    <label for="search-field-<?php echo esc_attr(wp_unique_id()); ?>" class="screen-reader-text"><?php esc_html_e('Buscar', 'comocomprar'); ?></label>
    <input type="search" id="search-field-<?php echo esc_attr(wp_unique_id()); ?>" class="search-field" name="s" placeholder="<?php esc_attr_e('Buscar...', 'comocomprar'); ?>" value="<?php echo get_search_query(); ?>" style="width:100%;padding:.625rem .875rem;border:2px solid var(--cc-gray-200);border-radius:var(--cc-radius-sm);font-size:.9375rem;font-family:var(--cc-font-body);">
</form>
