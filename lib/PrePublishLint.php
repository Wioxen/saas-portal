<?php
/**
 * PrePublishLint — valida trend ANTES de chamar Sonnet ($0.30/post).
 *
 * Sem isso: cada trend "aprovado" no DB vira post, mesmo se for lixo (nome de pessoa
 * fofoca, termo gigante mal-extraído, sem fontes válidas, duplicado de post existente).
 * Com isso: rejeita ~10% dos trends antes de queimar Sonnet.
 *
 * Em volume real (100 posts/dia): rejeita ~10/dia × $0.30 = $3/dia salvos = $90/mês.
 *
 * Validações:
 *   1. Termo sane (≥3 palavras, ≤120 chars, sem emojis)
 *   2. Fontes scrapeadas têm ≥500 chars cada (mínimo conteúdo pra Sonnet trabalhar)
 *   3. Cluster mapeável (não cair em catch-all `curiosidades_geral` se score=0)
 *   4. Não-duplicado (similaridade <85% com posts já publicados no mesmo site)
 *   5. Blocklist editorial (já tem em SpikeDetector — replicado/expandido aqui)
 *   6. Cross-site dedup (similaridade >60% com post de site IRMÃO da mesma empresa
 *      = canibalização interna da rede; rejeita pra preservar autoridade tópica).
 *   7. Pre-flight de especialização (Caminho C): bate o termo contra `termos_canibal`
 *      do site (termos que pertencem a sites irmãos) — anti-canibalização cruzada.
 *
 * Score 0-100 (default threshold pra publicar: 50).
 *
 * Uso:
 *   $resultado = PrePublishLint::avaliar($trend, $fontesScrapeadas, $db, $threshold, $cfg);
 *   if (!$resultado['aprovado']) {
 *       // log motivo, marca status='rejeitado_lint', NÃO chama Sonnet
 *   }
 */

require_once __DIR__ . '/DiscoverDb.php';

class PrePublishLint
{
    /** Score mínimo pra aprovar (configurável via cfg.lint_threshold). */
    public const THRESHOLD_DEFAULT = 50;

    /** Padrões que rejeitam direto (score = 0 imediato). */
    private const BLOCKLIST_PADROES = [
        '/\bmorre|morreu|mortes?|falec\w+|luto\b/iu',
        '/\bbbb|big brother|reality\b/iu',
        '/\b(igreja|pastor|rcc|dízimo|santuário|aparição)\b/iu',
        '/\b(tiroteio|atirador|massacre|estupro|sequestro|abus\w+)\b/iu',
        '/\b(divórcio|traição|amante|romance|namora\w+|noiv\w+)\b/iu',
        '/\b(cocaína|heroína|tráfico)\b/iu',
        '/\bxuxa|anitta|gusttavo|simone|simaria|maraisa\b/iu',
    ];

    /**
     * Limiar de canibalização cruzada entre sites IRMÃOS (mesma empresa).
     * Pra mesmo site usamos 90% (block) / 75% (penalidade); pra rede usamos 60% (block).
     * Razão: dois sites irmãos cobrindo o mesmo trend é sinal de PBN pro Google.
     */
    public const CROSS_SITE_SIM_BLOCK = 60.0;

