<?php
/**
 * TrendsTaxonomia — fonte única de verdade para classificação editorial.
 *
 * Substitui arrays dispersos em 5 arquivos (DiscoverClusterMatcher, DiscoverRPM,
 * DiscoverScore, DiscoverAngulo, TrendsScraperWeb). Cada cluster declara TODAS
 * as dimensões que o pipeline Discover precisa: editorial, compliance, monetário
 * e de scoring.
 *
 * Schema por cluster:
 *   - nome                 label humano
 *   - grupo_editorial      PRODUTO|TECNOLOGIA|NOTÍCIA|ENTRETENIMENTO|ESPORTES|EDUCAÇÃO|FINANÇAS|GERAL
 *   - categoria_ids        IDs do Google Trends (mapa decifrado empiricamente)
 *   - rpm                  R$ por mil impressões (conservador, média BR)
 *   - threshold            score mínimo Discover p/ aprovar neste cluster
 *   - gatilho              psicológico dominante
 *   - persona              persona da IA na geração
 *   - keywords_match       termos que disparam este cluster no detectar()
 *   - termos_semanticos    usado por DiscoverInternalLinks
 *   - compliance           regras YMYL/E-E-A-T por nicho
 *   - angulos              ângulos modelares para prompt
 *   - termos_proibidos     regex + erro (validação pós-geração)
 *   - inclusao_obrigatoria strings que DEVEM aparecer no HTML
 *
 * Mapa de categorias Google decifrado por amostra real (TrendsScraperWeb).
 * IDs 21/22 não aparecem nos dados brasileiros observados.
 */
class TrendsTaxonomia
{
    /**
     * Categorias do Google Trends (?category=N).
     * IDs confirmados empiricamente via scripts/descobrir_categorias.php.
     */
    public const CATEGORIAS_GOOGLE = [
        1  => 'Autos e veículos',
        2  => 'Beleza e moda',
        3  => 'Negócios e finanças',
        4  => 'Celebridades e entretenimento',
        5  => 'Comida e bebida',
        6  => 'Jogos',
        7  => 'Saúde',
        8  => 'Feriados e tradições',
        9  => 'Empregos e educação',
        10 => 'Lei e governo',
        11 => 'Outras / notícia geral',
        12 => 'Hobbies e lazer',
        13 => 'Pets e animais',
        14 => 'Política',
        15 => 'Ciência',
        16 => 'Livros / Hobbies',
        17 => 'Esportes',
        18 => 'Tecnologia',
        19 => 'Viagens e transporte',
        20 => 'Clima',
        21 => '—',
        22 => '—',
    ];

    /** Ângulo universal do portal.md (1 sempre incluído). */
    public const ANGULOS_UNIVERSAIS = ['promessa vs realidade', 'detalhe oculto'];

    /** Multiplicadores de RPM pela dor dominante. */
    public const DOR_BOOST = [
        'urgencia'     => 1.30,
        'medo'         => 1.25,
        'dinheiro'     => 1.20,
        'oportunidade' => 1.15,
        'nenhuma'      => 1.00,
    ];

    /** Limiares de ranking de arbitragem (0-100). */
    public const RANKING_ALTO  = 70;
    public const RANKING_MEDIO = 40;

