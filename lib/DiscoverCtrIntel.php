<?php
/**
 * DiscoverCtrIntel — sinais de SERP REAL pra alimentar o LLM e otimizar CTR.
 *
 * Hoje Sonnet inventa título baseado em termo + briefing — sem saber COMO PESSOAS
 * BUSCAM. Este módulo extrai 3 sinais oficiais Google e injeta no prompt:
 *
 *   1. AUTOCOMPLETE (5-10 sufixos) — o que pessoas digitam quando começam o termo
 *   2. RELATED SEARCHES — queries irmãs (cluster expandido)
 *   3. PEOPLE ALSO ASK (PAA) — perguntas reais do SERP (vão pra FAQ schema)
 *
 * Cache 12h por termo (intent muda lentamente, evita pagar Serper).
 *
 * Uso:
 *   $intel = DiscoverCtrIntel::obter('enem 2026', $serper);
 *   // → {autocomplete:[...], related:[...], paa:[{question, answer?}], cached:bool}
 *
 *   $bloco = DiscoverCtrIntel::paraPromptContext($intel);
 *   // → string formatada pra injetar como bloco do prompt
 */

require_once __DIR__ . '/Env.php';

class DiscoverCtrIntel
{
    private const CACHE_DIR = '/../data/cache/ctr_intel';
    private const CACHE_TTL = 43200; // 12h

    /**
     * Coleta intel de busca pra um termo. Cache 12h.
     *
     * @param string $termo
     * @param object $serper instância Serper (com método autocomplete/relatedSearches)
     * @return array {autocomplete, related, paa, cached, termo}
     */
    public static function obter(string $termo, $serper): array
    {
        $termoNorm = trim(mb_strtolower($termo, 'UTF-8'));
        if ($termoNorm === '') {
            return self::vazio($termo);
        }

        $cacheFile = self::cacheFilePath($termoNorm);
        if (is_file($cacheFile) && (time() - @filemtime($cacheFile)) < self::CACHE_TTL) {
            $raw = @file_get_contents($cacheFile);
            $cached = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($cached)) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        // Fetch — falha-silenciosa em qualquer chamada
        $autocomplete = [];
        $related = [];
        $paa = [];

        try {
            $auto = $serper->autocomplete($termo);
            // Estrutura típica: ['suggestions' => [{value, ...}, ...]]
            $sugs = $auto['suggestions'] ?? $auto['relatedSearches'] ?? [];
            foreach ($sugs as $s) {
                $val = is_array($s) ? ((string)($s['value'] ?? $s['query'] ?? '')) : (string)$s;
                $val = trim($val);
                if ($val !== '' && strcasecmp($val, $termo) !== 0) {
                    $autocomplete[] = $val;
                }
                if (count($autocomplete) >= 10) break;
            }
        } catch (Throwable $e) { /* fail-open */ }

        try {
            $relPaa = $serper->relatedSearches($termo);
            $relRaw = $relPaa['related'] ?? [];
            foreach ($relRaw as $r) {
                $q = is_array($r) ? ((string)($r['query'] ?? '')) : (string)$r;
                $q = trim($q);
                if ($q !== '' && strcasecmp($q, $termo) !== 0) {
                    $related[] = $q;
                }
                if (count($related) >= 10) break;
            }

            $paaRaw = $relPaa['paa'] ?? [];
            foreach ($paaRaw as $p) {
                if (!is_array($p)) continue;
                $q = trim((string)($p['question'] ?? ''));
                $a = trim((string)($p['snippet'] ?? $p['answer'] ?? ''));
                if ($q === '') continue;
                $paa[] = ['question' => $q, 'answer_snippet' => mb_substr($a, 0, 300)];
                if (count($paa) >= 8) break;
            }
        } catch (Throwable $e) { /* fail-open */ }

        $intel = [
            'termo'        => $termo,
            'autocomplete' => $autocomplete,
            'related'      => $related,
            'paa'          => $paa,
            'cached'       => false,
            'gerado_em'    => date('c'),
        ];

        // Persist cache (best-effort)
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        @file_put_contents($cacheFile, json_encode($intel, JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $intel;
    }

    /**
     * Formata intel como bloco pra injetar no prompt do Sonnet.
     * Inclui REGRAS explícitas pro modelo aproveitar os sinais.
     */
    public static function paraPromptContext(array $intel): string
    {
        $auto = $intel['autocomplete'] ?? [];
        $rel  = $intel['related'] ?? [];
        $paa  = $intel['paa'] ?? [];

        if (empty($auto) && empty($rel) && empty($paa)) {
            return ''; // nada útil — não polui prompt
        }

        $bloco = "═══ INTELIGÊNCIA SERP REAL (Google BR · pt-BR) ═══\n";
        $bloco .= "Use estes sinais pra alinhar o post com COMO PESSOAS REALMENTE BUSCAM.\n";
        $bloco .= "Cobertura ampla = mais tráfego de cauda longa.\n\n";

        if (!empty($auto)) {
            $bloco .= "AUTOCOMPLETE (sufixos mais buscados — cubra ≥3 em H2/H3):\n";
            foreach (array_slice($auto, 0, 10) as $a) $bloco .= "  • {$a}\n";
            $bloco .= "\n";
        }

        if (!empty($rel)) {
            $bloco .= "RELATED SEARCHES (queries irmãs — gere seções secundárias cobrindo 2-3 destas):\n";
            foreach (array_slice($rel, 0, 8) as $r) $bloco .= "  • {$r}\n";
            $bloco .= "\n";
        }

        if (!empty($paa)) {
            $bloco .= "PEOPLE ALSO ASK (perguntas REAIS do SERP — TRANSFORME em FAQ no fim):\n";
            $bloco .= "  Crie seção '## Perguntas Frequentes' com 3-5 destas perguntas LITERAIS\n";
            $bloco .= "  e respostas curtas baseadas nas FONTES (não invente).\n";
            $bloco .= "  PHP gera schema FAQPage automaticamente.\n";
            foreach (array_slice($paa, 0, 8) as $p) {
                $q = $p['question'] ?? '';
                $bloco .= "  • {$q}\n";
            }
            $bloco .= "\n";
        }

        $bloco .= "REGRAS DE OURO:\n";
        $bloco .= "  1. NÃO invente queries — use APENAS as listadas acima.\n";
        $bloco .= "  2. Não force tudo — se uma query é off-topic do trend principal, pula.\n";
        $bloco .= "  3. FAQ no fim deve usar PERGUNTAS LITERAIS do PAA — Google premia match exato.\n";
        $bloco .= "  4. Distribua subtópicos em H2 (3-5) e H3 (3-5 por H2).\n";

        return $bloco;
    }

    private static function cacheFilePath(string $termoNorm): string
    {
        $hash = sha1($termoNorm);
        $sub  = substr($hash, 0, 2);
        return __DIR__ . self::CACHE_DIR . '/' . $sub . '/' . $hash . '.json';
    }

    private static function vazio(string $termo): array
    {
        return [
            'termo' => $termo, 'autocomplete' => [], 'related' => [], 'paa' => [],
            'cached' => false, 'gerado_em' => date('c'),
        ];
    }
}
