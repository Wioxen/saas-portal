<?php
/**
 * DiscoverRPM — calcula score de arbitragem por trend (RPM estimado × dor × qualidade).
 *
 * Baseado no framework Gemini (g4): nem todo trend vale o mesmo. Fofoca tem CTR alto
 * mas RPM R$ 5. Cartão de crédito tem CTR menor mas RPM R$ 45. Focar nos trends de
 * MAIOR POTENCIAL LUCRATIVO, não de maior volume.
 *
 * Uso:
 *   $r = DiscoverRPM::calcular([
 *     'cluster_key' => 'negocios_financas',
 *     'pain'        => DiscoverPainClassifier::classificar('IR 2026 multa'),
 *     'score_discover' => 8.5,  // opcional
 *   ]);
 *   // => [
 *   //   'rpm_base' => 40,
 *   //   'dor_boost' => 1.3,
 *   //   'rpm_ajustado' => 52,
 *   //   'arbitragem_score' => 87.5,  // 0-100
 *   //   'ranking' => 'alto',         // alto | medio | baixo
 *   //   'potencial_mensal' => 'R$ 6k-18k',
 *   //   'label' => '💎 Alto',
 *   //   'emoji' => '💎',
 *   // ]
 */
require_once __DIR__ . '/TrendsTaxonomia.php';

class DiscoverRPM
{
    /** Limites pra classificação por ranking (alinhados com TrendsTaxonomia). */
    private const LIMIAR_ALTO  = TrendsTaxonomia::RANKING_ALTO;   // 70
    private const LIMIAR_MEDIO = TrendsTaxonomia::RANKING_MEDIO;  // 40

    /**
     * Calcula o score de arbitragem e retorna diagnóstico completo.
     *
     * @param array $trend ['cluster_key' => string, 'pain' => array, 'score_discover' => float|null]
     * @return array
     */
    public static function calcular(array $trend): array
    {
        $clusterKey = (string)($trend['cluster_key'] ?? '');
        $pain       = is_array($trend['pain'] ?? null) ? $trend['pain'] : ['dominante' => 'nenhuma', 'peso_total' => 0];
        $quality    = isset($trend['score_discover']) ? (float)$trend['score_discover'] : null;

        $rpmBase   = TrendsTaxonomia::rpm($clusterKey ?: 'curiosidades_geral');
        $dominante = $pain['dominante'] ?? 'nenhuma';
        $boost     = TrendsTaxonomia::DOR_BOOST[$dominante] ?? 1.0;

        // Se a dor tem peso baixo (< 3), suaviza o boost pra evitar inflar scores sem base real
        if (($pain['peso_total'] ?? 0) < 3) {
            $boost = 1.0 + (($boost - 1.0) * 0.3);
        }

        $rpmAjustado = round($rpmBase * $boost, 1);

        // Score de arbitragem 0-100:
        //   - 50% vem do RPM ajustado (normalizado pra max R$ 55)
        //   - 30% do score de qualidade do Discover (se fornecido)
        //   - 20% da intensidade da dor (0-10 de peso_total)
        $rpmScore     = min(100, ($rpmAjustado / 55) * 100);
        $qualityScore = $quality !== null ? min(100, $quality * 10) : 70; // fallback 70 se não tem
        $painScore    = min(100, ($pain['peso_total'] ?? 0) * 10);

        $arbitragem = round(
            ($rpmScore * 0.50) + ($qualityScore * 0.30) + ($painScore * 0.20),
            1
        );

        // Ranking + emoji + potencial mensal estimado
        if ($arbitragem >= self::LIMIAR_ALTO) {
            $ranking  = 'alto';
            $emoji    = '💎';
            $potencial = self::estimarPotencial($rpmAjustado, 'alto');
        } elseif ($arbitragem >= self::LIMIAR_MEDIO) {
            $ranking  = 'medio';
            $emoji    = '⭐';
            $potencial = self::estimarPotencial($rpmAjustado, 'medio');
        } else {
            $ranking  = 'baixo';
            $emoji    = '⚪';
            $potencial = self::estimarPotencial($rpmAjustado, 'baixo');
        }

        return [
            'rpm_base'          => $rpmBase,
            'dor_boost'         => $boost,
            'rpm_ajustado'      => $rpmAjustado,
            'arbitragem_score'  => $arbitragem,
            'ranking'           => $ranking,
            'potencial_mensal'  => $potencial,
            'emoji'             => $emoji,
            'label'             => self::labelRanking($ranking),
            'componentes'       => [
                'rpm'     => round($rpmScore, 1),
                'quality' => round($qualityScore, 1),
                'pain'    => round($painScore, 1),
            ],
        ];
    }

    /**
     * Estima faixa de receita mensal se o artigo hitar (volume variável).
     * Baseado em: mil views/mês × RPM (conservador), 20k/mês (médio), 80k/mês (pico viral).
     */
    private static function estimarPotencial(float $rpmAjustado, string $ranking): string
    {
        switch ($ranking) {
            case 'alto':
                $min = round(($rpmAjustado * 20));      // 20k views × RPM
                $max = round(($rpmAjustado * 80));      // 80k views × RPM (pico)
                break;
            case 'medio':
                $min = round(($rpmAjustado * 8));
                $max = round(($rpmAjustado * 30));
                break;
            default:
                $min = round(($rpmAjustado * 2));
                $max = round(($rpmAjustado * 10));
        }
        return 'R$ ' . number_format($min, 0, ',', '.') . '–' . number_format($max, 0, ',', '.');
    }

    private static function labelRanking(string $ranking): string
    {
        return ['alto' => 'Alto', 'medio' => 'Médio', 'baixo' => 'Baixo'][$ranking] ?? 'Baixo';
    }

    /**
     * Útil pra sort de lista de trends — maior arbitragem primeiro.
     * Uso: usort($trends, [DiscoverRPM::class, 'compararTrends']);
     */
    public static function compararTrends(array $a, array $b): int
    {
        $aSc = $a['arbitragem_score'] ?? self::calcular($a)['arbitragem_score'];
        $bSc = $b['arbitragem_score'] ?? self::calcular($b)['arbitragem_score'];
        return $bSc <=> $aSc;
    }

    /** Retorna tabela de RPM (útil pra UI/admin). */
    public static function tabelaRPM(): array
    {
        $out = [];
        foreach (TrendsTaxonomia::chaves() as $k) {
            $out[$k] = TrendsTaxonomia::rpm($k);
        }
        return $out;
    }
}
