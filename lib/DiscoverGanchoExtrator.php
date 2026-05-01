<?php
/**
 * Identifica o GANCHO MAIS FORTE dentro das fontes scrapeadas antes de gerar o artigo.
 *
 * Motivação: sem essa camada, o LLM escolhe sempre o fato mais óbvio (prazo/data).
 * O gancho de maior CTR costuma ser um risco/gap que está "enterrado" na fonte
 * (ex: "CadÚnico desatualizado reprova pedido automaticamente").
 *
 * Saída: array com palavras-chave do gancho + frase exemplo extraída literalmente
 * da fonte. Essas informações são injetadas no prompt como requisito obrigatório:
 * "o gancho abaixo DEVE aparecer no título OU no lead".
 */
class DiscoverGanchoExtrator
{
    /** Palavras que sinalizam risco/gap forte (não apenas prazo/data). */
    private static array $triggers = [
        // Risco de rejeição
        'reprova', 'reprovado', 'rejeitado', 'rejeita', 'negado', 'nega', 'barrado', 'barra',
        'elimina', 'eliminado', 'cancela', 'cancelado', 'bloqueado', 'bloqueia', 'impede',
        'exclui', 'excluído', 'não pode', 'não poderá',
        // Erros/pegadinhas comuns
        'erro', 'pegadinha', 'detalhe que', 'detalhe escondido', 'requisito escondido',
        'desatualizado', 'desatualizada', 'incompleto', 'incompleta', 'irregular',
        'inconsistência', 'divergência',
        // Consequências financeiras não-óbvias
        'paga a mais', 'paga em dobro', 'perde o direito', 'perde a vaga', 'perde o benefício',
        'taxa cheia', 'taxa integral', 'multa de', 'multa no valor',
        // Processos específicos que viram armadilha
        'CadÚnico', 'CPF pendente', 'CPF regular', 'cadastro ativo', 'comprovante',
        'declaração de imposto', 'recadastramento', 'biometria', 'prova de vida',
        // Gaps de exclusão
        'ficam de fora', 'ficam sem', 'não serão', 'não terão', 'só recebe', 'apenas quem',
        'precisa ter', 'precisa estar', 'exige',
    ];

    /**
     * @param array $fontes estrutura ['content' => ['paragraphs' => [...]]]
     * @return array ['palavras' => [...], 'frase' => '...', 'score' => int, 'tipo' => 'risco|gap|detalhe',
     *                'diferencial' => ['frase' => ..., 'score' => ...], 'escala' => ['valor' => ..., 'contexto' => ...]]
     */
    public static function extrair(array $fontes): array
    {
        $candidatas = [];
        $diferenciais = [];
        $escalas = [];

        foreach ($fontes as $f) {
            $paras = $f['content']['paragraphs'] ?? [];
            if (!is_array($paras)) continue;
            foreach ($paras as $p) {
                $p = (string)$p;
                $score = self::scoreParagrafo($p);
                $len = mb_strlen($p, 'UTF-8');
                if ($len < 60 || $len > 400) $score -= 2;
                if ($score > 0) $candidatas[] = ['frase' => trim($p), 'score' => $score];

                // DIFERENCIAL — frase com insight ÚNICO (regra inédita, mudança anunciada, automático, primeiro-de)
                $scoreDif = self::scoreDiferencial($p);
                if ($scoreDif > 0 && $len >= 60 && $len <= 400) {
                    $diferenciais[] = ['frase' => trim($p), 'score' => $scoreDif];
                }

                // ESCALA — volumetria forte (milhões, R$ bi, N mil pessoas)
                $escala = self::extrairEscala($p);
                if ($escala !== null) $escalas[] = $escala;
            }
        }

        $resultado = ['palavras' => [], 'frase' => '', 'score' => 0, 'tipo' => 'nenhum', 'diferencial' => null, 'escala' => null];

        if (!empty($candidatas)) {
            usort($candidatas, fn($a, $b) => $b['score'] <=> $a['score']);
            $top = $candidatas[0];
            $palavras = self::palavrasGatilhoNaFrase($top['frase']);
            $resultado = [
                'palavras' => $palavras,
                'frase'    => $top['frase'],
                'score'    => $top['score'],
                'tipo'     => self::classificarTipo($palavras),
                'diferencial' => null,
                'escala'      => null,
            ];
        }

        if (!empty($diferenciais)) {
            usort($diferenciais, fn($a, $b) => $b['score'] <=> $a['score']);
            $resultado['diferencial'] = $diferenciais[0];
        }

        if (!empty($escalas)) {
            // Pega a escala mais "forte" — ordena por peso heurístico (milhões > mil > centenas)
            usort($escalas, fn($a, $b) => $b['peso'] <=> $a['peso']);
            $resultado['escala'] = $escalas[0];
        }

        return $resultado;
    }

