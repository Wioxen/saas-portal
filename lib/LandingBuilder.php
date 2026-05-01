<?php
/**
 * LandingBuilder v10 — Zero classes custom. 100% tema ComoComprar.
 *
 * Classes usadas:
 *  cc-bento__main, cc-card, cc-fade-in, is-visible
 *  cc-card__thumb, cc-card__category, cc-card__body, cc-card__title
 *  cc-card__excerpt, cc-card__meta
 *  cc-btn, cc-btn--primary, cc-btn--sm, cc-btn--full
 *  cc-affiliate-cta, cc-affiliate-cta__price
 *  cc-product-box__rating
 *  cc-read-also (callout "por que recomendamos")
 *  cc-content details/summary (specs + FAQ)
 *  cc-content blockquote (why)
 *  cc-updated-badge
 */
class LandingBuilder
{
    private string $siteName;
    private string $siteUrl;
    private array $whatsapp;

    public function __construct(string $siteName = 'Como Comprar', string $siteUrl = '', array $whatsapp = [])
    {
        $this->siteName = $siteName;
        $this->siteUrl  = $siteUrl;
        $this->whatsapp = $whatsapp; // ['number'=>, 'group_url'=>, 'cta_text'=>]
    }

    /** Remove newlines entre tags HTML pra evitar wpautop inserir <br> e <p> vazios. */
    private function clean(string $html): string
    {
        // Remove newlines entre > e <
        $html = preg_replace('/>\s+</', '><', $html);
        // Remove <p></p> vazios
        $html = preg_replace('/<p>\s*<\/p>/', '', $html);
        // Remove <br> soltos entre tags
        $html = preg_replace('/<br\s*\/?>\s*(?=<)/', '', $html);
        return $html;
    }

    public function buildHtml(array $data): string
    {
        $products = $data['products'] ?? [];
        $html = $this->buildCSS();
        $html .= "<div id=\"rv\" class=\"cc-content\">";
        $html .= $this->buildIntro($data['intro_paragraphs'] ?? []);
        if (!empty($data['decision_block'])) $html .= $this->buildDecisionBlock($data['decision_block']);
        // Captura de lead WhatsApp (logo após o resumo do topo)
        $html .= $this->buildWhatsAppCapture($data);
        if (count($products) > 1) $html .= $this->buildCompareCards($products);
        if (!empty($products)) {
            $html .= "<h2>Análise detalhada</h2>";
            foreach ($products as $i => $p) {
                $html .= $this->buildReviewCard($p, $i + 1, $i === 0);
            }
        }
        // VS Comparisons
        if (!empty($data['vs_comparisons'])) $html .= $this->buildVsComparisons($data['vs_comparisons']);
        // Erro comum
        if (!empty($data['common_mistake'])) $html .= $this->buildCommonMistake($data['common_mistake'], $data['focus_keyword'] ?? '');
        // Recomendação final
        if (!empty($data['final_recommendation'])) $html .= $this->buildFinalRecommendation($data['final_recommendation']);
        // Cross-sell (produtos do mesmo momento de vida)
        if (!empty($data['cross_sell']['items'])) $html .= $this->buildCrossSell($data['cross_sell']);
        // Guia de compra
        if (!empty($data['buying_guide'])) {
            $html .= "<div style=\"background:var(--cc-gray-50);border:1px solid var(--cc-gray-200);border-radius:var(--cc-radius);padding:1.5rem 1.75rem;margin:2rem 0\"><h2 style=\"margin-top:0\">Como escolher</h2>" . $data['buying_guide'] . "</div>";
        }
        // Captura WhatsApp repetida no final (quem chegou até aqui é lead quente)
        $html .= $this->buildWhatsAppCapture($data, true);
        if (!empty($products)) $html .= $this->buildStickyCTA($products[0]);
        $html .= "</div>";
        return $this->clean($html);
    }

    public function buildFaqHtml(array $faq): string
    {
        if (empty($faq)) return '';
        $html = "<div class=\"cc-content\"><h2>Perguntas frequentes</h2>";
        foreach ($faq as $q) {
            $p = htmlspecialchars($q['q'] ?? '');
            $r = htmlspecialchars($q['a'] ?? '');
            if ($p === '' || $r === '') continue;
            $html .= "<details><summary>{$p}</summary><p>{$r}</p></details>";
        }
        return $this->clean($html . "</div>");
    }

    public function buildSchemas(array $data): string
    {
        $tags = '';
        $tags .= $this->schemaTag($this->buildArticleSchema($data));
        if (!empty($data['products'])) $tags .= $this->schemaTag($this->buildItemListSchema($data['products'], $data['title'] ?? ''));
        if (!empty($data['faq'])) $tags .= $this->schemaTag($this->buildFaqSchema($data['faq']));
        return "\n<!-- wp:html -->\n" . $tags . "\n<!-- /wp:html -->";
    }

