<?php
/**
 * Gerador de artigo via GPT (alternativa ao Claude).
 *
 * Pipeline simplificado:
 *  1. Fetch artigos via TrendsArticles
 *  2. Scrape defensivo + enrichment (JSON-LD, AMP, meta description)
 *  3. Monta prompt usando manifesto editorial (prompts/manifesto_editorial.md) + config do discover
 *  4. Chama GPT via OpenAI::chat
 *  5. Parseia JSON da resposta
 *  6. Cria post no WP (sem Maquina — publish direto)
 *  7. PostProcess + Auditor + QualityScore + DB update
 *
 * Mesmo input/output do DiscoverGerador pra comparação A/B.
 */
class DiscoverGeradorGPT
{
    private array $cfg;
    private DiscoverDb $db;
    private OpenAI $openai;
    private Wordpress $wp;
    private Scraper $scraper;
    private Serper $serper;
    private TrendsArticles $artigos;
    private string $modelo;

    public function __construct(array $cfg, DiscoverDb $db, string $modelo = 'gpt-4o-mini')
    {
        $this->cfg     = $cfg;
        $this->db      = $db;
        $this->modelo  = $modelo;
        $this->openai  = new OpenAI($cfg['openai_api_key'], $modelo);
        $this->wp      = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        $this->scraper = new Scraper($cfg['user_agent'], $cfg['scrape_timeout'] ?? 15);
        $this->serper  = new Serper($cfg['serper_api_key']);
        $this->artigos = new TrendsArticles($this->serper, $this->scraper, $cfg['user_agent']);
    }

