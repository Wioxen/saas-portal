<?php
/**
 * db_migrate — runner idempotente de migrations SQL.
 *
 * Lê migrations/*.sql em ordem alfabética. Pra cada arquivo:
 *   1. Extrai version (3 dígitos no nome — ex: 001_initial.sql → '001')
 *   2. Verifica se já está em schema_migrations
 *   3. Se não, executa o SQL inteiro em transaction
 *   4. Insere em schema_migrations
 *
 * Idempotente: re-rodar é seguro (só aplica novas migrations).
 *
 * Uso:
 *   php scripts/db_migrate.php                # aplica todas pendentes
 *   php scripts/db_migrate.php --status       # lista status sem aplicar
 *   php scripts/db_migrate.php --reset-test   # APAGA TUDO (só teste, exige flag)
 *
 * Pré-requisito: .env com DB_HOST, DB_USER, DB_PASS, DB_NAME, e DB já existente
 *   (CREATE DATABASE clonais_saas é manual — proteção contra erro de ops).
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/DbConnection.php';

$status = false;
$resetTest = false;
foreach ($argv as $a) {
    if ($a === '--status')      $status = true;
    elseif ($a === '--reset-test') $resetTest = true;
}

try {
    $pdo = DbConnection::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "[db_migrate] erro de conexão: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Confira .env: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS\n");
    fwrite(STDERR, "E que o DB existe (CREATE DATABASE deve ser manual)\n");
    exit(1);
}

// Garante tabela de controle (até a 001 cria, mas precisamos pra checar)
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(20) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($resetTest) {
    if ((string)getenv('DB_NAME') === 'clonais_saas') {
        fwrite(STDERR, "[db_migrate] --reset-test BLOQUEADO em DB_NAME='clonais_saas' (parece produção). Use DB_NAME diferente pra testes.\n");
        exit(2);
    }
    echo "[db_migrate] RESET TEST: dropping all tables...\n";
    $pdo->exec("DROP TABLE IF EXISTS trends, post_performance, click_log_summary, click_sync_state, schema_migrations");
    $pdo->exec("CREATE TABLE schema_migrations (version VARCHAR(20) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$migDir = $ROOT . '/migrations';
$files = @glob($migDir . '/*.sql');
if (!$files) {
    fwrite(STDERR, "[db_migrate] nenhuma migration encontrada em {$migDir}\n");
    exit(1);
}
sort($files);

// Versions já aplicadas
$jaAplicadas = [];
$st = $pdo->query("SELECT version FROM schema_migrations");
foreach ($st as $r) $jaAplicadas[$r['version']] = true;

if ($status) {
    echo "Migrations:\n";
    foreach ($files as $f) {
        $name = basename($f);
        $v = preg_match('/^(\d+)_/', $name, $m) ? $m[1] : '???';
        $marca = isset($jaAplicadas[$v]) ? '[X]' : '[ ]';
        echo "  {$marca} {$name}\n";
    }
    exit(0);
}

function split_sql(string $sql): array {
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    return array_filter(explode(';', $sql), fn($s) => trim($s) !== '');
}

$aplicadasAgora = 0;
foreach ($files as $f) {
    $name = basename($f);
    if (!preg_match('/^(\d+)_/', $name, $m)) {
        echo "[skip] {$name} (sem prefixo numérico)\n";
        continue;
    }
    $version = $m[1];
    if (isset($jaAplicadas[$version])) {
        echo "[ok ] {$name} já aplicada\n";
        continue;
    }
    $sql = @file_get_contents($f);
    if ($sql === false) {
        fwrite(STDERR, "[err] não leu {$name}\n");
        exit(3);
    }

    echo "[run] {$name} ... ";
    try {
        $pdo->beginTransaction();
        // Split por ; em statements (cuidado: split simples — não aceita ; em strings/triggers complexos)
        $statements = split_sql($sql);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || strpos($stmt, '--') === 0) continue;
            $pdo->exec($stmt);
        }
        $pdo->commit();
        echo "ok\n";
        $aplicadasAgora++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "FALHOU: " . $e->getMessage() . "\n");
        exit(4);
    }
}

echo "\nTotal aplicadas nesta execução: {$aplicadasAgora}\n";
exit(0);
