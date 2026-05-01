<?php
/**
 * DiscoverWebStory — client que chama o plugin wp-web-stories-ai.
 *
 * O plugin vive no WP do user e gera Web Stories (5-9 cenas) a partir
 * de um post existente, alimentando o post_type `web-story` do plugin
 * oficial Web Stories for WordPress do Google.
 *
 * Endpoint: POST /wp-json/wp-wsai/v1/create-story
 * Auth: Basic (wp_user + wp_app_password)
 *
 * Arquitetura do fluxo:
 *   [Post WP] → DiscoverWebStory::gerar($postId, $contexto) → plugin wsai
 *      → GPT-4o-mini monta narrativa (Hook + Desenvolvimento + CTA)
 *      → Pexels busca imagens por cena
 *      → cria post_type `web-story` (rascunho pro Google Web Stories plugin)
 *
 * Falha silenciosa (try-catch no caller) — Web Story é bonus, não bloqueia pipeline.
 */
class DiscoverWebStory
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * Gera Web Story para um post publicado.
     *
     * @param int $postId ID do post WP que serve de base
     * @param array $contexto ['keyword'=>..., 'meta_description'=>..., 'resposta_direta'=>..., 'imagem_prompt'=>..., 'dna'=>[]]
     * @return array ['ok'=>bool, 'story_id'=>int|null, 'scenes'=>int, 'view_url'=>string, 'tempo_ms'=>int, 'erro'=>?string, 'http_code'=>int]
     */
    public function gerar(int $postId, array $contexto = []): array
    {
        $t0 = microtime(true);
        $retornoBase = [
            'ok'        => false,
            'story_id'  => null,
            'scenes'    => 0,
            'view_url'  => '',
            'tempo_ms'  => 0,
            'erro'      => null,
            'http_code' => 0,
        ];

        if ($postId <= 0) {
            return array_merge($retornoBase, ['erro' => 'post_id inválido', 'tempo_ms' => 0]);
        }
        if (empty($this->cfg['wp_url']) || empty($this->cfg['wp_user']) || empty($this->cfg['wp_app_password'])) {
            return array_merge($retornoBase, ['erro' => 'credenciais WP ausentes']);
        }

        $payload = [
            'post_id'           => $postId,
            'min_scenes'        => (int)($this->cfg['webstory_min_scenes'] ?? 5),
            'max_scenes'        => (int)($this->cfg['webstory_max_scenes'] ?? 9),
            'keyword'           => (string)($contexto['keyword'] ?? ''),
            'meta_description'  => (string)($contexto['meta_description'] ?? ''),
            'resposta_direta'   => (string)($contexto['resposta_direta'] ?? ''),
            'imagem_prompt'     => (string)($contexto['imagem_prompt'] ?? ''),
            'dna'               => (array)($contexto['dna'] ?? []),
        ];

        // 2 formatos de URL — pretty permalinks primeiro, fallback pra rest_route plain
        $urls = [
            rtrim($this->cfg['wp_url'], '/') . '/wp-json/wp-wsai/v1/create-story',
            rtrim($this->cfg['wp_url'], '/') . '/?rest_route=/wp-wsai/v1/create-story',
        ];
        $auth = 'Basic ' . base64_encode($this->cfg['wp_user'] . ':' . $this->cfg['wp_app_password']);

        $httpCodeLast = 0;
        $ultimoErro = null;
        foreach ($urls as $url) {
            $resp = $this->postJson($url, $payload, $auth, 90);
            $httpCodeLast = $resp['http_code'];
            if ($resp['http_code'] === 200 && !empty($resp['body'])) {
                $data = json_decode($resp['body'], true);
                if (!empty($data['success']) && !empty($data['story_id'])) {
                    return [
                        'ok'        => true,
                        'story_id'  => (int)$data['story_id'],
                        'scenes'    => (int)($data['scenes'] ?? 0),
                        'view_url'  => (string)($data['view_url'] ?? ''),
                        'tempo_ms'  => (int)((microtime(true) - $t0) * 1000),
                        'erro'      => null,
                        'http_code' => 200,
                    ];
                }
                $ultimoErro = $data['error'] ?? ($data['message'] ?? 'resposta inválida do plugin');
                break; // 200 mas sem success: problema de negócio, não de rota
            }
            $ultimoErro = "HTTP {$resp['http_code']}" . ($resp['error'] ? ' · ' . $resp['error'] : '');
            // Só tenta URL alternativa se foi 404 (rota não existe nesse formato)
            if ($resp['http_code'] !== 404) break;
        }

        return [
            'ok'        => false,
            'story_id'  => null,
            'scenes'    => 0,
            'view_url'  => '',
            'tempo_ms'  => (int)((microtime(true) - $t0) * 1000),
            'erro'      => $ultimoErro ?? 'desconhecido',
            'http_code' => $httpCodeLast,
        ];
    }

    /** Wrapper cURL POST json auth. Retorna [http_code, body, error]. */
    private function postJson(string $url, array $payload, string $auth, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: ' . $auth,
            ],
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'body'      => is_string($body) ? $body : '',
            'error'     => $error,
        ];
    }

    /**
     * Decide se deve gerar Web Story para um trend, baseado em:
     *   - cfg.webstory_enabled == 1
     *   - ROI do cluster >= cfg.webstory_roi_min (default 5.0)
     *
     * Lógica: não faz sentido gastar chamadas GPT + Pexels em trends de clusters
     * com RPM baixo (esporte R$ 6/mil, entretenimento R$ 7/mil). Focar onde paga.
     */
    public static function deveGerar(array $cfg, string $clusterKey): bool
    {
        if (empty($cfg['webstory_enabled'])) return false;
        require_once __DIR__ . '/TrendsTaxonomia.php';
        $roi = TrendsTaxonomia::roiEditorial($clusterKey);
        // Threshold baixado de 5.0 → 1.3 — Web Story é canal direto Discover
        // (distribuição premium). ROI é normalizado [1-10] sobre rpm_max=42.
        // Threshold 1.3 inclui ~95% dos clusters (educacao 2.6, esportes 1.4, comidas 2.9)
        // e exclui só curiosidades_geral (1.2). Custo de Web Story compensa em qualquer cluster.
        $min = (float)($cfg['webstory_roi_min'] ?? 1.3);
        return $roi >= $min;
    }
}
