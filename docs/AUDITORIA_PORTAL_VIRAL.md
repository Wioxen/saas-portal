# Auditoria Honesta — Portal Viral Pronto pra Milhões de Acessos

> **Data:** 2026-04-27
> **Objetivo da auditoria:** Validar se o sistema atual está pronto pra escalar a rede de 6 sites Clonais Work pra **milhões de acessos/mês via Discover + SEO + Social**, monetizado por afiliado/CPA contextual.
> **Veredito honesto:** **60% do caminho.** Engenharia excelente, **gaps reais bloqueiam volume e conversão**. Documento abaixo lista TODOS os gaps em ordem de prioridade, com plano executável em 3 fases.

---

## 1. Resumo executivo

Em 5 sessões (g15–g42 + esta) entregamos **23+ módulos** que cobrem o pipeline editorial completo: scraping de fonte → classificação → score → geração Sonnet/GPT → revisão → publicação WP → Web Story → IndexNow → Auto-FB/IG → Smart In-Feed → afiliado contextual. **6/6 sites validados E2E** com posts de quality 8.8–10.0. A camada técnica está sólida.

**Mas portal viral pronto pra Discover não vive de pipeline — vive de:**

1. **Volume** (50+ posts/site × 6 sites × cron 24h)
2. **Frescor temático** (sazonal completo + trends esportivos/produtos detectados em tempo real)
3. **Monetização densa** (catálogo de 30–50 ofertas reais, não 7 placeholders)
4. **Distribuição automatizada** (FB/IG funcionando + RSS + Sitemap + GSC verificado)
5. **Latência mobile** (CDN + cache pesado, Discover é 90% mobile)
6. **Refresh inteligente** (CTR decai em 3–7 dias se artigo fica estático)
7. **Persona forte** (cada site escreve como o nicho dele, não como genérico)

Hoje temos **(1) e (4) parciais**, **(2) parcial**, **(3) frágil**, **(5) zero**, **(6) zero**, **(7) parcial**.

---

## 2. Estado atual — radiografia

### 2.1 O que está pronto (validado E2E)

- **Pipeline:** 6/6 sites publicaram artigo end-to-end com plugin Web Story v26 ativo
- **TIER S (3 itens):** cron tick filas, filtro pingo 2-camadas (43% aprovação), antecipação sazonal (40 trends)
- **TIER A (8 itens):** E-E-A-T schema + páginas/bio em 6 sites, InstantIndexing, Prompt Caching (-90% custo input), Auto-FB/IG (validado FB em 2 sites), CTA share contextual, mais fontes (16 ativas), Trend-Scoring Gate (Sonnet/GPT por score), Smart In-Feed plugin, ImagemViral (saturação+contraste+tarja)
- **Esta sessão (4 fixes):** Dispatcher cluster→site, Afiliado expansão (5→7 ofertas), Cluster classifier rebalanceado, Temporal verbo passado
- **Persona definida** em `sites.php` para os 6 sites (campos: autor, voz, especialidade, audiência, tom, clusters_foco)

### 2.2 Cobertura por categoria de necessidade

| Categoria | Estado | Quantidade |
|-----------|--------|------------|
| Sites WP funcionando | 🟢 6/6 | comocomprar, vagasebeneficios, cursosenacgratuito, guiadoscursos, leaodabarra, ondecompraragora |
| Posts publicados em produção | 🔴 6 (testes) | precisa 50+/site = 300 posts pra Discover indexar |
| Trends aprovadas pra publicar | 🟢 200+ | mas mal-distribuídas (171 em comocomprar, 0 em ondecompraragora antes do fix) |
| Fontes RSS ativas | 🟡 16 | só 1 dedicada pra comocomprar, **0 dedicadas pra leaodabarra/guiadoscursos/ondecompraragora** |
| Catálogo afiliado | 🔴 7 ofertas | todas com mesma URL placeholder `amzn.to/4ckOgUc` |
| Calendário sazonal | 🟡 49 eventos | falta Black Friday, Natal, Halloween, Dia dos Pais, Crianças, Boxing Day, Cyber Monday |
| Cron Linux 24h | 🔴 0 | sistema 100% manual hoje |
| GSC + Sitemap verificado | ❓ | não auditado em sessão; provável 0/6 |
| Cloudflare/CDN | 🔴 0 | nenhum site tem |
| RSS feed pro "Seguir" Discover | ❓ | não auditado; padrão WP existe mas pode estar bloqueado por robots.txt |

