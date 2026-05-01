<?php
/**
 * Classifica os 178 posts do guiadoscursos nos 8 silos V2 propostos.
 * Usa heurística de palavras-chave no título com priorização (mais específico primeiro).
 *
 * Saída: classificacao-v2.json + classificacao-v2.csv (revisar manualmente)
 */
declare(strict_types=1);

$base = 'C:/Users/Ivan/AppData/Local/Temp/';
$posts = array_merge(
    json_decode(file_get_contents($base.'gdc-p1.json'), true) ?: [],
    json_decode(file_get_contents($base.'gdc-p2.json'), true) ?: []
);

echo "Total posts: ".count($posts)."\n\n";

/**
 * Estrutura V2:
 * [silo => [subcategoria_slug => [name, keywords[], priority]]]
 *
 * Cada subcategoria tem keywords. Maior prioridade vence (mais específico primeiro).
 */
$silos = [
    'concursos-publicos' => [
        'name' => 'Concursos Públicos',
        'subs' => [
            'concursos-federais'         => ['name'=>'Concursos Federais',         'kw'=>['polícia federal','prf ','policia federal','metrô de sp','metro de sp','federal','governo federal','presidencial','tribunal federal','inss','receita federal','ministério','ministerio','petrobras','exército','exercito','esa ',' esa','esa-','sargento','fuzileiro','aeronáutica','aeronautica','marinha','correios','cnu','concurso unificado','bloco 2','tj sc','tjsc','tjmg','tjrs','tjsp','tjrj','tj-sc','ministério da','ministerio da','dpf','prf'],'prio'=>9],
            'concursos-estaduais'        => ['name'=>'Concursos Estaduais',        'kw'=>['detran','polícia civil','policia civil','polícia militar','policia militar','sefaz','secretaria estadual','assembleia legislativa','ses-mg','ses mg',' ses ','tjrs','tjsp','tjmg','tjrj','ufla','ufma','ufrrj','uerj','uesc','uemg','uneb','uems','upe','rs','sp','mg','rj','pe','ba','mt','ma','am','pa','am ','rj ','sp ','pe ','rs ','ba ','docente','docentes','ifrs','ifsp','ifmg','ifes','ifrn','ifg','ifba','ifce','ifma','ifc','if-rs','if-sp','if-mg'],'prio'=>8],
            'concursos-municipais'       => ['name'=>'Concursos Municipais',       'kw'=>['prefeitura','câmara municipal','camara municipal','câmara de','camara de','município','municipio','araguaína','araguaina','garanhuns','surubim','ouro preto','botucatu','gramado','santo andré','santo andre','itapevi','goiânia','goiania','sonora','promissão','promissao'],'prio'=>7],
            'editais-abertos'            => ['name'=>'Editais Abertos',            'kw'=>['edital','edital publicado','edital divulgado'],'prio'=>5],
            'resultado-convocacao'       => ['name'=>'Resultado e Convocação',     'kw'=>['convocação','convocacao','convocados','aprovados','posse','excedentes','classificação','classificacao','contratados','nomeação','nomeacao','7º lugar','aprovado'],'prio'=>6],
            'concursos-publicos-geral'   => ['name'=>'Concursos Públicos',         'kw'=>['concurso público','concursos públicos','concurso publico','concursos publicos','concurso','concursos'],'prio'=>4],
        ],
    ],
    'enem-sisu-prouni' => [
        'name' => 'ENEM, Sisu e ProUni',
        'subs' => [
            'redacao-enem'  => ['name'=>'Redação ENEM',         'kw'=>['redação enem','redacao enem','redação do enem'],'prio'=>10],
            'isencao-enem'  => ['name'=>'Isenção ENEM',         'kw'=>['isenção enem','isencao enem','isenção do enem','isenção da taxa do enem','isencao da taxa','isencao do enem'],'prio'=>10],
            'enem'          => ['name'=>'ENEM',                 'kw'=>['enem','encceja'],'prio'=>9],
            'sisu'          => ['name'=>'Sisu',                 'kw'=>['sisu'],'prio'=>9],
            'prouni'        => ['name'=>'ProUni',               'kw'=>['prouni','pró-uni'],'prio'=>9],
            'fies'          => ['name'=>'Fies',                 'kw'=>['fies'],'prio'=>9],
            'vestibular'    => ['name'=>'Vestibular',           'kw'=>['vestibular','vestibulares'],'prio'=>8],
        ],
    ],
    'cursos-gratuitos' => [
        'name' => 'Cursos Gratuitos com Certificado',
        'subs' => [
            'cursos-senac'         => ['name'=>'Cursos Senac',          'kw'=>['senac'],'prio'=>10],
            'cursos-senai'         => ['name'=>'Cursos Senai',          'kw'=>['senai','sena-i','senai-rn'],'prio'=>10],
            'cursos-senar'         => ['name'=>'Cursos Senar',          'kw'=>['senar'],'prio'=>10],
            'cursos-sebrae'        => ['name'=>'Cursos Sebrae',         'kw'=>['sebrae'],'prio'=>10],
            'cursos-mec'           => ['name'=>'Cursos MEC',            'kw'=>['mec','ministério da educação','ministerio da educacao','livros do mec','idiomas mec','livros mec','minc','ministério da cultura'],'prio'=>10],
            'cursos-usp-fapesp'    => ['name'=>'Cursos USP e Fapesp',   'kw'=>['usp ',' usp','fapesp','univ. de são paulo','universidade de são paulo'],'prio'=>10],
            'fundacao-bradesco'    => ['name'=>'Fundação Bradesco',     'kw'=>['fundação bradesco','bradesco fundação'],'prio'=>10],
            'cursos-fiocruz'       => ['name'=>'Cursos Fiocruz',        'kw'=>['fiocruz'],'prio'=>10],
            'cursos-fatec'         => ['name'=>'Cursos Fatec',          'kw'=>['fatec'],'prio'=>10],
            'cursos-estacio'       => ['name'=>'Cursos Estácio',        'kw'=>['estácio','estacio'],'prio'=>10],
            'cursos-embraer'       => ['name'=>'Cursos Embraer',        'kw'=>['embraer','aeronáutico','aeronautico'],'prio'=>10],
            'cursos-com-certificado'=>['name'=>'Cursos com Certificado','kw'=>['com certificado','certificado'],'prio'=>3],
            'cursos-gratuitos-geral'=>['name'=>'Cursos Gratuitos',      'kw'=>['curso gratuito','cursos gratuitos','curso grátis','cursos grátis','curso grátis','vagas grátis','vagas gratuitas','grátis','gratuito','gratuita','gratis','sem custo','sem mensalidade'],'prio'=>6],
        ],
    ],
    'cursos-tecnicos' => [
        'name' => 'Cursos Técnicos',
        'subs' => [
            'cursos-etec'        => ['name'=>'Cursos Etec',           'kw'=>['etec','escola técnica estadual','escola tecnica estadual'],'prio'=>9],
            'institutos-federais'=> ['name'=>'Institutos Federais',   'kw'=>['ifes','ifsuldeminas','ifmg','ifrj','ifsp','ifrn','ifba','ifg','ifgo','ifce','ifpe','if-','if ','instituto federal','institutos federais'],'prio'=>9],
            'tecnicos-estaduais' => ['name'=>'Cursos Técnicos Estaduais','kw'=>['uems','upe','ufrrj','uerj','uesc','uemg','uneb','universidade estadual'],'prio'=>8],
            'cursos-tecnicos-geral'=>['name'=>'Cursos Técnicos',      'kw'=>['curso técnico','cursos técnicos','técnico em','tecnico em'],'prio'=>4],
        ],
    ],
    'profissionalizantes' => [
        'name' => 'Profissionalizantes e Áreas',
        'subs' => [
            'inteligencia-artificial' => ['name'=>'Inteligência Artificial e Tech', 'kw'=>['inteligência artificial','inteligencia artificial',' ia ','ia para','ia em','ia no','marketing e ia','transição 4.0','transicao 4.0','5.0','segurança digital','seguranca digital','programação','programacao'],'prio'=>9],
            'saude-bem-estar'         => ['name'=>'Saúde e Bem-Estar',              'kw'=>['sus','enfermagem','saúde','saude','medicina','psicologia','nutrição','nutricao','farmácia'],'prio'=>8],
            'administracao-gestao'    => ['name'=>'Administração e Gestão',         'kw'=>['administração','administracao','gestão','gestao','liderança','lideranca','rh','recursos humanos','contabilidade','fiscal','tributário','tributario'],'prio'=>8],
            'marketing-vendas'        => ['name'=>'Marketing e Vendas',             'kw'=>['marketing','vendas','digital','redes sociais'],'prio'=>7],
            'manualidades'            => ['name'=>'Manualidades e Artesanato',      'kw'=>['costura','artesanato','culinária','culinaria','padaria','confeitaria'],'prio'=>7],
        ],
    ],
    'ead-online' => [
        'name' => 'EAD e Especialização Online',
        'subs' => [
            'especializacao-ead'   => ['name'=>'Especialização EAD',     'kw'=>['especialização','especializacao','pós-graduação','pos-graduacao','pós graduação','pós','mba'],'prio'=>9],
            'graduacao-ead'        => ['name'=>'Graduação EAD',          'kw'=>['graduação ead','graduacao ead','graduação a distância','graduacao a distancia','graduação online'],'prio'=>9],
            'cursos-online'        => ['name'=>'Cursos Online',          'kw'=>['ead','100% online','a distância','a distancia','online','remoto','remoto'],'prio'=>4],
        ],
    ],
    'idiomas' => [
        'name' => 'Idiomas',
        'subs' => [
            'cursos-ingles'    => ['name'=>'Cursos de Inglês',  'kw'=>['inglês','ingles'],'prio'=>9],
            'cursos-espanhol'  => ['name'=>'Cursos de Espanhol','kw'=>['espanhol'],'prio'=>9],
            'cursos-libras'    => ['name'=>'Cursos de Libras',  'kw'=>['libras'],'prio'=>9],
            'idiomas-geral'    => ['name'=>'Idiomas',           'kw'=>['idioma','idiomas','línguas','linguas'],'prio'=>5],
        ],
    ],
    'carreira-mercado' => [
        'name' => 'Carreira e Mercado',
        'subs' => [
            'jovem-aprendiz'       => ['name'=>'Jovem Aprendiz',        'kw'=>['jovem aprendiz','jovens aprendizes','aprendiz','aprendizes'],'prio'=>10],
            'estagio'              => ['name'=>'Estágio',               'kw'=>['estágio','estagio','estagiário','estagiario','estagiária'],'prio'=>10],
            'primeiro-emprego'     => ['name'=>'Primeiro Emprego',      'kw'=>['primeiro emprego','primeira oportunidade','primeiro trabalho'],'prio'=>10],
            'mercado-trabalho'     => ['name'=>'Mercado de Trabalho',   'kw'=>['mercado de trabalho','currículo','curriculo',' cv ','linkedin','entrevista de emprego','recolocação','recolocacao','calendário escolar','calendario escolar','feriado'],'prio'=>7],
            'bolsas-estudos'       => ['name'=>'Bolsas de Estudos',     'kw'=>['bolsas','bolsa de estudos','bolsa para','bolsas para','milhão para bolsas','milhao para bolsas'],'prio'=>9],
        ],
    ],
];

