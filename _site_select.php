<?php
/**
 * Render do seletor de site — chips clicáveis (sem JS).
 * Cada chip é um link com todos os params atuais + site=slug.
 * Requer $sites e $siteSlug no escopo (definidos via _site_helper.php antes do include).
 * Uso: <?php include __DIR__ . '/_site_select.php'; ?>
 */
if (!isset($sites) || !is_array($sites)) { $sites = sitesDisponiveis(); }
if (!isset($siteSlug)) { $siteSlug = siteAtivoSlug($sites); }

// Constrói query string base (todos os params atuais menos 'site')
$paramsBase = $_GET;
unset($paramsBase['site']);
// Preserva llm se houver no cookie/GET
if (!isset($paramsBase['llm']) && !empty($_COOKIE['portal_llm'])) {
    $paramsBase['llm'] = $_COOKIE['portal_llm'];
}
?>
<div class="box" style="padding:14px 18px">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:8px">
    <label style="margin:0;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#888">🌐 Site de destino</label>
    <span style="font-size:11px;color:#666">Clique pra trocar — params atuais preservados</span>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:6px">
    <?php foreach ($sites as $slug => $s):
      $ativo = ($slug === $siteSlug);
      $params = array_merge($paramsBase, ['site' => $slug]);
      $href = '?' . http_build_query($params);
      $bg   = $ativo ? '#0c4a6e' : '#0f1115';
      $bd   = $ativo ? '#0ea5e9' : '#2a2e38';
      $cor  = $ativo ? '#7dd3fc' : '#9ca3af';
    ?>
      <a href="<?= htmlspecialchars($href) ?>"
         style="padding:9px 14px;background:<?= $bg ?>;border:2px solid <?= $bd ?>;border-radius:6px;color:<?= $cor ?>;text-decoration:none;font-size:13px;font-weight:<?= $ativo ? '700' : '500' ?>;display:inline-flex;align-items:center;gap:6px;transition:all .15s"
         onmouseover="if(!this.dataset.ativo){this.style.borderColor='#0ea5e9';this.style.color='#7dd3fc'}"
         onmouseout="if(!this.dataset.ativo){this.style.borderColor='#2a2e38';this.style.color='#9ca3af'}"
         data-ativo="<?= $ativo ? '1' : '0' ?>">
        <?php if ($ativo): ?><span>✓</span><?php endif; ?>
        <strong><?= htmlspecialchars($s['name'] ?? $slug) ?></strong>
        <span style="opacity:.7;font-weight:normal;font-size:11px">— <?= htmlspecialchars(preg_replace('#^https?://#', '', $s['wp_url'] ?? '')) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
