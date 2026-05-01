<?php
$page_title = 'Cursos SENAC Gratuitos 2026: Lista Completa por Área e Como se Inscrever';
$page_desc = 'Lista atualizada dos cursos gratuitos do SENAC em 2026 pelo Programa de Gratuidade (PSG): áreas, carga horária, requisitos por estado e passo a passo da inscrição.';
$senac_oficial = 'https://www.ead.senac.br/';
?><!DOCTYPE html>
<html lang='pt-BR'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width, initial-scale=1.0,viewport-fit=cover'>
<meta name='theme-color' content='#3d9b34'>
<meta name='robots' content='index,follow,max-image-preview:large'>

<title><?=$page_title?></title>
<meta name='description' content='<?=$page_desc?>'>

<link rel='canonical' href='https://xegold.xyz/cursos-senac-gratuitos-2026/'>
<link rel='preconnect' href='https://script.joinads.me'>
<link rel='preconnect' href='https://pageview.joinads.me'>
<link rel='preconnect' href='https://office.joinads.me'>
<link rel='preconnect' href='https://www.googletagmanager.com'>

<!-- Google Ads + GA4 do xegold -->
<script async src='https://www.googletagmanager.com/gtag/js?id=AW-16675521270'></script>
<script>
window.dataLayer=window.dataLayer||[];
function gtag(){dataLayer.push(arguments)}
gtag('js',new Date());
gtag('config','AW-16675521270');
gtag('config','G-1XKCG60DMD'); // GA4 do xegold
function trackEngagement(action,value){gtag('event',action,{'event_category':'arbitrage_xegold_final','value':value||1})}
gtag('event','page_view_xegold_final',{'page_step':'p4_funnel_end'});
</script>

