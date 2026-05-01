# Pé-de-Meia 2026 — Deploy do funil cross-domain (xegold → vafast)

Funil de arbitragem espelhado do anterior, agora começando no xegold (publisher 20693) e terminando no vafast (publisher 20438). UX desktop reforçada (container 1180px, hero em 2 colunas, stats grid 4×, sidebar 320px).

## 🔄 Fluxo completo

```
Google Ads (CPC R$ 0,07-0,19 · CTR 10-66%)
  ↓
xegold.xyz/consulta-pe-de-meia/         (página 1 — CONSULTA pelo CPF)
  ↓
xegold.xyz/calendario-pe-de-meia-2026/  (página 2 — DATAS + VALORES)
  ↓
vafast.xyz/quem-tem-direito-pe-de-meia/ (página 3 — CRITÉRIOS + CadÚnico)
  ↓
vafast.xyz/nao-recebi-pe-de-meia-o-que-fazer/ (página 4 — FIM, sem CTA externo)
```

**Total:** 4 pageviews × 6 slots = **24 impressões JoinAds por sessão**, divididas entre publisher 20693 (2 págs) e 20438 (2 págs). Sem ponto de saída na p4 — dwell time é a moeda final (~180s alvo).

## 🎯 Mapeamento das keywords vencedoras

Baseado no sheet de search terms da campanha:

| Termo (CTR) | Página alvo |
|---|---|
| "consulta pé de meia pelo cpf" (30%) | p1 xegold/consulta-pe-de-meia |
| "consulta pe de meia 2025" (18%) | p1 xegold/consulta-pe-de-meia |
| "site do mec pé de meia" (66%) | p1 xegold (link gov.br/mec destacado) |
| "jornada dos estudantes" (60%) | p1 xegold (app destacado em método 1) |
| "pé de meia como consultar 2025" | p1 xegold |
| "por que eu não recebi pé de meia" (50%) | p4 vafast/nao-recebi-pe-de-meia-o-que-fazer |
| "como arrumar o pé de meia" (50%) | p4 vafast (motivos + soluções) |
| "jovem estudante pe de meia" | p3 vafast/quem-tem-direito-pe-de-meia |

URLs finais do Google Ads sugeridas: **p1 xegold** pra todos os grupos de keyword. Funil natural conduz pra p4.

## 📁 Arquivos prontos

```
lp/xegold/
├── consulta-pe-de-meia/
│   └── index.php                       (~28 KB · publisher 20693 · GA4 G-1XKCG60DMD)
└── calendario-pe-de-meia-2026/
    └── index.php                       (~30 KB · publisher 20693 · GA4 G-1XKCG60DMD)

lp/vafast/
├── quem-tem-direito-pe-de-meia/
│   └── index.php                       (~28 KB · publisher 20438 · GA4 G-Q25F19JPDZ)
└── nao-recebi-pe-de-meia-o-que-fazer/
    └── index.php                       (~32 KB · publisher 20438 · GA4 G-Q25F19JPDZ)
```

## 🎨 UX desktop nova (vs LPs anteriores)

| Item | Antes | Agora |
|---|---|---|
| Container max-width | 760px | **1180px** |
| Hero | 1 coluna | **2 colunas (texto + stat-cards)** |
| Stats card | nenhum | **Grid 2×2 / 4×1 com números grandes** |
| Tipografia H1 desktop | 1.8rem | **2.4-2.95rem** (escala progressiva 760→1000px) |
| Sidebar | 300px / 600px slot | **320px / 600px slot, sticky** |
| Hero CTA | igual ao final | **botão amarelo dedicado #FFC107 ou #FF9900** |
| Header | estático | **sticky top:0 z-index:90** |
| Background section | branco puro | **alternado #f5f7f4 (xegold) / #f5f7fa (vafast)** |
| Cards interativos | hover básico | **transform translateX + box-shadow + border destacado** |
| Timeline (p2) | tabela simples | **timeline visual com badges PRÓXIMO + past/active states** |

Mobile permaneceu igual (já estava bom): container fluído, sticky anchor com botão fechar, stats grid 2×2.

## 🚀 Deploy (5 passos)

### 1. Subir os 4 arquivos

```
xegold.xyz/consulta-pe-de-meia/index.php
xegold.xyz/calendario-pe-de-meia-2026/index.php
vafast.xyz/quem-tem-direito-pe-de-meia/index.php
vafast.xyz/nao-recebi-pe-de-meia-o-que-fazer/index.php
```

### 2. Slots de anúncio — separação total entre p1 (AdSense puro) e p2 (JoinAds puro)

**Decisão arquitetural (2026-04-29 final):** misturar AdSense + JoinAds na mesma página gera conflito de scripts (testado em `cursos-arbitragem/index.php` — só anchor JoinAds renderizou, in-feed AdSense não apareceu). Solução: **um network por página, sem mistura**, e **anchor removido em todas as páginas** (era a fonte do conflito).

**Páginas AdSense puro (p1 de cada funil — 4 arquivos):**

