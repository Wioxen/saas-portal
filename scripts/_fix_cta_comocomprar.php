<?php
declare(strict_types=1);
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/GoogleIndexingApi.php';

$cfg = require __DIR__ . '/../config.php';
aplicarSite($cfg, sitesDisponiveis(), 'comocomprar');
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

$afUrl = htmlspecialchars((string)$cfg['amazon_affiliate_url'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

$ctaMeio = "\n<div class='cta-afiliado' style='text-align:center;margin:32px 0;padding:24px;background:#fff8e7;border:2px dashed #ff9900;border-radius:8px;'>"
    . "<p style='margin:0 0 14px;font-size:16px;color:#333;'><strong>Encontrou o produto certo?</strong></p>"
    . "<a href='{$afUrl}' target='_blank' rel='nofollow sponsored noopener' style='display:inline-block;background:#ff9900;color:#fff;font-weight:bold;font-size:17px;padding:14px 28px;border-radius:6px;text-decoration:none;letter-spacing:0.3px;'>🛒 Veja a oferta na Amazon</a>"
    . "<p style='margin:12px 0 0;font-size:12px;color:#888;'>Link de afiliado — apoia o portal sem custo adicional pra você</p>"
    . "</div>\n";

$ctaFim = "\n<div class='cta-afiliado cta-fim' style='text-align:center;margin:32px 0;padding:20px;background:#fff8e7;border:2px solid #ff9900;border-radius:8px;'>"
    . "<a href='{$afUrl}' target='_blank' rel='nofollow sponsored noopener' style='display:inline-block;background:#ff9900;color:#fff;font-weight:bold;font-size:18px;padding:16px 32px;border-radius:6px;text-decoration:none;'>🛒 Comprar agora na Amazon</a></div>\n";

foreach ([3128, 3132] as $pid) {
    try {
        $p = $wp->getPost($pid);
        $h = $p['content']['raw'] ?? '';
        // Verifica se ja tem CTA
        if (strpos($h, "cta-afiliado") !== false) {
            echo "#$pid já tem CTA, skip\n";
            continue;
        }
        // Injeta CTA no meio: após o 2º </h2>
        $h2Count = 0;
        $h2 = preg_replace_callback(
            '|</h2>|',
            function ($m) use (&$h2Count, $ctaMeio) {
                $h2Count++;
                return $m[0] . ($h2Count === 2 ? $ctaMeio : '');
            },
            $h,
            2
        ) ?? $h;
        // Adiciona CTA no fim
        $h2 .= $ctaFim;
        $wp->atualizarPost($pid, ['content' => $h2]);
        echo "#$pid CTA injetado (meio + fim)\n";
        // Indexing API ping
        $idx = new GoogleIndexingApi(__DIR__ . '/../data/credentials/google-indexing.json');
        $r = $idx->notifyUrl($p['link'], 'URL_UPDATED');
        echo "  Indexing: HTTP " . ($r['http_code'] ?? '?') . "\n";
    } catch (Throwable $e) {
        echo "#$pid err: " . $e->getMessage() . "\n";
    }
}