    /**
     * Avalia se trend deve passar pra Sonnet.
     *
     * @param array $trend     {termo, cluster_detect, ...}
     * @param array $fontes    fontes scrapeadas (após DiscoverFontes::coletar)
     * @param ?DiscoverDb $db  pra checar duplicação (null = pula esse check)
     * @param int $threshold   score mínimo pra passar (default 50)
     * @param array $cfg       cfg do site (empresa, subtipo_nicho, termos_canibal). Default vazio.
     * @return array {aprovado: bool, score: int, motivos: [...], detalhes: {...}}
     */
    public static function avaliar(array $trend, array $fontes = [], ?DiscoverDb $db = null, int $threshold = self::THRESHOLD_DEFAULT, array $cfg = []): array
    {
        $termo = trim((string)($trend['termo'] ?? ''));
        $score = 100;
        $motivos = [];
        $detalhes = ['termo' => $termo];

        // ── 1. Termo sane ──
        if ($termo === '') {
            return ['aprovado' => false, 'score' => 0, 'motivos' => ['termo_vazio'], 'detalhes' => $detalhes];
        }
        $palavras = preg_split('/\s+/u', $termo);
        $palavrasCount = count($palavras);
        if ($palavrasCount < 2) { $score -= 20; $motivos[] = 'termo_curto'; }
        if (mb_strlen($termo, 'UTF-8') > 120) { $score -= 25; $motivos[] = 'termo_gigante'; }
        if (preg_match('/[\x{1F300}-\x{1F9FF}]/u', $termo)) { $score -= 15; $motivos[] = 'termo_com_emoji'; }
        // Termos all-caps ou só números são sinal de mal-extracted
        if (preg_match('/^[A-Z0-9\s\-:]+$/', $termo) && mb_strlen($termo) > 10) { $score -= 15; $motivos[] = 'termo_caps_only'; }

        // ── 2. Blocklist editorial ──
        foreach (self::BLOCKLIST_PADROES as $rx) {
            if (preg_match($rx, $termo)) {
                return ['aprovado' => false, 'score' => 0, 'motivos' => ['blocklist'], 'detalhes' => $detalhes + ['regex_hit' => $rx]];
            }
        }

        // ── 2b. Pre-flight de especialização (Caminho C) ──
        // termos_canibal são termos que pertencem a sites IRMÃOS da mesma editora.
        // Se o trend bate aqui = canibalização cruzada da rede; rejeita pra preservar
        // autoridade tópica de cada site (Sistema 2 vs Sistema 3).
        // Normalização: lower + sem acentos + tolerância plural (singular/plural batem).
        $canibal = $cfg['termos_canibal'] ?? [];
        if (!empty($canibal) && is_array($canibal)) {
            $termoNorm = self::normalizarParaCanibal($termo);
            foreach ($canibal as $bloqueio) {
                $blNorm = self::normalizarParaCanibal((string)$bloqueio);
                if ($blNorm === '') continue;
                // Match com word-boundaries: evita "ies" bater "calories" mas permite "fies 2026"
                // Tenta também variação plural simples ("curso senac" ↔ "cursos senac")
                if (self::contemTermoNormalizado($termoNorm, $blNorm)) {
                    return [
                        'aprovado' => false,
                        'score'    => 0,
                        'motivos'  => ['canibal_cruzado'],
                        'detalhes' => $detalhes + [
                            'termo_canibal'  => $bloqueio,
                            'subtipo_nicho'  => (string)($cfg['subtipo_nicho'] ?? ''),
                            'empresa_grupo'  => (string)($cfg['empresa']['nome'] ?? ''),
                        ],
                    ];
                }
            }
        }

        // ── 3. Fontes têm conteúdo ──
        $charsTotais = 0;
        $fontesValidas = 0;
        foreach ($fontes as $f) {
            $paragrafos = $f['fonte']['content']['paragraphs'] ?? $f['paragraphs'] ?? [];
            $charsArtigo = is_array($paragrafos) ? array_sum(array_map('strlen', $paragrafos)) : 0;
            $charsTotais += $charsArtigo;
            if ($charsArtigo >= 500) $fontesValidas++;
        }
        $detalhes['fontes_chars'] = $charsTotais;
        $detalhes['fontes_validas'] = $fontesValidas;
        if ($fontesValidas === 0) { $score -= 30; $motivos[] = 'sem_fontes_validas'; }
        elseif ($fontesValidas === 1 && $charsTotais < 1500) { $score -= 15; $motivos[] = 'pouca_fonte'; }
        if ($charsTotais < 800) { $score -= 20; $motivos[] = 'chars_insuficientes'; }

        // ── 4. Cluster mapeável (score 0 = catch-all default = sinal fraco) ──
        $clusterKey = (string)($trend['cluster_detect']['key'] ?? '');
        $clusterScore = (int)($trend['cluster_detect']['score'] ?? 0);
        $detalhes['cluster_key'] = $clusterKey;
        $detalhes['cluster_score'] = $clusterScore;
        if ($clusterKey === 'curiosidades_geral' && $clusterScore <= 1) {
            $score -= 10; $motivos[] = 'cluster_default_fraco';
        }

        // ── 4b. Cluster Killer (B5) — site×cluster_key pausado por baixa performance 30d ──
        $siteAtual = (string)($trend['site'] ?? ($cfg['_site_slug'] ?? ''));
        if ($clusterKey !== '' && $siteAtual !== '') {
            $clusterKillerPath = __DIR__ . '/ClusterKiller.php';
            if (is_file($clusterKillerPath)) {
                require_once $clusterKillerPath;
                if (ClusterKiller::estaPausado($siteAtual, $clusterKey)) {
                    return [
                        'aprovado' => false,
                        'score'    => 0,
                        'motivos'  => ['cluster_paused'],
                        'detalhes' => $detalhes + ['cluster_pausado' => "{$siteAtual}|{$clusterKey}"],
                    ];
                }
            }
        }

        // ── 5. Duplicação ──
        if ($db !== null) {
            $site = (string)($trend['site'] ?? '');
            $publicados = $db->all(['site' => $site, 'status' => 'publicado']);
            $maxSim = 0;
            $matchTermo = '';
            foreach ($publicados as $pub) {
                $termoPub = (string)($pub['termo'] ?? '');
                if ($termoPub === '' || $termoPub === $termo) continue;
                similar_text(mb_strtolower($termo, 'UTF-8'), mb_strtolower($termoPub, 'UTF-8'), $sim);
                if ($sim > $maxSim) {
                    $maxSim = $sim;
                    $matchTermo = $termoPub;
                }
                if ($maxSim >= 95) break; // dispensa rest dos checks
            }
            $detalhes['similaridade_max'] = round($maxSim, 1);
            $detalhes['match_termo'] = $matchTermo;
            if ($maxSim >= 90) {
                return ['aprovado' => false, 'score' => 0, 'motivos' => ['duplicado_alto'], 'detalhes' => $detalhes];
            }
            if ($maxSim >= 75) { $score -= 25; $motivos[] = 'similar_existente'; }

            // ── 5b. Cross-site dedup (Caminho C) ──
            // Sites IRMÃOS da mesma editora não podem cobrir mesmo trend (>60% similaridade).
            // Pro Google, dois sites do mesmo dono cobrindo o mesmo assunto = sinal de PBN.
            $sisterSites = self::getSisterSites($cfg);
            if (!empty($sisterSites)) {
                $maxSimCross = 0;
                $matchTermoCross = '';
                $matchSiteCross = '';
                $matchUrlCross = '';
                foreach ($sisterSites as $sisterSlug) {
                    $publicadosIrmao = $db->all(['site' => $sisterSlug, 'status' => 'publicado']);
                    foreach ($publicadosIrmao as $pub) {
                        $termoPub = (string)($pub['termo'] ?? '');
                        if ($termoPub === '') continue;
                        similar_text(mb_strtolower($termo, 'UTF-8'), mb_strtolower($termoPub, 'UTF-8'), $simCross);
                        if ($simCross > $maxSimCross) {
                            $maxSimCross = $simCross;
                            $matchTermoCross = $termoPub;
                            $matchSiteCross = $sisterSlug;
                            $matchUrlCross = (string)($pub['url_post'] ?? '');
                        }
                        if ($maxSimCross >= 90) break 2;
                    }
                }
                $detalhes['cross_sim_max']     = round($maxSimCross, 1);
                $detalhes['cross_match_termo'] = $matchTermoCross;
                $detalhes['cross_match_site']  = $matchSiteCross;
                if ($matchUrlCross !== '') $detalhes['cross_match_url'] = $matchUrlCross;
                if ($maxSimCross >= self::CROSS_SITE_SIM_BLOCK) {
                    return [
                        'aprovado' => false,
                        'score'    => 0,
                        'motivos'  => ['canibal_intra_rede'],
                        'detalhes' => $detalhes,
                    ];
                }
            }
        }

        $score = max(0, min(100, $score));
        $aprovado = $score >= $threshold;

        return [
            'aprovado'  => $aprovado,
            'score'     => $score,
            'threshold' => $threshold,
            'motivos'   => $motivos,
            'detalhes'  => $detalhes,
        ];
    }

