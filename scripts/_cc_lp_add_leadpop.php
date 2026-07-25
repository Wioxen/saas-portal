<?php
declare(strict_types=1);
/** cc — aplica o POPUP DE CAPTURA DE LEAD (cc_leadpop) nas 3 LPs de afiliado PAGO, AO VIVO.
 *  NÃO re-roda o gerador (não toca imagem/Serper/schema): só busca o content raw via context=edit,
 *  anexa o popup e re-PUT. Idempotente (marca id='ccLeadPop'); --refresh troca o bloco existente.
 *  php scripts/_cc_lp_add_leadpop.php            # DRY (mostra o que faria)
 *  php scripts/_cc_lp_add_leadpop.php --confirm  # aplica
 *  php scripts/_cc_lp_add_leadpop.php --confirm --refresh  # regrava o popup já existente
 */
date_default_timezone_set('America/Sao_Paulo');
$ROOT=dirname(__DIR__); require_once $ROOT.'/lib/Env.php'; Env::load($ROOT.'/.env');
require_once __DIR__.'/_cc_inc_review.php';
require_once __DIR__.'/_cc_lp_leadpop.php';
$S=require $ROOT.'/sites.php'; $V=$S['comocomprar'];
$AUTH=base64_encode($V['wp_user'].':'.$V['wp_app_password']);
$U=rtrim($V['wp_url'],'/');
$op=getopt('',['confirm','refresh']); $CONFIRM=isset($op['confirm']); $REFRESH=isset($op['refresh']);

$LPS=[
 ['slug'=>'melhor-perfume-arabe-masculino','emoji'=>'🔔','origem'=>'lp_perfume_arabe',
  'titulo'=>'Receba as promoções dos perfumes árabes',
  'sub'=>'Avisamos quando os mais elogiados (Club de Nuit, Asad, 9pm) baixarem de preço. É grátis, sem spam.'],
 ['slug'=>'comprar-extratora-de-estofado','emoji'=>'🛋️','origem'=>'lp_extratora',
  'titulo'=>'Receba as ofertas das melhores extratoras',
  'sub'=>'Avisamos quando WAP, Karcher e Vonder baixarem de preço. Higienize o sofá gastando menos. É grátis.'],
 ['slug'=>'melhor-depilador-de-luz-pulsada','emoji'=>'✨','origem'=>'lp_depilador_ipl',
  'titulo'=>'Receba as promoções dos depiladores IPL',
  'sub'=>'Avisamos quando os melhores depiladores de luz pulsada baixarem de preço. É grátis, sem spam.'],
];

$MARK="id='ccLeadPop'";

foreach($LPS as $lp){
  // content RAW exige context=edit + auth
  $c=curl_init("$U/wp-json/wp/v2/pages?slug={$lp['slug']}&status=any&context=edit&_fields=id,content");
  curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_HTTPHEADER=>["Authorization: Basic $AUTH"],CURLOPT_SSL_VERIFYPEER=>0]);
  $j=json_decode((string)curl_exec($c),true); curl_close($c);
  if(!is_array($j)||!count($j)){ echo "✗ {$lp['slug']}: página não encontrada\n"; continue; }
  $id=(int)$j[0]['id'];
  $content=(string)($j[0]['content']['raw'] ?? '');
  if($content===''){ echo "✗ {$lp['slug']} #$id: content raw vazio (context=edit falhou?)\n"; continue; }

  $tem = strpos($content,$MARK)!==false;
  if($tem && !$REFRESH){ echo "• {$lp['slug']} #$id: já tem popup — pulando (use --refresh p/ regravar)\n"; continue; }

  $pop = cc_leadpop($lp);

  if($tem){
    // remove bloco antigo: <div id='ccLeadPop' ...> ... </div></div><script>...</script>
    $novo = preg_replace('#<div id=\'ccLeadPop\'.*?</script>#s', $pop, $content, 1);
    if($novo===null || $novo===$content){ echo "⚠ {$lp['slug']} #$id: não consegui localizar bloco antigo p/ trocar — pulando\n"; continue; }
  } else {
    $novo = $content . $pop;
  }

  echo "→ {$lp['slug']} #$id: ".($tem?'REGRAVA':'ANEXA')." popup (origem={$lp['origem']}), +".strlen($pop)." bytes\n";
  if(!$CONFIRM){ continue; }

  $payload=json_encode(['content'=>$novo],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  [$code,$body]=cc_req('POST',"$U/wp-json/wp/v2/pages/$id",["Authorization: Basic $AUTH","Content-Type: application/json"],$payload);
  if($code!==200){ echo "  ✗ HTTP $code ".substr((string)$body,0,160)."\n"; continue; }
  // confirma no HTML renderizado ao vivo. O <div> sozinho NÃO basta: ele nasce display:none, então
  // se o kses do WP comer o <script> o popup fica invisível pra sempre e o erro passa silencioso.
  $r=json_decode((string)$body,true); $rend=(string)($r['content']['rendered'] ?? '');
  $temDiv=strpos($rend,'ccLeadPop')!==false; $temJs=strpos($rend,'__ccLeadInit')!==false;
  // 3ª checagem: o wptexturize encoda '&' dentro do <script> e mata o JS com SyntaxError silencioso.
  // Se aparecer entidade no bloco do popup, o popup está publicado porém MORTO.
  $ini=strpos($rend,'__ccLeadInit'); $blocoJs = $ini!==false ? substr($rend,$ini,8000) : '';
  $mangled = substr_count($blocoJs,'&#038;');
  $ok = $temDiv && $temJs && $mangled===0;
  echo "  ".($ok?'✓':'✗')." #$id atualizada — div: ".($temDiv?'SIM':'NÃO')
      ." | script: ".($temJs?'SIM':'NÃO (kses stripou <script>?)')
      ." | JS íntegro: ".($mangled===0?'SIM':"NÃO — $mangled entidade(s) &#038; no bloco, script quebrado")
      ." → ".($r['link'] ?? "$U/?p=$id")."\n";
}
if(!$CONFIRM){ echo "\nDRY. Rode com --confirm para aplicar.\n"; }
