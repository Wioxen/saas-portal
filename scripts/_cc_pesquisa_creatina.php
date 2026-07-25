<?php
declare(strict_types=1);
/** cc — apura produtos REAIS de creatina na Amazon BR (ASIN, título, nota, nº de avaliações)
 *  para alimentar o hub e a LP. Zero invenção: o que não vier da fonte não entra no texto.
 *
 *  php scripts/_cc_pesquisa_creatina.php
 */
date_default_timezone_set('America/Sao_Paulo');
$ROOT = dirname(__DIR__);
require_once $ROOT.'/lib/Env.php'; Env::load($ROOT.'/.env');
require_once $ROOT.'/lib/Serper.php';

$chave = Env::get('SERPER_API_KEY') ?: '';
$serper = new Serper($chave, Env::get('SERPER_API_KEY_2') ?: null);

$consultas = [
  'site:amazon.com.br creatina monohidratada 300g',
  'site:amazon.com.br creatina creapure',
  'site:amazon.com.br creatina growth',
  'site:amazon.com.br creatina integralmedica',
  'site:amazon.com.br creatina max titanium',
  'site:amazon.com.br creatina dux nutrition',
  'site:amazon.com.br creatina probiotica',
];

$achados = [];
foreach ($consultas as $q) {
  try { $r = $serper->search($q, 10); } catch (Throwable $e) { echo "✗ $q\n"; continue; }
  foreach (($r['organic'] ?? []) as $o) {
    $link = $o['link'] ?? '';
    if (!preg_match('#/(?:dp|gp/product)/([A-Z0-9]{10})#', $link, $m)) continue;
    $asin = $m[1];
    if (isset($achados[$asin])) continue;
    $achados[$asin] = [
      'asin'   => $asin,
      'titulo' => trim($o['title'] ?? ''),
      'link'   => 'https://www.amazon.com.br/dp/'.$asin,
      'snippet'=> mb_substr(trim($o['snippet'] ?? ''), 0, 180),
      'origem' => $q,
    ];
  }
  usleep(300000);
}

echo "=== ".count($achados)." ASINs encontrados ===\n\n";
foreach ($achados as $a) {
  printf("%s\n  %s\n  %s\n\n", $a['asin'], mb_substr($a['titulo'],0,100), $a['snippet']);
}

$out = $ROOT.'/data/_cc_creatina_asins.json';
if (!is_dir(dirname($out))) mkdir(dirname($out), 0777, true);
file_put_contents($out, json_encode(array_values($achados), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
echo "-> ".basename($out)."\n";
echo "\n⚠️ Nota e nº de avaliações NÃO vêm do Serper: confirmar na página do produto antes de citar.\n";
