# 06 — JS Frontend (linhas 2327-3343)

**Propósito:** ~1015 linhas de JavaScript que orquestram a UI: AJAX calls, fila assíncrona, polling de progresso, filtros client-side, atalhos do modo histórico. **Sem framework** (puro vanilla DOM + `fetch`).

## Estilo arquitetural

- **Event delegation no document:** quase todos os handlers usam `document.addEventListener('click', ev => { const btn = ev.target.closest('.btn-X'); if (!btn) return; ... })`. Funciona com botões adicionados dinamicamente, sem precisar re-bind.
- **IIFE pra escopar lógica complexa:** `(function() { ... })();` na fila (2488) e nos filtros (2938). Evita poluir o global.
- **`fetch` + `async/await`:** todas as chamadas usam padrão moderno, sem callbacks.
- **Confirmação UX-first:** quase toda ação destrutiva ou cara passa por `confirm()` com texto detalhando o quê + tempo estimado + custo (API).
- **Estado em DOM:** dados ficam em `data-*` attrs e `dataset.*`; sem store global.

## Mapa por linha

| Range     | Bloco                                                              |
|-----------|--------------------------------------------------------------------|
| 2328-2365 | Toggle "Ver consultas" (`btn-q`) → `?ajax=queries`                 |
| 2367-2387 | Helpers `renderQueries(d)` + `escapeHtml(s)`                       |
| 2389-2401 | Toggle briefing editorial (`btn-b`)                                |
| 2403-2431 | **Salvar 1 trend** (`btn-save-row`) → `?ajax=salvar_unico`         |
| 2433-2485 | **Polling de progresso** (`atualizarProgressosGerais` + `setInterval` 1.5s) |
| 2487-2682 | **IIFE da fila de geração:** seleção, iniciar, tickLoop, renderPainel |
| 2684-2730 | Atualizar post (`btn-u`) → `?ajax=atualizar`                       |
| 2732-2780 | Gerar artigo (`btn-g` no modo atual) → `?ajax=gerar`               |
| 2782-2827 | Toggle "Notícias reais" (`btn-n`) → `?ajax=noticias`               |
| 2829-2857 | Mover cluster pra outro site (`select-mover`) → `?ajax=migrar_site`|
| 2859-2935 | **Revisar post** (`btn-revisar`) → `?ajax=revisar_post` (UI rica)  |
| 2937-3002 | **IIFE de filtros combinados** (`busca + cluster + score + ROI`)   |
| 3004-3033 | "Gerar N pendentes" (`#gerar-tudo-pendente`) → `?ajax=fila_iniciar`|
| 3035-3054 | Avaliar qualidade em massa (`#avaliar-todos`) → `?ajax=avaliar_qualidade` |
| 3056-3086 | Reformatar (`btn-reformatar`) → `?ajax=reprocessar`                |
| 3088-3120 | Excluir trend (`btn-excluir-trend`) → `?ajax=excluir_trend`        |
| 3122-3144 | Regerar do zero (`btn-regerar`) → `?ajax=regerar_reset`            |
| 3146-3176 | Gerar 1 via GPT (`btn-gerar-gpt-unico`) → `?ajax=gerar_gpt`        |
| 3178-3211 | Gerar cluster pendente (`btn-gerar-cluster`) → `?ajax=fila_iniciar`|
| 3213-3235 | Interligar cluster (`btn-link-cluster`) → `?ajax=cluster_interligar` |
| 3237-3274 | Salvar cluster do calendário (`btn-save-cluster`) → `?ajax=calendario_salvar` |
| 3276-3302 | Gerar do calendário pós-save (`btn-gerar-do-calendario`)            |
| 3304-3342 | Atalhos de data do modo histórico (`.sc[data-shift]`, `.sc.seasonal`) |

## Funções e blocos principais

### `renderQueries(d)` — linha 2367

Constrói HTML de 2 colunas (TOP + RISING) a partir do JSON do `?ajax=queries`. Defaults pra "Sem dados" se vazio.

### `escapeHtml(s)` — linha 2385

Escape mínimo (5 caracteres: `& < > " '`). Análogo ao `h()` PHP. Usado em **toda** interpolação de HTML no JS.

### `atualizarProgressosGerais()` — linha 2438 + setInterval (linha 2485)

Polling de 1.5s. Itera todos os `.gen-progress-live[data-trend-id]` na página, agrupa IDs únicos, chama `?ajax=progresso&id=N` por ID. Renderiza barra + step atual + tempo decorrido.

**Heartbeat client-side (linhas 2451-2462):** o servidor reporta `elapsed_ms` total, mas se o step trava (Claude pensando sem atualizar arquivo) o número fica congelado. JS guarda `window._progressoStepStart[id:step]` na primeira vez que vê cada step e calcula tempo real local. Acima de 180s no mesmo step, mostra ⚠️ amarelo (provável trava). Resolve o "congelamento visual".

**Cleanup (linha 2476-2480):** quando step vira `concluido` ou `erro`, limpa entradas do heartbeat pra esse ID.

### IIFE da fila — linhas 2488-2682

Função auto-executada que controla o painel `#batch-panel`/`#batch-progress`. **Auto-resume na carga da página** (linhas 2670-2681): se há fila ativa quando a página carrega, retoma o tickLoop sem ação do usuário.

