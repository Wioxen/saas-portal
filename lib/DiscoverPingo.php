<?php
/**
 * DiscoverPingo — orquestrador de captura via RSS/Atom.
 *
 * Ciclo:
 *   1. Carrega fontes ativas de data/fontes_pingo.json
 *   2. Pra cada fonte (respeitando intervalo_min):
 *      a) fetch XML
 *      b) parse via PingoRssParser
 *      c) dedup contra data/pingo_state.json
 *      d) normaliza cada item novo em shape de trend
 *      e) calcula cluster + score + sinais
 *      f) upsert em DiscoverDb com status='novo' ou 'aprovado' (por auto_aprovar_score_min)
 *   3. Atualiza state com novos hashes vistos + histórico
 *
 * Dedup: hash(link) por fonte. TTL 30 dias (limite histórico).
 * Multi-site: campo 'site_target' da fonte ('auto' => usa mapeamento cluster→site).
 */

require_once __DIR__ . '/TrendsTaxonomia.php';
require_once __DIR__ . '/PingoRssParser.php';
require_once __DIR__ . '/DiscoverSinaisEditoriais.php';
require_once __DIR__ . '/DiscoverScore.php';
require_once __DIR__ . '/DiscoverAngulo.php';
require_once __DIR__ . '/DiscoverDb.php';

class DiscoverPingo
{
    private const MAX_HISTORICO = 50;   // últimas execuções por fonte no state
    private const TTL_LINKS_DIAS = 30;  // links vistos há mais de 30d são esquecidos (dedup reset)
    private const HTTP_TIMEOUT = 20;

    private array $cfg;
    private DiscoverDb $db;
    private string $pathFontes;
    private string $pathState;
    private string $pathFiltros;
    private string $pathLogFiltro;
    private ?array $filtrosCache = null;
    /** @var array {aceitos:int, rejeitados:int, motivos:array<string,int>, modo:string} */
    public array $statsFiltro = ['aceitos' => 0, 'rejeitados' => 0, 'motivos' => [], 'modo' => 'warn'];

    public function __construct(array $cfg, DiscoverDb $db)
    {
        $this->cfg = $cfg;
        $this->db = $db;
        $this->pathFontes  = __DIR__ . '/../data/fontes_pingo.json';
        $this->pathState   = __DIR__ . '/../data/pingo_state.json';
        $this->pathFiltros = __DIR__ . '/../data/pingo_filtros.json';
        $this->pathLogFiltro = __DIR__ . '/../data/fila/log_pingo_filtro.log';
    }

    // ═══════════════════════════════════════════════════════════════
    // FONTES — CRUD
    // ═══════════════════════════════════════════════════════════════

    public function listarFontes(bool $somenteAtivas = false): array
    {
        $data = $this->carregarFontes();
        $lista = $data['fontes'] ?? [];
        if ($somenteAtivas) {
            $lista = array_values(array_filter($lista, fn($f) => !empty($f['ativo'])));
        }
        return $lista;
    }

    public function fontePorId(int $id): ?array
    {
        foreach ($this->listarFontes() as $f) {
            if ((int)$f['id'] === $id) return $f;
        }
        return null;
    }

    public function adicionarFonte(array $fonte): array
    {
        $data = $this->carregarFontes();
        $id = (int)($data['next_id'] ?? count($data['fontes']) + 1);
        $nova = [
            'id'                    => $id,
            'nome'                  => trim((string)($fonte['nome'] ?? 'fonte-' . $id)),
            'url_rss'               => trim((string)($fonte['url_rss'] ?? '')),
            'tipo'                  => (string)($fonte['tipo'] ?? 'rss'),
            'ativo'                 => (bool)($fonte['ativo'] ?? true),
            'cluster_hint'          => (string)($fonte['cluster_hint'] ?? 'curiosidades_geral'),
            'site_target'           => (string)($fonte['site_target'] ?? 'auto'),
            'intervalo_min'         => max(1, (int)($fonte['intervalo_min'] ?? 15)),
            'max_itens_por_fetch'   => max(1, (int)($fonte['max_itens_por_fetch'] ?? 30)),
            'auto_aprovar_score_min'=> (float)($fonte['auto_aprovar_score_min'] ?? 7.0),
            'notas'                 => (string)($fonte['notas'] ?? ''),
        ];
        if ($nova['url_rss'] === '' || !preg_match('#^https?://#i', $nova['url_rss'])) {
            throw new InvalidArgumentException('url_rss inválida (precisa ser http/https)');
        }
        $data['fontes'][] = $nova;
        $data['next_id'] = $id + 1;
        $this->salvarFontes($data);
        return $nova;
    }

