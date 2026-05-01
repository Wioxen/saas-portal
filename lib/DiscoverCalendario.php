<?php
/**
 * Calendário sazonal preditivo — Fase 1 da estratégia de viralização.
 *
 * Lista mestra de eventos recorrentes BR com:
 *  - data do pico (fixa ou calculada)
 *  - janela ideal de produção (D-X a D-Y antes do pico)
 *  - tema-semente pra validação histórica via Trends
 *  - cluster sugerido (hub + satélites)
 *  - categoria editorial (pra mapear no Angulo existente)
 *
 * Datas móveis (Páscoa, Carnaval, Black Friday, Dia das Mães/Pais) são calculadas
 * dinamicamente em função do ano solicitado.
 */
class DiscoverCalendario
{
    /** Catálogo estático — eventos fixos (MM-DD) e móveis (callable). */
    public static function catalogo(): array
    {
        return [
            // ═══ FERIADOS NACIONAIS + GRANDES DATAS ═══
            ['nome'=>'Ano Novo','data'=>'01-01','ant'=>25,'tema'=>'ano novo','cat'=>'FERIADO','cluster'=>[
                'Mensagens de Ano Novo prontas para WhatsApp',
                'Simpatias de Ano Novo que funcionam',
                'Horóscopo do Ano Novo: o que esperar',
                'Frases de Réveillon para status',
            ]],
            ['nome'=>'Carnaval','data'=>fn($y)=>self::carnaval($y),'ant'=>25,'tema'=>'carnaval','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Carnaval: datas, blocos e calendário completo',
                'Feriado de Carnaval é nacional? Veja as regras',
                'Fantasias de Carnaval baratas e criativas',
                'Frases engraçadas de Carnaval para WhatsApp',
            ]],
            ['nome'=>'Dia Internacional da Mulher','data'=>'03-08','ant'=>15,'tema'=>'dia da mulher','cat'=>'DATA','cluster'=>[
                '50 mensagens para o Dia da Mulher',
                'Por que se comemora o Dia Internacional da Mulher?',
                'Frases de Dia da Mulher para status',
                'Presentes baratos para o Dia da Mulher',
            ]],
            ['nome'=>'Dia do Consumidor','data'=>'03-15','ant'=>12,'tema'=>'dia do consumidor','cat'=>'COMPRAS','cluster'=>[
                'Dia do Consumidor: melhores ofertas',
                'Seus direitos no Dia do Consumidor',
                'Lojas com desconto no Dia do Consumidor',
            ]],
            ['nome'=>'Páscoa','data'=>fn($y)=>self::pascoa($y),'ant'=>20,'tema'=>'páscoa','cat'=>'DATA','cluster'=>[
                'Receitas de Páscoa que estão viralizando',
                'Mensagens de Páscoa para WhatsApp',
                'Quando cai a Páscoa? Veja a data',
                'Ovo de Páscoa caseiro: passo a passo',
            ]],
            ['nome'=>'Tiradentes','data'=>'04-21','ant'=>10,'tema'=>'tiradentes feriado','cat'=>'FERIADO','cluster'=>[
                '21 de abril é feriado nacional? Veja regras',
                'Quem foi Tiradentes? História resumida',
                'Feriadão de Tiradentes: o que abre e fecha',
            ]],
            ['nome'=>'Dia do Trabalhador','data'=>'05-01','ant'=>20,'tema'=>'dia do trabalhador','cat'=>'FERIADO','cluster'=>[
                '1º de Maio: quem trabalhar no feriado recebe quanto?',
                'Direitos trabalhistas no feriado que você não conhece',
                '50 frases para o Dia do Trabalhador (WhatsApp)',
                'Vai ter feriadão em 2026? Calendário completo',
            ]],
            ['nome'=>'Dia das Mães','data'=>fn($y)=>self::diaDasMaes($y),'ant'=>20,'tema'=>'dia das mães','cat'=>'DATA','cluster'=>[
                'Presentes baratos de Dia das Mães que emocionam',
                'Mensagens de Dia das Mães para WhatsApp',
                'Ideias de Dia das Mães em casa (baratas)',
                'Quando é o Dia das Mães? Veja a data',
                'Frases curtas de Dia das Mães para status',
            ]],
            ['nome'=>'Corpus Christi','data'=>fn($y)=>self::corpusChristi($y),'ant'=>12,'tema'=>'corpus christi feriado','cat'=>'FERIADO','cluster'=>[
                'Corpus Christi 2026: é feriado nacional?',
                'O que abre e fecha no Corpus Christi',
                'Feriado de Corpus Christi: calendário',
            ]],
            ['nome'=>'Dia dos Namorados','data'=>'06-12','ant'=>20,'tema'=>'dia dos namorados','cat'=>'DATA','cluster'=>[
                'Presentes baratos de Dia dos Namorados',
                'Mensagens românticas para Dia dos Namorados',
                'Surpresas simples para o Dia dos Namorados',
                'Jantar romântico em casa barato',
                'Ideias de última hora para Dia dos Namorados',
            ]],
            ['nome'=>'Festas Juninas','data'=>'06-24','ant'=>25,'tema'=>'festa junina','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Comidas típicas de Festa Junina',
                'Decoração junina barata e simples',
                'Looks para Festa Junina',
                'Brincadeiras de Festa Junina para crianças',
                'Receitas de Festa Junina que bombam',
            ]],
            ['nome'=>'Dia do Amigo','data'=>'07-20','ant'=>10,'tema'=>'dia do amigo','cat'=>'DATA','cluster'=>[
                'Mensagens para o Dia do Amigo',
                'Dia do Amigo ou Dia da Amizade? Qual a diferença',
                'Frases para o Dia do Amigo no WhatsApp',
            ]],
            ['nome'=>'Dia dos Avós','data'=>'07-26','ant'=>10,'tema'=>'dia dos avós','cat'=>'DATA','cluster'=>[
                'Mensagens para o Dia dos Avós',
                'Presentes simples para os avós',
            ]],
            ['nome'=>'Dia dos Pais','data'=>fn($y)=>self::diaDosPais($y),'ant'=>20,'tema'=>'dia dos pais','cat'=>'DATA','cluster'=>[
                'Presentes baratos de Dia dos Pais',
                'Mensagens para Dia dos Pais (copiar e colar)',
                'Quando é o Dia dos Pais? Veja a data',
                'Ideias criativas para o Dia dos Pais',
            ]],
            ['nome'=>'Dia do Estudante','data'=>'08-11','ant'=>7,'tema'=>'dia do estudante','cat'=>'EDUCAÇÃO','cluster'=>[
                'Mensagens para o Dia do Estudante',
                'Frases motivacionais de Dia do Estudante',
            ]],
            ['nome'=>'Independência do Brasil','data'=>'09-07','ant'=>12,'tema'=>'7 de setembro','cat'=>'FERIADO','cluster'=>[
                '7 de Setembro é feriado prolongado? Veja',
                'Mensagens de 7 de Setembro para WhatsApp',
                'O que abre e fecha no feriado da Independência',
            ]],
            ['nome'=>'Dia das Crianças','data'=>'10-12','ant'=>20,'tema'=>'dia das crianças','cat'=>'DATA','cluster'=>[
                'Presentes baratos de Dia das Crianças',
                'Brincadeiras para o Dia das Crianças em casa',
                'Mensagens para Dia das Crianças',
                'Ideias criativas para Dia das Crianças',
            ]],
            ['nome'=>'Dia do Professor','data'=>'10-15','ant'=>10,'tema'=>'dia do professor','cat'=>'EDUCAÇÃO','cluster'=>[
                'Mensagens para o Dia do Professor',
                'Presentes simples para o Dia do Professor',
                'Frases de Dia do Professor para WhatsApp',
            ]],
            ['nome'=>'Finados','data'=>'11-02','ant'=>10,'tema'=>'finados feriado','cat'=>'FERIADO','cluster'=>[
                'Finados 2026: é feriadão?',
                'O que abre e fecha no Dia de Finados',
            ]],
            ['nome'=>'Proclamação da República','data'=>'11-15','ant'=>8,'tema'=>'proclamação da república','cat'=>'FERIADO','cluster'=>[
                '15 de Novembro é feriado nacional?',
                'O que abre e fecha no feriadão',
            ]],
            ['nome'=>'Consciência Negra','data'=>'11-20','ant'=>10,'tema'=>'consciência negra','cat'=>'DATA','cluster'=>[
                '20 de Novembro é feriado nacional em 2026?',
                'Frases para o Dia da Consciência Negra',
                'Mensagens de Consciência Negra para WhatsApp',
            ]],
            ['nome'=>'Black November (mês de antecipação)','data'=>'11-01','ant'=>10,'tema'=>'black november','cat'=>'COMPRAS','cluster'=>[
                'Black November 2026: lojas que já começaram',
                'Black November vale a pena ou esperar a Black Friday?',
                'Calendário de ofertas antecipadas de novembro',
                'Erros comuns na Black November que custam dinheiro',
            ]],
            ['nome'=>'Singles Day 11/11','data'=>'11-11','ant'=>10,'tema'=>'singles day 11/11','cat'=>'COMPRAS','cluster'=>[
                'Singles Day 11/11: o Black Friday chinês chega ao Brasil',
                'Melhores ofertas do Singles Day no Brasil',
                'Como aproveitar o 11/11 sem cair em golpes',
            ]],
            ['nome'=>'Black Friday','data'=>fn($y)=>self::blackFriday($y),'ant'=>25,'tema'=>'black friday','cat'=>'COMPRAS','cluster'=>[
                'Black Friday 2026: quando começa e o que esperar',
                'Como evitar golpes na Black Friday',
                'Melhores ofertas antecipadas de Black Friday',
                'Black Friday vale a pena? Dicas para economizar',
                'Erros comuns que fazem você pagar mais na Black Friday',
            ]],
            ['nome'=>'Cyber Monday','data'=>fn($y)=>self::cyberMonday($y),'ant'=>15,'tema'=>'cyber monday','cat'=>'COMPRAS','cluster'=>[
                'Cyber Monday 2026: o que esperar nas ofertas online',
                'Diferença entre Cyber Monday e Black Friday',
                'Eletrônicos com maior desconto na Cyber Monday',
                'Cupons e cashback para a Cyber Monday',
            ]],
            ['nome'=>'Halloween','data'=>'10-31','ant'=>20,'tema'=>'halloween dia das bruxas','cat'=>'COMPRAS','cluster'=>[
                'Fantasias de Halloween baratas e criativas',
                'Halloween: decoração simples para festa',
                'Receitas de Halloween para fazer em casa',
                'Filmes de terror para o Halloween',
            ]],
            ['nome'=>'Boxing Day','data'=>'12-26','ant'=>10,'tema'=>'boxing day liquidação','cat'=>'COMPRAS','cluster'=>[
                'Boxing Day no Brasil: lojas com queima pós-Natal',
                'Boxing Day vale a pena? Calendário de ofertas',
                'O que comprar no Boxing Day para economizar',
            ]],
            ['nome'=>'Natal','data'=>'12-25','ant'=>25,'tema'=>'natal','cat'=>'DATA','cluster'=>[
                'Mensagens de Natal para WhatsApp',
                'Presentes baratos de Natal',
                'Receitas de Natal fáceis',
                'Decoração de Natal barata em casa',
                'Frases curtas de Natal para status',
            ]],
            ['nome'=>'Réveillon','data'=>'12-31','ant'=>20,'tema'=>'ano novo réveillon','cat'=>'DATA','cluster'=>[
                'Simpatias de Ano Novo',
                'Look branco para o Réveillon barato',
                'Mensagens de Ano Novo 2027 prontas',
                'Horóscopo 2027: o que esperar',
            ]],

