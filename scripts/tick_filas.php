<?php
/**
 * Cron-runner da fila de geração — destrava produção 24/7 sem depender de aba aberta.
 *
 * Replica o handler ?ajax=fila_tick (portal.php:559-621) em CLI, iterando todos os
 * sites com fila ativa em data/fila/<slug>.json. Idempotente. Lock global anti-sobreposição.
 *
 * Uso:
 *   php scripts/tick_filas.php               → 1 item por site (com fila pendente)
 *   php scripts/tick_filas.php --max=1       → no máximo 1 item nesta execução
 *   php scripts/tick_filas.php --site=X      → força um site específico
 *   php scripts/tick_filas.php --dry-run     → só mostra o que faria (não processa)
 *   php scripts/tick_filas.php --quiet       → sem stdout (logs vão pra arquivo)
 *
 * Logs: data/fila/log_tick.log (append-only).
 *
 * Agendamento Windows (Task Scheduler):
 *   - Nome: Clonais Tick Filas
 *   - Trigger: a cada 2 minutos, indefinidamente
 *   - Ação: C:\xampp\php\php.exe C:\xampp\htdocs\apiclaudephp\scripts\tick_filas.php --quiet
 *   - Settings: "Do not start a new instance if already running" (defesa em camadas — lock global já cobre)
 */

set_time_limit(0);
ini_set('memory_limit', '512M');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$ROOT = dirname(__DIR__);

// ── parse args ──
$dryRun    = false;
$quiet     = false;
$forceSite = null;
$maxItems  = 0; // 0 = sem limite (1 por site)
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')              $dryRun = true;
    elseif ($arg === '--quiet')            $quiet = true;
    elseif (str_starts_with($arg, '--site=')) $forceSite = substr($arg, 7);
    elseif (str_starts_with($arg, '--max=')) $maxItems = (int)substr($arg, 6);
}

// ── bootstrap (mesma lista de requires do portal.php — evita dependência transitiva quebrada) ──
require_once $ROOT . '/lib/TrendsScraperWeb.php';
require_once $ROOT . '/lib/DiscoverScore.php';
require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/DiscoverAngulo.php';
require_once $ROOT . '/lib/Serper.php';
require_once $ROOT . '/lib/Scraper.php';
require_once $ROOT . '/lib/GoogleNewsRss.php';
require_once $ROOT . '/lib/TrendsArticles.php';
require_once $ROOT . '/lib/Claude.php';
require_once $ROOT . '/lib/Wordpress.php';
require_once $ROOT . '/lib/Maquina.php';
require_once $ROOT . '/lib/DiscoverGerador.php';
require_once $ROOT . '/lib/DiscoverUpdater.php';
require_once $ROOT . '/lib/DiscoverFila.php';
require_once $ROOT . '/lib/DiscoverCalendario.php';
require_once $ROOT . '/lib/DiscoverCluster.php';
require_once $ROOT . '/lib/DiscoverQualityScore.php';
require_once $ROOT . '/lib/DiscoverReviewer.php';
require_once $ROOT . '/lib/OpenAI.php';
require_once $ROOT . '/lib/DiscoverGeradorGPT.php';
require_once $ROOT . '/lib/DiscoverProgress.php';
require_once $ROOT . '/lib/DiscoverPainClassifier.php';
require_once $ROOT . '/lib/DiscoverRPM.php';
require_once $ROOT . '/lib/DiscoverClusterMatcher.php';
require_once $ROOT . '/lib/DiscoverSinaisEditoriais.php';

$cfg = require $ROOT . '/config.php';
require $ROOT . '/_site_helper.php';
$sites = sitesDisponiveis();
$db    = new DiscoverDb();

// Kill switch: pipeline pausado via .env
require_once $ROOT . '/lib/KillSwitch.php';
if (KillSwitch::ativo()) {
    @file_put_contents($ROOT . '/data/fila/log_tick.log',
        '[' . date('Y-m-d H:i:s') . "] [skip] PIPELINE_PAUSED=1 — " . KillSwitch::motivo() . "\n",
        FILE_APPEND);
    exit(0);
}

$LOG_FILE = $ROOT . '/data/fila/log_tick.log';

// ── lock por SLOT: permite N ticks paralelos (default 1, configurável via --slots=N) ──
// Cada slot tenta adquirir um arquivo de lock numerado. Primeiro livre é usado.
// Quando 0 slots livres, sai silenciosamente. Aumenta capacidade do pipeline ~Nx.
// Race entre 2 ticks pegando o mesmo item já é prevenida por DiscoverFila::proximoComLock.
$totalSlots = 1;
foreach ($argv as $a) {
    if (preg_match('/^--slots=(\d+)$/', $a, $sm)) {
        $totalSlots = max(1, min(10, (int)$sm[1]));
    }
}
$globalFp = null;
$slotPego = 0;
for ($i = 1; $i <= $totalSlots; $i++) {
    $slotFile = $ROOT . '/data/fila/.tick_slot_' . $i . '.lock';
    $fp = @fopen($slotFile, 'c');
    if (!$fp) continue;
    if (flock($fp, LOCK_EX | LOCK_NB)) {
        $globalFp = $fp;
        $slotPego = $i;
        break;
    }
    fclose($fp);
}
if (!$globalFp) {
    log_msg($LOG_FILE, $quiet, "[skip] todos os {$totalSlots} slots ocupados — outros ticks rodando, saindo");
    exit(0);
}

