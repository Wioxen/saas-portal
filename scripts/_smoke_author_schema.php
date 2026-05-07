<?php
/**
 * Smoke test: valida o Author Schema no JSON-LD dos posts publicados.
 *
 * Pra cada site (Sistema 2): pega 1 post recente, fetch HTML, extrai todos os
 * <script type="application/ld+json">, e verifica se o Author tem url + sameAs.
 *
 * Read-only. Não escreve nada.
 *
 * Uso:
 *   php scripts/_smoke_author_schema.php
 */

declare(strict_types=1);

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';

$alvo = [
    'cursosenac' => ['user_id' => 2, 'username' => 'paloma'],
    'guiadoscursos' => ['user_id' => 3, 'username' => 'ivan.alves'],
    'vagasebeneficios' => ['user_id' => 3, 'username' => 'igor.gusmao'],
];

$sitesGlobais = sitesDisponiveis();

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Smoke Author Schema — verifica URL+sameAs no JSON-LD\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

foreach ($alvo as $slug => $info) {
    $cfgSite = $sitesGlobais[$slug] ?? null;
    if (!$cfgSite) continue;
    $userId = $info['user_id'];
    $username = $info['username'];

    echo "═══ {$slug} (autor esperado: user_id={$userId} / {$username}) ═══\n";

    $aplicado = $cfg;
    aplicarSite($aplicado, $sitesGlobais, $slug);
    $wp = new Wordpress($aplicado['wp_url'], $aplicado['wp_user'], $aplicado['wp_app_password']);

    // Lista 3 posts mais recentes desse autor
    $base = rtrim($aplicado['wp_url'], '/') . '/wp-json/wp/v2';
    $auth = base64_encode($aplicado['wp_user'] . ':' . $aplicado['wp_app_password']);
    $ch = curl_init("{$base}/posts?author={$userId}&per_page=3&status=publish&_fields=id,link,title,author");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Basic {$auth}"]]);
    $resp = json_decode((string)curl_exec($ch), true) ?: [];
    curl_close($ch);

    if (empty($resp)) {
        echo "⚠ Nenhum post com author={$userId} encontrado. Tentando posts gerais...\n";
        $ch = curl_init("{$base}/posts?per_page=1&status=publish&_fields=id,link,title,author");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Basic {$auth}"]]);
        $resp = json_decode((string)curl_exec($ch), true) ?: [];
        curl_close($ch);
        if (empty($resp)) {
            echo "✗ sem posts publish encontrados\n\n";
            continue;
        }
    }

    $post = $resp[0];
    $url = (string)$post['link'];
    $titulo = strip_tags(html_entity_decode((string)($post['title']['rendered'] ?? '')));
    echo "Post: #{$post['id']} | author=#{$post['author']} | " . mb_substr($titulo, 0, 60) . "\n";
    echo "URL : {$url}\n";

    // Fetch HTML público (sem auth)
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => $aplicado['user_agent'] ?? 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $html = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || $html === '') {
        echo "✗ fetch falhou (HTTP {$code})\n\n";
        continue;
    }

    // Extrai todos os <script type="application/ld+json">
    if (!preg_match_all('#<script[^>]*type=[\'"]application/ld\+json[\'"][^>]*>(.*?)</script>#is', $html, $mm)) {
        echo "✗ NENHUM JSON-LD encontrado no HTML\n\n";
        continue;
    }

    echo "Encontrados " . count($mm[1]) . " script(s) JSON-LD\n";

    $achouAuthor = false;
    $achouPerson = false;
    foreach ($mm[1] as $idx => $rawJson) {
        $json = json_decode(trim($rawJson), true);
        if (!is_array($json)) continue;

        $nodes = isset($json['@graph']) ? $json['@graph'] : [$json];

        // 1) Article/BlogPosting com author inline
        foreach ($nodes as $node) {
            $type = $node['@type'] ?? '';
            if (is_array($type)) $type = implode(',', $type);
            $isArticle = preg_match('/Article|BlogPosting/i', $type);
            if (!$isArticle) continue;
            $author = $node['author'] ?? null;
            if (!$author) continue;
            $authors = isset($author[0]) ? $author : [$author];
            foreach ($authors as $a) {
                if (!is_array($a)) continue;
                $achouAuthor = true;
                echo "  Author inline no Article (script #{$idx}):\n";
                echo "    @id:         " . ($a['@id'] ?? '(sem @id, inline)') . "\n";
                echo "    name:        " . ($a['name'] ?? '(?)') . "\n";
                echo "    url:         " . (!empty($a['url']) ? '✓ ' . $a['url'] : '✗ AUSENTE') . "\n";
                echo "    sameAs:      " . (!empty($a['sameAs']) ? '✓ ' . (is_array($a['sameAs']) ? implode(', ', $a['sameAs']) : $a['sameAs']) : '✗ AUSENTE') . "\n";
                echo "    description: " . (!empty($a['description']) ? '✓ ' . mb_substr($a['description'], 0, 60) . '…' : '✗ AUSENTE') . "\n";
                echo "    image:       " . (!empty($a['image']) ? '✓ presente' : '✗ AUSENTE') . "\n";
            }
        }

        // 2) Person standalone em qualquer lugar do @graph (Rank Math pode emitir aqui)
        foreach ($nodes as $node) {
            $type = $node['@type'] ?? '';
            if (is_array($type)) $type = implode(',', $type);
            if (!preg_match('/Person/i', $type)) continue;
            $achouPerson = true;
            echo "  Person standalone (script #{$idx}):\n";
            echo "    @id:         " . ($node['@id'] ?? '(sem @id)') . "\n";
            echo "    name:        " . ($node['name'] ?? '(?)') . "\n";
            echo "    url:         " . (!empty($node['url']) ? '✓ ' . $node['url'] : '✗ AUSENTE') . "\n";
            echo "    sameAs:      " . (!empty($node['sameAs']) ? '✓ ' . (is_array($node['sameAs']) ? implode(', ', $node['sameAs']) : $node['sameAs']) : '✗ AUSENTE') . "\n";
            echo "    description: " . (!empty($node['description']) ? '✓ ' . mb_substr($node['description'], 0, 60) . '…' : '✗ AUSENTE') . "\n";
            echo "    image:       " . (!empty($node['image']) ? '✓ presente' : '✗ AUSENTE') . "\n";
        }
    }
    if (!$achouAuthor && !$achouPerson) {
        echo "⚠ Nenhum Author/Person encontrado em qualquer lugar do JSON-LD\n";
    }

    // Fetch ADICIONAL: author archive page (mostra Schema do user esperado direto)
    $authorUrl = rtrim($aplicado['wp_url'], '/') . "/author/{$username}/";
    echo "\n  ↘ Verificando author archive: {$authorUrl}\n";
    $ch = curl_init($authorUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => $aplicado['user_agent'] ?? 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $htmlAuthor = (string)curl_exec($ch);
    $codeAuthor = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($codeAuthor === 200 && preg_match_all('#<script[^>]*type=[\'"]application/ld\+json[\'"][^>]*>(.*?)</script>#is', $htmlAuthor, $mma)) {
        foreach ($mma[1] as $idx => $rawJson) {
            $json = json_decode(trim($rawJson), true);
            if (!is_array($json)) continue;
            $nodes = isset($json['@graph']) ? $json['@graph'] : [$json];
            foreach ($nodes as $node) {
                $type = $node['@type'] ?? '';
                if (is_array($type)) $type = implode(',', $type);
                if (!preg_match('/Person/i', $type)) continue;
                echo "    Person no author archive (script #{$idx}):\n";
                echo "      name:        " . ($node['name'] ?? '(?)') . "\n";
                echo "      url:         " . (!empty($node['url']) ? '✓ ' . $node['url'] : '✗ AUSENTE') . "\n";
                echo "      sameAs:      " . (!empty($node['sameAs']) ? '✓ ' . (is_array($node['sameAs']) ? implode(', ', $node['sameAs']) : $node['sameAs']) : '✗ AUSENTE') . "\n";
                echo "      description: " . (!empty($node['description']) ? '✓ ' . mb_substr($node['description'], 0, 80) . '…' : '✗ AUSENTE') . "\n";
                echo "      image:       " . (!empty($node['image']) ? '✓ presente' : '✗ AUSENTE') . "\n";
                break 2;
            }
        }
    } else {
        echo "    ⚠ author archive não retornou 200 (HTTP {$codeAuthor})\n";
    }
    echo "\n";
}

echo "═══ Smoke completo. Análise:\n";
echo "  url ✓        = Rank Math expõe URL do autor (LinkedIn/etc)\n";
echo "  sameAs ✓     = Rank Math expõe sameAs com perfis externos\n";
echo "  description ✓= bio aparece (E-E-A-T)\n";
echo "  image ✓      = avatar (Gravatar) detectado pelo Schema\n";
