<?php
/**
 * Restaura o <style> inline dos posts que perderam a estilização
 * depois da limpeza do trust bar.
 *
 * Hipótese: o WP REST API strippa <style> ao atualizar content se o
 * usuário não tem unfiltered_html. Este script re-injeta o mesmo CSS
 * do LandingBuilder::buildCSS() no início do content de posts que têm
 * o layout landing (id="rv") mas não têm <style>.
 *
 * Uso:
 *   restaurar-style.php?site=comocomprar          → diagnóstico (lista candidatos + caps do user)
 *   restaurar-style.php?site=comocomprar&confirm=1 → aplica
 */
require_once __DIR__ . '/lib/Wordpress.php';
require_once __DIR__ . '/lib/LandingBuilder.php';
$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

set_time_limit(0);

$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$lb = new LandingBuilder($cfg['site_name'] ?? '', $cfg['wp_url'] ?? '', []);
$css = $lb->buildCSS(); // <style>...</style>

// ── Checa capabilities do user via REST ──
$caps = [];
$unfiltered = null;
try {
    $me = (function() use ($wp) {
        $ref = new ReflectionClass($wp);
        $req = $ref->getMethod('request');
        $req->setAccessible(true);
        return $req->invoke($wp, 'GET', '/users/me?context=edit');
    })();
    $caps = $me['capabilities'] ?? [];
    $unfiltered = !empty($caps['unfiltered_html']);
} catch (Throwable $e) {
    $capsErro = $e->getMessage();
}

// ── Lista candidatos ──
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
        $rendered = $p['content']['rendered'] ?? '';
        // Só posts com layout landing
        if (!str_contains($rendered, 'id="rv"') && !str_contains($rendered, "id='rv'")) continue;
        // Só os que NÃO têm <style> (a estilização inline sumiu)
        $semStyle = !preg_match('#<style\b[^>]*>#i', $rendered);
        if (!$semStyle) continue;
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
            if (preg_match('#<style\b[^>]*>#i', $raw)) { $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'skip', 'msg' => 'raw já tem <style>']; continue; }

            $novo = $css . "\n" . $raw;
            $resp = $wp->atualizarPost($id, ['content' => $novo]);

            // Confere se o <style> realmente persistiu após o update
            $respRendered = $resp['content']['rendered'] ?? '';
            $persistiu = preg_match('#<style\b[^>]*>#i', $respRendered);
            if ($persistiu) {
                $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'ok', 'msg' => '<style> restaurado'];
            } else {
                $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'stripado', 'msg' => 'WP removeu <style> de novo — user precisa de unfiltered_html'];
            }
        } catch (Throwable $e) {
            $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'erro', 'msg' => $e->getMessage()];
        }
    }
}

