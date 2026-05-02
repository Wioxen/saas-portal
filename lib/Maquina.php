<?php
/**
 * Orquestrador da máquina de conteúdo.
 *
 * Pipeline:
 *  1. Serper /search → top URLs (UMA VEZ)
 *  2. Scraper → até N sucessos (UMA VEZ)
 *  3. Loop pelos formatos (SEO/Discover/News/SERP):
 *     a) Claude retorna dados estruturados (intro, products, guide, faq)
 *     b) LandingBuilder monta HTML + schemas em PHP
 *     c) Upload og:image, resolve cat/tag, cria draft
 *  → Mesmo scrape pra todos os formatos = economia de custo
 */

require_once __DIR__ . '/LandingBuilder.php';
require_once __DIR__ . '/PrettyLinks.php';

class Maquina
{
    private Serper $serper;
    private Scraper $scraper;
    private Claude $claude;
    private Wordpress $wp;
    private array $cfg;
    public array $log = [];

    public function __construct(Serper $s, Scraper $sc, Claude $c, Wordpress $w, array $cfg)
    {
        $this->serper  = $s;
        $this->scraper = $sc;
        $this->claude  = $c;
        $this->wp      = $w;
        $this->cfg     = $cfg;
    }

    public function rodarComBlocosPorFormato(string $keyword, array $formatos, array $blocosPorFormato = [], array $urls = []): array
    {
        return $this->rodarInterno($keyword, $formatos, $blocosPorFormato, $urls);
    }

    public function rodar(string $keyword, array $formatos, array $blocosCustom = [], array $urls = []): array
    {
        $blocosPorFormato = [];
        foreach ($formatos as $fmt) {
            $blocosPorFormato[$fmt] = $blocosCustom;
        }
        return $this->rodarInterno($keyword, $formatos, $blocosPorFormato, $urls);
    }

