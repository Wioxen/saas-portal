# 📍 Pickup Point — Clonais Work

> **Documento PRINCIPAL pra retomada.** Ler ESTE arquivo primeiro em qualquer nova sessão.
>
> **Última atualização**: 2026-04-29
> **Estado**: ✅ Código 100% pronto · 23 smokes verdes · 839/839 checks · ⏸ Aguardando credenciais do servidor

---

## 🎯 Onde paramos

Sistema **24 frentes entregues**, **23 smokes verdes**, **839/839 checks** automatizados.
Tudo que dependia de código está pronto. Próximo passo é **operacional**:
deploy + credenciais.

---

## 🔑 O que precisa do user pra finalizar

### 1. Servidor (EasyPanel + MariaDB)
- [ ] URL pública SaaS (ex: `https://saas.dominio.com`)
- [ ] Volume path do container PHP (ex: `/var/www/clonais` ou `/app`)
- [ ] Versão PHP (8.1 / 8.2 / 8.3)

### 2. MariaDB credenciais
- [ ] DB host (ex: `mariadb` no docker network)
- [ ] DB porta (default 3306)
- [ ] DB nome (sugestão: `clonais_saas`)
- [ ] DB user (sugestão: `clonais_saas`)
- [ ] DB senha (gerar 24+ chars)

### 3. Nomes EXATOS das DBs WP (pra GRANT SELECT cross-DB)
- [ ] cursosenac → `wp_?`
- [ ] guiadoscursos → `wp_?`
- [ ] vagasebeneficios → `wp_?`
- [ ] comocomprar → `wp_?`
- [ ] ondecompraragora → `wp_?`
- [ ] leaodabarra → `wp_?`

### 4. URLs WP confirmadas (atualizar `sites.php` se mudou)
- [ ] cursosenac: `https://cursosenacgratuito.com.br`
- [ ] guiadoscursos: `https://guiadoscursos.com`
- [ ] vagasebeneficios: `https://vagasebeneficios.com`
- [ ] comocomprar: `https://comocomprar.com.br`
- [ ] ondecompraragora: `https://ondecompraragora.com`
- [ ] leaodabarra: `https://leaodabarra.com.br`

### 5. Tokens externos
- [ ] `ANTHROPIC_API_KEY`
- [ ] `OPENAI_API_KEY`
- [ ] `SERPER_API_KEY`
- [ ] `PEXELS_API_KEY` (já tem: `yzCmPJXphWCna7SMtJhmtqghLKtnScQn0DTw338Gltx370FVfq9OHfgo`)
- [ ] `WP_APP_PASSWORD` por site (gerar em cada WP admin)
- [ ] Google Service Account JSON (`data/google_credentials.json`)
- [ ] Meta tokens (FB Page + IG) — opcional (já tem alguns)
- [ ] OneSignal tokens — opcional
- [ ] Bluesky handles + app passwords — opcional (criar 1 conta/site)
- [ ] Threads tokens (Meta Developer) — opcional
- [ ] X tokens — bloqueado por dev portal, **deixar pra depois**

### 6. Webhook (opcional mas recomendado)
- [ ] Discord webhook URL OU Telegram bot+chat → `HEALTH_WEBHOOK_ENABLED=1`

### 7. Backup off-site (opcional mas recomendado)
- [ ] S3-compatible (AWS / DigitalOcean Spaces / B2 / R2 / MinIO)
  - bucket, key, secret, endpoint, region

### 8. Cron method no EasyPanel
- [ ] Cron interno container OU job scheduler nativo OU container separado

---

## 📋 Quando user me devolver, faço:

1. Atualizar `.env.example` → `.env` com paths/URLs corretos
2. Ajustar `sites.php` se URL/slug mudou
3. Adaptar `crontab` no `DEPLOY_RUNBOOK` pro método EasyPanel
4. Final smoke 23/23 antes de subir
5. Documentar passos finais de ativação

---

## 🚀 Sequência de ativação no servidor (sem mim)

```bash
# 1. Sanity check local
cd /var/www/clonais
./scripts/check_pre_deploy.sh

# 2. MariaDB setup
mysql -h mariadb -u root -p < /tmp/setup_db.sql

# 3. Schema
php scripts/db_migrate.php

# 4. Migra JSON existente (se houver)
php scripts/migrar_json_para_db.php

# 5. Plugins WP em cada site (manual ou wp-cli)
# 6. Crontab Linux (do DEPLOY_RUNBOOK seção 5)
# 7. Health check
curl https://saas.dominio.com/saude.php
```

