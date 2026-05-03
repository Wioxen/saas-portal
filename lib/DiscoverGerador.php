<?php
/**
 * Pipeline Discover → Geração → Publicação.
 * Orquestra: briefing + fetch artigos + scrape + Claude + WordPress + DB + auditoria.
 *
 * Reusa lib/Maquina.php existente — só adiciona a camada de briefing e auditoria.
 */
require_once __DIR__ . '/DiscoverAuditor.php';
require_once __DIR__ . '/DiscoverPostProcess.php';
require_once __DIR__ . '/DiscoverQualityScore.php';
require_once __DIR__ . '/DiscoverTituloValidator.php';
require_once __DIR__ . '/DiscoverTituloRefazer.php';
require_once __DIR__ . '/DiscoverGanchoExtrator.php';
require_once __DIR__ . '/DiscoverKeywordLongTail.php';
require_once __DIR__ . '/DiscoverClusterMatcher.php';
require_once __DIR__ . '/DiscoverImagemSEO.php';
require_once __DIR__ . '/DiscoverLinkValidator.php';
require_once __DIR__ . '/DiscoverPainClassifier.php';
require_once __DIR__ . '/DiscoverRPM.php';
require_once __DIR__ . '/DiscoverPromptBuilder.php';
require_once __DIR__ . '/DiscoverProductRanker.php';

class DiscoverGerador
{
    private Serper $serper;
    private Scraper $scraper;
    private Claude $claude;
    private Wordpress $wp;
    private TrendsArticles $artigos;
    private DiscoverDb $db;
    private array $cfg;

    public function __construct(array $cfg, DiscoverDb $db)
    {
        // Validações precoces — prefere falhar com erro claro em vez de mistério depois.
        foreach (['wp_url', 'wp_user', 'wp_app_password', 'anthropic_api_key', 'serper_api_key'] as $k) {
            if (empty($cfg[$k])) {
                throw new RuntimeException("DiscoverGerador: config['{$k}'] ausente. Verifique .env e (se multi-site) sites.php.");
            }
        }
        // Pretty links prefix deve ser [a-z0-9_-] ou default 'go'
        $prefix = trim((string)($cfg['pretty_links_prefix'] ?? 'go'), '/');
        if ($prefix === '' || !preg_match('/^[a-z0-9_-]+$/i', $prefix)) {
            throw new RuntimeException("DiscoverGerador: pretty_links_prefix inválido ('{$prefix}'). Use apenas [a-z0-9_-], ex: 'go'.");
        }
        $cfg['pretty_links_prefix'] = $prefix;

        $this->cfg     = $cfg;
        $this->db      = $db;
        $this->serper  = new Serper($cfg['serper_api_key']);
        $this->scraper = new Scraper($cfg['user_agent'], $cfg['scrape_timeout'] ?? 15);
        $this->claude  = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model']);
        $this->wp      = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        $this->artigos = new TrendsArticles($this->serper, $this->scraper, $cfg['user_agent']);
    }

    /**
     * Resolve a config correta para um trend, levando em conta o site_target dele.
     *
     * Fundamental em multi-site: o portal pode estar com site=A no header,
     * mas o trend foi capturado/salvo com site=B (ex: pingo cursosenac com user navegando comocomprar).
     * Se usássemos $this->cfg cego, o push iria para o site errado, a URL apontaria
     * pra wp_url errado, etc. Esta função mescla cfg base + sites[$slug] específico.
     *
     * @param array $trend trend que vai ser processado
     * @return array cfg ajustado para o site correto
     */
    private function cfgDoTrend(array $trend): array
    {
        $siteTrend = trim((string)($trend['site'] ?? ''));
        $siteAtivo = (string)($this->cfg['_site_slug'] ?? '');
        // Se não tem trend['site'] OU bate com o ativo, retorna cfg atual sem custo.
        if ($siteTrend === '' || $siteTrend === $siteAtivo) return $this->cfg;

        // Mescla: tenta carregar definições de sites.php e re-aplicar via _site_helper.
        $sitesPath = __DIR__ . '/../_site_helper.php';
        if (!is_file($sitesPath)) return $this->cfg;
        require_once $sitesPath;
        $sites = sitesDisponiveis();
        if (!isset($sites[$siteTrend])) return $this->cfg;

        $cfgMesclado = $this->cfg;
        aplicarSite($cfgMesclado, $sites, $siteTrend);
        return $cfgMesclado;
    }

    /**
     * Mapeia cluster editorial detectado → nomes de categoria WP.
     * CategoryMatcher faz fuzzy match com categorias existentes (threshold 70%).
     * Se nenhuma match, cria nova com esse nome.
     *
     * Pra cluster=esportes, refina baseado no termo (Brasileirão/Libertadores/etc)
     * pra silo SEO mais específico — útil pro leaodabarra.
     */
    private static function clusterParaCategorias(array $cluster, array $trend): array
    {
        $key = (string)($cluster['key'] ?? 'curiosidades_geral');
        $termoLow = mb_strtolower((string)($trend['termo'] ?? ''));

        $base = match ($key) {
            'esportes'                   => ['Esportes'],
            'noticias_info_critica'      => ['Notícias'],
            'negocios_financas'          => ['Economia'],
            'leis_governo'               => ['Direitos'],
            'saude_bem_estar'            => ['Saúde'],
            'educacao_servicos_publicos' => ['Educação'],
            'educacao'                   => ['Educação'],
            'entretenimento'             => ['Entretenimento'],
            'tecnologia'                 => ['Tecnologia'],
            'viagem_transporte'          => ['Viagens'],
            'automoveis'                 => ['Carros'],
            'comidas_bebidas'            => ['Comida'],
            'lifestyle_consumo'          => ['Lifestyle'],
            'curiosidades_geral'         => ['Curiosidades'],
            default                      => ['Notícias'],
        };

        // Refinement esportes: adiciona subcategoria por torneio detectado no termo
        if ($key === 'esportes') {
            $torneio = match (true) {
                str_contains($termoLow, 'brasileirão') || str_contains($termoLow, 'brasileirao') => 'Brasileirão',
                str_contains($termoLow, 'libertadores') => 'Libertadores',
                str_contains($termoLow, 'sul-americana') || str_contains($termoLow, 'sulamericana') => 'Sul-Americana',
                str_contains($termoLow, 'champions') => 'Champions League',
                str_contains($termoLow, 'copa do mundo') || str_contains($termoLow, 'seleção brasileira') => 'Seleção',
                str_contains($termoLow, 'fórmula 1') || str_contains($termoLow, 'formula 1') || str_contains($termoLow, ' f1 ') => 'Fórmula 1',
                str_contains($termoLow, 'ufc') || str_contains($termoLow, 'mma') => 'MMA',
                str_contains($termoLow, 'nba') => 'Basquete',
                default => null,
            };
            if ($torneio !== null) $base[] = $torneio;
        }

        return $base;
    }

    /**
     * Extrai URLs oficiais (gov.br, edu.br, jus.br, *.org.br) das fontes scrapeadas.
     * Inclui URLs DE atributos href OU texto cru (alguns scrapers perdem o href).
     * Retorna lista única, ordenada por especificidade (subdomínios > domínios genéricos).
     */
    private static function extrairUrlsOficiais(array $fontesOk, array $textosFontes): array
    {
        $urls = [];
        // 1. URLs já scrapeadas com href em atributos (links no HTML das fontes)
        foreach ($fontesOk as $f) {
            $links = $f['fonte']['content']['links'] ?? [];
            foreach ($links as $l) {
                $u = is_array($l) ? ($l['href'] ?? '') : (string)$l;
                if ($u !== '') $urls[] = $u;
            }
        }
        // 2. URLs em texto puro das fontes (regex pra http/https + domínio oficial)
        foreach ($textosFontes as $tf) {
            if (preg_match_all('#https?://[a-z0-9.-]+\.(?:gov|edu|jus|org|mil)\.br[^\s<>"\']*#i', $tf, $m)) {
                foreach ($m[0] as $u) $urls[] = $u;
            }
            // Também menções literais sem http (ex: "enem.inep.gov.br/participante")
            if (preg_match_all('#(?<![/\w@])([a-z][a-z0-9-]*(?:\.[a-z0-9-]+){2,})(?:/[^\s<>"\')]*)?#i', $tf, $m)) {
                foreach ($m[0] as $u) {
                    if (preg_match('#\.(gov|edu|jus|mil)\.br#i', $u)) {
                        $urls[] = preg_match('#^https?://#i', $u) ? $u : 'https://' . $u;
                    }
                }
            }
        }

        // Filtra: limpa trailing punctuation, normaliza, dedupa
        $urlsLimpas = [];
        foreach ($urls as $u) {
            $u = rtrim($u, '.,;:)]\'\"');
            $u = trim($u);
            if ($u === '' || strlen($u) > 200) continue;
            // Aceita só URLs oficiais (gov/edu/jus/mil .br)
            if (!preg_match('#\.(gov|edu|jus|mil)\.br#i', $u)) continue;
            $urlsLimpas[mb_strtolower($u)] = $u;
        }

        // Ordena: URLs mais específicas (com path) antes de raiz; subdomínios antes de domínio puro
        $lista = array_values($urlsLimpas);
        usort($lista, fn($a, $b) => strlen($b) <=> strlen($a));
        return $lista;
    }

    /**
     * Erros transitórios do LLM que justificam fallback automático para GPT.
     * Conservador: só aciona fallback se o erro é claramente de infraestrutura
     * (não faz fallback por erro de negócio — ex: "termo vazio", "parse JSON falhou").
     */
    private static function deveTentarFallback(string $erro): bool
    {
        if ($erro === '') return false;
        $padroes = [
            '/timeout|timed?\s*out/i',
            '/\b429\b|rate\s*limit|too many requests/i',
            '/\b5\d{2}\b/',                     // 500, 502, 503, 504
            '/overloaded|service unavailable/i',
            '/connection (reset|refused|closed)/i',
            '/cURL error (6|7|28|35|52|56)/i',  // DNS/network/timeout/SSL do cURL
            '/claude[^a-z]*(indisp|unavail|fail|error)/i',
            '/anthropic[^a-z]*(down|error)/i',
            '/circuit .* (open|aberto)/i',      // CircuitBreaker abriu pra Anthropic → tenta GPT
        ];
        foreach ($padroes as $p) {
            if (preg_match($p, $erro)) return true;
        }
        return false;
    }

    /**
     * Detecta erro de "ambos LLMs indisponíveis" (Claude E GPT em circuit open OU ambos
     * com falha transitória). Sinaliza ao DB que vale RETRY, não FAIL definitivo.
     */
    private static function ambosLlmsIndisponiveis(string $erroCombinado): bool
    {
        // Heurística: se o texto menciona circuit ABERTO em ambas APIs OU se "Ambos LLMs"
        // E pelo menos um padrão transitório aparece duplicado.
        if (preg_match('/circuit .* (open|aberto)/i', $erroCombinado) &&
            preg_match_all('/\b(timeout|5\d{2}|429|circuit)\b/i', $erroCombinado) >= 2) {
            return true;
        }
        // Caso explícito: ambos circuits abertos
        return preg_match('/anthropic.*open|aberto.*openai|openai.*open|circuit.*anthropic.*circuit.*openai/i', $erroCombinado) === 1;
    }

