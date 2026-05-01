<?php
/**
 * Antecipação Sazonal — busca trends históricos do Google Trends pra eventos próximos
 * e cria registros 'aprovado' no DB ANTES do pico, dando vantagem competitiva.
 *
 * Estratégia:
 *  - DiscoverCalendario lista eventos próximos (≤ N dias) com data_historica_inicio/fim
 *    já calculadas para o MESMO período do ano passado.
 *  - Pra cada evento "acionavel" ou "aproximando":
 *    a) busca top + rising queries via TrendsScraperWeb::consultasHistoricas()
 *    b) filtra por relevância (mín tamanho, sem duplicatas)
 *    c) aplica filtro de qualidade do pingo (com bypass: cluster=sazonal_calendario)
 *    d) cria trend no DB com status='aprovado', evento_fonte=nome, score alto (sazonal valida por si)
 *  - Mapeia evento → sites alvo por categoria editorial (DATA/COMPRAS → comocomprar etc.)
 *  - Idempotente: termo+site duplicado é detectado pelo upsert do DiscoverDb.
 *
 * Uso:
 *   php scripts/antecipar_sazonal.php                       → roda full (todos eventos próximos)
 *   php scripts/antecipar_sazonal.php --evento="Dia das Mães"  → só 1 evento
 *   php scripts/antecipar_sazonal.php --dias=30             → janela de N dias (default 60)
 *   php scripts/antecipar_sazonal.php --dry-run             → mostra o que faria sem gravar
 *   php scripts/antecipar_sazonal.php --site=cursosenac     → força um site só
 *   php scripts/antecipar_sazonal.php --max-queries=10      → limita queries por evento
 *
 * Cron sugerido (diário, 3h da manhã):
 *   0 3 * * * /usr/bin/php /caminho/scripts/antecipar_sazonal.php >> data/fila/log_antecipar.log 2>&1
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/DiscoverCalendario.php';
require_once $ROOT . '/lib/TrendsScraperWeb.php';
require_once $ROOT . '/lib/DiscoverScore.php';
require_once $ROOT . '/lib/DiscoverAngulo.php';
require_once $ROOT . '/lib/DiscoverSinaisEditoriais.php';

// Lock global — cron 0 3 * * * pode sobrepor se demorar mais que 1 dia (improvável)
// ou se restart do servidor disparar 2x. Falha graciosa se outra instância rodando.
$sazLock = new CronLock('antecipar_sazonal');
if (!$sazLock->aquirir()) { fwrite(STDERR, "[skip] outro antecipar_sazonal já rodando — saindo.\n"); exit(0); }

$cfg = require $ROOT . '/config.php';

// ── parse args ──
$soEvento   = null;
$diasJanela = 60;
$dryRun     = false;
$forcarSite = null;
$maxQueries = 15;
$verbose    = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--evento='))    $soEvento = substr($arg, 9);
    elseif (str_starts_with($arg, '--dias='))  $diasJanela = (int)substr($arg, 7);
    elseif ($arg === '--dry-run')              $dryRun = true;
    elseif (str_starts_with($arg, '--site='))  $forcarSite = substr($arg, 7);
    elseif (str_starts_with($arg, '--max-queries=')) $maxQueries = (int)substr($arg, 14);
    elseif ($arg === '--verbose')              $verbose = true;
}

$db = new DiscoverDb();
$scraper = new TrendsScraperWeb($cfg['user_agent'] ?? 'Mozilla/5.0 ClonaisAntecipa/1.0');

// ═══════════════════════════════════════════════════════════════
// MAPEAMENTO evento → sites alvo (editar conforme nichos crescem)
// ═══════════════════════════════════════════════════════════════
//
// Categoria do evento → array de slugs de site que devem receber os trends.
// Vazio = não publica nesse site. Ordem importa (primeiro = primário).
//
// Slugs reais em sites.php: comocomprar, vagasebeneficios, cursosenac,
// guiadoscursos, leaodabarra (esporte), ondecompraragora.

$mapaCategoriaSites = [
    // Datas e mensagens — mensagens, presentes, frases (alto volume Discover)
    'DATA'          => ['comocomprar', 'ondecompraragora'],
    // Feriados nacionais — direitos trabalhistas + presentes + viagens
    'FERIADO'       => ['vagasebeneficios', 'comocomprar'],
    // Compras / promoções / Black Friday
    'COMPRAS'       => ['comocomprar', 'ondecompraragora'],
    // Festa Junina, Carnaval (decoração temática) — eventos genéricos
    'ENTRETENIMENTO'=> ['comocomprar', 'ondecompraragora'],
    // Volta às aulas, ENEM, formaturas
    'EDUCAÇÃO'      => ['cursosenac', 'guiadoscursos'],
    // IR, FGTS, 13º — temas tributários e dinheiro
    'FINANÇAS'      => ['vagasebeneficios'],
    // PIS, Bolsa Família, MCMV, saque-aniversário FGTS — benefícios públicos
    'SERVICO'       => ['vagasebeneficios'],
];

// Override por NOME exato do evento (mais granular que categoria — sobrescreve).
// Array vazio = pular este evento (não cabe em nenhum site).
$mapaNomeEvento = [
    // Mortes de celebridades — só Senna casa (F1 = esporte → leaodabarra)
    'Ayrton Senna (morte)'      => ['leaodabarra'],
    'Bob Marley (morte)'        => [],   // música pura, sem site
    'Freddie Mercury (morte)'   => [],   // idem
    'Michael Jackson (morte)'   => [],   // idem
    'Elvis Presley (morte)'     => [],   // idem
    // Eventos sazonais que misturam categorias (manter explícitos pra clareza)
    'Festas Juninas'            => ['comocomprar', 'ondecompraragora'],
    'Carnaval'                  => ['comocomprar', 'ondecompraragora'],
    'Páscoa'                    => ['comocomprar', 'ondecompraragora'],
    'Dia das Crianças'          => ['comocomprar', 'ondecompraragora'],
    'Natal'                     => ['comocomprar', 'ondecompraragora'],
    'Réveillon'                 => ['comocomprar', 'ondecompraragora'],
    // Educação — nomes EXATOS do calendário
    'Enem inscrições'           => ['cursosenac', 'guiadoscursos'],
    'Enem primeiro dia'         => ['cursosenac', 'guiadoscursos'],
    'SISU'                      => ['cursosenac', 'guiadoscursos'],
    'ProUni'                    => ['cursosenac', 'guiadoscursos'],
    'Volta às aulas (1sem)'     => ['cursosenac', 'guiadoscursos'],
    'Dia do Estudante'          => ['cursosenac'],
    'Dia do Professor'          => ['cursosenac', 'guiadoscursos'],
    // Trabalhador, Black Friday, IR
    'Dia do Trabalhador'        => ['vagasebeneficios', 'comocomprar'],
    'Black Friday'              => ['comocomprar', 'ondecompraragora'],
    'Declaração IR (abertura)'  => ['vagasebeneficios'],
    'Prazo final IR'            => ['vagasebeneficios'],
    'Primeira parcela 13º'      => ['vagasebeneficios'],
    'Segunda parcela 13º'       => ['vagasebeneficios'],
];

function siteAlvosPorEvento(array $ev, ?string $forcado = null): array {
    global $mapaCategoriaSites, $mapaNomeEvento;
    if ($forcado !== null) return [$forcado];
    // Prioridade: nome > categoria
    if (array_key_exists($ev['nome'], $mapaNomeEvento)) {
        return $mapaNomeEvento[$ev['nome']];
    }
    return $mapaCategoriaSites[$ev['categoria']] ?? ['comocomprar'];
}

/**
 * Dedup semântico: agrupa queries com >= $threshold de similaridade e mantém 1 por grupo.
 * Prioridade na escolha do representante: curado > top > rising. Em empate, query mais curta.
 *
 * Algoritmo simples (O(N²) — ok pra ≤50 queries por evento):
 *  - Pra cada query, compara contra grupos existentes via similar_text
 *  - Se overlap >= threshold, adiciona ao grupo. Senão, cria grupo novo.
 *  - Ao fim, escolhe o "melhor" representante de cada grupo.
 */
