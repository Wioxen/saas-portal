# 03 — Modo, cache e processamento de trends (linhas 700-845)

**Propósito:** depois que todos os handlers AJAX já tiveram chance de capturar a request e dar `exit`, este bloco é o que efetivamente roda quando o usuário pede a **página HTML do portal**. Resolve o modo (`atual` / `historico` / `calendario`), busca trends (com cache em /tmp), enriquece com score/briefing/sinais e aplica filtros + sort + limit.

## Linhas

| Range    | Bloco                                                                  |
|----------|------------------------------------------------------------------------|
| 700-707  | Resolução de modo + parâmetros base (`hours`, `sort`, `debug`)         |
| 709-712  | Parâmetros do modo histórico (`seed`, `data_inicio`, `data_fim`)       |
| 714-721  | Estado vazio (`$trends`, `$historico`, `$erro`, `$cacheHit`, `$cacheTs`) |
| 722      | Caminho do cache: `/tmp/portal_cache_<modo>.json`                      |
| 724-748  | Se `$buscou` (botão Atualizar): scrape + grava cache                   |
| 749-765  | Senão se cache existe: hidrata `$trends`/`$historico` do disco         |
| 776-781  | Parâmetros de filtro/sort (`scoreMin`, `catFilter`, `sortBy`, `search`, `limit`) |
| 783-829  | Enriquecimento (score, briefing, sinais) + filtros + sort + limit      |
| 831-839  | Contadores informativos (`$totalAprovados`, `$totalVisiveis`, `$totalSalvos`) |
| 841-845  | Set `$termosSalvos` (lowercase) pra UI marcar trends já no DB          |
| 847-861  | `$seedsSazonais` — 12 atalhos do calendário sazonal                    |

## Modos

```php
$modo = $_GET['modo'] ?? $_POST['modo'] ?? 'atual';
if (!in_array($modo, ['atual', 'historico', 'calendario'], true)) $modo = 'atual';
```

| Modo        | Quando usado                                          |
|-------------|-------------------------------------------------------|
| `atual`     | trends recentes (4h ou 168h)                          |
| `historico` | consultas por seed + intervalo de datas               |
| `calendario`| editorial sazonal (sem scrape — só UI + atalhos)      |

> **Observação:** o bloco de scrape (724-748) só trata `atual` e `historico`. Modo `calendario` não busca trends — a UI mostra o calendário e o botão "Salvar cluster" usa o handler AJAX `calendario_salvar`.

## Parâmetros

```
hours    ∈ {4, 168}              default 168
sort     ∈ {search-volume, recency} default 'search-volume'
debug    bool                    default false
seed     string                  histórico
data_inicio / data_fim           histórico (YYYY-MM-DD)
go       bool                    "buscou" (clicou em Atualizar)

# pós-scrape (filtros server-side):
score_min   float                default 0
cat         int (1-22, 0=todas)  default 0
sort_by     ∈ {score|volume|growth|recency|arb}  default 'score'
q           string (search)
limit       int [20, 3000]       default 500
```

## Cache em arquivo

```php
$cacheFile = sys_get_temp_dir() . '/portal_cache_' . $modo . '.json';
```

Estrutura do cache:
```json
{
  "t": 1714000000,
  "params": {"hours": 168, "sort": "search-volume"},
  "data": [...]
}
```

