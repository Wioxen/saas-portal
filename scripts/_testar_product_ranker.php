<?php
/**
 * [TESTE] Valida DiscoverProductRanker offline — intent + scrape + tabela HTML.
 *
 * Uso:
 *   php scripts/_testar_product_ranker.php "10 ideias de presente dia das mães"
 *   php scripts/_testar_product_ranker.php "presentes até R$ 100 dia dos pais" --cluster=lifestyle_consumo
 *   php scripts/_testar_product_ranker.php --status            # mostra estado do cache de cada categoria
 *   php scripts/_testar_product_ranker.php --html "termo"      # imprime só HTML pra preview no browser
 *   php scripts/_testar_product_ranker.php --salvar termo      # salva HTML em /tmp/ranker.html
 */

require_once __DIR__ . '/../lib/DiscoverProductRanker.php';
require_once __DIR__ . '/../lib/AmazonScraper.php';

$termo = '';
$cluster = '';
$soHtml = false;
$salvar = false;
$soStatus = false;
$siteSlug = '';

foreach (array_slice($argv, 1) as $a) {
    if ($a === '--status') $soStatus = true;
    elseif (str_starts_with($a, '--cluster=')) $cluster = substr($a, 10);
    elseif (str_starts_with($a, '--site=')) $siteSlug = substr($a, 7);
    elseif ($a === '--html') $soHtml = true;
    elseif ($a === '--salvar') $salvar = true;
    elseif ($a !== '' && $a[0] !== '-') $termo = trim($termo . ' ' . $a);
}

if ($soStatus) {
    $scraper = new AmazonScraper();
    foreach (AmazonScraper::categoriasDisponiveis() as $cat) {
        $s = $scraper->statusCache($cat);
        printf("%-14s | existe=%s | idade=%-6s | count=%-3s | erro=%s\n",
            $cat,
            $s['existe'] ? 'sim' : 'nao',
            isset($s['idade_seg']) ? round($s['idade_seg']/3600, 1) . 'h' : '-',
            $s['count'] ?? '-',
            $s['erro'] ?? '-'
        );
    }
    exit(0);
}

if ($termo === '') {
    fwrite(STDERR, "Uso: php scripts/_testar_product_ranker.php \"<termo>\" [--cluster=lifestyle_consumo|...] [--html|--salvar|--status]\n");
    exit(1);
}

$intent = DiscoverProductRanker::detectarIntent($termo, $cluster);
if (!$soHtml) {
    echo "TERMO: {$termo}\n";
    echo "CLUSTER: " . ($cluster ?: '(vazio — não filtra por cluster)') . "\n";
    echo "INTENT: " . json_encode($intent, JSON_UNESCAPED_UNICODE) . "\n\n";
}

if ($intent === null) {
    if (!$soHtml) echo "→ ranker NÃO atua (intent=null). Termo não parece pedir lista de produtos OU cluster fora dos permitidos.\n";
    exit(0);
}

$ranker = new DiscoverProductRanker();
$ret = $ranker->obter($intent);

if (!$soHtml) {
    echo "OBTER: " . ($ret['ok'] ? "ok ({$ret['categoria']}, " . count($ret['produtos']) . " produtos)" : "FALHA: " . ($ret['erro'] ?? '?')) . "\n\n";
}

if (empty($ret['ok'])) exit(2);

if (!$soHtml) {
    echo "═══ PRODUTOS ═══\n";
    foreach ($ret['produtos'] as $i => $p) {
        printf("  %2d. %s\n      preco=%s | rank=%d | url=%s\n",
            $i + 1,
            mb_substr($p['nome'], 0, 80),
            $p['preco_brl'] ?: '—',
            $p['rank'],
            $p['url']
        );
    }
    echo "\n═══ PROMPT CONTEXT (que o Sonnet vai receber) ═══\n";
    echo DiscoverProductRanker::paraPromptContext($ret['produtos'], $ret['categoria']) . "\n";
}

// PrettyLinks só com --site=slug (carrega cfg do site real)
$prettyLinks = null;
if ($siteSlug !== '') {
    require_once __DIR__ . '/../lib/PrettyLinks.php';
    require_once __DIR__ . '/../_site_helper.php';
    $sites = sitesDisponiveis();
    if (isset($sites[$siteSlug])) {
        $cfg = ['wp_url' => '', 'wp_user' => '', 'wp_app_password' => ''];
        aplicarSite($cfg, $sites, $siteSlug);
        if (!empty($cfg['wp_url']) && !empty($cfg['wp_user']) && !empty($cfg['wp_app_password'])) {
            $prettyLinks = new PrettyLinks($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
            if (!$soHtml) echo "→ PrettyLinks ativo no WP {$cfg['wp_url']}\n\n";
        }
    } else {
        fwrite(STDERR, "AVISO: site '{$siteSlug}' não encontrado em sites.php — usando fallback fixo\n");
    }
} elseif (!$soHtml) {
    echo "→ Sem --site=X: tabela vai usar FALLBACK_FIXO (amzn.to/4ckOgUc) em todos os botões\n\n";
}

$html = DiscoverProductRanker::paraTabelaHtml($ret['produtos'], $ret['categoria'], $prettyLinks);

if ($soHtml) {
    echo $html;
    exit(0);
}

echo "\n═══ TABELA HTML (" . strlen($html) . " bytes) ═══\n";
echo substr($html, 0, 800) . "...\n[truncado]\n";

// Teste de substituição
$htmlMock = "<h1>Título</h1>\n<h2>Os melhores</h2>\n<p>Texto qualquer.</p>\n" . DiscoverProductRanker::PLACEHOLDER . "\n<h3>Item 1</h3>";
$sub = DiscoverProductRanker::substituirPlaceholder($htmlMock, $html);
echo "\n═══ SUBSTITUIÇÃO ═══\n";
echo "Método: {$sub['metodo']} | tamanho final: " . strlen($sub['html']) . " bytes\n";

if ($salvar) {
    $path = sys_get_temp_dir() . '/ranker.html';
    @file_put_contents($path, "<!DOCTYPE html>\n<html><head><meta charset='utf-8'><title>{$termo}</title></head><body style='font-family:sans-serif;max-width:900px;margin:20px auto;padding:0 20px'>"
        . "<h1>Preview ranker</h1><p><strong>Termo:</strong> {$termo}</p>"
        . $html
        . "</body></html>");
    echo "\n→ Preview salvo em: {$path}\n";
}
