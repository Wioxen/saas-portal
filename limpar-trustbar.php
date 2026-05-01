<?php
/**
 * Script one-shot: remove o bloco de trust bar ("✓ N modelos analisados /
 * +2.300 avaliações verificadas / +80h de pesquisa / Última atualização: ...")
 * de todos os posts publicados do site ativo.
 *
 * Uso:
 *   limpar-trustbar.php?site=comocomprar          → dry-run (só lista)
 *   limpar-trustbar.php?site=comocomprar&confirm=1 → aplica
 *
 * APAGAR ESTE ARQUIVO DEPOIS DE USAR.
 */
require_once __DIR__ . '/lib/Wordpress.php';
$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

set_time_limit(0);

$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

// Regex principal: ancora no <div class="cc-card__meta" ... rgba(22,163,74 ...>
// e fecha no </div> após o span "Última atualização".
// Tolera aspas simples ou duplas nos atributos HTML.
$pattern = '#<div\s+class=(["\'])cc-card__meta\1[^>]*rgba\(22,163,74[^>]*>.*?Última atualização[^<]*</span>\s*</div>#us';

// Coleta todos os posts publicados, paginando.
$candidatos = [];
$erros = [];
$pagina = 1;
while (true) {
    try {
        $lote = $wp->listarPosts($pagina, 100);
    } catch (Throwable $e) {
        $erros[] = "Página {$pagina}: " . $e->getMessage();
        break;
    }
    if (!is_array($lote) || empty($lote)) break;
    foreach ($lote as $p) {
        $id = (int)($p['id'] ?? 0);
        if (!$id) continue;
        // Heurística rápida na response pública pra só pedir ?context=edit nos suspeitos.
        $rendered = $p['content']['rendered'] ?? '';
        if (!str_contains($rendered, 'modelos analisados') && !str_contains($rendered, 'Última atualização:')) continue;
        $candidatos[] = [
            'id'    => $id,
            'title' => html_entity_decode(strip_tags($p['title']['rendered'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'link'  => $p['link'] ?? '',
        ];
    }
    if (count($lote) < 100) break;
    $pagina++;
}

$resultados = [];
if ($confirm && !empty($candidatos)) {
    foreach ($candidatos as $c) {
        $id = $c['id'];
        try {
            $full = $wp->getPost($id);
            $raw = $full['content']['raw'] ?? '';
            if ($raw === '') { $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'skip', 'msg' => 'raw vazio']; continue; }

            $novo = preg_replace($pattern, '', $raw);
            if ($novo === null) { $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'erro', 'msg' => 'regex PCRE falhou']; continue; }
            if ($novo === $raw) { $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'skip', 'msg' => 'bloco não casou no raw']; continue; }

            $wp->atualizarPost($id, ['content' => $novo]);
            $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'ok', 'msg' => 'bloco removido'];
        } catch (Throwable $e) {
            $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'erro', 'msg' => $e->getMessage()];
        }
    }
}

