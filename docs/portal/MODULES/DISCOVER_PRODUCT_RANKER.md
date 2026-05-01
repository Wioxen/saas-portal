# DiscoverProductRanker — G6 (Fase 2 do roadmap)

> Quando o termo da trend pede LISTA DE PRODUTOS, busca os top vendidos REAIS da Amazon BR e injeta como contexto factual pro Sonnet escrever em volta. Substitui "Sonnet inventa 10 produtos" por "Sonnet escreve em volta de 10 produtos REAIS com nome+preço+imagem corretos".

## Arquivos

| Arquivo | Função |
|---------|--------|
| `lib/AmazonScraper.php` | Fetch HTML de `/gp/bestsellers/{categoria}` + parse DOM (ASIN, nome, img, preço, rank). Cache 24h, retry exponencial 0/3/7s, cookie jar, fallback cache stale em bloqueio |
| `lib/DiscoverProductRanker.php` | Detecta intent (regex CONSERVADOR), mapeia termo→categoria Amazon, gera bloco prompt + tabela HTML |
| `scripts/_testar_product_ranker.php` | Validação offline. Usos: termo direto, `--status`, `--html`, `--salvar` |

## Categorias suportadas

`electronics`, `home`, `toys`, `beauty`, `sports`, `books` — cobrem ~80% das trends de produto. URLs reais:

```
https://www.amazon.com.br/gp/bestsellers/{slug}/?language=pt_BR
```

## Fluxo

```
DiscoverGerador::gerar(trend)
  ├─ 3e) DiscoverProductRanker::detectarIntent(termo, cluster_key) → {categoria, limite} | null
  │       ├─ null  → segue fluxo normal (CTA single 5f)
  │       └─ obj   → AmazonScraper::obterBestsellers → cache 24h ou scrape
  │                  → bloco "PRODUTOS REAIS" entra no $blocos[] do prompt
  │                  → Sonnet escreve em volta + insere placeholder <!-- DISCOVER_TABELA_PRODUTOS -->
  ├─ 4) Maquina::rodar (Claude/Sonnet gera HTML)
  ├─ 5a-5b) post-process + interlinks + auditoria
  ├─ 5c) Substitui placeholder por tabela HTML rica (img+preço+botão por produto)
  │       → marca rankerInjetado=true
  └─ 5f) AFILIADO CTA single — DESLIGADO se rankerInjetado (1 tabela rica > 2 CTAs competindo)
```

## Detecção de intent (CONSERVADOR)

Dois filtros AND:

**(1) Gatilho regex** — pelo menos um bate:
- `top|melhores|mais vendidos|ranking + N` (ex: "top 10")
- `N + melhores|ideias|kits|opções` (ex: "10 melhores", "5 ideias")
- `N + [substantivo] + mais vendidos|baratos|melhores` (ex: "8 brinquedos mais vendidos")
- `presentes/ideias de presente/kits + de|para|pra` (ex: "presentes para dia das mães")
- `o que comprar|comprar (no|para|pra)`
- `até|abaixo de|menos de R$ N` (ex: "até R$ 100")
- `produtos mais vendidos|achados (da )?amazon`

**(2) Cluster permitido** — `lifestyle_consumo`, `comidas_bebidas`, `tecnologia`, `esportes`. Outros clusters (notícia, finanças, política, educação) → null mesmo se gatilho bate. Anti-falso-positivo crítico: "10 melhores presidentes" (curiosidades_geral) NÃO ATUA.

Mapeamento termo → categoria Amazon:
- keyword `criança/brinquedo/infantil/bebê` → `toys`
- `perfume/maquiagem/skincare/beleza` → `beauty`
- `tecnologia/fone/notebook/celular` → `electronics`
- `cozinha/casa/decoração/dia das mães` → `home`
- `esporte/fitness/academia/camisa` → `sports`
- `livro/leitura` → `books`
- fallback por cluster: lifestyle→home, comidas→home, tecnologia→electronics, esportes→sports

## Cache

`data/cache/amazon_bestsellers/{categoria}.json`:

```json
{
  "produtos": [{ "asin": "B0...", "nome": "...", "img": "https://...", "preco_brl": "R$ 37,94", "preco_num": 37.94, "url": "https://...", "rank": 1 }],
  "fetched_at": 1714234567,
  "categoria": "home",
  "url": "..."
}
```

