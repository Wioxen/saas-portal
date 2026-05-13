<?php
/**
 * Sidebar Template
 * 
 * @package LeaoDaBarra
 */

if (!is_active_sidebar('sidebar-main') && !is_active_sidebar('sidebar-tabela')) {
    return;
}
?>

<div class="ldb-sidebar-widgets">
    <?php dynamic_sidebar('sidebar-main'); ?>
    <?php dynamic_sidebar('sidebar-tabela'); ?>
</div>
