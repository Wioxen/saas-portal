<?php
$page_title = 'Quem Tem Direito ao Pé-de-Meia 2026: Critérios Oficiais e Regras';
$page_desc = 'Os 5 critérios do Pé-de-Meia 2026: idade, escola, renda, CadÚnico e frequência. Veja se você se enquadra antes da data-base de 7 de agosto.';
$external_cta = 'https://vafast.xyz/nao-recebi-pe-de-meia-o-que-fazer/';
?><!DOCTYPE html>
<html lang='pt-BR'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width, initial-scale=1.0,viewport-fit=cover'>
<meta name='theme-color' content='#0F4C81'>
<meta name='robots' content='index,follow,max-image-preview:large'>
<title><?=$page_title?></title>
<meta name='description' content='<?=$page_desc?>'>

<meta property='og:title' content='<?=$page_title?>'>
<meta property='og:description' content='<?=$page_desc?>'>
<meta property='og:type' content='article'>
<meta property='og:url' content='https://vafast.xyz/quem-tem-direito-pe-de-meia/'>

<link rel='canonical' href='https://vafast.xyz/quem-tem-direito-pe-de-meia/'>
<link rel='preconnect' href='https://pagead2.googlesyndication.com'>
<link rel='preconnect' href='https://www.googletagmanager.com'>
<link rel='preconnect' href='https://vafast.xyz'>

<script async src='https://www.googletagmanager.com/gtag/js?id=AW-16675521270'></script>
<script>
window.dataLayer=window.dataLayer||[];
function gtag(){dataLayer.push(arguments)}
gtag('js',new Date());
gtag('config','AW-16675521270');
gtag('config','G-Q25F19JPDZ');
function trackEngagement(action,value){gtag('event',action,{'event_category':'pedemeia_vafast','value':value||1})}
gtag('event','page_view_pedemeia',{'page_step':'p3_criterios'});
function gtag_report_conversion(url){
  var fired=false;
  var go=function(){if(fired)return;fired=true;if(url)window.location=url};
  gtag('event','conversion',{'send_to':'AW-16675521270/zjjrCI3gt8sZEPaFwY8-','value':1,'currency':'BRL','event_callback':go});
  setTimeout(go,1200);
  return false;
}
</script>

