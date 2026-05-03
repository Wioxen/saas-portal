<?php
declare(strict_types=1);

/**
 * scripts/gerar_paginas_hub.php
 *
 * Runner CLI pra gerar páginas-hub do leaodabarra (Esporte Clube Vitória).
 *
 * MODOS:
 *   --dry-run                      mostra o que vai gerar, sem chamar API nem criar post
 *   --single=<slug>                gera 1 hub específico (ex: --single=barradao)
 *   --from=<slug> --to=<slug>      gera intervalo (índices da lista)
 *   --batch                        gera todos (pede confirmação)
 *   --list                         lista todos os hubs com slug+tipo
 *   --site=<slug>                  default 'leaodabarra'
 *   --pause=<segundos>             pausa entre hubs (default 10s — evita rate limit)
 *
 * EXEMPLO TESTE:
 *   php scripts/gerar_paginas_hub.php --single=barradao
 *
 * EXEMPLO BATCH COMPLETO:
 *   php scripts/gerar_paginas_hub.php --batch
 *
 * SAÍDA:
 *   Cada hub vira página WP em status='draft' (pra você revisar antes de publicar).
 *   Logs em logs/hubs_<timestamp>.log.
 *   Relatório final: aprovados / rejeitados / a revisar.
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/Scraper.php';
require_once __DIR__ . '/../lib/Serper.php';
require_once __DIR__ . '/../lib/HubBuilder.php';

$opts = getopt('', ['dry-run', 'single::', 'from::', 'to::', 'batch', 'list', 'site::', 'pause::', 'yes']);

$siteSlug = (string)($opts['site'] ?? 'leaodabarra');
$pause    = (int)($opts['pause'] ?? 10);
$dryRun   = isset($opts['dry-run']);

$sites = sitesDisponiveis();
if (!isset($sites[$siteSlug])) {
    fwrite(STDERR, "Site '{$siteSlug}' não existe em sites.php\n");
    exit(2);
}
aplicarSite($cfg, $sites, $siteSlug);

$hubsCfg = require __DIR__ . '/../data/hubs_vitoria.php';
if (!is_array($hubsCfg) || empty($hubsCfg)) {
    fwrite(STDERR, "data/hubs_vitoria.php vazio\n");
    exit(2);
}

// MODO --list
if (isset($opts['list'])) {
    foreach ($hubsCfg as $i => $h) {
        printf("%3d  %-15s  %-40s  %s\n",
            $i,
            $h['tipo'] ?? '?',
            $h['slug'] ?? '?',
            $h['titulo_h1'] ?? ''
        );
    }
    exit(0);
}

// Filtra por modo
$selecionados = [];
if (!empty($opts['single'])) {
    foreach ($hubsCfg as $h) {
        if (($h['slug'] ?? '') === $opts['single']) { $selecionados[] = $h; break; }
    }
    if (empty($selecionados)) { fwrite(STDERR, "Slug '{$opts['single']}' não encontrado.\n"); exit(2); }
} elseif (!empty($opts['from']) || !empty($opts['to'])) {
    $from = (int)($opts['from'] ?? 0);
    $to   = (int)($opts['to'] ?? count($hubsCfg) - 1);
    for ($i = $from; $i <= $to && $i < count($hubsCfg); $i++) $selecionados[] = $hubsCfg[$i];
} elseif (isset($opts['batch'])) {
    $selecionados = $hubsCfg;
} else {
    fwrite(STDERR, "Especifique --single=<slug>, --from/--to, --batch, --list ou --dry-run\n");
    fwrite(STDERR, "Exemplo: php scripts/gerar_paginas_hub.php --single=barradao\n");
    exit(2);
}

echo str_repeat('═', 80) . "\n";
echo " HUB BUILDER · site={$siteSlug} · {" . count($selecionados) . "} hubs · " . ($dryRun ? 'DRY-RUN' : 'PRODUÇÃO') . "\n";
echo str_repeat('═', 80) . "\n\n";

// Confirma se for batch grande (skip com --yes pra background)
if (!$dryRun && count($selecionados) > 5 && !isset($opts['yes'])) {
    echo "Vai gerar " . count($selecionados) . " páginas em PRODUÇÃO (status=draft).\n";
    echo "Custo estimado: \$" . number_format(count($selecionados) * 0.18, 2) . " USD (~5h com pause de {$pause}s).\n";
    echo "Confirma? (digite SIM): ";
    $r = trim(fgets(STDIN));
    if (strtoupper($r) !== 'SIM') { echo "Abortado.\n"; exit(0); }
    echo "\n";
}

// Inicializa serviços
$wp     = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$claude = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model'] ?? 'claude-sonnet-4-6');
$scraper= new Scraper($cfg['user_agent'] ?? 'Mozilla/5.0', (int)($cfg['scrape_timeout'] ?? 15));
$serper = new Serper($cfg['serper_api_key']);

$services = ['wp' => $wp, 'claude' => $claude, 'scraper' => $scraper, 'serper' => $serper];

$logFile = __DIR__ . '/../logs/hubs_' . date('Ymd_His') . '.log';
@mkdir(dirname($logFile), 0775, true);

$resumo = ['aprovado' => 0, 'rejeitado' => 0, 'erro' => 0, 'lista_aprovados' => [], 'lista_rejeitados' => [], 'lista_erros' => []];

foreach ($selecionados as $idx => $hub) {
    $slug = $hub['slug'] ?? "hub_{$idx}";
    echo str_repeat('─', 80) . "\n";
    echo sprintf("[%d/%d] %s · tipo=%s\n", $idx + 1, count($selecionados), $slug, $hub['tipo'] ?? '?');
    echo str_repeat('─', 80) . "\n";

    $t0 = microtime(true);
    try {
        $resultado = HubBuilder::gerar($cfg, $hub, $services, $dryRun);
    } catch (Throwable $e) {
        $resultado = ['ok' => false, 'erro' => 'Exceção: ' . $e->getMessage()];
    }
    $tempo = round(microtime(true) - $t0, 1);

    foreach ($resultado['log'] ?? [] as $linha) {
        echo "  · {$linha}\n";
    }

    if ($resultado['ok'] ?? false) {
        if ($dryRun) {
            echo "  ✓ DRY-RUN ok ({$tempo}s)\n\n";
            $resumo['aprovado']++;
            $resumo['lista_aprovados'][] = $slug;
        } else {
            $tier = $resultado['tier_max'] ?? '?';
            $issues = count($resultado['auditoria']['issues'] ?? []);
            echo "  ✓ APROVADO post_id={$resultado['post_id']} tier={$tier} issues={$issues} tempo={$tempo}s\n";
            echo "    Edit: {$resultado['edit_url']}\n\n";
            $resumo['aprovado']++;
            $resumo['lista_aprovados'][] = "{$slug} (id={$resultado['post_id']})";
        }
    } else {
        $erro = $resultado['erro'] ?? '?';
        if (str_contains($erro, 'auditoria')) {
            echo "  ✗ REPROVADO auditoria — issues: " . count($resultado['auditoria']['issues'] ?? []) . "\n";
            foreach (array_slice($resultado['auditoria']['issues'] ?? [], 0, 5) as $i) echo "    - {$i}\n";
            $resumo['rejeitado']++;
            $resumo['lista_rejeitados'][] = $slug;
        } else {
            echo "  ✗ ERRO: {$erro}\n";
            $resumo['erro']++;
            $resumo['lista_erros'][] = "{$slug}: {$erro}";
        }
        echo "\n";
    }

    file_put_contents($logFile, json_encode([
        'idx' => $idx, 'slug' => $slug, 'tipo' => $hub['tipo'] ?? '?',
        'ok' => $resultado['ok'] ?? false,
        'post_id' => $resultado['post_id'] ?? null,
        'erro' => $resultado['erro'] ?? null,
        'tempo' => $tempo,
        'auditoria' => $resultado['auditoria'] ?? null,
        'tier_max' => $resultado['tier_max'] ?? null,
    ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

    // Pausa entre hubs (anti rate-limit)
    if (!$dryRun && $idx < count($selecionados) - 1 && $pause > 0) {
        echo "  (pause {$pause}s)\n";
        sleep($pause);
    }
}

echo "\n" . str_repeat('═', 80) . "\n";
echo " RELATÓRIO FINAL\n";
echo str_repeat('═', 80) . "\n";
echo "  Aprovados:  {$resumo['aprovado']}\n";
echo "  Rejeitados: {$resumo['rejeitado']}\n";
echo "  Erros:      {$resumo['erro']}\n";
echo "  Log:        {$logFile}\n\n";

if (!empty($resumo['lista_aprovados'])) {
    echo "APROVADOS:\n";
    foreach ($resumo['lista_aprovados'] as $a) echo "  ✓ {$a}\n";
    echo "\n";
}
if (!empty($resumo['lista_rejeitados'])) {
    echo "REJEITADOS (revisar antes de regerar):\n";
    foreach ($resumo['lista_rejeitados'] as $r) echo "  ✗ {$r}\n";
    echo "\n";
}
if (!empty($resumo['lista_erros'])) {
    echo "ERROS:\n";
    foreach ($resumo['lista_erros'] as $e) echo "  ! {$e}\n";
    echo "\n";
}

exit(0);
