# Antecipação Sazonal

> **Onde mora:** `scripts/antecipar_sazonal.php`
> **Depende de:** `lib/DiscoverCalendario.php` (catálogo) + `lib/TrendsScraperWeb.php` (scrape histórico)
> **Output:** trends `aprovado` no DB com `origem='sazonal:NOME_EVENTO'`, prontos pra entrar na fila

## Por que existe

A tese central do projeto (Pilar #3 da visão Clonais Work): **sair na frente em 5-15 minutos** ou em janelas previsíveis. Datas comemorativas (Dia das Mães, Black Friday, Festa Junina) **são previsíveis com 1 ano de antecedência**.

**Discover ama frescor + utilidade.** Quem publica artigo sobre "Mensagens Dia das Mães" 15 dias antes do pico, ganha indexação + autoridade ANTES da concorrência postar no dia D.

**Sem este script:** o operador teria que manualmente abrir portal, buscar trends históricos pra cada evento, salvar um por um. Para 30+ eventos no calendário ao longo do ano, é trabalho repetitivo que escala mal.

**Com este script (cron diário):** o sistema busca queries que viralizaram **no mesmo período do ano passado**, deduplica semanticamente, salva no site correto, e os trends ficam prontos pra fila — sem intervenção humana.

## Como funciona

```
DiscoverCalendario::proximos(60)
       ↓ (eventos próximos com data_historica_inicio/fim já calculados pra ano passado)
filtro: status ∈ {hoje, acionavel, aproximando}
       ↓
pra cada evento:
   TrendsScraperWeb::consultasHistoricas(tema, data_inicio, data_fim, 'BR')
       ↓ (top + rising do ano passado)
   + cluster manual do calendário (títulos editoriais curados)
       ↓
   dedup semântico (similar_text >= 70%, prioridade curado > top > rising)
       ↓
   pra cada site_alvo (mapeado por nome ou categoria):
      DiscoverDb::upsert({status: 'aprovado', origem: 'sazonal:NOME', evento_fonte, data_pico, score 7.5-8.7})
```

## Mapeamento evento → sites

Editar diretamente em `scripts/antecipar_sazonal.php`. Há **2 níveis** (nome > categoria):

### Por categoria editorial (fallback)
| Categoria | Sites alvo |
|-----------|-----------|
| `DATA` | comocomprar, ondecompraragora |
| `FERIADO` | vagasebeneficios, comocomprar |
| `COMPRAS` | comocomprar, ondecompraragora |
| `ENTRETENIMENTO` | comocomprar, ondecompraragora |
| `EDUCAÇÃO` | cursosenac, guiadoscursos |
| `FINANÇAS` | vagasebeneficios |
| `SERVICO` | vagasebeneficios |

### Por nome do evento (override granular)
Sobrescreve o mapeamento de categoria. Array vazio = pular o evento.

Exemplos:
- `'Ayrton Senna (morte)'` → `['leaodabarra']` (F1 = esporte)
- `'Bob Marley (morte)'` → `[]` (música pura, sem nicho casado)
- `'Enem inscrições'` → `['cursosenac', 'guiadoscursos']`

## Uso

```bash
# Roda full (todos eventos próximos ≤60 dias)
php scripts/antecipar_sazonal.php

# Só 1 evento
php scripts/antecipar_sazonal.php --evento="Dia das Mães"

# Janela diferente
php scripts/antecipar_sazonal.php --dias=30

# Limita queries por evento (default 15)
php scripts/antecipar_sazonal.php --max-queries=8

# Forçar 1 site específico (sobrescreve mapeamento)
php scripts/antecipar_sazonal.php --site=cursosenac

# Sem gravar nada (preview)
php scripts/antecipar_sazonal.php --dry-run

# Detalhado (cada query)
php scripts/antecipar_sazonal.php --verbose
```

## Cron sugerido (Linux produção)

```cron
# Antecipação sazonal — diário às 3h da manhã.
# Idempotente: trends duplicados não são re-criados (DB upsert por termo+site).
0 3 * * * /usr/bin/php /caminho/scripts/antecipar_sazonal.php --max-queries=8 >> data/fila/log_antecipar.log 2>&1
```

> **Por que diário (e não semanal):** novos eventos entram na janela "acionavel" a cada dia. Rodar 1x/dia garante que nenhum perde a janela.

## Compliance Google

✅ **NÃO viola scaled content abuse:**
- Dedup semântico reduz 30+ queries similares pra 5-10 únicos por evento
- Score limitado (8.7 hub, 7.5 satélites) — não infla artificialmente
- Sites recebem apenas o que faz sentido editorialmente

✅ **NÃO é doorway pages:**
- Cada site recebe trends DIFERENTES baseado no nicho
- Sem multiplicação de URL pro mesmo intent

✅ **Aproveita histórico real:**
- Queries vêm do Google Trends (público, gratuito)
- Não inventa nada — só "antecipa" o que viraliza ano após ano

## Calibração inicial (2026-04-26)

Primeira execução, eventos próximos:

| Evento | Site(s) | Trends salvos |
|--------|---------|---------------|
| Dia do Trabalhador (5d) | vagasebeneficios + comocomprar | 8 (4+4) |
| Ayrton Senna (5d) | leaodabarra | 10 |
| Dia das Mães (14d) | comocomprar + ondecompraragora | 16 (8+8) |
| Bob Marley (15d) | — | SKIP intencional |
| PIS/Pasep (19d) | vagasebeneficios | 0 (sem volume histórico) |
| Enem inscrições (19d) | cursosenac + guiadoscursos | 6 (5+1) |

**Total: 40 trends sazonais, status=aprovado**, prontos pra fila.

## Como consumir os trends

Os trends ficam com `status='aprovado'`. Pra virar artigo:

1. **Via portal UI:** abrir `?modo=atual&view=saved&site=X`, marcar os items, "Iniciar lote"
2. **Via cron tick:** se a fila já foi criada, `scripts/tick_filas.php` processa
3. **Via script de teste:** `scripts/_criar_fila_teste.php --site=X --id=N`

## Próximos passos (TODO)

- [ ] **Auto-fila:** criar fila automaticamente após `antecipar_sazonal.php` (sem precisar do operador clicar). Considerar dias_ate_pico — só vai pra fila quando estiver dentro da janela ideal (não 60 dias antes).
- [ ] **Métricas:** logar quantos trends sazonais viraram post publicado de fato (taxa de conversão).
- [ ] **Mais eventos:** adicionar ao `DiscoverCalendario` eventos faltantes (Dia do Pediatra, Halloween BR, Outubro Rosa, Novembro Azul, Dia da Visibilidade Trans, etc.).
- [ ] **Sub-temas dinâmicos:** se "Dia das Mães" virou bombante, gerar sub-buscas ("dia das mães onde almoçar", "promoções dia das mães loja X") pra capturar long tail.

## Histórico

- **2026-04-26** · Implementado. Primeira execução: 40 trends salvos pra 5 eventos próximos. Mapeamento por nome cobrindo casos edge (Senna→leaodabarra, Bob Marley skip).
