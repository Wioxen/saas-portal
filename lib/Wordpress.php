<?php
/**
 * Cliente WordPress REST API com Application Password.
 *
 * Suporta:
 *  - Upload de mídia
 *  - Criação de posts com meta fields (RankMath)
 *  - Criação de páginas (landing pages)
 *  - Auto-criação de categorias e tags por nome
 */
class Wordpress
{
    private string $base;
    private string $auth;

    public function __construct(string $url, string $user, string $appPassword)
    {
        $this->base = rtrim($url, '/') . '/wp-json/wp/v2';
        $this->auth = base64_encode("$user:$appPassword");
    }

    /** Cria post com todos os campos. Devolve ID + link de edição. */
    public function criarPost(array $payload): array
    {
        return $this->request('POST', '/posts', $payload);
    }

    /** Cria página (landing page). Devolve ID + link. */
    public function criarPagina(array $payload): array
    {
        return $this->request('POST', '/pages', $payload);
    }

    /** Lista posts publicados (paginado). */
    public function listarPosts(int $page = 1, int $perPage = 100): array
    {
        return $this->request('GET', "/posts?status=publish&per_page={$perPage}&page={$page}&orderby=date&order=desc");
    }

    /**
     * Lista posts que não recebem atualização há X dias ou mais.
     * Ordena por `modified` ascendente (mais desatualizados primeiro).
     * Retorna array de {id, title, link, modified, date, age_days, tag_count}.
     *
     * @param string $tagFilter 'todos' | 'sem_tags' | 'com_tags'
     */
    public function listarPostsParaAtualizar(int $diasMinimos = 30, int $limit = 50, string $tagFilter = 'todos', int $horasMinimas = 0, string $ordem = 'asc', int $pagina = 1, string $dataDe = '', string $dataAte = ''): array
    {
        $per = min($limit, 100);
        $ord = $ordem === 'desc' ? 'desc' : 'asc';
        $path = "/posts?status=publish&per_page={$per}&page={$pagina}&orderby=modified&order={$ord}";

        if ($dataDe !== '' && $dataAte !== '') {
            $path .= "&modified_after=" . urlencode($dataDe . 'T00:00:00');
            $path .= "&modified_before=" . urlencode($dataAte . 'T23:59:59');
        } elseif ($horasMinimas > 0) {
            $antes = (new DateTime("-{$horasMinimas} hours"))->format('Y-m-d\TH:i:s');
            $path .= "&modified_before=" . urlencode($antes);
        } else {
            $antes = (new DateTime("-{$diasMinimos} days"))->format('Y-m-d\TH:i:s');
            $path .= "&modified_before=" . urlencode($antes);
        }
        $posts = $this->request('GET', $path);
        $out = [];
        $agora = new DateTime();
        foreach ($posts as $p) {
            $mod = $p['modified'] ?? '';
            $age = 0;
            $ageHours = 0;
            if ($mod) {
                try {
                    $diff = (new DateTime($mod))->diff($agora);
                    $age = (int)$diff->days;
                    $ageHours = $age * 24 + (int)$diff->h;
                } catch (Throwable $e) {}
            }
            $tags = $p['tags'] ?? [];
            $tagCount = is_array($tags) ? count($tags) : 0;

            // Filtro de tags
            if ($tagFilter === 'sem_tags' && $tagCount > 0) continue;
            if ($tagFilter === 'com_tags' && $tagCount === 0) continue;

            $out[] = [
                'id'        => $p['id'],
                'title'     => strip_tags(html_entity_decode($p['title']['rendered'] ?? '')),
                'link'      => $p['link'] ?? '',
                'modified'  => $mod,
                'date'      => $p['date'] ?? '',
                'age_days'  => $age,
                'age_hours' => $ageHours,
                'tag_count' => $tagCount,
            ];
        }
        return $out;
    }

