<?php
/**
 * SEO & Google Discover Optimization
 * 
 * Schema.org, Open Graph, meta tags, sitemap customization
 * Conformidade com requisitos do Google Discover
 * 
 * @package LeaoDaBarra
 */

defined('ABSPATH') || exit;

// ============================================================
// META TAGS (Open Graph, Twitter Cards, etc.)
// ============================================================
function ldb_seo_meta_tags() {
    $title       = '';
    $description = '';
    $image       = '';
    $url         = '';
    $type        = 'website';

    if (is_singular()) {
        global $post;
        $title       = get_the_title();
        $description = has_excerpt() ? get_the_excerpt() : wp_trim_words(strip_tags($post->post_content), 30);
        $url         = get_permalink();
        $type        = 'article';

        // Imagem: featured image >= 1200px (requisito Discover)
        if (has_post_thumbnail()) {
            $img_data = wp_get_attachment_image_src(get_post_thumbnail_id(), 'ldb-hero');
            if ($img_data) {
                $image = $img_data[0];
            }
        }
    } elseif (is_home() || is_front_page()) {
        $title       = get_bloginfo('name') . ' - ' . get_bloginfo('description');
        $description = 'Notícias do Esporte Clube Vitória, futebol brasileiro e internacional. Tabelas, resultados, bastidores e muito mais.';
        $url         = home_url('/');
        $image       = LDB_URI . '/assets/img/og-default.jpg';
    } elseif (is_category() || is_tax()) {
        $term        = get_queried_object();
        $title       = $term->name . ' - ' . get_bloginfo('name');
        $description = $term->description ?: 'Últimas notícias sobre ' . $term->name . ' no Leão da Barra.';
        $url         = get_term_link($term);
    } elseif (is_author()) {
        $author      = get_queried_object();
        $title       = $author->display_name . ' - ' . get_bloginfo('name');
        $description = 'Artigos de ' . $author->display_name . ' no Leão da Barra.';
        $url         = get_author_posts_url($author->ID);
    }

    if (!$image) {
        $image = LDB_URI . '/assets/img/og-default.jpg';
    }

    // Sanitizar
    $title       = esc_attr(wp_strip_all_tags($title));
    $description = esc_attr(wp_strip_all_tags($description));
    $image       = esc_url($image);
    $url         = esc_url($url);

    // Open Graph (Facebook, Discover)
    echo '<meta property="og:type" content="' . $type . '" />' . "\n";
    echo '<meta property="og:title" content="' . $title . '" />' . "\n";
    echo '<meta property="og:description" content="' . $description . '" />' . "\n";
    echo '<meta property="og:url" content="' . $url . '" />' . "\n";
    echo '<meta property="og:image" content="' . $image . '" />' . "\n";
    echo '<meta property="og:image:width" content="1200" />' . "\n";
    echo '<meta property="og:image:height" content="630" />' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '" />' . "\n";
    echo '<meta property="og:locale" content="pt_BR" />' . "\n";

    // Twitter Card
    echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
    echo '<meta name="twitter:title" content="' . $title . '" />' . "\n";
    echo '<meta name="twitter:description" content="' . $description . '" />' . "\n";
    echo '<meta name="twitter:image" content="' . $image . '" />' . "\n";

    // Meta description
    echo '<meta name="description" content="' . $description . '" />' . "\n";

    // Robots
    if (is_search() || is_404()) {
        echo '<meta name="robots" content="noindex, follow" />' . "\n";
    } else {
        echo '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1" />' . "\n";
    }

    // Artigo
    if (is_singular('post') || is_singular('bastidores')) {
        echo '<meta property="article:published_time" content="' . esc_attr(get_the_date('c')) . '" />' . "\n";
        echo '<meta property="article:modified_time" content="' . esc_attr(get_the_modified_date('c')) . '" />' . "\n";
        echo '<meta property="article:author" content="' . esc_attr(get_the_author()) . '" />' . "\n";

        $cats = get_the_category();
        foreach ($cats as $cat) {
            echo '<meta property="article:section" content="' . esc_attr($cat->name) . '" />' . "\n";
        }

        $tags = get_the_tags();
        if ($tags) {
            foreach ($tags as $tag) {
                echo '<meta property="article:tag" content="' . esc_attr($tag->name) . '" />' . "\n";
            }
        }
    }
}
add_action('wp_head', 'ldb_seo_meta_tags', 1);

