<?php
/**
 * Search Form Template
 * 
 * @package LeaoDaBarra
 */
?>
<form role="search" method="get" class="search-form" action="<?php echo esc_url(home_url('/')); ?>">
    <label>
        <span class="screen-reader-text"><?php _e('Buscar:', 'leao-da-barra'); ?></span>
        <input type="search" class="search-field" placeholder="<?php esc_attr_e('Buscar notícias...', 'leao-da-barra'); ?>" value="<?php echo get_search_query(); ?>" name="s" autocomplete="off" />
    </label>
    <input type="submit" class="search-submit" value="<?php esc_attr_e('Buscar', 'leao-da-barra'); ?>" />
</form>
