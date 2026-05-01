# 02 — Handlers POST + AJAX (linhas 63-698)

**Propósito:** atender 1 POST não-AJAX (`salvar_aprovados`) + 21 endpoints AJAX (`?ajax=*`). Cada handler é um `if` independente. Todos usam estado global do bootstrap (`$cfg`, `$db`, `$siteSlug`).

**Estrutura padrão de handler AJAX:**

```php
if (($_GET['ajax'] ?? '') === 'NOME') {
    header('Content-Type: application/json; charset=utf-8');  // alguns
    set_time_limit(N);                                         // se for lento
    try {
        // valida input
        // executa lógica
        echo json_encode([...]);  ou  jsonOut([...]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}
```

> Inconsistência observada: nem todos usam `jsonOut()`. Alguns dão `header()` + `echo` + `exit` direto. Funciona porque o AJAX guard do bootstrap já bufferizou warnings — mas seria mais consistente usar `jsonOut()` em todos.

---

## 1 · POST `salvar_aprovados` (linhas 63-116)

| Item     | Valor |
|----------|-------|
| Trigger  | `<form method="POST">` com `acao=salvar_aprovados` |
| Resposta | Redirect 302 pra `portal.php?modo=atual&view=saved&site=X&saved=N` |
| Lib      | `DiscoverScore`, `DiscoverAngulo`, `DiscoverSinaisEditoriais`, `DiscoverDb` |

**Input:**
- `trends_json` (JSON array dos trends marcados)
- `origem` (`'168h'` por padrão)
- `manual` (se setado, NÃO bloqueia por threshold)

**Lógica:**
1. Decodifica JSON, itera trends.
2. Para cada um: calcula score, intenção, briefing, sinais editoriais.
3. Monta `$row` com 17 chaves padronizadas (site, termo, categoria, volume, score, briefing, etc.).
4. `$db->upsertMany($rows)`.
5. Redireciona pra view "saved" do site ativo (NÃO devolve JSON — é POST de form).

**Nota:** comentário linha 74-75 explica decisão arquitetural: "Score vira INFORMATIVO — não bloqueia mais nada. User decide o que salva." Histórico — antes havia threshold automático.

---

## 2 · `?ajax=salvar_unico` (linhas 119-158)

Versão AJAX do anterior, salva 1 trend só (botão 💾 por linha).

| Item     | Valor |
|----------|-------|
| Trigger  | botão 💾 ao lado de cada trend |
| Output   | `{ok, id, termo, score}` |
| Lib      | mesmo do anterior |

Diferença: monta `$row` único (não array), chama `$db->upsert($row)` (singular), devolve JSON via `jsonOut()`.

> **Duplicação detectada:** linhas 82-103 (POST salvar_aprovados) e 131-152 (salvar_unico) montam `$row` quase idêntico. Refatoração sugerida: helper `montarRowTrend($t, $siteSlug, $origem)`. Não fazer agora.

---

## 3 · `?ajax=queries` (linhas 164-178)

Consultas relacionadas a um termo (autocomplete/análise).

| Item     | Valor |
|----------|-------|
| Trigger  | `?ajax=queries&termo=X&hours=4|168` |
| Output   | `{ok, termo, data}` |
| Lib      | `TrendsScraperWeb::consultasRelacionadas()` |

Validação simples: `hours` ∈ [4, 168], senão 168.

---

## 4 · `?ajax=progresso` (linhas 181-186)

Polling do progresso de uma geração ativa.

| Item     | Valor |
|----------|-------|
| Trigger  | `?ajax=progresso&id=N` (id do trend) |
| Output   | `{ok, progresso}` |
| Lib      | `DiscoverProgress::ler($id)` |

Curtinho — só ponte pra `DiscoverProgress`. Esse cara é polled em loop pelo JS durante geração.

---

## 5 · `?ajax=gerar_gpt` (linhas 189-206)

Geração de post via OpenAI (teste A/B contra Claude).

| Item     | Valor |
|----------|-------|
| Trigger  | `?ajax=gerar_gpt`, POST `id`, `modelo` |
| Output   | resposta de `DiscoverGeradorGPT::gerar()` + `tempo_ms` |
| Limites  | `set_time_limit(300)`, `memory_limit=512M` |
| Lib      | `DiscoverGeradorGPT` |

Default `modelo='gpt-4o-mini'`. Mede tempo total. Sem fallback — se falhar, devolve `{ok:false, erro}`.

