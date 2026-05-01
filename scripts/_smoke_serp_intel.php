<?php
/**
 * Smoke do pacote SERP Intel + Content Depth (5 entregas):
 *   1. DiscoverCtrIntel
 *   2. DiscoverSerpAnalyzer
 *   3. DiscoverClusterExpander
 *   4. DiscoverUpdateDetector
 *   5. DiscoverInternalLinkRetro
 */

declare(strict_types=1);
$rootDir = dirname(__DIR__);

require_once $rootDir . '/lib/DiscoverCtrIntel.php';
require_once $rootDir . '/lib/DiscoverSerpAnalyzer.php';
require_once $rootDir . '/lib/DiscoverClusterExpander.php';
require_once $rootDir . '/lib/DiscoverUpdateDetector.php';
require_once $rootDir . '/lib/DiscoverInternalLinkRetro.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// ─────────────────────────────────────────────
echo "\n=== 1: CtrIntel — mock Serper ===\n";

// Mock Serper que retorna estruturas Serper-like
$mockSerper = new class {
    public function autocomplete(string $q): array {
        return ['suggestions' => [
            ['value' => $q . ' isenção'],
            ['value' => $q . ' cronograma'],
            ['value' => $q . ' inscrição'],
            ['value' => $q . ' prazo'],
        ]];
    }
    public function relatedSearches(string $q, string $tbs = ''): array {
        return [
            'related' => [
                ['query' => $q . ' resultado'],
                ['query' => $q . ' simulado'],
            ],
            'paa' => [
                ['question' => 'Quem tem direito à isenção?', 'snippet' => 'Estudantes da rede pública e baixa renda'],
                ['question' => 'Quando começa a inscrição?', 'snippet' => 'Em maio de 2026'],
                ['question' => 'Como recuperar a senha?'],
            ],
        ];
    }
    public function search(string $q, int $num = 10): array {
        return ['organic' => [
            ['link' => 'https://outrosite.com/enem-2026', 'title' => 'ENEM 2026 — guia completo', 'snippet' => 'Texto longo sobre o ENEM 2026 com várias informações importantes.', 'date' => 'maio 2024'],
            ['link' => 'https://blog.exemplo.com/enem', 'title' => 'Tudo sobre o ENEM 2024', 'snippet' => 'Snippet de exemplo'],
            ['link' => 'https://cursosenacgratuito.com.br/enem', 'title' => 'ENEM Senac', 'snippet' => 'já é nosso'],
            ['link' => 'https://x.gov.br/inep', 'title' => 'Inep ENEM', 'snippet' => 'Site oficial do Inep com informações detalhadas sobre o ENEM, prazos, isenção e cronograma.', 'date' => '2024-01-15'],
        ]];
    }
};

// Limpa cache de teste
$cacheDir = $rootDir . '/data/cache/ctr_intel';
foreach (glob($cacheDir . '/*/*.json') as $f) @unlink($f);

$intel = DiscoverCtrIntel::obter('enem 2026', $mockSerper);
check("CtrIntel retorna autocomplete", count($intel['autocomplete']) >= 3);
check("CtrIntel retorna related", count($intel['related']) >= 1);
check("CtrIntel retorna paa", count($intel['paa']) >= 2);
check("CtrIntel cached=false na 1ª", $intel['cached'] === false);

// 2ª chamada → cached
$intel2 = DiscoverCtrIntel::obter('enem 2026', $mockSerper);
check("CtrIntel cached=true na 2ª chamada", $intel2['cached'] === true);

$bloco = DiscoverCtrIntel::paraPromptContext($intel);
check("paraPromptContext retorna string com AUTOCOMPLETE", strpos($bloco, 'AUTOCOMPLETE') !== false);
check("bloco contém PEOPLE ALSO ASK", strpos($bloco, 'PEOPLE ALSO ASK') !== false);
check("bloco contém REGRAS DE OURO", strpos($bloco, 'REGRAS') !== false);

// Vazio → não polui prompt
$blocoVazio = DiscoverCtrIntel::paraPromptContext(['autocomplete' => [], 'related' => [], 'paa' => []]);
check("intel vazio → bloco vazio (não polui prompt)", $blocoVazio === '');

// ─────────────────────────────────────────────
echo "\n=== 2: SerpAnalyzer ===\n";
$cacheSerpDir = $rootDir . '/data/cache/serp_analysis';
foreach (glob($cacheSerpDir . '/*/*.json') as $f) @unlink($f);

