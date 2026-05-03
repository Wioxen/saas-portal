<?php
/**
 * Cadastro de sites (multi-WP).
 * Adicione novos sites copiando um bloco existente.
 * O 1º da lista é o default (selecionado quando nenhum é passado).
 *
 * Campos obrigatórios: name, wp_url, wp_user, wp_app_password
 * Campos opcionais:
 *   - site_name, whatsapp_number, whatsapp_group_url, whatsapp_cta_text, pretty_links_prefix
 *   - persona — voz editorial (autor, voz, especialidade, audiencia, tom, ...)
 *   - empresa — identidade institucional (nome, descricao, cnpj). Sinal anti-PBN: cada
 *     editora distinta no Schema Organization. Sites do mesmo grupo formam "rede irmã".
 *   - subtipo_nicho — declaração curta da especialização editorial (ex: "cursos técnicos
 *     / EAD"). Pre-flight check valida que termos batem com o subtipo antes de publicar.
 *   - termos_canibal — termos pertencentes a sites IRMÃOS da mesma editora (anti-canibalização
 *     cruzada). Se trend bate aqui → rejeita; o sister site cobre o assunto.
 *   - Meta (FB Page + Instagram Business): fb_page_id (numérico), fb_page_token (token, vem do .env),
 *     ig_user_id (numérico), ig_access_token (token, vem do .env)
 * Se omitidos, herdam do config.php.
 *
 * IMPORTANTE: tokens (fb_page_token, ig_access_token) são lidos do .env via Env::get().
 * Nunca cole tokens em plaintext aqui — a regra é IDs ficam, tokens vão pra .env.
 *
 * ── DIVISÃO EM 2 EDITORAS (Caminho C — Híbrido Especializado) ──
 * Sistema 2 — Editora Educacional/Serviço Público (cursosenac, guiadoscursos, vagasebeneficios)
 * Sistema 3 — Editora Lifestyle/Consumo/Esportes (comocomprar, ondecompraragora, leaodabarra)
 *
 * Sites do mesmo grupo (empresa.nome igual) formam "rede irmã": são tratados pelo
 * cross-site dedup (similaridade >60% bloqueada) e pelo pre-flight de especialização.
 */
require_once __DIR__ . '/lib/Env.php';
Env::load(__DIR__ . '/.env');

