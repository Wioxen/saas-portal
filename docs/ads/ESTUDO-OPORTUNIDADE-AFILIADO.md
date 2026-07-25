# Estudo de oportunidade — 50 termos "melhores X" · comocomprar

Levantado em 24-25/07/2026. Dados em `data/_cc_serp_oportunidade.json` (1ª página real dos 50 termos) e `data/_cc_autocomplete_cluster.json` (281 sugestões de autocomplete das sementes prioritárias). Scripts: `scripts/_cc_serp_oportunidade.php` e `scripts/_cc_autocomplete_cluster.php`.

## O que foi medido, e o que não foi

**Medido:** composição real da 1ª página dos 50 termos (Serper), autocomplete das sementes prioritárias (281 sugestões) e — na segunda rodada — **volume, variação anual, concorrência e faixa de lance de topo direto do Planejador de Palavras-chave** (plano `1428279237`, Brasil, jul/2025–jun/2026, CSV em `Downloads/Keyword Stats 2026-07-25 at 00_07_32.csv`).

**Não medido:** presença de AI Overview. O plano do Serper retorna apenas `organic`, sem os blocos de AI Overview, People Also Ask e related searches. O autocomplete supriu a falta de PAA como fonte de pauta.

> ⚠️ **Armadilha do CSV do Planejador, para quem for repetir:** ele mistura dois formatos numéricos na mesma linha. Volume vem em formato americano e sem aspas (`12100.0`, ponto decimal); CPC vem em formato brasileiro e entre aspas (`"0,16"`, vírgula decimal). Tratar os dois igual infla o volume em 10×. `scripts/_cc_merge_planner_serp.php` tem uma função para cada caso e um comentário explicando.

## A descoberta que muda a leitura

**Quase nenhum desses termos tem marketplace na 1ª página.** Em 50 termos, Amazon/Mercado Livre/Magalu aparecem no top 10 em apenas 12, e quase sempre com 1 ou 2 posições. Quem ocupa é **site de nicho e fórum**.

Isso é ótimo e contraintuitivo: são SERPs disputáveis por conteúdo editorial. O gargalo não é a concorrência — **é a comissão**. Volume alto com comissão de 2% é trabalho de graça.

## 🔁 Revisão com os dados do Planejador — o que mudou

A primeira versão deste estudo ordenou os nichos só pela liberdade da SERP e pela comissão. Com volume real na mão, **três conclusões mudaram**. Índice abaixo = volume × comissão (comparativo, não R$).

| # | Termo | Vol/mês | YoY | CPC topo | Com. | Índice | SERP n/m/mk |
|---|---|---:|---:|---|---:|---:|---|
| 1 | melhores creatinas | 14.800 | −45% | R$ 0,14–1,30 | 13% | **1924** | 6/1/0 |
| 2 | melhores perfumes femininos | 12.100 | −33% | R$ 0,17–1,19 | 13% | **1573** | 6/1/0 |
| 3 | melhores creatinas do mercado | 8.100 | −19% | R$ 0,15–1,23 | 13% | **1053** | 4/1/0 |
| 4 | melhores marcas de notebook | 12.100 | −18% | R$ 0,16–1,53 | 3% | 363 | 3/2/0 |
| 5 | melhores fones bluetooth | 9.900 | 0% | R$ 0,15–0,59 | 3% | 297 | 3/3/2 |
| 8 | melhores hidratantes faciais | 1.600 | **+50%** | R$ 0,32–2,90 | 13% | 208 | **8/0/0** |
| 12 | melhores rações para gato | 1.600 | −16% | R$ 0,08–1,21 | 11% | 176 | 5/0/0 |
| 13 | melhores oleos capilares | 1.300 | 0% | R$ 0,14–0,92 | 13% | 169 | 4/0/1 |

**Correção 1 — perfume feminino sobe para o 2º lugar.** Eu havia colocado em "segunda onda". São 12.100/mês a 13% com CPC baixo. Ressalva real: o cc já tem cluster de perfume árabe; o recorte feminino precisa nascer como silo próprio para não canibalizar.

**Correção 2 — ração pet cai da 2ª posição.** Minha empolgação vinha da SERP livre (5-9 de 10 são nicho, zero mídia), não da demanda. Os números: `rações para gato` 1.600, `rações cachorros` 720, `rações para cães` 110. E as quatro variações de cauda que você listou — super premium, porte grande, custo-benefício cão e gato — deram **volume 0 no Planejador**. Continua sendo nicho bom (recompra, comissão 11%, página livre), mas não é o segundo lugar.

**Correção 3 — kimono e quadro de bike saem da lista dos cinco.** 110 e 140 buscas/mês. SERP livre não compensa demanda que não existe.

### Três leituras que só o CPC revelou