$serp = DiscoverSerpAnalyzer::analisar('enem 2026', $mockSerper);
check("SerpAnalyzer retorna top_10_count > 0", $serp['top_10_count'] > 0);
check("SerpAnalyzer filtra dominios próprios (cursosenacgratuito.com.br)",
    $serp['concorrentes_count'] < $serp['top_10_count']);
check("SerpAnalyzer detecta tem_post_recente_2025_2026 (mock tem 2024 antigo)",
    isset($serp['tem_post_recente_2025_2026']));
check("SerpAnalyzer recomenda palavras", $serp['recomendacao_palavras'] >= 800);
check("SerpAnalyzer extrai titulos top",
    is_array($serp['organic_titulos_top5']) && count($serp['organic_titulos_top5']) > 0);

$blocoSerp = DiscoverSerpAnalyzer::paraPromptContext($serp);
check("paraPromptContext retorna bloco com DIRETIVAS",
    strpos($blocoSerp, 'DIRETIVAS') !== false);

// ─────────────────────────────────────────────
echo "\n=== 3: ClusterExpander ===\n";

// Mock DB com posts existentes pra cluster_expander
$mockDb = new class {
    private array $records = [];
    private int $nextId = 1;
    public function all(array $filters = []): array {
        $out = $this->records;
        if (isset($filters['site'])) $out = array_filter($out, fn($r) => ($r['site'] ?? '') === $filters['site']);
        if (isset($filters['status'])) $out = array_filter($out, fn($r) => ($r['status'] ?? '') === $filters['status']);
        return array_values($out);
    }
    public function upsert(array $row): int {
        $row['id'] = $this->nextId++;
        $this->records[] = $row;
        return $row['id'];
    }
    public function get(int $id): ?array {
        foreach ($this->records as $r) if (($r['id'] ?? 0) === $id) return $r;
        return null;
    }
    public function updateStatus(int $id, string $status, array $extra = []): bool {
        foreach ($this->records as $i => $r) {
            if (($r['id'] ?? 0) === $id) {
                $this->records[$i]['status'] = $status;
                foreach ($extra as $k => $v) $this->records[$i][$k] = $v;
                return true;
            }
        }
        return false;
    }
};

$trendMae = [
    'termo' => 'enem 2026',
    'site'  => 'cursosenac',
    'cluster_detect' => ['key' => 'noticias_info_critica'],
    'score_discover' => 9.0,
];
$resExp = DiscoverClusterExpander::expandir($trendMae, $mockSerper, $mockDb, ['dry_run' => false]);
check("ClusterExpander cria filhos", $resExp['filhos_criados'] >= 1);
check("filhos têm origem 'cluster_expander:'",
    count(array_filter($mockDb->all(), fn($r) => strpos((string)($r['origem'] ?? ''), 'cluster_expander:') === 0)) >= 1);

// 2ª expansão → ja_existiam
$resExp2 = DiscoverClusterExpander::expandir($trendMae, $mockSerper, $mockDb, ['dry_run' => false]);
check("expansão repetida: filhos já existem", $resExp2['ja_existiam'] >= 1);

// ─────────────────────────────────────────────
echo "\n=== 4: UpdateDetector ===\n";
// Simula DB com post publicado recente
$mockDb2 = new class {
    public function all(array $f): array {
        return [
            ['id' => 100, 'post_id' => 5001, 'site' => 'cursosenac', 'status' => 'publicado',
             'termo' => 'enem 2026 inscrição como fazer',
             'titulo' => 'ENEM 2026: como se inscrever passo a passo',
             'url_post' => 'https://x/y',
             'publicado_em' => date('Y-m-d H:i:s', strtotime('-30 days'))],
            ['id' => 101, 'post_id' => 5002, 'site' => 'cursosenac', 'status' => 'publicado',
             'termo' => 'fies 2026',
             'titulo' => 'FIES 2026 abre',
             'url_post' => 'https://x/y2',
             'publicado_em' => date('Y-m-d H:i:s', strtotime('-10 days'))],
        ];
    }
};

// Termo MUITO similar ao primeiro post → recomenda update
$rec = DiscoverUpdateDetector::analisar('enem 2026 como fazer inscrição', 'cursosenac', $mockDb2);
check("similar → recomenda update", $rec['acao'] === 'update',
    'acao=' . $rec['acao']);
check("recomendação inclui post_id existente",
    isset($rec['post_existente']['post_id']) && $rec['post_existente']['post_id'] === 5001);
check("similaridade reportada", isset($rec['similaridade']) && $rec['similaridade'] >= 70);

