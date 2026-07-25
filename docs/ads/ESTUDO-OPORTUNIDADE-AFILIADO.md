# Estudo de oportunidade — 50 termos "melhores X" · comocomprar

Levantado em 24-25/07/2026. Dados em `data/_cc_serp_oportunidade.json` (1ª página real dos 50 termos) e `data/_cc_autocomplete_cluster.json` (281 sugestões de autocomplete das sementes prioritárias). Scripts: `scripts/_cc_serp_oportunidade.php` e `scripts/_cc_autocomplete_cluster.php`.

## O que foi medido, e o que não foi

**Medido:** composição real da 1ª página de cada um dos 50 termos (quantos resultados são marketplace, mídia gigante, site de nicho ou fórum) e o autocomplete do Google das sementes que sobreviveram ao filtro.

**Não medido, e é honesto dizer:** volume de busca e CPC não vieram do Planejador — a interface do Ads estava instável e o Serper deste plano não devolve volume. Também **não** consegui medir presença de AI Overview: o plano do Serper retorna apenas `organic`, sem os blocos de AI Overview, People Also Ask e related searches. O autocomplete supriu a falta de PAA como fonte de pauta.

## A descoberta que muda a leitura

**Quase nenhum desses termos tem marketplace na 1ª página.** Em 50 termos, Amazon/Mercado Livre/Magalu aparecem no top 10 em apenas 12, e quase sempre com 1 ou 2 posições. Quem ocupa é **site de nicho e fórum**.

Isso é ótimo e contraintuitivo: são SERPs disputáveis por conteúdo editorial. O gargalo não é a concorrência — **é a comissão**. Volume alto com comissão de 2% é trabalho de graça.

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

## Próximo passo sugerido

Começar por **creatina** (maior cluster, 13%, SERP de nicho) e **ração pet** (dois hubs, recorrência, 11%), nesta ordem. Um hub cada, com 4-6 satélites saídos das sugestões acima, seguindo `reference_hub_autoridade_padrao`. As LPs pagas desses nichos só depois que o orgânico mostrar quais células convertem — assim a verba entra onde já há sinal.
