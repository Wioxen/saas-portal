<?php
/**
 * DiscoverScoreComposto — fórmula explícita de prioridade pra Sonnet.
 *
 * Hoje cada módulo (Pingo, SpikeDetector, PingoPreditor) define `score_discover` ad-hoc.
 * O Trend-Scoring Gate compara ≥ threshold pra decidir Sonnet vs GPT-mini.
 *
 * O composite score expõe os SINAIS combinados:
 *
 *   score = freshness × multi_fonte × predictor × cluster_match × site_authority
 *
 * Onde cada fator é normalizado em [0.5, 2.0] (1.0 = neutro).
 * Score final fica em escala 0-20+ (vs 0-10 ad-hoc atual).
 *
 * Aplicação:
 *  - Pingo/SpikeDetector chamam `calcular($trendBase, $contexto)` → recebem score final
 *  - DiscoverGerador usa pra ordenar fila (publica primeiro o de maior score)
 *  - Relatório mostra qual fator está "puxando pra cima/baixo" (debug)
 *
 * Fatores:
 *
 *   1. FRESHNESS (0.5x-2.0x): 0-30min idade = 2.0; 30-2h = 1.5; 2-12h = 1.0; >24h = 0.5
 *   2. MULTI_FONTE (0.7x-1.8x): trend confirmado em 1 fonte=1.0; 2 fontes=1.4; 3+=1.8
 *   3. PREDICTOR (0.5x-1.6x): rising=1.6; new=1.0; stable=1.0; declining=0.5
 *   4. CLUSTER_MATCH (0.6x-1.4x): cluster com score detect alto=1.4; default=1.0; fraco=0.6
 *   5. SITE_AUTHORITY (0.8x-1.5x): subtipo_nicho match com termo=1.5; off-topic=0.8
 */
class DiscoverScoreComposto
{
    /**
     * Calcula score composto.
     *
     * @param array $trend  trend (precisa ao menos: termo, data_detectada, cluster_detect)
     * @param array $contexto {
     *   freshness_minutos?: int,        // override; default calcula de data_detectada
     *   fontes_confirmadas?: int,       // # de feeds que detectaram (default 1)
     *   predictor_label?: string,       // new|rising|stable|declining
     *   subtipo_nicho?: string,         // do site
     *   site?: string                   // pra logging
     * }
     * @return array {score: float, base: float, fatores: [{nome, valor, contribuicao}], ...}
     */
    public static function calcular(array $trend, array $contexto = []): array
    {
        $base = 5.0; // base neutra

        // 1. FRESHNESS
        $minutos = self::idadeEmMinutos($trend, $contexto);
        if ($minutos < 30)         $fFresh = 2.0;
        elseif ($minutos < 120)    $fFresh = 1.5;
        elseif ($minutos < 720)    $fFresh = 1.0;
        elseif ($minutos < 1440)   $fFresh = 0.8;
        else                       $fFresh = 0.5;

        // 2. MULTI_FONTE
        $fontes = (int)($contexto['fontes_confirmadas'] ?? 1);
        if ($fontes >= 3)      $fMulti = 1.8;
        elseif ($fontes === 2) $fMulti = 1.4;
        else                   $fMulti = 1.0;

        // 3. PREDICTOR
        $label = (string)($contexto['predictor_label'] ?? $trend['predictor_label'] ?? 'unknown');
        switch ($label) {
            case 'rising':    $fPred = 1.6; break;
            case 'declining': $fPred = 0.5; break;
            case 'new':
            case 'stable':    $fPred = 1.0; break;
            default:          $fPred = 1.0;
        }

        // 4. CLUSTER_MATCH
        $cScore = (int)($trend['cluster_detect']['score'] ?? 0);
        $cKey   = (string)($trend['cluster_detect']['key'] ?? '');
        if ($cScore >= 5)                                     $fCluster = 1.4;
        elseif ($cScore >= 2)                                 $fCluster = 1.0;
        elseif ($cKey === 'curiosidades_geral' && $cScore <= 1) $fCluster = 0.6;
        else                                                  $fCluster = 1.0;

        // 5. SITE_AUTHORITY (match subtipo_nicho com termo)
        $subtipo = trim((string)($contexto['subtipo_nicho'] ?? ''));
        $termo   = mb_strtolower(trim((string)($trend['termo'] ?? '')), 'UTF-8');
        if ($subtipo !== '' && $termo !== '') {
            $matchSubtipo = self::matchSubtipo($termo, $subtipo);
            if ($matchSubtipo)     $fAuth = 1.5;
            else                   $fAuth = 0.8;
        } else {
            $fAuth = 1.0;
        }

        $score = $base * $fFresh * $fMulti * $fPred * $fCluster * $fAuth;

        return [
            'score'        => round($score, 2),
            'base'         => $base,
            'fatores'      => [
                ['nome' => 'freshness',      'valor' => $fFresh,    'minutos_idade' => $minutos],
                ['nome' => 'multi_fonte',    'valor' => $fMulti,    'fontes' => $fontes],
                ['nome' => 'predictor',      'valor' => $fPred,     'label' => $label],
                ['nome' => 'cluster_match',  'valor' => $fCluster,  'cluster_score' => $cScore, 'key' => $cKey],
                ['nome' => 'site_authority', 'valor' => $fAuth,     'subtipo_nicho' => $subtipo],
            ],
            'site'         => (string)($contexto['site'] ?? ''),
            'termo_resumo' => mb_substr($termo, 0, 80),
        ];
    }

    /**
     * Wrapper conveniente: aplica composite e devolve só o número (pra usar em score_discover).
     */
    public static function calcularSimples(array $trend, array $contexto = []): float
    {
        return (float)self::calcular($trend, $contexto)['score'];
    }

    // ── helpers ──

    private static function idadeEmMinutos(array $trend, array $contexto): int
    {
        if (isset($contexto['freshness_minutos'])) return (int)$contexto['freshness_minutos'];
        $data = (string)($trend['data_detectada'] ?? $trend['publicado_em'] ?? '');
        if ($data === '') return 9999;
        $ts = strtotime($data);
        if ($ts === false) return 9999;
        return (int)max(0, floor((time() - $ts) / 60));
    }

    /**
     * Match heurístico simples: alguma palavra ≥4 chars do subtipo aparece no termo.
     * Não pretende ser perfeito — sinaliza relevância.
     */
    private static function matchSubtipo(string $termo, string $subtipo): bool
    {
        $palavras = preg_split('/[\s,;\/\-]+/u', mb_strtolower($subtipo, 'UTF-8')) ?: [];
        $palavras = array_filter($palavras, fn($p) => mb_strlen($p) >= 4);
        foreach ($palavras as $p) {
            if (mb_strpos($termo, $p) !== false) return true;
        }
        return false;
    }
}
