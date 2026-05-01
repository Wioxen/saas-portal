<?php
/**
 * afiliados.php — UI de gerenciamento do catálogo de ofertas de afiliado.
 *
 * Features:
 *   - Lista ofertas com cluster, ROI estimado, cliques 7d, ativo/inativo
 *   - Form adicionar/editar com todos os campos
 *   - Testar match contra termo arbitrário
 *   - Link pra afiliados.php?acao=cliques&slug=X ver detalhes de tráfego
 */

require_once __DIR__ . '/lib/DiscoverAfiliados.php';
require_once __DIR__ . '/lib/TrendsTaxonomia.php';

$cfg = require __DIR__ . '/config.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
$msg  = '';
$erro = '';

// ─── Handlers POST ───────────────────────────────────────────────────
if ($acao === 'adicionar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $kw = array_values(array_filter(array_map('trim', explode(',', $_POST['keywords_match'] ?? ''))));
        $dor = array_values(array_filter((array)($_POST['dor_alvo'] ?? [])));
        if (!$dor) $dor = ['qualquer'];
        $sitesOferta = array_values(array_filter((array)($_POST['sites'] ?? [])));
        $r = DiscoverAfiliados::adicionar([
            'nome'            => $_POST['nome'] ?? '',
            'slug'            => $_POST['slug'] ?? '',
            'descricao_curta' => $_POST['descricao_curta'] ?? '',
            'url_afiliado'    => $_POST['url_afiliado'] ?? '',
            'plataforma'      => $_POST['plataforma'] ?? 'manual',
            'cluster'         => $_POST['cluster'] ?? 'curiosidades_geral',
            'dor_alvo'        => $dor,
            'sites'           => $sitesOferta,  // vazio = todos sites
            'keywords_match'  => $kw,
            'cta_texto'       => $_POST['cta_texto'] ?? 'Ver oferta',
            'cta_emoji'       => $_POST['cta_emoji'] ?? '👉',
            'comissao_pct'    => (float)($_POST['comissao_pct'] ?? 0),
            'ticket_medio_brl'=> (float)($_POST['ticket_medio_brl'] ?? 0),
            'ativo'           => !empty($_POST['ativo']),
        ]);
        header('Location: afiliados.php?msg=adicionado:' . urlencode($r['slug'])); exit;
    } catch (Throwable $e) {
        $erro = 'Erro ao adicionar: ' . $e->getMessage();
    }
}

if ($acao === 'atualizar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        $kw = array_values(array_filter(array_map('trim', explode(',', $_POST['keywords_match'] ?? ''))));
        $dor = array_values(array_filter((array)($_POST['dor_alvo'] ?? [])));
        if (!$dor) $dor = ['qualquer'];
        $sitesOferta = array_values(array_filter((array)($_POST['sites'] ?? [])));
        DiscoverAfiliados::atualizar($id, [
            'nome'            => $_POST['nome'] ?? '',
            'descricao_curta' => $_POST['descricao_curta'] ?? '',
            'url_afiliado'    => $_POST['url_afiliado'] ?? '',
            'plataforma'      => $_POST['plataforma'] ?? 'manual',
            'cluster'         => $_POST['cluster'] ?? 'curiosidades_geral',
            'dor_alvo'        => $dor,
            'sites'           => $sitesOferta,
            'keywords_match'  => $kw,
            'cta_texto'       => $_POST['cta_texto'] ?? 'Ver oferta',
            'cta_emoji'       => $_POST['cta_emoji'] ?? '👉',
            'comissao_pct'    => (float)($_POST['comissao_pct'] ?? 0),
            'ticket_medio_brl'=> (float)($_POST['ticket_medio_brl'] ?? 0),
            'ativo'           => !empty($_POST['ativo']),
        ]);
        header('Location: afiliados.php?msg=atualizado'); exit;
    } catch (Throwable $e) {
        $erro = 'Erro ao atualizar: ' . $e->getMessage();
    }
}

