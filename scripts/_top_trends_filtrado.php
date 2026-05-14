<?php
declare(strict_types=1);
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DbConnection.php';
date_default_timezone_set('America/Sao_Paulo');
$pdo = DbConnection::pdo();

echo "=== vagasebeneficios — trends NICHO (vagas/benefícios/inss/fgts/bolsa) ===\n";
$st = $pdo->prepare("
SELECT id, status, score_discover, data_detectada, pingo_link, SUBSTRING(titulo,1,140) titulo
FROM trends
WHERE site='vagasebeneficios'
  AND status IN ('aprovado','novo')
  AND ativo=1
  AND data_detectada >= DATE_SUB(NOW(), INTERVAL 36 HOUR)
  AND (
       titulo LIKE '%vaga%' OR titulo LIKE '%edital%' OR titulo LIKE '%concurso%'
    OR titulo LIKE '%bolsa%' OR titulo LIKE '%inss%' OR titulo LIKE '%fgts%'
    OR titulo LIKE '%saque%' OR titulo LIKE '%benefici%' OR titulo LIKE '%pis%'
    OR titulo LIKE '%pasep%' OR titulo LIKE '%pé-de-meia%' OR titulo LIKE '%pe-de-meia%'
    OR titulo LIKE '%bpc%' OR titulo LIKE '%aposentadoria%' OR titulo LIKE '%seguro-desemprego%'
    OR titulo LIKE '%auxílio%' OR titulo LIKE '%bolsa família%' OR titulo LIKE '%revisão%'
    OR titulo LIKE '%inscrição%' OR titulo LIKE '%CLT%' OR titulo LIKE '%trabalhador%'
    OR titulo LIKE '%aprovado%' OR titulo LIKE '%MP %' OR titulo LIKE '%projeto%'
  )
  AND titulo NOT LIKE '%onde assistir%'
  AND titulo NOT LIKE '%escalação%'
ORDER BY score_discover DESC, data_detectada DESC
LIMIT 8");
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  #{$r['id']} | {$r['status']} | s={$r['score_discover']} | {$r['data_detectada']}\n";
    echo "    {$r['titulo']}\n    URL: " . substr($r['pingo_link'] ?? '', 0, 130) . "\n";
}

echo "\n=== ondecompraragora — trends NICHO (oferta/desconto/cupom/preço) ===\n";
$st = $pdo->prepare("
SELECT id, status, score_discover, data_detectada, pingo_link, SUBSTRING(titulo,1,140) titulo
FROM trends
WHERE site='ondecompraragora'
  AND status IN ('aprovado','novo')
  AND ativo=1
  AND data_detectada >= DATE_SUB(NOW(), INTERVAL 36 HOUR)
  AND (
       titulo LIKE '%oferta%' OR titulo LIKE '%desconto%' OR titulo LIKE '%cupom%'
    OR titulo LIKE '%promoção%' OR titulo LIKE '%black%' OR titulo LIKE '%shopee%'
    OR titulo LIKE '%amazon%' OR titulo LIKE '%mercado livre%' OR titulo LIKE '%magalu%'
    OR titulo LIKE '%cashback%' OR titulo LIKE '%R\$%' OR titulo LIKE '%relâmpago%'
    OR titulo LIKE '%queima%' OR titulo LIKE '%liquidação%' OR titulo LIKE '%preço%'
  )
  AND titulo NOT LIKE '%onde assistir%'
  AND titulo NOT LIKE '%escalação%'
ORDER BY score_discover DESC, data_detectada DESC
LIMIT 8");
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  #{$r['id']} | {$r['status']} | s={$r['score_discover']} | {$r['data_detectada']}\n";
    echo "    {$r['titulo']}\n    URL: " . substr($r['pingo_link'] ?? '', 0, 130) . "\n";
}

echo "\n=== comocomprar — scrape rapido das 2 candidatas ===\n";
echo "  #17698 Smartwatch Bettdow Alexa → https://olhardigital.com.br/2026/05/12/reviews/smartwatch-bettdow-em-oferta-com-alexa/\n";
echo "  #16945 Projetores inteligentes → https://olhardigital.com.br/2026/05/12/reviews/oferta-projetores-inteligentes-para-cinema-em-casa/\n";
echo "(seguindo com o que voce ja viu)\n";
