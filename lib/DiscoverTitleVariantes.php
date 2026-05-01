<?php
/**
 * DiscoverTitleVariantes — gera 2 títulos alternativos pro Title A/B sequencial.
 *
 * Por que: Discover/SEO. Hoje Sonnet escreve UM título e a gente publica. Quando
 * post entra em "opportunity zone" (top 10, CTR < 1%) o gsc_aprender chama Reviewer
 * (caro, reescreve tudo). Aqui pré-geramos 2 variantes de título no momento da
 * publicação inicial pra que TitleSwapper possa fazer swap barato (só title).
 *
 * Estratégia:
 *   - Gera variantes com ÂNGULOS DIFERENTES do título original
 *   - Mantém regras CLAUDE.md: 55-68 chars, sem clickbait, com lastro na fonte
 *   - Cache: NÃO — chamada 1× por trend, na geração inicial
 *   - Fail-open: se LLM falhar, retorna [] (sem variantes = sem swap futuro)
 *
 * Uso (em DiscoverGerador após escolher título principal):
 *   $variantes = DiscoverTitleVariantes::gerar($titulo, $termo, $briefing, $claude);
 *   if (!empty($variantes)) {
 *       $extras['titulo_variantes'] = $variantes;  // persiste no payload
 *   }
 */

class DiscoverTitleVariantes
{
    /** Quantas variantes alternativas gerar (além do principal). */
    public const QTD_VARIANTES = 2;

    /** Limite de chars do título (mesmas regras do título principal). */
    public const MIN_CHARS = 50;
    public const MAX_CHARS = 70;

    /**
     * Gera N variantes alternativas. Retorna lista vazia se LLM falhar.
     *
     * @param string $tituloOriginal título já escolhido pelo Sonnet
     * @param string $termo termo do trend
     * @param string $briefingResumo resumo do briefing/fontes (até 500 chars)
     * @param object $llm instância Claude (com método ->ask) ou OpenAI compat
     * @return array<string> 0-2 variantes válidas
     */
    public static function gerar(string $tituloOriginal, string $termo, string $briefingResumo, $llm): array
    {
        $tituloOriginal = trim($tituloOriginal);
        $termo = trim($termo);
        if ($tituloOriginal === '' || $termo === '' || !is_object($llm)) return [];

        $briefingResumo = mb_substr(trim($briefingResumo), 0, 500, 'UTF-8');

        $prompt = self::montarPrompt($tituloOriginal, $termo, $briefingResumo);

        try {
            $resposta = self::chamarLlm($llm, $prompt);
        } catch (Throwable $e) {
            return [];
        }
        if (!is_string($resposta) || trim($resposta) === '') return [];

        $variantes = self::parsearResposta($resposta);
        return self::validar($variantes, $tituloOriginal);
    }

    private static function montarPrompt(string $titulo, string $termo, string $briefing): string
    {
        $n = self::QTD_VARIANTES;
        $min = self::MIN_CHARS;
        $max = self::MAX_CHARS;
        $bf = $briefing !== '' ? "\n\nBRIEFING (resumo das fontes):\n{$briefing}\n" : '';

        return <<<TXT
Você é editor de tráfego do Google Discover. Gere {$n} TÍTULOS ALTERNATIVOS pra A/B sequencial.

TÍTULO ATUAL (já publicado): {$titulo}
TERMO/TEMA: {$termo}{$bf}

REGRAS RÍGIDAS:
1. Cada variante DEVE ter ÂNGULO DIFERENTE do atual e dos outros (curiosidade, urgência, número, dor, oportunidade — escolha 2 distintos).
2. Entre {$min} e {$max} caracteres. Conta cada char.
3. ZERO clickbait sem lastro. Tudo que prometer DEVE estar no briefing/fonte.
4. ZERO emojis. ZERO travessão (—) ou en-dash (–).
5. Cada variante = uma manchete completa autônoma.
6. NÃO use o mesmo verbo/adjetivo principal do título atual.

FORMATO DE SAÍDA — APENAS as {$n} linhas, nada mais:
1. <variante 1>
2. <variante 2>
TXT;
    }

    /** Chama LLM com a interface mais comum do projeto (Claude->ask ou similar). */
    private static function chamarLlm($llm, string $prompt): ?string
    {
        // Claude (lib/Claude.php) → ->ask($prompt, $opts)
        if (method_exists($llm, 'ask')) {
            $r = $llm->ask($prompt, ['max_tokens' => 400, 'temperature' => 0.7]);
            if (is_string($r)) return $r;
            if (is_array($r) && isset($r['text'])) return (string)$r['text'];
            if (is_array($r) && isset($r['content'])) return (string)$r['content'];
        }
        // OpenAI compat → ->chat($messages)
        if (method_exists($llm, 'chat')) {
            $r = $llm->chat([['role' => 'user', 'content' => $prompt]], ['max_tokens' => 400]);
            if (is_string($r)) return $r;
            if (is_array($r) && isset($r['content'])) return (string)$r['content'];
        }
        return null;
    }

    /** Extrai linhas numeradas "1. ..." / "2. ..." da resposta. */
    private static function parsearResposta(string $resposta): array
    {
        $linhas = preg_split('/\r?\n/', trim($resposta)) ?: [];
        $out = [];
        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '') continue;
            // Aceita "1.", "1)", "- ", "• ", "* "
            if (preg_match('/^(?:\d+[\.\)]\s*|[-•*]\s*)(.+)$/u', $linha, $m)) {
                $candidato = trim($m[1]);
            } else {
                // Linha "limpa" sem prefixo — aceita se parecer título (não muito longa)
                $candidato = $linha;
            }
            // Remove aspas envolvendo
            $candidato = trim($candidato, "\"'“”‘’ ");
            if ($candidato !== '') $out[] = $candidato;
        }
        return $out;
    }

    /** Filtra variantes inválidas: mesma do original, fora dos limites de chars, com clickbait. */
    private static function validar(array $variantes, string $original): array
    {
        $origLow = mb_strtolower($original, 'UTF-8');
        $proibidos = ['—', '–', '😀', '😱', '🔥']; // chars proibidos (regra CLAUDE.md)
        $palavrasClickbait = ['incrível', 'imperdível', 'surpreendente', 'revolucionário', 'chocante', 'inacreditável'];

        $out = [];
        foreach ($variantes as $v) {
            $v = trim($v);
            $len = mb_strlen($v, 'UTF-8');
            if ($len < self::MIN_CHARS || $len > self::MAX_CHARS) continue;

            $vLow = mb_strtolower($v, 'UTF-8');
            if ($vLow === $origLow) continue;
            similar_text($vLow, $origLow, $sim);
            if ($sim >= 90) continue; // quase idêntico — não vale A/B

            $skip = false;
            foreach ($proibidos as $p) {
                if (mb_strpos($v, $p, 0, 'UTF-8') !== false) { $skip = true; break; }
            }
            if ($skip) continue;
            foreach ($palavrasClickbait as $p) {
                if (mb_strpos($vLow, $p, 0, 'UTF-8') !== false) { $skip = true; break; }
            }
            if ($skip) continue;

            $out[] = $v;
            if (count($out) >= self::QTD_VARIANTES) break;
        }
        return $out;
    }
}
