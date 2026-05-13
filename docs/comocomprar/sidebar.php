<?php
/**
 * Sidebar Template
 *
 * @package ComoComprar
 */

if (!is_active_sidebar('sidebar-main')) return;
?>

<aside class="cc-sidebar" role="complementary" aria-label="<?php esc_attr_e('Sidebar', 'comocomprar'); ?>">
    <?php dynamic_sidebar('sidebar-main'); ?>
</aside>