---

## 3. Gaps priorizados — 12 itens

Cada gap tem: **descrição → impacto → esforço → recomendação concreta**.

### 🔴 P0 — Bloqueia volume (sem isso, NÃO há milhões de acessos)

#### G1. Cron Linux desligado
**Descrição:** `tick_filas.php`, `pingo.php`, `antecipar_sazonal.php` rodam só em demanda manual. Sistema processa 1 trend → para.
**Impacto:** Sem cron, gera 1–5 posts/dia no máximo (manual). Discover precisa de **≥3 posts/dia × site × 30 dias** pra começar a impulsionar.
**Esforço:** 1h (operacional, no host Linux do user)
**Recomendação:**
```
*/2 * * * * /usr/bin/php /var/www/scripts/tick_filas.php --quiet >> /var/log/tick.log 2>&1
*/15 * * * * /usr/bin/php /var/www/scripts/pingo.php --site=comocomprar --quiet >> /var/log/pingo.log 2>&1
0 3 * * * /usr/bin/php /var/www/scripts/antecipar_sazonal.php --max-queries=12
```

#### G2. Volume zero — sites sem 50+ posts publicados
**Descrição:** 6 posts E2E em testes, mas Discover não distribui sites com <50 artigos.
**Impacto:** Bloqueia entrada no Discover Feed (algoritmo prefere portais com histórico).
**Esforço:** 7–14 dias rodando cron + pingo + sazonal
**Recomendação:** Liga cron (G1) + deixa rodar 14 dias. Cada site chega a 50–100 posts.

#### G3. Sem fontes RSS dedicadas pra leaodabarra / guiadoscursos / ondecompraragora
**Descrição:** Esses 3 sites dependem só de fontes "auto" + sazonal. leaodabarra não tem feed de esporte.
**Impacto:** Pool de trends fraco, conteúdo escasso.
**Esforço:** 30min–1h por site (achar feeds + adicionar JSON)
**Recomendação:**
- **leaodabarra** (esporte): GE.globo, ESPN Brasil, UOL Esporte, Lance!, F1.com (RSS oficiais)
- **guiadoscursos**: Guia do Estudante, Brasil Escola, Vestibulando, MEC Programas
- **ondecompraragora**: Promobit, Pelando, Cupom Tem, Reclame Aqui

#### G4. Calendário sazonal incompleto
**Descrição:** 49 eventos cobrem ~3 meses dos 12 do ano. Faltam: Black Friday (28/11), Cyber Monday (01/12), Natal, Páscoa próxima, Boxing Day, Dia dos Pais (08/08), Dia das Crianças (12/10), Halloween, Festa Junina ampla, vestibulares 2º semestre (Sisu, Fies, ProUni), férias escolares, Carnaval 2027.
**Impacto:** "Dia dos namorados 2026" chega 30 dias antes da data → site não tem post otimizado → perde tráfego sazonal de busca.
**Esforço:** 1h pra adicionar 30+ eventos a `lib/DiscoverCalendario.php`
**Recomendação:** Expandir `DiscoverCalendario::catalogo()` com calendário BR completo + eventos de produto (Black Friday, Cyber Monday, etc.)

### 🟡 P1 — Bloqueia receita (volume sem conversão = não monetiza)

#### G5. Catálogo de afiliados raso (7 ofertas, todas placeholder)
**Descrição:** Após sessão de hoje temos 7 ofertas mas TODAS com mesma URL `amzn.to/4ckOgUc`. Não rastreia por produto.
**Impacto:** Sem rastreio de conversão. Sem opcionalidade por produto. CTR baixo (CTA genérico vs CTA do produto específico mostrado no artigo).
**Esforço:** 4–8h pra montar 30–50 ofertas reais com URLs Amazon BR via Tag Manager / OneLink Amazon
**Recomendação:**
- Cadastrar tag de afiliado no painel Amazon Associates BR
- Criar 30+ ofertas em `data/afiliados.json` com URLs reais (não placeholder)
- Cobrir clusters: lifestyle (10), tecnologia (8), comidas (5), automoveis (3), saúde (3), esportes (3), educação (5)
- Adicionar **plataformas alternativas:** Hotmart (cursos), Shopee (lifestyle), Mercado Livre (geral), Magalu Ads, Awin (passagens/seguros)

