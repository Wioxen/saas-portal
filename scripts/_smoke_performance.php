<?php
/**
 * Smoke test da Frente B (B1 AutoRefresh discover + B2 PostPerformanceLog).
 *
 * GSC API requer credenciais reais (e a internet). Aqui mockamos os clients via
 * subclasse anônima pra rodar offline.
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/PostPerformanceLog.php';
require_once $rootDir . '/lib/AutoRefresh.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// ─────────────────────────────────────────────
echo "\n=== TESTE 1: AutoRefresh default tipo='discover' ===\n";
$reflect = new ReflectionMethod(AutoRefresh::class, 'detectarPostsEmQueda');
$params = $reflect->getParameters();
$tipoParam = null;
foreach ($params as $p) { if ($p->getName() === 'tipo') { $tipoParam = $p; break; } }
check("AutoRefresh::detectarPostsEmQueda tem param 'tipo'", $tipoParam !== null);
check("default de 'tipo' = 'discover'",
    $tipoParam !== null && $tipoParam->getDefaultValue() === 'discover');

// Verifica script auto_refresh_posts.php
$scriptContent = file_get_contents($rootDir . '/scripts/auto_refresh_posts.php');
check("script default \$tipo = 'discover'",
    strpos($scriptContent, "\$tipo       = 'discover';") !== false ||
    strpos($scriptContent, "\$tipo = 'discover'") !== false);

// ─────────────────────────────────────────────
echo "\n=== TESTE 2: PostPerformanceLog snapshot (mock GSC) ===\n";

// Mock DB
$mockDb = new class {
    public function all(array $filtros): array {
        return [
            ['id' => 100, 'post_id' => 1234, 'site' => 'cursosenac',
             'status' => 'publicado', 'url_post' => 'https://cursosenacgratuito.com.br/teste-1',
             'publicado_em' => date('Y-m-d', strtotime('-2 days'))],
            ['id' => 101, 'post_id' => 1235, 'site' => 'cursosenac',
             'status' => 'publicado', 'url_post' => 'https://cursosenacgratuito.com.br/teste-2',
             'publicado_em' => date('Y-m-d', strtotime('-5 days'))],
            ['id' => 102, 'post_id' => 1236, 'site' => 'cursosenac',
             'status' => 'publicado', 'url_post' => 'https://cursosenacgratuito.com.br/teste-3',
             'publicado_em' => date('Y-m-d', strtotime('-50 days'))], // fora da janela 30d
        ];
    }
};

// Mock GSC — retorna rows fake por surface
$mockGsc = new class {
    public function consultarPerformance(string $url, string $ini, string $fim, array $opts): array {
        $tipo = $opts['tipo'] ?? 'web';
        $rows = [];
        if ($tipo === 'discover') {
            $rows[] = ['keys' => ['https://cursosenacgratuito.com.br/teste-1'],
                       'clicks' => 250, 'impressions' => 7500, 'ctr' => 0.0333, 'position' => 0];
            $rows[] = ['keys' => ['https://cursosenacgratuito.com.br/teste-2'],
                       'clicks' => 5, 'impressions' => 200, 'ctr' => 0.025, 'position' => 0];
        } elseif ($tipo === 'web') {
            $rows[] = ['keys' => ['https://cursosenacgratuito.com.br/teste-1'],
                       'clicks' => 30, 'impressions' => 500, 'ctr' => 0.06, 'position' => 4.2];
        }
        // googleNews vazio (caso comum)
        return ['rows' => $rows, 'totals' => [], 'site' => $url, 'periodo' => [], 'tipo' => $tipo];
    }
};

// Snapshot em diretório isolado
$tmpDir = sys_get_temp_dir() . '/perf_log_' . uniqid();
$log = new PostPerformanceLog($tmpDir);
$diaAlvo = date('Y-m-d', strtotime('-3 days'));

$cfgSite = ['wp_url' => 'https://cursosenacgratuito.com.br'];
$res = $log->snapshot('cursosenac', $cfgSite, $mockDb, $mockGsc, [
    'janela_d' => 30, 'max_posts' => 100, 'dia_alvo' => $diaAlvo,
]);

check("snapshot retorna ok=true", !empty($res['ok']), 'res=' . json_encode($res));
check("snapshot processou 2 posts (3º fora da janela 30d)",
    ($res['posts_processados'] ?? 0) === 2, 'p=' . ($res['posts_processados'] ?? '?'));
check("entries logadas = 2 posts × 3 surfaces = 6",
    ($res['entries_logadas'] ?? 0) === 6, 'e=' . ($res['entries_logadas'] ?? '?'));
check("log_file existe", isset($res['log_file']) && is_file($res['log_file']));

// ─────────────────────────────────────────────
echo "\n=== TESTE 3: PostPerformanceLog::lerLog + filtros ===\n";

$mes = substr($diaAlvo, 0, 7);
$entries = PostPerformanceLog::lerLog($mes, [], $tmpDir);
check("lerLog sem filtro retorna 6 entries", count($entries) === 6, 'count=' . count($entries));

$entriesDiscover = PostPerformanceLog::lerLog($mes, ['surface' => 'discover'], $tmpDir);
check("lerLog filtro surface=discover: 2 entries", count($entriesDiscover) === 2);

$entriesPost1 = PostPerformanceLog::lerLog($mes, ['post_id' => 1234], $tmpDir);
check("lerLog filtro post_id=1234: 3 entries (3 surfaces)", count($entriesPost1) === 3);

// Validação de campos
foreach ($entries as $e) {
    foreach (['ts', 'post_id', 'trend_id', 'site', 'url', 'surface', 'clicks', 'impressions', 'ctr', 'day_offset'] as $campo) {
        if (!array_key_exists($campo, $e)) {
            check("entry tem campo '{$campo}'", false, 'entry=' . json_encode($e));
            break 2;
        }
    }
}
check("todas entries têm campos esperados", true);

// ─────────────────────────────────────────────
echo "\n=== TESTE 4: agregarPorPost ===\n";
$agg = PostPerformanceLog::agregarPorPost($entries);
check("agregação produz 6 chaves (2 posts × 3 surfaces)", count($agg) === 6, 'agg=' . count($agg));

// Post 1234 + discover deve ter 250 clicks
$key = '1234|discover';
check("agg[{$key}] tem clicks_total=250",
    isset($agg[$key]) && $agg[$key]['clicks_total'] === 250,
    'clicks=' . ($agg[$key]['clicks_total'] ?? '?'));
check("agg[{$key}] tem impressions_total=7500",
    isset($agg[$key]) && $agg[$key]['impressions_total'] === 7500);
check("agg tem campo url",
    isset($agg[$key]) && $agg[$key]['url'] === 'https://cursosenacgratuito.com.br/teste-1');

// ─────────────────────────────────────────────
echo "\n=== TESTE 5: snapshot é append-only (idempotência via re-run) ===\n";
$res2 = $log->snapshot('cursosenac', $cfgSite, $mockDb, $mockGsc, [
    'janela_d' => 30, 'dia_alvo' => $diaAlvo,
]);
check("re-snapshot OK", !empty($res2['ok']));
$entriesDepois = PostPerformanceLog::lerLog($mes, [], $tmpDir);
check("após re-snapshot: 12 entries (6 + 6 — append, sem dedup ainda)",
    count($entriesDepois) === 12, 'depois=' . count($entriesDepois));

// ─────────────────────────────────────────────
echo "\n=== TESTE 6: relatorio_performance.php parseia JSONL ===\n";
// Cria arquivo de teste em data/post_performance/{YYYY-MM}.jsonl temporário
// Pra rodar o script real, copio entries do tmpDir pra data/post_performance/
$realDir = $rootDir . '/data/post_performance';
@mkdir($realDir, 0777, true);
$realFile = $realDir . '/' . $mes . '.jsonl';
$backupReal = is_file($realFile) ? @file_get_contents($realFile) : null;
@copy($tmpDir . '/' . $mes . '.jsonl', $realFile);

$cmd = '"C:\xampp\php\php.exe" "' . $rootDir . '/scripts/relatorio_performance.php" --site=cursosenac --janela=7 --json --quiet';
exec($cmd, $out, $rc);
$reportJson = json_decode(implode("\n", $out), true);
check("relatorio retorna JSON válido (rc={$rc})", is_array($reportJson),
    'first=' . substr(implode(' ', $out), 0, 200));
check("report tem totais", isset($reportJson['totais']));
check("report tem top_viralizou_discover", isset($reportJson['top_viralizou_discover']));
check("report tem top_sem_tracao", isset($reportJson['top_sem_tracao']));
check("report tem medias_por_site", isset($reportJson['medias_por_site']));

// Restore arquivo real
if ($backupReal !== null) @file_put_contents($realFile, $backupReal);
else @unlink($realFile);

// Cleanup tmpDir
foreach (glob($tmpDir . '/*') as $f) @unlink($f);
@rmdir($tmpDir);

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[PERFORMANCE B1+B2] OK\n" : "\n[PERFORMANCE B1+B2] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
