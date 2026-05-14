<?php
declare(strict_types=1);
/**
 * Cleanup retroativo de travessões em-dash (—) e en-dash (–) no corpo dos posts.
 *
 * Manifesto editorial proibe travessões longos no corpo (fingerprint IA).
 * Posts antigos foram gerados antes da regra entrar em vigor — limpa via WP REST.
 *
 * Regras de substituição (conservadoras pra não quebrar contexto):
 *   ' — '      → ', '       (em-dash com espaços = vírgula)
 *   ' – '      → ', '       (en-dash com espaços = vírgula)
 *   '—'        → ', '       (em-dash colado = vírgula com espaço)
 *   '–'        → ', '       (en-dash colado = vírgula com espaço)
 *
 * Preserva travessões em URLs e atributos HTML (não toca em tags).
 *
 * Uso:
 *   php scripts/_fix_travessoes_retroativo.php
 *   php scripts/_fix_travessoes_retroativo.php --dry-run
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

$opts = getopt('', ['dry-run']);
$dryRun = isset($opts['dry-run']);

// Posts com travessões detectados pelo audit (14/05/2026 09:50)
$alvos = [
    'leaodabarra' => [1175, 1284, 1297, 1301],
    'cursosenac'  => [5756],
    'comocomprar' => [3041, 3047, 3067, 3089, 3123, 3128, 3140, 3198],
];

function limparTravessoes(string $html): array {
    $stats = ['em' => 0, 'en' => 0];
    $segments = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    $insideSkip = false;  // dentro de <script> ou <style>
    foreach ($segments as $seg) {
        if (str_starts_with($seg, '<') && str_ends_with($seg, '>')) {
            // É uma tag
            if (preg_match('#^<(script|style)\b#i', $seg))     $insideSkip = true;
            elseif (preg_match('#^</(script|style)\b#i', $seg)) $insideSkip = false;
            $out .= $seg;
            continue;
        }
        if ($insideSkip) {
            // Dentro de <script>/<style> — preserva intocado
            $out .= $seg;
            continue;
        }
        // Texto visível — aplica substituições
        $stats['em'] += substr_count($seg, '—');
        $stats['en'] += substr_count($seg, '–');
        $seg = str_replace([' — ', ' – '], ', ', $seg);
        $seg = str_replace(['—', '–'], ', ', $seg);
        $out .= $seg;
    }
    return ['html' => $out, 'stats' => $stats];
}

$totalPosts = 0;
$totalEm = 0;
$totalEn = 0;
$totalNoChange = 0;

foreach ($alvos as $slugSite => $ids) {
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $slugSite);
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

    echo "\n════ {$slugSite} ════\n";
    foreach ($ids as $pid) {
        $totalPosts++;
        try {
            $p = $wp->getPost($pid);
            $raw = (string)($p['content']['raw'] ?? '');
            if ($raw === '') {
                echo "  #{$pid}: conteúdo vazio\n";
                continue;
            }
            $res = limparTravessoes($raw);
            $emCount = $res['stats']['em'];
            $enCount = $res['stats']['en'];
            if ($emCount === 0 && $enCount === 0) {
                echo "  ✓ #{$pid}: já limpo (0 travessões)\n";
                $totalNoChange++;
                continue;
            }
            $totalEm += $emCount;
            $totalEn += $enCount;
            if ($dryRun) {
                echo "  [DRY] #{$pid}: removeria {$emCount} em-dash + {$enCount} en-dash\n";
                continue;
            }
            $r = $wp->atualizarPost($pid, ['content' => $res['html']]);
            echo "  ✅ #{$pid}: removidos {$emCount} em-dash + {$enCount} en-dash (status: " . ($r['status'] ?? '?') . ")\n";
        } catch (Throwable $e) {
            echo "  ❌ #{$pid}: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n═════ RESUMO ═════\n";
echo "Posts processados: {$totalPosts}\n";
echo "Em-dashes removidos: {$totalEm}\n";
echo "En-dashes removidos: {$totalEn}\n";
echo "Posts já limpos: {$totalNoChange}\n";
if ($dryRun) echo "[DRY-RUN — nada aplicado]\n";
