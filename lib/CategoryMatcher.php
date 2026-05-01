<?php
/**
 * CategoryMatcher — fuzzy matching inteligente de categorias antes de criar novas no WP.
 *
 * Sem ele: `wp.resolverCategorias(["Cursos de Beleza"])` cria categoria nova mesmo se já
 * existir "Beleza", "Belezas", "Cursos Beleza" no WP — fragmenta taxonomia.
 *
 * Com ele: carrega TODAS as categorias do site uma vez, calcula score de similaridade em 5
 * níveis e REUSA a melhor existente (≥ threshold). Só cria nova se nenhuma bate.
 *
 * Níveis de score:
 *   1. Match exato após normalizar acento/case → 100
 *   2. Mesmo bag-of-words sem stopwords (ordem diferente) → 95
 *   3. Substring (uma contém a outra) → 85
 *   4. Jaccard de tokens (palavras em comum) ≥ 0.7 → 70-95
 *   5. similar_text PHP + bônus por keyword forte do nicho → calculado
 *
 * Threshold default 70 — abaixo disso, cria nova via fluxo existente.
 *
 * Custo: ZERO API LLM. Só WP REST (cacheado por request) + CPU local.
 *
 * Uso:
 *   $matcher = new CategoryMatcher($wp, 70.0);
 *   $ids = $matcher->resolverComMatch(['Cursos de Beleza', 'Tecnologia']);
 *   // matcher->log mostra: "'Cursos de Beleza' → reusou 'Beleza' (87%)", "'Tecnologia' → CRIADA NOVA"
 */
require_once __DIR__ . '/Wordpress.php';

class CategoryMatcher
{
    private Wordpress $wp;
    private float $threshold;
    public array $log = [];

    /** Stopwords PT-BR ignoradas em bag-of-words match. */
    private const STOPWORDS = [
        'de', 'da', 'do', 'das', 'dos', 'para', 'pra', 'e', 'em', 'no', 'na', 'nos', 'nas',
        'a', 'o', 'as', 'os', 'um', 'uma', 'uns', 'umas', 'com', 'sem', 'por', 'sobre',
        'que', 'qual', 'quais', 'mais', 'menos', 'muito', 'pouco',
    ];

    public function __construct(Wordpress $wp, float $threshold = 70.0)
    {
        $this->wp = $wp;
        $this->threshold = $threshold;
    }

    /**
     * Resolve nomes propostos → IDs, preferindo categorias existentes via fuzzy match.
     * Cria nova SÓ se nenhuma existente bate o threshold.
     */
    public function resolverComMatch(array $nomesPropostos): array
    {
        $existentes = $this->wp->listarTodasCategorias();
        $resultado = [];

        foreach ($nomesPropostos as $nome) {
            $nome = trim((string)$nome);
            if ($nome === '') continue;

            $melhor = $this->encontrarMelhorMatch($nome, $existentes);
            if ($melhor !== null) {
                $resultado[] = (int)$melhor['id'];
                $this->log[] = "'{$nome}' → reusou '{$melhor['name']}' (#{$melhor['id']}, {$melhor['score']}%)";
                continue;
            }

            // Sem match acima do threshold → cria nova via fluxo existente
            try {
                $novosIds = $this->wp->resolverCategorias([$nome]);
                if (!empty($novosIds)) {
                    $idNovo = (int)$novosIds[0];
                    $resultado[] = $idNovo;
                    $this->log[] = "'{$nome}' → CRIADA NOVA #{$idNovo}";
                    // Adiciona ao cache local pra evitar duplicar no mesmo batch (ex: "Beleza" e "Belezas" no mesmo array)
                    $existentes[] = [
                        'id'     => $idNovo,
                        'name'   => $nome,
                        'slug'   => '',
                        'parent' => 0,
                        'count'  => 0,
                    ];
                }
            } catch (Throwable $e) {
                $this->log[] = "'{$nome}' → FALHOU ao criar: " . $e->getMessage();
            }
        }

        return array_values(array_unique($resultado));
    }

