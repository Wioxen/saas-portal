<?php
/**
 * Validador de título pra Discover 9.5+.
 *
 * Critérios (cada um vale 1 ponto, total 10):
 *  1. Comprimento entre 55 e 70 chars
 *  2. Tem número concreto (R$, valor, data, quantidade)
 *  3. Tem ano do tema sazonal (2026, 2027...) se aplicável
 *  4. Tem verbo de ação/prazo (encerra, termina, libera, começa, corre, paga, perde)
 *  5. Tem consequência ou segmentação (quem, só pra, ficam de fora, paga a mais, sem)
 *  6. Usa pontuação permitida como separador principal (:, ;, parênteses)
 *  7. NÃO tem travessão (—) nem en-dash (–)
 *  8. NÃO começa com adjetivo vazio (incrível, imperdível, surpreendente, revolucionário)
 *  9. NÃO é pergunta genérica (começa com "Sabe...", "Você sabia...")
 * 10. Primeira palavra é substantivo/sujeito concreto (keyword do tema)
 *
 * Uso:
 *   $r = DiscoverTituloValidator::avaliar('Isenção do ENEM 2026 encerra dia 24: quem perder paga a taxa');
 *   // => ['score' => 9, 'falhas' => ['ano_sazonal'], 'aprovado' => true]
 */
class DiscoverTituloValidator
{
    private const LIMIAR_APROVACAO = 8; // 8/11 = aprovado (critério diferenciação agregado)

    /** Verbos de ação/prazo que dão urgência real. */
    private static array $verbosAcao = [
        'encerra','termina','acaba','libera','começa','comeca','corre','paga','perde',
        'vence','abre','fecha','anuncia','aprova','rejeita','suspende','retoma','autoriza',
        'conclui','lança','lanca','amplia','reduz','dobra','triplica','sobe','cai',
    ];

    /** Marcadores de consequência ou segmentação. */
    private static array $marcadoresConsequencia = [
        // consequência
        'quem perder','quem não','quem ainda','quem fica','ficam de fora','ficam fora',
        'paga a mais','paga dobrado','paga a taxa','perde o direito','perde o prazo',
        'sem direito','sem receber','sem aviso','sem acesso','sem conseguir',
        'mas só','apenas quem','só pra quem','só para quem','só vale',
        'mas tem','mas exige','mas precisa','deixa de','impedem','barra','elimina',
        // segmentação
        'nascidos em','trabalhadores','aposentados','beneficiários','famílias',
        'moradores','candidatos','estudantes','idosos','mulheres','homens',
    ];

    /** Adjetivos vazios proibidos no início. */
    private static array $adjetivosVazios = [
        'incrível','incrivel','imperdível','imperdivel','revolucionário','revolucionario',
        'surpreendente','impressionante','fantástico','fantastico','maravilhoso',
        'inacreditável','inacreditavel','sensacional','espetacular','histórico','historico',
    ];

    /** Início de pergunta genérica. */
    private static array $perguntasGenericas = [
        'sabe como','você sabia','sabia que','descubra como','entenda como',
    ];

