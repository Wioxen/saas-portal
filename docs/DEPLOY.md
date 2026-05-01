# DEPLOY — Clonais SaaS no servidor Linux

> Guia completo de deploy. Use junto com `INSTALL.md` (incluso no tar.gz).
> Para rollback de emergência ver `docs/portal/ROLLBACK.md`.

---

## Visão geral do fluxo

```
[Local Windows/XAMPP]
    │
    ├── 1. php scripts/_empacotar_deploy.php   → cria tar.gz (~1MB)
    │
    ├── 2. scp tar.gz user@servidor:/tmp/      → upload
    │
[Servidor Linux]
    │
    ├── 3. tar -xzf + chown                    → extrai
    ├── 4. scp .env + credentials              → sobe credenciais separado
    ├── 5. mkdir data/{cache,backup,...}       → permissões
    ├── 6. php scripts/_smoke_test.php         → valida
    └── 7. crontab -e (cole crontab.template)  → ativa cron 24/7
```

---

## Pré-requisitos no servidor

### PHP 8.0+ + extensões

```bash
php -v
php -m | grep -E "curl|gd|mbstring|openssl|json|libxml|dom|simplexml|phar"
php -r 'print_r(gd_info());' | grep -i webp  # GD precisa WebP
```

Se faltar:

```bash
sudo apt update
sudo apt install php-curl php-gd php-mbstring php-xml php-zip -y
sudo systemctl restart php8.x-fpm  # ou apache2
```

### Cron + logs

```bash
which crontab    # /usr/bin/crontab
sudo mkdir -p /var/log/clonais
sudo chown www-data:www-data /var/log/clonais
sudo chmod 755 /var/log/clonais
```

### Espaço em disco

Mínimo 2 GB livres. Em 30 dias rodando, espera-se ~500 MB em `data/` (state + cache + backups + logs).

---

## Caminho A — Deploy inicial via tar.gz

### 1. Empacotar localmente

```bash
cd /c/xampp/htdocs/apiclaudephp
php scripts/_empacotar_deploy.php
# Output: /tmp/clonais-deploy-YYYYMMDD-HHMMSS.tar.gz (~1 MB)
```

Validar antes de subir:

```bash
php scripts/_empacotar_deploy.php --listar | tail -10
tar -tzf /tmp/clonais-deploy-*.tar.gz | wc -l   # deve dar ~212 arquivos
```

### 2. Upload via SCP

```bash
scp /tmp/clonais-deploy-*.tar.gz user@servidor:/tmp/
```

### 3. Extrair no servidor

```bash
ssh user@servidor
cd /var/www
sudo tar -xzf /tmp/clonais-deploy-*.tar.gz
sudo chown -R www-data:www-data clonais
cd clonais
ls -la
```

Diretório esperado:
```
/var/www/clonais/
├── lib/                  # 30+ arquivos
├── scripts/              # 25+ arquivos
├── docs/                 # docs/portal, docs/pingo, etc
├── plugin/               # *.zip dos plugins WP
├── data/
│   ├── *.json            # state files críticos
│   ├── cache/            # vazia (será populada)
│   ├── backup/           # vazia
│   ├── debug/            # vazia
│   ├── progress/         # vazia
│   └── fila/             # vazia
├── config.php
├── sites.php
├── portal.php
├── ...
├── .env.example          # template
├── crontab.template      # entries crontab
└── INSTALL.md            # passo-a-passo resumido
```

### 4. Subir credenciais (NUNCA via tar.gz — segurança)

```bash
# Do local:
scp .env user@servidor:/var/www/clonais/.env
scp data/google_credentials.json user@servidor:/var/www/clonais/data/google_credentials.json

# No servidor:
ssh user@servidor
sudo chmod 600 /var/www/clonais/.env
sudo chmod 600 /var/www/clonais/data/google_credentials.json
sudo chown www-data:www-data /var/www/clonais/.env /var/www/clonais/data/google_credentials.json
```

### 5. Permissões de escrita em data/

```bash
cd /var/www/clonais
sudo mkdir -p data/cache/amazon_bestsellers data/cache/articles_cache data/cache/search_console_cache
sudo chmod -R 775 data/
sudo chown -R www-data:www-data data/
```

### 6. Smoke test

```bash
cd /var/www/clonais
php scripts/_smoke_test.php
```

**Esperado:** `82 OK · 0 WARN · 0 FAIL`.

Se houver FAIL:
- Releia o output, corrija ANTES de ativar cron
- Ex: extensão PHP faltando? `sudo apt install php-...`
- Ex: permissão? `sudo chown -R www-data:www-data /var/www/clonais`

### 7. Ativar cron

```bash
sudo -u www-data crontab -e
```

Cole o conteúdo de `crontab.template` (substituir `/var/www/clonais` se seu path for diferente). Salvar.

Validar 5 min depois:

```bash
tail -f /var/log/clonais/tick.log
# Esperado: linhas tipo "[2026-04-27 12:00:00] === tick start..." e "[skip] todos os 1 slots ocupados" rotando
```

### 8. Deploy plugins WP (manual, em cada site)

