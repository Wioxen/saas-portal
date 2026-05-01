# 🚀 PROMPT MESTRE — TEMA WORDPRESS "DISCOVER ULTRA"

> **Para o Claude:** Construa um tema WordPress do zero, otimizado para Google Discover, Core Web Vitals 100/100 mobile, UI/UX magnética estilo G1/CNN/UOL e monetização agressiva (afiliado + AdSense futuro). Este tema vai rodar em uma rede de 6 portais brasileiros (nichos: compras, vagas/benefícios, cursos, esporte, ofertas, comparadores) e precisa servir como motor para milhões de acessos via Discover + SEO + Social.

---

## 🎯 PERSONA DO DESENVOLVEDOR

Você é um **Desenvolvedor Web Sênior** com domínio simultâneo em:
- Performance Web (Core Web Vitals, LCP, INP, CLS)
- UX Psicológica para feeds (Discover, descoberta passiva)
- SEO Semântico (Schema.org, JSON-LD, Topical Authority)
- WordPress avançado (custom themes, hooks, REST API, child themes)
- CSS moderno (Container Queries, CSS Variables, sem dependência de jQuery)

**Filosofia:** Código enxuto > código bonito. Cada linha precisa pagar o seu próprio peso em milissegundos.

---

## 🏛️ CONTEXTO DO PROJETO (LER ANTES DE CODAR)

**Operação Clonais Work — 6 portais ativos:**
| Portal | Nicho | Persona |
|---|---|---|
| comocomprar | Compras / produtos | Consumidor pesquisador |
| vagasebeneficios | Empregos + benefícios sociais | Trabalhador / beneficiário |
| cursosenac | Cursos gratuitos | Estudante / quer qualificar |
| guiadoscursos | Educação / cursos pagos | Quem quer mudar de carreira |
| leaodabarra | Esporte (sem apostas) | Torcedor |
| ondecompraragora | Ofertas / cupons | Caçador de promoção |

**Monetização atual:** afiliado (Amazon + outros). **AdSense:** NÃO está ativo — meta é gerar VOLUME para aprovar. O tema precisa estar PRONTO pra AdSense, mas NÃO carregar nada de Ads até a flag `wp_option('clonais_adsense_active')` ser true.

**Tráfego-alvo:** 80%+ vem do Google Discover (mobile). Otimização desktop é secundária.

---

## 1. ⚡ ENGENHARIA DE PERFORMANCE (CORE WEB VITALS)

**Meta: 100/100 mobile no PageSpeed Insights. LCP < 1.8s. CLS = 0. INP < 200ms.**

### 1.1 CSS Crítico
- CSS "above-the-fold" inline no `<head>` (`<style>...</style>`), máximo **14kb gzip**
- CSS restante via `<link rel="preload" as="style" onload="this.rel='stylesheet'">`
- Usar **CSS Variables** para temas (claro/escuro/cores de categoria) — sem pré-processador no front

### 1.2 JavaScript
- **Proibido jQuery** no front-end. Vanilla JS apenas
- Todo JS com `defer` ou `type="module"`
- Scripts de terceiros (Analytics, Pixel, Tag Manager) só carregam **após primeira interação do usuário** (`scroll`, `click`, `touchstart`) — Lazy-Load de Scripts de Terceiros
- Inline máx 1kb. Resto bundle único minificado

### 1.3 Imagens (regra crítica para Discover)
- **Featured Image:** `fetchpriority="high"`, `decoding="async"`, sem lazy
- Demais imagens: `loading="lazy"`, `decoding="async"`
- **Width e height obrigatórios** em toda `<img>` (zera CLS)
- WebP + AVIF com fallback JPEG via `<picture>`
- **srcset agressivo com Art Direction:**
  - Landscape 16:9 (1200x675) — desktop e og:image (Discover)
  - Square 1:1 (1080x1080) — feed Discover quadrado
  - Portrait 4:5 (1080x1350) — mobile vertical
- Discover oficial: mínimo 1200px largura, resolução ≥ 300K, `og:image` setado, `max-image-preview:large` na meta robots

### 1.4 Fontes
- Stack do sistema como padrão: `system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`
- Se usar fonte custom: hospedar local (sem Google Fonts CDN), `font-display: swap`, preload da regular + bold apenas

