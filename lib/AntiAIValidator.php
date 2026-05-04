<?php
declare(strict_types=1);

/**
 * AntiAIValidator — detecta padrões "AI tells" no HTML gerado pelo cluster.
 *
 * Uso:
 *   $v = new AntiAIValidator();
 *   $report = $v->validate($html);
 *   if ($report['severity'] === 'fail') { ... regerar ou rejeitar ... }
 *
 * Camadas:
 *   1. Blacklist de FRASES (vale destacar, em suma, nesse contexto, etc.)
 *   2. Blacklist de PADRÕES ESTRUTURAIS (H2s repetindo palavra inicial,
 *      parágrafos com comprimento uniforme = ritmo robótico)
 *   3. Severidade: ok | warn | fail
 */
class AntiAIValidator
{
    /** @var array<string,array<int,string>> */
    private array $blacklist;

    public function __construct(?array $customBlacklist = null)
    {
        $this->blacklist = $customBlacklist ?? $this->defaultBlacklist();
    }

    public function defaultBlacklist(): array
    {
        return [
            'connectors_robot' => [
                /* família "vale" */
                'vale destacar', 'vale ressaltar', 'vale lembrar', 'vale mencionar',
                'vale notar', 'vale observar', 'vale a pena destacar', 'vale a pena mencionar',
                'vale dizer', 'vale a pena lembrar', 'vale comentar',
                /* família "cabe" */
                'cabe destacar', 'cabe ressaltar', 'cabe mencionar', 'cabe lembrar', 'cabe pontuar',
                /* família "é importante / é fundamental" */
                'é importante destacar', 'é importante ressaltar', 'é importante mencionar',
                'é importante lembrar', 'é importante notar', 'é importante observar',
                'é fundamental destacar', 'é fundamental ressaltar', 'é fundamental lembrar',
                'é essencial destacar', 'é essencial ressaltar',
                /* família "diante" */
                'diante disso', 'diante desse cenário', 'diante desse contexto',
                'diante de tudo isso', 'diante do exposto',
                /* família "em" */
                'em suma', 'em síntese', 'em conclusão', 'em resumo', 'em última análise',
                'em síntese final', 'em última instância',
                /* família "nesse / neste" */
                'nesse contexto', 'neste contexto', 'nesse sentido', 'neste sentido',
                'nesse cenário', 'neste cenário', 'nesse aspecto', 'neste aspecto',
                'sob esse prisma', 'sob essa ótica', 'sob essa perspectiva',
                /* família "dessa / dessa forma" */
                'dessa forma', 'dessa maneira', 'desse modo', 'desse jeito', 'dessa feita',
                /* conectores acadêmicos pesados */
                'portanto', 'por conseguinte', 'por essa razão', 'por esse motivo',
                'ademais', 'outrossim', 'dito isso', 'isto posto',
                'em contrapartida', 'em contrapartida a isso',
            ],
            'meta_narrative' => [
                'a promessa deste artigo', 'a promessa desta matéria',
                'neste texto você vai', 'neste artigo você vai',
                'ao longo deste conteúdo', 'ao longo deste artigo',
                'vamos mostrar aqui', 'vamos te mostrar', 'vamos explicar aqui',
                'este artigo traz', 'este texto traz', 'esta matéria traz',
                'continue lendo', 'continue acompanhando',
                'nas próximas linhas', 'como vamos ver a seguir',
                'antes de mais nada', 'antes de tudo é importante',
                'fica a dica', 'a dica é', 'o segredo é',
            ],
            'cliches_ia_abertura' => [
                'um critério pouco comentado', 'um detalhe pouco comentado', 'um ponto pouco comentado',
                'a maioria das pessoas não sabe', 'a maioria não sabe', 'nem todo mundo sabe',
                'você sabia que', 'poucas pessoas percebem',
                'existe um detalhe que muda tudo', 'existe um ponto importante',
                'um dado importante que passa despercebido', 'um critério oculto', 'um fator decisivo',
                'pouca gente imagina', 'quase ninguém repara', 'muita gente desconhece',
                'a verdade é que', /* só quando inicia parágrafo, mas detectamos qualquer ocorrência */
                /* Audit 2026-05-03: padrões que estavam hardcoded como OBRIGATÓRIOS no
                 * manifesto antigo (BLOCO 5, BLOCO 7) e viraram fingerprint LLM clássico */
                'o que ninguém te conta', 'o que quase ninguém percebe', 'o que ninguém imagina',
                'vale a pena agora', 'a resposta surpreende', 'só que isso muda tudo',
                'mas tem um detalhe que quase ninguém', 'mas tem um detalhe que muita gente',
                'mas tem um detalhe que ninguém',
                'e é aqui que muita gente erra', 'é aqui que a maioria erra',
                'a maioria descobre tarde demais', 'a maioria perde por isso',
                'mas quase ninguém', 'a maioria vai ficar de fora',
                'a vaga não espera', 'vagas não esperam',
                'quem chega depois, não entra', 'quem chega depois não entra',
                'parece simples. não é', 'parece simples, não é',
                'fica a dica', 'simples assim',
            ],
            'adjetivos_vazios' => [
                'imperdível', 'incrível', 'revolucionário', 'surpreendente', 'espantoso',
                'simplesmente incrível', 'absolutamente imperdível', 'transformador',
                'magnífico', 'extraordinário', 'memorável',
                /* Adjetivos genéricos LLM (gap #2 audit 2026-05-03) — banidos quando
                 * isolados; aceitar SOMENTE quando qualificados ("interessante PORQUE..."). */
                'incrível como', 'simplesmente fascinante', 'verdadeiramente único',
                'algo realmente especial', 'algo realmente único', 'algo verdadeiramente',
                'simplesmente fundamental', 'absolutamente essencial', 'altamente significativo',
                'extremamente relevante', 'verdadeiramente notável', 'realmente impressionante',
            ],
            'self_reference' => [
                /* Gap #4 audit: meta-narrative expandido — variantes "veja a seguir" etc.
                 * Frases que LLM usa pra "se localizar" no texto e o leitor odeia. */
                'veja a seguir', 'veja abaixo', 'veja agora',
                'confira a seguir', 'confira abaixo', 'confira agora',
                'leia a seguir', 'leia abaixo', 'leia adiante',
                'descubra abaixo', 'descubra a seguir', 'descubra agora',
                'aprenda abaixo', 'aprenda a seguir', 'aprenda neste',
                'clique aqui', 'clique abaixo', 'clique no link',
                'continue lendo abaixo', 'continue a leitura',
                'acompanhe nos próximos parágrafos', 'siga lendo',
                'antes de mais nada vamos', 'vamos por partes',
            ],
            'teaser_isolado' => [
                /* Gap #1 audit (apontado pelo user 2026-05-03): frase-suspense LLM
                 * usada como parágrafo único curto pra criar cadência artificial.
                 * Detectada estruturalmente em detectStructuralPatterns() —
                 * lista aqui é defesa em profundidade pra match em texto inteiro. */
                'mas tem um detalhe', 'tem um porém', 'tem um detalhe importante',
                'mas atenção', 'mas calma', 'mas espera',
                'spoiler:', 'spoiler alert:', 'mas tem mais',
                'aí entra o problema', 'aí está o ponto', 'aí está o detalhe',
                'e não para por aí', 'e não acaba aí', 'e tem mais',
                'mas a história não termina', 'a história não para',
                'eis o ponto', 'eis a questão', 'eis o detalhe',
            ],
            'gerundismo' => [
                'estar fazendo', 'estar pensando', 'estar buscando', 'estar verificando',
                'estarão recebendo', 'estarão analisando', 'estará buscando', 'estará realizando',
                'vamos estar enviando', 'vou estar verificando', 'vai estar acompanhando',
            ],
            'fillers_genericos' => [
                'de forma geral', 'de modo geral', 'no final das contas', 'no fim do dia',
                'em última instância', 'de qualquer maneira', 'de qualquer forma', 'seja como for',
                'em todo caso', 'em todo o caso',
            ],
            'pomposos_desnecessarios' => [
                'outrora', 'doravante', 'destarte', 'urge ', 'mister ',
                'far-se-á', 'dar-se-á', 'tem-se que',
            ],
            'clickbait_titulo' => [
                /* Termos que disparam "anúncios limitados" no AdSense quando aparecem no <h1>/título */
                'você não vai acreditar', 'voce nao vai acreditar',
                'o segredo de ', 'o segredo do ', 'o segredo da ',
                'descubra agora', 'antes que seja tarde',
                'truque escondido', 'truque oculto', 'truque secreto',
                'filtro oculto', 'filtro escondido',
                'detalhe escondido', 'detalhe oculto',
                'o que ninguém te conta', 'o que ninguem te conta',
                'o que ninguém percebe', 'o que ninguem percebe',
                'o que ninguém sabe', 'o que ninguem sabe',
                'esse segredo', 'esse truque',
                'jamais vista', 'nunca vista',
                'método secreto', 'fórmula mágica',
            ],
            'narrativa_template_llm' => [
                /* Templates clássicos de IA narrativa — Sonnet/GPT convergem aqui quando
                   estrutura=narrativa. Caso real: 3 posts do cluster Senac (cursosenac, 2026-05-02)
                   abriram com "Tem gente que..." / "Tem gente em X que..." / "Quem tenta...".
                   Qualquer ocorrência destes é red flag. */
                'tem gente que', 'tem gente em',
                'quem tenta', 'quem busca', 'quem espera', 'quem precisa', 'quem chega',
                'descobre rapidamente', 'descobre que', 'descobre logo',
                'ficou de fora', 'fica de fora', 'fiquem de fora',
                'antes mesmo de completar', 'antes mesmo de terminar',
                'esperou meses', 'esperaram meses', 'depois de meses', 'passou meses',
                'há tempos', 'há um tempo', 'faz tempos',
                /* "tentou X, separou Y, e Z" — fórmula enumerativa clássica IA */
                'animada com a vaga', 'empolgado com a vaga', 'esperançoso com',
            ],
            'fillers_narrativa' => [
                /* Muletas que LLM enfia no flow narrativo. Comuns em PT-BR mas
                   uso REPETIDO denuncia template. Validator marca warn pra 1-2x, fail pra 3+. */
                'rapidamente', 'mesmo assim',
                'na prática', 'na real', 'no fim das contas',
                'sem perceber', 'sem nem perceber',
                'logo de cara', 'já de cara',
                'acaba descobrindo', 'acabam descobrindo',
            ],
            'vague_promise' => [
                /* "O X que" / "Um X que" sem qualificação concreta — clickbait sutil que limita anúncios.
                   Detectados literais. Em h1/h2/h3 = red flag direto. */
                'o filtro que', 'um filtro que', 'esse filtro',
                'o erro que', 'um erro que', 'esse erro',
                'o detalhe que', 'um detalhe que', 'esse detalhe',
                'o problema que', 'um problema que', 'esse problema',
                'o ponto que', 'um ponto que', 'esse ponto',
                'o critério que', 'um critério que', 'esse critério',
                'o que quase ninguém', 'o que poucos sabem', 'o que poucos percebem',
                'o que muita gente desconhece', 'o que muita gente ignora',
                'o que pouca gente sabe', 'o que pouca gente percebe',
                'a maioria não sabe', 'a maioria não percebe',
                'isso que ninguém', 'isso que poucos',
            ],
        ];
    }

