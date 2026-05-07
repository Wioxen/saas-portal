<?php
declare(strict_types=1);

/**
 * EntityHubBuilder — gera Pages WP de entidades (IFSP, Senac, MEC, Fundeb, Inep...).
 *
 * Cada Entity Page agrega 30 posts órfãos numa página-hub que ranqueia em queries broad
 * (ex: "IFSP cursos", "Senac EAD") e concentra PageRank interno.
 *
 * Estrutura Wordpress:
 *   Page parent: "Entidades" (slug=entidade)
 *     ↳ Page child: "IFSP" (slug=ifsp) → URL /entidade/ifsp/
 *     ↳ Page child: "Senac" (slug=senac) → URL /entidade/senac/
 *     ...
 *
 * Schema gerado automaticamente pelo Rank Math (já configurado nos sites).
 *
 * Uso (CLI):
 *   $hub = new EntityHubBuilder($wp, $haiku, $sonnet);
 *   $hub->gerarPara($cfgEntidade);
 */
class EntityHubBuilder
{
    private object $wp;          // Wordpress
    private object $sonnet;      // Claude Sonnet (sumario institucional)
    private string $siteSlug;
    private array $log = [];

    public function __construct(object $wp, object $sonnet, string $siteSlug)
    {
        $this->wp = $wp;
        $this->sonnet = $sonnet;
        $this->siteSlug = $siteSlug;
    }

    /**
     * Encontra ou cria a Page parent "Entidades" (slug=entidade).
     * Retorna o ID.
     */
    public function ensureParentPage(): int
    {
        // Tenta achar via WP REST search por slug
        $existente = $this->wp->buscarPaginaPorSlug('entidade');
        if ($existente && !empty($existente['id'])) {
            return (int)$existente['id'];
        }

        // Cria
        $payload = [
            'title'   => 'Entidades',
            'slug'    => 'entidade',
            'status'  => 'publish',
            'content' => '<p>Guias completos sobre as principais instituições de educação cobertas pelo portal: institutos federais, autarquias, programas e órgãos reguladores. Cada página agrega editais ativos, histórico de cobertura, perguntas frequentes e fontes oficiais.</p>',
        ];
        $r = $this->wp->criarPagina($payload);
        return (int)($r['id'] ?? 0);
    }

    /**
     * Gera 1 Entity Page.
     *
     * @param array $cfg ['nome', 'fullname', 'tipo_org', 'slug', 'url_oficial', 'descricao_seed']
     * @param int   $parentId Page parent ID
     * @return array ['id', 'link', 'posts_relacionados']
     */
    public function gerarPara(array $cfg, int $parentId): array
    {
        [$payload, $postsCount] = $this->montarPayload($cfg, $parentId);
        $r = $this->wp->criarPagina($payload);
        $pageId = (int)($r['id'] ?? 0);
        $this->persistirAliasesLocal($pageId, $cfg);
        return [
            'id'   => $pageId,
            'link' => (string)($r['link'] ?? ''),
            'posts_relacionados' => $postsCount,
        ];
    }

    /**
     * Re-renderiza Entity Page existente (regera sumário, posts, FAQ, html) e atualiza via REST.
     * Mantém status=draft. Útil pra aplicar melhorias do builder em pages já criadas.
     */
    public function atualizarPara(array $cfg, int $pageId, int $parentId = 0): array
    {
        [$payload, $postsCount] = $this->montarPayload($cfg, $parentId);
        // Não sobrescreve status existente — REST aceita ausência
        unset($payload['status']);
        $r = $this->wp->atualizarPagina($pageId, $payload);
        $this->persistirAliasesLocal($pageId, $cfg);
        return [
            'id'   => (int)($r['id'] ?? $pageId),
            'link' => (string)($r['link'] ?? ''),
            'posts_relacionados' => $postsCount,
        ];
    }

