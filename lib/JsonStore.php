<?php
/**
 * JsonStore — escrita atômica + backup rotativo pra arquivos JSON críticos.
 *
 * Problema que resolve:
 *   - file_put_contents puro não é atômico: se o processo morre no meio da escrita,
 *     o arquivo fica truncado/corrompido. JSON corrompido = DiscoverDb perde histórico,
 *     DiscoverFila perde fila, contador de status quebra.
 *   - Sem backup, corrupção = recuperação manual (ou nada).
 *
 * Estratégia (write):
 *   1. Serializa em string (JSON_THROW_ON_ERROR pra falhar visível, não silenciosa).
 *   2. Escreve em $path.tmp.{uniqid} com LOCK_EX.
 *   3. Antes do rename, copia o arquivo atual pra $path.bak.{Ymd_His} (mantém últimos N).
 *   4. rename() do tmp → path (atômico no NTFS/ext4 dentro do mesmo volume).
 *   5. Em caso de falha: tmp é apagado e backup permanece.
 *
 * Estratégia (read):
 *   - Se arquivo principal corrompido (json_decode == null E tamanho > 2 bytes), tenta o
 *     backup mais recente automaticamente. Loga em error_log.
 *
 * Uso:
 *   JsonStore::write('/path/db.json', $data);                   // backup default = 5 versões
 *   JsonStore::write('/path/cache.json', $data, 0);             // sem backup (descartável)
 *   $data = JsonStore::read('/path/db.json', ['records' => []]);// default se ausente OU corrupto
 *
 *   // operacional:
 *   JsonStore::backups('/path/db.json');                        // ['/path/db.json.bak.20260426_113258', ...]
 *   JsonStore::restore('/path/db.json');                        // restaura mais recente
 *   JsonStore::restore('/path/db.json', '20260426_113258');     // restaura específico
 */
class JsonStore
{
    /** Default de backups por arquivo. Configurável por chamada. */
    public const KEEP_BACKUPS_DEFAULT = 5;

    /**
     * Escreve atomicamente. Retorna true em sucesso, false em falha.
     * Falha NÃO corrompe o arquivo existente — atomic rename garante all-or-nothing.
     *
     * @param string $path        caminho final do JSON
     * @param mixed  $data        estrutura PHP serializável (array, object com toArray, etc)
     * @param int    $keepBackups número de backups históricos a manter (0 = sem backup)
     * @param bool   $pretty      JSON_PRETTY_PRINT (default true; útil pra debug, false economiza disco)
     */
    public static function write(string $path, $data, int $keepBackups = self::KEEP_BACKUPS_DEFAULT, bool $pretty = true): bool
    {
        $dir = dirname($path);
        if ($dir !== '' && !is_dir($dir)) @mkdir($dir, 0777, true);

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) $flags |= JSON_PRETTY_PRINT;

        try {
            $json = json_encode($data, $flags | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            error_log("[JsonStore] encode falhou em {$path}: " . $e->getMessage());
            return false;
        }
        if (!is_string($json)) return false;

        // 1) escreve no tmp
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            error_log("[JsonStore] write tmp falhou em {$tmp}");
            return false;
        }

        // 2) backup do arquivo atual ANTES do rename (se existe E é parseável — proteção
        // contra "backup do já-corrompido"). Se atual está corrupto, mantém os backups
        // anteriores válidos (não inflar com cópias inúteis).
        if ($keepBackups > 0 && is_file($path) && filesize($path) > 0) {
            $rawAtual = @file_get_contents($path);
            if ($rawAtual !== false && json_decode($rawAtual, true) !== null) {
                $bakPath = $path . '.bak.' . date('Ymd_His') . '_' . bin2hex(random_bytes(2));
                @copy($path, $bakPath);
                self::rotacionarBackups($path, $keepBackups);
            }
            // Se atual corrompido, não cria backup novo. Recovery futura usa backups antigos.
        }

        // 3) rename atômico (Windows: rename só é atômico se destino não existe; PHP faz unlink+rename)
        // Em XAMPP/Windows: PHP rename quebra se destino existe. Workaround: tenta @rename e fallback.
        if (!@rename($tmp, $path)) {
            // Fallback Windows: unlink destino, rename de novo
            if (is_file($path)) @unlink($path);
            if (!@rename($tmp, $path)) {
                error_log("[JsonStore] rename falhou {$tmp} -> {$path}");
                @unlink($tmp);
                return false;
            }
        }