    /**
     * Calcula score de match entre $nome e cada categoria existente.
     * Retorna a melhor candidata (com 'score') OU null se nenhuma ≥ threshold.
     */
    private function encontrarMelhorMatch(string $nome, array $existentes): ?array
    {
        $nomeNorm   = $this->normalizar($nome);
        $nomeTokens = $this->tokenize($nomeNorm);

        if (empty($nomeNorm)) return null;

        $melhor = null;
        $melhorScore = 0.0;

        foreach ($existentes as $cat) {
            $catName = (string)($cat['name'] ?? '');
            if ($catName === '') continue;
            $catNorm = $this->normalizar($catName);
            $catTokens = $this->tokenize($catNorm);

            $score = $this->calcularScore($nomeNorm, $catNorm, $nomeTokens, $catTokens);

            if ($score > $melhorScore) {
                $melhorScore = $score;
                $melhor = array_merge($cat, ['score' => round($score, 1)]);
            }
        }

        if ($melhor === null || $melhorScore < $this->threshold) return null;
        return $melhor;
    }

    /**
     * Score 0-100 entre 2 nomes normalizados.
     * Combina: match exato + bag-of-words + substring + Jaccard + similar_text.
     */
    private function calcularScore(string $a, string $b, array $tokensA, array $tokensB): float
    {
        // 1. Exato após normalização
        if ($a === $b) return 100.0;

        // 2. Mesmo bag-of-words sem stopwords (ordem diferente)
        $sortedA = $this->sortedTokens($tokensA);
        $sortedB = $this->sortedTokens($tokensB);
        if (!empty($sortedA) && $sortedA === $sortedB) return 95.0;

        // 3. Substring (uma contém a outra) — só se ambas têm ≥ 4 chars
        if (mb_strlen($a) >= 4 && mb_strlen($b) >= 4) {
            if (mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false) {
                // Diferença de tamanho gera score 70-90 (palavras muito diferentes em tamanho = menor confiança)
                $diff = abs(mb_strlen($a) - mb_strlen($b));
                $base = max(mb_strlen($a), mb_strlen($b));
                $score = 90.0 - min(20.0, ($diff / $base) * 50.0);
                if ($score > 70.0) return $score;
            }
        }

        // 4. Jaccard de tokens (sem stopwords)
        $sigA = $this->tokensSemStopwords($tokensA);
        $sigB = $this->tokensSemStopwords($tokensB);
        if (!empty($sigA) && !empty($sigB)) {
            $inter = count(array_intersect($sigA, $sigB));
            $uniao = count(array_unique(array_merge($sigA, $sigB)));
            if ($uniao > 0) {
                $jaccard = $inter / $uniao;
                if ($jaccard >= 0.5) {
                    return 60.0 + ($jaccard * 40.0); // Jaccard 0.5 → 80, 1.0 → 100
                }
            }
        }

        // 5. similar_text PHP (% chars comuns, ordem-aware)
        similar_text($a, $b, $percent);
        return $percent;
    }

    /** Lowercase + sem acento + sem ç + tira pontuação extra. */
    private function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $map = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c','ñ'=>'n',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/[^a-z0-9\s]/', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    private function tokenize(string $normalizado): array
    {
        if ($normalizado === '') return [];
        return preg_split('/\s+/', $normalizado, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function tokensSemStopwords(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            if (mb_strlen($t) < 2) continue;
            if (in_array($t, self::STOPWORDS, true)) continue;
            // Plural simples PT-BR: "belezas" → "beleza" (só pra match — não muda dado)
            if (mb_strlen($t) > 3 && mb_substr($t, -1) === 's') {
                $t = mb_substr($t, 0, -1);
            }
            $out[] = $t;
        }
        return $out;
    }

    private function sortedTokens(array $tokens): array
    {
        $sig = $this->tokensSemStopwords($tokens);
        sort($sig);
        return $sig;
    }
}