    /**
     * Gera e publica o artigo para um trend específico.
     *
     * @param array  $trend   shape: ['termo'=>..., 'briefing'=>..., 'score_discover'=>..., 'id'=>...]
     * @param string $formato seo|discover|news|serp (default: discover)
     * @return array ['ok', 'post_id', 'titulo', 'edit_url', 'fontes_usadas', 'erro', 'llm_fallback']
     */
    public function gerar(array $trend, string $formato = 'discover'): array
    {
        // Roteamento LLM: por padrão Claude (Sonnet) primeiro, com fallback pra GPT
        // em erro transitório (lógica no caminho Claude abaixo, ~linha 695).
        // Se default_llm='openai' explícito (raro, debug/testes), GPT primeiro com
        // fallback simétrico pra Claude. NÃO há mais gate por score — qualidade do
        // post vale mais que economia marginal de GPT-mini, e bugs em GPT (ex:
        // max_tokens em modelos novos) não devem derrubar o pipeline inteiro.
        if (($this->cfg['default_llm'] ?? 'claude') === 'openai') {
            require_once __DIR__ . '/OpenAI.php';
            require_once __DIR__ . '/DiscoverGeradorGPT.php';
            $modelo = $this->cfg['openai_model'] ?? 'gpt-4o-mini';
            $gpt = new DiscoverGeradorGPT($this->cfg, $this->db, $modelo);
            $respGpt = $gpt->gerar($trend, $formato);
            if (!empty($respGpt['ok'])) {
                return $respGpt;
            }
            // GPT falhou — vale a pena tentar Claude?
            if (self::deveTentarFallback((string)($respGpt['erro'] ?? ''))) {
                // Fall-through pro caminho Claude, marcando origem do fallback
                $trend['_fallback_from_gpt'] = true;
                $trend['_gpt_erro_original'] = $respGpt['erro'] ?? null;
            } else {
                return $respGpt;
            }
        }

        require_once __DIR__ . '/DiscoverProgress.php';
        $termo = trim((string)($trend['termo'] ?? ''));
        if ($termo === '') return ['ok' => false, 'erro' => 'termo vazio'];
        $trendId = (int)($trend['id'] ?? 0);
        // FIX 5 — briefing nulo quebrava bloco persona/prompt. Default array vazio + validação no render.
        $briefing = is_array($trend['briefing'] ?? null) ? $trend['briefing'] : [];
        $progress = new DiscoverProgress($trendId);

        // FIX 1+2 — resolve cfg do site do TREND (não do site ativo no header do portal).
        // Crítico em multi-site: trend pode ser de cursosenac mas portal está em comocomprar.
        // OneSignal, URL pública, afiliado Pretty Link — tudo precisa apontar pro site correto.
        $cfgTrend = $this->cfgDoTrend($trend);

        // 0-KILL) Pipeline pausado via .env PIPELINE_PAUSED=1. Defesa de emergência.
        try {
            require_once __DIR__ . '/KillSwitch.php';
            if (KillSwitch::ativo()) {
                $progress->erro('pipeline pausado');
                return KillSwitch::retornoErro();
            }
        } catch (Throwable $e) { /* kill switch é PLUS — não pode bloquear se ele próprio quebra */ }

        // 0-COST) GUARD — hard cap diário (defesa contra runaway de tokens/Serper).
        // Bloqueia ANTES de coletar fontes pra economizar até a chamada Serper.
        // Configurável via .env: COST_DAILY_LIMIT_USD, COST_DAILY_LIMIT_PER_SITE_USD
        try {
            require_once __DIR__ . '/CostGuard.php';
            $costGuard = CostGuard::verificar((string)($trend['site'] ?? ''), $this->db);
            if (!$costGuard['ok']) {
                $progress->erro($costGuard['motivo']);
                if ($trendId > 0) {
                    $this->db->updateStatus($trendId, 'bloqueado_cost_cap', [
                        'cost_guard_motivo'        => $costGuard['motivo'],
                        'cost_guard_gasto_global'  => $costGuard['gasto_global'],
                        'cost_guard_gasto_site'    => $costGuard['gasto_site'],
                    ]);
                }
                return [
                    'ok'           => false,
                    'erro'         => $costGuard['motivo'],
                    'cost_guard'   => $costGuard,
                ];
            }
        } catch (Throwable $e) { /* guard é PLUS — falha não bloqueia geração */ }

        // 1+2) Coleta fontes + enrichment via DiscoverFontes (compartilhado com GPT)
        require_once __DIR__ . '/DiscoverFontes.php';
        $progress->reportar('listando', 'Google News + Serper');
        $coletor = new DiscoverFontes($this->cfg, $this->artigos, $this->scraper);
        $progress->reportar('scraping', 'Scrape + enrichment das candidatas');
        $col = $coletor->coletar($termo, 5);
        if (!$col['ok']) {
            $progress->erro($col['erro']);
            return [
                'ok' => false,
                'erro' => $col['erro'],
                'fontes_tentadas' => $col['fontes_tentadas'] ?? 0,
                'fontes_ok'       => count($col['fontes_ok']),
                'chars_totais'    => $col['chars_totais'],
            ];
        }
        $progress->reportar('enriquecendo', count($col['fontes_ok']) . ' fontes · ' . $col['chars_totais'] . ' chars');
        $fontesOk   = $col['fontes_ok'];
        $totalChars = $col['chars_totais'];

        $urlsFinais = array_map(fn($x) => $x['url'], $fontesOk);
        // Guarda referência das fontes scrapeadas para auditor pós-geração
        $textosFontes = [];
        foreach ($fontesOk as $f) {
            $textoFull = '';
            if (!empty($f['fonte']['meta']['title'])) $textoFull .= $f['fonte']['meta']['title'] . "\n";
            if (!empty($f['fonte']['content']['paragraphs'])) {
                $textoFull .= implode("\n", $f['fonte']['content']['paragraphs']);
            }
            $textosFontes[] = $textoFull;
        }

        // 2.5) PRE-PUBLISH LINT — valida ANTES de chamar Sonnet ($0.30/post).
        //      Rejeita: blocklist editorial, sem fontes válidas, termo gigante, duplicado >90% sim
        //      com post existente. Score 0-100, threshold default 50. Economia ~$3/dia em volume.
        $lintEnabled = !isset($this->cfg['pre_publish_lint_enabled']) || !empty($this->cfg['pre_publish_lint_enabled']);
        if ($lintEnabled) {
            require_once __DIR__ . '/PrePublishLint.php';
            $clusterDet = DiscoverClusterMatcher::detectar(['termo' => $termo, 'categoria_ids' => $trend['categoria_ids'] ?? []]);
            $lintTrend = $trend + ['cluster_detect' => $clusterDet, 'site' => $trend['site'] ?? ($cfgTrend['_site_slug'] ?? '')];
            $lintThreshold = (int)($this->cfg['pre_publish_lint_threshold'] ?? PrePublishLint::THRESHOLD_DEFAULT);
            // Passa cfg com empresa/subtipo_nicho/termos_canibal/_site_slug pra
            // habilitar cross-site dedup + pre-flight de especialização (Caminho C).
            $lint = PrePublishLint::avaliar($lintTrend, $fontesOk, $this->db, $lintThreshold, $cfgTrend);
            if (!$lint['aprovado']) {
                $progress->erro('lint reprovou: ' . implode(',', $lint['motivos']));
                $this->db->updateStatus($trendId, 'rejeitado_lint', [
                    'lint_score' => $lint['score'],
                    'lint_motivos' => $lint['motivos'],
                    'lint_detalhes' => $lint['detalhes'],
                ]);
                return [
                    'ok' => false,
                    'erro' => 'pre_publish_lint: ' . implode(',', $lint['motivos']) . ' (score=' . $lint['score'] . ')',
                    'lint' => $lint,
                ];
            }

            // UPDATE DETECTOR — antes de gastar Sonnet, checa se já há post similar do site.
            // Se sim, redireciona pra DiscoverReviewer (atualizar) em vez de criar duplicado.
            // Preserva PageRank + idade + backlinks. Sinaliza freshness pro Discover.
            $updateEnabled = !isset($this->cfg['update_detector_enabled']) || !empty($this->cfg['update_detector_enabled']);
            if ($updateEnabled) {
                try {
                    require_once __DIR__ . '/DiscoverUpdateDetector.php';
                    $siteAtual = (string)($trend['site'] ?? $cfgTrend['_site_slug'] ?? '');
                    $rec = DiscoverUpdateDetector::analisar($termo, $siteAtual, $this->db);
                    if ($rec['acao'] === 'update' && !empty($rec['post_existente']['post_id'])) {
                        $progress->reportar('redirect_update', 'Trend similar a post existente — Reviewer');
                        require_once __DIR__ . '/DiscoverReviewer.php';
                        $rev = new DiscoverReviewer($this->cfg, $this->db);
                        $idTrendExistente = (int)$rec['post_existente']['id'];
                        $resR = $rev->revisar($idTrendExistente);
                        $this->db->updateStatus($trendId, 'descartado_update_existente', [
                            'redirected_to_post_id' => (int)$rec['post_existente']['post_id'],
                            'similaridade'          => $rec['similaridade'] ?? 0,
                            'update_motivo'         => $rec['motivo'],
                        ]);
                        return [
                            'ok'                    => !empty($resR['ok']),
                            'modo'                  => 'update',
                            'redirected_to_post_id' => (int)$rec['post_existente']['post_id'],
                            'similaridade'          => $rec['similaridade'] ?? 0,
                            'motivo'                => $rec['motivo'],
                            'reviewer_result'       => $resR,
                        ];
                    }
                } catch (Throwable $e) { /* update detector é PLUS — segue criação normal */ }
            }
        }

        // 3) Briefing → bloco de instrução extra pro Claude
        $progress->reportar('montando_prompt', 'Injetando briefing + manifesto editorial');
        $blocos = $this->briefingParaBlocos($briefing, $termo);

        // 3-CTR) INTELIGÊNCIA SERP — autocomplete + related + PAA reais via Serper.
        // Faz 1 post cobrir 5+ queries diferentes (mais tráfego long-tail) + FAQ schema
        // com perguntas LITERAIS do PAA (rich snippet quase garantido).
        // Cacheado 12h por termo. Fail-open: se Serper down, segue sem intel.
        try {
            require_once __DIR__ . '/DiscoverCtrIntel.php';
            $intel = DiscoverCtrIntel::obter($termo, $this->serper);
            $blocoIntel = DiscoverCtrIntel::paraPromptContext($intel);
            if ($blocoIntel !== '') $blocos[] = $blocoIntel;
        } catch (Throwable $e) { /* CTR Intel é PLUS — não bloqueia */ }

        // 3-SERP) ANÁLISE COMPETITIVA — top 10 do termo, gap de tamanho/freshness/ângulos.
        // Sonnet recebe diretivas explícitas pra VENCER os concorrentes (mais palavras
        // se eles são curtos, freshness se todos antigos, ângulos não cobertos).
        // Cacheado 24h. Reusa Serper que já temos.
        try {
            require_once __DIR__ . '/DiscoverSerpAnalyzer.php';
            $serp = DiscoverSerpAnalyzer::analisar($termo, $this->serper);
            $blocoSerp = DiscoverSerpAnalyzer::paraPromptContext($serp);
            if ($blocoSerp !== '') $blocos[] = $blocoSerp;
        } catch (Throwable $e) { /* SERP analyzer é PLUS */ }

        // 3a) FIX 13 — URLs OFICIAIS das fontes pro Claude preservar como links no artigo.
        //     Antes, fontes mencionavam "enem.inep.gov.br" e Claude reproduzia sem href.
        //     Agora extraímos URLs oficiais (gov.br, edu.br, mec.gov.br…) e instruímos a manter o link.
        $urlsOficiais = self::extrairUrlsOficiais($fontesOk, $textosFontes);
        if (!empty($urlsOficiais)) {
            $bloco = "═══ URLs OFICIAIS DAS FONTES (PRESERVE COMO LINKS NO ARTIGO) ═══\n"
                   . "Estas URLs aparecem nas fontes que você está usando. SEMPRE que o artigo mencionar\n"
                   . "o serviço/portal, INCLUA O LINK em <a href=\"...\" target=\"_blank\" rel=\"noopener\">.\n"
                   . "Não cite a URL como texto puro — vira link clicável.\n\n";
            foreach (array_slice($urlsOficiais, 0, 8) as $u) {
                $bloco .= "  • {$u}\n";
            }
            $blocos[] = $bloco;
        }

        // 3b) Cluster editorial — detecta nicho e anexa prompt específico (compliance + gatilho + ângulos)
        $cluster = DiscoverClusterMatcher::detectar($trend);
        $blocos[] = DiscoverClusterMatcher::instrucaoProPrompt($cluster);

        // 3b-bis) Cluster=esportes → blocos compatíveis com formato texto do prompt.md
        // (não pede JSON mid-prompt, evita o bug do SportsEvent original).
        // Foca em 4 pilares: anti-alucinação, profundidade, diferencial, título Discover-friendly.
        if (($cluster['key'] ?? '') === 'esportes') {
            $blocos[] = "\n═══ ESPORTES — CONTEXTUALIZE A FONTE ═══\n"
                      . "ANTES de qualquer coisa, leia as fontes anexadas COMO UM EDITOR DE ESPORTE\n"
                      . "experiente leria. Não use template fixo. Cada matéria pede sua estrutura.\n\n"
                      . "PASSO 1 — DECIFRE O QUE A FONTE ESTÁ DIZENDO\n"
                      . "  · Que evento/fato a fonte cobre? (jogo, transferência, lesão, polêmica,\n"
                      . "    tabela, declaração, mercado, análise, arbitragem, conquista, etc)\n"
                      . "  · Quais são as ENTIDADES principais? (times, atletas, técnicos, dirigentes,\n"
                      . "    competições, locais, datas, valores)\n"
                      . "  · Que DADOS específicos a fonte traz? (placar, escalação, valor da multa,\n"
                      . "    tempo de afastamento, posição na tabela, número de gols, histórico)\n"
                      . "  · Há uma virada/diferencial? (algo inesperado, dado raro, conexão histórica)\n\n"
                      . "PASSO 2 — IDENTIFIQUE A PERGUNTA DO LEITOR\n"
                      . "  Quem busca o termo do trend, o que QUER saber? Exemplos:\n"
                      . "  · 'Palmeiras x Santos hoje' → onde, quando, quem joga\n"
                      . "  · 'Raphinha alvo saudita' → quanto, quando, qual clube, posição do jogador\n"
                      . "  · 'Hulk fora do Atlético' → por quê, até quando, impacto no jogo\n"
                      . "  · 'Tabela Brasileirão' → posições atualizadas, G6, Z4, próximos jogos\n"
                      . "  · 'Norris pole Miami' → tempo, comparação rivais, expectativa pra corrida\n\n"
                      . "PASSO 3 — CONSTRUA A ESTRUTURA QUE MELHOR RESPONDE\n"
                      . "  · Use H2/H3 com nomes que CORRESPONDEM ao que a matéria entrega\n"
                      . "  · NÃO copie estrutura padronizada de outro tipo de matéria\n"
                      . "  · Inclua APENAS o que a fonte cobre + o que faz sentido pro leitor\n"
                      . "  · Se faltar dado pra uma seção, não invente — corte a seção\n"
                      . "  · Profundidade vem dos DADOS REAIS, não de prosa genérica\n\n"
                      . "EXEMPLOS DE ADAPTAÇÃO (sem ser template):\n"
                      . "  Matéria sobre TRANSFERÊNCIA não tem 'onde assistir' nem 'escalação'.\n"
                      . "  Matéria sobre LESÃO não tem 'horário do jogo' (só se for impacto direto).\n"
                      . "  Matéria sobre TABELA não tem 'escalação confirmada' (irrelevante).\n"
                      . "  Matéria sobre ANÁLISE TÁTICA pode incluir esquema 4-3-3 visualmente.\n"
                      . "  Matéria sobre F1 usa 'pole', 'volta mais rápida', 'pit stop' (não escalação).\n"
                      . "  Matéria sobre POLÊMICA foca posicionamento das partes + base regulatória.\n\n"
                      . "REGRA DE OURO: a estrutura é DERIVADA da fonte, não imposta de fora.\n"
                      . "═══ FIM CONTEXTUALIZAÇÃO ═══\n";

            $blocos[] = "\n═══ ESPORTES — DISCIPLINA FACTUAL (universal) ═══\n"
                      . "Aplica-se a TODOS os tipos acima:\n"
                      . "  ✓ Cite APENAS jogadores, técnicos, valores, datas, formações que aparecem\n"
                      . "    EXPLICITAMENTE nas fontes anexadas. Sem fonte ≠ inventar.\n"
                      . "  ✓ Se faltar dado importante, escreva 'a confirmar' / 'não divulgado' /\n"
                      . "    'dado não disponível'. NUNCA invente.\n"
                      . "  ✗ NÃO use conhecimento de treino pra completar lacuna. Transferências,\n"
                      . "    escalações e estados de carreira mudam constantemente. Só fontes scrapeadas.\n"
                      . "═══ FIM DISCIPLINA FACTUAL ═══\n";

            $blocos[] = "\n═══ ESPORTES — CITAR FONTES SEM PARECER CÓPIA ═══\n"
                      . "PROIBIDO citar veículo como BASE do artigo:\n"
                      . "  ✗ 'baseado no Terra/UOL/Globo' / 'segundo o site X' / 'de acordo com portal Y'\n"
                      . "    em parágrafo de abertura\n"
                      . "Em vez disso:\n"
                      . "  ✓ Cite FONTE PRIMÁRIA: 'CBF confirmou', 'clube divulgou em comunicado',\n"
                      . "    'transmissora oficial anunciou', 'agente do jogador disse', 'STJD decidiu'\n"
                      . "  ✓ Veículo só pode ser mencionado no MEIO do texto, nunca como base.\n"
                      . "═══ FIM CITAR FONTES ═══\n";

            $blocos[] = "\n═══ ESPORTES — ESTRUTURA DO ARTIGO (universal) ═══\n"
                      . "  ✗ NÃO inclua <h1> no body. WP renderiza via tema. Duplicate H1 = penalização.\n"
                      . "  ✓ Use H2/H3. Subseções H3 dentro de H2 quando o conteúdo justificar.\n"
                      . "  ✓ Primeira frase é o FATO PRINCIPAL extraído da fonte — específico, factual,\n"
                      . "    sem genericismo. Exemplos do estilo (não são templates a copiar):\n"
                      . "    Pré-jogo: 'Palmeiras e Santos se enfrentam neste sábado às 18h30 no Allianz.'\n"
                      . "    Mercado:  'Raphinha está no radar de clube saudita por € 100mi, segundo o\n"
                      . "               agente do jogador.'\n"
                      . "    Lesão:    'Neymar sentiu lesão muscular no treino e ficará fora por 4 semanas.'\n"
                      . "    F1:       'Norris conquistou pole position do GP de Miami neste sábado.'\n"
                      . "    Polêmica: 'STJD denunciou três dirigentes do Vitória pelo art. 258 do CBJD.'\n"
                      . "  ✗ NUNCA: 'Buscas por X saltaram esta semana...' (meta-narração genérica)\n"
                      . "  ✗ NUNCA: bloco 'AO VIVO HOJE às XX' em matéria que NÃO é sobre partida\n"
                      . "    iminente. Bloco de urgência só faz sentido em pré-jogo confirmado.\n"
                      . "  ✓ Se for pré-jogo de partida hoje/amanhã, pode incluir bloco curto:\n"
                      . "    <p><strong>⚽ HOJE às [hora] — [transmissão], com [gancho da matéria].</strong></p>\n"
                      . "═══ FIM ESTRUTURA ═══\n";

            $blocos[] = "\n═══ ESPORTES — ENTIDADES (universal) ═══\n"
                      . "Repita NOME COMPLETO 1-2x ao longo do artigo (NER ajuda Google):\n"
                      . "  ✓ 'Campeonato Brasileiro Série A' (1ª) → 'Brasileirão' (depois)\n"
                      . "  ✓ 'Sociedade Esportiva Palmeiras' (1ª) → 'Palmeiras' (depois)\n"
                      . "  ✓ 'Conmebol Libertadores' (1ª) → 'Libertadores' (depois)\n"
                      . "  ✓ Jogador com posição/função na 1ª menção: 'Endrick (atacante)'\n\n"
                      . "Palavras-chave secundárias VEM DO PRÓPRIO CONTEÚDO. Use o vocabulário\n"
                      . "natural do tema:\n"
                      . "  · Partida → 'ao vivo', 'transmissão', 'horário', 'escalação' (apenas se\n"
                      . "    a matéria realmente cobre uma partida iminente)\n"
                      . "  · Mercado → 'proposta', 'salário', 'multa', 'agente', 'janela'\n"
                      . "  · Lesão  → 'recuperação', 'tempo afastado', 'departamento médico'\n"
                      . "  · F1     → 'pole', 'volta mais rápida', 'pit stop', 'safety car'\n"
                      . "  · Tabela → 'classificação', 'G6', 'Z4', 'rebaixamento'\n"
                      . "  · Etc — adapte ao tema da matéria.\n\n"
                      . "REGRA: cada keyword deve fazer sentido NATURAL na frase. Sem stuffing.\n"
                      . "Se uma keyword não cabe naturalmente, NÃO force.\n"
                      . "═══ FIM ENTIDADES ═══\n";

            $blocos[] = "\n═══ ESPORTES — BACKLINKS EXTERNOS DE AUTORIDADE ═══\n"
                      . "Pra reforçar E-E-A-T e dar contexto factual, INCLUA 1-2 links pra fontes\n"
                      . "OFICIAIS de alta autoridade (não veículos de imprensa). Use quando o ângulo\n"
                      . "permite citação natural — NÃO force.\n\n"
                      . "FONTES OFICIAIS POR CONTEXTO:\n"
                      . "  ✓ Brasileirão / Copa do Brasil → cbf.com.br\n"
                      . "  ✓ Libertadores / Sul-Americana → conmebol.com\n"
                      . "  ✓ Champions League / Eurocopa → uefa.com\n"
                      . "  ✓ Premier League → premierleague.com\n"
                      . "  ✓ La Liga → laliga.com\n"
                      . "  ✓ Copa do Mundo → fifa.com\n"
                      . "  ✓ F1 → formula1.com\n"
                      . "  ✓ NBA → nba.com\n"
                      . "  ✓ UFC → ufc.com\n"
                      . "  ✓ Site oficial do clube → ex: vitoria.bahia.br, palmeiras.com.br, sccpfc.com.br\n\n"
                      . "FORMATO:\n"
                      . "  ✓ Link contextual no meio do parágrafo, com anchor descritivo:\n"
                      . "    '...na <a href=\"https://www.cbf.com.br/\">tabela oficial da CBF</a>...'\n"
                      . "  ✓ Em statements de fato verificável: 'segundo dados da [Fonte Oficial]'\n"
                      . "  ✗ NÃO linkar pra concorrentes (UOL, Globo, ESPN) — esses são imprensa, não autoridade\n"
                      . "  ✗ NÃO usar como referência de citação ('baseado em CBF') — usar como contexto.\n\n"
                      . "OBJETIVO: 1-2 links externos por artigo. Mais que isso vira link farm.\n"
                      . "═══ FIM BACKLINKS EXTERNOS ═══\n";

            $blocos[] = "\n═══ ESPORTES — CLUSTER (backlinks internos) ═══\n"
                      . "Sistema injeta interlinks automaticamente via DiscoverInternalLinks +\n"
                      . "DiscoverRelatedLinks após geração. Você NÃO precisa criar links HTML\n"
                      . "manualmente pra outros posts do site — isso vai sair na pós-processamento.\n\n"
                      . "Mas você PODE ajudar mencionando entidades canibalizáveis com naturalidade:\n"
                      . "  ✓ 'No clássico anterior, Palmeiras venceu Santos por 2x1'\n"
                      . "    → 'clássico anterior' vira anchor pra post sobre o jogo passado\n"
                      . "  ✓ 'Endrick, que protagonizou a vitória sobre o Atlético-MG'\n"
                      . "    → 'vitória sobre o Atlético-MG' pode virar link pro post correspondente\n"
                      . "  ✓ Mencione termos do mesmo cluster (Brasileirão, Libertadores, escalação\n"
                      . "    do Palmeiras, lesão do X) que provavelmente têm posts publicados.\n\n"
                      . "OBJETIVO: gerar pontos de ancoragem RICOS pra o auto-interlink achar matches.\n"
                      . "═══ FIM CLUSTER ═══\n";

            $blocos[] = "\n═══ ESPORTES — PROFUNDIDADE EXIGIDA ═══\n"
                      . "Quando as fontes cobrirem, INCLUA (cada um em H3 próprio):\n"
                      . "  • Forma recente: últimos 3-5 resultados de cada time (placar + adversário + local)\n"
                      . "  • Retrospecto direto: últimos 3-5 confrontos entre os 2 times\n"
                      . "  • Quem chega melhor: análise comparativa baseada em forma + desfalques + casa/fora\n"
                      . "  • Contexto da rodada: posição na tabela, importância (G6/Z4/Libertadores)\n"
                      . "Se a fonte NÃO traz esse dado, OMITA o bloco — não invente. Profundidade vem dos\n"
                      . "dados reais, não de prosa genérica. Artigo curto e factual > artigo longo e oco.\n"
                      . "═══ FIM PROFUNDIDADE ═══\n";

            $blocos[] = "\n═══ ESPORTES — DIFERENCIAL ANTI-GENÉRICO ═══\n"
                      . "Identifique nas fontes 1 DADO ESPECÍFICO que matérias genéricas sobre o jogo\n"
                      . "NÃO citariam. Pode ser:\n"
                      . "  - Estatística rara (ex: 'time X não vence o Y há 8 jogos como mandante')\n"
                      . "  - Curiosidade tática (ex: 'técnico mudou esquema 3 vezes no último mês')\n"
                      . "  - Histórico psicológico (ex: 'visitante perdeu últimos 3 clássicos por gols nos minutos finais')\n"
                      . "  - Detalhe contextual (ex: 'jogador X retorna após 6 meses — primeira vez em campo')\n"
                      . "INSIRA esse dado em destaque (caixa, negrito ou H3). É o gancho que diferencia.\n"
                      . "Se NÃO encontrar nada específico nas fontes, OMITA — não invente fato pra preencher.\n"
                      . "═══ FIM DIFERENCIAL ═══\n";

            $blocos[] = "\n═══ ESPORTES — TÍTULO PRO DISCOVER ═══\n"
                      . "Padrão alvo (ordem de prioridade):\n"
                      . "  1. [Time A] x [Time B]: onde assistir, escalação e [GANCHO ESPECÍFICO]\n"
                      . "  2. [Time A] x [Time B]: horário, escalações e quem chega mais forte\n"
                      . "  3. [Time A] x [Time B]: o detalhe nas escalações que pode decidir o jogo\n"
                      . "GANCHO ESPECÍFICO sai do bloco DIFERENCIAL acima — usa o dado raro como hook.\n"
                      . "Limite: 60-68 caracteres. Sem números inventados. Sem 'imperdível' / 'incrível'.\n"
                      . "Para tabelas/classificações (não-partida), use padrão diferente:\n"
                      . "  - Tabela do [Campeonato]: [INSIGHT atualizado]\n"
                      . "═══ FIM TÍTULO ESPORTES ═══\n";
        }

        // 3c) E-E-A-T — bloco "Humano-Especialista" universal (voz autoridade, pulo do gato, transparência)
        // Sinais que Google Helpful Content premia. Não conflita com persona (que é por nicho).
        $blocos[] = DiscoverPromptBuilder::blocoHumanoEspecialista();

        // 3d) CTA contextual de compartilhamento — direct traffic via WhatsApp/Telegram
        // é sinal forte pro Discover. CTA é texto editorial conectado à dor, não bot.
        $blocos[] = DiscoverPromptBuilder::blocoCTACompartilhamento();

        // 3d-bis) LINKS AFILIADO — proíbe Sonnet de inventar URL marketplace.
        // Sem API de afiliado pras 4 redes BR, único formato que atribui comissão é PrettyLink.
        $blocos[] = DiscoverPromptBuilder::blocoLinksAfiliado();

        // 3e) PRODUCT RANKER — se termo pede LISTA DE PRODUTOS ("top 10", "presentes até R$ X",
        //      "X mais vendidos"), busca top vendidos REAIS Amazon BR e injeta como contexto factual.
        //      Sonnet escreve em volta dos nomes/preços REAIS em vez de inventar produtos.
        //      CONSERVADOR: só dispara em termos explícitos + clusters shopping. Falso positivo é desastre.
        //      Pós-geração (bloco 5c): substitui placeholder DISCOVER_TABELA_PRODUTOS por tabela HTML rica.
        $rankerIntent = null;
        $rankerProdutos = null;
        try {
            $rankerIntent = DiscoverProductRanker::detectarIntent($termo, (string)($cluster['key'] ?? ''));
            if ($rankerIntent !== null) {
                $progress->reportar('product_ranker', "Buscando top {$rankerIntent['limite']} produtos Amazon ({$rankerIntent['categoria']})");
                $ranker = new DiscoverProductRanker();
                $rankerRet = $ranker->obter($rankerIntent);
                if (!empty($rankerRet['ok']) && !empty($rankerRet['produtos'])) {
                    $rankerProdutos = $rankerRet['produtos'];
                    $blocos[] = DiscoverProductRanker::paraPromptContext($rankerProdutos, $rankerRet['categoria']);
                }
            }
        } catch (Throwable $e) {
            // Falha no ranker NÃO bloqueia geração — segue sem tabela rica
        }

        // 4) Chama Maquina (pipeline existente). Se Claude falhar por erro transitório
        //    (timeout, 429 rate limit, 5xx, network), tenta GPT automaticamente.
        // SPORTS FACT EXTRACTOR — extrai fatos literais das fontes (canais TV, estádio,
        // horário, escalação, arbitragem, ingresso, pendurados, desfalques, pontos tabela,
        // placares) ANTES de chamar o LLM. Injetado no prompt como bloco inviolável.
        // Caso real #742/#1716 leaodabarra: Claude inventou TV Aratu pra Brasileirão (sabe
        // por treinamento que Aratu transmite Baianão). Com fatos extraídos, regra dura
        // bloqueia inferência e força uso literal só do que está nas fontes.
        try {
            require_once __DIR__ . '/SportsFactExtractor.php';
            if (($cluster['key'] ?? '') === 'esportes' && !empty($fontesOk)) {
                $fatosEsportivos = SportsFactExtractor::extrair($fontesOk);
                $blocoFatos = SportsFactExtractor::paraPrompt($fatosEsportivos);
                if ($blocoFatos !== '') {
                    $blocos[] = $blocoFatos;
                    $progress->reportar('fatos_extraidos',
                        "SportsFactExtractor: " .
                        count($fatosEsportivos['canais_tv']) . " canais, " .
                        count($fatosEsportivos['estadios']) . " estádios, " .
                        count($fatosEsportivos['arbitros']) . " árbitros, " .
                        count($fatosEsportivos['escalacoes_blocos']) . " escalações"
                    );
                }
            }
        } catch (Throwable $e) { /* extractor não bloqueia, segue sem ele */ }

        $progress->reportar('chamando_llm', 'Claude ' . ($this->cfg['anthropic_model'] ?? '?') . ' (60-120s)');
        $maq = new Maquina($this->serper, $this->scraper, $this->claude, $this->wp, $this->cfg);

        $llmFallbackUsado = null;
        $erroClaudeOriginal = null;
        try {
            $res = $maq->rodar($termo, [$formato], $blocos, $urlsFinais);
            $primeiro = $res['resultados'][0] ?? null;
        } catch (Throwable $e) {
            $primeiro = ['ok' => false, 'erro' => $e->getMessage()];
        }

        // Checa se precisa de fallback para GPT
        $erroMsg = $primeiro['erro'] ?? '';
        if ((!$primeiro || empty($primeiro['ok'])) && self::deveTentarFallback($erroMsg)) {
            $erroClaudeOriginal = $erroMsg;
            $progress->reportar('fallback_gpt', 'Claude falhou, tentando GPT...');
            try {
                require_once __DIR__ . '/OpenAI.php';
                require_once __DIR__ . '/DiscoverGeradorGPT.php';
                $modelo = $this->cfg['openai_model'] ?? 'gpt-4o-mini';
                $gpt = new DiscoverGeradorGPT($this->cfg, $this->db, $modelo);
                $respGpt = $gpt->gerar($trend, $formato);
                // Marca que usou fallback e anexa razão do Claude ter falhado
                if (!empty($respGpt['ok'])) {
                    $respGpt['llm_fallback'] = 'gpt';
                    $respGpt['claude_erro_original'] = $erroClaudeOriginal;
                    return $respGpt;
                }
                // GPT também falhou — combina os 2 erros
                $erroCombinado = 'Ambos LLMs falharam. Claude: ' . $erroClaudeOriginal . ' | GPT: ' . ($respGpt['erro'] ?? '?');
                // Se ambos circuits abertos / transitórios → marca AGUARDANDO em vez de FAIL
                // (fila pega de novo quando recovery acontece, não desperdiça o trend).
                if (self::ambosLlmsIndisponiveis($erroCombinado)) {
                    $this->db->updateStatus($trendId, 'aguardando_llm', [
                        'aguardando_motivo' => $erroCombinado,
                        'aguardando_desde'  => date('Y-m-d H:i:s'),
                    ]);
                    $progress->erro('Ambos LLMs indisponíveis (circuit open) — trend marcado aguardando_llm pra retry');
                    // Alerta crítico: pipeline parou. Webhook tem throttle, então floods são contidos.
                    $hwPath = __DIR__ . '/HealthWebhook.php';
                    if (is_file($hwPath)) {
                        require_once $hwPath;
                        HealthWebhook::erro('Pipeline LLM parado: Anthropic E OpenAI indisponíveis', [
                            'trend_id'    => $trendId,
                            'site'        => (string)($trend['site'] ?? '?'),
                            'erro_resumo' => mb_substr($erroCombinado, 0, 300),
                        ]);
                    }
                }
                return [
                    'ok' => false,
                    'erro' => $erroCombinado,
                    'claude_erro_original' => $erroClaudeOriginal,
                    'gpt_erro' => $respGpt['erro'] ?? null,
                    'fontes_usadas' => count($urlsFinais),
                    'aguardando_llm' => self::ambosLlmsIndisponiveis($erroCombinado),
                ];
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'erro' => 'Claude falhou (' . $erroClaudeOriginal . ') e fallback GPT exception: ' . $e->getMessage(),
                    'claude_erro_original' => $erroClaudeOriginal,
                    'fontes_usadas' => count($urlsFinais),
                ];
            }
        }

