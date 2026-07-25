<?php
declare(strict_types=1);
/** cc — puxa o AUTOCOMPLETE do Google (via Serper) das sementes prioritárias e monta o mapa de
 *  cluster: cada sugestão é uma consulta que gente REAL digita, logo é pauta de post com demanda
 *  comprovada. Supre a falta de People Also Ask / related searches neste plano do Serper.
 *
 *  php scripts/_cc_autocomplete_cluster.php
 *
 *  Além da semente pura, sonda os modificadores que revelam intenção:
 *  "<semente> para", "<semente> custo", "melhor <núcleo>", "<núcleo> vale a pena".
 */
date_default_timezone_set('America/Sao_Paulo');
$ROOT = dirname(__DIR__);
require_once $ROOT.'/lib/Env.php'; Env::load($ROOT.'/.env');
require_once $ROOT.'/lib/Serper.php';

$OUT = $ROOT.'/data/_cc_autocomplete_cluster.json';
$chave = Env::get('SERPER_API_KEY') ?: '';
if ($chave === '') { fwrite(STDERR, "sem SERPER_API_KEY\n"); exit(1); }
$serper = new Serper($chave, Env::get('SERPER_API_KEY_2') ?: null);

/** semente => núcleo curto (pro sufixo "melhor <núcleo>") */
$SEMENTES = [
  'melhores rações para cachorro' => 'ração para cachorro',
  'melhores rações para gato'     => 'ração para gato',
  'melhores creatinas'            => 'creatina',
  'melhores hidratantes faciais'  => 'hidratante facial',
  'melhores oleos capilares'      => 'óleo capilar',
  'melhores kits de shampoo e condicionador' => 'shampoo e condicionador',
  'melhores kimonos de jiu jitsu' => 'kimono de jiu jitsu',
  'melhores quadros de bike aro 29' => 'quadro de bike aro 29',
];

$res = [];
foreach ($SEMENTES as $semente => $nucleo) {
  $consultas = [
    $semente,
    $semente.' para',
    $semente.' custo',
    'melhor '.$nucleo,
    $nucleo.' vale a pena',
    $nucleo.' qual comprar',
  ];
  $todas = [];
  foreach ($consultas as $q) {
    // A resposta é {searchParameters:{...}, suggestions:[{value:"..."}]} — iterar o objeto
    // inteiro devolve lixo ("1"). O que interessa está em suggestions[].value.
    try { $r = $serper->autocomplete($q); } catch (Throwable $e) { continue; }
    foreach (($r['suggestions'] ?? []) as $s) {
      $txt = is_array($s) ? ($s['value'] ?? '') : (string)$s;
      $txt = trim(mb_strtolower($txt));
      if ($txt !== '') $todas[$txt] = true;
    }
    usleep(250000);
  }
  $lista = array_keys($todas);
  sort($lista);
  $res[$semente] = $lista;
  echo "\n=== $semente — ".count($lista)." sugestões ===\n";
  foreach ($lista as $l) echo "   $l\n";
}

if (!is_dir(dirname($OUT))) mkdir(dirname($OUT), 0777, true);
file_put_contents($OUT, json_encode($res, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
echo "\ntotal de sementes: ".count($res)." -> ".basename($OUT)."\n";