**Funções internas:**
- `getChecked()` — IDs marcados em `.batch-check:checked`
- `updateCount()` — atualiza contador + habilita botão "Iniciar"
- `iniciarFila()` (linha 2510) — monta FormData com IDs, chama `?ajax=fila_iniciar`, dispara `tickLoop()`
- `tickLoop()` (linha 2537) — while não-stopped: chama `?ajax=fila_tick` → renderiza status → pausa 2s → repete. Sai quando `terminou: true`. Acumula `interlinksDone` e `finalInterlinks`.
- `atualizarStatus()` — chama `?ajax=fila_status` e re-renderiza painel
- `renderPainel(st)` — pinta o `#batch-progress`: header colorido, barra, lista de items por status, slot de progresso pros `running`. Re-bind dos botões cancel/clear a cada render.

> **Importante:** `renderPainel` chama `atualizarProgressosGerais()` no fim (linha 2649) — assim que itens viram `running`, o polling pega.

### `aplicar()` — linha 2952 (filtros combinados)

Aplica 4 filtros simultâneos em `tr.tr-salvo`:
1. **Busca:** match em `dataset.search` normalizado (lowercase + sem acento via NFD).
2. **Cluster:** `dataset.cluster === selCluster.value`.
3. **Score min:** `dataset.score >= rngScore.value`.
4. **ROI min:** `dataset.roi >= rngRoi.value`.

`tr.style.display = hidden ? 'none' : ''`. Sincroniza visibilidade da `u-row` (linha de update inline) — esconde junto. Atualiza contador `mostrando X de Y`.

`debounce(aplicar, 80)` em busca e ranges. Reset zera todos.

### Atalhos de data (linha 3304-3342)

Computa datas relativas em JS:
- `data-shift="week"` — mesma semana ano passado (±3 dias)
- `data-shift="month"` — mês inteiro do ano passado
- `data-shift="quarter"` — últimos 3 meses do ano passado
- `.sc.seasonal[data-seed][data-mes]` — preenche seed + mês inteiro do ano passado

## Padrões de UX

### Estados visuais dos botões
- `running` — durante a chamada (cursor wait, fundo amarelo)
- `done` — sucesso (✓ verde)
- `failed` — erro (✗ vermelho)
- `saved` — finalizada e não repetível

CSS define o look (módulo 05), JS só toggla classes.

### Confirmações detalhadas
Toda ação custosa começa com `confirm()` explicando:
- O QUE vai acontecer (pipeline completo)
- TEMPO ESTIMADO (60s, 90s, ~Nmin)
- CUSTO (API, gratuito, sem IA)
- CONSEQUÊNCIA (post pra lixeira, mudança de site, etc)

Exemplo (linha 2738):
> "Gerar e publicar como RASCUNHO no WP selecionado?  
> Isso vai: 1) buscar artigos reais, 2) scrape das fontes, 3) chamar Claude, 4) criar draft no WP.  
> Tempo estimado: 30-90s."

### Reload após ação
Algumas ações (interligar, regerar, gerar, calendário-salvar) fazem `setTimeout(() => location.reload(), 1500)` pra simplificar UI — confiar que o re-render pega o novo estado.

> Trade-off: simples de implementar, mas perde estado client-side (filtros aplicados, scroll, abertura de detalhes). Aceitável.

### URLs com `&site=` interpolado
Vários `fetch` têm `<?= urlencode($siteSlug) ?>` no template — site ativo "viaja" com cada chamada. Garante que handler AJAX use o site certo mesmo se cookie expirar.

## Tratamento de erros

Padrão repetido (~16x):

```js
try {
  const r = await fetch(...);
  const d = await r.json();
  if (!d.ok) throw new Error(d.erro || 'Erro');
  // sucesso UI
} catch (e) {
  btn.textContent = '❌ ' + e.message;
  btn.disabled = false;
}
```

> Erros em network puro (no `await fetch`) são pegos pelo mesmo `catch`. Em alguns lugares (tickLoop linha 2545) há retry com `setTimeout(5000)`. Maioria não retenta — usuário aciona de novo.

## Pontos de extensão

- **Novo botão de ação?** Seguir padrão event-delegation: `document.addEventListener('click', ev => { const btn = ev.target.closest('.btn-X'); ... })`. Não bind direto em `.querySelector`.
- **Novo filtro client-side?** Adicionar UI (módulo 05) + `dataset.X` no `<tr>` + leitura em `aplicar()` (linha 2952).
- **Novo step no progresso?** Servidor (lib/DiscoverProgress) reporta. Frontend nem precisa mudar — `atualizarProgressosGerais` é genérico.
- **Auto-resume de fila com novo flow?** Adicionar lógica no IIFE da fila (linhas 2670-2681).

## Notas / cheiros

- **`<?= urlencode($siteSlug) ?>` dentro de strings JS** — funciona porque arquivo é `.php`. Mas mistura linguagens dentro de strings é frágil. Em refactor futuro, considerar passar `siteSlug` via `<meta>` ou `window.siteSlug = '<?= ... ?>'` num único lugar.
- **Polling de 1.5s mesmo sem itens running** — `atualizarProgressosGerais` faz `if (!slots.length) return` na 1ª linha, custo zero quando vazio. Aceitável.
- **17+ handlers separados** — muita repetição (FormData → fetch → json → if !ok → catch → setTimeout reload). Helper `chamarAjax(action, data, opts)` reduziria isso, mas não dá pra fazer sem refatorar tudo. Não otimizar agora.
- **Sem TypeScript / sem build step** — corresponde ao espírito do projeto (PHP de uma página só). Adicionar tooling seria fricção.
- **`alert()` em alguns erros** (linhas 2429, 2850, 2853, 3117, 3174). UX inconsistente vs estados visuais nos outros. Aceitável — são casos onde botão sumiu.