    /**
     * Atualiza só FAQ + posts agregados (preserva sumário Sonnet existente).
     * Custo zero de API — só REST WP. Pra cron de FAQ Hub crescente.
     *
     * @return array ['id', 'link', 'posts', 'faq_perguntas', 'faq_delta']
     */
    public function atualizarFaqEPostsApenas(array $cfg, int $pageId, int $limiteFaq = 50): array
    {
        // 1. Lê HTML atual e extrai sumário (tudo antes do 1º <h2>)
        // Edge case: hubs com 0 posts não geram <h2> — usa HTML inteiro como sumário.
        $pageAtual = $this->wp->getPagina($pageId);
        $htmlAtual = (string)($pageAtual['content']['raw'] ?? $pageAtual['content']['rendered'] ?? '');
        $pos = stripos($htmlAtual, '<h2');
        $sumarioHtmlPronto = $pos !== false ? trim(substr($htmlAtual, 0, $pos)) : trim($htmlAtual);

        // 2. Conta FAQ atual (pra log do delta)
        $faqAtualCount = preg_match_all('#<details\b[^>]*>\s*<summary[^>]*>#i', $htmlAtual);

        // 3. Re-busca posts e FAQ
        $tipo = (string)($cfg['tipo'] ?? 'entity');
        $primary = $tipo === 'concept'
            ? (string)($cfg['fullname'] ?? '')
            : (string)($cfg['nome'] ?? $cfg['fullname'] ?? '');
        $aliases = (array)($cfg['aliases'] ?? []);
        $posts = $this->buscarPostsDaEntidade($primary, $aliases);
        $faq = $this->extrairFaqAgregado($posts, $limiteFaq);

        // 4. Monta novo HTML reusando sumário pronto (sem chamar Sonnet)
        $cfgComSumario = $cfg;
        $cfgComSumario['_sumario_html_pronto'] = $sumarioHtmlPronto;
        $html = $this->montarHtml($cfgComSumario, '', $posts, $faq);

        // 5. Atualiza via REST
        $r = $this->wp->atualizarPagina($pageId, ['content' => $html]);
        $this->log[] = "[#{$pageId}] FAQ atualizado: {$faqAtualCount} → " . count($faq) . " perguntas (posts: " . count($posts) . ")";

        return [
            'id' => (int)($r['id'] ?? $pageId),
            'link' => (string)($r['link'] ?? ''),
            'posts' => count($posts),
            'faq_perguntas' => count($faq),
            'faq_delta' => count($faq) - (int)$faqAtualCount,
        ];
    }

