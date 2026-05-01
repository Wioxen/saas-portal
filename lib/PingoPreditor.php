<?php
/**
 * PingoPreditor — detecta termos em ASCENSÃO antes de viralizarem (B4 Frente Inteligência Viral).
 *
 * O SpikeDetector hoje captura termos JÁ trending (threshold de traffic). O Preditor adiciona
 * uma camada: compara traffic atual com snapshots anteriores do mesmo termo e classifica:
 *
 *   - new       → termo nunca visto antes (sem histórico). Sem boost.
 *   - rising    → traffic atual cresceu ≥50% vs snapshot anterior (±20-120min). BOOST.
 *   - stable    → variação <50%. Sem boost (já estabilizou no zeitgeist).
 *   - declining → traffic atual <80% do snapshot anterior. Sem boost (peak já passou).
 *
 * Estratégia (baixo overhead — sem novas chamadas API):
 *   1. Cada execução do SpikeDetector já lê o feed Trends; este lib espia o resultado.
 *   2. Persiste snapshots em `data/predictor_state.json` (compacto):
 *        {"termo_lower": [{"ts": 1234, "traffic": 5000}, ...]} — limite 5 snapshots/termo.
 *   3. Calcula momentum (delta% / hora) e classifica.
 *
 * Por que importa pra Discover:
 *   - Discover premia conteúdo nos primeiros 30-60min de um pico.
 *   - "Rising" = ainda subindo, janela ABERTA pra publicar antes da concorrência.
 *   - "Stable/declining" = janela fechada; publicar gera só pity-traffic.
 *
 * Uso (no SpikeDetector ou cron próprio):
 *   $preditor = new PingoPreditor();
 *   $items = [['termo' => 'X', 'traffic' => 5000], ...];   // do feed Trends
 *   $enriquecidos = $preditor->classificar($items);        // adiciona predictor_label, momentum
 *   foreach ($enriquecidos as $it) {
 *       if ($it['predictor_label'] === 'rising') {
 *           $registro['score_discover'] = 12.0;            // boost > threshold normal
 *       }
 *   }
 */
class PingoPreditor
{
    /** Janela mínima entre snapshots pra calcular momentum (segundos). 20min default. */
    public const JANELA_MIN_S = 1200;

    /** Janela máxima — snapshot mais velho que isso é descartado. 2h default. */
    public const JANELA_MAX_S = 7200;

    /** Delta% mínimo pra classificar como rising (default 50% = +0.5x). */
    public const RISING_DELTA_PCT = 50;

    /** Delta% máximo pra classificar como declining (default -20% = traffic atual <80% anterior). */
    public const DECLINING_DELTA_PCT = -20;

    /** Limite de snapshots por termo (FIFO — drops mais antigos). */
    public const MAX_SNAPSHOTS_POR_TERMO = 5;

    /** TTL do termo no state — descartado após 24h sem aparecer. Evita inflar arquivo. */
    public const TTL_TERMO_S = 86400;

    private string $statePath;

    public function __construct(?string $statePath = null)
    {
        $this->statePath = $statePath ?? __DIR__ . '/../data/predictor_state.json';
        $dir = dirname($this->statePath);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
    }

