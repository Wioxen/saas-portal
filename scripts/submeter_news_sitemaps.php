<?php
/**
 * submeter_news_sitemaps — submete /news-sitemap.xml de cada site ao GSC.
 *
 * Cron horário força re-crawl. Indexação em segundos vs minutos do sitemap padrão.
 * Crítico pra spike detection capitalizar a janela do Discover (30-60min).
 *
 * Pré-requisitos:
 *   - Plugin cc-news-sitemap.php instalado e ativado em cada site WP
 *   - URL acessível: curl https://{site}/news-sitemap.xml retorna XML válido
 *   - GSC Service Account autorizada como "user" no site
 *
 * Uso:
 *   php scripts/submeter_news_sitemaps.php
 *   php scripts/submeter_news_sitemaps.php --site=cursosenac
 *   php scripts/submeter_news_sitemaps.php --validar      # só checa URL (não submete GSC)
 *
 * Cron sugerido (horário, top of hour):
 *   0 * * * * /usr/bin/php /var/www/clonais/scripts/submeter_news_sitemaps.php --quiet >> /var/log/clonais/news_sitemap.log 2>&1
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/HttpClient.php';
require_once $ROOT . '/lib/DiscoverSearchConsole.php';
require_once $ROOT . '/_site_helper.php';

$cfg = require $ROOT . '/config.php';

$soSite = null; $soValidar = false; $quiet = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--site='))   $soSite = substr($a, 7);
    elseif ($a === '--validar')           $soValidar = true;
    elseif ($a === '--quiet')             $quiet = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo '[' . date('Y-m-d H:i:s') . "] {$m}\n"; }

$lock = new CronLock('submeter_news_sitemaps');
if (!$lock->aquirir()) { log_msg('outra instância rodando — saindo', $quiet); exit(0); }

$gsc = new DiscoverSearchConsole();
$sites = sitesDisponiveis();
if ($soSite !== null) {
    if (!isset($sites[$soSite])) { fwrite(STDERR, "Site '{$soSite}' não existe\n"); exit(2); }
    $sites = [$soSite => $sites[$soSite]];
}

$sucesso = 0; $falhas = 0;

foreach ($sites as $slug => $siteCfg) {
    $wpUrl = rtrim((string)($siteCfg['wp_url'] ?? ''), '/');
    if ($wpUrl === '') continue;
    $sitemapUrl = $wpUrl . '/news-sitemap.xml';
    $gscUrl = (string)($siteCfg['gsc_site_url'] ?? ($wpUrl . '/'));

    // 1. Valida que o sitemap está acessível e contém XML válido
    $head = HttpClient::get($sitemapUrl, ['timeout' => 10, 'tries' => 1]);
    if (!$head['ok'] || stripos((string)($head['body'] ?? ''), '<urlset') === false) {
        log_msg(sprintf('[%s] ✗ sitemap inacessível ou vazio (HTTP %d): %s', $slug, $head['http_code'], $sitemapUrl), $quiet);
        $falhas++;
        continue;
    }

    // Conta URLs no XML pra log
    preg_match_all('/<url>/i', (string)$head['body'], $m);
    $nUrls = count($m[0]);
    log_msg(sprintf('[%s] sitemap OK: %d URLs nas últimas 48h', $slug, $nUrls), $quiet);

    if ($soValidar) {
        $sucesso++;
        continue;
    }

    // 2. Submete via GSC API
    try {
        $ok = $gsc->submeterSitemap($gscUrl, $sitemapUrl);
        if ($ok) {
            log_msg("[{$slug}] ✓ submetido ao GSC: {$sitemapUrl}", $quiet);
            $sucesso++;
        } else {
            log_msg("[{$slug}] ✗ GSC rejeitou submissão", $quiet);
            $falhas++;
        }
    } catch (Throwable $e) {
        log_msg("[{$slug}] ✗ erro GSC: " . $e->getMessage(), $quiet);
        $falhas++;
    }
}

log_msg(sprintf('RESUMO: %d sucesso · %d falhas', $sucesso, $falhas), $quiet);

if ($falhas > 0) {
    require_once $ROOT . '/lib/HealthWebhook.php';
    HealthWebhook::aviso('news_sitemap: falhas', ['falhas' => $falhas, 'sucesso' => $sucesso]);
}

$lock->liberar();
exit($falhas > 0 && $sucesso === 0 ? 3 : 0);
