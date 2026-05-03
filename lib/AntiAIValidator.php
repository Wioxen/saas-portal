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

        /* Truncamento de P1 — frase termina em fragmento curto e desconexo após vírgula.
           Bug observado em #711 leaodabarra: "...saltaram nas últimas horas, já pesquisaram."
           Padrão: vírgula + 2-4 palavras + verbo no passado plural + ponto final. */
        $truncamentos = $this->detectarTruncamentos($html);
        foreach ($truncamentos as $issue) $issues[] = $issue;

        /* Hype não-factual — frases template inventadas pelo modelo sem lastro na fonte.
           Ex: "Buscas por X saltaram nas últimas horas", "viralizou nas redes", "ganhou repercussão" */
        $hype = $this->detectarHypeNaoFactual($html);
        foreach ($hype as $issue) $issues[] = $issue;

        return $issues;
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
        if ($totalPhraseViolations === 0 && $structCount === 0) return 'ok';
        if ($totalPhraseViolations <= 2 && $structCount <= 1) return 'warn';
        return 'fail';
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