if ($acao === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $o = DiscoverAfiliados::porId($id);
    if ($o) {
        DiscoverAfiliados::atualizar($id, ['ativo' => !$o['ativo']]);
    }
    header('Location: afiliados.php?msg=toggled'); exit;
}

if ($acao === 'remover' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    DiscoverAfiliados::remover((int)($_POST['id'] ?? 0));
    header('Location: afiliados.php?msg=removido'); exit;
}

if ($acao === 'testar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $termo = trim((string)($_POST['termo'] ?? ''));
    $clusterKey = (string)($_POST['cluster_key'] ?? '');
    $dor = (string)($_POST['dor'] ?? '');
    $relacionados = array_values(array_filter(array_map('trim', explode(',', $_POST['relacionados'] ?? ''))));
    $_teste = DiscoverAfiliados::matchear([
        'termo' => $termo,
        'cluster_detect' => ['key' => $clusterKey],
        'pain' => ['dominante' => $dor],
        'relacionados' => $relacionados,
    ]);
    // Cai no render normal com resultado disponível
}

$ofertas = DiscoverAfiliados::listar();
$cliques7d = DiscoverAfiliados::cliquesPorOferta(7);
$editando = null;
if ($acao === 'editar') {
    $editando = DiscoverAfiliados::porId((int)($_GET['id'] ?? 0));
}

