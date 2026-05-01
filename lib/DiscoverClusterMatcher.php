<?php
/**
 * Classifica o trend em 1 dos 13 clusters editoriais.
 *
 * FONTE DE DADOS: lib/TrendsTaxonomia.php (single source of truth).
 * Aqui fica APENAS a lógica de detecção, prompting e validação — os arrays
 * de compliance/keywords/etc vivem na taxonomia.
 *
 * Uso:
 *   $c = DiscoverClusterMatcher::detectar($trend);
 *   $prompt .= DiscoverClusterMatcher::instrucaoProPrompt($c);
 *   $issues = DiscoverClusterMatcher::validarCompliance($html, $c);
 */
require_once __DIR__ . '/TrendsTaxonomia.php';

class DiscoverClusterMatcher
{
    /**
     * Memo por sinatura de trend. Evita re-executar ~90 regexes × 13 clusters quando
     * o mesmo trend é classificado 3× por render (DiscoverScore, DiscoverAngulo, Sinais).
     * Chave: md5(termo + cats ordenadas + primeiros 20 relacionados).
     */
    private static array $cacheDetect = [];

    /** Útil em testes — esvazia o memo. */
    public static function limparCache(): void
    {
        self::$cacheDetect = [];
    }

    /**
     * Retorna termos semanticamente relacionados por cluster — usados pelo
     * DiscoverInternalLinks pra buscar artigos da MESMA família temática.
     */
    public static function termosSemanticos(string $clusterKey): array
    {
        return (array)TrendsTaxonomia::campo($clusterKey, 'termos_semanticos', []);
    }

    /**
     * Detecta o cluster mais provável para o trend atual.
     * Estratégia: cruza categoria_ids (peso baixo, Google mislabeliza) + keyword match
     * no termo (peso alto) e nos relacionados (peso médio). Memo ativo por request.
     */
    public static function detectar(array $trend): array
    {
        $catIds = array_map('intval', (array)($trend['categoria_ids'] ?? []));
        $termo  = mb_strtolower((string)($trend['termo'] ?? ''), 'UTF-8');

        // Cache key: termo + cats ordenadas + primeiros 20 relacionados.
        $relKey = '';
        if (!empty($trend['relacionados']) && is_array($trend['relacionados'])) {
            $relKey = implode('|', array_slice($trend['relacionados'], 0, 20));
        }
        $catsKey = $catIds;
        sort($catsKey);
        $ckey = md5($termo . '::' . implode(',', $catsKey) . '::' . $relKey);
        if (isset(self::$cacheDetect[$ckey])) {
            return self::$cacheDetect[$ckey];
        }

        // Relacionados: sinal mais confiável que categoria_ids do Google.
        $relacionados = '';
        if (!empty($trend['relacionados']) && is_array($trend['relacionados'])) {
            $relacionados = ' ' . mb_strtolower(
                implode(' ', array_slice($trend['relacionados'], 0, 20)),
                'UTF-8'
            );
        }

        $scores = [];
        foreach (TrendsTaxonomia::todos() as $key => $def) {
            $s = 0;
            // Categoria_id: peso baixo porque o Google mislabeliza E porque feeds genéricos
            //               (Google News Geral) carregam 5 cat_ids — somariam +10 e dominariam
            //               keyword match clara no conteúdo. Cap em +3 total (max 3 ids).
            $hitsCatId = 0;
            foreach ($catIds as $cid) {
                if (in_array($cid, $def['categoria_ids'] ?? [], true)) $hitsCatId++;
            }
            $s += min(3, $hitsCatId);
            // Keyword: termo direto = forte (7 + 2/token), relacionados = médio (3 + 1/token).
            // Subi de 5→7 termo e 2→3 relac pra 1 match no termo dominar 3 cat_ids do feed.
            foreach ($def['keywords_match'] ?? [] as $kw) {
                $kwNorm  = mb_strtolower($kw, 'UTF-8');
                $pattern = '/\b' . preg_quote($kwNorm, '/') . '\b/iu';
                $tokens  = max(1, count(preg_split('/\s+/u', trim($kwNorm))));
                if (preg_match($pattern, $termo)) {
                    $s += 7 + (($tokens - 1) * 2);
                } elseif ($relacionados !== '' && preg_match($pattern, $relacionados)) {
                    $s += 3 + (($tokens - 1) * 1);
                }
            }
            $scores[$key] = $s;
        }

        // Em empate de score, preferir cluster específico sobre catch-alls
        // (noticias_info_critica e curiosidades_geral são genéricos demais — perdem desempate).
        arsort($scores);
        $catchAll = ['noticias_info_critica', 'curiosidades_geral'];
        $top = array_key_first($scores);
        if ($top !== null && in_array($top, $catchAll, true)) {
            $maxScore = $scores[$top];
            foreach ($scores as $key => $s) {
                if ($s !== $maxScore) break; // arsort já ordenou — só interessa empate no topo
                if (!in_array($key, $catchAll, true)) {
                    $top = $key;
                    break;
                }
            }
        }
        if (!$top || $scores[$top] === 0) {
            $top = 'curiosidades_geral';
        }
        $def = TrendsTaxonomia::cluster($top);
        $def['key'] = $top;
        $def['score_detect'] = $scores[$top];
        self::$cacheDetect[$ckey] = $def;
        return $def;
    }

