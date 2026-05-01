# Disaster Recovery Runbook — Clonais Work

> Cenário: VPS principal **morreu**. Recuperar SaaS em outro servidor em **<1 hora**.

---

## Cenários cobertos

| Cenário | Severidade | Tempo de recovery |
|---|---|---|
| **A. VPS suspenso/morto** (todo o SaaS perdido) | 🔴 crítico | 30-60min |
| **B. data/ corrompida** (DB JSON quebrado) | 🟡 alto | 5-15min |
| **C. MariaDB perdida** (1 DB ou inteira) | 🟡 alto | 10-30min |
| **D. WordPress de 1 site fora** (não SaaS) | 🟢 médio | 15-30min |
| **E. Plugin WP corrompeu (cc-click-logger, etc)** | 🟢 baixo | 5min |

---

## Cenário A — VPS principal morreu

**Sintomas**: `saude.php` 503/timeout, SSH não conecta, EasyPanel offline.

### A.1 — Provisionar novo VPS (10min)
```bash
# EasyPanel novo OU VPS Linux puro
# Especificações mínimas:
#   2 vCPU, 4GB RAM, 40GB SSD, Ubuntu 22.04+
#   PHP 8.2 + APCu + curl + mbstring + xml + zip
```

### A.2 — Restaurar code (5min)
```bash
# Se tem git:
git clone <repo> /var/www/clonais
cd /var/www/clonais

# Se NÃO tem git, restaurar via último deploy.tar.gz off-site:
mkdir -p /var/www/clonais && cd /var/www/clonais
aws s3 cp s3://clonais-backups/clonais/deploy.tar.gz - | tar xzf -
# OU via DigitalOcean Spaces / B2 — usa BACKUP_S3_ENDPOINT do .env antigo
```

### A.3 — Restaurar data/ do backup off-site (10min)
```bash
# Pré-req: BACKUP_OFFSITE_ENABLED=1 estava ativo no servidor antigo
# E cron backup_offsite.php tinha rodado nas últimas 24h

# Lista o que tem no bucket:
aws s3 ls s3://clonais-backups/clonais/ --recursive

# Sync de tudo de volta pra data/:
aws s3 sync s3://clonais-backups/clonais/ /var/www/clonais/data/

# Permissões:
sudo chown -R www-data:www-data /var/www/clonais/data
sudo chmod -R 775 /var/www/clonais/data
```

### A.4 — Restaurar .env (manual)
```bash
# .env NUNCA vai pro git nem pro backup off-site (segurança)
# Tem que ter cópia segura em: 1Password / Bitwarden / vault interno
# Se NÃO tem, recriar do zero usando .env.example + tokens das contas:
cp /var/www/clonais/.env.example /var/www/clonais/.env
nano /var/www/clonais/.env
chmod 600 /var/www/clonais/.env
```

**Tokens que precisará re-gerar/recuperar:**
- `ANTHROPIC_API_KEY` — console.anthropic.com (gera novo, revoga antigo)
- `OPENAI_API_KEY` — platform.openai.com (idem)
- `SERPER_API_KEY` — serper.dev (idem)
- `WP_APP_PASSWORD` (cada site) — WP admin → Users → Application Passwords
- `FB_PAGE_TOKEN_*` / `IG_TOKEN_*` — Meta Business Suite, regerar
- `ONESIGNAL_REST_API_KEY` — onesignal.com
- `BLUESKY_APP_PASSWORD_*` — bsky.app → Settings → App Passwords (regenerar)

### A.5 — Restaurar MariaDB clonais_saas (5-15min)
```bash
# Cenário 1: MariaDB sobreviveu (era em servidor separado / managed) → nada a fazer
# Cenário 2: MariaDB junto morreu → recuperar do backup MariaDB

# Se você tem mysqldump diário (RECOMENDADO — adicionar antes do deploy!):
mysql -h novo-mariadb -u root -p < ultimo_dump_clonais_saas.sql

# Se NÃO tem dump SQL mas tem o data/ JSON via off-site backup:
# Aplicar migration + reimportar do JSON (lento mas funciona)
php scripts/db_migrate.php
php scripts/migrar_json_para_db.php --include-archive

# Confere:
mysql -e "SELECT COUNT(*) FROM trends" clonais_saas
```

