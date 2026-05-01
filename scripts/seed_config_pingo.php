<?php
/**
 * seed_config_pingo — bootstrap idempotente dos arquivos de config do pingo.
 *
 * Resolve gap "deploy fresh sem fontes/filtros configurados". Sem isso:
 *   - fontes_pingo.json ausente → cron roda mas array vazio = zero trends
 *   - pingo_filtros.json ausente → DiscoverPingo aplica defaults conservadores
 *
 * Arquivos cuidados:
 *   1. data/fontes_pingo.json    — MERGE idempotente (preserva state, adiciona faltantes)
 *   2. data/pingo_filtros.json   — COPY se ausente (preserva edits do servidor)
 *
 * Uso:
 *   php scripts/seed_config_pingo.php           → aplica
 *   php scripts/seed_config_pingo.php --dry-run → mostra o que faria
 *
 * Idempotente. Pode rodar múltiplas vezes.
 */

$ROOT = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv, true);

$exitCode = 0;

// ─── 1) FONTES PINGO (merge idempotente por url_rss) ───────────────────────
$dest = $ROOT . '/data/fontes_pingo.json';
$src  = $ROOT . '/data/fontes_pingo.template.json';

echo "═══ data/fontes_pingo.json ═══\n";

if (!is_file($src)) {
    echo "  ⚠ template ausente em {$src} — pulando fontes\n";
} else {
    $template = json_decode(file_get_contents($src), true);
    if (!is_array($template) || !isset($template['fontes'])) {
        echo "  ⚠ template inválido — pulando fontes\n";
    } elseif (!is_file($dest)) {
        if ($dryRun) {
            echo "  [dry-run] criaria {$dest} (" . count($template['fontes']) . " fontes)\n";
        } else {
            @mkdir(dirname($dest), 0775, true);
            if (!@copy($src, $dest)) {
                echo "  ✗ falha copiando template\n";
                $exitCode = 3;
            } else {
                echo "  ✓ criado {$dest} (" . count($template['fontes']) . " fontes)\n";
            }
        }
    } else {
        $atual = json_decode(file_get_contents($dest), true);
        if (!is_array($atual) || !isset($atual['fontes'])) {
            echo "  ✗ JSON existente inválido. Mover/apagar antes de seed.\n";
            $exitCode = 4;
        } else {
            $urlsExistentes = array_column($atual['fontes'], 'url_rss');
            $next = (int)($atual['next_id'] ?? (max(array_column($atual['fontes'], 'id') ?: [0]) + 1));
            $adicionadas = 0;
            $puladas = 0;

            foreach ($template['fontes'] as $f) {
                if (in_array($f['url_rss'] ?? '', $urlsExistentes, true)) {
                    $puladas++;
                    continue;
                }
                if ($dryRun) {
                    echo "  [dry-run] ADD #{$next}  {$f['nome']}\n";
                } else {
                    $f['id'] = $next;
                    $atual['fontes'][] = $f;
                    echo sprintf("  ADD #%d  %s\n", $next, $f['nome']);
                }
                $next++;
                $adicionadas++;
            }

            $atual['next_id'] = $next;

            if ($dryRun) {
                echo "  [dry-run] adicionaria {$adicionadas}, pularia {$puladas}\n";
            } elseif ($adicionadas > 0) {
                if (!writeAtomic($dest, $atual)) {
                    echo "  ✗ falha gravando merge\n";
                    $exitCode = 5;
                } else {
                    echo "  ✓ merge: +{$adicionadas} novas, {$puladas} já existiam, total " . count($atual['fontes']) . "\n";
                }
            } else {
                echo "  ✓ nada a fazer ({$puladas} fontes já cobertas pelo template)\n";
            }
        }
    }
}

// ─── 2) PINGO FILTROS (copy se ausente, preserva se existe) ────────────────
echo "\n═══ data/pingo_filtros.json ═══\n";

$destF = $ROOT . '/data/pingo_filtros.json';
$srcF  = $ROOT . '/data/pingo_filtros.template.json';

if (!is_file($srcF)) {
    echo "  ⚠ template ausente em {$srcF} — pulando filtros\n";
} elseif (!is_file($destF)) {
    if ($dryRun) {
        echo "  [dry-run] criaria {$destF} a partir do template\n";
    } else {
        @mkdir(dirname($destF), 0775, true);
        if (!@copy($srcF, $destF)) {
            echo "  ✗ falha copiando template\n";
            $exitCode = 6;
        } else {
            echo "  ✓ criado {$destF} a partir do template\n";
        }
    }
} else {
    echo "  ✓ já existe — preservando edits do servidor (template não sobrescreve)\n";
}

echo "\n═══ FIM ═══\n";
exit($exitCode);

// ─── helper ────────────────────────────────────────────────────────────────
function writeAtomic(string $path, array $data): bool
{
    @copy($path, $path . '.bak');
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}
