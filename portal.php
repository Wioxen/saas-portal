<?php
/**
 * Portal → Google Discover Engine
 * Etapa 1: coletar trends do Google Trends (batchexecute) e validar dados.
 * Ainda NÃO grava em banco — só exibe pra confirmar fidelidade do scraping.
 */

// AJAX endpoints NUNCA podem emitir HTML de warning/notice (quebra o JSON no frontend).
if (isset($_GET['ajax'])) {
    @ini_set('display_errors', '0');
    @error_reporting(E_ERROR | E_PARSE);
    ob_start(); // buffer — esvaziado antes de cada json_encode
}

require_once __DIR__ . '/lib/TrendsScraperWeb.php';
require_once __DIR__ . '/lib/DiscoverScore.php';
require_once __DIR__ . '/lib/DiscoverDb.php';
require_once __DIR__ . '/lib/DiscoverAngulo.php';
require_once __DIR__ . '/lib/Serper.php';
require_once __DIR__ . '/lib/Scraper.php';
require_once __DIR__ . '/lib/GoogleNewsRss.php';
require_once __DIR__ . '/lib/TrendsArticles.php';
require_once __DIR__ . '/lib/Claude.php';
require_once __DIR__ . '/lib/Wordpress.php';
require_once __DIR__ . '/lib/Maquina.php';
require_once __DIR__ . '/lib/DiscoverGerador.php';
require_once __DIR__ . '/lib/DiscoverUpdater.php';
require_once __DIR__ . '/lib/DiscoverFila.php';
require_once __DIR__ . '/lib/DiscoverCalendario.php';
require_once __DIR__ . '/lib/DiscoverCluster.php';
require_once __DIR__ . '/lib/DiscoverQualityScore.php';
require_once __DIR__ . '/lib/DiscoverReviewer.php';
require_once __DIR__ . '/lib/OpenAI.php';
require_once __DIR__ . '/lib/DiscoverGeradorGPT.php';
require_once __DIR__ . '/lib/DiscoverProgress.php';
require_once __DIR__ . '/lib/DiscoverPainClassifier.php';
require_once __DIR__ . '/lib/DiscoverRPM.php';
require_once __DIR__ . '/lib/DiscoverClusterMatcher.php';
require_once __DIR__ . '/lib/DiscoverSinaisEditoriais.php';

/**
 * Descarta qualquer output pendente no buffer e emite JSON limpo.
 * Usado antes de ECHO nos endpoints AJAX pra evitar HTML de warning vazar.
 */
function jsonOut(array $data): void {
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

// Resolve LLM ativo (claude ou openai) via cookie/URL — vai pra $cfg['default_llm']
$cfg['default_llm'] = llmAtivo();

$db = new DiscoverDb();

// Ação POST — salvar aprovados no banco simulado
if (($_POST['acao'] ?? '') === 'salvar_aprovados') {
    $payload = json_decode($_POST['trends_json'] ?? '[]', true) ?: [];
    $origem  = $_POST['origem'] ?? '168h';
    // Se 'manual=1', ignora threshold (seleção explícita do usuário)
    $manual  = !empty($_POST['manual']);
    $saved = 0;
    $rows = [];
    foreach ($payload as $t) {
        if (!is_array($t) || empty($t['termo'])) continue;
        $score = DiscoverScore::calcular($t);
        // Score vira INFORMATIVO — não bloqueia mais nada. User decide o que salva.
        // Temas de volume baixo mas relevância alta (concurso nav, leis específicas, etc) passam.
        $t['intencao'] = DiscoverScore::rotuloIntencao($t);
        $briefing = DiscoverAngulo::gerarBriefing($t);
        $sinais = DiscoverSinaisEditoriais::calcular(
            $t + ['score' => $score['final']],
            (string)($briefing['angulo_principal'] ?? '')
        );
        $rows[] = [
            'site'            => $siteSlug,
            'termo'           => (string)$t['termo'],
            'categoria'       => implode(', ', $t['categorias'] ?? []),
            'categoria_ids'   => $t['categoria_ids'] ?? [],
            'volume_busca'    => (int)($t['volume_num'] ?? 0),
            'volume_label'    => (string)($t['volume_label'] ?? ''),
            'growth_pct'      => (int)($t['growth_pct'] ?? 0),
            'origem'          => $origem,
            'status'          => 'aprovado',
            'score_discover'  => $score['final'],
            'score_detalhado' => $score,
            'intencao'        => $t['intencao'],
            'angulo'          => $briefing['angulo_principal'],
            'titulo'          => $briefing['titulo_sugerido'],
            'briefing'        => $briefing,
            'noticias_qtd'    => (int)($t['noticias_qtd'] ?? 0),
            'relacionados'    => array_slice($t['relacionados'] ?? [], 0, 10),
            'pain'            => $sinais['pain'],
            'cluster_detect'  => $sinais['cluster_detect'],
            'arbitragem'      => $sinais['arbitragem'],
        ];
    }
    $db->upsertMany($rows);
    $saved = count($rows);
    // Redireciona direto pra "Ver Salvos" do site certo — fica imediatamente visível
    $qs = http_build_query([
        'modo'  => 'atual',
        'view'  => 'saved',
        'site'  => $siteSlug,
        'saved' => $saved,
    ]);
    header('Location: portal.php?' . $qs);
    exit;
}

// ─── Endpoint AJAX — salvar UM trend individual (botão 💾 por linha) ───
if (($_GET['ajax'] ?? '') === 'salvar_unico') {
    try {
        $t = json_decode($_POST['trend_json'] ?? '[]', true);
        if (!is_array($t) || empty($t['termo'])) throw new RuntimeException('trend inválido');
        $origem = $_POST['origem'] ?? '168h';
        $score = DiscoverScore::calcular($t);
        $t['intencao'] = DiscoverScore::rotuloIntencao($t);
        $briefing = DiscoverAngulo::gerarBriefing($t);
        $sinais = DiscoverSinaisEditoriais::calcular(
            $t + ['score' => $score['final']],
            (string)($briefing['angulo_principal'] ?? '')
        );
        $row = [
            'site'            => $siteSlug,
            'termo'           => (string)$t['termo'],
            'categoria'       => implode(', ', $t['categorias'] ?? []),
            'categoria_ids'   => $t['categoria_ids'] ?? [],
            'volume_busca'    => (int)($t['volume_num'] ?? 0),
            'volume_label'    => (string)($t['volume_label'] ?? ''),
            'growth_pct'      => (int)($t['growth_pct'] ?? 0),
            'origem'          => $origem,
            'status'          => 'aprovado',
            'score_discover'  => $score['final'],
            'score_detalhado' => $score,
            'intencao'        => $t['intencao'],
            'angulo'          => $briefing['angulo_principal'],
            'titulo'          => $briefing['titulo_sugerido'],
            'briefing'        => $briefing,
            'noticias_qtd'    => (int)($t['noticias_qtd'] ?? 0),
            'relacionados'    => array_slice($t['relacionados'] ?? [], 0, 10),
            'pain'            => $sinais['pain'],
            'cluster_detect'  => $sinais['cluster_detect'],
            'arbitragem'      => $sinais['arbitragem'],
        ];
        $id = $db->upsert($row);
        jsonOut(['ok' => true, 'id' => $id, 'termo' => $t['termo'], 'score' => $score['final']]);
    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'erro' => $e->getMessage()]);
    }
}

// Ação GET — visualizar salvos
$visualizarSalvos = ($_GET['view'] ?? '') === 'saved';

// Endpoint AJAX — consultas relacionadas por termo
if (($_GET['ajax'] ?? '') === 'queries') {
    header('Content-Type: application/json; charset=utf-8');
    $termo = trim($_GET['termo'] ?? '');
    $h     = (int)($_GET['hours'] ?? 168);
    if (!in_array($h, [4, 168], true)) $h = 168;
    if ($termo === '') { echo json_encode(['error' => 'termo vazio']); exit; }
    try {
        $sc = new TrendsScraperWeb($cfg['user_agent']);
        $r  = $sc->consultasRelacionadas($termo, $h, 'BR');
        echo json_encode(['ok' => true, 'termo' => $termo, 'data' => $r], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── Progresso em tempo real da geração (usado pelo UI via polling) ───
if (($_GET['ajax'] ?? '') === 'progresso') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonOut(['ok' => false, 'erro' => 'id inválido']);
    $p = DiscoverProgress::ler($id);
    jsonOut(['ok' => true, 'progresso' => $p]);
}

// ─── Gerar post via GPT (teste A/B vs Claude) ───
if (($_GET['ajax'] ?? '') === 'gerar_gpt') {
    set_time_limit(300);
    ini_set('memory_limit', '512M');
    try {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $modelo = trim($_POST['modelo'] ?? 'gpt-4o-mini');
        if ($id <= 0) throw new RuntimeException('id inválido');
        $rec = $db->get($id);
        if (!$rec) throw new RuntimeException('Registro não encontrado');
        $gen = new DiscoverGeradorGPT($cfg, $db, $modelo);
        $t0 = microtime(true);
        $r = $gen->gerar($rec, 'discover');
        $r['tempo_ms'] = (int)((microtime(true) - $t0) * 1000);
        jsonOut($r);
    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'erro' => $e->getMessage()]);
    }
}

// ─── Revisar post (Etapa 2 do prompt master: otimização + alternativas) ───
if (($_GET['ajax'] ?? '') === 'revisar_post') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(200);
    ini_set('memory_limit', '512M');
    try {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('id inválido');
        $rev = new DiscoverReviewer($cfg, $db);
        $t0 = microtime(true);
        $r = $rev->revisar($id);
        $r['tempo_ms'] = (int)((microtime(true) - $t0) * 1000);
        // Re-avalia quality score após revisão
        if (!empty($r['ok']) && !empty($r['post_id'])) {
            try {
                $wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
                $post = $wp->getPost($r['post_id']);
                $qTitulo = (string)($post['title']['rendered'] ?? $r['titulo_depois']);
                $qContent = (string)($post['content']['raw'] ?? $post['content']['rendered'] ?? '');
                if ($qContent !== '') {
                    $q = DiscoverQualityScore::avaliar($qTitulo, $qContent);
                    $db->updateStatus($id, 'publicado', [
                        'quality_score'    => $q['score'],
                        'quality_status'   => $q['status'],
                        'quality_detalhes' => $q['detalhes'],
                        'quality_melhorias'=> $q['melhorias'],
                    ]);
                    $r['quality_score'] = $q['score'];
                    $r['quality_status'] = $q['status'];
                }
            } catch (Throwable $e) { /* não bloqueia */ }
        }
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// ─── Avaliar qualidade de posts existentes (recalcula DiscoverQualityScore) ───
if (($_GET['ajax'] ?? '') === 'avaliar_qualidade') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(60);
    try {
        $ids = json_decode($_POST['ids'] ?? '[]', true) ?: [];
        if (empty($ids) && !empty($_POST['id'])) $ids = [(int)$_POST['id']];
        // Se vazio, avalia TODOS publicados do site
        if (empty($ids)) {
            foreach ($db->all(['site' => $siteSlug]) as $r) {
                if (in_array($r['status'] ?? '', ['publicado','suspeita'], true) && !empty($r['url_post'])) {
                    $ids[] = (int)$r['id'];
                }
            }
        }
        $wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        $avaliados = 0; $erros = []; $resultados = [];
        foreach ($ids as $id) {
            $rec = $db->get((int)$id);
            if (!$rec || empty($rec['url_post'])) continue;
            if (!preg_match('/post=(\d+)/', $rec['url_post'], $m)) continue;
            $postId = (int)$m[1];
            try {
                $post = $wp->getPost($postId);
                $titulo  = (string)($post['title']['rendered'] ?? $rec['titulo'] ?? '');
                $content = (string)($post['content']['raw'] ?? $post['content']['rendered'] ?? '');
                if ($content === '') continue;
                $q = DiscoverQualityScore::avaliar($titulo, $content);
                $db->updateStatus((int)$id, $rec['status'], [
                    'quality_score'    => $q['score'],
                    'quality_status'   => $q['status'],
                    'quality_detalhes' => $q['detalhes'],
                    'quality_melhorias'=> $q['melhorias'],
                ]);
                $resultados[] = ['id' => $id, 'score' => $q['score'], 'status' => $q['status']];
                $avaliados++;
            } catch (Throwable $e) {
                $erros[] = "#{$id}: " . $e->getMessage();
            }
        }
        echo json_encode(['ok' => true, 'avaliados' => $avaliados, 'total' => count($ids), 'resultados' => $resultados, 'erros' => $erros], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// ─── Migrar registros entre sites (recupera quando salvou no site errado) ───
if (($_GET['ajax'] ?? '') === 'migrar_site') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $from   = trim($_POST['from'] ?? '');
        $to     = trim($_POST['to'] ?? '');
        $evento = trim($_POST['evento'] ?? '') ?: null;
        if ($from === '' || $to === '') throw new RuntimeException('Sites de origem e destino obrigatórios');
        if (!isset($sites[$from]) || !isset($sites[$to])) throw new RuntimeException('Site inválido');
        $r = $db->migrarSite($from, $to, $evento);
        echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// ─── Reprocessar: roda DiscoverPostProcess em post já publicado + re-interlink do cluster ───
if (($_GET['ajax'] ?? '') === 'reprocessar') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(600); // posts + cluster interligar podem ser lentos (muitas chamadas WP)
    ini_set('memory_limit', '512M');
    // Shutdown handler — se der fatal (timeout, OOM), ainda devolve JSON válido
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_PARSE], true)) {
            while (ob_get_level() > 0) @ob_end_clean();
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'erro' => 'Fatal: ' . $err['message'] . ' em ' . basename($err['file']) . ':' . $err['line'],
            ], JSON_UNESCAPED_UNICODE);
        }
    });
    try {
        $ids = json_decode($_POST['ids'] ?? '[]', true) ?: [];
        if (empty($ids) && !empty($_POST['id'])) $ids = [(int)$_POST['id']];
        if (empty($ids)) throw new RuntimeException('IDs não informados');

        $wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        $processados = 0; $erros = [];
        $eventosAfetados = [];

        foreach ($ids as $id) {
            $rec = $db->get((int)$id);
            if (!$rec || empty($rec['url_post'])) continue;
            if (!preg_match('/post=(\d+)/', $rec['url_post'], $m)) continue;
            $postId = (int)$m[1];
            try {
                $post = $wp->getPost($postId);
                $content = $post['content']['raw'] ?? $post['content']['rendered'] ?? '';
                if ($content === '') continue;
                $novo = DiscoverPostProcess::processar($content, [
                    'titulo' => $post['title']['rendered'] ?? $rec['titulo'] ?? '',
                    'url'    => $post['link'] ?? '',
                ]);
                if ($novo !== $content) {
                    $wp->atualizarPost($postId, ['content' => $novo]);
                }
                if (!empty($rec['evento_fonte'])) {
                    $eventosAfetados[$rec['evento_fonte']] = true;
                }
                $processados++;
            } catch (Throwable $e) {
                $erros[] = "#{$id}: " . $e->getMessage();
            }
        }

        // Re-interlink dos clusters afetados — só se usuário reformatou vários posts do mesmo cluster.
        // Pra reformat unitário (1 ID), pula pra não gastar tempo desnecessário.
        $interlinks = [];
        if (count($ids) > 1 && !empty($eventosAfetados)) {
            $cluster = new DiscoverCluster($cfg, $db);
            foreach (array_keys($eventosAfetados) as $evento) {
                try {
                    $r = $cluster->interligar($siteSlug, $evento);
                    $interlinks[] = [
                        'evento' => $evento,
                        'atualizados' => $r['atualizados'] ?? 0,
                        'total' => $r['total_posts'] ?? 0,
                    ];
                } catch (Throwable $e) {
                    $interlinks[] = ['evento' => $evento, 'erro' => $e->getMessage()];
                }
            }
        }

        jsonOut([
            'ok' => true,
            'processados' => $processados,
            'total' => count($ids),
            'erros' => $erros,
            'interlinks' => $interlinks,
        ]);
    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'erro' => $e->getMessage()]);
    }
}

// ─── Excluir trend salvo do DB (remove registro, NÃO mexe no WP) ───
if (($_GET['ajax'] ?? '') === 'excluir_trend') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('id inválido');
        $rec = $db->get($id);
        if (!$rec) throw new RuntimeException('Trend não encontrado');
        $ok = $db->delete($id);
        jsonOut(['ok' => $ok, 'id' => $id, 'termo' => $rec['termo'] ?? '']);
    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'erro' => $e->getMessage()]);
    }
}