### A.6 — Reativar crons (5min)
```bash
crontab -e
# Cole o crontab do DEPLOY_RUNBOOK.md (seção 5)

# Ou se você salvou off-site:
crontab /var/www/clonais/data/crontab.bak
```

### A.7 — DNS — apontar pros novos IPs (5-30min, depende TTL)
```bash
# Painel do registrar (Registro.br, Cloudflare, etc):
#   1. Site SaaS (saas.dominio.com) → IP novo VPS
#   2. Sites WP (cursosenacgratuito.com.br, etc) → SEUS HOSTING (não muda)
#
# Cloudflare na frente acelera propagação (TTL 60s vs 24h do registrar)
```

### A.8 — Sanity check (5min)
```bash
cd /var/www/clonais
./scripts/check_pre_deploy.sh        # 14 smokes
curl https://saas.dominio.com/saude.php          # HTTP 200
curl "https://saas.dominio.com/saude.php?token=$SAUDE_TOKEN&wp=1"  # 6 sites OK
```

### A.9 — Validar pipeline E2E (10min)
```bash
# Forçar 1 trend de teste:
php /var/www/clonais/scripts/spike_detect.php --quiet

# Aguardar tick_filas processar (max 2min):
sleep 120
tail /var/log/clonais/tick.log

# Confere se publicou:
mysql -e "SELECT COUNT(*) FROM trends WHERE status='publicado' AND publicado_em > NOW() - INTERVAL 30 MINUTE" clonais_saas
```

---

## Cenário B — `data/` corrompida (DB JSON quebrado)

**Sintomas**: `saude.php` HTTP 503 com `db.ok=false`. Logs mostram `JsonStore: CORRUPCAO detectada`.

### B.1 — Auto-recovery já tentou
JsonStore tenta recovery automático lendo backups locais (5 versões). Se chegou aqui, AUTO falhou.

### B.2 — Restore manual via JsonStore
```bash
cd /var/www/clonais
php -r "
require 'lib/JsonStore.php';
\$path = 'data/discover_trends.json';
echo 'Backups disponíveis:' . PHP_EOL;
foreach (JsonStore::backups(\$path) as \$bak) {
    echo '  ' . \$bak . ' (' . date('c', filemtime(\$bak)) . ')' . PHP_EOL;
}
echo 'Restaurando mais recente...' . PHP_EOL;
var_dump(JsonStore::restore(\$path));
"
```

### B.3 — Se NÃO tem backup local válido → off-site
```bash
aws s3 cp s3://clonais-backups/clonais/discover_trends.json data/discover_trends.json
sudo chown www-data:www-data data/discover_trends.json
```

### B.4 — Validar
```bash
php -r "require 'lib/DiscoverDb.php'; echo (new DiscoverDb())->count() . PHP_EOL;"
```

---

## Cenário C — MariaDB perdida (DB clonais_saas)

### C.1 — Alternar pra driver JSON temporariamente (rollback)
```bash
# Edita .env: DB_DRIVER=json
# JSON files locais ainda estão lá (BackupOffsite mantém copia)
# Pipeline volta a funcionar IMEDIATAMENTE em modo legacy
```

### C.2 — Restaurar MariaDB
```bash
# Se tem mysqldump diário (recomendado — adicionar ao crontab):
mysql -e "DROP DATABASE clonais_saas; CREATE DATABASE clonais_saas DEFAULT CHARSET utf8mb4"
mysql clonais_saas < /backups/mysql/clonais_saas_$(date +%Y-%m-%d).sql

# Se NÃO tem dump:
# 1. Aplica schema do zero
php scripts/db_migrate.php

# 2. Reimporta do JSON (que sobreviveu via JsonStore + off-site)
php scripts/migrar_json_para_db.php --include-archive

# 3. Volta DB_DRIVER=mysql no .env
```

