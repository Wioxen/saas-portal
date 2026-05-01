# Cron / Tick Filas — Operação 24/7

> Como agendar `scripts/tick_filas.php` pra processar fila sem depender de aba aberta. Foco em **Linux produção** (destino final), com referência rápida a Windows local pra dev.

## O que é

`scripts/tick_filas.php` é um runner CLI que processa **1 item por site com fila pendente** por execução. Replica o handler `?ajax=fila_tick` (em `portal.php:559-621`) mas roda em background, sem navegador.

**Garantias:**
- **Lock global** (`data/fila/.tick_global.lock`) impede 2 execuções simultâneas — Task Scheduler/cron pode disparar a cada 2 min sem medo.
- **Cleanup stale running** — items presos em `running` há mais de 10 min voltam pra `pending` automaticamente (defesa contra crash).
- **Logs append-only** em `data/fila/log_tick.log` — debug e auditoria.
- **Idempotente** — tick interrompido no meio é seguro: lock libera, próximo tick continua.

**Custo por tick (1 item):**
- Tempo: 60-180s (depende de quantas fontes scrape, latência do WP, LLM ativo)
- API: ~$0.30-1.00 (Claude default, GPT como fallback)
- 1 post draft criado no WP por execução

**Capacidade teórica:**
- Cron a cada 2 min, tick demora ~3 min real → ~1 item a cada 3 min → **~480 items/dia teórico**
- Realista: **150-300 items/dia** distribuídos entre os 6 sites

## Argumentos do script

```
php scripts/tick_filas.php
  --quiet               sem stdout (logs vão pra arquivo)
  --site=SLUG           força um site específico (ignora outros)
  --max=N               processa no máximo N items nesta execução
  --dry-run             só mostra o que faria (não chama API nem altera DB/fila)
```

**Default (sem flags):** 1 item por site com fila pendente, output no stdout + log.

## Linux — produção

### crontab (recomendado)

```bash
# Editar crontab do usuário que tem permissão no projeto
crontab -e
```

Adicionar:
```cron
# Tick fila Discover — a cada 2 minutos, indefinidamente.
# Lock global impede sobreposição mesmo se tick demora >2min.
*/2 * * * * /usr/bin/php /caminho/para/apiclaudephp/scripts/tick_filas.php --quiet
```

> Substituir `/caminho/para/apiclaudephp` pelo path real do projeto no servidor.

### Permissões

```bash
# Diretório de logs precisa ser writable pelo user do cron
chmod 775 /caminho/para/apiclaudephp/data/fila
chown www-data:www-data /caminho/para/apiclaudephp/data/fila  # ou o user apropriado
```

### Rotação de logs

`data/fila/log_tick.log` cresce com o tempo. Adicionar `/etc/logrotate.d/clonais-tick`:

```
/caminho/para/apiclaudephp/data/fila/log_tick.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
```

### Monitoramento

```bash
# Ver últimas execuções
tail -f /caminho/para/apiclaudephp/data/fila/log_tick.log

# Contar erros nas últimas 24h
grep "$(date +%Y-%m-%d)" data/fila/log_tick.log | grep -c "FAIL\|exception"

# Ver fila ativa de cada site
ls -lh data/fila/*.json

# Saúde geral do tick (precisa rodar via cron há > 10 min)
ps aux | grep -i tick_filas
```

## Windows local — dev

Pra testar localmente antes de subir pro Linux, executar manualmente via CMD/PowerShell:

```cmd
C:\xampp\php\php.exe C:\xampp\htdocs\apiclaudephp\scripts\tick_filas.php --max=1
```

**Para automação local** (opcional, não recomendado pra produção): Task Scheduler do Windows. Mas como destino é Linux, **investir tempo nisso é desperdício**. Use rodadas manuais pra teste.

## Troubleshooting

### Item fica preso em `running`
- **Causa esperada:** crash do PHP, kill do processo, máquina reiniciada
- **Solução automática:** próximo tick detecta `started_at > 10 min` e volta status pra `pending`
- **Solução manual:** editar `data/fila/<site>.json` e mudar `"status": "running"` pra `"status": "pending"`

### Tick não está rodando
- Checar permissões: `ls -la data/fila/.tick_global.lock` — precisa ser writable
- Checar PHP path: `which php` — deve bater com o do crontab
- Checar logs do cron: `grep CRON /var/log/syslog | tail -20`

