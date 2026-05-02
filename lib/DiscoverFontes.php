<?php
/**
 * Coletor + Enriquecedor de fontes. Compartilhado por DiscoverGerador (Claude) e
 * DiscoverGeradorGPT. Faz:
 *  1. Lista artigos via TrendsArticles (Google News + Serper)
 *  2. Scrape com Scraper
 *  3. Enrichment: articleBody (JSON-LD), versão AMP, og/meta description
 *  4. Filtra por threshold mínimo e retorna fontes aceitas
 */
class DiscoverFontes
{
    public const MIN_POR_FONTE     = 1200;  // 2 fontes cross-validam — relaxa por fonte
    public const MIN_AGREGADO      = 3000;  // com 2+ fontes, 3000 chars já é substancial
    public const MAX_FONTES        = 4;     // tenta mais fontes pra aumentar hit rate
    /** Tolerância — se só 1 fonte passou mas tem muito conteúdo, aceita. */
    public const MIN_FONTE_SOLO    = 4000;

    /**
     * Extrai tokens relevantes do termo pra filtragem de fontes por relevância.
     *
     * Estratégia: se termo tem padrão "X x Y" / "X vs Y" (jogo de futebol), o adversário
     * é o token mais discriminante. Sem adversário, usa palavras-chave significativas
     * (≥4 chars, não-stopword) que distingam o trend de outros do mesmo clube.
     *
     * Caso real #756: termo "Vitória x Coritiba: onde assistir" → tokens=['coritiba'].
     * Fonte 'confianca-x-vitoria-tv' não tem 'coritiba' no URL/título → rejeitada.
     */
    /** Público pra reuso em Maquina.php que tem scraper próprio. */
    public static function extrairTokensRelevancia(string $termo): array
    {
        $tokens = [];
        $termoLow = mb_strtolower($termo, 'UTF-8');
        // Remove acentos pra match robusto (URL slugs frequentemente sem acento)
        $termoNorm = strtr($termoLow, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);

        // Stopwords + nicho-stopwords (palavras do clube alvo do site, comuns em TODAS
        // as fontes do leaodabarra — não discriminam jogos diferentes do mesmo clube).
        // INCLUI nomes de fontes de imprensa (meuvitoria, bahianoticias, etc.) — termos
        // do Google News frequentemente terminam com " - [Nome do Site]" e essa palavra
        // bate com a URL da fonte → todas passam, filtro inútil.
        static $stop = [
            // genéricas
            'sobre','onde','assistir','ao','vivo','hoje','vai','vem','que','para','pelo',
            'pela','dos','das','quando','horario','horário','escala','escalações',
            'escalacao','escalacoes','desfalques','arbitragem','jogo','partida','rodada',
            'campeonato','brasileirao','brasileirão','copa','série','serie','time','clube',
            'futebol','tem','será','sera','está','esta','com',
            // verbos / palavras genéricas comuns em títulos de notícia
            'saiba','veja','confira','descubra','entenda','revela','revelou','anuncia',
            'anunciou','divulga','divulgou','contra','noticias','notícia','noticia',
            // nicho do leaodabarra (vão estar em TODAS as fontes do site, não discriminam)
            'vitoria','vitória','leao','leão','rubro','negro','rubro-negro','barradao','barradão',
            'salvador','bahia','baiano','baiana','manoel','barradas',
            // Nomes de fontes de imprensa (bate com URL/title da fonte → quebra filtro)
            'meuvitoria','meuvitória','bahianoticias','bnews','arenarubronegra','correio24horas',
            'terra','placar','itatiaia','globo','uol','ge','espn','sportv','premiere',
            'estadao','folha','oglobo','metropoles','cnn','cnnbrasil','revista','portal',
            'fogaonet','fogaonet.com','umdois','umdoisesportes','noataque',
            // outros clubes — vão estar quando rival visita ou é mencionado em sidebar
            // (não usar como discriminador único)
        ];

        // Padrão "X x Y" ou "X vs Y" (jogo) — extrai os 2 lados, IGNORANDO o lado nicho
        // do site (ex: 'vitória' em leaodabarra). Só o ADVERSÁRIO conta como discriminante.
        if (preg_match('/\b([a-zA-Z\-]+)\s*(?:x|vs)\s+([a-zA-Z\-]+)/iu', $termoNorm, $m)) {
            $a = trim($m[1]);
            $b = trim($m[2]);
            // Filtra cada lado: ignora se é stopword (nicho) ou < 4 chars
            if (mb_strlen($a) >= 4 && !in_array($a, $stop, true)) $tokens[] = $a;
            if (mb_strlen($b) >= 4 && !in_array($b, $stop, true)) $tokens[] = $b;
        }

        // Tokens adicionais relevantes do termo (entidades únicas, ≥5 chars, não stop)
        if (preg_match_all('/\b([a-z][a-z\-]{4,})\b/iu', $termoNorm, $m)) {
            foreach ($m[1] as $w) {
                $w = mb_strtolower(trim($w), 'UTF-8');
                if (in_array($w, $stop, true)) continue;
                if (in_array($w, $tokens, true)) continue;
                $tokens[] = $w;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Resolve threshold considerando override per-site no $cfg.
     * Permite que sites com fontes naturalmente mais curtas (ex: esportes — texto
     * jornalístico de jogo é breve) tenham critério mais permissivo. Outros sites
     * mantêm o default conservador.
     *
     * Keys aceitas em $cfg:
     *   fontes_min_por_fonte  → override de MIN_POR_FONTE
     *   fontes_min_agregado   → override de MIN_AGREGADO
     *   fontes_min_fonte_solo → override de MIN_FONTE_SOLO
     *   fontes_max_fontes     → override de MAX_FONTES
     */
    private function thresh(string $key): int
    {
        $cfgKey = 'fontes_' . $key;
        if (isset($this->cfg[$cfgKey]) && is_numeric($this->cfg[$cfgKey])) {
            return (int)$this->cfg[$cfgKey];
        }
        $constMap = [
            'min_por_fonte'  => self::MIN_POR_FONTE,
            'min_agregado'   => self::MIN_AGREGADO,
            'min_fonte_solo' => self::MIN_FONTE_SOLO,
            'max_fontes'     => self::MAX_FONTES,
        ];
        return $constMap[$key] ?? 0;
    }

    private TrendsArticles $artigos;
    private Scraper $scraper;
    private ?Serper $serper = null;
    private array $cfg;

    public function __construct(array $cfg, TrendsArticles $artigos, Scraper $scraper, ?Serper $serper = null)
    {
        $this->cfg     = $cfg;
        $this->artigos = $artigos;
        $this->scraper = $scraper;
        $this->serper  = $serper;
        // Auto-cria Serper se cfg tem chave e não veio injetado
        if ($this->serper === null && !empty($cfg['serper_api_key'])) {
            $this->serper = new Serper($cfg['serper_api_key']);
        }
    }

    /**
     * Coleta fontes pra um termo. Aplica enrichment em cada uma.
     * @return array ['ok'=>bool, 'fontes_ok'=>[...], 'chars_totais'=>int, 'erro'=>?, 'textos'=>[...]]
     */
    public function coletar(string $termo, int $max = 5): array
    {
        // 1ª onda: TrendsArticles (Google News RSS + Serper title-resolve)
        $lista = $this->artigos->listar($termo, $max);
        $urlsCandidatas = [];
        foreach ($lista as $a) {
            if (!empty($a['url_real'])) $urlsCandidatas[] = $a['url_real'];
        }

        // 2ª onda: Serper organic direto (SEMPRE, mesclado) — mais candidatas = mais chance
        // de passar nos thresholds, especialmente pra termos institucionais (PIS, INSS, FGTS)
        if ($this->serper !== null) {
            try {
                $serpResp = $this->serper->search($termo, 10);
                foreach (($serpResp['organic'] ?? []) as $r) {
                    $u = (string)($r['link'] ?? '');
                    if ($u === '' || in_array($u, $urlsCandidatas, true)) continue;
                    // Pula redes sociais, vídeo, PDF
                    if (preg_match('#(facebook|instagram|twitter|tiktok|youtube|pinterest|globoplay)\.com#i', $u)) continue;
                    if (preg_match('#\.pdf($|\?)#i', $u)) continue;
                    $urlsCandidatas[] = $u;
                    if (count($urlsCandidatas) >= 10) break;
                }
            } catch (Throwable $e) { /* pula se falhar */ }
        }

        if (empty($urlsCandidatas)) {
            return ['ok' => false, 'erro' => 'Sem URLs resolvidas (TrendsArticles + Serper).', 'fontes_ok' => [], 'chars_totais' => 0, 'textos' => []];
        }

        $minPorFonte  = $this->thresh('min_por_fonte');
        $minAgregado  = $this->thresh('min_agregado');
        $minFonteSolo = $this->thresh('min_fonte_solo');
        $maxFontes    = $this->thresh('max_fontes');

        // FILTRO DE RELEVÂNCIA POR ADVERSÁRIO (caso #756 leaodabarra 2026-05-02):
        // Pra trend tipo "Vitória x Coritiba" Serper retornava 4 fontes com palavra "Vitória"
        // — mas 2 eram de OUTROS jogos (Confiança x Vitória, Athletico-PR x Vitória) que
        // contaminaram extração de fatos (TV Aratu do Nordestão virou canal do Brasileirão).
        // Solução: extrair adversário do termo (depois do " x " ou " vs ") e exigir presença
        // dele na URL ou título da fonte antes de scrape.
        $tokensRelevancia = self::extrairTokensRelevancia($termo);

        $fontesOk = [];
        $totalChars = 0;
        $fontesRejeitadasRelevancia = [];
        foreach ($urlsCandidatas as $url) {
            try {
                $f = $this->scraper->fetch($url);
                $f = $this->enriquecerFonte($f, $url);
                $textoLen = 0;
                if (!empty($f['content']['paragraphs'])) {
                    $textoLen = strlen(implode(' ', $f['content']['paragraphs']));
                }
                if ($textoLen < $minPorFonte) continue;

                // Filtragem por relevância: se termo tem adversário (ex: "Vitória x Coritiba"),
                // a fonte só passa se URL ou título mencionam o adversário. Evita contaminação
                // de fatos entre jogos diferentes do mesmo clube.
                if (!empty($tokensRelevancia)) {
                    $haystack = mb_strtolower(
                        $url . ' ' . ($f['meta']['title'] ?? '') . ' ' . ($f['meta']['description'] ?? ''),
                        'UTF-8'
                    );
                    $matches = 0;
                    foreach ($tokensRelevancia as $tok) {
                        if (mb_strpos($haystack, $tok) !== false) $matches++;
                    }
                    // Exige >=1 match dos tokens significativos (adversário ou contexto único)
                    if ($matches === 0) {
                        $fontesRejeitadasRelevancia[] = $url;
                        continue;
                    }
                }

                $fontesOk[]   = ['url' => $url, 'fonte' => $f, 'chars' => $textoLen];
                $totalChars  += $textoLen;
            } catch (Throwable $e) { /* pula */ }
            if (count($fontesOk) >= $maxFontes && $totalChars >= $minAgregado) break;
        }

        // Critério: 2+ fontes OU 1 fonte robusta (>= MIN_FONTE_SOLO chars)
        $unicaRobusta = count($fontesOk) === 1 && ($fontesOk[0]['chars'] ?? 0) >= $minFonteSolo;
        $multiOk      = count($fontesOk) >= 2 && $totalChars >= $minAgregado;

        if (!$multiOk && !$unicaRobusta) {
            return [
                'ok'               => false,
                'erro'             => sprintf('Fontes insuficientes: %d OK (mín 2 ou 1 com ≥%d chars), %d chars totais (mín %d). Paywall/JS.', count($fontesOk), $minFonteSolo, $totalChars, $minAgregado),
                'fontes_ok'        => $fontesOk,
                'chars_totais'     => $totalChars,
                'fontes_tentadas'  => count($urlsCandidatas),
                'textos'           => [],
            ];
        }

        // Gera textos concatenados de cada fonte (pra auditor)
        $textos = [];
        foreach ($fontesOk as $f) {
            $t = '';
            if (!empty($f['fonte']['meta']['title'])) $t .= $f['fonte']['meta']['title'] . "\n";
            $t .= implode("\n", $f['fonte']['content']['paragraphs'] ?? []);
            $textos[] = $t;
        }

        return [
            'ok'              => true,
            'fontes_ok'       => $fontesOk,
            'chars_totais'    => $totalChars,
            'fontes_tentadas' => count($urlsCandidatas),
            'fontes_rejeitadas_relevancia' => $fontesRejeitadasRelevancia ?? [],
            'tokens_relevancia' => $tokensRelevancia ?? [],
            'textos'          => $textos,
        ];
    }

    // ═══ ENRICHMENT (copiado de DiscoverGerador) ═══
    private function enriquecerFonte(array $f, string $url): array
    {
        $charsAtual = strlen(implode(' ', $f['content']['paragraphs'] ?? []));
        if ($charsAtual >= $this->thresh('min_por_fonte')) return $f;

        // FALLBACK 1 — articleBody em JSON-LD
        $jsonld = $f['meta']['jsonld'] ?? [];
        if (is_array($jsonld)) {
            $articleBody = $this->extrairArticleBody($jsonld);
            if ($articleBody && mb_strlen($articleBody) > 300) {
                $paras = preg_split('/\n\s*\n|(?<=[.!?])\s{2,}/u', $articleBody, -1, PREG_SPLIT_NO_EMPTY);
                $paras = array_filter(array_map('trim', $paras), fn($p) => mb_strlen($p) >= 40);
                if (!empty($paras)) {
                    $f['content']['paragraphs'] = array_values(array_unique(array_merge($f['content']['paragraphs'] ?? [], $paras)));
                    $f['_enriched'] = ($f['_enriched'] ?? '') . ' jsonld';
                }
            }
        }
        $charsAtual = strlen(implode(' ', $f['content']['paragraphs'] ?? []));
        if ($charsAtual >= $this->thresh('min_por_fonte')) return $f;

        // FALLBACK 2 — versão AMP
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
        if ($charsAtual >= $this->thresh('min_por_fonte')) return $f;

        // FALLBACK 3 — description/og:description
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
            if (!empty($node['articleBody']) && is_string($node['articleBody'])) return $node['articleBody'];
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

    private function detectarAmp(string $url, $jsonld): ?string
    {
        $amp = $this->extrairJsonldCampo($jsonld, 'amphtml');
        if ($amp && preg_match('#^https?://#', $amp)) return $amp;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => $this->cfg['user_agent'] ?? 'Mozilla/5.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RANGE => '0-30000',
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
        if (is_string($html) && preg_match('#<link[^>]+rel=["\']amphtml["\'][^>]+href=["\']([^"\']+)#i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('#globo\.com#i', $url)) {
            return rtrim($url, '/') . '/amp';
        }
        return null;
    }
}
