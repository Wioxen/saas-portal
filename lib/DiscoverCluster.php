<?php
require_once __DIR__ . '/DiscoverInternalLinks.php';

/**
 * Cluster Generator — interliga posts do mesmo evento sazonal.
 *
 * Lógica:
 *  - Hub (primeiro post do cluster, score mais alto) recebe bloco "Leia também do cluster"
 *    apontando para TODOS os satélites
 *  - Cada satélite recebe bloco apontando para HUB + 2 sibling satélites (rotação)
 *
 * Benefício:
 *  - Google identifica topic authority (site domina o tema)
 *  - Tempo de sessão sobe (usuário consome múltiplos posts)
 *  - Posts se fortalecem mutuamente no Discover
 */
class DiscoverCluster
{
    private array $cfg;
    private DiscoverDb $db;
    private Wordpress $wp;

    public function __construct(array $cfg, DiscoverDb $db)
    {
        $this->cfg = $cfg;
        $this->db  = $db;
        $this->wp  = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
    }

    /** Lista clusters publicados do site atual — agrupados por evento_fonte. */
    public function listarClusters(string $site): array
    {
        $records = $this->db->all(['site' => $site]);
        $clusters = [];
        foreach ($records as $r) {
            $ev = $r['evento_fonte'] ?? '';
            if ($ev === '') continue;
            if (!isset($clusters[$ev])) {
                $clusters[$ev] = [
                    'nome'       => $ev,
                    'data_pico'  => $r['data_pico'] ?? null,
                    'total'      => 0,
                    'publicados' => 0,
                    'interligados' => 0,
                    'items'      => [],
                ];
            }
            $clusters[$ev]['total']++;
            if (in_array($r['status'] ?? '', ['publicado', 'suspeita'], true)) {
                $clusters[$ev]['publicados']++;
                if (!empty($r['cluster_interligado'])) $clusters[$ev]['interligados']++;
            }
            $clusters[$ev]['items'][] = $r;
        }
        // Ordena items por score (hub primeiro)
        foreach ($clusters as &$c) {
            usort($c['items'], fn($a, $b) => ($b['score_discover'] ?? 0) <=> ($a['score_discover'] ?? 0));
        }
        unset($c);
        return array_values($clusters);
    }