    /**
     * Avalia um título.
     * @param string $titulo
     * @param array  $ganchoPalavras palavras-chave do gancho da fonte (opcional) — se passadas,
     *                               título ganha ponto quando inclui pelo menos uma delas.
     */
    public static function avaliar(string $titulo, array $ganchoPalavras = []): array
    {
        $t = trim($titulo);
        $lower = mb_strtolower($t, 'UTF-8');
        $len = mb_strlen($t, 'UTF-8');
        $falhas = [];
        $score = 0;

        // 1. Comprimento
        if ($len >= 55 && $len <= 70) $score++; else $falhas[] = 'comprimento';
        // 2. Número concreto
        if (preg_match('/\d/', $t)) $score++; else $falhas[] = 'numero';
        // 3. Ano do tema sazonal (reprovação só se claramente sazonal sem ano; heurística leve)
        // Só conta ponto se o título já tem um ano. Não penaliza duro se não tem.
        $anoAtual = (int)date('Y');
        $anoProx = $anoAtual + 1;
        if (preg_match('/\b(' . $anoAtual . '|' . $anoProx . '|' . ($anoAtual - 1) . ')\b/', $t)) $score++;
        else $falhas[] = 'ano_sazonal';
        // 4. Verbo de ação/prazo
        $temVerbo = false;
        foreach (self::$verbosAcao as $v) {
            if (preg_match('/\b' . preg_quote($v, '/') . '\b/iu', $lower)) { $temVerbo = true; break; }
        }
        if ($temVerbo) $score++; else $falhas[] = 'verbo_acao';
        // 5. Consequência ou segmentação
        $temConsequencia = false;
        foreach (self::$marcadoresConsequencia as $m) {
            if (strpos($lower, mb_strtolower($m, 'UTF-8')) !== false) { $temConsequencia = true; break; }
        }
        if ($temConsequencia) $score++; else $falhas[] = 'consequencia';
        // 6. Pontuação permitida (:, ;, parênteses, vírgula) como separador — ganha ponto se tem pelo menos 1
        if (preg_match('/[:;()]/', $t) || preg_match('/,\s/', $t)) $score++;
        else $falhas[] = 'separador';
        // 7. SEM travessão / en-dash
        if (!preg_match('/[—–]/u', $t)) $score++; else $falhas[] = 'tem_travessao';
        // 8. NÃO começa com adjetivo vazio
        $primeiraPalavra = mb_strtolower(preg_split('/\s+/', $t)[0] ?? '', 'UTF-8');
        if (!in_array($primeiraPalavra, self::$adjetivosVazios, true)) $score++;
        else $falhas[] = 'adjetivo_vazio';
        // 9. NÃO é pergunta genérica
        $temPerguntaGenerica = false;
        foreach (self::$perguntasGenericas as $p) {
            if (strpos($lower, $p) === 0) { $temPerguntaGenerica = true; break; }
        }
        if (!$temPerguntaGenerica) $score++; else $falhas[] = 'pergunta_generica';
        // 10. Primeiras 5 palavras contêm sujeito/keyword (heurística: não são só stopwords)
        $primeiras = array_slice(preg_split('/\s+/', $t), 0, 5);
        $stopwords = ['o','a','os','as','um','uma','de','do','da','em','e','ou','para','pra','por','com'];
        $temSubstantivo = false;
        foreach ($primeiras as $p) {
            if (mb_strlen($p, 'UTF-8') >= 5 && !in_array(mb_strtolower($p, 'UTF-8'), $stopwords, true)) {
                $temSubstantivo = true; break;
            }
        }
        if ($temSubstantivo) $score++; else $falhas[] = 'sem_substantivo_inicial';

        // 11. DIFERENCIAÇÃO — título não pode ser "portal-padrão" (só keyword + data + verbo de prazo).
        //    Ganha ponto se tem pelo menos 1 das condições abaixo:
        //    a) Inclui palavra-chave do gancho extraído da fonte (quando passado)
        //    b) Inclui marcador de risco/gap/consequência específica (CadÚnico, multa, erro, pegadinha, reprova, etc)
        //    c) Tem número/valor concreto ALÉM de data (R$, %, quantidade) — dupla especificidade
        $diferenciado = false;
        // a) casamento com gancho da fonte
        if (!empty($ganchoPalavras)) {
            foreach ($ganchoPalavras as $gp) {
                if (mb_strpos($lower, mb_strtolower($gp, 'UTF-8')) !== false) { $diferenciado = true; break; }
            }
        }
        // b) marcadores genéricos de risco/gap específico
        if (!$diferenciado) {
            $marcadoresRisco = [
                'cadúnico','cadunico','cpf pendente','recadastramento','biometria','prova de vida',
                'multa','juros','reprova','reprovado','rejeita','rejeitado','negado','bloqueia',
                'elimina','barra','desatualizado','desatualizada','pegadinha','detalhe',
                'erro','exclui','ficam de fora','só recebe','apenas quem','passa batido',
                'paga em dobro','taxa cheia','taxa integral',
            ];
            foreach ($marcadoresRisco as $m) {
                if (mb_strpos($lower, $m) !== false) { $diferenciado = true; break; }
            }
        }
        // c) dupla especificidade: número além da data e do ano
        if (!$diferenciado) {
            $temData = preg_match('/\b\d{1,2}\s+de\s+(janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro|jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez)\b/iu', $t)
                    || preg_match('/\bdia\s+\d{1,2}\b/iu', $t);
            // Valor = R$, % ou número grande que NÃO seja ano (2024-2030 não contam)
            $temValor = preg_match('/R\$\s*\d+/', $t) || preg_match('/\b\d+\s*%/', $t);
            if (!$temValor && preg_match_all('/\b(\d{3,})\b/', $t, $mms)) {
                foreach ($mms[1] as $nn) {
                    $ni = (int)$nn;
                    if ($ni < 2010 || $ni > 2040) { $temValor = true; break; } // número "real", não ano
                }
            }
            if ($temData && $temValor) $diferenciado = true;
        }
        if ($diferenciado) $score++; else $falhas[] = 'sem_diferenciacao';

        return [
            'score'     => $score,
            'total'     => 11,
            'falhas'    => $falhas,
            'aprovado'  => $score >= self::LIMIAR_APROVACAO,
            'limiar'    => self::LIMIAR_APROVACAO,
            'comprimento' => $len,
        ];
    }

    /** Diagnóstico legível pra logs/UI. */
    public static function diagnostico(string $titulo, array $ganchoPalavras = []): string
    {
        $r = self::avaliar($titulo, $ganchoPalavras);
        $status = $r['aprovado'] ? '✓ APROVADO' : '✗ REPROVADO';
        return sprintf(
            "[%s] %d/%d (%d chars). Falhas: %s",
            $status,
            $r['score'],
            $r['total'],
            $r['comprimento'],
            $r['falhas'] ? implode(', ', $r['falhas']) : 'nenhuma'
        );
    }
}
