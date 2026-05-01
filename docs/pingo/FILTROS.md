# Filtro de Qualidade do Pingo

> **Onde mora:** `lib/DiscoverPingo.php` método `aplicarFiltro()` (chamado de `normalizarParaTrend()`).
> **Config:** `data/pingo_filtros.json` (editável sem mexer em código).
> **Log:** `data/fila/log_pingo_filtro.log` (append-only).

## Por que existe

Sem filtro, o pingo escarava QUALQUER coisa de feeds RSS — incluindo:
- Loteria (Lotofácil, Quina) — RPM ~zero, tráfego volátil
- Mortes/luto — alto sentimento negativo, Discover penaliza (g13)
- Fofoca celebridade — zero E-E-A-T, viola Helpful Content
- Política partidária pura — sem ação útil pro leitor
- Notícias internacionais sem utilidade BR

**Risco real:** **Scaled Content Abuse** (política Google de março/2024) penaliza sites que produzem volume alto de conteúdo automatizado sem valor único. Sem filtro, o tick processaria estes trends, gastaria API gerando artigos sem CTR, e **prejudicaria a aprovação do AdSense**.

## Arquitetura — 2 camadas

### Camada 1 — Rejeição explícita
Padrões reconhecidamente lixo. Se bater, rejeita imediatamente sem checar pontuação.

| Categoria | Exemplos rejeitados |
|-----------|---------------------|
| `loteria` | Lotofácil, Quina, Mega-Sena, Timemania, "concurso N" |
| `morte_luto` | morre, morreu, faleceu, luto, velório, encontrado morto |
| `fofoca_celebridade` | detona, alfineta, polêmica, fim do casamento, traição |
| `politica_partidaria` | "PT prevê", "Lula afirma", "Bolsonaro discurso" |
| `resultado_jogo_isolado` | "X x Y ao vivo", "jogo do X hoje" (com bypass pra `cluster=esportes`) |

> Regex em `data/pingo_filtros.json` — editar lá. Cada categoria tem seu próprio array de regex.

### Camada 2 — Pontuação por categoria
Termo precisa atingir `min_pontos_aprovacao` (default = 2) somando:

| Categoria | Peso | Sinaliza |
|-----------|------|----------|
| `verbo_acao` | +2 | Ação concreta: como, veja, anuncia, define, atualiza, libera... |
| `utilidade` | +2 | Conteúdo prático: prazo, edital, tarifa, inscrição, vestibular, auxílio, isenção... |
| `oportunidade` | +2 | Escassez/novidade: vagas, abertas, novo, antecipado, remanescente... |
| `categoria_favoravel` | +1 | Categoria do Google Trends bate com Empregos/Educação/Lei/Saúde |
| `temporal` | +1 | Indica frescor: hoje, amanhã, esta semana, 2026, 2027, mês... |
| `dor_monetizavel` | +2 | Ativa afiliados de alto RPM: FGTS, INSS, Bolsa Família, IR, Pix, CNH, MEI, Enem... |

**Threshold default:** 2 pontos. Termo precisa marcar pelo menos 1 categoria pesada (verbo, utilidade, oportunidade, dor) OU 2 categorias leves (categoria + temporal).

### Whitelist (bypass de ambas as camadas)

- `bypass_clusters`: cluster_hint da fonte é "esportes" (leaodabarra) ou "sazonal_calendario" — entra direto se passar camada 1
- `bypass_fontes_ids`: IDs específicos de fontes curadas que pulam tudo

## Modos de operação

```json
"modo": "warn"  // ← edita pra "block" depois de calibrar
```

| Modo | Comportamento |
|------|---------------|
| `warn` | Loga rejeição em `log_pingo_filtro.log` mas **APROVA** (deixa passar). Calibração inicial. |
| `block` | Rejeição efetiva. Trend não entra no DB. **Recomendado em produção.** |

> **Estratégia recomendada:** começar em `warn`, rodar 1-2 dias, revisar logs, calibrar listas de palavras, então mudar pra `block`.

## Calibração — script de teste offline

```bash
# Testa filtro contra trends 'novo' já no DB. Zero custo de API.
php scripts/_testar_filtro_pingo.php

# Só de um site:
php scripts/_testar_filtro_pingo.php --site=cursosenac

# Detalhado (cada decisão):
php scripts/_testar_filtro_pingo.php --verbose

# Só rejeitados:
php scripts/_testar_filtro_pingo.php --so-rejeitados
```

**Uso típico:** depois de editar `pingo_filtros.json`, rodar o teste pra ver impacto antes de subir.

## Resultado da calibração inicial (2026-04-26)

Contra **325 trends** já no DB (status=novo):
- ✅ **141 aprovados (43%)**
- ❌ **184 rejeitados (57%)**:
  - 174 por **pontuação baixa** (não tem palavras de utilidade/ação)
  - 5 por **loteria**
  - 4 por **morte/luto**
  - 1 por **fofoca celebridade**

Falsos negativos críticos detectados e corrigidos pela primeira calibração:
- "Conta de luz Aneel define bandeira tarifária" 1pt→5pt
- "Auxílio Desemprego inscrições iniciam" 1pt→5pt
- "Vestibular FECAP 2027 Inscrições bolsas" 1pt→3pt

Vocabulário expandido em 2026-04-26:
- Verbos: anuncia, define, atualiza, altera, reajusta, confirma, aprova, publica, regulamenta, decide, iniciam, começam, estreia
- Utilidade: tarifa, bandeira, fatura, inscrição, vestibular, bolsa, auxílio, desemprego, matrícula, isenção, deduzir, declarar
- Oportunidade: abre, abriu, chega, chegou, disponível, estreou

## O que falta (TODO)

- [ ] Subir modo pra `block` em produção depois de 24-48h em `warn`
- [ ] Adicionar penalização por palavras negativas (vítimas, foragido)
- [ ] Ajustar pontos por SITE (cursosenac valoriza educação +2 extra)
- [ ] Métrica: % de aprovados que viram post publicado em `done` (true positive rate)
- [ ] Painel de calibração no portal (opcional — TIER C)

## Como editar o filtro

1. Editar `data/pingo_filtros.json` (config externa, sem mexer em código)
2. Rodar `php scripts/_testar_filtro_pingo.php` pra ver impacto
3. Se OK, deploy (já vale na próxima execução do pingo)
4. Se quebrar JSON: classe usa defaults internos como fallback (não quebra runtime)

## Logs

```
[2026-04-26 14:30:12] [warn] [rejeicao_loteria] fonte=Google News BR · termo='Lotofácil hoje, concurso 3669...' · pontos=0 · detalhes={"regex_match":"..."}
[2026-04-26 14:30:13] [warn] [pontuacao_baixa] fonte=G1 Política · termo='Lula recebe alta em SP...' · pontos=0 · detalhes=[]
[2026-04-26 14:31:55] [block] [rejeicao_morte_luto] fonte=G1 Geral · termo='Morre ex-vereador...' · pontos=0 · detalhes={"regex_match":"..."}
```

Útil pra:
- Auditar se está rejeitando coisa boa por engano
- Ajustar listas de palavras
- Ver tendências (se "rejeicao_X" é alta, talvez a categoria precise ser desativada)
