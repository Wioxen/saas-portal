<?php
/**
 * preditor_snapshot — popula state do PingoPreditor independente do SpikeDetector (B4).
 *
 * Por que separado do spike_detect:
 *   - spike_detect roda a cada 10min mas SÓ persiste termos acima do threshold (1000+).
 *   - Pra detectar `rising` precisamos do snapshot do termo QUANDO ELE AINDA ESTAVA BAIXO.
 *   - Este cron roda mais frequente (5min) e LÊ TODOS os termos do feed (sem filtrar threshold)
 *     populando state pra que, quando o spike_detect detectar, já tenha histórico.
 *
 * Não persiste em DB nem cria posts. Só atualiza data/predictor_state.json.
 *
 * Uso:
 *   php scripts/preditor_snapshot.php           → ciclo único
 *   php scripts/preditor_snapshot.php --quiet
 *
 * Cron sugerido (5 min — antes do spike_detect ter chance de rodar):
 *   *\/5 * * * * /usr/bin/php /var/www/clonais/scripts/preditor_snapshot.php --quiet >> /var/log/clonais/preditor.log 2>&1
 *
 * Exit codes: 0 = OK · 1 = lock · 2 = feed indisponível
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/SpikeDetector.php';
require_once $ROOT . '/lib/PingoPreditor.php';

$quiet = false;
foreach ($argv as $a) if ($a === '--quiet') $quiet = true;

function log_msg(string $m, bool $q): void { if (!$q) echo "[preditor] {$m}\n"; }

$lock = new CronLock('preditor_snapshot');
if (!$lock->aquirir()) {
    log_msg('outra instância rodando', $quiet);
    exit(1);
}

// Reusa o parser do SpikeDetector via reflection (downloadFeed + parseFeed são privados)
$rf = new ReflectionClass('SpikeDetector');
try {
    $download = $rf->getMethod('baixarFeed');
    $download->setAccessible(true);
    $xml = $download->invoke(null);
    if ($xml === null) {
        log_msg('feed indisponível', $quiet);
        $lock->liberar();
        exit(2);
    }
    $parse = $rf->getMethod('parseFeed');
    $parse->setAccessible(true);
    $items = $parse->invoke(null, $xml);
} catch (Throwable $e) {
    log_msg('exception ao baixar/parsear: ' . $e->getMessage(), $quiet);
    $lock->liberar();
    exit(2);
}

if (empty($items)) {
    log_msg('feed retornou 0 items', $quiet);
    $lock->liberar();
    exit(0);
}

// Classifica TODOS os items (sem filtrar threshold) — popula state pro spike_detect usar
$preditor = new PingoPreditor();
$now = time();
$out = $preditor->classificar(array_map(
    fn($it) => ['termo' => (string)($it['termo'] ?? ''), 'traffic' => (int)($it['traffic'] ?? 0), 'ts' => $now],
    $items
));

$counts = ['new' => 0, 'rising' => 0, 'stable' => 0, 'declining' => 0, 'invalid' => 0];
foreach ($out as $o) {
    $l = $o['predictor_label'] ?? 'unknown';
    if (isset($counts[$l])) $counts[$l]++;
}

$stats = $preditor->stats();
log_msg(sprintf(
    "items=%d · new=%d rising=%d stable=%d declining=%d · state: %d termos / %d snapshots",
    count($items), $counts['new'], $counts['rising'], $counts['stable'], $counts['declining'],
    $stats['termos_no_state'], $stats['snapshots_total']
), $quiet);

// Webhook se aparecem >5 trends rising num único ciclo (evento raro digno de alerta)
if ($counts['rising'] >= 5) {
    $hwPath = $ROOT . '/lib/HealthWebhook.php';
    if (is_file($hwPath)) {
        require_once $hwPath;
        $top = array_slice(array_filter($out, fn($o) => ($o['predictor_label'] ?? '') === 'rising'), 0, 5);
        HealthWebhook::info('Pingo preditor: surge de trends rising', [
            'rising_count' => $counts['rising'],
            'top'          => array_map(fn($o) => ($o['termo'] ?? '?') . ' (' . round($o['momentum_pct'] ?? 0) . '%)', $top),
        ]);
    }
}

$lock->liberar();
exit(0);