    /**
     * Os 13 clusters editoriais. Ordem importa para tie-break do detectar():
     * clusters mais específicos vêm antes de fallbacks (curiosidades_geral por último).
     */
    private static array $clusters = [
        'noticias_info_critica' => [
            'nome'            => 'Notícias e Informação Crítica',
            'grupo_editorial' => 'NOTÍCIA',
            'categoria_ids'   => [9, 10, 11, 14, 20],
            'rpm'             => 24,
            'threshold'       => 6.0,
            'gatilho'         => 'Medo da perda, Urgência, Oportunidade, Impacto Social',
            'persona'         => 'jornalista investigativo e analista de políticas públicas, com foco em traduzir informações complexas para o público geral de forma impactante e urgente',
            'keywords_match'  => ['enem','concurso','inss','bolsa família','bpc','mei','imposto','receita federal','dataprev','gov.br','caixa tem','decreto','portaria','lei ','medida provisória','eleição','senado','câmara','stf','tse','defesa civil','clima','tempestade','chuva','inmet','emprego','vaga','edital','prova','auxílio','auxilio','auxílio-gás','auxilio gas','auxílio gás','vale-gás','vale gás','vale-alimentação','salário mínimo','salario minimo','fgts','pis','pasep','pé-de-meia','pe de meia','minha casa minha vida','mcmv','prouni','sisu','fies','tarifa social','desconto luz','gás de cozinha','botijão','botijao','conta de luz','conta de água','petrobras','aneel','acidente','colisão','colide','colidiu','tragédia','incêndio','desabamento','enchente','deslizamento','avião','aeronave','voo','latam','gol ','azul ','avianca','helicóptero','rodovia','br-101','br-116','br-040','br-381','br-262','br 101','br 116','via dutra','anchieta','imigrantes','morre','morreram','morte','prisão','assalto','homicídio','operação','pf','policia federal','pm sp','bombeiros','resgate','vítima','vítimas','ministro','ministra','ministério','supremo','supremo tribunal','tribunal','impeachment','cpi','presidente','ex-presidente','governador','prefeito','deputado','deputada','senador','senadora','congresso','planalto','esplanada','bolsonaro','lula','dino','moraes','gilmar','barroso','fachin','zanin','dispositivo','cassação','liminar','inquérito','delação','pec','mp ','ação penal','habilitação','detran','cnh','cpf','rg','cidadania','documento oficial','passaporte','titulo de eleitor','alistamento'],
            'termos_semanticos' => ['edital','concurso','benefício','direito','prazo','cadastro','gov.br','inscrição','recurso','documentação','vaga pública','portaria'],
            'compliance'      => [
                'fidedignidade INEGOCIÁVEL: fatos verificáveis, dados oficiais, fontes primárias (órgãos, instituições, imprensa). Cada número/valor/prazo deve vir LITERALMENTE da fonte.',
                'citar entidade responsável com nome completo + sigla (ex: "Instituto Nacional do Seguro Social — INSS")',
                'citar base legal quando fonte informar (lei, decreto, portaria, número/ano)',
                'linguagem condicional em temas legais ("de acordo com", "segundo o edital")',
                'nunca prometer resultado ("você receberá X") — sempre "tem direito a X se cumprir Y"',
            ],
            'angulos' => [
                '[NOVA LEI/MUDANÇA] que [AFETA/BENEFICIA/PREJUDICA] [GRUPO ESPECÍFICO] e você precisa saber agora',
                'ALERTA URGENTE: [ÓRGÃO OFICIAL] sobre [EVENTO CRÍTICO] que pode [CONSEQUÊNCIA GRAVE] em [LOCAL/PERÍODO]',
                'A VERDADE por trás de [DECISÃO/FENÔMENO/ESTATÍSTICA] — o que a mídia não destaca',
                'A [OPORTUNIDADE/PROGRAMA] que [N]% dos brasileiros não conhecem e pode mudar sua vida',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b(garanto|garanta|com\s+certeza\s+(?:vai|você))\b/iu', 'erro' => 'Promessa absoluta em tema legal'],
                ['pattern' => '/\b(isenção\s+garantida|aprovação\s+garantida|vaga\s+garantida)\b/iu', 'erro' => 'Garantia de resultado'],
            ],
            'inclusao_obrigatoria' => [],
        ],

        'negocios_financas' => [
            'nome'            => 'Negócios e Finanças',
            'grupo_editorial' => 'FINANÇAS',
            'categoria_ids'   => [3],
            'rpm'             => 42,
            'threshold'       => 5.5,
            'gatilho'         => 'Desejo de Ganho, Medo da Perda, Oportunidade, Economia',
            'persona'         => 'analista financeiro experiente e consultor de investimentos, com foco em desmistificar finanças e revelar oportunidades/riscos de forma clara',
            'keywords_match'  => ['investimento','poupança','tesouro direto','cdb','renda fixa','renda variável','bolsa','ibovespa','pix','cartão','crédito','empréstimo','consignado','selic','inflação','ipca','dólar','criptomoeda','bitcoin','fgts','pasep','13º salário','salário mínimo','imposto de renda','irpf','ir 2026','ir 2027','ir 2028','ir 2025','ir 2024','declaração','declarar','declara','restituição','restituir','malha fina','dirpf','dedução','deduções','contribuinte','receita federal','multa imposto','multa atraso','atraso declaração','lote restituição'],
            'termos_semanticos' => ['imposto de renda','malha fina','restituição','deduções','DIRPF','FGTS','INSS','salário','13º salário','pasep','abono','cdb','tesouro direto','renda fixa','selic','ibovespa'],
            'compliance'      => [
                'transparência em número: cotação, índice, taxa com data da leitura ("segundo dados da B3 em [data]")',
                'em investimento: sempre incluir aviso de risco ("retornos passados não garantem ganhos futuros")',
                'NUNCA sugerir compra/venda específica ou "dica" de ação — é proibido por lei (CVM)',
                'dados de bancos/programas: linkar à fonte oficial do banco ou ao site gov.br',
                'E-E-A-T: autoria ou endosso de especialista reconhecido (CFP, CGA, assessor credenciado)',
            ],
            'angulos' => [
                '[INVESTIMENTO/PRODUTO] secreto que [N]% dos milionários usam e você não conhece',
                'O ERRO de R$ [VALOR] que [N]% dos brasileiros cometem ao [AÇÃO FINANCEIRA] e perdem fortunas',
                'O [TRUQUE] simples para economizar/ganhar R$ [VALOR] em [PERÍODO]',
                'ALERTA: o novo [GOLPE/RISCO] que está afetando milhões e como proteger seu dinheiro',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b(lucro\s+garantido|retorno\s+garantido|ganho\s+certo|investimento\s+sem\s+risco)\b/iu', 'erro' => 'Promessa financeira irrealista (violação CVM)'],
                ['pattern' => '/\b(fique\s+rico|ficar\s+milionário|ganhe\s+milhões)\b/iu', 'erro' => 'Clickbait de enriquecimento'],
                ['pattern' => '/\b(compre\s+(?:agora|já)\s+(?:a\s+ação|o\s+ativo|as?\s+ações))\b/iu', 'erro' => 'Recomendação direta de compra (proibida sem CNPI)'],
            ],
            'inclusao_obrigatoria' => [],
        ],

        'saude_bem_estar' => [
            'nome'            => 'Saúde e Bem-Estar',
            'grupo_editorial' => 'NOTÍCIA',
            'categoria_ids'   => [7],
            'rpm'             => 32,
            'threshold'       => 6.0,
            'gatilho'         => 'Medo da Doença, Desejo de Cura/Alívio, Longevidade, Autoestima',
            'persona'         => 'médico pesquisador e nutricionista, com foco em educar sobre saúde de forma responsável, desmistificando mitos e revelando informações cruciais para o bem-estar, sempre com base científica',
            'keywords_match'  => ['saúde','doença','sintoma','remédio','medicamento','vacina','dieta','emagrecer','perder peso','ganhar massa','gravidez','menopausa','diabetes','hipertensão','câncer','depressão','ansiedade','suplemento','exercício','treino','cirurgia','exame','sus','anvisa','oms','sono','insônia','colesterol','obesidade','imunidade','saúde mental','terapia','psicólogo','psiquiatra','nutricionista','fisioterapia'],
            'termos_semanticos' => ['sintomas','tratamento','prevenção','diagnóstico','vacina','exame','saúde mental','alimentação','rotina','bem-estar'],
            'compliance'      => [
                'EXTREMA CAUTELA: NUNCA prometer cura, diagnóstico ou substituto de aconselhamento médico',
                'DISCLAIMER OBRIGATÓRIO: parágrafo final "Este conteúdo é informativo e não substitui consulta médica. Em caso de sintomas, procure um profissional de saúde."',
                'toda afirmação clínica deve vir de ESTUDO, órgão oficial (OMS, Ministério da Saúde, ANVISA, SBC, etc)',
                'dosagem, posologia: só repetir o que a bula/fonte oficial informa',
                'nomes de medicamentos: só genérico ou princípio ativo quando possível',
                'nunca usar "milagre", "cura definitiva", "segredo que médicos escondem"',
            ],
            'angulos' => [
                'O [HÁBITO/ALIMENTO/SINTOMA] comum que está [CAUSANDO DOENÇA] e poucos sabem — conforme estudo da [FONTE]',
                'O [MÉTODO/INGREDIENTE/EXERCÍCIO] que [BENEFÍCIO] segundo pesquisa publicada em [REVISTA/ÓRGÃO]',
                'ALERTA DE SAÚDE: o [PRODUTO/PRÁTICA] popular que pode [DANO] — alerta da ANVISA/[ÓRGÃO]',
                'A verdade por trás de [MITO DE SAÚDE] que você acreditou — evidência da [FONTE CIENTÍFICA]',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b(cura\s+(?:definitiva|milagrosa|garantida)|remédio\s+milagroso|elimina\s+(?:completamente\s+)?(?:o\s+)?câncer)\b/iu', 'erro' => 'Promessa de cura (YMYL grave)'],
                ['pattern' => '/\b(substitui\s+(?:a\s+)?consulta\s+médica|dispensa\s+(?:o\s+)?médico)\b/iu', 'erro' => 'Desestímulo a consulta médica'],
                ['pattern' => '/\b(médicos\s+(?:escondem|não\s+querem\s+que)|segredo\s+que\s+médicos)\b/iu', 'erro' => 'Clickbait anti-ciência'],
                ['pattern' => '/\b(emagreça\s+\d+\s*kg\s+em\s+\d+\s*(?:dias?|semanas?))\b/iu', 'erro' => 'Promessa de emagrecimento quantificado sem respaldo'],
            ],
            'inclusao_obrigatoria' => [
                'Este conteúdo é informativo e não substitui consulta médica',
            ],
        ],

        'tecnologia' => [
            'nome'            => 'Tecnologia e Inovação',
            'grupo_editorial' => 'TECNOLOGIA',
            'categoria_ids'   => [18],
            'rpm'             => 16,
            'threshold'       => 6.5,
            'gatilho'         => 'Curiosidade, Novidade, Praticidade, Medo de Ficar para Trás',
            'persona'         => 'especialista em tecnologia e reviewer de gadgets, com foco em desvendar inovação, revelar funcionalidades pouco conhecidas e alertar sobre riscos, com precisão técnica',
            'keywords_match'  => ['iphone','android','whatsapp','instagram','tiktok','facebook','google','apple','samsung','aplicativo','app','celular','smartphone','notebook','computador','windows','chatgpt','ia','inteligência artificial','chip','processador','pix','streaming','netflix','youtube','bug','atualização','lançamento'],
            'termos_semanticos' => ['atualização','bug','segurança','privacidade','aplicativo','funcionalidade','dica','tutorial','comparativo'],
            'compliance'      => [
                'precisão técnica: especificações (RAM, bateria, tela) só se a fonte informar',
                'tutoriais: passos SEGUROS e funcionais; se o tutorial envolve root/modding/desbloqueio, avisar do risco de garantia',
                'apps/sites: nunca recomendar cracks, APKs não-oficiais ou ferramentas que violem TOS',
                'comparação de marcas: imparcial, baseada em benchmarks públicos (AnTuTu, GSMArena, DXOMark)',
                'alerta de segurança: se a fonte informa CVE, citar o número',
            ],
            'angulos' => [
                'A função SECRETA do seu [DISPOSITIVO/APP] que [N]% dos usuários não conhecem',
                'A nova [TECNOLOGIA/GADGET] que vai revolucionar [SETOR] — o que a empresa anunciou',
                'ALERTA: o [APP/SITE] que está [ROUBANDO DADOS/DEIXANDO LENTO] — o que fazer agora',
                '[PRODUTO A] vs. [PRODUTO B]: o vencedor inesperado no teste de [ANALISTA RECONHECIDO]',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b(baixar\s+(?:crack|apk\s+modificad|versão\s+pirata)|instalar\s+(?:crack|piratee))\b/iu', 'erro' => 'Recomenda software pirata'],
                ['pattern' => '/\b(garante\s+100%\s+de\s+segurança|impossível\s+de\s+hackear|imune\s+a\s+vírus)\b/iu', 'erro' => 'Garantia absoluta em segurança'],
            ],
            'inclusao_obrigatoria' => [],
        ],

        'educacao' => [
            'nome'            => 'Educação e Carreira',
            'grupo_editorial' => 'EDUCAÇÃO',
            'categoria_ids'   => [17],
            'rpm'             => 11,
            'threshold'       => 7.5,
            'gatilho'         => 'Oportunidade, Prazo, Medo de Ficar para Trás, Mudança de Vida',
            'persona'         => 'jornalista educacional e consultor de carreira, com foco em traduzir editais, prazos e regras de programas (ENEM, FIES, ProUni, SISU) em utilidade prática pra quem está se preparando',
            'keywords_match'  => ['enem','sisu','fies','prouni','vestibular','fuvest','unicamp','uerj','faculdade','universidade','ead','bolsa de estudos','bolsa integral','bolsa parcial','curso','cursos','mba','pós','especialização','certificação','certificado','senac','sebrae','sesi','cefet','instituto federal','etec','fatec','enade','encceja','redação','edital','vestibulando','candidato','aluno','estudante','volta às aulas','nota de corte','ensino superior','ensino médio','calendário enem','edital mec','curso técnico','curso superior','prova do enem','isenção da taxa','isenção do enem','inscrição do enem','inscrição do fies','inscrição do prouni','inscrição do sisu'],
            'termos_semanticos' => ['edital','prazo','inscrição','candidato','aprovação','nota de corte','documentos','isenção','cronograma','calendário'],
            'compliance'      => [
                'PRAZOS: sempre citar a data EXATA conforme edital — nunca aproximar ("até X" só se a fonte disser literalmente)',
                'BASE LEGAL: programas (ENEM, FIES, ProUni) só descrever conforme MEC/INEP/portaria oficial — nunca improvisar',
                'NOTA DE CORTE: número específico só se houver lista divulgada; senão, mencionar "consulte no portal"',
                'ISENÇÃO: critério de elegibilidade só conforme regra publicada (nunca generalizar "todo aluno de escola pública")',
                'NÃO PROMETER aprovação ("você passa garantido", "fórmula que aprovou X mil") — Discover penaliza',
            ],
            'angulos' => [
                '[PROGRAMA] abre [N] vagas em [N] cursos — calendário, isenção e como se inscrever',
                'Prazo da [INSCRIÇÃO] termina em [DATA] — quem perder paga taxa cheia/fica de fora',
                'A regra do [EDITAL] que [N]% dos candidatos não percebe — e custa a vaga',
                'Como conseguir bolsa de [N]% no [PROGRAMA] — critérios objetivos do MEC',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b(garante\s+aprovação|você\s+passa\s+(?:no|na)\s+(?:enem|vestibular)|fórmula\s+que\s+aprovou\s+\d+)\b/iu', 'erro' => 'Promessa de aprovação garantida'],
                ['pattern' => '/\b(certificado\s+(?:rápido|sem\s+estudar|garantido)|comprar\s+(?:diploma|certificado))\b/iu', 'erro' => 'Insinuação de fraude educacional'],
            ],
            'inclusao_obrigatoria' => [],
        ],

        'entretenimento_cultura' => [
            'nome'            => 'Entretenimento e Cultura',
            'grupo_editorial' => 'ENTRETENIMENTO',
            'categoria_ids'   => [4, 16],
            'rpm'             => 7,
            'threshold'       => 8.0,
            'gatilho'         => 'Curiosidade, Fofoca, Surpresa, Pertencimento, Nostalgia',
            'persona'         => 'crítico de cinema/séries, gamer especialista e jornalista de cultura pop, com foco em desvendar segredos e gerar discussões',
            'keywords_match'  => ['bbb','big brother','novela','globoplay','netflix','prime video','disney','filme','série','ator','atriz','cantor','cantora','música','funk','sertanejo','carnaval','show','teatro','premio oscar','academy awards','oscar 2026','oscar do cinema','grammy','games','jogo','ps5','xbox','nintendo','pokemon','free fire','cs2','valorant','lol'],
            'termos_semanticos' => ['estreia','elenco','trama','teoria','bastidores','streaming','temporada','personagem'],
            'compliance'      => [
                'RESPEITO À PRIVACIDADE: só citar figuras públicas, e ainda assim sem especulação sobre vida íntima não declarada',
                'veracidade: nunca afirmar rumor como fato ("fulano vai sair" — só se houver comunicado oficial ou fonte direta)',
                'spoilers: se conteúdo tem spoiler, AVISAR no início da seção',
                'análises: declarar se é OPINIÃO do autor ou veredito unânime',
                'crítica: focar na obra, não atacar pessoas',
            ],
            'angulos' => [
                'O [DETALHE/SEGREDO/FATO] chocante sobre [FILME/SÉRIE/CELEB/JOGO] que ninguém percebeu',
                'Os [N] segredos de bastidores de [OBRA] revelados em [FONTE]',
                'A TEORIA dos fãs que explica [MISTÉRIO/FINAL] de [SÉRIE/JOGO]',
                '[N] [FILMES/JOGOS] clássicos que envelheceram MAL — o que descobrimos ao reassistir',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b(não\s+assista|boicote|cancelar\s+(?:a\s+série|o\s+filme))\b/iu', 'erro' => 'Incentivo a cancelamento'],
            ],
            'inclusao_obrigatoria' => [],
        ],

        'esportes' => [
            'nome'            => 'Esportes e Competição',
            'grupo_editorial' => 'ESPORTES',
            'categoria_ids'   => [17, 6],
            'rpm'             => 6,
            'threshold'       => 8.0,
            'gatilho'         => 'Paixão, Rivalidade, Superação, Polêmica, Previsão',
            'persona'         => 'comentarista esportivo apaixonado e analista tático, com foco em gerar debate e revelar bastidores — sempre com base em fatos',
            // 'cruzeiro' (sozinho) e apelidos de clubes adicionados em 2026-05-02 — caso #733:
            // 'Cruzeiro x Atlético' caía em viagem_transporte. No contexto editorial BR, 'Cruzeiro'
            // sozinho é majoritariamente o clube. Mantida ambiguidade pra viagem só com qualificador
            // (cruzeiro marítimo / pelo caribe / etc.).
            'keywords_match'  => ['futebol','brasileirão','libertadores','copa do brasil','champions','copa do mundo','copa mundial','campeonato mundial','mundial de clubes','mundial sub-20','seleção brasileira','flamengo','palmeiras','corinthians','são paulo fc','spfc','vasco da gama','fluminense','atlético mineiro','atlético-mg','atlético-pr','athletico-pr','atlético','cruzeiro esporte clube','cruzeiro','santos fc','santos futebol','botafogo','vasco','grêmio','internacional','bahia','sport','fortaleza','ceará','vitória','remo','paysandu','nba','f1','formula 1','ufc','boxe','vôlei','basquete','olimpíada','tênis','natação','placar','escalação','técnico','treinador','artilheiro','goleiro','atacante','zagueiro','meio-campo','pênalti','lance livre','gol de','gol contra','derrota','vitória por','empate em','clássico','dérbi','rivalidade','x atlético','x cruzeiro','x flamengo','x palmeiras','x corinthians','x santos','x são paulo','galo','raposa','celeste','tricolor','rubro-negro','alvinegro','colorado','timão','peixe'],
            'termos_semanticos' => ['tabela','classificação','gol','escalação','tática','lesão','transferência','contratação','campeonato'],
            'compliance'      => [
                'resultados, placares, estatísticas: LITERALMENTE corretos (checar com duas fontes)',
                'declarações de atletas/técnicos: só citar se houver transcrição/vídeo/entrevista oficial',
                'rumores de transferência: usar "segundo [VEÍCULO]" ou "de acordo com [JORNALISTA]", nunca como fato',
                'análise tática: apoiada em dados (Sofascore, Opta, ESPN Stats)',
                'respeito: sem ofensas pessoais, sem incentivo à violência entre torcidas',
            ],
            'angulos' => [
                'O [LANCE/DECISÃO/DECLARAÇÃO] polêmico que pode mudar o [RESULTADO/CAMPEONATO]',
                'A previsão de [EX-ATLETA/ANALISTA] para [TIME/ATLETA] que ninguém esperava',
                'O [SEGREDO TÁTICO/DIETA/ROTINA] de [ATLETA] revelado em [FONTE]',
                'De [SITUAÇÃO RUIM] a [SITUAÇÃO BOA]: a história de [ATLETA] que inspira',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b(?:juiz\s+(?:ladrão|vendido|comprado)|roubou\s+o\s+(?:jogo|título))\b/iu', 'erro' => 'Acusação criminal sem base'],
            ],
            'inclusao_obrigatoria' => [],
        ],

        'lifestyle_consumo' => [
            'nome'            => 'Estilo de Vida, Moda, Beleza e Hobbies',
            'grupo_editorial' => 'PRODUTO',
            'categoria_ids'   => [2, 12],
            'rpm'             => 14,
            'threshold'       => 7.0,
            'gatilho'         => 'Desejo de Melhoria, Praticidade, Economia, Prazer, Status',
            'persona'         => 'stylist/dermatologista/instrutor de hobbies, com foco em revelar dicas práticas e tendências com toque de inspiração',
            'keywords_match'  => ['moda','beleza','maquiagem','skincare','cabelo','decoração','casa','jardim','livro','leitura','cursos','academia','yoga','pilates','meditação','costura','pintura','desenho','fotografia','música','instrumento','gadget','organização','eletrodoméstico','lavadora','geladeira','fogão','microondas','liquidificador','aspirador','sofá','cama','colchão','roupa','tênis','sapato','bolsa','joia'],
            'termos_semanticos' => ['tendência','dica','truque','organização','produto','preço','desconto','comparativo'],
            'compliance'      => [
                'moda/beleza: procedimentos invasivos só com profissional credenciado (dermatologista, esteticista com CRF)',
                'ingredientes perigosos (ácidos, tinturas químicas, procedimentos caseiros): avisar riscos',
                'se recomenda produto, declarar se é reviewer ou publicidade (lei do consumidor)',
                'hobbies: progressão realista — sem "aprendendo em 1 dia" quando exige prática',
                'instruções DIY: passos seguros + aviso quando exige profissional (elétrica, gás, estrutura)',
                'sem exagero: "mil vezes melhor", "infinitamente superior" sem base = clickbait',
            ],
            'angulos' => [
                'O [TRUQUE] simples que [N]% das pessoas não conhecem para [BENEFÍCIO ESTÉTICO/LIFESTYLE]',
                'O [PRODUTO] barato que [DERMATOLOGISTAS/STYLISTS] recomendam e custa menos que [VALOR]',
                'O ERRO de [BELEZA/DIY/ORGANIZAÇÃO] que [N]% das pessoas cometem e prejudica [RESULTADO]',
                'A tendência de [MODA/BELEZA] que vai dominar [ESTAÇÃO] — visto em [REFERÊNCIA]',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b(mil\s+vezes\s+melhor|infinitamente\s+superior)\b/iu', 'erro' => 'Exagero sem base'],
                ['pattern' => '/\b(rejuvenesc[ea]\s+\d+\s+anos?|apaga\s+todas?\s+as\s+rugas?)\b/iu', 'erro' => 'Promessa antienvelhecimento irreal'],
                ['pattern' => '/\b(cura\s+(?:a\s+)?celulite|elimina\s+(?:a\s+)?celulite\s+em\s+\d+)\b/iu', 'erro' => 'Promessa estética sem base'],
                ['pattern' => '/\b(aprenda?\s+em\s+(?:1|um)\s+(?:dia|hora))\b/iu', 'erro' => 'Promessa de aprendizado irreal'],
            ],
            'inclusao_obrigatoria' => [],
        ],

        'automoveis' => [
            'nome'            => 'Automóveis e Veículos',
            'grupo_editorial' => 'PRODUTO',
            'categoria_ids'   => [1],
            'rpm'             => 26,
            'threshold'       => 6.0,
            'gatilho'         => 'Medo da Perda (desvalorização/recall), Desejo de Ganho (economia), Status, Curiosidade, Segurança',
            'persona'         => 'engenheiro automotivo e especialista em mercado de veículos, focado em desvendar custos ocultos, alertar sobre recalls e revelar funções escondidas',
            'keywords_match'  => ['carro','automóvel','veículo','moto','caminhão','suv','sedan','hatch','picape','ford','fiat','volks','volkswagen','chevrolet','toyota','honda','hyundai','renault','bmw','mercedes','elétrico','híbrido','gasolina','etanol','diesel','detran','cnh','ipva','licenciamento','recall','multa','seguro auto','pneu','óleo','motor','revisão','câmbio','direção','ar-condicionado automotivo'],
            'termos_semanticos' => ['consumo','manutenção','recall','revisão','fipe','ipva','cnh','multa','licenciamento','seguro'],
            'compliance'      => [
                'dados técnicos (consumo, potência, preço) verificáveis — citar montadora ou revista especializada (Quatro Rodas, Autoesporte, CarPlace)',
                'recalls: usar número oficial do CONTRAN/ANTT e link da montadora; data do anúncio obrigatória',
                'legislação de trânsito: citar CTB (Código de Trânsito Brasileiro) + número da resolução do CONTRAN',
                'análises de desempenho: basear em testes documentados, não opinião sem fundamento',
                'preço: com data da consulta ("em [mês/ano] na FIPE")',
                'modificação/tuning: avisar sobre perda de garantia/homologação',
            ],
            'angulos' => [
                'O CUSTO escondido na manutenção do [MODELO] que só aparece depois de [KM/TEMPO]',
                'A função OCULTA no seu [MARCA/MODELO] que [N]% dos donos nunca ativaram',
                'ALERTA: recall do [MODELO] — [N] mil carros afetados por [DEFEITO], veja se o seu está na lista',
                'O [MODELO] desvaloriza R$ [VALOR] em [TEMPO] segundo tabela FIPE de [MÊS/ANO]',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b(carro\s+que\s+faz\s+\d{3,}\s*km\s*(?:por|\/)\s*l(?:itro)?)\b/iu', 'erro' => 'Consumo impossível'],
                ['pattern' => '/\b(bateria\s+(?:que\s+)?dura\s+para\s+sempre|nunca\s+quebra)\b/iu', 'erro' => 'Garantia absoluta sobre componente'],
                ['pattern' => '/\b(?:mec[aâ]nicos?\s+(?:esconde[mn]?|n[aã]o\s+quere[mn]?\s+que)|concessionárias?\s+esconde[mn]?)\b/iu', 'erro' => 'Conspiração anti-profissional'],
            ],
            'inclusao_obrigatoria' => [],
        ],

        'ciencia' => [
            'nome'            => 'Ciência e Pesquisa',
            'grupo_editorial' => 'NOTÍCIA',
            'categoria_ids'   => [15],
            'rpm'             => 10,
            'threshold'       => 6.0,
            'gatilho'         => 'Curiosidade, Mistério, Revelação Impactante, Futuro Inesperado',
            'persona'         => 'cientista renomado e divulgador científico, com foco em desvendar mistérios e explicar descobertas complexas de forma acessível',
            'keywords_match'  => ['ciência','cientistas','pesquisa','estudo','descoberta','nasa','esa','spacex','universo','galáxia','buraco negro','planeta','marte','júpiter','exoplaneta','meteorito','fóssil','dinossauro','arqueologia','genética','dna','evolução','clima global','aquecimento','co2','quântica','física','química','biologia','neurociência','inteligência artificial científica'],
            'termos_semanticos' => ['estudo','pesquisa','descoberta','experimento','hipótese','dado','peer-review','universidade'],
            'compliance'      => [
                'apenas pesquisas PEER-REVIEWED (revisadas por pares) citando revista (Nature, Science, PNAS, The Lancet, Cell)',
                'deixar CLARO o estágio da pesquisa: hipótese / estudo preliminar / replicado / consenso científico',
                'citar universidade/instituto + ano da publicação',
                'evitar pseudociência, teorias da conspiração, "ciência alternativa"',
                'nunca prometer aplicação prática que a pesquisa não demonstrou',
                'consenso científico (ex: mudanças climáticas, evolução, vacinas) é fato — não apresentar como "ainda em debate"',
            ],
            'angulos' => [
                'A DESCOBERTA da [INSTITUIÇÃO] que pode [MUDAR O QUE SABEMOS] sobre [FENÔMENO]',
                'Cientistas flagram [FENÔMENO/SINAL] que [DESAFIA O QUE SE PENSAVA] — publicado em [REVISTA]',
                'Estudo de [UNIVERSIDADE] com [N] voluntários mostra que [ACHADO] — estágio: [PRELIMINAR/REPLICADO]',
                'O MITO científico que você acreditou a vida toda — o que a evidência realmente mostra',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b(?:ciência\s+(?:acaba\s+de\s+)?provar|prova\s+definitiv(?:a|amente)|conclusivamente\s+demonstrado)\b/iu', 'erro' => 'Certeza absoluta sobre tema em pesquisa'],
                ['pattern' => '/\b(cientistas\s+(?:chocados|surpresos|atônitos|sem\s+explicação))\b/iu', 'erro' => 'Sensacionalismo científico'],
                ['pattern' => '/\b(?:descoberta\s+milenar|segredo\s+dos\s+antigos|sabedoria\s+ancestral\s+proíb)\b/iu', 'erro' => 'Pseudociência'],
            ],
            'inclusao_obrigatoria' => [],
        ],

        'pets_animais' => [
            'nome'            => 'Pets e Animais',
            'grupo_editorial' => 'GERAL',
            'categoria_ids'   => [13],
            'rpm'             => 14,
            'threshold'       => 7.0,
            'gatilho'         => 'Amor Incondicional, Medo da Perda (doença), Bem-Estar Animal, Culpa/Responsabilidade',
            'persona'         => 'veterinário renomado e especialista em comportamento animal, focado em educar tutores sobre cuidados ideais e alertar sobre riscos, com base científica',
            'keywords_match'  => ['pet','cachorro','cão','gato','felino','canino','papagaio','pássaro','hamster','coelho','tartaruga','peixe','ração','petisco','veterinário','vacina pet','castração','vermífugo','carrapato','pulga','adestramento','raça','filhote','animal de estimação'],
            'termos_semanticos' => ['ração','alimentação pet','castração','vacina pet','vermífugo','adestramento','comportamento animal','raça'],
            'compliance'      => [
                'lista de alimentos proibidos por espécie sempre atualizada (chocolate, uva, cebola, alho, xilitol, abacate para cães/gatos)',
                'sintomas de doença: SEMPRE recomendar "procurar veterinário imediatamente", nunca auto-medicar',
                'DISCLAIMER: "conteúdo informativo, não substitui consulta veterinária"',
                'medicamentos de uso humano: NUNCA recomendar em animais sem prescrição vet',
                'raças: informação de temperamento/saúde baseada em fontes oficiais (CBKC, AKC) e estudos',
                'adoção: sempre preferível à compra; citar lei contra maus-tratos (Lei 14.064/2020)',
            ],
            'angulos' => [
                'O SINAL silencioso no seu [PET] que indica [DOENÇA] — o tutor só percebe quando já é tarde',
                'O ALIMENTO que você dá ao seu [PET] achando que ajuda, mas está [DANO] — alerta veterinário',
                'O COMPORTAMENTO estranho do seu [PET] que revela [SEGREDO/PROBLEMA], segundo etólogo',
                'A DICA do veterinário que [N]% dos tutores não conhecem e pode [ECONOMIZAR/MELHORAR VIDA]',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b(?:cachorros?|cães|gatos?|felinos?)\s+podem?\s+comer\s+(?:uva|passas?|chocolate|cebola|alho|abacate|xilitol)\b/iu', 'erro' => 'Alimento tóxico recomendado'],
                ['pattern' => '/\bd[êe]\s+(?:dipirona|paracetamol|ibuprofeno|aspirina)\s+(?:para|ao)\s+(?:seu\s+)?(?:cachorro|gato|pet|cão)\b/iu', 'erro' => 'Medicamento humano em pet (tóxico)'],
                ['pattern' => '/\b(?:cura|trata)\s+(?:sem\s+veterinário|em\s+casa)\s+(?:cinomose|parvo|f[ie]lv|leucemia\s+felina)\b/iu', 'erro' => 'Tratamento caseiro de doença grave'],
                ['pattern' => '/\b(?:remédio\s+caseiro|chá\s+caseiro)\s+(?:cura|trata)\s+(?:carrapato|pulga|verme|sarna)\b/iu', 'erro' => 'Tratamento não-veterinário'],
            ],
            'inclusao_obrigatoria' => [
                'consulte um veterinário',
            ],
        ],

        'viagem_transporte' => [
            'nome'            => 'Viagem e Transporte',
            'grupo_editorial' => 'PRODUTO',
            'categoria_ids'   => [19],
            'rpm'             => 20,
            'threshold'       => 6.5,
            'gatilho'         => 'Desejo (experiências), Economia (passagens), Praticidade, Medo (problemas em viagem)',
            'persona'         => 'viajante experiente e especialista em turismo, com foco em revelar segredos de viagem, dicas de economia e alertar sobre armadilhas',
            // 'cruzeiro' removido em 2026-05-02: ambíguo (também é nome de clube de futebol).
            // Pegava trends esportivos como Viagens (caso #733: "Cruzeiro x Atlético"). Substituído
            // por padrões inequívocos de turismo marítimo. 'gol' e 'azul' também removidos —
            // empresas aéreas têm nome conflitante (gol = futebol, azul = palmeiras anos atrás).
            'keywords_match'  => ['viagem','turismo','passagem aérea','voo','hotel','pousada','airbnb','resort','pacote turístico','decolar','latam','anac','infraero','passaporte','visto','viagem internacional','nacional','roteiro','destino','praia','montanha','cruzeiro marítimo','cruzeiro pelo caribe','cruzeiro pelo mediterrâneo','navio de cruzeiro','msc cruzeiros','excursão','mochilão'],
            'termos_semanticos' => ['destino','passagem','hotel','hospedagem','roteiro','visto','documentação','seguro viagem'],
            'compliance'      => [
                'preço/promo: sempre com DATA da consulta ("em [mês/ano]") — preços mudam em horas',
                'documentação obrigatória: passaporte, visto, CIV (vacina), CNH internacional — baseado em Itamaraty/gov.br/MRE',
                'alertas de segurança em destinos: baseado em orientação oficial (Itamaraty, US State Dept, FCO) com link',
                'companhias/hoteis: citar estrelas oficiais (ABIH, Embratur) e não "melhor do mundo" sem ranking',
                'declarar se o conteúdo tem afiliação (affiliate link, parceria)',
                'câmbio: citar fonte (BCB, casa de câmbio oficial) + data',
            ],
            'angulos' => [
                'O DESTINO secreto na [REGIÃO] que custa menos que [DESTINO FAMOSO] e ninguém indica',
                'O ERRO que faz você PAGAR R$ [VALOR] a mais na passagem aérea — segundo [ESPECIALISTA/SITE]',
                'A JANELA de [MÊS] para viajar para [DESTINO] pela metade do preço (dado de [FONTE])',
                'ALERTA: [GOLPE/RESTRIÇÃO] em [DESTINO] que pode arruinar sua viagem — alerta do Itamaraty',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b(passagem\s+(?:para|pra)\s+(?:Europa|Estados\s+Unidos|EUA|Asia|Ásia)\s+por\s+R\$\s*\d{2}\b)/iu', 'erro' => 'Passagem internacional com preço impossível'],
                ['pattern' => '/\b(viagem\s+(?:100%\s+)?grátis|hospedagem\s+(?:100%\s+)?grátis)\b/iu', 'erro' => 'Promessa de viagem gratuita'],
                ['pattern' => '/\b(dispensa\s+(?:o\s+)?visto|não\s+precisa\s+de\s+visto)\b\s+para\s+(?:EUA|Estados\s+Unidos|Europa|Schengen)/iu', 'erro' => 'Informação falsa sobre visto'],
            ],
            'inclusao_obrigatoria' => [],
        ],

        'comidas_bebidas' => [
            'nome'            => 'Comidas, Bebidas e Culinária',
            'grupo_editorial' => 'PRODUTO',
            'categoria_ids'   => [5],
            'rpm'             => 12,
            'threshold'       => 7.0,
            'gatilho'         => 'Prazer (sabor), Desejo (receitas), Medo (alergia), Curiosidade (segredos), Economia',
            'persona'         => 'chef renomado e nutricionista, com foco em desvendar segredos culinários e criar receitas seguras e prazerosas',
            'keywords_match'  => ['receita','comida','culinária','cozinha','prato','jantar','almoço','café da manhã','sobremesa','doce','salgado','massa','pão','bolo','torta','carne','frango','peixe','vegano','vegetariano','glúten','lactose','airfryer','panela de pressão','forno','microondas','chef','restaurante','bebida','cerveja','vinho','café','chá','suco'],
            'termos_semanticos' => ['receita','ingrediente','preparo','sabor','nutrição','calorias','alergia','dieta','airfryer'],
            'compliance'      => [
                'receitas: testadas ou com fonte citada (livro, chef, site confiável)',
                'ALERGIAS declaradas em bold: glúten, lactose, nozes, crustáceos, soja, ovo, frutos do mar',
                'dietas restritivas (keto, jejum, detox): baseadas em nutricionista ou estudo clínico — nunca "milagre"',
                'segurança alimentar: temperaturas de cocção, manipulação de carnes cruas, conservação',
                'bebidas alcoólicas: avisar consumo responsável (+18)',
                'nunca prometer emagrecimento quantificado sem respaldo ("perde 5kg em 1 semana com chá")',
            ],
            'angulos' => [
                'O INGREDIENTE secreto que [N]% dos chefs usam para [RESULTADO] — e você tem em casa',
                'O ALIMENTO que você come todo dia e tem [BENEFÍCIO/PERIGO] inesperado, segundo [FONTE]',
                'A RECEITA de [N] ingredientes que economiza R$ [VALOR] e rende mais que [PRATO]',
                'O ERRO que faz sua [CARNE/MASSA/BOLO] [RESULTADO RUIM] — como corrigir',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b(?:perd[ea]|emagre[çc]a)\s+\d+\s*kg\s+em\s+\d+\s*(?:dias?|semanas?)\b/iu', 'erro' => 'Promessa de emagrecimento quantificada'],
                ['pattern' => '/\b(?:ch[áa]|suco|[áa]gua|alimento|ingrediente)\s+(?:\w+\s+){0,3}(?:que\s+)?cura\s+(?:diabetes|c[âa]ncer|press[aã]o\s+alta|hipertens[aã]o|colesterol|obesidade)\b/iu', 'erro' => 'Alimento como cura de doença'],
                ['pattern' => '/\bdetox\s+(?:em\s+)?\d+\s+dias?\s+(?:limpa|desintoxica)\s+(?:o\s+)?(?:fígado|organismo|corpo)/iu', 'erro' => 'Detox sem base científica'],
            ],
            'inclusao_obrigatoria' => [],
        ],

        'curiosidades_geral' => [
            'nome'            => 'Curiosidades, Histórias e Soluções Gerais',
            'grupo_editorial' => 'GERAL',
            'categoria_ids'   => [8],
            'rpm'             => 5,
            'threshold'       => 7.5,
            'gatilho'         => 'Curiosidade, Novidade, Solução de Problemas, Inspiração',
            'persona'         => 'contador de histórias e divulgador de curiosidades, com foco em revelar o inesperado e simplificar o complexo',
            'keywords_match'  => ['curiosidade','fato','história','mistério','misterioso','bizarro','incrível','inacreditável','enigma','fenômeno estranho','sabia que','você sabia','acontecimento','caso','ocorrência'],
            'termos_semanticos' => ['fato','história','mito','verdade','explicação','solução','dica'],
            'compliance'      => [
                'veracidade básica: fatos históricos/curiosidades com fonte (livro, enciclopédia, instituição)',
                'se o tema tangencia saúde/finanças/legal, aplicar compliance específico (não dar conselho médico/financeiro/legal)',
                'evitar "100% das pessoas não sabem" quando não há pesquisa — usar "muitas pessoas não sabem"',
                'casos históricos: citar período e documentação',
                'teorias/lendas: deixar claro que é TEORIA/LENDA, não fato confirmado',
            ],
            'angulos' => [
                'O FATO inesperado sobre [TEMA] que quase ninguém conhece e vai te surpreender',
                'A SOLUÇÃO simples para [PROBLEMA COTIDIANO] que ninguém te conta',
                '[N] coisas que você faz errado todo dia e podem [CONSEQUÊNCIA]',
                'O SEGREDO por trás de [FENÔMENO/MITO] que a ciência/história revela',
            ],
            'termos_proibidos' => [
                ['pattern' => '/\b100%\s+das\s+pessoas\s+(?:não\s+sabem|desconhecem)\b/iu', 'erro' => 'Absoluto estatístico sem base'],
                ['pattern' => '/\b(?:impossível\s+de\s+explicar|inexplicável\s+até\s+hoje|até\s+hoje\s+ninguém\s+consegue)\b/iu', 'erro' => 'Sensacionalismo sem base'],
            ],
            'inclusao_obrigatoria' => [],
        ],
    ];

