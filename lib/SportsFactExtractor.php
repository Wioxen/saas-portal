<?php
declare(strict_types=1);

/**
 * SportsFactExtractor — extrai fatos esportivos LITERAIS das fontes scrapeadas
 * antes de chamar o LLM. Pipeline antes lia fontes fluidas e dava liberdade
 * pro Claude reescrever — resultado: alucinação de canal de TV, horário, estádio.
 *
 * Caso real #742/#1716 leaodabarra (2026-05-02): jogo Vitória x Coritiba era
 * transmitido SÓ pelo Premiere, fontes confirmavam, mas Claude inventou "TV Aratu"
 * (sabe por treinamento que Aratu transmite Baianão, alucinou pra Brasileirão).
 *
 * Solução: pré-processar fontes via whitelist/regex e produzir bloco estruturado
 * "FATOS EXTRAÍDOS" que vai pro prompt como instrução inviolável.
 *
 * Uso:
 *   $fatos = SportsFactExtractor::extrair($fontesOk);
 *   $bloco = SportsFactExtractor::paraPrompt($fatos);
 *   $promptSystem .= $bloco;
 *
 * Validação pós-geração via SportsFactValidator (separado).
 */
class SportsFactExtractor
{
    /**
     * Whitelist de CANAIS DE TV / streaming brasileiros conhecidos.
     * Extração só aceita match LITERAL no texto da fonte.
     * Inclui variações (TV Aratu / Aratu, SporTV / Sportv).
     */
    private const CANAIS_TV = [
        // TV aberta nacional
        'Globo', 'TV Globo', 'Rede Globo', 'Band', 'TV Band', 'Bandeirantes',
        'Record', 'TV Record', 'SBT', 'TV SBT', 'RedeTV', 'Cultura', 'TV Cultura',
        // TV fechada esportiva
        'SporTV', 'SporTV2', 'SporTV3', 'Sportv', 'ESPN', 'ESPN Brasil',
        'ESPN2', 'Disney+', 'Disney Plus', 'Star+', 'Premiere', 'Premiere FC',
        'Combate', 'TNT Sports', 'TNT', 'Space',
        // Streaming gratuito / Cazé / GE
        'Cazé TV', 'CazéTV', 'Caze TV', 'YouTube Cazé',
        'ge.globo', 'ge globo', 'GE TV', 'Globoplay', 'Globo Play',
        'Amazon Prime', 'Prime Video', 'Apple TV+', 'Apple TV',
        'Twitch', 'Pluto TV', 'Vix',
        // TV regional (incluídas pra DETECTAR menção, mas user vai querer SÓ se realmente está na fonte)
        'TV Aratu', 'Aratu', 'Aratu On', 'TV Bahia', 'Bahia FM', 'Itapoan',
        'Inter TV', 'TV Sergipe', 'TV Tribuna', 'TV Anhanguera',
        'TV Verdes Mares', 'TV Mirante', 'TV Tem', 'TV Vanguarda',
        // Rádio AM/FM esportiva
        'Rádio Itatiaia', 'Itatiaia', 'Bahia Notícias', 'Rádio Sociedade',
        'Rádio Cruzeiro do Sul', 'Rádio Cidade', 'Sportcenter',
    ];

    /**
     * Estádios brasileiros conhecidos. Extração só aceita match literal.
     */
    private const ESTADIOS_BR = [
        // Bahia
        'Barradão', 'Barradao', 'Manoel Barradas', 'Estádio Manoel Barradas',
        'Fonte Nova', 'Arena Fonte Nova', 'Itaipava Arena Fonte Nova',
        'Pituaçu', 'Estádio de Pituaçu', 'Joia da Princesa',
        // Sudeste
        'Maracanã', 'Estádio Mário Filho', 'Mineirão', 'Estádio Mineirão',
        'Mineirinho', 'Independência', 'Arena MRV', 'Arena Independência',
        'Allianz Parque', 'Arena Palmeiras', 'Morumbi', 'MorumBis',
        'Neo Química Arena', 'Arena Corinthians', 'Itaquerão',
        'Vila Belmiro', 'Urbano Caldeira',
        'Nilton Santos', 'Estádio Nilton Santos', 'Engenhão',
        'São Januário', 'Vasco da Gama', 'Maracanãzinho',
        // Sul
        'Arena do Grêmio', 'Arena Grêmio', 'Beira-Rio', 'Estádio Beira-Rio',
        'Couto Pereira', 'Vila Capanema', 'Arena da Baixada',
        'Ressacada', 'Aderbal Ramos da Silva', 'Orlando Scarpelli',
        // Centro / Norte
        'Mané Garrincha', 'Estádio Nacional Mané Garrincha',
        'Mangueirão', 'Estádio Olímpico do Pará',
        'Baenão', 'Curuzu',
        // Nordeste
        'Castelão', 'Castelão CE', 'Castelão MA', 'Arena Castelão',
        'Arena Pernambuco', 'Itaipava Arena Pernambuco',
        'Ilha do Retiro', 'Aflitos', 'Arruda',
        'Batistão', 'Estádio Lourival Batista',
        'Arena das Dunas', 'Frasqueirão',
        'Almeidão', 'Arena Aracaju', 'Arena Jacaré',
    ];

