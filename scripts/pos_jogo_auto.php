<?php
declare(strict_types=1);
/**
 * scripts/pos_jogo_auto.php
 *
 * Cron entry pra orquestrador de pós-jogo. Chamado a cada 5 min via:
 *   (every-5-min) root php /app/scripts/pos_jogo_auto.php --site=leaodabarra >> /var/log/posjogo.log 2>&1
 *
 * Comportamento:
 *   - Idle 99% do tempo (silently skip se fora de janela de jogo)
 *   - Quando entra em janela (pre/pos), gera o post UMA VEZ (idempotência via
 *     data/jogos_vitoria.json → posts_gerados)
 *
 * Uso manual / debug:
 *   php scripts/pos_jogo_auto.php --site=leaodabarra --verbose
 *   php scripts/pos_jogo_auto.php --site=leaodabarra --dry-run
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/PosJogoOrquestrador.php';

$opts = getopt('', ['site::', 'verbose', 'dry-run']);
$siteSlug = (string)($opts['site'] ?? 'leaodabarra');
$verbose  = isset($opts['verbose']);
$dryRun   = isset($opts['dry-run']);

$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteSlug);

if ($dryRun) {
    require_once __DIR__ . '/../lib/JogosCalendario.php';
    $cal = new JogosCalendario(__DIR__ . '/../data/jogos_vitoria.json');
    $janela = $cal->janelaAtual();
    if ($janela) {
        echo json_encode([
            'janela_atual' => $janela['tipo'],
            'jogo' => [
                'id' => $janela['jogo']['id'],
                'data' => $janela['jogo']['data'],
                'hora' => $janela['jogo']['hora'],
                'adversario' => $janela['jogo']['adversario']['nome'] ?? '?',
                'mando' => $janela['jogo']['mando'],
                'estadio' => $janela['jogo']['estadio'],
            ],
            'posts_gerados' => $janela['jogo']['posts_gerados'] ?? null,
            'cadencia_pingo_sugerida' => $cal->cadenciaPingoMinutos(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        $h = $cal->horasAteProximoJogo();
        echo json_encode([
            'janela_atual' => null,
            'horas_ate_proximo_jogo' => $h,
            'cadencia_pingo_sugerida' => $cal->cadenciaPingoMinutos(),
        ], JSON_UNESCAPED_UNICODE) . "\n";
    }
    exit(0);
}

$orq = new PosJogoOrquestrador(
    __DIR__ . '/../data/jogos_vitoria.json',
    $siteSlug,
    $cfg,
    $verbose
);

$resultado = $orq->executar();

// Log estruturado pro stdout (cron loga em /var/log/posjogo.log)
$ts = date('Y-m-d H:i:s');
echo "[{$ts}] " . json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

// Exit code: 0 sempre (cron não deve falhar — skip é normal)
exit(0);
