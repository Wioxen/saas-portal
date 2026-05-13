<?php
/**
 * Custom Widgets
 * 
 * Widgets para tabela, placar ao vivo, próximos jogos
 * 
 * @package LeaoDaBarra
 */

defined('ABSPATH') || exit;

// ============================================================
// WIDGET: TABELA DE CLASSIFICAÇÃO
// ============================================================
class LDB_Widget_Tabela extends WP_Widget {
    public function __construct() {
        parent::__construct('ldb_tabela', __('Tabela de Classificação', 'leao-da-barra'), [
            'description' => __('Exibe tabela de classificação via API Futebol', 'leao-da-barra'),
        ]);
    }

    public function widget($args, $instance) {
        $camp_id = $instance['campeonato_id'] ?? 10;
        $limit   = $instance['limit'] ?? 10;
        $title   = $instance['title'] ?? 'Classificação';

        echo $args['before_widget'];
        ?>
        <div class="ldb-tabela-widget" data-campeonato="<?php echo esc_attr($camp_id); ?>" data-limit="<?php echo esc_attr($limit); ?>">
            <div class="ldb-tabela-header">
                <h3 class="ldb-widget-title"><?php echo esc_html($title); ?></h3>
            </div>
            <div class="ldb-tabela-body" id="ldb-tabela-<?php echo esc_attr($camp_id); ?>">
                <div class="ldb-loading">Carregando...</div>
            </div>
            <a href="<?php echo esc_url(home_url('/tabela/')); ?>" class="ldb-tabela-link">Ver tabela completa →</a>
        </div>
        <?php
        echo $args['after_widget'];
    }

    public function form($instance) {
        $title   = $instance['title'] ?? 'Classificação';
        $camp_id = $instance['campeonato_id'] ?? 10;
        $limit   = $instance['limit'] ?? 10;
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Título:', 'leao-da-barra'); ?></label>
            <input class="widefat" type="text" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" value="<?php echo esc_attr($title); ?>" />
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('campeonato_id'); ?>"><?php _e('ID Campeonato:', 'leao-da-barra'); ?></label>
            <input class="widefat" type="number" id="<?php echo $this->get_field_id('campeonato_id'); ?>" name="<?php echo $this->get_field_name('campeonato_id'); ?>" value="<?php echo esc_attr($camp_id); ?>" />
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('limit'); ?>"><?php _e('Nº de times:', 'leao-da-barra'); ?></label>
            <input class="widefat" type="number" id="<?php echo $this->get_field_id('limit'); ?>" name="<?php echo $this->get_field_name('limit'); ?>" value="<?php echo esc_attr($limit); ?>" min="4" max="20" />
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        return [
            'title'         => sanitize_text_field($new_instance['title'] ?? ''),
            'campeonato_id' => absint($new_instance['campeonato_id'] ?? 10),
            'limit'         => absint($new_instance['limit'] ?? 10),
        ];
    }
}

// ============================================================
// WIDGET: PLACAR AO VIVO
// ============================================================
class LDB_Widget_AoVivo extends WP_Widget {
    public function __construct() {
        parent::__construct('ldb_ao_vivo', __('Placar Ao Vivo', 'leao-da-barra'), [
            'description' => __('Exibe jogos ao vivo', 'leao-da-barra'),
        ]);
    }

    public function widget($args, $instance) {
        echo $args['before_widget'];
        ?>
        <div class="ldb-live-widget" id="ldb-live-scores">
            <div class="ldb-live-header">
                <span class="ldb-live-dot"></span>
                <span class="ldb-live-label"><?php _e('Ao Vivo', 'leao-da-barra'); ?></span>
            </div>
            <div class="ldb-live-body">
                <div class="ldb-loading">Verificando jogos...</div>
            </div>
        </div>
        <?php
        echo $args['after_widget'];
    }

    public function form($instance) {
        echo '<p>' . __('Este widget exibe automaticamente os jogos ao vivo.', 'leao-da-barra') . '</p>';
    }

    public function update($new_instance, $old_instance) {
        return $new_instance;
    }
}

// ============================================================
// WIDGET: PRÓXIMOS JOGOS DO VITÓRIA
// ============================================================
class LDB_Widget_ProximosJogos extends WP_Widget {
    public function __construct() {
        parent::__construct('ldb_proximos_jogos', __('Próximos Jogos', 'leao-da-barra'), [
            'description' => __('Exibe os próximos jogos do Vitória', 'leao-da-barra'),
        ]);
    }

    public function widget($args, $instance) {
        $title = $instance['title'] ?? 'Próximos Jogos';
        $limit = $instance['limit'] ?? 3;

        echo $args['before_widget'];
        ?>
        <div class="ldb-fixtures-widget" data-limit="<?php echo esc_attr($limit); ?>">
            <h3 class="ldb-widget-title"><?php echo esc_html($title); ?></h3>
            <div class="ldb-fixtures-body" id="ldb-fixtures">
                <div class="ldb-loading">Carregando...</div>
            </div>
        </div>
        <?php
        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = $instance['title'] ?? 'Próximos Jogos';
        $limit = $instance['limit'] ?? 3;
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Título:', 'leao-da-barra'); ?></label>
            <input class="widefat" type="text" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" value="<?php echo esc_attr($title); ?>" />
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('limit'); ?>"><?php _e('Nº de jogos:', 'leao-da-barra'); ?></label>
            <input class="widefat" type="number" id="<?php echo $this->get_field_id('limit'); ?>" name="<?php echo $this->get_field_name('limit'); ?>" value="<?php echo esc_attr($limit); ?>" min="1" max="10" />
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        return [
            'title' => sanitize_text_field($new_instance['title'] ?? ''),
            'limit' => absint($new_instance['limit'] ?? 3),
        ];
    }
}

// Registrar widgets
function ldb_register_widgets() {
    register_widget('LDB_Widget_Tabela');
    register_widget('LDB_Widget_AoVivo');
    register_widget('LDB_Widget_ProximosJogos');
}
add_action('widgets_init', 'ldb_register_widgets');
