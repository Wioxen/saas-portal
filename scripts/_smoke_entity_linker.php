<?php
/**
 * Smoke test do EntityPageLinker contra produção (cursosenacgratuito.com.br).
 *
 * Read-only: só lista entity pages via REST e testa injeção em HTML mock local.
 * NÃO escreve em nada na produção.
 *
 * Uso:
 *   php scripts/_smoke_entity_linker.php
 */

declare(strict_types=1);

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/EntityPageLinker.php';

aplicarSite($cfg, sitesDisponiveis(), 'cursosenac');

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Smoke EntityPageLinker — site=cursosenac (READ-ONLY)\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

// 1. Lista pages do parent /entidade/ (smoke aceita drafts pra testar com pilotos)
echo "→ Carregando pages filhas de /entidade/ via REST (publish+draft)...\n";
$pagesEntidade = $wp->listarEntityPages('entidade', 100, 'publish,draft');
echo "   " . count($pagesEntidade) . " pages encontradas.\n";
foreach ($pagesEntidade as $p) {
    echo "   · #{$p['id']} [{$p['slug']}] {$p['title']}\n";
}
echo "\n";

// 2. Lista pages do parent /conceito/
echo "→ Carregando pages filhas de /conceito/ via REST (publish+draft)...\n";
$pagesConceito = $wp->listarEntityPages('conceito', 100, 'publish,draft');
echo "   " . count($pagesConceito) . " pages encontradas.\n";
foreach ($pagesConceito as $p) {
    echo "   · #{$p['id']} [{$p['slug']}] {$p['title']}\n";
}
echo "\n";

if (empty($pagesEntidade) && empty($pagesConceito)) {
    echo "⚠ Nenhuma entity/concept page encontrada. Crie via piloto antes.\n";
    exit(0);
}

// 3. Casos de teste — HTML mock variando contexto + posicionamento dos termos
$casos = [
    'caso_1_sigla_pura' => [
        'desc' => '1) Sigla pura em <p> simples',
        'html' => '<p>O IFSP abriu novas vagas para 2026 em diversos campi do estado.</p><p>Inscrições até 30/04.</p>',
    ],
    'caso_2_strong_ja_negrito' => [
        'desc' => '2) Sigla já dentro de <strong> (deve virar <a><strong>)',
        'html' => '<p>O <strong>IFSP</strong> publicou edital com 240 vagas. Veja como participar.</p>',
    ],
    'caso_3_h2_pula' => [
        'desc' => '3) Sigla em h2 deve ser pulada (não linka títulos)',
        'html' => "<h2>Edital IFSP 2026 abre inscrições</h2>\n<p>O processo seletivo do Senac também começou nesta semana.</p>",
    ],
    'caso_4_dentro_de_link' => [
        'desc' => '4) Sigla dentro de <a> existente deve ser pulada',
        'html' => '<p>Veja a <a href="https://example.com">cobertura completa do IFSP</a> deste ano.</p><p>O MEC publicou nova portaria sobre vestibular.</p>',
    ],
    'caso_5_fullname' => [
        'desc' => '5) Match por fullname (sem sigla disponível)',
        'html' => '<p>O Ministério da Educação anunciou novos investimentos em educação técnica.</p>',
    ],
    'caso_6_classes_protegidas' => [
        'desc' => '6) Classes RD/snippet/leia-mais são protegidas',
        'html' => '<p class="resposta-direta">O IFSP é o Instituto Federal de São Paulo, criado em 2008.</p>'
                . '<p>O IFSP aceita inscrições anuais.</p>',
    ],
    'caso_7_multiplas_entidades' => [
        'desc' => '7) Múltiplas entidades — limita a maxLinks',
        'html' => '<p>O IFSP, em parceria com o Senac e o MEC, publicou novo edital. O Inep também participou da consulta. O Fundeb financiou parte do programa.</p>',
    ],
    'caso_8_url_ja_no_html' => [
        'desc' => '8) URL da entity page já presente — não duplica',
        'html' => '<p>Saiba mais sobre o IFSP em <a href="https://cursosenacgratuito.com.br/entidade/ifsp/">nosso guia completo</a>.</p>'
                . '<p>O IFSP também publicou edital novo.</p>',
    ],
    'caso_9_concept_ead' => [
        'desc' => '9) Concept page — match em "EAD" ou "ensino a distância"',
        'html' => '<p>Os cursos EAD do Senac cresceram 40% no último ano. A modalidade ensino a distância já cobre todas as regiões.</p>',
    ],
];

// Limpa caches do EntityPageLinker pra forçar reload com status drafts
$cacheDir = __DIR__ . '/../data/entity_pages_cache';
foreach (glob($cacheDir . '/cursosenac_*.json') ?: [] as $f) {
    if (basename($f) !== 'cursosenac_aliases.json') @unlink($f);
}

foreach ($casos as $key => $caso) {
    echo "─── {$caso['desc']} ───\n";
    $linker = new EntityPageLinker($wp, 'cursosenac', ['entidade', 'conceito'], 2, 'publish,draft');
    $r = $linker->injetar($caso['html']);
    echo "INPUT  : " . $caso['html'] . "\n";
    echo "OUTPUT : " . $r['html'] . "\n";
    echo "Aplicados: {$r['aplicados']}";
    if (!empty($r['destinos'])) {
        echo " | destinos: ";
        foreach ($r['destinos'] as $d) echo "[{$d['termo']} → #{$d['page_id']}] ";
    }
    echo "\n\n";
}

echo "═══ Smoke completo. Inspecione visualmente os OUTPUTs acima. ═══\n";
