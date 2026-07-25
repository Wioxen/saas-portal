<?php
/** Helpers compartilhados dos geradores/clusters comocomprar (review afiliado). Requer no caller:
 *  $U, $AUTH (base64), $sx (SerperImages), $CATS (array), $CONFIRM (bool), $B (base url). */
if(!function_exists('cc_req')){
function cc_req($m,$u,$h,$b=null){ $c=curl_init($u); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_HTTPHEADER=>$h,CURLOPT_TIMEOUT=>90,CURLOPT_SSL_VERIFYPEER=>0]); if($b!==null)curl_setopt($c,CURLOPT_POSTFIELDS,$b); $r=curl_exec($c);$code=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c); return [$code,$r]; }
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function cc_slugid($s){ global $U,$AUTH;[$c,$b]=cc_req('GET',"$U/wp-json/wp/v2/posts?slug=$s&status=any&_fields=id,featured_media",["Authorization: Basic $AUTH"]);$j=json_decode($b,true);return (is_array($j)&&count($j))?['id'=>(int)$j[0]['id'],'feat'=>(int)($j[0]['featured_media']??0)]:null; }
function cc_upmedia($url,$slug,$alt){ global $U,$AUTH; $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_TIMEOUT=>40,CURLOPT_USERAGENT=>'Mozilla/5.0',CURLOPT_SSL_VERIFYPEER=>0,CURLOPT_REFERER=>'https://www.google.com/']);$bin=curl_exec($ch);$ct=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch); if(!$bin||$code>=400||strlen($bin)<3000)return $url; $ext=stripos($ct,'png')!==false?'png':(stripos($ct,'webp')!==false?'webp':'jpg');$mime=$ext==='png'?'image/png':($ext==='webp'?'image/webp':'image/jpeg'); [$c,$b]=cc_req('POST',"$U/wp-json/wp/v2/media",["Authorization: Basic $AUTH","Content-Type: $mime","Content-Disposition: attachment; filename=\"$slug.$ext\""],$bin);$j=json_decode($b,true);if(($c!==201&&$c!==200)||!isset($j['id']))return $url;$mid=(int)$j['id'];cc_req('POST',"$U/wp-json/wp/v2/media/$mid",["Authorization: Basic $AUTH","Content-Type: application/json"],json_encode(['alt_text'=>$alt],JSON_UNESCAPED_UNICODE));return $j['source_url']??$url; }
function cc_bestLocal($q,$slug,$alt,$fb){ global $U,$sx; $host=parse_url($U,PHP_URL_HOST); $cands=[]; try{foreach($sx->buscarRaw($q,10) as $im){$u=(string)($im['imageUrl']??'');$w=(int)($im['imageWidth']??0);if($u&&$w>=500&&!preg_match('/instagram|tiktok|facebook|pinterest|youtube|lookaside/i',$u))$cands[]=$u;}}catch(Throwable $e){} $cands[]=$fb; foreach($cands as $u){ $loc=cc_upmedia($u,$slug,$alt); if($host&&strpos($loc,$host)!==false)return $loc; } return $fb; }
function cc_upfeat($url,$slug,$alt){ global $U,$AUTH; $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_TIMEOUT=>40,CURLOPT_USERAGENT=>'Mozilla/5.0',CURLOPT_SSL_VERIFYPEER=>0]);$bin=curl_exec($ch);curl_close($ch);$im=@imagecreatefromstring((string)$bin);if(!$im)return 0;$w=imagesx($im);$h=imagesy($im);$tw=1200;$th=675;$d=imagecreatetruecolor($tw,$th);imagefill($d,0,0,imagecolorallocate($d,255,255,255));$sr=$w/$h;$tr=$tw/$th;if($sr>$tr){$sw=(int)round($h*$tr);$sxx=(int)round(($w-$sw)/2);$sh=$h;$sy=0;}else{$sh=(int)round($w/$tr);$sy=(int)round(($h-$sh)/2);$sw=$w;$sxx=0;}imagecopyresampled($d,$im,0,0,$sxx,$sy,$tw,$th,$sw,$sh);$tmp=sys_get_temp_dir().'/cc'.uniqid().'.jpg';imagejpeg($d,$tmp,88);$bin=file_get_contents($tmp);@unlink($tmp);[$c,$b]=cc_req('POST',"$U/wp-json/wp/v2/media",["Authorization: Basic $AUTH","Content-Type: image/jpeg","Content-Disposition: attachment; filename=\"$slug.jpg\""],$bin);$j=json_decode($b,true);if($c!==201||!isset($j['id']))return 0;$mid=(int)$j['id'];cc_req('POST',"$U/wp-json/wp/v2/media/$mid",["Authorization: Basic $AUTH","Content-Type: application/json"],json_encode(['alt_text'=>$alt],JSON_UNESCAPED_UNICODE));return $mid; }
function stars($n){ $n5=$n/2;$f=(int)floor($n5+0.0001);$hf=($n5-$f>=0.5)?1:0;if($f+$hf>5){$f=5;$hf=0;}$em=5-$f-$hf; return "<span style='color:#f59e0b;letter-spacing:1px'>".str_repeat('★',$f).($hf?'⯪':'').str_repeat('☆',$em)."</span>"; }
function css(){ return "<style>#rv h2{color:#0f172a;border-left:5px solid #ea580c;padding-left:.5em;margin:1.6em 0 .5em}#rv h3{color:#0f172a;margin:1.1em 0 .3em}#rv table{width:100%;border-collapse:collapse;margin:1em 0;font-size:.93em}#rv th{background:#9a3412;color:#fff;padding:.55em .7em;text-align:left}#rv td{padding:.55em .7em;border-bottom:1px solid #e5e7eb}#rv a{color:#c2410c}#rv .box{background:linear-gradient(135deg,#fff7ed,#ffedd5);border:2px solid #ea580c;border-radius:12px;padding:16px 20px;margin:1.3rem 0}#rv .alerta{background:#fffbeb;border:1px solid #fcd34d;border-left:4px solid #f59e0b;border-radius:10px;padding:12px 16px;margin:1rem 0;font-size:15px}#rv .leia{background:#fff;border:1px solid #ffedd5;border-left:4px solid #ea580c;border-radius:10px;padding:.9em 1.2em;margin:1.3rem 0}#rv ul{margin:.4em 0 .8em}#rv li{margin:.25em 0}</style>"; }
function eeat(){ return "<div style='display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid #16a34a;border-radius:10px;padding:10px 14px;margin:0 0 1.1rem;font-size:13.5px;color:#166534'><span>✅ <strong>Analisado pela equipe Como Comprar</strong></span><span>🔎 Faixas de preço conferidas na Amazon e no Mercado Livre</span></div>"; }
function fichaRapida($p,$extra=[]){ $rows=[];
 $sp=$p['spec']??''; $rows['Concentração']=stripos($sp,'edt')!==false?'Eau de Toilette (EDT)':'Eau de Parfum (EDP)';
 if(!empty($p['fam']))$rows['Família olfativa']=ucfirst($p['fam']); elseif(!empty($p['lembra']))$rows['Perfil']=ucfirst($p['lembra']);
 $g=stripos($sp,'feminino')!==false?'Feminino':(stripos($sp,'unissex')!==false?'Unissex':(stripos($sp,'masculino')!==false?'Masculino':''));
 if($g)$rows['Gênero']=$g;
 $rows['Nota da redação']=stars($p['nota'])." (".number_format($p['nota'],1,',','')."/10)";
 if(!empty($p['preco']))$rows['Preço médio']=e($p['preco']);
 foreach($extra as $k=>$v)$rows[$k]=$v;
 $h="<div style='overflow-x:auto'><table><tbody>"; foreach($rows as $k=>$v){ if($v==='')continue; $h.="<tr><th style='width:38%'>".e($k)."</th><td>$v</td></tr>"; } return $h."</tbody></table></div>"; }