### "Outro tick já está rodando" sempre aparece
- Lock global travado (provável crash anterior). Remover: `rm data/fila/.tick_global.lock`
- Se acontecer com frequência, investigar PHP fatal no log

### Bug raro: lock global criado mas não liberado
Acontece se PHP fatal entre `flock(LOCK_EX)` e `flock(LOCK_UN)`. Defesa: `try/finally` com `flock(LOCK_UN)`. Fallback manual: `rm data/fila/.tick_global.lock`.

### LLM falhando (Claude/GPT) com rate limit
- Sistema tem fallback automático Claude → GPT
- Se ambos falham: provavelmente API key inválida, créditos esgotados, ou rate limit
- Verificar `.env`: `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`

### WP timeout (Resolving timed out / cURL error)
- Verificar conectividade pro WP do site: `curl -I -A "Mozilla/5.0" https://SITE/wp-json`
- Se WP tem WAF/Cloudflare bloqueando bot: confirmar User-Agent em `lib/Wordpress.php` (5 ocorrências de `CURLOPT_USERAGENT`)
- Se DNS resolve mas TCP timeout: WP pode estar caído ou em manutenção

## Auditoria

Ver no log o que cada tick fez:

```bash
# Última execução
grep "tick start\|tick end" data/fila/log_tick.log | tail -2

# Items processados nas últimas 24h
grep "$(date +%Y-%m-%d)" data/fila/log_tick.log | grep -c "exec"

# Sucesso vs falha
grep "$(date +%Y-%m-%d)" data/fila/log_tick.log | grep -E "\\[OK\\]|\\[FAIL\\]" | sort | uniq -c
```

## InstantIndexing — auto-ativo no pipeline

Posts gerados via tick (e via `cli.php`, `gerarpost.php`, `atualizar.php`) **disparam IndexNow automaticamente** após publicação WP. Implementação:

- Lib: `lib/InstantIndexing.php` (cliente)
- Plugin WP: `plugin/cc-instant-indexing-api.php` — endpoint `/wp-json/cc/v1/indexar` com 4 fallbacks (Rank Math API → Rank Math action → IndexNow direto → ping sitemap)
- Indexação roda **incondicional** em `DiscoverGerador::gerar()` (linha 570-595)
- `gerarpost.php` e `atualizar.php` agora têm `auto_index` **default ON** (era OFF, mudou em 2026-04-26)
- `cli.php` indexa cada post gerado em massa (adicionado em 2026-04-26)
- Resultado salvo em `db.indexing_info` por trend

Pra indexar URLs existentes sem `indexing_info`:

```bash
php scripts/indexar_retroativo.php           # todos os sites
php scripts/indexar_retroativo.php --site=X  # 1 site
php scripts/indexar_retroativo.php --dry-run # preview
```

**Pré-requisitos por site WP:**
- Plugin `cc-instant-indexing-api` instalado e ativo
- (Opcional, recomendado) Rank Math + módulo Instant Indexing + Google Indexing API key configurados — pra ter pings ao Google direto. Sem isso, fallback é IndexNow (Bing/Yandex), que ainda é útil mas não atinge Google.

## O que o tick NÃO faz (out-of-scope)

- ❌ NÃO cria filas — só processa filas existentes (criadas via portal `?ajax=fila_iniciar`)
- ❌ NÃO scrape de Trends — isso é responsabilidade do `pingo` (TIER A item separado)
- ❌ NÃO publica em Facebook/Instagram diretamente — pertence à pipeline de geração (`Maquina.php` ou `DiscoverGerador.php`)
- ❌ NÃO envia push — pertence à `DiscoverOneSignal.php` invocada na geração
- ❌ NÃO indexa no Google — pertence à `InstantIndexing.php` invocada na geração

> Tudo isso acontece DENTRO da chamada `DiscoverGerador::gerar()` que o tick faz. Tick é só o **agendador** — quem faz o trabalho é o pipeline existente.

## Próximos passos

- [ ] Confirmar fix do User-Agent em `lib/Wordpress.php` resolveu timeout do cursosenac (TIER S #1.4)
- [ ] Subir pro servidor Linux quando código estiver estável
- [ ] Configurar logrotate
- [ ] Definir alerta se nenhum tick rodou nas últimas 30 min (provável cron quebrado)
