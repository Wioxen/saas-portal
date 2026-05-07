<?php
/**
 * gerar_dashboard.php — coleta stats da operação multi-site e gera HTML único.
 *
 * Output: /app/data/dashboard/index.html (acessível via dashboard.php).
 * Cron horário regenera. Custo zero (só DB + REST).
 *
 * Uso:
 *   php scripts/gerar_dashboard.php
 *   php scripts/gerar_dashboard.php --quiet
 */

declare(strict_types=1);

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$quiet = !empty($args['quiet']);

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/DbConnection.php';

$say = function (string $msg) use ($quiet) {
    if (!$quiet) echo $msg . "\n";
};

$say('═══ Dashboard generator ═══');

$sitesGlobais = sitesDisponiveis();
$pdo = DbConnection::pdo();

// Sites editoriais (com entity_pages_enabled) — exclui vafast
$sitesAlvo = array_filter($sitesGlobais, fn($c) => !empty($c['entity_pages_enabled']));

$dados = [
    'gerado_em' => date('Y-m-d H:i:s'),
    'sites' => [],
    'totais' => ['hubs' => 0, 'posts_24h' => 0, 'posts_7d' => 0, 'trends_publicados' => 0, 'contradicoes_7d' => 0],
];

foreach ($sitesAlvo as $slug => $cfgSite) {
    $say("→ {$slug}");
    $aplicado = $cfg;
    aplicarSite($aplicado, $sitesGlobais, $slug);

    // 1. Trends por status
    $statsTrends = [];
    $rs = $pdo->prepare("SELECT status, COUNT(*) as c FROM trends WHERE site=:s GROUP BY status");
    $rs->execute([':s' => $slug]);
    foreach ($rs as $row) $statsTrends[$row['status']] = (int)$row['c'];

    // 2. Posts publicados via REST (últimos 24h, 7d, 30d)
    $base = rtrim($aplicado['wp_url'], '/') . '/wp-json/wp/v2';
    $auth = base64_encode($aplicado['wp_user'] . ':' . $aplicado['wp_app_password']);
    $contar = function (string $afterIso) use ($base, $auth): int {
        $url = "{$base}/posts?status=publish&per_page=1&after=" . rawurlencode($afterIso);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Basic {$auth}"],
            CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = (string)curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        if (preg_match('/X-WP-Total:\s*(\d+)/i', $resp, $m)) return (int)$m[1];
        return 0;
    };
    $h24 = (new DateTime('-1 day'))->format('Y-m-d\TH:i:s');
    $d7 = (new DateTime('-7 days'))->format('Y-m-d\TH:i:s');
    $d30 = (new DateTime('-30 days'))->format('Y-m-d\TH:i:s');
    $posts24h = $contar($h24);
    $posts7d = $contar($d7);
    $posts30d = $contar($d30);

    // 3. Hubs entity/concept (do aliases.json local)
    $aliases = [];
    $aliasPath = __DIR__ . "/../data/entity_pages_cache/{$slug}_aliases.json";
    if (file_exists($aliasPath)) {
        $aliases = json_decode((string)file_get_contents($aliasPath), true) ?: [];
    }
    $hubsEntity = 0;
    $hubsConcept = 0;
    foreach ($aliases as $a) {
        $tipo = $a['tipo'] ?? 'entity';
        if ($tipo === 'concept') $hubsConcept++; else $hubsEntity++;
    }

    // 4. Contradições últimos 7d
    $contradicoes7d = 0;
    $contraDir = __DIR__ . '/../data/contradictions';
    if (is_dir($contraDir)) {
        foreach (glob("{$contraDir}/*_{$slug}.json") ?: [] as $f) {
            $mtime = filemtime($f);
            if ($mtime && (time() - $mtime) < 7 * 86400) {
                $j = json_decode((string)file_get_contents($f), true);
                if (is_array($j)) $contradicoes7d += count($j['contradicoes'] ?? []);
            }
        }
    }

    // 5. Last run de cada cron (último log mais recente do tipo)
    $lastRuns = [];
    foreach (['update_detector', 'faq_hubs', 'contradiction', 'knowledge_graph'] as $t) {
        $logFile = "/var/log/{$t}.log";
        $lastRuns[$t] = file_exists($logFile) ? date('Y-m-d H:i', filemtime($logFile)) : '—';
    }

    $dados['sites'][$slug] = [
        'name'           => $aplicado['name'] ?? $slug,
        'wp_url'         => $aplicado['wp_url'] ?? '',
        'persona_autor'  => $aplicado['persona']['autor'] ?? '—',
        'editora'        => $aplicado['empresa']['nome'] ?? '—',
        'trends'         => $statsTrends,
        'posts_24h'      => $posts24h,
        'posts_7d'       => $posts7d,
        'posts_30d'      => $posts30d,
        'hubs_entity'    => $hubsEntity,
        'hubs_concept'   => $hubsConcept,
        'hubs_total'     => $hubsEntity + $hubsConcept,
        'contradicoes_7d'=> $contradicoes7d,
        'last_runs'      => $lastRuns,
    ];

    $dados['totais']['hubs'] += $hubsEntity + $hubsConcept;
    $dados['totais']['posts_24h'] += $posts24h;
    $dados['totais']['posts_7d'] += $posts7d;
    $dados['totais']['trends_publicados'] += $statsTrends['publicado'] ?? 0;
    $dados['totais']['contradicoes_7d'] += $contradicoes7d;
}