        if (!$primeiro || empty($primeiro['ok'])) {
            $progress->erro($erroMsg ?: 'Erro Maquina');
            return [
                'ok' => false,
                'erro' => $erroMsg ?: 'Erro desconhecido no Maquina::rodar',
                'fontes_usadas' => count($urlsFinais),
            ];
        }
        $progress->reportar('publicando', 'Post criado no WordPress');

        $postId = (int)($primeiro['post_id'] ?? 0);
        $titulo = (string)($primeiro['titulo'] ?? '');

        // ─── CATEGORIA — fim do "sem-categoria" no fluxo tick_filas ───
        // Maquina/Claude criou o post sem categoria atribuída. CategoryMatcher
        // resolve via fuzzy match (existente em 5 níveis) ou cria nova. Cluster
        // editorial detectado vira nome de categoria base.
        if ($postId > 0) {
            try {
                require_once __DIR__ . '/CategoryMatcher.php';
                $cm = new CategoryMatcher($this->wp, 70.0);
                $catNomes = self::clusterParaCategorias($cluster, $trend);
                if (!empty($catNomes)) {
                    $catIds = $cm->resolverComMatch($catNomes);
                    if (!empty($catIds)) {
                        $this->wp->atualizarPost($postId, ['categories' => $catIds]);
                        $progress->reportar('categoria_set', 'Categoria atribuída: ' . implode(', ', $catNomes));
                    }
                }
            } catch (Throwable $e) { /* categoria é importante mas não bloqueia post */ }
        }
        $editUrl = (string)($primeiro['edit_url'] ?? '');