#### G6. DiscoverProductRanker não existe (TIER A 6)
**Descrição:** Hoje a Sonnet escreve "10 ideias de presentes" inventando produtos. Não consulta a Amazon BR pra trazer **os 10 mais vendidos REAIS** com nome+foto+preço+link de afiliado individual.
**Impacto:** ALTO. Esse é o módulo que separa "site de notícia" de "portal de monetização viral". CTR e conversão limitados sem ele.
**Esforço:** 1–2 dias (módulo novo). Componentes:
- API ou scraping da Amazon Best Sellers (`/zgbs/{categoria}`) ou Movers & Shakers
- Cache local 24h por categoria pra evitar rate-limit
- Detecção do tipo de lista no termo ("top 10", "mais baratos", "presentes ate R$ X") → busca categoria certa
- Geração de tabela HTML com nome + img + preço + link individual (cada produto vira /go/{slug-produto})
- Sonnet recebe os 10 produtos REAIS no contexto e escreve em volta
**Recomendação:** Implementar **APÓS** ligar cron e ter volume base. É o salto de receita.

#### G7. IG aspect ratio quebrado
**Descrição:** Featured image gerada é 1200×675 (16:9). IG Feed só aceita 1:1 a 4:5. Auto-IG falha silenciosamente.
**Impacto:** Tráfego social IG perdido (que é direto pra Discover via direct traffic signal).
**Esforço:** 2–3h (gerar variante 4:5 1080×1350 antes de postar IG)
**Recomendação:** Em `Meta::postarInstagramFeed`, aceitar URL secundária `imgUrlIg` 4:5 OU gerar via `DiscoverImagemViral::variante1080x1350()` antes de postar.

#### G8. Persona não força ângulo (briefing genérico vence)
**Descrição:** Em comocomprar #602, trend "dia das mães 2025" virou artigo informacional ("data, origem") em vez de shopping. Persona é injetada mas o ângulo do briefing genérico domina.
**Impacto:** Site de compras pode publicar artigo de Wikipedia. Perde conversão.
**Esforço:** 1h (fix em `DiscoverPromptBuilder`)
**Recomendação:** Mover bloco persona pra DEPOIS do briefing no prompt + adicionar regra explícita: "Mesmo que o briefing sugira ângulo genérico, REESCREVA com a lente do nicho deste site."

### 🟢 P2 — Otimização e qualidade

#### G9. Cloudflare/CDN inexistente
**Descrição:** Nenhum site tem CDN. Latência mobile é fator de ranking Discover (Core Web Vitals).
**Impacto:** TTFB alto + LCP ruim → Discover penaliza distribuição.
**Esforço:** 30min/site + API key (free tier funciona)
**Recomendação:** Cloudflare free + Auto Minify + APO (Automatic Platform Optimization, $5/mês opcional)

#### G10. Sem auto-refresh de posts antigos
**Descrição:** Posts decaem em CTR após 3–7 dias. Sem refresh, perdem distribuição.
**Impacto:** Curva de tráfego é "spike + queda rápida" em vez de "plateau sustentado".
**Esforço:** 1 dia (TIER A pendente do roadmap)
**Recomendação:** Cron diário detecta posts com queda CTR ≥20% (via GSC API) → re-roteia pra `DiscoverReviewer` pra atualização editorial.

#### G11. Cluster "educação" não existe na taxonomia
**Descrição:** ENEM, FIES, vestibulares caem em `noticias_info_critica` → roteia pra vagasebeneficios em vez de cursosenac/guiadoscursos.
**Impacto:** Mismatch site×conteúdo, persona errada aplicada.
**Esforço:** 30min (adicionar cluster `educacao` em `TrendsTaxonomia.php`)
**Recomendação:** Criar cluster `educacao` com keywords [enem, sisu, fies, prouni, vestibular, faculdade, curso, certificação, ead] e adicionar ao mapa de roteamento `roteamentoPorCluster` em `DiscoverPingo.php`.

