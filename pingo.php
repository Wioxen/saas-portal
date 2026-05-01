<?php
/**
 * pingo.php — UI de gerenciamento do DiscoverPingo.
 *
 * Features:
 *   - Lista fontes com stats (items 7d, última exec, último erro)
 *   - Form CRUD de fontes
 *   - Botão "Rodar agora" (AJAX, força execução ignorando intervalo)
 *   - Widget de últimas capturas do pingo
 */

require_once __DIR__ . '/lib/DiscoverDb.php';
require_once __DIR__ . '/lib/DiscoverPingo.php';
require_once __DIR__ . '/lib/TrendsTaxonomia.php';
require_once __DIR__ . '/_site_helper.php';

$cfg = require __DIR__ . '/config.php';
$sites = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

$db = new DiscoverDb();
$pingo = new DiscoverPingo($cfg, $db);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
$msg = '';
$erro = '';

// ─── AJAX: rodar fonte ───
if (($_GET['ajax'] ?? '') === 'rodar') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(120);
    try {
        $id = isset($_GET['fonte_id']) ? (int)$_GET['fonte_id'] : null;
        $t0 = microtime(true);
        $rel = $pingo->rodar([
            'fonte_id' => $id,
            'force' => true,
        ]);
        $rel['tempo_ms'] = (int)((microtime(true) - $t0) * 1000);
        echo json_encode(['ok' => true, 'relatorio' => $rel], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// ─── CRUD ───
if ($acao === 'adicionar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pingo->adicionarFonte([
            'nome'                    => $_POST['nome'] ?? '',
            'url_rss'                 => $_POST['url_rss'] ?? '',
            'tipo'                    => $_POST['tipo'] ?? 'rss',
            'cluster_hint'            => $_POST['cluster_hint'] ?? 'curiosidades_geral',
            'site_target'             => $_POST['site_target'] ?? 'auto',
            'intervalo_min'           => (int)($_POST['intervalo_min'] ?? 15),
            'max_itens_por_fetch'     => (int)($_POST['max_itens_por_fetch'] ?? 30),
            'auto_aprovar_score_min'  => (float)($_POST['auto_aprovar_score_min'] ?? 7.0),
            'ativo'                   => !empty($_POST['ativo']),
            'notas'                   => $_POST['notas'] ?? '',
        ]);
        header('Location: pingo.php?msg=adicionado&site=' . urlencode($siteSlug)); exit;
    } catch (Throwable $e) { $erro = $e->getMessage(); }
}

if ($acao === 'atualizar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pingo->atualizarFonte((int)($_POST['id'] ?? 0), [
            'nome'                    => $_POST['nome'] ?? '',
            'url_rss'                 => $_POST['url_rss'] ?? '',
            'tipo'                    => $_POST['tipo'] ?? 'rss',
            'cluster_hint'            => $_POST['cluster_hint'] ?? 'curiosidades_geral',
            'site_target'             => $_POST['site_target'] ?? 'auto',
            'intervalo_min'           => (int)($_POST['intervalo_min'] ?? 15),
            'max_itens_por_fetch'     => (int)($_POST['max_itens_por_fetch'] ?? 30),
            'auto_aprovar_score_min'  => (float)($_POST['auto_aprovar_score_min'] ?? 7.0),
            'ativo'                   => !empty($_POST['ativo']),
            'notas'                   => $_POST['notas'] ?? '',
        ]);
        header('Location: pingo.php?msg=atualizado&site=' . urlencode($siteSlug)); exit;
    } catch (Throwable $e) { $erro = $e->getMessage(); }
}

if ($acao === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $f = $pingo->fontePorId($id);
    if ($f) $pingo->atualizarFonte($id, ['ativo' => !$f['ativo']]);
    header('Location: pingo.php?site=' . urlencode($siteSlug)); exit;
}

if ($acao === 'remover' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pingo->removerFonte((int)($_POST['id'] ?? 0));
    header('Location: pingo.php?msg=removido&site=' . urlencode($siteSlug)); exit;
}

$fontes = $pingo->listarFontes();
$state = $pingo->estadoAtual();
$editando = null;
if ($acao === 'editar') $editando = $pingo->fontePorId((int)($_GET['id'] ?? 0));