    /**
     * Padrões de horário em PT-BR — capturados literais.
     */
    private const REGEX_HORARIO = '/\b(\d{1,2})h\d{0,2}\b/u';
    private const REGEX_DATA = '/\b(\d{1,2})\s+de\s+(janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)(?:\s+(?:de\s+)?(\d{4}))?\b/iu';
    private const REGEX_DIA_SEMANA = '/\b(domingo|segunda|terça|quarta|quinta|sexta|sábado|sabado)(?:-feira)?\b/iu';

    /**
     * Extrai todos os fatos esportivos das fontes scrapeadas.
     *
     * @param array $fontesOk  Lista de {url, fonte: {meta, content: {paragraphs}}}
     * @return array {
     *   canais_tv: string[],
     *   estadios: string[],
     *   horarios: string[],   # ex: ["18h30"]
     *   datas: string[],      # ex: ["2 de maio"]
     *   dias_semana: string[],
     *   fontes_consultadas: string[]
     * }
     */
    public static function extrair(array $fontesOk): array
    {
        $blob = self::concatenarFontes($fontesOk);
        if ($blob === '') {
            return self::emptyResult();
        }

        return [
            'canais_tv'          => self::extrairCanais($blob),
            'estadios'           => self::extrairEstadios($blob),
            'horarios'           => self::extrairHorarios($blob),
            'datas'              => self::extrairDatas($blob),
            'dias_semana'        => self::extrairDiasSemana($blob),
            'arbitros'           => self::extrairArbitragem($blob),
            'ingressos_urls'     => self::extrairIngressosUrls($blob),
            'publico_estimado'   => self::extrairPublico($blob),
            'pontos_tabela'      => self::extrairPontosTabela($blob),
            'placares'           => self::extrairPlacares($blob),
            'pendurados'         => self::extrairListaApos($blob, 'pendurado'),
            'desfalques'         => self::extrairListaApos($blob, 'desfalque'),
            'escalacoes_blocos'  => self::extrairEscalacoes($blob),
            'fontes_consultadas' => self::listarFontes($fontesOk),
        ];
    }

