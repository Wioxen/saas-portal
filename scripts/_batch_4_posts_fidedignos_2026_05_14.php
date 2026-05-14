<?php
declare(strict_types=1);
/**
 * Batch 4 posts manuais fidedignos — sessão Opus 14/05/2026.
 *
 * Trends de origem (Pingo via fontes RSS Tier S/A):
 *   - guiadoscursos      #21422 Enem 2026 isenção divulgada (g1.globo)
 *   - leaodabarra        #21314 Transporte vit-fla esquema especial (A Tarde)
 *   - vagasebeneficios   #20674 Alesp aprova SM SP R$ 1.874 (Metrópoles)
 *   - guiadoscursos      #21659 CEDERJ 7.505 vagas (Hora Brasil)
 *
 * Conteúdo escrito por Opus sem chamada LLM API.
 * Publica como DRAFT. Status atualizado nos trends DB via SSH separado.
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

// ════════════════════════════════════════════════════════════════════
// POST 1 — GUIADOSCURSOS #21422 — Enem 2026 isenção
// ════════════════════════════════════════════════════════════════════
$post1 = [
    'slug_site' => 'guiadoscursos', 'trend_id' => 21422,
    'titulo'    => 'Enem 2026 isenção: resultados divulgados hoje (14/05) e como consultar na Página do Participante',
    'slug'      => 'enem-2026-isencao-resultado-divulgado-14-maio-consulta-pagina-participante',
    'metaDesc'  => 'Inep divulgou nesta quinta (14/05) o resultado dos pedidos de isenção da taxa do Enem 2026. Veja como consultar pelo enem.inep.gov.br/participante e como apresentar recurso até o prazo final.',
    'focusKw'   => 'enem 2026 isencao resultado',
    'fonteUrl'  => 'https://g1.globo.com/educacao/enem/2026/noticia/2026/05/14/enem-2026-resultados-dos-pedidos-de-isencao-da-taxa-sao-divulgados.ghtml',
    'fonteNome' => 'g1 Globo', 'autorFonte' => 'Redação g1 Educação',
    'ogImage'   => 'https://s2-g1.glbimg.com/PppvyJXfwrxMQ13tonXYg7fcK9I=/2048x0/filters:format(jpeg)/https://i.s3.glbimg.com/v1/AUTH_59edd422c0c84a879bd37670ae4f538a/internal_photos/bs/2025/1/o/25Fk3hTbSK9MN8XPnvIw/54913100355-886af15cd9-k.jpg',
    'categoria' => 'Enem', 'tags' => ['Enem 2026', 'Isenção Taxa Enem', 'Inep', 'Página do Participante', 'CadÚnico', 'Educação', 'Vestibular'],
    'html' => <<<'HTML'
<p>O <strong>Instituto Nacional de Estudos e Pesquisas Educacionais Anísio Teixeira (Inep)</strong> divulgou nesta quinta-feira, 14 de maio de 2026, o resultado dos pedidos de isenção da taxa de inscrição do Enem 2026. A consulta é feita pela Página do Participante, no site enem.inep.gov.br/participante, conforme apurou a redação a partir de matéria do g1 Globo.</p>

<p>Quem teve o pedido aprovado garante gratuidade na inscrição. Quem foi negado pode apresentar recurso dentro do prazo definido pelo Inep, com nova janela específica para revisão.</p>

<p>O calendário oficial do Enem 2026 segue cronograma do Inep, e o resultado da isenção é a primeira etapa antes do período de inscrição propriamente dito, que abre nas semanas seguintes.</p>

<h2>Como consultar o resultado da isenção do Enem 2026</h2>

<p>A consulta é gratuita e exige apenas os dados pessoais do candidato. O passo a passo está disponível na Página do Participante.</p>

<ol>
  <li>Acessar o site oficial: <a href='https://enem.inep.gov.br/participante' target='_blank' rel='noopener'>enem.inep.gov.br/participante</a>;</li>
  <li>Fazer login com CPF e senha cadastrados anteriormente;</li>
  <li>Verificar a resposta do pedido de isenção exibida na tela principal;</li>
  <li>Se aprovado, aguardar a abertura das inscrições;</li>
  <li>Se negado, conferir o motivo e preparar o recurso.</li>
</ol>

<h2>Quem tem direito à isenção da taxa do Enem 2026</h2>

<p>Três grupos têm direito à isenção, conforme regulamento do Inep para a edição 2026.</p>

<ul>
  <li><strong>Vulnerabilidade socioeconômica:</strong> membros de família de baixa renda inscritos no Cadastro Único para Programas Sociais do Governo Federal (CadÚnico);</li>
  <li><strong>Estudantes da rede pública ou bolsistas:</strong> quem cursou todo o ensino médio em escola da rede pública, ou como bolsista integral em rede privada, com renda per capita igual ou inferior a 1,5 salário mínimo;</li>
  <li><strong>Concluintes em 2025:</strong> quem está cursando o último ano do ensino médio em 2025, em qualquer modalidade, em escola da rede pública declarada ao Censo Escolar.</li>
</ul>

<h2>Como apresentar recurso se a isenção foi negada</h2>

<p>O Inep abre prazo específico para recurso após a divulgação dos resultados. O candidato deve acessar a Página do Participante no mesmo endereço, escolher a opção de contestação e anexar os documentos que comprovem o direito à isenção.</p>

<p>A análise do recurso ocorre nas semanas seguintes, com nova divulgação de resultado antes da abertura do período de inscrição regular do Enem 2026.</p>

<h2>Justificativa de ausência: quem foi isento em 2025 e não compareceu</h2>

<p>Quem solicitou e obteve isenção no Enem 2025 mas não compareceu a nenhum dos dois dias de prova precisou justificar a ausência para manter o direito à gratuidade em 2026. Documentos aceitos como justificativa, conforme regras do Inep, incluem:</p>

<ul>
  <li>Boletim de ocorrência comprovando assalto, furto ou acidente de trânsito no dia da prova;</li>
  <li>Certidão de casamento ou contrato de união estável com data igual à do exame;</li>
  <li>Certidão de óbito comprovando morte na família próxima;</li>
  <li>Certidão de nascimento que comprove maternidade ou paternidade no período;</li>
  <li>Atestado de emergência médica, internação hospitalar ou repouso prescrito;</li>
  <li>Mandado de prisão que ateste privação de liberdade na data;</li>
  <li>Comprovante oficial de mudança de domicílio entre estados;</li>
  <li>Documento que comprove intercâmbio acadêmico ou atividade escolar obrigatória.</li>
</ul>

<h2>Próximas etapas do calendário Enem 2026</h2>

<p>Após o resultado dos recursos da isenção, o Inep abre o período de inscrição oficial do Enem 2026, válido tanto para candidatos isentos quanto para os pagantes. As datas exatas constam no edital oficial publicado no portal do Inep.</p>

<p>O cronograma também define datas das provas, geralmente em dois domingos seguidos no segundo semestre, com aplicação simultânea em todo o país.</p>

<details class='faq-discover'>
<summary><strong>Quando sai o resultado da isenção do Enem 2026?</strong></summary>
<p>O Inep divulgou o resultado dos pedidos de isenção da taxa do Enem 2026 nesta quinta-feira, 14 de maio de 2026. A consulta é feita pela Página do Participante, no site enem.inep.gov.br/participante.</p>
</details>

<details class='faq-discover'>
<summary><strong>Como consultar o resultado do pedido de isenção do Enem?</strong></summary>
<p>O candidato deve acessar enem.inep.gov.br/participante, fazer login com CPF e senha cadastrados, e verificar a resposta exibida na tela principal. O serviço é gratuito.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quem tem direito à isenção da taxa do Enem 2026?</strong></summary>
<p>Têm direito: membros de famílias de baixa renda inscritas no CadÚnico, estudantes que concluíram o ensino médio em escola pública ou como bolsistas integrais em rede privada com renda per capita até 1,5 salário mínimo, e quem está cursando o último ano do ensino médio em 2025 em escola pública.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso recorrer se a isenção foi negada?</strong></summary>
<p>Sim. O Inep abre prazo específico para recurso após divulgar o resultado. O candidato acessa a Página do Participante, escolhe a opção de contestação e anexa documentos que comprovem o direito à gratuidade.</p>
</details>

<details class='faq-discover'>
<summary><strong>O que faz quem foi isento em 2025 e não compareceu à prova?</strong></summary>
<p>É necessário justificar a ausência para manter o direito à isenção em 2026. Documentos aceitos incluem boletim de ocorrência, certidão de óbito, atestado médico, mandado de prisão e comprovantes de mudança de domicílio ou intercâmbio acadêmico.</p>
</details>

<p><em>Fonte: redação g1 Educação, matéria publicada em 14 de maio de 2026. <a href='https://g1.globo.com/educacao/enem/2026/noticia/2026/05/14/enem-2026-resultados-dos-pedidos-de-isencao-da-taxa-sao-divulgados.ghtml' target='_blank' rel='noopener'>Ver matéria original</a>.</em></p>
HTML,
];

// ════════════════════════════════════════════════════════════════════
// POST 2 — LEAODABARRA #21314 — Transporte Vitória x Flamengo
// ════════════════════════════════════════════════════════════════════
$post2 = [
    'slug_site' => 'leaodabarra', 'trend_id' => 21314,
    'titulo'    => 'Vitória x Flamengo: esquema especial de transporte com linha 1899 e ônibus extras nas estações Flamboyant e Pirajá',
    'slug'      => 'vitoria-flamengo-esquema-transporte-onibus-linha-1899-flamboyant-piraja-14-maio',
    'metaDesc'  => 'Salvador monta esquema especial de transporte para Vitória x Flamengo nesta quinta (14), 21h30. Linha especial 1899 + ônibus extras das 19h30 às 1h. Veja itinerários alterados.',
    'focusKw'   => 'vitoria flamengo transporte barradao onibus',
    'fonteUrl'  => 'https://atarde.com.br/salvador/vitoria-x-flamengo-tera-esquema-especial-de-trasporte-confira-1388824',
    'fonteNome' => 'A Tarde', 'autorFonte' => 'Luiza Nascimento',
    'ogImage'   => 'https://cdn.atarde.com.br/img/Artigo-Destaque/1380000/vitoria-x-flamengo-tera-esquema-especial-de-traspo0138882400202605140821.jpg?xid=7076948',
    'categoria' => 'Copa do Brasil', 'tags' => ['Vitória', 'Flamengo', 'Copa do Brasil', 'Barradão', 'Transporte Salvador', 'Semob', 'Esporte Clube Vitória'],
    'html' => <<<'HTML'
<p>A <strong>Secretaria de Mobilidade de Salvador (Semob)</strong> montou um esquema especial de transporte para o jogo Vitória x Flamengo desta quinta-feira, 14 de maio, no Estádio Manoel Barradas. A partida vale pelos 16 avos de final da Copa do Brasil, com bola rolando às 21h30, conforme apurado pela redação a partir do A Tarde.</p>

<p>O reforço inclui ônibus da frota reguladora nas estações Flamboyant e Pirajá, ativação da linha especial 1899 Estádio Barradão / Estação Flamboyant, e alteração temporária no itinerário das linhas 1353 e 1357.</p>

<p>A torcida rubro-negra esgotou os ingressos da casa para o duelo decisivo, segundo o clube. Ainda há entradas no setor visitante, com venda pelo site oficial do Flamengo.</p>

<h2>Linha especial 1899 atende deslocamento direto ao Barradão</h2>

<p>A linha 1899 conecta o Estádio Barradão à Estação Flamboyant, segundo informações da Semob divulgadas pelo A Tarde. A operação tem dois turnos.</p>

<ul>
  <li><strong>Antes do jogo:</strong> das 19h30 às 21h30, com base operacional na Estação Flamboyant;</li>
  <li><strong>Depois do jogo:</strong> das 23h30 à 1h, com base operacional na Avenida Mário Sérgio.</li>
</ul>

<p>O nome da linha homenageia o ano de fundação do Esporte Clube Vitória, 1899. O serviço facilita o retorno da torcida ao corredor central da capital baiana após o apito final.</p>

<h2>Ônibus extras nas estações Flamboyant e Pirajá</h2>

<p>Além da linha 1899, a Semob posicionou ônibus da frota reguladora nas estações Flamboyant e Pirajá durante o evento. Os veículos ficam à disposição das equipes de fiscalização e podem ser usados de forma estratégica em caso de aumento na demanda de passageiros.</p>

<p>O posicionamento dos extras segue o mesmo horário da linha especial: 19h30 às 21h30 antes do jogo, e 23h30 à 1h após o apito final.</p>

<h2>Linhas 1353 e 1357 têm itinerário alterado durante o jogo</h2>

<p>Duas linhas regulares têm percurso modificado por causa da movimentação ao redor do Barradão. As mudanças valem só durante a operação especial do dia 14.</p>

<h3>Linha 1353 (Estação Flamboyant - Jardim Nova Esperança)</h3>

<p>No sentido bairro / centro, a linha passa pela Rua do Mocambo, Rua Aymoré Moreira e Avenida Mário Sérgio (com retorno), segue pela marginal da Avenida Luís Viana, Rua Procurador Nelson Castro e Rua Artêmio Castro Valente. Daí acessa novamente a Luís Viana, com retornos nos viadutos do CAB, Bairro da Paz, Ferreira Costa e Eliana Kértesz até retomar o itinerário normal.</p>

<p>No sentido centro / bairro, a linha sai da Estação Pituaçu pela Luís Viana, com retorno no viaduto Orlando Gomes, passando pela Procurador Nelson Castro, Artêmio Castro Valente e Rua Nova Cidade.</p>

<h3>Linha 1357 (Nova Brasília-Trobogy - Estação Pituaçu)</h3>

<p>No sentido bairro / centro, o itinerário será pela Estrada Velha e Avenida Paralela até a Estação Pituaçu. No sentido centro / bairro, os veículos trafegam pela Paralela, com retorno no viaduto Orlando Gomes, seguindo pela Aymoré Moreira, Rua Mocambo e Estrada Velha.</p>

<h2>O jogo: cenários para o Vitória avançar</h2>

<p>O Leão precisa devolver o 2 a 1 sofrido no Maracanã para classificar. Os cenários são três.</p>

<ul>
  <li><strong>Vitória por 2 ou mais gols de diferença:</strong> classifica direto às oitavas;</li>
  <li><strong>Vitória por 1 gol de diferença:</strong> decisão vai aos pênaltis no próprio Barradão;</li>
  <li><strong>Empate ou derrota:</strong> Leão eliminado da Copa do Brasil 2026.</li>
</ul>

<p>A transmissão é de SporTV (TV fechada) e Premiere (pay-per-view).</p>

<details class='faq-discover'>
<summary><strong>Que horas começa o esquema especial de transporte para o Vitória x Flamengo?</strong></summary>
<p>A operação especial da Semob começa às 19h30 desta quinta-feira, 14 de maio, e segue até as 21h30. Depois do jogo, ônibus voltam a operar das 23h30 à 1h da madrugada, com base na Avenida Mário Sérgio.</p>
</details>

<details class='faq-discover'>
<summary><strong>O que é a linha 1899 que vai operar no jogo do Vitória?</strong></summary>
<p>A linha 1899 é a operação especial criada pela Semob para conectar o Estádio Barradão à Estação Flamboyant nos dias de jogos do Vitória. O nome homenageia o ano de fundação do clube. A linha opera antes e depois da partida.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quais linhas regulares mudam de itinerário por causa do jogo?</strong></summary>
<p>As linhas 1353 (Estação Flamboyant - Jardim Nova Esperança) e 1357 (Nova Brasília-Trobogy - Estação Pituaçu) têm itinerário temporariamente alterado durante a operação especial do jogo Vitória x Flamengo, com retornos pelos viadutos do CAB, Bairro da Paz, Ferreira Costa, Eliana Kértesz e Orlando Gomes.</p>
</details>

<details class='faq-discover'>
<summary><strong>Ainda há ingressos para Vitória x Flamengo?</strong></summary>
<p>A torcida do Vitória esgotou os ingressos para os setores da casa no Barradão. Ainda há entradas disponíveis para o setor visitante, com vendas pelo site oficial do Flamengo.</p>
</details>

<p><em>Fonte: reportagem de Luiza Nascimento publicada em A Tarde em 14 de maio de 2026, com dados oficiais da Secretaria de Mobilidade de Salvador (Semob).</em></p>
HTML,
];

// ════════════════════════════════════════════════════════════════════
// POST 3 — VAGASEBENEFICIOS #20674 — Salário mínimo SP R$ 1.874
// ════════════════════════════════════════════════════════════════════
$post3 = [
    'slug_site' => 'vagasebeneficios', 'trend_id' => 20674,
    'titulo'    => 'Salário mínimo SP 2026: Alesp aprova reajuste para R$ 1.874,36 (alta de 3,9%) e PL segue para sanção',
    'slug'      => 'salario-minimo-sp-2026-alesp-aprova-1874-36-reajuste-tarcisio-sancao',
    'metaDesc'  => 'Alesp aprovou nesta quarta o PL 386/2026 que eleva o salário mínimo em São Paulo para R$ 1.874,36 (alta de 3,9%). Veja quem é beneficiado e quando o reajuste entra em vigor.',
    'focusKw'   => 'salario minimo sp 2026 alesp tarcisio',
    'fonteUrl'  => 'https://www.metropoles.com/sao-paulo/alesp-aprova-projeto-que-eleva-salario-minimo-em-sp-para-r-1-874',
    'fonteNome' => 'Metrópoles', 'autorFonte' => 'Gabrielle Gonçalves',
    'ogImage'   => 'https://i.metroimg.com/XtJOxyOo-sJIvMPD_rkhlbxE3y-I_plbobTOYDpHaXQ/w:1200/q:90/f:webp/plain/https://images.metroimg.com/2025/06/03100603/PMSP-afasta-policiais-acusados-de-agredir-homem-que-passeava-com-gato-52.jpg',
    'categoria' => 'Benefícios Trabalhistas', 'tags' => ['Salário Mínimo', 'São Paulo', 'Alesp', 'Tarcísio de Freitas', 'Trabalhadores Domésticos', 'Cuidadores de Idosos', 'Motoboys'],
    'html' => <<<'HTML'
<p>A <strong>Assembleia Legislativa do Estado de São Paulo (Alesp)</strong> aprovou nesta quarta-feira, 13 de maio de 2026, o <strong>Projeto de Lei 386/2026 que eleva o salário mínimo estadual para R$ 1.874,36</strong>. O texto segue agora para sanção do governador Tarcísio de Freitas (Republicanos), conforme apurou a redação a partir de matéria do Metrópoles assinada por Gabrielle Gonçalves.</p>

<p>O novo piso é 3,9% maior que o valor atual de R$ 1.804, representando aumento nominal de R$ 70,36 por mês. Comparado ao salário mínimo nacional de R$ 1.621, o piso paulista fica 15,6% acima.</p>

<p>A medida atinge cerca de 70 categorias profissionais que não têm piso salarial definido por lei federal, convenção coletiva ou acordo coletivo de trabalho.</p>

<h2>Quem é beneficiado pelo novo salário mínimo de SP</h2>

<p>O piso estadual paulista vale apenas para profissões sem definição de salário-base por outro instrumento legal. Entre as principais categorias contempladas pelo reajuste estão:</p>

<ul>
  <li><strong>Trabalhadores domésticos:</strong> empregadas, faxineiras e empregados gerais sem vínculo com convenção;</li>
  <li><strong>Cuidadores de idosos:</strong> profissionais autônomos ou contratados em domicílio sem piso da categoria;</li>
  <li><strong>Motoboys e motofretistas:</strong> entregadores autônomos contratados sem acordo coletivo;</li>
  <li><strong>Demais categorias sem piso definido:</strong> as cerca de 70 ocupações listadas pela legislação paulista.</li>
</ul>

<p>Quem já recebe acima do novo piso não tem alteração imediata. Profissionais com salário entre R$ 1.804 e R$ 1.874 devem ser reajustados pelo empregador a partir da vigência da nova lei.</p>

<h2>Quanto representa o reajuste no bolso do trabalhador</h2>

<p>O aumento nominal é de R$ 70,36 por mês. Em 12 meses, o ganho adicional totaliza R$ 844,32 sobre o piso anterior. Com 13º salário, o impacto anual sobe para R$ 914,68.</p>

<p>O percentual de 3,9% supera a inflação prevista para 2026, segundo projeções do Banco Central, o que representa pequeno ganho real para o trabalhador da categoria. O piso paulista também segue acima do mínimo nacional, mantendo São Paulo na liderança entre os estados com regime próprio de piso.</p>

<h2>Diferença entre salário mínimo estadual e federal</h2>

<p>O Brasil tem salário mínimo nacional definido pelo governo federal, em R$ 1.621 para 2026. Cinco estados, no entanto, mantêm pisos próprios mais altos: São Paulo, Rio de Janeiro, Paraná, Santa Catarina e Rio Grande do Sul.</p>

<p>O piso estadual vale para trabalhadores que atuam nesses estados em ocupações sem outro piso definido. O salário federal segue valendo para o restante do país e para categorias com convenção coletiva específica que não chega ao patamar estadual.</p>

<h2>Quando o novo piso entra em vigor</h2>

<p>A vigência da nova faixa salarial depende da sanção do governador Tarcísio de Freitas e da publicação no Diário Oficial do Estado. O prazo regimental é de 15 dias úteis após o recebimento do texto da Alesp.</p>

<p>Em ciclos anteriores, o salário mínimo paulista costuma entrar em vigor com retroatividade a janeiro do ano de referência, garantindo o ajuste salarial sobre os meses já trabalhados. A decisão final sobre retroatividade consta no decreto regulamentador publicado após a sanção.</p>

<details class='faq-discover'>
<summary><strong>Qual é o novo salário mínimo de São Paulo em 2026?</strong></summary>
<p>O novo salário mínimo do estado de São Paulo será de R$ 1.874,36 quando entrar em vigor, conforme o Projeto de Lei 386/2026 aprovado pela Alesp em 13 de maio de 2026. O valor representa aumento de 3,9% sobre o piso anterior de R$ 1.804.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quem recebe o salário mínimo estadual em SP?</strong></summary>
<p>Recebem cerca de 70 categorias profissionais sem piso definido por lei federal, convenção coletiva ou acordo coletivo, incluindo trabalhadores domésticos, cuidadores de idosos, motoboys e demais autônomos sem instrumento próprio de negociação.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto é o salário mínimo nacional em 2026?</strong></summary>
<p>O salário mínimo nacional em 2026 é de R$ 1.621. O piso paulista de R$ 1.874,36 supera o federal em 15,6%, mas vale apenas para trabalhadores de SP em categorias sem outro piso aplicável.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quando o novo salário mínimo de SP entra em vigor?</strong></summary>
<p>A vigência depende da sanção do governador Tarcísio de Freitas e da publicação no Diário Oficial do Estado, com prazo regimental de 15 dias úteis. Em ciclos anteriores, o reajuste costuma entrar em vigor com retroatividade a janeiro do ano de referência.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quais estados têm salário mínimo regional próprio?</strong></summary>
<p>Cinco estados mantêm piso salarial próprio acima do nacional: São Paulo, Rio de Janeiro, Paraná, Santa Catarina e Rio Grande do Sul. Cada um define o valor para categorias sem piso por convenção coletiva.</p>
</details>

<p><em>Fonte: reportagem de Gabrielle Gonçalves publicada em Metrópoles em 13 de maio de 2026, sobre votação na Assembleia Legislativa do Estado de São Paulo (Alesp).</em></p>
HTML,
];

// ════════════════════════════════════════════════════════════════════
// POST 4 — GUIADOSCURSOS #21659 — CEDERJ 7.505 vagas graduação
// ════════════════════════════════════════════════════════════════════
$post4 = [
    'slug_site' => 'guiadoscursos', 'trend_id' => 21659,
    'titulo'    => 'CEDERJ 2026-2: 7.505 vagas gratuitas em 17 cursos de graduação a distância; inscrições até 17 de maio',
    'slug'      => 'cederj-2026-2-vestibular-7505-vagas-graduacao-ead-semipresencial-inscricoes-17-maio',
    'metaDesc'  => 'O Vestibular CEDERJ 2026-2 oferece 7.505 vagas em 17 cursos de graduação semipresencial e a distância. Inscrições até 17/05 por R$ 95,50. Veja os cursos e como participar.',
    'focusKw'   => 'cederj 2026-2 vagas graduacao vestibular',
    'fonteUrl'  => 'https://www.horabrasil.com.br/2026/05/14/cederj-oferta-mais-de-7-mil-vagas-em-cursos-de-graduacao/',
    'fonteNome' => 'Hora Brasil', 'autorFonte' => 'Flávia',
    'ogImage'   => 'https://www.horabrasil.com.br/wp-content/uploads/2023/03/cursos-tecnicos-e-de-graduacao-ifsudeste.jpg',
    'categoria' => 'Vestibular', 'tags' => ['CEDERJ', 'Vestibular', 'Graduação EAD', 'Rio de Janeiro', 'CECIERJ', 'Pedagogia', 'Administração', 'Licenciatura', 'Curso Gratuito'],
    'html' => <<<'HTML'
<p>A <strong>Fundação CECIERJ / Consórcio CEDERJ</strong> recebe inscrições até <strong>17 de maio de 2026</strong> para <strong>7.505 vagas gratuitas em 17 cursos de graduação</strong> nos formatos semipresencial e a distância. As aulas começam no segundo semestre letivo de 2026, conforme apurou a redação a partir de matéria do Hora Brasil.</p>

<p>As oportunidades cobrem áreas como Pedagogia, Administração, Engenharia de Produção, Ciências Biológicas, Matemática e Sistemas de Computação. A oferta abrange bacharelados, licenciaturas e tecnólogos, com formação reconhecida pelo MEC e diplomado por instituições do Consórcio CEDERJ.</p>

<p>A taxa de inscrição é de R$ 95,50, e a seleção é feita por prova objetiva e redação, marcadas para o dia 14 de junho de 2026.</p>

<h2>Cursos e vagas oferecidos no CEDERJ 2026-2</h2>

<p>O edital contempla 17 cursos distribuídos entre as universidades parceiras do Consórcio (UFF, UERJ, UENF, UFRJ, UFRRJ, UNIRIO e CEFET/RJ). A oferta por curso é apresentada abaixo.</p>

<ul>
  <li><strong>Pedagogia (Licenciatura):</strong> 1.120 vagas;</li>
  <li><strong>Ciências Biológicas (Licenciatura):</strong> 756 vagas;</li>
  <li><strong>Administração:</strong> 710 vagas;</li>
  <li><strong>Sistemas de Computação (Tecnologia):</strong> 645 vagas;</li>
  <li><strong>Ciências Contábeis:</strong> 590 vagas;</li>
  <li><strong>Matemática (Licenciatura):</strong> 545 vagas;</li>
  <li><strong>Engenharia de Produção:</strong> 440 vagas;</li>
  <li><strong>Segurança Pública (Tecnologia):</strong> 350 vagas;</li>
  <li><strong>Física (Licenciatura):</strong> 320 vagas;</li>
  <li><strong>Formação Pedagógica em Administração:</strong> 300 vagas;</li>
  <li><strong>Química (Licenciatura):</strong> 289 vagas;</li>
  <li><strong>Administração Pública:</strong> 280 vagas;</li>
  <li><strong>Geografia (Licenciatura):</strong> 280 vagas;</li>
  <li><strong>Letras (Licenciatura):</strong> 280 vagas;</li>
  <li><strong>Gestão de Turismo (Tecnologia):</strong> 250 vagas;</li>
  <li><strong>História (Licenciatura):</strong> 200 vagas;</li>
  <li><strong>Design Gráfico (Tecnologia):</strong> 150 vagas.</li>
</ul>

<p>O candidato escolhe o curso e o polo regional de oferta no momento da inscrição. Os polos estão distribuídos pelo estado do Rio de Janeiro, permitindo que estudantes de diferentes regiões cursem sem precisar se deslocar para a capital.</p>

<h2>Quem pode participar do Vestibular CEDERJ 2026-2</h2>

<p>O processo seletivo é aberto a candidatos que já concluíram ou venham a concluir o Ensino Médio até a data da matrícula nos cursos de graduação. Não há restrição de idade, escolaridade prévia adicional ou exigência de experiência profissional.</p>

<p>As licenciaturas têm reserva específica de vagas para professores da rede pública que ainda não possuem formação na área em que lecionam, conforme regras detalhadas no edital. O sistema de cotas segue a legislação federal, com vagas para estudantes de escola pública, baixa renda e ações afirmativas.</p>

<h2>Como se inscrever no Vestibular CEDERJ 2026-2</h2>

<p>As inscrições são feitas exclusivamente pela internet até 17 de maio. O passo a passo é direto.</p>

<ol>
  <li>Acessar o site oficial: <a href='https://www.cecierj.edu.br/consorciocederj/vestibular/2026-2/' target='_blank' rel='noopener'>cecierj.edu.br/consorciocederj/vestibular/2026-2</a>;</li>
  <li>Preencher o formulário com dados pessoais, documentos e escolaridade;</li>
  <li>Escolher o curso e o polo regional de interesse;</li>
  <li>Pagar a taxa de inscrição de R$ 95,50 via boleto, Pix ou cartão;</li>
  <li>Acompanhar o cronograma do edital para a data da prova.</li>
</ol>

<div class='cta-oficial' style='margin:24px 0;padding:18px 22px;background:#eef6f0;border-left:6px solid #1f8a4c;border-radius:6px;'><p style='margin:0 0 8px;font-size:17px;color:#1a2a1f;'><strong>Inscrição oficial Vestibular CEDERJ 2026-2</strong></p><p style='margin:0 0 12px;font-size:14px;color:#3a4a3f;'>Prazo final: 17 de maio de 2026. Prova: 14 de junho. Taxa: R$ 95,50.</p><a href='https://www.cecierj.edu.br/consorciocederj/vestibular/2026-2/' target='_blank' rel='noopener' style='display:inline-block;background:#1f8a4c;color:#fff;font-weight:600;font-size:15px;padding:11px 22px;border-radius:5px;text-decoration:none;'>Acessar cecierj.edu.br</a></div>

<h2>Como será a seleção dos candidatos</h2>

<p>A seleção é feita por Prova Objetiva e Prova de Redação, ambas no dia 14 de junho de 2026. As provas seguem o edital específico do Vestibular CEDERJ 2026-2, publicado pelo Consórcio CECIERJ.</p>

<p>O resultado costuma sair entre 30 e 45 dias após a aplicação, com chamada de aprovados publicada no portal oficial. O início das aulas está previsto para o segundo semestre letivo de 2026.</p>

<details class='faq-discover'>
<summary><strong>Quantas vagas o CEDERJ está oferecendo no Vestibular 2026-2?</strong></summary>
<p>O Vestibular CEDERJ 2026-2 oferece 7.505 vagas gratuitas distribuídas em 17 cursos de graduação nos formatos semipresencial e a distância, com início das aulas previsto para o segundo semestre de 2026.</p>
</details>

<details class='faq-discover'>
<summary><strong>Até quando posso me inscrever no Vestibular CEDERJ 2026-2?</strong></summary>
<p>As inscrições vão até 17 de maio de 2026 e devem ser feitas exclusivamente pelo site oficial cecierj.edu.br/consorciocederj/vestibular/2026-2. A taxa é de R$ 95,50.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual curso oferece mais vagas no CEDERJ 2026-2?</strong></summary>
<p>Pedagogia é o curso com maior oferta no CEDERJ 2026-2, com 1.120 vagas. Em seguida vêm Ciências Biológicas (756), Administração (710) e Sistemas de Computação (645).</p>
</details>

<details class='faq-discover'>
<summary><strong>Quando será a prova do Vestibular CEDERJ 2026-2?</strong></summary>
<p>A Prova Objetiva e a Prova de Redação serão aplicadas no dia 14 de junho de 2026, em local indicado pelo edital após o encerramento das inscrições.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quem pode participar do Vestibular CEDERJ 2026-2?</strong></summary>
<p>Podem participar candidatos que já concluíram ou venham a concluir o Ensino Médio até a data da matrícula nos cursos de graduação. Não há restrição de idade. As licenciaturas têm reserva de vagas para professores da rede pública sem formação na área.</p>
</details>

<p><em>Fonte: matéria de Flávia publicada em Hora Brasil em 14 de maio de 2026, com base no edital oficial do Vestibular CEDERJ 2026-2 publicado pela Fundação CECIERJ.</em></p>
HTML,
];

// ════════════════════════════════════════════════════════════════════
// Publicação batch
// ════════════════════════════════════════════════════════════════════
$resultados = [];
foreach ([$post1, $post2, $post3, $post4] as $info) {
    echo "\n══════ {$info['slug_site']} — trend #{$info['trend_id']} ══════\n";
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $info['slug_site']);
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

    // Featured
    $featuredId = 0;
    try {
        $featuredId = (int)($wp->uploadImagemPorUrl169($info['ogImage'], $info['titulo'], $info['slug']) ?? 0);
        if ($featuredId > 0) echo "✅ Featured 16:9: media #{$featuredId}\n";
    } catch (Throwable $e) { echo "uploadImagemPorUrl169: " . $e->getMessage() . "\n"; }
    if ($featuredId === 0) {
        try {
            $featuredId = (int)($wp->uploadImagemPorUrl($info['ogImage'], $info['titulo'], $info['slug']) ?? 0);
            if ($featuredId > 0) echo "✅ Featured original: media #{$featuredId}\n";
        } catch (Throwable $e) { echo "uploadImagemPorUrl fallback: " . $e->getMessage() . "\n"; }
    }
    if ($featuredId > 0) {
        $wp->atualizarMedia($featuredId, [
            'caption'     => "{$info['titulo']} (Foto: {$info['fonteNome']} / divulgação)",
            'description' => "Imagem ilustrativa da matéria '{$info['titulo']}'.",
            'title'       => $info['titulo'],
            'alt_text'    => $info['titulo'],
        ]);
    }

    // Schemas
    $schemaNews = [
        '@context' => 'https://schema.org', '@type' => 'NewsArticle',
        'headline' => $info['titulo'], 'datePublished' => date('c'), 'dateModified' => date('c'), 'inLanguage' => 'pt-BR',
        'citation' => [
            '@type' => 'NewsArticle', 'url' => $info['fonteUrl'],
            'publisher' => ['@type' => 'NewsMediaOrganization', 'name' => $info['fonteNome'], 'url' => parse_url($info['fonteUrl'], PHP_URL_SCHEME) . '://' . parse_url($info['fonteUrl'], PHP_URL_HOST)],
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
        'title' => $info['titulo'], 'slug' => $info['slug'], 'content' => $contentFinal,
        'status' => 'draft',
        'meta' => [
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
    if ($postId === 0) { echo "❌ ERRO criarPost\n"; $resultados[] = "  {$info['slug_site']}: FALHOU"; continue; }
    echo "✅ Post #{$postId} DRAFT\n   Link: {$link}\n";

    // Posts relacionados
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
echo "\nTrends DB remoto pra UPDATE (via SSH):\n";
echo "  UPDATE trends SET status='publicado', post_id=X, url_post='Y' WHERE id IN (21422, 21314, 20674, 21659);\n";