/* Casos especiais — siglas/nomes que mapeiam direto */
$silos['profissionalizantes']['subs']['fiscal-tributario'] = [
    'name'=>'Fiscal e Tributário', 'kw'=>['nf-e','nfe ','inscrição estadual','inscricao estadual','imposto','tributário','tributario','fiscal','sped'], 'prio'=>10,
];
$silos['profissionalizantes']['subs']['producao-audiovisual'] = [
    'name'=>'Audiovisual e Games', 'kw'=>['audiovisual','games','produção audiovisual','producao audiovisual','jogos','game design'], 'prio'=>10,
];
$silos['profissionalizantes']['subs']['educacao-saude-mental'] = [
    'name'=>'Saúde Mental e Bem-Estar', 'kw'=>['sofrimento mental','saúde mental','saude mental','bem-estar mental'], 'prio'=>10,
];

/* "Vagas em" sozinho cai como último recurso (depois do output principal) */

/* Classifica cada post */
function classifica(string $title, array $silos): array {
    $t = mb_strtolower($title);
    $melhor = null;
    $melhorPrio = 0;
    foreach ($silos as $silo_slug => $silo) {
        foreach ($silo['subs'] as $sub_slug => $sub) {
            foreach ($sub['kw'] as $kw) {
                if (strpos($t, mb_strtolower($kw)) !== false) {
                    if ($sub['prio'] > $melhorPrio) {
                        $melhorPrio = $sub['prio'];
                        $melhor = [
                            'silo_slug' => $silo_slug,
                            'silo_name' => $silo['name'],
                            'sub_slug' => $sub_slug,
                            'sub_name' => $sub['name'],
                            'matched_kw' => $kw,
                            'priority' => $sub['prio'],
                        ];
                    }
                }
            }
        }
    }
    return $melhor ?? [
        'silo_slug' => 'sem-classificacao',
        'silo_name' => '⚠️ Sem classificação',
        'sub_slug' => 'sem-classificacao',
        'sub_name' => '⚠️ Sem classificação',
        'matched_kw' => null,
        'priority' => 0,
    ];
}

