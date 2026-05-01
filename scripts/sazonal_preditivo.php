<?php
/**
 * sazonal_preditivo — antecipa picos sazonais (A1 da revisão pré-deploy).
 *
 * Diariamente, olha 7d à frente, expande eventos sazonais em termos pré-aprovados,
 * roteia pra sites cujo cluster bate. Score progressivo: ≤3d=15.0 → ≤14d=9.0.
 *
 * Tick_filas pega esses trends de score alto (vai pro Sonnet pelo Trend-Scoring Gate)
 * e gera ANTES do pico chegar. Quando Discover acelera no dia, post já está indexado.
 *
 * Uso:
 *   php scripts/sazonal_preditivo.php                 # roda
 *   php scripts/sazonal_preditivo.php --janela=14     # janela maior
 *   php scripts/sazonal_preditivo.php --dry-run       # só lista o que faria
 *
 * Cron sugerido (diário, 6:30am — após gsc_aprender e antes de tick_filas pegar):
 *   30 6 * * * /usr/bin/php /var/www/clonais/scripts/sazonal_preditivo.php --quiet
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/DiscoverPreditorSazonal.php';

$janela = DiscoverPreditorSazonal::JANELA_DIAS_DEFAULT;
$dryRun = false;
$quiet  = false;
foreach ($argv as $a) {
    if (preg_match('/^--janela=(\d+)$/', $a, $m)) $janela = (int)$m[1];
    elseif ($a === '--dry-run') $dryRun = true;
    elseif ($a === '--quiet')   $quiet  = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo "[sazonal_preditivo] {$m}\n"; }

$lock = new CronLock('sazonal_preditivo');
if (!$lock->aquirir()) { log_msg('outra instância rodando', $quiet); exit(1); }

$sites = require $ROOT . '/sites.php';
if (!is_array($sites)) { fwrite(STDERR, "sites.php não retornou array\n"); exit(1); }

$preditor = new DiscoverPreditorSazonal();
$res = $preditor->rodar($sites, $janela, $dryRun);

log_msg(sprintf(
    "eventos=%d · termos criados=%d · ja existiam=%d · sites: %s",
    $res['eventos'],
    $res['termos_criados'],
    $res['ja_existiam'],
    json_encode($res['sites_atingidos'])
), $quiet);

if ($dryRun && !empty($res['detalhes'])) {
    foreach ($res['detalhes'] as $d) {
        $score = $d['score'] ?? '?';
        $dias  = $d['dias_ate_pico'] ?? '?';
        log_msg(sprintf("  [%s] %s · score=%s · pico=%dd · '%s'",
            $d['site'] ?? '?', $d['evento'] ?? '?', $score, $dias, $d['termo'] ?? '?'), $quiet);
    }
}

// Webhook se criou MUITOS termos (sintoma de calendário denso, ou bug de loop)
if ($res['termos_criados'] > 50) {
    $hwPath = $ROOT . '/lib/HealthWebhook.php';
    if (is_file($hwPath)) {
        require_once $hwPath;
        HealthWebhook::aviso('sazonal_preditivo: alto volume de termos criados', [
            'termos'  => $res['termos_criados'],
            'eventos' => $res['eventos'],
        ]);
    }
}

$lock->liberar();
exit(0);
