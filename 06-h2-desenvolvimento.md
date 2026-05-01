# PROMPT 6 — H2 + DESENVOLVIMENTO

## INPUT
`JSON_MINERACAO` + `JSON_TITULO` + `JSON_P1` + `JSON_INTRO`.

## REGRAS

**R1** — H2 entre 40-70 caracteres.

**R2** — H2 não pode ser genérico. Teste: substituir palavra-chave do tema por outra — se ainda funciona, é genérico → reescrever.

**R3** — H2 contém palavra-chave ou variação direta.

**R4** — 3 parágrafos abaixo do H2, em ordem:
- **P-confirmação:** valida promessa do título com dados da fonte
- **P-profundidade:** detalhe técnico/legal/operacional (E-E-A-T)
- **P-open-loop:** gatilho para próximo bloco

**R5** — Cada parágrafo: 40-80 palavras.

**R6 — Frase obrigatória no P-open-loop (lista fechada):**
- "Mas tem um detalhe que [consequência]."
- "E é aqui que muita gente [erra/perde/falha]."
- "O que chama atenção, porém, é [elemento sem revelar]."
- "Só que existe um critério que [afirmação]."

**R7 — Reforço de escassez:** em 1 dos 3 parágrafos, com ângulo DIFERENTE do P1:
- P1 usou "vagas/número" → aqui usa "prazo" ou "critério"
- P1 usou "prazo" → aqui usa "concorrência" ou "critério"
- P1 usou "benefício" → aqui usa "elegibilidade" ou "janela temporal"

Omitir se fonte não sustenta.

**R8 — P-profundidade obrigatoriamente contém UM dos seguintes:**
- Número exato (valor, carga horária, prazo em dias)
- Citação direta do JSON (com autor e cargo)
- Detalhe operacional específico
- Base legal (lei, decreto, portaria)

Genérico ("oferece diversos benefícios") = reescrever.

**R9** — Expressões proibidas: "vale destacar", "é importante", "diante disso", "em suma", "nesse contexto", "cabe ressaltar", "é fundamental", "é essencial", "por fim", "sendo assim", "dessa forma", "nesse sentido".

Limites no artigo todo: "além disso" máx 1x, "no entanto" máx 1x.

**R12 — Micro-cliffhanger 1 (novo):** Após P-open-loop, antes da tabela. 1 frase isolada, máximo 12 palavras. Lista fechada:
- "Os dados abaixo explicam o porquê."
- "A tabela a seguir deixa isso claro."
- "Os números que seguem mudam a conversa."
- "Veja o que o edital realmente exige."
- "O cronograma a seguir decide quem consegue."

Posicionamento:
```html
<p>[P-OPEN-LOOP]</p>
<p style='font-weight:500; font-style:italic; color:#444; margin:20px 0;'>[cliffhanger]</p>
<!-- tabela aqui -->
```

**R13 — Impacto social no H2 (obrigatório):** em 1 dos 3 parágrafos, inserir UMA expressão (escolher 1):
- "muda a rotina de [público]"
- "tira [público] da informalidade"
- "paga conta no fim do mês"
- "fecha a porta para quem não cumpre [critério]"
- "abre caminho formal para [público]"
- "vira fonte de renda concreta"
- "resolve problema real de [público]"

[público] deve estar no JSON. Se não há lastro, omitir.

## PROCESSO

1. Escolher eixo do H2 baseado em `dado_mais_forte`:
   - Número forte → "Como [X] são distribuídas" / "Quem entra nas [X]"
   - Prazo → "Por que [X dias] muda tudo"
   - Valor → "Quem recebe [valor] e quando"
   - Mudança → "O que muda na [regra]"
   - Critério → "O critério que define quem [ação]"
2. Escrever H2 (R1 + R3)
3. Escrever 3 parágrafos na ordem
4. Inserir reforço de escassez em 1 deles (R7)
5. Inserir impacto social R13 em 1 deles
6. Adicionar micro-cliffhanger 1 (R12) após P-open-loop

## CHECKLIST
- H2: 40-70 car, não genérico, com palavra-chave
- P-confirmação: 40-80 palavras, valida título, com número/data
- P-profundidade: 40-80 palavras, contém dado específico R8
- P-open-loop: 40-80 palavras, contém frase R6, não revela resposta
- Reforço de escassez presente com ângulo diferente do P1 (ou justificado omitido)
- Micro-cliffhanger 1 presente, máximo 12 palavras, uma das 5 estruturas R12
- Impacto social R13 em 1 dos 3 parágrafos (ou justificado omitido)
- Zero expressões proibidas R9
- Limites "além disso"/"no entanto" respeitados
- Zero invenção

Se 1+ falha → reescrever. Máximo 4 tentativas.

## SAÍDA (JSON)

```json
{
  "h2_principal": "<h2>...</h2>",
  "h2_caracteres": 0,
  "p_confirmacao_html": "<p>...</p>",
  "p_profundidade_html": "<p>...</p>",
  "p_open_loop_html": "<p>...</p>",
  "micro_cliffhanger_1_html": "<p>...</p>",
  "micro_cliffhanger_1_palavras": 0,
  "reforco_escassez_em": "confirmacao|profundidade|open_loop|omitido",
  "angulo_reforco": "string",
  "angulo_p1": "string",
  "impacto_social_r13_em": "confirmacao|profundidade|open_loop|omitido",
  "frase_r6_usada": "string",
  "todos_sim": true,
  "itens_falhados": [],
  "tentativas": 1
}
```

## RETORNO
Apenas o JSON.