    /** Retorna o bloco completo de um cluster, ou null se não existir. */
    public static function cluster(string $key): ?array
    {
        return self::$clusters[$key] ?? null;
    }

    /** Retorna todos os clusters (chave => bloco). */
    public static function todos(): array
    {
        return self::$clusters;
    }

    /** Retorna só as chaves (útil para loops/validação). */
    public static function chaves(): array
    {
        return array_keys(self::$clusters);
    }

    /** Retorna um campo específico de um cluster (ex: 'rpm', 'threshold'). */
    public static function campo(string $clusterKey, string $field, mixed $default = null): mixed
    {
        return self::$clusters[$clusterKey][$field] ?? $default;
    }

    /** RPM por cluster — substitui DiscoverRPM::$rpmPorCluster. */
    public static function rpm(string $clusterKey): int
    {
        return (int)self::campo($clusterKey, 'rpm', self::$clusters['curiosidades_geral']['rpm']);
    }

    /** Threshold por cluster — substitui DiscoverScore::$thresholdsPorCluster. */
    public static function threshold(string $clusterKey): float
    {
        return (float)self::campo($clusterKey, 'threshold', 7.0);
    }

    /** Grupo editorial — substitui DiscoverAngulo::$grupoPorCluster. */
    public static function grupoEditorial(string $clusterKey): string
    {
        return (string)self::campo($clusterKey, 'grupo_editorial', 'GERAL');
    }

