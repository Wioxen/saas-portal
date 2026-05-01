<?php
/**
 * DiscoverRelatedLinks — 3 blocos de internal linking pra topical authority + UX:
 *
 *   1. Breadcrumbs visuais ("Início > Educação > Post atual")
 *      - Aparece no TOPO do post (antes do H1 ou logo após).
 *      - Schema BreadcrumbList já é gerado por DiscoverSchemas.
 *
 *   2. Continue lendo (3 posts relacionados)
 *      - Lista no fim do artigo, ANTES do "post-share" final.
 *      - Ranqueia por: mesmo cluster + similar_text(termo) >= 35%.
 *      - 3 posts max — mais que isso vira navegação ruim.
 *
 *   3. Back to Hub (link pra hub topical)
 *      - Se hub do cluster existe (`/hub-{cluster}`), bloco "Veja todos os N guias sobre X"
 *      - Aparece DEPOIS do Continue lendo.
 *
 * Distribui PageRank interno → posts profundos indexam melhor → Discover entende
 * estrutura tópica do site.
 *
 * Uso típico (em DiscoverPostProcess::processar com $trend+$cfg):
 *   $html = DiscoverRelatedLinks::injetar($html, $meta, $trend, $cfg, $db, $wp);
 */

require_once __DIR__ . '/DiscoverDb.php';
require_once __DIR__ . '/Wordpress.php';

class DiscoverRelatedLinks
{
    /** Limite de posts no "Continue lendo". */
    public const MAX_RELACIONADOS = 3;

    /** Similaridade mínima (similar_text %) pra entrar em "Continue lendo". */
    public const MIN_SIM = 35.0;

    /**
     * Aplica os 3 blocos no HTML.
     * Retorna HTML modificado (idempotente — markers evitam duplicação).
     *
     * @param string $html       HTML do post
     * @param array  $meta       ['titulo'=>..., 'url'=>...]
     * @param array  $trend      Trend completo do DB
     * @param array  $cfg        Cfg do site (com persona, wp_url, site_name)
     * @param DiscoverDb $db
     * @param Wordpress $wp
     */
    public static function injetar(string $html, array $meta, array $trend, array $cfg, DiscoverDb $db, Wordpress $wp): string
    {
        if (trim($html) === '') return $html;

        // 1. Breadcrumbs no topo
        $html = self::injetarBreadcrumbs($html, $trend, $cfg);

        // 2. Continue lendo (3 posts relacionados) ANTES do post-share final
        $html = self::injetarContinueLendo($html, $trend, $cfg, $db, $wp);

        // 3. Back to Hub
        $html = self::injetarBackToHub($html, $trend, $cfg, $wp);

        return $html;
    }

    // ─────────── 1. BREADCRUMBS VISUAIS ───────────

