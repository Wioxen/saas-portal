# LP — Máscara Absolut Repair Molecular L'Oréal

LP de afiliado Amazon, segunda do projeto ComoComprar. Replicada do template `lp/comocomprar/potes-vidro-hermeticos/`.

## Estrutura

```
mascara-absolut-repair-molecular/
├── index.html              # LP completa (gtag conversion ID Produto2)
├── img/                    # 9 JPG + 9 WebP (47% economia via api.gogleads.com.br)
│   ├── produto-1.jpg/.webp # Hero 800x700 (preload)
│   └── produto-2..9.*      # Galeria 600px (lazy)
├── CRIATIVOS-ADS.md        # Headlines, keywords, negativadas, configuração
└── README.md
```

## Pré-visualizar localmente

```
http://localhost/apiclaudephp/lp/comocomprar/mascara-absolut-repair-molecular/
```

## Pendências antes de subir Ads

### 1. Trocar placeholder do link de afiliado
3 ocorrências de `https://amzn.to/4mZeKhF` no `index.html` precisam virar o link curto Amazon real desse produto. Trocar com:
```
sed -i 's|amzn.to/4mZeKhF|amzn.to/SEU_LINK_REAL|g' index.html
```

### 2. Páginas obrigatórias no domínio
`/politica-de-privacidade/`, `/contato/`, `/sobre/` — Google Ads reprova LP de afiliado sem essas três.

### 3. Confirmar preço atual
Preço hard-coded R$ 244,99. Amazon flutua diariamente. Conferir em https://www.amazon.com.br/dp/B0D8JY97Z5 antes de subir o anúncio. Se mudou, atualizar em 3 lugares no HTML (price-current, schema offers.price, headline final).

### 4. Validar
- Schema: https://validator.schema.org/
- Rich Results: https://search.google.com/test/rich-results
- PageSpeed: https://pagespeed.web.dev/

## Conversão Google Ads (já configurada)

```
Conta: AW-16696952717
Conversion ID: sZLcCKL9l6QcEI2P3Zk-
Nome: Produto2 (clique de saída)
Valor: R$ 1,00
```

A LP já dispara `gtag_report_conversion()` no `onclick` dos 2 botões CTA. Após deploy, validar via DevTools → Network → clique no CTA → request pra `googleads.g.doubleclick.net/pagead/conversion/`.

## Conteúdo extraído da Amazon (28/abr/2026)

- **ASIN:** B0D8JY97Z5
- **Título:** Máscara Absolut Repair Molecular — L'Oréal Professionnel
- **Preço captado:** R$ 244,99
- **Avaliação:** 4,6★ em 4.091 análises
- **6 reviews verificados BR** capturados literalmente (Cláudia A, Maria Izabel, Cintia Santos, Cliente Amazon, Talita Duarte, Michele Paulino)
- **9 imagens hi-res** baixadas e otimizadas (JPG quality 82) + convertidas em WebP via `api.gogleads.com.br/Convert/image/webp`

## Métricas de tamanho da LP

| Item | Tamanho |
|---|---|
| HTML | ~26 KB |
| 9 imagens JPG (fallback) | 499 KB |
| 9 imagens WebP (servido) | **263 KB** |
| Hero WebP (LCP) | **27 KB** |
| LCP payload (HTML + hero WebP) | **~53 KB** |

LCP estimado em 4G mobile: **< 1s**.

## Comparativo com a primeira LP (potes vidro)

| | Potes Vidro | Absolut Repair |
|---|---|---|
| Preço | R$ 184,90 | R$ 244,99 |
| Comissão estimada (Amazon BR) | 4,5% = R$ 8,32 | **10% = R$ 24,50** |
| Avaliações | 19 | **4.091** |
| Categoria | Cuidados Casa | **Beleza (10%)** |
| Hero WebP | 62 KB | 27 KB |

> **3× mais comissão por venda + 200× mais avaliações.** Mesmo template, blastpipe diferente.

## Replicar para o próximo produto

Tempo estimado por LP usando esse template: **30-45 minutos** (já temos o conversor WebP, regex de scrape calibrado, e CSS validado WCAG).

1. Criar pasta `/lp/comocomprar/[slug]/`
2. Scrape: `Invoke-WebRequest` com User-Agent → salva em `_scrape_amazon.html`
3. Extrair com regex (script PowerShell já calibrado nas sessões anteriores)
4. Baixar imagens com `System.Drawing` (script PowerShell)
5. Converter pra WebP: `php scripts/converter_lp_webp.php lp/comocomprar/[slug]/img`
6. Duplicar `index.html` deste diretório, trocar:
   - `<title>`, `<meta description>`, `<link canonical>`, `og:*`
   - 3 blocos JSON-LD (Product/FAQPage/BreadcrumbList)
   - Conversion ID novo (criar no Ads, mesma conta `AW-16696952717`)
   - Hero: H1, subtitle, price, image dimensions
   - 4 USPs, especificações, reviews, FAQ
7. Duplicar `CRIATIVOS-ADS.md`, adaptar headlines/keywords/negativadas
8. Validar, subir, criar campanha
