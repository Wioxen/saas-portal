<?php
/**
 * data/hubs_vitoria.php
 *
 * Lista de páginas-hub do leaodabarra (nicho Esporte Clube Vitória).
 * Cada hub vai virar página WP /slug/ recebendo backlinks internos de todos
 * os posts novos sobre o tópico.
 *
 * Tipos: estadio | jogador | tecnico | presidente | titulo | classico |
 *        identidade | historia | elenco | competicao | base
 *
 * Cada item:
 *   slug:         segmento da URL (ex: 'barradao' → /barradao/)
 *   tipo:         tipo do hub (define prompt customizado)
 *   titulo_h1:    título principal (vai no <h1> e <title>)
 *   meta_title:   título SEO ≤60 chars (preenchido por Sonnet se vazio)
 *   meta_desc:    descrição SERP 140-160 chars (preenchido por Sonnet se vazio)
 *   query_serper: query pra buscar fontes
 *   urls_oficiais: URLs prioritárias (Wikipedia, ecvitoria, CBF) — sempre incluídas
 *   palavras_alvo: tamanho do hub (default 1500)
 */

return [

    // ═══════════════════════════════════════════════════════════════════════
    // TIER 1 — CRÍTICAS
    // ═══════════════════════════════════════════════════════════════════════

    [
        'slug' => 'barradao',
        'tipo' => 'estadio',
        'titulo_h1' => 'Barradão: o Estádio Manoel Barradas, casa do Esporte Clube Vitória',
        'query_serper' => 'Barradão Estádio Manoel Barradas Vitória Salvador história',
        'urls_oficiais' => [
            'https://pt.wikipedia.org/wiki/Est%C3%A1dio_Manoel_Barradas',
            'https://ecvitoria.com.br/o-clube/barradao/',
        ],
        'palavras_alvo' => 1500,
    ],
    [
        'slug' => 'classico-ba-vi',
        'tipo' => 'classico',
        'titulo_h1' => 'Ba-Vi: a história do clássico entre Bahia e Vitória',
        'query_serper' => 'clássico Ba-Vi Bahia Vitória história rivalidade',
        'urls_oficiais' => [
            'https://pt.wikipedia.org/wiki/Cl%C3%A1ssico_Ba-Vi',
        ],
        'palavras_alvo' => 1800,
    ],
    [
        'slug' => 'tecnico-jair-ventura',
        'tipo' => 'tecnico',
        'titulo_h1' => 'Jair Ventura: o técnico do Esporte Clube Vitória',
        'query_serper' => 'Jair Ventura técnico Vitória carreira treinador',
        'urls_oficiais' => [
            'https://pt.wikipedia.org/wiki/Jair_Ventura',
        ],
        'palavras_alvo' => 1200,
    ],
    [
        'slug' => 'presidente-fabio-mota',
        'tipo' => 'presidente',
        'titulo_h1' => 'Fábio Mota: o presidente do Esporte Clube Vitória',
        'query_serper' => 'Fábio Mota presidente Vitória eleição reeleição',
        'urls_oficiais' => [],
        'palavras_alvo' => 1000,
    ],
    [
        'slug' => 'elenco-2026',
        'tipo' => 'elenco',
        'titulo_h1' => 'Elenco 2026 do Esporte Clube Vitória',
        'query_serper' => 'elenco Vitória 2026 jogadores plantel',
        'urls_oficiais' => [
            'https://pt.wikipedia.org/wiki/Esporte_Clube_Vit%C3%B3ria',
        ],
        'palavras_alvo' => 1500,
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // TIER 2A — JOGADORES (32 atletas, base Wikipedia 2025-07-18)
    // ═══════════════════════════════════════════════════════════════════════

    // Goleiros
    ['slug' => 'jogador-lucas-arcanjo',           'tipo' => 'jogador', 'titulo_h1' => 'Lucas Arcanjo: o goleiro do Esporte Clube Vitória',           'query_serper' => 'Lucas Arcanjo goleiro Vitória carreira',           'urls_oficiais' => [], 'palavras_alvo' => 900],
    ['slug' => 'jogador-gabriel-vasconcellos',     'tipo' => 'jogador', 'titulo_h1' => 'Gabriel Vasconcellos: goleiro do Vitória',                  'query_serper' => 'Gabriel Vasconcellos goleiro Vitória',           'urls_oficiais' => [], 'palavras_alvo' => 800],
    ['slug' => 'jogador-yuri-sena',                'tipo' => 'jogador', 'titulo_h1' => 'Yuri Sena: goleiro do Vitória',                             'query_serper' => 'Yuri Sena goleiro Vitória',                       'urls_oficiais' => [], 'palavras_alvo' => 800],

    // Zagueiros
    ['slug' => 'jogador-camutanga',                'tipo' => 'jogador', 'titulo_h1' => 'Camutanga: zagueiro do Esporte Clube Vitória',              'query_serper' => 'Camutanga zagueiro Vitória carreira',             'urls_oficiais' => [], 'palavras_alvo' => 900],
    ['slug' => 'jogador-riccieli',                 'tipo' => 'jogador', 'titulo_h1' => 'Riccieli: zagueiro do Esporte Clube Vitória',               'query_serper' => 'Riccieli zagueiro Vitória carreira',              'urls_oficiais' => [], 'palavras_alvo' => 900],
    ['slug' => 'jogador-neris',                    'tipo' => 'jogador', 'titulo_h1' => 'Neris: zagueiro do Esporte Clube Vitória',                  'query_serper' => 'Neris zagueiro Vitória carreira',                 'urls_oficiais' => [], 'palavras_alvo' => 800],
    ['slug' => 'jogador-edu',                      'tipo' => 'jogador', 'titulo_h1' => 'Edu: zagueiro do Vitória',                                  'query_serper' => 'Edu zagueiro Vitória',                            'urls_oficiais' => [], 'palavras_alvo' => 800],

    // Laterais
    ['slug' => 'jogador-claudinho',                'tipo' => 'jogador', 'titulo_h1' => 'Claudinho: lateral-direito do Vitória',                     'query_serper' => 'Claudinho lateral Vitória carreira',              'urls_oficiais' => [], 'palavras_alvo' => 800],
    ['slug' => 'jogador-matheusinho',              'tipo' => 'jogador', 'titulo_h1' => 'Matheusinho: lateral-direito do Vitória',                   'query_serper' => 'Matheusinho lateral Vitória carreira',            'urls_oficiais' => [], 'palavras_alvo' => 800],
    ['slug' => 'jogador-jamerson',                 'tipo' => 'jogador', 'titulo_h1' => 'Jamerson: lateral-esquerdo do Vitória',                     'query_serper' => 'Jamerson lateral esquerdo Vitória',               'urls_oficiais' => [], 'palavras_alvo' => 800],
    ['slug' => 'jogador-luan-candido',             'tipo' => 'jogador', 'titulo_h1' => 'Luan Cândido: lateral-esquerdo do Vitória',                 'query_serper' => 'Luan Cândido lateral Vitória carreira',           'urls_oficiais' => [], 'palavras_alvo' => 800],
    ['slug' => 'jogador-ramon-ramos',              'tipo' => 'jogador', 'titulo_h1' => 'Ramon Ramos Lima: lateral-esquerdo do Vitória',             'query_serper' => 'Ramon Ramos Lima lateral Vitória',                'urls_oficiais' => [], 'palavras_alvo' => 800],

    // Volantes
    ['slug' => 'jogador-ronald',                   'tipo' => 'jogador', 'titulo_h1' => 'Ronald: volante do Esporte Clube Vitória',                  'query_serper' => 'Ronald volante Vitória carreira',                 'urls_oficiais' => [], 'palavras_alvo' => 800],
    ['slug' => 'jogador-dudu',                     'tipo' => 'jogador', 'titulo_h1' => 'Dudu: volante do Vitória',                                  'query_serper' => 'Dudu volante Vitória',                            'urls_oficiais' => [], 'palavras_alvo' => 800],
    ['slug' => 'jogador-gabriel-baralhas',         'tipo' => 'jogador', 'titulo_h1' => 'Gabriel Baralhas: volante do Vitória',                      'query_serper' => 'Gabriel Baralhas volante Vitória',                'urls_oficiais' => [], 'palavras_alvo' => 800],
    ['slug' => 'jogador-ruben-ismael',             'tipo' => 'jogador', 'titulo_h1' => 'Rúben Ismael: volante do Vitória',                          'query_serper' => 'Rúben Ismael volante Vitória',                    'urls_oficiais' => [], 'palavras_alvo' => 800],

    // Meias
    ['slug' => 'jogador-matheuzinho',              'tipo' => 'jogador', 'titulo_h1' => 'Matheuzinho: meia do Esporte Clube Vitória',                'query_serper' => 'Matheuzinho meia Vitória carreira',               'urls_oficiais' => [], 'palavras_alvo' => 900],
    ['slug' => 'jogador-emmanuel-martinez',        'tipo' => 'jogador', 'titulo_h1' => 'Emmanuel Martínez: meia do Vitória',                        'query_serper' => 'Emmanuel Martínez meia Vitória Argentina',        'urls_oficiais' => [], 'palavras_alvo' => 900],
    ['slug' => 'jogador-aitor-cantalapiedra',      'tipo' => 'jogador', 'titulo_h1' => 'Aitor Cantalapiedra: meia do Vitória',                      'query_serper' => 'Aitor Cantalapiedra meia Vitória Espanha',        'urls_oficiais' => [], 'palavras_alvo' => 900],
    ['slug' => 'jogador-lucas-silva',              'tipo' => 'jogador', 'titulo_h1' => 'Lucas Silva: meia do Vitória',                              'query_serper' => 'Lucas Silva meia Vitória',                        'urls_oficiais' => [], 'palavras_alvo' => 800],

    // Atacantes
    ['slug' => 'jogador-renato-kayzer',            'tipo' => 'jogador', 'titulo_h1' => 'Renato Kayzer: atacante do Esporte Clube Vitória',          'query_serper' => 'Renato Kayzer atacante Vitória carreira',         'urls_oficiais' => [], 'palavras_alvo' => 900],
    ['slug' => 'jogador-erick-serafim',            'tipo' => 'jogador', 'titulo_h1' => 'Erick Serafim: atacante do Vitória',                        'query_serper' => 'Erick Serafim atacante Vitória',                  'urls_oficiais' => [], 'palavras_alvo' => 900],
    ['slug' => 'jogador-osvaldo-filho',            'tipo' => 'jogador', 'titulo_h1' => 'Osvaldo Filho: atacante do Vitória',                        'query_serper' => 'Osvaldo Filho atacante Vitória',                  'urls_oficiais' => [], 'palavras_alvo' => 800],
    ['slug' => 'jogador-renzo-lopez',              'tipo' => 'jogador', 'titulo_h1' => 'Renzo López: atacante uruguaio do Vitória',                 'query_serper' => 'Renzo López atacante Vitória Uruguai',            'urls_oficiais' => [], 'palavras_alvo' => 800],
    ['slug' => 'jogador-kike-saverio',             'tipo' => 'jogador', 'titulo_h1' => 'Kike Saverio: atacante do Vitória',                         'query_serper' => 'Kike Saverio atacante Vitória',                   'urls_oficiais' => [], 'palavras_alvo' => 800],
    ['slug' => 'jogador-pedro-henrique',           'tipo' => 'jogador', 'titulo_h1' => 'Pedro Henrique: atacante do Vitória',                       'query_serper' => 'Pedro Henrique atacante Vitória',                 'urls_oficiais' => [], 'palavras_alvo' => 800],
    ['slug' => 'jogador-fabri',                    'tipo' => 'jogador', 'titulo_h1' => 'Fabri: atacante do Vitória',                                'query_serper' => 'Fabri atacante Vitória',                          'urls_oficiais' => [], 'palavras_alvo' => 800],

    // ═══════════════════════════════════════════════════════════════════════
    // TIER 2B — TÍTULOS HISTÓRICOS
    // ═══════════════════════════════════════════════════════════════════════

    [
        'slug' => 'titulos-do-vitoria',
        'tipo' => 'historia',
        'titulo_h1' => 'Títulos do Esporte Clube Vitória: Brasileirão, Nordestão, Baianos e mais',
        'query_serper' => 'títulos Esporte Clube Vitória Brasileirão Nordeste Baiano lista',
        'urls_oficiais' => [
            'https://pt.wikipedia.org/wiki/Esporte_Clube_Vit%C3%B3ria',
            'https://ecvitoria.com.br/o-clube/titulos/',
        ],
        'palavras_alvo' => 1800,
    ],
    [
        'slug' => 'historia-copa-do-nordeste-1997',
        'tipo' => 'titulo',
        'titulo_h1' => 'Copa do Nordeste 1997: o primeiro título regional do Vitória',
        'query_serper' => 'Vitória Copa do Nordeste 1997 campeão final',
        'urls_oficiais' => [],
        'palavras_alvo' => 1200,
    ],
    [
        'slug' => 'historia-copa-do-nordeste-1999',
        'tipo' => 'titulo',
        'titulo_h1' => 'Copa do Nordeste 1999: o bicampeonato do Vitória',
        'query_serper' => 'Vitória Copa do Nordeste 1999 campeão final',
        'urls_oficiais' => [],
        'palavras_alvo' => 1200,
    ],
    [
        'slug' => 'historia-copa-do-nordeste-2003',
        'tipo' => 'titulo',
        'titulo_h1' => 'Copa do Nordeste 2003: o tricampeonato rubro-negro',
        'query_serper' => 'Vitória Copa do Nordeste 2003 campeão final',
        'urls_oficiais' => [],
        'palavras_alvo' => 1200,
    ],
    [
        'slug' => 'historia-copa-do-nordeste-2010',
        'tipo' => 'titulo',
        'titulo_h1' => 'Copa do Nordeste 2010: o tetracampeonato do Vitória',
        'query_serper' => 'Vitória Copa do Nordeste 2010 campeão final',
        'urls_oficiais' => [],
        'palavras_alvo' => 1200,
    ],
    [
        'slug' => 'historia-serie-b-2023',
        'tipo' => 'titulo',
        'titulo_h1' => 'Série B 2023: o título histórico que devolveu o Vitória à elite',
        'query_serper' => 'Vitória Série B 2023 campeão acesso elite',
        'urls_oficiais' => [],
        'palavras_alvo' => 1500,
    ],
    [
        'slug' => 'fabrica-de-talentos',
        'tipo' => 'base',
        'titulo_h1' => 'Fábrica de Talentos: a história das categorias de base do Vitória',
        'query_serper' => 'Vitória Fábrica Talentos categorias base Dida Vampeta',
        'urls_oficiais' => [
            'https://pt.wikipedia.org/wiki/Esporte_Clube_Vit%C3%B3ria',
        ],
        'palavras_alvo' => 1500,
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // TIER 3 — IDENTIDADE / LONG TAIL
    // ═══════════════════════════════════════════════════════════════════════

    [
        'slug' => 'escudo-do-vitoria',
        'tipo' => 'identidade',
        'titulo_h1' => 'Escudo do Vitória: a história do símbolo rubro-negro',
        'query_serper' => 'escudo Esporte Clube Vitória história símbolo evolução',
        'urls_oficiais' => [
            'https://pt.wikipedia.org/wiki/Esporte_Clube_Vit%C3%B3ria',
        ],
        'palavras_alvo' => 1000,
    ],
    [
        'slug' => 'mascote-lele-leao',
        'tipo' => 'identidade',
        'titulo_h1' => 'Lelê Leão: o mascote do Esporte Clube Vitória criado por Ziraldo',
        'query_serper' => 'Lelê Leão mascote Vitória Ziraldo',
        'urls_oficiais' => [
            'https://pt.wikipedia.org/wiki/Esporte_Clube_Vit%C3%B3ria',
        ],
        'palavras_alvo' => 800,
    ],
    [
        'slug' => 'fundacao-do-vitoria-1899',
        'tipo' => 'historia',
        'titulo_h1' => 'Fundação do Esporte Clube Vitória: 13 de maio de 1899',
        'query_serper' => 'fundação Esporte Clube Vitória 1899 Club de Cricket Victoria',
        'urls_oficiais' => [
            'https://pt.wikipedia.org/wiki/Esporte_Clube_Vit%C3%B3ria',
        ],
        'palavras_alvo' => 1200,
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // COMPETIÇÕES (hubs categoria — paralelo às WP categories)
    // ═══════════════════════════════════════════════════════════════════════

    [
        'slug' => 'historia-do-esporte-clube-vitoria',  // ATUALIZAR a página existente
        'tipo' => 'historia',
        'titulo_h1' => 'História do Esporte Clube Vitória: tudo sobre o Leão da Barra',
        'query_serper' => 'história Esporte Clube Vitória fundação títulos jogadores',
        'urls_oficiais' => [
            'https://pt.wikipedia.org/wiki/Esporte_Clube_Vit%C3%B3ria',
            'https://ecvitoria.com.br/',
        ],
        'palavras_alvo' => 2500,
    ],
];
