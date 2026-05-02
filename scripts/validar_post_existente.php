<?php
/**
 * scripts/validar_post_existente.php
 *
 * Roda AntiAIValidator + SourceFidelityValidator num post WP que já foi gerado.
 * Útil pra checar artigos que saíram antes dos validators serem wireados.
 *
 * Uso:
 *   php scripts/validar_post_existente.php --site=SLUG --trend-id=N
 *   php scripts/validar_post_existente.php --site=SLUG --post-id=716
 *
 * Re-scrapa as fontes do trend pra ter contexto pra fidelity check.
 */

$siteArg  = '';
$trendId  = 0;
$postIdArg = 0;
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m)) $siteArg = $m[1];
    if (preg_match('/^--trend-id=(\d+)$/', $a, $m)) $trendId = (int)$m[1];
    if (preg_match('/^--post-id=(\d+)$/', $a, $m)) $postIdArg = (int)$m[1];
}
if ($siteArg === '' || ($trendId <= 0 && $postIdArg <= 0)) {
    fwrite(STDERR, "Uso: php scripts/validar_post_existente.php --site=SLUG (--trend-id=N | --post-id=N)\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
$sites = sitesDisponiveis();
if (!isset($sites[$siteArg])) { fwrite(STDERR, "Site '{$siteArg}' não existe.\n"); exit(2); }
aplicarSite($cfg, $sites, $siteArg);

require_once __DIR__ . '/../lib/DiscoverDb.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Scraper.php';
require_once __DIR__ . '/../lib/Serper.php';
require_once __DIR__ . '/../lib/GoogleNewsRss.php';
require_once __DIR__ . '/../lib/TrendsArticles.php';
require_once __DIR__ . '/../lib/DiscoverFontes.php';
require_once __DIR__ . '/../lib/AntiAIValidator.php';
require_once __DIR__ . '/../lib/SourceFidelityValidator.php';

$db = new DiscoverDb();
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

// Resolve trend e termo
$trend = null;
if ($trendId > 0) {
    $trend = $db->get($trendId);
    if (!$trend) { fwrite(STDERR, "Trend #{$trendId} não encontrado.\n"); exit(2); }
    $postId = (int)($trend['post_id'] ?? 0);
    if ($postIdArg > 0) $postId = $postIdArg; // override manual quando trend->post_id=0
}
if ($postId <= 0 && $postIdArg > 0) $postId = $postIdArg;
if ($postId <= 0) { fwrite(STDERR, "Post WP id não resolvido. Passa --post-id=N explícito.\n"); exit(2); }

echo "═══ VALIDANDO POST EXISTENTE ═══\n";
echo "  Site:    {$siteArg}\n";
echo "  Trend:   #" . ($trendId ?: '?') . "\n";
echo "  Post WP: #{$postId}\n";
if ($trend) echo "  Termo:   " . ($trend['termo'] ?? '?') . "\n";
echo "\n";

// Busca conteúdo do post
try {
    $post = $wp->getPost($postId);
} catch (Throwable $e) {
    fwrite(STDERR, "Falha ao buscar post WP: " . $e->getMessage() . "\n");
    exit(2);
}
$content = $post['content']['raw'] ?? $post['content']['rendered'] ?? '';
if ($content === '') { fwrite(STDERR, "Post #{$postId} sem conteúdo.\n"); exit(2); }

echo "Conteúdo: " . strlen($content) . " bytes · ~" . str_word_count(strip_tags($content)) . " palavras\n\n";

// AntiAIValidator
echo "═══ AntiAIValidator ═══\n";
$ai = new AntiAIValidator();
$aiReport = $ai->validate($content);
echo "  severity: {$aiReport['severity']}\n";
echo "  phrase violations: {$aiReport['total_phrase_violations']}\n";
if (!empty($aiReport['violations'])) {
    foreach (array_slice($aiReport['violations'], 0, 8) as $v) {
        echo "    ✗ banida: \"{$v['phrase']}\" x{$v['count']} ({$v['category']})\n";
    }
}
if (!empty($aiReport['structural'])) {
    foreach ($aiReport['structural'] as $issue) {
        echo "    ✗ estrutural: {$issue}\n";
    }
}
echo "\n";

// Re-scrapa fontes pra fidelity (precisa de Serper + Scraper)
echo "═══ Re-scrapando fontes (Serper + Scrape pra contexto fidelity) ═══\n";
if ($trend && !empty($trend['termo']) && !empty($cfg['serper_api_key'])) {
    $serper  = new Serper($cfg['serper_api_key']);
    $scraper = new Scraper($cfg['user_agent'] ?? 'Mozilla/5.0', $cfg['scrape_timeout'] ?? 15);
    $artigos = new TrendsArticles($serper, $scraper, $cfg['user_agent'] ?? 'Mozilla/5.0');
    $coletor = new DiscoverFontes($cfg, $artigos, $scraper);
    $col = $coletor->coletar($trend['termo'], 5);
    if (!empty($col['ok'])) {
        $fontesOk = $col['fontes_ok'];
        echo "  ✓ " . count($fontesOk) . " fontes scrapeadas, " . $col['chars_totais'] . " chars\n";

        $textosFontes = [];
        foreach ($fontesOk as $f) {
            $paragraphs = $f['fonte']['content']['paragraphs'] ?? [];
            if (!empty($paragraphs)) $textosFontes[] = implode("\n", $paragraphs);
            $meta = $f['fonte']['meta'] ?? [];
            if (!empty($meta['title'])) $textosFontes[] = (string)$meta['title'];
            if (!empty($meta['description'])) $textosFontes[] = (string)$meta['description'];
        }

        echo "\n═══ SourceFidelityValidator ═══\n";
        $fidReport = SourceFidelityValidator::validar($content, $textosFontes);
        echo "  severity: {$fidReport['severity']}\n";
        echo "  nomes extraídos do artigo: {$fidReport['stats']['nomes_extraidos']}\n";
        echo "  urls extraídas do artigo:  {$fidReport['stats']['urls_extraidas']}\n";
        echo "  fontes (chars total):      {$fidReport['stats']['fontes_chars']}\n";
        if (!empty($fidReport['issues'])) {
            echo "\n  Issues detectadas (" . count($fidReport['issues']) . "):\n";
            foreach ($fidReport['issues'] as $i) {
                echo "    [{$i['tipo']}] \"{$i['valor']}\"\n";
                if (!empty($i['contexto'])) echo "       contexto: {$i['contexto']}\n";
            }
        } else {
            echo "  ✓ tudo bate com a fonte\n";
        }
    } else {
        echo "  ✗ falha na coleta: " . ($col['erro'] ?? '?') . "\n";
    }
} else {
    echo "  (skip: trend sem termo ou serper_api_key não configurado)\n";
}

echo "\n═══ FIM ═══\n";
