<?php
/**
 * Reparo combinado de posts afetados pela limpeza do trust bar.
 *
 * Problemas detectados na amostra (https://comocomprar.com.br/fone-sem-fio-bom-e-barato/):
 *   1) Texto de intro dentro de <div id="rv"> sem <p> (WP strippou os paragraph tags)
 *   2) <style> inline pode ter sumido em alguns posts
 *
 * O que o script faz (por post):
 *   - Detecta se o intro (texto entre <div id="rv"> e o primeiro <div> interno) está "solto"
 *     sem <p>. Se estiver, quebra por linhas em branco e re-wrappa cada parágrafo em <p>.
 *   - Se faltar <style> antes do #rv, prepend do CSS do LandingBuilder::buildCSS().
 *   - Salva via REST e RELÊ o raw pra confirmar que <p> e <style> persistiram (se o WP
 *     strippar de novo, reporta status "stripado" em vez de falsear sucesso).
 *
 * Uso:
 *   reparar-post.php?site=comocomprar          → diagnóstico (lista candidatos)
 *   reparar-post.php?site=comocomprar&confirm=1 → aplica
 *   reparar-post.php?site=comocomprar&id=123&confirm=1 → só um post (útil pra testar antes)
 */
require_once __DIR__ . '/lib/Wordpress.php';
require_once __DIR__ . '/lib/LandingBuilder.php';
$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

set_time_limit(0);

$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$postIdUnico = (int)($_GET['id'] ?? 0);
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$lb = new LandingBuilder($cfg['site_name'] ?? '', $cfg['wp_url'] ?? '', []);
$css = $lb->buildCSS();

// ── Checa capabilities do user ──
$capsErro = null;
$unfiltered = null;
try {
    $ref = new ReflectionClass($wp);
    $req = $ref->getMethod('request');
    $req->setAccessible(true);
    $me = $req->invoke($wp, 'GET', '/users/me?context=edit');
    $caps = $me['capabilities'] ?? [];
    $unfiltered = !empty($caps['unfiltered_html']);
} catch (Throwable $e) {
    $capsErro = $e->getMessage();
}

// Tags que indicam conteúdo já estruturado (não wrappar texto solto se começa com uma delas)
const BLOCK_TAG_RE = '#^<(p|h[1-6]|ul|ol|table|figure|blockquote|pre|aside|div|section|article|nav|header|footer|details)\b#i';

/**
 * Wrappa texto solto em <p> dentro de um corpo HTML.
 * Respeita blocos já estruturados e o par <summary>X</summary>.
 */
function wrapLoose(string $body): string {
    $body = trim($body);
    if ($body === '') return '';

    // Protege blocos atômicos (conteúdo interno NÃO deve ser quebrado pela normalização)
    $placeholders = [];
    foreach (['ul', 'ol', 'table', 'figure', 'pre', 'blockquote'] as $tag) {
        $body = preg_replace_callback(
            '#<' . $tag . '\b[^>]*>[\s\S]*?</' . $tag . '>#i',
            function($m) use (&$placeholders) {
                $key = '{{ATOM_' . count($placeholders) . '}}';
                $placeholders[$key] = $m[0];
                return $key;
            },
            $body
        );
    }

    // Normalização wpautop-style: \n\n em torno de tags de parágrafo/seção (sem tocar em ul/ol/table/li)
    $blocks = '(?:p|h[1-6]|aside|div|section|article|nav|header|footer|summary|details|hr)';
    $body = preg_replace('#(</' . $blocks . '>)#i', "$1\n\n", $body);
    $body = preg_replace('#(<' . $blocks . '\b[^>]*>)#i', "\n\n$1", $body);
    // Também isola placeholders atômicos para que não sejam englobados em <p> junto com texto solto vizinho
    $body = preg_replace('#(\{\{ATOM_\d+\}\})#', "\n\n$1\n\n", $body);
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace("/\n\n+/", "\n\n", $body);
    $body = trim($body);

    $chunks = preg_split('/\n\s*\n/', $body, -1);
    $out = [];
    foreach ($chunks as $chunk) {
        $t = trim($chunk);
        if ($t === '') continue;
        // Chunk é só um placeholder de bloco atômico → trata como block
        if (preg_match('#^\{\{ATOM_\d+\}\}$#', $t)) { $out[] = $t; continue; }
        if (preg_match('#^<summary[^>]*>[\s\S]*?</summary>#si', $t, $sm)) {
            $sumario = $sm[0];
            $resto = trim(substr($t, strlen($sumario)));
            if ($resto === '') { $out[] = $sumario; }
            elseif (preg_match(BLOCK_TAG_RE, $resto) || preg_match('#^\{\{ATOM_\d+\}\}#', $resto)) { $out[] = $sumario; $out[] = $resto; }
            else { $out[] = $sumario; $out[] = '<p>' . $resto . '</p>'; }
        } elseif (preg_match(BLOCK_TAG_RE, $t)) {
            $out[] = $t;
        } else {
            $out[] = '<p>' . $t . '</p>';
        }
    }
    $result = "\n" . implode("\n", $out) . "\n";
    // Restaura blocos atômicos
    if ($placeholders) $result = strtr($result, $placeholders);
    return $result;
}

