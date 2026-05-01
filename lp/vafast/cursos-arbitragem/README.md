# LP Arbitragem — Cursos Gratuitos (Vafast)

Página de pouso otimizada pra **arbitragem Google Ads → JoinAds**. Substitui `vafast.xyz/cursos-gratuitos-com-certificado-pelo-governo/`.

## Estratégia central

**Multi-page = mais pageviews/sessão = mais revenue ads.**

Fluxo proposto: **4 páginas, 6 ad slots por página = 24 impressões/sessão (vs 1-2 atual)**.

```
Google Ads (CPC R$ 0,54)
  ↓
index.php  ← chega aqui (Página 1: Intro + por quê fazer)
  ↓ "Ver lista do Senac →"
2.php       ← Página 2: Lista de cursos Senac
  ↓ "Ver Senai →"
3.php       ← Página 3: Lista Senai + Sebrae
  ↓ "Como se inscrever →"
4.php       ← Página 4: Passo a passo + CTA final (link afiliado/lead capture)
```

## Os 6 slots de ad por página

| # | Slot | Posição | Tipo | Receita estimada |
|---|---|---|---|---|
| 1 | `Content1` | Above the fold (após hero) | In-feed grande | 🔥 Alta |
| 2 | `Content2` | In-article 1 (após 2-3 parágrafos) | Native médio | Alta |
| 3 | `Content3` | In-article 2 (meio do conteúdo) | Native grande | Alta |
| 4 | `Content4` | Antes do CTA "próxima página" | Native grande | 🔥 Muito alta |
| 5 | `Sidebar` | Sticky lateral (desktop only) | 300×600 | Média |
| 6 | `Anchor` | Sticky bottom (mobile only) | 320×50 ou 320×100 | 🔥 **A maior receita mobile** |

> **Importante:** os slots `Content1...4`, `Sidebar`, `Anchor` precisam ser configurados no painel JoinAds (publisher 20438). Use os mesmos nomes desses slots ao criar.

## O que essa LP corrige da atual

| Problema atual (vafast.xyz/cursos...) | Solução nova |
|---|---|
| WordPress + Astra + Bootstrap + jQuery + Firebase + 14 GA tags | HTML standalone, CSS inline, 1 GA4 + 1 Google Ads |
| LCP > 5s | LCP esperado < 1,5s |
| Loader fullscreen 5s (joinadsloader__wrapper) | Removido — perde 30-50% dos visitors antes do ad carregar |
| Apenas 1 slot ad (`Content1`) visível | 6 slots em posições estratégicas |
| Sem anchor sticky mobile | Anchor sticky com botão fechar (compliance) |
| Sem progress bar de leitura | Progress bar = engagement signal forte |
| Sem tracking de engagement | Eventos GA4: scroll 25/50/75/100, tempo 30/60/120s |
| CTAs externos cedo (perde pageviews) | CTAs internos pra próxima página = +3 pageviews/sessão |
| Conteúdo de ~300 palavras | ~600-800 palavras com pulso de curiosidade pra próxima página |

## Compliance AdSense/JoinAds (sem violar política)

✅ **OK:**
- Disclosure "Publicidade" acima de cada slot
- Botão fechar no anchor sticky
- Conteúdo original e útil (não thin content)
- Ratio conteúdo/ad ~70/30 (limite seguro)
- Sem auto-redirect
- Sem dark patterns ou typo bait
- Slots claramente delimitados visualmente
- Mobile-friendly + acessível

❌ **NÃO faça:**
- Mais de 6 slots por página (vira "primarily for ads")
- Pop-ups que cobrem todo conteúdo
- Botões fake que parecem CTA mas são ads
- "Click no anúncio pra continuar"
- Esconder o "Publicidade" label

## Replicar pras páginas 2, 3, 4

A `index.php` já tem toda estrutura. Pra criar as outras:

```bash
cp index.php 2.php  # Lista de cursos Senac
cp index.php 3.php  # Lista de cursos Senai + Sebrae
cp index.php 4.php  # Como se inscrever + CTA final
```

Em cada página, alterar:
1. `$page_num` (2, 3, 4)
2. `$next_url` (`./3.php`, `./4.php`, ou link externo de afiliado/captura na página 4)
3. `$page_title` e `$page_desc`
4. Dot navigation (`<span class='active'>` na posição correta)
5. Conteúdo da `<article>` (lista de cursos por área, depois lista Senai, depois passo a passo)
6. JSON-LD `headline` e `description`

**Manter idênticos:** estrutura HTML, CSS inline, 6 slots de ad, scripts de tracking.

## Páginas institucionais necessárias

Já existem em vafast.xyz:
- `/politica-de-privacidade-2/`
- `/termos-de-uso/`
- `/fale-conosco/`

Confirmar antes de subir Ads.

## Métricas-meta após deploy

| Métrica | Atual (vafast) | Esperado |
|---|---|---|
| LCP mobile | ~5s | **< 1,5s** |
| CLS | alto (ads dinâmicos) | **< 0,05** (slots reservados) |
| Pageviews/sessão | 1-1.5 | **3-4** |
| Tempo médio na sessão | <30s | **>90s** |
| Bounce rate | 70-80% | **40-50%** |
| RPM JoinAds (R$ por 1000 pageviews) | desconhecido | **+50-80%** |

## Como deployar

1. Subir os 4 `.php` em `vafast.xyz/cursos-arbitragem/` (substitui o WordPress permalink)
2. Configurar os 6 slot names no painel JoinAds (publisher 20438): `Content1`, `Content2`, `Content3`, `Content4`, `Sidebar`, `Anchor`
3. Atualizar a campanha Google Ads pra apontar pra `https://vafast.xyz/cursos-arbitragem/` (substituir URL atual)
4. Aguardar 7 dias coletar dados → comparar com período anterior

## Próxima evolução (depois de validar)

- A/B test: 4 páginas vs 6 páginas
- Adicionar slot `Below_FAQ` se 6 estiver convertendo bem
- Testar **AdSense** em paralelo ao JoinAds (network ID secundário) — se conta aprovada
- Lazy-load slots 4-5 só quando entrarem viewport (melhora LCP)
