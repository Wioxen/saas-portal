<?php
/**
 * Smoke do driver DiscoverDbMysql.
 *
 * Estratégia: usa SQLite ':memory:' como backing pra rodar OFFLINE (sem servidor MySQL).
 * SQLite suporta o subset de SQL usado pelo driver. Em produção (MySQL real), comportamento
 * é idêntico — mesmas queries, mesmos índices semânticos.
 *
 * Pra rodar contra MySQL real: `DB_DRIVER=mysql` no .env + DB_NAME diferente de prod
 *   (ex: clonais_saas_test) + executar com flag `--mysql-real`.
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/DbConnection.php';
require_once $rootDir . '/lib/DiscoverDbMysql.php';

$mysqlReal = in_array('--mysql-real', $argv, true);

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// Setup: SQLite ':memory:' pra testar o driver SEM precisar de MySQL rodando
if ($mysqlReal) {
    DbConnection::reset();
    echo "[modo] MySQL REAL (lê .env)\n\n";
    try {
        $pdo = DbConnection::pdo();
    } catch (Throwable $e) {
        fwrite(STDERR, "Falha conectar MySQL: " . $e->getMessage() . "\n");
        exit(2);
    }
    // Limpa tabela trends pra teste limpo (CUIDADO: assume DB de teste)
    $pdo->exec("DELETE FROM trends");
} else {
    echo "[modo] SQLite ':memory:' (offline)\n\n";
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Schema adaptado pra SQLite (sem ENUM, AUTO_INCREMENT, ENGINE)
    $pdo->exec("
        CREATE TABLE trends (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site TEXT NOT NULL,
            termo TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'novo',
            score_discover REAL DEFAULT 0,
            data_detectada TEXT NOT NULL,
            ultimo_update TEXT NOT NULL,
            publicado_em TEXT,
            post_id INTEGER,
            url_post TEXT,
            titulo TEXT,
            cluster_key TEXT,
            origem TEXT,
            categoria TEXT,
            volume_busca INTEGER,
            volume_label TEXT,
            growth_pct REAL,
            intencao TEXT,
            angulo TEXT,
            ativo INTEGER NOT NULL DEFAULT 1,
            noticias_qtd INTEGER,
            pingo_link TEXT,
            payload TEXT
        )");
    $pdo->exec("CREATE UNIQUE INDEX uk_site_termo ON trends(site, termo)");
    $pdo->exec("CREATE INDEX idx_site_status ON trends(site, status)");
    DbConnection::setTestPdo($pdo);
}

$db = new DiscoverDbMysql();

// ─────────────────────────────────────────────
echo "=== TESTE 1: upsert insert ===\n";
$id1 = $db->upsert([
    'site' => 'cursosenac', 'termo' => 'enem 2026 isenção',
    'status' => 'aprovado', 'score_discover' => 8.5,
    'cluster_detect' => ['key' => 'noticias_info_critica', 'nome' => 'Notícias'],
    'relacionados' => ['inep', 'mec'],
]);
check("upsert retorna id > 0", $id1 > 0);

// ─────────────────────────────────────────────
echo "\n=== TESTE 2: get retorna record completo (com payload) ===\n";
$r = $db->get($id1);
check("get retorna array", is_array($r));
check("campos dedicados preservados",
    ($r['site'] ?? '') === 'cursosenac' && ($r['termo'] ?? '') === 'enem 2026 isenção');
check("status preservado", ($r['status'] ?? '') === 'aprovado');
check("score_discover é float",
    isset($r['score_discover']) && is_float($r['score_discover']) && abs($r['score_discover'] - 8.5) < 0.01);
check("payload extraído (cluster_detect)",
    isset($r['cluster_detect']['key']) && $r['cluster_detect']['key'] === 'noticias_info_critica');
check("payload extraído (relacionados)",
    isset($r['relacionados']) && is_array($r['relacionados']) && count($r['relacionados']) === 2);

// ─────────────────────────────────────────────
echo "\n=== TESTE 3: upsert update preserva data_detectada ===\n";
$detectadaOriginal = $r['data_detectada'];
sleep(1);
$id2 = $db->upsert([
    'site' => 'cursosenac', 'termo' => 'enem 2026 isenção',  // mesma chave
    'score_discover' => 9.0, 'angulo' => 'urgência',
]);
check("upsert mesma chave retorna mesmo id", $id2 === $id1);
$r2 = $db->get($id1);
check("update NÃO mudou data_detectada", $r2['data_detectada'] === $detectadaOriginal);
check("update mudou score_discover", abs($r2['score_discover'] - 9.0) < 0.01);
check("update mudou ultimo_update", $r2['ultimo_update'] !== $r['ultimo_update']);
check("update setou angulo (campo dedicado)", ($r2['angulo'] ?? '') === 'urgência');

// ─────────────────────────────────────────────
echo "\n=== TESTE 4: all com filtros ===\n";
$db->upsert(['site' => 'guiadoscursos', 'termo' => 'fies', 'status' => 'novo', 'score_discover' => 5.0]);
$db->upsert(['site' => 'cursosenac', 'termo' => 'sisu', 'status' => 'novo', 'score_discover' => 3.0]);

$todos = $db->all();
check("all sem filtro: 3 records", count($todos) === 3);

$cursosenac = $db->all(['site' => 'cursosenac']);
check("all site=cursosenac: 2 records", count($cursosenac) === 2);

$aprovados = $db->all(['status' => 'aprovado']);
check("all status=aprovado: 1 record", count($aprovados) === 1);

$alto = $db->all(['score_min' => 8.0]);
check("all score_min=8: 1 record", count($alto) === 1);

// ─────────────────────────────────────────────
echo "\n=== TESTE 5: count ===\n";
check("count() = 3", $db->count() === 3);
check("count site=cursosenac = 2", $db->count(['site' => 'cursosenac']) === 2);

// ─────────────────────────────────────────────
echo "\n=== TESTE 6: updateStatus + extras ===\n";
$ok2 = $db->updateStatus($id1, 'publicado', ['post_id' => 1234, 'url_post' => 'https://x.com/y']);
check("updateStatus retorna true", $ok2 === true);
$r3 = $db->get($id1);
check("status atualizado", $r3['status'] === 'publicado');
check("post_id (campo dedicado, int) atualizado", ($r3['post_id'] ?? 0) === 1234);
check("url_post (campo dedicado) atualizado", ($r3['url_post'] ?? '') === 'https://x.com/y');

// ─────────────────────────────────────────────
echo "\n=== TESTE 7: delete ===\n";
$id3 = $db->upsert(['site' => 'X', 'termo' => 'temp', 'status' => 'novo']);
check("delete retorna true", $db->delete($id3));
check("get após delete retorna null", $db->get($id3) === null);

// ─────────────────────────────────────────────
echo "\n=== TESTE 8: migrarSite (sem colisão) ===\n";
$db->upsert(['site' => 'origem_x', 'termo' => 'mover este', 'status' => 'novo']);
$db->upsert(['site' => 'origem_x', 'termo' => 'mover aquele', 'status' => 'novo']);
$res = $db->migrarSite('origem_x', 'destino_y');
check("migrar 2 records sem colisão", $res['movidos'] === 2 && $res['colisoes'] === 0);
check("após migrar: origem_x vazio", $db->count(['site' => 'origem_x']) === 0);
check("após migrar: destino_y tem 2", $db->count(['site' => 'destino_y']) === 2);

// ─────────────────────────────────────────────
echo "\n=== TESTE 9: upsertMany batch ===\n";
$ids = $db->upsertMany([
    ['site' => 'batch', 'termo' => 'b1', 'status' => 'novo'],
    ['site' => 'batch', 'termo' => 'b2', 'status' => 'novo'],
    ['site' => 'batch', 'termo' => 'b3', 'status' => 'aprovado'],
]);
check("upsertMany retorna 3 ids", count($ids) === 3);
check("upsertMany todos id > 0", count(array_filter($ids, fn($i) => $i > 0)) === 3);
check("upsertMany batch site count = 3", $db->count(['site' => 'batch']) === 3);

// ─────────────────────────────────────────────
echo "\n=== TESTE 10: truncate ===\n";
$db->truncate();
check("truncate: count = 0", $db->count() === 0);

// ─────────────────────────────────────────────
echo "\n=== TESTE 11: facade DiscoverDb com forceDriver='mysql' ===\n";
require_once $rootDir . '/lib/DiscoverDb.php';
$facade = new DiscoverDb(null, 60, 'mysql');
check("facade isMysql() = true", $facade->isMysql());

$idF = $facade->upsert(['site' => 'facade_test', 'termo' => 'via facade', 'status' => 'novo']);
check("facade upsert OK via mysql driver", $idF > 0);
$rF = $facade->get($idF);
check("facade get OK", is_array($rF) && $rF['termo'] === 'via facade');

// ─────────────────────────────────────────────
echo "\n=== TESTE 12: facade DiscoverDb sem env (default = json — não toca DB) ===\n";
DbConnection::reset();
// Simula ambiente sem DB_DRIVER no env
$tmpJson = sys_get_temp_dir() . '/test_json_' . uniqid() . '.json';
$facadeJson = new DiscoverDb($tmpJson, 60, 'json');
check("facade isMysql() = false em json", !$facadeJson->isMysql());
$idJ = $facadeJson->upsert(['site' => 'X', 'termo' => 'json mode']);
check("facade upsert via JSON driver OK", $idJ > 0);
@unlink($tmpJson);
foreach (glob($tmpJson . '.bak.*') as $f) @unlink($f);

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[DB MYSQL] OK\n" : "\n[DB MYSQL] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
