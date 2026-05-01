<?php
/**
 * DiscoverPainClassifier — classifica um termo/keyword em 4 tipos de dor.
 *
 * Baseado no framework Gemini (g1, g6): cada trend tem uma "dor dominante"
 * que deveria guiar o tom editorial. Termo "CNH 2026" pode ser urgência (prazo),
 * medo (multa) ou dinheiro (valor). Saber qual domina muda como o LLM escreve.
 *
 * Uso:
 *   $p = DiscoverPainClassifier::classificar('CNH vencida multa 2026');
 *   // => ['urgencia' => 4, 'medo' => 8, 'dinheiro' => 3, 'oportunidade' => 0,
 *   //     'dominante' => 'medo', 'secundaria' => 'urgencia', 'peso_total' => 15, 'emoji' => '😨']
 *
 *   $inst = DiscoverPainClassifier::instrucaoProPrompt($p);
 *   // injeta no prompt do Claude/GPT pra guiar o tom
 */
class DiscoverPainClassifier
{
    /**
     * Pesos de keywords por dor. Valor 1-3 = quanto cada match contribui.
     * Max score por dor é 10 (saturado).
     */
    private static array $keywordsPorDor = [
        // 🔥 URGÊNCIA — prazo visível, contagem regressiva, perder janela temporal
        'urgencia' => [
            3 => ['hoje', 'amanhã', 'ontem', 'último dia', 'último prazo', 'prazo acaba', 'prazo final', 'encerra hoje', 'vence hoje', 'termina hoje'],
            2 => ['prazo', 'vence', 'encerra', 'termina', 'acaba', 'expira', 'até', 'antes que', 'corra', 'corre', 'rápido', 'imediato', 'urgente', 'não perca', 'últimas', 'últimos', 'fim'],
            1 => ['dias', 'horas', 'semana', 'em breve', 'próximo', 'agora', 'já', 'imediatamente'],
        ],

        // 😨 MEDO — perda real, multa, rejeição, bloqueio, erro grave
        'medo' => [
            3 => ['multa', 'penalidade', 'perder benefício', 'perder direito', 'corte', 'bloqueio', 'bloqueado', 'cancelamento', 'cancelado', 'pente fino', 'malha fina', 'fraude', 'golpe', 'prisão'],
            2 => ['perder', 'perde', 'perca', 'corta', 'negado', 'rejeitado', 'suspensão', 'suspenso', 'problema', 'erro', 'risco', 'alerta', 'cuidado', 'evitar', 'barra', 'barrado', 'impede', 'exclui', 'cuidado', 'atenção', 'fura', 'furada', 'descobre', 'descobriu'],
            1 => ['não', 'sem', 'fora', 'contra', 'ameaça'],
        ],

        // 💰 DINHEIRO — valor explícito, economia, pagamento, saque
        'dinheiro' => [
            3 => ['r$', 'reais', 'mil reais', 'milhões', 'bilhões'],
            2 => ['valor', 'preço', 'desconto', 'economia', 'economizar', 'pagar', 'pagamento', 'receber', 'saque', 'sacar', 'salário', 'renda', 'retorno', 'lucro', 'ganho', 'ganhar', 'investimento', 'comissão', 'taxa', 'isento', 'isenção', 'gratuito', 'grátis', 'aumento', 'reajuste', '13º', 'décimo terceiro', 'abono', 'bônus', 'restituição', 'cashback', 'crédito', 'dívida'],
            1 => ['dinheiro', 'caixa', 'banco', 'conta', 'reembolso'],
        ],

        // 💎 OPORTUNIDADE — elegibilidade, inscrição, benefício novo, acesso exclusivo
        'oportunidade' => [
            3 => ['curso grátis', 'vaga aberta', 'inscrições abertas', 'inscrição aberta', 'edital aberto', 'concurso aberto', 'benefício liberado', 'liberado'],
            2 => ['curso', 'vaga', 'vagas', 'emprego', 'concurso', 'bolsa', 'inscrição', 'inscrições', 'cadastre', 'solicite', 'disponível', 'novo', 'nova', 'novidade', 'abertas', 'aberto', 'participar', 'garantir', 'direito', 'acesso', 'benefício', 'programa', 'oportunidade', 'chance', 'elegível', 'elegibilidade', 'qualifica', 'qualificação'],
            1 => ['pode', 'podem', 'recebe', 'recebem', 'libera', 'distribui'],
        ],
    ];

