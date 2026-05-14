<?php
declare(strict_types=1);
/**
 * Remove atribuição a portais jornalísticos dos 4 posts publicados hoje.
 * Regra editorial: autoria = redação do site; scrape alimenta brain, não
 * vira menção no texto final. Fonte primária INSTITUCIONAL (gov.br/Inep/
 * Alesp/Semob/CECIERJ) pode permanecer; portal derivativo (g1, A Tarde,
 * Metrópoles, Hora Brasil) é removido.
 *
 * Também limpa schema NewsArticle: remove "citation" pra outros portais.
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

$alvos = [
    'guiadoscursos'    => [3761, 3764],
    'leaodabarra'      => [1677],
    'vagasebeneficios' => [2713],
];

function limparAtribuicao(string $html): string {
    // 1) Remove frases ", conforme apurou a redação a partir de matéria do X."
    //    (variantes: "apurado pela redação a partir do/da/de X")
    $patterns = [
        '/,\s*conforme\s+apurou\s+a\s+redação\s+a\s+partir\s+(?:de\s+matéria\s+(?:do|de\s+))?[^.]+?(?=\.)/iu',
        '/,\s*conforme\s+apurado\s+pela\s+redação\s+a\s+partir\s+(?:do|de\s+|de\s+matéria\s+(?:do|de\s+))?[^.]+?(?=\.)/iu',
        '/,\s*segundo\s+(?:o\s+|a\s+)?(?:portal|matéria|reportagem)\s+(?:do|da|de)\s+[A-ZÀ-Úa-zà-ú\s]+(?=\.|,)/u',
    ];
    foreach ($patterns as $p) {
        $html = preg_replace($p, '', $html);
    }

    // 2) Remove parágrafo final "<em>Fonte: ...</em>"
    //    Mas SE mencionar entidade institucional (Inep, Alesp, Semob, MEC,
    //    CECIERJ, gov.br) — reescreve mantendo só a parte institucional.
    $html = preg_replace_callback(
        '#<p>\s*<em>\s*Fonte\s*:\s*([^<]+?)\s*(?:<a[^>]*>[^<]*</a>\.?)?\s*</em>\s*</p>#iu',
        function ($m) {
            $texto = $m[1];
            // Detecta menção institucional preservável
            $instituicoes = [
                'Inep'              => 'Instituto Nacional de Estudos e Pesquisas Educacionais Anísio Teixeira (Inep)',
                'Alesp'             => 'Assembleia Legislativa do Estado de São Paulo (Alesp)',
                'Semob'             => 'Secretaria de Mobilidade de Salvador (Semob)',
                'CECIERJ'           => 'Fundação CECIERJ / Consórcio CEDERJ',
                'MEC'               => 'Ministério da Educação (MEC)',
            ];
            foreach ($instituicoes as $sigla => $nome) {
                if (str_contains($texto, $sigla)) {
                    return ''; // remove o "Fonte:" — a entidade já foi citada no corpo
                }
            }
            return ''; // sem menção institucional → remove totalmente
        },
        $html
    );

    // 3) Limpa espaços duplos e vírgulas órfãs que possam ter sobrado
    $html = preg_replace('/\s+,/u', ',', $html);
    $html = preg_replace('/,\s*\./u', '.', $html);
    $html = preg_replace('/  +/', ' ', $html);

    // 4) No JSON-LD: remove a chave "citation" inteira (com seu valor objeto/array)
    //    e adiciona "author" = "Equipe editorial do {site}"
    $html = preg_replace(
        '/,\s*"citation"\s*:\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/u',
        '',
        $html
    );

    return $html;
}

foreach ($alvos as $slugSite => $ids) {
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $slugSite);
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

    echo "\n══ {$slugSite} ══\n";
    foreach ($ids as $pid) {
        try {
            $p = $wp->getPost($pid);
            $raw = (string)($p['content']['raw'] ?? '');
            if ($raw === '') { echo "  #{$pid}: vazio\n"; continue; }
            $novo = limparAtribuicao($raw);
            if ($novo === $raw) {
                echo "  ✓ #{$pid}: já limpo\n";
                continue;
            }
            $r = $wp->atualizarPost($pid, ['content' => $novo]);
            $diff = strlen($raw) - strlen($novo);
            echo "  ✅ #{$pid}: -{$diff} chars (status: " . ($r['status'] ?? '?') . ")\n";
        } catch (Throwable $e) {
            echo "  ❌ #{$pid}: " . $e->getMessage() . "\n";
        }
    }
}