    public function atualizarFonte(int $id, array $mudancas): ?array
    {
        $data = $this->carregarFontes();
        foreach ($data['fontes'] as $idx => $f) {
            if ((int)$f['id'] !== $id) continue;
            $mudancas['id'] = $id;
            if (isset($mudancas['intervalo_min']))       $mudancas['intervalo_min'] = max(1, (int)$mudancas['intervalo_min']);
            if (isset($mudancas['max_itens_por_fetch'])) $mudancas['max_itens_por_fetch'] = max(1, (int)$mudancas['max_itens_por_fetch']);
            $data['fontes'][$idx] = array_merge($f, $mudancas);
            $this->salvarFontes($data);
            return $data['fontes'][$idx];
        }
        return null;
    }

    public function removerFonte(int $id): bool
    {
        $data = $this->carregarFontes();
        $antes = count($data['fontes']);
        $data['fontes'] = array_values(array_filter($data['fontes'], fn($f) => (int)$f['id'] !== $id));
        if (count($data['fontes']) === $antes) return false;
        $this->salvarFontes($data);
        return true;
    }

    // ═══════════════════════════════════════════════════════════════
    // RODAR CICLO — fontes ativas em sequência
    // ═══════════════════════════════════════════════════════════════

    /**
     * Roda o ciclo completo. Retorna relatório agregado.
     *
     * @param array $opcoes ['dry_run' => false, 'fonte_id' => null, 'force' => false, 'verbose' => false]
     * @return array {fontes_processadas, fontes_skipped, items_vistos, items_novos, items_salvos, erros, relatorio_por_fonte[]}
     */
    public function rodar(array $opcoes = []): array
    {
        $dryRun  = !empty($opcoes['dry_run']);
        $fonteId = $opcoes['fonte_id'] ?? null;
        $force   = !empty($opcoes['force']);  // ignora intervalo_min
        $verbose = !empty($opcoes['verbose']);

        $fontes = $this->listarFontes(true);
        if ($fonteId !== null) {
            $fontes = array_values(array_filter($fontes, fn($f) => (int)$f['id'] === (int)$fonteId));
        }

        $state = $this->carregarState();
        $relatorio = [
            'iniciado_em'          => date('c'),
            'dry_run'              => $dryRun,
            'fontes_processadas'   => 0,
            'fontes_skipped'       => 0,
            'items_vistos'         => 0,
            'items_novos'          => 0,
            'items_salvos'         => 0,
            'erros'                => [],
            'por_fonte'            => [],
        ];

        // Pre-filtra fontes que não estão em cooldown (`intervalo_min`) — pula essas direto
        $fontesAFetchar = [];
        foreach ($fontes as $fonte) {
            $fid = (int)$fonte['id'];
            if (!$force) {
                $ultimaExec = $state['fontes'][$fid]['ultima_execucao'] ?? null;
                if ($ultimaExec) {
                    $tsUltima = strtotime($ultimaExec);
                    $minutosDesde = (time() - $tsUltima) / 60;
                    $intervalo = (int)($fonte['intervalo_min'] ?? 15);
                    if ($minutosDesde < $intervalo) {
                        $relatorio['por_fonte'][$fid] = [
                            'skipped'     => true,
                            'motivo_skip' => sprintf("aguardando intervalo (passou %.1fmin de %dmin)", $minutosDesde, $intervalo),
                        ];
                        $relatorio['fontes_skipped']++;
                        continue;
                    }
                }
            }
            $fontesAFetchar[] = $fonte;
        }

        // Fetch em PARALELO de todos os XMLs elegíveis (E1 — curl_multi).
        // Hoje sequencial = 5-15s pra ~10 fontes. Paralelo: ~2s (limit pelo feed mais lento).
        $xmlsPorUrl = !empty($fontesAFetchar)
            ? $this->fetchXmlMulti(array_map(fn($f) => (string)$f['url_rss'], $fontesAFetchar))
            : [];

        foreach ($fontesAFetchar as $fonte) {
            $fid = (int)$fonte['id'];
            $url = (string)$fonte['url_rss'];
            $xmlOuErro = $xmlsPorUrl[$url] ?? new RuntimeException('fetch nao executado');
            $resFonte = $this->rodarFonteComXml($fonte, $xmlOuErro, $state, $dryRun, $force, $verbose);
            $relatorio['por_fonte'][$fid] = $resFonte;
            $relatorio['fontes_processadas']++;
            $relatorio['items_vistos'] += (int)($resFonte['vistos'] ?? 0);
            $relatorio['items_novos']  += (int)($resFonte['novos'] ?? 0);
            $relatorio['items_salvos'] += (int)($resFonte['salvos'] ?? 0);
            if (!empty($resFonte['erro'])) {
                $relatorio['erros'][] = ['fonte_id' => $fid, 'erro' => $resFonte['erro']];
            }
        }

        if (!$dryRun) {
            try {
                $this->salvarState($state);
            } catch (Throwable $e) {
                // FIX 3: state não persistiu — registramos no relatório mas não silenciamos.
                $relatorio['erros'][] = ['fonte_id' => 0, 'erro' => 'state_persist: ' . $e->getMessage()];
            }
        }

        $relatorio['terminado_em'] = date('c');
        return $relatorio;
    }

