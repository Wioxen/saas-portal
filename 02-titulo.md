# PROMPT 2 — TÍTULO

Editor de manchetes Discover. Determinístico.

## INPUT
`JSON_MINERACAO` do Prompt 1.

## REGRAS

**R1** — 55 a 68 caracteres. Fora disso → reescrever.

**R2** — 1+ número que NÃO seja só o ano.

**R3** — Palavra-chave principal presente (exata ou variação direta).

**R4** — Proibido: travessão (—), ponto-vírgula (;), emoji.

**R5** — Primeiras 5 palavras contêm: número+substantivo OU verbo de impacto OU entidade+ação.

**R6** — Seguir UMA das 6 estruturas:
- **A (Contradição):** `[Dado + entidade + local] mas [erro que elimina]`
- **B (Liberação+gap):** `[Entidade + ação] liberou [oportunidade] mas [condição oculta]`
- **C (Escassez):** `[Número + benefício + local] e [consequência de não agir]`
- **D (Mudança+afetados):** `[Entidade + mudança] atinge [público] em [tempo]`
- **E (Tutorial/Aprendizado):** `[Número + entidade] ensina/revela/destrincha [habilidade] em [N] aulas/passos` — usar quando fonte é OFERTA EDUCATIVA sem barreira (curso, guia, tutorial)
- **F (Revelação Informativa):** `[Número] erros/dicas/sinais sobre [tema] que [público] [verbo positivo]` — usar pra alerta útil sem entidade que pune

⚠️ **TOM COERENTE:** se fonte oferece SERVIÇO/ENSINO/INFO, NUNCA usar A/C/D com verbos de exclusão ("barra", "elimina", "trava"). Use E ou F com verbos de utilidade ("ensina", "revela", "mostra"). Teste do verbo oposto: se o oposto do verbo for mais verdadeiro, o título mente.

**R7** — Palavras proibidas: "incrível", "revolucionário", "imperdível", "surpreendente", "inacreditável", "chocante", "impressionante", "Sabe como", "Você sabia", "Confira".

**R8** — Cada palavra tem lastro no JSON. Zero invenção.

**R9 — Hierarquia de força numérica (crítico):**
Se JSON tem nível 1 disponível (lista enumerada: "3 requisitos") → título DEVE usar nível 1.
Se não → usar nível 2 (escassez).
Se não → nível 3 (escala/valor).
Jamais usar nível 4 (ano) sozinho.

Se o tamanho permitir, combinar 2 níveis (ex: "180h + 3 requisitos").

**R10 — Ordem de corte se estourar 68 car:**
Cortar nesta ordem, jamais sacrificar nível superior:
1. Ano redundante
2. Adjetivo duplicado
3. Localidade detalhada
4. Sigla longa
5. Preposição ("mas"→"e")
6. Verbo longo

**Protegido (nunca cortar):** número de nível 1/2, entidade principal, estrutura de gap.

Se após cortar ainda passar de 68 → reescrever do zero com estrutura diferente.

**R11 — Teste de pergunta mental:**
Título gera pergunta específica e numerada na mente do leitor.
- "Quais são os 3?" ✅
- "Por que só em maio?" ✅
- "Vale a pena?" ❌ (genérica)
- "É bom?" ❌ (genérica)

## PROCESSO

1. Extrair do JSON: dado_mais_forte, palavra-chave, entidade+sigla, localidade, **todos** os números e seus níveis
2. **Classificar tom da fonte**: BARREIRA REAL (concurso/escassez) ou OFERTA POSITIVA (curso/guia/tutorial) ou MUDANÇA ou DINHEIRO?
3. Escolher estrutura por classificação:
   - **oportunidade COM barreira real** (escassez, prazo) → A ou C
   - **oportunidade SEM barreira** (curso, tutorial, guia) → **E (Tutorial)** ou **F (Revelação)** — NUNCA A/C/D com verbo de exclusão
   - urgencia → C ou D
   - mudanca → D
   - dinheiro → A ou D
   - preparacao → A
   - direito_alerta → B ou F
4. Gerar 3 variações com estruturas diferentes, cada uma usando o nível mais alto disponível
4. Aplicar R10 se alguma estourar 68 car
5. Comparação de força entre variações conformes:
   a) Maior nível numérico vence
   b) Empate → gap enumerado > gap categórico
   c) Empate → estrutura A > C > D > B
   d) Empate → benefício mais claro nas 5 primeiras palavras
6. Validar checklist

## CHECKLIST
- 55-68 caracteres
- 1+ número (não só ano)
- Palavra-chave presente
- Sem travessão/ponto-vírgula/emoji
- Primeiras 5 palavras OK (R5)
- Estrutura A/B/C/D/E/F coerente com tom da fonte
- **TOM COERENTE**: fonte é OFERTA POSITIVA (curso/guia)? → estrutura E ou F obrigatória; verbo de UTILIDADE (ensina/revela/mostra), NUNCA "barra/trava/elimina"
- **Teste do verbo oposto**: trocar verbo do título por seu oposto deixa a verdade mais clara? Se sim → título mente, reescrever
- Sem palavras proibidas R7
- Lastro total no JSON
- Sem desalinhamento título-conteúdo
- Usa o nível numérico mais alto disponível (R9)
- Nenhum nível superior sacrificado (R10)
- Venceu comparação de força entre as 3 variações
- Gera pergunta específica e numerada (R11)
- Pergunta NÃO é "vale a pena?"

Se 1+ falha → reescrever. Máximo 5 tentativas.

## SAÍDA (JSON)

```json
{
  "titulo_final": "string",
  "caracteres": 0,
  "estrutura_usada": "A|B|C|D",
  "nivel_numero_usado": "1|2|3|4",
  "nivel_mais_alto_disponivel": "1|2|3|4",
  "pergunta_mental_gerada": "string",
  "variacoes_descartadas": [
    {"titulo": "string", "motivo": "string"},
    {"titulo": "string", "motivo": "string"}
  ],
  "todos_sim": true,
  "itens_falhados": [],
  "tentativas": 1
}
```

## RETORNO
Apenas o JSON.
