<?php
declare(strict_types=1);

/**
 * SerperImages — busca imagens via Google Images (Serper API).
 *
 * Diferente do Pexels (foto stock genérica), retorna imagens REAIS do contexto
 * (jogadores, estádios, treinos) puxadas dos portais que aparecem no Google Images.
 *
 * Uso:
 *   $sx = new SerperImages($cfg['serper_api_key']);
 *   $img = $sx->melhor("Vitoria x Fluminense Maracana", ['min_w' => 900]);
 *   if ($img) {
 *       $url = $img['imageUrl'];      // direto pro arquivo
 *       $width = $img['imageWidth'];
 *       $credito = $img['credito'];   // host extraído (ge.globo, etc.) — usar como "Foto: divulgação" se preferir
 *   }
 *
 * NOTA: Imagens vêm de portais terceiros. Para evitar "Foto: ge.globo" no crédito,
 * passe ['credito_generico' => true] que retorna 'Foto: divulgação' / 'Foto: reprodução'.
 */
class SerperImages
{
    private string $apiKey;
    private const ENDPOINT = 'https://google.serper.dev/images';

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Retorna a melhor imagem candidata baseada em score (tamanho + recência da URL + domínio).
     *
     * @param string $query   Termo de busca (ex: "Vitoria x Fluminense Maracana")
     * @param array  $opts    [
     *   'num' => 12,             // qtas pegar do Google
     *   'min_w' => 800,          // largura mínima aceita
     *   'min_h' => 400,          // altura mínima aceita
     *   'preferir_recente' => true,  // boost se URL tem /2026/ ou /YYYY-MM/
     *   'credito_generico' => true,  // usa "divulgação" em vez de nome do site
     *   'evitar_dominios' => ['instagram.com', 'tiktok.com', 'twitter.com'],
     * ]
     * @return array|null  ['imageUrl', 'imageWidth', 'imageHeight', 'title', 'credito', 'sourceUrl', 'score']
     */
    public function melhor(string $query, array $opts = []): ?array
    {
        $num = (int)($opts['num'] ?? 12);
        $minW = (int)($opts['min_w'] ?? 800);
        $minH = (int)($opts['min_h'] ?? 400);
        $preferirRecente = !empty($opts['preferir_recente']) || !isset($opts['preferir_recente']);
        $creditoGenerico = !empty($opts['credito_generico']);
        $evitar = $opts['evitar_dominios'] ?? ['instagram.com', 'tiktok.com', 'twitter.com', 'x.com', 'facebook.com', 'pinterest.com'];

        $candidatas = $this->buscarRaw($query, $num);
        if (empty($candidatas)) return null;

        $melhor = null;
        $melhorScore = -1;
        foreach ($candidatas as $img) {
            $w = (int)($img['imageWidth'] ?? 0);
            $h = (int)($img['imageHeight'] ?? 0);
            $url = (string)($img['imageUrl'] ?? '');
            $sourceUrl = (string)($img['link'] ?? '');
            if ($w < $minW || $h < $minH) continue;
            if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
            if (parse_url($url, PHP_URL_SCHEME) !== 'https') continue;
            $host = parse_url($sourceUrl, PHP_URL_HOST) ?: parse_url($url, PHP_URL_HOST);
            foreach ($evitar as $bloq) {
                if ($host && stripos($host, $bloq) !== false) continue 2;
            }

            $score = $this->scorear($img, $url, $sourceUrl, $preferirRecente);
            if ($score > $melhorScore) {
                $melhorScore = $score;
                $melhor = $img;
                $melhor['score'] = $score;
                $melhor['credito'] = $creditoGenerico
                    ? 'divulgação'
                    : ($host ?: 'reprodução');
                $melhor['sourceUrl'] = $sourceUrl;
            }
        }
        return $melhor;
    }

    public function buscarRaw(string $query, int $num = 12): array
    {
        $payload = ['q' => $query, 'gl' => 'br', 'hl' => 'pt-br', 'num' => $num];
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) return [];
        $d = json_decode((string)$r, true);
        return (array)($d['images'] ?? []);
    }

    private function scorear(array $img, string $url, string $sourceUrl, bool $preferirRecente): int
    {
        $w = (int)($img['imageWidth'] ?? 0);
        $h = (int)($img['imageHeight'] ?? 0);
        $score = min($w, 2000) / 10; // até 200 pts pra resolução

        // Boost se URL ou sourceUrl tem padrão de data recente
        if ($preferirRecente) {
            $anoAtual = (int)date('Y');
            $anoPassado = $anoAtual - 1;
            if (strpos($url, "/{$anoAtual}/") !== false || strpos($sourceUrl, "/{$anoAtual}/") !== false) $score += 100;
            elseif (strpos($url, "/{$anoPassado}/") !== false || strpos($sourceUrl, "/{$anoPassado}/") !== false) $score += 30;
        }

        // Boost domínios esportivos brasileiros confiáveis
        $bonus = ['ge.globo.com' => 50, 'lance.com.br' => 40, 'gazetaesportiva.com' => 30, 'placar.com.br' => 30, 'futebolbaiano.com.br' => 30, 'soudabahia.com.br' => 30, 'ecvitoria.com.br' => 50];
        $host = parse_url($sourceUrl ?: $url, PHP_URL_HOST) ?: '';
        foreach ($bonus as $d => $b) {
            if (stripos($host, $d) !== false) { $score += $b; break; }
        }

        // Penalidade thumbnails do YouTube (i.ytimg.com) — preferimos foto profissional
        if (stripos($url, 'ytimg.com') !== false) $score -= 50;

        return (int)$score;
    }
}
