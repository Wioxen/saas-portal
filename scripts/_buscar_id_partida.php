<?php
declare(strict_types=1);

/**
 * _buscar_id_partida.php — descobre partida_id da api-futebol pra um jogo do calendário.
 *
 * Uso:
 *   php scripts/_buscar_id_partida.php --campeonato-id=10 --data=2026-05-09
 *   php scripts/_buscar_id_partida.php --gravar  (atualiza data/jogos_vitoria.json com api_partida_id descoberto)
 *
 * Plano:
 *   1. Carrega data/jogos_vitoria.json
 *   2. Pra cada jogo SEM api_partida_id, tenta buscar via /campeonatos/{id}/rodadas
 *   3. Se --gravar, salva no JSON
 *
 * IDs de campeonato comuns (api-futebol):
 *   10  = Brasileirão Série A
 *   14  = Copa do Brasil
 *   ?   = Copa do Nordeste (descobrir via /campeonatos)
 */

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$gravar = !empty($args['gravar']);
$campId = (int)($args['campeonato-id'] ?? 0);
$dataAlvo = (string)($args['data'] ?? '');
$listarCamp = !empty($args['listar-campeonatos']);

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/ApiFutebol.php';

$apiKey = (string)($cfg['api_futebol_key'] ?? '');
if ($apiKey === '') { fwrite(STDERR, "✗ api_futebol_key não configurada (.env API_FUTEBOL_KEY)\n"); exit(2); }
$vitoriaId = (int)($cfg['api_futebol_vitoria_id'] ?? 50);
$api = new ApiFutebol($apiKey);

if ($listarCamp) {
    echo "→ Listando campeonatos disponíveis\n";
    try {
        $camps = $api->getCampeonatos();
        foreach ($camps as $c) {
            echo sprintf("  %4d  [%s]  %s\n", (int)($c['campeonato_id'] ?? 0), $c['status'] ?? '?', $c['nome_popular'] ?? $c['nome'] ?? '?');
        }
    } catch (Throwable $e) { fwrite(STDERR, "✗ {$e->getMessage()}\n"); exit(1); }
    exit(0);
}

// Modo busca pontual (campeonato-id + data)
if ($campId > 0 && $dataAlvo !== '') {
    echo "→ Buscando partida do Vitória em {$dataAlvo} no campeonato {$campId}\n";
    $pid = $api->buscarPartidaIdPorData($campId, $dataAlvo, $vitoriaId);
    echo $pid ? "  ✓ partida_id={$pid}\n" : "  ✗ não achou\n";
    exit($pid ? 0 : 1);
}

// Modo varredura: percorre data/jogos_vitoria.json
$jsonPath = __DIR__ . '/../data/jogos_vitoria.json';
$db = json_decode((string)file_get_contents($jsonPath), true);
if (!is_array($db) || empty($db['jogos'])) { fwrite(STDERR, "✗ jogos_vitoria.json inválido\n"); exit(2); }

$mapaCompetCamp = [
    'Brasileirão Série A' => 10,
    'Brasileirão' => 10,
    'Copa do Brasil' => 14,
];
$encontrados = 0;
$ja = 0;
foreach ($db['jogos'] as &$j) {
    if (!empty($j['api_partida_id'])) { $ja++; continue; }
    $comp = (string)($j['competicao'] ?? '');
    $campIdJogo = $mapaCompetCamp[$comp] ?? 0;
    if ($campIdJogo === 0) {
        echo "  · skip {$j['id']}: competição '{$comp}' não mapeada\n";
        continue;
    }
    $dt = (string)($j['data'] ?? '');
    if ($dt === '') continue;
    try {
        $pid = $api->buscarPartidaIdPorData($campIdJogo, $dt, $vitoriaId);
        if ($pid) {
            echo "  ✓ {$j['id']} ({$comp} {$dt}) → partida_id={$pid}\n";
            $j['api_partida_id'] = $pid;
            $encontrados++;
        } else {
            echo "  ⊘ {$j['id']} ({$comp} {$dt}) → não achou\n";
        }
    } catch (Throwable $e) {
        echo "  ✗ {$j['id']}: " . $e->getMessage() . "\n";
    }
}
unset($j);

echo "\nResumo: encontrados={$encontrados}, já mapeados={$ja}\n";

if ($gravar && $encontrados > 0) {
    file_put_contents($jsonPath, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "✓ data/jogos_vitoria.json atualizado\n";
} elseif ($encontrados > 0) {
    echo "(use --gravar pra persistir os api_partida_id no JSON)\n";
}
