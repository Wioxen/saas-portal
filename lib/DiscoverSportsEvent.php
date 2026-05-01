<?php
/**
 * DiscoverSportsEvent — extrai dados de evento esportivo do termo + LLM e gera
 * schema.org/SportsEvent válido pra rich result do Google.
 *
 * Arquitetura:
 *   1. instrucaoProPrompt() — bloco que pede ao Claude pra retornar
 *      sports_event no JSON de saída. Plugado quando cluster=esportes.
 *   2. extrairDoPrimeiro() — lê o response do LLM, valida, normaliza.
 *   3. paraSchema() — converte pra estrutura @graph do DiscoverSchemas.
 *
 * Validação obrigatória pro schema sair:
 *   - home_team + away_team não vazios
 *   - kickoff parseável como ISO 8601
 *   - kickoff dentro de janela [-12h, +30d] do agora
 *     (jogo terminado >12h vira post histórico, schema é misleading)
 *
 * Sem todos esses → método retorna null e DiscoverSchemas pula SportsEvent.
 * NewsArticle ainda sai sempre (fallback seguro).
 */

class DiscoverSportsEvent
{
    /** Janela máxima pra trás (jogo já terminou). Após isso, schema é histórico. */
    private const HORAS_MAX_PASSADO = 12;

    /** Janela máxima pra frente (jogos futuros muito distantes). */
    private const DIAS_MAX_FUTURO = 30;

    /**
     * Bloco de instrução pro prompt do Claude. Plugado em DiscoverGerador
     * quando cluster=esportes, antes da chamada Maquina::rodar().
     */
    public static function instrucaoProPrompt(): string
    {
        return "\n═══ EXTRAÇÃO DE EVENTO ESPORTIVO (schema.org/SportsEvent) ═══\n"
             . "Se este artigo cobre uma PARTIDA ESPECÍFICA (ex: 'Vitória x Coritiba',\n"
             . "'Brasil x Argentina', 'UFC 305 Adesanya x Du Plessis'), retorne no JSON\n"
             . "final um campo `sports_event` com a estrutura abaixo. Se NÃO for partida\n"
             . "específica (ex: 'tabela do brasileirão', 'classificação F1'), OMITA o campo.\n\n"
             . "Estrutura esperada (todos os campos opcionais exceto os marcados [obrig]):\n"
             . "  \"sports_event\": {\n"
             . "    \"home_team\":   \"Esporte Clube Vitória\",     [obrig]\n"
             . "    \"away_team\":   \"Coritiba\",                    [obrig]\n"
             . "    \"kickoff\":     \"2026-05-04T19:30:00-03:00\",   [obrig — ISO 8601 BRT]\n"
             . "    \"competition\": \"Brasileirão Série A 2026\",    (opcional)\n"
             . "    \"venue\":       \"Barradão\",                    (opcional)\n"
             . "    \"city\":        \"Salvador, BA\",                (opcional)\n"
             . "    \"sport\":       \"Football\"                     (opcional, default Football)\n"
             . "  }\n\n"
             . "REGRAS CRÍTICAS:\n"
             . "  ✓ kickoff DEVE ser data/hora real extraída de fonte primária (CBF, site oficial,\n"
             . "    portal esportivo). Se o horário não está confirmado nas fontes → OMITA o campo\n"
             . "    sports_event inteiro. Schema com data inventada penaliza Discover.\n"
             . "  ✓ Use timezone -03:00 (Brasília) para jogos no Brasil.\n"
             . "  ✓ home_team e away_team com NOME COMPLETO (ex: 'Esporte Clube Vitória',\n"
             . "    não 'Vitória'). Apelido vai pro alternateName se necessário.\n"
             . "  ✗ NÃO inclua scores ou resultados — schema é só sobre o evento, não sobre quem ganhou.\n"
             . "  ✗ NÃO invente competição ou local se a fonte não cita.\n"
             . "═══ FIM SPORTS EVENT ═══\n";
    }