    /**
     * Score pra detectar DIFERENCIAL (insight exclusivo da fonte).
     * Dispara pra palavras que indicam NOVIDADE/AUTOMATIZAÇÃO/UNICIDADE.
     */
    private static array $triggersDiferencial = [
        'pela primeira vez', 'nunca antes', 'é o primeiro', 'é o único',
        'automático', 'automática', 'automaticamente', 'sem precisar', 'sem declarar',
        'novidade', 'nova regra', 'nova modalidade', 'mudança', 'altera', 'aprovado agora',
        'recém-aprovado', 'entra em vigor', 'passa a valer', 'inédito', 'inédita',
        'exclusivo', 'exclusiva', 'somente em', 'só disponível',
        'cashback', 'crédito automático', 'depósito direto', 'liberação direta',
    ];

    private static function scoreDiferencial(string $p): int
    {
        $lower = mb_strtolower($p, 'UTF-8');
        $score = 0;
        foreach (self::$triggersDiferencial as $t) {
            if (mb_strpos($lower, mb_strtolower($t, 'UTF-8')) !== false) $score += 3;
        }
        // Bônus: combina diferencial + obstáculo na MESMA frase (lead perfeito pro Discover)
        if ($score > 0 && preg_match('/\b(mas|por[ée]m|no\s+entanto|por[ée]m\s+h[aá]|s[oó]\s+que|todavia|contudo)\b/iu', $lower)) {
            $score += 3;
        }
        return $score;
    }

    /**
     * Extrai escala quantificada (milhões, mil pessoas, R$ bi) quando presente.
     * Retorna null se não houver.
     */
    private static function extrairEscala(string $p): ?array
    {
        // R$ bilhões
        if (preg_match('/\bR\$\s*[\d\.,]+\s*(bilh[õo][eé]?s?|bi\b)/iu', $p, $m)) {
            return ['valor' => trim($m[0]), 'contexto' => self::janelaContexto($p, $m[0]), 'peso' => 10];
        }
        // R$ milhões
        if (preg_match('/\bR\$\s*[\d\.,]+\s*(milh[õo][eé]?s?|mi\b)/iu', $p, $m)) {
            return ['valor' => trim($m[0]), 'contexto' => self::janelaContexto($p, $m[0]), 'peso' => 8];
        }
        // N milhões de pessoas (com dígito)
        if (preg_match('/\b\d+(?:[\.,]\d+)?\s*milh[õo][eé]?s?\s+(?:de\s+)?(?:pessoas|brasileiros|trabalhadores|aposentados|beneficiários|fam[ií]lias|candidatos)/iu', $p, $m)) {
            return ['valor' => trim($m[0]), 'contexto' => self::janelaContexto($p, $m[0]), 'peso' => 9];
        }
        // "Milhões de X" (forma qualitativa sem dígito)
        if (preg_match('/\bmilh[õo][eé]?s?\s+de\s+(?:pessoas|brasileiros|trabalhadores|aposentados|beneficiários|fam[ií]lias|candidatos)/iu', $p, $m)) {
            return ['valor' => trim($m[0]), 'contexto' => self::janelaContexto($p, $m[0]), 'peso' => 7];
        }
        // N mil pessoas / mil vagas
        if (preg_match('/\b\d{1,3}(?:[\.,]\d{3})*\s*mil\s+(?:pessoas|brasileiros|vagas|candidatos|trabalhadores|aposentados|beneficiários|fam[ií]lias)/iu', $p, $m)) {
            return ['valor' => trim($m[0]), 'contexto' => self::janelaContexto($p, $m[0]), 'peso' => 6];
        }
        // N% dos X
        if (preg_match('/\b\d+(?:[\.,]\d+)?\s*%\s+d[ao]s?\s+(?:brasileiros|trabalhadores|aposentados|beneficiários|candidatos)/iu', $p, $m)) {
            return ['valor' => trim($m[0]), 'contexto' => self::janelaContexto($p, $m[0]), 'peso' => 7];
        }
        return null;
    }

