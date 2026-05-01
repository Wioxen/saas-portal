<?php
/**
 * Consulta preços de celulares na API pública do Mercado Livre Brasil.
 * Endpoint: https://api.mercadolibre.com/sites/MLB/search?q={termo}
 */

$termo = trim($_GET['q'] ?? '');
$produtos = [];
$erro = '';

if ($termo !== '') {
    $url = 'https://api.mercadolibre.com/sites/MLB/search?q=' . urlencode($termo) . '&limit=20&category=MLB1051';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false, // XAMPP no Windows costuma falhar sem isso
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $resposta = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        $erro = 'Erro ao consultar a API: ' . $curlErr;
    } elseif ($status !== 200) {
        $erro = 'API retornou status ' . $status;
    } else {
        $dados = json_decode($resposta, true);
        $produtos = $dados['results'] ?? [];
    }
}

function formatarPreco(float $valor): string {
    return 'R$ ' . number_format($valor, 2, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Preços de Celulares</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; margin: 0; padding: 24px; }
        .container { max-width: 1100px; margin: 0 auto; }
        h1 { color: #333; }
        form { display: flex; gap: 8px; margin-bottom: 24px; }
        input[type="text"] { flex: 1; padding: 11px; border: 1px solid #ccc; border-radius: 4px; font-size: 15px; }
        button { padding: 11px 22px; background: #1877f2; color: #fff; border: none; border-radius: 4px; font-size: 15px; font-weight: bold; cursor: pointer; }
        button:hover { background: #166fe0; }
        .erro { background: #ffe5e5; color: #c0392b; padding: 12px; border-radius: 4px; margin-bottom: 16px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(0,0,0,0.08); overflow: hidden; display: flex; flex-direction: column; }
        .card img { width: 100%; height: 200px; object-fit: contain; background: #fafafa; }
        .card-body { padding: 12px; flex: 1; display: flex; flex-direction: column; }
        .titulo { font-size: 14px; color: #333; margin: 0 0 8px; line-height: 1.3; flex: 1; }
        .preco { font-size: 18px; font-weight: bold; color: #1877f2; margin-bottom: 8px; }
        .link { font-size: 13px; color: #fff; background: #2ecc71; text-align: center; padding: 8px; border-radius: 4px; text-decoration: none; }
        .link:hover { background: #27ae60; }
        .vazio { color: #777; text-align: center; padding: 40px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Preços de Celulares — Mercado Livre BR</h1>

    <form method="GET">
        <input type="text" name="q" value="<?= htmlspecialchars($termo) ?>" placeholder="Ex: iPhone 15, Galaxy S24, Motorola Edge 50..." required>
        <button type="submit">Buscar</button>
    </form>

    <?php if ($erro !== ''): ?>
        <div class="erro"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <?php if ($termo !== '' && empty($produtos) && $erro === ''): ?>
        <div class="vazio">Nenhum produto encontrado para "<?= htmlspecialchars($termo) ?>".</div>
    <?php endif; ?>

    <?php if (!empty($produtos)): ?>
        <div class="grid">
            <?php foreach ($produtos as $p): ?>
                <div class="card">
                    <img src="<?= htmlspecialchars($p['thumbnail'] ?? '') ?>" alt="">
                    <div class="card-body">
                        <p class="titulo"><?= htmlspecialchars($p['title'] ?? '') ?></p>
                        <div class="preco"><?= formatarPreco((float)($p['price'] ?? 0)) ?></div>
                        <a class="link" href="<?= htmlspecialchars($p['permalink'] ?? '#') ?>" target="_blank" rel="noopener">Ver no ML</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
