<?php
/**
 * YouTube → Artigo → WordPress
 *
 * Pipeline:
 *  1. Transcreve vídeo do YouTube (legendas)
 *  2. Claude transforma em artigo (formatos: SEO/Discover/News/SERP)
 *  3. LandingBuilder monta HTML + schemas
 *  4. Publica como draft no WP + interliga
 *
 * Aceita múltiplos vídeos (um por linha).
 */
require_once __DIR__ . '/lib/Serper.php';
require_once __DIR__ . '/lib/Scraper.php';
require_once __DIR__ . '/lib/Claude.php';
require_once __DIR__ . '/lib/Wordpress.php';
require_once __DIR__ . '/lib/Maquina.php';
require_once __DIR__ . '/lib/LandingBuilder.php';
require_once __DIR__ . '/lib/YouTube.php';

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

$resultados = [];
$erro = null;
$processado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $processado = true;
    set_time_limit(0);

    $urlsRaw  = trim($_POST['urls'] ?? '');
    $keyword  = trim($_POST['keyword'] ?? '');
    $tipo     = $_POST['tipo'] ?? 'post';
    $formatos = $_POST['formatos'] ?? ['seo'];
    $blocos   = [];
    for ($i = 1; $i <= 8; $i++) $blocos[] = trim($_POST["bloco{$i}"] ?? '');

    if ($urlsRaw === '') {
        $erro = 'Cole pelo menos uma URL de vídeo do YouTube.';
    } else {
        $yt      = new YouTube($cfg['user_agent'], $cfg['scrape_timeout']);
        $claude  = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model']);
        $wp      = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        $builder = new LandingBuilder($cfg['site_name'] ?? 'Como Comprar', $cfg['wp_url'] ?? '', ['number' => $cfg['whatsapp_number'] ?? '', 'group_url' => $cfg['whatsapp_group_url'] ?? '', 'cta_text' => $cfg['whatsapp_cta_text'] ?? '']);

        $urls = array_filter(array_map('trim', preg_split('/\r?\n/', $urlsRaw)));

        foreach ($urls as $idx => $url) {
            $r = ['url' => $url, 'ok' => false, 'msg' => '', 'id' => null, 'titulo' => '', 'edit' => '', 'words' => 0];

            try {
                // 1. Transcreve
                $trans = $yt->transcrever($url);
                $r['titulo'] = $trans['title'];
                $r['words'] = $trans['word_count'];

                if ($trans['word_count'] < 50) throw new RuntimeException("Transcrição muito curta ({$trans['word_count']} palavras)");

                // 2. Monta briefing da transcrição como "fonte"
                $searchKeyword = $keyword !== '' ? $keyword : $trans['title'];

                // Trunca transcrição pra não estourar tokens (~4000 palavras max)
                $transcriptText = $trans['transcript'];
                if (str_word_count($transcriptText) > 4000) {
                    $words = explode(' ', $transcriptText);
                    $transcriptText = implode(' ', array_slice($words, 0, 4000));
                }

                // Cria fonte fake pro Claude
                $fontes = [[
                    'meta' => [
                        'url' => $url,
                        'title' => $trans['title'],
                        'description' => "Transcrição do vídeo: " . mb_substr($transcriptText, 0, 200),
                        'og_image' => "https://img.youtube.com/vi/{$trans['video_id']}/maxresdefault.jpg",
                        'site_name' => 'YouTube',
                        'author' => null,
                        'published' => null,
                        'jsonld' => [],
                    ],
                    'content' => [
                        'headings' => [['tag' => 'h1', 'text' => $trans['title']]],
                        'paragraphs' => str_split($transcriptText, 500),
                        'lists' => [],
                        'images' => [['src' => "https://img.youtube.com/vi/{$trans['video_id']}/maxresdefault.jpg", 'alt' => $trans['title']]],
                    ],
                ]];

                // 3. Gera artigo
                if ($tipo === 'page') {
                    $landing = $claude->gerarLanding($searchKeyword, [], $fontes, $blocos);
                    $content = $builder->buildHtml($landing);
                    $content .= $builder->buildFaqHtml($landing['faq'] ?? []);
                    $content .= $builder->buildSchemas($landing);

                    // Embed do vídeo no topo
                    $embed = "<div style=\"position:relative;padding-bottom:56.25%;height:0;overflow:hidden;margin:0 0 1.5rem;border-radius:var(--cc-radius)\"><iframe src=\"https://www.youtube.com/embed/{$trans['video_id']}\" style=\"position:absolute;top:0;left:0;width:100%;height:100%;border:none\" allowfullscreen loading=\"lazy\"></iframe></div>";
                    $content = $embed . $content;

                    $titulo = $landing['title'] ?? $searchKeyword;
                    $payload = ['title' => $titulo, 'slug' => $landing['slug'] ?? null, 'content' => $content, 'excerpt' => $landing['excerpt'] ?? '', 'status' => 'draft',
                        'meta' => ['rank_math_title' => $landing['meta_title'] ?? $titulo, 'rank_math_description' => $landing['meta_description'] ?? '', 'rank_math_focus_keyword' => $landing['focus_keyword'] ?? $searchKeyword]];
                    $post = $wp->criarPagina($payload);
                    $r['id'] = $post['id'] ?? null;
                    $r['titulo'] = $titulo;
                    $r['edit'] = rtrim($cfg['wp_url'], '/') . "/wp-admin/post.php?post={$r['id']}&action=edit";

                } else {
                    // Post via pipeline normal
                    $serper = new Serper($cfg['serper_api_key']);
                    $scraper = new Scraper($cfg['user_agent'], $cfg['scrape_timeout']);
                    $maq = new Maquina($serper, $scraper, $claude, $wp, $cfg);

                    // Injeta transcrição como fonte extra via URLs vazias (fontes já carregadas)
                    // Hack: usa gerarPost direto com fontes da transcrição
                    foreach ($formatos as $fmt) {
                        $artigo = $claude->gerarPost($searchKeyword, $fontes, $fmt, $blocos);

                        $hasProducts = !empty($artigo['products']);
                        if ($hasProducts) {
                            // Upload imagens
                            foreach ($artigo['products'] as &$prod) {
                                $imgUrl = $prod['image'] ?? '';
                                if ($imgUrl && preg_match('#^https?://#', $imgUrl)) {
                                    try { $mid = $wp->uploadImagemPorUrl($imgUrl, $prod['name'] ?? ''); if ($mid) { $media = $wp->getMedia($mid); $prod['image'] = $media['source_url'] ?? $imgUrl; $prod['wp_media_id'] = $mid; } } catch (Throwable $e) {}
                                }
                            }
                            unset($prod);
                            $contentFinal = $builder->buildHtml($artigo);
                        } else {
                            $contentFinal = $artigo['content_html'] ?? '';
                        }

                        // Embed do vídeo
                        $embed = "<div style=\"position:relative;padding-bottom:56.25%;height:0;overflow:hidden;margin:0 0 1.5rem;border-radius:var(--cc-radius)\"><iframe src=\"https://www.youtube.com/embed/{$trans['video_id']}\" style=\"position:absolute;top:0;left:0;width:100%;height:100%;border:none\" allowfullscreen loading=\"lazy\"></iframe></div>";
                        $contentFinal = $embed . $contentFinal;

                        // Relacionados
                        try {
                            $relacionados = $wp->buscarRelacionados($searchKeyword, 6);
                            if (!empty($relacionados)) {
                                $relHtml = '<h2>Leia também</h2>';
                                foreach ($relacionados as $rel) {
                                    $t = htmlspecialchars($rel['title']); $l = htmlspecialchars($rel['link']); $img = htmlspecialchars($rel['image']);
                                    $imgTag = $img !== '' ? "<img width=\"300\" height=\"225\" src=\"{$img}\" alt=\"{$t}\" loading=\"lazy\" decoding=\"async\">" : '';
                                    $relHtml .= "<article class=\"cc-bento__side cc-card cc-card--horizontal cc-fade-in is-visible\"><a href=\"{$l}\" class=\"cc-card__thumb\" aria-hidden=\"true\" tabindex=\"-1\">{$imgTag}</a><div class=\"cc-card__body\"><h3 class=\"cc-card__title\"><a href=\"{$l}\">{$t}</a></h3></div></article>";
                                }
                                $contentFinal .= $relHtml;
                            }
                        } catch (Throwable $e) {}

                        // FAQ
                        $contentFinal .= $builder->buildFaqHtml($artigo['faq'] ?? []);
                        if ($hasProducts) $contentFinal .= $builder->buildSchemas($artigo);

                        // Categorias/tags
                        $catIds = []; $tagIds = [];
                        if (!empty($artigo['categories'])) try { $catIds = $wp->resolverCategorias($artigo['categories']); } catch (Throwable $e) {}
                        if (!empty($artigo['tags'])) try { $tagIds = $wp->resolverTags($artigo['tags']); } catch (Throwable $e) {}

                        // Featured image (thumb do YouTube)
                        $featuredId = null;
                        foreach (($artigo['products'] ?? []) as $lp) { if (!empty($lp['wp_media_id'])) { $featuredId = $lp['wp_media_id']; break; } }
                        if (!$featuredId) {
                            try { $featuredId = $wp->uploadImagemPorUrl("https://img.youtube.com/vi/{$trans['video_id']}/maxresdefault.jpg", $trans['title']); } catch (Throwable $e) {}
                        }

                        $titulo = $artigo['title'] ?? $searchKeyword;
                        $slug = ($artigo['slug'] ?? '') . (count($formatos) > 1 ? "-{$fmt}" : '');
                        $payload = ['title' => $titulo, 'slug' => $slug, 'content' => $contentFinal, 'excerpt' => $artigo['excerpt'] ?? '', 'status' => 'draft', 'categories' => $catIds, 'tags' => $tagIds,
                            'meta' => ['rank_math_title' => $artigo['meta_title'] ?? $titulo, 'rank_math_description' => $artigo['meta_description'] ?? '', 'rank_math_focus_keyword' => $artigo['focus_keyword'] ?? $searchKeyword]];
                        if ($featuredId) $payload['featured_media'] = $featuredId;

                        $post = $wp->criarPost($payload);
                        $r['id'] = $post['id'] ?? null;
                        $r['titulo'] = $titulo;
                        $r['edit'] = rtrim($cfg['wp_url'], '/') . "/wp-admin/post.php?post={$r['id']}&action=edit";
                    }
                }

                $r['ok'] = true;
                $r['msg'] = "#{$r['id']} criado — {$trans['word_count']} palavras transcritas";

            } catch (Throwable $e) {
                $r['msg'] = $e->getMessage();
            }

            $resultados[] = $r;
            if ($idx < count($urls) - 1) sleep(3);
        }
    }
}