---

## 📊 Inventário do que está pronto

### Lib (40+ módulos)
- **Resiliência**: JsonStore, CronLock, CircuitBreaker, CacheManager, Saude, HealthWebhook
- **DB**: DiscoverDb (facade) + DiscoverDbMysql + DbConnection + migrations
- **Discover/E-E-A-T**: Schemas, RelatedLinks, TrustBlocks, AiOverview, UpdateBadge, QuoteEnrichment, AfiliadoLinkBuilder, HubAutoUpdate, AuthorityLinks, etc
- **SERP Intelligence**: CtrIntel (autocomplete+related+PAA), SerpAnalyzer (top10 análise), ClusterExpander (silo), UpdateDetector (anti-canibal), InternalLinkRetro (backlinks contextuais)
- **CTR Pack**: FaqEnricher (FAQPage schema garantido do PAA), TitleVariantes (3 títulos pré-gerados), TitleSwapper (A/B sequencial via GSC: troca título quando CTR<1% + pos top 10 + idade>=7d)
- **Pré-deploy Pack**: CostGuard (cap diário Claude/Serper, defesa runaway), P1Variantes/P1Swapper (A/B do snippet do Discover, 2ª opção depois do Title Swap), AfiliadoBR (detector de URLs marketplace inventadas + warning log; fluxo é PrettyLinks-only)
- **CTR/SERP Pack**: Featured Snippet Hijack (estrutura paragraph/list/table forçada quando snippet detectado no SERP), DiscoverMetaTags (og_title 90c + meta_description 155c + 2 variantes via Yoast/RankMath/SEOPress), DiscoverMetaSwapper (4ª na hierarquia A/B: Title→P1→Meta→Reviewer)
- **Defesa Operacional**: KillSwitch (.env PIPELINE_PAUSED → para tudo), heartbeat_check.php (cron horário, alerta se site sem post >4h via HealthWebhook), Dead-Letter Queue (trend que falhou 3× consecutivas → falhado_max_retries, sai da fila)
- **Perf Edge**: DiscoverResourceHints (preconnect/dns-prefetch pras CDNs externas, -100-200ms latency mobile), CloudflareCachePurge (defensivo, no-op sem token; auto-purga URL após Title/P1/Meta swap quando configurado)
- **Inteligência viral**: Pingo (paralelo curl_multi), PingoPreditor, ScoreComposto, PreditorSazonal, ClusterMatcher, ClusterKiller, SpikeDetector, AutoRefresh
- **Quality**: FactChecker, ReadingScore, VisionAlt, ImagemSEO, HtmlValidator
- **Distribuição**: SocialPoster (orquestrador), SocialBluesky, SocialThreads, OneSignal, Meta (FB/IG)
- **Performance**: PostPerformanceLog, ClickLog, CostTracker
- **Geração**: DiscoverGerador (Sonnet), DiscoverGeradorGPT (fallback), DiscoverPostProcess, DiscoverReviewer
- **Storage off-site**: BackupOffsite (S3 Signature V4 puro)

### Scripts (cron + CLI)
- `pingo.php` (10-15min/site)
- `internal_link_retroativo.php` (15min) — injeta links pros novos posts em posts antigos
- `cluster_expander.php` (30min) — expande trends fortes em silos tópicos (3-5 filhos)
- `spike_detect.php` (10min)
- `preditor_snapshot.php` (5min)
- `tick_filas.php` (2min)
- `submeter_news_sitemaps.php` (horário)
- `post_performance_snapshot.php` (5:30am daily)
- `sync_clicks.php` (4h)
- `auto_refresh_posts.php` (4am daily)
- `sazonal_preditivo.php` (6:30am daily)
- `cluster_killer.php` (segunda 6:30am weekly)
- `cache_eviction.php` (3:30am daily)
- `arquivar_trends.php` (mensal dia 1)
- `relatorio_performance.php` (segunda 7am weekly)
- `gsc_aprender.php` (segunda 6am weekly)
- `incrementar_hubs.php` (15min)
- `refresh_precos.php` (2:30am daily)
- `anomaly_detect.php` (8am daily)
- `backup_offsite.php` (5:30am daily)
- `criar_author_pages.php` (1× setup)
- `db_migrate.php` (ad-hoc)
- `migrar_json_para_db.php` (1× setup)

