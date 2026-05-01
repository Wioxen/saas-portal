<?php
/**
 * DiscoverSerpAnalyzer — analisa top 10 do SERP pra identificar gap competitivo.
 *
 * Pra cada termo, pede o /search Serper e extrai:
 *   - Tamanho médio dos posts top (pra calibrar "quantas palavras escrever")
 *   - Domínios dominantes (sinaliza autoridade requerida)
 *   - Datas de publicação (se todos antigos = oportunidade freshness)
 *   - Tipos de conteúdo (artigo, FAQ, vídeo, etc — via snippet patterns)
 *   - Estrutura H2/H3 implícita via featured snippet/sitelinks
 *
 * Output vira bloco no prompt do Sonnet com diretivas explícitas:
 *   "Top 10 médio = 1500 palavras → escreva 1700-2000"
 *   "Todos posts são de 2023 → DESTAQUE 2026 no título"
 *   "Faltam tópicos X, Y, Z na primeira página → COBRA"
 *
 * Cache 24h por termo (SERP não varia tanto).
 *
 * Custo Serper: 1 query por termo (já fazemos no `relatedSearches`, mas separamos
 * pra controle). ~$0.0003 por gap analysis.
 */

class DiscoverSerpAnalyzer
{
    private const CACHE_DIR = '/../data/cache/serp_analysis';
    private const CACHE_TTL = 86400; // 24h

    /** Domínios próprios — não conta como concorrente. */
    private const DOMINIOS_PROPRIOS = [
        'cursosenacgratuito.com.br', 'guiadoscursos.com', 'vagasebeneficios.com',
        'comocomprar.com.br', 'ondecompraragora.com', 'leaodabarra.com.br',
    ];