// Stats agregados
$agregados = ['vistos_total' => 0, 'salvos_total' => 0, 'fontes_com_erro' => 0, 'capturados_7d' => 0];
$corte7d = strtotime('-7 days');
foreach ($fontes as $f) {
    $fid = (int)$f['id'];
    $s = $state['fontes'][$fid] ?? null;
    if (!$s) continue;
    $agregados['vistos_total'] += (int)($s['contador_items_vistos_total'] ?? 0);
    $agregados['salvos_total'] += (int)($s['contador_items_salvos_total'] ?? 0);
    if (!empty($s['ultimo_erro'])) $agregados['fontes_com_erro']++;
    foreach (($s['historico'] ?? []) as $h) {
        if (strtotime($h['ts'] ?? '') >= $corte7d) $agregados['capturados_7d'] += (int)($h['salvos'] ?? 0);
    }
}

// Últimos 10 trends capturados via pingo
$trendsRecentes = array_filter($db->all(['site' => $siteSlug]), fn($r) => str_starts_with((string)($r['origem'] ?? ''), 'pingo:'));
usort($trendsRecentes, fn($a, $b) => strtotime((string)($b['data_detectada'] ?? '')) <=> strtotime((string)($a['data_detectada'] ?? '')));
$trendsRecentes = array_slice($trendsRecentes, 0, 10);

