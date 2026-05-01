# Roadmap — Operação LPs de Afiliado Amazon

**Atualizado:** 2026-04-28
**Conta Google Ads:** AW-16696952717
**Estratégia central:** LPs estáticas individuais por produto, tráfego pago Google Ads, conversão proxy via gtag no clique CTA.

---

## STATUS ATUAL

| LP / Campanha | Estado | Conversões | Notas |
|---|---|---|---|
| `lp/comocomprar/potes-vidro-hermeticos/` | ✅ Pronta local | — | Conversion ID `welKCJCdt9EZEI2P3Zk-`, link `amzn.to/4mT9dJu` |
| `lp/comocomprar/mascara-absolut-repair-molecular/` | ✅ Pronta local | — | Conversion ID `sZLcCKL9l6QcEI2P3Zk-`, link `amzn.to/4mZeKhF` |
| `lp/comocomprar/qcy-h3-anc/` | ✅ Pronta local | — | Conversion ID `NUQUCMi3t6QcEI2P3Zk-`, placeholder `amzn.to/QCYH3` |
| `lp/comocomprar/airpods-pro-3/` | ✅ Pronta local | — | Conversion ID `NUQUCMi3t6QcEI2P3Zk-`, placeholder `amzn.to/AIRPODS3` |
| `lp/comocomprar/galaxy-buds3-fe/` | ✅ Pronta local | — | Conversion ID `NUQUCMi3t6QcEI2P3Zk-`, placeholder `amzn.to/BUDS3FE` |
| `comocomprar.com.br/guia-compra-melhores-fones-de-ouvido/` | ⚠️ Em produção, NÃO converteu | **0 em R$ 202,21** | Review de 9 modelos. CPC R$ 2,73, CTR 2,65%. Conversion correto: `NUQUCMi3t6QcEI2P3Zk-` (atualmente usa o de potes por engano) |

---

## 🔥 PRIORIDADE 1 — SALVAR CAMPANHA DE FONES (faz HOJE no painel Ads)

### 1A. Pausar keywords broad genéricas que queimam orçamento

Selecionar essas no grid → **Pausar** (não excluir):
```
fones de ouvido
fone wireless
fone de ouvido sem fio
melhores fones
melhores fones bluetooth
fone headset
melhores headsets
compras online
fone de ouvido bluetooth (mantém só esta como exata)
melhores fones sem fio
melhores fones de ouvido sem fio
melhores fones de ouvido bluetooth
os melhores fones de ouvido bluetooth
melhor fone de ouvido (broad)
melhores fone de ouvido bluetooth
fone de ouvido bom
melhor fone de ouvido bluetooth
```

### 1B. Adicionar keywords exatas dos 9 modelos da LP atual

```
[sony wh-1000xm5]
[sony wh1000xm5]
[qcy h3 anc]
[qcy h3]
[philips taue101bk]
[soundcore p20i]
[soundcore p20i anker]
[jbl c50hi]
[jbl quantum 100m2]
[apple airpods pro 2]
[airpods pro 2]
[airpods pro 2 geracao]
[samsung galaxy buds3 pro]
[galaxy buds3 pro]
[havit h2002d]
```

### 1C. Adicionar variantes transacionais (correspondência de frase)

```
"comprar sony wh-1000xm5"
"sony wh-1000xm5 preco"
"sony wh-1000xm5 amazon"
"comprar qcy h3 anc"
"qcy h3 anc preco"
"qcy h3 amazon"
"airpods pro 2 amazon"
"airpods pro 2 preco"
"comprar airpods pro 2"
"airpods pro 2 geracao amazon"
"galaxy buds3 pro amazon"
"galaxy buds3 pro preco"
"comprar galaxy buds3 pro"
"soundcore p20i preco"
"soundcore p20i amazon"
"havit h2002d preco"
"havit h2002d amazon"
"philips taue101bk preco"
"jbl c50hi amazon"
"jbl quantum 100m2 amazon"
```

### 1D. Adicionar negativadas (modelos/marcas que NÃO estão na LP)

```
kz castor
kz edx
redmi buds
redmi airdots
miniso
h maston
kateluo
amazfit
realme buds
jabra
bose quiet
baseus
lenovo
tanchjim
matsuhashi
hrebos
bright max
nova 7x
tranya
imenso bluetooth
basike
buds3 fe
jbl tune
jbl wave
jbl 520
jbl race
jbl live beam
edifier wh700
oppo
huawei freebuds
xiaomi buds
moto g
iphone original
ipod
cupom
mercado livre
mercadolivre
shopee
magalu
review
é bom
natação
autista
condução óssea
ipx8
```

### 1E. Trocar Conversion ID na LP

✅ Conversion criada: **"Outbound click"** ID `NUQUCMi3t6QcEI2P3Zk-` (recebido 2026-04-28)

**Ação no WordPress:** abrir o plugin "Simple Custom CSS and JS" no admin do comocomprar.com.br, encontrar o snippet que tem `welKCJCdt9EZEI2P3Zk-` e trocar pelo código abaixo:

