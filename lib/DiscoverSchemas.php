<?php
/**
 * DiscoverSchemas — gera JSON-LD Schema.org expandido pra rich snippets do Google.
 *
 * Schemas suportados:
 *   - NewsArticle        (sempre — substitui Article genérico, sinaliza notícia)
 *   - BreadcrumbList     (sempre — Home > Categoria > Post)
 *   - Person             (sempre — autor, com knowsAbout = especialidades)
 *   - Course             (cluster=educacao + termo elegível)
 *   - Event              (origem=sazonal:* + data definida no Calendário)
 *   - ItemList + Product (ProductRanker injetou tabela)
 *
 * Estratégia:
 *   - Tudo encapsulado em UM `<script type="application/ld+json">` com `@graph`
 *     (padrão Schema.org pra múltiplos schemas — evita N scripts separados)
 *   - Idempotente: se já houver `<script ld-json="rich-schemas">`, não duplica
 *   - Falha-silenciosa: schema malformado loga mas não bloqueia post
 *
 * Validação manual recomendada:
 *   https://validator.schema.org/
 *   https://search.google.com/test/rich-results
 */

class DiscoverSchemas
{
    /**
     * Marker pra detectar idempotência.
     */
    private const MARKER = 'data-rich-schemas="1"';

    /**
     * Gera bloco <script ld-json> com TODOS os schemas aplicáveis.
     *
     * @param array $meta  ['titulo' => ..., 'url' => ...]
     * @param array $trend trend completo do DiscoverDb (cluster_detect, origem, pain, etc)
     * @param array $cfg   cfg do site (persona, site_name, wp_url)
     * @return string '<script type="application/ld+json" data-rich-schemas="1">...</script>' ou ''
     */
    public static function gerar(array $meta, array $trend, array $cfg): string
    {
        $url    = (string)($meta['url'] ?? '');
        $titulo = (string)($meta['titulo'] ?? '');
        if ($url === '' || $titulo === '') return '';

        $graph = [];

        // Sempre
        $news = self::newsArticle($meta, $trend, $cfg);
        if ($news) $graph[] = $news;

        $breadcrumb = self::breadcrumbList($meta, $trend, $cfg);
        if ($breadcrumb) $graph[] = $breadcrumb;

        $author = self::personAutor($trend, $cfg);
        if ($author) $graph[] = $author;

        // Organization próprio por site — sinaliza identidade institucional distinta
        // (mitigação anti-PBN: cada site é "outra editora", não cluster artificial)
        $org = self::organization($cfg);
        if ($org) $graph[] = $org;

        // Condicional
        $course = self::course($meta, $trend, $cfg);
        if ($course) $graph[] = $course;

        $event = self::event($meta, $trend, $cfg);
        if ($event) $graph[] = $event;

        // SportsEvent (esportes ao vivo) — só sai se DiscoverSportsEvent extraiu dados
        // válidos do LLM (home_team, away_team, kickoff em janela sensata).
        if (!empty($meta['sports_event']) && is_array($meta['sports_event'])) {
            require_once __DIR__ . '/DiscoverSportsEvent.php';
            $sportsEvent = DiscoverSportsEvent::paraSchema($meta['sports_event'], $meta);
            if ($sportsEvent) $graph[] = $sportsEvent;
        }

        // ItemList só se ProductRanker injetou (detectado por marker no HTML — caller passa via meta)
        if (!empty($meta['ranker_produtos']) && is_array($meta['ranker_produtos'])) {
            $itemList = self::itemListProdutos($meta['ranker_produtos'], $url);
            if ($itemList) $graph[] = $itemList;
        }

        if (empty($graph)) return '';

        $payload = [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return '';

        return "\n<script type=\"application/ld+json\" " . self::MARKER . ">\n" . $json . "\n</script>\n";
    }

    /**
     * Já existe schema rich no HTML? (idempotência)
     */
    public static function jaInjetado(string $html): bool
    {
        return strpos($html, self::MARKER) !== false;
    }

    // ─────────── SCHEMAS INDIVIDUAIS ───────────

    private static function newsArticle(array $meta, array $trend, array $cfg): ?array
    {
        $url = (string)($meta['url'] ?? '');
        $titulo = (string)($meta['titulo'] ?? '');
        $datePub = self::dataPublicacao($trend);
        $dateMod = self::dataAtualizacao($trend);
        $imageUrl = self::imageUrl($trend, $cfg);
        $autorNome = (string)($cfg['persona']['autor'] ?? 'Equipe Editorial');
        $autorUrl  = self::autorUrl($cfg);
        $siteName  = (string)($cfg['site_name'] ?? $cfg['_site_name'] ?? 'Site');
        $siteUrl   = rtrim((string)($cfg['wp_url'] ?? ''), '/');

        $out = [
            '@type'    => 'NewsArticle',
            'headline' => mb_substr(self::limparTexto($titulo), 0, 110),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => $url,
            ],
            'datePublished' => $datePub,
            'dateModified'  => $dateMod,
            'author' => [
                '@type' => 'Person',
                'name'  => $autorNome,
                'url'   => $autorUrl,
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name'  => $siteName,
                'url'   => $siteUrl,
                'logo'  => [
                    '@type' => 'ImageObject',
                    'url'   => $siteUrl . '/wp-content/uploads/logo.png',  // padrão WP, falha graciosa se não existe
                ],
            ],
        ];
        if ($imageUrl !== '') {
            $out['image'] = [
                '@type'  => 'ImageObject',
                'url'    => $imageUrl,
                'width'  => 1200,
                'height' => 630,
            ];
        }
        return $out;
    }

