<?php
/**
 * Atualiza a "Biographical Info" (bio rica) do user 'admin' em cada um dos 6 sites WP,
 * extraindo de persona.autor + persona.especialidade + persona.audiencia + persona.tom (sites.php).
 *
 * Reforça E-E-A-T no /author/admin/ que o WordPress gera nativamente.
 *
 * Uso:
 *   php scripts/atualizar_bio_autor.php                       → atualiza todos os 6 sites
 *   php scripts/atualizar_bio_autor.php --site=cursosenac     → só 1 site
 *   php scripts/atualizar_bio_autor.php --dry-run             → mostra a bio gerada sem publicar
 */

set_time_limit(60);
$ROOT = dirname(__DIR__);

$forceSite = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--site=')) $forceSite = substr($arg, 7);
    elseif ($arg === '--dry-run')         $dryRun = true;
}

$sites = require $ROOT . '/sites.php';

/**
 * Monta a bio rica do autor a partir da persona do site.
 * Texto curto (200-400 chars) que aparece no /author/admin/ e em alguns temas WP.
 */
function montarBio(string $slug, array $siteCfg): string
{
    $persona = $siteCfg['persona'] ?? [];
    $autor      = (string)($persona['autor']         ?? 'Redação');
    $voz        = (string)($persona['voz']           ?? '');
    $especial   = (string)($persona['especialidade'] ?? '');
    $audiencia  = (string)($persona['audiencia']     ?? '');
    $siteName   = (string)($siteCfg['site_name']    ?? $slug);

    // Bio formatada — voz + especialidade + audiência + ancoragem ao site
    $partes = [];
    $partes[] = "{$autor} é responsável pela cobertura editorial do {$siteName}.";

    if ($especial !== '') {
        $partes[] = "Especialidade: {$especial}.";
    }
    if ($audiencia !== '') {
        $partes[] = "Escreve para {$audiencia}.";
    }
    if ($voz !== '') {
        $partes[] = "Linha editorial: {$voz}.";
    }
    $partes[] = "Toda publicação passa por verificação cruzada em fontes oficiais primárias antes de ser publicada (ver Critérios Editoriais).";

    return implode(' ', $partes);
}

/**
 * Busca o user 'admin' (id) via WP REST. Retorna ID ou null.
 */
function buscarUserAdmin(array $siteCfg): ?int
{
    $url = rtrim($siteCfg['wp_url'], '/') . '/wp-json/wp/v2/users?slug=' . urlencode($siteCfg['wp_user']) . '&context=edit';
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

/**
 * Atualiza description (bio) + display_name do user via WP REST PUT.
 */
function atualizarUser(array $siteCfg, int $userId, string $bio, string $displayName): array
{
    $url = rtrim($siteCfg['wp_url'], '/') . '/wp-json/wp/v2/users/' . $userId;
    $auth = base64_encode($siteCfg['wp_user'] . ':' . $siteCfg['wp_app_password']);
    $payload = [
        'description' => $bio,
        'name'        => $displayName, // display_name — afeta /author/{slug}/
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'http_code' => $code,
        'data'      => $resp ? (json_decode((string)$resp, true) ?: null) : null,
    ];
}

// ─────────────────────────────────────────────────────────────────

echo "Atualizar Bio do Autor — " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('═', 80) . "\n\n";

$totalOk = 0;
$totalErro = 0;

foreach ($sites as $slug => $siteCfg) {
    if ($forceSite !== null && $slug !== $forceSite) continue;

    echo "─── {$slug} ({$siteCfg['site_name']}) ───\n";

    $bio = montarBio($slug, $siteCfg);
    $persona = $siteCfg['persona'] ?? [];
    $displayName = (string)($persona['autor'] ?? 'Redação ' . $siteCfg['site_name']);

    echo "  Display name: {$displayName}\n";
    echo "  Bio (" . mb_strlen($bio) . " chars): " . mb_substr($bio, 0, 100) . "...\n";

    if ($dryRun) {
        echo "  [dry] sem publicar.\n\n";
        continue;
    }

    $userId = buscarUserAdmin($siteCfg);
    if ($userId === null) {
        echo "  ✗ user '{$siteCfg['wp_user']}' não encontrado via REST\n\n";
        $totalErro++;
        continue;
    }

    echo "  user_id: {$userId} · atualizando...\n";
    $r = atualizarUser($siteCfg, $userId, $bio, $displayName);
    if ($r['http_code'] === 200) {
        $authorUrl = rtrim($siteCfg['wp_url'], '/') . '/author/' . $siteCfg['wp_user'] . '/';
        echo "  ✓ OK · {$authorUrl}\n\n";
        $totalOk++;
    } else {
        echo "  ✗ HTTP {$r['http_code']} · " . json_encode($r['data']) . "\n\n";
        $totalErro++;
    }
}

echo str_repeat('═', 80) . "\n";
echo "RESUMO: {$totalOk} sites OK · {$totalErro} erros\n";
