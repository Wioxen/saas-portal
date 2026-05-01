# Deploy Runbook — Clonais Work

> Roteiro pra subir o SaaS em produção. Linux server (Ubuntu 22.04+ recomendado).
> Pré-requisito: 9 smokes passando localmente (`scripts\check_pre_deploy.bat` ou `.sh`).

---

## 0. Sanity check local (antes de subir)

```bash
# Windows
scripts\check_pre_deploy.bat
# Linux (com php no PATH)
./scripts/check_pre_deploy.sh
```

Esperado: `TODOS OS 9 SMOKES PASSARAM`. Se algum FAIL, **NÃO subir** — diagnose primeiro.

---

## 1. Pré-requisitos do servidor

### Pacotes mínimos
```bash
sudo apt update && sudo apt install -y \
    php8.2-cli php8.2-curl php8.2-mbstring php8.2-xml php8.2-zip \
    php8.2-gd php8.2-apcu \
    git unzip cron rsync
```

### Verificações
```bash
php -v                          # >= 8.0 (8.2 recomendado)
php -m | grep -E 'curl|gd|apcu' # todos presentes
php -i | grep apc.enable_cli    # = 1 (precisa pra cache APCu em CLI)
```

### Habilitar APCu pra CLI (se 0)
Editar `/etc/php/8.2/cli/conf.d/20-apcu.ini`:
```
apc.enabled=1
apc.enable_cli=1
apc.shm_size=64M
```

---

## 2. Upload do código

```bash
# Estrutura recomendada
sudo mkdir -p /var/www/clonais
sudo chown $USER:www-data /var/www/clonais
cd /var/www/clonais

# Opção A: clone (se tem repo)
git clone <repo-url> .

# Opção B: rsync local → server
rsync -av --exclude='.git' --exclude='data/' --exclude='.env' \
    /caminho/local/apiclaudephp/ usuario@server:/var/www/clonais/

# Permissões
sudo chmod -R 775 data/ logs/ 2>/dev/null
sudo chown -R www-data:www-data data/ logs/ 2>/dev/null
```

### Diretórios que precisam ser graváveis
```
data/                       # JSONs principais
data/locks/                 # CronLock
data/circuit/               # CircuitBreaker
data/cache/                 # caches
data/post_performance/      # JSONL B2
data/click_log/             # JSONL C1
data/discover_trends_archive/  # P0-1 arquivamento mensal
data/predictor_state.json   # B4
data/cluster_paused.json    # B5
data/health_webhook_state.json
```

---

## 3. .env (`/var/www/clonais/.env`)

Copiar `.env.example` e preencher. Lista completa do que preencher:

### Database — MariaDB (RECOMENDADO em prod)
```ini
DB_DRIVER=mysql
DB_HOST=mariadb
DB_PORT=3306
DB_NAME=clonais_saas
DB_USER=clonais_saas
DB_PASS=<senha forte>
DB_CHARSET=utf8mb4
```

**Setup MariaDB no EasyPanel** (executar 1× antes do primeiro deploy):
```sql
-- Conectar como root
CREATE DATABASE clonais_saas DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'clonais_saas'@'%' IDENTIFIED BY '<senha forte>';
GRANT ALL PRIVILEGES ON clonais_saas.* TO 'clonais_saas'@'%';

-- Acesso READ-ONLY nas DBs WP (pra sync_clicks via SQL nativo no futuro)
GRANT SELECT ON wp_cursosenac.wp_cc_click_events    TO 'clonais_saas'@'%';
GRANT SELECT ON wp_guiadoscursos.wp_cc_click_events TO 'clonais_saas'@'%';
GRANT SELECT ON wp_vagasebeneficios.wp_cc_click_events TO 'clonais_saas'@'%';
GRANT SELECT ON wp_comocomprar.wp_cc_click_events   TO 'clonais_saas'@'%';
GRANT SELECT ON wp_ondecompraragora.wp_cc_click_events TO 'clonais_saas'@'%';
GRANT SELECT ON wp_leaodabarra.wp_cc_click_events   TO 'clonais_saas'@'%';
FLUSH PRIVILEGES;
```

**Aplicar migrations** (ANTES do primeiro pingo):
```bash
php scripts/db_migrate.php --status   # confere o que está pendente
php scripts/db_migrate.php            # aplica
```

