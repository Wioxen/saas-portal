<?php
/**
 * Gera variações long-tail de uma palavra-chave pra uso em H2/H3.
 * Objetivo: cada H2 cobrir uma intenção de busca diferente da mesma raiz semântica.
 *
 * Exemplo:
 *  keyword: "isenção do Enem 2026"
 *  variações geradas:
 *   - "Quem tem direito à isenção do Enem 2026"
 *   - "Como pedir isenção do Enem 2026 passo a passo"
 *   - "Prazo para solicitar isenção do Enem 2026"
 *   - "Documentos necessários para isenção do Enem 2026"
 *   - "Isenção do Enem 2026 vale para quem já foi isento antes"
 *   - "Como acompanhar o pedido de isenção do Enem 2026"
 *   - "Isenção do Enem 2026: quando sai o resultado"
 *   - "O que fazer se a isenção do Enem 2026 for negada"
 */
class DiscoverKeywordLongTail
{
    /** Templates de variações long-tail ordenados por intenção de busca. */
    private static array $templates = [
        // intenção: elegibilidade
        'Quem tem direito a <KEYWORD>',
        'Quem pode pedir <KEYWORD>',
        // intenção: processo
        'Como pedir <KEYWORD> passo a passo',
        'Como solicitar <KEYWORD>',
        // intenção: prazo
        'Prazo para <KEYWORD>',
        'Até quando pedir <KEYWORD>',
        // intenção: requisitos
        'Documentos necessários para <KEYWORD>',
        'Requisitos para <KEYWORD>',
        // intenção: valor/benefício
        '<KEYWORD> vale quanto',
        '<KEYWORD>: valor atualizado',
        // intenção: resultado/status
        'Como acompanhar <KEYWORD>',
        'Quando sai o resultado de <KEYWORD>',
        // intenção: negativa/erro
        'O que fazer se <KEYWORD> for negada',
        'Erros que barram <KEYWORD>',
        // intenção: calendário
        'Calendário de <KEYWORD>',
    ];

    /**
     * Gera até N variações long-tail relevantes à keyword.
     * Filtra templates que não fazem sentido gramatical (ex: "Quem tem direito a Enem 2026" soa estranho se a keyword já vira uma ação).
     */
    public static function gerar(string $keyword, int $max = 10): array
    {
        $k = trim($keyword);
        if ($k === '') return [];

        $variacoes = [];
        foreach (self::$templates as $tpl) {
            $frase = str_replace('<KEYWORD>', $k, $tpl);
            // Pequenas correções gramaticais: "a Isenção" (vogal), "do Concurso"
            $frase = self::corrigirArtigo($frase);
            $frase = trim(preg_replace('/\s+/', ' ', $frase));
            if (mb_strlen($frase, 'UTF-8') < 15 || mb_strlen($frase, 'UTF-8') > 80) continue;
            $variacoes[] = $frase;
        }
        return array_slice(array_values(array_unique($variacoes)), 0, $max);
    }

    /**
     * Ajusta artigos ("a", "à") conforme vogal inicial da keyword.
     * Simples: detecta "a <vogal>" e vira "à <vogal>" se faz sentido (preposição+artigo).
     */
    private static function corrigirArtigo(string $s): string
    {
        // "direito a Isenção" → "direito à isenção"
        $s = preg_replace_callback(
            '/\bdireito\s+a\s+([AÁÉÍÓÚÂÊÔÃÕ][a-záéíóúâêôãõç]+)/u',
            fn($m) => 'direito à ' . mb_strtolower(mb_substr($m[1], 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($m[1], 1, null, 'UTF-8'),
            $s
        ) ?? $s;
        // "solicitar Isenção" → "solicitar a isenção"
        // Não forço aqui, fica natural.
        return $s;
    }

    /**
     * Gera instrução pronta pra plugar no prompt de geração.
     * Injeta as variações como SUGESTÃO — o LLM escolhe 3-5 conforme couber na estrutura do artigo.
     */
    public static function instrucaoProPrompt(string $keyword, array $variacoes = []): string
    {
        if (empty($variacoes)) $variacoes = self::gerar($keyword);
        if (empty($variacoes)) return '';
        $lista = '';
        foreach (array_slice($variacoes, 0, 10) as $v) {
            $lista .= "  - {$v}\n";
        }
        return "\n═══ VARIAÇÕES LONG-TAIL PARA H2/H3 ═══\n"
             . "Cada H2/H3 deve COBRIR uma intenção de busca diferente da mesma raiz semântica. Use estas variações como BASE (adapte com dados concretos da fonte, nunca copie ao pé da letra):\n"
             . $lista
             . "\nREGRAS:\n"
             . "- Pelo menos 50% dos H2 devem conter a keyword principal ou variação semântica (SEO interno + Discover).\n"
             . "- NÃO use todas as variações — escolha 3-5 mais relevantes ao ângulo do artigo.\n"
             . "- Combine a variação com um dado específico da fonte (número, data, critério). Ex: NÃO \"Quem tem direito\" sozinho → SIM \"Quem tem direito: 4 perfis aprovados pelo Inep\".\n"
             . "═══ FIM LONG-TAIL ═══\n";
    }

    /**
     * Diagnóstico: mede cobertura de keyword/variantes nos H2 do artigo gerado.
     * @return array ['total_h2' => int, 'com_keyword' => int, 'cobertura_pct' => float, 'h2_fora' => array]
     */
    public static function diagnosticarCobertura(string $html, string $keyword): array
    {
        $k = trim(mb_strtolower($keyword, 'UTF-8'));
        if ($k === '') return ['total_h2' => 0, 'com_keyword' => 0, 'cobertura_pct' => 0, 'h2_fora' => []];

        // Extrai palavras "significativas" da keyword (3+ chars, não stopwords)
        $stop = ['de','do','da','dos','das','em','no','na','para','pra','por','com','o','a','os','as','e','ou'];
        $palavrasKw = [];
        foreach (preg_split('/\s+/', $k) as $w) {
            if (mb_strlen($w, 'UTF-8') < 3) continue;
            if (in_array($w, $stop, true)) continue;
            $palavrasKw[] = $w;
        }
        if (empty($palavrasKw)) return ['total_h2' => 0, 'com_keyword' => 0, 'cobertura_pct' => 0, 'h2_fora' => []];

        if (!preg_match_all('/<h2[^>]*>([\s\S]*?)<\/h2>/i', $html, $m)) {
            return ['total_h2' => 0, 'com_keyword' => 0, 'cobertura_pct' => 0, 'h2_fora' => []];
        }

        $total = 0; $com = 0; $fora = [];
        foreach ($m[1] as $h) {
            $plain = trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($plain === '') continue;
            $total++;
            $lower = mb_strtolower($plain, 'UTF-8');
            $match = false;
            foreach ($palavrasKw as $w) {
                if (mb_strpos($lower, $w) !== false) { $match = true; break; }
            }
            if ($match) $com++;
            else $fora[] = $plain;
        }

        $pct = $total > 0 ? round(($com / $total) * 100, 1) : 0.0;
        return [
            'total_h2'      => $total,
            'com_keyword'   => $com,
            'cobertura_pct' => $pct,
            'h2_fora'       => $fora,
            'alerta'        => $total > 0 && $pct < 50,
        ];
    }
}