$out = [];
$contagem_silo = [];
$contagem_sub = [];
$sem_class = [];

foreach ($posts as $p) {
    $title = html_entity_decode($p['title']['rendered']);
    $clas = classifica($title, $silos);
    $row = [
        'id' => $p['id'],
        'title' => $title,
        'slug' => $p['slug'],
        'date' => $p['date'],
        'old_cats' => $p['categories'],
        'silo_slug' => $clas['silo_slug'],
        'silo_name' => $clas['silo_name'],
        'sub_slug' => $clas['sub_slug'],
        'sub_name' => $clas['sub_name'],
        'matched_kw' => $clas['matched_kw'],
    ];
    $out[] = $row;
    $contagem_silo[$clas['silo_name']] = ($contagem_silo[$clas['silo_name']] ?? 0) + 1;
    $contagem_sub[$clas['sub_name']] = ($contagem_sub[$clas['sub_name']] ?? 0) + 1;
    if ($clas['silo_slug'] === 'sem-classificacao') {
        $sem_class[] = $title;
    }
}

/* Resumo silos */
echo "=== DISTRIBUIÇÃO POR SILO V2 ===\n";
arsort($contagem_silo);
foreach ($contagem_silo as $silo => $n) {
    echo str_pad($silo, 50)."{$n}\n";
}

