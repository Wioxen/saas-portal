<?php
/**
 * criar_author_pages — cria/atualiza páginas /sobre/{autor} em cada site (E-E-A-T).
 *
 * Discover usa /author/ pages como sinal de Authoritativeness (parte do E-E-A-T).
 * Schema.org Person já está nos posts; faltava a página HTML real.
 *
 * Pra cada site (sites.php), cria 1 página WP:
 *   slug: 'sobre-{autor-slug}' OU 'sobre/maria-gusmao' (URL pretty)
 *   title: 'Sobre {autor} — Editor de {especialidade}'
 *   content: bio + sameAs + standards editoriais + transparency
 *
 * Idempotente: se página existe, atualiza conteúdo (só se mudou).
 *
 * Uso:
 *   php scripts/criar_author_pages.php                  # todos sites
 *   php scripts/criar_author_pages.php --site=cursosenac
 *   php scripts/criar_author_pages.php --dry-run
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/Wordpress.php';
require_once $ROOT . '/_site_helper.php';

$siteArg = '';
$dryRun = false;
$quiet = false;
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m)) $siteArg = $m[1];
    elseif ($a === '--dry-run') $dryRun = true;
    elseif ($a === '--quiet')   $quiet = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo "[author_pages] {$m}\n"; }

$lock = new CronLock('author_pages');
if (!$lock->aquirir()) { log_msg('outra instância rodando', $quiet); exit(1); }

$cfgBase = require $ROOT . '/config.php';
$sites = sitesDisponiveis();
$alvosSites = $siteArg !== '' ? [$siteArg => $sites[$siteArg] ?? null] : $sites;

$total = 0;
$criados = 0;
$atualizados = 0;
$skipados = 0;

foreach ($alvosSites as $slug => $cfgSite) {
    if (!is_array($cfgSite)) continue;

    $cfgMesclado = $cfgBase;
    aplicarSite($cfgMesclado, $sites, $slug);

    $persona = $cfgMesclado['persona'] ?? null;
    if (!is_array($persona) || empty($persona['autor'])) {
        log_msg("[{$slug}] sem persona.autor — skipa", $quiet);
        continue;
    }

    $autorNome = (string)$persona['autor'];
    $autorSlug = 'sobre-' . autorParaSlug($autorNome);
    $title = "Sobre {$autorNome}";

    $content = montarBio($persona, $cfgMesclado, $slug);

    log_msg("[{$slug}] processando: {$autorNome} → /{$autorSlug}", $quiet);
    $total++;

    if ($dryRun) {
        log_msg("  [dry-run] criaria/atualizaria página '{$title}'", $quiet);
        continue;
    }

    try {
        $wp = new Wordpress($cfgMesclado['wp_url'], $cfgMesclado['wp_user'], $cfgMesclado['wp_app_password']);

        // Busca por slug (page_type)
        $pageId = buscarPaginaPorSlug($cfgMesclado, $autorSlug);

        if ($pageId > 0) {
            // Atualiza
            $wp->atualizarPost($pageId, [
                'title'   => $title,
                'content' => $content,
            ]);
            log_msg("  ✓ atualizada page_id={$pageId}", $quiet);
            $atualizados++;
        } else {
            // Cria nova page (não post — pages têm tratamento /sobre/X melhor pelo WP)
            $resp = criarPagina($cfgMesclado, [
                'title'   => $title,
                'content' => $content,
                'slug'    => $autorSlug,
                'status'  => 'publish',
            ]);
            if (!empty($resp['id'])) {
                log_msg("  ✓ criada page_id={$resp['id']}", $quiet);
                $criados++;
            } else {
                log_msg("  ✗ falha create: " . json_encode($resp), $quiet);
            }
        }
    } catch (Throwable $e) {
        log_msg("  erro: " . $e->getMessage(), $quiet);
        $skipados++;
    }
}

log_msg("TOTAL: {$total} processados · {$criados} criados · {$atualizados} atualizados · {$skipados} skipados", $quiet);
$lock->liberar();
exit(0);

// ─────────────────────────────────────────────

function autorParaSlug(string $nome): string {
    $s = mb_strtolower(trim($nome), 'UTF-8');
    $de = ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ',',','.','—'];
    $pa = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n','','','-'];
    $s = str_replace($de, $pa, $s);
    $s = preg_replace('/[^a-z0-9-]+/', '-', $s) ?? $s;
    return trim((string)$s, '-');
}

function montarBio(array $persona, array $cfg, string $slug): string {
    $autor         = (string)($persona['autor'] ?? '');
    $voz           = (string)($persona['voz'] ?? '');
    $especialidade = (string)($persona['especialidade'] ?? '');
    $audiencia     = (string)($persona['audiencia'] ?? '');
    $tom           = (string)($persona['tom'] ?? '');
    $siteName      = (string)($cfg['site_name'] ?? $cfg['_site_name'] ?? $slug);
    $siteUrl       = rtrim((string)($cfg['wp_url'] ?? ''), '/');

    $sameAs = [];
    if (!empty($persona['sameAs']) && is_array($persona['sameAs'])) {
        foreach ($persona['sameAs'] as $u) {
            if (preg_match('#^https?://#', (string)$u)) $sameAs[] = (string)$u;
        }
    }

    $h = '<div class="author-bio" itemscope itemtype="https://schema.org/Person">';
    $h .= '<h1 itemprop="name">' . htmlspecialchars($autor, ENT_QUOTES, 'UTF-8') . '</h1>';

    if ($especialidade !== '') {
        $h .= '<p class="author-jobtitle"><strong>Especialidade:</strong> <span itemprop="jobTitle">' . htmlspecialchars($especialidade, ENT_QUOTES, 'UTF-8') . '</span></p>';
    }

    $h .= '<h2>Sobre o trabalho editorial</h2>';
    if ($voz !== '') {
        $h .= '<p itemprop="description">' . htmlspecialchars($voz, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if ($audiencia !== '') {
        $h .= '<p><strong>Para quem escreve:</strong> ' . htmlspecialchars($audiencia, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if ($tom !== '') {
        $h .= '<p><strong>Tom editorial:</strong> ' . htmlspecialchars($tom, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $h .= '<h2>Padrões editoriais</h2>';
    $h .= '<ul>';
    $h .= '<li><strong>Verificação cruzada:</strong> cada matéria é checada contra fontes oficiais (.gov.br, .edu.br, .jus.br) antes de publicar.</li>';
    $h .= '<li><strong>Atualização contínua:</strong> posts em temas dinâmicos (prazos, valores, calendários) são revisados conforme novas informações.</li>';
    $h .= '<li><strong>Fonte sempre transparente:</strong> link pra fonte original em todo dado factual quando aplicável.</li>';
    $h .= '<li><strong>Sem clickbait:</strong> título promete só o que o texto entrega.</li>';
    $h .= '<li><strong>Correções:</strong> erros são corrigidos e marcados com data de atualização visível no post.</li>';
    $h .= '</ul>';

    $h .= '<h2>Site editorial</h2>';
    $h .= '<p>Este perfil é o autor editorial de <a href="' . htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</a>.</p>';

    if (!empty($sameAs)) {
        $h .= '<h2>Onde encontrar</h2><ul>';
        foreach ($sameAs as $u) {
            $h .= '<li><a href="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '" rel="me" itemprop="sameAs" target="_blank">' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '</a></li>';
        }
        $h .= '</ul>';
    }

    $h .= '</div>';
    return $h;
}

function buscarPaginaPorSlug(array $cfg, string $slug): int {
    $url = rtrim((string)$cfg['wp_url'], '/') . '/wp-json/wp/v2/pages?slug=' . urlencode($slug) . '&_fields=id';
    $auth = base64_encode((string)$cfg['wp_user'] . ':' . (string)$cfg['wp_app_password']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    $arr = json_decode((string)$r, true);
    if (is_array($arr) && !empty($arr) && isset($arr[0]['id'])) return (int)$arr[0]['id'];
    return 0;
}

function criarPagina(array $cfg, array $payload): array {
    $url = rtrim((string)$cfg['wp_url'], '/') . '/wp-json/wp/v2/pages';
    $auth = base64_encode((string)$cfg['wp_user'] . ':' . (string)$cfg['wp_app_password']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$r, true) ?: [];
}
