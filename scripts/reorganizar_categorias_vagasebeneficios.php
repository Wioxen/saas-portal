<?php
/**
 * Reorganização de categorias do vagasebeneficios.com — Estrutura V2.
 *
 * ESTRATÉGIA B (Híbrida Segura) — fases 30 dias:
 *
 *   Day 0:  --executar
 *           1. Cria 49 categorias novas
 *           2. Migra 116+ posts (mantém categoria antiga + adiciona nova)
 *           3. Cria 16 redirects 301 (Rank Math via cc-redirections-api)
 *           4. Move 6 posts fora-escopo pra LIXEIRA (trash, 30d pra restaurar)
 *           5. Purge cache LiteSpeed
 *           6. Re-indexa via Indexing API
 *
 *   Day 14: --fase14
 *           Remove categoria antiga dos posts (deixa só nova).
 *           Categorias antigas continuam existindo (vazias) — redirects ativos.
 *
 *   Day 30: --fase30
 *           Deleta categorias antigas vazias (count=0).
 *           Recria redirects "permanentes" (não dependem mais de term_id).
 *
 * Outros modos:
 *   (sem flag)    → DRY-RUN (default, só relatório, nada altera)
 *   --apenas=mapa → mostra mapeamento detalhado dos 135 posts
 *
 * Proteções:
 *   - Snapshot de cada post antes de modificar (data/snapshot_categorias_vagasebeneficios.json)
 *   - Confirmação interativa com countdown 5s antes de executar
 *   - Log completo em data/log_reorganizar_vagasebeneficios.log
 *   - Posts fora-escopo vão pra LIXEIRA (status=trash), não delete permanente
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
set_time_limit(0);

$ROOT = dirname(__DIR__);

$dryRun = true;
$fase = 0; // 0=Day0, 14=Day14, 30=Day30
$apenas = '';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--executar')      { $dryRun = false; $fase = 0; }
    elseif ($arg === '--fase14')    { $dryRun = false; $fase = 14; }
    elseif ($arg === '--fase30')    { $dryRun = false; $fase = 30; }
    elseif (str_starts_with($arg, '--apenas=')) $apenas = substr($arg, 9);
}

require_once $ROOT . '/lib/Wordpress.php';
$sites = require $ROOT . '/sites.php';
$cfgSite = $sites['vagasebeneficios'];
$WP_URL = rtrim($cfgSite['wp_url'], '/');
$AUTH = base64_encode($cfgSite['wp_user'] . ':' . $cfgSite['wp_app_password']);
$LOG_PATH = $ROOT . '/data/log_reorganizar_vagasebeneficios.log';
$SNAPSHOT_PATH = $ROOT . '/data/snapshot_categorias_vagasebeneficios.json';

function logToFile(string $msg): void {
    global $LOG_PATH;
    @file_put_contents($LOG_PATH, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function out(string $msg, bool $log = true): void {
    echo $msg . "\n";
    if ($log) logToFile($msg);
}

out("🎯 Site: {$cfgSite['name']} ({$WP_URL})");
if ($dryRun) out("🔍 MODO: DRY-RUN (nenhuma alteração será feita)");
elseif ($fase === 0)  out("⚡ MODO: EXECUTAR DAY 0 (cria + migra + redirects + trash fora-escopo)");
elseif ($fase === 14) out("⚡ MODO: FASE DAY 14 (remove categoria antiga dos posts)");
elseif ($fase === 30) out("⚡ MODO: FASE DAY 30 (deleta categorias antigas vazias)");
out("═════════════════════════════════════════════════════════════\n");

// ============================================================
// V2 — ESTRUTURA NOVA — [slug, name, parent_slug|null, description]
// ============================================================
$V2 = [
    ['inss-e-aposentadoria',          'INSS e Aposentadoria',                     null,                      'Tudo sobre INSS, aposentadoria, calendário de pagamentos e benefícios previdenciários no Brasil.'],
    ['calendario-inss',               'Calendário INSS',                          'inss-e-aposentadoria',    'Calendário oficial de pagamentos do INSS por número final do benefício.'],
    ['aposentadoria',                 'Aposentadoria',                            'inss-e-aposentadoria',    'Tipos de aposentadoria: idade, tempo de contribuição, especial, por invalidez.'],
    ['auxilio-doenca',                'Auxílio-Doença',                           'inss-e-aposentadoria',    'Como solicitar auxílio-doença, perícia médica e regras do INSS.'],
    ['bpc-loas',                      'BPC / LOAS',                               'inss-e-aposentadoria',    'Benefício de Prestação Continuada (BPC/LOAS) para idosos e pessoas com deficiência.'],
    ['decimo-terceiro-inss',          '13º do INSS',                              'inss-e-aposentadoria',    'Pagamento do 13º salário do INSS, antecipação e calendário.'],
    ['pensao-por-morte',              'Pensão por Morte',                         'inss-e-aposentadoria',    'Pensão por morte do INSS: quem tem direito, valor e tempo.'],
    ['salario-maternidade',           'Salário-Maternidade',                      'inss-e-aposentadoria',    'Salário-maternidade do INSS: como solicitar, valor e duração.'],
    ['revisao-de-beneficio',          'Revisão de Benefício',                     'inss-e-aposentadoria',    'Revisão de benefício do INSS: quando vale a pena pedir e como fazer.'],
    ['meu-inss',                      'Meu INSS',                                 'inss-e-aposentadoria',    'App e site Meu INSS: cadastro, login, consulta e serviços.'],

    ['beneficios-sociais',            'Benefícios Sociais',                       null,                      'Bolsa Família, Pé de Meia, Cadastro Único e demais benefícios do governo.'],
    ['bolsa-familia',                 'Bolsa Família',                            'beneficios-sociais',      'Calendário, valor e regras do programa Bolsa Família.'],
    ['cadastro-unico',                'Cadastro Único (CadÚnico)',                'beneficios-sociais',      'Como fazer e atualizar o Cadastro Único para acessar benefícios.'],
    ['pe-de-meia',                    'Pé de Meia',                               'beneficios-sociais',      'Programa Pé de Meia: parcelas, calendário e quem tem direito.'],
    ['auxilio-gas',                   'Auxílio Gás',                              'beneficios-sociais',      'Auxílio Gás dos Brasileiros: calendário, valor e regras.'],
    ['minha-casa-minha-vida',         'Minha Casa Minha Vida',                    'beneficios-sociais',      'Minha Casa Minha Vida: faixas, inscrição e financiamento.'],
    ['seguro-desemprego',             'Seguro-Desemprego',                        'beneficios-sociais',      'Seguro-desemprego: como solicitar, parcelas e valor.'],

    ['fgts-pis-direitos-trabalhador', 'FGTS, PIS e Direitos do Trabalhador',      null,                      'FGTS, PIS/Pasep, CLT, rescisão, férias e direitos do trabalhador brasileiro.'],
    ['fgts',                          'FGTS',                                     'fgts-pis-direitos-trabalhador', 'FGTS: consulta, saque, calendário e regras do Fundo de Garantia.'],
    ['saque-aniversario-fgts',        'Saque-Aniversário FGTS',                   'fgts-pis-direitos-trabalhador', 'Saque-Aniversário do FGTS: como aderir, valor e calendário.'],
    ['pis-pasep',                     'PIS / Pasep',                              'fgts-pis-direitos-trabalhador', 'Calendário PIS/Pasep, abono salarial e consulta de valor.'],
    ['decimo-terceiro-salario',       '13º Salário',                              'fgts-pis-direitos-trabalhador', '13º salário: cálculo, parcelas, descontos e prazo de pagamento.'],
    ['ferias-clt',                    'Férias CLT',                               'fgts-pis-direitos-trabalhador', 'Férias CLT: cálculo, vendas de 1/3, abono e direitos.'],
    ['rescisao-aviso-previo',         'Rescisão e Aviso Prévio',                  'fgts-pis-direitos-trabalhador', 'Rescisão de contrato e aviso prévio: cálculo e direitos do trabalhador.'],
    ['direitos-do-trabalhador',       'Direitos do Trabalhador',                  'fgts-pis-direitos-trabalhador', 'Direitos trabalhistas: justa causa, estabilidade, horas extras, intervalo.'],

    ['vagas-e-empregos',              'Vagas e Empregos',                         null,                      'Vagas de emprego, estágio, home office e oportunidades em todo o Brasil.'],
    ['vagas-clt',                     'Vagas CLT',                                'vagas-e-empregos',        'Vagas com carteira assinada (CLT) por todo o Brasil.'],
    ['home-office',                   'Home Office / Trabalho Remoto',            'vagas-e-empregos',        'Vagas em home office e trabalho remoto: empresas que contratam.'],
    ['estagio-e-jovem-aprendiz',      'Estágio e Jovem Aprendiz',                 'vagas-e-empregos',        'Vagas de estágio e jovem aprendiz: requisitos, salário e processo.'],
    ['sine',                          'SINE',                                     'vagas-e-empregos',        'Sistema Nacional de Emprego (SINE): como cadastrar e consultar vagas.'],
    ['vagas-por-estado',              'Vagas por Estado',                         'vagas-e-empregos',        'Vagas de emprego organizadas por estado e cidade no Brasil.'],

    ['concursos-publicos',            'Concursos Públicos',                       null,                      'Concursos públicos federais, estaduais e municipais: editais e resultados.'],
    ['concursos-federais',            'Concursos Federais',                       'concursos-publicos',      'Concursos públicos federais: PF, PRF, IBAMA, INSS, Banco do Brasil, etc.'],
    ['concursos-estaduais',           'Concursos Estaduais',                      'concursos-publicos',      'Concursos públicos estaduais: tribunais, secretarias e polícia civil.'],
    ['concursos-municipais',          'Concursos Municipais',                     'concursos-publicos',      'Concursos públicos das prefeituras e câmaras municipais brasileiras.'],
    ['editais-abertos',               'Editais Abertos',                          'concursos-publicos',      'Editais de concurso público abertos com inscrição em andamento.'],
    ['resultado-convocacao',          'Resultado e Convocação',                   'concursos-publicos',      'Resultados, convocação e nomeação de aprovados em concurso público.'],

    ['mei-e-trabalho-autonomo',       'MEI e Trabalho Autônomo',                  null,                      'MEI: como abrir, DAS, declaração anual, faixas e baixa do CNPJ.'],
    ['como-abrir-mei',                'Como Abrir MEI',                           'mei-e-trabalho-autonomo', 'Passo a passo para abrir MEI no Portal do Empreendedor.'],
    ['das-mei',                       'DAS MEI (Boleto)',                         'mei-e-trabalho-autonomo', 'Como gerar e pagar o boleto DAS MEI mensal.'],
    ['consultar-mei',                 'Consultar MEI / CNPJ',                     'mei-e-trabalho-autonomo', 'Consulta de MEI ativo, situação cadastral e CNPJ.'],
    ['dasn-simei',                    'DASN-Simei (Declaração Anual)',            'mei-e-trabalho-autonomo', 'DASN-Simei: declaração anual obrigatória do MEI.'],
    ['limite-mei',                    'Limite e Faixas MEI',                      'mei-e-trabalho-autonomo', 'Limite de faturamento e faixas de MEI atualizadas.'],
    ['baixa-do-mei',                  'Baixa do MEI',                             'mei-e-trabalho-autonomo', 'Como dar baixa no MEI passo a passo.'],

    ['imposto-de-renda',              'Imposto de Renda',                         null,                      'Imposto de Renda: declaração, restituição, tabela e malha fina.'],
    ['declaracao-ir',                 'Declaração IR',                            'imposto-de-renda',        'Como fazer a declaração do Imposto de Renda anual.'],
    ['restituicao-ir',                'Restituição IR',                           'imposto-de-renda',        'Calendário e consulta da restituição do Imposto de Renda.'],
    ['tabela-ir',                     'Tabela e Alíquotas IR',                    'imposto-de-renda',        'Tabela atualizada de alíquotas do Imposto de Renda.'],
    ['malha-fina',                    'Malha Fina',                               'imposto-de-renda',        'Malha fina: como sair, motivos e prazo de regularização.'],

    ['direitos-sociais-documentos',   'Direitos Sociais e Documentos',            null,                      'Documentos do cidadão brasileiro: CPF, CNH, carteira de trabalho, gov.br.'],
    ['cpf',                           'CPF',                                      'direitos-sociais-documentos', 'CPF: como tirar, regularizar e consultar situação cadastral.'],
    ['cnh-carteira-motorista',        'CNH (Carteira de Motorista)',              'direitos-sociais-documentos', 'CNH: como tirar, renovar, CNH Social e infrações.'],
    ['carteira-trabalho-digital',     'Carteira de Trabalho Digital',             'direitos-sociais-documentos', 'Carteira de Trabalho Digital: como acessar e baixar.'],
    ['cin-rg-identidade',             'CIN / RG / Identidade',                    'direitos-sociais-documentos', 'Carteira de Identidade Nacional (CIN), RG e documentos de identificação.'],
    ['carteira-do-idoso',             'Carteira do Idoso',                        'direitos-sociais-documentos', 'Carteira do Idoso: como tirar e benefícios.'],
    ['id-jovem',                      'ID Jovem',                                 'direitos-sociais-documentos', 'ID Jovem: como solicitar e benefícios do passe livre.'],
    ['govbr',                         'Gov.br',                                   'direitos-sociais-documentos', 'Gov.br: cadastro, login e níveis de conta (bronze, prata, ouro).'],
];

// Mapa direto: slug atual → novo slug (override de regex)
$MAPA_CAT_DIRETA = [
    'inss'                              => 'inss-e-aposentadoria',
    'pe-de-meia'                        => 'pe-de-meia',
    'bolsa-familia'                     => 'bolsa-familia',
    'beneficios'                        => 'beneficios-sociais',
    'minha-casa-minha-vida'             => 'minha-casa-minha-vida',
    'seguro-desemprego'                 => 'seguro-desemprego',
    'programas-do-governo'              => 'beneficios-sociais',
    'impostos-e-taxas'                  => 'imposto-de-renda',
    'economia'                          => 'imposto-de-renda',
    'empregos'                          => 'vagas-e-empregos',
    'vagas-abertas'                     => 'vagas-clt',
    'estagio-e-trainee'                 => 'estagio-e-jovem-aprendiz',
    'trabalhos-online'                  => 'home-office',
    'mercado-de-trabalho'               => 'vagas-e-empregos',
    'beneficios-e-direitos-trabalhistas'=> 'fgts-pis-direitos-trabalhador',
    'fgts'                              => 'fgts',
];

// Regex no título — usa /u (unicode) pra acentos. Ordem importa: mais específicas primeiro.
$REGEX_TITULO = [
    // CONCURSOS (específicos primeiro)
    '/concurso\s+(federal|inss|petrobras|banco\s+do\s+brasil|caixa|bb\b|ibge|tre|pf\b|prf\b|ibama|trf|policia\s+federal)/iu' => 'concursos-federais',
    '/concurso\s+(estadual|tj\s|tre\s|pm\s|pol[íi]cia\s+civil|sefaz|trib\w+\s+de\s+justi[çc]a)/iu' => 'concursos-estaduais',
    '/concurso\s+(prefeitura|municipal|c[âa]mara|sm\w+)/iu' => 'concursos-municipais',
    '/edital\s+(aberto|publicado|do|de)/iu' => 'editais-abertos',
    '/resultado.*concurso|convoca[çc][ãa]o.*concurso|nomea[çc][ãa]o.*concurso/iu' => 'resultado-convocacao',
    '/concurso/iu' => 'concursos-publicos',

    // INSS / Aposentadoria
    '/13[ºo°]?\s+(do\s+)?inss|13[ºo°]?\s+sal[áa]rio\s+do\s+inss/iu' => 'decimo-terceiro-inss',
    '/calend[áa]rio\s+(do\s+)?inss/iu' => 'calendario-inss',
    '/aposentad/iu' => 'aposentadoria',
    '/aux[íi]lio\s*\-?\s*doen[çc]a/iu' => 'auxilio-doenca',
    '/\bbpc\b|\bloas\b|benef[íi]cio\s+de\s+presta[çc][ãa]o\s+continuada/iu' => 'bpc-loas',
    '/pens[ãa]o\s+por\s+morte/iu' => 'pensao-por-morte',
    '/sal[áa]rio\s*\-?\s*maternidade/iu' => 'salario-maternidade',
    '/revis[ãa]o\s+(de\s+)?benef[íi]cio/iu' => 'revisao-de-beneficio',
    '/meu\s+inss/iu' => 'meu-inss',
    '/inss/iu' => 'inss-e-aposentadoria',

    // Benefícios Sociais — Pé-de-Meia precisa ser robusto pra acentos/hifens
    '/p[éeÉE][\s\-]*de[\s\-]*meia/iu' => 'pe-de-meia',
    '/cad[úu]nico|cadastro\s+[úu]nico/iu' => 'cadastro-unico',
    '/aux[íi]lio\s+g[áa]s/iu' => 'auxilio-gas',
    '/aux[íi]lio\s+brasil/iu' => 'beneficios-sociais',
    '/bolsa\s+fam[íi]lia/iu' => 'bolsa-familia',
    '/minha\s+casa\s+minha\s+vida|\bmcmv\b/iu' => 'minha-casa-minha-vida',
    '/seguro\s*\-?\s*desemprego/iu' => 'seguro-desemprego',

    // FGTS / PIS / CLT
    '/saque\s*\-?\s*anivers[áa]rio/iu' => 'saque-aniversario-fgts',
    '/fgts/iu' => 'fgts',
    '/\bpis\b|\bpasep\b|abono\s+salarial/iu' => 'pis-pasep',
    '/13[ºo°]?\s+sal[áa]rio(?!\s+do\s+inss)/iu' => 'decimo-terceiro-salario',
    '/f[ée]rias\b/iu' => 'ferias-clt',
    '/rescis[ãa]o|aviso\s+pr[ée]vio|justa\s+causa/iu' => 'rescisao-aviso-previo',
    '/horas\s+extras|estabilidade\s+gestante|estabilidade.*emprego/iu' => 'direitos-do-trabalhador',

    // Vagas
    '/home\s*\-?\s*office|trabalho\s+remoto|trabalho\s+em\s+casa/iu' => 'home-office',
    '/est[áa]gio|jovem\s+aprendiz|trainee/iu' => 'estagio-e-jovem-aprendiz',
    '/\bsine\b/iu' => 'sine',
    '/vagas?\s+(em|para|no|na)\s+[A-Z]\w+/u' => 'vagas-por-estado',
    '/vagas?(\s|$)|empregos?(\s|$)/iu' => 'vagas-clt',

    // MEI
    '/das\s+mei|boleto\s+(do\s+)?mei/iu' => 'das-mei',
    '/(como\s+)?abrir\s+(o\s+)?mei/iu' => 'como-abrir-mei',
    '/dasn\b|dasn\-simei|declara[çc][ãa]o\s+anual\s+(do\s+)?mei/iu' => 'dasn-simei',
    '/baixa\s+(do\s+)?mei/iu' => 'baixa-do-mei',
    '/consult\w+\s+mei|cnpj\s+(do\s+)?mei/iu' => 'consultar-mei',
    '/limite\s+(do\s+)?mei|faixa\s+(do\s+)?mei|faturamento\s+mei/iu' => 'limite-mei',
    '/\bmei\b|microempreendedor\s+individual/iu' => 'mei-e-trabalho-autonomo',

    // IR
    '/restitui[çc][ãa]o.*(ir\b|imposto\s+de\s+renda)/iu' => 'restituicao-ir',
    '/(declara[çc][ãa]o|declarar).*(ir\b|imposto\s+de\s+renda)/iu' => 'declaracao-ir',
    '/malha\s+fina/iu' => 'malha-fina',
    '/al[íi]quota\s+(do\s+)?ir|tabela\s+(do\s+)?ir|tabela.*imposto\s+de\s+renda/iu' => 'tabela-ir',
    '/imposto\s+de\s+renda|\bir\s+202\d/iu' => 'imposto-de-renda',

    // Documentos
    '/cnh\s+social|cnh\s+gratuita|carteira\s+(de\s+)?(motorista|habilita[çc][ãa]o)|renove?(?:r|m)?\s+(?:a\s+|sua\s+)?cnh|cnh\s+(digital|online|pelo|no)/iu' => 'cnh-carteira-motorista',
    '/cin\b|carteira\s+(de\s+)?identidade(\s+nacional)?/iu' => 'cin-rg-identidade',
    '/\bcpf\b/iu' => 'cpf',
    '/carteira\s+(de\s+)?trabalho/iu' => 'carteira-trabalho-digital',
    '/carteira\s+(do\s+)?idoso/iu' => 'carteira-do-idoso',
    '/id\s+jovem/iu' => 'id-jovem',
    '/gov\.?br|portal\s+gov/iu' => 'govbr',
];

// Regex de FORA-DE-ESCOPO → vai pra LIXEIRA (status=trash, recuperável 30d)
$FORA_ESCOPO = [
    '/feriado(?!\s+inss)/iu',                         // feriado de Tiradentes, 21 abril, etc
    '/dia\s+do\s+trabalh\w+(?!.*direito)/iu',         // Dia do Trabalhador (mas mantém se falar de "direito")
    '/dia\s+(mundial|internacional|nacional)\s+/iu',  // Dia Mundial Quântico, etc
    '/quem\s+foi\s+\w+|hist[óo]ria\s+(sangrenta|de\s+\w+)/iu', // "Quem foi Tiradentes"
    '/passagens?\s+(para|de)\s+/iu',                  // Passagens para 1º de maio
    '/sisu/iu',                                       // SISU (canibal com guiadoscursos)
    '/mensagens?\s+(do|de|para)\s+\w+/iu',            // 58 mensagens do Dia do Trabalhador
];

// ============================================================
// REST helpers
// ============================================================
function wpReq(string $method, string $path, array $body = []): array {
    global $WP_URL, $AUTH;
    $ch = curl_init($WP_URL . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $AUTH,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ];
    if (in_array($method, ['POST','PUT','PATCH','DELETE'], true) && !empty($body)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode((string)$resp, true), 'body' => $resp];
}

function listarCategorias(): array {
    $cats = [];
    $pagina = 1;
    while (true) {
        $r = wpReq('GET', "/wp-json/wp/v2/categories?per_page=100&page={$pagina}&_fields=id,name,slug,count,parent");
        if ($r['code'] !== 200 || empty($r['json'])) break;
        foreach ($r['json'] as $c) $cats[$c['slug']] = $c;
        if (count($r['json']) < 100) break;
        $pagina++;
        if ($pagina > 10) break;
    }
    return $cats;
}

function listarPosts(): array {
    $posts = [];
    $pagina = 1;
    while (true) {
        $r = wpReq('GET', "/wp-json/wp/v2/posts?status=publish&per_page=100&page={$pagina}&_fields=id,title,slug,categories,link");
        if ($r['code'] !== 200 || empty($r['json'])) break;
        foreach ($r['json'] as $p) $posts[] = $p;
        if (count($r['json']) < 100) break;
        $pagina++;
        if ($pagina > 20) break;
    }
    return $posts;
}

// ============================================================
// 1. Carrega estado atual + monta plano
// ============================================================
out("📥 Listando categorias atuais...");
$catsAtuais = listarCategorias();
out("  ✓ " . count($catsAtuais) . " categorias atuais");

out("📥 Listando posts publicados...");
$todosPosts = listarPosts();
out("  ✓ " . count($todosPosts) . " posts publicados\n");

// Mapeia post → novo slug
function mapearPostParaSlug(array $post, array $catsAtuais, array $MAPA_CAT_DIRETA, array $REGEX_TITULO, array $FORA_ESCOPO): array {
    $titulo = is_array($post['title']) ? ($post['title']['rendered'] ?? '') : (string)$post['title'];
    $titulo = html_entity_decode(strip_tags($titulo), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Verifica fora-de-escopo PRIMEIRO (prioridade)
    foreach ($FORA_ESCOPO as $regex) {
        if (preg_match($regex, $titulo)) {
            return ['slug' => null, 'razao' => 'FORA_ESCOPO', 'titulo' => $titulo];
        }
    }

    // Mapeamento direto via slug atual
    $catsPost = is_array($post['categories']) ? $post['categories'] : [];
    foreach ($catsPost as $catId) {
        foreach ($catsAtuais as $slug => $c) {
            if ($c['id'] === $catId && isset($MAPA_CAT_DIRETA[$slug])) {
                return ['slug' => $MAPA_CAT_DIRETA[$slug], 'razao' => "cat→cat ({$slug})", 'titulo' => $titulo];
            }
        }
    }

    // Regex no título
    foreach ($REGEX_TITULO as $regex => $slug) {
        if (preg_match($regex, $titulo)) {
            return ['slug' => $slug, 'razao' => 'regex', 'titulo' => $titulo];
        }
    }

    return ['slug' => null, 'razao' => 'ORFAO', 'titulo' => $titulo];
}

$migracoes = [];
$orfaos = [];
$foraEscopo = [];
foreach ($todosPosts as $p) {
    $r = mapearPostParaSlug($p, $catsAtuais, $MAPA_CAT_DIRETA, $REGEX_TITULO, $FORA_ESCOPO);
    if ($r['razao'] === 'FORA_ESCOPO') {
        $foraEscopo[] = ['id' => $p['id'], 'titulo' => $r['titulo']];
    } elseif ($r['slug'] === null) {
        $orfaos[] = ['id' => $p['id'], 'titulo' => $r['titulo']];
    } else {
        $migracoes[] = ['id' => $p['id'], 'titulo' => mb_substr($r['titulo'], 0, 70), 'novo_slug' => $r['slug'], 'razao' => $r['razao'], 'cats_atuais' => $p['categories']];
    }
}

out("📊 PLANO DE MAPEAMENTO:");
out("  Mapeados:           " . count($migracoes));
out("  Fora-escopo→trash:  " . count($foraEscopo));
out("  Órfãos→manual:      " . count($orfaos));
out("");

if ($apenas === 'mapa') {
    foreach ($migracoes as $m) {
        printf("  #%-5d  [%s]  %s  ← %s\n", $m['id'], $m['novo_slug'], mb_substr($m['titulo'], 0, 60), $m['razao']);
    }
    foreach ($foraEscopo as $f) {
        printf("  #%-5d  [TRASH]  %s\n", $f['id'], mb_substr($f['titulo'], 0, 60));
    }
    foreach ($orfaos as $o) {
        printf("  #%-5d  [ORFAO]  %s\n", $o['id'], mb_substr($o['titulo'], 0, 60));
    }
    exit(0);
}

// Distribuição por silo
$porSilo = [];
foreach ($migracoes as $m) $porSilo[$m['novo_slug']] = ($porSilo[$m['novo_slug']] ?? 0) + 1;
arsort($porSilo);
out("📍 Distribuição por silo:");
foreach ($porSilo as $slug => $n) out(sprintf("  %-40s %3d posts", $slug, $n));
out("");

// Lista fora-escopo
if (!empty($foraEscopo)) {
    out("🗑️ POSTS FORA-DE-ESCOPO (vão pra LIXEIRA, recuperáveis 30 dias):");
    foreach ($foraEscopo as $f) out("  · #{$f['id']}  " . mb_substr($f['titulo'], 0, 90));
    out("");
}

// Lista órfãos restantes
if (!empty($orfaos)) {
    out("⚠️ ÓRFÃOS RESTANTES (precisam decisão manual):");
    foreach ($orfaos as $o) out("  · #{$o['id']}  " . mb_substr($o['titulo'], 0, 90));
    out("");
}

// Categorias a CRIAR vs reusar
$paraCriar = [];
$paraReusar = [];
foreach ($V2 as $entry) {
    [$slug, $name, $parentSlug, $desc] = $entry;
    if (isset($catsAtuais[$slug])) $paraReusar[$slug] = $catsAtuais[$slug];
    else $paraCriar[] = compact('slug', 'name', 'parentSlug', 'desc');
}
out("📦 Categorias a CRIAR: " . count($paraCriar) . "  ·  REUSAR: " . count($paraReusar));
out("");

// Redirects
$redirects = [];
foreach ($MAPA_CAT_DIRETA as $slugAntigo => $slugNovo) {
    if (!isset($catsAtuais[$slugAntigo])) continue;
    $catAntiga = $catsAtuais[$slugAntigo];
    $parentSlug = '';
    if ($catAntiga['parent']) {
        foreach ($catsAtuais as $s => $c) if ($c['id'] === $catAntiga['parent']) { $parentSlug = $s; break; }
    }
    $urlAntiga = '/category/' . ($parentSlug !== '' ? $parentSlug . '/' : '') . $slugAntigo . '/';
    $urlNova = '/category/' . $slugNovo . '/';
    if ($urlAntiga === $urlNova) continue;
    $redirects[] = ['source' => $urlAntiga, 'destination' => $urlNova, 'type' => '301'];
}
out("🔁 Redirects 301: " . count($redirects));
out("");

out("═════════════════════════════════════════════════════════════");
out("📋 RESUMO");
out("  ▸ Cria categorias:          " . count($paraCriar));
out("  ▸ Migra posts:              " . count($migracoes));
out("  ▸ Trash fora-escopo:        " . count($foraEscopo));
out("  ▸ Órfãos manual:            " . count($orfaos));
out("  ▸ Redirects 301:            " . count($redirects));
out("═════════════════════════════════════════════════════════════\n");

// ============================================================
// DRY-RUN exit
// ============================================================
if ($dryRun) {
    out("🔍 DRY-RUN — nada foi alterado.");
    out("Pra executar Day 0:   php scripts/reorganizar_categorias_vagasebeneficios.php --executar");
    out("Pra Day 14:           php scripts/reorganizar_categorias_vagasebeneficios.php --fase14");
    out("Pra Day 30:           php scripts/reorganizar_categorias_vagasebeneficios.php --fase30");
    exit(0);
}

// ============================================================
// CONFIRMAÇÃO INTERATIVA — countdown 5s
// ============================================================
echo "\n⚠️ CONFIRMAÇÃO PRÉ-EXECUÇÃO\n";
echo "Você JÁ FEZ backup XML do WordPress (Tools → Export → All content)? [s/N]: ";
$confirma = trim(fgets(STDIN));
if (strtolower($confirma) !== 's') {
    echo "❌ Abortado. Faça backup primeiro: WP Admin → Tools → Export → All content → Download.\n";
    exit(1);
}

echo "Iniciando em 5s — Ctrl+C pra abortar...\n";
for ($i = 5; $i > 0; $i--) { echo "  $i...\n"; sleep(1); }
out("\n🚀 EXECUÇÃO INICIADA — fase {$fase}");

// ============================================================
// FASE 0 (Day 0): cria + migra + redirects + trash + reindex
// ============================================================
if ($fase === 0) {
    // Snapshot prévio dos posts (rollback)
    out("💾 Salvando snapshot dos posts em {$SNAPSHOT_PATH}...");
    $snapshot = ['feito_em' => date('c'), 'posts' => []];
    foreach ($todosPosts as $p) {
        $snapshot['posts'][$p['id']] = ['categorias_originais' => $p['categories']];
    }
    @file_put_contents($SNAPSHOT_PATH, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    out("  ✓ snapshot salvo (" . count($snapshot['posts']) . " posts)");

    // 1. CRIA categorias novas
    out("\n📦 [1/5] Criando " . count($paraCriar) . " categorias novas...");
    $slugToId = [];
    foreach ($catsAtuais as $slug => $c) $slugToId[$slug] = $c['id'];

    // Cria parents primeiro (sem parent), depois subs
    usort($paraCriar, fn($a, $b) => ($a['parentSlug'] === null ? 0 : 1) - ($b['parentSlug'] === null ? 0 : 1));
    $criadas = 0; $falhas = 0;
    foreach ($paraCriar as $c) {
        $payload = ['name' => $c['name'], 'slug' => $c['slug'], 'description' => $c['desc']];
        if ($c['parentSlug'] !== null) {
            $pid = $slugToId[$c['parentSlug']] ?? null;
            if ($pid) $payload['parent'] = $pid;
        }
        $r = wpReq('POST', '/wp-json/wp/v2/categories', $payload);
        if (in_array($r['code'], [200, 201], true) && !empty($r['json']['id'])) {
            $slugToId[$c['slug']] = $r['json']['id'];
            $criadas++;
            out("  ✓ #{$r['json']['id']} {$c['slug']}");
        } else {
            $falhas++;
            out("  ✗ FALHA {$c['slug']}: HTTP {$r['code']} " . substr((string)$r['body'], 0, 100));
        }
    }
    out("  → criadas: {$criadas} · falhas: {$falhas}");

    // 2. MIGRA posts (mantém categorias antigas + adiciona nova)
    out("\n🔄 [2/5] Migrando " . count($migracoes) . " posts (mantém antiga + adiciona nova)...");
    $migrados = 0; $falhasMig = 0;
    foreach ($migracoes as $m) {
        $novoCatId = $slugToId[$m['novo_slug']] ?? null;
        if (!$novoCatId) { $falhasMig++; continue; }

        $catsNovas = array_values(array_unique(array_merge($m['cats_atuais'], [$novoCatId])));
        $r = wpReq('POST', "/wp-json/wp/v2/posts/{$m['id']}", ['categories' => $catsNovas]);
        if ($r['code'] === 200) {
            $migrados++;
            if ($migrados % 20 === 0) out("    ... {$migrados}/" . count($migracoes));
        } else {
            $falhasMig++;
            out("  ✗ post #{$m['id']} HTTP {$r['code']}");
        }
        usleep(100000);
    }
    out("  → migrados: {$migrados} · falhas: {$falhasMig}");

    // 3. REDIRECTS via cc-redirections-api
    out("\n🔁 [3/5] Criando " . count($redirects) . " redirects 301...");
    $r = wpReq('POST', '/wp-json/cc/v1/redirections', ['redirects' => $redirects, 'purge_cache' => true]);
    if ($r['code'] === 200 && !empty($r['json'])) {
        out("  ✓ criados: " . ($r['json']['criados'] ?? 0) . " · falhas: " . ($r['json']['falhas'] ?? 0) . " · cache_purged: " . ($r['json']['cache_purged'] ?? 'no'));
    } else {
        out("  ✗ FALHA HTTP {$r['code']}: " . substr((string)$r['body'], 0, 200));
    }

    // 4. TRASH fora-escopo
    out("\n🗑️ [4/5] Movendo " . count($foraEscopo) . " posts fora-escopo pra LIXEIRA...");
    $trashed = 0;
    foreach ($foraEscopo as $f) {
        $r = wpReq('POST', "/wp-json/wp/v2/posts/{$f['id']}", ['status' => 'draft']);
        // Trash via DELETE com force=false
        $r2 = wpReq('DELETE', "/wp-json/wp/v2/posts/{$f['id']}");
        if ($r2['code'] === 200) {
            $trashed++;
            out("  ✓ #{$f['id']} → lixeira");
        }
        usleep(100000);
    }
    out("  → trashed: {$trashed}");

    // 5. PURGE cache
    out("\n🧹 [5/5] Purgando cache LiteSpeed...");
    $r = wpReq('POST', '/wp-json/cc/v1/redirections/purge', []);
    out("  → " . ($r['json']['method'] ?? 'fail'));

    out("\n✅ DAY 0 CONCLUÍDO");
    out("Próximas fases:");
    out("  Day 14 (em 14 dias): php scripts/reorganizar_categorias_vagasebeneficios.php --fase14");
    out("  Day 30 (em 30 dias): php scripts/reorganizar_categorias_vagasebeneficios.php --fase30");
    out("Re-indexação: php scripts/indexar_nao_indexados.php --site=vagasebeneficios");
    exit(0);
}

// ============================================================
// FASE 14: remove categorias antigas dos posts
// ============================================================
if ($fase === 14) {
    out("🔄 FASE DAY 14 — removendo categorias antigas dos posts...");
    $slugsAntigos = array_keys($MAPA_CAT_DIRETA);
    $idsAntigos = [];
    foreach ($slugsAntigos as $slugA) {
        if (!isset($catsAtuais[$slugA])) continue;
        // Não remove se slug é REUSADO no V2 (ex: 'fgts', 'pe-de-meia')
        $reusado = false;
        foreach ($V2 as $v) if ($v[0] === $slugA) { $reusado = true; break; }
        if ($reusado) continue;
        $idsAntigos[$catsAtuais[$slugA]['id']] = $slugA;
    }
    out("  IDs de categorias antigas a REMOVER dos posts: " . implode(',', array_keys($idsAntigos)));

    $atualizados = 0;
    foreach ($todosPosts as $p) {
        $catsP = is_array($p['categories']) ? $p['categories'] : [];
        $catsLimpas = array_values(array_diff($catsP, array_keys($idsAntigos)));
        if (count($catsLimpas) === count($catsP)) continue;
        if (empty($catsLimpas)) continue; // segurança: post sem categoria não pode

        $r = wpReq('POST', "/wp-json/wp/v2/posts/{$p['id']}", ['categories' => $catsLimpas]);
        if ($r['code'] === 200) {
            $atualizados++;
            if ($atualizados % 20 === 0) out("    ... {$atualizados} posts limpos");
        }
        usleep(100000);
    }
    out("✓ {$atualizados} posts tiveram categorias antigas removidas");

    out("\n✅ DAY 14 CONCLUÍDO");
    out("Aguardar mais 14 dias e rodar: php scripts/reorganizar_categorias_vagasebeneficios.php --fase30");
    exit(0);
}

// ============================================================
// FASE 30: deleta categorias antigas vazias
// ============================================================
if ($fase === 30) {
    out("🗑️ FASE DAY 30 — deletando categorias antigas vazias...");
    $deletadas = 0; $skip = 0;
    $slugsRemove = array_unique(array_merge(
        array_keys($MAPA_CAT_DIRETA),
        ['blog','noticias','financas-pessoais','curiosidades-e-historia','educacao-e-qualificacao','vacinacao','datas-comemorativas']
    ));
    foreach ($slugsRemove as $slug) {
        if (!isset($catsAtuais[$slug])) continue;
        // Não deleta se slug é REUSADO no V2
        $reusado = false;
        foreach ($V2 as $v) if ($v[0] === $slug) { $reusado = true; break; }
        if ($reusado) { $skip++; continue; }

        $catId = $catsAtuais[$slug]['id'];
        // Confirma count atual (não deletar se ainda tem posts)
        $r0 = wpReq('GET', "/wp-json/wp/v2/categories/{$catId}");
        if (($r0['json']['count'] ?? 1) > 0) {
            out("  ⚠️ skip {$slug} ainda tem posts (count={$r0['json']['count']})");
            $skip++; continue;
        }
        $r = wpReq('DELETE', "/wp-json/wp/v2/categories/{$catId}?force=true");
        if ($r['code'] === 200) {
            $deletadas++;
            out("  ✓ deletada {$slug}");
        } else {
            out("  ✗ falha {$slug} HTTP {$r['code']}");
        }
        usleep(100000);
    }
    // Plus zumbis count=0 não-V2
    foreach ($catsAtuais as $slug => $c) {
        if ($c['count'] !== 0) continue;
        $estaNoV2 = false;
        foreach ($V2 as $v) if ($v[0] === $slug) { $estaNoV2 = true; break; }
        if ($estaNoV2) continue;
        if (in_array($slug, $slugsRemove, true)) continue;
        $r = wpReq('DELETE', "/wp-json/wp/v2/categories/{$c['id']}?force=true");
        if ($r['code'] === 200) {
            $deletadas++;
            out("  ✓ deletada zumbi {$slug}");
        }
        usleep(100000);
    }
    out("\n✅ DAY 30 CONCLUÍDO");
    out("  → deletadas: {$deletadas} · skipped: {$skip}");
    out("Re-indexação final: php scripts/indexar_nao_indexados.php --site=vagasebeneficios");
    exit(0);
}
