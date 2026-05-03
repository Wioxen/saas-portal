<?php
declare(strict_types=1);
/**
 * Substitui qualquer JSON-LD existente em um post pelo schema correto:
 *   - NewsArticle (sempre — pra notícia esportiva)
 *   - SportsEvent COMPLETO (se --jogo-id informado e jogo no JSON)
 *
 * Resolve avisos GSC tipo "campo X não foi encontrado em location/event".
 *
 * Uso:
 *   php scripts/fix_schema_post.php --site=leaodabarra --post-id=810 --jogo-id=2026-05-02-vit-cfc
 *   php scripts/fix_schema_post.php --site=leaodabarra --post-id=811   (só NewsArticle, sem SportsEvent)
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';

$opts = getopt('', ['site::', 'post-id::', 'jogo-id::', 'dry-run']);
$siteSlug = (string)($opts['site'] ?? 'leaodabarra');
$postId   = (int)($opts['post-id'] ?? 0);
$jogoId   = (string)($opts['jogo-id'] ?? '');
$dryRun   = isset($opts['dry-run']);
if ($postId <= 0) { fwrite(STDERR, "uso: --post-id=N [--jogo-id=ID]\n"); exit(2); }

$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteSlug);

$base = rtrim($cfg['wp_url'], '/') . '/wp-json/wp/v2';
$auth = base64_encode("{$cfg['wp_user']}:{$cfg['wp_app_password']}");

function wpReq(string $m, string $u, string $a, ?array $p = null): array {
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m,
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $a, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($p !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($c >= 400) throw new RuntimeException("HTTP {$c}: " . substr((string)$b, 0, 300));
    return json_decode((string)$b, true) ?: [];
}

// 1. Lê post + featured image
$post = wpReq('GET', "{$base}/posts/{$postId}?context=edit&_embed=wp:featuredmedia", $auth);
$titulo = trim(html_entity_decode(strip_tags((string)($post['title']['raw'] ?? $post['title']['rendered'] ?? '')), ENT_QUOTES, 'UTF-8'));
$content = (string)($post['content']['raw'] ?? $post['content']['rendered'] ?? '');
$datePub = (string)($post['date'] ?? '');
$dateMod = (string)($post['modified'] ?? $datePub);
$linkPub = (string)($post['link'] ?? "{$cfg['wp_url']}/?p={$postId}");
$imageUrl = (string)($post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '');
$descricao = trim(strip_tags(mb_substr(strip_tags($content), 0, 200)));

echo "[post] #{$postId} '{$titulo}'\n";
echo "[image] {$imageUrl}\n";

// 2. NewsArticle — SÓ se RankMath não está handling (evita duplicação no <head>)
$rankmathHandles = !empty($cfg['rankmath_handles_schemas']);
$schemas = [];

if (!$rankmathHandles) {
    $newsArticle = [
    '@context' => 'https://schema.org',
    '@type'    => 'NewsArticle',
    'headline' => mb_substr($titulo, 0, 110),
    'description' => $descricao,
    'image' => $imageUrl ? [$imageUrl] : [],
    'datePublished' => $datePub,
    'dateModified' => $dateMod,
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $linkPub],
    'author' => [[
        '@type' => 'Person',
        'name'  => 'Equipe Leão da Barra',
        'url'   => rtrim($cfg['wp_url'], '/') . '/sobre/',
    ]],
    'publisher' => [
        '@type' => 'Organization',
        'name'  => $cfg['site_name'] ?? 'Leão da Barra',
        'url'   => $cfg['wp_url'],
        'logo'  => [
            '@type' => 'ImageObject',
            'url'   => rtrim($cfg['wp_url'], '/') . '/wp-content/uploads/logo.png',
        ],
    ],
    ];
    $schemas[] = $newsArticle;
} else {
    echo "[skip] NewsArticle pulado (rankmath_handles_schemas=true — RankMath gera no <head>)\n";
}

// 3. SportsEvent COMPLETO se --jogo-id
if ($jogoId !== '') {
    $jogos = json_decode((string)file_get_contents(__DIR__ . '/../data/jogos_vitoria.json'), true);
    $jogo = null;
    foreach (($jogos['jogos'] ?? []) as $j) if (($j['id'] ?? '') === $jogoId) { $jogo = $j; break; }
    if (!$jogo) { fwrite(STDERR, "[erro] jogo-id '{$jogoId}' não está em data/jogos_vitoria.json\n"); exit(3); }

    $startISO = "{$jogo['data']}T{$jogo['hora']}:00-03:00";
    $endTs = strtotime("{$jogo['data']} {$jogo['hora']} +110 minutes");  // futebol = 90min + acréscimos
    $endISO = date('Y-m-d\TH:i:s-03:00', $endTs);

    $isFinalizado = ($jogo['status'] ?? '') === 'finalizado';
    $eventStatus = $isFinalizado ? 'EventCompleted' : 'EventScheduled';

    // Endereço Barradão fixo (estádio do Vitória) — outros estádios cairiam num fallback
    $isBarradao = stripos($jogo['estadio'] ?? '', 'Barradão') !== false || stripos($jogo['estadio'] ?? '', 'Manoel Barradas') !== false;
    $address = $isBarradao
        ? [
            '@type' => 'PostalAddress',
            'streetAddress'   => 'Rua Eduardo Diniz Gonçalves, 1024 — Canabrava',
            'addressLocality' => 'Salvador',
            'addressRegion'   => 'BA',
            'postalCode'      => '41250-410',
            'addressCountry'  => 'BR',
        ]
        : [
            '@type' => 'PostalAddress',
            'addressCountry' => 'BR',
        ];

    $vitoriaTeam = ['@type' => 'SportsTeam', 'name' => 'Esporte Clube Vitória', 'sport' => 'Soccer'];
    $advTeam     = ['@type' => 'SportsTeam', 'name' => $jogo['adversario']['nome'], 'sport' => 'Soccer'];
    $isVitoriaCasa = ($jogo['mando'] ?? '') === 'casa';

    $sportsEvent = [
        '@context' => 'https://schema.org',
        '@type'    => 'SportsEvent',
        'name'     => "Vitória x {$jogo['adversario']['nome']}",
        'description' => $titulo,
        'image'    => $imageUrl ? [$imageUrl] : [],
        'startDate' => $startISO,
        'endDate'   => $endISO,
        'eventStatus' => "https://schema.org/{$eventStatus}",
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'location' => [
            '@type' => 'Place',
            'name'  => $jogo['estadio'] ?: 'Estádio',
            'address' => $address,
        ],
        'homeTeam' => $isVitoriaCasa ? $vitoriaTeam : $advTeam,
        'awayTeam' => $isVitoriaCasa ? $advTeam : $vitoriaTeam,
        'performer' => [$vitoriaTeam, $advTeam],
        'organizer' => [
            '@type' => 'SportsOrganization',
            'name'  => stripos($jogo['competicao'] ?? '', 'Nordeste') !== false ? 'CBF (Copa do Nordeste)' : 'CBF',
            'url'   => 'https://www.cbf.com.br',
        ],
        'offers' => [
            '@type' => 'Offer',
            'url'   => $linkPub,
            'availability' => $isFinalizado ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
            'validFrom' => date('Y-m-d\T00:00:00-03:00', strtotime("{$jogo['data']} -7 days")),
            'price' => '0',
            'priceCurrency' => 'BRL',
        ],
    ];

    // Se finalizado e tem placar, adiciona resultado
    if ($isFinalizado && isset($jogo['placar']['vitoria'], $jogo['placar']['adversario'])) {
        $sportsEvent['homeTeam']['name'] = $isVitoriaCasa ? 'Esporte Clube Vitória' : $jogo['adversario']['nome'];
        $sportsEvent['name'] = $isVitoriaCasa
            ? "Vitória {$jogo['placar']['vitoria']} x {$jogo['placar']['adversario']} {$jogo['adversario']['nome']}"
            : "{$jogo['adversario']['nome']} {$jogo['placar']['adversario']} x {$jogo['placar']['vitoria']} Vitória";
    }

    $schemas[] = $sportsEvent;
}

// 4. Remove qualquer JSON-LD existente do conteúdo
$contentLimpo = preg_replace('#<script\s+type=["\']application/ld\+json["\'][^>]*>.*?</script>#is', '', $content) ?? $content;
$contentLimpo = rtrim($contentLimpo);

// 5. Anexa schemas novos
$blocoSchemas = '';
foreach ($schemas as $s) {
    $blocoSchemas .= "\n<script type=\"application/ld+json\">" . json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>";
}

$contentNovo = $contentLimpo . "\n" . $blocoSchemas;

echo "[schemas] vai gravar " . count($schemas) . " blocos JSON-LD (NewsArticle" . ($jogoId !== '' ? " + SportsEvent" : '') . ")\n";

if ($dryRun) {
    echo "\n--- preview schema novo ---\n";
    echo $blocoSchemas . "\n";
    echo "\n[dry-run] sem gravar\n";
    exit(0);
}

// 6. Atualiza post
wpReq('POST', "{$base}/posts/{$postId}", $auth, ['content' => $contentNovo]);
echo "[ok] post #{$postId} atualizado\n";
echo "  Validar em: https://search.google.com/test/rich-results?url=" . urlencode($linkPub) . "\n";
