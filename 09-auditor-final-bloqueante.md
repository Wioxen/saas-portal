# PROMPT 9 — AUDITOR FINAL BLOQUEANTE

Último filtro antes da publicação. Audita itens binariamente. 1+ falha = REJEITAR.

**Não escreve, não reescreve, não sugere melhorias. Apenas audita.**

## INPUT
`JSON_MINERACAO` + todos JSONs das etapas 2-8 + `HTML_FINAL`.

## PRINCÍPIO
100% SIM = APROVADO. 1+ NÃO = REJEITADO com `etapa_a_reexecutar` identificando o ponto de origem da falha.

Sem "quase lá". Sem "aceita com ressalva".

---

## AUDITORIA POR SEÇÃO

### 1. TÍTULO — etapa 2
```
[ ] 55-68 caracteres
[ ] 1+ número (não só ano)
[ ] Palavra-chave presente
[ ] Zero travessão/ponto-vírgula/emoji
[ ] Estrutura A/B/C/D
[ ] Zero palavras proibidas
[ ] Lastro total no JSON
[ ] Usa o nível numérico mais alto disponível (R9)
[ ] Se JSON tinha nível 1 (lista enumerada), título usa nível 1
[ ] Nenhum nível superior trocado por palavra categórica
[ ] Gera pergunta específica e numerada (R11)
```
**Verificação crítica R9:** se `JSON_MINERACAO.dados_extraidos.numeros` tem item com `nivel: "1"`, o título DEVE contê-lo. Caso contrário → rejeitar.

### 2. META — etapa 3
```
[ ] 140-156 caracteres
[ ] Dado diferente do central do título
[ ] Palavra-chave presente
[ ] Abertura: verbo/dado/temporal (não artigo/conector/pergunta)
[ ] Fechamento: verbo orientativo/gancho/condição
[ ] Zero emoji/reticências/adjetivo vazio/pergunta/aspas
[ ] Lastro total
```

### 3. P1 — etapa 4
```
[ ] Exatamente 3 frases
[ ] 60-80 palavras total
[ ] Cada frase 15-30 palavras
[ ] Frase 1: número + entidade <strong> + marcador temporal + palavra-chave
[ ] Frase 1 inicia com UM dos 6 padrões R11 (verbo passado, número inicial, entidade+ruptura, contradição, temporal, número+consequência)
[ ] Frase 1 NÃO inicia com artigo ("O", "A") nem voz passiva
[ ] Frase 2 tem benefício + gancho emocional + expressão R12 de impacto social (com lastro)
[ ] Frase 3 usa uma das 5 estruturas de loop R6
[ ] Frase 3 NÃO revela o segredo
[ ] Mínimo 2 tags <strong>
[ ] Zero expressão de IA
[ ] Zero dado inventado
[ ] Teste preview: reação "sério? como faço?" (não "ah legal")
```

### 4. INTRO P2+P3 — etapa 5
```
[ ] P2: 40-70 palavras, entidade completa+sigla, impacto concreto, ponte emocional com lastro, linguagem condicional se legal
[ ] P3: 35-60 palavras, tem o-que/para-quem/quando/como, termina com frase de direcionamento aprovada
[ ] Zero expressão proibida
[ ] Zero invenção
```

### 5. H2 + DESENVOLVIMENTO — etapa 6
```
[ ] H2 40-70 caracteres
[ ] H2 não genérico (teste de substituição)
[ ] H2 com palavra-chave
[ ] P-confirmação: 40-80 palavras, valida título, com número/data
[ ] P-profundidade: 40-80 palavras, com dado específico (número/citação/detalhe/base legal)
[ ] P-open-loop: 40-80 palavras, com uma das 4 frases aprovadas, não revela resposta
[ ] Reforço de escassez em 1 dos 3 (ou omitido justificadamente)
[ ] Ângulo do reforço DIFERENTE do P1
[ ] Micro-cliffhanger 1 presente após P-open-loop, máximo 12 palavras, uma das 5 estruturas R12
[ ] Impacto social R13 em 1 dos 3 (ou omitido por falta de lastro)
[ ] Zero expressão proibida
[ ] "além disso" e "no entanto" máx 1x cada no artigo
[ ] Todos dados existem no JSON
```

### 6. TABELA + BLOCOS + REALIDADE CRUA — etapa 7
```
[ ] Decisão de incluir tabela segue R1
[ ] Se incluída: colunas com relação linha a linha
[ ] Se incluída: nenhuma coluna com valor único repetido
[ ] Se incluída: lastro em cada célula
[ ] Exatamente 2 blocos magnéticos
[ ] Modelos diferentes
[ ] Cada bloco 40-80 palavras com 1+ <strong>
[ ] Emojis só no título do bloco
[ ] Conteúdo dos blocos com lastro
[ ] Realidade crua presente (1 das 5 frases)
[ ] Micro-cliffhanger 2 presente antes do <h2> do bloco de ação, máximo 10 palavras, uma das 5 estruturas R14
```