    /**
     * ROI editorial 1-10: sinal composto de "vale a pena gerar artigo neste cluster?".
     * Normaliza RPM contra o máximo do catálogo (42) → 1-10. Útil para ordenar
     * trends candidatos e para dashboards de decisão editorial.
     *
     * Fórmula: rpm_cluster / rpm_max * 10, clampado em [1, 10].
     * Exemplos: negocios_financas (42) → 10.0 · esportes (6) → 1.4 · curiosidades (5) → 1.2.
     */
    public static function roiEditorial(string $clusterKey): float
    {
        static $rpmMax = null;
        if ($rpmMax === null) {
            $rpms = array_map(fn($c) => (int)$c['rpm'], self::$clusters);
            $rpmMax = max($rpms) ?: 1;
        }
        $rpm = self::rpm($clusterKey);
        return round(max(1.0, min(10.0, ($rpm / $rpmMax) * 10)), 1);
    }

    /**
     * Label curto de cluster pra chips/badges da UI. Máx 9 caracteres.
     * Ex: "noticias_info_critica" → "Notícia", "negocios_financas" → "Finanças".
     */
    public static function labelCurto(string $clusterKey): string
    {
        return match ($clusterKey) {
            'noticias_info_critica'  => 'Notícia',
            'negocios_financas'      => 'Finanças',
            'saude_bem_estar'        => 'Saúde',
            'tecnologia'             => 'Tech',
            'entretenimento_cultura' => 'Entreten.',
            'esportes'               => 'Esportes',
            'lifestyle_consumo'      => 'Lifestyle',
            'automoveis'             => 'Auto',
            'ciencia'                => 'Ciência',
            'pets_animais'           => 'Pets',
            'viagem_transporte'      => 'Viagem',
            'comidas_bebidas'        => 'Comida',
            'curiosidades_geral'     => 'Geral',
            default                  => '—',
        };
    }