    private function rodarInterno(string $keyword, array $formatos, array $blocosPorFormato, array $urls = []): array
    {
        $fontes = [];

        // ── ETAPA 1a: URLs diretas ──
        if (!empty($urls)) {
            $this->log('🔗 ' . count($urls) . ' URL(s) para scrapear diretamente');
            foreach ($urls as $url) {
                if (!preg_match('#^https?://#', $url)) continue;
                $this->log("📥 Scrapeando: $url");
                try {
                    $dados = $this->scraper->fetch($url);
                    if (count($dados['content']['paragraphs']) < 2) {
                        $this->log('  ⚠️ pouco conteúdo, pulando');
                        continue;
                    }
                    $fontes[] = $dados;
                    $this->log('  ✓ ok (' . count($dados['content']['paragraphs']) . ' parágrafos)');
                } catch (Throwable $e) {
                    $this->log('  ✗ ' . $e->getMessage());
                }
            }
        }

        // ── ETAPA 1b: Serper (se tiver keyword) ──
        if ($keyword !== '') {
            $this->log("🔍 Buscando '$keyword' no Google via Serper...");
            $serp = $this->serper->search($keyword, $this->cfg['scrape_max_try']);
            $organicos = $serp['organic'] ?? [];
            $this->log('  ✓ ' . count($organicos) . ' URLs candidatas');
        } else {
            $organicos = [];
        }

        // ── ETAPA 2: Scrape SERP ──
        $alvo = $this->cfg['scrape_top_n'];
        foreach ($organicos as $r) {
            if (count($fontes) >= $alvo) break;
            $url = $r['link'] ?? '';
            if (!$url) continue;
            $this->log("📥 Scrapeando: $url");
            try {
                $dados = $this->scraper->fetch($url);
                if (count($dados['content']['paragraphs']) < 3) {
                    $this->log('  ⚠️ pouco conteúdo, pulando');
                    continue;
                }
                $fontes[] = $dados;
                $this->log('  ✓ ok (' . count($dados['content']['paragraphs']) . ' parágrafos)');
            } catch (Throwable $e) {
                $this->log('  ✗ ' . $e->getMessage());
            }
        }
        if (empty($fontes)) {
            throw new RuntimeException('Nenhuma fonte obtida. Verifique URLs ou keyword.');
        }
        $this->log('✅ ' . count($fontes) . ' fontes coletadas');

        $heroUrl = null;
        foreach ($fontes as $f) {
            if (!empty($f['meta']['og_image'])) { $heroUrl = $f['meta']['og_image']; break; }
        }

        $mediaCache = [];
        $builder = new LandingBuilder($this->cfg['site_name'] ?? 'Como Comprar', $this->cfg['wp_url'] ?? '', ['number' => $this->cfg['whatsapp_number'] ?? '', 'group_url' => $this->cfg['whatsapp_group_url'] ?? '', 'cta_text' => $this->cfg['whatsapp_cta_text'] ?? '']);

        // ── ETAPA 3: Gerar por formato ──
        $resultados = [];
        foreach ($formatos as $fmt) {
            $fmtNome = Claude::$formatos[$fmt]['nome'] ?? strtoupper($fmt);
            $this->log('');
            $this->log("═══ FORMATO: $fmtNome ═══════════════════");

            try {
                $blocosDoFormato = $blocosPorFormato[$fmt] ?? [];
                $this->log("🤖 Gerando dados [$fmtNome] com Claude...");
                $artigo = $this->claude->gerarPost($keyword, $fontes, $fmt, $blocosDoFormato);

                $hasProducts = !empty($artigo['products']);
                $hasContentHtml = !empty($artigo['content_html']);

                // Pretty Links — roda SEMPRE que tiver products OU content_html
                $plInstance = null;
                if (!empty($this->cfg['pretty_links'])) {
                    try {
                        $plInstance = new PrettyLinks($this->cfg['wp_url'], $this->cfg['wp_user'], $this->cfg['wp_app_password']);
                    } catch (Throwable $e) { $this->log('  ✗ PrettyLinks init: ' . $e->getMessage()); }
                }

                if ($hasProducts) {
                    $this->log('  ✓ ' . count($artigo['products']) . ' produtos + dados estruturados');

                    // Upload imagens
                    $this->log("🖼️ Uploadando imagens dos produtos...");
                    foreach ($artigo['products'] as $idx => &$prod) {
                        $imgUrl = $prod['image'] ?? '';
                        if ($imgUrl === '' || !preg_match('#^https?://#', $imgUrl)) continue;
                        if (isset($mediaCache[$imgUrl])) {
                            $prod['image'] = $mediaCache[$imgUrl]['url'];
                            $prod['wp_media_id'] = $mediaCache[$imgUrl]['id'];
                            continue;
                        }
                        try {
                            $alt = $prod['name'] ?? "Produto " . ($idx + 1);
                            $mediaId = $this->wp->uploadImagemPorUrl($imgUrl, $alt);
                            if ($mediaId) {
                                $media = $this->wp->getMedia($mediaId);
                                $wpUrl = $media['source_url'] ?? $imgUrl;
                                $prod['image'] = $wpUrl;
                                $prod['wp_media_id'] = $mediaId;
                                $mediaCache[$imgUrl] = ['id' => $mediaId, 'url' => $wpUrl];
                                $this->log("  ✓ {$alt} → media #{$mediaId}");
                            }
                        } catch (Throwable $e) {
                            $this->log("  ✗ " . $e->getMessage());
                        }
                    }
                    unset($prod);

                    // Pretty Links pra products
                    if ($plInstance) {
                        $this->log('🔗 Criando Pretty Links...');
                        $prefix = $this->cfg['pretty_links_prefix'] ?? 'go';
                        foreach ($artigo['products'] as &$prod) {
                            $affUrl = $prod['affiliate_url'] ?? '';
                            if ($affUrl === '' || !preg_match('#^https?://#', $affUrl)) continue;
                            $slug = PrettyLinks::slugify($prod['name'] ?? 'produto', $prefix);
                            try {
                                $prettyUrl = $plInstance->criarOuBuscar($affUrl, $slug, $prod['name'] ?? '', true, '301');
                                if ($prettyUrl) { $prod['affiliate_url'] = $prettyUrl; $this->log("  ✓ {$slug}"); }
                            } catch (Throwable $e) { $this->log("  ✗ {$slug}: " . $e->getMessage()); }
                            if (!empty($prod['alt_stores'])) {
                                foreach ($prod['alt_stores'] as &$alt) {
                                    $altUrl = $alt['url'] ?? '';
                                    if ($altUrl === '' || !preg_match('#^https?://#', $altUrl)) continue;
                                    $altSlug = PrettyLinks::slugify(($prod['name'] ?? '') . ' ' . ($alt['store'] ?? ''), $prefix);
                                    try { $altPretty = $plInstance->criarOuBuscar($altUrl, $altSlug, '', true, '301'); if ($altPretty) $alt['url'] = $altPretty; } catch (Throwable $e) {}
                                }
                                unset($alt);
                            }
                        }
                        unset($prod);

                        // Decision block
                        if (!empty($artigo['decision_block']['picks'])) {
                            foreach ($artigo['decision_block']['picks'] as &$pick) {
                                $pickUrl = $pick['affiliate_url'] ?? '';
                                if ($pickUrl === '' || !preg_match('#^https?://#', $pickUrl)) continue;
                                $pickSlug = PrettyLinks::slugify($pick['product_name'] ?? '', $prefix);
                                try { $pp = $plInstance->criarOuBuscar($pickUrl, $pickSlug, $pick['product_name'] ?? ''); if ($pp) $pick['affiliate_url'] = $pp; } catch (Throwable $e) {}
                            }
                            unset($pick);
                        }
                    }

                    // Monta HTML via LandingBuilder
                    $contentFinal = $builder->buildHtml($artigo);
                    // Varre <a href> do HTML final e aplica PrettyLinks nos links comerciais restantes
                    if ($plInstance) {
                        $contentFinal = $this->aplicarPrettyNoHtml($contentFinal, $plInstance);
                    }
                } elseif ($hasContentHtml) {
                    $this->log('  ✓ ' . mb_strlen($artigo['content_html']) . ' chars de conteúdo HTML');

                    // Pretty Links para affiliate_url dentro de decision_block (antes de renderizar)
                    if ($plInstance && !empty($artigo['decision_block']['picks'])) {
                        $prefix = $this->cfg['pretty_links_prefix'] ?? 'go';
                        foreach ($artigo['decision_block']['picks'] as &$pick) {
                            $pickUrl = $pick['affiliate_url'] ?? '';
                            if ($pickUrl === '' || !preg_match('#^https?://#', $pickUrl)) continue;
                            $pickSlug = PrettyLinks::slugify($pick['product_name'] ?? 'pick', $prefix);
                            try { $pp = $plInstance->criarOuBuscar($pickUrl, $pickSlug, $pick['product_name'] ?? '', true, '301'); if ($pp) $pick['affiliate_url'] = $pp; } catch (Throwable $e) {}
                        }
                        unset($pick);
                    }

                    // Resumo (decision_block) no TOPO + content_html + Comparação direta (vs_comparisons) no final
                    $contentFinal = '';
                    if (!empty($artigo['decision_block']['picks'])) {
                        $contentFinal .= '<div class="cc-content">' . $builder->buildDecisionBlock($artigo['decision_block']) . '</div>';
                        $this->log('  ✓ Resumo rápido (decision_block) injetado');
                    }
                    $contentFinal .= $artigo['content_html'];
                    if (!empty($artigo['vs_comparisons'])) {
                        $contentFinal .= '<div class="cc-content">' . $builder->buildVsComparisons($artigo['vs_comparisons']) . '</div>';
                        $this->log('  ✓ Comparação direta (vs_comparisons) injetada');
                    }

                    // Reescreve <a href="..."> externos como Pretty Links
                    if ($plInstance) {
                        $contentFinal = $this->aplicarPrettyNoHtml($contentFinal, $plInstance);
                    }
                } else {
                    throw new RuntimeException('Claude não retornou products nem content_html');
                }

                // Wrap tabelas com scroll
                // Tema cc-content já estiliza tabelas

                // Featured image (reusa primeiro produto upado)
                $featuredId = null;
                foreach (($artigo['products'] ?? []) as $prod) {
                    if (!empty($prod['wp_media_id'])) { $featuredId = $prod['wp_media_id']; break; }
                }

                // CASCATA Pexels → DALL-E → og:image. Override do og:image cru que pegava
                // logo do site / banner sem relação. Slug SEO no nome do arquivo.
                $imagemMeta = null;
                if (!$featuredId && !empty($this->cfg['pexels_api_key'])) {
                    try {
                        require_once __DIR__ . '/DiscoverImagemFeatured.php';
                        $imgSvc = new DiscoverImagemFeatured($this->cfg);
                        $imagemMeta = $imgSvc->escolher([
                            'termo'             => $keyword,
                            'cluster_key'       => $artigo['cluster_key'] ?? '',
                            'briefing_titulo'   => $artigo['title'] ?? $keyword,
                            'og_image_fallback' => $heroUrl ?? '',
                        ]);
                        if (!empty($imagemMeta['url'])) {
                            $altImg  = $artigo['hero_alt'] ?? $keyword;
                            $slugImg = $imagemMeta['slug_sugerido'] ?? '';
                            $featuredId = $this->wp->uploadImagemPorUrl($imagemMeta['url'], $altImg, $slugImg);
                            if ($featuredId) {
                                $media = $this->wp->getMedia($featuredId);
                                $mediaCache[$imagemMeta['url']] = ['id' => $featuredId, 'url' => $media['source_url'] ?? $imagemMeta['url']];
                                $this->log('  ✓ Featured image via ' . $imagemMeta['fonte'] . ' (slug=' . $slugImg . ')');
                            }
                        }
                    } catch (Throwable $e) {
                        $this->log('  ✗ Featured cascade: ' . $e->getMessage());
                    }
                }

                // Fallback legado — og:image cru se cascata desligada/falhou
                if (!$featuredId && $heroUrl) {
                    if (isset($mediaCache[$heroUrl])) {
                        $featuredId = $mediaCache[$heroUrl]['id'];
                    } else {
                        try {
                            $featuredId = $this->wp->uploadImagemPorUrl($heroUrl, $artigo['hero_alt'] ?? $keyword);
                            if ($featuredId) {
                                $media = $this->wp->getMedia($featuredId);
                                $mediaCache[$heroUrl] = ['id' => $featuredId, 'url' => $media['source_url'] ?? $heroUrl];
                            }
                        } catch (Throwable $e) { /* silencia */ }
                    }
                }

                // Categorias / Tags
                $catIds = [];
                $tagIds = [];
                if (!empty($artigo['categories'])) {
                    $catIds = $this->wp->resolverCategorias($artigo['categories']);
                }
                if (!empty($artigo['tags'])) {
                    $tagIds = $this->wp->resolverTags($artigo['tags']);
                }

                // Posts relacionados — entre conteúdo e FAQ
                $searchTerm = $artigo['focus_keyword'] ?? $keyword;
                if ($searchTerm !== '') {
                    $this->log("🔗 Buscando posts relacionados...");
                    try {
                        $relacionados = $this->wp->buscarRelacionados($searchTerm, 6);
                        if (!empty($relacionados)) {
                            $this->log('  ✓ ' . count($relacionados) . ' posts encontrados');
                            $contentFinal .= $this->montarRelacionados($relacionados);
                        }
                    } catch (Throwable $e) {
                        $this->log('  ✗ Relacionados: ' . $e->getMessage());
                    }
                }

                // FAQ
                $contentFinal .= $builder->buildFaqHtml($artigo['faq'] ?? []);

                // Schemas (construídos em PHP — Product, ItemList, AggregateRating, Offer, FAQPage)
                if ($hasProducts) {
                    $contentFinal .= $builder->buildSchemas($artigo);
                    $this->log('  ✓ Schemas: ItemList + Product/Review/Offer/AggregateRating + FAQPage');
                } else {
                    // Fallback: schemas simples
                    $contentFinal .= $this->fallbackSchemas($artigo);
                }

                // Slug
                $slug = ($artigo['slug'] ?? sanitize($keyword));
                if (count($formatos) > 1) {
                    $slug .= '-' . $fmt;
                }

                $payload = [
                    'title'      => $artigo['title'],
                    'slug'       => $slug,
                    'content'    => $contentFinal,
                    'excerpt'    => $artigo['excerpt'] ?? '',
                    'status'     => $this->cfg['wp_default_status'],
                    'categories' => $catIds,
                    'tags'       => $tagIds,
                    'meta'       => $this->metaRankMath($artigo),
                ];
                if ($featuredId) $payload['featured_media'] = $featuredId;

                $this->log("📤 Criando draft [$fmtNome] no WordPress...");
                $post = $this->wp->criarPost($payload);
                $pid = $post['id'] ?? null;
                $this->log("  ✅ Post #$pid criado");

                $resultados[] = [
                    'formato'   => $fmt,
                    'nome'      => $fmtNome,
                    'post_id'   => $pid,
                    'edit_url'  => rtrim($this->cfg['wp_url'], '/') . "/wp-admin/post.php?post=$pid&action=edit",
                    'preview'   => $post['link'] ?? null,
                    'titulo'    => $artigo['title'],
                    'palavras'  => str_word_count(strip_tags($contentFinal)),
                    'fontes'    => count($fontes),
                    'ok'        => true,
                ];
            } catch (Throwable $e) {
                $this->log("  ❌ Erro [$fmtNome]: " . $e->getMessage());
                $resultados[] = [
                    'formato' => $fmt,
                    'nome'    => $fmtNome,
                    'ok'      => false,
                    'erro'    => $e->getMessage(),
                ];
            }
        }

        return [
            'keyword'    => $keyword,
            'fontes'     => count($fontes),
            'resultados' => $resultados,
            'log'        => $this->log,
        ];
    }