### 1.5 Cache & Edge
- Headers de cache para assets: `max-age=31536000, immutable`
- Suporte nativo a `Cloudflare APO` / `Page Rules`
- Sem cookies em assets estáticos (subdomain `static.` recomendado)

---

## 2. 🎨 UI/UX INTELIGENTE — DESIGN "G1 STYLE"

### 2.1 Cores Dinâmicas por Categoria (Pulo do Gato)

Cada categoria tem cor hexadecimal definida via **Customizer + filter PHP**. No card do post (index, categoria, search), a cor aparece em:
- Border-left de 4px
- Badge de categoria (background)
- Hover state do título

**Implementação esperada:**
```php
// functions.php
function clonais_get_category_color( $cat_id ) {
    $colors = get_option( 'clonais_category_colors', [
        'noticias'  => '#c4170c',  // vermelho G1
        'economia'  => '#0b8043',
        'esporte'   => '#1a73e8',
        'cultura'   => '#9334e6',
        'tecnologia'=> '#0097a7',
    ] );
    $cat = get_category( $cat_id );
    return $colors[ $cat->slug ] ?? '#5f6368';
}
```

E no template, classe + CSS variable:
```php
<article class="post-card" style="--cat-color: <?= esc_attr( clonais_get_category_color( $cat_id ) ); ?>">
```

```css
.post-card { border-left: 4px solid var(--cat-color); }
.post-card .badge { background: var(--cat-color); }
```

### 2.2 Tipografia
- Body: **mínimo 18px** mobile, line-height **1.6**
- H1: 28-32px mobile / 40-48px desktop
- Largura de leitura máxima: **70 caracteres** (`max-width: 65ch`)
- Espaçamento entre parágrafos: 1em mínimo

### 2.3 Dark Mode
- Suporte nativo a `prefers-color-scheme: dark`
- Toggle manual persistido em `localStorage`
- CSS Variables para todas as cores (background, text, border, accent)

### 2.4 Modo Leitura
- Botão "modo leitura" no single.php que esconde sidebar, ads e widgets, mantendo só o artigo

---

## 3. 🧠 ELEMENTOS MODERNOS DE NAVEGAÇÃO

### 3.1 Reading Progress Bar
Linha de 3px no topo, cor da categoria, que se preenche conforme o scroll. Vanilla JS, ~20 linhas.

### 3.2 Smart Search Overlay
- Lupa no header abre overlay fullscreen
- Input com autocomplete via REST API (`/wp-json/wp/v2/search`)
- Debounce 250ms
- Mostra: título, categoria (com cor), data, thumbnail
- Atalho teclado: `/` ou `Ctrl+K`

### 3.3 Sticky Share Bar (Mobile)
- Barra fixa no rodapé mobile com: WhatsApp, Telegram, X (Twitter), Copiar Link
- Some no scroll para baixo, aparece no scroll para cima
- Telegram e WhatsApp **sempre primeiro** (são as principais fontes virais no BR)

### 3.4 Infinite Scroll Inteligente
- Ao final do artigo, carrega o **próximo post relacionado** automaticamente (não a home)
- Critério de "próximo": mesma categoria, ordenado por views + recência
- Atualiza URL (`history.pushState`) para o leitor poder compartilhar
- Limite: 3 posts em sequência (evita scroll infinito viciante)

### 3.5 Tabela de Conteúdo Automática (Sumário)
- Posts > 800 palavras: gera TOC automático a partir de `<h2>` e `<h3>`
- Smooth scroll no clique
- Gera Sitelinks no Google (links extras nos resultados)
- Schema `ItemList` opcional

---

## 4. 🛠️ SEO SEMÂNTICO + RANK MATH

### 4.1 Decisão Técnica: Rank Math Free
- **Use Rank Math gratuito.** Resolve sitemap, redirect 404, edição de meta — leve e via API
- **Desativar módulos não-usados:** Analytics integration, Content AI, SEO Analysis (deixar só Sitemap, Schema, Redirections, Breadcrumbs)
- O SaaS Maestro envia via REST API:
  - `rank_math_title`
  - `rank_math_description`
  - `rank_math_focus_keyword`

