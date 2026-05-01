<?php
/**
 * cache_eviction — cron diário de poda de caches descartáveis (A2 da Frente Resiliência).
 *
 * Sem isso: data/articles_cache, data/cache, etc crescem sem limite. Em volume real
 * (100+ posts/dia × 6 sites), passa de 1GB em semanas. Disco enche → 503 silencioso.
 *
 * Regras por diretório (idade em dias / tamanho em MB / count máximo):
 *   articles_cache       → idade 7d  ou 200 MB
 *   cache (genérico)     → idade 7d  ou 100 MB
 *   cache/amazon_*       → idade 2d  (TTL real é 24h, 2d permite buffer pra retry)
 *   search_console_cache → idade 14d ou 50 MB
 *   debug                → idade 3d  ou 50 MB
 *   progress             → idade 1d  (state UI antigo)
 *
 * Uso:
 *   php scripts/cache_eviction.php             → aplica
 *   php scripts/cache_eviction.php --dry-run   → só preview
 *   php scripts/cache_eviction.php --quiet     → sem stdout
 *
 * Cron sugerido (diário, 3:30am — antes do auto_refresh):
 *   30 3 * * * /usr/bin/php /var/www/clonais/scripts/cache_eviction.php --quiet >> /var/log/clonais/cache_eviction.log 2>&1
 *
 * Exit codes:
 *   0 = OK · 1 = erro fatal (lock falhou, etc)
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/CacheManager.php';

// Args
$dryRun = false;
$quiet  = false;
foreach ($argv as $a) {
    if ($a === '--dry-run') $dryRun = true;
    if ($a === '--quiet')   $quiet  = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo "[cache_eviction] " . $m . "\n"; }

// Lock anti-overlap
$lock = new CronLock('cache_eviction');
if (!$lock->aquirir()) {
    log_msg('outra instância rodando — saindo', $quiet);
    exit(0);
}

// Regras por diretório (relativos a data/)
$regras = [
    'articles_cache'                 => ['byAge' => 7,  'bySize' => 200, 'byCount' => 5000],
    'cache'                          => ['byAge' => 7,  'bySize' => 100, 'byCount' => 3000],
    'cache/amazon_bestsellers'       => ['byAge' => 2,  'bySize' => 30,  'byCount' => 200],
    'search_console_cache'           => ['byAge' => 14, 'bySize' => 50,  'byCount' => 1000],
    'debug'                          => ['byAge' => 3,  'bySize' => 50,  'byCount' => 500],
    'progress'                       => ['byAge' => 1,  'bySize' => 20,  'byCount' => 500],
];

$totalApagados = 0;
$totalBytesApagados = 0;
$relatorio = [];

foreach ($regras as $relPath => $regra) {
    $dir = $ROOT . '/data/' . $relPath;
    if (!is_dir($dir)) {
        log_msg("skip {$relPath} (diretório não existe)", $quiet);
        continue;
    }

    $statsAntes = CacheManager::stats($dir);
    $resultado = CacheManager::prune($dir, $regra, $dryRun, false);

    $apagados = (int)($resultado['arquivos_apagados'] ?? 0);
    $bytesApagados = (int)($resultado['bytes_apagados'] ?? 0);
    $totalApagados += $apagados;
    $totalBytesApagados += $bytesApagados;

    $linha = sprintf(
        "%s: %d arquivos / %.2f MB → apagados: %d (%.2f MB) [%s]",
        $relPath,
        $statsAntes['arquivos'] ?? 0,
        ($statsAntes['mb'] ?? 0),
        $apagados,
        $bytesApagados / 1024 / 1024,
        json_encode($resultado['motivos'] ?? [])
    );
    log_msg($linha, $quiet);
    $relatorio[$relPath] = $resultado;
}

$linhaResumo = sprintf(
    "TOTAL: %d arquivos apagados, %.2f MB liberados (dry_run=%s)",
    $totalApagados,
    $totalBytesApagados / 1024 / 1024,
    $dryRun ? 'sim' : 'não'
);
log_msg($linhaResumo, $quiet);

// Disparo de alerta se eviction não rodava há tempo (sintoma de cron quebrado em outro host)
// — só se WEBHOOK habilitado E eviction liberou >500 MB (= cache estava acumulando demais)
if ($totalBytesApagados > 500 * 1024 * 1024) {
    $hwPath = $ROOT . '/lib/HealthWebhook.php';
    if (is_file($hwPath)) {
        require_once $hwPath;
        HealthWebhook::aviso('Cache eviction liberou volume alto', [
            'mb_liberados' => round($totalBytesApagados / 1024 / 1024, 2),
            'arquivos'     => $totalApagados,
            'detalhe'      => 'cache estava acumulando — verificar se cron rodava'
        ]);
    }
}

$lock->liberar();
exit(0);
