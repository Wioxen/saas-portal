<?php
/**
 * Pexels — client mínimo da API REST v1.
 *
 * Endpoint: https://api.pexels.com/v1/search
 * Auth: Authorization: <api_key> (header simples, não Bearer)
 * Rate limit: 200 req/h e 20.000/mês no plano free.
 *
 * Foco editorial:
 *   - Sempre orientation=landscape (Discover usa 16:9)
 *   - Filtra resultados sem alt-text (lixo / stock terrível)
 *   - Re-rank pra evitar imagens com texto sobreposto (heurística por alt + dimensão)
 *
 * Uso típico (via DiscoverImagemFeatured, não diretamente):
 *   $pex = new Pexels($apiKey);
 *   $candidatos = $pex->buscar('student studying desk', 15);
 *   foreach ($candidatos as $c) echo $c['url']; // top scores primeiro
 */
class Pexels
{
    private const ENDPOINT = 'https://api.pexels.com/v1/search';
    private const TIMEOUT  = 12;

    private string $apiKey;

    public function __construct(string $apiKey)
    {
        if ($apiKey === '') throw new InvalidArgumentException('Pexels API key vazia');
        $this->apiKey = $apiKey;
    }

    /**
     * Busca fotos por query. Retorna lista ordenada por score editorial decrescente.
     *
     * @param string $query Termo de busca (curto: 2-4 palavras em inglês funciona melhor)
     * @param int    $perPage Limite (max 80). Default 15 — equilibra resultado bom + custo de rate
     * @param string $orientation 'landscape' (default), 'portrait' ou 'square'
     * @return array<int,array> [{id, url, photographer, alt, width, height, score}]
     *                          score é 0-100 (heurística — sem texto, alta resolução, alt informativo)
     */
    public function buscar(string $query, int $perPage = 15, string $orientation = 'landscape'): array
    {
        if (trim($query) === '') return [];

        $url = self::ENDPOINT . '?' . http_build_query([
            'query'       => $query,
            'per_page'    => max(1, min(80, $perPage)),
            'orientation' => $orientation,
            'size'        => 'large',  // ≥1920px na largura — atende mínimo Discover
        ]);

        require_once __DIR__ . '/HttpClient.php';
        $r = HttpClient::get($url, [
            'headers' => ['Authorization: ' . $this->apiKey],
            'timeout' => self::TIMEOUT,
            'tries'   => 2,
            'backoff' => [0, 2],
        ]);
        if (!$r['ok']) return [];
        $data = $r['json'];
        if (!is_array($data) || empty($data['photos'])) return [];

        $candidatos = [];
        foreach ($data['photos'] as $p) {
            $alt = trim((string)($p['alt'] ?? ''));
            // Heurística sem alt → lixo (stock genérico/sem contexto)
            if (mb_strlen($alt) < 8) continue;

            // Prefere large2x (≈1920×1280) sobre original (que pode ser 8000px+)
            $imgUrl = (string)($p['src']['large2x'] ?? $p['src']['large'] ?? $p['src']['original'] ?? '');
            if ($imgUrl === '') continue;

            $candidatos[] = [
                'id'            => (int)($p['id'] ?? 0),
                'url'           => $imgUrl,
                'photographer'  => (string)($p['photographer'] ?? ''),
                'alt'           => $alt,
                'width'         => (int)($p['width'] ?? 0),
                'height'        => (int)($p['height'] ?? 0),
                'score'         => self::scorearImagem($p, $alt),
            ];
        }

        usort($candidatos, fn($a, $b) => $b['score'] <=> $a['score']);
        return $candidatos;
    }

    /**
     * Heurística de re-rank.
     * Sinais positivos: alt rico (>30 chars), pessoa/contexto humano no alt, alta resolução
     * Sinais negativos: alt curto/vazio, palavras sinalizadoras de imagem com texto sobreposto
     */
    private static function scorearImagem(array $p, string $alt): int
    {
        $score = 50;
        $altLow = mb_strtolower($alt, 'UTF-8');

        // Resolução
        $w = (int)($p['width'] ?? 0);
        if ($w >= 4000) $score += 8;
        elseif ($w >= 2400) $score += 5;
        elseif ($w >= 1600) $score += 2;

        // Alt-text rico
        $alLen = mb_strlen($alt);
        if ($alLen > 60) $score += 8;
        elseif ($alLen > 30) $score += 4;

        // Palavras humanas — Discover ama foto com gente
        foreach (['person', 'man', 'woman', 'child', 'student', 'teacher', 'family', 'people', 'human', 'group'] as $kw) {
            if (str_contains($altLow, $kw)) { $score += 6; break; }
        }

        // Sinalizadores de TEXTO SOBREPOSTO (ruim — Discover penaliza)
        foreach (['poster', 'banner', 'logo', 'sign', 'writing', 'text', 'caption', 'label', 'screenshot', 'document', 'paper with text', 'announcement'] as $kw) {
            if (str_contains($altLow, $kw)) { $score -= 25; break; }
        }

        // Sinalizadores de imagem stock genérica/3D rendering
        foreach (['3d', '3d render', 'illustration', 'cartoon', 'drawing', 'vector', 'icon', 'graphic'] as $kw) {
            if (str_contains($altLow, $kw)) { $score -= 15; break; }
        }

        return max(0, min(100, $score));
    }
}