/**
 * Analisa o content raw e retorna o que precisa ser reparado.
 * @return array{intro_solto:bool, details_solto:bool, sem_style:bool, raw:string}
 */
function analisar(string $raw): array {
    $semStyle = !preg_match('#<style\b[^>]*>#i', $raw);
    $introSolto = false;
    $detailsSolto = false;
    // Intro: trecho entre <div id="rv"...> e o primeiro <div interno
    if (preg_match('#(<div\s+id=(["\'])rv\2[^>]*>)([\s\S]*?)(<div[\s>])#i', $raw, $m)) {
        $corpo = $m[3];
        if (!preg_match('#<(p|h[1-6]|ul|ol)\b#i', $corpo) && trim($corpo) !== '') $introSolto = true;
    }
    // Details: dentro de cada <details>...</details> procuro:
    //   a) </summary>TEXTO (texto colado no fecho do summary)
    //   b) </p> ou </table> ou </ul> seguido de \n\n TEXTO (texto solto entre blocos)
    if (preg_match_all('#<details[^>]*>([\s\S]*?)</details>#i', $raw, $dm)) {
        foreach ($dm[1] as $body) {
            if (preg_match('#</summary>\s*[^\s<]#i', $body)) { $detailsSolto = true; break; }
            // chunk por chunk, algum sem block tag no início?
            $chunks = preg_split('/\n\s*\n/', trim($body), -1);
            foreach ($chunks as $c) {
                $c = trim($c);
                if ($c === '') continue;
                if (preg_match('#^<summary[^>]*>[\s\S]*?</summary>#si', $c, $sm)) {
                    $resto = trim(substr($c, strlen($sm[0])));
                    if ($resto !== '' && !preg_match(BLOCK_TAG_RE, $resto)) { $detailsSolto = true; break 2; }
                    continue;
                }
                if (!preg_match(BLOCK_TAG_RE, $c)) { $detailsSolto = true; break 2; }
            }
        }
    }
    return ['intro_solto' => $introSolto, 'details_solto' => $detailsSolto, 'sem_style' => $semStyle, 'raw' => $raw];
}