    private static function janelaContexto(string $texto, string $alvo): string
    {
        $pos = mb_strpos($texto, $alvo, 0, 'UTF-8');
        if ($pos === false) return $texto;
        $ini = max(0, $pos - 40);
        $fim = min(mb_strlen($texto, 'UTF-8'), $pos + mb_strlen($alvo, 'UTF-8') + 60);
        return trim(mb_substr($texto, $ini, $fim - $ini, 'UTF-8'));
    }

    private static function scoreParagrafo(string $p): int
    {
        $lower = mb_strtolower($p, 'UTF-8');
        $score = 0;
        foreach (self::$triggers as $t) {
            $tLower = mb_strtolower($t, 'UTF-8');
            if (mb_strpos($lower, $tLower) !== false) $score += 2;
        }
        // Bônus: tem número específico (R$, %, quantidade) — adiciona concretude
        if (preg_match('/\b\d+[%.,]?\d*|R\$\s*\d+/i', $p)) $score += 1;
        // Bônus: tem temporal específico (data/dia/mês)
        if (preg_match('/\b\d{1,2}\s+de\s+(janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\b/iu', $p)) $score += 1;
        // Penalidade: parágrafo é claramente institucional ("segundo o inep", "conforme portaria")
        if (preg_match('/\b(?:segundo\s+o?|conforme\s+(?:portaria|edital))\b/iu', $p)) $score -= 1;
        return $score;
    }

    private static function palavrasGatilhoNaFrase(string $frase): array
    {
        $lower = mb_strtolower($frase, 'UTF-8');
        $achadas = [];
        foreach (self::$triggers as $t) {
            $tLower = mb_strtolower($t, 'UTF-8');
            if (mb_strpos($lower, $tLower) !== false) {
                $achadas[] = $t;
            }
        }
        return array_values(array_unique($achadas));
    }

    private static function classificarTipo(array $palavras): string
    {
        $joined = mb_strtolower(implode(' ', $palavras), 'UTF-8');
        if (preg_match('/\b(reprov|rejeit|nega|barrad|elimin|cancel|bloque|impede|exclu)/iu', $joined)) {
            return 'risco';
        }
        if (preg_match('/\b(erro|pegadinha|desatualiz|incompleto|irregular|divergência|detalhe)/iu', $joined)) {
            return 'detalhe';
        }
        if (preg_match('/\b(ficam\s+de\s+fora|ficam\s+sem|só\s+recebe|apenas\s+quem|precisa)/iu', $joined)) {
            return 'gap';
        }
        return 'outro';
    }

    /**
     * Gera a instrução pronta pra ser plugada no prompt.
     * @param array $gancho resultado de extrair()
     * @return string (vazio se nada forte achado)
     */
    /**
     * Detecta se há prazo próximo (<15 dias) mencionado nos parágrafos das fontes.
     * Retorna ['tem_prazo' => bool, 'data' => 'DD de MES', 'dias_restantes' => N] ou null.
     */
    public static function detectarPrazoProximo(array $fontes): ?array
    {
        $hoje = new DateTime('today');
        $mesesPt = ['janeiro'=>1,'fevereiro'=>2,'março'=>3,'marco'=>3,'abril'=>4,'maio'=>5,'junho'=>6,'julho'=>7,'agosto'=>8,'setembro'=>9,'outubro'=>10,'novembro'=>11,'dezembro'=>12];

        $maisProximo = null;
        foreach ($fontes as $f) {
            $paras = $f['content']['paragraphs'] ?? [];
            if (!is_array($paras)) continue;
            $textoFonte = implode(' ', $paras);
            // Captura "[dia] de [mês]"
            if (preg_match_all('/\b(\d{1,2})\s+de\s+(janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\b/iu', $textoFonte, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    $dia = (int)$m[1];
                    $mes = $mesesPt[mb_strtolower($m[2], 'UTF-8')] ?? 0;
                    if ($mes === 0) continue;
                    $ano = (int)date('Y');
                    try {
                        $data = new DateTime("{$ano}-{$mes}-{$dia}");
                        if ($data < $hoje) {
                            // Se já passou este ano, pode ser que a fonte se refira ao próximo ano
                            $data = new DateTime(($ano + 1) . "-{$mes}-{$dia}");
                        }
                        $diff = (int)$hoje->diff($data)->format('%r%a');
                        if ($diff >= 0 && $diff <= 15) {
                            if ($maisProximo === null || $diff < $maisProximo['dias_restantes']) {
                                $maisProximo = [
                                    'tem_prazo' => true,
                                    'data' => $m[1] . ' de ' . $m[2],
                                    'dias_restantes' => $diff,
                                ];
                            }
                        }
                    } catch (Throwable $e) {}
                }
            }
        }
        return $maisProximo;
    }

