# portal.php — Arquitetura de Alto Nível

> Visão de uma página só. Detalhe vai em `MODULES/`.

## Visão maior — onde portal.php se encaixa no Clonais Work

> Contexto-chave registrado em 2026-04-25 a partir de `docs/ideias-externas/g1-g11.md`. Sem esse contexto, mudanças no portal podem servir ao escopo errado.

**Clonais Work** é uma operação própria de rede de portais BR (4 domínios: `cursosenacgratuito`, `vagasebeneficios`, `comocomprararagora`, `guiadoscursos`) cujo objetivo é **milhões de acessos/mês via Google Discover + SEO + Social**, monetizados por **CPA/afiliado contextual** (não AdSense default).

**5 pilares operacionais:**
1. **Triple-threat de tráfego** — SEO + Discover + Social (Web Stories, Face, Insta)
2. **Cluster de autoridade** — 1 trend → silo de 5+ posts interligados
3. **Pingo de 5-15 min** — janela de ouro p/ sair na frente de G1/Verge/CNN
4. **IA Propõe, Humano Valida** — IA faz 95%, operador valida em 30s mobile
5. **3 motores monetários** simultâneos: Finanças (caixa) · Tech (viral) · Leis/Utilidade (autoridade)

**O papel do portal.php nessa visão:** é o **cockpit do operador** — onde ele faz a validação rápida do pilar #4. Tudo no portal deve servir a:
- Mostrar trends/clusters classificados por **dor + RPM ajustado** (não só score)
- Permitir **gerar/aprovar/regerar em poucos cliques** (fila, batch, swipe)
- Mostrar **resultado financeiro real** por motor (afiliados 7d, GSC, push)
- Operar **multi-site** (1 painel, 4 domínios)

> Mudanças no portal devem ser pesadas por: ajuda a sair na frente em 5-15 min? aumenta RPM via afiliado contextual? facilita validação de 30s no mobile? Se nenhuma delas, provavelmente é fora de escopo.

> Memórias relacionadas: `project_visao_clonais`, `project_dores_nichos_monetizacao`, `project_dominios`, `project_pingo_e_fontes` (em `~/.claude/projects/.../memory/`).

---

## O que é

`portal.php` é o **cockpit web** do pipeline Discover. Página única (PHP server-side rendering) que:

1. Coleta trends do Google (scraping) em 3 modos: **atual**, **histórico**, **calendário**.
2. Calcula score + briefing + sinais editoriais por trend.
3. Permite selecionar trends, salvar como "aprovados" no banco.
4. Dispara fila de geração de artigos (LLM → WordPress).
5. Mostra status, painel de progresso, clusters, web stories, afiliados.
6. Expõe ~22 endpoints AJAX no mesmo arquivo (`?ajax=...`).

**Padrão arquitetural:** "PHP de uma página só" — bootstrap + handlers AJAX no topo, render HTML no meio, JS no fim. Sem framework, sem MVC.

## Fluxo principal (modo "atual")

```
Browser GET portal.php?modo=atual&go=1&hours=168
        │
        ▼
[1-13] AJAX guard (não dispara aqui)
[15-39] requires
[52-60] config + site + LLM
[700-748] cria TrendsScraperWeb → busca trends → cacheia em /tmp/portal_cache_atual.json
[776-829] aplica DiscoverScore + DiscoverAngulo + DiscoverSinaisEditoriais
        │  filtros: cat, search, sort
        │  limit 500 (de ~2000)
        ▼
[1156+] renderiza HTML:
        - cabeçalho (modo + site)
        - parâmetros
        - cards de cluster
        - tabela de trends
        - batch-panel (fila)
        - widgets: clusters sazonais, web stories, afiliados
        ▼
[2327-3343] JS:
        - aplica() filtra DOM client-side
        - iniciarFila() / tickLoop() conversa com ?ajax=fila_*
        - renderPainel() pinta progresso
```

## Fluxo AJAX (exemplo: gerar 1 artigo)

```
JS chama portal.php?ajax=gerar&id=123
        │
        ▼
[1-13] AJAX guard ativa: display_errors=0, ob_start
[15-60] requires + config carregam normalmente
[645-675] handler gerar:
        - DiscoverDb::pegar(id)
        - DiscoverGerador::gerar() (Claude/OpenAI → WP REST)
        - jsonOut() devolve JSON limpo
        ▼
JS recebe JSON, atualiza UI
```

