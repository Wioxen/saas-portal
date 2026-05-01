<?php
/**
 * migrar_json_para_db — lê data/discover_trends.json e importa pra MySQL trends.
 *
 * Idempotente: usa upsert (site, termo) — re-rodar é seguro.
 * Performance: bulk insert em transaction (1000× mais rápido que insert individual).
 *
 * Pré-requisito: db_migrate.php aplicado (tabela trends existe).
 *
 * Uso:
 *   php scripts/migrar_json_para_db.php                # migra principal
 *   php scripts/migrar_json_para_db.php --include-archive  # também migra discover_trends_archive/*.json
 *   php scripts/migrar_json_para_db.php --dry-run
 *
 * Após migração:
 *   1. .env: DB_DRIVER=mysql
 *   2. Smokes 9/9 (pra confirmar API igual)
 *   3. Pipeline produção
 *
 * Rollback: troca DB_DRIVER=json no .env. JSON files ficam intactos no disco.
 */

set_time_limit(0);
ini_set('memory_limit', '1G');
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/JsonStore.php';
require_once $ROOT . '/lib/DbConnection.php';
require_once $ROOT . '/lib/DiscoverDbMysql.php';

$incArchive = false;
$dryRun = false;
foreach ($argv as $a) {
    if ($a === '--include-archive') $incArchive = true;
    if ($a === '--dry-run')         $dryRun = true;
}

$mainPath = $ROOT . '/data/discover_trends.json';
if (!is_file($mainPath)) {
    fwrite(STDERR, "[migrar] {$mainPath} não existe — nada a migrar\n");
    exit(0);
}

echo "[migrar] lendo {$mainPath}\n";
$data = JsonStore::read($mainPath, ['records' => []]);
$records = $data['records'] ?? [];
echo "[migrar] " . count($records) . " records no principal\n";

if ($incArchive) {
    $archDir = $ROOT . '/data/discover_trends_archive';
    if (is_dir($archDir)) {
        foreach (glob($archDir . '/*.json') as $f) {
            $arch = JsonStore::read($f, ['records' => []]);
            $records = array_merge($records, $arch['records'] ?? []);
            echo "[migrar] +{count} de " . basename($f) . "\n";
        }
    }
    echo "[migrar] " . count($records) . " records totais (com archive)\n";
}

if (empty($records)) { echo "[migrar] nada a fazer\n"; exit(0); }

if ($dryRun) {
    echo "[migrar] dry-run — primeiros 3 records:\n";
    foreach (array_slice($records, 0, 3) as $r) {
        echo "  - " . ($r['site'] ?? '?') . ' :: ' . substr((string)($r['termo'] ?? '?'), 0, 60) . "\n";
    }
    exit(0);
}

// Conecta
try {
    $pdo = DbConnection::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "[migrar] falha conexão: " . $e->getMessage() . "\n");
    exit(1);
}

// Garante migrations aplicadas
$st = $pdo->query("SHOW TABLES LIKE 'trends'");
if ($st->rowCount() === 0) {
    fwrite(STDERR, "[migrar] tabela 'trends' não existe — rode primeiro `php scripts/db_migrate.php`\n");
    exit(1);
}

$mysql = new DiscoverDbMysql();

$sucessos = 0;
$falhas = 0;
$bytesAntes = is_file($mainPath) ? @filesize($mainPath) : 0;

// Bulk em transaction batches de 200 (compromise entre lock duration e overhead)
$batchSize = 200;
$batches = array_chunk($records, $batchSize);

foreach ($batches as $idx => $batch) {
    try {
        DbConnection::tx(function () use ($mysql, $batch, &$sucessos, &$falhas) {
            foreach ($batch as $r) {
                try {
                    $mysql->upsert($r);
                    $sucessos++;
                } catch (Throwable $e) {
                    $falhas++;
                    error_log("[migrar] falha record (" . ($r['site'] ?? '?') . " :: " . substr((string)($r['termo'] ?? ''), 0, 50) . "): " . $e->getMessage());
                }
            }
        });
    } catch (Throwable $e) {
        fwrite(STDERR, "[migrar] batch {$idx} rollback: " . $e->getMessage() . "\n");
    }
    echo "[migrar] batch " . ($idx + 1) . "/" . count($batches) . " · ok=" . $sucessos . " · falhas=" . $falhas . "\n";
}

// Confere total
$totalDB = (int)$pdo->query("SELECT COUNT(*) FROM trends")->fetchColumn();
echo "\n[migrar] resumo:\n";
echo "  records no JSON:     " . count($records) . "\n";
echo "  inseridos/atualizado: {$sucessos}\n";
echo "  falhas:              {$falhas}\n";
echo "  total na tabela:     {$totalDB}\n";
echo "\n[migrar] próximos passos:\n";
echo "  1. Edite .env: DB_DRIVER=mysql\n";
echo "  2. Rode: scripts\\check_pre_deploy.bat (smokes 9/9 — drivers ainda usam JSON, ok)\n";
echo "  3. Rode: php scripts/_smoke_db_mysql.php (testa MySQL driver)\n";
echo "  4. Pipeline em produção\n";

exit($falhas > 0 ? 1 : 0);