    /**
     * Formata os fatos pra ser injetado no prompt do LLM como bloco inviolável.
     */
    public static function paraPrompt(array $fatos): string
    {
        $bloco = "═══ FATOS EXTRAÍDOS DAS FONTES — USE APENAS ESTES ═══\n";
        $bloco .= "Os dados abaixo foram extraídos LITERALMENTE das fontes scrapeadas. ";
        $bloco .= "REGRA INVIOLÁVEL: cada fato concreto sobre o jogo (canal de TV, horário, ";
        $bloco .= "estádio, data) que você mencionar no artigo DEVE constar nesta lista. ";
        $bloco .= "Se não está aqui, NÃO MENCIONE — não infira por treinamento.\n\n";

        $linhas = [];
        if (!empty($fatos['canais_tv'])) {
            $linhas[] = "📺 Canais de TV/streaming citados: " . implode(', ', $fatos['canais_tv']);
        } else {
            $linhas[] = "📺 Canais de TV: NENHUM citado nas fontes — escreva 'Transmissão a confirmar pela emissora oficial'.";
        }
        if (!empty($fatos['estadios']))      $linhas[] = "🏟️ Estádio(s): " . implode(', ', $fatos['estadios']);
        if (!empty($fatos['horarios']))      $linhas[] = "⏰ Horários: " . implode(', ', $fatos['horarios']);
        if (!empty($fatos['datas']))         $linhas[] = "📅 Datas: " . implode('; ', $fatos['datas']);
        if (!empty($fatos['dias_semana']))   $linhas[] = "📆 Dias da semana: " . implode(', ', $fatos['dias_semana']);
        if (!empty($fatos['arbitros']))      $linhas[] = "👨‍⚖️ Arbitragem: " . implode(' · ', $fatos['arbitros']);
        if (!empty($fatos['ingressos_urls']))$linhas[] = "🎟️ URLs de ingresso: " . implode(' · ', $fatos['ingressos_urls']);
        if (!empty($fatos['publico_estimado'])) $linhas[] = "👥 Público citado: " . implode(', ', $fatos['publico_estimado']);
        if (!empty($fatos['pontos_tabela'])) $linhas[] = "📊 Pontos/posição na tabela: " . implode(', ', $fatos['pontos_tabela']);
        if (!empty($fatos['placares']))      $linhas[] = "⚽ Placares mencionados: " . implode(', ', $fatos['placares']);
        if (!empty($fatos['pendurados']))    $linhas[] = "🟨 Pendurados: " . implode(', ', $fatos['pendurados']);
        if (!empty($fatos['desfalques']))    $linhas[] = "🩹 Desfalques: " . implode(', ', $fatos['desfalques']);
        if (!empty($fatos['escalacoes_blocos'])) {
            $linhas[] = "📋 Escalações:\n  " . implode("\n  ", $fatos['escalacoes_blocos']);
        }

        $bloco .= "- " . implode("\n- ", $linhas) . "\n\n";

        $bloco .= "REGRAS PRA CADA CATEGORIA DE FATO:\n";
        $bloco .= "📺 CANAL DE TV — só os listados acima. Inferir 'Aratu transmite Baianão' = alucinação proibida. Se a fonte não cita canal, escreva 'Transmissão a confirmar'.\n";
        $bloco .= "🏟️ ESTÁDIO — só os listados acima. Não invente apelido alternativo.\n";
        $bloco .= "⏰ HORÁRIO — use exatamente o que está acima. Não converta fuso (já está em horário de Brasília).\n";
        $bloco .= "📋 ESCALAÇÃO — use APENAS os nomes listados acima. Não complete time com jogador que você 'sabe' por treinamento. Se faltam jogadores, deixe explícito 'a confirmar'.\n";
        $bloco .= "👨‍⚖️ ARBITRAGEM — use exatamente o nome listado, com UF entre parênteses. Não invente assistentes.\n";
        $bloco .= "🎟️ INGRESSO — use SÓ a URL listada. Não construa URL por padrão (ex: 'site.com/ingresso' baseado no domínio do clube).\n";
        $bloco .= "🟨 PENDURADOS / 🩹 DESFALQUES — use SÓ os nomes listados.\n";
        $bloco .= "═══ FIM FATOS EXTRAÍDOS ═══\n\n";

        return $bloco;
    }

    /** Concatena texto de todas as fontes (paragraphs + meta.title + meta.description). */
    private static function concatenarFontes(array $fontesOk): string
    {
        $partes = [];
        foreach ($fontesOk as $f) {
            $meta = $f['fonte']['meta'] ?? [];
            if (!empty($meta['title']))       $partes[] = (string)$meta['title'];
            if (!empty($meta['description'])) $partes[] = (string)$meta['description'];
            $paras = $f['fonte']['content']['paragraphs'] ?? [];
            if (is_array($paras))             $partes[] = implode("\n", $paras);
        }
        return implode("\n\n", array_filter($partes, 'is_string'));
    }

    /** Whitelist match: só inclui canal se aparece literal (case-insensitive, word boundary). */
    private static function extrairCanais(string $blob): array
    {
        $blobLower = mb_strtolower($blob, 'UTF-8');
        $achados = [];
        foreach (self::CANAIS_TV as $canal) {
            $needle = mb_strtolower($canal, 'UTF-8');
            // Word boundary pra evitar match parcial ("Globo" matchando "globoplay")
            $pattern = '/\b' . preg_quote($needle, '/') . '\b/iu';
            if (preg_match($pattern, $blobLower)) {
                $achados[$canal] = true;
            }
        }
        // Dedupe variações (ex: 'SporTV' e 'Sportv' — preserva a primeira encontrada)
        return array_keys($achados);
    }