> AJAX guard é crítico: warnings PHP no buffer = JSON inválido = frontend quebra.

## Modos

| Modo        | Trigger                  | Cache                      | O que mostra            |
|-------------|--------------------------|----------------------------|-------------------------|
| atual       | `?modo=atual`            | `/tmp/portal_cache_atual.json` | Trends últimas 4h ou 168h |
| historico   | `?modo=historico`        | `/tmp/portal_cache_historico.json` | Consultas relacionadas + rising |
| calendario  | `?modo=calendario`       | -                          | Editorial calendar      |

## Endpoints AJAX (mapa)

> Todos retornam JSON. Todos passam pelo guard de display_errors. Todos chamam `jsonOut()` ou `echo json_encode + exit`.

| Linha | Endpoint              | Função                                       |
|-------|-----------------------|----------------------------------------------|
| 119   | salvar_unico          | Salva 1 trend manualmente no DB              |
| 164   | queries               | Consultas relacionadas a um termo            |
| 181   | progresso             | Progresso de uma geração                     |
| 189   | gerar_gpt             | Geração via OpenAI                           |
| 209   | revisar_post          | Re-revisa post publicado                     |
| 248   | avaliar_qualidade     | Quality score em massa                       |
| 295   | migrar_site           | Move registro de site                        |
| 312   | reprocessar           | Reformatar post (com shutdown handler)       |
| 394   | excluir_trend         | Remove trend do DB                           |
| 408   | regerar_reset         | Trash post + reset trend pra "aprovado"      |
| 445   | cluster_interligar    | Interlink de cluster                         |
| 461   | calendario_salvar     | Salva ítem do calendário                     |
| 508   | fila_iniciar          | Inicia fila de geração                       |
| 538   | fila_status           | Status da fila                               |
| 545   | fila_cancelar         | Cancela fila                                 |
| 552   | fila_limpar           | Limpa fila                                   |
| 559   | fila_tick             | Avança 1 item da fila (loop do JS)           |
| 624   | atualizar             | Atualização inteligente (Etapa 10)           |
| 645   | gerar                 | Geração + publicação completa                |
| 677   | noticias              | Artigos reais via Serper + GoogleNewsRss     |

## Estado e persistência

- **Banco:** via `lib/DiscoverDb.php` (a investigar — provavelmente SQLite ou JSON em `data/`)
- **Cache de trends:** arquivos JSON em `sys_get_temp_dir()` por modo
- **Cookies:** site ativo, LLM ativo (resolvidos por `_site_helper.php`)
- **WordPress:** estado dos posts vive lá fora; portal lê via `lib/Wordpress.php`

## Convenções de código observadas

- Aspas simples em atributos HTML (instrução do CLAUDE.md)
- `htmlspecialchars` via helper `h($s)` (linha 863)
- Mensagens de UI em PT-BR
- Comentários em PT-BR explicam o "porquê"
- Endpoints AJAX retornam SEMPRE JSON; nunca HTML
- `jsonOut()` esvazia buffer antes de imprimir (proteção contra warning leaks)

## O que NÃO está em portal.php (limites do escopo)

| Domínio                  | Onde está                              | Módulo dono              |
|--------------------------|----------------------------------------|--------------------------|
| Geração de artigo        | lib/DiscoverGerador, lib/DiscoverGeradorGPT | gerar (gerar.php)    |
| Publicação WP            | lib/Wordpress                          | compartilhada            |
| Web Story (geração)      | lib/Maquina + plugin wp-web-stories-ai | **maquina (maquina.php)**|
| Score                    | lib/DiscoverScore                      | compartilhada            |
| Briefing                 | lib/DiscoverAngulo                     | compartilhada (lida pelo portal) |
| Indexação                | lib/?, indexar.php                     | indexar                  |
| Geração em massa         | massa.php                              | massa                    |

> portal.php é **UI + orquestração**. Lógica de domínio está em `lib/`. Ao tocar em qualquer dessas libs, atualizar a doc do **módulo dono**, não a do portal.