// ── execução principal ──
try {
    $startTs = time();
    log_msg($LOG_FILE, $quiet, sprintf("=== tick start %s · PID %d · dry-run=%s ===",
        date('Y-m-d H:i:s'), getmypid(), $dryRun ? 'sim' : 'não'));

    $filaDir = $ROOT . '/data/fila';
    if (!is_dir($filaDir)) {
        log_msg($LOG_FILE, $quiet, "[fim] data/fila/ não existe");
        finalizar($globalFp);
    }

    $arquivos = glob($filaDir . '/*.json');
    if (empty($arquivos)) {
        log_msg($LOG_FILE, $quiet, "[fim] nenhuma fila ativa (data/fila/ vazio)");
        finalizar($globalFp);
    }

    $totalProcessados = 0;
    $totalErros = 0;

    foreach ($arquivos as $arq) {
        $site = basename($arq, '.json');
        if ($forceSite !== null && $site !== $forceSite) continue;
        if (!isset($sites[$site])) {
            log_msg($LOG_FILE, $quiet, "[skip] site '{$site}' não está em sites.php (arquivo órfão?)");
            continue;
        }

        // Aplica config do site (credenciais WP, persona, etc.)
        aplicarSite($cfg, $sites, $site);

        // Cleanup: items presos em "running" há mais de 10 min → voltam pra "pending"
        // Defesa contra crashes (servidor caiu, processo morreu, etc.)
        $reanimados = cleanupStaleRunning($arq, 600);
        if ($reanimados > 0) {
            log_msg($LOG_FILE, $quiet, "[{$site}] cleanup: {$reanimados} items 'running' stale → 'pending'");
        }

        $fila = new DiscoverFila($site);
        $status = $fila->status();

        if (!$status['existe']) continue;
        if (!empty($status['cancelado'])) {
            log_msg($LOG_FILE, $quiet, "[skip {$site}] fila cancelada");
            continue;
        }

        $pending = $status['counts']['pending'] ?? 0;
        $running = $status['counts']['running'] ?? 0;
        $done    = $status['counts']['done']    ?? 0;
        $failed  = $status['counts']['failed']  ?? 0;

        // Tudo terminou? Roda interlink final (idempotente — ok rodar várias vezes)
        if ($pending === 0 && $running === 0) {
            log_msg($LOG_FILE, $quiet, "[{$site}] fila completa (done={$done}, failed={$failed}) — interlink final");
            if (!$dryRun) rodarInterlinkFinal($cfg, $db, $site, $LOG_FILE, $quiet);
            continue;
        }

        log_msg($LOG_FILE, $quiet, sprintf("[%s] pending=%d running=%d done=%d failed=%d",
            $site, $pending, $running, $done, $failed));

        if ($dryRun) {
            log_msg($LOG_FILE, $quiet, "  [dry] pegaria próximo pendente (sem executar)");
            continue;
        }

        // Pega próximo pendente (com lock interno da fila)
        $item = $fila->proximoComLock();
        if (!$item) {
            log_msg($LOG_FILE, $quiet, "  [skip] sem item pendente disponível (provável race com outro tick)");
            continue;
        }

        log_msg($LOG_FILE, $quiet, "  [exec] item #{$item['id']} · trend_id={$item['trend_id']} · termo='{$item['termo']}'");

        try {
            $rec = $db->get((int)$item['trend_id']);
            if (!$rec) {
                $fila->marcarResultado($item['id'], ['ok' => false, 'erro' => 'Registro não encontrado no DB']);
                log_msg($LOG_FILE, $quiet, "  [erro] trend_id {$item['trend_id']} não existe no DB");
                $totalErros++;
                continue;
            }

            // ─── DEAD-LETTER QUEUE: skip se trend já falhou demais ───
            $maxFalhas = (int)(getenv('DLQ_MAX_FALHAS_CONSECUTIVAS') ?: 3);
            $falhasAtuais = (int)($rec['falhas_consecutivas'] ?? 0);
            if ($falhasAtuais >= $maxFalhas) {
                $db->updateStatus((int)$rec['id'], 'falhado_max_retries', [
                    'dlq_razao' => "max retries atingido ({$falhasAtuais}/{$maxFalhas})",
                    'dlq_em'    => date('Y-m-d H:i:s'),
                ]);
                $fila->marcarResultado($item['id'], ['ok' => false, 'erro' => "DLQ: max retries ({$falhasAtuais})"]);
                log_msg($LOG_FILE, $quiet, "  [DLQ] trend {$rec['id']} parado após {$falhasAtuais} falhas consecutivas");
                $totalErros++;
                continue;
            }

            $t0 = microtime(true);
            $gen = new DiscoverGerador($cfg, $db);
            $res = $gen->gerar($rec, $status['formato'] ?? 'discover');
            $res['tempo_ms'] = (int)((microtime(true) - $t0) * 1000);
            $fila->marcarResultado($item['id'], $res);

            // Atualiza contador de falhas consecutivas no trend
            try {
                $statusAtual = (string)($db->get((int)$rec['id'])['status'] ?? $rec['status'] ?? 'novo');
                if (!empty($res['ok'])) {
                    // Sucesso → reset
                    if ($falhasAtuais > 0) {
                        $db->updateStatus((int)$rec['id'], $statusAtual, ['falhas_consecutivas' => 0]);
                    }
                } else {
                    $novoFalhas = $falhasAtuais + 1;
                    $db->updateStatus((int)$rec['id'], $statusAtual, [
                        'falhas_consecutivas'  => $novoFalhas,
                        'ultimo_erro'          => mb_substr((string)($res['erro'] ?? '?'), 0, 200, 'UTF-8'),
                        'ultimo_erro_em'       => date('Y-m-d H:i:s'),
                    ]);
                    if ($novoFalhas >= $maxFalhas) {
                        log_msg($LOG_FILE, $quiet, "  [DLQ-warn] trend {$rec['id']} atingiu {$novoFalhas}/{$maxFalhas} falhas — próxima execução vai pular");
                    }
                }
            } catch (Throwable $e) { /* contador é PLUS — não bloqueia tick */ }

            $resStatus = !empty($res['ok']) ? 'OK' : 'FAIL';
            $tempo = round($res['tempo_ms'] / 1000, 1);
            $postId = $res['post_id'] ?? '?';
            $erro = $res['erro'] ?? '';
            log_msg($LOG_FILE, $quiet,
                "  [{$resStatus}] {$tempo}s · post_id={$postId}" . ($erro ? " · erro: {$erro}" : ''));

            if (!empty($res['ok'])) $totalProcessados++;
            else $totalErros++;

            if ($maxItems > 0 && ($totalProcessados + $totalErros) >= $maxItems) {
                log_msg($LOG_FILE, $quiet, "[limite] atingiu --max={$maxItems}, parando");
                break;
            }
        } catch (Throwable $e) {
            $fila->marcarResultado($item['id'], ['ok' => false, 'erro' => $e->getMessage()]);
            log_msg($LOG_FILE, $quiet, "  [exception] " . $e->getMessage());
            $totalErros++;
        }
    }

    $tempo = time() - $startTs;
    log_msg($LOG_FILE, $quiet,
        sprintf("=== tick end · processados=%d erros=%d · %ds ===",
            $totalProcessados, $totalErros, $tempo));
} finally {
    flock($globalFp, LOCK_UN);
    fclose($globalFp);
}