    /** Emojis por dor — usados em UI (chip visual). */
    private static array $emojis = [
        'urgencia'     => '🔥',
        'medo'         => '😨',
        'dinheiro'     => '💰',
        'oportunidade' => '💎',
        'nenhuma'      => '⚪',
    ];

    /** Nome legível por dor (pra prompts e UI). */
    private static array $nomes = [
        'urgencia'     => 'Urgência',
        'medo'         => 'Medo de perda',
        'dinheiro'     => 'Dinheiro',
        'oportunidade' => 'Oportunidade',
    ];

    /**
     * Classifica um termo (e opcionalmente contexto de fontes) em 4 dores.
     *
     * @param string $termo       Keyword ou título do trend (obrigatório)
     * @param string $contextoExtra Texto adicional pra refinar (opcional): briefing, fontes, etc.
     * @return array ['urgencia' => N, 'medo' => N, 'dinheiro' => N, 'oportunidade' => N,
     *                'dominante' => string, 'secundaria' => string, 'peso_total' => int,
     *                'emoji' => string, 'label' => string]
     */
    public static function classificar(string $termo, string $contextoExtra = ''): array
    {
        $texto = mb_strtolower(trim($termo . ' ' . $contextoExtra), 'UTF-8');
        $texto = ' ' . preg_replace('/\s+/u', ' ', $texto) . ' '; // padding pra word boundary fácil

        $scores = [
            'urgencia'     => 0,
            'medo'         => 0,
            'dinheiro'     => 0,
            'oportunidade' => 0,
        ];

        foreach (self::$keywordsPorDor as $dor => $pesos) {
            foreach ($pesos as $peso => $kws) {
                foreach ($kws as $kw) {
                    $kwLow = mb_strtolower($kw, 'UTF-8');
                    // word boundary simples: delimitador antes e depois (espaço, pontuação)
                    if (preg_match('/[^a-zá-ÿ0-9]' . preg_quote($kwLow, '/') . '[^a-zá-ÿ0-9]/u', $texto)) {
                        $scores[$dor] += $peso;
                    }
                }
            }
            // Satura em 10
            if ($scores[$dor] > 10) $scores[$dor] = 10;
        }

        // Determina dominante / secundária
        arsort($scores);
        $keys = array_keys($scores);
        $dominante  = $scores[$keys[0]] > 0 ? $keys[0] : 'nenhuma';
        $secundaria = isset($keys[1]) && $scores[$keys[1]] > 0 ? $keys[1] : 'nenhuma';

        return [
            'urgencia'     => $scores['urgencia'],
            'medo'         => $scores['medo'],
            'dinheiro'     => $scores['dinheiro'],
            'oportunidade' => $scores['oportunidade'],
            'dominante'    => $dominante,
            'secundaria'   => $secundaria,
            'peso_total'   => array_sum($scores),
            'emoji'        => self::$emojis[$dominante] ?? '⚪',
            'label'        => self::$nomes[$dominante] ?? 'Sem dor identificada',
        ];
    }

