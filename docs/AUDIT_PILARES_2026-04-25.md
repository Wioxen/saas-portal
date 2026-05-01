# Auditoria dos 5 Pilares — Clonais Work · 2026-04-25

> **Escopo:** este doc é **cross-module** (toca portal, maquina, lib/, scripts/, plugin WP, data/). Não pertence a `docs/portal/` — vive na raiz `docs/`. Resultado de 4 agentes Explore investigando em paralelo.
>
> **Modo:** diagnóstico. Nenhum código foi alterado.

---

## TL;DR — placar geral

| # | Pilar | Status | Próximo passo crítico |
|---|-------|--------|---------------------|
| 1 | Triple-threat (Discover + Social + Push) | 🟡 25% | Ativar meta-box do plugin web-story; integrar Graph API; conectar OneSignal ao pipeline |
| 2 | Cluster de autoridade | 🟢 80% | Cap configurável por evento (5-7 posts) |
| 3 | Pingo 5-15 min | 🟡 50% | Adicionar fontes primárias (DOU, DETRAN, TechCrunch); spike detection; filtro anti-lixo |
| 4 | IA-Propõe (95%) / Humano-valida (30s mobile) | 🟡 70% IA / 30% mobile | Cron pra fila; mobile swipe-first; geração 24h antes |
| 5 | Monetização contextual + 3 motores | 🟢 70% framework / 🔴 10% catálogo | Expandir base de 5 → 50+ ofertas; Tech sem oferta nenhuma |

**Resumo de 1 frase:** o **framework editorial está maduro** (dor, cluster, RPM, multi-site, persona, taxonomia, fila), mas faltam **3 conectores estratégicos** pra essa máquina virar receita real: (a) **fontes de dados primárias** pra sair na frente; (b) **distribuição social automática** (Face/Insta) pra Triple-threat virar tripleto; (c) **catálogo de ofertas escalado** com matching dinâmico.

---

## PILAR 1 — Triple-threat (Web Story + Social + Push)

### Web Story
**Existe:** `lib/DiscoverWebStory.php` (cliente REST do plugin), `lib/Maquina.php` orquestra. Plugin `wp-content/plugins/wp-web-stories-ai/` recebe POST em `/wp-json/wp-wsai/v1/create-story`. Gera 5-9 cenas via GPT-4o-mini. Estrutura: hook (cena 1, CTA forte) → desenvolvimento → ação. Meta key real: **`_wp_wsai_scenes`** (não `story_data` como hipotetizado).

**Gap descoberto sobre versão:** plugin instalado é **v1.0.0** (não v23 como assumimos). Os zips v19-v23 existem como backup mas o ativo é o 1.0.0. Há código no v1.0.0 que **referencia features "v24"** que não estão registradas — descasamento interno.

### 🐛 Bug #001 — cenas não aparecem ao editar (CAUSA IDENTIFICADA)
- O arquivo `wp-web-stories-ai/wp-web-stories-ai.php` linhas 30-36 **NÃO instancia `WP_WSAI_Meta_Box`**. A classe existe (em `class-wp-wsai-meta-box.php`) mas seu `init()` nunca roda.
- `admin.js` chama AJAX hooks (`wp_wsai_get_ai_draft`, `wp_wsai_change_image`, `wp_wsai_publish_final`) que **não estão registrados**.
- `single-web-story.php` renderiza AMP do `post_content`, não da meta key — edições na meta não refletem na story sem regerar.

> **Ação direta possível:** instanciar `WP_WSAI_Meta_Box` no plugin + registrar handlers AJAX. Mas pertence ao módulo `maquina` / `wp-web-stories-ai`, não ao portal. Documentar em `docs/maquina/KNOWN_ISSUES.md` quando criar a pasta.

### Instagram (Carrossel)
**Existe:** `lib/CarrosselGenerator.php` (18 KB). Gera **JPGs 1080×1350** via PHP GD em formato Instagram (4:5). 2 modos: `gerarCarrossel` (slides hero/topic/cta com brand) e `gerarFotografico` (overlay em featured image).
**Falta:** **ZERO integração com Graph API**. Apenas gera arquivos locais em temp dir, retorna paths. Publicação 100% manual.

