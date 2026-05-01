<?php
/**
 * DiscoverMetaTags — gera og:title + meta_description (+ 2 variantes pra A/B).
 *
 * Por que SEPARADOS do title:
 *   - title: regra Google SERP (55-68 chars, factual)
 *   - og:title: regra Discover/social (até 90 chars, mais punchy/emocional)
 *   - meta_description: snippet do SERP (até 155 chars, com CTA)
 *
 * Hoje WP usa o mesmo title pra tudo. Isso desperdiça ângulos diferentes pra
 * audiences diferentes (SERP factual vs Discover emocional).
 *
 * Variantes de meta_description (2 alt) → MetaSwapper pode rotacionar via GSC.
 *
 * Push pro WP: aplicarNoWp() seta tanto fields Yoast quanto RankMath (plugins
 * concorrentes pra SEO no WP). Sites diferentes podem ter qualquer um.
 *
 * Fail-open: LLM falhou ou parse errado → retorna [] (sem meta tags geradas =
 * WP usa default).
 *
 * Uso (em DiscoverGerador, junto com Title/P1 Variantes):
 *   $tags = DiscoverMetaTags::gerar($titulo, $p1, $termo, $briefing, $claude);
 *   if (!empty($tags['og_title'])) {
 *       DiscoverMetaTags::aplicarNoWp($wp, $postId, $tags);
 *       $extras['meta_tags'] = $tags;
 *   }
 */

class DiscoverMetaTags
{
    public const OG_TITLE_MAX     = 90;
    public const META_DESC_MAX    = 155;
    public const META_DESC_MIN    = 110;
    public const QTD_VARIANTES    = 2;

    /**
     * @return array {og_title, meta_description, meta_description_variantes[]} ou []
     */
    public static function gerar(string $titulo, string $p1, string $termo, string $briefingResumo, $llm): array
    {
        $titulo = trim($titulo);
        $termo = trim($termo);
        if ($titulo === '' || $termo === '' || !is_object($llm)) return [];

        $p1 = trim($p1);
        $briefingResumo = mb_substr(trim($briefingResumo), 0, 400, 'UTF-8');
        $prompt = self::montarPrompt($titulo, $p1, $termo, $briefingResumo);

        try {
            $resposta = self::chamarLlm($llm, $prompt);
        } catch (Throwable $e) {
            return [];
        }
        if (!is_string($resposta) || trim($resposta) === '') return [];

        return self::parsearResposta($resposta);
    }

    private static function montarPrompt(string $titulo, string $p1, string $termo, string $briefing): string
    {
        $ogMax  = self::OG_TITLE_MAX;
        $mdMin  = self::META_DESC_MIN;
        $mdMax  = self::META_DESC_MAX;
        $p1Cut  = $p1 !== '' ? "\nP1 (1º parágrafo): " . mb_substr($p1, 0, 250, 'UTF-8') : '';
        $bf     = $briefing !== '' ? "\nBRIEFING: {$briefing}" : '';

        return <<<TXT
Você é editor de Google Discover + SEO. Para o post abaixo, gere tags otimizadas pra preview/snippet.

TÍTULO (já escolhido — NÃO altere): {$titulo}
TERMO/TEMA: {$termo}{$p1Cut}{$bf}

GERE 4 ITENS:

1. OG_TITLE — usado no preview do Discover/Facebook/Twitter. Pode (e DEVE) ser MAIS PUNCHY que o título do Google. Limite: {$ogMax} chars. Pode usar pergunta retórica, número impactante, contraste. ZERO clickbait sem lastro.

2. META_DESCRIPTION_PRINCIPAL — snippet do SERP. {$mdMin}-{$mdMax} chars. Deve conter: o termo, 1 fato específico (número/prazo), CTA implícito ('saiba como', 'veja o passo a passo', 'confira a lista'). Sem ponto final no fim.

3. META_DESCRIPTION_VARIANTE_B — ângulo DIFERENTE da principal (se principal foca em curiosidade, B foca em urgência/dor). Mesmas regras de tamanho.

4. META_DESCRIPTION_VARIANTE_C — outro ângulo (terceira via). Mesmas regras.

REGRAS:
  - Tudo prometido DEVE ter lastro no briefing/título.
  - ZERO emojis. ZERO travessão (—).
  - NUNCA repetir o título exato no og_title — se for fazer, tem que ser MELHOR.

FORMATO DE SAÍDA — EXATAMENTE estas 4 linhas, nada mais:
OG_TITLE: <texto>
META_A: <texto>
META_B: <texto>
META_C: <texto>
TXT;
    }

