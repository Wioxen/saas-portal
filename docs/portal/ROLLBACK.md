# ROLLBACK — saída de emergência

> Procedimento pra reverter rapidamente quando o pipeline em produção começa a publicar conteúdo ruim, postar errado ou corromper state files. Mantenha esse doc atualizado conforme o sistema evolui.

---

## Cenário 1 — Código novo quebra geração

**Sintoma:** posts publicados depois do deploy estão errados (formato quebrado, alucinação, ângulo errado).

### 1.1 Parar o pipeline ANTES de tudo

```bash
# Linux servidor SaaS — comenta linhas do crontab
crontab -e
# Prefixar com # as 4 linhas (tick_filas, pingo, antecipar_sazonal, auto_refresh_posts)

# Mata processos que estão rodando NESTE momento
pkill -f tick_filas.php
pkill -f auto_refresh_posts.php
pkill -f antecipar_sazonal.php
pkill -f scripts/pingo.php
```

### 1.2 Reverter código

```bash
cd /var/www/clonais
# Identificar último commit bom
git log --oneline -10

# Reverter pra hash conhecido
git reset --hard <hash-bom>

# OU, se preferir manter histórico:
git revert <hash-ruim>
```

### 1.3 Liberar locks e re-ativar cron

```bash
# Locks em /tmp não somem se processo morreu sem liberar
rm -f /tmp/clonais_*.lock

# Re-ativar crontab (descomentar)
crontab -e
```

### 1.4 Smoke test antes de descomentar

```bash
php /var/www/clonais/scripts/_smoke_test.php
# Se passar 100%, descomenta cron. Se não, fica off até resolver.
```

---

## Cenário 2 — State file corrompido

**Sintoma:** `discover_trends.json` fica vazio, JSON inválido, ou cron começa a logar "JSON inválido em data/...".

### 2.1 Restaurar do backup mais recente

```bash
cd /var/www/clonais

# Listar snapshots disponíveis
php scripts/backup_state.php --listar
# Saída exemplo:
#   2026-04-26 · 7 arquivos · 4823.1 KB
#   2026-04-27 · 7 arquivos · 4796.6 KB

# Restaurar (interativo — pede confirmação 'sim')
php scripts/backup_state.php --restaurar=2026-04-26
```

O comando preserva o estado ATUAL como `data/X.json.before_restore_TIMESTAMP` antes de sobrescrever, então dá pra desfazer a restauração se necessário.

### 2.2 Validar JSONs após restore

```bash
# Cada arquivo principal deve dar parse OK
for f in data/discover_trends.json data/afiliados.json data/fontes_pingo.json; do
    echo "=== $f ==="
    php -r "var_dump(json_decode(file_get_contents('$f'), true) === null ? 'INVALIDO' : 'OK');"
done
```

---

## Cenário 3 — Posts ruins já publicados em massa

**Sintoma:** 20+ posts publicados nos últimos minutos antes de você conseguir parar o cron. Precisam sumir do ar.

### 3.1 Lista os posts via REST WP

```bash
# No servidor SaaS, lista posts publicados nas últimas 4h (ajustar -d):
DESDE=$(date -u -d '4 hours ago' '+%Y-%m-%dT%H:%M:%S')
curl -s -u "$WP_USER:$WP_APP_PASSWORD" \
  "https://comocomprar.com.br/wp-json/wp/v2/posts?after=${DESDE}&per_page=50&status=publish&_fields=id,date,title.rendered,link" \
  | python3 -m json.tool
```

### 3.2 Bulk-unpublish (mover pra draft)

Caminho mais seguro (preserva conteúdo, só esconde do público):

```bash
# Pra cada post_id retornado acima:
curl -X POST -u "$WP_USER:$WP_APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{"status": "draft"}' \
  "https://comocomprar.com.br/wp-json/wp/v2/posts/<POST_ID>"
```

Loop pra 50 posts:

