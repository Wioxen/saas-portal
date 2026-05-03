<?php
declare(strict_types=1);

require_once __DIR__ . '/Serper.php';

/**
 * SportsHighlightsExtractor — busca vídeo de "melhores momentos" / "gols" do
 * jogo via Serper (preferindo canais oficiais YouTube: Premiere, Globo Esporte,
 * Brasileirão TV, GE) e devolve embed pronto pra inserir no pós-jogo.
 *
 * Uso:
 *   $hl = SportsHighlightsExtractor::buscar('Vitória', 4, 'Coritiba', 1, 'Brasileirão Série A');
 *   if ($hl) echo $hl['embed_html'];   // iframe YouTube
 */
class SportsHighlightsExtractor
{
    /** Domínios prioritários (canais oficiais). */
    private const PRIORIDADE = [
        'youtube.com/watch'   => 10,
        'youtu.be/'           => 10,
        'globoplay.globo.com' => 8,
        'ge.globo.com'        => 7,
        'sportv.globo.com'    => 7,
    ];

    /** Canais YouTube oficiais — ranking extra. */
    private const CANAIS_OFICIAIS_BR = [
        'premiere',
        'cbf tv', 'cbftv',
        'globoesporte', 'ge.globo',
        'brasileirao', 'brasileirão',
        'sportv',
        'esporte interativo',
    ];

    /**
     * Busca vídeo de melhores momentos via Serper.
     *
     * @return array|null {url, video_id, embed_html, fonte, titulo}
     */
    public static function buscar(string $timeMandante, int $placarMand, string $timeVisitante, int $placarVis, string $competicao = '', ?Serper $serper = null, string $apiKey = ''): ?array
    {
        if ($serper === null) {
            if ($apiKey === '') return null;
            $serper = new Serper($apiKey);
        }

        $queries = [
            "{$timeMandante} {$placarMand} x {$placarVis} {$timeVisitante} melhores momentos",
            "{$timeMandante} x {$timeVisitante} gols {$competicao}",
            "melhores momentos {$timeMandante} {$timeVisitante}",
        ];

        $candidatos = [];
        foreach ($queries as $q) {
            try {
                $resp = $serper->search($q, 10);
                $organic = $resp['organic'] ?? [];
                $videos  = $resp['videos']  ?? [];
                $items = array_merge($videos, $organic);
                foreach ($items as $r) {
                    $url = (string)($r['link'] ?? $r['url'] ?? '');
                    if ($url === '') continue;
                    $titulo = (string)($r['title'] ?? '');
                    $score = self::pontuar($url, $titulo);
                    if ($score > 0) {
                        $candidatos[] = ['url' => $url, 'titulo' => $titulo, 'score' => $score];
                    }
                }
            } catch (Throwable $e) { /* skip query */ }
            if (count($candidatos) >= 5) break;
        }

        if (empty($candidatos)) return null;

        usort($candidatos, fn($a, $b) => $b['score'] <=> $a['score']);
        $top = $candidatos[0];

        $videoId = self::extrairVideoId($top['url']);
        $embed = '';
        if ($videoId !== null) {
            $embed = "<div class='video-highlights' style='position:relative;padding-bottom:56.25%;height:0;overflow:hidden;margin:20px 0;'>"
                   . "<iframe src='https://www.youtube.com/embed/{$videoId}' "
                   . "style='position:absolute;top:0;left:0;width:100%;height:100%;border:0;' "
                   . "frameborder='0' allow='accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture' "
                   . "allowfullscreen title='" . htmlspecialchars($top['titulo'], ENT_QUOTES, 'UTF-8') . "'></iframe>"
                   . "</div>";
        }

        return [
            'url'        => $top['url'],
            'video_id'   => $videoId,
            'embed_html' => $embed,
            'fonte'      => self::dominio($top['url']),
            'titulo'     => $top['titulo'],
            'score'      => $top['score'],
        ];
    }

    /** Pontua URL/título por relevância. 0 = inválido. */
    private static function pontuar(string $url, string $titulo): int
    {
        $score = 0;
        $urlLow = strtolower($url);

        foreach (self::PRIORIDADE as $padrao => $pts) {
            if (str_contains($urlLow, $padrao)) { $score += $pts; break; }
        }
        if ($score === 0) return 0;

        $titLow = mb_strtolower($titulo, 'UTF-8');
        foreach (self::CANAIS_OFICIAIS_BR as $canal) {
            if (str_contains($titLow, $canal)) { $score += 5; break; }
        }
        if (str_contains($titLow, 'melhores momentos')) $score += 3;
        if (str_contains($titLow, 'gols')) $score += 2;
        // Penaliza títulos com "shorts" (cortes curtos, não highlights completos)
        if (str_contains($urlLow, '/shorts/')) $score -= 8;
        return max(0, $score);
    }

    /** Extrai video_id do YouTube de várias formas de URL. */
    private static function extrairVideoId(string $url): ?string
    {
        $patterns = [
            '#youtube\.com/watch\?.*?v=([A-Za-z0-9_-]{11})#',
            '#youtu\.be/([A-Za-z0-9_-]{11})#',
            '#youtube\.com/embed/([A-Za-z0-9_-]{11})#',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $url, $m)) return $m[1];
        }
        return null;
    }

    private static function dominio(string $url): string
    {
        $h = parse_url($url, PHP_URL_HOST) ?: '';
        return preg_replace('#^www\.#', '', $h) ?? $h;
    }
}
