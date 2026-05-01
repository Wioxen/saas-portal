<?php
/**
 * Plugin Name: CC News Sitemap
 * Description: Expõe /news-sitemap.xml com posts publicados nas últimas 48h (formato Google News Sitemap). Permite indexação rápida em Google News e Discover.
 * Version: 1.0
 * Author: Clonais
 *
 * Endpoint exposto: https://{site}/news-sitemap.xml
 *
 * Formato seguindo spec oficial:
 *   https://developers.google.com/search/docs/crawling-indexing/sitemaps/news-sitemap
 *
 * Regras:
 *   - Max 1000 URLs (Google limit)
 *   - Apenas posts publicados nas últimas 48h
 *   - Re-submissão via GSC API força re-crawl em segundos
 */

if (!defined('ABSPATH')) exit;

class CC_News_Sitemap
{
    public static function init()
    {
        add_action('init', [__CLASS__, 'register_rewrite']);
        add_action('template_redirect', [__CLASS__, 'serve']);
        add_filter('query_vars', [__CLASS__, 'add_query_var']);
    }

    public static function register_rewrite()
    {
        add_rewrite_rule(
            '^news-sitemap\.xml$',
            'index.php?cc_news_sitemap=1',
            'top'
        );
    }

    public static function add_query_var($vars)
    {
        $vars[] = 'cc_news_sitemap';
        return $vars;
    }

    public static function serve()
    {
        $flag = get_query_var('cc_news_sitemap');
        if (empty($flag)) return;

        // Cache 10 min — Google re-checa periodicamente, mas re-submissão força refresh
        nocache_headers();
        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow');
        header('Cache-Control: public, max-age=600');

        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-48 hours'));

        $args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 1000,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'date_query'     => [['after' => $cutoff, 'inclusive' => true]],
            'no_found_rows'  => true,
        ];
        $q = new WP_Query($args);

        $siteName = get_bloginfo('name') ?: 'Site';
        $language = 'pt-BR';

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ';
        echo 'xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

        if ($q->have_posts()) {
            while ($q->have_posts()) {
                $q->the_post();
                $url = esc_url(get_permalink());
                $titulo = self::cdataSafe(get_the_title());
                $publicacaoIso = mysql2date('c', get_the_date('Y-m-d H:i:s', get_the_ID()), false);

                echo "  <url>\n";
                echo "    <loc>{$url}</loc>\n";
                echo "    <news:news>\n";
                echo "      <news:publication>\n";
                echo "        <news:name>" . self::cdataSafe($siteName) . "</news:name>\n";
                echo "        <news:language>{$language}</news:language>\n";
                echo "      </news:publication>\n";
                echo "      <news:publication_date>{$publicacaoIso}</news:publication_date>\n";
                echo "      <news:title>{$titulo}</news:title>\n";
                echo "    </news:news>\n";
                echo "  </url>\n";
            }
            wp_reset_postdata();
        }

        echo '</urlset>' . "\n";
        exit;
    }

    /** Encapsula em CDATA quando texto tem caractere especial. */
    private static function cdataSafe($text)
    {
        $text = wp_strip_all_tags((string)$text);
        if (preg_match('/[<>&]/u', $text)) {
            return '<![CDATA[' . str_replace(']]>', ']]&gt;', $text) . ']]>';
        }
        return htmlspecialchars($text, ENT_XML1, 'UTF-8');
    }

    public static function activate()
    {
        self::register_rewrite();
        flush_rewrite_rules();
    }

    public static function deactivate()
    {
        flush_rewrite_rules();
    }
}

CC_News_Sitemap::init();
register_activation_hook(__FILE__, ['CC_News_Sitemap', 'activate']);
register_deactivation_hook(__FILE__, ['CC_News_Sitemap', 'deactivate']);