    /**
     * Persiste aliases (e tipo) por page_id em data/entity_pages_cache/{site}_aliases.json.
     * Usado pelo EntityPageLinker pra enriquecer termos linkáveis.
     */
    private function persistirAliasesLocal(int $pageId, array $cfg): void
    {
        if ($pageId === 0) return;
        $dir = __DIR__ . '/../data/entity_pages_cache';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $path = "{$dir}/{$this->siteSlug}_aliases.json";
        $atual = [];
        if (file_exists($path)) {
            $j = json_decode((string)file_get_contents($path), true);
            if (is_array($j)) $atual = $j;
        }
        $atual[(string)$pageId] = [
            'tipo' => (string)($cfg['tipo'] ?? 'entity'),
            'nome' => (string)($cfg['nome'] ?? ''),
            'fullname' => (string)($cfg['fullname'] ?? ''),
            'slug' => (string)($cfg['slug'] ?? ''),
            'aliases' => array_values($cfg['aliases'] ?? []),
            'updated_at' => date('c'),
        ];
        @file_put_contents($path, json_encode($atual, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Monta o payload completo (title, slug, content, meta) pra criar OU atualizar.
     * Suporta tipo='entity' (default — institutos, órgãos com sigla+url_oficial) e
     * tipo='concept' (conceitos transversais como EAD, Vestibular, Curso Técnico).
     * Retorna [$payload, $postsCount].
     */
    private function montarPayload(array $cfg, int $parentId): array
    {
        $tipo = (string)($cfg['tipo'] ?? 'entity');
        $fullname = (string)$cfg['fullname'];
        $nome = (string)($cfg['nome'] ?? $fullname);
        $slug = (string)$cfg['slug'];
        $rotulo = $tipo === 'concept' ? $fullname : $nome;

        // 1. Busca posts: entity usa nome+aliases; concept usa fullname+aliases (não tem sigla)
        $termosBusca = $tipo === 'concept' ? [$fullname] : [$nome];
        foreach (($cfg['aliases'] ?? []) as $a) $termosBusca[] = $a;
        $postsRelacionados = $this->buscarPostsDaEntidade($termosBusca[0], array_slice($termosBusca, 1));
        $this->log[] = "[$rotulo] " . count($postsRelacionados) . ' posts relacionados encontrados';

        // 2. Sumario via Sonnet
        $sumario = $this->gerarSumario($cfg);
        $this->log[] = "[$rotulo] sumario gerado (" . str_word_count(strip_tags($sumario)) . ' palavras)';

        // 3. FAQ agregado
        $faqAgregado = $this->extrairFaqAgregado($postsRelacionados, 8);
        $this->log[] = "[$rotulo] FAQ agregado: " . count($faqAgregado) . ' perguntas únicas';

        // 4. Monta HTML
        $html = $this->montarHtml($cfg, $sumario, $postsRelacionados, $faqAgregado);

        if ($tipo === 'concept') {
            $titlePage = "{$fullname} — guia completo de cursos, modalidades e como funciona";
            $rankTitle = "{$fullname} — Guia Completo";
            $rankDesc = "Tudo sobre {$fullname}: definição, modalidades, requisitos, prazos e oportunidades. Guia atualizado com cobertura editorial completa.";
            $focusKw = mb_strtolower($fullname);
        } else {
            $titlePage = "{$fullname} ({$nome}) — guia completo de cursos, editais e inscrições";
            $rankTitle = "{$fullname} ({$nome}) — Guia Completo";
            $rankDesc = "Tudo sobre {$nome}: cursos, editais, vestibulares, inscrições e prazos. Guia atualizado com cobertura editorial completa.";
            $focusKw = mb_strtolower($nome);
        }

        $payload = [
            'title'   => $titlePage,
            'slug'    => $slug,
            'status'  => 'draft',
            'content' => $html,
            'meta'    => [
                'rank_math_focus_keyword' => $focusKw,
                'rank_math_title'         => $rankTitle,
                'rank_math_description'   => $rankDesc,
            ],
        ];
        if ($parentId > 0) $payload['parent'] = $parentId;

        return [$payload, count($postsRelacionados)];
    }

    /**
     * Busca posts WP que mencionam a entidade no título OU conteúdo.
     * Tenta nome principal + aliases (ex: "IFSP" + "Instituto Federal São Paulo").
     */
    private function buscarPostsDaEntidade(string $nome, array $aliases): array
    {
        $termos = array_merge([$nome], $aliases);
        $colhidos = [];
        foreach ($termos as $t) {
            try {
                $r = $this->wp->buscarRelacionados($t, 15, 0);
                foreach ($r as $p) {
                    $id = (int)($p['id'] ?? 0);
                    if ($id > 0 && !isset($colhidos[$id])) $colhidos[$id] = $p;
                }
            } catch (Throwable $e) { /* ignora */ }
        }
        // Ordena por data desc (mais recentes primeiro)
        $todos = array_values($colhidos);
        usort($todos, fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
        return array_slice($todos, 0, 30);
    }

    /**
     * Sumário enciclopédico (200 palavras). Adapta tom conforme tipo (entity vs concept).
     */
    private function gerarSumario(array $cfg): string
    {
        $tipo = (string)($cfg['tipo'] ?? 'entity');

        if ($tipo === 'concept') {
            $sys = "Você redige sumários enciclopédicos de conceitos e modalidades educacionais brasileiras. Tom factual, jornalístico, sem opinião. PT-BR. 180-220 palavras. Apenas o sumário, sem introdução nem fechamento meta.\n\n"
                 . "FORMATAÇÃO OBRIGATÓRIA: use <strong> na PRIMEIRA ocorrência de cada termo-chave:\n"
                 . "- Nome do conceito/modalidade (ex: <strong>EAD</strong>, <strong>ensino a distância</strong>, <strong>vestibular</strong>)\n"
                 . "- Sinônimos e variações terminológicas (ex: <strong>educação a distância</strong>)\n"
                 . "- Órgãos reguladores e instituições citadas (ex: <strong>MEC</strong>, <strong>Inep</strong>, <strong>Capes</strong>)\n"
                 . "- Programas e exames relacionados (ex: <strong>Sisu</strong>, <strong>Enem</strong>, <strong>ProUni</strong>)\n"
                 . "- Leis e marcos regulatórios (ex: <strong>LDB</strong>, <strong>Decreto 9.057/2017</strong>)\n"
                 . "- Datas e números marcantes\n"
                 . "Use <strong> APENAS na primeira menção. Máximo 8 negritos por parágrafo.";
            $aliasesStr = !empty($cfg['aliases']) ? implode(', ', $cfg['aliases']) : '';
            $user = "Redija um sumário enciclopédico sobre <strong>{$cfg['fullname']}</strong>.\n\n"
                  . ($aliasesStr !== '' ? "Sinônimos/variações: {$aliasesStr}\n" : '')
                  . "Descrição-seed: {$cfg['descricao_seed']}\n\n"
                  . "Cubra: definição, modalidades/variantes, requisitos típicos, principais instituições/programas que oferecem, regulação federal, vantagens e limites para o estudante.\n"
                  . "NÃO especule. NÃO invente datas. Use dados públicos canônicos.\n"
                  . "Estrutura: 2-3 parágrafos. Sem títulos. Sem listas. Prosa enciclopédica com <strong> nos termos-chave conforme system.";
        } else {
            $sys = "Você redige sumários enciclopédicos de instituições brasileiras de educação. Tom factual, jornalístico, sem opinião. PT-BR. 180-220 palavras. Apenas o sumário, sem introdução nem fechamento meta.\n\n"
                 . "FORMATAÇÃO OBRIGATÓRIA: use a tag <strong> para destacar termos-chave na PRIMEIRA ocorrência de cada um:\n"
                 . "- Sigla principal e nome completo da instituição (ex: <strong>IFSP</strong>, <strong>Instituto Federal de São Paulo</strong>)\n"
                 . "- Nomes de programas, cursos ou exames citados (ex: <strong>Pé-de-Meia</strong>, <strong>Enem</strong>, <strong>ProUni</strong>)\n"
                 . "- Leis, decretos, emendas e marcos regulatórios (ex: <strong>Lei 11.892</strong>, <strong>EC 108/2020</strong>)\n"
                 . "- Órgãos vinculados ou mantenedores citados (ex: <strong>MEC</strong>, <strong>CNC</strong>)\n"
                 . "- Datas de criação e números relevantes (ex: <strong>1937</strong>, <strong>R$ 710 milhões</strong>)\n"
                 . "Use <strong> APENAS na primeira menção de cada termo. Não destaque palavras comuns. Máximo 8 negritos por parágrafo.";
            $user = "Redija um sumário enciclopédico sobre <strong>{$cfg['fullname']} ({$cfg['nome']})</strong>.\n\n"
                  . "Tipo: " . ($cfg['tipo_org'] ?? '') . "\n"
                  . "Site oficial: " . ($cfg['url_oficial'] ?? '') . "\n"
                  . "Descrição-seed: {$cfg['descricao_seed']}\n\n"
                  . "Cubra: definição, atribuição/missão, estrutura/abrangência, principais programas/cursos, vínculo regulatório, ano de criação se relevante.\n"
                  . "NÃO especule. NÃO invente datas. Use dados públicos canônicos.\n"
                  . "Estrutura: 2-3 parágrafos. Sem títulos. Sem listas. Apenas prosa enciclopédica com <strong> nos termos-chave conforme instrução do system.";
        }

        try {
            $resp = $this->sonnet->callPublic([['role' => 'user', 'content' => $user]], $sys, 600);
            $texto = trim((string)($resp['content'][0]['text'] ?? ''));
            if ($texto !== '') return $texto;
        } catch (Throwable $e) {
            $this->log[] = '[sumario_erro] ' . $e->getMessage();
        }
        return (string)($cfg['descricao_seed'] ?? '');
    }

    /**
     * Extrai perguntas-respostas de todos os <details> dos posts relacionados.
     * Dedupe por similaridade de pergunta (Jaccard ≥0.6 = duplicada).
     * Retorna top N perguntas únicas, com resposta do primeiro post que cobriu.
     */
    private function extrairFaqAgregado(array $posts, int $limite = 8): array
    {
        $perguntas = [];
        foreach ($posts as $p) {
            $id = (int)($p['id'] ?? 0);
            if ($id === 0) continue;
            try {
                $full = $this->wp->getPost($id);
                $content = (string)($full['content']['rendered'] ?? '');
                if ($content === '') continue;
                if (!preg_match_all('#<details\b[^>]*>\s*<summary[^>]*>([^<]+)</summary>(.+?)</details>#is', $content, $mm, PREG_SET_ORDER)) continue;
                foreach ($mm as $m) {
                    $perg = trim(strip_tags(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')));
                    $resp = trim($m[2]);
                    if (mb_strlen($perg) < 10 || mb_strlen($perg) > 200) continue;
                    // Dedupe por Jaccard
                    $tokensP = $this->tokens($perg);
                    $duplicada = false;
                    foreach ($perguntas as $exist) {
                        if ($this->jaccard($tokensP, $this->tokens($exist['pergunta'])) >= 0.6) { $duplicada = true; break; }
                    }
                    if ($duplicada) continue;
                    $perguntas[] = ['pergunta' => $perg, 'resposta' => $resp, 'post_id' => $id];
                    if (count($perguntas) >= $limite) return $perguntas;
                }
            } catch (Throwable $e) { /* ignora post que falha */ }
        }
        return $perguntas;
    }

    private function tokens(string $s): array
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, 'áéíóúâêôàãõç', 'aeiouaeoaaoc');
        $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s) ?? '';
        $parts = preg_split('/\s+/', trim($s)) ?: [];
        $stop = ['que','de','da','do','das','dos','o','a','os','as','e','para','com','em','no','na','um','uma','é','são','foi','tem','sobre','isso','este','esta'];
        return array_values(array_unique(array_filter($parts, fn($t) => mb_strlen($t) > 2 && !in_array($t, $stop, true))));
    }

    private function jaccard(array $a, array $b): float
    {
        if (empty($a) || empty($b)) return 0.0;
        $i = count(array_intersect($a, $b));
        $u = count(array_unique(array_merge($a, $b)));
        return $u > 0 ? $i / $u : 0.0;
    }

    /**
     * Constrói HTML da Entity Page com 5 blocos:
     *   1. Sumário institucional
     *   2. Editais ativos / cobertura recente (top 10 posts mais novos)
     *   3. Histórico de cobertura (posts ordenados por ano)
     *   4. FAQ agregado
     *   5. Link oficial
     */
    private function montarHtml(array $cfg, string $sumario, array $posts, array $faq): string
    {
        $tipo = (string)($cfg['tipo'] ?? 'entity');
        $fullname = htmlspecialchars((string)$cfg['fullname']);
        $nome = htmlspecialchars((string)($cfg['nome'] ?? $cfg['fullname']));
        $rotulo = $tipo === 'concept' ? $fullname : $nome;
        $urlOficial = htmlspecialchars((string)($cfg['url_oficial'] ?? ''));

        // Override: se _sumario_html_pronto existe (atualizarFaqEPostsApenas), usa direto
        $sumarioHtml = !empty($cfg['_sumario_html_pronto'])
            ? (string)$cfg['_sumario_html_pronto']
            : $this->renderizarSumario($sumario, $cfg);

        $html = "";

        // BLOCO 1: Sumário
        $html .= $sumarioHtml . "\n\n";

        // BLOCO 2: cobertura recente
        $recentes = array_slice($posts, 0, 10);
        if (!empty($recentes)) {
            $tituloBloco = $tipo === 'concept'
                ? "Cobertura recente sobre <strong>{$rotulo}</strong>"
                : "Editais ativos e cobertura recente da <strong>{$rotulo}</strong>";
            $introBloco = $tipo === 'concept'
                ? "Notícias, guias e análises mais recentes sobre <strong>{$rotulo}</strong> cobertos editorialmente:"
                : "Acompanhe os editais, vestibulares e inscrições da <strong>{$rotulo}</strong> cobertos editorialmente neste portal:";
            $html .= "<h2>{$tituloBloco}</h2>\n";
            $html .= "<p>{$introBloco}</p>\n<ul>\n";
            foreach ($recentes as $p) {
                $titulo = htmlspecialchars(strip_tags(html_entity_decode((string)($p['title'] ?? ''), ENT_QUOTES, 'UTF-8')));
                $link = htmlspecialchars((string)($p['link'] ?? ''));
                $data = '';
                if (!empty($p['date'])) {
                    try {
                        $dt = new DateTime((string)$p['date']);
                        $data = ' <span style="color:#64748b;font-size:13px">(' . $dt->format('d/m/Y') . ')</span>';
                    } catch (Throwable $e) {}
                }
                $html .= "<li><a href=\"{$link}\" data-internal-link=\"1\">{$titulo}</a>{$data}</li>\n";
            }
            $html .= "</ul>\n\n";
        }

        // BLOCO 3: Histórico
        if (count($posts) > 10) {
            $antigos = array_slice($posts, 10);
            $html .= "<h2>Histórico de cobertura sobre <strong>{$rotulo}</strong></h2>\n";
            $html .= "<p>Notícias e análises anteriores sobre <strong>{$rotulo}</strong>:</p>\n<ul>\n";
            foreach ($antigos as $p) {
                $titulo = htmlspecialchars(strip_tags(html_entity_decode((string)($p['title'] ?? ''), ENT_QUOTES, 'UTF-8')));
                $link = htmlspecialchars((string)($p['link'] ?? ''));
                $html .= "<li><a href=\"{$link}\" data-internal-link=\"1\">{$titulo}</a></li>\n";
            }
            $html .= "</ul>\n\n";
        }

        // BLOCO 4: FAQ
        if (!empty($faq)) {
            $html .= "<h2>Perguntas frequentes sobre <strong>{$rotulo}</strong></h2>\n";
            foreach ($faq as $item) {
                $perg = htmlspecialchars($item['pergunta']);
                $resp = $item['resposta']; // já HTML
                $html .= "<details><summary>{$perg}</summary>{$resp}</details>\n";
            }
            $html .= "\n";
        }

        // BLOCO 5: só pra entity (concept não tem url_oficial)
        if ($tipo !== 'concept' && $urlOficial !== '') {
            $html .= "<h2>Onde consultar informações oficiais sobre a <strong>{$nome}</strong></h2>\n";
            $html .= "<p>Para informações canônicas e atualizadas sobre a <strong>{$fullname}</strong>, consulte sempre o portal oficial:</p>\n";
            $html .= "<p><a href=\"{$urlOficial}\" target=\"_blank\" rel=\"noopener nofollow\">{$urlOficial}</a></p>\n";
        }

        return $html;
    }

    /**
     * Renderiza o sumário do Sonnet preservando <strong> e aplicando pós-processo determinístico.
     *
     * Pipeline:
     *   1. Quebra em parágrafos por linha em branco
     *   2. Em cada parágrafo: escapa HTML mas re-permite <strong>...</strong>
     *   3. Aplica pós-processo: negrita 1ª ocorrência da sigla principal + fullname + aliases
     *      caso o LLM tenha esquecido (idempotente — não re-negrita).
     *   4. Envolve cada parágrafo em <p>
     */
    private function renderizarSumario(string $sumario, array $cfg): string
    {
        $sumario = trim($sumario);
        if ($sumario === '') return '';

        // Normaliza tags <strong> que podem vir maiúsculas ou com <b>
        $sumario = preg_replace('#<\s*b\s*>#i', '<strong>', $sumario) ?? $sumario;
        $sumario = preg_replace('#<\s*/\s*b\s*>#i', '</strong>', $sumario) ?? $sumario;
        $sumario = preg_replace('#<\s*strong\s*>#i', '<strong>', $sumario) ?? $sumario;
        $sumario = preg_replace('#<\s*/\s*strong\s*>#i', '</strong>', $sumario) ?? $sumario;

        // Markdown **bold** → <strong> (caso Sonnet caia no padrão markdown)
        $sumario = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $sumario) ?? $sumario;

        // Quebra em parágrafos
        $paragrafos = preg_split('/\n\s*\n/u', $sumario) ?: [$sumario];

        $out = [];
        foreach ($paragrafos as $idx => $p) {
            $p = trim($p);
            if ($p === '') continue;

            // Escapa tudo, depois re-permite <strong> e </strong>
            $escapado = htmlspecialchars($p, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $escapado = str_replace(
                ['&lt;strong&gt;', '&lt;/strong&gt;'],
                ['<strong>', '</strong>'],
                $escapado
            );

            // Quebras de linha simples viram <br>
            $escapado = nl2br($escapado, false);

            // Pós-processo determinístico só no 1º parágrafo (1ª ocorrência)
            if ($idx === 0) {
                $escapado = $this->garantirNegritoTermos($escapado, $cfg);
            }

            $out[] = '<p>' . $escapado . '</p>';
        }

        return implode("\n\n", $out);
    }

    /**
     * Negrita a 1ª ocorrência de termos-chave (sigla, fullname, aliases) caso ainda não estejam.
     * Idempotente: se o termo já está dentro de <strong>...</strong>, pula.
     */
    private function garantirNegritoTermos(string $html, array $cfg): string
    {
        $termos = [];
        if (!empty($cfg['nome'])) $termos[] = (string)$cfg['nome'];
        if (!empty($cfg['fullname'])) $termos[] = (string)$cfg['fullname'];
        foreach (($cfg['aliases'] ?? []) as $a) $termos[] = (string)$a;

        // Ordena por comprimento desc pra não quebrar substring (ex: "Senac Brasil" antes de "Senac")
        usort($termos, fn($a, $b) => mb_strlen($b) - mb_strlen($a));

        foreach ($termos as $termo) {
            $termo = trim($termo);
            if ($termo === '') continue;

            // Já está dentro de <strong>? skip
            if (preg_match('#<strong>[^<]*' . preg_quote($termo, '#') . '[^<]*</strong>#u', $html)) continue;

            // Negrita 1ª ocorrência fora de tag
            $pattern = '#(?<!<strong>)(?<![\w])(' . preg_quote($termo, '#') . ')(?![\w])(?!</strong>)#u';
            $html = preg_replace($pattern, '<strong>$1</strong>', $html, 1) ?? $html;
        }

        return $html;
    }

    public function getLog(): array
    {
        return $this->log;
    }
}
