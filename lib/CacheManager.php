<?php
/**
 * CacheManager — pruning de caches descartáveis (A2 da Frente Resiliência).
 *
 * Problema que resolve:
 *   - data/cache/, data/articles_cache/, data/search_console_cache/, data/debug/, e outros
 *     diretórios de cache crescem sem limite. Em volume real (100+ posts/dia × 6 sites),
 *     articles_cache pode passar de 1GB em semanas.
 *   - Disco enche → 503 silencioso. Pingo trava. WP upload falha. CRON quebra.
 *
 * Estratégia (3 modos, podem ser combinados):
 *   - byAge:   apaga arquivos com mtime > N dias (clássico TTL)
 *   - bySize:  se diretório > N MB, apaga mais antigos (LRU por mtime) até voltar ao limite
 *   - byCount: se >N arquivos, apaga mais antigos
 *
 * Aplicar em ordem (chamada conveniente `prune($dir, $regras)`):
 *   byAge → bySize → byCount
 *
 * SEGURANÇA:
 *   - Só apaga DENTRO do diretório passado (não recursivo por default — usar $recursive=true).
 *   - Whitelist de extensões: .json, .html, .htm, .txt, .log, .png, .jpg, .jpeg, .webp, .xml.
 *     Arquivos fora da whitelist são IGNORADOS (não apagados).
 *   - NUNCA apaga `.lock`, `.meta`, ou arquivos sem extensão (state files).
 *   - Modo dry-run pra preview antes de aplicar.
 *
 * Uso:
 *   $stat = CacheManager::prune($dir, ['byAge' => 7, 'bySize' => 100, 'byCount' => 5000]);
 *   $stat = CacheManager::prune($dir, ['byAge' => 7], $dryRun = true);
 *   $info = CacheManager::stats($dir);  // {arquivos, bytes, oldest, newest}
 */
class CacheManager
{
    /**
     * Extensões "seguras" pra deletar (caches conhecidos). Arquivos com extensão fora
     * dessa lista são ignorados — proteção contra apagar state, locks, credenciais.
     */
    private const EXTENSOES_PERMITIDAS = [
        'json', 'html', 'htm', 'txt', 'log',
        'png', 'jpg', 'jpeg', 'webp', 'gif',
        'xml', 'csv', 'tsv', 'tmp',
    ];

