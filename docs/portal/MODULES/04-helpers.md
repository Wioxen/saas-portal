# 04 — Helpers (linhas 45-50, 767-773, 863-875)

**Propósito:** funções utilitárias chamadas múltiplas vezes pelo PHP do portal. Pequenas, focadas, sem estado.

## Funções

### `jsonOut(array $data): void` — linha 45-50

Já documentado em `01-bootstrap.md`. Saída segura de JSON usada por handlers AJAX.

```php
function jsonOut(array $data): void {
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
```

### `tempoAtras(int $ts): string` — linha 767-773

Formata timestamp como "tempo atrás" relativo ao agora.

```php
function tempoAtras(int $ts): string {
    if ($ts <= 0) return '';
    $diff = time() - $ts;
    if ($diff < 60)   return $diff . 's atrás';
    if ($diff < 3600) return (int)($diff/60) . ' min atrás';
    if ($diff < 86400)return (int)($diff/3600) . 'h atrás';
    return (int)($diff/86400) . 'd atrás';
}
```

| Faixa     | Saída            |
|-----------|------------------|
| `<= 0`    | `""` (string vazia) |
| `< 60s`   | `Ns atrás`       |
| `< 1h`    | `N min atrás`    |
| `< 24h`   | `Nh atrás`       |
| resto     | `Nd atrás`       |

**Uso:** mostrar "X min atrás" do cache de trends (ver módulo 03 — `$cacheTs`).

> Não cobre semanas/meses/anos. Suficiente porque cache raramente passa de 24h.

### `h($s): string` — linha 863

Atalho pra `htmlspecialchars` com defaults seguros.

```php
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
```

`ENT_QUOTES` escapa aspas simples E duplas — coerente com a regra de "aspas simples em atributos HTML" do CLAUDE.md (mesmo se algo tiver aspas no conteúdo, não quebra).

**Uso:** ubíquo no render. Toda interpolação de dado externo no HTML deve passar por `h()`.

### `scoreRotulo(float $score, float $threshold): array` — linha 870-875

Classifica score em 4 níveis baseado no threshold do cluster (não em valor absoluto).

```php
function scoreRotulo(float $score, float $threshold): array {
    if ($score >= $threshold + 1.5) return ['s-excelente', 'Excelente'];
    if ($score >= $threshold)       return ['s-bom',       'Bom'];
    if ($score >= $threshold - 1)   return ['s-medio',     'Médio'];
    return ['s-fraco', 'Fraco'];
}
```

| Score                            | Classe CSS    | Label      |
|----------------------------------|---------------|------------|
| `>= threshold + 1.5`             | s-excelente   | Excelente  |
| `>= threshold`                   | s-bom         | Bom        |
| `>= threshold - 1`               | s-medio       | Médio      |
| `< threshold - 1`                | s-fraco       | Fraco      |

**Por que threshold por trend e não fixo?** Comentário linha 866: "respeita threshold do cluster". Cada trend tem seu próprio `threshold` (vem de `DiscoverScore::calcular`), e o rótulo é relativo. Score 7 num cluster threshold 7 = "Bom"; mesmo 7 num cluster threshold 8 = "Médio".

**Uso:** render aplica `[$cls, $lbl] = scoreRotulo($t['score'], $t['threshold'])` por linha.

## Pontos de extensão

- Novo formatter? → adicionar aqui, pequeno, sem dependência. Se crescer, mover pra `lib/`.
- `h()` é importante — não duplicar. Se faltar variação (ex: escape pra atributo de JS), criar novo helper, não modificar `h()`.

## Notas

- 4 funções, ~15 linhas de lógica total.
- Nenhuma usa estado global (recebem tudo por argumento ou são puras).
- `tempoAtras` e `scoreRotulo` poderiam morar em uma lib utilitária, mas são pequenas o bastante pra ficar no portal.
