<?php
declare(strict_types=1);
/**
 * Mega-batch 6 posts cursosenac: Cluster A (SENAI 3 posts) + Cluster B (Senac 3 posts).
 * Hub + 2 sub-posts cada. Manifesto-compliant.
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';
$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

// ════════════════════════════════════════════════════════════════════
// CLUSTER A — SENAI
// ════════════════════════════════════════════════════════════════════

$pA1 = [
    'titulo' => 'Cursos SENAI em 2026: catálogo completo de áreas industriais, NRs e técnicos com certificado',
    'slug'   => 'cursos-senai-2026-catalogo-areas-industriais-nrs-tecnicos-com-certificado',
    'metaDesc' => 'Catálogo completo dos cursos SENAI em 2026: áreas industriais (mecânica, eletrotécnica, automação, química), normas regulamentadoras (NR-10, NR-12, NR-33, NR-35), cursos técnicos e como se inscrever.',
    'focusKw' => 'cursos senai',
    'ogUrl' => 'https://senaies.com.br/wp-content/uploads/2020/01/Senai-Braco-mecanico-utilizado-nos-cursos-de-Automacao-e-Robotica-scaled-1.jpg',
];
$pA1['html'] = <<<'HTML'
<p>O <strong>Serviço Nacional de Aprendizagem Industrial (SENAI)</strong> é a maior rede de educação profissional industrial da América Latina. Em 2026, o catálogo de cursos SENAI cobre desde formação inicial de curta duração (8 a 60 horas) até cursos técnicos completos de nível médio (1.200 a 1.800 horas), distribuídos em 28 áreas industriais e em todos os estados brasileiros.</p>

<p>A maior parte dos cursos curtos online é gratuita e aberta a qualquer interessado, sem critério de renda. Cursos técnicos completos têm vagas gratuitas via Programa SENAI de Inclusão (PSI) com critério socioeconômico. Cursos de qualificação profissional de média duração (160-400 horas) também aparecem gratuitos em editais periódicos.</p>

<p>O guia abaixo cobre o catálogo do SENAI por área, as principais Normas Regulamentadoras (NRs) ofertadas, os cursos técnicos de maior procura e o passo a passo para se inscrever pela plataforma SENAI EAD ou pela unidade estadual.</p>

<h2>Estrutura nacional do SENAI: como o sistema funciona</h2>

<p>O SENAI é mantido por contribuições compulsórias das empresas industriais via Sistema S, criado pelo Decreto-Lei 4.048/1942. Cada um dos 27 estados tem um Departamento Regional autônomo, que define oferta local e calendário próprio dentro de diretrizes nacionais. Por isso o catálogo varia ligeiramente entre estados — mas o tronco principal é nacional.</p>

<p>Os 4 níveis de oferta:</p>

<ul>
  <li><strong>Cursos livres (8-60h):</strong> totalmente online, gratuitos, sem critério de renda. Certificado de extensão SENAI;</li>
  <li><strong>Qualificação profissional (160-400h):</strong> presencial em unidade SENAI estadual, com vagas gratuitas via editais. Certificado de qualificação profissional;</li>
  <li><strong>Técnico (1.200-1.800h):</strong> formação técnica de nível médio em 2 anos, gratuita via PSI ou paga em formato regular. Diploma de Técnico em Nível Médio com reconhecimento nacional;</li>
  <li><strong>Aprendizagem industrial (1-2 anos):</strong> formação para jovens via Lei do Aprendiz (10.097/2000), com bolsa e contratação obrigatória pelas empresas industriais.</li>
</ul>

<h2>As 8 áreas industriais com maior oferta de cursos SENAI</h2>

<ul>
  <li><strong>Mecânica e manutenção:</strong> mecânica automotiva, industrial, de motos, de máquinas pesadas, manutenção mecânica, leitura de desenho técnico, metrologia, soldagem (MIG/MAG, eletrodo, TIG);</li>
  <li><strong>Eletrotécnica e eletricidade:</strong> instalações elétricas residenciais e industriais, eletrônica, comandos elétricos, redes de baixa tensão, fotovoltaica básica;</li>
  <li><strong>Automação e indústria 4.0:</strong> IoT industrial, robótica, CLP, sensores, sistemas embarcados, controle de processos;</li>
  <li><strong>Segurança do trabalho:</strong> técnico em segurança do trabalho (1.200h), Normas Regulamentadoras (NR-5, NR-10, NR-12, NR-13, NR-33, NR-35), prevenção de acidentes, primeiros socorros industriais;</li>
  <li><strong>Química e biotecnologia:</strong> técnico em química, análises clínicas básicas, biotecnologia industrial;</li>
  <li><strong>Construção civil:</strong> técnico em edificações, leitura de planta, gestão de obra, segurança em obras, fundamentos de concreto e fundações;</li>
  <li><strong>Refrigeração e climatização:</strong> mecânica de refrigeração, ar condicionado split, sistemas centrais, NR-13 (caldeiras e vasos);</li>
  <li><strong>Tecnologia da informação industrial:</strong> programação para automação, redes industriais, segurança de sistemas industriais, manutenção de equipamentos eletrônicos.</li>
</ul>

<h2>Cursos técnicos SENAI mais procurados em 2026</h2>

<p>Os 7 cursos técnicos completos com maior demanda nacional, todos com diploma de Técnico em Nível Médio reconhecido pelo MEC:</p>

<ul>
  <li><strong>Técnico em Segurança do Trabalho:</strong> 1.200h, habilita o profissional a emitir documentos legais (PCMSO, PCMAT, PPRA, mapas de risco), forte demanda em indústria, construção e logística;</li>
  <li><strong>Técnico em Mecânica:</strong> 1.400h, manutenção e operação de equipamentos industriais, base para soldadura, usinagem e manutenção de máquinas pesadas;</li>
  <li><strong>Técnico em Eletrotécnica:</strong> 1.300h, instalações industriais, comandos elétricos, projeto e execução em baixa e média tensão;</li>
  <li><strong>Técnico em Automação Industrial:</strong> 1.500h, CLP, sensores, robótica, programação de PLCs, manutenção de linhas automatizadas;</li>
  <li><strong>Técnico em Edificações:</strong> 1.500h, projeto de planta, gestão de obra, leitura técnica, fundamentos de cálculo estrutural;</li>
  <li><strong>Técnico em Química:</strong> 1.300h, processos químicos industriais, controle de qualidade, análises laboratoriais;</li>
  <li><strong>Técnico em Refrigeração e Climatização:</strong> 1.400h, instalação e manutenção de sistemas de ar condicionado industrial e comercial.</li>
</ul>

<h2>Como acessar cursos SENAI online gratuitos</h2>

<p>O catálogo nacional de cursos curtos online está na plataforma SENAI EAD (senaiead.com.br) e nos portais estaduais. O processo:</p>

<ol>
  <li>Acessar senaiead.com.br ou o portal do SENAI do seu estado (sp.senai.br, ba.senai.br, mg.senai.br, etc.);</li>
  <li>Cadastrar-se com CPF, e-mail e celular. Não há critério de renda para os cursos curtos;</li>
  <li>Navegar pelo catálogo por área ou usar busca por palavra-chave;</li>
  <li>Selecionar o curso, conferir carga horária, ementa e pré-requisitos (a maioria não tem);</li>
  <li>Inscrever-se. O acesso é imediato;</li>
  <li>Cursar no próprio ritmo. Cumprir avaliações com aproveitamento mínimo;</li>
  <li>Baixar o certificado em PDF após aprovação. O documento tem QR Code de validação online.</li>
</ol>

<h2>Como acessar cursos técnicos SENAI gratuitos via PSI</h2>

<p>O Programa SENAI de Inclusão (PSI) oferta vagas gratuitas em cursos técnicos completos para candidatos com perfil socioeconômico definido. Critérios e processo:</p>

<ul>
  <li><strong>Idade mínima:</strong> 16 anos;</li>
  <li><strong>Escolaridade:</strong> ensino médio completo ou em curso;</li>
  <li><strong>Renda familiar:</strong> bruta mensal per capita até 1,5 salário mínimo;</li>
  <li><strong>Inscrição:</strong> por edital estadual periódico, com prazo de 15 a 30 dias;</li>
  <li><strong>Seleção:</strong> análise socioeconômica + análise de histórico escolar ou prova específica conforme o estado;</li>
  <li><strong>Documentação:</strong> RG, CPF, comprovante de residência, escolaridade e renda familiar.</li>
</ul>

<h2>Cursos por valor: quanto custa o que não é gratuito no SENAI</h2>

<p>Cursos pagos do SENAI seguem precificação por área e carga horária:</p>

<ul>
  <li>Cursos curtos online pagos: R$ 100 a R$ 500;</li>
  <li>Qualificação profissional presencial (160-400h): R$ 800 a R$ 2.500;</li>
  <li>Técnico completo presencial regular (1.200-1.800h): R$ 5.000 a R$ 15.000 totais (com possibilidade de parcelamento);</li>
  <li>Cursos para empresas (in-company): orçados individualmente.</li>
</ul>

<details class='faq-discover'>
<summary><strong>Os cursos SENAI são gratuitos para qualquer pessoa?</strong></summary>
<p>Os cursos curtos online (8-60h) na plataforma SENAI EAD são totalmente gratuitos, sem critério de renda. Cursos técnicos completos (1.200h+) têm vagas gratuitas apenas via Programa SENAI de Inclusão (PSI), com critério socioeconômico (renda familiar per capita até 1,5 salário mínimo). Cursos de qualificação presencial podem ser gratuitos via editais periódicos ou pagos no formato regular.</p>
</details>

<details class='faq-discover'>
<summary><strong>O certificado do SENAI é reconhecido pelo MEC?</strong></summary>
<p>Os cursos técnicos completos do SENAI têm diploma de Técnico em Nível Médio reconhecido pelo MEC, com validade nacional. Cursos curtos e de qualificação profissional emitem certificado SENAI sem necessidade de reconhecimento MEC, com forte reconhecimento direto no mercado industrial brasileiro.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto tempo dura um curso técnico do SENAI?</strong></summary>
<p>Cursos técnicos completos do SENAI duram entre 1.200 e 1.800 horas, geralmente em 2 anos com aulas semanais. Cursos de qualificação profissional vão de 160 a 400 horas (alguns meses). Cursos curtos online ficam entre 8 e 60 horas.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso fazer curso SENAI online com certificado gratuito?</strong></summary>
<p>Sim. A plataforma SENAI EAD (senaiead.com.br) oferta dezenas de cursos curtos online totalmente gratuitos com certificado SENAI emitido em PDF com QR Code de validação. Áreas mais populares: NR-12 segurança em máquinas, 5S no ambiente industrial, introdução à indústria 4.0, Excel aplicado, hidráulica básica.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual a diferença entre SENAI e Senac?</strong></summary>
<p>SENAI atende a indústria (mecânica, eletrotécnica, automação, química, segurança industrial), enquanto Senac atende comércio e serviços (beleza, gastronomia, atendimento, saúde básica, idiomas). Ambos fazem parte do Sistema S e ofertam cursos gratuitos.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

$pA2 = [
    'titulo' => 'Cursos SENAI das Normas Regulamentadoras em 2026: NR-10, NR-12, NR-13, NR-33 e NR-35 com certificado',
    'slug'   => 'cursos-senai-nrs-2026-nr10-nr12-nr13-nr33-nr35-seguranca-trabalho-certificado',
    'metaDesc' => 'Cursos SENAI das principais Normas Regulamentadoras em 2026: NR-10 (eletricidade), NR-12 (máquinas), NR-13 (caldeiras), NR-33 (espaço confinado) e NR-35 (altura). Carga horária, validade e como se inscrever.',
    'focusKw' => 'curso senai nr',
    'ogUrl' => 'https://onsafety.com.br/wp-content/uploads/2024/10/Capa-p-Blog-2024-14.jpg',
];
$pA2['html'] = <<<'HTML'
<p>Os <strong>cursos SENAI das Normas Regulamentadoras (NRs)</strong> são exigência operacional em praticamente toda indústria brasileira. Trabalhadores que executam atividades com eletricidade (NR-10), máquinas (NR-12), caldeiras e vasos de pressão (NR-13), espaço confinado (NR-33) ou trabalho em altura (NR-35) precisam de certificado válido para serem autorizados pelo empregador. O SENAI é a instituição mais aceita pelo mercado industrial brasileiro para emissão desse tipo de capacitação.</p>

<p>Em 2026, o SENAI oferta cursos das principais NRs em três formatos: online gratuito (cursos curtos introdutórios), online pago (cursos completos com prática simulada) e presencial (com parte prática obrigatória em laboratório, exigência da maioria das NRs). A escolha do formato depende da Norma específica e da carga horária mínima exigida pela legislação.</p>

<p>O guia abaixo cobre as 5 NRs mais procuradas, a carga horária legal de cada uma, o que diferencia treinamento inicial de reciclagem, a validade do certificado e o passo a passo para se inscrever pela SENAI EAD ou pela unidade estadual.</p>

<h2>O que são Normas Regulamentadoras e por que o curso é obrigatório</h2>

<p>As Normas Regulamentadoras são regulamentos do Ministério do Trabalho que estabelecem requisitos mínimos de saúde e segurança em atividades específicas. A primeira NR foi publicada em 1978; em 2026, são 38 NRs vigentes cobrindo diferentes tipos de trabalho e risco.</p>

<p>O empregador tem responsabilidade legal de fornecer ou contratar treinamento adequado para os trabalhadores. O descumprimento gera autuação trabalhista, embargo da atividade e responsabilização civil/criminal em caso de acidente. Por isso o certificado das NRs é demanda operacional permanente da indústria.</p>

<h2>NR-10: Segurança em Instalações e Serviços em Eletricidade</h2>

<p>A NR-10 regula trabalho com eletricidade em todas as etapas — geração, transmissão, distribuição e consumo. Aplicação direta em eletricistas, engenheiros eletricistas, técnicos em eletrotécnica e qualquer profissional que execute serviços com energia elétrica.</p>

<ul>
  <li><strong>Carga horária mínima legal:</strong> 40 horas (curso básico inicial); 80 horas para Sistema Elétrico de Potência (SEP);</li>
  <li><strong>Validade do certificado:</strong> 2 anos (reciclagem obrigatória após esse período);</li>
  <li><strong>Conteúdo:</strong> riscos elétricos, medidas de proteção coletiva e individual, normas técnicas, primeiros socorros, análise de risco;</li>
  <li><strong>Formato no SENAI:</strong> online + prática presencial obrigatória conforme estado;</li>
  <li><strong>Custo:</strong> versão básica online costuma estar entre gratuita e R$ 400; versão completa presencial varia de R$ 600 a R$ 1.500.</li>
</ul>

<h2>NR-12: Segurança no Trabalho em Máquinas e Equipamentos</h2>

<p>A NR-12 trata da segurança operacional em máquinas e equipamentos industriais. Aplicação em quem opera, mantém, instala ou inspeciona máquinas — operadores de produção, mecânicos, técnicos industriais.</p>

<ul>
  <li><strong>Carga horária mínima:</strong> 8 horas (introdutório) a 16-40 horas (operacional avançado);</li>
  <li><strong>Validade:</strong> 2 anos;</li>
  <li><strong>Conteúdo:</strong> riscos em máquinas, sistemas de segurança, dispositivos de proteção, intertravamento, procedimentos seguros;</li>
  <li><strong>Formato no SENAI:</strong> versão introdutória 8h é gratuita online; versão completa 40h pode ser presencial ou misto;</li>
  <li><strong>Custo:</strong> gratuita a R$ 600 dependendo da carga e modalidade.</li>
</ul>

<h2>NR-13: Caldeiras, Vasos de Pressão, Tubulações e Tanques</h2>

<p>A NR-13 regula trabalho com equipamentos pressurizados — caldeiras industriais, vasos de pressão, tubulações de gás e tanques. Exigência operacional para operadores, mantenedores, inspetores e responsáveis técnicos desses equipamentos.</p>

<ul>
  <li><strong>Carga horária mínima:</strong> 40 horas para operador iniciante; 100+ horas para inspetor;</li>
  <li><strong>Validade:</strong> 3 anos para operador; varia para inspetor;</li>
  <li><strong>Conteúdo:</strong> tipos de equipamentos pressurizados, riscos específicos, manobras seguras, inspeção de segurança, legislação;</li>
  <li><strong>Formato no SENAI:</strong> predominantemente presencial pela exigência de prática em equipamentos reais;</li>
  <li><strong>Custo:</strong> R$ 800 a R$ 2.500 conforme nível e estado.</li>
</ul>

<h2>NR-33: Segurança e Saúde nos Trabalhos em Espaços Confinados</h2>

<p>A NR-33 regula atividades em espaços confinados (tanques, silos, túneis, galerias, esgotos). Aplicação em qualquer trabalhador que adentra esses ambientes, supervisor e vigia de espaço confinado.</p>

<ul>
  <li><strong>Carga horária mínima:</strong> 16 horas (trabalhador autorizado); 40 horas (supervisor de entrada);</li>
  <li><strong>Validade:</strong> 12 meses (reciclagem anual obrigatória);</li>
  <li><strong>Conteúdo:</strong> identificação de espaços confinados, riscos, equipamentos de proteção, monitoramento atmosférico, plano de emergência;</li>
  <li><strong>Formato no SENAI:</strong> presencial com prática obrigatória em ambiente simulado;</li>
  <li><strong>Custo:</strong> R$ 400 a R$ 1.200.</li>
</ul>

<h2>NR-35: Trabalho em Altura</h2>

<p>A NR-35 regula trabalho realizado acima de 2 metros do solo com risco de queda. Aplicação ampla — construção civil, manutenção predial, instalação de telhados, antenas, painéis solares, podas de árvores, etc.</p>

<ul>
  <li><strong>Carga horária mínima:</strong> 8 horas (trabalhador autorizado);</li>
  <li><strong>Validade:</strong> 2 anos;</li>
  <li><strong>Conteúdo:</strong> identificação de riscos, equipamentos de proteção individual (cinto, talabarte, capacete), sistemas de ancoragem, análise preliminar de risco;</li>
  <li><strong>Formato no SENAI:</strong> teoria online + prática presencial obrigatória em estrutura específica;</li>
  <li><strong>Custo:</strong> gratuita a R$ 500 dependendo de modalidade e região.</li>
</ul>

<h2>Reciclagem das NRs: por que e quando refazer</h2>

<p>Quase todas as NRs exigem reciclagem periódica, com carga horária e prazo definidos:</p>

<ul>
  <li><strong>NR-10:</strong> reciclagem bienal de 40 horas;</li>
  <li><strong>NR-12:</strong> reciclagem bienal ou em caso de modificação significativa do equipamento;</li>
  <li><strong>NR-13:</strong> reciclagem trienal de operador;</li>
  <li><strong>NR-33:</strong> reciclagem anual obrigatória;</li>
  <li><strong>NR-35:</strong> reciclagem bienal ou imediata em caso de mudança de função, acidente ou ausência prolongada.</li>
</ul>

<p>Trabalhar com certificado vencido em qualquer NR equivale a trabalhar sem treinamento — empregador autuado e trabalhador exposto a risco legal e operacional.</p>

<h2>Passo a passo: como conseguir certificado SENAI de NR</h2>

<ol>
  <li>Identificar a NR exigida para o trabalho (consultar com empregador ou Comissão Interna de Prevenção de Acidentes - CIPA);</li>
  <li>Acessar senaiead.com.br ou o portal do SENAI estadual;</li>
  <li>Buscar a NR específica (NR-10, NR-12, etc.) e verificar modalidades disponíveis;</li>
  <li>Confirmar carga horária mínima exigida pela legislação para a função pretendida;</li>
  <li>Inscrever-se na modalidade adequada (online gratuita para versão introdutória, presencial para versões com prática);</li>
  <li>Cumprir o conteúdo teórico + prática presencial quando exigida;</li>
  <li>Realizar a avaliação final;</li>
  <li>Baixar certificado SENAI com QR Code, número de série e indicação clara da NR e carga horária;</li>
  <li>Apresentar o certificado ao empregador para autorização formal de exercer a função.</li>
</ol>

<details class='faq-discover'>
<summary><strong>Os cursos de NR do SENAI são gratuitos?</strong></summary>
<p>A versão introdutória online (geralmente 8 a 16 horas) costuma ser gratuita na plataforma SENAI EAD. Versões com carga horária completa exigida pela legislação (NR-10 com 40h, NR-33 com 16h, etc.) e prática presencial são pagas. A versão gratuita serve para conhecimento inicial; para autorização legal de exercer a função, a maioria dos empregadores exige a versão completa com carga horária integral.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso fazer NR-10 totalmente online no SENAI?</strong></summary>
<p>A parte teórica pode ser online; a prática é presencial obrigatória pela legislação, em laboratório ou em ambiente similar ao de trabalho. Cursos totalmente online sem prática não atendem ao requisito legal da NR-10 para autorização do empregador.</p>
</details>

<details class='faq-discover'>
<summary><strong>O certificado de NR do SENAI vence?</strong></summary>
<p>Sim. Quase todas as NRs exigem reciclagem periódica: NR-10 e NR-12 a cada 2 anos, NR-33 anual, NR-13 trienal para operador, NR-35 bienal. Trabalhar com certificado vencido equivale a trabalhar sem treinamento, com risco de autuação trabalhista para a empresa.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual NR é a mais procurada na indústria brasileira?</strong></summary>
<p>NR-10 (eletricidade) tem maior demanda quantitativa, seguida por NR-35 (trabalho em altura) e NR-12 (máquinas). NR-33 (espaço confinado) e NR-13 (caldeiras) têm volume menor mas alta especialização — quem tem essas certificações é mais valorizado pelo nicho de atuação.</p>
</details>

<details class='faq-discover'>
<summary><strong>O empregador é obrigado a pagar o curso de NR?</strong></summary>
<p>Sim. As NRs são regulamentos do Ministério do Trabalho que estabelecem dever do empregador de fornecer treinamento adequado aos trabalhadores. O empregador pode contratar o curso (na unidade SENAI ou em escola credenciada), oferecer treinamento próprio com instrutor qualificado, ou cobrir o custo do treinamento que o funcionário fizer externamente — mas não pode descontar do salário.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional. Verificar legislação vigente do Ministério do Trabalho para confirmar requisitos atualizados.</em></p>
HTML;

$pA3 = [
    'titulo' => 'Cursos técnicos do SENAI gratuitos via PSI em 2026: requisitos, áreas e como se inscrever no Programa SENAI de Inclusão',
    'slug'   => 'cursos-tecnicos-senai-gratuitos-psi-2026-programa-inclusao-requisitos-areas',
    'metaDesc' => 'Como conseguir vaga gratuita em curso técnico completo do SENAI via Programa SENAI de Inclusão (PSI) em 2026: requisitos socioeconômicos, áreas (mecânica, eletrotécnica, automação, segurança), documentação e seleção.',
    'focusKw' => 'curso técnico senai gratuito psi',
    'ogUrl' => 'https://cms.fiemt.ind.br/arquivos/senai/images/Senai%20Lab%20(2).jpeg',
];
$pA3['html'] = <<<'HTML'
<p>O <strong>Programa SENAI de Inclusão (PSI)</strong> é o caminho oficial para cursar gratuitamente formação técnica completa de nível médio no SENAI em 2026. Cursos técnicos de 1.200 a 1.800 horas, em 2 anos, com diploma de Técnico em Nível Médio reconhecido pelo MEC — todos cobertos pelo Sistema S para candidatos com perfil socioeconômico definido.</p>

<p>As vagas do PSI são preenchidas por edital periódico de cada SENAI estadual, com calendário próprio. O critério principal é renda familiar bruta mensal per capita igual ou inferior a 1,5 salário mínimo. Não há restrição de idade exceto a mínima legal (16 anos para cursos técnicos) e a escolaridade compatível com cada curso.</p>

<p>O guia abaixo cobre os requisitos completos do PSI, as áreas técnicas com maior oferta, a documentação necessária, o processo de seleção e o que esperar do curso a partir da aprovação.</p>

<h2>O que é o Programa SENAI de Inclusão (PSI)</h2>

<p>O PSI é uma política nacional do SENAI que reserva parte das vagas de seus cursos técnicos para candidatos de baixa renda. O programa é financiado pelas contribuições compulsórias da indústria via Sistema S, criado em 1942. Para o candidato aprovado, o curso é 100% gratuito — sem cobrança de matrícula, mensalidade, material didático ou taxa de certificação.</p>

<p>O programa atende preferencialmente:</p>

<ul>
  <li>Pessoas em situação de vulnerabilidade socioeconômica;</li>
  <li>Trabalhadores da indústria que querem qualificação formal;</li>
  <li>Jovens em primeiro emprego (15-24 anos);</li>
  <li>Pessoas com deficiência (PcD), com vagas reservadas em alguns estados;</li>
  <li>Mulheres em áreas tradicionalmente masculinas (mecânica, eletrotécnica, automação).</li>
</ul>

<h2>Requisitos socioeconômicos do PSI em 2026</h2>

<p>Os critérios nacionais (com pequenas variações estaduais):</p>

<ul>
  <li><strong>Idade mínima:</strong> 16 anos para cursos técnicos;</li>
  <li><strong>Escolaridade:</strong> ensino médio em curso ou concluído (depende do curso técnico);</li>
  <li><strong>Renda familiar:</strong> bruta mensal per capita igual ou inferior a 1,5 salário mínimo. Em 2026, com SM nacional de R$ 1.621, o limite é de aproximadamente R$ 2.432 por pessoa da família;</li>
  <li><strong>Composição familiar:</strong> considera todos os moradores do domicílio, incluindo dependentes;</li>
  <li><strong>Comprovação:</strong> contracheques recentes (3 meses), comprovante de benefício social (Bolsa Família, Auxílio Brasil), declaração de autônomo ou declaração negativa de renda.</li>
</ul>

<h2>Áreas técnicas com vagas PSI consistentes em 2026</h2>

<p>O catálogo varia por estado, mas as áreas com oferta nacional regular são:</p>

<ul>
  <li><strong>Técnico em Segurança do Trabalho:</strong> 1.200 horas. Forma profissional para emissão de PCMSO, PCMAT e PPRA, com alta demanda em indústria, construção e logística;</li>
  <li><strong>Técnico em Mecânica:</strong> 1.400 horas. Manutenção e operação industrial, soldagem, usinagem, manutenção de equipamentos pesados;</li>
  <li><strong>Técnico em Eletrotécnica:</strong> 1.300 horas. Instalações industriais, comandos elétricos, automação básica;</li>
  <li><strong>Técnico em Automação Industrial:</strong> 1.500 horas. CLP, sensores, robótica, programação de PLCs;</li>
  <li><strong>Técnico em Edificações:</strong> 1.500 horas. Projeto, gestão de obra, leitura técnica, fundamentos estruturais;</li>
  <li><strong>Técnico em Química:</strong> 1.300 horas. Processos químicos industriais, controle de qualidade, análises laboratoriais;</li>
  <li><strong>Técnico em Refrigeração e Climatização:</strong> 1.400 horas. Instalação e manutenção de sistemas;</li>
  <li><strong>Técnico em Logística:</strong> 1.200 horas. Gestão de cadeia de suprimentos, transportes, armazenagem.</li>
</ul>

<h2>Documentação necessária para inscrição</h2>

<ul>
  <li>RG e CPF do candidato;</li>
  <li>Comprovante de residência atualizado (até 3 meses);</li>
  <li>Comprovante de escolaridade (histórico do ensino médio ou declaração de matrícula);</li>
  <li>Comprovantes de renda de todos os membros da família:
    <ul>
      <li>Contracheques dos últimos 3 meses (para trabalhadores formais);</li>
      <li>Declaração de Imposto de Renda ou declaração de isento;</li>
      <li>Declaração de autônomo (formulário próprio do SENAI);</li>
      <li>Comprovante de benefícios sociais (Bolsa Família, Auxílio Brasil, BPC, etc.);</li>
    </ul>
  </li>
  <li>Foto 3x4 recente;</li>
  <li>Comprovante de inscrição no CadÚnico (quando aplicável);</li>
  <li>Laudo médico para vagas reservadas a Pessoa com Deficiência (PcD).</li>
</ul>

<h2>Passo a passo: como se inscrever no PSI</h2>

<ol>
  <li><strong>Identificar editais abertos:</strong> acessar o portal do SENAI estadual (sp.senai.br, ba.senai.br, mg.senai.br, etc.) e procurar a seção PSI ou Cursos Gratuitos. Editais costumam abrir nos meses de janeiro, abril, julho e outubro;</li>
  <li><strong>Escolher o curso:</strong> conferir áreas disponíveis, carga horária, unidade ofertante e cronograma de aulas (presencial ou misto);</li>
  <li><strong>Preencher o formulário online:</strong> dados pessoais, escolaridade, composição familiar, renda mensal de cada membro, motivação para o curso;</li>
  <li><strong>Anexar documentação:</strong> upload de RG, CPF, comprovantes de residência, escolaridade e renda. Algumas unidades pedem entrega presencial dos originais;</li>
  <li><strong>Análise socioeconômica:</strong> o SENAI analisa renda per capita e perfil. Resultado preliminar em 5-15 dias;</li>
  <li><strong>Análise pedagógica:</strong> em alguns cursos, análise de histórico escolar ou prova específica. Em outros, sorteio entre os aprovados na análise socioeconômica;</li>
  <li><strong>Resultado final:</strong> publicado no portal. Aprovados recebem instruções para matrícula presencial na unidade SENAI ofertante;</li>
  <li><strong>Matrícula e início:</strong> apresentar originais da documentação, assinar termo de compromisso (presença mínima 75%, aproveitamento) e iniciar o curso na data prevista pelo cronograma.</li>
</ol>

<h2>O que esperar do curso após aprovação</h2>

<ul>
  <li><strong>Duração:</strong> 2 anos, com aulas semanais (geralmente noturnas para atender quem trabalha durante o dia);</li>
  <li><strong>Carga horária:</strong> entre 1.200 e 1.800 horas, distribuídas em disciplinas teóricas + práticas em laboratório + estágio supervisionado obrigatório;</li>
  <li><strong>Frequência:</strong> presença mínima 75% em todas as disciplinas;</li>
  <li><strong>Aproveitamento:</strong> média mínima por disciplina (geralmente 60% ou 6,0);</li>
  <li><strong>Material didático:</strong> apostilas, equipamentos de laboratório e ferramentas básicas fornecidas pelo SENAI;</li>
  <li><strong>Estágio supervisionado:</strong> obrigatório na maioria dos cursos (200-400 horas), em empresas conveniadas;</li>
  <li><strong>Diploma:</strong> Técnico em Nível Médio reconhecido pelo MEC, com validade nacional, após conclusão de todas as exigências.</li>
</ul>

<details class='faq-discover'>
<summary><strong>Quem pode fazer curso técnico do SENAI grátis em 2026?</strong></summary>
<p>Pelo Programa SENAI de Inclusão (PSI), candidatos com renda familiar per capita até 1,5 salário mínimo (cerca de R$ 2.432 por pessoa em 2026), idade mínima 16 anos e escolaridade compatível com o curso técnico desejado. A seleção ocorre por edital periódico de cada SENAI estadual.</p>
</details>

<details class='faq-discover'>
<summary><strong>O curso técnico do PSI tem mensalidade?</strong></summary>
<p>Não. O PSI é 100% gratuito para o aluno aprovado — sem matrícula, mensalidade, material didático ou taxa de certificação. O programa é financiado pelas contribuições compulsórias da indústria via Sistema S.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto tempo demora a análise do PSI?</strong></summary>
<p>Análise socioeconômica costuma sair em 5 a 15 dias após o encerramento do edital. Análise pedagógica (quando aplicável) leva mais 5 a 10 dias. Resultado final publicado em até 30 dias após o encerramento da inscrição.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso fazer técnico do SENAI online via PSI?</strong></summary>
<p>Cursos técnicos completos do SENAI são predominantemente presenciais ou em modalidade mista (online + prática presencial em laboratório), pela exigência legal de prática supervisionada. Em alguns estados específicos há ofertas EAD de áreas mais teóricas (Logística, Administração), mas a maioria exige presencial.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso me inscrever em mais de um curso PSI no mesmo edital?</strong></summary>
<p>Cada edital define suas regras. Em geral, o candidato pode se inscrever em até 2 cursos no mesmo edital, indicando ordem de preferência. Aprovação no primeiro curso preferencial automaticamente cancela inscrição nos demais.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// CLUSTER B — Senac cursos
// ════════════════════════════════════════════════════════════════════

$pB1 = [
    'titulo' => 'Cursos Senac em 2026: catálogo de 8 áreas com cursos profissionalizantes, gratuitos e pagos',
    'slug'   => 'cursos-senac-2026-catalogo-8-areas-profissionalizantes-gratuitos-pagos',
    'metaDesc' => 'Catálogo completo dos cursos Senac em 2026: 8 áreas (beleza, gastronomia, saúde, idiomas, gestão, moda, tecnologia, hospitalidade), modalidades, certificados e como se inscrever.',
    'focusKw' => 'cursos senac',
    'ogUrl' => 'https://ogimg.infoglobo.com.br/in/19851608-3b1-91e/FT1086A/L2P-Moda-materia-2.png',
];
$pB1['html'] = <<<'HTML'
<p>O <strong>Senac (Serviço Nacional de Aprendizagem Comercial)</strong> é referência nacional em educação profissional para os setores de comércio, serviços e turismo. Em 2026, o catálogo cobre 8 grandes áreas, com cursos do curtíssimo prazo (aulas de 4 horas) a cursos técnicos completos de 1.200+ horas. A maioria das ofertas é paga, mas o Programa Senac de Gratuidade (PSG) reserva uma parte significativa das vagas para candidatos de baixa renda.</p>

<p>O Senac integra o Sistema S junto com SENAI, Sesi, Sesc, Sebrae e outras instituições. É mantido por contribuições compulsórias das empresas de comércio e serviços. Por isso, o "gratuito" no Senac significa custo zero para o aluno aprovado — a operação é custeada pelo setor empresarial via Sistema S.</p>

<p>O guia abaixo cobre as 8 áreas com maior catálogo, exemplos de cursos por área, modalidades disponíveis (presencial, EAD, livre, profissionalizante, técnico) e como acessar cada categoria de curso.</p>

<h2>Beleza e estética</h2>

<p>Área com maior oferta de cursos curtos no Senac. Forte demanda de mercado em todas as regiões brasileiras, com forte empregabilidade direta após formação. Cursos típicos:</p>

<ul>
  <li><strong>Manicure e pedicure:</strong> 60-160 horas, base para abertura de salão próprio ou trabalho terceirizado;</li>
  <li><strong>Cabeleireiro:</strong> 200-400 horas, formação completa com corte, química, finalização, escova progressiva;</li>
  <li><strong>Design de sobrancelhas:</strong> 40-80 horas, técnica específica de alta demanda;</li>
  <li><strong>Depilação:</strong> 60-120 horas, com aulas práticas obrigatórias;</li>
  <li><strong>Maquiagem profissional:</strong> 80-200 horas, para atuação em salão, eventos ou freelance;</li>
  <li><strong>Especialização em alongamento de cílios:</strong> 40-60 horas, técnica específica;</li>
  <li><strong>Visagismo:</strong> 60-120 horas, análise de tipo facial e harmonização de corte.</li>
</ul>

<h2>Gastronomia e alimentação</h2>

<p>Segunda maior área em volume. Senac é referência consolidada em gastronomia, com algumas unidades famosas (Águas de São Pedro, Santos Dumont, Tutoia em São Paulo). Cursos:</p>

<ul>
  <li><strong>Cozinheiro:</strong> 200-400 horas, formação base para profissão em restaurantes;</li>
  <li><strong>Confeitaria básica e avançada:</strong> 80-200 horas, com módulos específicos (bolos, doces finos, panificação artesanal);</li>
  <li><strong>Panificação:</strong> 120-240 horas, técnica de produção de pães, fermentação natural;</li>
  <li><strong>Garçom e atendente de restaurante:</strong> 80-160 horas, serviço de salão, atendimento;</li>
  <li><strong>Sommelier:</strong> 100-200 horas, harmonização e serviço de vinhos;</li>
  <li><strong>Cozinha asiática, italiana, francesa:</strong> 60-120 horas cada, técnicas específicas;</li>
  <li><strong>Especialização em hambúrguer gourmet, churrasco, comida fitness:</strong> 40-80 horas cada, atende novos nichos.</li>
</ul>

<h2>Saúde e bem-estar</h2>

<p>Área em crescimento, especialmente cuidador de idosos pelo envelhecimento populacional brasileiro. Cursos:</p>

<ul>
  <li><strong>Cuidador de idosos:</strong> 160 horas, formação básica para atuar com idosos em domicílio ou casas de repouso;</li>
  <li><strong>Cuidador infantil:</strong> 80-160 horas, alternativa para quem prefere atender crianças;</li>
  <li><strong>Auxiliar de farmácia:</strong> 240-400 horas, com farmacologia básica e dispensação;</li>
  <li><strong>Recepcionista de consultório médico:</strong> 80-160 horas;</li>
  <li><strong>Técnico em enfermagem:</strong> 1.800 horas em 2 anos (curso técnico completo), com prática hospitalar supervisionada;</li>
  <li><strong>Auxiliar de saúde bucal:</strong> 800-1.200 horas;</li>
  <li><strong>Massoterapia:</strong> 240-480 horas, formação para massagista profissional.</li>
</ul>

<h2>Idiomas</h2>

<p>Senac mantém escolas de idiomas em todas as capitais. Oferta:</p>

<ul>
  <li><strong>Inglês básico, intermediário e avançado:</strong> ciclos de 80-160 horas por nível;</li>
  <li><strong>Espanhol:</strong> mesma estrutura, com forte demanda especialmente em Salvador, Manaus e cidades de fronteira;</li>
  <li><strong>Italiano, francês, mandarim, japonês:</strong> oferta menor mas consistente em capitais maiores;</li>
  <li><strong>Inglês para negócios, viagens, hospitalidade:</strong> módulos especializados de 40-80 horas.</li>
</ul>

<h2>Gestão e administração</h2>

<p>Área teórica com forte oferta EAD. Cursos típicos:</p>

<ul>
  <li><strong>Auxiliar administrativo:</strong> 160-320 horas;</li>
  <li><strong>Vendedor e atendente de comércio:</strong> 80-160 horas;</li>
  <li><strong>Telemarketing e operador de telemarketing:</strong> 60-120 horas;</li>
  <li><strong>Recepcionista:</strong> 80-160 horas;</li>
  <li><strong>Auxiliar de contabilidade:</strong> 200-400 horas;</li>
  <li><strong>Gestão de pessoas, gestão de projetos, marketing digital:</strong> cursos livres de 20-60 horas;</li>
  <li><strong>Técnico em Administração:</strong> 1.200 horas, curso completo de nível médio.</li>
</ul>

<h2>Moda e vestuário</h2>

<ul>
  <li><strong>Corte e costura:</strong> 80-240 horas, técnica clássica de modelagem e costura;</li>
  <li><strong>Modelista:</strong> 240-480 horas, especialização em criação de moldes;</li>
  <li><strong>Vestuário industrial:</strong> 200-400 horas, para indústria de confecção;</li>
  <li><strong>Estilismo:</strong> cursos livres de 40-120 horas;</li>
  <li><strong>Design de moda:</strong> graduação tecnólogica em algumas unidades (Senac Moda, Senac São Paulo).</li>
</ul>

<h2>Tecnologia da informação</h2>

<ul>
  <li><strong>Operador de computador:</strong> 80-160 horas, fundamentos de informática;</li>
  <li><strong>Atendente de tecnologia:</strong> 120-240 horas, suporte técnico básico;</li>
  <li><strong>Web designer e desenvolvedor de sites:</strong> 200-400 horas;</li>
  <li><strong>Técnico em informática para internet:</strong> 1.200 horas;</li>
  <li><strong>Análise de dados, programação, IA aplicada:</strong> cursos livres de 40-120 horas (oferta mais recente, com crescimento rápido).</li>
</ul>

<h2>Hospitalidade e turismo</h2>

<ul>
  <li><strong>Camareira (governança hoteleira):</strong> 80-160 horas;</li>
  <li><strong>Recepcionista de hotel:</strong> 120-240 horas;</li>
  <li><strong>Guia de turismo regional:</strong> 240-480 horas, com licenciamento profissional;</li>
  <li><strong>Eventos:</strong> 160-320 horas, organização e produção;</li>
  <li><strong>Técnico em Hospedagem:</strong> 1.200 horas;</li>
  <li><strong>Técnico em Eventos:</strong> 1.200 horas.</li>
</ul>

<h2>Modalidades: como o aluno pode estudar</h2>

<ul>
  <li><strong>Presencial:</strong> aulas em unidade Senac, com prática obrigatória em laboratório (cozinha, salão de beleza, salas técnicas);</li>
  <li><strong>EAD nacional:</strong> 100% online via plataforma ead.senac.br, com tutoria assíncrona;</li>
  <li><strong>EAD misto:</strong> teoria online + prática presencial em unidade Senac;</li>
  <li><strong>Curso livre:</strong> formação curta sem certificação profissional (extensão);</li>
  <li><strong>Qualificação profissional:</strong> formação média com certificado profissional;</li>
  <li><strong>Curso técnico:</strong> formação completa de nível médio com diploma reconhecido pelo MEC.</li>
</ul>

<h2>Quanto custa um curso Senac em 2026</h2>

<ul>
  <li><strong>Cursos curtos online (40-80h):</strong> R$ 80 a R$ 400, alguns gratuitos;</li>
  <li><strong>Qualificação profissional presencial (160-400h):</strong> R$ 800 a R$ 3.500 (com parcelamento), gratuitos via PSG;</li>
  <li><strong>Cursos técnicos completos (1.200h+):</strong> R$ 5.000 a R$ 20.000 totais (com parcelamento de 12-24 meses), gratuitos via PSG;</li>
  <li><strong>Idiomas:</strong> R$ 250 a R$ 600 por mês conforme estado;</li>
  <li><strong>Cursos in-company:</strong> orçados individualmente.</li>
</ul>

<details class='faq-discover'>
<summary><strong>O Senac é gratuito ou pago?</strong></summary>
<p>Ambos. A maior parte do catálogo é paga, com preços que variam conforme curso e estado. Mas o Programa Senac de Gratuidade (PSG) reserva vagas gratuitas para candidatos com renda familiar bruta mensal per capita até 2 salários mínimos. Cursos curtos online (ead.senac.br) também têm parte do catálogo totalmente gratuito.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quais são as áreas mais procuradas no Senac em 2026?</strong></summary>
<p>Beleza (manicure, cabeleireiro, design de sobrancelhas), gastronomia (cozinheiro, confeitaria, panificação), saúde (cuidador de idosos, auxiliar de farmácia, técnico em enfermagem) e idiomas (inglês básico e intermediário) lideram em volume de inscrições. Tecnologia e gestão crescem rapidamente.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto tempo dura um curso Senac?</strong></summary>
<p>Cursos livres: 4 a 80 horas. Qualificação profissional: 160 a 400 horas (alguns meses). Cursos técnicos completos: 1.200 a 1.800 horas (em 2 anos). Idiomas: ciclos de 80-160 horas por nível.</p>
</details>

<details class='faq-discover'>
<summary><strong>O certificado do Senac tem validade no currículo?</strong></summary>
<p>Sim, com forte reconhecimento de mercado em áreas de serviços e comércio (beleza, gastronomia, atendimento, saúde básica, hospitalidade, idiomas). Cursos técnicos completos têm diploma reconhecido pelo MEC com validade nacional. Cursos curtos e qualificação emitem certificado Senac válido para currículo e qualificação profissional.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso fazer curso Senac à distância?</strong></summary>
<p>Sim. A plataforma ead.senac.br oferece dezenas de cursos online, alguns gratuitos. Áreas mais comuns em EAD: idiomas, gestão, marketing digital, atendimento, finanças. Áreas com prática obrigatória (beleza, gastronomia, saúde técnica) são predominantemente presenciais.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

$pB2 = [
    'titulo' => 'Curso de cuidador de idosos do Senac em 2026: duração, conteúdo, certificação e como conseguir vaga gratuita',
    'slug'   => 'curso-cuidador-idosos-senac-2026-duracao-conteudo-certificacao-vaga-gratuita',
    'metaDesc' => 'Como funciona o curso de cuidador de idosos do Senac em 2026: 160 horas, conteúdo teórico e prático, certificação válida no mercado, vagas gratuitas via PSG e onde se inscrever.',
    'focusKw' => 'curso cuidador de idosos senac',
    'ogUrl' => 'https://healthsenior.com.br/wp-content/uploads/2024/07/Cuidadora-auxiliando-uma-senhora-idosa-em-suas-atividades-diarias-em-casa_@jacoblund_Canva-scaled.jpg',
];
$pB2['html'] = <<<'HTML'
<p>O <strong>curso de cuidador de idosos do Senac</strong> é uma das qualificações profissionais com maior empregabilidade no Brasil em 2026. O envelhecimento populacional brasileiro acelerou a demanda por cuidadores capacitados — segundo o IBGE, o número de pessoas com 60 anos ou mais passou de 28 milhões em 2020 para mais de 33 milhões em 2025, com projeção de chegar a 38 milhões em 2030. Cada idoso que precisa de cuidado domiciliar ou em instituição de longa permanência (ILPI) representa uma vaga ativa de cuidador.</p>

<p>O Senac é uma das instituições mais reconhecidas pelo mercado para a formação de cuidadores de idosos. O curso típico tem 160 horas, divididas entre teoria sobre fisiologia do envelhecimento, doenças prevalentes, primeiros socorros, nutrição, higiene, e práticas supervisionadas em instituições conveniadas. O certificado Senac é amplamente aceito por casas de repouso, agências de cuidador e famílias contratantes diretas.</p>

<p>O guia abaixo cobre o conteúdo programático do curso, a duração padrão, os pré-requisitos, as oportunidades de mercado, o salário médio em 2026 e o passo a passo para conseguir vaga gratuita via Programa Senac de Gratuidade (PSG).</p>

<h2>Por que a profissão de cuidador de idosos cresceu tanto</h2>

<p>Três fatores estruturais explicam o crescimento:</p>

<ul>
  <li><strong>Envelhecimento populacional:</strong> a taxa de fecundidade brasileira caiu de 4,3 filhos por mulher em 1980 para 1,6 em 2024, enquanto a expectativa de vida subiu de 62 para 76 anos. O resultado é uma pirâmide etária cada vez mais "invertida";</li>
  <li><strong>Mudança na estrutura familiar:</strong> as famílias menores e a participação feminina no mercado de trabalho reduziram a disponibilidade dos parentes próximos para cuidar dos idosos em casa, abrindo espaço para cuidadores contratados;</li>
  <li><strong>Reconhecimento legal:</strong> a Lei 13.342/2016 reconheceu a profissão de cuidador de idosos, e a Classificação Brasileira de Ocupações (CBO 5162-10) garante formalização e registro no eSocial.</li>
</ul>

<h2>Conteúdo programático do curso Senac de cuidador de idosos</h2>

<p>As 160 horas do curso são distribuídas entre módulos teóricos e práticos:</p>

<ul>
  <li><strong>Fundamentos do envelhecimento:</strong> fisiologia, mudanças biológicas, sociais e psicológicas próprias do envelhecimento;</li>
  <li><strong>Cuidados básicos com higiene pessoal:</strong> banho assistido, higiene oral, troca de fralda, prevenção de assaduras;</li>
  <li><strong>Mobilidade e prevenção de quedas:</strong> técnicas de transferência (cama-cadeira, cadeira-banheiro), uso de cadeira de rodas, andador, bengala;</li>
  <li><strong>Nutrição e hidratação:</strong> dietas para idosos, dietas especiais (diabetes, hipertensão), hidratação preventiva;</li>
  <li><strong>Doenças prevalentes:</strong> hipertensão, diabetes, Alzheimer, Parkinson, AVC, depressão, insuficiência cardíaca;</li>
  <li><strong>Administração de medicamentos:</strong> leitura de prescrição, horários, vias de administração (oral, sublingual, tópica), conservação;</li>
  <li><strong>Primeiros socorros:</strong> identificação de emergências (engasgo, queda, parada cardiorrespiratória, AVC), procedimento básico até chegada do socorro;</li>
  <li><strong>Aspectos psicológicos:</strong> manejo de quadros depressivos e demenciais, comunicação não-verbal com idoso com Alzheimer;</li>
  <li><strong>Direitos do idoso:</strong> Estatuto do Idoso (Lei 10.741/2003), prevenção de violência e negligência;</li>
  <li><strong>Aspectos legais da profissão:</strong> CLT, eSocial, MEI cuidador, registro CBO;</li>
  <li><strong>Estágio supervisionado:</strong> 20-40 horas em instituição de longa permanência ou domicílio, com supervisão de profissional formado.</li>
</ul>

<h2>Pré-requisitos para fazer o curso</h2>

<ul>
  <li><strong>Idade mínima:</strong> 18 anos;</li>
  <li><strong>Escolaridade:</strong> ensino fundamental completo (alguns estados aceitam ensino fundamental em curso);</li>
  <li><strong>Saúde:</strong> não há restrição médica formal, mas o trabalho exige força física para levantar e transferir pacientes acamados;</li>
  <li><strong>Perfil:</strong> paciência, empatia, capacidade de lidar com situações estressantes — características avaliadas no início do curso e durante o estágio.</li>
</ul>

<h2>Mercado de trabalho e salário em 2026</h2>

<p>O cuidador de idosos certificado encontra 3 caminhos de atuação:</p>

<ul>
  <li><strong>Domicílio com CLT:</strong> contratado por família ou agência, com salário entre R$ 1.700 e R$ 3.200 conforme estado, jornada e complexidade do caso. Em São Paulo e Rio, a média fica entre R$ 2.500 e R$ 3.200;</li>
  <li><strong>Instituição de Longa Permanência (ILPI - "casa de repouso"):</strong> trabalho em escala (12x36 ou 24x48), com salário entre R$ 1.800 e R$ 2.800;</li>
  <li><strong>Autônomo / MEI cuidador:</strong> precificação por hora (R$ 18 a R$ 50/hora conforme cidade e turno) ou por diária (R$ 180 a R$ 400). Demanda flexibilidade e clientela própria.</li>
</ul>

<p>Cuidadores com especialização adicional (Alzheimer, pacientes acamados, traqueostomia) cobram acima da média e têm demanda permanente.</p>

<h2>Como conseguir vaga gratuita no curso Senac de cuidador de idosos</h2>

<p>Pelo Programa Senac de Gratuidade (PSG):</p>

<ol>
  <li>Acessar o portal do Senac do seu estado (ba.senac.br, sp.senac.br, rj.senac.br, etc.);</li>
  <li>Localizar a seção PSG ou Cursos Gratuitos;</li>
  <li>Buscar "Cuidador de Idosos" no catálogo e verificar editais abertos. Costumam abrir nos meses de janeiro, abril, julho e outubro;</li>
  <li>Preencher o formulário online com dados pessoais, escolaridade e renda familiar;</li>
  <li>Anexar documentação:
    <ul>
      <li>RG e CPF;</li>
      <li>Comprovante de residência;</li>
      <li>Comprovante de escolaridade (mínimo fundamental);</li>
      <li>Comprovante de renda familiar bruta mensal per capita até 2 salários mínimos.</li>
    </ul>
  </li>
  <li>Aguardar análise socioeconômica (5-15 dias);</li>
  <li>Aprovação → matrícula presencial na unidade Senac com horário e calendário definidos;</li>
  <li>Frequentar 75% mínimo das aulas, cumprir estágio supervisionado e aproveitamento mínimo para receber o certificado.</li>
</ol>

<h2>Alternativas pagas se não conseguir vaga PSG</h2>

<p>Se não tiver perfil PSG ou não houver vaga disponível, o curso pago do Senac custa entre R$ 1.200 e R$ 2.800 dependendo do estado, com parcelamento em 6 a 12 vezes. Vale considerar:</p>

<ul>
  <li>Algumas prefeituras (São Paulo, Rio, Salvador) ofertam cursos gratuitos próprios de cuidador de idosos via secretarias de saúde ou trabalho;</li>
  <li>O Pronatec (Programa Nacional de Acesso ao Ensino Técnico e Emprego) inclui cuidador de idosos no catálogo, com vagas em diferentes instituições;</li>
  <li>Sindicatos da categoria oferecem cursos próprios com valor reduzido para filiados.</li>
</ul>

<details class='faq-discover'>
<summary><strong>Quanto tempo dura o curso de cuidador de idosos do Senac?</strong></summary>
<p>O curso padrão tem 160 horas, distribuídas entre módulos teóricos e estágio supervisionado, geralmente em 4 a 6 meses com aulas semanais (2-3 vezes por semana). Algumas unidades oferecem versão intensiva de 240 horas com formação mais aprofundada.</p>
</details>

<details class='faq-discover'>
<summary><strong>O Senac tem curso de cuidador gratuito?</strong></summary>
<p>Sim, via Programa Senac de Gratuidade (PSG) para candidatos com renda familiar bruta mensal per capita até 2 salários mínimos. As vagas são preenchidas por edital periódico em cada Senac estadual, com prazos de 15 a 30 dias de inscrição.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto ganha um cuidador de idosos certificado pelo Senac em 2026?</strong></summary>
<p>Em CLT no domicílio, salário médio fica entre R$ 1.700 e R$ 3.200 conforme estado e complexidade. Em ILPI (casa de repouso) com escala 12x36, entre R$ 1.800 e R$ 2.800. Autônomo cobra entre R$ 18 e R$ 50/hora ou R$ 180-400 por diária. Cuidadores com especialização (Alzheimer, acamados) cobram acima da média.</p>
</details>

<details class='faq-discover'>
<summary><strong>Preciso de ensino médio para fazer o curso de cuidador no Senac?</strong></summary>
<p>Não. O pré-requisito padrão é ensino fundamental completo. Alguns estados aceitam ensino fundamental em curso. A idade mínima é 18 anos.</p>
</details>

<details class='faq-discover'>
<summary><strong>O certificado de cuidador do Senac é aceito por casas de repouso?</strong></summary>
<p>Sim, e com forte reconhecimento. O Senac é uma das instituições mais aceitas pelo mercado para qualificação em saúde. Casas de repouso, agências de cuidador e famílias contratantes diretas reconhecem o certificado Senac como qualificação válida e suficiente para contratação. Profissão regulamentada pela Lei 13.342/2016 com CBO 5162-10.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

$pB3 = [
    'titulo' => 'Cursos de gastronomia do Senac em 2026: confeitaria, cozinheiro, panificação e como entrar no mercado',
    'slug'   => 'cursos-gastronomia-senac-2026-confeitaria-cozinheiro-panificacao-mercado-trabalho',
    'metaDesc' => 'Cursos de gastronomia do Senac em 2026: confeitaria, cozinha profissional, panificação artesanal, garçom. Modalidades, certificação, oportunidades de mercado e como conseguir vaga gratuita via PSG.',
    'focusKw' => 'curso senac gastronomia',
    'ogUrl' => 'https://leschefsacademia.com.br/upload/09092020124807.png',
];
$pB3['html'] = <<<'HTML'
<p>Os <strong>cursos de gastronomia do Senac</strong> são referência consolidada no Brasil há mais de 50 anos. Algumas das unidades têm reputação internacional — Senac Águas de São Pedro (SP), Santos Dumont (SP), Tutoia (SP) e outras formam parte significativa dos chefs profissionais que comandam restaurantes brasileiros. Em 2026, o catálogo cobre desde curso curto de 40 horas (uma técnica específica) até a graduação tecnológica em Gastronomia, com mais de 30 unidades especializadas pelo país.</p>

<p>A demanda de mercado pela gastronomia profissional acompanhou o crescimento do setor de food service brasileiro: bares, restaurantes, padarias artesanais, bistrôs, dark kitchens e gastronomia delivery cresceram acima da economia geral nos últimos anos. O profissional certificado pelo Senac entra no mercado com diferencial de marca — restaurantes médios e grandes pedem qualificação formal nos processos seletivos.</p>

<p>O guia abaixo cobre as principais modalidades de curso, conteúdo programático típico, valores em 2026, oportunidades de mercado para cozinheiro e confeiteiro, e como acessar vagas gratuitas via PSG.</p>

<h2>Os 5 cursos de gastronomia mais procurados no Senac em 2026</h2>

<ul>
  <li><strong>Cozinheiro:</strong> 200-400 horas. Formação base para profissional de cozinha em restaurantes médios e grandes. Inclui técnicas de corte, métodos de cocção, montagem de pratos, gestão de cozinha;</li>
  <li><strong>Confeitaria básica e avançada:</strong> 80-200 horas por nível. Cobertura de bolos básicos, doces finos, sobremesas, pâtisserie francesa avançada;</li>
  <li><strong>Panificação artesanal:</strong> 120-240 horas. Foco em fermentação natural, pães rústicos, brioches, baguetes, ciabatta. Crescimento forte com tendência de padarias artesanais;</li>
  <li><strong>Garçom e atendente de restaurante:</strong> 80-160 horas. Serviço de salão, comanda, atendimento ao cliente, conhecimento básico de vinhos e harmonização;</li>
  <li><strong>Sommelier:</strong> 100-200 horas. Especialização em vinhos, harmonização, serviço, gestão de adega.</li>
</ul>

<h2>Cozinheiro: o curso base para entrada no mercado</h2>

<p>O curso de cozinheiro é a porta principal para a carreira em gastronomia. Conteúdo padrão das 200-400 horas:</p>

<ul>
  <li><strong>Técnicas básicas de corte:</strong> brunoise, julienne, chiffonade, paysanne;</li>
  <li><strong>Métodos de cocção:</strong> grelhar, assar, refogar, braising, sous-vide, banho-maria;</li>
  <li><strong>Bases da cozinha clássica:</strong> os 5 fundos básicos da gastronomia francesa, molhos-mãe (béchamel, espagnole, velouté, holandês, tomate);</li>
  <li><strong>Cozinha brasileira regional:</strong> Bahia, Nordeste, Sul, cozinha mineira, paulista;</li>
  <li><strong>Cozinha internacional:</strong> francesa, italiana, mediterrânea, asiática (japonesa, chinesa, tailandesa);</li>
  <li><strong>Gestão de cozinha:</strong> mise en place, ficha técnica, custos, controle de estoque, segurança alimentar (Boas Práticas, RDC 216);</li>
  <li><strong>Estágio supervisionado:</strong> 40-80 horas em restaurante conveniado.</li>
</ul>

<h2>Confeitaria: do básico ao avançado</h2>

<p>A confeitaria do Senac é organizada em módulos progressivos:</p>

<ul>
  <li><strong>Confeitaria básica (80h):</strong> bolos simples, recheios, coberturas, doces tradicionais brasileiros (brigadeiro gourmet, beijinho, brownie, bolo de cenoura);</li>
  <li><strong>Confeitaria intermediária (120h):</strong> bolos de aniversário decorados, glacê real, pasta americana, tortas francesas, mousses, panna cotta;</li>
  <li><strong>Confeitaria avançada (200h):</strong> pâtisserie francesa (macarons, éclairs, mille-feuille), técnicas de temperagem de chocolate, sobremesas de restaurante;</li>
  <li><strong>Especializações:</strong> bolo de noiva e casamento, confeitaria com chocolate, decorações elaboradas, confeitaria fitness e funcional.</li>
</ul>

<h2>Panificação artesanal: mercado em expansão</h2>

<p>O curso de panificação artesanal cresceu fortemente desde 2020 com a multiplicação de padarias artesanais brasileiras. Conteúdo padrão:</p>

<ul>
  <li><strong>Tipos de farinha e moagem:</strong> trigo, centeio, integral, sem glúten;</li>
  <li><strong>Fermentação:</strong> levain (fermentação natural), levedo comercial, fermentação longa;</li>
  <li><strong>Pães clássicos:</strong> pão francês, ciabatta, baguete, focaccia, brioche;</li>
  <li><strong>Pães artesanais:</strong> sourdough, pães rústicos integrais, pães com sementes;</li>
  <li><strong>Massas folhadas:</strong> croissant, pain au chocolat, danesa;</li>
  <li><strong>Gestão de padaria:</strong> equipamentos, custos, precificação, controle de produção.</li>
</ul>

<h2>Valores: quanto custa um curso de gastronomia no Senac em 2026</h2>

<ul>
  <li><strong>Cursos curtos (40-80h):</strong> R$ 350 a R$ 1.200 conforme estado e técnica;</li>
  <li><strong>Cozinheiro completo (200-400h):</strong> R$ 1.800 a R$ 5.500 (com parcelamento);</li>
  <li><strong>Confeitaria avançada (200h):</strong> R$ 1.500 a R$ 4.000;</li>
  <li><strong>Sommelier (200h):</strong> R$ 2.500 a R$ 6.000;</li>
  <li><strong>Tecnólogo em Gastronomia (graduação, 2.400h):</strong> R$ 25.000 a R$ 50.000 totais (em 2 anos, parcelado);</li>
  <li><strong>Vagas PSG gratuitas:</strong> custo zero para candidatos com renda familiar per capita até 2 SM.</li>
</ul>

<h2>Mercado de trabalho e salários em 2026</h2>

<p>Salários médios por função, conforme dados recentes de mercado:</p>

<ul>
  <li><strong>Auxiliar de cozinha / commis:</strong> R$ 1.700 a R$ 2.500 mensais;</li>
  <li><strong>Cozinheiro:</strong> R$ 2.400 a R$ 4.500 mensais;</li>
  <li><strong>Confeiteiro:</strong> R$ 2.200 a R$ 4.000 mensais;</li>
  <li><strong>Chef de cozinha:</strong> R$ 4.500 a R$ 12.000 mensais conforme restaurante e cidade;</li>
  <li><strong>Padeiro artesanal:</strong> R$ 2.000 a R$ 3.500 mensais;</li>
  <li><strong>Sommelier:</strong> R$ 2.800 a R$ 7.000 mensais conforme estabelecimento;</li>
  <li><strong>Garçom em restaurante padrão alto:</strong> R$ 1.800 a R$ 2.800 + gorjetas (que podem dobrar a remuneração);</li>
  <li><strong>Confeiteiro autônomo (sob encomenda):</strong> precificação por pedido — bolo simples R$ 80-200, bolo de noiva R$ 800-3.500, mesa de doces R$ 1.500-5.000;</li>
  <li><strong>Dono de padaria artesanal pequena:</strong> faturamento variável (R$ 15-50 mil/mês), com margem operacional típica de 12-22%.</li>
</ul>

<h2>Como conseguir vaga gratuita em gastronomia via PSG</h2>

<ol>
  <li>Acessar o portal do Senac estadual e localizar editais PSG abertos;</li>
  <li>Conferir oferta de cursos de gastronomia (cozinheiro, confeitaria, panificação, garçom) e unidade Senac ofertante;</li>
  <li>Preencher formulário com dados pessoais, escolaridade e renda familiar bruta per capita;</li>
  <li>Anexar documentação:
    <ul>
      <li>RG, CPF, comprovante de residência;</li>
      <li>Comprovante de escolaridade compatível (geralmente ensino fundamental completo, alguns cursos pedem médio);</li>
      <li>Comprovante de renda familiar bruta mensal per capita até 2 salários mínimos;</li>
    </ul>
  </li>
  <li>Aguardar análise socioeconômica;</li>
  <li>Aprovação → matrícula presencial na unidade Senac;</li>
  <li>Frequentar 75% mínimo das aulas e cumprir o aproveitamento exigido.</li>
</ol>

<details class='faq-discover'>
<summary><strong>O Senac tem curso de gastronomia gratuito?</strong></summary>
<p>Sim, via Programa Senac de Gratuidade (PSG) para candidatos com renda familiar per capita até 2 salários mínimos. Áreas como cozinheiro, confeitaria e garçom têm vagas gratuitas em editais periódicos. Inscrição pelo portal do Senac estadual nos meses de janeiro, abril, julho e outubro.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto tempo dura o curso de cozinheiro do Senac?</strong></summary>
<p>O curso completo de cozinheiro varia entre 200 e 400 horas, geralmente em 4 a 8 meses com aulas semanais (2-3 vezes por semana). Existem versões mais curtas (80-160h) com foco em uma técnica específica ou tipo de cozinha (italiana, asiática, mediterrânea).</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso ser chef de cozinha só com curso do Senac?</strong></summary>
<p>O curso de cozinheiro do Senac forma o profissional de base. Para chegar a chef de cozinha, é necessário acumular experiência prática (5-10 anos), aperfeiçoamento contínuo, especializações e geralmente experiência internacional. O Senac forma a base; chef é construído na rotina de cozinha.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto custa o curso de confeitaria avançada do Senac em 2026?</strong></summary>
<p>Entre R$ 1.500 e R$ 4.000 conforme estado e unidade. O curso costuma ter 120-200 horas, com módulos sobre bolos decorados, pasta americana, pâtisserie francesa, temperagem de chocolate e sobremesas de restaurante. Possibilidade de parcelamento em 6-12 vezes.</p>
</details>

<details class='faq-discover'>
<summary><strong>O Senac forma para abrir padaria artesanal própria?</strong></summary>
<p>O curso de panificação do Senac forma o conhecimento técnico necessário (fermentação, tipos de pão, gestão de produção). Para abrir padaria própria, é recomendado complementar com curso de gestão de pequenos negócios (Sebrae oferta gratuito) e estudo do mercado local. O Senac forma o padeiro; o empreendedor se forma com tempo + capital + experiência de gestão.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// Publicação batch (6 posts: Cluster A 3 + Cluster B 3)
// ════════════════════════════════════════════════════════════════════
$slugSite = 'cursosenac';
$cfgSite = $cfg;
aplicarSite($cfgSite, $sites, $slugSite);
$wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

$schemaAuthor = ['@type' => 'Organization', 'name' => 'Redação Curso Senac Gratuito', 'url' => 'https://cursosenacgratuito.com.br'];
$schemaPublisher = ['@type' => 'Organization', 'name' => 'Curso Senac Gratuito', 'url' => 'https://cursosenacgratuito.com.br'];

$posts = [$pA1, $pA2, $pA3, $pB1, $pB2, $pB3];
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
    $tagIds = $wp->resolverTags(['SENAI', 'Senac', 'Curso Gratuito', 'PSG', 'PSI', 'NR-10', 'NR-12', 'Cuidador de Idosos', 'Gastronomia', 'Confeitaria']);

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

echo "\n══════ FIM CLUSTERS A+B (SENAI + Senac cursos) ══════\n";
