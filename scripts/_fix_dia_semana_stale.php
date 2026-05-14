<?php
declare(strict_types=1);
/**
 * Cleanup retroativo de "nesta/neste {weekday}" stale + mismatches "X de mês (Y-feira)".
 *
 * Estratégia:
 *   1. Para "nesta quarta-feira" — substitui por data absoluta no dia da semana
 *      mais próximo (forward) a partir da data de publicação do post.
 *   2. Para "20 de maio, terça-feira" — preserva a data, corrige weekday claim.
 *   3. Pula <script>/<style>.
 *
 * Uso:
 *   php scripts/_fix_dia_semana_stale.php
 *   php scripts/_fix_dia_semana_stale.php --dry-run
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();
$opts = getopt('', ['dry-run']);
$dryRun = isset($opts['dry-run']);

$alvos = [
    'leaodabarra'      => [1175, 1323, 1352, 1379],
    'cursosenac'       => [5761],
    'comocomprar'      => [3146, 3191],
    'vagasebeneficios' => [2464],
];

$weekdayMap = [
    'segunda' => 1, 'segunda-feira' => 1,
    'terça'   => 2, 'terça-feira'   => 2, 'terca' => 2, 'terca-feira' => 2,
    'quarta'  => 3, 'quarta-feira'  => 3,
    'quinta'  => 4, 'quinta-feira'  => 4,
    'sexta'   => 5, 'sexta-feira'   => 5,
    'sábado'  => 6, 'sabado'        => 6,
    'domingo' => 7,
];
$weekdayNameByNum = [1=>'segunda-feira', 2=>'terça-feira', 3=>'quarta-feira', 4=>'quinta-feira', 5=>'sexta-feira', 6=>'sábado', 7=>'domingo'];
$mesMap = [
    'janeiro'=>1, 'fevereiro'=>2, 'março'=>3, 'marco'=>3, 'abril'=>4, 'maio'=>5, 'junho'=>6,
    'julho'=>7, 'agosto'=>8, 'setembro'=>9, 'outubro'=>10, 'novembro'=>11, 'dezembro'=>12,
];
$mesNomePorNum = array_flip(array_map(fn($v) => $v, array_intersect($mesMap, range(1,12))));
// Simples: 1=>janeiro etc
$mesNomePorNum = [1=>'janeiro', 2=>'fevereiro', 3=>'março', 4=>'abril', 5=>'maio', 6=>'junho', 7=>'julho', 8=>'agosto', 9=>'setembro', 10=>'outubro', 11=>'novembro', 12=>'dezembro'];

function dataAbsoluta(int $pubTs, string $weekdayPt, array $map, array $mesNomes): ?array {
    $key = mb_strtolower($weekdayPt);
    if (!isset($map[$key])) return null;
    $target = $map[$key];
    $pubWk = (int)date('N', $pubTs);
    $offset = ($target >= $pubWk) ? ($target - $pubWk) : (7 - ($pubWk - $target));
    $ts = $pubTs + $offset * 86400;
    $dia = (int)date('j', $ts);
    $mes = (int)date('n', $ts);
    return ['ts' => $ts, 'texto' => "no dia {$dia} de {$mesNomes[$mes]}"];
}

function fixarDataWeekdayMismatch(string $texto, array $map, array $weekdayNum, array $mesMap, array $mesNomes): array {
    // Padrão: "20 de maio, terça-feira" OU "20 de maio (terça)"
    $count = 0;
    $padrao = '/(\b\d{1,2})\s+de\s+(janeiro|fevereiro|março|marco|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)(?:(\s*\()(\w+(?:-feira)?)(\))|(,?\s+)(\w+-feira|sábado|sabado|domingo))/iu';
    $texto = preg_replace_callback($padrao, function ($m) use (&$count, $map, $weekdayNum, $mesMap, $mesNomes) {
        $dia = (int)$m[1];
        $mesNome = mb_strtolower($m[2]);
        $mes = $mesMap[$mesNome] ?? null;
        if (!$mes) return $m[0];
        $weekdayClaim = !empty($m[4]) ? $m[4] : ($m[6] ?? '');
        if ($weekdayClaim === '') return $m[0];
        // Calcula weekday real do dia+mês no ano atual
        $ts = mktime(12, 0, 0, $mes, $dia, (int)date('Y'));
        $realWk = (int)date('N', $ts);
        $realName = $weekdayNum[$realWk];
        $claimedWk = $map[mb_strtolower($weekdayClaim)] ?? null;
        if ($claimedWk === null || $claimedWk === $realWk) return $m[0];
        // Mismatch — substitui
        $count++;
        if (!empty($m[3])) {
            // padrão "(weekday)"
            return $m[1] . ' de ' . $m[2] . $m[3] . $realName . $m[5];
        }
        return $m[1] . ' de ' . $m[2] . $m[5] . $realName;
    }, $texto);
    return ['html' => $texto, 'count' => $count];
}

function aplicarTudo(string $html, int $pubTs, array $weekdayMap, array $weekdayNum, array $mesMap, array $mesNomes): array {
    $segs = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    $skip = false;
    $countNesta = 0;
    $countMismatch = 0;
    foreach ($segs as $seg) {
        if (str_starts_with($seg, '<') && str_ends_with($seg, '>')) {
            if (preg_match('#^<(script|style)\b#i', $seg))      $skip = true;
            elseif (preg_match('#^</(script|style)\b#i', $seg))  $skip = false;
            $out .= $seg;
            continue;
        }
        if ($skip) { $out .= $seg; continue; }
        // 1) Substitui "nesta {weekday}" / "neste {weekday}" por data absoluta
        $seg = preg_replace_callback(
            '/\b(nest[ae])\s+(segunda(?:-feira)?|terça(?:-feira)?|terca(?:-feira)?|quarta(?:-feira)?|quinta(?:-feira)?|sexta(?:-feira)?|sábado|sabado|domingo)\b/iu',
            function ($m) use ($pubTs, $weekdayMap, $mesNomes, &$countNesta) {
                $abs = dataAbsoluta($pubTs, $m[2], $weekdayMap, $mesNomes);
                if ($abs === null) return $m[0];
                $countNesta++;
                return $abs['texto'];
            },
            $seg
        );
        // 2) Mismatch "X de mês (Y-feira)" / "X de mês, Y-feira"
        $res = fixarDataWeekdayMismatch($seg, $weekdayMap, $weekdayNum, $mesMap, $mesNomes);
        $seg = $res['html'];
        $countMismatch += $res['count'];
        $out .= $seg;
    }
    return ['html' => $out, 'nesta' => $countNesta, 'mismatch' => $countMismatch];
}

$totalPosts = 0;
$totalNesta = 0;
$totalMismatch = 0;

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
            $pubDate = (string)($p['date'] ?? '');
            $pubTs = strtotime($pubDate);
            if ($raw === '' || !$pubTs) { echo "  #{$pid}: skip (vazio)\n"; continue; }
            $res = aplicarTudo($raw, $pubTs, $weekdayMap, $weekdayNameByNum, $mesMap, $mesNomePorNum);
            $tot = $res['nesta'] + $res['mismatch'];
            if ($tot === 0) { echo "  ✓ #{$pid}: já limpo (pub " . date('d/m', $pubTs) . ")\n"; continue; }
            $totalNesta += $res['nesta']; $totalMismatch += $res['mismatch'];
            if ($dryRun) {
                echo "  [DRY] #{$pid}: {$res['nesta']} 'nesta/neste' + {$res['mismatch']} mismatch (pub " . date('d/m', $pubTs) . ")\n";
                continue;
            }
            $r = $wp->atualizarPost($pid, ['content' => $res['html']]);
            echo "  ✅ #{$pid}: {$res['nesta']} 'nesta/neste' + {$res['mismatch']} mismatch (pub " . date('d/m', $pubTs) . ") → " . ($r['status'] ?? '?') . "\n";
        } catch (Throwable $e) {
            echo "  ❌ #{$pid}: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n═════ RESUMO ═════\n";
echo "Posts processados: {$totalPosts}\n";
echo "Substituições 'nesta/neste': {$totalNesta}\n";
echo "Mismatch weekday corrigido: {$totalMismatch}\n";
if ($dryRun) echo "[DRY-RUN]\n";
