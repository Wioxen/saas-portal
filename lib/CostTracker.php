<?php
/**
 * CostTracker — agrega gastos de APIs externas (LLM, Serper, Pexels, OpenAI image, etc).
 *
 * Lê 3 fontes:
 *   - data/cost_tracker/llm_calls.jsonl    (Claude::logCacheStats)
 *   - data/cost_tracker/serper_cache.jsonl (Serper hit/miss)
 *   - data/cost_tracker/openai_calls.jsonl (futuro — image gen + chat)
 *
 * Calcula:
 *   - Tokens consumidos (input/output/cache_read)
 *   - Estimativa de custo USD por API
 *   - Cache hit ratio
 *   - Savings estimados ($ economizados via cache)
 *   - Posts gerados (cross-ref com DiscoverDb)
 *
 * Tabela de preços (atualizada 2026-04 — verificar Anthropic/OpenAI/Serper antes de produção):
 *   - Claude Sonnet 4.6:    $3.00 / 1M input, $15.00 / 1M output, $0.30 / 1M cache_read
 *   - GPT-4o-mini:          $0.15 / 1M input, $0.60 / 1M output
 *   - DALL-E 3 HD 1792x1024: $0.080 / image
 *   - Serper (paid):        $0.30 / 1k queries
 *   - Pexels:               grátis (até 200 req/h)
 *
 * Uso:
 *   $stats = CostTracker::resumoDoDia();
 *   $stats = CostTracker::resumoDoMes('2026-04');
 *   $stats = CostTracker::estatsDetalhadas(['since_ts' => time() - 86400]);
 */
class CostTracker
{
    private const PATH_DEF = '/../data/cost_tracker';

    /** Preço USD por 1M tokens (Anthropic Claude Sonnet 4.6). Verificar pricing oficial. */
    public const PRICE_CLAUDE_INPUT      = 3.00;
    public const PRICE_CLAUDE_OUTPUT     = 15.00;
    public const PRICE_CLAUDE_CACHE_READ = 0.30;
    public const PRICE_CLAUDE_CACHE_CREATION = 3.75; // 25% acima do input

    /** GPT-4o-mini. */
    public const PRICE_GPT_MINI_INPUT  = 0.15;
    public const PRICE_GPT_MINI_OUTPUT = 0.60;

    /** DALL-E 3 HD por imagem (1792x1024). */
    public const PRICE_DALLE3_HD = 0.080;

    /** Serper paid: $0.30 / 1000 queries. */
    public const PRICE_SERPER = 0.30 / 1000;

    /**
     * Resumo do dia atual.
     */
    public static function resumoDoDia(): array
    {
        return self::estatsDetalhadas(['since_ts' => strtotime('today')]);
    }

    /**
     * Resumo de um mês específico (YYYY-MM).
     */
    public static function resumoDoMes(string $mes): array
    {
        $inicio = strtotime($mes . '-01 00:00:00');
        $fim    = strtotime($mes . '-01 +1 month');
        return self::estatsDetalhadas(['since_ts' => $inicio, 'until_ts' => $fim]);
    }

