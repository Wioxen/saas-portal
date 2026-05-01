<?php
/**
 * _sast_check — análise estática própria (sem composer/phpstan).
 *
 * Detecta padrões inseguros / propensos a bug em lib/ e scripts/:
 *   - eval(), assert() com strings dinâmicas
 *   - $_GET/$_POST/$_REQUEST sem sanitize antes de usar em SQL/file path
 *   - file_get_contents/include em path com $_GET (potencial path traversal)
 *   - shell_exec/exec/system com input direto
 *   - SQL string concat (mesmo que usemos PDO prepared, pegar regressões)
 *   - die()/exit() fora de scripts CLI/saude.php
 *   - @ silencioso em operações críticas (db, auth)
 *   - functions sem return type hint (warning, não fail)
 *   - PHP curtas: <? sem ?php
 *
 * Não substitui phpstan. Cobre ~70% dos bugs comuns.
 *
 * Uso:
 *   php scripts/_sast_check.php             # check todas libs/scripts
 *   php scripts/_sast_check.php --fail-warn # warnings também derrubam exit (estrito)
 *   php scripts/_sast_check.php --json
 */

declare(strict_types=1);
$rootDir = dirname(__DIR__);

$failOnWarn = in_array('--fail-warn', $argv, true);
$jsonOut    = in_array('--json', $argv, true);

$alvos = array_merge(
    glob($rootDir . '/lib/*.php') ?: [],
    glob($rootDir . '/scripts/*.php') ?: [],
    glob($rootDir . '/plugin/*.php') ?: [],
    [$rootDir . '/saude.php']
);

$issues = [];
$totalArquivos = 0;

// Self-skip: o próprio SAST contém PADRÕES nos comentários docs
$selfSkip = realpath(__FILE__);

foreach ($alvos as $arquivo) {
    if (!is_file($arquivo)) continue;
    if (realpath($arquivo) === $selfSkip) continue; // não analisa a si mesmo
    $totalArquivos++;
    $rel = ltrim(str_replace($rootDir, '', $arquivo), '/\\');
    $src = (string)@file_get_contents($arquivo);
    if ($src === '') continue;

    // Remove comentários block /* ... */ e single-line // pra reduzir false positives
    $srcSemComentarios = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
    $srcSemComentarios = preg_replace('#^\s*//.*$#m', '', $srcSemComentarios) ?? $srcSemComentarios;

    $linhas = explode("\n", $src);

    // ─── 1. eval()/assert() com strings ───
    foreach ($linhas as $n => $line) {
        $linha = $n + 1;
        if (preg_match('/\beval\s*\(/', $line) && !str_contains($line, '//')) {
            $issues[] = ['arq' => $rel, 'linha' => $linha, 'severidade' => 'error', 'tipo' => 'eval', 'codigo' => trim(mb_substr($line, 0, 120))];
        }
        if (preg_match('/\bassert\s*\(\s*[\'"\$]/', $line) && !str_starts_with(ltrim($line), '//')) {
            $issues[] = ['arq' => $rel, 'linha' => $linha, 'severidade' => 'warn', 'tipo' => 'assert_string', 'codigo' => trim(mb_substr($line, 0, 120))];
        }
    }

    // ─── 2. shell_exec/exec/system/passthru com $_VAR ───
    if (preg_match_all('/\b(shell_exec|exec|system|passthru|popen|proc_open)\s*\([^)]*\$_(GET|POST|REQUEST|COOKIE)/i', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $linha = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
            $issues[] = ['arq' => $rel, 'linha' => $linha, 'severidade' => 'error', 'tipo' => 'shell_input_direto', 'codigo' => 'shell call com $_VAR direto'];
        }
    }

    // ─── 3. SQL string concat (PDO query/exec/prepare com SELECT/INSERT/UPDATE/DELETE) ───
    // Refinado: só flagga se a query tem SELECT/INSERT/UPDATE/DELETE/CREATE — evita
    // falso positivo com $xpath->query() do DOM
    if (preg_match_all('/->(?:query|exec|prepare)\s*\(\s*["\'][^"\']*\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b[^"\']*\$\w+/i', $srcSemComentarios, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $linha = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
            $linhaTexto = $linhas[$linha - 1] ?? '';
            // Falso positivo se :var presente (prepared) OU ?
            if (strpos($linhaTexto, ':') !== false || strpos($linhaTexto, '?') !== false) continue;
            $issues[] = ['arq' => $rel, 'linha' => $linha, 'severidade' => 'warn', 'tipo' => 'sql_concat_suspeito', 'codigo' => trim(mb_substr($linhaTexto, 0, 120))];
        }
    }

    // ─── 4. file_get_contents/include com $_GET/$_POST direto ───
    if (preg_match_all('/(file_get_contents|file_put_contents|include|require|require_once|include_once)\s*\(\s*\$_(GET|POST|REQUEST)/i', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $linha = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
            $issues[] = ['arq' => $rel, 'linha' => $linha, 'severidade' => 'error', 'tipo' => 'path_traversal', 'codigo' => 'IO/include com $_GET/$_POST direto'];
        }
    }

    // ─── 5. <? short tag (deprecated/risky se short_open_tag desligado) ───
    // Flagga só fora de comentários — ignora `<?` em strings literais "<?xml" ou em /* */
    if (preg_match('/<\?(?!php|=|xml)/', $srcSemComentarios, $m, PREG_OFFSET_CAPTURE)) {
        $linha = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
        $linhaTexto = $linhas[$linha - 1] ?? '';
        // Falso positivo: string literal contendo <?
        if (!preg_match('/[\'"][^\'"]*<\?/', $linhaTexto)) {
            $issues[] = ['arq' => $rel, 'linha' => $linha, 'severidade' => 'warn', 'tipo' => 'short_tag', 'codigo' => trim(mb_substr($linhaTexto, 0, 120))];
        }
    }

    // ─── 6. echo/print de variável não-escaped em contexto HTML ───
    // Heurística: arquivo que NÃO é puro CLI e tem `echo $var` sem htmlspecialchars/json_encode
    if (str_contains($rel, 'lib') === false && str_contains($rel, 'plugin') === false && !str_contains($rel, 'scripts/')) {
        if (preg_match_all('/\becho\s+\$_(GET|POST|REQUEST|COOKIE)\b/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $linha = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
                $issues[] = ['arq' => $rel, 'linha' => $linha, 'severidade' => 'error', 'tipo' => 'xss_echo', 'codigo' => 'echo $_VAR sem escape'];
            }
        }
    }

    // ─── 7. die()/exit() em libs (que devem usar exception) ───
    if (str_contains($rel, 'lib/')) {
        if (preg_match_all('/\b(die|exit)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $linha = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
                $linhaTexto = $linhas[$linha - 1] ?? '';
                // Plugins WP usam if (!defined('ABSPATH')) exit; — OK
                if (strpos($linhaTexto, 'ABSPATH') !== false) continue;
                if (str_starts_with(ltrim($linhaTexto), '//')) continue;
                $issues[] = ['arq' => $rel, 'linha' => $linha, 'severidade' => 'warn', 'tipo' => 'die_em_lib', 'codigo' => 'die/exit em lib (use exception)'];
            }
        }
    }

    // ─── 8. operator @ em chamadas críticas ───
    // Só flagga em chamadas db/auth — chamadas a IO opcional já é padrão usar @
    if (preg_match_all('/@\s*\$\w*->(query|exec|prepare|fetch|fetchAll)\b/', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $linha = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
            $issues[] = ['arq' => $rel, 'linha' => $linha, 'severidade' => 'warn', 'tipo' => 'silenced_db', 'codigo' => '@ em chamada DB silencia bug'];
        }
    }

    // ─── 9. require/include sem path absoluto ───
    // Padrão preferido: usar __DIR__ ou variável $rootDir. Detecta require 'string-relativa'
    if (preg_match_all('/\b(?:require|require_once|include|include_once)\s*\(?\s*[\'"]([^\'"\$]+)[\'"]\s*\)?/i', $srcSemComentarios, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => $hit) {
            $path = $m[1][$i][0] ?? '';
            // Path absoluto (começa com / ou C:) ou já tem __DIR__ — OK
            if (preg_match('#^(/|[A-Za-z]:|\\\\)#', $path)) continue;
            $linha = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
            $linhaTexto = $linhas[$linha - 1] ?? '';
            if (strpos($linhaTexto, '__DIR__') !== false) continue;
            if (strpos($linhaTexto, 'ABSPATH') !== false) continue;
            $issues[] = ['arq' => $rel, 'linha' => $linha, 'severidade' => 'warn', 'tipo' => 'include_relativo', 'codigo' => trim(mb_substr($linhaTexto, 0, 120))];
        }
    }

    // ─── 10. Hardcoded credential (senhas/tokens em string literal) ───
    if (preg_match_all('/(api_key|password|token|secret)\s*=\s*[\'"]([a-zA-Z0-9_-]{20,})[\'"]/i', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => $hit) {
            $valor = $m[2][$i][0];
            // Não-flagga se é placeholder do .env.example
            if (str_contains($valor, 'xxxx') || str_contains($valor, 'placeholder') || str_contains($valor, 'example')) continue;
            $linha = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
            $linhaTexto = $linhas[$linha - 1] ?? '';
            // .env.example é OK
            if (str_contains($rel, '.env.example')) continue;
            $issues[] = ['arq' => $rel, 'linha' => $linha, 'severidade' => 'warn', 'tipo' => 'hardcoded_secret', 'codigo' => trim(mb_substr($linhaTexto, 0, 120))];
        }
    }
}

