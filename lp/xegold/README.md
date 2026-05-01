# Xegold — Páginas de monetização (final do funil)

LPs standalone que recebem o tráfego do vafast e fecham o funil dentro do domínio xegold.xyz, **sem mais saída pra clickez**. Tudo monetizado via JoinAds (publisher 20693) + AdSense ativo do xegold.

## 🔄 Fluxo completo (atualizado · sem clickez)

```
Google Ads (CPC R$ 0,30-0,80)
  ↓
vafast.xyz/cursos-arbitragem/         (Página 1 — intro)
  ↓
vafast.xyz/cursos-arbitragem/2.php    (Página 2 — listas Senac+Senai+Sebrae)
  ↓
xegold.xyz/top-plataformas-ead/       ⭐ Página 3 — Top 10 ranking
  ↓
xegold.xyz/cursos-senac-gratuitos-2026/  ⭐ Página 4 — final do funil
  ↓
(fim — sem CTA externo, dwell time alto)
```

**Total:** 4 pageviews × 6 slots = **24 impressões JoinAds por sessão**. Sem dependência de clickez. 100% receita no domínio xegold.

## 🎯 Estratégia da página final

A `cursos-senac-gratuitos-2026/` é a 4ª (e última) etapa. Sem CTA externo proposital — o objetivo é **maximizar tempo na página**:

- 7 minutos de leitura
- 8 itens FAQ que prendem atenção
- 6 áreas detalhadas com listas de cursos
- 16 estados listados na grid
- Eventos GA4 a cada 30/60/120/**180s**
- Sem ponto de saída → user fica até o anchor + sidebar terminarem o ciclo de slots

## 📁 Arquivos

```
lp/xegold/
├── top-plataformas-ead/
│   └── index.php   (~14 KB · 6 slots · CTA → cursos-senac-gratuitos-2026)
└── cursos-senac-gratuitos-2026/
    └── index.php   (~22 KB · 6 slots · sem CTA externo)
```

## 🎯 Diferenças do xegold vs vafast (importante)

| Item | vafast | xegold |
|---|---|---|
| JoinAds publisher | **20438** | **20693** ⚠️ |
| GA4 principal | G-D04KPSC2ZZ | **G-1XKCG60DMD** |
| Google Ads conversion | AW-16675521270/zjjrCI3gt8sZEPaFwY8- | **mesmo (compartilhado)** |
| Cor da marca | Azul `#0F4C81` | **Verde `#3d9b34`** (do spinner JoinAds) |
| AdSense | Não detectado | **ca-pub-8993730121896376** ATIVO ✅ |
| CTA externo final | xegold.xyz/top-plataformas-ead | **(sem) — fim do funil dentro do domínio** |

## 🚀 Deploy (3 passos)

### 1. Subir os arquivos
- `lp/xegold/top-plataformas-ead/index.php` → `xegold.xyz/top-plataformas-ead/index.php`
- `lp/xegold/cursos-senac-gratuitos-2026/index.php` → `xegold.xyz/cursos-senac-gratuitos-2026/index.php`

### 2. Configurar slots no JoinAds (publisher 20693)
- `Content1`, `Content2`, `Content3`, `Content4`, `Sidebar`, `Anchor` (mesmos nomes do vafast, painel JoinAds do publisher 20693)

### 3. Garantir que vafast/2.php aponta pra xegold/top-plataformas-ead
Já feito no código (`$external_cta = 'https://xegold.xyz/top-plataformas-ead/'` em `lp/vafast/cursos-arbitragem/2.php`). Confirmar no servidor após deploy.

## 🎯 AdSense ativo no xegold (oportunidade futura)

`ca-pub-8993730121896376` está ativo no WP do xegold. As novas páginas **não incluem AdSense por padrão** pra não competir com JoinAds. Se quiser ativar Auto Ads depois, basta colar no `<head>`:

```html
<script async src='https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-8993730121896376' crossorigin='anonymous'></script>
```

Recomendado A/B test antes — JoinAds + AdSense simultâneo pode reduzir CPM de cada um por competição de slot.

## 📊 Métricas-meta

| Métrica | Esperado |
|---|---|
| Pageviews/sessão | **4** (era 2 antes) |
| Impressões JoinAds/sessão | **~24** (4 págs × 6 slots) |
| Tempo na página final | **>3 min** (objetivo: chegar no evento `senac_time_180s`) |
| Bounce na pág 4 | **<25%** (já passou por 3 págs antes) |
| Receita JoinAds/sessão | **+50-80%** vs fluxo anterior (4 pgvw vs 2) |

## ⚙️ Configurações hardcoded — confirmadas

| Item | Valor |
|---|---|
| Conversion ID Google Ads | `AW-16675521270/zjjrCI3gt8sZEPaFwY8-` |
| GA4 | `G-1XKCG60DMD` (xegold) |
| JoinAds publisher | `20693` (xegold) |
| Página 4 — sem CTA externo | dwell time é a moeda |
| Soft link informativo | `ead.senac.br` (rel='nofollow', sem conversion fire) |
| Cor da marca | Verde `#3d9b34` |
| Imagens | Gradiente CSS (sem imagem externa) |

## 🔥 Tudo pronto. Pode subir.

Mesmo padrão técnico das LPs do vafast (HTML standalone, 6 slots, anchor sticky mobile, progress bar, scroll/tempo events) — só com publisher JoinAds, GA4 e cores próprias do xegold, e sem ponto de saída na pág 4.
