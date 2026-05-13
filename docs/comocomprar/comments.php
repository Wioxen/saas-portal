<?php
/**
 * Comments Template
 *
 * @package ComoComprar
 */

if (post_password_required()) return;
?>

<div id="comments" class="cc-comments">
    <?php if (have_comments()) : ?>
        <h2 class="cc-section__title" style="margin-bottom:1rem;">
            <?php
            $count = get_comments_number();
            printf(
                esc_html(_n('%d Comentário', '%d Comentários', $count, 'comocomprar')),
                $count
            );
            ?>
        </h2>

        <ol class="comment-list">
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
        'class_form'    => 'cc-comment-form',
        'title_reply'   => __('Deixe um comentário', 'comocomprar'),
        'submit_button' => '<button type="submit" class="cc-btn cc-btn--primary cc-btn--sm">%4$s</button>',
    ]);
    ?>
</div>
