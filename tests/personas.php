<?php
/**
 * tests/personas.php
 *
 * Testes do schema de persona em sites.php + injeção via _site_helper.php.
 * Runner: /c/xampp/php/php.exe tests/personas.php
 */

require_once __DIR__ . '/../_site_helper.php';

$pass = 0;
$total = 0;
$falhas = [];

function ok(string $desc, bool $cond): void {
    global $pass, $total, $falhas;
    $total++;
    if ($cond) { $pass++; return; }
    $falhas[] = $desc;
}

$sites = sitesDisponiveis();

// ── 1. Todos os 6 sites têm persona ───────────────────────────────────
ok('sites.php tem 6+ sites', count($sites) >= 6);

$sitesComPersona = 0;
foreach ($sites as $slug => $info) {
    if (isset($info['persona']) && is_array($info['persona']) && !empty($info['persona']['autor'])) {
        $sitesComPersona++;
    }
}
ok('todos os sites têm persona definida', $sitesComPersona === count($sites));

// ── 2. Cada persona passa em validarPersona() ─────────────────────────
foreach ($sites as $slug => $info) {
    $problemas = validarPersona($info['persona'] ?? null);
    ok("persona '{$slug}' válida", empty($problemas));
}

// ── 3. aplicarSite copia persona pro $cfg ─────────────────────────────
$cfg = ['wp_url' => ''];
aplicarSite($cfg, $sites, 'cursosenac');
ok('aplicarSite copia persona para $cfg', isset($cfg['persona']) && !empty($cfg['persona']['autor']));
ok('persona cursosenac é Maria Gusmão', str_contains((string)$cfg['persona']['autor'], 'Maria'));
ok('cluster foco da cursosenac inclui notícia', in_array('noticias_info_critica', (array)($cfg['persona']['clusters_foco'] ?? []), true));

// Cross-check: trocar pra outro site, persona muda
$cfg2 = ['wp_url' => ''];
aplicarSite($cfg2, $sites, 'leaodabarra');
ok('persona muda ao trocar de site', $cfg['persona']['autor'] !== $cfg2['persona']['autor']);
ok('persona leaodabarra fala de IR', str_contains(mb_strtolower($cfg2['persona']['especialidade']), 'imposto'));

// ── 4. validarPersona pega problemas reais ────────────────────────────
$incompleta = ['autor' => 'X', 'voz' => '', 'especialidade' => 'Y'];  // faltam: audiencia, tom + voz vazio
ok('validarPersona detecta voz vazio', count(validarPersona($incompleta)) > 0);
ok('validarPersona aceita persona completa', empty(validarPersona([
    'autor' => 'X', 'voz' => 'Y', 'especialidade' => 'Z', 'audiencia' => 'W', 'tom' => 'V',
])));
ok('validarPersona rejeita null', !empty(validarPersona(null)));
ok('validarPersona rejeita array vazio', !empty(validarPersona([])));

// ── 5. Personas distintas (não duplicadas) ────────────────────────────
$autores = [];
foreach ($sites as $slug => $info) {
    if (!empty($info['persona']['autor'])) $autores[] = $info['persona']['autor'];
}
ok('cada site tem autor único', count(array_unique($autores)) === count($autores));

// ── Sumário ───────────────────────────────────────────────────────────
echo "═══════════════════════════════════════════════════════════════\n";
echo "  tests/personas.php — {$total} casos\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

if (!empty($falhas)) {
    echo "─── FALHAS ─────────────────────────────────────────────────────\n";
    foreach ($falhas as $f) echo "  ✗ {$f}\n";
    echo "\n";
}

printf("  Passaram: %d / %d (%.1f%%)\n", $pass, $total, ($pass / $total) * 100);

if ($pass === $total) {
    echo "  ✓ Todos os casos passaram.\n";
    exit(0);
}
exit(1);
