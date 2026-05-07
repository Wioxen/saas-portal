<?php
/**
 * publicar_entity_concept_pages.php — publica entity/concept hubs em status=draft.
 *
 * Lê data/entity_pages_cache/{site}_aliases.json, checa status atual via REST,
 * publica todos que ainda estão em draft.
 *
 * Uso:
 *   php scripts/publicar_entity_concept_pages.php --site=cursosenac
 *   php scripts/publicar_entity_concept_pages.php --site=cursosenac --slug=ifsp,senac      # só esses
 *   php scripts/publicar_entity_concept_pages.php --site=cursosenac --tipo=entity          # só entity
 *   php scripts/publicar_entity_concept_pages.php --site=cursosenac --dry-run              # só lista
 *   php scripts/publicar_entity_concept_pages.php --site=cursosenac --quiet
 */

declare(strict_types=1);

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$siteSlug = (string)($args['site'] ?? 'cursosenac');
$dryRun = !empty($args['dry-run']);
$quiet = !empty($args['quiet']);
$slugFiltro = isset($args['slug']) ? array_filter(array_map('trim', explode(',', (string)$args['slug']))) : [];
$tipoFiltro = isset($args['tipo']) ? (string)$args['tipo'] : '';

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';

aplicarSite($cfg, sitesDisponiveis(), $siteSlug);

$say = function (string $msg) use ($quiet) {
    if (!$quiet) echo $msg . "\n";
};

$say("═══ Publicar Hubs — site={$siteSlug} | " . ($dryRun ? 'DRY-RUN' : 'EXECUTE') . " ═══");

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

$aliasesPath = __DIR__ . "/../data/entity_pages_cache/{$siteSlug}_aliases.json";
if (!file_exists($aliasesPath)) {
    fwrite(STDERR, "✗ aliases.json não encontrado: {$aliasesPath}\n");
    exit(1);
}
$aliases = json_decode((string)file_get_contents($aliasesPath), true);
if (!is_array($aliases) || empty($aliases)) {
    fwrite(STDERR, "✗ aliases.json vazio\n");
    exit(1);
}

$say("aliases.json: " . count($aliases) . " hubs configurados");

$candidatos = [];
foreach ($aliases as $pid => $info) {
    $slug = (string)($info['slug'] ?? '');
    $tipo = (string)($info['tipo'] ?? 'entity');
    $rotulo = (string)($info['nome'] ?? $info['fullname'] ?? "page#{$pid}");

    if (!empty($slugFiltro) && !in_array($slug, $slugFiltro, true)) continue;
    if ($tipoFiltro !== '' && $tipo !== $tipoFiltro) continue;

    try {
        $page = $wp->getPagina((int)$pid);
        $statusAtual = (string)($page['status'] ?? '');
        $candidatos[] = [
            'page_id' => (int)$pid,
            'slug' => $slug,
            'tipo' => $tipo,
            'rotulo' => $rotulo,
            'status' => $statusAtual,
        ];
    } catch (Throwable $e) {
        $say("  ⚠ #{$pid} {$rotulo}: erro fetch ({$e->getMessage()})");
    }
}

$paraPublicar = array_filter($candidatos, fn($c) => $c['status'] === 'draft');
$jaPublicados = array_filter($candidatos, fn($c) => $c['status'] === 'publish');

$say(count($paraPublicar) . " a publicar | " . count($jaPublicados) . " já publicados");

if (empty($paraPublicar)) {
    $say("✓ Nada a fazer.");
    exit(0);
}

if (!$quiet) {
    foreach ($paraPublicar as $c) {
        echo "  · #{$c['page_id']} [{$c['tipo']}/{$c['slug']}] {$c['rotulo']}\n";
    }
}

if ($dryRun) {
    $say("\n[dry-run] Use sem --dry-run pra publicar.");
    exit(0);
}

echo "\n";
$ok = 0;
$fail = 0;
foreach ($paraPublicar as $c) {
    $pid = $c['page_id'];
    $say("→ #{$pid} {$c['rotulo']} ...");
    try {
        $r = $wp->atualizarPagina($pid, ['status' => 'publish']);
        $linkFinal = (string)($r['link'] ?? '');
        $statusFinal = (string)($r['status'] ?? '');
        if ($statusFinal === 'publish') {
            $say("   ✓ {$linkFinal}");
            $ok++;
        } else {
            $say("   ⚠ status retornado: {$statusFinal}");
            $fail++;
        }
    } catch (Throwable $e) {
        $say("   ✗ FAIL: " . $e->getMessage());
        $fail++;
    }
}

if (!$quiet) {
    echo "\n═══ RESUMO ═══\n";
    echo "  publicadas: {$ok} / " . count($paraPublicar) . "\n";
    echo "  falhas:     {$fail}\n";
}