    /**
     * Lista posts publicados há pelo menos X horas (ordem: mais antigos primeiro).
     * Útil para verificação de indexação — Google precisa de tempo pra indexar naturalmente.
     * @return array de {id, title, link, date, age_hours}
     */
    public function listarPostsParaIndexar(int $horasMinimas = 72, int $limit = 60): array
    {
        $antes = (new DateTime("-{$horasMinimas} hours"))->format('Y-m-d\TH:i:s');
        $per = min($limit, 100);
        $path = "/posts?status=publish&per_page={$per}&orderby=date&order=desc&before=" . urlencode($antes);
        $posts = $this->request('GET', $path);
        $out = [];
        $agora = new DateTime();
        foreach ($posts as $p) {
            $dt  = $p['date'] ?? '';
            $age = 0;
            if ($dt) {
                try {
                    $diff = (new DateTime($dt))->diff($agora);
                    $age = ($diff->days * 24) + $diff->h;
                } catch (Throwable $e) {}
            }
            $out[] = [
                'id'        => $p['id'],
                'title'     => strip_tags(html_entity_decode($p['title']['rendered'] ?? '')),
                'link'      => $p['link'] ?? '',
                'date'      => $dt,
                'age_hours' => $age,
            ];
        }
        return $out;
    }

    /** Retorna dados de um post (inclusive content raw). */
    public function getPost(int $id): array
    {
        return $this->request('GET', "/posts/{$id}?context=edit");
    }

    /**
     * Atualiza campos de um post existente. Se $cfgPurge contém cloudflare_zone_id,
     * purga URL do post no edge Cloudflare após sucesso (Title/P1/Meta swap visível imediato).
     * No-op se cfg vazio ou sem CLOUDFLARE_API_TOKEN.
     */
    public function atualizarPost(int $id, array $payload, array $cfgPurge = []): array
    {
        $resp = $this->request('POST', "/posts/{$id}", $payload);
        self::purgeIfConfigured($cfgPurge, $resp);
        return $resp;
    }

    /**
     * Purga URL no Cloudflare quando cfg tem cloudflare_zone_id + .env tem token.
     * Falha silenciosa: purge é PLUS.
     */
    private static function purgeIfConfigured(array $cfg, array $resp): void
    {
        if (empty($cfg['cloudflare_zone_id'])) return;
        $url = trim((string)($resp['link'] ?? ''));
        if ($url === '') return;
        try {
            require_once __DIR__ . '/CloudflareCachePurge.php';
            CloudflareCachePurge::purgeUrl($cfg, $url);
        } catch (Throwable $e) { /* purge é PLUS */ }
    }

    /** Retorna dados de uma página (inclusive content raw). */
    public function getPagina(int $id): array
    {
        return $this->request('GET', "/pages/{$id}?context=edit");
    }

    /** Atualiza campos de uma página existente. */
    public function atualizarPagina(int $id, array $payload): array
    {
        return $this->request('POST', "/pages/{$id}", $payload);
    }

    /** Retorna dados de um media attachment (inclusive source_url). */
    public function getMedia(int $id): array
    {
        return $this->request('GET', "/media/$id");
    }

    /** Atualiza campos de um media attachment (alt_text, caption, description, title). */
    public function atualizarMedia(int $id, array $payload): array
    {
        return $this->request('POST', "/media/$id", $payload);
    }

    /**
     * Busca posts relacionados por keyword.
     * Retorna array com id, title, link, featured_image_url.
     */
    public function buscarRelacionados(string $keyword, int $limit = 6, int $excluirId = 0): array
    {
        $params = '?search=' . urlencode($keyword)
            . '&per_page=' . $limit
            . '&status=publish'
            . '&_embed=wp:featuredmedia'
            . '&orderby=relevance';
        if ($excluirId) {
            $params .= '&exclude=' . $excluirId;
        }

        $posts = $this->request('GET', '/posts' . $params);
        $resultado = [];
        foreach ($posts as $p) {
            $img = '';
            if (!empty($p['_embedded']['wp:featuredmedia'][0]['source_url'])) {
                $img = $p['_embedded']['wp:featuredmedia'][0]['source_url'];
            } elseif (!empty($p['_embedded']['wp:featuredmedia'][0]['media_details']['sizes']['medium']['source_url'])) {
                $img = $p['_embedded']['wp:featuredmedia'][0]['media_details']['sizes']['medium']['source_url'];
            }
            $resultado[] = [
                'id'    => $p['id'],
                'title' => $p['title']['rendered'] ?? '',
                'link'  => $p['link'] ?? '',
                'image' => $img,
            ];
        }
        return $resultado;
    }

