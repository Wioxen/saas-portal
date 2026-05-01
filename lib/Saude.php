<?php
/**
 * Saude — health check do pipeline (lib testável; saude.php é só HTTP shim).
 *
 * Verifica:
 *   1. App alive (PHP rodando, sites.php parseia, DB lê)
 *   2. Circuits (anthropic / openai / openai_image)
 *   3. Locks (algum stale há >1h = cron travado)
 *   4. Disk usage em data/
 *   5. Pingo activity (data/pingo_state.json modificado nos últimos 30min)
 *   6. WP REST per site (opcional, lento — flag `incluirWp=true`)
 *
 * Severidades:
 *   - ok       → tudo verde
 *   - warning  → degradação parcial (1 LLM aberto, disco 85%, pingo silencioso)
 *   - error    → catastrófico (db illegível, disco 95%, ambos LLMs abertos)
 */

class Saude
{
    /**
     * @param bool $detalhado retorna `checks` completo (vs `summary` mínimo)
     * @param bool $incluirWp pinga cada wp_url (lento — só pra debug)
     * @return array {ok, severidade, timestamp, checks/summary}
     */
    public static function checar(bool $detalhado = false, bool $incluirWp = false): array
    {
        $ROOT = __DIR__ . '/..';
        $checks = [];
        $severidade = 'ok';

        $eleva = function (string $nivel) use (&$severidade) {
            $ranking = ['ok' => 0, 'warning' => 1, 'error' => 2];
            if (($ranking[$nivel] ?? 0) > ($ranking[$severidade] ?? 0)) {
                $severidade = $nivel;
            }
        };

        // ─── 1. App alive ───
        $checks['app'] = ['ok' => true, 'php' => PHP_VERSION];

        // ─── 2. DB ───
        try {
            require_once __DIR__ . '/DiscoverDb.php';
            $db = new DiscoverDb();
            $total = count($db->all());
            $checks['db'] = ['ok' => true, 'total_trends' => $total];
        } catch (Throwable $e) {
            $checks['db'] = ['ok' => false, 'erro' => $detalhado ? $e->getMessage() : 'falha leitura'];
            $eleva('error');
        }

        // ─── 3. Sites ───
        $sites = null;
        try {
            $sites = @require $ROOT . '/sites.php';
            if (!is_array($sites)) throw new RuntimeException('sites.php não retornou array');
            $checks['sites'] = ['ok' => true, 'count' => count($sites)];
        } catch (Throwable $e) {
            $checks['sites'] = ['ok' => false, 'erro' => $detalhado ? $e->getMessage() : 'falha sites.php'];
            $eleva('error');
        }

        // ─── 4. Circuits ───
        $circuitsCheck = ['ok' => true, 'estados' => []];
        try {
            require_once __DIR__ . '/CircuitBreaker.php';
            $estados = [];
            foreach (['anthropic', 'openai', 'openai_image'] as $cn) {
                $cb = new CircuitBreaker($cn);
                $st = $cb->status();
                $estados[$cn] = $st['estado'];
                $circuitsCheck['estados'][$cn] = $detalhado
                    ? ['estado' => $st['estado'], 'falhas' => $st['falhas_recentes'], 'reabre_em_s' => $st['reabre_em_s']]
                    : ['estado' => $st['estado']];
                if ($st['estado'] === CircuitBreaker::ESTADO_OPEN) {
                    $circuitsCheck['ok'] = false;
                    $eleva('warning');
                }
            }
            // Se ambos LLM principais abertos → error crítico
            if (($estados['anthropic'] ?? '') === CircuitBreaker::ESTADO_OPEN
                && ($estados['openai'] ?? '') === CircuitBreaker::ESTADO_OPEN) {
                $eleva('error');
            }
        } catch (Throwable $e) {
            $circuitsCheck = ['ok' => false, 'erro' => $detalhado ? $e->getMessage() : 'falha'];
            $eleva('warning');
        }
        $checks['circuits'] = $circuitsCheck;

        // ─── 5. Locks (stale = cron travado) ───
        $locksCheck = ['ok' => true, 'stale' => []];
        $locksDir = $ROOT . '/data/locks';
        if (is_dir($locksDir)) {
            foreach (glob($locksDir . '/*.lock') as $lockFile) {
                $age = time() - (int)@filemtime($lockFile);
                if ($age < 3600) continue;
                $metaFile = $lockFile . '.meta';
                $hbAge = $age;
                if (is_file($metaFile)) {
                    $meta = json_decode((string)@file_get_contents($metaFile), true);
                    if (isset($meta['heartbeat_at'])) {
                        $hbTs = strtotime($meta['heartbeat_at']);
                        if ($hbTs) $hbAge = time() - $hbTs;
                    }
                }
                if ($hbAge > 3600) {
                    $locksCheck['ok'] = false;
                    $nome = pathinfo($lockFile, PATHINFO_FILENAME);
                    $locksCheck['stale'][] = $detalhado
                        ? ['nome' => $nome, 'age_h' => round($hbAge / 3600, 1)]
                        : $nome;
                }
            }
            if (!$locksCheck['ok']) $eleva('warning');
        }
        $checks['locks'] = $locksCheck;

        // ─── 6. Disco ───
        $diskCheck = ['ok' => true];
        $dataDir = $ROOT . '/data';
        $total = @disk_total_space($dataDir);
        $free  = @disk_free_space($dataDir);
        if ($total && $free && $total > 0) {
            $usedPct = (int)round(100 * ($total - $free) / $total);
            $diskCheck['used_pct'] = $usedPct;
            if ($detalhado) {
                $diskCheck['total_gb'] = round($total / 1024 / 1024 / 1024, 1);
                $diskCheck['free_gb']  = round($free / 1024 / 1024 / 1024, 1);
            }
            if ($usedPct >= 95) {
                $diskCheck['ok'] = false;
                $eleva('error');
            } elseif ($usedPct >= 85) {
                $diskCheck['ok'] = false;
                $eleva('warning');
            }
        } else {
            $diskCheck = ['ok' => true, 'nota' => 'disk_space não suportado'];
        }
        $checks['disk'] = $diskCheck;

        // ─── 7. Pingo activity ───
        $pingoCheck = ['ok' => true];
        $pingoState = $ROOT . '/data/pingo_state.json';
        if (is_file($pingoState)) {
            $age = time() - (int)@filemtime($pingoState);
            $pingoCheck['idade_min'] = (int)round($age / 60);
            if ($age > 1800) {
                $pingoCheck['ok'] = false;
                $eleva('warning');
            }
        } else {
            $pingoCheck = ['ok' => true, 'nota' => 'sem state ainda'];
        }
        $checks['pingo'] = $pingoCheck;

        // ─── 8. WP REST per site (opcional) ───
        if ($incluirWp && is_array($sites)) {
            $wpCheck = ['ok' => true, 'sites' => []];
            foreach ($sites as $slug => $cfg) {
                $url = rtrim((string)($cfg['wp_url'] ?? ''), '/') . '/wp-json/';
                if ($url === '/wp-json/') continue;
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_NOBODY         => true,
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                ]);
                @curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $tempo = (int)round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
                curl_close($ch);
                $okSite = $code >= 200 && $code < 400;
                $wpCheck['sites'][$slug] = ['ok' => $okSite, 'http' => $code, 'ms' => $tempo];
                if (!$okSite) $wpCheck['ok'] = false;
            }
            if (!$wpCheck['ok']) $eleva('warning');
            $checks['wp'] = $wpCheck;
        }

        $resposta = [
            'ok'         => $severidade !== 'error',
            'severidade' => $severidade,
            'timestamp'  => date('c'),
        ];
        if ($detalhado) {
            $resposta['checks'] = $checks;
        } else {
            $resposta['summary'] = [
                'db'       => $checks['db']['ok'] ?? false,
                'circuits' => $checks['circuits']['ok'] ?? false,
                'locks'    => $checks['locks']['ok'] ?? false,
                'disk'     => $checks['disk']['ok'] ?? false,
                'pingo'    => $checks['pingo']['ok'] ?? true,
                'sites'    => $checks['sites']['count'] ?? 0,
            ];
        }
        return $resposta;
    }

    /**
     * Estatísticas de custo (LLM, Serper, OpenAI image). Usado por saude.php?stats=1.
     */
    public static function stats(): array
    {
        require_once __DIR__ . '/CostTracker.php';
        try {
            $hoje = CostTracker::resumoDoDia();
            $mes  = CostTracker::resumoDoMes(date('Y-m'));
            $mesAnterior = CostTracker::resumoDoMes(date('Y-m', strtotime('-1 month')));
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => $e->getMessage()];
        }
        return [
            'ok'             => true,
            'hoje'           => $hoje,
            'mes_atual'      => $mes,
            'mes_anterior'   => $mesAnterior,
            'gerado_em'      => date('c'),
        ];
    }
}
