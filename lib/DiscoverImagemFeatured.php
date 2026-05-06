<?php
/**
 * DiscoverImagemFeatured — escolhe a imagem destacada do post via cascata.
 *
 * DEFAULT atual (2026-05-04, 'og_first'):
 *   1. og:image (preferido — foto autêntica da matéria-fonte, melhor E-E-A-T/autoridade)
 *   2. Pexels API (fallback — quando og é inválido: logo, brasão, ícone)
 *   3. DALL-E 3 (último recurso — geração editorial, ~$0.04/imagem hd)
 *
 * Estratégias alternativas via $cfg['imagem_featured_estrategia']:
 *   - 'og_only'      → SOMENTE og, sem fallback (leaodabarra: foto real do clube)
 *   - 'pexels_first' → comportamento legado (Pexels → DALL-E → og)
 *   - 'dalle_first'  → DALL-E direto (caro, raro — A/B test apenas)
 *
 * Por que mudou pra og_first em 2026-05-04:
 *   - og:image da fonte original = foto contextual REAL (alunos, cerimônia, edital, etc)
 *   - Google Discover/SERP premiam imagem original > stock Pexels > geração IA
 *   - E-E-A-T: imagem da fonte reforça credibilidade editorial
 *   - Validação ogImageValido() filtra logos/brasões/ícones (cai pra Pexels nesses)
 *
 * Heurística de query Pexels (extraída do termo + cluster):
 *   - Termos em PT-BR convertidos pra EN (Pexels tem mais resultados)
 *   - 3 queries de prioridade decrescente; primeira que retorna ≥1 candidato vence
 *   - Filtros: orientation=landscape, size=large (≥1920px), re-rank por score editorial
 *
 * Heurística de prompt DALL-E:
 *   - Template editorial estruturado: "Editorial photography, [SUJEITO], [CONTEXTO], natural light..."
 *   - Negative obrigatório: "NO text overlays, NO logos, NO graphics" (regra Discover)
 *   - Aspect 1792×1024 ≈ 16:9
 *
 * Nome de arquivo SEO:
 *   - Sempre `{slug-do-post}.jpg` quando upload via WP (não nome aleatório)
 *   - Ajuda Google a entender que imagem é original e relevante
 */

require_once __DIR__ . '/Pexels.php';
require_once __DIR__ . '/OpenAI.php';

