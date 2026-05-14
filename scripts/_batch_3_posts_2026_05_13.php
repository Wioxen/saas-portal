<?php
declare(strict_types=1);
/**
 * Batch 3 posts manuais — sessão Opus 13/05/2026 noite.
 *
 * Trends de origem (Pingo via RSS):
 *   - cursosenac        #17709 UNIRIO 755 vagas licenciaturas CEDERJ 2026/2 (Hora Brasil)
 *   - comocomprar       #16945 Projetores inteligentes Amazon (Olhar Digital)
 *   - ondecompraragora  #20288 JBL Charge 5 47% off Amazon (Portal 6)
 *
 * Conteúdo escrito por Opus em sessão Claude Code sem chamada LLM API.
 * Publica todos como DRAFT em WP. Usuário revisa, anexa featured se faltou,
 * publica. Amazon afiliado: PrettyLink fixo https://amzn.to/4ckOgUc
 *
 * Uso:
 *   php scripts/_batch_3_posts_2026_05_13.php
 */

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

// ════════════════════════════════════════════════════════════════════
// POST 1 — GUIADOSCURSOS #17709 (re-rotado de cursosenac, é vestibular)
//   — UNIRIO 755 vagas licenciaturas
// ════════════════════════════════════════════════════════════════════
$post1 = [
    'slug_site' => 'guiadoscursos',
    'trend_id'  => 17709,
    'titulo'    => 'UNIRIO abre 755 vagas em licenciaturas gratuitas pelo CEDERJ; inscrições até 17 de maio',
    'slug'      => 'unirio-755-vagas-licenciaturas-cederj-2026-pedagogia-historia-matematica',
    'metaDesc'  => 'UNIRIO oferece 755 vagas em licenciaturas gratuitas via CEDERJ 2026/2 (Pedagogia, História, Matemática). Inscrições até 17/05. Veja polos, cotas e como participar.',
    'focusKw'   => 'unirio cederj licenciatura gratuita',
    'fonteUrl'  => 'https://www.horabrasil.com.br/2026/05/12/unirio-licenciaturas-gratuitas-cederj/',
    'fonteNome' => 'Hora Brasil',
    'autorFonte'=> 'Natalia Marinho',
    'ogImage'   => 'https://www.horabrasil.com.br/wp-content/uploads/2022/10/768px-UNIRIO_Logo.svg.png',
    'categoria' => 'Vestibular',
    'tags'      => ['UNIRIO', 'CEDERJ', 'Vestibular', 'Licenciatura', 'Pedagogia', 'História', 'Matemática', 'Universidade Federal', 'Rio de Janeiro', 'Professores'],
    'html'      => <<<'HTML'
<p>A <strong>Universidade Federal do Estado do Rio de Janeiro (UNIRIO)</strong> oferece <strong>755 vagas em cursos gratuitos de licenciatura</strong> no Vestibular CEDERJ 2026/2. As inscrições vão até <strong>17 de maio</strong> e o ingresso é previsto para o segundo semestre letivo de 2026.</p>

<p>As vagas estão distribuídas em 3 cursos no modelo semipresencial do Consórcio CEDERJ. A maior oferta é para Pedagogia, segundo apuração da redação a partir da reportagem do Hora Brasil assinada por Natalia Marinho.</p>

<h2>Cursos e vagas oferecidos pela UNIRIO no CEDERJ 2026/2</h2>

<p>O edital contempla três licenciaturas, todas gratuitas e semipresenciais. A distribuição segue abaixo.</p>

<ul>
  <li><strong>Licenciatura em Pedagogia:</strong> 360 vagas;</li>
  <li><strong>Licenciatura em História:</strong> 200 vagas;</li>
  <li><strong>Licenciatura em Matemática:</strong> 195 vagas.</li>
</ul>

<p>O modelo semipresencial combina atividades online com encontros presenciais obrigatórios nos polos regionais. A formação é voltada a candidatos que já concluíram o Ensino Médio.</p>

<h2>Polos regionais onde os cursos serão ofertados</h2>

<p>A distribuição por polos permite que candidatos de diferentes regiões do Rio de Janeiro acessem o curso sem deslocamento diário até a capital. Os polos variam por licenciatura.</p>

<ul>
  <li><strong>Pedagogia (360 vagas):</strong> Barra do Piraí, Belford Roxo, Cantagalo, Duque de Caxias, Niterói, Petrópolis, Piraí, Resende, São Gonçalo e Volta Redonda;</li>
  <li><strong>História (200 vagas):</strong> Miguel Pereira, Piraí, Saquarema, Três Rios, Itaocara, Magé e Rio Bonito;</li>
  <li><strong>Matemática (195 vagas):</strong> Cantagalo, Itaocara, Macaé, Magé, Natividade, Rio Bonito e Rio das Flores.</li>
</ul>

<h2>Cotas e reserva de vagas para professores da rede pública</h2>

<p>As vagas seguem a legislação federal de cotas, com reserva para candidatos de escolas públicas e subgrupos definidos por renda e ações afirmativas. A UNIRIO aplica os critérios do sistema oficial.</p>

<p>Para as licenciaturas, o edital prevê adicionalmente <strong>reserva de 20% das vagas para professores da rede pública</strong> que ainda não possuem formação na área em que lecionam. A regra amplia o acesso de profissionais já em sala à formação superior exigida.</p>

<h2>Como se inscrever no Vestibular CEDERJ 2026/2 da UNIRIO</h2>

<p>As inscrições são feitas exclusivamente pela internet até <strong>17 de maio de 2026</strong>. O candidato deve seguir o passo a passo abaixo.</p>

<ol>
  <li>Acessar o site oficial do Vestibular CEDERJ (<a href='https://www.cederj.edu.br' target='_blank' rel='noopener'>cederj.edu.br</a>) até 17 de maio;</li>
  <li>Preencher o formulário de inscrição com dados pessoais e escolaridade;</li>
  <li>Escolher o curso e o polo regional de interesse, conforme a lista de oferta;</li>
  <li>Pagar a taxa de inscrição de <strong>R$ 95,50</strong> via boleto, Pix ou cartão;</li>
  <li>Acompanhar o cronograma do edital para as datas das provas.</li>
</ol>

<div class='cta-oficial' style='margin:24px 0;padding:18px 22px;background:#eef6f0;border-left:6px solid #1f8a4c;border-radius:6px;'><p style='margin:0 0 8px;font-size:17px;color:#1a2a1f;'><strong>Inscrição oficial Vestibular CEDERJ 2026/2</strong></p><p style='margin:0 0 12px;font-size:14px;color:#3a4a3f;'>Inscrições abertas até 17 de maio de 2026. Acesso pelo site oficial do Consórcio CEDERJ.</p><a href='https://www.cederj.edu.br' target='_blank' rel='noopener' style='display:inline-block;background:#1f8a4c;color:#fff;font-weight:600;font-size:15px;padding:11px 22px;border-radius:5px;text-decoration:none;'>Acessar cederj.edu.br</a></div>

<h2>Como será a seleção dos candidatos</h2>

<p>A seleção é feita por prova objetiva e redação, conforme regras do edital. A UNIRIO também aceita aproveitamento da nota do Enem em edições entre 2015 e 2025, dentro dos critérios definidos pelo processo.</p>

<p>Para os candidatos que utilizarem o Enem, a redação ainda compõe a nota final segundo o sistema. Os detalhes específicos de pontuação por área constam no edital publicado pelo Consórcio CEDERJ.</p>

<details class='faq-discover'>
<summary><strong>Até quando posso me inscrever no Vestibular CEDERJ 2026/2 da UNIRIO?</strong></summary>
<p>As inscrições vão até 17 de maio de 2026 e devem ser feitas exclusivamente pelo site oficial do Vestibular CEDERJ (cederj.edu.br).</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto custa a inscrição no Vestibular CEDERJ 2026/2?</strong></summary>
<p>A taxa de inscrição é de R$ 95,50 e pode ser paga via boleto bancário, Pix ou cartão de crédito conforme as opções disponibilizadas no sistema.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quais cursos a UNIRIO está oferecendo no CEDERJ 2026/2?</strong></summary>
<p>São três licenciaturas: Pedagogia (360 vagas), História (200 vagas) e Matemática (195 vagas), totalizando 755 vagas distribuídas em polos regionais do estado do Rio de Janeiro.</p>
</details>

<details class='faq-discover'>
<summary><strong>Professores da rede pública têm prioridade nas vagas?</strong></summary>
<p>Sim. O edital reserva 20% das vagas das licenciaturas para professores da rede pública que ainda não possuem formação na área em que atuam, conforme regras do processo seletivo.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso usar a nota do Enem para concorrer?</strong></summary>
<p>Sim. A UNIRIO aceita a nota do Enem em edições entre 2015 e 2025, dentro dos critérios definidos pelo edital. A redação do Enem também é considerada na nota final.</p>
</details>

<p><em>Fonte: reportagem de Natalia Marinho publicada em Hora Brasil em 12 de maio de 2026.</em></p>
HTML,
];