**Política:**
- Se `?go=1` → re-scrape e sobrescreve cache (sem TTL — controle manual via botão).
- Senão → carrega cache se existir. **Sem expiração automática.** O usuário decide quando atualizar (UI mostra "X min atrás" via `tempoAtras()`).
- Diretório: `sys_get_temp_dir()` (multiplataforma — `/tmp` no Linux, `C:\Users\X\AppData\Local\Temp\` no Windows).

> **Risco:** sem TTL, dados podem ficar velhos por horas. UI mitiga ao mostrar timestamp. Aceitável pro uso interno.

> **Risco multi-usuário:** cache é global (um arquivo por modo, sem per-user). Se 2 pessoas abrirem o portal com filtros diferentes, vão ver os mesmos dados crus (filtros aplicam em memória depois). OK enquanto for ferramenta interna.

## Enriquecimento (linhas 783-794)

Para cada trend buscado:

```php
$sc = DiscoverScore::calcular($t);
$t['score'] = $sc['final'];
$t['score_breakdown'] = $sc;
$t['threshold'] = $sc['threshold'] ?? 7.0;
$t['status_auto'] = $sc['status'] ?? 'ignorado';
$t['intencao'] = DiscoverScore::rotuloIntencao($t);
$t['briefing'] = DiscoverAngulo::gerarBriefing($t);
DiscoverSinaisEditoriais::enriquecer($t, (string)($t['briefing']['angulo_principal'] ?? ''));
```

> **Atenção:** `enriquecer()` recebe `&$t` por referência (lib muta direto). Verificar se algum handler que chama essa lib em outro contexto espera comportamento idêntico.

> Comentário linha 792: "calcula 1x aqui, render só lê" — render NÃO recalcula. Otimização importante (evita rodar `enriquecer` por linha do DOM).

## Filtros (linhas 797-813)

Acumulativos, em ordem:
1. **Categoria:** `$catFilter > 0` → mantém só trends com `categoria_ids` contendo o filtro.
2. **Search:** `$search` lowercase casa em `termo` OU em qualquer item de `relacionados[]`.
3. **Score mínimo:** `$scoreMin > 0` → mantém só trends com `score ≥ scoreMin`.

Tudo via `array_filter` + `array_values` (reindexa). Lazy — não cria índices.

## Sort (linhas 816-823)

`match` PHP 8 com 5 modos:

| sort_by | Critério                                                |
|---------|---------------------------------------------------------|
| volume  | `volume_num` desc                                       |
| growth  | `growth_pct` desc                                       |
| recency | `iniciado_em` desc (string compare ISO)                 |
| arb     | `arbitragem.arbitragem_score` desc                      |
| score   | `score` desc, tiebreak por `volume_num` desc (default)  |

## Limit (linhas 826-828)

```php
if (count($trends) > $limit) $trends = array_slice($trends, 0, $limit);
```

Padrão 500 de ~2000 scrapeados. Comentário: "pra não estourar DOM" — JS aplica filtros client-side em cima desses 500.

## Contadores informativos (linhas 831-839)

```
$totalAprovados : quantos passariam no threshold por cluster (não bloqueia, só informa)
$totalVisiveis  : count($trends) após filtros + limit
$totalSalvos    : $db->count(['site' => $siteSlug])
```

## Set `$termosSalvos`

Linhas 841-845: chama `$db->all()` e mapeia termos pra lowercase em hash `$termosSalvos`. Usado no render pra:
1. Marcar visualmente trends já salvos.
2. Desabilitar botão 💾 quando duplicado.

> **Custo O(N) por load** — chama `$db->all()` mesmo se ninguém vai usar (UI poderia ser servida sem isso). Se DB crescer muito, considerar caching ou lazy via AJAX. Não otimizar agora.

## Atalhos sazonais (linhas 847-861)

`$seedsSazonais` — array PT-BR com 12 entradas (Black Friday, Natal, Carnaval, Páscoa, Dia das Mães/Namorados, Festa Junina, Dia dos Pais, Independência, ENEM, Volta às Aulas, Férias). Cada entrada: `{seed, mes}`.

Renderizada como botões no modo histórico/calendário (usuário clica → preenche seed e calcula período sugerido).

## Pontos de extensão

- Adicionar novo modo? → 3 lugares: lista válida (linha 701), bloco de scrape (724-748), e UI no render (módulo 05).
- Novo filtro? → adicionar antes do sort (linhas 797-813), seguir padrão `array_filter + array_values`.
- Novo sort_by? → adicionar case ao `match` (linha 816-822).
- Atalho sazonal novo? → entrada em `$seedsSazonais` + ajustar render.

## Notas

- Cache global por modo é simples mas frágil em multi-usuário concorrente. Aceitável agora.
- Score/briefing rodam para TODOS os trends (até `array_slice` cortar). Se trends crescerem 10x, virá custo. `DiscoverScore::calcular()` precisa ser barato.
- Modo `calendario` praticamente não toca este bloco — ele renderiza um widget próprio (módulo 05).
