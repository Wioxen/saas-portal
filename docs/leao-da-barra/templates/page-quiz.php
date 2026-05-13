<?php
/**
 * Template Name: Quiz do Vitória
 * 
 * Quiz interativo com login Google, níveis, timer, ranking
 * 
 * @package LeaoDaBarra
 */

get_header();
$google_client_id = get_option('ldb_google_client_id', '');
?>

<div class="g1-container">
    <div id="ldb-quiz-app" style="max-width:560px;margin:0 auto;padding:20px 0 40px;"></div>
</div>

<script src="https://accounts.google.com/gsi/client" async defer></script>
<script>
window.ldbQuizConfig = {
    restUrl: '<?php echo esc_url(rest_url('ldb/v1/quiz/')); ?>',
    googleClientId: '<?php echo esc_js($google_client_id); ?>',
    siteName: '<?php echo esc_js(get_bloginfo('name')); ?>',
    siteUrl: '<?php echo esc_url(home_url('/')); ?>',
};
</script>

<?php get_footer(); ?>
