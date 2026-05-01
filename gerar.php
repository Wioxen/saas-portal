<?php
/**
 * Interface de geração — uma página ou em lote.
 *
 * Modo único:  POST com categoria, preco, ano
 * Modo lote:   textarea com uma combinação por linha no formato "categoria|preco|ano"
 *              ex: celulares|1500|2026
 *                  perfumes masculinos|200|2026
 *                  notebooks para estudar|3000|2026
 */

require_once __DIR__ . '/lib/Serper.php';
require_once __DIR__ . '/lib/Gerador.php';
require_once __DIR__ . '/lib/Template.php';

$cfg = require_once __DIR__ . '/config.php';

if (!is_dir($cfg['pages_dir'])) {
    mkdir($cfg['pages_dir'], 0777, true);
}

$serper   = new Serper($cfg['serper_api_key']);
$template = new Template($cfg);

$mensagens = [];
$geradas   = [];

function gerarPagina(string $categoria, int $preco, int $ano, Serper $serper, Template $template, array $cfg): array
{
    $query = "melhores {$categoria} ate {$preco} reais {$ano}";
    $shop  = $serper->shopping($query, 12);
    $produtos = $shop['shopping'] ?? [];

    if (empty($produtos)) {
        // fallback: usa orgânica
        $org = $serper->search($query, 10);
        foreach (($org['organic'] ?? []) as $r) {
            $produtos[] = [
                'title'    => $r['title'] ?? '',
                'link'     => $r['link'] ?? '',
                'imageUrl' => '',
                'price'    => '',
                'source'   => parse_url($r['link'] ?? '', PHP_URL_HOST) ?: '',
            ];
        }
    }

    $gen = new Gerador($categoria, $preco, $ano, $produtos);
    $artigo = $gen->gerar();
    $html = $template->render($artigo);

    $arquivo = $cfg['pages_dir'] . '/' . $artigo['slug'] . '.html';
    file_put_contents($arquivo, $html);

    return [
        'slug'    => $artigo['slug'],
        'arquivo' => $arquivo,
        'url'     => $cfg['pages_url'] . '/' . $artigo['slug'] . '.html',
        'titulo'  => $artigo['titulo'],
        'qtd'     => count($produtos),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!empty($_POST['lote'])) {
            $linhas = preg_split('/\r?\n/', trim($_POST['lote']));
            foreach ($linhas as $linha) {
                $linha = trim($linha);
                if ($linha === '') continue;
                $parts = array_map('trim', explode('|', $linha));
                if (count($parts) < 3) {
                    $mensagens[] = "⚠️ Linha ignorada (formato inválido): {$linha}";
                    continue;
                }
                [$cat, $pr, $an] = $parts;
                $r = gerarPagina($cat, (int)$pr, (int)$an, $serper, $template, $cfg);
                $geradas[] = $r;
            }
        } else {
            $cat = trim($_POST['categoria'] ?? '');
            $pr  = (int)($_POST['preco'] ?? 0);
            $an  = (int)($_POST['ano'] ?? date('Y'));
            if ($cat === '' || $pr <= 0) {
                throw new RuntimeException('Categoria e preço são obrigatórios.');
            }
            $geradas[] = gerarPagina($cat, $pr, $an, $serper, $template, $cfg);
        }
    } catch (Throwable $e) {
        $mensagens[] = '❌ ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Gerador de Páginas SEO</title>
<style>
body{font-family:Arial,sans-serif;background:#f0f2f5;margin:0;padding:24px}
.container{max-width:780px;margin:0 auto}
h1{color:#222}
.box{background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,0.08);margin-bottom:20px}
label{display:block;font-weight:bold;margin:12px 0 6px;color:#333}
input,textarea{width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;font-size:14px;font-family:inherit}
textarea{min-height:140px;font-family:monospace}
button{margin-top:16px;padding:12px 24px;background:#1877f2;color:#fff;border:none;border-radius:4px;font-size:15px;font-weight:bold;cursor:pointer}
button:hover{background:#166fe0}
.row{display:flex;gap:12px}
.row>div{flex:1}
.msg{background:#fff8e1;border-left:4px solid #ffc107;padding:10px 14px;margin-bottom:8px;border-radius:4px;font-size:14px}
.ok{background:#e8f5e9;border-color:#2ecc71}
.gerada{background:#fff;padding:12px;border:1px solid #eee;border-radius:6px;margin-bottom:8px}
.gerada a{color:#1877f2;text-decoration:none;font-weight:bold}
small{color:#777}
hr{border:none;border-top:1px solid #ddd;margin:24px 0}
</style>
</head>
<body>
<div class="container">
  <h1>🚀 Gerador de Páginas SEO</h1>
  <p>Cria artigos otimizados para Google Discover, Google News e SEO orgânico.</p>

  <?php foreach ($mensagens as $m): ?>
    <div class="msg"><?= htmlspecialchars($m) ?></div>
  <?php endforeach; ?>

  <?php if (!empty($geradas)): ?>
    <div class="box">
      <h2>✅ <?= count($geradas) ?> página(s) gerada(s)</h2>
      <?php foreach ($geradas as $g): ?>
        <div class="gerada">
          <a href="<?= htmlspecialchars($g['url']) ?>" target="_blank"><?= htmlspecialchars($g['titulo']) ?></a><br>
          <small><?= htmlspecialchars($g['slug']) ?>.html · <?= $g['qtd'] ?> produtos</small>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="POST" class="box">
    <h2>Gerar uma página</h2>
    <div class="row">
      <div>
        <label for="categoria">Categoria</label>
        <input id="categoria" name="categoria" placeholder="celulares, perfumes masculinos, notebooks para estudar">
      </div>
      <div>
        <label for="preco">Preço máximo (R$)</label>
        <input id="preco" name="preco" type="number" placeholder="1500">
      </div>
      <div>
        <label for="ano">Ano</label>
        <input id="ano" name="ano" type="number" value="<?= date('Y') ?>">
      </div>
    </div>
    <button type="submit">Gerar página</button>
  </form>

  <form method="POST" class="box">
    <h2>Gerar em lote</h2>
    <p><small>Uma combinação por linha: <code>categoria|preço|ano</code></small></p>
    <label for="lote">Lista</label>
    <textarea id="lote" name="lote" placeholder="celulares|1500|2026
perfumes masculinos|200|2026
notebooks para estudar|3000|2026
fones bluetooth|300|2026
smart tv 50 polegadas|2500|2026"></textarea>
    <button type="submit">Gerar lote</button>
  </form>

  <p style="text-align:center"><a href="sitemap.php">Ver sitemap</a> · <a href="pages/">Pasta de páginas</a></p>
</div>
</body>
</html>