**Migrar dados existentes do JSON** (se já tem dados em `data/discover_trends.json`):
```bash
php scripts/migrar_json_para_db.php --dry-run   # preview
php scripts/migrar_json_para_db.php             # aplica
```

JSON files ficam intactos no disco (rollback: `DB_DRIVER=json` no .env).

### Database — JSON (dev local OU rollback)
```ini
DB_DRIVER=json
# DB_HOST/PORT/NAME/USER/PASS ignorados
```

### LLM (obrigatório)
```ini
ANTHROPIC_API_KEY=sk-ant-api03-...
ANTHROPIC_MODEL=claude-sonnet-4-6
OPENAI_API_KEY=sk-proj-...
OPENAI_MODEL=gpt-4o-mini
DEFAULT_LLM=claude
```

### Scraping
```ini
SERPER_API_KEY=...
```

### Health webhook (opcional mas RECOMENDADO)
```ini
HEALTH_WEBHOOK_ENABLED=1
# Use UM dos dois (ou ambos):
DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/.../...
TELEGRAM_BOT_TOKEN=123456:ABC...
TELEGRAM_CHAT_ID=-100123456789
```

### Health endpoint público (opcional)
```ini
SAUDE_TOKEN=<gerar 32 chars random>  # se setado, ?token=X expõe detalhes
```

### Meta (FB Page + IG) — só se vai postar nas redes
```ini
FB_PAGE_TOKEN_MARIA=EAAR_...
IG_TOKEN_CURSOSENAC=IGAA_...
IG_TOKEN_GUIADOSCURSOS=IGAA_...
IG_TOKEN_VAGASEBENEFICIOS=IGAA_...
```

### Push (OneSignal — opcional)
```ini
ONESIGNAL_APP_ID=...
ONESIGNAL_REST_API_KEY=...
ONESIGNAL_ENABLED=1
ONESIGNAL_ROI_MIN=5.0
```

### Outros
```ini
AMAZON_AFFILIATE_URL=https://amzn.to/4ckOgUc
WHATSAPP_NUMBER=...
SITE_NAME=Clonais Work
```

### Permissões
```bash
chmod 600 .env
chown www-data:www-data .env
```

---

## 4. Plugins WordPress (cada um dos 6 sites)

### Plugins obrigatórios
| Plugin | Arquivo | Função | ZIP |
|---|---|---|---|
| **CC Meta Bridge** ⚠️ | `plugin/cc-meta-bridge.php` | **OBRIGATÓRIO**: registra meta keys SEO via REST. Sem ele, MetaSwapper falha silenciosamente | `cc-meta-bridge-v1.zip` |
| **CC PrettyLinks API** | `plugin/cc-prettylinks-api.php` | REST endpoint pra criar Pretty Links | `cc-prettylinks-api-v1.zip` |
| **CC News Sitemap** | `plugin/cc-news-sitemap.php` | `/news-sitemap.xml` (Discover) | `cc-news-sitemap-v1.zip` |
| **CC Click Logger** | `plugin/cc-click-logger.php` | Captura clicks por post_id (revenue attribution) | `cc-click-logger-v1.zip` |
| **CC Smart Infeed** | `plugin/cc-smart-infeed.php` | Bloco de oferta in-feed | `cc-smart-infeed-v1.zip` |
| **CC Move JSON-LD Footer** | `plugin/cc-move-jsonld-footer.php` | Schema.org no fim do `<body>` | `cc-move-jsonld-footer-v1.zip` |
| **CC Clean Empty P** | `plugin/cc-clean-empty-p.php` | Remove `<p></p>` vazios | `cc-clean-empty-p-v1.zip` |
| **CC Instant Indexing API** | `plugin/cc-instant-indexing-api.php` | IndexNow (Bing/Yandex) | `cc-instant-indexing-api-v1.zip` |
| **CC Speculation Rules** | `plugin/cc-speculation-rules.php` | Pre-render de links no hover (Chrome/Edge) → navegação interna instantânea | `cc-speculation-rules-v1.zip` |

### Plugins de terceiros que precisam estar instalados
- **Pretty Links** (free, do repositório WP) — base do CC PrettyLinks API
- **Yoast SEO** OU **Rank Math** OU **SEOPress** (qualquer um dos 3) — meta tags SEO. CC Meta Bridge cobre os 3 schemas.
- **Cloudflare for WordPress** (opcional, free) — purge automático de cache em editor changes (complementa nosso CloudflareCachePurge)

