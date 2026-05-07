<?php
/** Auto-fix HTML pós-geração — extraído de gerarpost.php pra reuso em CLI. */

if (!function_exists('quebrarParagrafosLongos')) {
    function quebrarParagrafosLongos(string $html, int $maxPalavras = 70): string
    {
        $out = preg_replace_callback('#<p(\s[^>]*)?>(.*?)</p>#is', function($m) use ($maxPalavras) {
            $attrs = $m[1] ?? '';
            $texto = trim($m[2]);
            if (preg_match('#<(div|img|table|ul|ol|figure|blockquote|iframe|video|audio)\b#i', $texto)) return $m[0];
            $plano = trim(strip_tags($texto));
            if ($plano === '') return $m[0];
            $palavras = preg_match_all('/\S+/u', $plano);
            if ($palavras <= $maxPalavras) return $m[0];
            $frases = preg_split('/(?<=[.!?])\s+(?=[A-ZÁÀÃÂÄÉÈÊËÍÌÎÏÓÒÕÔÖÚÙÛÜÇÑ])/u', $texto);
            if (!is_array($frases) || count($frases) < 2) return $m[0];
            $buckets = [[]]; $idx = 0; $count = 0;
            foreach ($frases as $f) {
                $fw = preg_match_all('/\S+/u', strip_tags($f));
                if ($count + $fw > $maxPalavras && !empty($buckets[$idx])) { $idx++; $buckets[$idx] = []; $count = 0; }
                $buckets[$idx][] = $f;
                $count += $fw;
            }
            if (count($buckets) < 2) return $m[0];
            $reconstruido = '';
            foreach ($buckets as $b) {
                if (empty($b)) continue;
                $reconstruido .= '<p' . $attrs . '>' . trim(implode(' ', $b)) . "</p>\n";
            }
            return rtrim($reconstruido, "\n");
        }, $html);
        return $out ?? $html;
    }
}

if (!function_exists('sanitizarTravessoes')) {
    function sanitizarTravessoes(string $html): string
    {
        $out = preg_replace_callback(
            '#(<pre\b[^>]*>.*?</pre>)|(<code\b[^>]*>.*?</code>)|(<[^>]+>)|([^<]+)#is',
            function ($m) {
                if (!empty($m[1])) return $m[1];
                if (!empty($m[2])) return $m[2];
                if (!empty($m[3])) return $m[3];
                $txt = $m[4] ?? '';
                $txt = preg_replace('/\s*—\s*/u', ', ', $txt) ?? $txt;
                $txt = preg_replace('/\s*–\s*/u', ', ', $txt) ?? $txt;
                $txt = preg_replace('/,\s*,+/', ',', $txt) ?? $txt;
                $txt = preg_replace('/ {2,}/', ' ', $txt) ?? $txt;
                return $txt;
            },
            $html
        );
        return $out ?? $html;
    }
}

if (!function_exists('autoFixIntroInflada')) {
    function autoFixIntroInflada(string $html): string
    {
        $posH2 = stripos($html, '<h2');
        if ($posH2 === false) return $html;
        $intro = substr($html, 0, $posH2);
        $resto = substr($html, $posH2);
        if (!preg_match_all('/<p\b([^>]*)>(.*?)<\/p>/is', $intro, $ps, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) return $html;
        $intros = [];
        foreach ($ps as $row) {
            $atribs = $row[1][0];
            if (preg_match('/class\s*=\s*[\'"][^\'"]*(?:resposta-direta|snippet-resumo|leia-mais|leia-tambem|alerta-critico|fonte-rodape)[^\'"]*[\'"]/i', $atribs)) continue;
            $intros[] = ['full' => $row[0][0], 'offset' => $row[0][1], 'len' => strlen($row[0][0])];
        }
        if (count($intros) <= 3) return $html;
        $extras = array_slice($intros, 3);
        $movidos = '';
        foreach (array_reverse($extras) as $ex) {
            $movidos = $ex['full'] . "\n" . $movidos;
            $intro = substr($intro, 0, $ex['offset']) . substr($intro, $ex['offset'] + $ex['len']);
        }
        if (preg_match('/<h2\b[^>]*>.*?<\/h2>/is', $resto, $h2m, PREG_OFFSET_CAPTURE)) {
            $endH2 = $h2m[0][1] + strlen($h2m[0][0]);
            $resto = substr($resto, 0, $endH2) . "\n" . trim($movidos) . "\n" . substr($resto, $endH2);
        }
        return $intro . $resto;
    }
}

if (!function_exists('autoFixRdParaFechamento')) {
    function autoFixRdParaFechamento(string $html): string
    {
        if (!preg_match_all('/<p\b[^>]*class\s*=\s*[\'"][^\'"]*resposta-direta[^\'"]*[\'"][^>]*>.*?<\/p>/is', $html, $rds, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) return $html;
        $manter = end($rds)[0][0];
        foreach (array_reverse($rds) as $rd) {
            $offset = $rd[0][1];
            $len = strlen($rd[0][0]);
            $html = substr($html, 0, $offset) . substr($html, $offset + $len);
        }
        if (preg_match('/<p\b[^>]*>\s*(?:Conte[úu]do\s+elaborado|Fonte\s*:)/isu', $html, $fm, PREG_OFFSET_CAPTURE)) {
            $insertAt = $fm[0][1];
            $html = substr($html, 0, $insertAt) . trim($manter) . "\n\n" . substr($html, $insertAt);
        } else {
            $html = rtrim($html) . "\n\n" . trim($manter) . "\n";
        }
        return $html;
    }
}

if (!function_exists('autoFixReticenciasExcessivas')) {
    function autoFixReticenciasExcessivas(string $html): string
    {
        $count = 0;
        $out = preg_replace_callback(
            '#(<pre\b[^>]*>.*?</pre>)|(<code\b[^>]*>.*?</code>)|(<[^>]+>)|([^<]+)#is',
            function ($m) use (&$count) {
                if (!empty($m[1])) return $m[1];
                if (!empty($m[2])) return $m[2];
                if (!empty($m[3])) return $m[3];
                $txt = $m[4] ?? '';
                $txt = preg_replace_callback('/(\.{3}|…)/u', function ($mm) use (&$count) {
                    $count++;
                    return $count <= 1 ? $mm[0] : '.';
                }, $txt) ?? $txt;
                return $txt;
            },
            $html
        );
        return $out ?? $html;
    }
}
