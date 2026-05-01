# PROMPT 5 — INTRODUÇÃO P2 + P3

## INPUT
`JSON_MINERACAO` + `JSON_P1`.

## REGRAS

**R1** — Tamanho: P2 = 40-70 palavras. P3 = 35-60 palavras.

**R2 — P2 contém obrigatoriamente:**
- Nome completo da entidade na 1ª menção + sigla entre parênteses
- Impacto real com dado concreto (número atendidos, abrangência, histórico)
- 1 frase de ponte emocional conectando o fato à vida do leitor (com lastro no JSON)
- Linguagem condicional em tema legal ("de acordo com", "segundo", "conforme")

**R3 — P3 contém obrigatoriamente, em ordem:**
- O que é
- Para quem
- Quando (prazo/marco temporal)
- Como acessar (citar canal)
- Frase de direcionamento final (R4)

**R4 — Frases de direcionamento (lista fechada):**
- "A seguir, veja [os requisitos/o passo a passo/quem pode participar]."
- "Antes de tentar [ação], é preciso entender o critério que elimina candidatos."
- "Entenda o que está em jogo e quem realmente tem chance."
- "Veja quem pode participar, quais documentos preparar e o prazo exato."
- "O passo a passo e a lista completa aparecem logo abaixo."

**R5** — Expressões proibidas: "vale destacar", "é importante", "diante disso", "cabe ressaltar", "é fundamental", "é essencial", "sendo assim", "dessa forma", "nesse sentido".

## PROCESSO

1. Extrair do JSON: entidades completas, base legal, público-alvo, prazo, canal_inscricao
2. Montar P2: entidade completa + sigla → contexto → impacto com número → ponte emocional
3. Montar P3: o que → para quem → quando → como → frase R4
4. Contar palavras

## CHECKLIST

**P2:**
- 40-70 palavras
- Nome completo + sigla
- Impacto com dado concreto
- Ponte emocional com lastro
- Linguagem condicional em tema legal (se aplicável)
- Sem expressões proibidas
- Zero invenção

**P3:**
- 35-60 palavras
- Tem: o que, para quem, quando, como
- Termina com frase R4
- Sem expressões proibidas
- Zero invenção

Se 1+ falha → reescrever. Máximo 4 tentativas por parágrafo.

## SAÍDA (JSON)

```json
{
  "p2_html": "<p>...</p>",
  "p3_html": "<p>...</p>",
  "p2_palavras": 0,
  "p3_palavras": 0,
  "frase_r4_usada": "string",
  "todos_sim": true,
  "itens_falhados": [],
  "tentativas": 1
}
```

## RETORNO
Apenas o JSON.