<style>
*,*::before,*::after{box-sizing:border-box}
html{-webkit-text-size-adjust:100%;scroll-behavior:smooth}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:16px;line-height:1.65;color:#1a1a1a;background:#f5f7f4;-webkit-font-smoothing:antialiased}
img{max-width:100%;height:auto;display:block}
a{color:#3d9b34}
h1,h2,h3{margin:0 0 .55em;line-height:1.3;font-weight:700;letter-spacing:-.01em;color:#1a1a1a}
h1{font-size:1.5rem}h2{font-size:1.25rem;color:#3d9b34}h3{font-size:1.05rem}
p{margin:0 0 1em;color:#333}
.container{max-width:760px;margin:0 auto;padding:0 14px}

.progress-bar{position:fixed;top:0;left:0;height:3px;background:linear-gradient(90deg,#3d9b34,#5cc44d);z-index:200;transition:width .1s ease;width:0}

.top-strip{background:#3d9b34;color:#fff;text-align:center;padding:7px 12px;font-size:.78rem;font-weight:600}
.top-strip strong{color:#FFE082}

.header{background:#fff;border-bottom:3px solid #3d9b34;padding:10px 0}
.header .container{display:flex;align-items:center;justify-content:space-between}
.header .logo{font-weight:800;font-size:1rem;color:#3d9b34}
.header .ind-tag{background:#3d9b34;color:#fff;font-size:.7rem;padding:3px 9px;border-radius:10px;font-weight:600}

.hero{background:linear-gradient(135deg,#3d9b34 0%,#2d7027 100%);color:#fff;padding:24px 0;text-align:center}
.hero h1{color:#fff;font-size:1.45rem;margin-bottom:.5em}
.hero p{color:rgba(255,255,255,.95);font-size:.95rem;margin-bottom:14px}
.hero .badges{display:flex;gap:6px;flex-wrap:wrap;justify-content:center}
.hero .badge{background:rgba(0,0,0,.25);color:#fff;font-size:.7rem;padding:3px 9px;border-radius:12px;font-weight:600}

.update-line{background:#fff;padding:10px 0;border-bottom:1px solid #e6e6e6;font-size:.78rem;color:#555;text-align:center}
.update-line span::before{content:'';display:inline-block;width:6px;height:6px;background:#0d8a3b;border-radius:50%;margin-right:5px;vertical-align:middle}

article{background:#fff;padding:18px 0;margin-bottom:14px}
article p{font-size:1rem;line-height:1.7}
article ul,article ol{padding-left:22px;margin:0 0 1em}
article ul li,article ol li{margin-bottom:.4em}

.area-card{background:#fff;border:1px solid #e6e6e6;border-radius:10px;padding:16px;margin:14px 0;border-left:4px solid #3d9b34;box-shadow:0 2px 6px rgba(61,155,52,.08)}
.area-card h3{color:#3d9b34;font-size:1.05rem;margin-bottom:10px;display:flex;align-items:center;gap:10px}
.area-card .emoji{font-size:1.3rem}
.area-card ul{margin:0;padding-left:18px}
.area-card li{margin-bottom:5px;font-size:.93rem;color:#333}
.area-card .vagas{display:inline-block;background:#E8F5E9;color:#0d4516;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:10px;margin-left:auto}

.highlight-box{background:#fff8e6;border-left:4px solid #d4a017;padding:14px 16px;margin:18px 0;border-radius:0 8px 8px 0}
.highlight-box strong{color:#3a2a00}

.steps{counter-reset:step;list-style:none;padding:0;margin:18px 0}
.steps li{position:relative;padding:14px 14px 14px 56px;margin-bottom:10px;background:#f0f8f4;border-radius:8px;border-left:3px solid #3d9b34;font-size:.95rem}
.steps li::before{counter-increment:step;content:counter(step);position:absolute;left:14px;top:14px;width:30px;height:30px;background:#3d9b34;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.95rem}
.steps li strong{display:block;color:#1a1a1a;margin-bottom:3px}

.estados-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin:14px 0}
.estado-tag{background:#fff;border:1px solid #d6e8d3;border-radius:6px;padding:8px 12px;font-size:.85rem;color:#1a1a1a;display:flex;justify-content:space-between;align-items:center}
.estado-tag strong{color:#3d9b34}
.estado-tag::after{content:'→';color:#3d9b34;font-weight:700}

.docs-list{background:#fff;border:1px solid #e6e6e6;border-radius:10px;padding:14px 18px;margin:14px 0}
.docs-list ul{margin:0;padding-left:20px}
.docs-list li{padding:5px 0;color:#333}
.docs-list li::marker{color:#3d9b34}

.ad-slot{margin:18px 0;text-align:center;min-height:90px;background:#f0f0f0;border-radius:8px;overflow:hidden}
.ad-slot::before{content:'Publicidade';display:block;font-size:.65rem;color:#999;text-transform:uppercase;letter-spacing:.5px;padding:6px;background:#fff;border-bottom:1px solid #e6e6e6}
.ad-slot.ad-large{min-height:250px}

.faq-item{background:#fff;border:1px solid #e6e6e6;border-radius:8px;margin-bottom:6px;overflow:hidden}
.faq-item summary{padding:12px 14px;cursor:pointer;font-weight:600;font-size:.92rem;color:#1a1a1a;list-style:none;display:flex;justify-content:space-between;align-items:center}
.faq-item summary::-webkit-details-marker{display:none}
.faq-item summary::after{content:'+';color:#3d9b34;font-size:1.3rem;font-weight:300;line-height:1}
.faq-item[open] summary::after{content:'−'}
.faq-item .faq-answer{padding:0 14px 12px;color:#444;font-size:.88rem;line-height:1.6}

.soft-link-box{background:#f0f8f4;border:1px dashed #3d9b34;border-radius:8px;padding:14px 16px;margin:18px 0;text-align:center;font-size:.9rem;color:#444}
.soft-link-box a{font-weight:600;color:#3d9b34}

footer{background:#1a1a1a;color:#aaa;padding:24px 0;text-align:center;font-size:.78rem;line-height:1.6;margin-top:24px}
footer a{color:#3d9b34;text-decoration:underline}
footer .disclaimer{background:rgba(61,155,52,.1);padding:12px;border-radius:6px;border-left:3px solid #3d9b34;text-align:left;margin:12px auto;max-width:680px;color:#ccc;font-size:.75rem}

@media(min-width:760px){
  h1{font-size:1.8rem}
  .hero h1{font-size:1.8rem}
  .layout{display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start}
  .sidebar-ads{position:sticky;top:14px}
  .sidebar-ads .ad-slot{min-height:300px}
}
</style>

<script type='application/ld+json'>
{"@context":"https://schema.org","@type":"Article","headline":"<?=$page_title?>","description":"<?=$page_desc?>","datePublished":"2026-04-29","dateModified":"2026-04-29","author":{"@type":"Organization","name":"Xegold"},"publisher":{"@type":"Organization","name":"Xegold","logo":{"@type":"ImageObject","url":"https://xegold.xyz/wp-content/uploads/2024/09/cropped-XEGOLD.png"}}}
</script>

<!-- JoinAds do xegold (publisher 20693) -->
<script type='module' src='https://script.joinads.me/myad20693.js' crossorigin='anonymous' async></script>
</head>
<body>

<div class='progress-bar' id='progress-bar'></div>

<div class='top-strip'>🎓 <strong>SENAC 2026</strong> · Lista oficial · Cursos PSG com certificado MEC</div>

<header class='header'>
  <div class='container'>
    <div class='logo'>Xegold · Cursos Grátis</div>
    <span class='ind-tag'>SENAC PSG</span>
  </div>
</header>

<section class='hero'>
  <div class='container'>
    <h1>Cursos SENAC Gratuitos 2026: Lista Completa por Área</h1>
    <p>Programa de Gratuidade (PSG) do SENAC com vagas abertas em 2026: cursos por área, requisitos por estado e o passo a passo da inscrição.</p>
    <div class='badges'>
      <span class='badge'>+95 mil alunos formados</span>
      <span class='badge'>Certificado MEC</span>
      <span class='badge'>EAD + Presencial</span>
    </div>
  </div>
</section>

<div class='update-line'>
  <div class='container'>
    <span>Atualizado em 29 de abril de 2026</span> · ⏱ 7 min de leitura
  </div>
</div>

<main>
<div class='container'>
  <div class='layout'>
    <div class='content-main'>

      <!-- AD SLOT 1 — Above the fold -->
      <div class='ad-slot ad-large'><div joinadscode='Content1'></div></div>

      <article>

        <p>Você chegou aqui porque quer fazer um curso SENAC <strong>de graça</strong> com certificado válido. Esta página é o destino certo: lista completa dos cursos do <strong>Programa de Gratuidade (PSG) SENAC 2026</strong>, requisitos por estado e como garantir sua vaga. Sem rodeio, sem propaganda enganosa.</p>

        <div class='highlight-box'>
          <strong>O que é o PSG SENAC:</strong> programa que destina <strong>no mínimo 1/3 da receita líquida</strong> do SENAC para oferta de vagas gratuitas em cursos profissionalizantes. Para quem ganha até <strong>2 salários mínimos por pessoa da família</strong>, o curso é 100% pago pelo programa — incluindo material didático na maioria dos casos.
        </div>

        <h2>Cursos SENAC PSG 2026 por Área</h2>

        <p>Abaixo, as áreas com vagas abertas para o primeiro semestre de 2026. As vagas variam por estado — sempre confira o site da unidade SENAC do seu estado para ver as turmas locais.</p>

        <div class='area-card'>
          <h3><span class='emoji'>💻</span> Tecnologia da Informação <span class='vagas'>Alta procura</span></h3>
          <ul>
            <li><strong>Informática Básica</strong> — 80h, EAD ou presencial</li>
            <li><strong>Pacote Office Completo (Word, Excel, PowerPoint)</strong> — 120h</li>
            <li><strong>Excel Avançado</strong> — 60h, EAD</li>
            <li><strong>Introdução à Programação</strong> — 60h, EAD</li>
            <li><strong>Power BI para Análise de Dados</strong> — 40h, EAD</li>
            <li><strong>Marketing Digital</strong> — 60h, EAD</li>
            <li><strong>Design Gráfico</strong> — 80h, presencial</li>
          </ul>
        </div>

        <div class='area-card'>
          <h3><span class='emoji'>📊</span> Administração e Negócios <span class='vagas'>Vagas abertas</span></h3>
          <ul>
            <li><strong>Assistente Administrativo</strong> — 160h, presencial</li>
            <li><strong>Auxiliar de Recursos Humanos</strong> — 120h, presencial</li>
            <li><strong>Assistente Financeiro</strong> — 120h, EAD</li>
            <li><strong>Vendas e Atendimento ao Cliente</strong> — 60h, EAD</li>
            <li><strong>Liderança e Gestão de Equipes</strong> — 40h, EAD</li>
            <li><strong>Gestão de Pequenos Negócios</strong> — 80h, EAD</li>
          </ul>
        </div>

        <!-- AD SLOT 2 — Após primeiras áreas -->
        <div class='ad-slot'><div joinadscode='Content2'></div></div>

        <div class='area-card'>
          <h3><span class='emoji'>💊</span> Saúde e Bem-estar <span class='vagas'>Mais procurados</span></h3>
          <ul>
            <li><strong>Cuidador de Idosos</strong> — 160h, presencial</li>
            <li><strong>Auxiliar de Enfermagem</strong> — 1.200h, presencial</li>
            <li><strong>Atendente de Farmácia</strong> — 200h, presencial</li>
            <li><strong>Massoterapia</strong> — 200h, presencial</li>
            <li><strong>Primeiros Socorros</strong> — 40h, EAD</li>
            <li><strong>Saúde Mental no Trabalho</strong> — 30h, EAD</li>
          </ul>
        </div>

        <div class='area-card'>
          <h3><span class='emoji'>💅</span> Beleza e Estética <span class='vagas'>Vagas limitadas</span></h3>
          <ul>
            <li><strong>Manicure e Pedicure</strong> — 160h, presencial</li>
            <li><strong>Maquiagem Profissional</strong> — 80h, presencial</li>
            <li><strong>Maquiagem para Pele Negra</strong> — 40h, presencial</li>
            <li><strong>Penteados e Coque Profissional</strong> — 40h, presencial</li>
            <li><strong>Técnicas de Tranças Afro</strong> — 60h, presencial</li>
            <li><strong>Unhas Decoradas e Nail Art</strong> — 40h, presencial</li>
            <li><strong>Gestão de Salões de Beleza</strong> — 60h, EAD</li>
            <li><strong>Depilação Profissional</strong> — 80h, presencial</li>
          </ul>
        </div>

        <div class='area-card'>
          <h3><span class='emoji'>🍳</span> Gastronomia e Hotelaria <span class='vagas'>Vagas abertas</span></h3>
          <ul>
            <li><strong>Cozinha Brasileira</strong> — 80h, presencial</li>
            <li><strong>Confeitaria Básica</strong> — 80h, presencial</li>
            <li><strong>Padaria Artesanal</strong> — 100h, presencial</li>
            <li><strong>Garçom e Atendimento</strong> — 60h, presencial</li>
            <li><strong>Preparo de Drinques e Coquetéis</strong> — 40h, presencial</li>
            <li><strong>Aproveitamento Integral de Alimentos</strong> — 30h, EAD</li>
          </ul>
        </div>

        <!-- AD SLOT 3 — Meio do conteúdo -->
        <div class='ad-slot ad-large'><div joinadscode='Content3'></div></div>

        <div class='area-card'>
          <h3><span class='emoji'>👶</span> Educação e Pedagogia <span class='vagas'>Vagas abertas</span></h3>
          <ul>
            <li><strong>Auxiliar de Sala em Educação Infantil</strong> — 200h, presencial</li>
            <li><strong>Cuidador Infantil</strong> — 160h, presencial</li>
            <li><strong>Mediador Escolar</strong> — 120h, EAD</li>
            <li><strong>Educação Especial e Inclusiva</strong> — 80h, EAD</li>
          </ul>
        </div>

        <div class='area-card'>
          <h3><span class='emoji'>👔</span> Moda e Vestuário <span class='vagas'>Vagas abertas</span></h3>
          <ul>
            <li><strong>Costureiro Industrial</strong> — 200h, presencial</li>
            <li><strong>Modelagem Industrial</strong> — 160h, presencial</li>
            <li><strong>Estilismo Básico</strong> — 80h, presencial</li>
            <li><strong>Costura Criativa</strong> — 60h, presencial</li>
          </ul>
        </div>

        <h2>Quem pode se inscrever no PSG SENAC</h2>

        <p>O PSG não é para qualquer pessoa — é uma política pública de inclusão social. Os critérios são definidos por lei e aplicam-se a todos os estados:</p>

        <div class='docs-list'>
          <ul>
            <li><strong>Renda familiar per capita de até 2 salários mínimos</strong> — calcula-se somando a renda de todos da família e dividindo pelo número de moradores</li>
            <li><strong>Idade mínima</strong> — geralmente 14 anos para cursos de aprendizagem e 16 anos para a maioria; alguns cursos exigem 18+</li>
            <li><strong>Escolaridade compatível com o curso</strong> — informática básica geralmente exige só alfabetização; cursos técnicos exigem ensino fundamental ou médio</li>
            <li><strong>Não estar matriculado em outro curso PSG no momento</strong> — você pode fazer um por vez</li>
            <li><strong>Documentos pessoais e comprovação de renda</strong> — explicado mais abaixo</li>
          </ul>
        </div>

        <div class='highlight-box'>
          <strong>⚠️ Atenção:</strong> as vagas do PSG são <strong>limitadas e por ordem de inscrição</strong> dentro de cada turma. Cursos populares como Cuidador de Idosos, Manicure e Informática enchem em <strong>poucas horas</strong> após abrirem. Mantenha o cadastro pronto antes do dia da abertura.
        </div>

        <h2>Como se inscrever no SENAC PSG: passo a passo</h2>

        <ol class='steps'>
          <li><strong>Acesse o site do SENAC do seu estado</strong>
          Cada SENAC estadual tem seu próprio portal de inscrições. Procure por "SENAC [seu estado] gratuidade" ou veja a lista de portais por estado abaixo.</li>

          <li><strong>Crie seu cadastro no portal</strong>
          Você vai precisar do CPF, e-mail e telefone. Guarde a senha com cuidado — é por ali que você acompanha o status da inscrição.</li>

          <li><strong>Pesquise pelo curso desejado</strong>
          Use o filtro "Gratuidade" ou "PSG" para ver só os cursos sem custo. Veja as turmas com data e horário compatível com você.</li>

          <li><strong>Faça a inscrição na turma</strong>
          A inscrição é rápida (5 minutos) mas não garante a vaga. Você fica na lista até o SENAC confirmar a documentação.</li>

          <li><strong>Envie os documentos</strong>
          Pode ser pelo portal (digital) ou presencial na unidade. O prazo geralmente é de até 5 dias úteis após a inscrição.</li>

          <li><strong>Aguarde a confirmação</strong>
          Em até 7 dias úteis o SENAC valida tudo e envia o e-mail de confirmação da matrícula. Aí é só começar.</li>
        </ol>

        <!-- AD SLOT 4 — Antes da seção de estados (alta receita) -->
        <div class='ad-slot ad-large'><div joinadscode='Content4'></div></div>

        <h2>Documentos necessários para o PSG SENAC</h2>

        <div class='docs-list'>
          <ul>
            <li><strong>RG e CPF</strong> do candidato</li>
            <li><strong>Comprovante de residência</strong> recente (até 90 dias)</li>
            <li><strong>Comprovante de renda</strong> de todos os membros da família que trabalham (carteira de trabalho, holerite, declaração de MEI ou autônomo)</li>
            <li><strong>Comprovante de escolaridade</strong> compatível com o curso (histórico escolar ou certificado)</li>
            <li><strong>Documentos dos demais membros da família</strong> (RG, CPF) — para comprovar a composição familiar</li>
            <li><strong>Foto 3x4 recente</strong> (alguns estados ainda pedem)</li>
          </ul>
        </div>

        <h2>SENAC por estado: onde se inscrever</h2>

        <p>Cada estado tem seu próprio portal e calendário. Os links abaixo levam para a busca oficial. Se o seu estado não estiver listado, procure por <strong>"SENAC [estado] gratuidade"</strong> no Google — a primeira opção oficial é a correta.</p>

        <div class='estados-grid'>
          <div class='estado-tag'><strong>SP</strong>São Paulo</div>
          <div class='estado-tag'><strong>RJ</strong>Rio de Janeiro</div>
          <div class='estado-tag'><strong>MG</strong>Minas Gerais</div>
          <div class='estado-tag'><strong>RS</strong>Rio Grande do Sul</div>
          <div class='estado-tag'><strong>PR</strong>Paraná</div>
          <div class='estado-tag'><strong>SC</strong>Santa Catarina</div>
          <div class='estado-tag'><strong>BA</strong>Bahia</div>
          <div class='estado-tag'><strong>PE</strong>Pernambuco</div>
          <div class='estado-tag'><strong>CE</strong>Ceará</div>
          <div class='estado-tag'><strong>GO</strong>Goiás</div>
          <div class='estado-tag'><strong>DF</strong>Distrito Federal</div>
          <div class='estado-tag'><strong>ES</strong>Espírito Santo</div>
          <div class='estado-tag'><strong>PA</strong>Pará</div>
          <div class='estado-tag'><strong>AM</strong>Amazonas</div>
          <div class='estado-tag'><strong>MA</strong>Maranhão</div>
          <div class='estado-tag'><strong>MT</strong>Mato Grosso</div>
        </div>

        <div class='soft-link-box'>
          Para o portal nacional do SENAC EAD com cursos abertos a todo Brasil:
          <a href='<?=$senac_oficial?>' rel='noopener nofollow' target='_blank' onclick='trackEngagement("link_senac_oficial",1)'>www.ead.senac.br</a>
        </div>

        <h2>Erros que fazem o candidato perder a vaga</h2>

        <p>Anos vendo gente desistir bem na hora final do processo. Os erros mais comuns:</p>

        <ul>
          <li><strong>Esperar a próxima rodada de vagas</strong> — a maioria dos cursos populares enche no primeiro dia. Se você espera "abrir mais turmas", a chance é mínima.</li>
          <li><strong>Demorar para enviar a documentação</strong> — sem documento, sem matrícula. Mesmo que você tenha feito a inscrição, ela cai depois de 5-7 dias sem confirmação documental.</li>
          <li><strong>Comprovar renda errada</strong> — se você é autônomo, a declaração precisa estar atualizada no CadÚnico ou ter declaração formal. Renda inconsistente = matrícula bloqueada.</li>
          <li><strong>Inscrever em curso fora do PSG</strong> — nem todo curso do SENAC é gratuito. Confira o filtro "Gratuidade" antes de selecionar.</li>
          <li><strong>Não ler os pré-requisitos</strong> — alguns cursos exigem ensino médio completo. Se você não tem, o sistema rejeita na hora.</li>
        </ul>

        <div class='highlight-box'>
          <strong>💡 Dica de quem já passou pelo processo:</strong> deixe a documentação pronta em PDF antes mesmo de abrir as inscrições. Quando o portal liberar, você anexa em 1 minuto e garante prioridade na fila — a diferença entre ficar dentro ou fora da turma costuma ser de minutos.
        </div>

        <h2>Cursos SENAC EAD nacionais (sem PSG)</h2>

        <p>Além do PSG (que é por estado), o SENAC tem o portal nacional <strong>ead.senac.br</strong> com alguns cursos curtos abertos a qualquer brasileiro, sem comprovação de renda. Ótima alternativa para quem não se enquadra no PSG.</p>

        <ul>
          <li>Cursos de 4h a 30h, online, com material atualizado</li>
          <li>Certificado emitido na hora ao concluir</li>
          <li>Áreas: vendas, atendimento, comportamento profissional, soft skills</li>
          <li>Algumas opções são pagas (a partir de R$ 35), mas várias são <strong>gratuitas mesmo sem PSG</strong></li>
        </ul>

      </article>

      <h2>Perguntas frequentes</h2>

      <details class='faq-item'>
        <summary>Eu posso fazer mais de um curso SENAC PSG ao mesmo tempo?</summary>
        <div class='faq-answer'>Em regra, não. O PSG permite uma matrícula gratuita por vez. Quando você concluir, pode se inscrever em outro. Cursos curtos (40h-60h) costumam terminar em 1-2 meses, então em pouco tempo você pode acumular vários certificados.</div>
      </details>

      <details class='faq-item'>
        <summary>Se eu trancar o curso, perco a vaga?</summary>
        <div class='faq-answer'>Sim. Trancamento de matrícula significa abrir mão da vaga, e ela retorna pra fila. Em alguns casos especiais (problema de saúde com atestado), o SENAC permite trancamento e reabertura, mas é exceção, não regra.</div>
      </details>

      <details class='faq-item'>
        <summary>Quanto tempo dura o certificado SENAC?</summary>
        <div class='faq-answer'>O certificado SENAC <strong>não expira</strong>. Vale para sempre, em todo o Brasil, em concursos, currículos e processos seletivos. É reconhecido pelo MEC como Formação Inicial e Continuada (FIC).</div>
      </details>

      <details class='faq-item'>
        <summary>Eu trabalho com carteira assinada. Tenho direito ao PSG?</summary>
        <div class='faq-answer'>Tem, desde que a renda familiar per capita esteja dentro do limite (até 2 salários mínimos por pessoa). O sistema soma a renda total da família (incluindo o seu salário) e divide pelo número de pessoas da casa. Se cair dentro do limite, você está elegível.</div>
      </details>

      <details class='faq-item'>
        <summary>O SENAC oferece auxílio transporte ou alimentação?</summary>
        <div class='faq-answer'>Em alguns programas específicos sim, especialmente em cursos técnicos longos (1.000h+) e em parceria com governos estaduais. Para cursos curtos do PSG, geralmente não há auxílio. Sempre confira na unidade SENAC do seu estado.</div>
      </details>

      <details class='faq-item'>
        <summary>Posso fazer SENAC PSG sendo bolsista de outro programa (ProUni, Pronatec)?</summary>
        <div class='faq-answer'>Depende do programa. Você pode acumular SENAC PSG com programas de outros níveis (ProUni para faculdade, por exemplo). Mas não pode ser bolsista de outro curso técnico ativo simultaneamente. Confira os termos do edital específico.</div>
      </details>

      <details class='faq-item'>
        <summary>Quando saem as próximas vagas do SENAC em 2026?</summary>
        <div class='faq-answer'>O SENAC abre turmas de PSG em ondas durante o ano todo, geralmente em fevereiro, maio, agosto e outubro. As datas variam por estado. A melhor estratégia é cadastrar-se no portal do seu estado e ativar notificação para receber e-mail quando novas turmas abrirem.</div>
      </details>

      <details class='faq-item'>
        <summary>O SENAC EAD nacional substitui o PSG estadual?</summary>
        <div class='faq-answer'>Não exatamente. O EAD nacional (ead.senac.br) tem cursos curtos, alguns gratuitos, abertos a qualquer pessoa. O PSG estadual oferece cursos mais longos e profissionalizantes, gratuitos para quem se enquadra no critério de renda. São complementares — você pode usar os dois.</div>
      </details>

    </div>

    <aside class='sidebar-ads'>
      <!-- AD SLOT 5 — Sidebar -->
      <div class='ad-slot ad-large'><div joinadscode='Sidebar'></div></div>
    </aside>

  </div>
</div>
</main>

<footer>
  <div class='container'>
    <div>Xegold — Guia de cursos gratuitos com certificado</div>
    <div class='disclaimer'>
      <strong>Aviso:</strong> Este site não tem vínculo com o SENAC ou Sistema S. Somos um portal informativo independente que reúne informações públicas sobre cursos gratuitos. Para inscrição oficial, sempre acesse o site da unidade SENAC do seu estado.
    </div>
    <div style='margin-top:6px'>
      <a href='https://xegold.xyz/politica-de-privacidade-2/'>Política de Privacidade</a> ·
      <a href='https://xegold.xyz/termos-de-uso/'>Termos</a> ·
      <a href='https://xegold.xyz/fale-conosco/'>Contato</a>
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
    Object.keys(ms).forEach(function(m){if(!ms[m]&&p>=m){ms[m]=true;trackEngagement('senac_scroll_'+m,m)}});
  },{passive:true});
  setTimeout(function(){trackEngagement('senac_time_30s',30)},30000);
  setTimeout(function(){trackEngagement('senac_time_60s',60)},60000);
  setTimeout(function(){trackEngagement('senac_time_120s',120)},120000);
  setTimeout(function(){trackEngagement('senac_time_180s',180)},180000);
})();
</script>

</body>
</html>