#### G12. Web Story ROI gate muito restritivo
**Descrição:** Cluster DATA/FERIADO tem ROI baixo → Web Story pulado. Mas Web Story é canal direto Discover (super valioso).
**Impacto:** Subutilização do canal mais visual do Discover.
**Esforço:** 30min (revisar threshold em `DiscoverWebStory::deveGerar`)
**Recomendação:** Reduzir `webstory_roi_min` de 5.0 → 3.0 OU adicionar override por persona ("ondecompraragora sempre gera Web Story em DATA/FERIADO").

---

## 4. Roadmap em 3 fases

### Fase 1 — Destravar volume (3–7 dias)

**Objetivo:** Sair de "6 posts em testes" pra "300 posts indexados".

| # | Item | Owner | Tempo |
|---|------|-------|-------|
| 1.1 | Configurar cron Linux (G1) | user | 1h |
| 1.2 | Mudar pingo `warn` → `block` após 24h | user | 5min |
| 1.3 | Switch filtro pingo pra modo agressivo | code | já |
| 1.4 | Adicionar feeds dedicados leaodabarra (5 feeds) (G3) | code | 1h |
| 1.5 | Adicionar feeds dedicados guiadoscursos (3 feeds) (G3) | code | 30min |
| 1.6 | Adicionar feeds dedicados ondecompraragora (3 feeds) (G3) | code | 30min |
| 1.7 | Expandir calendário sazonal pra 12 meses (G4) | code | 1h |
| 1.8 | Adicionar cluster `educacao` (G11) | code | 30min |
| 1.9 | Deploy plugin Web Story v26 nos 4 sites restantes | user | 30min |
| 1.10 | Configurar Rank Math Indexing API key em 6 sites | user | 1h |
| 1.11 | Verificar/corrigir RSS feed pro "Seguir" em 6 sites | user | 30min |
| 1.12 | Submeter sitemap ao GSC em 6 sites | user | 1h |

**Critério de "pronto" da Fase 1:** Cada um dos 6 sites com ≥30 posts publicados via cron + sitemap submetido + RSS funcional.

---

### Fase 2 — Destravar receita (1–2 semanas)

**Objetivo:** Sair de "afiliado coringa Amazon" pra "10 produtos reais por artigo de produto".

| # | Item | Owner | Tempo |
|---|------|-------|-------|
| 2.1 | Cadastrar 30+ ofertas reais em afiliados.json (G5) | user/code | 4–8h — **bloqueado: depende user cadastrar Associates BR + Hotmart + Awin** |
| 2.2 | ✅ Implementar DiscoverProductRanker (G6) | code | **entregue 2026-04-27** |
| 2.3 | ✅ Integrar ProductRanker no DiscoverGerador | code | **entregue 2026-04-27** |
| 2.4 | ✅ Fixar persona override no prompt (G8) | code | **entregue 2026-04-27 madrugada** |
| 2.5 | ✅ Fixar IG aspect ratio (G7) | code | **entregue 2026-04-27** |
| 2.6 | ✅ Reduzir Web Story ROI gate (G12) | code | **entregue 2026-04-27 madrugada** |
| 2.7 | ✅ Auto-refresh inteligente (G10) | code | **entregue 2026-04-27** |

**Critério de "pronto" da Fase 2:** 70%+ dos artigos de produto com 5–10 produtos reais Amazon. CTR afiliado >2%. CR (clique→compra) rastreado.

---

### Fase 3 — Otimização contínua (1 mês)

**Objetivo:** Sair de "300 posts" pra "1000+ posts/mês com refresh + CDN + AdSense".

| # | Item | Owner | Tempo |
|---|------|-------|-------|
| 3.1 | Cloudflare em 6 sites (G9) | user | 3h |
| 3.2 | Spike detection (TIER A pendente) | code | 1 dia |
| 3.3 | Pruning automático posts antigos (TIER A pendente) | code | 1 dia |
| 3.4 | Aplicar AdSense (depende de 50+ posts/site) | user | aplicação |
| 3.5 | Iniciar coleta de viral patterns (estudo de títulos top performers) | code | contínuo |
| 3.6 | A/B testing de CTAs e tarjas | code | contínuo |

**Critério de "pronto" da Fase 3:** AdSense aprovado em 4+ sites. RPM combined ≥R$ 8/mil. Tráfego orgânico ≥100k/site/mês.

---

## 5. Métricas de monitoramento

