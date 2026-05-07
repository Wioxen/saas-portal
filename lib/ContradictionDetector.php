<?php
declare(strict_types=1);

/**
 * ContradictionDetector — compara fatos entre posts da mesma entidade publicados em janela
 * curta (default 7 dias) e flag posts com valores discordantes pra revisão. Onda 3 llm-wiki.
 *
 * Pipeline:
 *   1. Lê data/entity_pages_cache/{site}_aliases.json (entidades + aliases)
 *   2. Pra cada entidade: WP search → posts da janela
 *   3. EntityExtractor extrai fatos (datas, valores, números, programas)
 *   4. Pares de posts: se compartilham programa_evento (proxy de "mesmo edital"),
 *      compara datas/valores/números e flag divergências
 *   5. Retorna lista estruturada de contradições
 */
class ContradictionDetector
{
    private const ALIASES_DIR = __DIR__ . '/../data/entity_pages_cache';

    private Wordpress $wp;
    private string $siteSlug;
    private int $janelaDias;
    private array $log = [];

    public function __construct(Wordpress $wp, string $siteSlug, int $janelaDias = 7)
    {
        $this->wp = $wp;
        $this->siteSlug = $siteSlug;
        $this->janelaDias = $janelaDias;
    }

    /**
     * Executa detecção. Retorna array com summary + lista de contradições.
     */
    public function detectar(): array
    {
        $aliasesMap = $this->carregarAliases();
        if (empty($aliasesMap)) {
            return ['site' => $this->siteSlug, 'janela_dias' => $this->janelaDias, 'erro' => 'sem aliases.json — gere entity/concept pages primeiro', 'contradicoes' => []];
        }

        $resultado = [
            'site' => $this->siteSlug,
            'data' => date('Y-m-d'),
            'janela_dias' => $this->janelaDias,
            'entidades_analisadas' => 0,
            'posts_analisados' => 0,
            'contradicoes' => [],
        ];

        foreach ($aliasesMap as $pageId => $info) {
            $tipo = (string)($info['tipo'] ?? 'entity');
            $primaryTerm = $tipo === 'entity'
                ? (string)($info['nome'] ?? $info['fullname'] ?? '')
                : (string)($info['fullname'] ?? '');
            if ($primaryTerm === '') continue;

            $aliases = (array)($info['aliases'] ?? []);
            $posts = $this->buscarPostsRecentes($primaryTerm, $aliases);
            if (count($posts) < 2) continue; // sem comparação possível

            $resultado['entidades_analisadas']++;
            $resultado['posts_analisados'] += count($posts);

            $fatosPorPost = [];
            foreach ($posts as $p) {
                $pid = (int)$p['id'];
                try {
                    $full = $this->wp->getPost($pid);
                    $contentPlain = trim(strip_tags(html_entity_decode((string)($full['content']['rendered'] ?? ''))));
                    $title = (string)($full['title']['rendered'] ?? $p['title'] ?? '');
                    $fatosPorPost[$pid] = [
                        'titulo' => $title,
                        'link' => (string)($p['link'] ?? ''),
                        'data' => (string)($full['date'] ?? ''),
                        'fatos' => EntityExtractor::extract($contentPlain, $title),
                    ];
                } catch (Throwable $e) { /* skip */ }
            }

            $contradicoes = $this->compararPares($primaryTerm, $fatosPorPost);
            foreach ($contradicoes as $c) $resultado['contradicoes'][] = $c;
        }

        return $resultado;
    }

    /**
     * Busca posts recentes (janela em dias) que mencionam a entidade ou aliases.
     */
    private function buscarPostsRecentes(string $primary, array $aliases): array
    {
        $termos = array_merge([$primary], $aliases);
        $colhidos = [];
        $cutoff = (new DateTime("-{$this->janelaDias} days"))->format('Y-m-d\TH:i:s');

        foreach ($termos as $t) {
            try {
                $posts = $this->wp->buscarRelacionados($t, 20, 0);
                foreach ($posts as $p) {
                    $pid = (int)($p['id'] ?? 0);
                    if ($pid === 0 || isset($colhidos[$pid])) continue;
                    // Filtra por data (buscarRelacionados não tem after)
                    // Como não vem date no retorno, busca getPost lazy depois — aqui só dedupe
                    $colhidos[$pid] = $p;
                }
            } catch (Throwable $e) { /* skip */ }
        }

        // Filtra por janela: precisa fazer getPost batch ou passar por outra query.
        // Atalho: usa /posts?after=cutoff&search=primary pra ter date direto.
        return $this->filtrarPorJanela(array_values($colhidos), $cutoff);
    }

    private function filtrarPorJanela(array $posts, string $cutoff): array
    {
        $out = [];
        foreach ($posts as $p) {
            $pid = (int)$p['id'];
            try {
                $full = $this->wp->getPost($pid);
                $date = (string)($full['date'] ?? '');
                if ($date === '' || $date >= $cutoff) {
                    $p['date'] = $date;
                    $out[] = $p;
                }
            } catch (Throwable $e) { /* skip */ }
        }
        return $out;
    }

