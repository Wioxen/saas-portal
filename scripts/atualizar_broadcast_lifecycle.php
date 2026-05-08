<?php
declare(strict_types=1);

/**
 * atualizar_broadcast_lifecycle.php
 *
 * Varre data/jogos_vitoria.json e atualiza o eventStatus do BroadcastEvent
 * embutido em cada post pré-jogo conforme a fase do jogo (relativa a now):
 *
 *   now < kickoff               → EventScheduled  (estado inicial; ignora)
 *   kickoff..kickoff+130min     → EventInProgress
 *   >= kickoff+130min           → EventCompleted (+ injeta placar se houver)
 *
 * Idempotente: persiste `_lifecycle_status` no JSON pra não retrabalhar.
 * Pode rodar via cron de 15 em 15 min sem custo de API (só atinge WP+IndexingAPI quando há transição real).
 *
 * Flags:
 *   --site=leaodabarra (default)
 *   --dry-run
 *   --force-game=ID (rodar só 1 jogo, ignorando _lifecycle_status)
 *   --verbose
 *   --skip-indexing-api
 */

date_default_timezone_set('America/Sao_Paulo');

$opts = getopt('', ['site::', 'dry-run', 'force-game::', 'verbose', 'skip-indexing-api']);
$siteSlug = $opts['site'] ?? 'leaodabarra';
$dryRun = isset($opts['dry-run']);
$forceGame = $opts['force-game'] ?? null;
$verbose = isset($opts['verbose']);
$skipIdx = isset($opts['skip-indexing-api']);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
$cfg = require __DIR__ . '/../config.php';
aplicarSite($cfg, sitesDisponiveis(), $siteSlug);
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/BroadcastEventBuilder.php';
require_once __DIR__ . '/../lib/GoogleIndexingApi.php';

$jogosPath = __DIR__ . '/../data/jogos_vitoria.json';
if (!file_exists($jogosPath)) {
    fwrite(STDERR, "ERRO: {$jogosPath} não encontrado\n");
    exit(1);
}
$db = json_decode((string)file_get_contents($jogosPath), true);
if (!is_array($db) || empty($db['jogos'])) {
    fwrite(STDERR, "ERRO: jogos_vitoria.json inválido\n");
    exit(1);
}

$tz = new DateTimeZone('America/Sao_Paulo');
$now = new DateTime('now', $tz);
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$builder = new BroadcastEventBuilder();

$idxClient = null;
if (!$skipIdx) {
    $credPath = '/app/data/credentials/google-indexing.json';
    if (!file_exists($credPath)) $credPath = __DIR__ . '/../data/credentials/google-indexing.json';
    if (file_exists($credPath)) {
        try { $idxClient = new GoogleIndexingApi($credPath); }
        catch (Throwable $e) { if ($verbose) echo "[WARN] IndexingAPI off: {$e->getMessage()}\n"; }
    }
}

$updated = 0;
$skipped = 0;
$errors = 0;

