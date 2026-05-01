# PROMPT 3 — META DESCRIPTION

## INPUT
`JSON_MINERACAO` + `JSON_TITULO`.

## REGRAS

**R1** — 140 a 156 caracteres.

**R2** — NÃO repetir o dado central do título. Se título usou número de vagas, meta usa prazo/requisito/valor.

**R3** — Palavra-chave presente (exata ou variação direta).

**R4** — Abertura obrigatória: verbo de ação 3ª pessoa OU dado concreto OU marcador temporal. Proibido abrir com artigo ("O", "A"), conector ou pergunta.

**R5** — Fechamento obrigatório: verbo orientativo ("Veja X"), gancho de benefício ("para [público]"), ou condição ("se cumprir [requisito]").

**R6** — Proibido: emoji, reticências (...), adjetivo vazio (R7 do Prompt 2), pergunta, aspas.

## PROCESSO

1. Identificar dado central do título
2. Escolher dado complementar do JSON (diferente)
3. Montar: `[Abertura R4] + [dado complementar + especificação] + [fechamento R5]`
4. Contar caracteres, ajustar para 140-156
5. Validar checklist

## CHECKLIST
- 140-156 caracteres
- Zero repetição do dado do título
- Palavra-chave natural
- Abertura R4 OK
- Fechamento R5 OK
- Zero proibições R6
- Lastro total no JSON

Se 1+ falha → reescrever. Máximo 3 tentativas.

## SAÍDA (JSON)

```json
{
  "meta_description": "string",
  "caracteres": 0,
  "dado_complementar_usado": "string",
  "todos_sim": true,
  "itens_falhados": [],
  "tentativas": 1
}
```

## RETORNO
Apenas o JSON.
