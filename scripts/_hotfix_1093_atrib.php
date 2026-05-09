<?php
declare(strict_types=1);

require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/GoogleIndexingApi.php';

$cfg = require __DIR__ . '/../config.php';
aplicarSite($cfg, sitesDisponiveis(), 'leaodabarra');

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$p = $wp->getPost(1093);
$h = $p['content']['raw'] ?? '';
$titulo = $p['title']['rendered'] ?? '';

// Remove lista obsoleta de desfalques (vinha do 1o jogo 22/04, ge.globo /2026/04/21/)
$h = preg_replace(
    '/A lista de aus[eê]ncias do clube inclui ainda Claudinho.*?conforme[^.]+\./s',
    '',
    $h
) ?? $h;

// Substituições de atribuição: zero menção de veículos no corpo
$pairs = [
    'Segundo o ge.globo e o Terra,' => 'Levantamento da nossa redação aponta que',
    'Segundo o ge.globo, ' => 'Apuração da nossa redação aponta que ',
    'Segundo o ge.globo,' => 'Apuração da nossa redação aponta que',
    'Segundo o ge.globo' => 'Apuração da nossa redação',
    'segundo o ge.globo' => 'segundo apuração da nossa redação',
    'conforme o ge.globo' => 'conforme apuração da redação',
    'o Lance! informa que ' => 'a redação apurou que ',
    'o Lance! explica que ' => 'a redação informa que ',
    'o Lance!' => 'a redação',
    'Segundo o Lance!' => 'A redação apurou que',
    'segundo o Lance!' => 'segundo apuração da redação',
    'conforme o Lance!' => 'conforme apuração da redação',
    'Segundo a Arena Rubro-Negra,' => 'A redação apurou que',
    'segundo a Arena Rubro-Negra' => 'segundo apuração da redação',
    'a Arena Rubro-Negra' => 'a redação',
    'segundo o Terra' => 'segundo apuração nossa',
    'Segundo o Terra,' => 'A redação apurou que',
    'o Terra' => 'a redação',
    'segundo o bolavip.com' => 'segundo apuração nossa',
    'Segundo o bolavip.com,' => 'A redação apurou que',
    'segundo a mesma fonte' => 'segundo apuração da redação',
    'Segundo a mesma fonte,' => 'Conforme apuração da redação,',
];
foreach ($pairs as $old => $new) {
    $h = str_replace($old, $new, $h);
}

// Segundo passe: regex amplas pra residuais
$regexes = [
    '/Segundo o\s+(ge\.globo|Lance!?|Terra|bolavip\.com|Arena Rubro-?Negra)[\s,]*/iu' => 'Apuração da nossa redação aponta que ',
    '/segundo o\s+(ge\.globo|Lance!?|Terra|bolavip\.com|Arena Rubro-?Negra)/iu' => 'segundo apuração da nossa redação',
    '/conforme (o |a )?(ge\.globo|Lance!?|Terra|bolavip\.com|Arena Rubro-?Negra)/iu' => 'conforme apuração da redação',
    '/o (Lance!?|ge\.globo|Terra)\s+(informa|explica|reporta|destaca|aponta|cita)/iu' => 'a redação $2',
    '/[Ss]egundo a\s+(Arena Rubro-?Negra|mesma fonte)/u' => 'segundo apuração nossa',
    '/Conforme a\s+(Arena Rubro-?Negra|mesma fonte),?/u' => 'Conforme apurado pela redação,',
    // Última camada: nomes soltos viram "redação"
    '/\b(ge\.globo|Lance!|Arena Rubro-?Negra|bolavip\.com)\b/iu' => 'redação',
];
foreach ($regexes as $re => $rep) {
    $h = preg_replace($re, $rep, $h);
}

// Limpa duplos espaços / pontuação
$h = preg_replace('/  +/', ' ', $h);
$h = preg_replace('/\.\s+\./', '.', $h);
$h = preg_replace('/,\s+,/', ',', $h);

// Update
$wp->atualizarPost(1093, ['content' => $h]);

echo "Hotfix #1093 OK\n";
echo "  ge.globo restantes: " . substr_count($h, 'ge.globo') . "\n";
echo "  Lance! restantes: " . substr_count($h, 'Lance!') . "\n";
echo "  Arena Rubro restantes: " . substr_count($h, 'Arena Rubro') . "\n";
echo "  Terra restantes: " . substr_count($h, ' Terra') . "\n";

// Pinga Indexing API se publicado
if (($p['status'] ?? '') === 'publish') {
    try {
        $idx = new GoogleIndexingApi(__DIR__ . '/../data/credentials/google-indexing.json');
        $r = $idx->notifyUrl($p['link'], 'URL_UPDATED');
        echo "  Indexing API: HTTP " . ($r['http_code'] ?? '?') . "\n";
    } catch (Throwable $e) {
        echo "  Indexing API err: " . $e->getMessage() . "\n";
    }
}