    /**
     * Normaliza string pra comparação canibal: lower + remove acentos + colapsa espaços.
     * Não remove pontuação leve (hífen/dois-pontos) — pode ser parte do termo legítimo.
     */
    private static function normalizarParaCanibal(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        // Remove acentos preservando ç (depois normalizado pra c)
        $de = ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ'];
        $pa = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'];
        $s = str_replace($de, $pa, $s);
        // Colapsa espaços
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * Verifica se $termoNorm (já normalizado) contém $blNorm como TERMO INTEIRO.
     *
     * Crítico: usar word-boundaries (`\b`) — substring puro causou falso positivo:
     *   "inss" (canibal) batendo "inscricoes" (palavra inocente).
     *
     * Tolerância plural simples: testa também blNorm + "s" (singular declarado bate plural).
     * NÃO testa singular-de-plural (substring inversa) porque é fonte de FP.
     *
     * "fies" bate "fies 2026" mas NÃO bate "calorIES" ou "inSCricoes".
     * "curso senac" bate "cursos senac" (via candidate "curso senacs"? não — inverte primeira palavra).
     *
     * Estratégia revisada:
     *   1. Tenta blNorm direto com \b
     *   2. Tenta cada palavra do blNorm com sufixo 's' (cobre "curso" → "cursos")
     */
    private static function contemTermoNormalizado(string $termoNorm, string $blNorm): bool
    {
        if ($blNorm === '' || $termoNorm === '') return false;

        // 1. Match direto com word-boundaries
        if (self::regexComBoundaries($termoNorm, $blNorm)) return true;

        // 2. Tolerância plural: cada palavra do bloqueio com 's' adicionado
        $palavras = explode(' ', $blNorm);
        if (count($palavras) === 0) return false;
        // Se bl é UMA palavra: testa também com 's' adicionado se não termina em 's'
        if (count($palavras) === 1) {
            $p = $palavras[0];
            if (mb_strlen($p) >= 4 && substr($p, -1) !== 's') {
                if (self::regexComBoundaries($termoNorm, $p . 's')) return true;
            }
            return false;
        }
        // Multi-palavra: tenta variar cada uma adicionando 's'
        foreach ($palavras as $idx => $p) {
            if (mb_strlen($p) < 4) continue;
            if (substr($p, -1) === 's') continue; // já é plural
            $variacao = $palavras;
            $variacao[$idx] = $p . 's';
            $candidato = implode(' ', $variacao);
            if (self::regexComBoundaries($termoNorm, $candidato)) return true;
        }
        return false;
    }

    /** Regex com \b nos extremos. Usa modificador u (UTF-8). */
    private static function regexComBoundaries(string $haystack, string $needle): bool
    {
        $escaped = preg_quote($needle, '~');
        // \b funciona com ASCII; nossa string já está sem acentos via normalizarParaCanibal
        return preg_match('~\b' . $escaped . '\b~u', $haystack) === 1;
    }

    /**
     * Sites IRMÃOS = mesma `empresa.nome`, slug diferente. Lista carregada de sites.php
     * uma vez por processo (cache estático). Se a empresa atual não estiver definida,
     * retorna [] (cross-site dedup vira no-op).
     *
     * @param array $cfg cfg do site atual (precisa ter empresa.nome E _site_slug)
     * @return string[]  slugs dos sites irmãos
     */
    private static function getSisterSites(array $cfg): array
    {
        static $catalog = null;

        $minhaEmpresa = trim((string)($cfg['empresa']['nome'] ?? ''));
        if ($minhaEmpresa === '') return [];

        $meuSlug = (string)($cfg['_site_slug'] ?? '');

        if ($catalog === null) {
            $sitesFile = __DIR__ . '/../sites.php';
            if (!is_file($sitesFile)) { $catalog = []; return []; }
            $sites = @require $sitesFile;
            $catalog = is_array($sites) ? $sites : [];
        }

        $sisters = [];
        foreach ($catalog as $slug => $siteCfg) {
            if (!is_array($siteCfg)) continue;
            if ($slug === $meuSlug) continue;
            $empresa = trim((string)($siteCfg['empresa']['nome'] ?? ''));
            if ($empresa !== '' && $empresa === $minhaEmpresa) {
                $sisters[] = (string)$slug;
            }
        }
        return $sisters;
    }
}