    /** Monta a instrução cluster-específica pra plugar no prompt do LLM. */
    public static function instrucaoProPrompt(array $cluster): string
    {
        $compliance = '';
        foreach ($cluster['compliance'] ?? [] as $c) $compliance .= "- {$c}\n";

        $angulos = '';
        foreach ($cluster['angulos'] ?? [] as $a) $angulos .= "  • {$a}\n";

        $proibidos = '';
        foreach ($cluster['termos_proibidos'] ?? [] as $p) $proibidos .= "  ✗ {$p['erro']}\n";

        $incl = '';
        if (!empty($cluster['inclusao_obrigatoria'])) {
            foreach ($cluster['inclusao_obrigatoria'] as $i) $incl .= "  ✓ \"{$i}\"\n";
        }

        return "\n═══ CLUSTER EDITORIAL: {$cluster['nome']} ═══\n"
             . "PERSONA DA IA: {$cluster['persona']}.\n"
             . "GATILHO DOMINANTE: {$cluster['gatilho']}.\n\n"
             . "COMPLIANCE OBRIGATÓRIO DESTE NICHO:\n{$compliance}\n"
             . "ÂNGULOS MODELARES (use 1 como base estrutural, adapte com dados específicos):\n{$angulos}\n"
             . ($proibidos !== '' ? "PROIBIÇÕES ESPECÍFICAS DESTE CLUSTER:\n{$proibidos}\n" : '')
             . ($incl !== ''      ? "INCLUSÃO OBRIGATÓRIA NO ARTIGO:\n{$incl}\n" : '')
             . "═══ FIM CLUSTER ═══\n";
    }

    /**
     * Valida o HTML final contra as regras de compliance específicas do cluster.
     * Retorna lista de violações detectadas.
     */
    public static function validarCompliance(string $html, array $cluster): array
    {
        $issues = [];
        $texto = strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        foreach ($cluster['termos_proibidos'] ?? [] as $p) {
            if (preg_match($p['pattern'], $texto, $m)) {
                $issues[] = [
                    'tipo'   => 'termo_proibido',
                    'cluster'=> $cluster['nome'],
                    'erro'   => $p['erro'],
                    'amostra'=> trim($m[0]),
                ];
            }
        }

        foreach ($cluster['inclusao_obrigatoria'] ?? [] as $obrig) {
            if (mb_stripos($texto, $obrig) === false) {
                $variacoes = [
                    'Este conteúdo é informativo e não substitui consulta médica' => [
                        'não substitui consulta médica',
                        'não substitui orientação médica',
                        'procure um profissional de saúde',
                        'consulte seu médico',
                    ],
                ];
                $ok = false;
                foreach (($variacoes[$obrig] ?? []) as $v) {
                    if (mb_stripos($texto, $v) !== false) { $ok = true; break; }
                }
                if (!$ok) {
                    $issues[] = [
                        'tipo'    => 'faltou_inclusao',
                        'cluster' => $cluster['nome'],
                        'erro'    => 'Inclusão obrigatória ausente',
                        'amostra' => $obrig,
                    ];
                }
            }
        }

        return $issues;
    }
}
