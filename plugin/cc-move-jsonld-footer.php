<?php
/**
 * Plugin Name: CC Move JSON-LD to Footer
 * Description: Extrai scripts JSON-LD do conteúdo do post e move para o wp_footer (após author-box). Melhora LCP.
 * Version: 1.0
 * Author: Como Comprar
 *
 * Instalar: copiar para wp-content/plugins/ e ativar.
 *
 * O que faz:
 * 1. No filtro 'the_content', remove todas as tags <script type="application/ld+json"> do HTML
 * 2. Guarda os scripts removidos em variável global
 * 3. No hook 'wp_footer', imprime os scripts (após author-box, sidebar, tudo)
 *
 * Resultado: navegador renderiza texto + author-box ANTES de processar JSON-LD → melhor LCP
 */

if (!defined('ABSPATH')) exit;

// Variável global para guardar os scripts extraídos
global $cc_jsonld_scripts;
$cc_jsonld_scripts = [];

/**
 * Filtro no the_content: remove <script type="application/ld+json"> e guarda pra depois
 */
add_filter('the_content', function ($content) {
    if (!is_singular('post') && !is_singular('page')) return $content;

    global $cc_jsonld_scripts;

    // Encontra todos os <script type='application/ld+json'> (aspas simples ou duplas)
    $pattern = '#<script\s+type\s*=\s*["\']application/ld\+json["\']\s*>.*?</script>#is';

    if (preg_match_all($pattern, $content, $matches)) {
        foreach ($matches[0] as $script) {
            $cc_jsonld_scripts[] = $script;
        }
        // Remove do content
        $content = preg_replace($pattern, '', $content);
        // Limpa espaços vazios deixados
        $content = preg_replace('#\n\s*\n\s*\n#', "\n\n", $content);
    }

    return $content;
}, 999); // prioridade alta — roda depois de outros filtros

/**
 * Hook wp_footer: imprime os scripts JSON-LD após tudo (author-box, sidebar, etc)
 */
add_action('wp_footer', function () {
    global $cc_jsonld_scripts;

    if (empty($cc_jsonld_scripts)) return;
    if (!is_singular('post') && !is_singular('page')) return;

    echo "\n<!-- CC JSON-LD (movido do content para footer para melhorar LCP) -->\n";
    foreach ($cc_jsonld_scripts as $script) {
        echo $script . "\n";
    }
}, 99); // prioridade alta no footer
