<?php
declare(strict_types=1);
/**
 * Mega-batch 6 posts cursosenac: Cluster C (Senac PSG 3 posts) + Cluster D (Aprenda Mais MEC 3 posts).
 * Hub + 2 sub-posts cada. Manifesto-compliant.
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';
$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

// ════════════════════════════════════════════════════════════════════
// CLUSTER C — Senac cursos gratuitos (PSG)
// ════════════════════════════════════════════════════════════════════

$pC1 = [
    'titulo' => 'Senac cursos gratuitos 2026: tudo sobre o Programa Senac de Gratuidade (PSG) e como participar',
    'slug'   => 'senac-cursos-gratuitos-2026-programa-psg-como-participar-criterios',
    'metaDesc' => 'Programa Senac de Gratuidade (PSG) em 2026: critérios de renda, cursos elegíveis, calendário de editais, modalidades e processo completo de inscrição em cursos profissionalizantes gratuitos.',
    'focusKw' => 'senac cursos gratuitos psg',
    'ogUrl' => 'https://mg.senac.br/documents/20117/34889/bh-venda-nova.jpg/bf453318-8da6-7873-e302-984ca7c7772e?version=1.0&t=1773757860515',
];
$pC1['html'] = <<<'HTML'
<p>O <strong>Programa Senac de Gratuidade (PSG)</strong> é a principal política de inclusão educacional do Senac no Brasil. Em 2026, o programa destina anualmente parte significativa das vagas de cursos profissionalizantes a candidatos de baixa renda, com cobertura 100% gratuita — sem matrícula, mensalidade, material didático ou taxa de certificação para o aluno aprovado.</p>

<p>Criado em 2008 por compromisso firmado entre o Senac, a Confederação Nacional do Comércio (CNC), o Ministério Público do Trabalho e o Ministério da Educação, o PSG hoje é regulamentado pelo Acordo de Gratuidade renovado em 2014. O Senac é obrigado a destinar pelo menos 2/3 da receita líquida da contribuição compulsória do Sistema S à educação gratuita.</p>

<p>O guia abaixo cobre as regras completas do PSG em 2026: critérios socioeconômicos, cursos elegíveis, calendário de editais por estado, modalidades disponíveis (presencial e EAD), processo de inscrição e o que esperar após a aprovação.</p>

<h2>O que é o Programa Senac de Gratuidade</h2>

<p>O PSG é a oferta de cursos do Senac com custo zero para o aluno aprovado, financiada pelas contribuições compulsórias da indústria, comércio e serviços via Sistema S. O programa atende:</p>

<ul>
  <li>Pessoas físicas com renda familiar bruta mensal per capita igual ou inferior a 2 salários mínimos;</li>
  <li>Trabalhadores autônomos em situação de vulnerabilidade socioeconômica;</li>
  <li>Beneficiários de programas sociais do governo federal (Bolsa Família, Auxílio Brasil, BPC);</li>
  <li>Estudantes e jovens em primeiro emprego (15-24 anos);</li>
  <li>Pessoas com deficiência (PcD), com vagas reservadas;</li>
  <li>Trabalhadores do comércio e serviços vinculados a empresas filiadas ao Sistema CNC.</li>
</ul>

<h2>Critérios socioeconômicos do PSG em 2026</h2>

<p>O critério principal é a renda familiar bruta mensal per capita. Com salário mínimo nacional de R$ 1.621 em 2026, o limite per capita é de R$ 3.242 (2 SM por pessoa da família). A composição familiar considera todos os moradores do domicílio, incluindo dependentes.</p>

<p>Documentação aceita como comprovação de renda:</p>

<ul>
  <li><strong>Contracheques recentes</strong> (3 meses) de todos os membros da família com renda formal;</li>
  <li><strong>Declaração de Imposto de Renda</strong> ou declaração de isenção;</li>
  <li><strong>Comprovante de benefícios sociais</strong> (Bolsa Família, Auxílio Brasil, BPC, Auxílio Doença);</li>
  <li><strong>Declaração de autônomo</strong> em formulário próprio do Senac, para trabalhadores informais;</li>
  <li><strong>Comprovante de inscrição no CadÚnico</strong> (quando aplicável);</li>
  <li><strong>Declaração negativa de renda</strong> assinada pelo candidato, em caso de desemprego sem outros membros com renda na família.</li>
</ul>

<h2>Quais cursos têm vagas PSG em 2026</h2>

<p>O catálogo PSG varia por estado, mas as áreas com oferta nacional consistente são:</p>

<ul>
  <li><strong>Beleza e estética:</strong> manicure, cabeleireiro, design de sobrancelhas, depilação;</li>
  <li><strong>Gastronomia:</strong> cozinheiro, confeitaria, panificação, garçom;</li>
  <li><strong>Saúde e bem-estar:</strong> cuidador de idosos, cuidador infantil, auxiliar de farmácia, recepcionista de consultório;</li>
  <li><strong>Tecnologia da informação:</strong> operador de computador, atendente de tecnologia, web designer;</li>
  <li><strong>Gestão e administração:</strong> auxiliar administrativo, vendedor, atendente de comércio, telemarketing;</li>
  <li><strong>Moda e vestuário:</strong> corte e costura, modelista;</li>
  <li><strong>Idiomas:</strong> inglês básico e intermediário;</li>
  <li><strong>Hospitalidade:</strong> camareira, recepcionista de hotel, eventos básicos.</li>
</ul>

<h2>Modalidades do PSG: presencial e EAD</h2>

<p>O PSG é majoritariamente presencial, com aulas em unidades do Senac estadual. Algumas modalidades também estão disponíveis em EAD:</p>

<ul>
  <li><strong>PSG presencial:</strong> a maior parte do catálogo. Aulas semanais (2-3 vezes por semana) em unidade Senac, com prática obrigatória em laboratório;</li>
  <li><strong>PSG EAD:</strong> oferta menor, em cursos com baixa exigência prática (gestão, atendimento, idiomas). Plataforma online com tutoria assíncrona;</li>
  <li><strong>PSG misto:</strong> teoria online + prática presencial em unidade Senac. Modalidade crescente desde 2022.</li>
</ul>

<h2>Calendário de editais PSG em 2026</h2>

<p>Cada Senac estadual define seu calendário próprio. Os meses com maior abertura de editais são:</p>

<ul>
  <li><strong>Janeiro / fevereiro:</strong> início do ano letivo, com maior número de vagas;</li>
  <li><strong>Abril:</strong> turmas de segundo ciclo;</li>
  <li><strong>Julho:</strong> reposição de turmas e cursos novos;</li>
  <li><strong>Outubro:</strong> último ciclo do ano.</li>
</ul>

<p>Editais ficam abertos por 15 a 30 dias. Vale acompanhar regularmente o portal do Senac do seu estado para não perder o prazo.</p>

<h2>Passo a passo: como se inscrever no PSG em 2026</h2>

<ol>
  <li><strong>Acessar o portal do Senac estadual:</strong> ba.senac.br, sp.senac.br, rj.senac.br, mg.senac.br, etc.;</li>
  <li><strong>Localizar a seção PSG</strong> (geralmente em destaque na página inicial ou no menu "Cursos Gratuitos");</li>
  <li><strong>Conferir editais abertos</strong> e cursos disponíveis;</li>
  <li><strong>Selecionar o curso</strong> de interesse, verificando pré-requisitos (escolaridade mínima, idade) e unidade Senac ofertante;</li>
  <li><strong>Preencher o formulário online</strong> com dados pessoais, escolaridade, composição familiar e renda;</li>
  <li><strong>Anexar documentação</strong> em PDF ou imagem;</li>
  <li><strong>Confirmar inscrição</strong> e aguardar análise socioeconômica (5-15 dias);</li>
  <li><strong>Acompanhar resultado</strong> no portal do Senac;</li>
  <li><strong>Aprovação → matrícula presencial</strong> na unidade Senac, com apresentação dos documentos originais;</li>
  <li><strong>Frequentar 75% mínimo</strong> das aulas e cumprir aproveitamento exigido pelo curso.</li>
</ol>

<h2>O que muda em 2026 no PSG</h2>

<ul>
  <li><strong>Inclusão de cursos de tecnologia:</strong> análise de dados, programação básica, atendimento via IA, marketing digital — áreas com forte demanda de mercado;</li>
  <li><strong>Expansão de cursos EAD:</strong> mais cursos de gestão e atendimento disponíveis em modalidade 100% online;</li>
  <li><strong>Vagas reservadas para PcD:</strong> reforço da política de inclusão de pessoas com deficiência;</li>
  <li><strong>Integração com CadÚnico:</strong> em alguns estados, a inscrição automática passou a usar dados do CadÚnico para acelerar análise.</li>
</ul>

<details class='faq-discover'>
<summary><strong>Quem pode fazer curso no PSG em 2026?</strong></summary>
<p>Pessoas físicas com renda familiar bruta mensal per capita até 2 salários mínimos (cerca de R$ 3.242 por pessoa em 2026), com idade e escolaridade compatíveis com o curso desejado. Beneficiários de programas sociais (Bolsa Família, Auxílio Brasil, BPC) e pessoas com deficiência têm vagas prioritárias.</p>
</details>

<details class='faq-discover'>
<summary><strong>O PSG do Senac é totalmente gratuito?</strong></summary>
<p>Sim. Para o candidato aprovado, o curso é 100% gratuito — sem matrícula, mensalidade, material didático, uniforme ou taxa de certificação. O financiamento vem das contribuições compulsórias da indústria, comércio e serviços via Sistema S.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quando abrem inscrições PSG em 2026?</strong></summary>
<p>Cada Senac estadual define seu calendário próprio, mas os meses com maior abertura de editais são janeiro/fevereiro (início do ano), abril (segundo ciclo), julho (reposição) e outubro (último ciclo). Editais ficam abertos por 15 a 30 dias.</p>
</details>

<details class='faq-discover'>
<summary><strong>O Senac cobra de quem é aprovado no PSG?</strong></summary>
<p>Não. O aprovado no PSG não paga matrícula, mensalidade, material didático ou taxa de certificação. Cobranças por essas rubricas em pretexto do PSG são irregulares e devem ser reportadas à Ouvidoria do Senac.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso fazer mais de um curso PSG no mesmo ano?</strong></summary>
<p>Cada edital define suas regras. Em geral, o candidato pode se inscrever em até 2 cursos no mesmo edital, indicando ordem de preferência. Após concluir um curso PSG, há geralmente prazo de carência de 6-12 meses antes de poder se inscrever em novo curso no mesmo estado.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

$pC2 = [
    'titulo' => 'Como conseguir vaga no PSG Senac em 2026: estratégia, documentação e cronograma de inscrição',
    'slug'   => 'como-conseguir-vaga-psg-senac-2026-estrategia-documentacao-cronograma',
    'metaDesc' => 'Como aumentar as chances de conseguir vaga no PSG Senac em 2026: estratégia de inscrição, documentação completa, prazos por estado, perfis prioritários e o que fazer se não passar.',
    'focusKw' => 'como conseguir vaga psg senac',
    'ogUrl' => 'https://sistemas.educacao.ma.gov.br/prematricula2026/img/banner_sistema2026.png',
];
$pC2['html'] = <<<'HTML'
<p><strong>Conseguir vaga no PSG do Senac em 2026</strong> exige preparação e estratégia. Em estados com forte demanda (São Paulo, Rio, Bahia, Minas Gerais), a concorrência é alta — algumas turmas chegam a receber 10-30 candidatos por vaga. Ter a documentação correta, escolher o momento certo do edital e entender os critérios de prioridade aumenta significativamente as chances de aprovação.</p>

<p>O PSG não usa critério único de seleção. Cada edital combina: análise socioeconômica (renda familiar), perfis prioritários (PcD, jovens em primeiro emprego, beneficiários de programas sociais), ordem de inscrição em alguns casos e sorteio quando há empate. Conhecer essa estrutura permite que o candidato prepare a inscrição com mais cuidado.</p>

<p>O guia abaixo cobre a estratégia completa: como organizar documentação, como escolher o curso certo para maximizar chances, perfis prioritários por estado, o que fazer se não passar e como recorrer em caso de rejeição na análise.</p>

<h2>Os 4 fatores que determinam aprovação no PSG</h2>

<ul>
  <li><strong>Renda familiar bruta mensal per capita:</strong> critério obrigatório. Limite de 2 salários mínimos por pessoa da família (R$ 3.242 em 2026);</li>
  <li><strong>Perfil prioritário:</strong> beneficiários de programas sociais (Bolsa Família, Auxílio Brasil, BPC), PcD, jovens em primeiro emprego, mulheres chefes de família;</li>
  <li><strong>Ordem de inscrição:</strong> em editais com vagas remanescentes, prevalece a ordem cronológica de cadastro;</li>
  <li><strong>Sorteio:</strong> quando há mais candidatos qualificados que vagas, alguns editais usam sorteio eletrônico como critério final.</li>
</ul>

<h2>Documentação completa do PSG (sem pendências)</h2>

<p>A reprovação mais comum é por documentação incompleta ou inconsistente. A lista completa:</p>

<ul>
  <li><strong>RG do candidato</strong> (frente e verso, legível);</li>
  <li><strong>CPF do candidato</strong> (cartão ou consulta CPF da Receita Federal);</li>
  <li><strong>Comprovante de residência atualizado</strong> em até 3 meses (conta de água, luz, gás, telefone, internet ou correspondência bancária);</li>
  <li><strong>Comprovante de escolaridade</strong>:
    <ul>
      <li>Histórico do ensino médio (concluído ou em curso);</li>
      <li>Declaração de matrícula da escola atual;</li>
      <li>Diploma (quando aplicável);</li>
    </ul>
  </li>
  <li><strong>Comprovantes de renda de TODOS os membros da família</strong>:
    <ul>
      <li>Contracheques dos últimos 3 meses (trabalhadores formais);</li>
      <li>Declaração de IR ou declaração de isenção;</li>
      <li>Declaração de autônomo no formulário próprio do Senac (informal);</li>
      <li>Comprovante de benefícios sociais;</li>
      <li>Declaração negativa de renda assinada (desempregados);</li>
    </ul>
  </li>
  <li><strong>Comprovante de inscrição no CadÚnico</strong> (acelera análise quando aplicável);</li>
  <li><strong>Laudo médico</strong> para vagas PcD (pessoa com deficiência);</li>
  <li><strong>Foto 3x4 recente</strong> (algumas unidades pedem);</li>
  <li><strong>Carteira de Trabalho Digital</strong> (alguns estados, para confirmar status profissional).</li>
</ul>

<h2>Estratégia: como aumentar as chances de aprovação</h2>

<ol>
  <li><strong>Identificar perfil prioritário:</strong> se você se enquadra em PcD, beneficiário Bolsa Família, jovem 15-24 anos em primeiro emprego ou mulher chefe de família, mencionar explicitamente na inscrição e comprovar com documentação. Aumenta significativamente as chances;</li>
  <li><strong>Inscrever cedo:</strong> em editais com critério "ordem de inscrição", os primeiros têm vantagem. Tenha documentação pronta antes do edital abrir e inscreva-se nas primeiras 24 horas;</li>
  <li><strong>Escolher curso com menor demanda:</strong> cursos noturnos, cursos em unidades Senac mais afastadas do centro e cursos de áreas técnicas menos populares têm menos concorrência. Cursos "da moda" (gastronomia, beleza, design) costumam ter mais inscritos;</li>
  <li><strong>Aproveitar cursos novos:</strong> Senac periodicamente abre cursos em novas áreas (tecnologia, IA aplicada, sustentabilidade). Esses cursos têm menos histórico de demanda e mais chance de vaga;</li>
  <li><strong>Documentação consistente:</strong> renda declarada deve bater com comprovantes. Inconsistências geram rejeição automática na análise;</li>
  <li><strong>Acompanhar resultado:</strong> alguns aprovados não comparecem à matrícula. Vagas remanescentes são preenchidas em ordem da lista de espera. Manter cadastro atualizado e telefone disponível.</li>
</ol>

<h2>Perfis prioritários por estado</h2>

<ul>
  <li><strong>São Paulo:</strong> reserva forte para jovens em primeiro emprego e PcD;</li>
  <li><strong>Rio de Janeiro:</strong> prioridade para moradores de comunidades em vulnerabilidade social;</li>
  <li><strong>Bahia:</strong> reserva para mulheres chefes de família e quilombolas;</li>
  <li><strong>Minas Gerais:</strong> prioridade para trabalhadores rurais e desempregados de longa duração;</li>
  <li><strong>Norte e Nordeste:</strong> alguns estados reservam vagas para população indígena.</li>
</ul>

<h2>O que fazer se não passar no PSG</h2>

<ul>
  <li><strong>Recorrer da análise:</strong> a maioria dos editais permite recurso em 3-5 dias úteis após divulgação do resultado, com justificativa e novos documentos. Vale tentar quando a rejeição foi por documento mal interpretado;</li>
  <li><strong>Aplicar em outro edital:</strong> editais novos abrem nos meses seguintes. Documentação preparada agiliza inscrição;</li>
  <li><strong>Aplicar em outra unidade Senac:</strong> demanda varia muito entre unidades dentro do mesmo estado. Cursos em unidades menos centrais costumam ter menos concorrência;</li>
  <li><strong>Alternativas equivalentes:</strong> Pronatec (governo federal), cursos prefeitura, ONGs com cursos profissionalizantes, ou cursos pagos do próprio Senac com parcelamento.</li>
</ul>

<h2>Erros comuns na inscrição PSG</h2>

<ul>
  <li><strong>Documentação fora do prazo:</strong> comprovante de residência com mais de 3 meses é rejeitado;</li>
  <li><strong>Renda inconsistente:</strong> declarar valor diferente do que aparece nos contracheques;</li>
  <li><strong>Omitir membro da família:</strong> família declarada com 3 pessoas mas comprovantes mostram outros membros gera análise negativa;</li>
  <li><strong>Inscrição em curso incompatível:</strong> inscrever-se em curso técnico sem ter ensino médio em curso (depende do curso);</li>
  <li><strong>Esquecer comprovante de inscrição:</strong> em alguns estados, é necessário levar protocolo de inscrição impresso à entrevista presencial;</li>
  <li><strong>Não acompanhar resultado:</strong> aprovados que não comparecem à matrícula em prazo curto perdem a vaga.</li>
</ul>

<details class='faq-discover'>
<summary><strong>Qual a chance real de conseguir vaga no PSG do Senac?</strong></summary>
<p>Varia muito por estado, curso e edital. Cursos populares (gastronomia, beleza, design) em capitais grandes têm 10-30 candidatos por vaga. Cursos técnicos em áreas menos populares ou em unidades afastadas do centro têm concorrência muito menor, com aprovação quase certa para quem tem perfil socioeconômico adequado.</p>
</details>

<details class='faq-discover'>
<summary><strong>Tem como saber antes se vou ser aprovado?</strong></summary>
<p>Não com 100% de certeza, mas há sinais claros: se a renda familiar bruta per capita está abaixo de 1 salário mínimo (priorizado), se a família é beneficiária de Bolsa Família ou Auxílio Brasil, se é PcD ou jovem em primeiro emprego, as chances são significativamente maiores.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso recorrer se for reprovado na análise PSG?</strong></summary>
<p>Sim. A maioria dos editais permite recurso em 3-5 dias úteis após divulgação do resultado, com justificativa e novos documentos. Vale tentar quando a rejeição foi por documento mal interpretado ou inconsistência facilmente esclarecida.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto tempo demora a análise do PSG?</strong></summary>
<p>Em média, 5 a 15 dias após o encerramento do edital. Em estados com integração ao CadÚnico, a análise pode sair em 3-5 dias. Em alguns editais com muitas inscrições, o prazo pode esticar para 20-30 dias.</p>
</details>

<details class='faq-discover'>
<summary><strong>Se eu for aprovado mas não conseguir frequentar, perco a vaga?</strong></summary>
<p>Sim. Aprovação não significa direito perpétuo. O aluno tem que cumprir 75% de frequência mínima e aproveitamento exigido pelo curso. Faltas excessivas ou trancamento sem motivo justificado causam perda da vaga e podem gerar carência de 12 meses antes de poder se inscrever em outro PSG.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

$pC3 = [
    'titulo' => 'Cursos gratuitos do Senac em Salvador, São Paulo, Rio e Belo Horizonte em 2026: editais e calendário',
    'slug'   => 'cursos-gratuitos-senac-salvador-sao-paulo-rio-belo-horizonte-2026-editais-calendario',
    'metaDesc' => 'Cursos gratuitos do Senac nas principais capitais brasileiras em 2026: Salvador, São Paulo, Rio de Janeiro e Belo Horizonte. Áreas mais ofertadas, calendário de editais e como se inscrever em cada estado.',
    'focusKw' => 'curso gratuito senac salvador',
    'ogUrl' => 'https://mg.senac.br/documents/20117/34889/bh-venda-nova.jpg/bf453318-8da6-7873-e302-984ca7c7772e?version=1.0&t=1773757860515',
];
$pC3['html'] = <<<'HTML'
<p>Os <strong>cursos gratuitos do Senac</strong> têm oferta concentrada nas capitais brasileiras, com calendário e catálogo variando por estado. Salvador (Bahia), São Paulo, Rio de Janeiro e Belo Horizonte são as 4 cidades com maior volume de vagas PSG (Programa Senac de Gratuidade) em 2026, atendendo perfis distintos com cursos em áreas locais de maior demanda.</p>

<p>Cada Senac estadual opera com autonomia para definir editais, prioridades e cursos disponíveis. O candidato precisa acessar o portal do Senac do seu estado para conferir oferta atual.</p>

<p>O guia abaixo cobre o panorama dos cursos gratuitos do Senac nas 4 principais capitais em 2026: áreas mais ofertadas, unidades com maior catálogo, prioridades locais e como se inscrever em cada uma.</p>

<h2>Senac Bahia: cursos gratuitos em Salvador e interior</h2>

<p>O Senac Bahia opera com sede em Salvador e unidades em Feira de Santana, Vitória da Conquista, Ilhéus, Juazeiro, Itabuna e outras 15+ cidades baianas. O catálogo PSG é amplo, com forte presença em:</p>

<ul>
  <li><strong>Beleza:</strong> manicure, cabeleireiro, design de sobrancelhas (forte tradição no estado);</li>
  <li><strong>Gastronomia:</strong> cozinheiro, confeitaria, panificação, com algumas turmas voltadas para cozinha baiana regional;</li>
  <li><strong>Hospitalidade:</strong> camareira, recepcionista de hotel, garçom — atendendo o setor turístico de Salvador e do litoral baiano;</li>
  <li><strong>Saúde:</strong> cuidador de idosos, auxiliar de farmácia, técnico em enfermagem;</li>
  <li><strong>Idiomas:</strong> inglês (especialmente para mercado turístico), espanhol básico.</li>
</ul>

<p>Editais PSG abertos periodicamente no portal ba.senac.br. Prioridades locais incluem mulheres chefes de família, comunidades quilombolas e moradores de bairros em vulnerabilidade social. A capital Salvador concentra a maior oferta, com unidades no Comércio, Pelourinho e bairros da Pituba e Barra.</p>

<h2>Senac São Paulo: a maior oferta nacional do PSG</h2>

<p>O Senac São Paulo opera mais de 60 unidades pelo estado, com a maior oferta PSG do país. Sede em São Paulo capital, com unidades estratégicas em Lapa, Tutoia, Vila Mariana, Aclimação e em todas as principais cidades do interior (Campinas, Sorocaba, Ribeirão Preto, Bauru, etc.).</p>

<ul>
  <li><strong>Gastronomia:</strong> referência nacional. Unidades como Senac Aclimação e Senac Águas de São Pedro têm formação reconhecida internacionalmente;</li>
  <li><strong>Tecnologia da informação:</strong> oferta forte de operador de computador, web designer, programador básico, atendente de tecnologia;</li>
  <li><strong>Gestão e administração:</strong> auxiliar administrativo, vendedor, atendente de comércio, telemarketing;</li>
  <li><strong>Saúde:</strong> cuidador de idosos com forte demanda; técnico em enfermagem com formação extensa;</li>
  <li><strong>Moda:</strong> corte e costura, modelista, vestuário industrial (Senac Moda em São Paulo);</li>
  <li><strong>Idiomas:</strong> inglês, espanhol, italiano, francês, japonês — oferta diversificada por demanda local.</li>
</ul>

<p>Editais no portal sp.senac.br. Prioridades incluem jovens em primeiro emprego (15-24 anos), PcD e trabalhadores em situação de transição. O volume de vagas é grande mas a demanda também — concorrência significativa em cursos populares.</p>

<h2>Senac Rio de Janeiro: cursos gratuitos na cidade e Baixada</h2>

<p>O Senac Rio opera unidades na capital (Centro, Copacabana, Madureira, Tijuca, Barra) e em São Gonçalo, Niterói, Nova Iguaçu, Duque de Caxias e Petrópolis. Catálogo PSG forte em:</p>

<ul>
  <li><strong>Gastronomia:</strong> tradição forte, com algumas unidades especializadas (Senac Tijuca em gastronomia);</li>
  <li><strong>Beleza:</strong> manicure, cabeleireiro, design de sobrancelhas, depilação;</li>
  <li><strong>Hospitalidade:</strong> camareira, recepcionista de hotel — atendendo o setor turístico carioca;</li>
  <li><strong>Saúde:</strong> cuidador de idosos com forte oferta na cidade pela demanda envelhecida da Zona Sul;</li>
  <li><strong>Eventos:</strong> produção de eventos com foco no mercado de festas, casamentos e produção cultural carioca.</li>
</ul>

<p>Editais no portal rj.senac.br. Prioridades incluem moradores de comunidades em vulnerabilidade social. Unidades em São Gonçalo e Baixada Fluminense costumam ter menos concorrência que as da Zona Sul carioca.</p>

<h2>Senac Minas Gerais: forte presença em Belo Horizonte e interior</h2>

<p>O Senac Minas opera mais de 30 unidades pelo estado, com sede em Belo Horizonte e unidades nas principais cidades (Juiz de Fora, Uberlândia, Uberaba, Montes Claros, Sete Lagoas, Contagem, Betim). O PSG é robusto em:</p>

<ul>
  <li><strong>Saúde:</strong> cuidador de idosos, auxiliar de farmácia, técnico em enfermagem;</li>
  <li><strong>Gastronomia:</strong> com foco em cozinha mineira regional + cozinha brasileira;</li>
  <li><strong>Tecnologia da informação:</strong> crescimento forte nas turmas de programação básica e atendimento técnico;</li>
  <li><strong>Beleza:</strong> oferta consistente em todas as unidades;</li>
  <li><strong>Setor sucroenergético e mineração:</strong> alguns cursos específicos para mercado industrial mineiro (operador de equipamentos, segurança industrial).</li>
</ul>

<p>Editais no portal mg.senac.br. Prioridades incluem trabalhadores rurais (cidades do interior), desempregados de longa duração e jovens em primeiro emprego.</p>

<h2>Como identificar o Senac do seu estado e calendário</h2>

<p>Para os demais estados brasileiros, o portal nacional do Senac (senac.br) tem mapa interativo com link direto para cada Senac estadual:</p>

<ul>
  <li>Senac Acre — ac.senac.br;</li>
  <li>Senac Alagoas — al.senac.br;</li>
  <li>Senac Amazonas — am.senac.br;</li>
  <li>Senac Ceará — ce.senac.br;</li>
  <li>Senac Distrito Federal — df.senac.br;</li>
  <li>Senac Espírito Santo — es.senac.br;</li>
  <li>Senac Goiás — go.senac.br;</li>
  <li>Senac Mato Grosso — mt.senac.br;</li>
  <li>Senac Mato Grosso do Sul — ms.senac.br;</li>
  <li>Senac Paraná — pr.senac.br;</li>
  <li>Senac Pernambuco — pe.senac.br;</li>
  <li>Senac Rio Grande do Sul — rs.senac.br;</li>
  <li>Senac Santa Catarina — sc.senac.br;</li>
  <li>Demais estados — busca por "senac.[sigla].br" geralmente leva ao portal correto.</li>
</ul>

<h2>Calendário típico do PSG em 2026</h2>

<p>Padrão nacional de editais (com variações por estado):</p>

<ul>
  <li><strong>Janeiro / fevereiro:</strong> abertura de turmas do primeiro semestre;</li>
  <li><strong>Abril:</strong> segundo ciclo do primeiro semestre;</li>
  <li><strong>Julho:</strong> abertura do segundo semestre;</li>
  <li><strong>Outubro:</strong> último ciclo do ano.</li>
</ul>

<p>Editais ficam abertos por 15-30 dias. Inscrição online no portal estadual. Análise leva 5-15 dias. Início das aulas costuma ocorrer 15-30 dias após divulgação do resultado.</p>

<details class='faq-discover'>
<summary><strong>O Senac Bahia tem curso gratuito em Salvador 2026?</strong></summary>
<p>Sim. O Senac Bahia mantém oferta PSG significativa em Salvador e em mais de 15 cidades do interior, com cursos em beleza, gastronomia, hospitalidade, saúde e idiomas. Inscrições periódicas pelo portal ba.senac.br, com prioridade para mulheres chefes de família, comunidades quilombolas e moradores de bairros em vulnerabilidade social.</p>
</details>

<details class='faq-discover'>
<summary><strong>Onde o Senac São Paulo tem mais unidades?</strong></summary>
<p>O Senac São Paulo opera mais de 60 unidades pelo estado, com forte presença em São Paulo capital (Lapa, Tutoia, Vila Mariana, Aclimação) e nas principais cidades do interior (Campinas, Sorocaba, Ribeirão Preto, Bauru, São José dos Campos). É a maior rede Senac do país em volume de unidades.</p>
</details>

<details class='faq-discover'>
<summary><strong>O Senac Rio tem cursos gratuitos em São Gonçalo?</strong></summary>
<p>Sim. O Senac Rio mantém unidades em São Gonçalo, Niterói, Nova Iguaçu, Duque de Caxias e outras cidades da Baixada Fluminense. Cursos PSG são ofertados com calendário regular. Unidades fora da Zona Sul carioca costumam ter menos concorrência que as do Centro e Zona Sul.</p>
</details>

<details class='faq-discover'>
<summary><strong>Em qual estado é mais fácil conseguir vaga PSG?</strong></summary>
<p>Estados com menor concentração urbana (Norte, Centro-Oeste) costumam ter menos concorrência por vaga PSG em cursos populares. Já capitais grandes (São Paulo, Rio, Belo Horizonte) têm mais oferta absoluta mas também mais demanda. Para maximizar chance, considerar unidades Senac em cidades médias ou bairros menos centrais.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto tempo a aula gratuita do Senac dura por semana?</strong></summary>
<p>Varia conforme o curso. Cursos de qualificação profissional (160-400 horas) costumam ter 8-16 horas semanais distribuídas em 2-3 dias. Cursos técnicos completos (1.200h+) têm 16-24 horas semanais em 4-5 dias, com duração total de 2 anos. Cursos curtos podem ser concentrados em 1 semana intensiva.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// CLUSTER D — Aprenda Mais MEC
// ════════════════════════════════════════════════════════════════════

$pD1 = [
    'titulo' => 'Aprenda Mais MEC em 2026: portal oficial com cursos EAD gratuitos dos Institutos Federais',
    'slug'   => 'aprenda-mais-mec-2026-portal-oficial-cursos-ead-gratuitos-institutos-federais',
    'metaDesc' => 'O que é o Aprenda Mais MEC em 2026: portal nacional da Rede Federal de Institutos Federais com cursos EAD gratuitos. Áreas, certificado federal, IFs participantes e como se inscrever.',
    'focusKw' => 'aprenda mais mec',
    'ogUrl' => 'https://to.catolica.edu.br/portal/wp-content/uploads/2021/02/euiiy2pxsaag_xc.png',
];
$pD1['html'] = <<<'HTML'
<p>O <strong>Aprenda Mais MEC</strong> é o portal nacional da <strong>Rede Federal de Educação Profissional, Científica e Tecnológica</strong> que reúne cursos EAD gratuitos dos 38 Institutos Federais (IFs) brasileiros. Em 2026, mais de 5.000 cursos estão disponíveis no catálogo, abrangendo desde cursos livres curtos (40 horas) até cursos técnicos completos de nível médio. Todos são gratuitos, com certificado emitido pelo IF ofertante e reconhecimento federal.</p>

<p>A plataforma é mantida pelo Ministério da Educação (MEC), em parceria com os IFs, dentro da estrutura da Rede Federal criada pela Lei 11.892/2008. O portal nacional centraliza o catálogo, mas cada IF é responsável pela operação técnica do seu curso — ambiente Moodle, tutoria, avaliação e emissão do certificado.</p>

<p>O guia abaixo cobre o que é o Aprenda Mais, como funciona o portal, os IFs com maior oferta, as áreas com catálogo mais robusto, o passo a passo de cadastro e as vantagens do certificado federal sobre alternativas privadas ou do Sistema S.</p>

<h2>O que é a Rede Federal e por que ela importa</h2>

<p>A Rede Federal de Educação Profissional, Científica e Tecnológica reúne:</p>

<ul>
  <li><strong>38 Institutos Federais (IFs):</strong> autarquias federais com autonomia administrativa, distribuídas em todos os 26 estados e no Distrito Federal;</li>
  <li><strong>2 Centros Federais de Educação Tecnológica (CEFETs):</strong> CEFET-RJ e CEFET-MG;</li>
  <li><strong>1 Universidade Tecnológica Federal (UTFPR):</strong> com sede no Paraná e campus em vários municípios;</li>
  <li><strong>1 Escola Técnica vinculada à UFRJ:</strong> Colégio Pedro II e outros vinculados.</li>
</ul>

<p>A Rede Federal foi criada pela Lei 11.892/2008 e tem como missão ofertar educação profissional gratuita em todos os níveis (do curso livre ao mestrado), priorizando cursos técnicos e tecnológicos com forte relação com o mundo produtivo.</p>

<p>Quando um IF oferta um curso pelo Aprenda Mais, o certificado:</p>

<ul>
  <li>É emitido por autarquia federal vinculada ao MEC;</li>
  <li>Tem validade nacional sem restrição geográfica;</li>
  <li>Conta como título em concursos públicos federais, estaduais e municipais;</li>
  <li>Pode ser usado para progressão de carreira no serviço público;</li>
  <li>É aceito como qualificação profissional em processos seletivos privados.</li>
</ul>

<h2>Estrutura do portal Aprenda Mais</h2>

<ul>
  <li><strong>Catálogo nacional:</strong> mais de 5.000 cursos somando todos os IFs, organizado por área, IF ofertante e modalidade;</li>
  <li><strong>Filtros de busca:</strong> por palavra-chave, área (informática, gestão, agropecuária, etc.), IF ofertante, modalidade (livre, FIC, técnico);</li>
  <li><strong>Cadastro único:</strong> uma única conta serve para todos os cursos de todos os IFs participantes (em fase de implementação completa);</li>
  <li><strong>Plataforma operacional descentralizada:</strong> cada IF mantém seu Moodle ou ambiente próprio, com login federado ou cadastro local.</li>
</ul>

<h2>Os 38 Institutos Federais participantes</h2>

<p>Por região:</p>

<ul>
  <li><strong>Sudeste:</strong> IFSP (maior IF do país), IFMG, IFES, IFRJ, IFF;</li>
  <li><strong>Sul:</strong> IFRS, IFSC, IFSul, IFC, IFFar, IFPR;</li>
  <li><strong>Nordeste:</strong> IFBA, IFCE, IFPB, IFRN, IFPE, IFAL, IFS, IFMA, IFPI, IF Baiano, IF Sertão-PE;</li>
  <li><strong>Norte:</strong> IFAM, IFPA, IFRO, IFRR, IFTO, IFAC, IFAP;</li>
  <li><strong>Centro-Oeste:</strong> IFG, IFGoiano, IFMT, IFMS, IFB.</li>
</ul>

<h2>Áreas com maior catálogo no Aprenda Mais 2026</h2>

<ul>
  <li><strong>Tecnologia da informação:</strong> programação, banco de dados, redes, segurança da informação, programação para internet, manutenção de computadores;</li>
  <li><strong>Gestão e administração:</strong> técnico em administração, auxiliar administrativo, gestão de estoque, atendimento ao cliente;</li>
  <li><strong>Agropecuária:</strong> técnico agropecuário, agroindústria, técnico em alimentos, agricultura familiar (forte presença em IFs do Norte, Nordeste e Centro-Oeste);</li>
  <li><strong>Indústria:</strong> técnico em segurança do trabalho, manutenção industrial, eletrotécnica, mecânica básica;</li>
  <li><strong>Educação:</strong> formação continuada para professores, prática pedagógica, educação inclusiva, EAD;</li>
  <li><strong>Saúde:</strong> primeiros socorros, atendente de saúde, biossegurança;</li>
  <li><strong>Idiomas:</strong> inglês instrumental, espanhol básico, libras;</li>
  <li><strong>Empreendedorismo:</strong> plano de negócios, marketing digital, gestão financeira para pequenos negócios;</li>
  <li><strong>Sustentabilidade e meio ambiente:</strong> gestão ambiental, ESG, energias renováveis básicas.</li>
</ul>

<h2>Tipos de curso disponíveis</h2>

<ul>
  <li><strong>Cursos livres (40-60h):</strong> formação rápida, sem pré-requisito formal de escolaridade. Certificado de extensão;</li>
  <li><strong>Formação Inicial e Continuada (FIC, 160-240h):</strong> qualificação profissional de média duração. Certificado profissional;</li>
  <li><strong>Cursos técnicos completos (1.200-2.400h):</strong> formação técnica de nível médio em 2 anos. Diploma de Técnico em Nível Médio com validade federal.</li>
</ul>

<h2>Passo a passo: como se inscrever no Aprenda Mais</h2>

<ol>
  <li>Acessar o portal Aprenda Mais (aprendamais.ifsp.edu.br/cursoslivres ou pelo portal central da Rede Federal);</li>
  <li>Navegar pelo catálogo, usando filtro por área, modalidade e IF;</li>
  <li>Selecionar o curso desejado, verificar pré-requisitos (escolaridade mínima, idade) e período de oferta;</li>
  <li>Clicar em Inscrever-se. Preencher cadastro com dados pessoais, CPF, e-mail e escolaridade;</li>
  <li>Aguardar confirmação da matrícula (cursos livres geralmente confirmam imediatamente; FIC e técnicos podem ter processo seletivo);</li>
  <li>Acessar a plataforma do IF responsável pelo curso (Moodle ou ambiente próprio);</li>
  <li>Cumprir as atividades, fóruns, avaliações e exigência de presença mínima (75%);</li>
  <li>Após aprovação, baixar o certificado em PDF com QR Code de validação online.</li>
</ol>

<h2>Diferenças entre o Aprenda Mais e outras plataformas</h2>

<ul>
  <li><strong>Reconhecimento federal:</strong> certificado emitido por IF federal supera Senac/SENAI em concursos públicos federais e estaduais;</li>
  <li><strong>Custo zero real:</strong> sem critério de renda, aberto a qualquer brasileiro;</li>
  <li><strong>Catálogo amplo:</strong> mais de 5.000 cursos somando todos os IFs;</li>
  <li><strong>Modalidade técnica completa:</strong> únicos a ofertar formação técnica completa de 2 anos gratuita 100% online (em alguns estados);</li>
  <li><strong>Pré-requisito flexível:</strong> cursos livres aceitos por qualquer pessoa; FIC exigem ensino fundamental completo; técnicos pedem ensino médio em curso.</li>
</ul>

<details class='faq-discover'>
<summary><strong>O Aprenda Mais MEC é gratuito?</strong></summary>
<p>Sim, 100% gratuito. Não há cobrança de matrícula, mensalidade, material didático ou certificação. O portal e os cursos são mantidos pelos Institutos Federais como parte da missão de educação profissional gratuita prevista na Lei 11.892/2008.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quantos cursos o Aprenda Mais oferece em 2026?</strong></summary>
<p>Mais de 5.000 cursos somando o catálogo de todos os 38 Institutos Federais e CEFETs vinculados à plataforma.</p>
</details>

<details class='faq-discover'>
<summary><strong>O certificado do Aprenda Mais vale para o INSS ou progressão de carreira?</strong></summary>
<p>Sim. O certificado é emitido por autarquia federal (IF) e tem validade nacional. Conta como título em concursos públicos federais, estaduais e municipais, para progressão de carreira no serviço público com plano de cargos, e como qualificação profissional em processos seletivos privados.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual a diferença entre cursos livres, FIC e técnicos no Aprenda Mais?</strong></summary>
<p>Cursos livres (40-60h) têm certificado de extensão, sem pré-requisito formal. FIC (160-240h) emite certificado de qualificação profissional, requer ensino fundamental completo. Cursos técnicos (1.200h+) levam ao Diploma de Técnico em Nível Médio, exigem ensino médio em curso e duram 2 anos.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso fazer curso técnico do MEC totalmente online?</strong></summary>
<p>Sim, em parte dos IFs participantes. Cursos técnicos como Técnico em Administração, Técnico em Informática para Internet e Técnico em Logística são ofertados em formato 100% EAD em alguns estados. Outros (especialmente da área industrial) exigem componente presencial obrigatório.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

$pD2 = [
    'titulo' => 'Cursos EAD gratuitos do IFSP em 2026: catálogo, áreas e como se inscrever no Instituto Federal de São Paulo',
    'slug'   => 'cursos-ead-gratuitos-ifsp-2026-instituto-federal-sao-paulo-catalogo-areas-inscricao',
    'metaDesc' => 'Cursos EAD gratuitos do IFSP em 2026: catálogo do maior Instituto Federal do Brasil, com cursos livres, FIC e técnicos em informática, gestão, idiomas e automação. Como se inscrever.',
    'focusKw' => 'cursos ead gratuitos ifsp',
    'ogUrl' => 'https://spo.ifsp.edu.br/images/imagem_artigo/IMAGENS_ARTIGOS_FIXOS/INSTITUCIONAL/ifsp_aereo.jpg',
];
$pD2['html'] = <<<'HTML'
<p>O <strong>Instituto Federal de São Paulo (IFSP)</strong> é o maior IF do Brasil em volume de oferta educacional. Em 2026, o IFSP mantém o catálogo mais robusto de cursos EAD gratuitos da Rede Federal, com mais de 800 ofertas disponíveis via plataforma Aprenda Mais e plataformas internas próprias. Todas as ofertas são 100% gratuitas, sem critério de renda, com certificado federal de validade nacional.</p>

<p>O IFSP tem 38 campi distribuídos pelo estado de São Paulo, da capital ao interior. A oferta EAD gratuita complementa os cursos presenciais ofertados nesses campi, expandindo o alcance da instituição para qualquer brasileiro com acesso à internet. Cursos livres, qualificação profissional FIC e até alguns cursos técnicos completos estão disponíveis no formato EAD.</p>

<p>O guia abaixo cobre o catálogo do IFSP por área, os cursos mais procurados em 2026, a plataforma utilizada, o passo a passo de inscrição e o peso do certificado federal no mercado e em concursos públicos.</p>

<h2>Por que o IFSP é o maior IF do Brasil</h2>

<p>O IFSP foi criado em 2008 pela Lei 11.892, unificando a Escola Técnica Federal de São Paulo, os Centros Federais de Educação Tecnológica de São Paulo (CEFETs) e outras instituições. Em 2026:</p>

<ul>
  <li>38 campi presenciais distribuídos pelo estado de São Paulo;</li>
  <li>Mais de 70 mil alunos matriculados em cursos presenciais e EAD;</li>
  <li>Mais de 800 cursos EAD gratuitos no catálogo Aprenda Mais;</li>
  <li>Centenas de cursos técnicos, tecnólogos, bacharelados, licenciaturas, especializações e mestrados;</li>
  <li>Investimento contínuo em tecnologia educacional (Moodle institucional robusto, conteúdos audiovisuais próprios).</li>
</ul>

<h2>Áreas com maior oferta de cursos EAD no IFSP em 2026</h2>

<ul>
  <li><strong>Tecnologia da informação:</strong> programação básica (Python, Java, JavaScript), banco de dados, redes, segurança da informação, programação para internet, lógica de programação, manutenção de computadores;</li>
  <li><strong>Gestão e administração:</strong> auxiliar administrativo, gestão de estoque, atendimento ao cliente, gestão de equipes, gestão de projetos, gestão pública;</li>
  <li><strong>Automação e indústria:</strong> CLP básico, sensores, robótica educacional, manutenção industrial, lógica de controle;</li>
  <li><strong>Idiomas:</strong> inglês instrumental (níveis básico, intermediário e avançado), espanhol básico, libras;</li>
  <li><strong>Empreendedorismo:</strong> plano de negócios, marketing digital, gestão financeira para pequenos negócios, finanças pessoais;</li>
  <li><strong>Educação:</strong> formação continuada para professores, tecnologias educacionais, prática pedagógica, EAD pedagógico;</li>
  <li><strong>Eletrônica:</strong> eletrônica básica, circuitos lógicos, componentes eletrônicos, projeto de placas;</li>
  <li><strong>Edificações:</strong> leitura de planta, fundamentos de cálculo estrutural, gestão de obra básica.</li>
</ul>

<h2>Cursos EAD mais procurados no IFSP em 2026</h2>

<ul>
  <li><strong>Lógica de Programação:</strong> 40-60 horas, base para iniciantes em TI;</li>
  <li><strong>Excel Aplicado a Negócios:</strong> 30-60 horas, planilhas avançadas para gestão;</li>
  <li><strong>Python para Iniciantes:</strong> 60-80 horas, programação introdutória;</li>
  <li><strong>Inglês Instrumental:</strong> 60-120 horas, leitura técnica em inglês;</li>
  <li><strong>Empreendedorismo Digital:</strong> 40-60 horas, marketing digital, vendas online;</li>
  <li><strong>Auxiliar Administrativo:</strong> 160-200 horas, qualificação profissional;</li>
  <li><strong>Gestão de Projetos:</strong> 40-80 horas, fundamentos PMBOK e metodologias ágeis;</li>
  <li><strong>Libras (Língua Brasileira de Sinais):</strong> 60-120 horas, comunicação com pessoas surdas.</li>
</ul>

<h2>Cursos técnicos completos do IFSP em EAD</h2>

<ul>
  <li><strong>Técnico em Administração:</strong> 1.200 horas, EAD com encontros presenciais ocasionais;</li>
  <li><strong>Técnico em Informática para Internet:</strong> 1.500 horas, EAD com prática em laboratório virtual;</li>
  <li><strong>Técnico em Logística:</strong> 1.200 horas, EAD com estágio supervisionado;</li>
  <li><strong>Técnico em Gestão Pública:</strong> 1.200 horas, EAD voltado para servidores e candidatos a concurso;</li>
  <li><strong>Técnico em Serviços Públicos:</strong> 1.200 horas, voltado para atuação na esfera pública.</li>
</ul>

<p>Esses cursos exigem processo seletivo com análise de histórico escolar e/ou prova específica. Diploma de Técnico em Nível Médio reconhecido pelo MEC.</p>

<h2>Passo a passo: como se inscrever em curso EAD do IFSP</h2>

<ol>
  <li>Acessar aprendamais.ifsp.edu.br/cursoslivres (cursos livres) ou cursosfic.ifsp.edu.br (cursos FIC);</li>
  <li>Navegar pelo catálogo ou usar filtro por área e modalidade;</li>
  <li>Conferir pré-requisitos (escolaridade mínima, idade), carga horária e período de oferta;</li>
  <li>Clicar em Inscrever-se. Preencher cadastro com nome, CPF, e-mail e escolaridade;</li>
  <li>Confirmar matrícula (cursos livres geralmente confirmam imediatamente; FIC podem ter sorteio se houver mais inscritos que vagas);</li>
  <li>Receber e-mail com link de acesso à plataforma Moodle do IFSP;</li>
  <li>Iniciar o curso conforme cronograma, cumprir atividades online, participar de fóruns, fazer provas avaliativas;</li>
  <li>Após aprovação (mínimo 60-75% de aproveitamento), baixar o certificado em PDF com QR Code de validação.</li>
</ol>

<h2>Calendário e turmas do IFSP em 2026</h2>

<ul>
  <li><strong>Janeiro / fevereiro:</strong> primeiro ciclo do ano, maior volume de vagas;</li>
  <li><strong>Maio / junho:</strong> turmas do meio do ano;</li>
  <li><strong>Setembro / outubro:</strong> último ciclo do ano.</li>
</ul>

<p>Cursos livres com vagas remanescentes ficam abertos ao longo de todo o ano. Cursos FIC e técnicos têm edital específico com prazo de inscrição definido.</p>

<h2>Peso do certificado IFSP no mercado e em concursos</h2>

<ul>
  <li><strong>Concursos públicos:</strong> certificado IFSP conta como título quando o edital pontua qualificação profissional ou extensão;</li>
  <li><strong>Progressão de carreira no serviço público:</strong> contagem direta nas faixas que pontuam capacitação;</li>
  <li><strong>Empresas privadas:</strong> reconhecimento como qualificação profissional, especialmente em áreas técnicas (TI, automação, gestão);</li>
  <li><strong>Crédito em graduações:</strong> alguns cursos do IFSP dão crédito de horas em graduações tecnólogas posteriores (varia por instituição);</li>
  <li><strong>Estágio:</strong> certificado em cursos técnicos do IFSP qualifica para estágio supervisionado em empresas grandes.</li>
</ul>

<details class='faq-discover'>
<summary><strong>Os cursos EAD do IFSP são gratuitos para qualquer pessoa?</strong></summary>
<p>Sim. Não há critério de renda nem residência exigido. Qualquer brasileiro com acesso à internet pode se inscrever, desde que atenda aos pré-requisitos específicos do curso (escolaridade, idade). Os cursos são 100% gratuitos.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto tempo dura um curso EAD do IFSP?</strong></summary>
<p>Cursos livres: 40 a 80 horas (em 1-2 meses). Cursos FIC: 160 a 240 horas (3-6 meses). Cursos técnicos completos em EAD: 1.200 a 1.500 horas (2 anos). Cursos curtos costumam ser autoinstrucionais sem prazo fixo.</p>
</details>

<details class='faq-discover'>
<summary><strong>O certificado EAD do IFSP é igual ao presencial?</strong></summary>
<p>Sim. O certificado EAD do IFSP tem o mesmo valor legal do certificado presencial — emitido pela mesma instituição, com mesma validade federal. Para concursos públicos e progressão de carreira, ambos contam igualmente como título.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso fazer Técnico em Administração 100% online no IFSP?</strong></summary>
<p>Sim. O Técnico em Administração é um dos cursos técnicos completos ofertados pelo IFSP em modalidade EAD, com 1.200 horas em 2 anos. Algumas atividades específicas (estágio supervisionado) exigem componente presencial, mas a maior parte do curso é online.</p>
</details>

<details class='faq-discover'>
<summary><strong>Como saber quais cursos IFSP têm turma aberta agora?</strong></summary>
<p>Acessar aprendamais.ifsp.edu.br/cursoslivres ou cursosfic.ifsp.edu.br e usar o filtro "Inscrições Abertas". O catálogo mostra cursos com turmas ativas em qualquer momento do ano, com link direto para inscrição.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

$pD3 = [
    'titulo' => 'Como funciona o cadastro no Aprenda Mais MEC em 2026: passo a passo e os IFs participantes',
    'slug'   => 'como-funciona-cadastro-aprenda-mais-mec-2026-passo-a-passo-ifs-participantes',
    'metaDesc' => 'Como fazer cadastro no Aprenda Mais MEC em 2026 e acessar cursos gratuitos dos Institutos Federais: passo a passo, login federado, recuperação de senha e lista dos IFs participantes.',
    'focusKw' => 'cadastro aprenda mais mec',
    'ogUrl' => 'https://wiki.educacao.aju.br/uploads/images/gallery/2025-08/login.png',
];
$pD3['html'] = <<<'HTML'
<p>Fazer <strong>cadastro no Aprenda Mais MEC</strong> é o primeiro passo para acessar mais de 5.000 cursos EAD gratuitos da Rede Federal de Institutos Federais. O processo é rápido — leva menos de 10 minutos — e não exige envio de documentos físicos, comprovação de renda ou aprovação prévia. Qualquer brasileiro com CPF e e-mail pode criar conta e se inscrever em cursos abertos.</p>

<p>O Aprenda Mais funciona como agregador nacional: o portal central reúne os cursos de todos os IFs participantes, mas cada IF mantém sua plataforma operacional própria (geralmente Moodle). Por isso, dependendo do curso escolhido, o aluno pode precisar de um cadastro adicional na plataforma específica do IF — embora o login federado esteja sendo expandido para unificar o acesso.</p>

<p>O guia abaixo cobre o passo a passo completo do cadastro, como recuperar senha em caso de esquecimento, a estrutura dos 38 IFs participantes e dicas para aproveitar melhor o portal.</p>

<h2>Passo a passo: criar conta no Aprenda Mais</h2>

<ol>
  <li>Acessar o portal principal: aprendamais.ifsp.edu.br/cursoslivres (caminho mais comum) ou diretamente o portal do IF de seu interesse;</li>
  <li>Localizar o botão Inscrever-se ou Criar Conta no cabeçalho;</li>
  <li>Preencher o formulário de cadastro:
    <ul>
      <li>Nome completo (sem abreviações);</li>
      <li>CPF (será o login principal);</li>
      <li>E-mail válido (será usado para confirmações e recuperação de senha);</li>
      <li>Senha (mínimo 8 caracteres, com letras e números);</li>
      <li>Data de nascimento;</li>
      <li>Escolaridade atual;</li>
      <li>Endereço completo (estado, cidade, CEP);</li>
      <li>Telefone celular.</li>
    </ul>
  </li>
  <li>Aceitar os Termos de Uso e a Política de Privacidade (LGPD);</li>
  <li>Confirmar e-mail clicando no link enviado pelo portal (verificar caixa de spam se demorar);</li>
  <li>Cadastro concluído. Agora é possível navegar pelo catálogo e se inscrever em cursos.</li>
</ol>

<h2>Como se inscrever em um curso após criar conta</h2>

<ol>
  <li>Buscar curso no catálogo, usando filtros por área, IF ofertante e modalidade;</li>
  <li>Selecionar o curso desejado;</li>
  <li>Verificar pré-requisitos (escolaridade mínima, idade);</li>
  <li>Clicar em Inscrever-se;</li>
  <li>Se for cursos livres ou FIC com vagas remanescentes, a matrícula é confirmada automaticamente;</li>
  <li>Se for curso com seleção (técnicos completos), submeter inscrição e aguardar resultado conforme cronograma do edital;</li>
  <li>Receber e-mail com instruções para acessar a plataforma Moodle do IF responsável pelo curso;</li>
  <li>Fazer login na plataforma (usando o mesmo CPF e senha do Aprenda Mais OU criando cadastro adicional no IF, dependendo do nível de integração);</li>
  <li>Iniciar as atividades do curso conforme cronograma.</li>
</ol>

<h2>Login federado e cadastro único: o que está sendo implementado</h2>

<ul>
  <li><strong>Cadastro único no portal central:</strong> implementado, com login via CPF e senha;</li>
  <li><strong>Login federado IFSP, IFRS, IFMG, IFBA:</strong> já operacional;</li>
  <li><strong>Outros IFs:</strong> em fase de migração progressiva. Alguns ainda exigem cadastro local no Moodle do IF, com CPF como login;</li>
  <li><strong>Conta gov.br:</strong> integração em estudo, ainda não disponível em 2026, mas planejada para 2027.</li>
</ul>

<h2>Como recuperar senha do Aprenda Mais</h2>

<ol>
  <li>Acessar a tela de login do portal;</li>
  <li>Clicar em Esqueci a Senha;</li>
  <li>Informar CPF ou e-mail cadastrado;</li>
  <li>Aguardar e-mail com link de redefinição (verificar caixa de spam);</li>
  <li>Clicar no link recebido e criar nova senha (mínimo 8 caracteres, com letras e números);</li>
  <li>Fazer login com a nova senha.</li>
</ol>

<h2>Os 38 Institutos Federais participantes (lista completa em 2026)</h2>

<p><strong>Região Norte:</strong></p>
<ul>
  <li>IFAC — Acre</li>
  <li>IFAM — Amazonas</li>
  <li>IFAP — Amapá</li>
  <li>IFPA — Pará</li>
  <li>IFRO — Rondônia</li>
  <li>IFRR — Roraima</li>
  <li>IFTO — Tocantins</li>
</ul>

<p><strong>Região Nordeste:</strong></p>
<ul>
  <li>IFAL — Alagoas</li>
  <li>IFBA — Bahia</li>
  <li>IF Baiano — Bahia (foco em agropecuária)</li>
  <li>IFCE — Ceará</li>
  <li>IFMA — Maranhão</li>
  <li>IFPB — Paraíba</li>
  <li>IFPE — Pernambuco</li>
  <li>IF Sertão-PE — Pernambuco (foco em região semiárida)</li>
  <li>IFPI — Piauí</li>
  <li>IFRN — Rio Grande do Norte</li>
  <li>IFS — Sergipe</li>
</ul>

<p><strong>Região Centro-Oeste:</strong></p>
<ul>
  <li>IFB — Brasília (Distrito Federal)</li>
  <li>IFG — Goiás</li>
  <li>IF Goiano — Goiás (foco em agropecuária)</li>
  <li>IFMS — Mato Grosso do Sul</li>
  <li>IFMT — Mato Grosso</li>
</ul>

<p><strong>Região Sudeste:</strong></p>
<ul>
  <li>IFES — Espírito Santo</li>
  <li>IFMG — Minas Gerais</li>
  <li>IF Sudeste MG — Minas Gerais</li>
  <li>IF Sul MG — Minas Gerais (Sul de Minas)</li>
  <li>IFNMG — Minas Gerais (Norte de Minas)</li>
  <li>IFTM — Minas Gerais (Triângulo Mineiro)</li>
  <li>IFRJ — Rio de Janeiro</li>
  <li>IFF — Rio de Janeiro (Fluminense)</li>
  <li>IFSP — São Paulo</li>
</ul>

<p><strong>Região Sul:</strong></p>
<ul>
  <li>IFC — Catarinense (Santa Catarina)</li>
  <li>IF Far — Rio Grande do Sul (Farroupilha)</li>
  <li>IFPR — Paraná</li>
  <li>IFRS — Rio Grande do Sul</li>
  <li>IFSC — Santa Catarina</li>
  <li>IFSul — Rio Grande do Sul (Sul-rio-grandense)</li>
</ul>

<h2>Dicas para aproveitar melhor o Aprenda Mais</h2>

<ul>
  <li><strong>Buscar por palavra-chave específica:</strong> "Python", "Excel", "inglês", "logística" — mais eficiente que navegar por categoria genérica;</li>
  <li><strong>Conferir IFs locais primeiro:</strong> cursos do IF do seu estado podem ter encontros presenciais opcionais, eventos e atividades complementares;</li>
  <li><strong>Combinar cursos:</strong> fazer 3-4 cursos curtos relacionados constrói portfólio coerente para currículo. Ex: Lógica de Programação + Python + Banco de Dados;</li>
  <li><strong>Salvar certificados em PDF:</strong> baixar e arquivar localmente cada certificado emitido para o currículo e comprovação em concursos;</li>
  <li><strong>Acompanhar editais de cursos técnicos:</strong> cursos completos de 1.200h+ saem periodicamente. Inscrever-se cedo aumenta chance em processos seletivos com sorteio.</li>
</ul>

<details class='faq-discover'>
<summary><strong>É obrigatório fazer cadastro no Aprenda Mais para ver os cursos?</strong></summary>
<p>Não para navegar o catálogo (qualquer pessoa pode ver os cursos disponíveis). Para se inscrever e fazer um curso, sim — é necessário ter cadastro com CPF, e-mail e dados básicos. O cadastro é gratuito e rápido.</p>
</details>

<details class='faq-discover'>
<summary><strong>O Aprenda Mais usa conta gov.br?</strong></summary>
<p>Não ainda em 2026. A integração com gov.br está em estudo e planejada para 2027. Por enquanto, o cadastro é feito diretamente no portal Aprenda Mais com CPF, e-mail e senha próprios.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso usar o mesmo cadastro em qualquer IF?</strong></summary>
<p>Em parte. O cadastro central no Aprenda Mais funciona para inscrição em cursos de todos os IFs participantes. O login federado para acessar diretamente o Moodle dos IFs está em fase de implementação progressiva — alguns IFs (IFSP, IFRS, IFMG, IFBA) já têm login unificado, outros ainda exigem cadastro local adicional.</p>
</details>

<details class='faq-discover'>
<summary><strong>Como saber qual IF é responsável pelo curso?</strong></summary>
<p>No próprio catálogo do Aprenda Mais, cada curso exibe o IF ofertante (IFSP, IFRS, IFMA, etc.) e o campus específico. Ao se inscrever, a plataforma direciona automaticamente para a área operacional do IF responsável.</p>
</details>

<details class='faq-discover'>
<summary><strong>Esqueci minha senha do Aprenda Mais, como faço?</strong></summary>
<p>Acessar a tela de login, clicar em Esqueci a Senha, informar CPF ou e-mail cadastrado e aguardar e-mail com link de redefinição. Se não receber, verificar caixa de spam. Se mesmo assim não receber, contatar suporte do IF onde está matriculado.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// Publicação batch (6 posts: Cluster C 3 + Cluster D 3)
// ════════════════════════════════════════════════════════════════════
$slugSite = 'cursosenac';
$cfgSite = $cfg;
aplicarSite($cfgSite, $sites, $slugSite);
$wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

$schemaAuthor = ['@type' => 'Organization', 'name' => 'Redação Curso Senac Gratuito', 'url' => 'https://cursosenacgratuito.com.br'];
$schemaPublisher = ['@type' => 'Organization', 'name' => 'Curso Senac Gratuito', 'url' => 'https://cursosenacgratuito.com.br'];

$posts = [$pC1, $pC2, $pC3, $pD1, $pD2, $pD3];
foreach ($posts as $info) {
    echo "\n══ {$info['slug']} ══\n";
    $featuredId = 0;
    try {
        $featuredId = (int)($wp->uploadImagemPorUrl169($info['ogUrl'], $info['titulo'], $info['slug']) ?? 0);
        if ($featuredId > 0) echo "✅ Featured: media #{$featuredId}\n";
    } catch (Throwable $e) {}
    if ($featuredId === 0) {
        try { $featuredId = (int)($wp->uploadImagemPorUrl($info['ogUrl'], $info['titulo'], $info['slug']) ?? 0); } catch (Throwable $e) {}
    }
    if ($featuredId > 0) {
        $wp->atualizarMedia($featuredId, [
            'caption' => "{$info['titulo']} (Foto: divulgação)",
            'description' => "Imagem ilustrativa.",
            'title' => $info['titulo'],
            'alt_text' => $info['titulo'],
        ]);
    }

    $schemaNews = [
        '@context' => 'https://schema.org', '@type' => 'NewsArticle',
        'headline' => $info['titulo'],
        'datePublished' => date('c'), 'dateModified' => date('c'),
        'inLanguage' => 'pt-BR', 'author' => $schemaAuthor, 'publisher' => $schemaPublisher,
    ];
    $content = $info['html'] . "\n<script type=\"application/ld+json\" data-newsarticle=\"1\">\n" . json_encode($schemaNews, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n</script>\n";

    $cm = new CategoryMatcher($wp, 70.0);
    $catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch(['Cursos Gratuitos']))));
    $tagIds = $wp->resolverTags(['PSG', 'Senac', 'Aprenda Mais', 'MEC', 'Rede Federal', 'IFSP', 'Cursos EAD', 'Curso Gratuito', 'Institutos Federais']);

    $payload = [
        'title' => $info['titulo'], 'slug' => $info['slug'], 'content' => $content,
        'status' => 'draft',
        'meta' => [
            'rank_math_title' => $info['titulo'] . ' | Curso Senac Gratuito',
            'rank_math_description' => $info['metaDesc'],
            'rank_math_focus_keyword' => $info['focusKw'],
        ],
        'categories' => $catIds, 'tags' => $tagIds,
    ];
    if ($featuredId > 0) $payload['featured_media'] = $featuredId;
    if (!empty($cfgSite['default_post_author_id'])) $payload['author'] = (int)$cfgSite['default_post_author_id'];

    $r = $wp->criarPost($payload);
    $pid = (int)($r['id'] ?? 0);
    $link = (string)($r['link'] ?? '');
    if ($pid === 0) { echo "❌ ERRO\n"; continue; }
    echo "✅ Post #{$pid} DRAFT · {$link}\n";

    try {
        $rel = $wp->buscarRelacionados('curso', 4, $pid);
        if (is_array($rel) && count($rel) >= 2) {
            $bloco = "\n<aside class='posts-relacionados'>\n<h2>Veja também</h2>\n<ul>\n";
            foreach (array_slice($rel, 0, 4) as $r2) {
                $titRel = htmlspecialchars(html_entity_decode((string)$r2['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $linkRel = htmlspecialchars((string)$r2['link'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $bloco .= "  <li><a href='{$linkRel}'>{$titRel}</a></li>\n";
            }
            $bloco .= "</ul>\n</aside>\n";
            $p2get = $wp->getPost($pid);
            $wp->atualizarPost($pid, ['content' => ($p2get['content']['raw'] ?? $content) . $bloco]);
            echo "   Relacionados anexados\n";
        }
    } catch (Throwable $e) {}
}

echo "\n══════ FIM CLUSTERS C+D (PSG + Aprenda Mais) ══════\n";
