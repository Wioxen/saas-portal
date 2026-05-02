<?php
/**
 * scripts/diagnosticar_travessoes.php
 *
 * Diagnostica por que substituirTravessaoContextual não está limpando travessões
 * em posts já gerados. Verifica:
 *   1. Quantos travessões — (U+2014) e – (U+2013) há no conteúdo bruto do WP
 *   2. Onde estão (em <p>, <li>, <h2>, dentro de <a>, etc.)
 *   3. Se a função substituirTravessaoContextual remove eles
 *
 * Uso:
 *   php scripts/diagnosticar_travessoes.php --site=SLUG --post-id=N
 */

$siteArg = '';
$postId  = 0;
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m)) $siteArg = $m[1];
    if (preg_match('/^--post-id=(\d+)$/', $a, $m)) $postId = (int)$m[1];
}
if ($siteArg === '' || $postId <= 0) {
    fwrite(STDERR, "Uso: php scripts/diagnosticar_travessoes.php --site=SLUG --post-id=N\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
$sites = sitesDisponiveis();
if (!isset($sites[$siteArg])) { fwrite(STDERR, "Site inválido.\n"); exit(2); }
aplicarSite($cfg, $sites, $siteArg);

require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/DiscoverPostProcess.php';

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$post = $wp->getPost($postId);
$html = $post['content']['raw'] ?? $post['content']['rendered'] ?? '';

echo "═══ POST #{$postId} · " . strlen($html) . " bytes ═══\n\n";

// ─── 1. Contagem por tipo
$emDash = substr_count($html, "\xE2\x80\x94");  // U+2014 em UTF-8
$enDash = substr_count($html, "\xE2\x80\x93");  // U+2013
echo "1. Caracteres travessão no HTML bruto:\n";
echo "   em-dash  (—, U+2014): {$emDash}\n";
echo "   en-dash  (–, U+2013): {$enDash}\n";
echo "   total: " . ($emDash + $enDash) . "\n\n";

if ($emDash + $enDash === 0) {
    echo "✓ Zero travessões no post. Método já limpou (ou Sonnet não usou).\n";
    exit(0);
}

// ─── 2. Mostrar 5 contextos
echo "2. Contextos (até 5 ocorrências):\n";
$count = 0;
$padrao = '/.{0,40}[—–].{0,40}/u';
if (preg_match_all($padrao, $html, $m)) {
    foreach ($m[0] as $trecho) {
        if ($count >= 5) break;
        $clean = preg_replace('/\s+/', ' ', strip_tags($trecho));
        echo "   - …{$clean}…\n";
        $count++;
    }
}
echo "\n";

// ─── 3. Tentar substituir e ver se sobra algum
echo "3. Aplicando DiscoverPostProcess::processar agora pra ver se pega:\n";
try {
    $depois = DiscoverPostProcess::processar($html);
    $emDashDepois = substr_count($depois, "\xE2\x80\x94");
    $enDashDepois = substr_count($depois, "\xE2\x80\x93");
    $diff = ($emDash + $enDash) - ($emDashDepois + $enDashDepois);
    echo "   antes: " . ($emDash + $enDash) . " · depois: " . ($emDashDepois + $enDashDepois) . " · removidos: {$diff}\n";
    if ($emDashDepois + $enDashDepois > 0) {
        echo "\n   Travessões que SOBRARAM (até 3):\n";
        if (preg_match_all($padrao, $depois, $m)) {
            $shown = 0;
            foreach ($m[0] as $trecho) {
                if ($shown >= 3) break;
                $clean = preg_replace('/\s+/', ' ', strip_tags($trecho));
                echo "   - …{$clean}…\n";
                $shown++;
            }
        }
    }
} catch (Throwable $e) {
    echo "   ✗ falha em processar: " . $e->getMessage() . "\n";
}

// ─── 4. Verificar onde estão (qual tag-pai)
echo "\n4. Quais tags contêm travessão:\n";
$tagsComTrav = [];
if (preg_match_all('#<([a-z][a-z0-9]*)\b[^>]*>([^<]*[—–][^<]*)<#i', $html, $m, PREG_SET_ORDER)) {
    foreach ($m as $hit) {
        $tag = strtolower($hit[1]);
        $tagsComTrav[$tag] = ($tagsComTrav[$tag] ?? 0) + substr_count($hit[2], '—') + substr_count($hit[2], '–');
    }
}
foreach ($tagsComTrav as $tag => $n) echo "   <{$tag}>: {$n}\n";

echo "\n═══ FIM ═══\n";