```js
jQuery(function($) {
    if (window.location.pathname === "/guia-compra-melhores-fones-de-ouvido/") {

        function gtag_report_conversion(url) {
            var called = false;
            var callback = function () {
                if (called) return;
                called = true;
                if (url) { window.location.href = url; }
            };

            if (typeof gtag === "function") {
                gtag('event', 'conversion', {
                    'send_to': 'AW-16696952717/NUQUCMi3t6QcEI2P3Zk-',
                    'value': 1.0,
                    'currency': 'BRL',
                    'event_callback': callback,
                    'event_timeout': 2000
                });
                setTimeout(callback, 1500);
            } else {
                callback();
            }
        }

        $(document).on("click", ".cc-btn--primary", function(e) {
            e.preventDefault();
            var url = $(this).attr("href");
            gtag_report_conversion(url);
        });
    }
});
```

> A única mudança é o `send_to` — de `welKCJCdt9EZEI2P3Zk-` para `NUQUCMi3t6QcEI2P3Zk-`.

**Validação após salvar:** abrir a LP em aba anônima → DevTools Network → clicar em qualquer "💰 Ver preço" → confirmar request pra `googleads.g.doubleclick.net/pagead/conversion/16696952717/?label=NUQUCMi3t6QcEI2P3Zk-` retornando 200.

### 1F. Ajustar configuração da campanha
- **Lance:** trocar broad com R$ 6,41 médio pra **CPC manual R$ 1,50 max** (cauda longa exata é barata)
- **Públicos:** mudar pra **Observação** se estiver em Segmentação
- **Orçamento:** R$ 30-50/dia ok pra teste

### Métrica esperada após Fase 1 (7 dias)
| | Antes | Esperado |
|---|---|---|
| CTR | 2,65% | 5-8% |
| CPC | R$ 2,73 | R$ 0,80-1,50 |
| Conversões | 0 | 2-5 |

---

## 🔥 PRIORIDADE 2 — Aplicar otimizações nas 2 campanhas existentes

### Campanha Potes Vidro Hermético

Tudo já documentado em `lp/comocomprar/potes-vidro-hermeticos/CRIATIVOS-ADS.md`. Resumo:

