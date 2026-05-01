<?php
/**
 * DiscoverFaqEnricher — injeta seção FAQ a partir do PAA cacheado quando o
 * artigo NÃO criou FAQ próprio. Garante FAQPage schema (rich snippet) mesmo
 * quando Sonnet ignora as instruções do CtrIntel.
 *
 * Estratégia:
 *   1. Detecta se HTML já tem FAQ (keywords ou <details><summary>) → no-op
 *   2. Pega PAA (perguntas literais do Google) do meta/trend
 *   3. Injeta `<h2>Perguntas frequentes</h2>` + 3-5 <details> antes do
 *      botão de compartilhar (caso exista) ou no fim
 *   4. injetarFaqSchema() (PostProcess) detecta e gera FAQPage automático
 *
 * O PAA vem com `answer_snippet` que o Serper extrai do próprio Google —
 * resposta com 1-3 frases. Boa prática SEO: FAQ literal do PAA = match exato
 * de query → rich snippet quase garantido.
 *
 * Idempotente via marker `data-cc-faq-enriched="1"`.
 */

class DiscoverFaqEnricher
{
    private const MARKER = 'data-cc-faq-enriched="1"';
    private const MAX_FAQ = 5;
    private const MIN_FAQ = 3;

    /**
     * Aplica enriquecimento se PAA disponível e HTML não tem FAQ.
     *
     * @param string $html
     * @param array  $meta  pode conter ['paa' => [{question, answer_snippet}, ...]]
     * @param array  $trend pode conter ['paa' => ...] no payload
     * @return string HTML possivelmente enriquecido
     */
    public static function aplicar(string $html, array $meta = [], array $trend = []): string
    {
        if ($html === '') return $html;
        if (strpos($html, self::MARKER) !== false) return $html;
        if (self::jaTemFaq($html)) return $html;

        $paa = self::resolverPaa($meta, $trend);
        if (count($paa) < self::MIN_FAQ) return $html;

        $bloco = self::montarBloco(array_slice($paa, 0, self::MAX_FAQ));
        if ($bloco === '') return $html;

        // Injeta antes de:
        //   - .post-share (botão final de compartilhar) OU
        //   - </body> OU
        //   - fim do HTML
        if (preg_match('/<div[^>]*class=["\'][^"\']*post-share[^"\']*["\']/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = (int)$m[0][1];
            return substr($html, 0, $pos) . $bloco . substr($html, $pos);
        }
        if (stripos($html, '</body>') !== false) {
            return preg_replace('#</body>#i', $bloco . '</body>', $html, 1) ?? ($html . $bloco);
        }
        return $html . $bloco;
    }

    /** Detecta se artigo já tem seção de FAQ (heurística generosa). */
    public static function jaTemFaq(string $html): bool
    {
        // Marker explícito
        if (preg_match('/"@type"\s*:\s*"FAQPage"/i', $html)) return true;
        // Pelo menos 2 <details><summary> = considera FAQ existente
        if (preg_match_all('/<details[^>]*>\s*<summary/i', $html, $m) && count($m[0]) >= 2) {
            return true;
        }
        // H2 com keyword FAQ
        $kws = 'Perguntas\s+frequentes|Perguntas\s+e\s+respostas|FAQ|Dúvidas(?:\s+frequentes|\s+comuns|\s+mais\s+comuns)?|Tire\s+suas\s+dúvidas|Principais\s+dúvidas';
        if (preg_match('/<h[23][^>]*>[^<]*(?:' . $kws . ')[^<]*<\/h[23]>/iu', $html)) return true;
        return false;
    }

    /** Procura PAA em meta primeiro, depois no trend payload. */
    private static function resolverPaa(array $meta, array $trend): array
    {
        $candidatos = [
            $meta['paa'] ?? null,
            $trend['paa'] ?? null,
            $trend['ctr_intel']['paa'] ?? null,
        ];
        foreach ($candidatos as $p) {
            if (is_array($p) && !empty($p)) {
                $out = [];
                foreach ($p as $item) {
                    if (!is_array($item)) continue;
                    $q = trim((string)($item['question'] ?? ''));
                    $a = trim((string)($item['answer_snippet'] ?? $item['answer'] ?? $item['snippet'] ?? ''));
                    if ($q === '' || mb_strlen($a, 'UTF-8') < 20) continue;
                    if (!str_ends_with($q, '?')) $q .= '?';
                    $out[] = ['question' => $q, 'answer_snippet' => $a];
                }
                if (count($out) >= self::MIN_FAQ) return $out;
            }
        }
        return [];
    }

    /** Monta bloco HTML <h2> + <details><summary>... pronto pra injetar. */
    private static function montarBloco(array $paa): string
    {
        $items = '';
        foreach ($paa as $p) {
            $q = htmlspecialchars($p['question'], ENT_QUOTES, 'UTF-8');
            $a = htmlspecialchars($p['answer_snippet'], ENT_QUOTES, 'UTF-8');
            $items .= "<details style='margin:8px 0;padding:12px 16px;background:#f8f9fa;border-left:3px solid #0b57d0;border-radius:6px'>"
                   . "<summary style='cursor:pointer;font-weight:600;color:#202124'>{$q}</summary>"
                   . "<p style='margin:10px 0 0;color:#3c4043;line-height:1.6'>{$a}</p>"
                   . "</details>\n";
        }
        if ($items === '') return '';

        return "\n<section " . self::MARKER . " style='margin:32px 0'>"
             . "<h2 style='font-size:1.4em;margin:0 0 16px;color:#202124'>Perguntas frequentes</h2>"
             . $items
             . "</section>\n";
    }
}