    private static function breadcrumbList(array $meta, array $trend, array $cfg): ?array
    {
        $url = (string)($meta['url'] ?? '');
        $titulo = (string)($meta['titulo'] ?? '');
        $siteUrl = rtrim((string)($cfg['wp_url'] ?? ''), '/');
        $siteName = (string)($cfg['site_name'] ?? $cfg['_site_name'] ?? 'Início');
        $clusterNome = (string)($trend['cluster_detect']['nome'] ?? '');
        $clusterKey = (string)($trend['cluster_detect']['key'] ?? '');

        $items = [
            [
                '@type'    => 'ListItem',
                'position' => 1,
                'name'     => $siteName,
                'item'     => $siteUrl . '/',
            ],
        ];

        if ($clusterNome !== '' && $clusterKey !== '') {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => 2,
                'name'     => $clusterNome,
                'item'     => $siteUrl . '/categoria/' . self::slugify($clusterKey) . '/',
            ];
        }

        $items[] = [
            '@type'    => 'ListItem',
            'position' => count($items) + 1,
            'name'     => mb_substr(self::limparTexto($titulo), 0, 80),
            'item'     => $url,
        ];

        return [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * Organization schema do site. Distinto por site = sinaliza ao Google que é uma
     * editora autônoma, não cluster artificial (anti-PBN).
     *
     * Caminho C: se `cfg.empresa.nome` estiver definido, descrição usa empresa.descricao
     * (institucional, declarado) em vez de inferir da persona, e adiciona
     * `parentOrganization` apontando pra editora-mãe (Sistema 2 ou Sistema 3).
     */
    private static function organization(array $cfg): ?array
    {
        $siteUrl = rtrim((string)($cfg['wp_url'] ?? ''), '/');
        if ($siteUrl === '') return null;
        $siteName = (string)($cfg['site_name'] ?? $cfg['_site_name'] ?? '');
        if ($siteName === '') return null;

        $persona = $cfg['persona'] ?? [];
        $audiencia = (string)($persona['audiencia'] ?? '');
        $especialidade = (string)($persona['especialidade'] ?? '');
        $empresa = $cfg['empresa'] ?? [];
        $empresaNome = trim((string)($empresa['nome'] ?? ''));
        $empresaDesc = trim((string)($empresa['descricao'] ?? ''));
        $subtipoNicho = trim((string)($cfg['subtipo_nicho'] ?? ''));

        // Description: prioriza declaração institucional da empresa.descricao + subtipo_nicho.
        // Fallback pra inferência de persona se empresa não estiver definida.
        if ($empresaDesc !== '') {
            $descricao = $empresaDesc;
            if ($subtipoNicho !== '') {
                $descricao .= ". Foco editorial: {$subtipoNicho}.";
            } elseif ($audiencia !== '') {
                $descricao .= " Voltado pra {$audiencia}.";
            }
        } else {
            $descricao = $especialidade !== ''
                ? "Editorial brasileiro especializado em {$especialidade}."
                : 'Editorial brasileiro independente.';
            if ($audiencia !== '') {
                $descricao .= " Voltado pra {$audiencia}.";
            }
        }

        $out = [
            '@type'       => 'Organization',
            '@id'         => $siteUrl . '/#organization',
            'name'        => $siteName,
            'url'         => $siteUrl,
            'description' => $descricao,
            'logo'        => [
                '@type' => 'ImageObject',
                'url'   => $siteUrl . '/wp-content/uploads/logo.png',
            ],
            // Each site has its own potential search action — sinaliza site search box no SERP
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => $siteUrl . '/?s={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ];

        // parentOrganization: editora-mãe declarada. Sinaliza ao Google a estrutura corporativa
        // distintiva (Sistema 2 vs Sistema 3) — em vez de 6 sites soltos sem dono visível.
        if ($empresaNome !== '') {
            $parent = [
                '@type' => 'Organization',
                'name'  => $empresaNome,
            ];
            if ($empresaDesc !== '') $parent['description'] = $empresaDesc;
            if (!empty($empresa['cnpj'])) {
                // identifier no Schema.org aceita PropertyValue ou string. Mantemos string.
                $parent['identifier'] = (string)$empresa['cnpj'];
            }
            $out['parentOrganization'] = $parent;
        }

        // knowsAbout: especialização editorial declarada (Caminho C). Sinaliza ao Google
        // o foco tópico do site — diferente do `knowsAbout` da Person (autor).
        if ($subtipoNicho !== '') {
            $out['knowsAbout'] = $subtipoNicho;
        }

        // sameAs: redes sociais do site (FB Page, IG)
        $sameAs = [];
        if (!empty($cfg['fb_page_id'])) {
            $sameAs[] = 'https://www.facebook.com/' . $cfg['fb_page_id'];
        }
        // Persona pode ter sameAs do site (não do autor)
        if (!empty($persona['site_sameAs']) && is_array($persona['site_sameAs'])) {
            foreach ($persona['site_sameAs'] as $u) {
                if (preg_match('#^https?://#', (string)$u)) $sameAs[] = (string)$u;
            }
        }
        if (!empty($sameAs)) $out['sameAs'] = array_values(array_unique($sameAs));

        return $out;
    }

    private static function personAutor(array $trend, array $cfg): ?array
    {
        $persona = $cfg['persona'] ?? [];
        if (empty($persona) || empty($persona['autor'])) return null;

        $autor = (string)$persona['autor'];
        $url = self::autorUrl($cfg);
        $jobTitle = (string)($persona['especialidade'] ?? 'Editor de conteúdo editorial');
        $audiencia = (string)($persona['audiencia'] ?? '');

        // knowsAbout: extrai do campo clusters_foco da persona, OU usa cluster atual
        $knowsAbout = [];
        if (!empty($persona['clusters_foco']) && is_array($persona['clusters_foco'])) {
            foreach ($persona['clusters_foco'] as $c) {
                $knowsAbout[] = self::clusterParaTopico($c);
            }
        }
        // Adiciona o cluster_detect atual se não estiver
        $clusterAtual = (string)($trend['cluster_detect']['nome'] ?? '');
        if ($clusterAtual !== '' && !in_array($clusterAtual, $knowsAbout, true)) {
            $knowsAbout[] = $clusterAtual;
        }

        $out = [
            '@type'    => 'Person',
            'name'     => $autor,
            'jobTitle' => $jobTitle,
            'url'      => $url,
        ];
        if (!empty($knowsAbout)) {
            $out['knowsAbout'] = array_values(array_unique($knowsAbout));
        }
        // sameAs vazio por padrão (caller pode futuramente injetar LinkedIn/Twitter da persona)
        if (!empty($persona['sameAs']) && is_array($persona['sameAs'])) {
            $out['sameAs'] = $persona['sameAs'];
        }
        return $out;
    }

    private static function course(array $meta, array $trend, array $cfg): ?array
    {
        $clusterKey = (string)($trend['cluster_detect']['key'] ?? '');
        if ($clusterKey !== 'educacao') return null;

        $termo = mb_strtolower((string)($trend['termo'] ?? ''), 'UTF-8');
        // Só dispara se termo cita curso/edital/inscrição (não qualquer trend educacional)
        if (!preg_match('/\b(curso|cursos|edital|editais|inscri[çc][aã]o|inscri[çc][oõ]es|vagas?|matr[íi]cula|prova|sele[çc][aã]o)\b/iu', $termo)) {
            return null;
        }

        $titulo = (string)($meta['titulo'] ?? '');
        $url    = (string)($meta['url'] ?? '');
        $siteName = (string)($cfg['site_name'] ?? $cfg['_site_name'] ?? '');

        // Detecta provedor (Senac, MEC, etc) — heurística simples
        $provedor = 'Provedor educacional brasileiro';
        $mapaProv = [
            'senac'    => 'Senac',
            'senai'    => 'Senai',
            'fies'     => 'MEC / FIES',
            'prouni'   => 'MEC / ProUni',
            'enem'     => 'Inep / MEC',
            'unicamp'  => 'Unicamp',
            'usp'      => 'USP',
            'fuvest'   => 'Fuvest',
        ];
        foreach ($mapaProv as $kw => $nome) {
            if (strpos($termo, $kw) !== false) { $provedor = $nome; break; }
        }

        return [
            '@type'       => 'Course',
            'name'        => mb_substr(self::limparTexto($titulo), 0, 110),
            'description' => 'Informações sobre cursos, editais e inscrições — atualizado pela equipe editorial.',
            'provider'    => [
                '@type' => 'Organization',
                'name'  => $provedor,
            ],
            'url'         => $url,
            'inLanguage'  => 'pt-BR',
            'isAccessibleForFree' => true,
        ];
    }

    private static function event(array $meta, array $trend, array $cfg): ?array
    {
        $origem = (string)($trend['origem'] ?? '');
        if (strpos($origem, 'sazonal:') !== 0) return null;

        $titulo = (string)($meta['titulo'] ?? '');
        $url    = (string)($meta['url'] ?? '');

        // Tenta extrair data do nome do evento (origem = "sazonal:Dia das Mães")
        $eventoNome = trim(substr($origem, strlen('sazonal:')));
        if ($eventoNome === '') return null;

        // Tentativa de obter data do calendário
        $dataInicio = self::dataDoCalendario($eventoNome);
        if ($dataInicio === null) return null;

        return [
            '@type'      => 'Event',
            'name'       => $eventoNome,
            'startDate'  => $dataInicio,
            'eventStatus'         => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location'   => [
                '@type'   => 'Place',
                'name'    => 'Brasil',
                'address' => [
                    '@type'         => 'PostalAddress',
                    'addressCountry'=> 'BR',
                ],
            ],
            'url'        => $url,
        ];
    }

    private static function itemListProdutos(array $produtos, string $urlPost): ?array
    {
        $items = [];
        foreach (array_slice($produtos, 0, 10) as $i => $p) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'item'     => [
                    '@type' => 'Product',
                    'name'  => mb_substr((string)($p['nome'] ?? ''), 0, 110),
                    'image' => (string)($p['img'] ?? ''),
                    'url'   => (string)($p['url'] ?? ''),
                    'offers' => [
                        '@type'         => 'Offer',
                        'priceCurrency' => 'BRL',
                        'price'         => (string)($p['preco_num'] ?? '0'),
                        'availability'  => 'https://schema.org/InStock',
                    ],
                ],
            ];
        }
        if (empty($items)) return null;
        return [
            '@type'           => 'ItemList',
            'itemListOrder'   => 'https://schema.org/ItemListOrderAscending',
            'numberOfItems'   => count($items),
            'itemListElement' => $items,
            'mainEntityOfPage' => $urlPost,
        ];
    }

    // ─────────── HELPERS ───────────

    private static function dataPublicacao(array $trend): string
    {
        $val = (string)($trend['publicado_em'] ?? $trend['data_detectada'] ?? date('Y-m-d H:i:s'));
        // Converte pra ISO 8601 com timezone BR
        $ts = strtotime($val);
        if ($ts === false) $ts = time();
        return date('c', $ts);
    }

    private static function dataAtualizacao(array $trend): string
    {
        $val = (string)($trend['ultimo_update'] ?? $trend['publicado_em'] ?? $trend['data_detectada'] ?? date('Y-m-d H:i:s'));
        $ts = strtotime($val);
        if ($ts === false) $ts = time();
        return date('c', $ts);
    }

    private static function imageUrl(array $trend, array $cfg): string
    {
        // Caller passa imagem específica via $cfg['_image_url'] se quiser
        return (string)($cfg['_image_url'] ?? '');
    }

    private static function autorUrl(array $cfg): string
    {
        $siteUrl = rtrim((string)($cfg['wp_url'] ?? ''), '/');
        if ($siteUrl === '') return '';
        return $siteUrl . '/author/admin/';
    }

    private static function clusterParaTopico(string $clusterKey): string
    {
        $mapa = [
            'esportes'              => 'Esportes',
            'educacao'              => 'Educação e Cursos',
            'noticias_info_critica' => 'Notícias e Serviços Públicos',
            'negocios_financas'     => 'Finanças e Benefícios Sociais',
            'tecnologia'            => 'Tecnologia',
            'lifestyle_consumo'     => 'Lifestyle e Consumo',
            'comidas_bebidas'       => 'Comida e Bebida',
            'viagem_transporte'     => 'Viagem e Transporte',
            'automoveis'            => 'Automóveis',
            'saude_bem_estar'       => 'Saúde e Bem-estar',
            'entretenimento'        => 'Entretenimento',
            'entretenimento_cultura'=> 'Entretenimento e Cultura',
            'curiosidades_geral'    => 'Curiosidades Gerais',
        ];
        return $mapa[$clusterKey] ?? ucfirst(str_replace('_', ' ', $clusterKey));
    }

    /**
     * Resolve data ISO de evento sazonal pelo DiscoverCalendario.
     * Usa DiscoverCalendario::proximos(365) que já calcula datas relativas ao ano atual.
     */
    private static function dataDoCalendario(string $eventoNome): ?string
    {
        $path = __DIR__ . '/DiscoverCalendario.php';
        if (!is_file($path)) return null;
        require_once $path;
        if (!class_exists('DiscoverCalendario')) return null;

        try {
            $proximos = DiscoverCalendario::proximos(365);
            foreach ($proximos as $evento) {
                if (strcasecmp(trim((string)($evento['nome'] ?? '')), $eventoNome) !== 0) continue;
                // Campo correto é `data_pico` no output de proximos()
                $data = (string)($evento['data_pico'] ?? $evento['data'] ?? $evento['data_historica_inicio'] ?? '');
                if ($data !== '') {
                    $ts = strtotime($data);
                    if ($ts !== false) return date('Y-m-d', $ts);
                }
            }
        } catch (Throwable $e) { /* fall-through */ }
        return null;
    }

    private static function slugify(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9_]+/', '-', $s) ?? $s;
        return trim($s, '-');
    }

    private static function limparTexto(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }
}
