<?php
/**
 * DiscoverReadingScore — Flesch Reading Ease adaptado pra português brasileiro.
 *
 * Discover/Search prefere texto fluido. Flesch >70 = fácil; 50-70 = médio; <40 = difícil.
 * Para português BR, ajustes (Camargo & Souza 1998):
 *   Score = 248.835 − 1.015 × (palavras/sentença) − 84.6 × (sílabas/palavra)
 *
 * Heurística de sílabas em português (sem dependência externa):
 *   - Conta vogais consecutivas como UMA sílaba (ditongo)
 *   - Vogal isolada após consoante = nova sílaba
 *   - Hífens em palavras compostas: cada componente conta separado
 *
 * Não pretende ser linguisticamente perfeito — pretende ser CONSISTENTE.
 *
 * Uso:
 *   $r = DiscoverReadingScore::calcular($html);
 *   if ($r['nivel'] === 'dificil') reprovar();
 *
 * Output:
 *   - score (float, ~0-100, mais alto = mais fácil)
 *   - nivel: 'muito_facil' | 'facil' | 'medio' | 'dificil' | 'muito_dificil'
 *   - palavras_total, sentencas_total, silabas_media
 */
class DiscoverReadingScore
{
    public const SCORE_MUITO_FACIL = 80;
    public const SCORE_FACIL       = 65;
    public const SCORE_MEDIO       = 50;
    public const SCORE_DIFICIL     = 35;

    public static function calcular(string $html): array
    {
        $texto = self::extrairTextoLimpo($html);
        if (mb_strlen($texto) < 50) {
            return ['score' => 0, 'nivel' => 'insuficiente', 'palavras_total' => 0];
        }

        $sentencas = self::contarSentencas($texto);
        $palavras = self::extrairPalavras($texto);
        $totalPalavras = count($palavras);
        if ($totalPalavras === 0 || $sentencas === 0) {
            return ['score' => 0, 'nivel' => 'insuficiente', 'palavras_total' => $totalPalavras];
        }

        $totalSilabas = 0;
        foreach ($palavras as $p) $totalSilabas += self::contarSilabas($p);

        $palavrasPorSentenca = $totalPalavras / $sentencas;
        $silabasPorPalavra = $totalSilabas / $totalPalavras;

        // Flesch português BR (Camargo & Souza)
        $score = 248.835 - (1.015 * $palavrasPorSentenca) - (84.6 * $silabasPorPalavra);
        $score = max(0, min(100, $score));

        $nivel = self::nivelDoScore($score);

        return [
            'score'                 => round($score, 1),
            'nivel'                 => $nivel,
            'palavras_total'        => $totalPalavras,
            'sentencas_total'       => $sentencas,
            'silabas_total'         => $totalSilabas,
            'palavras_por_sentenca' => round($palavrasPorSentenca, 1),
            'silabas_por_palavra'   => round($silabasPorPalavra, 2),
        ];
    }

    public static function nivelDoScore(float $score): string
    {
        if ($score >= self::SCORE_MUITO_FACIL) return 'muito_facil';
        if ($score >= self::SCORE_FACIL)       return 'facil';
        if ($score >= self::SCORE_MEDIO)       return 'medio';
        if ($score >= self::SCORE_DIFICIL)     return 'dificil';
        return 'muito_dificil';
    }

    /**
     * Heurística de sílabas em português:
     * - Cada GRUPO de vogais consecutivas é 1 sílaba (cobre ditongos: pau, lei, raio)
     * - Vogal: a, e, i, o, u (com acentos), e y
     * - Hífens: split em palavras separadas (guarda-chuva → guarda + chuva)
     */
    public static function contarSilabas(string $palavra): int
    {
        $palavra = mb_strtolower(trim($palavra), 'UTF-8');
        if ($palavra === '') return 0;

        // Hífen → soma sílabas dos componentes
        if (strpos($palavra, '-') !== false) {
            $partes = explode('-', $palavra);
            $total = 0;
            foreach ($partes as $p) $total += self::contarSilabas($p);
            return max(1, $total);
        }

        // Remove caracteres não-letras (apóstrofos, pontuação interna)
        $palavra = preg_replace('/[^a-záéíóúâêôãõàü]/u', '', $palavra) ?? '';
        if ($palavra === '') return 0;

        // Conta grupos vogais
        $vogais = 'aáàâãeéêiíoóôõuúü';
        $silabas = 0;
        $emVogal = false;
        $len = mb_strlen($palavra, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $c = mb_substr($palavra, $i, 1, 'UTF-8');
            $ehVogal = mb_strpos($vogais, $c) !== false;
            if ($ehVogal && !$emVogal) {
                $silabas++;
                $emVogal = true;
            } elseif (!$ehVogal) {
                $emVogal = false;
            }
        }
        return max(1, $silabas);
    }

    /**
     * Conta sentenças por terminadores . ! ? (sem confundir com abreviações).
     */
    public static function contarSentencas(string $texto): int
    {
        // Remove abreviações comuns que confundem o split
        $abrev = ['Sr.', 'Sra.', 'Dr.', 'Dra.', 'Prof.', 'etc.', 'Ex.', 'p.ex.', 'i.e.', 'e.g.'];
        foreach ($abrev as $a) {
            $texto = str_replace($a, str_replace('.', '', $a), $texto);
        }
        $count = preg_match_all('/[.!?]+(?=\s|$)/u', $texto);
        return max(1, (int)$count);
    }

    /**
     * Extrai palavras (sequência de letras, ignorando números/pontuação).
     */
    public static function extrairPalavras(string $texto): array
    {
        if (preg_match_all('/\p{L}+/u', $texto, $m)) {
            return $m[0];
        }
        return [];
    }

    /**
     * Texto puro do HTML — remove scripts/styles/JSON-LD, decodifica entities.
     */
    public static function extrairTextoLimpo(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html);
        $texto = strip_tags($html);
        $texto = html_entity_decode($texto, ENT_QUOTES, 'UTF-8');
        $texto = preg_replace('/\s+/u', ' ', $texto);
        return trim((string)$texto);
    }
}
