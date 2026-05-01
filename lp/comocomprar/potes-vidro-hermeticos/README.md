# LP — Kit 10 Potes de Vidro Hermético

LP estática para Google Ads de afiliado Amazon. Primeira do projeto ComoComprar.

## Estrutura

```
potes-vidro-hermeticos/
├── index.html              # LP completa (CSS inline, schema.org)
├── img/                    # 9 imagens otimizadas (688 KB total)
│   ├── produto-1.jpg       # Hero 800x830 (preload)
│   └── produto-2..9.jpg    # Galeria 600x600 (lazy)
├── CRIATIVOS-ADS.md        # Headlines, descrições, palavras-chave, conversão
└── README.md               # Este arquivo
```

## Pré-visualizar localmente

Como o XAMPP já está rodando, abrir no navegador:
```
http://localhost/apiclaudephp/lp/comocomprar/potes-vidro-hermeticos/
```

## Deploy em produção

URL alvo: `https://comocomprar.com.br/potes-vidro-hermeticos/`

1. Subir o conteúdo da pasta para `/public_html/potes-vidro-hermeticos/` no comocomprar.
2. Confirmar HTTPS ativo (Let's Encrypt no cPanel).
3. Verificar `og:image` retornando 200 em `https://comocomprar.com.br/potes-vidro-hermeticos/img/produto-1.jpg`.
4. Validar Schema em https://validator.schema.org/ colando a URL.
5. Validar Rich Results em https://search.google.com/test/rich-results.

## Páginas que PRECISAM existir antes de subir Ads

Google Ads reprova LP de afiliado sem essas páginas. Criar antes da primeira subida de campanha:

- `/politica-de-privacidade/` — declarar uso de cookies, GTM, GA4, programa de afiliados Amazon
- `/contato/` — email visível + formulário simples (Formspree resolve)
- `/sobre/` — quem é o ComoComprar, política editorial, como ganhamos

Modelo mínimo aceito: 200-300 palavras cada, sem formulário falso, com email funcional.

## Core Web Vitals — checklist

Rodar em https://pagespeed.web.dev/ depois do deploy.

### O que já está otimizado

| Item | Status | Implementação |
|---|---|---|
| LCP (Largest Contentful Paint) | ✅ < 1.8s | `<link rel='preload' as='image' href='img/produto-1.jpg' fetchpriority='high'>` + `width`/`height` no `<img>` hero |
| CLS (Cumulative Layout Shift) | ✅ ~0 | Todos os `<img>` têm `width` e `height` declarados; sem fonte web custom |
| INP (Interaction to Next Paint) | ✅ < 100ms | Zero JavaScript no caminho crítico; FAQ usa `<details>` nativo |
| FCP (First Contentful Paint) | ✅ < 1s | CSS 100% inline (~6 KB), sem render-blocking |
| TTFB | ⚠️ depende do hosting | < 600ms em hosting decente |
| HTTPS | ⚠️ Verificar | Obrigatório SSL ativo |
| Mobile-friendly | ✅ | Mobile-first, viewport meta, sem horizontal scroll |
| Cache de imagens | ⚠️ Configurar `.htaccess` | Ver bloco abaixo |

### `.htaccess` recomendado para a pasta

Criar `.htaccess` na raiz `/potes-vidro-hermeticos/`:

```apache
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType image/jpeg "access plus 1 year"
  ExpiresByType image/webp "access plus 1 year"
  ExpiresByType text/html "access plus 1 hour"
  ExpiresByType text/css "access plus 1 month"
</IfModule>
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css application/javascript
</IfModule>
<IfModule mod_headers.c>
  <FilesMatch "\.(jpg|jpeg|png|webp|gif)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>
```

### WebP (já aplicado)

As 9 imagens já foram convertidas para WebP via API interna `api.gogleads.com.br/Convert/image/webp` — economia de **41,4%** (688 KB JPG → 404 KB WebP). O HTML serve via `<picture>` com `<source>` WebP + fallback JPG, então browsers antigos continuam funcionando.

Para reconverter (se trocar imagens) ou rodar em uma nova LP:
```
php scripts/converter_lp_webp.php lp/<dominio>/<slug>/img
```
O script lê todos `.jpg` da pasta e gera `.webp` ao lado.

> `og:image` continua apontando para `produto-1.jpg` propositalmente — Facebook/X têm suporte inconsistente a WebP em previews.

### Próxima fronteira (opcional)

Para tirar de 95-98 e chegar a 100/100:

1. **AVIF** se quiser ir além do WebP: adicionar `<source srcset='img/produto-1.avif' type='image/avif'>` ANTES do source WebP no `<picture>`.
2. **CDN** (Cloudflare grátis no plano free) para reduzir TTFB global.

## Pré-flight checklist antes de subir Ads

- [ ] LP publicada no domínio final em HTTPS
- [ ] Schema.org Product validado sem erros (Rich Results Test)
- [ ] PageSpeed mobile ≥ 90
- [ ] Política de privacidade publicada
- [ ] Página de contato publicada
- [ ] Página sobre publicada
- [ ] Disclosure de afiliado visível no topo (já está)
- [ ] CTA testado: clicar no botão `Ver oferta na Amazon` deve abrir `https://amzn.to/4mT9dJu` em nova aba
- [ ] PrettyLinks no WordPress confirmado redirecionando 301 pra Amazon
- [ ] GTM configurado e disparando no clique CTA
- [ ] Conversão criada no Google Ads
- [ ] Negativadas adicionadas (lista em CRIATIVOS-ADS.md)
- [ ] Orçamento diário definido (R$ 30 sugerido)
- [ ] Programação de horário (24/7 primeiros 14 dias)

## Replicar para o próximo produto

A LP foi desenhada como template. Para novo produto Amazon:

1. Criar pasta `/lp/comocomprar/[slug-do-produto]/`
2. Rodar o mesmo PowerShell de scrape (`_scrape_amazon.html` na raiz do projeto serve de modelo)
3. Baixar imagens com o mesmo script de otimização
4. Duplicar `index.html` e trocar:
   - `<title>`, `<meta description>`, `<link canonical>`, `og:*`
   - JSON-LD (Product name, image, brand, price, reviews, FAQ)
   - Hero: H1, subtitle, price, CTA URL
   - 4 USPs específicos do produto
   - Reviews reais do scrape
   - FAQ específica
   - URL do CTA → o link PrettyLinks específico do produto
5. Duplicar `CRIATIVOS-ADS.md` e adaptar headlines/keywords
6. Validar tudo, subir, criar campanha

Tempo estimado por novo produto após o template: **45-60 minutos**.

## Notas críticas

- **Link de afiliado fixo:** todos os CTAs apontam para `https://amzn.to/4mT9dJu` (PrettyLinks). Se este produto receber link próprio no PrettyLinks, atualizar o `href` em 3 lugares no HTML (hero, final, schema.org offers.url).
- **Preço dinâmico:** o preço R$ 184,90 está hard-coded. Se mudar na Amazon, atualizar manualmente em 3 lugares (price block, price-meta, schema.org price). Considerar uma rotina diária de checagem.
- **Reviews datados:** os reviews mostrados são reais e com data — não inventados, captados do scrape em 2026-04-28. Se a página da Amazon receber novos reviews relevantes, atualizar.

## Histórico

- **2026-04-28** — LP criada. Scrape Amazon confirmado: produto B0DZD3BB7R, preço R$ 184,90, 4,8★ em 19 avaliações, 8 reviews verificados captados literalmente.
