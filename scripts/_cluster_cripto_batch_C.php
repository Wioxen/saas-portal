<?php
declare(strict_types=1);
/**
 * Cluster cripto comocomprar — Batch C (2 posts finais).
 * Posts: Nubank Cripto, Comprar criptomoedas é seguro? 5 riscos.
 * Autoria = Redação do Como Comprar.
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

// ════════════════════════════════════════════════════════════════════
// POST 7 — Como comprar Bitcoin na Nubank
// ════════════════════════════════════════════════════════════════════
$p7 = [
    'titulo' => 'Como comprar Bitcoin na Nubank em 2026: passo a passo, valor mínimo, taxas e parceria com Mercado Bitcoin',
    'slug'   => 'como-comprar-bitcoin-nubank-2026-mercado-bitcoin-parceria-passo-a-passo',
    'metaDesc' => 'Guia completo para comprar Bitcoin na Nubank em 2026 via o serviço Nu Cripto: passo a passo no app, valor mínimo de R$ 1, taxas, custódia via Mercado Bitcoin e como sacar para wallet pessoal.',
    'focusKw' => 'como comprar bitcoin nubank',
    'ogUrl' => 'https://livecoins.com.br/wp-content/uploads/2022/07/Aplicativo-do-Nubank-proximo-de-criptomoedas-Bitcoin-e-Ethereum-.jpg.webp',
];
$p7['html'] = <<<'HTML'
<p><strong>Comprar Bitcoin na Nubank</strong> ficou possível em 2022, quando o banco digital lançou o serviço Nu Cripto em parceria com o Mercado Bitcoin para custódia e execução. Em 2026, mais de 2 milhões de clientes Nubank já compraram cripto direto pelo app, atraídos pela combinação de interface simples, valor mínimo de R$ 1 e ausência de cadastro em exchange externa.</p>

<p>A operação é mais cara que comprar direto no Mercado Bitcoin (taxas embutidas no spread), mas resolve a barreira de entrada para quem nunca operou cripto. O Nubank funciona como camada de interface, com a parte técnica delegada ao parceiro.</p>

<p>O guia abaixo cobre o passo a passo no app Nubank, quais criptomoedas estão disponíveis, comparação de taxas com a compra direta em exchange, custódia e a possibilidade de transferir para wallet pessoal.</p>

<h2>Como funciona a parceria Nubank + Mercado Bitcoin</h2>

<p>Nu Cripto não é uma exchange própria do Nubank. O banco oferece a interface dentro do próprio app, mas as operações de compra, venda e custódia são executadas pelo Mercado Bitcoin (CNPJ Mercado Bitcoin Serviços Digitais Ltda). O fluxo é o seguinte:</p>

<ul>
  <li><strong>Interface:</strong> roxa, intuitiva, dentro do app do Nubank (mesma tela onde aparecem conta, cartão e investimentos);</li>
  <li><strong>Execução:</strong> Mercado Bitcoin executa a compra no mercado spot, na ordem solicitada pelo cliente Nubank;</li>
  <li><strong>Custódia:</strong> os criptoativos ficam custodiados na infraestrutura do Mercado Bitcoin, registrados no nome do cliente Nubank;</li>
  <li><strong>KYC:</strong> aproveita a verificação de identidade que o cliente já fez para abrir a conta Nubank (CPF, foto do RG, selfie). Não precisa de cadastro adicional;</li>
  <li><strong>Tributação:</strong> as operações já vão automaticamente para o sistema da Receita Federal via Mercado Bitcoin (IN RFB 1.888/2019).</li>
</ul>

<h2>Quais criptomoedas o Nubank vende</h2>

<p>O catálogo Nu Cripto é menor que o do Mercado Bitcoin direto, com foco em moedas estabelecidas. Em 2026, estão disponíveis:</p>

<ul>
  <li><strong>Bitcoin (BTC):</strong> a principal opção, disponível desde o lançamento em 2022;</li>
  <li><strong>Ethereum (ETH):</strong> segunda cripto por valor de mercado;</li>
  <li><strong>USDC (USD Coin):</strong> stablecoin lastreada em dólar americano, alternativa para preservação de valor;</li>
  <li><strong>Solana (SOL):</strong> adicionada em 2024, blockchain de alta velocidade;</li>
  <li><strong>Cardano (ADA):</strong> blockchain prova-de-participação;</li>
  <li><strong>Polkadot (DOT):</strong> protocolo de interoperabilidade entre blockchains;</li>
  <li><strong>Chainlink (LINK):</strong> rede de oracles para contratos inteligentes;</li>
  <li><strong>Bitcoin Cash (BCH), Litecoin (LTC):</strong> bifurcações históricas do Bitcoin.</li>
</ul>

<p>O catálogo é atualizado periodicamente. Altcoins menores e tokens DeFi não estão disponíveis no Nu Cripto — para esses, é preciso usar exchange dedicada (Binance, NovaDAX).</p>

<h2>Passo a passo: comprar Bitcoin no app Nubank</h2>

<p>O processo demora menos de 5 minutos para quem já é cliente Nubank com conta verificada:</p>

<ol>
  <li>Abrir o app Nubank e fazer login;</li>
  <li>Na tela inicial, rolar até encontrar a seção <strong>Nu Cripto</strong> (geralmente entre Investimentos e Caixinha);</li>
  <li>Tocar em Nu Cripto. Na primeira vez, o app exibe uma tela de introdução explicando os riscos (volatilidade, sem garantia de retorno). Aceitar os termos;</li>
  <li>Selecionar Bitcoin (BTC). A tela mostra a cotação atual e o gráfico de preço;</li>
  <li>Tocar em Comprar e informar o valor em reais (mínimo R$ 1, sem máximo definido além do saldo disponível);</li>
  <li>O app exibe quanto de BTC será adquirido após desconto da taxa. Confirmar com senha do Nubank ou biometria;</li>
  <li>O Bitcoin aparece imediatamente no extrato Nu Cripto, com cotação atualizada em tempo real.</li>
</ol>

<h2>Quanto custa: as taxas do Nu Cripto vs Mercado Bitcoin direto</h2>

<p>O Nubank não cobra taxa de corretagem explícita. O custo está embutido no <strong>spread</strong> — diferença entre o preço de compra do mercado e o preço cobrado do cliente. Comparativo prático para R$ 100 em Bitcoin:</p>

<ul>
  <li><strong>Nu Cripto (via Nubank):</strong> spread estimado de 1,5% a 2,5%. Em R$ 100, recebe-se cerca de R$ 97,50 a R$ 98,50 em BTC;</li>
  <li><strong>Mercado Bitcoin direto (app MB):</strong> taxa de 0,3% a 0,7% + spread menor. Em R$ 100, recebe-se cerca de R$ 99 a R$ 99,50;</li>
  <li><strong>Binance:</strong> taxa 0,1% padrão + spread baixo. Em R$ 100, recebe-se cerca de R$ 99,50 em BTC.</li>
</ul>

<p>A diferença parece pequena em valores baixos, mas se acumula. Em aporte mensal de R$ 1.000 ao longo de 12 meses, a diferença entre Nu Cripto e Mercado Bitcoin direto pode chegar a R$ 200 perdidos para o spread.</p>

<h2>Posso transferir o Bitcoin do Nubank para wallet pessoal?</h2>

<p>Sim, desde 2023 o Nubank permite saque de Bitcoin e Ethereum para wallet pessoal (MetaMask, Trust Wallet, hardware wallets). O fluxo:</p>

<ol>
  <li>Dentro do Nu Cripto, selecionar Bitcoin → Sacar;</li>
  <li>Informar o endereço público da wallet de destino (começa com bc1... ou 1... para BTC);</li>
  <li>Informar a quantidade desejada (em BTC ou em BRL equivalente);</li>
  <li>Confirmar a taxa de rede (cobrada pelo Mercado Bitcoin, varia entre R$ 30-80 dependendo do congestionamento do Bitcoin);</li>
  <li>Autenticar com senha Nubank e aguardar de 10 minutos a 2 horas para a transação ser confirmada na blockchain.</li>
</ol>

<p>O saque tem custo, mas é a prática recomendada para valores acima de R$ 5 mil — reduz exposição à infraestrutura compartilhada Mercado Bitcoin e dá controle total da chave privada.</p>

<h2>Vantagens e limitações do Nu Cripto</h2>

<p>O serviço atende um perfil específico de usuário. Vale considerar antes:</p>

<ul>
  <li><strong>✅ Vantagens:</strong> sem cadastro adicional, interface simples, mínimo de R$ 1, integração com saldo Nubank, saque para wallet pessoal disponível, declaração de IR facilitada (Nubank gera relatório anual);</li>
  <li><strong>❌ Limitações:</strong> taxas mais altas (spread de 1,5-2,5%), catálogo menor que exchanges (cerca de 10 criptos vs 30 do MB e 350+ da Binance), sem ferramentas avançadas (gráficos, ordens limit, futures);</li>
  <li><strong>❌ Não tem:</strong> staking nativo, lending, P2P, ofertas iniciais (IDOs), perpetuais.</li>
</ul>

<h2>Para quem o Nu Cripto faz sentido</h2>

<p>O serviço se encaixa em 3 perfis principais:</p>

<ol>
  <li><strong>Iniciante absoluto:</strong> nunca comprou cripto, quer começar com R$ 50-500 sem aprender a operar exchange dedicada;</li>
  <li><strong>Já cliente Nubank que valoriza unificação:</strong> prefere ter conta, cartão e cripto no mesmo app, mesmo pagando spread um pouco maior;</li>
  <li><strong>Aporte recorrente pequeno:</strong> R$ 100 por mês em BTC via DCA, sem necessidade de ferramentas avançadas.</li>
</ol>

<p>Para quem aporta R$ 1.000 ou mais por mês, ou opera várias criptos diferentes, o Mercado Bitcoin direto ou outra exchange compensa pelo menor custo total acumulado.</p>

<details class='faq-discover'>
<summary><strong>O Bitcoin no Nubank é meu mesmo?</strong></summary>
<p>Sim. As operações são executadas pelo Mercado Bitcoin, parceiro técnico do Nubank, mas os criptoativos são custodiados em nome do cliente. O usuário pode sacar para wallet pessoal a qualquer momento, transferindo o controle total para si.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual o valor mínimo para comprar Bitcoin no Nubank?</strong></summary>
<p>R$ 1. O Nubank tem o menor valor mínimo de entrada do mercado brasileiro de cripto. Ideal para quem quer testar a operação ou começar com aporte mensal pequeno.</p>
</details>

<details class='faq-discover'>
<summary><strong>O Nubank cobra taxa de saque de Bitcoin?</strong></summary>
<p>Sim. A taxa de saque para wallet externa é repassada pelo Mercado Bitcoin e varia conforme o congestionamento da rede Bitcoin. Em média, fica entre R$ 30 e R$ 80 por saque. Para Ethereum, a taxa também varia, sendo geralmente menor.</p>
</details>

<details class='faq-discover'>
<summary><strong>Como declarar Bitcoin do Nubank no Imposto de Renda?</strong></summary>
<p>O Nubank gera relatório anual com posição em 31/12 e operações do ano. Lançar na ficha Bens e Direitos da DIRPF, grupo 08 (Criptoativos), código 81 (Bitcoin) ou 82 (outras criptos). O valor é o de aquisição (preço pago), não o de mercado atual. Vendas mensais até R$ 35 mil são isentas.</p>
</details>

<details class='faq-discover'>
<summary><strong>Nu Cripto tem stake ou rendimento em cripto?</strong></summary>
<p>Não atualmente. O Nu Cripto oferece apenas compra e venda spot — sem staking, sem lending, sem yield farming. Para serviços de renda em cripto, é preciso usar plataformas dedicadas (Binance Earn, Mercado Bitcoin Stake, ou DeFi via wallet pessoal).</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional. Não constitui recomendação de investimento.</em></p>
HTML;

// ════════════════════════════════════════════════════════════════════
// POST 8 — Comprar criptomoedas é seguro? 5 riscos
// ════════════════════════════════════════════════════════════════════
$p8 = [
    'titulo' => 'Comprar criptomoedas é seguro em 2026? Os 5 riscos reais e como proteger o seu aporte',
    'slug'   => 'comprar-criptomoedas-e-seguro-5-riscos-volatilidade-golpe-falencia-exchange-2026',
    'metaDesc' => 'Comprar criptomoedas é seguro em 2026? Análise dos 5 riscos reais (volatilidade, phishing, golpes Ponzi, falência de exchange, perda de seed) e as proteções práticas para cada um.',
    'focusKw' => 'comprar criptomoedas é seguro',
    'ogUrl' => 'https://www.policiacivil.se.gov.br/wp-content/uploads/2022/05/CapaH02-1024x574.png',
];
$p8['html'] = <<<'HTML'
<p><strong>Comprar criptomoedas é seguro em 2026?</strong> A resposta honesta é: seguro do ponto de vista <strong>operacional</strong> quando feito em plataformas regulamentadas, mas com 5 riscos específicos que merecem atenção antes do primeiro aporte. A regulação consolidada pela Lei 14.478/2022 e a fiscalização do Banco Central reduziram bastante os riscos de plataforma — mas a volatilidade do ativo, os golpes em ascensão e a responsabilidade pelas próprias chaves seguem como pontos críticos.</p>

<p>O guia abaixo cobre os 5 riscos reais do mercado em 2026, com dados de incidentes recentes, ranking de gravidade e proteções práticas para cada categoria. O conteúdo é editorial e independente — não pretende vender medo, mas mostrar o que efetivamente faz diferença na proteção do investidor pessoa física.</p>

<p>O primeiro princípio: nenhuma fonte séria garante retorno em criptomoedas. Quem promete "100% ao mês", "passive income garantido" ou "Bitcoin a R$ 1 milhão até dezembro" está vendendo golpe ou esquema. A volatilidade é parte estrutural do ativo, não falha do mercado.</p>

<h2>Risco 1: volatilidade — o preço cai 30% a 50% em ciclos de baixa</h2>

<p>A volatilidade é o risco mais previsível e o que menos pode ser eliminado. O Bitcoin já registrou múltiplas quedas históricas significativas:</p>

<ul>
  <li><strong>2017-2018:</strong> queda de USD 19.800 (dezembro/2017) para USD 3.200 (dezembro/2018) — perda de 84% em 12 meses;</li>
  <li><strong>2021-2022:</strong> queda de USD 69.000 (novembro/2021) para USD 15.500 (novembro/2022) — perda de 77%;</li>
  <li><strong>Ciclos curtos:</strong> quedas de 20-30% em janelas de 1 semana são comuns em períodos de tensão geopolítica ou alterações de política monetária dos Estados Unidos.</li>
</ul>

<p><strong>Como proteger:</strong> alocar apenas valor que se possa perder integralmente sem comprometer reserva de emergência, compromissos básicos ou crédito. Investidores conservadores recomendam exposição máxima entre 1% e 10% do patrimônio total em criptoativos. Estratégia DCA (aporte fixo mensal) reduz o impacto de comprar em topos.</p>

<h2>Risco 2: phishing e golpes de "suporte" — fraude na qual vítima entrega chave</h2>

<p>O golpe mais comum em 2026 é o phishing aplicado em duas variações principais:</p>

<ul>
  <li><strong>Site falso imitando exchange ou wallet:</strong> Google Ads pagos posicionam sites idênticos a MetaMask, Binance ou Mercado Bitcoin no topo da pesquisa. Usuário digita seed phrase ou senha, perde o saldo;</li>
  <li><strong>"Suporte" falso por WhatsApp/Telegram:</strong> golpistas criam canais imitando o suporte oficial. Pedem para o usuário "verificar a conta" digitando a frase de recuperação ou senha. Saldo é drenado em minutos.</li>
</ul>

<p>Em 2024-2025, a Federação Brasileira de Bancos (Febraban) registrou aumento de 300% em golpes envolvendo cripto. O valor médio por vítima passou de R$ 25 mil em 2023 para R$ 47 mil em 2025.</p>

<p><strong>Como proteger:</strong> nunca acessar exchange ou wallet por links de pesquisa Google. Salvar URL oficial nos favoritos. Nunca compartilhar seed phrase ou senha com ninguém (nenhuma empresa real pede). Ativar autenticação em dois fatores (2FA) via Google Authenticator ou Authy em toda conta. Em caso de "suporte" pedir dados, encerrar contato e procurar canal oficial.</p>

<h2>Risco 3: esquemas Ponzi disfarçados de cripto — promessa de retorno garantido</h2>

<p>Esquemas Ponzi em cripto seguem padrão clássico: prometem retorno mensal de 5% a 20%, mostram "ganhos" em conta falsa, pedem para o investidor recrutar amigos e somem com o capital quando o influxo de novos depositantes para. Casos brasileiros conhecidos:</p>

<ul>
  <li><strong>Atlas Quantum:</strong> investidores brasileiros perderam mais de R$ 600 milhões entre 2018 e 2019;</li>
  <li><strong>UnickForex:</strong> envolveu R$ 850 milhões em pirâmide com mineração de cripto até 2019;</li>
  <li><strong>GAS Consultoria / Trust Investing:</strong> casos posteriores que se aproveitaram do entusiasmo cripto entre 2022 e 2024.</li>
</ul>

<p>A Comissão de Valores Mobiliários (CVM) mantém lista pública de empresas <strong>não autorizadas</strong> a operar no Brasil. Em 2026, mais de 80 empresas relacionadas a cripto constam na lista oficial.</p>

<p><strong>Como proteger:</strong> antes de qualquer aporte fora de exchange regulamentada, consultar a lista de não-autorizadas no site da CVM (gov.br/cvm). Suspeitar de retorno garantido — não existe na realidade. Suspeitar de operação que paga comissão por indicação. Empresas legítimas não trabalham assim.</p>

<h2>Risco 4: falência de exchange — saldo congelado por anos</h2>

<p>Mesmo plataformas grandes podem quebrar. Os casos históricos mais relevantes:</p>

<ul>
  <li><strong>Mt.Gox (2014):</strong> exchange japonesa que dominava 70% do mercado mundial faliu após hack. Cerca de 850 mil BTC desapareceram. Investidores estão recebendo parcial em 2024-2026, 10 anos depois;</li>
  <li><strong>FTX (2022):</strong> segunda maior exchange do mundo quebrou em novembro de 2022. CEO Sam Bankman-Fried condenado a 25 anos em 2024. Recuperação parcial ainda em andamento;</li>
  <li><strong>Celsius Network (2022):</strong> plataforma de lending congelou saldos em junho de 2022. Reorganização ainda em curso.</li>
</ul>

<p>No Brasil, exchanges como Mercado Bitcoin, Binance Brasil, NovaDAX e Foxbit operam sob a Lei 14.478/2022, com supervisão do Banco Central e da Comissão de Valores Mobiliários. O cenário é mais regulado, mas o risco existe.</p>

<p><strong>Como proteger:</strong> não manter todo o patrimônio cripto em uma única exchange. Para valores acima de R$ 5 mil, migrar para wallet pessoal (hot wallet). Acima de R$ 50 mil, usar hardware wallet (Ledger, Trezor). Manter saldos espalhados em 2 plataformas reduz exposição. Conferir se a exchange publica Proof of Reserves (relatórios periódicos mostrando que tem reservas equivalentes aos saldos).</p>

<h2>Risco 5: perda de seed phrase — perda permanente de acesso</h2>

<p>Carteiras não-custodiais (MetaMask, Trust Wallet, hardware wallets) operam sob princípio de auto-custódia: o usuário é único responsável pela chave privada e pela frase de recuperação. Perder a seed phrase significa perder o ativo permanentemente, sem possibilidade de recuperação.</p>

<p>Estima-se que 20% de todos os Bitcoins minerados estão perdidos para sempre por falha de backup — equivalente a aproximadamente 4 milhões de BTC inacessíveis. Os principais cenários de perda:</p>

<ul>
  <li><strong>HD do computador queimado</strong> sem backup da seed phrase;</li>
  <li><strong>Anotação em papel</strong> perdida em mudança de residência;</li>
  <li><strong>Senha de wallet esquecida</strong> sem backup da seed;</li>
  <li><strong>Hardware wallet danificado</strong> sem seed phrase guardada separadamente;</li>
  <li><strong>Falecimento do titular</strong> sem repassar a seed a herdeiros.</li>
</ul>

<p><strong>Como proteger:</strong> ao criar qualquer wallet pessoal, anotar a seed phrase em papel ou metal (Cryptosteel, Billfodl) <strong>imediatamente</strong>. Guardar em 2 locais físicos seguros e diferentes (casa + cofre, por exemplo). Nunca digitar em arquivo digital, nuvem ou e-mail. Considerar arranjo sucessório formal para repassar acesso em caso de falecimento.</p>

<h2>Resumo do ranking de risco</h2>

<p>Os 5 riscos por ordem de probabilidade de impacto no investidor pessoa física brasileira em 2026:</p>

<ol>
  <li><strong>Volatilidade:</strong> probabilidade muito alta (parte estrutural do ativo);</li>
  <li><strong>Phishing/golpes de "suporte":</strong> probabilidade alta. Aumento contínuo nos últimos 3 anos;</li>
  <li><strong>Esquemas Ponzi disfarçados:</strong> probabilidade alta entre quem busca "ganhos passivos" em cripto;</li>
  <li><strong>Falência de exchange:</strong> probabilidade média-baixa em exchanges brasileiras reguladas; alta em estrangeiras sem proteção legal local;</li>
  <li><strong>Perda de seed phrase:</strong> probabilidade média entre usuários de wallets pessoais sem backup adequado.</li>
</ol>

<h2>A regra prática de proteção em 4 passos</h2>

<p>Cobre 80% dos riscos para quem opera valores até R$ 100 mil em cripto:</p>

<ol>
  <li><strong>Usar apenas exchange brasileira regulamentada</strong> com CNPJ ativo e mais de 5 anos de operação;</li>
  <li><strong>Ativar 2FA em todas as contas</strong> via Google Authenticator ou Authy, nunca por SMS (vulnerável a SIM swap);</li>
  <li><strong>Migrar saldos acima de R$ 50 mil para hardware wallet</strong> (Ledger, Trezor) comprado direto do fabricante oficial;</li>
  <li><strong>Backup da seed phrase em papel/metal em 2 locais físicos distintos</strong>, nunca em arquivo digital.</li>
</ol>

<p>Para quem segue os 4 passos, os riscos remanescentes ficam principalmente nas oscilações de preço — fato estrutural do ativo, não falha de segurança.</p>

<details class='faq-discover'>
<summary><strong>O Bitcoin pode ir a zero?</strong></summary>
<p>Tecnicamente sim, como qualquer ativo financeiro. Na prática, requereria perda total de demanda global, fechamento generalizado de exchanges e dissolução da rede de mineradores. Probabilidade extremamente baixa em curto e médio prazo, mas não zero. Por isso, a regra de alocar apenas valor que se possa perder integralmente segue valendo.</p>
</details>

<details class='faq-discover'>
<summary><strong>Como saber se uma empresa de cripto é autorizada no Brasil?</strong></summary>
<p>Acessar o site da Comissão de Valores Mobiliários (gov.br/cvm) e consultar a lista de empresas autorizadas e a lista de não autorizadas. Adicionalmente, conferir CNPJ ativo na Receita Federal, tempo de operação no Brasil e Histórico de incidentes. As 5 principais exchanges nacionais (Mercado Bitcoin, Binance Brasil, NovaDAX, Foxbit, BitPreço) operam sob Lei 14.478/2022.</p>
</details>

<details class='faq-discover'>
<summary><strong>Caí em golpe de cripto, o que fazer?</strong></summary>
<p>Cinco passos imediatos: (1) registrar Boletim de Ocorrência em delegacia de crimes cibernéticos; (2) reportar à Comissão de Valores Mobiliários (CVM) via canal oficial; (3) notificar à exchange envolvida (pode bloquear conta do golpista); (4) preservar evidências (prints de conversas, e-mails, comprovantes); (5) procurar advogado especializado para ação cível de reparação. Recuperação total é rara, mas o reporte ajuda na investigação coletiva.</p>
</details>

<details class='faq-discover'>
<summary><strong>O 2FA por SMS protege a conta?</strong></summary>
<p>Apenas parcialmente. 2FA por SMS é vulnerável a ataques de SIM swap, em que o golpista convence a operadora a transferir o número para um chip controlado por ele. A partir daí recebe os códigos SMS. Para conta de cripto, a recomendação é usar Google Authenticator, Authy ou chave de segurança física (YubiKey) — métodos imunes a SIM swap.</p>
</details>

<details class='faq-discover'>
<summary><strong>Vale a pena ter seguro para criptomoedas?</strong></summary>
<p>Mercado em desenvolvimento. Algumas exchanges (Coinbase, Gemini) oferecem seguro próprio para saldos custodiados. No Brasil, ainda não há produto consolidado de seguro de cripto para pessoa física. Para grandes valores, a melhor "apólice" disponível é hardware wallet em local seguro + backup da seed phrase em metal anti-incêndio em local separado.</p>
</details>

<p><em>Atualizado em 14 de maio de 2026. Conteúdo educacional. Não constitui recomendação de investimento. Em caso de dúvida sobre legalidade de plataforma, consultar diretamente o site da Comissão de Valores Mobiliários (gov.br/cvm).</em></p>
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

foreach ([$p7, $p8] as $info) {
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
    $tagIds = $wp->resolverTags(['Criptomoedas', 'Bitcoin', 'Nubank', 'Mercado Bitcoin', 'Segurança Digital', 'CVM', 'Phishing', 'Hardware Wallet']);

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

echo "\n══════ FIM BATCH C ══════\n";
