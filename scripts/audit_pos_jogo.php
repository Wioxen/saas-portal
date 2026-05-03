<?php
declare(strict_types=1);
/**
 * scripts/audit_pos_jogo.php
 *
 * Auditoria diária do pipeline pós-jogo. Roda 1x/dia 09:00 BR (12:00 UTC) via cron:
 *   0 12 * * * root php /app/scripts/audit_pos_jogo.php >> /var/log/audit_posjogo.log 2>&1
 *
 * Pra cada jogo finalizado nas últimas 24h:
 *   - Tem posts_gerados.pos_jogo preenchido? Se SIM, sucesso.
 *   - Se NÃO, alerta (post não gerado).
 *
 * Pra cada jogo agendado nas próximas 6h:
 *   - posts_gerados.pre_jogo está preenchido? Se SIM, ok.
 *   - Se NÃO, sinal de atenção (orquestrador vai ter que disparar).
 *
 * Saída TRIPLA (pra garantir visibilidade):
 *   1. STDOUT (cron loga em /var/log/audit_posjogo.log)
 *   2. WP draft post "📊 AUDIT pós-jogo {data}" no leaodabarra
 *   3. data/audit_posjogo_status.json (legível via dashboard)
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/JogosCalendario.php';
require_once __DIR__ . '/../lib/Wordpress.php';

$opts = getopt('', ['site::', 'dry-run', 'force-post']);
$siteSlug = (string)($opts['site'] ?? 'leaodabarra');
$dryRun   = isset($opts['dry-run']);
$forcePost = isset($opts['force-post']);  // gera post mesmo se nada de novo

$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteSlug);

$jsonPath = __DIR__ . '/../data/jogos_vitoria.json';
$cal = new JogosCalendario($jsonPath);

$agora = time();
$h24Atras = $agora - 86400;
$h6Frente = $agora + 6 * 3600;

// Carrega jogos
$dados = json_decode((string)file_get_contents($jsonPath), true) ?: [];
$jogos = (array)($dados['jogos'] ?? []);

$relatorio = [
    'data_auditoria' => date('c'),
    'site' => $siteSlug,
    'jogos_recentes' => [],     // finalizados nas últimas 24h
    'jogos_proximos' => [],     // próximos 6h
    'alertas' => [],
    'sucessos' => [],
];

foreach ($jogos as $j) {
    $data = $j['data'] ?? '';
    $hora = $j['hora'] ?? '21:30';
    $tz = $j['timezone'] ?? 'America/Sao_Paulo';
    if ($data === '') continue;

    try {
        $dt = new DateTime("{$data} {$hora}", new DateTimeZone($tz));
        $tsJogo = $dt->getTimestamp();
    } catch (Throwable $e) { continue; }

    $idJogo = $j['id'] ?? "({$data})";
    $advNome = $j['adversario']['nome'] ?? '?';
    $posIds = $j['posts_gerados'] ?? [];
    $jaFinalizado = ($j['status'] ?? '') === 'finalizado' || $tsJogo + 7200 < $agora;

    // Jogos FINALIZADOS nas últimas 24h
    if ($jaFinalizado && $tsJogo >= $h24Atras && $tsJogo <= $agora) {
        $temPosJogo = !empty($posIds['pos_jogo']);
        $info = [
            'id' => $idJogo,
            'data' => $data,
            'adversario' => $advNome,
            'posts' => $posIds,
            'pos_jogo_gerado' => $temPosJogo,
        ];
        $relatorio['jogos_recentes'][] = $info;
        if ($temPosJogo) {
            $relatorio['sucessos'][] = "✓ Pós-jogo de {$idJogo} (vs {$advNome}) gerado: post #{$posIds['pos_jogo']}";
        } else {
            $relatorio['alertas'][] = "🚨 SEM PÓS-JOGO: jogo {$idJogo} (vs {$advNome}) finalizado mas posts_gerados.pos_jogo=null. Investigar!";
        }
    }

    // Jogos PRÓXIMOS nas próximas 6h
    if ($tsJogo > $agora && $tsJogo <= $h6Frente) {
        $temPreJogo = !empty($posIds['pre_jogo']);
        $info = [
            'id' => $idJogo,
            'data' => $data,
            'hora' => $hora,
            'adversario' => $advNome,
            'horas_ate' => round(($tsJogo - $agora) / 3600, 1),
            'posts' => $posIds,
            'pre_jogo_gerado' => $temPreJogo,
        ];
        $relatorio['jogos_proximos'][] = $info;
        if ($temPreJogo) {
            $relatorio['sucessos'][] = "✓ Pré-jogo de {$idJogo} (vs {$advNome}) gerado: post #{$posIds['pre_jogo']}";
        } elseif (($tsJogo - $agora) <= 3 * 3600) {
            // Já entrou em janela pré-jogo (T-3h) e ainda não tem
            $relatorio['alertas'][] = "⚠️ PRÉ-JOGO PENDENTE: jogo {$idJogo} (vs {$advNome}) em {$info['horas_ate']}h, sem pre_jogo gerado. Orquestrador deveria disparar nos próximos 5 min.";
        }
    }
}

// Imprime resumo no stdout (cron log)
echo "═══ AUDIT PÓS-JOGO · " . date('Y-m-d H:i') . " · site={$siteSlug} ═══\n\n";
echo "JOGOS RECENTES (últimas 24h): " . count($relatorio['jogos_recentes']) . "\n";
foreach ($relatorio['jogos_recentes'] as $j) {
    $marker = $j['pos_jogo_gerado'] ? '✓' : '🚨';
    echo "  {$marker} {$j['id']} vs {$j['adversario']} — pos_jogo: " . ($j['posts']['pos_jogo'] ?? 'NULL') . "\n";
}

echo "\nJOGOS PRÓXIMOS (6h): " . count($relatorio['jogos_proximos']) . "\n";
foreach ($relatorio['jogos_proximos'] as $j) {
    $marker = $j['pre_jogo_gerado'] ? '✓' : ($j['horas_ate'] <= 3 ? '⚠️' : '○');
    echo "  {$marker} {$j['id']} vs {$j['adversario']} em {$j['horas_ate']}h — pre_jogo: " . ($j['posts']['pre_jogo'] ?? 'NULL') . "\n";
}

echo "\nALERTAS: " . count($relatorio['alertas']) . "\n";
foreach ($relatorio['alertas'] as $a) echo "  {$a}\n";

echo "\nSUCESSOS: " . count($relatorio['sucessos']) . "\n";
foreach ($relatorio['sucessos'] as $s) echo "  {$s}\n";

if ($dryRun) { echo "\n[dry-run] sem gravar\n"; exit(0); }

// Grava JSON status (legível via dashboard depois)
$statusPath = __DIR__ . '/../data/audit_posjogo_status.json';
file_put_contents($statusPath, json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

// Cria WP draft SE houver alertas OU --force-post
$temNovidade = !empty($relatorio['alertas']) || !empty($relatorio['sucessos']);
$temAlerta   = !empty($relatorio['alertas']);

if ($forcePost || $temAlerta) {
    $dataLabel = date('d/m');
    $marker = $temAlerta ? '🚨' : '📊';
    $tituloAudit = "{$marker} AUDIT pós-jogo {$dataLabel} ({$siteSlug})";

    $htmlBody = "<h2>Resumo da auditoria · " . date('d/m/Y H:i') . "</h2>\n";
    $htmlBody .= "<p><strong>Site:</strong> {$siteSlug} · <strong>Alertas:</strong> " . count($relatorio['alertas']) . " · <strong>Sucessos:</strong> " . count($relatorio['sucessos']) . "</p>\n";

    if (!empty($relatorio['alertas'])) {
        $htmlBody .= "<h3>🚨 Alertas</h3><ul>\n";
        foreach ($relatorio['alertas'] as $a) $htmlBody .= "<li>" . htmlspecialchars($a, ENT_QUOTES, 'UTF-8') . "</li>\n";
        $htmlBody .= "</ul>\n";
    }
    if (!empty($relatorio['jogos_recentes'])) {
        $htmlBody .= "<h3>Jogos finalizados (24h)</h3><ul>\n";
        foreach ($relatorio['jogos_recentes'] as $j) {
            $marker = $j['pos_jogo_gerado'] ? '✓' : '🚨';
            $linkPost = !empty($j['posts']['pos_jogo']) ? " · <a href=\"{$cfg['wp_url']}/wp-admin/post.php?post={$j['posts']['pos_jogo']}&action=edit\">post #{$j['posts']['pos_jogo']}</a>" : '';
            $htmlBody .= "<li>{$marker} <strong>{$j['adversario']}</strong> — pos_jogo: " . ($j['posts']['pos_jogo'] ?? 'NULL') . "{$linkPost}</li>\n";
        }
        $htmlBody .= "</ul>\n";
    }
    if (!empty($relatorio['jogos_proximos'])) {
        $htmlBody .= "<h3>Jogos próximos (6h)</h3><ul>\n";
        foreach ($relatorio['jogos_proximos'] as $j) {
            $marker = $j['pre_jogo_gerado'] ? '✓' : ($j['horas_ate'] <= 3 ? '⚠️' : '○');
            $htmlBody .= "<li>{$marker} <strong>{$j['adversario']}</strong> em {$j['horas_ate']}h — pre_jogo: " . ($j['posts']['pre_jogo'] ?? 'NULL') . "</li>\n";
        }
        $htmlBody .= "</ul>\n";
    }
    $htmlBody .= "<hr><p><small>Gerado por audit_pos_jogo.php · " . date('c') . "</small></p>";

    try {
        $wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        $post = $wp->criarPost([
            'title'   => $tituloAudit,
            'content' => $htmlBody,
            'status'  => 'draft',
            'meta'    => ['rank_math_focus_keyword' => 'audit interno'],
        ]);
        $auditId = (int)($post['id'] ?? 0);
        echo "\n✓ AUDIT POST criado #{$auditId} (status=draft)\n";
        echo "  Edit: {$cfg['wp_url']}/wp-admin/post.php?post={$auditId}&action=edit\n";
    } catch (Throwable $e) {
        echo "\n⚠️ Falha ao criar audit post: " . $e->getMessage() . "\n";
    }
}

echo "\n[ok] " . date('H:i') . " · status: " . $statusPath . "\n";
exit(empty($relatorio['alertas']) ? 0 : 0);  // sempre 0 (cron não deve falhar)
