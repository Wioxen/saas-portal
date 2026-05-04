<?php
/**
 * gerarimagem.php — gerador manual de imagem destacada por post.
 *
 * Fluxo em 3 fases:
 *  1. form    — escolhe site, busca posts, escolhe post + fonte (DALL-E|Pexels) + prompt
 *  2. gerar   — chama OpenAI (DALL-E 3) ou Pexels com o prompt, baixa binário,
 *               converte para WebP via api.gogleads.com.br/Convert/image/webp,
 *               salva em data/img_cache/, mostra preview on-screen
 *  3. aplicar — lê o WebP cacheado, faz upload no WP e atualiza featured_media do post
 *
 * Convenções: aspas simples no HTML, acentos completos, prefere métodos existentes
 * de Wordpress.php (uploadImagemBinario + atualizarPost).
 */

require_once __DIR__ . '/lib/Wordpress.php';
require_once __DIR__ . '/lib/OpenAI.php';
require_once __DIR__ . '/lib/Pexels.php';

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

$cacheDir = __DIR__ . '/data/img_cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

// ── Helpers ─────────────────────────────────────────────────────────────────
function gi_baixar(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $b = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($b !== false && $code < 400) ? $b : null;
}

/**
 * Converte binário para WebP via api.gogleads.com.br. Retorna binário WebP ou null.
 * Mesma lógica de Wordpress::converterParaWebp (que é private).
 */
function gi_converterWebp(string $bin, string $nome = 'imagem.jpg'): ?string {
    $tmp = tempnam(sys_get_temp_dir(), 'webp_');
    if ($tmp === false) return null;
    file_put_contents($tmp, $bin);

    $ch = curl_init('https://api.gogleads.com.br/Convert/image/webp');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['file' => new CURLFile($tmp, '', $nome)],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    @unlink($tmp);

    if ($resp === false || $code >= 400 || $resp === '') return null;

    if (stripos($ct, 'application/json') !== false) {
        $j = json_decode($resp, true);
        if (is_array($j)) {
            if (!empty($j['data']) && is_string($j['data'])) {
                $d = base64_decode($j['data'], true);
                if ($d !== false) return $d;
                return $j['data'];
            }
            if (!empty($j['url'])) return gi_baixar($j['url']);
        }
        return null;
    }
    return $resp;
}

function gi_listarPosts(Wordpress $wp, string $busca, int $limit = 20): array {
    if ($busca !== '') {
        return $wp->buscarRelacionados($busca, $limit, 0);
    }
    // Sem busca: posts mais recentes (sem featured embed pra não estourar)
    $raw = $wp->listarPosts(1, $limit);
    $out = [];
    foreach ($raw as $p) {
        $out[] = [
            'id'    => (int)($p['id'] ?? 0),
            'title' => strip_tags(html_entity_decode((string)($p['title']['rendered'] ?? ''))),
            'link'  => (string)($p['link'] ?? ''),
            'image' => '',
        ];
    }
    return $out;
}

// ── Endpoint: serve WebP cacheado pra <img src='?preview=...'> ─────────────
if (isset($_GET['preview'])) {
    $key = preg_replace('/[^a-z0-9]/i', '', (string)$_GET['preview']);
    $f = $cacheDir . '/' . $key . '.webp';
    if (!is_file($f)) {
        $f = $cacheDir . '/' . $key . '.jpg';
        if (!is_file($f)) { http_response_code(404); exit('not found'); }
        header('Content-Type: image/jpeg');
    } else {
        header('Content-Type: image/webp');
    }
    header('Cache-Control: max-age=600');
    readfile($f);
    exit;
}

// ── Estado ──────────────────────────────────────────────────────────────────
$fase     = $_POST['fase'] ?? $_GET['fase'] ?? 'form';
$busca    = trim((string)($_POST['busca'] ?? $_GET['busca'] ?? ''));
$postId   = (int)($_POST['post_id'] ?? $_GET['post_id'] ?? 0);
$fonte    = (string)($_POST['fonte'] ?? 'dalle');
$prompt   = trim((string)($_POST['prompt'] ?? ''));
$tamanho  = (string)($_POST['tamanho'] ?? '1792x1024');
$cacheKey = preg_replace('/[^a-z0-9]/i', '', (string)($_POST['cache_key'] ?? ''));

$erro     = null;
$ok       = null;
$posts    = [];
$preview  = null;   // {cache_key, fonte, prompt, bytes_origem, bytes_webp, webp_ok, metadata}
$aplicado = null;   // {media_id, source_url}
$postInfo = null;   // {id, title, link, featured_media, featured_url}

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