### Facebook
**Existe:** nada. Grep por "facebook", "graph.facebook", "fb.com" → **0 hits** no projeto inteiro.

### Push (OneSignal)
**Existe:** `lib/DiscoverOneSignal.php` (9 KB). Cliente REST completo. `deveEnviar()` decide via 3 condições: `onesignal_enabled=1`, `cluster_ROI ≥ onesignal_roi_min` (default 5.0), `site == onesignal_site_target`. Métricas: `recipients`, `notification_id`.
**Gap:** classe pronta, **mas nada a chama automaticamente**. Provavelmente deveria estar em `Maquina.php` pós-publicação — não está hookado.

### Gaps prioritários do Pilar 1
1. **[HIGH]** Web Story v1.0.0: meta-box e AJAX hooks não registrados → cenas inacessíveis pro editor.
2. **[HIGH]** Instagram: CarrosselGenerator gera arte mas não publica.
3. **[HIGH]** Facebook: ausente. Plataforma 100% descoberta.
4. **[MED]** OneSignal: pronto mas desconectado do pipeline.

---

## PILAR 2 — Cluster de autoridade

**Existe:** `lib/DiscoverCluster.php` (19 KB) faz silo via `evento_fonte`. Hub = post de maior `score_discover`. Satélites recebem links inline (3 irmãos) + bloco "Veja também" no fim. Schema `ItemList` gerado. `DiscoverClusterMatcher` classifica em 13 clusters editoriais.

**Falta:**
- **Cap de tamanho:** sem limite — se cluster tem 20 posts aprovados, vira 20 publicações = dilui autoridade ao invés de concentrar (visão pede 5+ posts, mas com **piso, não teto**).
- **Política de "fechamento":** nenhuma lógica que marca cluster como "fechado" e re-organiza autoridade quando vira hub muito antigo.

### Gaps prioritários do Pilar 2
1. **[MED]** Cap configurável por evento (5-7 posts).
2. **[LOW]** Re-balanceamento automático quando hub muda (detalhe de longo prazo).

---

## PILAR 3 — Pingo 5-15 min

### Cadência
**Existe:** `pingo.php` na raiz (498L) + `scripts/pingo.php` + `lib/DiscoverPingo.php` (22 KB) + `lib/PingoRssParser.php`. Sistema cron-driven. Cada fonte tem `intervalo_min` próprio (15-30 min) configurado em `data/fontes_pingo.json`.

**Falta:**
- **Diferenciação por tipo:** sem lógica "Breaking 5 min · Tech 15 min · Lifestyle 60 min". Tudo no intervalo da fonte.
- **Spike detection:** `growth_pct` sempre = 0 (linha 311 de DiscoverPingo) — nenhuma integração com `GoogleTrends.php` pra detectar "+50% em 15 min".
- **Webhook listener:** pull-only via cron. Sem push de fontes externas.

### Fontes
**Existem 13** em `data/fontes_pingo.json`:

| Cobertas | Não cobertas |
|----------|--------------|
| Google News BR (Geral, Business, Tech) | DOU (Diário Oficial) |
| G1 (Economia, Política, Educação, Carros) | Gov.br |
| InfoMoney, Tecmundo | DETRAN, CONTRAN |
| Google News Câmara/Senado, Saúde, INSS, Vestibulando Web | Câmara/Senado RSS oficial |
| | Valor Investe |
| | TechCrunch, The Verge, 9to5Mac |
| | GitHub Trends |
| | HardMob, Pelando |

> **Crítico:** todas as fontes "oficiais" (Câmara, INSS, etc) entram via **proxy do Google News**, não fonte primária. Perde 5-15 min de janela.

### Filtros e cruzamento
**Falta:**
- **Cruzamento de sinais** ("G1 postou X **E** Trends +40%"): não existe.
- **Filtro de verbo de ação** ("Como", "Onde", "Prazo"): não existe — só rejeita `mb_strlen < 8`.
- **Pingo preditivo (markdown 24h antes):** `DiscoverCalendario` lista eventos mas **não está conectado ao pingo** — é só catálogo pra UI.