            // ═══ SAZONAIS INSTITUCIONAIS (alto CPC, público gigante) ═══
            ['nome'=>'Declaração IR (abertura)','data'=>'03-05','ant'=>15,'tema'=>'imposto de renda 2026','cat'=>'FINANÇAS','cluster'=>[
                'IR 2026: quem é obrigado a declarar?',
                'Documentos para declaração do IR 2026',
                'Como declarar IR passo a passo',
                'Restituição do IR: quando cai?',
            ]],
            ['nome'=>'Prazo final IR','data'=>'05-28','ant'=>15,'tema'=>'prazo imposto de renda','cat'=>'FINANÇAS','cluster'=>[
                'Último dia para declarar IR: veja o prazo',
                'Quem não declarou IR ainda: o que fazer',
                'Multa por atraso no IR: quanto custa',
            ]],
            ['nome'=>'Primeira parcela 13º','data'=>'11-20','ant'=>15,'tema'=>'13º salário primeira parcela','cat'=>'FINANÇAS','cluster'=>[
                '13º salário: quando cai a primeira parcela?',
                'Como calcular o 13º salário',
                'Tem desconto no 13º? Entenda',
            ]],
            ['nome'=>'Segunda parcela 13º','data'=>'12-18','ant'=>10,'tema'=>'13º salário segunda parcela','cat'=>'FINANÇAS','cluster'=>[
                'Segunda parcela do 13º: data e valor',
                'Como usar o 13º sem endividar',
            ]],
            ['nome'=>'MCMV calendário anual','data'=>'01-15','ant'=>20,'tema'=>'minha casa minha vida 2026','cat'=>'SERVICO','cluster'=>[
                'Minha Casa Minha Vida 2026: como se inscrever',
                'MCMV: valor e faixas de renda atualizadas',
                'Calendário de pagamento do MCMV',
                'Quem tem direito ao Minha Casa Minha Vida',
            ]],
            ['nome'=>'Saque-aniversário FGTS','data'=>'01-10','ant'=>15,'tema'=>'saque aniversário fgts','cat'=>'SERVICO','cluster'=>[
                'Saque-aniversário FGTS: como funciona',
                'Calendário do FGTS aniversário 2026',
                'Vale a pena aderir ao saque-aniversário?',
            ]],
            ['nome'=>'PIS/Pasep pagamento','data'=>'05-15','ant'=>20,'tema'=>'pis pasep pagamento','cat'=>'SERVICO','cluster'=>[
                'PIS/Pasep 2026: calendário de pagamento',
                'Quem tem direito ao PIS/Pasep',
                'Como consultar PIS/Pasep',
            ]],
            ['nome'=>'Bolsa Família início mês','data'=>'01-18','ant'=>5,'tema'=>'bolsa família calendário','cat'=>'SERVICO','cluster'=>[
                'Bolsa Família: calendário de pagamento do mês',
                'Valor do Bolsa Família atualizado',
                'Como consultar Bolsa Família pelo Caixa Tem',
            ]],
            ['nome'=>'Aniversário do PIX','data'=>'11-16','ant'=>7,'tema'=>'pix aniversário','cat'=>'FINANÇAS','cluster'=>[
                'PIX completa X anos: o que mudou',
                'Novidades do PIX em 2026',
                'PIX vs TED: ainda vale a pena usar TED?',
            ]],
            ['nome'=>'Dia do Idoso','data'=>'10-01','ant'=>10,'tema'=>'dia do idoso','cat'=>'DATA','cluster'=>[
                'Dia do Idoso: direitos que muitos não conhecem',
                'Mensagens para o Dia do Idoso',
                'Benefícios para idosos a partir de 60 anos',
            ]],