Os plugins **wp-web-stories-ai-v26**, **cc-instant-indexing-api-v1**, **cc-prettylinks-api-v1** e **cc-smart-infeed-v1** estão em `plugin/*.zip`.

Em cada um dos 6 sites:
1. WP Admin → Plugins → **Adicionar Novo** → **Carregar plugin**
2. Selecionar o `.zip` correspondente → Instalar → Ativar

Sites pendentes (verificar quais já têm cada plugin):

| Site | wp-web-stories-ai-v26 | cc-instant-indexing-api-v1 | cc-prettylinks-api-v1 | cc-smart-infeed-v1 |
|------|----------------------|----------------------------|------------------------|---------------------|
| comocomprar | ? | ✓ | ✓ | ? |
| vagasebeneficios | ? | ✓ | ✓ | ? |
| cursosenacgratuito | ✓ | ✓ | ✓ | ? |
| guiadoscursos | ✓ | ✓ | ✓ | ? |
| leaodabarra | ? | ✓ | ✓ | ? |
| ondecompraragora | ? | ✓ | ✓ | ? |

---

## Caminho B — Updates incrementais via rsync

Após o deploy inicial, pra atualizar código novo sem repetir empacotamento:

```bash
# Do local:
rsync -avz --delete \
  --exclude='.env' \
  --exclude='.git/' \
  --exclude='.vscode/' \
  --exclude='node_modules/' \
  --exclude='wp-content/' \
  --exclude='data/cache/' \
  --exclude='data/backup/' \
  --exclude='data/debug/' \
  --exclude='data/progress/' \
  --exclude='data/fila/log_*' \
  --exclude='data/fila/.tick_*' \
  --exclude='data/google_credentials.json' \
  ./ user@servidor:/var/www/clonais/
```

**Vantagens do rsync:**
- Só transfere arquivos modificados (delta)
- `--delete` remove no destino arquivos que sumiram do source (útil)
- Preserva permissões com `-a`
- Compressão `-z`

Após rsync, smoke test no servidor:
```bash
ssh user@servidor 'cd /var/www/clonais && php scripts/_smoke_test.php | tail -5'
```

---

## Validação pós-deploy

### Smoke check

```bash
ssh user@servidor 'cd /var/www/clonais && php scripts/_smoke_test.php' | tail -5
```

### Trend manual de teste

```bash
ssh user@servidor
cd /var/www/clonais
php scripts/_listar_aprovados.php | head -5
# Anota um trend_id qualquer:
php scripts/_criar_fila_teste.php --site=cursosenac --id=<TREND_ID>
php scripts/tick_filas.php --site=cursosenac --max=1
# Verificar:
php scripts/auto_refresh_posts.php --historico=1
php scripts/backup_state.php --listar
```

### Logs ativos

```bash
tail -f /var/log/clonais/tick.log
tail -f /var/log/clonais/spike.log
tail -f /var/log/clonais/pingo.log
```

### GSC autorizada?

```bash
php -r 'require "lib/DiscoverSearchConsole.php"; echo count((new DiscoverSearchConsole())->listarSites()) . " sites" . PHP_EOL;'
# Esperado: ≥1 (cursosenac já confirmado). Outros 5 sites pendem ser adicionada SA como user.
```

---

## Rollback

Ver `docs/portal/ROLLBACK.md`. Resumindo:

```bash
# Pausar cron antes de qualquer coisa drástica:
crontab -l | grep -v "/var/www/clonais" | crontab -

# Reverter código (rsync):
git reset --hard <hash-bom>
rsync -avz ./ user@servidor:/var/www/clonais/

# Restaurar state files de backup:
ssh user@servidor 'cd /var/www/clonais && php scripts/backup_state.php --restaurar=YYYY-MM-DD'

# Reativar cron:
crontab -e  # cole crontab.template novamente
```

---

## Troubleshooting

| Sintoma | Causa provável | Fix |
|---------|----------------|-----|
| Smoke test FAIL em "extensão" | PHP sem GD/curl | `sudo apt install php-gd php-curl` |
| `tick.log` vazio após 5min | crontab não ativado, ou path errado | `crontab -l` mostra entries? path bate? |
| `Permission denied` ao gravar | data/ sem permissão | `chmod -R 775 data/ && chown -R www-data:www-data data/` |
| GSC retorna 403 | Service Account não autorizada nesse site | GSC → Settings → Users → adicionar SA email |
| IG retorna 400 com URL própria | Domain não verificado no Meta | https://business.facebook.com/settings/owned-domains |
| Sonnet rate limit | Trend-Scoring Gate desviando muito | aumente `trend_scoring_threshold` em `.env` |

---

## Comandos de manutenção

```bash
# Limpar cache antigo (semanalmente)
find data/cache -mtime +7 -delete

# Comprimir logs antigos (mensal)
find /var/log/clonais -name "*.log" -mtime +30 -exec gzip {} \;

# Espaço em disco
du -sh /var/www/clonais/data/*

# Status cron
systemctl status cron

# Listar processos PHP rodando
ps aux | grep -E "tick_filas|spike_detect|pingo|auto_refresh" | grep -v grep
```
