# 05 — Render HTML (linhas 877-2326)

**Propósito:** servir a página HTML do portal — depois que nenhum handler AJAX/POST capturou a request. ~1450 linhas de PHP+HTML+CSS inline. **Server-side rendering puro**: zero framework.

## Estrutura macro

| Range     | Conteúdo                                                                |
|-----------|-------------------------------------------------------------------------|
| 877-1153  | `<head>` + `<style>` inline (~270 linhas de CSS)                        |
| 1154-1156 | Body open + container + H1                                              |
| 1156-1175 | Header: site ativo + toggle LLM (Claude/GPT) preservando query string   |
| 1177-1181 | Tabs: `?modo=atual` / `?modo=historico` / `?modo=calendario`            |
| 1183-1262 | **Modo `calendario`**: cards de eventos sazonais BR                     |
| 1263-1302 | **Modo `atual`**: form de parâmetros (hours, sort, debug)               |
| 1303-1352 | **Modo `historico`**: form (seed, datas) + atalhos sazonais             |
| 1354-1356 | Banner de erro                                                          |
| 1358-1368 | Banner OK do modo atual + cache info + link refresh                     |
| 1370-1410 | Resultados históricos (TOP + RISING side-by-side)                       |
| 1412-1427 | Banner "X trends salvos" (após POST salvar_aprovados)                   |
| 1429-2041 | **View "Salvos"** — dashboard rico (afiliados, web stories, GSC, push, clusters, tabela, …) |
| 2043-2326 | **View "Trends atuais"** — toolbar de filtros + tabela de trends crus  |

> A página NUNCA mostra "Salvos" e "Trends atuais" simultaneamente — `?view=saved` alterna.

## Header (1156-1181)

- **H1** com emoji 🧠.
- **Site ativo** mostrado como pill com nome + URL (sem protocolo).
- **Toggle LLM:** preserva `$_GET` removendo a chave `llm`, e renderiza 2 links que adicionam `&llm=claude` ou `&llm=openai`. O link ativo recebe background colorido. Comentário interno (linha 1165) explica a estratégia.
- **Tabs** simples (3 anchors, modo ativo recebe class `active`).

## Modo calendário (1183-1262)

Renderizado quando `$modo === 'calendario'`. Não usa scrape — chama `DiscoverCalendario::proximos($diasLim)`.

- **`$diasLim` ∈ {30, 60, 90, 180}**, default 60. Pills no H2 alternam.
- Eventos agrupados por status: `hoje`, `acionavel`, `aproximando`, `futuro`. Cada bucket tem cor + sub-mensagem (linhas 1189-1194).
- Cada evento renderiza um `.cal-card` com:
  - Nome + categoria (chip colorido)
  - Pico, dias até, antecipação
  - Tema-semente em `<code>`
  - **2 botões:** "Validar histórico" (link pra `?modo=historico&seed=...`) e "Salvar cluster" (button JS que chama `?ajax=calendario_salvar`).
  - `<details>` colapsável com lista do cluster sugerido (1º item marcado HUB).

## Modo atual / histórico (1263-1352)

Forms simples que postam pra `portal.php?go=1&modo=...`. Atalhos sazonais (linha 1345-1347) iteram `$seedsSazonais` (ver módulo 03).

**Atalhos relativos** (linhas 1336-1339):
- `data-shift="week"` — mesma semana ano passado
- `data-shift="month"` — mesmo mês ano passado
- `data-shift="quarter"` — último trimestre ano passado

> A lógica do shift está no JS (módulo 06).

## View "Salvos" — bloco gigante (1429-2041)

Renderizado quando `?view=saved`. **Mais denso da página.** Inclui:

### 1. Pré-processamento (1430-1502)
- `$db->all(['site' => $siteSlug])` ordenado por `score_discover` desc
- `DiscoverUpdater::elegiveis()` → mapa `id → idade_horas` (posts elegíveis pra refresh)
- Contadores por status (`todos`, `aprovado`, `publicado`, `suspeita`)
- Filtro de status via `?filtro=...`
- **Lazy include** `lib/DiscoverAfiliados.php` (linha 1455) → cliques 7d
- **Cache GSC local** lido de `data/search_console_cache/<site>.json`
- **Agregadores 7d** de Web Stories (`wsOk`, `wsErro`, `wsPulado`, `wsPorCluster`) e OneSignal (`osOk`, `osErro`, `osPulado`, `osRecipientsTotal`).