$mensagemTop = '';
if (!empty($_GET['msg'])) {
    $m = (string)$_GET['msg'];
    $mensagemTop = match($m) {
        'adicionado' => 'Fonte adicionada ao catálogo.',
        'atualizado' => 'Fonte atualizada.',
        'removido'   => 'Fonte removida.',
        default      => '',
    };
}
?><!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pingo — Portal Discover</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:#0f1115;color:#e2e8f0;line-height:1.5}
.container{max-width:1200px;margin:0 auto;padding:20px}
h1{color:#a78bfa;margin:0 0 4px;font-size:22px}
.muted{color:#6b7280;font-size:12px}
.box{background:#13161d;border:1px solid #1f232b;border-radius:10px;padding:18px;margin:16px 0}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:8px 10px;text-align:left;border-bottom:1px solid #1f232b;font-size:12.5px;vertical-align:top}
th{background:#0b0d12;color:#a78bfa;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;font-weight:700}
tr.inativa{opacity:.5}
tr.com-erro{background:rgba(239,68,68,.08)}
.badge{display:inline-block;padding:3px 8px;border-radius:4px;font-size:10.5px;font-weight:700}
.b-ativo{background:#14532d;color:#86efac}
.b-inativo{background:#292524;color:#78716c}
.b-cluster{background:#1e1b4b;color:#a5b4fc}
.b-site{background:#164e63;color:#a5f3fc}
.b-stats{background:#1e293b;color:#93c5fd;font-family:'Courier New',monospace}
.b-erro{background:#7f1d1d;color:#fecaca;font-family:monospace}
input,select,textarea{background:#0f1115;border:1px solid #2a2e38;color:#e2e8f0;padding:8px 10px;border-radius:6px;font-size:13px;font-family:inherit;width:100%}
input:focus,select:focus,textarea:focus{outline:none;border-color:#a78bfa}
label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#a78bfa;font-weight:700;margin-bottom:4px;margin-top:10px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
@media(max-width:768px){.grid-2,.grid-3{grid-template-columns:1fr}}
.btn{display:inline-block;padding:8px 14px;background:#7c3aed;color:#fff;border:none;border-radius:6px;font-weight:700;cursor:pointer;font-size:13px;text-decoration:none}
.btn:hover{background:#6d28d9}
.btn-sm{padding:4px 8px;font-size:11px;border-radius:4px}
.btn-secondary{background:#1e293b;color:#93c5fd}
.btn-secondary:hover{background:#334155}
.btn-danger{background:#450a0a;color:#fca5a5;border:1px solid #991b1b}
.btn-danger:hover{background:#991b1b;color:#fff}
.btn-run{background:#14532d;color:#86efac;border:1px solid #16a34a}
.btn-run:hover{background:#16a34a;color:#fff}
.btn-run.running{background:#ca8a04;color:#fef3c7;cursor:wait}
.msg-ok{padding:10px 14px;background:#064e3b;color:#86efac;border-radius:6px;margin-bottom:14px}
.msg-erro{padding:10px 14px;background:#7f1d1d;color:#fecaca;border-radius:6px;margin-bottom:14px}
.nav a{color:#a78bfa;text-decoration:none;margin-right:18px;font-size:13px}
.nav a:hover{color:#c4b5fd}
.stat-box{display:inline-block;padding:10px 14px;background:#0b0d12;border:1px solid #2a2e38;border-radius:8px;margin-right:10px;margin-bottom:6px}
.stat-box .n{font-size:22px;font-weight:800;color:#e2e8f0;font-family:monospace}
.stat-box .l{font-size:10px;color:#a78bfa;text-transform:uppercase;letter-spacing:.3px;font-weight:700}
.checkbox-row{display:flex;align-items:center;gap:6px;margin-top:10px}
.checkbox-row input{width:auto}
.checkbox-row label{display:inline;margin:0;text-transform:none;letter-spacing:normal;color:#cbd5e1;font-size:13px;font-weight:400}
code{background:#0b0d12;padding:1px 6px;border-radius:3px;font-size:11px;color:#fbbf24}
</style>
</head>
<body>
<div class="container">
  <div class="nav">
    <a href="portal.php?site=<?= h($siteSlug) ?>">← Portal</a>
    <a href="afiliados.php">🎯 Afiliados</a>
    <a href="pingo.php?site=<?= h($siteSlug) ?>">📡 Pingo</a>
    <?php if (!$editando): ?>
      <a href="#form" style="color:#4ade80">+ Nova fonte</a>
    <?php endif; ?>
  </div>

  <h1>📡 Pingo — captura automática de trends via RSS</h1>
  <p class="muted">Site ativo: <strong><?= h($cfg['_site_name'] ?? $siteSlug) ?></strong>. Roda via cron/Task Scheduler.</p>

  <?php if ($mensagemTop): ?><div class="msg-ok">✓ <?= h($mensagemTop) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="msg-erro">✗ <?= h($erro) ?></div><?php endif; ?>

  <div class="box" style="padding:14px 18px">
    <div class="stat-box"><div class="n"><?= count($fontes) ?></div><div class="l">Fontes cadastradas</div></div>
    <div class="stat-box"><div class="n"><?= count(array_filter($fontes, fn($f) => $f['ativo'])) ?></div><div class="l">Ativas</div></div>
    <div class="stat-box"><div class="n"><?= $agregados['capturados_7d'] ?></div><div class="l">Trends 7 dias</div></div>
    <div class="stat-box"><div class="n"><?= $agregados['salvos_total'] ?></div><div class="l">Total histórico</div></div>
    <?php if ($agregados['fontes_com_erro'] > 0): ?>
      <div class="stat-box" style="border-color:#991b1b"><div class="n" style="color:#fca5a5"><?= $agregados['fontes_com_erro'] ?></div><div class="l" style="color:#fca5a5">Fontes com erro</div></div>
    <?php endif; ?>
  </div>

  <div class="box">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
      <h2 style="margin:0;color:#e2e8f0;font-size:16px">Catálogo de fontes</h2>
      <button type="button" id="rodar-todas" class="btn btn-run">▶ Rodar todas agora</button>
    </div>
    <div id="rodar-resultado" style="display:none;margin-bottom:10px;padding:10px 14px;border-radius:6px;font-size:12px;font-family:monospace;white-space:pre-wrap"></div>

    <?php if (empty($fontes)): ?>
      <p class="muted">Nenhuma fonte cadastrada. Cadastre abaixo.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th>#</th><th>Nome / URL</th><th>Cluster</th><th>Site alvo</th>
        <th>Intervalo</th><th>7d</th><th>Última exec</th><th>Status</th><th>Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($fontes as $f):
        $fid = (int)$f['id'];
        $s = $state['fontes'][$fid] ?? null;
        $ultima = $s['ultima_execucao'] ?? null;
        $erroAtual = $s['ultimo_erro'] ?? null;
        $items7d = 0;
        foreach (($s['historico'] ?? []) as $hist) {
          if (strtotime($hist['ts'] ?? '') >= $corte7d) $items7d += (int)($hist['salvos'] ?? 0);
        }
        $classeTr = !$f['ativo'] ? 'inativa' : ($erroAtual ? 'com-erro' : '');
      ?>
        <tr class="<?= $classeTr ?>">
          <td class="muted">#<?= $fid ?></td>
          <td>
            <strong><?= h($f['nome']) ?></strong>
            <div class="muted" style="margin-top:3px;font-size:10.5px;word-break:break-all"><?= h(mb_substr($f['url_rss'], 0, 80)) ?><?= mb_strlen($f['url_rss']) > 80 ? '…' : '' ?></div>
            <?php if (!empty($f['notas'])): ?>
              <div class="muted" style="margin-top:3px;font-size:10.5px;font-style:italic">💬 <?= h($f['notas']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge b-cluster"><?= TrendsTaxonomia::emojiRoi($f['cluster_hint']) ?> <?= h(TrendsTaxonomia::labelCurto($f['cluster_hint'])) ?></span>
            <div class="muted" style="margin-top:3px;font-size:10px">score min: <?= (float)$f['auto_aprovar_score_min'] ?></div>
          </td>
          <td><span class="badge b-site"><?= h($f['site_target']) ?></span></td>
          <td class="muted"><?= (int)$f['intervalo_min'] ?> min<br><span style="font-size:10px">máx <?= (int)$f['max_itens_por_fetch'] ?>/fetch</span></td>
          <td><span class="badge b-stats"><?= $items7d ?></span></td>
          <td class="muted" style="font-size:10.5px">
            <?= $ultima ? h(date('d/m H:i', strtotime($ultima))) : '—' ?>
            <?php if ($erroAtual): ?>
              <div style="margin-top:3px"><span class="badge b-erro" title="<?= h($erroAtual) ?>">⚠ erro</span></div>
            <?php endif; ?>
          </td>
          <td><span class="badge <?= $f['ativo'] ? 'b-ativo' : 'b-inativo' ?>"><?= $f['ativo'] ? 'Ativa' : 'Inativa' ?></span></td>
          <td style="white-space:nowrap">
            <button type="button" class="btn btn-sm btn-run rodar-fonte" data-id="<?= $fid ?>" title="Rodar esta fonte agora">▶</button>
            <a href="pingo.php?acao=editar&id=<?= $fid ?>&site=<?= h($siteSlug) ?>" class="btn btn-sm btn-secondary">✏️</a>
            <form method="POST" style="display:inline">
              <input type="hidden" name="acao" value="toggle">
              <input type="hidden" name="id" value="<?= $fid ?>">
              <button class="btn btn-sm btn-secondary" title="<?= $f['ativo'] ? 'Desativar' : 'Ativar' ?>"><?= $f['ativo'] ? '⏸' : '▶' ?></button>
            </form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Remover fonte <?= h($f['nome']) ?>?')">
              <input type="hidden" name="acao" value="remover">
              <input type="hidden" name="id" value="<?= $fid ?>">
              <button class="btn btn-sm btn-danger">🗑</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($trendsRecentes)): ?>
  <div class="box">
    <h2 style="margin:0 0 8px;color:#e2e8f0;font-size:15px">Últimas 10 capturas</h2>
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>ID</th><th>Termo</th><th>Cluster</th><th>Score</th><th>Origem</th><th>Data</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($trendsRecentes as $t):
        $ck = $t['cluster_detect']['key'] ?? 'curiosidades_geral';
        $origemId = preg_replace('/^pingo:/', '', (string)$t['origem']);
        $fonte = $pingo->fontePorId((int)$origemId);
        $fonteName = $fonte['nome'] ?? "fonte #{$origemId}";
      ?>
        <tr>
          <td class="muted">#<?= (int)$t['id'] ?></td>
          <td><?= h(mb_substr($t['termo'], 0, 80)) ?></td>
          <td><span class="badge b-cluster"><?= TrendsTaxonomia::labelCurto($ck) ?></span></td>
          <td><code><?= number_format((float)$t['score_discover'], 2, ',', '') ?></code></td>
          <td class="muted" style="font-size:10.5px"><?= h(mb_substr($fonteName, 0, 32)) ?></td>
          <td class="muted" style="font-size:10.5px"><?= h(date('d/m H:i', strtotime($t['data_detectada']))) ?></td>
          <td><span class="badge <?= ($t['status']??'')==='aprovado'?'b-ativo':'b-inativo' ?>"><?= h($t['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div style="margin-top:8px;font-size:11px;color:#6b7280">
      Veja todos em <a href="portal.php?site=<?= h($siteSlug) ?>&view=saved" style="color:#60a5fa">portal → Ver salvos</a> (filtre por origem: pingo).
    </div>
  </div>
  <?php endif; ?>

  <div class="box" id="form">
    <h2 style="margin:0 0 12px;color:#e2e8f0;font-size:16px">
      <?= $editando ? '✏️ Editar fonte #' . (int)$editando['id'] : '➕ Nova fonte' ?>
    </h2>
    <form method="POST">
      <input type="hidden" name="acao" value="<?= $editando ? 'atualizar' : 'adicionar' ?>">
      <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int)$editando['id'] ?>"><?php endif; ?>

      <div class="grid-2">
        <div>
          <label>Nome da fonte *</label>
          <input name="nome" required maxlength="80" value="<?= h($editando['nome'] ?? '') ?>" placeholder="ex: G1 Economia">
        </div>
        <div>
          <label>Tipo</label>
          <select name="tipo">
            <?php foreach (['rss','atom'] as $t): ?>
              <option value="<?= $t ?>" <?= ($editando['tipo'] ?? 'rss') === $t ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <label>URL do feed RSS/Atom *</label>
      <input name="url_rss" type="url" required value="<?= h($editando['url_rss'] ?? '') ?>" placeholder="https://...">

      <div class="grid-3">
        <div>
          <label>Cluster hint</label>
          <select name="cluster_hint">
            <?php foreach (TrendsTaxonomia::chaves() as $ck): ?>
              <option value="<?= h($ck) ?>" <?= ($editando['cluster_hint'] ?? '') === $ck ? 'selected' : '' ?>>
                <?= TrendsTaxonomia::emojiRoi($ck) ?> <?= h(TrendsTaxonomia::labelCurto($ck)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Site target</label>
          <select name="site_target">
            <option value="auto" <?= ($editando['site_target'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>auto (usa site ativo)</option>
            <?php foreach ($sites as $ss => $info): ?>
              <option value="<?= h($ss) ?>" <?= ($editando['site_target'] ?? '') === $ss ? 'selected' : '' ?>><?= h($info['name'] ?? $ss) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Ativa?</label>
          <div class="checkbox-row">
            <input type="checkbox" name="ativo" id="ativo" <?= ($editando['ativo'] ?? true) ? 'checked' : '' ?>>
            <label for="ativo">Fonte ativa</label>
          </div>
        </div>
      </div>

      <div class="grid-3">
        <div>
          <label>Intervalo (min)</label>
          <input type="number" min="1" max="1440" name="intervalo_min" value="<?= (int)($editando['intervalo_min'] ?? 15) ?>">
        </div>
        <div>
          <label>Máx items / fetch</label>
          <input type="number" min="1" max="100" name="max_itens_por_fetch" value="<?= (int)($editando['max_itens_por_fetch'] ?? 30) ?>">
        </div>
        <div>
          <label>Score p/ auto-aprovar</label>
          <input type="number" step="0.1" min="0" max="10" name="auto_aprovar_score_min" value="<?= (float)($editando['auto_aprovar_score_min'] ?? 7.0) ?>">
        </div>
      </div>

      <label>Notas editoriais</label>
      <textarea name="notas" rows="2" placeholder="ex: usar pra capturar recalls automotivos" maxlength="300"><?= h($editando['notas'] ?? '') ?></textarea>

      <div style="margin-top:16px">
        <button type="submit" class="btn"><?= $editando ? 'Salvar alterações' : '+ Cadastrar fonte' ?></button>
        <?php if ($editando): ?><a href="pingo.php?site=<?= h($siteSlug) ?>" class="btn btn-secondary">Cancelar</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="box" style="background:#0b1a0f;border:1px solid #16a34a">
    <h2 style="margin:0 0 10px;color:#4ade80;font-size:14px">⏰ Automação via cron/agendador</h2>
    <div style="font-size:12px;color:#d1d5db;line-height:1.6">
      <strong>Linux (crontab -e):</strong>
      <pre style="background:#0f1115;padding:8px 10px;border-radius:4px;overflow-x:auto;font-size:11px">*/10 * * * * /usr/bin/php <?= h(realpath(__DIR__)) ?>/scripts/pingo.php --site=<?= h($siteSlug) ?> >> <?= h(realpath(__DIR__)) ?>/data/pingo.log 2>&1</pre>

      <strong style="margin-top:8px;display:inline-block">Windows (Task Scheduler — criar tarefa básica):</strong>
      <ul style="margin:4px 0;padding-left:22px">
        <li>Trigger: "diariamente · a cada 10 minutos"</li>
        <li>Ação: <code>C:\xampp\php\php.exe</code></li>
        <li>Argumentos: <code>"<?= h(realpath(__DIR__) ?: __DIR__) ?>\scripts\pingo.php" --site=<?= h($siteSlug) ?></code></li>
      </ul>
    </div>
  </div>

</div>

<script>
(function() {
  // Lock global contra dupla-clique — 2 ciclos paralelos = duplicatas no DB.
  // Nenhuma execução nova começa enquanto outra está em andamento.
  let cicloEmExecucao = false;

  function setRunning(btn, running) {
    if (!btn) return;
    if (running) { btn.classList.add('running'); btn.disabled = true; btn.dataset.origText = btn.textContent; btn.textContent = '…'; }
    else { btn.classList.remove('running'); btn.disabled = false; if (btn.dataset.origText) btn.textContent = btn.dataset.origText; }
  }
  function bloquearTodos(running) {
    document.querySelectorAll('.btn-run').forEach(b => {
      b.disabled = running;
      if (running) { b.classList.add('running'); }
      else { b.classList.remove('running'); }
    });
  }
  function mostrarResultado(r) {
    const box = document.getElementById('rodar-resultado');
    if (!box) return;
    box.style.display = 'block';
    if (!r.ok) {
      box.style.background = '#7f1d1d';
      box.style.color = '#fecaca';
      box.textContent = '✗ Erro: ' + (r.erro || 'desconhecido');
      return;
    }
    box.style.background = '#14532d';
    box.style.color = '#86efac';
    const rel = r.relatorio;
    const linhas = [
      `✓ Ciclo completo em ${(rel.tempo_ms/1000).toFixed(1)}s`,
      `Fontes: ${rel.fontes_processadas} processadas · ${rel.fontes_skipped} skipped · ${rel.erros.length} erros`,
      `Items: ${rel.items_vistos} vistos · ${rel.items_novos} novos · ${rel.items_salvos} salvos`,
    ];
    if (rel.erros.length > 0) {
      linhas.push('\nErros:');
      rel.erros.forEach(e => linhas.push(`  fonte #${e.fonte_id}: ${e.erro}`));
    }
    box.textContent = linhas.join('\n');
    setTimeout(() => location.reload(), 2500);
  }
  async function rodar(fonteId) {
    if (cicloEmExecucao) {
      // Sinaliza pro user que está bloqueado (evita silêncio confuso)
      const box = document.getElementById('rodar-resultado');
      if (box) {
        box.style.display = 'block';
        box.style.background = '#78350f';
        box.style.color = '#fcd34d';
        box.textContent = '⏳ Outro ciclo já está em execução. Aguarde terminar.';
        setTimeout(() => { box.style.display = 'none'; }, 3000);
      }
      return;
    }
    cicloEmExecucao = true;
    bloquearTodos(true);
    const btn = fonteId ? document.querySelector(`.rodar-fonte[data-id="${fonteId}"]`) : document.getElementById('rodar-todas');
    setRunning(btn, true);
    try {
      const url = 'pingo.php?ajax=rodar' + (fonteId ? '&fonte_id=' + fonteId : '') + '&site=<?= h($siteSlug) ?>';
      const resp = await fetch(url, { method: 'GET' });
      const data = await resp.json();
      mostrarResultado(data);
    } catch (e) {
      mostrarResultado({ ok: false, erro: e.message });
    } finally {
      setRunning(btn, false);
      bloquearTodos(false);
      cicloEmExecucao = false;
    }
  }
  document.addEventListener('click', (e) => {
    if (e.target.id === 'rodar-todas') { e.preventDefault(); rodar(null); }
    const btnFonte = e.target.closest('.rodar-fonte');
    if (btnFonte) { e.preventDefault(); rodar(parseInt(btnFonte.dataset.id)); }
  });
})();
</script>
</body>
</html>
