<?php
declare(strict_types=1);

/**
 * tick_filas_v2_ondecompraragora.php — substituto do tick_filas (pausado 2026-05-08).
 *
 * Substitui pipeline antigo (DiscoverGerador/DebateBuilder via prompt.md
 * com bugs) pelo gerar_post_trend que tem V4 estrito + voz própria
 * "redação Leão da Barra" + categoria via CategoryMatcher + featured Serper
 * Images + posts relacionados + entity links + video embed automático.
 *
 * Comportamento:
 *   1. Query trends status='aprovado' (já passou gate semântico do pingo)
 *   2. Filtra origens ondecompraragora (pingo:48,51,55-60)
 *   3. Score >= 5.5
 *   4. Dedup Jaccard ≥0.7 com posts publicados últimos 3 dias (anti-canibalização)
 *   5. Cap diário: 6 posts/dia
 *   6. Max 2 posts por execução
 *   7. Dispara gerar_post_trend.php --trend-id=X --draft
 *   8. gerar_post_trend marca trend como 'publicado'
 *
 * Cron sugerido: a cada 30 minutos (toda meia hora)
 *
 * Flags:
 *   --max-por-run=N (default 2)
 *   --cap-dia=N (default 6)
 *   --dry-run (lista o que faria, sem disparar)
 *   --verbose
 */

date_default_timezone_set('America/Sao_Paulo');

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$maxPorRun = (int)($args['max-por-run'] ?? 2);
$capDia = (int)($args['cap-dia'] ?? 6);
$dryRun = !empty($args['dry-run']);
$verbose = !empty($args['verbose']);
$siteSlug = (string)($args['site'] ?? 'ondecompraragora');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/DbConnection.php';
require_once __DIR__ . '/../lib/Wordpress.php';

$cfg = require __DIR__ . '/../config.php';
aplicarSite($cfg, sitesDisponiveis(), $siteSlug);

$pdo = DbConnection::pdo();

// Origens ondecompraragora (2 fontes)
$origens = ['pingo:30','pingo:41'];
$qmOrig = implode(',', array_fill(0, count($origens), '?'));

// Cap diário: conta posts ja gerados HOJE pelo gerar_post_trend (publicado nas ultimas 24h via origens leao)
$sqlHoje = "SELECT COUNT(*) FROM trends WHERE origem IN ({$qmOrig}) AND status='publicado' AND ultimo_update >= NOW() - INTERVAL 24 HOUR";
$stHoje = $pdo->prepare($sqlHoje);
$stHoje->execute($origens);
$publicados24h = (int)$stHoje->fetchColumn();
echo "[tick-v2] publicados ultimas 24h: {$publicados24h} | cap diario: {$capDia}\n";

if ($publicados24h >= $capDia) {
    echo "  ⊘ cap diario atingido, skip\n";
    exit(0);
}
$disponivelHoje = $capDia - $publicados24h;
$alvoEstaRun = min($maxPorRun, $disponivelHoje);

// Pega candidatas: status=aprovado, score>=5.5, origem ondecompraragora, ordenadas por score DESC, data DESC
$sqlCand = "SELECT id, score_discover, titulo, data_detectada FROM trends
            WHERE origem IN ({$qmOrig})
              AND status IN ('aprovado', 'novo')
              AND score_discover >= 5.5
              AND data_detectada >= NOW() - INTERVAL 24 HOUR
            ORDER BY score_discover DESC, data_detectada DESC
            LIMIT 20";
$stCand = $pdo->prepare($sqlCand);
$stCand->execute($origens);
$candidatas = $stCand->fetchAll(PDO::FETCH_ASSOC);
echo "  candidatas novo/aprovado >=5.5 (24h): " . count($candidatas) . "\n";

if (empty($candidatas)) { echo "  ⊘ sem candidatas\n"; exit(0); }