- **Jogos de console têm CPC de fundo de poço** — PS4 R$0,04–0,73, Xbox 360 R$0,01–1,08. Anunciante não paga por esse tráfego. É a confirmação numérica de que não há intenção de compra ali, apesar dos 66.000/mês.
- **Ketchup tem o CPC mais caro de toda a lista: R$ 2,55–5,92.** Alguém disputa forte (provável marca). Mas 5% sobre um ketchup não paga um clique de R$3. Volume e CPC alto não significam oportunidade de afiliado.
- **Hidratante facial paga até R$ 2,90** com apenas 1.600 buscas — sinal de intenção comercial densa. Somado ao +50% YoY e à SERP mais livre de todo o levantamento (8/0/0, zero mídia), é a melhor aposta de **trajetória**, ainda que não de volume atual.

⚠️ **Atenção ao YoY negativo dos dois primeiros** (creatina −45%, perfume feminino −33%). Os termos-semente estão encolhendo — provavelmente porque a busca se fragmenta em cauda longa (o autocomplete de creatina tem 54 variações). Isso reforça a estratégia de **hub + cluster** em vez de apostar tudo no termo-cabeça.

## Veredito por bloco

### 🟢 FAZER — comissão alta + 1ª página de nicho

| Termo | 1ª página (mkt/mídia/nicho/fórum) | Comissão |
|---|---|---|
| melhores hidratantes faciais | 0 / **0** / **8** / 1 | 13% |
| melhores kits de shampoo e condicionador | 0 / **0** / 7 / 2 | 13% |
| melhores creatinas | 0 / 1 / 6 / 1 | 13% |
| melhores oleos capilares (+cacheados) | 1 / **0** / 4 / 3 | 13% |
| melhores rações para cães | 0 / **0** / **9** / 1 | 11% |
| melhores rações para cachorro porte grande | 0 / **0** / **9** / 1 | 11% |
| melhores rações custo benefício para cachorro | 0 / **0** / 8 / 2 | 11% |
| melhores rações super premium para cães | 0 / **0** / 7 / 3 | 11% |
| melhores rações para gato filhote | 0 / 1 / 7 / 1 | 11% |
| melhores racoes cachorros / para gato | 0 / **0** / 5-6 / 2-3 | 11% |
| melhores kimonos de jiu jitsu | 0 / **0** / **8** / 2 | 8% |
| melhores quadros de bike aro 29 | 0 / **0** / 6 / 4 | 8% |

Zero mídia gigante em quase todos. São os alvos.

### 🟡 SEGUNDA ONDA — comissão boa, disputa maior

`melhores perfumes femininos` (13%, nicho 6) — bom, mas a SERP é de marca e o cc já tem o cluster de perfume árabe rodando; risco de canibalizar. `melhores geladeiras 2026` e `custo benefício` (8%, mas mídia 3-4 e ticket alto com frete complicado).

### 🔴 NÃO FAZER PARA AFILIADO — a armadilha do volume

Celulares, iPhones, notebooks (4 variações), smartwatch (7 variações), fones bluetooth, headsets, jogos de PS1/PS2/PS3/PS4/Switch/Xbox 360.

Dois problemas somados: **comissão Amazon de 2-3%** e **mídia gigante ocupando a 1ª página** (TechTudo, Canaltech, Zoom, Buscapé). Em `melhores smartwatch`, 6 dos 10 são mídia. Um notebook de R$ 3.000 a 3% rende R$ 90, e você disputa com redação que tem equipe e domínio de 20 anos. É o pior par possível: difícil de ranquear e mal pago quando ranqueia.

⚠️ Os jogos retrô (PS1/PS2/PS3/Xbox 360) têm um problema extra: **não há o que vender novo na Amazon**. Tráfego de nostalgia, zero intenção de compra afiliada.

### ⚫ FORA — SERP fácil, mas não há produto

Os **10 termos de queijo** e `melhores ketchup`. A 1ª página é de nicho e daria para ranquear, mas não existe venda de afiliado relevante em queijo e ketchup na Amazon. Se o objetivo é receita, é distração. (Fariam sentido só num projeto de conteúdo monetizado por AdSense, que não é o caso — o cc foi reprovado por conteúdo de baixo valor e não deve gerar volume sem venda.)

## A regra: HUB/CLUSTER/REVIEW vs LP BoFu

Não é escolha, é **função da intenção** — e os dois convivem no mesmo nicho:

**HUB + CLUSTER (orgânico, grátis, escala)** para consulta comparativa: `melhores X`, `melhor X para Y`, `X custo benefício`. Quem digita isso ainda está escolhendo. O formato que ganha é comparativo com tabela, critério explícito e recomendação por perfil. É também o formato que o AI Overview cita, porque entrega dado estruturado e resposta direta.

