# DEPLOY — Checklist final pra subir pro servidor

**Status:** ✅ Tudo pronto. Funil de 4 páginas (2 vafast + 2 xegold), sem clickez. Receita 100% no próprio domínio (JoinAds + AdSense xegold).

## 📁 Arquivos prontos pra deploy

```
lp/vafast/
├── cursos-arbitragem/         (Senac+Senai+Sebrae — funil 2 páginas)
│   ├── index.php              (página 1: intro + por quê)
│   └── 2.php                  (página 2: lista completa + CTA xegold)
├── senai/
│   └── index.php              (LP única SENAI vermelho industrial)
├── bradesco/
│   └── index.php              (LP única Fundação Bradesco azul/vermelho)
├── README.md                  (não subir)
├── DEPLOY-CHECKLIST.md        (não subir — esse arquivo)
└── cursos-arbitragem/README.md (não subir)
```

**Total de páginas a subir:** 4 PHP files (~80 KB total).

## 🎯 Fluxo final do tráfego

```
Google Ads (CPC R$ 0,30-0,80)
  ↓
LP por keyword group:
  ├─ /cursos-arbitragem/    → 2 pageviews (index + 2.php) → handoff xegold
  ├─ /senai/                → 1 pageview rica + CTA externo
  └─ /bradesco/             → 1 pageview rica + CTA externo
  ↓
Receita JoinAds vafast (6 slots/pág, publisher 20438) + conversão Google Ads
  ↓
xegold.xyz/top-plataformas-ead/         (página 3 — JoinAds 20693)
  ↓
xegold.xyz/cursos-senac-gratuitos-2026/ (página 4 — fim do funil, sem CTA externo)
```

**Pageviews/sessão (multi-step):** 4 (vafast 2 + xegold 2). 24 impressões JoinAds totais por sessão completa. Sem clickez no caminho.

## 🚀 Como deployar (5 passos · 15 min)

### 1. Upload (FTP/SSH/cPanel)
- `cursos-arbitragem/index.php` + `2.php` → `vafast.xyz/cursos-arbitragem/`
- `senai/index.php` → `vafast.xyz/senai/`
- `bradesco/index.php` → `vafast.xyz/bradesco/`

> NÃO subir os `README.md` nem o `DEPLOY-CHECKLIST.md` — só doc interna.

### 2. Configurar 6 slots no painel JoinAds (publisher 20438)

Nomes EXATOS (case-sensitive):

| Slot | Tipo | Posição estratégica nas páginas |
|---|---|---|
| `Content1` | Native in-feed responsive | **Above the fold** (após hero) |
| `Content2` | Native in-article responsive | Após primeiras seções de conteúdo |
| `Content3` | Native in-article large | Meio do conteúdo, alta viewability |
| `Content4` | Native in-feed large | **Antes do CTA externo** (última impressão antes do redirect) |
| `Sidebar` | Display 300×600 ou 300×250 | Sticky lateral (desktop only via CSS) |
| `Anchor` | Sticky bottom 320×50/100 | **Sticky mobile com botão fechar** |

### 3. Atualizar campanha Google Ads
URL final dos anúncios:
- `vafast.xyz/cursos-arbitragem/` (cauda longa Senac/Senai/Sebrae)
- `vafast.xyz/senai/` (cauda longa SENAI específico)
- `vafast.xyz/bradesco/` (cauda longa Bradesco/Escola Virtual)

### 4. Testar antes de ativar (5 min, aba anônima mobile)

- [ ] `/cursos-arbitragem/` carrega < 2s · slots ad aparecem · anchor sticky com × · progress bar topo
- [ ] Clica "Ver lista de cursos →" vai pra `2.php` corretamente
- [ ] Em `2.php` clica "Acessar guia completo →" → redireciona pra `xegold.xyz/...` após ~1.2s (com gtag conversion disparado)
- [ ] `/senai/` e `/bradesco/` carregam, slots aparecem, CTAs externos funcionam
- [ ] DevTools Network: ao clicar CTA externo, request pra `googleads.g.doubleclick.net/pagead/conversion/...?label=zjjrCI3gt8sZEPaFwY8-` retorna 200

### 5. Monitorar 7 dias
| Métrica | Atual (vafast WP) | Esperado (novo) |
|---|---|---|
| LCP mobile | ~5s | **< 1,5s** |
| CLS | alto | **< 0,05** |
| Pageviews/sessão | 1-1.5 | **2 (multi-step) / 1 (single)** |
| Tempo médio | <30s | **>90s** |
| Bounce | 70-80% | **40-50%** |
| Cliques no CTA externo (xegold) | n/a | **alvo 30-50% dos visitantes da p2** |
| RPM JoinAds | baseline | **+50-80%** |

## ⚙️ Configurações hardcoded — **CONFIRMADAS** com você

| Item | Valor |
|---|---|
| Conversion ID Google Ads | `AW-16675521270/zjjrCI3gt8sZEPaFwY8-` |
| GA4 | `G-D04KPSC2ZZ` |
| JoinAds publisher | 20438 |
| CTA externo final (cursos-arbitragem) | `https://xegold.xyz/top-plataformas-ead/` (pula a WP atual) |
| CTA externo final (senai/bradesco) | `https://xegold.xyz/curso-gratuito-com-certificado-opcoes-ead-e-presencial-para-impulsionar-sua-carreira/` (mantido por enquanto) |
| Imagens hero | Gradientes CSS (sem imagem externa, LCP otimizado) |

## 🎨 Por que 2 páginas e não 4

**Decisão estratégica do user (29/abr/2026):**
> "depois de 3 paginas nao monetiza muito"

Verdade conhecida em arbitragem: ad fatigue do JoinAds derruba CPM após 3+ impressões na mesma sessão. **2 pageviews × 6 slots = 12 impressões** já é o sweet spot — passar de 3 páginas começa a ter retorno decrescente em receita publicitária e o user fica cansado de "Próxima página", o que reduz CTR no CTA externo final.

Trade-off aceito:
- ❌ Menos pageviews/sessão (2 vs 4)
- ✅ Mais cliques no CTA externo xegold (afiliado/parceiro paga melhor que JoinAds nas últimas impressões)
- ✅ User chega no destino final com energia (não exausto)
- ✅ Conversão Google Ads dispara no CTA externo (sinal forte pro Smart Bidding)

## ❓ Pendências opcionais (não bloqueiam deploy)

- Replicar template pra novas verticais (cartões, vagas, governo, benefícios sociais)
- Lazy-load slots 4-5 quando entrarem viewport (ganha mais 5-10% LCP)
- AdSense em paralelo ao JoinAds se a conta for aprovada (network ID secundário)
- A/B test entre `/cursos-arbitragem/` (multi) vs `/senai/` (single rica) pra ver qual converte mais por keyword group

## 🔥 Material pronto. Pode subir.

Sem pendências de informação. Apenas execute os 5 passos do deploy. Quando tiver dados de 7 dias me manda RPM JoinAds + cliques CTA externo pra refinar.