    /**
     * Validação específica de TÍTULO (h1 ou string solta).
     * Aplica blacklist de clickbait + checa estrutura mínima exigida pelo AdSense:
     *   - Tem número?
     *   - Tem nome próprio (entidade, cidade, programa)?
     *   - Length 35-80 chars
     *
     * Usado pra título antes de publicar — clickbait genérico no h1 é red flag direto pro AdSense.
     */
    public function validateTitle(string $title): array
    {
        $issues = [];
        $titleLower = mb_strtolower(trim(strip_tags($title)));
        $titleClean = trim(strip_tags($title));

        /* Blacklist clickbait */
        foreach ($this->blacklist['clickbait_titulo'] ?? [] as $phrase) {
            if (strpos($titleLower, mb_strtolower($phrase)) !== false) {
                $issues[] = ['type' => 'clickbait', 'detail' => "frase banida: \"{$phrase}\""];
            }
        }

        /* Frases genéricas/conectores também são red flag em título */
        foreach (['vale destacar','vale ressaltar','vale lembrar','é importante destacar',
                  'diante disso','em suma','em conclusão','nesse contexto'] as $phrase) {
            if (strpos($titleLower, $phrase) !== false) {
                $issues[] = ['type' => 'connector_in_title', 'detail' => "conector AI no título: \"{$phrase}\""];
            }
        }

        /* Estrutura mínima — título AdSense-safe precisa concretude */
        $hasNumber = (bool)preg_match('/\d/', $titleClean);
        /* Nome próprio: palavra com inicial maiúscula no meio (Araguaína, Senac) OU sigla 2+ caps (CPF, INSS, MEC, ENEM) OU palavra com hífen (CNH-D, Polícia-RS) */
        $hasProperNoun = (bool)preg_match('/(?<=\w\s)[A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõçA-Z]+/', $titleClean)
                       || (bool)preg_match('/\b[A-ZÁÉÍÓÚÂÊÔÃÕÇ]{2,}\b/', $titleClean)
                       || (bool)preg_match('/\b[A-ZÁÉÍÓÚÂÊÔÃÕÇ][\wÁÉÍÓÚá-ÿ]*-[A-ZÁÉÍÓÚÂÊÔÃÕÇ]+/', $titleClean);

        if (!$hasNumber && !$hasProperNoun) {
            $issues[] = ['type' => 'too_generic', 'detail' => 'título sem número E sem nome próprio — muito genérico pro AdSense'];
        }

        /* Length sanity */
        $len = mb_strlen($titleClean);
        if ($len < 25) $issues[] = ['type' => 'too_short', 'detail' => "título com {$len} chars (mínimo 25)"];
        if ($len > 95) $issues[] = ['type' => 'too_long', 'detail' => "título com {$len} chars (máximo 95)"];

        /* Travessões e exclamações em excesso */
        if (substr_count($titleClean, '—') + substr_count($titleClean, '–') > 0) {
            $issues[] = ['type' => 'em_dash_in_title', 'detail' => 'travessão (—) no título — banido'];
        }
        if (substr_count($titleClean, '!') > 1) {
            $issues[] = ['type' => 'too_many_exclamations', 'detail' => 'múltiplas exclamações'];
        }

        /* Teste de genericidade: se substituir entidade/número por placeholder, ainda faz sentido? */
        $genericityFlags = [];
        if (preg_match('/^(o|a|os|as|esse|essa|esses|essas)\s+\w+\s+que/i', $titleClean)) {
            /* Padrão "O X que [verbo]" — só é OK se X é específico (CNH, edital, etc) */
            if (!$hasProperNoun && !$hasNumber) {
                $genericityFlags[] = 'inicia com "O/A X que" sem entidade ou número específico';
            }
        }
        foreach ($genericityFlags as $f) {
            $issues[] = ['type' => 'generic_pattern', 'detail' => $f];
        }

        $severity = 'ok';
        if (count($issues) >= 3) $severity = 'fail';
        elseif (count($issues) >= 1) $severity = 'warn';

        return [
            'ok' => empty($issues),
            'severity' => $severity,
            'issues' => $issues,
            'has_number' => $hasNumber,
            'has_proper_noun' => $hasProperNoun,
            'length' => $len,
        ];
    }

    /**
     * Valida o HTML e retorna report estruturado.
     *
     * @return array{
     *   ok:bool,
     *   severity:string,
     *   total_phrase_violations:int,
     *   violations:array,
     *   structural:array
     * }
     */
    public function validate(string $html): array
    {
        $text = strip_tags(html_entity_decode($html, ENT_QUOTES|ENT_HTML5, 'UTF-8'));
        $textLower = mb_strtolower($text);
        $violations = [];
        $totalCount = 0;

        foreach ($this->blacklist as $category => $phrases) {
            foreach ($phrases as $phrase) {
                $count = mb_substr_count($textLower, mb_strtolower($phrase));
                if ($count > 0) {
                    $violations[] = [
                        'category' => $category,
                        'phrase'   => $phrase,
                        'count'    => $count,
                    ];
                    $totalCount += $count;
                }
            }
        }

        $structural = $this->detectStructuralPatterns($html);

        return [
            'ok'                       => empty($violations) && empty($structural),
            'severity'                 => $this->computeSeverity($totalCount, $structural),
            'total_phrase_violations'  => $totalCount,
            'violations'               => $violations,
            'structural'               => $structural,
        ];
    }

    /**
     * Detecta padrões estruturais robóticos:
     *   • H2s repetindo palavra inicial (template fingerprint)
     *   • Parágrafos com comprimento uniforme (ritmo robótico)
     *   • Mais de 1 ocorrência de "Além disso" no artigo
     */
    private function detectStructuralPatterns(string $html): array
    {
        $issues = [];

        /* H2s — palavra inicial repetida 3+ vezes */
        if (preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $html, $h2s)) {
            $firstWords = [];
            foreach ($h2s[1] as $h) {
                $clean = trim(strip_tags($h));
                $first = mb_strtolower(explode(' ', $clean)[0] ?? '');
                if (mb_strlen($first) > 3) $firstWords[] = $first;
            }
            $counts = array_count_values($firstWords);
            foreach ($counts as $word => $n) {
                if ($n >= 3) {
                    $issues[] = "H2s repetem palavra inicial '{$word}' {$n}x — template fingerprint";
                }
            }
        }

