<?php
/**
 * Score Discover — pontua um trend conforme portal.md
 *
 * Composição (0-10 cada, pesos somam 100%):
 *   Trend    30%  — volume + growth
 *   Emoção   30%  — heurística por categoria + gatilhos lexicais
 *   Intenção 25%  — tipo de consulta (informativa/transacional/ao-vivo...)
 *   Alcance  15%  — nacional vs regional vs global
 *
 * Resultado final 0-10. Regra portal.md: < 7 ignorar, >= 7 aprovar.
 */
require_once __DIR__ . '/TrendsTaxonomia.php';
require_once __DIR__ . '/DiscoverClusterMatcher.php';

class DiscoverScore
{
    /** Gatilhos lexicais por eixo emocional. */
    private static array $gatilhosEmocao = [
        // choque/alerta — 10
        10 => ['morre','morreu','preso','presa','absurdo','chocante','escândalo','polêmica','proibido','alerta','urgente','grave','acidente','assassinato','tragédia','sequestro','ataque','explosão','revolta','repudia','manifesto','denúncia','caso de','vaza'],
        // surpresa/reveal — 8
        8  => ['surpresa','inesperado','revela','confirmado','assumiu','descobre','exclusivo','bomba','anuncia','rompeu','separação','namoro','casamento','gravidez','volta','adeus','demitido'],
        // utilidade direta — 7
        7  => ['como','onde','quanto','quando','quem','vale a pena','melhor','preço','barato','desconto','gratuito','gratis','cupom','liberou','abriu inscrição','edital','vagas','concurso','novo'],
        // esporte ao vivo — 9
        9  => ['x','vs','ao vivo','jogo de hoje','hoje','transmissão','onde assistir','escalação','provável','gol','placar'],
    ];

    /** Padrões de intenção — regex => score. */
    private static array $padroesIntencao = [
        // transacional / ao vivo / agora — score alto
        '/\b(ao vivo|onde assistir|que horas|hoje|agora|transmiss(ã|a)o|link|streaming)\b/iu' => 10,
        '/\b(resultado|placar|quem ganhou|vencedor)\b/iu'                                       => 9,
        // tutorial: aceita "como se inscrever", "como fazer", "como consultar", etc.
        '/\b(como(?:\s+se)?\s+(fazer|receber|pedir|inscrever|consultar|sacar|cadastrar|solicitar|participar|acessar|tirar|pegar|emitir|agendar)|passo a passo|tutorial)\b/iu' => 9,
        // Serviço público / institucional recorrente — "minha casa minha vida", FGTS, INSS, PIS, Bolsa Família...
        '/\b(cadastro|inscri(ç|c)(ã|a)o|calend(á|a)rio de pagamento|tabela de pagamento|faixa de renda|consultar saldo|consultar benef(í|i)cio|nis|caixa tem|meu inss|gov\.?br|auxilio|benef(í|i)cio|limite|renda m(í|i)nima|saque|pis\/pasep|fgts|bolsa fam(í|i)lia|pé[- ]de[- ]meia|vale g(á|a)s|minha casa minha vida|mcmv|valor do benef(í|i)cio)\b/iu' => 9,
        '/\b(quanto custa|pre(ç|c)o|vale a pena|barato|desconto|cupom)\b/iu'                    => 8,
        '/\b(o que (é|significa)|quem é|significado|por que)\b/iu'                              => 7,
        '/\b(melhor|top|ranking|compara(ç|c)(a|ã)o)\b/iu'                                       => 7,
    ];

    /**
     * Bônus emocional por ID de categoria Google (mapa real decifrado empiricamente).
     * IDs corrigidos: 17=Esportes (não 11), 14=Política, 11=Outras, 7=Saúde (não 20), 18=Tecnologia (não 21).
     */
    private static array $emocaoPorCategoria = [
        4  => 8,  // Celebridades e entretenimento
        17 => 8,  // Esportes
        11 => 7,  // Outras / notícia geral
        14 => 7,  // Política
        20 => 7,  // Clima
        7  => 7,  // Saúde (YMYL emocional)
        10 => 7,  // Lei e governo
        9  => 7,  // Empregos e educação
        3  => 6,  // Negócios e finanças
        18 => 5,  // Tecnologia
    ];

    /**
     * Intenção natural alta por ID de categoria Google (mapa real).
     * Correções: 9=Empregos (não Compras), 17=Esportes, 10=Lei/gov, 19=Viagens (não 22), 7=Saúde (não 20).
     */
    private static array $intencaoPorCategoria = [
        17 => 8,  // Esportes (onde assistir, placar)
        9  => 8,  // Empregos e educação (como se inscrever, edital)
        10 => 8,  // Lei e governo (MCMV, INSS, FGTS, Bolsa Família)
        7  => 8,  // Saúde (sintomas, como tratar)
        14 => 7,  // Política
        11 => 7,  // Outras/notícia geral
        19 => 7,  // Viagens e transporte
        3  => 7,  // Negócios e finanças (IR, extrato, saque)
        1  => 7,  // Autos (recall, FIPE, CNH)
        20 => 7,  // Clima (alerta, previsão)
        18 => 6,  // Tecnologia
        15 => 6,  // Ciência
    ];

