# PROMPT 4 — P1 (PREVIEW DISCOVER)

Parágrafo mais crítico. Aparece no preview do feed.

## INPUT
`JSON_MINERACAO` + `JSON_TITULO` + `JSON_META`.

## REGRAS

**R1** — Exatamente 3 frases (terminadas em ponto final).

**R2** — Tamanho: 60-80 palavras total. Cada frase: 15-30 palavras.

**R3** — Funções obrigatórias:
- **Frase 1:** Fato + número + entidade em `<strong>` + marcador temporal + palavra-chave
- **Frase 2:** Benefício direto + ponte emocional com lastro + expressão de impacto social (R12)
- **Frase 3:** Loop que NÃO revela o segredo

**R4** — Mínimo 2 tags `<strong>`.

**R5** — Zero expressões de IA: "vale destacar", "é importante", "diante disso", "cabe ressaltar", "é fundamental", "é essencial", "sendo assim".

**R6 — Loop da frase 3 (lista fechada):**
- "O problema? [afirmação sem revelar]."
- "Só que existe [elemento] que [consequência]."
- "E é aqui que muita gente [erra/perde/falha]."
- "Mas tem [elemento/critério/detalhe] que muda tudo."
- "A questão é que [afirmação com gap]."

**R7 — Ganchos emocionais da frase 2 por classificação:**
- oportunidade → "para quem busca [primeira renda/recolocação/qualificação]"
- urgencia → "para famílias que dependem de [reforço/prazo]"
- dinheiro → "com calendário já definido" / "para quem cumpre os critérios"
- mudanca → "para quem já estava no programa"
- preparacao → "para quem planeja a próxima rodada"
- direito_alerta → "para quem sequer sabia que podia [solicitar]"

**R11 — Abertura agressiva da frase 1 (6 padrões, escolher 1):**
1. **Verbo passado + temporal:** "Começou nesta quarta-feira..." / "Abriu nesta semana..." / "Caiu o prazo..."
2. **Número impactante no início:** "180 horas de qualificação..." / "R$ 600 mensais entram..." / "15 vagas apenas..."
3. **Entidade + ruptura:** "Senac-ES liberou acesso..." / "INSS abriu brecha..." / "Caixa mudou a regra..."
4. **Contradição imediata:** "Parece simples, mas..." / "A inscrição é gratuita, porém..." / "Vagas existem. Só que..."
5. **Marcador temporal curto:** "Desde esta quarta..." / "A partir de 22 de abril..." / "Nesta semana..."
6. **Número + consequência humana:** "15 mulheres podem mudar de renda..." / "3 requisitos separam quem entra..."

**Proibido na frase 1:** iniciar com artigo ("O", "A") ou voz passiva ("Foi").

**R12 — Impacto social na frase 2 (escolher 1, com lastro no JSON):**
- "muda a rotina de [público]"
- "tira [público] da informalidade"
- "paga conta no fim do mês de [público]"
- "fecha a porta para quem não cumpre [critério]"
- "abre caminho formal para [público]"
- "vira fonte de renda concreta para [público]"
- "resolve problema real de [público]"

O [público] deve aparecer no JSON. Se não, escolher expressão sem público específico.

## PROCESSO

1. Frase 1: escolher um dos 6 padrões R11 e construir com todos elementos de R3
2. Frase 2: benefício + gancho R7 + expressão R12
3. Frase 3: escolher uma das 5 estruturas R6
4. Contar palavras, validar tags `<strong>`

## CHECKLIST
- 3 frases com ponto final
- 60-80 palavras total
- Cada frase 15-30 palavras
- Frase 1 tem todos 4 elementos + 1 dos 6 padrões R11
- Frase 1 não inicia com artigo ou voz passiva
- Frase 2 tem gancho R7 + expressão R12
- Frase 3 segue uma das 5 estruturas R6
- Frase 3 não revela o segredo
- Mínimo 2 `<strong>`
- Zero expressão R5
- Palavra-chave na frase 1
- Zero invenção
- Teste do preview: reação "sério? como faço?" (não "ah legal")

Se 1+ falha → reescrever. Máximo 7 tentativas.

## SAÍDA (JSON)

```json
{
  "p1_html": "<p>...</p>",
  "contagem": {
    "total_palavras": 0,
    "frase_1_palavras": 0,
    "frase_2_palavras": 0,
    "frase_3_palavras": 0,
    "strong_tags": 0
  },
  "padrao_r11_usado": "1|2|3|4|5|6",
  "expressao_r12_usada": "string",
  "estrutura_r6_usada": "problema|so_que_existe|aqui_muita_gente_erra|mas_tem|questao_e_que",
  "todos_sim": true,
  "itens_falhados": [],
  "tentativas": 1
}
```

## RETORNO
Apenas o JSON.
