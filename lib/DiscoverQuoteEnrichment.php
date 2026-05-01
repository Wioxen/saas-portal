<?php
/**
 * DiscoverQuoteEnrichment — extrai citação direta de fonte oficial e injeta no post.
 *
 * Sinal forte E-E-A-T: posts com `<blockquote cite="url-fonte">` mostram pro Google que
 * a info veio de fonte autoritativa. Plus: leitor confia mais.
 *
 * Estratégia:
 *   1. Pra cada fonte scrapeada, busca trechos com aspas:
 *      "frase entre aspas curvas" OU "aspas retas" OU citação reportada ("disse X")
 *   2. Filtra: 50-300 chars, com pelo menos 1 número/entidade, não fake-quote ("Título:")
 *   3. Prefere fontes oficiais (.gov.br, .edu.br, .jus.br) — mais peso E-E-A-T
 *   4. Injeta MAX 1 quote no post (mais fica spam visual)
 *      Posição: após o 2º H2 OU ~30% do scroll
 *
 * Idempotente: marker `data-cc-quote="1"`.
 *
 * Uso (em DiscoverPostProcess::processar):
 *   $html = DiscoverQuoteEnrichment::aplicar($html, $fontes, $meta);
 *
 * Falha-silenciosa: nenhuma fonte com quote elegível → retorna HTML original.
 */
class DiscoverQuoteEnrichment
{
    private const MARKER = 'data-cc-quote="1"';

    /**
     * @param string $html         HTML do post (já processado por outras etapas)
     * @param array  $fontes       array de fontes scrapeadas (cada uma: {url, content: {title, paragraphs}})
     * @param array  $meta         opcional ({titulo, ...})
     * @return string HTML com quote injetada (ou original se nada elegível)
     */
    public static function aplicar(string $html, array $fontes, array $meta = []): string
    {
        if (strpos($html, self::MARKER) !== false) return $html; // idempotência
        if (empty($fontes)) return $html;

        $melhor = self::escolherMelhorQuote($fontes);
        if ($melhor === null) return $html;

        $blockquote = self::montarBlockquote($melhor);
        return self::inserirAposSegundoH2($html, $blockquote);
    }

    /**
     * Escolhe a melhor quote dentre as fontes.
     * Ranking: oficial (gov/edu/jus) > não-oficial; depois por tamanho ideal (80-200 chars).
     */
    private static function escolherMelhorQuote(array $fontes): ?array
    {
        $candidatos = [];
        foreach ($fontes as $f) {
            $url = (string)($f['url'] ?? $f['fonte']['url'] ?? '');
            if ($url === '') continue;
            $eOficial = self::ehFonteOficial($url);
            $titulo = (string)($f['fonte']['meta']['title'] ?? $f['title'] ?? '');
            $paragrafos = $f['fonte']['content']['paragraphs'] ?? $f['paragraphs'] ?? [];
            if (!is_array($paragrafos)) continue;

            $textoFonte = implode("\n\n", array_filter($paragrafos, 'is_string'));
            $quotes = self::extrairQuotes($textoFonte);
            foreach ($quotes as $q) {
                $tam = mb_strlen($q);
                if ($tam < 50 || $tam > 300) continue;
                if (!self::passaSanidade($q)) continue;

                // Score: oficial = +10, tamanho ideal (80-200) = +5, tem número = +3
                $score = 0;
                if ($eOficial) $score += 10;
                if ($tam >= 80 && $tam <= 200) $score += 5;
                if (preg_match('/\b\d+\b/', $q)) $score += 3;
                if (preg_match('/\b(R\$|milhões|bilhões|mil|por cento|%)\b/iu', $q)) $score += 2;

                $candidatos[] = [
                    'quote'      => $q,
                    'url_fonte'  => $url,
                    'titulo'     => $titulo,
                    'oficial'    => $eOficial,
                    'score'      => $score,
                ];
            }
        }
        if (empty($candidatos)) return null;
        usort($candidatos, fn($a, $b) => $b['score'] <=> $a['score']);
        return $candidatos[0];
    }