$mensagemTop = '';
if (!empty($_GET['msg'])) {
    $m = (string)$_GET['msg'];
    if (str_starts_with($m, 'adicionado:')) $mensagemTop = 'Oferta adicionada: ' . substr($m, 11);
    elseif ($m === 'atualizado') $mensagemTop = 'Oferta atualizada.';
    elseif ($m === 'removido')   $mensagemTop = 'Oferta removida.';
    elseif ($m === 'toggled')    $mensagemTop = 'Status alterado.';
}
?><!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Afiliados — Portal Discover</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:#0f1115;color:#e2e8f0;line-height:1.5}
.container{max-width:1180px;margin:0 auto;padding:20px}
h1{color:#a78bfa;margin:0 0 4px}
.muted{color:#6b7280;font-size:12px}
.box{background:#13161d;border:1px solid #1f232b;border-radius:10px;padding:18px;margin:18px 0}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:8px 10px;text-align:left;border-bottom:1px solid #1f232b;font-size:13px;vertical-align:top}
th{background:#0b0d12;color:#a78bfa;font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:700}
tr.inativa{opacity:.5}
.badge{display:inline-block;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700}
.b-ativo{background:#14532d;color:#86efac}
.b-inativo{background:#292524;color:#78716c}
.b-platf{background:#1e293b;color:#93c5fd}
.b-cluster{background:#1e1b4b;color:#a5b4fc}
.b-cliques{background:#78350f;color:#fcd34d;font-family:monospace}
input,select,textarea{background:#0f1115;border:1px solid #2a2e38;color:#e2e8f0;padding:8px 10px;border-radius:6px;font-size:13px;font-family:inherit;width:100%}
input:focus,select:focus,textarea:focus{outline:none;border-color:#a78bfa}
label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#a78bfa;font-weight:700;margin-bottom:4px;margin-top:10px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:768px){.grid{grid-template-columns:1fr}}
.btn{display:inline-block;padding:8px 14px;background:#7c3aed;color:#fff;border:none;border-radius:6px;font-weight:700;cursor:pointer;font-size:13px;text-decoration:none}
.btn:hover{background:#6d28d9}
.btn-sm{padding:4px 8px;font-size:11px;border-radius:4px}
.btn-secondary{background:#1e293b;color:#93c5fd}
.btn-secondary:hover{background:#334155}
.btn-danger{background:#450a0a;color:#fca5a5;border:1px solid #991b1b}
.btn-danger:hover{background:#991b1b;color:#fff}
.msg-ok{padding:10px 14px;background:#064e3b;color:#86efac;border-radius:6px;margin-bottom:14px}
.msg-erro{padding:10px 14px;background:#7f1d1d;color:#fecaca;border-radius:6px;margin-bottom:14px}
.nav a{color:#a78bfa;text-decoration:none;margin-right:18px;font-size:13px}
.nav a:hover{color:#c4b5fd}
.test-result{background:#0b0d12;border-left:3px solid #22c55e;padding:12px;margin-top:10px;font-size:13px}
.test-result.none{border-left-color:#ef4444}
.chip-dor{display:inline-block;margin:0 4px 4px 0;padding:2px 8px;background:#1f1f1f;color:#cbd5e1;border-radius:4px;font-size:11px}
.checkbox-row{display:flex;align-items:center;gap:6px;margin-top:10px}
.checkbox-row input{width:auto}
.checkbox-row label{display:inline;margin:0;text-transform:none;letter-spacing:normal;color:#cbd5e1;font-size:13px;font-weight:400}
</style>
</head>
<body>
<div class="container">
  <div class="nav">
    <a href="portal.php">← Voltar ao portal</a>
    <a href="afiliados.php">🎯 Afiliados</a>
    <a href="pingo.php">📡 Pingo</a>
    <?php if (!$editando): ?>
      <a href="#form-add" style="color:#4ade80">+ Nova oferta</a>
    <?php endif; ?>
  </div>

  <h1>🎯 Catálogo de ofertas de afiliado</h1>
  <p class="muted"><?= count($ofertas) ?> ofertas · <?= count(array_filter($ofertas, fn($o) => $o['ativo'])) ?> ativas · <?= array_sum($cliques7d) ?> cliques nos últimos 7 dias</p>

  <!-- ═══ COMO FUNCIONA (instruções críticas) ═══ -->
  <div class="box" style="background:#0b1a0f;border:1px solid #16a34a">
    <h2 style="margin:0 0 10px;color:#4ade80;font-size:15px">⚙️ Como funciona o fluxo de afiliado</h2>
    <div style="font-size:13px;color:#d1d5db;line-height:1.7">
      <strong>1. URL de afiliado (neste form):</strong> cole a URL REAL que você recebe do parceiro (Hotmart, Amazon, Awin, Credfácil, BV, etc.). Ex: <code style="font-size:11px;color:#fbbf24">https://hotmart.com/produto/xyz?a=SEU_CODIGO</code>
      <br><br>
      <strong>2. Pretty Link no WP:</strong> nosso sistema gera links nos artigos como <code style="font-size:11px;color:#60a5fa"><?= h(rtrim($cfg['wp_url'], '/') . '/' . ($cfg['pretty_links_prefix'] ?? 'go')) ?>/SLUG</code>. Pra isso funcionar, você precisa criar UM Pretty Link por oferta no plugin Pretty Links do WP:
      <ol style="margin:8px 0;padding-left:22px">
        <li>Vá em <a href="<?= h(rtrim($cfg['wp_url'], '/')) ?>/wp-admin/admin.php?page=prli-add-new" target="_blank" style="color:#60a5fa">wp-admin → Pretty Links → Add New</a></li>
        <li>Target URL: <em>(a URL de afiliado que você cadastrou aqui)</em></li>
        <li>Pretty Link: <code style="font-size:11px"><?= h(($cfg['pretty_links_prefix'] ?? 'go')) ?>/SLUG</code> (use o MESMO slug cadastrado aqui abaixo)</li>
        <li>Save — plugin conta cliques nativamente</li>
      </ol>
      <strong>3. Ciclo completo:</strong> leitor clica no bloco do artigo → Pretty Link redireciona (com rastreamento) → URL real de afiliado → parceiro paga comissão.
    </div>
  </div>

  <?php if ($mensagemTop): ?><div class="msg-ok">✓ <?= h($mensagemTop) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="msg-erro">✗ <?= h($erro) ?></div><?php endif; ?>

  <!-- ═══ LISTA DE OFERTAS ═══ -->
  <div class="box">
    <h2 style="margin:0 0 10px;color:#e2e8f0;font-size:16px">Ofertas cadastradas</h2>
    <?php if (empty($ofertas)): ?>
      <p class="muted">Nenhuma oferta ainda. Cadastre abaixo.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th>ID</th><th>Oferta</th><th>Cluster</th><th>Plataforma</th><th>Comissão</th>
        <th>Cliques 7d</th><th>Status</th><th>Ações</th>
      </tr></thead>
      <tbody>
        <?php foreach ($ofertas as $o):
          $n = $cliques7d[$o['slug']] ?? 0;
          $roiOferta = $o['ticket_medio_brl'] > 0 && $o['comissao_pct'] > 0
              ? round($o['ticket_medio_brl'] * $o['comissao_pct'] / 100, 2)
              : null;
        ?>
        <tr class="<?= $o['ativo'] ? '' : 'inativa' ?>">
          <td class="muted">#<?= (int)$o['id'] ?></td>
          <td>
            <strong><?= $o['cta_emoji'] ?? '' ?> <?= h($o['nome']) ?></strong>
            <div class="muted" style="margin-top:3px"><?= h(mb_substr($o['descricao_curta'] ?? '', 0, 80)) ?><?= mb_strlen($o['descricao_curta'] ?? '') > 80 ? '...' : '' ?></div>
            <?php $prettyUrl = rtrim($cfg['wp_url'], '/') . '/' . ($cfg['pretty_links_prefix'] ?? 'go') . '/' . $o['slug']; ?>
            <div class="muted" style="margin-top:4px;font-size:11px">
              <span style="color:#94a3b8">Pretty Link:</span>
              <a href="<?= h($prettyUrl) ?>" target="_blank" style="color:#60a5fa"><?= h($prettyUrl) ?></a>
            </div>
            <div class="muted" style="margin-top:2px;font-size:10px">
              <span style="color:#94a3b8">URL real:</span>
              <code style="font-size:10px;color:#fbbf24"><?= h(mb_substr($o['url_afiliado'] ?? '', 0, 60)) ?><?= mb_strlen($o['url_afiliado'] ?? '') > 60 ? '…' : '' ?></code>
            </div>
          </td>
          <td>
            <span class="badge b-cluster"><?= h(TrendsTaxonomia::labelCurto($o['cluster'])) ?></span>
            <div class="muted" style="margin-top:3px">dor: <?= h(implode(', ', $o['dor_alvo'] ?? [])) ?></div>
            <?php $sitesO = $o['sites'] ?? []; ?>
            <div class="muted" style="margin-top:3px;font-size:10px">
              sites: <?= empty($sitesO) ? '<em>todos</em>' : h(implode(', ', $sitesO)) ?>
            </div>
          </td>
          <td><span class="badge b-platf"><?= h($o['plataforma']) ?></span></td>
          <td>
            <?php if ($o['comissao_pct'] > 0): ?>
              <strong><?= number_format($o['comissao_pct'], 1, ',', '') ?>%</strong>
              <?php if ($roiOferta !== null): ?>
                <div class="muted" style="margin-top:3px">≈ R$ <?= number_format($roiOferta, 2, ',', '.') ?>/conv</div>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td><span class="badge b-cliques"><?= $n ?></span></td>
          <td><span class="badge <?= $o['ativo'] ? 'b-ativo' : 'b-inativo' ?>"><?= $o['ativo'] ? 'Ativa' : 'Inativa' ?></span></td>
          <td style="white-space:nowrap">
            <a href="afiliados.php?acao=editar&id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-secondary">✏️</a>
            <form method="POST" style="display:inline">
              <input type="hidden" name="acao" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <button class="btn btn-sm btn-secondary" title="<?= $o['ativo'] ? 'Desativar' : 'Ativar' ?>"><?= $o['ativo'] ? '⏸' : '▶' ?></button>
            </form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Remover <?= h($o['nome']) ?>?')">
              <input type="hidden" name="acao" value="remover">
              <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <button class="btn btn-sm btn-danger">🗑</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ═══ FORM ADICIONAR / EDITAR ═══ -->
  <div class="box" id="form-add">
    <h2 style="margin:0 0 14px;color:#e2e8f0;font-size:16px"><?= $editando ? '✏️ Editar oferta #' . (int)$editando['id'] : '➕ Nova oferta' ?></h2>
    <form method="POST">
      <input type="hidden" name="acao" value="<?= $editando ? 'atualizar' : 'adicionar' ?>">
      <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int)$editando['id'] ?>"><?php endif; ?>

      <div class="grid">
        <div>
          <label>Nome da oferta *</label>
          <input name="nome" required maxlength="80" value="<?= h($editando['nome'] ?? '') ?>" placeholder="ex: Curso preparatório concurso INSS">
        </div>
        <div>
          <label>Slug (vira /<?= h($cfg['pretty_links_prefix'] ?? 'go') ?>/X no Pretty Links) <?= $editando ? '' : '— gerado automaticamente se vazio' ?></label>
          <input name="slug" maxlength="60" value="<?= h($editando['slug'] ?? '') ?>" <?= $editando ? 'readonly' : '' ?> placeholder="curso-concurso-inss" pattern="[a-z0-9-]+" title="Apenas [a-z0-9-]">
        </div>
      </div>

      <label>Descrição curta (aparece no bloco CTA — máx 120 chars)</label>
      <input name="descricao_curta" maxlength="120" value="<?= h($editando['descricao_curta'] ?? '') ?>" placeholder="ex: Reposição grátis se não passar no primeiro edital.">

      <label>URL de afiliado (destino real)</label>
      <input name="url_afiliado" type="url" required value="<?= h($editando['url_afiliado'] ?? '') ?>" placeholder="https://hotmart.com/...?a=...">

      <div class="grid">
        <div>
          <label>Plataforma</label>
          <select name="plataforma">
            <?php foreach (['amazon','hotmart','shopee','mercado_livre','awin','manual'] as $p): ?>
              <option value="<?= $p ?>" <?= ($editando['plataforma'] ?? '') === $p ? 'selected' : '' ?>><?= $p ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Cluster preferencial</label>
          <select name="cluster">
            <?php foreach (TrendsTaxonomia::chaves() as $ck): ?>
              <option value="<?= h($ck) ?>" <?= ($editando['cluster'] ?? 'curiosidades_geral') === $ck ? 'selected' : '' ?>>
                <?= TrendsTaxonomia::emojiRoi($ck) ?> <?= h(TrendsTaxonomia::labelCurto($ck)) ?> (RPM R$<?= TrendsTaxonomia::rpm($ck) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <label>Sites elegíveis — marca onde esta oferta pode aparecer (nenhum = todos)</label>
      <div>
        <?php
          require_once __DIR__ . '/_site_helper.php';
          $todosSites = sitesDisponiveis();
          $sitesAtivos = (array)($editando['sites'] ?? []);
          foreach ($todosSites as $slugSite => $infoSite):
            $checked = in_array($slugSite, $sitesAtivos, true);
        ?>
          <label class="chip-dor" style="cursor:pointer;<?= $checked ? 'background:#1e3a8a;color:#93c5fd' : '' ?>">
            <input type="checkbox" name="sites[]" value="<?= h($slugSite) ?>" <?= $checked ? 'checked' : '' ?> style="width:auto;margin-right:4px"> <?= h($infoSite['name'] ?? $slugSite) ?>
          </label>
        <?php endforeach; ?>
        <div class="muted" style="font-size:10px;margin-top:4px">Se nenhum for marcado, a oferta pode aparecer em artigos de qualquer site.</div>
      </div>

      <label>Dor(es) alvo — marca todas que se aplicam</label>
      <div>
        <?php foreach (['urgencia','medo','dinheiro','oportunidade','qualquer'] as $d):
          $checked = in_array($d, $editando['dor_alvo'] ?? [], true);
        ?>
          <label class="chip-dor" style="cursor:pointer;<?= $checked ? 'background:#14532d;color:#86efac' : '' ?>">
            <input type="checkbox" name="dor_alvo[]" value="<?= $d ?>" <?= $checked ? 'checked' : '' ?> style="width:auto;margin-right:4px"> <?= $d ?>
          </label>
        <?php endforeach; ?>
      </div>

      <label>Keywords (vírgula separa — reforçam match quando aparecem no termo)</label>
      <input name="keywords_match" value="<?= h(implode(', ', $editando['keywords_match'] ?? [])) ?>" placeholder="ex: inss, aposentado, consignado, crédito, fgts">

      <div class="grid">
        <div>
          <label>Texto do botão CTA</label>
          <input name="cta_texto" maxlength="40" value="<?= h($editando['cta_texto'] ?? 'Ver oferta') ?>">
        </div>
        <div>
          <label>Emoji CTA</label>
          <input name="cta_emoji" maxlength="4" value="<?= h($editando['cta_emoji'] ?? '👉') ?>">
        </div>
      </div>

      <div class="grid">
        <div>
          <label>Comissão estimada (%)</label>
          <input type="number" step="0.1" min="0" max="100" name="comissao_pct" value="<?= (float)($editando['comissao_pct'] ?? 0) ?>">
        </div>
        <div>
          <label>Ticket médio (R$)</label>
          <input type="number" step="0.01" min="0" name="ticket_medio_brl" value="<?= (float)($editando['ticket_medio_brl'] ?? 0) ?>">
        </div>
      </div>

      <div class="checkbox-row">
        <input type="checkbox" name="ativo" id="ativo" <?= ($editando['ativo'] ?? true) ? 'checked' : '' ?>>
        <label for="ativo">Oferta ativa (senão não aparece em artigos novos)</label>
      </div>

      <div style="margin-top:16px">
        <button type="submit" class="btn"><?= $editando ? 'Salvar alterações' : '+ Cadastrar oferta' ?></button>
        <?php if ($editando): ?><a href="afiliados.php" class="btn btn-secondary">Cancelar</a><?php endif; ?>
      </div>
    </form>
  </div>

  <!-- ═══ TESTE DE MATCH ═══ -->
  <div class="box">
    <h2 style="margin:0 0 10px;color:#e2e8f0;font-size:16px">🧪 Testar matchmaker</h2>
    <p class="muted" style="margin:0 0 12px">Simula um trend e veja qual oferta ativa seria sugerida.</p>
    <form method="POST">
      <input type="hidden" name="acao" value="testar">
      <div class="grid">
        <div>
          <label>Termo do trend</label>
          <input name="termo" value="<?= h($_POST['termo'] ?? '') ?>" placeholder="ex: edital concurso inss 2026">
        </div>
        <div>
          <label>Cluster (opcional — simula detecção)</label>
          <select name="cluster_key">
            <option value="">(automático)</option>
            <?php foreach (TrendsTaxonomia::chaves() as $ck): ?>
              <option value="<?= h($ck) ?>" <?= ($_POST['cluster_key'] ?? '') === $ck ? 'selected' : '' ?>><?= h(TrendsTaxonomia::labelCurto($ck)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid">
        <div>
          <label>Dor dominante (opcional)</label>
          <select name="dor">
            <option value="">(nenhuma)</option>
            <?php foreach (['urgencia','medo','dinheiro','oportunidade'] as $d): ?>
              <option value="<?= $d ?>" <?= ($_POST['dor'] ?? '') === $d ? 'selected' : '' ?>><?= $d ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Relacionados (vírgula)</label>
          <input name="relacionados" value="<?= h($_POST['relacionados'] ?? '') ?>" placeholder="inscrição, vagas, prova">
        </div>
      </div>
      <button type="submit" class="btn btn-secondary" style="margin-top:12px">▶ Rodar match</button>
    </form>

    <?php if (isset($_teste)): ?>
      <?php if ($_teste === null): ?>
        <div class="test-result none"><strong>Nenhum match.</strong> Nenhuma oferta ativa atingiu o threshold mínimo (5). Considere adicionar keywords ou cadastrar oferta pra esse cluster.</div>
      <?php else: ?>
        <div class="test-result">
          <strong>Match:</strong> <?= h($_teste['oferta']['nome']) ?> (slug: <code><?= h($_teste['oferta']['slug']) ?></code>)<br>
          <strong>Score:</strong> <?= $_teste['score'] ?> · <strong>Motivos:</strong> <?= h(implode(', ', $_teste['motivos'])) ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
