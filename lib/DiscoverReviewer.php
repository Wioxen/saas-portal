<?php
require_once __DIR__ . '/DiscoverPostProcess.php';
require_once __DIR__ . '/DiscoverTituloValidator.php';
require_once __DIR__ . '/DiscoverTituloRefazer.php';
require_once __DIR__ . '/DiscoverGanchoExtrator.php';
require_once __DIR__ . '/DiscoverKeywordLongTail.php';
require_once __DIR__ . '/DiscoverClusterMatcher.php';
require_once __DIR__ . '/DiscoverPainClassifier.php';
/**
 * Revisor de post — aplica o prompt master de revisão sobre um post já publicado.
 *
 * Fluxo:
 *  1. Pega HTML + título do WP
 *  2. Chama Claude com prompt focado em revisão (não geração)
 *  3. Preserva blocos injetados pelo sistema (cluster-interlink, leia-tambem, msg-card, bloco-resumo, schemas)
 *  4. Aplica versão otimizada + guarda alternativas no DB
 *
 * Retorna: título otimizado, content revisado, 5 títulos alternativos,
 * 3 aberturas alternativas, 5 frases de impacto, meta description, slug.
 */
class DiscoverReviewer
{
    private array $cfg;
    private Wordpress $wp;
    private DiscoverDb $db;
    private string $claudeApiKey;
    private string $claudeModel;

    public function __construct(array $cfg, DiscoverDb $db)
    {
        $this->cfg = $cfg;
        $this->db  = $db;
        $this->wp  = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        $this->claudeApiKey = $cfg['anthropic_api_key'];
        $this->claudeModel  = $cfg['anthropic_model'];
    }