        /* Parágrafos uniformes — desvio padrão muito baixo = ritmo robótico */
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $ps)) {
            $lengths = [];
            foreach ($ps[1] as $p) {
                $w = str_word_count(strip_tags($p));
                if ($w >= 6) $lengths[] = $w;
            }
            if (count($lengths) >= 5) {
                $avg = array_sum($lengths) / count($lengths);
                $variance = array_sum(array_map(fn($n) => ($n - $avg) ** 2, $lengths)) / count($lengths);
                $stddev = sqrt($variance);
                if ($stddev < 4 && $avg > 20) {
                    $issues[] = sprintf('parágrafos uniformes (avg=%.0f palavras, σ=%.1f) — ritmo robótico', $avg, $stddev);
                }
            }
        }

        /* "Além disso" mais de 1 vez — banido pelo prompt */
        $alemDisso = mb_substr_count(mb_strtolower(strip_tags($html)), 'além disso');
        if ($alemDisso > 1) {
            $issues[] = "'Além disso' aparece {$alemDisso}x — máximo permitido: 1";
        }

        /* Reticências (...) — máximo 1 por artigo */
        $reticencias = substr_count($html, '...') + substr_count($html, '…');
        if ($reticencias > 1) {
            $issues[] = "reticências {$reticencias}x — máximo permitido: 1";
        }

        /* Travessões (—) no corpo — proibidos */
        $body = preg_replace('/<h1[^>]*>.*?<\/h1>/is', '', $html);
        $travessoes = substr_count($body, '—') + substr_count($body, '–');
        if ($travessoes > 0) {
            $issues[] = "travessões (—) no corpo {$travessoes}x — banidos pelo prompt";
        }

        /* GAP #1 user 2026-05-03: parágrafo ÚNICO CURTO (1 frase, <40 palavras)
         * com teaser-style ("Mas tem um detalhe", "Spoiler:", "Mas atenção"...).
         * Padrão LLM clássico pra criar suspense artificial — humano embute em
         * frase maior. Detecção estrutural: <p> isolado curto + verbos/marcadores
         * de transição abrupta. */
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $psAll)) {
            $teaserPatterns = [
                '/^\s*mas\s+(tem|atenção|calma|espera|tem\s+um|aqui|aí|nem|olha)\b/iu',
                '/^\s*spoiler\b/iu',
                '/^\s*tem\s+um\s+(porém|detalhe|problema|ponto|porm)\b/iu',
                '/^\s*aí\s+(entra|está)\s+(o|a)\b/iu',
                '/^\s*e\s+não\s+(para|acaba)\s+por\s+aí/iu',
                '/^\s*eis\s+(o|a)\s+(ponto|questão|detalhe|problema)/iu',
            ];
            $teasersAchados = [];
            foreach ($psAll[1] as $pHtml) {
                $pTexto = trim(strip_tags($pHtml));
                $pPalavras = str_word_count($pTexto);
                // Parágrafo curto: 3-40 palavras + 1 frase só (não tem . interno antes do final)
                $pTextoSemFinal = rtrim($pTexto, '.!?');
                $pontosInternos = substr_count($pTextoSemFinal, '. ');
                if ($pPalavras < 3 || $pPalavras > 40 || $pontosInternos > 0) continue;
                foreach ($teaserPatterns as $pat) {
                    if (preg_match($pat, $pTexto)) {
                        $teasersAchados[] = mb_substr($pTexto, 0, 60);
                        break;
                    }
                }
            }
            if (!empty($teasersAchados)) {
                $issues[] = "teaser-paragrafo-isolado " . count($teasersAchados) . "x — padrão LLM (suspense artificial): " . implode(' | ', array_slice($teasersAchados, 0, 3));
            }
        }

        /* GAP #3 audit: listas com EXATAMENTE 3 itens — padrão LLM clássico
         * (LLM tende ao "trio perfeito"). Avisa se >40% das listas do post
         * tem exatamente 3 li (não falha post com 1-2 listas, só com pattern). */
        if (preg_match_all('/<(ul|ol)[^>]*>(.*?)<\/\1>/is', $html, $listasMatches)) {
            $totalListas = count($listasMatches[2]);
            $listas3 = 0;
            foreach ($listasMatches[2] as $listaInner) {
                $liCount = preg_match_all('/<li\b/i', $listaInner);
                if ($liCount === 3) $listas3++;
            }
            if ($totalListas >= 3 && $listas3 / $totalListas >= 0.6) {
                $issues[] = "listas-trio-perfeito {$listas3}/{$totalListas} listas têm exatos 3 itens — fingerprint LLM";
            }
        }

        /* GAP #5 audit: densidade de conector — mesmo conector aparecendo >2x
         * no mesmo post sinaliza dependência mecânica. Lista de conectores
         * suspeitos quando repetidos. */
        $conectoresParaContagem = [
            'nesse contexto', 'neste contexto', 'dessa forma', 'desse modo',
            'por outro lado', 'além disso', 'no entanto', 'sendo assim',
            'diante disso', 'dito isso', 'isso significa que',
        ];
        $textPlain = mb_strtolower(strip_tags($body));
        foreach ($conectoresParaContagem as $conn) {
            $n = mb_substr_count($textPlain, $conn);
            if ($n >= 3) {
                $issues[] = "densidade-conector '{$conn}' {$n}x — máximo aceitável: 2";
            }
        }

        /* Headings com vague promise (H1/H2/H3 com "O filtro/erro/detalhe que" sem qualificação) */
        $headingsVagos = $this->detectarVaguenessHeadings($html);
        foreach ($headingsVagos as $issue) $issues[] = $issue;

        /* Prompt-leak da seção "Erro Fatal": o modelo regurgita os exemplos literais do
         * prompt.md ("o erro que elimina a inscrição", "erro que derruba", "filtro que barra")
         * em posts cujo tema NÃO tem critério eliminatório real (curso por ordem de chegada,
         * tutorial, lista). Caso real: post sobre curso grátis Sejuv Campo Grande recebeu
         * H2 "O erro que elimina a inscrição para os dias noturnos em Campo Grande".
         * Força regen — esses títulos são marketing-vazio que destrói confiança editorial. */
        $promptLeak = $this->detectarPromptLeakErroFatal($html);
        foreach ($promptLeak as $issue) $issues[] = $issue;

        /* Truncamento de P1 — frase termina em fragmento curto e desconexo após vírgula.
           Bug observado em #711 leaodabarra: "...saltaram nas últimas horas, já pesquisaram."
           Padrão: vírgula + 2-4 palavras + verbo no passado plural + ponto final. */
        $truncamentos = $this->detectarTruncamentos($html);
        foreach ($truncamentos as $issue) $issues[] = $issue;

        /* Hype não-factual — frases template inventadas pelo modelo sem lastro na fonte.
           Ex: "Buscas por X saltaram nas últimas horas", "viralizou nas redes", "ganhou repercussão" */
        $hype = $this->detectarHypeNaoFactual($html);
        foreach ($hype as $issue) $issues[] = $issue;

        /* Redundância P1↔P3 (caso real 2026-05-03 user): em intro_format=classico_3p_resposta_snippet,
         * Sonnet estava parafraseando P1 no P3 (mesma entidade+prazo+canal). Detector Jaccard de tokens
         * (sem stopwords PT-BR) entre o 1º e 3º <p> antes do 1º <h2>. ≥0.40 warn, ≥0.55 fail. */
        $redundancia = $this->detectarRedundanciaP1P3($html);
        foreach ($redundancia as $issue) $issues[] = $issue;

        /* Gatilho-batido Discover (caso reportado 2026-05-03 user no post #2126 Senai
         * Autonomia Renda): P1 abriu com "perde quem deixa pra última hora" — fórmula
         * vazia que aparece em todo post de prazo curto. User deu nota 8.8 vs 9.2 do
         * MRS que tinha gatilho ÚNICO da fonte ("filtro de CEP de BH"). Regra: P1 deve
         * trazer ângulo ESPECÍFICO da fonte (ocupação rara, mecânica única, restrição
         * geográfica), nunca clichê de prazo. Detector roda nos primeiros 3 <p> sem class. */
        $gatilhoBatido = $this->detectarGatilhoBatidoDiscover($html);
        foreach ($gatilhoBatido as $issue) $issues[] = $issue;

        /* Parágrafo-paredão (caso 2026-05-03 user pós-feedback #2126 v1): frases longas
         * (≥30 palavras) viram paredão no mobile e o leitor scrolla fora. Regra do prompt
         * "frases ≤20 palavras" só vira gate aqui. Conta frases por <p> da intro. */
        $paredao = $this->detectarParagrafoParedao($html);
        foreach ($paredao as $issue) $issues[] = $issue;

        /* Tom edital frio (caso 2026-05-03 user pós-feedback): frases típicas de release
         * institucional ("Segundo o edital divulgado", "interessados deverão", "recomenda-se
         * que", "será divulgado") matam E-E-A-T de Experiência. Pediu tom de "guia amigo". */
        $edital = $this->detectarTomEdital($html);
        foreach ($edital as $issue) $issues[] = $issue;

        /* RD na intro = posição errada (decisão editorial 2026-05-04 user). Resposta-direta
         * deve ficar no FECHAMENTO (antes do rodapé de fonte), não na intro — virava
         * "rodapé de edital" repetindo P1+P2+P3. Detector: <p class='resposta-direta'>
         * antes do 1º <h2> = posição errada → force-regen. */
        $rdNaIntro = $this->detectarRdNaIntro($html);
        foreach ($rdNaIntro as $issue) $issues[] = $issue;

        /* Intro inflada (caso reportado 2026-05-03 user nos posts 2082, 2091, 2075):
         * 5 <p> SEM class antes do 1º <h2>, mesmo com Jaccard baixo. Não é paráfrase
         * textual (que detectarRedundanciaP1P3 pega) — é dilução estrutural. Conta os
         * <p> que NÃO têm class semântica (resposta-direta, snippet-resumo, alerta-critico,
         * leia-mais, leia-tambem) antes do 1º <h2>. Regra: exatos 3 = ok; 4 = warn;
         * 5+ = fail força regen. */
        $introInflada = $this->detectarIntroInflada($html);
        foreach ($introInflada as $issue) $issues[] = $issue;

        return $issues;
    }

    /**
     * Detecta gatilhos batidos de "urgência clichê" no P1/P2/P3 — fórmulas vazias que
     * aparecem em qualquer post de prazo curto e não usam diferencial real da fonte.
     * Caso real: post #2126 Senai Autonomia Renda saiu com "perde quem deixa pra última
     * hora" → user deu nota 8.8 (vs 9.2 do post irmão MRS que tinha "filtro de CEP de BH").
     *
     * Severidade: force-regen — esses gatilhos só fazem sentido quando NÃO existe ângulo
     * único na fonte; e nessa generalidade NUNCA é o caso (todo post tem ângulo único se
     * o redator olhar pra fonte com atenção).
     */
    private function detectarGatilhoBatidoDiscover(string $html): array
    {
        $issues = [];
        $beforeH2 = $html;
        if (preg_match('/<h2/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $beforeH2 = substr($html, 0, $m[0][1]);
        }

        /* Padrões clichê (regex caso-insensitivo, com flexibilidade de plural/conjugação) */
        $padroes = [
            'deixa(m) pra ultima hora'            => '/deixa[mn]?\s+(?:pra|para)\s+(?:a\s+)?(?:ultima|última)\s+hora/iu',
            'vagas se esgotam rapido'             => '/vagas?\s+(?:se\s+)?(?:esgotam|esgota|esgotar(?:ao|ão)?)\s+(?:rapid[oa]|rapidamente|cedo|antes|em\s+horas)/iu',
            'vagas voam'                          => '/vagas?\s+voam\b/iu',
            'ultima chamada'                      => '/(?:e\s+a\s+|esta\s+(?:e|é|eh)\s+a\s+|essa\s+(?:e|é|eh)\s+a\s+|na\s+)(?:ultima|última)\s+chamada/iu',
            'nao da pra perder essa chance'       => '/n[ãa]o\s+d[áa]\s+(?:pra|para)\s+perder\s+(?:essa|esta)\s+(?:chance|oportunidade)/iu',
            'quem chega depois fica de fora'      => '/quem\s+chega\s+(?:depois|por\s+(?:ultimo|último))\s+(?:fica|nao\s+entra|n[ãa]o\s+entra)/iu',
            'quem espera fica de fora'            => '/quem\s+esp[eé]ra\s+fica\s+(?:de\s+)?fora/iu',
            'corre antes que esgote'              => '/corre[r]?\s+antes\s+que\s+(?:esgot[ae]|acabe?m?)/iu',
            'tem que correr'                      => '/tem\s+que\s+correr\s+(?:agora|antes|porque)/iu',
            'ultima oportunidade'                 => '/(?:essa|esta)\s+(?:e|é|eh)\s+(?:a\s+)?(?:ultima|última)\s+(?:oportunidade|chance)/iu',
            'nao deixe pra depois'                => '/n[ãa]o\s+deixe\s+(?:pra|para)\s+(?:depois|amanh[ãa]|a\s+ultima)/iu',
            'nao perca essa'                      => '/n[ãa]o\s+perca\s+(?:essa|esta)\s+(?:chance|oportunidade|vaga)/iu',
        ];

        $achados = [];
        foreach ($padroes as $rotulo => $regex) {
            if (preg_match($regex, $beforeH2, $mm)) {
                $achados[] = "\"" . trim($mm[0]) . "\" ({$rotulo})";
            }
        }
        if (!empty($achados)) {
            $issues[] = 'gatilho-batido-discover P1/P2/P3 contém clichê de urgência: ' . implode(' | ', $achados) . ' — substituir por ângulo ESPECÍFICO da fonte (ocupação rara, mecânica única, restrição geográfica, contraste numérico)';
            $issues[] = 'gatilho-batido-discover-forca-regen';
        }
        return $issues;
    }

    /**
     * Detecta parágrafo-paredão na intro: frase com ≥30 palavras dentro de <p> sem class
     * antes do 1º <h2>. Regra editorial 2026-05-03 user: "frases ≤20 palavras pra mobile,
     * paredão = scroll fora". Severidade force-regen quando aparece em qualquer P da intro.
     * Threshold 30 (e não 20) pra dar margem ao prompt — modelo emitir 21-29 palavras é OK.
     */
    private function detectarParagrafoParedao(string $html): array
    {
        $issues = [];
        $beforeH2 = $html;
        if (preg_match('/<h2/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $beforeH2 = substr($html, 0, $m[0][1]);
        }
        if (!preg_match_all('/<p\b([^>]*)>(.*?)<\/p>/is', $beforeH2, $ps, PREG_SET_ORDER)) return $issues;

        $paredos = [];
        foreach ($ps as $idx => $row) {
            $atribs = $row[1] ?? '';
            /* Filtra <p> com class semântica — RD pode ser longa por necessidade GEO */
            if (preg_match('/class\s*=\s*[\'"][^\'"]*(?:resposta-direta|snippet-resumo|leia-mais|leia-tambem|alerta-critico)[^\'"]*[\'"]/i', $atribs)) continue;
            $clean = trim(strip_tags($row[2]));
            if (mb_strlen($clean) < 20) continue;
            /* Quebra em frases por ponto/!/? */
            $frases = preg_split('/(?<=[.!?])\s+/u', $clean) ?: [];
            foreach ($frases as $fIdx => $f) {
                $wc = str_word_count($f);
                if ($wc >= 30) {
                    $paredos[] = "P" . ($idx + 1) . " frase " . ($fIdx + 1) . " ({$wc} palavras): \"" . mb_substr($f, 0, 80) . "...\"";
                }
            }
        }
        if (!empty($paredos)) {
            $issues[] = 'paragrafo-paredao ' . count($paredos) . 'x na intro (frase ≥30 palavras): ' . implode(' | ', array_slice($paredos, 0, 3))
                     . ' — quebrar em 2 frases curtas (≤20 palavras cada) pra escaneabilidade mobile';
            $issues[] = 'paragrafo-paredao-forca-regen';
        }
        return $issues;
    }

    /**
     * Detecta tom-edital: frases típicas de release institucional que matam E-E-A-T de
     * Experiência. Lista veio do feedback user 2026-05-03 ("Segundo o edital", "conforme
     * anexo", "interessados deverão", "candidatos preparem documentação", "recomenda-se").
     * Severidade force-regen — Sonnet substituir por tom guia amigo (AutoRevisor sabe).
     */
    private function detectarTomEdital(string $html): array
    {
        $issues = [];
        $textPlain = strip_tags(html_entity_decode($html, ENT_QUOTES|ENT_HTML5, 'UTF-8'));

        $padroes = [
            'segundo o edital divulgado'    => '/segundo\s+o\s+edital\s+(?:divulgado|publicado|oficial|n[ºo°]?\s*\d)/iu',
            'conforme o anexo'              => '/conforme\s+(?:o\s+)?anexo/iu',
            'interessados devera(o)'        => '/interessad(?:os|as)\s+dever(?:[ãa]o|iam)/iu',
            'candidatos preparem'           => '/(?:candidatos?|interessad[oa]s?)\s+preparem\s+document/iu',
            'recomenda-se que'              => '/recomenda-se\s+que/iu',
            'sera divulgado/divulgada'      => '/ser[áa]\s+divulgad[oa]\s+(?:posteriormente|em\s+breve|no\s+site|pelo)/iu',
            'os interessados podera(o)'     => '/os\s+interessad(?:os|as)\s+pod(?:er[ãa]o|em)\s+(?:realizar|efetuar)/iu',
            'fica estabelecido'             => '/fica\s+estabelecido\s+(?:que|o)/iu',
            'no ambito do programa'         => '/no\s+[âa]mbito\s+(?:do\s+programa|deste\s+(?:programa|edital))/iu',
            'em conformidade com'           => '/em\s+conformidade\s+com\s+(?:o\s+)?(?:edital|regulamento|portaria)/iu',
        ];

        $achados = [];
        foreach ($padroes as $rotulo => $regex) {
            if (preg_match($regex, $textPlain, $mm)) {
                $achados[] = "\"" . trim($mm[0]) . "\" ({$rotulo})";
            }
        }
        if (!empty($achados)) {
            $issues[] = 'tom-edital ' . count($achados) . 'x: ' . implode(' | ', array_slice($achados, 0, 3))
                     . ' — substituir por tom guia amigo ("pela divulgação oficial", "vale juntar", "costuma travar quem não preparou")';
            $issues[] = 'tom-edital-forca-regen';
        }
        return $issues;
    }

    /**
     * Detecta `<p class='resposta-direta'>` ANTES do 1º <h2>. Decisão editorial 2026-05-04:
     * RD vai pro fechamento (antes do rodapé de fonte), não na intro. Manter na intro
     * disparou redundância visível com P1+P2+P3 (recap "rodapé de edital").
     */
    private function detectarRdNaIntro(string $html): array
    {
        $issues = [];
        $beforeH2 = $html;
        if (preg_match('/<h2/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $beforeH2 = substr($html, 0, $m[0][1]);
        }
        if (preg_match('/<p\s+class\s*=\s*[\'"][^\'"]*resposta-direta[^\'"]*[\'"]/i', $beforeH2)) {
            $issues[] = 'rd-na-intro: <p class="resposta-direta"> antes do 1º <h2> — mover pra ANTES do rodapé de fonte (no fechamento). Decisão editorial 2026-05-04: RD na intro virava recap repetindo P_n.';
            $issues[] = 'rd-na-intro-forca-regen';
        }
        return $issues;
    }

    /**
     * Detecta intro inflada (>3 parágrafos textuais antes do 1º H2).
     *
     * Conta `<p>` que NÃO têm class semântica `resposta-direta|snippet-resumo|
     * alerta-critico|leia-mais|leia-tambem`. Esses são os P1+P2+P3 reais.
     *
     * Threshold:
     *   • exatamente 3 → ok (regra do prompt.md item ORDEM FIXA DO TOPO)
     *   • 4 → warn (sinal pra revisão, mas não regen)
     *   • 5+ → fail force-regen (caso real reportado pelo user 2026-05-03)
     */
    private function detectarIntroInflada(string $html): array
    {
        $issues = [];

        $beforeH2 = $html;
        if (preg_match('/<h2/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $beforeH2 = substr($html, 0, $m[0][1]);
        }

        if (!preg_match_all('/<p\b([^>]*)>(.*?)<\/p>/is', $beforeH2, $ps, PREG_SET_ORDER)) {
            return $issues;
        }

        $intro = 0;
        foreach ($ps as $row) {
            $atribs = $row[1] ?? '';
            /* Filtra <p> com class semântica — esses são parte da ORDEM FIXA mas não
             * contam como "intro textual". */
            if (preg_match('/class\s*=\s*[\'"][^\'"]*(?:resposta-direta|snippet-resumo|leia-mais|leia-tambem|alerta-critico)[^\'"]*[\'"]/i', $atribs)) {
                continue;
            }
            $clean = trim(strip_tags($row[2]));
            /* Ignora parágrafos curtos (<6 palavras) — são separadores, não intro */
            if (str_word_count($clean) < 6) continue;
            $intro++;
        }

        if ($intro >= 5) {
            $issues[] = "intro-inflada {$intro} paragrafos textuais antes do 1º H2 — ORDEM FIXA exige exatos 3 (P1+P2+P3); 5+ dilui o lead e mata o CTR mobile";
            $issues[] = 'intro-inflada-forca-regen';
        } elseif ($intro === 4) {
            $issues[] = "intro-inflada 4 paragrafos textuais antes do 1º H2 — ORDEM FIXA exige exatos 3 (P1+P2+P3); 4º é peso morto, reescrever";
            $issues[] = 'intro-inflada-forca-regen';
        }

        /* TODOS paragrafos sem class (P1, P2, P3...) ↔ resposta-direta — bug observado
         * 2026-05-03 post #2126: P3 e RD diziam quase a mesma coisa ("inscrição online
         * pelo portal autonomiaerenda.com.br"). Detector original só medía P1↔RD —
         * extendido pra cobrir todos os paragrafos textuais. Threshold mais RIGOROSO
         * pra P_n↔RD porque eles aparecem sequenciais no preview mobile.
         * Bug observado 2026-05-04 post #2137 (Encceja): P3 falava "secretaria estadual"
         * e RD "secretarias estaduais" — bigrams diferentes por plural, detector não pegava.
         * Hoje aplicamos stemming light (corta s/es/r terminais) ANTES do match. */
        $rdText = '';
        $intros = []; /* lista ordenada de paragrafos textuais */
        foreach ($ps as $row) {
            $atribs = $row[1] ?? '';
            $clean = trim(strip_tags($row[2]));
            if ($clean === '') continue;
            $temClass = preg_match('/class\s*=\s*[\'"][^\'"]*(?:resposta-direta|snippet-resumo|leia-mais|leia-tambem|alerta-critico)[^\'"]*[\'"]/i', $atribs);
            $isRD = preg_match('/class\s*=\s*[\'"][^\'"]*resposta-direta[^\'"]*[\'"]/i', $atribs);
            if (!$temClass && str_word_count($clean) >= 6) {
                $intros[] = $clean;
            } elseif ($isRD && $rdText === '' && str_word_count($clean) >= 6) {
                $rdText = $clean;
            }
        }
        if ($rdText !== '' && !empty($intros)) {
            $stop = $this->ptStopwords();
            /* Stemming light pra normalizar plural/singular antes do match — bug do
             * post #2137 (Encceja 2026-05-04): "secretaria estadual" vs "secretarias
             * estaduais" virava 0 bigrams sob match strict. Stemmer corta s/es/r terminais
             * só pra match (não altera tokens originais nas demais comparações). */
            $tB = $this->tokenizeStemmed($rdText, $stop);
            $bigB = []; for ($k=0,$kn=count($tB)-1;$k<$kn;$k++) $bigB[]=$tB[$k].' '.$tB[$k+1];
            $bigBSet = array_unique($bigB);
            foreach ($intros as $idx => $pText) {
                $tA = $this->tokenizeStemmed($pText, $stop);
                if (empty($tA)) continue;
                $sA = array_unique($tA);
                $sB = array_unique($tB);
                $shared = array_intersect($sA, $sB);
                $jacc = count($shared) / max(1, count(array_unique(array_merge($sA, $sB))));
                $cont = count($shared) / max(1, count($sA));
                $bigA = []; for ($k=0,$kn=count($tA)-1;$k<$kn;$k++) $bigA[]=$tA[$k].' '.$tA[$k+1];
                $bigShared = array_values(array_unique(array_intersect(array_unique($bigA), $bigBSet)));
                $nBig = count($bigShared);
                $hostsCompartilhados = self::hostsCompartilhados($pText, $rdText);
                /* Sinal extra: entidades-chave (datas, valores R$, números >= 100, nomes
                 * próprios capitalizados) compartilhadas. Bug post #2137: P_n não tinha
                 * datas/valores mas conceito redundante; no futuro detector pega outros casos. */
                $entidadesShared = self::entidadesChaveCompartilhadas($pText, $rdText);
                /* Threshold: 3+ bigrams OU jacc≥0.30 OU containment≥0.45 c/ bigrams≥1 OU
                 * URL compartilhada + 1 bigram OU 2+ entidades-chave compartilhadas */
                $isFail = ($nBig >= 3)
                       || ($jacc >= 0.30)
                       || ($cont >= 0.45 && $nBig >= 1)
                       || (!empty($hostsCompartilhados) && $nBig >= 1)
                       || (count($entidadesShared) >= 2);
                if ($isFail) {
                    $detalhes = $nBig > 0 ? ' bigrams=[' . implode(', ', array_slice($bigShared, 0, 3)) . ']' : '';
                    if (!empty($hostsCompartilhados)) $detalhes .= ' canal=[' . implode(',', $hostsCompartilhados) . ']';
                    if (!empty($entidadesShared)) $detalhes .= ' entidades=[' . implode(',', array_slice($entidadesShared, 0, 3)) . ']';
                    $rotulo = 'P' . ($idx + 1);
                    $issues[] = sprintf('redundancia-%s-resposta-direta jacc=%.2f cont=%.2f bigrams=%d%s — RD recapitula %s; reescrever %s com SALTO factual NOVO (consequência, contraste, restrição) sem repetir entidade+canal+dado da RD', strtolower($rotulo), $jacc, $cont, $nBig, $detalhes, $rotulo, $rotulo);
                    $issues[] = 'redundancia-' . strtolower($rotulo) . '-resposta-direta-forca-regen';
                }
            }
        }

        /* BÔNUS: redundância conceitual entre TODOS os pares de parágrafos da intro.
         * detectarRedundanciaP1P3 só mede P1↔P3. Aqui medimos cada par com bigrams >= 3
         * OU jaccard >= 0.30 = sinal de paráfrase. */
        $textos = [];
        foreach ($ps as $row) {
            $atribs = $row[1] ?? '';
            if (preg_match('/class\s*=\s*[\'"][^\'"]*(?:resposta-direta|snippet-resumo|leia-mais|leia-tambem|alerta-critico)[^\'"]*[\'"]/i', $atribs)) continue;
            $clean = trim(strip_tags($row[2]));
            if (str_word_count($clean) >= 6) $textos[] = $clean;
        }

        $stop = $this->ptStopwords();
        $n = count($textos);
        $forcaFail = false;
        for ($i = 0; $i < $n - 1; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($i === 0 && $j === 2) continue; /* P1↔P3 já é coberto por detectarRedundanciaP1P3 */
                $tA = $this->tokenize($textos[$i], $stop);
                $tB = $this->tokenize($textos[$j], $stop);
                if (empty($tA) || empty($tB)) continue;
                $sA = array_unique($tA);
                $sB = array_unique($tB);
                $shared = array_intersect($sA, $sB);
                $jacc = count($shared) / max(1, count(array_unique(array_merge($sA, $sB))));
                $bigA = []; for ($k = 0, $kn = count($tA) - 1; $k < $kn; $k++) $bigA[] = $tA[$k] . ' ' . $tA[$k + 1];
                $bigB = []; for ($k = 0, $kn = count($tB) - 1; $k < $kn; $k++) $bigB[] = $tB[$k] . ' ' . $tB[$k + 1];
                $bigShared = array_intersect(array_unique($bigA), array_unique($bigB));
                $nBig = count($bigShared);
                $isFail = ($nBig >= 3) || ($nBig >= 2 && $jacc >= 0.18) || ($jacc >= 0.30);
                if ($isFail) {
                    $issues[] = sprintf('intro-redundancia P%d↔P%d jaccard=%.2f bigrams=%d — paráfrase entre paragrafos da intro, eliminar redundancia', $i + 1, $j + 1, $jacc, $nBig);
                    $forcaFail = true;
                }
            }
        }
        if ($forcaFail) $issues[] = 'intro-redundancia-forca-regen';

        return $issues;
    }

    /**
     * Detecta redundância entre P1 e P3 — Sonnet em modo classico_3p_resposta_snippet
     * tendia a duplicar entidade+prazo+canal. Combina 2 sinais:
     *   • bigrams compartilhados (n-grams de 2 tokens consecutivos pós-filtro): mede
     *     se P3 RECICLA frases inteiras do P1 ("senac ponta", "técnico enfermagem",
     *     "maio 2026"). 3+ bigrams compartilhados = paráfrase descarada.
     *   • Jaccard de tokens único: mede overlap geral.
     *
     * Calibrado contra 2 casos reais reportados em 2026-05-03 (Senac PP + Senac RJ)
     * e 1 contra-exemplo do prompt (feriado SP — P3 traz consequência nova).
     *
     * Retorna 2 issues quando crítico pra forçar severity=fail e disparar regen via
     * DebateBuilder.regenerateWithFeedback().
     */
    private function detectarRedundanciaP1P3(string $html): array
    {
        $issues = [];

        $beforeH2 = $html;
        if (preg_match('/<h2/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $beforeH2 = substr($html, 0, $m[0][1]);
        }

        if (!preg_match_all('/<p\b([^>]*)>(.*?)<\/p>/is', $beforeH2, $ps, PREG_SET_ORDER)) {
            return $issues;
        }

        $paragrafos = [];
        foreach ($ps as $p) {
            $atribs = $p[1] ?? '';
            if (preg_match('/class\s*=\s*[\'"][^\'"]*(?:resposta-direta|snippet-resumo|leia-mais|leia-tambem|alerta-critico)[^\'"]*[\'"]/i', $atribs)) {
                continue;
            }
            $clean = trim(strip_tags($p[2]));
            if (str_word_count($clean) >= 6) $paragrafos[] = $clean;
        }

        if (count($paragrafos) < 3) return $issues;

        $p1 = $paragrafos[0];
        $p3 = $paragrafos[2];

        $stop = $this->ptStopwords();
        $tokA = $this->tokenize($p1, $stop);
        $tokB = $this->tokenize($p3, $stop);
        if (empty($tokA) || empty($tokB)) return $issues;

        $setA = array_unique($tokA);
        $setB = array_unique($tokB);
        $shared = array_intersect($setA, $setB);
        $jacc = count($setA) + count($setB) > 0
            ? count($shared) / count(array_unique(array_merge($setA, $setB)))
            : 0.0;
        /* Containment(P1→P3): fração de tokens únicos do P1 que aparecem no P3.
         * Pega caso onde P3 contém o NÚCLEO do P1 mas adiciona dados novos
         * (ex: mesma entidade+curso+modelo mas cidades novas) — Jaccard fica baixo
         * por causa do denominador inflado, containment captura. */
        $cont = count($setA) > 0 ? count($shared) / count($setA) : 0.0;

        /* Bigrams pós-filtro: pares consecutivos de tokens não-stopword */
        $bigA = [];
        for ($i = 0, $n = count($tokA) - 1; $i < $n; $i++) $bigA[] = $tokA[$i] . ' ' . $tokA[$i + 1];
        $bigB = [];
        for ($i = 0, $n = count($tokB) - 1; $i < $n; $i++) $bigB[] = $tokB[$i] . ' ' . $tokB[$i + 1];
        $bigShared = array_values(array_unique(array_intersect(array_unique($bigA), array_unique($bigB))));
        $nBig = count($bigShared);

        /* Decisão multi-sinal:
         *   FAIL: 3+ bigrams OU (2+ bigrams + jacc ≥ 0.18) OU (containment ≥ 0.30 + bigrams ≥ 1)
         *   WARN: 1 bigram + jacc ≥ 0.20  OU  jacc ≥ 0.30 sozinho */
        $amostra = $nBig > 0 ? ' bigrams=[' . implode(', ', array_slice($bigShared, 0, 3)) . ']' : '';
        $isFail = ($nBig >= 3) || ($nBig >= 2 && $jacc >= 0.18) || ($cont >= 0.30 && $nBig >= 1);
        $isWarn = ($nBig >= 1 && $jacc >= 0.20) || ($jacc >= 0.30);

        if ($isFail) {
            $issues[] = sprintf('redundancia-p1-p3 CRITICA jaccard=%.2f containment=%.2f bigrams=%d%s — P3 parafraseia P1; trazer consequencia, contraste, detalhe novo OU restricao', $jacc, $cont, $nBig, $amostra);
            $issues[] = 'redundancia-p1-p3-forca-regen';
        } elseif ($isWarn) {
            $issues[] = sprintf('redundancia-p1-p3 jaccard=%.2f containment=%.2f bigrams=%d%s — P3 sobrepoe P1; trazer dado/angulo NOVO', $jacc, $cont, $nBig, $amostra);
        }

        return $issues;
    }

    /**
     * Extrai hosts/URLs específicas presentes em AMBOS textos. Sinaliza redundância
     * conceitual quando 2 paragrafos da intro mencionam o MESMO canal de inscrição
     * (autonomiaerenda.com.br, app.eureca.me, gov.br/inss, etc).
     *
     * Caso real post #2126: P3 e RD ambos diziam "portal autonomiaerenda.com.br" —
     * Jaccard de tokens baixo (~0.07) mas leitor mobile vê paráfrase explícita.
     *
     * Retorna lista de hosts compartilhados (sem duplicatas, lowercased).
     */
    private static function hostsCompartilhados(string $a, string $b): array
    {
        $rxUrl = '/(?:https?:\/\/)?([a-z0-9][a-z0-9.-]+\.[a-z]{2,}(?:\.[a-z]{2})?)(?:\/[^\s<>"\']*)?/i';
        $hostsA = []; $hostsB = [];
        if (preg_match_all($rxUrl, $a, $mA)) {
            foreach ($mA[1] as $h) {
                $h = mb_strtolower(rtrim($h, '.,);'));
                /* Ignora hosts irrelevantes (palavras com pontos tipo "12.500") */
                if (!preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/', $h)) continue;
                /* Pelo menos 1 segmento de 4+ chars pra evitar "p.s" ou "1.2" como host */
                if (!preg_match('/[a-z]{4,}/', $h)) continue;
                $hostsA[$h] = true;
            }
        }
        if (preg_match_all($rxUrl, $b, $mB)) {
            foreach ($mB[1] as $h) {
                $h = mb_strtolower(rtrim($h, '.,);'));
                if (!preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/', $h)) continue;
                if (!preg_match('/[a-z]{4,}/', $h)) continue;
                $hostsB[$h] = true;
            }
        }
        return array_keys(array_intersect_key($hostsA, $hostsB));
    }

    /**
     * Tokeniza com stemming light pt-BR — corta sufixos plural/verbal pra normalizar
     * variação morfológica que o tokenize strict não pega.
     *
     * Regras (ordem importa):
     *   • -ais → -al  (estaduais → estadual)
     *   • -eis → -el  (federais ↛ federa, mas instituições → instituiç... cuidado)
     *   • -es ao final (>4 chars) → corta (instituições → instituiçõ; federais → federai)
     *   • -s ao final (>4 chars) → corta (vagas → vaga)
     *
     * Não vai resolver TUDO mas pega 90% do plural/conjugação simples.
     */
    private function tokenizeStemmed(string $text, array $stop): array
    {
        $tokens = $this->tokenize($text, $stop);
        $out = [];
        foreach ($tokens as $t) {
            $orig = $t;
            $len = mb_strlen($t);
            if ($len > 5) {
                /* -ais → -al (estaduais → estadual) */
                if (substr($t, -3) === 'ais') $t = substr($t, 0, -3) . 'al';
                /* -ões → -ão (instituições → instituição) */
                elseif (substr($t, -4) === 'ções') $t = substr($t, 0, -4) . 'cao';
                elseif (substr($t, -4) === 'ções') $t = substr($t, 0, -4) . 'cao';
                /* -es plural (federais → federai → federal? hmm. melhor não)
                 * Na real -ais já cobriu federais. Sim. */
                /* -s plural simples (vagas → vaga) — mas não em palavras curtas */
                elseif (substr($t, -1) === 's' && substr($t, -2) !== 'es') $t = substr($t, 0, -1);
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Extrai entidades-chave PESADAS de 2 textos pra detectar redundância conceitual:
     *   • datas explícitas (DD de mês ou DD/MM)
     *   • valores R$ (R$ 700, R$ 2.826)
     *   • números específicos >= 100 (vagas, pontos)
     *   • entidades nominativas MULTI-palavra (≥2 palavras capitalizadas:
     *     "Faculdade das Artes", "SP Escola Superior" — não Encceja sozinho)
     *
     * IGNORA: ano isolado (2026), nomes single-word (focus keyword tende a aparecer
     * em vários lugares por design). Antes esses geravam false positive.
     */
    private static function entidadesChaveCompartilhadas(string $a, string $b): array
    {
        $extrair = function(string $t): array {
            $itens = [];
            /* Datas "DD de mês" */
            if (preg_match_all('/\b\d{1,2}\s+de\s+(?:janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\b/iu', $t, $m)) {
                foreach ($m[0] as $d) $itens['data:' . mb_strtolower($d)] = true;
            }
            /* Valores R$ */
            if (preg_match_all('/R\$\s*\d[\d\.\,]*/u', $t, $m)) {
                foreach ($m[0] as $v) $itens['rs:' . preg_replace('/\s+/', '', $v)] = true;
            }
            /* Números >= 100 com contexto (vagas, candidatos, anos) */
            if (preg_match_all('/\b\d{3,}\s+(?:vagas?|candidatos?|jovens?|alunos?|inscritos?|pontos?)\b/iu', $t, $m)) {
                foreach ($m[0] as $n) $itens['num:' . preg_replace('/\s+/', '_', mb_strtolower($n))] = true;
            }
            /* Entidades MULTI-palavra (≥2 palavras capitalizadas consecutivas).
             * Nominal único (Encceja, Inep, Senai) é IGNORADO porque tende a ser
             * focus keyword e aparece em P1+RD por design. */
            if (preg_match_all('/\b[A-ZÁÉÍÓÚÂÊÔÃÕÇ][\wÁÉÍÓÚÂÊÔÃÕÇáéíóúâêôãõç]+(?:\s+(?:de|da|do|das|dos|e\s+)?[A-ZÁÉÍÓÚÂÊÔÃÕÇ][\wÁÉÍÓÚÂÊÔÃÕÇáéíóúâêôãõç]+)+/u', $t, $m)) {
                foreach ($m[0] as $n) {
                    $n = trim($n);
                    /* Mínimo 2 palavras E pelo menos 8 chars */
                    if (mb_strlen($n) >= 8 && substr_count($n, ' ') >= 1) {
                        $itens['entidade:' . mb_strtolower($n)] = true;
                    }
                }
            }
            return array_keys($itens);
        };
        $entA = $extrair($a);
        $entB = $extrair($b);
        return array_values(array_intersect($entA, $entB));
    }

    private function ptStopwords(): array
    {
        return [
            'a','o','as','os','um','uns','uma','umas','de','do','da','dos','das',
            'em','no','na','nos','nas','para','pro','pra','por','pelo','pela','pelos','pelas',
            'com','sem','e','ou','mas','que','se','ao','aos','até','mais','menos',
            'muito','muita','muitos','muitas','este','esta','estes','estas','esse','essa','esses','essas',
            'isso','isto','aquilo','seu','sua','seus','suas','meu','minha','nosso','nossa',
            'ja','já','agora','tambem','também','sao','são','tem','têm','foi','foram','ser','sera','será','serao','serão',
            'tinha','tinham','quando','onde','como','sobre','entre','nao','não','sim','depois','antes','sera','está','estão',
        ];
    }

    private function tokenize(string $text, array $stop): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $tokens = preg_split('/\s+/', trim($text)) ?: [];
        return array_values(array_filter($tokens, fn($t) => mb_strlen($t) >= 3 && !in_array($t, $stop, true)));
    }

    /**
     * Detecta sentenças truncadas: vírgula seguida de fragmento curto (2-4 palavras) com
     * verbo no passado plural (-aram/-eram/-iram) e ponto final, sem sujeito explícito.
     *
     * Caso real: "Buscas saltaram nas últimas horas, já pesquisaram." (post #711)
     * O fragmento "já pesquisaram" não tem sujeito claro e parece artefato de pós-processador
     * que cortou texto entre o `<strong>` e o `—` original.
     */
    private function detectarTruncamentos(string $html): array
    {
        $issues = [];
        if (!preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $ps)) return $issues;

        foreach ($ps[1] as $i => $p) {
            $clean = trim(strip_tags($p));
            if ($clean === '' || mb_strlen($clean) < 30) continue;
            // Procura padrão: ", [adverb opcional 2-5 chars] [palavra terminada em verbo passado plural].$"
            // Verbo passado plural PT-BR: -aram, -eram, -iram (ex: pesquisaram, buscaram, viram)
            // Ou 1ª pessoa plural: -mos no fim de palavra curta
            $padroes = [
                // ", já pesquisaram." / ", todos buscaram." / ", muitos viram."
                '/,\s+(já|todos|muitos|alguns|ainda|também)\s+\S{4,15}(aram|eram|iram)\.\s*$/iu',
                // ", X Y." onde X é monossílabo e Y é verbo curto (max 12 chars) terminado em verbo
                '/,\s+\S{2,5}\s+\S{4,12}(aram|eram|iram|ou)\.\s*$/iu',
            ];
            foreach ($padroes as $regex) {
                if (preg_match($regex, $clean, $m)) {
                    $trecho = mb_substr($clean, max(0, mb_strlen($clean) - 60));
                    $issues[] = "P{$i} possível truncamento: \"...{$trecho}\" — fragmento curto após vírgula sugere artefato de pós-processador";
                    break;
                }
            }
        }
        return $issues;
    }

    /**
     * Detecta frases de hype não-factual que GPT/Claude inventam sem lastro na fonte.
     * Padrão clássico: "Buscas saltaram", "viralizou nas redes", "ganhou repercussão".
     * Não é bloqueante por si só (pode ter lastro real), mas marca pra revisão.
     */
    private function detectarHypeNaoFactual(string $html): array
    {
        $issues = [];
        $body = strip_tags($html);
        $padroes = [
            '/buscas?\s+(por|sobre)\s+[^.,]{3,40}\s+(saltaram|dispararam|exploraram|cresceram)\s+(nas?\s+últimas\s+horas?|nas?\s+redes|na?\s+internet)/iu',
            '/viralizou\s+(nas?\s+redes|na?\s+internet|no\s+twitter|no\s+x)/iu',
            '/ganhou\s+repercussão\s+(nas?\s+redes|internacional|sem\s+precedentes)/iu',
            '/bombou\s+(nas?\s+redes|na?\s+internet)/iu',
            '/tomou\s+conta\s+das?\s+redes/iu',
            '/movimentou\s+a\s+internet/iu',
        ];
        foreach ($padroes as $regex) {
            if (preg_match($regex, $body, $m)) {
                $issues[] = "hype não-factual detectado: \"" . trim($m[0]) . "\" — verifica se a fonte sustenta ou é alucinação";
            }
        }
        return $issues;
    }

    /**
     * Verifica cada H1/H2/H3 individualmente em busca de vague promise sem qualificação.
     * Ex: "O filtro que barra inscrições" → vago (cadê o filtro real?)
     *     "Filtro de cargo barra candidatos sem CNH-D" → ok (filtro qualificado)
     */
    private function detectarVaguenessHeadings(string $html): array
    {
        $issues = [];
        if (!preg_match_all('/<(h[123])[^>]*>(.*?)<\/\1>/is', $html, $headings, PREG_SET_ORDER)) {
            return $issues;
        }
        $vagueRoots = ['filtro', 'erro', 'detalhe', 'problema', 'ponto', 'critério', 'criterio', 'truque', 'segredo'];
        foreach ($headings as $h) {
            $tag = $h[1];
            $text = trim(strip_tags($h[2]));
            $textLower = mb_strtolower($text);
            foreach ($vagueRoots as $root) {
                /* Padrão "O X que" / "Um X que" / "Esse X" sem qualificador adjacente */
                $pattern = '/\b(o|um|esse|essa|aquele|aquela)\s+' . preg_quote($root, '/') . '\b/iu';
                if (preg_match($pattern, $textLower)) {
                    /* Tem qualificador? Procura por preposição "de/no/na/em/do/da" seguida de palavra concreta após o root */
                    $qualPattern = '/' . preg_quote($root, '/') . '\s+(de|no|na|do|da|em|para|por|com)\s+[\wÁÉÍÓÚá-ÿ]+/iu';
                    $hasQualifier = (bool)preg_match($qualPattern, $textLower);
                    if (!$hasQualifier) {
                        $issues[] = "<{$tag}> vago: \"{$text}\" — '{$root}' sem qualificação concreta (ex: '{$root} de cargo', '{$root} no cadastro')";
                        break;
                    }
                }
            }
        }
        return $issues;
    }

    private function computeSeverity(int $totalPhraseViolations, array $structural): string
    {
        $structCount = count($structural);
        /* Sentinel "*-forca-fail" / "*-forca-regen" escala pra fail mesmo com 1 issue só.
         * Usado por detectores que sabem que o achado é crítico e merece regen
         * (redundância P1/P3, prompt-leak da seção "Erro Fatal"). */
        foreach ($structural as $s) {
            if (is_string($s) && (str_contains($s, '-forca-fail') || str_contains($s, '-forca-regen'))) {
                return 'fail';
            }
        }
        if ($totalPhraseViolations === 0 && $structCount === 0) return 'ok';
        if ($totalPhraseViolations <= 2 && $structCount <= 1) return 'warn';
        return 'fail';
    }

    /**
     * Detecta prompt-leak da seção "Erro Fatal": o modelo copia os exemplos literais
     * do prompt.md em vez de aplicar o template com conteúdo da fonte. Casos:
     *
     *   1. H2 "O (erro|detalhe|filtro|ponto) que (elimina|derruba|barra) a
     *      (inscrição|vaga|seleção|matrícula)" — sem qualificador concreto entre
     *      "erro/detalhe" e o verbo. Esse é literalmente o template do prompt.
     *
     *   2. <p class="alerta-critico__titulo"> contendo essas mesmas frases vagas
     *      (verbatim do exemplo no prompt.md ou variação leve com adverbial copiado).
     *
     * Severidade: force-fail (marker emitido força regen via DebateBuilder).
     */
    private function detectarPromptLeakErroFatal(string $html): array
    {
        $issues = [];

        /* Padrão central: "[artigo opcional] <root> que <verbo> a <objeto>" sem qualificador
         * entre root e "que". Artigo é opcional pra cobrir alerta-critico__titulo que copia
         * o exemplo "Erro que derruba a inscrição" (sem artigo). Adverbiais APÓS o objeto
         * (ex: "a inscrição para os dias noturnos") NÃO contam como qualificador real. */
        $pattern = '/(?:^|>|\s|"|\')\s*(?:(?:o|um|esse|essa|aquele|aquela)\s+)?'
                 . '(erro|detalhe|filtro|ponto|crit[eé]rio)\s+'
                 . 'que\s+(elimina|derruba|barra|tira|exclui|rejeita)\s+'
                 . 'a\s+(inscri[çc][ãa]o|vaga|sele[çc][ãa]o|matr[íi]cula|candidatura)/iu';

        /* H2/H3 com prompt-leak */
        if (preg_match_all('/<(h[123])[^>]*>(.*?)<\/\1>/is', $html, $hs, PREG_SET_ORDER)) {
            foreach ($hs as $h) {
                $tag  = $h[1];
                $text = trim(strip_tags($h[2]));
                if ($text === '') continue;
                if (preg_match($pattern, $text, $m)) {
                    $issues[] = "prompt-leak-erro-fatal <{$tag}>: \"{$text}\" — regurgita exemplo do prompt sem nomear critério real";
                    $issues[] = 'prompt-leak-erro-fatal-forca-fail';
                }
            }
        }

        /* alerta-critico__titulo com prompt-leak (regex casa class com aspas simples ou duplas) */
        if (preg_match_all('/<p\s+class\s*=\s*[\'"][^\'"]*alerta-critico__titulo[^\'"]*[\'"][^>]*>(.*?)<\/p>/is', $html, $ps, PREG_SET_ORDER)) {
            foreach ($ps as $p) {
                $text = trim(strip_tags($p[1]));
                if ($text === '') continue;
                if (preg_match($pattern, $text)) {
                    $issues[] = "prompt-leak-alerta-critico: \"{$text}\" — copia frase do exemplo do prompt";
                    $issues[] = 'prompt-leak-erro-fatal-forca-fail';
                }
            }
        }

        return $issues;
    }

    /**
     * Gera bloco texto pra injetar no prompt do Claude — força lembrar das proibições.
     */
    public function blacklistForPrompt(): string
    {
        $lines = [];
        $lines[] = '## DETECÇÃO PROGRAMÁTICA — FRASES BANIDAS (validação automática pós-geração)';
        $lines[] = 'O HTML final será escaneado pelo AntiAIValidator. Qualquer ocorrência destas frases marca o artigo como REPROVADO:';
        $lines[] = '';
        foreach ($this->blacklist as $category => $phrases) {
            $catLabel = str_replace('_', ' ', strtoupper($category));
            $shown = array_slice($phrases, 0, 10);
            $extra = count($phrases) - count($shown);
            $line = "**[{$catLabel}]** " . implode(' / ', array_map(fn($p) => "\"{$p}\"", $shown));
            if ($extra > 0) $line .= " ... +{$extra} variantes";
            $lines[] = $line;
        }
        $lines[] = '';
        $lines[] = 'PROIBIDO ABSOLUTO. Não importa o contexto. Use construções alternativas listadas no prompt principal.';
        return implode("\n", $lines);
    }

    public function getBlacklist(): array
    {
        return $this->blacklist;
    }

    /**
     * Converte report numa linha curta pra log.
     */
    public function reportToLogLine(array $report): string
    {
        if ($report['ok']) return 'AntiAI: OK (zero violações)';
        $parts = ["AntiAI: severity={$report['severity']} phrases={$report['total_phrase_violations']}"];
        if (!empty($report['violations'])) {
            $top = array_slice($report['violations'], 0, 3);
            $parts[] = 'top=[' . implode(',', array_map(fn($v) => "{$v['phrase']}x{$v['count']}", $top)) . ']';
        }
        if (!empty($report['structural'])) {
            $parts[] = 'struct=' . count($report['structural']);
        }
        return implode(' ', $parts);
    }
}