    // thresholdsPorCluster agora vive em TrendsTaxonomia (campo 'threshold' de cada cluster).

    /** Termos institucionais/recorrentes — ganham boost de Trend mesmo sem pico. */
    private static array $termosInstitucionais = [
        'minha casa minha vida','mcmv','bolsa fam(í|i)lia','pé[- ]de[- ]meia','fgts','inss','pis[\/ ]pasep','pis','pasep',
        'caixa tem','sisu','prouni','fies','encceja','enem','auxílio','aux(í|i)lio brasil','vale g(á|a)s',
        'receita federal','imposto de renda','ir \d{4}','restitui(ç|c)(ã|a)o',
        'detran','tse','calend(á|a)rio do','tabela do',
    ];

    /**
     * Calcula score completo de um trend vindo de TrendsScraperWeb::buscar().
     * @param array $t shape esperado: termo, volume_num, growth_pct, categoria_ids, noticias_qtd, relacionados
     * @return array ['final' => float, 'trend' => float, 'emocao' => float, 'intencao' => float, 'alcance' => float, 'status' => 'aprovado'|'ignorado']
     */
    public static function calcular(array $t): array
    {
        $trend    = self::scoreTrend($t);
        $emocao   = self::scoreEmocao($t);
        $intencao = self::scoreIntencao($t);
        $alcance  = self::scoreAlcance($t);

        $final = round(
            $trend * 0.30 +
            $emocao * 0.30 +
            $intencao * 0.25 +
            $alcance * 0.15,
            2
        );

        // Threshold dinâmico por cluster — antes fixo em 7.0 (matava serviço público).
        $threshold = self::thresholdPorTrend($t);

        return [
            'final'     => $final,
            'trend'     => $trend,
            'emocao'    => $emocao,
            'intencao'  => $intencao,
            'alcance'   => $alcance,
            'threshold' => $threshold,
            'status'    => $final >= $threshold ? 'aprovado' : 'ignorado',
        ];
    }

    /**
     * Retorna o threshold de aprovação do trend com base no cluster editorial detectado.
     * Fallback 7.0 se DiscoverClusterMatcher não estiver disponível.
     */
    public static function thresholdPorTrend(array $t): float
    {
        $cluster = DiscoverClusterMatcher::detectar([
            'termo'         => (string)($t['termo'] ?? ''),
            'categoria_ids' => $t['categoria_ids'] ?? [],
            'categorias'    => $t['categorias'] ?? [],
            'relacionados'  => $t['relacionados'] ?? [],
        ]);
        return TrendsTaxonomia::threshold($cluster['key'] ?? 'curiosidades_geral');
    }

    /** Volume (log) + sinal de crescimento + quantidade de artigos. */
    private static function scoreTrend(array $t): float
    {
        $vol   = (int)($t['volume_num'] ?? 0);
        $grow  = (int)($t['growth_pct'] ?? 0);
        $arts  = (int)($t['noticias_qtd'] ?? 0);

        // Volume → 0-10 em escala log
        $volScore = match (true) {
            $vol >= 1_000_000 => 10,
            $vol >=   500_000 => 9,
            $vol >=   200_000 => 8,
            $vol >=   100_000 => 7,
            $vol >=    50_000 => 6,
            $vol >=    20_000 => 5,
            $vol >=    10_000 => 4,
            $vol >=     5_000 => 3,
            $vol >     0      => 2,
            default           => 0,
        };

        // Growth: 1000%+ é limite prático; normaliza 0-10
        $growScore = min(10, $grow / 100);

        // Qtd de artigos = sinal de que a imprensa já está cobrindo (quanto maior, mais competição mas mais demanda comprovada)
        $artsScore = match (true) {
            $arts >= 50 => 10,
            $arts >= 20 => 8,
            $arts >= 10 => 6,
            $arts >= 3  => 4,
            $arts > 0   => 2,
            default     => 0,
        };

        // Boost institucional: termos recorrentes (MCMV, INSS, Bolsa Família, FGTS...) têm demanda estável
        // que não aparece como "pico" de growth. Se bater padrão institucional, eleva o piso do Trend.
        if (self::ehInstitucional($t)) {
            // Trata volume médio (>= 20K) como se fosse alto. Piso 7 no volScore se houver sinal institucional.
            if ($vol >= 20_000 && $volScore < 7) $volScore = 7;
            if ($vol >=  5_000 && $volScore < 5) $volScore = 5;
            // Boost de growth: public service searches têm baseline alto, 100% não é "pouco"
            if ($grow > 0) $growScore = max($growScore, 6);
        }

        return round($volScore * 0.6 + $growScore * 0.2 + $artsScore * 0.2, 2);
    }

