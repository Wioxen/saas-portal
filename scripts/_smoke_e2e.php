<?php
/**
 * Smoke E2E — pipeline INTEIRO com mocks (offline).
 *
 * Cobre:
 *   1. Pingo (mock RSS) → DB com trend novo
 *   2. PrePublishLint avalia → aprova
 *   3. Fila pega → DiscoverFila::criar / proximoComLock
 *   4. PostProcess pipeline (schemas, related links, badges, quote, AI overview, attribution)
 *   5. Status update no DB → publicado
 *   6. SocialPoster mock → log JSONL
 *   7. PostPerformanceLog snapshot mock
 *
 * NÃO chama: Anthropic, OpenAI, WP, Serper, Bluesky, Threads. Tudo simulado.
 *
 * Validação: pipeline COMPLETA mesmo sem credenciais externas.
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/DiscoverDb.php';
require_once $rootDir . '/lib/PrePublishLint.php';
require_once $rootDir . '/lib/DiscoverFila.php';
require_once $rootDir . '/lib/DiscoverPostProcess.php';
require_once $rootDir . '/lib/SocialPoster.php';
require_once $rootDir . '/lib/PostPerformanceLog.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// Setup: DB + dirs em tempfiles isolados
$tmpDb = sys_get_temp_dir() . '/e2e_db_' . uniqid() . '.json';

// ─────────────────────────────────────────────
echo "\n=== ETAPA 1: Pingo simulado → trend salvo no DB ===\n";
$db = new DiscoverDb($tmpDb, 0);

// Simula trend descoberta pelo Pingo
$trendId = $db->upsert([
    'site'          => 'cursosenac',
    'termo'         => 'enem 2026 abre inscrições com isenção',
    'origem'        => 'rss:gov-inep',
    'status'        => 'aprovado',
    'score_discover'=> 8.5,
    'data_detectada'=> date('Y-m-d H:i:s'),
    'cluster_detect'=> ['key' => 'noticias_info_critica', 'nome' => 'Notícias', 'score' => 5],
    'pingo_link'    => 'https://gov.br/inep/news',
]);
check("trend criado com id > 0", $trendId > 0);
check("trend recuperável via get()", $db->get($trendId) !== null);

// ─────────────────────────────────────────────
echo "\n=== ETAPA 2: PrePublishLint avalia ===\n";
$cfgSite = [
    '_site_slug'    => 'cursosenac',
    'subtipo_nicho' => 'cursos técnicos / EAD',
    'empresa'       => ['nome' => 'Sistema 2'],
    'termos_canibal'=> ['inss', 'fies'], // canibal de outros sites do grupo
];
$fontesOk = [
    [
        'url' => 'https://www.gov.br/inep/pt-br/noticia',
        'fonte' => [
            'meta' => ['title' => 'Inep abre ENEM'],
            'content' => [
                'paragraphs' => [
                    'O Inep informou em nota oficial: "A inscrição para o ENEM 2026 começa nesta segunda e seguirá até dia 14 de junho."',
                    'A taxa pode ser dispensada em casos específicos.',
                    str_repeat('texto adicional ', 50),
                ],
            ],
        ],
    ],
];
$trend = $db->get($trendId);
$lint = PrePublishLint::avaliar($trend, $fontesOk, $db, 50, $cfgSite);
check("lint aprova trend OK", $lint['aprovado'] === true,
    'motivos=' . json_encode($lint['motivos']));

// ─────────────────────────────────────────────
echo "\n=== ETAPA 3: Fila criada e item pego com lock ===\n";
$filaSite = 'cursosenac_e2e_' . uniqid();
$fila = new DiscoverFila($filaSite);
$state = $fila->criar([$trend], 'discover');
check("fila criada com 1 item", count($state['items']) === 1);

$item = $fila->proximoComLock();
check("proximoComLock retorna item", $item !== null);
check("item status='running' após lock",
    is_array($item) && ($item['status'] ?? '') === 'running');

$status = $fila->status();
check("fila status reporta 1 running",
    $status['counts']['running'] === 1 && $status['counts']['pending'] === 0);

// Limpa fila do teste
$fila->limpar();

// ─────────────────────────────────────────────
echo "\n=== ETAPA 4: PostProcess pipeline completo ===\n";
// HTML simulado (saída de Sonnet)
$html = '<h1>ENEM 2026: como pedir isenção da taxa em 2 passos</h1>'
      . '<p>O Inep abriu nesta segunda as inscrições do ENEM 2026 com 5 milhões de candidatos esperados.</p>'
      . '<h2>Quem tem direito à isenção</h2>'
      . '<p>Estudantes da rede pública e candidatos de baixa renda podem pedir.</p>'
      . '<h2>Como solicitar</h2>'
      . '<p>O pedido é feito pelo gov.br até 14 de junho. Quem perder paga R$ 85.</p>'
      . '<p>Detalhes adicionais sobre documentação.</p>';

$meta = [
    'titulo'  => 'ENEM 2026: como pedir isenção da taxa',
    'url'     => 'https://cursosenacgratuito.com.br/enem-isencao-2026',
    'post_id' => 9999,
    'fontes'  => $fontesOk,
];
$cfgPp = $cfgSite + [
    'wp_url'      => 'https://cursosenacgratuito.com.br',
    'wp_user'     => 'admin',
    'wp_app_password' => 'mock',
    'site_name'   => 'Curso SENAC',
    'persona'     => ['autor' => 'Maria Gusmão', 'voz' => 'mentora', 'especialidade' => 'cursos'],
    'pretty_links_prefix' => 'go',
];

$htmlOut = DiscoverPostProcess::processar($html, $meta, $trend, $cfgPp);
check("PostProcess retorna HTML não-vazio", trim($htmlOut) !== '');
check("PostProcess HTML cresceu (enriquecimentos aplicados)", strlen($htmlOut) >= strlen($html));
check("PostProcess preserva H1 original", strpos($htmlOut, 'ENEM 2026: como pedir isenção') !== false);
// AI Overview: P1 já é "ready" (tem ENEM, número, segunda, abriu) → speakable schema, sem TL;DR visual
check("AI Overview Speakable schema injetado",
    strpos($htmlOut, 'data-speakable="1"') !== false);
// Quote enrichment: fonte oficial (.gov.br) com aspas → blockquote
check("QuoteEnrichment injetou blockquote da fonte oficial",
    strpos($htmlOut, 'data-cc-quote="1"') !== false && strpos($htmlOut, 'OFICIAL') !== false);

// ─────────────────────────────────────────────
echo "\n=== ETAPA 5: DB updateStatus → publicado ===\n";
$ok2 = $db->updateStatus($trendId, 'publicado', [
    'post_id'      => 9999,
    'url_post'     => 'https://cursosenacgratuito.com.br/enem-isencao-2026',
    'titulo'       => 'ENEM 2026: como pedir isenção da taxa',
    'publicado_em' => date('Y-m-d H:i:s'),
]);
check("updateStatus retorna true", $ok2);
$publicado = $db->get($trendId);
check("status agora é 'publicado'", ($publicado['status'] ?? '') === 'publicado');
check("post_id persistido", (int)($publicado['post_id'] ?? 0) === 9999);

// ─────────────────────────────────────────────
echo "\n=== ETAPA 6: SocialPoster com canais SEM credenciais ===\n";
$cfgSocial = [
    'social' => [
        'bluesky' => ['enabled' => true],   // sem creds
        'threads' => ['enabled' => true],   // sem creds
    ],
];
$resSocial = SocialPoster::publicar([
    'titulo'      => 'ENEM 2026',
    'url'         => 'https://cursosenacgratuito.com.br/enem-isencao-2026',
    'site_slug'   => 'cursosenac',
    'post_id'     => 9999,
], $cfgSocial);
check("SocialPoster retorna estrutura completa",
    isset($resSocial['sucessos']) && isset($resSocial['falhas']) && isset($resSocial['por_canal']));
check("ambos canais falharam (sem creds) — esperado",
    $resSocial['sucessos'] === 0 && $resSocial['falhas'] === 2);
check("erros são informativos (não throw)",
    isset($resSocial['por_canal']['bluesky']['erro']) && isset($resSocial['por_canal']['threads']['erro']));

// ─────────────────────────────────────────────
echo "\n=== ETAPA 7: PostPerformanceLog mock snapshot ===\n";
// Mock GSC que retorna stats
$mockGsc = new class {
    public function consultarPerformance(string $url, string $ini, string $fim, array $opts): array {
        $tipo = $opts['tipo'] ?? 'web';
        return ['rows' => $tipo === 'discover' ? [
            ['keys' => ['https://cursosenacgratuito.com.br/enem-isencao-2026'],
             'clicks' => 350, 'impressions' => 8000, 'ctr' => 0.044, 'position' => 0],
        ] : []];
    }
};

$tmpPerfDir = sys_get_temp_dir() . '/e2e_perf_' . uniqid();
$perfLog = new PostPerformanceLog($tmpPerfDir);
$resPerf = $perfLog->snapshot('cursosenac', ['wp_url' => 'https://cursosenacgratuito.com.br'], $db, $mockGsc, [
    'janela_d' => 30,
    'dia_alvo' => date('Y-m-d', strtotime('-3 days')),
]);
check("snapshot retorna ok=true", !empty($resPerf['ok']));
check("posts processados >= 1",
    ($resPerf['posts_processados'] ?? 0) >= 1,
    'p=' . ($resPerf['posts_processados'] ?? '?'));
check("entries logadas (3 surfaces × 1 post = 3)",
    ($resPerf['entries_logadas'] ?? 0) === 3);

// Cleanup
@unlink($tmpDb);
foreach (glob($tmpDb . '.bak.*') as $f) @unlink($f);
foreach (glob($tmpPerfDir . '/*') as $f) @unlink($f);
@rmdir($tmpPerfDir);

// ─────────────────────────────────────────────
echo "\n=== ETAPA 8: Pipeline completo SOBREVIVE sem credenciais opcionais ===\n";
// Cenário extremo: cfg só tem o mínimo absoluto
$cfgMinimo = [
    '_site_slug'    => 'cursosenac',
    'wp_url'        => 'https://cursosenacgratuito.com.br',
    'wp_user'       => 'admin',
    'wp_app_password' => 'mock',
    'pretty_links_prefix' => 'go',
];
$htmlMin = '<h1>X</h1><p>conteúdo simples sem nada</p>';
$metaMin = ['titulo' => 'X', 'url' => 'https://x', 'post_id' => 1];
$outMin = DiscoverPostProcess::processar($htmlMin, $metaMin, ['cluster_detect' => ['key' => 'X']], $cfgMinimo);
check("PostProcess sobrevive cfg mínimo", trim($outMin) !== '');

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[E2E] OK · pipeline completo funcional offline\n" : "\n[E2E] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
