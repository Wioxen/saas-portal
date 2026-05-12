<?php
declare(strict_types=1);
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

$alvos = [
    'leaodabarra' => [
        1120 => ['cat' => 'Brasileirão 2026', 'tags' => ['Esporte Clube Vitória', 'Fluminense', 'Brasileirão']],
        1169 => ['cat' => 'Brasileirão 2026', 'tags' => ['Esporte Clube Vitória', 'Fluminense', 'Jair Ventura', 'Renato Kayzer']],
        1110 => ['cat' => 'Copa do Brasil', 'tags' => ['Esporte Clube Vitória', 'Flamengo']],
        1209 => ['cat' => 'Brasileirão 2026', 'tags' => ['Esporte Clube Vitória', 'Bragantino']],
        1214 => ['cat' => 'Copa do Brasil', 'tags' => ['Esporte Clube Vitória', 'Flamengo', 'Jair Ventura', 'Arbitragem']],
        1218 => ['cat' => 'Copa do Brasil', 'tags' => ['Esporte Clube Vitória', 'Flamengo', 'Jair Ventura']],
        1124 => ['cat' => 'Brasileirão 2026', 'tags' => ['Esporte Clube Vitória', 'STJD', 'CBF', 'Fábio Mota']],
        1128 => ['cat' => 'Copa do Nordeste', 'tags' => ['Esporte Clube Vitória', 'Ceará', 'Arbitragem']],
        1132 => ['cat' => 'Mercado da Bola', 'tags' => ['Esporte Clube Vitória', 'Grêmio', 'Wagner Leonardo']],
        1136 => ['cat' => 'Copa do Nordeste', 'tags' => ['Esporte Clube Vitória', 'CBF']],
        1175 => ['cat' => 'Brasileirão 2026', 'tags' => ['Esporte Clube Vitória', 'Bragantino']],
        1179 => ['cat' => 'Brasileirão 2026', 'tags' => ['Esporte Clube Vitória', 'Bragantino']],
        1183 => ['cat' => 'Brasileirão 2026', 'tags' => ['Esporte Clube Vitória', 'Fluminense']],
    ],
    'comocomprar' => [
        3128 => ['cat' => 'Câmeras e Vigilância', 'tags' => ['Yoosee', 'Wi-Fi', 'Amazon', 'Ofertas', 'Casa Inteligente']],
        3132 => ['cat' => 'Casa Inteligente', 'tags' => ['Positivo', 'Wi-Fi', 'Alexa', 'Amazon', 'Ofertas']],
    ],
    'cursosenac' => [
        5590 => ['cat' => 'Cursos Gratuitos', 'tags' => ['MEC', 'Lula', 'Gratuito', 'Acessibilidade']],
        5594 => ['cat' => 'Bolsas e Financiamento', 'tags' => ['UFPB', 'FURG', 'UAB', 'CAPES', 'Bolsa']],
        5709 => ['cat' => 'Vagas de Emprego', 'tags' => ['São Paulo', 'CATe']],
        5735 => ['cat' => 'Pós-graduação', 'tags' => ['IFSP', 'EAD', 'Inscrições Abertas']],
    ],
];

$totUpd = 0;
foreach ($alvos as $slug => $posts) {
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $slug);
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);
    $cm = new CategoryMatcher($wp, 70.0);
    echo "\n=== $slug ===\n";
    foreach ($posts as $pid => $info) {
        try {
            $rc = $cm->resolverComMatch([$info['cat']]);
            $catIds = array_values(array_filter(array_map('intval', $rc)));
            $tagIds = $wp->resolverTags($info['tags']);
            $wp->atualizarPost($pid, ['categories' => $catIds, 'tags' => $tagIds]);
            echo sprintf("  #%d cat=%s tags=%s\n", $pid, implode(',', $catIds), implode(',', $tagIds));
            $totUpd++;
        } catch (Throwable $e) {
            echo "  #$pid err: " . $e->getMessage() . "\n";
        }
    }
}
echo "\nTotal atualizado: $totUpd\n";
