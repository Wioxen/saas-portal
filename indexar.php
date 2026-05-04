<?php
/**
 * Indexação inteligente — verifica e solicita indexação de posts.
 *
 * Fluxo:
 *  Fase 1: usuário escolhe site + horas mínimas (default 72)
 *  Fase 2: sistema lista posts publicados há ≥X horas e checa cada URL no Google via site:URL
 *  Fase 3: usuário dispara indexação das URLs não indexadas (batch ou individual)
 *
 * Indexação usa o plugin cc-instant-indexing-api no WP (Rank Math Instant Indexing com
 * fallback automático para IndexNow).
 */
require_once __DIR__ . '/lib/Serper.php';
require_once __DIR__ . '/lib/Wordpress.php';
require_once __DIR__ . '/lib/InstantIndexing.php';

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

$fase        = $_POST['fase'] ?? 'listar';
$horas       = (int)($_POST['horas'] ?? 72);
$posts       = [];
$erro        = null;
$resultados  = [];
$processado  = false;
$jaChecado   = false;

// Fase 1: listar + checar
if ($fase === 'listar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        set_time_limit(300);
        $raw = $wp->listarPostsParaIndexar(max(1, $horas), 60);
        $serper = new Serper($cfg['serper_api_key']);
        foreach ($raw as $p) {
            $link = $p['link'] ?? '';
            if ($link === '') continue;
            $indexed = false;
            try {
                $indexed = $serper->checarIndexacao($link);
            } catch (Throwable $e) {}
            $p['indexed'] = $indexed;
            $posts[] = $p;
        }
        $jaChecado = true;
    } catch (Throwable $e) {
        $erro = 'Erro ao listar/checar: ' . $e->getMessage();
    }
}

