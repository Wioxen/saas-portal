<?php
/**
 * Smoke C1 (Pretty Links click attribution).
 * Cobertura:
 *   1. AfiliadoLinkBuilder::comAttribution + ehPrettyLink + aplicarEmHtml
 *   2. ClickLog escrita/leitura JSONL + state incremental
 *   3. ClickLog::clicksPorPost (dedupe por dia × ip)
 *   4. Plugin cc-click-logger.php parseia (PHP -l)
 *   5. DiscoverPostProcess wire (post_id em meta vira ?p=X em <a href>)
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/AfiliadoLinkBuilder.php';
require_once $rootDir . '/lib/ClickLog.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// ─────────────────────────────────────────────
echo "\n=== TESTE 1: AfiliadoLinkBuilder::comAttribution ===\n";
check("URL simples → ?p=ID",
    AfiliadoLinkBuilder::comAttribution('https://site.com/go/x', 1234) === 'https://site.com/go/x?p=1234');

check("URL com query existente → &p=ID",
    AfiliadoLinkBuilder::comAttribution('https://site.com/go/x?ref=foo', 1234) === 'https://site.com/go/x?ref=foo&p=1234');

check("URL com fragment → preserva fragment",
    AfiliadoLinkBuilder::comAttribution('https://site.com/go/x#section', 1234) === 'https://site.com/go/x?p=1234#section');

check("idempotência: já tem ?p= → não duplica",
    AfiliadoLinkBuilder::comAttribution('https://site.com/go/x?p=999', 1234) === 'https://site.com/go/x?p=999');

check("idempotência: já tem &p= → não duplica",
    AfiliadoLinkBuilder::comAttribution('https://site.com/go/x?ref=a&p=999', 1234) === 'https://site.com/go/x?ref=a&p=999');

check("postId=0 → URL inalterada",
    AfiliadoLinkBuilder::comAttribution('https://site.com/go/x', 0) === 'https://site.com/go/x');

check("URL não-http → inalterada",
    AfiliadoLinkBuilder::comAttribution('mailto:x@y.com', 1234) === 'mailto:x@y.com');

// ─────────────────────────────────────────────
echo "\n=== TESTE 2: AfiliadoLinkBuilder::ehPrettyLink ===\n";
check("absoluta /go/x → true", AfiliadoLinkBuilder::ehPrettyLink('https://site.com/go/x'));
check("relativa /go/x → true",  AfiliadoLinkBuilder::ehPrettyLink('/go/x'));
check("relativa /go/x?p=1 → true", AfiliadoLinkBuilder::ehPrettyLink('/go/x?p=1'));
check("/categoria/x → false (não é Pretty Link)", !AfiliadoLinkBuilder::ehPrettyLink('/categoria/x'));
check("https://amazon.com → false", !AfiliadoLinkBuilder::ehPrettyLink('https://amazon.com'));
check("/ir/x com prefix='ir' → true", AfiliadoLinkBuilder::ehPrettyLink('/ir/x', ['ir']));

// ─────────────────────────────────────────────
echo "\n=== TESTE 3: AfiliadoLinkBuilder::aplicarEmHtml ===\n";
$html = '<p>Veja <a href="https://site.com/go/produto-1">esta oferta</a> e <a href="/go/produto-2?ref=test">outra</a>. Mas <a href="https://amazon.com">esse não</a> e <a href="/categoria/x">esse também não</a>.</p>';
$novoHtml = AfiliadoLinkBuilder::aplicarEmHtml($html, 5678);

check("aplicarEmHtml: link 1 ganhou ?p=5678",
    strpos($novoHtml, 'https://site.com/go/produto-1?p=5678') !== false,
    'novoHtml=' . substr($novoHtml, 0, 200));
check("aplicarEmHtml: link 2 ganhou &p=5678",
    strpos($novoHtml, '/go/produto-2?ref=test&p=5678') !== false);
check("aplicarEmHtml: amazon.com não modificado",
    strpos($novoHtml, 'href="https://amazon.com"') !== false);
check("aplicarEmHtml: /categoria/x não modificado",
    strpos($novoHtml, 'href="/categoria/x"') !== false);

// postId=0 → HTML inalterado
$inalt = AfiliadoLinkBuilder::aplicarEmHtml($html, 0);
check("postId=0 → HTML inalterado", $inalt === $html);

// ─────────────────────────────────────────────
echo "\n=== TESTE 4: ClickLog write/read/aggregate ===\n";
$tmpDir = sys_get_temp_dir() . '/click_test_' . uniqid();
$cl = new ClickLog($tmpDir);

// Mock: faz appendJsonl manualmente via reflection — simulando o que sincronizar() faria
$mes = date('Y-m');
$logFile = $tmpDir . '/' . $mes . '.jsonl';
@mkdir($tmpDir, 0777, true);
$now = time();
$entries = [
    ['id' => 1, 'slug' => 'go/p1', 'post_id' => 100, 'ts' => $now,        'ip_hash' => 'aaa', 'ua_hash' => 'b1', 'referer_hash' => null],
    ['id' => 2, 'slug' => 'go/p1', 'post_id' => 100, 'ts' => $now,        'ip_hash' => 'aaa', 'ua_hash' => 'b1', 'referer_hash' => null], // mesmo IP, mesmo dia → dedupe
    ['id' => 3, 'slug' => 'go/p1', 'post_id' => 100, 'ts' => $now,        'ip_hash' => 'bbb', 'ua_hash' => 'b2', 'referer_hash' => null],
    ['id' => 4, 'slug' => 'go/p2', 'post_id' => 200, 'ts' => $now,        'ip_hash' => 'ccc', 'ua_hash' => 'b3', 'referer_hash' => null],
    ['id' => 5, 'slug' => 'go/p1', 'post_id' => 100, 'ts' => $now - 86400 * 2, 'ip_hash' => 'aaa', 'ua_hash' => 'b1', 'referer_hash' => null], // 2d atrás
];

// Escreve via reflection do método privado
$rf = new ReflectionClass($cl);
$m = $rf->getMethod('appendJsonl');
$m->setAccessible(true);
$m->invoke($cl, $entries, 'cursosenac');

check("JSONL existe após appendJsonl", is_file($logFile) || is_file($tmpDir . '/' . date('Y-m', $now - 86400 * 2) . '.jsonl'));

// Lê todos os meses tocados (incluindo o de 2 dias atrás)
$entriesAtual = ClickLog::lerLog($mes, [], $tmpDir);
$mesAntigo = date('Y-m', $now - 86400 * 2);
if ($mesAntigo !== $mes) {
    $entriesAtual = array_merge($entriesAtual, ClickLog::lerLog($mesAntigo, [], $tmpDir));
}
check("lerLog total: 5 entries (todas as 5 do mock)",
    count($entriesAtual) === 5, 'count=' . count($entriesAtual));

// Filtros
$entriesP100 = ClickLog::lerLog($mes, ['post_id' => 100], $tmpDir);
check("filtro post_id=100", count($entriesP100) >= 2);

$entriesSite = ClickLog::lerLog($mes, ['site' => 'cursosenac'], $tmpDir);
check("filtro site=cursosenac retorna entries", count($entriesSite) >= 3);

$entriesNoSite = ClickLog::lerLog($mes, ['site' => 'inexistente'], $tmpDir);
check("filtro site inexistente → 0 entries", count($entriesNoSite) === 0);

// clicksPorPost com dedupe (mesmo ip+dia conta 1)
// Post 100 tem 4 events: 3 hoje (aaa, aaa-dup, bbb) + 1 dois dias atrás (aaa)
// Dedupe por (pid|ip|dia): hoje (aaa, bbb) = 2 únicos + 2d-atrás (aaa) = 1 → total 3
$cont = ClickLog::clicksPorPost($entriesAtual, true);
check("clicksPorPost dedupe: post 100 = 3 (2 IPs hoje + 1 IP 2d atrás)",
    isset($cont[100]) && $cont[100] === 3,
    'cont=' . json_encode($cont));
check("clicksPorPost dedupe: post 200 = 1", isset($cont[200]) && $cont[200] === 1);

// Sem dedupe — 4 events do post 100, 1 do post 200
$contRaw = ClickLog::clicksPorPost($entriesAtual, false);
check("clicksPorPost sem dedupe: post 100 = 4",
    isset($contRaw[100]) && $contRaw[100] === 4,
    'contRaw=' . json_encode($contRaw));

// topPosts
$top = ClickLog::topPosts($entriesAtual, 5);
check("topPosts retorna ordenado por clicks",
    !empty($top) && $top[0]['post_id'] === 100,
    'top=' . json_encode($top));

// Cleanup
foreach (glob($tmpDir . '/*') as $f) @unlink($f);
@rmdir($tmpDir);

// ─────────────────────────────────────────────
echo "\n=== TESTE 5: Plugin cc-click-logger.php sintaxe ===\n";
$cmd = '"C:\xampp\php\php.exe" -l "' . $rootDir . '/plugin/cc-click-logger.php"';
exec($cmd . ' 2>&1', $output, $rc);
check("plugin sem erro de sintaxe (php -l)", $rc === 0, implode(' ', $output));

// ─────────────────────────────────────────────
echo "\n=== TESTE 6: DiscoverPostProcess wire (post_id → ?p=) ===\n";
require_once $rootDir . '/lib/DiscoverPostProcess.php';

$htmlIn = '<p>Compre <a href="https://site.com/go/produto">aqui</a> agora.</p>';
$htmlOut = DiscoverPostProcess::processar($htmlIn, [
    'titulo' => 'Teste',
    'url'    => 'https://site.com/post/x',
    'post_id'=> 9999,
], [], ['pretty_links_prefix' => 'go']);

check("DiscoverPostProcess injeta ?p=9999 quando post_id em meta",
    strpos($htmlOut, 'p=9999') !== false,
    'out=' . substr($htmlOut, 0, 200));

// Sem post_id, nada muda
$htmlOut2 = DiscoverPostProcess::processar($htmlIn, [
    'titulo' => 'Teste',
    'url'    => 'https://site.com/post/x',
], [], []);
check("DiscoverPostProcess sem post_id → URL não recebe ?p=",
    strpos($htmlOut2, 'p=') === false || strpos($htmlOut2, '/go/produto?p=') === false);

// ─────────────────────────────────────────────
echo "\n=== TESTE 7: relatorio_performance integra clicks ===\n";
// Cria fixtures temporárias em data/post_performance e data/click_log
$mes = date('Y-m');
$realPerfDir  = $rootDir . '/data/post_performance';
$realClickDir = $rootDir . '/data/click_log';
@mkdir($realPerfDir, 0777, true);
@mkdir($realClickDir, 0777, true);
$perfFile  = $realPerfDir  . '/' . $mes . '.jsonl';
$clickFile = $realClickDir . '/' . $mes . '.jsonl';

$backupPerf  = is_file($perfFile)  ? @file_get_contents($perfFile)  : null;
$backupClick = is_file($clickFile) ? @file_get_contents($clickFile) : null;

$tsHoje = time();
$tsHojeYmd = date('Y-m-d', $tsHoje);

// 2 posts com performance GSC (Discover) — post 7777 viraliza, post 8888 médio
$perfFixtures = [
    ['ts' => $tsHojeYmd, 'post_id' => 7777, 'trend_id' => 1, 'site' => 'cursosenac',
     'url' => 'https://cursosenacgratuito.com.br/post-viral',
     'published_at' => $tsHojeYmd, 'day_offset' => 1, 'surface' => 'discover',
     'clicks' => 500, 'impressions' => 12000, 'ctr' => 0.0417, 'position' => 0],
    ['ts' => $tsHojeYmd, 'post_id' => 8888, 'trend_id' => 2, 'site' => 'cursosenac',
     'url' => 'https://cursosenacgratuito.com.br/post-medio',
     'published_at' => $tsHojeYmd, 'day_offset' => 1, 'surface' => 'discover',
     'clicks' => 50, 'impressions' => 1500, 'ctr' => 0.0333, 'position' => 0],
];
file_put_contents($perfFile, implode("\n",
    array_map(fn($e) => json_encode($e, JSON_UNESCAPED_UNICODE), $perfFixtures)) . "\n");

// 3 clicks afiliado pra post 7777, 1 pra post 8888 (dedupe ip×dia: contam tudo se IPs diferentes)
$clickFixtures = [
    ['ts' => $tsHoje, 'ts_iso' => date('c', $tsHoje), 'site' => 'cursosenac',
     'slug' => 'go/produto', 'post_id' => 7777, 'ip_hash' => 'aaa', 'ua_hash' => 'b1', 'source_id' => 1],
    ['ts' => $tsHoje, 'ts_iso' => date('c', $tsHoje), 'site' => 'cursosenac',
     'slug' => 'go/produto', 'post_id' => 7777, 'ip_hash' => 'bbb', 'ua_hash' => 'b2', 'source_id' => 2],
    ['ts' => $tsHoje, 'ts_iso' => date('c', $tsHoje), 'site' => 'cursosenac',
     'slug' => 'go/produto', 'post_id' => 7777, 'ip_hash' => 'ccc', 'ua_hash' => 'b3', 'source_id' => 3],
    ['ts' => $tsHoje, 'ts_iso' => date('c', $tsHoje), 'site' => 'cursosenac',
     'slug' => 'go/outro',   'post_id' => 8888, 'ip_hash' => 'ddd', 'ua_hash' => 'b4', 'source_id' => 4],
];
file_put_contents($clickFile, implode("\n",
    array_map(fn($e) => json_encode($e, JSON_UNESCAPED_UNICODE), $clickFixtures)) . "\n");

$cmd = '"C:\xampp\php\php.exe" "' . $rootDir . '/scripts/relatorio_performance.php" --site=cursosenac --janela=7 --json --quiet';
exec($cmd, $out, $rc);
$report = json_decode(implode("\n", $out), true);

check("relatório E2E retorna JSON (rc={$rc})", is_array($report),
    'first=' . substr(implode(' ', $out), 0, 200));
check("totais.click_entries presente",
    isset($report['totais']['click_entries']) && $report['totais']['click_entries'] === 4,
    'click_entries=' . ($report['totais']['click_entries'] ?? '?'));
check("totais.posts_com_clicks_afiliado = 2",
    isset($report['totais']['posts_com_clicks_afiliado']) && $report['totais']['posts_com_clicks_afiliado'] === 2);
check("top_clicks_afiliado seção existe",
    isset($report['top_clicks_afiliado']) && is_array($report['top_clicks_afiliado']));
check("top_clicks_afiliado[0] = post 7777 com 3 clicks",
    isset($report['top_clicks_afiliado'][0]) &&
    $report['top_clicks_afiliado'][0]['post_id'] === 7777 &&
    $report['top_clicks_afiliado'][0]['clicks_afiliado'] === 3);
check("top_clicks_afiliado[0] tem gsc_clicks correlato",
    isset($report['top_clicks_afiliado'][0]['gsc_clicks']) &&
    $report['top_clicks_afiliado'][0]['gsc_clicks'] === 500);

// top_viralizou_discover deve ter clicks_afiliado anexado
$topViral = $report['top_viralizou_discover'] ?? [];
$post7777 = null;
foreach ($topViral as $p) { if ($p['post_id'] === 7777) { $post7777 = $p; break; } }
check("top_viralizou_discover post 7777 tem clicks_afiliado=3",
    $post7777 !== null && ($post7777['clicks_afiliado'] ?? 0) === 3);
check("top_viralizou_discover post 7777 tem ctr_afiliado_pct calculado (3/500=0.6%)",
    $post7777 !== null && abs(($post7777['ctr_afiliado_pct'] ?? 0) - 0.6) < 0.01,
    'ctr_afil=' . ($post7777['ctr_afiliado_pct'] ?? '?'));

// medias_por_site deve ter clicks_afiliado + cvr_afiliado_pct
$mediaSite = $report['medias_por_site'][0] ?? null;
check("medias_por_site tem clicks_afiliado",
    $mediaSite !== null && isset($mediaSite['clicks_afiliado']) && $mediaSite['clicks_afiliado'] === 4);
check("medias_por_site tem cvr_afiliado_pct calculado",
    $mediaSite !== null && isset($mediaSite['cvr_afiliado_pct']));

// Restore arquivos reais
if ($backupPerf  !== null) @file_put_contents($perfFile,  $backupPerf);
else @unlink($perfFile);
if ($backupClick !== null) @file_put_contents($clickFile, $backupClick);
else @unlink($clickFile);

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[CLICKS C1] OK\n" : "\n[CLICKS C1] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