        // ═══ PARIDADE COM REVIEWER: valida título + retry + normaliza ═══
        $tituloInfo = ['refeito' => false, 'score' => null, 'falhas' => []];
        if ($postId > 0 && $titulo !== '') {
            try {
                // Extrai gancho das fontes pra alimentar o validator + retry
                $fontesArr = [];
                foreach ($fontesOk as $f) $fontesArr[] = $f['fonte'] ?? [];
                $gancho = DiscoverGanchoExtrator::extrair($fontesArr);
                $ganchoPalavras = $gancho['palavras'] ?? [];
                $ganchoFrase    = $gancho['frase']   ?? '';

                $vr = DiscoverTituloRefazer::validarERefazer(
                    $this->claude, $titulo, $termo, $titulo, $ganchoPalavras, $ganchoFrase
                );
                if ($vr['titulo'] !== $titulo) {
                    // Atualiza WP com novo título
                    try {
                        $this->wp->atualizarPost($postId, ['title' => $vr['titulo']]);
                        $titulo = $vr['titulo'];
                    } catch (Throwable $e) { /* mantém título original */ }
                }
                $tituloInfo = ['refeito' => $vr['refeito'], 'score' => $vr['score'], 'falhas' => $vr['falhas']];
            } catch (Throwable $e) { /* falha no retry não bloqueia */ }
        }

