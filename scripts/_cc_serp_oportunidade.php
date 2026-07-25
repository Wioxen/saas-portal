<?php
declare(strict_types=1);
/** cc — varre a 1ª página do Google (via Serper) de uma lista de termos "melhores X" e classifica
 *  cada um por VIABILIDADE DE RANQUEAR e por ENCAIXE DE AFILIADO.
 *
 *  php scripts/_cc_serp_oportunidade.php --limit=2      # teste (2 termos, economiza crédito)
 *  php scripts/_cc_serp_oportunidade.php                # todos
 *
 *  Para cada termo mede o que decide a estratégia:
 *   · quem ocupa a 1ª página — marketplace, mídia-gigante, nicho/afiliado ou fórum;
 *   · answerBox (trecho em destaque) e knowledgeGraph — sinal de resposta já consolidada;
 *   · nº de People Also Ask e related searches — matéria-prima de cluster e de FAQ;
 *   · comissão Amazon BR da categoria — sem isso, volume alto vira trabalho de graça.
 *
 *  A saída alimenta a decisão HUB/CLUSTER/REVIEW (orgânico) vs LP BoFu (Search pago).
 */
date_default_timezone_set('America/Sao_Paulo');
$ROOT = dirname(__DIR__);
require_once $ROOT.'/lib/Env.php'; Env::load($ROOT.'/.env');
require_once $ROOT.'/lib/Serper.php';

$op = getopt('', ['limit::','out::']);
$LIMIT = isset($op['limit']) ? (int)$op['limit'] : 0;
$OUT   = $op['out'] ?? $ROOT.'/data/_cc_serp_oportunidade.json';

$chave = Env::get('SERPER_API_KEY') ?: Env::get('SERPER_KEY') ?: '';
if ($chave === '') { fwrite(STDERR, "sem SERPER_API_KEY no .env\n"); exit(1); }
$serper = new Serper($chave, Env::get('SERPER_API_KEY_2') ?: null);

/** categoria => [comissão Amazon BR %, rótulo] — fonte: associados.amazon.com.br/welcome/compensation */
$COMISSAO = [
  'beleza'      => [13, 'beleza/cuidados pessoais'],
  'saude'       => [13, 'saúde/cuidados pessoais'],
  'casa'        => [8,  'casa/cozinha'],
  'esporte'     => [8,  'esporte'],
  'pet'         => [11, 'pet'],
  'eletronico'  => [3,  'eletrônicos'],
  'informatica' => [3,  'informática'],
  'celular'     => [2,  'celulares'],
  'games'       => [3,  'games'],
  'alimento'    => [5,  'alimentos'],
];

/** termo => categoria. Sem isso o ranking de oportunidade fica cego pra economia. */
$TERMOS = [
  'melhores celulares 2026'                              => 'celular',
  'melhores iphones para comprar em 2026'                => 'celular',
  'melhores creatinas'                                   => 'saude',
  'melhores creatinas do mercado'                        => 'saude',
  'melhores fones de ouvido bluetooth'                   => 'eletronico',
  'melhores headsets'                                    => 'eletronico',
  'melhores geladeiras 2026'                             => 'casa',
  'melhores geladeiras custo beneficio 2026'             => 'casa',
  'melhores hidratantes faciais'                         => 'beleza',
  'melhores perfumes femininos'                          => 'beleza',
  'melhores oleos capilares'                             => 'beleza',
  'melhores oleos capilares para cabelos cacheados'      => 'beleza',
  'melhores kits de shampoo e condicionador'             => 'beleza',
  'melhores notebooks'                                   => 'informatica',
  'melhores marcas de notebook'                          => 'informatica',
  'melhores notebooks custo beneficio 2026'              => 'informatica',
  'melhores notebooks gamers'                            => 'informatica',
  'melhores smartwatch'                                  => 'eletronico',
  'melhores smartwatch custo benefício 2026'             => 'eletronico',
  'melhores smartwatch para iphone'                      => 'eletronico',
  'melhores smartwatch com gps custo benefício'          => 'eletronico',
  'melhores smartwatch com gps'                          => 'eletronico',
  'melhores smartwatch para corrida'                     => 'eletronico',
  'melhores smartwatch 2026 ate 500 reais'               => 'eletronico',
  'melhores racoes para gato'                            => 'pet',
  'melhores racoes cachorros'                            => 'pet',
  'melhores rações para gato filhote'                    => 'pet',
  'melhores rações para cães'                            => 'pet',
  'melhores rações super premium para cães'              => 'pet',
  'melhores rações para cachorro porte grande'           => 'pet',
  'melhores rações custo benefício para cachorro'        => 'pet',
  'melhores rações para gatos custo beneficio'           => 'pet',
  'melhores kimonos de jiu jitsu'                        => 'esporte',
  'melhores quadros de bike aro 29'                      => 'esporte',
  'melhores jogos de ps2'                                => 'games',
  'melhores jogos nintendo switch'                       => 'games',
  'melhores jogos ps1'                                   => 'games',
  'melhores jogos ps3'                                   => 'games',
  'melhores jogos ps4'                                   => 'games',
  'melhores jogos xbox 360'                              => 'games',
  'melhores ketchup'                                     => 'alimento',
  'melhores queijos'                                     => 'alimento',
  'melhores queijos para tabua de frios'                 => 'alimento',
  'melhores queijos para quem tem colesterol alto'       => 'alimento',
  'melhores queijos para dieta'                          => 'alimento',
  'melhores queijos para fazer pao de queijo'            => 'alimento',
  'melhores queijos para quem tem intolerância a lactose'=> 'alimento',
  'melhores queijos da serra da canastra'                => 'alimento',
  'melhores queijos para fondue'                         => 'alimento',
  'melhores queijos do brasil'                           => 'alimento',
];