    /**
     * Variante de `rodarFonte` que recebe XML pré-fetched (do curl_multi paralelo).
     * Pula intervalo_min E fetch — só processa parse + dedupe + filtro + persist.
     *
     * @param string|Throwable $xmlOuErro retorno de fetchXmlMulti pra essa URL
     */
    private function rodarFonteComXml(array $fonte, $xmlOuErro, array &$state, bool $dryRun, bool $force, bool $verbose): array
    {
        $fid = (int)$fonte['id'];
        $out = ['vistos' => 0, 'novos' => 0, 'salvos' => 0, 'trends_salvos_ids' => []];

        if ($xmlOuErro instanceof Throwable) {
            $this->registrarExecucao($state, $fid, 0, 0, 0, $xmlOuErro->getMessage());
            return $out + ['erro' => $xmlOuErro->getMessage()];
        }
        $xml = (string)$xmlOuErro;

        // (parse + dedupe + filtro vão direto — código original preservado abaixo)
        return $this->processarXmlEContinuar($fonte, $xml, $state, $dryRun, $force, $verbose, $out);
    }

    /**
     * Processa uma única fonte (legacy — usado pra calls síncronos isolados, ex: `--fonte=N`).
     * Em ciclos completos, `rodar()` agora usa `rodarFonteComXml` após fetchXmlMulti paralelo.
     *
     * @return array {skipped?, vistos, novos, salvos, erro?, motivo_skip?, trends_salvos_ids[]}
     */
    private function rodarFonte(array $fonte, array &$state, bool $dryRun, bool $force, bool $verbose): array
    {
        $fid = (int)$fonte['id'];
        $out = ['vistos' => 0, 'novos' => 0, 'salvos' => 0, 'trends_salvos_ids' => []];

        // Respeita intervalo_min (só checa se não --force)
        if (!$force) {
            $ultimaExec = $state['fontes'][$fid]['ultima_execucao'] ?? null;
            if ($ultimaExec) {
                $tsUltima = strtotime($ultimaExec);
                $minutosDesde = (time() - $tsUltima) / 60;
                $intervalo = (int)($fonte['intervalo_min'] ?? 15);
                if ($minutosDesde < $intervalo) {
                    return [
                        'skipped'     => true,
                        'motivo_skip' => sprintf("aguardando intervalo (passou %.1fmin de %dmin)", $minutosDesde, $intervalo),
                    ];
                }
            }
        }

        // Fetch XML
        try {
            $xml = $this->fetchXml($fonte['url_rss']);
        } catch (Throwable $e) {
            $this->registrarExecucao($state, $fid, 0, 0, 0, $e->getMessage());
            return $out + ['erro' => $e->getMessage()];
        }

        return $this->processarXmlEContinuar($fonte, $xml, $state, $dryRun, $force, $verbose, $out);
    }

