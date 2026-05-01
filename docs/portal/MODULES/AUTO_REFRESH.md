# AutoRefresh — G10 (Fase 2 do roadmap)

> Posts decaem em CTR após 3-7 dias. Sem refresh, perdem distribuição. Cron diário detecta queda e re-roteia post pro `DiscoverReviewer` pra atualização editorial. Transforma curva "spike + queda rápida" em "plateau sustentado".

## Arquivos

| Arquivo | Função |
|---------|--------|
| `lib/AutoRefresh.php` | Detecta posts em queda (2 janelas GSC), mapeia URL→trend_id, controla cooldown anti-loop, persiste state |
| `scripts/auto_refresh_posts.php` | Cron diário. Itera 6 sites, chama AutoRefresh + DiscoverReviewer, log estruturado |
| `data/auto_refresh_state.json` | State file com histórico de refreshes (1 evento por linha em `events[]`) |

## Como funciona (1 ciclo)

```
Pra cada site em sites.php:
  ├─ GSC.consultarPerformance(url, dias-13..-7, dim=[page]) → janela ANTERIOR
  ├─ GSC.consultarPerformance(url, dias-9..-3, dim=[page]) → janela ATUAL
  │   (offset -3 dias pq dado mais recente é preliminar no GSC)
  ├─ Pra cada page em ambas janelas:
  │   ├─ delta_clicks_pct = (clicks_atual - clicks_anterior) / clicks_anterior
  │   ├─ filtra: clicks_anterior ≥ minClicks (10 default — ruído estatístico) E delta ≤ -threshold% (-20% default)
  │   └─ ordena: queda mais forte primeiro
  ├─ Pra cada candidato (max 5/site/execução):
  │   ├─ AutoRefresh::mapearUrlParaTrendId(url, site)
  │   │     └─ Busca DiscoverDb records do site → extrai postId do url_post → wp.getPost.link → compara
  │   ├─ AutoRefresh::jaRefreshou(trendId)
  │   │     └─ true se refreshado nos últimos 14d (cooldown anti-loop) → pula
  │   ├─ DiscoverReviewer::revisar(trendId)
  │   │     └─ Aplica prompt master de revisão Sonnet → atualiza WP
  │   └─ AutoRefresh::marcarRefresh(trendId, contexto, resultado)
  │         └─ Append em data/auto_refresh_state.json
  └─ log resumo
```

## Pré-requisitos

1. **`data/google_credentials.json`** — JSON Service Account com escopo `webmasters.readonly`
2. **Service Account ativada** em cada Search Console property (configurações → Usuários → adicionar como "Restricted user")
3. **Sites com ≥14 dias de histórico GSC** — sem isso, janela anterior fica vazia e nada é detectado
4. **Posts gerados pelo `DiscoverGerador`** — só mapea trend_id pra posts que passaram pelo pipeline (posts manuais não são tocados)

## Parâmetros

| Flag | Default | O que faz |
|------|---------|-----------|
| `--site=X` | todos | Roda só num site |
| `--dry-run` | off | Detecta + mapeia, NÃO chama Reviewer |
| `--min-clicks=N` | 10 | Cliques mínimos na janela ANTERIOR (filtro de ruído) |
| `--threshold=N` | 20 | Queda mínima % pra entrar na fila |
| `--max-por-site=N` | 5 | Limite de refreshes por site/execução (custo Sonnet) |
| `--tipo=X` | discover | `discover`, `web` ou `googleNews` |
| `--quiet` | off | Sem log no stdout (cron) |
| `--historico=N` | — | Modo readonly: lista últimos N dias do state file e SAI |

## Anti-loop (cooldown)

`COOLDOWN_DIAS = 14` (constante em `AutoRefresh.php`). Cada `trend_id` só pode ser refreshed 1x a cada 14 dias. Sem isso, o cron rodaria refresh diário no mesmo post até esgotar o orçamento Sonnet sem ganho real (revisões consecutivas não trazem fato novo).

## Cron sugerido (Linux)

```cron
# Auto-refresh diário às 4h da manhã (após GSC processar dado do dia anterior)
0 4 * * * /usr/bin/php /var/www/clonais/scripts/auto_refresh_posts.php --quiet >> /var/log/clonais/auto_refresh.log 2>&1
```

## Testar localmente

```bash
# Sem GSC (vai dar erro de credenciais — esperado se não cadastrou):
php scripts/auto_refresh_posts.php --site=cursosenac --dry-run

# Com GSC autorizado (cursosenac), thresholds baixos pra qualquer sinal:
php scripts/auto_refresh_posts.php --site=cursosenac --tipo=web --min-clicks=1 --threshold=5 --dry-run

# Modo histórico (readonly):
php scripts/auto_refresh_posts.php --historico=30
```

## Métrica de sucesso

| Métrica | Alvo |
|---------|------|
| Posts refreshados/dia (rede) | 5–15 |
| % posts em queda mapeáveis | ≥80% (resto = posts manuais antigos, normal) |
| Reviewer ok rate | ≥90% |
| CTR pós-refresh +7d | retorna ao baseline ou melhor |

## Limitações conhecidas

1. **GSC tem 2-3 dias de delay** — dado de "ontem" é preliminar. Por isso usa offset -3 dias na janela atual.
2. **Sites novos sem histórico Discover** — `tipo=discover` retorna vazio. Em sites que ainda não foram aceitos no Discover Feed, usar `--tipo=web` como proxy.
3. **Mapper depende de `wp.getPost`** — N chamadas WP por execução. Cache em memória mitiga, mas com 100+ posts em queda fica lento. Se virar problema, persistir mapping em `data/url_to_trend_cache.json`.
4. **Reviewer ratio guard-rail** — se conteúdo encolhe <70% ou cresce >150%, Reviewer retorna erro. Conta como falha no resumo.