$MARKETPLACE = ['amazon.com.br','mercadolivre.com.br','magazineluiza.com.br','americanas.com.br',
                'casasbahia.com.br','shopee.com.br','submarino.com.br','extra.com.br','kabum.com.br',
                'pontofrio.com.br','carrefour.com.br','aliexpress.com','shoptime.com.br','netshoes.com.br'];
$MIDIA = ['techtudo.com.br','canaltech.com.br','olhardigital.com.br','tecmundo.com.br','g1.globo.com',
          'uol.com.br','terra.com.br','estadao.com.br','folha.uol.com.br','cnnbrasil.com.br',
          'zoom.com.br','buscape.com.br','tudocelular.com','showmetech.com.br','ge.globo.com',
          'gizmodo.uol.com.br','r7.com','ig.com.br','metropoles.com','veja.abril.com.br','cnet.com',
          'reclameaqui.com.br','ihappy.com.br'];
$FORUM = ['reddit.com','quora.com','youtube.com','tiktok.com','instagram.com','facebook.com'];

function dominio(string $url): string {
  $h = parse_url($url, PHP_URL_HOST) ?: '';
  return preg_replace('/^www\./', '', strtolower($h));
}

$termos = $TERMOS;
if ($LIMIT > 0) $termos = array_slice($TERMOS, 0, $LIMIT, true);

$res = [];
$i = 0;
foreach ($termos as $termo => $cat) {
  $i++;
  try {
    $r = $serper->searchRich($termo, 10);
  } catch (Throwable $e) {
    echo str_pad((string)$i,3,' ',STR_PAD_LEFT).". ✗ $termo — ".substr($e->getMessage(),0,90)."\n";
    continue;
  }
  $org = $r['organic'] ?? [];
  $c = ['marketplace'=>0,'midia'=>0,'nicho'=>0,'forum'=>0];
  $doms = [];
  foreach (array_slice($org,0,10) as $o) {
    $d = dominio($o['link'] ?? '');
    if ($d==='') continue;
    $doms[] = $d;
    if (in_array($d,$MARKETPLACE,true))      $c['marketplace']++;
    elseif (in_array($d,$MIDIA,true))        $c['midia']++;
    elseif (in_array($d,$FORUM,true))        $c['forum']++;
    else                                     $c['nicho']++;
  }
  [$pct, $rotulo] = $COMISSAO[$cat];
  $res[$termo] = [
    'categoria'    => $cat,
    'comissao_pct' => $pct,
    'comissao_txt' => $rotulo,
    'top10'        => $c,
    'dominios'     => $doms,
    'paa'          => count($r['peopleAlsoAsk'] ?? []),
    'related'      => count($r['relatedSearches'] ?? []),
    'answerBox'    => !empty($r['answerBox']),
    'knowledge'    => !empty($r['knowledgeGraph']),
  ];
  printf("%3d. %-52s mkt:%d midia:%d NICHO:%d forum:%d | PAA:%d rel:%d | com:%d%%\n",
    $i, mb_substr($termo,0,52), $c['marketplace'], $c['midia'], $c['nicho'], $c['forum'],
    $res[$termo]['paa'], $res[$termo]['related'], $pct);
  usleep(300000);
}

if (!is_dir(dirname($OUT))) mkdir(dirname($OUT), 0777, true);
file_put_contents($OUT, json_encode($res, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
echo "\n".count($res)." termos analisados -> ".basename($OUT)."\n";