        // 5) PÓS-PROCESSAMENTO + AUDITORIA
        $auditoria = null;
        $validationReport = ['anti_ai' => null, 'fidelity' => null];
        if ($postId > 0) {
            try {
                $post = $this->wp->getPost($postId);
                $content = $post['content']['raw'] ?? $post['content']['rendered'] ?? '';

                // ─── VALIDADORES PÓS-GERAÇÃO (paridade com DiscoverGeradorGPT) ───
                // Mesmos validators do path GPT, agora também no path Claude (default p/ leaodabarra
                // após threshold per-site = 5.5). Detectam frases banidas, truncamentos, hype não
                // factual (AntiAI) e nomes/URLs sem lastro na fonte (SourceFidelity).
                if ($content !== '') {
                    if (!class_exists('AntiAIValidator')) {
                        $aiPath = __DIR__ . '/AntiAIValidator.php';
                        if (file_exists($aiPath)) require_once $aiPath;
                    }
                    if (class_exists('AntiAIValidator')) {
                        try {
                            $aiVal = new AntiAIValidator();
                            $aiReport = $aiVal->validate($content);
                            $validationReport['anti_ai'] = $aiReport;
                            $progress->reportar('validando_anti_ai', $aiVal->reportToLogLine($aiReport));

                            // AUTO-REVISÃO Haiku 4.5 se severity != ok (custo extra ~$0.02)
                            if (($aiReport['severity'] ?? 'ok') !== 'ok') {
                                if (!class_exists('AutoRevisor')) {
                                    $arPath = __DIR__ . '/AutoRevisor.php';
                                    if (file_exists($arPath)) require_once $arPath;
                                }
                                if (class_exists('AutoRevisor')) {
                                    $persona = (array)($this->cfg['persona'] ?? []);
                                    $rev = (new AutoRevisor((string)($this->cfg['anthropic_api_key'] ?? '')))->revisar($content, [
                                        'site_name'      => (string)($this->cfg['site_name'] ?? ''),
                                        'persona_autor'  => (string)($persona['autor'] ?? "Equipe " . ($this->cfg['site_name'] ?? '')),
                                        'persona_voz'    => (string)($persona['voz'] ?? 'jornalística direta'),
                                        'persona_tom'    => (string)($persona['tom'] ?? 'direto e factual'),
                                        'subtipo_nicho'  => (string)($this->cfg['subtipo_nicho'] ?? ''),
                                    ]);
                                    if (!empty($rev['reescreveu']) && !empty($rev['html'])) {
                                        $content = (string)$rev['html'];
                                        $validationReport['anti_ai_revisado'] = [
                                            'severity_antes'  => $rev['antes']['severity'] ?? '?',
                                            'severity_depois' => $rev['depois']['severity'] ?? '?',
                                            'ok'              => $rev['ok'] ?? false,
                                        ];
                                        $progress->reportar('auto_revisao_haiku', sprintf(
                                            'severity %s → %s',
                                            $rev['antes']['severity'] ?? '?',
                                            $rev['depois']['severity'] ?? '?'
                                        ));
                                    }
                                }
                            }
                        } catch (Throwable $e) { /* não bloqueia */ }
                    }
                    if (!class_exists('SourceFidelityValidator')) {
                        $sfPath = __DIR__ . '/SourceFidelityValidator.php';
                        if (file_exists($sfPath)) require_once $sfPath;
                    }
                    if (class_exists('SourceFidelityValidator')) {
                        try {
                            // Extrai texto bruto das fontes scrapeadas (paragraphs por fonte)
                            $textosFontes = [];
                            foreach ($fontesOk as $f) {
                                $paragraphs = $f['fonte']['content']['paragraphs'] ?? [];
                                if (!empty($paragraphs)) $textosFontes[] = implode("\n", $paragraphs);
                                // Inclui meta title + description também (fonte pode trazer dados ali)
                                $meta = $f['fonte']['meta'] ?? [];
                                if (!empty($meta['title'])) $textosFontes[] = (string)$meta['title'];
                                if (!empty($meta['description'])) $textosFontes[] = (string)$meta['description'];
                            }
                            $fidReport = SourceFidelityValidator::validar($content, $textosFontes, [
                                'own_domain' => (string)($this->cfg['wp_url'] ?? ''),
                            ]);
                            $validationReport['fidelity'] = $fidReport;
                            $progress->reportar('validando_fidelidade', SourceFidelityValidator::reportToLogLine($fidReport));
                            // Grava report quando severity=fail (não bloqueia publicação aqui — orquestrador decide)
                            if (($fidReport['severity'] ?? '') === 'fail') {
                                $dbgPath = __DIR__ . '/../data/debug/fidelity_fail_' . date('Ymd_His') . '_' . $trendId . '.json';
                                @mkdir(dirname($dbgPath), 0777, true);
                                @file_put_contents($dbgPath, json_encode([
                                    'trend_id' => $trendId,
                                    'post_id'  => $postId,
                                    'termo'    => $termo,
                                    'modelo'   => 'claude',
                                    'report'   => $fidReport,
                                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                            }
                        } catch (Throwable $e) { /* não bloqueia */ }
                    }
                }

                $progress->reportar('pos_processing', 'Cards, schemas, auto-links, cluster');
                // 5a) Pós-processamento: auto-links de tel/WhatsApp + FAQPage/HowTo schemas + limpeza de Article + CTA
                if ($content !== '') {
                    // Resolve URL pública (slug-based se disponível — funciona mesmo em draft)
                    $baseUrl = rtrim((string)($this->cfg['wp_url'] ?? ''), '/');
                    $slug    = (string)($post['slug'] ?? '');
                    $urlPublica = ($slug !== '' && $baseUrl !== '')
                        ? $baseUrl . '/' . $slug . '/'
                        : (string)($post['link'] ?? $editUrl);

                    // Trend completo (com cluster_detect/origem/pain) + cfg (com persona) habilita schemas rich G1
                    $trendCompleto = $this->db->get($trendId) ?: $trend;
                    $cfgComImagem = $cfgTrend;
                    if (!empty($post['featured_media'])) {
                        try {
                            $media = $this->wp->getMedia((int)$post['featured_media']);
                            $cfgComImagem['_image_url'] = $media['source_url'] ?? '';
                        } catch (Throwable $e) {}
                    }
                    $metaPos = ['titulo' => $titulo, 'url' => $urlPublica, 'post_id' => $postId];
                    // Se ProductRanker rodou e tem produtos, passa pra ItemList schema
                    if (!empty($rankerProdutos)) $metaPos['ranker_produtos'] = $rankerProdutos;
                    // Sports event (cluster=esportes) — extrai do response do LLM se válido
                    if (($cluster['key'] ?? '') === 'esportes') {
                        $sportsEvent = DiscoverSportsEvent::extrairDoPrimeiro($primeiro);
                        if ($sportsEvent !== null) $metaPos['sports_event'] = $sportsEvent;
                    }
                    // Fontes scrapeadas pra QuoteEnrichment extrair citação oficial
                    if (!empty($fontesOk)) $metaPos['fontes'] = $fontesOk;
                    // PAA cacheado (CtrIntel) → FaqEnricher injeta seção FAQ se Sonnet não fez
                    if (isset($intel) && is_array($intel) && !empty($intel['paa'])) {
                        $metaPos['paa'] = $intel['paa'];
                    }
                    $contentPos = DiscoverPostProcess::processar($content, $metaPos, $trendCompleto, $cfgComImagem);
                    // FIX 11: validação inteligente em vez de threshold rígido 90%.
                    // dedupeFaq/dedupeLeiaTambem REMOVEM conteúdo legitimamente — antes o threshold
                    // descartava TODA a melhoria, deixando FAQs duplicadas no post final.
                    // 2026-05-02: threshold 50% era ainda agressivo demais (post #716 leaodabarra
                    // teve PostProcess descartado e ficou com 12 travessões). Relaxado pra 35%
                    // + log de descarte pra debug.
                    if ($contentPos !== $content) {
                        $tamOriginal = strlen($content);
                        $tamPos      = strlen($contentPos);
                        $tamOk       = $tamPos >= $tamOriginal * 0.35;
                        $estruOk     = preg_match('/<h2[^>]*>/i', $contentPos)
                                     && preg_match_all('/<p[^>]*>/i', $contentPos) >= 3;
                        if ($tamOk && $estruOk) {
                            try {
                                $this->wp->atualizarPost($postId, ['content' => $contentPos]);
                                $content = $contentPos;
                                $progress->reportar('postprocess_aplicado', "PostProcess OK ({$tamOriginal} → {$tamPos} bytes)");
                            } catch (Throwable $e) { /* mantém original em caso de erro */ }
                        } else {
                            // DESCARTE — grava log pra investigação. Antes era silencioso.
                            $motivo = !$tamOk
                                ? "tamanho caiu " . round((1 - $tamPos / max(1, $tamOriginal)) * 100, 1) . "% (threshold 35%)"
                                : "estrutura inválida (sem H2 ou <3 <p>)";
                            $progress->reportar('postprocess_descartado', "PostProcess descartado: {$motivo}");
                            $dbgPath = __DIR__ . '/../data/debug/postprocess_discard_' . date('Ymd_His') . '_' . $trendId . '.txt';
                            @mkdir(dirname($dbgPath), 0777, true);
                            @file_put_contents($dbgPath, "MOTIVO: {$motivo}\n\n=== ANTES ({$tamOriginal} bytes) ===\n{$content}\n\n=== DEPOIS ({$tamPos} bytes) ===\n{$contentPos}\n");
                        }
                    }

                    // 5a-bis) Imagem SEO — garante alt_text, legenda e descrição na featured image
                    // Se houver image_url + openai_api_key, tenta Vision pra alt rico contextual
                    // (cai pra fallback baseado em título se Vision falhar — fail-safe).
                    try {
                        $mediaId = (int)($post['featured_media'] ?? 0);
                        if ($mediaId > 0) {
                            $ganchoFrase = $gancho['diferencial']['frase'] ?? ($gancho['frase'] ?? '');
                            $metaExistente = (array)($primeiro['imagem'] ?? []);
                            $metaExistente['alt_text'] = $metaExistente['alt_text'] ?? ($primeiro['hero_alt'] ?? '');
                            $imgUrl = (string)($cfgComImagem['_image_url'] ?? $primeiro['imagem']['url'] ?? '');
                            $imgSEO = DiscoverImagemSEO::gerar(
                                $titulo, $termo, (string)$ganchoFrase, $metaExistente,
                                $imgUrl, $cfgTrend
                            );
                            $this->wp->atualizarMedia($mediaId, [
                                'alt_text'    => $imgSEO['alt_text'],
                                'caption'     => $imgSEO['legenda'],
                                'description' => $imgSEO['descricao'],
                            ]);
                        }
                    } catch (Throwable $e) { /* não bloqueia */ }

                    // 5a-ter) Valida links — remove <a> alucinado (URL pro próprio site sem post)
                    try {
                        $valR = DiscoverLinkValidator::validar($content, (string)($this->cfg['wp_url'] ?? ''), $this->wp);
                        if (!empty($valR['removidos'])) {
                            $this->wp->atualizarPost($postId, ['content' => $valR['html']]);
                            $content = $valR['html'];
                        }
                        $linksRemovidosAlucinados = count($valR['removidos'] ?? []);
                    } catch (Throwable $e) { /* não bloqueia */ }

                    // 5b) Interlinks internos STANDALONE — funcionam mesmo sem cluster formado
                    //     (cluster só tem siblings quando há 2+ posts do mesmo evento;
                    //     sem isso, o artigo saía sem NENHUM link interno contextual)
                    try {
                        require_once __DIR__ . '/DiscoverInternalLinks.php';
                        $clusterDet2 = DiscoverClusterMatcher::detectar([
                            'termo' => $termo,
                            'categoria_ids' => $trend['categoria_ids'] ?? [],
                        ]);
                        $termosLink = DiscoverInternalLinks::extrairTermos($content, [
                            'termo'       => $termo,
                            'cluster_key' => $clusterDet2['key'] ?? null,
                            'relacionados'=> $trend['relacionados'] ?? [],
                        ]);
                        if (!empty($termosLink)) {
                            $linker = new DiscoverInternalLinks($this->wp, 5); // 5 max backlinks internos
                            $linker->setKeywordAncora($termo);
                            // Combina termos do cluster + n-gramas do próprio texto (ambos "seguros")
                            $termosSeguros = !empty($clusterDet2['key'])
                                ? DiscoverClusterMatcher::termosSemanticos($clusterDet2['key'])
                                : [];
                            $termosSeguros = array_merge($termosSeguros, DiscoverInternalLinks::extrairNgramasSignificativos($content));
                            $linker->setTermosSemanticos($termosSeguros);
                            $r = $linker->injetar($content, $termosLink, [], $postId);
                            if ($r['aplicados'] > 0) {
                                $this->wp->atualizarPost($postId, ['content' => $r['html']]);
                                $content = $r['html'];
                            }
                            $internalLinksAplicados = $r['aplicados'] ?? 0;
                        }
                    } catch (Throwable $e) { /* não bloqueia */ }
                }

                if ($content !== '') {
                    $auditoria = DiscoverAuditor::auditar($content, $textosFontes);
                    // Se falhou: injeta aviso no topo do post + muda status pra "suspeita"
                    if (!$auditoria['ok']) {
                        $aviso = "<div style='background:#fef2f2;border-left:4px solid #dc2626;padding:12px 16px;margin:0 0 20px;font-family:sans-serif'>"
                               . "<strong style='color:#991b1b'>⚠️ REVISÃO MANUAL OBRIGATÓRIA — possível alucinação detectada</strong><br>"
                               . "<span style='font-size:13px;color:#7f1d1d'>Os nomes abaixo aparecem no artigo mas NÃO foram encontrados nas fontes scrapeadas. "
                               . "Confirme cada um antes de publicar:</span><ul style='margin:8px 0 0;color:#7f1d1d;font-size:13px'>";
                        foreach ($auditoria['nomes_suspeitos'] as $n) {
                            $aviso .= "<li><strong>" . htmlspecialchars($n['nome']) . "</strong>"
                                   . " — <em>&ldquo;…" . htmlspecialchars($n['contexto']) . "…&rdquo;</em></li>";
                        }
                        $aviso .= "</ul></div>\n";
                        $this->wp->atualizarPost($postId, [
                            'content' => $aviso . $content,
                            'status'  => 'draft', // já é draft mas reforça
                        ]);
                    }
                }
            } catch (Throwable $e) {
                // falha na auditoria não impede retorno, só não marca flag
                $auditoria = ['erro' => $e->getMessage()];
            }
        }

        // 5c) PRODUCT RANKER — substitui placeholder DISCOVER_TABELA_PRODUTOS por tabela HTML rica.
        //      Roda DEPOIS da auditoria (auditoria flag nomes inventados nas fontes; produtos REAIS
        //      Amazon não são "alucinação"). Quando ranker injetar com sucesso, 5f (CTA single)
        //      é DESLIGADO — uma tabela rica > duas CTAs competindo no mesmo artigo.
        $rankerInjetado = false;
        $rankerInfo = null;
        if (!empty($rankerProdutos) && $postId > 0 && $content !== '') {
            try {
                // PrettyLinks individuais por produto: /go/produto-{ASIN} → target Amazon
                // Quando user cadastrar Associates BR, edita Pretty Links no WP (sem reescrever posts)
                require_once __DIR__ . '/PrettyLinks.php';
                $prettyLinks = new PrettyLinks(
                    (string)$cfgTrend['wp_url'],
                    (string)$cfgTrend['wp_user'],
                    (string)$cfgTrend['wp_app_password']
                );
                $tabelaHtml = DiscoverProductRanker::paraTabelaHtml(
                    $rankerProdutos,
                    (string)($rankerIntent['categoria'] ?? ''),
                    $prettyLinks,
                    (string)($cfgTrend['amazon_associates_tag'] ?? '')
                );
                $sub = DiscoverProductRanker::substituirPlaceholder($content, $tabelaHtml);
                if ($sub['metodo'] !== 'nao_injetou' && $sub['html'] !== $content) {
                    try {
                        $this->wp->atualizarPost($postId, ['content' => $sub['html']]);
                        $content = $sub['html'];
                        $rankerInjetado = true;
                        $rankerInfo = [
                            'categoria' => (string)($rankerIntent['categoria'] ?? ''),
                            'count'     => count($rankerProdutos),
                            'metodo'    => $sub['metodo'],
                        ];
                    } catch (Throwable $e) {
                        $rankerInfo = ['erro' => $e->getMessage(), 'injetado' => false];
                    }
                }
            } catch (Throwable $e) {
                $rankerInfo = ['erro' => $e->getMessage(), 'injetado' => false];
            }
        }

        // 5f) AFILIADO CTA — matchea trend contra catálogo e injeta bloco após H2 principal.
        //      Silencioso quando não há match ou nenhuma oferta ativa bate o threshold.
        //      DESLIGADO quando ProductRanker (5c) já injetou tabela rica — evita 2 CTAs competindo.
        $afiliadoInfo = null;
        if ($postId > 0 && !empty($content) && !$rankerInjetado) {
            try {
                require_once __DIR__ . '/DiscoverAfiliados.php';
                $trendParaMatch = [
                    'termo'          => $termo,
                    'cluster_detect' => $cluster,
                    'pain'           => $painRet ?? DiscoverPainClassifier::classificar($termo),
                    'relacionados'   => $trend['relacionados'] ?? [],
                    'site'           => (string)($trend['site'] ?? $this->cfg['_site_slug'] ?? ''),
                ];
                $match = DiscoverAfiliados::matchear($trendParaMatch);
                if ($match !== null) {
                    // Passa $cfgTrend explícito pra renderizar Pretty Link com wp_url do site do trend
                    $blocoHtml = $this->renderizarBlocoAfiliado($match['oferta'], $trendId, $postId, $cfgTrend);
                    $contentComCta = self::injetarAposPrimeiroH2($content, $blocoHtml);
                    if ($contentComCta !== $content) {
                        try {
                            $this->wp->atualizarPost($postId, ['content' => $contentComCta]);
                            $content = $contentComCta;
                            $afiliadoInfo = [
                                'slug'    => $match['oferta']['slug'],
                                'nome'    => $match['oferta']['nome'],
                                'score'   => $match['score'],
                                'motivos' => $match['motivos'],
                                'injetado'=> true,
                            ];
                        } catch (Throwable $e) {
                            $afiliadoInfo = ['erro' => $e->getMessage(), 'injetado' => false];
                        }
                    }
                }
            } catch (Throwable $e) {
                $afiliadoInfo = ['erro' => $e->getMessage(), 'injetado' => false];
            }
        }

        // 5g) WEB STORY — se cluster tem ROI >= threshold, aciona plugin wp-web-stories-ai.
        //      FIX multi-site: usa $cfgTrend para que o plugin do site CORRETO seja chamado.
        //      (cada site tem seu próprio plugin wsai — credenciais WP do site do trend)
        $webStoryInfo = null;
        if ($postId > 0) {
            try {
                require_once __DIR__ . '/DiscoverWebStory.php';
                $clusterKeyWs = $cluster['key'] ?? 'curiosidades_geral';
                if (DiscoverWebStory::deveGerar($cfgTrend, $clusterKeyWs)) {
                    // Extrai resposta-direta (bloco factual GEO) pra enriquecer o prompt das cenas
                    $respostaDireta = '';
                    if ($content !== '' && preg_match('#<p\s+class=[\'"]resposta-direta[\'"][^>]*>(.*?)</p>#is', $content, $mrd)) {
                        $respostaDireta = trim(strip_tags($mrd[1]));
                    }
                    $ws = new DiscoverWebStory($cfgTrend);
                    $webStoryInfo = $ws->gerar($postId, [
                        'keyword'          => $termo,
                        'meta_description' => (string)($primeiro['meta_description'] ?? ''),
                        'resposta_direta'  => $respostaDireta,
                        'imagem_prompt'    => (string)($primeiro['imagem']['imagem_prompt'] ?? ''),
                        'dna'              => (array)($briefing ?? []),
                    ]);
                } else {
                    $webStoryInfo = ['ok' => false, 'pulado' => true, 'motivo' => 'ROI do cluster < threshold ou desabilitado'];
                }
            } catch (Throwable $e) {
                $webStoryInfo = ['ok' => false, 'erro' => $e->getMessage()];
            }
        }

        // 5h) ONESIGNAL PUSH — se cluster tem ROI ≥ limite E site bate target, dispara push.
        //      Fallback silencioso: notificação é bônus, nunca quebra pipeline.
        //
        //      FIX CRÍTICO multi-site: usa $cfgTrend (não $this->cfg) para que credenciais
        //      OneSignal e wp_url batam com o site DO TREND, não o site ativo do portal.
        $onesignalInfo = null;
        if ($postId > 0 && !empty($titulo)) {
            try {
                require_once __DIR__ . '/DiscoverOneSignal.php';
                $clusterKeyOs = $cluster['key'] ?? 'curiosidades_geral';
                $siteAtual = (string)($trend['site'] ?? $cfgTrend['_site_slug'] ?? '');
                if (DiscoverOneSignal::deveEnviar($cfgTrend, $clusterKeyOs, $siteAtual)) {
                    $os = new DiscoverOneSignal($cfgTrend);
                    // URL pública do artigo — usa wp_url do site do TREND
                    $baseUrl = rtrim((string)($cfgTrend['wp_url'] ?? ''), '/');
                    $slugPost = '';
                    try { $pt = $this->wp->getPost($postId); $slugPost = (string)($pt['slug'] ?? ''); } catch (Throwable $e) {}
                    $urlPublica = ($slugPost !== '' && $baseUrl !== '') ? $baseUrl . '/' . $slugPost . '/' : ($primeiro['url'] ?? $editUrl);
                    $imgPush = (string)($primeiro['imagem']['url'] ?? '');
                    $onesignalInfo = $os->enviar($titulo, $urlPublica, [
                        'descricao' => (string)($primeiro['meta_description'] ?? ''),
                        'imagem_url'=> $imgPush,
                    ]);
                } else {
                    $onesignalInfo = ['ok' => false, 'pulado' => true, 'motivo' => 'ROI cluster < limite, site ≠ target ou desabilitado'];
                }
            } catch (Throwable $e) {
                $onesignalInfo = ['ok' => false, 'erro' => $e->getMessage()];
            }
        }

        // 5i) INSTANT INDEXING — pinga Google pra indexar URL imediatamente em vez de esperar crawl.
        //      Plugin custom no WP: POST /wp-json/cc/v1/indexar (Rank Math + IndexNow fallback).
        //      Indexa: (a) URL pública do artigo, (b) Web Story se criada.
        //      Usa cfgTrend pra credenciais WP do site CORRETO em multi-site.
        $indexingInfo = ['post_url' => null, 'web_story_url' => null];
        $urlPost = ''; // mantém em scope pra reuso no Meta abaixo
        if ($postId > 0) {
            try {
                require_once __DIR__ . '/InstantIndexing.php';
                $idx = new InstantIndexing(
                    (string)$cfgTrend['wp_url'],
                    (string)$cfgTrend['wp_user'],
                    (string)$cfgTrend['wp_app_password']
                );
                // (a) URL pública do post — calcula slug-based (funciona mesmo em draft)
                $base = rtrim((string)($cfgTrend['wp_url'] ?? ''), '/');
                $slugPost = '';
                try { $pt = $this->wp->getPost($postId); $slugPost = (string)($pt['slug'] ?? ''); } catch (Throwable $e) {}
                if ($slugPost !== '' && $base !== '') {
                    $urlPost = $base . '/' . $slugPost . '/';
                    $indexingInfo['post_url'] = $idx->indexar($urlPost, 'URL_UPDATED');
                }
                // (b) Web Story (se criada com sucesso)
                if (!empty($webStoryInfo['ok']) && !empty($webStoryInfo['view_url'])) {
                    $indexingInfo['web_story_url'] = $idx->indexar((string)$webStoryInfo['view_url'], 'URL_UPDATED');
                }
            } catch (Throwable $e) {
                $indexingInfo['erro'] = $e->getMessage();
            }
        }

        // 5i.5) SOCIAL POSTER — distribui em canais grátis (Bluesky, Threads, X) baseado em cfg.social
        //       Falha-silenciosa por canal — não bloqueia pipeline.
        $socialInfo = null;
        if ($postId > 0 && $urlPost !== '' && !empty($cfgTrend['social']) && is_array($cfgTrend['social'])) {
            try {
                require_once __DIR__ . '/SocialPoster.php';
                // Busca imagem featured pra Threads/Bluesky embed
                $imgSocial = '';
                try {
                    $ptS = $this->wp->getPost($postId);
                    if (!empty($ptS['featured_media'])) {
                        $mediaS = $this->wp->getMedia((int)$ptS['featured_media']);
                        $imgSocial = (string)($mediaS['source_url'] ?? '');
                    }
                } catch (Throwable $e) { /* imagem é opcional */ }

                $socialInfo = SocialPoster::publicar([
                    'titulo'      => $titulo,
                    'url'         => $urlPost,
                    'imagem_url'  => $imgSocial,
                    'site_slug'   => (string)($trend['site'] ?? $cfgTrend['_site_slug'] ?? ''),
                    'cluster_key' => (string)($cluster['key'] ?? ''),
                    'post_id'     => $postId,
                ], $cfgTrend);
            } catch (Throwable $e) {
                $socialInfo = ['ok' => false, 'erro' => $e->getMessage()];
            }
        }

        // 5j) AUTO-PUBLICAR FB/IG — distribui o artigo nas redes pós-publicação.
        //      Tráfego direto via social = sinal forte pro Discover (descoberto em g36-g42).
        //      Só dispara se: (1) site tem fb_page_id+token configurado em sites.php,
        //      (2) post foi publicado com sucesso (postId > 0 + urlPost calculada).
        //      Falha silenciosa — não bloqueia pipeline. Persiste em meta_info.
        $metaInfo = null;
        if ($postId > 0 && $urlPost !== '' && !empty($cfgTrend['fb_page_id']) && !empty($cfgTrend['fb_page_token'])) {
            try {
                require_once __DIR__ . '/Meta.php';
                $meta = new Meta(
                    (string)$cfgTrend['fb_page_id'],
                    (string)$cfgTrend['fb_page_token'],
                    (string)($cfgTrend['ig_user_id'] ?? ''),
                    (string)($cfgTrend['ig_access_token'] ?? '')
                );

                // Busca featured image e meta_description pro caption
                $imgUrl = '';
                $metaDesc = '';
                try {
                    $pt = $this->wp->getPost($postId);
                    // featured_media id → buscar URL via WP API
                    if (!empty($pt['featured_media'])) {
                        try {
                            $media = $this->wp->getMedia((int)$pt['featured_media']);
                            $imgUrl = (string)($media['source_url'] ?? '');
                        } catch (Throwable $e) { /* sem imagem é OK pro FB (pega og:image), mas IG precisa */ }
                    }
                    $metaDesc = (string)($pt['excerpt']['rendered'] ?? '');
                    $metaDesc = trim(strip_tags($metaDesc));
                } catch (Throwable $e) { /* skip */ }

                // Caption: título + 1-2 linhas da meta_description (se houver) — humano, não markup
                $caption = $titulo;
                if ($metaDesc !== '' && mb_strlen($metaDesc) > 30) {
                    $caption .= "\n\n" . $metaDesc;
                }

                $fbResult = ['skipped' => true];
                $igResult = ['skipped' => true];

                // Facebook: postar link (FB pega og:image automático). Não precisa de imageUrl.
                if ($meta->fbConfigurado()) {
                    $fbResult = $meta->postarFacebookPage($urlPost, $caption);
                }
                // Instagram: precisa de imageUrl válido (HTTPS público, JPG/PNG, ≤8MB).
                // IG só aceita aspect 1:1 a 4:5; featured image é 16:9 (1200×675) → falhava silenciosa.
                // Fluxo: gera variante 4:5 (1080×1350) → upload WP Media → URL HTTPS pública → IG.
                $imgUrlIg = $imgUrl;
                $igVarianteInfo = null;
                if ($meta->igConfigurado() && $imgUrl !== '' && preg_match('#^https://#', $imgUrl)) {
                    try {
                        require_once __DIR__ . '/DiscoverImagemViral.php';
                        $bytes45 = DiscoverImagemViral::variante1080x1350($imgUrl);
                        if ($bytes45 !== null && strlen($bytes45) > 5000) {
                            $tmp = tempnam(sys_get_temp_dir(), 'ig_4x5_') . '.jpg';
                            if (@file_put_contents($tmp, $bytes45) !== false) {
                                $up = $this->wp->uploadImagemLocalJpg($tmp, 'Imagem Instagram 4:5: ' . $titulo);
                                @unlink($tmp);
                                if ($up && !empty($up['source_url']) && preg_match('#^https://#', $up['source_url'])) {
                                    $imgUrlIg = $up['source_url'];
                                    $igVarianteInfo = ['variante_id' => $up['id'], 'variante_url' => $up['source_url']];
                                } else {
                                    $igVarianteInfo = ['erro' => 'upload_falhou', 'fallback' => '16:9'];
                                }
                            } else {
                                $igVarianteInfo = ['erro' => 'tmp_write_falhou', 'fallback' => '16:9'];
                            }
                        } else {
                            $igVarianteInfo = ['erro' => 'gd_falhou', 'fallback' => '16:9'];
                        }
                    } catch (Throwable $e) {
                        $igVarianteInfo = ['erro' => $e->getMessage(), 'fallback' => '16:9'];
                    }

                    $captionIg = trim($caption . "\n\n🔗 Link completo no perfil");
                    $igResult = $meta->postarInstagramFeed($imgUrlIg, $captionIg);
                } elseif ($meta->igConfigurado() && $imgUrl === '') {
                    $igResult = ['success' => false, 'pulado' => true, 'motivo' => 'sem featured image'];
                }

                $metaInfo = [
                    'fb' => $fbResult,
                    'ig' => $igResult,
                    'ig_variante_4x5' => $igVarianteInfo,
                    'caption_chars' => mb_strlen($caption),
                ];
            } catch (Throwable $e) {
                $metaInfo = ['erro' => $e->getMessage()];
            }
        } elseif ($postId > 0 && (empty($cfgTrend['fb_page_id']) || empty($cfgTrend['fb_page_token']))) {
            $metaInfo = ['pulado' => true, 'motivo' => 'site sem credenciais Meta (fb_page_id/fb_page_token)'];
        }

        // 5e) CLUSTER INTERLINK — se este artigo pertence a um evento sazonal
        //      e já há ≥ 2 posts publicados desse evento, interliga todos (hub ← satélites).
        //      Idempotente: limpa blocos antigos antes de reinserir. Silencioso quando não aplicável.
        $interlinkInfo = null;
        if ($postId > 0 && !empty($trend['evento_fonte'])) {
            try {
                require_once __DIR__ . '/DiscoverCluster.php';
                // Importante: '_site_slug' (com underscore) é setado por _site_helper.php::aplicarSite().
                // 'site_slug' sem underscore não existe — bug silencioso que fazia interlink nunca disparar.
                $siteSlug = (string)($trend['site'] ?? $this->cfg['_site_slug'] ?? '');
                if ($siteSlug !== '') {
                    $clusterSvc = new DiscoverCluster($this->cfg, $this->db);
                    $allClusters = $clusterSvc->listarClusters($siteSlug);
                    foreach ($allClusters as $cl) {
                        if ($cl['nome'] === $trend['evento_fonte'] && ($cl['publicados'] ?? 0) >= 2) {
                            $r = $clusterSvc->interligar($siteSlug, $cl['nome']);
                            $interlinkInfo = [
                                'evento'      => $cl['nome'],
                                'atualizados' => $r['atualizados'] ?? 0,
                                'total'       => $r['total_posts'] ?? 0,
                                'hub_post_id' => $r['hub_post_id'] ?? null,
                            ];
                            break;
                        }
                    }
                }
            } catch (Throwable $e) {
                $interlinkInfo = ['erro' => $e->getMessage()];
            }
        }

        // 6) Score de qualidade — avalia HTML final contra checklist Discover
        $quality = null;
        $diagAbertura = ['manual' => false, 'motivo' => 'nao_avaliado'];
        $diagFluidez = [];
        $diagRepeticao = [];
        if ($postId > 0 && !empty($content)) {
            try {
                $quality = DiscoverQualityScore::avaliar($titulo, $content);
            } catch (Throwable $e) {
                $quality = ['erro' => $e->getMessage()];
            }
            try {
                $diagAbertura  = DiscoverPostProcess::diagnosticarAbertura($content);
                $diagFluidez   = DiscoverPostProcess::diagnosticarFluidez($content);
                $diagRepeticao = DiscoverPostProcess::diagnosticarRepeticoes($content);
                $diagExpositivo = DiscoverPostProcess::diagnosticarExposicaoApoH2($content);
                $diagLongTail  = DiscoverKeywordLongTail::diagnosticarCobertura($content, $termo);
                $diagCompliance = DiscoverClusterMatcher::validarCompliance($content, $cluster);
                $diagPromessa  = DiscoverPostProcess::diagnosticarPromessaNaoCalibrada($content);
                $diagAlerta    = DiscoverPostProcess::diagnosticarAlertaForte($content);
            } catch (Throwable $e) {}
            if (!isset($diagExpositivo))  $diagExpositivo  = [];
            if (!isset($diagLongTail))    $diagLongTail    = ['cobertura_pct' => null, 'alerta' => false, 'h2_fora' => []];
            if (!isset($diagCompliance))  $diagCompliance  = [];
            if (!isset($diagPromessa))    $diagPromessa    = [];
            if (!isset($diagAlerta))      $diagAlerta      = ['presente' => false, 'estilo' => 'ausente', 'alerta_recomendado' => true];
        }

        // 6.5) HTML VALIDATOR — sanity check final antes de marcar publicado
        // Detecta bugs invisíveis: <a> aninhado, atributos vazados em text nodes, smart quotes.
        // Auto-fix via DOM. Se restar crítico, marca status='html_invalido' (não publica).
        $htmlValidatorInfo = null;
        if (!empty($content) && $postId > 0) {
            try {
                require_once __DIR__ . '/DiscoverHtmlValidator.php';
                $val = DiscoverHtmlValidator::validar($content);
                $htmlValidatorInfo = [
                    'ok'         => $val['ok'],
                    'problemas'  => $val['problemas'],
                    'corrigidos' => $val['corrigidos'],
                    'criticos_restantes' => $val['criticos_restantes'] ?? [],
                ];
                // Se houve auto-fix, atualiza WP com HTML corrigido
                if (!empty($val['corrigidos']) && $val['html'] !== $content) {
                    try {
                        $this->wp->atualizarPost($postId, ['content' => $val['html']]);
                        $content = $val['html'];
                    } catch (Throwable $e) { /* não bloqueia */ }
                }
                // Se não passou, dispara alerta + marca status especial
                if (!$val['ok']) {
                    require_once __DIR__ . '/HealthWebhook.php';
                    HealthWebhook::erro('HTML inválido detectado pós-geração', [
                        'site' => $cfgTrend['_site_slug'] ?? '?',
                        'post_id' => $postId,
                        'criticos' => implode(',', $val['criticos_restantes'] ?? []),
                    ]);
                    // Marca post como rascunho pra não publicar lixo
                    try {
                        $this->wp->atualizarPost($postId, ['status' => 'draft']);
                    } catch (Throwable $e) {}
                }
            } catch (Throwable $e) {
                $htmlValidatorInfo = ['erro' => $e->getMessage()];
            }
        }

        // 6-bis) TITLE A/B + P1 A/B — gera variantes alternativas pra Swappers futuros.
        // Usadas pelo gsc_aprender quando post entra em opportunity zone (CTR baixo, top 10).
        // Title Swap > P1 Swap > Reviewer (do mais barato pro mais caro). Fail-open.
        $tituloVariantes = [];
        $p1Variantes = [];
        try {
            require_once __DIR__ . '/DiscoverTitleVariantes.php';
            $briefingResumo = is_array($briefing ?? null) ? json_encode($briefing, JSON_UNESCAPED_UNICODE) : '';
            $tituloVariantes = DiscoverTitleVariantes::gerar($titulo, $termo, (string)$briefingResumo, $this->claude);
        } catch (Throwable $e) { /* variantes são PLUS — não bloqueiam publicação */ }
        $p1Original = '';
        try {
            require_once __DIR__ . '/DiscoverP1Variantes.php';
            // Extrai P1 do conteúdo final (texto do 1º <p>)
            if (is_string($content) && preg_match('/<p\b[^>]*>([\s\S]*?)<\/p>/i', $content, $mP1)) {
                $p1Original = trim(strip_tags(html_entity_decode((string)$mP1[1], ENT_QUOTES, 'UTF-8')));
            }
            if ($p1Original !== '') {
                $briefingResumo = $briefingResumo ?? (is_array($briefing ?? null) ? json_encode($briefing, JSON_UNESCAPED_UNICODE) : '');
                $p1Variantes = DiscoverP1Variantes::gerar($p1Original, $titulo, $termo, (string)$briefingResumo, $this->claude);
            }
        } catch (Throwable $e) { /* P1 variantes são PLUS */ }

        // 6-ter) META TAGS — og_title (Discover preview punchy) + meta_description (snippet SERP)
        // + 2 variantes pra A/B via DiscoverMetaSwapper. Aplicado no WP via Yoast/RankMath fields.
        $metaTags = [];
        try {
            require_once __DIR__ . '/DiscoverMetaTags.php';
            $briefingResumo = $briefingResumo ?? (is_array($briefing ?? null) ? json_encode($briefing, JSON_UNESCAPED_UNICODE) : '');
            $metaTags = DiscoverMetaTags::gerar($titulo, $p1Original, $termo, (string)$briefingResumo, $this->claude);
            if (!empty($metaTags) && !empty($postId)) {
                DiscoverMetaTags::aplicarNoWp($this->wp, (int)$postId, $metaTags);
            }
        } catch (Throwable $e) { /* meta tags são PLUS */ }

        // 6-quater) GUARD FINAL ANTI-TRAVESSÃO
        // Manifesto editorial proíbe travessões (—/–) no corpo. PostProcess principal já tenta remover,
        // mas algum stage subsequente (LinkValidator, internal/authority/related links, MetaTags) pode
        // re-introduzir via concatenação. Caso real: post #716 leaodabarra ficou com 12 travessões
        // mesmo com PostProcess passando. Esse guard re-aplica a substituição como última checagem.
        if (!empty($postId)) {
            try {
                $postFinal = $this->wp->getPost((int)$postId);
                $contentFinal = $postFinal['content']['raw'] ?? $postFinal['content']['rendered'] ?? '';
                if ($contentFinal !== '') {
                    $emDash = substr_count($contentFinal, "\xE2\x80\x94");
                    $enDash = substr_count($contentFinal, "\xE2\x80\x93");
                    if ($emDash + $enDash > 0) {
                        $contentLimpo = DiscoverPostProcess::substituirTravessaoContextual($contentFinal);
                        if ($contentLimpo !== $contentFinal) {
                            $this->wp->atualizarPost((int)$postId, ['content' => $contentLimpo]);
                            $progress->reportar('guard_travessao', "Removidos {$emDash} em-dash + {$enDash} en-dash que escaparam do PostProcess");
                        }
                    }
                }
            } catch (Throwable $e) { /* guard não bloqueia */ }
        }

        // 7) Atualiza DB simulado
        // POLÍTICA fidelity_warn (2026-05-02): se SourceFidelityValidator detectou alucinação
        // (nome ou URL com path sem lastro na fonte), NÃO marca como 'publicado'. Status fica
        // 'fidelity_warn' pra revisão humana antes de ir ao ar. Post WP já é draft por default
        // (WP_DEFAULT_STATUS=draft), então o conteúdo não vaza enquanto não for revisado.
        // Caso real: post #728 leaodabarra atribuiu falsamente "Kaio Jorge assume posto" à
        // Rádio Itatiaia (fonte não dizia isso) — risco editorial e jurídico.
        $fidelityFail = isset($validationReport['fidelity']['severity'])
            && $validationReport['fidelity']['severity'] === 'fail';

        if ($fidelityFail) {
            $statusFinal = 'fidelity_warn';
        } elseif (!empty($auditoria) && isset($auditoria['ok']) && !$auditoria['ok']) {
            $statusFinal = 'suspeita';
        } elseif (!empty($htmlValidatorInfo) && isset($htmlValidatorInfo['ok']) && !$htmlValidatorInfo['ok']) {
            $statusFinal = 'html_invalido';
        } else {
            $statusFinal = 'publicado';
        }
        if ($trendId > 0) {
            $extras = [
                'url_post'        => $editUrl,
                'titulo'          => $titulo,
                'publicado_em'    => date('Y-m-d H:i:s'),
                'auditoria'       => $auditoria,
                'quality_score'   => $quality['score'] ?? null,
                'quality_status'  => $quality['status'] ?? null,
                'quality_detalhes'=> $quality['detalhes'] ?? null,
                'quality_melhorias'=> $quality['melhorias'] ?? [],
                'web_story_info'  => $webStoryInfo,
                'afiliado_info'   => $afiliadoInfo,
                'product_ranker'  => $rankerInfo,
                'html_validator'  => $htmlValidatorInfo,
                'onesignal_info'  => $onesignalInfo,
                'indexing_info'   => $indexingInfo,
                'meta_info'       => $metaInfo,
                'validation'      => $validationReport ?? null,
            ];
            if (!empty($tituloVariantes)) {
                $extras['titulo_variantes'] = $tituloVariantes;
            }
            if (!empty($p1Variantes)) {
                $extras['p1_variantes'] = $p1Variantes;
            }
            if (!empty($metaTags)) {
                $extras['meta_tags'] = $metaTags;
            }
            $this->db->updateStatus($trendId, $statusFinal, $extras);
        }

        $progress->concluido();

        return [
            'ok'             => true,
            'post_id'        => $postId,
            'titulo'         => $titulo,
            'titulo_score'   => $tituloInfo['score'],
            'titulo_falhas'  => $tituloInfo['falhas'],
            'titulo_refeito' => $tituloInfo['refeito'],
            'abertura_alerta'=> $diagAbertura['manual'] ?? false,
            'abertura_motivo'=> $diagAbertura['motivo'] ?? 'ok',
            'fluidez_issues' => $diagFluidez,
            'repeticao_issues'=> $diagRepeticao,
            'expositivo_issues'=> $diagExpositivo,
            'longtail_h2'    => $diagLongTail,
            'cluster'        => ['nome' => $cluster['nome'] ?? null, 'key' => $cluster['key'] ?? null],
            'pain'           => ($painRet = DiscoverPainClassifier::classificar($termo)),
            'arbitragem'     => DiscoverRPM::calcular([
                'cluster_key'    => $cluster['key'] ?? '',
                'pain'           => $painRet,
                'score_discover' => $quality['score'] ?? null,
            ]),
            'compliance_issues' => $diagCompliance,
            'promessa_issues'=> $diagPromessa,
            'alerta_forte'   => $diagAlerta,
            'internal_links_count'  => $internalLinksAplicados ?? 0,
            'authority_links_count' => isset($content) ? substr_count($content, 'data-authority-link') : 0,
            'links_alucinados_removidos' => $linksRemovidosAlucinados ?? 0,
            'edit_url'       => $editUrl,
            'fontes_usadas'  => count($urlsFinais),
            'fontes_tentadas'=> $col['fontes_tentadas'] ?? count($urlsFinais),
            'chars_fontes'   => $totalChars,
            'auditoria'      => $auditoria,
            'validation'     => $validationReport ?? null,
            'quality'        => $quality,
            'status'         => $statusFinal,
            'provedor'       => 'Claude ' . ($this->cfg['anthropic_model'] ?? ''),
            'llm_fallback'   => !empty($trend['_fallback_from_gpt']) ? 'claude' : $llmFallbackUsado,
            'gpt_erro_original' => $trend['_gpt_erro_original'] ?? null,
            'cluster_interlink' => $interlinkInfo,
            'afiliado'          => $afiliadoInfo,
            'product_ranker'    => $rankerInfo,
            'web_story'         => $webStoryInfo,
            'onesignal'         => $onesignalInfo,
            'indexing'          => $indexingInfo,
            'social'            => $socialInfo,
        ];
    }

    /**
     * Renderiza o bloco HTML de CTA de afiliado — estilo destacado.
     *
     * URL segue o Pretty Links plugin: /{pretty_links_prefix}/{slug}  (default: /go/{slug})
     * O plugin Pretty Links faz o rastreamento nativamente. Nosso /go.php serve só pra dev.
     *
     * IMPORTANTE: user precisa criar o Pretty Link MANUALMENTE no WP apontando para a
     * url_afiliado real. afiliados.php mostra a URL esperada + instruções.
     */
    private function renderizarBlocoAfiliado(array $oferta, int $trendId, int $postId, ?array $cfgOverride = null): string
    {
        $slug   = htmlspecialchars((string)$oferta['slug'], ENT_QUOTES, 'UTF-8');
        $nome   = htmlspecialchars((string)$oferta['nome'], ENT_QUOTES, 'UTF-8');
        $desc   = htmlspecialchars((string)($oferta['descricao_curta'] ?? ''), ENT_QUOTES, 'UTF-8');
        $cta    = htmlspecialchars((string)($oferta['cta_texto'] ?? 'Ver oferta'), ENT_QUOTES, 'UTF-8');
        $emoji  = (string)($oferta['cta_emoji'] ?? '👉');

        // FIX multi-site: usa cfg passado (do site do trend), com fallback pro cfg base do gerador.
        $cfgUse = $cfgOverride ?? $this->cfg;

        // Pretty Links URL (absoluta pra funcionar em qualquer post — wp_url do site CORRETO)
        $prefix = trim((string)($cfgUse['pretty_links_prefix'] ?? 'go'), '/');
        $base   = rtrim((string)($cfgUse['wp_url'] ?? ''), '/');
        $goUrl  = $base . '/' . $prefix . '/' . urlencode($oferta['slug']);

        // Estilo inline pra funcionar em qualquer tema WP sem depender de CSS externo.
        $html = "\n<!-- discover-afiliado -->\n"
              . "<aside class='discover-afiliado-cta' style='margin:24px 0;padding:18px 20px;background:linear-gradient(135deg,#fef3c7,#fde68a);border:2px solid #f59e0b;border-radius:10px;box-shadow:0 2px 8px rgba(245,158,11,.15);font-family:sans-serif'>"
              . "<div style='display:flex;align-items:center;gap:12px;flex-wrap:wrap'>"
              . "<div style='font-size:36px;line-height:1'>{$emoji}</div>"
              . "<div style='flex:1;min-width:200px'>"
              . "<div style='font-weight:800;color:#78350f;font-size:15px;margin-bottom:2px'>{$nome}</div>"
              . ($desc !== '' ? "<div style='color:#92400e;font-size:13px;line-height:1.4'>{$desc}</div>" : '')
              . "</div>"
              . "<a href='{$goUrl}' target='_blank' rel='sponsored noopener' "
              . "style='display:inline-block;padding:10px 18px;background:#d97706;color:#fff;text-decoration:none;border-radius:6px;font-weight:700;font-size:14px;white-space:nowrap'>"
              . "{$cta} →</a>"
              . "</div>"
              . "<div style='margin-top:8px;font-size:10px;color:#92400e;opacity:.75'>Conteúdo patrocinado · ao clicar, você é direcionado ao parceiro</div>"
              . "</aside>\n"
              . "<!-- /discover-afiliado -->\n";
        return $html;
    }

    /**
     * Injeta o bloco após o primeiro </h2> do conteúdo. Se não houver H2,
     * injeta após o primeiro </p>. Se também não houver, prepende no topo.
     * Idempotente — limpa bloco anterior antes de inserir.
     */
    private static function injetarAposPrimeiroH2(string $html, string $blocoHtml): string
    {
        // Limpeza idempotente
        $html = preg_replace('/\s*<!-- discover-afiliado -->[\s\S]*?<!-- \/discover-afiliado -->\s*/', "\n", $html) ?? $html;

        // Tenta após o primeiro </h2>
        if (preg_match('/<\/h2>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $pos) . $blocoHtml . substr($html, $pos);
        }
        // Fallback 1: após o primeiro </p>
        if (preg_match('/<\/p>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $pos) . $blocoHtml . substr($html, $pos);
        }
        // Fallback 2: topo
        return $blocoHtml . $html;
    }

    /**
     * Tenta enriquecer uma fonte com conteúdo adicional quando o scrape principal vem pobre.
     * Fallbacks em ordem: (1) articleBody do JSON-LD, (2) versão AMP, (3) description/og:description.
     */
    private function enriquecerFonte(array $f, string $url): array
    {
        $charsAtual = strlen(implode(' ', $f['content']['paragraphs'] ?? []));
        if ($charsAtual >= 1500) return $f; // já bom, não mexer

        // FALLBACK 1 — articleBody em JSON-LD (schema.org/Article, NewsArticle)
        $jsonld = $f['meta']['jsonld'] ?? [];
        if (is_array($jsonld)) {
            $articleBody = $this->extrairArticleBody($jsonld);
            if ($articleBody && mb_strlen($articleBody) > 300) {
                // Fragmenta em parágrafos por \n\n ou period duplo
                $paras = preg_split('/\n\s*\n|(?<=[.!?])\s{2,}/u', $articleBody, -1, PREG_SPLIT_NO_EMPTY);
                $paras = array_filter(array_map('trim', $paras), fn($p) => mb_strlen($p) >= 40);
                if (!empty($paras)) {
                    $f['content']['paragraphs'] = array_values(array_unique(array_merge($f['content']['paragraphs'] ?? [], $paras)));
                    $f['_enriched'] = ($f['_enriched'] ?? '') . ' jsonld';
                }
            }
        }
        $charsAtual = strlen(implode(' ', $f['content']['paragraphs'] ?? []));
        if ($charsAtual >= 1500) return $f;

        // FALLBACK 2 — versão AMP (Globo, Estadão, UOL publicam AMP completo)
        $ampUrl = $this->detectarAmp($url, $jsonld);
        if ($ampUrl && $ampUrl !== $url) {
            try {
                $amp = $this->scraper->fetch($ampUrl);
                $paraAmp = $amp['content']['paragraphs'] ?? [];
                $charsAmp = strlen(implode(' ', $paraAmp));
                if ($charsAmp > $charsAtual) {
                    $f['content']['paragraphs'] = array_values(array_unique(array_merge($f['content']['paragraphs'] ?? [], $paraAmp)));
                    if (empty($f['meta']['title'])) $f['meta']['title'] = $amp['meta']['title'] ?? '';
                    $f['_enriched'] = ($f['_enriched'] ?? '') . ' amp';
                }
            } catch (Throwable $e) { /* ignora */ }
        }
        $charsAtual = strlen(implode(' ', $f['content']['paragraphs'] ?? []));
        if ($charsAtual >= 1500) return $f;

        // FALLBACK 3 — description / og:description / JSON-LD description (último recurso)
        $descritivo = '';
        foreach ([$f['meta']['description'] ?? '', $f['meta']['og_description'] ?? '', $this->extrairJsonldCampo($jsonld, 'description')] as $d) {
            if (is_string($d) && mb_strlen($d) > mb_strlen($descritivo)) $descritivo = $d;
        }
        if (mb_strlen($descritivo) >= 150) {
            $f['content']['paragraphs'] = array_merge($f['content']['paragraphs'] ?? [], [$descritivo]);
            $f['_enriched'] = ($f['_enriched'] ?? '') . ' meta-desc';
        }

        return $f;
    }

    private function extrairArticleBody($node): ?string
    {
        if (is_array($node)) {
            if (!empty($node['articleBody']) && is_string($node['articleBody'])) {
                return $node['articleBody'];
            }
            foreach ($node as $child) {
                $r = $this->extrairArticleBody($child);
                if ($r !== null) return $r;
            }
        }
        return null;
    }

    private function extrairJsonldCampo($node, string $campo): ?string
    {
        if (is_array($node)) {
            if (isset($node[$campo]) && is_string($node[$campo])) return $node[$campo];
            foreach ($node as $child) {
                $r = $this->extrairJsonldCampo($child, $campo);
                if ($r !== null) return $r;
            }
        }
        return null;
    }

    /** Tenta identificar a versão AMP: <link rel="amphtml"> ou padrões comuns por domínio. */
    private function detectarAmp(string $url, $jsonld): ?string
    {
        // JSON-LD pode ter "amphtml" em campos diversos
        $amp = $this->extrairJsonldCampo($jsonld, 'amphtml');
        if ($amp && preg_match('#^https?://#', $amp)) return $amp;

        // Tenta baixar o HTML rapido e procurar rel=amphtml
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => $this->cfg['user_agent'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RANGE => '0-30000', // primeiros 30KB bastam pra achar o <head>
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
        if (is_string($html) && preg_match('#<link[^>]+rel=["\']amphtml["\'][^>]+href=["\']([^"\']+)#i', $html, $m)) {
            return $m[1];
        }
        // Padrões comuns: /amp no fim, ou ?outputType=amp
        if (preg_match('#globo\.com#i', $url)) {
            return rtrim($url, '/') . '/amp';
        }
        return null;
    }

    /** Converte o briefing estruturado em instruções textuais pros blocos da Maquina. */
    private function briefingParaBlocos(?array $briefing, string $termo): array
    {
        $bl = [];

        // BLOCO ZERO — anti-alucinação (sempre injetado, independente de ter briefing)
        $bl[] = "═══ BLINDAGEM ANTI-ALUCINAÇÃO (PRIORIDADE MÁXIMA) ═══\n"
              . "PROIBIÇÕES ABSOLUTAS (violar = conteúdo inaceitável):\n"
              . "1) NÃO cite NOMES DE PESSOAS (técnicos, jogadores, políticos, executivos, celebridades, funcionários, autoridades) que NÃO apareçam LITERALMENTE no texto das fontes fornecidas.\n"
              . "   - Se a fonte fala 'o técnico' sem nomear → o artigo fala 'o técnico' sem nomear.\n"
              . "   - Se a fonte fala 'o presidente da empresa' sem nomear → NÃO invente nome.\n"
              . "   - NÃO use conhecimento prévio pra preencher identidade, cargo, histórico, idade, nacionalidade.\n"
              . "2) NÃO liste escalações, times, elencos, ministros, membros, participantes a menos que a LISTA COMPLETA esteja LITERALMENTE na fonte.\n"
              . "   - Se a fonte lista parcialmente, você lista apenas o que está lá e diz 'entre outros' ou 'além de outros confirmados'.\n"
              . "3) NÃO traga DATAS, VALORES, ESTATÍSTICAS, PERCENTUAIS que não estejam literalmente nas fontes.\n"
              . "4) NÃO afirme RESULTADOS de eventos (jogos, eleições, julgamentos, votações, premiações) que não estejam literalmente confirmados nas fontes.\n"
              . "5) Em tema esportivo/eventos: se a fonte é sobre pré-jogo, o artigo é sobre pré-jogo. Se é pós-jogo, é pós-jogo. NÃO misture.\n"
              . "6) Se a fonte é escassa/curta sobre determinado ponto, você OMITE esse ponto em vez de preencher de memória.\n"
              . "REGRA DE OURO: quando em dúvida sobre um fato específico, OMITA o fato. A omissão é permitida, a invenção NÃO.\n"
              . "Cite a fonte pelo nome do veículo ('segundo a CNN Brasil', 'de acordo com o Estadão') sempre que possível.\n";

        // BRIEFING vem ANTES de persona — persona é o último bloco (efeito recência).
        if (!empty($briefing)) {
            $bl[] = "═══ BRIEFING EDITORIAL PRÉ-ANALISADO ═══\n"
                  . "TERMO: {$termo}\n"
                  . "GRUPO EDITORIAL: " . ($briefing['grupo_editorial'] ?? '-') . "\n"
                  . "ÂNGULO PRINCIPAL SUGERIDO: " . ($briefing['angulo_principal'] ?? '-') . "\n"
                  . "ÂNGULO UNIVERSAL (incluir 1x no texto): " . ($briefing['angulo_universal'] ?? '-') . "\n"
                  . "INTENÇÃO DE BUSCA: " . ($briefing['intencao'] ?? '-') . "\n"
                  . "TÍTULO SUGERIDO (base — pode refinar, SÓ com informação das fontes): " . ($briefing['titulo_sugerido'] ?? '-') . "\n"
                  . "GANCHO SUGERIDO DO P1: " . ($briefing['gancho_p1'] ?? '-') . "\n"
                  . "OBS: O briefing acima é uma SUGESTÃO baseada no termo. Se o ângulo conflitar com a PERSONA do site (bloco final), prevalece a PERSONA — reescreva o ângulo sob a lente do nicho deste site.\n";

            if (!empty($briefing['h3_sugeridos'])) {
                $bl[] = "SUBTÍTULOS H3 RECOMENDADOS (use pelo menos 4 deles):\n- " . implode("\n- ", $briefing['h3_sugeridos']);
            }

            if (!empty($briefing['faq_sugerido'])) {
                $bl[] = "FAQ OBRIGATÓRIO (incluir como seção com 3-5 perguntas, priorizando estas):\n- " . implode("\n- ", $briefing['faq_sugerido']);
            }

            if (!empty($briefing['palavras_chave'])) {
                $bl[] = "PALAVRAS-CHAVE (distribuir natural, sem stuffing):\n- " . implode("\n- ", $briefing['palavras_chave']);
            }
        }

        // BLOCO PERSONA — voz editorial do site atual. ÚLTIMO bloco propositalmente
        // (efeito recência no LLM = persona prevalece sobre briefing genérico em conflito).
        $p = $this->cfg['persona'] ?? null;
        $personaValida = is_array($p) && !empty($p['autor']) && !empty($p['voz'])
                      && !empty($p['especialidade']) && !empty($p['audiencia']) && !empty($p['tom']);
        if ($personaValida) {
            $clustersFoco = is_array($p['clusters_foco'] ?? null) ? implode(', ', $p['clusters_foco']) : '';
            $proibidos    = is_array($p['termos_proibidos'] ?? null) ? implode('; ', $p['termos_proibidos']) : '';
            $bloco = "═══ PERSONA DESTE SITE (voz editorial — TEM PRIORIDADE SOBRE BRIEFING) ═══\n"
                   . "SITE:            " . ($this->cfg['_site_name'] ?? '?') . "\n"
                   . "AUTOR:           " . (string)$p['autor'] . "\n"
                   . "VOZ:             " . (string)($p['voz'] ?? '') . "\n"
                   . "ESPECIALIDADE:   " . (string)($p['especialidade'] ?? '') . "\n"
                   . "AUDIÊNCIA-ALVO:  " . (string)($p['audiencia'] ?? '') . "\n"
                   . "TOM:             " . (string)($p['tom'] ?? '') . "\n";
            if ($clustersFoco !== '') $bloco .= "CLUSTERS FOCO:   {$clustersFoco}\n";
            if ($proibidos !== '')    $bloco .= "PROIBIDOS:       {$proibidos}\n";
            if (!empty($p['cta_estilo'])) $bloco .= "CTAs TÍPICAS:    " . (string)$p['cta_estilo'] . "\n";

            // Caminho C: subtipo_nicho declarado é a AUTORIDADE editorial. Termos_canibal
            // são "patrimônio" de sites IRMÃOS e devem ser EVITADOS na geração (não só
            // bloqueados no lint depois de gastar Sonnet).
            $subtipo  = trim((string)($this->cfg['subtipo_nicho'] ?? ''));
            $canibal  = is_array($this->cfg['termos_canibal'] ?? null)
                ? array_slice($this->cfg['termos_canibal'], 0, 12) : [];
            $empresa  = trim((string)($this->cfg['empresa']['nome'] ?? ''));
            if ($subtipo !== '') {
                $bloco .= "SUBTIPO NICHO:   {$subtipo}\n";
                $bloco .= "EDITORA:         {$empresa}\n";
                $bloco .= "REGRA DE FOCO:   o artigo DEVE caber em '{$subtipo}'. Se o termo da trend bate apenas tangencialmente, ENCAIXE o ângulo no subtipo (ex: virando comparativo, guia, alerta de prazo). Sem encaixe possível, escolha o ângulo MAIS PRÓXIMO da especialidade.\n";
            }
            if (!empty($canibal)) {
                $bloco .= "TERMOS DE OUTROS SITES IRMÃOS (EVITE — pertencem a editorias paralelas):\n";
                $bloco .= "  " . implode('; ', $canibal) . "\n";
                $bloco .= "  Se a trend forçar abordar esses termos, fique no NÍVEL ALTO/CONTEXTUAL e devolva o foco pro nosso subtipo. Não escreva guia/passo-a-passo desses tópicos.\n";
            }
            $bloco .= "\n"
                    . "REGRA DE OURO (override do briefing):\n"
                    . "1. TODO artigo deve soar escrito por ESTE autor para ESTA audiência.\n"
                    . "2. Se o briefing sugerir ângulo INFORMATIVO genérico (data, origem, história, definição) mas a ESPECIALIDADE deste site é COMERCIAL/SERVIÇO/ESPORTE/etc, REESCREVA o ângulo sob a lente do nicho:\n"
                    . "   - Site de SHOPPING (comocomprar, ondecompraragora) → ângulo vira '10 ideias até R$ X', 'oferta da semana', 'erros que custam dinheiro', 'comparativo de preço', 'guia de compra'. NUNCA 'data, origem, história'.\n"
                    . "   - Site de SERVIÇO (vagasebeneficios) → ângulo vira 'quem tem direito', 'como solicitar', 'prazo que está acabando', 'erro que tira o benefício'.\n"
                    . "   - Site de EDUCAÇÃO (cursosenac, guiadoscursos) → ângulo vira 'cursos abertos hoje', 'como se inscrever', 'critérios de seleção', 'edital novo'.\n"
                    . "   - Site de ESPORTE (leaodabarra) → ângulo vira 'onde assistir', 'escalação confirmada', 'análise tática', 'histórico recente do confronto'. NUNCA 'biografia/origem'.\n"
                    . "3. Se o tema da trend foge totalmente da ESPECIALIDADE, adapte SEM negar a identidade editorial — encontre o gancho do nicho dentro do tema.\n"
                    . "4. Em conflito persona × briefing, VENCE a persona. O briefing é input, a persona é diretriz.\n";
            $bl[] = $bloco;
        }

        return $bl;
    }
}
