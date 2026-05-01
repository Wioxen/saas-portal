<?php
/**
 * Cria/atualiza página "Critérios Editoriais" em cada um dos 6 sites WP.
 *
 * Slug da página: 'criterios-editoriais' (URL fica /criterios-editoriais/).
 * Idempotente: detecta se já existe (por slug) e atualiza; senão cria.
 *
 * Conteúdo é padronizado mas customizado por persona/especialidade do site
 * (extraído de sites.php). Reforça E-E-A-T pra Google Helpful Content.
 *
 * Uso:
 *   php scripts/publicar_criterios_editoriais.php                      → atualiza todos os 6 sites
 *   php scripts/publicar_criterios_editoriais.php --site=cursosenac    → só 1 site
 *   php scripts/publicar_criterios_editoriais.php --dry-run            → mostra HTML sem publicar
 */

set_time_limit(60);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/Wordpress.php';

$forceSite = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--site=')) $forceSite = substr($arg, 7);
    elseif ($arg === '--dry-run')         $dryRun = true;
}

$cfg = require $ROOT . '/config.php';
$sites = require $ROOT . '/sites.php';

/**
 * Mapeia o "tipo dominante" do site → fontes oficiais relevantes pro nicho.
 * Esses fontes são citadas explicitamente na página de critérios pra reforçar E-E-A-T.
 */
function fontesOficiaisPorSite(string $slug): array
{
    $mapa = [
        'comocomprar'      => ['Procon Nacional (gov.br/procon)', 'Inmetro (gov.br/inmetro)', 'Anatel (anatel.gov.br)', 'sites oficiais dos fabricantes', 'Reclame Aqui (verificação de loja)'],
        'ondecompraragora' => ['Procon Nacional (gov.br/procon)', 'sites oficiais das marcas', 'Reclame Aqui', 'comparadores de preço (Buscapé, Zoom)'],
        'vagasebeneficios' => ['Meu INSS (gov.br/meuinss)', 'Caixa (caixa.gov.br)', 'Diário Oficial da União (in.gov.br)', 'Ministério do Trabalho (gov.br/trabalho-e-emprego)', 'Receita Federal (gov.br/receitafederal)'],
        'cursosenac'       => ['Inep (gov.br/inep)', 'MEC (gov.br/mec)', 'Senac e Senai (sites institucionais)', 'FIES Online (fies.mec.gov.br)', 'Sisu (sisu.mec.gov.br)'],
        'guiadoscursos'    => ['MEC e-MEC (emec.mec.gov.br)', 'Inep (gov.br/inep)', 'CAPES (gov.br/capes)', 'sites oficiais das universidades', 'enade (gov.br/inep)'],
        'leaodabarra'      => ['Receita Federal (gov.br/receitafederal)', 'Diário Oficial da União (in.gov.br)', 'gov.br', 'CFC (cfc.org.br)', 'Sebrae (sebrae.com.br)'],
    ];
    return $mapa[$slug] ?? ['órgãos públicos brasileiros (gov.br)', 'fontes oficiais primárias citadas em cada artigo'];
}

/**
 * Monta o HTML da página de critérios editoriais.
 * Usa a persona do site pra customizar autor/voz/especialidade.
 */
