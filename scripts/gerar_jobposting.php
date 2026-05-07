<?php
/**
 * gerar_jobposting.php — pipeline 1-shot: scraper de URL fonte (Catho/etc) →
 * artigo editorial + schema JobPosting → publica WP → Google Indexing API.
 *
 * JobPosting é 1 dos 2 tipos OFICIAIS aceitos pelo Google Indexing API
 * (outro é BroadcastEvent). Indexação típica: minutos vs dias do crawl normal.
 *
 * Uso:
 *   php scripts/gerar_jobposting.php --url=URL_FONTE --site=vagasebeneficios
 *   php scripts/gerar_jobposting.php --url=URL --site=vagasebeneficios --dry-run
 */

declare(strict_types=1);

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$urlFonte = (string)($args['url'] ?? '');
$siteSlug = (string)($args['site'] ?? 'vagasebeneficios');
$dryRun = !empty($args['dry-run']);

if ($urlFonte === '' || !preg_match('#^https?://#', $urlFonte)) {
    fwrite(STDERR, "uso: php gerar_jobposting.php --url=URL --site=SLUG [--dry-run]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Scraper.php';
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/GoogleIndexingApi.php';

aplicarSite($cfg, sitesDisponiveis(), $siteSlug);

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  JobPosting Pipeline — site={$siteSlug}\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

// 1. Scrape
echo "→ [1/5] Scraping {$urlFonte}\n";
$scraper = new Scraper($cfg['user_agent'], (int)($cfg['scrape_timeout'] ?? 15));
try {
    $scrape = $scraper->fetch($urlFonte);
} catch (Throwable $e) {
    fwrite(STDERR, "✗ scrape falhou: " . $e->getMessage() . "\n");
    exit(1);
}

// 2. Extrair JSON-LD JobPosting da fonte (Catho/Vagas/Indeed costumam ter)
echo "→ [2/5] Extraindo JSON-LD JobPosting da fonte\n";
$jobLd = null;
$htmlBruto = file_get_contents($urlFonte);
if (preg_match_all('#<script[^>]*type=[\'"]application/ld\+json[\'"][^>]*>(.*?)</script>#is', (string)$htmlBruto, $mm)) {
    foreach ($mm[1] as $rawJson) {
        $j = json_decode(trim($rawJson), true);
        if (!is_array($j)) continue;
        $nodes = isset($j['@graph']) ? $j['@graph'] : [$j];
        foreach ($nodes as $n) {
            $type = $n['@type'] ?? '';
            if (is_array($type)) $type = implode(',', $type);
            if (preg_match('/JobPosting/i', $type)) { $jobLd = $n; break 2; }
        }
    }
}
if ($jobLd) {
    echo "   ✓ JobPosting encontrado: " . ($jobLd['title'] ?? '?') . "\n";
} else {
    echo "   ⚠ Sem JSON-LD JobPosting na fonte. Vou montar do zero baseado no scrape textual.\n";
}

// 3. Claude reescreve descrição editorial + extrai dados
echo "→ [3/5] Claude reescrevendo descrição editorial\n";
$claude = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model']);

$dadosFonte = [
    'titulo'    => $jobLd['title']           ?? $scrape['meta']['title'] ?? '',
    'empresa'   => $jobLd['hiringOrganization']['name'] ?? '',
    'localizacao' => '',
    'salario'   => '',
    'employmentType' => $jobLd['employmentType'] ?? '',
    'descricao' => '',
];
if (isset($jobLd['jobLocation'])) {
    $loc = $jobLd['jobLocation'];
    if (isset($loc[0])) $loc = $loc[0];
    $addr = $loc['address'] ?? [];
    $dadosFonte['localizacao'] = trim(
        ($addr['addressLocality'] ?? '') . ' / ' . ($addr['addressRegion'] ?? '') . ' / ' . ($addr['addressCountry'] ?? 'BR'),
        ' /'
    );
}
if (isset($jobLd['baseSalary'])) {
    $bs = $jobLd['baseSalary'];
    $val = $bs['value'] ?? [];
    if (isset($val['value'])) $dadosFonte['salario'] = "R$ " . $val['value'];
    elseif (isset($val['minValue']) && isset($val['maxValue'])) $dadosFonte['salario'] = "R$ {$val['minValue']} - R$ {$val['maxValue']}";
}
$dadosFonte['descricao'] = (string)($jobLd['description'] ?? implode("\n", array_slice($scrape['content']['paragraphs'] ?? [], 0, 8)));

// Prompt pro Claude reescrever em estilo vagasebeneficios
$systemPrompt = <<<EOT
Você é redator editorial do Vagas e Benefícios, especializado em vagas CLT, concursos e oportunidades de trabalho. Tom jornalístico de serviço público, didático sem paternalismo, foco em passo-a-passo acionável. NUNCA copia descrição literal — reescreve em PT-BR claro, factual.

Estrutura obrigatória do post:
1. P1 (45-65 palavras): lead 5W (quem contrata, cargo, local, prazo, salário se houver)
2. P2-P3: contexto da vaga (segmento, perfil ideal, requisitos principais)
3. <h2>Como se candidatar</h2>: passo-a-passo numerado
4. <h2>Sobre a empresa</h2>: 1-2 parágrafos
5. <h2>Detalhes da oportunidade</h2>: bullets com salário, benefícios, modalidade
6. Fechamento P1-frase com CTA "envie seu currículo via [URL fonte]"

Saída: apenas HTML limpo (sem ``` markdown). Use <p>, <h2>, <ul>, <li>, <strong>. Acentuação portuguesa completa.
EOT;

$userPrompt = "DADOS DA VAGA:\n"
    . "Título: {$dadosFonte['titulo']}\n"
    . "Empresa: {$dadosFonte['empresa']}\n"
    . "Localização: {$dadosFonte['localizacao']}\n"
    . "Salário: {$dadosFonte['salario']}\n"
    . "Tipo: {$dadosFonte['employmentType']}\n\n"
    . "DESCRIÇÃO ORIGINAL (reescreva em PT-BR jornalístico, sem copiar literal):\n"
    . substr($dadosFonte['descricao'], 0, 3500) . "\n\n"
    . "URL FONTE: {$urlFonte}\n\n"
    . "Reescreva em estilo Vagas e Benefícios. Saída só HTML.";

$resp = $claude->callPublic([['role' => 'user', 'content' => $userPrompt]], $systemPrompt, 2500);
$contentHtml = trim((string)($resp['content'][0]['text'] ?? ''));
if ($contentHtml === '') {
    fwrite(STDERR, "✗ Claude retornou vazio\n");
    exit(1);
}
echo "   ✓ Conteúdo gerado (" . str_word_count(strip_tags($contentHtml)) . " palavras)\n";

// 4. Monta schema JobPosting + payload WP
echo "→ [4/5] Montando schema JobPosting + payload WP\n";

$titulo = (string)$dadosFonte['titulo'] ?: 'Vaga: ' . substr(strip_tags($contentHtml), 0, 60);
$validThrough = date('c', strtotime('+30 days'));
$datePosted = date('c');

$jobSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'JobPosting',
    'title' => $titulo,
    'description' => $contentHtml,
    'datePosted' => $datePosted,
    'validThrough' => $validThrough,
    'employmentType' => $dadosFonte['employmentType'] ?: 'FULL_TIME',
    'hiringOrganization' => [
        '@type' => 'Organization',
        'name' => $dadosFonte['empresa'] ?: 'Empresa contratante (via Vagas e Benefícios)',
        'sameAs' => $urlFonte,
    ],
    'jobLocation' => [
        '@type' => 'Place',
        'address' => [
            '@type' => 'PostalAddress',
            'addressLocality' => $jobLd['jobLocation']['address']['addressLocality'] ?? '',
            'addressRegion' => $jobLd['jobLocation']['address']['addressRegion'] ?? '',
            'addressCountry' => 'BR',
        ],
    ],
    'directApply' => false,
    'url' => $urlFonte,
];
if ($dadosFonte['salario']) {
    $jobSchema['baseSalary'] = [
        '@type' => 'MonetaryAmount',
        'currency' => 'BRL',
        'value' => [
            '@type' => 'QuantitativeValue',
            'value' => (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', explode('-', $dadosFonte['salario'])[0])),
            'unitText' => 'MONTH',
        ],
    ];
}

$contentComSchema = $contentHtml . "\n\n<script type=\"application/ld+json\">\n"
    . json_encode($jobSchema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    . "\n</script>\n";

$slug = trim(preg_replace('/[^a-z0-9-]/', '-', strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $titulo))), '-');
$slug = substr($slug, 0, 70);

$payload = [
    'title' => $titulo,
    'slug' => $slug,
    'content' => $contentComSchema,
    'status' => 'publish',
    'meta' => [
        'rank_math_focus_keyword' => mb_strtolower($titulo),
        'rank_math_title' => "{$titulo} | Vagas e Benefícios",
        'rank_math_description' => "Vaga aberta: " . substr(strip_tags($contentHtml), 0, 150),
    ],
];
if (!empty($cfg['default_post_author_id'])) {
    $payload['author'] = (int)$cfg['default_post_author_id'];
}

if ($dryRun) {
    echo "\n[DRY-RUN] Payload + Schema preview:\n";
    echo "  Título: {$titulo}\n";
    echo "  Slug:   {$slug}\n";
    echo "  Author: " . ($payload['author'] ?? 'default') . "\n";
    echo "  Schema datePosted/validThrough: {$datePosted} → {$validThrough}\n";
    echo "  Schema empresa: " . ($jobSchema['hiringOrganization']['name']) . "\n";
    echo "\nPRIMEIRAS 800 chars do content+schema:\n";
    echo substr($contentComSchema, 0, 800) . "...\n";
    exit(0);
}

// 5. Publica WP + indexing API
echo "→ [5/5] Publicando WP + Google Indexing API\n";
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
try {
    $r = $wp->criarPost($payload);
    $postId = (int)($r['id'] ?? 0);
    $linkPub = (string)($r['link'] ?? '');
    if ($postId === 0) throw new RuntimeException('post não criado');
    echo "   ✓ Post WP #{$postId} publicado: {$linkPub}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "✗ falha publicar WP: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    $idx = new GoogleIndexingApi(__DIR__ . '/../data/credentials/google-indexing.json');
    $rIdx = $idx->notifyUrl($linkPub, 'URL_UPDATED');
    if ($rIdx['success']) {
        echo "   ✓ Google Indexing API: HTTP {$rIdx['http_code']}\n";
    } else {
        echo "   ⚠ Indexing API erro: {$rIdx['error']}\n";
    }
} catch (Throwable $e) {
    echo "   ⚠ Indexing API falhou (post já está publicado): " . $e->getMessage() . "\n";
}

echo "\n═══ RESUMO ═══\n";
echo "  post_id:    {$postId}\n";
echo "  link:       {$linkPub}\n";
echo "  fonte:      {$urlFonte}\n";
echo "  schema:     JobPosting com validThrough={$validThrough}\n";
echo "  empresa:    {$jobSchema['hiringOrganization']['name']}\n";
echo "  salario:    " . ($dadosFonte['salario'] ?: '—') . "\n";
echo "\nValide o JobPosting em https://search.google.com/test/rich-results?url=" . urlencode($linkPub) . "\n";
