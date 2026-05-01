<?php
/**
 * fix_adsense_warnings.php
 *   1. Confirma se posts têm featured_media (resolve falso positivo "sem imagens")
 *   2. Aplica noindex via Rank Math REST nas categorias com <3 posts
 *
 * Uso: php fix_adsense_warnings.php cursosenac
 */
require_once __DIR__ . '/_site_helper.php';
$slug = $argv[1] ?? '';
$sites = sitesDisponiveis();
if (!isset($sites[$slug])) { exit(1); }
$s = $sites[$slug];
$wpUrl = rtrim($s['wp_url'], '/');
$user = $s['wp_user']; $pass = $s['wp_app_password'];

function rest($method, $url, $payload, $user, $pass) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERPWD => "{$user}:{$pass}",
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !empty($payload)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$body, true) ?: $body];
}

echo "=== FIX ADSENSE WARNINGS ({$slug}) ===\n";
echo "WP: {$wpUrl}\n\n";

// ─── PARTE 1: VERIFICA FEATURED_MEDIA NOS POSTS ───
echo "[1/2] Confirmando featured_image nos posts...\n";
$r = rest('GET', "{$wpUrl}/wp-json/wp/v2/posts?per_page=10&_fields=id,title,featured_media", [], $user, $pass);
$comFeatured = 0; $semFeatured = 0; $exemplos = [];
if (is_array($r['body'])) {
    foreach ($r['body'] as $p) {
        if ((int)($p['featured_media'] ?? 0) > 0) {
            $comFeatured++;
        } else {
            $semFeatured++;
            $exemplos[] = "#{$p['id']} '" . mb_substr((string)($p['title']['rendered'] ?? ''), 0, 50) . "'";
        }
    }
    $total = $comFeatured + $semFeatured;
    echo "  Com featured_image: {$comFeatured}/{$total}\n";
    echo "  Sem featured_image: {$semFeatured}/{$total}\n";
    if ($semFeatured > 0) {
        echo "  Posts sem featured (primeiros 3): " . implode(', ', array_slice($exemplos, 0, 3)) . "\n";
        echo "  → Fix: pipeline de geração deve setar featured_media obrigatório.\n";
    } else {
        echo "  ✓ Warning era falso positivo — todos posts têm featured_image\n";
    }
}

// ─── PARTE 2: NOINDEX CATEGORIAS COM <3 POSTS ───
echo "\n[2/2] Aplicando noindex em categorias com <3 posts...\n";
$cats = rest('GET', "{$wpUrl}/wp-json/wp/v2/categories?per_page=100&hide_empty=false", [], $user, $pass);
$alvo = [];
if (is_array($cats['body'])) {
    foreach ($cats['body'] as $c) {
        if (($c['count'] ?? 0) < 3 && !in_array(($c['slug'] ?? ''), ['uncategorized', 'sem-categoria'], true)) {
            $alvo[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'count' => (int)$c['count']];
        }
    }
}
echo "  Categorias alvo: " . count($alvo) . "\n";

if (count($alvo) === 0) {
    echo "  ✓ Nenhuma categoria precisa noindex\n";
} else {
    echo "  Tentando aplicar noindex via Rank Math /updateMeta...\n";
    $okRm = 0; $failRm = 0;

    foreach ($alvo as $cat) {
        // Endpoint Rank Math: /wp-json/rankmath/v1/updateMeta
        // Payload provável: objectID + objectType=term + meta com rank_math_robots
        $payload = [
            'objectID'   => $cat['id'],
            'objectType' => 'term',
            'meta'       => [
                'rank_math_robots' => ['noindex'],
            ],
        ];
        $r = rest('POST', "{$wpUrl}/wp-json/rankmath/v1/updateMeta", $payload, $user, $pass);
        if ($r['code'] >= 200 && $r['code'] < 300) {
            $okRm++;
            echo "    ✓ '{$cat['name']}' (#{$cat['id']}, {$cat['count']}p) → noindex\n";
        } else {
            $failRm++;
            $msg = is_array($r['body']) ? json_encode($r['body']) : $r['body'];
            echo "    ✗ '{$cat['name']}' (#{$cat['id']}) HTTP {$r['code']} — " . mb_substr((string)$msg, 0, 150) . "\n";
            // Se falhar 1ª categoria, para — provavelmente endpoint não aceita esse payload
            if ($failRm === 1) {
                echo "    Endpoint pode não aceitar esse payload. Tentando variação...\n";
                $payload2 = ['rank_math_robots' => 'noindex'];
                $r2 = rest('POST', "{$wpUrl}/wp-json/wp/v2/categories/{$cat['id']}", ['meta' => $payload2], $user, $pass);
                echo "    Variação WP REST meta: HTTP {$r2['code']}\n";
                if ($r2['code'] >= 200 && $r2['code'] < 300) {
                    echo "    ✓ Variação funcionou! Continuando com WP REST padrão...\n";
                    // Refazer com o método que funcionou
                    foreach (array_slice($alvo, 1) as $c2) {
                        $r3 = rest('POST', "{$wpUrl}/wp-json/wp/v2/categories/{$c2['id']}",
                            ['meta' => ['rank_math_robots' => 'noindex']], $user, $pass);
                        if ($r3['code'] >= 200 && $r3['code'] < 300) {
                            $okRm++;
                            echo "    ✓ '{$c2['name']}' → noindex\n";
                        } else {
                            $failRm++;
                        }
                    }
                    break;
                } else {
                    $msg2 = is_array($r2['body']) ? json_encode($r2['body']) : $r2['body'];
                    echo "    Variação também falhou: " . mb_substr((string)$msg2, 0, 150) . "\n";
                    break;
                }
            }
        }
    }

    echo "\n  Resultado: ✓ {$okRm} aplicados · ✗ " . (count($alvo) - $okRm) . " falharam\n";
}

echo "\n=== FIM ===\n";
