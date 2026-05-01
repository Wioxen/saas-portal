# portal.php — Bugs Conhecidos e Investigações Pendentes

> Cada bug tem ID, status, sintoma reproduzível, hipóteses e ponto de partida. **Não corrigir sem registrar aqui primeiro.**

---

> **Bugs de geração de web story NÃO pertencem a este arquivo.** Geração é responsabilidade do módulo `maquina` (entry-point `maquina.php` + `lib/Maquina.php`). portal.php apenas **lê** `web_story_info` para renderizar badge e link. Quando a doc de maquina existir, o issue real fica em `docs/maquina/KNOWN_ISSUES.md`.

---

## #001 — [moved + RESOLVIDO] Cenas do web story não aparecem ao editar

**Status:** ✅ **RESOLVIDO em 2026-04-26 no módulo `maquina`** (ver `docs/maquina/KNOWN_ISSUES.md#001`). Aqui fica só o **rastro de leitura no portal**.

### Onde portal.php toca em web story (apenas leitura)

- Linha 1477: `$wsInfo = $rr['web_story_info'] ?? null;` (badge no card)
- Linha 1503: link `wp-admin/edit.php?post_type=web-story` (admin do WP)
- Linha 1613: aviso "Nenhuma story em 7 dias" + flags `WEBSTORY_ENABLED`, `webstory_roi_min`
- Linha 1987: outra leitura de `$r['web_story_info']`

Se a investigação no módulo `maquina` revelar que portal precisa renderizar algo a mais (ex: warning "story criada mas sem cenas"), só então abrir issue novo aqui.

---

## Como adicionar novo issue

Copiar o template:

```markdown
## #NNN — Título curto

**Reportado em:** YYYY-MM-DD
**Status:** em aberto | em investigação | corrigido | wontfix
**Severidade:** baixa | média | alta | crítica

### Sintoma
(passos reproduzíveis)

### Onde no portal.php
(file:line se aplicável)

### Hipóteses iniciais
1. ...

### Próximos passos
1. ...

### O que NÃO fazer ainda
- ...
```