    private static function injetarBreadcrumbs(string $html, array $trend, array $cfg): string
    {
        if (strpos($html, 'data-cc-breadcrumb') !== false) return $html; // idempotente

        $siteUrl = rtrim((string)($cfg['wp_url'] ?? ''), '/');
        $siteName = (string)($cfg['site_name'] ?? $cfg['_site_name'] ?? 'Início');
        $clusterNome = (string)($trend['cluster_detect']['nome'] ?? '');
        $clusterKey  = (string)($trend['cluster_detect']['key'] ?? '');

        $items = [];
        $items[] = '<a href="' . htmlspecialchars($siteUrl . '/', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</a>';
        if ($clusterNome !== '' && $clusterKey !== '') {
            $items[] = '<a href="' . htmlspecialchars($siteUrl . '/categoria/' . self::slugify($clusterKey) . '/', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($clusterNome, ENT_QUOTES, 'UTF-8') . '</a>';
        }

        $separator = ' <span style="color:#94a3b8">›</span> ';
        $bloco = '<nav data-cc-breadcrumb="1" aria-label="Breadcrumb" '
               . 'style="font-size:13px;color:#64748b;margin:0 0 14px;padding:6px 0;">'
               . implode($separator, $items)
               . '</nav>';

        // Insere ANTES do primeiro <p> ou <h1>/<h2>
        if (preg_match('#<(h[12]|p|div|article)[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            return substr($html, 0, $pos) . $bloco . "\n" . substr($html, $pos);
        }
        return $bloco . "\n" . $html;
    }

    // ─────────── 2. CONTINUE LENDO ───────────

    private static function injetarContinueLendo(string $html, array $trend, array $cfg, DiscoverDb $db, Wordpress $wp): string
    {
        if (strpos($html, 'data-cc-continue-lendo') !== false) return $html; // idempotente

        $siteSlug = (string)($trend['site'] ?? $cfg['_site_slug'] ?? '');
        $clusterKey = (string)($trend['cluster_detect']['key'] ?? '');
        $termoAtual = (string)($trend['termo'] ?? '');
        $postIdAtual = self::extrairPostId((string)($trend['url_post'] ?? ''));

        if ($siteSlug === '' || $clusterKey === '' || $termoAtual === '') return $html;

        // Lista posts publicados do mesmo cluster, exclui post atual
        $candidatos = $db->all(['site' => $siteSlug, 'status' => 'publicado']);
        $relevantes = [];
        $agora = time();
        foreach ($candidatos as $p) {
            if ((string)($p['cluster_detect']['key'] ?? '') !== $clusterKey) continue;
            $pid = self::extrairPostId((string)($p['url_post'] ?? ''));
            if ($pid <= 0 || $pid === $postIdAtual) continue;
            $termoCand = (string)($p['termo'] ?? '');
            similar_text(mb_strtolower($termoAtual, 'UTF-8'), mb_strtolower($termoCand, 'UTF-8'), $sim);
            if ($sim < self::MIN_SIM) continue;
            if ($sim >= 95) continue; // duplicação

            // Score composto: similar (peso 1) + recência (peso 0.4) — recente vai pra cima
            $publicadoTs = strtotime((string)($p['publicado_em'] ?? '')) ?: 0;
            $diasDesdePub = $publicadoTs > 0 ? max(1, ($agora - $publicadoTs) / 86400) : 999;
            $boostRecencia = $diasDesdePub <= 30 ? (30 - $diasDesdePub) / 30 : 0;
            $scoreFinal = $sim + ($boostRecencia * 30); // boost até +30 pra posts <30d

            $relevantes[] = [
                'post_id' => $pid, 'termo' => $termoCand,
                'titulo' => (string)($p['titulo'] ?? ''),
                'sim' => $sim, 'score_final' => $scoreFinal,
                'publicado_em' => (string)($p['publicado_em'] ?? ''),
                'dias_desde_pub' => $diasDesdePub,
            ];
        }
        usort($relevantes, fn($a, $b) => $b['score_final'] <=> $a['score_final']);
        $relevantes = array_slice($relevantes, 0, self::MAX_RELACIONADOS);
        if (empty($relevantes)) return $html;

        // Resolve URL pública via WP (cache local)
        $itemsHtml = '';
        foreach ($relevantes as $r) {
            try {
                $wpPost = $wp->getPost($r['post_id']);
                $link = (string)($wpPost['link'] ?? '');
                $statusWp = (string)($wpPost['status'] ?? '');
                if ($link === '' || $statusWp !== 'publish') continue;
                $titulo = trim(strip_tags(html_entity_decode((string)($wpPost['title']['rendered'] ?? $r['titulo']))));
            } catch (Throwable $e) { continue; }

            $tituloEsc = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
            $linkEsc = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
            $itemsHtml .= '<li style="padding:10px 0;border-bottom:1px solid #e5e7eb">'
                       . '<a href="' . $linkEsc . '" style="color:#0f172a;font-weight:600;text-decoration:none">' . $tituloEsc . '</a>'
                       . '</li>';
        }
        if ($itemsHtml === '') return $html;

        $bloco = '<aside data-cc-continue-lendo="1" '
               . 'style="margin:30px 0;padding:18px 22px;background:#f8fafc;border-left:4px solid #0ea5e9;border-radius:8px;font-family:sans-serif">'
               . '<p style="margin:0 0 12px;font-size:14px;font-weight:700;color:#0c4a6e;text-transform:uppercase;letter-spacing:0.5px">📚 Continue lendo</p>'
               . '<ul style="list-style:none;padding:0;margin:0">' . $itemsHtml . '</ul>'
               . '</aside>';

        // Insere ANTES de post-share / antes do </body>; senão no fim
        if (preg_match('#<div[^>]*data-post-share=#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            return substr($html, 0, $pos) . $bloco . "\n" . substr($html, $pos);
        }
        return $html . "\n" . $bloco;
    }

    // ─────────── 3. BACK TO HUB ───────────

    private static function injetarBackToHub(string $html, array $trend, array $cfg, Wordpress $wp): string
    {
        if (strpos($html, 'data-cc-hub-link') !== false) return $html; // idempotente

        $clusterKey = (string)($trend['cluster_detect']['key'] ?? '');
        $clusterNome = (string)($trend['cluster_detect']['nome'] ?? '');
        $siteUrl = rtrim((string)($cfg['wp_url'] ?? ''), '/');
        if ($clusterKey === '' || $siteUrl === '') return $html;

        // Hub URL convencional: /hub-{cluster_key}
        $hubSlug = 'hub-' . self::slugify($clusterKey);
        $hubUrl = $siteUrl . '/' . $hubSlug . '/';

        // Verifica se hub existe (HEAD rápido) — fail-safe: se não existir, pula
        $existe = self::hubExiste($wp, $hubSlug);
        if (!$existe) return $html;

        $clusterDisplay = $clusterNome !== '' ? $clusterNome : 'esse tema';
        $bloco = '<aside data-cc-hub-link="1" '
               . 'style="margin:24px 0;padding:14px 20px;background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:8px;font-family:sans-serif;text-align:center">'
               . '<p style="margin:0;font-size:14px;color:#78350f">'
               . '🎯 <a href="' . htmlspecialchars($hubUrl, ENT_QUOTES, 'UTF-8') . '" '
               .       'style="color:#78350f;font-weight:700;text-decoration:underline">'
               . 'Veja todos os guias sobre ' . htmlspecialchars($clusterDisplay, ENT_QUOTES, 'UTF-8')
               . ' →</a>'
               . '</p>'
               . '</aside>';

        // Insere ANTES do continue-lendo se existir, senão antes do post-share, senão no fim
        if (preg_match('#<aside[^>]*data-cc-continue-lendo=#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            return substr($html, 0, $pos) . $bloco . "\n" . substr($html, $pos);
        }
        if (preg_match('#<div[^>]*data-post-share=#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            return substr($html, 0, $pos) . $bloco . "\n" . substr($html, $pos);
        }
        return $html . "\n" . $bloco;
    }

    /** Verifica se hub page existe via /pages?slug=X. Cache estático na request. */
    private static function hubExiste(Wordpress $wp, string $hubSlug): bool
    {
        static $cache = [];
        $cacheKey = $hubSlug;
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];

        try {
            $rc = new ReflectionClass($wp);
            $baseProp = $rc->getProperty('base'); $baseProp->setAccessible(true);
            $authProp = $rc->getProperty('auth'); $authProp->setAccessible(true);
            $base = $baseProp->getValue($wp);
            $auth = $authProp->getValue($wp);

            $url = $base . '/pages?slug=' . urlencode($hubSlug) . '&status=publish';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth],
                CURLOPT_TIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $existe = ($code === 200 && is_array(json_decode((string)$resp, true) ?: null) && trim((string)$resp) !== '[]');
            $cache[$cacheKey] = $existe;
            return $existe;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────── HELPERS ───────────

    private static function extrairPostId(string $urlPost): int
    {
        if (preg_match('/post=(\d+)/', $urlPost, $m)) return (int)$m[1];
        return 0;
    }

    private static function slugify(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        return trim($s, '-');
    }
}