/** Aplica os reparos no raw e retorna o novo content. */
function reparar(string $raw, string $css): string {
    // 1) Re-wrap intro solto dentro de <div id="rv">
    if (preg_match('#(<div\s+id=(["\'])rv\2[^>]*>)([\s\S]*?)(<div[\s>])#i', $raw, $m, PREG_OFFSET_CAPTURE)) {
        $corpo    = $m[3][0];
        $corpoPos = $m[3][1];
        $corpoLen = strlen($corpo);
        if (!preg_match('#<(p|h[1-6]|ul|ol)\b#i', $corpo) && trim($corpo) !== '') {
            $raw = substr($raw, 0, $corpoPos) . wrapLoose($corpo) . substr($raw, $corpoPos + $corpoLen);
        }
    }
    // 2) Re-wrap texto solto dentro de cada <details>...</details>
    $raw = preg_replace_callback(
        '#(<details[^>]*>)([\s\S]*?)(</details>)#i',
        function($m) { return $m[1] . wrapLoose($m[2]) . $m[3]; },
        $raw
    );
    // 3) Prepend <style> se não houver
    if (!preg_match('#<style\b[^>]*>#i', $raw)) {
        $raw = $css . "\n" . $raw;
    }
    return $raw;
}

// ── Coleta candidatos ──
$candidatos = [];
$erros = [];