function montarHtmlCriterios(string $siteSlug, array $siteCfg): string
{
    $persona  = $siteCfg['persona'] ?? [];
    $autor    = (string)($persona['autor']        ?? 'Redação ' . ($siteCfg['site_name'] ?? $siteSlug));
    $espec    = (string)($persona['especialidade']?? 'temas relacionados ao nicho do site');
    $audiencia= (string)($persona['audiencia']    ?? 'leitores brasileiros');
    $voz      = (string)($persona['voz']          ?? 'conteúdo factual com utilidade prática');
    $tom      = (string)($persona['tom']          ?? 'claro e direto');
    $siteName = (string)($siteCfg['site_name']   ?? $siteSlug);
    $fontes   = fontesOficiaisPorSite($siteSlug);
    $hoje     = date('d/m/Y');

    $fontesHtml = '<ul>';
    foreach ($fontes as $f) {
        $fontesHtml .= '<li>' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $fontesHtml .= '</ul>';

    $html = <<<HTML
<p><strong>Última atualização:</strong> {$hoje}</p>

<p>Esta página descreve como o {$siteName} produz, verifica e mantém o conteúdo publicado. Nossa equipe segue critérios editoriais públicos para que você saiba <strong>como cada artigo é construído</strong>, <strong>de onde vêm as informações</strong> e <strong>como contestar algo que esteja incorreto</strong>.</p>

<h2>Sobre nossa equipe editorial</h2>
<p>O {$siteName} é assinado por <strong>{$autor}</strong>. Nossa especialidade é cobrir <em>{$espec}</em>, com foco em <em>{$audiencia}</em>. O tom é {$voz} — sempre {$tom}.</p>

<h2>Nossa missão editorial</h2>
<p>Acreditamos que conteúdo útil é aquele que faz o leitor <strong>tomar uma ação concreta</strong> depois de ler: descobrir um prazo, entender uma regra nova, conseguir um benefício, evitar um erro. Não publicamos conteúdo só para preencher espaço — cada artigo precisa responder a uma pergunta real do nosso público.</p>

<h2>Como verificamos as informações</h2>
<p>Cada artigo passa por <strong>verificação cruzada em fontes oficiais primárias</strong> antes de ser publicado. As principais fontes que consultamos para o nicho deste site são:</p>
{$fontesHtml}

<p>Quando uma informação vem de um veículo de imprensa secundário, sempre tentamos rastrear até a <strong>fonte original</strong> (decreto, portaria, edital, comunicado oficial). Em temas legais, valores ou prazos, citamos explicitamente o documento de origem dentro do artigo.</p>

<h2>Como tratamos correções</h2>
<p>Se você identificar um erro factual em um artigo do {$siteName}, entre em contato. Nosso compromisso público é:</p>
<ul>
  <li><strong>Corrigir em até 24 horas</strong> qualquer informação factual incorreta após verificação.</li>
  <li><strong>Manter visível</strong> a indicação de que o artigo foi atualizado, com data da correção.</li>
  <li><strong>Não apagar</strong> erros silenciosamente — preferimos transparência ao "fingir que não aconteceu".</li>
</ul>

<h2>Como tratamos conteúdo gerado com auxílio de IA</h2>
<p>Usamos ferramentas de IA para <strong>acelerar a estruturação inicial</strong> dos artigos (research, primeiro rascunho, sugestão de subtítulos). <strong>Toda publicação passa por curadoria editorial humana</strong> antes de ir ao ar — ajustes de tom, verificação de fatos contra as fontes oficiais, supressão de generalizações e adição de observações práticas que só quem conhece o tema sabe.</p>

<p>Não publicamos conteúdo gerado 100% por IA sem revisão. Não usamos IA para inventar dados, citações ou estatísticas. Quando uma fonte é citada, ela existe e foi consultada.</p>

<h2>Anúncios e conteúdo de afiliado</h2>
<p>O {$siteName} pode receber comissão quando o leitor clica em links de afiliado para parceiros (Amazon, Hotmart, Shopee, Mercado Livre, instituições de ensino, casas de despachante, entre outros). Quando isso acontece:</p>
<ul>
  <li>O custo final para o leitor <strong>não muda</strong>.</li>
  <li>Marcamos o link como afiliado quando relevante.</li>
  <li>A relação comercial <strong>não influencia</strong> nossa cobertura factual — recomendações são baseadas em utilidade real, não em comissão.</li>
</ul>

<h2>Política de privacidade e cookies</h2>
<p>Coletamos apenas dados anônimos de navegação (Google Analytics e similares) para entender quais conteúdos são úteis. Não vendemos dados pessoais. Detalhes na nossa <em>Política de Privacidade</em>.</p>

<h2>Como entrar em contato</h2>
<p>Para reportar um erro, sugerir uma pauta ou contestar uma informação, use o formulário de contato do site ou o canal direto da redação. Levamos retornos da audiência a sério — nossa autoridade depende disso.</p>

<hr>

<p><em>Estes critérios editoriais foram publicados pela primeira vez em {$hoje} e são revisados periodicamente. A versão mais atual está sempre nesta página.</em></p>
HTML;

    return $html;
}

/**
 * Busca página existente por slug via WP REST. Retorna ID ou null.
 */
function buscarPaginaPorSlug(array $siteCfg, string $slug): ?int
{
    $url = rtrim($siteCfg['wp_url'], '/') . '/wp-json/wp/v2/pages?slug=' . urlencode($slug) . '&status=publish,draft';
    $auth = base64_encode($siteCfg['wp_user'] . ':' . $siteCfg['wp_app_password']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $auth,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code !== 200) return null;
    $data = json_decode((string)$resp, true);
    if (!is_array($data) || empty($data[0]['id'])) return null;
    return (int)$data[0]['id'];
}

// ─────────────────────────────────────────────────────────────────
// Loop principal
// ─────────────────────────────────────────────────────────────────

echo "Publicar Critérios Editoriais — " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('═', 80) . "\n\n";

$totalOk = 0;
$totalErro = 0;

foreach ($sites as $slug => $siteCfg) {
    if ($forceSite !== null && $slug !== $forceSite) continue;

    echo "─── {$slug} ({$siteCfg['site_name']}) ───\n";
    echo "  URL: {$siteCfg['wp_url']}\n";

    $html = montarHtmlCriterios($slug, $siteCfg);
    $titulo = 'Critérios Editoriais — ' . ($siteCfg['site_name'] ?? $slug);

    if ($dryRun) {
        echo "  [dry] HTML gerado (" . strlen($html) . " chars). Skipping publish.\n";
        $previewLen = 200;
        echo "  Preview: " . substr(strip_tags($html), 0, $previewLen) . "...\n\n";
        continue;
    }

    try {
        // 1) Busca se já existe
        $pageId = buscarPaginaPorSlug($siteCfg, 'criterios-editoriais');

        // 2) Cria/atualiza
        $wp = new Wordpress($siteCfg['wp_url'], $siteCfg['wp_user'], $siteCfg['wp_app_password']);
        $payload = [
            'title'   => $titulo,
            'slug'    => 'criterios-editoriais',
            'content' => $html,
            'status'  => 'publish',
        ];

        if ($pageId !== null) {
            echo "  [exist] Página existente (id={$pageId}) — atualizando...\n";
            $resp = $wp->atualizarPagina($pageId, $payload);
            $finalId = $pageId;
        } else {
            echo "  [novo] Criando página nova...\n";
            $resp = $wp->criarPagina($payload);
            $finalId = (int)($resp['id'] ?? 0);
        }

        if ($finalId > 0) {
            $link = $resp['link'] ?? rtrim($siteCfg['wp_url'], '/') . '/criterios-editoriais/';
            echo "  ✓ OK · id={$finalId} · {$link}\n\n";
            $totalOk++;
        } else {
            echo "  ✗ Resposta sem ID válido: " . json_encode($resp) . "\n\n";
            $totalErro++;
        }
    } catch (Throwable $e) {
        echo "  ✗ ERRO: " . $e->getMessage() . "\n\n";
        $totalErro++;
    }
}

echo str_repeat('═', 80) . "\n";
echo "RESUMO: {$totalOk} sites OK · {$totalErro} erros\n";