---

## 6 · `?ajax=revisar_post` (linhas 209-245)

Revisa post já publicado (Etapa 2 — otimização + alternativas).

| Item     | Valor |
|----------|-------|
| Trigger  | `?ajax=revisar_post`, POST `id` |
| Output   | resposta de `DiscoverReviewer::revisar()` + quality opcional |
| Limites  | 200s, 512MB |
| Lib      | `DiscoverReviewer`, `Wordpress`, `DiscoverQualityScore` |

**Pós-processamento:** se revisão OK e `post_id` retornado:
1. Busca post atual via `Wordpress::getPost()`.
2. Recalcula `DiscoverQualityScore::avaliar()`.
3. Atualiza DB com `quality_score`, `quality_status`, `quality_detalhes`, `quality_melhorias` via `db->updateStatus(id, 'publicado', [...])`.
4. Erro nesse passo NÃO bloqueia (catch silencioso).

---

## 7 · `?ajax=avaliar_qualidade` (linhas 248-292)

Recalcula `DiscoverQualityScore` em massa para posts já publicados.

| Item     | Valor |
|----------|-------|
| Trigger  | POST `ids` (JSON array) ou `id` único; ou vazio = TODOS publicados do site |
| Output   | `{ok, avaliados, total, resultados, erros}` |
| Limites  | 60s |
| Lib      | `Wordpress`, `DiscoverQualityScore` |

**Filtro automático:** se `ids` vazio, lista do `$db->all(['site' => $siteSlug])` os com status `publicado` ou `suspeita` E `url_post` preenchido.

**Extração do post_id:** regex `/post=(\d+)/` em `$rec['url_post']`.

Atualiza DB mantendo o status atual (não muda pra "publicado" arbitrariamente).

---

## 8 · `?ajax=migrar_site` (linhas 295-309)

Move registros de um site pra outro (recupera quando salvou no site errado).

| Item     | Valor |
|----------|-------|
| Trigger  | POST `from`, `to`, `evento` (opcional) |
| Output   | `{ok, ...migrarSite()}` |
| Lib      | `DiscoverDb::migrarSite($from, $to, $evento)` |

Validação: ambos `from` e `to` devem existir em `$sites`.

---

## 9 · `?ajax=reprocessar` (linhas 312-391)

**O mais robusto.** Roda `DiscoverPostProcess` em posts publicados + re-interlink dos clusters afetados.

| Item     | Valor |
|----------|-------|
| Trigger  | POST `ids` (JSON) ou `id` |
| Output   | `{ok, processados, total, erros, interlinks}` |
| Limites  | 600s (!), 512MB |
| Lib      | `Wordpress`, `DiscoverPostProcess`, `DiscoverCluster` |

**Particularidades:**

- **Shutdown handler** (linha 317-327): se der fatal (timeout, OOM, parse error), ainda devolve JSON válido. Pega `error_get_last()`, esvazia buffer, manda `{ok:false, erro:'Fatal: ...'}`.
- **`DiscoverPostProcess` NÃO está no require_once do bootstrap** — provavelmente é incluído transitivamente por `DiscoverGerador` (verificar se for refatorar).
- **Re-interlink condicional** (linha 365): só roda se `count($ids) > 1` AND havia eventos. Pra reformat de 1 ID só, pula (custo alto sem benefício).
- Erros por ID são acumulados em `$erros` mas não interrompem o loop.

---

## 10 · `?ajax=excluir_trend` (linhas 394-405)

Remove registro do DB. **NÃO mexe no WP** (post fica lá).

| Item     | Valor |
|----------|-------|
| Trigger  | POST `id` |
| Output   | `{ok, id, termo}` |
| Lib      | `DiscoverDb::delete()` |

Curtinho. Comentário deixa claro o escopo.

---

## 11 · `?ajax=regerar_reset` (linhas 408-442)

"Regerar do zero": manda post antigo pro lixo + reseta status do trend pra `'aprovado'`.

| Item     | Valor |
|----------|-------|
| Trigger  | POST `ids` ou `id` |
| Output   | `{ok, resetados, wp_trashed}` |
| Lib      | `Wordpress::atualizarPost(status=trash)`, `DiscoverDb::updateStatus()` |

**Ações por ID:**
1. Se tem `url_post` → trash no WP (catch silencioso se já deletado).
2. `db->updateStatus($id, 'aprovado', { url_post: null, publicado_em: null, cluster_*: null, auditoria: null })`.

