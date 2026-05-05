<?php
/**
 * Pós-processamento do HTML gerado antes de ir pro WP.
 *
 * Responsabilidades:
 *  1. Remove schemas Article/NewsArticle (Rank Math já gera — evita duplicidade)
 *  2. Auto-linka telefones BR: <a href="tel:+55...">
 *  3. Auto-linka WhatsApp: <a href="https://wa.me/55...">
 *  4. Injeta FAQPage schema quando detecta seção de FAQ
 *  5. Injeta HowTo schema quando detecta seção passo-a-passo (<ol> após H2 tipo "Como...")
 *  6. Garante bloco-resumo (TL;DR) após o primeiro parágrafo se Claude esqueceu
 */
class DiscoverPostProcess
{
    /**
     * Processa o HTML aplicando todas as transformações.
     * @param string $html
     * @param array  $meta  ['titulo'=>..., 'url'=>..., 'ranker_produtos'=>?, '_image_url'=>?] — pra schemas
     * @param array  $trend Trend completo do DiscoverDb (cluster_detect, origem, pain) — opcional, habilita schemas rich
     * @param array  $cfg   Cfg do site (persona, site_name, wp_url) — opcional, habilita schemas rich
     * @return string HTML transformado
     */
    public static function processar(string $html, array $meta = [], array $trend = [], array $cfg = []): string
    {
        if (trim($html) === '') return $html;
        // Defesa em profundidade: se QUALQUER etapa interna explodir com erro inesperado,
        // retornamos o HTML ORIGINAL em vez de bloquear o post. Enriquecimentos (schemas,
        // hub-spoke, badges) são PLUS — post precisa subir mesmo sem eles.
        $htmlOriginal = $html;
        try {
            return self::processarInterno($html, $meta, $trend, $cfg);
        } catch (Throwable $e) {
            error_log('[DiscoverPostProcess] erro fatal: ' . $e->getMessage() . ' — retornando HTML original');
            return $htmlOriginal;
        }
    }

    private static function processarInterno(string $html, array $meta = [], array $trend = [], array $cfg = []): string
    {
        // 0-pre. Decode \n \r \t LITERAIS em texto fora de tags. Caso real #4995:
        // Sonnet retorna `\\n` no source JSON → após json_decode vira `\` + `n` literal
        // bruto. Detectores de Schema (HowTo, FAQ) e migradores de h3-pergunta dependem
        // de `\s*` em regex que NÃO casa com bytes `\` + `n`. Sem decode, HowTo schema
        // não é detectado mesmo com <h2>Passo a passo</h2><ol><li>... bem formado.
        $html = preg_replace_callback(
            '#(<[^>]*>)|([^<]+)#',
            function ($m) {
                if (!empty($m[1])) return $m[1]; // tag — preserva atributos com \n se houver
                return str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $m[2]);
            },
            $html
        ) ?? $html;

        // 0. Converte "Leia também" antigo (cards com imagens) em lista simples de títulos
        $html = self::limparLeiaTambemAntigo($html);

        // 0b. Remove prefixos metafóricos vazios em H2/H3 ("Pulo do Gato:", "Sem Enrolação:", etc)
        $html = self::removerPrefixosMetaforicosH2($html);

        // 0c. Corrige alucinações temporais (ex: "24 de abril — mesma data de hoje" quando hoje é 22/04)
        $html = self::corrigirAlucinacoesTemporais($html);

        // 0d. Remove/substitui frases-template (cara-de-IA que matam autenticidade)
        $html = self::removerFrasesTemplate($html);

        // 0e. Se houver múltiplas seções de FAQ/Perguntas frequentes, mantém só a ÚLTIMA (schema roda nela)
        $html = self::dedupeFaq($html);

        // 0e-bis. Dedupe de SCHEMA JSON-LD FAQPage duplicado (mantém só o último)
        $html = self::dedupeSchemaFaq($html);

        // 0e-ter. Remove H1 do body — WP já renderiza H1 do título via tema.
        // LLM às vezes inclui H1 dentro do content, gerando duplicate H1 no DOM
        // (perigoso pra SEO: Google confunde qual é o título canônico do post).
        $html = preg_replace('#<h1\b[^>]*>.*?</h1>#isu', '', $html) ?? $html;

        // 0f. Dedupe de "Leia também" / "Veja também" — mantém só 1 bloco (o último)
        $html = self::dedupeLeiaTambem($html);

        // 0g. Sanitiza anchors YMYL borderline em blocos de interlink
        //     ("Saque de até R$ 7.200" → "Saque do FGTS" — preserva link, suaviza promessa)
        $html = self::sanitizarAnchorsYmyl($html);

        // 0h. Substitui travessão (—/–) por pontuação contextual PT-BR (vírgula, dois-pontos, etc)
        $html = self::substituirTravessaoContextual($html);

        // 0i. Limpa interlinks internos inadequados (dentro de FAQ, duplicados)
        //     Dá espaço pro DiscoverInternalLinks redistribuir corretamente depois.
        $html = self::limparInterlinksInadequados($html);

        // 1. Remove schemas que Rank Math gera
        $html = self::removerSchemasRedundantes($html);

        // 2. Auto-links de telefone/WhatsApp (ordem importa: WhatsApp antes de tel:)
        $html = self::autoLinkWhatsApp($html);
        $html = self::autoLinkTelefones($html);

        // 2b. Auto-link de órgãos oficiais (gov.br) — E-E-A-T sinal de fonte autoritária
        require_once __DIR__ . '/DiscoverAuthorityLinks.php';
        $html = DiscoverAuthorityLinks::aplicar($html)['html'];

        // 2c. Auto-link de DOMÍNIOS LITERAIS no texto (ex: "enem.inep.gov.br" → <a href>)
        //     Cobre o caso onde Claude menciona URL específica sem linkar.
        //     Crítico: domínios oficiais (.gov.br, .edu.br) preservam autoridade do leitor.
        $html = self::autoLinkDominios($html);

        // 2d. FAQ ENRICHER — se artigo NÃO tem FAQ próprio E temos PAA cacheado,
        //     injeta seção <h2>Perguntas frequentes</h2> com perguntas LITERAIS do
        //     Google + answer_snippets. Garante FAQPage schema (rich snippet) mesmo
        //     quando Sonnet ignora as instruções do CtrIntel.
        try {
            require_once __DIR__ . '/DiscoverFaqEnricher.php';
            $html = DiscoverFaqEnricher::aplicar($html, $meta, $trend);
        } catch (Throwable $e) { /* enriquecimento é PLUS — não bloqueia post */ }

        // 3. FAQPage schema (se tem FAQ, injeta JSON-LD) — pega tanto FAQ orgânico
        //    do Sonnet quanto o injetado pelo FaqEnricher acima
        $html = self::injetarFaqSchema($html);

        // 4. HowTo schema (se tem passo-a-passo estruturado)
        $html = self::injetarHowToSchema($html, $meta);

        // 4b. SCHEMAS RICH (G1) — NewsArticle + BreadcrumbList + Person + Course/Event/ItemList
        // Só dispara se caller passou trend+cfg. Falha-silenciosa: schema é PLUS — post
        // PRECISA continuar mesmo se schema gen explodir (ex: cfg corrompida).
        if (!empty($trend) && !empty($cfg)) {
            try {
                require_once __DIR__ . '/DiscoverSchemas.php';
                if (!DiscoverSchemas::jaInjetado($html)) {
                    $schemaHtml = DiscoverSchemas::gerar($meta, $trend, $cfg);
                    if ($schemaHtml !== '') {
                        $html .= $schemaHtml;
                    }
                }
            } catch (Throwable $e) { /* schema é opcional — não bloqueia post */ }
        }

        // 4c. INTERNAL LINKING G4 — Breadcrumbs visuais + Continue lendo + Back to Hub
        if (!empty($trend) && !empty($cfg) && !empty($cfg['wp_url']) && !empty($cfg['wp_user']) && !empty($cfg['wp_app_password'])) {
            try {
                require_once __DIR__ . '/DiscoverRelatedLinks.php';
                require_once __DIR__ . '/DiscoverDb.php';
                require_once __DIR__ . '/Wordpress.php';
                $dbG4 = new DiscoverDb();
                $wpG4 = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
                $html = DiscoverRelatedLinks::injetar($html, $meta, $trend, $cfg, $dbG4, $wpG4);
            } catch (Throwable $e) { /* falha silenciosa */ }
        }

        // 4c-bis. GLOSSÁRIO DE BACKLINKS FIXOS (cluster topical authority)
        // Termo recorrente → URL canônica do site. Cada match na 1ª ocorrência (fora de
        // headings/tabelas/details/anchors). Configurado em sites.php → internal_link_glossary.
        // Pivot leaodabarra 2026-05-02: 'Esporte Clube Vitória' → /historia-do-...,
        // 'Copa do Nordeste' → /category/copa-do-nordeste/, 'Barradão' → /barradao/, etc.
        if (!empty($cfg['internal_link_glossary']) && is_array($cfg['internal_link_glossary'])) {
            try {
                require_once __DIR__ . '/InternalLinkGlossary.php';
                $glossarioRet = InternalLinkGlossary::aplicar($html, [
                    'wp_url'    => (string)($cfg['wp_url'] ?? ''),
                    'glossario' => $cfg['internal_link_glossary'],
                ]);
                if (!empty($glossarioRet['html']) && $glossarioRet['html'] !== $html) {
                    $html = $glossarioRet['html'];
                }
            } catch (Throwable $e) { /* falha silenciosa — glossário é PLUS */ }
        }

        // 4d. TRUST BLOCKS T1 — Affiliate disclosure + Fontes consultadas + Sobre o autor
        if (!empty($trend) && !empty($cfg)) {
            try {
                require_once __DIR__ . '/DiscoverTrustBlocks.php';
                $html = DiscoverTrustBlocks::injetar($html, $meta, $trend, $cfg);
            } catch (Throwable $e) { /* falha silenciosa */ }
        }

        // 4e. AFFILIATE ATTRIBUTION C1 — anexa ?p={post_id} em links Pretty Links pra
        // attribution post→click→sale (cc-click-logger captura via query no WP).
        // Só dispara se caller passou meta['post_id'] (DiscoverGerador faz após criar post).
        $postIdAttr = (int)($meta['post_id'] ?? 0);
        if ($postIdAttr > 0) {
            try {
                require_once __DIR__ . '/AfiliadoLinkBuilder.php';
                $prefixos = [(string)($cfg['pretty_links_prefix'] ?? 'go')];
                $html = AfiliadoLinkBuilder::aplicarEmHtml($html, $postIdAttr, $prefixos);
            } catch (Throwable $e) { /* falha silenciosa — não bloqueia post */ }
        }

        // 4e-bis. AFILIADO BR (multi-rede) — injeta tags nas 4 maiores redes brasileiras
        // em URLs brutas que Sonnet colou direto: Amazon, Magalu, Mercado Livre, Shopee.
        // ProductRanker já injeta nas URLs que ele gera. Este pega URLs do meio do texto.
        // Sub-IDs ({tag}-{post_id}) permitem reports atribuir venda ao post específico.
        // Tags configuráveis em sites.php por site (amazon_associates_tag, magalu_partner_id,
        // ml_matt_word, shopee_af_siteid). Se a tag não está setada → rede é skipada.
        try {
            require_once __DIR__ . '/DiscoverAfiliadoBR.php';
            $html = DiscoverAfiliadoBR::aplicar($html, $cfg, $postIdAttr);
        } catch (Throwable $e) { /* falha silenciosa */ }

        // 4f. AI OVERVIEW B1 — TL;DR + Speakable schema pra Google citar em featured snippet.
        // Discover/Search "AI Overview" extrai resumo direto. TL;DR estruturado = +30% CTR brand.
        if (!empty($meta['titulo']) && !empty($meta['url'])) {
            try {
                require_once __DIR__ . '/DiscoverAiOverview.php';
                $html = DiscoverAiOverview::aplicar($html, $meta, $trend);
            } catch (Throwable $e) { /* falha silenciosa */ }
        }

        // 4f-bis. IMAGE PERFORMANCE — lazy load + fetchpriority em <img> (Core Web Vitals).
        // 1ª imagem: eager + fetchpriority=high (LCP candidate). Demais: lazy + decoding=async.
        try {
            require_once __DIR__ . '/DiscoverImagemPerformance.php';
            $html = DiscoverImagemPerformance::otimizar($html);
        } catch (Throwable $e) { /* falha silenciosa */ }

        // 4g. QUOTE ENRICHMENT — extrai 1 citação da fonte oficial e injeta como blockquote.
        // Sinal forte E-E-A-T (Google adora). Só dispara se caller passou meta['fontes'].
        if (!empty($meta['fontes']) && is_array($meta['fontes'])) {
            try {
                require_once __DIR__ . '/DiscoverQuoteEnrichment.php';
                $html = DiscoverQuoteEnrichment::aplicar($html, $meta['fontes'], $meta);
            } catch (Throwable $e) { /* falha silenciosa */ }
        }

        // 5a. Reverte msg-cards posicionados ANTES do 1º H2 (intro/TL;DR convertido errado)
        $html = self::reverterCardsNaIntro($html);