// ════════════════════════════════════════════════════════════════════
// POST 2 — COMOCOMPRAR #16945 — Projetores inteligentes em oferta
// ════════════════════════════════════════════════════════════════════
$post2 = [
    'slug_site' => 'comocomprar',
    'trend_id'  => 16945,
    'titulo'    => 'Projetores inteligentes em oferta: Mini Portátil Android sai por R$ 153 e JMGO N1S Nano por R$ 2.499',
    'slug'      => 'projetores-inteligentes-oferta-mini-portatil-android-jmgo-n1s-nano-amazon',
    'metaDesc'  => 'Compare projetores inteligentes em oferta na Amazon: Mini Portátil 5G Android 11 por R$ 153,33 e JMGO N1S Nano com Google TV por R$ 2.499. Veja specs e qual escolher.',
    'focusKw'   => 'projetor inteligente oferta amazon',
    'fonteUrl'  => 'https://olhardigital.com.br/2026/05/12/reviews/oferta-projetores-inteligentes-para-cinema-em-casa/',
    'fonteNome' => 'Olhar Digital',
    'autorFonte'=> 'Heloisa Zotino Rodrigues',
    'ogImage'   => 'https://img.odcdn.com.br/wp-content/uploads/2026/05/Design-sem-nome-2026-05-12T101804.647.png',
    'categoria' => 'Tecnologia',
    'tags'      => ['Projetor', 'JMGO', 'Cinema em Casa', 'Amazon', 'Android TV', 'Google TV', 'Oferta'],
    'html'      => <<<'HTML'
<p>Duas ofertas de projetores inteligentes aparecem na Amazon nesta semana: o <strong>Mini Projetor Portátil 5G Wi-Fi 6 Android 11</strong> caiu de R$ 199,90 para <strong>R$ 153,33</strong> e o <strong>JMGO N1S Nano</strong> com Google TV foi reduzido de R$ 2.999 para <strong>R$ 2.499</strong>, conforme levantamento do Olhar Digital.</p>

<p>Os dois modelos representam categorias opostas: opção econômica para uso casual e opção premium para experiência de cinema portátil. Veja as especificações antes de decidir.</p>

<h2>Mini Projetor Portátil Android 11 por R$ 153,33: a opção econômica</h2>

<p>O modelo de entrada chega com sistema Android 11 integrado, o que dispensa a necessidade de um TV Box separado. O usuário acessa Netflix, YouTube e outros apps de streaming direto pelo projetor.</p>

<p>Especificações principais segundo a oferta:</p>

<ul>
  <li><strong>Sistema:</strong> Android 11 integrado;</li>
  <li><strong>Wi-Fi:</strong> 5G + Wi-Fi 6;</li>
  <li><strong>Bluetooth:</strong> 5.0;</li>
  <li><strong>Resolução nativa:</strong> Full HD 1080p (suporte a 4K);</li>
  <li><strong>Iluminação:</strong> LED;</li>
  <li><strong>Rotação:</strong> 180 graus;</li>
  <li><strong>Correção:</strong> trapezoidal automática;</li>
  <li><strong>Preço:</strong> R$ 153,33 (de R$ 199,90).</li>
</ul>

<p>É a escolha pra quem quer testar a categoria sem investir muito. Funciona bem em quartos e ambientes pequenos, com tela suficiente pra filmes, séries e até reuniões online improvisadas.</p>

<div class='cta-afiliado' style='text-align:center;margin:30px 0;padding:22px;background:#fff8e7;border:2px dashed #ff9900;border-radius:8px;'><p style='margin:0 0 12px;font-size:16px;color:#333;'><strong>Encontrou o produto certo?</strong></p><a href='https://amzn.to/4ckOgUc' target='_blank' rel='nofollow sponsored noopener' style='display:inline-block;background:#ff9900;color:#fff;font-weight:bold;font-size:17px;padding:14px 28px;border-radius:6px;text-decoration:none;'>🛒 Ver oferta na Amazon</a><p style='margin:12px 0 0;font-size:12px;color:#888;'>Link de afiliado — apoia o portal sem custo adicional pra você</p></div>

<h2>JMGO N1S Nano por R$ 2.499: o premium com Google TV</h2>

<p>O JMGO N1S Nano joga em outra liga. O sistema Google TV nativo entrega a mesma experiência de uma smart TV moderna, com acesso direto a Netflix, YouTube, Prime Video, Disney+ e Spotify sem dispositivos extras.</p>

<p>Especificações principais segundo a oferta:</p>

<ul>
  <li><strong>Sistema:</strong> Google TV integrado;</li>
  <li><strong>Resolução:</strong> suporte a 4K nativo;</li>
  <li><strong>Brilho:</strong> 460 Lumens ISO;</li>
  <li><strong>Projeção:</strong> até 180 polegadas;</li>
  <li><strong>Suporte:</strong> gimbal integrado (ajuste automático);</li>
  <li><strong>Correção:</strong> automática avançada;</li>
  <li><strong>Áudio:</strong> alto-falantes 10W;</li>
  <li><strong>Preço:</strong> R$ 2.499 (de R$ 2.999).</li>
</ul>

<p>O suporte gimbal e a projeção de até 180 polegadas viabilizam uso em ambientes externos, como churrascos no quintal ou cinema na praia. A redução de R$ 500 ajuda a justificar o investimento dentro da categoria premium.</p>

<h2>Mini Projetor Android ou JMGO N1S Nano: qual escolher</h2>

<p>A diferença de R$ 2.345 entre os dois modelos resolve em uso pretendido. O Mini Portátil compensa pra quem está experimentando a categoria ou quer um aparelho secundário. O JMGO N1S Nano é o modelo definitivo pra montar uma sala dedicada de cinema em casa.</p>

<p>Em comparativo rápido:</p>

<ul>
  <li><strong>Mini Android (R$ 153,33):</strong> Full HD nativo, Android 11, ideal pra quartos e uso casual;</li>
  <li><strong>JMGO N1S Nano (R$ 2.499):</strong> 4K nativo, Google TV, projeção até 180", gimbal integrado.</li>
</ul>

<p>Para quem quer custo-benefício imediato, o Mini Portátil entrega o suficiente. Para quem prioriza qualidade e versatilidade, o JMGO N1S Nano tem o pacote mais completo da categoria portátil.</p>

<div class='cta-afiliado cta-fim' style='text-align:center;margin:32px 0;padding:20px;background:#fff8e7;border:2px solid #ff9900;border-radius:8px;'><a href='https://amzn.to/4ckOgUc' target='_blank' rel='nofollow sponsored noopener' style='display:inline-block;background:#ff9900;color:#fff;font-weight:bold;font-size:18px;padding:16px 32px;border-radius:6px;text-decoration:none;'>🛒 Comprar agora na Amazon</a></div>

<details class='faq-discover'>
<summary><strong>Vale a pena comprar um projetor portátil em 2026?</strong></summary>
<p>Vale para quem quer flexibilidade de uso, projeção em diferentes ambientes e não tem espaço para uma TV grande. Projetores como o Mini Portátil Android entregam Full HD por menos de R$ 200, enquanto modelos premium como o JMGO N1S Nano oferecem qualidade comparável a smart TVs.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual a diferença entre Android TV e Google TV em projetores?</strong></summary>
<p>O Google TV é uma evolução do Android TV com interface mais moderna e recomendações personalizadas de conteúdo. O JMGO N1S Nano usa Google TV nativo, enquanto o Mini Projetor da oferta utiliza Android 11 padrão (sem a camada Google TV).</p>
</details>

<details class='faq-discover'>
<summary><strong>O Mini Projetor Android serve para apresentações de trabalho?</strong></summary>
<p>Serve para apresentações casuais em ambientes não tão claros. Para reuniões corporativas em salas iluminadas, o brilho LED do modelo pode ser insuficiente. Modelos com mais lumens, como o JMGO N1S Nano (460 ISO), funcionam melhor nesse cenário.</p>
</details>

<p><em>Fonte: reportagem de Heloisa Zotino Rodrigues publicada em Olhar Digital em 12 de maio de 2026.</em></p>
HTML,
];