            // ═══ EDUCAÇÃO ═══
            ['nome'=>'Enem inscrições','data'=>'05-15','ant'=>20,'tema'=>'enem inscrição','cat'=>'EDUCAÇÃO','cluster'=>[
                'Enem 2026: como se inscrever',
                'Isenção da taxa do Enem: quem tem direito',
                'Prazo das inscrições do Enem',
                'Erros comuns que invalidam a inscrição',
            ]],
            ['nome'=>'Enem primeiro dia','data'=>'11-08','ant'=>30,'tema'=>'enem prova','cat'=>'EDUCAÇÃO','cluster'=>[
                'Enem 2026: o que cai na prova',
                'Como se preparar para o Enem em 1 mês',
                'Documentos para levar no Enem',
                'Horário e local de prova do Enem',
            ]],
            ['nome'=>'SISU','data'=>'01-20','ant'=>15,'tema'=>'sisu 2026','cat'=>'EDUCAÇÃO','cluster'=>[
                'SISU 2026: como se inscrever',
                'Nota de corte do SISU',
                'Cursos mais concorridos no SISU',
            ]],
            ['nome'=>'ProUni','data'=>'01-25','ant'=>10,'tema'=>'prouni 2026','cat'=>'EDUCAÇÃO','cluster'=>[
                'ProUni 2026: como se inscrever',
                'Bolsas do ProUni: 100% vs 50%',
                'Requisitos para o ProUni',
            ]],
            ['nome'=>'Volta às aulas (1sem)','data'=>'02-05','ant'=>15,'tema'=>'volta às aulas','cat'=>'EDUCAÇÃO','cluster'=>[
                'Volta às aulas: lista de material escolar',
                'Como economizar na volta às aulas',
                'Dicas para a volta às aulas de filhos',
            ]],
            ['nome'=>'Volta às aulas (2sem)','data'=>'07-28','ant'=>10,'tema'=>'volta às aulas segundo semestre','cat'=>'EDUCAÇÃO','cluster'=>[
                'Volta às aulas no segundo semestre: o que mudou',
                'Material escolar: o que reaproveitar do 1º semestre',
                'Como organizar a rotina pós-férias de julho',
            ]],
            ['nome'=>'FIES inscrições (1sem)','data'=>'02-15','ant'=>15,'tema'=>'fies inscrição','cat'=>'EDUCAÇÃO','cluster'=>[
                'FIES 2026: como se inscrever passo a passo',
                'FIES: quem tem direito ao financiamento',
                'Diferenças entre FIES, ProUni e SISU',
                'Documentos para o FIES',
            ]],
            ['nome'=>'FIES inscrições (2sem)','data'=>'08-15','ant'=>15,'tema'=>'fies segundo semestre','cat'=>'EDUCAÇÃO','cluster'=>[
                'FIES 2026/2: cronograma do segundo semestre',
                'Quem ficou de fora no 1º semestre pode tentar agora',
                'FIES segundo semestre: prazos e cursos',
            ]],
            ['nome'=>'Fuvest 1ª fase','data'=>'11-17','ant'=>20,'tema'=>'fuvest prova','cat'=>'EDUCAÇÃO','cluster'=>[
                'Fuvest 2026: o que cai na 1ª fase',
                'Como se preparar para a Fuvest em 30 dias',
                'Cursos mais concorridos da Fuvest',
                'Fuvest vs Enem: estratégia para passar',
            ]],