    /**
     * Gera instrução pronta pra injetar no prompt do LLM.
     * O LLM recebe a dor dominante + guidance editorial específica por dor.
     */
    public static function instrucaoProPrompt(array $classificacao): string
    {
        $dom = $classificacao['dominante'] ?? 'nenhuma';
        if ($dom === 'nenhuma' || ($classificacao['peso_total'] ?? 0) < 3) {
            return ''; // sinal fraco, não força
        }

        $sec = $classificacao['secundaria'] ?? 'nenhuma';
        $guidance = [
            'urgencia' => [
                'titulo' => 'Abrir com countdown específico (quantos dias/horas faltam); prazo na 1ª frase; data absoluta no título.',
                'lead'   => 'Primeira linha revela o prazo; segunda linha mostra a consequência de não agir; terceira linha dá caminho de ação imediato.',
                'cta'    => 'Focado em ação agora. Ex: "Quem tem direito deve fazer o pedido ainda hoje — depois do dia X não tem como voltar atrás."',
                'evitar' => 'Não começar com contexto histórico ou definição — vá direto ao prazo.',
            ],
            'medo' => [
                'titulo' => 'Destacar a consequência concreta no título (quem perde, quanto paga de multa, quem é barrado).',
                'lead'   => 'Frase 1 com o risco específico baseado na fonte; frase 2 com quem é afetado; frase 3 com como escapar do problema.',
                'cta'    => 'Focado em proteção/prevenção. Ex: "Confira seu cadastro antes de [data] para não ter o benefício cortado."',
                'evitar' => 'Não gerar pânico genérico sem fato específico da fonte. Toda ameaça precisa de base.',
            ],
            'dinheiro' => [
                'titulo' => 'Valor exato no título (R$ X). Público específico que recebe quanto (R$ 500 pra nascidos em janeiro, R$ 300 pra outros).',
                'lead'   => 'Primeira linha com o valor máximo + escala; segunda linha com critério de elegibilidade; terceira linha com data de pagamento.',
                'cta'    => 'Focado em liberar o dinheiro. Ex: "Confira se seu cadastro está em dia para receber a parcela no dia X."',
                'evitar' => 'Valor sem qualificador ("R$ 1 mil pra 4 milhões") soa como promessa ampla — Discover corta alcance. Sempre com critério.',
            ],
            'oportunidade' => [
                'titulo' => 'Destacar o benefício + exclusividade (N vagas, inscrições abertas até X, elegibilidade simples).',
                'lead'   => 'Frase 1 com o benefício e número (N vagas, N bolsas); frase 2 com quem pode concorrer; frase 3 com como se inscrever.',
                'cta'    => 'Focado em garantir a vaga/benefício. Ex: "Inscrições abrem em X. Quem se cadastrar antes entra no primeiro lote."',
                'evitar' => 'Não omitir critérios de elegibilidade — clareza é o que diferencia oportunidade real de clickbait.',
            ],
        ];

        $bloco = "\n═══ DOR DOMINANTE DO TERMO: {$classificacao['label']} {$classificacao['emoji']} ═══\n";
        $bloco .= "Pontuação: " . self::formatarScores($classificacao) . "\n";
        if ($sec !== 'nenhuma' && ($classificacao[$sec] ?? 0) >= 3) {
            $bloco .= "Dor secundária: " . (self::$nomes[$sec] ?? '-') . "\n";
        }
        $bloco .= "\nCALIBRAGEM EDITORIAL POR DOR:\n";
        $g = $guidance[$dom] ?? null;
        if ($g) {
            $bloco .= "- TÍTULO: {$g['titulo']}\n";
            $bloco .= "- LEAD: {$g['lead']}\n";
            $bloco .= "- CTA: {$g['cta']}\n";
            $bloco .= "- EVITAR: {$g['evitar']}\n";
        }
        if ($sec !== 'nenhuma' && isset($guidance[$sec])) {
            $bloco .= "\nDor secundária ({$sec}) deve aparecer NO CORPO, nunca dominar o título.\n";
        }
        $bloco .= "═══ FIM DOR DOMINANTE ═══\n";
        return $bloco;
    }

    private static function formatarScores(array $c): string
    {
        return sprintf('🔥%d · 😨%d · 💰%d · 💎%d',
            $c['urgencia'] ?? 0, $c['medo'] ?? 0, $c['dinheiro'] ?? 0, $c['oportunidade'] ?? 0);
    }

    /** Label curto pra badges na UI. */
    public static function labelCurto(array $c): string
    {
        $dom = $c['dominante'] ?? 'nenhuma';
        $emoji = self::$emojis[$dom] ?? '⚪';
        $nome = self::$nomes[$dom] ?? '-';
        return "{$emoji} {$nome}";
    }
}
