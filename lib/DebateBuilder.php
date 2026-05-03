<?php
/**
 * DebateBuilder v3 — Simples e direto.
 *
 * Sonnet gera o artigo completo com prompt.md único.
 * Substitui variáveis {{DATA_HOJE}}, {{TITULO}}, {{CONTEUDO}}.
 * Leia também é injetado via cross-links dos items do batch.
 */
class DebateBuilder
{
    private Claude $claude;
    public array $log = [];
    private string $dataHoje;
    private string $promptTemplate;

    public function __construct(Claude $claude)
    {
        $this->claude = $claude;

        $dias = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
        $meses = [1=>'janeiro',2=>'fevereiro',3=>'março',4=>'abril',5=>'maio',6=>'junho',7=>'julho',8=>'agosto',9=>'setembro',10=>'outubro',11=>'novembro',12=>'dezembro'];
        $this->dataHoje = date('d') . ' de ' . $meses[(int)date('n')] . ' de ' . date('Y') . ' (' . $dias[(int)date('w')] . ')';

        $path = dirname(__DIR__) . '/prompt.md';
        $this->promptTemplate = file_exists($path) ? (string)file_get_contents($path) : '';
    }

    /**
     * Gera artigo completo Discover via Sonnet.
     * @param string $titulo     Título do RSS (ou refinado por GPT)
     * @param string $conteudo   Conteúdo scrapeado (fullText)
     * @param array  $backlinks  Posts WP para "Leia também" [{title, link, slug}]
     * @param string $fonteUrl   URL da fonte original (para rodapé)
     * @return array {titulo, slug, meta_description, palavra_chave, html, faq, _log}
     */
    public function gerar(string $titulo, string $conteudo, array $backlinks = [], string $fonteUrl = '', array $padroesUsados = [], array $titulosRecentes = [], string $amazonAfiliado = '', int $maxTokens = 32000, int $conteudoLimit = 12000, array $dna = [], ?array $pillar = null): array
    {
        $dias = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
        $diaSemana = $dias[(int)date('w')];
        $isoDate = date('c');

        // Monta o prompt substituindo variáveis
        $prompt = $this->promptTemplate;
        $prompt = str_replace('{{DATA_HOJE}}', $this->dataHoje, $prompt);
        $prompt = str_replace('{{DIA_SEMANA}}', $diaSemana, $prompt);
        $prompt = str_replace('{{TITULO}}', $titulo, $prompt);
        $prompt = str_replace('{{CONTEUDO}}', mb_substr($conteudo, 0, $conteudoLimit), $prompt);
        $prompt = str_replace('{{ISO_DATE}}', $isoDate, $prompt);
        $prompt = str_replace('{{EXPRESSAO_TEMPORAL}}', $diaSemana === $dias[(int)date('w')] ? 'neste ' . $diaSemana : 'recentemente', $prompt);

        /* {{ENTIDADES_REAIS}} — extração de entidades concretas do scraper pra prevenir vague_promise NA geração */
        if (!class_exists('EntityExtractor')) {
            $entityPath = __DIR__ . '/EntityExtractor.php';
            if (file_exists($entityPath)) require_once $entityPath;
        }
        $entitySection = '';
        if (class_exists('EntityExtractor')) {
            try {
                $entities = EntityExtractor::extract($conteudo, $titulo);
                $entitySection = EntityExtractor::formatForPrompt($entities);
                $countTotal = array_sum(array_map('count', $entities));
                $this->log[] = "EntityExtractor: {$countTotal} entidades extraídas (orgaos=" . count($entities['orgaos_instituicoes']) . ", cidades=" . count($entities['cidades_estados']) . ", numeros=" . count($entities['numeros_chave']) . ")";
            } catch (Throwable $e) {
                $this->log[] = 'EntityExtractor: falhou — ' . $e->getMessage();
            }
        }
        $prompt = str_replace('{{ENTIDADES_REAIS}}', $entitySection, $prompt);

        // Seção de backlinks: 3 embutidos em frase contextual + 3 separados pra caixa Leia Também
        $backlinkSection = '';
        if (!empty($backlinks)) {
            $total = count($backlinks);
            $backlinkSection  = "BACKLINKS_INTERNOS PARA EMBUTIR EM FRASE CONTEXTUAL (1 em cada: final do P1, meio do desenvolvimento, antes do bloco de ação).\n";
            $backlinkSection .= "REGRA DURA: cada backlink É PARTE de uma frase editorial completa. Anchor text = TÍTULO do post (ou núcleo informativo). PROIBIDO formato '+ Titulo' standalone, parágrafo cujo único conteúdo é o link, anchor genérico ('clique aqui', 'saiba mais').\n";
            $backlinkSection .= "LISTA (formato: TÍTULO = anchor text candidato | URL = href):\n";
            // Primeiros 3 (ou todos se < 3) para os parágrafos
            foreach (array_slice($backlinks, 0, min(3, $total)) as $bl) {
                $t = $bl['title'] ?? '';
                $l = $bl['link'] ?? '';
                if ($t && $l) $backlinkSection .= "- TÍTULO: \"{$t}\" | URL: {$l}\n";
            }
            // Se tem mais de 3, os próximos vão pro Leia Também
            if ($total > 3) {
                $backlinkSection .= "\nBACKLINKS PARA CAIXA LEIA TAMBÉM (bloco visual, formato lista, 3 links DIFERENTES dos acima — único lugar onde o link aparece sem frase em volta):\n";
                foreach (array_slice($backlinks, 3, 3) as $bl) {
                    $t = $bl['title'] ?? '';
                    $l = $bl['link'] ?? '';
                    if ($t && $l) $backlinkSection .= "- \"{$t}\" → {$l}\n";
                }
            } else {
                $backlinkSection .= "\n(Leia Também: reutilizar os mesmos links acima na caixa após P3, mas na caixa o link fica em formato lista puro — é bloco visual, não texto editorial.)\n";
            }
        } else {
            $backlinkSection = "BACKLINKS_INTERNOS: nenhum disponível. Não insira backlinks forçados. Inserir ao menos 1 link externo dofollow pra site oficial da entidade nos primeiros 3 parágrafos.";
        }
        $prompt = str_replace('{{BACKLINKS_SECTION}}', $backlinkSection, $prompt);

        // Leia também — monta $leiaTambem (só os <li>s) e $leiaTambemSection (bloco completo)
        $leiaTambem = '';
        $leiaTambemSection = '';
        if (!empty($backlinks)) {
            foreach (array_slice($backlinks, 0, 3) as $bl) {
                $t = htmlspecialchars($bl['title'] ?? '');
                $l = $bl['link'] ?? '';
                $leiaTambem .= "<li><a href='{$l}' rel='dofollow'>{$t}</a></li>";
            }
            $leiaTambemSection = "LEIA TAMBÉM (inserir APÓS o 3º parágrafo no HTML):\n"
                . "<div class='leia-mais-box' style='background-color: #f1f3f4; border-left: 4px solid #0b57d0; padding: 20px; margin: 30px 0; border-radius: 8px;'>\n"
                . "<strong style='font-size: 1.1em; color: #000;'>Leia também:</strong>\n"
                . "<ul id='leiamais'>\n{$leiaTambem}\n</ul>\n</div>";
        }
        $prompt = str_replace('{{LEIA_TAMBEM_SECTION}}', $leiaTambemSection, $prompt);

        // Rodapé de fonte
        $rodapeSection = '';
        if ($fonteUrl !== '') {
            $host = parse_url($fonteUrl, PHP_URL_HOST) ?: 'fonte original';
            $rodapeSection = "RODAPÉ DE FONTE (inserir no final do HTML):\n"
                . "<p style='font-size:14px; color:#666;'>Fonte: Informações publicadas pelo "
                . "<a href='{$fonteUrl}' target='_blank' rel='noopener noreferrer'>{$host}</a>, com adaptação editorial.</p>";
        }
        $prompt = str_replace('{{RODAPE_SECTION}}', $rodapeSection, $prompt);

        // Padrões de título já usados no cluster (pra evitar redundância entre irmãos)
        $padroesSection = 'Nenhum — você tem liberdade total para escolher o melhor padrão.';
        if (!empty($padroesUsados)) {
            $nomes = [1=>'Pente Fino', 2=>'Aviso de Sistema', 3=>'Contagem Regressiva', 4=>'Contradição', 5=>'Liberação + Barreira', 6=>'Tempo + Perda + Curiosidade'];
            $linhas = [];
            foreach ($padroesUsados as $n) {
                $n = (int)$n;
                if (isset($nomes[$n])) $linhas[] = "- Padrão {$n} ({$nomes[$n]}) — JÁ USADO, evitar";
            }
            if (!empty($linhas)) $padroesSection = implode("\n", $linhas);
        }
        $prompt = str_replace('{{PADROES_USADOS}}', $padroesSection, $prompt);

        // Títulos recentes do mesmo site — a prova mais forte contra redundância estrutural
        $titulosSection = 'Nenhum — este é o primeiro artigo recente do site.';
        if (!empty($titulosRecentes)) {
            $linhas = [];
            foreach (array_slice($titulosRecentes, 0, 5) as $i => $t) {
                $t = trim((string)$t);
                if ($t !== '') $linhas[] = ($i + 1) . '. "' . $t . '"';
            }
            if (!empty($linhas)) $titulosSection = implode("\n", $linhas);
        }
        $prompt = str_replace('{{TITULOS_RECENTES}}', $titulosSection, $prompt);

        // Link Amazon de afiliado (config por site)
        $amazonFallback = 'https://amzn.to/4ckOgUc';
        $prompt = str_replace('{{AMAZON_AFILIADO}}', $amazonAfiliado !== '' ? $amazonAfiliado : $amazonFallback, $prompt);

        // DNA editorial pre-alocado pelo ClusterAngleAllocator (cluster mode). Se vazio → sem restrição extra.
        $dnaSection = 'Sem DNA pre-alocado — escolha ângulo e intenção conforme o conteúdo, respeitando apenas as regras gerais de padrões e variedade já definidas acima.';
        if (!empty($dna) && is_array($dna) && !empty($dna['angulo'])) {
            $angDescMap = [
                'alerta_urgencia' => 'prazo curto ou ameaça concreta',
                'erro_comum'      => 'problema que elimina/impede o leitor',
                'oportunidade'    => 'ganho direto disponível (como garantir, quem tem direito)',
                'comparacao'      => 'opções confrontadas (X vs Y, melhor rota)',
                'guia_pratico'    => 'passo a passo concreto',
                'revelacao'       => 'dado pouco conhecido ou mal entendido',
                'economia'        => 'dinheiro direto na mão (valor real)',
                'timing'          => 'momento certo de agir (melhor dia, janela ideal)',
            ];
            $estDescMap = [
                'table_heavy'        => 'PREDOMINANTEMENTE tabelas/dados estruturados — calendário/valores/comparativos em <table>; texto serve a tabela, não o contrário',
                'step_by_step'       => 'PASSOS NUMERADOS em <ol> com Schema HowTo no JSON-LD; cada step com nome+descrição',
                'q_and_a'            => 'PERGUNTAS-RESPOSTA dominam o flow (FAQ-first); pelo menos 6 dúvidas reais como H2/H3, com Schema FAQPage',
                'narrativa'          => 'CENÁRIO HUMANO no lead (caso real ou contexto), depois dado; menos lista, mais flow editorial',
                'lista_comparativa'  => 'BULLETS/CARDS lado a lado comparando opções/critérios (não uma tabela única — vários pequenos blocos visuais)',
            ];
            $tpDescMap = [
                'pergunta_direta' => 'comece o título com pergunta direta cuja resposta está no corpo (Qual...? Quanto...? Quem...?)',
                'numero_promessa' => 'comece com número + promessa concreta (5 erros que..., 7 formas de..., 3 motivos por que...)',
                'alerta_prazo'    => 'urgência temporal explícita no título (Último dia..., Acaba em..., Até quando...)',
                'data_promessa'   => 'data específica + benefício (Pagamento começa segunda (23), Sai em maio o...)',
                'comparativo'     => 'X vs Y / X ou Y / X melhor que Y',
                'revelacao'       => 'desvelamento de algo escondido (O que ninguém te conta..., A verdade sobre..., Por que...)',
            ];
            $introDescMap = [
                'classico_3p_resposta_snippet' => 'INTRO: P1 + P2 + P3 + Resposta Direta + snippet <ul> + 1º H2. Padrão SEO/GEO clássico.',
                'lead_curto_2p_h2_imediato'    => 'INTRO ENXUTA: APENAS P1 (lead denso até 40 palavras) + Resposta Direta + 1º H2 IMEDIATO. NÃO escreva P2 nem P3. Snippet <ul> opcional (só se a fonte sustenta 2 dados fortes).',
                'narrativa_p_unico_denso'      => 'INTRO NARRATIVA: 1 ÚNICO <p> denso (50-70 palavras) com cenário humano + dado-chave + Resposta Direta + 1º H2. SEM P2/P3, SEM snippet.',
                'pergunta_lead_resposta'       => 'INTRO PERGUNTA: P1 termina com pergunta direta + Resposta Direta responde + 1 P de contexto curto + 1º H2. Sem P3. Snippet opcional.',
                'dado_solo_h2'                 => 'INTRO MINIMALISTA: P1 só com o dado bruto (até 25 palavras) + snippet <ul> com 3 bullets fortes + 1º H2 IMEDIATO. SEM Resposta Direta padrão (o snippet absorve a função GEO). SEM P2/P3.',
            ];
            $ang  = (string)$dna['angulo'];
            $angD = $angDescMap[$ang] ?? '';
            $int  = (string)($dna['intencao'] ?? '');
            $est  = (string)($dna['estrutura'] ?? '');
            $estD = $estDescMap[$est] ?? '';
            $tp   = (string)($dna['title_pattern'] ?? '');
            $tpD  = $tpDescMap[$tp] ?? '';
            $intro  = (string)($dna['intro_format'] ?? '');
            $introD = $introDescMap[$intro] ?? '';
            $numH2  = (int)($dna['num_h2'] ?? 0);
            $dif  = trim((string)($dna['diferenciador'] ?? ''));
            $abP  = trim((string)($dna['abertura_proibida'] ?? ''));
            $prom = trim((string)($dna['promessa'] ?? ''));

            $dnaSection  = "ÂNGULO OBRIGATÓRIO: {$ang}" . ($angD !== '' ? " ({$angD})" : '') . "\n";
            $dnaSection .= "INTENÇÃO: " . ($int !== '' ? $int : 'livre') . " — calibre tom e estrutura conforme essa intenção.\n";
            if ($est !== '') {
                $dnaSection .= "ESTRUTURA OBRIGATÓRIA: {$est}";
                if ($estD !== '') $dnaSection .= " — {$estD}";
                $dnaSection .= "\n";
            }
            if ($tp !== '') {
                $dnaSection .= "PADRÃO DE TÍTULO OBRIGATÓRIO: {$tp}";
                if ($tpD !== '') $dnaSection .= " — {$tpD}";
                $dnaSection .= "\n";
            }
            if ($intro !== '') {
                $dnaSection .= "FORMATO DE INTRODUÇÃO OBRIGATÓRIO: {$intro}";
                if ($introD !== '') $dnaSection .= " — {$introD}";
                $dnaSection .= "\n  ↳ Esse formato OVERRIDE a 'ORDEM FIXA DO TOPO' descrita no prompt principal. Siga ESTE intro_format, não o padrão.\n";
            }
            if ($numH2 >= 3 && $numH2 <= 6) {
                $dnaSection .= "NÚMERO DE H2s OBRIGATÓRIO: exatamente {$numH2} H2s no desenvolvimento (não 3-5 livres — {$numH2} ponto final).\n";
            }
            if ($dif  !== '') $dnaSection .= "DIFERENCIADOR ÚNICO (só este artigo do cluster cobre isso): {$dif}\n";
            if ($abP  !== '') $dnaSection .= "ABERTURA PROIBIDA (outros irmãos do cluster usariam — NÃO USE): {$abP}\n";
            if ($prom !== '') $dnaSection .= "PROMESSA ESPECÍFICA AO LEITOR: {$prom}\n";
            $dnaSection .= "\nVIOLAR QUALQUER REGRA ACIMA = ARTIGO REPROVADO. O sistema valida ângulo/estrutura/title_pattern/intro_format/num_h2 antes de publicar.";
        }
        $prompt = str_replace('{{DNA_SECTION}}', $dnaSection, $prompt);

        // PILLAR LINK (topical authority): cluster compartilha um pillar; cada artigo
        // injeta UM link interno pra ele com anchor text natural relacionado ao SEU ângulo.
        // Sinaliza ao Google que o site é fonte central no tópico (topical authority).
        if (!empty($pillar) && is_array($pillar) && !empty($pillar['link']) && !empty($pillar['title'])) {
            $pTopico = trim((string)($pillar['topico'] ?? ''));
            $pLink   = trim((string)$pillar['link']);
            $pTitle  = trim((string)$pillar['title']);
            $pillarSection  = "\n\n## PILLAR / TOPICAL AUTHORITY (OBRIGATÓRIO)\n";
            $pillarSection .= "Este artigo faz parte de um cluster sobre o tópico **{$pTopico}**.\n";
            $pillarSection .= "Existe um pillar (guia abrangente) sobre esse tópico no mesmo site:\n";
            $pillarSection .= "- Título: \"{$pTitle}\"\n";
            $pillarSection .= "- URL: {$pLink}\n\n";
            $pillarSection .= "REGRA: insira EXATAMENTE 1 link <a href=\"{$pLink}\">…</a> nesse artigo, posicionado no 1º terço do conteúdo (após o lead ou no primeiro H2). O anchor text DEVE:\n";
            $pillarSection .= "- Ser natural e contextual ao SEU ângulo único (não copiar o título do pillar literalmente)\n";
            $pillarSection .= "- Variar do anchor que outros artigos do cluster provavelmente usariam\n";
            $pillarSection .= "- Conter palavras do tópico ({$pTopico}) ou semanticamente relacionadas\n";
            $pillarSection .= "- Ter 3-7 palavras (não 1 palavra solta, não frase inteira)\n";
            $pillarSection .= "- Exemplos de boa anchor: \"guia completo do {$pTopico}\", \"entenda como funciona o {$pTopico}\", \"veja o passo a passo do {$pTopico}\", \"tudo sobre {$pTopico}\".\n\n";
            $pillarSection .= "NÃO use \"clique aqui\", \"saiba mais\", \"leia também\" — anchor text genérico desperdiça o sinal de topical authority.\n";
            $prompt .= $pillarSection;
            $this->log[] = "pillar:link";
        }

        $this->log[] = 'Sonnet: gerando artigo...';

        // Chama Claude Sonnet
        $resp = $this->claude->callPublic(
            [['role' => 'user', 'content' => $prompt]],
            "Você é o Editor-Chefe descrito no prompt. DATA: {$this->dataHoje}. ANO: " . date('Y') . ". Retorne APENAS JSON válido.",
            $maxTokens
        );

        $texto = trim($resp['content'][0]['text'] ?? '');

        // Extrai JSON da resposta
        $json = null;
        if (preg_match('/\{[\s\S]*\}/s', $texto, $m)) {
            $json = json_decode($m[0], true);
        }
        if (!is_array($json)) {
            // Tenta fixar JSON (remove markdown, escapa control chars em strings, etc)
            $fixed = $this->fixJson($texto);
            $json = json_decode($fixed, true);
        }
        if (!is_array($json) || empty($json['html'])) {
            // Última chance: extração manual dos campos via regex (HTML mesmo com JSON quebrado)
            $manual = $this->extractFieldsManually($texto);
            if ($manual && !empty($manual['html'])) {
                $json = $manual;
                $this->log[] = 'Sonnet: JSON parse falhou → extração manual OK';
            }
        }

        if (!is_array($json) || empty($json['html'])) {
            $this->log[] = 'Sonnet: falha no parse JSON (' . mb_substr($texto, 0, 200) . ')';
            throw new RuntimeException('Claude não retornou JSON válido. Primeiros 300 chars: ' . mb_substr($texto, 0, 300));
        }

        $this->log[] = 'Sonnet: artigo gerado OK (' . str_word_count(strip_tags($json['html'])) . ' palavras)';

        // Substitui placeholder <%leiamais%> se Claude usou
        if ($leiaTambem !== '') {
            $json['html'] = str_replace('<%leiamais%>', $leiaTambem, $json['html']);
        }

        /* AntiAIValidator — detecta frases proibidas e padrões robóticos pós-geração */
        if (!class_exists('AntiAIValidator')) {
            $validatorPath = __DIR__ . '/AntiAIValidator.php';
            if (file_exists($validatorPath)) require_once $validatorPath;
        }
        if (class_exists('AntiAIValidator')) {
            try {
                $validator = new AntiAIValidator();
                $aiReport = $validator->validate($json['html']);
                $this->log[] = $validator->reportToLogLine($aiReport);
                if (!empty($aiReport['violations'])) {
                    foreach (array_slice($aiReport['violations'], 0, 5) as $v) {
                        $this->log[] = "  ✗ banida: \"{$v['phrase']}\" x{$v['count']} ({$v['category']})";
                    }
                }
                foreach (array_slice($aiReport['structural'], 0, 3) as $issue) {
                    $this->log[] = "  ✗ estrutural: {$issue}";
                }

                /* Validação AdSense-safe do TÍTULO */
                $tituloChecar = (string)($json['title'] ?? $json['titulo'] ?? '');
                if ($tituloChecar !== '') {
                    $titReport = $validator->validateTitle($tituloChecar);
                    if (!$titReport['ok']) {
                        $this->log[] = "AntiAI[título]: severity={$titReport['severity']} (issues: " . count($titReport['issues']) . ", has_number=" . ($titReport['has_number']?'sim':'NÃO') . ", proper_noun=" . ($titReport['has_proper_noun']?'sim':'NÃO') . ", len={$titReport['length']})";
                        foreach ($titReport['issues'] as $i) {
                            $this->log[] = "  ✗ título: [{$i['type']}] {$i['detail']}";
                        }
                        /* Anexa pro caller */
                        $aiReport['title_validation'] = $titReport;
                        /* Se severity fail no título, força regeneração junto com o resto */
                        if ($titReport['severity'] === 'fail' && $aiReport['severity'] !== 'fail') {
                            $aiReport['severity'] = 'fail';
                            $aiReport['_title_caused_fail'] = true;
                        }
                    } else {
                        $this->log[] = "AntiAI[título]: OK (number=" . ($titReport['has_number']?'sim':'não') . ", proper_noun=" . ($titReport['has_proper_noun']?'sim':'não') . ", len={$titReport['length']})";
                        $aiReport['title_validation'] = $titReport;
                    }
                }

                /* AUTO-REGENERAÇÃO — se severity=fail, pede 1 reescrita ao Sonnet com feedback explícito */
                if ($aiReport['severity'] === 'fail') {
                    $regenJson = $this->regenerateWithFeedback($prompt, $aiReport, $maxTokens);
                    if ($regenJson !== null && !empty($regenJson['html'])) {
                        $regenReport = $validator->validate($regenJson['html']);
                        $this->log[] = 'AntiAI[regen]: ' . $validator->reportToLogLine($regenReport);
                        /* Aceita regeneração apenas se MELHOROU (severidade caiu OU contagem caiu ≥50%) */
                        $melhorou = (
                            $regenReport['severity'] !== 'fail' ||
                            $regenReport['total_phrase_violations'] <= max(0, (int)floor($aiReport['total_phrase_violations'] / 2))
                        );
                        if ($melhorou) {
                            $this->log[] = "AntiAI[regen]: ACEITO (de {$aiReport['total_phrase_violations']} → {$regenReport['total_phrase_violations']} violações)";
                            /* Preserva placeholders ja substituídos */
                            if ($leiaTambem !== '') {
                                $regenJson['html'] = str_replace('<%leiamais%>', $leiaTambem, $regenJson['html']);
                            }
                            $json = $regenJson;
                            $aiReport = $regenReport;
                            $aiReport['__regenerated'] = true;
                        } else {
                            $this->log[] = 'AntiAI[regen]: REJEITADO — não melhorou suficientemente, mantendo original';
                        }
                    } else {
                        $this->log[] = 'AntiAI[regen]: regeneração falhou — mantendo original';
                    }
                }

                /* Anexa report no JSON pra caller decidir */
                $json['__ai_validation'] = $aiReport;
            } catch (Throwable $e) {
                $this->log[] = 'AntiAI: validador falhou — ' . $e->getMessage();
            }
        }

        /* RankMathSeoValidator — espelha os 11 checks do painel RankMath, regen 1x se score < 80 */
        if (!class_exists('RankMathSeoValidator')) {
            $rmPath = __DIR__ . '/RankMathSeoValidator.php';
            if (file_exists($rmPath)) require_once $rmPath;
        }
        if (class_exists('RankMathSeoValidator')) {
            try {
                $kwFinal = trim((string)($json['focus_keyword'] ?? $json['palavra_chave'] ?? ''));
                if ($kwFinal === '' || str_word_count($kwFinal) > 5) {
                    /* Sonnet devolveu vazio ou frase longa demais (provável título inteiro) → deriva do H1 */
                    $kwFinal = RankMathSeoValidator::derivarKeywordDoTitulo((string)($json['titulo'] ?? $titulo));
                }
                $json['focus_keyword'] = $kwFinal;

                $rmOpts = [
                    'titulo'        => (string)($json['titulo'] ?? $titulo),
                    'meta_title'    => (string)($json['titulo'] ?? $titulo),
                    'meta_desc'     => (string)($json['meta_description'] ?? ''),
                    'slug'          => (string)($json['slug'] ?? ''),
                    'focus_keyword' => $kwFinal,
                    'featured_alt'  => (string)($json['imagem']['alt_text'] ?? ''),
                ];
                $rmReport = RankMathSeoValidator::validar((string)$json['html'], $rmOpts);
                $this->log[] = "RankMath: score={$rmReport['score']}/100 ({$rmReport['passes']}/{$rmReport['total']}) kw=\"{$kwFinal}\" dens={$rmReport['densidade']}%";
                foreach (array_slice($rmReport['fails'], 0, 4) as $f) {
                    $det = !empty($f['detalhe']) ? " ({$f['detalhe']})" : '';
                    $this->log[] = "  ✗ rm: {$f['titulo']}{$det}";
                }

                if ($rmReport['score'] < 80) {
                    $regenJson = $this->regenerateForRankMath($prompt, $rmReport, $kwFinal, $maxTokens);
                    if ($regenJson !== null && !empty($regenJson['html'])) {
                        $kwR = trim((string)($regenJson['focus_keyword'] ?? $regenJson['palavra_chave'] ?? $kwFinal));
                        if ($kwR === '' || str_word_count($kwR) > 5) {
                            $kwR = RankMathSeoValidator::derivarKeywordDoTitulo((string)($regenJson['titulo'] ?? $titulo));
                        }
                        $regenJson['focus_keyword'] = $kwR;
                        $rmOptsR = [
                            'titulo'        => (string)($regenJson['titulo'] ?? $titulo),
                            'meta_title'    => (string)($regenJson['titulo'] ?? $titulo),
                            'meta_desc'     => (string)($regenJson['meta_description'] ?? ''),
                            'slug'          => (string)($regenJson['slug'] ?? ''),
                            'focus_keyword' => $kwR,
                            'featured_alt'  => (string)($regenJson['imagem']['alt_text'] ?? ''),
                        ];
                        $rmReportR = RankMathSeoValidator::validar((string)$regenJson['html'], $rmOptsR);
                        $this->log[] = "RankMath[regen]: score={$rmReportR['score']}/100 ({$rmReportR['passes']}/{$rmReportR['total']}) kw=\"{$kwR}\"";
                        if ($rmReportR['score'] > $rmReport['score']) {
                            $this->log[] = "RankMath[regen]: ACEITO ({$rmReport['score']} → {$rmReportR['score']})";
                            if ($leiaTambem !== '') {
                                $regenJson['html'] = str_replace('<%leiamais%>', $leiaTambem, $regenJson['html']);
                            }
                            $json = $regenJson;
                            $json['focus_keyword'] = $kwR;
                            $rmReport = $rmReportR;
                            $rmReport['__regenerated'] = true;
                        } else {
                            $this->log[] = "RankMath[regen]: REJEITADO — score não subiu, mantendo original";
                        }
                    } else {
                        $this->log[] = 'RankMath[regen]: regeneração falhou — mantendo original';
                    }
                }

                $json['__rankmath_validation'] = $rmReport;
            } catch (Throwable $e) {
                $this->log[] = 'RankMath: validador falhou — ' . $e->getMessage();
            }
        }

        // Extrai FAQ se veio no JSON
        $faqArr = [];
        if (!empty($json['faq']) && is_array($json['faq'])) {
            $faqArr = $json['faq'];
        }

        /* focus_keyword final: prioriza o que validador RankMath calculou (string única, 2-4 palavras) */
        $focusKw = trim((string)($json['focus_keyword'] ?? ''));
        if ($focusKw === '') $focusKw = trim((string)($json['palavra_chave'] ?? ''));
        if ($focusKw === '' && class_exists('RankMathSeoValidator')) {
            $focusKw = RankMathSeoValidator::derivarKeywordDoTitulo((string)($json['titulo'] ?? $titulo));
        }
        if ($focusKw === '') $focusKw = (string)$titulo;

        return [
            'title'            => $json['titulo'] ?? $titulo,
            'slug'             => $json['slug'] ?? '',
            'excerpt'          => $json['meta_description'] ?? '',
            'meta_title'       => $json['titulo'] ?? $titulo,
            'meta_description' => $json['meta_description'] ?? '',
            'focus_keyword'    => $focusKw,
            'padrao_titulo'    => isset($json['padrao_titulo']) ? (int)$json['padrao_titulo'] : 0,
            'content_html'     => $json['html'],
            'faq'              => $faqArr,
            'tags'             => [$focusKw],
            'categories'       => ['Educação e Qualificação'],
            'hero_alt'         => $json['imagem']['alt_text'] ?? $json['titulo'] ?? $titulo,
            'imagem'           => is_array($json['imagem'] ?? null) ? $json['imagem'] : [],
            'schema_type'      => 'NewsArticle',
            'products'         => [],
            '__rankmath_validation' => $json['__rankmath_validation'] ?? null,
            '_debate_log'      => $this->log,
        ];
    }

