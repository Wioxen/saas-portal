<?php
/**
 * heartbeat_check — dead-man-switch do pipeline.
 *
 * Cron horário (sugerido). Pra cada site verifica:
 *   1. Quantas horas desde o último post publicado?
 *   2. Quantas horas desde o último trend detectado (pingo)?
 *
 * Se > HEARTBEAT_MAX_HORAS_SEM_POST (default 4h), envia alerta via HealthWebhook
 * (Discord/Telegram). Sem isso, pipeline pode estar morto há 12h sem ninguém saber.
 *
 * Throttle: HealthWebhook tem throttle 30min built-in. Plus state próprio
 * (data/heartbeat_state.json) com cooldown configurável (default 4h) pra evitar
 * spam de "horas sem post" em pipeline genuinamente parado por dias.
 *
 * Uso:
 *   php scripts/heartbeat_check.php
 *   php scripts/heartbeat_check.php --quiet
 *   php scripts/heartbeat_check.php --dry-run    (só mostra o que faria)
 *
 * Cron Linux:  0 * * * * /usr/bin/php /path/to/scripts/heartbeat_check.php --quiet
 *
 * NÃO é bloqueado pelo PIPELINE_PAUSED — heartbeat funciona mesmo com pipeline pausado
 * (pra detectar pause prolongado ou esquecido).
 */

set_time_limit(60);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/Env.php';
require_once $ROOT . '/lib/HealthWebhook.php';
require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/_site_helper.php';

$quiet = false;
$dryRun = false;
foreach ($argv as $a) {
    if ($a === '--quiet')   $quiet = true;
    if ($a === '--dry-run') $dryRun = true;
}

function hb_log(string $msg, bool $q): void {
    if (!$q) echo "[heartbeat] {$msg}\n";
}

@Env::load($ROOT . '/.env');
$maxHoras    = (int)Env::get('HEARTBEAT_MAX_HORAS_SEM_POST', '4');
$rateHoras   = (int)Env::get('HEARTBEAT_RATE_LIMIT_HORAS', '4');
$maxHoras    = max(1, $maxHoras);
$rateHoras   = max(1, $rateHoras);

$sites = sitesDisponiveis();
$db = new DiscoverDb();

// Estado pra rate-limit por site (não spammar enquanto pipeline está parado)
$statePath = $ROOT . '/data/heartbeat_state.json';
$state = is_file($statePath) ? (json_decode((string)@file_get_contents($statePath), true) ?: []) : [];
if (!is_array($state)) $state = [];

$alertados = 0;
$saudaveis = 0;

foreach ($sites as $slug => $cfg) {
    // Último post publicado por site (push-down via filtro range)
    $ultimos = $db->all([
        'site'     => $slug,
        'status'   => 'publicado',
        'order_by' => 'publicado_desc',
        'limit'    => 1,
    ]);
    $ultimoPost = $ultimos[0] ?? null;

    if ($ultimoPost === null) {
        // Nunca publicou — pode ser site recém-criado. Não alerta agressivamente,
        // mas loga.
        hb_log("[{$slug}] sem posts publicados ainda — skip", $quiet);
        continue;
    }

    $tsUltimo = strtotime((string)($ultimoPost['publicado_em'] ?? ''));
    if (!$tsUltimo) {
        hb_log("[{$slug}] post sem publicado_em parseável — skip", $quiet);
        continue;
    }

    $horasDesde = (int)floor((time() - $tsUltimo) / 3600);
    if ($horasDesde < $maxHoras) {
        $saudaveis++;
        hb_log("[{$slug}] OK — último post há {$horasDesde}h", $quiet);
        continue;
    }

    // Pipeline parado pro site. Verifica rate-limit local (cooldown próprio).
    $ultimoAlerta = (int)($state[$slug]['last_alert_ts'] ?? 0);
    $horasDesdeAlerta = $ultimoAlerta > 0 ? (int)floor((time() - $ultimoAlerta) / 3600) : 999;
    if ($horasDesdeAlerta < $rateHoras) {
        hb_log("[{$slug}] sem post há {$horasDesde}h MAS já alertou há {$horasDesdeAlerta}h — skip cooldown", $quiet);
        continue;
    }

    // Dispara alerta
    $assunto = "Pipeline parado: {$slug} sem post há {$horasDesde}h";
    $ctx = [
        'site'              => $slug,
        'horas_desde_post'  => $horasDesde,
        'limite_horas'      => $maxHoras,
        'ultimo_post_titulo'=> mb_substr((string)($ultimoPost['titulo'] ?? '?'), 0, 80, 'UTF-8'),
        'ultimo_post_url'   => (string)($ultimoPost['url_post'] ?? ''),
        'ultimo_post_em'    => (string)($ultimoPost['publicado_em'] ?? ''),
        'severity'          => $horasDesde >= ($maxHoras * 3) ? 'crítico' : 'warning',
    ];
    if ($dryRun) {
        hb_log("[dry] alertaria {$slug}: " . $assunto, $quiet);
    } else {
        $sev = $horasDesde >= ($maxHoras * 3) ? HealthWebhook::ERROR : HealthWebhook::WARNING;
        $enviado = HealthWebhook::alertar($sev, $assunto, $ctx);
        if ($enviado) {
            $state[$slug]['last_alert_ts'] = time();
            $state[$slug]['last_alert_horas'] = $horasDesde;
        }
        hb_log("[{$slug}] ALERTA enviado: {$assunto} (webhook=" . ($enviado ? 'ok' : 'noop/throttled') . ')', $quiet);
    }
    $alertados++;
}

if (!$dryRun) {
    @file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

hb_log(sprintf('saudaveis=%d alertados=%d (limite=%dh, rate=%dh)', $saudaveis, $alertados, $maxHoras, $rateHoras), $quiet);
exit(0);