| Arquivo | Network | Slots |
|---|---|---|
| `lp/xegold/consulta-pe-de-meia/index.php` | AdSense `ca-pub-8993730121896376` slot `6852121130` (in-article) | 4 Content + 1 Sidebar = **5 unidades AdSense** |
| `lp/xegold/top-plataformas-ead/index.php` | AdSense `ca-pub-8993730121896376` slot `6852121130` (in-article) | 4 Content + 1 Sidebar = **5 unidades AdSense** |
| `lp/vafast/quem-tem-direito-pe-de-meia/index.php` | AdSense `ca-pub-1690973013586490` slot `7773118277` (in-feed layout-key `-6t+ed+2i-1n-4w`) | 4 Content + 1 Sidebar = **5 unidades AdSense** |
| `lp/vafast/cursos-arbitragem/index.php` | AdSense `ca-pub-1690973013586490` slot `7773118277` (in-feed) | 4 Content + 1 Sidebar = **5 unidades AdSense** |

→ Sem JoinAds (script removido, blocos removidos, preconnects removidos). 100% AdSense.

**Páginas JoinAds puro (p2 de cada funil — 4 arquivos):**

| Arquivo | Network | Slots |
|---|---|---|
| `lp/xegold/calendario-pe-de-meia-2026/index.php` | JoinAds publisher `20693` | 4 Content + 1 Sidebar = **5 unidades JoinAds** |
| `lp/xegold/cursos-senac-gratuitos-2026/index.php` | JoinAds publisher `20693` | 4 Content + 1 Sidebar = **5 unidades JoinAds** |
| `lp/vafast/nao-recebi-pe-de-meia-o-que-fazer/index.php` | JoinAds publisher `20438` | 4 Content + 1 Sidebar = **5 unidades JoinAds** |
| `lp/vafast/cursos-arbitragem/2.php` | JoinAds publisher `20438` | 4 Content + 1 Sidebar = **5 unidades JoinAds** |

→ Sem AdSense. 100% JoinAds.

**Single pages (mantém 100% JoinAds publisher 20438):**
- `lp/vafast/senai/index.php` — 4 Content + 1 Sidebar
- `lp/vafast/bradesco/index.php` — 4 Content + 1 Sidebar

**Anchor removido de TODAS as 10 páginas** (era a fonte do conflito + UX intrusiva). CSS limpo: sem `padding-bottom:80px` no body, sem `margin-bottom:80px` no footer, sem `.sticky-anchor` rules.

**Resultado de receita:** cada sessão completa do funil pé-de-meia (4 págs) gera **20 unidades de anúncio** (5 AdSense + 5 JoinAds + 5 AdSense + 5 JoinAds) + interstitial de cada network (4 interstitials no total).

### 3. Ajustar campanha Google Ads

- **URL final dos anúncios:** `https://xegold.xyz/consulta-pe-de-meia/`
- Manter os top termos do sheet (CTR > 10%) como exact match
- Negativar genéricas que não bateram (qualquer search term abaixo de 5% CTR e 0 conv)

### 4. Testar (5 min, aba anônima mobile + desktop)

- [ ] p1 xegold carrega < 2s
- [ ] Hero desktop em 2 colunas (texto à esquerda, stat-cards à direita)
- [ ] Sticky CTA (mobile) e botão amarelo aparece no hero
- [ ] CTA "Ver Calendário 2026 →" leva pra p2 com gtag conversion disparado
- [ ] p2 xegold timeline visual (badges, dates) renderiza corretamente
- [ ] p3 vafast cores azul/amarelo + 5 cards de critérios em 2 colunas (desktop)
- [ ] p4 vafast carrega sem CTA externo (objetivo: dwell time)
- [ ] Slots JoinAds 1-6 aparecem em cada página
- [ ] Google Ads conversion `AW-16675521270/zjjrCI3gt8sZEPaFwY8-` dispara nas 3 transições (p1→p2, p2→p3, p3→p4)

### 5. Monitorar 7 dias

| Métrica | Meta |
|---|---|
| Pageviews/sessão | **3-4** (vs 1-2 antes) |
| Tempo p4 | **>2 min** (eventos `pdm_p4_time_120s` aparecendo) |
| Bounce p1 | **<35%** |
| RPM JoinAds | **+50-80% vs baseline** |
| CPC médio | **manter R$ 0,15-0,20** |

## ⚙️ Configurações hardcoded — confirmadas

| Item | Valor |
|---|---|
| Conversion ID (compartilhado) | `AW-16675521270/zjjrCI3gt8sZEPaFwY8-` |
| GA4 xegold | `G-1XKCG60DMD` |
| GA4 vafast | `G-Q25F19JPDZ` |
| AdSense xegold (in-article p1) | `ca-pub-8993730121896376` slot `6852121130` (layout `in-article`) |
| AdSense vafast (in-feed p1) | `ca-pub-1690973013586490` slot `7773118277` (layout-key `-6t+ed+2i-1n-4w`) |
| JoinAds xegold (sidebar+anchor p1, full p2) | publisher `20693` |
| JoinAds vafast (sidebar+anchor p1, full p2) | publisher `20438` |
| Página final (p4) | sem CTA externo, max dwell time |
| Cores xegold | Verde `#3d9b34` + dourado `#FFC107` (CTA) |
| Cores vafast | Azul `#0F4C81` + laranja `#FF9900` (CTA) |
| Imagens | Gradientes CSS (sem imagem externa) |

## 🔥 Pronto. Pode subir.

Mesmo padrão técnico do funil anterior + UX desktop reforçada. CPC do Pé-de-Meia (R$ 0,07-0,19) é 4× mais barato que cursos (R$ 0,30-0,80) — economics esperado é muito superior.
