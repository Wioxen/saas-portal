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

        $fontesOk = [];
        $totalChars = 0;
        foreach ($urlsCandidatas as $url) {
            try {
                $f = $this->scraper->fetch($url);
                $f = $this->enriquecerFonte($f, $url);
                $textoLen = 0;
                if (!empty($f['content']['paragraphs'])) {
                    $textoLen = strlen(implode(' ', $f['content']['paragraphs']));
                }
                if ($textoLen >= self::MIN_POR_FONTE) {
                    $fontesOk[]   = ['url' => $url, 'fonte' => $f, 'chars' => $textoLen];
                    $totalChars  += $textoLen;
                }
            } catch (Throwable $e) { /* pula */ }
            if (count($fontesOk) >= self::MAX_FONTES && $totalChars >= self::MIN_AGREGADO) break;
        }

        // Critério: 2+ fontes OU 1 fonte robusta (≥ 5000 chars = rich content tipo JSON-LD articleBody)
        $unicaRobusta = count($fontesOk) === 1 && ($fontesOk[0]['chars'] ?? 0) >= self::MIN_FONTE_SOLO;
        $multiOk      = count($fontesOk) >= 2 && $totalChars >= self::MIN_AGREGADO;

        if (!$multiOk && !$unicaRobusta) {
            return [
                'ok'               => false,
                'erro'             => sprintf('Fontes insuficientes: %d OK (mín 2 ou 1 com ≥%d chars), %d chars totais (mín %d). Paywall/JS.', count($fontesOk), self::MIN_FONTE_SOLO, $totalChars, self::MIN_AGREGADO),
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
            'textos'          => $textos,
        ];
    }

    // ═══ ENRICHMENT (copiado de DiscoverGerador) ═══
    private function enriquecerFonte(array $f, string $url): array
    {
        $charsAtual = strlen(implode(' ', $f['content']['paragraphs'] ?? []));
        if ($charsAtual >= self::MIN_POR_FONTE) return $f;

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
        if ($charsAtual >= self::MIN_POR_FONTE) return $f;

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
        if ($charsAtual >= self::MIN_POR_FONTE) return $f;

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