### Instalação (cada site)

**Opção A — upload manual** (8 ZIPs já estão prontos em `plugin/`):
```
plugin/cc-meta-bridge-v1.zip          ← OBRIGATÓRIO instalar antes de qualquer geração
plugin/cc-prettylinks-api-v1.zip
plugin/cc-news-sitemap-v1.zip
plugin/cc-click-logger-v1.zip
plugin/cc-smart-infeed-v1.zip
plugin/cc-move-jsonld-footer-v1.zip
plugin/cc-clean-empty-p-v1.zip
plugin/cc-instant-indexing-api-v1.zip
plugin/cc-speculation-rules-v1.zip
```
Upload via WP Admin → Plugins → Adicionar Novo → Enviar. **Ativar todos.**

**Validação pós-instalação (importante):**
```bash
# Health check do meta-bridge (deve retornar registered_keys: 21)
curl https://SEU-SITE.com/wp-json/cc-meta-bridge/v1/health
```

**Opção B — wp-cli (mais rápido pra 6 sites)**:
```bash
# No servidor onde o WP está hospedado:
cd /var/www/cursosenacgratuito
wp plugin install /path/to/cc-news-sitemap-v1.zip --activate
wp plugin install /path/to/cc-click-logger.php --activate  # se for arquivo solo
# repete pros outros 6 plugins
```

### Após ativar
Cada plugin pode ter **activation hook**. Confirmar:

```bash
# cc-click-logger criou tabela?
wp db query "SHOW TABLES LIKE 'wp_cc_click_events'"
# cc-news-sitemap respondeu?
curl -I https://cursosenacgratuito.com.br/news-sitemap.xml
```

---

## 5. Cron tab Linux (`crontab -e` como user www-data ou similar)

```cron
# ────── Captura de trends + scoring ──────
*/5  * * * * /usr/bin/php /var/www/clonais/scripts/preditor_snapshot.php --quiet >> /var/log/clonais/preditor.log 2>&1
*/10 * * * * /usr/bin/php /var/www/clonais/scripts/spike_detect.php --quiet >> /var/log/clonais/spike.log 2>&1
*/10 * * * * /usr/bin/php /var/www/clonais/scripts/pingo.php --site=cursosenac --quiet >> /var/log/clonais/pingo.log 2>&1
*/15 * * * * /usr/bin/php /var/www/clonais/scripts/pingo.php --site=vagasebeneficios --quiet >> /var/log/clonais/pingo.log 2>&1
*/15 * * * * /usr/bin/php /var/www/clonais/scripts/pingo.php --site=guiadoscursos --quiet >> /var/log/clonais/pingo.log 2>&1
*/15 * * * * /usr/bin/php /var/www/clonais/scripts/pingo.php --site=comocomprar --quiet >> /var/log/clonais/pingo.log 2>&1
*/15 * * * * /usr/bin/php /var/www/clonais/scripts/pingo.php --site=ondecompraragora --quiet >> /var/log/clonais/pingo.log 2>&1
*/15 * * * * /usr/bin/php /var/www/clonais/scripts/pingo.php --site=leaodabarra --quiet >> /var/log/clonais/pingo.log 2>&1

# ────── Geração + publicação (fila) ──────
*/2  * * * * /usr/bin/php /var/www/clonais/scripts/tick_filas.php --quiet >> /var/log/clonais/tick.log 2>&1

# ────── Sitemap + indexação ──────
0    * * * * /usr/bin/php /var/www/clonais/scripts/submeter_news_sitemaps.php --quiet >> /var/log/clonais/sitemap.log 2>&1

# ────── Performance + revenue ──────
30   5 * * * /usr/bin/php /var/www/clonais/scripts/post_performance_snapshot.php --quiet >> /var/log/clonais/perf.log 2>&1
0   */4 * * * /usr/bin/php /var/www/clonais/scripts/sync_clicks.php --quiet >> /var/log/clonais/clicks.log 2>&1

# ────── Auto-refresh + reviewer ──────
0    4 * * * /usr/bin/php /var/www/clonais/scripts/auto_refresh_posts.php --quiet >> /var/log/clonais/refresh.log 2>&1

# ────── Aprendizado + relatórios semanais ──────
0    6 * * 1 /usr/bin/php /var/www/clonais/scripts/gsc_aprender.php --quiet >> /var/log/clonais/aprender.log 2>&1
30   6 * * 1 /usr/bin/php /var/www/clonais/scripts/cluster_killer.php --quiet >> /var/log/clonais/killer.log 2>&1
0    7 * * 1 /usr/bin/php /var/www/clonais/scripts/relatorio_performance.php --webhook --quiet >> /var/log/clonais/relatorio.log 2>&1

# ────── Manutenção ──────
30   3 * * * /usr/bin/php /var/www/clonais/scripts/cache_eviction.php --quiet >> /var/log/clonais/cache.log 2>&1
0    4 1 * * /usr/bin/php /var/www/clonais/scripts/arquivar_trends.php --quiet >> /var/log/clonais/arquivar.log 2>&1
0    5 * * * /usr/bin/php /var/www/clonais/scripts/backup_state.php --quiet >> /var/log/clonais/backup.log 2>&1
0    2 * * * /usr/bin/php /var/www/clonais/scripts/pruning_posts_antigos.php --quiet >> /var/log/clonais/pruning.log 2>&1

# ────── Sazonal (1× ao dia, top of hour pra evitar pile-up) ──────
0    9 * * * /usr/bin/php /var/www/clonais/scripts/antecipar_sazonal.php --quiet >> /var/log/clonais/sazonal.log 2>&1

# ────── SERP Intelligence + Content Depth ──────
*/15 * * * * /usr/bin/php /var/www/clonais/scripts/internal_link_retroativo.php --quiet >> /var/log/clonais/retrolink.log 2>&1
*/30 * * * * /usr/bin/php /var/www/clonais/scripts/cluster_expander.php --quiet >> /var/log/clonais/cluster_expander.log 2>&1

# ────── Defesa Operacional (heartbeat) ──────
0    * * * * /usr/bin/php /var/www/clonais/scripts/heartbeat_check.php --quiet >> /var/log/clonais/heartbeat.log 2>&1
```