function originalBox($marca){ return "<div class='alerta'><strong>🛡️ Como saber se o ".e($marca)." é original:</strong> a marca é uma <strong>fabricante real</strong> (não é \"cheiro de grife original\", e sim fragrância própria inspirada). Para não cair em réplica: <strong>(1)</strong> compre de vendedor <strong>bem avaliado</strong> e com muitas vendas; <strong>(2)</strong> desconfie de preço <strong>muito abaixo</strong> do normal; <strong>(3)</strong> confira <strong>lote, lacre e acabamento</strong> do frasco; <strong>(4)</strong> prefira lojas com política de troca. Cheiro é pessoal: a mesma fragrância evolui diferente em cada pele.</div>"; }
function veredito($t,$html){ return "<div class='box'><p style='margin:0 0 .35em;font-weight:800;font-size:1.06em;color:#9a3412'>✅ Veredito final: ".e($t)."</p><p style='margin:0;font-size:15.5px'>$html</p></div>"; }
function scoreBadge($nota,$txt=''){ $pct=(int)round($nota*10); if($txt==='')$txt="nota da redação Como Comprar, com base em desempenho real e no que quem comprou relata."; return "<div style='display:flex;align-items:center;gap:16px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:2px solid #16a34a;border-radius:14px;padding:14px 18px;margin:1.1rem 0'><div style='text-align:center;line-height:1'><div style='font-size:2.2em;font-weight:900;color:#15803d'>$pct%</div><div style='font-size:12px;color:#166534;font-weight:700'>VALE COMPRAR</div></div><div style='font-size:14.5px;color:#166534'>".stars($nota)." <strong>".number_format($nota,1,',','')."/10</strong> — ".e($txt)."</div></div>"; }
function card($p){ global $B; $nf=number_format($p['nota'],1,',',''); $slug=e($p['slug']);
 $pc=(!empty($p['pros'])||!empty($p['cons']))?"<div style='font-size:12.5px;margin-top:.35em;line-height:1.55'>".(!empty($p['pros'])?"<span style='color:#16a34a'><strong>Prós:</strong> ".e(implode(', ',$p['pros']))."</span><br>":"").(!empty($p['cons'])?"<span style='color:#dc2626'><strong>Contras:</strong> ".e(implode(', ',$p['cons']))."</span>":"")."</div>":"";
 return "<div style='border:2px solid #ffedd5;border-radius:14px;padding:16px;margin:1.1rem 0;display:flex;gap:16px;align-items:center;flex-wrap:wrap;background:#fff'>"
 ."<a href='$B/go/$slug' target='_blank' rel='nofollow sponsored noopener'><img src='".e($p['img'])."' alt='".e($p['nome'])."' style='width:112px;height:112px;object-fit:contain;background:#fff;border-radius:8px' loading='lazy' referrerpolicy='no-referrer'></a>"
 ."<div style='flex:1;min-width:200px'><span style='display:inline-block;background:#fef3c7;color:#92400e;font-size:12px;font-weight:700;padding:2px 9px;border-radius:20px;margin-bottom:4px'>".e($p['tag'])."</span><br><a href='$B/go/$slug' target='_blank' rel='nofollow sponsored noopener' style='text-decoration:none'><strong style='font-size:1.04em;color:#0f172a'>".e($p['nome'])."</strong></a><div style='margin:.3em 0'>".stars($p['nota'])." <strong>$nf/10</strong></div><div style='font-size:14px;color:#475569'>".e($p['spec'])."</div>$pc<div style='font-size:1.03em;font-weight:800;color:#16a34a;margin-top:.25em'>".e($p['preco'])."</div></div>"
 ."<div style='display:flex;flex-direction:column;min-width:150px;gap:6px'>"
 ."<a href='$B/go/$slug' target='_blank' rel='nofollow sponsored noopener' style='background:#ea580c;color:#fff;padding:11px 18px;border-radius:10px;font-weight:700;text-decoration:none;white-space:nowrap;display:block;text-align:center'>Ver na Amazon →</a>"
 ."<a href='$B/go/$slug-ml' target='_blank' rel='nofollow sponsored noopener' style='background:#fff;color:#ea580c;border:2px solid #ea580c;padding:9px 18px;border-radius:10px;font-weight:700;text-decoration:none;white-space:nowrap;display:block;text-align:center;font-size:.92em'>Ver no Mercado Livre</a>"
 ."</div></div>"; }