    /**
     * Analisa SERP top 10 pro termo. Retorna gap report.
     *
     * @return array {
     *   tamanho_medio_chars: int,
     *   dominios_dominantes: [{dominio, count}],
     *   tem_post_recente_2025_2026: bool,
     *   posts_antigos_count: int,
     *   gap_titulos: [titulos do top que NÃO mencionam ano/dado importante],
     *   recomendacao_palavras: int,
     *   recomendacao_freshness: string,
     *   organic_top: array
     * }
     */
    public static function analisar(string $termo, $serper): array
    {
        $termoNorm = trim(mb_strtolower($termo, 'UTF-8'));
        $cacheFile = self::cacheFilePath($termoNorm);
        if (is_file($cacheFile) && (time() - @filemtime($cacheFile)) < self::CACHE_TTL) {
            $raw = @file_get_contents($cacheFile);
            $cached = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($cached)) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        try {
            $resp = $serper->search($termo, 10);
        } catch (Throwable $e) {
            return self::vazio($termo);
        }

        $organicos = $resp['organic'] ?? [];
        if (empty($organicos)) return self::vazio($termo);

        // FEATURED SNIPPET — Google extrai resposta direta de UM post pra "position 0".
        // Detectar tipo (paragraph/list/table) permite Sonnet estruturar resposta NO MESMO formato
        // → maior chance de roubar a posição. Serper retorna em $resp['answerBox'].
        $featuredSnippet = self::detectarFeaturedSnippet($resp);

        // Filtra próprios sites (não somos concorrência)
        $concorrentes = array_filter($organicos, function ($o) {
            $url = (string)($o['link'] ?? '');
            $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
            $host = preg_replace('/^www\./', '', $host);
            return !in_array($host, self::DOMINIOS_PROPRIOS, true);
        });
        $concorrentes = array_values($concorrentes);

        // Análise: tamanho via comprimento do snippet (proxy fraco mas indicativo)
        $charsTotal = 0;
        $countSnippets = 0;
        $dominios = [];
        $titulos = [];
        $datas = [];
        $temRecente = false;

        foreach ($concorrentes as $o) {
            $url = (string)($o['link'] ?? '');
            $titulo = (string)($o['title'] ?? '');
            $snippet = (string)($o['snippet'] ?? '');
            $data = (string)($o['date'] ?? '');

            $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
            $host = preg_replace('/^www\./', '', $host);
            $dominios[$host] = ($dominios[$host] ?? 0) + 1;
            $titulos[] = $titulo;

            if ($snippet !== '') { $charsTotal += mb_strlen($snippet); $countSnippets++; }
            if ($data !== '') $datas[] = $data;

            // Detecta freshness: snippet ou data com 2025/2026
            if (preg_match('/\b(202[5-9])\b/', $titulo . ' ' . $snippet . ' ' . $data)) {
                $temRecente = true;
            }
        }

        $tamMedio = $countSnippets > 0 ? (int)($charsTotal / $countSnippets) : 0;

        // Domínios dominantes (top 3 com >= 2 posts)
        arsort($dominios);
        $dominantes = [];
        foreach ($dominios as $dom => $cnt) {
            if ($cnt >= 2) $dominantes[] = ['dominio' => $dom, 'count' => $cnt];
            if (count($dominantes) >= 3) break;
        }

        // Posts antigos: count de items SEM "2025|2026|atual"
        $antigos = 0;
        foreach ($titulos as $t) {
            if (!preg_match('/\b(202[4-9])\b/', $t) && !preg_match('/\b(atualizad|recém|este ano|esta semana)\b/iu', $t)) {
                $antigos++;
            }
        }

        // Recomendações
        // Snippet médio é ~150-200 chars. Posts top normalmente são 1500-2500 palavras.
        // Heurística pragmática: passar do tamanho médio em pelo menos 200 palavras.
        // Discover prefere posts 600-700, mas SEO long-tail responde a 1500+.
        // Vamos sugerir: max(800, tamMedio_palavras + 200) palavras.
        $tamMediaPalavrasEstimado = max(1000, (int)($tamMedio * 8)); // snippet ~150 chars proxy
        $sugestaoPalavras = max(800, $tamMediaPalavrasEstimado + 200);
        $sugestaoPalavras = min(2200, $sugestaoPalavras); // teto pra não exagerar

        $freshnessRec = $temRecente
            ? 'Top 10 já tem post recente — DESTAQUE diferencial editorial (autoridade, dados específicos)'
            : 'Top 10 NÃO tem nada de 2025/2026 — INCLUA ano nos títulos/H2 pra ganhar freshness';

        $intel = [
            'termo'                       => $termo,
            'top_10_count'                => count($organicos),
            'concorrentes_count'          => count($concorrentes),
            'tamanho_snippet_medio'       => $tamMedio,
            'dominios_dominantes'         => $dominantes,
            'tem_post_recente_2025_2026'  => $temRecente,
            'posts_antigos_count'         => $antigos,
            'recomendacao_palavras'       => $sugestaoPalavras,
            'recomendacao_freshness'      => $freshnessRec,
            'organic_titulos_top5'        => array_slice($titulos, 0, 5),
            'featured_snippet'            => $featuredSnippet, // {tem, tipo, dono_dominio, snippet_atual} ou null
            'cached'                      => false,
            'gerado_em'                   => date('c'),
        ];

        // Persist cache
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        @file_put_contents($cacheFile, json_encode($intel, JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $intel;
    }

    /**
     * Bloco de prompt que orienta Sonnet sobre como vencer o SERP atual.
     */
    public static function paraPromptContext(array $intel): string
    {
        if (empty($intel['concorrentes_count']) || $intel['concorrentes_count'] === 0) return '';

        $bloco = "═══ ANÁLISE COMPETITIVA SERP ═══\n";
        $bloco .= "Os {$intel['concorrentes_count']} concorrentes que ranqueiam HOJE pra '{$intel['termo']}':\n\n";

        if (!empty($intel['dominios_dominantes'])) {
            $bloco .= "DOMÍNIOS DOMINANTES (precisamos vencer):\n";
            foreach ($intel['dominios_dominantes'] as $d) {
                $bloco .= "  • {$d['dominio']} ({$d['count']} posts no top 10)\n";
            }
            $bloco .= "\n";
        }

        if (!empty($intel['organic_titulos_top5'])) {
            $bloco .= "TÍTULOS DO TOP 5 (use pra entender ANGULOS — NÃO copie):\n";
            foreach ($intel['organic_titulos_top5'] as $t) {
                $bloco .= "  • " . mb_substr($t, 0, 120) . "\n";
            }
            $bloco .= "\n";
        }

        $bloco .= "DIRETIVAS PRA VENCER:\n";
        $bloco .= "  • {$intel['recomendacao_freshness']}\n";
        $bloco .= "  • Tamanho-alvo: ~{$intel['recomendacao_palavras']} palavras (cobre cluster expandido)\n";
        $bloco .= "  • Estrutura: 4-6 H2 + FAQ. NÃO repita exatamente os ângulos do top — cubra o que ELES não cobrem.\n";
        if ($intel['posts_antigos_count'] >= 5) {
            $bloco .= "  • {$intel['posts_antigos_count']}/10 posts são ANTIGOS — VITÓRIA POR FRESHNESS é caminho fácil.\n";
        }

        // FEATURED SNIPPET HIJACKING — diretiva específica baseada no tipo do snippet atual
        $snippet = $intel['featured_snippet'] ?? null;
        if (is_array($snippet) && !empty($snippet['tem'])) {
            $bloco .= "\n" . self::blocoHijackingFeaturedSnippet($snippet);
        } else {
            $bloco .= "\n═══ FEATURED SNIPPET — OPORTUNIDADE LIVRE ═══\n";
            $bloco .= "NÃO há featured snippet ainda nesse termo. Quem estruturar primeiro PEGA.\n";
            $bloco .= "PRIMEIRA seção depois do P1 deve ser uma resposta DIRETA da query em formato candidate:\n";
            $bloco .= "  • Pergunta exata do termo como H2 (ex: 'O que é X?', 'Como fazer Y?')\n";
            $bloco .= "  • 1 parágrafo de 40-60 palavras (~280 chars) com a resposta SEM rodeio\n";
            $bloco .= "  • Se for 'como fazer', pode ser lista numerada de 3-5 passos curtos\n";
            $bloco .= "  • Se for 'quanto custa / quando / qual', resposta numérica em <strong>\n";
            $bloco .= "═══ FIM FEATURED SNIPPET ═══\n";
        }

        return $bloco;
    }

    /**
     * Bloco específico de hijacking baseado no tipo do snippet atual no SERP.
     * Estrutura matching aumenta chance de Google trocar pelo nosso conteúdo.
     */
    private static function blocoHijackingFeaturedSnippet(array $snippet): string
    {
        $tipo = (string)($snippet['tipo'] ?? 'paragraph');
        $dono = (string)($snippet['dono_dominio'] ?? '?');
        $atual = trim((string)($snippet['snippet_atual'] ?? ''));
        $atualPreview = $atual !== '' ? mb_substr($atual, 0, 200, 'UTF-8') . (mb_strlen($atual, 'UTF-8') > 200 ? '...' : '') : '(sem texto)';

        $b = "═══ FEATURED SNIPPET — POSITION 0 PRA ROUBAR ═══\n";
        $b .= "Snippet atual é de '{$dono}', tipo: {$tipo}.\n";
        $b .= "Conteúdo dele (preview): \"{$atualPreview}\"\n\n";
        $b .= "ESTRUTURA OBRIGATÓRIA pra roubar:\n";

        switch ($tipo) {
            case 'list':
                $b .= "  → Logo após o P1, criar H2 com a pergunta literal do termo.\n";
                $b .= "  → Lista NUMERADA <ol> com 4-7 passos. Cada <li> = 1 frase de 8-15 palavras.\n";
                $b .= "  → Cobrir MAIS passos que o atual (se ele tem 4, faça 6) — Google premia completude.\n";
                $b .= "  → Cada <li> deve começar com VERBO ('Acesse', 'Preencha', 'Confirme').\n";
                break;
            case 'table':
                $b .= "  → Logo após o P1, H2 com a pergunta + tabela <table> comparativa.\n";
                $b .= "  → Mínimo 3 colunas, 4 linhas. Header em <th>.\n";
                $b .= "  → Cobre a comparação que o atual NÃO faz — adicione 1 critério a mais.\n";
                break;
            case 'paragraph':
            default:
                $b .= "  → Logo após o P1, H2 com a pergunta LITERAL (ex: 'O que é X?').\n";
                $b .= "  → 1 parágrafo de 40-60 palavras (~280 chars). Sem rodeio.\n";
                $b .= "  → Primeira frase = resposta DIRETA. Segunda = contexto. Terceira = exceção/detalhe.\n";
                $b .= "  → Dado-chave em <strong>. Sem 'É importante notar que...' (filler).\n";
                break;
        }
        $b .= "  → Não repita o ângulo do snippet atual — cubra o gap dele.\n";
        $b .= "═══ FIM HIJACKING ═══\n";
        return $b;
    }

    /**
     * Detecta featured snippet no response Serper.
     * Serper retorna em $resp['answerBox'] = {title, snippet, snippetHighlighted, link, ...}
     * Tipo é heurístico: lista (\n + bullets), tabela (palavras-tabela no snippet), senão paragraph.
     */
    private static function detectarFeaturedSnippet(array $resp): array
    {
        $box = $resp['answerBox'] ?? null;
        if (!is_array($box) || empty($box)) {
            return ['tem' => false];
        }
        $textoSnippet = trim((string)($box['snippet'] ?? $box['answer'] ?? ''));
        $link = (string)($box['link'] ?? '');
        $host = strtolower(parse_url($link, PHP_URL_HOST) ?? '');
        $host = preg_replace('/^www\./', '', $host);

        // Heurística de tipo
        $tipo = 'paragraph';
        // Lista: snippet com múltiplas linhas iniciadas por número/bullet
        $linhas = preg_split('/\r?\n/', $textoSnippet) ?: [];
        $linhasComBullet = 0;
        foreach ($linhas as $l) {
            if (preg_match('/^\s*(?:\d+[\.\)]|[-•*])\s+\S/u', $l)) $linhasComBullet++;
        }
        if ($linhasComBullet >= 2) {
            $tipo = 'list';
        } elseif (preg_match('/\b(?:tabela|comparativo|preço|R\$\s*\d|\d+\s*x\s*\d+)\b/iu', $textoSnippet)) {
            // Heurística fraca, mas serve. Idealmente Serper avisaria.
            $tipo = 'table';
        }

        return [
            'tem'             => true,
            'tipo'            => $tipo,
            'dono_dominio'    => $host,
            'dono_url'        => $link,
            'snippet_atual'   => $textoSnippet,
            'titulo_atual'    => trim((string)($box['title'] ?? '')),
        ];
    }

    private static function cacheFilePath(string $termoNorm): string
    {
        $hash = sha1($termoNorm);
        $sub = substr($hash, 0, 2);
        return __DIR__ . self::CACHE_DIR . '/' . $sub . '/' . $hash . '.json';
    }

    private static function vazio(string $termo): array
    {
        return [
            'termo' => $termo, 'top_10_count' => 0, 'concorrentes_count' => 0,
            'tamanho_snippet_medio' => 0, 'dominios_dominantes' => [],
            'tem_post_recente_2025_2026' => false, 'posts_antigos_count' => 0,
            'recomendacao_palavras' => 0, 'recomendacao_freshness' => '',
            'organic_titulos_top5' => [], 'featured_snippet' => ['tem' => false],
            'cached' => false, 'gerado_em' => date('c'),
        ];
    }
}