### Diretórios de log
```bash
sudo mkdir -p /var/log/clonais
sudo chown www-data:www-data /var/log/clonais
```

### Logrotate (opcional mas recomendado)
`/etc/logrotate.d/clonais`:
```
/var/log/clonais/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    create 0644 www-data www-data
}
```

---

## 6. Cron WP em cada site (TTL do click-logger)

O plugin `cc-click-logger` agenda WP-Cron automaticamente, mas WP-Cron depende de tráfego.
Pra garantir execução, desabilitar WP-Cron interno e chamar via crontab:

`wp-config.php` (cada site):
```php
define('DISABLE_WP_CRON', true);
```

Crontab Linux (cada site):
```cron
*/5 * * * * /usr/bin/wget -qO- https://cursosenacgratuito.com.br/wp-cron.php?doing_wp_cron >/dev/null 2>&1
*/5 * * * * /usr/bin/wget -qO- https://guiadoscursos.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
# repete pros 6 sites
```

---

## 7. Sanity check pós-deploy

### Health check público
```bash
curl https://saas.dominio.com/saude.php
# Esperado: HTTP 200 + JSON com "ok": true
```

Com token (detalhado):
```bash
curl "https://saas.dominio.com/saude.php?token=$SAUDE_TOKEN&wp=1"
```

### Smokes via SSH
```bash
cd /var/www/clonais
./scripts/check_pre_deploy.sh
# Esperado: TODOS OS 9 SMOKES PASSARAM
```

### Crons rodando
```bash
# Watch logs primeiros 30min
tail -f /var/log/clonais/*.log

# Verifica que pingo está populando DB
php -r "require '/var/www/clonais/lib/DiscoverDb.php'; echo (new DiscoverDb())->count() . PHP_EOL;"
```

### Plugins WP funcionando
```bash
# News sitemap
curl -I https://cursosenacgratuito.com.br/news-sitemap.xml

# Click logger REST (precisa Application Password no header)
curl -H "Authorization: Basic $(echo -n 'admin:wp_app_pwd' | base64)" \
    "https://cursosenacgratuito.com.br/wp-json/cc/v1/clicks/recent?since=0&limit=5"
```

---

## 8. Primeiro post (validação E2E)

```bash
# Forçar pingo + tick filas + observar log
php /var/www/clonais/scripts/pingo.php --site=cursosenac --force
php /var/www/clonais/scripts/tick_filas.php --max=1 --site=cursosenac

# Verificar publicação no WP
wp post list --post_status=publish --post_type=post --format=ids | head -1
```