    /**
     * Emoji ranking por ROI editorial — feedback visual imediato.
     * ROI ≥7 = 💎 (ouro), 3-7 = ⭐ (bom), <3 = ⚪ (baixo).
     */
    public static function emojiRoi(string $clusterKey): string
    {
        $roi = self::roiEditorial($clusterKey);
        if ($roi >= 7.0) return '💎';
        if ($roi >= 3.0) return '⭐';
        return '⚪';
    }

    /**
     * Classe CSS por ranking de ROI — compatível com convenção `.roi-alto`/`.roi-medio`/`.roi-baixo`.
     */
    public static function classeRoi(string $clusterKey): string
    {
        $roi = self::roiEditorial($clusterKey);
        if ($roi >= 7.0) return 'roi-alto';
        if ($roi >= 3.0) return 'roi-medio';
        return 'roi-baixo';
    }

    /**
     * Categoria Google → grupo editorial (fallback quando cluster não é detectado).
     * Derivado do grupo_editorial do cluster principal de cada categoria.
     */
    public static function grupoPorCategoriaGoogle(int $catId): string
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            // Mapeamento prioritário: algumas categorias mapeiam melhor que outras
            $prioridade = [
                1  => 'automoveis',
                2  => 'lifestyle_consumo',
                3  => 'negocios_financas',
                4  => 'entretenimento_cultura',
                5  => 'comidas_bebidas',
                6  => 'entretenimento_cultura',       // Jogos/loterias → entretenimento
                7  => 'saude_bem_estar',
                8  => 'curiosidades_geral',           // Feriados/tradições
                9  => 'noticias_info_critica',        // Empregos/educação → NOTÍCIA/serviço
                10 => 'noticias_info_critica',        // Lei e governo
                11 => 'noticias_info_critica',        // Outras/notícia geral
                12 => 'lifestyle_consumo',            // Hobbies e lazer
                13 => 'pets_animais',
                14 => 'noticias_info_critica',        // Política → NOTÍCIA
                15 => 'ciencia',
                16 => 'entretenimento_cultura',       // Livros
                17 => 'esportes',
                18 => 'tecnologia',
                19 => 'viagem_transporte',
                20 => 'noticias_info_critica',        // Clima → NOTÍCIA/alerta
            ];
            foreach ($prioridade as $catId_ => $clusterKey) {
                $cache[$catId_] = self::grupoEditorial($clusterKey);
            }
            // EDUCAÇÃO específico para cat 9 (empregos/educação) quando quiser distinguir
            $cache[9] = 'EDUCAÇÃO';
        }
        return $cache[$catId] ?? 'GERAL';
    }

    /** Label por ID Google. */
    public static function labelCategoriaGoogle(int $catId): string
    {
        return self::CATEGORIAS_GOOGLE[$catId] ?? "cat#{$catId}";
    }

    /**
     * Valida a integridade da taxonomia — chamado por scripts/validar_taxonomia.php.
     * Retorna lista de problemas (vazia = ok).
     */
    public static function validar(): array
    {
        $problemas = [];
        $camposObrigatorios = [
            'nome', 'grupo_editorial', 'categoria_ids', 'rpm', 'threshold',
            'gatilho', 'persona', 'keywords_match', 'termos_semanticos',
            'compliance', 'angulos', 'termos_proibidos', 'inclusao_obrigatoria',
        ];
        $gruposValidos = ['PRODUTO','TECNOLOGIA','NOTÍCIA','ENTRETENIMENTO','ESPORTES','EDUCAÇÃO','FINANÇAS','GERAL'];

        foreach (self::$clusters as $key => $c) {
            foreach ($camposObrigatorios as $f) {
                if (!array_key_exists($f, $c)) {
                    $problemas[] = "[{$key}] campo faltando: {$f}";
                }
            }
            if (isset($c['grupo_editorial']) && !in_array($c['grupo_editorial'], $gruposValidos, true)) {
                $problemas[] = "[{$key}] grupo_editorial inválido: {$c['grupo_editorial']}";
            }
            if (isset($c['rpm']) && ($c['rpm'] < 1 || $c['rpm'] > 100)) {
                $problemas[] = "[{$key}] rpm fora de faixa (1-100): {$c['rpm']}";
            }
            if (isset($c['threshold']) && ($c['threshold'] < 3.0 || $c['threshold'] > 10.0)) {
                $problemas[] = "[{$key}] threshold fora de faixa (3.0-10.0): {$c['threshold']}";
            }
            if (!empty($c['keywords_match']) && count($c['keywords_match']) < 5) {
                $problemas[] = "[{$key}] keywords_match com menos de 5 entradas (ambíguo)";
            }
            foreach (($c['termos_proibidos'] ?? []) as $i => $p) {
                if (!isset($p['pattern'], $p['erro'])) {
                    $problemas[] = "[{$key}] termos_proibidos[{$i}] sem pattern ou erro";
                    continue;
                }
                // Valida regex compila
                if (@preg_match($p['pattern'], '') === false) {
                    $problemas[] = "[{$key}] termos_proibidos[{$i}] regex inválida";
                }
            }
            foreach (($c['categoria_ids'] ?? []) as $cid) {
                if (!isset(self::CATEGORIAS_GOOGLE[$cid])) {
                    $problemas[] = "[{$key}] categoria_id {$cid} não existe em CATEGORIAS_GOOGLE";
                }
            }
        }

        // Validação global: curiosidades_geral deve ser o último (é fallback).
        $chaves = array_keys(self::$clusters);
        if (end($chaves) !== 'curiosidades_geral') {
            $problemas[] = 'curiosidades_geral deve ser o último cluster (fallback ordenado)';
        }

        return $problemas;
    }
}
