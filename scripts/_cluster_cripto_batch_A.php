<?php
declare(strict_types=1);
/**
 * Cluster cripto comocomprar — Batch A (3 posts técnicos).
 * Posts: P2P sem corretora, Pix passo a passo, Ethereum na MetaMask.
 * Autoria = Redação do Como Comprar. Featured 16:9 WebP gogleads.
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

// ════════════════════════════════════════════════════════════════════
// POST 1 — Bitcoin sem corretora (P2P + DEXs)
// ════════════════════════════════════════════════════════════════════
$p1 = [
    'titulo' => 'Como comprar Bitcoin sem corretora em 2026: P2P na Binance e exchanges descentralizadas (DEXs)',
    'slug'   => 'comprar-bitcoin-sem-corretora-p2p-binance-dex-uniswap-pancakeswap-2026',
    'metaDesc' => 'Guia completo para comprar Bitcoin sem corretora centralizada: passo a passo no P2P da Binance via Pix e nas principais DEXs (Uniswap, PancakeSwap, Jupiter). Riscos, custos e quando faz sentido.',
    'focusKw' => 'comprar bitcoin sem corretora p2p',
    'ogUrl' => 'https://public.bnbstatic.com/image/cms/blog/20210423/b4cde7cd-3428-4f02-9eb0-3d1a484583f7.png',
];
$p1['html'] = <<<'HTML'
<p><strong>Comprar Bitcoin sem corretora centralizada</strong> deixou de ser exclusividade de usuários técnicos. Duas opções dominam o cenário em 2026: o mercado <strong>peer-to-peer (P2P)</strong> da Binance, onde o usuário compra direto de outro vendedor pagando via Pix, e as <strong>exchanges descentralizadas (DEXs)</strong> como Uniswap, PancakeSwap e Jupiter, que executam a troca direto na blockchain sem intermediário.</p>

<p>Cada caminho atende um perfil diferente. O P2P resolve a maior parte dos casos de uso brasileiros, com Pix e fluxo similar a um marketplace de produtos. As DEXs entram quando o usuário já tem cripto em carteira e quer trocar por outro ativo sem passar por exchange centralizada.</p>

<p>Os dois métodos exigem mais atenção que comprar pela exchange tradicional. A custódia é responsabilidade do usuário, e os riscos operacionais aumentam. Em compensação, taxas costumam ser menores e a privacidade é maior.</p>

<h2>O que é mercado P2P e por que ele cresceu no Brasil</h2>

<p>P2P significa peer-to-peer, transação direta entre duas pessoas. Na prática, plataformas como Binance P2P hospedam um "mural de anúncios" onde vendedores publicam ofertas de Bitcoin (BTC), Tether (USDT) e outras criptos, com preço próprio e método de pagamento aceito.</p>

<p>O comprador escolhe um anúncio, abre a operação, paga o vendedor via Pix (ou TED, cartão, depósito) e a plataforma libera a cripto quando o pagamento é confirmado. A Binance funciona como árbitra: o vendedor não recebe o cripto até a Binance verificar o pagamento, e o comprador não recebe o cripto até o pagamento ser efetivamente feito.</p>

<p>O crescimento do P2P no Brasil tem 3 motores:</p>

<ul>
  <li><strong>Pix instantâneo:</strong> a transferência cai em segundos, tornando o ciclo P2P quase tão rápido quanto comprar pela exchange direta;</li>
  <li><strong>Spread melhor:</strong> em momentos de liquidez normal, o P2P costuma oferecer preços 1% a 3% melhores que o mercado spot da exchange centralizada;</li>
  <li><strong>Limites maiores:</strong> grandes vendedores aceitam operações de R$ 50 mil a R$ 1 milhão em uma transação só, raro no fluxo padrão da exchange.</li>
</ul>

<h2>Passo a passo: comprar Bitcoin no P2P da Binance via Pix</h2>

<p>O fluxo na Binance é o mais usado pelo público brasileiro. As etapas:</p>

<ol>
  <li><strong>Conta Binance:</strong> criar conta no site oficial (binance.com) ou app, fazer cadastro com nome, CPF, e-mail e celular. KYC básico é suficiente para começar (foto do documento e selfie);</li>
  <li><strong>Acessar P2P Trading:</strong> dentro do app, menu Compra cripto → P2P Express ou P2P Mercado. Selecionar "Comprar", BTC ou USDT, BRL como moeda, e Pix como método;</li>
  <li><strong>Escolher anunciante:</strong> a lista mostra vendedores ordenados por preço, com indicador de reputação (taxa de conclusão e tempo médio de liberação). Preferir anunciantes com 95%+ de conclusão e 100+ operações;</li>
  <li><strong>Abrir ordem:</strong> informar quanto quer comprar (em BRL ou em cripto), clicar Comprar. A plataforma reserva o cripto do vendedor e exibe a chave Pix dele e o tempo limite (geralmente 15-30 minutos);</li>
  <li><strong>Pagar via Pix:</strong> ir ao app do banco, copiar e colar a chave Pix exata exibida pela Binance, conferir o valor e o nome do recebedor, confirmar o pagamento;</li>
  <li><strong>Confirmar pagamento na Binance:</strong> voltar ao app Binance, clicar "Já paguei". O vendedor recebe notificação e libera o cripto manualmente (geralmente em 1-5 minutos);</li>
  <li><strong>Cripto liberado:</strong> o BTC ou USDT cai na carteira spot da Binance. Pode ser usado imediatamente, transferido para wallet pessoal ou vendido.</li>
</ol>

<div class='cta-oficial' style='margin:24px 0;padding:18px 22px;background:#fef3e8;border-left:6px solid #d97706;border-radius:6px;'><p style='margin:0 0 8px;font-size:17px;color:#1a2a1f;'><strong>Regras de segurança no P2P (não negociáveis)</strong></p><ul style='margin:0;padding-left:20px;font-size:14px;line-height:1.6;color:#3a4a3f;'><li>NUNCA cancele uma ordem após pagar. Use só o canal de disputa da plataforma;</li><li>Não pague Pix para chave diferente da exibida pela Binance;</li><li>Não aceite "combinar fora da plataforma" — é golpe certo;</li><li>Confira o nome do recebedor antes de confirmar (deve bater com o anunciante).</li></ul></div>

<h2>O que são DEXs e como elas funcionam</h2>

<p>Exchange descentralizada (DEX) é um protocolo que roda direto na blockchain. Não tem CEO, não tem CNPJ, não custódia recursos. O usuário conecta a própria wallet (MetaMask, Trust Wallet, Phantom), aprova a transação e a blockchain executa a troca automaticamente via contratos inteligentes.</p>

<p>As principais DEXs por blockchain:</p>

<ul>
  <li><strong>Uniswap:</strong> a maior DEX do mundo, roda na rede Ethereum. Liquidez profunda para os principais tokens ERC-20;</li>
  <li><strong>PancakeSwap:</strong> equivalente da Uniswap na BNB Smart Chain (BSC). Taxas de gás muito menores que Ethereum;</li>
  <li><strong>Jupiter:</strong> agregador de DEXs da rede Solana. Compara liquidez em várias DEXs Solana e executa pelo melhor preço;</li>
  <li><strong>Curve:</strong> especializada em stablecoins (USDC, USDT, DAI), com swaps de baixo slippage;</li>
  <li><strong>1inch:</strong> agregador multi-blockchain que roteia ordens pela melhor rota possível.</li>
</ul>

<h2>Como usar uma DEX para a primeira swap</h2>

<p>O fluxo numa DEX é mais técnico que P2P. As etapas básicas (usando Uniswap como exemplo):</p>

<ol>
  <li><strong>Wallet pronta:</strong> ter MetaMask ou outra wallet compatível instalada e com saldo na rede correta (ETH na Ethereum para swap em Uniswap);</li>
  <li><strong>Conectar:</strong> acessar app.uniswap.org, clicar "Connect Wallet" e autorizar a conexão com a wallet;</li>
  <li><strong>Selecionar tokens:</strong> escolher o token de origem (ex: ETH) e o de destino (ex: USDC), informar quanto quer trocar;</li>
  <li><strong>Conferir slippage e gas:</strong> o app mostra o preço estimado, slippage tolerável (geralmente 0,5%) e a estimativa de gas fee. Aprovar;</li>
  <li><strong>Aprovar contrato:</strong> primeira vez que usa um token novo, a wallet pede aprovação separada (gasta gas) antes da swap;</li>
  <li><strong>Confirmar swap:</strong> a wallet pede confirmação final. A transação vai pro mempool e é confirmada em 12-60 segundos (Ethereum) ou alguns segundos (Solana, BSC);</li>
  <li><strong>Cripto na wallet:</strong> o novo token aparece automaticamente na wallet quando a transação é confirmada na blockchain.</li>
</ol>

<h2>Custos: P2P vs DEX vs exchange centralizada</h2>

<p>Os 3 caminhos têm estruturas de custo distintas:</p>

<ul>
  <li><strong>Exchange centralizada (CEX):</strong> taxa de corretagem 0,1% a 0,5% + spread + taxa de saque para wallet (R$ 30-80 em BTC);</li>
  <li><strong>P2P na Binance:</strong> spread embutido no preço do anunciante (0% a 3% pior que mercado), sem taxa adicional da Binance pra compradores;</li>
  <li><strong>DEX:</strong> taxa do protocolo (0,3% no Uniswap, 0,25% no PancakeSwap) + gas fee da rede (variável). Em Ethereum, pode chegar a R$ 50-200 por swap em momentos de congestionamento; em Solana ou BSC, fica abaixo de R$ 1.</li>
</ul>

<p>Para aportes pequenos (R$ 50 a R$ 500), CEX sai mais barato em valor absoluto. Para aportes médios (R$ 500 a R$ 5.000), P2P costuma vencer. Para volumes grandes ou trocas entre tokens raros, DEX é frequentemente o melhor caminho.</p>

<h2>Riscos específicos do P2P e das DEXs</h2>

<p>Sair da exchange centralizada significa abrir mão de proteções importantes. Os riscos específicos:</p>

<ul>
  <li><strong>P2P — chargeback:</strong> golpistas pagam via cartão de crédito que depois é estornado. Por isso a maioria dos anunciantes só aceita Pix de pessoa física, com nome verificado;</li>
  <li><strong>P2P — golpe da chave:</strong> golpistas convencem o iniciante a pagar pra chave Pix fora do anúncio. A Binance NÃO arbitra fora do fluxo oficial;</li>
  <li><strong>DEX — tokens falsos:</strong> golpistas criam tokens com nome idêntico ao real. Sempre conferir o endereço do contrato no explorador oficial (Etherscan, BscScan);</li>
  <li><strong>DEX — slippage extremo:</strong> em tokens de baixa liquidez, o preço pode oscilar 10% a 50% entre a quotação e a execução. Sempre setar slippage máximo;</li>
  <li><strong>DEX — perda de seed:</strong> sem custódia, perder a frase de recuperação significa perder o ativo permanentemente.</li>
</ul>

<h2>Quando faz sentido fugir da exchange centralizada</h2>

<p>Sair da CEX vale a pena nestes cenários:</p>

<ol>
  <li><strong>Aporte alto pontual:</strong> comprar R$ 50 mil ou mais em BTC. P2P consegue preços 1-3% melhores, economia direta de R$ 500-1.500 na operação;</li>
  <li><strong>Privacidade ampliada:</strong> P2P básico exige KYC menor que CEX completa. DEX dispensa cadastro;</li>
  <li><strong>Token raro:</strong> altcoins novos listados em DEX antes de chegar à CEX;</li>
  <li><strong>Custódia já em wallet:</strong> quem já tem cripto em wallet própria e quer trocar por outro token sem voltar à exchange;</li>
  <li><strong>Geografia:</strong> regiões com bloqueio bancário ou regulatório onde exchange centralizada não opera.</li>
</ol>

<p>Para iniciante absoluto comprando R$ 100-1.000 em Bitcoin, a exchange centralizada continua sendo a opção mais simples e segura.</p>

<details class='faq-discover'>
<summary><strong>P2P na Binance é legal no Brasil?</strong></summary>
<p>Sim. A Lei 14.478/2022 (marco legal das criptomoedas) regulamenta a compra e venda de criptoativos entre pessoas físicas e jurídicas, incluindo modalidades P2P. As operações seguem sujeitas a tributação pela Receita Federal e devem ser informadas conforme a Instrução Normativa 1.888/2019 quando o volume mensal ultrapassa R$ 30 mil.</p>
</details>

<details class='faq-discover'>
<summary><strong>Preciso pagar imposto sobre Bitcoin comprado em P2P ou DEX?</strong></summary>
<p>Sim. A regra é a mesma da exchange centralizada: vendas mensais até R$ 35 mil são isentas; acima disso, incidem 15% a 22,5% sobre o ganho de capital via DARF código 4600. A IN RFB 1.888/2019 obriga reporte mensal à Receita Federal de operações em exchanges estrangeiras ou P2P quando o volume passa de R$ 30 mil/mês.</p>
</details>

<details class='faq-discover'>
<summary><strong>Como evitar golpe no P2P?</strong></summary>
<p>Seguir 4 regras: (1) só operar pelo canal oficial da plataforma, nunca por WhatsApp ou Telegram com o vendedor; (2) confirmar que o nome no Pix bate com o nome do anunciante; (3) nunca cancelar uma ordem depois de pagar; (4) preferir anunciantes com mais de 100 operações concluídas e taxa acima de 95%.</p>
</details>

<details class='faq-discover'>
<summary><strong>O que é gas fee em uma DEX?</strong></summary>
<p>Gas fee é a taxa paga pela rede blockchain para processar a transação. Quem recebe é o validador (na Ethereum, antigamente os mineradores). O valor varia conforme o congestionamento da rede e o tipo de operação. Em Ethereum, swaps comuns custam entre R$ 5 e R$ 200. Em Solana e BSC, ficam abaixo de R$ 1.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual é mais seguro: P2P ou DEX?</strong></summary>
<p>P2P é mais seguro para iniciante porque a plataforma arbitra disputas e mantém sistema de reputação. DEX é mais seguro do ponto de vista de custódia (não tem terceiro guardando), mas mais vulnerável a erros do usuário (token errado, slippage alto, perda de seed). Pra primeira operação fora da CEX, P2P na Binance é o caminho recomendado.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional. Não constitui recomendação de investimento.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// POST 2 — Como comprar criptomoedas pelo Pix
// ════════════════════════════════════════════════════════════════════
$p2 = [
    'titulo' => 'Como comprar criptomoedas pelo Pix em 2026: passo a passo nas 6 principais exchanges brasileiras',
    'slug'   => 'como-comprar-criptomoedas-pelo-pix-passo-a-passo-mercado-bitcoin-binance-2026',
    'metaDesc' => 'Guia completo para comprar criptomoedas pelo Pix em 2026 nas 6 principais exchanges: Mercado Bitcoin, Binance, Foxbit, NovaDAX, BitPreço e Coinex. Tempo de cair, limites, taxas e cuidados.',
    'focusKw' => 'como comprar criptomoedas pelo pix',
    'ogUrl' => 'https://assets.staticimg.com/cms/media/3h2sKhVodYJRLULspHPUWIFA8S73XG9UHq704Ftlu.jpg',
];
$p2['html'] = <<<'HTML'
<p><strong>Comprar criptomoedas pelo Pix em 2026</strong> é o método mais rápido, barato e popular no Brasil. O depósito cai em até 60 segundos, a operação funciona 24 horas por dia inclusive em fins de semana e feriados, e a maioria dos bancos não cobra taxa para pessoa física. Os 3 fatores combinados fizeram do Pix o canal preferencial em todas as principais exchanges brasileiras.</p>

<p>O fluxo é praticamente idêntico nas 6 maiores plataformas: criar conta, completar verificação de identidade (KYC), copiar a chave Pix da exchange, fazer a transferência pelo app do banco e executar a ordem de compra do cripto desejado. As variações ficam nos limites, no tempo de liberação e nas pequenas diferenças de interface.</p>

<p>O guia abaixo cobre o passo a passo nas 6 principais exchanges com operação consolidada no Brasil, mais os limites, as taxas e os cuidados de segurança em comum.</p>

<h2>Por que o Pix se tornou o método preferencial para comprar cripto</h2>

<p>Em 2026, o Pix concentra cerca de 80% dos depósitos em exchanges brasileiras, segundo dados de mercado divulgados pelas próprias plataformas. As vantagens estruturais explicam o domínio:</p>

<ul>
  <li><strong>Tempo:</strong> o saldo cai na exchange em até 60 segundos, contra 1-2 dias úteis de TED e DOC;</li>
  <li><strong>Custo:</strong> a maioria dos bancos não cobra Pix de pessoa física, contra R$ 5-15 de TED;</li>
  <li><strong>Disponibilidade:</strong> funciona 24/7, sem limite de horário bancário;</li>
  <li><strong>Limite alto:</strong> Pix permite até R$ 1 milhão por operação para pessoa física (sujeito a limites definidos por cada banco);</li>
  <li><strong>Rastreabilidade:</strong> a Receita Federal e o Banco Central conseguem auditar as operações, reduzindo o risco de uso indevido.</li>
</ul>

<h2>Passo a passo: comprar cripto no Mercado Bitcoin via Pix</h2>

<p>O Mercado Bitcoin é a maior exchange brasileira em volume e foi o canal pioneiro a integrar Pix em 2020. O fluxo:</p>

<ol>
  <li>Baixar o app oficial Mercado Bitcoin (iOS ou Android) e fazer cadastro com nome, CPF, e-mail e celular;</li>
  <li>Completar KYC enviando foto do RG ou CNH, comprovante de residência e selfie (validação automática em até 1 hora);</li>
  <li>No menu principal, acessar Depositar → Pix → escolher o valor desejado;</li>
  <li>O app gera um QR Code Pix ou exibe a chave (CNPJ da Mercado Bitcoin). Copiar e ir ao banco;</li>
  <li>Pagar o Pix pelo app do banco, conferindo o nome do recebedor (deve ser "Mercado Bitcoin Serviços Digitais Ltda");</li>
  <li>Voltar ao app e aguardar até 60 segundos para o saldo aparecer em BRL;</li>
  <li>Acessar Comprar → Bitcoin (ou outra cripto), informar o valor em reais e confirmar.</li>
</ol>

<h2>Passo a passo: comprar cripto na Binance via Pix</h2>

<p>A Binance opera no Brasil via parceria com instituição financeira local. O fluxo:</p>

<ol>
  <li>Cadastrar conta em binance.com ou app oficial. Completar KYC nível 1 (documento + selfie);</li>
  <li>No menu, ir em Compra cripto → Depósito em BRL → Pix;</li>
  <li>Informar o valor a depositar (mínimo geralmente R$ 100, máximo varia conforme o nível de verificação);</li>
  <li>A Binance gera a chave Pix dinâmica (válida por 30 minutos);</li>
  <li>Pagar via app do banco, conferir o recebedor e confirmar;</li>
  <li>Saldo BRL aparece em segundos. Usar o Trade Spot para comprar a cripto desejada.</li>
</ol>

<h2>Passo a passo: comprar cripto na Foxbit, NovaDAX e BitPreço</h2>

<p>As 3 exchanges seguem fluxo praticamente idêntico ao Mercado Bitcoin, com pequenas variações na interface. As etapas são as mesmas:</p>

<ul>
  <li><strong>Foxbit:</strong> cadastro nacional, KYC com foto + selfie, depósito Pix pela área "Adicionar saldo" e compra direta no app;</li>
  <li><strong>NovaDAX:</strong> integração Pix nativa, com mínimo de depósito de R$ 50. Taxas competitivas (0,25% por operação para iniciantes);</li>
  <li><strong>BitPreço:</strong> agregador que compara preço entre exchanges. Depósito Pix vai pra carteira BitPreço e a plataforma roteia a compra pra exchange com melhor preço naquele momento.</li>
</ul>

<h2>Como funciona o Pix na Coinex Brasil</h2>

<p>A Coinex Brasil é o ramo brasileiro da Coinex global, com operação consolidada desde 2023. O Pix funciona via parceiro financeiro local:</p>

<ol>
  <li>Cadastrar conta com nome, CPF e e-mail;</li>
  <li>Completar KYC nível 2 para depósitos via Pix (documento + comprovante + selfie);</li>
  <li>No menu, acessar Carteira → Depositar BRL → Pix;</li>
  <li>Receber chave Pix, transferir do banco, aguardar liberação;</li>
  <li>Comprar a cripto desejada via Trade Spot ou Convert (modo simplificado).</li>
</ol>

<h2>Limites e horários do Pix nas exchanges</h2>

<p>Os limites variam por exchange e por nível de verificação do usuário. Padrões mais comuns:</p>

<ul>
  <li><strong>Mínimo de depósito:</strong> R$ 1 a R$ 100 dependendo da exchange. Mercado Bitcoin aceita R$ 1; Binance pede R$ 100 mínimo;</li>
  <li><strong>Máximo por operação:</strong> limite do banco do usuário (geralmente R$ 1 milhão pessoa física Pix);</li>
  <li><strong>Máximo diário no Pix bancário:</strong> definido por cada banco. Itaú, Bradesco, Nubank e Santander geralmente permitem R$ 1 milhão/dia para pessoa física;</li>
  <li><strong>Pix noturno:</strong> entre 20h e 6h, alguns bancos aplicam limite menor (R$ 1.000 a R$ 10.000) por segurança;</li>
  <li><strong>Horário operacional:</strong> exchanges operam 24/7. Liberação de saldo é automática em segundos.</li>
</ul>

<h2>Taxas: o que muda entre os métodos de pagamento</h2>

<p>Pix domina pela combinação custo + velocidade, mas vale comparar com as outras opções:</p>

<ul>
  <li><strong>Pix:</strong> gratuito para pessoa física na maioria dos bancos. Cai em segundos;</li>
  <li><strong>TED:</strong> custa R$ 5-20 e cai em 1 dia útil. Funcional para operações grandes em horário comercial;</li>
  <li><strong>Cartão de crédito:</strong> aceito em algumas exchanges (Binance, Foxbit), mas taxa de 4-8% sobre o valor. Encarece muito a operação;</li>
  <li><strong>Boleto bancário:</strong> aceito em poucas exchanges. Demora 1-3 dias e tem taxas pequenas.</li>
</ul>

<p>Para aportes regulares de R$ 50 a R$ 50 mil, Pix é o melhor caminho em praticamente 100% dos cenários.</p>

<h2>Cuidados de segurança específicos do Pix em cripto</h2>

<p>O Pix é seguro pelo desenho do sistema, mas exige atenção em 4 pontos práticos:</p>

<ul>
  <li><strong>Sempre copiar a chave Pix exibida pela exchange</strong> e não digitar manualmente. Erros de digitação enviam o dinheiro pra outra conta sem retorno;</li>
  <li><strong>Conferir o nome do recebedor</strong> antes de confirmar o pagamento. Deve bater com a razão social da exchange (Mercado Bitcoin Serviços Digitais Ltda, etc.);</li>
  <li><strong>Não responder a "suporte" pelo WhatsApp ou Telegram</strong> pedindo Pix de "validação". Nenhuma exchange faz isso. Suporte real é via chat interno do app ou e-mail oficial;</li>
  <li><strong>Limite Pix noturno baixo</strong> reduz dano em caso de golpe que envolva sequestro do celular. Manter o limite reduzido entre 20h e 6h é boa prática.</li>
</ul>

<details class='faq-discover'>
<summary><strong>Pix para criptomoeda tem taxa?</strong></summary>
<p>Em geral não. A maioria dos bancos não cobra Pix para pessoa física, e nenhuma das principais exchanges brasileiras cobra taxa de depósito via Pix. O custo real da compra de cripto fica na taxa de corretagem (0,1% a 0,5%) e no spread, não no Pix em si.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quanto tempo demora o Pix cair na exchange?</strong></summary>
<p>Em média, 5 a 60 segundos. O Pix entre bancos é instantâneo no nível do Sistema de Pagamentos Brasileiro (SPB), mas a exchange precisa identificar o depósito e creditar na carteira do usuário. Em 99% dos casos, isso ocorre em menos de 1 minuto.</p>
</details>

<details class='foaq-discover'>
<summary><strong>Posso comprar criptomoedas no Pix sem corretora?</strong></summary>
<p>Sim, via mercado P2P (peer-to-peer) na Binance ou em plataformas como LocalBitcoins. O usuário compra direto de outro vendedor pagando via Pix, sem a custódia da exchange. O processo exige mais atenção (verificação de reputação do vendedor, uso correto do canal oficial), mas funciona dentro da legalidade brasileira.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual o valor mínimo para comprar Bitcoin pelo Pix?</strong></summary>
<p>Varia por exchange. Mercado Bitcoin aceita a partir de R$ 1. NovaDAX começa em R$ 50. Binance pede mínimo de R$ 100 para depósitos via Pix. Foxbit e BitPreço geralmente aceitam a partir de R$ 10. Para aportes pequenos (R$ 1-50), Mercado Bitcoin é o canal mais flexível.</p>
</details>

<details class='faq-discover'>
<summary><strong>Pix em criptomoeda é fiscalizado pela Receita Federal?</strong></summary>
<p>Sim. As exchanges brasileiras enviam dados de todas as operações ao Banco Central via Sistema de Pagamentos Brasileiro (SPB) e à Receita Federal via Instrução Normativa 1.888/2019. O movimento aparece nas declarações de Imposto de Renda quando obrigatório (posição acima de R$ 5 mil em 31/12 ou vendas mensais acima de R$ 35 mil).</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional. Não constitui recomendação de investimento.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// POST 3 — Como comprar Ethereum na MetaMask
// ════════════════════════════════════════════════════════════════════
$p3 = [
    'titulo' => 'Como comprar Ethereum na MetaMask em 2026: do Pix à wallet em 15 minutos sem custódia centralizada',
    'slug'   => 'como-comprar-ethereum-metamask-pix-wallet-nao-custodial-15-minutos',
    'metaDesc' => 'Guia completo para comprar Ethereum (ETH) direto na MetaMask em 2026: criação da wallet, backup da seed phrase, onramp via Pix (Transak/MoonPay), recebimento de exchange e taxas de gás. Sem corretora intermediária.',
    'focusKw' => 'como comprar ethereum metamask',
    'ogUrl' => 'https://empiricus.com.br/uploads/2022/11/Metamask.jpg',
];
$p3['html'] = <<<'HTML'
<p><strong>Comprar Ethereum (ETH) na MetaMask</strong> é o caminho mais usado por quem quer controle total sobre os próprios ativos cripto, sem deixar saldo custodiado em exchange. A MetaMask é uma carteira não-custodial, o que significa que o usuário guarda diretamente a chave privada e a frase de recuperação (seed phrase). Nenhum terceiro tem acesso aos fundos.</p>

<p>Há 2 maneiras práticas de levar ETH para a MetaMask: usar uma rampa fiat-para-cripto integrada (Transak, MoonPay) que aceita Pix direto na wallet, ou comprar primeiro em exchange brasileira e transferir para o endereço MetaMask. Cada caminho tem custos e tempos diferentes.</p>

<p>O guia abaixo cobre a instalação correta da MetaMask, o passo a passo dos 2 caminhos, custos esperados, segurança da seed phrase e quando vale a pena cada opção.</p>

<h2>O que é MetaMask e por que ela é diferente de uma exchange</h2>

<p>MetaMask é uma carteira de criptomoedas não-custodial criada em 2016. Funciona como extensão de navegador (Chrome, Firefox, Edge, Brave) e aplicativo mobile (iOS e Android). A wallet suporta nativamente a rede Ethereum e todas as redes EVM compatíveis (Polygon, BNB Smart Chain, Arbitrum, Optimism, Base, entre outras).</p>

<p>A diferença fundamental para uma exchange centralizada é a custódia. Na exchange, o saldo é registrado em uma conta da empresa e o usuário tem direito de saque. Na MetaMask, o usuário detém diretamente a chave privada criptográfica — não há intermediário que possa congelar, perder ou bloquear os fundos.</p>

<p>O trade-off é a responsabilidade. Perder a frase de recuperação (12 palavras geradas no momento da criação) significa perder os ativos permanentemente, sem possibilidade de recuperação. Nenhum suporte da MetaMask consegue resetar a wallet.</p>

<h2>Instalação e criação da carteira MetaMask</h2>

<p>O processo leva cerca de 10 minutos e deve ser feito com atenção ao backup da seed phrase. Etapas:</p>

<ol>
  <li><strong>Baixar da fonte oficial:</strong> acessar metamask.io e clicar em Download. Para extensão de navegador, ir direto à Chrome Web Store ou loja do navegador. Para mobile, baixar na App Store ou Google Play oficial. <strong>Nunca</strong> baixar por links de Google Ads ou banners pagos: golpes imitam o site oficial;</li>
  <li><strong>Criar nova carteira:</strong> abrir a extensão ou app, clicar Create a new wallet, definir senha local forte. Essa senha protege a wallet apenas no dispositivo atual;</li>
  <li><strong>Revelar a Secret Recovery Phrase:</strong> a MetaMask exibe 12 palavras em ordem específica. Essa frase é o backup da carteira. <strong>Anotar em papel ou metal, NUNCA em digital</strong>. Não tirar foto, não enviar para si mesmo por e-mail, não digitar em nenhum site;</li>
  <li><strong>Confirmar a frase:</strong> a MetaMask pede pra digitar as palavras em ordem para confirmar o backup. Esse passo é obrigatório;</li>
  <li><strong>Wallet pronta:</strong> a tela principal exibe o endereço público (começa com 0x...), saldo em ETH (zero inicialmente) e abas para tokens, atividade e configurações.</li>
</ol>

<div class='cta-oficial' style='margin:24px 0;padding:18px 22px;background:#fef3e8;border-left:6px solid #d97706;border-radius:6px;'><p style='margin:0 0 8px;font-size:17px;color:#1a2a1f;'><strong>Backup da seed phrase: regras críticas</strong></p><ul style='margin:0;padding-left:20px;font-size:14px;line-height:1.6;color:#3a4a3f;'><li>Anotar em papel ou em placa de metal anti-incêndio. Nunca em arquivo digital;</li><li>Guardar em 2 locais físicos seguros e diferentes (casa + cofre, por exemplo);</li><li>Nunca compartilhar com ninguém, nem com "suporte da MetaMask";</li><li>Nunca digitar a frase em sites — nem mesmo no site oficial. A wallet só pede no app, jamais via formulário web.</li></ul></div>

<h2>Caminho 1: comprar ETH direto na MetaMask via Pix (onramp)</h2>

<p>A MetaMask integra rampas fiat-para-cripto que aceitam Pix brasileiro direto. Os 2 principais provedores são Transak e MoonPay. Funcionam dentro do app da wallet:</p>

<ol>
  <li>Na MetaMask, clicar Buy (Comprar) na tela principal;</li>
  <li>Escolher a moeda de pagamento (BRL) e o ativo a receber (ETH);</li>
  <li>Selecionar o provedor: Transak, MoonPay ou Banxa, conforme disponibilidade na sua região;</li>
  <li>Informar o valor em reais. O sistema mostra a cotação em ETH descontando taxa do provedor (3% a 5%) e gas fee estimado;</li>
  <li>Completar KYC do provedor (geralmente 1-2 vezes; informações ficam salvas para próximas compras);</li>
  <li>Receber a chave Pix do provedor e pagar pelo app do banco;</li>
  <li>Aguardar de 5 a 30 minutos para o ETH cair direto na sua MetaMask.</li>
</ol>

<p>A vantagem do caminho onramp é a simplicidade: o ETH chega na própria wallet sem precisar transferir manualmente. A desvantagem é o custo: taxas de 3% a 5% são bem mais altas que comprar pela exchange tradicional (0,25% a 0,5%).</p>

<h2>Caminho 2: comprar em exchange brasileira e transferir para MetaMask</h2>

<p>O fluxo é mais econômico para aportes acima de R$ 500. As etapas:</p>

<ol>
  <li>Comprar ETH na exchange escolhida (Mercado Bitcoin, Binance, NovaDAX) via Pix, conforme passo a passo padrão;</li>
  <li>Na MetaMask, copiar o endereço público da wallet (botão de cópia ao lado do endereço 0x...);</li>
  <li>Na exchange, ir em Sacar → Ethereum → Endereço externo;</li>
  <li>Colar o endereço da MetaMask. Conferir os primeiros e últimos 4 caracteres antes de confirmar;</li>
  <li>Escolher a rede: <strong>Ethereum Mainnet</strong> (taxa maior, mais segura) ou <strong>Arbitrum / Base / Polygon</strong> (taxa baixa, mais rápida, mas exige verificar se a MetaMask suporta a rede destino);</li>
  <li>Confirmar a transferência. O ETH leva entre 2 e 15 minutos para aparecer na MetaMask (rede Ethereum) ou alguns segundos (Arbitrum/Base);</li>
  <li>Conferir a chegada na aba Tokens da MetaMask.</li>
</ol>

<h2>Custos comparados: onramp vs exchange + transferência</h2>

<p>Para um aporte de R$ 1.000 em ETH, a diferença é significativa:</p>

<ul>
  <li><strong>Onramp direto na MetaMask (Transak):</strong> taxa 3-5% do provedor + spread embutido ≈ R$ 40-60 de custo total. Tempo: 5-30 minutos;</li>
  <li><strong>Exchange + transferência Ethereum Mainnet:</strong> 0,5% da exchange + taxa de saque em ETH ≈ R$ 20-80 dependendo do gás. Tempo: 5-30 minutos;</li>
  <li><strong>Exchange + transferência Arbitrum/Base:</strong> 0,5% da exchange + taxa de saque baixíssima (R$ 0,50-2). Tempo: 1-5 minutos.</li>
</ul>

<p>O caminho mais econômico em 2026 é via Arbitrum ou Base (camadas 2 do Ethereum), com custo total inferior a R$ 10 por operação independente do valor aportado. Exige cadastrar a rede no MetaMask antes (configurações → adicionar rede).</p>

<h2>Gas fee: o que é e como economizar</h2>

<p>Gas fee é a taxa paga à rede para processar a transação. Na rede Ethereum, varia conforme o congestionamento:</p>

<ul>
  <li><strong>Período calmo (madrugada/fim de semana):</strong> R$ 5 a R$ 20 por swap simples;</li>
  <li><strong>Período movimentado:</strong> R$ 50 a R$ 200 por swap em momentos de alta no mercado;</li>
  <li><strong>Picos extremos:</strong> alguns eventos isolados levaram gas fee a passar de R$ 500 por transação.</li>
</ul>

<p>Estratégias práticas para economizar:</p>

<ul>
  <li>Usar camadas 2 (Arbitrum, Base, Optimism) sempre que possível;</li>
  <li>Operar em horários de baixo congestionamento (3h-10h horário do Brasil);</li>
  <li>Configurar gas baixo (Low) em transações não-urgentes;</li>
  <li>Agrupar operações: fazer várias swaps em uma única visita à wallet em vez de várias seções separadas.</li>
</ul>

<h2>Hardware wallet integração: máxima segurança</h2>

<p>Para valores acima de R$ 10 mil em ETH, a recomendação técnica é integrar a MetaMask com uma hardware wallet (Ledger, Trezor). O fluxo:</p>

<ol>
  <li>Adquirir um hardware wallet direto do fabricante oficial (ledger.com ou trezor.io). Nunca comprar de revendedor sem certificação;</li>
  <li>Inicializar o dispositivo conforme manual, criando seed phrase própria e PIN;</li>
  <li>Na MetaMask, abrir menu → Connect Hardware Wallet → escolher Ledger ou Trezor;</li>
  <li>Conectar o dispositivo via USB ou Bluetooth (Ledger Nano X);</li>
  <li>Selecionar as contas a importar. A MetaMask passa a operar como interface, mas a chave privada permanece offline no hardware wallet;</li>
  <li>Toda transação exige confirmação física no botão do dispositivo, impedindo malware de drenar a wallet.</li>
</ol>

<details class='faq-discover'>
<summary><strong>É possível recuperar a MetaMask se eu perder a senha?</strong></summary>
<p>Sim, usando a seed phrase (12 palavras de backup). Basta reinstalar a MetaMask, escolher Importar wallet existente e inserir a frase. Se a seed phrase também estiver perdida, a recuperação é impossível: nenhum suporte da MetaMask consegue resetar a wallet. Por isso o backup da seed em papel ou metal é crítico.</p>
</details>

<details class='faq-discover'>
<summary><strong>Comprar ETH na MetaMask é mais caro que na exchange?</strong></summary>
<p>Sim, quando usado o onramp direto (Transak, MoonPay). As taxas ficam entre 3% e 5% sobre o valor, contra 0,25% a 0,5% da exchange tradicional. Para aportes acima de R$ 500, sai mais econômico comprar em exchange brasileira via Pix e transferir para a MetaMask depois — sobretudo usando Arbitrum ou Base como rede de saída.</p>
</details>

<details class='faq-discover'>
<summary><strong>A MetaMask é segura?</strong></summary>
<p>A MetaMask em si é segura. O risco maior está no usuário: phishing (sites falsos imitando a MetaMask), instalação de extensões maliciosas e perda da seed phrase. Boas práticas: baixar só do site oficial, não digitar a seed em formulários web, usar hardware wallet para valores altos e nunca compartilhar a frase de recuperação com ninguém.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso usar a MetaMask para guardar Bitcoin?</strong></summary>
<p>Nativamente não. A MetaMask suporta Ethereum e redes EVM compatíveis (Polygon, BSC, Arbitrum, Optimism, Base). Para guardar Bitcoin, é necessário usar Bitcoin Wrapped (WBTC, versão tokenizada do BTC na rede Ethereum) ou uma wallet específica para Bitcoin como Trust Wallet, Electrum ou hardware wallet.</p>
</details>

<details class='faq-discover'>
<summary><strong>Tenho que pagar imposto sobre ETH que está na MetaMask?</strong></summary>
<p>Sim, mesmas regras gerais: posição acima de R$ 5 mil em 31/12 precisa entrar na declaração anual (DIRPF, ficha Bens e Direitos código 81). Vendas mensais até R$ 35 mil são isentas; acima, incide 15-22,5% sobre o ganho via DARF 4600. Quem opera P2P ou DEX precisa entregar o formulário mensal da IN RFB 1.888/2019 quando o volume passa de R$ 30 mil/mês.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional. Não constitui recomendação de investimento.</em></p>
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

$resultados = [];
foreach ([$p1, $p2, $p3] as $info) {
    echo "\n══════ {$info['slug']} ══════\n";

    $featuredId = 0;
    try {
        $featuredId = (int)($wp->uploadImagemPorUrl169($info['ogUrl'], $info['titulo'], $info['slug']) ?? 0);
        if ($featuredId > 0) echo "✅ Featured 16:9 WebP gogleads: media #{$featuredId}\n";
    } catch (Throwable $e) { echo "uploadImagemPorUrl169: " . $e->getMessage() . "\n"; }
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
        'inLanguage' => 'pt-BR',
        'author' => $schemaAuthor, 'publisher' => $schemaPublisher,
    ];
    $content = $info['html'] . "\n<script type=\"application/ld+json\" data-newsarticle=\"1\">\n" . json_encode($schemaNews, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n</script>\n";

    $cm = new CategoryMatcher($wp, 70.0);
    $catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch(['Criptomoedas']))));
    $tagIds = $wp->resolverTags(['Criptomoedas', 'Bitcoin', 'Ethereum', 'Pix', 'Como Comprar', 'Investimentos', 'P2P', 'DEX', 'MetaMask', 'Binance', 'Mercado Bitcoin']);

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

    $resultados[] = "  → #{$pid} · {$link}";
}

echo "\n══════ RESUMO BATCH A ══════\n";
foreach ($resultados as $l) echo $l . "\n";
