<?php
/**
 * Smoke B5 ClusterKiller.
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/ClusterKiller.php';
require_once $rootDir . '/lib/PostPerformanceLog.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// Mock DB que retorna posts publicados com cluster_key
$mockDb = new class {
    public function all(array $f): array {
        return [
            // Cluster RUIM (5 posts, todos com clicks Discover < 10 e CTR baixo)
            ['post_id' => 100, 'site' => 'cursosenac', 'status' => 'publicado', 'cluster_detect' => ['key' => 'esportes']],
            ['post_id' => 101, 'site' => 'cursosenac', 'status' => 'publicado', 'cluster_detect' => ['key' => 'esportes']],
            ['post_id' => 102, 'site' => 'cursosenac', 'status' => 'publicado', 'cluster_detect' => ['key' => 'esportes']],
            ['post_id' => 103, 'site' => 'cursosenac', 'status' => 'publicado', 'cluster_detect' => ['key' => 'esportes']],
            ['post_id' => 104, 'site' => 'cursosenac', 'status' => 'publicado', 'cluster_detect' => ['key' => 'esportes']],
            // Cluster BOM (5 posts, com volume Discover bom)
            ['post_id' => 200, 'site' => 'cursosenac', 'status' => 'publicado', 'cluster_detect' => ['key' => 'noticias_info_critica']],
            ['post_id' => 201, 'site' => 'cursosenac', 'status' => 'publicado', 'cluster_detect' => ['key' => 'noticias_info_critica']],
            ['post_id' => 202, 'site' => 'cursosenac', 'status' => 'publicado', 'cluster_detect' => ['key' => 'noticias_info_critica']],
            ['post_id' => 203, 'site' => 'cursosenac', 'status' => 'publicado', 'cluster_detect' => ['key' => 'noticias_info_critica']],
            ['post_id' => 204, 'site' => 'cursosenac', 'status' => 'publicado', 'cluster_detect' => ['key' => 'noticias_info_critica']],
            // Cluster com poucos posts (<MIN_POSTS_CLUSTER) — não pausa mesmo com clicks=0
            ['post_id' => 300, 'site' => 'cursosenac', 'status' => 'publicado', 'cluster_detect' => ['key' => 'lifestyle_consumo']],
            ['post_id' => 301, 'site' => 'cursosenac', 'status' => 'publicado', 'cluster_detect' => ['key' => 'lifestyle_consumo']],
        ];
    }
};

// Cria fixtures de PostPerformanceLog em pasta temporária
$tmpDir = sys_get_temp_dir() . '/clusterkill_test_' . uniqid();
@mkdir($tmpDir, 0777, true);
$mes = date('Y-m');
$jsonl = $tmpDir . '/' . $mes . '.jsonl';
$tsHoje = date('Y-m-d');
$entries = [];

// Cluster ruim (esportes): 5 posts, TODOS com clicks=0 e impressions baixas
foreach ([100, 101, 102, 103, 104] as $pid) {
    $entries[] = ['ts' => $tsHoje, 'post_id' => $pid, 'site' => 'cursosenac', 'surface' => 'discover',
                  'clicks' => 0, 'impressions' => 50, 'ctr' => 0, 'position' => 0,
                  'day_offset' => 5, 'url' => "https://x/{$pid}"];
}
// Cluster bom: clicks altos
foreach ([200, 201, 202, 203, 204] as $pid) {
    $entries[] = ['ts' => $tsHoje, 'post_id' => $pid, 'site' => 'cursosenac', 'surface' => 'discover',
                  'clicks' => 200, 'impressions' => 5000, 'ctr' => 0.04, 'position' => 0,
                  'day_offset' => 5, 'url' => "https://x/{$pid}"];
}
// Cluster com poucos posts (lifestyle, 2 posts) — não atinge MIN_POSTS, não deve pausar
foreach ([300, 301] as $pid) {
    $entries[] = ['ts' => $tsHoje, 'post_id' => $pid, 'site' => 'cursosenac', 'surface' => 'discover',
                  'clicks' => 0, 'impressions' => 10, 'ctr' => 0, 'position' => 0,
                  'day_offset' => 5, 'url' => "https://x/{$pid}"];
}
file_put_contents($jsonl, implode("\n",
    array_map(fn($e) => json_encode($e, JSON_UNESCAPED_UNICODE), $entries)) . "\n");

$pausePath = $tmpDir . '/cluster_paused.json';
$killer = new ClusterKiller($pausePath);

// ─────────────────────────────────────────────
echo "\n=== TESTE 1: analisar() agrega corretamente ===\n";
$r = $killer->analisar($mockDb, ['log_base_dir' => $tmpDir, 'janela_dias' => 30]);

check("retorna analise como array",
    is_array($r['analise']) && !empty($r['analise']));
check("total_clusters = 3 (esportes + noticias + lifestyle)",
    $r['total_clusters'] === 3, 'total=' . $r['total_clusters']);

// Encontrar cada cluster no resultado
$clusterMap = [];
foreach ($r['analise'] as $a) {
    $clusterMap[$a['cluster_key']] = $a;
}

check("cluster esportes: pausar=true",
    isset($clusterMap['esportes']) && $clusterMap['esportes']['pausar'] === true,
    'esportes=' . json_encode($clusterMap['esportes'] ?? null));
check("cluster esportes: posts=5",
    isset($clusterMap['esportes']) && $clusterMap['esportes']['posts'] === 5);
check("cluster esportes: clicks=0",
    isset($clusterMap['esportes']) && $clusterMap['esportes']['clicks'] === 0);
check("cluster esportes: razao mencionada",
    isset($clusterMap['esportes']) && !empty($clusterMap['esportes']['razao']));

check("cluster noticias_info_critica: pausar=false (perfomance boa)",
    isset($clusterMap['noticias_info_critica']) && $clusterMap['noticias_info_critica']['pausar'] === false);
check("cluster noticias: clicks=1000 (5×200)",
    isset($clusterMap['noticias_info_critica']) && $clusterMap['noticias_info_critica']['clicks'] === 1000);

check("cluster lifestyle (poucos posts): pausar=false (proteção MIN_POSTS)",
    isset($clusterMap['lifestyle_consumo']) && $clusterMap['lifestyle_consumo']['pausar'] === false);

check("total_pausados = 1 (só esportes)",
    $r['total_pausados'] === 1, 'pausados=' . $r['total_pausados']);

// ─────────────────────────────────────────────
echo "\n=== TESTE 2: aplicar() grava arquivo ===\n";
$apl = $killer->aplicar($r);
check("aplicar retorna pausados=1", $apl['pausados'] === 1);
check("arquivo cluster_paused.json criado", is_file($pausePath));

$conteudo = json_decode(file_get_contents($pausePath), true);
check("arquivo tem campo 'pausados'",
    isset($conteudo['pausados']) && is_array($conteudo['pausados']));
check("chave 'cursosenac|esportes' no arquivo",
    isset($conteudo['pausados']['cursosenac|esportes']));
check("entrada tem razao + clicks + ctr",
    isset($conteudo['pausados']['cursosenac|esportes']['razao'])
    && isset($conteudo['pausados']['cursosenac|esportes']['clicks']));

// ─────────────────────────────────────────────
echo "\n=== TESTE 3: estaPausado() retorna true/false correto ===\n";
check("estaPausado(cursosenac, esportes) = true",
    ClusterKiller::estaPausado('cursosenac', 'esportes', $pausePath));
check("estaPausado(cursosenac, noticias_info_critica) = false",
    !ClusterKiller::estaPausado('cursosenac', 'noticias_info_critica', $pausePath));
check("estaPausado(outro_site, esportes) = false (chave inclui site)",
    !ClusterKiller::estaPausado('comocomprar', 'esportes', $pausePath));

// ─────────────────────────────────────────────
echo "\n=== TESTE 4: PrePublishLint integra ClusterKiller ===\n";
require_once $rootDir . '/lib/PrePublishLint.php';

// Trend de cluster pausado → reject com motivo cluster_paused
$trendPausado = [
    'termo' => 'jogador X transferência rumor',
    'site'  => 'cursosenac',
    'cluster_detect' => ['key' => 'esportes', 'score' => 5, 'nome' => 'Esportes'],
];
$fontes = [['fonte' => ['content' => ['paragraphs' => [str_repeat('texto ', 200)]]]]];

// Hack: o PrePublishLint usa default path; nosso teste usa $pausePath custom
// Solução: copia nosso arquivo de teste pro path real (e backup do original)
$realPath = $rootDir . '/data/cluster_paused.json';
$backup = is_file($realPath) ? @file_get_contents($realPath) : null;
@copy($pausePath, $realPath);

// Limpa cache estático ao chamar com path diferente do anterior
// (estaPausado tem cache por path — limpa via reflection se preciso)

$lint = PrePublishLint::avaliar($trendPausado, $fontes, null, 50, [
    'empresa' => ['nome' => 'X'],
    '_site_slug' => 'cursosenac',
]);
check("lint rejeita trend de cluster pausado",
    !$lint['aprovado'] && in_array('cluster_paused', $lint['motivos']),
    'motivos=' . json_encode($lint['motivos']));
check("detalhes têm cluster_pausado preenchido",
    !empty($lint['detalhes']['cluster_pausado']));

// Restore arquivo original
if ($backup !== null) @file_put_contents($realPath, $backup);
else @unlink($realPath);

// Cleanup
foreach (glob($tmpDir . '/*') as $f) @unlink($f);
@rmdir($tmpDir);

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[CLUSTER KILLER B5] OK\n" : "\n[CLUSTER KILLER B5] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
