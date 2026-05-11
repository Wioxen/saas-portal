<?php
declare(strict_types=1);
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/GoogleIndexingApi.php';
$cfg = require __DIR__ . '/../config.php';
aplicarSite($cfg, sitesDisponiveis(), 'leaodabarra');
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

$p = $wp->getPost(1183);
$h = $p['content']['raw'] ?? '';

// Remove TODAS as tentativas anteriores (h2 Assista, p descritivo, div video, iframe)
$h = preg_replace('|<h2>Assista[^<]*</h2>|', '', $h) ?? $h;
$h = preg_replace('|<p>O lance que viralizou[^<]*</p>|', '', $h) ?? $h;
$h = preg_replace('|<p>O lance da chance perdida[^<]*</p>|', '', $h) ?? $h;
$h = preg_replace('|<div class=[\'"]video-highlights[\'"][^>]*>.*?</div>|s', '', $h) ?? $h;
$h = preg_replace('|<iframe[^>]*youtube[^>]*></iframe>|s', '', $h) ?? $h;
// Caso ainda haja remanescentes com u0027 escapado:
$h = preg_replace('|<div [^>]*\\\\u0027[^>]*>.*?</div>|s', '', $h) ?? $h;

// Injeta UM bloco com aspas duplas reais
$embed = "\n<h2>Assista aos melhores momentos</h2>\n"
    . "<p>O lance que viralizou aconteceu no segundo tempo do empate entre Fluminense e Vitória, no Maracanã. Veja os melhores momentos da partida pelo Brasileirão:</p>\n"
    . '<div class="video-highlights" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;margin:20px 0;">'
    . '<iframe src="https://www.youtube.com/embed/bmRVCG5Egfs" '
    . 'style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;" '
    . 'frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
    . 'allowfullscreen title="Fluminense 2x2 Vitória — melhores momentos"></iframe>'
    . "</div>\n";

// Injeta após o primeiro </p>
$h = preg_replace('|(</p>)|', '$1' . $embed, $h, 1) ?? $h;

$wp->atualizarPost(1183, ['content' => $h]);
echo "#1183 limpo + 1 embed final\n";
echo "iframes restantes: " . substr_count($h, '<iframe') . "\n";
echo "u0027 literal: " . substr_count($h, 'u0027') . "\n";

$idx = new GoogleIndexingApi(__DIR__ . '/../data/credentials/google-indexing.json');
$r = $idx->notifyUrl($p['link'], 'URL_UPDATED');
echo "Indexing API: HTTP " . ($r['http_code'] ?? '?') . "\n";