// ════════════════════════════════════════════════════════════════════
// POST 3 — ONDECOMPRARAGORA #20288 — JBL Charge 5 47% off
// ════════════════════════════════════════════════════════════════════
$post3 = [
    'slug_site' => 'ondecompraragora',
    'trend_id'  => 20288,
    'titulo'    => 'Oferta relâmpago: JBL Charge 5 com 47% de desconto na Amazon hoje',
    'slug'      => 'jbl-charge-5-oferta-amazon-47-desconto-relampago-13-maio-2026',
    'metaDesc'  => 'A caixa de som JBL Charge 5 está com 47% de desconto na Amazon nesta terça (13). Veja specs (20h bateria, IPX7, PartyBoost, Powerbank) e se vale a pena.',
    'focusKw'   => 'jbl charge 5 oferta amazon desconto',
    'fonteUrl'  => 'https://portal6.com.br/2026/05/13/oferta-relampago-caixa-de-som-jbl-charge-5-com-desconto-de-47/',
    'fonteNome' => 'Portal 6',
    'autorFonte'=> 'Redação Ofertas',
    'ogImage'   => 'https://m.media-amazon.com/images/I/71N8si9jomL._AC_SX522_.jpg',
    'categoria' => 'Eletrônicos',
    'tags'      => ['JBL', 'Caixa de Som Bluetooth', 'Amazon', 'Oferta Relâmpago', 'Áudio Portátil', 'PartyBoost'],
    'html'      => <<<'HTML'
<p>A caixa de som <strong>JBL Charge 5</strong> aparece com <strong>47% de desconto</strong> na Amazon nesta quarta-feira, 13 de maio. A oferta foi sinalizada pelo Portal 6 e cobre o modelo conhecido pela durabilidade da bateria, resistência à água e som potente.</p>

<p>É uma das ofertas relâmpago da semana em áudio portátil. O modelo Charge 5 segue como referência da JBL na categoria intermediária-alta de Bluetooth.</p>

<h2>JBL Charge 5: o que entrega na prática</h2>

<p>A Charge 5 chegou ao mercado como sucessora direta da Charge 4 e mantém os pontos fortes que consolidaram a linha. As especificações principais aparecem abaixo, conforme descrição da oferta.</p>

<ul>
  <li><strong>Bateria:</strong> até 20 horas de reprodução contínua;</li>
  <li><strong>Resistência:</strong> certificação IPX7 (à prova d'água em imersão);</li>
  <li><strong>Conectividade:</strong> Bluetooth 5.1;</li>
  <li><strong>Áudio:</strong> JBL Original Pro Sound;</li>
  <li><strong>Multi-conexão:</strong> compatível com PartyBoost (parea com outras JBL);</li>
  <li><strong>Energia:</strong> função Powerbank (carrega o celular).</li>
</ul>

<p>A combinação de bateria longa + IPX7 + Powerbank torna o modelo apropriado para uso prolongado em praia, piscina e viagens. O áudio JBL Original Pro Sound entrega volume e clareza suficientes para ambientes médios sem distorção em volume alto.</p>

<div class='cta-afiliado' style='text-align:center;margin:30px 0;padding:22px;background:#fff8e7;border:2px dashed #ff9900;border-radius:8px;'><p style='margin:0 0 12px;font-size:16px;color:#333;'><strong>Aproveite enquanto a oferta vale</strong></p><a href='https://amzn.to/4ckOgUc' target='_blank' rel='nofollow sponsored noopener' style='display:inline-block;background:#ff9900;color:#fff;font-weight:bold;font-size:17px;padding:14px 28px;border-radius:6px;text-decoration:none;'>🛒 Ver desconto na Amazon</a><p style='margin:12px 0 0;font-size:12px;color:#888;'>Link de afiliado — apoia o portal sem custo adicional</p></div>

<h2>Para que tipo de uso a JBL Charge 5 funciona</h2>

<p>O modelo é portátil mas com tamanho intermediário, mais robusto que a Flip 6 e menor que a Xtreme 3. Funciona melhor em três cenários.</p>

<ul>
  <li><strong>Uso doméstico:</strong> som potente para sala, varanda ou churrasco em casa;</li>
  <li><strong>Uso ao ar livre:</strong> resistência IPX7 permite levar à praia, piscina ou parque sem preocupação com água;</li>
  <li><strong>Multi-caixa:</strong> compatível com PartyBoost, pode parear com outras JBL para som ambiente em diferentes cômodos.</li>
</ul>

<p>Para uso estritamente individual em quartos pequenos, modelos menores como Flip 6 ou Clip 5 entregam o suficiente. A Charge 5 compensa quando o ambiente exige mais volume ou robustez física.</p>

<h2>Como avaliar se a oferta vale a pena</h2>

<p>O desconto de 47% indica margem agressiva para a categoria — caixas JBL geralmente trabalham com 15-25% de desconto em datas normais. Quando o desconto passa de 40%, costuma ser sinal de oferta relâmpago de janela curta (24-48h).</p>

<p>Antes de comprar, vale conferir três pontos básicos:</p>

<ul>
  <li><strong>Preço de referência:</strong> verificar histórico do produto em sites como Buscapé ou Zoom para confirmar que o desconto é real;</li>
  <li><strong>Garantia:</strong> JBL oferece 1 ano de garantia oficial no Brasil — vendido por revendedor autorizado mantém o suporte;</li>
  <li><strong>Versão:</strong> evitar confundir com modelos paralelos ou Charge 4 ainda em estoque — o nome completo é "JBL Charge 5".</li>
</ul>

<div class='cta-afiliado cta-fim' style='text-align:center;margin:32px 0;padding:20px;background:#fff8e7;border:2px solid #ff9900;border-radius:8px;'><a href='https://amzn.to/4ckOgUc' target='_blank' rel='nofollow sponsored noopener' style='display:inline-block;background:#ff9900;color:#fff;font-weight:bold;font-size:18px;padding:16px 32px;border-radius:6px;text-decoration:none;'>🛒 Garantir na Amazon agora</a></div>

<details class='faq-discover'>
<summary><strong>A JBL Charge 5 é à prova d'água?</strong></summary>
<p>Sim. A Charge 5 tem certificação IPX7, o que significa que resiste a imersão em água doce por até 30 minutos a 1 metro de profundidade. Pode ser levada à piscina, praia ou usada na chuva sem comprometer o funcionamento.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto tempo dura a bateria da JBL Charge 5?</strong></summary>
<p>A bateria entrega até 20 horas de reprodução contínua em condições ideais (volume médio, sem multi-conexão ativa). Em volume alto e com PartyBoost ativado, o tempo cai entre 12 e 16 horas.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso conectar a Charge 5 a outras caixas JBL?</strong></summary>
<p>Sim, via tecnologia PartyBoost. A caixa pode ser pareada com outros modelos JBL compatíveis (Flip 6, Charge 5, Xtreme 3, entre outros) para criar um sistema multi-caixa sem fio.</p>
</details>

<details class='faq-discover'>
<summary><strong>A JBL Charge 5 carrega celular?</strong></summary>
<p>Sim. A Charge 5 conta com função Powerbank, permitindo carregar smartphones e outros dispositivos via porta USB-A integrada. O recurso reduz a autonomia total da caixa quando ativado.</p>
</details>

<p><em>Fonte: Redação Ofertas do Portal 6, publicação de 13 de maio de 2026.</em></p>
HTML,
];

// ════════════════════════════════════════════════════════════════════
// Publicação batch
// ════════════════════════════════════════════════════════════════════
$resultados = [];
foreach ([$post1, $post2, $post3] as $info) {
    echo "\n══════ {$info['slug_site']} — trend #{$info['trend_id']} ══════\n";
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $info['slug_site']);
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

    // Featured (16:9 1200×675 crop)
    $featuredId = 0;
    try {
        $featuredId = (int)($wp->uploadImagemPorUrl169($info['ogImage'], $info['titulo'], $info['slug']) ?? 0);
        if ($featuredId > 0) echo "✅ Featured 16:9: media #{$featuredId}\n";
    } catch (Throwable $e) {
        echo "uploadImagemPorUrl169 falhou: " . $e->getMessage() . "\n";
    }
    if ($featuredId === 0) {
        try {
            $featuredId = (int)($wp->uploadImagemPorUrl($info['ogImage'], $info['titulo'], $info['slug']) ?? 0);
            if ($featuredId > 0) echo "✅ Featured original: media #{$featuredId}\n";
        } catch (Throwable $e) { echo "uploadImagemPorUrl fallback: " . $e->getMessage() . "\n"; }
    }
    if ($featuredId > 0) {
        $wp->atualizarMedia($featuredId, [
            'caption'     => "{$info['titulo']} (Foto: divulgação / {$info['fonteNome']})",
            'description' => "Imagem ilustrativa da matéria '{$info['titulo']}'.",
            'title'       => $info['titulo'],
            'alt_text'    => $info['titulo'],
        ]);
    }

    // Schema NewsArticle + FAQ
    $schemaNews = [
        '@context' => 'https://schema.org', '@type' => 'NewsArticle',
        'headline' => $info['titulo'], 'datePublished' => date('c'), 'dateModified' => date('c'), 'inLanguage' => 'pt-BR',
        'citation' => [
            '@type' => 'NewsArticle', 'url' => $info['fonteUrl'],
            'publisher' => ['@type' => 'NewsMediaOrganization', 'name' => $info['fonteNome']],
            'author'    => ['@type' => 'Person', 'name' => $info['autorFonte']],
        ],
    ];
    $contentFinal = $info['html']
        . "\n<script type=\"application/ld+json\" data-newsarticle=\"1\">\n"
        . json_encode($schemaNews, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n</script>\n";

    // Categoria + tags
    $cm = new CategoryMatcher($wp, 70.0);
    $catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch([$info['categoria']]))));
    $tagIds = $wp->resolverTags($info['tags']);

    $payload = [
        'title'   => $info['titulo'], 'slug' => $info['slug'], 'content' => $contentFinal,
        'status'  => 'draft',
        'meta'    => [
            'rank_math_title'         => $info['titulo'] . ' | ' . ucfirst($info['slug_site']),
            'rank_math_description'   => $info['metaDesc'],
            'rank_math_focus_keyword' => $info['focusKw'],
        ],
        'categories' => $catIds, 'tags' => $tagIds,
    ];
    if ($featuredId > 0) $payload['featured_media'] = $featuredId;
    if (!empty($cfgSite['default_post_author_id'])) $payload['author'] = (int)$cfgSite['default_post_author_id'];

    $r = $wp->criarPost($payload);
    $postId = (int)($r['id'] ?? 0);
    $link = (string)($r['link'] ?? '');

    if ($postId === 0) {
        echo "❌ ERRO criarPost\n";
        $resultados[] = "  {$info['slug_site']}: FALHOU";
        continue;
    }

    echo "✅ Post #{$postId} criado como DRAFT\n";
    echo "   Link: {$link}\n";

    // Posts relacionados (best effort)
    try {
        $kw = explode(' ', $info['focusKw'])[0];
        $rel = $wp->buscarRelacionados($kw, 4, $postId);
        if (is_array($rel) && count($rel) >= 2) {
            $bloco = "\n<aside class='posts-relacionados'>\n<h2>Veja também</h2>\n<ul>\n";
            foreach (array_slice($rel, 0, 4) as $r2) {
                $titRel = htmlspecialchars(html_entity_decode((string)$r2['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $linkRel = htmlspecialchars((string)$r2['link'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $bloco .= "  <li><a href='{$linkRel}'>{$titRel}</a></li>\n";
            }
            $bloco .= "</ul>\n</aside>\n";
            $p2 = $wp->getPost($postId);
            $wp->atualizarPost($postId, ['content' => ($p2['content']['raw'] ?? $contentFinal) . $bloco]);
            echo "   Relacionados: " . min(4, count($rel)) . "\n";
        }
    } catch (Throwable $e) {}

    $resultados[] = "  {$info['slug_site']} #trend{$info['trend_id']} → post #{$postId} ({$link})";
}

echo "\n══════ RESUMO BATCH ══════\n";
foreach ($resultados as $l) echo $l . "\n";
echo "\nProximos passos:\n";
echo "1. Revisar cada draft no WP admin\n";
echo "2. Publicar (botão azul)\n";
echo "3. Trends DB remoto: UPDATE manualmente quando quiser rastrear no portal:\n";
echo "   UPDATE trends SET status='publicado', post_id=X, url_post='Y' WHERE id IN (17709, 16945, 20288);\n";
