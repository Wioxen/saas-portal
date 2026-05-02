<?php
/**
 * Adiciona 6 fontes RSS específicas do Esporte Clube Vitória em data/fontes_pingo.json.
 * Idempotente: pula se já existe (por url_rss exato).
 *
 * Pivot leaodabarra 2026-05-02: site virou nicho exclusivo do EC Vitória.
 * Estas fontes complementam UOL Esporte/Itatiaia já existentes — agora o filtro
 * nicho_required_terms (sites.php) garante que trends de outros clubes sejam
 * rejeitados como fora_escopo_nicho.
 *
 * Origem: validação 2026-05-02 — todas as 6 fontes via WebFetch, retornaram
 * 50-150+ itens com conteúdo majoritário sobre EC Vitória. Skipadas: ecvitoria.com.br
 * (feed retornou títulos lixo) e Itatiaia/GauchaZH (regionais MG/RS, esporádicas).
 *
 * Uso (no servidor EasyPanel):
 *   php /app/scripts/_adicionar_fontes_vitoria.php
 *
 * Pode rodar múltiplas vezes sem efeito colateral.
 */

$file = __DIR__ . '/../data/fontes_pingo.json';
if (!is_file($file)) { fwrite(STDERR, "fontes_pingo.json não encontrado em {$file}\n"); exit(1); }

$j = json_decode(file_get_contents($file), true);
if (!is_array($j) || !isset($j['fontes']) || !is_array($j['fontes'])) {
    fwrite(STDERR, "JSON inválido ou sem array 'fontes'\n");
    exit(2);
}

$novas = [
    [
        'nome'                  => 'Bahia Notícias — EC Vitória',
        'url_rss'               => 'https://news.google.com/rss/search?q=site:bahianoticias.com.br+%22Vit%C3%B3ria%22+leao&hl=pt-BR&gl=BR&ceid=BR:pt-419',
        'tipo'                  => 'rss',
        'cluster_hint'          => 'esportes',
        'site_target'           => 'leaodabarra',
        'intervalo_min'         => 15,
        'auto_aprovar_score_min'=> 5.5,
        'notas'                 => 'Validado 2026-05-02: 50 itens, 100% sobre EC Vitória. Cobertura editorial qualidade alta.',
    ],
    [
        'nome'                  => 'BNews — Esporte Vitória',
        'url_rss'               => 'https://news.google.com/rss/search?q=site:bnews.com.br+%22Vit%C3%B3ria%22+leao&hl=pt-BR&gl=BR&ceid=BR:pt-419',
        'tipo'                  => 'rss',
        'cluster_hint'          => 'esportes',
        'site_target'           => 'leaodabarra',
        'intervalo_min'         => 15,
        'auto_aprovar_score_min'=> 5.5,
        'notas'                 => 'Validado 2026-05-02: 72 itens, cobertura ampla Leão+Barradão+Baianão.',
    ],
    [
        'nome'                  => 'Arena Rubro-Negra (fan-site Vitória)',
        'url_rss'               => 'https://news.google.com/rss/search?q=site:arenarubronegra.com&hl=pt-BR&gl=BR&ceid=BR:pt-419',
        'tipo'                  => 'rss',
        'cluster_hint'          => 'esportes',
        'site_target'           => 'leaodabarra',
        'intervalo_min'         => 15,
        'auto_aprovar_score_min'=> 5.5,
        'notas'                 => 'Validado 2026-05-02: 100+ itens, fan-site dedicado, cobertura analítica do dia-a-dia rubro-negro.',
    ],
    [
        'nome'                  => 'MeuVitória (fan-site)',
        'url_rss'               => 'https://news.google.com/rss/search?q=site:meuvitoria.com.br&hl=pt-BR&gl=BR&ceid=BR:pt-419',
        'tipo'                  => 'rss',
        'cluster_hint'          => 'esportes',
        'site_target'           => 'leaodabarra',
        'intervalo_min'         => 15,
        'auto_aprovar_score_min'=> 5.5,
        'notas'                 => 'Validado 2026-05-02: 150+ itens, fan-site com mercado e bastidores.',
    ],
    [
        'nome'                  => 'Correio 24h — EC Vitória',
        'url_rss'               => 'https://news.google.com/rss/search?q=site:correio24horas.com.br+%22Vit%C3%B3ria%22+rubro-negro&hl=pt-BR&gl=BR&ceid=BR:pt-419',
        'tipo'                  => 'rss',
        'cluster_hint'          => 'esportes',
        'site_target'           => 'leaodabarra',
        'intervalo_min'         => 15,
        'auto_aprovar_score_min'=> 5.5,
        'notas'                 => 'Validado 2026-05-02: 50 itens, jornal Salvador, 100% Vitória com filtro rubro-negro.',
    ],
    [
        'nome'                  => 'Terra — Esporte Clube Vitória',
        'url_rss'               => 'https://news.google.com/rss/search?q=site:terra.com.br+%22Esporte+Clube+Vit%C3%B3ria%22&hl=pt-BR&gl=BR&ceid=BR:pt-419',
        'tipo'                  => 'rss',
        'cluster_hint'          => 'esportes',
        'site_target'           => 'leaodabarra',
        'intervalo_min'         => 30,
        'auto_aprovar_score_min'=> 5.5,
        'notas'                 => 'Validado 2026-05-02: 50 itens, ~90% Vitória com filtro discriminante Esporte Clube Vitória. Cobertura nacional.',
    ],
];

$existentes = array_column($j['fontes'], 'url_rss');
$next = (int)($j['next_id'] ?? 100);
$adicionadas = 0;
$puladas = 0;

foreach ($novas as $f) {
    if (in_array($f['url_rss'], $existentes, true)) {
        echo "SKIP (já existe): {$f['nome']}\n";
        $puladas++;
        continue;
    }
    $f = ['id' => $next, 'ativo' => true] + $f + ['max_itens_por_fetch' => 30];
    $j['fontes'][] = $f;
    $next++;
    $adicionadas++;
    echo "ADD #{$f['id']}: {$f['nome']}\n";
}

$j['next_id'] = $next;
file_put_contents($file, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "\n--- RESUMO ---\n";
echo "Adicionadas: {$adicionadas}\n";
echo "Já existiam: {$puladas}\n";
echo "Total fontes agora: " . count($j['fontes']) . "\n";
echo "Próximo id: {$next}\n";
