<?php
declare(strict_types=1);
/** cc — confirma NOTA e Nº DE AVALIAÇÕES na página real de cada ASIN de creatina.
 *  Sem isso não dá pra citar E-E-A-T no hub nem na LP (regra: zero invenção).
 *
 *  php scripts/_cc_creatina_notas.php
 */
date_default_timezone_set('America/Sao_Paulo');
$ROOT = dirname(__DIR__);
require_once $ROOT.'/lib/Env.php'; Env::load($ROOT.'/.env');
require_once $ROOT.'/lib/Serper.php';

$serper = new Serper(Env::get('SERPER_API_KEY') ?: '', Env::get('SERPER_API_KEY_2') ?: null);

/** shortlist: cobre os recortes que o autocomplete mostrou (monohidratada pura, creapure, marcas) */
$ALVOS = [
  'B07L5WFHXW' => 'Integralmedica Creatina Hardcore 100% Pura 300g',
  'B07DVJC66X' => 'Max Titanium Creatina Monohidratada 300g',
  'B0BJ32SHS4' => 'Growth Creatina Monohidratada Creapure 100g',
  'B07LCTTF8V' => 'Vitafor Creatine Creatina Monohidratada 300g',
  'B0CTCY58H3' => 'Soldiers Nutrition Creatina Monohidratada 300g',
  'B07G7JPTCV' => 'Probiotica Creatina Monohidratada 300g',
];

$res = [];
foreach ($ALVOS as $asin => $rotulo) {
  $url = "https://www.amazon.com.br/dp/$asin";
  $nota = null; $aval = null; $titulo = null;
  try {
    $p = $serper->webpage($url, false);
    $txt = is_array($p) ? (string)($p['text'] ?? $p['markdown'] ?? json_encode($p)) : (string)$p;
  } catch (Throwable $e) { $txt = ''; }

  if ($txt !== '') {
    // "4,7 de 5 estrelas" / "4.7 out of 5"
    if (preg_match('/([0-9][,.][0-9])\s*(?:de\s*5|out of 5)/iu', $txt, $m)) $nota = str_replace('.', ',', $m[1]);
    // "1.234 avaliações" / "1,234 ratings"
    if (preg_match('/([\d.,]{2,})\s*(?:avalia[çc][õo]es|classifica[çc][õo]es|ratings)/iu', $txt, $m)) {
      $aval = preg_replace('/\D/', '', $m[1]);
    }
    if (preg_match('/^\s*(.{10,120}?)\s*[\r\n]/u', $txt, $m)) $titulo = trim($m[1]);
  }
  $res[$asin] = ['asin'=>$asin,'rotulo'=>$rotulo,'nota'=>$nota,'avaliacoes'=>$aval,'titulo_pagina'=>$titulo,'chars'=>mb_strlen($txt)];
  printf("%-12s %-46s nota:%-5s aval:%-8s (%d chars)\n", $asin, mb_substr($rotulo,0,46), $nota ?? '-', $aval ?? '-', mb_strlen($txt));
  usleep(400000);
}

$out = $ROOT.'/data/_cc_creatina_notas.json';
file_put_contents($out, json_encode($res, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
echo "\n-> ".basename($out)."\n";
echo "Só entra no texto o que tiver nota E nº de avaliações confirmados aqui.\n";
