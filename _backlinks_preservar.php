<?php
/**
 * Preserva backlinks do post antigo + injeta backlinks internos relacionados por keyword.
 *
 * Regras:
 * - Extrai todos os <a href> do HTML antigo (dedupado por URL)
 * - URLs já presentes no novo HTML são ignoradas (zero repetição)
 * - Internos (mesmo host do site): 3 posições estratégicas
 *     -> após o primeiro parágrafo
 *     -> no meio do artigo
 *     -> antes do fechamento
 *   Pool de candidatos internos = internos_antigos + relacionados_wp (dedupado, antigos têm prioridade)
 * - Externos: SEMPRE no final do conteúdo, em bloco único "Fontes e referências"
 */

if (!function_exists('atualizar_extrair_backlinks')) {
    function atualizar_extrair_backlinks(string $html): array
    {
        $links = [];
        if (!preg_match_all('#<a\s+[^>]*href=(["\'])(https?://[^"\'\s]+)\1[^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER)) {
            return $links;
        }
        $vistos = [];
        foreach ($m as $match) {
            $url = trim($match[2]);
            $anchor = trim(html_entity_decode(strip_tags($match[3]), ENT_QUOTES, 'UTF-8'));
            if ($url === '' || $anchor === '') continue;
            $chave = strtolower(rtrim($url, '/'));
            if (isset($vistos[$chave])) continue;
            $vistos[$chave] = true;
            $links[] = ['url' => $url, 'anchor' => $anchor];
        }
        return $links;
    }
}

if (!function_exists('atualizar_chave_url')) {
    function atualizar_chave_url(string $url): string
    {
        return strtolower(rtrim($url, '/'));
    }
}

if (!function_exists('atualizar_eh_interno')) {
    function atualizar_eh_interno(string $url, string $siteHost): bool
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        if ($host === '' || $siteHost === '') return false;
        return strpos($host, $siteHost) !== false || strpos($siteHost, $host) !== false;
    }
}

if (!function_exists('atualizar_preservar_backlinks')) {
    function atualizar_preservar_backlinks(
        string $htmlAntigo,
        string $novoHtml,
        string $siteUrl,
        array $relacionadosWp = []
    ): string {
        $siteHost = strtolower(parse_url($siteUrl, PHP_URL_HOST) ?: '');
        if ($siteHost === '') return $novoHtml;

        $novoLower = strtolower($novoHtml);

        $internos = [];
        $externos = [];
        $usados   = [];

        // 1. Antigos — preservar link equity primeiro
        foreach (atualizar_extrair_backlinks($htmlAntigo) as $l) {
            $chave = atualizar_chave_url($l['url']);
            if (isset($usados[$chave])) continue;
            if (strpos($novoLower, $chave) !== false) { $usados[$chave] = true; continue; }
            $usados[$chave] = true;
            if (atualizar_eh_interno($l['url'], $siteHost)) $internos[] = $l;
            else $externos[] = $l;
        }

        // 2. Relacionados do WP — complementam o pool de internos
        foreach ($relacionadosWp as $r) {
            $url = trim((string)($r['url'] ?? $r['link'] ?? ''));
            $anchor = trim((string)($r['anchor'] ?? $r['title'] ?? ''));
            if ($url === '' || $anchor === '') continue;
            $chave = atualizar_chave_url($url);
            if (isset($usados[$chave])) continue;
            if (strpos($novoLower, $chave) !== false) { $usados[$chave] = true; continue; }
            $usados[$chave] = true;
            if (atualizar_eh_interno($url, $siteHost)) $internos[] = ['url' => $url, 'anchor' => $anchor];
        }

        if (empty($internos) && empty($externos)) return $novoHtml;

        $resultado = $novoHtml;

        // 3. Injeta internos em 3 posições estratégicas (após 1º, meio, antes do fechamento)
        if (!empty($internos) && preg_match_all('#<p\b[^>]*>.*?</p>#is', $resultado, $pm, PREG_OFFSET_CAPTURE)) {
            $paragrafos = $pm[0];
            $totalP = count($paragrafos);
            if ($totalP >= 3) {
                $posInternas = array_values(array_unique([0, intdiv($totalP, 2), $totalP - 2]));
                $frases = ['Leia também', 'Veja também', 'Saiba mais'];
                $alvos = [];
                foreach ($posInternas as $k => $idx) {
                    if (!isset($internos[$k])) break;
                    $alvos[] = ['link' => $internos[$k], 'idxP' => $idx, 'frase' => $frases[$k] ?? 'Leia também'];
                }
                // Ordem decrescente por offset para não invalidar posições durante o splice
                usort($alvos, fn($a, $b) => $paragrafos[$b['idxP']][1] <=> $paragrafos[$a['idxP']][1]);
                foreach ($alvos as $a) {
                    [$paraText, $paraOffset] = $paragrafos[$a['idxP']];
                    $endOffset = $paraOffset + strlen($paraText);
                    $url    = htmlspecialchars($a['link']['url'], ENT_QUOTES, 'UTF-8');
                    $anchor = htmlspecialchars($a['link']['anchor'], ENT_QUOTES, 'UTF-8');
                    $inject = "\n<p class='cc-backlink-interno'><strong>" . $a['frase'] . ":</strong> <a href='" . $url . "'>" . $anchor . "</a></p>\n";
                    $resultado = substr($resultado, 0, $endOffset) . $inject . substr($resultado, $endOffset);
                }
            }
        }

        // 4. Externos: SEMPRE no final, bloco único "Fontes e referências"
        if (!empty($externos)) {
            $itens = '';
            foreach ($externos as $e) {
                $url    = htmlspecialchars($e['url'], ENT_QUOTES, 'UTF-8');
                $anchor = htmlspecialchars($e['anchor'], ENT_QUOTES, 'UTF-8');
                $itens .= "<li><a href='" . $url . "' rel='noopener noreferrer' target='_blank'>" . $anchor . "</a></li>";
            }
            $bloco = "\n<div class='cc-backlinks-externos'><h3>Fontes e referências</h3><ul>" . $itens . "</ul></div>\n";
            $resultado .= $bloco;
        }

        return $resultado;
    }
}
