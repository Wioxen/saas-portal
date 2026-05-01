<?php
/**
 * DiscoverP1Variantes — gera 2 variantes do PRIMEIRO PARÁGRAFO.
 *
 * Por que: P1 = preview do Discover. Quando o título empata vs concorrente, o que
 * decide o clique é o snippet (que vem do P1 ou meta description). Hoje só Title
 * Swap troca o título quando CTR cai. Loop incompleto — preview também precisa
 * ser otimizado.
 *
 * Estratégia idêntica ao DiscoverTitleVariantes:
 *   - 2 variantes do P1 com ângulos diferentes do original
 *   - Mantém regras CLAUDE.md: fato + tempo + entidade + acelerador, sem clickbait
 *   - Validação: 200-450 chars, sem clickbait, similaridade < 90%
 *   - Fail-open: LLM falha → retorna [] (sem swap futuro)
 *
 * Uso (em DiscoverGerador junto com Title Variantes):
 *   $p1Vars = DiscoverP1Variantes::gerar($p1Original, $titulo, $termo, $briefing, $claude);
 *   $extras['p1_variantes'] = $p1Vars;
 */

class DiscoverP1Variantes
{
    public const QTD_VARIANTES = 2;
    public const MIN_CHARS = 200;
    public const MAX_CHARS = 450;

    /**
     * @param string $p1Original 1º parágrafo já publicado (texto puro, sem HTML)
     * @param string $titulo título do post (contexto pro LLM)
     * @param string $termo termo do trend
     * @param string $briefingResumo resumo da fonte (até 500 chars)
     * @param object $llm Claude/OpenAI compat
     * @return array<string> 0-2 variantes válidas (texto puro)
     */
    public static function gerar(string $p1Original, string $titulo, string $termo, string $briefingResumo, $llm): array
    {
        $p1Original = trim($p1Original);
        $titulo = trim($titulo);
        if ($p1Original === '' || $termo === '' || !is_object($llm)) return [];
        if (mb_strlen($p1Original, 'UTF-8') < 100) return [];

        $briefingResumo = mb_substr(trim($briefingResumo), 0, 500, 'UTF-8');
        $prompt = self::montarPrompt($p1Original, $titulo, $termo, $briefingResumo);

        try {
            $resposta = self::chamarLlm($llm, $prompt);
        } catch (Throwable $e) {
            return [];
        }
        if (!is_string($resposta) || trim($resposta) === '') return [];

        $variantes = self::parsearResposta($resposta);
        return self::validar($variantes, $p1Original);
    }

    private static function montarPrompt(string $p1, string $titulo, string $termo, string $briefing): string
    {
        $n = self::QTD_VARIANTES;
        $min = self::MIN_CHARS;
        $max = self::MAX_CHARS;
        $bf = $briefing !== '' ? "\n\nBRIEFING (resumo das fontes):\n{$briefing}\n" : '';

        return <<<TXT
Você é editor do Google Discover. Gere {$n} VARIANTES do PRIMEIRO PARÁGRAFO pra A/B sequencial.

TÍTULO DO POST: {$titulo}
TERMO/TEMA: {$termo}
P1 ATUAL (publicado):
{$p1}{$bf}

REGRAS RÍGIDAS:
1. Cada P1 DEVE conter: FATO + NÚMERO + ENTIDADE + MARCADOR TEMPORAL + GANCHO (perda/oportunidade/dinheiro).
2. Cada variante usa ÂNGULO DIFERENTE do P1 atual (curiosidade, urgência, dor, oportunidade).
3. Entre {$min} e {$max} caracteres por variante.
4. ZERO clickbait sem lastro. Tudo prometido DEVE estar no briefing/fonte.
5. ZERO emojis. ZERO travessão (—).
6. NÃO repita o verbo principal do P1 atual.
7. 2-4 frases. Última frase abre LOOP (não revela a resposta).

FORMATO DE SAÍDA — APENAS as {$n} variantes, separadas por linha em branco:
[1] <variante 1 do P1>

[2] <variante 2 do P1>
TXT;
    }

    private static function chamarLlm($llm, string $prompt): ?string
    {
        if (method_exists($llm, 'ask')) {
            $r = $llm->ask($prompt, ['max_tokens' => 800, 'temperature' => 0.7]);
            if (is_string($r)) return $r;
            if (is_array($r) && isset($r['text'])) return (string)$r['text'];
            if (is_array($r) && isset($r['content'])) return (string)$r['content'];
        }
        if (method_exists($llm, 'chat')) {
            $r = $llm->chat([['role' => 'user', 'content' => $prompt]], ['max_tokens' => 800]);
            if (is_string($r)) return $r;
            if (is_array($r) && isset($r['content'])) return (string)$r['content'];
        }
        return null;
    }

    /** Extrai variantes [1] / [2] / "1." / "1)" da resposta. */
    private static function parsearResposta(string $resposta): array
    {
        // Tenta primeiro split por marcadores [1], [2]
        if (preg_match_all('/\[\d+\]\s*([\s\S]+?)(?=\[\d+\]|$)/u', $resposta, $ms)) {
            $out = [];
            foreach ($ms[1] as $bloco) {
                $bloco = trim($bloco);
                if ($bloco !== '') $out[] = $bloco;
            }
            if (!empty($out)) return $out;
        }
        // Fallback: split por linhas em branco duplas
        $blocos = preg_split('/\n\s*\n/u', trim($resposta)) ?: [];
        $out = [];
        foreach ($blocos as $b) {
            $b = trim($b);
            // Remove prefixos "1.", "1)", "[1]"
            $b = preg_replace('/^(?:\[\d+\]|\d+[\.\)])\s*/u', '', $b) ?? $b;
            if (mb_strlen($b, 'UTF-8') >= 100) $out[] = $b;
        }
        return $out;
    }

    private static function validar(array $variantes, string $original): array
    {
        $origLow = mb_strtolower($original, 'UTF-8');
        $palavrasClickbait = ['incrível', 'imperdível', 'surpreendente', 'revolucionário', 'chocante', 'inacreditável'];

        $out = [];
        foreach ($variantes as $v) {
            $v = trim($v);
            $len = mb_strlen($v, 'UTF-8');
            if ($len < self::MIN_CHARS || $len > self::MAX_CHARS) continue;

            $vLow = mb_strtolower($v, 'UTF-8');
            if ($vLow === $origLow) continue;
            similar_text($vLow, $origLow, $sim);
            if ($sim >= 90) continue;

            $skip = false;
            foreach ($palavrasClickbait as $p) {
                if (mb_strpos($vLow, $p, 0, 'UTF-8') !== false) { $skip = true; break; }
            }
            if ($skip) continue;
            if (mb_strpos($v, '—', 0, 'UTF-8') !== false) continue; // travessão proibido

            $out[] = $v;
            if (count($out) >= self::QTD_VARIANTES) break;
        }
        return $out;
    }
}
