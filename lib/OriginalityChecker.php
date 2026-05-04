<?php
declare(strict_types=1);

/**
 * OriginalityChecker — calcula Jaccard distance entre artigo e fontes.
 *
 * Google detecta "near-duplicate" via shingle (N-gram). Se artigo é só
 * paráfrase da fonte (Jaccard > 0.55 em bigramas), perde autoridade.
 *
 * Threshold default: warn em 0.45, fail em 0.60. Editor humano costuma
 * ficar em 0.20-0.40 (pega fatos mas reescreve estrutura/ângulo).
 *
 * Uso:
 *   $r = OriginalityChecker::analisar($html, $textosFontes);
 *   if ($r['severity'] === 'fail') { ... bloquear publicação ... }
 */
class OriginalityChecker
{
    private const N = 3; // trigrams (3 palavras consecutivas)

    /**
     * @param string $html        Artigo final
     * @param array  $sources     Array de strings (texto bruto de cada fonte)
     * @return array {ok, severity, jaccard_max, fonte_mais_similar, jaccard_por_fonte}
     */
    public static function analisar(string $html, array $sources): array
    {
        $artText = self::normalizar(strip_tags(html_entity_decode($html, ENT_QUOTES|ENT_HTML5, 'UTF-8')));
        $artShingles = self::shingles($artText, self::N);

        if (count($artShingles) < 50) {
            return [
                'ok' => true,
                'severity' => 'ok',
                'motivo' => 'artigo curto demais pra avaliar (< 50 trigrams)',
                'jaccard_max' => 0.0,
                'fonte_mais_similar' => null,
                'jaccard_por_fonte' => [],
            ];
        }

        $jaccards = [];
        $maxJ = 0.0;
        $maxIdx = null;
        foreach ($sources as $i => $src) {
            $srcText = self::normalizar((string)$src);
            $srcShingles = self::shingles($srcText, self::N);
            if (count($srcShingles) < 30) {
                $jaccards[$i] = 0.0;
                continue;
            }
            $j = self::jaccard($artShingles, $srcShingles);
            $jaccards[$i] = $j;
            if ($j > $maxJ) { $maxJ = $j; $maxIdx = $i; }
        }

        $severity = 'ok';
        if ($maxJ >= 0.60) $severity = 'fail';
        elseif ($maxJ >= 0.45) $severity = 'warn';

        return [
            'ok' => $severity === 'ok',
            'severity' => $severity,
            'jaccard_max' => round($maxJ, 3),
            'fonte_mais_similar' => $maxIdx,
            'jaccard_por_fonte' => array_map(fn($v) => round($v, 3), $jaccards),
        ];
    }

    private static function normalizar(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        // remove acentos pra estabilizar match
        $s = strtr($s,
            'áéíóúâêôàãõçÁÉÍÓÚÂÊÔÀÃÕÇ',
            'aeiouaeoaaocAEIOUAEOAAOC'
        );
        $s = preg_replace('/[^\w\s]/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * @return array<string,bool> shingles como chaves (set)
     */
    private static function shingles(string $text, int $n): array
    {
        $words = explode(' ', $text);
        $count = count($words);
        if ($count < $n) return [];
        $set = [];
        for ($i = 0; $i <= $count - $n; $i++) {
            $shingle = implode(' ', array_slice($words, $i, $n));
            $set[$shingle] = true;
        }
        return $set;
    }

    private static function jaccard(array $a, array $b): float
    {
        if (empty($a) || empty($b)) return 0.0;
        $inter = count(array_intersect_key($a, $b));
        $union = count($a) + count($b) - $inter;
        return $union > 0 ? $inter / $union : 0.0;
    }

    public static function reportToLogLine(array $r): string
    {
        $jx = round((float)($r['jaccard_max'] ?? 0), 3);
        return "Originality: severity={$r['severity']} jaccard_max={$jx}";
    }
}
