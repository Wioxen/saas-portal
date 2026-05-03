<?php
declare(strict_types=1);
/**
 * scripts/publicar_top_trends.php
 *
 * Pega os top N trends de um site (status='aprovado', ordenado por score DESC),
 * busca fontes complementares via Serper, dispara gerar_noticia.php pra cada,
 * grava trends.post_id ao final.
 *
 * Uso (servidor):
 *   php /app/scripts/publicar_top_trends.php --site=cursosenac --limit=3 --dry-run
 *   php /app/scripts/publicar_top_trends.php --site=cursosenac --limit=3 --confirm
 *   php /app/scripts/publicar_top_trends.php --site=cursosenac --limit=3 --confirm --publicar
 *
 * Sem --publicar: posts ficam em status='draft' (você revisa no WP).
 * Com --publicar: status='publish' direto + IndexNow.
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/DbConnection.php';
require_once __DIR__ . '/../lib/Serper.php';
require_once __DIR__ . '/../lib/SourceTrustScore.php';

$opts = getopt('', ['site::', 'limit::', 'dry-run', 'confirm', 'publicar', 'min-score::', 'status::']);
$siteSlug = (string)($opts['site'] ?? 'cursosenac');
$limit    = (int)($opts['limit'] ?? 3);
$dryRun   = isset($opts['dry-run']);
$confirm  = isset($opts['confirm']);
$publicar = isset($opts['publicar']);
$minScore = (float)($opts['min-score'] ?? 5.0);
// Status aceitos — default inclui 'novo' e 'aprovado' pra cobrir casos onde
// auto_aprovar_score_min da fonte é maior que o score real do trend.
$statusList = (string)($opts['status'] ?? 'novo,aprovado');
$statusArr = array_filter(array_map('trim', explode(',', $statusList)));

if (!$dryRun && !$confirm) {
    fwrite(STDERR, "Modo padrão é --dry-run. Pra publicar use --confirm.\n");
    fwrite(STDERR, "uso: --site=SLUG --limit=N [--min-score=5.0] [--dry-run | --confirm] [--publicar]\n");
    exit(2);
}

$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteSlug);

$pdo = DbConnection::pdo();

// Lista top N trends sem post_id, status na lista permitida
$placeholders = [];
$bindStatus = [];
foreach ($statusArr as $i => $s) {
    $ph = ":st{$i}";
    $placeholders[] = $ph;
    $bindStatus[$ph] = $s;
}
$inClause = implode(',', $placeholders);

$sql = "SELECT id, termo, pingo_link, score_discover, status, payload
        FROM trends
        WHERE site = :site
          AND status IN ({$inClause})
          AND score_discover >= :ms
          AND (post_id IS NULL OR post_id = 0)
        ORDER BY score_discover DESC
        LIMIT :lim";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':site', $siteSlug);
$stmt->bindValue(':ms', $minScore);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
foreach ($bindStatus as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($trends)) {
    echo "Nenhum trend encontrado pra '{$siteSlug}' (status IN [" . implode(',', $statusArr) . "], score>={$minScore}, sem post_id).\n";
    echo "Tente: --status=novo,aprovado,gerando ou baixar --min-score=4.5\n";
    exit(0);
}

echo "═══ Top {$limit} trends · {$siteSlug} · status=[" . implode(',', $statusArr) . "] · " . ($dryRun ? 'DRY-RUN' : 'PRODUÇÃO') . " ═══\n\n";

$serper = new Serper($cfg['serper_api_key']);
$scriptsDir = __DIR__;
$sucessos = 0; $falhas = 0;

foreach ($trends as $i => $t) {
    $tid = (int)$t['id'];
    $termo = (string)$t['termo'];
    $score = (float)$t['score_discover'];
    $pingoLink = trim((string)($t['pingo_link'] ?? ''));

    echo "[" . ($i+1) . "/" . count($trends) . "] trend #{$tid} score={$score}\n";
    echo "  termo: {$termo}\n";
    echo "  fonte original: {$pingoLink}\n";

    // Busca fontes complementares via Serper
    $urlsExtras = [];
    try {
        $resp = $serper->search($termo, 8);
        foreach (($resp['organic'] ?? []) as $r) {
            $u = (string)($r['link'] ?? '');
            if ($u !== '' && $u !== $pingoLink) $urlsExtras[] = ['url' => $u];
        }
    } catch (Throwable $e) {
        echo "  ⚠️ Serper falhou: " . $e->getMessage() . "\n";
    }
    $urlsExtras = SourceTrustScore::ordenarPorTier($urlsExtras);
    $urlsExtras = array_slice(array_column($urlsExtras, 'url'), 0, 3);

    $todasUrls = $pingoLink !== '' ? array_merge([$pingoLink], $urlsExtras) : $urlsExtras;
    $todasUrls = array_unique($todasUrls);

    echo "  fontes (com original + Serper top): " . count($todasUrls) . "\n";
    foreach ($todasUrls as $u) {
        $tier = SourceTrustScore::tierUrl($u);
        echo "    [{$tier}] {$u}\n";
    }

    if ($dryRun) { echo "  [dry-run]\n\n"; continue; }
    if (count($todasUrls) < 1) { echo "  ✗ sem fontes — pulando\n\n"; $falhas++; continue; }

    // Dispara gerar_noticia.php (separador | pra preservar URLs com vírgula)
    $statusFlag = $publicar ? '--publicar' : '';
    $cmd = sprintf(
        'php %s --site=%s --urls=%s --titulo-hint=%s %s 2>&1',
        escapeshellarg($scriptsDir . '/gerar_noticia.php'),
        escapeshellarg($siteSlug),
        escapeshellarg(implode('|', $todasUrls)),
        escapeshellarg($termo),
        $statusFlag
    );
    $output = (string)shell_exec($cmd);
    echo "  --- output gerar_noticia ---\n";
    foreach (explode("\n", trim($output)) as $line) echo "    {$line}\n";

    if (preg_match('/POST CRIADO id=(\d+)/', $output, $m)) {
        $postId = (int)$m[1];
        // Marca trend.post_id
        $upd = $pdo->prepare("UPDATE trends SET post_id = :pid, status = 'publicado' WHERE id = :tid");
        $upd->execute([':pid' => $postId, ':tid' => $tid]);
        echo "  ✓ trend #{$tid} → post_id={$postId} (status=publicado)\n\n";
        $sucessos++;
    } else {
        echo "  ✗ não detectou 'POST CRIADO id=' no output\n\n";
        $falhas++;
    }
}

echo str_repeat('─', 60) . "\n";
echo "Sucessos: {$sucessos} · Falhas: {$falhas}\n";
