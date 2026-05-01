<?php
/**
 * DiscoverHubPages — gera/atualiza pillar pages por cluster pra topical authority.
 *
 * Por que: Discover prioriza sites com AUTORIDADE TÓPICA. 50 posts soltos sobre Enem ≠
 * 50 posts + 1 hub que linka todos como "Tudo sobre Enem 2026". Hub sinaliza ao Google
 * que esse site é referência no nicho → eleva ranqueamento de TODOS os posts do cluster.
 *
 * Funciona como:
 *   - Pra cada (site, cluster) com ≥5 posts publicados, gera/atualiza uma página WP
 *   - URL fixo: /hub-{cluster_slug} (ex: /hub-educacao, /hub-financas)
 *   - Conteúdo: H1 + intro + posts cronológicos com link
 *   - Schema CollectionPage + ItemList (cada post vira ListItem)
 *   - Idempotente: detecta hub existente via slug, atualiza vs cria
 *   - Re-renderiza a cada execução (caller decide cadência via cron diário)
 *
 * Uso típico (em scripts/gerar_hubs.php cron diário):
 *   $ret = DiscoverHubPages::gerarHub('educacao', 'cursosenac', $cfgSite, $db);
 *   if ($ret['ok']) echo "Hub atualizado: {$ret['page_url']} ({$ret['posts_count']} posts)";
 */

require_once __DIR__ . '/DiscoverDb.php';
require_once __DIR__ . '/Wordpress.php';

class DiscoverHubPages
{
    /** Mínimo de posts pra gerar hub. Abaixo disso o hub fica fraco e é skipado. */
    public const MIN_POSTS_HUB = 5;

    /** Limite máx de posts listados na hub (mais que isso vira navegação ruim). */
    public const MAX_POSTS_HUB = 80;

    /**
     * Gera/atualiza hub page pra um (cluster, site).
     *
     * @param string $clusterKey Ex: 'educacao', 'noticias_info_critica'
     * @param string $siteSlug   Ex: 'cursosenac'
     * @param array $cfg         Cfg do site (wp_url, wp_user, wp_app_password, persona, site_name)
     * @param DiscoverDb $db
     * @param bool $dryRun       Se true, simula sem chamar WP
     * @return array {ok, page_id?, page_url?, posts_count, action: 'created|updated|skipped', motivo?}
     */
    public static function gerarHub(string $clusterKey, string $siteSlug, array $cfg, DiscoverDb $db, bool $dryRun = false): array
    {
        // 1. Lista posts publicados desse cluster nesse site
        $candidatos = self::filtrarPostsCluster($db, $siteSlug, $clusterKey);
        if (count($candidatos) < self::MIN_POSTS_HUB) {
            return ['ok' => false, 'action' => 'skipped', 'motivo' => 'min_posts_nao_atingido', 'posts_count' => count($candidatos), 'minimo' => self::MIN_POSTS_HUB];
        }

        // 2. Resolve URLs públicas via WP
        $wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        $posts = self::enriquecerComUrlsWp($candidatos, $wp);
        if (count($posts) < self::MIN_POSTS_HUB) {
            return ['ok' => false, 'action' => 'skipped', 'motivo' => 'urls_publicas_insuficientes', 'posts_count' => count($posts)];
        }

        // 3. Constrói HTML do hub + título + meta + slug
        $hubData = self::montarHubHtml($posts, $clusterKey, $cfg);
        $slug = $hubData['slug'];

        if ($dryRun) {
            return [
                'ok' => true,
                'action' => 'dry-run',
                'posts_count' => count($posts),
                'titulo' => $hubData['titulo'],
                'slug'   => $slug,
                'chars_html' => strlen($hubData['html']),
                'page_url' => rtrim((string)$cfg['wp_url'], '/') . '/' . $slug . '/',
            ];
        }

        // 4. Verifica se hub existe (via slug) — se sim atualiza, senão cria
        $existing = self::buscarPaginaPorSlug($wp, $slug);
        if ($existing) {
            $payload = [
                'title'   => $hubData['titulo'],
                'content' => $hubData['html'],
                'meta'    => [
                    'rank_math_title'         => $hubData['meta_title'],
                    'rank_math_description'   => $hubData['meta_description'],
                    'rank_math_focus_keyword' => $hubData['focus_keyword'],
                ],
            ];
            try {
                $resp = self::atualizarPagina($wp, (int)$existing['id'], $payload);
                return [
                    'ok' => true,
                    'action' => 'updated',
                    'page_id' => (int)$existing['id'],
                    'page_url' => (string)($existing['link'] ?? rtrim((string)$cfg['wp_url'], '/') . '/' . $slug . '/'),
                    'posts_count' => count($posts),
                ];
            } catch (Throwable $e) {
                return ['ok' => false, 'action' => 'update_failed', 'erro' => $e->getMessage()];
            }
        }

        // 5. Cria nova página
        $payload = [
            'title'   => $hubData['titulo'],
            'content' => $hubData['html'],
            'slug'    => $slug,
            'status'  => 'publish',
            'meta'    => [
                'rank_math_title'         => $hubData['meta_title'],
                'rank_math_description'   => $hubData['meta_description'],
                'rank_math_focus_keyword' => $hubData['focus_keyword'],
            ],
        ];
        try {
            $resp = $wp->criarPagina($payload);
            return [
                'ok' => true,
                'action' => 'created',
                'page_id' => (int)($resp['id'] ?? 0),
                'page_url' => (string)($resp['link'] ?? rtrim((string)$cfg['wp_url'], '/') . '/' . $slug . '/'),
                'posts_count' => count($posts),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'action' => 'create_failed', 'erro' => $e->getMessage()];
        }
    }