    /**
     * Compara pares de posts da mesma entidade. Flag quando:
     *   - Compartilham 1+ programa_evento (proxy de mesmo edital/contexto)
     *   - E têm datas/valores/números divergentes
     */
    private function compararPares(string $entidade, array $fatosPorPost): array
    {
        $flags = [];
        $ids = array_keys($fatosPorPost);
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $fatosPorPost[$ids[$i]];
                $b = $fatosPorPost[$ids[$j]];
                $contextoComum = $this->contextoComum($a['fatos'], $b['fatos']);
                if (empty($contextoComum)) continue;

                $diffs = $this->diffFatos($a['fatos'], $b['fatos']);
                foreach ($diffs as $d) {
                    $flags[] = [
                        'entidade' => $entidade,
                        'contexto' => $contextoComum,
                        'tipo' => $d['tipo'],
                        'campo' => $d['campo'],
                        'fatos_a' => $d['a'],
                        'fatos_b' => $d['b'],
                        'post_a' => ['id' => $ids[$i], 'titulo' => $a['titulo'], 'link' => $a['link'], 'data' => $a['data']],
                        'post_b' => ['id' => $ids[$j], 'titulo' => $b['titulo'], 'link' => $b['link'], 'data' => $b['data']],
                    ];
                }
            }
        }
        return $flags;
    }

    /**
     * Contexto comum = sobreposição de programas_eventos (mesmo edital citado).
     * Lowercase + trim pra match difuso.
     */
    private function contextoComum(array $fatosA, array $fatosB): array
    {
        $a = array_map(fn($s) => mb_strtolower(trim($s)), $fatosA['programas_eventos'] ?? []);
        $b = array_map(fn($s) => mb_strtolower(trim($s)), $fatosB['programas_eventos'] ?? []);
        return array_values(array_intersect($a, $b));
    }

    /**
     * Diff de fatos críticos. Retorna lista de divergências por campo.
     * Tipos:
     *   - 'vagas_divergentes': numeros_chave com sufixo "vagas" diferentes
     *   - 'datas_disjuntas': nenhum overlap entre datas_prazos
     *   - 'valores_divergentes': valores_dinheiro distintos quando ambos posts têm pelo menos 1
     */
    private function diffFatos(array $fatosA, array $fatosB): array
    {
        $diffs = [];

        // Vagas: extrai N de "N vagas" em cada lado
        $vagasA = $this->extrairNumeroDeContexto($fatosA['numeros_chave'] ?? [], 'vagas?');
        $vagasB = $this->extrairNumeroDeContexto($fatosB['numeros_chave'] ?? [], 'vagas?');
        if (!empty($vagasA) && !empty($vagasB) && empty(array_intersect($vagasA, $vagasB))) {
            $diffs[] = ['tipo' => 'vagas_divergentes', 'campo' => 'numeros_chave', 'a' => $vagasA, 'b' => $vagasB];
        }

        // Datas: listas disjuntas
        $datasA = array_map('mb_strtolower', $fatosA['datas_prazos'] ?? []);
        $datasB = array_map('mb_strtolower', $fatosB['datas_prazos'] ?? []);
        if (!empty($datasA) && !empty($datasB) && empty(array_intersect($datasA, $datasB))) {
            $diffs[] = ['tipo' => 'datas_disjuntas', 'campo' => 'datas_prazos', 'a' => array_slice($datasA, 0, 5), 'b' => array_slice($datasB, 0, 5)];
        }

        // Valores R$: presentes nos 2 mas distintos
        $valoresA = array_map('mb_strtolower', $fatosA['valores_dinheiro'] ?? []);
        $valoresB = array_map('mb_strtolower', $fatosB['valores_dinheiro'] ?? []);
        if (!empty($valoresA) && !empty($valoresB) && empty(array_intersect($valoresA, $valoresB))) {
            $diffs[] = ['tipo' => 'valores_divergentes', 'campo' => 'valores_dinheiro', 'a' => $valoresA, 'b' => $valoresB];
        }

        return $diffs;
    }

    /** Extrai apenas o N de "N {sufixo}" (ex: "15 vagas" → 15). */
    private function extrairNumeroDeContexto(array $numeros, string $sufixoRegex): array
    {
        $out = [];
        foreach ($numeros as $s) {
            if (preg_match('/(\d+(?:\.\d{3})*)\s+' . $sufixoRegex . '/iu', (string)$s, $m)) {
                $out[] = str_replace('.', '', $m[1]);
            }
        }
        return array_values(array_unique($out));
    }

    private function carregarAliases(): array
    {
        $path = self::ALIASES_DIR . "/{$this->siteSlug}_aliases.json";
        if (!file_exists($path)) return [];
        $j = json_decode((string)file_get_contents($path), true);
        return is_array($j) ? $j : [];
    }

    public function getLog(): array { return $this->log; }
}