function clonais($a){ $o=[];foreach($a as $s)$o[]=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); return json_encode($o,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function faqSchema($faq){ $m=[];foreach($faq as [$q,$a])$m[]=['@type'=>'Question','name'=>$q,'acceptedAnswer'=>['@type'=>'Answer','text'=>trim(strip_tags($a))]]; return ['@context'=>'https://schema.org','@type'=>'FAQPage','mainEntity'=>$m]; }
function faqSubject($kw){ $s=' '.mb_strtolower(trim((string)$kw),'UTF-8').' ';
 $s=preg_replace('~\b(os |as |o |a )?(melhor(es)?|vale a pena|custo[\s-]benef[ií]cio|qual (o |a )?(comprar|melhor|diferen[çc]a)|onde comprar|onde|review|[eé] bom|de presente|para que serve|como (escolher|comprar|funciona)|passo a passo|guia( de)? compra|comprar|seo|2026|2025)\b~u',' ',$s);
 $s=trim(preg_replace('~\s+~',' ',$s));
 $s=preg_replace('~^(de |da |do |para |por |com |e |ou |na |no |nas |nos |em )+~u','',$s); // apara preposição solta no início
 $s=preg_replace('~( de| da| do| para| por| com| e| ou| a| o)+$~u','',$s);   // e no fim
 return trim($s); }
function faqH($faq,$kw=''){ $subj=$kw!==''?faqSubject($kw):''; $tit=$subj!==''?('Perguntas frequentes sobre '.$subj):'Perguntas frequentes'; $h="<h2>".e($tit)."</h2>";foreach($faq as [$q,$a])$h.="<h3>".e($q)."</h3><p>".$a."</p>"; return $h; }
function prodSchema($p){ return ['@context'=>'https://schema.org','@type'=>'Product','name'=>$p['nome'],'image'=>$p['img']??'','review'=>['@type'=>'Review','author'=>['@type'=>'Organization','name'=>'Como Comprar'],'datePublished'=>date('Y-m-d'),'reviewBody'=>'Avaliação da redação Como Comprar com base em especificações reais e nas avaliações de quem comprou.','reviewRating'=>['@type'=>'Rating','ratingValue'=>number_format($p['nota']/2,1,'.',''),'bestRating'=>'5','worstRating'=>'1']]]; }
/** ANTI-IA: remove travessão (—/–/−) preservando <style> (fingerprint de IA proibido na rede). /u seguro p/ emoji. */
function cc_limpaTravessao($html){ if($html===''||(strpos($html,'—')===false&&strpos($html,'–')===false&&strpos($html,'−')===false))return $html;
 require_once dirname(__DIR__).'/lib/PostFinishing.php';
 $styles=[]; $html=preg_replace_callback('#<style[^>]*>.*?</style>#is',function($m)use(&$styles){$k='@@STY'.count($styles).'@@';$styles[$k]=$m[0];return $k;},$html);
 $html=PostFinishing::limparTravessoes($html);
 foreach($styles as $k=>$v)$html=str_replace($k,$v,$html); return $html; }
/** injeta nota metodologia + sticky CTA mobile (idempotente) + limpa travessão. $B global. */
function cc_finish($html){ global $B;
 if(strpos($html,'cc-metodologia')===false){ $met='<!-- cc-metodologia --><p style="font-size:13px;color:#64748b;background:#f8fafc;border-left:3px solid #ea580c;border-radius:0 8px 8px 0;padding:8px 12px;margin:0 0 1.1rem"><strong>Como avaliamos:</strong> comparamos especificações reais, avaliações de quem comprou e faixas de preço na Amazon e no Mercado Livre, sem ficha inflada.</p>'; $pp=strpos($html,'</p>'); $html=($pp!==false)?substr($html,0,$pp+4).$met.substr($html,$pp+4):$met.$html; }
 if(strpos($html,'cc-sticky-cta')===false && preg_match('#href=[\'"]('.preg_quote($B,'#').'/go/[a-z0-9-]+)[\'"]#',$html,$mm)){ $html.='<!-- cc-sticky-cta --><style>@media(max-width:768px){#cc-sticky{position:fixed;left:0;right:0;bottom:0;z-index:9999;background:#fff;border-top:1px solid #e5e7eb;box-shadow:0 -2px 10px rgba(0,0,0,0.08);padding:9px 12px;display:flex;gap:10px;align-items:center;justify-content:space-between}#cc-sticky span{font-size:13px;font-weight:700;color:#0f172a}#cc-sticky a{background:#ea580c;color:#fff;padding:10px 16px;border-radius:9px;font-weight:800;text-decoration:none;white-space:nowrap;font-size:14px}}@media(min-width:769px){#cc-sticky{display:none}}</style><div id="cc-sticky"><span>🛒 Ver a nossa recomendação</span><a href="'.$mm[1].'" target="_blank" rel="nofollow sponsored noopener">Ver oferta →</a></div>'; }
 return cc_limpaTravessao($html); }
/** Monta o destino REAL de um /go/{slug}: busca do produto na loja JÁ com afiliado.
 *  Amazon = busca por termo + tag (Associates permite link de busca com tag). ML = busca por termo (+ afiliado se configurado).
 *  Deslugifica o slug (que É derivado do nome do produto) → termo de busca fiel. Cobre 100% dos produtos sem colar link a link. */
/** Resolve o termo -> ASIN de produto Amazon direto (via Serper /search, 1º resultado /dp/). Cache em data/_go_asin_cache.json.
 *  Fallback: retorna null (o cc_go_target cai na busca). Nunca quebra. */
function cc_amz_dp($termo){
 static $cache=null; $cf=dirname(__DIR__).'/data/_go_asin_cache.json';
 if($cache===null){ $cache=is_file($cf)?(json_decode((string)file_get_contents($cf),true)?:[]):[]; }
 $key=mb_strtolower(trim($termo)); if($key==='')return null;
 if(array_key_exists($key,$cache)) return $cache[$key]?:null; // '' = já resolvido sem /dp/, não repetir
 $k=getenv('SERPER_API_KEY'); if(!$k)return null;
 $ch=curl_init('https://google.serper.dev/search'); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_POST=>1,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>["X-API-KEY: $k","Content-Type: application/json"],CURLOPT_POSTFIELDS=>json_encode(['q'=>$termo.' amazon','gl'=>'br','hl'=>'pt-br','num'=>10])]); $d=json_decode((string)curl_exec($ch),true); curl_close($ch);
 $asin=null; foreach(($d['organic']??[]) as $o){ if(preg_match('~amazon\.com\.br/(?:[^/]*/)?dp/([A-Z0-9]{10})~i',(string)($o['link']??''),$m)){ $asin=strtoupper($m[1]); break; } }
 $cache[$key]=$asin?:''; @file_put_contents($cf,json_encode($cache,JSON_UNESCAPED_UNICODE));
 return $asin;
}
/** Link de afiliado ML já gerado para este slug, se existir. O mapa é exportado do banco
 *  (wp_prli_links) por scripts/_cc_ml_atualizar_map.php. Retorna null se desconhecido. */
