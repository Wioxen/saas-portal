# Portal Discover — Documentação Viva

> Sumário navegável da documentação de `portal.php`. Toda sessão de estudo / mudança deve começar lendo este arquivo e terminar atualizando o `CHANGELOG.md`.

**Arquivo-alvo:** `C:/xampp/htdocs/apiclaudephp/portal.php` (3345 linhas, ~175 KB)
**Escopo desta doc:** APENAS portal.php e arquivos que ele consome diretamente. Outros módulos (gerar.php, indexar.php, massa.php, etc.) não entram aqui.

---

## Como usar esta documentação

1. **Antes de mexer em portal.php** → ler INDEX.md (este arquivo) + ARCHITECTURE.md + KNOWN_ISSUES.md
2. **Antes de mexer numa seção específica** → ler o módulo correspondente em `MODULES/`
3. **Depois de qualquer mudança** → registrar entrada no CHANGELOG.md (data, o que mudou, file:line, status)
4. **Achou bug ou comportamento estranho?** → registrar em KNOWN_ISSUES.md ANTES de tentar corrigir

---

## Arquivos desta pasta

- **INDEX.md** (este) — sumário e regras de navegação
- **ARCHITECTURE.md** — visão de alto nível: o que portal.php faz, fluxo de dados, dependências
- **CHANGELOG.md** — diário cronológico de cada sessão (append-only)
- **KNOWN_ISSUES.md** — bugs conhecidos e comportamentos a investigar
- **MODULES/** — uma pasta com 1 .md por seção lógica do portal.php:
  - `01-bootstrap.md` — linhas 1-60 (AJAX guard, requires, config)
  - `02-ajax-handlers.md` — linhas 119-698 (todos os endpoints ajax=*)
  - `03-modo-cache-trends.md` — linhas 700-845 (modos, cache, score, filtros)
  - `04-helpers.md` — linhas 45-50, 767-773, 863-875 (utilitários)
  - `05-render-html.md` — linhas 877-2326 (HTML, CSS, includes)
  - `06-js-frontend.md` — linhas 2327-3343 (JavaScript)
  - `07-webstory-readonly.md` — APENAS onde portal **lê** `web_story_info` (1477, 1503, 1613, 1987). Geração da story pertence ao módulo `maquina`, não ao portal.

> Cada módulo tem o mesmo formato: **Propósito · Linhas · Entradas · Saídas · Funções principais (file:line) · Dependências externas · Pontos de extensão · Notas**.

---

## Mapa rápido por linha

| Linha    | Conteúdo                                              |
|----------|-------------------------------------------------------|
| 1-13     | Bootstrap + AJAX guard (display_errors=0, ob_start)   |
| 15-39    | 25 `require_once` de `lib/`                           |
| 45-50    | `jsonOut()`                                           |
| 52-60    | Config + resolução de site + LLM ativo                |
| 63-118   | POST salvar aprovados → redirect                      |
| 119-698  | 22 endpoints AJAX (`ajax=*`)                          |
| 700-765  | Modo (atual/historico/calendario) + cache em /tmp     |
| 767-773  | `tempoAtras()`                                        |
| 776-845  | Score + briefing + filtros + sort + atalhos sazonais  |
| 863-875  | `h()`, `scoreRotulo()`                                |
| 876      | `?>` fim do PHP puro                                  |
| 877-1155 | CSS + setup HTML                                      |
| 1156     | `<h1>🧠 Portal Discover — Etapa 1: Coleta de Trends`  |
| 1156-1270| Seletor de modo + seletor de site                     |
| 1271-1378| Parâmetros + período histórico                        |
| 1455+    | `DiscoverAfiliados` include                           |
| 1477,1987| Leitura de `web_story_info` (renderiza badge/link)    |
| 1503     | `wp-admin/edit.php?post_type=web-story` link          |
| 1613     | Aviso "Nenhuma story em 7 dias" + cfg `WEBSTORY_*`    |
| 1778     | Clusters sazonais                                     |
| 1862-76  | `#batch-panel` (fila de geração)                      |
| 2327     | `<script>` início do JS                               |
| 2367-3343| 12 funções JS (renderQueries, fila, painel, filtros)  |
| 3343     | `</script>` fim                                       |

---

## Dependências externas (lib/) que o portal consome

Da linha 15-39 (25 includes):

> Estas libs são **incluídas** pelo portal, mas a maioria pertence a outros módulos (gerar, indexar, maquina, massa…). A doc do portal só descreve **como portal as usa** — a lógica interna de cada lib é responsabilidade da doc do módulo dono.

| Lib                              | Como portal usa                       | Módulo dono (provável)  |
|----------------------------------|---------------------------------------|-------------------------|
| TrendsScraperWeb                 | scraping Google Trends                | portal                  |
| DiscoverScore                    | calcula score por trend               | portal / gerar          |
| DiscoverDb                       | persistência (aprovados, status)      | compartilhada           |
| DiscoverAngulo                   | briefing por trend                    | portal                  |
| Serper                           | Google search API                     | compartilhada           |
| Scraper                          | scraping genérico                     | compartilhada           |
| GoogleNewsRss                    | RSS Google News                       | compartilhada           |
| TrendsArticles                   | artigos por termo (ajax=noticias)     | portal                  |
| Claude / OpenAI                  | LLMs                                  | gerar / maquina         |
| Wordpress                        | bridge WP REST                        | compartilhada           |
| **Maquina**                      | leitura de info de web story          | **maquina** (NÃO portal)|
| DiscoverGerador / GPT            | pipeline de geração                   | gerar                   |
| DiscoverUpdater                  | atualização de posts                  | atualizar               |
| DiscoverFila                     | fila de geração em lote               | portal (UI da fila)     |
| DiscoverCalendario               | agendamento                           | portal (modo calendario)|
| DiscoverCluster                  | agrupamento de trends                 | cluster                 |
| DiscoverQualityScore             | score de qualidade pós-geração        | gerar                   |
| DiscoverReviewer                 | revisão automática                    | gerar                   |
| DiscoverProgress                 | progresso de jobs                     | compartilhada           |
| DiscoverPainClassifier           | classificador de "dor"                | gerar                   |
| DiscoverRPM                      | RPM / monetização                     | compartilhada           |
| DiscoverClusterMatcher           | match com clusters existentes         | cluster                 |
| DiscoverSinaisEditoriais         | pain/cluster/arbitragem               | portal (enriquece trends)|
| DiscoverAfiliados (1455)         | afiliados (lazy include)              | afiliados               |

> Coluna "Módulo dono" é hipótese inicial — confirmar caso a caso quando cada módulo for estudado.

---

## Outras integrações citadas

- `_site_helper.php` — resolução de site (`sitesDisponiveis()`, `siteAtivoSlug()`, `aplicarSite()`, `llmAtivo()`)
- `_site_select.php` — include de UI (3 vezes, linhas 1196, 1268, 1308)
- `config.php` — array de configuração
- WordPress remoto (via `wp_url` de `$cfg`) — admin de web-story
- Plugin `wp-content/plugins/wp-web-stories-ai` (v23 ativa) — geração das stories no WP

---

## Histórico

- **2026-04-25** · Documentação inicializada. Esqueleto mapeado, módulos pendentes de estudo individual.
- **2026-04-25** · Módulos 01-07 completos. Cada bloco lógico de portal.php está coberto. portal.php não foi alterado. Próxima sessão começa por leitura do INDEX + módulo relevante.