        // 5. Mensagens compartilháveis: <li> vira <div class="msg-card"> com botões Copiar + WhatsApp
        $html = self::transformarMensagensEmCards($html, $meta);

        // 6. Botão final de compartilhamento do post inteiro (sinal social pro Discover)
        $html = self::inserirBotaoCompartilharPost($html, $meta);

        // 7. RESOURCE HINTS — preconnect/dns-prefetch pras CDNs/marketplaces detectados.
        // Reduz latency mobile 100-200ms (Core Web Vitals = ranking factor Discover).
        // Funciona sem CDN próprio. Browsers honram <link> em qualquer posição.
        try {
            require_once __DIR__ . '/DiscoverResourceHints.php';
            $html = DiscoverResourceHints::aplicar($html);
        } catch (Throwable $e) { /* hints são PLUS — não bloqueia post */ }

        // 8. BACKSTOP DEDUPE FAQ — roda DE NOVO no final, depois de TODOS os enrichers
        // (FaqEnricher, LandingBuilder buildFaqHtml em Maquina, etc.) que podem ter injetado
        // bloco FAQ extra mesmo após o dedupe inicial da etapa 0e. Caso real #738 leaodabarra:
        // Claude gerou FAQ inline + Maquina concatenou buildFaqHtml(faq) extra → 2 FAQs no post.
        // O dedupeFaq do início rodou antes dessa concatenação. Backstop garante 1 só.
        $html = self::dedupeFaq($html);
        $html = self::dedupeSchemaFaq($html);