### Smokes (23 — `check_pre_deploy.bat`/.sh)
1. `_smoke_test` (101) — geral
2. `_smoke_caminho_c` (64) — anti-PBN editorial
3. `_smoke_resiliencia` (42) — JsonStore + CronLock + CircuitBreaker
4. `_smoke_resiliencia_2` (31) — CacheManager + Saude
5. `_smoke_performance` (22) — PostPerformanceLog
6. `_smoke_clicks` (40) — ClickLog + AfiliadoLinkBuilder + relatório
7. `_smoke_preditor` (21) — PingoPreditor (rising signals)
8. `_smoke_cluster_killer` (20) — ClusterKiller
9. `_smoke_p0_revisao` (28) — fixes pré-escala (TZ, normalização canibal, backoff, etc)
10. `_smoke_db_mysql` (36) — driver MySQL/MariaDB via SQLite ':memory:'
11. `_smoke_pacote_8h` (42) — fórmula viral fechada
12. `_smoke_social_quality` (32) — Bluesky/Threads/FactChecker/ReadingScore
13. `_smoke_failsafe` (25) — credenciais ausentes não derrubam pipeline
14. `_smoke_roi` (38) — prompt cache + Serper cache + cost tracking + quote + vision
15. `_smoke_e2e` (22) — pipeline INTEIRO offline (8 etapas)
16. `_smoke_hardening` (26) — SAST + E2E + Backup + DR
17. `_smoke_roi_extra` (28) — WebP nativo + lazy load + Amazon tag
18. `_smoke_serp_intel` (33) — CtrIntel + SerpAnalyzer + ClusterExpander + UpdateDetector + InternalLinkRetro
19. `_smoke_title_ab` (31) — TitleVariantes + TitleSwapper + FaqEnricher + wires
20. `_smoke_pacote_pre_deploy` (45) — CostGuard + P1 Variantes/Swapper + AfiliadoBR (detector)
21. `_smoke_pacote_ctr_serp` (43) — Featured Snippet Hijack + MetaTags + MetaSwapper
22. `_smoke_pacote_defesa` (32) — KillSwitch + Heartbeat + Dead-Letter Queue
23. `_smoke_pacote_perf_edge` (36) — ResourceHints + CloudflareCachePurge defensivo

**Total: 839/839 checks ✅**

### Plugins WP próprios (9 + 9 ZIPs prontos)
- `cc-meta-bridge.php` ⚠️ **OBRIGATÓRIO** — registra meta keys SEO (Yoast/RankMath/SEOPress) via REST. Sem isso MetaSwapper falha silencioso
- `cc-prettylinks-api.php` — REST endpoint pra Pretty Links
- `cc-news-sitemap.php` — `/news-sitemap.xml` Discover
- `cc-click-logger.php` — captura clicks por post_id (revenue attribution + TTL 90d)
- `cc-smart-infeed.php` — bloco oferta in-feed
- `cc-move-jsonld-footer.php` — Schema.org no fim do `<body>`
- `cc-clean-empty-p.php` — remove `<p></p>` vazios
- `cc-instant-indexing-api.php` — IndexNow (Bing/Yandex)
- `cc-speculation-rules.php` — pre-render de links no hover (Chrome/Edge) → navegação interna 0ms

### Documentação
- `docs/DEPLOY_RUNBOOK.md` (13 seções, passo-a-passo)
- `docs/DR_RUNBOOK.md` (5 cenários, recovery <1h)
- `docs/STATUS_OPERACAO.md` (histórico de sessões)
- `docs/portal/CHANGELOG.md` (histórico mudanças, append-only)
- `docs/PICKUP_POINT.md` (este arquivo)

### Migrations SQL
- `migrations/001_initial.sql` — schema completo (trends + post_performance + click_log_summary + click_sync_state + schema_migrations)

---

## 💰 Economia esperada (vs sem otimizações)

Volume real estimado (100 posts/dia × 6 sites × 30 dias):

| Item | Sem | Com | Economia/mês |
|---|---|---|---|
| Anthropic prompt cache | ~$2k | ~$1.4k | **~$300-600** |
| Serper cache 24h | ~$160 | ~$95 | **~$65** |
| Vision alt (gasto novo) | $0 | ~$18 | -$18 |
| **NET** | | | **~$345-650** |

---

## 🎬 Próxima sessão: começar lendo este arquivo

```
1. Ler docs/PICKUP_POINT.md (este)
2. Ler docs/STATUS_OPERACAO.md (histórico)
3. Verificar memória `auto memory` (Claude Code)
4. User devolve credenciais → finalizar config → smokes 23/23 → deploy
```