class DiscoverImagemFeatured
{
    private array $cfg;
    private ?Pexels $pexels = null;
    private ?OpenAI $openai = null;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        if (!empty($cfg['pexels_api_key'])) {
            try { $this->pexels = new Pexels($cfg['pexels_api_key']); }
            catch (Throwable $e) { $this->pexels = null; }
        }
        if (!empty($cfg['openai_api_key'])) {
            $this->openai = new OpenAI($cfg['openai_api_key'], 'gpt-4o-mini');
        }
    }

    /**
     * Escolhe URL HTTPS da melhor imagem disponível.
     *
     * @param array $contexto {termo, cluster_key, briefing_titulo, og_image_fallback, slug?}
     * @return array {url, fonte: 'pexels|dalle|og', metadata: [...], slug_sugerido}
     *               url='' se nada funcionou (caller decide se cria post sem featured)
     */
    public function escolher(array $contexto): array
    {
        $termo       = (string)($contexto['termo'] ?? '');
        $clusterKey  = (string)($contexto['cluster_key'] ?? '');
        $tituloHint  = (string)($contexto['briefing_titulo'] ?? $termo);
        $ogFallback  = (string)($contexto['og_image_fallback'] ?? '');
        $estrategia  = (string)($this->cfg['imagem_featured_estrategia'] ?? 'og_first');
        $dalleFb     = !empty($this->cfg['imagem_featured_dalle_fallback']);
        // Override via env (testes): IMAGEM_FEATURED_OVERRIDE=dalle_first força DALL-E
        // mesmo em sites com og_first/pexels_first/og_only.
        $envOverride = trim((string)getenv('IMAGEM_FEATURED_OVERRIDE'));
        if ($envOverride !== '') $estrategia = $envOverride;

        $slugSugerido = self::slugSeo($tituloHint, $termo);

        // Estratégia legacy — ignora Pexels/DALL-E (leaodabarra: foto real da fonte)
        if ($estrategia === 'og_only') {
            return ['url' => $ogFallback, 'fonte' => 'og', 'metadata' => [], 'slug_sugerido' => $slugSugerido];
        }

        // og_first — tenta og:image primeiro (autenticidade da fonte), fallback Pexels/DALL-E.
        // Recomendado pra cursosenac/guiadoscursos: MEC/Senac/UTFPR/IFs publicam imagens
        // contextuais reais (alunos, cerimônia, prédio) que Google premia mais que stock.
        if ($estrategia === 'og_first' && self::ogImageValido($ogFallback)) {
            return [
                'url' => $ogFallback,
                'fonte' => 'og',
                'metadata' => ['motivo' => 'og:image autêntico da fonte (og_first)'],
                'slug_sugerido' => $slugSugerido,
            ];
        }

        // 1. Pexels (preferido)
        if ($this->pexels !== null && $estrategia !== 'dalle_first') {
            $resultado = $this->tentarPexels($termo, $clusterKey);
            if ($resultado !== null) {
                $resultado['slug_sugerido'] = $slugSugerido;
                return $resultado;
            }
        }

        // 2. DALL-E (fallback ou primary se estratégia=dalle_first)
        if ($this->openai !== null && ($dalleFb || $estrategia === 'dalle_first')) {
            $resultado = $this->tentarDalle($termo, $clusterKey, $tituloHint);
            if ($resultado !== null) {
                $resultado['slug_sugerido'] = $slugSugerido;
                return $resultado;
            }
        }

        // 3. og:image (último recurso)
        return [
            'url' => $ogFallback,
            'fonte' => 'og',
            'metadata' => ['motivo' => 'pexels+dalle falharam ou desabilitados'],
            'slug_sugerido' => $slugSugerido,
        ];
    }

    /**
     * Heurística: og:image é válido se URL não está vazio e não é logo/ícone/brasão genérico.
     * Rejeita patterns típicos de logo institucional (gov.br/imagens/brasao.svg, etc).
     */
    private static function ogImageValido(string $ogUrl): bool
    {
        if ($ogUrl === '' || !filter_var($ogUrl, FILTER_VALIDATE_URL)) return false;
        $low = mb_strtolower($ogUrl);
        // Patterns de logo/ícone — rejeitar
        $rejeitar = ['/logo', '/brand', '/icon', '/favicon', 'brasao', 'avatar', 'placeholder', 'default-image', 'no-image'];
        foreach ($rejeitar as $p) {
            if (str_contains($low, $p)) return false;
        }
        // Aceitar formatos comuns
        return preg_match('/\.(jpg|jpeg|png|webp|avif)(\?|$)/i', $low) === 1;
    }

    /**
     * Gera 3 queries Pexels de prioridade decrescente. Pega top score da primeira que retornar.
     */
    private function tentarPexels(string $termo, string $clusterKey): ?array
    {
        $queries = self::gerarQueriesPexels($termo, $clusterKey);
        foreach ($queries as $q) {
            $candidatos = $this->pexels->buscar($q, 15, 'landscape');
            if (empty($candidatos)) continue;

            // Filtra score mínimo (40 = corta sinais negativos como "screenshot/logo/3d")
            $candidatos = array_filter($candidatos, fn($c) => $c['score'] >= 40);
            if (empty($candidatos)) continue;

            $top = reset($candidatos);
            return [
                'url' => $top['url'],
                'fonte' => 'pexels',
                'metadata' => [
                    'query'        => $q,
                    'photographer' => $top['photographer'],
                    'pexels_id'    => $top['id'],
                    'alt'          => $top['alt'],
                    'score'        => $top['score'],
                ],
            ];
        }
        return null;
    }

    /**
     * Gera queries Pexels em inglês a partir do termo + cluster.
     * Estratégia 1: keyword principal mapeada por cluster
     * Estratégia 2: query genérica do cluster
     * Estratégia 3: fallback super genérico
     */
    public static function gerarQueriesPexels(string $termo, string $clusterKey): array
    {
        $termoLow = mb_strtolower($termo, 'UTF-8');
        $queries = [];

        // ── Estratégia 1: keywords específicas do termo ──
        $mapaKeywords = [
            // Educação / vestibulares
            'enem'           => 'student studying desk',
            'sisu'           => 'university student campus',
            'fies'           => 'student loan college',
            'prouni'         => 'college student campus',
            'vestibular'     => 'student studying books',
            'fuvest'         => 'concentrated student exam',
            'unicamp'        => 'university student',
            'redação'        => 'student writing notebook',
            'isenção'        => 'concentrated student mobile',
            'inscrição'      => 'home office education',
            'enade'          => 'graduation ceremony',
            'concurso'       => 'professional studying paperwork',
            'edital'         => 'office documents reading',

            // Educação infantil/jovem
            'pé-de-meia'     => 'brazilian high school student uniform',
            'pe de meia'     => 'brazilian high school student uniform',
            'bolsa família'  => 'brazilian family money mother child',
            'volta às aulas' => 'students walking school',

            // Benefícios / governo
            'inss'           => 'elderly person paperwork retirement',
            'aposentado'     => 'senior person home',
            'fgts'           => 'professional documents office',
            'auxílio'        => 'mother child kitchen home',
            'cadúnico'       => 'family home documents',
            'pix'            => 'mobile phone payment',
            'restituição'    => 'tax documents calculator',
            'imposto'        => 'tax documents calculator',

            // Trabalho
            'vagas'          => 'professional job interview',
            'emprego'        => 'professional handshake meeting',
            'salário'        => 'office worker desk',
            'clt'            => 'professional office workplace',

            // Shopping
            'presente'       => 'gift box ribbon',
            'mãe'            => 'mother family love',
            'pai'            => 'father child happy',
            'criança'        => 'children playing toys',
            'natal'          => 'christmas tree gifts',
            'black friday'   => 'shopping bags discount',
            'amazon'         => 'package delivery box',
            'cozinha'        => 'modern kitchen home',
            'beleza'         => 'beauty cosmetics products',
            'perfume'        => 'perfume bottle elegant',

            // Saúde
            'saúde'          => 'doctor patient hospital',
            'sus'            => 'medical hospital brazil',

            // ─── Esporte Clube Vitória (BA) — nicho exclusivo do leaodabarra (pivot 2026-05-02) ───
            // Entidades únicas do clube têm prioridade sobre as keywords genéricas de futebol BR
            'esporte clube vitória' => 'vitoria salvador soccer brazil',
            'ec vitória'      => 'vitoria salvador soccer brazil',
            'leão da barra'   => 'vitoria salvador rubro-negro soccer',
            'rubro-negro baiano' => 'vitoria salvador soccer red black',
            'barradão'        => 'barradao stadium salvador soccer',
            'manoel barradas' => 'barradao stadium salvador soccer',
            'jair ventura'    => 'soccer coach brazil sideline',
            'fábio mota'      => 'soccer club president meeting',
            'jamerson'        => 'vitoria soccer player full back brazil',
            'erick serafim'   => 'vitoria soccer striker brazil',
            'matheuzinho'     => 'vitoria soccer midfielder brazil',
            'lucas arcanjo'   => 'soccer goalkeeper brazil',
            'camutanga'       => 'vitoria soccer center back',
            'renato kayzer'   => 'vitoria soccer striker brazil',
            'osvaldo filho'   => 'vitoria soccer winger brazil',
            'renzo lópez'     => 'soccer striker uruguay brazil',
            'kike saverio'    => 'vitoria soccer brazil player',
            'ronald vitória'  => 'soccer midfielder brazil',
            'dudu vitória'    => 'soccer midfielder brazil',
            'aitor cantalapiedra' => 'soccer playmaker spain brazil',
            'emmanuel martínez' => 'soccer playmaker argentina brazil',
            'ba-vi'           => 'classic salvador soccer rivalry brazil',
            'campeonato baiano' => 'salvador soccer brazil bahia',
            'copa do nordeste' => 'salvador recife soccer brazil northeast',

            // Esportes — Brasileirão Série A (todos os 20 clubes + apelidos comuns)
            'flamengo'       => 'soccer stadium fans brazil',
            'palmeiras'      => 'soccer brazil stadium',
            'corinthians'    => 'soccer brazil stadium',
            'são paulo fc'   => 'soccer brazil stadium',
            'spfc'           => 'soccer brazil stadium',
            'fluminense'     => 'soccer brazil stadium',
            'botafogo'       => 'soccer brazil stadium',
            'vasco'          => 'soccer brazil stadium',
            'cruzeiro'       => 'soccer brazil stadium minas',
            'atlético'       => 'soccer brazil stadium minas',
            'atletico-mg'    => 'soccer brazil stadium minas',
            'mineirão'       => 'mineirao stadium soccer',
            'galo'           => 'soccer brazil stadium minas',
            'raposa'         => 'soccer brazil stadium minas',
            'grêmio'         => 'soccer brazil stadium gaucho',
            'internacional'  => 'soccer brazil stadium gaucho',
            'colorado'       => 'soccer brazil stadium gaucho',
            'bahia'          => 'soccer brazil stadium nordeste',
            'vitória'        => 'vitoria salvador rubro-negro soccer',
            'sport'          => 'soccer brazil stadium nordeste',
            'fortaleza'      => 'soccer brazil stadium nordeste',
            'ceará'          => 'soccer brazil stadium nordeste',
            'mirassol'       => 'soccer brazil stadium',
            'santos'         => 'soccer brazil stadium',
            'athletico'      => 'soccer brazil stadium',
            'coritiba'       => 'soccer brazil stadium',
            // Modalidades / competições
            'libertadores'   => 'soccer match crowd south america',
            'brasileirão'    => 'soccer match brazil stadium',
            'sul-americana'  => 'soccer match crowd south america',
            'copa do brasil' => 'soccer match brazil',
            'copa do mundo'  => 'world cup soccer fans',
            'seleção'        => 'brazil national team soccer',
            'clássico'       => 'soccer rivalry brazil stadium',
            'derby'          => 'soccer rivalry brazil stadium',
            'dérbi'          => 'soccer rivalry brazil stadium',
            'escalação'      => 'soccer team formation field',
            'jogo'           => 'soccer match brazil stadium',
            'técnico'        => 'soccer coach sideline',
            'treinador'      => 'soccer coach sideline',
            'pênalti'        => 'soccer penalty kick goal',
            'gol'            => 'soccer goal celebration',
            // F1 / Outros
            'f1'             => 'formula 1 race car',
            'fórmula 1'      => 'formula 1 race car',
            'senna'          => 'formula 1 race brazil',
            'nba'            => 'basketball arena game',
            'ufc'            => 'mma fighter cage',
            'mma'            => 'mma fighter cage',
            'vôlei'          => 'volleyball match court',
            'superliga'      => 'volleyball match court',
            'basquete'       => 'basketball match court',
            // Jogadores top do momento — força query específica quando termo é sobre jogador
            'gerson'         => 'soccer player midfielder brazil',
            'hulk'           => 'soccer player striker brazil',
            'neymar'         => 'soccer player brazil',
            'messi'          => 'soccer player legend',
            'kaio jorge'     => 'soccer striker brazil',

            // Tech
            'celular'        => 'smartphone modern mobile',
            'iphone'         => 'smartphone person hands',
            'notebook'       => 'laptop modern workspace',
            'inteligência artificial' => 'technology computer ai',
        ];

        foreach ($mapaKeywords as $palavra => $query) {
            if (mb_strpos($termoLow, $palavra) !== false) {
                $queries[] = $query;
                if (count($queries) >= 2) break;
            }
        }

        // ── Estratégia 2: query genérica do cluster ──
        $mapaCluster = [
            'educacao'              => 'student studying education',
            'noticias_info_critica' => 'news brazil people',
            'negocios_financas'     => 'finance money brazil',
            'tecnologia'            => 'modern technology workspace',
            'lifestyle_consumo'     => 'shopping happy people',
            'comidas_bebidas'       => 'food restaurant kitchen',
            'viagem_transporte'     => 'travel landscape brazil',
            'automoveis'            => 'modern car road',
            'saude_bem_estar'       => 'healthcare wellness people',
            'esportes'              => 'soccer brazil stadium fans',
            'entretenimento'        => 'people watching event',
            'curiosidades_geral'    => 'people curious looking',
            'comemorativo_data'     => 'celebration brazilian family',
        ];
        if (isset($mapaCluster[$clusterKey])) {
            $queries[] = $mapaCluster[$clusterKey];
        }

        // ── Estratégia 3: fallback super genérico ──
        $queries[] = 'brazilian people lifestyle';

        return array_values(array_unique($queries));
    }

    /**
     * Gera imagem via DALL-E com prompt editorial estruturado.
     */
    private function tentarDalle(string $termo, string $clusterKey, string $tituloHint): ?array
    {
        $prompt = self::montarPromptDalle($termo, $clusterKey, $tituloHint);
        // Prefixo anti-rewriting (oficial OpenAI) — DALL-E reescreve menos
        $prompt = "I NEED to test how the tool works with extremely specific prompts. DO NOT add any detail, just use it AS-IS:\n\n" . $prompt;

        try {
            // style 'vivid' = high-CTR (default do ChatGPT)
            $res = $this->openai->gerarImagemDetalhado($prompt, '1792x1024', 'hd', 'vivid', 'dall-e-3');
        } catch (Throwable $e) {
            return null;
        }
        if (!$res || empty($res['url'])) return null;
        return [
            'url'      => $res['url'],
            'fonte'    => 'dalle',
            'metadata' => [
                'prompt'         => $prompt,
                'revised_prompt' => $res['revised_prompt'] ?? null,
                'model'          => 'dall-e-3',
                'size'           => '1792x1024',
                'style'          => 'vivid',
            ],
        ];
    }

    /**
     * Template DALL-E para feed do Google Discover.
     *
     * Framing: parte do artigo gerado, declara intent viral, lista boas práticas oficiais
     * do Google e injeta cena editorial específica do cluster — pra produzir imagem
     * que pare o scroll em <1 segundo no mobile.
     */
    public static function montarPromptDalle(string $termo, string $clusterKey, string $tituloHint = ''): string
    {
        $tituloHint = trim($tituloHint);

        // Persona/cenário pelo termo (mantém variação contextual)
        $persona = self::personaPorTermo($termo, $clusterKey);

        // Overlay text: máximo 8 palavras complementares ao tema (NÃO o título inteiro).
        // Pega 4-8 palavras significativas do título já filtrado.
        $base = $tituloHint !== '' ? $tituloHint : $termo;
        $base = preg_replace('/[":;|.!?]/u', '', $base) ?? '';
        $palavras = preg_split('/\s+/', $base);
        $stop = ['de','da','do','das','dos','o','a','os','as','um','uma','e','ou','que','com','para','por','no','na','nos','nas','em','é','até','sobre','este','esta'];
        $palavras = array_values(array_filter($palavras, fn($p) => $p !== '' && mb_strlen($p) > 2 && !in_array(mb_strtolower($p), $stop, true)));
        $palavras = array_slice($palavras, 0, 8);
        $overlayText = mb_strtoupper(implode(' ', $palavras), 'UTF-8') ?: 'OPORTUNIDADE ABERTA';

        // Prompt: foto humana realista, sem cara de IA, otimizada pra Discover scroll-stop mobile.
        // Especificação user 06/05: imagem 16:9 humanizada, retângulo azul superior-esquerdo
        // com texto branco grande, sem ícones pequenos, mobile-first, sem aspecto de IA.
        $prompt  = "Create a 16:9 horizontal photograph for the Google Discover feed (Brazilian news portal). The image must look like a REAL photograph taken with a professional DSLR camera by a photojournalist, NOT artificial intelligence. Natural skin texture, realistic lighting, no over-sharpened or fantasy aesthetic.\n\n";
        $prompt .= "COMPOSITION:\n";
        $prompt .= "- Main subject: {$persona['person']}, captured in a natural authentic moment with relaxed unposed expression. Centered or slightly right of center (about 55-60% from left).\n";
        $prompt .= "- Background: {$persona['scenario']}; soft natural depth of field, blurred but coherent with the article topic.\n";
        $prompt .= "- Frame: 16:9 horizontal, generous breathing room, clean composition. Mobile-first — only large readable elements visible.\n";
        $prompt .= "- AVOID: small icons, complex graphics, multiple objects, busy scenes, fantasy elements, plastic skin, oversaturated colors.\n\n";
        $prompt .= "TEXT OVERLAY (mandatory, scroll-stop layer for Discover feed):\n";
        $prompt .= "- BLUE rectangular badge in the TOP-LEFT corner (about 35-45% of width, 15-20% of height).\n";
        $prompt .= "- Background color: solid editorial blue (#1E40AF or similar deep blue).\n";
        $prompt .= "- Text inside the rectangle: \"{$overlayText}\" — in WHITE, bold sans-serif font, large and legible at thumbnail size.\n";
        $prompt .= "- Maximum 8 words. NO small subtitles, NO numbers below 24pt, NO secondary text. ONLY the headline phrase, large.\n";
        $prompt .= "- The blue rectangle must NOT cover the subject's face.\n\n";
        $prompt .= "TECHNICAL:\n";
        $prompt .= "- Lighting: natural ambient light (window light, morning/afternoon), realistic shadows, no studio plastic look.\n";
        $prompt .= "- Color palette: neutral, true-to-life, slight warmth. Avoid HDR/oversaturated.\n";
        $prompt .= "- Style: photojournalism — like a Folha de SP or Estadão front-page photo.\n";
        $prompt .= "- Aspect ratio: 16:9 landscape strict.\n\n";

        if ($tituloHint !== '') {
            $prompt .= "EDITORIAL CONTEXT: news article titled \"{$tituloHint}\" about {$termo}. The image must visually anchor a Brazilian reader scrolling through Discover.\n\n";
        } else {
            $prompt .= "EDITORIAL CONTEXT: news article about {$termo}.\n\n";
        }
        $prompt .= "SAFETY: regular anonymous Brazilian adult; no real celebrities; no minors under 18; no violence/sexual/discriminatory content; no partisan political symbols; no private company logos.";

        return $prompt;
    }

    /**
     * Mapeamento de tema → persona/cenário/objeto/texto (espelha clonais_persona_por_tema do gerarpost).
     */
    private static function personaPorTermo(string $termo, string $clusterKey): array
    {
        $kw = mb_strtolower($termo . ' ' . $clusterKey, 'UTF-8');
        if (preg_match('/inss|aposenta|13[ºo°]?\s*sal|previd[êe]nci/u', $kw)) {
            return ['person' => 'a Brazilian senior citizen (around 65, grey hair, warm relieved smile)', 'scenario' => 'a cozy Brazilian home living room with afternoon sunlight', 'object' => 'a smartphone', 'device_text' => 'BENEFÍCIO LIBERADO'];
        }
        if (preg_match('/bolsa\s+fam|cad[úu]nico|aux[íi]lio/u', $kw)) {
            return ['person' => 'a Brazilian working-class mother in her 30s with a warm smile', 'scenario' => 'a simple Brazilian kitchen with afternoon light', 'object' => 'a smartphone', 'device_text' => 'CALENDÁRIO 2026'];
        }
        if (preg_match('/p[ée].?de.?meia|ensino\s+m[ée]dio/u', $kw)) {
            return ['person' => 'a Brazilian high-school student (late teens, hopeful confident)', 'scenario' => 'a Brazilian public school courtyard, peers blurred in bokeh', 'object' => 'a smartphone', 'device_text' => 'BENEFÍCIO ESTUDANTE'];
        }
        if (preg_match('/enem|sisu|fies|prouni|fuvest/u', $kw)) {
            return ['person' => 'a Brazilian college student (18-22, focused confident)', 'scenario' => 'a Brazilian university campus or library blurred in bokeh', 'object' => 'a tablet', 'device_text' => 'INSCRIÇÃO ABERTA'];
        }
        if (preg_match('/senac|sesi|sesc|senai|t[ée]cnic/u', $kw)) {
            return ['person' => 'a young Brazilian apprentice in their 20s wearing professional uniform', 'scenario' => 'a modern training workshop with equipment blurred in bokeh', 'object' => 'a tablet', 'device_text' => 'CURSO GRÁTIS'];
        }
        if (preg_match('/concurso|edital|servidor/u', $kw)) {
            return ['person' => 'a focused Brazilian professional in their 30s, smart-casual', 'scenario' => 'a clean home office with bookshelf blurred in bokeh', 'object' => 'a tablet', 'device_text' => 'EDITAL ABERTO'];
        }
        if (preg_match('/fgts|saque|caixa\s+tem|pis\b|pasep/u', $kw)) {
            return ['person' => 'a Brazilian working adult in their 40s with a relieved smile', 'scenario' => 'a clean modern Brazilian home with neutral tones, natural light', 'object' => 'a smartphone showing the Caixa Tem app', 'device_text' => 'SAQUE LIBERADO'];
        }
        if (preg_match('/vagas?\b|emprego|contrata/u', $kw)) {
            return ['person' => 'a confident Brazilian professional in their 30s, smart-casual smiling', 'scenario' => 'a modern office or coworking space, people blurred in bokeh', 'object' => 'a smartphone', 'device_text' => 'VAGAS ABERTAS'];
        }
        return ['person' => 'a Brazilian adult in their 30s with a warm confident expression', 'scenario' => 'a clean modern Brazilian environment, soft natural light, blurred in bokeh', 'object' => 'a smartphone', 'device_text' => 'CONFIRA'];
    }

    /**
     * Cena editorial por cluster. Inspirado em fotojornalismo brasileiro.
     */
    private static function cenaPorCluster(string $clusterKey, string $termoLow): array
    {
        $termoLow = mb_strtolower($termoLow, 'UTF-8');

        // Cenas específicas por keyword (sobreescreve cluster genérico)
        if (str_contains($termoLow, 'enem') || str_contains($termoLow, 'isenção')) {
            return [
                'scene'      => 'A young Brazilian student studying at home for an important exam',
                'focus'      => 'a smartphone screen showing a government website (gov.br style) and a notebook with handwritten notes',
                'background' => 'a laptop, a coffee cup, books and pens on a wooden desk near a sunlit window',
            ];
        }
        if (str_contains($termoLow, 'pé-de-meia') || str_contains($termoLow, 'pe de meia') || str_contains($termoLow, 'volta às aulas')) {
            return [
                'scene'      => 'A Brazilian high school teenager wearing a school uniform',
                'focus'      => 'the student smiling, holding books and a backpack in a public school hallway',
                'background' => 'other students walking, classroom doors, natural light from large windows',
            ];
        }
        if (str_contains($termoLow, 'inss') || str_contains($termoLow, 'aposent')) {
            return [
                'scene'      => 'A senior Brazilian person at home reviewing official documents',
                'focus'      => 'the elderly person\'s hands holding a printed letter with a calculator nearby',
                'background' => 'a comfortable home environment with framed family photos, soft daylight',
            ];
        }
        if (str_contains($termoLow, 'bolsa família') || str_contains($termoLow, 'auxílio')) {
            return [
                'scene'      => 'A Brazilian working-class mother in her home kitchen',
                'focus'      => 'the mother with her young child, smiling, holding a smartphone',
                'background' => 'a simple kitchen with groceries and cookware, warm afternoon light',
            ];
        }
        if (str_contains($termoLow, 'concurso') || str_contains($termoLow, 'edital')) {
            return [
                'scene'      => 'A Brazilian professional studying for a public service exam',
                'focus'      => 'an organized desk with study materials, sticky notes and a laptop',
                'background' => 'a bookshelf with technical books, modern home office',
            ];
        }
        if (str_contains($termoLow, 'amazon') || str_contains($termoLow, 'presente') || str_contains($termoLow, 'compra')) {
            return [
                'scene'      => 'A happy Brazilian person opening a delivery box at home',
                'focus'      => 'hands carefully unboxing a parcel with brown paper',
                'background' => 'a stylish living room interior, late afternoon sun',
            ];
        }
        if ($clusterKey === 'esportes') {
            return [
                'scene'      => 'Brazilian soccer fans cheering at a stadium',
                'focus'      => 'enthusiastic fans wearing team jerseys, hands raised',
                'background' => 'green soccer field, crowd, evening floodlights',
            ];
        }
        if ($clusterKey === 'tecnologia') {
            return [
                'scene'      => 'A modern home workspace with current technology',
                'focus'      => 'a person using a smartphone and laptop together at a clean desk',
                'background' => 'a minimalist room with plants and ambient daylight',
            ];
        }

        // Default genérico
        return [
            'scene'      => 'A Brazilian person in a relevant everyday context',
            'focus'      => 'a meaningful action related to the topic',
            'background' => 'natural urban or home environment, soft daylight',
        ];
    }

    /**
     * Slug SEO-friendly pra o nome do arquivo de imagem.
     * Combina título + termo se necessário, limita ~60 chars.
     */
    public static function slugSeo(string $tituloHint, string $termo = ''): string
    {
        $base = trim($tituloHint) !== '' ? $tituloHint : $termo;
        $s = mb_strtolower($base, 'UTF-8');
        $s = preg_replace('/[áàâãä]/u', 'a', $s) ?? $s;
        $s = preg_replace('/[éèêë]/u', 'e', $s) ?? $s;
        $s = preg_replace('/[íìîï]/u', 'i', $s) ?? $s;
        $s = preg_replace('/[óòôõö]/u', 'o', $s) ?? $s;
        $s = preg_replace('/[úùûü]/u', 'u', $s) ?? $s;
        $s = preg_replace('/[ç]/u', 'c', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        $s = trim($s, '-');
        if (mb_strlen($s) > 60) $s = mb_substr($s, 0, 60);
        $s = rtrim($s, '-');
        return $s !== '' ? $s : ('post-' . date('Ymd-His'));
    }
}