            // ═══ EFEMÉRIDES DE PESSOAS (pico anual garantido) ═══
            ['nome'=>'Ayrton Senna (nascimento)','data'=>'03-21','ant'=>10,'tema'=>'ayrton senna nascimento','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Senna faria X anos: o legado em 2026',
                'Curiosidades sobre o nascimento de Ayrton Senna',
                'Família Senna: a influência da família no piloto',
            ]],
            ['nome'=>'Ayrton Senna (morte)','data'=>'05-01','ant'=>15,'tema'=>'ayrton senna','cat'=>'ENTRETENIMENTO','cluster'=>[
                'O que aconteceu com Ayrton Senna em 1º de maio de 1994',
                'Curiosidades sobre Ayrton Senna que voltaram a viralizar',
                'O último dia de Ayrton Senna: detalhes',
                'O legado de Ayrton Senna na Fórmula 1',
            ]],
            ['nome'=>'Michael Jackson (morte)','data'=>'06-25','ant'=>10,'tema'=>'michael jackson','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Michael Jackson: o que aconteceu naquele dia',
                'Curiosidades sobre Michael Jackson',
                'Melhores músicas de Michael Jackson',
            ]],
            ['nome'=>'Elvis Presley (morte)','data'=>'08-16','ant'=>10,'tema'=>'elvis presley','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Elvis Presley: o que aconteceu em 16 de agosto',
                'Curiosidades sobre Elvis Presley',
                'O último show de Elvis Presley',
            ]],
            ['nome'=>'Marília Mendonça (morte)','data'=>'11-05','ant'=>10,'tema'=>'marília mendonça','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Marília Mendonça: o legado que continua emocionando',
                'Curiosidades sobre Marília Mendonça',
                'Melhores músicas de Marília Mendonça',
            ]],
            ['nome'=>'Princesa Diana (morte)','data'=>'08-31','ant'=>10,'tema'=>'princesa diana','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Princesa Diana: o que aconteceu naquela noite',
                'Curiosidades sobre a Princesa Diana',
                'Por que Diana ainda emociona milhões',
            ]],
            ['nome'=>'Chorão (morte)','data'=>'03-06','ant'=>7,'tema'=>'chorão charlie brown','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Chorão: fatos que voltaram a viralizar',
                'Por que Chorão ainda é tão lembrado',
                'Melhores músicas do Charlie Brown Jr',
            ]],
            ['nome'=>'Cazuza (morte)','data'=>'07-07','ant'=>7,'tema'=>'cazuza','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Cazuza: frases e histórias que emocionam',
                'O legado de Cazuza',
            ]],
            ['nome'=>'Maradona (morte)','data'=>'11-25','ant'=>7,'tema'=>'maradona','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Maradona: o dia que parou o futebol',
                'Curiosidades sobre Maradona',
            ]],
            ['nome'=>'Pelé (morte)','data'=>'12-29','ant'=>7,'tema'=>'pelé rei','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Pelé: o legado que nunca morre',
                'Curiosidades sobre o Rei Pelé',
            ]],
            ['nome'=>'Mamonas Assassinas (morte)','data'=>'03-02','ant'=>7,'tema'=>'mamonas assassinas','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Mamonas Assassinas: o acidente que marcou o Brasil',
                'Curiosidades sobre os Mamonas Assassinas',
            ]],
            ['nome'=>'Bob Marley (morte)','data'=>'05-11','ant'=>7,'tema'=>'bob marley','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Bob Marley: o legado do reggae',
                'Curiosidades sobre Bob Marley',
            ]],
            ['nome'=>'Freddie Mercury (morte)','data'=>'11-24','ant'=>7,'tema'=>'freddie mercury','cat'=>'ENTRETENIMENTO','cluster'=>[
                'Freddie Mercury: o último show do Queen',
                'Curiosidades sobre Freddie Mercury',
            ]],
        ];
    }

    /**
     * Retorna os eventos nos próximos $dias, com a data resolvida para o ano atual/próximo.
     * @return array cada item tem: nome, data_pico (YYYY-MM-DD), dias_ate, status, tema, cat, cluster, antecipacao_ideal
     */
    public static function proximos(int $dias = 60, ?DateTime $hoje = null): array
    {
        $hoje = $hoje ?: new DateTime('today');
        $anoBase = (int)$hoje->format('Y');
        $limite = (clone $hoje)->modify("+{$dias} days");

        $out = [];
        foreach (self::catalogo() as $ev) {
            // Resolve a data pro ano atual E pro próximo (capta eventos de início de ano)
            foreach ([$anoBase, $anoBase + 1] as $ano) {
                $dataPico = self::resolverData($ev['data'], $ano);
                if (!$dataPico) continue;
                $dt = DateTime::createFromFormat('Y-m-d', $dataPico);
                if (!$dt) continue;
                if ($dt < $hoje || $dt > $limite) continue;

                $diasAte = (int)$hoje->diff($dt)->format('%a');
                $ant = (int)$ev['ant'];
                // Status por proximidade/janela ideal
                if ($diasAte <= 3)             $status = 'hoje';         // pico iminente/acontecendo
                elseif ($diasAte <= $ant)      $status = 'acionavel';    // dentro da janela — publicar agora
                elseif ($diasAte <= $ant + 10) $status = 'aproximando';  // quase na janela
                else                            $status = 'futuro';

                $out[] = [
                    'nome'         => $ev['nome'],
                    'data_pico'    => $dataPico,
                    'dias_ate'     => $diasAte,
                    'status'       => $status,
                    'tema'         => $ev['tema'],
                    'categoria'    => $ev['cat'],
                    'antecipacao'  => $ant,
                    'cluster'      => $ev['cluster'],
                    'data_historica_inicio' => date('Y-m-d', strtotime($dataPico . ' -' . ($ant+5) . ' days -1 year')),
                    'data_historica_fim'    => date('Y-m-d', strtotime($dataPico . ' +5 days -1 year')),
                ];
            }
        }

        usort($out, fn($a, $b) => $a['dias_ate'] <=> $b['dias_ate']);
        return $out;
    }

    private static function resolverData($data, int $ano): ?string
    {
        if (is_callable($data)) {
            return $data($ano);
        }
        if (is_string($data) && preg_match('/^\d{2}-\d{2}$/', $data)) {
            return $ano . '-' . $data;
        }
        return null;
    }

    // ═══ CÁLCULO DE DATAS MÓVEIS ═══

    /** Páscoa — algoritmo de Gauss (funciona sem ext-calendar). */
    public static function pascoa(int $ano): string
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
    }

    /** Terça-feira de carnaval — 47 dias antes da Páscoa. */
    public static function carnaval(int $ano): string
    {
        $ts = strtotime(self::pascoa($ano) . ' -47 days');
        return date('Y-m-d', $ts);
    }

    /** Corpus Christi — 60 dias após a Páscoa (sempre quinta-feira). */
    public static function corpusChristi(int $ano): string
    {
        $ts = strtotime(self::pascoa($ano) . ' +60 days');
        return date('Y-m-d', $ts);
    }

    /** Dia das Mães — 2º domingo de maio. */
    public static function diaDasMaes(int $ano): string
    {
        return self::nthDayOfWeek($ano, 5, 0, 2);
    }

    /** Dia dos Pais — 2º domingo de agosto. */
    public static function diaDosPais(int $ano): string
    {
        return self::nthDayOfWeek($ano, 8, 0, 2);
    }

    /** Black Friday — 4ª sexta-feira de novembro (dia seguinte ao Thanksgiving US). */
    public static function blackFriday(int $ano): string
    {
        return self::nthDayOfWeek($ano, 11, 5, 4);
    }

    /** Cyber Monday — segunda-feira após a Black Friday (3 dias depois). */
    public static function cyberMonday(int $ano): string
    {
        $ts = strtotime(self::blackFriday($ano) . ' +3 days');
        return date('Y-m-d', $ts);
    }

    /** N-ésimo dia-da-semana do mês. $dow 0=dom, 1=seg...6=sab. */
    private static function nthDayOfWeek(int $ano, int $mes, int $dow, int $n): string
    {
        $dt = new DateTime(sprintf('%04d-%02d-01', $ano, $mes));
        $primeiroDow = (int)$dt->format('w');
        $offset = ($dow - $primeiroDow + 7) % 7;
        $dia = 1 + $offset + ($n - 1) * 7;
        return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
    }
}