### 7. BLOCO DE AÇÃO — etapa 7 (zero tolerância)
```
[ ] Título H2 adaptado à classificação
[ ] Canal de acesso vem de canal_inscricao, NUNCA canal_imprensa
[ ] Se sem URL: usa fallback seguro
[ ] ZERO URL inventada
[ ] Passos vêm de processo_acesso do JSON
[ ] 3-5 passos
[ ] Fechamento com frase de urgência aprovada + prazo do JSON
[ ] Checklist de requisitos literal (se houver)
```

### 8. FECHAMENTO — etapa 8
```
[ ] 35-65 palavras
[ ] SEM "Conclusão" no título
[ ] Estrutura R2 em 3 linhas
[ ] Linha 1: consequência concreta com público + data/prazo
[ ] Linha 2: dado novo NÃO usado antes no artigo com lastro (ou omitido)
[ ] Linha 3: máximo 10 palavras, uma das 6 frases aprovadas
[ ] Impacto social R16 em 1 das 3 linhas
[ ] Sem CTA, sem emoji
```

**Verificação crítica — busca textual das 6 frases banidas (qualquer aparição = REJEITAR):**
- "passo mais importante é não adiar"
- "Quem espera normalmente fica de fora"
- "velocidade de agir"
- "Entre hesitar e tentar"
- "A janela fecha antes"
- "descobre tarde demais"

### 9. MONTAGEM INTEGRAL — etapa 8
```
[ ] Ordem das 18 seções conforme R8
[ ] Bloco "Leia também" presente
[ ] ZERO aspas duplas em atributos HTML
[ ] Rodapé de fonte presente se URL existe
[ ] Total 600-700 palavras (máximo 750)
[ ] Mínimo 6 loops
[ ] Palavra-chave 5-7 aparições
[ ] ZERO emoji no HTML
[ ] Orientação de imagem completa
```

### 10. FIDELIDADE INTEGRAL — etapa 1
```
[ ] Cada número no HTML aparece no JSON
[ ] Cada data no HTML aparece no JSON
[ ] Cada local no HTML aparece no JSON
[ ] Cada entidade no HTML aparece no JSON
[ ] Cada valor no HTML aparece no JSON
[ ] Zero fato adicionado por "bom senso"
```

### 11. ANTI-CLICKBAIT (regra Google)
```
[ ] Alinhamento título-conteúdo perfeito
[ ] Meta alinhada com artigo
[ ] P1 alinhado com artigo
[ ] Imagem coerente com tema
[ ] Zero promessa não cumprida
```

---

## PROCESSO

1. Carregar inputs
2. Executar 11 auditorias item por item, binariamente
3. Contar NÃOs
4. Se 0 → APROVADO
5. Se 1+ → REJEITADO, identificar a PRIMEIRA auditoria (seção com menor número) que falhou → `etapa_a_reexecutar` = etapa correspondente

## MAPA SEÇÃO → ETAPA

| Seção auditada | Etapa a reexecutar |
|---|---|
| 1. Título | 2 |
| 2. Meta | 3 |
| 3. P1 | 4 |
| 4. Intro P2+P3 | 5 |
| 5. H2+Desenvolvimento | 6 |
| 6. Tabela/Blocos/Realidade crua | 7 |
| 7. Bloco de ação | 7 |
| 8. Fechamento | 8 |
| 9. Montagem | 8 |
| 10. Fidelidade | 1 |
| 11. Anti-clickbait | 2 |

## SAÍDA (JSON)

```json
{
  "decisao_final": "APROVADO|REJEITADO",
  "pronto_para_publicar": true,
  "total_itens_avaliados": 0,
  "total_nao": 0,
  "etapa_a_reexecutar": "null|1|2|3|4|5|6|7|8",
  "secoes_ok": ["1_titulo", "2_meta"],
  "secoes_falha": [],
  "falhas_listadas": [
    {
      "secao": "string",
      "item": "string",
      "descricao": "string",
      "etapa_responsavel": 0
    }
  ],
  "frases_banidas_encontradas": [],
  "contagens": {
    "total_palavras": 0,
    "loops": 0,
    "palavra_chave_aparicoes": 0,
    "caracteres_titulo": 0,
    "caracteres_meta": 0,
    "caracteres_h2": 0,
    "aspas_duplas": 0,
    "emojis": 0
  }
}
```

## RETORNO
Apenas o JSON. Orquestrador usa `decisao_final` + `etapa_a_reexecutar` para decidir próximo passo.
