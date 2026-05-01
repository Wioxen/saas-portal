<?php
/**
 * CostGuard — hard cap diário pra LLM/APIs externas. Defesa contra runaway.
 *
 * Cenário que resolve: bug em loop, prompt errado que faz Sonnet escrever 100k tokens
 * por chamada, multi-key sem rate limit local. Sem cap, $200-500 podem queimar
 * em horas. Com cap, sistema PARA antes (devolve `ok=false` no DiscoverGerador).
 *
 * Lê CostTracker (já agrega Claude + Serper + OpenAI). Compara com limites do .env:
 *   COST_DAILY_LIMIT_USD                — limite GLOBAL do dia (todos os sites)
 *   COST_DAILY_LIMIT_PER_SITE_USD       — limite por site (estimativa proporcional)
 *   COST_GUARD_ENABLED                  — '0' desativa (default '1')
 *
 * Estimativa por site: como hoje logCacheStats não grava `site`, usamos proporção
 * (posts publicados hoje pelo site / total publicados hoje) × custo total. Cap por
 * site é "best effort" — protege de site rogue mas não é tão preciso quanto global.
 *
 * Uso (em DiscoverGerador antes de chamar Maquina/Claude):
 *   $g = CostGuard::verificar($siteSlug, $db);
 *   if (!$g['ok']) return ['ok' => false, 'erro' => $g['motivo']];
 */

require_once __DIR__ . '/Env.php';
require_once __DIR__ . '/CostTracker.php';

class CostGuard
{
    /** Limite global default (USD/dia) se .env não tiver. */
    public const LIMITE_GLOBAL_DEFAULT = 20.0;

    /** Limite por site default (USD/dia). */
    public const LIMITE_PER_SITE_DEFAULT = 5.0;

    /**
     * Verifica se geração pode prosseguir.
     *
     * @param string $site slug do site (cursosenac, vagasebeneficios, etc.)
     * @param object|null $db DiscoverDb (opcional — pra cap por site via proporção)
     * @return array {ok: bool, motivo: string, gasto_global: float, gasto_site: float, limite_global: float, limite_site: float}
     */
    public static function verificar(string $site, $db = null): array
    {
        @Env::load(__DIR__ . '/../.env');
        if ((string)Env::get('COST_GUARD_ENABLED', '1') === '0') {
            return self::ok('guard desativado (COST_GUARD_ENABLED=0)');
        }

        $limGlobal  = (float)Env::get('COST_DAILY_LIMIT_USD', (string)self::LIMITE_GLOBAL_DEFAULT);
        $limSite    = (float)Env::get('COST_DAILY_LIMIT_PER_SITE_USD', (string)self::LIMITE_PER_SITE_DEFAULT);

        $resumo = CostTracker::resumoDoDia();
        $gastoGlobal = (float)($resumo['total']['custo_usd'] ?? 0);

        // Cap GLOBAL primeiro — defesa principal
        if ($gastoGlobal >= $limGlobal) {
            return self::bloqueio(
                sprintf('cost cap GLOBAL atingido: $%.2f >= $%.2f', $gastoGlobal, $limGlobal),
                $gastoGlobal, 0, $limGlobal, $limSite
            );
        }

        // Cap por site (best-effort via proporção de posts hoje)
        $gastoSite = $gastoGlobal; // fallback conservador (sem $db = atribui tudo ao site)
        if (is_object($db) && $gastoGlobal > 0) {
            try {
                $todayStart = strtotime('today');
                $publicadosHoje = $db->all([
                    'status'         => 'publicado',
                    'publicado_apos' => $todayStart,
                ]);
                $totalPosts = count($publicadosHoje);
                $postsDoSite = 0;
                foreach ($publicadosHoje as $p) {
                    if (($p['site'] ?? '') === $site) $postsDoSite++;
                }
                if ($totalPosts > 0) {
                    $gastoSite = round($gastoGlobal * ($postsDoSite / $totalPosts), 4);
                }
            } catch (Throwable $e) { /* mantém fallback conservador */ }
        }

        if ($gastoSite >= $limSite) {
            return self::bloqueio(
                sprintf("cost cap do site '%s' atingido: $%.2f >= $%.2f (estimativa por proporção)",
                    $site, $gastoSite, $limSite),
                $gastoGlobal, $gastoSite, $limGlobal, $limSite
            );
        }

        return [
            'ok'            => true,
            'motivo'        => 'dentro do limite',
            'gasto_global'  => round($gastoGlobal, 4),
            'gasto_site'    => round($gastoSite, 4),
            'limite_global' => $limGlobal,
            'limite_site'   => $limSite,
        ];
    }

    private static function ok(string $motivo): array
    {
        return ['ok' => true, 'motivo' => $motivo, 'gasto_global' => 0, 'gasto_site' => 0, 'limite_global' => 0, 'limite_site' => 0];
    }

    private static function bloqueio(string $motivo, float $gG, float $gS, float $lG, float $lS): array
    {
        return [
            'ok' => false,
            'motivo' => $motivo,
            'gasto_global' => round($gG, 4),
            'gasto_site' => round($gS, 4),
            'limite_global' => $lG,
            'limite_site' => $lS,
        ];
    }
}