        return true;
    }

    /**
     * Lê JSON. Se arquivo ausente, retorna $default.
     * Se corrompido (json_decode null e size > 2), tenta backup mais recente automaticamente
     * e loga o evento — pra alerting saber que houve recovery.
     */
    public static function read(string $path, $default = null)
    {
        if (!is_file($path)) return $default;

        $raw = @file_get_contents($path);
        if ($raw === false) return $default;

        // Arquivo vazio é OK (significa "ainda nada salvo" pra muitos JSONs nossos)
        if ($raw === '' || $raw === '[]' || $raw === '{}') {
            return is_callable([self::class, 'tipoVazio']) ? self::tipoVazio($default, $raw) : $default;
        }

        $data = json_decode($raw, true);
        if ($data !== null) return $data;

        // null + arquivo > 2 bytes = corrupção. Varre backups (mais recente primeiro)
        // até achar um parseavel — em vez de só tentar o primeiro.
        if (strlen($raw) > 2) {
            error_log("[JsonStore] CORRUPCAO detectada em {$path}, varrendo backups");
            foreach (self::backups($path) as $bak) {
                $rawBak = @file_get_contents($bak);
                if ($rawBak === false) continue;
                $dataBak = json_decode($rawBak, true);
                if ($dataBak !== null) {
                    error_log("[JsonStore] RECOVERY OK de {$bak}");
                    self::alertarCorrupcao($path, $bak, true);
                    return $dataBak;
                }
            }
            error_log("[JsonStore] sem backup recuperavel pra {$path}");
            self::alertarCorrupcao($path, null, false);
        }
        return $default;
    }

    /**
     * Notifica via HealthWebhook quando há corrupção. Recovery OK = warning, sem backup = error.
     * Falha-silenciosa se HealthWebhook indisponível (não bloqueia leitura).
     */
    private static function alertarCorrupcao(string $path, ?string $bakUsado, bool $recovered): void
    {
        $hwPath = __DIR__ . '/HealthWebhook.php';
        if (!is_file($hwPath)) return;
        try {
            require_once $hwPath;
            $base = basename($path);
            if ($recovered) {
                HealthWebhook::aviso("JsonStore: recovery automático de '{$base}'", [
                    'arquivo' => $base,
                    'backup_usado' => $bakUsado ? basename($bakUsado) : null,
                ]);
            } else {
                HealthWebhook::erro("JsonStore: CORRUPÇÃO sem backup recuperavel em '{$base}'", [
                    'arquivo' => $base,
                ]);
            }
        } catch (Throwable $e) { /* falha silenciosa */ }
    }

    /**
     * Lista backups do arquivo, mais recente primeiro. Vazio se não há.
     * @return string[] paths absolutos
     */
    public static function backups(string $path): array
    {
        $glob = @glob($path . '.bak.*');
        if (!$glob) return [];
        rsort($glob); // ordem por nome (timestamp embutido) descendente
        return $glob;
    }

    /**
     * Restaura um backup. Se $stamp = null, usa o mais recente.
     * Cria backup do estado atual antes (recovery reversível).
     */
    public static function restore(string $path, ?string $stamp = null): bool
    {
        // Constrói lista de candidatos (mais recente primeiro)
        if ($stamp === null) {
            $candidatos = self::backups($path);
        } else {
            $todosBks = self::backups($path);
            $candidatos = [];
            foreach ($todosBks as $c) {
                if (strpos(basename($c), 'bak.' . $stamp) !== false) $candidatos[] = $c;
            }
        }
        // Varre até achar um parseavel
        $bak = null;
        $raw = null;
        foreach ($candidatos as $c) {
            $r = @file_get_contents($c);
            if ($r === false) continue;
            if (json_decode($r, true) !== null || strlen($r) <= 2) {
                $bak = $c;
                $raw = $r;
                break;
            }
            error_log("[JsonStore] restore: ignorando backup corrompido {$c}");
        }
        if ($bak === null || $raw === null) {
            error_log("[JsonStore] restore: nenhum backup parseavel pra {$path} stamp={$stamp}");
            return false;
        }
        // backup do estado atual antes (caso recovery seja errado) — mas só se o atual é parseavel
        if (is_file($path)) {
            $atual = @file_get_contents($path);
            if ($atual !== false && (json_decode($atual, true) !== null || strlen($atual) <= 2)) {
                @copy($path, $path . '.bak.prerestore_' . date('Ymd_His'));
            }
        }
        return @file_put_contents($path, $raw, LOCK_EX) !== false;
    }

    // ── helpers internos ──

    private static function backupMaisRecente(string $path): ?string
    {
        $bks = self::backups($path);
        return $bks[0] ?? null;
    }

    private static function rotacionarBackups(string $path, int $keep): void
    {
        $bks = self::backups($path);
        if (count($bks) <= $keep) return;
        $apagar = array_slice($bks, $keep); // já vem do mais recente; corta os antigos
        foreach ($apagar as $f) @unlink($f);
    }
}