// Dedup: pega títulos de posts publicados ultimos 3 dias pra Jaccard
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$publicadosRecentes = [];
try {
    $ps = $wp->listarPosts(1, 30);
    foreach ($ps as $p) {
        $dataP = strtotime($p['date'] ?? '') ?: 0;
        if ($dataP > 0 && $dataP >= (time() - 3 * 86400)) {
            $publicadosRecentes[] = html_entity_decode((string)($p['title']['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
} catch (Throwable $e) { echo "  ⚠ listar posts: {$e->getMessage()}\n"; }
echo "  posts publicados 3d (pra dedup): " . count($publicadosRecentes) . "\n";

function tokenizarLow(string $s): array {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\w\sáéíóúâêôãõç]/u', ' ', $s) ?? $s;
    $tokens = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    // Remove stopwords curtas
    $stop = ['o','a','os','as','de','do','da','dos','das','e','em','para','por','com','sobre','que','na','no','nos','nas','um','uma','ao','aos','as','é','será'];
    return array_values(array_diff($tokens, $stop));
}
function jaccard(array $a, array $b): float {
    if (empty($a) || empty($b)) return 0.0;
    $sa = array_unique($a); $sb = array_unique($b);
    $inter = count(array_intersect($sa, $sb));
    $uni = count(array_unique(array_merge($sa, $sb)));
    return $uni > 0 ? $inter / $uni : 0.0;
}

// Filtra dedup
// Filtro de nicho: trend deve ter pelo menos 1 keyword de "onde comprar/loja/preço"
$keywordsNicho = '/\b(onde\s+comprar|loja|preç[oa]|amazon|magazine|americanas|mercadolivre|shopee|aliexpress|oferta|desconto|cupom|frete|liquida|black\s+friday|melhor\s+pre[çc]o|comparativo|review|recomenda|economizar|barato)/iu';

$processadas = 0;
foreach ($candidatas as $cand) {
    if ($processadas >= $alvoEstaRun) break;

    if (!preg_match($keywordsNicho, $cand['titulo'])) {
        echo "  ⊘ #{$cand['id']} [{$cand['score_discover']}] off-topic: " . mb_substr($cand['titulo'], 0, 60) . "\n";
        $pdo->prepare("UPDATE trends SET status='descartado_off_topic' WHERE id=?")->execute([(int)$cand['id']]);
        continue;
    }

    $tokensCand = tokenizarLow($cand['titulo']);
    $maxJac = 0.0;
    $matchTit = '';
    foreach ($publicadosRecentes as $tp) {
        $j = jaccard($tokensCand, tokenizarLow($tp));
        if ($j > $maxJac) { $maxJac = $j; $matchTit = $tp; }
    }
    if ($maxJac >= 0.7) {
        echo "  ⊘ #{$cand['id']} [{$cand['score_discover']}] dedup Jaccard=" . number_format($maxJac, 2) . " vs '" . mb_substr($matchTit, 0, 50) . "...'\n";
        // Marca como descartado-canibal pra não tentar de novo
        $pdo->prepare("UPDATE trends SET status='descartado_canibal' WHERE id=?")->execute([(int)$cand['id']]);
        continue;
    }

    // Vai disparar!
    echo "  → #{$cand['id']} [{$cand['score_discover']}] " . mb_substr($cand['titulo'], 0, 70) . "\n";
    if ($dryRun) { $processadas++; continue; }

    // Roda gerar_post_trend
    $cmd = sprintf(
        'API_FUTEBOL_KEY=%s php %s --trend-id=%d --site=%s --draft 2>&1',
        escapeshellarg((string)($cfg['api_futebol_key'] ?? '')),
        escapeshellarg(__DIR__ . '/gerar_post_trend.php'),
        (int)$cand['id'],
        escapeshellarg($siteSlug)
    );
    $out = shell_exec($cmd) ?? '';
    if ($verbose) echo "    --- output ---\n" . substr($out, -800) . "\n";
    // Pega RESUMO
    if (preg_match('/post_id:\s+(\d+).*?status:\s+(\w+)/s', $out, $m)) {
        echo "    ✓ post #{$m[1]} status={$m[2]}\n";
        $processadas++;
    } else {
        echo "    ⚠ gerou? (sem RESUMO no output)\n";
    }
}

echo "[done] processadas: {$processadas} / alvo {$alvoEstaRun} (cap dia: {$capDia})\n";
exit(0);