function deduplicarSemantico(array $queries, float $threshold = 0.70): array {
    $prioridade = ['curado' => 3, 'top' => 2, 'rising' => 1];
    $grupos = []; // [['membros' => [...], 'rep' => Q]]

    foreach ($queries as $q) {
        $alocou = false;
        foreach ($grupos as &$g) {
            similar_text(
                mb_strtolower($q['query'], 'UTF-8'),
                mb_strtolower($g['rep']['query'], 'UTF-8'),
                $pct
            );
            if ($pct >= $threshold * 100) {
                $g['membros'][] = $q;
                // Re-escolhe representante: maior prioridade ganha; em empate, mais curta
                $atualPrio = $prioridade[$g['rep']['tipo']] ?? 0;
                $novoPrio  = $prioridade[$q['tipo']] ?? 0;
                if ($novoPrio > $atualPrio
                    || ($novoPrio === $atualPrio && mb_strlen($q['query']) < mb_strlen($g['rep']['query']))) {
                    $g['rep'] = $q;
                }
                $alocou = true;
                break;
            }
        }
        unset($g);
        if (!$alocou) {
            $grupos[] = ['membros' => [$q], 'rep' => $q];
        }
    }

    // Retorna só os representantes
    $out = [];
    foreach ($grupos as $g) {
        $rep = $g['rep'];
        $rep['_grupo_size'] = count($g['membros']); // útil pra debug
        $key = mb_strtolower($rep['query'], 'UTF-8');
        $out[$key] = $rep;
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════
// LOOP principal
// ═══════════════════════════════════════════════════════════════

echo "Antecipação Sazonal — " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('═', 80) . "\n";

$eventos = DiscoverCalendario::proximos($diasJanela);
if (empty($eventos)) {
    echo "Nenhum evento nos próximos {$diasJanela} dias.\n";
    exit(0);
}

if ($soEvento !== null) {
    $eventos = array_values(array_filter($eventos, fn($e) =>
        mb_stripos($e['nome'], $soEvento) !== false
    ));
    if (empty($eventos)) {
        echo "Evento '{$soEvento}' não encontrado nos próximos {$diasJanela} dias.\n";
        exit(0);
    }
}

// Filtra: só eventos 'acionavel' ou 'aproximando' têm urgência editorial
$eventosUteis = array_values(array_filter($eventos, fn($e) =>
    in_array($e['status'], ['hoje', 'acionavel', 'aproximando'], true)
));

if (empty($eventosUteis)) {
    echo count($eventos) . " evento(s) próximo(s) mas nenhum dentro da janela editorial (acionavel/aproximando/hoje).\n";
    echo "Status dos eventos:\n";
    foreach ($eventos as $e) {
        printf("  [%-12s] %-30s pico=%s · %dd · ant=%dd\n",
            $e['status'], $e['nome'], $e['data_pico'], $e['dias_ate'], $e['antecipacao']);
    }
    exit(0);
}

echo count($eventosUteis) . " evento(s) na janela editorial:\n\n";

$totalSalvos = 0;
$totalSkipExistentes = 0;
$totalErros = 0;

foreach ($eventosUteis as $ev) {
    printf("─── %s ─── (%s, status=%s, pico=%s em %dd, ant=%dd)\n",
        $ev['nome'], $ev['categoria'], $ev['status'], $ev['data_pico'], $ev['dias_ate'], $ev['antecipacao']);
    printf("  Tema: '%s' · histórico: %s → %s\n",
        $ev['tema'], $ev['data_historica_inicio'], $ev['data_historica_fim']);

    $sitesAlvo = siteAlvosPorEvento($ev, $forcarSite);
    if (empty($sitesAlvo)) {
        echo "  [skip] evento '{$ev['nome']}' não tem site casado (mapeamento vazio intencional)\n\n";
        continue;
    }
    echo "  Sites alvo: " . implode(', ', $sitesAlvo) . "\n";

    // Busca trends históricos
    try {
        $hist = $scraper->consultasHistoricas(
            $ev['tema'],
            $ev['data_historica_inicio'],
            $ev['data_historica_fim'],
            'BR'
        );
    } catch (Throwable $e) {
        echo "  [ERRO] consultasHistoricas: " . $e->getMessage() . "\n\n";
        $totalErros++;
        continue;
    }

    $top = array_slice($hist['top'] ?? [], 0, $maxQueries);
    $rising = array_slice($hist['rising'] ?? [], 0, $maxQueries);
    printf("  Trends históricos coletados: %d top + %d rising\n", count($top), count($rising));

    if (empty($top) && empty($rising)) {
        echo "  [skip] zero queries históricas — talvez tema com pouco volume na época\n\n";
        continue;
    }

    // Combina top + rising, deduplica EXATA
    $queries = [];
    foreach ($top as $q)    if (!empty($q['query'])) $queries[mb_strtolower($q['query'], 'UTF-8')] = ['query' => $q['query'], 'tipo' => 'top',    'value' => $q['formatted'] ?? $q['value'] ?? ''];
    foreach ($rising as $q) if (!empty($q['query'])) $queries[mb_strtolower($q['query'], 'UTF-8')] = ['query' => $q['query'], 'tipo' => 'rising', 'value' => $q['formatted'] ?? $q['value'] ?? ''];

    // Adiciona os títulos do cluster manual do calendário (já curados)
    foreach ((array)$ev['cluster'] as $titulo) {
        $key = mb_strtolower($titulo, 'UTF-8');
        if (!isset($queries[$key])) {
            $queries[$key] = ['query' => $titulo, 'tipo' => 'curado', 'value' => 'sazonal'];
        }
    }

    // ─── DEDUP SEMÂNTICO ───
    // Agrupa queries similares (>= 70% de overlap textual) e mantém só 1 representante
    // por grupo. Evita scaled content abuse (8 artigos sobre "mensagem dia das mães"
    // no mesmo site = doorway pages, política Google).
    // Prioridade ao manter: curado > top > rising (curado = título editorial limpo).
    $queries = deduplicarSemantico($queries, 0.70);
    printf("  Após dedup semântico: %d queries únicas\n", count($queries));

    if ($verbose) {
        echo "  Queries (após dedup):\n";
        foreach ($queries as $q) {
            printf("    [%s] %s\n", $q['tipo'], mb_substr($q['query'], 0, 60));
        }
    }

    // Salva no DB pra cada site alvo
    foreach ($sitesAlvo as $site) {
        $salvosNoSite = 0;
        $existentes = 0;
        $i = 0;
        foreach ($queries as $q) {
            $termo = trim($q['query']);
            if ($termo === '' || mb_strlen($termo) < 8) continue;

            // Score artificial alto (8.7 hub, decay 0.1 satélites — mesmo padrão do calendario_salvar do portal)
            $score = max(7.5, 8.7 - ($i * 0.05));

            // Verifica se já existe (idempotência manual — upsert do DB também cuida)
            $jaExiste = false;
            foreach ($db->all(['site' => $site]) as $r) {
                if (mb_strtolower((string)($r['termo'] ?? ''), 'UTF-8') === mb_strtolower($termo, 'UTF-8')) {
                    $jaExiste = true;
                    break;
                }
            }

            if ($jaExiste) {
                $existentes++;
                continue;
            }

            $row = [
                'site'            => $site,
                'termo'           => $termo,
                'categoria'       => $ev['categoria'],
                'categoria_ids'   => [],
                'volume_busca'    => 100000, // placeholder: sazonalidade valida por si
                'volume_label'    => 'sazonal',
                'growth_pct'      => 0,
                'origem'          => 'sazonal:' . $ev['nome'],
                'status'          => 'aprovado',
                'score_discover'  => round($score, 2),
                'score_detalhado' => [
                    'trend' => 9, 'emocao' => 8, 'intencao' => 9, 'alcance' => 9,
                    'final' => round($score, 2), 'status' => 'aprovado',
                    'fonte_score' => 'sazonal_antecipado',
                ],
                'intencao'        => $i === 0 ? 'sazonal_hub' : 'sazonal_satelite',
                'angulo'          => $i === 0 ? 'hub completo' : 'satélite',
                'titulo'          => $termo,
                'relacionados'    => array_slice(array_column($queries, 'query'), 0, 6),
                'evento_fonte'    => $ev['nome'],
                'data_pico'       => $ev['data_pico'],
                'dias_ate_pico'   => $ev['dias_ate'],
                'noticias_qtd'    => 0,
                'sazonal_tipo'    => $q['tipo'], // top|rising|curado
            ];

            if ($dryRun) {
                printf("  [%s] [dry] gravaria #%d %s '%s' (score=%.2f)\n",
                    $site, $i + 1, $q['tipo'], mb_substr($termo, 0, 50), $score);
            } else {
                try {
                    $id = $db->upsert($row);
                    $salvosNoSite++;
                    if ($verbose) {
                        printf("  [%s] ✓ #%d %s '%s' (score=%.2f, id=%d)\n",
                            $site, $i + 1, $q['tipo'], mb_substr($termo, 0, 50), $score, $id);
                    }
                } catch (Throwable $e) {
                    echo "  [{$site}] ERRO upsert '{$termo}': " . $e->getMessage() . "\n";
                    $totalErros++;
                }
            }
            $i++;
        }
        printf("  [%s] salvos=%d, já existentes=%d\n", $site, $salvosNoSite, $existentes);
        $totalSalvos += $salvosNoSite;
        $totalSkipExistentes += $existentes;
    }

    echo "\n";
}

echo str_repeat('═', 80) . "\n";
printf("RESUMO: salvos=%d · já existentes=%d · erros=%d\n",
    $totalSalvos, $totalSkipExistentes, $totalErros);
if ($dryRun) echo "(modo dry-run — nada gravado)\n";
echo "\nPróximo passo:\n";
echo "  → trends entram na fila quando alguém clicar 'Iniciar lote' no portal,\n";
echo "  → ou quando o cron tick_filas.php processar (se essas filas já tiverem sido criadas).\n";
echo "  → opcional: adicionar trends à fila via _criar_fila_teste.php ou via portal UI.\n";