foreach ($db['jogos'] as &$jogo) {
    if ($forceGame && $jogo['id'] !== $forceGame) { continue; }

    $postId = (int)($jogo['posts_gerados']['pre_jogo'] ?? 0);
    if ($postId <= 0) {
        if ($verbose) echo "[{$jogo['id']}] sem pre_jogo, skip\n";
        $skipped++;
        continue;
    }
    if (empty($jogo['data']) || empty($jogo['hora'])) {
        if ($verbose) echo "[{$jogo['id']}] sem data/hora, skip\n";
        $skipped++;
        continue;
    }

    try {
        $kickoff = new DateTime($jogo['data'] . ' ' . $jogo['hora'] . ':00', $tz);
    } catch (Throwable $e) {
        echo "[{$jogo['id']}] data/hora inválida: {$e->getMessage()}\n";
        $errors++;
        continue;
    }
    $end = (clone $kickoff)->modify('+130 minutes');

    if ($now < $kickoff) {
        $newStatus = 'EventScheduled';
    } elseif ($now < $end) {
        $newStatus = 'EventInProgress';
    } else {
        $newStatus = 'EventCompleted';
    }

    $current = $jogo['_lifecycle_status'] ?? 'EventScheduled';
    if (!$forceGame && $current === $newStatus) {
        if ($verbose) echo "[{$jogo['id']}] já em {$newStatus}, skip\n";
        $skipped++;
        continue;
    }

    try {
        $post = $wp->getPost($postId);
    } catch (Throwable $e) {
        echo "[{$jogo['id']}] getPost({$postId}) falhou: {$e->getMessage()}\n";
        $errors++;
        continue;
    }
    $html = $post['content']['raw'] ?? '';
    $link = $post['link'] ?? '';

    $hasBroadcast = (bool)preg_match('/<script[^>]*data-broadcast-event[^>]*>.*?<\/script>/s', $html);
    if (!$hasBroadcast) {
        echo "[{$jogo['id']}] post #{$postId} não tem BroadcastEvent embutido, skip\n";
        $skipped++;
        continue;
    }

    $schema = $builder->montar($jogo, ['post_url' => $link]);
    $schema['eventStatus'] = "https://schema.org/{$newStatus}";
    $schema['broadcastOfEvent']['eventStatus'] = "https://schema.org/{$newStatus}";

    if ($newStatus === 'EventCompleted' && !empty($jogo['placar']) && isset($jogo['placar']['vitoria'], $jogo['placar']['adversario'])) {
        $advNome = $jogo['adversario']['nome'] ?? 'Adversário';
        $mandoCasa = ($jogo['mando'] ?? '') === 'casa';
        $homeName = $mandoCasa ? 'Esporte Clube Vitória' : $advNome;
        $awayName = $mandoCasa ? $advNome : 'Esporte Clube Vitória';
        $homeScore = $mandoCasa ? (int)$jogo['placar']['vitoria'] : (int)$jogo['placar']['adversario'];
        $awayScore = $mandoCasa ? (int)$jogo['placar']['adversario'] : (int)$jogo['placar']['vitoria'];
        $schema['broadcastOfEvent']['homeTeam']['score'] = $homeScore;
        $schema['broadcastOfEvent']['awayTeam']['score'] = $awayScore;
        $schema['name'] = "Replay: {$homeName} {$homeScore} x {$awayScore} {$awayName} — " . ($jogo['competicao'] ?? '');
    }

    $newJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $newHtml = preg_replace_callback(
        '/<script([^>]*data-broadcast-event[^>]*)>.*?<\/script>/s',
        function ($m) use ($newJson) {
            return '<script' . $m[1] . ">\n" . $newJson . "\n</script>";
        },
        $html,
        1
    );

    if ($newHtml === $html) {
        echo "[{$jogo['id']}] regex não alterou HTML — verifique pattern\n";
        $errors++;
        continue;
    }

    if ($dryRun) {
        echo "[DRY] {$jogo['id']}: {$current} → {$newStatus} (post #{$postId})\n";
        continue;
    }

    try {
        $wp->atualizarPost($postId, ['content' => $newHtml]);
    } catch (Throwable $e) {
        echo "[{$jogo['id']}] atualizarPost falhou: {$e->getMessage()}\n";
        $errors++;
        continue;
    }

    $jogo['_lifecycle_status'] = $newStatus;
    $jogo['_lifecycle_updated_at'] = $now->format('c');

    $idxStatus = 'skip';
    if ($idxClient && $link) {
        try {
            $r = $idxClient->notifyUrl($link, 'URL_UPDATED');
            $idxStatus = ($r['success'] ?? false) ? 'ok' : ('fail http=' . ($r['http_code'] ?? '?'));
        } catch (Throwable $e) {
            $idxStatus = 'err: ' . $e->getMessage();
        }
    }

    echo "[{$jogo['id']}] {$current} → {$newStatus} (post #{$postId}) idx={$idxStatus}\n";
    $updated++;
}
unset($jogo);

if (!$dryRun && $updated > 0) {
    $db['_meta']['lifecycle_atualizado_em'] = $now->format('c');
    file_put_contents(
        $jogosPath,
        json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
}

echo "\n═══ RESUMO ═══\n";
echo "  updated: {$updated}\n";
echo "  skipped: {$skipped}\n";
echo "  errors:  {$errors}\n";
exit($errors > 0 ? 1 : 0);