    private static function chamarLlm($llm, string $prompt): ?string
    {
        if (method_exists($llm, 'ask')) {
            $r = $llm->ask($prompt, ['max_tokens' => 600, 'temperature' => 0.7]);
            if (is_string($r)) return $r;
            if (is_array($r) && isset($r['text'])) return (string)$r['text'];
            if (is_array($r) && isset($r['content'])) return (string)$r['content'];
        }
        if (method_exists($llm, 'chat')) {
            $r = $llm->chat([['role' => 'user', 'content' => $prompt]], ['max_tokens' => 600]);
            if (is_string($r)) return $r;
            if (is_array($r) && isset($r['content'])) return (string)$r['content'];
        }
        return null;
    }

    /** Extrai OG_TITLE / META_A / META_B / META_C da resposta. */
    private static function parsearResposta(string $resposta): array
    {
        $linhas = preg_split('/\r?\n/', trim($resposta)) ?: [];
        $out = ['og_title' => '', 'meta_description' => '', 'meta_description_variantes' => []];
        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '') continue;
            if (preg_match('/^OG_TITLE:\s*(.+)$/u', $linha, $m)) {
                $out['og_title'] = trim($m[1], "\"'“”‘’ ");
            } elseif (preg_match('/^META_A:\s*(.+)$/u', $linha, $m)) {
                $out['meta_description'] = trim($m[1], "\"'“”‘’ ");
            } elseif (preg_match('/^META_(?:B|C):\s*(.+)$/u', $linha, $m)) {
                $v = trim($m[1], "\"'“”‘’ ");
                if ($v !== '') $out['meta_description_variantes'][] = $v;
            }
        }
        return self::validar($out);
    }

    private static function validar(array $tags): array
    {
        $og = $tags['og_title'] ?? '';
        $md = $tags['meta_description'] ?? '';
        $variantes = $tags['meta_description_variantes'] ?? [];

        // Limites de tamanho (se fora, descarta)
        if ($og !== '' && (mb_strlen($og, 'UTF-8') < 30 || mb_strlen($og, 'UTF-8') > self::OG_TITLE_MAX)) $og = '';
        if ($md !== '' && (mb_strlen($md, 'UTF-8') < self::META_DESC_MIN || mb_strlen($md, 'UTF-8') > self::META_DESC_MAX)) $md = '';

        $vOk = [];
        foreach ($variantes as $v) {
            $len = mb_strlen($v, 'UTF-8');
            if ($len < self::META_DESC_MIN || $len > self::META_DESC_MAX) continue;
            // Não duplica do principal
            if ($md !== '' && strcasecmp(mb_strtolower($v, 'UTF-8'), mb_strtolower($md, 'UTF-8')) === 0) continue;
            $vOk[] = $v;
            if (count($vOk) >= self::QTD_VARIANTES) break;
        }

        // Filtra clickbait
        $proibidos = ['incrível', 'imperdível', 'surpreendente', 'chocante', 'inacreditável'];
        foreach ($proibidos as $p) {
            if (mb_strpos(mb_strtolower($og, 'UTF-8'), $p) !== false) $og = '';
            if (mb_strpos(mb_strtolower($md, 'UTF-8'), $p) !== false) $md = '';
        }

        return [
            'og_title'                    => $og,
            'meta_description'            => $md,
            'meta_description_variantes'  => $vOk,
        ];
    }

    /**
     * Aplica meta_description + og_title no WP via REST. Suporta Yoast E RankMath
     * em paralelo (cada plugin lê do seu próprio meta key — se ambos instalados,
     * setamos os dois pra cobrir). Se $cfg contém cloudflare_zone_id, purga URL
     * no edge automaticamente após sucesso.
     */
    public static function aplicarNoWp($wp, int $postId, array $tags, array $cfg = []): bool
    {
        if ($postId <= 0 || !is_object($wp)) return false;
        $og = (string)($tags['og_title'] ?? '');
        $md = (string)($tags['meta_description'] ?? '');
        if ($og === '' && $md === '') return false;

        $meta = [];
        // Yoast SEO
        if ($md !== '') $meta['_yoast_wpseo_metadesc']         = $md;
        if ($og !== '') $meta['_yoast_wpseo_opengraph-title']  = $og;
        if ($og !== '') $meta['_yoast_wpseo_twitter-title']    = $og;
        // Rank Math (plugin alternativo)
        if ($md !== '') $meta['rank_math_description']         = $md;
        if ($og !== '') $meta['rank_math_facebook_title']      = $og;
        if ($og !== '') $meta['rank_math_twitter_title']       = $og;
        // SEOPress (3º plugin comum)
        if ($md !== '') $meta['_seopress_titles_desc']         = $md;
        if ($og !== '') $meta['_seopress_social_fb_title']     = $og;

        try {
            if (method_exists($wp, 'atualizarPost')) {
                $wp->atualizarPost($postId, ['meta' => $meta, 'excerpt' => $md], $cfg); // excerpt como fallback genérico
            } elseif (method_exists($wp, 'updatePost')) {
                $wp->updatePost($postId, ['meta' => $meta, 'excerpt' => $md]);
            } else {
                return false;
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
