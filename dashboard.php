<?php
/**
 * Dashboard — hub de acesso rápido a todas as ferramentas do projeto.
 * Lista agrupada por categoria com descrição curta.
 */
$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites = sitesDisponiveis();

$grupos = [
    'Geração de conteúdo' => [
        ['file' => 'gerarpost.php',  'emoji' => '📝', 'nome' => 'Gerar Post / Cluster', 'desc' => 'Artigo editorial + Cluster SEO (interliga sites). RSS, Discover, carrossel IG, FB, indexação, termos virais.'],
        ['file' => 'landing.php',    'emoji' => '🎯', 'nome' => 'Landing de Review',    'desc' => 'Página com cards de produto, comparativo, decision_block. Ideal para reviews de compra.'],
        ['file' => 'maquina.php',    'emoji' => '⚙️', 'nome' => 'Máquina de Conteúdo',  'desc' => 'Geração unitária por keyword com todos os formatos (SEO/Discover/News/SERP) em abas.'],
        ['file' => 'massa.php',      'emoji' => '🚀', 'nome' => 'Em Massa',             'desc' => 'Cola várias keywords de uma vez — gera tudo em sequência + interligação automática.'],
    ],
    'Descoberta de tópicos' => [
        ['file' => 'categorias.php', 'emoji' => '🏗️', 'nome' => 'Categorias de Nicho', 'desc' => 'Digite o nicho → gera 30+ variações de keywords → publica em lote.'],
        ['file' => 'trending.php',   'emoji' => '🔥', 'nome' => 'Trending Topics',      'desc' => 'Google Trends BR + Explorar keywords do Google em tempo real.'],
        ['file' => 'youtube.php',    'emoji' => '▶️', 'nome' => 'YouTube → Artigo',    'desc' => 'Transcreve vídeo do YouTube e transforma em artigo otimizado.'],
    ],
    'Manutenção e SEO' => [
        ['file' => 'atualizar.php',  'emoji' => '🔄', 'nome' => 'Atualizar Posts',      'desc' => 'Refresh inteligente de posts antigos + tags via IA. Preserva URL/backlinks.'],
        ['file' => 'indexar.php',    'emoji' => '🔎', 'nome' => 'Indexação',            'desc' => 'Checa no Google quais posts estão indexados e solicita indexação via Rank Math/IndexNow.'],
    ],
];

$totalSites = count($sites);
$totalFerramentas = array_sum(array_map('count', $grupos));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Máquina de Conteúdo</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0a0a0f;color:#e0e0e0;margin:0;padding:32px 24px;line-height:1.5;min-height:100vh}
.container{max-width:1200px;margin:0 auto}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:32px;flex-wrap:wrap;gap:16px}
h1{color:#fff;margin:0;font-size:28px;font-weight:800}
.sub{color:#666;font-size:14px;margin:4px 0 0}
.stats{display:flex;gap:12px;flex-wrap:wrap}
.stat{background:#12141a;border:1px solid #1e2230;padding:10px 18px;border-radius:10px;display:flex;flex-direction:column;align-items:flex-start;min-width:100px}
.stat strong{font-size:22px;color:#a78bfa;font-weight:800;line-height:1}
.stat span{font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.5px;margin-top:4px}
.grupo{margin-bottom:32px}
.grupo h2{color:#ccc;font-size:14px;text-transform:uppercase;letter-spacing:1.5px;margin:0 0 14px;padding-bottom:10px;border-bottom:1px solid #1e2230}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}
.card{background:#12141a;border:1px solid #1e2230;border-radius:12px;padding:22px;text-decoration:none;color:inherit;transition:all .2s;display:flex;flex-direction:column;gap:8px;position:relative;overflow:hidden}
.card::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(99,102,241,0) 0%,rgba(139,92,246,0) 100%);transition:background .2s;pointer-events:none}
.card:hover{border-color:#6366f1;transform:translateY(-2px);box-shadow:0 8px 24px rgba(99,102,241,.15)}
.card:hover::before{background:linear-gradient(135deg,rgba(99,102,241,.08) 0%,rgba(139,92,246,.04) 100%)}
.card-head{display:flex;align-items:center;gap:12px;position:relative;z-index:1}
.card-emoji{font-size:28px;line-height:1}
.card-nome{font-size:16px;font-weight:700;color:#fff}
.card-desc{font-size:13px;color:#666;line-height:1.5;flex:1;position:relative;z-index:1}
.card-arrow{font-size:18px;color:#6366f1;opacity:0;transform:translateX(-4px);transition:all .2s;align-self:flex-end;margin-top:8px;position:relative;z-index:1}
.card:hover .card-arrow{opacity:1;transform:translateX(0)}
.sites-list{background:#12141a;border:1px solid #1e2230;padding:20px;border-radius:12px;margin-bottom:32px}
.sites-list h2{margin:0 0 12px;font-size:14px;text-transform:uppercase;letter-spacing:1.5px;color:#ccc}
.sites-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
.site-item{background:#0a0a0f;border:1px solid #1e2230;border-radius:8px;padding:10px 14px;font-size:13px}
.site-item strong{color:#a78bfa;font-weight:700;display:block;margin-bottom:2px}
.site-item span{color:#555;font-size:11px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
footer{text-align:center;color:#333;font-size:11px;margin-top:40px}
footer code{background:#12141a;padding:2px 8px;border-radius:4px;color:#a78bfa}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div>
      <h1>🛠️ Máquina de Conteúdo</h1>
      <p class="sub">Hub de ferramentas para gerar, atualizar e indexar conteúdo em múltiplos sites WordPress.</p>
    </div>
    <div class="stats">
      <div class="stat"><strong><?= $totalSites ?></strong><span>sites</span></div>
      <div class="stat"><strong><?= $totalFerramentas ?></strong><span>ferramentas</span></div>
    </div>
  </div>

  <div class="sites-list">
    <h2>🌐 Sites cadastrados</h2>
    <div class="sites-grid">
      <?php foreach ($sites as $slug => $s): ?>
        <div class="site-item">
          <strong><?= htmlspecialchars($s['name'] ?? $slug) ?></strong>
          <span><?= htmlspecialchars(preg_replace('#^https?://#', '', $s['wp_url'] ?? '')) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php foreach ($grupos as $grupo => $ferramentas): ?>
    <div class="grupo">
      <h2><?= htmlspecialchars($grupo) ?></h2>
      <div class="cards">
        <?php foreach ($ferramentas as $f): ?>
          <a href="<?= htmlspecialchars($f['file']) ?>" class="card">
            <div class="card-head">
              <span class="card-emoji"><?= $f['emoji'] ?></span>
              <div class="card-nome"><?= htmlspecialchars($f['nome']) ?></div>
            </div>
            <div class="card-desc"><?= htmlspecialchars($f['desc']) ?></div>
            <div class="card-arrow">→</div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <footer>
    Configuração em <code>config.php</code> · Sites em <code>sites.php</code> · Blocos de IA em <code>_blocos_data.php</code>
  </footer>
</div>
</body>
</html>
