# PROMPT 8 — FECHAMENTO + MONTAGEM FINAL

## INPUT
Todos os JSONs anteriores + `BACKLINKS_INTERNOS`.

---

## PARTE A — FECHAMENTO (R2 nova estrutura em 3 linhas)

**R1** — 35-65 palavras, 1 parágrafo, sem título. Palavras "Conclusão", "Considerações Finais" proibidas.

**R2 — Estrutura obrigatória em 3 linhas:**

- **Linha 1 — Consequência concreta:** o que acontece com quem não agir, com público específico do JSON. Templates:
  - "Sem cadastro até [data], [público] fica fora da próxima turma só em [mês]."
  - "Quem não confirmar [requisito] antes de [data], vê a vaga ir para [outro perfil]."
  - "Sem documentação pronta até [data], [público] assiste a turma encher pela internet."

- **Linha 2 — Dado novo:** UM número/fato/detalhe que NÃO apareceu antes no artigo. Com lastro no JSON (citação secundária, referência institucional). Se não houver dado disponível, omitir.

- **Linha 3 — Tensão curta (máx 10 palavras):** frase-soco. Lista fechada:
  - "O relógio não para para ninguém."
  - "A vaga é pra quem move primeiro."
  - "O cadastro não espera reunião de família."
  - "Prazo corrido é prazo que passa."
  - "O sistema fecha sozinho no horário."
  - "Ninguém abre exceção pra atraso."

**R3 — FRASES BANIDAS (qualquer aparição = reescrever):**
- "passo mais importante é não adiar"
- "Quem espera normalmente fica de fora"
- "velocidade de agir"
- "Entre hesitar e tentar"
- "A janela fecha antes"
- "descobre tarde demais"

**R16 — Impacto social** (obrigatório em 1 das 3 linhas):
- "muda a rotina de [público]"
- "tira [público] da informalidade"
- "paga conta no fim do mês"
- "fecha a porta para quem não cumpre [critério]"
- "abre caminho formal para [público]"
- "vira fonte de renda concreta"
- "resolve problema real de [público]"

[público] deve estar no JSON.

**R4** — Proibido: CTA explícito, emoji, expressões proibidas R9 do Prompt 6.

---

## PARTE B — BACKLINKS INTERNOS

**R5 — Quantidade:**
- BACKLINKS_INTERNOS tem 2+ posts → inserir 2
- Tem 1 → inserir 1
- Tem 0 → não inserir

**R6 — Posicionamento:**
- Backlink 1 → dentro de P1 ou P2
- Backlink 2 → dentro de P3 ou bloco de ação

**R7** — Âncora natural (2-6 palavras, contextual). Proibido: "clique aqui", "saiba mais", ou criar frase só para linkar.

---

## PARTE C — MONTAGEM HTML

**R8 — Ordem fixa obrigatória:**
1. P1 (com backlink se R6)
2. P2 (com backlink se R6)
3. P3 (com backlink se R6)
4. H2 principal
5. P-confirmação
6. P-profundidade
7. P-open-loop
8. **Micro-cliffhanger 1** (Prompt 6)
9. Bloco "Leia também"
10. Tabela (se houver)
11. Bloco magnético 1
12. Realidade crua
13. Bloco magnético 2
14. **Micro-cliffhanger 2** (Prompt 7)
15. Bloco de ação (título + abertura + passos + canal + fechamento)
16. Checklist de requisitos (se houver)
17. Fechamento
18. Rodapé de fonte (se URL existe)

**R9** — Bloco "Leia também" após Micro-cliffhanger 1, template fixo:
```html
<div class='leia-mais-box' style='background-color:#f1f3f4; border-left:4px solid #0b57d0; padding:20px; margin:30px 0; border-radius:8px;'>
  <strong style='font-size:1.1em; color:#000;'>Leia também:</strong>
  <ul id='leiamais'>
    <!-- 3 posts de BACKLINKS_INTERNOS OU placeholder <%leiamais%> -->
  </ul>
</div>
```

**R10** — Aspas simples em TODOS os atributos HTML.

**R11** — Rodapé de fonte (se URL existe):
```html
<p style='font-size:14px; color:#666; margin-top:30px;'>Fonte: Informações publicadas pelo <a href='[URL]' target='_blank' rel='noopener noreferrer'>[Nome]</a>, com adaptação editorial.</p>
```

---

## PARTE D — CONTAGENS FINAIS

**R12** — Total 600-700 palavras (máximo absoluto 750).
**R13** — Mínimo 6 loops distribuídos.
**R14** — Palavra-chave 5-7 aparições (variar entre exata, sinônimo, expandida).

---

## PARTE E — ORIENTAÇÃO DE IMAGEM

```json
{
  "tema_sugerido": "[descrição visual]",
  "alt_text": "[frase natural com palavra-chave]",
  "largura_minima_px": 1200,
  "resolucao_minima": "300K",
  "proporcao": "16:9",
  "og_image": true,
  "max_image_preview_large": true,
  "proibicoes": ["logo do site", "imagem genérica de banco", "imagem com texto sobreposto"]
}
```

---

## CHECKLIST

**Fechamento:**
- 35-65 palavras
- Sem título "Conclusão"
- Estrutura R2 em 3 linhas
- Linha 1: consequência + público específico + data/prazo
- Linha 2: dado novo com lastro (ou omitido justificadamente)
- Linha 3: máximo 10 palavras, usa uma das 6 frases R2
- Zero das 6 frases banidas R3
- Impacto social R16 em 1 das 3 linhas
- Sem CTA, sem emoji

**Backlinks:**
- Quantidade conforme R5
- Posicionamento conforme R6
- Âncoras naturais (2-6 palavras)
- Zero "clique aqui" / "saiba mais"

**Montagem:**
- Ordem das 18 seções conforme R8
- Leia também presente
- Aspas simples em todos os atributos
- Rodapé de fonte se URL existe

**Contagens:**
- Total 600-700 palavras
- Mínimo 6 loops
- Palavra-chave 5-7 aparições
- Zero emoji no HTML
- Zero aspas duplas em atributos

**Imagem:**
- Tema coerente
- Alt text com palavra-chave
- Especificações completas

Se 1+ falha → corrigir. Máximo 3 tentativas.

## SAÍDA (JSON)

```json
{
  "fechamento_html": "<p>...</p>",
  "fechamento_palavras": 0,
  "frase_r2_linha3_usada": "string",
  "dado_novo_linha2": "string_ou_omitido",
  "impacto_social_r16_em_linha": "1|2|3",
  "frases_banidas_encontradas": [],
  "backlinks_inseridos": [
    {"posicao": "p1|p2|p3|bloco_acao", "url": "string", "ancora": "string"}
  ],
  "html_final_artigo": "[HTML completo na ordem R8]",
  "contagens": {
    "total_palavras": 0,
    "loops": 0,
    "palavra_chave_aparicoes": 0,
    "aspas_duplas": 0,
    "emojis": 0
  },
  "orientacao_imagem": {
    "tema_sugerido": "string",
    "alt_text": "string",
    "largura_minima_px": 1200,
    "resolucao_minima": "300K",
    "proporcao": "16:9",
    "og_image": true,
    "max_image_preview_large": true,
    "proibicoes": ["logo do site", "imagem genérica de banco", "imagem com texto sobreposto"]
  },
  "resumo": {
    "titulo": "string",
    "meta_description": "string",
    "slug": "string (kebab-case)"
  },
  "todos_sim": true,
  "itens_falhados": [],
  "tentativas": 1
}
```

## RETORNO
Apenas o JSON.