// Renderizar HTML
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

ob_start();
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard Operação · <?= date('d/m/Y H:i') ?></title>
<style>
* { box-sizing: border-box; }
body { font: 14px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; margin:0; padding:24px; background:#f8fafc; color:#1e293b; }
h1 { font-size:22px; margin:0 0 4px; }
h2 { font-size:18px; margin:32px 0 12px; }
.meta { color:#64748b; font-size:12px; margin-bottom:24px; }
.cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin:16px 0 32px; }
.card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; }
.card .label { font-size:11px; text-transform:uppercase; color:#64748b; letter-spacing:.5px; }
.card .value { font-size:28px; font-weight:700; color:#0f172a; margin-top:4px; }
.card .sub { font-size:12px; color:#64748b; margin-top:4px; }
table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; }
th { background:#f1f5f9; text-align:left; padding:12px; font-size:11px; text-transform:uppercase; color:#475569; letter-spacing:.5px; }
td { padding:12px; border-top:1px solid #e2e8f0; vertical-align:top; }
td.num { text-align:right; font-variant-numeric:tabular-nums; }
.editora-2 { background:#dbeafe; color:#1e40af; padding:2px 8px; border-radius:4px; font-size:11px; }
.editora-3 { background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:4px; font-size:11px; }
.warn { color:#dc2626; font-weight:600; }
.ok { color:#16a34a; }
.muted { color:#94a3b8; }
.tiny { font-size:11px; }
</style>
</head>
<body>
<h1>Dashboard de Operação · 6 sites</h1>
<p class="meta">Atualizado em <?= $h($dados['gerado_em']) ?> · Cron horário regenera automaticamente</p>

<div class="cards">
  <div class="card"><div class="label">Total hubs (rede)</div><div class="value"><?= $dados['totais']['hubs'] ?></div><div class="sub">entity + concept publicados</div></div>
  <div class="card"><div class="label">Posts 24h</div><div class="value"><?= $dados['totais']['posts_24h'] ?></div><div class="sub">soma 6 sites</div></div>
  <div class="card"><div class="label">Posts 7d</div><div class="value"><?= $dados['totais']['posts_7d'] ?></div></div>
  <div class="card"><div class="label">Trends publicados (DB)</div><div class="value"><?= $dados['totais']['trends_publicados'] ?></div></div>
  <div class="card"><div class="label">Contradições 7d</div><div class="value <?= $dados['totais']['contradicoes_7d']>0?'warn':'ok' ?>"><?= $dados['totais']['contradicoes_7d'] ?></div><div class="sub">drafts WP gerados</div></div>
</div>

<h2>Por site</h2>
<table>
<thead><tr>
<th>Site</th><th>Editora</th><th>Autor</th>
<th class="num">Hubs E/C</th>
<th class="num">Posts 24h</th><th class="num">Posts 7d</th><th class="num">Posts 30d</th>
<th class="num">Trends pub</th>
<th class="num">Contrad. 7d</th>
</tr></thead>
<tbody>
<?php foreach ($dados['sites'] as $slug => $s): ?>
<tr>
<td><a href="<?= $h($s['wp_url']) ?>" target="_blank"><strong><?= $h($slug) ?></strong></a><br><span class="tiny muted"><?= $h($s['name']) ?></span></td>
<td><span class="editora-<?= str_contains($s['editora'],'Sistema 2') ? '2' : '3' ?>"><?= $h(str_replace(['Sistema 2 Conteúdo Educacional','Sistema 3 Mídia Digital'],['S2 Educação','S3 Lifestyle'],$s['editora'])) ?></span></td>
<td class="tiny"><?= $h($s['persona_autor']) ?></td>
<td class="num"><strong><?= $s['hubs_total'] ?></strong> <span class="muted tiny">(<?= $s['hubs_entity'] ?>e/<?= $s['hubs_concept'] ?>c)</span></td>
<td class="num"><?= $s['posts_24h'] ?></td>
<td class="num"><?= $s['posts_7d'] ?></td>
<td class="num"><?= $s['posts_30d'] ?></td>
<td class="num"><?= $s['trends']['publicado'] ?? 0 ?></td>
<td class="num <?= $s['contradicoes_7d']>0?'warn':'muted' ?>"><?= $s['contradicoes_7d'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h2>Trends por status (DB)</h2>
<table>
<thead><tr><th>Site</th><th class="num">Total</th><th class="num">novo</th><th class="num">aprovado</th><th class="num">processando</th><th class="num">publicado</th><th class="num">rejeitado</th><th class="num">suspeita</th><th class="num">fidelity_warn</th></tr></thead>
<tbody>
<?php foreach ($dados['sites'] as $slug => $s): $t = $s['trends']; $total = array_sum($t); ?>
<tr>
<td><strong><?= $h($slug) ?></strong></td>
<td class="num"><?= $total ?></td>
<td class="num"><?= $t['novo'] ?? 0 ?></td>
<td class="num"><?= $t['aprovado'] ?? 0 ?></td>
<td class="num"><?= $t['processando'] ?? 0 ?></td>
<td class="num ok"><?= $t['publicado'] ?? 0 ?></td>
<td class="num"><?= $t['rejeitado'] ?? 0 ?></td>
<td class="num"><?= $t['suspeita'] ?? 0 ?></td>
<td class="num"><?= $t['fidelity_warn'] ?? 0 ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h2>Last run dos crons (mtime do log)</h2>
<table>
<thead><tr><th>Site</th><th>Update Detector</th><th>FAQ Hubs</th><th>Contradiction</th><th>Knowledge Graph</th></tr></thead>
<tbody>
<?php foreach ($dados['sites'] as $slug => $s): ?>
<tr>
<td><strong><?= $h($slug) ?></strong></td>
<td class="tiny"><?= $h($s['last_runs']['update_detector']) ?></td>
<td class="tiny"><?= $h($s['last_runs']['faq_hubs']) ?></td>
<td class="tiny"><?= $h($s['last_runs']['contradiction']) ?></td>
<td class="tiny"><?= $h($s['last_runs']['knowledge_graph']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p class="meta tiny" style="margin-top:32px">Dashboard gerado por <code>scripts/gerar_dashboard.php</code> · cron horário · acesso restrito via <code>dashboard.php</code> + basic auth.</p>
</body>
</html>
<?php
$html = ob_get_clean();

// Salva HTML
$outDir = __DIR__ . '/../data/dashboard';
@mkdir($outDir, 0775, true);
$outPath = "{$outDir}/index.html";
file_put_contents($outPath, $html);

// Plus salva JSON pra consumo programático
file_put_contents("{$outDir}/data.json", json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$say("✓ {$outPath} (" . number_format(strlen($html) / 1024, 1) . ' KB)');
$say("✓ {$outDir}/data.json");
$say("Acesse via dashboard.php (basic auth).");