### 2. Persona (1506-1521)
Card colorido mostrando voz/audiência do site ativo (lido de `$cfg['persona']`). Link pra editar em `sites.php`.

### 3. Resumo + ações rápidas (1523-1547)
- H2 com nome do site + total
- Subtítulo: # elegíveis pra update
- **Botão grande "Gerar N pendentes"** se há aprovados (mostra LLM ativo no label).

### 4. Widgets sequenciais (1549-1699)
Cada um numa caixinha `flex` com emoji + métricas + link externo:

| Widget        | Linhas    | Métricas                                      | Link externo            |
|---------------|-----------|-----------------------------------------------|-------------------------|
| Afiliados 7d  | 1549-1577 | total cliques + top 3 ofertas                 | `afiliados.php`         |
| Web Stories 7d| 1579-1617 | criadas/falharam/puladas + taxa sucesso + top clusters | `wp-admin/edit.php?post_type=web-story` |
| Search Console| 1619-1666 | cliques, impressões, CTR, top 5 queries       | `search.google.com/search-console` |
| OneSignal 7d  | 1668-1699 | enviadas, subscribers, falhas, taxa           | `dashboard.onesignal.com/apps/...` |

> **Widget Web Stories** lê `web_story_info` de cada `$rr` salvo nos últimos 7 dias e agrega. Se total = 0, mostra mensagem orientando flag `WEBSTORY_ENABLED`. **Esta é a única fronteira do portal com web story — apenas leitura.**

> **Dependência externa:** `TrendsTaxonomia` é usada (1605, 1747, 1912-1915) mas NÃO está no `require_once` do bootstrap. Provavelmente vem por transitividade.

### 5. Filtros (1701-1765)

- **Pills de status** (linhas 1703-1723): Todos/Pendentes/Publicados/Suspeita, contador por bucket.
- **Busca** (linha 1724-1727): input search live (JS filtra DOM).
- **Filtros avançados** (1731-1765): cluster (select), score min (range 0-10), ROI min (range 0-10), botão limpar, contador de visíveis.

### 6. Clusters sazonais (1776-1859)

Renderiza `$cluster->listarClusters($siteSlug)`. Para cada cluster:
- Nome + data pico + contadores (publicados/total, aguardando, suspeita, interligados)
- **Botões condicionais** dependendo do estado:
  - Se há `aprovado` → botão "Gerar N (via Claude/GPT)" + (opcional) "Testar 1 via GPT" se LLM ativo é Claude
  - Se `publicados ≥ 2` e nem todos interligados → botão "Interligar"
  - Se todos interligados → botão "Re-interligar"
  - Se `< 2` publicados e nada pendente → mensagem "aguardando 2+ posts publicados"
  - Se há publicados → botão "Reformatar (DiscoverPostProcess)"
  - **Select "Mover cluster pra ..."** com lista de outros sites

### 7. Batch panel (1861-1878)

`#batch-panel` aparece se há aprovados pendentes. Botões:
- ☑ Marcar todos
- ☐ Limpar
- ▶ Iniciar lote (N) — disabled até ter seleção

`#batch-progress` (oculto inicialmente) — JS injeta painel de progresso da fila.

### 8. Tabela de salvos (1879-2039)

- Header com 11 colunas: ☐, ID, Score, Qualidade, Termo, Cluster/ROI, Intenção, Volume, Atualização, Status, Ação.
- Linha por trend, com **muita densidade visual:**
  - Score com `score-badge` + bar + label (via `scoreRotulo()`)
  - Qualidade com cor por faixa + tooltip com melhorias sugeridas
  - Cluster chip + ROI bar
  - Intenção + pain chip (se peso ≥ 3)
  - Volume + crescimento %
  - Última atualização + idade em horas
  - **Status: chip + badges Web Story (📽️ Story / 📽️ — / 📽️✗) + badges Push (🔔 Push / 🔔 — / 🔔✗) + ângulo**
  - Ação: até 6 botões dependendo do status (Editar, Reformatar, Revisar, Regerar, Atualizar, Excluir)