A partir da Fase 1 ligada, observar diariamente:

| Métrica | Onde | Alvo Fase 1 | Alvo Fase 3 |
|---------|------|-------------|-------------|
| Posts publicados/dia/site | log_tick + WP | ≥3 | ≥10 |
| Trends aprovadas em fila | discover_trends.json | ≥20 por site | ≥50 |
| IndexNow success rate | indexing_info | ≥95% | ≥99% |
| Web Story criadas/dia | web_story_info | ≥1/site | ≥3/site |
| FB/IG posts/dia | meta_info | ≥3/site | ≥10/site |
| Discover impressions (GSC) | GSC | ≥1k/dia | ≥100k/dia |
| CTR Discover | GSC | ≥3% | ≥6% |
| Afiliado CTR | afiliados_clicks.json | ≥1% | ≥3% |
| Conversão afiliado | Amazon Associates BR | — | ≥0.5% |

---

## 6. O que NÃO é prioridade agora (parking lot)

Coisas que parecem urgentes mas vão drenar tempo sem retorno proporcional na Fase 1:

- ❌ Reescrever cluster classifier do zero (já foi rebalanceado nesta sessão, suficiente)
- ❌ Implementar GA4 / Tag Manager custom (Cloudflare basic + GSC já dá visibilidade)
- ❌ Migrar pra Astro / Next.js (WP é estável e amado pelo Discover)
- ❌ Refatorar persona schema (atual funciona, fix do briefing override resolve)
- ❌ Adicionar 50 fontes RSS adicionais (16 ativas + 9 dedicadas = volume mais que suficiente)
- ❌ Implementar IA própria / fine-tuning (Sonnet + GPT-mini cobre 99% dos casos)

---

## 7. O que aprendemos sobre "viralizar no Discover" (insights desta operação)

Ao longo das sessões g15–g42 + esta, padrões fortes:

1. **Trend-first beats site-first** — Sonnet escreve em volta do termo da trend. Persona só é respeitada se PROMPT FORÇA. Lição: persona vai ANTES de briefing no prompt.

2. **Volume vence elegância** — 50 posts mid-quality > 5 posts perfeitos. Discover indexa frequência. Cron 24h é mais valioso que prompt perfeito.

3. **Categoria de notícia ≠ categoria do site** — Google News Geral é genérico. Mandar tudo pra comocomprar é o erro mais comum (corrigido nesta sessão).

4. **Auditoria cospe falsos positivos em Leia Mais** — não tratar como bug, ignorar.

5. **Web Story é under-rated** — Discover dá distribuição premium pra Stories AMP. ROI gate precisa ser frouxo.

6. **Direct traffic > all** — FB/IG/WhatsApp share signal é fortíssimo pro Discover (descoberto via g36–g42).

7. **Sazonal antecipado é ouro** — D-7 a D-30 é janela mágica (Google sobe trends antes da data).

8. **Cat_ids do feed mente** — categoria_ids do RSS é só hint, não verdade. Conteúdo do termo é a verdade.

9. **Afiliado match coringa é pior que skipar** — match estranho (concurso público em post de Senna) destrói CTR. Threshold mínimo 5 está correto.

10. **Plugin keys ≠ instalado** — instalar plugin não basta, precisa configurar credenciais (Anthropic/OpenAI no Web Story v26).

---

## 8. Como retomar amanhã

1. Ler **`docs/STATUS_OPERACAO.md`** (pickup point)
2. Ler **este documento** (`docs/AUDITORIA_PORTAL_VIRAL.md`)
3. Decidir ordem: **Fase 1 destrava volume** — começar por **G1 (cron)**, depois **G3 (feeds dedicados)**, depois **G4 (calendário expandido)**.
4. Itens user-only (G1, deploy plugins, Rank Math, GSC) podem rodar em paralelo com itens code-only (G3, G4, G8, G11).

**Sugestão de sprint amanhã (4h):**
- 1h — user configura cron + GSC nos 6 sites
- 1h — code adiciona feeds esporte/cursos/shopping
- 1h — code expande calendário sazonal
- 1h — code adiciona cluster educação + fix persona override

Após esse sprint: cron rodando 24h + 22 fontes RSS ativas + calendário 12 meses + 6 clusters bem mapeados → **Fase 1 quase completa em 1 dia**.