// ============================================================
// SCHEMA.ORG (JSON-LD)
// ============================================================
function ldb_schema_organization() {
    if (!is_front_page()) return;

    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'NewsMediaOrganization',
        'name'        => 'Leão da Barra',
        'url'         => home_url('/'),
        'logo'        => [
            '@type'  => 'ImageObject',
            'url'    => LDB_URI . '/assets/img/logo.png',
            'width'  => 250,
            'height' => 60,
        ],
        'description' => 'Portal de notícias do Esporte Clube Vitória e futebol brasileiro e internacional.',
        'sameAs'      => [
            get_option('ldb_facebook_url', ''),
            get_option('ldb_twitter_url', ''),
            get_option('ldb_instagram_url', ''),
            get_option('ldb_youtube_url', ''),
        ],
        'address'     => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'Salvador',
            'addressRegion'   => 'BA',
            'addressCountry'  => 'BR',
        ],
    ];

    // Remover sameAs vazios
    $schema['sameAs'] = array_values(array_filter($schema['sameAs']));

    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
add_action('wp_head', 'ldb_schema_organization');

function ldb_schema_article() {
    if (!is_singular(['post', 'bastidores'])) return;

    global $post;

    $image = '';
    if (has_post_thumbnail()) {
        $img_data = wp_get_attachment_image_src(get_post_thumbnail_id(), 'ldb-hero');
        if ($img_data) {
            $image = $img_data[0];
        }
    }

    $schema = [
        '@context'         => 'https://schema.org',
        '@type'            => 'NewsArticle',
        'headline'         => get_the_title(),
        'description'      => has_excerpt() ? get_the_excerpt() : wp_trim_words(strip_tags($post->post_content), 30),
        'image'            => $image ?: LDB_URI . '/assets/img/og-default.jpg',
        'datePublished'    => get_the_date('c'),
        'dateModified'     => get_the_modified_date('c'),
        'author'           => [
            '@type' => 'Person',
            'name'  => get_the_author(),
            'url'   => get_author_posts_url(get_the_author_meta('ID')),
        ],
        'publisher'        => [
            '@type' => 'Organization',
            'name'  => 'Leão da Barra',
            'logo'  => [
                '@type' => 'ImageObject',
                'url'   => LDB_URI . '/assets/img/logo.png',
            ],
        ],
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id'   => get_permalink(),
        ],
        'articleSection'   => [],
        'keywords'         => [],
    ];

    $cats = get_the_category();
    foreach ($cats as $cat) {
        $schema['articleSection'][] = $cat->name;
    }

    $tags = get_the_tags();
    if ($tags) {
        foreach ($tags as $tag) {
            $schema['keywords'][] = $tag->name;
        }
    }

    // Tempo de leitura estimado
    $word_count = str_word_count(strip_tags($post->post_content));
    $reading_time = max(1, ceil($word_count / 200));
    $schema['timeRequired'] = 'PT' . $reading_time . 'M';

    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
add_action('wp_head', 'ldb_schema_article');

// ============================================================
// GOOGLE DISCOVER REQUIREMENTS
// ============================================================

/**
 * Garante que imagens atendam requisito mínimo do Discover (1200px)
 */
function ldb_discover_image_check($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    $thumb_id = get_post_thumbnail_id($post_id);
    if (!$thumb_id) return;

    $meta = wp_get_attachment_metadata($thumb_id);
    if ($meta && isset($meta['width']) && $meta['width'] < 1200) {
        update_post_meta($post_id, '_ldb_image_warning', 'Imagem menor que 1200px. Para Google Discover, use imagens >= 1200px de largura.');
    } else {
        delete_post_meta($post_id, '_ldb_image_warning');
    }
}
add_action('save_post', 'ldb_discover_image_check');

/**
 * Aviso no editor se imagem pequena
 */
