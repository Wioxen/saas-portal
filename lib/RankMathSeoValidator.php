<?php
declare(strict_types=1);

/**
 * RankMathSeoValidator — replica os 12 checks principais do plugin RankMath
 * que aparecem no painel "SEO Básico / Adicional / Legibilidade do título".
 *
 * Uso:
 *   $r = RankMathSeoValidator::validar($html, [
 *       'titulo'        => 'Isenção Enem 2026: prazo termina dia 30',
 *       'meta_title'    => '...',
 *       'meta_desc'     => '...',
 *       'slug'          => 'isencao-enem-2026',
 *       'focus_keyword' => 'isenção Enem 2026',
 *       'featured_alt'  => '...',
 *   ]);
 *   if ($r['score'] < 80) { ... }
 *
 * Retorna {score (0-100), passes, fails, warnings}.
 */
class RankMathSeoValidator
{
    public static function validar(string $html, array $opts): array
    {
        $titulo  = trim((string)($opts['titulo'] ?? ''));
        $metaT   = trim((string)($opts['meta_title'] ?? $titulo));
        $metaD   = trim((string)($opts['meta_desc'] ?? ''));
        $slug    = trim((string)($opts['slug'] ?? ''));
        $kw      = mb_strtolower(trim((string)($opts['focus_keyword'] ?? '')), 'UTF-8');
        $alt     = mb_strtolower(trim((string)($opts['featured_alt'] ?? '')), 'UTF-8');

        $bodyText = mb_strtolower(strip_tags($html), 'UTF-8');
        $totalPalavras = max(1, str_word_count(strip_tags($html)));
        $kwOcorrencias = $kw !== '' ? mb_substr_count($bodyText, $kw) : 0;
        $densidade = $kw !== '' ? round(($kwOcorrencias / $totalPalavras) * 100, 2) : 0;

        $checks = [];

        // === SEO BÁSICO (5 checks) ===
        $checks[] = self::check('Focus keyword no título SEO', mb_stripos($metaT, $kw) !== false, $kw === '' ? 'sem keyword' : null);
        $checks[] = self::check('Focus keyword na meta description', mb_stripos($metaD, $kw) !== false, $kw === '' ? 'sem keyword' : null);
        $checks[] = self::check('Focus keyword no URL/slug', mb_stripos($slug, self::slugify($kw)) !== false, $slug === '' ? 'sem slug' : null);
        $checks[] = self::check('Focus keyword no início do conteúdo (primeiras 100 palavras)',
            $kw !== '' && mb_stripos(self::primeirasPalavras($bodyText, 100), $kw) !== false);
        $checks[] = self::check('Focus keyword aparece no conteúdo', $kwOcorrencias > 0);

        // === ADICIONAL (4 checks) ===
        $temKwH = false;
        if (preg_match_all('#<h[2-4][^>]*>(.*?)</h[2-4]>#is', $html, $hs)) {
            foreach ($hs[1] as $h) if (mb_stripos(strip_tags($h), $kw) !== false) { $temKwH = true; break; }
        }
        $checks[] = self::check('Focus keyword em subtítulo (H2/H3/H4)', $temKwH);
        $checks[] = self::check('Imagem com keyword no alt text', $kw !== '' && mb_stripos($alt, $kw) !== false);
        $idealMin = 0.5; $idealMax = 2.5;
        $checks[] = self::check(
            "Densidade da keyword adequada (0.5%-2.5%, atual: {$densidade}%)",
            $densidade >= $idealMin && $densidade <= $idealMax,
            $densidade < $idealMin ? "muito baixa ({$kwOcorrencias} ocorr.)" : "muito alta (keyword stuffing)"
        );
        $checks[] = self::check("URL ≤ 75 caracteres (atual: " . strlen($slug) . ")", strlen($slug) > 0 && strlen($slug) <= 75);

        // === LEGIBILIDADE DO TÍTULO (2 checks) ===
        $kwInicio = $kw !== '' && mb_stripos(mb_substr($metaT, 0, mb_strlen($kw) + 5), $kw) !== false;
        $checks[] = self::check('Focus keyword no INÍCIO do título', $kwInicio);
        $checks[] = self::check('Título contém número', preg_match('/\d/', $metaT) === 1);

        // Score
        $totalChecks = count($checks);
        $passes = count(array_filter($checks, fn($c) => $c['ok']));
        $score = $totalChecks > 0 ? (int)round(($passes / $totalChecks) * 100) : 0;

        $fails = array_values(array_filter($checks, fn($c) => !$c['ok']));

        return [
            'score'     => $score,
            'passes'    => $passes,
            'total'     => $totalChecks,
            'densidade' => $densidade,
            'kw_ocorrencias' => $kwOcorrencias,
            'fails'     => $fails,
            'all_checks'=> $checks,
        ];
    }

    private static function check(string $titulo, bool $ok, ?string $detalhe = null): array
    {
        return ['titulo' => $titulo, 'ok' => $ok, 'detalhe' => $detalhe];
    }

    private static function primeirasPalavras(string $texto, int $n): string
    {
        $palavras = preg_split('/\s+/u', $texto) ?: [];
        return implode(' ', array_slice($palavras, 0, $n));
    }

    /** Gera slug pra comparar (lowercase, sem acento, hífen). */
    private static function slugify(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = transliterator_transliterate('Any-Latin; Latin-ASCII', $s) ?: $s;
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    /** Helper: deriva focus_keyword do título (2-4 palavras-chave principais sem stop words). */
    public static function derivarKeywordDoTitulo(string $titulo): string
    {
        $stopWords = ['a','o','e','de','do','da','dos','das','para','por','com','sem','em','no','na','nos','nas','um','uma','que','se','ou','mas','já','é','foi','será','ao','aos'];
        $palavras = preg_split('/\s+/u', mb_strtolower(strip_tags($titulo), 'UTF-8')) ?: [];
        $palavras = array_filter($palavras, fn($p) => mb_strlen($p) >= 3 && !in_array($p, $stopWords, true));
        // Pega as primeiras 2-4 palavras (mais relevantes vêm primeiro)
        return implode(' ', array_slice($palavras, 0, 4));
    }
}