### 4.2 Schemas Obrigatórios (JSON-LD injetado pelo tema, não pelo Rank Math)

**Em todo single.php:**
- `NewsArticle` — datePublished, dateModified, headline, image (16:9), author, publisher
- `BreadcrumbList` — gerado a partir da hierarquia de categoria

**Condicional (parser detecta no conteúdo):**
- `HowTo` — quando há `<ol>` precedido de palavras como "passo a passo", "como fazer", "como inscrever"
- `FAQPage` — quando há bloco de FAQ no final (Sonnet sempre gera 3 perguntas)
- `VideoObject` — quando há `<iframe>` de YouTube/Vimeo
- `Product` + `Offer` — em posts de afiliado (com preço, disponibilidade)

### 4.3 Parser HowTo (PHP)
```php
// Detecta: H2/H3 com "como [verbo]" + <ol> ou <ul> seguinte
// Extrai cada <li> como step com name + text
// Se houver imagem dentro do step, adiciona image ao schema
function clonais_parse_howto( $content ) {
    // ... parser regex + DOMDocument
    // Retorna array pronto para wp_json_encode em schema HowTo
}
```

### 4.4 FAQ Visual + Schema
- Bloco de FAQ no final renderiza como **accordion** (`<details>` nativo, sem JS)
- Cada `<details><summary>pergunta</summary><div>resposta</div></details>` vira item no `FAQPage` schema
- Estilizar com cor da categoria no `summary`

### 4.5 HowTo Visual
- Não basta o JSON-LD oculto. O `<ol>` deve renderizar com:
  - Número grande (40px+) circular com cor da categoria
  - Espaçamento generoso
  - Ícone opcional por step
- Mobile-first

### 4.6 Breadcrumbs
- Usar `rank_math_the_breadcrumbs()` no header.php (logo abaixo do header)
- Estilizar: texto 13px, cinza médio, separador `›`, último item em negrito

---

## 5. 🏰 ENGENHARIA DE REDES DE ALTA FREQUÊNCIA

(O que separa portal de nicho de portal de gigante)

### 5.1 Speculation Rules API (Pré-renderização)
Adicionar no `<head>`:
```html
<script type="speculationrules">
{
  "prerender": [{
    "where": { "and": [{ "href_matches": "/*" }, { "not": { "href_matches": "/wp-admin/*" } }] },
    "eagerness": "moderate"
  }]
}
</script>
```
Resultado: hover/touch num link já carrega a próxima página em background. Discover ama LCP zero.

### 5.2 Lazy-Load de Anúncios
- Slot AdSense só faz request quando está a **300px** do viewport (IntersectionObserver)
- Slot tem `min-height` reservado para não causar CLS
- Flag global: `if (!window.clonaisAdsActive) return;` — desliga tudo quando AdSense não está aprovado

### 5.3 PWA (Progressive Web App)
- `manifest.json` com ícones (192, 512, maskable), theme_color, display: `standalone`
- Service Worker mínimo (cache de shell + offline page)
- Resultado: usuário pode "Instalar" o site no celular → ícone na home → tráfego recorrente sem pagar ads

### 5.4 Internal Linking Automático (Topic Clusters)
- Tabela `wp_clonais_topic_clusters` com: `cluster_id, pillar_post_id, keyword, related_post_ids[]`
- Ao publicar, script varre o conteúdo e linka primeira ocorrência de cada `keyword` para o `pillar_post_id`
- Máximo 3 internal links automáticos por post (evita over-optimization)

### 5.5 Monitor de Core Web Vitals Interno
- Snippet JS coleta `web-vitals` (lib oficial Google, 1.5kb) e envia para `/wp-json/clonais/v1/cwv`
- Endpoint salva em tabela `wp_clonais_cwv_log` (post_id, lcp, cls, inp, device, timestamp)
- Dashboard simples no admin lista posts com LCP > 2.5s para ação manual

---

## 6. 🛡️ ESTRUTURA SEMÂNTICA HTML