echo "\n=== DISTRIBUIÇÃO POR SUBCATEGORIA ===\n";
arsort($contagem_sub);
foreach ($contagem_sub as $sub => $n) {
    if ($n >= 2) echo str_pad($sub, 50)."{$n}\n";
}

if (!empty($sem_class)) {
    echo "\n=== ⚠️ SEM CLASSIFICAÇÃO (".count($sem_class).") ===\n";
    foreach ($sem_class as $t) echo "  - {$t}\n";
}

/* Salva CSV pra revisar manualmente */
$csv = fopen(__DIR__.'/classificacao-v2.csv', 'w');
fputcsv($csv, ['id','title','silo','subcategoria','matched_kw','date']);
foreach ($out as $r) {
    fputcsv($csv, [$r['id'], $r['title'], $r['silo_name'], $r['sub_name'], $r['matched_kw'], substr($r['date'], 0, 10)]);
}
fclose($csv);

/* Salva JSON pra usar no script de migração */
file_put_contents(__DIR__.'/classificacao-v2.json', json_encode([
    'silos' => $silos,
    'posts' => $out,
    'resumo_silos' => $contagem_silo,
    'resumo_subs' => $contagem_sub,
    'sem_classificacao' => $sem_class,
], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

echo "\nArquivos gerados:\n  ".__DIR__."/classificacao-v2.csv\n  ".__DIR__."/classificacao-v2.json\n";