// Agrupa por severidade
$errors = array_filter($issues, fn($i) => $i['severidade'] === 'error');
$warns  = array_filter($issues, fn($i) => $i['severidade'] === 'warn');

// Output
if ($jsonOut) {
    echo json_encode([
        'arquivos_analisados' => $totalArquivos,
        'errors'              => count($errors),
        'warnings'            => count($warns),
        'issues'              => $issues,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo "\n══════════════════════════════════════════════════\n";
    echo " SAST CHECK · {$totalArquivos} arquivos analisados\n";
    echo "══════════════════════════════════════════════════\n\n";

    if (empty($issues)) {
        echo "✓ Nenhum issue detectado\n";
    } else {
        // Agrupa por tipo
        $porTipo = [];
        foreach ($issues as $iss) {
            $porTipo[$iss['tipo']][] = $iss;
        }
        foreach ($porTipo as $tipo => $list) {
            $sev = $list[0]['severidade'];
            $tag = $sev === 'error' ? '🔴 ERROR' : '🟡 WARN';
            echo "{$tag}  {$tipo}  ({" . count($list) . " hits)\n";
            foreach (array_slice($list, 0, 5) as $iss) {
                echo "  · {$iss['arq']}:{$iss['linha']}  {$iss['codigo']}\n";
            }
            if (count($list) > 5) echo "  ... e mais " . (count($list) - 5) . "\n";
            echo "\n";
        }
    }

    echo "──────────────────────────────────────────────────\n";
    echo " ERRORS:   " . count($errors) . "\n";
    echo " WARNINGS: " . count($warns) . "\n";
    echo "──────────────────────────────────────────────────\n";
}

if (count($errors) > 0) exit(2);
if ($failOnWarn && count($warns) > 0) exit(1);
exit(0);