```html
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <!-- meta crítica -->
</head>
<body>
  <header role="banner">
    <nav role="navigation">...</nav>
  </header>

  <main role="main">
    <article itemscope itemtype="https://schema.org/NewsArticle">
      <header><!-- title, meta, breadcrumbs --></header>
      <div class="article-content">...</div>
      <footer><!-- tags, share, faq --></footer>
    </article>
    <aside role="complementary"><!-- relacionados --></aside>
  </main>

  <footer role="contentinfo">...</footer>
</body>
</html>
```

Hierarquia: 1 H1 por página, H2/H3 sequenciais, sem pular níveis.

---

## 7. 📁 ESTRUTURA DE ARQUIVOS DO TEMA

```
clonais-discover/
├── style.css                    (header WP + import do bundle)
├── functions.php                (boot + load includes)
├── index.php                    (loop home estilo G1)
├── single.php                   (artigo + schemas + share + relacionados)
├── page.php                     (página estática)
├── archive.php                  (categoria/tag — usa cor da categoria no header)
├── search.php                   (resultados + smart search)
├── 404.php                      (custom + sugestões)
├── header.php
├── footer.php
├── searchform.php
├── comments.php                 (desabilitado por padrão — Discover não gosta)
├── manifest.json                (PWA)
├── service-worker.js            (PWA)
├── /assets
│   ├── /css
│   │   ├── critical.css         (inline no <head>)
│   │   └── main.css             (async)
│   ├── /js
│   │   ├── main.js              (vanilla, defer)
│   │   ├── search.js            (overlay)
│   │   ├── share.js             (sticky bar)
│   │   ├── reading-progress.js
│   │   ├── infinite-scroll.js
│   │   └── cwv-monitor.js
│   └── /img
│       └── icons/               (PWA icons)
├── /inc
│   ├── setup.php                (theme support, menus, image sizes)
│   ├── enqueue.php              (CSS/JS com critical inline)
│   ├── customizer.php           (cores de categoria)
│   ├── category-colors.php      (lógica das cores)
│   ├── schema-newsarticle.php
│   ├── schema-howto.php         (parser)
│   ├── schema-faq.php
│   ├── schema-video.php
│   ├── schema-breadcrumbs.php
│   ├── rank-math-integration.php
│   ├── topic-clusters.php       (internal linking automático)
│   ├── cwv-endpoint.php         (REST endpoint p/ logging)
│   ├── pwa.php                  (manifest + sw register)
│   ├── speculation-rules.php
│   └── ads-lazy-loader.php      (gated por flag)
├── /template-parts
│   ├── content-card.php         (card do index)
│   ├── content-single.php
│   ├── faq-block.php
│   ├── howto-block.php
│   ├── share-bar.php
│   ├── reading-progress.php
│   ├── related-posts.php
│   └── breadcrumbs.php
└── README.md
```

---

## 8. ✅ CHECKLIST DE ENTREGA (Definition of Done)

Cada item precisa estar verificável:

- [ ] **PageSpeed Mobile 100/100** em pelo menos 3 URLs distintas (home, single, categoria)
- [ ] **LCP < 1.8s** medido via Lighthouse e DevTools
- [ ] **CLS = 0** (imagens com width/height, sem injeção de DOM no above-the-fold)
- [ ] **INP < 200ms** em interações principais (menu, search, share)
- [ ] Cores por categoria funcionando em index, archive, single (border + badge + hover)
- [ ] Dark mode automático + toggle manual persistido
- [ ] Reading progress bar funcional em mobile
- [ ] Smart Search com autocomplete debounced
- [ ] Sticky share bar com WhatsApp + Telegram primeiro
- [ ] Infinite scroll de artigo relacionado (máx 3)
- [ ] TOC automático em posts > 800 palavras
- [ ] Schema NewsArticle + BreadcrumbList em todo single (validado no Rich Results Test)
- [ ] Schema HowTo detectado e injetado quando há `<ol>` de processo
- [ ] Schema FAQPage funcional com accordion `<details>`
- [ ] Schema VideoObject quando há embed
- [ ] Rank Math integrado: campos `title`, `description`, `focus_keyword` aceitos via REST API
- [ ] Speculation Rules API ativo
- [ ] Lazy-load de scripts de terceiros após 1ª interação
- [ ] Lazy-load de slots de ads (gated por flag, OFF por padrão)
- [ ] PWA instalável (manifest válido, ícones, service worker básico)
- [ ] Internal linking automático para Topic Clusters
- [ ] Endpoint CWV `/wp-json/clonais/v1/cwv` salvando métricas
- [ ] HTML semântico validado (W3C)
- [ ] **Zero jQuery** no front
- [ ] **Aspas simples** em atributos HTML (consistência com gerador de artigos)
- [ ] Compatível com geração automática do SaaS Maestro (formato de HTML do artigo)

