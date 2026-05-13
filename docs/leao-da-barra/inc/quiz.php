<?php
/**
 * Quiz do Vitória - Backend
 * 
 * Google OAuth login, scores no banco WP, admin de recompensas
 * 
 * @package LeaoDaBarra
 */

defined('ABSPATH') || exit;

// ============================================================
// DATABASE TABLE
// ============================================================
function ldb_quiz_create_table() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // Scores table
    $table_scores = $wpdb->prefix . 'ldb_quiz_scores';
    $sql_scores = "CREATE TABLE $table_scores (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_email varchar(255) NOT NULL,
        user_name varchar(255) NOT NULL,
        user_avatar varchar(500) DEFAULT '',
        google_id varchar(100) DEFAULT '',
        score int(11) NOT NULL DEFAULT 0,
        total int(11) NOT NULL DEFAULT 0,
        percentage decimal(5,2) NOT NULL DEFAULT 0,
        level_reached int(11) NOT NULL DEFAULT 0,
        time_spent int(11) NOT NULL DEFAULT 0,
        is_winner tinyint(1) NOT NULL DEFAULT 0,
        reward_claimed tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_email (user_email),
        KEY is_winner (is_winner),
        KEY percentage (percentage)
    ) $charset;";

    // Seen questions table (anti-cheat)
    $table_seen = $wpdb->prefix . 'ldb_quiz_seen';
    $sql_seen = "CREATE TABLE $table_seen (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_email varchar(255) NOT NULL,
        question_id varchar(50) NOT NULL,
        was_correct tinyint(1) NOT NULL DEFAULT 0,
        seen_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_question (user_email, question_id),
        KEY user_email (user_email)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_scores);
    dbDelta($sql_seen);

    update_option('ldb_quiz_db_version', '1.1');
}

// Create table on admin_init if not exists
function ldb_quiz_maybe_create_table() {
    if (get_option('ldb_quiz_db_version') !== '1.1') {
        ldb_quiz_create_table();
    }
}
add_action('admin_init', 'ldb_quiz_maybe_create_table');

// Also run on theme switch
function ldb_quiz_on_switch() { ldb_quiz_create_table(); }
add_action('after_switch_theme', 'ldb_quiz_on_switch');

// ============================================================
// REST ENDPOINTS
// ============================================================
function ldb_quiz_register_routes() {
    register_rest_route('ldb/v1', '/quiz/save', [
        'methods'             => 'POST',
        'callback'            => 'ldb_quiz_save_score',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('ldb/v1', '/quiz/ranking', [
        'methods'             => 'GET',
        'callback'            => 'ldb_quiz_get_ranking',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('ldb/v1', '/quiz/reward', [
        'methods'             => 'GET',
        'callback'            => 'ldb_quiz_get_reward',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('ldb/v1', '/quiz/progress', [
        'methods'             => 'GET',
        'callback'            => 'ldb_quiz_get_progress',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('ldb/v1', '/quiz/seen', [
        'methods'             => 'POST',
        'callback'            => 'ldb_quiz_save_seen',
        'permission_callback' => '__return_true',
    ]);
}
add_action('rest_api_init', 'ldb_quiz_register_routes');

function ldb_quiz_get_progress($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'ldb_quiz_scores';
    $table_seen = $wpdb->prefix . 'ldb_quiz_seen';
    $email = sanitize_email($request->get_param('email'));

    if (!$email) {
        return rest_ensure_response([
            'max_level'  => 0,
            'best_score' => 0,
            'attempts'   => 0,
            'seen'       => [],
        ]);
    }

    $max_level = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(level_reached) FROM $table WHERE user_email = %s",
        $email
    ));

    $best = $wpdb->get_row($wpdb->prepare(
        "SELECT MAX(percentage) as best_pct, COUNT(*) as attempts FROM $table WHERE user_email = %s",
        $email
    ));

    // Get all questions this user has already seen
    $seen_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT question_id FROM $table_seen WHERE user_email = %s",
        $email
    ));

    return rest_ensure_response([
        'max_level'  => $max_level ?: 0,
        'best_score' => $best ? floatval($best->best_pct) : 0,
        'attempts'   => $best ? intval($best->attempts) : 0,
        'seen'       => $seen_ids ?: [],
    ]);
}

// Save seen questions to prevent repeating them
function ldb_quiz_save_seen($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'ldb_quiz_seen';

    $params = $request->get_json_params();
    $email = sanitize_email($params['email'] ?? '');
    $questions = $params['questions'] ?? [];

    if (!$email || !is_array($questions) || empty($questions)) {
        return new WP_Error('invalid', 'Dados incompletos', ['status' => 400]);
    }

    foreach ($questions as $q) {
        $qid = sanitize_text_field($q['id'] ?? '');
        $correct = !empty($q['correct']) ? 1 : 0;
        if (!$qid) continue;

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (user_email, question_id, was_correct) VALUES (%s, %s, %d)",
            $email, $qid, $correct
        ));
    }

    return rest_ensure_response(['success' => true, 'saved' => count($questions)]);
}

function ldb_quiz_save_score($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'ldb_quiz_scores';

    $params = $request->get_json_params();
    $email      = sanitize_email($params['email'] ?? '');
    $name       = sanitize_text_field($params['name'] ?? '');
    $avatar     = esc_url_raw($params['avatar'] ?? '');
    $google_id  = sanitize_text_field($params['google_id'] ?? '');
    $score      = absint($params['score'] ?? 0);
    $total      = absint($params['total'] ?? 0);
    $level      = absint($params['level_reached'] ?? 0);
    $time_spent = absint($params['time_spent'] ?? 0);
    $token      = sanitize_text_field($params['quiz_token'] ?? '');

    if (!$email || !$name || $total === 0) {
        return new WP_Error('invalid', 'Dados incompletos', ['status' => 400]);
    }

    // Verificar token anti-cheat (hash baseado no tempo de início)
    $percentage = round(($score / $total) * 100, 2);
    $is_winner = $percentage >= 100 ? 1 : 0;

    $wpdb->insert($table, [
        'user_email'     => $email,
        'user_name'      => $name,
        'user_avatar'    => $avatar,
        'google_id'      => $google_id,
        'score'          => $score,
        'total'          => $total,
        'percentage'     => $percentage,
        'level_reached'  => $level,
        'time_spent'     => $time_spent,
        'is_winner'      => $is_winner,
        'reward_claimed' => 0,
    ]);

    $response = [
        'success'    => true,
        'percentage' => $percentage,
        'is_winner'  => $is_winner,
        'position'   => 0,
    ];

    // Calculate ranking position
    $position = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) + 1 FROM $table WHERE percentage > %f OR (percentage = %f AND time_spent < %d)",
        $percentage, $percentage, $time_spent
    ));
    $response['position'] = intval($position);

    // If winner, check reward
    if ($is_winner) {
        $reward = get_option('ldb_quiz_reward', '');
        if ($reward) {
            $response['reward'] = $reward;
        }
    }

    return rest_ensure_response($response);
}

