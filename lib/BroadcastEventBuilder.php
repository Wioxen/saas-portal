<?php
declare(strict_types=1);

/**
 * BroadcastEventBuilder — monta Schema.org BroadcastEvent pra livestreams futuras.
 *
 * BroadcastEvent é 1 dos 2 tipos OFICIAIS aceitos pelo Google Indexing API
 * (outro é JobPosting). Indexação típica: minutos vs dias do crawl normal.
 * Ideal pra pré-jogos do EC Vitória (cada partida = livestream futura).
 *
 * Documentação Google: https://developers.google.com/search/docs/appearance/structured-data/broadcast-event
 *
 * Uso:
 *   $b = new BroadcastEventBuilder();
 *   $schema = $b->montar($jogoArr);
 *   $html .= $b->renderizarScript($schema);
 */
class BroadcastEventBuilder
{
    /**
     * Monta schema BroadcastEvent a partir de array de jogo (formato jogos_vitoria.json).
     *
     * @param array $jogo Array com keys: id, data, hora, timezone, competicao, mando,
     *                    adversario [nome,sigla], estadio, transmissao
     * @param array $opts ['post_url'?, 'site_name'?, 'duracao_minutos'?]
     */
    public function montar(array $jogo, array $opts = []): array
    {
        $tz = $jogo['timezone'] ?? 'America/Sao_Paulo';
        $dataStr = ($jogo['data'] ?? '') . ' ' . ($jogo['hora'] ?? '20:00') . ':00';

        try {
            $startDt = new DateTime($dataStr, new DateTimeZone($tz));
        } catch (Throwable $e) {
            throw new RuntimeException("Data/hora inválida no jogo: {$dataStr}");
        }
        $duracaoMin = (int)($opts['duracao_minutos'] ?? 130); // 90 + intervalo + acréscimos
        $endDt = (clone $startDt)->modify("+{$duracaoMin} minutes");

        $startDate = $startDt->format('c');
        $endDate = $endDt->format('c');

        $advNome = $jogo['adversario']['nome'] ?? 'Adversário';
        $mandoCasa = ($jogo['mando'] ?? '') === 'casa';
        $clubeCasa = $mandoCasa ? 'Esporte Clube Vitória' : $advNome;
        $clubeFora = $mandoCasa ? $advNome : 'Esporte Clube Vitória';
        $partidaNome = "{$clubeCasa} x {$clubeFora}";
        $estadio = $jogo['estadio'] ?? ($mandoCasa ? 'Barradão' : 'Estádio do adversário');
        $competicao = $jogo['competicao'] ?? 'Brasileirão Série A';

        $eventoEsportivo = [
            '@type' => 'SportsEvent',
            'name' => "{$partidaNome} — {$competicao}",
            'startDate' => $startDate,
            'endDate' => $endDate,
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/MixedEventAttendanceMode',
            'location' => [
                '@type' => 'StadiumOrArena',
                'name' => $estadio,
                'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'BR'],
            ],
            'homeTeam' => ['@type' => 'SportsTeam', 'name' => $clubeCasa],
            'awayTeam' => ['@type' => 'SportsTeam', 'name' => $clubeFora],
            'sport' => 'Football (Soccer)',
        ];

        $broadcast = [
            '@context' => 'https://schema.org',
            '@type' => 'BroadcastEvent',
            'name' => "Live: {$partidaNome} ao vivo — {$competicao}",
            'description' => "Acompanhe a transmissão ao vivo de {$partidaNome} pelo {$competicao}, no {$estadio}, em " . $startDt->format('d/m/Y \à\s H:i') . " (horário de Brasília).",
            'startDate' => $startDate,
            'endDate' => $endDate,
            'isLiveBroadcast' => true,
            'videoFormat' => 'HD',
            'inLanguage' => 'pt-BR',
            'broadcastOfEvent' => $eventoEsportivo,
        ];

        // publishedOn: canais que vão transmitir (se houver)
        if (!empty($jogo['transmissao'])) {
            $tvs = is_array($jogo['transmissao']) ? $jogo['transmissao'] : [$jogo['transmissao']];
            $publishedOn = [];
            foreach ($tvs as $tv) {
                $publishedOn[] = [
                    '@type' => 'BroadcastService',
                    'name' => (string)$tv,
                    'broadcastDisplayName' => (string)$tv,
                ];
            }
            if (!empty($publishedOn)) $broadcast['publishedOn'] = $publishedOn;
        }

        // URL do post canônico
        if (!empty($opts['post_url'])) {
            $broadcast['url'] = (string)$opts['post_url'];
        }

        return $broadcast;
    }

    /**
     * Renderiza o script JSON-LD pra injetar no HTML do post.
     */
    public function renderizarScript(array $schema): string
    {
        return "\n<script type=\"application/ld+json\" data-broadcast-event=\"1\">\n"
             . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
             . "\n</script>\n";
    }
}