// Termo COMPLETAMENTE diferente → recomenda create
$rec2 = DiscoverUpdateDetector::analisar('bolsa familia janeiro 2026', 'cursosenac', $mockDb2);
check("dissimilar → recomenda create", $rec2['acao'] === 'create');

// Termo de site sem histórico
$mockVazio = new class { public function all(array $f): array { return []; } };
$rec3 = DiscoverUpdateDetector::analisar('qualquer termo', 'siteX', $mockVazio);
check("site vazio → create", $rec3['acao'] === 'create');

// ─────────────────────────────────────────────
echo "\n=== 5: InternalLinkRetro ===\n";

// Mock WP que captura updates
$mockWp = new class {
    public array $atualizados = [];
    private array $posts = [
        2001 => ['content' => ['raw' => '<h1>Post antigo 1</h1><p>Conteúdo antigo</p><p>Final</p>']],
        2002 => ['content' => ['raw' => '<h1>Post antigo 2</h1><p>Outro conteúdo</p>']],
    ];
    public function getPost(int $id): array {
        return $this->posts[$id] ?? throw new RuntimeException("post $id não existe");
    }
    public function atualizarPost(int $id, array $payload): array {
        if (!isset($this->posts[$id])) throw new RuntimeException("post $id não existe");
        $this->posts[$id]['content']['raw'] = $payload['content'] ?? $this->posts[$id]['content']['raw'];
        $this->atualizados[$id] = $payload['content'];
        return ['id' => $id];
    }
};

// Mock DB com 2 posts antigos do mesmo cluster
$mockDb3 = new class {
    public function all(array $f): array {
        return [
            ['post_id' => 2001, 'site' => 'cursosenac', 'status' => 'publicado',
             'cluster_detect' => ['key' => 'noticias_info_critica'],
             'titulo' => 'ENEM 2024: cronograma divulgado',
             'publicado_em' => date('Y-m-d H:i:s', strtotime('-60 days'))],
            ['post_id' => 2002, 'site' => 'cursosenac', 'status' => 'publicado',
             'cluster_detect' => ['key' => 'noticias_info_critica'],
             'titulo' => 'ENEM 2025 mudanças',
             'publicado_em' => date('Y-m-d H:i:s', strtotime('-200 days'))],
        ];
    }
};

$cfgRetro = ['_site_slug' => 'cursosenac', 'wp_url' => 'https://x', 'wp_user' => 'a', 'wp_app_password' => 'b'];
$resRetro = DiscoverInternalLinkRetro::injetar(
    9999, 'noticias_info_critica',
    'ENEM 2026 isenção: como pedir',
    'https://x/enem-2026-isencao',
    $cfgRetro, $mockDb3, $mockWp
);
check("InternalLinkRetro processou candidatos", $resRetro['processados'] >= 1);
check("links injetados nos antigos", $resRetro['linkados'] >= 1);
check("atualizou via mockWp", count($mockWp->atualizados) >= 1);

// Verifica que conteúdo atualizado tem aside cc-retrolink + URL nova
$primeiroAtt = reset($mockWp->atualizados);
check("conteúdo atualizado tem aside cc-retrolink",
    is_string($primeiroAtt) && strpos($primeiroAtt, 'cc-retrolink') !== false);
check("conteúdo atualizado contém URL do novo post",
    is_string($primeiroAtt) && strpos($primeiroAtt, 'enem-2026-isencao') !== false);

// 2ª chamada idempotente
$resRetro2 = DiscoverInternalLinkRetro::injetar(
    9999, 'noticias_info_critica',
    'ENEM 2026 isenção: como pedir',
    'https://x/enem-2026-isencao',
    $cfgRetro, $mockDb3, $mockWp
);
check("idempotência: 2ª chamada → ja_continham >= 1",
    $resRetro2['ja_continham'] >= 1);

// ─────────────────────────────────────────────
echo "\n=== Wire em DiscoverGerador ===\n";
$srcGer = file_get_contents($rootDir . '/lib/DiscoverGerador.php');
check("DiscoverGerador require DiscoverCtrIntel",
    strpos($srcGer, 'DiscoverCtrIntel.php') !== false);
check("DiscoverGerador require DiscoverSerpAnalyzer",
    strpos($srcGer, 'DiscoverSerpAnalyzer.php') !== false);
check("DiscoverGerador require DiscoverUpdateDetector",
    strpos($srcGer, 'DiscoverUpdateDetector.php') !== false);
check("Update detector tem branch que redireciona pra Reviewer",
    strpos($srcGer, 'descartado_update_existente') !== false);

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[SERP INTEL] OK\n" : "\n[SERP INTEL] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