    /** Schemas fallback quando não há products estruturados. */
    private function fallbackSchemas(array $a): string
    {
        $html = '';
        $schemas = [];

        $nowIso = date('c');
        $siteName = $this->cfg['site_name'] ?? 'Redação';
        $siteUrl  = $this->cfg['wp_url'] ?? '';

        // Schema_type='none' é sentinela do formato 'discover' (Claude.php:62) —
        // significa "RankMath cuida do Article/NewsArticle". NÃO emitir schema
        // template aqui (causa "@type":"none" inválido no JSON-LD final).
        $schemaType = $a['schema_type'] ?? 'Article';
        if ($schemaType !== 'none') {
            $schemas[] = [
                '@context'      => 'https://schema.org',
                '@type'         => $schemaType,
                'headline'      => mb_substr($a['title'] ?? '', 0, 110),
                'description'   => $a['meta_description'] ?? $a['excerpt'] ?? '',
                'datePublished' => $nowIso,
                'dateModified'  => $nowIso,
                'author'        => [
                    '@type' => 'Organization',
                    'name'  => $siteName,
                    'url'   => $siteUrl,
                ],
                'publisher'     => [
                    '@type' => 'Organization',
                    'name'  => $siteName,
                    'url'   => $siteUrl,
                ],
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $siteUrl],
            ];
        }