$okCount  = count(array_filter($resultados, fn($r) => $r['status'] === 'ok'));
$errCount = count(array_filter($resultados, fn($r) => $r['status'] === 'erro'));
$skipCount= count(array_filter($resultados, fn($r) => $r['status'] === 'skip'));
?>
<!DOCTYPE html>
<html lang='pt-br'>
<head>
<meta charset='UTF-8'>
<title>Limpar Trust Bar — <?= htmlspecialchars($cfg['_site_name'] ?? $siteSlug) ?></title>
<style>
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:980px;margin:0 auto}
h1{color:#fff;margin:0 0 6px}
.sub{color:#666;margin-bottom:18px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:18px 22px;border-radius:10px;margin-bottom:14px}
.box h2{margin-top:0;font-size:16px;color:#e0e0e0}
.btn{display:inline-block;padding:12px 22px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;text-decoration:none;cursor:pointer}
.btn-dry{background:linear-gradient(135deg,#6366f1,#8b5cf6)}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px}
th{text-align:left;padding:8px 10px;background:#0f1115;color:#888;font-size:11px;text-transform:uppercase;border-bottom:2px solid #2a2e38}
td{padding:8px 10px;border-bottom:1px solid #1e2230;vertical-align:middle}
.tag-ok{color:#22c55e;font-weight:700}
.tag-erro{color:#ef4444;font-weight:700}
.tag-skip{color:#a78bfa;font-weight:700}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
.resumo{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:10px 0}
.resumo-item{text-align:center;background:#0f1115;padding:14px;border-radius:6px}
.resumo-item strong{display:block;font-size:22px;color:#a78bfa}
.resumo-item span{font-size:11px;color:#666}
</style>
</head>
<body>
<div class='container'>
  <h1>🧹 Limpar Trust Bar</h1>
  <p class='sub'>Remove o bloco "✓ N modelos analisados / +2.300 avaliações verificadas / +80h de pesquisa / Última atualização" dos posts.</p>

  <div class='box'>
    <h2>Site ativo</h2>
    <p><strong><?= htmlspecialchars($cfg['_site_name'] ?? $siteSlug) ?></strong> · <?= htmlspecialchars($cfg['wp_url'] ?? '') ?></p>
    <p style='font-size:12px;color:#666'>Troque via ?site=slug — disponíveis: <?= implode(', ', array_keys($sites)) ?></p>
  </div>

  <?php if (!empty($erros)): ?>
    <div class='box' style='border-left:4px solid #ef4444'>
      <h2 style='color:#fca5a5'>Erros de listagem</h2>
      <?php foreach ($erros as $e): ?><p style='color:#fca5a5;font-size:13px'><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class='box'>
    <h2><?= count($candidatos) ?> post(s) com o bloco detectado</h2>
    <?php if (empty($candidatos)): ?>
      <p style='color:#888'>Nenhum post contém a assinatura do bloco. Nada a limpar.</p>
    <?php else: ?>
      <?php if (!$confirm): ?>
        <p style='color:#888;font-size:13px'>Modo <strong>dry-run</strong>: nada foi alterado. Confira a lista abaixo e aplique quando estiver pronto.</p>
        <p style='margin-top:14px'>
          <a class='btn' href='?site=<?= urlencode($siteSlug) ?>&confirm=1' onclick="return confirm('Aplicar remoção em <?= count($candidatos) ?> post(s) de <?= htmlspecialchars($cfg['_site_name'] ?? $siteSlug) ?>?')">⚠️ Aplicar limpeza</a>
          <a class='btn btn-dry' style='margin-left:8px' href='?site=<?= urlencode($siteSlug) ?>'>Recarregar dry-run</a>
        </p>
      <?php else: ?>
        <div class='resumo'>
          <div class='resumo-item'><strong><?= $okCount ?></strong><span>removidos</span></div>
          <div class='resumo-item'><strong><?= $skipCount ?></strong><span>pulados</span></div>
          <div class='resumo-item'><strong><?= $errCount ?></strong><span>erros</span></div>
        </div>
      <?php endif; ?>

      <table>
        <thead><tr><th>ID</th><th>Título</th><?php if ($confirm): ?><th>Status</th><th>Msg</th><?php else: ?><th>Ver</th><?php endif; ?></tr></thead>
        <tbody>
        <?php if ($confirm): foreach ($resultados as $r): ?>
          <tr>
            <td><?= $r['id'] ?></td>
            <td><?= htmlspecialchars($r['title']) ?></td>
            <td><span class='tag-<?= $r['status'] ?>'><?= strtoupper($r['status']) ?></span></td>
            <td style='color:#888;font-size:12px'><?= htmlspecialchars($r['msg']) ?></td>
          </tr>
        <?php endforeach; else: foreach ($candidatos as $c): ?>
          <tr>
            <td><?= $c['id'] ?></td>
            <td><?= htmlspecialchars($c['title']) ?></td>
            <td><?php if (!empty($c['link'])): ?><a href='<?= htmlspecialchars($c['link']) ?>' target='_blank'>abrir</a><?php endif; ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($confirm): ?>
    <div class='box' style='border-left:4px solid #f59e0b'>
      <p style='color:#fbbf24;font-size:13px;margin:0'><strong>Recomendado:</strong> apague este arquivo (<code>limpar-trustbar.php</code>) do servidor após uso.</p>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