### Stack técnica
- RSS via `SimpleXML` (PingoRssParser)
- Sem headless browser (Puppeteer/Playwright) → sites gov que retornam HTML em vez de RSS quebram silenciosamente
- Sem retry/backoff em 429
- Cache TTL 30 dias, dedup por SHA1 do link
- User-Agent: `Mozilla/5.0 DiscoverPingo/1.0`
- Lock por arquivo (LOCK_EX) — bom

### Gaps prioritários do Pilar 3
1. **[CRÍTICO]** Adicionar 8-10 fontes primárias governamentais (DOU, Gov.br, DETRAN, Câmara/Senado RSS oficiais).
2. **[CRÍTICO]** Spike detection — cruzar fonte primária + Trends growth_pct real.
3. **[ALTO]** Filtro de verbo de ação (regex pré-classificação).
4. **[ALTO]** Conectar `DiscoverCalendario` ao pingo → markdown gerado 24h antes de evento previsível.
5. **[MED]** Headless browser pra fontes que não têm RSS válido.
6. **[MED]** Tech secundárias (TechCrunch, Verge, GitHub Trends, HardMob, Pelando).

---

## PILAR 4 — IA-Propõe (95%) / Humano-valida (30s mobile)

### IA Propõe — atual ~70%
**Existe:**
- `DiscoverClusterMatcher::detectar()` — classifica em 13 clusters + atribui persona/ângulos/compliance.
- `ClusterAngleAllocator::alocar()` — chama Claude pra distribuir ângulos por item (com fallback determinístico).
- `DiscoverGerador::gerar()` (53 KB!) — pipeline scrape → LLM → HTML.
- `DiscoverPostProcess::processar()` (84 KB!) — pós-processo (datas, jargão, interlinks, schemas).

**Falta da promessa de "95%":**
- IA **não escolhe headline final** — entrega 5 alternativas via reviewer, mas humano clica.
- IA **não escolhe imagem** — fonte externa, sem seleção contextual.
- IA **não escolhe oferta de afiliado** — matching automático mas humano não vê opções pra confirmar.
- IA **não decide** "ofereço push? gero web story? gero carrossel?" — está hardcoded ou desconectado.

### Humano Valida — atual ~30%
**Existe:**
- Portal tem CSS responsivo (`@media (max-width:768px)` com tabela virando cards).
- Modal de revisão com 5 títulos + 3 aberturas + 5 frases de impacto alternativas.

**Falta:**
- **Mobile-first swipe estilo Tinder:** Grep por "swipe" → 0 hits. UI atual é botões + modal nativa.
- **Aprovação em 30s:** confirmações longas com `confirm()` bloqueante explicando pipeline (30-90s). UX rica em texto, ruim em velocidade.
- **App PWA / mobile dedicado:** não existe. Usuário usa portal.php no celular.

### Fila e cron
**Existe:** `lib/DiscoverFila.php` — fila em `data/fila/<site>.json`, lock por arquivo, idempotente.
**Falta:**
- **NENHUM CRON ENCONTRADO.** `cli.php` é entry point manual. Fila é tickada **pelo navegador via polling JS** (`tickLoop` no portal.php JS). **Se usuário fecha aba, fila para indefinidamente.**

### Calendário preditivo
**Existe:** `DiscoverCalendario` (22 KB) — catálogo de 30+ eventos com `cluster` array de 3-5 títulos sugeridos.
**Falta:** **automação de geração antes do evento.** Hoje é reativo: usuário clica "Salvar cluster" → títulos viram trends → fila gera. Sem "24h antes, draft pronto".