        return $html;
    }

    // ═══ BOTÃO FINAL DE COMPARTILHAMENTO DO POST ═══
    private static function inserirBotaoCompartilharPost(string $html, array $meta): string
    {
        $url    = (string)($meta['url']    ?? '');
        $titulo = (string)($meta['titulo'] ?? '');
        if ($url === '' || $titulo === '') return $html;
        // Idempotente: se já tem o bloco, não duplica
        if (strpos($html, 'data-post-share') !== false) return $html;

        // Compartilhamento: WhatsApp (texto + URL) + link copiável
        $msgTxt   = rawurlencode($titulo . "\n\n" . $url);
        $waHref   = 'https://wa.me/?text=' . $msgTxt;
        $urlEsc   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $tituloEsc= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');

        $bloco = '<div class="post-share" data-post-share="1" style="margin:30px 0 10px;padding:18px 22px;background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border-left:4px solid #25d366;border-radius:8px;text-align:center">'
               . '<p style="margin:0 0 12px;font-size:15px;font-weight:600;color:#0c4a6e">💡 Conteúdo útil? Encaminhe pra quem está nessa situação:</p>'
               . '<a href="' . htmlspecialchars($waHref, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" '
               .    'style="display:inline-block;padding:10px 22px;background:#25d366;color:#fff;border-radius:6px;text-decoration:none;font-weight:700;font-size:14px;margin:0 4px">'
               .    '💬 Compartilhar no WhatsApp</a> '
               . '<button type="button" class="post-share-copy" data-url="' . $urlEsc . '" '
               .    'style="display:inline-block;padding:10px 22px;background:#e5e7eb;color:#374151;border:none;border-radius:6px;font-weight:700;font-size:14px;cursor:pointer;margin:0 4px">'
               .    '🔗 Copiar link</button>'
               . '</div>';

        // Script de cópia (injeta uma vez)
        if (strpos($html, 'data-post-share-runtime') === false) {
            $bloco .= '<script data-post-share-runtime="1">'
                   . 'document.addEventListener("click",function(e){'
                   .   'if(!e.target.classList.contains("post-share-copy"))return;'
                   .   'e.preventDefault();var u=e.target.dataset.url;if(!u)return;'
                   .   'var done=function(){e.target.textContent="✓ Link copiado";setTimeout(function(){e.target.textContent="🔗 Copiar link";},1800);};'
                   .   'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(u).then(done);}'
                   .   'else{var ta=document.createElement("textarea");ta.value=u;ta.style.position="fixed";ta.style.left="-9999px";document.body.appendChild(ta);ta.select();try{document.execCommand("copy");done();}catch(err){}document.body.removeChild(ta);}'
                   . '});'
                   . '</script>';
        }

        return $html . $bloco;
    }

    // ═══ CARDS DE MENSAGEM (copiar + compartilhar WhatsApp) ═══
    private static array $palavrasDeMensagem = [
        'mensagens','mensagem','frases','frase','simpatias','textos','legendas','status',
        'poesias','pensamentos','parabéns','votos','saudação','brinde','cumprimentos',
        'dedicatória','homenagem','whatsapp','copie e','copiar e','prontas'
    ];

    private static function transformarMensagensEmCards(string $html, array $meta): string
    {
        // Detecta se é post de mensagens pelo título + presença de listas
        $titulo = mb_strtolower((string)($meta['titulo'] ?? ''), 'UTF-8');
        $isPostDeMensagens = false;
        foreach (self::$palavrasDeMensagem as $kw) {
            if (mb_strpos($titulo, $kw) !== false) { $isPostDeMensagens = true; break; }
        }
        if (!$isPostDeMensagens) return $html;

        // Pra cada <ul>/<ol> "pura" (sem links, sem sublista, sem classe reservada),
        // transforma cada <li> em card de mensagem SE tiver 5+ itens que parecem mensagens.
        $convertidos = 0;
        $html = preg_replace_callback(
            '/<(ul|ol)([^>]*)>([\s\S]*?)<\/\1>/i',
            function($m) use (&$convertidos) {
                $attrs = $m[2];
                $inner = $m[3];

                // Pula classes reservadas do sistema (TL;DR, cluster, leia-tambem, etc)
                if (preg_match('/class=[\'"][^\'"]*(bloco-resumo|cluster-box|leia-tambem|msg-card|post-share|q-list|brf-list|news-list)/i', $attrs)) return $m[0];
                // Pula listas com links (são navegação)
                if (preg_match('/<a\s/i', $inner)) return $m[0];
                // Pula sublistas
                if (preg_match('/<(ul|ol)\s/i', $inner)) return $m[0];

                if (!preg_match_all('/<li[^>]*>([\s\S]*?)<\/li>/i', $inner, $liMatches)) return $m[0];

                // Critério: cada <li> precisa ser uma MENSAGEM — texto 40-500 chars, sem HTML estrutural
                $mensagens = [];
                foreach ($liMatches[1] as $liContent) {
                    $texto = self::limparTextoMensagem($liContent);
                    if (!self::pareceMensagem($texto)) { $mensagens = []; break; }
                    $mensagens[] = $texto;
                }
                // Só converte se tiver 5+ itens (posts reais de frases têm 20-100 itens).
                // Listas de 2-4 itens geralmente são tips/regras/passos, não mensagens.
                if (count($mensagens) < 5) return $m[0];

                $cards = '';
                foreach ($mensagens as $msg) {
                    $cards .= self::montarCardMensagem($msg);
                }
                $convertidos += count($mensagens);
                return $cards;
            },
            $html
        ) ?? $html;

        // Também trata <blockquote> standalone (Claude às vezes gera mensagens assim)
        $html = preg_replace_callback(
            '/<blockquote[^>]*>([\s\S]*?)<\/blockquote>/i',
            function($m) use (&$convertidos) {
                $texto = self::limparTextoMensagem($m[1]);
                if (!self::pareceMensagem($texto)) return $m[0];
                $convertidos++;
                return self::montarCardMensagem($texto);
            },
            $html
        ) ?? $html;

        // Se converteu ao menos 1 card, prepende CSS + JS (uma vez só)
        if ($convertidos > 0 && strpos($html, 'data-msg-runtime') === false) {
            $html = self::cssJsMensagens() . $html;
        }
        return $html;
    }

    private static function limparTextoMensagem(string $html): string
    {
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        // Remove aspas envolventes (tipográficas ou retas) pra card ficar limpo
        $t = preg_replace('/^[\x{201C}\x{201D}\x{00AB}\x{00BB}"\'\x{2018}\x{2019}]+/u', '', $t);
        $t = preg_replace('/[\x{201C}\x{201D}\x{00AB}\x{00BB}"\'\x{2018}\x{2019}]+$/u', '', $t);
        return trim($t);
    }

    private static function pareceMensagem(string $texto): bool
    {
        $len = mb_strlen($texto, 'UTF-8');
        if ($len < 40 || $len > 500) return false;
        // Deve terminar com pontuação ou emoji (aspas já foram removidas em limparTextoMensagem)
        if (!preg_match('/[.!?…🌹✨❤💐🎉🎊]$/u', $texto)) return false;
        return true;
    }

    private static function montarCardMensagem(string $texto): string
    {
        $textoEsc  = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
        $textoUrl  = rawurlencode($texto);
        return '<div class="msg-card">'
             . '<p class="msg-text">' . $textoEsc . '</p>'
             . '<div class="msg-actions">'
             .   '<button type="button" class="msg-btn msg-copy">📋 Copiar</button> '
             .   '<a class="msg-btn msg-wa" href="https://wa.me/?text=' . $textoUrl . '" rel="nofollow noopener" target="_blank">💬 WhatsApp</a>'
             . '</div>'
             . '</div>';
    }

    private static function cssJsMensagens(): string
    {
        return '<style data-msg-runtime="1">'
             . '.msg-card{background:#f8f9fa;border-left:4px solid #25d366;padding:14px 18px;margin:14px 0;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,.06)}'
             . '.msg-text{margin:0 0 10px;font-size:15px;line-height:1.55;color:#1f2937}'
             . '.msg-actions{display:flex;gap:8px;flex-wrap:wrap}'
             . '.msg-btn{display:inline-block;padding:7px 16px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:all .15s}'
             . '.msg-copy{background:#e5e7eb;color:#374151}'
             . '.msg-copy:hover{background:#d1d5db}'
             . '.msg-copy.copiado{background:#22c55e;color:#fff}'
             . '.msg-wa{background:#25d366;color:#fff}'
             . '.msg-wa:hover{background:#128c7e;color:#fff;text-decoration:none}'
             . '</style>'
             . '<script data-msg-runtime="1">'
             . 'document.addEventListener("click",function(e){'
             .   'if(!e.target.classList.contains("msg-copy"))return;'
             .   'e.preventDefault();'
             .   'var card=e.target.closest(".msg-card");if(!card)return;'
             .   'var t=card.querySelector(".msg-text");if(!t)return;'
             .   'var txt=t.textContent||t.innerText;'
             .   'var done=function(){e.target.textContent="✓ Copiado";e.target.classList.add("copiado");setTimeout(function(){e.target.textContent="📋 Copiar";e.target.classList.remove("copiado");},1800);};'
             .   'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(txt).then(done);}'
             .   'else{var ta=document.createElement("textarea");ta.value=txt;ta.style.position="fixed";ta.style.left="-9999px";document.body.appendChild(ta);ta.select();try{document.execCommand("copy");done();}catch(err){}document.body.removeChild(ta);}'
             . '});'
             . '</script>';
    }

    // ═══ CORRIGIR ALUCINAÇÕES TEMPORAIS ═══
    // O LLM às vezes escreve "24 de abril — mesma data de hoje" mesmo quando hoje é 22/04.
    // Estratégia: detectar padrões "[data] — mesma data de hoje" / "prazo encerra hoje" e comparar
    // a data citada com a data real. Se divergir, remove a cauda relativa ("— mesma data de hoje").
    private static array $mesesPt = [
        'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3, 'abril' => 4,
        'maio' => 5, 'junho' => 6, 'julho' => 7, 'agosto' => 8, 'setembro' => 9,
        'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
    ];

    private static function corrigirAlucinacoesTemporais(string $html): string
    {
        $hojeDia = (int)date('j');
        $hojeMes = (int)date('n');
        $mes = '(?:janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)';

        // Padrão A: "hoje, DD de MES" / "hoje (DD de MES)" — HOJE precede a data (ex: "<strong>hoje, 24 de abril</strong>")
        // Se DD de MES não é hoje, remove "hoje," e mantém só a data.
        $html = preg_replace_callback(
            '/\bhoje\s*,?\s*(?:\(\s*)?(\d{1,2})\s+de\s+(' . $mes . ')\b(\s*\))?/iu',
            function($m) use ($hojeDia, $hojeMes) {
                $dia = (int)$m[1];
                $mm = self::$mesesPt[mb_strtolower($m[2], 'UTF-8')] ?? 0;
                if ($dia === $hojeDia && $mm === $hojeMes) return $m[0]; // bate → mantém
                return $m[1] . ' de ' . $m[2]; // não bate → remove "hoje," e (parênteses)
            },
            $html
        ) ?? $html;

        // Padrão B: "DD de MES [sep] mesma data de hoje"
        $html = preg_replace_callback(
            '/(\b\d{1,2})\s+de\s+(' . $mes . ')\b((?:\s*<\/[a-z]+>)?)(\s*[—–\-,]\s*|\s+e\s+|\s+)mesma\s+data\s+de\s+hoje\b\.?/iu',
            function($m) use ($hojeDia, $hojeMes) {
                $dia = (int)$m[1];
                $mm = self::$mesesPt[mb_strtolower($m[2], 'UTF-8')] ?? 0;
                if ($dia === $hojeDia && $mm === $hojeMes) return $m[0];
                return $m[1] . ' de ' . $m[2] . $m[3];
            },
            $html
        ) ?? $html;

        // Padrão C: "DD de MES [sep] também (é) hoje"
        $html = preg_replace_callback(
            '/(\b\d{1,2})\s+de\s+(' . $mes . ')\b((?:\s*<\/[a-z]+>)?)(\s*[—–\-,]\s*|\s+e\s+|\s+)tamb[ée]m\s+(?:é\s+)?hoje\b\.?/iu',
            function($m) use ($hojeDia, $hojeMes) {
                $dia = (int)$m[1];
                $mm = self::$mesesPt[mb_strtolower($m[2], 'UTF-8')] ?? 0;
                if ($dia === $hojeDia && $mm === $hojeMes) return $m[0];
                return $m[1] . ' de ' . $m[2] . $m[3];
            },
            $html
        ) ?? $html;

        // Fallback global: se o HTML INTEIRO tem uma data DD de MES que não bate com hoje
        // E nenhuma data bate com hoje, então qualquer "hoje" em contexto arriscado é alucinação.
        // Troca por "em <data_global>".
        $dataGlobal = null;
        if (preg_match_all('/\b(\d{1,2})\s+de\s+(' . $mes . ')\b/iu', $html, $todasDatas, PREG_SET_ORDER)) {
            $algumaBate = false;
            $candidata = null;
            foreach ($todasDatas as $d) {
                $dia = (int)$d[1];
                $mm = self::$mesesPt[mb_strtolower($d[2], 'UTF-8')] ?? 0;
                if ($dia === $hojeDia && $mm === $hojeMes) { $algumaBate = true; break; }
                if ($candidata === null) $candidata = $d[1] . ' de ' . $d[2];
            }
            if (!$algumaBate && $candidata !== null) $dataGlobal = $candidata;
        }

        if ($dataGlobal !== null) {
            // "prazo de hoje" / "data de hoje" → já tem "de", usa a data direto (sem "em")
            $html = preg_replace_callback(
                '/\b(prazo|data|limite|vencimento)\s+de\s+hoje\b/iu',
                fn($mm) => $mm[1] . ' de ' . $dataGlobal,
                $html
            ) ?? $html;
            // Verbos arriscados: encerra/termina/vence/acaba/acontece hoje → "... em DD de MES"
            $html = preg_replace_callback(
                '/\b(prazo\s+(?:encerra|termina|acaba|vai\s+at[ée]|vence)|encerra|termina|acaba|acontece|inicia|come[çc]a|vence|ser[aá]\s+pago)\s+hoje\b/iu',
                fn($mm) => $mm[1] . ' em ' . $dataGlobal,
                $html
            ) ?? $html;
            // "também vence hoje" / "vence hoje" em H2/H3 → ", em DD de MES"
            $html = preg_replace_callback(
                '/\b(tamb[ée]m\s+(?:vence|encerra|termina|acaba|acontece|come[çc]a))\s+hoje\b/iu',
                fn($mm) => $mm[1] . ' em ' . $dataGlobal,
                $html
            ) ?? $html;
            // "é hoje" isolado em contexto de prazo/data → "é em DD de MES"
            $html = preg_replace_callback(
                '/\b(prazo|data|limite|último\s+dia|vencimento)\s+é\s+hoje\b/iu',
                fn($mm) => $mm[1] . ' é em ' . $dataGlobal,
                $html
            ) ?? $html;
        }

        // Padrão D: container textual (<p>, <li>, <h2>, <h3>) contém "<verbo> hoje"
        // + uma data DD de MES que NÃO bate com hoje → substitui "hoje" pela data.
        $html = preg_replace_callback(
            '/(<(?:p|li|h2|h3|h4|td|div|blockquote)[^>]*>)([\s\S]*?)(<\/(?:p|li|h2|h3|h4|td|div|blockquote)>)/i',
            function($m) use ($hojeDia, $hojeMes, $mes) {
                $abre = $m[1]; $inner = $m[2]; $fecha = $m[3];
                if (!preg_match_all('/\b(\d{1,2})\s+de\s+(' . $mes . ')\b/iu', $inner, $datas)) {
                    return $m[0];
                }
                // Se alguma data no container bate com hoje, não mexe
                foreach ($datas[0] as $k => $_) {
                    $dia = (int)$datas[1][$k];
                    $mm = self::$mesesPt[mb_strtolower($datas[2][$k], 'UTF-8')] ?? 0;
                    if ($dia === $hojeDia && $mm === $hojeMes) return $m[0];
                }
                $primeiraData = $datas[0][0];
                // "<verbo> hoje [sep] DD de MES" → "<verbo> em DD de MES" (evita duplicar data)
                $inner2 = preg_replace(
                    '/\b(prazo\s+(?:encerra|termina|acaba|vai\s+at[ée]|vence)|encerra|termina|acaba|acontece|inicia|come[çc]a|vence|ser[aá]\s+pago)\s+hoje\s*[—–\-,]\s*\d{1,2}\s+de\s+' . $mes . '\b/iu',
                    '$1 em ' . $primeiraData,
                    $inner
                ) ?? $inner;
                // "<verbo> hoje" isolado → "<verbo> em DD de MES"
                $alterado = preg_replace_callback(
                    '/\b(prazo\s+(?:encerra|termina|acaba|vai\s+at[ée]|vence))\s+hoje\b/iu',
                    fn($mm) => $mm[1] . ' em ' . $primeiraData,
                    $inner2
                );
                $alterado = preg_replace_callback(
                    '/\b(encerra|termina|acaba|acontece|inicia|come[çc]a|vence|ser[aá]\s+pago)\s+hoje\b/iu',
                    fn($mm) => $mm[1] . ' em ' . $primeiraData,
                    $alterado
                );
                // "é hoje" em contexto de data → "é em DD de MES"
                $alterado = preg_replace_callback(
                    '/\bé\s+hoje\b/iu',
                    fn($mm) => 'é em ' . $primeiraData,
                    $alterado
                );
                // "prazo de hoje" → "prazo de DD de MES"
                $alterado = preg_replace_callback(
                    '/\bprazo\s+de\s+hoje\b/iu',
                    fn($mm) => 'prazo de ' . $primeiraData,
                    $alterado
                );
                if ($alterado === $inner) return $m[0];
                return $abre . $alterado . $fecha;
            },
            $html
        ) ?? $html;

        return $html;
    }

    // ═══ DEDUPE DE SCHEMA JSON-LD FAQPage ═══
    /**
     * Se há múltiplos <script type="application/ld+json"> com @type=FAQPage (ou NewsArticle/Article),
     * mantém só o ÚLTIMO. Evita duplicação que causa warning no Rich Results do Google.
     */
    private static function dedupeSchemaFaq(string $html): string
    {
        $pattern = '/<script[^>]*type=[\'"]application\/ld\+json[\'"][^>]*>[\s\S]*?<\/script>/i';
        if (!preg_match_all($pattern, $html, $mm, PREG_OFFSET_CAPTURE)) return $html;

        // Agrupa scripts por @type detectado no conteúdo
        $porTipo = [];
        foreach ($mm[0] as $idx => $m) {
            $script = $m[0];
            $pos    = $m[1];
            // Extrai @type do conteúdo
            if (preg_match('/"@type"\s*:\s*"([^"]+)"/i', $script, $tm)) {
                $tipo = $tm[1];
                $porTipo[$tipo][] = ['script' => $script, 'pos' => $pos];
            }
        }

        // Para cada tipo com 2+ ocorrências, marca os não-últimos pra remover
        $removerPos = [];
        foreach ($porTipo as $tipo => $items) {
            if (count($items) < 2) continue;
            // Mantém o ÚLTIMO; remove os anteriores
            $paraRemover = array_slice($items, 0, -1);
            foreach ($paraRemover as $r) {
                $removerPos[] = ['pos' => $r['pos'], 'len' => strlen($r['script'])];
            }
        }

        if (empty($removerPos)) return $html;

        // Remove de trás pra frente pra preservar offsets
        usort($removerPos, fn($a, $b) => $b['pos'] <=> $a['pos']);
        foreach ($removerPos as $r) {
            $html = substr($html, 0, $r['pos']) . substr($html, $r['pos'] + $r['len']);
        }
        return $html;
    }

    // ═══ RESET TOTAL DE INTERLINKS INTERNOS (usado em reprocessamento) ═══
    /**
     * Remove TODOS os <a data-internal-link>, preservando texto interno.
     * Usado por script de reprocessamento pra permitir redistribuição dos links
     * segundo a regra mais recente (primeira ocorrência natural).
     */
    public static function resetInterlinksInternos(string $html): string
    {
        if (strpos($html, 'data-internal-link') === false) return $html;
        // Preserva conteúdo interno, remove só a tag <a>
        return preg_replace(
            '/<a\b[^>]*data-internal-link[^>]*>([\s\S]*?)<\/a>/i',
            '$1',
            $html
        ) ?? $html;
    }

    // ═══ LIMPAR INTERLINKS INTERNOS INADEQUADOS ═══
    /**
     * Remove <a data-internal-link> de passagens anteriores que:
     *   - Caíram dentro de <details> / <summary> (FAQ — proibido)
     *   - Caíram em blocos reservados (cluster-box, leia-tambem, msg-card, bloco-resumo, post-share)
     *   - São duplicados (mesmo href aparece 2+ vezes)
     * Preserva o texto interno (remove só a tag <a>).
     */
    private static function limparInterlinksInadequados(string $html): string
    {
        if (strpos($html, 'data-internal-link') === false) return $html;

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xp = new DOMXPath($dom);
        $hrefsVistos = [];
        $aRemover = [];

        // 1. Identifica todos <a data-internal-link>
        foreach (iterator_to_array($xp->query('//a[@data-internal-link]')) as $a) {
            $href = (string)$a->getAttribute('href');
            $inadequado = false;

            // Zona proibida (FAQ ou blocos reservados)
            $ancestor = $a->parentNode;
            while ($ancestor && $ancestor->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($ancestor->nodeName);
                if (in_array($tag, ['details', 'summary'], true)) { $inadequado = true; break; }
                $cls = $ancestor->getAttribute('class');
                // bloco-resumo (TL;DR) agora é PERMITIDO — user quer backlinks no início do artigo
                if ($cls && preg_match('/\b(cluster-box|leia-tambem|msg-card|post-share)\b/i', $cls)) {
                    $inadequado = true; break;
                }
                $ancestor = $ancestor->parentNode;
            }

            // Duplicado? Marca pra remover (mantém só o PRIMEIRO por URL)
            if (!$inadequado && isset($hrefsVistos[$href])) {
                $inadequado = true;
            }
            if (!$inadequado) {
                $hrefsVistos[$href] = true;
            } else {
                $aRemover[] = $a;
            }
        }

        // 2. Remove as tags <a> inadequadas, preservando o conteúdo interno
        foreach ($aRemover as $a) {
            $parent = $a->parentNode;
            if (!$parent) continue;
            while ($a->firstChild) {
                $parent->insertBefore($a->firstChild, $a);
            }
            $parent->removeChild($a);
        }

        if (empty($aRemover)) return $html;

        $out = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out !== '' ? $out : $html;
    }

    // ═══ SUBSTITUIÇÃO CONTEXTUAL DE TRAVESSÃO (PT-BR) ═══
    /**
     * Substitui travessão (—) e en-dash (–) no CORPO do artigo por pontuação
     * adequada segundo regras de português culto. Não toca em <pre>, <code>, <script>.
     *
     * Regras aplicadas:
     *  1. PAR de travessões (aposto isolado): "X — Y — Z" → "X, Y, Z"
     *  2. Travessão antes de explicação direta (substantivo/cláusula curta): ":"
     *  3. Travessão antes de consequência/contraste (mas/e/porém/pois): ","
     *  4. Travessão no início de item de lista (diálogo/enumeração): mantém
     *  5. Em h2/h3/h4: NÃO mexe (títulos já passam por normalizarTitulo separadamente)
     */
    /**
     * Substitui travessão (— em-dash, – en-dash) por pontuação contextual PT-BR.
     * Público pra permitir uso como guard final em geradores (caso travessão seja
     * reintroduzido por algum stage do pipeline após o processar() principal).
     */
    public static function substituirTravessaoContextual(string $html): string
    {
        // Isola blocos que NÃO podem ser mexidos (pre, code, script, style, h2-h6, blockquote emoji-alert)
        $protegidos = [];
        $idx = 0;
        $padroesProtecao = [
            '/<pre\b[\s\S]*?<\/pre>/i',
            '/<code\b[\s\S]*?<\/code>/i',
            '/<script\b[\s\S]*?<\/script>/i',
            '/<style\b[\s\S]*?<\/style>/i',
            '/<h[2-6]\b[^>]*>[\s\S]*?<\/h[2-6]>/i',
            '/<a\b[^>]*>[\s\S]*?<\/a>/i', // não mexe em texto dentro de link
        ];
        foreach ($padroesProtecao as $p) {
            $html = preg_replace_callback($p, function($m) use (&$protegidos, &$idx) {
                $token = "__TRAV_PROT_{$idx}__";
                $protegidos[$token] = $m[0];
                $idx++;
                return $token;
            }, $html) ?? $html;
        }

        // REGRA 1: PAR de travessões (aposto) — "X — Y — Z" → "X, Y, Z"
        //    Detecta 2 travessões separados por texto curto-médio (≤80 chars) sem pontuação forte entre eles.
        $html = preg_replace_callback(
            '/([^—–\n])\s*[—–]\s*([^—–\n]{3,80}?)\s*[—–]\s*([^\n])/u',
            function($m) {
                // Se o conteúdo entre travessões parece explicação com dois-pontos ou ponto, não é par
                if (strpos($m[2], '.') !== false || strpos($m[2], ':') !== false) {
                    return $m[0];
                }
                return rtrim($m[1]) . ', ' . trim($m[2]) . ', ' . ltrim($m[3]);
            },
            $html
        ) ?? $html;

        // REGRA 2a: Travessão antes de VALOR/DATA → dois pontos (explicação factual)
        //    Precisa rodar ANTES da regra geral porque a geral pega qualquer letra.
        $html = preg_replace_callback(
            '/([^—–\n:])\s*[—–]\s*(R\$\s*\d|at[ée]\s+R\$|\d{1,2}\s+de\s+(?:janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro))/iu',
            function($m) {
                return rtrim($m[1]) . ': ' . $m[2];
            },
            $html
        ) ?? $html;

        // REGRA 2b: Travessão isolado geral — escolhe pontuação conforme contexto
        // Inclui dígitos no charset depois do travessão pra cobrir casos tipo
        // "112 pontos no total — 15 a mais que..." que escapavam antes.
        $html = preg_replace_callback(
            '/([^—–\n])\s*[—–]\s*([a-záéíóúâêôãõçA-ZÁÉÍÓÚÂÊÔÃÕÇ0-9])/u',
            function($m) {
                // Travessão em notícia PT-BR quase sempre pode virar vírgula sem perda de sentido.
                return rtrim($m[1]) . ', ' . $m[2];
            },
            $html
        ) ?? $html;

        // Limpa vírgulas duplas que possam ter surgido
        $html = preg_replace('/,\s*,\s*/u', ', ', $html) ?? $html;
        // Limpa espaços duplos
        $html = preg_replace('/ {2,}/u', ' ', $html) ?? $html;

        // Reinjeta blocos protegidos
        if (!empty($protegidos)) {
            $html = strtr($html, $protegidos);
        }

        return $html;
    }

    // ═══ SANITIZA ANCHORS YMYL BORDERLINE ═══
    /**
     * Em blocos de interlink (leia-tambem, cluster-interlink), títulos com promessa agressiva
     * ("Saque de até R$ 7.200", "Pagamentos de até R$ 500") são amenizados no TEXTO VISÍVEL
     * do link, preservando o href. Protege YMYL + trust.
     */
    private static function sanitizarAnchorsYmyl(string $html): string
    {
        // Processa apenas dentro de blocos conhecidos de interlink
        $blocos = ['cluster-interlink', 'leia-tambem'];
        foreach ($blocos as $marker) {
            $pattern = '/(<!-- ' . $marker . ' -->[\s\S]*?<!-- \/' . $marker . ' -->)/i';
            $html = preg_replace_callback($pattern, function($mOuter) {
                $bloco = $mOuter[1];
                // Reescreve o texto dentro de cada <a>...</a>
                $bloco = preg_replace_callback(
                    '/(<a\s[^>]*>)([^<]+)(<\/a>)/i',
                    function($mA) {
                        $abreTag = $mA[1];
                        $texto   = $mA[2];
                        $fecha   = $mA[3];
                        $novo    = self::amenizarPromessaTexto($texto);
                        return $abreTag . $novo . $fecha;
                    },
                    $bloco
                );
                return $bloco;
            }, $html) ?? $html;
        }
        return $html;
    }

    /**
     * Remove promessas agressivas do texto: "de até R$ X", "em até N%", "até R$ X" no fim.
     * Se o que sobra ficar < 3 palavras, retorna original (link é mais importante que sanitização).
     */
    private static function amenizarPromessaTexto(string $texto): string
    {
        $original = $texto;

        // Padrões: "de até R$ X", "até R$ X", "em até N%", "em até N horas/dias"
        $padroes = [
            '/\s*(?:de\s+)?at[ée]\s+R\$\s*[\d\.,]+(?:\s*(?:mil|milh[õo][eé]?s?|bi))?\b/iu',
            '/\s*(?:em\s+)?at[ée]\s+\d+\s*%/u',
            '/\s*(?:em\s+)?at[ée]\s+\d+\s+(?:horas?|dias?|minutos?|anos?|meses)\b/iu',
            '/\s*(?:paga|recebe)\s+at[ée]\s+R\$\s*[\d\.,]+/iu',
        ];
        $limpo = $texto;
        foreach ($padroes as $p) {
            $limpo = preg_replace($p, '', $limpo) ?? $limpo;
        }
        $limpo = trim(preg_replace('/\s+/u', ' ', $limpo));
        // Remove pontuação pendurada no fim ("Saque de até R$ X," → "Saque" com vírgula solta)
        $limpo = rtrim($limpo, " ,;:-—");

        // Se o sanitizado ficar muito curto ou vazio, mantém o original
        $palavras = preg_split('/\s+/', $limpo);
        if (count($palavras) < 3 || mb_strlen($limpo, 'UTF-8') < 12) return $original;
        return $limpo;
    }

    // ═══ DEDUPE DE "LEIA TAMBÉM" (mantém só 1 — prioridade: cluster-interlink > leia-tambem > sem-marker) ═══
    /**
     * Remove blocos duplicados de "Leia também" / "Veja também". Política:
     *  - Se há <!-- cluster-interlink --> → mantém esse, remove todos os demais (leia-tambem, sem marker)
     *  - Senão, se há <!-- leia-tambem --> → mantém o ÚLTIMO, remove os demais
     *  - Blocos sem marker (legado cc-card) também são removidos quando há marker presente
     */
    private static function dedupeLeiaTambem(string $html): string
    {
        $temCluster = preg_match('/<!-- cluster-interlink -->/', $html);
        $temLeia    = preg_match('/<!-- leia-tambem -->/', $html);

        // Caso 1: tem cluster-interlink → ele é o canônico, remove todos os leia-tambem e sem-marker
        if ($temCluster) {
            // Remove TODOS os leia-tambem com marker
            $html = preg_replace('/\s*<!-- leia-tambem -->[\s\S]*?<!-- \/leia-tambem -->\s*/', "\n", $html) ?? $html;
            // Remove blocos órfãos <div class="leia-tambem">...</div> (sem marker)
            $html = preg_replace('/<div class=[\'"]leia-tambem[\'"][^>]*>[\s\S]*?<\/div>\s*<\/div>?/', '', $html) ?? $html;
            // Se há múltiplos cluster-interlink (2+), mantém só o último
            $count = preg_match_all('/<!-- cluster-interlink -->/', $html);
            if ($count > 1) {
                // Remove todos menos o último
                $positions = [];
                if (preg_match_all('/<!-- cluster-interlink -->[\s\S]*?<!-- \/cluster-interlink -->/', $html, $mm, PREG_OFFSET_CAPTURE)) {
                    // Remove de trás pra frente, pulando o último
                    $aRemover = array_slice($mm[0], 0, -1);
                    $aRemover = array_reverse($aRemover);
                    foreach ($aRemover as $r) {
                        $html = substr($html, 0, $r[1]) . substr($html, $r[1] + strlen($r[0]));
                    }
                }
            }
            return $html;
        }

        // Caso 2: só tem leia-tambem → mantém o último
        if ($temLeia) {
            $count = preg_match_all('/<!-- leia-tambem -->/', $html);
            if ($count > 1) {
                if (preg_match_all('/<!-- leia-tambem -->[\s\S]*?<!-- \/leia-tambem -->/', $html, $mm, PREG_OFFSET_CAPTURE)) {
                    $aRemover = array_slice($mm[0], 0, -1);
                    $aRemover = array_reverse($aRemover);
                    foreach ($aRemover as $r) {
                        $html = substr($html, 0, $r[1]) . substr($html, $r[1] + strlen($r[0]));
                    }
                }
            }
            return $html;
        }

        return $html;
    }

    // ═══ DEDUPE DE FAQ (mantém só a última) ═══
    /**
     * Detecta múltiplas seções de FAQ no artigo e mantém só a ÚLTIMA.
     * Motivo: FAQ duplicado dilui atenção + parece redundante. Schema JSON-LD roda só uma vez no final.
     *
     * Heurística: procura <h2> cujo texto contém "Pergunta" / "FAQ" / "Dúvida". Se houver 2+,
     * remove todos exceto o último (com seu conteúdo até o próximo <h2>).
     */
    public static function dedupeFaq(string $html): string
    {
        if (!preg_match_all('/<h2[^>]*>([\s\S]*?)<\/h2>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $palavrasFaq = [
            'perguntas frequentes', 'perguntas e respostas', 'pergunta e resposta',
            'faq', 'p&r', 'q&a',
            'dúvidas frequentes', 'dúvidas comuns', 'dúvidas mais comuns',
            'duvidas frequentes', 'duvidas comuns',
            'tire suas dúvidas', 'tire suas duvidas', 'tire sua dúvida',
            'tira-dúvidas', 'tira duvidas', 'tiradúvidas',
            'principais dúvidas', 'principais duvidas',
            'o que mais perguntam',
        ];

        // 1. Identifica cada seção (delimita do H2 até o próximo H2)
        //    Classifica como FAQ se:
        //    a) H2 tem palavra-chave ("perguntas frequentes", "FAQ", "dúvidas", etc), OU
        //    b) Seção tem 3+ <details><summary> consecutivos (FAQ implícita)
        $secoes = [];
        foreach ($m[1] as $idx => $matchInner) {
            $innerHtml = $matchInner[0];
            $plainLower = mb_strtolower(
                trim(html_entity_decode(strip_tags($innerHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'UTF-8'
            );
            $plainLower = preg_replace('/^[^\w]+|[^\w]+$/u', '', $plainLower);

            $inicio = $m[0][$idx][1];
            if (preg_match('/<h2\b/i', $html, $mm2, PREG_OFFSET_CAPTURE, $inicio + 1)) {
                $fim = $mm2[0][1];
            } else {
                $fim = strlen($html);
            }
            $secaoHtml = substr($html, $inicio, $fim - $inicio);

            // (a) H2 com palavra-chave FAQ?
            $isFaqPorTitulo = false;
            foreach ($palavrasFaq as $kw) {
                if (mb_strpos($plainLower, $kw) !== false) { $isFaqPorTitulo = true; break; }
            }

            // (b) Contagem de <details><summary> na seção
            $qtdDetails = preg_match_all('/<details\b[^>]*>\s*<summary\b/i', $secaoHtml);
            $isFaqPorEstrutura = $qtdDetails >= 3;

            if (!$isFaqPorTitulo && !$isFaqPorEstrutura) continue;

            $secoes[] = [
                'inicio'     => $inicio,
                'fim'        => $fim,
                'tem_details'=> (bool)$qtdDetails,
                'qtd_details'=> (int)$qtdDetails,
                'por_titulo' => $isFaqPorTitulo,
            ];
        }

        if (count($secoes) < 2) return $html;

        // 2. Decide qual MANTER — prioridade:
        //    a) Seção que tem <details> (formato interativo, Google ama)
        //    b) Última seção encontrada
        $manterIdx = -1;
        for ($i = count($secoes) - 1; $i >= 0; $i--) {
            if ($secoes[$i]['tem_details']) { $manterIdx = $i; break; }
        }
        if ($manterIdx === -1) $manterIdx = count($secoes) - 1; // fallback: a última

        // 3. Remove todas as outras (de trás pra frente pra preservar offsets)
        $remover = [];
        foreach ($secoes as $i => $s) {
            if ($i !== $manterIdx) $remover[] = $s;
        }
        usort($remover, fn($a, $b) => $b['inicio'] <=> $a['inicio']);

        foreach ($remover as $s) {
            $html = substr($html, 0, $s['inicio']) . substr($html, $s['fim']);
        }
        return $html;
    }

    // ═══ DIAGNÓSTICO: ALERTA FORTE PRESENTE ═══
    /**
     * Verifica se o artigo tem 1 bloco de ALERTA destacado (fundo vermelho/âmbar)
     * com o erro crítico. Ausência é sinalizada — não é bloqueante, mas reduz tempo
     * na página. Reviewer/Gerador podem usar pra pedir inserção.
     */
    public static function diagnosticarAlertaForte(string $html): array
    {
        // Match: div com background vermelho/âmbar + border-left vermelho/âmbar
        $temVermelho = preg_match('/<div[^>]*style=[\'"][^\'"]*(?:background[^;]*(?:#fef2f2|#fee2e2|#fca5a5|#dc2626)[^\'"]*border-left[^;]*(?:#dc2626|#991b1b|#b91c1c)|border-left[^;]*(?:#dc2626|#991b1b|#b91c1c))/iu', $html);
        // Ou <strong> com emoji de atenção + tom de erro
        $temAtencao = preg_match('/<strong[^>]*>\s*(?:⚠️|🚨)\s*ATEN[ÇC][AÃ]O/iu', $html);

        return [
            'presente' => (bool)($temVermelho || $temAtencao),
            'estilo'   => $temVermelho ? 'box_vermelho' : ($temAtencao ? 'emoji_atencao' : 'ausente'),
            'alerta_recomendado' => !($temVermelho || $temAtencao), // se falta, recomendar
        ];
    }

    // ═══ DIAGNÓSTICO: PROMESSA NÃO-CALIBRADA (Discover corta alcance) ═══
    /**
     * Detecta frases que combinam VALOR + PÚBLICO DE ESCALA sem qualificador condicional.
     * Ex: "R$ 1 mil para 4 milhões de brasileiros" sem "se cumprirem X".
     * Isso é percebido pelo Discover como "benefício exagerado" → reduz distribuição.
     */
    public static function diagnosticarPromessaNaoCalibrada(string $html): array
    {
        $issues = [];
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace('/\s+/u', ' ', $texto);

        // Padrão: VALOR + "para/pra" + QUANTIDADE DE PESSOAS (milhões, mil, milhares)
        $valor  = '(?:R\$\s*[\d\.,]+(?:\s*(?:mil|milh[õo][eé]?s?|bi(?:lh[õo][eé]?s?)?))?|at[ée]\s+R\$\s*[\d\.,]+(?:\s*(?:mil|milh[õo][eé]?s?))?)';
        $escala = '(?:\d+(?:[\.,]\d+)?\s*(?:milh[õo][eé]?s?|mil|milhares)|milh[õo][eé]?s?\s+de|milhares\s+de)';
        // Qualificadores válidos que, se aparecerem na MESMA ou PRÓXIMA frase, liberam
        $qualificadores = '(?:pra\s+quem|para\s+quem|apenas\s+quem|s[oó]\s+(?:pra|para)\s+quem|se\s+cumpr[ie]|se\s+enquadra|conforme\s+(?:o\s+)?crit[ée]rio|estimado\s+em|grupo\s+estimado|entre\s+os\s+que|desde\s+que)';

        $pattern = '/(' . $valor . ')(?:\s+\w+){0,8}?\s+(?:para|pra)\s+(' . $escala . '(?:\s+\w+){0,5})/iu';
        if (preg_match_all($pattern, $texto, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as $idx => $m) {
                $trecho = $m[0];
                $inicio = $m[1];
                // Verifica janela de 200 chars à frente do trecho pra ver se tem qualificador
                $janela = substr($texto, $inicio, min(300, strlen($texto) - $inicio));
                if (!preg_match('/' . $qualificadores . '/iu', $janela)) {
                    $issues[] = [
                        'tipo'    => 'promessa_nao_calibrada',
                        'trecho'  => trim($trecho),
                        'motivo'  => 'valor + público de escala sem qualificador condicional (Discover pode cortar alcance)',
                    ];
                }
            }
        }
        return $issues;
    }

    // ═══ DIAGNÓSTICO: EXPOSIÇÃO NEUTRA (H2 seguido de definição, não ação) ═══
    /**
     * Detecta seções onde o 1º <p> após um H2 é puramente expositivo/definitório —
     * padrões "O X é Y gerido por Z", "A X é um Y que Z". Isso mata retenção no Discover.
     * Retorna lista de H2s flaggeados.
     */
    public static function diagnosticarExposicaoApoH2(string $html): array
    {
        $issues = [];
        // Captura <h2>...</h2> seguido do primeiro <p>...</p>
        if (!preg_match_all('/<h2[^>]*>([\s\S]*?)<\/h2>\s*(?:<[^p][^>]*>[\s\S]*?<\/[^>]+>\s*)*<p[^>]*>([\s\S]*?)<\/p>/i', $html, $mm, PREG_SET_ORDER)) {
            return $issues;
        }

        $padroesExpositivos = [
            // "O X é um Y gerido por Z" / "A X é um programa..."
            '/^\s*(?:O|A|Os|As)\s+\w[\w\s]{0,30}\s+(?:é|são)\s+(?:um|uma|uns|umas|o|a|os|as)\s+\w+/iu',
            // "Trata-se de..."
            '/^\s*Trata-se\s+de\b/iu',
            // "X consiste em..." / "X compreende..."
            '/^\s*\w+\s+(?:consiste\s+em|compreende|engloba|abrange)\b/iu',
            // "De acordo com a lei..." / "Segundo o órgão..." seguido de definição
            '/^\s*(?:De\s+acordo\s+com|Segundo\s+o?)\s+\w+[,\s]+o\s+(?:programa|benefício|projeto|cadastro)\b/iu',
        ];

        foreach ($mm as $match) {
            $h2 = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $p  = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($p === '' || $h2 === '') continue;

            foreach ($padroesExpositivos as $padrao) {
                if (preg_match($padrao, $p)) {
                    $issues[] = [
                        'h2'     => $h2,
                        'p_amostra' => mb_substr($p, 0, 120, 'UTF-8') . (mb_strlen($p, 'UTF-8') > 120 ? '…' : ''),
                        'motivo' => 'primeiro <p> após H2 é expositivo puro (sem ação/alerta/decisão)',
                    ];
                    break;
                }
            }
        }
        return $issues;
    }

    // ═══ DIAGNÓSTICO: FLUIDEZ (frases truncadas, vírgulas órfãs, preposição no fim) ═══
    /**
     * Detecta problemas editoriais leves que derrubam E-E-A-T:
     *  - Frases que terminam com preposição ("...abrirem, de perfil.")
     *  - Vírgulas duplas ",,"
     *  - Parágrafos terminando em conector solto (, e. / , de. / , com.)
     *  - Espaços duplos dentro de texto
     *  - Pontuação sobreposta (?.,  !.)
     * Retorna lista de issues com amostra — NÃO modifica HTML.
     */
    public static function diagnosticarFluidez(string $html): array
    {
        $issues = [];

        // Processa <p>, <li>, <h2>, <h3> — containers textuais editoriais
        if (preg_match_all('/<(?:p|li|h2|h3|h4)[^>]*>([\s\S]*?)<\/(?:p|li|h2|h3|h4)>/i', $html, $mm)) {
            foreach ($mm[1] as $inner) {
                $texto = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($texto === '') continue;

                // 1. Frase terminando em cauda preposicional solta (ex: "…abrirem, de perfil.")
                //    Exclui expressões idiomáticas válidas ("sem problema", "com certeza", etc)
                $excecoes = '/(?:sem\s+(?:problema|dúvida|exce[çc][ãa]o|aviso|custo|demora|motivo)|com\s+(?:certeza|pressa|cuidado|urg[eê]ncia|seguran[çc]a)|de\s+(?:novo|fato|imediato|repente|verdade|graça|vez))\s*\.\s*$/iu';
                if (preg_match('/,\s+(de|da|do|dos|das|em|no|na|nos|nas|para|pra|por|pelo|pela|com|sem|sobre|sob)\s+\w+\s*\.\s*$/iu', $texto)
                    && !preg_match($excecoes, $texto)) {
                    $issues[] = [
                        'tipo'    => 'frase_truncada',
                        'trecho'  => '…' . mb_substr($texto, max(0, mb_strlen($texto,'UTF-8') - 60), null, 'UTF-8'),
                        'motivo'  => 'frase termina com cauda preposicional solta (possível truncagem editorial)',
                    ];
                }

                // 2. Parágrafo terminando em preposição sem objeto ("…leva a.", "…com.", "…de.")
                if (preg_match('/\b(de|em|com|para|pra|por|sobre)\s*\.\s*$/iu', $texto)) {
                    $issues[] = [
                        'tipo'    => 'preposicao_final',
                        'trecho'  => '…' . mb_substr($texto, max(0, mb_strlen($texto,'UTF-8') - 40), null, 'UTF-8'),
                        'motivo'  => 'parágrafo termina em preposição sem objeto',
                    ];
                }

                // 2b. Parágrafo SEM pontuação final (sinal forte de truncagem do LLM)
                //     Ex: "...podem ter o pagamento bloqueado automaticamente, mesmo"
                $ultimoChar = mb_substr($texto, -1, 1, 'UTF-8');
                if (!in_array($ultimoChar, ['.', '!', '?', ':', '…', ')', ']', '"', "'", '”', '’', '»'], true)
                    && mb_strlen($texto, 'UTF-8') > 30) {
                    $issues[] = [
                        'tipo'    => 'frase_sem_pontuacao',
                        'trecho'  => '…' . mb_substr($texto, max(0, mb_strlen($texto,'UTF-8') - 50), null, 'UTF-8'),
                        'motivo'  => 'parágrafo não tem pontuação final (possível corte/truncagem)',
                    ];
                }

                // 3. Vírgulas duplas
                if (preg_match('/,{2,}|,\s+,/', $texto)) {
                    $issues[] = [
                        'tipo'    => 'virgulas_duplas',
                        'trecho'  => mb_substr($texto, 0, 80, 'UTF-8') . '…',
                        'motivo'  => 'vírgulas duplicadas',
                    ];
                }

                // 4. Pontuação sobreposta (?., !., ?!.)
                if (preg_match('/[?!]\.|\.[?!]/', $texto)) {
                    $issues[] = [
                        'tipo'    => 'pontuacao_sobreposta',
                        'trecho'  => mb_substr($texto, 0, 80, 'UTF-8') . '…',
                        'motivo'  => 'pontuação sobreposta (ex: "?.", "!.")',
                    ];
                }

                // 5. Frase inicia com minúscula após ponto (deve ser maiúscula)
                if (preg_match('/\.\s+[a-záéíóúâêôãõç]/u', $texto)) {
                    $issues[] = [
                        'tipo'    => 'minuscula_apos_ponto',
                        'trecho'  => mb_substr($texto, 0, 80, 'UTF-8') . '…',
                        'motivo'  => 'frase começa com minúscula depois de ponto',
                    ];
                }
            }
        }

        return $issues;
    }

    // ═══ DIAGNÓSTICO: REPETIÇÃO SEMÂNTICA ═══
    /**
     * Detecta frases-chave repetidas 3+ vezes (over-optimization).
     * Ex: "24 de abril" aparecendo 5x no artigo.
     * Retorna lista ['termo' => 'x', 'ocorrencias' => N].
     */
    public static function diagnosticarRepeticoes(string $html): array
    {
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace('/\s+/u', ' ', $texto);

        $issues = [];
        // Padrões factuais repetíveis: datas DD de MES, valores R$ X, % Y
        $regexs = [
            'data'  => '/\b\d{1,2}\s+de\s+(?:janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\b/iu',
            'valor' => '/R\$\s*[\d\.,]+/u',
            'porc'  => '/\b\d+\s*%/',
        ];
        foreach ($regexs as $cat => $re) {
            if (preg_match_all($re, $texto, $mm)) {
                $contagem = [];
                foreach ($mm[0] as $s) {
                    $k = mb_strtolower(trim($s), 'UTF-8');
                    $contagem[$k] = ($contagem[$k] ?? 0) + 1;
                }
                foreach ($contagem as $s => $n) {
                    if ($n >= 4) {
                        $issues[] = [
                            'tipo'        => 'repeticao_' . $cat,
                            'termo'       => $s,
                            'ocorrencias' => $n,
                            'motivo'      => "'{$s}' aparece {$n}x — acima de 3 é over-optimization, use variação semântica",
                        ];
                    }
                }
            }
        }
        return $issues;
    }

    // ═══ DIAGNÓSTICO: DETECTA ABERTURA MANUAL-STYLE ═══
    /**
     * Varre o 1º <p> do conteúdo e detecta se é abertura tutorial/manual.
     * Retorna array ['manual' => bool, 'motivo' => string] — NÃO modifica HTML (decisão de UI/Reviewer).
     */
    public static function diagnosticarAbertura(string $html): array
    {
        if (!preg_match('/<p[^>]*>([\s\S]*?)<\/p>/i', $html, $m)) {
            return ['manual' => false, 'motivo' => 'sem_paragrafo'];
        }
        $primeiroP = trim(strip_tags(html_entity_decode($m[1])));
        if ($primeiroP === '') return ['manual' => false, 'motivo' => 'vazio'];

        // Números por extenso comuns
        $numExt = 'dois|duas|tr[eê]s|quatro|cinco|seis|sete|oito|nove|dez|onze|doze';
        $padroes = [
            'enumeracao_fria'   => '/^(?:Os|As)\s+(?:\d+|' . $numExt . ')\s+(?:grupos|regras|perfis|casos|situa[çc][ãa]o(?:es)?|categorias?|passos|requisitos|tipos|formas|maneiras)\b/iu',
            'tutorial_abertura' => '/^(?:Conhe[çc]a|Saiba|Veja|Confira|Entenda|Descubra)\s+(?:os|as|quem|como|quando|quais)\b/iu',
            'lista_abertura'    => '/^Existem\s+(?:\d+|' . $numExt . ')\s+(?:grupos|perfis|formas|maneiras|tipos|categorias)\b/iu',
            'informativo_puro'  => '/^(?:O|A|Os|As)\s+\w+\s+\w+\s+(?:é|são|inclui|abrange|compreende)/iu',
            // NEUTRAS (condicional soft, sem risco): "Se você se encaixa", "Caso você tenha", "Para quem", "Fique atento"
            'abertura_neutra'   => '/^(?:Se\s+você\s+(?:se\s+encaixa|tem|está|faz\s+parte|pretende)|Caso\s+você|Aqueles\s+que|Para\s+quem|Fique\s+atento|Você\s+que\s+(?:é|tem))\b/iu',
        ];
        foreach ($padroes as $motivo => $padrao) {
            if (preg_match($padrao, $primeiroP)) {
                return ['manual' => true, 'motivo' => $motivo, 'amostra' => mb_substr($primeiroP, 0, 80, 'UTF-8')];
            }
        }
        return ['manual' => false, 'motivo' => 'ok'];
    }

    // ═══ NORMALIZAR TÍTULO (sem travessão, sem en-dash) ═══
    /**
     * Aplica pontuação editorial no título:
     *  - Remove travessão (—) e en-dash (–) → substitui pelo separador natural (dois pontos na 1ª ocorrência, depois vírgula)
     *  - Remove espaços redundantes
     *
     * @param string $titulo
     * @return string
     */
    public static function normalizarTitulo(string $titulo): string
    {
        $t = trim($titulo);
        if ($t === '') return $t;

        // ─── REMOVE SUFIXO " - VeiculoName" típico de Google News ───
        // Padrões: "Title - UOL", "Title - Folha de S.Paulo", "Title - LANCE!"
        // Riscos: pode bater em título legítimo com hífen no final ("Curso X - 2026")
        // Mitigação: só remove se sufixo for ≤30 chars E não começar com número.
        // Lista de veículos comuns evita falsos positivos em títulos legítimos.
        $veiculosComuns = '(?:UOL|LANCE!?|Globo|G1|Terra|Folha|Estadão|Estadao|O\s+Dia|R7|CNN(?:\s+Brasil)?|ESPN(?:\s+Brasil)?|Trivela|Veja|IstoÉ|Isto[Éé]|GE|Goal|Metrópoles|Metropoles|TecMundo|Tecnoblog|Olhar\s+Digital|Canaltech|InfoMoney|Exame|Forbes|Reuters|AP|AFP|BBC|Diário\s+\S+|Jornal\s+\S+|Folha\s+de\s+\S+(?:\s+\S+)?|Coluna\s+do\s+\S+|Rádio\s+\S+|F1Mania\.net)';
        $t = preg_replace('/\s+[-–—]\s+' . $veiculosComuns . '\s*$/iu', '', $t) ?? $t;
        // Fallback genérico: " - WordCapitalizadaCurta" no fim (≤25 chars, sem números)
        $t = preg_replace('/\s+[-–—]\s+([A-ZÀ-Ý][A-Za-zÀ-ÿ\s\.\!]{0,23})\s*$/u', '', $t) ?? $t;

        // Primeira ocorrência de em-dash/en-dash → ":"
        $t = preg_replace('/\s*[—–]\s*/u', ': ', $t, 1) ?? $t;
        // Demais ocorrências (raras) → ", "
        $t = preg_replace('/\s*[—–]\s*/u', ', ', $t) ?? $t;
        // Colapsa ": : " que possa ter surgido se já havia dois pontos
        $t = preg_replace('/\s*:\s*:\s*/u', ': ', $t) ?? $t;
        // Múltiplos espaços
        $t = preg_replace('/\s{2,}/u', ' ', $t) ?? $t;
        return trim($t);
    }

    // ═══ REMOVER FRASES-TEMPLATE (cara-de-IA) ═══
    // Detecta e substitui/remove frases genéricas típicas de conteúdo gerado por IA.
    // Estratégia: cada entrada é ['pattern' => regex, 'replace' => null (remove frase) | string (substitui)].
    private static array $frasesTemplate = [
        // "Olha só cada um deles:" / "Olha só como funciona:" — intro oca de lista
        ['pattern' => '/\bOlha\s+só\s+(?:cada\s+um\s+deles|como\s+funciona|o\s+que\s+tem|os\s+detalhes)\s*:?\s*/iu', 'replace' => ''],
        // "Se você ainda não [verbo + até 5 palavras], leia isso agora"
        ['pattern' => '/\bSe\s+você\s+ainda\s+não\s+(?:\S+\s+){1,6}leia\s+isso\s+agora\b[\.!]?/iu', 'replace' => ''],
        // "Entenda tudo sobre" / "Saiba mais sobre" / "Confira a seguir" — intros vazias
        ['pattern' => '/\b(?:Entenda\s+tudo\s+sobre|Saiba\s+mais\s+sobre|Confira\s+a\s+seguir|Descubra\s+agora|Tudo\s+(?:o\s+)?que\s+você\s+precisa\s+saber\s+sobre)\b\s*:?\s*/iu', 'replace' => ''],
        // "Neste artigo, vamos falar sobre" — meta-narração
        ['pattern' => '/\bNest[ea]\s+(?:artigo|conteúdo|post|matéria)(?:,?\s+(?:vamos\s+(?:falar|explicar)|você\s+vai\s+(?:descobrir|entender|aprender)))?\s+sobre\b\s*/iu', 'replace' => ''],
        // "Continue lendo" / "A seguir, entenda" — quebra de ritmo
        ['pattern' => '/\b(?:Continue\s+lendo|A\s+seguir,?\s+entenda)\b[\.!]?\s*/iu', 'replace' => ''],
        // Enchimento retórico
        ['pattern' => '/\b(?:Sem\s+dúvidas?|Com\s+certeza|Certamente|Vale\s+destacar(?:\s+que)?|É\s+importante\s+(?:lembrar|mencionar|destacar|ressaltar)|Vale\s+a\s+pena\s+ficar\s+atento)\b,?\s*/iu', 'replace' => ''],
        // "Não perca essa oportunidade" — clickbait vazio
        ['pattern' => '/\bNão\s+perca\s+(?:essa|esta)\s+(?:oportunidade|chance)\b[\.!]?\s*/iu', 'replace' => ''],
        // "processo leva menos de N minutos" / "leva poucos minutos" / "é rapidinho" — clichê de tempo vago
        ['pattern' => '/\bo\s+(?:processo|pedido|cadastro|procedimento)\s+(?:leva|é\s+feito\s+em|dura)\s+(?:menos\s+de\s+)?(?:poucos\s+minutos|alguns\s+minutos|\d{1,2}\s+minutos)\b[\.!]?\s*/iu', 'replace' => ''],
        ['pattern' => '/\b(?:leva|é\s+feito\s+em|dura)\s+(?:menos\s+de\s+)?poucos\s+minutos\b[\.!]?\s*/iu', 'replace' => ''],
        ['pattern' => '/\bem\s+menos\s+de\s+\d{1,2}\s+minutos\b[\.!]?\s*/iu', 'replace' => ''],
        ['pattern' => '/\b(?:é\s+)?rapidinho\b[\.!]?\s*/iu', 'replace' => ''],
        // Informalidade excessiva no CTA final
        ['pattern' => '/\b(?:manda|envia|mande|envie)\s+(?:esse|este)\s+(?:artigo|post|link|texto)\b[^.!?]*[\.!?]?\s*/iu', 'replace' => ''],
        ['pattern' => '/\b(?:manda|envia|mande|envie)\s+pra?\s+(?:quem|alguém|o\s+grupo|todo\s+mundo|a\s+família|seu\s+grupo)\b[^.!?]*[\.!?]?\s*/iu', 'replace' => ''],
        ['pattern' => '/\bpassa\s+(?:esse|este|o)\s+link\b[^.!?]*[\.!?]?\s*/iu', 'replace' => ''],
        ['pattern' => '/\bcompartilh[ae]\s+com\s+quem\b[^.!?]*[\.!?]?\s*/iu', 'replace' => ''],
        ['pattern' => '/\bse\s+ajudou,?\s+(?:manda|envie|compartilh[ae])\b[^.!?]*[\.!?]?\s*/iu', 'replace' => ''],
        // Eco-frases (referências ao próprio texto)
        ['pattern' => '/\b(?:Como\s+vimos|Conforme\s+(?:dito|mencionado)|Como\s+já\s+(?:mencionamos|dissemos|falamos))\s*,?\s*/iu', 'replace' => ''],
        // Aforismos genéricos de urgência (fecham parágrafo sem agregar)
        ['pattern' => '/\bO\s+erro\s+é\s+silencioso\b[^.!?]*[\.!?]?\s*/iu',              'replace' => ''],
        ['pattern' => '/\bs[eé]m\s+exceção\b[,\.]?\s*/iu',                               'replace' => ''],
        ['pattern' => '/\bantes\s+mesmo\s+de\s+você\s+perceber\b[\.!?]?\s*/iu',          'replace' => ''],
        ['pattern' => '/\bsem\s+direito\s+a\s+(?:qualquer\s+)?recurso\b[\.!?]?\s*/iu',   'replace' => ''],
        ['pattern' => '/\bsem\s+aviso\s+prévio\b[\.!?]?\s*/iu',                          'replace' => ''],
        ['pattern' => '/\bdescobre\s+tarde\s+demais\b[\.!?]?\s*/iu',                     'replace' => ''],
        ['pattern' => '/\bpassa\s+batido\b[\.!?]?\s*/iu',                                'replace' => ''],
        ['pattern' => '/\belimina\s+(?:o\s+candidato\s+)?antes\s+de\s+qualquer\s+recurso\b[\.!?]?\s*/iu', 'replace' => ''],
        // Frases-template prescritas anteriormente que viraram padrão detectável
        ['pattern' => '/\bA\s+vaga\s+não\s+espera\.?\s*/iu',                 'replace' => ''],
        ['pattern' => '/\bA\s+maioria\s+perde\s+por\s+isso\.?\s*/iu',        'replace' => ''],
        ['pattern' => '/\bQuem\s+chega\s+depois,?\s+não\s+entra\.?\s*/iu',   'replace' => ''],
        ['pattern' => '/\bParece\s+simples\.\s+Não\s+é\.?\s*/iu',            'replace' => ''],
        ['pattern' => '/\bÉ\s+aqui\s+que\s+a\s+maioria\s+erra\.?\s*/iu',     'replace' => ''],
        ['pattern' => '/\bFica\s+a\s+dica\.?\s*/iu',                         'replace' => ''],
        ['pattern' => '/\bSimples\s+assim\.?\s*/iu',                         'replace' => ''],
        ['pattern' => '/\bFica\s+esperto[\.!]?\s*/iu',                       'replace' => ''],
        // Redundâncias comuns (substitui pela forma enxuta)
        ['pattern' => '/\bprazo\s+final\b/iu',                 'replace' => 'prazo'],
        ['pattern' => '/\bconfirmação\s+oficial\s+do\s+governo\b/iu', 'replace' => 'confirmação do governo'],
        ['pattern' => '/\bplanejamento\s+prévio\b/iu',         'replace' => 'planejamento'],
        ['pattern' => '/\btotalmente\s+(?:grátis|gratuito|gratuita)\b/iu', 'replace' => 'gratuito'],
        ['pattern' => '/\bbenefício\s+gratuito\b/iu',          'replace' => 'benefício'],
        ['pattern' => '/\ba\s+partir\s+do\s+momento\s+em\s+que\b/iu', 'replace' => 'quando'],
        ['pattern' => '/\bpor\s+meio\s+de\b/iu',               'replace' => 'por'],
        ['pattern' => '/\bcom\s+o\s+objetivo\s+de\b/iu',       'replace' => 'para'],
        ['pattern' => '/\bde\s+forma\s+a\b/iu',                'replace' => 'para'],
        ['pattern' => '/\bfaz(?:em)?\s+uso\s+d[eao]s?\b/iu',   'replace' => 'usam'],
        ['pattern' => '/\bno\s+que\s+diz\s+respeito\s+a\b/iu', 'replace' => 'sobre'],
    ];

    private static function removerFrasesTemplate(string $html): string
    {
        // Extrai blocos reservados (não podem ter regex de template aplicado neles)
        [$html, $protegidos] = self::extrairBlocosProtegidos($html);

        foreach (self::$frasesTemplate as $regra) {
            $html = preg_replace($regra['pattern'], $regra['replace'], $html) ?? $html;
        }
        // Limpa parágrafos que ficaram com só pontuação/espaço após as remoções
        $html = preg_replace('/<p[^>]*>\s*[\.,:;!?]?\s*<\/p>/i', '', $html) ?? $html;
        // Colapsa 3+ espaços em 1
        $html = preg_replace('/[ \t]{3,}/', ' ', $html) ?? $html;
        // Corrige início de frase em maiúscula após remoção no meio de parágrafo
        $html = preg_replace_callback(
            '/(<p[^>]*>)(\s*)([a-záéíóúâêôãõç])/u',
            fn($m) => $m[1] . $m[2] . mb_strtoupper($m[3], 'UTF-8'),
            $html
        ) ?? $html;

        // Reinjeta blocos protegidos
        return self::reinjetarBlocosProtegidos($html, $protegidos);
    }

    /**
     * Substitui blocos reservados (post-share, msg-card, cluster-interlink, leia-tambem, bloco-resumo,
     * script JSON-LD) por placeholders únicos, antes de aplicar filtros que podem corromper o conteúdo.
     * @return array [html_placeholder, protegidos_array]
     */
    private static function extrairBlocosProtegidos(string $html): array
    {
        $protegidos = [];
        $padroes = [
            '/<div class="post-share"[\s\S]*?<\/div>\s*(?:<script[^>]*data-post-share-runtime[\s\S]*?<\/script>)?/',
            '/<div class="msg-card">[\s\S]*?<\/div>\s*<\/div>/',
            '/<!-- cluster-interlink -->[\s\S]*?<!-- \/cluster-interlink -->/',
            '/<!-- leia-tambem -->[\s\S]*?<!-- \/leia-tambem -->/',
            '/<!-- cluster-inline -->[\s\S]*?<!-- \/cluster-inline -->/',
            '/<!-- cluster-schema -->[\s\S]*?<!-- \/cluster-schema -->/',
            '/<ul class="bloco-resumo">[\s\S]*?<\/ul>/',
            '/<script[^>]*type=[\'"]application\/ld\+json[\'"][\s\S]*?<\/script>/',
            '/<style[^>]*data-msg-runtime[\s\S]*?<\/style>/',
            '/<script[^>]*data-msg-runtime[\s\S]*?<\/script>/',
        ];
        $idx = 0;
        foreach ($padroes as $p) {
            $html = preg_replace_callback($p, function($m) use (&$protegidos, &$idx) {
                $token = "__PROTECTED_BLOCK_{$idx}__";
                $protegidos[$token] = $m[0];
                $idx++;
                return $token;
            }, $html) ?? $html;
        }
        return [$html, $protegidos];
    }

    private static function reinjetarBlocosProtegidos(string $html, array $protegidos): string
    {
        if (empty($protegidos)) return $html;
        return strtr($html, $protegidos);
    }

    // ═══ REMOVER PREFIXOS METAFÓRICOS DE H2/H3 ═══
    // Clichês como "Pulo do Gato: …", "Sem Enrolação: …" não agregam informação e soam repetitivos.
    private static array $prefixosBanidos = [
        'pulo do gato',
        'sem enrolação',
        'sem enrolacao',
        'direto ao ponto',
        'dinheiro no bolso',
        'no papel',
        'na prática',
        'na pratica',
        'de olho',
        'de olho em',
        'dica de ouro',
        'pé no chão',
        'pe no chao',
        'sem rodeios',
        'no bolso',
        'passa batido',
    ];

    private static function removerPrefixosMetaforicosH2(string $html): string
    {
        return preg_replace_callback(
            '/(<h[23][^>]*>)([\s\S]*?)(<\/h[23]>)/i',
            function($m) {
                $inner = $m[2];
                $plain = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($plain === '') return $m[0];

                // Verifica se começa com um dos prefixos seguidos de : ou —
                foreach (self::$prefixosBanidos as $pref) {
                    $padrao = '/^\s*' . preg_quote($pref, '/') . '\s*[:\-–—]\s*/iu';
                    if (preg_match($padrao, $plain)) {
                        $novoPlain = preg_replace($padrao, '', $plain);
                        $novoPlain = mb_strtoupper(mb_substr($novoPlain, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($novoPlain, 1, null, 'UTF-8');
                        if (trim($novoPlain) === '') return $m[0]; // não deixa título vazio
                        return $m[1] . htmlspecialchars($novoPlain, ENT_QUOTES, 'UTF-8') . $m[3];
                    }
                }
                return $m[0];
            },
            $html
        ) ?? $html;
    }

    // ═══ REVERTER msg-cards POSICIONADOS ANTES DO 1º H2 ═══
    // Posts antigos (gerados antes do filtro "5+ itens" ficar firme) tiveram textos de intro/TL;DR
    // transformados em msg-card. Se encontrarmos cards ANTES do primeiro <h2>, revertem pra <p>/<li>.
    private static function reverterCardsNaIntro(string $html): string
    {
        // Localiza posição do 1º <h2>. Se não houver, não mexe (risco alto).
        if (!preg_match('/<h2\b/i', $html, $hm, PREG_OFFSET_CAPTURE)) return $html;
        $h2Pos = $hm[0][1];

        $intro = substr($html, 0, $h2Pos);
        $body  = substr($html, $h2Pos);

        if (strpos($intro, 'class="msg-card"') === false) return $html;

        // Extrai todos os textos dos msg-cards da intro
        $padrao = '/<div class="msg-card">\s*<p class="msg-text">([\s\S]*?)<\/p>\s*<div class="msg-actions">[\s\S]*?<\/div>\s*<\/div>/';
        if (!preg_match_all($padrao, $intro, $cm)) return $html;

        $textos = [];
        foreach ($cm[1] as $t) {
            $t = trim(html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($t !== '') $textos[] = $t;
        }
        if (empty($textos)) return $html;

        // Remove os cards do intro
        $introLimpa = preg_replace($padrao, '', $intro);
        $introLimpa = preg_replace('/(\r?\n){3,}/', "\n\n", $introLimpa);

        // Reinsere como parágrafos simples (não como <ul> — eram intro/TL;DR, não lista)
        $reinsercao = '';
        foreach ($textos as $t) {
            $reinsercao .= '<p>' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";
        }

        return rtrim($introLimpa) . "\n" . $reinsercao . $body;
    }

    // ═══ LIMPEZA DO "LEIA TAMBÉM" ANTIGO (cards com imagens → lista simples de títulos) ═══
    private static function limparLeiaTambemAntigo(string $html): string
    {
        // Se não tem o marcador do formato antigo, pula
        if (strpos($html, 'cc-card__thumb') === false && strpos($html, 'cc-card--horizontal') === false) {
            return $html;
        }

        // Acha a seção: <h2>Leia também</h2> (ou variantes) + articles até o próximo h2 ou fim
        $pattern = '/(<h2[^>]*>\s*Leia\s+tamb[ée]m\s*<\/h2>)([\s\S]*?)(?=<h2[^>]*>|<!--\s*cluster-interlink|$)/iu';
        if (!preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE)) return $html;

        $matchInteiro = $m[0][0];
        $posInicio    = $m[0][1];
        $conteudo     = $m[2][0];

        // Só aplica se o conteúdo tem o marcador antigo
        if (strpos($conteudo, 'cc-card') === false) return $html;

        // Extrai title + link de cada <article>
        $posts = [];
        if (preg_match_all('/<article[^>]*cc-card[^>]*>([\s\S]*?)<\/article>/i', $conteudo, $arts)) {
            foreach ($arts[1] as $art) {
                // Pega o link e título do <h3 class="cc-card__title"><a href=..>title</a></h3>
                if (preg_match('/<h3[^>]*cc-card__title[^>]*>\s*<a[^>]+href=[\'"]([^\'"]+)[\'"][^>]*>([\s\S]*?)<\/a>/i', $art, $tm)) {
                    $link = trim(html_entity_decode($tm[1]));
                    $titulo = trim(html_entity_decode(strip_tags($tm[2])));
                    if ($link !== '' && $titulo !== '') {
                        $posts[] = ['link' => $link, 'titulo' => $titulo];
                    }
                }
            }
        }

        if (empty($posts)) return $html;

        // Dedupe por URL
        $seen = [];
        $posts = array_values(array_filter($posts, function($p) use (&$seen) {
            if (isset($seen[$p['link']])) return false;
            $seen[$p['link']] = true;
            return true;
        }));

        // Monta bloco novo (mesmo padrão do Maquina::montarRelacionados atualizado)
        $novo = "\n<!-- leia-tambem -->\n"
              . "<div class='leia-tambem' style='background:#f8fafc;border-left:4px solid #0369a1;padding:16px 20px;margin:30px 0;border-radius:8px'>"
              . "<strong style='font-size:1.1em;color:#0c4a6e;display:block;margin-bottom:10px'>Leia também</strong>"
              . "<ul style='margin:0;padding-left:18px;list-style:none'>";
        foreach ($posts as $p) {
            $titulo = htmlspecialchars($p['titulo'], ENT_QUOTES, 'UTF-8');
            $link   = htmlspecialchars($p['link'],   ENT_QUOTES, 'UTF-8');
            $novo .= "<li style='margin-bottom:6px;padding-left:4px'>"
                  .    "<strong style='color:#0369a1'>+</strong> "
                  .    "<a href='{$link}'>{$titulo}</a>"
                  . "</li>";
        }
        $novo .= "</ul></div>\n<!-- /leia-tambem -->\n";

        // Substitui
        return substr($html, 0, $posInicio) . $novo . substr($html, $posInicio + strlen($matchInteiro));
    }

    // ═══ LIMPEZA DE SCHEMAS ═══
    private static function removerSchemasRedundantes(string $html): string
    {
        // Remove <script type="application/ld+json"> que contenha Article/NewsArticle/BlogPosting
        return preg_replace_callback(
            '/<script[^>]*type=[\'"]application\/ld\+json[\'"][^>]*>([\s\S]*?)<\/script>/i',
            function($m) {
                $json = trim($m[1]);
                if (preg_match('/"@type"\s*:\s*"(Article|NewsArticle|BlogPosting|WebPage)"/i', $json)) {
                    return ''; // remove — Rank Math cuida
                }
                return $m[0];
            },
            $html
        ) ?? $html;
    }

    // ═══ AUTO-LINK WHATSAPP ═══
    // Padrão: "WhatsApp (XX) XXXXX-XXXX" ou "zap XX XXXX-XXXX" — linka pra wa.me/55...
    private static function autoLinkWhatsApp(string $html): string
    {
        // Usa DOM (mesmo padrão de autoLinkTelefones) pra evitar vazar pra atributos
        // de tags existentes — bug similar ao autoLinkDominios pré-fix.
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);
        $textNodes = $xp->query('//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)]');
        $pattern = '/\b(WhatsApp|WhatsApp:|zap|Zap|ZAP)\s*[:\s]*[-–—]?\s*(\+?(?:55\s*)?\(?\d{2}\)?[\s\-]*\d{4,5}[\s\-]*\d{4})/u';
        $aplicou = false;
        foreach ($textNodes as $node) {
            $text = $node->nodeValue;
            if (!preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) continue;
            $matchStart = $m[0][1];
            $matchStr   = $m[0][0];
            $numero = preg_replace('/\D/', '', $m[2][0]);
            $numeroE164 = self::normalizarNumeroBR($numero);
            if ($numeroE164 === null) continue;
            $antes  = substr($text, 0, $matchStart);
            $depois = substr($text, $matchStart + strlen($matchStr));
            $frag = $dom->createDocumentFragment();
            // Preserva o prefixo "WhatsApp" + espaço como texto normal
            $frag->appendChild($dom->createTextNode($antes . $m[1][0] . ' '));
            $a = $dom->createElement('a', trim($m[2][0]));
            $a->setAttribute('href', 'https://wa.me/' . $numeroE164);
            $frag->appendChild($a);
            if ($depois !== '') $frag->appendChild($dom->createTextNode($depois));
            $node->parentNode->replaceChild($frag, $node);
            $aplicou = true;
        }
        if (!$aplicou) return $html;
        $out = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out !== '' ? $out : $html;
    }

    // ═══ AUTO-LINK TELEFONES ═══
    // Captura telefones BR em formato (XX) XXXX(X)-XXXX quando NÃO estão dentro de tags ou já linkados
    private static function autoLinkTelefones(string $html): string
    {
        // Usa DOMDocument pra processar só texto (evita quebrar href/atributos existentes)
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // Wrapper com encoding pra preservar acentos
        $wrapper = '<?xml encoding="UTF-8"?><div>' . $html . '</div>';
        @$dom->loadHTML($wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xp = new DOMXPath($dom);
        // Text nodes que NÃO estão dentro de <a>
        $textNodes = $xp->query('//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)]');

        $pattern = '/(?<![\d\w])\(?(\d{2})\)?[\s\-]*(\d{4,5})[\s\-]*(\d{4})(?![\d\w])/';

        foreach ($textNodes as $node) {
            $text = $node->nodeValue;
            if (!preg_match($pattern, $text)) continue;

            // Quebra o texto em pedaços: matches viram <a>, o resto texto normal
            $pos = 0;
            $newFragment = $dom->createDocumentFragment();
            $found = false;
            while (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $pos)) {
                $matchStart = $m[0][1];
                $matchStr   = $m[0][0];
                // texto antes do match
                if ($matchStart > $pos) {
                    $newFragment->appendChild($dom->createTextNode(substr($text, $pos, $matchStart - $pos)));
                }
                // Monta o número
                $numero = $m[1][0] . $m[2][0] . $m[3][0];
                $numeroE164 = self::normalizarNumeroBR($numero);
                if ($numeroE164) {
                    $a = $dom->createElement('a', htmlspecialchars_decode($matchStr));
                    $a->setAttribute('href', 'tel:+' . $numeroE164);
                    $newFragment->appendChild($a);
                    $found = true;
                } else {
                    $newFragment->appendChild($dom->createTextNode($matchStr));
                }
                $pos = $matchStart + strlen($matchStr);
            }
            if ($found) {
                if ($pos < strlen($text)) {
                    $newFragment->appendChild($dom->createTextNode(substr($text, $pos)));
                }
                $node->parentNode->replaceChild($newFragment, $node);
            }
        }

        // Extrai HTML do wrapper
        $out = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out !== '' ? $out : $html;
    }

    private static function normalizarNumeroBR(string $digits): ?string
    {
        $n = preg_replace('/\D/', '', $digits);
        $len = strlen($n);
        // DDD (2) + número (8 ou 9) = 10 ou 11
        if ($len === 10 || $len === 11) return '55' . $n;
        // Já com 55: 12 ou 13
        if (($len === 12 || $len === 13) && strpos($n, '55') === 0) return $n;
        return null;
    }

    // ═══ AUTO-LINK DE DOMÍNIOS LITERAIS ═══
    /**
     * Detecta menções a domínios oficiais em texto puro (não dentro de <a>) e cria link.
     * Crítico em E-E-A-T: artigo cita "enem.inep.gov.br" e leitor pode clicar pra ir.
     *
     * Whitelist de domínios mapeados pra URL canônica (http→https, com path correto).
     * Só linka 1ª ocorrência de cada domínio (evita poluir texto com mesma âncora).
     */
    private static function autoLinkDominios(string $html): string
    {
        $oficiais = [
            // Educação federal — URLs apontam pra páginas FUNCIONAIS (onde leitor age), não raiz
            'enem.inep.gov.br'       => 'https://enem.inep.gov.br/participante/',
            'acessounico.mec.gov.br' => 'https://acessounico.mec.gov.br/',
            'sisu.mec.gov.br'        => 'https://acessounico.mec.gov.br/sisu',
            'prouni.mec.gov.br'      => 'https://acessounico.mec.gov.br/prouni',
            'fies.mec.gov.br'        => 'https://acessounico.mec.gov.br/fies',
            'inep.gov.br'            => 'https://www.gov.br/inep/',
            'mec.gov.br'             => 'https://www.gov.br/mec/',
            'capes.gov.br'           => 'https://www.gov.br/capes/',
            'cnpq.br'                => 'https://www.gov.br/cnpq/',
            // Trabalho/benefícios
            'meu.inss.gov.br'        => 'https://meu.inss.gov.br/',
            'meuinss.gov.br'         => 'https://meu.inss.gov.br/',
            'caixa.gov.br'           => 'https://www.caixa.gov.br/',
            'caixatem.com.br'        => 'https://caixatem.caixa.gov.br/',
            'cadastrounico.gov.br'   => 'https://www.gov.br/mds/pt-br/acoes-e-programas/cadastro-unico',
            // Receita / fazenda
            'receita.fazenda.gov.br' => 'https://www.gov.br/receitafederal/',
            'gov.br/receitafederal'  => 'https://www.gov.br/receitafederal/',
            // Saúde
            'anvisa.gov.br'          => 'https://www.gov.br/anvisa/',
            'sus.gov.br'             => 'https://www.gov.br/saude/pt-br/assuntos/saude-de-a-a-z/s/sus',
            // Trânsito
            'denatran.gov.br'        => 'https://www.gov.br/pt-br/orgaos/departamento-nacional-de-transito',
            'detran.sp.gov.br'       => 'https://www.detran.sp.gov.br/',
            // Justiça/eleitoral
            'tse.jus.br'             => 'https://www.tse.jus.br/',
            'stf.jus.br'             => 'https://portal.stf.jus.br/',
            // Standards/tech
            'schema.org'             => 'https://schema.org/',
            // Genérico (último — só se nenhum específico bateu)
            'gov.br'                 => 'https://www.gov.br/',
        ];

        // Ordena por tamanho desc — domínios maiores (mais específicos) substituem antes
        uksort($oficiais, fn($a, $b) => strlen($b) <=> strlen($a));

        // Bug 2026-04-27: regex substituía DENTRO de atributos `title="..."` de links existentes,
        // criando <a> aninhado dentro de <a> e quebrando HTML (rel/target literais como texto).
        // Fix: usar DOM — atributos não são text nodes, então substituição não vaza pra atributos.
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);

        // Só text nodes fora de <a>, <script>, <style> (igual AuthorityLinks)
        $textNodes = iterator_to_array($xp->query(
            '//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)]'
        ));

        $jaLinkado = []; // garante 1 link por domínio
        foreach ($oficiais as $dominio => $url) {
            if (isset($jaLinkado[$dominio])) continue;
            $domEsc = preg_quote($dominio, '#');
            $regex  = '#(?<![./\w@])(' . $domEsc . ')(?![\w./?-])#i';

            foreach ($textNodes as $node) {
                if (!$node->parentNode) continue;
                $text = $node->nodeValue;
                if (!preg_match($regex, $text, $m, PREG_OFFSET_CAPTURE)) continue;

                $matchStart = $m[0][1];
                $matchStr   = $m[0][0];
                $antes  = substr($text, 0, $matchStart);
                $depois = substr($text, $matchStart + strlen($matchStr));

                $frag = $dom->createDocumentFragment();
                if ($antes !== '')  $frag->appendChild($dom->createTextNode($antes));
                $a = $dom->createElement('a', $matchStr);
                $a->setAttribute('href', $url);
                $a->setAttribute('target', '_blank');
                $a->setAttribute('rel', 'noopener external');
                $frag->appendChild($a);
                if ($depois !== '') $frag->appendChild($dom->createTextNode($depois));

                $node->parentNode->replaceChild($frag, $node);
                $jaLinkado[$dominio] = true;
                break; // próximo domínio
            }
        }

        if (empty($jaLinkado)) return $html;

        $out = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out !== '' ? $out : $html;
    }

    // ═══ FAQPage SCHEMA ═══
    private static function injetarFaqSchema(string $html): string
    {
        // Se já tem FAQPage, não duplica
        if (preg_match('/"@type"\s*:\s*"FAQPage"/i', $html)) return $html;

        $faq = self::extrairFaq($html);
        if (count($faq) < 2) return $html; // precisa de pelo menos 2 Q&A

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'FAQPage',
            'mainEntity' => array_map(fn($q) => [
                '@type'          => 'Question',
                'name'           => $q['pergunta'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $q['resposta'],
                ],
            ], $faq),
        ];
        $script = "\n" . '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
        return $html . $script;
    }

    /** Extrai pares pergunta-resposta do HTML (H3 seguido de P dentro de seção FAQ). */
    private static function extrairFaq(string $html): array
    {
        $faq = [];

        // Padrão 1: <details><summary>Q?</summary><p>R</p></details> — preferido (formato interativo)
        if (preg_match_all('/<details[^>]*>\s*<summary[^>]*>([\s\S]*?)<\/summary>([\s\S]*?)<\/details>/iu', $html, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $match) {
                $q = trim(strip_tags(html_entity_decode($match[1])));
                $r = trim(strip_tags(html_entity_decode($match[2])));
                $r = preg_replace('/\s+/u', ' ', $r);
                if ($q !== '' && $r !== '' && mb_strlen($r) > 10) {
                    if (!str_ends_with($q, '?')) $q .= '?';
                    $faq[] = ['pergunta' => $q, 'resposta' => $r];
                }
            }
        }

        // Padrão 2: <h2>Perguntas frequentes</h2> ... <h3>Q?</h3><p>R</p> (formato textual)
        // Só roda se padrão 1 não retornou nada (evita dupla contagem)
        if (empty($faq)) {
            $kws = 'Perguntas\s+frequentes|Perguntas\s+e\s+respostas|FAQ|Dúvidas(?:\s+frequentes|\s+comuns|\s+mais\s+comuns)?|Tire\s+suas\s+dúvidas|Principais\s+dúvidas';
            if (preg_match('/<h[23][^>]*>[\s\S]*?(?:' . $kws . ')[\s\S]*?<\/h[23]>([\s\S]*?)(?=<h2[^>]*>|$)/iu', $html, $m)) {
                $secao = $m[1];
                if (preg_match_all('/<h[34][^>]*>([^<]+\??)<\/h[34]>\s*((?:<p[^>]*>[\s\S]*?<\/p>\s*)+)/iu', $secao, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $q = trim(strip_tags($match[1]));
                        $r = trim(strip_tags(html_entity_decode($match[2])));
                        $r = preg_replace('/\s+/u', ' ', $r);
                        if ($q !== '' && $r !== '' && mb_strlen($r) > 10) {
                            if (!str_ends_with($q, '?')) $q .= '?';
                            $faq[] = ['pergunta' => $q, 'resposta' => $r];
                        }
                    }
                }
            }
        }

        return array_slice($faq, 0, 10);
    }

    // ═══ HowTo SCHEMA ═══
    private static function injetarHowToSchema(string $html, array $meta): string
    {
        if (preg_match('/"@type"\s*:\s*"HowTo"/i', $html)) return $html;

        // Trigger 1: H2/H3 com keyword de tutorial seguido de <ol> (passo-a-passo)
        // Cobre: "Como X", "Passo a passo", "Tutorial", "Guia", "X em N passos"
        // NOTA: o `(?:<p>.*?</p>\s*)*` opcional original causava catastrophic backtracking
        // em conteúdos longos (regex engine atinge limite e retorna 0). Estratégia nova:
        // 2 passes — primeiro tenta h2+ol direto (caso simples, mais comum),
        // depois h2 + qualquer conteúdo limitado a 2000 chars + ol (caso com p intermediário).
        $kwTutorial = 'Como\s+[^<]+|Passo\s+a\s+passo[^<]*|Tutorial[^<]*|Guia\s+(?:passo\s+a\s+passo|completo|pr[áa]tico)[^<]*|[^<]+\sem\s+\d+\s+passos[^<]*';
        $m = null;
        // Pass 1: h2 imediatamente seguido de <ol> (whitespace só) — caso mais comum
        $pad1 = '/<h[23][^>]*>(' . $kwTutorial . ')<\/h[23]>\s*<ol[^>]*>([\s\S]*?)<\/ol>/iu';
        if (!preg_match($pad1, $html, $m)) {
            // Pass 2: h2 + 1 ou mais <p> intermediários + <ol>. Padrão simples (sem
            // {0,2000} negative-lookahead que estoura regex size em PCRE).
            $pad2 = '/<h[23][^>]*>(' . $kwTutorial . ')<\/h[23]>\s*(?:<p[^>]*>[\s\S]*?<\/p>\s*){1,5}<ol[^>]*>([\s\S]*?)<\/ol>/iu';
            if (!preg_match($pad2, $html, $m)) {
                // Pass 3: H2 com id='como-*' (fallback) — só com <ol> direto após h2
                $pad3 = "/<h2[^>]*id=['\"]como-[^'\"]+['\"][^>]*>([^<]+)<\/h2>\s*(?:<p[^>]*>[\s\S]*?<\/p>\s*){0,5}<ol[^>]*>([\s\S]*?)<\/ol>/iu";
                if (!preg_match($pad3, $html, $m)) {
                    // Pass 4: localiza h2-tutorial e <ol> separadamente, valida proximidade.
                    // Cobre casos atípicos sem regex grande.
                    if (preg_match('/<h[23][^>]*>(' . $kwTutorial . ')<\/h[23]>/iu', $html, $hm, PREG_OFFSET_CAPTURE)) {
                        $h2End = $hm[0][1] + strlen($hm[0][0]);
                        $resto = substr($html, $h2End, 5000);
                        if (preg_match('/<ol[^>]*>([\s\S]*?)<\/ol>/i', $resto, $om)) {
                            $m = [$hm[0][0], $hm[1][0], $om[1]];
                        } else {
                            return $html;
                        }
                    } else {
                        return $html;
                    }
                }
            }
        }
        $titulo = trim(strip_tags($m[1]));
        // Extrai <li>
        if (!preg_match_all('/<li[^>]*>([\s\S]*?)<\/li>/i', $m[2], $lis)) return $html;
        // URL canônica do post (se disponível em $meta) pra fallback de step.url
        $postUrl = (string)($meta['url'] ?? $meta['url_post'] ?? '');
        $steps = [];
        foreach ($lis[1] as $i => $li) {
            $texto = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($li))));
            if ($texto === '') continue;
            $step = [
                '@type' => 'HowToStep',
                'position' => $i + 1,
                'name' => mb_strimwidth($texto, 0, 80, '...', 'UTF-8'),
                'text' => $texto,
            ];
            // Extrai 1º <a href> dentro do <li> (link real pra portal de inscrição/recurso)
            // Schema.org HowToStep recommended: ter url pra cada passo aponta pra ancora ou portal externo
            if (preg_match('/<a\s+[^>]*href=[\'"]([^\'"]+)[\'"]/i', $li, $hm)) {
                $href = trim($hm[1]);
                if ($href !== '' && $href !== '#' && stripos($href, 'javascript:') !== 0) {
                    $step['url'] = $href;
                }
            }
            // Fallback: se não tem link no <li> mas temos URL do post → âncora pro step
            if (empty($step['url']) && $postUrl !== '') {
                $step['url'] = rtrim($postUrl, '/') . '#passo-' . ($i + 1);
            }
            $steps[] = $step;
        }
        if (count($steps) < 2) return $html;

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            'name'     => $titulo,
            'step'     => $steps,
        ];
        // totalTime opcional: estima 5min por step (boa heurística pra processo de inscrição)
        $schema['totalTime'] = 'PT' . (count($steps) * 5) . 'M';
        $script = "\n" . '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
        return $html . $script;
    }
}