// ─── Regerar do zero: manda post antigo pro lixo + reseta status no DB ───
if (($_GET['ajax'] ?? '') === 'regerar_reset') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $ids = json_decode($_POST['ids'] ?? '[]', true) ?: [];
        if (empty($ids) && !empty($_POST['id'])) $ids = [(int)$_POST['id']];
        if (empty($ids)) throw new RuntimeException('IDs não informados');

        $wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        $resetados = 0; $trashed = 0;
        foreach ($ids as $id) {
            $rec = $db->get((int)$id);
            if (!$rec) continue;
            // Manda post antigo pro lixo
            if (!empty($rec['url_post']) && preg_match('/post=(\d+)/', $rec['url_post'], $m)) {
                try {
                    $wp->atualizarPost((int)$m[1], ['status' => 'trash']);
                    $trashed++;
                } catch (Throwable $e) { /* pula se já deletado */ }
            }
            // Reset do registro no DB — volta pra "aprovado" pra entrar de novo na fila
            $db->updateStatus((int)$id, 'aprovado', [
                'url_post'            => null,
                'publicado_em'        => null,
                'cluster_interligado' => false,
                'cluster_papel'       => null,
                'auditoria'           => null,
            ]);
            $resetados++;
        }
        echo json_encode(['ok' => true, 'resetados' => $resetados, 'wp_trashed' => $trashed]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// ─── Cluster: interligar posts publicados do mesmo evento ───
if (($_GET['ajax'] ?? '') === 'cluster_interligar') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(120);
    try {
        $evento = trim($_POST['evento'] ?? $_GET['evento'] ?? '');
        if ($evento === '') throw new RuntimeException('Evento não informado');
        $c = new DiscoverCluster($cfg, $db);
        $r = $c->interligar($siteSlug, $evento);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// ─── Calendário: salvar cluster como trends aprovados no DB ───
if (($_GET['ajax'] ?? '') === 'calendario_salvar') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $nome       = trim($_POST['nome'] ?? '');
        $tema       = trim($_POST['tema'] ?? '');
        $categoria  = trim($_POST['categoria'] ?? 'GERAL');
        $dataPico   = trim($_POST['data_pico'] ?? '');
        $cluster    = json_decode($_POST['cluster'] ?? '[]', true) ?: [];
        if ($tema === '' || empty($cluster)) throw new RuntimeException('Dados do cluster ausentes');

        $scoreBase  = 8.7; // score artificial alto — já validado pela sazonalidade
        $diasAte    = (int)(($dataPico ? strtotime($dataPico) - time() : 0) / 86400);
        $rows = [];
        foreach ($cluster as $i => $titulo) {
            $termo = trim((string)$titulo);
            if ($termo === '') continue;
            $rows[] = [
                'site'            => $siteSlug,
                'termo'           => $termo,
                'categoria'       => $categoria,
                'categoria_ids'   => [],
                'volume_busca'    => 100000, // placeholder — sazonalidade valida por si
                'volume_label'    => 'sazonal',
                'growth_pct'      => 0,
                'origem'          => 'calendario',
                'status'          => 'aprovado',
                'score_discover'  => round($scoreBase - ($i * 0.1), 2), // hub > satélites
                'score_detalhado' => ['trend'=>9,'emocao'=>8,'intencao'=>9,'alcance'=>9,'final'=>$scoreBase,'status'=>'aprovado'],
                'intencao'        => 'sazonal',
                'angulo'          => $i === 0 ? 'hub completo' : 'satélite',
                'titulo'          => $termo,
                'relacionados'    => array_slice($cluster, 0, 6),
                'evento_fonte'    => $nome,
                'data_pico'       => $dataPico,
                'dias_ate_pico'   => $diasAte,
                'noticias_qtd'    => 0,
            ];
        }
        $ids = $db->upsertMany($rows);
        echo json_encode(['ok' => true, 'salvos' => count($rows), 'ids' => array_values($ids), 'evento' => $nome], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// ─── Endpoints da FILA de geração em lote ───
if (($_GET['ajax'] ?? '') === 'fila_iniciar') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $ids = json_decode($_POST['ids'] ?? '[]', true) ?: [];
        $formato = $_POST['formato'] ?? 'discover';
        if (empty($ids)) throw new RuntimeException('Nenhum trend selecionado');
        $trends = [];
        $rejeitados = ['site_errado' => 0, 'nao_encontrado' => 0];
        foreach ($ids as $id) {
            $r = $db->get((int)$id);
            if (!$r) { $rejeitados['nao_encontrado']++; continue; }
            if (($r['site'] ?? '') !== $siteSlug) { $rejeitados['site_errado']++; continue; }
            // Sem filtro de score — se está no DB, user quer gerar. Score foi filtrado no save.
            $trends[] = $r;
        }
        if (empty($trends)) {
            throw new RuntimeException(sprintf(
                'Nenhum trend válido. %d não encontrados, %d em outro site (ativo: %s).',
                $rejeitados['nao_encontrado'], $rejeitados['site_errado'], $siteSlug
            ));
        }
        $fila = new DiscoverFila($siteSlug);
        $r = $fila->criar($trends, $formato);
        echo json_encode(['ok' => true, 'batch_id' => $r['batch_id'], 'total' => $r['total']]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'fila_status') {
    header('Content-Type: application/json; charset=utf-8');
    $fila = new DiscoverFila($siteSlug);
    echo json_encode($fila->status(), JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['ajax'] ?? '') === 'fila_cancelar') {
    header('Content-Type: application/json; charset=utf-8');
    (new DiscoverFila($siteSlug))->cancelar();
    echo json_encode(['ok' => true]);
    exit;
}

if (($_GET['ajax'] ?? '') === 'fila_limpar') {
    header('Content-Type: application/json; charset=utf-8');
    (new DiscoverFila($siteSlug))->limpar();
    echo json_encode(['ok' => true]);
    exit;
}

if (($_GET['ajax'] ?? '') === 'fila_tick') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(200);
    ini_set('memory_limit', '512M');
    try {
        $fila = new DiscoverFila($siteSlug);
        $item = $fila->proximoComLock();
        if (!$item) {
            // ─── PASS FINAL — re-interligar TODOS os clusters com 2+ publicados ───
            // Idempotente. Segurança extra pra pegar algum cluster que não foi tocado
            // pelo interlink progressivo (ex: itens que já estavam publicados antes da fila)
            $autoLinks = [];
            try {
                $cluster = new DiscoverCluster($cfg, $db);
                foreach ($cluster->listarClusters($siteSlug) as $cl) {
                    if ($cl['publicados'] >= 2) {
                        try {
                            $r = $cluster->interligar($siteSlug, $cl['nome']);
                            $autoLinks[] = [
                                'evento'      => $cl['nome'],
                                'atualizados' => $r['atualizados'] ?? 0,
                                'total'       => $r['total_posts'] ?? 0,
                            ];
                        } catch (Throwable $e) {
                            $autoLinks[] = ['evento' => $cl['nome'], 'erro' => $e->getMessage()];
                        }
                    }
                }
            } catch (Throwable $e) { /* não bloqueia */ }

            $st = $fila->status();
            echo json_encode(['ok' => true, 'feito' => null, 'terminou' => true, 'status' => $st, 'auto_interlinks' => $autoLinks], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Resolve o registro do DB e processa
        $rec = $db->get((int)$item['trend_id']);
        if (!$rec) {
            $fila->marcarResultado($item['id'], ['ok' => false, 'erro' => 'Registro não encontrado no DB']);
            echo json_encode(['ok' => true, 'feito' => $item, 'terminou' => false, 'resultado' => ['ok' => false, 'erro' => 'Registro não encontrado']]);
            exit;
        }
        $t0 = microtime(true);
        $gen = new DiscoverGerador($cfg, $db);
        $res = $gen->gerar($rec, 'discover');
        $res['tempo_ms'] = (int)((microtime(true) - $t0) * 1000);
        $fila->marcarResultado($item['id'], $res);

        // Interlink agora é parte de DiscoverGerador::gerar() — vem em $res['cluster_interlink']
        echo json_encode([
            'ok' => true,
            'feito' => $item,
            'terminou' => false,
            'resultado' => $res,
            'interlink' => $res['cluster_interlink'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if (isset($item, $fila)) {
            $fila->marcarResultado($item['id'], ['ok' => false, 'erro' => $e->getMessage()]);
        }
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// Endpoint AJAX — atualização inteligente (Etapa 10)
if (($_GET['ajax'] ?? '') === 'atualizar') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(180);
    ini_set('memory_limit', '512M');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok'=>false,'erro'=>'id inválido']); exit; }
    try {
        $rec = $db->get($id);
        if (!$rec) throw new RuntimeException('Registro não encontrado');
        $up = new DiscoverUpdater($cfg, $db);
        $t0 = microtime(true);
        $res = $up->atualizar($rec);
        $res['tempo_ms'] = (int)((microtime(true) - $t0) * 1000);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// Endpoint AJAX — geração + publicação completa
if (($_GET['ajax'] ?? '') === 'gerar') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(180);
    ini_set('memory_limit', '512M');
    $termo    = trim($_GET['termo'] ?? '');
    $formato  = $_GET['formato'] ?? 'discover';
    if (!in_array($formato, ['discover','seo','news','serp'], true)) $formato = 'discover';
    if ($termo === '') { echo json_encode(['ok'=>false,'erro'=>'termo vazio']); exit; }

    try {
        // Tenta localizar o trend salvo para carregar briefing real
        $rec = null;
        foreach ($db->all(['site' => $siteSlug]) as $r) {
            if (mb_strtolower($r['termo']) === mb_strtolower($termo)) { $rec = $r; break; }
        }
        if (!$rec) {
            // Trend não salvo: monta payload mínimo on-the-fly
            $rec = ['termo' => $termo, 'id' => 0, 'briefing' => null];
        }

        $gen = new DiscoverGerador($cfg, $db);
        $t0  = microtime(true);
        $res = $gen->gerar($rec, $formato);
        $res['tempo_ms'] = (int)((microtime(true) - $t0) * 1000);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// Endpoint AJAX — artigos reais para um termo (Google News RSS + Serper)
if (($_GET['ajax'] ?? '') === 'noticias') {
    header('Content-Type: application/json; charset=utf-8');
    $termo = trim($_GET['termo'] ?? '');
    $max   = min(10, max(3, (int)($_GET['max'] ?? 5)));
    if ($termo === '') { echo json_encode(['error' => 'termo vazio']); exit; }
    try {
        $ta = new TrendsArticles(new Serper($cfg['serper_api_key']), null, $cfg['user_agent']);
        $artigos = $ta->listar($termo, $max);
        $resolvidos = 0;
        foreach ($artigos as $a) if (!empty($a['url_real'])) $resolvidos++;
        echo json_encode([
            'ok' => true,
            'termo' => $termo,
            'total' => count($artigos),
            'resolvidos' => $resolvidos,
            'data' => $artigos,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$modo = $_GET['modo'] ?? $_POST['modo'] ?? 'atual';
if (!in_array($modo, ['atual', 'historico', 'calendario'], true)) $modo = 'atual';

$hours  = (int)($_GET['hours'] ?? $_POST['hours'] ?? 168);
if (!in_array($hours, [4, 168], true)) $hours = 168;
$sort   = $_GET['sort'] ?? $_POST['sort'] ?? 'search-volume';
if (!in_array($sort, ['search-volume', 'recency'], true)) $sort = 'search-volume';
$debug  = !empty($_GET['debug']) || !empty($_POST['debug']);

// Histórico — seed + intervalo
$hSeed       = trim($_GET['seed'] ?? $_POST['seed'] ?? '');
$hDataInicio = trim($_GET['data_inicio'] ?? $_POST['data_inicio'] ?? '');
$hDataFim    = trim($_GET['data_fim']    ?? $_POST['data_fim']    ?? '');

$trends = [];
$historico = null;
$erro = null;
$scraper = null;
$buscou = !empty($_GET['go']) || !empty($_POST['go']);
$cacheHit = false;
$cacheTs  = 0;

$cacheFile = sys_get_temp_dir() . '/portal_cache_' . $modo . '.json';

if ($buscou) {
    set_time_limit(60);
    try {
        $scraper = new TrendsScraperWeb($cfg['user_agent']);
        if ($modo === 'historico') {
            if ($hSeed === '' || $hDataInicio === '' || $hDataFim === '') {
                throw new RuntimeException('Preencha termo-semente, data início e data fim.');
            }
            $historico = $scraper->consultasHistoricas($hSeed, $hDataInicio, $hDataFim, 'BR');
            @file_put_contents($cacheFile, json_encode([
                't' => time(),
                'params' => ['seed' => $hSeed, 'inicio' => $hDataInicio, 'fim' => $hDataFim],
                'data' => $historico,
            ], JSON_UNESCAPED_UNICODE));
        } else {
            $trends = $scraper->buscar($hours, $sort, 'BR');
            @file_put_contents($cacheFile, json_encode([
                't' => time(),
                'params' => ['hours' => $hours, 'sort' => $sort],
                'data' => $trends,
            ], JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
} elseif (is_file($cacheFile)) {
    $cached = json_decode((string)@file_get_contents($cacheFile), true);
    if (is_array($cached) && isset($cached['data'])) {
        $cacheHit = true;
        $cacheTs  = (int)($cached['t'] ?? 0);
        if ($modo === 'historico') {
            $historico    = $cached['data'];
            $hSeed        = $cached['params']['seed']   ?? $hSeed;
            $hDataInicio  = $cached['params']['inicio'] ?? $hDataInicio;
            $hDataFim     = $cached['params']['fim']    ?? $hDataFim;
        } else {
            $trends = $cached['data'];
            $hours  = (int)($cached['params']['hours'] ?? $hours);
            $sort   = $cached['params']['sort'] ?? $sort;
        }
    }
}

function tempoAtras(int $ts): string {
    if ($ts <= 0) return '';
    $diff = time() - $ts;
    if ($diff < 60)   return $diff . 's atrás';
    if ($diff < 3600) return (int)($diff/60) . ' min atrás';
    if ($diff < 86400)return (int)($diff/3600) . 'h atrás';
    return (int)($diff/86400) . 'd atrás';
}

// Aplica score + briefing em cada trend e ordena por score
$scoreMin  = (float)($_GET['score_min'] ?? 0);
$catFilter = (int)($_GET['cat'] ?? 0);                    // 1-22 (Google categories), 0 = todas
$sortBy    = (string)($_GET['sort_by'] ?? 'score');       // score | volume | growth | recency
$search    = trim((string)($_GET['q'] ?? ''));
$limit     = max(20, min(3000, (int)($_GET['limit'] ?? 500)));

if (!empty($trends)) {
    foreach ($trends as &$t) {
        $sc = DiscoverScore::calcular($t);
        $t['score']    = $sc['final'];
        $t['score_breakdown'] = $sc;
        $t['threshold'] = $sc['threshold'] ?? 7.0;
        $t['status_auto'] = $sc['status'] ?? 'ignorado';
        $t['intencao'] = DiscoverScore::rotuloIntencao($t);
        $t['briefing'] = DiscoverAngulo::gerarBriefing($t);
        // Sinais editoriais (pain/cluster/arbitragem) — calcula 1x aqui, render só lê.
        DiscoverSinaisEditoriais::enriquecer($t, (string)($t['briefing']['angulo_principal'] ?? ''));
    }
    unset($t);

    // Filtros client-side (todos acumulativos)
    if ($catFilter > 0) {
        $trends = array_values(array_filter($trends, fn($t) => in_array($catFilter, (array)($t['categoria_ids'] ?? []), true)));
    }
    if ($search !== '') {
        $s = mb_strtolower($search, 'UTF-8');
        $trends = array_values(array_filter($trends, function($t) use ($s) {
            if (mb_stripos((string)$t['termo'], $s) !== false) return true;
            foreach ((array)($t['relacionados'] ?? []) as $r) {
                if (mb_stripos((string)$r, $s) !== false) return true;
            }
            return false;
        }));
    }
    if ($scoreMin > 0) {
        $trends = array_values(array_filter($trends, fn($t) => ($t['score'] ?? 0) >= $scoreMin));
    }

    // Sort dinâmico
    $cmp = match ($sortBy) {
        'volume'   => fn($a, $b) => ($b['volume_num'] ?? 0) <=> ($a['volume_num'] ?? 0),
        'growth'   => fn($a, $b) => ($b['growth_pct'] ?? 0) <=> ($a['growth_pct'] ?? 0),
        'recency'  => fn($a, $b) => strcmp((string)($b['iniciado_em'] ?? ''), (string)($a['iniciado_em'] ?? '')),
        'arb'      => fn($a, $b) => (($b['arbitragem']['arbitragem_score'] ?? 0) <=> ($a['arbitragem']['arbitragem_score'] ?? 0)),
        default    => fn($a, $b) => ($b['score'] <=> $a['score']) ?: (($b['volume_num'] ?? 0) <=> ($a['volume_num'] ?? 0)),
    };
    usort($trends, $cmp);

    // Limit pós-sort pra não estourar DOM (padrão 500 de ~2000 scrapeados)
    if (count($trends) > $limit) {
        $trends = array_slice($trends, 0, $limit);
    }
}

// Contador meramente INFORMATIVO — score não bloqueia mais salvamento.
// Mostra quantos bateriam no threshold por cluster (pra user ter noção de qualidade do lote).
$totalAprovados = 0;
foreach ($trends as $t) {
    $thr = DiscoverScore::thresholdPorTrend($t);
    if (($t['score'] ?? 0) >= $thr) $totalAprovados++;
}
$totalVisiveis = count($trends);
$totalSalvos   = $db->count(['site' => $siteSlug]);

// Set de termos já salvos (lowercase) — usado pra marcar rows e desabilitar botão 💾
$termosSalvos = [];
foreach ($db->all(['site' => $siteSlug]) as $rSalvo) {
    $termosSalvos[mb_strtolower((string)($rSalvo['termo'] ?? ''), 'UTF-8')] = true;
}

// Atalhos sazonais (seed sugerida + mês referência)
$seedsSazonais = [
    'Black Friday'     => ['seed' => 'black friday',     'mes' => 11],
    'Natal'            => ['seed' => 'natal',            'mes' => 12],
    'Carnaval'         => ['seed' => 'carnaval',         'mes' => 2],
    'Páscoa'           => ['seed' => 'páscoa',           'mes' => 4],
    'Dia das Mães'     => ['seed' => 'dia das mães',     'mes' => 5],
    'Dia dos Namorados'=> ['seed' => 'dia dos namorados','mes' => 6],
    'Festa Junina'     => ['seed' => 'festa junina',     'mes' => 6],
    'Dia dos Pais'     => ['seed' => 'dia dos pais',     'mes' => 8],
    'Independência'    => ['seed' => 'independência',    'mes' => 9],
    'ENEM'             => ['seed' => 'enem',             'mes' => 10],
    'Volta às Aulas'   => ['seed' => 'volta às aulas',   'mes' => 1],
    'Férias'           => ['seed' => 'férias',           'mes' => 7],
];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Classifica um score relativo ao threshold do cluster.
 * Gera tupla [classe, label] — ex: ['s-excelente', 'Excelente'].
 * Excelente: score ≥ threshold+1.5 · Bom: ≥ threshold · Médio: ≥ threshold-1 · Fraco: <.
 */
function scoreRotulo(float $score, float $threshold): array {
    if ($score >= $threshold + 1.5) return ['s-excelente', 'Excelente'];
    if ($score >= $threshold)       return ['s-bom',       'Bom'];
    if ($score >= $threshold - 1)   return ['s-medio',     'Médio'];
    return ['s-fraco', 'Fraco'];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Portal Discover — Coleta de Trends</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:1180px;margin:0 auto}
h1{color:#fff;margin:0 0 4px}
.sub{color:#666;margin-bottom:20px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:20px;border-radius:10px;margin-bottom:16px}
.box h2{margin:0 0 12px;font-size:17px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.row > *{flex:0 0 auto}
label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#888;margin-bottom:4px}
select,input[type=text]{padding:10px 12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:14px}
button{padding:11px 22px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;border-radius:6px;font-weight:700;cursor:pointer;font-size:14px}
button:hover{opacity:.92}
.alert{background:#3b1818;border-left:4px solid #ef4444;padding:14px;border-radius:6px;color:#fca5a5;margin-bottom:16px;font-size:14px}
.ok{background:#14321a;border-left:4px solid #22c55e;padding:12px 14px;border-radius:6px;color:#86efac;font-size:13px;margin-bottom:16px}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:8px 10px;background:#0f1115;color:#888;font-size:11px;text-transform:uppercase;border-bottom:2px solid #2a2e38;position:sticky;top:0}
td{padding:10px;border-bottom:1px solid #1e2230;vertical-align:top}
tr:hover{background:#1e2230}
.termo{font-weight:700;color:#fff;font-size:14px}
.vol{display:inline-block;background:#1a2e1a;color:#4ade80;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px}
.cat{display:inline-block;background:#1e293b;color:#93c5fd;font-size:10px;padding:1px 6px;border-radius:8px;margin:1px 2px}
.muted{color:#666;font-size:11px}
.news-list{margin:4px 0 0 0;padding:0;list-style:none}
.news-list li{font-size:11px;color:#9ca3af;margin-bottom:2px}
.news-list a{color:#93c5fd;text-decoration:none}
.news-list a:hover{text-decoration:underline}
.pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;margin-right:6px}
.pill.on{background:#4c1d95;color:#fff}
.pill.off{background:#1a1d23;color:#888;border:1px solid #2a2e38}
details{margin-top:16px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;padding:10px 14px}
details summary{cursor:pointer;color:#a78bfa;font-size:13px}
pre{white-space:pre-wrap;word-break:break-all;font-size:11px;color:#9ca3af;max-height:360px;overflow:auto;background:#0b0d11;padding:10px;border-radius:4px}
.btn-q{padding:5px 10px;background:#1e293b;color:#93c5fd;border:1px solid #334155;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer}
.btn-q:hover{background:#334155;color:#fff}
.btn-q.open{background:#4c1d95;color:#fff;border-color:#6d28d9}
.q-cell{background:#0b0d11 !important;padding:14px 20px !important}
.q-slot{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.q-col h4{margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#a78bfa}
.q-col h4.rising{color:#f59e0b}
.q-list{list-style:none;padding:0;margin:0}
.q-list li{padding:5px 0;border-bottom:1px dashed #1e2230;font-size:13px;display:flex;justify-content:space-between;gap:10px}
.q-list li:last-child{border-bottom:none}
.q-list .q-term{color:#e5e7eb}
.q-list .q-val{font-size:11px;font-weight:700;color:#4ade80;background:#14321a;padding:1px 7px;border-radius:10px;white-space:nowrap}
.q-list .q-val.rising{color:#fbbf24;background:#3b2e0f}
.q-err{color:#fca5a5;font-size:13px}
@media(max-width:800px){.q-slot{grid-template-columns:1fr}}
.tabs{display:flex;gap:2px;margin-bottom:0}
.tab{padding:12px 22px;font-weight:700;font-size:14px;border:1px solid #2a2e38;border-bottom:none;border-radius:8px 8px 0 0;text-decoration:none;color:#666;background:transparent}
.tab.active{background:#1a1d23;color:#fff}
.tab:hover{color:#fff}
input[type=date]{padding:10px 12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:14px;color-scheme:dark}
.shortcuts{display:flex;flex-wrap:wrap;gap:6px;margin-top:4px}
.sc{padding:6px 12px;background:#1e293b;color:#93c5fd;border:1px solid #334155;border-radius:16px;font-size:12px;font-weight:600;cursor:pointer}
.sc:hover{background:#334155;color:#fff}
.sc.seasonal{background:#2a1830;color:#f0abfc;border-color:#701a75}
.sc.seasonal:hover{background:#4c1d95;color:#fff}
.hist-result{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.hist-result h3{margin:0 0 10px;font-size:14px;text-transform:uppercase;letter-spacing:.5px;color:#a78bfa;border-bottom:1px solid #2a2e38;padding-bottom:6px}
.hist-result h3.rising{color:#f59e0b}
@media(max-width:800px){.hist-result{grid-template-columns:1fr}}
.cache-tag{display:inline-block;background:#1e293b;color:#93c5fd;font-size:11px;padding:2px 8px;border-radius:10px;margin-left:8px;font-weight:600}
.cache-refresh{margin-left:8px;color:#f59e0b;font-size:12px;text-decoration:none}
.cache-refresh:hover{text-decoration:underline}
.toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:10px 0 14px;padding-bottom:12px;border-bottom:1px solid #1e2230}
.filter-pills{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.pill{padding:5px 12px;border-radius:14px;font-size:12px;font-weight:700;text-decoration:none;border:1px solid #334155;background:#0f1115;color:#888}
.pill.on{background:#4c1d95;color:#fff;border-color:#6d28d9}
.pill.off:hover{color:#fff;border-color:#4c1d95}
.btn-save{padding:9px 18px;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;border:none;border-radius:6px;font-weight:700;cursor:pointer;font-size:13px}
.btn-save:hover{opacity:.92}
.btn-save:disabled{background:#334155;color:#666;cursor:not-allowed}
.score-badge{display:inline-block;padding:4px 10px;border-radius:6px;font-weight:800;font-size:13px;min-width:42px;text-align:center;font-family:'Courier New',monospace}
.s-top{background:#1a2e1a;color:#4ade80}
.s-ok{background:#1e293b;color:#60a5fa}
.s-mid{background:#292524;color:#fbbf24}
.s-low{background:#27272a;color:#6b7280}
/* Score com barra visual + rótulo contextual (respeita threshold do cluster) */
.score-box{display:flex;flex-direction:column;gap:3px;align-items:center;min-width:56px}
.score-bar-wrap{width:100%;height:4px;background:#0f172a;border-radius:2px;overflow:hidden}
.score-bar-fill{height:100%;border-radius:2px;transition:width .3s}
.score-bar-fill.s-excelente{background:linear-gradient(90deg,#16a34a,#22c55e)}
.score-bar-fill.s-bom      {background:linear-gradient(90deg,#2563eb,#3b82f6)}
.score-bar-fill.s-medio    {background:linear-gradient(90deg,#ca8a04,#eab308)}
.score-bar-fill.s-fraco    {background:linear-gradient(90deg,#7f1d1d,#991b1b)}
.score-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.score-label.s-excelente{color:#4ade80}
.score-label.s-bom      {color:#60a5fa}
.score-label.s-medio    {color:#fbbf24}
.score-label.s-fraco    {color:#f87171}
.intent-tag{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.intent-ao-vivo{background:#450a0a;color:#fca5a5}
.intent-resultado{background:#431407;color:#fdba74}
.intent-tutorial{background:#1e1b4b;color:#a5b4fc}
.intent-transacional{background:#134e4a;color:#5eead4}
.intent-comparativo{background:#422006;color:#fbbf24}
.intent-informacional{background:#1e293b;color:#93c5fd}
.intent-geral{background:#1a1d23;color:#9ca3af}
.intent-servico-publico{background:#064e3b;color:#86efac}
.intent-publicado{background:#064e3b;color:#86efac}
.intent-suspeita{background:#7f1d1d;color:#fecaca;animation:pulse 2s infinite}
.intent-aprovado{background:#1e3a8a;color:#93c5fd}
.intent-novo{background:#1a1d23;color:#9ca3af}
.intent-ignorado{background:#292524;color:#78716c}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.7}}
tr.row-aprovado{background:rgba(251,191,36,.08)}
tr.row-aprovado:hover{background:rgba(251,191,36,.14)}
tr[data-status="publicado"]{background:rgba(34,197,94,.03)}
tr[data-status="suspeita"]{background:rgba(239,68,68,.06)}
tr[data-status="suspeita"]:hover{background:rgba(239,68,68,.12)}
.batch-check{width:16px;height:16px;cursor:pointer;accent-color:#22c55e}
/* Tabela Ver Salvos — mais densa e legível */
.tr-salvo td{padding:8px 10px;font-size:12.5px;vertical-align:top}
.tr-salvo .termo{font-size:13px;font-weight:700}
.tr-salvo td button, .tr-salvo td a.btn-n{margin:1px 2px;font-size:10.5px;padding:3px 7px;white-space:nowrap}
#busca-salvos:focus{outline:none;border-color:#0ea5e9}
/* Pills de filtro de status */
.filter-pills .pill{padding:5px 12px;font-size:12px;transition:all .15s}
.filter-pills .pill:hover{transform:translateY(-1px)}
.cal-card{background:#0f1115;border-radius:6px;padding:12px 14px;margin-bottom:10px}
.cal-card details summary::marker{color:#a78bfa}
.btn-save-cluster.saved{background:#22c55e;color:#052e16}
.btn-save-cluster.saved::before{content:'✓ '}
.btn-save-row{padding:5px 10px;background:#14532d;color:#86efac;border:1px solid #16a34a;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;margin-left:4px}
.btn-x{padding:5px 10px;background:#450a0a;color:#fca5a5;border:1px solid #991b1b;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;margin-left:4px}
.pain-chip{display:inline-block;margin-top:4px;padding:2px 7px;border-radius:4px;font-size:10.5px;font-weight:700;line-height:1.5;cursor:help}
.pain-urgencia    {background:#7c2d12;color:#fed7aa;border:1px solid #c2410c}
.pain-medo        {background:#4c1d95;color:#ddd6fe;border:1px solid #7c3aed}
.pain-dinheiro    {background:#14532d;color:#86efac;border:1px solid #16a34a}
.pain-oportunidade{background:#164e63;color:#a5f3fc;border:1px solid #0891b2}
.arb-chip{display:inline-block;margin-top:4px;padding:2px 7px;border-radius:4px;font-size:10.5px;font-weight:700;line-height:1.5;cursor:help;font-family:'Courier New',monospace}
.arb-alto  {background:#1e3a8a;color:#bfdbfe;border:1px solid #3b82f6}
.arb-medio {background:#1e293b;color:#cbd5e1;border:1px solid #475569}
.arb-baixo {background:#1f1f1f;color:#6b7280;border:1px solid #2f2f2f}
/* Cluster chip + ROI bar — mostra nicho editorial e viabilidade econômica */
.cluster-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:5px;font-size:11px;font-weight:700;line-height:1.4;cursor:help;white-space:nowrap}
.cluster-chip .c-emoji{font-size:13px}
.cluster-chip .c-name{font-weight:700}
.cluster-chip.roi-alto  {background:#14532d;color:#86efac;border:1px solid #16a34a}
.cluster-chip.roi-medio {background:#1e293b;color:#cbd5e1;border:1px solid #475569}
.cluster-chip.roi-baixo {background:#1c1917;color:#78716c;border:1px solid #292524}
.roi-bar{display:block;height:3px;background:#0f172a;border-radius:2px;overflow:hidden;margin-top:3px;width:100%}
.roi-bar-fill{height:100%;transition:width .3s;border-radius:2px}
.roi-bar-fill.alto {background:linear-gradient(90deg,#16a34a,#22c55e)}
.roi-bar-fill.medio{background:linear-gradient(90deg,#64748b,#94a3b8)}
.roi-bar-fill.baixo{background:#44403c}
.cluster-col{min-width:110px}
.cluster-col .roi-value{font-size:9px;color:#94a3b8;margin-top:2px;font-family:'Courier New',monospace}
.cluster-col .roi-value strong{color:#e2e8f0}
.btn-x:hover{background:#991b1b;color:#fff}
.btn-x.running{background:#b91c1c;color:#fef2f2;cursor:wait}
.btn-save-row:hover{background:#16a34a;color:#fff}
.btn-save-row.saved{background:#064e3b;color:#6ee7b7;border-color:#065f46;cursor:default}
.btn-save-row:disabled{cursor:not-allowed;opacity:.85}
.btn-save-row.running{background:#ca8a04;color:#fef3c7;border-color:#f59e0b;cursor:wait}
.grupo-feriado{background:#7f1d1d;color:#fca5a5}
.grupo-data{background:#4c1d95;color:#d8b4fe}
.grupo-compras{background:#064e3b;color:#6ee7b7}
.grupo-finanças{background:#134e4a;color:#5eead4}
.grupo-servico{background:#1e3a8a;color:#93c5fd}
.grupo-educação{background:#78350f;color:#fcd34d}
.grupo-entretenimento{background:#581c87;color:#d8b4fe}
.grupo{display:inline-block;padding:3px 9px;border-radius:4px;font-size:10px;font-weight:800;letter-spacing:.5px;text-transform:uppercase}
.grupo-produto{background:#064e3b;color:#6ee7b7}
.grupo-notícia{background:#1e3a8a;color:#93c5fd}
.grupo-esportes{background:#7f1d1d;color:#fca5a5}
.grupo-entretenimento{background:#581c87;color:#d8b4fe}
.grupo-educação{background:#78350f;color:#fcd34d}
.grupo-finanças{background:#134e4a;color:#5eead4}
.grupo-tecnologia{background:#312e81;color:#a5b4fc}
.grupo-geral{background:#27272a;color:#d4d4d8}
.btn-b{padding:5px 10px;background:#1e1b4b;color:#c4b5fd;border:1px solid #4c1d95;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;margin-left:4px}
.btn-b:hover{background:#4c1d95;color:#fff}
.btn-b.open{background:#a78bfa;color:#1e1b4b;border-color:#a78bfa}
.btn-n{padding:5px 10px;background:#0c4a6e;color:#7dd3fc;border:1px solid #0369a1;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;margin-left:4px}
.btn-n:hover{background:#0369a1;color:#fff}
.btn-n.open{background:#0ea5e9;color:#0c4a6e;border-color:#0ea5e9}
.btn-g{padding:5px 10px;background:#14532d;color:#86efac;border:1px solid #16a34a;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;margin-left:4px}
.btn-g:hover{background:#16a34a;color:#fff}
.btn-g.running{background:#ca8a04;color:#fef3c7;border-color:#f59e0b;cursor:wait}
.btn-g.done{background:#22c55e;color:#052e16;border-color:#22c55e}
.btn-g.failed{background:#7f1d1d;color:#fecaca;border-color:#b91c1c}
.btn-u{padding:5px 10px;background:#422006;color:#fbbf24;border:1px solid #ca8a04;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer}
.btn-u:hover{background:#ca8a04;color:#1a1a1a}
.btn-u.running{background:#ca8a04;color:#fef3c7;cursor:wait}
.btn-u.done{background:#22c55e;color:#052e16;border-color:#22c55e}
.btn-u.failed{background:#7f1d1d;color:#fecaca;border-color:#b91c1c}
.gen-progress{padding:14px;background:#0b0d11;border-radius:6px;font-size:13px;line-height:1.6}
.gen-step{display:block;padding:3px 0;color:#9ca3af}
.gen-step.active{color:#fbbf24}
.gen-step.done{color:#4ade80}
.gen-step.err{color:#fca5a5}
.gen-result{padding:14px;background:#052e16;border-left:4px solid #22c55e;border-radius:6px;font-size:13px}
.gen-result.fail{background:#2e0505;border-left-color:#ef4444}
.gen-result a{color:#93c5fd;font-weight:700}
.news-card{padding:10px 0;border-bottom:1px dashed #2a2e38}
.news-card:last-child{border-bottom:none}
.news-card a{color:#93c5fd;text-decoration:none;font-weight:600;font-size:13px}
.news-card a:hover{text-decoration:underline}
.news-meta{font-size:11px;color:#888;margin-top:4px}
.news-source{display:inline-block;background:#1e293b;color:#fbbf24;padding:1px 7px;border-radius:8px;font-size:10px;font-weight:700;margin-right:6px}
.news-desc{font-size:12px;color:#cbd5e1;margin-top:4px;line-height:1.4}
.news-warn{color:#fbbf24;font-size:11px;margin-left:6px}
.briefing{padding:4px 6px}
.brf-head{margin-bottom:14px;padding-bottom:10px;border-bottom:1px dashed #2a2e38}
.brf-pill{display:inline-block;padding:3px 10px;margin:2px 4px 2px 0;border-radius:12px;font-size:10px;font-weight:700;letter-spacing:.4px}
.brf-pill-g{background:#1e3a8a;color:#93c5fd}
.brf-pill-a{background:#4c1d95;color:#e9d5ff}
.brf-pill-u{background:#134e4a;color:#5eead4}
.brf-pill-i{background:#451a03;color:#fdba74}
.brf-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:12px}
@media(max-width:800px){.brf-grid{grid-template-columns:1fr}}

/* ──────────────── MOBILE ≤768px — tabelas viram cards ──────────────── */
@media (max-width: 768px) {
  .container{padding:8px}
  .toolbar{flex-direction:column;align-items:stretch;gap:8px}
  .toolbar > *{width:100%}
  .adv-filters{padding:8px 10px}
  .adv-filters > div{width:100%;min-width:0}
  .adv-filters #filtro-contador{margin-left:0;text-align:center}
  .filter-pills{flex-wrap:wrap;justify-content:flex-start}
  .filter-pills .pill{flex:1 1 auto;text-align:center}

  /* Tabela Salvos — vira lista de cards */
  .tr-salvo, table:has(.tr-salvo){display:block}
  table:has(.tr-salvo) thead{display:none}
  table:has(.tr-salvo) tbody{display:block}
  .tr-salvo{display:block;background:#13161d;border:1px solid #2a2e38;border-radius:10px;padding:12px 14px;margin-bottom:10px}
  .tr-salvo td{display:block;padding:5px 0;border:none;font-size:13px;text-align:left}
  .tr-salvo td::before{content:attr(data-lbl);display:block;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px}
  .tr-salvo td:nth-of-type(1)::before{content:''} /* checkbox — sem label */
  .tr-salvo td:nth-of-type(2)::before{content:'ID'}
  .tr-salvo td:nth-of-type(3)::before{content:'Score'}
  .tr-salvo td:nth-of-type(4)::before{content:'Qualidade'}
  .tr-salvo td:nth-of-type(5)::before{content:''} /* Termo — sem label, já é o destaque */
  .tr-salvo td:nth-of-type(6)::before{content:'Cluster / ROI'}
  .tr-salvo td:nth-of-type(7)::before{content:'Intenção'}
  .tr-salvo td:nth-of-type(8)::before{content:'Volume'}
  .tr-salvo td:nth-of-type(9)::before{content:'Atualização'}
  .tr-salvo td:nth-of-type(10)::before{content:'Status'}
  .tr-salvo td:nth-of-type(11)::before{content:'Ações'}
  .tr-salvo td:nth-of-type(5) .termo{font-size:15px;color:#fff}
  .tr-salvo td:nth-of-type(11){display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;padding-top:8px;border-top:1px dashed #2a2e38}
  .tr-salvo td:nth-of-type(11) button, .tr-salvo td:nth-of-type(11) a.btn-n{flex:1 1 calc(50% - 3px);text-align:center;font-size:12px;padding:8px 6px}
  .tr-salvo .u-row, tr.u-row{display:none !important}
  .score-box{flex-direction:row;gap:6px;align-items:center}
  .score-box .score-bar-wrap{flex:1}

  /* Tabela Trends atuais — scroll horizontal forçado (muitas colunas) */
  .box > table:not(:has(.tr-salvo)){display:block;overflow-x:auto;white-space:nowrap}
  .box > table:not(:has(.tr-salvo)) thead th{font-size:10px;padding:6px 8px}
  .box > table:not(:has(.tr-salvo)) tbody td{font-size:11px;padding:6px 8px}

  /* Header do portal */
  h1{font-size:20px !important}
  h2{font-size:15px !important}
  .box{padding:12px}
  .cal-card{padding:10px}
}
.brf-label{font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:#a78bfa;font-weight:700;margin-bottom:4px}
.brf-val{font-size:13px;color:#e5e7eb;line-height:1.45}
.brf-list{list-style:none;padding:0;margin:0}
.brf-list li{font-size:12px;color:#cbd5e1;padding:3px 0;border-bottom:1px dashed #1e2230}
.brf-list li:before{content:'→ ';color:#6d28d9}
.brf-kws{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
.brf-kw{background:#1e293b;color:#93c5fd;font-size:11px;padding:3px 8px;border-radius:10px}
</style>
</head>
<body>
<div class="container">
  <h1>🧠 Portal Discover — Etapa 1: Coleta de Trends</h1>
  <p class="sub">
    Scraping direto do Google Trends. Sem API paga. Banco ainda não criado — valide os dados aqui antes.
    <br>
    <span style="display:inline-block;margin-top:6px;padding:4px 10px;background:#0c4a6e;color:#7dd3fc;border-radius:12px;font-size:11px;font-weight:700">
      🌐 Site ativo: <?= h($cfg['_site_name'] ?? $siteSlug) ?>
      <span style="opacity:.7">(<?= h(preg_replace('#^https?://#', '', $cfg['wp_url'] ?? '')) ?>)</span>
    </span>
    <?php
    // Preserva params atuais (exceto 'llm') pra construir links da toggle
    $paramsSemLlm = $_GET; unset($paramsSemLlm['llm']);
    $baseQs = http_build_query($paramsSemLlm);
    $llmAtivo = $cfg['default_llm'];
    ?>
    <span style="display:inline-flex;gap:4px;margin-left:8px;padding:2px 6px;background:#1a1d23;border:1px solid #2a2e38;border-radius:12px;font-size:11px;font-weight:700;vertical-align:middle">
      <span style="padding:2px 4px;color:#888">🤖 LLM:</span>
      <a href="?<?= $baseQs ?>&llm=claude" style="padding:3px 10px;border-radius:10px;text-decoration:none;<?= $llmAtivo==='claude' ? 'background:#4c1d95;color:#fff' : 'color:#888' ?>">Claude</a>
      <a href="?<?= $baseQs ?>&llm=openai" style="padding:3px 10px;border-radius:10px;text-decoration:none;<?= $llmAtivo==='openai' ? 'background:#064e3b;color:#6ee7b7' : 'color:#888' ?>">GPT</a>
    </span>
  </p>

  <div class="tabs">
    <a href="?modo=atual" class="tab <?= $modo === 'atual' ? 'active' : '' ?>">🔥 Trends atuais</a>
    <a href="?modo=historico" class="tab <?= $modo === 'historico' ? 'active' : '' ?>">📅 Histórico</a>
    <a href="?modo=calendario" class="tab <?= $modo === 'calendario' ? 'active' : '' ?>">📆 Calendário sazonal</a>
  </div>

  <?php if ($modo === 'calendario'):
    $diasLim = (int)($_GET['dias'] ?? 60);
    if (!in_array($diasLim, [30, 60, 90, 180], true)) $diasLim = 60;
    $eventos = DiscoverCalendario::proximos($diasLim);
    $porStatus = ['hoje'=>[], 'acionavel'=>[], 'aproximando'=>[], 'futuro'=>[]];
    foreach ($eventos as $e) $porStatus[$e['status']][] = $e;
    $titulos = [
      'hoje'        => ['🔥 Pico iminente (≤3 dias)', '#dc2626', 'Publique HOJE se ainda não publicou'],
      'acionavel'   => ['🚀 ATAQUE AGORA — dentro da janela de antecipação', '#f59e0b', 'Janela ótima de publicação'],
      'aproximando' => ['🟡 Aproximando — planeje esta semana', '#fbbf24', 'Entra na janela em ≤10 dias'],
      'futuro'      => ['🟢 Futuro — no radar', '#4ade80', 'Monitore, ainda longe'],
    ];
  ?>
    <?php include __DIR__ . '/_site_select.php'; ?>
    <div class="box">
      <h2 style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <span>📆 Calendário sazonal preditivo — <?= count($eventos) ?> eventos em <?= $diasLim ?> dias</span>
        <span style="font-size:13px;font-weight:normal">
          <?php foreach ([30, 60, 90, 180] as $d): ?>
            <a href="?modo=calendario&dias=<?= $d ?>" class="pill <?= $diasLim == $d ? 'on' : 'off' ?>"><?= $d ?>d</a>
          <?php endforeach; ?>
        </span>
      </h2>
      <p class="muted" style="margin:-6px 0 10px;font-size:13px">
        Eventos BR recorrentes com pico previsível. Para cada um: tema-semente pra validação histórica e cluster pronto pra virar artigos antes do pico.
        Timing ideal: publicar 15-20 dias antes · atualizar 5 dias antes · reforçar no dia.
      </p>
    </div>

    <?php foreach ($titulos as $st => [$label, $cor, $sub]): if (empty($porStatus[$st])) continue; ?>
      <div class="box" style="border-left:4px solid <?= $cor ?>">
        <h2 style="color:<?= $cor ?>;margin-bottom:4px"><?= $label ?> · <?= count($porStatus[$st]) ?></h2>
        <div class="muted" style="font-size:12px;margin-bottom:12px"><?= $sub ?></div>
        <?php foreach ($porStatus[$st] as $e): ?>
          <div class="cal-card" style="border-left:3px solid <?= $cor ?>">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
              <div style="flex:1;min-width:260px">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:4px">
                  <strong style="color:#fff;font-size:15px"><?= h($e['nome']) ?></strong>
                  <span class="grupo grupo-<?= h(strtolower($e['categoria'])) ?>"><?= h($e['categoria']) ?></span>
                </div>
                <div style="font-size:12px;color:#9ca3af">
                  📌 Pico em <strong style="color:#fff"><?= h($e['data_pico']) ?></strong>
                  · <strong style="color:<?= $cor ?>"><?= $e['dias_ate'] ?>d</strong>
                  · janela ideal: publicar <?= $e['antecipacao'] ?>d antes
                </div>
                <div style="margin-top:8px;padding:8px 10px;background:#0f1115;border-radius:4px;font-size:12px">
                  <span class="muted">Tema-semente:</span>
                  <code style="color:#93c5fd;font-weight:700"><?= h($e['tema']) ?></code>
                </div>
              </div>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <a href="?modo=historico&seed=<?= urlencode($e['tema']) ?>&data_inicio=<?= h($e['data_historica_inicio']) ?>&data_fim=<?= h($e['data_historica_fim']) ?>&go=1"
                   class="btn-n" style="text-decoration:none;display:inline-block">🔍 Validar histórico</a>
                <button type="button" class="btn-g btn-save-cluster"
                  data-nome="<?= h($e['nome']) ?>"
                  data-tema="<?= h($e['tema']) ?>"
                  data-categoria="<?= h($e['categoria']) ?>"
                  data-datapico="<?= h($e['data_pico']) ?>"
                  data-cluster='<?= h(json_encode($e['cluster'], JSON_UNESCAPED_UNICODE)) ?>'
                  >💾 Salvar cluster (<?= count($e['cluster']) ?>)</button>
              </div>
            </div>
            <details style="margin-top:10px">
              <summary style="cursor:pointer;color:#a78bfa;font-size:12px">▸ Cluster sugerido (<?= count($e['cluster']) ?> artigos)</summary>
              <ol style="margin:8px 0 0;padding-left:22px;font-size:13px;color:#cbd5e1">
                <?php foreach ($e['cluster'] as $j => $t): ?>
                  <li style="padding:4px 0"><?= h($t) ?><?php if ($j === 0): ?> <span style="color:#fbbf24;font-size:11px;margin-left:4px">HUB</span><?php endif; ?></li>
                <?php endforeach; ?>
              </ol>
            </details>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <?php if (empty($eventos)): ?>
      <div class="box" style="text-align:center;color:#666">Nenhum evento nos próximos <?= $diasLim ?> dias.</div>
    <?php endif; ?>

  <?php elseif ($modo === 'atual'): ?>
    <form method="GET">
      <input type="hidden" name="go" value="1">
      <input type="hidden" name="modo" value="atual">
      <input type="hidden" name="site" value="<?= h($siteSlug) ?>">
      <?php include __DIR__ . '/_site_select.php'; ?>

      <div class="box">
        <h2>⚙️ Parâmetros de busca</h2>
        <div class="row">
          <div>
            <label>Janela de tempo</label>
            <select name="hours">
              <option value="168" <?= $hours === 168 ? 'selected' : '' ?>>Últimos 7 dias (168h)</option>
              <option value="4"   <?= $hours === 4   ? 'selected' : '' ?>>Últimas 4 horas</option>
            </select>
          </div>
          <div>
            <label>Ordenação</label>
            <select name="sort">
              <option value="search-volume" <?= $sort === 'search-volume' ? 'selected' : '' ?>>Volume de busca</option>
              <option value="recency"       <?= $sort === 'recency'       ? 'selected' : '' ?>>Mais recente</option>
            </select>
          </div>
          <div>
            <label style="visibility:hidden">x</label>
            <label style="display:flex;align-items:center;gap:6px;text-transform:none;color:#ddd;font-size:13px;letter-spacing:0">
              <input type="checkbox" name="debug" value="1" <?= $debug ? 'checked' : '' ?>> Mostrar debug da resposta
            </label>
          </div>
          <div>
            <label style="visibility:hidden">x</label>
            <button type="submit">🔎 Buscar trends</button>
          </div>
        </div>
        <p class="muted" style="margin-top:10px">
          Endpoint-alvo: <code>https://trends.google.com.br/trending?geo=BR&amp;hours=<?= $hours ?>&amp;sort=<?= h($sort) ?></code>
        </p>
      </div>
    </form>
  <?php else: ?>
    <form method="GET">
      <input type="hidden" name="go" value="1">
      <input type="hidden" name="modo" value="historico">
      <input type="hidden" name="site" value="<?= h($siteSlug) ?>">
      <?php include __DIR__ . '/_site_select.php'; ?>

      <div class="box">
        <h2>📅 Explorar período histórico</h2>
        <p class="muted" style="margin:-4px 0 12px">
          Antecipe conteúdo sazonal: descubra o que as pessoas buscaram em torno de um termo em datas passadas para publicar <strong>antes</strong> do próximo ciclo.
        </p>
        <div class="row" style="align-items:flex-end">
          <div style="flex:1;min-width:240px">
            <label>Termo-semente</label>
            <input type="text" name="seed" id="h-seed" value="<?= h($hSeed) ?>" placeholder="ex: black friday, enem, natal, dia das mães..." style="width:100%">
          </div>
          <div>
            <label>Data início</label>
            <input type="date" name="data_inicio" id="h-inicio" value="<?= h($hDataInicio) ?>">
          </div>
          <div>
            <label>Data fim</label>
            <input type="date" name="data_fim" id="h-fim" value="<?= h($hDataFim) ?>">
          </div>
          <div>
            <button type="submit">🔎 Explorar</button>
          </div>
        </div>

        <div style="margin-top:16px">
          <label>Atalhos de data (relativo a hoje)</label>
          <div class="shortcuts">
            <button type="button" class="sc" data-shift="week">Mesma semana ano passado</button>
            <button type="button" class="sc" data-shift="month">Mesmo mês ano passado</button>
            <button type="button" class="sc" data-shift="quarter">Último trimestre do ano passado</button>
          </div>
        </div>

        <div style="margin-top:16px">
          <label>Eventos sazonais (preenche seed + mês do ano passado)</label>
          <div class="shortcuts">
            <?php foreach ($seedsSazonais as $nome => $cfgS): ?>
              <button type="button" class="sc seasonal" data-seed="<?= h($cfgS['seed']) ?>" data-mes="<?= $cfgS['mes'] ?>"><?= h($nome) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </form>
  <?php endif; ?>

  <?php if ($erro): ?>
    <div class="alert"><strong>Erro:</strong> <?= h($erro) ?></div>
  <?php endif; ?>

  <?php if (!$erro && $modo === 'atual' && !empty($trends)): ?>
    <div class="ok">
      ✅ <?= count($trends) ?> trends — janela <?= $hours ?>h · ordenação <?= h($sort) ?>
      <?php if ($cacheHit): ?>
        <span class="cache-tag">📦 cache de <?= tempoAtras($cacheTs) ?></span>
        <a href="?modo=atual&hours=<?= $hours ?>&sort=<?= h($sort) ?>&go=1" class="cache-refresh">🔄 atualizar</a>
      <?php elseif ($scraper): ?>
        · HTTP <?= $scraper->lastHttpCode ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($historico !== null): ?>
    <div class="ok">
      ✅ <strong><?= h($hSeed) ?></strong> · <?= h($historico['time_range'] ?? ($hDataInicio . ' ' . $hDataFim)) ?> · TOP: <?= count($historico['top']) ?> · RISING: <?= count($historico['rising']) ?>
      <?php if ($cacheHit): ?>
        <span class="cache-tag">📦 cache de <?= tempoAtras($cacheTs) ?></span>
        <a href="?modo=historico&seed=<?= urlencode($hSeed) ?>&data_inicio=<?= h($hDataInicio) ?>&data_fim=<?= h($hDataFim) ?>&go=1" class="cache-refresh">🔄 atualizar</a>
      <?php endif; ?>
    </div>
    <div class="box">
      <h2>📅 Resultados históricos — "<?= h($hSeed) ?>"</h2>
      <p class="muted" style="margin:-6px 0 14px">
        Use estas consultas como briefing: títulos, subtítulos H3, FAQ e palavras-chave para o conteúdo sazonal antecipado.
      </p>
      <div class="hist-result">
        <div>
          <h3>📊 Consultas mais frequentes (<?= count($historico['top']) ?>)</h3>
          <ul class="q-list">
            <?php foreach ($historico['top'] as $q): ?>
              <li>
                <span class="q-term"><?= h($q['query']) ?></span>
                <span class="q-val"><?= h($q['formatted'] ?: $q['value']) ?></span>
              </li>
            <?php endforeach; ?>
            <?php if (empty($historico['top'])): ?><li style="color:#555">Sem dados para este período.</li><?php endif; ?>
          </ul>
        </div>
        <div>
          <h3 class="rising">🔥 Consultas em alta (<?= count($historico['rising']) ?>)</h3>
          <ul class="q-list">
            <?php foreach ($historico['rising'] as $q): ?>
              <li>
                <span class="q-term"><?= h($q['query']) ?></span>
                <span class="q-val rising"><?= h($q['formatted'] ?: $q['value']) ?></span>
              </li>
            <?php endforeach; ?>
            <?php if (empty($historico['rising'])): ?><li style="color:#555">Sem dados para este período.</li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="ok" style="font-size:14px;padding:12px 16px">
      💾 <strong style="font-size:16px"><?= (int)$_GET['saved'] ?> trends salvos</strong>
      no site <strong><?= h($cfg['_site_name'] ?? $siteSlug) ?></strong>
      — veja na lista abaixo e marque os que quer gerar.
      <?php if ((int)$_GET['saved'] === 0): ?>
        <div style="color:#fca5a5;margin-top:6px;font-size:13px">
          ⚠️ Nenhum item foi salvo. Verifique:
          <ul style="margin:4px 0 0;padding-left:20px">
            <li>Se usou "Salvar aprovados" mas nenhum tinha score ≥ 7 — use o botão <strong>⚠️ Salvar X manual</strong> em vez disso.</li>
            <li>Se o site ativo é o certo (toggle no topo da página).</li>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($visualizarSalvos):
    $salvosAll = $db->all(['site' => $siteSlug]);
    usort($salvosAll, fn($a,$b) => ($b['score_discover'] ?? 0) <=> ($a['score_discover'] ?? 0));
    $updater = new DiscoverUpdater($cfg, $db);
    $elegiveisMap = [];
    foreach ($updater->elegiveis($siteSlug, 48, 100) as $e) $elegiveisMap[$e['id']] = $e['_idade_horas'];

    // Contadores por status
    $contador = ['todos' => 0, 'aprovado' => 0, 'publicado' => 0, 'suspeita' => 0];
    foreach ($salvosAll as $r) {
        $contador['todos']++;
        $st = $r['status'] ?? '';
        if (isset($contador[$st])) $contador[$st]++;
    }

    // Filtro por status via URL
    $filtroStatus = $_GET['filtro'] ?? 'todos';
    if (!in_array($filtroStatus, ['todos', 'aprovado', 'publicado', 'suspeita'], true)) $filtroStatus = 'todos';
    $salvos = $filtroStatus === 'todos'
        ? $salvosAll
        : array_values(array_filter($salvosAll, fn($r) => ($r['status'] ?? '') === $filtroStatus));

    $idsAprovadosTotal = array_values(array_map(fn($r) => (int)$r['id'],
        array_filter($salvosAll, fn($r) => ($r['status'] ?? '') === 'aprovado')));

    // Dashboard de cliques de afiliado (últimos 7 dias)
    require_once __DIR__ . '/lib/DiscoverAfiliados.php';
    $cliques7d = DiscoverAfiliados::cliquesPorOferta(7);
    $totalCliques7d = array_sum($cliques7d);
    $ofertasMap = [];
    foreach (DiscoverAfiliados::listar() as $o) $ofertasMap[$o['slug']] = $o;

    // Search Console — lê cache local sincronizado por scripts/sync_search_console.php
    $gscCache = __DIR__ . '/data/search_console_cache/' . $siteSlug . '.json';
    $gscData = null;
    if (is_file($gscCache)) {
        $gscData = json_decode((string)@file_get_contents($gscCache), true) ?: null;
    }

    // Dashboard de Web Stories (últimos 7 dias) — extraído dos registros persistidos
    $corte7d = strtotime('-7 days');
    $wsOk = 0; $wsErro = 0; $wsPulado = 0; $wsPorCluster = [];
    // Push OneSignal 7d
    $osOk = 0; $osErro = 0; $osPulado = 0; $osRecipientsTotal = 0;
    foreach ($salvosAll as $rr) {
        $pubTs = strtotime((string)($rr['publicado_em'] ?? ''));
        if ($pubTs === false || $pubTs < $corte7d) continue;

        $wsInfo = $rr['web_story_info'] ?? null;
        if (is_array($wsInfo)) {
            if (!empty($wsInfo['ok'])) {
                $wsOk++;
                $ck = $rr['cluster_detect']['key'] ?? 'curiosidades_geral';
                $wsPorCluster[$ck] = ($wsPorCluster[$ck] ?? 0) + 1;
            } elseif (!empty($wsInfo['pulado'])) {
                $wsPulado++;
            } else {
                $wsErro++;
            }
        }

        $osInfo = $rr['onesignal_info'] ?? null;
        if (is_array($osInfo)) {
            if (!empty($osInfo['ok'])) {
                $osOk++;
                $osRecipientsTotal += (int)($osInfo['recipients'] ?? 0);
            } elseif (!empty($osInfo['pulado'])) {
                $osPulado++;
            } else {
                $osErro++;
            }
        }
    }
    arsort($wsPorCluster);
    $wsAdminUrl = rtrim((string)($cfg['wp_url'] ?? ''), '/') . '/wp-admin/edit.php?post_type=web-story';
  ?>
    <!-- Persona do site ativo — guia a voz dos artigos gerados -->
    <?php $personaAtiva = $cfg['persona'] ?? null; if (is_array($personaAtiva) && !empty($personaAtiva['autor'])): ?>
      <div class="box" style="background:#0b0d11;border-left:3px solid #a78bfa;padding:10px 14px">
        <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap">
          <span style="font-size:22px">🎭</span>
          <div style="flex:1;min-width:260px">
            <div style="font-size:11px;color:#a78bfa;font-weight:700;text-transform:uppercase;letter-spacing:.3px">Persona editorial ativa · <?= h($cfg['_site_name'] ?? $siteSlug) ?></div>
            <div style="font-size:14px;color:#e2e8f0;font-weight:700;margin-top:2px"><?= h($personaAtiva['autor']) ?></div>
            <div style="font-size:12px;color:#cbd5e1;margin-top:4px"><em><?= h($personaAtiva['voz']) ?></em></div>
            <div style="font-size:11px;color:#94a3b8;margin-top:4px">
              <strong>Audiência:</strong> <?= h($personaAtiva['audiencia']) ?>
            </div>
          </div>
          <div style="font-size:10px;color:#6b7280">editar em <code style="background:#13161d;padding:2px 6px;border-radius:3px;color:#fbbf24">sites.php</code></div>
        </div>
      </div>
    <?php endif; ?>

    <!-- ═══ RESUMO + AÇÕES RÁPIDAS ═══ -->
    <div class="box" style="background:linear-gradient(135deg,#0b0d11,#1a1d23);border:1px solid #2a2e38">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
        <div>
          <h2 style="margin:0;font-size:18px;color:#fff">
            🗄 Registros salvos — <span style="color:#7dd3fc"><?= h($cfg['_site_name'] ?? $siteSlug) ?></span>
            <span style="color:#888;font-size:14px;font-weight:400">(<?= $contador['todos'] ?>)</span>
          </h2>
          <div style="margin-top:4px;font-size:12px;color:#9ca3af">
            <?php if (!empty($elegiveisMap)): ?>
              <span style="color:#fbbf24">🔄 <?= count($elegiveisMap) ?> elegíveis p/ update (+48h)</span> ·
            <?php endif; ?>
            <a href="?modo=atual&site=<?= urlencode($siteSlug) ?>" style="color:#a78bfa">← voltar pra Trends</a>
          </div>
        </div>
        <?php if (count($idsAprovadosTotal) > 0): ?>
          <button type="button" id="gerar-tudo-pendente" class="btn-save"
                  data-ids='<?= h(json_encode($idsAprovadosTotal)) ?>'
                  style="font-size:15px;padding:12px 22px"
                  title="Marca todos os <?= count($idsAprovadosTotal) ?> pendentes + dispara a fila automaticamente usando o LLM ativo (<?= $cfg['default_llm'] === 'openai' ? 'GPT' : 'Claude' ?>)">
            ▶ Gerar <?= count($idsAprovadosTotal) ?> pendentes
            (via <?= $cfg['default_llm'] === 'openai' ? 'GPT' : 'Claude' ?>)
          </button>
        <?php endif; ?>
      </div>

      <!-- Widget de cliques de afiliado (últimos 7 dias) -->
      <div style="margin:14px 0 0;padding:12px 14px;background:#0b0d12;border:1px solid #1f232b;border-radius:8px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:20px">🎯</span>
          <div>
            <div style="font-size:12px;color:#a78bfa;font-weight:700;text-transform:uppercase;letter-spacing:.3px">Afiliados · 7d</div>
            <div style="font-size:14px;color:#e2e8f0;font-weight:700"><?= $totalCliques7d ?> cliques rastreados</div>
          </div>
        </div>
        <?php if (!empty($cliques7d)):
          $top3 = array_slice($cliques7d, 0, 3, true);
        ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;flex:1">
            <?php foreach ($top3 as $slug => $n):
              $o = $ofertasMap[$slug] ?? null;
              if (!$o) continue;
            ?>
              <div style="background:#13161d;border:1px solid #2a2e38;border-radius:6px;padding:6px 10px;font-size:11px">
                <span style="font-weight:700;color:#e2e8f0"><?= $o['cta_emoji'] ?? '🎯' ?> <?= h(mb_substr($o['nome'], 0, 22)) ?></span>
                <span style="color:#fbbf24;font-weight:700;margin-left:4px"><?= $n ?></span>
                <span style="color:#6b7280;margin-left:2px">cliques</span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="flex:1;font-size:12px;color:#6b7280">Nenhum clique registrado ainda — artigos publicados com CTA ativam automaticamente.</div>
        <?php endif; ?>
        <a href="afiliados.php" class="btn-n" style="font-size:11px;padding:6px 12px;background:#1e1b4b;color:#a5b4fc;border:1px solid #4c1d95;border-radius:6px;text-decoration:none;font-weight:700">Gerenciar ofertas →</a>
      </div>

      <!-- Widget Web Stories (últimos 7 dias) -->
      <?php
        $wsTotal7d = $wsOk + $wsErro;
        $wsTaxaSucesso = $wsTotal7d > 0 ? round($wsOk * 100 / $wsTotal7d) : null;
      ?>
      <div style="margin:10px 0 0;padding:12px 14px;background:#0b0d12;border:1px solid #1f232b;border-radius:8px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:20px">📽️</span>
          <div>
            <div style="font-size:12px;color:#f59e0b;font-weight:700;text-transform:uppercase;letter-spacing:.3px">Web Stories · 7d</div>
            <div style="font-size:14px;color:#e2e8f0;font-weight:700">
              <?= $wsOk ?> criadas
              <?php if ($wsErro > 0): ?><span style="color:#fca5a5;font-size:12px">· <?= $wsErro ?> falharam</span><?php endif; ?>
              <?php if ($wsPulado > 0): ?><span style="color:#6b7280;font-size:12px">· <?= $wsPulado ?> puladas (ROI baixo)</span><?php endif; ?>
              <?php if ($wsTaxaSucesso !== null): ?>
                <span style="color:<?= $wsTaxaSucesso >= 90 ? '#4ade80' : ($wsTaxaSucesso >= 70 ? '#fbbf24' : '#fca5a5') ?>;font-size:12px;margin-left:6px"><?= $wsTaxaSucesso ?>% sucesso</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php if (!empty($wsPorCluster)):
          $topClusters = array_slice($wsPorCluster, 0, 3, true);
        ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;flex:1">
            <?php foreach ($topClusters as $ck => $n): ?>
              <div style="background:#13161d;border:1px solid #2a2e38;border-radius:6px;padding:6px 10px;font-size:11px">
                <span style="font-weight:700;color:#e2e8f0"><?= TrendsTaxonomia::emojiRoi($ck) ?> <?= h(TrendsTaxonomia::labelCurto($ck)) ?></span>
                <span style="color:#f59e0b;font-weight:700;margin-left:4px"><?= $n ?></span>
                <span style="color:#6b7280;margin-left:2px">stories</span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php elseif ($wsTotal7d === 0): ?>
          <div style="flex:1;font-size:12px;color:#6b7280">
            Nenhuma story gerada em 7 dias. <?php if (empty($cfg['webstory_enabled'])): ?>Ative com <code>WEBSTORY_ENABLED=1</code> no .env.<?php else: ?>Gera quando cluster tem ROI ≥ <?= $cfg['webstory_roi_min'] ?? 5.0 ?>.<?php endif; ?>
          </div>
        <?php endif; ?>
        <a href="<?= h($wsAdminUrl) ?>" target="_blank" class="btn-n" style="font-size:11px;padding:6px 12px;background:#78350f;color:#fcd34d;border:1px solid #d97706;border-radius:6px;text-decoration:none;font-weight:700">Ver Stories no WP ↗</a>
      </div>

      <!-- Widget Google Search Console -->
      <?php if ($gscData): ?>
        <?php
          $gscQ = $gscData['queries'] ?? null;
          $gscTotalClicks = (int)($gscQ['totals']['clicks'] ?? 0);
          $gscTotalImpr   = (int)($gscQ['totals']['impressions'] ?? 0);
          $gscCtr = $gscTotalImpr > 0 ? round($gscTotalClicks * 100 / $gscTotalImpr, 2) : 0;
          $gscSinc = strtotime($gscData['sincronizado_em'] ?? '');
          $gscIdadeH = $gscSinc ? round((time() - $gscSinc) / 3600) : null;
          $topQueries = array_slice($gscQ['rows'] ?? [], 0, 5);
        ?>
      <div style="margin:10px 0 0;padding:12px 14px;background:#0b0d12;border:1px solid #1f232b;border-radius:8px;display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:8px;min-width:180px">
          <span style="font-size:20px">🔍</span>
          <div>
            <div style="font-size:12px;color:#22c55e;font-weight:700;text-transform:uppercase;letter-spacing:.3px">Search Console · <?= (int)($gscData['periodo']['dias'] ?? 7) ?>d</div>
            <div style="font-size:14px;color:#e2e8f0;font-weight:700"><?= number_format($gscTotalClicks,0,',','.') ?> cliques · <?= number_format($gscTotalImpr,0,',','.') ?> impr</div>
            <div style="font-size:11px;color:#94a3b8">CTR <?= $gscCtr ?>% · sync <?= $gscIdadeH !== null ? $gscIdadeH . 'h atrás' : '?' ?></div>
          </div>
        </div>
        <?php if (!empty($topQueries)): ?>
          <div style="flex:1;min-width:260px">
            <div style="font-size:10.5px;color:#a78bfa;font-weight:700;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Top queries</div>
            <?php foreach ($topQueries as $q):
              $qTxt = (string)($q['keys'][0] ?? '?');
              $qC = (int)($q['clicks'] ?? 0);
              $qI = (int)($q['impressions'] ?? 0);
              $qCtr = (float)($q['ctr'] ?? 0) * 100;
              $qPos = (float)($q['position'] ?? 0);
            ?>
              <div style="font-size:11px;line-height:1.5;color:#cbd5e1">
                <span style="color:#fff"><?= h(mb_strimwidth($qTxt, 0, 50, '…')) ?></span>
                <span style="color:#fbbf24;font-family:monospace"> · <?= $qC ?>cl/<?= $qI ?>impr</span>
                <span style="color:#94a3b8;font-family:monospace"> · CTR <?= number_format($qCtr,1,',','') ?>%</span>
                <span style="color:#94a3b8;font-family:monospace"> · pos <?= number_format($qPos,1,',','') ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="flex:1;font-size:12px;color:#6b7280">Sem queries no período. Site pode estar sem tráfego ou recém indexado.</div>
        <?php endif; ?>
        <a href="https://search.google.com/search-console?resource_id=<?= urlencode($gscData['site_url']) ?>" target="_blank" class="btn-n" style="font-size:11px;padding:6px 12px;background:#14532d;color:#86efac;border:1px solid #16a34a;border-radius:6px;text-decoration:none;font-weight:700">Abrir GSC ↗</a>
      </div>
      <?php else: ?>
      <div style="margin:10px 0 0;padding:10px 14px;background:#0b0d12;border:1px dashed #2a2e38;border-radius:8px;font-size:12px;color:#6b7280">
        🔍 Search Console: sem cache. Rode <code style="background:#13161d;padding:2px 6px;border-radius:3px;color:#fbbf24">php scripts/sync_search_console.php</code> ou aguarde cron.
      </div>
      <?php endif; ?>

      <!-- Widget OneSignal (últimos 7 dias) -->
      <?php
        $osTotal7d = $osOk + $osErro;
        $osTaxaSucesso = $osTotal7d > 0 ? round($osOk * 100 / $osTotal7d) : null;
        $onesignalAtivo = !empty($cfg['onesignal_app_id']) && !empty($cfg['onesignal_enabled']);
      ?>
      <?php if ($onesignalAtivo): ?>
      <div style="margin:10px 0 0;padding:12px 14px;background:#0b0d12;border:1px solid #1f232b;border-radius:8px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:20px">🔔</span>
          <div>
            <div style="font-size:12px;color:#60a5fa;font-weight:700;text-transform:uppercase;letter-spacing:.3px">Push Notifications · 7d</div>
            <div style="font-size:14px;color:#e2e8f0;font-weight:700">
              <?= $osOk ?> enviadas
              <?php if ($osRecipientsTotal > 0): ?><span style="color:#4ade80;font-size:12px">· <?= number_format($osRecipientsTotal, 0, ',', '.') ?> subscribers atingidos</span><?php endif; ?>
              <?php if ($osErro > 0): ?><span style="color:#fca5a5;font-size:12px">· <?= $osErro ?> falharam</span><?php endif; ?>
              <?php if ($osPulado > 0): ?><span style="color:#6b7280;font-size:12px">· <?= $osPulado ?> puladas</span><?php endif; ?>
              <?php if ($osTaxaSucesso !== null): ?>
                <span style="color:<?= $osTaxaSucesso >= 90 ? '#4ade80' : ($osTaxaSucesso >= 70 ? '#fbbf24' : '#fca5a5') ?>;font-size:12px;margin-left:6px"><?= $osTaxaSucesso ?>% sucesso</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div style="flex:1;font-size:12px;color:#6b7280">
          Site alvo: <code style="color:#a5f3fc"><?= h($cfg['onesignal_site_target'] ?: 'todos') ?></code> · ROI ≥ <?= (float)$cfg['onesignal_roi_min'] ?>
          <?php if ($osTotal7d === 0): ?>
            · <em>nenhuma notificação enviada ainda — só dispara quando artigo é publicado em <?= h($cfg['onesignal_site_target'] ?: 'site-alvo') ?></em>
          <?php endif; ?>
        </div>
        <a href="https://dashboard.onesignal.com/apps/<?= h($cfg['onesignal_app_id']) ?>" target="_blank" class="btn-n" style="font-size:11px;padding:6px 12px;background:#1e3a8a;color:#bfdbfe;border:1px solid #2563eb;border-radius:6px;text-decoration:none;font-weight:700">Dashboard OneSignal ↗</a>
      </div>
      <?php endif; ?>

      <!-- Filtros de status -->
      <div class="toolbar" style="margin:14px 0 0;padding-bottom:0;border:none;gap:14px">
        <div class="filter-pills">
          <span class="muted" style="font-size:11px">Filtro:</span>
          <?php
          $filtros = [
              ['key'=>'todos',     'label'=>'Todos',      'cor'=>'#a78bfa'],
              ['key'=>'aprovado',  'label'=>'⏸ Pendentes', 'cor'=>'#fbbf24'],
              ['key'=>'publicado', 'label'=>'✅ Publicados','cor'=>'#4ade80'],
              ['key'=>'suspeita',  'label'=>'⚠️ Suspeita', 'cor'=>'#fca5a5'],
          ];
          foreach ($filtros as $f):
              $ativo = ($filtroStatus === $f['key']);
              $n = $contador[$f['key']];
              $qs = array_merge($_GET, ['filtro' => $f['key']]);
              unset($qs['saved']); // evita re-mostrar banner
          ?>
            <a href="?<?= http_build_query($qs) ?>" class="pill <?= $ativo ? 'on' : 'off' ?>"
               style="<?= $ativo ? 'background:' . $f['cor'] . ';color:#0f1115' : '' ?>">
              <?= h($f['label']) ?> (<?= $n ?>)
            </a>
          <?php endforeach; ?>
        </div>
        <div style="flex:1;min-width:200px">
          <input type="search" id="busca-salvos" placeholder="🔍 Buscar no título ou termo..."
                 style="width:100%;padding:8px 12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:13px">
        </div>
      </div>

      <!-- Filtros avançados: cluster + score mín + ROI mín (client-side) -->
      <div class="toolbar adv-filters" style="margin:10px 0 0;padding:10px 14px;background:#0b0d12;border:1px solid #1f232b;border-radius:8px;gap:18px;flex-wrap:wrap;align-items:center">
        <div style="display:flex;align-items:center;gap:6px">
          <label class="muted" style="font-size:11px">Cluster:</label>
          <select id="filtro-cluster" style="padding:6px 10px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#e2e8f0;font-size:12px;cursor:pointer">
            <option value="">Todos (<?= count($salvos) ?>)</option>
            <?php
              // Conta quantos salvos por cluster
              $porCluster = [];
              foreach ($salvos as $rr) {
                  $ss = DiscoverSinaisEditoriais::ler($rr);
                  $ck = $ss['cluster_detect']['key'] ?? 'curiosidades_geral';
                  $porCluster[$ck] = ($porCluster[$ck] ?? 0) + 1;
              }
              arsort($porCluster);
              foreach ($porCluster as $ck => $cnt):
            ?>
              <option value="<?= h($ck) ?>"><?= TrendsTaxonomia::emojiRoi($ck) ?> <?= h(TrendsTaxonomia::labelCurto($ck)) ?> (<?= $cnt ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;align-items:center;gap:8px;min-width:180px">
          <label class="muted" style="font-size:11px;white-space:nowrap">Score mín:</label>
          <input type="range" id="filtro-score-min" min="0" max="10" step="0.5" value="0"
                 style="flex:1;accent-color:#22c55e;cursor:pointer">
          <span id="filtro-score-min-val" style="font-size:11px;color:#e2e8f0;font-family:monospace;min-width:28px;text-align:right">0</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;min-width:180px">
          <label class="muted" style="font-size:11px;white-space:nowrap">ROI mín:</label>
          <input type="range" id="filtro-roi-min" min="0" max="10" step="0.5" value="0"
                 style="flex:1;accent-color:#3b82f6;cursor:pointer">
          <span id="filtro-roi-min-val" style="font-size:11px;color:#e2e8f0;font-family:monospace;min-width:28px;text-align:right">0</span>
        </div>
        <button type="button" id="filtro-reset" class="btn-n" style="padding:5px 12px;font-size:11px">↺ Limpar</button>
        <span id="filtro-contador" class="muted" style="font-size:11px;margin-left:auto">—</span>
      </div>
    </div>

      <?php
      // Lista os aprovados-não-publicados (elegíveis pra gerar em lote)
      $aprovadosPendentes = array_values(array_filter($salvos, fn($r) => ($r['status'] ?? '') === 'aprovado'));
      ?>
      <?php
      $cluster = new DiscoverCluster($cfg, $db);
      $clustersDb = $cluster->listarClusters($siteSlug);
      ?>
      <?php if (!empty($clustersDb)): ?>
        <div class="box" style="border-left:4px solid #0369a1">
          <h2 style="color:#7dd3fc;margin-bottom:4px">🔗 Clusters sazonais · <?= count($clustersDb) ?></h2>
          <div class="muted" style="font-size:12px;margin-bottom:12px">
            Grupos de artigos do mesmo evento. Interligar faz todos apontarem entre si (topic authority + tempo de sessão).
          </div>
          <?php foreach ($clustersDb as $cl):
            $pct = $cl['total'] > 0 ? round($cl['publicados'] * 100 / $cl['total']) : 0;
            $allInterlinked = $cl['publicados'] > 0 && $cl['interligados'] === $cl['publicados'];
            // IDs dos "aprovado" (pendentes de geração) deste cluster
            $idsAprovados = array_values(array_map(fn($i) => (int)$i['id'],
                array_filter($cl['items'], fn($i) => ($i['status'] ?? '') === 'aprovado')));
            $idsSuspeita  = array_values(array_map(fn($i) => (int)$i['id'],
                array_filter($cl['items'], fn($i) => ($i['status'] ?? '') === 'suspeita')));
          ?>
            <div class="cal-card" style="border-left:3px solid <?= $allInterlinked ? '#22c55e' : '#0369a1' ?>">
              <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
                <div style="flex:1;min-width:260px">
                  <strong style="color:#fff;font-size:14px"><?= h($cl['nome']) ?></strong>
                  <?php if (!empty($cl['data_pico'])): ?>
                    <span class="muted" style="margin-left:8px;font-size:12px">📌 pico <?= h($cl['data_pico']) ?></span>
                  <?php endif; ?>
                  <div style="font-size:12px;color:#9ca3af;margin-top:3px">
                    <?= $cl['publicados'] ?>/<?= $cl['total'] ?> publicados
                    <?php if (count($idsAprovados) > 0): ?>
                      · <span style="color:#fbbf24"><?= count($idsAprovados) ?> aguardando geração</span>
                    <?php endif; ?>
                    <?php if (count($idsSuspeita) > 0): ?>
                      · <span style="color:#fca5a5"><?= count($idsSuspeita) ?> suspeita (revisar)</span>
                    <?php endif; ?>
                    <?php if ($cl['interligados'] > 0): ?>
                      · <span style="color:#4ade80"><?= $cl['interligados'] ?> interligados ✓</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                  <?php if (count($idsAprovados) > 0):
                    $llmLabel = ($cfg['default_llm'] === 'openai') ? 'GPT' : 'Claude';
                    $llmCor   = ($cfg['default_llm'] === 'openai') ? '#064e3b' : '#4c1d95';
                  ?>
                    <button type="button" class="btn-g btn-gerar-cluster"
                            data-evento="<?= h($cl['nome']) ?>"
                            data-ids='<?= h(json_encode($idsAprovados)) ?>'
                            style="background:<?= $llmCor ?>;"
                            title="Respeita o toggle global de LLM no header. Muda lá pra trocar.">
                      ▶ Gerar <?= count($idsAprovados) ?> (via <?= $llmLabel ?>)
                    </button>
                    <?php if ($cfg['default_llm'] === 'claude'): ?>
                      <button type="button" class="btn-n btn-gerar-gpt-unico"
                              data-ids='<?= h(json_encode($idsAprovados)) ?>'
                              data-evento="<?= h($cl['nome']) ?>"
                              title="Teste A/B — gera só 1 via GPT mesmo com toggle em Claude (pra comparar)">
                        🤖 Testar 1 via GPT
                      </button>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($cl['publicados'] >= 2 && !$allInterlinked): ?>
                    <button type="button" class="btn-g btn-link-cluster" data-evento="<?= h($cl['nome']) ?>">🔗 Interligar (<?= $cl['publicados'] ?>)</button>
                  <?php elseif ($allInterlinked): ?>
                    <button type="button" class="btn-g btn-link-cluster" data-evento="<?= h($cl['nome']) ?>" style="background:#14532d">🔄 Re-interligar</button>
                  <?php elseif ($cl['publicados'] < 2 && count($idsAprovados) === 0): ?>
                    <span class="muted" style="font-size:11px">aguardando 2+ posts publicados</span>
                  <?php endif; ?>
                  <?php $idsPublicados = array_values(array_map(fn($i) => (int)$i['id'],
                      array_filter($cl['items'], fn($i) => in_array($i['status'] ?? '', ['publicado','suspeita'])))); ?>
                  <?php if (count($idsPublicados) > 0): ?>
                    <button type="button" class="btn-n btn-reformatar"
                            data-ids='<?= h(json_encode($idsPublicados)) ?>'
                            title="Roda DiscoverPostProcess nos posts existentes — cards de mensagem, auto-links, schemas. Sem gastar IA.">
                      ✨ Reformatar (<?= count($idsPublicados) ?>)
                    </button>
                  <?php endif; ?>
                  <select class="btn-n select-mover" data-evento="<?= h($cl['nome']) ?>" data-from="<?= h($siteSlug) ?>" style="padding:5px 8px">
                    <option value="">↪ Mover cluster pra...</option>
                    <?php foreach ($sites as $slug => $s): if ($slug === $siteSlug) continue; ?>
                      <option value="<?= h($slug) ?>"><?= h($s['name'] ?? $slug) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($aprovadosPendentes)): ?>
        <div id="batch-panel" style="background:#0b0d11;border:1px solid #2a2e38;border-radius:8px;padding:12px 16px;margin-bottom:16px">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
            <div>
              <strong style="color:#86efac">🚀 Fila de geração em lote</strong>
              <div class="muted" style="margin-top:3px;font-size:12px">
                <?= count($aprovadosPendentes) ?> aprovados pendentes. Selecione abaixo e dispare o lote.
              </div>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <button type="button" id="select-all-aprovados" class="btn-n" style="margin:0">☑ Marcar todos</button>
              <button type="button" id="clear-aprovados" class="btn-n" style="margin:0">☐ Limpar</button>
              <button type="button" id="start-batch" class="btn-g" style="margin:0" disabled>▶ Iniciar lote (<span id="sel-count">0</span>)</button>
            </div>
          </div>
          <div id="batch-progress" style="display:none;margin-top:14px"></div>
        </div>
      <?php endif; ?>
      <?php if (empty($salvos)): ?>
        <div class="muted">Nenhum trend salvo para este site ainda. Vá em Trends atuais e clique em "Salvar aprovados".</div>
      <?php else: ?>
        <div style="margin:-6px 0 12px;display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap">
          <button type="button" id="avaliar-todos" class="btn-n"
                  title="Roda DiscoverQualityScore em TODOS os posts publicados do site. Mostra quais estão fracos.">
            🧪 Avaliar qualidade de todos
          </button>
        </div>
        <table>
          <thead><tr>
            <th style="width:30px">☐</th>
            <th style="width:60px">ID</th>
            <th style="width:70px">Score</th>
            <th style="width:75px">Qualidade</th>
            <th>Termo</th>
            <th class="cluster-col" style="width:130px">Cluster / ROI</th>
            <th style="width:110px">Intenção</th>
            <th style="width:100px">Volume</th>
            <th style="width:140px">Atualização</th>
            <th style="width:110px">Status</th>
            <th style="width:140px">Ação</th>
          </tr></thead>
          <tbody>
            <?php foreach ($salvos as $r):
                $idadeH = $elegiveisMap[$r['id']] ?? null;
                $isAprovado = ($r['status'] ?? '') === 'aprovado';
                // Campo agregado pra busca JS (case-insensitive substring)
                $searchBag = mb_strtolower(($r['termo'] ?? '') . ' ' . ($r['titulo'] ?? '') . ' ' . ($r['evento_fonte'] ?? ''), 'UTF-8');
                // Pré-compute sinais editoriais (usados em várias <td>)
                $s = DiscoverSinaisEditoriais::ler($r);
                $painR = $s['pain']; $clusterR = $s['cluster_detect']; $arbR = $s['arbitragem'];
                $clusterKeyR  = $clusterR['key'] ?? 'curiosidades_geral';
                $roiR         = TrendsTaxonomia::roiEditorial($clusterKeyR);
                $classeRoiR   = TrendsTaxonomia::classeRoi($clusterKeyR);
                $emojiRoiR    = TrendsTaxonomia::emojiRoi($clusterKeyR);
                $labelCurtoR  = TrendsTaxonomia::labelCurto($clusterKeyR);
                $thresholdR   = TrendsTaxonomia::threshold($clusterKeyR);
                $scoreR       = (float)$r['score_discover'];
                [$classeScoreR, $labelScoreR] = scoreRotulo($scoreR, $thresholdR);
            ?>
              <tr data-batch-id="<?= (int)$r['id'] ?>"
                  data-search="<?= h($searchBag) ?>"
                  data-status="<?= h($r['status'] ?? '') ?>"
                  data-cluster="<?= h($clusterKeyR) ?>"
                  data-score="<?= number_format($scoreR, 2, '.', '') ?>"
                  data-roi="<?= $roiR ?>"
                  class="tr-salvo <?= $isAprovado ? 'row-aprovado' : '' ?>">
                <td>
                  <?php if ($isAprovado): ?>
                    <input type="checkbox" class="batch-check" value="<?= (int)$r['id'] ?>">
                  <?php endif; ?>
                </td>
                <td class="muted">#<?= (int)$r['id'] ?></td>
                <td>
                  <div class="score-box" title="Threshold do cluster: <?= $thresholdR ?> · Score: <?= number_format($scoreR,2,',','') ?>">
                    <div class="score-badge <?= $scoreR>=9?'s-top':($scoreR>=7?'s-ok':'s-mid') ?>"><?= number_format($scoreR, 1, ',', '') ?></div>
                    <div class="score-bar-wrap"><div class="score-bar-fill <?= $classeScoreR ?>" style="width:<?= min(100, $scoreR * 10) ?>%"></div></div>
                    <div class="score-label <?= $classeScoreR ?>"><?= $labelScoreR ?></div>
                  </div>
                </td>
                <td>
                  <?php $qs = $r['quality_score'] ?? null; ?>
                  <?php if ($qs !== null): ?>
                    <?php $qCor = $qs >= 8.5 ? 's-top' : ($qs >= 7 ? 's-ok' : ($qs >= 5 ? 's-mid' : 's-low')); ?>
                    <div class="score-badge <?= $qCor ?>"
                         title="Qualidade Discover: <?= h($r['quality_status'] ?? '') ?><?php if (!empty($r['quality_melhorias'])): ?>&#10;&#10;Melhorias:&#10;<?php foreach ($r['quality_melhorias'] as $mm) echo '• ' . h(is_array($mm) ? ($mm['sugestao'] ?? '') : $mm) . '&#10;'; endif; ?>"
                         style="cursor:help;font-size:12px">
                      <?= number_format((float)$qs, 1, ',', '') ?>
                    </div>
                  <?php else: ?>
                    <span class="muted" style="font-size:10px">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="termo"><?= h($r['termo']) ?></div>
                  <?php if (!empty($r['titulo'])): ?>
                    <div class="muted" style="margin-top:4px;font-size:12px;color:#c4b5fd">📌 <?= h($r['titulo']) ?></div>
                  <?php endif; ?>
                  <div class="muted" style="margin-top:3px"><?= h($r['categoria']) ?></div>
                </td>
                <td class="cluster-col">
                  <div class="cluster-chip <?= h($classeRoiR) ?>"
                       title="<?= h($clusterR['nome'] ?? '—') ?> · RPM R$ <?= $arbR['rpm_ajustado'] ?>/mil · ROI <?= $roiR ?>/10 · threshold <?= $thresholdR ?>">
                    <span class="c-emoji"><?= $emojiRoiR ?></span>
                    <span class="c-name"><?= h($labelCurtoR) ?></span>
                  </div>
                  <div class="roi-bar"><div class="roi-bar-fill <?= $roiR >= 7 ? 'alto' : ($roiR >= 3 ? 'medio' : 'baixo') ?>" style="width:<?= min(100, $roiR * 10) ?>%"></div></div>
                  <div class="roi-value">ROI <strong><?= $roiR ?></strong>/10 · R$ <?= $arbR['rpm_ajustado'] ?>/mil</div>
                </td>
                <td>
                  <span class="intent-tag intent-<?= h($r['intencao']) ?>"><?= h($r['intencao']) ?></span>
                  <?php if ($painR['peso_total'] >= 3): ?>
                    <div class="pain-chip pain-<?= h($painR['dominante']) ?>" title="Urgência:<?= $painR['urgencia'] ?> · Medo:<?= $painR['medo'] ?> · Dinheiro:<?= $painR['dinheiro'] ?> · Oportunidade:<?= $painR['oportunidade'] ?>">
                      <?= h(DiscoverPainClassifier::labelCurto($painR)) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td><span class="vol"><?= h($r['volume_label']) ?></span><?php if (!empty($r['growth_pct'])): ?><div class="muted" style="margin-top:3px">+<?= (int)$r['growth_pct'] ?>%</div><?php endif; ?></td>
                <td class="muted" style="font-size:11px">
                  <?= h($r['ultimo_update'] ?? $r['data_detectada']) ?>
                  <?php if ($idadeH !== null): ?>
                    <div style="color:#fbbf24;margin-top:2px">⏱ <?= $idadeH ?>h atrás</div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="intent-tag intent-<?= h($r['status']) ?>"><?= h($r['status']) ?></span>
                  <?php
                    $wsI = $r['web_story_info'] ?? null;
                    if (is_array($wsI)):
                      if (!empty($wsI['ok'])):
                        $wsTt = 'Web Story #' . (int)$wsI['story_id'] . ' · ' . (int)$wsI['scenes'] . ' cenas · ' . (int)$wsI['tempo_ms'] . 'ms';
                        if (!empty($wsI['view_url'])):
                  ?>
                    <a href="<?= h($wsI['view_url']) ?>" target="_blank" class="intent-tag" title="<?= h($wsTt) ?>"
                       style="display:inline-block;margin-top:4px;background:#78350f;color:#fcd34d;text-decoration:none;font-size:10px">📽️ Story</a>
                  <?php   else: ?>
                    <span class="intent-tag" title="<?= h($wsTt) ?>" style="display:inline-block;margin-top:4px;background:#78350f;color:#fcd34d;font-size:10px">📽️ Story</span>
                  <?php   endif; elseif (!empty($wsI['pulado'])): ?>
                    <span class="intent-tag" title="Web Story pulada (ROI baixo ou desabilitado)" style="display:inline-block;margin-top:4px;background:#27272a;color:#78716c;font-size:10px">📽️ —</span>
                  <?php elseif (isset($wsI['erro'])): ?>
                    <span class="intent-tag" title="Falha: <?= h($wsI['erro']) ?>" style="display:inline-block;margin-top:4px;background:#450a0a;color:#fca5a5;font-size:10px">📽️✗</span>
                  <?php endif; endif; ?>
                  <?php $osI = $r['onesignal_info'] ?? null; if (is_array($osI)):
                      if (!empty($osI['ok'])):
                        $osTt = 'Push #' . ($osI['notification_id'] ?? '?') . ' · ' . ($osI['recipients'] ?? 0) . ' subscribers';
                  ?>
                    <span class="intent-tag" title="<?= h($osTt) ?>" style="display:inline-block;margin-top:4px;background:#1e3a8a;color:#bfdbfe;font-size:10px">🔔 Push</span>
                  <?php elseif (!empty($osI['pulado'])): ?>
                    <span class="intent-tag" title="Push pulado (ROI baixo, site ≠ target ou desabilitado)" style="display:inline-block;margin-top:4px;background:#27272a;color:#78716c;font-size:10px">🔔 —</span>
                  <?php elseif (isset($osI['erro'])): ?>
                    <span class="intent-tag" title="Falha push: <?= h($osI['erro']) ?>" style="display:inline-block;margin-top:4px;background:#450a0a;color:#fca5a5;font-size:10px">🔔✗</span>
                  <?php endif; endif; ?>
                  <?php if (!empty($r['angulo'])): ?>
                    <div class="muted" style="margin-top:4px;font-size:10px">ângulo: <strong><?= h($r['angulo']) ?></strong></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (in_array($r['status'] ?? '', ['publicado','suspeita'], true) && !empty($r['url_post'])): ?>
                    <a href="<?= h($r['url_post']) ?>" target="_blank" class="btn-n" style="text-decoration:none;display:inline-block;margin:0 0 4px 0">✏️ Editar</a>
                    <button type="button" class="btn-n btn-reformatar" data-ids='<?= h(json_encode([(int)$r['id']])) ?>'
                            title="Roda DiscoverPostProcess — cards de mensagem, auto-links, schemas, cluster interlink. Sem IA.">✨ Reformatar</button>
                    <button type="button" class="btn-b btn-revisar" data-id="<?= (int)$r['id'] ?>"
                            title="Revisa o post via Claude: otimiza intro, corta redundâncias, humaniza, entrega 5 títulos/3 aberturas/5 frases alternativas. Gasta API (~60s).">🔄 Revisar</button>
                    <button type="button" class="btn-u btn-regerar" data-id="<?= (int)$r['id'] ?>"
                            title="Manda post atual pro lixo do WP e volta o trend pra fila pra refazer do zero (gasta API)">🔁 Regerar</button>
                    <?php if ($idadeH !== null): ?>
                      <button type="button" class="btn-u" data-id="<?= (int)$r['id'] ?>" title="Atualizar este post (saneia temporais + adiciona 'O que mudou recentemente')">🔄 Atualizar</button>
                    <?php endif; ?>
                  <?php endif; ?>
                  <button type="button" class="btn-x btn-excluir-trend" data-id="<?= (int)$r['id'] ?>" data-termo="<?= h($r['termo']) ?>"
                          title="Remove este trend do banco local (não mexe no WP). Útil pra liberar pra re-coleta.">🗑️</button>
                </td>
              </tr>
              <tr class="u-row" id="u-row-<?= (int)$r['id'] ?>" style="display:none">
                <td colspan="11" class="q-cell"><div class="u-slot">…</div></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($trends) && $modo === 'atual' && !$visualizarSalvos): ?>
    <div class="box">
      <h2 style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <span>📋 Trends coletados (<?= count($trends) ?>) · ⭐ <?= $totalAprovados ?> com score alto · 💾 <?= $totalSalvos ?> salvos em <strong style="color:#7dd3fc"><?= h($cfg['_site_name'] ?? $siteSlug) ?></strong></span>
        <span style="font-size:12px;font-weight:normal">
          <a href="?modo=atual&view=saved&site=<?= urlencode($siteSlug) ?>" style="color:#a78bfa;font-weight:700">🗄 Ver Salvos →</a>
        </span>
      </h2>

      <?php
        $qs = function(array $override = []) use ($hours, $sort, $scoreMin, $catFilter, $sortBy, $search, $limit) {
          $base = ['modo'=>'atual','hours'=>$hours,'sort'=>$sort,'score_min'=>$scoreMin,'cat'=>$catFilter,'sort_by'=>$sortBy,'q'=>$search,'limit'=>$limit];
          return '?' . http_build_query(array_merge($base, $override));
        };
      ?>
      <form method="GET" class="toolbar" style="flex-wrap:wrap;gap:10px;align-items:end">
        <input type="hidden" name="modo" value="atual">
        <input type="hidden" name="hours" value="<?= $hours ?>">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <div>
          <label style="display:block;font-size:10px;color:#888;margin-bottom:3px">📂 Categoria</label>
          <select name="cat" onchange="this.form.submit()" style="padding:6px 10px;background:#1a1d24;color:#fff;border:1px solid #334155;border-radius:4px;font-size:12px">
            <option value="0">🌐 Todas</option>
            <?php foreach (TrendsScraperWeb::$categoriasMap as $cid => $cname): ?>
              <option value="<?= $cid ?>" <?= $catFilter === $cid ? 'selected' : '' ?>><?= h($cname) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:10px;color:#888;margin-bottom:3px">📊 Ordenar por</label>
          <select name="sort_by" onchange="this.form.submit()" style="padding:6px 10px;background:#1a1d24;color:#fff;border:1px solid #334155;border-radius:4px;font-size:12px">
            <option value="score"   <?= $sortBy==='score'?'selected':'' ?>>Score Discover</option>
            <option value="volume"  <?= $sortBy==='volume'?'selected':'' ?>>Volume de busca</option>
            <option value="growth"  <?= $sortBy==='growth'?'selected':'' ?>>Crescimento %</option>
            <option value="recency" <?= $sortBy==='recency'?'selected':'' ?>>Mais recente</option>
            <option value="arb"     <?= $sortBy==='arb'?'selected':'' ?>>💎 Arbitragem (RPM)</option>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:10px;color:#888;margin-bottom:3px">🎯 Score mín.</label>
          <select name="score_min" onchange="this.form.submit()" style="padding:6px 10px;background:#1a1d24;color:#fff;border:1px solid #334155;border-radius:4px;font-size:12px">
            <option value="0" <?= $scoreMin==0?'selected':'' ?>>Todos</option>
            <option value="5" <?= $scoreMin==5?'selected':'' ?>>≥ 5</option>
            <option value="6" <?= $scoreMin==6?'selected':'' ?>>≥ 6</option>
            <option value="7" <?= $scoreMin==7?'selected':'' ?>>≥ 7</option>
            <option value="9" <?= $scoreMin==9?'selected':'' ?>>≥ 9 (top)</option>
          </select>
        </div>
        <div style="flex:1;min-width:180px">
          <label style="display:block;font-size:10px;color:#888;margin-bottom:3px">🔍 Buscar termo</label>
          <input type="text" name="q" value="<?= h($search) ?>" placeholder="ex: enem, inss, cnh" style="width:100%;padding:6px 10px;background:#1a1d24;color:#fff;border:1px solid #334155;border-radius:4px;font-size:12px">
        </div>
        <div>
          <label style="display:block;font-size:10px;color:#888;margin-bottom:3px">📄 Mostrar</label>
          <select name="limit" onchange="this.form.submit()" style="padding:6px 10px;background:#1a1d24;color:#fff;border:1px solid #334155;border-radius:4px;font-size:12px">
            <option value="100"  <?= $limit==100?'selected':'' ?>>Top 100</option>
            <option value="500"  <?= $limit==500?'selected':'' ?>>Top 500</option>
            <option value="1000" <?= $limit==1000?'selected':'' ?>>Top 1000</option>
            <option value="3000" <?= $limit==3000?'selected':'' ?>>Tudo (≤3000)</option>
          </select>
        </div>
        <div>
          <button type="submit" style="padding:7px 16px;background:#0b57d0;color:#fff;border:none;border-radius:4px;font-weight:700;cursor:pointer;font-size:12px">Aplicar</button>
          <?php if ($catFilter || $search || $scoreMin > 0 || $sortBy !== 'score'): ?>
            <a href="<?= $qs(['cat'=>0,'q'=>'','score_min'=>0,'sort_by'=>'score']) ?>" style="padding:7px 12px;background:#334155;color:#cbd5e1;border-radius:4px;text-decoration:none;font-size:11px;margin-left:4px">✕ limpar</a>
          <?php endif; ?>
        </div>
      </form>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <form method="POST" onsubmit="return confirm('Salvar os <?= $totalVisiveis ?> trends visíveis no banco?')" style="margin:0">
            <input type="hidden" name="acao" value="salvar_aprovados">
            <input type="hidden" name="manual" value="1">
            <input type="hidden" name="site" value="<?= h($siteSlug) ?>">
            <input type="hidden" name="origem" value="<?= $hours ?>h">
            <input type="hidden" name="trends_json" value="<?= h(json_encode(array_slice($trends, 0, 500), JSON_UNESCAPED_UNICODE)) ?>">
            <button type="submit" class="btn-save" <?= $totalVisiveis === 0 ? 'disabled' : '' ?>>💾 Salvar <?= $totalVisiveis ?> visíveis</button>
          </form>
          <?php $totalExibidos = count($trends); if ($totalExibidos > 0): ?>
            <form method="POST" onsubmit="return confirm('Salvar os <?= $totalExibidos ?> trends EXIBIDOS (inclusive score < 7)?\n\nUso: quando o score automático está muito rigoroso e você quer salvar manualmente. Use o filtro de score acima pra limitar antes.')" style="margin:0">
              <input type="hidden" name="acao" value="salvar_aprovados">
              <input type="hidden" name="site" value="<?= h($siteSlug) ?>">
              <input type="hidden" name="origem" value="<?= $hours ?>h">
              <input type="hidden" name="manual" value="1">
              <input type="hidden" name="trends_json" value="<?= h(json_encode(array_slice($trends, 0, 500), JSON_UNESCAPED_UNICODE)) ?>">
              <button type="submit" class="btn-n" style="background:#78350f;color:#fcd34d;border-color:#d97706" title="Ignora o threshold automático de score ≥ 7. Salva todos os trends exibidos nesta tela (após filtros).">⚠️ Salvar <?= $totalExibidos ?> manual (sem threshold)</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <table>
        <thead>
          <tr>
            <th style="width:30px">#</th>
            <th style="width:70px">Score</th>
            <th>Termo</th>
            <th style="width:110px">Volume / Crescimento</th>
            <th style="width:120px">Início / Status</th>
            <th style="width:110px">Intenção</th>
            <th class="cluster-col" style="width:150px">Cluster · ROI · Grupo</th>
            <th style="width:100px">Notícias</th>
            <th style="width:150px">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($trends as $i => $t):
              $sc = $t['score'];
              // Pré-compute sinais (usados em várias <td>)
              $sT = DiscoverSinaisEditoriais::ler($t);
              $painT = $sT['pain']; $clusterT = $sT['cluster_detect']; $arbT = $sT['arbitragem'];
              $clusterKeyT  = $clusterT['key'] ?? 'curiosidades_geral';
              $roiT         = TrendsTaxonomia::roiEditorial($clusterKeyT);
              $classeRoiT   = TrendsTaxonomia::classeRoi($clusterKeyT);
              $emojiRoiT    = TrendsTaxonomia::emojiRoi($clusterKeyT);
              $labelCurtoT  = TrendsTaxonomia::labelCurto($clusterKeyT);
              $thresholdT   = (float)($t['threshold'] ?? TrendsTaxonomia::threshold($clusterKeyT));
              [$classeScoreT, $labelScoreT] = scoreRotulo((float)$sc, $thresholdT);
          ?>
            <tr>
              <td class="muted"><?= $i + 1 ?></td>
              <td>
                <div class="score-box" title="Trend:<?= $t['score_breakdown']['trend'] ?> · Emoção:<?= $t['score_breakdown']['emocao'] ?> · Intenção:<?= $t['score_breakdown']['intencao'] ?> · Alcance:<?= $t['score_breakdown']['alcance'] ?> · threshold <?= $thresholdT ?>">
                  <div class="score-badge <?= $sc>=9?'s-top':($sc>=7?'s-ok':($sc>=5?'s-mid':'s-low')) ?>"><?= number_format($sc, 1, ',', '') ?></div>
                  <div class="score-bar-wrap"><div class="score-bar-fill <?= $classeScoreT ?>" style="width:<?= min(100, $sc * 10) ?>%"></div></div>
                  <div class="score-label <?= $classeScoreT ?>"><?= $labelScoreT ?></div>
                </div>
              </td>
              <td>
                <div class="termo"><?= h($t['termo']) ?></div>
                <?php if (!empty($t['relacionados'])): ?>
                  <div class="muted" style="margin-top:3px">
                    relacionados: <?= h(implode(' · ', array_slice($t['relacionados'], 0, 4))) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($t['volume_label']): ?>
                  <span class="vol"><?= h($t['volume_label']) ?></span>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
                <?php if (!empty($t['growth_pct'])): ?>
                  <div style="margin-top:3px;color:#4ade80;font-size:11px;font-weight:700">↑ +<?= $t['growth_pct'] ?>%</div>
                <?php endif; ?>
              </td>
              <td style="font-size:11px">
                <?php if (!empty($t['iniciado_rel'])): ?>
                  <div title="<?= h($t['iniciado_em']) ?>"><?= h($t['iniciado_rel']) ?></div>
                <?php endif; ?>
                <?php if (!empty($t['ativa'])): ?>
                  <div style="margin-top:3px;color:#4ade80;font-weight:700">● Ativa</div>
                <?php elseif (!empty($t['duracao_label'])): ?>
                  <div style="margin-top:3px;color:#94a3b8" title="Encerrou em <?= h($t['terminado_em'] ?? '') ?>">⏱ <?= h($t['duracao_label']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="intent-tag intent-<?= h($t['intencao']) ?>"><?= h($t['intencao']) ?></span>
                <?php if ($painT['peso_total'] >= 3): ?>
                  <div class="pain-chip pain-<?= h($painT['dominante']) ?>" title="Urgência:<?= $painT['urgencia'] ?> · Medo:<?= $painT['medo'] ?> · Dinheiro:<?= $painT['dinheiro'] ?> · Oportunidade:<?= $painT['oportunidade'] ?>">
                    <?= h(DiscoverPainClassifier::labelCurto($painT)) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="cluster-col">
                <div class="cluster-chip <?= h($classeRoiT) ?>"
                     title="<?= h($clusterT['nome']) ?> · RPM R$ <?= $arbT['rpm_ajustado'] ?>/mil · ROI <?= $roiT ?>/10 · <?= h($t['briefing']['grupo_editorial']) ?>">
                  <span class="c-emoji"><?= $emojiRoiT ?></span>
                  <span class="c-name"><?= h($labelCurtoT) ?></span>
                </div>
                <div class="roi-bar" title="ROI editorial <?= $roiT ?>/10">
                  <div class="roi-bar-fill <?= $roiT >= 7 ? 'alto' : ($roiT >= 3 ? 'medio' : 'baixo') ?>" style="width:<?= min(100, $roiT * 10) ?>%"></div>
                </div>
                <div class="muted" style="margin-top:3px;font-size:10.5px">
                  <span class="grupo grupo-<?= h(strtolower($t['briefing']['grupo_editorial'])) ?>" style="font-size:9px;padding:2px 6px"><?= h($t['briefing']['grupo_editorial']) ?></span>
                </div>
                <div class="muted" style="margin-top:3px;font-size:10.5px"><?= h($t['briefing']['angulo_principal']) ?></div>
                <div class="roi-value">R$ <?= $arbT['rpm_ajustado'] ?>/mil · ROI <strong><?= $roiT ?></strong>/10</div>
              </td>
              <td class="muted">
                <?php if ($t['noticias_qtd'] > 0): ?>
                  <strong style="color:#93c5fd"><?= $t['noticias_qtd'] ?></strong> artigos
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td style="white-space:nowrap">
                <button type="button" class="btn-q" data-termo="<?= h($t['termo']) ?>" data-hours="<?= $hours ?>" title="Consultas TOP/RISING">🔍</button>
                <button type="button" class="btn-n" data-termo="<?= h($t['termo']) ?>" data-idx="<?= $i ?>" title="Notícias reais (Google News + Serper)">📰</button>
                <button type="button" class="btn-b" data-idx="<?= $i ?>" title="Ver briefing editorial">📝</button>
                <?php $jaSalvo = isset($termosSalvos[mb_strtolower($t['termo'], 'UTF-8')]); ?>
                <button type="button"
                        class="btn-save-row <?= $jaSalvo ? 'saved' : '' ?>"
                        data-trend="<?= h(json_encode($t, JSON_UNESCAPED_UNICODE)) ?>"
                        data-origem="<?= $hours ?>h"
                        <?= $jaSalvo ? 'disabled' : '' ?>
                        title="<?= $jaSalvo ? 'Já salvo no banco' : 'Salvar este trend no banco (ignora threshold)' ?>">
                  <?= $jaSalvo ? '✓' : '💾' ?>
                </button>
                <button type="button" class="btn-g" data-termo="<?= h($t['termo']) ?>" data-idx="<?= $i ?>" title="Gerar e publicar como rascunho no WP">✍️</button>
              </td>
            </tr>
            <tr class="q-row" id="q-row-<?= $i ?>" data-for="<?= h($t['termo']) ?>" style="display:none">
              <td colspan="9" class="q-cell"><div class="q-slot">Carregando…</div></td>
            </tr>
            <tr class="n-row" id="n-row-<?= $i ?>" style="display:none">
              <td colspan="9" class="q-cell"><div class="n-slot">Carregando…</div></td>
            </tr>
            <tr class="g-row" id="g-row-<?= $i ?>" style="display:none">
              <td colspan="9" class="q-cell"><div class="g-slot">…</div></td>
            </tr>
            <tr class="b-row" id="b-row-<?= $i ?>" style="display:none">
              <td colspan="9" class="q-cell">
                <?php $b = $t['briefing']; ?>
                <div class="briefing">
                  <div class="brf-head">
                    <div>
                      <span class="brf-pill brf-pill-g">GRUPO: <?= h($b['grupo_editorial']) ?></span>
                      <span class="brf-pill brf-pill-a">ÂNGULO: <?= h($b['angulo_principal']) ?></span>
                      <span class="brf-pill brf-pill-u">UNIVERSAL: <?= h($b['angulo_universal']) ?></span>
                      <span class="brf-pill brf-pill-i">INTENÇÃO: <?= h($b['intencao']) ?></span>
                    </div>
                  </div>
                  <div class="brf-grid">
                    <div>
                      <div class="brf-label">📌 Título sugerido</div>
                      <div class="brf-val"><?= h($b['titulo_sugerido']) ?></div>
                    </div>
                    <div>
                      <div class="brf-label">🎯 Gancho do P1</div>
                      <div class="brf-val"><?= h($b['gancho_p1']) ?></div>
                    </div>
                  </div>
                  <div class="brf-grid">
                    <div>
                      <div class="brf-label">🧱 Subtítulos (H3) sugeridos</div>
                      <ul class="brf-list">
                        <?php foreach ($b['h3_sugeridos'] as $h3): ?><li><?= h($h3) ?></li><?php endforeach; ?>
                      </ul>
                    </div>
                    <div>
                      <div class="brf-label">❓ FAQ sugerido</div>
                      <ul class="brf-list">
                        <?php foreach ($b['faq_sugerido'] as $q): ?><li><?= h($q) ?></li><?php endforeach; ?>
                      </ul>
                    </div>
                  </div>
                  <div>
                    <div class="brf-label">🔑 Palavras-chave</div>
                    <div class="brf-kws">
                      <?php foreach ($b['palavras_chave'] as $kw): ?><span class="brf-kw"><?= h($kw) ?></span><?php endforeach; ?>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($debug && $scraper): ?>
    <details open>
      <summary>🛠 Debug — resposta bruta do batchexecute</summary>
      <p class="muted" style="margin:6px 0">Endpoint: <?= h($scraper->lastEndpoint) ?></p>
      <p class="muted" style="margin:6px 0">HTTP: <?= $scraper->lastHttpCode ?> · Tamanho: <?= is_string($scraper->lastRawResponse) ? strlen($scraper->lastRawResponse) : 0 ?> bytes</p>
      <p class="muted" style="margin:6px 0">f.req enviado:</p>
      <pre><?= h($scraper->lastRpcPayload ?? '') ?></pre>
      <p class="muted" style="margin:6px 0">Resposta crua (primeiros 5000 bytes):</p>
      <pre><?= h(mb_substr((string)$scraper->lastRawResponse, 0, 5000)) ?></pre>
    </details>
  <?php endif; ?>

  <?php $temResultado = (!empty($trends) || $historico !== null); ?>
  <?php if ($modo !== 'calendario' && !$buscou && !$temResultado): ?>
    <div class="box" style="text-align:center;color:#666">
      <?php if ($modo === 'atual'): ?>
        Escolha a janela e clique em <strong>Buscar trends</strong> para validar a coleta.
      <?php else: ?>
        Defina termo-semente + período ou use um atalho sazonal e clique em <strong>Explorar</strong>.
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script>
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-q');
  if (!btn) return;
  ev.preventDefault();

  const tr = btn.closest('tr');
  const qRow = tr.nextElementSibling;
  if (!qRow || !qRow.classList.contains('q-row')) return;
  const slot = qRow.querySelector('.q-slot');

  // Toggle se já está aberto
  if (qRow.style.display !== 'none' && btn.classList.contains('open')) {
    qRow.style.display = 'none';
    btn.classList.remove('open');
    btn.textContent = '🔍 Ver';
    return;
  }

  qRow.style.display = 'table-row';
  btn.classList.add('open');
  btn.textContent = '✕ Fechar';

  // Se já carregou, não recarrega
  if (slot.dataset.loaded === '1') return;
  slot.innerHTML = '<div style="color:#888;font-size:13px">Buscando consultas no Google Trends…</div>';

  const termo = btn.dataset.termo;
  const hours = btn.dataset.hours;
  try {
    const res = await fetch(`portal.php?ajax=queries&termo=${encodeURIComponent(termo)}&hours=${hours}`);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Erro desconhecido');
    slot.innerHTML = renderQueries(data.data);
    slot.dataset.loaded = '1';
  } catch (e) {
    slot.innerHTML = `<div class="q-err">⚠️ ${e.message}</div>`;
  }
});

function renderQueries(d) {
  const top = (d.top || []).map(q => `
    <li><span class="q-term">${escapeHtml(q.query)}</span><span class="q-val">${escapeHtml(q.formatted || q.value)}</span></li>
  `).join('') || '<li style="color:#555">Sem dados</li>';
  const rising = (d.rising || []).map(q => `
    <li><span class="q-term">${escapeHtml(q.query)}</span><span class="q-val rising">${escapeHtml(q.formatted || q.value)}</span></li>
  `).join('') || '<li style="color:#555">Sem dados</li>';
  return `
    <div class="q-col">
      <h4>📊 Consultas mais frequentes</h4>
      <ul class="q-list">${top}</ul>
    </div>
    <div class="q-col">
      <h4 class="rising">🔥 Consultas em alta</h4>
      <ul class="q-list">${rising}</ul>
    </div>
  `;
}
function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// Toggle briefing editorial
document.addEventListener('click', (ev) => {
  const btn = ev.target.closest('.btn-b');
  if (!btn) return;
  ev.preventDefault();
  const idx = btn.dataset.idx;
  const row = document.getElementById('b-row-' + idx);
  if (!row) return;
  const open = row.style.display !== 'none';
  row.style.display = open ? 'none' : 'table-row';
  btn.classList.toggle('open', !open);
  btn.textContent = open ? '📝' : '✕';
});

// ═══ SALVAR 1 TREND INDIVIDUAL (botão 💾 por linha) ═══
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-save-row');
  if (!btn || btn.disabled || btn.classList.contains('saved')) return;
  ev.preventDefault();
  const trendJson = btn.dataset.trend;
  const origem = btn.dataset.origem || '168h';
  if (!trendJson) return;
  btn.classList.add('running');
  btn.textContent = '…';
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('trend_json', trendJson);
    fd.append('origem', origem);
    const r = await fetch('portal.php?ajax=salvar_unico', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'erro');
    btn.classList.remove('running');
    btn.classList.add('saved');
    btn.textContent = '✓';
    btn.title = 'Salvo (ID ' + d.id + ')';
  } catch (e) {
    btn.classList.remove('running');
    btn.disabled = false;
    btn.textContent = '💾';
    alert('Erro ao salvar: ' + (e.message || e));
  }
});

// ═══ PROGRESSO GRANULAR DA GERAÇÃO (polling a cada 1.5s + heartbeat no cliente) ═══
// Heartbeat: cliente guarda quando viu cada step pela primeira vez e calcula tempo REAL
// decorrido — resolve o "congelamento" visual enquanto Claude processa sem atualizar o arquivo.
window._progressoStepStart = window._progressoStepStart || {};

async function atualizarProgressosGerais() {
  const slots = document.querySelectorAll('.gen-progress-live[data-trend-id]');
  if (!slots.length) return;
  const ids = Array.from(new Set(Array.from(slots).map(s => s.dataset.trendId)));
  for (const id of ids) {
    try {
      const r = await fetch('portal.php?ajax=progresso&id=' + id);
      const d = await r.json();
      if (!d.ok || !d.progresso) continue;
      const p = d.progresso;
      const pct = Math.max(3, Math.round((p.step_idx / p.step_total) * 100));
      const cor = p.step === 'erro' ? '#ef4444' : (p.step === 'concluido' ? '#22c55e' : '#3b82f6');

      // Rastreia primeira vez que vimos o step atual
      const stepKey = id + ':' + p.step;
      if (!window._progressoStepStart[stepKey]) {
        window._progressoStepStart[stepKey] = Date.now();
      }
      const segAtual = Math.round((Date.now() - window._progressoStepStart[stepKey]) / 1000);
      const segTotal = Math.round((p.elapsed_ms || 0) / 1000) + segAtual;

      // Warning quando passar de 180s no mesmo step (provável travamento)
      const travado = segAtual >= 180 && !['concluido','erro'].includes(p.step);
      const corSeg = travado ? '#f59e0b' : cor;
      const aviso  = travado ? ' ⚠️' : '';

      const html = `
        <div style="background:#1a1d23;border-radius:4px;overflow:hidden;height:6px;margin-bottom:4px">
          <div style="background:${cor};height:100%;width:${pct}%;transition:width .3s"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:#9ca3af">
          <span><strong style="color:${cor}">${escapeHtml(p.label)}</strong> ${p.detail ? '— ' + escapeHtml(p.detail) : ''}</span>
          <span class="muted">${segTotal}s · neste step: <strong style="color:${corSeg}">${segAtual}s${aviso}</strong> · ${p.step_idx}/${p.step_total}</span>
        </div>
      `;
      document.querySelectorAll(`.gen-progress-live[data-trend-id="${id}"]`).forEach(el => el.innerHTML = html);

      // Cleanup quando concluir
      if (p.step === 'concluido' || p.step === 'erro') {
        Object.keys(window._progressoStepStart).forEach(k => {
          if (k.startsWith(id + ':')) delete window._progressoStepStart[k];
        });
      }
    } catch (e) { /* ignora — próximo tick tenta de novo */ }
  }
}
// Loop contínuo enquanto houver items running na página
setInterval(() => atualizarProgressosGerais(), 1500);

// ═══ FILA DE GERAÇÃO EM LOTE ═══
(function() {
  const selAll   = document.getElementById('select-all-aprovados');
  const selNone  = document.getElementById('clear-aprovados');
  const startBtn = document.getElementById('start-batch');
  const countEl  = document.getElementById('sel-count');
  const panel    = document.getElementById('batch-progress');
  if (!selAll || !startBtn) return;

  const getChecked = () => Array.from(document.querySelectorAll('.batch-check:checked')).map(x => x.value);
  const updateCount = () => {
    const n = getChecked().length;
    countEl.textContent = n;
    startBtn.disabled = n === 0;
  };
  document.addEventListener('change', e => { if (e.target.classList.contains('batch-check')) updateCount(); });
  selAll.onclick  = () => { document.querySelectorAll('.batch-check').forEach(c => c.checked = true);  updateCount(); };
  selNone.onclick = () => { document.querySelectorAll('.batch-check').forEach(c => c.checked = false); updateCount(); };

  let stopTicking = false;
  let interlinksDone = {}; // acumula interlinks reportados por cada tick
  let finalInterlinks = []; // reportados no terminou=true

  async function iniciarFila() {
    const ids = getChecked();
    if (!ids.length) return;
    if (!confirm(`Gerar ${ids.length} artigos em lote?\n\nCada item leva ~60-120s. Tempo estimado total: ~${Math.round(ids.length * 100 / 60)} min.\nVocê pode fechar/reabrir a aba — a fila resume automaticamente.\n\nContinuar?`)) return;

    startBtn.disabled = true;
    panel.style.display = 'block';
    panel.innerHTML = '<div class="muted">Criando fila…</div>';

    try {
      const fd = new FormData();
      fd.append('ids', JSON.stringify(ids));
      fd.append('formato', 'discover');
      const r = await fetch('portal.php?ajax=fila_iniciar&site=<?= urlencode($siteSlug) ?>', { method: 'POST', body: fd });
      const d = await r.json();
      if (!d.ok) throw new Error(d.erro || 'Erro ao criar fila');
      stopTicking = false;
      renderPainel(null);
      tickLoop();
    } catch (e) {
      panel.innerHTML = `<div class="gen-result fail">❌ ${escapeHtml(e.message)}</div>`;
      startBtn.disabled = false;
    }
  }

  startBtn.onclick = iniciarFila;

  async function tickLoop() {
    // Status inicial
    await atualizarStatus();
    while (!stopTicking) {
      let r;
      try {
        const resp = await fetch('portal.php?ajax=fila_tick&site=<?= urlencode($siteSlug) ?>');
        r = await resp.json();
      } catch (e) {
        await new Promise(s => setTimeout(s, 5000));
        continue;
      }
      if (!r.ok) {
        console.error('tick erro', r);
        break;
      }
      if (r.terminou) {
        finalInterlinks = r.auto_interlinks || [];
        await atualizarStatus();
        break;
      }
      // Registra interlink progressivo se houver
      if (r.interlink && r.interlink.evento) {
        interlinksDone[r.interlink.evento] = r.interlink;
      }
      await atualizarStatus();
      // pequena pausa entre items pra dar respiro
      await new Promise(s => setTimeout(s, 2000));
    }
  }

  async function atualizarStatus() {
    try {
      const resp = await fetch('portal.php?ajax=fila_status&site=<?= urlencode($siteSlug) ?>');
      const d = await resp.json();
      renderPainel(d);
    } catch (e) { /* ignora */ }
  }

  function renderPainel(st) {
    if (!st || !st.existe) {
      panel.innerHTML = '<div class="muted">Nenhuma fila ativa.</div>';
      return;
    }
    const c = st.counts;
    const pct = st.total > 0 ? Math.round(((c.done + c.failed + c.canceled) / st.total) * 100) : 0;
    const terminou = (c.pending === 0 && c.running === 0);
    const headerColor = st.cancelado ? '#dc2626' : (terminou ? '#22c55e' : '#f59e0b');

    // Resumo dos interlinks aplicados (progressivo + final)
    const interlinksAll = Object.assign({}, interlinksDone);
    for (const fi of finalInterlinks) {
      if (fi && fi.evento && !fi.erro) {
        interlinksAll[fi.evento] = fi;
      }
    }
    let interlinkHtml = '';
    const evs = Object.keys(interlinksAll);
    if (evs.length > 0) {
      interlinkHtml = `<div style="margin-top:6px;padding:6px 10px;background:#0c4a6e;border-radius:4px;font-size:11px;color:#7dd3fc">
        🔗 Cluster interlink aplicado: ${evs.map(e => `<strong>${escapeHtml(e)}</strong> (${interlinksAll[e].atualizados}/${interlinksAll[e].total})`).join(' · ')}
      </div>`;
    }

    let html = `
      <div style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <strong style="color:${headerColor}">
            ${terminou ? '✅' : '⏳'} Fila ${st.batch_id} · ${c.done}/${st.total} concluídos${c.failed ? ` · ${c.failed} falhas` : ''}
          </strong>
          <div>
            ${!terminou && !st.cancelado ? '<button type="button" id="cancel-batch" class="btn-b" style="margin:0">✕ Cancelar</button>' : ''}
            ${terminou ? '<button type="button" id="clear-batch" class="btn-n" style="margin:0">🗑 Limpar fila</button>' : ''}
          </div>
        </div>
        <div style="background:#1a1d23;height:8px;border-radius:4px;margin-top:8px;overflow:hidden">
          <div style="background:linear-gradient(90deg,#22c55e,#16a34a);height:100%;width:${pct}%;transition:width .3s"></div>
        </div>
        ${interlinkHtml}
      </div>
      <div style="display:grid;gap:5px;max-height:400px;overflow-y:auto">
    `;
    for (const it of st.items) {
      const icon = {pending:'⏸',running:'⏳',done:'✅',failed:'❌',canceled:'⊘'}[it.status] || '?';
      const color = {pending:'#6b7280',running:'#f59e0b',done:'#4ade80',failed:'#fca5a5',canceled:'#78716c'}[it.status] || '#fff';
      const extra = it.status === 'done' && it.edit_url
        ? `<a href="${escapeHtml(it.edit_url)}" target="_blank" style="color:#93c5fd;font-size:11px">✏️ editar</a>`
        : (it.status === 'failed' ? `<span style="color:#fca5a5;font-size:11px">${escapeHtml(it.erro || '')}</span>` : '');
      const audFlag = it.auditoria_ok === false ? '<span style="color:#fca5a5;margin-left:6px" title="Auditor detectou nomes suspeitos">⚠️</span>' : '';
      const isRunning = it.status === 'running';
      const progressSlot = isRunning ? `<div class="gen-progress-live" data-trend-id="${it.trend_id}" style="margin-top:6px"></div>` : '';
      html += `
        <div style="padding:6px 10px;background:#0f1115;border-radius:4px;border-left:3px solid ${color};font-size:12px">
          <div style="display:flex;justify-content:space-between;gap:10px">
            <div style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <span style="color:${color}">${icon}</span>
              <strong style="color:#e5e7eb">${escapeHtml(it.termo)}</strong>${audFlag}
              ${it.titulo ? `<div class="muted" style="font-size:11px;margin-top:2px">${escapeHtml(it.titulo)}</div>` : ''}
            </div>
            <div style="text-align:right;white-space:nowrap">
              ${it.tempo_s ? `<span class="muted" style="font-size:10px">${it.tempo_s}s</span> ` : ''}
              ${extra}
            </div>
          </div>
          ${progressSlot}
        </div>
      `;
    }
    html += '</div>';
    panel.innerHTML = html;

    // Inicia polling de progresso pros items running
    atualizarProgressosGerais();

    // bind cancel/clear
    const cancelBtn = document.getElementById('cancel-batch');
    const clearBtn  = document.getElementById('clear-batch');
    if (cancelBtn) cancelBtn.onclick = async () => {
      if (!confirm('Cancelar a fila? Items em andamento não são interrompidos.')) return;
      stopTicking = true;
      await fetch('portal.php?ajax=fila_cancelar&site=<?= urlencode($siteSlug) ?>');
      await atualizarStatus();
    };
    if (clearBtn) clearBtn.onclick = async () => {
      if (!confirm('Limpar histórico da fila?')) return;
      await fetch('portal.php?ajax=fila_limpar&site=<?= urlencode($siteSlug) ?>');
      panel.style.display = 'none';
      panel.innerHTML = '';
      startBtn.disabled = false;
    };
  }

  // Ao carregar, verifica se há fila em andamento (pra retomar)
  (async () => {
    const resp = await fetch('portal.php?ajax=fila_status&site=<?= urlencode($siteSlug) ?>');
    const d = await resp.json();
    if (d.existe) {
      panel.style.display = 'block';
      renderPainel(d);
      if (d.counts.pending > 0 && !d.cancelado) {
        // tem pendente → retoma
        tickLoop();
      }
    }
  })();
})();

// Atualizar post (Etapa 10)
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-u');
  if (!btn) return;
  ev.preventDefault();
  if (btn.classList.contains('running')) return;
  if (!confirm('Atualizar este post?\n\nIsso vai: 1) puxar notícias frescas, 2) saneamento de termos temporais, 3) adicionar seção "O que mudou recentemente", 4) atualizar no WP.\n\nTempo estimado: 45-90s.')) return;

  const id = btn.dataset.id;
  const row = document.getElementById('u-row-' + id);
  const slot = row.querySelector('.u-slot');
  row.style.display = 'table-row';
  btn.classList.add('running');
  const orig = btn.textContent;
  btn.textContent = '⏳';

  slot.innerHTML = `
    <div class="gen-progress">
      <strong>Atualizando post #${id}</strong>
      <span class="gen-step active">▸ 1/4 Lendo post atual no WP…</span>
      <span class="gen-step">▸ 2/4 Buscando notícias frescas (mín 2)</span>
      <span class="gen-step">▸ 3/4 Claude: saneando + adicionando "O que mudou recentemente"</span>
      <span class="gen-step">▸ 4/4 Salvando no WP</span>
    </div>
  `;

  try {
    const res = await fetch(`portal.php?ajax=atualizar&id=${id}`);
    const d = await res.json();
    if (!d.ok) throw new Error(d.erro || 'Erro desconhecido');
    btn.classList.remove('running');
    btn.classList.add('done');
    btn.textContent = '✓';
    slot.innerHTML = `
      <div class="gen-result">
        ✅ <strong>Post #${d.post_id}</strong> atualizado em ${Math.round(d.tempo_ms/1000)}s<br>
        <span style="color:#cbd5e1">${escapeHtml(d.titulo || '')}</span><br>
        <span style="color:#888;font-size:11px">Fontes frescas: ${d.fontes} · tamanho: ${d.tamanho_antes} → ${d.tamanho_depois} chars</span>
      </div>
    `;
  } catch (e) {
    btn.classList.remove('running');
    btn.classList.add('failed');
    btn.textContent = '✗';
    slot.innerHTML = `<div class="gen-result fail">❌ ${escapeHtml(e.message)}</div>`;
  }
});

// Gerar artigo (Etapa 6-9: pipeline completo)
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-g');
  if (!btn) return;
  ev.preventDefault();
  if (btn.classList.contains('running')) return;
  if (!confirm('Gerar e publicar como RASCUNHO no WP selecionado?\n\nIsso vai: 1) buscar artigos reais, 2) scrape das fontes, 3) chamar Claude, 4) criar draft no WP.\n\nTempo estimado: 30-90s.')) return;

  const idx = btn.dataset.idx;
  const termo = btn.dataset.termo;
  const row = document.getElementById('g-row-' + idx);
  const slot = row.querySelector('.g-slot');
  row.style.display = 'table-row';
  btn.classList.add('running');
  btn.textContent = '⏳';

  slot.innerHTML = `
    <div class="gen-progress">
      <strong>Gerando artigo para: ${escapeHtml(termo)}</strong>
      <span class="gen-step active" id="gs-1-${idx}">▸ 1/4 Buscando notícias (Google News + Serper)…</span>
      <span class="gen-step" id="gs-2-${idx}">▸ 2/4 Fazendo scrape das fontes (mín 2 com 500+ chars)</span>
      <span class="gen-step" id="gs-3-${idx}">▸ 3/4 Gerando artigo com Claude</span>
      <span class="gen-step" id="gs-4-${idx}">▸ 4/4 Publicando como rascunho no WP</span>
    </div>
  `;

  try {
    const res = await fetch(`portal.php?ajax=gerar&termo=${encodeURIComponent(termo)}&formato=discover`);
    const d = await res.json();
    if (!d.ok) throw new Error(d.erro || 'Erro desconhecido');

    btn.classList.remove('running');
    btn.classList.add('done');
    btn.textContent = '✓';
    slot.innerHTML = `
      <div class="gen-result">
        ✅ <strong>Post #${d.post_id}</strong> criado como rascunho em ${Math.round(d.tempo_ms/1000)}s<br>
        <span style="color:#cbd5e1">${escapeHtml(d.titulo || '')}</span><br>
        <span style="color:#888;font-size:11px">Fontes usadas: ${d.fontes_usadas}/${d.fontes_tentadas || d.fontes_usadas}</span><br>
        ${d.edit_url ? `<a href="${escapeHtml(d.edit_url)}" target="_blank">→ Abrir no WP para revisar</a>` : ''}
      </div>
    `;
  } catch (e) {
    btn.classList.remove('running');
    btn.classList.add('failed');
    btn.textContent = '✗';
    slot.innerHTML = `<div class="gen-result fail">❌ ${escapeHtml(e.message)}</div>`;
  }
});

// Toggle + carregar notícias reais
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-n');
  if (!btn) return;
  ev.preventDefault();
  const idx = btn.dataset.idx;
  const termo = btn.dataset.termo;
  const row = document.getElementById('n-row-' + idx);
  if (!row) return;
  const open = row.style.display !== 'none';
  if (open) {
    row.style.display = 'none';
    btn.classList.remove('open');
    btn.textContent = '📰';
    return;
  }
  row.style.display = 'table-row';
  btn.classList.add('open');
  btn.textContent = '✕';
  const slot = row.querySelector('.n-slot');
  if (slot.dataset.loaded === '1') return;
  slot.innerHTML = '<div style="color:#888;font-size:13px">Buscando notícias no Google News + resolvendo URLs via Serper…</div>';
  try {
    const res = await fetch(`portal.php?ajax=noticias&termo=${encodeURIComponent(termo)}&max=5`);
    const d = await res.json();
    if (!d.ok) throw new Error(d.error || 'Erro');
    const html = d.data.map(a => `
      <div class="news-card">
        ${a.url_real
          ? `<a href="${escapeHtml(a.url_real)}" target="_blank" rel="noopener">${escapeHtml(a.title)}</a>`
          : `<span style="color:#e5e7eb">${escapeHtml(a.title)}</span><span class="news-warn">⚠ URL não resolveu</span>`}
        <div class="news-meta"><span class="news-source">${escapeHtml(a.source || '?')}</span>${escapeHtml(a.pubDate || '')}</div>
        ${a.description ? `<div class="news-desc">${escapeHtml(a.description.substring(0, 220))}${a.description.length > 220 ? '…' : ''}</div>` : ''}
      </div>
    `).join('');
    slot.innerHTML = `
      <div style="margin-bottom:8px;font-size:12px;color:#888">
        ${d.total} artigos · ${d.resolvidos} com URL resolvida
      </div>
      ${html || '<div class="muted">Nenhuma notícia encontrada.</div>'}
    `;
    slot.dataset.loaded = '1';
  } catch (e) {
    slot.innerHTML = `<div class="q-err">⚠️ ${e.message}</div>`;
  }
});

// Mover cluster pra outro site (recupera quando salvou no site errado)
document.addEventListener('change', async (ev) => {
  const sel = ev.target;
  if (!sel.classList.contains('select-mover')) return;
  if (!sel.value) return;
  const evento = sel.dataset.evento;
  const from   = sel.dataset.from;
  const to     = sel.value;
  if (!confirm(`Mover cluster "${evento}" do site "${from}" pra "${to}"?\n\nOs registros mudam de site na DB. Posts já gerados no WP atual NÃO mudam — só os trends do DB. Você precisa regerar no novo site.`)) {
    sel.value = '';
    return;
  }
  sel.disabled = true;
  try {
    const fd = new FormData();
    fd.append('from', from);
    fd.append('to', to);
    fd.append('evento', evento);
    const r = await fetch('portal.php?ajax=migrar_site', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Erro');
    alert(`✓ ${d.movidos} movidos${d.colisoes ? ` · ${d.colisoes} colisões (já existiam no destino)` : ''}`);
    location.reload();
  } catch (e) {
    alert('❌ ' + e.message);
    sel.disabled = false;
    sel.value = '';
  }
});

// Revisar post via Claude — otimiza + entrega alternativas
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-revisar');
  if (!btn) return;
  ev.preventDefault();
  if (btn.disabled) return;
  if (!confirm('Revisar este post via Claude?\n\nVai: (1) otimizar intro/corpo/estrutura, (2) preservar cluster interlink + schemas + cards, (3) entregar 5 títulos + 3 aberturas + 5 frases de impacto alternativas.\n\nCusta API. Tempo ~60-90s.')) return;

  const id = btn.dataset.id;
  const row = document.getElementById('u-row-' + id);
  const slot = row ? row.querySelector('.u-slot') : null;
  if (row) row.style.display = 'table-row';
  const orig = btn.textContent;
  btn.classList.add('running');
  btn.textContent = '⏳';
  if (slot) slot.innerHTML = '<div class="gen-progress"><strong>Revisando post via Claude…</strong><span class="gen-step active">▸ Enviando pro Claude (aguarde ~60s)</span></div>';

  try {
    const fd = new FormData();
    fd.append('id', id);
    const r = await fetch('portal.php?ajax=revisar_post', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Erro');

    btn.classList.remove('running');
    btn.classList.add('done');
    btn.textContent = '✓';

    const alt = d.alternativas || {};
    const tituloMudou = d.titulo_antes !== d.titulo_depois;
    let html = `<div class="gen-result">
      <div style="margin-bottom:10px"><strong>✅ Post #${d.post_id} revisado em ${Math.round(d.tempo_ms/1000)}s</strong>`;
    if (d.quality_score) html += ` · <span style="background:#1e3a8a;color:#93c5fd;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">Qualidade: ${d.quality_score}/10</span>`;
    html += `</div>`;

    if (tituloMudou) {
      html += `<div style="margin:10px 0;padding:8px 10px;background:#0b0d11;border-left:3px solid #fbbf24;border-radius:4px">
        <div class="muted" style="font-size:11px">Título antigo:</div>
        <div style="font-size:13px;color:#9ca3af;text-decoration:line-through">${escapeHtml(d.titulo_antes)}</div>
        <div class="muted" style="font-size:11px;margin-top:4px">Título novo:</div>
        <div style="font-size:13px;color:#4ade80;font-weight:600">${escapeHtml(d.titulo_depois)}</div>
      </div>`;
    }
    html += `<div style="font-size:11px;color:#888;margin-bottom:10px">Tamanho: ${d.chars_antes} → ${d.chars_depois} chars`;
    if (d.blocos_perdidos_recuperados && d.blocos_perdidos_recuperados.length) {
      html += ` · <span style="color:#fbbf24">${d.blocos_perdidos_recuperados.length} bloco(s) recuperado(s)</span>`;
    }
    html += `</div>`;

    if (d.meta_description) html += `<div style="margin:8px 0;padding:6px 10px;background:#0b0d11;border-radius:4px;font-size:12px"><strong style="color:#a78bfa">Meta Description:</strong> ${escapeHtml(d.meta_description)}</div>`;
    if (d.slug)             html += `<div style="margin:8px 0;padding:6px 10px;background:#0b0d11;border-radius:4px;font-size:12px"><strong style="color:#a78bfa">Slug sugerido:</strong> <code>${escapeHtml(d.slug)}</code></div>`;

    if (alt.titulos && alt.titulos.length) {
      html += `<div style="margin-top:14px"><strong style="color:#a78bfa">🔥 5 títulos alternativos (escolha pra testar CTR):</strong><ol style="margin:6px 0 0;padding-left:22px;font-size:12px;color:#cbd5e1">${
        alt.titulos.map(t => `<li style="padding:3px 0">${escapeHtml(t)}</li>`).join('')
      }</ol></div>`;
    }
    if (alt.aberturas && alt.aberturas.length) {
      html += `<div style="margin-top:14px"><strong style="color:#a78bfa">📱 3 aberturas alternativas pro Discover:</strong><ol style="margin:6px 0 0;padding-left:22px;font-size:12px;color:#cbd5e1">${
        alt.aberturas.map(a => `<li style="padding:3px 0">${escapeHtml(a)}</li>`).join('')
      }</ol></div>`;
    }
    if (alt.frases_impacto && alt.frases_impacto.length) {
      html += `<div style="margin-top:14px"><strong style="color:#a78bfa">💥 5 frases de impacto pra retenção:</strong><ul style="margin:6px 0 0;padding-left:22px;font-size:12px;color:#cbd5e1;list-style:none">${
        alt.frases_impacto.map(f => `<li style="padding:3px 0">→ ${escapeHtml(f)}</li>`).join('')
      }</ul></div>`;
    }

    html += `</div>`;
    if (slot) slot.innerHTML = html;
  } catch (e) {
    btn.classList.remove('running');
    btn.classList.add('failed');
    btn.textContent = '✗';
    if (slot) slot.innerHTML = `<div class="gen-result fail">❌ ${escapeHtml(e.message)}</div>`;
  }
});

// ═══ FILTROS COMBINADOS em Ver Salvos (busca + cluster + score + ROI) ═══
(function() {
  const inputBusca = document.getElementById('busca-salvos');
  const selCluster = document.getElementById('filtro-cluster');
  const rngScore   = document.getElementById('filtro-score-min');
  const rngRoi     = document.getElementById('filtro-roi-min');
  const lblScore   = document.getElementById('filtro-score-min-val');
  const lblRoi     = document.getElementById('filtro-roi-min-val');
  const btnReset   = document.getElementById('filtro-reset');
  const contador   = document.getElementById('filtro-contador');
  if (!inputBusca) return;

  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  const normalize = s => s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

  function aplicar() {
    const q        = normalize(inputBusca.value.trim());
    const cluster  = selCluster ? selCluster.value : '';
    const scoreMin = rngScore ? parseFloat(rngScore.value) : 0;
    const roiMin   = rngRoi ? parseFloat(rngRoi.value) : 0;
    const rows = document.querySelectorAll('tr.tr-salvo');
    let matches = 0, total = rows.length;
    rows.forEach(tr => {
      const hay = normalize(tr.dataset.search || '');
      const trCluster = tr.dataset.cluster || '';
      const trScore = parseFloat(tr.dataset.score || '0');
      const trRoi = parseFloat(tr.dataset.roi || '0');
      let hidden = false;
      if (q !== '' && !hay.includes(q)) hidden = true;
      if (cluster !== '' && trCluster !== cluster) hidden = true;
      if (scoreMin > 0 && trScore < scoreMin) hidden = true;
      if (roiMin > 0 && trRoi < roiMin) hidden = true;
      tr.style.display = hidden ? 'none' : '';
      if (!hidden) matches++;
      const urow = document.getElementById('u-row-' + tr.dataset.batchId);
      if (urow) urow.style.display = hidden ? 'none' : (urow.dataset.wasOpen === '1' ? 'table-row' : 'none');
    });
    if (contador) {
      contador.textContent = matches === total
        ? `mostrando todos os ${total}`
        : `mostrando ${matches} de ${total}`;
      contador.style.color = matches < total ? '#fbbf24' : '#6b7280';
    }
  }

  const aplicarDebounced = debounce(aplicar, 80);

  inputBusca.addEventListener('input', aplicarDebounced);
  if (selCluster) selCluster.addEventListener('change', aplicar);
  if (rngScore) rngScore.addEventListener('input', () => {
    if (lblScore) lblScore.textContent = parseFloat(rngScore.value).toFixed(1);
    aplicarDebounced();
  });
  if (rngRoi) rngRoi.addEventListener('input', () => {
    if (lblRoi) lblRoi.textContent = parseFloat(rngRoi.value).toFixed(1);
    aplicarDebounced();
  });
  if (btnReset) btnReset.addEventListener('click', () => {
    inputBusca.value = '';
    if (selCluster) selCluster.value = '';
    if (rngScore) { rngScore.value = 0; if (lblScore) lblScore.textContent = '0'; }
    if (rngRoi) { rngRoi.value = 0; if (lblRoi) lblRoi.textContent = '0'; }
    aplicar();
  });
  aplicar(); // initial
})();

// ═══ "▶ Gerar N pendentes" — botão principal do header ═══
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('#gerar-tudo-pendente');
  if (!btn) return;
  ev.preventDefault();
  if (btn.disabled) return;
  let ids = [];
  try { ids = JSON.parse(btn.dataset.ids || '[]'); } catch (e) {}
  if (!ids.length) return;
  const min = Math.round(ids.length * 100 / 60);
  if (!confirm(`Gerar ${ids.length} artigos pendentes em lote?\n\nTempo estimado: ~${min} min (pode acompanhar progresso).\n\nUsa o LLM ativo no toggle. Fecha essa janela se quiser — a fila continua.`)) return;
  btn.disabled = true;
  const origText = btn.textContent;
  btn.textContent = '⏳ Criando fila...';
  try {
    const fd = new FormData();
    fd.append('ids', JSON.stringify(ids));
    fd.append('formato', 'discover');
    const r = await fetch('portal.php?ajax=fila_iniciar&site=<?= urlencode($siteSlug) ?>', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Erro');
    btn.textContent = `✓ ${d.total} na fila`;
    // Scroll pro painel de progresso e recarrega pra UI assumir
    setTimeout(() => { location.reload(); }, 600);
  } catch (e) {
    btn.textContent = '❌ ' + e.message;
    btn.disabled = false;
    setTimeout(() => { btn.textContent = origText; btn.disabled = false; }, 4000);
  }
});

// Avaliar qualidade de todos os posts publicados do site
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('#avaliar-todos');
  if (!btn) return;
  ev.preventDefault();
  if (btn.disabled) return;
  if (!confirm('Avaliar a qualidade de TODOS os posts publicados deste site?\n\nRoda o DiscoverQualityScore em cada um (~2s/post). Sem chamar IA. Depois mostra o score em cada linha.')) return;
  btn.textContent = '⏳ Avaliando...';
  btn.disabled = true;
  try {
    const r = await fetch('portal.php?ajax=avaliar_qualidade&site=<?= urlencode($siteSlug) ?>', { method: 'POST' });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Erro');
    btn.textContent = `✓ ${d.avaliados} avaliados`;
    setTimeout(() => location.reload(), 1500);
  } catch (e) {
    btn.textContent = '❌ ' + e.message;
    btn.disabled = false;
  }
});

// Reformatar: aplica DiscoverPostProcess em posts existentes (sem chamar Claude)
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-reformatar');
  if (!btn) return;
  ev.preventDefault();
  if (btn.disabled) return;
  let ids = [];
  try { ids = JSON.parse(btn.dataset.ids || '[]'); } catch (e) {}
  if (!ids.length) return;
  if (!confirm(`Reformatar ${ids.length} post(s)?\n\nVai aplicar: cards de mensagem, auto-links telefone/WhatsApp, schemas FAQPage/HowTo/ItemList, limpeza de Article. Sem chamar IA. ~${ids.length * 2}s.`)) return;
  const orig = btn.textContent;
  btn.textContent = '⏳...';
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('ids', JSON.stringify(ids));
    const r = await fetch('portal.php?ajax=reprocessar&site=<?= urlencode($siteSlug) ?>', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Erro');
    var info = `✓ ${d.processados}/${d.total}`;
    if (d.interlinks && d.interlinks.length) {
      var ok = d.interlinks.filter(x => !x.erro).reduce((s,x) => s + (x.atualizados||0), 0);
      if (ok > 0) info += ` · 🔗 ${ok} interligados`;
    }
    btn.textContent = info;
    setTimeout(() => location.reload(), 1500);
  } catch (e) {
    btn.textContent = '❌ ' + e.message;
    btn.disabled = false;
  }
});

// Excluir trend do DB (libera pra re-coletar, não mexe no WP)
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-excluir-trend');
  if (!btn) return;
  ev.preventDefault();
  if (btn.disabled) return;
  const termo = btn.dataset.termo || '';
  if (!confirm(`Excluir trend "${termo}"?\n\nIsso remove o registro do banco local.\nO post publicado no WP NÃO é afetado.\nVocê poderá re-coletar/salvar o mesmo termo depois.`)) return;
  btn.classList.add('running');
  btn.textContent = '⏳';
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('id', btn.dataset.id);
    const r = await fetch('portal.php?ajax=excluir_trend&site=<?= urlencode($siteSlug) ?>', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Erro');
    btn.classList.remove('running');
    btn.textContent = '✓';
    // Remove a linha da tabela
    const row = btn.closest('tr');
    if (row) {
      row.style.transition = 'opacity .3s';
      row.style.opacity = '0';
      setTimeout(() => row.remove(), 300);
    }
  } catch (e) {
    btn.classList.remove('running');
    btn.textContent = '❌';
    alert('Erro: ' + e.message);
    btn.disabled = false;
  }
});

// Regerar do zero: trash do WP + reset do status na DB
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-regerar');
  if (!btn) return;
  ev.preventDefault();
  if (btn.disabled) return;
  if (!confirm('Regerar do zero?\n\n1) O post atual vai pra lixeira do WP\n2) O trend volta como "aprovado" na lista\n3) Marque o checkbox e clique em "Iniciar lote" pra gerar de novo\n\nCusta ~100s + API. Só use se o conteúdo ficou ruim.')) return;
  const orig = btn.textContent;
  btn.textContent = '⏳...';
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('id', btn.dataset.id);
    const r = await fetch('portal.php?ajax=regerar_reset&site=<?= urlencode($siteSlug) ?>', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Erro');
    btn.textContent = '✓ reset';
    setTimeout(() => location.reload(), 1000);
  } catch (e) {
    btn.textContent = '❌ ' + e.message;
    btn.disabled = false;
  }
});

// Gerar 1 post via GPT (teste A/B)
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-gerar-gpt-unico');
  if (!btn) return;
  ev.preventDefault();
  if (btn.disabled) return;
  let ids = [];
  try { ids = JSON.parse(btn.dataset.ids || '[]'); } catch (e) {}
  if (!ids.length) return;
  const idEscolhido = ids[0]; // pega o primeiro pendente
  if (!confirm(`Gerar 1 artigo via GPT (gpt-4o-mini) do cluster "${btn.dataset.evento}"?\n\nVai processar o trend de ID #${idEscolhido} usando OpenAI em vez de Claude. ~60-120s. Custa menos que Claude.`)) return;

  btn.textContent = '⏳ GPT gerando…';
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('id', idEscolhido);
    fd.append('modelo', 'gpt-4o-mini');
    const r = await fetch('portal.php?ajax=gerar_gpt', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Erro');
    let msg = `✓ Post #${d.post_id} criado via ${d.provedor} em ${Math.round(d.tempo_ms/1000)}s`;
    if (d.quality && d.quality.score) msg += ` · Qualidade: ${d.quality.score}/10`;
    alert(msg + `\n\nTítulo: ${d.titulo}\n\nStatus: ${d.status}`);
    setTimeout(() => location.reload(), 500);
  } catch (e) {
    btn.textContent = '❌ ' + e.message;
    btn.disabled = false;
    alert('Falhou: ' + e.message);
  }
});

// Gerar todos pendentes de um cluster (atalho a partir do card)
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-gerar-cluster');
  if (!btn) return;
  ev.preventDefault();
  if (btn.disabled) return;
  let ids = [];
  try { ids = JSON.parse(btn.dataset.ids || '[]'); } catch (e) { ids = []; }
  if (!ids.length) return;
  if (!confirm(`Gerar ${ids.length} artigos do cluster "${btn.dataset.evento}" em lote?\n\nTempo estimado: ~${Math.round(ids.length * 100 / 60)} min. Pode acompanhar o progresso no painel abaixo.`)) return;

  btn.textContent = '⏳ Criando fila...';
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('ids', JSON.stringify(ids));
    fd.append('formato', 'discover');
    const r = await fetch('portal.php?ajax=fila_iniciar&site=<?= urlencode($siteSlug) ?>', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Erro ao criar fila');
    btn.textContent = `✓ ${d.total} na fila`;
    // Scroll e dispara o tick loop da fila global existente
    const panel = document.getElementById('batch-progress');
    if (panel) {
      panel.style.display = 'block';
      panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    // Recarrega depois de 1s pra UI da fila assumir (ela tem auto-resume)
    setTimeout(() => location.reload(), 1200);
  } catch (e) {
    btn.textContent = '❌ ' + e.message;
    btn.disabled = false;
  }
});

// Interligar cluster (Fase 2 — cross-link entre hub e satélites)
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-link-cluster');
  if (!btn) return;
  ev.preventDefault();
  if (btn.disabled) return;
  if (!confirm(`Interligar todos os posts publicados do cluster "${btn.dataset.evento}"?\n\nCada post receberá um bloco "Leia também" apontando para os outros do mesmo evento.`)) return;
  const orig = btn.textContent;
  btn.textContent = '⏳ Interligando...';
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('evento', btn.dataset.evento);
    const r = await fetch('portal.php?ajax=cluster_interligar&site=<?= urlencode($siteSlug) ?>', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Erro ao interligar');
    btn.textContent = `✓ ${d.atualizados}/${d.total_posts} atualizados`;
    setTimeout(() => location.reload(), 1500);
  } catch (e) {
    btn.textContent = '❌ ' + e.message;
    btn.disabled = false;
  }
});

// Salvar cluster do calendário como trends aprovados
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-save-cluster');
  if (!btn) return;
  ev.preventDefault();
  if (btn.classList.contains('saved')) return;
  if (!confirm(`Salvar cluster "${btn.dataset.nome}" (${JSON.parse(btn.dataset.cluster).length} artigos) como trends aprovados?\n\nVão aparecer na aba "Ver salvos" prontos pra fila de geração.`)) return;
  btn.textContent = '⏳ Salvando…';
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('nome', btn.dataset.nome);
    fd.append('tema', btn.dataset.tema);
    fd.append('categoria', btn.dataset.categoria);
    fd.append('data_pico', btn.dataset.datapico);
    fd.append('cluster', btn.dataset.cluster);
    const r = await fetch('portal.php?ajax=calendario_salvar&site=<?= urlencode($siteSlug) ?>', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Erro ao salvar');
    btn.classList.add('saved');
    btn.textContent = `✓ ${d.salvos} salvos`;

    // Adiciona botão "Gerar agora" logo ao lado, com os IDs recém-salvos
    if (d.ids && d.ids.length > 0) {
      const genBtn = document.createElement('button');
      genBtn.type = 'button';
      genBtn.className = 'btn-g btn-gerar-do-calendario';
      genBtn.style.marginLeft = '6px';
      genBtn.dataset.ids = JSON.stringify(d.ids);
      genBtn.dataset.evento = btn.dataset.nome;
      genBtn.innerHTML = `▶ Gerar agora (${d.ids.length}) →`;
      btn.parentElement.appendChild(genBtn);
    }
  } catch (e) {
    btn.textContent = '❌ ' + e.message;
    btn.disabled = false;
  }
});

// Gerar agora a partir do calendário (sem trocar de aba)
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-gerar-do-calendario');
  if (!btn) return;
  ev.preventDefault();
  if (btn.disabled) return;
  let ids = [];
  try { ids = JSON.parse(btn.dataset.ids || '[]'); } catch (e) {}
  if (!ids.length) return;
  const min = Math.round(ids.length * 100 / 60);
  if (!confirm(`Iniciar fila com ${ids.length} artigos do cluster "${btn.dataset.evento}"?\n\nTempo estimado: ~${min} min. Você será redirecionado pra "Ver Salvos" onde acompanha o progresso em tempo real (pode fechar/reabrir a aba — a fila resume sozinha).`)) return;
  btn.textContent = '⏳ Criando fila…';
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('ids', JSON.stringify(ids));
    fd.append('formato', 'discover');
    const r = await fetch('portal.php?ajax=fila_iniciar&site=<?= urlencode($siteSlug) ?>', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Erro ao iniciar fila');
    // Redireciona pra Ver Salvos (lá o painel de progresso assume e fica tickando)
    window.location.href = 'portal.php?modo=atual&view=saved';
  } catch (e) {
    btn.textContent = '❌ ' + e.message;
    btn.disabled = false;
  }
});

// Atalhos do modo histórico
document.addEventListener('click', (ev) => {
  const sc = ev.target.closest('.sc');
  if (!sc) return;
  const inicio = document.getElementById('h-inicio');
  const fim    = document.getElementById('h-fim');
  const seed   = document.getElementById('h-seed');
  if (!inicio || !fim) return;

  const today = new Date();
  const ymd = d => d.toISOString().slice(0, 10);

  // Atalhos de data relativos a hoje
  if (sc.dataset.shift) {
    const lastYear = new Date(today); lastYear.setFullYear(today.getFullYear() - 1);
    if (sc.dataset.shift === 'week') {
      const start = new Date(lastYear); start.setDate(start.getDate() - 3);
      const end   = new Date(lastYear); end.setDate(end.getDate() + 3);
      inicio.value = ymd(start); fim.value = ymd(end);
    } else if (sc.dataset.shift === 'month') {
      const start = new Date(lastYear.getFullYear(), lastYear.getMonth(), 1);
      const end   = new Date(lastYear.getFullYear(), lastYear.getMonth() + 1, 0);
      inicio.value = ymd(start); fim.value = ymd(end);
    } else if (sc.dataset.shift === 'quarter') {
      const start = new Date(lastYear); start.setMonth(start.getMonth() - 3);
      inicio.value = ymd(start); fim.value = ymd(lastYear);
    }
  }

  // Atalhos sazonais: preenche seed + mês do ano passado (cobre o mês inteiro)
  if (sc.classList.contains('seasonal')) {
    seed.value = sc.dataset.seed;
    const mes = parseInt(sc.dataset.mes, 10) - 1; // JS: 0=janeiro
    const ano = today.getFullYear() - 1;
    const start = new Date(ano, mes, 1);
    const end   = new Date(ano, mes + 1, 0);
    inicio.value = ymd(start); fim.value = ymd(end);
  }
});
</script>
</body>
</html>
