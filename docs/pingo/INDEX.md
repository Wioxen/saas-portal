# Pingo — Documentação Viva

> Sumário do módulo pingo (monitoramento RSS + ingestão de trends). Ler este arquivo antes de qualquer trabalho em `lib/DiscoverPingo.php`, `pingo.php`, ou `data/fontes_pingo.json`.

**Arquivos do módulo:**
- `pingo.php` (raiz) e `scripts/pingo.php` — entry points (verificar diferença em sessão futura)
- `lib/DiscoverPingo.php` — engine
- `lib/PingoRssParser.php` — parser RSS/Atom
- `data/fontes_pingo.json` — config de fontes (13 atualmente)
- `data/pingo_state.json` — estado (último fetch, links vistos)
- `data/pingo_filtros.json` — filtro de qualidade (config externa, ver `FILTROS.md`)
- `data/fila/log_pingo_filtro.log` — log de rejeições

**Documentos nesta pasta:**
- `INDEX.md` (este) — sumário e regras
- `FILTROS.md` — filtro de qualidade em 2 camadas (rejeição + pontuação)

## Visão geral

Pingo é o **sistema de monitoramento RSS** que captura trends de fontes externas (G1, InfoMoney, Tecmundo, Google News, etc.) e popula o DB com candidatos a virar artigo.

**Ciclo:**
1. Carrega 13 fontes ativas de `fontes_pingo.json`
2. Pra cada fonte: respeita `intervalo_min`, fetch XML, parse, dedup contra state
3. **Filtro de qualidade** (camadas 1 e 2 — ver `FILTROS.md`) — rejeita lixo
4. Normaliza item → trend row, calcula score, briefing, sinais editoriais
5. Persiste em DB com status `novo` ou `aprovado` (gate por `auto_aprovar_score_min`)

## Fronteira de módulos

Pingo é **dono** de:
- `lib/DiscoverPingo.php`, `lib/PingoRssParser.php`
- `pingo.php`, `scripts/pingo.php`
- Configs e state em `data/`

Pingo **consome** (não é dono):
- `lib/DiscoverDb.php` (compartilhada)
- `lib/TrendsTaxonomia.php` (compartilhada)
- `lib/DiscoverScore.php`, `lib/DiscoverAngulo.php`, `lib/DiscoverSinaisEditoriais.php` (compartilhadas)

## Histórico

- **2026-04-26** · Doc inicializada. Filtro de qualidade em 2 camadas implementado (`FILTROS.md`).