    /**
     * Compartilhado por `rodarFonte` (síncrono, fetch isolado) e `rodarFonteComXml` (paralelo).
     * Recebe XML já fetched, faz parse + dedupe + normalize + persist.
     */
    private function processarXmlEContinuar(array $fonte, string $xml, array &$state, bool $dryRun, bool $force, bool $verbose, array $out): array
    {
        $fid = (int)$fonte['id'];

        // Parse
        $maxItems = (int)($fonte['max_itens_por_fetch'] ?? 30);
        $items = PingoRssParser::parse($xml, $maxItems);
        $out['vistos'] = count($items);

        if (empty($items)) {
            $this->registrarExecucao($state, $fid, 0, 0, 0, 'nenhum item parseado');
            return $out;
        }

        // Dedup
        $linksVistos = $state['fontes'][$fid]['ultimos_links'] ?? [];
        $linksVistosSet = array_flip($linksVistos);
        $itensNovos = [];
        foreach ($items as $it) {
            $hash = sha1((string)($it['link'] ?: $it['guid'] ?: $it['title']));
            if (isset($linksVistosSet[$hash])) continue;
            $itensNovos[] = $it + ['_hash' => $hash];
        }
        $out['novos'] = count($itensNovos);

        // Normaliza + salva
        $novosHashes = [];
        $idsSalvos = [];
        foreach ($itensNovos as $it) {
            $novosHashes[] = $it['_hash'];
            try {
                $trendRow = $this->normalizarParaTrend($it, $fonte);
                if ($trendRow === null) continue;
                if (!$dryRun) {
                    $id = $this->db->upsert($trendRow);
                    $idsSalvos[] = $id;
                    $out['salvos']++;
                }
            } catch (Throwable $e) {
                if ($verbose) {
                    fwrite(STDERR, "[fonte #{$fid}] erro normalizar item: " . $e->getMessage() . " | titulo: " . ($it['title'] ?? '?') . "\n");
                }
            }
        }
        $out['trends_salvos_ids'] = $idsSalvos;

        // Atualiza state
        $novosLinksVistos = array_merge($linksVistos, $novosHashes);
        $max = max(500, $maxItems * 30);
        if (count($novosLinksVistos) > $max) {
            $novosLinksVistos = array_slice($novosLinksVistos, -$max);
        }
        $state['fontes'][$fid]['ultimos_links'] = $novosLinksVistos;

        $this->registrarExecucao($state, $fid, $out['vistos'], $out['novos'], $out['salvos'], null);
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════
    // NORMALIZAÇÃO — RSS item → trend row
    // ═══════════════════════════════════════════════════════════════

    /**
     * Converte item RSS num row compatível com DiscoverDb::upsert().
     * Aplica cluster match + score + sinais editoriais. Decide status (novo|aprovado).
     */
    private function normalizarParaTrend(array $item, array $fonte): ?array
    {
        $termo = trim((string)($item['title'] ?? ''));
        if ($termo === '' || mb_strlen($termo) < 8) return null;

        // FILTRO DE FRESHNESS — rejeita itens antigos (pub_ts > max_idade_dias).
        // Pingo capturava pub_ts do RSS (PingoRssParser) mas não usava como filtro. Resultado:
        // notícias do ano passado entravam no DB e viravam posts como se fossem atuais.
        // Caso real #742 leaodabarra (2026-05-02): trend 'CBF libera setor visitante Barradão'
        // veio do Correio 24h via Google News com data ~2025 e gerou post desatualizado.
        // Default por cluster: esportes=7d (notícia esportiva fica obsoleta rápido),
        //                      outros=30d (informativo evergreen tem vida útil maior).
        $pubTs = (int)($item['pub_ts'] ?? 0);
        if ($pubTs > 0) {
            $clusterHintFonte = (string)($fonte['cluster_hint'] ?? '');
            $defaultDiasPorCluster = ['esportes' => 7];
            $diasMax = (int)($fonte['noticia_max_idade_dias']
                          ?? $defaultDiasPorCluster[$clusterHintFonte]
                          ?? 30);
            $idadeDias = (time() - $pubTs) / 86400;
            if ($idadeDias > $diasMax) {
                $this->logRejeicao($termo, $fonte, [
                    'rejeitar' => true,
                    'modo'     => 'block',
                    'motivo'   => 'noticia_velha',
                    'pontos'   => 0,
                    'detalhes' => [
                        'idade_dias' => round($idadeDias, 1),
                        'max_dias'   => $diasMax,
                        'pub_ts'     => $pubTs,
                        'pub_data'   => date('Y-m-d', $pubTs),
                    ],
                ]);
                return null;
            }
        }

        // Filtro de qualidade — rejeita lixo (loteria, mortes, fofoca, política partidária)
        // E exige sinais de utilidade (verbos de ação, palavras temporais, dor monetizável).
        // Em modo 'warn', loga rejeições mas APROVA tudo — útil pra calibração inicial.
        $resultadoFiltro = $this->aplicarFiltro($termo, $fonte);
        $deveriaSerRejeitado = $resultadoFiltro['motivo'] !== 'aprovado'
                             && !str_starts_with($resultadoFiltro['motivo'], 'bypass_');
        if ($deveriaSerRejeitado) {
            $this->logRejeicao($termo, $fonte, $resultadoFiltro);
        }
        if ($resultadoFiltro['rejeitar']) {
            return null;  // só bloqueia quando modo=block
        }

        // Relacionados: pedaços da description (primeiras 3 frases)
        $desc = (string)($item['description'] ?? '');
        $relacionados = [];
        if ($desc !== '') {
            $partes = preg_split('/[.!?]+\s+/u', $desc, 5) ?: [];
            foreach ($partes as $p) {
                $p = trim($p);
                if ($p !== '' && mb_strlen($p) < 200) $relacionados[] = $p;
            }
        }
        // Complementa com categorias do feed
        foreach ((array)($item['categorias'] ?? []) as $c) {
            if (is_string($c) && $c !== '' && mb_strlen($c) < 80) $relacionados[] = $c;
        }
        $relacionados = array_slice(array_unique($relacionados), 0, 6);

        // Cluster hint da fonte fortalece o match (categoria_ids do taxonomia)
        $clusterHint = (string)($fonte['cluster_hint'] ?? 'curiosidades_geral');
        $categoriaIdsHint = (array)TrendsTaxonomia::campo($clusterHint, 'categoria_ids', []);

        $trendParaScore = [
            'termo'         => $termo,
            'categoria_ids' => $categoriaIdsHint,
            'relacionados'  => $relacionados,
            'volume_num'    => 0,       // desconhecido — RSS não tem volume
            'growth_pct'    => 0,
            'noticias_qtd'  => 1,       // a própria fonte
        ];
        $scoreOut = DiscoverScore::calcular($trendParaScore);

        $intencao = DiscoverScore::rotuloIntencao($trendParaScore);
        $briefing = DiscoverAngulo::gerarBriefing($trendParaScore + ['intencao' => $intencao, 'volume_label' => '']);

        $sinais = DiscoverSinaisEditoriais::calcular(
            $trendParaScore + ['score' => $scoreOut['final']],
            (string)($briefing['angulo_principal'] ?? '')
        );

        // Resolve site_target: 'auto' → roteamento por cluster detectado, ou slug específico.
        // Se cluster_detect falhou, fallback pro cluster_hint da fonte. Senão, comocomprar.
        $siteTarget = trim((string)($fonte['site_target'] ?? 'auto'));
        if ($siteTarget === 'auto' || $siteTarget === '') {
            $clusterPraRota = (string)($sinais['cluster_detect']['key'] ?? $clusterHint);
            $siteTarget = self::roteamentoPorCluster($clusterPraRota);
        }

        // FILTRO DE NICHO (sites.php → nicho_required_terms)
        // Se o site alvo tem lista de termos exigidos, trend só passa quando contém 1+ deles
        // (no termo OU nos relacionados). Caso #leaodabarra (pivot 2026-05-02): site nicho
        // exclusivo do Esporte Clube Vitória — trends de outros clubes/esportes ficam
        // status='fora_escopo_nicho' em vez de poluir a fila.
        $nichoTerms = self::nichoRequiredTerms($siteTarget);
        if (!empty($nichoTerms)) {
            $haystack = mb_strtolower($termo . ' ' . implode(' ', $relacionados), 'UTF-8');
            $bateu = false;
            foreach ($nichoTerms as $t) {
                $tNorm = mb_strtolower(trim($t), 'UTF-8');
                if ($tNorm === '') continue;
                if (mb_strpos($haystack, $tNorm) !== false) { $bateu = true; break; }
            }
            if (!$bateu) {
                // Não bate com nenhum termo do nicho — rejeita silenciosamente.
                // Loga pra debug (logRejeicao espera modo/motivo/pontos/detalhes).
                $this->logRejeicao($termo, $fonte, [
                    'rejeitar' => true,
                    'modo'     => 'block',
                    'motivo'   => 'fora_escopo_nicho',
                    'pontos'   => 0,
                    'detalhes' => ['site' => $siteTarget, 'termos_exigidos' => count($nichoTerms)],
                ]);
                return null;
            }
        }

        // Decide status: auto_aprovar_score_min da fonte é o gate
        $limiteAutoAprovar = (float)($fonte['auto_aprovar_score_min'] ?? 7.0);
        $status = $scoreOut['final'] >= $limiteAutoAprovar ? 'aprovado' : 'novo';

        return [
            'site'            => $siteTarget,
            'termo'           => $termo,
            'categoria'       => implode(', ', array_map(
                fn($id) => TrendsTaxonomia::labelCategoriaGoogle($id),
                $categoriaIdsHint
            )),
            'categoria_ids'   => $categoriaIdsHint,
            'volume_busca'    => 0,
            'volume_label'    => '',
            'growth_pct'      => 0,
            'origem'          => 'pingo:' . (int)$fonte['id'],
            'status'          => $status,
            'score_discover'  => $scoreOut['final'],
            'score_detalhado' => $scoreOut,
            'intencao'        => $intencao,
            'angulo'          => (string)($briefing['angulo_principal'] ?? ''),
            'titulo'          => (string)($briefing['titulo_sugerido'] ?? ''),
            'briefing'        => $briefing,
            'noticias_qtd'    => 1,
            'relacionados'    => $relacionados,
            'pain'            => $sinais['pain'],
            'cluster_detect'  => $sinais['cluster_detect'],
            'arbitragem'      => $sinais['arbitragem'],
            'pingo_link'      => (string)($item['link'] ?? ''),
            'pingo_pub_ts'    => (int)($item['pub_ts'] ?? 0),
        ];
    }

    /**
     * Roteia trends para o site adequado pelo cluster detectado.
     * Usado quando a fonte tem site_target='auto' — em vez de mandar tudo pra comocomprar,
     * distribui pelos 6 sites por afinidade temática.
     *
     * Mapeamento conservador: cada cluster vai pra UM site (sem split). Sites sem entrada
     * dedicada (curiosidades_geral, default) caem em comocomprar como fallback.
     */
    public static function roteamentoPorCluster(string $clusterKey): string
    {
        $mapa = [
            'esportes'              => 'leaodabarra',
            'educacao'              => 'cursosenac',
            'noticias_info_critica' => 'vagasebeneficios',
            'negocios_financas'     => 'vagasebeneficios',
            'tecnologia'            => 'comocomprar',
            'lifestyle_consumo'     => 'ondecompraragora',
            'comidas_bebidas'       => 'comocomprar',
            'viagem_transporte'     => 'comocomprar',
            'automoveis'            => 'comocomprar',
            'saude_bem_estar'       => 'comocomprar',
            'entretenimento'        => 'leaodabarra',
            'entretenimento_cultura'=> 'leaodabarra',
            'curiosidades_geral'    => 'comocomprar',
        ];
        return $mapa[$clusterKey] ?? 'comocomprar';
    }

    /**
     * Carrega lista nicho_required_terms do sites.php pra um site específico.
     * Cacheada em memória estática pra evitar re-load. Retorna [] se site não tem nicho restrito.
     */
    private static array $nichoCache = [];
    public static function nichoRequiredTerms(string $siteSlug): array
    {
        if (array_key_exists($siteSlug, self::$nichoCache)) return self::$nichoCache[$siteSlug];
        $sitesPath = dirname(__DIR__) . '/_site_helper.php';
        if (!is_file($sitesPath)) { self::$nichoCache[$siteSlug] = []; return []; }
        if (!function_exists('sitesDisponiveis')) {
            require_once $sitesPath;
        }
        $sites = sitesDisponiveis();
        $cfg = $sites[$siteSlug] ?? [];
        $terms = (array)($cfg['nicho_required_terms'] ?? []);
        self::$nichoCache[$siteSlug] = $terms;
        return $terms;
    }

    // ═══════════════════════════════════════════════════════════════
    // STATE & PERSISTÊNCIA
    // ═══════════════════════════════════════════════════════════════

    public function estadoAtual(): array
    {
        return $this->carregarState();
    }

    /** Registra execução no histórico (limita a MAX_HISTORICO). */
    private function registrarExecucao(array &$state, int $fid, int $vistos, int $novos, int $salvos, ?string $erro): void
    {
        if (!isset($state['fontes'][$fid])) {
            $state['fontes'][$fid] = [
                'ultima_execucao'              => null,
                'ultimos_links'                => [],
                'contador_items_vistos_total'  => 0,
                'contador_items_salvos_total'  => 0,
                'ultimo_erro'                  => null,
                'ultimo_erro_em'               => null,
                'historico'                    => [],
            ];
        }
        $s =& $state['fontes'][$fid];
        $s['ultima_execucao'] = date('c');
        $s['contador_items_vistos_total'] = (int)($s['contador_items_vistos_total'] ?? 0) + $vistos;
        $s['contador_items_salvos_total'] = (int)($s['contador_items_salvos_total'] ?? 0) + $salvos;
        if ($erro !== null) {
            $s['ultimo_erro'] = $erro;
            $s['ultimo_erro_em'] = date('c');
        }
        $s['historico'][] = [
            'ts'     => date('c'),
            'vistos' => $vistos,
            'novos'  => $novos,
            'salvos' => $salvos,
            'erro'   => $erro,
        ];
        if (count($s['historico']) > self::MAX_HISTORICO) {
            $s['historico'] = array_slice($s['historico'], -self::MAX_HISTORICO);
        }
    }

    private function carregarFontes(): array
    {
        if (!is_file($this->pathFontes)) {
            return ['next_id' => 1, 'fontes' => []];
        }
        $raw = (string)@file_get_contents($this->pathFontes);
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new RuntimeException('JSON inválido em ' . $this->pathFontes);
        if (!isset($data['fontes'])) $data['fontes'] = [];
        if (!isset($data['next_id'])) $data['next_id'] = count($data['fontes']) + 1;
        return $data;
    }

    private function salvarFontes(array $data): void
    {
        $tmp = $this->pathFontes . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('falha serializando fontes');
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException("falha gravando {$tmp}");
        }
        if (!@rename($tmp, $this->pathFontes)) {
            @unlink($tmp);
            throw new RuntimeException("falha movendo {$tmp} → {$this->pathFontes}");
        }
    }