$fmtInfo = Claude::$formatos;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>YouTube → Artigo → WordPress</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:960px;margin:0 auto}
h1{color:#fff;margin:0 0 4px}
.sub{color:#666;margin-bottom:20px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:24px;border-radius:10px;margin-bottom:16px}
.box h2{margin-top:0;font-size:18px;color:#e0e0e0}
label{display:block;font-weight:bold;margin:10px 0 6px;font-size:13px;color:#bbb}
input[type=text],textarea{width:100%;padding:12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#ddd;font-size:14px;font-family:inherit}
textarea{min-height:120px;resize:vertical;font-family:'JetBrains Mono',monospace;font-size:13px}
input:focus,textarea:focus{outline:none;border-color:#ef4444}
select{padding:10px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#ddd;font-size:14px}
.row{display:flex;gap:14px;flex-wrap:wrap;align-items:end}
.row>div{flex:1;min-width:150px}
button[type=submit]{margin-top:16px;padding:14px 28px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:bold;cursor:pointer;width:100%}
button[type=submit]:hover{opacity:0.9}
.erro{background:#3b1818;border-left:4px solid #ef4444;padding:14px;border-radius:6px;margin-bottom:16px;color:#fca5a5}
.result{background:#111318;border:1px solid #2a2e38;border-radius:8px;padding:12px 16px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center}
.result.ok{border-left:4px solid #22c55e}
.result.fail{border-left:4px solid #ef4444}
.result-info{flex:1}
.result-url{font-size:12px;color:#666;word-break:break-all}
.result-title{font-weight:700;color:#e0e0e0;font-size:14px;margin-top:2px}
.result-msg{font-size:12px;color:#888;margin-top:2px}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
.hint{font-size:11px;color:#444;margin-top:4px}
.formatos-bar{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
.fmt-check{display:flex;align-items:center;gap:6px;background:#0f1115;border:2px solid #2a2e38;border-radius:8px;padding:8px 14px;cursor:pointer}
.fmt-check input{accent-color:#ef4444}
.fmt-check span{font-size:13px;color:#888;font-weight:600}
.blocos{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}
.blocos textarea{min-height:60px}
.blocos label{margin:0 0 4px}
</style>
</head>
<body>
<div class="container">
  <h1>▶️ YouTube → Artigo → WordPress</h1>
  <p class="sub">Transcreve vídeos, gera artigos otimizados e publica como draft. Tudo automático.</p>

  <?php if ($erro): ?>
    <div class="erro"><?= htmlspecialchars($erro) ?></div>
  <?php endif; ?>

  <?php if ($processado && !$erro): ?>
    <div class="box">
      <h2>Resultado</h2>
      <?php foreach ($resultados as $r): ?>
        <div class="result <?= $r['ok'] ? 'ok' : 'fail' ?>">
          <div class="result-info">
            <div class="result-url"><?= htmlspecialchars($r['url']) ?></div>
            <?php if ($r['titulo']): ?><div class="result-title"><?= htmlspecialchars($r['titulo']) ?></div><?php endif; ?>
            <div class="result-msg"><?= htmlspecialchars($r['msg']) ?></div>
          </div>
          <?php if ($r['ok'] && $r['edit']): ?>
            <a href="<?= htmlspecialchars($r['edit']) ?>" target="_blank" style="font-size:13px;flex-shrink:0">Editar →</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="POST">
    <?php include __DIR__ . '/_site_select.php'; ?>
    <div class="box">
      <h2>Vídeos do YouTube</h2>
      <textarea name="urls" placeholder="https://www.youtube.com/watch?v=xxxxx
https://youtu.be/yyyyy
https://www.youtube.com/watch?v=zzzzz"><?= htmlspecialchars($_POST['urls'] ?? '') ?></textarea>
      <p class="hint">Uma URL por linha. Aceita youtube.com/watch, youtu.be, embed.</p>
    </div>

    <div class="box">
      <h2>Configuração</h2>
      <div class="row">
        <div>
          <label>Keyword (opcional)</label>
          <input type="text" name="keyword" placeholder="Se vazio, usa o título do vídeo" value="<?= htmlspecialchars($_POST['keyword'] ?? '') ?>">
        </div>
        <div>
          <label>Tipo</label>
          <select name="tipo">
            <option value="post" <?= ($_POST['tipo'] ?? '') === 'post' ? 'selected' : '' ?>>Post (artigo)</option>
            <option value="page" <?= ($_POST['tipo'] ?? '') === 'page' ? 'selected' : '' ?>>Página (landing)</option>
          </select>
        </div>
      </div>
      <label style="margin-top:14px">Formatos</label>
      <div class="formatos-bar">
        <?php foreach ($fmtInfo as $key => $f): ?>
          <label class="fmt-check">
            <input type="checkbox" name="formatos[]" value="<?= $key ?>" <?= in_array($key, $_POST['formatos'] ?? ['seo']) ? 'checked' : '' ?>>
            <span><?= $f['nome'] ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <?php include __DIR__ . '/_blocos_inputs.php'; ?>

    <button type="submit">▶️ Transcrever e gerar artigos</button>
    <p class="hint" style="text-align:center;margin-top:8px">~60-120s por vídeo: transcrição + Claude + upload + WP</p>
  </form>

  <p style="text-align:center;color:#333;font-size:12px;margin-top:20px">
    <a href="maquina.php">Máquina</a> · <a href="landing.php">Landing</a> · <a href="massa.php">Em massa</a>
  </p>
</div>
</body>
</html>