Volta o trend pra fila como se nunca tivesse sido gerado.

---

## 12 · `?ajax=cluster_interligar` (linhas 445-458)

Interliga posts publicados do mesmo evento (cluster manual).

| Item     | Valor |
|----------|-------|
| Trigger  | POST `evento` |
| Output   | resposta de `DiscoverCluster::interligar()` |
| Limites  | 120s |
| Lib      | `DiscoverCluster` |

---

## 13 · `?ajax=calendario_salvar` (linhas 461-505)

Salva um cluster sazonal (do modo calendário) como trends aprovados.

| Item     | Valor |
|----------|-------|
| Trigger  | POST `nome`, `tema`, `categoria`, `data_pico`, `cluster` (JSON array de títulos) |
| Output   | `{ok, salvos, ids, evento}` |
| Lib      | `DiscoverDb::upsertMany()` |

**Score artificial alto** (8.7, com decay 0.1 por satélite — hub > satélites). Volume placeholder (100000, label `'sazonal'`). Hub = primeiro item, satélites = restantes.

`evento_fonte` recebe `nome` — usado depois pra interlink do cluster.

---

## 14 · `?ajax=fila_iniciar` (linhas 508-536)

Cria batch de geração em lote.

| Item     | Valor |
|----------|-------|
| Trigger  | POST `ids` (JSON), `formato` (default `'discover'`) |
| Output   | `{ok, batch_id, total}` |
| Lib      | `DiscoverFila::criar()` |

**Validação por ID:**
- Não encontrado → conta em `rejeitados['nao_encontrado']`.
- `site != siteSlug` → `rejeitados['site_errado']` (proteção contra rodar em site errado).
- Score NÃO filtra (decisão arquitetural, ver salvar_aprovados).

Se nenhum válido, lança erro com contagem detalhada por categoria.

---

## 15-17 · `?ajax=fila_status` / `fila_cancelar` / `fila_limpar` (linhas 538-557)

| Endpoint        | Output                            | Lib                                |
|-----------------|-----------------------------------|------------------------------------|
| fila_status     | `DiscoverFila::status()`          | DiscoverFila                       |
| fila_cancelar   | `{ok:true}` + cancelar()          | idem                               |
| fila_limpar     | `{ok:true}` + limpar()            | idem                               |

3 one-liners. Polled pelo JS.

---

## 18 · `?ajax=fila_tick` (linhas 559-621) — **o motor da fila**

Avança 1 item da fila. JS chama em loop até `terminou: true`.

| Item     | Valor |
|----------|-------|
| Trigger  | `?ajax=fila_tick` (sem payload — fila resolve sozinha) |
| Output   | `{ok, feito, terminou, resultado, interlink, status?, auto_interlinks?}` |
| Limites  | 200s, 512MB (gerar 1 post) |
| Lib      | `DiscoverFila`, `DiscoverGerador`, `DiscoverCluster` |

**Fluxo:**
1. `fila->proximoComLock()` — pega próximo + lock pra evitar race.
2. **Se `null`** (fim da fila):
   - Pass final: itera todos os clusters, se cluster tem ≥ 2 publicados, roda `interligar()` (idempotente — pega itens que já estavam publicados antes da fila).
   - Devolve `terminou: true` com `auto_interlinks`.
3. **Senão:** carrega `$rec`, roda `DiscoverGerador::gerar()`, marca resultado na fila, devolve `feito + terminou:false`.

**Linha 606:** comentário esclarece que interlink agora é parte de `DiscoverGerador::gerar()` — vem em `$res['cluster_interlink']`. Antes era passo separado.

---

## 19 · `?ajax=atualizar` (linhas 623-642) — Etapa 10

Atualização inteligente (refresh) de um post existente.

| Item     | Valor |
|----------|-------|
| Trigger  | `?ajax=atualizar&id=N` |
| Output   | resposta de `DiscoverUpdater::atualizar()` |
| Limites  | 180s, 512MB |
| Lib      | `DiscoverUpdater` |

---

## 20 · `?ajax=gerar` (linhas 645-674) — geração ad-hoc

Geração + publicação completa, com ou sem trend salvo.

| Item     | Valor |
|----------|-------|
| Trigger  | `?ajax=gerar&termo=X&formato=discover|seo|news|serp` |
| Output   | resposta de `DiscoverGerador::gerar()` |
| Limites  | 180s, 512MB |
| Lib      | `DiscoverGerador` |

