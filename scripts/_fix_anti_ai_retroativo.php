<?php
declare(strict_types=1);
/**
 * Cleanup retroativo de padrões IA detectados pelo AntiAIValidator:
 *   - cliches_ia_abertura  ("o que ninguém te conta")
 *   - clickbait_titulo     (mesma frase em <title>)
 *   - narrativa_template_llm  ("quem busca/precisa/espera")
 *   - vague_promise        ("o detalhe que", "o ponto que")
 *   - connectors_robot     ("portanto")
 *   - fillers_narrativa    ("na prática", "rapidamente", "logo de cara", "sem perceber")
 *   - atribuicao-fonte-no-corpo ("no portal globo" → "no ge.globo")
 *   - cliches_fechamento   ("para concluir" → "no fim")
 *
 * Pula <script>/<style> blocks. Substituições conservadoras (preservam leitura).
 *
 * Uso:
 *   php scripts/_fix_anti_ai_retroativo.php
 *   php scripts/_fix_anti_ai_retroativo.php --dry-run
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();
$opts = getopt('', ['dry-run']);
$dryRun = isset($opts['dry-run']);

// Substituições regex (case-insensitive). Ordem importa: específicas primeiro.
$subs = [
    // cliches_ia_abertura + clickbait_titulo
    '/\bo que ninguém te conta\b/i'                 => 'o que está em jogo',

    // narrativa_template_llm — "quem X" → "se você X"
    '/\bquem busca\b/i'                              => 'se você busca',
    '/\bquem precisa\b/i'                            => 'se você precisa',
    '/\bquem espera\b/i'                             => 'se você espera',

    // vague_promise
    '/\bo detalhe que\b/i'                           => 'o ponto',
    '/\bo ponto que\b/i'                             => 'o aspecto',

    // connectors_robot
    '/\bPortanto,\s+/'                               => 'No fim, ',
    '/\bportanto,\s+/'                               => 'no fim, ',
    '/\bPortanto\s+/'                                => 'No fim, ',

    // fillers_narrativa
    '/\bna prática,\s+/i'                            => 'no dia a dia, ',
    '/\bna prática\b/i'                              => 'no dia a dia',
    '/\brapidamente\b/i'                             => 'logo',
    '/\blogo de cara\b/i'                            => 'de início',
    '/\bsem perceber\b/i'                            => 'sem notar',

    // cliches_fechamento
    '/\bpara concluir\b/i'                           => 'no fim',

    // atribuicao-fonte-no-corpo
    '/\bno portal globo\b/i'                         => 'no ge.globo',
    '/\bno portal Globo\b/'                          => 'no ge.globo',
];

// Posts alvo do audit 14/05/2026 09:50
$alvos = [
    'leaodabarra' => [1175, 1313, 1323, 1358, 1284],
    'cursosenac'  => [5769, 5802, 5841, 5761],
    'comocomprar' => [3041, 3047, 3067, 3123, 3128],
];

function aplicarSubs(string $html, array $subs): array {
    $totais = array_fill_keys(array_keys($subs), 0);
    $segments = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    $insideSkip = false;
    foreach ($segments as $seg) {
        if (str_starts_with($seg, '<') && str_ends_with($seg, '>')) {
            if (preg_match('#^<(script|style)\b#i', $seg))      $insideSkip = true;
            elseif (preg_match('#^</(script|style)\b#i', $seg))  $insideSkip = false;
            $out .= $seg;
            continue;
        }
        if ($insideSkip) { $out .= $seg; continue; }
        // Texto — aplica substituições + conta
        foreach ($subs as $pattern => $replacement) {
            $count = 0;
            $seg = preg_replace($pattern, $replacement, $seg, -1, $count);
            $totais[$pattern] += $count;
        }
        $out .= $seg;
    }
    return ['html' => $out, 'subs' => $totais];
}

$relatorioGlobal = [];
$totalPosts = 0;
$totalSubs = 0;

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
            $titulo = (string)($p['title']['raw'] ?? '');
            if ($raw === '') { echo "  #{$pid}: conteúdo vazio\n"; continue; }

            $res = aplicarSubs($raw, $subs);
            $detalhes = [];
            $countSub = 0;
            foreach ($res['subs'] as $pattern => $n) {
                if ($n > 0) {
                    $detalhes[] = preg_replace('/[\/\\\\bI]/u', '', $pattern) . " x{$n}";
                    $countSub += $n;
                }
            }

            // Título — tem clichê?
            $tituloNovo = $titulo;
            foreach ($subs as $pattern => $replacement) {
                if (preg_match($pattern, $titulo)) {
                    $tituloNovo = preg_replace($pattern, $replacement, $tituloNovo);
                }
            }
            $tituloMudou = ($tituloNovo !== $titulo);

            if ($countSub === 0 && !$tituloMudou) {
                echo "  ✓ #{$pid}: já limpo\n";
                continue;
            }
            $totalSubs += $countSub;

            $sumario = $countSub > 0 ? "{$countSub} subs (" . implode(', ', array_slice($detalhes, 0, 4)) . ")" : "só título";
            if ($tituloMudou) $sumario .= " + título";

            if ($dryRun) {
                echo "  [DRY] #{$pid}: {$sumario}\n";
                if ($tituloMudou) echo "        TIT antes: " . mb_substr($titulo, 0, 100) . "\n";
                if ($tituloMudou) echo "        TIT novo:  " . mb_substr($tituloNovo, 0, 100) . "\n";
                continue;
            }

            $payload = ['content' => $res['html']];
            if ($tituloMudou) $payload['title'] = $tituloNovo;
            $r = $wp->atualizarPost($pid, $payload);
            echo "  ✅ #{$pid}: {$sumario} → status: " . ($r['status'] ?? '?') . "\n";
        } catch (Throwable $e) {
            echo "  ❌ #{$pid}: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n═════ RESUMO ═════\n";
echo "Posts processados: {$totalPosts}\n";
echo "Substituições totais: {$totalSubs}\n";
if ($dryRun) echo "[DRY-RUN — nada aplicado]\n";
