# PROMPT 7 — TABELA + BLOCOS MAGNÉTICOS + BLOCO DE AÇÃO

## INPUT
`JSON_MINERACAO` + `JSON_TITULO` + `JSON_H2` + `angulo_usado_no_p1`.

---

## PARTE A — TABELA

**R1 — Quando incluir:**
- JSON tem listagens com 3+ itens → SIM
- JSON tem múltiplos dados comparáveis (3+ atributos) → SIM
- JSON tem calendário com datas → SIM
- Nenhum acima → OMITIR (retornar null)

**R2** — Colunas devem ter relação linha a linha. Teste: coluna 2 descreve o item da coluna 1?

**R3** — Mínimo 3 colunas significativas. Se uma coluna repete sempre o mesmo valor ("teórico e prático" 4x), trocar por outra dimensão (ex: "Por que importa").

**R4** — Cada célula com lastro no JSON.

**R5** — Até 15 itens → reproduzir integralmente. Acima → principais + informar total.

---

## PARTE B — BLOCOS MAGNÉTICOS

**R6** — Exatamente 2 blocos.

**R7** — Modelos diferentes (lista de 3):
- ⚠️ "O que ninguém te conta" (erro invisível)
- 💡 "O que quase ninguém percebe" (oportunidade escondida)
- 🔥 "Vale a pena agora?" (decisão imediata)

**R8** — Cada bloco: 40-80 palavras, 1+ `<strong>`, emojis só no título (não no corpo).

**R9** — Conteúdo com lastro no JSON. Zero invenção.

---

## PARTE C — REALIDADE CRUA

**R10** — 1 frase isolada em `<p>` próprio, entre blocos ou antes da ação. Lista fechada:
- "A vaga não espera."
- "A maioria perde por isso."
- "Quem chega depois, não entra."
- "O edital não volta atrás."
- "O prazo não negocia."

---

## PARTE D — MICRO-CLIFFHANGER 2

**R14** — Após bloco magnético 2, antes do `<h2>` do bloco de ação. 1 frase isolada, máximo 10 palavras. Lista fechada:
- "Mas tem uma condição que decide tudo."
- "Só que o prazo muda quem consegue."
- "Agir agora separa quem entra de quem fica."
- "O sistema de inscrição tem um detalhe."
- "A janela é mais curta do que parece."

Posicionamento:
```html
<div>[bloco magnético 2]</div>
<p style='font-weight:500; font-style:italic; color:#444; margin:20px 0;'>[cliffhanger 2]</p>
<h2 id='como-se-inscrever'>Como se Inscrever</h2>
```

---

## PARTE E — BLOCO DE AÇÃO

**R15 — Título adaptado à classificação:**
- oportunidade/preparacao → `<h2 id='como-se-inscrever'>Como se Inscrever</h2>`
- dinheiro → `<h2 id='como-sacar'>Como Sacar</h2>` ou `Como Receber`
- urgencia → `<h2 id='como-garantir'>Como Garantir</h2>`
- mudanca/direito_alerta → `<h2 id='como-consultar'>Como Consultar</h2>`

**R16 — Canal de acesso (CRÍTICO):**
Usar SOMENTE `canal_inscricao` do JSON, NUNCA `canal_imprensa`.

Fluxo:
1. `canal_inscricao.url` existe → usar como link direto
2. Só `canal_inscricao.nome` existe → `<strong>nome</strong>` + "conforme indicado no site oficial"
3. Nenhum existe → fallback:
```html
<p>As inscrições são feitas diretamente pelo <strong>[entidade oficial]</strong>, conforme orientação da instituição.</p>
```

**PROIBIDO:** email/telefone de imprensa como canal, URL inventada.

**R17 — Passos:** 3-5 passos vindos de `processo_acesso` do JSON. Zero passo inventado.

**R18 — Fechamento do bloco de ação (lista fechada):**
- "Leva poucos minutos. Mas só funciona se fizer isso antes de [prazo do JSON]."
- "O processo é rápido. Só não pode deixar para depois de [prazo]."
- "Entre o cadastro e a vaga garantida, o que decide é agir antes do preenchimento."

**R19 — Checklist de requisitos:** se JSON tem requisitos, adicionar `<h3>O que preparar antes</h3>` + `<ul>` com dados literais.

---

## PROCESSO

1. Decidir tabela (R1). Se SIM, montar com R2-R5
2. Escolher 2 blocos magnéticos de modelos diferentes (R6-R9)
3. Escolher 1 frase de realidade crua (R10)
4. Montar micro-cliffhanger 2 (R14)
5. Bloco de ação: título (R15) + canal (R16) + passos (R17) + fechamento (R18) + requisitos (R19)

## CHECKLIST
- Decisão tabela segue R1
- Tabela (se incluída): colunas têm relação, sem coluna repetitiva, mínimo 3 colunas, lastro total
- Exatamente 2 blocos magnéticos, modelos diferentes, 40-80 palavras cada, com lastro
- Realidade crua presente (1 das 5 frases R10)
- Micro-cliffhanger 2 presente, máximo 10 palavras, uma das 5 estruturas R14
- Bloco de ação: título adaptado, canal_inscricao (não imprensa), zero URL inventada
- 3-5 passos com lastro no JSON
- Fechamento com frase R18, prazo do JSON
- Checklist de requisitos literal (se houver)
- Zero invenção

Se 1+ falha → reescrever. Máximo 3 tentativas.

## SAÍDA (JSON)

```json
{
  "tabela": {
    "incluida": true,
    "html": "string_ou_null",
    "motivo_omissao": "string_ou_null"
  },
  "blocos_magneticos": [
    {"modelo": "ninguem_te_conta|ninguem_percebe|vale_pena_agora", "html": "<div>...</div>"},
    {"modelo": "ninguem_te_conta|ninguem_percebe|vale_pena_agora", "html": "<div>...</div>"}
  ],
  "realidade_crua_html": "<p>...</p>",
  "micro_cliffhanger_2_html": "<p>...</p>",
  "micro_cliffhanger_2_palavras": 0,
  "bloco_acao": {
    "titulo_html": "<h2>...</h2>",
    "abertura_html": "<p>...</p>",
    "passos_html": "<ul>...</ul>",
    "canal_acesso_html": "<p>...</p>",
    "canal_cenario": "1_com_url|2_sem_url|3_fallback",
    "fechamento_html": "<p>...</p>",
    "checklist_requisitos_html": "string_ou_null"
  },
  "todos_sim": true,
  "itens_falhados": [],
  "tentativas": 1
}
```

## RETORNO
Apenas o JSON.