    /**
     * Extrai e valida sports_event do response do LLM. Retorna null se inválido
     * ou ausente (schema não sai).
     *
     * @param array $primeiro response shape do Maquina/DiscoverGerador
     * @return array|null campos normalizados ou null
     */
    public static function extrairDoPrimeiro(array $primeiro): ?array
    {
        $raw = $primeiro['sports_event'] ?? null;
        // Pode vir aninhado em 'meta' ou em 'extras' dependendo do parser
        if ($raw === null && isset($primeiro['meta']['sports_event'])) {
            $raw = $primeiro['meta']['sports_event'];
        }
        if ($raw === null && isset($primeiro['extras']['sports_event'])) {
            $raw = $primeiro['extras']['sports_event'];
        }
        if (!is_array($raw)) return null;

        $home = trim((string)($raw['home_team'] ?? ''));
        $away = trim((string)($raw['away_team'] ?? ''));
        $kickoff = trim((string)($raw['kickoff'] ?? ''));

        // Campos obrigatórios
        if ($home === '' || $away === '' || $kickoff === '') return null;
        if (mb_strtolower($home) === mb_strtolower($away)) return null; // mesmo time = nonsense

        // kickoff válido?
        $ts = strtotime($kickoff);
        if ($ts === false) return null;

        // Janela temporal sensata
        $agora = time();
        $deltaHoras = ($ts - $agora) / 3600;
        if ($deltaHoras < -self::HORAS_MAX_PASSADO) return null; // já terminou há muito
        if ($deltaHoras > self::DIAS_MAX_FUTURO * 24) return null; // futuro distante demais

        return [
            'home_team'   => mb_substr($home, 0, 200),
            'away_team'   => mb_substr($away, 0, 200),
            'kickoff'     => date(DATE_ATOM, $ts), // normaliza ISO 8601
            'competition' => mb_substr(trim((string)($raw['competition'] ?? '')), 0, 200),
            'venue'       => mb_substr(trim((string)($raw['venue'] ?? '')), 0, 200),
            'city'        => mb_substr(trim((string)($raw['city'] ?? '')), 0, 100),
            'sport'       => mb_substr(trim((string)($raw['sport'] ?? 'Football')), 0, 50),
        ];
    }

    /**
     * Converte sports_event normalizado em estrutura schema.org/SportsEvent
     * pronta pra entrar no @graph.
     */
    public static function paraSchema(array $se, array $meta): ?array
    {
        if (empty($se['home_team']) || empty($se['away_team']) || empty($se['kickoff'])) return null;

        $url = (string)($meta['url'] ?? '');
        $titulo = (string)($meta['titulo'] ?? '');

        $name = $se['home_team'] . ' x ' . $se['away_team'];
        if (!empty($se['competition'])) $name .= ' — ' . $se['competition'];

        $schema = [
            '@type'       => 'SportsEvent',
            'name'        => mb_substr($name, 0, 250),
            'startDate'   => $se['kickoff'],
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/MixedEventAttendanceMode',
            'sport'       => $se['sport'] ?: 'Football',
            'homeTeam'    => [
                '@type' => 'SportsTeam',
                'name'  => $se['home_team'],
            ],
            'awayTeam'    => [
                '@type' => 'SportsTeam',
                'name'  => $se['away_team'],
            ],
            'competitor'  => [
                ['@type' => 'SportsTeam', 'name' => $se['home_team']],
                ['@type' => 'SportsTeam', 'name' => $se['away_team']],
            ],
            'description' => mb_substr($titulo, 0, 250),
        ];

        if ($url !== '') $schema['url'] = $url;
        if (!empty($se['competition'])) $schema['superEvent'] = [
            '@type' => 'SportsEvent',
            'name'  => $se['competition'],
        ];

        if (!empty($se['venue'])) {
            $location = [
                '@type' => 'Place',
                'name'  => $se['venue'],
            ];
            if (!empty($se['city'])) {
                $location['address'] = [
                    '@type'           => 'PostalAddress',
                    'addressLocality' => $se['city'],
                    'addressCountry'  => 'BR',
                ];
            }
            $schema['location'] = $location;
        }

        return $schema;
    }
}