    public static function instrucaoProPrompt(array $gancho): string
    {
        $tipoLegivel = [
            'risco'   => 'RISCO DE REJEIÇÃO',
            'detalhe' => 'DETALHE/ERRO QUE ELIMINA',
            'gap'     => 'GAP DE EXCLUSÃO',
            'outro'   => 'GANCHO DE ALTO CTR',
        ][$gancho['tipo'] ?? 'outro'] ?? 'GANCHO';

        $out = '';

        // Bloco 1: gancho primário (risco/gap)
        if (!empty($gancho['palavras']) && ($gancho['score'] ?? 0) >= 3) {
            $palavras = implode(', ', array_slice($gancho['palavras'], 0, 5));
            $out .= "\n═══ GANCHO DE ALTO CTR IDENTIFICADO NA FONTE ═══\n"
                  . "TIPO: {$tipoLegivel}\n"
                  . "PALAVRAS-CHAVE: {$palavras}\n"
                  . "FRASE EXEMPLO DA FONTE: \"{$gancho['frase']}\"\n\n"
                  . "OBRIGATÓRIO: este gancho DEVE aparecer no TÍTULO ou na 1ª linha do LEAD. "
                  . "Não enterre no meio do artigo. O fato mais óbvio (prazo/data) é o que TODO portal tem — "
                  . "a diferenciação vem desse gancho específico. Use as palavras-chave acima de forma natural, "
                  . "reescrevendo com suas palavras.\n"
                  . "═══ FIM GANCHO ═══\n";
        }

        // Bloco 2: DIFERENCIAL — insight único da fonte (cashback automático, regra inédita, etc)
        if (!empty($gancho['diferencial']) && ($gancho['diferencial']['score'] ?? 0) >= 3) {
            $out .= "\n═══ DIFERENCIAL EXCLUSIVO DA FONTE (use no topo) ═══\n"
                  . "FRASE: \"{$gancho['diferencial']['frase']}\"\n\n"
                  . "Este é o INSIGHT ÚNICO desse artigo — o que nenhum outro portal vai enfatizar da mesma forma. "
                  . "Coloque esse diferencial no TÍTULO ou no 1º parágrafo do LEAD. NÃO descreva como guia geral; "
                  . "puxe o diferencial pra frente. Isso é o que vai separar seu artigo dos 50 outros na SERP.\n"
                  . "═══ FIM DIFERENCIAL ═══\n";
        }

        // Bloco 3: ESCALA — volumetria forte disponível (milhões, R$ bi, N mil)
        if (!empty($gancho['escala'])) {
            $v = $gancho['escala']['valor'];
            $ctx = $gancho['escala']['contexto'];
            $out .= "\n═══ ESCALA QUANTIFICADA (use no lead de alto impacto) ═══\n"
                  . "ESCALA: {$v}\n"
                  . "CONTEXTO: \"{$ctx}\"\n\n"
                  . "Essa escala deve aparecer no LEAD (1º parágrafo) combinada com o obstáculo/consequência. "
                  . "Fórmula estrutural: [escala] + [ação positiva] + [mas] + [obstáculo específico]. "
                  . "Ex: \"{$v} vão receber X — mas [detalhe] impede Y.\" "
                  . "Escala no topo aumenta CTR dramaticamente no Discover.\n"
                  . "═══ FIM ESCALA ═══\n";
        }

        return $out;
    }
}