### Gaps prioritários do Pilar 4
1. **[CRÍTICO]** Cron pra fila (backend tick a cada 2 min). Sem isso, todo o resto depende de aba aberta.
2. **[CRÍTICO]** Geração preditiva 24h antes (cron + DiscoverCalendario + DiscoverGerador).
3. **[ALTO]** Mobile-first swipe (PWA ou nova rota `pages/mobile.php`).
4. **[ALTO]** IA escolhe headline final automaticamente (com regenerar manual como fallback).
5. **[MED]** Confirmações sem `confirm()` bloqueante — toast + undo.

---

## PILAR 5 — Monetização contextual + 3 motores

### Framework de dor
**Existe — completo:** `DiscoverPainClassifier.php` (11 KB). 4 dores (Urgência, Medo, Dinheiro, Oportunidade) com regex word-boundary contra ~80-100 keywords cada, peso 1-3. Output: `{urgencia, medo, dinheiro, oportunidade, dominante, emoji}`. Saturado em 0-10. Propaga para `DiscoverSinaisEditoriais` → `DiscoverRPM` → prompt do LLM via `instrucaoProPrompt()`.

**Falta:** matriz de keywords é manual. Termos novos exigem editar regex hardcoded.

### Matchmaker de oferta
**Existe:** `DiscoverAfiliados::matchear()` faz scoring aditivo (cluster +10, dor +5, keyword no termo +2 cada). Threshold mínimo 5 pontos.

**Falta — brutal:** **`data/afiliados.json` tem APENAS 5 OFERTAS:**

| Oferta | Cluster | Dor | RPM | Ticket |
|--------|---------|-----|-----|--------|
| amazon-geral | curiosidades_geral | qualquer | 4% | R$ 120 |
| emprestimo-consignado | negocios_financas | dinheiro/urgencia/medo | 2% | R$ 8.000 |
| curso-concurso-publico | noticias_info_critica | oportunidade | 40% | R$ 197 |
| seguro-auto-cotacao | automoveis | medo/dinheiro | 8% | R$ 1.800 |
| passagem-aerea-decolar | viagem_transporte | oportunidade/urgencia | 3% | R$ 1.200 |

**Cluster `tecnologia` (RPM 16) não tem NENHUMA oferta cadastrada.** Tech vira AdSense default.

### RPM ajustado
**Existe:** `DiscoverRPM.php` calcula `rpm_base × dor_boost`:
- `rpm_base` por cluster (taxonomia define 5-42 por cluster)
- `dor_boost` 1.0-1.30 (urgencia=1.30, medo=1.25, dinheiro=1.20, oportunidade=1.15, nenhuma=1.0)
- `arbitragem_score` 0-100 = 50% RPM + 30% qualidade + 20% intensidade dor

**Falta:**
- RPM é **fixo por cluster** — não lê CTR real de `afiliados_clicks.json`, não reage a sazonalidade ou competição.
- `arbitragem_score` é calculado mas **não bloqueia geração** — não há "se score < limiar, pula".

### Multi-site
**Existe — descoberta importante:** **6 sites configurados em `sites.php`** (não 4 como achávamos):

| Site | Nicho | Persona |
|------|-------|---------|
| `comocomprar` | Consumo/shopping | Comparador + cashback |
| `vagasebeneficios` | Benefícios públicos | Jornalismo de serviço |
| `cursosenac` | Educação/ENEM | Mentor de carreira |
| `guiadoscursos` | Cursos pagos | Pragmático ROI |
| `leaodabarra` | IR/MEI | Consultor tributário |
| `ondecompraragora` | Promoções relâmpago | Caçador de oferta |

> Os 4 domínios da memória `project_dominios.md` estão CORRETOS conceitualmente, mas há **2 sites adicionais** (`leaodabarra`, `ondecompraragora`). Memória precisa de update.

**Personas únicas por site:** sim — `persona.autor`, `voz`, `especialidade`, `audiencia`, `tom`, `termos_proibidos`. Estratégia multi-site é REAL e implementada.

**Falta:** ofertas não exploram filtro `sites: [...]` — Amazon é universal, consignado sem restrição. Multi-monetização per-site não é realmente segmentada.

