<?php
/**
 * tests/afiliados.php
 *
 * 10 casos-ouro verificando o matchmaker contra o catálogo
 * de data/afiliados.json (seeds padrão).
 *
 * Runner: /c/xampp/php/php.exe tests/afiliados.php
 * Exit 0 = tudo passou · 1 = falhas.
 */

require_once __DIR__ . '/../lib/DiscoverAfiliados.php';

$casos = [
    // id, trend, slug_esperado (ou null se não deve matchear)
    ['T1', 'concurso INSS 2026', 'noticias_info_critica', 'oportunidade', ['edital','vagas'], 'curso-concurso-publico'],
    ['T2', 'recall fiat toro', 'automoveis', 'medo', ['cnh','carro'], 'seguro-auto-cotacao'],
    ['T3', 'passagem aérea argentina promoção', 'viagem_transporte', 'oportunidade', ['voo','destino'], 'passagem-aerea-decolar'],
    ['T4', 'inss 13 salário aposentado', 'negocios_financas', 'dinheiro', ['fgts','crédito'], 'emprestimo-consignado'],
    ['T5', 'flamengo libertadores', 'esportes', 'nenhuma', ['jogo'], null],                // esporte: sem oferta → null
    ['T6', 'receita de bolo caseiro', 'comidas_bebidas', 'nenhuma', [], null],              // comida: sem oferta cadastrada → null
    ['T7', 'iphone 17 preço comprar', 'tecnologia', 'oportunidade', ['apple','comprar'], 'amazon-geral'], // amazon via keyword 'comprar' + fallback cluster curiosidades
    ['T8', 'enem 2026 isenção prazo', 'noticias_info_critica', 'urgencia', ['inscrição'], 'curso-concurso-publico'],
    ['T9', 'bbb 26 eliminação', 'entretenimento_cultura', 'nenhuma', [], null],              // entretenimento: sem oferta → null
    ['T10', 'crédito pessoal empréstimo', 'negocios_financas', 'dinheiro', ['consignado','taxa'], 'emprestimo-consignado'],
];

$total = count($casos);
$passaram = 0;
$falhas = [];

foreach ($casos as $c) {
    [$id, $termo, $clusterKey, $dor, $relacionados, $esperado] = $c;
    $res = DiscoverAfiliados::matchear([
        'termo' => $termo,
        'cluster_detect' => ['key' => $clusterKey],
        'pain' => ['dominante' => $dor],
        'relacionados' => $relacionados,
    ]);
    $obtido = $res['oferta']['slug'] ?? null;

    if ($obtido === $esperado) {
        $passaram++;
    } else {
        $falhas[] = [
            'id'       => $id,
            'termo'    => $termo,
            'cluster'  => $clusterKey,
            'esperado' => $esperado ?? '(null)',
            'obtido'   => $obtido ?? '(null)',
            'score'    => $res['score'] ?? 0,
        ];
    }
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  tests/afiliados.php — {$total} casos\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

if ($falhas) {
    echo "═══ FALHAS ═══════════════════════════════════════════════════\n";
    foreach ($falhas as $f) {
        echo sprintf(
            "  [%s] %s (cluster=%s)\n    esperado: %s\n    obtido:   %s (score=%d)\n\n",
            $f['id'], $f['termo'], $f['cluster'], $f['esperado'], $f['obtido'], $f['score']
        );
    }
}

echo "─── Resumo ─────────────────────────────────────────────────────\n";
echo sprintf("  Passaram: %d / %d (%.1f%%)\n", $passaram, $total, ($passaram / $total) * 100);
echo sprintf("  Falharam: %d\n\n", count($falhas));

if ($passaram === $total) {
    echo "  ✓ Todos os casos passaram.\n";
    exit(0);
}
echo "  ✗ " . count($falhas) . " casos falharam.\n";
exit(1);