    public function gerar(array $trend, string $formato = 'discover'): array
    {
        require_once __DIR__ . '/DiscoverProgress.php';
        $termo = trim((string)($trend['termo'] ?? ''));
        if ($termo === '') return ['ok' => false, 'erro' => 'termo vazio'];
        $trendId  = (int)($trend['id'] ?? 0);
        $briefing = $trend['briefing'] ?? null;
        $progress = new DiscoverProgress($trendId);

        // 1+2) Coleta fontes + enrichment (JSON-LD, AMP, description) — reusa DiscoverFontes
        require_once __DIR__ . '/DiscoverFontes.php';
        $progress->reportar('listando', 'Google News + Serper');
        $coletor = new DiscoverFontes($this->cfg, $this->artigos, $this->scraper);
        $progress->reportar('scraping', 'Buscando e fazendo scrape das URLs candidatas');
        $col = $coletor->coletar($termo, 5);
        if (!$col['ok']) {
            $progress->erro($col['erro']);
            return [
                'ok' => false,
                'erro' => $col['erro'],
                'fontes_ok' => count($col['fontes_ok']),
                'chars_totais' => $col['chars_totais'],
                'fontes_tentadas' => $col['fontes_tentadas'] ?? 0,
            ];
        }
        $fontesOk     = $col['fontes_ok'];
        $totalChars   = $col['chars_totais'];
        $textosFontes = $col['textos'];
        $progress->reportar('enriquecendo', count($fontesOk) . ' fontes · ' . $totalChars . ' chars');

        // 3) Monta prompt (reusa estrutura do Claude — prompt similar)
        $progress->reportar('montando_prompt', 'Construindo system + user prompt');
        [$system, $user] = $this->montarPrompt($termo, $briefing, $fontesOk, $formato);

        // 4) Chama GPT
        $progress->reportar('chamando_llm', 'GPT (' . $this->modelo . ') — ~40-60s');
        try {
            $resp = $this->openai->chat($system, $user, 16000);
        } catch (Throwable $e) {
            $progress->erro('OpenAI: ' . $e->getMessage());
            return ['ok' => false, 'erro' => 'OpenAI: ' . $e->getMessage()];
        }

        // 5) Parse JSON
        $progress->reportar('parseando', 'Extraindo JSON da resposta');
        $json = Claude::parseJsonResponse($resp); // parser robusto já existente
        if (!is_array($json) || empty($json['content_html'])) {
            $dbg = __DIR__ . '/../data/debug/gpt_fail_' . date('Ymd_His') . '_' . substr(md5($resp), 0, 8) . '.txt';
            @mkdir(dirname($dbg), 0777, true);
            @file_put_contents($dbg, $resp);
            $progress->erro('JSON inválido');
            return ['ok' => false, 'erro' => 'GPT não retornou JSON válido. Raw: ' . basename($dbg) . '. Primeiros 400: ' . mb_substr($resp, 0, 400, 'UTF-8')];
        }

        $titulo       = DiscoverPostProcess::normalizarTitulo((string)($json['title'] ?? $json['titulo_final'] ?? $termo));
        $content      = (string)($json['content_html'] ?? '');
        $metaTitle    = DiscoverPostProcess::normalizarTitulo((string)($json['meta_title'] ?? $titulo));

        // ─── VALIDADORES DE QUALIDADE PÓS-GERAÇÃO ───
        // Caso real (post #711 leaodabarra): GPT inventou técnicos, URLs, número errado.
        // Esses validadores marcam alucinações ANTES de salvar no WP.
        $validationReport = ['anti_ai' => null, 'fidelity' => null];

        // 1) AntiAIValidator — frases banidas, padrões robóticos, truncamento, hype
        if (!class_exists('AntiAIValidator')) {
            $aiPath = __DIR__ . '/AntiAIValidator.php';
            if (file_exists($aiPath)) require_once $aiPath;
        }
        if (class_exists('AntiAIValidator')) {
            try {
                $aiVal = new AntiAIValidator();
                $aiReport = $aiVal->validate($content);
                $validationReport['anti_ai'] = $aiReport;
                $progress->reportar('validando_anti_ai', $aiVal->reportToLogLine($aiReport));
            } catch (Throwable $e) { /* validador não bloqueia geração */ }
        }

        // 2) SourceFidelityValidator — nomes próprios e URLs sem lastro na fonte
        if (!class_exists('SourceFidelityValidator')) {
            $sfPath = __DIR__ . '/SourceFidelityValidator.php';
            if (file_exists($sfPath)) require_once $sfPath;
        }
        if (class_exists('SourceFidelityValidator')) {
            try {
                $fidReport = SourceFidelityValidator::validar($content, $textosFontes);
                $validationReport['fidelity'] = $fidReport;
                $progress->reportar('validando_fidelidade', SourceFidelityValidator::reportToLogLine($fidReport));
                // Se severity=fail (nome ou URL inventada), grava report num arquivo de debug
                // mas continua o fluxo (decisão de bloquear publicação fica pro orquestrador).
                if (($fidReport['severity'] ?? '') === 'fail') {
                    $dbgPath = __DIR__ . '/../data/debug/fidelity_fail_' . date('Ymd_His') . '_' . $trendId . '.json';
                    @mkdir(dirname($dbgPath), 0777, true);
                    @file_put_contents($dbgPath, json_encode([
                        'trend_id' => $trendId,
                        'termo'    => $termo,
                        'modelo'   => $this->modelo,
                        'report'   => $fidReport,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            } catch (Throwable $e) { /* validador não bloqueia geração */ }
        }

        // Validator + retry de título (GPT delega pro Claude pra evitar custo de 2º call no OpenAI).
        // Se anthropic_api_key está configurado, retry roda; caso contrário skip.
        $tituloInfo = ['refeito' => false, 'score' => null, 'falhas' => []];
        if (!empty($this->cfg['anthropic_api_key'])) {
            try {
                require_once __DIR__ . '/Claude.php';
                require_once __DIR__ . '/DiscoverTituloRefazer.php';
                require_once __DIR__ . '/DiscoverGanchoExtrator.php';
                $fontesArr = [];
                foreach ($textosFontes as $tf) {
                    $fontesArr[] = ['content' => ['paragraphs' => array_filter(explode("\n", $tf))]];
                }
                $gancho = DiscoverGanchoExtrator::extrair($fontesArr);
                $ganchoPalavras = $gancho['palavras'] ?? [];
                $ganchoFrase    = $gancho['frase']   ?? '';
                $claude = new Claude($this->cfg['anthropic_api_key'], $this->cfg['anthropic_model'] ?? 'claude-sonnet-4-6');
                $vr = DiscoverTituloRefazer::validarERefazer($claude, $titulo, $termo, $titulo, $ganchoPalavras, $ganchoFrase);
                if ($vr['titulo'] !== $titulo) {
                    $titulo    = $vr['titulo'];
                    $metaTitle = DiscoverPostProcess::normalizarTitulo($metaTitle ?: $titulo);
                }
                $tituloInfo = ['refeito' => $vr['refeito'], 'score' => $vr['score'], 'falhas' => $vr['falhas']];
            } catch (Throwable $e) { /* retry opcional, não bloqueia */ }
        }
        $metaDesc     = (string)($json['meta_description'] ?? '');
        $slug         = (string)($json['slug'] ?? '');
        $focusKw      = (string)($json['focus_keyword'] ?? $termo);
        // Combina focus + secondary keywords pra rank_math_focus_keyword (CSV)
        $allKws = [trim($focusKw)];
        foreach ((array)($json['secondary_keywords'] ?? []) as $sk) {
            $sk = trim((string)$sk);
            if ($sk !== '' && !in_array($sk, $allKws, true)) $allKws[] = $sk;
        }
        $kwsStr = implode(', ', array_filter($allKws));

        // 5b) FEATURED IMAGE — cascata Pexels → DALL-E → og:image (paridade com Sonnet/Maquina)
        // Antes desse bloco, GPT path NUNCA gerava imagem. Bug histórico de paridade.
        $featuredId = null;
        $imagemMeta = null;
        if (!empty($this->cfg['pexels_api_key'])) {
            try {
                require_once __DIR__ . '/DiscoverImagemFeatured.php';
                require_once __DIR__ . '/DiscoverClusterMatcher.php';
                $clusterDet = DiscoverClusterMatcher::detectar(['termo' => $termo]);
                $ogFallback = '';
                foreach ($fontesOk as $f) {
                    if (!empty($f['fonte']['meta']['og_image'])) { $ogFallback = $f['fonte']['meta']['og_image']; break; }
                }
                $imgSvc = new DiscoverImagemFeatured($this->cfg);
                $imagemMeta = $imgSvc->escolher([
                    'termo'             => $termo,
                    'cluster_key'       => $clusterDet['key'] ?? '',
                    'briefing_titulo'   => $titulo,
                    'og_image_fallback' => $ogFallback,
                ]);
                if (!empty($imagemMeta['url'])) {
                    $altImg = (string)($json['imagem']['alt_text'] ?? $json['hero_alt'] ?? $titulo);
                    $featuredId = $this->wp->uploadImagemPorUrl($imagemMeta['url'], $altImg, $imagemMeta['slug_sugerido'] ?? '');
                }
            } catch (Throwable $e) { /* falha não bloqueia post */ }
        }

        // 6) Cria post no WP
        $progress->reportar('publicando', 'Criando rascunho no WordPress');
        try {
            $payloadPost = [
                'title'   => $titulo,
                'content' => $content,
                'status'  => $this->cfg['wp_default_status'] ?? 'draft',
                'slug'    => $slug ?: null,
                'excerpt' => (string)($json['excerpt'] ?? ''),
                'meta'    => [
                    'rank_math_title'         => $metaTitle,
                    'rank_math_description'   => $metaDesc,
                    'rank_math_focus_keyword' => $kwsStr,
                ],
            ];
            if ($featuredId) $payloadPost['featured_media'] = $featuredId;
            $postCriado = $this->wp->criarPost($payloadPost);
            $postId = (int)($postCriado['id'] ?? 0);
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao criar post no WP: ' . $e->getMessage()];
        }
        if ($postId <= 0) return ['ok' => false, 'erro' => 'post_id inválido retornado pelo WP'];

        $editUrl = ($this->cfg['wp_url'] ?? '') . '/wp-admin/post.php?post=' . $postId . '&action=edit';

        // ─── CATEGORIA — fim do "sem-categoria" também no fluxo GPT (fallback) ───
        // Mesma lógica do DiscoverGerador (Claude path). Garante que post saia com
        // categoria mapeada do cluster mesmo quando Claude falha e cai pra GPT.
        try {
            require_once __DIR__ . '/CategoryMatcher.php';
            $cm = new CategoryMatcher($this->wp, 70.0);
            $clusterKey = (string)($trend['cluster_detect']['key'] ?? 'curiosidades_geral');
            $catNomes = self::clusterParaCategorias($clusterKey, (string)($trend['termo'] ?? ''));
            if (!empty($catNomes)) {
                $catIds = $cm->resolverComMatch($catNomes);
                if (!empty($catIds)) {
                    $this->wp->atualizarPost($postId, ['categories' => $catIds]);
                }
            }
        } catch (Throwable $e) { /* categoria não bloqueia post */ }

        // 7) PostProcess (auto-links, cards, schemas, cluster interlink)
        $progress->reportar('pos_processing', 'Cards, schemas, auto-links, cluster');
        $auditoria = null;
        $quality = null;
        try {
            $postInfo = $this->wp->getPost($postId);
            $slugReal = (string)($postInfo['slug'] ?? $slug);
            $urlPublica = ($slugReal !== '') ? rtrim($this->cfg['wp_url'], '/') . '/' . $slugReal . '/' : ($postInfo['link'] ?? '');

            $contentAtual = $postInfo['content']['raw'] ?? $postInfo['content']['rendered'] ?? $content;
            // Schemas rich G1 — passa trend completo + cfg (com persona) + imagem
            $trendCompletoGpt = $this->db->get($trendId) ?: $trend;
            $cfgGpt = $this->cfg;
            if (!empty($postInfo['featured_media'])) {
                try {
                    $media = $this->wp->getMedia((int)$postInfo['featured_media']);
                    $cfgGpt['_image_url'] = $media['source_url'] ?? '';
                } catch (Throwable $e) {}
            }
            $contentPos = DiscoverPostProcess::processar($contentAtual, ['titulo' => $titulo, 'url' => $urlPublica, 'post_id' => $postId], $trendCompletoGpt, $cfgGpt);
            if ($contentPos !== $contentAtual && strlen($contentPos) > strlen($contentAtual) * 0.9) {
                $this->wp->atualizarPost($postId, ['content' => $contentPos]);
                $contentAtual = $contentPos;
            }

            // Imagem SEO — alt_text, legenda, descrição na featured image
            try {
                require_once __DIR__ . '/DiscoverImagemSEO.php';
                $mediaId = (int)($postInfo['featured_media'] ?? 0);
                if ($mediaId > 0) {
                    $ganchoFrase = $gancho['diferencial']['frase'] ?? ($gancho['frase'] ?? '');
                    $metaExistente = [
                        'alt_text'  => $json['imagem']['alt_text'] ?? ($json['hero_alt'] ?? ''),
                        'legenda'   => $json['imagem']['legenda']   ?? '',
                        'descricao' => $json['imagem']['descricao'] ?? '',
                    ];
                    $imgSEO = DiscoverImagemSEO::gerar($titulo, $termo, (string)$ganchoFrase, $metaExistente);
                    $this->wp->atualizarMedia($mediaId, [
                        'alt_text'    => $imgSEO['alt_text'],
                        'caption'     => $imgSEO['legenda'],
                        'description' => $imgSEO['descricao'],
                    ]);
                }
            } catch (Throwable $e) { /* não bloqueia */ }

            // Validador de links alucinados — remove <a> com URL inventada
            try {
                require_once __DIR__ . '/DiscoverLinkValidator.php';
                $valR = DiscoverLinkValidator::validar($contentAtual, (string)($this->cfg['wp_url'] ?? ''), $this->wp);
                if (!empty($valR['removidos'])) {
                    $this->wp->atualizarPost($postId, ['content' => $valR['html']]);
                    $contentAtual = $valR['html'];
                }
                $linksRemovidosAlucinados = count($valR['removidos'] ?? []);
            } catch (Throwable $e) { /* não bloqueia */ }

            // Interlinks internos standalone (funciona mesmo sem cluster formado)
            try {
                require_once __DIR__ . '/DiscoverInternalLinks.php';
                require_once __DIR__ . '/DiscoverClusterMatcher.php';
                $clusterDet2 = DiscoverClusterMatcher::detectar([
                    'termo' => $termo,
                    'categoria_ids' => $trend['categoria_ids'] ?? [],
                ]);
                $termosLink = DiscoverInternalLinks::extrairTermos($contentAtual, [
                    'termo'        => $termo,
                    'cluster_key'  => $clusterDet2['key'] ?? null,
                    'relacionados' => $trend['relacionados'] ?? [],
                ]);
                if (!empty($termosLink)) {
                    $linker = new DiscoverInternalLinks($this->wp, 5);
                    $linker->setKeywordAncora($termo);
                    $termosSeguros = !empty($clusterDet2['key'])
                        ? DiscoverClusterMatcher::termosSemanticos($clusterDet2['key'])
                        : [];
                    $termosSeguros = array_merge($termosSeguros, DiscoverInternalLinks::extrairNgramasSignificativos($contentAtual));
                    $linker->setTermosSemanticos($termosSeguros);
                    $r = $linker->injetar($contentAtual, $termosLink, [], $postId);
                    if ($r['aplicados'] > 0) {
                        $this->wp->atualizarPost($postId, ['content' => $r['html']]);
                        $contentAtual = $r['html'];
                    }
                    $internalLinksAplicados = $r['aplicados'] ?? 0;
                }
            } catch (Throwable $e) { /* não bloqueia */ }

            // Auditor
            $progress->reportar('auditando', 'Comparando nomes próprios vs fontes');
            $auditoria = DiscoverAuditor::auditar($contentAtual, $textosFontes);
            if (!$auditoria['ok']) {
                $aviso = "<div style='background:#fef2f2;border-left:4px solid #dc2626;padding:12px 16px;margin:0 0 20px;font-family:sans-serif'>"
                       . "<strong style='color:#991b1b'>⚠️ REVISÃO MANUAL (GPT) — possível alucinação detectada</strong><br>"
                       . "<span style='font-size:13px;color:#7f1d1d'>Nomes não encontrados nas fontes:</span>"
                       . "<ul style='margin:8px 0 0;color:#7f1d1d;font-size:13px'>";
                foreach ($auditoria['nomes_suspeitos'] as $n) {
                    $aviso .= "<li><strong>" . htmlspecialchars($n['nome']) . "</strong></li>";
                }
                $aviso .= "</ul></div>\n";
                $this->wp->atualizarPost($postId, ['content' => $aviso . $contentAtual]);
            }

            // Quality score + diagnósticos (abertura, fluidez, repetição)
            $quality = DiscoverQualityScore::avaliar($titulo, $contentAtual);
            $diagAbertura  = DiscoverPostProcess::diagnosticarAbertura($contentAtual);
            $diagFluidez   = DiscoverPostProcess::diagnosticarFluidez($contentAtual);
            $diagRepeticao = DiscoverPostProcess::diagnosticarRepeticoes($contentAtual);
            $diagExpositivo = DiscoverPostProcess::diagnosticarExposicaoApoH2($contentAtual);
            require_once __DIR__ . '/DiscoverKeywordLongTail.php';
            require_once __DIR__ . '/DiscoverClusterMatcher.php';
            $diagLongTail  = DiscoverKeywordLongTail::diagnosticarCobertura($contentAtual, $termo);
            $clusterDet = DiscoverClusterMatcher::detectar(['termo' => $termo, 'categoria_ids' => []]);
            $diagCompliance = DiscoverClusterMatcher::validarCompliance($contentAtual, $clusterDet);
            $diagPromessa   = DiscoverPostProcess::diagnosticarPromessaNaoCalibrada($contentAtual);
            $diagAlerta     = DiscoverPostProcess::diagnosticarAlertaForte($contentAtual);
        } catch (Throwable $e) {
            $auditoria = ['erro' => $e->getMessage()];
            $diagAbertura = ['manual' => false, 'motivo' => 'nao_avaliado'];
            $diagFluidez = []; $diagRepeticao = []; $diagExpositivo = []; $diagLongTail = ['cobertura_pct' => null, 'alerta' => false, 'h2_fora' => []];
            $clusterDet = ['nome' => null, 'key' => null]; $diagCompliance = []; $diagPromessa = [];
        }
        if (!isset($diagAbertura))    $diagAbertura    = ['manual' => false, 'motivo' => 'nao_avaliado'];
        if (!isset($diagFluidez))     $diagFluidez     = [];
        if (!isset($diagRepeticao))   $diagRepeticao   = [];
        if (!isset($diagExpositivo))  $diagExpositivo  = [];
        if (!isset($diagLongTail))    $diagLongTail    = ['cobertura_pct' => null, 'alerta' => false, 'h2_fora' => []];
        if (!isset($clusterDet))      $clusterDet      = ['nome' => null, 'key' => null];
        if (!isset($diagCompliance))  $diagCompliance  = [];
        if (!isset($diagPromessa))    $diagPromessa    = [];
        if (!isset($diagAlerta))      $diagAlerta      = ['presente' => false, 'estilo' => 'ausente', 'alerta_recomendado' => true];
        if (!isset($contentAtual))    $contentAtual    = '';

        // Aplica o novo título no WP se foi refeito pelo validator retry (após o post já estar criado)
        if (!empty($tituloInfo['refeito']) && $postId > 0) {
            try { $this->wp->atualizarPost($postId, ['title' => $titulo]); } catch (Throwable $e) {}
        }

        // GUARD FINAL ANTI-TRAVESSÃO (paridade com Claude path)
        // Manifesto editorial proíbe travessões (—/–) no corpo. PostProcess principal já tenta remover,
        // mas algum stage subsequente pode re-introduzir. Esse guard re-aplica como última checagem.
        if ($postId > 0) {
            try {
                $postFinal = $this->wp->getPost($postId);
                $contentFinal = $postFinal['content']['raw'] ?? $postFinal['content']['rendered'] ?? '';
                if ($contentFinal !== '') {
                    $emDash = substr_count($contentFinal, "\xE2\x80\x94");
                    $enDash = substr_count($contentFinal, "\xE2\x80\x93");
                    if ($emDash + $enDash > 0) {
                        $contentLimpo = DiscoverPostProcess::substituirTravessaoContextual($contentFinal);
                        if ($contentLimpo !== $contentFinal) {
                            $this->wp->atualizarPost($postId, ['content' => $contentLimpo]);
                            $progress->reportar('guard_travessao', "Removidos {$emDash} em-dash + {$enDash} en-dash que escaparam do PostProcess");
                        }
                    }
                }
            } catch (Throwable $e) { /* guard não bloqueia */ }
        }

        // 8) DB update
        $statusFinal = (!empty($auditoria) && isset($auditoria['ok']) && !$auditoria['ok']) ? 'suspeita' : 'publicado';
        if ($trendId > 0) {
            $this->db->updateStatus($trendId, $statusFinal, [
                'url_post'        => $editUrl,
                'titulo'          => $titulo,
                'publicado_em'    => date('Y-m-d H:i:s'),
                'auditoria'       => $auditoria,
                'quality_score'   => $quality['score'] ?? null,
                'quality_status'  => $quality['status'] ?? null,
                'quality_detalhes'=> $quality['detalhes'] ?? null,
                'quality_melhorias'=> $quality['melhorias'] ?? [],
                'gerado_por'      => "gpt:{$this->modelo}",
            ]);
        }

        $progress->concluido();

        return [
            'ok'             => true,
            'post_id'        => $postId,
            'titulo'         => $titulo,
            'titulo_score'   => $tituloInfo['score'],
            'titulo_falhas'  => $tituloInfo['falhas'],
            'titulo_refeito' => $tituloInfo['refeito'],
            'abertura_alerta'=> $diagAbertura['manual'] ?? false,
            'abertura_motivo'=> $diagAbertura['motivo'] ?? 'ok',
            'fluidez_issues' => $diagFluidez,
            'repeticao_issues'=> $diagRepeticao,
            'expositivo_issues'=> $diagExpositivo,
            'longtail_h2'    => $diagLongTail,
            'cluster'        => ['nome' => $clusterDet['nome'] ?? null, 'key' => $clusterDet['key'] ?? null],
            'pain'           => ($painRet = DiscoverPainClassifier::classificar($termo)),
            'arbitragem'     => (function() use ($clusterDet, $painRet, $quality) {
                require_once __DIR__ . '/DiscoverRPM.php';
                return DiscoverRPM::calcular([
                    'cluster_key'    => $clusterDet['key'] ?? '',
                    'pain'           => $painRet,
                    'score_discover' => $quality['score'] ?? null,
                ]);
            })(),
            'compliance_issues' => $diagCompliance,
            'promessa_issues'=> $diagPromessa,
            'alerta_forte'   => $diagAlerta,
            'internal_links_count'  => $internalLinksAplicados ?? 0,
            'authority_links_count' => substr_count($contentAtual, 'data-authority-link'),
            'links_alucinados_removidos' => $linksRemovidosAlucinados ?? 0,
            'edit_url'       => $editUrl,
            'fontes_usadas'  => count($fontesOk),
            'chars_fontes'   => $totalChars,
            'validation'     => $validationReport ?? null,
            'auditoria'      => $auditoria,
            'quality'        => $quality,
            'status'         => $statusFinal,
            'provedor'       => "GPT ({$this->modelo})",
        ];
    }

    /**
     * Mapeia cluster editorial → nomes de categoria WP.
     * Mesma lógica de DiscoverGerador::clusterParaCategorias mas duplicada aqui
     * por simplicidade (evita refactor pra utility class).
     */
    private static function clusterParaCategorias(string $key, string $termo): array
    {
        $termoLow = mb_strtolower($termo);
        $base = match ($key) {
            'esportes'                   => ['Esportes'],
            'noticias_info_critica'      => ['Notícias'],
            'negocios_financas'          => ['Economia'],
            'leis_governo'               => ['Direitos'],
            'saude_bem_estar'            => ['Saúde'],
            'educacao_servicos_publicos' => ['Educação'],
            'educacao'                   => ['Educação'],
            'entretenimento'             => ['Entretenimento'],
            'tecnologia'                 => ['Tecnologia'],
            'viagem_transporte'          => ['Viagens'],
            'automoveis'                 => ['Carros'],
            'comidas_bebidas'            => ['Comida'],
            'lifestyle_consumo'          => ['Lifestyle'],
            'curiosidades_geral'         => ['Curiosidades'],
            default                      => ['Notícias'],
        };
        if ($key === 'esportes') {
            $torneio = match (true) {
                str_contains($termoLow, 'brasileirão') || str_contains($termoLow, 'brasileirao') => 'Brasileirão',
                str_contains($termoLow, 'libertadores') => 'Libertadores',
                str_contains($termoLow, 'sul-americana') || str_contains($termoLow, 'sulamericana') => 'Sul-Americana',
                str_contains($termoLow, 'champions') => 'Champions League',
                str_contains($termoLow, 'copa do mundo') || str_contains($termoLow, 'seleção brasileira') => 'Seleção',
                str_contains($termoLow, 'fórmula 1') || str_contains($termoLow, 'formula 1') || str_contains($termoLow, ' f1 ') => 'Fórmula 1',
                str_contains($termoLow, 'ufc') || str_contains($termoLow, 'mma') => 'MMA',
                str_contains($termoLow, 'nba') => 'Basquete',
                default => null,
            };
            if ($torneio !== null) $base[] = $torneio;
        }
        return $base;
    }

    /** Monta system + user prompt usando manifesto editorial + schema de saída esperado. */
    private function montarPrompt(string $termo, ?array $briefing, array $fontes, string $formato): array
    {
        require_once __DIR__ . '/DiscoverPromptBuilder.php';
        // Manifesto, data, regras temporais, blindagem, regra de links — agora canônicos via builder.

        $fontesBloco = '';
        foreach ($fontes as $i => $f) {
            $m = $f['fonte']['meta'] ?? [];
            $p = $f['fonte']['content']['paragraphs'] ?? [];
            $fontesBloco .= "\n── FONTE " . ($i + 1) . " (" . ($m['site_name'] ?? '?') . ") ──\n";
            if (!empty($m['title']))       $fontesBloco .= "Título: " . $m['title'] . "\n";
            if (!empty($m['published']))   $fontesBloco .= "Publicado: " . $m['published'] . "\n";
            if (!empty($m['description'])) $fontesBloco .= "Descrição: " . $m['description'] . "\n";
            $fontesBloco .= "Conteúdo:\n" . implode("\n\n", array_slice($p, 0, 15)) . "\n";
        }

        $briefingBloco = '';
        if (!empty($briefing)) {
            $briefingBloco = "\n═══ BRIEFING EDITORIAL ═══\n"
                           . "Grupo: " . ($briefing['grupo_editorial'] ?? '-') . "\n"
                           . "Ângulo principal: " . ($briefing['angulo_principal'] ?? '-') . "\n"
                           . "Ângulo universal: " . ($briefing['angulo_universal'] ?? '-') . "\n"
                           . "Intenção: " . ($briefing['intencao'] ?? '-') . "\n"
                           . "Título sugerido: " . ($briefing['titulo_sugerido'] ?? '-') . "\n"
                           . "Gancho P1: " . ($briefing['gancho_p1'] ?? '-') . "\n";
            if (!empty($briefing['h3_sugeridos'])) {
                $briefingBloco .= "Subtítulos sugeridos:\n- " . implode("\n- ", $briefing['h3_sugeridos']) . "\n";
            }
            if (!empty($briefing['palavras_chave'])) {
                $briefingBloco .= "Keywords: " . implode(", ", $briefing['palavras_chave']) . "\n";
            }
        }

        $d = DiscoverPromptBuilder::dataAtual();
        $hoje = $d['hoje']; $diaSemana = $d['dia_semana'];

        // Bloco persona — voz editorial do site ativo (paridade com Claude).
        // PRIORIDADE SOBRE BRIEFING: se trend term é genérico mas site é nicho específico,
        // o ângulo deve ser reescrito sob a lente do nicho (ex: shopping nunca vira info).
        $personaBloco = '';
        $p = $this->cfg['persona'] ?? null;
        if (is_array($p) && !empty($p['autor'])) {
            $proibidos = is_array($p['termos_proibidos'] ?? null) ? implode('; ', $p['termos_proibidos']) : '';
            $subtipo = trim((string)($this->cfg['subtipo_nicho'] ?? ''));
            $canibal = is_array($this->cfg['termos_canibal'] ?? null)
                ? array_slice($this->cfg['termos_canibal'], 0, 12) : [];
            $empresa = trim((string)($this->cfg['empresa']['nome'] ?? ''));

            $personaBloco = "\n═══ PERSONA DESTE SITE (TEM PRIORIDADE — override de briefing genérico) ═══\n"
                          . "SITE: " . ($this->cfg['_site_name'] ?? '?') . "\n"
                          . "AUTOR: " . (string)$p['autor'] . "\n"
                          . "VOZ: " . (string)($p['voz'] ?? '') . "\n"
                          . "ESPECIALIDADE: " . (string)($p['especialidade'] ?? '') . "\n"
                          . "AUDIÊNCIA-ALVO: " . (string)($p['audiencia'] ?? '') . "\n"
                          . "TOM: " . (string)($p['tom'] ?? '') . "\n"
                          . ($proibidos !== '' ? "PROIBIDOS: {$proibidos}\n" : '')
                          . (!empty($p['cta_estilo']) ? "CTAs TÍPICAS: " . $p['cta_estilo'] . "\n" : '')
                          . ($subtipo !== '' ? "SUBTIPO NICHO: {$subtipo}\n" : '')
                          . ($empresa !== '' ? "EDITORA: {$empresa}\n" : '')
                          . (!empty($canibal) ? "EVITE (são de sites irmãos): " . implode('; ', $canibal) . "\n" : '')
                          . "REGRA DE OVERRIDE: se o termo da trend é genérico (data, pessoa, evento), REESCREVA o ângulo sob a lente do nicho deste site. "
                          . "SHOPPING (comocomprar, ondecompraragora) → '10 ideias até R\$ X', 'oferta', 'comparativo', 'guia de compra' (NUNCA 'data, origem, história'). "
                          . "SERVIÇO (vagasebeneficios) → 'quem tem direito', 'como solicitar', 'prazo'. "
                          . "EDUCAÇÃO (cursosenac, guiadoscursos) → 'cursos abertos', 'como se inscrever', 'edital'. "
                          . "ESPORTE (leaodabarra) → 'onde assistir', 'escalação', 'análise tática' (NUNCA 'biografia/origem').\n"
                          . ($subtipo !== '' ? "Se o termo bate só tangencialmente em '{$subtipo}', ENCAIXE no subtipo. Sem encaixe possível, escolha o ângulo MAIS PRÓXIMO.\n" : '')
                          . "\n";
        }

        $system = "Você é redator sênior de portal viral brasileiro, especialista em Google Discover + SEO + alta retenção mobile.\n\n"
            . DiscoverPromptBuilder::blocoManifesto()
            . DiscoverPromptBuilder::regrasTemporais('gerar') . "\n"
            . $personaBloco
            . "MODO: ARTIGO DISCOVER (formato {$formato})\n"
            . "- Estrutura em pílulas (H2/H3 a cada 2-3 parágrafos)\n"
            . "- TÍTULO com TENSÃO obrigatória: junte FATO + CONSEQUÊNCIA usando dois pontos (:), ponto-e-vírgula (;) ou parênteses (...). NUNCA travessão (—) ou en-dash (–). Ex forte: 'Isenção do ENEM 2026 encerra dia 24: quem perder paga a taxa cheia' ou 'BPC libera 2ª parcela (4 milhões ficam de fora por erro de cadastro)'.\n"
            . "- LEAD DE ESCALA + OBSTÁCULO (prioritário quando a fonte tem volumetria): abra com [ESCALA+ação positiva] + [MAS] + [detalhe que bloqueia]. Ex: 'Milhões vão receber até R\$ 1 mil sem declarar — mas detalhe no cadastro bancário impede o depósito.' Se a fonte não tem escala, use um dos outros 6 padrões.\n"
            . "- DIFERENCIAL NO TOPO: quando a fonte traz insight único (cashback automático, regra inédita, mudança anunciada agora), esse diferencial vai no TÍTULO ou no 1º parágrafo. Não descreva como guia geral.\n"
            . "- LEAD: escolha UM dos 6 padrões (NÃO use sempre o mesmo): (1) COUNTDOWN — prazo <72h: 'Falta menos de X horas para...'. (2) GAP/INSIGHT — 'Quem tem [X] pode ter [problema] sem nem saber...'. (3) NÚMERO-FIRST — '[N] pessoas têm direito, mas só [Y] conseguem...'. (4) CONTRASTE — 'Parece X. Não é. Na verdade...'. (5) CASE CONCRETO — exemplo real da fonte. (6) DATA-CHAVE — '[Dia], [data] muda [regra]'. Rotacione padrões entre artigos, não aplique sempre o mesmo. Máx 3 linhas.\n"
            . "- ÚLTIMA FRASE DO LEAD: nunca aforismo genérico ('O erro é silencioso', 'Sem aviso', 'Passa batido'). Prefira AÇÃO prática ou DETALHE específico da fonte.\n"
            . "- PROIBIDO abertura manual/tutorial: 'Os [N] grupos', 'As [N] regras', 'Conheça os', 'Veja quem tem direito', 'Saiba quem pode', 'Existem [N] perfis'. Isso é tutorial. Abra com RISCO/CONSEQUÊNCIA, não com enumeração fria.\n"
            . "- PROIBIDA ABERTURA NEUTRA: 'Se você se encaixa em algum desses perfis', 'Caso você tenha', 'Para quem busca', 'Aqueles que', 'Você que é', 'Fique atento'. São soft. O Discover premia framing de RISCO. Substitua por: 'Muita gente vai perder [X] por causa de [Y]', '[N] candidatos costumam ser barrados por [detalhe concreto]', 'O que elimina mais pedidos é [motivo específico]'.\n"
            . "- <ul class='bloco-resumo'> após 1º parágrafo com 2-3 <li> factuais em <strong>\n"
            . "- Parágrafos MÁX 3-4 linhas mobile\n"
            . "- Tabelas <table> pra valores/calendários/comparações\n"
            . "- Blockquote amarelo com dado crítico destacado\n"
            . "- PRIMEIRO <p> APÓS CADA H2 = AÇÃO/ALERTA/DECISÃO (nunca expositivo puro). Leitor deve saber o que FAZER ou EVITAR logo que bate no H2. Errado: 'O CadÚnico é um cadastro do governo federal'. Certo: 'Quem não atualizou o CadÚnico em 24 meses deve correr — o sistema nega a isenção automaticamente.'\n"
            . "- LONG-TAIL NOS H2 (≥50%): pelo menos metade dos H2 cobrem intenção de busca long-tail da keyword. Intenções-base: elegibilidade ('Quem tem direito a X'), processo ('Como pedir X passo a passo'), prazo ('Prazo para X'), requisitos ('Documentos necessários para X'), valor ('X vale quanto'), resultado ('Quando sai o resultado de X'), negativa ('O que fazer se X for negada'). Combine cada variação com dado da fonte (número/data/critério) — nunca a variação pelada.\n"
            . "- H2/H3 INFORMATIVOS E ESPECÍFICOS — PROIBIDO começar com metáforas genéricas: 'Pulo do Gato:', 'Sem Enrolação:', 'Direto ao Ponto:', 'Dinheiro no Bolso:', 'No Papel:', 'Na Prática:', 'De Olho em:', 'Dica de Ouro:'. Use dado/número/ação concreta.\n"
            . "- Linguagem coloquial brasileira NO CORPO do texto (não em títulos).\n"
            . "- CTA FINAL em 2 frases editoriais: (1) AÇÃO específica + PRAZO/CONSEQUÊNCIA; (2) PONTE pro próximo conteúdo com link interno contextual. Ex: 'Quem tem direito deve fazer o pedido ainda em abril; depois do dia 24 o candidato paga a taxa cheia. Se o objetivo é uma vaga pública, vale conhecer <a href=\"/concursos-federais-2026\">os concursos federais com edital previsto para 2026</a>.'\n"
            . "- PROIBIDO no CTA: 'manda pra alguém', 'manda esse artigo', 'manda pro grupo', 'compartilhe', 'passa o link' — informalidade demais pro padrão editorial.\n"
            . "- VARIAÇÃO HUMANA (princípio, não fórmula): inclua 1 recurso de quebra de ritmo. Pode ser: (a) pausa curta em <p> próprio com 3-7 palavras CONTEXTUAIS ao assunto específico (NÃO copie frases prontas — derive do conteúdo: ex 'O cadastro ainda aceita edições.', 'A regra já vale pra março.'); (b) contraste inesperado reformulado com dado da fonte; (c) exemplo real. PROIBIDO VERBATIM: 'A vaga não espera.', 'É aqui que a maioria erra.', 'Parece simples. Não é.', 'A maioria perde por isso.', 'Quem chega depois, não entra.', 'Fica a dica.', 'Simples assim.' — essas viraram templates detectáveis.\n"
            . "- FRASES PROIBIDAS (cara-de-IA): 'Se você ainda não [verbo], leia isso agora', 'processo leva menos de N minutos' / 'leva poucos minutos' / 'é rapidinho' (cite tempo só com número e sujeito específico da fonte), 'Olha só cada um deles:', 'Entenda tudo sobre', 'Saiba mais sobre', 'Vale a pena ficar atento', 'Descubra agora', 'Tudo o que você precisa saber sobre', 'Neste artigo', 'Continue lendo'.\n"
            . "- ANTI-REDUNDÂNCIA: o mesmo dado (valor, data, público) não pode aparecer em 3+ lugares estruturais (título + TL;DR + H2 + intro). Distribua em ângulos diferentes. Corte ~15% de gordura: zero 'É importante', 'Vale destacar', 'Como já mencionamos', 'Conforme dito'.\n"
            . "- ANTI-REPETIÇÃO SEMÂNTICA: uma mesma expressão factual (data, valor, keyword) NÃO pode aparecer +3x com wording idêntico. A partir da 4ª ocorrência, use variação: '24 de abril' → 'nesta quinta', 'no último dia do prazo', 'até a data limite'; 'R\$ 85' → 'o valor da taxa', 'a cobrança'. Repetição ≥4x = over-optimization detectável.\n"
            . "- CONSEQUÊNCIA em 3 LUGARES: o que o leitor PERDE se não agir precisa aparecer em (1) TÍTULO após separador, (2) LEAD (camada 2), (3) CTA FINAL (1ª frase). Verbos de urgência real: paga, perde, fica de fora, deixa de receber, é eliminado, tem o pedido negado. Nunca invente — consequência sempre vem da fonte.\n"
            . "- EXPANSÃO DE FATO EM CONSEQUÊNCIA: todo detalhe técnico precisa carregar impacto prático pro leitor. FRACO: 'erro nos dados bancários'. FORTE: 'erro nos dados bancários impede o pagamento e atrasa a restituição em até 6 meses' (se a fonte sustenta). Regra: a cada detalhe, pergunte 'o que isso significa na prática?' e escreva a resposta concreta.\n"
            . "- CALIBRAGEM DE PROMESSA (crítico pra alcance no Discover): valor+público de escala SEMPRE com qualificador. ❌ 'R\$ 1 mil para 4 milhões de brasileiros' (exagerado). ✅ 'R\$ 1 mil para brasileiros que cumprem X, grupo estimado em 4 milhões pela fonte'. Use 'pra quem se enquadra', 'conforme critério', 'apenas quem cumpre Y'. Remove ambiguidade de elegibilidade.\n"
            . "- CONCRETUDE (anti-abstrato): todo termo técnico vem com cenário real — quanto tempo, qual dado, qual situação. FRACO: 'cadastro desatualizado'. FORTE: 'quem não atualiza há mais de 2 anos'. FRACO: 'dados divergentes'. FORTE: 'quando a renda declarada não bate com o extrato'. FRACO: 'documentação incompleta'. FORTE: 'quem esquece de anexar o comprovante de matrícula'. Cada adjetivo (desatualizado/divergente/incompleto/irregular/pendente) precisa do cenário QUANTO TEMPO ou QUAL DADO.\n"
            . "- FRASE FORTE POR SEÇÃO (1 por H2): última frase de cada seção é um micro-choque ≤15 palavras. Ex: '90% ignora esse detalhe e paga a conta', 'É o erro que mais trava restituição', 'Quem não confere, perde'. Derivada do conteúdo da seção, não genérica.\n"
            . "- GANCHOS DE SCROLL (1-2 no MEIO do texto, entre H2 #2 e #4): frase curta (max 12 palavras) que puxe pra continuar lendo. DERIVADA do tema deste artigo — NÃO frases genéricas ('É aqui que a maioria erra', 'Parece simples. Não é.' — proibidas verbatim). Estrutura: 'É aqui que [ação/consequência específica do tema]' OU '[Termo específico] é o que mais [verbo de risco]' OU 'Esse é o detalhe que [consequência]'. Ex contextual: 'É aqui que mais pedidos de isenção são negados silenciosamente' ou 'O envio errado do comprovante é o que mais elimina candidatos'.\n"
            . "- ALERTA FORTE (1 obrigatório): insira 1 <div style='background:#fef2f2;border-left:4px solid #dc2626;padding:14px 18px;margin:24px 0;border-radius:6px'><strong style='color:#991b1b;display:block'>⚠️ ATENÇÃO: [ERRO CRÍTICO em 6-10 palavras]</strong><span style='color:#7f1d1d'>[1-2 frases do erro baseado na fonte]</span></div> no MEIO do artigo (após 2º H2), destacando o erro que barra/elimina/nega — usando o gancho principal extraído da fonte. NÃO inventar risco; só usar o que a fonte sustenta.\n"
            . "- INTERPRETAÇÃO EDITORIAL (1 por artigo): 1 frase que conecta pontos além da descrição. Diferente de voz de especialista (observação prática). Fórmula: 'O que parece Y é, na prática, Z'. Ex: 'O que parece um detalhe administrativo é, na prática, uma filtragem por cadastro regular.' Posicionar entre H2 2-3. Não inventar número; pode citar 'padrão observado'.\n"
            . "- VOZ DE ESPECIALISTA (1 por artigo): 1 parágrafo curto (2-3 linhas) com tom de insider — 'Na prática, o erro mais comum que leva a [PROBLEMA] é [DETALHE da fonte]', 'Quem trabalha com isso sabe: [observação da fonte]', 'O que se vê em campo é [padrão da fonte]'. Nunca inventar estatística interna; formular impessoal. Posicionar entre H2 2-3 ou antes da conclusão.\n"
            . "- MICRO-NARRATIVA (1 obrigatória por artigo): 1 parágrafo no meio do corpo com cenário real — alguém que viveu a situação, erro comum documentado na fonte. Max 3 linhas. Ex: 'Quem perdeu o Enem em 2025 agora precisa justificar — e muita gente esquece esse detalhe e paga a taxa sem precisar.' NUNCA inventar personagem fictício ('Joana, 34 anos'). Conectar regra abstrata com drama prático (perdeu, pagou, ficou sem, descobriu tarde).\n"
            . "- Aspas SIMPLES em atributos HTML. NUNCA aspas duplas dentro do HTML.\n"
            . "- Zero alucinação: use APENAS fatos das fontes abaixo.\n\n"
            . DiscoverPromptBuilder::blocoHumanoEspecialista() . "\n"
            . DiscoverPromptBuilder::blocoCTACompartilhamento() . "\n"
            . DiscoverPromptBuilder::blocoLinksAfiliado() . "\n"
            . DiscoverPromptBuilder::blindagemAntiAlucinacao() . "\n"
            . DiscoverPromptBuilder::regraLinksInternos((string)($this->cfg['wp_url'] ?? ''), 'gerar') . "\n"
            . DiscoverPromptBuilder::schemaGerar($termo);

        // GANCHO DE ALTO CTR — extrai o risco/gap mais forte da fonte antes de gerar
        require_once __DIR__ . '/DiscoverGanchoExtrator.php';
        require_once __DIR__ . '/DiscoverKeywordLongTail.php';
        require_once __DIR__ . '/DiscoverClusterMatcher.php';
        $gancho = DiscoverGanchoExtrator::extrair($fontes);
        $ganchoInstrucao = DiscoverGanchoExtrator::instrucaoProPrompt($gancho);
        $longTailInstrucao = DiscoverKeywordLongTail::instrucaoProPrompt($termo);
        $prazoProximo = DiscoverGanchoExtrator::detectarPrazoProximo($fontes);
        // Dor dominante — calibra tom por tipo de gatilho
        require_once __DIR__ . '/DiscoverPainClassifier.php';
        $contextoDor = (string)($gancho['frase'] ?? '') . ' ' . ($gancho['diferencial']['frase'] ?? '');
        $dor = DiscoverPainClassifier::classificar($termo, $contextoDor);
        $dorInstrucao = DiscoverPainClassifier::instrucaoProPrompt($dor);
        $countdownInstrucao = $prazoProximo !== null
            ? "\n═══ COUNTDOWN OBRIGATÓRIO (prazo em {$prazoProximo['dias_restantes']} dias) ═══\n"
              . "A fonte informa prazo em {$prazoProximo['data']}. O LEAD DEVE abrir com 'Faltam {$prazoProximo['dias_restantes']} dias para [ação]' + obstáculo específico.\n"
              . "═══ FIM COUNTDOWN ═══\n"
            : '';
        // Detecta cluster editorial com base no termo
        $cluster = DiscoverClusterMatcher::detectar(['termo' => $termo, 'categoria_ids' => []]);
        $clusterInstrucao = DiscoverClusterMatcher::instrucaoProPrompt($cluster);

        $user = "PALAVRA-CHAVE: {$termo}\n"
              . $briefingBloco
              . $clusterInstrucao
              . $countdownInstrucao
              . $ganchoInstrucao
              . $dorInstrucao
              . $longTailInstrucao
              . "\n═══ FONTES SCRAPEADAS ═══"
              . $fontesBloco;

        return [$system, $user];
    }
}
