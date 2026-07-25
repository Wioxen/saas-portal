<?php
declare(strict_types=1);
/** cc — publica TEMPORARIAMENTE os CSVs de upload em massa na biblioteca de mídia do WP, para que
 *  o Google Ads possa buscá-los pela origem HTTPS (a origem "arquivo do computador" abre diálogo
 *  nativo do sistema, que automação de navegador não consegue operar).
 *
 *  php scripts/_cc_ads_publicar_csv.php --subir     # envia e imprime as URLs
 *  php scripts/_cc_ads_publicar_csv.php --remover   # apaga tudo que foi enviado
 *
 *  Os IDs ficam em data/_cc_ads_csv_publicados.json para a limpeza ser exata — sem varrer a
 *  biblioteca por nome, que apagaria arquivo de terceiro com nome parecido.
 *
 *  ⚠️ Enquanto publicados, os arquivos são acessíveis por URL. Contêm estrutura de campanha e
 *  texto de anúncio; nada de credencial. REMOVER assim que os uploads no Ads terminarem.
 */
date_default_timezone_set('America/Sao_Paulo');
$ROOT = dirname(__DIR__);
require_once $ROOT.'/lib/Env.php'; Env::load($ROOT.'/.env');
$S = require $ROOT.'/sites.php'; $V = $S['comocomprar'];
$AUTH = base64_encode($V['wp_user'].':'.$V['wp_app_password']);
$U = rtrim($V['wp_url'], '/');

$op = getopt('', ['subir','remover']);
$SUBIR = isset($op['subir']); $REMOVER = isset($op['remover']);
if (!$SUBIR && !$REMOVER) { fwrite(STDERR, "use --subir ou --remover\n"); exit(1); }

$DIR = $ROOT.'/docs/ads/bulk';
$REG = $ROOT.'/data/_cc_ads_csv_publicados.json';
if (!is_dir(dirname($REG))) mkdir(dirname($REG), 0777, true);

function req(string $metodo, string $url, array $headers, ?string $body = null): array {
  $c = curl_init($url);
  curl_setopt_array($c, [
    CURLOPT_RETURNTRANSFER => 1, CURLOPT_CUSTOMREQUEST => $metodo,
    CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_TIMEOUT => 60,
  ]);
  if ($body !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $body);
  $r = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
  return [$code, (string)$r];
}

if ($SUBIR) {
  $arquivos = glob("$DIR/*.csv");
  sort($arquivos);
  if (!$arquivos) { fwrite(STDERR, "nenhum CSV em $DIR\n"); exit(1); }
  $registro = [];
  foreach ($arquivos as $caminho) {
    $nome = basename($caminho);
    $bytes = (string)file_get_contents($caminho);
    [$code, $resp] = req('POST', "$U/wp-json/wp/v2/media", [
      "Authorization: Basic $AUTH",
      "Content-Type: text/csv",
      "Content-Disposition: attachment; filename=\"$nome\"",
    ], $bytes);
    $j = json_decode($resp, true);
    if ($code !== 201 || !isset($j['id'])) {
      echo "  ✗ $nome — HTTP $code ".substr($resp, 0, 200)."\n";
      continue;
    }
    $registro[] = ['id' => (int)$j['id'], 'nome' => $nome, 'url' => (string)$j['source_url']];
    echo "  ✓ $nome -> {$j['source_url']}\n";
  }
  file_put_contents($REG, json_encode($registro, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  echo "\n".count($registro)." de ".count($arquivos)." publicados. Registro em data/_cc_ads_csv_publicados.json\n";

  // confirma que estão realmente acessíveis por HTTPS, que é o que o Ads vai fazer
  echo "\n=== checando acesso público (o Ads precisa baixar) ===\n";
  foreach ($registro as $r) {
    [$c2, $corpo] = req('GET', $r['url'], []);
    $ok = $c2 === 200 && strlen($corpo) > 0;
    echo "  ".($ok?'✓':'✗')." HTTP $c2 (".strlen($corpo)." bytes) {$r['nome']}\n";
  }
  exit(0);
}

if ($REMOVER) {
  if (!is_file($REG)) { fwrite(STDERR, "sem registro em $REG — nada a remover\n"); exit(1); }
  $registro = json_decode((string)file_get_contents($REG), true) ?: [];
  $ok = 0;
  foreach ($registro as $r) {
    [$code, $resp] = req('DELETE', "$U/wp-json/wp/v2/media/{$r['id']}?force=true", ["Authorization: Basic $AUTH"]);
    if ($code === 200) { echo "  ✓ removido {$r['nome']} (#{$r['id']})\n"; $ok++; }
    else { echo "  ✗ {$r['nome']} (#{$r['id']}) — HTTP $code ".substr($resp,0,160)."\n"; }
  }
  echo "\n$ok de ".count($registro)." removidos.\n";
  if ($ok === count($registro)) unlink($REG);
  exit(0);
}