```bash
for ID in 2810 2811 2812 ...; do
    curl -X POST -u "$WP_USER:$WP_APP_PASSWORD" \
      -H "Content-Type: application/json" \
      -d '{"status": "draft"}' \
      "https://comocomprar.com.br/wp-json/wp/v2/posts/${ID}"
    sleep 0.3
done
```

### 3.3 Pedir indexação reversa no Google

GSC → Indexação → Remover URLs → adicionar cada URL do batch ruim. Remove do Discover/SERP em ~6h.

---

## Cenário 4 — Fila de geração com items envenenados

**Sintoma:** trends "ativos" no DB com termo lixo (regex test, dataset corrompido). Cron tick processa e queima crédito Sonnet.

### 4.1 Identificar trends suspeitos

```bash
php -r '
$d = json_decode(file_get_contents("data/discover_trends.json"), true);
foreach ($d["records"] as $r) {
    if (($r["status"] ?? "") === "aprovado" && empty($r["url_post"])) {
        echo "{$r["id"]} | {$r["site"]} | {$r["termo"]}\n";
    }
}'
```

### 4.2 Mover trends pra "skipped"

Editar `data/discover_trends.json` ou via php:

```bash
php -r '
require "lib/DiscoverDb.php";
$db = new DiscoverDb();
foreach ([123, 456, 789] as $id) {  // ids dos trends ruins
    $db->updateStatus($id, "skipped", ["motivo_skip" => "rollback manual"]);
}'
```

---

## Cenário 5 — API LLM com problema (Anthropic 5xx, OpenAI 429)

**Sintoma:** logs mostram falhas em série de geração. Posts ficam stuck em fila.

### 5.1 Pausar tick_filas temporariamente

```bash
# Comenta só a linha tick_filas no crontab. Pingo + auto_refresh continuam normais.
crontab -e
# # */2 * * * * /usr/bin/php /var/www/clonais/scripts/tick_filas.php ...
```

### 5.2 Quando API voltar

Re-ativa linha. Trends em status `running` que ficaram travados por timeout vão ser recuperados pelo cleanup (`tick_filas` faz cleanup de stale > 30min).

---

## Cenário 6 — Custo Sonnet inflando

**Sintoma:** fatura Anthropic surpresa.

### 6.1 Diagnóstico rápido

```bash
# Quantos posts foram gerados hoje:
php -r '
$d = json_decode(file_get_contents("data/discover_trends.json"), true);
$hoje = date("Y-m-d");
$qtd = 0;
foreach ($d["records"] as $r) {
    if (substr(($r["publicado_em"] ?? ""), 0, 10) === $hoje) $qtd++;
}
echo "Posts publicados hoje: {$qtd}\n";'
```

### 6.2 Apertar Trend-Scoring Gate

`config.php`:
```php
'trend_scoring_threshold' => 8.5,  // era 7.0 → mais Sonnet vira GPT-mini
```

Sem deploy: trends novos a partir desse momento já respeitam.

---

## Tabela rápida — qual rollback usar

| Sintoma | Cenário |
|---------|---------|
| Posts saindo errados depois do deploy | 1 (revert código) |
| `JSON inválido em data/...` no log | 2 (restore backup) |
| 20+ posts ruins no ar | 3 (bulk unpublish) |
| Trends suspeitos na fila aprovado | 4 (skipped) |
| API LLM intermitente | 5 (pause + cleanup auto) |
| Fatura Anthropic alta | 6 (apertar gate) |

---

## Pré-deploy — sempre fazer ANTES de sobrescrever produção

```bash
# 1. Backup imediato dos state files (manual fora do cron)
php scripts/backup_state.php

# 2. Lista último snapshot pra confirmar criação
php scripts/backup_state.php --listar | tail -3

# 3. Tag git ANTES do deploy pra rollback rápido
git tag pre-deploy-$(date +%Y%m%d-%H%M)
git push origin --tags
```

Se algo quebrar:

```bash
git reset --hard pre-deploy-YYYYMMDD-HHMM
php scripts/backup_state.php --restaurar=YYYY-MM-DD
```