**Particularidade:** procura trend salvo por `termo` (case-insensitive); se não achar, monta `$rec` mínimo on-the-fly (`['termo' => $termo, 'id' => 0, 'briefing' => null]`).

Permite gerar pra termo que NUNCA foi escrapeado/aprovado.

---

## 21 · `?ajax=noticias` (linhas 677-697)

Lista artigos reais (Google News RSS + Serper) pra um termo.

| Item     | Valor |
|----------|-------|
| Trigger  | `?ajax=noticias&termo=X&max=3..10` |
| Output   | `{ok, termo, total, resolvidos, data}` |
| Lib      | `TrendsArticles`, `Serper` |

`max` clampado a [3, 10], default 5. Conta `resolvidos` = quantos têm `url_real` populada.

---

## Tabela-resumo

| #  | Endpoint            | Limite | Output principal                | Lib principal                |
|----|---------------------|--------|---------------------------------|------------------------------|
| 1  | salvar_aprovados (POST)| –   | redirect                        | DiscoverDb                   |
| 2  | salvar_unico        | –      | `{ok,id,termo,score}`           | DiscoverDb                   |
| 3  | queries             | –      | `{ok,data}`                     | TrendsScraperWeb             |
| 4  | progresso           | –      | `{progresso}`                   | DiscoverProgress             |
| 5  | gerar_gpt           | 300s   | resultado + tempo_ms            | DiscoverGeradorGPT           |
| 6  | revisar_post        | 200s   | resultado + quality opcional    | DiscoverReviewer + Quality   |
| 7  | avaliar_qualidade   | 60s    | `{avaliados,resultados,erros}`  | DiscoverQualityScore         |
| 8  | migrar_site         | –      | resultado migrarSite            | DiscoverDb                   |
| 9  | reprocessar         | 600s   | `{processados,interlinks}`      | DiscoverPostProcess + Cluster|
| 10 | excluir_trend       | –      | `{ok,id,termo}`                 | DiscoverDb                   |
| 11 | regerar_reset       | –      | `{resetados,wp_trashed}`        | Wordpress + DiscoverDb       |
| 12 | cluster_interligar  | 120s   | resultado interligar            | DiscoverCluster              |
| 13 | calendario_salvar   | –      | `{salvos,ids,evento}`           | DiscoverDb                   |
| 14 | fila_iniciar        | –      | `{batch_id,total}`              | DiscoverFila                 |
| 15 | fila_status         | –      | status                          | DiscoverFila                 |
| 16 | fila_cancelar       | –      | `{ok}`                          | DiscoverFila                 |
| 17 | fila_limpar         | –      | `{ok}`                          | DiscoverFila                 |
| 18 | fila_tick           | 200s   | `{feito,terminou,resultado}`    | DiscoverGerador + Cluster    |
| 19 | atualizar           | 180s   | resultado update                | DiscoverUpdater              |
| 20 | gerar               | 180s   | resultado gerar                 | DiscoverGerador              |
| 21 | noticias            | –      | `{total,resolvidos,data}`       | TrendsArticles + Serper      |

## Observações transversais

- **`DiscoverPostProcess` nunca é incluído explicitamente** em portal.php — vem por transitividade (provavelmente via `DiscoverGerador`). Se quebrar a transitividade no futuro, handler #9 quebra.
- **Inconsistência de saída:** uns usam `jsonOut()`, outros `header() + echo + exit`. Mesmo efeito final. Se padronizar, todos via `jsonOut()`.
- **Catch genérico `Throwable`** em todos — pega Error e Exception. Boa prática.
- **Nenhum handler tem CSRF/nonce check.** Portal é interno; se for exposto, precisa proteção.
- **Limites de `set_time_limit` variam** (60s a 600s). Decisões pareciam empíricas — se ajustar, atentar pro pior caso real.
- **Endpoint `?ajax=fila_tick`** + JS `tickLoop()` (frontend) implementa um workflow assíncrono manual — sem worker/cron. Cada tick = 1 request HTTP.

## Pontos de extensão

- Novo handler? Seguir o padrão `if ($ajax === ...) try { ... } catch { ... } exit;`.
- Padronizar saída via `jsonOut()` reduz risco de buffer leak.
- Helper `montarRowTrend()` removeria duplicação (handlers #1 e #2).
- CSRF/nonce centralizado se portal for exposto pra fora da rede interna.
