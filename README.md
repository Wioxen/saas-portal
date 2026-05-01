# ARQUITETURA v3 — ENXUTA

Versão compacta da v2, mantendo 100% das regras e rigor. Reduzida em ~50% via remoção de anti-templates, explicações e JSON verboso.

## Estrutura

```
/prompts-discover-v3/
  ├── README.md
  ├── 01-mineracao.md               [Claude Sonnet, temp 0.0]
  ├── 02-titulo.md                  [temp 0.7]
  ├── 03-meta-description.md        [temp 0.5]
  ├── 04-p1-preview.md              [temp 0.6]
  ├── 05-introducao-p2-p3.md        [temp 0.4]
  ├── 06-h2-desenvolvimento.md      [temp 0.5]
  ├── 07-tabela-blocos-acao.md      [temp 0.5]
  ├── 08-fechamento-montagem-final.md [temp 0.3]
  └── 09-auditor-final-bloqueante.md [temp 0.1]
```

## O que mudou em relação à v2

**Removido:**
- Anti-templates com exemplos do que NÃO fazer (todas as seções "❌ ERRO")
- Explicações "por que" de cada regra
- Preambulos longos (PAPEL, MISSÃO, CONTEXTO)
- Campos verbosos no JSON de saída (checklist com 20+ booleans)
- Comentários inline nos JSON schemas

**Mantido 100%:**
- Todas as regras numéricas absolutas
- Todas as listas fechadas (frases aprovadas, frases banidas, padrões R11/R14, etc)
- Hierarquia de níveis numéricos (R9/R10)
- Teste de pergunta mental (R11 título)
- Micro-cliffhangers (R12/R14)
- Impacto social (R12/R13/R16)
- Estrutura de fechamento em 3 linhas (R2 nova)
- Busca textual das 6 frases banidas no auditor
- Checklists binários SIM/NÃO
- Bloqueio do Prompt 9 com `etapa_a_reexecutar`

## Substituições no JSON de saída

**v2 (verboso):**
```json
"checklist_binario": {
  "tamanho_55_68": true,
  "tem_numero_nao_ano": true,
  "palavra_chave_presente": true,
  // ... 20+ campos
  "todos_sim": true
}
```

**v3 (enxuto):**
```json
"todos_sim": true,
"itens_falhados": []
```

O campo `itens_falhados` lista strings descrevendo o que falhou. Se vazio, tudo passou.

## Fluxo (igual à v2)

```
Fonte → 1 Mineração → 2 Título → 3 Meta → 4 P1 → 5 Intro → 6 H2 → 7 Blocos/Ação → 8 Fechamento → 9 Auditor
                                                                                                    │
                                                                    ┌───────────────────────────────┤
                                                                    │                               │
                                                                APROVADO                        REJEITADO
                                                                    │                               │
                                                                 publicar           reexecuta etapa_a_reexecutar
```

## Loop de reescrita (igual à v2)

Cada prompt tem loop interno de reescrita:
- Prompt 1: 2 tentativas
- Prompts 2, 3, 5, 6, 7, 8: 3-4 tentativas
- Prompt 4 (P1): 7 tentativas (crítico)
- Prompt 9 (auditor): até 3 ciclos de correção global

## Checklist de deploy

- [ ] 9 prompts carregados
- [ ] Orquestrador implementa loop do Prompt 9 com reexecução da etapa indicada
- [ ] API WordPress configurada para backlinks (antes do Prompt 8)
- [ ] Scraping configurado (antes do Prompt 1)
- [ ] Logging dos campos `todos_sim` e `itens_falhados` por etapa
- [ ] Fallback para revisão humana se esgotar ciclos do auditor