---

## 9. Observabilidade contínua

### Webhook recebendo alertas
Force um circuit breaker manualmente pra testar:
```bash
php -r "require '/var/www/clonais/lib/CircuitBreaker.php'; \$cb = new CircuitBreaker('test_alert'); \$cb->falha('teste'); \$cb->falha('teste'); \$cb->falha('teste');"
```
Discord/Telegram deve receber alerta em <30s.

Limpar depois:
```bash
rm /var/www/clonais/data/circuit/test_alert.json
```

### Disco
```bash
df -h /var/www/clonais
du -sh /var/www/clonais/data/*
```

### MySQL (sites WP)
```bash
mysql -e "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH/1024/1024 AS MB
          FROM information_schema.TABLES
          WHERE TABLE_NAME LIKE '%cc_click%'
          ORDER BY DATA_LENGTH DESC;"
```

---

## 10. Rollback

### Código
```bash
cd /var/www/clonais
git log --oneline -10
git checkout <commit-anterior>
```

### Dados
JsonStore mantém 5 backups por arquivo. Restaurar manualmente:
```bash
ls /var/www/clonais/data/discover_trends.json.bak.*
cp /var/www/clonais/data/discover_trends.json.bak.20260428_120000 \
   /var/www/clonais/data/discover_trends.json
```

OU via lib:
```bash
php -r "require '/var/www/clonais/lib/JsonStore.php'; \
    var_dump(JsonStore::restore('/var/www/clonais/data/discover_trends.json'));"
```

### Pausa total (emergência)
```bash
# Suspende todos os crons
sudo systemctl stop cron
# OU comenta linhas no crontab
crontab -l | sed 's|^|#|' | crontab -
```

---

## 11. Checklist final pré-go-live

- [ ] `check_pre_deploy.sh` 9/9 verde no servidor
- [ ] `.env` preenchido + `chmod 600`
- [ ] `data/` writable por `www-data`
- [ ] Plugins WP ativos em **todos os 6 sites**
- [ ] `cc-click-logger` criou tabela (`SHOW TABLES LIKE 'wp_cc_click_events'`)
- [ ] News sitemap acessível em **todos os 6 sites**
- [ ] Crontab carregado (`crontab -l | wc -l` ≥ 18 linhas)
- [ ] Logrotate em `/etc/logrotate.d/clonais`
- [ ] `saude.php` HTTP 200
- [ ] Webhook (Discord/Telegram) recebendo alertas de teste
- [ ] Service Account Google (GSC API) configurada como user em **todos os 6 Search Console properties**
- [ ] WhatsApp / Meta Page tokens válidos (testar 1× publicação manual)
- [ ] OneSignal apps criados pra sites com push (cursosenac é o ativo hoje)

---

## 12. Tuning pós-30 dias

Quando tiver dado real, calibrar:

| Onde | Setting | Default | Como decidir |
|---|---|---|---|
| `lib/CircuitBreaker.php` | cooldown base | 300s | aumenta se Anthropic ficar fora >30min frequentemente |
| `lib/PingoPreditor.php` | `RISING_DELTA_PCT` | 50 | analisar `rising` que NÃO viralizou → subir threshold |
| `lib/ClusterKiller.php` | `MAX_CLICKS_PARA_PAUSAR` | 10 | observar quantos clusters ficam pausados — se >50% dos clusters, baixar |
| `lib/DiscoverDb.php` | `JANELA_DIAS_DEFAULT` | 60 | reduzir se memory peak alto; aumentar se relatórios precisam mais histórico |
| `scripts/cache_eviction.php` | regras `bySize` | 200/100/50 MB | observar tamanho real após 30d, ajustar |

---

## 13. Próximas evoluções (ver CHANGELOG)

- **P1** (semana 1 pós-deploy): URL canonicalização, signal-cross em Saúde, stream JSONL
- **P2** (mês 1+): SQLite migration (`discover_trends`), métricas RPM próprias, schema migration tool, rate-limit local LLM
- **B3** (pós-deploy + dado real): gap SERP scraper
- **C2** (quando começar a vender): Amazon Affiliates Reports CSV importer
- **C3** (quando configurar Hotmart): webhook receiver pra conversão

---

**Última atualização**: 2026-04-28 — pós revisão P0 (369/369 smokes verdes)