    /** Tenta corrigir JSON com aspas duplas dentro de valores HTML */
    private function fixJson(string $text): string
    {
        // Remove markdown wrappers
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text) ?? $text;
        $text = preg_replace('/\s*```\s*$/m', '', $text) ?? $text;
        $text = trim($text);

        // Extrai do primeiro { ao último }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        // Escapa control chars (\n, \r, \t) DENTRO de strings — causa #1 de parse fail
        $text = $this->escapeControlCharsInStrings($text);

        return $text;
    }

    /**
     * Walk char-a-char: quando estiver dentro de uma string JSON ("..."),
     * converte newlines/tabs em \n, \r, \t. Respeita escapes existentes.
     */
    private function escapeControlCharsInStrings(string $json): string
    {
        $out = '';
        $inStr = false;
        $len = strlen($json);
        for ($i = 0; $i < $len; $i++) {
            $c = $json[$i];
            // Escape sequence: copia os 2 chars
            if ($c === '\\' && $i + 1 < $len) {
                $out .= $c . $json[$i + 1];
                $i++;
                continue;
            }
            if ($c === '"') {
                $inStr = !$inStr;
                $out .= $c;
                continue;
            }
            if ($inStr) {
                switch ($c) {
                    case "\n": $out .= '\\n'; break;
                    case "\r": $out .= '\\r'; break;
                    case "\t": $out .= '\\t'; break;
                    case "\0": $out .= ''; break; // null char: drop
                    default:   $out .= $c;
                }
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    /**
     * Fallback de última instância: extrai os campos por regex quando json_decode falha.
     * Objetivo: salvar o HTML mesmo que o JSON esteja parcialmente quebrado.
     */
    private function extractFieldsManually(string $texto): ?array
    {
        // Strip markdown
        $t = preg_replace('/^```(?:json)?\s*/m', '', $texto) ?? $texto;
        $t = preg_replace('/\s*```\s*$/m', '', $t) ?? $t;
        $t = trim($t);

        $out = ['titulo' => '', 'slug' => '', 'meta_description' => '', 'palavra_chave' => '', 'html' => '', 'faq' => []];

        // Campos simples (sem aspas internas provavelmente)
        foreach (['titulo', 'slug', 'meta_description', 'palavra_chave'] as $k) {
            $pattern = '/"' . preg_quote($k, '/') . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s';
            if (preg_match($pattern, $t, $m)) {
                $out[$k] = $this->decodeJsonStr($m[1]);
            }
        }

        // HTML: acha "html": " e pega até próxima chave conhecida ou "}
        if (preg_match('/"html"\s*:\s*"/', $t, $m, PREG_OFFSET_CAPTURE)) {
            $htmlStart = $m[0][1] + strlen($m[0][0]);
            $rest = substr($t, $htmlStart);
            $bestEnd = strlen($rest);
            foreach (['faq', 'titulo', 'slug', 'meta_description', 'palavra_chave', 'categories', 'tags', 'imagem'] as $k) {
                if (preg_match('/",\s*"' . preg_quote($k, '/') . '"\s*:/s', $rest, $mm, PREG_OFFSET_CAPTURE)) {
                    if ($mm[0][1] < $bestEnd) $bestEnd = $mm[0][1];
                }
            }
            // Último recurso: "} ao final
            if (preg_match('/"\s*}\s*$/s', $rest, $mm, PREG_OFFSET_CAPTURE)) {
                if ($mm[0][1] < $bestEnd) $bestEnd = $mm[0][1];
            }
            $htmlRaw = substr($rest, 0, $bestEnd);
            $out['html'] = $this->decodeJsonStr($htmlRaw);
        }

        if (empty($out['html'])) return null;
        return $out;
    }

    private function decodeJsonStr(string $s): string
    {
        // Desescapa sequências JSON básicas
        return strtr($s, ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\', '\\/' => '/']);
    }

    /**
     * Pede regeneração ao Sonnet com feedback EXPLÍCITO das violações detectadas.
     * Chamado APENAS uma vez por geração (cap pra evitar loops/custo).
     *
     * @param string $originalPrompt prompt completo original
     * @param array  $report         report do AntiAIValidator
     * @param int    $maxTokens
     * @return array|null JSON parseado ou null se falhou
     */
    private function regenerateWithFeedback(string $originalPrompt, array $report, int $maxTokens): ?array
    {
        $feedback = "## CORREÇÃO OBRIGATÓRIA — TENTATIVA ANTERIOR FOI REPROVADA\n\n";
        $feedback .= "O artigo que você gerou contém frases banidas e/ou padrões estruturais robóticos.\n";
        $feedback .= "Reescreva o MESMO conteúdo (mesmos fatos, mesma estrutura H2 prometida) eliminando ABSOLUTAMENTE todas as ocorrências abaixo:\n\n";

        if (!empty($report['violations'])) {
            $feedback .= "**FRASES BANIDAS DETECTADAS:**\n";
            foreach ($report['violations'] as $v) {
                $feedback .= "- \"{$v['phrase']}\" (apareceu {$v['count']}x — categoria: {$v['category']})\n";
            }
            $feedback .= "\n";
            $feedback .= "Substitua estas construções por linguagem editorial concreta. Em vez de conectores genéricos, use:\n";
            $feedback .= "- Em vez de 'Vale destacar' → entre direto no fato OU 'Outro ponto:' OU 'A questão é que'\n";
            $feedback .= "- Em vez de 'Diante disso' → 'Resultado:' OU 'Aí' OU 'No fim'\n";
            $feedback .= "- Em vez de 'Em suma / Em conclusão' → entre direto na consequência sem anunciar fechamento\n";
            $feedback .= "- Em vez de 'Nesse contexto / Nesse cenário' → cite o dado específico em vez de 'contexto'\n";
            $feedback .= "- Em vez de 'É importante destacar' → diga o fato direto, sem rotular como importante\n\n";
        }

        if (!empty($report['structural'])) {
            $feedback .= "**PADRÕES ESTRUTURAIS DETECTADOS:**\n";
            foreach ($report['structural'] as $issue) {
                $feedback .= "- {$issue}\n";
            }
            $feedback .= "\nVarie o início dos H2s e o comprimento dos parágrafos. Frases curtas (3-10 palavras) intercaladas com médias (11-22) — nunca tudo ~20 palavras.\n\n";
        }

        $feedback .= "REGRA DURA: a nova versão NÃO PODE ter NENHUMA das frases acima (nem variantes próximas). ";
        $feedback .= "Mantenha todos os dados factuais, todos os backlinks, toda a estrutura JSON pedida. ";
        $feedback .= "Apenas troque a linguagem das frases violadoras.\n\n";
        $feedback .= "Retorne APENAS JSON válido com o artigo corrigido (mesmo schema do prompt original).";

        $regenPrompt = $originalPrompt . "\n\n" . $feedback;

        try {
            $resp = $this->claude->callPublic(
                [['role' => 'user', 'content' => $regenPrompt]],
                "Você é o Editor-Chefe descrito no prompt. DATA: {$this->dataHoje}. Esta é uma REGERAÇÃO DE CORREÇÃO. Retorne APENAS JSON válido com a versão limpa.",
                $maxTokens
            );
            $texto = trim($resp['content'][0]['text'] ?? '');
            $json = null;
            if (preg_match('/\{[\s\S]*\}/s', $texto, $m)) {
                $json = json_decode($m[0], true);
            }
            if (!is_array($json)) {
                $fixed = $this->fixJson($texto);
                $json = json_decode($fixed, true);
            }
            if (!is_array($json) || empty($json['html'])) {
                $manual = $this->extractFieldsManually($texto);
                if ($manual && !empty($manual['html'])) $json = $manual;
            }
            return is_array($json) && !empty($json['html']) ? $json : null;
        } catch (Throwable $e) {
            $this->log[] = 'AntiAI[regen]: exception ' . $e->getMessage();
            return null;
        }
    }

    /**
     * Regen pra cobrir os checks RankMath que reprovaram. Cap 1x — chamado APENAS se score < 80.
     * Diferente do regen anti-AI: aqui pedimos pra MANTER conteúdo factual e ajustar APENAS
     * (a) onde a focus_keyword aparece no body, (b) o alt_text, (c) meta_description, (d) slug.
     */
    private function regenerateForRankMath(string $originalPrompt, array $rmReport, string $focusKw, int $maxTokens): ?array
    {
        $feedback = "## CORREÇÃO OBRIGATÓRIA — RANKMATH SEO SCORE BAIXO ({$rmReport['score']}/100)\n\n";
        $feedback .= "A versão anterior reprovou nos seguintes checks RankMath:\n\n";
        foreach ($rmReport['fails'] as $f) {
            $det = !empty($f['detalhe']) ? " — {$f['detalhe']}" : '';
            $feedback .= "- ✗ {$f['titulo']}{$det}\n";
        }
        $feedback .= "\n## INSTRUÇÕES DE CORREÇÃO\n\n";
        $feedback .= "**FOCUS KEYWORD A USAR (sem alterar):** `{$focusKw}`\n\n";
        $feedback .= "Reescreva o MESMO artigo (mesmos fatos, mesmo ângulo do DNA, mesma estrutura H2 prometida) garantindo que:\n\n";
        $feedback .= "1. **titulo** — começa com `{$focusKw}` (front-load nas primeiras 5 palavras) E contém pelo menos 1 número.\n";
        $feedback .= "2. **slug** — `slugify({$focusKw})` + 1 modificador opcional, ≤ 60 chars, sem stop words.\n";
        $feedback .= "3. **meta_description** — 140-155 chars, com `{$focusKw}` nos primeiros 60 chars.\n";
        $feedback .= "4. **focus_keyword** — retorne EXATAMENTE `{$focusKw}` (não invente outra).\n";
        $feedback .= "5. **html → P1** — primeira ocorrência de `{$focusKw}` nas primeiras 100 palavras (de preferência na 1ª frase).\n";
        $feedback .= "6. **html → pelo menos 1 H2** — texto do H2 contém `{$focusKw}` (ou variação muito próxima).\n";
        $feedback .= "7. **html → corpo** — densidade 0.8% a 1.5% (artigo ~800 palavras → 6 a 12 ocorrências de `{$focusKw}` ou variações exatas).\n";
        $feedback .= "8. **imagem.alt_text** — contém `{$focusKw}` literalmente + descrição visual da cena.\n\n";
        $feedback .= "REGRA DURA: NÃO mudar fatos, datas, números, ângulo, abertura proibida do DNA. Apenas redistribuir a `focus_keyword` nos lugares listados acima. Manter todos os backlinks internos, schemas FAQ/HowTo, leia também, rodapé fonte. Retorne APENAS JSON válido com o mesmo schema do prompt original.";

        $regenPrompt = $originalPrompt . "\n\n" . $feedback;

        try {
            $resp = $this->claude->callPublic(
                [['role' => 'user', 'content' => $regenPrompt]],
                "Você é o Editor-Chefe descrito no prompt. DATA: {$this->dataHoje}. Esta é uma REGERAÇÃO PARA RANKMATH — redistribuir a focus_keyword sem mexer em fatos. Retorne APENAS JSON válido.",
                $maxTokens
            );
            $texto = trim($resp['content'][0]['text'] ?? '');
            $json = null;
            if (preg_match('/\{[\s\S]*\}/s', $texto, $m)) {
                $json = json_decode($m[0], true);
            }
            if (!is_array($json)) {
                $fixed = $this->fixJson($texto);
                $json = json_decode($fixed, true);
            }
            if (!is_array($json) || empty($json['html'])) {
                $manual = $this->extractFieldsManually($texto);
                if ($manual && !empty($manual['html'])) $json = $manual;
            }
            return is_array($json) && !empty($json['html']) ? $json : null;
        } catch (Throwable $e) {
            $this->log[] = 'RankMath[regen]: exception ' . $e->getMessage();
            return null;
        }
    }
}