    /**
     * Classifica items do feed Trends e atualiza state.
     *
     * @param array $items lista de [{termo, traffic, ts?}] (ts default = now)
     * @return array mesma lista enriquecida com [predictor_label, momentum_pct, snapshot_anterior]
     */
    public function classificar(array $items): array
    {
        $state = $this->loadState();
        $now = time();
        $out = [];

        foreach ($items as $it) {
            $termo = trim((string)($it['termo'] ?? ''));
            $traffic = (int)($it['traffic'] ?? 0);
            $ts = (int)($it['ts'] ?? $now);
            if ($termo === '' || $traffic <= 0) {
                $out[] = $it + ['predictor_label' => 'invalid', 'momentum_pct' => 0, 'snapshot_anterior' => null];
                continue;
            }

            $key = mb_strtolower($termo, 'UTF-8');
            $hist = $state[$key] ?? [];

            // Encontra snapshot ANTERIOR comparável (entre JANELA_MIN_S e JANELA_MAX_S atrás)
            $snapAnterior = null;
            foreach (array_reverse($hist) as $s) {
                $idade = $ts - (int)$s['ts'];
                if ($idade < self::JANELA_MIN_S) continue;       // muito recente, ignora
                if ($idade > self::JANELA_MAX_S) break;          // muito velho, ignora (e seguintes)
                $snapAnterior = $s;
                break;
            }

            if ($snapAnterior === null) {
                // Sem snapshot comparável — termo novo OU intervalo fora da janela
                $label = empty($hist) ? 'new' : 'stable';
                $momentum = 0;
            } else {
                $tAnterior = (int)$snapAnterior['traffic'];
                $delta = $tAnterior > 0
                    ? round(100 * ($traffic - $tAnterior) / $tAnterior, 1)
                    : 0;
                $momentum = $delta;
                if ($delta >= self::RISING_DELTA_PCT) {
                    $label = 'rising';
                } elseif ($delta <= self::DECLINING_DELTA_PCT) {
                    $label = 'declining';
                } else {
                    $label = 'stable';
                }
            }

            // Adiciona snapshot atual ao histórico
            $hist[] = ['ts' => $ts, 'traffic' => $traffic];
            if (count($hist) > self::MAX_SNAPSHOTS_POR_TERMO) {
                $hist = array_slice($hist, -self::MAX_SNAPSHOTS_POR_TERMO);
            }
            $state[$key] = $hist;

            $out[] = $it + [
                'predictor_label'   => $label,
                'momentum_pct'      => $momentum,
                'snapshot_anterior' => $snapAnterior ? [
                    'ts'      => $snapAnterior['ts'],
                    'traffic' => $snapAnterior['traffic'],
                    'idade_s' => $ts - $snapAnterior['ts'],
                ] : null,
            ];
        }

        // GC: remove termos não vistos em 24h
        $cutoff = $now - self::TTL_TERMO_S;
        foreach ($state as $k => $hist) {
            if (!is_array($hist) || empty($hist)) { unset($state[$k]); continue; }
            $ultimoTs = (int)end($hist)['ts'];
            if ($ultimoTs < $cutoff) unset($state[$k]);
        }

        $this->saveState($state);
        return $out;
    }

    /**
     * Resumo do state pra debug/observabilidade.
     */
    public function stats(): array
    {
        $state = $this->loadState();
        $totalTermos = count($state);
        $totalSnapshots = 0;
        foreach ($state as $hist) $totalSnapshots += count($hist);
        return [
            'termos_no_state'   => $totalTermos,
            'snapshots_total'   => $totalSnapshots,
            'state_file'        => $this->statePath,
        ];
    }

    /**
     * Boost de score sugerido pra um label. Tabela tunável.
     */
    public static function boostScoreDiscover(string $label, float $scoreBase = 10.0): float
    {
        return match ($label) {
            'rising'    => $scoreBase + 2.0,   // 12.0 — passa antes pelo Trend-Scoring Gate
            'declining' => max(0, $scoreBase - 3.0), // 7.0 — desincentiva (já passou peak)
            default     => $scoreBase,         // new/stable mantém base
        };
    }

    // ── helpers ──

    private function loadState(): array
    {
        if (!is_file($this->statePath)) return [];
        $raw = @file_get_contents($this->statePath);
        $d = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        return is_array($d) ? $d : [];
    }

    private function saveState(array $state): void
    {
        require_once __DIR__ . '/JsonStore.php';
        // Sem backup: state é descartável (auto-recupera no próximo ciclo).
        JsonStore::write($this->statePath, $state, 0, false);
    }
}
