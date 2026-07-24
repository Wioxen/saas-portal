<?php
/** cc — POPUP DE CAPTURA DE LEAD para as Landing Pages de afiliado PAGO.
 *  Motivo (Hotmart Cast #243 / low ticket): as LPs pagas hoje mandam o clique pra Amazon e PERDEM
 *  a pessoa pra sempre. Este popup captura o e-mail dos ~90% que NÃO clicam-compram na 1ª visita,
 *  pra remarketar de graça (Black Friday / queda de preço) e transformar tráfego pago em LISTA.
 *
 *  Infra reusada (validada em produção): webhook n8n -> Mautic, MESMO payload de 6 campos do
 *  vd_newsletter_submit (docs/vagas-discover/inc/ajax.php) e da estática que já roda ao vivo
 *  (curso-tecnico-senac-salvador.html). Segmentação por campanha + respostas.origem.
 *
 *  POST client-side em modo cors com Content-Type: application/json FUNCIONA. Verificado em 24/07:
 *  o n8n NÃO devolve "*" — ele ECOA o Origin recebido (qualquer um), e o preflight OPTIONS responde
 *  204 com Access-Control-Allow-Headers: content-type. Testado com Origin comocomprar.com.br: passa.
 *  O bug histórico do no-cors (header stripado) NÃO se aplica aqui — não usamos no-cors.
 *
 *  O webhook VALIDA se o e-mail existe: endereço inexistente volta HTTP 400 + {"valido":false}
 *  (e o Gmail chega normalizado, sem os pontos). Por isso o submit trata 400/valido:false à parte.
 *
 *  Gatilhos calibrados p/ NÃO canibalizar o clique Amazon (que É a conversão paga, evento
 *  AW-16696952717). Regra: o popup só pode aparecer quando a visita JÁ ACABOU de fato —
 *    · exit-intent (só em ponteiro fino/hover real, i.e. desktop; touch não dispara mouseout);
 *    · scroll >= 85% — quem chegou aí já passou por TODOS os botões .cc-amz-cta;
 *    · e NUNCA depois de um clique em .cc-amz-cta (a pessoa foi pra Amazon; se voltar na aba,
 *      um modal bloqueando a página impediria o 2º clique).
 *  Sem timer: um contador cego interrompe quem ainda está lendo e ainda não chegou no CTA — é o
 *  único gatilho capaz de custar o clique pago, então não existe. Captura menos, mas não canibaliza.
 *
 *  Por que não A/B em LPs separadas: com 3x R$25/dia, detectar queda de 25%->20% no clique exigiria
 *  ~1.200 sessões por braço (~2-3 meses), além de duplicar URL (canibalização) e dividir a verba,
 *  cegando a otimização do Ads. O risco se resolve no gatilho, não na medição.
 *
 *  Uso: require_once __DIR__.'/_cc_lp_leadpop.php'; $h .= cc_leadpop([...]);
 */

if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

/**
 * @param array $c titulo, sub, origem (obrigatórios); emoji, btn, campanha (opcionais)
 * @return string HTML+JS self-contained do popup (position:fixed, some no localStorage por 180 dias)
 */