    private function carregarState(): array
    {
        if (!is_file($this->pathState)) {
            return ['fontes' => []];
        }
        $raw = (string)@file_get_contents($this->pathState);
        $data = json_decode($raw, true);
        if (!is_array($data)) return ['fontes' => []];
        if (!isset($data['fontes']) || !is_array($data['fontes'])) $data['fontes'] = [];
        return $data;
    }

    private function salvarState(array $data): void
    {
        $tmp = $this->pathState . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // FIX 3: erro silencioso era catastrófico — state não persistia, items
        // re-processavam infinitamente, gerando duplicatas no DB. Agora propaga.
        if ($json === false) {
            throw new RuntimeException('DiscoverPingo::salvarState — falha ao serializar state JSON');
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException("DiscoverPingo::salvarState — falha gravando temp {$tmp} (disco cheio? permissão?)");
        }
        if (!@rename($tmp, $this->pathState)) {
            @unlink($tmp);
            throw new RuntimeException("DiscoverPingo::salvarState — falha movendo {$tmp} → {$this->pathState}");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // HTTP helper
    // ═══════════════════════════════════════════════════════════════

    /**
     * Fetch paralelo de múltiplas URLs via curl_multi. Retorna [url => xml STRING ou Throwable].
     *
     * Performance: 10 feeds × 2s cada = 20s sequencial → 2-3s paralelo (limit pelo mais lento).
     * Mantém mesmas validações do fetchXml individual (HTML disfarçado, vazio, HTTP >=400).
     */
    private function fetchXmlMulti(array $urls): array
    {
        if (empty($urls)) return [];
        $mh = curl_multi_init();
        $handles = [];
        $userAgent = (string)($this->cfg['user_agent'] ?? 'Mozilla/5.0 DiscoverPingo/1.0');

        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_USERAGENT      => $userAgent,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9',
                    'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$url] = $ch;
        }

        // Loop até todos terminarem
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running > 0);

        $resultados = [];
        foreach ($handles as $url => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($body === false || $body === null) {
                $resultados[$url] = new RuntimeException("cURL falhou: " . ($err ?: 'sem corpo'));
                continue;
            }
            if ($code >= 400) {
                $resultados[$url] = new RuntimeException("HTTP {$code}");
                continue;
            }
            if ($body === '') {
                $resultados[$url] = new RuntimeException('resposta vazia');
                continue;
            }
            if (stripos($ct, 'html') !== false || stripos($body, '<!DOCTYPE html') === 0 || stripos(substr($body, 0, 200), '<html') !== false) {
                $resultados[$url] = new RuntimeException("servidor retornou HTML, não XML/RSS (content-type: {$ct})");
                continue;
            }
            $resultados[$url] = (string)$body;
        }
        curl_multi_close($mh);
        return $resultados;
    }

