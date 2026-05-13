<?php
/**
 * Pagination
 *
 * @package ComoComprar
 */

$pagination = paginate_links([
    'type'      => 'array',
    'prev_text' => '&laquo;',
    'next_text' => '&raquo;',
]);

if ($pagination) : ?>
    <nav class="cc-pagination" aria-label="<?php esc_attr_e('Paginação', 'comocomprar'); ?>">
        <?php foreach ($pagination as $link) : ?>
            <?php echo $link; ?>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
