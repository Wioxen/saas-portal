<?php
/**
 * Shortcodes
 * 
 * Shortcodes para inserir dados da API nos posts
 * [ldb_tabela campeonato_id="10" limit="20"]
 * [ldb_placar partida_id="7034"]
 * [ldb_ao_vivo]
 * [ldb_campeonatos]
 * 
 * @package LeaoDaBarra
 */

defined('ABSPATH') || exit;

/**
 * [ldb_tabela] - Tabela de classificação
 */
function ldb_shortcode_tabela($atts) {
    $atts = shortcode_atts([
        'campeonato_id' => 10,
        'limit'         => 20,
        'highlight'     => 'vitoria',
    ], $atts, 'ldb_tabela');

    $id    = absint($atts['campeonato_id']);
    $limit = absint($atts['limit']);

    ob_start();
    ?>
    <div class="ldb-tabela-shortcode" data-campeonato="<?php echo $id; ?>" data-limit="<?php echo $limit; ?>" data-highlight="<?php echo esc_attr($atts['highlight']); ?>">
        <div class="ldb-tabela-container" id="ldb-sc-tabela-<?php echo $id; ?>">
            <div class="ldb-loading">
                <div class="ldb-spinner"></div>
                <span>Carregando classificação...</span>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('ldb_tabela', 'ldb_shortcode_tabela');

/**
 * [ldb_placar] - Placar de uma partida
 */
function ldb_shortcode_placar($atts) {
    $atts = shortcode_atts([
        'partida_id' => 0,
    ], $atts, 'ldb_placar');

    $id = absint($atts['partida_id']);
    if (!$id) return '<p class="ldb-error">ID da partida não informado.</p>';

    ob_start();
    ?>
    <div class="ldb-placar-shortcode" data-partida="<?php echo $id; ?>">
        <div class="ldb-placar-container" id="ldb-sc-placar-<?php echo $id; ?>">
            <div class="ldb-loading">
                <div class="ldb-spinner"></div>
                <span>Carregando placar...</span>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('ldb_placar', 'ldb_shortcode_placar');

/**
 * [ldb_ao_vivo] - Jogos ao vivo
 */
function ldb_shortcode_ao_vivo($atts) {
    ob_start();
    ?>
    <div class="ldb-ao-vivo-shortcode">
        <div class="ldb-ao-vivo-container" id="ldb-sc-ao-vivo">
            <div class="ldb-loading">
                <div class="ldb-spinner"></div>
                <span>Verificando jogos ao vivo...</span>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('ldb_ao_vivo', 'ldb_shortcode_ao_vivo');

/**
 * [ldb_campeonatos] - Lista de campeonatos
 */
function ldb_shortcode_campeonatos($atts) {
    $atts = shortcode_atts([
        'regiao' => '', // nacional, regional, internacional
    ], $atts, 'ldb_campeonatos');

    ob_start();
    ?>
    <div class="ldb-campeonatos-shortcode" data-regiao="<?php echo esc_attr($atts['regiao']); ?>">
        <div class="ldb-campeonatos-container" id="ldb-sc-campeonatos">
            <div class="ldb-loading">
                <div class="ldb-spinner"></div>
                <span>Carregando campeonatos...</span>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('ldb_campeonatos', 'ldb_shortcode_campeonatos');

/**
 * [ldb_artilharia] - Artilharia de um campeonato
 */
function ldb_shortcode_artilharia($atts) {
    $atts = shortcode_atts([
        'campeonato_id' => 10,
        'limit'         => 10,
    ], $atts, 'ldb_artilharia');

    $id    = absint($atts['campeonato_id']);
    $limit = absint($atts['limit']);

    ob_start();
    ?>
    <div class="ldb-artilharia-shortcode" data-campeonato="<?php echo $id; ?>" data-limit="<?php echo $limit; ?>">
        <div class="ldb-artilharia-container" id="ldb-sc-artilharia-<?php echo $id; ?>">
            <div class="ldb-loading">
                <div class="ldb-spinner"></div>
                <span>Carregando artilharia...</span>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('ldb_artilharia', 'ldb_shortcode_artilharia');