function cc_ml_afiliado($slug){
 static $map=null; $f=dirname(__DIR__).'/data/_cc_ml_afiliado_map.json';
 if($map===null){ $map=is_file($f)?(json_decode((string)file_get_contents($f),true)?:[]):[]; }
 $s=strtolower(trim($slug));
 return isset($map[$s]) && stripos($map[$s],'meli.la')!==false ? $map[$s] : null;
}
function cc_go_target($slug){
 $tag=Env::get('AMAZON_ASSOC_TAG','iga095-20');
 $ml=(substr($slug,-3)==='-ml'); $base=$ml?substr($slug,0,-3):$slug;
 $termo=trim(preg_replace('/\s+/',' ',str_replace('-',' ',$base)));  // deslug
 if($ml){
   /* 22/07: o programa de afiliados do ML NÃO aceita tag em querystring nem fragmento.
      O antigo `$u.'#'.$mltag` nunca rastreou nada — fragmento não vai ao servidor.
      O link real só sai do gerador do ML (endpoint createLink) e é opaco (blob `ref` cifrado).
      Estratégia: consultar o mapa dos links JÁ gerados (data/_cc_ml_afiliado_map.json).
      Se o produto já é conhecido, o post nasce com afiliado de verdade.
      Se for produto novo, cai na busca e o job de upgrade resolve depois
      (ver _cc_ml_resolver_lote.php + _cc_ml_aplicar_lote_SERVER.php). */
   $m=cc_ml_afiliado($slug); if($m) return $m;
   return 'https://lista.mercadolivre.com.br/'.$base; }
 // NOVO 20/07: tenta PRODUTO DIRETO (converte mais); fallback = busca (nunca quebra)
 $asin=cc_amz_dp($termo); if($asin) return "https://www.amazon.com.br/dp/$asin?tag=$tag";
 return 'https://www.amazon.com.br/s?'.http_build_query(['k'=>$termo,'tag'=>$tag]);
}
/** cria/garante os Pretty Links de todos os /go/ do html apontando pro destino REAL (busca do produto + afiliado). */
function cc_prettylinks($html){ global $U,$AUTH; preg_match_all('#/go/([a-z0-9-]+)#',$html,$g); foreach(array_unique($g[1]??[]) as $s){ $ml=(substr($s,-3)==='-ml'); cc_req('POST',"$U/wp-json/cc/v1/pretty-link",["Authorization: Basic $AUTH","Content-Type: application/json"],json_encode(['slug'=>'go/'.$s,'target_url'=>cc_go_target($s),'name'=>($ml?'ML: ':'Amazon: ').$s],JSON_UNESCAPED_UNICODE)); usleep(90000); } }
/** SEO DEEP: extrai os cards do html e injeta (antes da FAQ) tabela comparativa detalhada + "melhor para cada perfil".
 *  Aprofunda a página (+tabela +lista +H2 +densidade de keyword) sem inventar dado — usa o que já está nos cards. */