return [
    'comocomprar' => [
        'name'              => 'Como Comprar',
        'wp_url'            => 'https://comocomprar.com.br',
        'wp_user'           => 'admin',
        'wp_app_password'   => Env::get('WP_PASS_COMOCOMPRAR', ''),
        'site_name'         => 'Como Comprar',
        'whatsapp_number'   => '5571992354308',
        'whatsapp_group_url'=> 'https://chat.whatsapp.com/DZQEBj3hHEiE15pS2osUqC?mode=gi_t',
        'whatsapp_cta_text' => 'Receba ofertas relâmpago no WhatsApp',
        'pretty_links_prefix' => 'go',
        // AMAZON ASSOCIATES — usado FUTURAMENTE quando API estiver liberada.
        // Hoje (sem API) o fluxo é: cadastrar PrettyLink manual com link de afiliado
        // gerado no SiteStripe da Amazon. Magalu/ML/Shopee mesma coisa: PrettyLink
        // com deeplink real do programa. Anexar tag em URL marketplace original NÃO
        // atribui comissão.
        'amazon_associates_tag' => '',
        // Cloudflare Zone ID — purge automático após Title/P1/Meta swap. Defensivo:
        // vazio aqui OU sem CLOUDFLARE_API_TOKEN no .env → no-op silencioso.
        'cloudflare_zone_id'    => '',
        'empresa' => [
            'nome'      => 'Sistema 3 Mídia Digital',
            'descricao' => 'Editora brasileira de mídia digital especializada em consumo, lifestyle e esportes',
            'cnpj'      => '',
        ],
        'subtipo_nicho' => 'guias de compra e comparativos de produto (Amazon, Shopee, Mercado Livre)',
        'termos_canibal' => [
            // ondecompraragora cobre oferta relâmpago/janela curta
            'oferta relâmpago', 'só hoje', 'última hora', 'janela de 24h', 'cupom expira',
            // leaodabarra cobre esportes
            'escalação', 'libertadores', 'brasileirão', 'onde assistir jogo', 'fórmula 1', 'mma', 'nba',
            // vafast cobre cursos/benefícios/finanças (Sistema 3)
            'curso gratuito', 'curso ead', 'bolsa família', 'bpc', 'auxílio gás', 'inss', 'fgts',
            'consignado', 'cartão de crédito sem anuidade', 'investimento renda fixa', 'tesouro direto',
        ],
        'persona' => [
            'autor'            => 'Equipe Como Comprar',
            'voz'              => 'direta e econômica, comparador nato, traduz caro-vs-barato em 2 linhas',
            'especialidade'    => 'guias de compra, comparativos, calendário de promoções, cashback, Amazon/Shopee/Mercado Livre',
            'audiencia'        => 'consumidores brasileiros classe B/C, pesquisadores de preço, quem quer economizar sem abrir mão de qualidade',
            'tom'              => 'prático, anti-clickbait, faz as contas na cara do leitor (R$ economizado, % desconto)',
            'clusters_foco'    => ['lifestyle_consumo', 'automoveis', 'tecnologia', 'comidas_bebidas'],
            'termos_proibidos' => ['absurdo', 'imperdível', 'jamais visto', 'preço de banana'],
            'cta_estilo'       => 'ver preço hoje · comparar ofertas · garantir cashback',
        ],
        // Tema do site já renderiza author box + breadcrumb + schemas via RankMath.
        // Inline desses elementos no content = duplicação visual. Manter false.
        'author_box_inline'        => false,
        'breadcrumb_inline'        => false,
        'rankmath_handles_schemas' => true,
        // FRESHNESS: só trends capturados nas últimas N horas viram post.
        // Default 24h pra evitar gerar matérias com base em fontes velhas.
        'trend_max_idade_horas' => 24,
    ],
	'vagasebeneficios' => [
        'name'              => 'Vagas e Beneficios',
        'wp_url'            => 'https://vagasebeneficios.com',
        'wp_user'           => 'admin',
        'wp_app_password'   => Env::get('WP_PASS_VAGASEBENEFICIOS', ''),
        'site_name'         => 'Vagas e Beneficios',
        'whatsapp_number'   => '5571992354308',
        'whatsapp_group_url'=> '',
        'whatsapp_cta_text' => 'Receba ofertas no WhatsApp',
        'pretty_links_prefix' => 'go',
        // Override imagem: usa DALL-E direto (em vez de Pexels first). A/B test contra outros sites.
        'imagem_featured_estrategia' => 'dalle_first',
		// Facebook Page "Maria Gusmão" — token vem do .env (FB_PAGE_TOKEN_MARIA)
        'fb_page_id'        => '101766412913237',
        'fb_page_token'     => Env::get('FB_PAGE_TOKEN_MARIA', ''),
        // Instagram @_cursocomcertificado (vinculada à Page Maria Gusmão) — token vem do .env
        // ig_user_id 17841459270105515 é o "instagram_business_account" da Page (alias funcional do user_id 26495809790087167)
        'ig_user_id'        => '17841459270105515',
        'ig_access_token'   => Env::get('IG_TOKEN_VAGASEBENEFICIOS', ''),
        'empresa' => [
            'nome'      => 'Sistema 2 Conteúdo Educacional',
            'descricao' => 'Editora brasileira especializada em educação, serviços públicos e direitos do trabalhador',
            'cnpj'      => '',
        ],
        'subtipo_nicho' => 'INSS, benefícios sociais, vagas CLT e concursos públicos',
        'termos_canibal' => [
            // cursosenac cobre cursos técnicos/EAD profissionalizante
            'curso senac', 'curso senai', 'curso técnico', 'qualificação profissional senac', 'curso ead senac',
            // guiadoscursos cobre ensino superior
            'fies', 'prouni', 'sisu', 'graduação', 'ensino superior', 'mba', 'pós-graduação',
            'vestibular fuvest', 'vestibular unicamp', 'nota mec',
        ],
        'persona' => [
            'autor'            => 'Redação Vagas & Benefícios',
            'voz'              => 'jornalismo de serviço público, didática sem paternalismo, foca no passo-a-passo acionável',
            'especialidade'    => 'INSS, Bolsa Família, BPC, FGTS, PIS/PASEP, Auxílio Gás, calendários de pagamento, concursos, vagas CLT',
            'audiencia'        => 'trabalhadores CLT, autônomos, aposentados, famílias de baixa renda que dependem de benefícios',
            'tom'              => 'urgente em prazos, alertivo em golpes, empático em negativas, claro em como recorrer',
            'clusters_foco'    => ['noticias_info_critica', 'negocios_financas'],
            'termos_proibidos' => ['milagre', 'receba sem burocracia', 'golpe do governo', 'vai perder se não correr'],
            'cta_estilo'       => 'consultar calendário · pedir no Meu INSS · simular benefício · ver edital',
        ],
        'author_box_inline'        => false,
        'breadcrumb_inline'        => false,
        'rankmath_handles_schemas' => true,
        'trend_max_idade_horas'    => 24,
     ],
	 'cursosenac' => [
        'name'              => 'Curso SENAC',
        'wp_url'            => 'https://cursosenacgratuito.com.br',
        'wp_user'           => 'admin',
        'wp_app_password'   => Env::get('WP_PASS_CURSOSENAC', ''),
        'site_name'         => 'Curso SENAC',
        'whatsapp_number'   => '5571992354308',
        'whatsapp_group_url'=> '',
        'whatsapp_cta_text' => 'Receba ofertas no WhatsApp',
        'pretty_links_prefix' => 'go',
        // Facebook Page "Maria Gusmão" — token vem do .env (FB_PAGE_TOKEN_MARIA)
        'fb_page_id'        => '101766412913237',
        'fb_page_token'     => Env::get('FB_PAGE_TOKEN_MARIA', ''),
        // Instagram @cursodosenac — token vem do .env (IG_TOKEN_CURSOSENAC)
        'ig_user_id'        => '26580195228287994',
        'ig_access_token'   => Env::get('IG_TOKEN_CURSOSENAC', ''),
        'empresa' => [
            'nome'      => 'Sistema 2 Conteúdo Educacional',
            'descricao' => 'Editora brasileira especializada em cursos técnicos, EAD profissionalizante e qualificação Senac/Senai',
            'cnpj'      => '',
        ],
        'subtipo_nicho' => 'cursos técnicos gratuitos, EAD profissionalizante, qualificação Senac/Senai e ENEM',
        'termos_canibal' => [
            // vagasebeneficios cobre INSS/BPC/FGTS/concursos
            'inss', 'aposentadoria', 'bpc', 'bolsa família', 'fgts', 'pis/pasep', 'auxílio gás',
            'concurso público federal', 'concurso estadual', 'vaga clt',
            // guiadoscursos cobre ensino superior
            'fies', 'prouni', 'sisu', 'graduação', 'ensino superior', 'mba', 'pós-graduação',
            'nota mec', 'vestibular fuvest', 'vestibular unicamp',
        ],
        'persona' => [
            'autor'            => 'Maria Gusmão, editora de educação',
            'voz'              => 'orientadora de carreira, mistura empatia com rigor técnico, cita fontes oficiais (Inep, MEC, Senac)',
            'especialidade'    => 'ENEM, SISU, ProUni, FIES, cursos gratuitos Senac/Senai, vestibulares, olimpíadas, concursos de nível médio/técnico',
            'audiencia'        => 'estudantes de ensino médio, vestibulandos, pais preocupados, jovens de 16-28 procurando primeiro emprego via qualificação',
            'tom'              => 'motivacional sem ser vazio, urgente em prazos de inscrição, detalha critérios de isenção',
            'clusters_foco'    => ['noticias_info_critica'],
            'termos_proibidos' => ['fácil passar', 'vagas sobrando', 'sem estudo você entra', 'cola'],
            'cta_estilo'       => 'pedir isenção · consultar edital · baixar apostila oficial · inscrever até [data]',
        ],
        // RankMath gera NewsArticle/BreadcrumbList/Person/Organization/WebPage no <head>
        // E o tema renderiza author box + breadcrumb na sidebar/topo do post.
        // Se gerarmos esses elementos inline no content = duplicação visual + DOM bagunçado.
        'author_box_inline'        => false,
        'breadcrumb_inline'        => false,
        'rankmath_handles_schemas' => true,
        // Threshold mais baixo pra educação — nicho informativo/crítico tem score natural menor
        // que esporte/política. Default global é 7.0; aqui 5.0 captura trends gold tipo "Enem isenção".
        'trend_scoring_threshold' => 5.0,
        'trend_scoring_enabled'   => true,
        // Filtro de nicho — trend só é aprovado se contém 1+ termo desta lista (educação/cursos).
        // Bloqueia vazamentos como "F1 GP Miami" pontuando alto.
        'nicho_required_terms' => [
            // Cursos e qualificação
            'curso', 'cursos', 'capacitação', 'qualificação', 'formação', 'especialização',
            'técnico', 'técnica', 'profissionalizante', 'ead', 'a distância', 'tutorial',
            'senac', 'senai', 'sebrae', 'sesc', 'sesi', 'if ', 'instituto federal',
            'instituição', 'escola técnica', 'escola profissionalizante',
            // Vestibulares e bolsas
            'enem', 'sisu', 'prouni', 'fies', 'fuvest', 'unicamp', 'vestibular',
            'bolsa', 'bolsas', 'isenção', 'inscrição', 'edital', 'inscrições',
            'pé-de-meia', 'pe-de-meia', 'pé de meia',
            // MEC e contexto educacional
            'mec', 'inep', 'cnpq', 'capes',
            'estudante', 'estudantes', 'aluno', 'alunos', 'universitário', 'universitária',
            'graduando', 'graduandos', 'vestibulando', 'vestibulandos',
            'professor', 'professores', 'docente', 'pedagogo',
            'escola', 'escolas', 'colégio', 'colégios', 'universidade', 'faculdade',
            'ensino médio', 'ensino fundamental', 'ensino superior', 'ensino básico',
            'olimpíada', 'olimpíadas', 'concurso', 'concursos',
            // Educação geral
            'educação', 'educacional', 'pedagógico', 'pedagogia', 'didática',
            'aprendizagem', 'leitura', 'alfabetização', 'letramento',
            'pesquisa científica', 'iniciação científica',
        ],
     ],
	 'guiadoscursos' => [
        'name'              => 'Guia dos Cursos',
        'wp_url'            => 'https://guiadoscursos.com',
        'wp_user'           => 'admin',
        'wp_app_password'   => Env::get('WP_PASS_GUIADOSCURSOS', ''),
        'site_name'         => 'Guia dos Cursos',
        'whatsapp_number'   => '5571992354308',
        'whatsapp_group_url'=> '',
        'whatsapp_cta_text' => 'Receba ofertas no WhatsApp',
        'pretty_links_prefix' => 'go',
        // Facebook Page "Maria Gusmão" — token vem do .env (compartilha com cursosenac)
        'fb_page_id'        => '101766412913237',
        'fb_page_token'     => Env::get('FB_PAGE_TOKEN_MARIA', ''),
        // Instagram @_guiadoscursos — token vem do .env (IG_TOKEN_GUIADOSCURSOS)
        'ig_user_id'        => '26500297942963184',
        'ig_access_token'   => Env::get('IG_TOKEN_GUIADOSCURSOS', ''),
        'empresa' => [
            'nome'      => 'Sistema 2 Conteúdo Educacional',
            'descricao' => 'Editora brasileira especializada em ensino superior, vestibulares, FIES, ProUni e MBA',
            'cnpj'      => '',
        ],
        'subtipo_nicho' => 'ensino superior, graduação EAD/presencial, vestibulares, FIES, ProUni, pós-graduação e MBA',
        'termos_canibal' => [
            // vagasebeneficios cobre INSS/BPC/benefícios
            'inss', 'aposentadoria', 'bpc', 'bolsa família', 'fgts', 'pis/pasep', 'auxílio gás',
            'concurso público federal', 'concurso estadual', 'vaga clt',
            // cursosenac cobre cursos técnicos/EAD profissionalizante
            'curso senac', 'curso senai', 'curso técnico', 'qualificação profissional senac',
            'enem inep', 'inscrição enem',
        ],
        'persona' => [
            'autor'            => 'Redação Guia dos Cursos',
            'voz'              => 'mentor de carreira, pragmático sobre ROI de cada formação, sem romantizar diploma',
            'especialidade'    => 'graduação EAD/presencial, pós e MBA, certificações profissionais, bolsas e descontos, comparativos de universidades',
            'audiencia'        => 'adultos 20-45 pensando em graduação/pós, profissionais em transição, quem busca qualificação paga',
            'tom'              => 'realista sobre mercado, mostra salário-hora-esforço, alertivo sobre faculdades com pouca empregabilidade',
            'clusters_foco'    => ['noticias_info_critica', 'negocios_financas'],
            'termos_proibidos' => ['basta ter o diploma', 'faculdade fácil', 'salário garantido', 'sucesso certo'],
            'cta_estilo'       => 'comparar faculdades · simular financiamento · pedir desconto · ver nota MEC',
        ],
        'author_box_inline'        => false,
        'breadcrumb_inline'        => false,
        'rankmath_handles_schemas' => true,
        'trend_max_idade_horas'    => 24,
     ],
     'leaodabarra' => [
         'name'              => 'Leão da Barra',
         'wp_url'            => 'https://leaodabarra.com.br',
         'wp_user'           => 'admin',
         'wp_app_password'   => Env::get('WP_PASS_LEAODABARRA', ''),
         'site_name'         => 'Leão da Barra',
         'whatsapp_number'   => '71992354308',
         'whatsapp_group_url'=> '',
         'whatsapp_cta_text' => 'Receba alertas de jogos do Vitória no WhatsApp',
         'pretty_links_prefix' => 'go',
         'empresa' => [
             'nome'      => 'Sistema 3 Mídia Digital',
             'descricao' => 'Editora brasileira especializada na cobertura editorial do Esporte Clube Vitória (BA) — Brasileirão Série A, Copa do Nordeste, Copa do Brasil, Campeonato Baiano, base e mercado rubro-negro',
             'cnpj'      => '',
         ],
         // PIVOT 2026-05-02: leaodabarra virou nicho EXCLUSIVO do Esporte Clube Vitória.
         // Antes cobria esportes em geral (multi-clube, F1, NBA, MMA) — diluía autoridade.
         // Outro domínio será registrado pra cobrir esportes gerais.
         'subtipo_nicho' => 'Esporte Clube Vitória (BA) — Brasileirão Série A 2026, Copa do Brasil, Copa do Nordeste, Campeonato Baiano, mercado da bola rubro-negro, base (sub-20/sub-17), elenco principal, Estádio Manoel Barradas (Barradão), bastidores institucionais e clássico Ba-Vi',
         'termos_canibal' => [
             // comocomprar cobre tech/shopping
             'comparativo de preço', 'review smartphone', 'cashback', 'amazon ofertas',
             // ondecompraragora cobre oferta relâmpago
             'oferta relâmpago', 'cupom expira', 'promoção 24h',
             // vafast cobre cursos/benefícios/finanças
             'curso gratuito', 'curso ead', 'bolsa família', 'bpc', 'auxílio gás', 'inss', 'fgts',
             'consignado', 'cartão de crédito sem anuidade', 'investimento renda fixa', 'tesouro direto',
             // Nicho exclusivo Vitória — outros clubes/modalidades viram canibal
             // (vão pro futuro domínio de esportes gerais, ainda não registrado)
             'flamengo escalação', 'flamengo desfalque', 'palmeiras escalação',
             'corinthians escalação', 'são paulo escalação', 'cruzeiro escalação',
             'atlético-mg escalação', 'fluminense escalação', 'botafogo escalação',
             'vasco escalação', 'grêmio escalação', 'internacional escalação',
             'fórmula 1', 'gp de', 'corrida da', 'pole position',
             'nba', 'wnba', 'ufc', 'mma luta',
             'vôlei superliga', 'kudiess', 'tandara',
         ],
         // ─── NICHO EXCLUSIVO ESPORTE CLUBE VITÓRIA ───
         // Trend só é aceito se contém 1+ termo desta lista. Caso contrário, status='fora_escopo_vitoria'.
         // 5 grupos de discriminação:
         //   A) apelidos/identidade do clube (alto discriminante)
         //   B) pessoas únicas (presidente, técnico, elenco — extraídas Wikipedia 2026-05-02)
         //   C) padrões esportivos com "vitória" (verbos esportivos comuns que distinguem clube
         //      de "vitória=conquista". Caso #742 2026-05-02: "Vitória empata com Avaí no Sub-20"
         //      foi bloqueado errado porque só "vitória" sozinho não estava na lista.)
         //   D) competições e clássicos
         //   E) domínios e marcas oficiais
         'nicho_required_terms' => [
             // A — identidade do clube (sem ambiguidade)
             'esporte clube vitória', 'ec vitória', 'leão da barra', 'leao da barra',
             'rubro-negro baiano', 'rubronegro baiano', 'rubro-negro do vitória',
             'barradão', 'barradao', 'estádio manoel barradas', 'manoel barradas',
             'lelê leão', 'fábrica de talentos',
             // B — pessoas únicas
             // Diretoria
             'fábio mota', 'fabio mota',
             // Técnico
             'jair ventura',
             // Goleiros
             'lucas arcanjo', 'gabriel vasconcellos', 'yuri sena', 'fintelman',
             // Zagueiros
             'camutanga', 'riccieli',
             // Laterais
             'ramon ramos', 'luan cândido', 'jamerson', 'matheusinho vitória',
             'nathan gabriel', 'claudinho vitória',
             // Volantes
             'rúben ismael', 'gabriel baralhas', 'caíque gonçalves',
             // Meias
             'matheuzinho', 'lucas silva vitória', 'emmanuel martínez', 'aitor cantalapiedra',
             // Atacantes
             'kike saverio', 'fabri vitória', 'renzo lópez', 'erick serafim',
             'pedro henrique vitória', 'diego tarzia', 'mário sérgio vitória',
             'osvaldo filho', 'renato kayzer', 'anderson bispo',
             // C — padrões esportivos com "vitória" (clube, não conquista)
             // Confronto / partida
             'vitória x ', 'x vitória', 'vitória vs ', 'vs vitória', 'vitória contra ',
             // Verbos de jogo
             'vitória vence', 'vitória venceu', 'vitória empata', 'vitória empatou',
             'vitória perde', 'vitória perdeu', 'vitória goleou', 'vitória derrota',
             'vitória de virada', 'vitória nos pênaltis', 'vitória nos acréscimos',
             'vitória sofre', 'vitória sofreu',
             // Mercado / institucional
             'vitória contrata', 'vitória contratou', 'vitória anuncia', 'vitória anunciou',
             'vitória rescinde', 'vitória rescindiu', 'vitória demite', 'vitória demitiu',
             'vitória aprova', 'vitória negocia', 'vitória oficializa', 'vitória oficializou',
             'vitória renova', 'vitória renovou', 'vitória apresenta', 'vitória apresentou',
             'vitória contrata', 'vitória dispensa', 'vitória inscreve',
             'vitória relaciona', 'vitória relacionados',
             // Status
             'vitória escala', 'vitória escalado', 'vitória escalação', 'escalação do vitória',
             'time do vitória', 'elenco do vitória', 'desfalque do vitória', 'desfalques do vitória',
             'titular do vitória', 'titulares do vitória', 'reserva do vitória', 'reservas do vitória',
             'gol do vitória', 'gols do vitória', 'penalti do vitória', 'pênalti do vitória',
             'expulsão do vitória', 'cartão amarelo do vitória', 'lesão no vitória',
             'pré-jogo vitória', 'pre-jogo vitória', 'pós-jogo vitória', 'pos-jogo vitória',
             // Diretoria/SAF
             'saf vitória', 'saf do vitória', 'sócio vitória', 'sócios vitória',
             'eleição vitória', 'presidente do vitória',
             // Time / classificação
             'jogo do vitória', 'jogos do vitória', 'tabela do vitória', 'classificação do vitória',
             'situação do vitória', 'campanha do vitória', 'sequência do vitória',
             // D — competições e clássicos (Vitória atua)
             'ba-vi', 'bavi', 'clássico ba-vi',
             'campeonato baiano', 'baianão', 'baiano sub-20', 'baiano sub-17',
             // E — domínios e marcas
             'ecvitoria.com.br', 'vitória na tv', 'vitorianatv', '@vitorianatv',
         ],
         // Author box + breadcrumb visual + schemas duplicados desabilitados —
         // tema WP + RankMath já renderizam tudo isso. RankMath gera NewsArticle
         // + BreadcrumbList + Person + Organization + WebPage automaticamente.
         // Continuamos gerando schemas rich que RankMath não cobre: FAQPage,
         // HowTo, ItemList, Course, Event, SportsEvent (quando ativar).
         'author_box_inline'        => false,
         'breadcrumb_inline'        => false,
         'rankmath_handles_schemas' => true,
         // Esporte SEMPRE tem foto real na fonte (jogador, jogo, treino) — Pexels
         // entrega cara genérica chutando bola. og:image da fonte (ge.globo,
         // bahianoticias, lance) é foto contextual real. og_only força usar.
         'imagem_featured_estrategia' => 'og_only',
         // Esporte muda rápido: pré-jogo D-3 + pós-jogo D+1 = 4 dias úteis máx
         'trend_max_idade_horas'    => 96,
         // Esportes raramente passa de score 7.0 (faixa típica 5.5-7.0 vista no pingo).
         // Threshold global default 7.0 desviava 100% dos trends esportivos pra GPT-mini,
         // que aluvina nomes de técnicos/URLs e ignora o manifesto. Threshold 5.5 alinhado
         // com scoring de fonte do leaodabarra → mantém Sonnet como principal.
         'trend_scoring_threshold' => 5.5,
         // Esportes tem texto jornalístico naturalmente curto (notícia de jogo / mercado /
         // história individual: 1500-3000 chars). Defaults globais (1200/3000/4000) rejeitavam
         // trends como Hulk e Kudiess (1700-1900 chars de fonte única). Override mais permissivo:
         //   - aceita 1 fonte com ≥1500 chars (era 4000)
         //   - aceita 2+ fontes somando ≥2000 chars (era 3000)
         //   - mantém min por fonte em 800 (era 1200) pra acolher resumos de notícia esportiva
         'fontes_min_por_fonte'  => 800,
         'fontes_min_agregado'   => 2000,
         'fontes_min_fonte_solo' => 1500,
         'persona' => [
             'autor'            => 'Redação Leão da Barra',
             'voz'              => 'jornalismo investigativo dedicado ao Esporte Clube Vitória, voz de quem mora em Salvador e respira o Barradão; respeita o leitor rubro-negro com informação verificada, sem fanatismo, sem apostas, sem ofender a rivalidade Ba-Vi. Apura na fonte (clube oficial, CBF, Federação Baiana, comunicados, entrevistas) e contextualiza pra quem acompanha o Leão há décadas',
             'especialidade'    => 'Esporte Clube Vitória 100% — elenco 2026 (Lucas Arcanjo, Camutanga, Riccieli, Jamerson, Ronald, Matheuzinho, Erick Serafim, Renato Kayzer, Osvaldo Filho, Renzo López e companheiros), técnico Jair Ventura, presidente Fábio Mota, Estádio Manoel Barradas (Barradão), Brasileirão Série A 2026, Copa do Brasil 2026, Copa do Nordeste 2026, Campeonato Baiano, mercado da bola rubro-negro, base sub-20 e sub-17 (Fábrica de Talentos), bastidores institucionais (eleições, finanças, SAF), histórico do clube desde 1899, clássico Ba-Vi (rivalidade com Esporte Clube Bahia), Copa do Nordeste (campeão 1997, 1999, 2003, 2010), 30 títulos baianos',
             'audiencia'        => 'torcedor rubro-negro do Vitória — baiano, residente em Salvador e interior da Bahia, ou exportado pra outras capitais; conhece a história do clube, acompanha o Barradão presencial ou via Vitória na TV/Premiere; busca info útil, escalação confirmada, mercado e bastidores. Idade ampla (18-65), engajamento alto em rede social, fidelidade ao clube acima do bairrismo institucional',
             'tom'              => 'rubro-negro identitário SEM fanatismo cego: critica diretoria, técnico e jogadores quando há base factual; celebra vitória sem clichê; trata Bahia (rival) com respeito mas sem apologia; frase curta na intro, narrativa nas análises; usa "Leão", "Rubro-Negro", "rubro-negro" como sinônimos do clube; jamais "Galo", "Mengão", "Verdão" (esses são outros clubes)',
             'clusters_foco'    => ['esportes'],
             'termos_proibidos' => [
                 // Apostas (manifesto editorial Discover proíbe)
                 'aposte agora', 'palpite garantido', 'odds', 'casa de apostas', 'tip seguro',
                 'cravo', 'palpiteiro',
                 // Clichês de torcida
                 'galera do gol', 'maravilha', 'sensacional', 'magnífico', 'imbatível',
                 // Anti-fanatismo (manifesto)
                 'tricolor lixo', 'bahia lixo', 'rivalreco', 'ladrão',
                 // Clubes irmãos (não é nicho — vai pro outro domínio)
                 'flamengo titular', 'palmeiras titular', 'corinthians titular',
             ],
             'cta_estilo'       => 'ver onde assistir o Vitória · ver escalação confirmada · acompanhar Leão tempo real · ver tabela do Brasileirão · ler última coletiva de Jair Ventura',
         ],
         // ─── GLOSSÁRIO DE BACKLINKS INTERNOS (cluster topical authority) ───
         // Termos recorrentes que aparecem em quase todo artigo de Vitória → URLs canônicas.
         // Aplicado pelo InternalLinkGlossary no PostProcess. 1ª ocorrência por termo, fora
         // de h1-3/table/details/anchors. Termos longos têm prioridade ('Esporte Clube
         // Vitória' antes de 'Vitória').
         //
         // IMPORTANTE: cada URL DEVE existir no WP — link 404 fere autoridade. Antes de
         // ativar um termo aqui, criar a página correspondente como hub editorial.
         'internal_link_glossary' => [
             // Identidade do clube → página história
             'Esporte Clube Vitória' => '/historia-do-esporte-clube-vitoria/',
             'EC Vitória'            => '/historia-do-esporte-clube-vitoria/',
             // Competições (categorias WP — não são hubs)
             'Copa do Nordeste'      => '/category/copa-do-nordeste/',
             'Copa do Brasil'        => '/category/copa-do-brasil/',
             'Brasileirão Série A'   => '/category/brasileirao-2026/',
             'Brasileirão'           => '/category/brasileirao-2026/',
             'Campeonato Baiano'     => '/category/campeonato-baiano/',
             'Baianão'               => '/category/campeonato-baiano/',
             // Estádio
             'Barradão'                => '/barradao/',
             'Estádio Manoel Barradas' => '/barradao/',
             // Clássico — slug alinhado com data/hubs_vitoria.php
             'Ba-Vi'                 => '/classico-ba-vi/',
             'Clássico Ba-Vi'        => '/classico-ba-vi/',
             // Comissão técnica e diretoria
             'Jair Ventura'          => '/tecnico-jair-ventura/',
             'Fábio Mota'            => '/presidente-fabio-mota/',
             // Elenco
             'Elenco 2026'           => '/elenco-2026/',
             // Títulos históricos
             'Série B 2023'          => '/historia-serie-b-2023/',
             // Identidade
             'Lelê Leão'             => '/mascote-lele-leao/',
             // Jogadores (anchors completas pra evitar match em palavras genéricas)
             'Lucas Arcanjo'         => '/jogador-lucas-arcanjo/',
             'Camutanga'             => '/jogador-camutanga/',
             'Riccieli'              => '/jogador-riccieli/',
             'Renato Kayzer'         => '/jogador-renato-kayzer/',
             'Matheuzinho'           => '/jogador-matheuzinho/',
             'Emmanuel Martínez'     => '/jogador-emmanuel-martinez/',
             'Aitor Cantalapiedra'   => '/jogador-aitor-cantalapiedra/',
             'Ronald'                => '/jogador-ronald/',
             // (jogadores menos icônicos não no glossary pra evitar spam de links)
         ],
     ],
	 'ondecompraragora' => [
         'name'              => 'Onde comprar agora',
         'wp_url'            => 'https://ondecompraragora.com',
         'wp_user'           => 'admin',
         'wp_app_password'   => Env::get('WP_PASS_ONDECOMPRARAGORA', ''),
         'site_name'         => 'Onde comprar agora',
         'whatsapp_number'   => '71992354308',
         'whatsapp_group_url'=> '',
         'whatsapp_cta_text' => 'Receba ofertas no WhatsApp',
         'pretty_links_prefix' => 'go',
         // Amazon Associates BR — preencher quando aprovado
         'amazon_associates_tag' => '',
         'empresa' => [
             'nome'      => 'Sistema 3 Mídia Digital',
             'descricao' => 'Editora brasileira de mídia digital especializada em ofertas relâmpago, calendário promocional e Black Friday',
             'cnpj'      => '',
         ],
         'subtipo_nicho' => 'ofertas relâmpago, promoções de janela curta (24h-72h), Black Friday e datas comerciais',
         'termos_canibal' => [
             // comocomprar cobre comparativos aprofundados
             'comparativo aprofundado', 'review completo', 'guia de compra detalhado',
             // leaodabarra cobre esportes
             'escalação', 'libertadores', 'brasileirão', 'fórmula 1', 'mma', 'nba',
             // vafast cobre cursos/benefícios/finanças
             'curso gratuito', 'curso ead', 'bolsa família', 'bpc', 'auxílio gás', 'inss', 'fgts',
             'consignado', 'cartão de crédito sem anuidade', 'investimento renda fixa', 'tesouro direto',
         ],
         'persona' => [
             'autor'            => 'Redação Onde Comprar Agora',
             'voz'              => 'caçador de oferta relâmpago, fala rápido, destaca janela de tempo (48h, 72h) e escassez real',
             'especialidade'    => 'descontos do dia, promoções relâmpago, Black Friday, cashback, comparação entre marketplaces',
             'audiencia'        => 'compradores impulsivos, caçadores de promo, pessoas que usam WhatsApp e Telegram pra achar oferta',
             'tom'              => 'urgência real (não inventada), mostra ticker de desconto, compara preços em tempo real',
             'clusters_foco'    => ['lifestyle_consumo', 'tecnologia', 'comidas_bebidas'],
             'termos_proibidos' => ['nunca mais vai ter', 'última unidade' , 'só hoje' ],
             'cta_estilo'       => 'ver oferta · garantir enquanto tem · comparar preços · ativar cashback',
         ],
         'author_box_inline'        => false,
         'breadcrumb_inline'        => false,
         'rankmath_handles_schemas' => true,
     ],
     'vafast' => [
         'name'              => 'VaFast',
         'wp_url'            => 'https://vafast.xyz',
         'wp_user'           => 'Redacao',
         'wp_app_password'   => Env::get('WP_PASS_VAFAST', ''),
         'site_name'         => 'VaFast',
         'whatsapp_number'   => '',
         'whatsapp_group_url'=> '',
         'whatsapp_cta_text' => 'Receba alertas no WhatsApp',
         'pretty_links_prefix' => 'go',
         'empresa' => [
             'nome'      => 'Sistema 3 Mídia Digital',
             'descricao' => 'Editora brasileira de mídia digital especializada em cursos rápidos, programas sociais e finanças pessoais',
             'cnpj'      => '',
         ],
         'subtipo_nicho' => 'cursos rápidos/online, programas sociais (Bolsa Família, BPC, FGTS, INSS) e finanças pessoais (consignado, cartão, investimentos básicos)',
         'termos_canibal' => [
             // comocomprar cobre guias de compra/comparativos
             'comparativo de preço', 'review smartphone', 'cashback amazon', 'guia de compra detalhado',
             // ondecompraragora cobre oferta relâmpago/janela curta
             'oferta relâmpago', 'cupom expira', 'promoção 24h', 'só hoje', 'janela de 24h',
             // leaodabarra cobre esportes
             'escalação', 'libertadores', 'brasileirão', 'onde assistir jogo', 'fórmula 1', 'mma', 'nba',
         ],
         'persona' => [
             'autor'            => 'Redação VaFast',
             'voz'              => 'serviço rápido e prático, traduz burocracia em passo-a-passo, mostra valor e prazo nas primeiras linhas',
             'especialidade'    => 'cursos rápidos online/presenciais, programas sociais (Bolsa Família, BPC, FGTS, INSS, PIS/PASEP), finanças pessoais (consignado, cartões sem anuidade, Tesouro Direto, renda fixa básica)',
             'audiencia'        => 'adultos 25-55 que buscam qualificação rápida, beneficiários de programas sociais e quem quer organizar finanças sem jargão de banco',
             'tom'              => 'urgente em prazos, claro em valores, alertivo em golpes, didático em primeiros passos — sem promessa de enriquecimento',
             'clusters_foco'    => ['noticias_info_critica', 'negocios_financas'],
             'termos_proibidos' => ['enriqueça rápido', 'dinheiro fácil', 'dica infalível', 'segredo do banco', 'milagre financeiro', 'receba sem burocracia'],
             'cta_estilo'       => 'consultar calendário · ver curso disponível · simular benefício · comparar cartão',
         ],
     ],
    // Exemplo de segundo site (preencha quando adicionar):
    // 'site2' => [
    //     'name'              => 'Nome do Site 2',
    //     'wp_url'            => 'https://site2.com.br',
    //     'wp_user'           => 'admin',
    //     'wp_app_password'   => 'xxxx xxxx xxxx xxxx xxxx xxxx',
    //     'site_name'         => 'Site 2',
    //     'whatsapp_number'   => '',
    //     'whatsapp_group_url'=> '',
    //     'whatsapp_cta_text' => 'Receba ofertas no WhatsApp',
    //     'pretty_links_prefix' => 'go',
    // ],
];