    /**
     * Interliga todos os posts publicados de um cluster.
     * Faz 3 coisas por post:
     *  1. Injeta inline backlinks no corpo (texto "+ Título" linkado no meio do conteúdo)
     *  2. Adiciona bloco "Veja também" com cluster + WP related posts (via buscarRelacionados)
     *  3. Links abrem na mesma janela (sem target=_blank)
     */
    public function interligar(string $site, string $eventoFonte): array
    {
        $records = $this->db->all(['site' => $site]);
        $items = array_values(array_filter($records, fn($r) =>
            ($r['evento_fonte'] ?? '') === $eventoFonte
            && in_array($r['status'] ?? '', ['publicado', 'suspeita'], true)
            && !empty($r['url_post'])
        ));

        if (count($items) < 2) {
            return ['ok' => false, 'erro' => 'Cluster precisa ter ≥ 2 posts publicados (tem ' . count($items) . ')'];
        }
        usort($items, fn($a, $b) => ($b['score_discover'] ?? 0) <=> ($a['score_discover'] ?? 0));

        // Resolve URLs públicas — prefere slug (funciona mesmo em draft, e será a URL final)
        $baseUrl = rtrim((string)($this->cfg['wp_url'] ?? ''), '/');
        $postsMeta = [];
        foreach ($items as $it) {
            if (!preg_match('/post=(\d+)/', $it['url_post'], $m)) continue;
            $postId = (int)$m[1];
            try {
                $post = $this->wp->getPost($postId);
                $slug = (string)($post['slug'] ?? '');
                $linkWp = (string)($post['link'] ?? '');

                // Prioridade: slug-based URL (permanente). Fallback: link do WP (pode ser ?p=ID em draft).
                $link = ($slug !== '' && $baseUrl !== '')
                    ? $baseUrl . '/' . $slug . '/'
                    : $linkWp;

                $titulo = $post['title']['rendered'] ?? $post['title']['raw'] ?? $it['titulo'] ?? $it['termo'];
                if ($link) {
                    $postsMeta[] = [
                        'db_id'   => (int)$it['id'],
                        'post_id' => $postId,
                        'link'    => $link,
                        'slug'    => $slug,
                        'titulo'  => html_entity_decode((string)$titulo, ENT_QUOTES),
                        'termo'   => (string)($it['termo'] ?? ''),
                        'score'   => (float)($it['score_discover'] ?? 0),
                    ];
                }
            } catch (Throwable $e) { /* pula */ }
        }
        if (count($postsMeta) < 2) {
            return ['ok' => false, 'erro' => 'Menos de 2 posts acessíveis no WP'];
        }

        $atualizados = 0;
        $erros = [];

        foreach ($postsMeta as $current) {
            $siblings = array_values(array_filter($postsMeta, fn($p) => $p['post_id'] !== $current['post_id']));

            try {
                if ($this->aplicarInterlinks($current, $siblings, $eventoFonte)) {
                    $papel = $current['post_id'] === $postsMeta[0]['post_id'] ? 'hub' : 'satelite';
                    $this->db->updateStatus($current['db_id'], 'publicado', [
                        'cluster_interligado' => true, 'cluster_papel' => $papel,
                    ]);
                    $atualizados++;
                }
            } catch (Throwable $e) {
                $erros[] = "#{$current['post_id']}: " . $e->getMessage();
            }
        }

        return [
            'ok'          => $atualizados > 0,
            'atualizados' => $atualizados,
            'total_posts' => count($postsMeta),
            'hub_post_id' => $postsMeta[0]['post_id'],
            'erros'       => $erros,
        ];
    }