$okCount       = count(array_filter($resultados, fn($r) => $r['status'] === 'ok'));
$errCount      = count(array_filter($resultados, fn($r) => $r['status'] === 'erro'));
$skipCount     = count(array_filter($resultados, fn($r) => $r['status'] === 'skip'));
$stripadoCount = count(array_filter($resultados, fn($r) => $r['status'] === 'stripado'));
?>
<!DOCTYPE html>
<html lang='pt-br'>
<head>
<meta charset='UTF-8'>
<title>Restaurar Style — <?= htmlspecialchars($cfg['_site_name'] ?? $siteSlug) ?></title>
<style>
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:980px;margin:0 auto}
h1{color:#fff;margin:0 0 6px}
.sub{color:#666;margin-bottom:18px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:18px 22px;border-radius:10px;margin-bottom:14px}
.box h2{margin-top:0;font-size:16px;color:#e0e0e0}
.btn{display:inline-block;padding:12px 22px;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;text-decoration:none;cursor:pointer}
.btn-dry{background:linear-gradient(135deg,#6366f1,#8b5cf6)}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px}
th{text-align:left;padding:8px 10px;background:#0f1115;color:#888;font-size:11px;text-transform:uppercase;border-bottom:2px solid #2a2e38}
td{padding:8px 10px;border-bottom:1px solid #1e2230;vertical-align:middle}
.tag-ok{color:#22c55e;font-weight:700}
.tag-erro{color:#ef4444;font-weight:700}
.tag-skip{color:#a78bfa;font-weight:700}
.tag-stripado{color:#f59e0b;font-weight:700}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
.resumo{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:10px 0}
.resumo-item{text-align:center;background:#0f1115;padding:14px;border-radius:6px}
.resumo-item strong{display:block;font-size:22px;color:#a78bfa}
.resumo-item span{font-size:11px;color:#666}
.cap-ok{color:#22c55e}
.cap-bad{color:#ef4444}
</style>
</head>
<body>
<div class='container'>
  <h1>🎨 Restaurar &lt;style&gt; dos posts</h1>
  <p class='sub'>Re-injeta o CSS mobile-first em posts que perderam o bloco <code>&lt;style&gt;</code> após a limpeza do trust bar.</p>

  <div class='box'>
    <h2>Site ativo + capacidades REST</h2>
    <p><strong><?= htmlspecialchars($cfg['_site_name'] ?? $siteSlug) ?></strong> · <?= htmlspecialchars($cfg['wp_url'] ?? '') ?></p>
    <?php if ($unfiltered === null): ?>
      <p class='cap-bad'>❌ Não foi possível checar capabilities: <?= htmlspecialchars($capsErro ?? 'desconhecido') ?></p>
    <?php elseif ($unfiltered): ?>
      <p class='cap-ok'>✅ Usuário tem <code>unfiltered_html</code> — o <code>&lt;style&gt;</code> vai persistir.</p>
    <?php else: ?>
      <p class='cap-bad'>⚠️ Usuário NÃO tem <code>unfiltered_html</code> — o REST vai strippar o <code>&lt;style&gt;</code> de novo.</p>
      <p style='font-size:12px;color:#fbbf24'>Soluções: (1) usar Application Password de um Admin single-site; (2) criar um mu-plugin que filtre <code>wp_kses_allowed_html</code> pra permitir <code>&lt;style&gt;</code>; (3) editar os posts diretamente no banco.</p>
    <?php endif; ?>
    <p style='font-size:12px;color:#666'>Troque via ?site=slug — disponíveis: <?= implode(', ', array_keys($sites)) ?></p>
  </div>

  <?php if (!empty($erros)): ?>
    <div class='box' style='border-left:4px solid #ef4444'>
      <h2 style='color:#fca5a5'>Erros de listagem</h2>
      <?php foreach ($erros as $e): ?><p style='color:#fca5a5;font-size:13px'><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class='box'>
    <h2><?= count($candidatos) ?> post(s) com layout landing mas sem &lt;style&gt;</h2>
    <?php if (empty($candidatos)): ?>
      <p style='color:#888'>Nenhum post precisa de restauração.</p>
    <?php else: ?>
      <?php if (!$confirm): ?>
        <p style='color:#888;font-size:13px'>Modo <strong>dry-run</strong>: nada foi alterado. Confira a lista e aplique quando estiver pronto.</p>
        <p style='margin-top:14px'>
          <a class='btn' href='?site=<?= urlencode($siteSlug) ?>&confirm=1' onclick="return confirm('Re-injetar &lt;style&gt; em <?= count($candidatos) ?> post(s) de <?= htmlspecialchars($cfg['_site_name'] ?? $siteSlug) ?>?')">✓ Aplicar restauração</a>
          <a class='btn btn-dry' style='margin-left:8px' href='?site=<?= urlencode($siteSlug) ?>'>Recarregar dry-run</a>
        </p>
      <?php else: ?>
        <div class='resumo'>
          <div class='resumo-item'><strong><?= $okCount ?></strong><span>restaurados</span></div>
          <div class='resumo-item'><strong><?= $stripadoCount ?></strong><span>stripados de novo</span></div>
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
</div>
</body>
</html>