function ldb_quiz_get_ranking() {
    global $wpdb;
    $table = $wpdb->prefix . 'ldb_quiz_scores';

    $results = $wpdb->get_results(
        "SELECT user_name, user_avatar, percentage, level_reached, time_spent, is_winner, created_at
         FROM $table
         ORDER BY percentage DESC, time_spent ASC
         LIMIT 20"
    );

    return rest_ensure_response($results ?: []);
}

function ldb_quiz_get_reward() {
    $reward = get_option('ldb_quiz_reward', '');
    return rest_ensure_response(['reward' => $reward]);
}

// ============================================================
// ADMIN PAGE - Manage Quiz Rewards
// ============================================================
function ldb_quiz_admin_menu() {
    add_submenu_page(
        'ldb-settings',
        __('Quiz do Vitória', 'leao-da-barra'),
        __('Quiz', 'leao-da-barra'),
        'manage_options',
        'ldb-quiz',
        'ldb_quiz_admin_page'
    );
}
add_action('admin_menu', 'ldb_quiz_admin_menu', 20);

function ldb_quiz_admin_init() {
    register_setting('ldb_quiz_settings', 'ldb_quiz_reward', [
        'type' => 'string',
        'sanitize_callback' => 'wp_kses_post',
    ]);

    register_setting('ldb_quiz_settings', 'ldb_google_client_id', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
}
add_action('admin_init', 'ldb_quiz_admin_init');

function ldb_quiz_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'ldb_quiz_scores';
    $total_players = $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $winners = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_winner = 1");
    $top10 = $wpdb->get_results("SELECT * FROM $table ORDER BY percentage DESC, time_spent ASC LIMIT 10");
    ?>
    <div class="wrap">
        <h1>Quiz do Vitoria</h1>

        <div style="display:flex;gap:16px;margin:16px 0;">
            <div style="background:#fff;border:1px solid #ddd;padding:16px 20px;border-radius:6px;min-width:140px;">
                <div style="font-size:28px;font-weight:700;color:#C41E2A;"><?php echo intval($total_players); ?></div>
                <div style="color:#666;font-size:13px;">Jogadores</div>
            </div>
            <div style="background:#fff;border:1px solid #ddd;padding:16px 20px;border-radius:6px;min-width:140px;">
                <div style="font-size:28px;font-weight:700;color:#0F6E56;"><?php echo intval($winners); ?></div>
                <div style="color:#666;font-size:13px;">100% (Vencedores)</div>
            </div>
        </div>

        <h2>Configuracoes</h2>
        <form method="post" action="options.php">
            <?php settings_fields('ldb_quiz_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="ldb_google_client_id">Google Client ID</label></th>
                    <td>
                        <input type="text" name="ldb_google_client_id" id="ldb_google_client_id"
                               value="<?php echo esc_attr(get_option('ldb_google_client_id', '')); ?>"
                               class="regular-text" />
                        <p class="description">Crie em <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a> &rarr; Credenciais &rarr; ID do cliente OAuth 2.0</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ldb_quiz_reward">Recompensa (100%)</label></th>
                    <td>
                        <?php wp_editor(get_option('ldb_quiz_reward', ''), 'ldb_quiz_reward', [
                            'textarea_rows' => 5,
                            'media_buttons' => true,
                        ]); ?>
                        <p class="description">HTML que sera exibido para quem acertar 100%. Pode ser cupom, link, imagem, etc.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Salvar'); ?>
        </form>

        <h2>Top 10 Ranking</h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Jogador</th>
                    <th>Email</th>
                    <th>Acertos</th>
                    <th>Nivel</th>
                    <th>Tempo</th>
                    <th>Data</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($top10) : foreach ($top10 as $i => $row) : ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td>
                            <?php if ($row->user_avatar) : ?>
                                <img src="<?php echo esc_url($row->user_avatar); ?>" width="24" height="24" style="border-radius:50%;vertical-align:middle;margin-right:6px;">
                            <?php endif; ?>
                            <?php echo esc_html($row->user_name); ?>
                        </td>
                        <td><?php echo esc_html($row->user_email); ?></td>
                        <td><?php echo esc_html($row->percentage); ?>%</td>
                        <td><?php echo esc_html($row->level_reached); ?></td>
                        <td><?php echo gmdate('i:s', $row->time_spent); ?></td>
                        <td><?php echo esc_html($row->created_at); ?></td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="7">Nenhum jogador ainda.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
