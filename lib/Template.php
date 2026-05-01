<?php
/**
 * Renderiza HTML SEO otimizado:
 *  - Schema.org Article + ItemList + FAQPage
 *  - Open Graph + Twitter Card
 *  - max-image-preview:large (Google Discover)
 *  - Tags pra Google News (publisher, datePublished, author)
 *  - HTML semântico, mobile-first, CSS inline (sem render-blocking)
 */
class Template
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function render(array $artigo): string
    {
        $cfg       = $this->cfg;
        $titulo    = htmlspecialchars($artigo['titulo']);
        $descricao = htmlspecialchars($artigo['descricao']);
        $slug      = $artigo['slug'];
        $url       = $cfg['pages_url'] . '/' . $slug . '.html';
        $autor     = htmlspecialchars($cfg['autor']);
        $site      = htmlspecialchars($cfg['site_name']);
        $data      = date('c'); // ISO 8601
        $dataLeg   = date('d/m/Y');
        $imgCapa   = htmlspecialchars($artigo['produtos'][0]['imageUrl'] ?? $artigo['produtos'][0]['thumbnail'] ?? '');

        // Schema Article
        $articleSchema = json_encode([
            '@context'         => 'https://schema.org',
            '@type'            => 'NewsArticle',
            'headline'         => $artigo['titulo'],
            'description'      => $artigo['descricao'],
            'image'            => [$imgCapa],
            'datePublished'    => $data,
            'dateModified'     => $data,
            'author'           => [
                '@type' => 'Organization',
                'name'  => $cfg['autor'],
            ],
            'publisher'        => [
                '@type' => 'Organization',
                'name'  => $cfg['site_name'],
                'logo'  => [
                    '@type' => 'ImageObject',
                    'url'   => $cfg['site_url'] . '/assets/logo.png',
                ],
            ],
            'mainEntityOfPage' => $url,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Schema ItemList
        $itemList = ['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => []];
        foreach ($artigo['produtos'] as $i => $p) {
            $itemList['itemListElement'][] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $p['title'] ?? '',
                'url'      => $p['link'] ?? '',
            ];
        }
        $itemListSchema = json_encode($itemList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $faqSchema = $artigo['faq_schema'];

        $css = $this->css();

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$titulo}</title>
<meta name="description" content="{$descricao}">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
<meta name="author" content="{$autor}">
<link rel="canonical" href="{$url}">

<!-- Open Graph -->
<meta property="og:type" content="article">
<meta property="og:title" content="{$titulo}">
<meta property="og:description" content="{$descricao}">
<meta property="og:image" content="{$imgCapa}">
<meta property="og:url" content="{$url}">
<meta property="og:site_name" content="{$site}">
<meta property="og:locale" content="pt_BR">
<meta property="article:published_time" content="{$data}">
<meta property="article:author" content="{$autor}">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$titulo}">
<meta name="twitter:description" content="{$descricao}">
<meta name="twitter:image" content="{$imgCapa}">

<!-- Schemas -->
<script type="application/ld+json">{$articleSchema}</script>
<script type="application/ld+json">{$itemListSchema}</script>
<script type="application/ld+json">{$faqSchema}</script>

<style>{$css}</style>
</head>
<body>
<header class="topo">
  <div class="container">
    <a href="{$cfg['site_url']}" class="logo">{$site}</a>
  </div>
</header>

<main class="container">
  <article itemscope itemtype="https://schema.org/NewsArticle">
    <h1 itemprop="headline">{$titulo}</h1>
    <p class="meta">
      Por <span itemprop="author">{$autor}</span> ·
      <time datetime="{$data}" itemprop="datePublished">{$dataLeg}</time> ·
      Leitura de 5 min
    </p>

    <div itemprop="articleBody">
      {$artigo['introducao']}

      <h2>Os melhores escolhidos pela nossa equipe</h2>
      {$artigo['lista']}

      {$artigo['dicas']}

      {$artigo['conclusao']}

      {$artigo['faq']}
    </div>

    <p class="aviso"><small>⚠️ Os preços podem variar. Esta página pode conter links de afiliados — ao comprar por eles, você apoia nosso trabalho sem pagar nada a mais.</small></p>
  </article>
</main>

<footer class="rodape">
  <div class="container">
    <p>© {$dataLeg} {$site} — Todos os direitos reservados.</p>
  </div>
</footer>
</body>
</html>
HTML;
    }

    private function css(): string
    {
        return <<<CSS
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#222;line-height:1.6;background:#fff}
.container{max-width:780px;margin:0 auto;padding:0 16px}
.topo{background:#1877f2;padding:14px 0;margin-bottom:24px}
.topo .logo{color:#fff;font-weight:bold;font-size:18px;text-decoration:none}
h1{font-size:30px;line-height:1.2;margin:16px 0;color:#111}
h2{font-size:23px;margin:32px 0 12px;color:#111;border-bottom:2px solid #1877f2;padding-bottom:6px}
h3{font-size:19px;margin:0 0 10px;color:#1877f2}
p{margin:0 0 14px}
.meta{color:#777;font-size:14px;margin-bottom:24px}
.produto{background:#fafafa;border:1px solid #eee;border-radius:8px;padding:16px;margin:18px 0}
.produto-grid{display:grid;grid-template-columns:140px 1fr;gap:16px;align-items:start}
.produto img{width:140px;height:140px;object-fit:contain;background:#fff;border-radius:4px}
.produto-desc{font-size:15px;color:#444}
.produto-preco{font-size:15px;color:#222;margin:8px 0}
.produto-preco small{color:#888}
.cta{display:inline-block;background:#2ecc71;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:bold;font-size:14px;margin-top:8px}
.cta:hover{background:#27ae60}
.dicas{margin:0 0 20px 22px}
.dicas li{margin-bottom:10px}
.faq details{background:#f7f7f7;padding:12px 16px;border-radius:6px;margin-bottom:8px;cursor:pointer}
.faq summary{font-weight:bold;color:#1877f2}
.faq p{margin-top:8px;color:#444}
.aviso{margin-top:32px;padding:14px;background:#fff8e1;border-left:4px solid #ffc107;border-radius:4px;color:#666;font-size:13px}
.rodape{margin-top:48px;padding:24px 0;background:#222;color:#aaa;font-size:14px;text-align:center}
@media (max-width:600px){
  h1{font-size:24px}
  .produto-grid{grid-template-columns:1fr}
  .produto img{width:100%;height:200px}
}
CSS;
    }
}