---

## Cenário D — WordPress de 1 site fora

**Sintomas**: `saude.php?wp=1` mostra `wp.sites.cursosenac.ok=false`.

### D.1 — Confirmar com curl
```bash
curl -I https://cursosenacgratuito.com.br/wp-json/
# Se 503/timeout = WP morreu
# Se 401/403 = WP OK mas WP_APP_PASSWORD inválido
```

### D.2 — Reiniciar container WP (EasyPanel)
```bash
# UI EasyPanel → restart container do site afetado
# OU via docker:
docker restart wp_cursosenac
```

### D.3 — Trends pendentes pra esse site continuam vivos
```bash
# Status `aprovado` ou `gerando` ficam pendentes
# tick_filas vai re-tentar quando WP voltar — sem perda
```

### D.4 — Se WP perdido total → restaurar
- Backup MySQL do WP (responsabilidade do EasyPanel/HostMariaDB)
- Reinstalar plugins: `cc-click-logger.php`, `cc-news-sitemap.php`, `cc-prettylinks-api.php`, etc

---

## Cenário E — Plugin WP corrompido

**Sintomas**: erros REST API tipo "create_pretty_link falhou" / "404 /wp-json/cc/v1/clicks/recent".

### E.1 — Reativar plugin
```bash
wp plugin deactivate cc-click-logger --path=/var/www/cursosenac
wp plugin activate cc-click-logger --path=/var/www/cursosenac
# Activation hook re-cria tabelas (cc_click_events) se faltarem
```

### E.2 — Re-upload se ZIP corrompeu
```bash
# Local Windows tem plugin/cc-click-logger.php
# Upload via WP Admin → Plugins → Adicionar Novo → Enviar
```

---

## Pré-requisitos pra DR funcionar (CHECKLIST hoje)

- [ ] **Backup off-site habilitado** — `BACKUP_OFFSITE_ENABLED=1` + cron `backup_offsite.php` ativo
- [ ] **mysqldump diário** — adicionar ao crontab:
  ```cron
  0 3 * * * mysqldump -u clonais_saas -p$DB_PASS clonais_saas | gzip > /backups/mysql/clonais_saas_$(date +\%Y-\%m-\%d).sql.gz
  0 4 * * * find /backups/mysql -name '*.sql.gz' -mtime +14 -delete
  ```
- [ ] **`.env` armazenado em vault seguro** — 1Password / Bitwarden / Hashicorp Vault
- [ ] **Senha root MariaDB salva** off-site
- [ ] **Lista de tokens externos documentada** (não os tokens em si — só onde regenerar):
  - Anthropic console URL
  - OpenAI platform URL
  - Cada conta WP Application Password — admin de cada site
  - Meta Business Suite — URL
  - OneSignal dashboard — URL
  - Bluesky — handles registrados
- [ ] **Test mensal de DR** — 1× ao mês, restaurar em servidor de staging

---

## Tempo total realista de recovery

| Cenário | Tempo (com tudo preparado) | Tempo (improviso) |
|---|---|---|
| A — VPS morto | 45-60min | 4-8h |
| B — data/ corrompida | 5-10min | 30min |
| C — MariaDB perdida | 15-25min | 1-2h |
| D — WP fora | 10-20min | 30-60min |
| E — Plugin corrompido | 2-5min | 10-15min |

**A diferença entre "preparado" e "improviso" são esses checklists acima.** Vale a pena gastar 2h preparando.

---

## Quem alertar quando

- **Cenário A/C** (data loss potencial) → me ligar imediatamente. Decisões irreversíveis aqui.
- **Cenário B/D** → resolvível em <30min, executar do runbook
- **Cenário E** → trivial, só executar

---

**Última atualização**: 2026-04-28