    /**
     * Revisa um post específico do DB.
     * @return array ['ok', 'post_id', 'titulo_antes', 'titulo_depois', 'chars_antes', 'chars_depois',
     *               'alternativas' => [titulos, aberturas, frases], 'meta_description', 'slug']
     */
    public function revisar(int $trendId): array
    {
        $rec = $this->db->get($trendId);
        if (!$rec) return ['ok' => false, 'erro' => 'Registro não encontrado'];
        if (empty($rec['url_post'])) return ['ok' => false, 'erro' => 'Post sem URL'];
        if (!preg_match('/post=(\d+)/', $rec['url_post'], $m)) {
            return ['ok' => false, 'erro' => 'post_id não extraído'];
        }
        $postId = (int)$m[1];

        $post = $this->wp->getPost($postId);
        $tituloAntes  = (string)($post['title']['raw'] ?? $post['title']['rendered'] ?? $rec['titulo'] ?? '');
        $contentAntes = (string)($post['content']['raw'] ?? $post['content']['rendered'] ?? '');
        if ($contentAntes === '') {
            return ['ok' => false, 'erro' => 'Post sem conteúdo'];
        }

        $keyword = (string)($rec['termo'] ?? '');

        // Detecta cluster editorial pra passar compliance específico ao Claude
        $cluster = DiscoverClusterMatcher::detectar(['termo' => $keyword, 'categoria_ids' => $rec['categoria_ids'] ?? []]);
        $resposta = $this->chamarClaude($tituloAntes, $contentAntes, $keyword, $cluster);
        if (!$resposta['ok']) return $resposta;
        $r = $resposta['data'];

        $novoTitulo  = (string)($r['titulo_final'] ?? $tituloAntes);
        $novoContent = trim((string)($r['content_html']   ?? ''));

        // Extrai gancho do corpo ORIGINAL pra usar como palavras-obrigatórias do validator
        $ganchoPalavras = [];
        $ganchoFrase = '';
        try {
            $fakeFontes = [['content' => ['paragraphs' => explode("\n", strip_tags($contentAntes))]]];
            $g = DiscoverGanchoExtrator::extrair($fakeFontes);
            $ganchoPalavras = $g['palavras'] ?? [];
            $ganchoFrase    = $g['frase']   ?? '';
        } catch (Throwable $e) {}

        // Validator + retry via helper compartilhado
        $claude = new Claude($this->claudeApiKey, $this->claudeModel);
        $vr = DiscoverTituloRefazer::validarERefazer($claude, $novoTitulo, $keyword, $tituloAntes, $ganchoPalavras, $ganchoFrase);
        $novoTitulo       = $vr['titulo'];
        $tituloFoiRefeito = $vr['refeito'];
        $val              = ['score' => $vr['score'], 'falhas' => $vr['falhas']];

        // Guard-rails: tamanho razoável (não encolher demais nem inchar)
        if ($novoContent === '') {
            return ['ok' => false, 'erro' => 'Claude não retornou content_html'];
        }
        $ratio = strlen($novoContent) / max(1, strlen($contentAntes));
        if ($ratio < 0.7) {
            return ['ok' => false, 'erro' => sprintf(
                'Content revisado muito menor (%.0f%% do original — mínimo 70%%). Possível perda de conteúdo.',
                $ratio * 100
            )];
        }
        if ($ratio > 1.5) {
            return ['ok' => false, 'erro' => sprintf(
                'Content revisado muito maior (%.0f%% do original — máximo 150%%). Possível duplicação.',
                $ratio * 100
            )];
        }

        // Valida preservação de blocos críticos
        $blocosPreservar = [
            '<!-- cluster-interlink -->', '<!-- /cluster-interlink -->',
            '<!-- leia-tambem -->',       '<!-- /leia-tambem -->',
            '<!-- cluster-inline -->',    '<!-- /cluster-inline -->',
            '<!-- cluster-schema -->',    '<!-- /cluster-schema -->',
            'class="bloco-resumo"',
            'class="msg-card"',
            'data-post-share',
        ];
        $blocosPerdidos = [];
        foreach ($blocosPreservar as $b) {
            $antes = substr_count($contentAntes, $b);
            $depois = substr_count($novoContent, $b);
            if ($antes > 0 && $depois < $antes) {
                $blocosPerdidos[] = $b . " ({$antes}→{$depois})";
            }
        }
        // Se perdeu blocos, reintegra do original (append na mesma ordem)
        if (!empty($blocosPerdidos)) {
            $novoContent = $this->reintegrarBlocos($novoContent, $contentAntes);
        }

        // Defesa em profundidade: passa pelo PostProcess (corrige datas, remove prefixos metafóricos, schemas G1)
        $novoContent = DiscoverPostProcess::processar($novoContent, [
            'titulo'  => $novoTitulo,
            'url'     => (string)($rec['url_post'] ?? ''),
            'post_id' => (int)($rec['post_id'] ?? 0),
        ], $rec, $this->cfg);

        // Diagnósticos finais: abertura, fluidez, repetição, expositivo, cobertura long-tail, compliance
        $diagAbertura    = DiscoverPostProcess::diagnosticarAbertura($novoContent);
        $diagFluidez     = DiscoverPostProcess::diagnosticarFluidez($novoContent);
        $diagRepeticao   = DiscoverPostProcess::diagnosticarRepeticoes($novoContent);
        $diagExpositivo  = DiscoverPostProcess::diagnosticarExposicaoApoH2($novoContent);
        $diagLongTail    = DiscoverKeywordLongTail::diagnosticarCobertura($novoContent, $keyword);
        $diagCompliance  = DiscoverClusterMatcher::validarCompliance($novoContent, $cluster);
        $diagPromessa    = DiscoverPostProcess::diagnosticarPromessaNaoCalibrada($novoContent);
        $diagAlerta      = DiscoverPostProcess::diagnosticarAlertaForte($novoContent);

        // B3 — adiciona badge "Atualizado em X" antes de salvar (sinal Discover "fresh")
        try {
            require_once __DIR__ . '/DiscoverUpdateBadge.php';
            $novoContent = DiscoverUpdateBadge::aplicar($novoContent, time(), 'revisão editorial');
        } catch (Throwable $e) { /* falha silenciosa — não bloqueia review */ }

        // Atualiza WP
        try {
            $this->wp->atualizarPost($postId, [
                'title'   => $novoTitulo,
                'content' => $novoContent,
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao salvar no WP: ' . $e->getMessage()];
        }

        // Guarda alternativas + meta/slug no DB pra UI mostrar depois
        $this->db->updateStatus($trendId, $rec['status'] ?? 'publicado', [
            'titulo'            => $novoTitulo,
            'ultimo_update'     => date('Y-m-d H:i:s'),
            'revisao'           => [
                'titulos_alt'       => array_slice($r['titulos_alternativos']     ?? [], 0, 5),
                'aberturas_alt'     => array_slice($r['aberturas_alternativas']   ?? [], 0, 3),
                'frases_impacto'    => array_slice($r['frases_impacto']           ?? [], 0, 5),
                'meta_description'  => (string)($r['meta_description'] ?? ''),
                'slug_sugerido'     => (string)($r['slug']              ?? ''),
                'revisado_em'       => date('Y-m-d H:i:s'),
            ],
        ]);

        return [
            'ok'             => true,
            'post_id'        => $postId,
            'titulo_antes'   => $tituloAntes,
            'titulo_depois'  => $novoTitulo,
            'titulo_score'   => $val['score'],
            'titulo_falhas'  => $val['falhas'],
            'titulo_refeito' => $tituloFoiRefeito,
            'abertura_alerta' => $diagAbertura['manual'] ?? false,
            'abertura_motivo' => $diagAbertura['motivo'] ?? 'ok',
            'fluidez_issues'  => $diagFluidez,
            'repeticao_issues'=> $diagRepeticao,
            'expositivo_issues'=> $diagExpositivo,
            'longtail_h2'     => $diagLongTail,
            'cluster'         => ['nome' => $cluster['nome'] ?? null, 'key' => $cluster['key'] ?? null],
            'pain'            => DiscoverPainClassifier::classificar($keyword),
            'compliance_issues'=> $diagCompliance,
            'promessa_issues' => $diagPromessa,
            'alerta_forte'    => $diagAlerta,
            'authority_links_count' => substr_count($novoContent, 'data-authority-link'),
            'chars_antes'    => strlen($contentAntes),
            'chars_depois'   => strlen($novoContent),
            'blocos_perdidos_recuperados' => $blocosPerdidos,
            'alternativas'   => [
                'titulos'          => array_slice($r['titulos_alternativos']   ?? [], 0, 5),
                'aberturas'        => array_slice($r['aberturas_alternativas'] ?? [], 0, 3),
                'frases_impacto'   => array_slice($r['frases_impacto']         ?? [], 0, 5),
            ],
            'meta_description' => (string)($r['meta_description'] ?? ''),
            'slug'             => (string)($r['slug']             ?? ''),
        ];
    }

    /** Se Claude perdeu blocos críticos, re-injeta do original (append ao fim). */
    private function reintegrarBlocos(string $novo, string $original): string
    {
        $blocos = [];
        // Extrai blocos marcados por HTML comment pares
        $paresMarcador = [
            ['<!-- cluster-interlink -->', '<!-- /cluster-interlink -->'],
            ['<!-- leia-tambem -->',       '<!-- /leia-tambem -->'],
            ['<!-- cluster-schema -->',    '<!-- /cluster-schema -->'],
        ];
        foreach ($paresMarcador as [$ini, $fim]) {
            $pattern = '/' . preg_quote($ini, '/') . '[\s\S]*?' . preg_quote($fim, '/') . '/';
            if (preg_match_all($pattern, $original, $matches)) {
                foreach ($matches[0] as $b) {
                    if (strpos($novo, $b) === false) $blocos[] = $b;
                }
            }
        }
        // Extrai <script type="application/ld+json">
        if (preg_match_all('/<script[^>]*application\/ld\+json[^>]*>[\s\S]*?<\/script>/i', $original, $ms)) {
            foreach ($ms[0] as $s) {
                if (strpos($novo, $s) === false) $blocos[] = $s;
            }
        }
        // Extrai post-share block
        if (preg_match('/<div class="post-share"[\s\S]*?<\/div>/', $original, $m)) {
            if (strpos($novo, 'data-post-share') === false) $blocos[] = $m[0];
        }
        if (empty($blocos)) return $novo;
        return $novo . "\n" . implode("\n", $blocos);
    }

    /** Chama Claude com o prompt master de revisão (Etapa 2 + 3). */
    private function chamarClaude(string $titulo, string $content, string $keyword, ?array $cluster = null): array
    {
        require_once __DIR__ . '/DiscoverPromptBuilder.php';

        $clusterBloco = $cluster ? DiscoverClusterMatcher::instrucaoProPrompt($cluster) : '';

        // Dor dominante — calibra tom editorial baseado no termo/título + corpo original
        $dor = DiscoverPainClassifier::classificar($keyword, mb_substr(strip_tags($content), 0, 500, 'UTF-8'));
        $dorBloco = DiscoverPainClassifier::instrucaoProPrompt($dor);

        $wpUrl = (string)($this->cfg['wp_url'] ?? '');

        $system = "Você é um EDITOR SÊNIOR de portal viral brasileiro, especialista em Google Discover, SEO e alta retenção mobile.\n\n"
            . "TAREFA: revisar e otimizar um artigo JÁ PUBLICADO, entregando versão final + alternativas.\n\n"
            . DiscoverPromptBuilder::blocoManifesto()  // carrega manifesto editorial — antes faltava aqui, gerando drift
            . $clusterBloco
            . $dorBloco
            . DiscoverPromptBuilder::regrasTemporais('revisar') . "\n"
            . DiscoverPromptBuilder::regraLinksInternos($wpUrl, 'revisar') . "\n"
            . "═══ REGRAS INVIOLÁVEIS ═══\n"
            . "1. NÃO INVENTAR: use apenas fatos, dados, nomes e números que JÁ estão no HTML original. Nada novo.\n"
            . "2. PRESERVAR BLOCOS DO SISTEMA (copiar VERBATIM, sem modificar):\n"
            . "   - <!-- cluster-interlink -->...<!-- /cluster-interlink --> (bloco Veja também)\n"
            . "   - <!-- leia-tambem -->...<!-- /leia-tambem --> (bloco Leia também)\n"
            . "   - <!-- cluster-inline -->...<!-- /cluster-inline --> (backlinks inline)\n"
            . "   - <!-- cluster-schema -->...<!-- /cluster-schema --> (ItemList schema)\n"
            . "   - <ul class='bloco-resumo'>...</ul> (TL;DR pro Discover)\n"
            . "   - <div class='msg-card'>...</div> (cards de mensagem)\n"
            . "   - <div class='post-share' data-post-share='1'>...</div> (CTA compartilhamento)\n"
            . "   - <script type='application/ld+json'>...</script> (schemas)\n"
            . "   - <blockquote>...</blockquote> (boxes de destaque)\n"
            . "3. Manter 90-110% do tamanho original (nem encolher muito, nem inchar).\n"
            . "4. Zero clickbait vazio — promessa precisa ser cumprida.\n"
            . "5. Aspas SIMPLES em atributos HTML dentro de content_html. NUNCA aspas duplas.\n"
            . "6. Nunca quebra de linha literal dentro do content_html (tudo em uma linha JSON).\n"
            . "7. PROIBIDO começar H2/H3 com metáforas genéricas: 'Pulo do Gato:', 'Sem Enrolação:', 'Direto ao Ponto:', 'Dinheiro no Bolso:', 'No Papel:', 'Na Prática:', 'De Olho em:', 'Dica de Ouro:'. Use dado/número/ação concreta.\n\n"
            . "═══ ANTI-REDUNDÂNCIA + CONSEQUÊNCIA EMOCIONAL ═══\n"
            . "ANTI-REDUNDÂNCIA: corte ~15% de gordura do original. Se o mesmo dado (valor, data, público) aparece em 3+ lugares estruturais (título, TL;DR, H2, intro), distribua em ângulos diferentes. REMOVA construções redundantes: 'prazo final', 'confirmação oficial do governo', 'planejamento prévio', 'totalmente grátis'. REMOVA frases-eco: 'Como vimos', 'Conforme dito', 'Como já mencionamos'. REMOVA abridores vazios: 'É importante', 'Vale destacar', 'Cabe ressaltar'.\n"
            . "ANTI-REPETIÇÃO SEMÂNTICA: uma mesma expressão factual (data, valor, keyword) NÃO pode aparecer +3x com wording idêntico. Se o original repete 'R\$ 85' 6x, reescreva 3-4 ocorrências com variação ('o valor da taxa', 'a cobrança'). Se '24 de abril' aparece 5x, troque pelos equivalentes ('nesta quinta', 'até a data limite', 'no último dia do prazo'). Over-optimization detectável mata score.\n"
            . "CONSEQUÊNCIA EM 3 LUGARES: o que o leitor PERDE se não agir precisa aparecer em (1) TÍTULO após separador, (2) LEAD (camada 2), (3) CTA FINAL (1ª frase). Verbos de urgência real (não repetir mesmo verbo nas 3 posições): paga, perde, fica de fora, deixa de receber, é eliminado, tem o pedido negado. A consequência SEMPRE vem da fonte — nunca invente.\n"
            . "EXPANSÃO DE FATO EM CONSEQUÊNCIA: todo detalhe técnico precisa carregar impacto prático pro leitor. Procure expressões frias no original ('erro nos dados bancários', 'falta de documento', 'cadastro desatualizado') e expanda com a consequência real que a fonte sustenta. Ex: 'erro nos dados bancários' → 'erro nos dados bancários impede o pagamento e atrasa a restituição em até 6 meses'.\n"
            . "CALIBRAGEM DE PROMESSA (Discover corta alcance de promessa ampla): se o original traz 'R\$ X para N milhões de brasileiros' sem qualificador, REESCREVA adicionando critério: 'R\$ X para brasileiros que cumprem Y, grupo estimado em N milhões pela fonte'. Não suaviza atratividade, remove ambiguidade de elegibilidade.\n"
            . "CONCRETUDE (anti-abstrato): cada adjetivo técnico (desatualizado, divergente, incompleto, irregular, pendente) no original precisa ser expandido com CENÁRIO REAL — QUANTO TEMPO ou QUAL DADO ESPECÍFICO. Ex: 'cadastro desatualizado' → 'quem não atualiza há mais de 2 anos'; 'dados divergentes' → 'quando a renda declarada não bate com o extrato'; 'documentação incompleta' → 'quem esquece de anexar o comprovante'. Só use o abstrato sem cenário se a fonte realmente não informar.\n"
            . "FRASE FORTE POR SEÇÃO (1 por H2): cada seção encerra com micro-choque ≤15 palavras. Ex: '90% ignora esse detalhe', 'É o erro que mais trava restituição'. Derivada do conteúdo da seção, nunca genérica. Se o original não tem, adicionar a cada H2.\n"
            . "GANCHOS DE SCROLL (1-2 entre H2 #2 e H2 #4): frase curta (≤12 palavras) DERIVADA do tema deste artigo. Proibido frases genéricas verbatim ('É aqui que a maioria erra', 'Parece simples. Não é.'). Ex contextual: 'É aqui que mais pedidos de isenção são negados silenciosamente', 'O envio errado do comprovante é o que mais elimina candidatos'. Estrutura: 'É aqui que [consequência específica]' OU '[Termo do tema] é o que mais [verbo de risco]' OU 'Esse é o detalhe que [impacto]'. Se o artigo já tem o equivalente, preservar.\n"
            . "ALERTA FORTE (1 obrigatório): se o original não tiver, INSIRA no meio do artigo (após 2º H2) este bloco: <div style='background:#fef2f2;border-left:4px solid #dc2626;padding:14px 18px;margin:24px 0;border-radius:6px'><strong style='color:#991b1b;display:block'>⚠️ ATENÇÃO: [ERRO CRÍTICO em 6-10 palavras]</strong><span style='color:#7f1d1d'>[1-2 frases do erro da fonte]</span></div>. Destaca o risco que barra/elimina/nega. Baseado na fonte — nunca inventar.\n"
            . "INTERPRETAÇÃO EDITORIAL (1 por artigo): adicionar 1 frase que conecta pontos da fonte com leitura editorial. Fórmula: 'O que parece Y é, na prática, Z'. Ex: 'O que parece um detalhe administrativo é uma filtragem por cadastro regular.' Diferente de voz de especialista (observação prática). Não inventar estatística — 'padrão observado' é permitido. Posicionar entre H2 2-3.\n"
            . "VOZ DE ESPECIALISTA (1 por artigo): adicionar 1 parágrafo curto (2-3 linhas) com tom de insider. Aberturas válidas: 'Na prática, o erro mais comum que leva a [PROBLEMA] é [DETALHE]', 'Quem trabalha com isso sabe: [observação da fonte]', 'O que se vê em campo é [padrão]'. Insight derivado da fonte — nunca inventar estatística interna. Posicionar entre H2 2-3 ou antes da conclusão. Se o original já tem esse tipo de voz, preservar.\n"
            . "MICRO-NARRATIVA (1 por artigo): se o original não tiver, insira 1 parágrafo de cenário real no meio do corpo — alguém que viveu, erro documentado na fonte. Max 3 linhas. Ex: 'Quem perdeu o Enem em 2025 agora precisa justificar — muita gente esquece e paga a taxa sem precisar.' NUNCA personagem fictício. Conecta regra com drama real (perdeu, pagou, ficou sem, descobriu tarde).\n\n"
            . "═══ O QUE OTIMIZAR (ALVO: score Discover 9.5+) ═══\n"
            . "- TÍTULO COM TENSÃO: junte FATO + CONSEQUÊNCIA com dois pontos (:), ponto-e-vírgula (;) ou parênteses (...). PROIBIDO travessão (—) ou en-dash (–). Ex forte: 'Isenção do ENEM 2026 encerra dia 24: quem perder paga a taxa cheia'. Ex fraco: 'Enem 2026: prazo encerra dia 24'. Se o original só DESCREVE, reescreva com consequência real (baseada na fonte).\n"
            . "- LEAD DE ESCALA + OBSTÁCULO: se a fonte/original traz volumetria forte (milhões, R\$ bi, N mil), reescreva o lead com [ESCALA+ação positiva] + [MAS] + [obstáculo específico]. Ex: 'Milhões vão receber até R\$ 1 mil sem declarar — mas detalhe no cadastro bancário impede o depósito.' Isso transforma lead informativo em lead de impacto.\n"
            . "- DIFERENCIAL NO TOPO: se o original enterra um insight único (cashback automático, regra inédita, novidade) no meio, PUXE pro título ou 1º parágrafo. Não pode ficar como 'detalhe a mais'.\n"
            . "- LEAD: escolha UM de 6 padrões, evitando repetir o padrão do artigo original: (1) COUNTDOWN (prazo <72h), (2) GAP/INSIGHT ('Quem tem X pode ter problema sem nem saber'), (3) NÚMERO-FIRST ('N pessoas têm direito, mas só Y...'), (4) CONTRASTE ('Parece X. Não é.'), (5) CASE concreto, (6) DATA-CHAVE. Rotacione.\n"
            . "- ÚLTIMA frase do lead: AÇÃO prática OU DETALHE específico — NUNCA aforismo genérico ('O erro é silencioso', 'Sem aviso', 'Passa batido', 'Descobre tarde demais').\n"
            . "- PROIBIDO abertura manual/tutorial: 'Os [N] grupos', 'As [N] regras', 'Conheça os', 'Veja quem tem direito', 'Saiba quem pode', 'Existem [N] perfis'. Se o original começa assim, REESCREVA com RISCO/CONSEQUÊNCIA. Abertura-manual mata CTR no Discover.\n"
            . "- PROIBIDA ABERTURA NEUTRA: 'Se você se encaixa em algum desses perfis', 'Caso você', 'Para quem', 'Aqueles que', 'Você que é', 'Fique atento'. Substituir por framing de RISCO: 'Muita gente vai perder [X] por [Y]', 'O que elimina mais pedidos é [detalhe]', '[N] candidatos costumam ser barrados por [motivo específico]'.\n"
            . "- Cortar redundâncias, frases longas, jargão burocrático.\n"
            . "- SEO natural: distribuir palavra-chave e variações sem stuffing.\n"
            . "- Humanizar no CORPO do texto (não em títulos); fala COM o leitor sem infantilizar.\n"
            . "- Fortalecer H2/H3: cada um é micro-manchete factual (dado/número/ação), sem prefixos-cliché.\n"
            . "- PRIMEIRO <p> DE CADA H2 = AÇÃO/ALERTA/DECISÃO. Se o original começa seção com 'X é um programa gerido por Y' (expositivo puro), REESCREVA pra começar com a ação prática ou alerta: 'Quem fizer [X] até [Y] recebe [Z]; quem atrasar perde.' Explicação técnica fica DEPOIS.\n"
            . "- LONG-TAIL NOS H2 (≥50% cobertura): pelo menos metade dos H2 contém a keyword principal ou variação semântica (elegibilidade/processo/prazo/requisitos/valor/resultado/negativa). Se o original tem H2 soltos ('Informações gerais', 'Sobre o programa'), REESCREVA com intenção de busca: 'Quem tem direito à isenção do Enem 2026', 'Documentos necessários para pedir isenção'. Sempre combine com dado da fonte.\n"
            . "- Parágrafos curtos (3-4 linhas mobile), destaques em <strong>.\n"
            . "- CTA FINAL em 2 frases editoriais: (1) AÇÃO + PRAZO/CONSEQUÊNCIA; (2) PONTE pro próximo conteúdo com link interno contextual (âncora 2-5 palavras). Ex: 'Quem tem direito deve fazer o pedido ainda em abril; depois do dia 24 o candidato paga a taxa cheia. Se o objetivo é uma vaga pública, vale conhecer <a href=\"/concursos-federais-2026\">os concursos federais com edital previsto para 2026</a>.' PROIBIDO 'manda pra alguém', 'manda esse artigo', 'compartilhe', 'passa o link' — informalidade demais.\n"
            . "- VARIAÇÃO HUMANA (princípio): inclua quebra de ritmo derivada do CONTEÚDO deste artigo, nunca frases prontas. Se o original já tem frases-modelo ('A vaga não espera.', 'É aqui que a maioria erra.', 'Parece simples. Não é.', 'A maioria perde por isso.', 'Fica a dica.') — REESCREVA com dado específico da fonte. Ex: em vez de 'É aqui que a maioria erra', usar algo como 'Faltou atualizar o CadÚnico nos últimos 24 meses — motivo principal de negação'.\n\n"
            . "═══ FRASES PROIBIDAS (REMOVER se estiverem no original) ═══\n"
            . "- 'Se você ainda não [verbo], leia isso agora'\n"
            . "- 'processo leva menos de N minutos' / 'leva poucos minutos' / 'é rapidinho' / 'em menos de 10 minutos' — clichê banido. Só cite tempo com número exato + sujeito da fonte.\n"
            . "- 'Olha só cada um deles:' / 'Olha só como funciona:'\n"
            . "- 'Entenda tudo sobre' / 'Saiba mais sobre' / 'Confira a seguir'\n"
            . "- 'Vale a pena ficar atento' / 'Vale destacar' / 'É importante lembrar'\n"
            . "- 'Descubra agora' / 'Não perca essa oportunidade'\n"
            . "- 'Tudo o que você precisa saber sobre'\n"
            . "- 'Neste artigo, vamos falar sobre' / 'Neste conteúdo'\n"
            . "- 'Continue lendo' / 'A seguir, entenda'\n"
            . "- 'Sem dúvidas' / 'Com certeza' / 'Certamente' (enchimento)\n"
            . "- 'Manda pra quem', 'manda esse artigo', 'manda pro grupo', 'passa o link', 'compartilhe com quem' — informalidade demais.\n"
            . "- 'O erro é silencioso', 'sem exceção', 'antes mesmo de você perceber', 'sem direito a recurso', 'sem aviso prévio', 'descobre tarde demais', 'passa batido' — aforismos de urgência genérica. Substituir por ação prática ou detalhe específico.\n\n"
            . DiscoverPromptBuilder::blocoHumanoEspecialista() . "\n"
            . DiscoverPromptBuilder::schemaRevisar();

        $user = "KEYWORD PRINCIPAL: {$keyword}\n\n"
              . "TÍTULO ATUAL:\n{$titulo}\n\n"
              . "CONTEÚDO ATUAL (HTML):\n{$content}";

        $payload = [
            'model'      => $this->claudeModel,
            'max_tokens' => 48000,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->claudeApiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            return ['ok' => false, 'erro' => "Claude HTTP {$code}: " . substr((string)$resp, 0, 400)];
        }
        $data = json_decode((string)$resp, true);
        $texto = $data['content'][0]['text'] ?? '';
        $stopReason = $data['stop_reason'] ?? '';

        // Detecta truncagem explícita
        if ($stopReason === 'max_tokens') {
            return ['ok' => false, 'erro' => 'Claude truncou resposta (max_tokens). Post grande demais — tente dividir ou use Reformatar em vez de Revisar.'];
        }

        // Usa parser robusto (5 tentativas + reparo de truncagem) do Claude.php
        $j = Claude::parseJsonResponse($texto);

        if (!is_array($j)) {
            // Salva a resposta crua em arquivo pra debug
            $dbgDir = __DIR__ . '/../data/debug';
            if (!is_dir($dbgDir)) @mkdir($dbgDir, 0777, true);
            $dbgFile = $dbgDir . '/revisao_fail_' . date('Ymd_His') . '_' . substr(md5($texto), 0, 8) . '.txt';
            @file_put_contents($dbgFile, $texto);

            // Detalha onde quebrou pra debug
            $len = strlen($texto);
            $jsonErr = json_last_error_msg();
            $amostra = mb_substr($texto, 0, 400, 'UTF-8');
            $parecerTruncado = (substr_count($texto, '{') > substr_count($texto, '}'));
            $dica = $parecerTruncado ? ' PROVÁVEL TRUNCAGEM.' : '';
            return [
                'ok' => false,
                'erro' => "Claude retornou algo não-JSON (len={$len}, stop={$stopReason}, json_err={$jsonErr}).{$dica} Raw salva em: " . basename($dbgFile) . " · Primeiros 400 chars: " . $amostra,
                'debug_file' => $dbgFile,
            ];
        }

        if (!isset($j['content_html']) || trim((string)$j['content_html']) === '') {
            $chavesRecebidas = implode(', ', array_keys($j));
            return ['ok' => false, 'erro' => "JSON parseado mas SEM content_html. Chaves recebidas: [{$chavesRecebidas}]. Claude pode não ter seguido o schema de saída."];
        }

        return ['ok' => true, 'data' => $j];
    }
}