TTL 24h. Em bloqueio (captcha/HTTP 5xx/body curto): adiciona `blocked_until: ts+6h` e serve cache stale (preferível a vazio).

## URL de afiliado

**Cada produto vira um Pretty Link individual** via plugin REST `cc-prettylinks-api`:

- Slug: `produto-{ASIN-lowercase}` (ex: `/go/produto-b07fgyqckm`)
- Target: `https://www.amazon.com.br/dp/{ASIN}` (com `?tag={TAG}` se `cfg.amazon_associates_tag` setado)
- Vantagem: quando user cadastrar Amazon Associates BR, edita Pretty Links no WP **sem reescrever posts**
- Fallback (plugin Pretty Links offline, REST 5xx, ASIN inválido): `amzn.to/4ckOgUc`

A criação do Pretty Link é idempotente — `PrettyLinks::criarOuBuscar()` retorna o existente se já existe, cria se novo.

## Por que não há paridade no DiscoverGeradorGPT

GPT-mini é o caminho **barato** (Trend-Scoring Gate desvia trends de score < 7 pra ele). Termos de produto têm intenção comercial alta → score ≥ 7 → Sonnet. Não faz sentido onerar GPT-mini com scrape Amazon. Se um dia uma trend de produto cair no GPT (score baixo), perde apenas a tabela rica — mantém CTA single (5f) normal.

## Como testar

```bash
# Casos negativos (devem dar null):
php scripts/_testar_product_ranker.php "INSS aposentadoria 2026"
php scripts/_testar_product_ranker.php "10 melhores presidentes" --cluster=curiosidades_geral
php scripts/_testar_product_ranker.php "Lula reforma agrária" --cluster=noticias_info_critica

# Casos positivos:
php scripts/_testar_product_ranker.php "10 ideias de presente dia das mães" --cluster=lifestyle_consumo
php scripts/_testar_product_ranker.php "Top 5 fones bluetooth" --cluster=tecnologia
php scripts/_testar_product_ranker.php "8 brinquedos mais vendidos" --cluster=lifestyle_consumo

# Estado do cache:
php scripts/_testar_product_ranker.php --status

# Salva preview HTML em /tmp pra inspecionar visualmente (URLs vão pro fallback fixo):
php scripts/_testar_product_ranker.php --salvar "10 presentes dia das mães" --cluster=lifestyle_consumo

# Teste end-to-end com PrettyLinks reais de um site WP:
php scripts/_testar_product_ranker.php --site=comocomprar --salvar "10 presentes dia das mães" --cluster=lifestyle_consumo
```

## Limitações conhecidas (follow-ups)

1. **Mapeamento `home` é dominado por produtos de limpeza** — Amazon BR `home` bestsellers tem Finish/detergente no top. Pra "Dia das Mães" idealmente queremos `home-and-kitchen` ou `kitchen`. Iteração futura: subcategorias específicas (`home/kitchen`, `home/garden`).
2. **Sem subcategoria por preço** — quando termo diz "até R$ 100", ranker traz top vendidos sem filtrar por preço. Iteração: filtrar por `preco_num` no `obter()`.
3. **Re-rank por relevância ao termo** — top vendido absoluto ≠ top vendido **pra mãe**. Iteração: scoring entre termo e nome do produto antes de cortar pelo limite.
4. **Sem PA API** — usa scraping. Quando Amazon Associates BR estiver com vendas comprovadas, migrar pra PA API (mais estável, dados mais ricos).
5. **Sem GPT paridade** — decisão consciente (acima). Se Trend-Scoring Gate desviar trend produto pro GPT, perde tabela rica.

## Métrica de sucesso (Fase 2)

| Métrica | Alvo |
|---------|------|
| % artigos de produto com 5+ produtos REAIS | ≥70% |
| CTR afiliado em artigos com tabela | ≥2x CTR de artigo com CTA single |
| Falso positivo (ranker em trend não-produto) | 0 — protegido por cluster filter |
| Tempo de scrape (cache miss) | <8s p95 |
| Tempo de scrape (cache hit) | <100ms |
