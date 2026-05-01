<?php
/**
 * [TESTE] DiscoverImagemFeatured — gera 3 candidatas (Pexels top, DALL-E, og fallback)
 * pra comparação visual antes de subir o pipeline em produção.
 *
 * Uso:
 *   php scripts/_testar_imagem_featured.php "Enem 2026 prazo isenção" --cluster=educacao
 *   php scripts/_testar_imagem_featured.php "presentes dia das mães" --cluster=lifestyle_consumo --salvar
 *   php scripts/_testar_imagem_featured.php "..." --skip-dalle    # só Pexels (zero custo)
 *
 * Salva preview HTML em /tmp/imagem_featured_preview.html.
 */

require_once __DIR__ . '/../lib/DiscoverImagemFeatured.php';

$cfg = require __DIR__ . '/../config.php';

$termo = '';
$cluster = '';
$salvar = false;
$skipDalle = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--cluster=')) $cluster = substr($a, 10);
    elseif ($a === '--salvar') $salvar = true;
    elseif ($a === '--skip-dalle') $skipDalle = true;
    elseif ($a !== '' && $a[0] !== '-') $termo = trim($termo . ' ' . $a);
}

if ($termo === '') {
    fwrite(STDERR, "Uso: php scripts/_testar_imagem_featured.php \"<termo>\" [--cluster=X] [--skip-dalle] [--salvar]\n");
    exit(1);
}

echo "TERMO: {$termo}\n";
echo "CLUSTER: " . ($cluster ?: '(vazio)') . "\n\n";

// Pexels queries previstas pelo gerador
echo "═══ Pexels queries previstas (em ordem de prioridade) ═══\n";
$queries = DiscoverImagemFeatured::gerarQueriesPexels($termo, $cluster);
foreach ($queries as $i => $q) printf("  %d. %s\n", $i + 1, $q);

// Prompt DALL-E previsto
echo "\n═══ Prompt DALL-E previsto ═══\n";
echo DiscoverImagemFeatured::montarPromptDalle($termo, $cluster, $termo) . "\n";

// Slug SEO sugerido
echo "\nSlug SEO sugerido: " . DiscoverImagemFeatured::slugSeo($termo) . "\n";

// Tenta resolução real
echo "\n═══ Resolvendo (Pexels primeiro) ═══\n";
$cfgTeste = $cfg;
if ($skipDalle) $cfgTeste['imagem_featured_dalle_fallback'] = false;
$svc = new DiscoverImagemFeatured($cfgTeste);
$ini = microtime(true);
$res = $svc->escolher([
    'termo'           => $termo,
    'cluster_key'     => $cluster,
    'briefing_titulo' => $termo,
    'og_image_fallback' => '',
]);
$dur = round((microtime(true) - $ini) * 1000);

printf("→ %s (%dms)\n", $res['fonte'] ?? '?', $dur);
echo "URL: " . ($res['url'] ?: '(vazia)') . "\n";
if (!empty($res['metadata'])) {
    foreach ($res['metadata'] as $k => $v) {
        if (is_string($v)) echo "  {$k}: " . substr($v, 0, 120) . "\n";
        else echo "  {$k}: " . json_encode($v) . "\n";
    }
}

if ($salvar) {
    $path = sys_get_temp_dir() . '/imagem_featured_preview.html';
    $img = htmlspecialchars($res['url'] ?? '', ENT_QUOTES, 'UTF-8');
    @file_put_contents($path,
        "<!DOCTYPE html><html><head><meta charset='utf-8'><title>{$termo}</title></head>"
        . "<body style='font-family:sans-serif;max-width:900px;margin:20px auto;padding:0 20px'>"
        . "<h1>Preview Imagem Featured</h1>"
        . "<p><strong>Termo:</strong> {$termo} · <strong>Cluster:</strong> {$cluster} · <strong>Fonte:</strong> {$res['fonte']}</p>"
        . "<p><strong>Slug SEO:</strong> " . htmlspecialchars($res['slug_sugerido']) . ".webp</p>"
        . "<img src='{$img}' style='width:100%;max-width:900px;border:1px solid #ddd'>"
        . "<pre style='background:#f5f5f5;padding:12px;font-size:12px'>"
        . htmlspecialchars(json_encode($res['metadata'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
        . "</pre></body></html>");
    echo "\n→ Preview salvo em: {$path}\n";
}
