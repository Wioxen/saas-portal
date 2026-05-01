<?php
/**
 * Adiciona 3 fontes RSS esportivas validadas em data/fontes_pingo.json.
 * Idempotente: pula se já existe (por url_rss exato).
 *
 * Origem: validação 2026-05-01 — DOMDocument retornou 56/23/20 items reais
 * por feed (futebol BR + F1 + Champions + cobertura editorial).
 *
 * Uso (no servidor EasyPanel):
 *   php /app/scripts/_adicionar_fontes_esportes.php
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
        'nome'                  => 'Google News BR — Sports',
        'url_rss'               => 'https://news.google.com/rss/headlines/section/topic/SPORTS?hl=pt-BR&gl=BR&ceid=BR:pt-419',
        'tipo'                  => 'rss',
        'cluster_hint'          => 'esportes',
        'site_target'           => 'leaodabarra',
        'intervalo_min'         => 15,
        'auto_aprovar_score_min'=> 7.0,
        'notas'                 => 'Validado 2026-05-01: 56 items/fetch. Futebol BR + F1 + Champions + Brasileirão. Volume alto, base do leaodabarra.',
    ],
    [
        'nome'                  => 'ESPN BR',
        'url_rss'               => 'https://www.espn.com.br/espn/rss/news',
        'tipo'                  => 'rss',
        'cluster_hint'          => 'esportes',
        'site_target'           => 'leaodabarra',
        'intervalo_min'         => 15,
        'auto_aprovar_score_min'=> 7.0,
        'notas'                 => 'Validado 2026-05-01: 23 items/fetch. Cobertura editorial qualidade alta, mistura BR + internacional + NBA/NFL.',
    ],
    [
        'nome'                  => 'Trivela',
        'url_rss'               => 'https://trivela.com.br/feed/',
        'tipo'                  => 'rss',
        'cluster_hint'          => 'esportes',
        'site_target'           => 'leaodabarra',
        'intervalo_min'         => 30,
        'auto_aprovar_score_min'=> 7.5,
        'notas'                 => 'Validado 2026-05-01: 20 items/fetch. Foco europeu (Champions/Premier League). Threshold mais alto pra evitar canibalização BR-only.',
    ],
];

$existentes = array_column($j['fontes'], 'url_rss');
$next = (int)$j['next_id'];
$adicionadas = 0;
$puladas = 0;

foreach ($novas as $f) {
    if (in_array($f['url_rss'], $existentes, true)) {
        echo "SKIP (já existe): {$f['nome']}\n";
        $puladas++;
        continue;
    }
    $entry = array_merge(['id' => $next, 'ativo' => true], $f, [
        'max_itens_por_fetch' => 30,
    ]);
    $j['fontes'][] = $entry;
    echo sprintf("ADD #%d  %-30s  → %s\n", $next, $f['nome'], $f['site_target']);
    $next++;
    $adicionadas++;
}

$j['next_id'] = $next;

// Atomic write — backup + rename pra evitar fila parar com JSON corrompido se crash
$backup = $file . '.bak';
@copy($file, $backup);
$tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
$json = json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) { fwrite(STDERR, "Falha ao serializar JSON\n"); exit(3); }
if (file_put_contents($tmp, $json, LOCK_EX) === false) { fwrite(STDERR, "Falha gravando {$tmp}\n"); exit(4); }
if (!@rename($tmp, $file)) { @unlink($tmp); fwrite(STDERR, "Falha movendo {$tmp} → {$file}\n"); exit(5); }

echo "\n--- RESUMO ---\n";
echo "Adicionadas: {$adicionadas}\n";
echo "Já existiam: {$puladas}\n";
echo "Total fontes agora: " . count($j['fontes']) . "\n";
echo "Próximo id: {$next}\n";
echo "Backup: {$backup}\n";
