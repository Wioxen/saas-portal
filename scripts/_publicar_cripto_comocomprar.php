<?php
declare(strict_types=1);
/**
 * Post sobre "Como comprar criptomoedas no Brasil em 2026" pra comocomprar.
 *
 * Estratégia editorial 2026-05-14:
 *   - Autoria = Redação do Como Comprar (zero atribuição a portal)
 *   - Scrape Serper Images pra featured scrollstop (Bitcoin + bandeira Brasil)
 *   - Conversão WebP via gogleads automática (uploadImagemPorUrl169)
 *   - Schema NewsArticle + HowTo + FAQPage (3 tipos pra Discover/Rich Results)
 *   - Cita entidades INSTITUCIONAIS: CVM, BC, Receita Federal, Lei 14.478/2022, IN RFB 1.888
 *   - Disclaimers de risco (YMYL — Google ultra-exigente em E-E-A-T)
 *
 * Long-tails cobertos no corpo (via autocomplete Google BR):
 *   - binance, nubank, mercado pago, sem corretora, pix, cartão de crédito,
 *     declarar IR 2026, é seguro, vale a pena.
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';
require_once __DIR__ . '/../lib/SerperImages.php';
require_once __DIR__ . '/../lib/Env.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();
Env::load(__DIR__ . '/../.env');

$slugSite = 'comocomprar';
$titulo   = 'Como comprar criptomoedas no Brasil em 2026: passo a passo, exchanges, Pix, taxas e Imposto de Renda';
$slug     = 'como-comprar-criptomoedas-brasil-2026-exchanges-pix-binance-mercado-bitcoin-ir';
$metaDesc = 'Guia completo para comprar criptomoedas no Brasil em 2026: exchanges regulamentadas (Mercado Bitcoin, Binance), depósito via Pix, taxas, KYC, custódia, declaração no IR e segurança. Sem promessas, com dados.';
$focusKw  = 'como comprar criptomoedas brasil';

// Featured candidate: portaldobitcoin (Bitcoin + bandeira Brasil, 1200×690, ratio 1.74)
$featuredUrl = 'https://portaldobitcoin.uol.com.br/wp-content/uploads/sites/6/2024/10/moeda-de-bitcoin-com-bandeira-do-brasil-ao-fundo-2.jpg';
$featuredQueryFallback = 'comprar criptomoedas bitcoin brasil real'; // pra Serper se URL acima falhar

$html = <<<'HTML'
<p><strong>Comprar criptomoedas no Brasil em 2026</strong> ficou mais simples, mais regulado e com mais opções de pagamento via Pix do que em qualquer outro momento da história do mercado. A combinação de Lei 14.478/2022 (marco legal das criptomoedas), regulação da Comissão de Valores Mobiliários (CVM) e supervisão do Banco Central do Brasil consolidou um ambiente onde plataformas regulamentadas atendem milhões de brasileiros com segurança operacional comparável a corretoras tradicionais.</p>

<p>O guia abaixo apresenta o passo a passo prático para comprar a primeira criptomoeda, comparativo entre as principais plataformas reguladas no país, dados sobre taxas e tributação, e os riscos que merecem atenção antes de qualquer aporte. O conteúdo é educacional e não constitui recomendação de investimento.</p>

<p>O foco é o investidor pessoa física que está começando, com R$ 50 a R$ 5 mil para alocar, e quer entender o processo de ponta a ponta antes de movimentar o primeiro real. Tópicos avançados (DeFi, staking, NFTs) ficam fora do escopo desta leitura inicial.</p>

<h2>O que é uma criptomoeda e por que vale entender antes de comprar</h2>

<p>Criptomoeda é um ativo digital que existe em uma rede descentralizada de computadores, registrada em uma tecnologia chamada blockchain. O Bitcoin (BTC), criado em 2009, é a criptomoeda mais conhecida. O Ethereum (ETH), de 2015, é a segunda em valor de mercado e roda os principais contratos inteligentes do mercado.</p>

<p>Diferente do real ou do dólar, criptomoedas não têm banco central emissor. O preço se forma na oferta e demanda global, com variação de até 10% em um único dia em períodos de volatilidade. Por isso, comprar criptomoeda é um investimento de risco — não há garantia de retorno e o valor pode cair drasticamente em horas.</p>

<p>Entender essa volatilidade antes de comprar é fundamental. O ponto inicial certo é alocar um valor que o investidor possa perder integralmente sem comprometer compromissos financeiros básicos, e tratar o aporte como exposição de longo prazo (3 a 5 anos) em vez de aposta de curto prazo.</p>

<h2>Onde comprar criptomoedas no Brasil: exchanges regulamentadas</h2>

<p>A forma mais simples e segura para iniciantes é comprar via <strong>exchange centralizada</strong> (CEX) regulamentada no Brasil. Exchange é uma plataforma que intermedia a compra e venda, custódia o ativo e cumpre obrigações regulatórias com a Receita Federal e o Banco Central.</p>

<p>As principais opções com operação consolidada no Brasil em 2026 incluem:</p>

<ul>
  <li><strong>Mercado Bitcoin (MB):</strong> a maior exchange brasileira, fundada em 2013, com CNPJ ativo da Mercado Bitcoin Serviços Digitais Ltda. Suporte completo em português, app próprio e cadastro 100% online com Pix.</li>
  <li><strong>Binance:</strong> a maior exchange global, com escritório local. Oferece o maior catálogo de criptoativos do mundo, ferramentas avançadas e suporte ao mercado P2P.</li>
  <li><strong>Foxbit:</strong> exchange brasileira histórica, com foco em segurança e suporte por chat. Cadastro nacional simplificado.</li>
  <li><strong>NovaDAX:</strong> opera no Brasil desde 2018, com integração ao Pix, listagem ampla de criptos e taxas competitivas.</li>
  <li><strong>BitPreço:</strong> agregador que compara preço entre exchanges em tempo real e executa a compra na que oferece o melhor valor.</li>
  <li><strong>Coinex Brasil:</strong> ramo brasileiro da Coinex global, com cadastro nacional e suporte em português.</li>
</ul>

<p>Cada plataforma tem perfil diferente. Mercado Bitcoin atende melhor o iniciante absoluto pela interface simples; Binance é mais indicada para quem quer maior variedade; BitPreço entrega o melhor preço efetivo via comparativo. A escolha depende do tamanho do aporte, da frequência de operação e da preferência por suporte em português.</p>

<h2>Plataformas que oferecem cripto mas não são exchanges puras</h2>

<p>Bancos digitais e corretoras tradicionais brasileiras começaram a oferecer criptomoedas via parceria com exchanges, sem terem custódia própria. Para o usuário, a interface aparece dentro do app conhecido, mas a operação ocorre por trás na exchange parceira:</p>

<ul>
  <li><strong>Nubank Cripto:</strong> integração via Mercado Bitcoin. Compra direta pelo app do Nubank, com Bitcoin, Ethereum e algumas altcoins selecionadas. Limite mínimo a partir de R$ 1.</li>
  <li><strong>Mercado Pago Cripto:</strong> oferece Bitcoin, Ethereum e USDC dentro do app Mercado Pago, com saldo separado do saldo normal de pagamentos.</li>
  <li><strong>XP Investimentos:</strong> permite comprar cripto via XP Trade Cripto, voltado para clientes com perfil de investidor já cadastrado na corretora.</li>
  <li><strong>Rico:</strong> oferece exposição a criptoativos via fundos de investimento e ETFs listados na B3 (HASH11, QBTC11, QETH11), sem custódia direta.</li>
</ul>

<p>Comprar pelo banco digital ou pela corretora tradicional é mais simples para quem já tem o app instalado, mas costuma custar mais caro nas taxas embutidas no spread. Em geral, comprar direto na exchange (Mercado Bitcoin, Binance) sai 0,5% a 2% mais barato no mesmo valor de aporte.</p>

<h2>Passo a passo: como comprar a primeira criptomoeda</h2>

<p>O fluxo padrão em qualquer exchange regulamentada no Brasil segue 5 etapas. Os tempos abaixo são estimativas para cadastros pessoais (CPF), sem complicações de validação:</p>

<ol>
  <li><strong>Cadastro na exchange (15-30 min):</strong> baixar o app oficial (ou acessar o site), informar nome completo, CPF, e-mail e celular. Criar senha forte e ativar autenticação em dois fatores (2FA) via Google Authenticator ou Authy.</li>
  <li><strong>Verificação de identidade (KYC, 1-24h):</strong> enviar foto do RG ou CNH, comprovante de residência recente e selfie com o documento. A validação é exigência da Lei 14.478/2022 e da regulação CVM. Plataformas com algoritmo automático liberam em minutos; manuais podem levar 24 horas.</li>
  <li><strong>Depósito via Pix (instantâneo):</strong> dentro do app, abrir a área de depósito, copiar a chave Pix da exchange (geralmente CNPJ da empresa) e fazer a transferência pelo banco. O saldo cai em até 60 segundos. TED e DOC também funcionam, mas demoram mais.</li>
  <li><strong>Ordem de compra (1 min):</strong> escolher a criptomoeda desejada (BTC, ETH ou outra), informar o valor em reais que quer aplicar e confirmar. O sistema executa a ordem ao preço de mercado e adiciona o ativo na carteira da plataforma.</li>
  <li><strong>Custódia (opcional, 10-30 min):</strong> manter o ativo na exchange ou transferir para uma carteira própria (Trust Wallet, MetaMask para Ethereum, ou hardware wallet Ledger/Trezor). Iniciantes podem deixar na exchange por simplicidade; valores acima de R$ 5 mil pedem migração para wallet pessoal.</li>
</ol>

<div class='cta-oficial' style='margin:24px 0;padding:18px 22px;background:#fef3e8;border-left:6px solid #d97706;border-radius:6px;'><p style='margin:0 0 8px;font-size:17px;color:#1a2a1f;'><strong>Antes de comprar: 3 verificações de segurança</strong></p><ul style='margin:0;padding-left:20px;font-size:14px;line-height:1.6;color:#3a4a3f;'><li>Confirme que a exchange tem CNPJ ativo na Receita Federal e endereço operacional no Brasil;</li><li>Ative 2FA imediatamente após criar a conta;</li><li>Nunca compartilhe senha, chave privada ou frase de recuperação (seed) com ninguém.</li></ul></div>

<h2>Pix se consolidou como o método de pagamento mais usado para cripto</h2>

<p>O Pix se tornou o canal preferencial para compra de criptomoedas no Brasil em 2026. As principais razões:</p>

<ul>
  <li><strong>Instantâneo:</strong> o saldo cai na exchange em segundos, permitindo aproveitar movimentos de preço sem atraso;</li>
  <li><strong>Gratuito ou baratíssimo:</strong> a maioria dos bancos não cobra taxa para Pix de pessoa física;</li>
  <li><strong>Sem limite restritivo:</strong> Pix permite transações de até R$ 1 milhão por operação (sujeito a limites de cada banco);</li>
  <li><strong>Pago 24/7:</strong> funciona inclusive nos fins de semana e feriados, ao contrário de TED e DOC.</li>
</ul>

<p>Cartão de crédito também aparece como opção em algumas plataformas, mas com cuidado: as taxas variam entre 4% e 8% sobre o valor da compra, encarecendo significativamente o investimento. Para aportes de R$ 100 a R$ 5 mil, Pix é quase sempre o melhor caminho.</p>

<h2>Taxas: spread, corretagem e como comparar custo real</h2>

<p>O custo total de comprar criptomoedas em uma exchange tem 3 componentes principais:</p>

<ul>
  <li><strong>Taxa de corretagem (trading fee):</strong> normalmente entre 0,1% e 0,5% do valor da operação. Plataformas como Binance e NovaDAX cobram 0,1% para ordens grandes; iniciantes costumam pagar 0,25% a 0,5%.</li>
  <li><strong>Spread:</strong> diferença entre o preço de compra e venda. Em criptoativos com alta liquidez (BTC, ETH), o spread é pequeno. Em altcoins, pode ser 1% a 3%.</li>
  <li><strong>Taxa de saque (withdrawal fee):</strong> custo para transferir o cripto para outra carteira. Varia por moeda: Bitcoin custa cerca de R$ 30 a R$ 80 por saque na rede principal; Ethereum varia conforme o congestionamento.</li>
</ul>

<p>Para o iniciante que vai comprar e manter o ativo na exchange (sem saque externo), o custo real fica entre 0,5% e 2% por operação. Para quem move para wallet própria, soma-se o custo de saque.</p>

<h2>Custódia: deixar na exchange ou mover para carteira pessoal</h2>

<p>Depois de comprar, o ativo fica em uma de duas situações: custodial (na exchange) ou não-custodial (carteira pessoal). Cada opção tem trade-offs claros:</p>

<ul>
  <li><strong>Custodial (na exchange):</strong> a plataforma guarda. Mais simples para o usuário, mas depende da solvência e segurança da empresa. Histórico do mercado mostra falências (FTX em 2022, Mt.Gox em 2014) que congelaram saldos por anos.</li>
  <li><strong>Hot wallet pessoal:</strong> carteira em app no celular ou navegador (MetaMask para Ethereum, Trust Wallet, Phantom para Solana). Usuário controla a chave privada, mas vulnerável a malware e phishing.</li>
  <li><strong>Cold wallet (hardware wallet):</strong> dispositivo físico tipo Ledger ou Trezor, custa entre R$ 400 e R$ 1.500. Chave privada nunca toca a internet. Recomendado para valores acima de R$ 10 mil.</li>
</ul>

<p>A regra prática usada por gestores conservadores: até R$ 5 mil, custódia na exchange basta. Entre R$ 5 mil e R$ 50 mil, transferir para hot wallet pessoal. Acima de R$ 50 mil, hardware wallet com seed phrase guardada offline em local seguro.</p>

<h2>Imposto de Renda em criptomoedas: o que muda em 2026</h2>

<p>A Receita Federal trata criptoativos como bens, não como moeda. A tributação segue dois eixos: imposto sobre ganho de capital nas vendas, e obrigação acessória de informar posições mensais e anuais.</p>

<p>Em 2026 valem as seguintes regras gerais:</p>

<ul>
  <li><strong>Vendas mensais até R$ 35 mil:</strong> isentas de imposto de renda sobre ganho de capital;</li>
  <li><strong>Vendas mensais acima de R$ 35 mil:</strong> incidem alíquotas de 15% a 22,5% sobre o ganho (lucro entre compra e venda), conforme o valor total apurado no mês;</li>
  <li><strong>Declaração de bens (DIRPF):</strong> obrigatória para quem teve posição acima de R$ 5 mil em qualquer criptoativo em 31/12. Lançamento na ficha Bens e Direitos sob o código 81 (criptoativos);</li>
  <li><strong>IN RFB 1.888/2019:</strong> exchanges brasileiras informam à Receita Federal todas as operações de compra, venda e movimentação. Quem operou em exchange estrangeira ou fez P2P precisa entregar mensalmente o formulário próprio quando ultrapassar R$ 30 mil em operações no mês.</li>
</ul>

<p>O imposto sobre ganho é apurado e pago até o último dia útil do mês seguinte à operação, via DARF código 4600. O cálculo considera o preço médio de compra como custo de aquisição.</p>

<h2>Os 5 riscos que merecem atenção antes de comprar</h2>

<p>O ambiente cripto consolidou-se nos últimos anos, mas os riscos seguem reais. Entender antes de comprar reduz a chance de prejuízo evitável:</p>

<ol>
  <li><strong>Volatilidade:</strong> o preço pode cair 30% a 50% em ciclos de baixa do mercado. Bitcoin já perdeu mais de 70% do valor entre o pico e o vale em 2017-2018 e em 2021-2022.</li>
  <li><strong>Golpes e phishing:</strong> sites falsos imitando exchanges, "suporte" pedindo senha por WhatsApp, falsas oportunidades de retorno garantido. Nenhuma exchange séria pede chave privada ou seed phrase.</li>
  <li><strong>Esquemas Ponzi disfarçados de cripto:</strong> "investimento garantido" com retornos mensais altos é fraude. A Comissão de Valores Mobiliários (CVM) publica lista de empresas não autorizadas.</li>
  <li><strong>Falência de exchange:</strong> mesmo plataformas grandes podem quebrar. Diversificar entre 2 plataformas reduz exposição. Saldos relevantes devem migrar para carteira própria.</li>
  <li><strong>Perda de senha ou seed:</strong> sem custódia centralizada, perder a chave privada significa perder o ativo permanentemente. Backup físico (papel laminado ou metal) é essencial para wallets pessoais.</li>
</ol>

<h2>Quanto investir e como começar: regra prática</h2>

<p>Especialistas em finanças pessoais costumam recomendar exposição máxima a criptoativos entre 1% e 10% do patrimônio total, conforme o perfil de risco do investidor. Para o iniciante, valores entre R$ 100 e R$ 1.000 funcionam como aporte de aprendizagem, suficiente para entender a operação sem comprometer reserva de emergência.</p>

<p>Estratégia DCA (Dollar Cost Averaging) é o método mais usado por investidores conservadores: aportar valor fixo (R$ 100, R$ 200, R$ 500) todo mês, independente do preço. Reduz o risco de comprar no topo e suaviza a volatilidade ao longo do tempo.</p>

<details class='faq-discover'>
<summary><strong>É seguro comprar criptomoedas no Brasil em 2026?</strong></summary>
<p>É seguro do ponto de vista operacional quando feito em exchanges regulamentadas com CNPJ ativo, registro junto à Receita Federal e cumprimento da Lei 14.478/2022. O risco principal é a volatilidade do ativo: o preço pode oscilar fortemente, com perdas relevantes em períodos de baixa do mercado. Segurança operacional não significa garantia de retorno.</p>
</details>

<details class='faq-discover'>
<summary><strong>Posso comprar criptomoedas pelo Nubank?</strong></summary>
<p>Sim. O Nubank oferece compra de Bitcoin, Ethereum e algumas altcoins selecionadas direto pelo app, em parceria com o Mercado Bitcoin para custódia e execução. O valor mínimo é a partir de R$ 1, e o pagamento é debitado direto da conta Nubank. Taxas costumam ser mais altas que comprar direto na exchange, mas a interface é mais simples para iniciantes.</p>
</details>

<details class='faq-discover'>
<summary><strong>É possível comprar criptomoedas sem corretora?</strong></summary>
<p>Sim, via mercado P2P (peer-to-peer) ou exchanges descentralizadas (DEXs). No P2P (disponível na Binance, por exemplo), o usuário compra direto de outro vendedor pagando via Pix, sem custódia da exchange. Em DEXs (Uniswap, PancakeSwap), o usuário troca criptos sem intermediário centralizado, mas precisa de uma wallet pessoal (MetaMask) e arcar com taxas de rede. Comprar sem corretora reduz custos mas exige mais conhecimento técnico.</p>
</details>

<details class='faq-discover'>
<summary><strong>Como comprar criptomoedas pelo Pix em 2026?</strong></summary>
<p>O processo é direto: criar conta na exchange escolhida, completar o cadastro KYC, abrir a área de depósito, copiar a chave Pix da plataforma (geralmente o CNPJ), fazer transferência pelo banco e aguardar até 60 segundos para o saldo cair. Depois é só executar a ordem de compra do ativo desejado pelo valor em reais. Pix é gratuito na maioria dos bancos para pessoa física.</p>
</details>

<details class='faq-discover'>
<summary><strong>Como declarar criptomoedas no Imposto de Renda 2026?</strong></summary>
<p>A declaração é obrigatória para quem teve posição acima de R$ 5 mil em qualquer criptoativo em 31 de dezembro do ano anterior. O lançamento é feito na ficha Bens e Direitos da declaração anual (DIRPF) sob o código 81 (criptoativos). Vendas mensais até R$ 35 mil são isentas de imposto sobre ganho. Vendas acima desse valor pagam de 15% a 22,5% sobre o lucro, com pagamento via DARF código 4600 até o último dia útil do mês seguinte.</p>
</details>

<details class='faq-discover'>
<summary><strong>Comprar criptomoedas é crime no Brasil?</strong></summary>
<p>Não. A Lei 14.478/2022 (marco legal das criptomoedas) regulamentou o setor e tornou explicitamente legal a compra, venda, custódia e transferência de criptoativos por pessoas físicas e jurídicas. O Banco Central regula prestadoras de serviços de ativos virtuais, e a Comissão de Valores Mobiliários (CVM) supervisiona ofertas com características de valor mobiliário. Operações fora dessas regras (lavagem de dinheiro, evasão fiscal) seguem ilegais como em qualquer outro mercado.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual a melhor exchange para comprar Bitcoin no Brasil?</strong></summary>
<p>Não existe melhor universal — depende do perfil. Mercado Bitcoin atende melhor o iniciante por interface simples e suporte em português. Binance oferece o maior catálogo e menores taxas para volume alto. BitPreço executa a compra na exchange com melhor preço efetivo em tempo real. Para começar, Mercado Bitcoin e Binance cobrem a maioria dos casos de uso.</p>
</details>

<details class='faq-discover'>
<summary><strong>Vale a pena comprar criptomoedas em 2026?</strong></summary>
<p>Depende do perfil de risco e dos objetivos. Para diversificação de portfólio em horizonte de 3 a 5 anos, com exposição entre 1% e 10% do patrimônio, faz sentido para investidores que toleram alta volatilidade. Para quem busca preservação de capital ou retorno previsível no curto prazo, criptomoedas não são o instrumento adequado. Em qualquer cenário, alocar apenas valor que se possa perder integralmente sem comprometer compromissos financeiros.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional. Não constitui recomendação de investimento. Antes de aplicar, consulte um assessor certificado pela CVM.</em></p>
HTML;

// ──────────────────────────────────────────────────────────────────
// SCHEMAS — NewsArticle + HowTo + FAQPage
// ──────────────────────────────────────────────────────────────────
$schemaNews = [
    '@context' => 'https://schema.org', '@type' => 'NewsArticle',
    'headline' => $titulo,
    'datePublished' => date('c'), 'dateModified' => date('c'),
    'inLanguage' => 'pt-BR',
    'author' => ['@type' => 'Organization', 'name' => 'Redação Como Comprar', 'url' => 'https://comocomprar.com.br'],
    'publisher' => ['@type' => 'Organization', 'name' => 'Como Comprar', 'url' => 'https://comocomprar.com.br'],
];

$schemaHowTo = [
    '@context' => 'https://schema.org', '@type' => 'HowTo',
    'name' => 'Como comprar criptomoedas no Brasil em 2026',
    'description' => 'Passo a passo para comprar a primeira criptomoeda em uma exchange brasileira regulamentada via Pix.',
    'totalTime' => 'PT45M',
    'step' => [
        ['@type' => 'HowToStep', 'position' => 1, 'name' => 'Cadastro na exchange', 'text' => 'Baixar app oficial, informar nome completo, CPF, e-mail e celular. Criar senha forte e ativar autenticação em dois fatores (2FA).'],
        ['@type' => 'HowToStep', 'position' => 2, 'name' => 'Verificação de identidade (KYC)', 'text' => 'Enviar foto do RG ou CNH, comprovante de residência recente e selfie com o documento. Exigência da Lei 14.478/2022 e da CVM.'],
        ['@type' => 'HowToStep', 'position' => 3, 'name' => 'Depósito via Pix', 'text' => 'Copiar a chave Pix da exchange (geralmente CNPJ da empresa) e fazer transferência pelo banco. Saldo cai em até 60 segundos.'],
        ['@type' => 'HowToStep', 'position' => 4, 'name' => 'Ordem de compra', 'text' => 'Escolher a criptomoeda (BTC, ETH ou outra), informar o valor em reais e confirmar. Sistema executa ao preço de mercado.'],
        ['@type' => 'HowToStep', 'position' => 5, 'name' => 'Custódia', 'text' => 'Manter na exchange (até R$ 5 mil) ou transferir para wallet pessoal (Trust Wallet, MetaMask ou hardware wallet Ledger/Trezor para valores maiores).'],
    ],
];

$schemaFaq = [
    '@context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => [
        ['@type' => 'Question', 'name' => 'É seguro comprar criptomoedas no Brasil em 2026?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'É seguro operacionalmente quando feito em exchanges regulamentadas com CNPJ ativo e cumprimento da Lei 14.478/2022. O risco principal é a volatilidade do ativo. Segurança operacional não significa garantia de retorno.']],
        ['@type' => 'Question', 'name' => 'Posso comprar criptomoedas pelo Nubank?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Sim. O Nubank oferece Bitcoin, Ethereum e algumas altcoins direto pelo app, em parceria com o Mercado Bitcoin. Valor mínimo a partir de R$ 1.']],
        ['@type' => 'Question', 'name' => 'É possível comprar criptomoedas sem corretora?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Sim, via mercado P2P (peer-to-peer) ou exchanges descentralizadas (DEXs) como Uniswap. Reduz custos mas exige conhecimento técnico e wallet pessoal.']],
        ['@type' => 'Question', 'name' => 'Como comprar criptomoedas pelo Pix em 2026?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Criar conta na exchange, completar KYC, copiar a chave Pix da plataforma, fazer transferência pelo banco e aguardar até 60 segundos para o saldo cair. Depois executar a ordem de compra.']],
        ['@type' => 'Question', 'name' => 'Como declarar criptomoedas no Imposto de Renda 2026?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Obrigatório para quem teve posição acima de R$ 5 mil em 31/12 do ano anterior. Lançar na ficha Bens e Direitos código 81. Vendas mensais acima de R$ 35 mil pagam 15% a 22,5% sobre o lucro via DARF 4600.']],
        ['@type' => 'Question', 'name' => 'Comprar criptomoedas é crime no Brasil?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Não. A Lei 14.478/2022 regulamentou o setor. Banco Central e CVM supervisionam o mercado. Operações de lavagem ou evasão fiscal seguem ilegais como em qualquer mercado.']],
        ['@type' => 'Question', 'name' => 'Qual a melhor exchange para comprar Bitcoin no Brasil?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Não existe melhor universal. Mercado Bitcoin atende iniciantes pela interface simples. Binance oferece maior catálogo e menores taxas para volume alto. BitPreço executa na exchange com melhor preço em tempo real.']],
        ['@type' => 'Question', 'name' => 'Vale a pena comprar criptomoedas em 2026?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Depende do perfil. Para diversificação de portfólio em horizonte de 3 a 5 anos, com exposição entre 1% e 10% do patrimônio, faz sentido para quem tolera alta volatilidade. Alocar apenas valor que se possa perder integralmente.']],
    ],
];

$contentFinal = $html
    . "\n<script type=\"application/ld+json\" data-newsarticle=\"1\">\n"
    . json_encode($schemaNews, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n</script>\n"
    . "<script type=\"application/ld+json\" data-howto=\"1\">\n"
    . json_encode($schemaHowTo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n</script>\n"
    . "<script type=\"application/ld+json\" data-faqpage=\"1\">\n"
    . json_encode($schemaFaq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n</script>\n";

// ──────────────────────────────────────────────────────────────────
// PUBLICAÇÃO via WP REST
// ──────────────────────────────────────────────────────────────────
echo "═══════ {$slugSite} — Como comprar criptomoedas (autoridade ampla) ═══════\n";
$cfgSite = $cfg;
aplicarSite($cfgSite, $sites, $slugSite);
$wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

// Featured: scrollstop Bitcoin + Brasil. Tenta URL escolhida, fallback via Serper.
$featuredId = 0;
echo "Tentando featured curated: {$featuredUrl}\n";
try {
    $featuredId = (int)($wp->uploadImagemPorUrl169($featuredUrl, $titulo, $slug) ?? 0);
    if ($featuredId > 0) echo "✅ Featured 16:9 WebP via gogleads: media #{$featuredId}\n";
} catch (Throwable $e) {
    echo "  Falha featured curated: " . $e->getMessage() . "\n";
}

if ($featuredId === 0) {
    echo "Fallback: Serper Images query...\n";
    try {
        $sx = new SerperImages(Env::get('SERPER_API_KEY'));
        $img = $sx->melhor($featuredQueryFallback, [
            'min_w' => 1000, 'min_h' => 550, 'credito_generico' => true,
            'evitar_dominios' => ['instagram.com', 'tiktok.com', 'twitter.com', 'x.com', 'facebook.com', 'pinterest.com', 'reddit.com'],
        ]);
        if ($img && !empty($img['imageUrl'])) {
            echo "  Serper escolheu: {$img['imageUrl']} ({$img['imageWidth']}×{$img['imageHeight']})\n";
            $featuredId = (int)($wp->uploadImagemPorUrl169($img['imageUrl'], $titulo, $slug) ?? 0);
            if ($featuredId > 0) echo "✅ Featured Serper 16:9 WebP: media #{$featuredId}\n";
        }
    } catch (Throwable $e) {
        echo "  Falha Serper: " . $e->getMessage() . "\n";
    }
}

if ($featuredId > 0) {
    $wp->atualizarMedia($featuredId, [
        'caption'     => "Como comprar criptomoedas no Brasil em 2026 (Foto: divulgação)",
        'description' => "Imagem ilustrativa da matéria '{$titulo}'.",
        'title'       => $titulo,
        'alt_text'    => 'Bitcoin sobre bandeira do Brasil — guia para comprar criptomoedas em 2026',
    ]);
}

$cm = new CategoryMatcher($wp, 70.0);
$catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch(['Criptomoedas']))));
if (empty($catIds)) {
    $catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch(['Investimentos']))));
}
if (empty($catIds)) {
    $catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch(['Tecnologia']))));
}

$tags = ['Criptomoedas', 'Bitcoin', 'Ethereum', 'Mercado Bitcoin', 'Binance', 'Nubank Cripto', 'Pix', 'Imposto de Renda', 'Lei 14.478/2022', 'CVM', 'Receita Federal', 'Carteira Digital', 'Exchange', 'Como Comprar'];
$tagIds = $wp->resolverTags($tags);

$payload = [
    'title' => $titulo, 'slug' => $slug, 'content' => $contentFinal,
    'status' => 'draft',
    'meta' => [
        'rank_math_title'         => 'Como comprar criptomoedas no Brasil em 2026: guia completo | Como Comprar',
        'rank_math_description'   => $metaDesc,
        'rank_math_focus_keyword' => $focusKw,
    ],
    'categories' => $catIds, 'tags' => $tagIds,
];
if ($featuredId > 0) $payload['featured_media'] = $featuredId;
if (!empty($cfgSite['default_post_author_id'])) $payload['author'] = (int)$cfgSite['default_post_author_id'];

$r = $wp->criarPost($payload);
$postId = (int)($r['id'] ?? 0);
$link = (string)($r['link'] ?? '');
if ($postId === 0) { echo "❌ ERRO criarPost\n"; exit(1); }

echo "\n✅ Post #{$postId} DRAFT criado\n";
echo "Link: {$link}\n";
echo "Admin: {$cfgSite['wp_url']}/wp-admin/post.php?post={$postId}&action=edit\n";

// Posts relacionados (best effort)
try {
    $rel = $wp->buscarRelacionados('comprar', 4, $postId);
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
        echo "✅ Relacionados: " . min(4, count($rel)) . "\n";
    }
} catch (Throwable $e) {}

echo "\n══════ CLUSTER PROPOSTO (próximos posts) ══════\n";
$cluster = [
    'Como comprar Bitcoin sem corretora: P2P na Binance e DEXs (Uniswap, PancakeSwap)',
    'Como comprar criptomoedas pelo Pix: passo a passo nas 6 principais exchanges',
    'Como declarar criptomoedas no Imposto de Renda 2026: ficha Bens, código 81 e DARF',
    'Melhor exchange brasileira em 2026: comparativo Mercado Bitcoin x Binance x Foxbit x NovaDAX',
    'Carteira de criptomoedas: hot wallet, cold wallet e quando migrar (Ledger, Trezor, MetaMask)',
    'Como comprar Bitcoin na Nubank em 2026: parceria com Mercado Bitcoin explicada',
    'Como comprar Ethereum na MetaMask: do Pix à wallet em 15 minutos',
    'Comprar criptomoedas é seguro? Os 5 riscos reais e como proteger seu aporte',
];
foreach ($cluster as $t) echo "  · {$t}\n";
