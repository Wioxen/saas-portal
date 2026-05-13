<?php
/**
 * Comments Template
 * 
 * @package LeaoDaBarra
 */

if (post_password_required()) return;
?>

<div id="comments" class="ldb-comments">
    <?php if (have_comments()) : ?>
        <h3 class="ldb-section-title">
            <?php
            printf(
                _n('%d Comentário', '%d Comentários', get_comments_number(), 'leao-da-barra'),
                get_comments_number()
            );
            ?>
        </h3>

        <ol class="ldb-comment-list" style="list-style:none;padding:0;">
            <?php
            wp_list_comments([
                'style'       => 'ol',
                'short_ping'  => true,
                'avatar_size' => 40,
            ]);
            ?>
        </ol>

        <?php the_comments_navigation(); ?>
    <?php endif; ?>

    <?php
    comment_form([
        'title_reply'         => __('Deixe seu comentário', 'leao-da-barra'),
        'label_submit'        => __('Enviar', 'leao-da-barra'),
        'comment_notes_after' => '',
        'class_form'          => 'ldb-comment-form',
        'class_submit'        => 'ldb-btn ldb-btn-primary',
    ]);
    ?>
</div>