// ── Carrega lista de posts pro form sempre que houver busca/recarrega ──────
if (in_array($fase, ['form', 'buscar', 'gerar', 'aplicar'], true)) {
    try {
        $posts = gi_listarPosts($wp, $busca, 20);
    } catch (Throwable $e) {
        $erro = 'Erro ao listar posts: ' . $e->getMessage();
    }
}

// ── Carrega dados do post selecionado (pra mostrar título e featured atual) ──
if ($postId > 0) {
    try {
        $pd = $wp->getPost($postId);
        $featuredId  = (int)($pd['featured_media'] ?? 0);
        $featuredUrl = '';
        if ($featuredId > 0) {
            try {
                $m = $wp->getMedia($featuredId);
                $featuredUrl = (string)($m['source_url'] ?? '');
            } catch (Throwable $e) {}
        }
        $postInfo = [
            'id'             => $postId,
            'title'          => strip_tags(html_entity_decode((string)($pd['title']['rendered'] ?? ''))),
            'link'           => (string)($pd['link'] ?? ''),
            'featured_media' => $featuredId,
            'featured_url'   => $featuredUrl,
        ];
    } catch (Throwable $e) {
        $erro = 'Erro ao buscar post #' . $postId . ': ' . $e->getMessage();
    }
}

// ── Fase: gerar imagem ─────────────────────────────────────────────────────
if ($fase === 'gerar' && $erro === null) {
    set_time_limit(0);
    if ($postId <= 0) {
        $erro = 'Escolha um post antes de gerar.';
    } elseif ($prompt === '') {
        $erro = 'Digite um prompt pra gerar a imagem.';
    } elseif (!in_array($fonte, ['dalle', 'pexels'], true)) {
        $erro = 'Fonte inválida.';
    } else {
        try {
            $bin   = null;
            $orig  = '';
            $meta  = [];

            if ($fonte === 'dalle') {
                if (empty($cfg['openai_api_key'])) {
                    throw new RuntimeException('OPENAI_API_KEY não configurada no .env');
                }
                $openai = new OpenAI($cfg['openai_api_key'], $cfg['openai_model'] ?? 'gpt-4o-mini');
                $size = in_array($tamanho, ['1792x1024', '1024x1792', '1024x1024'], true) ? $tamanho : '1792x1024';
                $res = $openai->gerarImagemDetalhado($prompt, $size, 'hd', 'vivid', 'dall-e-3');
                if (!$res || empty($res['url'])) {
                    throw new RuntimeException('DALL-E não retornou URL (limite de quota? circuit breaker aberto?)');
                }
                $orig = (string)$res['url'];
                $meta = [
                    'revised_prompt' => $res['revised_prompt'] ?? null,
                    'size'           => $res['size'] ?? $size,
                    'style'          => $res['style'] ?? 'vivid',
                ];
                $bin = gi_baixar($orig);
                if ($bin === null) throw new RuntimeException('Falha ao baixar binário do DALL-E');
            } else { // pexels
                if (empty($cfg['pexels_api_key'])) {
                    throw new RuntimeException('PEXELS_API_KEY não configurada no .env');
                }
                $pex = new Pexels($cfg['pexels_api_key']);
                $cands = $pex->buscar($prompt, 15, 'landscape');
                if (empty($cands)) {
                    throw new RuntimeException('Pexels não encontrou imagens pro prompt "' . $prompt . '" (tenta termos em inglês)');
                }
                $top = reset($cands);
                $orig = (string)$top['url'];
                $meta = [
                    'photographer' => $top['photographer'] ?? '',
                    'pexels_id'    => $top['id'] ?? 0,
                    'alt'          => $top['alt'] ?? '',
                    'score'        => $top['score'] ?? 0,
                    'width'        => $top['width'] ?? 0,
                    'height'       => $top['height'] ?? 0,
                ];
                $bin = gi_baixar($orig);
                if ($bin === null) throw new RuntimeException('Falha ao baixar binário do Pexels');
            }

            $bytesOrig = strlen($bin);

            // Conversão WebP via gogleads
            $nomeArq = ($fonte === 'dalle' ? 'dalle-' : 'pexels-') . time() . '.jpg';
            $webp = gi_converterWebp($bin, $nomeArq);
            $webpOk = false;
            $bytesWebp = 0;
            $cacheKey = bin2hex(random_bytes(8));

            if ($webp !== null && strlen($webp) > 0) {
                $bytesWebp = strlen($webp);
                file_put_contents($cacheDir . '/' . $cacheKey . '.webp', $webp);
                $webpOk = true;
            } else {
                // Fallback: salva binário original como .jpg pra ainda ser exibível/aplicável
                file_put_contents($cacheDir . '/' . $cacheKey . '.jpg', $bin);
            }

            $preview = [
                'cache_key'    => $cacheKey,
                'fonte'        => $fonte,
                'prompt'       => $prompt,
                'url_original' => $orig,
                'bytes_origem' => $bytesOrig,
                'bytes_webp'   => $bytesWebp,
                'webp_ok'      => $webpOk,
                'metadata'     => $meta,
            ];
            $ok = 'Imagem gerada e ' . ($webpOk ? 'convertida para WebP' : 'salva (WebP falhou — usando original)') . '.';
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    }
}

// ── Fase: aplicar como featured do post ────────────────────────────────────
if ($fase === 'aplicar' && $erro === null) {
    set_time_limit(0);
    if ($postId <= 0)              $erro = 'Post inválido.';
    elseif ($cacheKey === '')      $erro = 'cache_key ausente — gere a imagem antes.';
    else {
        $arqWebp = $cacheDir . '/' . $cacheKey . '.webp';
        $arqJpg  = $cacheDir . '/' . $cacheKey . '.jpg';
        $arq = is_file($arqWebp) ? $arqWebp : (is_file($arqJpg) ? $arqJpg : '');
        if ($arq === '') {
            $erro = 'Cache da imagem expirou — gere de novo.';
        } else {
            try {
                $bin = (string)file_get_contents($arq);
                $ext = (substr($arq, -5) === '.webp') ? 'webp' : 'jpg';
                $slugBase = ($postInfo['title'] ?? 'imagem-' . $postId);
                // Slug SEO: minúsculas + hifens, max 60 chars
                require_once __DIR__ . '/lib/DiscoverImagemFeatured.php';
                $slug = DiscoverImagemFeatured::slugSeo($slugBase, '');
                $alt  = $postInfo['title'] ?? '';

                $mediaId = $wp->uploadImagemBinario($bin, $slug, $alt, $ext);
                if (!$mediaId) throw new RuntimeException('Upload no WP retornou null');

                $resp = $wp->atualizarPost($postId, ['featured_media' => $mediaId]);

                $sourceUrl = '';
                try { $m = $wp->getMedia($mediaId); $sourceUrl = (string)($m['source_url'] ?? ''); } catch (Throwable $e) {}

                $aplicado = [
                    'media_id'   => $mediaId,
                    'source_url' => $sourceUrl,
                    'edit_url'   => rtrim($cfg['wp_url'], '/') . '/wp-admin/post.php?post=' . $postId . '&action=edit',
                ];
                $ok = 'Imagem aplicada como destacada do post #' . $postId;

                // Atualiza preview pro re-render mostrar resultado
                $preview = [
                    'cache_key'    => $cacheKey,
                    'fonte'        => $fonte,
                    'prompt'       => $prompt,
                    'url_original' => '',
                    'bytes_origem' => 0,
                    'bytes_webp'   => filesize($arq),
                    'webp_ok'      => $ext === 'webp',
                    'metadata'     => [],
                ];

                // Atualiza postInfo pra refletir featured nova
                $postInfo['featured_media'] = $mediaId;
                $postInfo['featured_url']   = $sourceUrl;
            } catch (Throwable $e) {
                $erro = 'Erro ao aplicar: ' . $e->getMessage();
            }
        }
    }
}

$temDalle  = !empty($cfg['openai_api_key']);
$temPexels = !empty($cfg['pexels_api_key']);
?>
<!DOCTYPE html>
<html lang='pt-br'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width, initial-scale=1.0'>
<title>Gerar imagem destacada — DALL-E + Pexels</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:1100px;margin:0 auto}
h1{color:#fff;margin:0 0 4px;font-size:24px}
.sub{color:#666;margin-bottom:20px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:22px;border-radius:10px;margin-bottom:16px}
.box h2{margin-top:0;font-size:18px;color:#fff}
label{display:block;font-weight:600;margin:10px 0 6px;font-size:13px;color:#bbb}
input[type=text],input[type=search],textarea,select{width:100%;padding:12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:14px;font-family:inherit}
textarea{min-height:110px;resize:vertical;line-height:1.5}
input:focus,textarea:focus,select:focus{outline:none;border-color:#6366f1}
button,.btn{padding:13px 22px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;display:inline-block;text-decoration:none}
button.secondary,.btn.secondary{background:#1e2230;border:1px solid #2a2e38}
button.danger,.btn.danger{background:linear-gradient(135deg,#f59e0b,#ef4444)}
.row{display:flex;gap:14px;align-items:end;flex-wrap:wrap}
.erro{background:#3b1818;border-left:4px solid #ef4444;padding:14px;border-radius:6px;margin-bottom:16px;color:#fca5a5}
.ok{background:#0f3a1c;border-left:4px solid #22c55e;padding:14px;border-radius:6px;margin-bottom:16px;color:#86efac}
.hint{font-size:11px;color:#555;margin-top:4px}
.posts-list{max-height:380px;overflow-y:auto;border:1px solid #2a2e38;border-radius:8px;background:#0f1115}
.post-row{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #1e2230;cursor:pointer}
.post-row:hover{background:#1e2230}
.post-row.sel{background:#0c4a6e;border-left:4px solid #0ea5e9}
.post-row label{margin:0;flex:1;cursor:pointer;font-size:13px;color:#d0d0d0;font-weight:500}
.post-row .pid{font-size:11px;color:#666;min-width:50px}
.post-row .pthumb{width:42px;height:42px;border-radius:4px;background:#1e2230;flex-shrink:0;object-fit:cover}
.fonte-bar{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}
.fonte-radio{flex:1;min-width:200px;display:flex;align-items:center;gap:10px;background:#0f1115;border:2px solid #2a2e38;border-radius:8px;padding:14px;cursor:pointer}
.fonte-radio.sel{border-color:#8b5cf6;background:#1a1538}
.fonte-radio input{accent-color:#8b5cf6}
.fonte-radio strong{color:#e0e0e0;display:block;font-size:14px}
.fonte-radio span{color:#888;font-size:11px}
.fonte-radio.disabled{opacity:.4;cursor:not-allowed}
.preview-imgs{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}
.preview-imgs .col{background:#0f1115;padding:10px;border-radius:8px;border:1px solid #2a2e38}
.preview-imgs h4{margin:0 0 8px;color:#888;font-size:11px;text-transform:uppercase;letter-spacing:.5px}
.preview-imgs img{width:100%;height:auto;border-radius:6px;display:block}
.kv{display:grid;grid-template-columns:160px 1fr;gap:6px 14px;font-size:12px;margin-top:10px}
.kv b{color:#888;font-weight:600}
.kv span{color:#d0d0d0;word-break:break-all}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
.tag{display:inline-block;background:#1e2230;color:#a78bfa;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;margin-left:6px}
.tag.green{background:#0f3a1c;color:#86efac}
.tag.red{background:#3b1818;color:#fca5a5}
@media(max-width:720px){.preview-imgs{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class='container'>
  <h1>🖼️ Gerar imagem destacada</h1>
  <p class='sub'>DALL-E 3 ou Pexels → conversão WebP via gogleads → upload + featured_media no WP.</p>

  <?php if ($erro): ?><div class='erro'><?= htmlspecialchars($erro) ?></div><?php endif; ?>
  <?php if ($ok):   ?><div class='ok'><?= htmlspecialchars($ok) ?></div><?php endif; ?>

  <?php include __DIR__ . '/_site_select.php'; ?>

  <form method='POST' id='formGerar'>
    <input type='hidden' name='fase' id='faseInput' value='gerar'>
    <input type='hidden' name='site' value='<?= htmlspecialchars($siteSlug) ?>'>
    <input type='hidden' name='post_id' id='postIdInput' value='<?= (int)$postId ?>'>

    <div class='box'>
      <h2>1. Buscar post</h2>
      <div class='row'>
        <div style='flex:1;min-width:300px'>
          <label>Buscar por título (vazio = 20 mais recentes)</label>
          <input type='search' name='busca' value='<?= htmlspecialchars($busca) ?>' placeholder='ex: vitória, enem, vagas...' onkeydown='if(event.key==="Enter"){event.preventDefault();faseSubmit("buscar")}'>
        </div>
        <div>
          <button type='button' class='secondary' onclick='faseSubmit("buscar")'>🔍 Buscar</button>
        </div>
      </div>

      <?php if (!empty($posts)): ?>
        <div style='margin-top:14px'>
          <label><?= count($posts) ?> post<?= count($posts)===1?'':'s' ?> — clique pra selecionar</label>
          <div class='posts-list'>
            <?php foreach ($posts as $p): $isSel = ((int)$p['id'] === $postId); ?>
              <div class='post-row <?= $isSel ? 'sel' : '' ?>' onclick='selecionarPost(<?= (int)$p['id'] ?>)'>
                <span class='pid'>#<?= (int)$p['id'] ?></span>
                <?php if (!empty($p['image'])): ?>
                  <img class='pthumb' src='<?= htmlspecialchars($p['image']) ?>' alt='' loading='lazy'>
                <?php else: ?>
                  <div class='pthumb' style='display:flex;align-items:center;justify-content:center;color:#444;font-size:18px'>—</div>
                <?php endif; ?>
                <label><?= htmlspecialchars(html_entity_decode($p['title'])) ?></label>
                <?php if (!empty($p['link'])): ?>
                  <a href='<?= htmlspecialchars($p['link']) ?>' target='_blank' onclick='event.stopPropagation()' style='font-size:11px'>↗</a>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php elseif ($busca !== ''): ?>
        <p class='hint' style='font-size:12px;color:#666;margin-top:10px'>Nenhum post encontrado pra "<?= htmlspecialchars($busca) ?>".</p>
      <?php endif; ?>

      <?php if ($postInfo): ?>
        <div style='margin-top:14px;padding:12px;background:#0c4a6e22;border:1px solid #0ea5e9;border-radius:8px'>
          <div style='display:flex;justify-content:space-between;align-items:start;gap:10px;flex-wrap:wrap'>
            <div>
              <strong style='color:#7dd3fc'>✓ Post selecionado #<?= $postInfo['id'] ?></strong>
              <div style='color:#e0e0e0;font-size:14px;margin-top:4px'><?= htmlspecialchars($postInfo['title']) ?></div>
              <a href='<?= htmlspecialchars($postInfo['link']) ?>' target='_blank' style='font-size:11px'>abrir post ↗</a>
            </div>
            <?php if ($postInfo['featured_url']): ?>
              <div>
                <div style='font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.5px'>featured atual</div>
                <img src='<?= htmlspecialchars($postInfo['featured_url']) ?>' style='width:140px;height:80px;object-fit:cover;border-radius:4px;margin-top:4px'>
              </div>
            <?php else: ?>
              <span class='tag red'>sem featured</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class='box'>
      <h2>2. Fonte da imagem</h2>
      <div class='fonte-bar'>
        <label class='fonte-radio <?= $fonte==='dalle'?'sel':'' ?> <?= $temDalle?'':'disabled' ?>'>
          <input type='radio' name='fonte' value='dalle' <?= $fonte==='dalle'?'checked':'' ?> <?= $temDalle?'':'disabled' ?> onchange='this.closest(".fonte-bar").querySelectorAll(".fonte-radio").forEach(r=>r.classList.remove("sel"));this.closest(".fonte-radio").classList.add("sel")'>
          <div>
            <strong>🎨 DALL-E 3 <?= $temDalle ? '' : '<span class="tag red">sem chave</span>' ?></strong>
            <span>Geração editorial. ~$0.04-0.12 por imagem HD. Prompt em inglês funciona melhor.</span>
          </div>
        </label>
        <label class='fonte-radio <?= $fonte==='pexels'?'sel':'' ?> <?= $temPexels?'':'disabled' ?>'>
          <input type='radio' name='fonte' value='pexels' <?= $fonte==='pexels'?'checked':'' ?> <?= $temPexels?'':'disabled' ?> onchange='this.closest(".fonte-bar").querySelectorAll(".fonte-radio").forEach(r=>r.classList.remove("sel"));this.closest(".fonte-radio").classList.add("sel")'>
          <div>
            <strong>📷 Pexels <?= $temPexels ? '' : '<span class="tag red">sem chave</span>' ?></strong>
            <span>Foto real grátis. Prompt vira query (em inglês 2-4 palavras: "soccer stadium fans").</span>
          </div>
        </label>
      </div>
    </div>

    <div class='box'>
      <h2>3. Prompt</h2>
      <label>Descreva a imagem (DALL-E) ou termo de busca (Pexels)</label>
      <textarea name='prompt' placeholder='Ex DALL-E: "Brazilian soccer fans cheering at stadium, golden hour, 16:9"&#10;Ex Pexels: "soccer stadium fans"'><?= htmlspecialchars($prompt) ?></textarea>

      <div class='row' style='margin-top:14px'>
        <div>
          <label>Tamanho (DALL-E only)</label>
          <select name='tamanho' style='width:200px'>
            <option value='1792x1024' <?= $tamanho==='1792x1024'?'selected':'' ?>>1792×1024 (16:9 paisagem)</option>
            <option value='1024x1024' <?= $tamanho==='1024x1024'?'selected':'' ?>>1024×1024 (quadrado)</option>
            <option value='1024x1792' <?= $tamanho==='1024x1792'?'selected':'' ?>>1024×1792 (retrato)</option>
          </select>
        </div>
        <button type='submit' class='danger' onclick='document.getElementById("faseInput").value="gerar"'>🚀 Gerar imagem</button>
      </div>
      <p class='hint'>Após gerar, conversão WebP via api.gogleads.com.br. O preview abaixo já mostra o WebP final.</p>
    </div>
  </form>

  <?php if ($preview): ?>
    <div class='box'>
      <h2>4. Preview <?= $preview['webp_ok'] ? '<span class="tag green">WebP OK</span>' : '<span class="tag red">WebP falhou — usando original</span>' ?></h2>
      <div class='preview-imgs'>
        <div class='col'>
          <h4>Imagem gerada (<?= htmlspecialchars($preview['fonte']) ?>)</h4>
          <img src='?preview=<?= htmlspecialchars($preview['cache_key']) ?>' alt='preview'>
        </div>
        <div class='col'>
          <h4>Metadata</h4>
          <div class='kv'>
            <b>Fonte</b><span><?= htmlspecialchars($preview['fonte']) ?></span>
            <b>Cache key</b><span><?= htmlspecialchars($preview['cache_key']) ?></span>
            <?php if ($preview['bytes_origem']): ?>
              <b>Tamanho original</b><span><?= number_format($preview['bytes_origem']/1024, 1) ?> KB</span>
            <?php endif; ?>
            <?php if ($preview['bytes_webp']): ?>
              <b>Tamanho WebP</b><span><?= number_format($preview['bytes_webp']/1024, 1) ?> KB
                <?php if ($preview['bytes_origem']): $r = round((1 - $preview['bytes_webp']/$preview['bytes_origem'])*100); ?>
                  <span style='color:#86efac'>(−<?= $r ?>%)</span>
                <?php endif; ?>
              </span>
            <?php endif; ?>
            <b>Prompt usado</b><span><?= htmlspecialchars($preview['prompt']) ?></span>
            <?php foreach ($preview['metadata'] as $k => $v): if ($v === null || $v === '' || (is_array($v) && empty($v))) continue; ?>
              <b><?= htmlspecialchars($k) ?></b><span><?= htmlspecialchars(is_array($v) ? json_encode($v) : (string)$v) ?></span>
            <?php endforeach; ?>
            <?php if ($preview['url_original']): ?>
              <b>URL original</b><span><a href='<?= htmlspecialchars($preview['url_original']) ?>' target='_blank'>abrir ↗</a></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if (!$aplicado): ?>
        <form method='POST' style='margin-top:18px'>
          <input type='hidden' name='fase' value='aplicar'>
          <input type='hidden' name='site' value='<?= htmlspecialchars($siteSlug) ?>'>
          <input type='hidden' name='post_id' value='<?= (int)$postId ?>'>
          <input type='hidden' name='cache_key' value='<?= htmlspecialchars($preview['cache_key']) ?>'>
          <input type='hidden' name='fonte' value='<?= htmlspecialchars($preview['fonte']) ?>'>
          <input type='hidden' name='prompt' value='<?= htmlspecialchars($preview['prompt']) ?>'>
          <button type='submit' class='danger'>📌 Aplicar como destacada do post #<?= (int)$postId ?></button>
        </form>
      <?php else: ?>
        <div class='ok' style='margin-top:18px'>
          ✓ Aplicada com sucesso. Media #<?= $aplicado['media_id'] ?>.
          <?php if ($aplicado['source_url']): ?>
            <br>URL: <a href='<?= htmlspecialchars($aplicado['source_url']) ?>' target='_blank'><?= htmlspecialchars($aplicado['source_url']) ?></a>
          <?php endif; ?>
          <br><a href='<?= htmlspecialchars($aplicado['edit_url']) ?>' target='_blank'>Editar post no WP →</a>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script>
function selecionarPost(id){
  document.getElementById('postIdInput').value = id;
  document.querySelectorAll('.post-row').forEach(r=>r.classList.remove('sel'));
  event.currentTarget.classList.add('sel');
}
function faseSubmit(fase){
  document.getElementById('faseInput').value = fase;
  document.getElementById('formGerar').submit();
}
</script>
</body>
</html>