    private function fetchXml(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_USERAGENT      => (string)($this->cfg['user_agent'] ?? 'Mozilla/5.0 DiscoverPingo/1.0'),
            // ENCODING '' habilita auto-decode de gzip/deflate/br (crítico — G1 serve gzipped).
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false) throw new RuntimeException("cURL falhou: {$err}");
        if ($code >= 400) throw new RuntimeException("HTTP {$code}");
        if ($body === '') throw new RuntimeException('resposta vazia');

        // Detecta HTML disfarçado de RSS (URL errada comum em govs)
        if (stripos($ct, 'html') !== false || stripos($body, '<!DOCTYPE html') === 0 || stripos(substr($body, 0, 200), '<html') !== false) {
            throw new RuntimeException("servidor retornou HTML, não XML/RSS (content-type: {$ct}) — URL do feed provavelmente está errada");
        }
        return (string)$body;
    }

    // ═══════════════════════════════════════════════════════════════
    // FILTRO DE QUALIDADE — Camada 1 (rejeição) + Camada 2 (pontuação)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Aplica filtro em 2 camadas. Retorna decisão com diagnóstico completo.
     *
     * @return array {
     *   rejeitar: bool,                  // true = bloquear o trend
     *   motivo: string,                  // 'rejeicao_loteria' | 'pontuacao_baixa' | 'aprovado' | 'bypass_cluster' | ...
     *   pontos: int,                     // soma de pontos da camada 2
     *   detalhes: array,                 // breakdown por categoria
     *   modo: string                     // 'warn' | 'block'
     * }
     */
    public function aplicarFiltro(string $termo, array $fonte): array
    {
        $f = $this->carregarFiltros();
        $modo = (string)($f['modo'] ?? 'warn');
        $resultado = [
            'rejeitar' => false,
            'motivo'   => '',
            'pontos'   => 0,
            'detalhes' => [],
            'modo'     => $modo,
        ];

        // Bypass por fonte específica (feeds curados)
        $fid = (int)($fonte['id'] ?? 0);
        if (in_array($fid, (array)($f['bypass_fontes_ids'] ?? []), true)) {
            $resultado['motivo'] = 'bypass_fonte_id';
            $this->statsFiltro['aceitos']++;
            return $resultado;
        }

        // Bypass por cluster (esportes, sazonal_calendario)
        $clusterHint = (string)($fonte['cluster_hint'] ?? '');
        if (in_array($clusterHint, (array)($f['bypass_clusters'] ?? []), true)) {
            $resultado['motivo'] = 'bypass_cluster:' . $clusterHint;
            $this->statsFiltro['aceitos']++;
            return $resultado;
        }

        // ─── Camada 1: rejeição explícita por padrão ───
        foreach ((array)($f['rejeicao_categorias'] ?? []) as $catNome => $cat) {
            foreach ((array)($cat['regex'] ?? []) as $re) {
                if (@preg_match($re, $termo)) {
                    $resultado['rejeitar'] = ($modo === 'block');  // 'warn' marca mas não bloqueia
                    $resultado['motivo']   = 'rejeicao_' . $catNome;
                    $resultado['detalhes']['regex_match'] = $re;
                    $this->statsFiltro['rejeitados']++;
                    $this->statsFiltro['motivos'][$resultado['motivo']] =
                        ($this->statsFiltro['motivos'][$resultado['motivo']] ?? 0) + 1;
                    return $resultado;
                }
            }
        }

        // ─── Camada 2: pontuação por categoria ───
        $pontos = 0;
        $detalhes = [];
        $termoLow = mb_strtolower($termo, 'UTF-8');

        foreach ((array)($f['aprovacao_pontos'] ?? []) as $catNome => $cat) {
            $peso = (int)($cat['peso'] ?? 1);
            $matched = false;
            // palavras (literais com regex word-boundary, case-insensitive)
            foreach ((array)($cat['palavras'] ?? []) as $palavra) {
                if (@preg_match('/\b' . $palavra . '\b/iu', $termoLow)) { $matched = true; break; }
            }
            // regex livres
            if (!$matched) {
                foreach ((array)($cat['regex'] ?? []) as $re) {
                    if (@preg_match($re, $termo)) { $matched = true; break; }
                }
            }
            if ($matched) {
                $pontos += $peso;
                $detalhes[$catNome] = $peso;
            }
        }

        $resultado['pontos']   = $pontos;
        $resultado['detalhes'] = $detalhes;

        $minPontos = (int)($f['min_pontos_aprovacao'] ?? 2);
        if ($pontos < $minPontos) {
            $resultado['rejeitar'] = ($modo === 'block');
            $resultado['motivo']   = 'pontuacao_baixa';
            $this->statsFiltro['rejeitados']++;
            $this->statsFiltro['motivos']['pontuacao_baixa'] =
                ($this->statsFiltro['motivos']['pontuacao_baixa'] ?? 0) + 1;
            return $resultado;
        }

        $resultado['motivo'] = 'aprovado';
        $this->statsFiltro['aceitos']++;
        return $resultado;
    }

    /** Carrega filtros do JSON (cache em memória durante a execução). */
    private function carregarFiltros(): array
    {
        if ($this->filtrosCache !== null) return $this->filtrosCache;
        $defaults = [
            'modo'                 => 'warn',
            'min_pontos_aprovacao' => 2,
            'bypass_clusters'      => ['esportes', 'sazonal_calendario'],
            'bypass_fontes_ids'    => [],
            'rejeicao_categorias'  => [],
            'aprovacao_pontos'     => [],
        ];
        if (!is_file($this->pathFiltros)) {
            $this->filtrosCache = $defaults;
            return $defaults;
        }
        $raw = (string)@file_get_contents($this->pathFiltros);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->filtrosCache = $defaults;
            return $defaults;
        }
        $this->filtrosCache = array_merge($defaults, $data);
        $this->statsFiltro['modo'] = (string)($this->filtrosCache['modo'] ?? 'warn');
        return $this->filtrosCache;
    }

    /** Loga rejeição em arquivo append-only pra auditoria/calibração. */
    private function logRejeicao(string $termo, array $fonte, array $resultado): void
    {
        $linha = sprintf(
            "[%s] [%s] [%s] fonte=%s · termo='%s' · pontos=%d · detalhes=%s\n",
            date('Y-m-d H:i:s'),
            $resultado['modo'],
            $resultado['motivo'],
            (string)($fonte['nome'] ?? '?'),
            mb_substr($termo, 0, 80),
            (int)($resultado['pontos'] ?? 0),
            json_encode($resultado['detalhes'] ?? [], JSON_UNESCAPED_UNICODE)
        );
        @file_put_contents($this->pathLogFiltro, $linha, FILE_APPEND);
    }
}
