<?php
/**
 * DiscoverAmazonTag — injeta tag de afiliado em URLs Amazon brutas no HTML.
 *
 * ProductRanker já injeta tag em URLs que ele gera. Mas Sonnet pode ter inserido
 * URLs Amazon DIRETAS no texto (`https://amazon.com.br/dp/X`) sem tag. Este módulo
 * cobre esse caso — varre HTML e adiciona `?tag={X}` em todo Amazon URL bruto.
 *
 * Domínios cobertos: amazon.com.br, amzn.to, amazon.com (BR users compram em .com.br
 * mas Sonnet às vezes referencia .com geral).
 *
 * Idempotente: se URL já tem `?tag=`, não duplica.
 *
 * Sub-IDs (post_id como tag): permite Amazon Associates Reports correlacionar venda
 * com post específico (sub_id é último segmento da tag, formato `{base}-{post_id}`).
 *
 * Uso (em DiscoverPostProcess):
 *   $html = DiscoverAmazonTag::aplicar($html, $cfg['amazon_associates_tag'] ?? '', $postId);
 */
class DiscoverAmazonTag
{
    public static function aplicar(string $html, string $tagBase, int $postId = 0): string
    {
        $tagBase = trim($tagBase);
        if ($tagBase === '' || $html === '') return $html;

        // Sub-id por post: tag = base-{post_id}. Permite Amazon Associates Reports atribuir.
        // Amazon aceita tag de até 20 chars; cortamos se ultrapassar.
        $tagFinal = $postId > 0 ? mb_substr($tagBase . '-' . $postId, 0, 20) : $tagBase;

        // Match em href="https://amazon.com.br/..." OU href='...' OU em texto puro
        // Cuidado: NÃO bater /go/{slug} (que é PrettyLinks — já tratado por outro lib)
        $pattern = '#https?://(?:www\.)?(?:amazon\.com\.br|amzn\.to|amazon\.com)/[^\s\'"<>]+#i';

        return preg_replace_callback($pattern, function ($m) use ($tagFinal) {
            $url = $m[0];
            // Idempotência: já tem ?tag=
            if (preg_match('/[?&]tag=/i', $url)) return $url;
            // Skipa amzn.to (URLs encurtadas — tag é controlada na configuração da Amazon)
            if (stripos($url, 'amzn.to/') !== false) return $url;
            // Adiciona tag
            $sep = (strpos($url, '?') !== false) ? '&' : '?';
            return $url . $sep . 'tag=' . urlencode($tagFinal);
        }, $html) ?? $html;
    }
}
