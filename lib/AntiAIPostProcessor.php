<?php
declare(strict_types=1);

/**
 * AntiAIPostProcessor — limpeza determinística pós-Sonnet/Haiku.
 *
 * Remove frases banidas, travessões e excesso de reticências via find-replace literal.
 * Garante 0% de padrões IA conhecidos no output (zera o que LLM ignora).
 *
 * Ordem no pipeline:
 *   Sonnet → AntiAI valida → se fail, Haiku revisa → AntiAI re-valida → AntiAIPostProcessor
 *   → AntiAI valida final → gate publish/draft
 *
 * Filosofia: LLM pode ser persuadido (prompt) mas vai vazar 3-5% das vezes.
 * Regex find-replace literal vaza 0%. Esta é a rede de segurança.
 *
 * Uso:
 *   $r = AntiAIPostProcessor::limpar($html);
 *   echo $r['html'];   // HTML limpo
 *   var_dump($r['log']); // o que foi alterado
 */
class AntiAIPostProcessor
{
    /**
     * @param string $html      HTML do artigo
     * @param string $titulo    Título do artigo (usado pra decidir se msg-card é legítimo)
     * @return array {html, log, mudou}
     */
    public static function limpar(string $html, string $titulo = ''): array
    {
        $original = $html;
        $log = [
            'phrases' => [],
            'travessoes' => 0,
            'reticencias_extras' => 0,
            'msg_cards_removidos' => 0,
        ];

        // Dedup semântico: remove perguntas do FAQ <details> que duplicam tema dos h2s do corpo.
        // Caso real 04/05 #4727: h2 "Plataforma Aprenda Mais ou AVAMEC: qual acessar?" + FAQ
        // pergunta similar = redundância semântica. Google penaliza repetição.
        $resDedup = self::dedupFaqVsH2($html);
        $html = $resDedup['html'];
        $log['faq_perguntas_removidas'] = $resDedup['removidas'];

        // Detector msg-card indevido — só permitido se título contém trigger keyword.
        // Caso real 04/05: posts #4680/#4688 (curso de IA p/ professores) ganharam msg-card
        // num número de WhatsApp/contato indevido. Sonnet ignorou regra do prompt.
        if ($titulo !== '' && self::contemMsgCard($html) && !self::tituloPermiteMsgCard($titulo)) {
            $count = 0;
            $html = preg_replace_callback(
                '#<div\b[^>]*\bclass=[\'"][^\'"]*\bmsg-card\b[^\'"]*[\'"][^>]*>.*?</div>\s*</div>#is',
                function () use (&$count) { $count++; return ''; },
                $html
            ) ?? $html;
            // Pattern alternativo (fallback se aninhamento der ruim) — remove qualquer div com msg-card
            $html = preg_replace_callback(
                '#<div\b[^>]*\bclass=[\'"][^\'"]*\bmsg-card\b[^\'"]*[\'"][^>]*>(?:(?!</div>).)*?</div>#is',
                function () use (&$count) { $count++; return ''; },
                $html
            ) ?? $html;
            $log['msg_cards_removidos'] = $count;
        }

        // 1. Preservar blocos <script>, <style>, <code> — não tocar conteúdo
        $preservados = [];
        $html = preg_replace_callback(
            '#<(script|style|code)\b[^>]*>.*?</\1>#is',
            function ($m) use (&$preservados) {
                $token = '@@@PRESERVED_' . count($preservados) . '@@@';
                $preservados[$token] = $m[0];
                return $token;
            },
            $html
        ) ?: $html;

        // 2. Reticências: contar e manter só a 1ª. `.{3,}` ou `…` viram `.`
        $count = 0;
        $html = preg_replace_callback(
            '/\.{3,}|…/u',
            function ($m) use (&$count, &$log) {
                $count++;
                if ($count === 1) return $m[0];
                $log['reticencias_extras']++;
                return '.';
            },
            $html
        ) ?? $html;

        // 3. Frases banidas — find-replace literal preservando capitalização inicial
        foreach (self::mapaSubstituicoes() as $banida => $sub) {
            $pattern = '/(?<![\w\-])' . preg_quote($banida, '/') . '(?![\w\-])/iu';
            $alterado = 0;
            $html = preg_replace_callback($pattern, function ($m) use ($sub) {
                if ($sub === '') return '';
                $orig = $m[0];
                // Preserva capitalização do início se original começa maiúsculo
                if (preg_match('/^[A-ZÁÉÍÓÚÂÊÔÀÃÕÇ]/u', $orig)) {
                    return mb_strtoupper(mb_substr($sub, 0, 1)) . mb_substr($sub, 1);
                }
                return $sub;
            }, $html, -1, $alterado) ?? $html;
            if ($alterado > 0) {
                $log['phrases'][$banida] = ['sub' => $sub, 'count' => $alterado];
            }
        }

        // 4. Travessões `—` e en-dash `–` → vírgula. Aplica em TODO o HTML (h1 incluso).
        $travessoesAntes = substr_count($html, '—') + substr_count($html, '–');
        if ($travessoesAntes > 0) {
            $html = str_replace(['—', '–'], ',', $html);
            $log['travessoes'] = $travessoesAntes;
        }

        // 5. Cleanup de artefatos da remoção de frases:
        //    - vírgulas duplicadas → vírgula
        //    - ponto + vírgula sequência → ponto
        //    - espaços múltiplos → 1 espaço
        //    - vírgula no início de parágrafo (depois de `<p>`)
        //    - `, ` antes de `.` ou `!` ou `?`
        $html = preg_replace('/,\s*,+/u', ',', $html) ?? $html;
        $html = preg_replace('/\.\s*,/u', '.', $html) ?? $html;
        $html = preg_replace('/,\s*([.!?])/u', '$1', $html) ?? $html;
        $html = preg_replace('/(<p[^>]*>)\s*,\s*/u', '$1', $html) ?? $html;
        $html = preg_replace('/(<h[1-6][^>]*>)\s*,\s*/u', '$1', $html) ?? $html;
        $html = preg_replace('/(<li[^>]*>)\s*,\s*/u', '$1', $html) ?? $html;
        // Espaço duplo (não em pre/code que estão preservados)
        $html = preg_replace('/[ \t]+/u', ' ', $html) ?? $html;
        // Capitalizar 1ª letra de parágrafo se ficou minúscula após remoção
        $html = preg_replace_callback(
            '/(<p[^>]*>)\s*([a-záéíóúâêôàãõç])/u',
            fn($m) => $m[1] . mb_strtoupper($m[2]),
            $html
        ) ?? $html;

        // 6. Restaurar blocos preservados
        foreach ($preservados as $token => $bloco) {
            $html = str_replace($token, $bloco, $html);
        }

        return [
            'html' => $html,
            'log' => $log,
            'mudou' => ($html !== $original),
        ];
    }