    /**
     * Lista todos os clusters elegíveis (≥ MIN_POSTS_HUB) num site.
     * Útil pra cron iterativo.
     */
    public static function clustersElegiveis(string $siteSlug, DiscoverDb $db): array
    {
        $publicados = $db->all(['site' => $siteSlug, 'status' => 'publicado']);
        $contagem = [];
        foreach ($publicados as $p) {
            $key = (string)($p['cluster_detect']['key'] ?? '');
            if ($key === '') continue;
            $contagem[$key] = ($contagem[$key] ?? 0) + 1;
        }
        $elegiveis = [];
        foreach ($contagem as $cluster => $n) {
            if ($n >= self::MIN_POSTS_HUB) $elegiveis[$cluster] = $n;
        }
        arsort($elegiveis);
        return $elegiveis;
    }

    // ─────────── INTERNOS ───────────

    /** Filtra posts publicados de um cluster específico. */
    private static function filtrarPostsCluster(DiscoverDb $db, string $siteSlug, string $clusterKey): array
    {
        $todos = $db->all(['site' => $siteSlug, 'status' => 'publicado']);
        $filtrados = [];
        foreach ($todos as $p) {
            $key = (string)($p['cluster_detect']['key'] ?? '');
            if ($key !== $clusterKey) continue;
            if (empty($p['url_post']) || empty($p['titulo'])) continue;
            $filtrados[] = $p;
        }
        // Ordena por data publicação desc (mais recentes primeiro)
        usort($filtrados, fn($a, $b) => strcmp((string)($b['publicado_em'] ?? ''), (string)($a['publicado_em'] ?? '')));
        if (count($filtrados) > self::MAX_POSTS_HUB) {
            $filtrados = array_slice($filtrados, 0, self::MAX_POSTS_HUB);
        }
        return $filtrados;
    }

    /** Resolve URL pública de cada post via wp.getPost. Cache em memória. */
    private static function enriquecerComUrlsWp(array $candidatos, Wordpress $wp): array
    {
        $out = [];
        foreach ($candidatos as $p) {
            if (!preg_match('/post=(\d+)/', (string)($p['url_post'] ?? ''), $m)) continue;
            $postId = (int)$m[1];
            try {
                $wpPost = $wp->getPost($postId);
                $linkPub = (string)($wpPost['link'] ?? '');
                $statusWp = (string)($wpPost['status'] ?? '');
                if ($linkPub === '' || $statusWp !== 'publish') continue;
                $out[] = [
                    'id'           => $postId,
                    'titulo'       => trim(strip_tags(html_entity_decode((string)($wpPost['title']['rendered'] ?? $p['titulo'])))),
                    'link'         => $linkPub,
                    'data'         => (string)($wpPost['date'] ?? $p['publicado_em'] ?? ''),
                    'excerpt'      => trim(strip_tags(html_entity_decode((string)($wpPost['excerpt']['rendered'] ?? '')))),
                    'termo_orig'   => (string)($p['termo'] ?? ''),
                ];
            } catch (Throwable $e) { continue; }
        }
        return $out;
    }

