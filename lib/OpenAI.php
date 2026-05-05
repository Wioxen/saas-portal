<?php
/**
 * Cliente OpenAI — usado como "editor revisor" que avalia e dá feedback
 * pro Claude refinar até 10/10. Modelo: gpt-4o-mini (barato e rápido).
 */
class OpenAI
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'gpt-4o-mini')
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    /**
     * Avalia um título para Google Discover.
     * Retorna ['nota'=>int(1-10), 'aprovado'=>bool, 'feedback'=>string, 'sugestao'=>string]
     */
    public function avaliarTitulo(string $titulo, string $conteudo, string $tituloOriginalRss = ''): array
    {
        $conteudoTrunc = mb_substr(trim($conteudo), 0, 6000);
        $system = <<<SYS
Você é o DONO do Google Discover. Nenhum título sai da sua mesa se não for 10/10.
Se chegar 9/10, você refaz. Não existe "bom o suficiente".

Especialista no nicho de educação, emprego, benefícios e cursos no Brasil.
Data atual: hoje, abril de 2026.

AVALIE o título nos 10 critérios (1 ponto cada):
1. FATO NA FRENTE — dado forte nas primeiras 5 palavras
2. TAMANHO — 55-68 caracteres
3. TEMPO VERBAL — correto pro fato
4. SEM INVENÇÃO — 100% dos dados existem no conteúdo
5. SEM CTA — zero "Saiba/Veja/Confira/Entenda/Descubra"
6. SEM CLICKBAIT — zero adjetivos vazios ou promessas
7. ENTIDADE COMO ÂNCORA — nome da instituição presente
8. DADO CONCRETO — pelo menos 1 dado específico (número, valor, prazo)
9. ZERO DESPERDÍCIO — cada palavra adiciona informação nova
10. PARA O SCROLL — o leitor PARA e precisa clicar

PRINCÍPIO DE OURO: Diga o máximo de informações NOVAS com o mínimo de palavras.

REGRA ZERO: se um dado NÃO está ESCRITO no conteúdo → NÃO existe. Título com dado inventado = nota ZERO.

Responda APENAS com JSON:
{"nota":N,"aprovado":true/false,"criterios_falhos":["lista dos que falharam"],"feedback":"1-2 frases do que está errado","sugestao":"título alternativo que seria 10/10 (ou vazio se já é 10)"}
SYS;

        $user = "TÍTULO PARA AVALIAR: {$titulo}\n";
        if ($tituloOriginalRss !== '') $user .= "TÍTULO ORIGINAL DO RSS: {$tituloOriginalRss}\n";
        $user .= "\nCONTEÚDO COMPLETO DA FONTE:\n{$conteudoTrunc}";

        $resp = $this->chat($system, $user, 800);
        $json = $this->extractJson($resp);
        return [
            'nota'      => (int)($json['nota'] ?? 0),
            'aprovado'  => (bool)($json['aprovado'] ?? false),
            'feedback'  => (string)($json['feedback'] ?? ''),
            'sugestao'  => (string)($json['sugestao'] ?? ''),
            'criterios' => $json['criterios_falhos'] ?? [],
        ];
    }

    /**
     * Avalia um artigo completo para Discover.
     * Retorna ['nota'=>int, 'aprovado'=>bool, 'feedback'=>string, 'problemas'=>array]
     */
    public function avaliarArtigo(string $html, string $titulo, string $keyword): array
    {
        $textoLimpo = mb_substr(strip_tags($html), 0, 8000);
        $palavras = str_word_count($textoLimpo);
        $system = <<<SYS
Você é editor-chefe revisor de artigos para Google Discover. Avalie RIGOROSAMENTE.

CHECKLIST (1 ponto cada, total 10):
1. TÍTULO — gera "como assim?" (não "ah, legal")? Tem benefício+autoridade+urgência?
2. P1 — funciona sozinho no preview do Discover? Fato+tempo+entidade+acelerador?
3. TAMANHO — 600-700 palavras (penalizar se <550 ou >800)
4. LOOPS — mínimo 5 loops de curiosidade distribuídos
5. TABELA — dados reais organizados
6. BLOCOS MAGNÉTICOS — 2 blocos com tensão real
7. SEO — keyword 5-7x natural + campo semântico
8. E-E-A-T — nomes completos, dados com atribuição, base legal
9. BLINDAGEM — zero dados inventados, zero expressões de IA ("vale destacar", "é importante", "diante disso")
10. COPY CONTROL — máx 2 frases de pressão, urgência informativa (não emocional vazia)

Responda APENAS com JSON:
{"nota":N,"aprovado":true/false,"palavras":{$palavras},"problemas":["lista dos itens <10"],"feedback":"o que precisa mudar para ser 10/10"}
SYS;

        $user = "TÍTULO: {$titulo}\nKEYWORD: {$keyword}\n\nARTIGO:\n{$textoLimpo}";
        $resp = $this->chat($system, $user, 1000);
        $json = $this->extractJson($resp);
        return [
            'nota'      => (int)($json['nota'] ?? 0),
            'aprovado'  => (bool)($json['aprovado'] ?? false),
            'palavras'  => (int)($json['palavras'] ?? $palavras),
            'problemas' => $json['problemas'] ?? [],
            'feedback'  => (string)($json['feedback'] ?? ''),
        ];
    }

    /** Versão pública — usada pelo DebateBuilder */
    public function chat(string $system, string $user, int $maxTokens = 1000): string
    {
        // gpt-5+ e o-series renomearam max_tokens → max_completion_tokens.
        // Manter max_tokens pra modelos legados (gpt-4o, gpt-4o-mini, gpt-3.5).
        $tokenParam = self::tokenParamFor($this->model);
        $payload = [
            'model'      => $this->model,
            $tokenParam  => $maxTokens,
            'messages'   => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ];
        require_once __DIR__ . '/HttpClient.php';
        require_once __DIR__ . '/CircuitBreaker.php';

        // Circuit breaker: 3 falhas em 60s → cooldown 5min. Caller (DiscoverGerador)
        // captura CircuitOpenException → marca trend `aguardando_llm`.
        $cb = new CircuitBreaker('openai');
        $cb->guarda();

        // gpt-5+ usa reasoning tokens internos que podem demorar 90-180s; subido pra 300s.
        $isReasoningModel = preg_match('/^(gpt-[5-9]|o\d)/', $this->model) === 1;
        $r = HttpClient::request('POST', 'https://api.openai.com/v1/chat/completions', [
            'json' => $payload,
            'headers' => ['Authorization: Bearer ' . $this->apiKey],
            'timeout' => $isReasoningModel ? 300 : 120,
            'tries' => 2,
            'backoff' => [0, 4],
        ]);
        if (!$r['ok']) {
            // Só conta como falha de API se for transitório (5xx/429/timeout). 4xx (auth/quota) não.
            if (self::ehFalhaTransitoria((int)$r['http_code'])) {
                $cb->falha("HTTP {$r['http_code']}");
            }
            throw new RuntimeException("OpenAI HTTP {$r['http_code']}: " . substr($r['body'], 0, 300));
        }
        $cb->sucesso();
        return $r['json']['choices'][0]['message']['content'] ?? '';
    }

    /** HTTP code que justifica abrir circuit (API genuinamente em problema). */
    private static function ehFalhaTransitoria(int $code): bool
    {
        return $code === 0 || $code === 408 || $code === 429 || ($code >= 500 && $code <= 599);
    }

    /**
     * Decide qual parametro de limite de tokens usar para o modelo.
     * gpt-5+ / gpt-6 / o-series usam max_completion_tokens.
     * gpt-4o*, gpt-4*, gpt-3.5*, etc. continuam com max_tokens.
     */
    private static function tokenParamFor(string $model): string
    {
        return preg_match('/^(gpt-[5-9]|o\d)/', $model) === 1
            ? 'max_completion_tokens'
            : 'max_tokens';
    }

    public function extractJsonPublic(string $text): array { return $this->extractJson($text); }

    /**
     * Gera imagem via OpenAI (dall-e-3 por padrão, tamanho 1792x1024 ≈ 16:9 pra Discover).
     * Retorna URL da imagem (válida por ~1h — deve ser baixada rapidamente) ou null em falha.
     *
     * @param string $prompt    Descrição da imagem
     * @param string $size      '1792x1024' (landscape 16:9-ish), '1024x1792' (portrait), '1024x1024'
     * @param string $quality   'hd' (melhor, +custo) ou 'standard'
     * @param string $style     'natural' (mais editorial) ou 'vivid' (mais estilizado)
     * @param string $model     'dall-e-3' (padrão) ou 'gpt-image-1'
     * @return string|null URL da imagem ou null em falha
     */
    public function gerarImagem(string $prompt, string $size = '1792x1024', string $quality = 'hd', string $style = 'natural', string $model = 'dall-e-3'): ?string
    {
        $payload = [
            'model'   => $model,
            'prompt'  => mb_substr($prompt, 0, 4000, 'UTF-8'),
            'n'       => 1,
            'size'    => $size,
            'quality' => $quality,
            'style'   => $style,
        ];
        require_once __DIR__ . '/HttpClient.php';
        require_once __DIR__ . '/CircuitBreaker.php';

        // Circuit breaker compartilhado com `chat()` — DALL-E e ChatGPT vivem na mesma
        // infra OpenAI. Threshold mais permissivo (5 falhas) porque imagem é menos crítica.
        $cb = new CircuitBreaker('openai_image', 5, 120, 300);
        try {
            $cb->guarda();
        } catch (CircuitOpenException $e) {
            // Imagem é opcional — falha-silenciosa em vez de propagar (callers já tratam null)
            return null;
        }

        $r = HttpClient::request('POST', 'https://api.openai.com/v1/images/generations', [
            'json' => $payload,
            'headers' => ['Authorization: Bearer ' . $this->apiKey],
            'timeout' => 90,
            'tries' => 2,
            'backoff' => [0, 4],
        ]);
        if (!$r['ok']) {
            if (self::ehFalhaTransitoria((int)$r['http_code'])) {
                $cb->falha("HTTP {$r['http_code']}");
            }
            return null;
        }
        $cb->sucesso();
        return $r['json']['data'][0]['url'] ?? null;
    }

    /**
     * Versão estendida: retorna URL + revised_prompt + parâmetros usados.
     * O revised_prompt é o que DALL-E 3 REALMENTE usou (depois do rewriter interno) —
     * essencial pra debugar diferença entre "API" e "ChatGPT direto".
     *
     * @return array{url: ?string, revised_prompt: ?string, prompt_enviado: string, style: string, size: string, quality: string}|null
     *                null se falhou totalmente
     */
    public function gerarImagemDetalhado(string $prompt, string $size = '1792x1024', string $quality = 'hd', string $style = 'vivid', string $model = 'dall-e-3'): ?array
    {
        $promptEnviado = mb_substr($prompt, 0, 4000, 'UTF-8');
        $payload = [
            'model'   => $model,
            'prompt'  => $promptEnviado,
            'n'       => 1,
            'size'    => $size,
            'quality' => $quality,
            'style'   => $style,
        ];
        require_once __DIR__ . '/HttpClient.php';
        require_once __DIR__ . '/CircuitBreaker.php';

        $cb = new CircuitBreaker('openai_image', 5, 120, 300);
        try {
            $cb->guarda();
        } catch (CircuitOpenException $e) {
            return null;
        }

        $r = HttpClient::request('POST', 'https://api.openai.com/v1/images/generations', [
            'json' => $payload,
            'headers' => ['Authorization: Bearer ' . $this->apiKey],
            'timeout' => 90,
            'tries' => 2,
            'backoff' => [0, 4],
        ]);
        if (!$r['ok']) {
            if (self::ehFalhaTransitoria((int)$r['http_code'])) {
                $cb->falha("HTTP {$r['http_code']}");
            }
            return null;
        }
        $cb->sucesso();
        return [
            'url'             => $r['json']['data'][0]['url']            ?? null,
            'revised_prompt'  => $r['json']['data'][0]['revised_prompt'] ?? null,
            'prompt_enviado'  => $promptEnviado,
            'style'           => $style,
            'size'            => $size,
            'quality'         => $quality,
        ];
    }

    private function extractJson(string $text): array
    {
        $text = trim($text);
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $j = json_decode($m[0], true);
            if (is_array($j)) return $j;
        }
        return [];
    }
}