    /**
     * Aplica os 3 tipos de interlink em um post:
     *  - Inline scattered (2-3 links "+ Título" pelo corpo)
     *  - Bloco final "Veja também" com cluster + WP related
     *  - Links abrem na mesma aba (sem target=_blank)
     */
    private function aplicarInterlinks(array $current, array $siblings, string $eventoFonte): bool
    {
        $post = $this->wp->getPost($current['post_id']);
        $content = $post['content']['raw'] ?? $post['content']['rendered'] ?? '';
        if ($content === '') return false;

        // 0. Limpeza idempotente — remove blocos/inlines antigos antes de reinserir
        $content = preg_replace('/\s*<!-- cluster-interlink -->[\s\S]*?<!-- \/cluster-interlink -->\s*/', "\n", $content) ?? $content;
        $content = preg_replace('/<!-- cluster-inline -->[\s\S]*?<!-- \/cluster-inline -->/', '', $content) ?? $content;

        // 1. DIVISÃO SEM REPETIÇÃO — cada sibling aparece em APENAS UM lugar
        //    Inline (até 3): os primeiros 3 siblings por score
        //    Resto siblings: os que SOBRARAM (vão pro bloco final)
        $MAX_INLINE = 3;
        $inlineSiblings = array_slice($siblings, 0, $MAX_INLINE);
        $restoSiblings  = array_slice($siblings, $MAX_INLINE); // EXCLUSIVAMENTE os não-usados inline
        $idsInline      = array_map(fn($s) => $s['post_id'], $inlineSiblings);
        $idsResto       = array_map(fn($s) => $s['post_id'], $restoSiblings);

        // 2. Insere inline backlinks distribuídos pelos H2 do corpo
        $content = $this->inserirInlineBacklinks($content, $inlineSiblings);

        // 3. Busca WP related posts por keyword (exclui o próprio post + TODOS do cluster)
        $wpRelated = [];
        try {
            $keyword = $this->keywordParaBusca($current['termo']);
            if ($keyword !== '') {
                $excluir = array_merge([$current['post_id']], $idsInline, $idsResto);
                $res = $this->wp->buscarRelacionados($keyword, 10, $current['post_id']);
                foreach ($res as $r) {
                    $rid    = (int)($r['id'] ?? 0);
                    $titulo = html_entity_decode((string)($r['title'] ?? $r['title']['rendered'] ?? ''));
                    $rlink  = (string)($r['link'] ?? '');
                    if ($rid > 0 && in_array($rid, $excluir, true)) continue;
                    if ($titulo === '' || $rlink === '') continue;
                    $wpRelated[] = ['titulo' => $titulo, 'link' => $rlink];
                }
            }
        } catch (Throwable $e) { /* WP sem posts ou sem match — ignora */ }

        // 4. Bloco "Veja também" — apenas siblings NÃO usados inline + WP related (dedupe por URL)
        $todosLinks = [];
        foreach ($restoSiblings as $s) {
            $todosLinks[$s['link']] = ['titulo' => $s['titulo'], 'link' => $s['link']];
        }
        foreach ($wpRelated as $r) {
            if (isset($todosLinks[$r['link']])) continue;
            $todosLinks[$r['link']] = $r;
        }
        $todosLinks = array_slice(array_values($todosLinks), 0, 8);

        // Só insere o bloco se houver pelo menos 2 links (senão poluição visual)
        if (count($todosLinks) >= 2) {
            $bloco = $this->montarBlocoVejaTambem('Leia também', $todosLinks);
            $content = $this->inserirBlocoNoFim($content, $bloco);

            // Também insere um bloco-MEIO (após H2 #3 ou #4) — prática jornalística forte.
            // Usa subset de 2-3 links diferentes pra não duplicar literalmente.
            $linksMeio = array_slice($todosLinks, 0, 3);
            if (count($linksMeio) >= 2) {
                $blocoMeio = $this->montarBlocoVejaTambem('Leia também', $linksMeio, 'meio');
                $content = $this->inserirBlocoNoMeio($content, $blocoMeio);
            }
        }

        // 5. ItemList schema (RelatedTopics) — ajuda Google a entender o cluster
        $schemaLinks = [];
        foreach ($inlineSiblings as $s) $schemaLinks[$s['link']] = ['titulo' => $s['titulo'], 'link' => $s['link']];
        foreach ($restoSiblings as $s) $schemaLinks[$s['link']] = ['titulo' => $s['titulo'], 'link' => $s['link']];
        $schemaLinks = array_values($schemaLinks);
        if (count($schemaLinks) >= 2) {
            $schema = $this->montarItemListSchema('Tópicos relacionados — ' . $eventoFonte, $schemaLinks);
            // Remove schema anterior (idempotente)
            $content = preg_replace('/\s*<!-- cluster-schema -->[\s\S]*?<!-- \/cluster-schema -->\s*/', '', $content) ?? $content;
            $content .= "\n<!-- cluster-schema -->\n" . $schema . "\n<!-- /cluster-schema -->\n";
        }

        // 6. BACKLINKS CONTEXTUAIS EXTRAS — posts existentes no WP diferentes do cluster
        //    Injeta até 3 links no corpo do texto baseados nos principais termos do artigo.
        //    Exclui posts já linkados (cluster siblings + WP related do bloco Veja também).
        $excluirIds = [];
        foreach ($inlineSiblings as $s) $excluirIds[] = $s['post_id'];
        foreach ($restoSiblings as $s)  $excluirIds[] = $s['post_id'];
        foreach ($wpRelated as $r) {
            // WP related pode não ter post_id; extrai do link se puder
            if (preg_match('/[\?&]p=(\d+)/', $r['link'] ?? '', $mp)) $excluirIds[] = (int)$mp[1];
        }

        try {
            // Remove cluster-inline ANTES de extrair termos (senão pega títulos dos siblings como termos)
            $htmlParaExtrair = preg_replace('/<!-- cluster-inline -->[\s\S]*?<!-- \/cluster-inline -->/', '', $content) ?? $content;
            // Detecta cluster editorial pra expansão semântica dos termos de busca interlink
            $clusterDet = null;
            try {
                require_once __DIR__ . '/DiscoverClusterMatcher.php';
                $clusterDet = DiscoverClusterMatcher::detectar(['termo' => $current['termo'] ?? '', 'categoria_ids' => $current['categoria_ids'] ?? []]);
            } catch (Throwable $e) {}
            $trendMeta = [
                'termo' => $current['termo'] ?? '',
                'relacionados' => [],
                'cluster_key' => $clusterDet['key'] ?? null,
            ];
            $termosPrincipais = DiscoverInternalLinks::extrairTermos($htmlParaExtrair, $trendMeta);

            if (!empty($termosPrincipais)) {
                $linker = new DiscoverInternalLinks($this->wp, 3);
                // Keyword-âncora: termo-seed do post atual. Candidatos sem overlap com ela são rejeitados
                // (evita NF-e em artigo de ENEM por match lexical tangencial).
                $linker->setKeywordAncora((string)($current['termo'] ?? ''));
                $resLink = $linker->injetar($content, $termosPrincipais, $excluirIds, $current['post_id']);
                if ($resLink['aplicados'] > 0) {
                    $content = $resLink['html'];
                }
            }
        } catch (Throwable $e) { /* não bloqueia — apenas pula extras */ }

        $this->wp->atualizarPost($current['post_id'], ['content' => $content]);
        return true;
    }

