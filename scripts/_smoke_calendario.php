<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/JogosCalendario.php';

$cal = new JogosCalendario(__DIR__ . '/../data/jogos_vitoria.json');

echo "=== JogosCalendario smoke ===\n\n";
echo "Total jogos no JSON: " . count($cal->jogos()) . "\n\n";

$proximo = $cal->proximoJogo();
if ($proximo) {
    echo "PRÓXIMO JOGO:\n";
    echo "  · {$proximo['data']} {$proximo['hora']} ({$proximo['timezone']})\n";
    echo "  · {$proximo['mando']} vs {$proximo['adversario']['nome']} [{$proximo['adversario']['sigla']}]\n";
    echo "  · estádio: {$proximo['estadio']} · status: {$proximo['status']}\n";
    $h = $cal->horasAteProximoJogo();
    echo "  · faltam: " . round($h, 1) . "h\n\n";
} else {
    echo "Sem próximo jogo agendado.\n\n";
}

$ultimo = $cal->ultimoJogo();
if ($ultimo) {
    echo "ÚLTIMO JOGO:\n";
    echo "  · {$ultimo['data']} {$ultimo['hora']}\n";
    echo "  · {$ultimo['mando']} vs {$ultimo['adversario']['nome']} · status: {$ultimo['status']}\n";
    if ($ultimo['status'] === 'finalizado') {
        echo "  · placar: Vitória {$ultimo['placar']['vitoria']} x {$ultimo['placar']['adversario']} {$ultimo['adversario']['nome']}\n";
    }
    echo "\n";
} else {
    echo "Sem último jogo finalizado.\n\n";
}

$janela = $cal->janelaAtual();
if ($janela) {
    echo "JANELA ATUAL: " . strtoupper($janela['tipo']) . " ({$janela['jogo']['adversario']['nome']})\n";
} else {
    echo "FORA DE QUALQUER JANELA DE JOGO.\n";
}

$cad = $cal->cadenciaPingoMinutos(15);
echo "Cadência sugerida do Pingo: {$cad} min\n\n";

echo "PRÓXIMOS 5 JOGOS:\n";
foreach ($cal->proximosJogos(5) as $j) {
    echo "  · {$j['data']} {$j['hora']} — {$j['mando']} vs {$j['adversario']['nome']} ({$j['estadio']})\n";
}
