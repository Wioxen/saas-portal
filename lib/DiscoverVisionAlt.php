<?php
/**
 * DiscoverVisionAlt — gera alt text rico contextual usando GPT-4o-mini-vision.
 *
 * Hoje alt text tipico: "imagem do {tema}" — pobre. Vision lê imagem real e gera:
 *   "Mulher idosa olhando para o smartphone com expressão de surpresa, sentada
 *   em sala de estar iluminada"
 *
 * Custo: ~$0.001 por imagem (gpt-4o-mini com modalidade vision).
 *
 * Uso:
 *   $alt = DiscoverVisionAlt::gerar($imageUrl, $titulo, $cfg);
 *   if ($alt) $wp->atualizarMedia($mediaId, ['alt_text' => $alt, 'caption' => $alt]);
 *
 * Falha-silenciosa: sem OPENAI_API_KEY, URL inválida, ou vision falhar → retorna null.
 * Caller usa alt antigo (que já existe).
 */
class DiscoverVisionAlt
{
    /**
     * @param string $imageUrl URL pública da imagem (PNG/JPG/WebP)
     * @param string $contexto Tema/título do post — ajuda Vision a não inventar
     * @param array  $cfg      cfg site (precisa openai_api_key)
     * @return string|null alt text ou null se falhar
     */
    public static function gerar(string $imageUrl, string $contexto, array $cfg): ?string
    {
        $apiKey = trim((string)($cfg['openai_api_key'] ?? ''));
        if ($apiKey === '' || $imageUrl === '') return null;
        if (!preg_match('#^https?://#i', $imageUrl)) return null;

        require_once __DIR__ . '/HttpClient.php';
        require_once __DIR__ . '/CircuitBreaker.php';

        $cb = new CircuitBreaker('openai');
        try { $cb->guarda(); } catch (CircuitOpenException $e) { return null; }

        $payload = [
            'model'       => 'gpt-4o-mini',
            'max_tokens'  => 150,
            'temperature' => 0.4,
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => 'Você é um redator de alt text pra acessibilidade e SEO em pt-BR. '
                              . 'Descreva o que VÊ na imagem em 1 frase de 80-180 caracteres. '
                              . 'NÃO inicie com "imagem de", "foto de", "ilustração". Comece direto pela cena. '
                              . 'NÃO especule sobre marcas, identidades ou metadados. '
                              . 'Use linguagem acessível, descreva pessoas pela aparência observável (ex: "mulher idosa de cabelo grisalho").',
                ],
                [
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => "Contexto do post: " . mb_substr($contexto, 0, 200) . "\n\nDescreva a imagem em 1 frase só (alt text)."],
                        ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'low']],
                    ],
                ],
            ],
        ];

        $r = HttpClient::request('POST', 'https://api.openai.com/v1/chat/completions', [
            'json'    => $payload,
            'headers' => ['Authorization: Bearer ' . $apiKey],
            'timeout' => 30,
            'tries'   => 2,
            'backoff' => [0, 3],
        ]);
        if (empty($r['ok'])) {
            // Falha transitória? Conta no circuit
            $code = (int)($r['http_code'] ?? 0);
            if ($code === 0 || $code === 408 || $code === 429 || ($code >= 500 && $code <= 599)) {
                $cb->falha("vision HTTP {$code}");
            }
            return null;
        }
        $cb->sucesso();

        $alt = trim((string)($r['json']['choices'][0]['message']['content'] ?? ''));
        if ($alt === '') return null;

        // Sanitização
        $alt = preg_replace('/^["\'\s]+|["\'\s]+$/u', '', $alt) ?? $alt;
        $alt = preg_replace('/\s+/u', ' ', $alt) ?? $alt;
        // Tira padrões "Imagem de" / "Foto mostra"
        $alt = preg_replace('/^(imagem de |foto de |foto mostra |a imagem mostra |a foto mostra )/iu', '', $alt) ?? $alt;
        $alt = mb_substr(trim($alt), 0, 250);

        if (mb_strlen($alt) < 30) return null; // muito curto = não confiável

        // Log custo
        try {
            $usage = $r['json']['usage'] ?? [];
            require_once __DIR__ . '/CostTracker.php';
            CostTracker::logManual('openai', [
                'modelo'        => 'gpt-4o-mini',
                'input_tokens'  => (int)($usage['prompt_tokens'] ?? 0),
                'output_tokens' => (int)($usage['completion_tokens'] ?? 0),
                'tipo'          => 'vision_alt',
                'ts_unix'       => time(),
            ]);
        } catch (Throwable $e) { /* log opcional */ }

        return $alt;
    }
}
