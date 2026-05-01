<?php
/**
 * Smoke test do Caminho C — Híbrido Especializado.
 * Valida:
 *   1. sites.php tem empresa/subtipo_nicho/termos_canibal nos 6 sites
 *   2. Sistema 2 = 3 sites, Sistema 3 = 3 sites
 *   3. PrePublishLint::avaliar rejeita termo canibal (cursosenac falando de INSS)
 *   4. PrePublishLint::avaliar rejeita similaridade cross-site >60%
 *   5. DiscoverSchemas::organization() inclui parentOrganization quando empresa setada
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/PrePublishLint.php';
require_once $rootDir . '/lib/DiscoverSchemas.php';
require_once $rootDir . '/lib/DiscoverDb.php';

$ok = 0;
$fail = 0;
$warn = 0;

function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) {
        echo "  [OK]   {$label}\n";
        $ok++;
    } else {
        echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n";
        $fail++;
    }
}

echo "\n=== TESTE 1: sites.php — campos empresa / subtipo_nicho / termos_canibal ===\n";
$sites = require $rootDir . '/sites.php';
$slugsEsperados = ['comocomprar', 'vagasebeneficios', 'cursosenac', 'guiadoscursos', 'leaodabarra', 'ondecompraragora'];

foreach ($slugsEsperados as $slug) {
    check("site '{$slug}' existe", isset($sites[$slug]));
    if (!isset($sites[$slug])) continue;
    $s = $sites[$slug];
    check("  {$slug}.empresa.nome", !empty($s['empresa']['nome']));
    check("  {$slug}.empresa.descricao", !empty($s['empresa']['descricao']));
    check("  {$slug}.subtipo_nicho", !empty($s['subtipo_nicho']));
    check("  {$slug}.termos_canibal[] >= 3", !empty($s['termos_canibal']) && is_array($s['termos_canibal']) && count($s['termos_canibal']) >= 3);
}

echo "\n=== TESTE 2: divisão Sistema 2 (3 sites) vs Sistema 3 (3 sites) ===\n";
$grupos = [];
foreach ($sites as $slug => $s) {
    $emp = (string)($s['empresa']['nome'] ?? '');
    if ($emp === '') continue;
    $grupos[$emp][] = $slug;
}
check("Sistema 2 Conteúdo Educacional tem 3 sites", count($grupos['Sistema 2 Conteúdo Educacional'] ?? []) === 3,
    'sistema2=' . json_encode($grupos['Sistema 2 Conteúdo Educacional'] ?? []));
check("Sistema 3 Mídia Digital tem 4 sites", count($grupos['Sistema 3 Mídia Digital'] ?? []) === 4,
    'sistema3=' . json_encode($grupos['Sistema 3 Mídia Digital'] ?? []));

$esperaS2 = ['vagasebeneficios', 'cursosenac', 'guiadoscursos'];
$esperaS3 = ['comocomprar', 'leaodabarra', 'ondecompraragora', 'vafast'];
sort($esperaS2); sort($esperaS3);
$realS2 = $grupos['Sistema 2 Conteúdo Educacional'] ?? []; sort($realS2);
$realS3 = $grupos['Sistema 3 Mídia Digital'] ?? []; sort($realS3);
check("Sistema 2 contém {vagasebeneficios, cursosenac, guiadoscursos}", $realS2 === $esperaS2);
check("Sistema 3 contém {comocomprar, leaodabarra, ondecompraragora, vafast}", $realS3 === $esperaS3);

echo "\n=== TESTE 3: pre-flight rejeita canibal cruzado ===\n";
// cursosenac NÃO pode publicar sobre INSS (pertence a vagasebeneficios)
$cfgCurso = $sites['cursosenac'];
$cfgCurso['_site_slug'] = 'cursosenac';
$trendInss = [
    'termo' => 'inss libera revisão de aposentadoria em abril',
    'site'  => 'cursosenac',
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'Notícias'],
];
$fontesValidas = [
    ['fonte' => ['content' => ['paragraphs' => [str_repeat('texto ', 200)]]]]
];
$resultado = PrePublishLint::avaliar($trendInss, $fontesValidas, null, 50, $cfgCurso);
check("cursosenac × 'inss libera revisão' → REJEITADO", !$resultado['aprovado'] && in_array('canibal_cruzado', $resultado['motivos']),
    'motivos=' . json_encode($resultado['motivos']));
check("  motivo retorna termo_canibal nos detalhes", !empty($resultado['detalhes']['termo_canibal']));
check("  motivo retorna empresa_grupo nos detalhes", !empty($resultado['detalhes']['empresa_grupo']));

// vagasebeneficios NÃO pode publicar sobre FIES (pertence a guiadoscursos)
$cfgVagas = $sites['vagasebeneficios'];
$cfgVagas['_site_slug'] = 'vagasebeneficios';
$trendFies = [
    'termo' => 'fies abre nova rodada de contratos em maio',
    'site'  => 'vagasebeneficios',
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'Notícias'],
];
$resultado = PrePublishLint::avaliar($trendFies, $fontesValidas, null, 50, $cfgVagas);
check("vagasebeneficios × 'fies abre nova rodada' → REJEITADO", !$resultado['aprovado'] && in_array('canibal_cruzado', $resultado['motivos']));

// Termo legítimo do próprio site PASSA (vagasebeneficios falando de INSS)
$trendLegitimo = [
    'termo' => 'inss antecipa pagamento do 13o salário',
    'site'  => 'vagasebeneficios',
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'Notícias'],
];
$resultado = PrePublishLint::avaliar($trendLegitimo, $fontesValidas, null, 50, $cfgVagas);
check("vagasebeneficios × 'inss antecipa 13o' → APROVADO (não é canibal)", $resultado['aprovado'],
    'motivos=' . json_encode($resultado['motivos']));

echo "\n=== TESTE 4: cross-site dedup (mock DB com sister site) ===\n";
// Cria DB temporário em arquivo separado pra não interferir com o real.
$dbFile = sys_get_temp_dir() . '/test_cross_site_' . uniqid() . '.json';
$db = new DiscoverDb($dbFile);
// Insere post publicado em guiadoscursos (irmão de cursosenac e vagasebeneficios)
$db->upsert([
    'site' => 'guiadoscursos',
    'termo' => 'enem 2026 abre inscrição com isenção',
    'status' => 'publicado',
    'url_post' => 'https://guiadoscursos.com/enem-2026-isencao',
]);

// Agora cursosenac tenta publicar termo MUITO similar
$trendSimilar = [
    'termo' => 'enem 2026 abre inscrições com isenção de taxa',
    'site'  => 'cursosenac',
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'Notícias'],
];
$resultado = PrePublishLint::avaliar($trendSimilar, $fontesValidas, $db, 50, $cfgCurso);
check("cursosenac similar (>60%) ao guiadoscursos → REJEITADO canibal_intra_rede",
    !$resultado['aprovado'] && in_array('canibal_intra_rede', $resultado['motivos']),
    'motivos=' . json_encode($resultado['motivos']) . ' sim=' . ($resultado['detalhes']['cross_sim_max'] ?? '?'));
check("  detalhes.cross_match_site = guiadoscursos", ($resultado['detalhes']['cross_match_site'] ?? '') === 'guiadoscursos');

// Termo TOTALMENTE diferente passa
$trendDiff = [
    'termo' => 'senac abre 2 mil vagas em curso de auxiliar de saúde bucal',
    'site'  => 'cursosenac',
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'Notícias'],
];
$resultado = PrePublishLint::avaliar($trendDiff, $fontesValidas, $db, 50, $cfgCurso);
check("cursosenac termo único (sem sister match) → APROVADO", $resultado['aprovado'],
    'motivos=' . json_encode($resultado['motivos']));

// Cross-site NÃO bloqueia entre Sistema 2 e Sistema 3 (são empresas diferentes)
$db->upsert([
    'site' => 'comocomprar',
    'termo' => 'enem 2026 abre inscrição com isenção',  // sim, mesmo termo
    'status' => 'publicado',
]);
$resultado = PrePublishLint::avaliar([
    'termo' => 'enem 2026 abre inscrição com isenção de taxa',
    'site'  => 'cursosenac',
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'Notícias'],
], $fontesValidas, $db, 50, $cfgCurso);
// guiadoscursos é irmão de cursosenac → bloqueia. comocomprar é Sistema 3 → não importa.
// Continua sendo bloqueado pelo guiadoscursos, mas o motivo deve ser canibal_intra_rede
// (vindo do match com guiadoscursos).
check("comocomprar (Sistema 3) NÃO bloqueia cursosenac (Sistema 2)",
    ($resultado['detalhes']['cross_match_site'] ?? '') !== 'comocomprar');

@unlink($dbFile);

echo "\n=== TESTE 3b: termos_canibal normalização (acentos + plural) ===\n";
// Acento: "Aposentadoria" deve bater "aposentadoria" (declarado em vagasebeneficios canibal de cursosenac)
$cfgCurso = $sites['cursosenac'];
$cfgCurso['_site_slug'] = 'cursosenac';

$trendAcento = [
    'termo' => 'INSS revisão de Aposentadoria pra quem nasceu antes de 1965',
    'site'  => 'cursosenac',
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'Notícias'],
];
$res = PrePublishLint::avaliar($trendAcento, $fontesValidas, null, 50, $cfgCurso);
check("acento: 'Aposentadoria' (case+acento) bate 'aposentadoria' canibal",
    !$res['aprovado'] && in_array('canibal_cruzado', $res['motivos']));

// Plural: "cursos senac" deve bater "curso senac" (declarado em vagasebeneficios)
$cfgVagas = $sites['vagasebeneficios'];
$cfgVagas['_site_slug'] = 'vagasebeneficios';
$trendPlural = [
    'termo' => 'cursos Senac abertos pra inscrição em Salvador',
    'site'  => 'vagasebeneficios',
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'Notícias'],
];
$res = PrePublishLint::avaliar($trendPlural, $fontesValidas, null, 50, $cfgVagas);
check("plural: 'cursos Senac' bate 'curso senac' canibal",
    !$res['aprovado'] && in_array('canibal_cruzado', $res['motivos']),
    'motivos=' . json_encode($res['motivos']));

// Inverso: "curso senac" deve bater "cursos senac" (singular bate plural declarado)
// (não tem plural declarado nos termos atuais; teste apenas que match direto também rola)
$trendDireto = [
    'termo' => 'inscrição enem 2026 abre amanhã',
    'site'  => 'vagasebeneficios',
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'Notícias'],
];
// "ENEM 2026" não está em vagasebeneficios.termos_canibal; deve passar
$res = PrePublishLint::avaliar($trendDireto, $fontesValidas, null, 50, $cfgVagas);
// Mas ENEM Inep está em guiadoscursos.termos_canibal — não importa pra vagasebeneficios
check("vagasebeneficios + 'enem 2026' → APROVADO (ENEM não está em seus canibais)",
    $res['aprovado'], 'motivos=' . json_encode($res['motivos'] ?? []));

echo "\n=== TESTE 4b: subtipo_nicho + termos_canibal nos prompts LLM ===\n";
// Testa via source (não instancia clients que precisam de API key real)
$srcSonnet = file_get_contents($rootDir . '/lib/DiscoverGerador.php');
$srcGpt    = file_get_contents($rootDir . '/lib/DiscoverGeradorGPT.php');

check("Sonnet (DiscoverGerador) injeta SUBTIPO NICHO no bloco persona",
    strpos($srcSonnet, 'SUBTIPO NICHO:') !== false);
check("Sonnet menciona EDITORA no prompt",
    strpos($srcSonnet, 'EDITORA:') !== false);
check("Sonnet menciona TERMOS DE OUTROS SITES IRMÃOS",
    strpos($srcSonnet, 'TERMOS DE OUTROS SITES IRMÃOS') !== false);
check("Sonnet lê cfg['subtipo_nicho']",
    strpos($srcSonnet, "cfg['subtipo_nicho']") !== false || strpos($srcSonnet, "this->cfg['subtipo_nicho']") !== false);
check("Sonnet lê cfg['termos_canibal']",
    strpos($srcSonnet, "termos_canibal") !== false);
check("Sonnet lê cfg['empresa']['nome']",
    strpos($srcSonnet, "empresa']['nome']") !== false || strpos($srcSonnet, 'empresa.nome') !== false);

check("GPT (DiscoverGeradorGPT) injeta SUBTIPO NICHO no bloco persona",
    strpos($srcGpt, 'SUBTIPO NICHO:') !== false);
check("GPT menciona EDITORA",
    strpos($srcGpt, 'EDITORA:') !== false);
check("GPT menciona 'EVITE (são de sites irmãos)'",
    strpos($srcGpt, 'EVITE (são de sites irmãos)') !== false);
check("GPT lê cfg['subtipo_nicho']",
    strpos($srcGpt, "cfg['subtipo_nicho']") !== false || strpos($srcGpt, "this->cfg['subtipo_nicho']") !== false);
check("GPT lê cfg['termos_canibal']",
    strpos($srcGpt, "termos_canibal") !== false);

echo "\n=== TESTE 5: DiscoverSchemas::organization() com empresa ===\n";
$cfgCurso2 = $sites['cursosenac'];
$cfgCurso2['_site_slug'] = 'cursosenac';
$cfgCurso2['_site_name'] = 'Curso SENAC';
$schemaJson = DiscoverSchemas::gerar(
    ['titulo' => 'Senac abre 2 mil vagas', 'url' => 'https://cursosenacgratuito.com.br/teste'],
    ['cluster_detect' => ['key' => 'noticias_info_critica', 'nome' => 'Notícias'], 'termo' => 'senac vagas'],
    $cfgCurso2
);
check("schema gerado", $schemaJson !== '');
$jsonInline = preg_match('/<script[^>]*>(.*?)<\/script>/s', $schemaJson, $m) ? $m[1] : '';
$payload = json_decode(trim($jsonInline), true);
check("schema é JSON válido", is_array($payload) && isset($payload['@graph']));

$organization = null;
foreach ($payload['@graph'] ?? [] as $node) {
    if (($node['@type'] ?? '') === 'Organization') { $organization = $node; break; }
}
check("Organization presente em @graph", $organization !== null);
check("Organization.parentOrganization.name = Sistema 2 Conteúdo Educacional",
    isset($organization['parentOrganization']['name']) && $organization['parentOrganization']['name'] === 'Sistema 2 Conteúdo Educacional');
check("Organization.knowsAbout = subtipo_nicho",
    isset($organization['knowsAbout']) && stripos($organization['knowsAbout'], 'cursos técnicos') !== false);
check("Organization.description usa empresa.descricao",
    isset($organization['description']) && stripos($organization['description'], 'cursos técnicos, EAD profissionalizante') !== false);

// Sistema 3 — leaodabarra
$cfgLeao = $sites['leaodabarra'];
$cfgLeao['_site_slug'] = 'leaodabarra';
$cfgLeao['_site_name'] = 'Leão da Barra';
$schemaLeao = DiscoverSchemas::gerar(
    ['titulo' => 'Bahia x Vitória ao vivo', 'url' => 'https://leaodabarra.com.br/teste'],
    ['cluster_detect' => ['key' => 'esportes', 'nome' => 'Esportes'], 'termo' => 'bahia vitoria'],
    $cfgLeao
);
$jsonInline2 = preg_match('/<script[^>]*>(.*?)<\/script>/s', $schemaLeao, $m2) ? $m2[1] : '';
$payload2 = json_decode(trim($jsonInline2), true);
$orgLeao = null;
foreach ($payload2['@graph'] ?? [] as $node) {
    if (($node['@type'] ?? '') === 'Organization') { $orgLeao = $node; break; }
}
check("Leão da Barra: parentOrganization.name = Sistema 3 Mídia Digital",
    isset($orgLeao['parentOrganization']['name']) && $orgLeao['parentOrganization']['name'] === 'Sistema 3 Mídia Digital');

echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n";
echo "  WARN: {$warn}\n";
echo "  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[CAMINHO C] OK · todos checks passaram\n" : "\n[CAMINHO C] FALHOU · {$fail} checks falharam\n";
exit($fail === 0 ? 0 : 1);