function ldb_discover_image_admin_notice() {
    global $post;
    if (!$post) return;

    $warning = get_post_meta($post->ID, '_ldb_image_warning', true);
    if ($warning) {
        echo '<div class="notice notice-warning"><p><strong>Google Discover:</strong> ' . esc_html($warning) . '</p></div>';
    }
}
add_action('admin_notices', 'ldb_discover_image_admin_notice');

// ============================================================
// CANONICAL & HREFLANG
// ============================================================
function ldb_canonical_url() {
    if (is_singular()) {
        echo '<link rel="canonical" href="' . esc_url(get_permalink()) . '" />' . "\n";
    } elseif (is_home() || is_front_page()) {
        echo '<link rel="canonical" href="' . esc_url(home_url('/')) . '" />' . "\n";
    } elseif (is_category() || is_tax()) {
        echo '<link rel="canonical" href="' . esc_url(get_term_link(get_queried_object())) . '" />' . "\n";
    }
}
add_action('wp_head', 'ldb_canonical_url', 1);

// ============================================================
// SITEMAP CUSTOMIZATION (only for WP native sitemap, not Rank Math)
// ============================================================
function ldb_sitemap_post_types($post_types) {
    // Don't modify if Rank Math or Yoast handles sitemaps
    if (class_exists('RankMath') || defined('WPSEO_VERSION')) {
        return $post_types;
    }

    $bastidores = get_post_type_object('bastidores');
    $historia   = get_post_type_object('historia');

    if ($bastidores) $post_types['bastidores'] = $bastidores;
    if ($historia)   $post_types['historia']   = $historia;

    return $post_types;
}
add_filter('wp_sitemaps_post_types', 'ldb_sitemap_post_types');

function ldb_sitemap_taxonomies($taxonomies) {
    if (class_exists('RankMath') || defined('WPSEO_VERSION')) {
        return $taxonomies;
    }

    $campeonato = get_taxonomy('campeonato');
    $time       = get_taxonomy('time');
    $escopo     = get_taxonomy('escopo');

    if ($campeonato) $taxonomies['campeonato'] = $campeonato;
    if ($time)       $taxonomies['time']       = $time;
    if ($escopo)     $taxonomies['escopo']      = $escopo;

    return $taxonomies;
}
add_filter('wp_sitemaps_taxonomies', 'ldb_sitemap_taxonomies');

function ldb_sitemap_entry($entry, $post_type, $post) {
    if (class_exists('RankMath') || defined('WPSEO_VERSION')) {
        return $entry;
    }
    $entry['lastmod'] = get_the_modified_date('c', $post);
    return $entry;
}
add_filter('wp_sitemaps_posts_entry', 'ldb_sitemap_entry', 10, 3);

// ============================================================
// DISABLE DUPLICATE META (se Yoast/RankMath ativo)
// ============================================================
function ldb_check_seo_plugin() {
    if (defined('WPSEO_VERSION') || class_exists('RankMath')) {
        // Remove nosso OG/Schema (plugin cuida disso)
        remove_action('wp_head', 'ldb_schema_organization');
        remove_action('wp_head', 'ldb_schema_article');
        remove_action('wp_head', 'ldb_canonical_url', 1);
        // Mantém ldb_seo_meta_tags como fallback apenas para meta description
        // caso o plugin não gere (ex: home page sem configuração)
    }
}
add_action('wp', 'ldb_check_seo_plugin');

/**
 * Fallback meta description - só adiciona se nenhum plugin já adicionou
 */
function ldb_fallback_meta_description() {
    // Se Rank Math ou Yoast está ativo, verificar se já tem meta description
    if (class_exists('RankMath') || defined('WPSEO_VERSION')) {
        // O plugin deveria gerar, mas se a home não tiver configurada, geramos
        if (is_front_page() || is_home()) {
            $desc = get_option('blogdescription', '');
            if (empty($desc)) {
                $desc = 'Notícias do Esporte Clube Vitória, futebol brasileiro e internacional. Tabelas, resultados, bastidores e muito mais.';
            }
            // Verificar se o plugin já gerou (checar buffer não é viável, então geramos sempre na home)
            echo '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
        }
    }
}
add_action('wp_head', 'ldb_fallback_meta_description', 2);
