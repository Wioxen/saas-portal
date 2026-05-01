<?php
/**
 * SocialPoster — orquestrador de distribuição multi-canal pra cada post publicado.
 *
 * Estratégia:
 *   1. Lê `cfg.social` do site (quais canais estão `enabled` + creds)
 *   2. Pra cada canal habilitado, chama driver correspondente
 *   3. Adapta mensagem ao limite/idioma da plataforma
 *   4. Loga resultado em data/social_log/{YYYY-MM}.jsonl pra observabilidade
 *
 * Falha-silenciosa: se 1 canal falha, outros continuam. Erro NÃO bloqueia o post WP.
 *
 * Uso (em DiscoverGerador, após publicação confirmada):
 *   SocialPoster::publicar([
 *       'titulo'      => 'Post viral',
 *       'url'         => 'https://site.com/post',
 *       'imagem_url'  => 'https://site.com/featured.jpg',  // opcional
 *       'site_slug'   => 'cursosenac',
 *       'cluster_key' => 'noticias_info_critica',
 *       'post_id'     => 1234,
 *   ], $cfg);
 *
 * Drivers disponíveis: bluesky, threads. (X virá quando dev portal aprovar.)
 */
class SocialPoster
{
    private const PATH_LOG = '/../data/social_log';

    /**
     * Posta em todos os canais ativos. Retorna resultado por canal.
     *
     * @return array {sucessos: int, falhas: int, por_canal: [canal => {ok, urls?, erro?}]}
     */
    public static function publicar(array $post, array $cfg): array
    {
        $social = $cfg['social'] ?? [];
        if (!is_array($social) || empty($social)) {
            return ['sucessos' => 0, 'falhas' => 0, 'por_canal' => [], 'nota' => 'sem cfg.social'];
        }

        $titulo = trim((string)($post['titulo'] ?? ''));
        $url    = trim((string)($post['url'] ?? ''));
        if ($titulo === '' || $url === '') {
            return ['sucessos' => 0, 'falhas' => 0, 'erro' => 'titulo/url obrigatórios'];
        }

        $sucessos = 0;
        $falhas = 0;
        $porCanal = [];

        foreach ($social as $canal => $cfgCanal) {
            if (!is_array($cfgCanal) || empty($cfgCanal['enabled'])) continue;

            try {
                $driver = self::carregarDriver($canal);
                if ($driver === null) {
                    $porCanal[$canal] = ['ok' => false, 'erro' => 'driver_inexistente'];
                    $falhas++;
                    continue;
                }

                $mensagem = self::adaptarMensagem($titulo, $url, $canal, $cfgCanal);
                $r = $driver::postar($mensagem, $url, $post, $cfgCanal);
                $porCanal[$canal] = $r;
                if (!empty($r['ok'])) $sucessos++; else $falhas++;
            } catch (Throwable $e) {
                $porCanal[$canal] = ['ok' => false, 'erro' => $e->getMessage()];
                $falhas++;
            }
        }

        // Log
        self::logEvento([
            'ts'         => date('c'),
            'site_slug'  => (string)($post['site_slug'] ?? ''),
            'post_id'    => (int)($post['post_id'] ?? 0),
            'titulo'     => mb_substr($titulo, 0, 200),
            'url'        => $url,
            'sucessos'   => $sucessos,
            'falhas'     => $falhas,
            'canais'     => array_keys($porCanal),
            'resultados' => $porCanal,
        ]);

        return [
            'sucessos'  => $sucessos,
            'falhas'    => $falhas,
            'por_canal' => $porCanal,
        ];
    }

    /**
     * Adapta mensagem por plataforma:
     *  - X / Twitter: ≤280 chars (titulo encurtado + url; URL conta 23)
     *  - Threads:     ≤500 chars (mais espaço pra contexto)
     *  - Bluesky:     ≤300 chars
     */
    public static function adaptarMensagem(string $titulo, string $url, string $canal, array $cfgCanal): string
    {
        $limites = [
            'x'        => 280,
            'twitter'  => 280,
            'bluesky'  => 300,
            'threads'  => 500,
            'mastodon' => 500,
        ];
        $limite = $limites[$canal] ?? 280;
        $reservaUrl = mb_strlen($url) + 2; // 2 chars de quebra

        // Hashtag opcional (cfg.canal.hashtags = ['concurso', 'enem'])
        $hashtags = '';
        if (!empty($cfgCanal['hashtags']) && is_array($cfgCanal['hashtags'])) {
            $tags = array_slice(array_map(fn($t) => '#' . preg_replace('/[^a-zA-Z0-9_]/', '', $t), $cfgCanal['hashtags']), 0, 3);
            $hashtags = "\n\n" . implode(' ', $tags);
        }

        $reservaHashtag = mb_strlen($hashtags);
        $espacoTitulo = $limite - $reservaUrl - $reservaHashtag;

        $tituloAjustado = $titulo;
        if (mb_strlen($titulo) > $espacoTitulo) {
            $tituloAjustado = mb_substr($titulo, 0, max(50, $espacoTitulo - 1)) . '…';
        }

        return $tituloAjustado . "\n\n" . $url . $hashtags;
    }

    /**
     * Carrega driver dinamicamente. Retorna FQCN como string (usar :: postar()).
     */
    private static function carregarDriver(string $canal): ?string
    {
        $map = [
            'bluesky'  => 'SocialBluesky',
            'threads'  => 'SocialThreads',
            'x'        => 'SocialX',
            'twitter'  => 'SocialX',
        ];
        $class = $map[$canal] ?? null;
        if ($class === null) return null;
        $path = __DIR__ . '/' . $class . '.php';
        if (!is_file($path)) return null;
        require_once $path;
        return class_exists($class) ? $class : null;
    }

    private static function logEvento(array $evento): void
    {
        $dir = __DIR__ . self::PATH_LOG;
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $file = $dir . '/' . date('Y-m') . '.jsonl';
        $linha = json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($file, $linha . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Lê log. Útil pra dashboard de social posts.
     */
    public static function lerLog(string $mes, array $filtros = []): array
    {
        $file = __DIR__ . self::PATH_LOG . '/' . $mes . '.jsonl';
        if (!is_file($file)) return [];
        $out = [];
        $fp = @fopen($file, 'rb');
        if (!$fp) return [];
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $e = json_decode($line, true);
            if (!is_array($e)) continue;
            if (isset($filtros['site_slug']) && ($e['site_slug'] ?? '') !== $filtros['site_slug']) continue;
            if (isset($filtros['post_id']) && (int)($e['post_id'] ?? 0) !== (int)$filtros['post_id']) continue;
            $out[] = $e;
        }
        @fclose($fp);
        return $out;
    }
}