    /**
     * Insere linhas inline "+ Título" após os primeiros H2 (ou H3) do conteúdo.
     * Uma inserção por sibling, rotativa pra espalhar pelo texto.
     */
    private function inserirInlineBacklinks(string $content, array $siblings): string
    {
        if (empty($siblings)) return $content;

        // Posiciona após </h2> e </h3> — pega todos os matches primeiro
        preg_match_all('/<\/h[23]>/i', $content, $matches, PREG_OFFSET_CAPTURE);
        if (empty($matches[0])) return $content;

        $posicoes = array_column($matches[0], 1);
        // Pula o primeiro H2 (provavelmente logo após intro) pra distribuir melhor
        $posicoes = array_slice($posicoes, 1);
        if (empty($posicoes)) $posicoes = array_column($matches[0], 1);

        // Passa pelos siblings; pra cada um pega uma posição diferente
        $offsetGlobal = 0; // ajusta porque cada inserção aumenta o tamanho
        $inseridos = 0;
        foreach ($siblings as $i => $sib) {
            if (!isset($posicoes[$i])) break;
            $pos = $posicoes[$i] + strlen('</h2>') + $offsetGlobal;
            $inlineHtml = "\n<!-- cluster-inline --><p style='margin:10px 0 14px;padding:8px 12px;background:#f0f9ff;border-left:3px solid #0369a1;border-radius:4px'><strong>+</strong> <a href='" . htmlspecialchars($sib['link']) . "'>" . htmlspecialchars($sib['titulo']) . "</a></p><!-- /cluster-inline -->\n";
            $content = substr($content, 0, $pos) . $inlineHtml . substr($content, $pos);
            $offsetGlobal += strlen($inlineHtml);
            $inseridos++;
        }
        return $content;
    }

    /** Extrai 1ª keyword útil do termo (primeira palavra relevante, descartando artigos/preposições). */
    private function keywordParaBusca(string $termo): string
    {
        $termo = trim($termo);
        if ($termo === '') return '';
        // Remove prefixos comuns de listas/perguntas
        $termo = preg_replace('/^\d+\s*(frases|mensagens|dicas|ideias)\s+(para|de)\s+/iu', '', $termo);
        $stop = ['o','a','os','as','de','do','da','dos','das','em','no','na','nos','nas','para','por','com','sem','que','e','ou'];
        $palavras = array_filter(explode(' ', mb_strtolower($termo, 'UTF-8')),
                                 fn($p) => !in_array($p, $stop) && mb_strlen($p) >= 3);
        return implode(' ', array_slice(array_values($palavras), 0, 3));
    }