**Headlines RSA:** trocar 4 das 15 (#9, #11, #13, #14) pelas keyword-dense:
- #9 → `Melhor Pote de Vidro Hermético`
- #11 → `Pote de Vidro 1040ml Amazon`
- #13 → `Comprar Kit Potes de Vidro`
- #14 → `Onde Comprar Pote de Vidro`

**Keywords:** apagar Grupo 1/2/3 antigo, colar Tier 1-7 do CRIATIVOS-ADS.md.

**Negativadas:** colar lista expandida de 50+ termos (marcas concorrentes brinox/marinex/electrolux, materiais errados bambu/acrílico, casos errados leite materno/vela/lembrancinha, tamanhos diferentes 150/200/500/640/750ml, canais shopee/mercadolivre).

### Campanha Absolut Repair Molecular

Tudo em `lp/comocomprar/mascara-absolut-repair-molecular/CRIATIVOS-ADS.md`. Resumo:

**Headlines RSA:** as 15 já reescritas (versão keyword-dense de 2026-04-28).

**Descrições:** as 4 já reescritas (keyword-dense).

**Keywords:** apagar lista antiga, colar Tier 1-7 (marca exata + produto irmão + dor + onde comprar + preço + amazon + melhor+dor).

**Pausar (KILL LIST):** 5 keywords confirmadas Low search volume.

**Negativadas:** lista expandida com marcas concorrentes (kerastase, wella, pantene, natura, eudora, lola, mantecorp, widi care, matizadora) + barata/barato/crimson desert/silicone/cpap/carnaval.

---

## 📝 PRIORIDADE 3 — Construir 3 LPs individuais top modelos (Fase 2)

Replicar template de `lp/comocomprar/mascara-absolut-repair-molecular/`. Tempo estimado: 30-45 min por LP usando o template já calibrado.

### LP 3 — Apple AirPods Pro 2ª Geração
- Pasta: `lp/comocomprar/airpods-pro-2/`
- Procurar ASIN na Amazon BR (Apple AirPods Pro 2 cabo USB-C)
- Conversion ID: `NUQUCMi3t6QcEI2P3Zk-` (Outbound click — mesmo das outras LPs de fones)
- Link Amazon: pendente (gerar via Associates SiteStripe)

### LP 4 — Samsung Galaxy Buds3 Pro
- Pasta: `lp/comocomprar/galaxy-buds3-pro/`
- Procurar ASIN na Amazon BR
- Conversion ID: `NUQUCMi3t6QcEI2P3Zk-` (Outbound click — mesmo das outras LPs de fones)
- Link Amazon: pendente

### LP 5 — QCY H3 ANC
- Pasta: `lp/comocomprar/qcy-h3-anc/`
- Procurar ASIN na Amazon BR
- Conversion ID: `NUQUCMi3t6QcEI2P3Zk-` (Outbound click — mesmo das outras LPs de fones)
- Link Amazon: pendente

**Para cada LP, sequência:**
1. Scrape Amazon (`Invoke-WebRequest` com User-Agent → salva HTML)
2. Extrair título, preço, rating, bullets, reviews verificados BR, imagens hi-res (regex calibrado)
3. Baixar 9 imagens via System.Drawing (hero 800px, galeria 600px, JPG q82)
4. Converter pra WebP via `php scripts/converter_lp_webp.php`
5. Duplicar `index.html` do template, trocar metadata + JSON-LD + conteúdo
6. Trocar conversion ID `sZLcCKL9l6QcEI2P3Zk-` pelo novo
7. Hero, USPs (4), reviews (3-6 reais), FAQ (6), specs
8. Criar `CRIATIVOS-ADS.md` específico (15 headlines, 4 descrições, keywords cauda longa de modelo)
9. Criar `README.md` do produto

---

## 📝 PRIORIDADE 4 — Construir 6 LPs restantes (Fase 3)

Mesma sequência da Fase 2 — quando Fase 2 mostrar conversão.

- Sony WH-1000XM5
- Philips TAUE101BK
- Soundcore P20i (Anker)
- JBL C50HI
- JBL Quantum 100M2
- Havit H2002D

---

## 🛠️ MANUTENÇÃO — Páginas institucionais (PRÉ-REQUISITO de qualquer LP de Ads)

Google Ads reprova LP de afiliado sem essas 3 páginas. **Confirmar que existem em comocomprar.com.br:**

- [ ] `/sobre/` — quem é o ComoComprar, política editorial, como ganhamos comissão
- [ ] `/contato/` — email visível + formulário (Formspree resolve)
- [ ] `/politica-de-privacidade/` — uso de cookies, GTM, GA4, programa de afiliados Amazon

> Se já existem, marcar OK. Se não, criar antes da próxima campanha subir.

---

## 🧪 VALIDAÇÃO PRÉ-DEPLOY de cada LP

- [ ] PageSpeed Insights ≥ 90 mobile (https://pagespeed.web.dev/)
- [ ] Schema.org Product validado (https://validator.schema.org/)
- [ ] Rich Results test (https://search.google.com/test/rich-results)
- [ ] HTTPS funcionando
- [ ] CTA testado: clica no botão → abre `amzn.to/...` em nova aba
- [ ] DevTools Network: clique no CTA → request pra `googleads.g.doubleclick.net/pagead/conversion/` retorna 200
- [ ] PrettyLinks confirmado redirecionando 301 pra Amazon
- [ ] Disclosure de afiliado visível no topo + footer

---

## 📊 MÉTRICAS-META por LP nos primeiros 30 dias

| Métrica | Meta |
|---|---|
| CTR do anúncio | > 5% |
| Taxa de conversão LP (clique CTA) | > 15% |
| CPC médio | R$ 0,80-1,50 |
| CPA proxy | < R$ 8 |
| Score qualidade | ≥ 7/10 |

---

## 🎯 DECISÕES PENDENTES DO USER

| Pergunta | Status |
|---|---|
| Subir campanhas via API Google Ads? | ❌ Decidiu **manual por enquanto** (2026-04-28) |
| Hosting/domínio das LPs estáticas | ⚠️ Pendente — `https://comocomprar.com.br/[slug]/` |
| Hostinger ou cPanel atual permite SSL? | ⚠️ Confirmar |
| Páginas /sobre, /contato, /privacidade existem? | ⚠️ Confirmar |
| Conversion IDs novos pra LPs Fase 2 | ⚠️ User precisa criar 3 no Ads (FONES-AIRPODS, FONES-GALAXY, FONES-QCY) |
| Links curtos Amazon (`amzn.to/...`) Fase 2 | ⚠️ User gera 3 via Associates SiteStripe |

---

## 📚 MEMÓRIAS JÁ SALVAS (referência rápida pra próximas sessões)

- `reference_lp_afiliado_template.md` — caminho do template + processo de replicação (45-60 min/LP)
- `feedback_rsa_keyword_density.md` — RSA precisa 3+ headlines por grupo semântico de keyword
- `feedback_validar_keywords_autocomplete.md` — validar volume via autocomplete antes de cadastrar
- `feedback_intencao_compra_keywords.md` — afiliado precisa modificador transacional, não usar ampla em produto premium
- `feedback_produtos_amazon_afiliado.md` — 3 trilhas (CTA single / ProductRanker / LP Ads)

---

## ⏱️ ORDEM SUGERIDA DE EXECUÇÃO

1. **HOJE (1h):** Fase 1 da campanha de fones — pausar broad + adicionar exata + negativadas + trocar conversion ID
2. **HOJE (1h):** Aplicar otimizações documentadas nas campanhas Potes e Absolut Repair (cola dos CRIATIVOS-ADS.md)
3. **HOJE/AMANHÃ:** Confirmar /sobre, /contato, /privacidade no domínio
4. **AMANHÃ (3-4h):** Construir 3 LPs Fase 2 (AirPods, Galaxy Buds3 Pro, QCY H3 ANC) — eu construo, você gera links + conversion IDs
5. **APÓS 7 DIAS:** Medir resultados Fase 1 + 2. Se positivo → Fase 3 (6 LPs restantes)
6. **APÓS 30 dias com conversões consistentes:** considerar API Google Ads pra escalar