    private function buildArticleSchema(array $data): array
    {
        $nowIso = date('c');
        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => $data['schema_type'] ?? 'Article',
            'headline'      => mb_substr($data['title'] ?? '', 0, 110),
            'description'   => $data['meta_description'] ?? $data['excerpt'] ?? '',
            'datePublished' => $nowIso,
            'dateModified'  => $nowIso,
            'author'        => [
                '@type' => 'Organization',
                'name'  => $this->siteName,
                'url'   => $this->siteUrl,
            ],
            'publisher'     => [
                '@type' => 'Organization',
                'name'  => $this->siteName,
                'url'   => $this->siteUrl,
            ],
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => $this->siteUrl,
            ],
        ];
        if (!empty($data['focus_keyword'])) $schema['keywords'] = $data['focus_keyword'];
        return $schema;
    }

    /* ══════════════════ CSS (mínimo — só o que o tema não cobre) ══════════════════ */

    public function buildCSS(): string
    {
        return <<<'CSS'
<style>
/* Overrides mínimos para review dentro de #rv */
#rv .cc-bento__main{grid-row:auto}
#rv .cc-bento__side{margin-bottom:.75rem}
#rv .cc-card{scroll-margin-top:24px;margin-bottom:1.75rem;border:2px solid var(--cc-gray-200)}
#rv .cc-card:target{border-color:var(--cc-blue-light) !important;box-shadow:0 0 0 4px rgba(37,99,235,.12) !important}
#rv .cc-card.rv-best{border-color:var(--cc-green) !important;box-shadow:0 4px 24px rgba(22,163,74,.1) !important}
#rv .cc-card .cc-card__thumb img{width:100%;height:100%;object-fit:cover;padding:0;background:var(--cc-gray-50)}
#rv .cc-bento__main .cc-card__title{font-size:1.8rem}
#rv .cc-card .cc-card__excerpt{-webkit-line-clamp:unset;overflow:visible;display:block}
#rv p,#rv .cc-card__excerpt,#rv li{font-size:18px}
.rv-microcopy{font-size:14px;color:var(--cc-gray-600);line-height:1.5;margin:0 0 6px;padding:0}
#rv .pros-cons{display:grid;grid-template-columns:1fr 1fr;gap:.875rem;margin:1rem 0}
#rv .pros-box{border-radius:var(--cc-radius);padding:1rem 1.125rem}
#rv .pros-box--pro{background:rgba(22,163,74,.06);border:1px solid rgba(22,163,74,.2)}
#rv .pros-box--con{background:rgba(220,38,38,.04);border:1px solid rgba(220,38,38,.18)}
#rv .pros-box h4{font-family:var(--cc-font-heading);font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;margin:0 0 10px}
#rv .pros-box--pro h4{color:var(--cc-green)}
#rv .pros-box--con h4{color:var(--cc-red)}
#rv .pros-box ul{list-style:none;padding:0;margin:0}
#rv .pros-box li{padding:4px 0;font-size:15px;color:var(--cc-gray-700);line-height:1.5}
#rv .pros-box--pro li::before{content:"✓ ";color:var(--cc-green);font-weight:800}
#rv .pros-box--con li::before{content:"✗ ";color:var(--cc-red);font-weight:800}
/* Sticky mobile */
#rv-sticky{display:none;position:fixed;bottom:0;left:0;right:0;background:var(--cc-white);border-top:3px solid var(--cc-green);padding:10px 16px;z-index:9999;box-shadow:0 -4px 24px rgba(0,0,0,.12)}
#rv-sticky>div{display:flex;align-items:center;gap:10px;max-width:600px;margin:0 auto}
/* Comparativo rápido: força grid 3 colunas (override do scroll-strip do tema) */
#rv .cc-scroll-strip{display:grid !important;grid-template-columns:repeat(3,1fr) !important;gap:1rem;overflow:visible !important}
#rv .cc-scroll-strip__item{flex:unset !important;width:auto !important;min-width:0 !important;scroll-snap-align:unset !important}
@media(max-width:900px){#rv .cc-scroll-strip{grid-template-columns:repeat(2,1fr) !important}}
@media(max-width:768px){
  .cc-single__container{padding:0 .7rem !important}
  #rv .pros-cons{grid-template-columns:1fr}
  #rv-sticky{display:block}
  body{padding-bottom:70px}
  #rv .cc-scroll-strip{grid-template-columns:1fr !important}
  #rv section div[style*="grid-template-columns:repeat(3,1fr)"]{grid-template-columns:1fr !important}
}
#rv .cc-cross-sell-card:hover{border-color:var(--cc-blue-light,#60a5fa) !important}
/* Contraste WCAG AA — verde texto e âmbar escurecidos (fundo claro) */
#rv{--rv-green:#15803d;--rv-amber:#b45309}
#rv .cc-updated-badge{color:#1f2937 !important}
#rv .cc-card__meta span{color:#374151}
/* Força contraste em botões primários (tema usa amarelo/laranja claro que falha AA) */
#rv .cc-btn.cc-btn--primary,#rv-sticky .cc-btn.cc-btn--primary{background:#b45309 !important;background-image:none !important;border-color:#b45309 !important;color:#fff !important;text-shadow:none !important}
#rv .cc-btn.cc-btn--primary:hover,#rv-sticky .cc-btn.cc-btn--primary:hover{background:#92400e !important;border-color:#92400e !important;color:#fff !important}
</style>
CSS;
    }

    /* ══════════════════ SVGs do tema ══════════════════ */

    private function svgCal(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.75 2a.75.75 0 01.75.75V4h7V2.75a.75.75 0 011.5 0V4h.25A2.75 2.75 0 0118 6.75v8.5A2.75 2.75 0 0115.25 18H4.75A2.75 2.75 0 012 15.25v-8.5A2.75 2.75 0 014.75 4H5V2.75A.75.75 0 015.75 2zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75z" clip-rule="evenodd"/></svg>';
    }

    private function svgClock(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-13a.75.75 0 00-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 000-1.5h-3.25V5z" clip-rule="evenodd"/></svg>';
    }

    /* ══════════════════ HTML ══════════════════ */

    private function buildIntro(array $pars): string
    {
        $h = '';
        foreach ($pars as $p) {
            $t = htmlspecialchars($p);
            if ($t !== '' && $t !== '&nbsp;') $h .= "<p>{$t}</p>";
        }
        return $h;
    }

    private function buildMeta(): string
    {
        $dt = date('c');
        $df = $this->dataFormatada();
        return "<div class=\"cc-card__meta\"><span>{$this->svgCal()}<time datetime=\"{$dt}\">{$df}</time></span><span class=\"cc-article__reading-time\">{$this->svgClock()} Avaliação independente</span></div>";
    }

    /** Comparativo rápido — layout cc-section + cc-scroll-strip (cards horizontais deslizantes) */
    private function buildCompareCards(array $products): string
    {
        $html  = '<section class="cc-section cc-section--compact">';
        $html .= '<div class="cc-container">';
        $html .= '<div class="cc-section__header"><h2 class="cc-section__title">Comparativo rápido</h2></div>';
        $html .= '<div class="cc-scroll-strip">';

        foreach ($products as $i => $p) {
            $nm = htmlspecialchars($p['name'] ?? '');
            $bg = htmlspecialchars($p['badge'] ?? '');
            $lk = htmlspecialchars($p['affiliate_url'] ?? '#');
            $im = htmlspecialchars($p['image'] ?? '');
            $id = 'rv-p' . ($i + 1);
            $ld = $i === 0 ? 'eager' : 'lazy';
            $bc = $bg ? $this->badgeColor($bg) : 'blue';
            $rt = number_format((float)($p['rating'] ?? 0), 1);
            $st = $this->renderStars((float)($p['rating'] ?? 0));

            $html .= '<article class="cc-scroll-strip__item cc-card cc-fade-in is-visible">';
            $html .= "<a href=\"#{$id}\" class=\"cc-card__thumb\" aria-hidden=\"true\" tabindex=\"-1\">";
            if ($im) $html .= "<img width=\"600\" height=\"338\" src=\"{$im}\" alt=\"{$nm}\" loading=\"{$ld}\" decoding=\"async\">";
            $html .= '</a>';
            if ($bg) $html .= "<a href=\"#{$id}\" class=\"cc-card__category\" data-color=\"{$bc}\">{$bg}</a>";
            $html .= '<div class="cc-card__body">';
            $html .= "<h3 class=\"cc-card__title\"><a href=\"#{$id}\">{$nm}</a></h3>";
            $html .= "<div class=\"cc-card__meta\"><span style=\"display:flex;align-items:center;gap:6px\"><strong style=\"font-size:1.1rem;color:#15803d\">{$rt}</strong><span style=\"font-size:.8rem;color:#4b5563\">/10</span><span style=\"color:#b45309;letter-spacing:1px\">{$st}</span></span></div>";
            $html .= "<a href=\"{$lk}\" class=\"cc-btn cc-btn--primary cc-btn--sm cc-btn--full\" rel=\"nofollow sponsored noopener\" target=\"_blank\" style=\"margin-top:8px\">💰 Ver menor preço</a>";
            $html .= '</div></article>';
        }

        $html .= '</div></div></section>';
        return $html;
    }

    private function buildReviewCard(array $p, int $pos, bool $best = false): string
    {
        $nm = htmlspecialchars($p['name'] ?? '');
        $bg = htmlspecialchars($p['badge'] ?? '');
        $im = htmlspecialchars($p['image'] ?? '');
        $rt = number_format((float)($p['rating'] ?? 0), 1);
        $st = $this->renderStars((float)($p['rating'] ?? 0));
        $pr = htmlspecialchars($p['price_display'] ?? '');
        $lk = htmlspecialchars($p['affiliate_url'] ?? '#');
        $fw = htmlspecialchars($p['for_whom'] ?? '');
        $rv = $p['review_text'] ?? '';
        $wy = htmlspecialchars($p['why_recommend'] ?? '');
        $ct = htmlspecialchars($p['cta_text'] ?? "Ver menor preço do {$nm} →");
        $id = 'rv-p' . $pos;
        $bestCls = $best ? ' rv-best' : '';

        $html = "<article class=\"cc-bento__main cc-card cc-fade-in is-visible{$bestCls}\" id=\"{$id}\">";

        // Thumb — primeira é LCP candidate: eager + fetchpriority high
        $imgLoad = $pos === 1 ? 'eager' : 'lazy';
        $imgPrio = $pos === 1 ? ' fetchpriority="high"' : '';
        $html .= "<a href=\"{$lk}\" class=\"cc-card__thumb\" aria-hidden=\"true\" tabindex=\"-1\" rel=\"nofollow sponsored noopener\" target=\"_blank\">";
        if ($im) $html .= "<img width=\"1200\" height=\"630\" src=\"{$im}\" alt=\"{$nm}\" loading=\"{$imgLoad}\" decoding=\"async\"{$imgPrio}>";
        $html .= "</a>";

        // Badge
        if ($bg) $html .= "<span class=\"cc-card__category\" data-color=\"" . $this->badgeColor($bg) . "\">{$bg}</span>";

        // Vídeo oficial (se fornecido pelo Claude/Scraper)
        if (!empty($p['video_url'])) {
            $embed = $this->buildVideoEmbed((string)$p['video_url'], $nm);
            if ($embed !== '') $html .= $embed;
        }

        // Body
        $html .= "<div class=\"cc-card__body\">";

        // Título
        $html .= "<h3 class=\"cc-card__title\">{$pos}. {$nm}</h3>";

        // Score destaque (grande, logo abaixo do título)
        $html .= "<div style=\"display:flex;align-items:center;gap:8px;margin:4px 0 12px\"><span style=\"font-size:2rem;font-weight:900;color:#15803d;line-height:1\">{$rt}</span><span style=\"font-size:.85rem;color:#4b5563\">/10</span><span style=\"font-size:1rem;color:#b45309\">{$st}</span></div>";

        // Tudo em details fechados — compacto

        // Pra quem é
        if ($fw !== '' && $fw !== '&nbsp;') {
            $html .= "<details><summary>Pra quem é</summary><p>{$fw}</p></details>";
        }

        // Review / análise (com faixa de preço dentro)
        if ($rv || ($pr !== '' && $pr !== '&nbsp;')) {
            $reviewHtml = '';
            if ($pr !== '' && $pr !== '&nbsp;') {
                $reviewHtml .= "<p style=\"color:var(--cc-gray-500);font-size:.875rem\">Faixa de preço: <strong style=\"color:var(--cc-gray-700)\">{$pr}</strong> <small>(pode variar)</small></p>";
            }
            if ($rv) {
                $rv = str_replace(['<br>', '<br/>', '<br />'], "\n", $rv);
                foreach (preg_split('/\n+/', $rv) as $rp) {
                    $rp = trim(strip_tags($rp, '<strong><em><a><b><i>'));
                    if ($rp !== '' && $rp !== '&nbsp;') $reviewHtml .= "<p>{$rp}</p>";
                }
            }
            if ($reviewHtml !== '') $html .= "<details><summary>Análise completa</summary>{$reviewHtml}</details>";
        }

        // Specs
        if (!empty($p['specs'])) {
            $html .= "<details><summary>Especificações técnicas</summary><table><tbody>";
            foreach ($p['specs'] as $k => $v) $html .= "<tr><td><strong>" . htmlspecialchars($k) . "</strong></td><td>" . htmlspecialchars($v) . "</td></tr>";
            $html .= "</tbody></table></details>";
        }

        // Prós
        if (!empty($p['pros'])) {
            $html .= "<details><summary>Prós</summary><ul>";
            foreach ($p['pros'] as $pro) $html .= "<li>" . htmlspecialchars($pro) . "</li>";
            $html .= "</ul></details>";
        }

        // Contras
        if (!empty($p['cons'])) {
            $html .= "<details><summary>Contras</summary><ul>";
            foreach ($p['cons'] as $con) $html .= "<li>" . htmlspecialchars($con) . "</li>";
            $html .= "</ul></details>";
        }

        // Por que recomendamos
        if ($wy !== '' && $wy !== '&nbsp;') {
            $html .= "<details><summary>Por que recomendamos</summary><p>{$wy}</p></details>";
        }

        // Vale a pena / decision line
        $dl = htmlspecialchars($p['decision_line'] ?? '');
        if ($dl !== '' && $dl !== '&nbsp;') {
            $html .= "<details><summary>Vale a pena?</summary><p><strong>{$dl}</strong></p></details>";
        }

        // Microcopy + CTA por posição
        $microcopy = $this->getMicrocopy($pos);
        $ctaLabel = $this->getCtaLabel($pos);
        $html .= "<p class=\"rv-microcopy\">{$microcopy}</p>";
        $html .= "<a href=\"{$lk}\" class=\"cc-btn cc-btn--primary cc-btn--full\" rel=\"nofollow sponsored noopener\" target=\"_blank\">{$ctaLabel}</a>";
        $html .= "<div class=\"cc-card__meta\" style=\"margin-top:8px\"><span>Compra protegida</span><span>Frete grátis</span><span>30 dias devolução</span><span>Mais vendido</span></div>";
        $html .= $this->buildMeta();

        $html .= "</div></article>";
        return $html;
    }

    /** Bloco de decisão rápida — logo após intro */
    public function buildDecisionBlock(array $db): string
    {
        $title = htmlspecialchars($db['title'] ?? 'Escolha rápida: qual comprar?');
        $html = "<div style=\"background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:2px solid #0ea5e9;border-radius:12px;padding:20px 24px;margin:28px 0\">";
        $html .= "<h2 style=\"margin:0 0 12px 0;font-size:1.2em;color:#0c4a6e;border:none;padding:0\">{$title}</h2>";
        foreach (($db['picks'] ?? []) as $pick) {
            $label = htmlspecialchars($pick['label'] ?? '');
            $name = htmlspecialchars($pick['product_name'] ?? '');
            $reason = htmlspecialchars($pick['reason'] ?? '');
            $cta = htmlspecialchars($pick['cta_text'] ?? 'Ver preço →');
            $url = htmlspecialchars($pick['affiliate_url'] ?? '#');
            $html .= "<p style=\"margin:6px 0;font-size:15px\">{$label} <strong>{$name}</strong> — {$reason} <a href=\"{$url}\" class=\"cc-btn cc-btn--primary cc-btn--sm\" style=\"margin-left:8px\" rel=\"nofollow sponsored noopener\" target=\"_blank\">{$cta}</a></p>";
        }
        $html .= "</div>";
        return $html;
    }

    /** Comparações X vs Y */
    public function buildVsComparisons(array $comparisons): string
    {
        $html = "<h2>Comparação direta</h2>";
        foreach ($comparisons as $vs) {
            $title = htmlspecialchars($vs['title'] ?? '');
            $text = $vs['text'] ?? '';
            $winner = htmlspecialchars($vs['winner'] ?? '');
            $html .= "<div class=\"cc-read-also\" style=\"margin:1.5rem 0\"><h3 style=\"margin-top:0\">{$title}</h3>";
            // Text pode ter \n
            $text = str_replace(['<br>', '<br/>', '<br />'], "\n", $text);
            foreach (preg_split('/\n+/', $text) as $tp) {
                $tp = trim($tp);
                if ($tp !== '') $html .= "<p>{$tp}</p>";
            }
            if ($winner !== '') $html .= "<p><strong>Veredicto: {$winner}</strong></p>";
            $html .= "</div>";
        }
        return $html;
    }

    /** Erro comum */
    private function buildCommonMistake(string $text, string $keyword): string
    {
        $cat = $keyword ?: 'este tipo de produto';
        $html = "<h2>O erro que muita gente comete ao comprar {$cat}</h2>";
        $text = str_replace(['<br>', '<br/>', '<br />'], "\n", $text);
        foreach (preg_split('/\n+/', $text) as $tp) {
            $tp = trim(strip_tags($tp, '<strong><em><a><b><i>'));
            if ($tp !== '' && $tp !== '&nbsp;') $html .= "<p>{$tp}</p>";
        }
        return $html;
    }

    /** Recomendação final — orçamento / equilíbrio / premium */
    private function buildFinalRecommendation(array $rec): string
    {
        $title = htmlspecialchars($rec['title'] ?? 'Qual vale mais a pena pra você?');
        $html = "<div class=\"cc-affiliate-cta\" style=\"text-align:left\"><h2 style=\"margin-top:0;border:none;padding:0\">{$title}</h2>";
        $items = [
            ['key' => 'budget',   'label' => 'Quer economizar'],
            ['key' => 'balanced', 'label' => 'Quer equilíbrio'],
            ['key' => 'premium',  'label' => 'Quer o melhor'],
        ];
        foreach ($items as $item) {
            $data = $rec[$item['key']] ?? null;
            if (!$data) continue;
            $prod = htmlspecialchars($data['product'] ?? '');
            $reason = htmlspecialchars($data['reason'] ?? '');
            $html .= "<p style=\"padding:.5rem 0;border-bottom:1px solid var(--cc-gray-200);margin:0\">";
            $html .= "<strong>{$item['label']}:</strong> <strong style=\"color:#15803d\">{$prod}</strong> — {$reason}</p>";
        }
        $html .= "</div>";
        return $html;
    }

    private function buildStickyCTA(array $p): string
    {
        $nm = htmlspecialchars($p['name'] ?? '');
        $pr = htmlspecialchars($p['price_display'] ?? '');
        $lk = htmlspecialchars($p['affiliate_url'] ?? '#');
        return "<div id=\"rv-sticky\"><div style=\"display:flex;align-items:center;gap:10px;max-width:600px;margin:0 auto\"><div style=\"flex:1;min-width:0\"><div style=\"font-weight:800;font-size:13px;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis\">{$nm}</div><div style=\"font-size:15px;color:#15803d;font-weight:900\">{$pr}</div></div><a href=\"{$lk}\" class=\"cc-btn cc-btn--primary cc-btn--sm\" rel=\"nofollow sponsored noopener\" target=\"_blank\">💰 Ver preço →</a></div></div>";
    }

    /* ══════════════════ Helpers ══════════════════ */

    /**
     * Captura de lead WhatsApp — bloco CTA linkando pro grupo ou chat direto.
     * Ativado apenas se config tiver number ou group_url.
     */
    private function buildWhatsAppCapture(array $data, bool $compact = false): string
    {
        $groupUrl = $this->whatsapp['group_url'] ?? '';
        $number   = $this->whatsapp['number'] ?? '';
        if ($groupUrl === '' && $number === '') return '';

        $cta = htmlspecialchars($this->whatsapp['cta_text'] ?? 'Receba ofertas no WhatsApp');
        $keyword = htmlspecialchars($data['focus_keyword'] ?? 'ofertas');

        if ($groupUrl !== '') {
            $href = htmlspecialchars($groupUrl, ENT_QUOTES);
        } else {
            $msg = rawurlencode("Oi! Quero receber ofertas sobre {$keyword}");
            $href = "https://wa.me/{$number}?text={$msg}";
        }

        if ($compact) {
            return '<div style="background:#dcfce7;border:2px solid #16a34a;border-radius:12px;padding:16px 20px;margin:24px 0;text-align:center;min-height:140px">'
                . "<p style=\"margin:0 0 8px;font-size:15px;color:#14532d\"><strong>{$cta}</strong></p>"
                . '<p style="margin:0 0 12px;font-size:13px;color:#166534">Alertas de preço, cupons exclusivos e promoções relâmpago do mesmo nicho.</p>'
                . "<a href=\"{$href}\" target=\"_blank\" rel=\"nofollow noopener\" class=\"cc-btn cc-btn--primary\" style=\"background:#15803d !important;border-color:#15803d !important;color:#fff !important\">📲 Entrar no grupo grátis</a>"
                . '</div>';
        }

        return '<div style="background:linear-gradient(135deg,#ecfdf5,#dcfce7);border:2px solid #16a34a;border-radius:12px;padding:20px 24px;margin:24px 0;display:flex;gap:16px;align-items:center;flex-wrap:wrap;min-height:96px">'
            . '<div style="flex:1;min-width:240px">'
            . "<h3 style=\"margin:0 0 4px;color:#14532d;font-size:1.1rem\">{$cta}</h3>"
            . '<p style="margin:0;font-size:14px;color:#166534">Ofertas relâmpago, cupons exclusivos e alertas de preço direto no seu celular. Sem spam.</p>'
            . '</div>'
            . "<a href=\"{$href}\" target=\"_blank\" rel=\"nofollow noopener\" class=\"cc-btn cc-btn--primary\" style=\"background:#15803d !important;border-color:#15803d !important;color:#fff !important;white-space:nowrap\">📲 Entrar no grupo grátis</a>"
            . '</div>';
    }

    /**
     * Cross-sell — produtos complementares do mesmo momento de vida.
     * Cada item aponta para uma busca interna (pode ser usada como link pra categoria do site).
     */
    private function buildCrossSell(array $cs): string
    {
        $items = $cs['items'] ?? [];
        if (empty($items)) return '';
        $title = htmlspecialchars($cs['title'] ?? 'Quem comprou também montou');
        $intro = htmlspecialchars($cs['intro'] ?? '');
        $base  = rtrim($this->siteUrl, '/');

        $html = "<section style=\"margin:32px 0\"><h2>{$title}</h2>";
        if ($intro !== '') $html .= "<p style=\"color:var(--cc-gray-600);margin:0 0 16px\">{$intro}</p>";
        $html .= '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">';
        foreach ($items as $it) {
            $cat = htmlspecialchars($it['category'] ?? '');
            $reason = htmlspecialchars($it['reason'] ?? '');
            $kw = $it['keyword_search'] ?? $it['category'] ?? '';
            $href = $base !== '' ? $base . '/?s=' . urlencode($kw) : '#';
            if ($cat === '') continue;
            $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" style="display:block;background:var(--cc-gray-50);border:1px solid var(--cc-gray-200);border-radius:10px;padding:14px 16px;text-decoration:none;color:inherit;transition:border-color .2s;min-height:80px" class="cc-cross-sell-card">'
                . "<strong style=\"display:block;color:var(--cc-gray-900);margin-bottom:4px;font-size:15px\">{$cat}</strong>"
                . "<span style=\"font-size:13px;color:var(--cc-gray-600)\">{$reason}</span>"
                . '</a>';
        }
        $html .= '</div></section>';
        return $html;
    }

    /**
     * Embeda vídeo oficial (YouTube, Vimeo ou MP4 direto).
     * Não baixa nem re-hospeda — usa o player do provedor via iframe/<video>.
     * Retorna '' se a URL não for reconhecida ou for insegura.
     */
    private function buildVideoEmbed(string $url, string $label = ''): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) return '';
        $title = htmlspecialchars($label ?: 'Vídeo oficial', ENT_QUOTES);

        // YouTube (watch, youtu.be, embed, shorts)
        if (preg_match('#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})#i', $url, $m)) {
            $vid = $m[1];
            $src = "https://www.youtube.com/embed/{$vid}";
            return '<div class="rv-video" style="position:relative;aspect-ratio:16/9;margin:12px 0;border-radius:10px;overflow:hidden;background:#000;min-height:200px">'
                . "<iframe width=\"1280\" height=\"720\" src=\"{$src}\" title=\"{$title}\" frameborder=\"0\" allow=\"accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture\" allowfullscreen loading=\"lazy\" style=\"position:absolute;inset:0;width:100%;height:100%\"></iframe>"
                . '</div>';
        }

        // Vimeo
        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $url, $m)) {
            $vid = $m[1];
            $src = "https://player.vimeo.com/video/{$vid}";
            return '<div class="rv-video" style="position:relative;aspect-ratio:16/9;margin:12px 0;border-radius:10px;overflow:hidden;background:#000;min-height:200px">'
                . "<iframe width=\"1280\" height=\"720\" src=\"{$src}\" title=\"{$title}\" frameborder=\"0\" allow=\"autoplay;fullscreen;picture-in-picture\" allowfullscreen loading=\"lazy\" style=\"position:absolute;inset:0;width:100%;height:100%\"></iframe>"
                . '</div>';
        }

        // MP4/WebM direto (player nativo, sem re-hospedar)
        if (preg_match('#\.(mp4|webm|mov)(\?|$)#i', $url, $m)) {
            $mime = strtolower($m[1]) === 'webm' ? 'video/webm' : 'video/mp4';
            $u = htmlspecialchars($url, ENT_QUOTES);
            return '<div class="rv-video" style="margin:12px 0;border-radius:10px;overflow:hidden;background:#000">'
                . "<video controls preload=\"metadata\" playsinline style=\"width:100%;display:block\" aria-label=\"{$title}\">"
                . "<source src=\"{$u}\" type=\"{$mime}\">"
                . '</video></div>';
        }

        return '';
    }

    private function badgeColor(string $badge): string
    {
        $b = mb_strtolower($badge);
        if (str_contains($b, 'custo') || str_contains($b, 'barato') || str_contains($b, 'econôm')) return 'orange';
        if (str_contains($b, 'premium') || str_contains($b, 'top') || str_contains($b, 'luxo')) return 'purple';
        if (str_contains($b, 'esport') || str_contains($b, 'corrida') || str_contains($b, 'treino')) return 'teal';
        if (str_contains($b, 'gamer') || str_contains($b, 'jogo')) return 'red';
        if (str_contains($b, 'geral') || str_contains($b, 'melhor')) return 'green';
        return 'blue';
    }

    /** Microcopy emocional por posição — topo=curiosidade, meio=prova, fundo=urgência */
    private function getMicrocopy(int $pos): string
    {
        $copies = [
            1 => 'O preço desse modelo muda várias vezes por semana — já vimos diferença de mais de R$200',
            2 => 'Algumas cores e versões costumam entrar em promoção antes das outras',
            3 => 'Muita gente paga mais caro sem perceber — o preço muda dependendo do vendedor',
            4 => 'Esse modelo entra frequentemente no ranking dos mais vendidos da Amazon',
            5 => 'Esse é um dos mais vendidos — algumas vezes fica indisponível por dias',
            6 => 'O valor desse modelo não é fixo — pode estar mais barato hoje do que ontem',
            7 => 'Se você chegou até aqui, esse já é um dos melhores da lista — vale ver o preço real',
            8 => 'Quem espera demais geralmente paga mais caro nesse modelo',
            9 => 'Compra com garantia, devolução e suporte direto da Amazon',
            10 => 'Esse modelo aparece com desconto relâmpago em horários específicos',
        ];
        return $copies[$pos] ?? $copies[($pos % 10) ?: 10];
    }

    /** CTA label por posição — varia pra não repetir */
    private function getCtaLabel(int $pos): string
    {
        $labels = [
            1 => '🔥 Ver melhor preço disponível agora',
            2 => '💰 Ver se está mais barato hoje',
            3 => '👉 Conferir preço atualizado',
            4 => '⚡ Ver oferta antes que acabe',
            5 => '🔎 Descobrir quanto está custando',
            6 => '📉 Checar preço atualizado na Amazon',
            7 => '🎯 Ver oferta recomendada',
            8 => '🛒 Ver onde comprar pelo menor preço',
            9 => '🔒 Comprar com garantia na Amazon',
            10 => '🏆 Ver o melhor preço desse modelo',
        ];
        return $labels[$pos] ?? $labels[($pos % 10) ?: 10];
    }

    /** Cor de fundo do botão por loja — identidade visual real */
    private function storeColor(string $store): string
    {
        $s = mb_strtolower(trim($store));
        if (str_contains($s, 'amazon')) return '#FF9900';
        if (str_contains($s, 'mercado') || str_contains($s, 'ml')) return '#FFE600;color:#333 !important';
        if (str_contains($s, 'shopee')) return '#EE4D2D';
        if (str_contains($s, 'magalu') || str_contains($s, 'magazine')) return '#0086FF';
        if (str_contains($s, 'kabum')) return '#FF6500';
        if (str_contains($s, 'casas bahia') || str_contains($s, 'cb')) return '#0066CC';
        if (str_contains($s, 'aliexpress') || str_contains($s, 'ali')) return '#E62E04';
        if (str_contains($s, 'zoom')) return '#6C3BF5';
        if (str_contains($s, 'americanas')) return '#E60014';
        if (str_contains($s, 'pichau')) return '#00B4D8';
        return 'var(--cc-green,#16A34A)';
    }

    private function renderStars(float $r): string
    {
        $r5 = $r > 5 ? $r / 2 : $r;
        $f = (int)floor($r5); $h = ($r5 - $f) >= 0.3 ? 1 : 0; $e = 5 - $f - $h;
        return str_repeat('★', $f) . ($h ? '½' : '') . str_repeat('☆', max(0, $e));
    }

    private function mesAno(): string
    {
        $m = [1=>'jan',2=>'fev',3=>'mar',4=>'abr',5=>'mai',6=>'jun',7=>'jul',8=>'ago',9=>'set',10=>'out',11=>'nov',12=>'dez'];
        return $m[(int)date('n')] . '/' . date('Y');
    }

    private function dataFormatada(): string
    {
        $m = [1=>'janeiro',2=>'fevereiro',3=>'março',4=>'abril',5=>'maio',6=>'junho',7=>'julho',8=>'agosto',9=>'setembro',10=>'outubro',11=>'novembro',12=>'dezembro'];
        return date('d') . ' de ' . $m[(int)date('n')] . ' de ' . date('Y');
    }

    /* ══════════════════ Schemas ══════════════════ */

    private function buildItemListSchema(array $products, string $title): array
    {
        $items = [];
        foreach ($products as $i => $p) {
            $price = (float)($p['price'] ?? 0);
            $r = (float)($p['rating'] ?? 0);
            $r5 = $r > 5 ? round($r / 2, 1) : round($r, 1);
            if ($r5 < 1) $r5 = 4.0;

            // reviewCount: usa review_count do produto se existir, senão estima
            $reviewCount = (string)($p['review_count'] ?? '1000');

            // reviewBody resumido pro schema (texto longo fica no HTML)
            $reviewBody = $p['why_recommend'] ?? $p['description'] ?? '';
            if (mb_strlen($reviewBody) > 300) $reviewBody = mb_substr($reviewBody, 0, 297) . '...';

            $product = [
                '@type' => 'Product',
                'name' => $p['name'] ?? '',
                'image' => $p['image'] ?? '',
                'description' => $p['description'] ?? '',
                'brand' => ['@type' => 'Brand', 'name' => $p['brand'] ?? ''],
            ];

            // SKU (ASIN, EAN, ou modelo) — evita warning do Google
            if (!empty($p['sku'])) {
                $product['sku'] = $p['sku'];
            } elseif (!empty($p['asin'])) {
                $product['sku'] = $p['asin'];
            }

            // AggregateRating — reviewCount coerente
            $product['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => (string)$r5,
                'reviewCount' => $reviewCount,
            ];

            // Review — author como Person (E-E-A-T) + resumo
            $product['review'] = [
                '@type' => 'Review',
                'datePublished' => date('Y-m-d'),
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => (string)$r5,
                    'bestRating' => '5',
                ],
                'author' => [
                    '@type' => 'Person',
                    'name' => 'Equipe Editorial',
                ],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $this->siteName,
                ],
                'reviewBody' => $reviewBody,
            ];

            // Offers
            $product['offers'] = $this->buildOffer($p, $price);

            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'item' => $product];
        }
        return [
            '@context' => 'https://schema.org/',
            '@type' => 'ItemList',
            'name' => $title,
            'description' => "Análise dos melhores produtos pela equipe {$this->siteName}.",
            'itemListElement' => $items,
        ];
    }

    private function buildOffer(array $p, float $pr): array
    {
        $o = [
            '@type' => 'Offer',
            'url' => $p['affiliate_url'] ?? $this->siteUrl,
            'priceCurrency' => $p['currency'] ?? 'BRL',
            'price' => $pr > 0 ? number_format($pr, 2, '.', '') : '0.00',
            'priceValidUntil' => date('Y-12-31'),
            'itemCondition' => 'https://schema.org/NewCondition',
            'availability' => 'https://schema.org/InStock',
        ];
        if (!empty($p['store'])) $o['seller'] = ['@type' => 'Organization', 'name' => $p['store']];
        $o['shippingDetails'] = [
            '@type' => 'OfferShippingDetails',
            'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => 0, 'currency' => 'BRL'],
            'deliveryTime' => [
                '@type' => 'ShippingDeliveryTime',
                'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 1, 'unitCode' => 'DAY'],
                'transitTime' => ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 5, 'unitCode' => 'DAY'],
            ],
            'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'BR'],
        ];
        $o['hasMerchantReturnPolicy'] = [
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => 'BR',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => 30,
            'returnMethod' => 'https://schema.org/ReturnByMail',
            'returnFees' => 'https://schema.org/FreeReturn',
        ];
        return $o;
    }

    private function buildFaqSchema(array $faq): array
    {
        return ['@context'=>'https://schema.org','@type'=>'FAQPage',
            'mainEntity'=>array_map(fn($q)=>['@type'=>'Question','name'=>$q['q']??'',
                'acceptedAnswer'=>['@type'=>'Answer','text'=>$q['a']??'']],$faq)];
    }

    /** Retorna o <script type="application/ld+json"> de FAQPage — público para reuso. */
    public function buildFaqSchemaTag(array $faq): string
    {
        $limpo = array_values(array_filter($faq, fn($q) => !empty($q['q']) && !empty($q['a'])));
        if (empty($limpo)) return '';
        return "\n<!-- wp:html -->\n" . $this->schemaTag($this->buildFaqSchema($limpo)) . "\n<!-- /wp:html -->";
    }

    /**
     * Schema NewsArticle JSON-LD — otimizado para Google Discover/Top Stories.
     * Inclui múltiplas aspect ratios de imagem (16:9, 4:3, 1:1) — exigência oficial.
     * @param array $data {headline, url, image_url, excerpt, author_name, author_url, keyword}
     */
    public function buildNewsArticleSchemaTag(array $data): string
    {
        $headline = $data['headline'] ?? '';
        if ($headline === '') return '';

        $nowIso = date('c'); // ISO 8601 com timezone (ex: 2026-04-15T10:00:00-03:00)
        $publisher = [
            '@type' => 'Organization',
            'name'  => $this->siteName,
            'url'   => $this->siteUrl,
        ];
        if ($this->siteUrl !== '') {
            $publisher['logo'] = [
                '@type' => 'ImageObject',
                'url'   => rtrim($this->siteUrl, '/') . '/wp-content/uploads/logo.png',
            ];
        }

        $images = [];
        if (!empty($data['image_url'])) {
            $img = $data['image_url'];
            // 3 aspect ratios exigidas pela documentação Article structured data
            $images = [$img, $img, $img]; // mesma URL servindo as 3 — Google aceita (embora ideal seria crops distintos)
        }

        $author = [
            '@type' => 'Person',
            'name'  => $data['author_name'] ?? ($this->siteName . ' — Redação'),
        ];
        if (!empty($data['author_url'])) $author['url'] = $data['author_url'];

        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => 'NewsArticle',
            'headline'      => mb_substr($headline, 0, 110), // Google trunca acima de 110 chars
            'description'   => $data['excerpt'] ?? '',
            'datePublished' => $nowIso,
            'dateModified'  => $nowIso,
            'author'        => $author,
            'publisher'     => $publisher,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => $data['url'] ?? '',
            ],
        ];
        if (!empty($images)) $schema['image'] = $images;
        if (!empty($data['keyword'])) $schema['keywords'] = $data['keyword'];

        return "\n<!-- wp:html -->\n" . $this->schemaTag($schema) . "\n<!-- /wp:html -->";
    }

    /**
     * Byline visível no HTML — diretriz oficial de "byline dates".
     * Renderiza próximo ao topo do artigo com autor + data formatada em PT-BR.
     */
    public function buildByline(string $keyword = ''): string
    {
        $meses = [1=>'janeiro',2=>'fevereiro',3=>'março',4=>'abril',5=>'maio',6=>'junho',7=>'julho',8=>'agosto',9=>'setembro',10=>'outubro',11=>'novembro',12=>'dezembro'];
        $dias  = [0=>'domingo',1=>'segunda-feira',2=>'terça-feira',3=>'quarta-feira',4=>'quinta-feira',5=>'sexta-feira',6=>'sábado'];
        $dia   = (int)date('j');
        $mes   = $meses[(int)date('n')];
        $ano   = date('Y');
        $dsem  = $dias[(int)date('w')];
        $iso   = date('c');
        $autor = htmlspecialchars($this->siteName . ' — Redação');
        $dataTexto = "{$dsem}, {$dia} de {$mes} de {$ano}";
        return '<div class="cc-byline" style="display:flex;align-items:center;gap:14px;margin:0 0 1.5rem;padding:10px 0;border-bottom:1px solid var(--cc-gray-200,#e5e7eb);font-size:13px;color:var(--cc-gray-600,#4b5563)">'
            . "<span><strong>Por {$autor}</strong></span>"
            . "<span>·</span>"
            . "<time datetime=\"{$iso}\">Publicado em {$dataTexto}</time>"
            . '</div>';
    }

    private function schemaTag(array $d): string
    {
        return "<script type=\"application/ld+json\">".json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."</script>";
    }
}