    private function montarBlocoVejaTambem(string $titulo, array $links, string $posicao = 'fim'): string
    {
        if (empty($links)) return '';
        // Marker diferente pra meio vs fim — permite dedupe e CSS distinto
        $marker = $posicao === 'meio' ? 'cluster-interlink-meio' : 'cluster-interlink';
        $html = "\n<!-- {$marker} -->\n"
              . "<div class='cluster-box' style='background:#f0f9ff;border-left:4px solid #0369a1;padding:16px 20px;margin:30px 0;border-radius:8px'>"
              . "<strong style='font-size:1.1em;color:#0c4a6e;display:block;margin-bottom:10px'>" . htmlspecialchars($titulo) . "</strong>"
              . "<ul style='margin:0;padding-left:18px;list-style:none'>";
        foreach ($links as $l) {
            if (empty($l['link']) || empty($l['titulo'])) continue;
            $html .= "<li style='margin-bottom:6px;padding-left:4px'>"
                   . "<strong style='color:#0369a1'>+</strong> "
                   . "<a href='" . htmlspecialchars($l['link']) . "'>"
                   . htmlspecialchars($l['titulo']) . "</a></li>";
        }
        $html .= "</ul></div>\n<!-- /{$marker} -->\n";
        return $html;
    }

    /**
     * Insere bloco "Leia também" no MEIO do artigo — após H2 #3 (ou #2 se houver menos H2s).
     * Prática jornalística: quebra visual + scroll retention.
     * Idempotente: se já existe marker cluster-interlink-meio, não duplica.
     */
    private function inserirBlocoNoMeio(string $content, string $bloco): string
    {
        if ($bloco === '' || strpos($content, '<!-- cluster-interlink-meio -->') !== false) {
            return $content; // já tem
        }
        // Acha todos os H2s
        if (!preg_match_all('/<h2\b[^>]*>/i', $content, $mm, PREG_OFFSET_CAPTURE)) {
            return $content . $bloco; // sem H2: joga no fim
        }
        $total = count($mm[0]);
        // Inserir ANTES do H2 #3 — se só tem 2 H2, antes do 2º
        $idxAlvo = min(2, $total - 1); // zero-based
        if ($idxAlvo < 1) return $content . $bloco; // só 1 H2: não faz sentido bloco no meio
        $pos = $mm[0][$idxAlvo][1];
        return substr($content, 0, $pos) . $bloco . substr($content, $pos);
    }

    /** Gera schema.org ItemList com os posts do cluster (RelatedTopics fidedigno). */
    private function montarItemListSchema(string $nome, array $links): string
    {
        $items = [];
        foreach ($links as $i => $l) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'url'      => $l['link'],
                'name'     => $l['titulo'],
            ];
        }
        $schema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => $nome,
            'itemListOrder'   => 'https://schema.org/ItemListOrderAscending',
            'numberOfItems'   => count($items),
            'itemListElement' => $items,
        ];
        return '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }

    private function inserirBlocoNoFim(string $content, string $bloco): string
    {
        if ($bloco === '') return $content;

        // Remove "Leia também" antigo do Maquina (div.leia-tambem + marker HTML comment)
        $content = preg_replace('/\s*<!-- leia-tambem -->[\s\S]*?<!-- \/leia-tambem -->\s*/', "\n", $content) ?? $content;
        // Remove blocos "Leia também" sem marker (de versões antigas do Maquina com cc-card)
        $content = preg_replace('/<h2[^>]*>\s*Leia\s+tamb[ée]m\s*<\/h2>[\s\S]*?(?=<h2|<!-- |$)/iu', '', $content, 1) ?? $content;

        $padroes = [
            '/(<h2[^>]*>[\s]*(?:Perguntas frequentes|FAQ|F\.A\.Q)[\s]*<\/h2>)/i',
            '/(<h2[^>]*>[\s]*Conclus(?:ã|a)o[\s]*<\/h2>)/i',
        ];
        foreach ($padroes as $p) {
            if (preg_match($p, $content)) {
                return preg_replace($p, $bloco . '$1', $content, 1);
            }
        }
        return $content . $bloco;
    }
}