    /**
     * Extrai sequências entre aspas curvas (“ ”) ou retas (" ") OU reportadas
     * (palavra seguida de ", afirmou X" / "disse Y" / "informou em nota").
     */
    private static function extrairQuotes(string $texto): array
    {
        $quotes = [];

        // Aspas curvas duplas (estilo brasileiro)
        if (preg_match_all('/[“"](.{40,400}?)[”"]/u', $texto, $m)) {
            foreach ($m[1] as $q) $quotes[] = trim($q);
        }
        // Aspas duplas retas — usa pares de "
        if (preg_match_all('/"([^"]{40,300})"/u', $texto, $m)) {
            foreach ($m[1] as $q) $quotes[] = trim($q);
        }
        // Trecho reportado: ", afirmou X" / "informou Y" / "diz a Z" — captura ANTES do verbo
        if (preg_match_all('/([A-ZÁÉÍÓÚÂÊÔÃÕ][^.!?]{50,250}?)[,.] ?(?:afirmou|informou|disse|destacou|esclareceu|explicou|reforçou|pontuou|afirma|informa|diz|esclarece|explica)\b/u', $texto, $m)) {
            foreach ($m[1] as $q) $quotes[] = trim($q);
        }

        // Dedupe + filtro de duplicatas óbvias
        $unique = [];
        foreach ($quotes as $q) {
            $norm = mb_strtolower(preg_replace('/\s+/u', ' ', $q));
            if (!isset($unique[$norm])) $unique[$norm] = $q;
        }
        return array_values($unique);
    }

    /** Filtra quotes que parecem "Título:", "Veja mais", header/menu, fake. */
    private static function passaSanidade(string $q): bool
    {
        // Sem palavras editoriais/menu
        if (preg_match('/(saiba mais|leia também|clique aqui|veja como|entenda|publicidade)/iu', $q)) return false;
        // Não deve ser frase imperativa só (CTA disfarçado)
        if (preg_match('/^[A-ZÁÉÍÓÚÂÊÔÃÕ]+\s*[:!]/u', $q)) return false;
        // Tem pelo menos 3 palavras espaçadas (não é hashtag/CTA)
        if (mb_substr_count($q, ' ') < 3) return false;
        return true;
    }

    private static function ehFonteOficial(string $url): bool
    {
        return (bool)preg_match('#https?://[^/]*\.(gov|edu|jus|mil|leg)\.br#i', $url);
    }

    private static function montarBlockquote(array $melhor): string
    {
        $quote = htmlspecialchars($melhor['quote'], ENT_QUOTES, 'UTF-8');
        $url   = htmlspecialchars($melhor['url_fonte'], ENT_QUOTES, 'UTF-8');
        $titulo = htmlspecialchars(mb_substr($melhor['titulo'], 0, 150), ENT_QUOTES, 'UTF-8');
        $oficial = !empty($melhor['oficial']);

        $iconeOficial = $oficial
            ? '<span title="Fonte oficial" style="display:inline-block;background:#0b57d0;color:#fff;font-size:0.7em;padding:2px 6px;border-radius:4px;margin-right:6px;font-weight:600;">OFICIAL</span>'
            : '';

        return "\n<blockquote " . self::MARKER . " cite=\"{$url}\" "
             . 'style="margin:24px 0;padding:18px 24px;background:#f8fafc;border-left:5px solid #0b57d0;font-style:italic;color:#1e293b;font-size:1.05em;line-height:1.6;border-radius:0 8px 8px 0">'
             . '<p style="margin:0 0 8px 0">' . $quote . '</p>'
             . '<footer style="font-size:0.85em;color:#64748b;font-style:normal;margin-top:8px">'
             . $iconeOficial
             . '<a href="' . $url . '" target="_blank" rel="noopener" style="color:#0b57d0;text-decoration:none">' . ($titulo ?: 'fonte') . '</a>'
             . '</footer>'
             . "</blockquote>\n";
    }

    /**
     * Insere blockquote APÓS o 2º H2 (posição ideal: leitor já se engajou).
     * Se há só 1 H2, insere após esse. Se nenhum, insere após 1º <p>.
     */
    private static function inserirAposSegundoH2(string $html, string $blockquote): string
    {
        $h2Count = preg_match_all('#</h2>#i', $html, $m, PREG_OFFSET_CAPTURE);
        if ($h2Count >= 2) {
            $segundoH2End = $m[0][1][1] + strlen($m[0][1][0]);
            return substr($html, 0, $segundoH2End) . $blockquote . substr($html, $segundoH2End);
        }
        if ($h2Count === 1) {
            $primeiroH2End = $m[0][0][1] + strlen($m[0][0][0]);
            return substr($html, 0, $primeiroH2End) . $blockquote . substr($html, $primeiroH2End);
        }
        // Sem H2: após 1º </p>
        if (preg_match('#</p>#i', $html, $mp, PREG_OFFSET_CAPTURE)) {
            $end = $mp[0][1] + strlen($mp[0][0]);
            return substr($html, 0, $end) . $blockquote . substr($html, $end);
        }
        return $html . $blockquote;
    }
}