    /** Constrói HTML do hub. Retorna {titulo, html, slug, meta_*}. */
    private static function montarHubHtml(array $posts, string $clusterKey, array $cfg): array
    {
        $clusterNomes = self::clusterParaNomeBonito();
        $clusterNome = $clusterNomes[$clusterKey]['nome'] ?? ucfirst(str_replace('_', ' ', $clusterKey));
        $clusterIntro = $clusterNomes[$clusterKey]['intro']
            ?? 'Conteúdos verificados sobre o tema, atualizados pela equipe editorial.';
        $ano = date('Y');

        $titulo = "Tudo sobre {$clusterNome} {$ano}";
        $slug   = 'hub-' . self::slugify($clusterKey);
        $siteName = (string)($cfg['site_name'] ?? $cfg['_site_name'] ?? '');
        $totalPosts = count($posts);
        $atualizadoEm = date('d/m/Y');

        // Intro humanizada (declarativa, não-markup-pesada)
        $introHtml = "<p><strong>{$totalPosts} guias verificados</strong> sobre {$clusterNome} reunidos aqui — todos com fontes oficiais e atualizados em {$atualizadoEm}.</p>"
                   . "<p>{$clusterIntro}</p>";

        // Lista de posts cronológica
        $itemsHtml = '';
        $itemListSchema = [];
        foreach ($posts as $i => $p) {
            $tituloEsc = htmlspecialchars($p['titulo'], ENT_QUOTES, 'UTF-8');
            $linkEsc   = htmlspecialchars($p['link'], ENT_QUOTES, 'UTF-8');
            $dataFmt   = self::formatarData($p['data']);
            $excerpt   = $p['excerpt'] !== '' ? mb_substr(self::limparTexto($p['excerpt']), 0, 140) : '';
            $excerptEsc = htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8');

            $itemsHtml .= "<li class='hub-item'>"
                       .   "<a href='{$linkEsc}' class='hub-link'>{$tituloEsc}</a>"
                       .   ($dataFmt ? " <span class='hub-date'>· {$dataFmt}</span>" : '')
                       .   ($excerptEsc !== '' ? "<p class='hub-excerpt'>{$excerptEsc}</p>" : '')
                       . "</li>\n";

            $itemListSchema[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'url'      => $p['link'],
                'name'     => $p['titulo'],
            ];
        }

        // CSS inline simples (aspas simples por convenção CLAUDE.md)
        $css = "<style>"
             . ".hub-list{list-style:none;padding:0;margin:24px 0}"
             . ".hub-item{padding:14px 0;border-bottom:1px solid #e5e7eb}"
             . ".hub-link{font-weight:600;color:#0f172a;font-size:17px;text-decoration:none}"
             . ".hub-link:hover{text-decoration:underline}"
             . ".hub-date{font-size:13px;color:#64748b;margin-left:6px}"
             . ".hub-excerpt{margin:6px 0 0;color:#475569;font-size:14px;line-height:1.5}"
             . "</style>";

        // Schema CollectionPage
        $schemaHub = [
            '@context' => 'https://schema.org',
            '@type'    => 'CollectionPage',
            'name'     => $titulo,
            'description' => "Reúne {$totalPosts} artigos sobre {$clusterNome} — fontes oficiais, atualizado em {$atualizadoEm}.",
            'mainEntity' => [
                '@type'           => 'ItemList',
                'numberOfItems'   => $totalPosts,
                'itemListElement' => $itemListSchema,
            ],
        ];
        $schemaJson = json_encode($schemaHub, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $html = $css
              . "<div class='hub-container'>"
              . $introHtml
              . "<ul class='hub-list'>\n" . $itemsHtml . "</ul>"
              . "<p class='hub-footer' style='font-size:13px;color:#64748b;margin-top:24px'>Atualizado periodicamente conforme novos guias são publicados.</p>"
              . "</div>"
              . "\n<script type='application/ld+json' data-hub-schema='1'>" . $schemaJson . "</script>";

        // Meta SEO
        $metaDescription = "Reúne {$totalPosts} guias sobre {$clusterNome} no {$siteName}. Conteúdos com fontes oficiais, atualizados em {$atualizadoEm}.";
        $focusKeyword = strtolower($clusterNome);

        return [
            'titulo'       => $titulo,
            'html'         => $html,
            'slug'         => $slug,
            'meta_title'   => mb_substr($titulo . ' · ' . $siteName, 0, 70),
            'meta_description' => mb_substr($metaDescription, 0, 160),
            'focus_keyword' => $focusKeyword,
        ];
    }

    /** Mapa cluster_key → {nome bonito, intro padrão pra hub}. */
    public static function clusterParaNomeBonito(): array
    {
        return [
            'educacao' => [
                'nome' => 'Educação e Cursos',
                'intro' => 'Aqui você encontra editais, prazos de inscrição, calendários de prova, dicas de estudo e tudo que precisa pra avançar nos estudos. Cada guia é checado contra as fontes oficiais (Inep, MEC, instituições de ensino).',
            ],
            'noticias_info_critica' => [
                'nome' => 'Notícias e Serviços Públicos',
                'intro' => 'Cobertura de benefícios sociais, calendários de pagamento, mudanças em programas do governo e direitos do cidadão. Cada guia explica quem tem direito, como solicitar e os prazos críticos.',
            ],
            'negocios_financas' => [
                'nome' => 'Finanças e Benefícios',
                'intro' => 'INSS, FGTS, PIS/Pasep, restituição de imposto, financiamentos, calendários de pagamento. Guias práticos com passo-a-passo de cada processo, prazos e dicas pra evitar problemas comuns.',
            ],
            'tecnologia' => [
                'nome' => 'Tecnologia',
                'intro' => 'Reviews, comparativos e guias sobre celulares, notebooks, eletrônicos e tendências tech. Tudo testado, sem hype — recomendações baseadas em uso real.',
            ],
            'lifestyle_consumo' => [
                'nome' => 'Lifestyle e Consumo',
                'intro' => 'Presentes, ofertas, ideias pra datas comemorativas, comparativos de produtos. Curadoria pra você acertar na compra sem cair em armadilhas de marketing.',
            ],
            'comidas_bebidas' => [
                'nome' => 'Comida e Bebida',
                'intro' => 'Receitas, dicas de cozinha, comparativos de produtos alimentícios. Conteúdo pra quem cozinha em casa.',
            ],
            'viagem_transporte' => [
                'nome' => 'Viagem e Transporte',
                'intro' => 'Passagens, hospedagem, roteiros, dicas pra economizar em viagens. Cobertura de companhias aéreas, hospedagem e atrações.',
            ],
            'automoveis' => [
                'nome' => 'Automóveis',
                'intro' => 'Recalls, consumo, comparativos, dicas de manutenção. Conteúdo prático pra quem dirige no Brasil.',
            ],
            'saude_bem_estar' => [
                'nome' => 'Saúde e Bem-estar',
                'intro' => 'Informações verificadas sobre saúde, bem-estar, benefícios públicos da área da saúde. Sempre cite a fonte oficial.',
            ],
            'esportes' => [
                'nome' => 'Esportes',
                'intro' => 'Cobertura de futebol brasileiro, Fórmula 1, escalação de jogos, análise tática, transmissões ao vivo. Sem fofoca, sem aposta — foco no jogo.',
            ],
            'entretenimento' => [
                'nome' => 'Entretenimento',
                'intro' => 'Filmes, séries, cultura, lançamentos. Conteúdo pra quem acompanha o cenário cultural.',
            ],
            'entretenimento_cultura' => [
                'nome' => 'Entretenimento e Cultura',
                'intro' => 'Filmes, séries, cultura, lançamentos. Conteúdo pra quem acompanha o cenário cultural.',
            ],
            'curiosidades_geral' => [
                'nome' => 'Curiosidades',
                'intro' => 'Pautas que geram curiosidade — cobertas com fontes verificáveis.',
            ],
        ];
    }

    private static function buscarPaginaPorSlug(Wordpress $wp, string $slug): ?array
    {
        try {
            $resp = self::wpGetRaw($wp, '/pages?slug=' . urlencode($slug) . '&status=publish,draft');
            if (is_array($resp) && !empty($resp[0]['id'])) return $resp[0];
        } catch (Throwable $e) {}
        return null;
    }

    private static function atualizarPagina(Wordpress $wp, int $pageId, array $payload): array
    {
        // Wordpress::request é privado — precisamos de wrapper público OU usar curl direto
        return self::wpRequest($wp, 'POST', "/pages/{$pageId}", $payload);
    }

    private static function wpGetRaw(Wordpress $wp, string $path): array
    {
        return self::wpRequest($wp, 'GET', $path, null);
    }

    /** Helper: chama REST WP via curl direto (Wordpress::request é privado). */
    private static function wpRequest(Wordpress $wp, string $method, string $path, ?array $payload): array
    {
        // Reusa cfg via reflection se existir, senão constrói URL
        $rc = new ReflectionClass($wp);
        $baseProp = $rc->getProperty('base'); $baseProp->setAccessible(true);
        $authProp = $rc->getProperty('auth'); $authProp->setAccessible(true);
        $base = $baseProp->getValue($wp);
        $auth = $authProp->getValue($wp);

        $ch = curl_init($base . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$resp, true);
        if ($code >= 400) throw new RuntimeException("WP HTTP {$code}: " . substr((string)$resp, 0, 200));
        return is_array($data) ? $data : [];
    }

    private static function formatarData(string $iso): string
    {
        if ($iso === '') return '';
        $ts = strtotime($iso);
        if ($ts === false) return '';
        return date('d/m/Y', $ts);
    }

    private static function limparTexto(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    private static function slugify(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        return trim($s, '-');
    }
}