    /** Whitelist match pra estádios. */
    private static function extrairEstadios(string $blob): array
    {
        $blobLower = mb_strtolower($blob, 'UTF-8');
        $achados = [];
        foreach (self::ESTADIOS_BR as $est) {
            $needle = mb_strtolower($est, 'UTF-8');
            $pattern = '/\b' . preg_quote($needle, '/') . '\b/iu';
            if (preg_match($pattern, $blobLower)) {
                $achados[$est] = true;
            }
        }
        return array_keys($achados);
    }

    /** Captura horários no formato Xh, XhYY, XX:YY. */
    private static function extrairHorarios(string $blob): array
    {
        $horarios = [];
        if (preg_match_all(self::REGEX_HORARIO, $blob, $m)) {
            foreach ($m[0] as $h) {
                $h = trim($h);
                if ($h !== '') $horarios[$h] = true;
            }
        }
        return array_keys($horarios);
    }

    /** Captura "X de [mes]" formato PT-BR. */
    private static function extrairDatas(string $blob): array
    {
        $datas = [];
        if (preg_match_all(self::REGEX_DATA, $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $datas[trim($hit[0])] = true;
            }
        }
        return array_keys($datas);
    }

    /** Dia da semana mencionado. */
    private static function extrairDiasSemana(string $blob): array
    {
        $dias = [];
        if (preg_match_all(self::REGEX_DIA_SEMANA, $blob, $m)) {
            foreach ($m[0] as $d) {
                $dias[mb_strtolower($d, 'UTF-8')] = true;
            }
        }
        return array_keys($dias);
    }

    /** Extrai árbitros: "Árbitro: Nome (UF)" / "VAR: Nome (UF)". */
    private static function extrairArbitragem(string $blob): array
    {
        $arbs = [];
        // "Árbitro: Nome Sobrenome (UF)"
        $regex = '/(árbitro|arbitro|var|assistente\s*\d?|4[º°]?\s*árbitro)\s*[:\-]?\s*([A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç]+(?:\s+[A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç]+){1,4})\s*\(?([A-Z]{2}(?:\/Fifa)?)?/iu';
        if (preg_match_all($regex, $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $papel = trim($hit[1]);
                $nome  = trim($hit[2]);
                $uf    = trim($hit[3] ?? '');
                if (mb_strlen($nome) >= 8) {
                    $arbs[] = $papel . ': ' . $nome . ($uf ? " ({$uf})" : '');
                }
            }
        }
        return array_slice(array_unique($arbs), 0, 6);
    }

    /** Extrai URLs de ingressos (com palavra-chave ingresso/bilhete/venda no path). */
    private static function extrairIngressosUrls(string $blob): array
    {
        $urls = [];
        if (preg_match_all('#https?://[^\s<>"\']+#iu', $blob, $m)) {
            foreach ($m[0] as $u) {
                $u = rtrim($u, '.,;:)');
                if (preg_match('/(ingress|bilhete|tickets?|comprar|venda)/iu', $u)) {
                    $urls[$u] = true;
                }
            }
        }
        // URLs sem http (ex: "botafogo.com.br/ingresso")
        if (preg_match_all('#\b[a-z0-9-]+\.(?:com\.br|com|net|org)/(?:ingress|bilhete|tickets|comprar|venda)[^\s<>"\']*#iu', $blob, $m)) {
            foreach ($m[0] as $u) {
                $clean = rtrim($u, '.,;:)');
                $urls['https://' . $clean] = true;
            }
        }
        return array_keys($urls);
    }

    /** Captura "X mil torcedores" / "Y mil ingressos" / "Z mil presentes". */
    private static function extrairPublico(string $blob): array
    {
        $pubs = [];
        $regex = '/\b(\d{1,3}(?:\.\d{3})*|\d+\s*mil)\s+(torcedores|presentes|ingressos|pessoas|fãs|cadeiras)\b/iu';
        if (preg_match_all($regex, $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) $pubs[] = trim($hit[0]);
        }
        return array_slice(array_unique($pubs), 0, 5);
    }