exit(0);

// ────────────────────────────────────────────────────────────────────────
// Funções auxiliares
// ────────────────────────────────────────────────────────────────────────

function log_msg(string $logFile, bool $quiet, string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if (!$quiet) echo $line . PHP_EOL;
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

/**
 * Items presos em "running" há mais de $thresholdSec → volta status pra "pending".
 * Defesa contra crashes (PHP fatal, servidor reiniciado, etc.).
 * Retorna quantos items foram revertidos.
 */
function cleanupStaleRunning(string $arq, int $thresholdSec): int
{
    $d = json_decode((string)@file_get_contents($arq), true);
    if (!is_array($d) || empty($d['items'])) return 0;
    $now = time();
    $count = 0;
    $changed = false;
    foreach ($d['items'] as &$it) {
        if (($it['status'] ?? '') !== 'running') continue;
        $started = isset($it['started_at']) ? strtotime((string)$it['started_at']) : 0;
        if ($started > 0 && ($now - $started) > $thresholdSec) {
            $it['status']     = 'pending';
            $it['started_at'] = null;
            $count++;
            $changed = true;
        }
    }
    unset($it);
    if ($changed) {
        @file_put_contents($arq, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    return $count;
}

/**
 * Roda re-interligação dos clusters do site. Idempotente — só atualiza posts onde os links
 * realmente mudaram. Mesmo comportamento do bloco final do handler ?ajax=fila_tick.
 */
function rodarInterlinkFinal(array $cfg, DiscoverDb $db, string $site, string $logFile, bool $quiet): void
{
    try {
        $cluster = new DiscoverCluster($cfg, $db);
        foreach ($cluster->listarClusters($site) as $cl) {
            if (($cl['publicados'] ?? 0) >= 2) {
                try {
                    $r = $cluster->interligar($site, $cl['nome']);
                    log_msg($logFile, $quiet, sprintf("  [interlink %s] %s: %d/%d atualizados",
                        $site, $cl['nome'], $r['atualizados'] ?? 0, $r['total_posts'] ?? 0));
                } catch (Throwable $e) {
                    log_msg($logFile, $quiet, "  [interlink err] {$cl['nome']}: " . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        log_msg($logFile, $quiet, "  [interlink global err] " . $e->getMessage());
    }
}

function finalizar($globalFp): void
{
    flock($globalFp, LOCK_UN);
    fclose($globalFp);
    exit(0);
}
