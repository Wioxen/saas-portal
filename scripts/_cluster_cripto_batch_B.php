<?php
declare(strict_types=1);
/**
 * Cluster cripto comocomprar — Batch B (3 posts de gestão).
 * Posts: Declaração IR 2026, comparativo exchanges, hot vs cold wallet.
 * Autoria = Redação do Como Comprar. Featured 16:9 WebP gogleads.
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

// ════════════════════════════════════════════════════════════════════
// POST 4 — Declaração de criptomoedas no IR 2026
// ════════════════════════════════════════════════════════════════════
$p4 = [
    'titulo' => 'Como declarar criptomoedas no Imposto de Renda 2026: ficha Bens código 81, DARF 4600 e IN RFB 1.888',
    'slug'   => 'declarar-criptomoedas-imposto-renda-2026-ficha-bens-codigo-81-darf-4600',
    'metaDesc' => 'Guia completo para declarar criptomoedas no IR 2026: quem é obrigado, código 81 na ficha Bens e Direitos, isenção até R$ 35 mil/mês, alíquotas 15-22,5%, DARF 4600 e obrigação acessória IN RFB 1.888/2019.',
    'focusKw' => 'declarar criptomoedas imposto de renda 2026',
    'ogUrl' => 'https://livecoins.com.br/wp-content/uploads/2025/03/ir-2025-ativos-exterior.jpg.webp',
];
$p4['html'] = <<<'HTML'
<p><strong>Declarar criptomoedas no Imposto de Renda 2026</strong> segue regras consolidadas pela Receita Federal desde 2019. Quem teve posição em criptoativos acima de R$ 5 mil em 31 de dezembro do ano anterior, ou vendeu mais de R$ 35 mil em um único mês, tem obrigação clara perante o fisco. As duas frentes — declaração anual (DIRPF) e obrigação acessória mensal (IN RFB 1.888/2019) — exigem entendimento distinto.</p>

<p>O guia abaixo cobre as 3 situações que geram obrigação tributária em cripto, o passo a passo no programa da Receita Federal, o cálculo do imposto sobre ganho de capital, o preenchimento da DARF código 4600 e o formulário mensal específico para operações fora de exchanges brasileiras.</p>

<p>O conteúdo é educacional. Em casos com volume alto, operações em exchange estrangeira, ganhos significativos ou dúvidas técnicas, consultar um contador especializado em criptoativos é a prática recomendada.</p>

<h2>Quem é obrigado a declarar criptomoedas no IR 2026</h2>

<p>Existem 2 caminhos independentes pelos quais cripto entra no Imposto de Renda:</p>

<ul>
  <li><strong>Declaração anual (DIRPF):</strong> obrigatória para quem teve <strong>posição superior a R$ 5 mil em qualquer criptoativo individualmente em 31 de dezembro</strong> de 2025. A soma se aplica por tipo de moeda, não pelo total da carteira. Ter R$ 4.500 em BTC e R$ 4.500 em ETH não obriga; ter R$ 5.001 em BTC sozinho obriga;</li>
  <li><strong>Obrigação acessória mensal (IN RFB 1.888/2019):</strong> obrigatória para operações <strong>fora de exchanges brasileiras</strong> (P2P, DEXs, exchanges estrangeiras) que somem mais de R$ 30 mil em um único mês. Aplica-se também a pessoa jurídica em qualquer volume.</li>
</ul>

<p>As exchanges brasileiras (Mercado Bitcoin, Binance Brasil, NovaDAX, Foxbit) já enviam dados de todas as operações dos usuários à Receita Federal, então o brasileiro que opera só dentro de plataformas nacionais não precisa entregar o formulário mensal — só a declaração anual quando aplicável.</p>

<h2>Passo a passo: declaração anual na ficha Bens e Direitos</h2>

<p>O lançamento na DIRPF é feito na ficha <strong>Bens e Direitos</strong>, com código específico para criptoativos:</p>

<ol>
  <li>Abrir o programa da Receita Federal (PGD IRPF) ou a versão online no e-CAC;</li>
  <li>Acessar a ficha Bens e Direitos → Novo;</li>
  <li>Em Grupo, selecionar <strong>08 — Criptoativos</strong>;</li>
  <li>Em Código, escolher o código específico do ativo:
    <ul>
      <li><strong>81 — Criptoativo Bitcoin (BTC)</strong></li>
      <li><strong>82 — Outros criptoativos do tipo moeda</strong> (ETH, LTC, BCH, etc.)</li>
      <li><strong>83 — Stablecoins</strong> (USDT, USDC, BUSD)</li>
      <li><strong>89 — Demais criptoativos</strong> (NFTs e tokens diversos)</li>
    </ul>
  </li>
  <li>Preencher Discriminação com detalhes: nome do criptoativo, quantidade, exchange ou wallet onde está custodiado, CPF/CNPJ da custodiante quando aplicável;</li>
  <li>Em Situação em 31/12/2024, informar o valor que estava em posse no ano anterior;</li>
  <li>Em Situação em 31/12/2025, informar o valor atual. <strong>Importante:</strong> o valor é o de aquisição (preço pago), não o valor de mercado atual. Cripto não tem atualização anual a mercado na DIRPF.</li>
</ol>

<h2>Cálculo do imposto sobre ganho de capital</h2>

<p>Cripto é tratado como bem, e o lucro na venda é tributado como ganho de capital. As regras essenciais:</p>

<ul>
  <li><strong>Isenção mensal:</strong> vendas até R$ 35 mil em um único mês (somando todas as criptos) são isentas de IR sobre ganho de capital;</li>
  <li><strong>Acima do limite:</strong> incide alíquota progressiva sobre o lucro:
    <ul>
      <li>15% sobre ganhos até R$ 5 milhões;</li>
      <li>17,5% entre R$ 5 milhões e R$ 10 milhões;</li>
      <li>20% entre R$ 10 milhões e R$ 30 milhões;</li>
      <li>22,5% acima de R$ 30 milhões.</li>
    </ul>
  </li>
  <li><strong>Custo de aquisição:</strong> o ganho é calculado como (valor de venda) − (preço médio de aquisição). Quem comprou em várias datas precisa calcular o preço médio ponderado;</li>
  <li><strong>Apuração mensal:</strong> o imposto é apurado mês a mês, fechando 100% até o último dia útil do mês seguinte.</li>
</ul>

<h2>Como preencher e pagar a DARF 4600</h2>

<p>O imposto sobre ganho de capital em cripto é recolhido via DARF (Documento de Arrecadação de Receitas Federais) com código próprio. Os passos:</p>

<ol>
  <li>Calcular o ganho total do mês (soma dos lucros de todas as vendas);</li>
  <li>Aplicar a alíquota correspondente (15% para a maioria das pessoas físicas);</li>
  <li>Acessar o site da Receita Federal (gov.br/receitafederal) → Pagamento e Parcelamento → Sicalc Web;</li>
  <li>Preencher: Código <strong>4600</strong> (Ganho de Capital de Pessoa Física em Criptoativos), Período de Apuração (mês da venda), Vencimento (último dia útil do mês seguinte), Valor Principal (imposto calculado);</li>
  <li>Imprimir a DARF gerada e pagar via Pix, código de barras ou direto no banco;</li>
  <li>Guardar comprovante para reportar na DIRPF no ano seguinte.</li>
</ol>

<h2>O formulário mensal da IN RFB 1.888/2019</h2>

<p>Para operações <strong>fora das exchanges brasileiras</strong> que somam mais de R$ 30 mil em um único mês, existe obrigação adicional independente da DIRPF. O canal:</p>

<ol>
  <li>Acessar o e-CAC → Serviços → Declarações e Demonstrativos → Conjunto de Demonstrativos Coleta de Dados de Operações com Criptoativos;</li>
  <li>Preencher mês a mês: data da operação, tipo (compra, venda, permuta, doação), criptoativo, quantidade, valor em BRL, contraparte (CPF/CNPJ ou endereço de wallet);</li>
  <li>Enviar até o último dia útil do mês subsequente ao da operação;</li>
  <li>Multa pelo atraso: R$ 100/mês para pessoa física, R$ 500/mês para pessoa jurídica.</li>
</ol>

<p>Quem opera apenas em exchanges brasileiras (Mercado Bitcoin, Binance Brasil, NovaDAX, Foxbit, BitPreço) está dispensado do formulário — as próprias plataformas já reportam à Receita pelo sistema delas.</p>

<h2>Erros comuns que geram problema na Receita</h2>

<p>Os 5 erros mais frequentes em declaração de cripto:</p>

<ul>
  <li><strong>Esquecer a obrigação acessória mensal:</strong> quem opera P2P ou em exchange estrangeira e não entrega o formulário acumula multa de R$ 100/mês;</li>
  <li><strong>Declarar pelo valor de mercado em 31/12:</strong> o correto é valor de aquisição (preço pago), não cotação atual;</li>
  <li><strong>Não pagar DARF dentro do mês:</strong> imposto vence no último dia útil do mês seguinte à operação. Atraso gera juros Selic + multa de 0,33% ao dia (limite 20%);</li>
  <li><strong>Esquecer transferências entre wallets:</strong> mover ETH da exchange para MetaMask não é venda, não é fato gerador, mas vale registrar para histórico próprio;</li>
  <li><strong>Misturar contas pessoais com PJ:</strong> empresário que opera cripto pela empresa precisa lançar no balanço da PJ, com tributação corporativa diferente.</li>
</ul>

<h2>Software vs planilha: como organizar o controle</h2>

<p>Quem faz poucas operações por ano consegue controlar em planilha (Excel, Google Sheets) com colunas para data, ativo, tipo, quantidade, valor em BRL e exchange. Para mais de 50 operações/ano, vale considerar ferramentas especializadas:</p>

<ul>
  <li><strong>Bitcoin.Tax / Koinly:</strong> importam histórico de várias exchanges via API ou CSV, calculam ganho médio, geram relatório pronto para o IR;</li>
  <li><strong>Contabilizei Cripto:</strong> versão brasileira de contabilidade automatizada, gera relatório no formato da Receita;</li>
  <li><strong>Mycrypto.pro:</strong> agregador brasileiro que conecta direto às principais exchanges nacionais e calcula imposto mensal.</li>
</ul>

<p>Os serviços cobram entre R$ 100 e R$ 500 por ano dependendo do volume. Para quem opera valores significativos, o custo se justifica pela redução de erros e tempo gasto.</p>

<details class='faq-discover'>
<summary><strong>Preciso declarar cripto se nunca vendi?</strong></summary>
<p>Sim, quando a posição em 31/12 ultrapassa R$ 5 mil em qualquer criptoativo individualmente. A declaração na ficha Bens e Direitos é obrigatória mesmo sem ter vendido nada, com o valor de aquisição (preço pago) no campo Situação em 31/12.</p>
</details>

<details class='faq-discover'>
<summary><strong>Vendi cripto com prejuízo, ainda preciso declarar?</strong></summary>
<p>Sim. Mesmo com prejuízo, a venda precisa ser reportada se houver obrigação acessória (volume mensal acima de R$ 30 mil em exchange estrangeira ou P2P). Prejuízos em criptoativos podem ser compensados com lucros futuros do mesmo tipo de ativo no mesmo ano-calendário.</p>
</details>

<details class='faq-discover'>
<summary><strong>A Receita Federal tem como saber se tenho cripto?</strong></summary>
<p>Sim. Exchanges brasileiras são obrigadas pela IN RFB 1.888/2019 a enviar dados mensais de todas as operações dos usuários. A Receita cruza essas informações com a declaração do contribuinte. Operações em exchange estrangeira ou P2P sem reporte caem na malha fina e podem gerar autuação com multa de 75% a 150% do imposto devido.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual o código correto para Bitcoin no Imposto de Renda 2026?</strong></summary>
<p>Código 81 (Criptoativo Bitcoin BTC), dentro do grupo 08 (Criptoativos) da ficha Bens e Direitos. Para Ethereum, Litecoin e demais criptos do tipo moeda, usar código 82. Para stablecoins (USDT, USDC), código 83. NFTs e tokens diversos, código 89.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso compensar prejuízo de cripto com lucro de ações?</strong></summary>
<p>Não diretamente. Pela legislação brasileira, prejuízos em cripto só podem ser compensados com lucros em cripto futuros (mesmo tipo de ativo). Já lucros e prejuízos em ações seguem regra própria de mercado de capitais, separada da tributação de criptoativos.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional. Não constitui aconselhamento contábil ou tributário. Em casos complexos, consulte um contador especializado em criptoativos.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// POST 5 — Melhor exchange brasileira (comparativo)
// ════════════════════════════════════════════════════════════════════
$p5 = [
    'titulo' => 'Melhor exchange brasileira em 2026: comparativo Mercado Bitcoin × Binance × Foxbit × NovaDAX × BitPreço',
    'slug'   => 'melhor-exchange-brasileira-2026-comparativo-mercado-bitcoin-binance-foxbit-novadax-bitpreco',
    'metaDesc' => 'Comparativo das 5 principais exchanges brasileiras em 2026: Mercado Bitcoin, Binance, Foxbit, NovaDAX e BitPreço. Taxas, mínimos, catálogo, regulação e qual escolher pelo seu perfil.',
    'focusKw' => 'melhor exchange brasileira criptomoedas',
    'ogUrl' => 'https://br.coingape.com/wp-content/uploads/2024/10/prime-xbt.webp',
];
$p5['html'] = <<<'HTML'
<p>Escolher a <strong>melhor exchange brasileira para comprar criptomoedas em 2026</strong> depende do perfil do usuário. Cinco plataformas dominam o mercado nacional com operação consolidada, regulação seguindo a Lei 14.478/2022 e integração ao Pix: Mercado Bitcoin, Binance, Foxbit, NovaDAX e BitPreço. Cada uma tem força em um aspecto diferente.</p>

<p>O comparativo abaixo cobre os 6 critérios que mais pesam na decisão: taxas, valor mínimo de operação, catálogo de criptomoedas, regulamentação, suporte em português e ferramentas avançadas. No fim, a recomendação por perfil — iniciante absoluto, investidor de médio porte, trader ativo e quem busca menor custo.</p>

<p>Nenhuma exchange é melhor universal. A escolha certa depende do volume mensal de operação, da quantidade de altcoins que se pretende negociar e da preferência por suporte em português ou ferramentas avançadas.</p>

<h2>Mercado Bitcoin: a maior exchange brasileira</h2>

<p>Fundado em 2013, o Mercado Bitcoin (MB) é a exchange brasileira com maior volume histórico e a primeira a operar Pix em escala. A empresa opera sob CNPJ Mercado Bitcoin Serviços Digitais Ltda, com sede em São Paulo.</p>

<ul>
  <li><strong>Pontos fortes:</strong> interface mais simples do mercado, suporte 24/7 em português, integração nativa com Pix, mínimo de R$ 1 para começar, parceria oficial com Nubank Cripto;</li>
  <li><strong>Pontos fracos:</strong> catálogo menor que Binance (cerca de 30 criptoativos vs centenas), taxas levemente acima da média (0,3% a 0,7%), poucas ferramentas avançadas;</li>
  <li><strong>Ideal para:</strong> iniciante absoluto, investidor brasileiro que prioriza simplicidade e suporte local, quem quer começar com R$ 50-1.000.</li>
</ul>

<h2>Binance: a maior exchange global com operação local</h2>

<p>A Binance opera no Brasil via parceria com instituições financeiras locais e mantém escritório no país. É a maior exchange do mundo em volume e tem o catálogo mais amplo de criptoativos.</p>

<ul>
  <li><strong>Pontos fortes:</strong> catálogo com mais de 350 criptoativos, taxas baixas (0,1% padrão, 0,075% pagando em BNB), mercado P2P brasileiro próprio, futures e ferramentas avançadas, Binance Earn para staking;</li>
  <li><strong>Pontos fracos:</strong> interface mais complexa para iniciantes, depósito mínimo de R$ 100 via Pix, suporte em português via chat (não 24/7 totalmente nacional);</li>
  <li><strong>Ideal para:</strong> investidor de médio a alto volume, trader que precisa de altcoins menos populares, quem usa P2P, usuário com perfil mais técnico.</li>
</ul>

<h2>Foxbit: a exchange histórica brasileira</h2>

<p>Fundada em 2014, a Foxbit é uma das exchanges nacionais mais antigas ainda em operação. Especializou-se em atender o mercado brasileiro com foco em segurança e suporte humano.</p>

<ul>
  <li><strong>Pontos fortes:</strong> suporte por chat com atendentes humanos, interface intuitiva, integração Pix, histórico longo sem incidentes de segurança graves;</li>
  <li><strong>Pontos fracos:</strong> catálogo limitado (cerca de 20 criptoativos), taxas médias (0,25% a 0,5%), aplicativo móvel com menos recursos que MB e Binance;</li>
  <li><strong>Ideal para:</strong> usuário que valoriza atendimento humano e prefere uma plataforma brasileira de longa tradição, com aporte pequeno a médio (R$ 100-10.000).</li>
</ul>

<h2>NovaDAX: integração Pix e taxas competitivas</h2>

<p>A NovaDAX opera no Brasil desde 2018, pertencente ao grupo NovaDAX Global. Foi uma das pioneiras na integração com Pix e tem taxas competitivas.</p>

<ul>
  <li><strong>Pontos fortes:</strong> taxa de 0,25% para iniciantes, suporte em português, app móvel completo, catálogo amplo (cerca de 100 criptoativos), promoções recorrentes de zero taxa em pares específicos;</li>
  <li><strong>Pontos fracos:</strong> volume menor que MB e Binance pode resultar em spread maior em altcoins menos negociados, suporte com tempos de resposta variáveis;</li>
  <li><strong>Ideal para:</strong> investidor que opera em vários criptoativos e quer taxas baixas, perfil intermediário entre MB (simples) e Binance (avançada).</li>
</ul>

<h2>BitPreço: o agregador que roteia para melhor preço</h2>

<p>A BitPreço opera de forma diferente das demais. Em vez de ser uma exchange tradicional, funciona como agregador: compara preços em várias exchanges em tempo real e executa a compra na que oferece o melhor valor naquele momento.</p>

<ul>
  <li><strong>Pontos fortes:</strong> sempre executa pelo melhor preço efetivo do mercado, taxa única transparente (1% sobre a operação), interface simples, integração Pix;</li>
  <li><strong>Pontos fracos:</strong> taxa relativamente alta (1%) compensada apenas em altcoins onde o spread entre exchanges é grande, catálogo limitado às criptos mais negociadas, custódia depende da exchange parceira escolhida;</li>
  <li><strong>Ideal para:</strong> investidor que opera valores grandes (R$ 10 mil ou mais) onde 1% a 2% de spread entre exchanges faz diferença, e que valoriza simplicidade no roteamento.</li>
</ul>

<h2>Comparativo direto: taxas e mínimos</h2>

<p>Para o investidor que vai comprar R$ 500 a R$ 5 mil em Bitcoin ou Ethereum, os custos efetivos por operação ficam:</p>

<ul>
  <li><strong>Mercado Bitcoin:</strong> 0,3% a 0,7% de taxa + 0,5% a 1% de spread = ~1% a 1,5% total;</li>
  <li><strong>Binance:</strong> 0,1% de taxa + 0,1% a 0,3% de spread = ~0,3% a 0,5% total (vantagem em volume);</li>
  <li><strong>Foxbit:</strong> 0,25% a 0,5% + 0,5% a 1% = ~0,8% a 1,5% total;</li>
  <li><strong>NovaDAX:</strong> 0,25% + 0,3% a 0,8% = ~0,55% a 1% total;</li>
  <li><strong>BitPreço:</strong> 1% taxa única (já inclui spread) = ~1% total fixo.</li>
</ul>

<p>Para volume baixo (R$ 50-500), Mercado Bitcoin compensa pela simplicidade. Para volume médio (R$ 500-5.000), NovaDAX e Binance se destacam. Para volume alto (R$ 5.000+), Binance e BitPreço dominam.</p>

<h2>Regulação e segurança: o que vale checar antes</h2>

<p>Todas as 5 plataformas têm CNPJ ativo no Brasil e operam dentro da Lei 14.478/2022. Os pontos de verificação adicionais:</p>

<ul>
  <li><strong>CNPJ ativo na Receita Federal:</strong> conferir em consulta pública no site da Receita;</li>
  <li><strong>Histórico de incidentes:</strong> Mercado Bitcoin e Foxbit operam há mais de 10 anos sem brechas graves; NovaDAX e BitPreço têm 5+ anos limpos;</li>
  <li><strong>Reserva de prova (Proof of Reserves):</strong> Binance publica relatório PoR mostrando que tem reservas equivalentes aos saldos dos usuários. MB também publica;</li>
  <li><strong>Suporte ao pedido judicial:</strong> em casos extremos (suspeita de uso indevido da conta), exchanges nacionais respondem ao Judiciário brasileiro com mais agilidade que estrangeiras.</li>
</ul>

<h2>Recomendação por perfil</h2>

<p>A resposta prática para a pergunta "qual é a melhor?", segmentada por perfil:</p>

<ul>
  <li><strong>Iniciante absoluto, R$ 100 por mês:</strong> Mercado Bitcoin. Interface simples, mínimo de R$ 1, parceria com Nubank;</li>
  <li><strong>Investidor médio, R$ 1.000 por mês em DCA:</strong> NovaDAX ou Binance. Taxas baixas, catálogo amplo;</li>
  <li><strong>Trader ativo, várias operações por semana:</strong> Binance. Ferramentas profissionais, futures, P2P, menor taxa por operação;</li>
  <li><strong>Aporte alto pontual (R$ 50 mil em BTC):</strong> Binance P2P ou BitPreço. Spread menor em volume grande;</li>
  <li><strong>Prefere atendimento humano e suporte por telefone:</strong> Foxbit.</li>
</ul>

<details class='faq-discover'>
<summary><strong>Qual exchange brasileira tem a menor taxa?</strong></summary>
<p>Binance, com 0,1% padrão (0,075% pagando em BNB). NovaDAX vem em segundo com 0,25%. Para volume alto, Binance se destaca pela combinação de taxa baixa e spread reduzido. BitPreço cobra 1% único mas inclui o roteamento para a melhor exchange disponível.</p>
</details>

<details class='faq-discover'>
<summary><strong>Mercado Bitcoin é confiável em 2026?</strong></summary>
<p>Sim. Opera desde 2013 sob CNPJ ativo da Mercado Bitcoin Serviços Digitais Ltda, sem incidentes de segurança graves no histórico recente. Tem parceria oficial com Nubank Cripto, publica relatórios de reserva e responde à regulação brasileira pela Lei 14.478/2022.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso usar mais de uma exchange ao mesmo tempo?</strong></summary>
<p>Sim, e é prática recomendada para diversificar risco de contraparte. Manter saldos em 2 plataformas reduz exposição a falência de uma única empresa. O custo extra é o tempo de gerenciar duas contas. Para volumes acima de R$ 50 mil, o ganho em segurança justifica.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual exchange tem mais criptomoedas listadas no Brasil?</strong></summary>
<p>Binance, com mais de 350 criptoativos negociáveis em pares com BRL ou USDT. NovaDAX vem em segundo com cerca de 100. Mercado Bitcoin lista cerca de 30 criptoativos selecionados, com foco em moedas estabelecidas. Para altcoins novas ou de nicho, Binance é o canal principal.</p>
</details>

<details class='faq-discover'>
<summary><strong>Como saber se uma exchange é confiável?</strong></summary>
<p>Quatro verificações: (1) CNPJ ativo na Receita Federal Brasil, (2) tempo de operação no mercado (5+ anos sem incidentes graves), (3) publicação de Proof of Reserves ou relatórios de auditoria, (4) ausência em listas de não-autorizadas da Comissão de Valores Mobiliários (CVM). As 5 exchanges deste comparativo atendem aos 4 critérios.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional. Não constitui recomendação de investimento ou indicação preferencial de plataforma.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// POST 6 — Carteira hot vs cold wallet
// ════════════════════════════════════════════════════════════════════
$p6 = [
    'titulo' => 'Carteira de criptomoedas em 2026: hot wallet, cold wallet e quando migrar para Ledger ou Trezor',
    'slug'   => 'carteira-criptomoedas-hot-cold-wallet-ledger-trezor-quando-migrar-2026',
    'metaDesc' => 'Guia completo sobre carteiras de criptomoedas em 2026: diferença entre hot wallet (MetaMask, Trust Wallet) e cold wallet (Ledger, Trezor), quando migrar, custos e backup da seed phrase.',
    'focusKw' => 'carteira criptomoedas hot cold wallet',
    'ogUrl' => 'https://kriptobr.com/wp-content/uploads/2024/03/box_content-2.jpg',
];
$p6['html'] = <<<'HTML'
<p>A <strong>carteira de criptomoedas</strong> é o componente que determina quem tem controle real sobre o ativo. Diferente de uma conta bancária, onde o banco custodia o dinheiro, em cripto a chave privada é quem comanda o saldo. Quem detém a chave, detém o ativo. Por isso, a escolha entre hot wallet e cold wallet é uma das decisões técnicas mais importantes para quem investe em criptoativos.</p>

<p>Hot wallets são carteiras conectadas à internet (apps mobile, extensões de navegador). Cold wallets são dispositivos físicos que mantêm a chave privada offline. Cada tipo atende um nível de exposição e segurança diferente, e a regra prática mais usada por gestores conservadores recomenda combinar os dois conforme o tamanho do patrimônio.</p>

<p>O guia abaixo cobre as diferenças entre hot e cold wallet, os modelos mais usados no mercado em 2026, quando vale migrar e como fazer o backup correto da seed phrase — a frase de 12 ou 24 palavras que é o backup definitivo da carteira.</p>

<h2>O que é uma carteira de criptomoedas (e o que ela realmente guarda)</h2>

<p>Diferente do nome sugerir, a carteira de cripto não guarda as moedas em si. As criptomoedas existem na blockchain — um registro distribuído entre milhares de computadores no mundo. O que a carteira guarda é o <strong>par de chaves criptográficas</strong> que prova quem tem direito a movimentar os ativos registrados em determinado endereço.</p>

<ul>
  <li><strong>Chave pública (endereço):</strong> equivale ao número da conta bancária. Pode ser compartilhada livremente para receber transações. Em Ethereum, começa com 0x... e tem 42 caracteres. Em Bitcoin, começa com bc1... ou 1... ou 3...;</li>
  <li><strong>Chave privada:</strong> a senha matemática que autoriza envios. Quem tem a chave privada controla o ativo. Não pode ser compartilhada com ninguém;</li>
  <li><strong>Seed phrase (frase de recuperação):</strong> 12 ou 24 palavras em inglês geradas no momento de criação da carteira. É a representação humana da chave privada — quem tem a seed, recupera a carteira em qualquer dispositivo.</li>
</ul>

<h2>Hot wallet: praticidade conectada à internet</h2>

<p>Hot wallets são carteiras que ficam conectadas à internet via app ou extensão. A chave privada é armazenada localmente no dispositivo (criptografada por senha), mas vulnerável a malware se o dispositivo for comprometido. As mais usadas:</p>

<ul>
  <li><strong>MetaMask:</strong> extensão de navegador e app mobile. Suporta Ethereum e redes EVM compatíveis (Polygon, BSC, Arbitrum, Optimism, Base). Padrão de mercado para DeFi e NFTs;</li>
  <li><strong>Trust Wallet:</strong> app mobile (iOS e Android) com suporte multi-chain (Bitcoin, Ethereum, Solana, BNB Chain, +100 redes). Propriedade da Binance;</li>
  <li><strong>Phantom:</strong> wallet padrão da rede Solana. Extensão de navegador e mobile;</li>
  <li><strong>Exodus:</strong> wallet desktop e mobile com interface amigável e suporte a 200+ criptos. Boa para iniciantes;</li>
  <li><strong>Rabby Wallet:</strong> alternativa à MetaMask focada em DeFi, com simulação de transações antes de aprovar (reduz risco de assinar contratos maliciosos).</li>
</ul>

<p>Vantagens das hot wallets: gratuitas, acesso rápido para swaps e operações em DEXs, integração nativa com sites Web3 via WalletConnect. Desvantagens: vulneráveis a malware, phishing e ataques via aprovações maliciosas em contratos inteligentes.</p>

<h2>Cold wallet: chave privada offline em dispositivo físico</h2>

<p>Cold wallets (ou hardware wallets) são dispositivos físicos que armazenam a chave privada offline. Para autorizar uma transação, o dispositivo precisa ser conectado fisicamente (USB ou Bluetooth) e a operação confirmada pressionando o botão físico. A chave privada nunca toca a internet, eliminando risco de malware remoto.</p>

<p>Os 3 fabricantes que dominam o mercado em 2026:</p>

<ul>
  <li><strong>Ledger:</strong> empresa francesa, fundada em 2014. Modelos Nano S Plus (R$ 400) e Nano X (R$ 900, com Bluetooth). Suporta mais de 5.000 criptoativos. App Ledger Live faz a interface;</li>
  <li><strong>Trezor:</strong> empresa tcheca, primeiro hardware wallet do mercado (lançado em 2014). Modelo One (R$ 500) e Model T (R$ 1.500, com tela colorida touch). Suporte forte para Bitcoin e altcoins principais;</li>
  <li><strong>SafePal:</strong> alternativa mais barata (R$ 250-500), foco em mercado emergente. Integração com Binance.</li>
</ul>

<h2>Quando migrar de hot wallet para cold wallet</h2>

<p>A regra prática usada por gestores conservadores escalona pelo valor em risco:</p>

<ul>
  <li><strong>Até R$ 5 mil:</strong> custódia na exchange ou em hot wallet é suficiente. O custo de uma cold wallet (R$ 400-1.500) não se justifica;</li>
  <li><strong>R$ 5 mil a R$ 50 mil:</strong> migrar para hot wallet pessoal (MetaMask, Trust) reduz risco de falência da exchange. Cold wallet vira opcional dependendo do perfil de risco;</li>
  <li><strong>R$ 50 mil ou mais:</strong> hardware wallet é praticamente obrigatório. O investimento de R$ 500-1.500 protege patrimônio significativo;</li>
  <li><strong>Acima de R$ 500 mil:</strong> considerar setup multi-sig (transações exigem 2 ou 3 assinaturas de wallets diferentes) ou divisão entre 2 hardware wallets em locais distintos.</li>
</ul>

<h2>Como configurar uma cold wallet pela primeira vez</h2>

<p>O processo leva cerca de 20-30 minutos e exige cuidado com a seed phrase. Passos genéricos (variando levemente por fabricante):</p>

<ol>
  <li><strong>Compra direta:</strong> adquirir o dispositivo direto do fabricante oficial (ledger.com, trezor.io). <strong>Nunca</strong> comprar de revendedor não certificado ou marketplace genérico — risco de dispositivo adulterado;</li>
  <li><strong>Conferir lacre:</strong> caixa lacrada de fábrica. Dispositivos abertos previamente devem ser devolvidos imediatamente;</li>
  <li><strong>Inicialização:</strong> conectar ao computador via USB, abrir o app do fabricante (Ledger Live, Trezor Suite) e escolher Configurar como dispositivo novo;</li>
  <li><strong>Criar PIN:</strong> definir PIN local de 4-8 dígitos. Esse PIN bloqueia o uso físico do dispositivo, mas <strong>não</strong> substitui a seed;</li>
  <li><strong>Gerar seed phrase:</strong> o dispositivo exibe as 24 palavras na tela própria (nunca no computador). Anotar em papel ou metal, na ordem exata;</li>
  <li><strong>Confirmar seed:</strong> o dispositivo pede para confirmar palavras específicas (por exemplo, a 7ª e a 19ª);</li>
  <li><strong>Instalar apps:</strong> via Ledger Live ou Trezor Suite, instalar os apps dos criptoativos que serão guardados (Bitcoin app, Ethereum app, etc.);</li>
  <li><strong>Receber criptoativos:</strong> copiar o endereço público da carteira do hardware wallet e usar como destino para transferências.</li>
</ol>

<div class='cta-oficial' style='margin:24px 0;padding:18px 22px;background:#fef3e8;border-left:6px solid #d97706;border-radius:6px;'><p style='margin:0 0 8px;font-size:17px;color:#1a2a1f;'><strong>Backup da seed phrase: regras essenciais</strong></p><ul style='margin:0;padding-left:20px;font-size:14px;line-height:1.6;color:#3a4a3f;'><li>Anotar em papel ou placa de metal anti-incêndio (Cryptosteel, Billfodl);</li><li>Guardar em 2 locais físicos seguros (casa + cofre, por exemplo);</li><li>Nunca digitar a seed em formulário web, e-mail ou nuvem;</li><li>Nunca tirar foto da seed;</li><li>Nunca compartilhar com ninguém, nem com "suporte do fabricante".</li></ul></div>

<h2>Erros que comprometem a segurança da carteira</h2>

<p>Os 5 erros mais frequentes que resultam em perda de cripto:</p>

<ol>
  <li><strong>Tirar foto da seed phrase:</strong> a foto entra no backup automático do iCloud ou Google Photos. Conta hackeada = wallet drenada;</li>
  <li><strong>Digitar a seed em site falso:</strong> phishing imitando MetaMask, Ledger Live ou exchange popular. Nenhuma carteira real pede a seed em formulário web;</li>
  <li><strong>Aprovar contrato malicioso:</strong> autorizar gastos ilimitados em DEX desconhecida. O contrato pode drenar a wallet inteira na primeira transação;</li>
  <li><strong>Manter saldo grande em exchange:</strong> falências (FTX 2022, Mt.Gox 2014) congelaram saldos por anos. Migrar para wallet pessoal é prática conservadora;</li>
  <li><strong>Comprar hardware wallet de fonte não-oficial:</strong> dispositivos adulterados vêm com seed pré-gerada pelo golpista. Sempre comprar direto do fabricante.</li>
</ol>

<details class='faq-discover'>
<summary><strong>Qual a diferença entre hot wallet e cold wallet?</strong></summary>
<p>Hot wallet é uma carteira conectada à internet (app, extensão), vulnerável a malware mas prática para uso diário. Cold wallet é um dispositivo físico que mantém a chave privada offline (Ledger, Trezor), imune a ataques remotos mas exige acesso físico para cada transação. A regra geral: até R$ 5 mil, hot wallet basta; acima de R$ 50 mil, cold wallet é praticamente obrigatória.</p>
</details>

<details class='faq-discover'>
<summary><strong>O que acontece se eu perder a seed phrase?</strong></summary>
<p>O acesso à carteira é perdido permanentemente. Sem a seed, nenhum suporte do fabricante, da MetaMask ou da exchange consegue recuperar os fundos. A chave privada associada ao endereço se torna inacessível, e os criptoativos ficam efetivamente travados na blockchain para sempre. Por isso o backup em papel ou metal em 2 locais físicos diferentes é prática essencial.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto custa uma cold wallet (hardware wallet)?</strong></summary>
<p>Ledger Nano S Plus custa cerca de R$ 400, Ledger Nano X cerca de R$ 900, Trezor One cerca de R$ 500 e Trezor Model T cerca de R$ 1.500. SafePal é alternativa mais barata (R$ 250-500). O preço inclui frete internacional quando comprado direto do fabricante.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso usar a MetaMask como cold wallet?</strong></summary>
<p>Não diretamente, a MetaMask é uma hot wallet (conectada à internet). Mas a MetaMask <strong>pode ser integrada</strong> a uma hardware wallet (Ledger ou Trezor), passando a operar como interface enquanto a chave privada permanece no dispositivo físico. A integração combina a facilidade da MetaMask com a segurança do hardware wallet.</p>
</details>

<details class='faq-discover'>
<summary><strong>É seguro comprar Ledger em marketplace genérico?</strong></summary>
<p>Não. Hardware wallets adquiridos em revendedor não certificado, marketplace genérico ou de origem desconhecida correm risco de adulteração. Dispositivos podem vir com seed pré-gerada pelo golpista, o que esvaziaria a wallet assim que receba fundos. Sempre comprar direto do fabricante oficial (ledger.com, trezor.io) ou em revendedores certificados listados no site oficial.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional. Não constitui recomendação de investimento ou indicação preferencial de fabricante.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// Publicação batch
// ════════════════════════════════════════════════════════════════════
$slugSite = 'comocomprar';
$cfgSite = $cfg;
aplicarSite($cfgSite, $sites, $slugSite);
$wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

$schemaAuthor = ['@type' => 'Organization', 'name' => 'Redação Como Comprar', 'url' => 'https://comocomprar.com.br'];
$schemaPublisher = ['@type' => 'Organization', 'name' => 'Como Comprar', 'url' => 'https://comocomprar.com.br'];

foreach ([$p4, $p5, $p6] as $info) {
    echo "\n══════ {$info['slug']} ══════\n";

    $featuredId = 0;
    try {
        $featuredId = (int)($wp->uploadImagemPorUrl169($info['ogUrl'], $info['titulo'], $info['slug']) ?? 0);
        if ($featuredId > 0) echo "✅ Featured 16:9 WebP: media #{$featuredId}\n";
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
    $catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch(['Criptomoedas']))));
    $tagIds = $wp->resolverTags(['Criptomoedas', 'Bitcoin', 'Ethereum', 'Imposto de Renda', 'Exchange', 'Carteira Digital', 'Ledger', 'Trezor', 'MetaMask']);

    $payload = [
        'title' => $info['titulo'], 'slug' => $info['slug'], 'content' => $content,
        'status' => 'draft',
        'meta' => [
            'rank_math_title' => $info['titulo'] . ' | Como Comprar',
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
        $rel = $wp->buscarRelacionados('criptomoedas', 4, $pid);
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

echo "\n══════ FIM BATCH B ══════\n";
