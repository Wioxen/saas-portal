<?php
declare(strict_types=1);
/**
 * Cleanup retroativo de atribuição derivada em posts publicados.
 * Patterns removidos (autoria = redação do site):
 *   - "conforme apurou a redação a partir de matéria do {portal}"
 *   - "conforme apurado pela redação a partir do {portal}"
 *   - "conforme apurou a redação" (genérico — flag editorial mas mantém o fato)
 *   - "<em>Fonte: ... </em>" paragraph (se mencionar portal jornalístico)
 *   - "Ver matéria original" link
 *   - Schema "citation" key (NewsArticle.citation)
 *   - "segundo o/a {portal}" → removido
 *   - "no portal {portal}" / "no jornal {portal}" / "no site {portal}" → removido
 *   - "matéria do {portal}" / "reportagem do {portal}" → removido
 *
 * NÃO remove: "veja", "exame" (palavras comuns em PT, alto false-positive).
 * Preserva: menções a entidades INSTITUCIONAIS (Inep, MEC, Alesp, gov.br, etc).
 *
 * Uso:
 *   php scripts/_fix_atribuicao_retroativo_batch.php
 *   php scripts/_fix_atribuicao_retroativo_batch.php --dry-run
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();
$opts = getopt('', ['dry-run']);
$dryRun = isset($opts['dry-run']);

// Posts flageados pelo audit 14/05/2026
$alvos = [
    'vagasebeneficios' => [2713, 2436, 2432, 2261, 1922],
    'cursosenac'       => [5841, 5834, 5826, 5802, 5774, 5769, 5756, 5752, 5744, 5740, 5735, 5731, 5709, 5621, 5617, 5613, 5609, 5590, 5604],
    'guiadoscursos'    => [3761, 3716, 3720, 759, 2486, 2484, 3427, 3426, 3424, 3280, 3197],
    'comocomprar'      => [3211, 3143, 3140, 3132, 3128, 3123, 3120, 3067, 3047, 3016, 2992, 2947, 2934, 2929, 2813, 2795, 2787, 2754, 2749, 2747],
    'ondecompraragora' => [124],
    'leaodabarra'      => [408, 410, 413, 419, 420, 421, 423, 472, 474, 476, 1677, 662, 872, 882, 891, 1179, 1183, 1218, 1238, 1214, 1222, 1271, 1288, 1292, 1379],
];

// Portais a remover (case-insensitive). NÃO inclui "veja"/"exame" (false-positive verbo).
$portais = [
    'A Tarde', 'atarde', 'A TARDE',
    'g1 globo', 'g1 educação', 'g1 esportes', 'g1', 'G1',
    'Metrópoles', 'Metropoles',
    'BNews', 'B News',
    'Hora Brasil', 'horabrasil',
    'CNN Brasil', 'CNN Esportes', 'CNN',
    'Estadão', 'Estadao',
    'Folha de S.Paulo', 'Folha de São Paulo',
    'Valor Econômico',
    'Olhar Digital', 'olhardigital',
    'Tecnoblog', 'TechTudo',
    'Terra Esporte',
    'UOL Esporte', 'UOL Economia',
    'R7',
    'O Globo', 'oglobo',
    'Agência Brasil', 'Agencia Brasil',
    'Arena Rubro-Negra', 'arenarubronegra',
    'Meu Vitória', 'meuvitoria',
    'Correio 24h', 'Correio 24 horas',
    'AloAloBahia', 'Alô Alô Bahia',
    'Portal 6', 'portal6',
    'mktesportivo',
    'Jornal Correio',
    'Globo Esporte', 'ge.globo',
    'CBN',
];

function limparAtribuicao(string $html, array $portais): array {
    $stats = ['conforme' => 0, 'rodape' => 0, 'ver_materia' => 0, 'segundo' => 0, 'no_portal' => 0, 'materia_do' => 0, 'citation' => 0, 'mencao_avulsa' => 0];

    // Separa <script>/<style> e <a href> pra não tocar
    // Substituições aplicadas no $html cru — proteção de <script> embutida.

    // 1) "conforme apurou a redação ..."
    $html = preg_replace('/,\s*conforme\s+apurou\s+a\s+redação\s+a\s+partir\s+(?:de\s+matéria\s+(?:do|da|de\s+)?|do|da|de\s+)?[^.]+?(?=\.)/iu', '', $html, -1, $c); $stats['conforme'] += $c;
    $html = preg_replace('/,\s*conforme\s+apurado\s+pela\s+redação\s+a\s+partir\s+(?:do|da|de\s+|de\s+matéria\s+(?:do|da)?)?[^.]+?(?=\.)/iu', '', $html, -1, $c); $stats['conforme'] += $c;
    $html = preg_replace('/,?\s*conforme\s+apurou\s+a\s+redação\b/iu', '', $html, -1, $c); $stats['conforme'] += $c;
    $html = preg_replace('/,?\s*segundo\s+apurou\s+a\s+redação\b/iu', '', $html, -1, $c); $stats['conforme'] += $c;

    // 2) "<em>Fonte: ...</em>" rodapé inteiro — DENTRO de <p>
    $html = preg_replace('#<p[^>]*>\s*<em>\s*Fonte\s*:\s*[^<]*(?:<a[^>]*>[^<]*</a>[^<]*)?\s*</em>\s*</p>#iu', '', $html, -1, $c); $stats['rodape'] += $c;
    // 3) Sem <p> wrapper
    $html = preg_replace('#<em>\s*Fonte\s*:\s*[^<]*(?:<a[^>]*>[^<]*</a>[^<]*)?\s*</em>#iu', '', $html, -1, $c); $stats['rodape'] += $c;

    // 4) "Ver matéria original" link
    $html = preg_replace('/<a[^>]*>\s*Ver\s+mat[ée]ria\s+original\.?\s*<\/a>/iu', '', $html, -1, $c); $stats['ver_materia'] += $c;

    // 5) Schema "citation" key (objeto completo)
    $html = preg_replace('/,\s*"citation"\s*:\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/u', '', $html, -1, $c); $stats['citation'] += $c;

    // 6) "segundo o/a {portal}" → remove a referência
    foreach ($portais as $p) {
        $pRegex = preg_quote($p, '/');
        // ", segundo o A Tarde" / ", segundo o g1" etc — remove a porção
        $html = preg_replace('/,\s*segundo\s+(?:o\s+|a\s+)?(?:portal\s+|jornal\s+|site\s+)?' . $pRegex . '\b/iu', '', $html, -1, $c); $stats['segundo'] += $c;
    }

    // 7) "no portal {portal}" / "no jornal {portal}" / "no site {portal}"
    foreach ($portais as $p) {
        $pRegex = preg_quote($p, '/');
        $html = preg_replace('/,?\s*no\s+(?:portal|jornal|site)\s+' . $pRegex . '\b/iu', '', $html, -1, $c); $stats['no_portal'] += $c;
    }

    // 8) "matéria/reportagem do/no {portal}" / "publicada no {portal}" / "publicado em {portal}"
    foreach ($portais as $p) {
        $pRegex = preg_quote($p, '/');
        $html = preg_replace('/,?\s*(?:matéria|reportagem|publicação|publicado(?:\s+em|\s+pelo)?|publicada(?:\s+em|\s+pelo)?)\s+(?:do|da|de|no|na|em|pelo|pela)\s+' . $pRegex . '\b/iu', '', $html, -1, $c); $stats['materia_do'] += $c;
    }

    // 9) Menções avulsas residuais: "do jornal A Tarde", "do site g1", "do portal Olhar Digital"
    foreach ($portais as $p) {
        $pRegex = preg_quote($p, '/');
        $html = preg_replace('/\b(?:do|da|no|na|em)\s+(?:jornal|portal|site)\s+' . $pRegex . '\b/iu', '', $html, -1, $c); $stats['mencao_avulsa'] += $c;
    }

    // 10) Cleanup espaços/vírgulas órfãs
    $html = preg_replace('/\s+,/u', ',', $html);
    $html = preg_replace('/,\s*\./u', '.', $html);
    $html = preg_replace('/\.\s*,/u', '.', $html);
    $html = preg_replace('/  +/', ' ', $html);
    // 11) Parágrafos com apenas espaço/vazio
    $html = preg_replace('#<p[^>]*>\s*</p>#i', '', $html);

    return ['html' => $html, 'stats' => $stats];
}

$totalPosts = 0; $totalSubs = 0; $statsAcum = [];
foreach ($alvos as $slugSite => $ids) {
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $slugSite);
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);
    echo "\n════ {$slugSite} (" . count($ids) . " posts) ════\n";
    foreach ($ids as $pid) {
        $totalPosts++;
        try {
            $p = $wp->getPost($pid);
            $raw = (string)($p['content']['raw'] ?? '');
            if ($raw === '') { echo "  #{$pid}: vazio\n"; continue; }
            $res = limparAtribuicao($raw, $portais);
            $totalSub = array_sum($res['stats']);
            foreach ($res['stats'] as $k => $v) { $statsAcum[$k] = ($statsAcum[$k] ?? 0) + $v; }
            if ($totalSub === 0) { echo "  ✓ #{$pid}: já limpo (nenhum pattern bate)\n"; continue; }
            $totalSubs += $totalSub;
            $resumo = implode(' ', array_filter(array_map(fn($k,$v)=>$v>0?"{$k}={$v}":null, array_keys($res['stats']), $res['stats'])));
            if ($dryRun) { echo "  [DRY] #{$pid}: {$totalSub} subs ({$resumo})\n"; continue; }
            $r = $wp->atualizarPost($pid, ['content' => $res['html']]);
            $diff = strlen($raw) - strlen($res['html']);
            echo "  ✅ #{$pid}: {$totalSub} subs · -{$diff} chars · {$resumo}\n";
        } catch (Throwable $e) {
            echo "  ❌ #{$pid}: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n═════ RESUMO GERAL ═════\n";
echo "Posts processados: {$totalPosts}\n";
echo "Substituições totais: {$totalSubs}\n";
echo "Por tipo:\n";
foreach ($statsAcum as $k => $v) echo "  {$k}: {$v}\n";
if ($dryRun) echo "[DRY-RUN — nada aplicado]\n";