    private static function contemMsgCard(string $html): bool
    {
        return stripos($html, 'msg-card') !== false;
    }

    /**
     * Dedup semântico FAQ vs H2 do corpo.
     * Para cada <details><summary>Pergunta</summary> dentro de seção FAQ, verifica se
     * algum h2 do desenvolvimento (não-FAQ) cobre o MESMO tema (Jaccard de palavras-chave
     * >= 0.5). Se sim, remove o <details> redundante.
     */
    private static function dedupFaqVsH2(string $html): array
    {
        $removidas = 0;
        // Coleta h2s do CORPO (excluindo h2 de FAQ)
        $h2sCorpo = [];
        if (preg_match_all('/<h2[^>]*>([\s\S]*?)<\/h2>/i', $html, $m)) {
            foreach ($m[1] as $h2Inner) {
                $txt = mb_strtolower(trim(strip_tags(html_entity_decode($h2Inner, ENT_QUOTES, 'UTF-8'))));
                // Pula se for h2 de FAQ
                if (preg_match('/perguntas?\s*frequentes?|faq|d[úu]vidas?/iu', $txt)) continue;
                $h2sCorpo[] = self::tokenizar($txt);
            }
        }
        if (empty($h2sCorpo)) return ['html' => $html, 'removidas' => 0];

        // Para cada <details><summary>Pergunta</summary>...</details>, comparar
        $html = preg_replace_callback(
            '#<details\b[^>]*>\s*<summary[^>]*>(.+?)</summary>([\s\S]*?)</details>#i',
            function ($match) use ($h2sCorpo, &$removidas) {
                $pergunta = mb_strtolower(trim(strip_tags(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'))));
                $tokensP = self::tokenizar($pergunta);
                if (count($tokensP) < 2) return $match[0]; // pergunta muito curta, manter

                foreach ($h2sCorpo as $tokensH2) {
                    if (count($tokensH2) < 2) continue;
                    $jacc = self::jaccard($tokensP, $tokensH2);
                    if ($jacc >= 0.5) {
                        $removidas++;
                        return ''; // remove esse <details>
                    }
                }
                return $match[0];
            },
            $html
        ) ?? $html;

        return ['html' => $html, 'removidas' => $removidas];
    }

    private static function tokenizar(string $s): array
    {
        $s = strtr($s, 'áéíóúâêôàãõçÁÉÍÓÚÂÊÔÀÃÕÇ', 'aeiouaeoaaocAEIOUAEOAAOC');
        $s = preg_replace('/[^\w\s]/u', ' ', $s) ?? $s;
        $stopwords = ['de','da','do','das','dos','o','a','os','as','um','uma','e','ou','que','com','para','por','no','na','nos','nas','em','é','ser','estar','ter','tem','seu','sua','seus','suas','isso','esse','essa','este','esta','qual','como','onde','quando','por','que','quem','não','sim'];
        $tokens = array_filter(
            array_map('mb_strtolower', preg_split('/\s+/u', trim($s))),
            fn($t) => $t && mb_strlen($t) > 2 && !in_array($t, $stopwords, true)
        );
        return array_unique(array_values($tokens));
    }

    private static function jaccard(array $a, array $b): float
    {
        if (empty($a) || empty($b)) return 0.0;
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? $inter / $union : 0.0;
    }

    /**
     * Título permite msg-card APENAS se contém trigger de "lista de mensagens copiáveis".
     * Critério: artigos de Dia das Mães/Pais, parabéns, frases prontas, citações.
     */
    private static function tituloPermiteMsgCard(string $titulo): bool
    {
        $low = mb_strtolower($titulo, 'UTF-8');
        $triggers = [
            'mensagem', 'mensagens', 'frase', 'frases', 'citação', 'citações', 'citacao', 'citacoes',
            'homenagem', 'homenagens', 'declaração', 'declaracoes', 'declarações',
            'texto pronto', 'textos prontos', 'parabéns', 'parabens',
            'feliz aniversário', 'feliz aniversario', 'feliz natal', 'feliz ano novo',
            'dia das mães', 'dia das maes', 'dia dos pais', 'dia dos professores',
            'recado', 'recados', 'versículo', 'versiculos', 'versículos',
            'oração', 'oracao', 'orações', 'oracoes',
        ];
        foreach ($triggers as $t) {
            if (mb_strpos($low, $t) !== false) return true;
        }
        return false;
    }

    /**
     * Mapa frase banida → substituto (ou '' pra remover).
     * Mantém vivo só substitutos que não ativam outros gatilhos do AntiAI.
     */
    private static function mapaSubstituicoes(): array
    {
        return [
            // ── Conectores robóticos ──
            'em resumo' => 'resumindo',
            'em síntese' => 'resumindo',
            'em conclusão' => 'pra fechar',
            'em última análise' => '',
            'em última instância' => '',
            'em contrapartida' => 'em troca',
            'em contrapartida a isso' => 'em troca',
            'diante disso' => '',
            'diante desse cenário' => '',
            'diante desse contexto' => '',
            'diante de tudo isso' => '',
            'diante do exposto' => '',
            'vale destacar' => '',
            'vale ressaltar' => '',
            'vale lembrar' => '',
            'vale mencionar' => '',
            'vale notar' => '',
            'vale observar' => '',
            'vale a pena destacar' => '',
            'vale a pena mencionar' => '',
            'vale a pena lembrar' => '',
            'vale dizer' => '',
            'vale comentar' => '',
            'cabe destacar' => '',
            'cabe ressaltar' => '',
            'cabe mencionar' => '',
            'cabe lembrar' => '',
            'cabe pontuar' => '',
            'é importante destacar' => '',
            'é importante ressaltar' => '',
            'é importante mencionar' => '',
            'é importante lembrar' => '',
            'é importante notar' => '',
            'é importante observar' => '',
            'é fundamental destacar' => '',
            'é fundamental ressaltar' => '',
            'é fundamental lembrar' => '',
            'é essencial destacar' => '',
            'é essencial ressaltar' => '',
            'nesse contexto' => '',
            'neste contexto' => '',
            'nesse sentido' => '',
            'neste sentido' => '',
            'nesse cenário' => '',
            'neste cenário' => '',
            'nesse aspecto' => '',
            'neste aspecto' => '',
            'sob esse prisma' => '',
            'sob essa ótica' => '',
            'sob essa perspectiva' => '',
            'dessa forma' => 'assim',
            'dessa maneira' => 'assim',
            'desse modo' => 'assim',
            'desse jeito' => 'assim',
            'dessa feita' => 'assim',
            'portanto' => 'por isso',
            'por conseguinte' => 'por isso',
            'por essa razão' => 'por isso',
            'por esse motivo' => 'por isso',
            'ademais' => 'também',
            'outrossim' => 'também',
            'dito isso' => '',
            'isto posto' => '',

            // ── Clichês de abertura ──
            'a verdade é que' => '',
            'mas tem um detalhe que quase ninguém' => '',
            'mas tem um detalhe que muita gente' => '',
            'mas tem um detalhe que ninguém' => '',
            'e é aqui que muita gente erra' => '',
            'é aqui que a maioria erra' => '',
            'a maioria descobre tarde demais' => '',
            'só que isso muda tudo' => '',
            'simples assim' => '',
            // Mais clichês "o que X / X percebe" — frequentes em geração 04/05
            'o que ninguém te conta' => '',
            'o que ninguem te conta' => '',
            'o que ninguém imagina' => '',
            'o que ninguem imagina' => '',
            'o que quase ninguém percebe' => '',
            'o que quase ninguem percebe' => '',
            'o que poucos sabem' => '',
            'o que poucos percebem' => '',
            'o que muita gente desconhece' => '',
            'o que muita gente ignora' => '',
            'o que pouca gente sabe' => '',
            'o que pouca gente percebe' => '',
            'isso que ninguém' => 'isso que',
            'isso que poucos' => 'isso que',

            // ── Template narrativo LLM ──
            'tem gente que' => '',
            'tem gente em' => '',
            'fica de fora' => 'perde a vaga',
            'ficou de fora' => 'perdeu a vaga',
            'fiquem de fora' => 'percam a vaga',
            'quem tenta' => '',
            'quem busca' => '',
            'quem espera' => '',
            'quem precisa' => '',
            'quem chega' => '',
            'descobre rapidamente' => 'descobre',
            'descobre logo' => 'descobre',
            'animada com a vaga' => '',
            'empolgado com a vaga' => '',
            'esperançoso com' => '',

            // ── Teasers isolados ──
            'mas tem um detalhe' => '',
            'tem um porém' => '',
            'tem um detalhe importante' => '',
            'mas atenção' => '',
            'mas calma' => '',
            'mas espera' => '',
            'mas tem mais' => '',
            'aí entra o problema' => '',
            'aí está o ponto' => '',
            'aí está o detalhe' => '',
            'e não para por aí' => '',
            'e não acaba aí' => '',
            'e tem mais' => '',
            'mas a história não termina' => '',
            'a história não para' => '',
            'eis o ponto' => '',
            'eis a questão' => '',
            'eis o detalhe' => '',

            // ── Fillers narrativos ──
            'na prática' => '',
            'na real' => '',
            'no fim das contas' => '',
            'no final das contas' => '',
            'logo de cara' => '',
            'já de cara' => '',
            'rapidamente' => '',
            'mesmo assim' => '',
            'sem perceber' => '',
            'sem nem perceber' => '',
            'acaba descobrindo' => 'descobre',
            'acabam descobrindo' => 'descobrem',

            // ── Promessa vaga ──
            'esse detalhe' => 'essa parte',
            'esse erro' => 'esse engano',
            'esse ponto' => 'essa parte',
            'esse problema' => 'isso',
            'esse critério' => 'esse requisito',
            'esse filtro' => 'essa exigência',
            // Padrões "o X que / um X que" — vague_promise sem qualificador concreto
            'o erro que' => 'o engano que',
            'um erro que' => 'um engano que',
            'o detalhe que' => 'a parte que',
            'um detalhe que' => 'a parte que',
            'o problema que' => 'a questão que',
            'um problema que' => 'a questão que',
            'o ponto que' => 'a parte que',
            'um ponto que' => 'a parte que',
            'o critério que' => 'o requisito que',
            'um critério que' => 'um requisito que',
            'o filtro que' => 'a exigência que',
            'um filtro que' => 'uma exigência que',

            // ── Adjetivos vazios isolados ──
            'imperdível' => '',
            'incrível' => '',
            'revolucionário' => '',
            'surpreendente' => '',
            'transformador' => '',
            'magnífico' => '',
            'extraordinário' => '',
            'memorável' => '',

            // ── Self-reference ──
            'veja a seguir' => '',
            'veja abaixo' => '',
            'veja agora' => '',
            'confira a seguir' => '',
            'confira abaixo' => '',
            'confira agora' => '',
            'leia a seguir' => '',
            'leia abaixo' => '',
            'leia adiante' => '',
            'descubra a seguir' => '',
            'descubra abaixo' => '',
            'descubra agora' => '',
            'clique aqui' => '',
            'clique abaixo' => '',
            'continue lendo abaixo' => '',
            'continue a leitura' => '',
            'siga lendo' => '',

            // ── Gerundismo ──
            'estar fazendo' => 'fazer',
            'estarão recebendo' => 'vão receber',
            'estarão analisando' => 'vão analisar',
            'estará buscando' => 'vai buscar',
            'estará realizando' => 'vai realizar',
            'vai estar acompanhando' => 'vai acompanhar',
            'vai estar verificando' => 'vai verificar',

            // ── Pomposo ──
            'outrora' => 'antes',
            'doravante' => 'a partir de agora',
            'destarte' => 'assim',

            // ── Filler genérico ──
            'de forma geral' => '',
            'de modo geral' => '',
            'de qualquer maneira' => '',
            'de qualquer forma' => '',
            'seja como for' => '',
            'em todo caso' => '',
            'em todo o caso' => '',

            // ── Meta-narrativa ──
            'continue lendo' => '',
            'continue acompanhando' => '',
            'antes de mais nada' => '',
            'fica a dica' => '',
        ];
    }
}
