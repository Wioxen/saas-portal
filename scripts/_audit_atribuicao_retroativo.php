<?php
declare(strict_types=1);
/**
 * Audit retroativo: detecta atribuição derivada a portais jornalísticos
 * em posts publicados. Aponta quais posts violam a regra editorial
 * (autoria = redação do site, scrape alimenta brain mas não vira atribuição).
 *
 * Detecta padrões:
 *   - "conforme apurou a redação a partir de..."
 *   - "segundo o {portal}"
 *   - "<em>Fonte: ...</em>" rodapé
 *   - "matéria/reportagem do {portal}"
 *   - "publicado em {portal}"
 *   - Schema JSON-LD com "citation" apontando pra portal jornalístico
 *
 * Preserva entidades INSTITUCIONAIS (gov.br, Inep, MEC, Alesp, etc) como
 * fonte primária legítima.
 *
 * Uso:
 *   php scripts/_audit_atribuicao_retroativo.php
 *   php scripts/_audit_atribuicao_retroativo.php --site=cursosenac --dias=30 --max=50
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

$opts = getopt('', ['site::', 'dias::', 'max::']);
$siteFiltro = (string)($opts['site'] ?? '');
$dias = (int)($opts['dias'] ?? 30);
$max  = (int)($opts['max'] ?? 30);

// Portais derivativos a detectar (NÃO citar)
$portaisDerivativos = [
    'g1', 'g1 globo', 'g1 educação', 'g1 esportes',
    'a tarde', 'atarde',
    'metrópoles', 'metropoles',
    'bnews', 'b news',
    'hora brasil', 'horabrasil',
    'cnn brasil', 'cnn esportes', 'cnnbrasil',
    'estadão', 'estadao',
    'folha de s.paulo', 'folha de são paulo',
    'valor econômico', 'valor.globo',
    'exame',
    'olhar digital', 'olhardigital',
    'tecnoblog', 'techtudo',
    'terra esporte', 'terra.com.br',
    'uol esporte', 'uol economia',
    'r7',
    'o globo', 'oglobo',
    'agência brasil', 'agencia brasil',
    'arena rubro-negra', 'arenarubronegra',
    'meu vitória', 'meuvitoria',
    'correio 24h', 'correio24horas',
    'aloalobahia', 'alô alô bahia',
    'portal 6', 'portal6',
    'mktesportivo',
    'jornal correio',
    'veja',
    'globo esporte', 'ge.globo',
    'cbn',
];

// Entidades INSTITUCIONAIS — citação permitida (não conta como violação)
$instWhitelist = [
    'inep', 'mec', 'gov.br', 'alesp', 'semob', 'cecierj', 'cederj',
    'caixa', 'inss', 'mte', 'anvisa', 'cbf', 'fifa', 'ibge', 'tse', 'stf',
    'senado', 'câmara', 'congresso', 'planalto', 'fazenda', 'ministério',
    'secretaria', 'prefeitura', 'governo', 'universidade federal', 'ifsp',
    'ifma', 'ifrn', 'unirio', 'unicamp', 'usp', 'uerj', 'ufrj', 'uece',
    'urca', 'iff', 'ifsuldeminas',
];

function detectarViolacoes(string $html, array $portais, array $whitelist): array {
    $violacoes = [];

    // Separa <script>/<style> do texto visível
    $textoVisivel = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);

    // 1) Conforme apurou
    if (preg_match('/conforme\s+(?:apurou|apurado)/iu', $textoVisivel)) $violacoes[] = "conforme-apurou";

    // 2) Rodapé "<em>Fonte:" or "<p><strong>Fonte:" or footnote patterns
    if (preg_match('#<(?:em|strong|p)[^>]*>\s*Fonte\s*:#iu', $textoVisivel)) $violacoes[] = "rodape-fonte";

    // 3) Link "ver matéria original"
    if (preg_match('/ver\s+mat[ée]ria\s+original/iu', $textoVisivel)) $violacoes[] = "link-ver-materia-original";

    // 4) Schema citation (em <script>) apontando pra portal
    if (preg_match_all('/"citation"\s*:\s*\{[^{}]*"(?:name|url)"\s*:\s*"([^"]+)"/u', $html, $m)) {
        foreach ($m[1] as $captured) {
            $low = mb_strtolower($captured);
            $isInst = false;
            foreach ($whitelist as $w) { if (str_contains($low, $w)) { $isInst = true; break; } }
            if (!$isInst) $violacoes[] = "schema-citation";
        }
    }

    // 5) Qualquer menção LITERAL de portal no texto visível (excluindo URLs em <a>)
    //    Strip tags <a> (que podem ter href com domínio portal — não conta como atribuição visual)
    $semLinks = preg_replace('#<a\b[^>]*>.*?</a>#is', '', $textoVisivel);
    $semHtml = strip_tags($semLinks);
    $low = mb_strtolower($semHtml);
    $portaisDetectados = [];
    foreach ($portais as $p) {
        if (str_contains($low, mb_strtolower($p))) {
            $portaisDetectados[] = $p;
        }
    }
    if (!empty($portaisDetectados)) {
        // Lista até 3 portais detectados
        $violacoes[] = "menção-portal:" . implode(',', array_slice($portaisDetectados, 0, 3));
    }

    return array_unique($violacoes);
}

$lista = $siteFiltro !== '' ? [$siteFiltro] : ['vagasebeneficios', 'cursosenac', 'guiadoscursos', 'comocomprar', 'ondecompraragora', 'leaodabarra'];

$totalGeral = 0;
$totalViolacoes = 0;
$comViolacao = [];

foreach ($lista as $site) {
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $site);
    $base = rtrim($cfgSite['wp_url'], '/') . '/wp-json/wp/v2';
    $auth = base64_encode("{$cfgSite['wp_user']}:{$cfgSite['wp_app_password']}");
    $after = date('Y-m-d\TH:i:s', time() - $dias * 86400);
    $url = "{$base}/posts?per_page={$max}&orderby=date&order=desc&after=" . urlencode($after) . "&status=publish,draft&context=edit&_fields=id,title,link,content,status,date";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth],
    ]);
    $body = curl_exec($ch); curl_close($ch);
    $posts = json_decode((string)$body, true);
    if (!is_array($posts)) {
        echo "[{$site}] ERRO ao listar posts\n";
        continue;
    }

    $siteViolacoes = 0;
    $sitePostsViolando = 0;
    echo "\n══════════ {$site} (últimos {$dias} dias · {$max} posts) ══════════\n";
    foreach ($posts as $p) {
        $totalGeral++;
        $pid = (int)$p['id'];
        $raw = (string)($p['content']['raw'] ?? '');
        if ($raw === '') continue;
        $vios = detectarViolacoes($raw, $portaisDerivativos, $instWhitelist);
        if (empty($vios)) continue;
        $sitePostsViolando++;
        $siteViolacoes += count($vios);
        $totalViolacoes += count($vios);
        $tit = trim(html_entity_decode(strip_tags((string)($p['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        echo "  🚨 #{$pid} [{$p['status']}] " . mb_substr($tit, 0, 90) . "\n";
        foreach ($vios as $v) echo "       · {$v}\n";
        $comViolacao[$site][] = $pid;
    }
    echo "  ── {$sitePostsViolando} posts c/ violação · {$siteViolacoes} violações totais\n";
}

echo "\n══════════ RESUMO GERAL ══════════\n";
echo "Posts auditados: {$totalGeral}\n";
echo "Total de violações: {$totalViolacoes}\n";
echo "Por site:\n";
foreach ($comViolacao as $s => $ids) {
    echo "  {$s}: " . count($ids) . " posts c/ violação → IDs: " . implode(',', $ids) . "\n";
}
