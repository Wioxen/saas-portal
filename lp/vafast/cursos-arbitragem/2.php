<?php
$page_num = 2;
$total_pages = 2;
$external_cta = 'https://xegold.xyz/top-plataformas-ead/';
$prev_url = './' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
$page_title = 'Lista de Cursos Gratuitos com Certificado: Senac, Senai e Sebrae 2026';
$page_desc = 'Veja os cursos gratuitos do Senac, Senai e Sebrae com vagas abertas agora, separados por área. Acesse o guia completo de inscrição.';
?><!DOCTYPE html>
<html lang='pt-BR'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width, initial-scale=1.0,viewport-fit=cover'>
<meta name='theme-color' content='#0F4C81'>
<meta name='robots' content='index,follow,max-image-preview:large'>
<title><?=$page_title?></title>
<meta name='description' content='<?=$page_desc?>'>
<link rel='canonical' href='https://vafast.xyz/cursos-arbitragem/2.php'>
<link rel='prev' href='./'>
<link rel='preconnect' href='https://script.joinads.me'>
<link rel='preconnect' href='https://xegold.xyz'>
<link rel='preconnect' href='https://www.googletagmanager.com'>

<script async src='https://www.googletagmanager.com/gtag/js?id=AW-16675521270'></script>
<script>
window.dataLayer=window.dataLayer||[];
function gtag(){dataLayer.push(arguments)}
gtag('js',new Date());
gtag('config','AW-16675521270');
gtag('config','G-Q25F19JPDZ');
function trackEngagement(action,value){gtag('event',action,{'event_category':'arbitrage','value':value||1})}
gtag('event','page_view_arbitrage',{'page_num':<?=$page_num?>});
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
h1,h2,h3{margin:0 0 .55em;line-height:1.3;font-weight:700;letter-spacing:-.01em;color:#0F4C81}
h1{font-size:1.45rem}h2{font-size:1.2rem}h3{font-size:1.05rem}
p{margin:0 0 1em;color:#333}
.container{max-width:720px;margin:0 auto;padding:0 14px}
.progress-bar{position:fixed;top:0;left:0;height:3px;background:linear-gradient(90deg,#FF9900,#FFB84D);z-index:200;transition:width .1s ease;width:0}
.top-strip{background:#0F4C81;color:#fff;text-align:center;padding:7px 12px;font-size:.78rem;font-weight:600}
.header{background:#fff;border-bottom:1px solid #e6ecf5;padding:10px 0;text-align:center}
.header .logo{font-weight:800;font-size:1rem;color:#0F4C81}
.header .logo span{color:#FF9900}
.hero{background:#fff;padding:18px 0 22px;border-bottom:1px solid #e6ecf5}
.hero h1{font-size:1.35rem;margin-bottom:.4em}
.hero .subtitle{color:#444;font-size:.95rem}
.page-pos{display:inline-block;background:#fff3d6;color:#7c4a00;font-size:.72rem;padding:3px 9px;border-radius:10px;font-weight:700;margin-bottom:12px}
article{background:#fff;padding:18px 0;margin-bottom:14px}
article p{font-size:1rem;line-height:1.7}
.area-card{background:#fff;border:1px solid #e6ecf5;border-radius:10px;padding:16px;margin:14px 0;border-left:4px solid #0F4C81}
.area-card h3{font-size:1.05rem;margin:0 0 10px;display:flex;align-items:center;gap:10px;color:#0F4C81}
.area-card.senai{border-left-color:#ed1c24}
.area-card.senai h3{color:#ed1c24}
.area-card.sebrae{border-left-color:#005EB8}
.area-card.sebrae h3{color:#005EB8}
.section-divider{display:flex;align-items:center;gap:10px;margin:24px 0 14px;padding:14px;background:#f0f3f8;border-radius:10px}
.section-divider .icon{font-size:1.6rem;flex-shrink:0}
.section-divider h2{margin:0;font-size:1.15rem;color:#0F4C81}
.section-divider .desc{font-size:.85rem;color:#555;margin-top:2px}
.area-card .emoji{font-size:1.3rem}
.area-card ul{margin:0;padding-left:18px}
.area-card li{margin-bottom:5px;font-size:.94rem;color:#333}
.area-card .vagas{display:inline-block;background:#E8F5E9;color:#0d4516;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:10px;margin-left:auto}
.next-page-cta{background:linear-gradient(135deg,#0F4C81 0%,#1565a8 100%);color:#fff;padding:22px 18px;border-radius:12px;text-align:center;margin:20px 0}
.next-page-cta h3{color:#fff;margin-bottom:8px}
.next-page-cta p{color:#cfe1f2;margin-bottom:14px;font-size:.92rem}
.next-page-cta a{display:inline-flex;gap:8px;background:#FF9900;color:#1a1a1a;font-weight:700;padding:13px 24px;border-radius:8px;text-decoration:none;border:1px solid #cc7700;animation:pulse-cta 2.5s ease-in-out infinite}
@keyframes pulse-cta{0%,100%{box-shadow:0 2px 8px rgba(255,153,0,.3)}50%{box-shadow:0 2px 18px rgba(255,153,0,.6)}}
@media(prefers-reduced-motion:reduce){.next-page-cta a{animation:none}}
.ad-slot{margin:18px 0;text-align:center;min-height:90px;background:#f0f3f8;border-radius:8px;overflow:hidden}
.ad-slot::before{content:'Publicidade';display:block;font-size:.65rem;color:#999;text-transform:uppercase;letter-spacing:.5px;padding:6px;background:#fff;border-bottom:1px solid #e6ecf5}
.ad-slot.ad-large{min-height:250px}
.in-page-nav{display:flex;justify-content:space-between;align-items:center;background:#f5f7fa;padding:10px 14px;border-radius:8px;margin:12px 0;font-size:.85rem;color:#555}
.in-page-nav .step-dots{display:flex;gap:5px}
.in-page-nav .step-dots span{width:8px;height:8px;border-radius:50%;background:#d6dfeb}
.in-page-nav .step-dots span.active{background:#0F4C81}
.prev-link{display:inline-block;color:#666;font-size:.85rem;margin-bottom:14px;text-decoration:none}
.prev-link:hover{color:#0F4C81}
footer.site-footer{background:#1a1a1a;color:#aaa;padding:20px 0;text-align:center;font-size:.78rem;line-height:1.6;margin-top:24px}
footer.site-footer a{color:#bcd0e0}
@media(min-width:760px){
  .layout-with-sidebar{display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start}
  .sidebar-ads{position:sticky;top:14px}
  .sidebar-ads .ad-slot{min-height:300px}
}
</style>

<script type='module' src='https://script.joinads.me/myad20438.js' crossorigin='anonymous' async></script>
</head>
<body>

<div class='progress-bar' id='progress-bar'></div>

<div class='top-strip'>🎓 <strong>Página 2 de <?=$total_pages?></strong> · Lista de cursos com vagas abertas</div>

<header class='header'>
  <div class='container'>
    <div class='logo'>Va<span>Fast</span> · Cursos Grátis</div>
  </div>
</header>

<main>
<div class='container'>
  <div class='layout-with-sidebar'>
    <div class='content-main'>

      <a href='<?=$prev_url?>' class='prev-link'>← Voltar para introdução</a>

      <section class='hero'>
        <span class='page-pos'>📋 Página 2 de <?=$total_pages?> · Senac, Senai e Sebrae</span>
        <h1>Cursos Gratuitos com Certificado: Lista Atualizada com Vagas Abertas</h1>
        <p class='subtitle'>Senac, Senai e Sebrae em uma página só. Inscrições abertas para o primeiro semestre — escolha sua área e clique pra ver o guia completo.</p>
      </section>

      <!-- AD SLOT 1 — Above the fold -->
      <div class='ad-slot ad-large'><div joinadscode='Content1'></div></div>

      <article>

        <p>Aqui está a lista atualizada dos <strong>cursos gratuitos com certificado MEC</strong> com vagas abertas em 2026 — Senac, Senai e Sebrae em uma página só. Escolha a sua área e ao final acesse o guia completo de inscrição.</p>

        <div class='section-divider'>
          <span class='icon'>🎓</span>
          <div>
            <h2>Senac — Programa de Gratuidade (PSG)</h2>
            <div class='desc'>+95.000 alunos formados · Certificado MEC · EAD e presencial</div>
          </div>
        </div>

        <div class='area-card'>
          <h3><span class='emoji'>💻</span> Tecnologia e Informática <span class='vagas'>Vagas abertas</span></h3>
          <ul>
            <li><strong>Informática Básica com Internet e Mídias Sociais</strong> — 80h, EAD</li>
            <li><strong>Pacote Office Completo</strong> — 120h, presencial e EAD</li>
            <li><strong>Introdução à Programação</strong> — 60h, EAD</li>
            <li><strong>Business Intelligence com Power BI</strong> — 40h, EAD</li>
            <li><strong>Design Gráfico</strong> — 80h, presencial</li>
            <li><strong>Marketing Digital</strong> — 60h, EAD</li>
          </ul>
        </div>

        <div class='area-card'>
          <h3><span class='emoji'>📊</span> Administração e Negócios <span class='vagas'>Vagas abertas</span></h3>
          <ul>
            <li><strong>Assistente Administrativo</strong> — 160h, presencial</li>
            <li><strong>Gestão de Pequenos Negócios</strong> — 80h, EAD</li>
            <li><strong>Vendas</strong> — 60h, EAD</li>
            <li><strong>Líder Coach</strong> — 40h, EAD</li>
            <li><strong>Marketing Social</strong> — 40h, EAD</li>
          </ul>
        </div>

        <!-- AD SLOT 2 — In-article após primeiras áreas -->
        <div class='ad-slot'><div joinadscode='Content2'></div></div>

        <div class='area-card'>
          <h3><span class='emoji'>💊</span> Saúde e Bem-estar <span class='vagas'>Alta procura</span></h3>
          <ul>
            <li><strong>Cuidador de Idosos</strong> — 160h, presencial</li>
            <li><strong>Primeiros Socorros</strong> — 40h, EAD</li>
            <li><strong>Massoterapia</strong> — 200h, presencial</li>
            <li><strong>Atendente de Farmácia</strong> — 200h, presencial</li>
          </ul>
        </div>

        <div class='area-card'>
          <h3><span class='emoji'>💅</span> Beleza e Estética <span class='vagas'>Vagas limitadas</span></h3>
          <ul>
            <li><strong>Manicure e Pedicure</strong> — 160h, presencial</li>
            <li><strong>Maquiagem Profissional</strong> — 80h, presencial</li>
            <li><strong>Maquiagem Artística</strong> — 60h, presencial</li>
            <li><strong>Maquiagem para Pele Negra</strong> — 40h, presencial</li>
            <li><strong>Penteados</strong> — 40h, presencial</li>
            <li><strong>Técnicas de Tranças</strong> — 60h, presencial</li>
            <li><strong>Unhas Decoradas</strong> — 40h, presencial</li>
            <li><strong>Gestão de Salões de Beleza</strong> — 60h, EAD</li>
          </ul>
        </div>

        <!-- AD SLOT 3 — In-article meio do conteúdo -->
        <div class='ad-slot ad-large'><div joinadscode='Content3'></div></div>

        <div class='area-card'>
          <h3><span class='emoji'>🍳</span> Gastronomia e Hotelaria <span class='vagas'>Vagas abertas</span></h3>
          <ul>
            <li><strong>Cozinha Brasileira</strong> — 80h, presencial</li>
            <li><strong>Confeitaria Básica</strong> — 80h, presencial</li>
            <li><strong>Garçom e Atendimento</strong> — 60h, presencial</li>
            <li><strong>Preparo de Drinques e Coquetéis</strong> — 40h, presencial</li>
            <li><strong>Aproveitamento Integral de Alimentos</strong> — 30h, EAD</li>
          </ul>
        </div>

        <div class='section-divider'>
          <span class='icon'>🏭</span>
          <div>
            <h2 style='color:#ed1c24'>SENAI — Formação Industrial</h2>
            <div class='desc'>+3M profissionais formados · 600+ unidades · Certificado MEC</div>
          </div>
        </div>

        <div class='area-card senai'>
          <h3><span class='emoji'>⚙️</span> Mecânica, Metalurgia e Eletrotécnica <span class='vagas'>Alta procura</span></h3>
          <ul>
            <li><strong>Técnico em Mecânica Industrial</strong> — 1.200h, presencial</li>
            <li><strong>Técnico em Eletrotécnica</strong> — 1.200h, presencial</li>
            <li><strong>Técnico em Automação Industrial</strong> — 1.000h, presencial</li>
            <li><strong>Soldador Industrial</strong> — 400h, presencial</li>
            <li><strong>Operador de Máquinas CNC</strong> — 240h, presencial</li>
            <li><strong>CLP - Controlador Lógico Programável</strong> — 60h, EAD</li>
          </ul>
        </div>

        <div class='area-card senai'>
          <h3><span class='emoji'>💻</span> Tecnologia e Logística <span class='vagas'>Vagas abertas</span></h3>
          <ul>
            <li><strong>Técnico em Desenvolvimento de Sistemas</strong> — 1.000h, EAD</li>
            <li><strong>Programação em Python</strong> — 80h, EAD</li>
            <li><strong>Inteligência Artificial Aplicada</strong> — 40h, EAD <span style='background:#fff3d6;padding:2px 6px;border-radius:6px;font-size:.7rem;color:#7c4a00'>NOVO 2026</span></li>
            <li><strong>Técnico em Logística</strong> — 1.000h, presencial</li>
            <li><strong>Técnico em Segurança do Trabalho</strong> — 1.200h, presencial</li>
            <li><strong>NR-10 (Segurança em Eletricidade)</strong> — 40h, EAD</li>
          </ul>
        </div>

        <!-- AD SLOT 4 — Antes do Sebrae -->
        <div class='ad-slot ad-large'><div joinadscode='Content4'></div></div>

        <div class='section-divider'>
          <span class='icon'>💼</span>
          <div>
            <h2 style='color:#005EB8'>Sebrae — Empreendedorismo e Pequenos Negócios</h2>
            <div class='desc'>Cursos online · Sem comprovação de renda · Certificado válido</div>
          </div>
        </div>

        <div class='area-card sebrae'>
          <h3><span class='emoji'>🚀</span> Começando um Negócio <span class='vagas'>Mais procurados</span></h3>
          <ul>
            <li><strong>Como Abrir um Negócio</strong> — 8h, EAD</li>
            <li><strong>Plano de Negócios na Prática</strong> — 12h, EAD</li>
            <li><strong>MEI: Tudo o que Você Precisa Saber</strong> — 6h, EAD</li>
            <li><strong>Empreendedorismo Digital</strong> — 10h, EAD</li>
          </ul>
        </div>

        <div class='area-card sebrae'>
          <h3><span class='emoji'>📈</span> Gestão, Marketing e Vendas <span class='vagas'>Vagas abertas</span></h3>
          <ul>
            <li><strong>Gestão Financeira para Pequenos Negócios</strong> — 10h, EAD</li>
            <li><strong>Marketing Digital para PMEs</strong> — 12h, EAD</li>
            <li><strong>Como Vender pelo Instagram</strong> — 8h, EAD</li>
            <li><strong>WhatsApp Business para Vendas</strong> — 6h, EAD</li>
            <li><strong>Atendimento e Vendas</strong> — 8h, EAD</li>
          </ul>
        </div>

        <div class='in-page-nav'>
          <span>Etapa 2 de <?=$total_pages?> · Última</span>
          <div class='step-dots'>
            <span class='active'></span>
            <span class='active'></span>
          </div>
        </div>

      </article>

      <!-- (Slot Content4 já posicionado entre SENAI e Sebrae acima) -->

      <div class='next-page-cta'>
        <h3>Pronto para se inscrever?</h3>
        <p>Veja o ranking das 10 melhores plataformas com certificado, comparativo entre Senac, Senai, FGV, Bradesco e mais — separado por área e tempo de conclusão</p>
        <a href='<?=$external_cta?>' onclick='trackEngagement("cta_external_xegold_top10",1);return gtag_report_conversion(this.href)' rel='noopener'>
          Ver Top 10 Plataformas →
        </a>
      </div>

    </div>

    <aside class='sidebar-ads'>
      <!-- AD SLOT 5 — Sidebar -->
      <div class='ad-slot ad-large'><div joinadscode='Sidebar'></div></div>
    </aside>

  </div>
</div>
</main>

<footer class='site-footer'>
  <div class='container'>
    <div>VaFast — Guia de cursos gratuitos com certificado MEC</div>
    <div style='margin-top:6px'>
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
  var milestones={25:false,50:false,75:false,100:false};
  window.addEventListener('scroll',function(){
    var pct=(document.documentElement.scrollTop||document.body.scrollTop)/((document.documentElement.scrollHeight||document.body.scrollHeight)-document.documentElement.clientHeight)*100;
    Object.keys(milestones).forEach(function(m){if(!milestones[m]&&pct>=m){milestones[m]=true;trackEngagement('p2_scroll_'+m,m)}});
  },{passive:true});
  setTimeout(function(){trackEngagement('p2_time_30s',30)},30000);
  setTimeout(function(){trackEngagement('p2_time_60s',60)},60000);
})();
</script>

</body>
</html>