    /**
     * Estatísticas detalhadas com filtros.
     */
    public static function estatsDetalhadas(array $filtros = []): array
    {
        $sinceTs = (int)($filtros['since_ts'] ?? 0);
        $untilTs = (int)($filtros['until_ts'] ?? PHP_INT_MAX);
        $dir = __DIR__ . self::PATH_DEF;
        if (!is_dir($dir)) {
            return self::estatsVazias();
        }

        // 1. LLM calls (Claude)
        $llm = self::carregarJsonl($dir . '/llm_calls.jsonl', $sinceTs, $untilTs);
        $totalInput = 0;
        $totalOutput = 0;
        $totalCacheRead = 0;
        $totalCacheCreation = 0;
        $callsLlm = count($llm);
        foreach ($llm as $e) {
            $totalInput        += (int)($e['input_tokens'] ?? 0);
            $totalOutput       += (int)($e['output_tokens'] ?? 0);
            $totalCacheRead    += (int)($e['cache_read'] ?? 0);
            $totalCacheCreation+= (int)($e['cache_creation'] ?? 0);
        }
        $custoClaude = ($totalInput / 1e6) * self::PRICE_CLAUDE_INPUT
                     + ($totalOutput / 1e6) * self::PRICE_CLAUDE_OUTPUT
                     + ($totalCacheRead / 1e6) * self::PRICE_CLAUDE_CACHE_READ
                     + ($totalCacheCreation / 1e6) * self::PRICE_CLAUDE_CACHE_CREATION;
        $cacheHitRatio = ($totalInput + $totalCacheRead) > 0
            ? round($totalCacheRead / ($totalInput + $totalCacheRead), 3) : 0;
        $savingsCache = ($totalCacheRead / 1e6) * (self::PRICE_CLAUDE_INPUT - self::PRICE_CLAUDE_CACHE_READ);

        // 2. Serper
        $serper = self::carregarJsonl($dir . '/serper_cache.jsonl', $sinceTs, $untilTs);
        $serperHits = 0;
        $serperMisses = 0;
        foreach ($serper as $e) {
            if (($e['tipo'] ?? '') === 'hit')  $serperHits++;
            elseif (($e['tipo'] ?? '') === 'miss') $serperMisses++;
        }
        $custoSerper = $serperMisses * self::PRICE_SERPER;
        $savingsSerper = $serperHits * self::PRICE_SERPER;
        $serperHitRatio = ($serperHits + $serperMisses) > 0
            ? round($serperHits / ($serperHits + $serperMisses), 3) : 0;

        // 3. OpenAI calls (futuro — placeholder)
        $openai = self::carregarJsonl($dir . '/openai_calls.jsonl', $sinceTs, $untilTs);
        $custoOpenAI = 0; // calc quando wired

        $custoTotalUsd = $custoClaude + $custoSerper + $custoOpenAI;
        $savingsTotalUsd = $savingsCache + $savingsSerper;

        return [
            'periodo' => [
                'since' => $sinceTs > 0 ? date('c', $sinceTs) : null,
                'until' => $untilTs < PHP_INT_MAX ? date('c', $untilTs) : null,
            ],
            'llm' => [
                'calls'                => $callsLlm,
                'input_tokens'         => $totalInput,
                'output_tokens'        => $totalOutput,
                'cache_read_tokens'    => $totalCacheRead,
                'cache_creation_tokens'=> $totalCacheCreation,
                'cache_hit_ratio'      => $cacheHitRatio,
                'custo_usd'            => round($custoClaude, 4),
                'savings_usd_cache'    => round($savingsCache, 4),
            ],
            'serper' => [
                'hits'       => $serperHits,
                'misses'     => $serperMisses,
                'hit_ratio'  => $serperHitRatio,
                'custo_usd'  => round($custoSerper, 4),
                'savings_usd'=> round($savingsSerper, 4),
            ],
            'openai_image' => [
                'calls'     => count($openai),
                'custo_usd' => round($custoOpenAI, 4),
            ],
            'total' => [
                'custo_usd'        => round($custoTotalUsd, 2),
                'savings_usd'      => round($savingsTotalUsd, 2),
                'savings_pct'      => $custoTotalUsd > 0
                    ? round(100 * $savingsTotalUsd / ($custoTotalUsd + $savingsTotalUsd), 1)
                    : 0,
            ],
        ];
    }

    /**
     * Helper pra outros módulos logarem custos manualmente.
     */
    public static function logManual(string $api, array $detalhes): void
    {
        $dir = __DIR__ . self::PATH_DEF;
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $detalhes['ts'] = $detalhes['ts'] ?? date('c');
        $detalhes['ts_unix'] = $detalhes['ts_unix'] ?? time();
        $line = json_encode($detalhes, JSON_UNESCAPED_UNICODE);
        $file = $dir . '/' . preg_replace('/[^a-z0-9_]/', '_', strtolower($api)) . '_calls.jsonl';
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function carregarJsonl(string $file, int $sinceTs, int $untilTs): array
    {
        if (!is_file($file)) return [];
        $out = [];
        $fp = @fopen($file, 'rb');
        if (!$fp) return [];
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $e = json_decode($line, true);
            if (!is_array($e)) continue;
            $ts = isset($e['ts_unix']) ? (int)$e['ts_unix'] : (strtotime($e['ts'] ?? '') ?: 0);
            if ($ts < $sinceTs || $ts >= $untilTs) continue;
            $out[] = $e;
        }
        @fclose($fp);
        return $out;
    }

    private static function estatsVazias(): array
    {
        return [
            'periodo' => [],
            'llm'     => ['calls' => 0, 'custo_usd' => 0, 'cache_hit_ratio' => 0],
            'serper'  => ['hits' => 0, 'misses' => 0, 'hit_ratio' => 0, 'custo_usd' => 0],
            'openai_image' => ['calls' => 0, 'custo_usd' => 0],
            'total'   => ['custo_usd' => 0, 'savings_usd' => 0],
        ];
    }
}