- Cada `<tr>` carrega `data-*` attrs usados pelo JS de filtro: `batch-id`, `search` (lowercased agregado), `status`, `cluster`, `score`, `roi`.
- Linha extra escondida `tr.u-row` pra renderizar resultado de "Atualizar" inline.

> Esta tabela mostra os badges 📽️ Story que vêm de `$r['web_story_info']` (linha 1987-2001). Apenas leitura — clicar abre `view_url` da story (ou só badge se sem URL).

## View "Trends atuais" (2043-2326)

Renderizada quando `$trends` populado e NÃO está em `?view=saved`.

- **Toolbar** (após H2): pílulas de filtro por categoria, score min, sort_by, search. Helper inline `$qs(array $override)` (linha 2053) constrói URLs preservando estado.
- **Tabela de trends crus** (estrutura paralela à de salvos, mas com colunas diferentes):
  - Score (com sazonal/threshold)
  - Termo + relacionados
  - Categoria + grupo
  - Volume + crescimento
  - Cluster chip + ROI
  - Pain + sinais
  - Intent
  - Botões: 💾 Salvar (desabilita se já salvo), 🔍 Queries, 📰 Notícias, ⚡ Gerar
- Botão **"Salvar todos aprovados"** + **"⚠️ Salvar X manual"** (ignora threshold).

> Esta tabela é a "frente" do portal — onde usuário valida/seleciona trends scrapeados antes de gravar.

## Padrões transversais

- **Aspas duplas em atributos** — atenção: o CLAUDE.md pede aspas simples, mas o código existente usa `"` em muitos lugares. **Não normalizar agora** (custo alto, ganho zero). Em código novo, seguir CLAUDE.md.
- **Inline styles abundantes:** custom design vive direto no HTML. CSS no `<style>` só pra padrões reutilizados.
- **`h()` em todo dado externo.** Seguir essa regra rigorosamente em qualquer mudança.
- **Tooltips via `title="..."`:** muita informação técnica está em tooltips (threshold, RPM, melhorias de qualidade, etc.). UX assume hover.
- **Emojis estruturais:** 🧠 portal, 🔥 atuais, 📅 histórico, 📆 calendário, 🗄 salvos, 📽️ stories, 🔔 push, 🎯 afiliados, 🔍 GSC, 🚀 fila. Padrão consistente.

## Pontos de extensão

- **Novo widget no dashboard "Salvos"?** → adicionar dentro do bloco 1523-1699 seguindo o mesmo flex/box pattern.
- **Nova coluna na tabela de salvos?** → adicionar `<th>` em 1889-1901 + `<td>` correspondente em 1927-2031 + atualizar `data-*` se for usar em filtro JS.
- **Novo modo?** → adicionar tab (1177-1181) + bloco condicional `<?php elseif ($modo === 'X'): ?>` (1263+).
- **Novo filtro client-side?** → adicionar UI em 1731-1765, atributo `data-*` na linha (1920-1926), e regra em `aplicar()` no JS (módulo 06, linha 2952).

## Notas / cheiros

- **CSS inline gigante (~270 linhas).** Move-able pra arquivo `.css` separado, mas não há benefício imediato (1 página só). Manter.
- **Densidade de informação na tabela é alta** — boa pra usuários experientes, ruim pra onboarding. Aceitável (portal é interno).
- **`TrendsTaxonomia` não está em require_once.** Se essa lib mudar de localização, render quebra silenciosamente (apenas labels viram nulls). Risco médio.
- **`DiscoverPostProcess` referenciado em 2 tooltips** (1844, 2020) — texto sobrevive mesmo se a lib estiver ausente; só os botões "Reformatar" quebram quando o handler #9 (`?ajax=reprocessar`) é chamado.
- **Sem paginação na tabela de salvos.** Se DB crescer pra milhares, render fica lento e DOM enorme. Não otimizar agora — o filtro client-side já mitiga visualmente.
