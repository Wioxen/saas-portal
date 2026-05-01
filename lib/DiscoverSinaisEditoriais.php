<?php
/**
 * DiscoverSinaisEditoriais — helper único pra computar {pain, cluster_detect, arbitragem}.
 *
 * Antes: cada caller (portal.php × 2 tabelas, DiscoverGerador, DiscoverGeradorGPT, DiscoverReviewer,
 * script de backfill) repetia o mesmo trio de 3 chamadas com micro-variações. Agora:
 *
 *   $sinais = DiscoverSinaisEditoriais::calcular($trend);
 *   // => [
 *   //   'pain'           => [...],  // DiscoverPainClassifier::classificar
 *   //   'cluster_detect' => [...],  // DiscoverClusterMatcher::detectar
 *   //   'arbitragem'     => [...],  // DiscoverRPM::calcular
 *   // ]
 *
 * Entrada aceita vários formatos de trend — tanto o formato "em memória" (scrapper:
 * termo, score, categoria_ids, categorias) quanto o formato persistido em DiscoverDb
 * (termo, score_discover, categoria_ids, categoria).
 */

require_once __DIR__ . '/DiscoverPainClassifier.php';
require_once __DIR__ . '/DiscoverClusterMatcher.php';
require_once __DIR__ . '/DiscoverRPM.php';

class DiscoverSinaisEditoriais
{
    /**
     * Calcula os 3 sinais editoriais pra um trend.
     *
     * @param array       $trend         trend object (memória ou persistido)
     * @param string|null $contextoExtra texto adicional pra pain classifier (ex: frase do gancho)
     * @return array{pain:array, cluster_detect:array, arbitragem:array}
     */
    public static function calcular(array $trend, ?string $contextoExtra = null): array
    {
        $termo = (string)($trend['termo'] ?? '');

        $pain = DiscoverPainClassifier::classificar($termo, (string)($contextoExtra ?? ''));

        $cluster = DiscoverClusterMatcher::detectar([
            'termo'         => $termo,
            'categoria_ids' => $trend['categoria_ids'] ?? [],
            'categorias'    => $trend['categorias'] ?? [],
            'relacionados'  => $trend['relacionados'] ?? [],
        ]);

        // score_discover (persistido) OU score (em memória, do scraper)
        $quality = $trend['score_discover'] ?? $trend['score'] ?? null;

        $arbitragem = DiscoverRPM::calcular([
            'cluster_key'    => $cluster['key'] ?? '',
            'pain'           => $pain,
            'score_discover' => $quality,
        ]);

        return [
            'pain'           => $pain,
            'cluster_detect' => $cluster,
            'arbitragem'     => $arbitragem,
        ];
    }

    /**
     * Enriquece um trend IN-PLACE com os 3 campos, preservando tudo que já existe.
     * Útil pra popular $trends antes de renderizar ou antes de persistir em DiscoverDb.
     *
     * @param array &$trend trend modificado por referência
     * @return void
     */
    public static function enriquecer(array &$trend, ?string $contextoExtra = null): void
    {
        $sinais = self::calcular($trend, $contextoExtra);
        $trend['pain']           = $sinais['pain'];
        $trend['cluster_detect'] = $sinais['cluster_detect'];
        $trend['arbitragem']     = $sinais['arbitragem'];
    }

    /**
     * Lê sinais de um trend com fallback: se já persistidos, usa. Senão, calcula.
     * Não modifica o trend (leitura read-only pra UI).
     *
     * @param array $trend
     * @return array{pain:array, cluster_detect:array, arbitragem:array}
     */
    public static function ler(array $trend): array
    {
        if (isset($trend['pain'], $trend['cluster_detect'], $trend['arbitragem'])
            && is_array($trend['pain']) && is_array($trend['cluster_detect']) && is_array($trend['arbitragem'])) {
            return [
                'pain'           => $trend['pain'],
                'cluster_detect' => $trend['cluster_detect'],
                'arbitragem'     => $trend['arbitragem'],
            ];
        }
        return self::calcular($trend);
    }
}
