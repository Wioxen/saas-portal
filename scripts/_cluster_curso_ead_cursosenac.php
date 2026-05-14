<?php
declare(strict_types=1);
/**
 * Cluster "Melhor curso EAD gratuito" para cursosenac.
 *
 * Hub + 5 cluster posts cobrindo long-tails do autocomplete Google BR:
 * - Senac (PSG + EAD), IFs (Aprenda Mais), FGV Online, Coursera, SENAI.
 *
 * Regra editorial 2026-05-14: AUTORIA = REDAÇÃO DO SITE.
 * Zero atribuição a portal jornalístico. Entidades institucionais
 * (Senac, MEC, FGV, IFs, Capes, Coursera, Sebrae) podem ser citadas
 * como entes cobertos.
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

// ════════════════════════════════════════════════════════════════════
// POST HUB — Melhor curso EAD gratuito em 2026 (autoridade ampla)
// ════════════════════════════════════════════════════════════════════
$pHub = [
    'titulo' => 'Melhor curso EAD gratuito em 2026: 10 plataformas confiáveis com certificado e reconhecimento de mercado',
    'slug'   => 'melhor-curso-ead-gratuito-2026-plataformas-certificado-mec-senac-fgv-coursera',
    'metaDesc' => 'Guia completo do melhor curso EAD gratuito em 2026: comparativo das 10 principais plataformas (Senac, AVAMEC, FGV Online, Coursera, SENAI, Sebrae, Bradesco), com certificado, validade e reconhecimento de mercado.',
    'focusKw' => 'melhor curso ead gratuito 2026',
    'ogUrl' => 'https://s2.glbimg.com/mxdx0c6drG1g7NDxuouSxUT3ES8=/0x0:800x450/984x0/smart/filters:strip_icc()/i.s3.glbimg.com/v1/AUTH_59edd422c0c84a879bd37670ae4f538a/internal_photos/bs/2021/3/U/woFv6ASIiFZEav6SPLpQ/curso-ead.jpg',
];
$pHub['html'] = <<<'HTML'
<p>Escolher o <strong>melhor curso EAD gratuito em 2026</strong> exige separar duas perguntas distintas: o curso entrega aprendizado real e o certificado tem reconhecimento no mercado? As 10 plataformas que dominam a oferta de cursos online gratuitos no Brasil cumprem os dois critérios em graus diferentes, com foco em públicos distintos — do desempregado que precisa de qualificação rápida ao profissional já estabelecido que quer atualização técnica.</p>

<p>O guia abaixo compara as 10 principais opções de cursos EAD 100% gratuitos disponíveis em 2026: AVAMEC, Aprenda Mais (Rede Federal de IFs), Senac PSG, FGV Online, SENAI, Sebrae, Coursera, edX, Fundação Bradesco e Sebrae. Cada plataforma é apresentada com áreas cobertas, carga horária típica, validade do certificado e perfil de público atendido.</p>

<p>O conteúdo é educacional, sem indicação preferencial de plataforma. A escolha certa depende do objetivo do aluno — qualificação rápida para emprego, atualização técnica, certificação para currículo, complementação de pós-graduação ou pura curiosidade.</p>

<h2>O que diferencia um curso EAD gratuito bom de um ruim</h2>

<p>Os 4 critérios práticos que separam plataformas sérias das que apenas distribuem PDFs reciclados:</p>

<ul>
  <li><strong>Reconhecimento institucional:</strong> ser ofertado por instituição com CNPJ ativo, histórico de operação e credenciamento (MEC, Senac, Sistema S, fundações sem fins lucrativos);</li>
  <li><strong>Conteúdo atualizado:</strong> data da última revisão visível, materiais com referências recentes, exercícios alinhados ao que o mercado pede em 2026;</li>
  <li><strong>Certificado verificável:</strong> documento com QR Code ou link de validação online, número de série único, identificação da instituição emissora;</li>
  <li><strong>Carga horária declarada e cumprida:</strong> minutos efetivos de aula + atividades, não horas-relógio infladas para encher currículo.</li>
</ul>

<h2>AVAMEC: a porta de entrada oficial do Ministério da Educação</h2>

<p>O <strong>Ambiente Virtual de Aprendizagem do Ministério da Educação (AVAMEC)</strong> é a plataforma oficial do governo federal para cursos de formação continuada. Atende prioritariamente professores da educação básica, mas também servidores públicos e cidadãos em geral.</p>

<ul>
  <li><strong>Áreas principais:</strong> formação docente, gestão escolar, educação inclusiva, tecnologias educacionais;</li>
  <li><strong>Carga horária:</strong> cursos de 20 a 180 horas;</li>
  <li><strong>Certificado:</strong> emitido pelo MEC, validade nacional, válido para progressão de carreira em redes públicas;</li>
  <li><strong>Acesso:</strong> avamec.mec.gov.br, com cadastro via gov.br;</li>
  <li><strong>Ideal para:</strong> profissionais da educação que precisam de horas de formação continuada com validade oficial.</li>
</ul>

<h2>Aprenda Mais: a Rede Federal dos Institutos Federais</h2>

<p>O <strong>Aprenda Mais</strong> é o portal nacional da Rede Federal de Educação Profissional, Científica e Tecnológica (Rede Federal). Reúne cursos EAD gratuitos de quase todos os 38 Institutos Federais do país (IFRS, IFSP, IFMG, IFBA, IFRJ e outros).</p>

<ul>
  <li><strong>Áreas principais:</strong> tecnologia da informação, gestão, segurança do trabalho, agropecuária, educação, idiomas, empreendedorismo;</li>
  <li><strong>Carga horária:</strong> cursos curtos (40 a 120 horas), de formação inicial e continuada (FIC);</li>
  <li><strong>Certificado:</strong> emitido pelo IF ofertante, com reconhecimento institucional federal e validade nacional;</li>
  <li><strong>Acesso:</strong> aprendamais.ifsp.edu.br/cursoslivres ou portal central via Rede Federal;</li>
  <li><strong>Ideal para:</strong> quem quer certificação federal sem custo, especialmente em áreas técnicas de informática, eletrônica, agropecuária e gestão.</li>
</ul>

<h2>Senac PSG: cursos profissionalizantes pagos pelo Sistema S</h2>

<p>O <strong>Programa Senac de Gratuidade (PSG)</strong> oferta cursos profissionalizantes gratuitos para pessoas físicas que se enquadrem no perfil de baixa renda. As vagas são preenchidas por edital periódico, com calendário próprio de cada estado.</p>

<ul>
  <li><strong>Áreas principais:</strong> beleza, gastronomia, hotelaria, idiomas, informática, gestão, saúde (cuidador de idoso, auxiliar de farmácia), moda;</li>
  <li><strong>Carga horária:</strong> cursos de qualificação profissional (160 a 400 horas) e cursos livres (20 a 80 horas);</li>
  <li><strong>Modalidade:</strong> presencial em unidades Senac e EAD via plataforma própria;</li>
  <li><strong>Certificado:</strong> Senac, com forte reconhecimento de mercado em áreas operacionais;</li>
  <li><strong>Critério de seleção:</strong> renda familiar bruta mensal per capita igual ou inferior a 2 salários mínimos;</li>
  <li><strong>Acesso:</strong> portal Senac do estado (ba.senac.br, sp.senac.br, etc.);</li>
  <li><strong>Ideal para:</strong> quem busca qualificação profissional para entrada no mercado com certificado forte.</li>
</ul>

<h2>FGV Online: 216 cursos gratuitos da Fundação Getúlio Vargas</h2>

<p>A Fundação Getúlio Vargas mantém em 2026 um catálogo de mais de 200 cursos online gratuitos pela plataforma FGV Online. A reputação da FGV em administração, economia e direito agrega valor significativo ao certificado.</p>

<ul>
  <li><strong>Áreas principais:</strong> administração, finanças, marketing, gestão de projetos, direito, recursos humanos, economia, sustentabilidade;</li>
  <li><strong>Carga horária:</strong> cursos curtos (5 a 30 horas);</li>
  <li><strong>Certificado:</strong> emitido pela FGV, com QR Code de validação, reconhecimento corporativo elevado;</li>
  <li><strong>Acesso:</strong> educacao-executiva.fgv.br/cursos-gratuitos;</li>
  <li><strong>Ideal para:</strong> profissional já no mercado que quer atualização técnica em negócios, com certificado de instituição de prestígio para o currículo.</li>
</ul>

<h2>SENAI: cursos industriais gratuitos do Sistema S</h2>

<p>O <strong>Serviço Nacional de Aprendizagem Industrial (SENAI)</strong> oferta cursos gratuitos online pela plataforma SENAI EAD. O foco é qualificação industrial: indústria 4.0, mecânica, eletrotécnica, automação, segurança do trabalho, qualidade.</p>

<ul>
  <li><strong>Áreas principais:</strong> indústria 4.0, manufatura, automação, mecânica, eletrotécnica, qualidade, segurança do trabalho, soldagem;</li>
  <li><strong>Carga horária:</strong> cursos curtos online (8 a 60 horas) e cursos técnicos completos (1200+ horas, alguns presenciais);</li>
  <li><strong>Certificado:</strong> SENAI, com reconhecimento forte em indústria;</li>
  <li><strong>Acesso:</strong> senaiead.com.br ou portal do SENAI estadual;</li>
  <li><strong>Ideal para:</strong> quem trabalha ou pretende trabalhar em indústria — manutenção, produção, qualidade.</li>
</ul>

<h2>Coursera: cursos de universidades internacionais com financial aid</h2>

<p>O <strong>Coursera</strong> hospeda cursos de Stanford, Yale, Princeton, USP, FGV, Google e outras instituições. A maior parte dos cursos individuais permite acesso ao conteúdo gratuitamente (audit mode); o certificado é pago, mas pode ser solicitado de graça via Financial Aid para quem comprova restrição de renda.</p>

<ul>
  <li><strong>Áreas principais:</strong> tecnologia da informação, ciência de dados, ciência da computação, idiomas, negócios, design, ciências humanas;</li>
  <li><strong>Carga horária:</strong> cursos isolados de 4 a 50 horas; trilhas e Especializações de 3 a 6 meses;</li>
  <li><strong>Certificado:</strong> emitido pela universidade ou empresa parceira (Google Career Certificates, IBM Data Science, Meta Marketing Analytics), com validação online;</li>
  <li><strong>Acesso:</strong> coursera.org, com Financial Aid em cada curso (formulário detalhado, aprovação em 15-20 dias);</li>
  <li><strong>Ideal para:</strong> profissional de TI que busca certificação de Google, IBM, Microsoft; estudante universitário; quem quer aprender com universidades de ponta.</li>
</ul>

<h2>edX: cursos do MIT, Harvard e Berkeley pelo modelo "audit"</h2>

<p>A <strong>edX</strong> foi fundada por MIT e Harvard. Hospeda cursos das principais universidades dos EUA e Europa, com modelo similar ao Coursera (acesso gratuito ao conteúdo, certificado pago mas com financial aid disponível).</p>

<ul>
  <li><strong>Áreas principais:</strong> ciência da computação, engenharias, ciências exatas, negócios, humanidades;</li>
  <li><strong>Carga horária:</strong> cursos individuais de 30 a 100 horas; MicroBachelors e MicroMasters de 6 a 12 meses;</li>
  <li><strong>Certificado:</strong> Verified Certificate da universidade ofertante;</li>
  <li><strong>Acesso:</strong> edx.org, com financial aid para quem precisar;</li>
  <li><strong>Ideal para:</strong> aluno autônomo com inglês intermediário que busca formação em ciências exatas ou tecnologia de instituições renomadas.</li>
</ul>

<h2>Sebrae: cursos para empreendedores e MEI</h2>

<p>O <strong>Serviço Brasileiro de Apoio às Micro e Pequenas Empresas (Sebrae)</strong> oferta cursos gratuitos online voltados a empreendedores em todas as fases — desde quem está pensando em abrir o primeiro negócio até quem já tem MEI ou microempresa em operação.</p>

<ul>
  <li><strong>Áreas principais:</strong> empreendedorismo, finanças para PJ, marketing digital para pequenos negócios, vendas, gestão de equipe, formalização (MEI), inovação;</li>
  <li><strong>Carga horária:</strong> cursos curtos (2 a 20 horas);</li>
  <li><strong>Certificado:</strong> Sebrae, com forte reconhecimento entre empreendedores e instituições financeiras (algumas linhas de crédito Sebrae exigem cursos prévios);</li>
  <li><strong>Acesso:</strong> sebrae.com.br/educacao-empreendedora;</li>
  <li><strong>Ideal para:</strong> quem tem ou quer abrir negócio próprio, MEI em busca de profissionalização, microempreendedor.</li>
</ul>

<h2>Fundação Bradesco: cursos sociais com tradição</h2>

<p>A Fundação Bradesco mantém a Escola Virtual há mais de 20 anos, com catálogo robusto de cursos gratuitos voltados a desenvolvimento profissional e social.</p>

<ul>
  <li><strong>Áreas principais:</strong> tecnologia da informação, administração, comportamento empresarial, idiomas (inglês básico), educação;</li>
  <li><strong>Carga horária:</strong> cursos de 2 a 60 horas;</li>
  <li><strong>Certificado:</strong> Fundação Bradesco, com reconhecimento corporativo;</li>
  <li><strong>Acesso:</strong> ev.org.br;</li>
  <li><strong>Ideal para:</strong> quem busca qualificação básica em informática (Office, fundamentos), inglês inicial ou administração.</li>
</ul>

<h2>Cursos do Coursera Google Career Certificates: a opção mais procurada em TI</h2>

<p>Dentro do Coursera, os <strong>Google Career Certificates</strong> ganharam tração rápida no Brasil em 2024-2026. Cobrem 6 carreiras de tecnologia: Suporte de TI, Análise de Dados, Gestão de Projetos, Design UX, Marketing Digital e Automação de TI com Python.</p>

<ul>
  <li><strong>Duração:</strong> 3 a 6 meses (10-20 horas/semana);</li>
  <li><strong>Certificado:</strong> Google profissional, aceito por empresas parceiras (mais de 150 no Brasil) como qualificação para vaga de entrada;</li>
  <li><strong>Custo:</strong> US$ 49/mês via Coursera, mas com Financial Aid 100% gratuito para quem se qualifica;</li>
  <li><strong>Ideal para:</strong> quem quer migrar para TI e precisa de certificação reconhecida pelas grandes empresas.</li>
</ul>

<h2>Comparativo: qual escolher pelo seu objetivo</h2>

<p>Resposta rápida por perfil:</p>

<ul>
  <li><strong>Procurando emprego operacional (vendas, recepção, cuidador, cozinha):</strong> Senac PSG;</li>
  <li><strong>Quer certificação técnica federal:</strong> Aprenda Mais (Rede Federal IFs);</li>
  <li><strong>Profissional de negócios buscando atualização:</strong> FGV Online;</li>
  <li><strong>Trabalha ou quer trabalhar na indústria:</strong> SENAI;</li>
  <li><strong>Migrando para TI:</strong> Coursera (Google, IBM Career Certificates);</li>
  <li><strong>Aluno de pós ou interesse acadêmico:</strong> edX (MIT, Harvard);</li>
  <li><strong>Empreendedor / MEI:</strong> Sebrae;</li>
  <li><strong>Professor da rede pública:</strong> AVAMEC;</li>
  <li><strong>Informática básica e inglês inicial:</strong> Fundação Bradesco;</li>
  <li><strong>Aulas de cursos universitários estrangeiros (sem certificado):</strong> Coursera audit ou edX audit.</li>
</ul>

<details class='faq-discover'>
<summary><strong>Qual é o melhor curso EAD gratuito com certificado reconhecido pelo MEC?</strong></summary>
<p>Para certificado oficial reconhecido pelo MEC, as melhores opções são o AVAMEC (formação continuada para professores e servidores públicos) e o Aprenda Mais da Rede Federal de Institutos Federais (cursos técnicos e de qualificação profissional). Ambos emitem certificados federais com validade nacional.</p>
</details>

<details class='faq-discover'>
<summary><strong>Os cursos EAD gratuitos têm validade para o currículo?</strong></summary>
<p>Sim, quando emitidos por instituição reconhecida com sistema de validação. Cursos do Senac, SENAI, FGV, Sebrae, IFs e plataformas internacionais (Coursera, edX) têm reconhecimento corporativo. Cursos sem identificação clara da instituição emissora ou sem QR Code de validação têm reconhecimento limitado.</p>
</details>

<details class='faq-discover'>
<summary><strong>Como conseguir certificado gratuito no Coursera?</strong></summary>
<p>Via Financial Aid. No início de cada curso pago, o Coursera oferece a opção de aplicar para auxílio financeiro. O formulário pede informações de renda, motivação para fazer o curso e como ele agregaria à carreira. A aprovação leva 15-20 dias e cobre 100% do valor do certificado.</p>
</details>

<details class='faq-discover'>
<summary><strong>O Senac tem cursos gratuitos online?</strong></summary>
<p>Sim, dentro do Programa Senac de Gratuidade (PSG), que oferta cursos profissionalizantes pagos pelo Sistema S a pessoas físicas com renda familiar per capita até 2 salários mínimos. As inscrições são por edital periódico nos portais estaduais do Senac (ba.senac.br, sp.senac.br, etc.).</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto tempo dura um curso EAD gratuito típico?</strong></summary>
<p>Varia conforme a plataforma e o objetivo. Cursos livres curtos (Bradesco, Sebrae, FGV) duram 5 a 30 horas. Qualificação profissional do Senac PSG varia entre 160 e 400 horas. Cursos técnicos da Rede Federal vão de 120 a 1.200 horas. Trilhas internacionais (Google Career Certificates, MicroMasters edX) levam de 3 a 12 meses.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional. Sem indicação preferencial de plataforma.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// POST 2 — Senac PSG / EAD
// ════════════════════════════════════════════════════════════════════
$p2 = [
    'titulo' => 'Cursos gratuitos do Senac em 2026: como conseguir vaga no PSG e nas opções EAD nacionais',
    'slug'   => 'cursos-gratuitos-senac-2026-psg-ead-nacional-como-conseguir-vaga',
    'metaDesc' => 'Guia completo dos cursos gratuitos do Senac em 2026: Programa Senac de Gratuidade (PSG) presencial + cursos EAD nacionais. Critérios, áreas (beleza, gastronomia, idiomas, saúde, tecnologia) e como se inscrever.',
    'focusKw' => 'cursos gratuitos senac',
    'ogUrl' => 'https://es.senac.br/admin/data/dynamic/noticias/798/e914fd42e3764a443a7dbc76f25b15c4_lg_original_.jpg',
];
$p2['html'] = <<<'HTML'
<p><strong>Cursos gratuitos do Senac</strong> são ofertados em dois canais principais em 2026: o Programa Senac de Gratuidade (PSG), presencial em unidades estaduais com critério de renda, e os cursos EAD nacionais abertos a qualquer interessado. Os dois entregam o mesmo nome forte no mercado — Senac, uma das marcas de educação profissional mais reconhecidas do Brasil em áreas de serviços.</p>

<p>O PSG é a porta mais procurada por quem busca qualificação profissional de média duração (160 a 400 horas) com certificado de peso. As vagas são preenchidas por edital periódico, com calendário próprio de cada estado. Os cursos EAD nacionais funcionam como complemento, com formação mais curta e acesso universal.</p>

<p>O guia abaixo cobre como funciona cada modalidade, quem pode participar do PSG, as áreas disponíveis, o passo a passo de inscrição e o que esperar do certificado emitido pelo Senac.</p>

<h2>O que é o Programa Senac de Gratuidade (PSG)</h2>

<p>O <strong>Programa Senac de Gratuidade</strong> é uma política nacional do Senac que reserva parte das vagas de seus cursos para pessoas físicas de baixa renda. As vagas são custeadas com recursos do Sistema S — contribuições pagas por empresas do setor de comércio, serviços e turismo. Por isso, "gratuito" no PSG significa que o aluno não paga nada e o Senac recebe pelo curso por outra fonte.</p>

<p>Em cada estado, o Senac local define o calendário de editais, áreas ofertadas e quantidade de vagas. As inscrições têm prazos curtos (geralmente 15 a 30 dias) e seleção rápida — quem cumpre os critérios entra por ordem ou sorteio, conforme o edital.</p>

<h2>Quem pode participar do PSG: critérios de renda</h2>

<p>O PSG é restrito a pessoas físicas que se enquadrem no perfil socioeconômico definido pelo Sistema S. Os critérios principais:</p>

<ul>
  <li><strong>Renda familiar bruta mensal per capita:</strong> igual ou inferior a 2 salários mínimos (em 2026, cerca de R$ 3.038 por pessoa da família);</li>
  <li><strong>Idade mínima:</strong> 14 anos para cursos de formação inicial; 16 anos para cursos técnicos;</li>
  <li><strong>Escolaridade:</strong> varia por curso. Auxiliar de farmácia exige ensino médio completo; cuidador de idoso, ensino fundamental;</li>
  <li><strong>Documentação:</strong> RG, CPF, comprovante de residência, comprovante de renda familiar (holerite, declaração CadÚnico ou autodeclaração), histórico escolar.</li>
</ul>

<p>Algumas unidades também priorizam públicos específicos: trabalhadores em vulnerabilidade, pessoas com deficiência, idosos, jovens em primeiro emprego. As regras de prioridade constam em cada edital.</p>

<h2>Principais áreas disponíveis no PSG</h2>

<p>O catálogo varia por estado, mas as áreas mais consistentes na oferta nacional são:</p>

<ul>
  <li><strong>Beleza e estética:</strong> manicure e pedicure, cabeleireiro, design de sobrancelhas, depilação, maquiagem profissional;</li>
  <li><strong>Gastronomia:</strong> cozinheiro, confeitaria básica e avançada, panificação, garçom, atendente de restaurante;</li>
  <li><strong>Saúde e bem-estar:</strong> cuidador de idoso, auxiliar de farmácia, recepcionista de consultório médico, técnico em enfermagem (alguns estados);</li>
  <li><strong>Tecnologia da informação:</strong> operador de computador, atendente de tecnologia, web designer, programador de sites;</li>
  <li><strong>Idiomas:</strong> inglês básico e intermediário, espanhol básico;</li>
  <li><strong>Moda e vestuário:</strong> corte e costura, modelista, vestuário industrial;</li>
  <li><strong>Gestão e administração:</strong> auxiliar administrativo, vendedor, atendente de comércio, telemarketing.</li>
</ul>

<h2>Passo a passo: como se inscrever no PSG</h2>

<p>O processo varia ligeiramente entre estados, mas segue lógica nacional:</p>

<ol>
  <li><strong>Identificar o portal estadual do Senac</strong> (ba.senac.br, sp.senac.br, rj.senac.br, mg.senac.br, etc.);</li>
  <li><strong>Localizar a seção PSG</strong> ou "Cursos Gratuitos" no menu principal;</li>
  <li><strong>Verificar os editais abertos</strong> e datas. Costumam abrir vagas em janeiro, abril, julho e outubro;</li>
  <li><strong>Selecionar o curso desejado</strong>, verificar pré-requisitos (escolaridade, idade) e a unidade Senac ofertante;</li>
  <li><strong>Preencher o formulário online</strong> com dados pessoais, escolaridade e renda familiar;</li>
  <li><strong>Anexar documentos:</strong> RG, CPF, comprovante de residência, declaração de renda ou CadÚnico;</li>
  <li><strong>Acompanhar o resultado</strong> via portal. Aprovados recebem instruções para matrícula presencial na unidade Senac;</li>
  <li><strong>Frequentar as aulas:</strong> 75% de presença mínima e aproveitamento conforme avaliações da unidade.</li>
</ol>

<h2>Cursos EAD nacionais do Senac: oferta paralela sem critério de renda</h2>

<p>Paralelamente ao PSG, o Senac oferta cursos EAD nacionais abertos a qualquer interessado, sem critério de renda. As características principais:</p>

<ul>
  <li><strong>Modalidade:</strong> 100% online, com conteúdo gravado e tutoria assíncrona;</li>
  <li><strong>Carga horária:</strong> cursos curtos (20 a 80 horas);</li>
  <li><strong>Áreas:</strong> empreendedorismo, atendimento ao cliente, finanças pessoais, marketing digital básico, comunicação no trabalho, gestão do tempo;</li>
  <li><strong>Certificado:</strong> Senac digital, com QR Code de validação;</li>
  <li><strong>Acesso:</strong> ead.senac.br, plataforma única nacional;</li>
  <li><strong>Custo:</strong> alguns cursos têm taxa simbólica (R$ 30-100), outros são totalmente gratuitos. A separação consta na plataforma.</li>
</ul>

<h2>Reconhecimento do certificado Senac no mercado</h2>

<p>O certificado Senac tem reputação consolidada em áreas de serviços. Em recrutamento para vagas operacionais (beleza, gastronomia, atendimento, saúde básica), o Senac aparece como referência em currículo. Em áreas técnicas mais especializadas (engenharia, ciências, finanças avançadas), a percepção é mais limitada — outras instituições têm peso maior.</p>

<p>O certificado é válido para:</p>

<ul>
  <li><strong>Currículo:</strong> sinaliza qualificação formal e capacidade de concluir formação estruturada;</li>
  <li><strong>Concursos públicos:</strong> conta como título quando o edital pontua cursos de qualificação;</li>
  <li><strong>Crédito de horas em cursos superiores:</strong> alguns cursos do Senac dão crédito de horas em graduações tecnólogas (varia por instituição);</li>
  <li><strong>Sebrae, BNDES e SOMSeg:</strong> alguns programas de crédito a pequenos negócios exigem comprovação de capacitação profissional, sendo Senac aceito.</li>
</ul>

<details class='faq-discover'>
<summary><strong>Quem pode fazer curso gratuito no Senac em 2026?</strong></summary>
<p>Pelo Programa Senac de Gratuidade (PSG), pessoas físicas com renda familiar per capita até 2 salários mínimos, com escolaridade compatível com o curso desejado. Pelos cursos EAD nacionais do Senac (ead.senac.br), qualquer pessoa pode se inscrever, sem critério de renda.</p>
</details>

<details class='faq-discover'>
<summary><strong>Como conseguir vaga no PSG do Senac?</strong></summary>
<p>Acompanhar o portal estadual do Senac (ba.senac.br, sp.senac.br, etc.), encontrar editais abertos, preencher formulário online com dados pessoais, escolaridade e comprovante de renda familiar. Vagas são preenchidas conforme critério de cada edital (ordem de inscrição, sorteio ou avaliação social).</p>
</details>

<details class='faq-discover'>
<summary><strong>O certificado do Senac vale para o currículo?</strong></summary>
<p>Sim. O certificado Senac tem forte reconhecimento em áreas de serviços (beleza, gastronomia, atendimento, saúde básica, idiomas) e é valorizado em vagas operacionais. Em áreas técnicas específicas (engenharia, TI avançada), o peso pode ser complementar a outras formações.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto tempo dura um curso PSG do Senac?</strong></summary>
<p>Cursos de Formação Inicial e Continuada (FIC) duram de 160 a 240 horas. Cursos de Qualificação Profissional, 240 a 400 horas. Cursos técnicos completos (técnico em enfermagem, técnico em informática), 1.200 a 1.600 horas, geralmente em mais de um ano.</p>
</details>

<details class='faq-discover'>
<summary><strong>Os cursos EAD nacionais do Senac são realmente grátis?</strong></summary>
<p>Parte do catálogo é totalmente gratuita; outra parte tem taxa simbólica (R$ 30 a R$ 100). A separação consta na plataforma ead.senac.br, com filtros para mostrar apenas cursos gratuitos. Ambos emitem certificado Senac válido.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// POST 3 — Aprenda Mais (Rede Federal IFs)
// ════════════════════════════════════════════════════════════════════
$p3 = [
    'titulo' => 'Cursos técnicos EAD gratuitos no Aprenda Mais: a porta nacional dos Institutos Federais em 2026',
    'slug'   => 'curso-tecnico-ead-gratuito-aprenda-mais-rede-federal-institutos-federais-2026',
    'metaDesc' => 'Como funciona o Aprenda Mais, portal nacional da Rede Federal de Institutos Federais com cursos EAD gratuitos: áreas, certificado MEC, como se inscrever e a oferta dos principais IFs (IFSP, IFRS, IFMG, IFBA).',
    'focusKw' => 'curso técnico ead gratuito aprenda mais',
    'ogUrl' => 'https://ixymyhazbhztpjnlxmbd.supabase.co/storage/v1/object/images/generated/estudante-estudando-ead-computador-918.webp',
];
$p3['html'] = <<<'HTML'
<p>O <strong>Aprenda Mais</strong> é a plataforma nacional da Rede Federal de Educação Profissional, Científica e Tecnológica para oferta de cursos EAD gratuitos. Reúne as ofertas dos 38 Institutos Federais brasileiros (IFSP, IFRS, IFMG, IFBA, IFRJ, IFMA e outros) em um portal único com cursos livres, de qualificação profissional e técnicos.</p>

<p>O diferencial do Aprenda Mais é o reconhecimento institucional federal: cursos são ofertados por autarquias do governo federal (os Institutos Federais), com certificado emitido diretamente pelo IF responsável. O certificado tem validade nacional e peso em concursos públicos, planos de carreira do serviço público e processos seletivos de pós-graduação.</p>

<p>O guia abaixo cobre como o portal funciona, as áreas com maior oferta em 2026, o passo a passo de inscrição e a diferença entre cursos livres, qualificação profissional FIC e técnicos completos.</p>

<h2>O que é a Rede Federal e por que isso importa</h2>

<p>A <strong>Rede Federal de Educação Profissional, Científica e Tecnológica</strong> é a estrutura nacional que reúne os 38 Institutos Federais, 2 Centros Federais de Educação Tecnológica (CEFETs) e a Universidade Tecnológica Federal do Paraná (UTFPR). Foi criada pela Lei 11.892/2008 e tem como missão ofertar educação profissional gratuita em todos os níveis — do curso livre ao mestrado.</p>

<p>Quando um IF oferta um curso pelo Aprenda Mais, o certificado:</p>

<ul>
  <li>É emitido por autarquia federal vinculada ao MEC;</li>
  <li>Tem validade nacional, sem restrição geográfica;</li>
  <li>Conta como título em concursos públicos federais, estaduais e municipais;</li>
  <li>Pode ser usado para progressão de carreira no serviço público (plano de cargos com bônus por titulação);</li>
  <li>É aceito como qualificação profissional em processos seletivos de empresas privadas.</li>
</ul>

<h2>Cursos livres, FIC e técnicos: as 3 modalidades</h2>

<p>O Aprenda Mais reúne 3 tipos diferentes de curso, cada um com proposta distinta:</p>

<ul>
  <li><strong>Cursos livres:</strong> formação rápida (20 a 60 horas), sem pré-requisito formal de escolaridade. Cobrem temas pontuais — Excel básico, comunicação no trabalho, redação para concursos, finanças pessoais. Certificado de extensão;</li>
  <li><strong>Formação Inicial e Continuada (FIC):</strong> qualificação profissional de média duração (160 a 240 horas). Atende quem quer entrar no mercado em uma profissão específica — operador de computador, auxiliar administrativo, técnico em informática para internet. Certificado profissional;</li>
  <li><strong>Cursos técnicos completos:</strong> formação técnica de nível médio (1.200 a 2.400 horas, geralmente 2 anos). Inclui Técnico em Administração, Técnico em Informática, Técnico em Logística, Técnico em Agropecuária. Exige ensino médio em andamento ou concluído. Certificado de Técnico em Nível Médio com validade federal.</li>
</ul>

<h2>Principais áreas e cursos do Aprenda Mais em 2026</h2>

<p>O catálogo varia por IF, mas as áreas com maior consistência de oferta nacional são:</p>

<ul>
  <li><strong>Informática e TI:</strong> programação, banco de dados, redes, segurança da informação, programação para internet, manutenção de computadores;</li>
  <li><strong>Gestão e administração:</strong> técnico em administração, auxiliar administrativo, gestão de estoque, atendimento ao cliente;</li>
  <li><strong>Agropecuária:</strong> técnico agropecuário, agroindústria, técnico em alimentos, agricultura familiar;</li>
  <li><strong>Indústria:</strong> técnico em segurança do trabalho, manutenção industrial, eletrotécnica, mecânica básica;</li>
  <li><strong>Educação:</strong> formação continuada para professores, prática pedagógica, educação inclusiva, educação a distância;</li>
  <li><strong>Saúde:</strong> primeiros socorros, atendente de saúde, biossegurança;</li>
  <li><strong>Idiomas:</strong> inglês instrumental, espanhol básico, libras;</li>
  <li><strong>Empreendedorismo:</strong> plano de negócios, marketing digital, gestão financeira para pequenos negócios.</li>
</ul>

<h2>Passo a passo: como se inscrever no Aprenda Mais</h2>

<p>O processo é mais simples que o PSG do Senac e não exige comprovação de renda:</p>

<ol>
  <li>Acessar o portal Aprenda Mais (aprendamais.ifsp.edu.br/cursoslivres ou portal central da Rede Federal);</li>
  <li>Navegar pelo catálogo ou usar filtro por área, modalidade e Instituto Federal;</li>
  <li>Selecionar o curso desejado, verificar pré-requisitos (escolaridade mínima, idade) e período de oferta;</li>
  <li>Clicar em Inscrever-se. Preencher cadastro com dados pessoais, CPF, e-mail e escolaridade;</li>
  <li>Aguardar confirmação da matrícula. Cursos livres geralmente confirmam imediatamente; FIC e técnicos podem ter processo seletivo (análise de histórico ou prova);</li>
  <li>Acessar a plataforma do IF responsável pelo curso (cada IF tem seu Moodle ou ambiente próprio);</li>
  <li>Cumprir as atividades, fóruns, avaliações e exigência de presença mínima (geralmente 75%);</li>
  <li>Após aprovação, baixar o certificado em PDF com QR Code de validação online.</li>
</ol>

<h2>Os IFs com maior oferta de cursos em 2026</h2>

<p>Por região, os Institutos Federais com catálogo mais robusto:</p>

<ul>
  <li><strong>IFSP (São Paulo):</strong> maior IF do país, com forte oferta em informática, automação, gestão e idiomas;</li>
  <li><strong>IFRS (Rio Grande do Sul):</strong> destaque em pedagogia, educação a distância, tecnologias educacionais;</li>
  <li><strong>IFMG (Minas Gerais):</strong> oferta consistente em agroindústria, mecânica, segurança do trabalho;</li>
  <li><strong>IFBA (Bahia):</strong> química, tecnologia em alimentos, gestão;</li>
  <li><strong>IFRJ (Rio de Janeiro):</strong> saúde pública, biotecnologia, química;</li>
  <li><strong>IFMA (Maranhão):</strong> administração, agropecuária, eletrônica;</li>
  <li><strong>IFG (Goiás):</strong> tecnologia da informação, indústria, gestão pública.</li>
</ul>

<h2>Diferenças entre o Aprenda Mais e outros portais</h2>

<p>Comparado com Senac, SENAI, FGV ou Coursera, o Aprenda Mais ocupa lugar específico:</p>

<ul>
  <li><strong>Reconhecimento federal:</strong> certificado emitido por IF federal supera Senac (Sistema S) em concursos públicos federais e estaduais;</li>
  <li><strong>Custo zero real:</strong> sem critério de renda (diferente do PSG do Senac), aberto a qualquer brasileiro;</li>
  <li><strong>Catálogo amplo:</strong> mais de 5.000 cursos somando todos os IFs;</li>
  <li><strong>Modalidade técnica completa:</strong> únicos a ofertar formação técnica completa de 2 anos gratuita 100% online (alguns estados);</li>
  <li><strong>Pré-requisito flexível:</strong> cursos livres aceitos por qualquer pessoa; FIC exigem ensino fundamental completo; técnicos pedem ensino médio em curso.</li>
</ul>

<details class='faq-discover'>
<summary><strong>O certificado do Aprenda Mais vale para concurso público?</strong></summary>
<p>Sim. Como o curso é ofertado por autarquia federal (IF), o certificado tem validade nacional e conta como título em concursos públicos federais, estaduais e municipais que pontuam cursos de qualificação profissional ou extensão. Verificar o edital específico para confirmar a categoria de pontuação.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso fazer curso técnico completo gratuito no Aprenda Mais?</strong></summary>
<p>Sim, em parte dos cursos ofertados. Alguns IFs disponibilizam cursos técnicos completos (1.200 a 2.400 horas) em modalidade EAD gratuita, geralmente com processo seletivo via análise de histórico escolar ou prova específica. Os mais comuns: Técnico em Administração, Técnico em Informática para Internet, Técnico em Logística.</p>
</details>

<details class='faq-discover'>
<summary><strong>Precisa pagar alguma taxa para se inscrever?</strong></summary>
<p>Não. Os cursos do Aprenda Mais são 100% gratuitos, sem cobrança de taxa de inscrição, matrícula, mensalidade ou certificação. O custo total para o aluno é zero.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual a diferença entre curso livre e FIC do Aprenda Mais?</strong></summary>
<p>Curso livre tem carga horária menor (20-60 horas) e emite certificado de extensão. FIC (Formação Inicial e Continuada) tem 160-240 horas e emite certificado de qualificação profissional, mais valorizado em currículo para vagas operacionais e administrativas. Cursos técnicos completos vão muito além — 1.200+ horas com diploma de Técnico em Nível Médio.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual IF oferece mais cursos em 2026?</strong></summary>
<p>O IFSP (Instituto Federal de São Paulo) mantém o maior catálogo nacional via Aprenda Mais, com foco em informática, automação, gestão e idiomas. Outros IFs com oferta robusta incluem IFRS, IFMG, IFBA, IFRJ, IFMA e IFG. O portal Aprenda Mais permite filtrar por IF ofertante.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// POST 4 — FGV Online 216 cursos
// ════════════════════════════════════════════════════════════════════
$p4 = [
    'titulo' => 'FGV Online em 2026: 216 cursos gratuitos com certificado, áreas e como escolher pelo seu objetivo',
    'slug'   => 'fgv-online-2026-216-cursos-gratuitos-certificado-areas-administracao-financas-marketing',
    'metaDesc' => 'Como funciona a FGV Online em 2026: catálogo de 216 cursos gratuitos com certificado, áreas (administração, finanças, marketing, gestão), validade do certificado FGV e perfil ideal de aluno.',
    'focusKw' => 'fgv online cursos gratuitos',
    'ogUrl' => 'https://uniateneu.edu.br/wp-content/uploads/2021/12/Qualificacao-profissional-scaled.jpg',
];
$p4['html'] = <<<'HTML'
<p>A <strong>FGV Online</strong> mantém em 2026 um catálogo de mais de 200 cursos online gratuitos com certificado, ofertados pela Fundação Getúlio Vargas. A combinação de marca consolidada em administração, economia e direito, com acesso totalmente gratuito e certificação validável, faz da plataforma uma das opções mais atraentes para quem quer atualização profissional em negócios.</p>

<p>O catálogo cobre administração, finanças, marketing, gestão de projetos, recursos humanos, direito e economia. A carga horária varia entre 5 e 30 horas por curso, com formato 100% autoinstrucional — sem turmas, sem horário fixo, sem prazo de conclusão.</p>

<p>O guia abaixo cobre como a plataforma funciona, quais áreas têm catálogo mais consistente, como o certificado FGV é percebido no mercado e o passo a passo de inscrição na plataforma.</p>

<h2>Como funciona a FGV Online</h2>

<p>A FGV Online é uma das áreas educacionais da Fundação Getúlio Vargas, instituição fundada em 1944 e referência em pesquisa e ensino em negócios no Brasil. A plataforma de cursos gratuitos opera em paralelo aos cursos pagos (MBAs, especializações, cursos abertos pagos), com proposta de democratizar acesso a conteúdos básicos das áreas em que a FGV é reconhecida.</p>

<p>As características operacionais:</p>

<ul>
  <li><strong>Modalidade:</strong> 100% online, autoinstrucional;</li>
  <li><strong>Custo:</strong> totalmente gratuito (curso + certificado), sem cobrança em qualquer etapa;</li>
  <li><strong>Cadastro:</strong> único na plataforma, com CPF e e-mail. Sem aprovação prévia;</li>
  <li><strong>Conteúdo:</strong> videoaulas, textos de apoio, leituras complementares;</li>
  <li><strong>Avaliação:</strong> testes online ao final de cada módulo, sem prova presencial;</li>
  <li><strong>Certificado:</strong> emitido automaticamente após aprovação, com QR Code de validação online e número de série único;</li>
  <li><strong>Prazo:</strong> aluno tem liberdade total — não há prazo máximo nem mínimo entre módulos.</li>
</ul>

<h2>As áreas com maior catálogo na FGV Online em 2026</h2>

<p>Os 216+ cursos disponíveis se distribuem em 10 grandes áreas:</p>

<ul>
  <li><strong>Administração e gestão:</strong> 45+ cursos. Cobre gestão estratégica, gestão de pessoas, gestão de operações, governança corporativa, gestão pública;</li>
  <li><strong>Finanças:</strong> 35+ cursos. Inclui finanças pessoais, finanças corporativas, contabilidade básica, mercado de capitais, análise de investimentos;</li>
  <li><strong>Marketing:</strong> 28+ cursos. Marketing digital, branding, gestão de marca, marketing de relacionamento, pesquisa de mercado;</li>
  <li><strong>Direito:</strong> 22+ cursos. Direito empresarial, direito tributário, direito administrativo, contratos, propriedade intelectual;</li>
  <li><strong>Recursos humanos:</strong> 18+ cursos. Liderança, comunicação, gestão de conflitos, desenvolvimento de equipes, avaliação de desempenho;</li>
  <li><strong>Economia:</strong> 15+ cursos. Microeconomia, macroeconomia, economia brasileira, política econômica;</li>
  <li><strong>Gestão de projetos:</strong> 12+ cursos. Metodologias ágeis, PMBOK, gestão de risco em projetos, gestão de stakeholders;</li>
  <li><strong>Sustentabilidade:</strong> 10+ cursos. Gestão ambiental, ESG, sustentabilidade corporativa, responsabilidade social;</li>
  <li><strong>Sociologia e comportamento organizacional:</strong> 8+ cursos. Cultura organizacional, sociologia do trabalho;</li>
  <li><strong>Tecnologia aplicada a negócios:</strong> 12+ cursos. Transformação digital, dados e analytics para gestão, IA aplicada a negócios.</li>
</ul>

<h2>O que diferencia o certificado FGV</h2>

<p>O certificado FGV tem percepção elevada em recrutamento corporativo, especialmente em áreas de negócios:</p>

<ul>
  <li><strong>Marca FGV:</strong> a Fundação Getúlio Vargas é nome forte em administração, economia e direito. O certificado carrega o peso da instituição;</li>
  <li><strong>Validação online:</strong> o documento tem QR Code que abre página oficial fgv.br confirmando autenticidade. Recrutadores e instituições conseguem validar em 10 segundos;</li>
  <li><strong>Carga horária real:</strong> a hora declarada no certificado corresponde a tempo de estudo efetivo, não "horas-relógio infladas";</li>
  <li><strong>Detalhamento:</strong> o certificado inclui ementa, conteúdos abordados e bibliografia básica — mais informação que certificados de plataformas concorrentes.</li>
</ul>

<p>O limite do peso do certificado: por ser autoinstrucional sem prova presencial, o documento sinaliza estudo individual, não graduação ou diploma. Para vagas que pedem graduação ou MBA, o certificado FGV Online complementa o currículo mas não substitui a formação formal.</p>

<h2>Passo a passo: como se inscrever na FGV Online</h2>

<ol>
  <li>Acessar educacao-executiva.fgv.br/cursos-gratuitos ou fgv.br/cursos-gratuitos;</li>
  <li>Navegar pelo catálogo ou usar a busca por palavra-chave ou área;</li>
  <li>Selecionar o curso desejado, conferir ementa, carga horária e pré-requisitos (a maioria não tem pré-requisito);</li>
  <li>Clicar em Acessar Curso. Se for o primeiro acesso, fazer cadastro com nome, CPF e e-mail;</li>
  <li>Confirmar matrícula no curso. O acesso é liberado imediatamente;</li>
  <li>Estudar no ritmo próprio — videoaulas, leituras complementares e testes online ao final de cada módulo;</li>
  <li>Após concluir todos os módulos com aproveitamento mínimo (geralmente 70% nos testes), o certificado é gerado automaticamente;</li>
  <li>Baixar o certificado em PDF na área do aluno. O documento já vem com QR Code de validação.</li>
</ol>

<h2>Cursos FGV Online mais procurados em 2026</h2>

<p>Por número de matrículas e relevância de mercado, os cursos mais buscados são:</p>

<ul>
  <li><strong>Gestão de projetos com metodologias ágeis:</strong> aplicação prática de Scrum e Kanban em equipes corporativas;</li>
  <li><strong>Finanças pessoais:</strong> orçamento, investimentos, planejamento de aposentadoria;</li>
  <li><strong>Excel aplicado a negócios:</strong> tabelas dinâmicas, dashboards básicos, análise de dados;</li>
  <li><strong>Marketing digital para pequenos negócios:</strong> SEO básico, redes sociais, anúncios pagos;</li>
  <li><strong>Direito do trabalho:</strong> reforma trabalhista, terceirização, eSocial;</li>
  <li><strong>Liderança e gestão de pessoas:</strong> técnicas de feedback, comunicação não-violenta, desenvolvimento de equipes;</li>
  <li><strong>Compliance corporativo:</strong> integridade, anti-corrupção, gestão de riscos.</li>
</ul>

<h2>Para quem a FGV Online faz sentido</h2>

<ul>
  <li><strong>Profissional de negócios já atuante</strong> buscando atualização rápida em tópico específico;</li>
  <li><strong>Recém-formado</strong> que quer engordar o currículo com certificações reconhecidas antes da primeira vaga;</li>
  <li><strong>Empreendedor / MEI</strong> que precisa entender melhor finanças, marketing ou gestão;</li>
  <li><strong>Servidor público</strong> em plano de carreira com bônus por capacitação;</li>
  <li><strong>Estudante de pós-graduação</strong> aprofundando temas auxiliares da pesquisa.</li>
</ul>

<details class='faq-discover'>
<summary><strong>Os cursos da FGV Online são realmente gratuitos?</strong></summary>
<p>Sim, 100% gratuitos. Não há cobrança de matrícula, mensalidade, certificação ou material complementar. O modelo é sustentado pela própria Fundação Getúlio Vargas como parte de sua missão institucional. Os cursos pagos (MBAs, especializações) são outro produto da FGV, separado da FGV Online.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto tempo demora um curso da FGV Online?</strong></summary>
<p>Cursos curtos duram entre 5 e 30 horas de estudo individual. Como o formato é autoinstrucional sem prazo, o aluno tem liberdade total — pode concluir em 1 dia ou ao longo de várias semanas. A média declarada por alunos é de 1 a 3 semanas em cursos de 20 horas, estudando 1-2 horas por dia.</p>
</details>

<details class='faq-discover'>
<summary><strong>O certificado FGV Online vale para concursos públicos?</strong></summary>
<p>Sim, quando o edital pontua certificados de qualificação profissional ou extensão emitidos por instituição de ensino superior reconhecida. A FGV se enquadra. Verificar o edital específico para confirmar a categoria e o valor de pontos atribuído ao tipo de certificado.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso fazer vários cursos da FGV Online ao mesmo tempo?</strong></summary>
<p>Sim, não há limite de matrículas simultâneas. O aluno pode se inscrever em quantos cursos quiser e administrar o próprio ritmo de conclusão. Cada curso gera certificado independente após aprovação.</p>
</details>

<details class='faq-discover'>
<summary><strong>Como o recrutador valida o certificado FGV?</strong></summary>
<p>O certificado tem QR Code que, quando escaneado, abre a página oficial da FGV confirmando autenticidade, data de emissão, ementa do curso e nome do aluno. O recrutador valida em segundos sem precisar contatar a FGV. O número de série único também permite consulta manual no portal da fundação.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional. Sem indicação preferencial.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// POST 5 — Coursera Financial Aid + Google
// ════════════════════════════════════════════════════════════════════
$p5 = [
    'titulo' => 'Curso Coursera gratuito em 2026: Financial Aid, Google Career Certificates e auditoria sem certificado',
    'slug'   => 'curso-coursera-gratuito-2026-financial-aid-google-career-certificates-audit',
    'metaDesc' => 'Como fazer curso Coursera gratuito em 2026: Financial Aid (certificado 100% grátis), Google Career Certificates por R$ 0 e audit mode (acesso ao conteúdo sem certificado). Áreas, prazos e como aplicar.',
    'focusKw' => 'curso coursera gratuito',
    'ogUrl' => 'https://uniateneu.edu.br/wp-content/uploads/2021/12/Qualificacao-profissional-scaled.jpg',
];
$p5['html'] = <<<'HTML'
<p>Fazer <strong>curso no Coursera de graça</strong> em 2026 é possível por 3 caminhos diferentes: o Financial Aid (auxílio financeiro que cobre 100% do valor do certificado), o audit mode (acesso ao conteúdo sem certificado) e cursos gratuitos disponibilizados diretamente por algumas universidades parceiras. A escolha do caminho certo depende do objetivo — aprender por conta própria, certificar para currículo ou conseguir certificação reconhecida para nova carreira.</p>

<p>Em 2026, os <strong>Google Career Certificates</strong> dominam as buscas brasileiras por "curso Coursera gratuito" — são 6 trilhas de carreira (Suporte de TI, Análise de Dados, Gestão de Projetos, Design UX, Marketing Digital e Automação de TI com Python) que custam US$ 49/mês na assinatura padrão, mas podem ser cursados 100% de graça via Financial Aid aprovado.</p>

<p>O guia abaixo cobre os 3 modelos de acesso gratuito, o passo a passo do Financial Aid com critérios reais de aprovação, as áreas mais procuradas e como o certificado é percebido no mercado brasileiro.</p>

<h2>Os 3 caminhos para fazer Coursera de graça</h2>

<p>Os modelos disponíveis em 2026:</p>

<ul>
  <li><strong>Financial Aid (auxílio financeiro):</strong> caminho mais comum. Cobre 100% do certificado de cursos pagos. Aprovação em 15-20 dias por curso, sem custo;</li>
  <li><strong>Audit mode:</strong> acesso gratuito ao conteúdo (videoaulas, leituras) sem direito a certificado, exercícios avaliados ou notas. Bom para aprender por curiosidade ou complementar outro curso;</li>
  <li><strong>Cursos gratuitos diretos:</strong> algumas universidades disponibilizam cursos isolados gratuitos com certificado, sem necessidade de Financial Aid. Lista pequena mas crescente.</li>
</ul>

<h2>Financial Aid: o caminho principal para certificado gratuito</h2>

<p>O Coursera Financial Aid é um sistema oficial que cobre integralmente o custo de cursos pagos para alunos que comprovam restrição financeira. O processo:</p>

<ol>
  <li>No curso de interesse, clicar em Inscrever-se → Financial Aid Available;</li>
  <li>Preencher o formulário de aplicação, que contém:
    <ul>
      <li>Razões pessoais e profissionais para fazer o curso (descrever em inglês, entre 150-500 palavras);</li>
      <li>Como o curso ajudará no objetivo de carreira;</li>
      <li>Por que precisa de auxílio financeiro (situação econômica, mercado profissional na sua região);</li>
      <li>Renda bruta mensal estimada (faixa);</li>
      <li>Compromisso de concluir o curso.</li>
    </ul>
  </li>
  <li>Submeter o formulário (em inglês — é exigência);</li>
  <li>Aguardar 15 a 20 dias para análise. Resposta vai por e-mail;</li>
  <li>Aprovado: o curso é desbloqueado integralmente. Pode acessar todo o conteúdo, exercícios avaliados e receber certificado ao concluir;</li>
  <li>Negado: pode aplicar novamente em 15 dias, reescrevendo as justificativas com mais detalhes.</li>
</ol>

<h2>O que aumenta as chances de aprovação no Financial Aid</h2>

<p>Análise de padrões de aprovação ao longo dos últimos anos sugere boas práticas:</p>

<ul>
  <li><strong>Escrever em inglês completo, com cuidado gramatical:</strong> formulários em português ou com inglês muito ruim têm taxa de rejeição maior;</li>
  <li><strong>Detalhar a situação financeira sem dramatizar:</strong> mencionar renda mensal aproximada, despesas fixas relevantes, motivo da restrição (desemprego, transição de carreira, baixa remuneração);</li>
  <li><strong>Conectar o curso a objetivo concreto:</strong> "Quero aprender Python pra automatizar relatórios no meu trabalho atual" funciona melhor que "Tenho interesse em programação";</li>
  <li><strong>Mencionar contribuição de retorno:</strong> indicar que pretende compartilhar aprendizado, contribuir para a comunidade, ajudar outros — aumenta probabilidade;</li>
  <li><strong>Solicitar para 1 curso por vez:</strong> aplicar para muitos cursos simultaneamente baixa a credibilidade da aplicação.</li>
</ul>

<h2>Google Career Certificates: o destaque brasileiro</h2>

<p>Os 6 Google Career Certificates ofertados no Coursera têm crescido como porta de entrada para carreiras em tecnologia. Características em 2026:</p>

<ul>
  <li><strong>Suporte de TI (IT Support):</strong> 5 cursos, ~120 horas, voltado para vagas de help desk e suporte técnico;</li>
  <li><strong>Análise de Dados (Data Analytics):</strong> 8 cursos, ~180 horas, com foco em SQL, R, Tableau e visualização;</li>
  <li><strong>Gestão de Projetos (Project Management):</strong> 6 cursos, ~140 horas, metodologias ágeis e tradicionais;</li>
  <li><strong>Design UX (UX Design):</strong> 7 cursos, ~200 horas, pesquisa de usuário e prototipagem em Figma;</li>
  <li><strong>Marketing Digital e E-commerce:</strong> 7 cursos, ~150 horas, anúncios pagos, SEO, analytics;</li>
  <li><strong>Automação de TI com Python:</strong> 6 cursos, ~130 horas, scripts, Git, infraestrutura.</li>
</ul>

<p>Cada certificado custa US$ 49/mês via assinatura Coursera Plus ou Coursera Career Path. Com Financial Aid aprovado, sai 100% grátis para os 6 cursos da trilha escolhida.</p>

<h2>Empresas parceiras que reconhecem Google Career Certificates no Brasil</h2>

<p>Em 2026, mais de 150 empresas no Brasil aceitam Google Career Certificates como qualificação válida para vagas de entrada em tecnologia. Algumas das mais relevantes:</p>

<ul>
  <li>Grupo Boticário, Magalu, Mercado Livre, Nubank, Itaú, Bradesco;</li>
  <li>Vivo, TIM, Claro, Oi;</li>
  <li>Stefanini, Accenture, Deloitte, EY;</li>
  <li>iFood, 99, Loft, QuintoAndar;</li>
  <li>Acessoria, RD Saúde, Centauro, Renner.</li>
</ul>

<p>O peso do certificado é maior para vagas júnior ou de transição de carreira. Para vagas pleno e sênior, o certificado complementa mas não substitui experiência prática.</p>

<h2>Audit mode: estudar sem certificado</h2>

<p>Para quem quer apenas acessar o conteúdo sem se importar com certificado, o audit mode é o caminho mais simples:</p>

<ol>
  <li>No curso desejado, clicar em Inscrever-se;</li>
  <li>Na tela de pagamento, procurar a opção Audit the course (Auditar o curso) ou Acesso de Auditoria;</li>
  <li>O acesso é liberado imediatamente, sem precisar de aprovação ou pagamento;</li>
  <li>O aluno acessa videoaulas, leituras de apoio, fóruns;</li>
  <li>Não tem direito a: exercícios avaliados, notas, certificado, suporte de tutoria;</li>
  <li>Limite: alguns cursos têm acesso de auditoria por tempo determinado (geralmente 14 dias após início do conteúdo).</li>
</ol>

<h2>Cursos gratuitos diretos no Coursera (sem necessidade de Financial Aid)</h2>

<p>Algumas universidades disponibilizam cursos isolados 100% gratuitos com certificado, sem precisar pedir auxílio. Procurar com o filtro Free Courses na busca da plataforma. Alguns exemplos consistentes em 2026:</p>

<ul>
  <li><strong>USP — Inovação e Criatividade:</strong> conteúdo em português, certificado USP;</li>
  <li><strong>Yale — The Science of Well-Being:</strong> psicologia positiva, em inglês com legendas;</li>
  <li><strong>Universidade de Michigan — Programming for Everybody (Python):</strong> introdução à programação;</li>
  <li><strong>Duke — Data Science Math Skills:</strong> matemática básica para ciência de dados;</li>
  <li><strong>Cursos UNESCO e Banco Mundial:</strong> sustentabilidade, desenvolvimento, educação.</li>
</ul>

<details class='faq-discover'>
<summary><strong>Como conseguir certificado gratuito no Coursera?</strong></summary>
<p>Aplicar para Financial Aid no curso desejado. Preencher o formulário em inglês descrevendo razões pessoais, profissionais, situação financeira e compromisso de concluir. Aprovação leva 15-20 dias e cobre 100% do valor do certificado. Alternativa: alguns cursos isolados (USP, Yale, Duke) são gratuitos sem precisar de Financial Aid.</p>
</details>

<details class='faq-discover'>
<summary><strong>O Google Career Certificate vale a pena no Brasil?</strong></summary>
<p>Vale especialmente para vagas júnior ou transição de carreira em tecnologia. Mais de 150 empresas brasileiras aceitam como qualificação válida — Boticário, Magalu, Mercado Livre, Itaú, iFood e outros. Para vagas pleno e sênior, complementa mas não substitui experiência prática consolidada.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso fazer Financial Aid em vários cursos ao mesmo tempo?</strong></summary>
<p>Sim, mas pedido por pedido. Cada curso exige aplicação individual de Financial Aid. Aprovações são analisadas separadamente. Pedir para muitos cursos simultaneamente pode reduzir a chance de aprovação em cada um, então recomenda-se solicitar por trilha ou tema de interesse focado.</p>
</details>

<details class='faq-discover'>
<summary><strong>Preciso saber inglês para fazer Coursera de graça?</strong></summary>
<p>Para Financial Aid, sim: o formulário precisa ser preenchido em inglês. Para o curso em si, depende — muitos cursos do Coursera têm legendas em português, e alguns são originalmente em português (USP, FGV, professores brasileiros). Cursos do MIT, Stanford, Princeton, Yale e Harvard são em inglês, geralmente com legenda em português.</p>
</details>

<details class='faq-discover'>
<summary><strong>O que fazer se o Financial Aid for negado?</strong></summary>
<p>Aplicar novamente em 15 dias com formulário melhorado. Detalhar mais a situação financeira, conectar o curso a objetivo concreto de carreira, escrever em inglês mais cuidadoso. Aplicações reescritas costumam ser aprovadas na segunda tentativa quando a primeira foi negada por falta de clareza.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// POST 6 — SENAI gratuito
// ════════════════════════════════════════════════════════════════════
$p6 = [
    'titulo' => 'Cursos SENAI gratuitos online em 2026: indústria 4.0, eletrotécnica, mecânica e segurança do trabalho',
    'slug'   => 'cursos-senai-gratuitos-online-2026-industria-mecanica-eletrotecnica-seguranca-trabalho',
    'metaDesc' => 'Como fazer curso SENAI gratuito online em 2026: catálogo nacional (SENAI EAD), áreas industriais (mecânica, eletrotécnica, automação, segurança do trabalho), certificado reconhecido pela indústria e como se inscrever.',
    'focusKw' => 'cursos senai gratuitos online',
    'ogUrl' => 'https://ixymyhazbhztpjnlxmbd.supabase.co/storage/v1/object/images/generated/estudante-estudando-ead-computador-918.webp',
];
$p6['html'] = <<<'HTML'
<p>Os <strong>cursos SENAI gratuitos online</strong> são uma das principais portas para qualificação industrial no Brasil. O Serviço Nacional de Aprendizagem Industrial (SENAI) oferta cursos pela plataforma SENAI EAD, com catálogo nacional voltado para áreas como indústria 4.0, mecânica, eletrotécnica, automação, segurança do trabalho e qualidade — todos com certificado reconhecido pela própria indústria como qualificação válida.</p>

<p>O SENAI é parte do Sistema S, mantido por contribuições de empresas industriais. Por isso, os cursos gratuitos não exigem critério de renda (diferente do PSG do Senac) — qualquer pessoa pode se inscrever. A taxa de matrícula é zero, sem cobrança em qualquer fase.</p>

<p>O guia abaixo cobre o catálogo SENAI online em 2026, as áreas com maior oferta, o passo a passo de inscrição e o peso do certificado SENAI no setor industrial brasileiro.</p>

<h2>Como o SENAI EAD funciona</h2>

<p>O SENAI EAD é a plataforma nacional de cursos a distância do SENAI, com oferta de cursos curtos (8 a 60 horas) gratuitos e cursos técnicos completos (1.200+ horas) com modalidade mista. A operação técnica é distribuída entre os SENAIs estaduais, cada um responsável por parte do catálogo.</p>

<ul>
  <li><strong>Modalidade:</strong> 100% online em cursos curtos; misto (online + presencial) em cursos técnicos completos;</li>
  <li><strong>Custo:</strong> cursos curtos são totalmente gratuitos. Cursos técnicos completos são gratuitos via vagas do PSI (Programa SENAI de Inclusão) ou pagos no formato regular;</li>
  <li><strong>Cadastro:</strong> único na plataforma SENAI EAD nacional ou no portal do SENAI estadual;</li>
  <li><strong>Conteúdo:</strong> videoaulas, simulações práticas (em alguns cursos técnicos), atividades online, fóruns;</li>
  <li><strong>Avaliação:</strong> testes online ao final de módulos + trabalho final quando aplicável;</li>
  <li><strong>Certificado:</strong> emitido pelo SENAI estadual responsável, com validação online.</li>
</ul>

<h2>Áreas com maior oferta no SENAI EAD em 2026</h2>

<p>O catálogo nacional cobre as principais áreas industriais brasileiras:</p>

<ul>
  <li><strong>Indústria 4.0 e automação:</strong> introdução à indústria 4.0, IoT industrial, robótica básica, sistemas embarcados;</li>
  <li><strong>Mecânica industrial:</strong> manutenção mecânica, leitura de desenho técnico, metrologia, soldagem básica, hidráulica;</li>
  <li><strong>Eletrotécnica:</strong> instalações elétricas residenciais, eletrônica básica, comandos elétricos, NR-10;</li>
  <li><strong>Segurança do trabalho:</strong> NR-5 (CIPA), NR-12 (máquinas), NR-33 (espaços confinados), NR-35 (altura), prevenção de acidentes;</li>
  <li><strong>Qualidade:</strong> ISO 9001 introdutório, ferramentas da qualidade (5S, PDCA, Kaizen), inspeção de qualidade;</li>
  <li><strong>Logística industrial:</strong> gestão de estoques, transporte, distribuição, supply chain básico;</li>
  <li><strong>Energia e eficiência:</strong> energias renováveis básicas, fotovoltaica introdutória, eficiência energética;</li>
  <li><strong>Construção civil:</strong> leitura de planta, gestão de obra, segurança em obras, fundamentos de concreto.</li>
</ul>

<h2>Cursos curtos gratuitos: 8 a 60 horas online</h2>

<p>Os cursos curtos do SENAI EAD são totalmente gratuitos e voltados a qualificação rápida. Os mais procurados em 2026:</p>

<ul>
  <li><strong>NR-12 Segurança em Máquinas e Equipamentos:</strong> 16 horas, válido para inspeção de equipamentos industriais;</li>
  <li><strong>5S no Ambiente Industrial:</strong> 8 horas, fundamentos da metodologia japonesa de organização;</li>
  <li><strong>Introdução à Indústria 4.0:</strong> 12 horas, conceitos básicos de manufatura conectada;</li>
  <li><strong>Excel Aplicado à Indústria:</strong> 24 horas, planilhas para operação fabril;</li>
  <li><strong>Hidráulica Industrial Básica:</strong> 30 horas, sistemas pneumáticos e hidráulicos;</li>
  <li><strong>Metrologia Dimensional:</strong> 20 horas, paquímetro, micrômetro, instrumentos de medida;</li>
  <li><strong>Soldagem MIG/MAG Básica:</strong> 40 horas, fundamentos do processo de soldagem.</li>
</ul>

<h2>Cursos técnicos completos do SENAI (gratuidade via PSI)</h2>

<p>Para quem quer formação técnica completa de nível médio (1.200 a 1.800 horas), o SENAI oferta vagas gratuitas via Programa SENAI de Inclusão (PSI). Os critérios:</p>

<ul>
  <li>Idade mínima de 16 anos;</li>
  <li>Ensino médio completo ou em curso;</li>
  <li>Renda familiar bruta mensal per capita até 1,5 salário mínimo;</li>
  <li>Vagas preenchidas por edital periódico com calendário estadual.</li>
</ul>

<p>Os cursos técnicos PSI mais procurados:</p>

<ul>
  <li><strong>Técnico em Mecânica:</strong> 1.400 horas, formação completa para manutenção e operação;</li>
  <li><strong>Técnico em Eletrotécnica:</strong> 1.300 horas, instalações e comandos elétricos;</li>
  <li><strong>Técnico em Segurança do Trabalho:</strong> 1.200 horas, formação para emissão de PCMSO, PCMAT e PPRA;</li>
  <li><strong>Técnico em Automação Industrial:</strong> 1.500 horas, CLP, sensores, robótica;</li>
  <li><strong>Técnico em Química:</strong> 1.300 horas, processos químicos industriais;</li>
  <li><strong>Técnico em Logística:</strong> 1.200 horas, gestão de cadeia de suprimentos.</li>
</ul>

<h2>Passo a passo: como se inscrever em curso SENAI gratuito</h2>

<p>Para cursos curtos (8-60 horas) na plataforma EAD:</p>

<ol>
  <li>Acessar senaiead.com.br ou o portal do SENAI estadual;</li>
  <li>Buscar o curso desejado por área ou palavra-chave;</li>
  <li>Verificar carga horária, ementa e pré-requisitos;</li>
  <li>Clicar em Inscrever-se. Cadastrar com CPF, nome, e-mail e celular;</li>
  <li>Acessar a plataforma e iniciar o curso imediatamente;</li>
  <li>Cumprir os módulos no próprio ritmo, fazer as avaliações;</li>
  <li>Após aprovação, baixar o certificado em PDF.</li>
</ol>

<p>Para cursos técnicos via PSI:</p>

<ol>
  <li>Acompanhar editais do SENAI estadual (sp.senai.br, ba.senai.br, mg.senai.br, etc.);</li>
  <li>Inscrever-se no período do edital com documentação:
    <ul>
      <li>RG, CPF, comprovante de residência;</li>
      <li>Comprovante de escolaridade;</li>
      <li>Comprovante de renda familiar.</li>
    </ul>
  </li>
  <li>Aguardar análise socioeconômica;</li>
  <li>Aprovados são chamados para matrícula presencial na unidade SENAI ofertante;</li>
  <li>Cumprir o cronograma do curso técnico (geralmente 2 anos, com aulas semanais).</li>
</ol>

<h2>Peso do certificado SENAI na indústria brasileira</h2>

<p>O SENAI é referência histórica em qualificação industrial brasileira. Em 2026, o certificado tem peso elevado em:</p>

<ul>
  <li><strong>Empresas industriais de grande porte:</strong> Embraer, Petrobras, WEG, Vale, Gerdau, BRF, JBS aceitam o SENAI como qualificação válida em processos seletivos;</li>
  <li><strong>Concursos públicos:</strong> conta como título quando o edital pontua qualificação profissional;</li>
  <li><strong>NR-10, NR-12, NR-33, NR-35:</strong> certificados SENAI dessas normas são aceitos por empresas para liberação de trabalho em atividades de risco;</li>
  <li><strong>Sindicatos e associações industriais:</strong> reconhecimento direto do certificado para vagas operacionais e técnicas.</li>
</ul>

<details class='faq-discover'>
<summary><strong>Os cursos curtos do SENAI são realmente gratuitos?</strong></summary>
<p>Sim, os cursos curtos (8 a 60 horas) do SENAI EAD são 100% gratuitos, sem critério de renda. Qualquer pessoa pode se inscrever via senaiead.com.br ou portal estadual. Cursos técnicos completos (1.200+ horas) são gratuitos apenas via vagas do Programa SENAI de Inclusão (PSI), com critério socioeconômico.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual a diferença entre SENAI e Senac?</strong></summary>
<p>SENAI atende indústria (mecânica, eletrotécnica, automação, segurança do trabalho industrial), enquanto Senac atende comércio e serviços (beleza, gastronomia, atendimento, saúde básica, idiomas). Ambos fazem parte do Sistema S e ofertam cursos gratuitos, mas com critérios e áreas distintas.</p>
</details>

<details class='faq-discover'>
<summary><strong>O certificado SENAI é reconhecido por empresas grandes?</strong></summary>
<p>Sim. Empresas industriais de grande porte (Embraer, Petrobras, WEG, Vale, Gerdau, BRF, JBS) reconhecem o SENAI como qualificação válida em processos seletivos para vagas técnicas e operacionais. Certificados de normas regulamentadoras (NR-10, NR-12, NR-33, NR-35) emitidos pelo SENAI são aceitos para liberação de trabalho em atividades de risco.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto tempo dura um curso técnico do SENAI?</strong></summary>
<p>Cursos técnicos completos duram entre 1.200 e 1.800 horas, geralmente em 2 anos com aulas semanais. Cursos de qualificação profissional vão de 160 a 400 horas (alguns meses). Cursos curtos online ficam entre 8 e 60 horas.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso fazer NR-10 ou NR-12 grátis no SENAI?</strong></summary>
<p>Sim. O SENAI oferta cursos online gratuitos de NR-10 (eletricidade) e NR-12 (máquinas) entre outras NRs. Os certificados SENAI dessas normas são aceitos por empresas para liberação de trabalho em atividades de risco regulamentadas pelo Ministério do Trabalho.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// Publicação batch
// ════════════════════════════════════════════════════════════════════
$slugSite = 'cursosenac';
$cfgSite = $cfg;
aplicarSite($cfgSite, $sites, $slugSite);
$wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

$schemaAuthor = ['@type' => 'Organization', 'name' => 'Redação Curso Senac Gratuito', 'url' => 'https://cursosenacgratuito.com.br'];
$schemaPublisher = ['@type' => 'Organization', 'name' => 'Curso Senac Gratuito', 'url' => 'https://cursosenacgratuito.com.br'];

$posts = [$pHub, $p2, $p3, $p4, $p5, $p6];
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
    $tagIds = $wp->resolverTags(['Curso EAD', 'Curso Gratuito', 'Certificado', 'Senac', 'SENAI', 'IFs', 'FGV Online', 'Coursera', 'Capacitação Profissional']);

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

echo "\n══════ FIM CLUSTER CURSO EAD ══════\n";