---

## 9. 🚦 PLANO DE EXECUÇÃO MODULAR

**Não codifique tudo de uma vez.** Faça módulo por módulo, testando cada um:

| Módulo | O que entrega | Como testa |
|---|---|---|
| **M1 — Esqueleto** | functions.php + index.php + single.php + style.css funcionais | Tema ativa sem erro, lista posts, abre single |
| **M2 — Cores de Categoria** | Customizer + category-colors.php + CSS Variables | Mudar cor no admin reflete no card |
| **M3 — Performance Base** | Critical CSS inline + defer JS + image sizes | Lighthouse > 90 mobile |
| **M4 — Schemas** | NewsArticle + Breadcrumbs + parser HowTo + FAQ | Rich Results Test passa |
| **M5 — UX Components** | Reading bar + Search overlay + Share bar + TOC | Manual em mobile real |
| **M6 — Rank Math** | REST integration + breadcrumbs estilizados | Criar post via API com meta fields |
| **M7 — Speculation Rules + PWA** | Pre-rendering + manifest + SW | Chrome DevTools Application tab |
| **M8 — Topic Clusters + CWV Monitor** | Internal linking auto + endpoint logging | Ver tabela `wp_clonais_cwv_log` populando |
| **M9 — Ads Lazy (gated)** | Slot stub + IntersectionObserver | Toggle flag, slot só requesta ao chegar perto |
| **M10 — Auditoria final** | Lighthouse 100/100 + Rich Results + W3C | Checklist completo |

---

## 10. 🚫 REGRAS INVIOLÁVEIS

1. **ZERO jQuery** no front-end
2. **ZERO Google Fonts CDN** (sempre local)
3. **ZERO scripts de terceiros** carregados antes da 1ª interação
4. **ZERO imagens sem width/height**
5. **ZERO CSS bloqueante** acima de 14kb gzip
6. **ZERO emojis no código** (apenas em conteúdo se o artigo gerado tiver)
7. **ZERO comentários explicando o óbvio** — só comente o "porquê" não-trivial
8. **ZERO dependência de plugins pesados** (Elementor, Visual Composer proibidos)
9. **Mobile-first sempre** — desktop é layout secundário
10. **Aspas simples em atributos HTML** (consistência com o gerador de conteúdo)

---

## 11. 📦 DELIVERABLE FINAL

Ao concluir, entregar:
1. ZIP do tema pronto para upload em `wp-content/themes/clonais-discover/`
2. README.md com instruções de instalação e configuração inicial
3. Lista de plugins recomendados (Rank Math + qualquer dependência mínima)
4. Screenshots Lighthouse mobile (home + single + archive) — todos 100/100
5. Validação Rich Results para 3 schemas: NewsArticle, HowTo, FAQ
6. Manifest.json e Service Worker funcionais
7. Documentação dos hooks e filters customizados criados (para o SaaS Maestro consumir)

---

## 🏁 COMANDO INICIAL

> **Claude, comece pelo Módulo 1 (Esqueleto). Crie:**
> 1. `style.css` com header WP correto
> 2. `functions.php` com setup mínimo (theme support, menus, image sizes 16:9 / 1:1 / 4:5)
> 3. `index.php` com loop básico estilo card
> 4. `single.php` com estrutura semântica
> 5. `header.php` e `footer.php`
>
> **Depois pare e me mostre.** Vou testar a ativação do tema antes de seguir para o Módulo 2 (cores de categoria).
>
> **Não pule módulos. Não otimize antes de funcionar. Não adicione features fora do escopo.**

---

*Este prompt é a fonte única de verdade do projeto Tema Clonais Discover. Qualquer mudança de escopo precisa atualizar este arquivo antes de ir para código.*
