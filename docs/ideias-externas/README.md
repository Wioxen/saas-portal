# Ideias externas (Gemini, GPT, Manus, revisores humanos)

Pasta pra armazenar conversas, análises e sugestões vindas de fora do projeto
(Gemini, ChatGPT, Manus, consultores, etc.) — pra depois Claude Code ler,
extrair acionáveis e implementar.

## Nomenclatura

```
<fonte>-<YYYY-MM-DD>-<ordem>-<tema-slug>.md
```

**Exemplos:**
- `gemini-2026-04-24-01-titulos-discover.md`
- `gemini-2026-04-24-02-cluster-ymyl.md`
- `gpt-2026-04-24-01-anti-alucinacao.md`
- `manus-2026-04-24-01-categorias-trends.md`

**Regras:**
- `fonte`: minúsculo, sem espaço. Identifica quem produziu.
- Data: ISO 8601 (`YYYY-MM-DD`). Facilita ordenação cronológica.
- `ordem`: 2 dígitos (01, 02...). Múltiplas conversas no mesmo dia.
- `tema-slug`: 2-5 palavras-chave, separadas por hífen, minúsculo.

## Template de conversa

Copie como base ao criar um arquivo novo. **Não precisa preencher tudo** —
só o que for útil. O trecho "transcrição" é o mínimo.

```markdown
---
fonte: gemini
data: 2026-04-24
tema: títulos discover
status: aberto
prioridade: alta
---

# Títulos Discover — conversa com Gemini

## Contexto

O que eu estava tentando resolver nessa conversa.

## Transcrição

Cole aqui a conversa inteira, na íntegra. Pode ser longa — não precisa
editar nem resumir. Use blockquote pra diferenciar minhas perguntas das
respostas do Gemini, se quiser.

> Minha pergunta
Resposta do Gemini...

## O que eu acho interessante

Trechos específicos que me chamaram atenção. Opcional.

## O que já existe no projeto

O que disso aí já é tratado no código atual (pra evitar duplicar trabalho).
Opcional — se não souber, Claude Code descobre.

## Decisões

- [ ] Implementar item X
- [ ] Descartar item Y (motivo)
- [ ] Estudar mais o item Z antes
```

## Fluxo de trabalho

1. **Você** cria um `.md` nessa pasta, cola a conversa, salva.
2. **Você** me avisa no chat: *"li `gemini-2026-04-24-01-titulos-discover.md`, o que dá pra aplicar?"*
3. **Eu** leio o arquivo, extraio acionáveis, comparo com o que já existe
   no código (`lib/Discover*.php`, `portal.php`, `CLAUDE.md`), sugiro
   prioridades em 3 níveis: alta / média / baixa.
4. **Você** aprova o que quer implementar.
5. **Eu** implemento. Atualizo a seção `## Decisões` do arquivo marcando
   `[x]` no que foi feito.

## Arquivos rejeitados ou feitos

Depois de tratada, mova o arquivo pra subpasta `arquivo/`:

```
docs/ideias-externas/arquivo/
├── gemini-2026-04-24-01-titulos-discover.md   # implementado
├── gemini-2026-04-22-03-schema-review.md      # descartado
```

Mantém a pasta raiz limpa com só o que está sendo trabalhado.

## O que NÃO colocar aqui

- Conversas sobre **bug específico** que já foi corrigido — isso vira
  commit e some. Essa pasta é pra IDEIAS, não pra troubleshooting.
- Ideias já implementadas — mova pra `arquivo/` após aplicar.
- Credenciais, tokens, dados sensíveis — mesmo que o Gemini tenha mostrado,
  **não cole aqui**.
</content>