<style>
*,*::before,*::after{box-sizing:border-box}
html{-webkit-text-size-adjust:100%;scroll-behavior:smooth}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:16px;line-height:1.65;color:#1a1a1a;background:#f5f7fa;-webkit-font-smoothing:antialiased}
img{max-width:100%;height:auto;display:block}
a{color:#0F4C81}
h1,h2,h3{margin:0 0 .55em;line-height:1.25;font-weight:700;letter-spacing:-.015em;color:#1a1a1a}
h1{font-size:1.55rem}h2{font-size:1.3rem;color:#0F4C81}h3{font-size:1.1rem}
p{margin:0 0 1em;color:#333}
.container{max-width:1180px;margin:0 auto;padding:0 16px}

.progress-bar{position:fixed;top:0;left:0;height:3px;background:linear-gradient(90deg,#FF9900,#FFB84D);z-index:200;transition:width .1s ease;width:0}

.top-strip{background:#0F4C81;color:#fff;text-align:center;padding:8px 12px;font-size:.8rem;font-weight:600}
.top-strip strong{color:#FF9900}

.header{background:#fff;border-bottom:3px solid #0F4C81;padding:12px 0;position:sticky;top:0;z-index:90}
.header .container{display:flex;align-items:center;justify-content:space-between}
.header .logo{font-weight:800;font-size:1.05rem;color:#0F4C81}
.header .logo span{color:#FF9900}
.header .ind-tag{background:#FF9900;color:#1a1a1a;font-size:.72rem;padding:4px 11px;border-radius:12px;font-weight:700;letter-spacing:.5px}

.hero{background:linear-gradient(135deg,#0F4C81 0%,#072a4a 100%);color:#fff;padding:32px 0 36px;position:relative;overflow:hidden}
.hero::before{content:'';position:absolute;top:-30%;right:-10%;width:480px;height:480px;background:radial-gradient(circle,rgba(255,153,0,.15),transparent 60%);pointer-events:none}
.hero-inner{position:relative;z-index:1}
.hero h1{color:#fff;font-size:1.7rem;margin-bottom:.4em;line-height:1.2}
.hero p{color:rgba(255,255,255,.95);font-size:1rem;margin-bottom:18px}
.hero .badges{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:18px}
.hero .badge{background:rgba(255,153,0,.22);color:#FFC677;font-size:.72rem;padding:4px 11px;border-radius:14px;font-weight:600;border:1px solid rgba(255,153,0,.3)}
.hero-cta{display:inline-flex;gap:6px;background:#FF9900;color:#1a1a1a;font-weight:700;padding:13px 26px;border-radius:8px;text-decoration:none;font-size:.95rem;border:2px solid rgba(0,0,0,.05);box-shadow:0 4px 14px rgba(0,0,0,.18)}
.hero-cta:hover{background:#FFAA33}

.hero-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:18px}
.stat-card{background:rgba(255,255,255,.1);backdrop-filter:blur(8px);padding:14px;border-radius:10px;border:1px solid rgba(255,255,255,.18)}
.stat-card .num{font-size:1.5rem;font-weight:800;color:#FF9900;line-height:1;margin-bottom:4px;letter-spacing:-.02em}
.stat-card .lbl{font-size:.72rem;color:rgba(255,255,255,.85);text-transform:uppercase;letter-spacing:.5px;font-weight:600}

.update-line{background:#fff;padding:11px 0;border-bottom:1px solid #e6e6e6;font-size:.78rem;color:#555;text-align:center}
.update-line .live{display:inline-block;width:7px;height:7px;background:#0d8a3b;border-radius:50%;margin-right:5px;vertical-align:middle;animation:livedot 1.6s ease-in-out infinite}
@keyframes livedot{0%,100%{opacity:1}50%{opacity:.4}}

article{padding:18px 0}
article p{font-size:1rem;line-height:1.75}
article ul,article ol{padding-left:22px;margin:0 0 1em}
article ul li,article ol li{margin-bottom:.4em}

.intro-box{background:#fff;border:1px solid #d6dfeb;border-radius:14px;padding:18px;margin:0 0 18px;box-shadow:0 2px 12px rgba(15,76,129,.06)}
.intro-box p:last-child{margin-bottom:0}

.criteria-grid{display:grid;grid-template-columns:1fr;gap:14px;margin:18px 0}
.criteria-card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 2px 14px rgba(15,76,129,.07);position:relative;border-top:4px solid #0F4C81}
.criteria-card .badge-num{position:absolute;top:-14px;left:18px;background:#0F4C81;color:#fff;font-weight:800;font-size:.85rem;padding:5px 13px;border-radius:14px;letter-spacing:.5px}
.criteria-card h3{color:#1a1a1a;font-size:1.15rem;margin-top:6px;margin-bottom:8px;display:flex;align-items:center;gap:10px}
.criteria-card h3 .ico{font-size:1.4rem}
.criteria-card .lead{color:#444;font-size:.95rem;margin-bottom:10px}
.criteria-card .checks{list-style:none;padding:0;margin:0}
.criteria-card .checks li{padding:7px 0 7px 28px;position:relative;font-size:.93rem;color:#333;border-top:1px dashed #e6ecf5}
.criteria-card .checks li:first-child{border-top:0}
.criteria-card .checks li::before{content:'✓';position:absolute;left:6px;top:7px;color:#0d8a3b;font-weight:800}
.criteria-card .checks li.bad::before{content:'✗';color:#d04545}
.criteria-card .num-tag{position:absolute;top:14px;right:14px;width:36px;height:36px;background:#FF9900;color:#1a1a1a;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.05rem}

.checklist-box{background:linear-gradient(135deg,#0F4C81 0%,#072a4a 100%);color:#fff;border-radius:14px;padding:22px;margin:20px 0}
.checklist-box h3{color:#fff;margin:0 0 12px;font-size:1.15rem;display:flex;align-items:center;gap:10px}
.checklist-box ul{margin:0;padding:0;list-style:none}
.checklist-box li{padding:8px 0 8px 30px;position:relative;font-size:.95rem;color:rgba(255,255,255,.95);border-bottom:1px solid rgba(255,255,255,.12)}
.checklist-box li:last-child{border-bottom:0}
.checklist-box li::before{content:'';position:absolute;left:0;top:11px;width:18px;height:18px;border:2px solid #FF9900;border-radius:4px;background:rgba(255,153,0,.15)}
.checklist-box .deadline{margin-top:14px;padding:10px 14px;background:rgba(255,153,0,.18);border-left:3px solid #FF9900;border-radius:0 8px 8px 0;font-size:.92rem}
.checklist-box .deadline strong{color:#FFC677}

.highlight-box{background:linear-gradient(135deg,#fff8e6 0%,#fff3cf 100%);border-left:4px solid #d4a017;padding:16px 18px;margin:20px 0;border-radius:0 10px 10px 0}
.highlight-box strong{color:#3a2a00}
.highlight-box p:last-child{margin-bottom:0}

.warning-box{background:#fff5f5;border-left:4px solid #d04545;padding:16px 18px;margin:20px 0;border-radius:0 10px 10px 0}
.warning-box strong{color:#9b1414}

.cta-final{background:linear-gradient(135deg,#0F4C81 0%,#072a4a 100%);color:#fff;padding:26px 22px;border-radius:14px;text-align:center;margin:24px 0;box-shadow:0 6px 24px rgba(15,76,129,.22);position:relative;overflow:hidden}
.cta-final::before{content:'';position:absolute;top:-50%;right:-15%;width:380px;height:380px;background:radial-gradient(circle,rgba(255,153,0,.12),transparent 60%)}
.cta-final h3{color:#fff;margin-bottom:10px;font-size:1.2rem;position:relative}
.cta-final p{color:rgba(255,255,255,.95);margin-bottom:16px;font-size:.95rem;position:relative}
.cta-final a{display:inline-flex;gap:8px;background:#FF9900;color:#1a1a1a;font-weight:700;padding:14px 28px;border-radius:8px;text-decoration:none;font-size:1rem;border:2px solid rgba(0,0,0,.05);position:relative;animation:pulse-cta 2.5s ease-in-out infinite}
.cta-final a:hover{background:#FFAA33}
@keyframes pulse-cta{0%,100%{box-shadow:0 4px 14px rgba(255,153,0,.4)}50%{box-shadow:0 4px 24px rgba(255,153,0,.7)}}
@media(prefers-reduced-motion:reduce){.cta-final a{animation:none}}

.ad-slot{margin:20px 0;text-align:center;min-height:90px;background:#f0f0f0;border-radius:10px;overflow:hidden;border:1px solid #e6e6e6}
.ad-slot::before{content:'Publicidade';display:block;font-size:.65rem;color:#999;text-transform:uppercase;letter-spacing:.5px;padding:6px;background:#fff;border-bottom:1px solid #e6e6e6}
.ad-slot.ad-large{min-height:250px}

.faq-item{background:#fff;border:1px solid #e6e6e6;border-radius:10px;margin-bottom:8px;overflow:hidden}
.faq-item[open]{box-shadow:0 2px 12px rgba(15,76,129,.1)}
.faq-item summary{padding:14px 16px;cursor:pointer;font-weight:600;font-size:.95rem;color:#1a1a1a;list-style:none;display:flex;justify-content:space-between;align-items:center}
.faq-item summary::-webkit-details-marker{display:none}
.faq-item summary::after{content:'+';color:#0F4C81;font-size:1.4rem;font-weight:300;line-height:1}
.faq-item[open] summary::after{content:'−'}
.faq-item .faq-answer{padding:0 16px 14px;color:#444;font-size:.92rem;line-height:1.65}

.prev-link{display:inline-flex;align-items:center;gap:6px;color:#666;font-size:.85rem;margin:14px 0 0;text-decoration:none}
.prev-link:hover{color:#0F4C81}

footer{background:#1a1a1a;color:#aaa;padding:26px 0;text-align:center;font-size:.78rem;line-height:1.65;margin-top:28px}
footer a{color:#bcd0e0;text-decoration:underline}
footer .disclaimer{background:rgba(15,76,129,.15);padding:14px 16px;border-radius:8px;border-left:3px solid #0F4C81;text-align:left;margin:14px auto;max-width:780px;color:#ccc;font-size:.78rem}

@media(min-width:760px){
  h1{font-size:2.4rem}h2{font-size:1.7rem}h3{font-size:1.2rem}
  .container{padding:0 24px}
  .header{padding:14px 0}
  .header .logo{font-size:1.2rem}
  .header .ind-tag{font-size:.78rem}
  .hero{padding:54px 0 60px}
  .hero h1{font-size:2.6rem}
  .hero p{font-size:1.13rem;max-width:580px}
  .hero-grid{display:grid;grid-template-columns:1.35fr 1fr;gap:48px;align-items:center}
  .hero-stats{grid-template-columns:repeat(2,1fr);gap:14px;margin-top:0}
  .stat-card{padding:18px}
  .stat-card .num{font-size:2.1rem}
  .layout{display:grid;grid-template-columns:1fr 320px;gap:36px;align-items:start;padding:24px 0}
  .sidebar-ads{position:sticky;top:80px}
  .sidebar-ads .ad-slot{min-height:600px}
  .criteria-grid{grid-template-columns:repeat(2,1fr);gap:24px}
  .criteria-card{padding:24px 22px}
  article p{font-size:1.05rem}
  .intro-box{padding:24px 28px}
  .checklist-box{padding:32px}
  .checklist-box h3{font-size:1.35rem}
  .cta-final{padding:36px 30px}
  .cta-final h3{font-size:1.5rem}
  .cta-final p{font-size:1.05rem}
}
@media(min-width:1000px){
  .hero h1{font-size:2.95rem;letter-spacing:-.025em}
}
</style>

<script type='application/ld+json'>
{"@context":"https://schema.org","@type":"Article","headline":"<?=$page_title?>","description":"<?=$page_desc?>","datePublished":"2026-04-29","dateModified":"2026-04-29","author":{"@type":"Organization","name":"VaFast"},"publisher":{"@type":"Organization","name":"VaFast"}}
</script>

<script async src='https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1690973013586490' crossorigin='anonymous'></script>
</head>
<body>

<div class='progress-bar' id='progress-bar'></div>

<div class='top-strip'>📚 <strong>Pé-de-Meia 2026</strong> · Critérios oficiais · Data-base CadÚnico: 7 de agosto</div>

<header class='header'>
  <div class='container'>
    <div class='logo'>Va<span>Fast</span> · Educação</div>
    <span class='ind-tag'>QUEM TEM DIREITO</span>
  </div>
</header>

<section class='hero'>
  <div class='container hero-inner'>
    <div class='hero-grid'>
      <div>
        <h1>Quem Tem Direito ao Pé-de-Meia em 2026</h1>
        <p>Os 5 critérios oficiais do MEC para receber até R$ 9.200 ao longo do ensino médio. Veja agora se você se enquadra antes da data-base de 7 de agosto de 2026.</p>
        <div class='badges'>
          <span class='badge'>Idade 14-24 anos</span>
          <span class='badge'>Ensino médio público</span>
          <span class='badge'>CadÚnico atualizado</span>
        </div>
        <a href='#criterios' class='hero-cta' onclick='trackEngagement("hero_ver_criterios",1)'>Ver os 5 critérios →</a>
      </div>
      <div class='hero-stats'>
        <div class='stat-card'>
          <div class='num'>5</div>
          <div class='lbl'>Critérios oficiais</div>
        </div>
        <div class='stat-card'>
          <div class='num'>14-24</div>
          <div class='lbl'>Idade elegível</div>
        </div>
        <div class='stat-card'>
          <div class='num'>1/2 SM</div>
          <div class='lbl'>Renda per capita</div>
        </div>
        <div class='stat-card'>
          <div class='num'>80%</div>
          <div class='lbl'>Frequência mínima</div>
        </div>
      </div>
    </div>
  </div>
</section>

<div class='update-line'>
  <div class='container'>
    <span class='live'></span> <strong>Atualizado em 29/04/2026</strong> · 6 min de leitura · Fonte: MEC e Lei 14.818/2024
  </div>
</div>

<main>
<div class='container'>
  <div class='layout'>
    <div class='content-main'>

      <!-- AD SLOT 1 — Above the fold -->
      <div class='ad-slot ad-large'><ins class='adsbygoogle' style='display:block' data-ad-format='fluid' data-ad-layout-key='-6t+ed+2i-1n-4w' data-ad-client='ca-pub-1690973013586490' data-ad-slot='7773118277'></ins><script>(adsbygoogle=window.adsbygoogle||[]).push({})</script></div>

      <article>

        <a href='https://xegold.xyz/calendario-pe-de-meia-2026/' class='prev-link'>← Voltar para "Calendário 2026"</a>

        <div class='intro-box'>
          <p>O Pé-de-Meia <strong>não é universal</strong>. É um benefício direcionado a estudantes de baixa renda no ensino médio público — definido por 5 critérios objetivos. Quem cumpre todos é <strong>incluído automaticamente</strong> pelo MEC; não precisa fazer inscrição.</p>
          <p>Mas tem um detalhe crítico: a <strong>data-base é 7 de agosto de 2026</strong>. Se na data o seu CadÚnico estiver desatualizado ou fora dos critérios, fica de fora do programa em 2026 — só volta a contar a partir de 2027.</p>
        </div>

        <h2 id='criterios'>Os 5 critérios oficiais do Pé-de-Meia 2026</h2>

        <p>Você precisa atender <strong>todos os 5</strong> simultaneamente. Falhar em 1 desclassifica.</p>

        <div class='criteria-grid'>

          <div class='criteria-card'>
            <span class='badge-num'>CRITÉRIO 1</span>
            <span class='num-tag'>1</span>
            <h3><span class='ico'>🎂</span> Idade entre 14 e 24 anos</h3>
            <p class='lead'>Faixa etária válida, considerando os anos comuns do ensino médio e EJA.</p>
            <ul class='checks'>
              <li>14 a 19 anos no ensino médio regular</li>
              <li>14 a 24 anos na EJA (Educação de Jovens e Adultos)</li>
              <li>Idade conferida na data-base de 7/ago/2026</li>
            </ul>
          </div>

          <div class='criteria-card'>
            <span class='badge-num'>CRITÉRIO 2</span>
            <span class='num-tag'>2</span>
            <h3><span class='ico'>🏫</span> Matrícula ativa no ensino médio público</h3>
            <p class='lead'>Aluno regularmente matriculado em rede pública.</p>
            <ul class='checks'>
              <li>Rede estadual, federal, distrital ou municipal</li>
              <li>1º, 2º ou 3º ano do ensino médio</li>
              <li>EJA do ensino médio também conta</li>
              <li class='bad'>Escolas particulares não têm direito</li>
            </ul>
          </div>

          <!-- AD SLOT 2 -->
          <div class='ad-slot' style='grid-column:1/-1'><ins class='adsbygoogle' style='display:block' data-ad-format='fluid' data-ad-layout-key='-6t+ed+2i-1n-4w' data-ad-client='ca-pub-1690973013586490' data-ad-slot='7773118277'></ins><script>(adsbygoogle=window.adsbygoogle||[]).push({})</script></div>

          <div class='criteria-card'>
            <span class='badge-num'>CRITÉRIO 3</span>
            <span class='num-tag'>3</span>
            <h3><span class='ico'>💰</span> Renda familiar até 1/2 salário mínimo per capita</h3>
            <p class='lead'>Cálculo: renda total da família dividida pelo número de moradores.</p>
            <ul class='checks'>
              <li>Em 2026: até <strong>R$ 759 por pessoa</strong> (1/2 de R$ 1.518)</li>
              <li>Considera todos da família que moram na casa</li>
              <li>Inclui salários, BPC, aposentadoria, pensão, BF</li>
              <li>Verificada via CadÚnico</li>
            </ul>
          </div>

          <div class='criteria-card'>
            <span class='badge-num'>CRITÉRIO 4</span>
            <span class='num-tag'>4</span>
            <h3><span class='ico'>📋</span> CadÚnico atualizado até 7/ago/2026</h3>
            <p class='lead'>Cadastro Único deve estar incluído e com dados recentes.</p>
            <ul class='checks'>
              <li>Família precisa estar no CadÚnico</li>
              <li>Atualização nos últimos 24 meses</li>
              <li>Renda registrada compatível com critério 3</li>
              <li>Atualização feita gratuitamente no <strong>CRAS</strong></li>
            </ul>
          </div>

          <div class='criteria-card' style='grid-column:1/-1'>
            <span class='badge-num'>CRITÉRIO 5</span>
            <span class='num-tag'>5</span>
            <h3><span class='ico'>📊</span> Frequência escolar mínima de 80%</h3>
            <p class='lead'>Aluno precisa estar presente em pelo menos 80% das aulas no mês para receber a parcela referente.</p>
            <ul class='checks'>
              <li>Cálculo mês a mês — só perde a parcela do mês com baixa frequência</li>
              <li>Recesso escolar oficial não conta como falta</li>
              <li>Faltas justificadas (médica, óbito) também não pesam</li>
              <li>Quando volta a 80%+ recebe parcela retroativa automaticamente</li>
            </ul>
          </div>

        </div>

        <h2>Checklist rápido: você se enquadra?</h2>

        <div class='checklist-box'>
          <h3>📝 Antes de 7 de agosto de 2026, confirme se</h3>
          <ul>
            <li>Idade entre 14 e 24 anos na data-base</li>
            <li>Matriculado em escola pública (estadual, federal ou municipal) no ensino médio</li>
            <li>Família no CadÚnico com dados atualizados nos últimos 24 meses</li>
            <li>Renda familiar registrada de até R$ 759 por pessoa</li>
            <li>Frequência mensal acima de 80% (a partir do início do ano letivo)</li>
          </ul>
          <div class='deadline'>
            <strong>⏰ Prazo crítico:</strong> 7 de agosto de 2026 é a data-base. Tudo que estiver desatualizado depois disso só vale para o programa em 2027.
          </div>
        </div>

        <!-- AD SLOT 3 — Meio do conteúdo -->
        <div class='ad-slot ad-large'><ins class='adsbygoogle' style='display:block' data-ad-format='fluid' data-ad-layout-key='-6t+ed+2i-1n-4w' data-ad-client='ca-pub-1690973013586490' data-ad-slot='7773118277'></ins><script>(adsbygoogle=window.adsbygoogle||[]).push({})</script></div>

        <h2>Como atualizar o CadÚnico (passo crítico)</h2>

        <p>O critério que mais elimina alunos elegíveis é o <strong>CadÚnico desatualizado</strong>. Se a renda registrada lá está acima do limite (porque alguém da família tinha emprego antes e perdeu, por exemplo), o sistema do MEC bloqueia. Solução:</p>

        <ol>
          <li><strong>Localize o CRAS mais próximo</strong> — pesquisa no Google "CRAS [seu bairro]" ou no site da prefeitura. Atendimento é gratuito.</li>
          <li><strong>Agende uma atualização</strong> — leve documentos da família inteira (RG, CPF, comprovante de residência, comprovantes de renda atual de todos)</li>
          <li><strong>Atualize TODA a composição familiar</strong> — quem entrou ou saiu de casa, quem trabalha agora, quem perdeu emprego</li>
          <li><strong>Aguarde 30 dias</strong> — o cruzamento com o MEC não é imediato; espera ~30 dias após atualização</li>
          <li><strong>Volte a consultar no Jornada do Estudante</strong> — se foi feito antes de 7/ago, você é incluído ainda em 2026</li>
        </ol>

        <div class='warning-box'>
          <strong>⚠️ Sobre a data-base:</strong> o sistema do MEC consulta o CadÚnico no <strong>dia 7 de agosto de 2026</strong>. Se você atualizar dia 8 ou depois, fica de fora dos pagamentos do segundo semestre desse ano. Cadastros novos contam só pra 2027.
        </div>

        <h2>Casos específicos: dúvidas frequentes</h2>

        <div class='criteria-card'>
          <h3>🤰 Aluno é mãe/pai e mora com os filhos?</h3>
          <p>A renda da própria família do aluno é considerada — não a da família dos pais dele. Se o aluno tem CPF próprio no CadÚnico junto com filhos/companheiro(a), é avaliado por essa unidade familiar.</p>
        </div>

        <div class='criteria-card'>
          <h3>🏠 Aluno mora sozinho ou com outros estudantes?</h3>
          <p>Se está no CadÚnico como pessoa só (família unipessoal), a renda dele individual é o que conta. Precisa estar abaixo de meio salário mínimo.</p>
        </div>

        <div class='criteria-card'>
          <h3>💼 Aluno trabalha em estágio ou jovem aprendiz?</h3>
          <p>Pode receber. A renda do estagiário/aprendiz entra no cálculo da família, mas não desclassifica automaticamente — depende do total dividido pelos moradores.</p>
        </div>

        <div class='criteria-card'>
          <h3>🎓 Aluno repetiu ou trancou o ano?</h3>
          <p>Quem repete não perde a elegibilidade — desde que continue dentro da faixa etária e mantenha frequência. Trancamento por longo período pode tirar do programa naquele ano específico, mas reabrindo a matrícula volta a contar.</p>
        </div>

        <div class='highlight-box'>
          <strong>💡 Dica de quem já passou:</strong> a maioria dos casos de "não recebimento" entre alunos que claramente cumprem os critérios é problema com CadÚnico. Faz sentido ir ao CRAS antes do meio do ano pra evitar correria perto do prazo. <strong>Maio e junho são meses ideais</strong> pra atualizar — fila menor.
        </div>

      </article>

      <!-- AD SLOT 4 — Antes do CTA -->
      <div class='ad-slot ad-large'><ins class='adsbygoogle' style='display:block' data-ad-format='fluid' data-ad-layout-key='-6t+ed+2i-1n-4w' data-ad-client='ca-pub-1690973013586490' data-ad-slot='7773118277'></ins><script>(adsbygoogle=window.adsbygoogle||[]).push({})</script></div>

      <div class='cta-final'>
        <h3>"Eu cumpro os critérios mas não recebi. E agora?"</h3>
        <p>Se você se enquadra mas a parcela não caiu, há 6 motivos comuns que travam o pagamento — e todos têm solução. Veja agora o guia de problemas resolvidos.</p>
        <a href='<?=$external_cta?>' onclick='trackEngagement("cta_p4_problemas",1);return gtag_report_conversion(this.href)'>
          Ver Como Resolver →
        </a>
      </div>

      <h2>Perguntas frequentes</h2>

      <details class='faq-item'>
        <summary>Aluno de escola conveniada (filantrópica) tem direito?</summary>
        <div class='faq-answer'>Não. O Pé-de-Meia é exclusivo de redes públicas (estadual, federal, distrital, municipal). Escolas filantrópicas, comunitárias ou particulares — mesmo que aceitem bolsa — ficam de fora. A única exceção são as escolas conveniadas que recebem recursos federais e operam como rede pública para fins do programa.</div>
      </details>

      <details class='faq-item'>
        <summary>Alunos quilombolas e indígenas têm regras diferentes?</summary>
        <div class='faq-answer'>Têm prioridade no programa. Para alunos quilombolas e indígenas, o critério de renda é flexibilizado — o cadastro automaticamente inclui essas populações independentemente do limite de meio salário mínimo, desde que estejam no CadÚnico identificados nessas categorias.</div>
      </details>

      <details class='faq-item'>
        <summary>Beneficiário de Bolsa Família automaticamente recebe Pé-de-Meia?</summary>
        <div class='faq-answer'>Não automaticamente, mas é praticamente certo. Família no Bolsa Família tem renda dentro do limite e CadÚnico ativo. Falta apenas o aluno estar matriculado no ensino médio público e com idade compatível. São programas independentes, mas que se sobrepõem na maioria dos casos.</div>
      </details>

      <details class='faq-item'>
        <summary>Aluno de escola técnica federal tem direito?</summary>
        <div class='faq-answer'>Sim. Estudantes do ensino médio integrado de IFs (Institutos Federais) e demais escolas técnicas federais têm direito normal ao Pé-de-Meia, desde que cumpram os outros 4 critérios.</div>
      </details>

      <details class='faq-item'>
        <summary>Tem como saber se a renda do CadÚnico está certa?</summary>
        <div class='faq-answer'>Sim. Pelo aplicativo CadÚnico ou pelo gov.br você consegue ver os dados registrados da sua família. Se algum valor estiver desatualizado (alguém perdeu emprego, alguém saiu de casa), agenda no CRAS para atualizar. É gratuito e tem validade de 24 meses.</div>
      </details>

      <details class='faq-item'>
        <summary>Quem entra no ensino médio em agosto/setembro recebe?</summary>
        <div class='faq-answer'>Sim, mas com pagamentos parciais a partir do mês de matrícula. Não recebe parcelas retroativas dos meses anteriores em que não estava matriculado. A parcela de matrícula é paga uma vez no início do ano letivo dele.</div>
      </details>

    </div>

    <aside class='sidebar-ads'>
      <!-- AD SLOT 5 — Sidebar (AdSense) -->
      <div class='ad-slot ad-large'><ins class='adsbygoogle' style='display:block' data-ad-format='fluid' data-ad-layout-key='-6t+ed+2i-1n-4w' data-ad-client='ca-pub-1690973013586490' data-ad-slot='7773118277'></ins><script>(adsbygoogle=window.adsbygoogle||[]).push({})</script></div>
    </aside>

  </div>
</div>
</main>

<footer>
  <div class='container'>
    <div>VaFast — Guia de programas educacionais e oportunidades</div>
    <div class='disclaimer'>
      <strong>Aviso:</strong> Este site não tem vínculo com Ministério da Educação, Caixa Econômica Federal ou Governo Federal. Critérios extraídos da Lei 14.818/2024 e Portaria nº 169/2026 do MEC. Para inscrição e consulta oficial, acesse <a href='https://www.gov.br/mec/pt-br/pe-de-meia' rel='nofollow'>gov.br/mec/pt-br/pe-de-meia</a> ou ligue para 0800 616 161.
    </div>
    <div style='margin-top:8px'>
      <a href='/politica-de-privacidade-2/'>Política de Privacidade</a> ·
      <a href='/termos-de-uso/'>Termos</a> ·
      <a href='/fale-conosco/'>Contato</a>
    </div>
  </div>
</footer>

<script>
(function(){
  var bar=document.getElementById('progress-bar');
  var ticking=false;
  function update(){
    var h=document.documentElement;var b=document.body;
    var pct=(h.scrollTop||b.scrollTop)/((h.scrollHeight||b.scrollHeight)-h.clientHeight)*100;
    bar.style.width=Math.min(pct,100)+'%';ticking=false;
  }
  window.addEventListener('scroll',function(){if(!ticking){requestAnimationFrame(update);ticking=true}},{passive:true});
  var ms={25:false,50:false,75:false,100:false};
  window.addEventListener('scroll',function(){
    var p=(document.documentElement.scrollTop||document.body.scrollTop)/((document.documentElement.scrollHeight||document.body.scrollHeight)-document.documentElement.clientHeight)*100;
    Object.keys(ms).forEach(function(m){if(!ms[m]&&p>=m){ms[m]=true;trackEngagement('pdm_p3_scroll_'+m,m)}});
  },{passive:true});
  setTimeout(function(){trackEngagement('pdm_p3_time_30s',30)},30000);
  setTimeout(function(){trackEngagement('pdm_p3_time_60s',60)},60000);
})();
</script>

</body>
</html>
