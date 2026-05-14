<?php
declare(strict_types=1);
/**
 * Lista os top trends FIDEDIGNOS de hoje por site.
 *
 * Critérios:
 *   1. data_detectada >= CURDATE() (ou últimas 24h)
 *   2. status IN ('aprovado','novo','em_fila_geracao')
 *   3. ativo=1
 *   4. score_discover >= 5.0
 *   5. fonte de alta autoridade — pingo_link contém domínio Tier S/A
 *      Tier S: g1.globo, agenciabrasil.ebc, gov.br, mec.gov.br, estadao,
 *              folha, valor.globo, exame, cnnbrasil, atarde, ifma.edu, ifsp,
 *              uerj, uece, ufrj, unicamp, usp, mil.br, .leg.br
 *      Tier A: bnews, correio24horas, arenarubronegra, ge.globo,
 *              olhardigital, tecnoblog, techtudo, terra.com.br
 *   6. EXCLUI Google News RSS aggregator (news.google.com/rss/articles) —
 *      autoridade depende do destino, não do redirector
 *
 * Output ranqueado por site com score + fonte + categoria + título.
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DbConnection.php';
date_default_timezone_set('America/Sao_Paulo');
$pdo = DbConnection::pdo();

// Lista de domínios Tier S/A (alta autoridade)
$dominiosTierS = [
    'g1.globo.com', 'globo.com', 'agenciabrasil.ebc.com.br',
    'gov.br', 'mec.gov.br', 'estadao.com.br', 'folha.uol.com.br',
    'valor.globo.com', 'exame.com', 'cnnbrasil.com.br', 'atarde.com.br',
    'ifma.edu.br', 'ifsp.edu.br', 'iff.edu.br', 'ifrn.edu.br',
    'uerj.br', 'uece.br', 'ufrj.br', 'unicamp.br', 'usp.br', 'urca.br',
    '.mil.br', '.leg.br', 'inep.gov.br', 'fazenda.gov.br',
];
$dominiosTierA = [
    'bnews.com.br', 'correio24horas.com.br', 'arenarubronegra.com',
    'meuvitoria.com.br', 'ge.globo.com', 'olhardigital.com.br',
    'tecnoblog.net', 'techtudo.com.br', 'terra.com.br',
    'horabrasil.com.br', 'conectaprofessores.com', 'metropoles.com',
    'oglobo.globo.com', 'r7.com', 'uol.com.br/esporte',
    'aloalobahia.com', 'jornalcorreio.com.br',
];

$padroesTierS = '(' . implode('|', array_map('preg_quote', $dominiosTierS)) . ')';
$padroesTierA = '(' . implode('|', array_map('preg_quote', $dominiosTierA)) . ')';

$sites = ['vagasebeneficios', 'cursosenac', 'guiadoscursos', 'comocomprar', 'ondecompraragora', 'leaodabarra'];

foreach ($sites as $site) {
    echo "\n══════════════════ {$site} ══════════════════\n";
    $st = $pdo->prepare("
        SELECT id, status, score_discover, origem, data_detectada, pingo_link,
               SUBSTRING(titulo, 1, 160) titulo, categoria
        FROM trends
        WHERE site = ?
          AND status IN ('aprovado','novo','em_fila_geracao')
          AND ativo = 1
          AND data_detectada >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          AND score_discover >= 5.0
          AND pingo_link IS NOT NULL
          AND pingo_link != ''
          AND pingo_link NOT LIKE 'https://news.google.com/rss/articles/%'
        ORDER BY score_discover DESC, data_detectada DESC
        LIMIT 40
    ");
    $st->execute([$site]);

    $tierS = [];
    $tierA = [];
    $tierBC = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $url = $r['pingo_link'];
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $tier = 'C';
        foreach ($dominiosTierS as $dom) {
            if (str_contains($host, $dom)) { $tier = 'S'; break; }
        }
        if ($tier === 'C') {
            foreach ($dominiosTierA as $dom) {
                if (str_contains($host, $dom) || str_contains($url, $dom)) { $tier = 'A'; break; }
            }
        }
        $r['_tier'] = $tier;
        $r['_host'] = $host;
        if ($tier === 'S')      $tierS[] = $r;
        elseif ($tier === 'A')  $tierA[] = $r;
        else                    $tierBC[] = $r;
    }

    $listar = function (array $rows, string $label) {
        if (empty($rows)) return;
        echo "\n  ─── {$label} ───\n";
        foreach (array_slice($rows, 0, 6) as $r) {
            echo "  #{$r['id']} | s={$r['score_discover']} | {$r['status']} | {$r['_host']}\n";
            echo "     {$r['titulo']}\n";
            echo "     URL: " . substr($r['pingo_link'], 0, 130) . "\n";
        }
    };

    $listar($tierS, "TIER S (top autoridade): " . count($tierS));
    $listar($tierA, "TIER A (alta autoridade): " . count($tierA));
    if (!empty($tierBC)) echo "\n  (Tier C/sem categorização: " . count($tierBC) . " trends ignoradas)\n";
}
