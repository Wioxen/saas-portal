<?php
/**
 * trashar_hubs.php — move entity/concept hubs pra trash via WP REST.
 * Atualiza aliases.json removendo entries. Regenera KG ao final.
 *
 * Uso:
 *   php scripts/trashar_hubs.php --site=cursosenac --ids=5154,5177,5174,5173,5175,5181
 *   php scripts/trashar_hubs.php --site=cursosenac --ids=5154 --force   # delete permanente
 */

declare(strict_types=1);

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$siteSlug = (string)($args['site'] ?? '');
$idsStr = (string)($args['ids'] ?? '');
$force = !empty($args['force']);

if ($siteSlug === '' || $idsStr === '') {
    fwrite(STDERR, "uso: php trashar_hubs.php --site=SLUG --ids=A,B,C [--force]\n");
    exit(2);
}

$ids = array_filter(array_map('intval', explode(',', $idsStr)));
if (empty($ids)) {
    fwrite(STDERR, "✗ ids inválidos\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';

aplicarSite($cfg, sitesDisponiveis(), $siteSlug);

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

echo "═══ Trash hubs — site={$siteSlug} | " . ($force ? 'DELETE PERMANENTE' : 'TRASH') . " ═══\n";
echo "ids: " . implode(',', $ids) . "\n\n";

// 1. Trash via REST (DELETE /pages/{id} ou ?force=true)
$pdoUrl = rtrim($cfg['wp_url'], '/') . '/wp-json/wp/v2';
$auth = base64_encode("{$cfg['wp_user']}:{$cfg['wp_app_password']}");

$ok = 0;
$fail = 0;
foreach ($ids as $id) {
    echo "→ #{$id} ... ";
    $url = "{$pdoUrl}/pages/{$id}" . ($force ? '?force=true' : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => ["Authorization: Basic {$auth}", 'Accept: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        echo "✓ OK ({$code})\n";
        $ok++;
    } else {
        echo "✗ HTTP {$code}: " . substr((string)$body, 0, 200) . "\n";
        $fail++;
    }
}

// 2. Atualiza aliases.json removendo IDs
$aliasesPath = __DIR__ . "/../data/entity_pages_cache/{$siteSlug}_aliases.json";
if (file_exists($aliasesPath)) {
    $aliases = json_decode((string)file_get_contents($aliasesPath), true);
    if (is_array($aliases)) {
        $removidos = 0;
        foreach ($ids as $id) {
            if (isset($aliases[(string)$id])) {
                unset($aliases[(string)$id]);
                $removidos++;
            }
        }
        file_put_contents($aliasesPath, json_encode($aliases, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo "\n✓ aliases.json atualizado ({$removidos} entries removidos)\n";
    }
}

// 3. Limpa cache do EntityPageLinker (parents)
foreach (glob(__DIR__ . "/../data/entity_pages_cache/{$siteSlug}_*-*.json") ?: [] as $f) {
    if (basename($f) === "{$siteSlug}_aliases.json") continue;
    @unlink($f);
}

echo "\n═══ RESUMO ═══\n";
echo "  ok: {$ok} / " . count($ids) . "\n";
echo "  falhas: {$fail}\n";
echo "\n  Próximo: rode `php scripts/gerar_knowledge_graph.php --site={$siteSlug}` pra atualizar KG\n";