### 3 motores em ação
**Mapeamento atual** (implícito via cluster):
- 💰 **FINANÇAS** → `negocios_financas` (RPM 42) → consignado (1 oferta)
- 📱 **TECH** → `tecnologia` (RPM 16) → **ZERO ofertas** ← gap crítico
- ⚖️ **LEIS/UTILIDADE** → `noticias_info_critica` (RPM 24) + `saude_bem_estar` (RPM 32) → curso-concurso (40% Hotmart)

**Falta:** roteamento explícito "motor → ofertas". `afiliados.json` não tem campo `motores: ['financas', 'tech', 'leis']`.

### Gaps prioritários do Pilar 5
1. **[CRÍTICO]** Catálogo de ofertas: 5 → 50+. Tech tem zero. Finanças tem 1 (faltam FGTS antecipação, cartão p/ negativado, limpa-nome).
2. **[ALTO]** RPM dinâmico (ler `afiliados_clicks.json`, ajustar live).
3. **[ALTO]** Bloqueio por arbitragem mínima — não gerar artigo se RPM esperado < limiar.
4. **[MED]** Roteamento explícito motor → oferta (campo no JSON).
5. **[MED]** Validar multi-site com oferta restrita a 1 site (`sites:[]` real).

---

## Priorização cross-pilar (proposta)

Listagem dos gaps **do mais barato/alto-impacto pro mais caro/longo prazo:**

### Camada 0 — bug crítico identificado (1 dia)
1. **Bug #001 web story** — instanciar `WP_WSAI_Meta_Box` + registrar AJAX hooks no plugin. Causa raiz mapeada.

### Camada 1 — quick wins (1 semana)
2. **Cron pra fila** — backend tick a cada 2 min. Sem isso, fila depende de aba aberta. Resolve 1 dor enorme do operador.
3. **Filtro de verbo de ação no pingo** — regex simples, evita lixo.
4. **Adicionar 5 fontes primárias governamentais** (DOU, Gov.br, DETRAN, Câmara, Senado RSS oficiais).
5. **Conectar OneSignal ao pipeline** (hook em Maquina pós-publicação).

### Camada 2 — média complexidade (2-4 semanas)
6. **Spike detection** — integrar Trends growth_pct real ao pingo.
7. **Geração preditiva 24h antes** — cron + DiscoverCalendario + DiscoverGerador.
8. **Catálogo de ofertas: 5 → 50+** com matching por motor/dor (Hotmart/Shopee/Amazon/Awin).
9. **Cluster com cap configurável** (5-7 posts por evento).

### Camada 3 — investimento estratégico (1-3 meses)
10. **Mobile-first swipe (PWA)** pro fluxo "Humano valida em 30s" virar real.
11. **Integração Instagram + Facebook (Graph API)** — Triple-threat completo.
12. **RPM dinâmico** lendo `afiliados_clicks.json` em tempo real.
13. **Tech secundárias** (TechCrunch, Verge, GitHub Trends, HardMob, Pelando).

---

## Apêndice — descobertas que mudam memórias

1. **Plugin web-story é v1.0.0**, não v23. Os zips v19-v23 são histórico/backup. Atualizar `KNOWN_ISSUES.md` do portal.
2. **6 sites em produção** (`comocomprar`, `vagasebeneficios`, `cursosenac`, `guiadoscursos`, `leaodabarra`, `ondecompraragora`), não 4. Atualizar `project_dominios.md`.
3. **`DiscoverPostProcess.php` (84 KB)** é o pós-processador heavy — pesa mais que `DiscoverGerador.php` (53 KB!). Vale uma doc própria quando tocar nele.
4. **`TrendsTaxonomia.php` (49 KB)** define 13 clusters editoriais com mapeamento RPM/dor/persona/compliance — **fonte da verdade** pra qualquer decisão editorial.
5. **`scripts/pingo.php`** ≠ `pingo.php` (raiz). Pelo menos 2 entry points distintos pro pingo. Investigar diferença em sessão futura.
6. **Bug #001** tem causa raiz identificada (meta-box não instanciada no plugin) — pronto pra correção quando documentar módulo `maquina`.