// Fase 2: solicitar indexação
if ($fase === 'indexar') {
    $processado = true;
    set_time_limit(0);
    $selected = json_decode($_POST['selected_json'] ?? '[]', true) ?: [];
    if (empty($selected)) {
        $erro = 'Nenhuma URL selecionada.';
    } else {
        $idx = new InstantIndexing($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        foreach ($selected as $i => $item) {
            $url = $item['link'] ?? '';
            $id  = (int)($item['id'] ?? 0);
            $title = $item['title'] ?? '';
            $r = ['id' => $id, 'title' => $title, 'link' => $url, 'ok' => false, 'msg' => '', 'method' => ''];
            if ($url === '') { $r['msg'] = 'URL vazia'; $resultados[] = $r; continue; }
            try {
                $res = $idx->indexar($url, 'URL_UPDATED');
                $r['ok']     = !empty($res['success']);
                $r['method'] = $res['method'] ?? '';
                $r['msg']    = $res['error'] ?? ($r['ok'] ? 'Solicitação enviada' : 'Falha');
            } catch (Throwable $e) {
                $r['msg'] = $e->getMessage();
            }
            $resultados[] = $r;
            if ($i < count($selected) - 1) usleep(500000); // 0.5s entre chamadas
        }
    }
}

$totalOk  = count(array_filter($resultados, fn($r) => $r['ok']));
$totalErr = count($resultados) - $totalOk;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Indexação — checar e solicitar</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:1100px;margin:0 auto}
h1{color:#fff;margin:0 0 4px}
.sub{color:#666;margin-bottom:20px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:22px;border-radius:10px;margin-bottom:16px}
.box h2{margin-top:0;font-size:18px}
label{display:block;font-weight:600;margin:10px 0 6px;font-size:13px;color:#bbb}
input[type=number]{width:140px;padding:12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:15px}
input:focus{outline:none;border-color:#6366f1}
button,.btn{padding:13px 22px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;display:inline-block;text-decoration:none}
.btn-go{background:linear-gradient(135deg,#10b981,#059669);width:100%;margin-top:14px;padding:16px}
.erro{background:#3b1818;border-left:4px solid #ef4444;padding:14px;border-radius:6px;margin-bottom:16px;color:#fca5a5}
.row{display:flex;gap:14px;align-items:end;flex-wrap:wrap}
.hint{font-size:11px;color:#444;margin-top:4px}
.p-table{width:100%;border-collapse:collapse;margin:12px 0;font-size:13px}
.p-table th{text-align:left;padding:8px 10px;background:#0f1115;color:#888;font-size:11px;text-transform:uppercase;border-bottom:2px solid #2a2e38}
.p-table td{padding:8px 10px;border-bottom:1px solid #1e2230;vertical-align:top}
.p-table tr:hover{background:#1e2230}
.p-title{font-weight:700;color:#e0e0e0;font-size:13px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:800}
.b-yes{background:#1a2e1a;color:#4ade80}
.b-no{background:#3b1818;color:#fca5a5}
.result{background:#111318;border:1px solid #2a2e38;border-radius:8px;padding:12px 16px;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;font-size:13px}
.result.ok{border-left:4px solid #22c55e}
.result.fail{border-left:4px solid #ef4444}
.resumo{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:14px 0}
.resumo-item{text-align:center;background:#0f1115;padding:14px;border-radius:6px}
.resumo-item strong{display:block;font-size:24px;color:#10b981}
.resumo-item span{font-size:11px;color:#666}
.check-ctrl{display:flex;gap:8px;margin:8px 0}
.check-ctrl button{padding:6px 12px;font-size:12px;background:#1e2230;border:1px solid #2a2e38;border-radius:6px;color:#ccc}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
code{background:#0f1115;padding:2px 6px;border-radius:4px;font-size:11px;color:#a78bfa}
</style>
</head>
<body>
<div class="container">
  <h1>🔎 Indexação — checar e solicitar</h1>
  <p class="sub">Verifica se cada post publicado há ≥X horas está no Google via <code>site:URL</code>. Os não indexados podem ser enviados ao Rank Math Instant Indexing (com fallback IndexNow).</p>

  <?php if ($erro): ?><div class="erro"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

  <?php if ($processado && !empty($resultados)): ?>
    <div class="box">
      <h2>Resultado da indexação</h2>
      <div class="resumo">
        <div class="resumo-item"><strong><?= $totalOk ?></strong><span>enviadas</span></div>
        <div class="resumo-item"><strong><?= $totalErr ?></strong><span>falhas</span></div>
        <div class="resumo-item"><strong><?= count($resultados) ?></strong><span>total</span></div>
      </div>
      <?php foreach ($resultados as $r): ?>
        <div class="result <?= $r['ok'] ? 'ok' : 'fail' ?>">
          <div style="flex:1;min-width:0">
            <div><strong><?= htmlspecialchars($r['title']) ?></strong>
                 <?php if ($r['method']): ?><span class="badge b-yes" style="margin-left:6px"><?= htmlspecialchars($r['method']) ?></span><?php endif; ?></div>
            <div style="font-size:11px;color:#666;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($r['link']) ?></div>
            <?php if (!empty($r['msg'])): ?><div style="color:#888;font-size:11px;margin-top:2px"><?= htmlspecialchars($r['msg']) ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <p style="margin-top:14px"><a href="indexar.php" class="btn">← Nova verificação</a></p>
    </div>
  <?php endif; ?>

  <?php if (!$processado): ?>
    <form method="POST">
      <input type="hidden" name="fase" value="listar">
      <?php include __DIR__ . '/_site_select.php'; ?>
      <div class="box">
        <h2>1. Parâmetros da verificação</h2>
        <div class="row">
          <div>
            <label>Publicados há pelo menos (horas)</label>
            <input type="number" name="horas" min="1" max="8760" value="<?= htmlspecialchars((string)$horas) ?>">
          </div>
          <button type="submit">🔎 Listar e checar</button>
        </div>
        <p class="hint">Google precisa de tempo pra indexar naturalmente — 72h é o piso recomendado. Cada post gasta 1 créd. Serper.</p>
      </div>
    </form>

    <?php if ($jaChecado && !empty($posts)):
      $naoIndexados = array_filter($posts, fn($p) => !$p['indexed']);
      $totalNaoIdx  = count($naoIndexados);
    ?>
      <form method="POST" id="idxForm">
        <input type="hidden" name="fase" value="indexar">
        <input type="hidden" name="selected_json" id="sel-json" value="">
        <?php include __DIR__ . '/_site_select.php'; ?>

        <div class="box">
          <h2>2. <?= count($posts) ?> posts verificados — <?= $totalNaoIdx ?> não indexados</h2>
          <div class="check-ctrl">
            <button type="button" onclick="toggleAll(true)">Marcar todos</button>
            <button type="button" onclick="toggleAll(false)">Desmarcar</button>
            <button type="button" onclick="toggleNaoIdx()">Marcar só não indexados</button>
          </div>
          <table class="p-table">
            <thead><tr><th>✓</th><th>Título / URL</th><th>Idade</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($posts as $i => $p): ?>
                <tr>
                  <td><input type="checkbox" class="p-check" data-idx="<?= $i ?>" data-indexed="<?= $p['indexed'] ? '1' : '0' ?>" <?= $p['indexed'] ? '' : 'checked' ?>></td>
                  <td>
                    <div class="p-title"><?= htmlspecialchars($p['title']) ?></div>
                    <a href="<?= htmlspecialchars($p['link']) ?>" target="_blank" style="font-size:11px"><?= htmlspecialchars($p['link']) ?> ↗</a>
                  </td>
                  <td style="color:#888;font-size:12px"><?= (int)$p['age_hours'] ?>h</td>
                  <td><span class="badge <?= $p['indexed'] ? 'b-yes' : 'b-no' ?>"><?= $p['indexed'] ? 'indexado' : 'não indexado' ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <button type="submit" class="btn-go" onclick="prepareJson()">📤 Solicitar indexação dos selecionados</button>
        <p class="hint" style="text-align:center;margin-top:8px">Usa Rank Math Instant Indexing (Google). Se o plugin não estiver configurado, faz fallback automático para IndexNow.</p>
      </form>

      <script>
      const postsData = <?= json_encode($posts, JSON_UNESCAPED_UNICODE) ?>;
      function toggleAll(v) { document.querySelectorAll('.p-check').forEach(cb => cb.checked = v); }
      function toggleNaoIdx() {
        document.querySelectorAll('.p-check').forEach(cb => {
          cb.checked = cb.dataset.indexed === '0';
        });
      }
      function prepareJson() {
        const sel = [];
        document.querySelectorAll('.p-check:checked').forEach(cb => {
          const idx = parseInt(cb.dataset.idx);
          const p = postsData[idx];
          sel.push({id: p.id, title: p.title, link: p.link});
        });
        document.getElementById('sel-json').value = JSON.stringify(sel);
      }
      </script>
    <?php elseif ($jaChecado): ?>
      <div class="box"><p>Nenhum post encontrado com ≥<?= $horas ?>h desde a publicação.</p></div>
    <?php endif; ?>
  <?php endif; ?>

  <p style="text-align:center;color:#333;font-size:12px;margin-top:24px">
    <a href="atualizar.php">Atualizar posts</a> · <a href="gerarimagem.php">Gerar imagem</a> · <a href="massa.php">Em massa</a> · <a href="categorias.php">Categorias</a> · <a href="trending.php">Trending</a>
  </p>
</div>
</body>
</html>
