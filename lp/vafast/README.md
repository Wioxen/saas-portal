# Vafast — Operação Arbitragem Google Ads → JoinAds

Conjunto de LPs otimizadas pra arbitragem de tráfego pago. Substitui as versões WordPress lentas atuais.

## Fluxo completo

```
Google Ads (CPC R$ 0,30-0,80, segmentação por intent + UTM)
  ↓
LP por temática (cada uma standalone, otimizada):
  ├─ /cursos-arbitragem/     → Senac (4 páginas multi-step)
  ├─ /senai/                 → SENAI (single-page rica + sidebar/anchor)
  └─ /bradesco/              → Fundação Bradesco (single-page + cores marca)
  ↓
JoinAds rendering (publisher 20438, 6 slots por página)
  ↓
Receita publicitária + lead capture conversion (AW-16675521270/zjjrCI3gt8sZEPaFwY8-)
```

## Estrutura de arquivos

| Caminho | Conteúdo | Cor da marca |
|---|---|---|
| `cursos-arbitragem/index.php` | Página 1 multi-step (intro Senac) | Azul corporativo (#0F4C81) |
| `cursos-arbitragem/2.php` | Página 2 multi-step (lista Senac) | Azul corporativo |
| `senai/index.php` | LP única SENAI rica em conteúdo | Vermelho SENAI (#ed1c24) + preto |
| `bradesco/index.php` | LP única Fundação Bradesco | Azul (#004B87) + vermelho (#CC092F) |

## Os 6 slots de ad em TODAS as páginas

Configurar no painel JoinAds (publisher 20438) com esses nomes exatos:

| Slot | Posição | Tipo | Receita esperada |
|---|---|---|---|
| `Content1` | Above the fold (após hero) | In-feed grande | 🔥🔥 Maior |
| `Content2` | In-article após primeiros 2-3 parágrafos | Native médio | 🔥 Alta |
| `Content3` | In-article meio do conteúdo | Native grande | 🔥 Alta |
| `Content4` | Antes do CTA final (gancho de saída) | Native grande | 🔥🔥 Muito alta |
| `Sidebar` | Sticky lateral (desktop only) | 300×600 | Média |
| `Anchor` | Sticky bottom (mobile) com botão fechar | 320×50 ou 320×100 | 🔥🔥🔥 **A maior receita mobile** |

> Cada slot tem label "Publicidade" automático via CSS — compliance JoinAds/AdSense.

## Padrão técnico aplicado em TODAS

- HTML standalone (zero WordPress, zero Astra, zero Bootstrap, zero jQuery, zero Firebase)
- CSS 100% inline crítico (~10KB, sem render-blocking)
- 1 GA4 + 1 Google Ads conversion (consolidados, vs 14+ tags do WP atual)
- Reading progress bar topo da página
- Eventos GA4 de scroll 25/50/75/100% + tempo 30/60/120s (qualidade pra Smart Bidding)
- Anchor sticky mobile com botão fechar (compliance)
- Schema.org Article (rich results)
- Mobile-first com layout responsivo (sidebar só desktop)
- `prefers-reduced-motion` respeitado nas animações (acessibilidade)

## O que cada LP corrige da versão WP original

| Antes (WP) | Depois (standalone) | Impacto |
|---|---|---|
| Bootstrap + Astra + Firebase + jQuery + 14 GA tags | HTML inline + 1 GA4 + 1 Ads | LCP 5s → < 1,5s |
| Preloader 3-5s fullscreen (mata conversão) | Removido | +30-50% retenção |
| 1 slot ad visível (`Content1` apenas) | **6 slots estratégicos** | RPM +50-80% |
| Sem anchor sticky mobile | Anchor com fechar | Receita mobile dobra |
| Sem progress bar / engagement tracking | Progress + scroll milestones + tempo | Sinal qualidade pro Smart Bidding |

## Estratégia por LP (tráfego pago)

### Senac (`cursos-arbitragem/`)
- Multi-step (4 páginas) = 4× pageviews/sessão
- Best for cauda longa: "cursos gratuitos com certificado", "senac cursos online", "psg senac"
- Conversion lead capture no fim da página 4

### SENAI (`senai/`)
- Single-page rica (5 áreas de cursos + estatísticas + processo + depoimentos + FAQ)
- Best for cauda longa: "senai cursos gratuitos", "curso técnico senai", "senai ead"
- CTA pra LP `/curso-gratuito-senai-com-certificado-online-e-presencial/` no fim

### Fundação Bradesco (`bradesco/`)
- Single-page com 5 áreas de cursos + processo + FAQ
- Best for cauda longa: "cursos fundacao bradesco", "escola virtual bradesco", "bradesco cursos online gratuitos"
- CTA pra LP `/bradesco-cursos-guia-completo-...` no fim

## Compliance JoinAds/AdSense (não viola)

✅ OK
- Disclosure "Publicidade" acima de cada slot
- Botão fechar no anchor
- Ratio conteúdo/ad ~70/30
- Sem auto-redirect
- Sem dark patterns
- Disclaimer legal no footer (não vínculo com Senac/Senai/Bradesco)

❌ NÃO ULTRAPASSAR
- 6 slots por página (vira "primarily for ads")
- Pop-ups que cobrem todo conteúdo
- Botões fake parecendo CTA mas ads
- "Click no anúncio pra continuar"
- Esconder label "Publicidade"

## Métricas-meta após deploy (vs versão WP atual)

| Métrica | Atual (vafast WP) | Esperado (novo) |
|---|---|---|
| LCP mobile | ~5s | **< 1,5s** |
| CLS | alto (ads dinâmicos) | **< 0,05** |
| Pageviews/sessão | 1-1.5 | **2-4 (single) / 4 (multi)** |
| Tempo médio | <30s | **>90s** |
| Bounce rate | 70-80% | **40-50%** |
| RPM JoinAds | baseline | **+50-80%** |

## Como deployar

1. Subir cada pasta no domínio:
   - `vafast.xyz/cursos-arbitragem/` (contém `index.php` e `2.php`)
   - `vafast.xyz/senai/` (contém `index.php`)
   - `vafast.xyz/bradesco/` (contém `index.php`)

2. Configurar no painel JoinAds (publisher 20438) os slot names: `Content1`, `Content2`, `Content3`, `Content4`, `Sidebar`, `Anchor`

3. Atualizar campanhas Google Ads pra apontar pras novas URLs (substituir as URLs WP atuais)

4. Aguardar 7 dias coletar dados → comparar com período anterior

## Próximos passos depois de validar (7-14 dias)

1. **A/B test:** versão multi-step (4 páginas) vs single-page rica — qual gera mais RPM/sessão
2. **Adicionar 3.php e 4.php** ao fluxo Senac (lista Senai+Sebrae + passo a passo)
3. **Replicar template** pra novas verticais: cartões, vagas, governo
4. **Lazy-load slots 4-5** quando entrarem viewport (melhora LCP em ~10%)
5. **AdSense em paralelo** ao JoinAds se a conta foi aprovada (network ID secundário)

## Conversion ID Google Ads atual

`AW-16675521270/zjjrCI3gt8sZEPaFwY8-` — chamado nos onclicks dos CTAs principais via `gtag_report_conversion(url)`.

GA4 principal: `G-D04KPSC2ZZ` (consolidar os 14 G-XXXX que tem no WP atual nesse único).