    /** Detecta se o termo (ou seus relacionados) matcha padrão institucional. */
    private static function ehInstitucional(array $t): bool
    {
        $termo = mb_strtolower((string)($t['termo'] ?? ''), 'UTF-8');
        $rel   = mb_strtolower(implode(' ', $t['relacionados'] ?? []), 'UTF-8');
        $h     = $termo . ' ' . $rel;
        foreach (self::$termosInstitucionais as $pat) {
            if (preg_match('/\b' . $pat . '\b/iu', $h)) return true;
        }
        // Categoria 15 (Leis e governo) + categoria 10 (Empregos/educação) são fortes candidatas
        $cats = $t['categoria_ids'] ?? [];
        if (in_array(15, $cats, true) || in_array(10, $cats, true)) {
            // Exige também alguma palavra de "serviço" para evitar falso positivo
            if (preg_match('/\b(cadastro|inscri(ç|c)|benef|saque|consulta|calend|tabela|valor|renda|aux[íi]lio|edital|concurso|vagas)\b/iu', $h)) {
                return true;
            }
        }
        return false;
    }

    private static function scoreEmocao(array $t): float
    {
        $termo = mb_strtolower((string)($t['termo'] ?? ''), 'UTF-8');
        $relacionados = array_map(fn($r) => mb_strtolower((string)$r, 'UTF-8'), $t['relacionados'] ?? []);
        $haystack = $termo . ' ' . implode(' ', $relacionados);

        // Score base vindo da categoria
        $catIds = $t['categoria_ids'] ?? [];
        $base = 5;
        foreach ($catIds as $id) {
            if (isset(self::$emocaoPorCategoria[$id])) {
                $base = max($base, self::$emocaoPorCategoria[$id]);
            }
        }

        // Boost por gatilho lexical — pega o maior que bater
        $boost = 0;
        foreach (self::$gatilhosEmocao as $score => $gatilhos) {
            foreach ($gatilhos as $g) {
                if (preg_match('/\b' . preg_quote($g, '/') . '\b/iu', $haystack)) {
                    $boost = max($boost, $score - 5); // gatilho 10 = +5, gatilho 7 = +2
                }
            }
        }

        return min(10, round($base + $boost * 0.7, 2));
    }

    private static function scoreIntencao(array $t): float
    {
        $termo = (string)($t['termo'] ?? '');
        $relacionados = implode(' ', $t['relacionados'] ?? []);
        $haystack = $termo . ' ' . $relacionados;

        // Base por categoria
        $catIds = $t['categoria_ids'] ?? [];
        $base = 5;
        foreach ($catIds as $id) {
            if (isset(self::$intencaoPorCategoria[$id])) {
                $base = max($base, self::$intencaoPorCategoria[$id]);
            }
        }

        // Regex patterns nos termos relacionados (sinal forte de intenção real)
        $maxPattern = 0;
        foreach (self::$padroesIntencao as $pattern => $score) {
            if (preg_match($pattern, $haystack)) {
                $maxPattern = max($maxPattern, $score);
            }
        }

        if ($maxPattern > 0) return (float)$maxPattern;
        return (float)$base;
    }

    private static function scoreAlcance(array $t): float
    {
        $termo = mb_strtolower((string)($t['termo'] ?? ''), 'UTF-8');
        $vol   = (int)($t['volume_num'] ?? 0);

        // Indicador de alcance nacional pelo volume absoluto
        $volComp = match (true) {
            $vol >= 500_000 => 10,
            $vol >= 100_000 => 8,
            $vol >=  20_000 => 6,
            default         => 4,
        };

        // Menção regional reduz alcance (cidade/estado pequeno no termo)
        $regionais = ['bairro', 'interior', 'distrito', 'município', 'municipal'];
        foreach ($regionais as $r) {
            if (str_contains($termo, $r)) return max(3, $volComp - 3);
        }

        return (float)$volComp;
    }

    /** Rotula a intenção em texto curto pra exibição. */
    public static function rotuloIntencao(array $t): string
    {
        $termo = (string)($t['termo'] ?? '');
        $rel   = implode(' ', $t['relacionados'] ?? []);
        $h     = $termo . ' ' . $rel;

        if (preg_match('/\b(ao vivo|onde assistir|que horas|hoje|agora)\b/iu', $h)) return 'ao-vivo';
        if (preg_match('/\b(resultado|placar|quem ganhou)\b/iu', $h))               return 'resultado';
        // institucional ANTES de tutorial (mais específico)
        if (self::ehInstitucional($t))                                              return 'servico-publico';
        if (preg_match('/\b(como(?:\s+se)?\s+\w+|passo a passo|tutorial)\b/iu', $h)) return 'tutorial';
        if (preg_match('/\b(pre(ç|c)o|vale a pena|barato|desconto)\b/iu', $h))      return 'transacional';
        if (preg_match('/\b(melhor|top|ranking|compara)\b/iu', $h))                 return 'comparativo';
        if (preg_match('/\b(o que é|quem é|significado|por que)\b/iu', $h))         return 'informacional';
        return 'geral';
    }
}
