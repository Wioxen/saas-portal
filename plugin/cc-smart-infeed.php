<?php
/**
 * Plugin Name: CC Smart In-Feed
 * Description: Oculta blocos de afiliado/CTA até o leitor scrollar 50% do post. UX melhor + sinal pro AdSense de que o site respeita o leitor (não martela com afiliado antes do conteúdo).
 * Version: 1.0
 * Author: Como Comprar
 *
 * Detecta classes: .discover-afiliado-cta (gerador), .smart-infeed (manual), .cc-cta-50scroll (legacy).
 * Aciona apenas em single posts (post type post). JS no footer. Animação fade-in.
 *
 * Instalar: copie para wp-content/plugins/ e ative no WP admin.
 *
 * Customização (filtros do WP):
 *   - cc_smart_infeed_threshold: int — % de scroll pra revelar (default 50)
 *   - cc_smart_infeed_classes: array — classes CSS alvo (default ['discover-afiliado-cta', 'smart-infeed', 'cc-cta-50scroll'])
 *   - cc_smart_infeed_post_types: array — post types onde ativa (default ['post'])
 */

if (!defined('ABSPATH')) exit;

add_action('wp_footer', 'cc_smart_infeed_inject', 99);

function cc_smart_infeed_inject() {
    // Aplica só em single de post types configurados (default: 'post')
    $allowed = apply_filters('cc_smart_infeed_post_types', ['post']);
    if (!is_singular($allowed)) return;

    $threshold = (int) apply_filters('cc_smart_infeed_threshold', 50);
    $threshold = max(10, min(90, $threshold)); // clamp

    $classes = apply_filters('cc_smart_infeed_classes', [
        'discover-afiliado-cta',
        'smart-infeed',
        'cc-cta-50scroll',
    ]);
    $selectorList = implode(', ', array_map(fn($c) => '.' . preg_replace('/[^a-z0-9_-]/i', '', $c), $classes));

    ?>
<style id="cc-smart-infeed-css">
<?php echo $selectorList; ?>{
    opacity:0 !important;
    max-height:0 !important;
    overflow:hidden !important;
    margin-top:0 !important;
    margin-bottom:0 !important;
    padding-top:0 !important;
    padding-bottom:0 !important;
    border-width:0 !important;
    transition:opacity .5s ease, max-height .6s ease, margin .4s ease, padding .4s ease, border-width .3s ease;
    pointer-events:none;
}
.cc-smart-infeed-revealed{
    opacity:1 !important;
    max-height:2000px !important;
    margin-top:24px !important;
    margin-bottom:24px !important;
    padding-top:18px !important;
    padding-bottom:18px !important;
    border-width:2px !important;
    pointer-events:auto;
}
</style>
<script id="cc-smart-infeed-js">
(function(){
    'use strict';
    var threshold = <?php echo $threshold; ?> / 100;
    var revealed = false;
    var blocks;

    function pickBlocks(){
        return document.querySelectorAll('<?php echo $selectorList; ?>');
    }

    function reveal(){
        if (revealed) return;
        revealed = true;
        if (!blocks || !blocks.length) return;
        for (var i = 0; i < blocks.length; i++) {
            blocks[i].classList.add('cc-smart-infeed-revealed');
        }
        // Após revelar, libera memória do listener
        window.removeEventListener('scroll', onScroll, { passive: true });
    }

    function onScroll(){
        if (revealed) return;
        var doc = document.documentElement;
        var scrolled = (window.scrollY || doc.scrollTop);
        var max = (doc.scrollHeight - doc.clientHeight);
        if (max <= 0) { reveal(); return; }
        var pct = scrolled / max;
        if (pct >= threshold) reveal();
    }

    function init(){
        blocks = pickBlocks();
        if (!blocks.length) return; // sem blocos pra ocultar
        // Edge case: post curto onde scrollHeight não atinge threshold — revela após 8s
        setTimeout(function(){ if (!revealed) reveal(); }, 8000);
        window.addEventListener('scroll', onScroll, { passive: true });
        // Trigger inicial caso já esteja com scroll alto (ex: voltar de outra aba)
        onScroll();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
    <?php
}