**LP BoFu Amazon-only (pago, Search)** para intenção de decisão já tomada: `comprar X`, `X marca Z`, `X vale a pena`. É o modelo já validado nas 3 LPs (#18312/#18316/#18317): sem preço, botão direto `dp/ASIN?tag=`, conversão `AW-16696952717`.

O autocomplete mostrou que **as duas intenções existem em todos os nichos bons** — `creatina vale a pena`, `qual creatina comprar`, `quadro de bike aro 29 vale a pena comprar`. Ou seja: hub captura orgânico, LP captura pago, e o hub ainda alimenta a LP com link interno.

## Os 5 clusters recomendados, com pauta pronta

Todas as pautas abaixo saíram do autocomplete real — são consultas que gente digita, não invenção.

### 1. CREATINA — o mais rico (54 sugestões) · 13%
HUB: "melhores creatinas 2026". Cluster por: **creapure/selo**, **custo-benefício**, **pura vs monohidratada**, **em goma**, e por público: **mulher**, **iniciantes**, **idosos**, **adolescentes**, **hipertrofia**, **emagrecer**. BoFu: `creatina vale a pena`, `qual creatina comprar`, marcas (Growth, Dux, Soldiers).

### 2. RAÇÃO PET — dois hubs, cão e gato (64 sugestões) · 11%
Segmentação natural e limpa por **fase** (filhote, adulto, idoso/sênior), **porte** (pequeno, grande), **condição** (castrado, diabetes, obeso, FeLV) e **custo-benefício**. É o cluster de maior número de células legítimas sem canibalizar. Ticket recorrente — o cliente volta.

### 3. HIDRATANTE FACIAL (36 sugestões) · 13%
Segmentação por **tipo de pele**: oleosa, seca, mista, acneica, madura, sensível. Mais **gestante**, **adolescente** e **coreano** (K-beauty tem demanda própria). A 1ª página é 8/10 de nicho e **zero mídia** — é o SERP mais livre de todo o levantamento.

### 4. ÓLEO CAPILAR + KIT SHAMPOO (70 sugestões juntos) · 13%
Segmentação por **tipo de cabelo**: cacheado, crespo, ondulado, liso, fino, danificado, seco, com progressiva. Mais **função**: crescimento, queda, umectação, frizz, finalizador. Os dois se cruzam e sustentam um hub de "rotina capilar" com silo forte.

### 5. KIMONO JIU-JITSU (32 sugestões) · 8%
Nicho pequeno, mas **8/10 de nicho e zero mídia**. Segmenta por **infantil / feminino / masculino / iniciantes / custo-benefício** e por marca. Público apaixonado, compra recorrente (kimono desgasta).

> **Quadro de bike aro 29** fica como candidato: SERP livre, mas a maioria das sugestões é sobre **tamanho de quadro** (15/17/19/21) — intenção técnica, não de compra imediata. Vale como cluster de autoridade, não como primeira aposta.

## ⚠️ Achado que vale para todas as campanhas pagas

O autocomplete está **cheio de intenção norte-americana**: "nos eua", "estados unidos", "walmart", "shampoo comprar eua", "melhor creatina dos eua", "hidratante facial nos estados unidos". São brasileiros pesquisando o que comprar em viagem — **não convertem em Amazon BR**.

Vira negativa obrigatória em qualquer campanha desses nichos: `eua`, `estados unidos`, `walmart`, `nos eua`, `usa`. E também `reddit` (aparece em creatina e ração), que é intenção de fórum.

## Sobre a lista de produtos do GPT

Câmera Wi-Fi 360°, mini impressora térmica, aspirador portátil, bebedouro pet e fechadura digital são **produto**, não consulta — jogam no outro tabuleiro. São candidatos a **LP BoFu paga**, no molde das 3 já criadas, não a hub orgânico. Antes de construir qualquer uma, passar pelo filtro que este estudo usou: comissão da categoria × quem ocupa a 1ª página. Bebedouro pet herda os 11% de pet e o SERP livre do nicho — é o mais promissor da lista. Fechadura digital tem ticket alto mas é compra de confiança, com ciclo longo.

## Próximo passo sugerido (revisado com o Planejador)

Ordem final, agora com demanda medida:

1. **Creatina** — 14.800 + 8.100 no termo irmão, 13%, SERP de nicho, 54 variações de autocomplete. É o único que combina volume, comissão e cauda longa rica.
2. **Perfume feminino** — 12.100 a 13%, CPC baixo. Nascer como silo separado do perfume árabe.
3. **Hidratante facial** — volume modesto (1.600) mas +50% ao ano, CPC até R$2,90 e a SERP mais livre de todas. Aposta de trajetória.
4. **Óleo capilar** (1.300, e "para cacheados" cresce +200%) — junta com kit shampoo no mesmo hub de rotina capilar.
5. **Ração pet** — mantém, por recompra e página livre, mas sem a pressa que eu havia sugerido.

Um hub cada, 4-6 satélites saídos do autocomplete, seguindo `reference_hub_autoridade_padrao`. As LPs pagas desses nichos só depois que o orgânico mostrar qual célula converte — assim a verba entra onde já existe sinal.
