# Módulo Maquina — Documentação Viva

> Sumário do módulo `maquina` (orquestração de geração de artigos + web story + redes sociais). Ler antes de tocar em `maquina.php`, `lib/Maquina.php`, ou plugin `wp-web-stories-ai`.

## Arquivos do módulo

**Entry point + lib:**
- `maquina.php` (raiz, 458 linhas) — entry point
- `lib/Maquina.php` (26 KB) — orquestrador

**Sub-componentes (libs):**
- `lib/DiscoverWebStory.php` — cliente que chama `/wp-json/wp-wsai/v1/create-story` no plugin WP
- `lib/CarrosselGenerator.php` (18 KB) — gera arte JPG pra Instagram (não publica ainda — TIER C)
- `lib/Meta.php` — cliente Graph API Facebook/Instagram (auditar uso completo)

**Plugin WordPress (parte do escopo do módulo):**
- `wp-content/plugins/wp-web-stories-ai/` (v1.0.0)
  - `wp-web-stories-ai.php` — entry point
  - `includes/class-wp-wsai-admin.php` — meta-box "Iniciar Geração IA" (criar do zero)
  - `includes/class-wp-wsai-meta-box.php` — meta-box "📽️ Web Story Vinculada" (editar story já criada)
  - `includes/class-wp-wsai-api.php` — REST API: `/create-story`, `/scenes/{id}`, `/regerar-story`
  - `includes/class-wp-wsai-generator.php` — geração de cenas via OpenAI GPT-4o-mini
  - `includes/class-wp-wsai-openai.php` — cliente OpenAI
  - `assets/admin.js` — UI do botão "Iniciar Geração IA"
  - `assets/meta-box.js` — UI da meta-box "Web Story Vinculada" (criada em 2026-04-26)
  - `templates/single-web-story.php` — template AMP

## Documentos nesta pasta

- `INDEX.md` (este) — sumário
- `KNOWN_ISSUES.md` — bugs conhecidos (#001 RESOLVIDO em 2026-04-26)

## Visão geral

`Maquina` é o **orquestrador final do pipeline editorial**: pega o conteúdo gerado pelo `DiscoverGerador`, publica no WP, e dispara componentes adicionais (Web Story, Push, Social).

**Fluxo conceitual:**
```
DiscoverGerador → Maquina → [Wordpress (post draft)] → DiscoverWebStory → wp-web-stories-ai plugin → AMP story
                                                    → DiscoverOneSignal (push)
                                                    → Meta (FB/IG) — TIER C
```

**Schema de `web_story_info`** (campo do trend após geração):
```
{
  ok: bool,           // sucesso
  story_id: int,      // ID do post web-story no WP
  scenes: int,        // número de cenas geradas
  view_url: string,   // URL pública da story
  tempo_ms: int,      // tempo de geração
  pulado: bool,       // pulado por gating (ROI baixo, desabilitado)
  erro: string,       // mensagem se falhou
  http_code: int      // status HTTP da chamada
}
```

## Fronteira de módulos

Maquina é **dono** de:
- `lib/Maquina.php`, `maquina.php`
- `lib/DiscoverWebStory.php` (cliente do plugin)
- `lib/CarrosselGenerator.php`, `lib/Meta.php` (a confirmar)
- Plugin `wp-content/plugins/wp-web-stories-ai/` (custom do projeto)

Maquina **consome** (não é dono):
- `lib/Wordpress.php` (compartilhada)
- `lib/DiscoverDb.php` (compartilhada)
- `lib/DiscoverOneSignal.php` (compartilhada)

## Histórico

- **2026-04-26** · Doc inicializada. Bug #001 (cenas Web Story não aparecem ao editar) **RESOLVIDO** — ver `KNOWN_ISSUES.md`.