function cc_deepen($html,$kw){ if(strpos($html,'cc-seo-deep')!==false)return $html;
 $Kw=function($s){return mb_strtoupper(mb_substr($s,0,1)).mb_substr($s,1);}; $kw=trim(preg_replace('/^melhor(es)?\s+/u','',mb_strtolower($kw)));
 $cards=[]; foreach(preg_split("~(?=<div style='border:2px solid #ffedd5;border-radius:14px)~u",$html) as $blk){ if(strpos($blk,'border:2px solid #ffedd5')===false)continue;
   if(!preg_match("~font-size:1\.04em;color:#0f172a'>([^<]+)~u",$blk,$mn))continue; $c=['nome'=>trim($mn[1])];
   $c['tag']=preg_match('~#92400e;[^>]*>([^<]+)</span>~u',$blk,$m)?trim($m[1]):'';
   $c['nota']=preg_match('~<strong>([0-9],[0-9])/10~u',$blk,$m)?$m[1]:'';
   $c['preco']=preg_match("~color:#16a34a;margin-top:\.25em'>([^<]+)</div>~u",$blk,$m)?trim($m[1]):'';
   $c['pros']=preg_match('~<strong>Prós:</strong> ([^<]+)~u',$blk,$m)?trim($m[1]):'';
   $c['contras']=preg_match('~<strong>Contras:</strong> ([^<]+)~u',$blk,$m)?trim($m[1]):'';
   $cards[]=$c; }
 if(count($cards)<2)return $html;
 $b="<!-- cc-seo-deep --><h2>Comparativo de ".$Kw($kw).": tabela lado a lado</h2><p>Para facilitar a decisão, veja o <strong>comparativo dos melhores ".e($kw)."</strong> reunido numa tabela, com nota, prós, contras e faixa de preço de cada modelo:</p>";
 $b.="<table><thead><tr><th>".$Kw($kw)."</th><th>Nota</th><th>Prós</th><th>Contras</th><th>Preço</th></tr></thead><tbody>";
 foreach($cards as $c){ $b.="<tr><td><strong>".e($c['nome'])."</strong>".($c['tag']?"<br><span style='font-size:11.5px;color:#64748b'>".e($c['tag'])."</span>":"")."</td><td>".($c['nota']?e($c['nota'])."/10":"n/d")."</td><td>".($c['pros']?e($c['pros']):"n/d")."</td><td>".($c['contras']?e($c['contras']):"n/d")."</td><td>".e($c['preco'])."</td></tr>"; }
 $b.="</tbody></table>";
 // melhor para cada perfil (deriva das tags)
 $perf=[]; foreach($cards as $c){ $t=mb_strtolower($c['tag']); $lab=''; if(strpos($t,'custo')!==false||strpos($t,'benef')!==false)$lab='Melhor custo-benefício'; elseif(strpos($t,'premium')!==false||strpos($t,'referência')!==false||strpos($t,'top')!==false)$lab='Melhor premium'; elseif(strpos($t,'barat')!==false||strpos($t,'entrada')!==false)$lab='Mais barato'; if($lab&&!isset($perf[$lab]))$perf[$lab]=$c['nome']; }
 if($perf){ $b.="<h2>Qual ".e($kw)." comprar para cada perfil</h2><p>Resumo rápido de <strong>qual ".e($kw)." vale a pena</strong> conforme a sua prioridade:</p><ul>"; foreach($perf as $lab=>$nome)$b.="<li><strong>$lab:</strong> ".e($nome)."</li>"; $b.="<li><strong>Na dúvida:</strong> comece pelo custo-benefício e evolua conforme a sua necessidade.</li></ul>"; }
 // injeta antes da FAQ (ou antes do "leia", ou no fim do #rv)
 $pos=strpos($html,'<h2>Perguntas frequentes'); if($pos===false)$pos=strpos($html,"<div class='leia'");
 return $pos!==false ? substr($html,0,$pos).$b.substr($html,$pos) : $html.$b;
}
function cc_publica($slug,$title,$mt,$md,$focus,$html,$featq,$alt,$schemas){ global $U,$AUTH,$CONFIRM,$CATS,$IMGFALL;
 $html=cc_deepen($html,$focus);
 $html=cc_finish($html);
 $ex=cc_slugid($slug); $w=str_word_count(strip_tags($html)); echo "  $slug: ".($ex?"#{$ex['id']} UPDATE":"CRIAR")." | ~{$w}w | h2=".preg_match_all('/<h2/',$html)." | go=".preg_match_all('#/go/#',$html)."\n"; if(!$CONFIRM)return 0;
 $meta=['rank_math_title'=>$mt,'rank_math_description'=>$md,'rank_math_focus_keyword'=>$focus,'_clonais_schemas'=>preg_replace('/\s*[\x{2014}\x{2013}\x{2212}]\s*/u',', ',clonais($schemas))];
 if($ex){ [$c]=cc_req('POST',"$U/wp-json/wp/v2/posts/{$ex['id']}",["Authorization: Basic $AUTH","Content-Type: application/json"],json_encode(['title'=>$title,'content'=>$html,'meta'=>$meta],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); echo "    ".($c===200?"✓ #{$ex['id']}":"✗ $c")."\n"; cc_prettylinks($html); return $ex['id']; }
 $mid=cc_upfeat(cc_bestLocal($featq,$slug.'-feat',$alt,$IMGFALL),$slug,$alt); $pl=['title'=>$title,'slug'=>$slug,'status'=>'publish','content'=>$html,'categories'=>$CATS,'excerpt'=>$md,'meta'=>$meta]; if($mid)$pl['featured_media']=$mid;
 [$c,$b]=cc_req('POST',"$U/wp-json/wp/v2/posts",["Authorization: Basic $AUTH","Content-Type: application/json"],json_encode($pl,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); $j=json_decode($b,true); if($c!==201&&$c!==200){echo "    ✗ $c ".substr($b,0,120)."\n";return 0;} echo "    ✓ #{$j['id']} → {$j['link']}\n";
 cc_prettylinks($html);
 return (int)$j['id']; }
}
