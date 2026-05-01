<?php
/**
 * CronLock — lock cooperativo cross-platform pra impedir 2 instâncias do mesmo cron.
 *
 * Por que existe:
 *   Cron pode disparar overlap (DST, host travado, restart, NTP, execução longa). 2x
 *   `tick_filas` simultâneos podem corromper state (race no read+write JSON), gerar
 *   posts duplicados, dobrar custo de API LLM.
 *
 * Estratégia:
 *   1. flock(LOCK_EX | LOCK_NB) — adquire exclusivo, falha rápido se já lockado.
 *   2. Lock libera AUTOMATICAMENTE no fim do processo (mesmo em fatal/segfault).
 *   3. Stale recovery: se lock ficou >10min sem heartbeat (processo morreu sem release),
 *      próxima tentativa quebra automaticamente em vez de ficar travado pra sempre.
 *   4. Metadata em JSON: pid + host + script + started_at + heartbeat_at — pra debug.
 *   5. heartbeat() pra loops longos atualizarem mtime e indicar "ainda vivo".
 *
 * Padrão de uso:
 *   $lock = new CronLock('auto_refresh');
 *   if (!$lock->aquirir()) { echo "outra instância rodando"; exit(0); }
 *   try { ... trabalho real ... $lock->heartbeat(); ... }
 *   finally { $lock->liberar(); }   // opcional — auto-libera no fim do PHP
 *
 * Inspeção (sem tocar):
 *   $info = CronLock::status('auto_refresh');  // {locked, pid, age_s, heartbeat_at, ...}
 *   CronLock::quebrar('auto_refresh');         // emergência: força destrava
 *
 * Onde os locks vivem: data/locks/ no projeto. Persistente entre reboots, fácil debug.
 * (Antes era sys_get_temp_dir(), mas isso some entre reboots e dificulta inspeção remota.)
 */
class CronLock
{
    /** Tempo (segundos) sem heartbeat até lock ser considerado stale e poder ser quebrado. */
    public const STALE_AFTER_DEFAULT = 600;

    private string $nome;
    private string $path;
    private int $staleAfter;
    /** @var resource|null */
    private $fp = null;
    private bool $obtido = false;