    /** "Vitória tem X pontos" / "13ª colocação com Y pontos". */
    private static function extrairPontosTabela(string $blob): array
    {
        $info = [];
        // "X pontos" + clube no contexto
        if (preg_match_all('/\b(\d{1,3})\s+pontos?\b/iu', $blob, $m)) {
            foreach ($m[0] as $p) $info[] = trim($p);
        }
        // "Xª colocação" / "Xº colocado"
        if (preg_match_all('/\b(\d{1,2})[ºª]\s+(coloca[çc][ãa]o|colocado|posi[çc][ãa]o|lugar)\b/iu', $blob, $m)) {
            foreach ($m[0] as $p) $info[] = trim($p);
        }
        return array_slice(array_unique($info), 0, 8);
    }

    /** Placares: "X a Y" / "X x Y" pós-jogo. */
    private static function extrairPlacares(string $blob): array
    {
        $placares = [];
        // "venceu por 2 a 1" / "empatou em 1 a 1"
        if (preg_match_all('/\b(\d+)\s*[ax]\s*(\d+)\b/iu', $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $a = (int)$hit[1]; $b = (int)$hit[2];
                if ($a < 20 && $b < 20) $placares[] = "{$a} a {$b}";
            }
        }
        return array_slice(array_unique($placares), 0, 5);
    }

    /**
     * Lista de itens após uma palavra-chave (pendurados, desfalques, lesionados, suspensos).
     * Captura formato: "Pendurados: Nome1, Nome2, Nome3"
     */
    private static function extrairListaApos(string $blob, string $palavra): array
    {
        $itens = [];
        // Variações: "pendurado", "pendurados:", "Pendurados —"
        $regex = '/\b' . preg_quote($palavra, '/') . 's?\s*[:\-—]\s*([A-ZÁÉÍÓÚÂÊÔÃÕÇ][^.\n]{10,200})/iu';
        if (preg_match_all($regex, $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $lista = trim($hit[1]);
                // Quebra por vírgula ou "e"
                $partes = preg_split('/,|\s+e\s+/u', $lista) ?: [];
                foreach ($partes as $p) {
                    $p = trim($p);
                    // Aceita só nomes próprios (começam com maiúscula, 4+ chars)
                    if (preg_match('/^[A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç]{2,}/u', $p) && mb_strlen($p) <= 50) {
                        $itens[] = $p;
                    }
                }
                if (count($itens) >= 12) break;
            }
        }
        return array_slice(array_unique($itens), 0, 12);
    }

    /**
     * Captura blocos de escalação: "TIME1: Nome1, Nome2, ..."
     * Heurística: linha que tem time conhecido + ":" + ≥6 palavras com inicial maiúscula.
     */
    private static function extrairEscalacoes(string $blob): array
    {
        $escalacoes = [];
        // Linhas tipo "BOTAFOGO: Neto; Vitinho, Bastos, ..."
        $regex = '/\b([A-ZÁÉÍÓÚÂÊÔÃÕÇ][A-ZÁÉÍÓÚÂÊÔÃÕÇ\s\-]{3,30})\s*:\s*([A-ZÁÉÍÓÚÂÊÔÃÕÇ][^\n]{40,400})/u';
        if (preg_match_all($regex, $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $time = trim($hit[1]);
                $linha = trim($hit[2]);
                // Conta palavras com inicial maiúscula (provável jogador)
                $palavras = preg_split('/\s+/u', $linha) ?: [];
                $maius = 0;
                foreach ($palavras as $p) {
                    if (preg_match('/^[A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç]{2,}/u', $p)) $maius++;
                }
                if ($maius >= 6) {
                    $escalacoes[] = "{$time}: " . mb_substr($linha, 0, 300);
                }
            }
        }
        return array_slice(array_unique($escalacoes), 0, 4);
    }

    private static function listarFontes(array $fontesOk): array
    {
        $fontes = [];
        foreach ($fontesOk as $f) {
            $url = (string)($f['url'] ?? '');
            if ($url !== '') $fontes[] = $url;
        }
        return $fontes;
    }

    private static function emptyResult(): array
    {
        return [
            'canais_tv' => [],
            'estadios' => [],
            'horarios' => [],
            'datas' => [],
            'dias_semana' => [],
            'fontes_consultadas' => [],
        ];
    }
}
