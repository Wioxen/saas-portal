<?php
/**
 * Plugin Name: CC Clean Empty P Tags
 * Description: Remove <p></p> vazios do conteúdo dos posts (novos e antigos). Funciona em tempo real via the_content.
 * Version: 1.0
 * Author: Como Comprar
 *
 * Instalar: copiar para wp-content/plugins/ e ativar.
 */

if (!defined('ABSPATH')) exit;

/**
 * Filtro no the_content: remove <p></p> vazios em tempo real (renderização)
 * Funciona para posts antigos e novos sem precisar editar.
 */
add_filter('the_content', function ($content) {
    if (!$content) return $content;

    // Remove <p></p> vazios (com ou sem espaços/nbsp)
    $content = preg_replace('#<p>\s*</p>#i', '', $content);
    $content = preg_replace('#<p>&nbsp;</p>#i', '', $content);
    $content = preg_replace('#<p>\s*&nbsp;\s*</p>#i', '', $content);

    // Remove <br> soltos repetidos (3+)
    $content = preg_replace('#(<br\s*/?\s*>){3,}#i', '', $content);

    // Remove linhas em branco excessivas
    $content = preg_replace('#\n{3,}#', "\n\n", $content);

    return $content;
}, 20); // prioridade 20 = roda antes do cc-move-jsonld-footer (999)

/**
 * Filtro no wp_insert_post_data: limpa ANTES de salvar no banco.
 * Garante que posts novos criados via API já entram limpos.
 */
add_filter('wp_insert_post_data', function ($data) {
    if (empty($data['post_content'])) return $data;

    $content = $data['post_content'];

    $content = preg_replace('#<p>\s*</p>#i', '', $content);
    $content = preg_replace('#<p>&nbsp;</p>#i', '', $content);
    $content = preg_replace('#<p>\s*&nbsp;\s*</p>#i', '', $content);
    $content = preg_replace('#(<br\s*/?\s*>){3,}#i', '', $content);
    $content = preg_replace('#\n{3,}#', "\n\n", $content);

    $data['post_content'] = $content;
    return $data;
}, 10);