function cc_leadpop(array $c): string {
    $emoji    = e($c['emoji']    ?? '🔔');
    $titulo   = e($c['titulo']   ?? 'Receba as melhores ofertas');
    $sub      = e($c['sub']      ?? 'Avisamos quando baixar de preço. É grátis, sem spam.');
    $btn      = e($c['btn']      ?? 'Quero os alertas');
    $origem   = preg_replace('/[^a-z0-9_]/','', strtolower((string)($c['origem'] ?? 'lp')));
    $campanha = preg_replace('/[^a-z0-9_]/','', strtolower((string)($c['campanha'] ?? 'comocomprar')));
    $webhook  = 'https://n8n-1.digite.com.br/webhook/f5e44c10-12a6-4247-a6e8-2699d0d80a54';

    $html =
      "<div id='ccLeadPop' role='dialog' aria-modal='true' aria-labelledby='ccLpTitle' style='position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:1rem;background:rgba(8,20,40,.55)'>"
      ."<div style='position:relative;max-width:440px;width:100%;background:#fff;border-radius:16px;padding:2rem 1.6rem 1.6rem;box-shadow:0 24px 60px rgba(0,0,0,.3);font-family:system-ui,Arial,sans-serif;color:#1a1a1a'>"
      ."<button id='ccLpClose' type='button' aria-label='Fechar' style='position:absolute;top:.6rem;right:.8rem;border:0;background:none;font-size:1.6rem;line-height:1;color:#94a3b8;cursor:pointer'>&times;</button>"
      ."<form id='ccLeadForm' novalidate>"
      ."<div style='font-size:2.2rem;text-align:center;margin-bottom:.3rem' aria-hidden='true'>$emoji</div>"
      ."<h3 id='ccLpTitle' style='margin:0 0 .4rem;font-size:1.25rem;color:#9a3412;text-align:center'>$titulo</h3>"
      ."<p style='margin:0 0 1.1rem;font-size:.95rem;color:#475569;text-align:center'>$sub</p>"
      ."<input id='ccLfNome' type='text' placeholder='Seu nome' autocomplete='name' required style='width:100%;box-sizing:border-box;margin-bottom:.7rem;padding:.85rem .9rem;font-size:1rem;border:1px solid #c4cedd;border-radius:9px'>"
      ."<input id='ccLfEmail' type='email' placeholder='Seu melhor e-mail' autocomplete='email' required style='width:100%;box-sizing:border-box;margin-bottom:.7rem;padding:.85rem .9rem;font-size:1rem;border:1px solid #c4cedd;border-radius:9px'>"
      ."<input id='ccLfHp' type='text' tabindex='-1' autocomplete='off' aria-hidden='true' style='position:absolute;left:-9999px;width:1px;height:1px;opacity:0'>"
      ."<button id='ccLfBtn' type='submit' style='width:100%;padding:.9rem;font-size:1.05rem;font-weight:800;color:#fff;background:linear-gradient(135deg,#f97316,#ea580c);border:0;border-radius:9px;cursor:pointer'>$btn</button>"
      ."<p id='ccLfMsg' role='status' aria-live='polite' style='margin:.6rem 0 0;font-size:.9rem;min-height:1.1em;text-align:center'></p>"
      ."<p style='margin:.5rem 0 0;font-size:.78rem;color:#7a8694;text-align:center'>Seus dados estão protegidos. Sem spam, cancele quando quiser.</p>"
      ."</form></div></div>";

    $js = <<<'JS'
(function(){
  'use strict';
  if(window.__ccLeadInit) return; window.__ccLeadInit=1;
  var WEBHOOK='__WEBHOOK__', CAMPANHA='__CAMPANHA__', ORIGEM='__ORIGEM__';
  var KSUB='cc_lead_sub', SUB_DAYS=180, SCROLL_PCT=85;
  var pop=document.getElementById('ccLeadPop'); if(!pop) return;
  var f=document.getElementById('ccLeadForm'); if(!f) return;
  function num(k){try{var v=localStorage.getItem(k);return v?+v:0;}catch(e){return 0;}}
  function setv(k,v){try{localStorage.setItem(k,v);}catch(e){}}
  // ATENCAO: proibido o operador logico AND de dois caracteres neste JS. O wptexturize do WP encoda
  // esse caractere como entidade quando ele vem seguido de identificador DENTRO do <script> que vai
  // no content, e o script inteiro morre com SyntaxError: o popup fica display:none pra sempre, sem
  // nenhum erro visivel. Seguido de parentese ele escapa, o que torna o bug intermitente e dificil
  // de enxergar. Por isso if aninhado em vez de condicao composta. Guarda no PHP, no fim do arquivo.
  var ultimaSub=num(KSUB);
  if(ultimaSub){ if((Date.now()-ultimaSub) < SUB_DAYS*864e5) return; }
  var opened=false, saiu=false;
  // Clicou pra Amazon = conversao paga aconteceu. A partir daqui o popup fica MUDO nesta pageview:
  // a aba original continua aberta e um modal bloqueando a pagina impediria um 2o clique na volta.
  document.addEventListener('click',function(e){
    var t=e.target; if(!t) return; if(!t.closest) return;
    var a=t.closest('a');
    if(!a) return;
    if(a.classList.contains('cc-amz-cta') || a.getAttribute('data-store') || /\/go\/|amazon\.|amzn\.|mercadolivre\.|meli\.la/.test(a.getAttribute('href')||'')){
      saiu=true; desarmar();
    }
  },true);
  function desarmar(){
    window.removeEventListener('scroll',onScroll);
    document.removeEventListener('mouseout',onExit);
  }
  function open(){ if(opened||saiu) return; opened=true;
    desarmar();
    pop.style.display='flex'; document.body.style.overflow='hidden';
    setTimeout(function(){var n=document.getElementById('ccLfNome'); if(n) n.focus();},120);
  }
  function close(){ pop.style.display='none'; document.body.style.overflow=''; }
  function onScroll(){ var el=document.documentElement, sc=el.scrollHeight-el.clientHeight; if(sc<=0) return; if((el.scrollTop/sc*100)>=SCROLL_PCT) open(); }
  function onExit(e){ if(e.clientY>0) return; if(e.relatedTarget) return; open(); }
  window.addEventListener('scroll',onScroll,{passive:true});
  // exit-intent so onde existe ponteiro fino de verdade; em touch mouseout dispara falso e o modal
  // apareceria no meio da leitura, justamente o que nao pode acontecer.
  if(window.matchMedia){
    if(window.matchMedia('(hover:hover) and (pointer:fine)').matches){
      document.addEventListener('mouseout',onExit);
    }
  }
  document.getElementById('ccLpClose').addEventListener('click',close);
  pop.addEventListener('click',function(e){ if(e.target===pop) close(); });
  document.addEventListener('keydown',function(e){ if(e.key!=='Escape') return; if(pop.style.display!=='none') close(); });
  f.addEventListener('submit',function(e){
    e.preventDefault();
    var hp=document.getElementById('ccLfHp'); if(hp){ if(hp.value){ close(); return; } }
    var nome=document.getElementById('ccLfNome').value.trim();
    var email=document.getElementById('ccLfEmail').value.trim();
    var msg=document.getElementById('ccLfMsg'), btn=document.getElementById('ccLfBtn');
    if(nome.length<2 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ msg.textContent='Preencha seu nome e um e-mail válido.'; msg.style.color='#c0392b'; return; }
    var label=btn.textContent; btn.disabled=true; btn.style.opacity='.6'; btn.textContent='Enviando...'; msg.textContent='';
    var payload={ id:1, nome:nome, email:email.toLowerCase(), campanha:CAMPANHA, phone:'', respostas:{origem:ORIGEM, tipo:'lp_afiliado_promo'} };
    fetch(WEBHOOK,{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload), keepalive:true })
    .then(function(r){
      if(r.ok) return;
      // O n8n valida se o endereco EXISTE e devolve 400 + {"valido":false} quando nao existe.
      // Sem separar esse caso, um typo cai no erro generico "tente de novo" e o lead se perde.
      return r.json().catch(function(){ return null; }).then(function(j){
        var invalido = j ? (j.valido===false) : false;
        throw new Error(invalido ? 'invalido' : 'falha');
      });
    })
    .then(function(){
      setv(KSUB, Date.now());
      f.querySelectorAll('input,button').forEach(function(el){el.style.display='none';});
      msg.textContent='✓ Pronto! Vamos te avisar das melhores ofertas.'; msg.style.color='#1b7a36';
      setTimeout(close,2600);
    })
    .catch(function(err){
      btn.disabled=false; btn.style.opacity='1'; btn.textContent=label;
      var eraInvalido = err ? (err.message==='invalido') : false;
      msg.textContent = eraInvalido
        ? 'Esse e-mail não parece existir. Confira e tente de novo.'
        : 'Não consegui enviar agora. Tente de novo.';
      msg.style.color='#c0392b';
    });
  });
})();
JS;
    $js = strtr($js, ['__WEBHOOK__'=>$webhook, '__CAMPANHA__'=>$campanha, '__ORIGEM__'=>$origem]);

    // GUARDA — bug real pego em 24/07 nas 3 LPs: o wptexturize do WP encoda "&" seguido de
    // identificador DENTRO do <script> que vai no content ("&& x" -> "&#038;&#038; x"), o script
    // quebra com SyntaxError e o popup fica display:none pra sempre. Como "&& (" NAO e afetado, o
    // bug e intermitente e passa no olho. Falhar aqui e barato; publicar popup morto nao e.
    if (strpos($js, '&&') !== false) {
        throw new RuntimeException("cc_leadpop: o JS contem '&&' — o wptexturize quebra o script no render. Use ifs aninhados.");
    }
    return $html."<script>".$js."</script>";
}