    /** Resolve nomes de categoria → IDs (cria se não existir). */
    public function resolverCategorias(array $nomes): array
    {
        return $this->resolverTermos($nomes, '/categories');
    }

    /**
     * Lista TODAS as categorias do site (paginadas). Retorna [{id, name, slug, parent, count}].
     * Útil pra fuzzy matching antes de criar categoria nova (CategoryMatcher).
     * Cache estático por instância — reuso na mesma request.
     */
    public function listarTodasCategorias(int $perPage = 100, int $maxPages = 20): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $todas = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $batch = $this->request('GET', "/categories?per_page={$perPage}&page={$page}&orderby=name&order=asc&hide_empty=false");
            } catch (Throwable $e) {
                break;
            }
            if (empty($batch) || !is_array($batch)) break;
            foreach ($batch as $c) {
                $todas[] = [
                    'id'     => (int)($c['id'] ?? 0),
                    'name'   => (string)($c['name'] ?? ''),
                    'slug'   => (string)($c['slug'] ?? ''),
                    'parent' => (int)($c['parent'] ?? 0),
                    'count'  => (int)($c['count'] ?? 0),
                ];
            }
            if (count($batch) < $perPage) break; // última página
        }
        $cache = $todas;
        return $todas;
    }

    /** Resolve nomes de tag → IDs (cria se não existir). */
    public function resolverTags(array $nomes): array
    {
        return $this->resolverTermos($nomes, '/tags');
    }

    private function resolverTermos(array $nomes, string $endpoint): array
    {
        $ids = [];
        foreach ($nomes as $nome) {
            $nome = trim($nome);
            if ($nome === '') continue;

            $slug = $this->slugifyTermo($nome);

            // 1ª tentativa: busca por slug (match mais confiável que nome)
            try {
                $bySlug = $this->request('GET', $endpoint . '?slug=' . urlencode($slug) . '&per_page=5');
                if (!empty($bySlug[0]['id'])) { $ids[] = (int)$bySlug[0]['id']; continue; }
            } catch (Throwable $e) {}

            // 2ª tentativa: busca full-text e compara case-insensitive
            try {
                $busca = $this->request('GET', $endpoint . '?search=' . urlencode($nome) . '&per_page=20');
                $achado = null;
                foreach ($busca as $t) {
                    $n1 = $this->normalizar($t['name'] ?? '');
                    $n2 = $this->normalizar($nome);
                    if ($n1 === $n2) { $achado = $t; break; }
                }
                if ($achado) { $ids[] = (int)$achado['id']; continue; }
            } catch (Throwable $e) {}

            // 3ª tentativa: cria; se WP responder term_exists, extrai o ID
            [$code, $body] = $this->requestRaw('POST', $endpoint, ['name' => $nome, 'slug' => $slug]);
            if ($code >= 200 && $code < 300 && !empty($body['id'])) {
                $ids[] = (int)$body['id'];
                continue;
            }
            if (($body['code'] ?? '') === 'term_exists') {
                $existingId = $body['data']['term_id'] ?? ($body['additional_data'][0] ?? null);
                if ($existingId) { $ids[] = (int)$existingId; continue; }
            }
            // falha silenciosa — não tenho ID, pula
        }
        return $ids;
    }

    /** Slug simples, sem acentos, minúsculo, hifens. */
    private function slugifyTermo(string $nome): string
    {
        $s = $this->normalizar($nome);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    }

    private function normalizar(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
        return strtr($s, $map);
    }

    /**
     * Variante de request() que NÃO dispara exception em HTTP ≥ 400.
     * Retorna [status_code, body_decodificado_ou_null].
     */
    private function requestRaw(string $method, string $path, array $payload = []): array
    {
        $ch = curl_init($this->base . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $this->auth,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ];
        if ($method !== 'GET' && !empty($payload)) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) return [0, null];
        $data = json_decode((string)$resp, true);
        return [$code, is_array($data) ? $data : null];
    }

    /**
     * Faz upload de um arquivo local como media do WP, SEM conversão WebP.
     * Usado para carrossel do Instagram (IG rejeita WebP).
     * @return array|null ['id'=>int, 'source_url'=>string] ou null em falha
     */
    public function uploadImagemLocalJpg(string $arquivoLocal, string $alt = ''): ?array
    {
        if (!file_exists($arquivoLocal)) return null;
        $bin = file_get_contents($arquivoLocal);
        if ($bin === false) return null;

        $nome = 'carrossel-' . time() . '-' . bin2hex(random_bytes(4)) . '.jpg';
        $mime = 'image/jpeg';

        $ch = curl_init($this->base . '/media');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bin,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $this->auth,
                'Content-Type: ' . $mime,
                'Content-Disposition: attachment; filename="' . $nome . '"',
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 201 && $code !== 200) return null;
        $data = json_decode($resp, true);
        $id = $data['id'] ?? null;
        if (!$id) return null;

        if ($alt !== '') {
            try { $this->request('POST', "/media/$id", ['alt_text' => $alt]); } catch (Throwable $e) {}
        }
        return ['id' => $id, 'source_url' => $data['source_url'] ?? ''];
    }

    /**
     * Sobe binário de imagem direto (já processado em memória).
     * Pula download (já temos o bin) mas reaproveita conversão webp + curl upload.
     * Usado quando precisamos modificar a imagem antes de enviar (ex: queimar overlay).
     */
    public function uploadImagemBinario(string $bin, string $slugBase, string $alt = '', string $extOriginal = 'jpg'): ?int
    {
        if ($bin === '') return null;
        $ext = strtolower($extOriginal);
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) $ext = 'jpg';
        $nome = $slugBase . '.' . $ext;

        // Tenta WebP via API externa; se falhar, GD reencode local
        if (!preg_match('/\.webp$/i', $nome)) {
            $conv = $this->converterParaWebp($bin, $nome);
            if ($conv) {
                $bin = $conv;
                $nome = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $nome);
            } else {
                require_once __DIR__ . '/ImagemOptimizer.php';
                $opt = new ImagemOptimizer();
                $reenc = $opt->reencode($bin, 'jpeg', 85);
                if ($reenc) {
                    $bin = $reenc;
                    $nome = preg_replace('/\.(png|gif|webp)$/i', '.jpg', $nome) ?? $nome;
                }
            }
        }
        $mime = $this->mimeFromName($nome);

        $ch = curl_init($this->base . '/media');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bin,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $this->auth,
                'Content-Type: ' . $mime,
                'Content-Disposition: attachment; filename="' . $nome . '"',
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 201 && $code !== 200) {
            throw new RuntimeException("WP upload binario falhou ($code): " . substr($resp, 0, 300));
        }
        $data = json_decode($resp, true);
        $id = $data['id'] ?? null;
        if ($id && $alt !== '') {
            try { $this->request('POST', "/media/$id", ['alt_text' => $alt]); } catch (Throwable $e) {}
        }
        return $id ? (int)$id : null;
    }

    /** Faz upload de uma URL de imagem como media do WP. Devolve ID. */
    public function uploadImagemPorUrl(string $url, string $alt = '', string $slugCustom = ''): ?int
    {
        $bin = $this->baixar($url);
        if (!$bin) return null;

        // Se slug foi passado, usa ele como nome (SEO-friendly); senão usa basename da URL
        if ($slugCustom !== '') {
            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) $ext = 'jpg';
            $nome = $slugCustom . '.' . $ext;
        } else {
            $nome = basename(parse_url($url, PHP_URL_PATH)) ?: 'imagem.jpg';
            if (!preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $nome)) $nome .= '.jpg';
        }

        // Cascata pra reduzir bandwidth (~30% smaller que JPEG):
        //  1. API externa gogleads (mais rápida, qualidade boa)
        //  2. GD local imagewebp (sem dep externa, nativo PHP)
        //  3. Fallback final: GD reencode como JPEG (strip metadata, mantém compat universal)
        if (!preg_match('/\.webp$/i', $nome)) {
            $conv = $this->converterParaWebp($bin, $nome);
            if ($conv) {
                $bin = $conv;
                $nome = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $nome);
            } else {
                require_once __DIR__ . '/ImagemOptimizer.php';
                $opt = new ImagemOptimizer();
                // Tenta WebP nativo via GD primeiro (PHP >= 7.1 com gd-webp)
                $webpLocal = $opt->reencode($bin, 'webp', 82);
                if ($webpLocal !== null) {
                    $bin = $webpLocal;
                    $nome = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $nome) ?? $nome;
                } else {
                    // Fallback final: JPEG (compat universal). Strip metadata é side-effect aceito.
                    $reenc = $opt->reencode($bin, 'jpeg', 85);
                    if ($reenc) {
                        $bin = $reenc;
                        $nome = preg_replace('/\.(png|gif|webp)$/i', '.jpg', $nome) ?? $nome;
                    }
                }
            }
        }
        $mime = $this->mimeFromName($nome);

        $ch = curl_init($this->base . '/media');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bin,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $this->auth,
                'Content-Type: ' . $mime,
                'Content-Disposition: attachment; filename="' . $nome . '"',
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 201 && $code !== 200) {
            throw new RuntimeException("WP upload falhou ($code): " . substr($resp, 0, 300));
        }
        $data = json_decode($resp, true);
        $id = $data['id'] ?? null;

        // Atualiza alt
        if ($id && $alt !== '') {
            $this->request('POST', "/media/$id", ['alt_text' => $alt]);
        }
        return $id;
    }

    /**
     * Converte uma imagem binária para WebP usando a API externa gogleads.
     * Retorna o binário WebP ou null em falha (o caller segue com o original).
     */
    private function converterParaWebp(string $bin, string $nome): ?string
    {
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
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        @unlink($tmp);

        if ($resp === false || $code >= 400 || $resp === '') return null;

        // Se a API retornou JSON (ex.: {"data":"base64..."} ou {"url":"..."})
        if (stripos($ct, 'application/json') !== false) {
            $json = json_decode($resp, true);
            if (is_array($json)) {
                if (!empty($json['data']) && is_string($json['data'])) {
                    $decoded = base64_decode($json['data'], true);
                    if ($decoded !== false) return $decoded;
                    return $json['data']; // fallback: já é binário
                }
                if (!empty($json['url'])) {
                    return $this->baixar($json['url']);
                }
            }
            return null;
        }

        // Caso padrão: binário WebP direto
        return $resp;
    }

    private function baixar(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $b = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($b !== false && $code < 400) ? $b : null;
    }

    private function mimeFromName(string $nome): string
    {
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        return [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        ][$ext] ?? 'image/jpeg';
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $url = $this->base . $path;
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $this->auth,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ];
        if ($method !== 'GET' && !empty($payload)) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) throw new RuntimeException("WP cURL: $err");
        if ($code >= 400)    throw new RuntimeException("WP HTTP $code em $method $path: " . substr($resp, 0, 400));

        $data = json_decode($resp, true);
        return is_array($data) ? $data : [];
    }
}
