<?php
/**
 * scripts/pingo.php — runner CLI do DiscoverPingo.
 *
 * Uso:
 *   php scripts/pingo.php --site=comocomprar                  # ciclo completo, todas fontes ativas
 *   php scripts/pingo.php --site=comocomprar --dry-run        # simula, não salva em DB nem state
 *   php scripts/pingo.php --site=comocomprar --fonte=4        # roda só fonte com id=4
 *   php scripts/pingo.php --site=comocomprar --force          # ignora intervalo_min
 *   php scripts/pingo.php --site=comocomprar --verbose        # log verboso de erros
 *
 * Exit codes:
 *   0 = rodou sem erro crítico (pode ter erros de fonte individual)
 *   1 = falha no pingo inteiro (config, DB, etc)
 *
 * Cron Linux:  *\/10 * * * * /usr/bin/php /path/to/scripts/pingo.php --site=comocomprar >> /path/to/logs/pingo.log 2>&1
 * Task Scheduler Windows:  trigger a cada 10 min executando este PHP com --site=SLUG
 */

$siteArg = '';
$dryRun  = false;
$fonteId = null;
$force   = false;
$verbose = false;

foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m)) $siteArg = $m[1];
    if (preg_match('/^--fonte=(\d+)$/', $a, $m)) $fonteId = (int)$m[1];
    if ($a === '--dry-run') $dryRun = true;
    if ($a === '--force')   $force  = true;
    if ($a === '--verbose') $verbose = true;
    if ($a === '--help' || $a === '-h') {
        echo file_get_contents(__FILE__, false, null, 0, 1200);
        exit(0);
    }
}

if ($siteArg === '') {
    fwrite(STDERR, "Uso: php scripts/pingo.php --site=SLUG [--dry-run] [--fonte=N] [--force] [--verbose]\n");
    fwrite(STDERR, "Veja --help para detalhes.\n");
    exit(1);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';

// Kill switch: pipeline pausado via .env. Pingo segue rodando coleta? Não — pausa total.
// Se quiser coletar mesmo pausado, basta desativar PIPELINE_PAUSED.
require_once __DIR__ . '/../lib/KillSwitch.php';
if (KillSwitch::ativo()) {
    fwrite(STDERR, "[skip] PIPELINE_PAUSED=1 — " . KillSwitch::motivo() . "\n");
    exit(0);
}

$sites = sitesDisponiveis();
if (!isset($sites[$siteArg])) {
    fwrite(STDERR, "Site '{$siteArg}' não existe. Disponíveis: " . implode(', ', array_keys($sites)) . "\n");
    exit(1);
}
aplicarSite($cfg, $sites, $siteArg);

require_once __DIR__ . '/../lib/CronLock.php';
require_once __DIR__ . '/../lib/DiscoverDb.php';
require_once __DIR__ . '/../lib/DiscoverPingo.php';

// Lock POR SITE — cron de sites diferentes pode rodar paralelo, mas não
// 2x do mesmo site (race em pingo_state.json + duplicação de upserts).
$pingoLock = new CronLock('pingo_' . preg_replace('/[^a-z0-9_-]/i', '', $siteArg));
if (!$pingoLock->aquirir()) {
    if (!$dryRun) fwrite(STDERR, "[skip] outro pingo --site={$siteArg} já rodando — saindo.\n");
    exit(0);
}

$db = new DiscoverDb();
$pingo = new DiscoverPingo($cfg, $db);

$t0 = microtime(true);
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  DiscoverPingo · site={$siteArg}" . ($dryRun ? " · \033[36mDRY-RUN\033[0m" : " · \033[33mAPPLY\033[0m") . "\n";
echo "  Iniciado: " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

$relatorio = $pingo->rodar([
    'dry_run'  => $dryRun,
    'fonte_id' => $fonteId,
    'force'    => $force,
    'verbose'  => $verbose,
]);

$tempo = round(microtime(true) - $t0, 1);

// Relatório por fonte
$fontesInfo = [];
foreach ($pingo->listarFontes() as $f) $fontesInfo[(int)$f['id']] = $f;

echo "── Relatório por fonte ───────────────────────────────────────────────────\n";
printf("%-4s %-38s %-9s %-8s %-8s %-8s %s\n", '#', 'nome', 'status', 'vistos', 'novos', 'salvos', 'detalhe');
echo str_repeat('─', 90) . "\n";

foreach ($relatorio['por_fonte'] as $fid => $rf) {
    $nome = $fontesInfo[$fid]['nome'] ?? "fonte #{$fid}";
    $nomeCurto = mb_strimwidth($nome, 0, 37, '…', 'UTF-8');
    if (!empty($rf['skipped'])) {
        printf("%-4d %-38s %-9s %s\n", $fid, $nomeCurto, "\033[90mSKIP\033[0m", $rf['motivo_skip']);
        continue;
    }
    if (!empty($rf['erro'])) {
        printf("%-4d %-38s %-9s %-8d %-8d %-8d \033[31m%s\033[0m\n",
            $fid, $nomeCurto, "\033[31mERRO\033[0m",
            $rf['vistos'] ?? 0, $rf['novos'] ?? 0, $rf['salvos'] ?? 0,
            mb_strimwidth($rf['erro'], 0, 40, '…'));
        continue;
    }
    $status = ($rf['salvos'] ?? 0) > 0 ? "\033[32mOK\033[0m" : "\033[90mvazio\033[0m";
    $detalhe = ($rf['salvos'] ?? 0) > 0 ? "ids: " . implode(',', array_slice($rf['trends_salvos_ids'] ?? [], 0, 5)) : '';
    printf("%-4d %-38s %-9s %-8d %-8d %-8d %s\n",
        $fid, $nomeCurto, $status,
        $rf['vistos'] ?? 0, $rf['novos'] ?? 0, $rf['salvos'] ?? 0, $detalhe);
}

echo str_repeat('─', 90) . "\n";
echo "\n── Resumo ────────────────────────────────────────────────────────────────\n";
printf("  Fontes processadas: %d · skipped: %d · erros: %d\n",
    $relatorio['fontes_processadas'],
    $relatorio['fontes_skipped'],
    count($relatorio['erros']));
printf("  Items: %d vistos · %d novos · %d salvos no DB\n",
    $relatorio['items_vistos'],
    $relatorio['items_novos'],
    $relatorio['items_salvos']);
printf("  Tempo total: %ss\n", $tempo);
if ($dryRun) {
    echo "\n  \033[36m⚠ DRY-RUN: nenhum dado foi gravado.\033[0m Rode sem --dry-run para persistir.\n";
}

if (!empty($relatorio['erros'])) {
    echo "\n── Erros ─────────────────────────────────────────────────────────────────\n";
    foreach ($relatorio['erros'] as $e) {
        echo "  fonte #{$e['fonte_id']}: {$e['erro']}\n";
    }
    exit(0); // Erros de fonte não são falha crítica
}

exit(0);