    /**
     * Aplica regras de pruning em ordem (byAge → bySize → byCount).
     *
     * @param string $dir    diretório a podar
     * @param array  $regras {byAge: dias, bySize: MB, byCount: N}
     * @param bool   $dryRun não apaga, só lista
     * @param bool   $recursive desce subdiretórios
     * @return array {bytes_antes, bytes_depois, arquivos_apagados, arquivos_mantidos, motivos: {...}}
     */
    public static function prune(string $dir, array $regras, bool $dryRun = false, bool $recursive = false): array
    {
        if (!is_dir($dir)) {
            return ['ok' => false, 'erro' => "diretório não existe: {$dir}"];
        }

        $arquivos = self::listarElegiveis($dir, $recursive);
        $bytesAntes = array_sum(array_column($arquivos, 'size'));

        $motivos = ['byAge' => 0, 'bySize' => 0, 'byCount' => 0];
        $apagar = [];

        // 1) byAge — TTL clássico
        if (!empty($regras['byAge'])) {
            $cutoff = time() - ((int)$regras['byAge'] * 86400);
            foreach ($arquivos as $i => $f) {
                if ($f['mtime'] < $cutoff) {
                    $apagar[$f['path']] = 'byAge';
                    $motivos['byAge']++;
                    unset($arquivos[$i]);
                }
            }
            $arquivos = array_values($arquivos);
        }

        // 2) bySize — se total > limite, apaga mais antigos até voltar
        // (isset em vez de !empty pra aceitar 0 como "limite zero, apaga tudo")
        if (isset($regras['bySize']) && (int)$regras['bySize'] >= 0) {
            $maxBytes = max(0, (int)$regras['bySize']) * 1024 * 1024;
            $totalAtual = array_sum(array_column($arquivos, 'size'));
            if ($totalAtual > $maxBytes) {
                // ordena por mtime ASC (mais antigos primeiro)
                usort($arquivos, fn($a, $b) => $a['mtime'] <=> $b['mtime']);
                while ($totalAtual > $maxBytes && !empty($arquivos)) {
                    $f = array_shift($arquivos);
                    $apagar[$f['path']] = 'bySize';
                    $motivos['bySize']++;
                    $totalAtual -= $f['size'];
                }
            }
        }

        // 3) byCount — se ainda muitos, apaga mais antigos
        if (isset($regras['byCount']) && (int)$regras['byCount'] >= 0) {
            $maxCount = (int)$regras['byCount'];
            if (count($arquivos) > $maxCount) {
                usort($arquivos, fn($a, $b) => $a['mtime'] <=> $b['mtime']);
                $excedente = count($arquivos) - $maxCount;
                while ($excedente-- > 0 && !empty($arquivos)) {
                    $f = array_shift($arquivos);
                    $apagar[$f['path']] = 'byCount';
                    $motivos['byCount']++;
                }
            }
        }

        // Aplica (ou simula)
        $apagados = 0;
        $bytesApagados = 0;
        foreach ($apagar as $path => $motivo) {
            $size = is_file($path) ? (int)@filesize($path) : 0;
            if ($dryRun) {
                $apagados++;
                $bytesApagados += $size;
                continue;
            }
            if (@unlink($path)) {
                $apagados++;
                $bytesApagados += $size;
            }
        }

        return [
            'ok'                 => true,
            'dir'                => $dir,
            'dry_run'            => $dryRun,
            'arquivos_elegiveis' => count(self::listarElegiveis($dir, $recursive)) + ($dryRun ? 0 : -$apagados),
            'arquivos_apagados'  => $apagados,
            'bytes_antes'        => $bytesAntes,
            'bytes_apagados'     => $bytesApagados,
            'bytes_depois'       => $bytesAntes - ($dryRun ? 0 : $bytesApagados),
            'motivos'            => $motivos,
        ];
    }

    /**
     * Stats do diretório (sem apagar nada).
     */
    public static function stats(string $dir, bool $recursive = false): array
    {
        if (!is_dir($dir)) return ['ok' => false, 'erro' => 'inexistente'];
        $arquivos = self::listarElegiveis($dir, $recursive);
        if (empty($arquivos)) {
            return ['ok' => true, 'dir' => $dir, 'arquivos' => 0, 'bytes' => 0, 'mb' => 0];
        }
        $bytes = array_sum(array_column($arquivos, 'size'));
        usort($arquivos, fn($a, $b) => $a['mtime'] <=> $b['mtime']);
        return [
            'ok'       => true,
            'dir'      => $dir,
            'arquivos' => count($arquivos),
            'bytes'    => $bytes,
            'mb'       => round($bytes / 1024 / 1024, 2),
            'oldest'   => date('Y-m-d H:i:s', $arquivos[0]['mtime']),
            'newest'   => date('Y-m-d H:i:s', end($arquivos)['mtime']),
        ];
    }

    // ── helpers ──

    private static function listarElegiveis(string $dir, bool $recursive): array
    {
        $arquivos = [];
        if ($recursive) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($it as $info) {
                if (!$info->isFile()) continue;
                if (!self::extensaoPermitida($info->getFilename())) continue;
                $arquivos[] = ['path' => $info->getPathname(), 'mtime' => $info->getMTime(), 'size' => $info->getSize()];
            }
        } else {
            $files = @scandir($dir);
            if (!$files) return [];
            foreach ($files as $name) {
                if ($name === '.' || $name === '..') continue;
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                if (!is_file($path)) continue;
                if (!self::extensaoPermitida($name)) continue;
                $arquivos[] = ['path' => $path, 'mtime' => (int)@filemtime($path), 'size' => (int)@filesize($path)];
            }
        }
        return $arquivos;
    }

    private static function extensaoPermitida(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '') return false; // sem extensão = state file → não apagar
        return in_array($ext, self::EXTENSOES_PERMITIDAS, true);
    }
}