        if (!empty($a['faq'])) {
            $schemas[] = [
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => array_map(fn($q) => [
                    '@type' => 'Question',
                    'name'  => $q['q'] ?? '',
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['a'] ?? ''],
                ], $a['faq']),
            ];
        }

        if (!empty($a['is_howto']) && !empty($a['howto_steps'])) {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type'    => 'HowTo',
                'name'     => $a['title'],
                'step'     => array_map(fn($s, $i) => [
                    '@type'    => 'HowToStep',
                    'position' => $i + 1,
                    'name'     => $s['name'] ?? '',
                    'text'     => $s['text'] ?? $s['name'] ?? '',
                ], $a['howto_steps'], array_keys($a['howto_steps'])),
            ];
        }

        $tags = '';
        foreach ($schemas as $s) {
            $json = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $tags .= "<script type=\"application/ld+json\">{$json}</script>";
        }
        return "\n<!-- wp:html -->\n" . $tags . "\n<!-- /wp:html -->";
    }

    /** Leia também — lista simples de títulos (sem imagem). */
    private function montarRelacionados(array $posts): string
    {
        if (empty($posts)) return '';
        $html = "\n<!-- leia-tambem -->\n"
              . "<div class='leia-tambem' style='background:#f8fafc;border-left:4px solid #0369a1;padding:16px 20px;margin:30px 0;border-radius:8px'>"
              . "<strong style='font-size:1.1em;color:#0c4a6e;display:block;margin-bottom:10px'>Leia também</strong>"
              . "<ul style='margin:0;padding-left:18px;list-style:none'>";
        foreach ($posts as $p) {
            $titulo = htmlspecialchars((string)($p['title'] ?? ''));
            $link   = htmlspecialchars((string)($p['link']  ?? ''));
            if ($titulo === '' || $link === '') continue;
            $html .= "<li style='margin-bottom:6px;padding-left:4px'>"
                  .    "<strong style='color:#0369a1'>+</strong> "
                  .    "<a href='{$link}'>{$titulo}</a>"
                  . "</li>";
        }
        $html .= "</ul></div>\n<!-- /leia-tambem -->\n";
        return $html;
    }

    private function metaRankMath(array $a): array
    {
        // Combina focus_keyword + secondary_keywords em uma string CSV.
        // RankMath aceita múltiplas keywords separadas por vírgula no mesmo campo,
        // melhorando match semântico em queries variadas (vs só 1 frase exata).
        $kws = [];
        if (!empty($a['focus_keyword'])) $kws[] = trim((string)$a['focus_keyword']);
        if (!empty($a['secondary_keywords']) && is_array($a['secondary_keywords'])) {
            foreach ($a['secondary_keywords'] as $sk) {
                $sk = trim((string)$sk);
                if ($sk !== '' && !in_array($sk, $kws, true)) $kws[] = $sk;
            }
        }
        $kwsStr = implode(', ', $kws);

        return [
            'rank_math_title'                => $a['meta_title'] ?? $a['title'],
            'rank_math_description'          => $a['meta_description'] ?? $a['excerpt'] ?? '',
            'rank_math_focus_keyword'        => $kwsStr,
            'rank_math_facebook_title'       => $a['meta_title'] ?? $a['title'],
            'rank_math_facebook_description' => $a['meta_description'] ?? '',
            'rank_math_twitter_title'        => $a['meta_title'] ?? $a['title'],
            'rank_math_twitter_description'  => $a['meta_description'] ?? '',
            'rank_math_rich_snippet'         => 'off',
        ];
    }

    private function log(string $msg): void
    {
        $this->log[] = $msg;
    }

    /** Hosts de lojas / afiliados comerciais — estes viram Pretty Links com rel=sponsored nofollow. */
    private const HOSTS_COMERCIAIS = [
        'amzn.to', 'amazon.', 'mercadolivre.', 'mercadolibre.',
        'magazineluiza.', 'magazinevoce.', 'shopee.', 'aliexpress.',
        'shein.', 'casasbahia.', 'kabum.', 'americanas.', 'submarino.',
        'extra.com', 'pontofrio.', 'girafa.', 'fastshop.', 'pichau.',
        'terabyteshop.', 'dellstore.', 'samsung.com/br/', 'apple.com/br/',
    ];

    /**
     * Reescreve <a href="http..."> do conteúdo com a "inteligência" PrettyLinks:
     *   - Interno (mesmo host do site) → deixa intacto.
     *   - Comercial (lojas/afiliados) → PrettyLink + rel="sponsored nofollow noopener" + target="_blank".
     *   - Externo institucional  → mantém URL + rel="noopener" + target="_blank".
     *
     * Roda tanto no fluxo de products (após buildHtml) quanto no content_html direto.
     */
    private function aplicarPrettyNoHtml(string $html, PrettyLinks $pl): string
    {
        $prefix = $this->cfg['pretty_links_prefix'] ?? 'go';
        $siteHost = strtolower(parse_url($this->cfg['wp_url'] ?? '', PHP_URL_HOST) ?: '');
        $cache = [];
        $countPretty = 0;
        $countExterno = 0;

        $novo = preg_replace_callback(
            '#<a\s+([^>]*?)href=(["\'])(https?://[^"\']+)\2([^>]*)>(.*?)</a>#is',
            function ($m) use ($pl, $prefix, $siteHost, &$cache, &$countPretty, &$countExterno) {
                $before = $m[1];
                $quote  = $m[2];
                $url    = $m[3];
                $after  = $m[4];
                $text   = $m[5];

                $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
                // 1) Interno → não mexe
                if ($siteHost !== '' && stripos($host, $siteHost) !== false) return $m[0];

                // 2) Comercial? PrettyLink + rel=sponsored nofollow
                $isComercial = false;
                foreach (self::HOSTS_COMERCIAIS as $hc) {
                    if (stripos($host, $hc) !== false) { $isComercial = true; break; }
                }

                $destino = $url;
                if ($isComercial) {
                    if (isset($cache[$url])) {
                        $pretty = $cache[$url];
                    } else {
                        $anchor = trim(strip_tags($text));
                        $slug = PrettyLinks::slugify($anchor !== '' ? $anchor : $host, $prefix);
                        try { $pretty = $pl->criarOuBuscar($url, $slug, $anchor ?: $host, true, '301'); }
                        catch (Throwable $e) { $pretty = null; }
                        $cache[$url] = $pretty;
                    }
                    if ($pretty) { $destino = $pretty; $countPretty++; }
                } else {
                    $countExterno++;
                }

                // Limpa rel/target pré-existentes e reaplica
                $attrs = preg_replace('#\s*rel\s*=\s*["\'][^"\']*["\']#i', '', $before . $after);
                $attrs = preg_replace('#\s*target\s*=\s*["\'][^"\']*["\']#i', '', $attrs);
                $rel = $isComercial ? 'sponsored nofollow noopener' : 'noopener';
                return '<a ' . trim($attrs) . ' href=' . $quote . htmlspecialchars($destino, ENT_QUOTES) . $quote . ' rel="' . $rel . '" target="_blank">' . $text . '</a>';
            },
            $html
        );

        if ($countPretty > 0)  $this->log("  ✓ {$countPretty} link(s) comercial(is) → Pretty Link");
        if ($countExterno > 0) $this->log("  ✓ {$countExterno} link(s) externo(s) → rel+target ajustados");
        return $novo ?? $html;
    }
}

if (!function_exists('sanitize')) {
    function sanitize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    }
}
