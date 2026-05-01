<?php
/**
 * Extrai transcrição/legendas de vídeos do YouTube.
 *
 * Estratégia:
 *  1. Busca legendas manuais (pt-BR, pt, en)
 *  2. Fallback: legendas automáticas (asr)
 *  3. Parseia XML de legendas → texto limpo
 */
class YouTube
{
    private string $userAgent;
    private int $timeout;

    public function __construct(string $userAgent = '', int $timeout = 15)
    {
        $this->userAgent = $userAgent ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $this->timeout = $timeout;
    }

    /**
     * Extrai transcrição de um vídeo do YouTube.
     * @return array ['video_id', 'title', 'transcript', 'language', 'segments']
     */
    public function transcrever(string $url): array
    {
        $videoId = $this->extractVideoId($url);
        if (!$videoId) throw new RuntimeException("URL inválida: {$url}");

        // Busca página do vídeo pra extrair dados
        $html = $this->fetch("https://www.youtube.com/watch?v={$videoId}");
        if (!$html) throw new RuntimeException("Não conseguiu acessar o vídeo");

        // Extrai título
        $title = '';
        if (preg_match('/<title>(.+?)<\/title>/', $html, $m)) {
            $title = html_entity_decode(str_replace(' - YouTube', '', $m[1]), ENT_QUOTES, 'UTF-8');
        }

        // Extrai captions tracks do playerResponse
        $captionTracks = $this->extractCaptionTracks($html);
        if (empty($captionTracks)) {
            throw new RuntimeException("Vídeo sem legendas disponíveis: {$videoId}");
        }

        // Prioridade: pt-BR manual > pt manual > pt auto > en manual > en auto > qualquer
        $track = $this->selectBestTrack($captionTracks);
        if (!$track) throw new RuntimeException("Nenhuma legenda compatível encontrada");

        // Baixa XML de legendas
        $captionUrl = $track['baseUrl'] ?? '';
        if ($captionUrl === '') throw new RuntimeException("URL de legendas vazia");

        $xml = $this->fetch($captionUrl);
        if (!$xml) throw new RuntimeException("Falha ao baixar legendas");

        // Parseia XML → segmentos
        $segments = $this->parseXml($xml);
        $transcript = implode(' ', array_column($segments, 'text'));

        // Limpa texto
        $transcript = preg_replace('/\s+/', ' ', $transcript);
        $transcript = trim($transcript);

        return [
            'video_id'   => $videoId,
            'title'      => $title,
            'transcript'  => $transcript,
            'language'   => $track['languageCode'] ?? 'pt',
            'segments'   => $segments,
            'word_count' => str_word_count($transcript),
        ];
    }

    private function extractVideoId(string $url): ?string
    {
        // youtube.com/watch?v=xxx
        if (preg_match('/[?&]v=([a-zA-Z0-9_-]{11})/', $url, $m)) return $m[1];
        // youtu.be/xxx
        if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]{11})/', $url, $m)) return $m[1];
        // youtube.com/embed/xxx
        if (preg_match('/embed\/([a-zA-Z0-9_-]{11})/', $url, $m)) return $m[1];
        // ID direto
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $url)) return $url;
        return null;
    }

    private function extractCaptionTracks(string $html): array
    {
        // Busca "captionTracks" no JSON embutido
        if (!preg_match('/"captionTracks"\s*:\s*(\[.*?\])/', $html, $m)) return [];
        $json = $m[1];
        // Fix: aspas escapadas
        $json = str_replace('\u0026', '&', $json);
        $tracks = json_decode($json, true);
        return is_array($tracks) ? $tracks : [];
    }

    private function selectBestTrack(array $tracks): ?array
    {
        $priority = ['pt-BR', 'pt', 'en'];
        // Primeiro: manuais na ordem de prioridade
        foreach ($priority as $lang) {
            foreach ($tracks as $t) {
                $code = $t['languageCode'] ?? '';
                $kind = $t['kind'] ?? '';
                if ($code === $lang && $kind !== 'asr') return $t;
            }
        }
        // Segundo: automáticas na ordem
        foreach ($priority as $lang) {
            foreach ($tracks as $t) {
                $code = $t['languageCode'] ?? '';
                if ($code === $lang) return $t;
            }
        }
        // Qualquer uma
        return $tracks[0] ?? null;
    }

    private function parseXml(string $xml): array
    {
        $segments = [];
        // Regex pra extrair <text start="..." dur="...">conteúdo</text>
        preg_match_all('/<text\s+start="([^"]*)"(?:\s+dur="([^"]*)")?\s*>(.*?)<\/text>/s', $xml, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $text = html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8');
            $text = trim($text);
            if ($text === '') continue;
            $segments[] = [
                'start' => (float)$m[1],
                'dur'   => (float)($m[2] ?? 0),
                'text'  => $text,
            ];
        }
        return $segments;
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept-Language: pt-BR,pt;q=0.9,en;q=0.8'],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($resp !== false && $code < 400) ? $resp : null;
    }
}
