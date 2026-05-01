# Maquina — Bugs Conhecidos

## #001 — Cenas do web story não aparecem ao editar o post — ✅ RESOLVIDO + VALIDADO em 2026-04-26

**Reportado em:** 2026-04-24
**Status:** ✅ **RESOLVIDO + VALIDADO** em comocomprar.com.br #2795
**Severidade:** média (web story é gerada e visível publicamente, mas não editável pelo admin WP)

### Sintoma original

1. Sistema gera artigo via `DiscoverGerador::gerar()`
2. `lib/Maquina.php` orquestra → chama `lib/DiscoverWebStory.php` → POST `/wp-json/wp-wsai/v1/create-story`
3. Plugin `wp-web-stories-ai` cria a story (post type `web-story` com 5-9 cenas), salva meta `_wp_wsai_scenes` e vincula ao post original via `_wp_wsai_story_id`
4. **Bug:** ao abrir o post original no WP admin pra editar, **NÃO aparecia meta-box mostrando as cenas pra editar**

### Causa raiz (mapeada em 2026-04-26)

Plugin `wp-web-stories-ai` v1.0.0 tinha **DUAS funcionalidades distintas:**

**A) Meta-box "Iniciar Geração IA"** (registrada por `WP_WSAI_Admin::add_meta_box()`)
- Funciona ✓
- Permite criar story do zero a partir do post

**B) Meta-box "📽️ Web Story Vinculada"** (deveria registrar via `WP_WSAI_Meta_Box`)
- ❌ **NÃO funcionava** — classe existia em `class-wp-wsai-meta-box.php` mas:
  1. **Não era `require`d em `wp-web-stories-ai.php`**
  2. **Não era instanciada** em `wp_wsai_init()`
  3. **`assets/meta-box.js` que ela tentaria enfileirar não existia**
- Endpoints REST `/scenes/{post_id}` e `/regerar-story` **JÁ existiam** (em `WP_WSAI_Api`) — só faltava UI

### Correção aplicada

**1) `wp_wsai_init()` em `wp-web-stories-ai.php`:**
```php
require_once WP_WSAI_PLUGIN_DIR . 'includes/class-wp-wsai-meta-box.php';
// ...
$meta_box = new WP_WSAI_Meta_Box();
$meta_box->init();
```

**2) Criado `assets/meta-box.js`** (~180 linhas):
- Carrega cenas via `wp.apiFetch` em GET `/wp-wsai/v1/scenes/{post_id}` ao DOM ready
- Renderiza lista editável: textarea (text), input (search_term Pexels), input (cta_text), preview da imagem
- Botão "Salvar e Regerar" → POST `/wp-wsai/v1/regerar-story` com cenas editadas
- Checkbox "Buscar novas imagens no Pexels" controla `refetch_images`
- Recarrega cenas após regeração (mostra novas)

**3) Não foi necessário alterar:**
- `class-wp-wsai-meta-box.php` (já estava ok, só precisava ser instanciada)
- `class-wp-wsai-api.php` (endpoints `/scenes` e `/regerar-story` já existiam)
- `class-wp-wsai-generator.php` (não tinha bug)
- `lib/DiscoverWebStory.php` (cliente PHP do projeto, não relacionado)

### Validação pós-correção

Posts existentes com story vinculada (checados via `web_story_info` em `data/discover_trends.json`):

| Site | post_id | Termo | story_id |
|------|---------|-------|----------|
| comocomprar | 2795 | "dolar" | 2801 |
| cursosenac | 3494 | "Fies 2026 inscrições" | 3499 |
| cursosenac | 3486 | "Isenção do ENEM 2026" | 3490 |