    public function __construct(string $nome, int $staleAfter = self::STALE_AFTER_DEFAULT)
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $nome)) {
            throw new InvalidArgumentException("Nome de lock inválido: '{$nome}'");
        }
        $this->nome = $nome;
        $this->staleAfter = $staleAfter;
        $this->path = self::lockPathPara($nome);
    }

    /**
     * Tenta adquirir o lock. Retorna true se adquiriu, false se outra instância tem
     * (E não está stale). Em caso de stale, quebra automaticamente e adquire.
     */
    public function aquirir(): bool
    {
        if ($this->obtido) return true;

        $dir = dirname($this->path);
        if ($dir !== '' && !is_dir($dir)) @mkdir($dir, 0777, true);

        $this->fp = @fopen($this->path, 'c+');
        if ($this->fp === false) {
            // Sem permissão de escrita? Falha aberta — permite execução, loga
            error_log("[CronLock] não conseguiu abrir {$this->path} — fail-open (sem proteção contra overlap)");
            return true;
        }

        if (!@flock($this->fp, LOCK_EX | LOCK_NB)) {
            // Já lockado. Verifica se está stale (mtime velho = processo morreu).
            $stat = @stat($this->path);
            $age = $stat ? (time() - (int)$stat['mtime']) : 0;
            if ($age > $this->staleAfter) {
                error_log("[CronLock] lock '{$this->nome}' stale (age={$age}s > {$this->staleAfter}s), forçando reset");
                @fclose($this->fp);
                @unlink($this->path);
                // Re-tenta UMA vez (não loop infinito)
                $this->fp = @fopen($this->path, 'c+');
                if ($this->fp === false || !@flock($this->fp, LOCK_EX | LOCK_NB)) {
                    if (is_resource($this->fp)) @fclose($this->fp);
                    $this->fp = null;
                    return false;
                }
            } else {
                @fclose($this->fp);
                $this->fp = null;
                return false;
            }
        }

        // Marca o lockfile com sentinel mínimo (só pra flock funcionar; metadata vai em
        // arquivo separado .meta pra que status() consiga ler em outro processo OU mesmo
        // processo Windows — fopen 'c+' bloqueia leitura paralela).
        @ftruncate($this->fp, 0);
        @rewind($this->fp);
        @fwrite($this->fp, "1\n");
        @fflush($this->fp);

        // Metadata em arquivo .meta separado (file_get_contents ok)
        $payload = [
            'pid'          => function_exists('getmypid') ? getmypid() : 0,
            'host'         => function_exists('gethostname') ? gethostname() : '',
            'started_at'   => date('c'),
            'heartbeat_at' => date('c'),
            'script'       => $_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? ''),
            'lock_name'    => $this->nome,
        ];
        @file_put_contents($this->path . '.meta', json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);

        $this->obtido = true;
        return true;
    }

    /**
     * Atualiza o heartbeat (timestamp + mtime do arquivo). Chamar dentro de loops
     * longos pra sinalizar "ainda vivo" e evitar que outro processo quebre o lock.
     */
    public function heartbeat(): void
    {
        if (!$this->obtido) return;
        $metaFile = $this->path . '.meta';
        $raw = is_file($metaFile) ? @file_get_contents($metaFile) : '';
        $data = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
        $data['heartbeat_at'] = date('c');
        @file_put_contents($metaFile, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        // Touch lockfile pra atualizar mtime (stale detection usa mtime)
        if (is_resource($this->fp)) {
            @fflush($this->fp);
        }
        @touch($this->path);
    }

    /**
     * Libera o lock manualmente. Chamada opcional — fim do processo já libera.
     */
    public function liberar(): void
    {
        if (!$this->obtido) return;
        if (is_resource($this->fp)) {
            @flock($this->fp, LOCK_UN);
            @fclose($this->fp);
        }
        $this->fp = null;
        $this->obtido = false;
        @unlink($this->path);
        @unlink($this->path . '.meta');
    }

    /** Path do arquivo de lock (debug/admin). */
    public function path(): string { return $this->path; }

    // ── inspeção estática (sem instanciar) ──

    /**
     * Status de um lock por nome. Não toca/não retém — apenas inspeciona.
     */
    public static function status(string $nome): array
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $nome)) {
            return ['locked' => false, 'erro' => 'nome_invalido'];
        }
        $path = self::lockPathPara($nome);
        if (!is_file($path)) return ['locked' => false, 'file' => $path];

        $stat = @stat($path);
        $age  = $stat ? (time() - (int)$stat['mtime']) : null;

        // Metadata em arquivo paralelo (não conflita com flock no .lock)
        $meta = [];
        $metaPath = $path . '.meta';
        if (is_file($metaPath)) {
            $rawMeta = @file_get_contents($metaPath);
            if (is_string($rawMeta) && $rawMeta !== '') {
                $decoded = json_decode($rawMeta, true);
                if (is_array($decoded)) $meta = $decoded;
            }
        }

        // Detecta lock ativo via flock try
        $locked = true;
        $fp = @fopen($path, 'rb');
        if ($fp !== false) {
            if (@flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = false;
                @flock($fp, LOCK_UN);
            }
            @fclose($fp);
        }

        return [
            'locked'       => $locked,
            'file'         => $path,
            'age_s'        => $age,
            'pid'          => $meta['pid'] ?? null,
            'host'         => $meta['host'] ?? null,
            'started_at'   => $meta['started_at'] ?? null,
            'heartbeat_at' => $meta['heartbeat_at'] ?? null,
            'script'       => $meta['script'] ?? null,
        ];
    }

    /**
     * Quebra lock manualmente (operação de emergência). Use quando processo morreu sem
     * release E o stale timeout ainda não bateu.
     */
    public static function quebrar(string $nome): bool
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $nome)) return false;
        $path = self::lockPathPara($nome);
        @unlink($path . '.meta');
        if (!is_file($path)) return true;
        return @unlink($path);
    }

    private static function lockPathPara(string $nome): string
    {
        $safe = preg_replace('/[^a-z0-9_-]+/i', '_', $nome) ?: 'unnamed';
        return __DIR__ . '/../data/locks/' . $safe . '.lock';
    }
}