if ($postIdUnico > 0) {
    try {
        $p = $wp->getPost($postIdUnico);
        $candidatos[] = [
            'id'    => $postIdUnico,
            'title' => html_entity_decode(strip_tags($p['title']['rendered'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'link'  => $p['link'] ?? '',
        ];
    } catch (Throwable $e) {
        $erros[] = "Post #{$postIdUnico}: " . $e->getMessage();
    }
} else {
    $pagina = 1;
    while (true) {
        try { $lote = $wp->listarPosts($pagina, 100); }
        catch (Throwable $e) { $erros[] = "Página {$pagina}: " . $e->getMessage(); break; }
        if (!is_array($lote) || empty($lote)) break;
        foreach ($lote as $p) {
            $id = (int)($p['id'] ?? 0);
            if (!$id) continue;
            $rendered = $p['content']['rendered'] ?? '';
            if (!str_contains($rendered, 'id="rv"') && !str_contains($rendered, "id='rv'")) continue;
            // Suspeito se: sem <style> NO rendered OU intro solta OU details com texto solto
            $semStyle = !preg_match('#<style\b[^>]*>#i', $rendered);
            $introSolta = (bool)preg_match('#<div\s+id=(["\'])rv\1[^>]*>\s*(?!<[ph]|<ul|<ol|<div|<section)[\w<]#i', $rendered);
            $detailsSolto = (bool)preg_match('#</summary>\s*[^\s<]#i', $rendered);
            if (!$semStyle && !$introSolta && !$detailsSolto) continue;
            $candidatos[] = [
                'id'    => $id,
                'title' => html_entity_decode(strip_tags($p['title']['rendered'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'link'  => $p['link'] ?? '',
            ];
        }
        if (count($lote) < 100) break;
        $pagina++;
    }
}

$resultados = [];
if ($confirm && !empty($candidatos)) {
    foreach ($candidatos as $c) {
        $id = $c['id'];
        try {
            $full = $wp->getPost($id);
            $raw = $full['content']['raw'] ?? '';
            if ($raw === '') { $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'skip', 'msg' => 'raw vazio']; continue; }

            $info = analisar($raw);
            if (!$info['intro_solto'] && !$info['details_solto'] && !$info['sem_style']) {
                $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'skip', 'msg' => 'nada a reparar']; continue;
            }

            $novo = reparar($raw, $css);
            if ($novo === $raw) { $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'skip', 'msg' => 'reparar() não alterou']; continue; }

            $wp->atualizarPost($id, ['content' => $novo]);

            // Relê e verifica persistência
            sleep(1);
            $check = $wp->getPost($id);
            $chkRaw = $check['content']['raw'] ?? '';
            $chkInfo = analisar($chkRaw);
            $falhas = [];
            if ($chkInfo['intro_solto'])   $falhas[] = 'intro';
            if ($chkInfo['details_solto']) $falhas[] = 'details';
            if ($chkInfo['sem_style'])     $falhas[] = '<style>';

            if (empty($falhas)) {
                $reparos = [];
                if ($info['intro_solto'])   $reparos[] = 'intro';
                if ($info['details_solto']) $reparos[] = 'details';
                if ($info['sem_style'])     $reparos[] = '<style>';
                $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'ok', 'msg' => 'reparado: ' . implode(' + ', $reparos)];
            } else {
                $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'stripado', 'msg' => 'WP removeu de novo: ' . implode(', ', $falhas)];
            }
        } catch (Throwable $e) {
            $resultados[] = ['id' => $id, 'title' => $c['title'], 'status' => 'erro', 'msg' => $e->getMessage()];
        }
    }
}

$okCount       = count(array_filter($resultados, fn($r) => $r['status'] === 'ok'));
$errCount      = count(array_filter($resultados, fn($r) => $r['status'] === 'erro'));
$skipCount     = count(array_filter($resultados, fn($r) => $r['status'] === 'skip'));
$stripadoCount = count(array_filter($resultados, fn($r) => $r['status'] === 'stripado'));
?>
<!DOCTYPE html>
<html lang='pt-br'>
<head>
<meta charset='UTF-8'>
<title>Reparar Posts — <?= htmlspecialchars($cfg['_site_name'] ?? $siteSlug) ?></title>
<style>
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:980px;margin:0 auto}
h1{color:#fff;margin:0 0 6px}
.sub{color:#666;margin-bottom:18px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:18px 22px;border-radius:10px;margin-bottom:14px}
.box h2{margin-top:0;font-size:16px;color:#e0e0e0}
.btn{display:inline-block;padding:12px 22px;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;text-decoration:none;cursor:pointer}
.btn-dry{background:linear-gradient(135deg,#6366f1,#8b5cf6)}
.btn-test{background:linear-gradient(135deg,#f59e0b,#d97706)}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px}
th{text-align:left;padding:8px 10px;background:#0f1115;color:#888;font-size:11px;text-transform:uppercase;border-bottom:2px solid #2a2e38}
td{padding:8px 10px;border-bottom:1px solid #1e2230;vertical-align:middle}
.tag-ok{color:#22c55e;font-weight:700}
.tag-erro{color:#ef4444;font-weight:700}
.tag-skip{color:#a78bfa;font-weight:700}
.tag-stripado{color:#f59e0b;font-weight:700}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
.resumo{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:10px 0}
.resumo-item{text-align:center;background:#0f1115;padding:14px;border-radius:6px}
.resumo-item strong{display:block;font-size:22px;color:#a78bfa}
.resumo-item span{font-size:11px;color:#666}
.cap-ok{color:#22c55e}.cap-bad{color:#f59e0b}
input[type=text]{padding:10px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:14px;width:140px}
</style>
</head>
<body>
<div class='container'>
  <h1>🔧 Reparar Posts (intro &lt;p&gt; + &lt;style&gt;)</h1>
  <p class='sub'>Re-wrap de parágrafos de intro soltos + re-injeção do CSS. Verifica persistência após o save.</p>

  <div class='box'>
    <h2>Site + user REST</h2>
    <p><strong><?= htmlspecialchars($cfg['_site_name'] ?? $siteSlug) ?></strong> · <?= htmlspecialchars($cfg['wp_url'] ?? '') ?></p>
    <?php if ($unfiltered === null): ?>
      <p class='cap-bad'>❌ Não consegui ler capabilities: <?= htmlspecialchars($capsErro ?? '') ?></p>
    <?php elseif ($unfiltered): ?>
      <p class='cap-ok'>✅ User tem <code>unfiltered_html</code> — <code>&lt;style&gt;</code> e <code>&lt;p&gt;</code> devem persistir.</p>
    <?php else: ?>
      <p class='cap-bad'>⚠️ User NÃO tem <code>unfiltered_html</code> — pode ser que nem mesmo <code>&lt;p&gt;</code> persista. Teste com 1 post antes de aplicar em lote.</p>
    <?php endif; ?>
  </div>

  <div class='box'>
    <h2>🧪 Teste em um único post (recomendado primeiro)</h2>
    <form method='get' style='display:flex;gap:10px;align-items:end'>
      <input type='hidden' name='site' value='<?= htmlspecialchars($siteSlug) ?>'>
      <div>
        <label style='display:block;font-size:12px;color:#bbb'>Post ID</label>
        <input type='text' name='id' placeholder='ex: 123' value='<?= $postIdUnico ?: '' ?>'>
      </div>
      <button class='btn btn-test' type='submit' name='confirm' value='1'>Reparar este post</button>
    </form>
    <p style='color:#666;font-size:11px;margin-top:8px'>Use pra testar 1 post afetado. Se o status voltar <code>stripado</code>, não adianta rodar em lote sem resolver capability antes.</p>
  </div>

  <?php if (!empty($erros)): ?>
    <div class='box' style='border-left:4px solid #ef4444'>
      <h2 style='color:#fca5a5'>Erros</h2>
      <?php foreach ($erros as $e): ?><p style='color:#fca5a5;font-size:13px'><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$postIdUnico): ?>
    <div class='box'>
      <h2><?= count($candidatos) ?> post(s) candidatos a reparo</h2>
      <?php if (empty($candidatos)): ?>
        <p style='color:#888'>Nenhum post precisa reparo.</p>
      <?php else: ?>
        <?php if (!$confirm): ?>
          <p style='color:#888;font-size:13px'>Modo <strong>dry-run</strong>. Recomendo testar 1 post primeiro (acima).</p>
          <p style='margin-top:12px'>
            <a class='btn' href='?site=<?= urlencode($siteSlug) ?>&confirm=1' onclick="return confirm('Reparar <?= count($candidatos) ?> post(s)?')">✓ Aplicar em lote</a>
            <a class='btn btn-dry' style='margin-left:8px' href='?site=<?= urlencode($siteSlug) ?>'>Recarregar</a>
          </p>
        <?php else: ?>
          <div class='resumo'>
            <div class='resumo-item'><strong><?= $okCount ?></strong><span>reparados</span></div>
            <div class='resumo-item'><strong><?= $stripadoCount ?></strong><span>stripados</span></div>
            <div class='resumo-item'><strong><?= $skipCount ?></strong><span>pulados</span></div>
            <div class='resumo-item'><strong><?= $errCount ?></strong><span>erros</span></div>
          </div>
        <?php endif; ?>

        <table>
          <thead><tr><th>ID</th><th>Título</th><?php if ($confirm): ?><th>Status</th><th>Msg</th><?php else: ?><th>Ver</th><?php endif; ?></tr></thead>
          <tbody>
          <?php if ($confirm): foreach ($resultados as $r): ?>
            <tr>
              <td><?= $r['id'] ?></td>
              <td><?= htmlspecialchars($r['title']) ?></td>
              <td><span class='tag-<?= $r['status'] ?>'><?= strtoupper($r['status']) ?></span></td>
              <td style='color:#888;font-size:12px'><?= htmlspecialchars($r['msg']) ?></td>
            </tr>
          <?php endforeach; else: foreach ($candidatos as $c): ?>
            <tr>
              <td><?= $c['id'] ?></td>
              <td><?= htmlspecialchars($c['title']) ?></td>
              <td><?php if (!empty($c['link'])): ?><a href='<?= htmlspecialchars($c['link']) ?>' target='_blank'>abrir</a><?php endif; ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php elseif ($confirm && !empty($resultados)): ?>
    <div class='box'>
      <h2>Resultado do teste no post #<?= $postIdUnico ?></h2>
      <?php foreach ($resultados as $r): ?>
        <p><span class='tag-<?= $r['status'] ?>'><?= strtoupper($r['status']) ?></span> — <?= htmlspecialchars($r['title']) ?></p>
        <p style='color:#888'><?= htmlspecialchars($r['msg']) ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
