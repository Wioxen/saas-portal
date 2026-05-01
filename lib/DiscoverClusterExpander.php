<?php
/**
 * DiscoverClusterExpander — expande 1 trend em N posts cobrindo silo completo.
 *
 * Hoje: trend "ENEM 2026" → 1 post.
 * Com expansão: trend "ENEM 2026" → 1 post-mãe + 3-5 posts-filho cobrindo:
 *   - ENEM 2026 isenção (autocomplete real)
 *   - ENEM 2026 cronograma
 *   - ENEM 2026 prazo inscrição
 *   - ENEM 2026 melhores cursinhos
 *
 * Resultado: site cobre o cluster INTEIRO (autoridade tópica) em vez de 1 post solto.
 *
 * Estratégia:
 *   1. Pega CTR Intel (autocomplete + related)
 *   2. Filtra variações que são SUFICIENTEMENTE diferentes do termo-mãe (>30% diff)
 *   3. Cria trends pre-aprovadas no DB com origem='cluster_expander:{termo_mae}'
 *   4. score_discover ligeiramente menor que mãe (mãe publica 1º, filhos depois)
 *   5. Idempotente: skipa se já tem post nosso pra essa variação
 *
 * Quando dispara:
 *   - Cron diário (silencioso) — pra trends de score alto que ainda não foram expandidos
 *   - Manual: php scripts/cluster_expander.php --termo="ENEM 2026"
 *
 * Trade-off: mais Sonnet calls. Em volume real (100/dia), expansion adiciona ~30-40%.
 * Mas autoridade tópica vale (Discover/Search valoriza site DEPTH > breadth).
 */

require_once __DIR__ . '/DiscoverDb.php';
require_once __DIR__ . '/DiscoverCtrIntel.php';

class DiscoverClusterExpander
{
    public const MAX_FILHOS = 5;
    /** Score do filho (mãe = score original — filho expansion entra numa fila secundária). */
    public const SCORE_FILHO = 6.5;

    /**
     * Expande trend-mãe em N filhos.
     *
     * @param array $trendMae trend completo do DB (termo, site, cluster_detect, score_discover)
     * @param object $serper instância Serper (pra CTR Intel)
     * @param object $db DiscoverDb
     * @param array $opts {max_filhos, dry_run}
     * @return array {filhos_criados, ja_existiam, detalhes}
     */
    public static function expandir(array $trendMae, $serper, $db, array $opts = []): array
    {
        $maxFilhos = (int)($opts['max_filhos'] ?? self::MAX_FILHOS);
        $dryRun    = !empty($opts['dry_run']);

        $termoMae = trim((string)($trendMae['termo'] ?? ''));
        $siteMae  = (string)($trendMae['site'] ?? '');
        $clusterKey = (string)($trendMae['cluster_detect']['key'] ?? '');
        if ($termoMae === '' || $siteMae === '') {
            return ['filhos_criados' => 0, 'ja_existiam' => 0, 'detalhes' => [], 'erro' => 'trend incompleto'];
        }

        // 1. Pega CTR intel
        $intel = DiscoverCtrIntel::obter($termoMae, $serper);
        $variacoes = array_unique(array_merge(
            $intel['autocomplete'] ?? [],
            $intel['related'] ?? []
        ));
        if (empty($variacoes)) {
            return ['filhos_criados' => 0, 'ja_existiam' => 0, 'detalhes' => [], 'nota' => 'sem variações no SERP'];
        }

        // 2. Filtra: tem que ser suficientemente diferente do termo-mãe (similar < 75%)
        $candidatos = [];
        $termoMaeLow = mb_strtolower($termoMae, 'UTF-8');
        foreach ($variacoes as $v) {
            $vLow = mb_strtolower(trim($v), 'UTF-8');
            if ($vLow === '' || $vLow === $termoMaeLow) continue;
            similar_text($termoMaeLow, $vLow, $sim);
            if ($sim >= 75) continue; // muito parecido — vai canibalizar
            if ($sim < 25) continue;  // off-topic — não é silo
            $candidatos[] = ['termo' => $v, 'sim' => round($sim, 1)];
        }
        // Dedupe por similaridade entre candidatos (evita "X 2026" e "X 2026 inscrição" — pega só 1)
        $unique = [];
        foreach ($candidatos as $c) {
            $manter = true;
            foreach ($unique as $u) {
                similar_text(mb_strtolower($c['termo'], 'UTF-8'), mb_strtolower($u['termo'], 'UTF-8'), $simX);
                if ($simX >= 70) { $manter = false; break; }
            }
            if ($manter) $unique[] = $c;
            if (count($unique) >= $maxFilhos) break;
        }

        if (empty($unique)) {
            return ['filhos_criados' => 0, 'ja_existiam' => 0, 'detalhes' => [], 'nota' => 'sem variações suficientemente distintas'];
        }

        // 3. Verifica posts existentes (mesma site) — skipa se já temos
        $publicados = $db->all(['site' => $siteMae]);
        $termosExistentes = [];
        foreach ($publicados as $p) {
            $termosExistentes[mb_strtolower((string)($p['termo'] ?? ''), 'UTF-8')] = true;
        }

        $criados = 0;
        $jaExistiam = 0;
        $detalhes = [];

        foreach ($unique as $c) {
            $termoFilho = $c['termo'];
            $key = mb_strtolower($termoFilho, 'UTF-8');
            if (isset($termosExistentes[$key])) {
                $jaExistiam++;
                $detalhes[] = ['termo' => $termoFilho, 'pulado' => 'já existe'];
                continue;
            }

            if ($dryRun) {
                $criados++;
                $detalhes[] = ['termo' => $termoFilho, 'sim_mae' => $c['sim'], 'dry' => true];
                continue;
            }

            try {
                $db->upsert([
                    'site'           => $siteMae,
                    'termo'          => $termoFilho,
                    'status'         => 'aprovado',
                    'score_discover' => self::SCORE_FILHO,
                    'origem'         => 'cluster_expander:' . mb_substr($termoMae, 0, 80),
                    'categoria'      => 'Silo · ' . $termoMae,
                    'cluster_detect' => ['key' => $clusterKey, 'nome' => $clusterKey, 'score' => 4],
                    'data_detectada' => date('Y-m-d H:i:s'),
                    'angulo'         => "Filho de '{$termoMae}' — {$c['sim']}% sim",
                    'evento_fonte'   => 'silo_' . sha1($termoMae),
                ]);
                $criados++;
                $detalhes[] = ['termo' => $termoFilho, 'criado' => true];
            } catch (Throwable $e) {
                $detalhes[] = ['termo' => $termoFilho, 'erro' => $e->getMessage()];
            }
        }

        return [
            'filhos_criados' => $criados,
            'ja_existiam'    => $jaExistiam,
            'detalhes'       => $detalhes,
            'termo_mae'      => $termoMae,
            'site'           => $siteMae,
        ];
    }
}