**Como validar visualmente:**
1. Abrir `https://comocomprar.com.br/wp-admin/post.php?post=2795&action=edit` (recomendado — cursosenac local tinha timeout)
2. Procurar meta-box **"📽️ Web Story Vinculada"** na barra lateral
3. Deve mostrar 8 cenas editáveis (já que `web_story_info.scenes = 8`)
4. Botão "🔄 Salvar e Regerar Web Story" no fim
5. (Opcional) editar 1 cena → clicar regerar → confirmar nova story criada

### Detalhes técnicos

**Meta key correta** pra cenas: `_wp_wsai_scenes` (string JSON serializada via `wp_json_encode + wp_slash`)

**Endpoint que carrega:** `GET /wp-json/wp-wsai/v1/scenes/{post_id}` retorna:
```json
{
  "success": true,
  "story_id": 2801,
  "story_view_url": "https://...",
  "scenes": [
    {"text": "...", "search_term": "...", "cta_text": "...", "image_url": "..."},
    ...
  ],
  "description": "..."
}
```

**Endpoint que salva (regera):** `POST /wp-json/wp-wsai/v1/regerar-story` com body `{post_id, scenes, refetch_images}` — apaga story antiga + cria nova.

**Auth:** `wp.apiFetch` usa nonce REST do WP automaticamente; capability `edit_posts`.

### Riscos do fix

- **Risco baixo** — só ATIVA código existente (require + instanciar) + adiciona JS isolado
- **Não modifica fluxo de criação** de stories (continua via `/create-story`)
- **Reversível**: remover 4 linhas em `wp-web-stories-ai.php` + apagar `assets/meta-box.js`

### Lições aprendidas

1. **Não confiar em audit superficial** — meu audit inicial via grep disse que "AJAX hooks não estavam registrados". Estavam — só pertenciam a OUTRA meta-box. Causa raiz só apareceu lendo cada classe completa.
2. **Plugin tinha código "v24" referenciado** mas estava em estado `v1.0.0` parcialmente — meio-implementado. Fix completou a implementação.
3. **Diagnóstico exige ler o que existe + ver o que NÃO existe** (arquivo `meta-box.js` ausente foi descoberta-chave).
4. **PowerShell `Compress-Archive` cria ZIPs com backslash que quebram em Linux** — `feedback_zip_paths_unix.md` registrado.
5. **Deploy de plugin é por SITE individual** — XAMPP local NÃO sincroniza com servidores de produção; cada um dos 6 sites tem sua cópia do plugin.

### Versões do plugin entregues

- **v24** — require + instanciar `WP_WSAI_Meta_Box` + criar `assets/meta-box.js`
- **v25** — meta-box.php sempre renderiza skeleton (não bloqueia em check antecipado de `_wp_wsai_story_id`)
- **v26** — endpoint `POST /wp-wsai/v1/regenerate-scenes` + UI de botão "Regenerar Cenas via IA" pra stories antigas (criadas pré-v24)

**ZIP em produção:** `wp-web-stories-ai-v26.zip` (26.6 KB, paths Unix corretos).

### Estado por site

Validado em **comocomprar.com.br** (#2795 mostra caixa amarela + botão regenerar). Replicar v26 nos outros 5 sites:
- vagasebeneficios.com (sem stories vinculadas no DB local — meta-box vai mostrar "nenhuma vinculada")
- cursosenacgratuito.com.br (#3494 e #3486 são posts antigos — vão mostrar caixa amarela)
- guiadoscursos.com (sem stories no DB local)
- leaodabarra.com.br (sem stories no DB local)
- ondecompraragora.com (sem stories no DB local)

### Migração de stories antigas

Os **3 posts pré-v24** podem ser migrados clicando no botão "🤖 Regenerar Cenas via IA":
1. comocomprar #2795 → "Dólar abaixo de R$ 5..."
2. cursosenac #3494 → "Fies 2026 inscrições..."
3. cursosenac #3486 → "Isenção do ENEM 2026..."

Custo estimado: 3 × $0.20 = **$0.60 total** pra migrar todas. URL pública muda em cada uma (slug novo).
