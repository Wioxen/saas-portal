<?php
/**
 * DbConnection — singleton PDO pra MariaDB/MySQL com retry, transaction helper, prepared.
 *
 * Por que singleton: cada cron/request deve usar UMA conexão (PDO mantém TCP keepalive
 * por todo o ciclo). Sem isso, cada query custa ~5ms de connect overhead.
 *
 * Lê config do .env (Env::get):
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_CHARSET (default utf8mb4)
 *
 * Modo de teste: passar driver SQLite em path de testes (in-memory ':memory:')
 * via DbConnection::setTestPdo($pdo).
 *
 * Uso típico:
 *   $pdo = DbConnection::pdo();
 *   $st = $pdo->prepare("SELECT * FROM trends WHERE site = :site");
 *   $st->execute([':site' => 'cursosenac']);
 *
 * Helper de transaction:
 *   DbConnection::tx(function ($pdo) {
 *       $pdo->exec("INSERT ...");
 *       $pdo->exec("UPDATE ...");
 *   });
 */
class DbConnection
{
    private static ?PDO $pdo = null;
    private static bool $isTest = false;

    /** Retry params em caso de connection failure transitória. */
    private const CONNECT_RETRIES = 3;
    private const CONNECT_BACKOFF_MS = [0, 200, 800];

    /**
     * Retorna PDO. Cria na primeira chamada, reusa nas seguintes.
     * Lança RuntimeException se DB inacessível após retries.
     */
    public static function pdo(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;

        require_once __DIR__ . '/Env.php';
        Env::load(__DIR__ . '/../.env');

        $host    = (string)Env::get('DB_HOST', 'localhost');
        $port    = (int)Env::get('DB_PORT', 3306);
        $name    = (string)Env::get('DB_NAME', 'clonais_saas');
        $user    = (string)Env::get('DB_USER', 'root');
        $pass    = (string)Env::get('DB_PASS', '');
        $charset = (string)Env::get('DB_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // bind real (resistência a SQL injection)
            PDO::ATTR_PERSISTENT         => false, // crons curtos: keepalive não vale o risco de leak
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}, time_zone='+00:00', sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
        ];

        $ultimoErro = null;
        foreach (self::CONNECT_BACKOFF_MS as $i => $waitMs) {
            if ($waitMs > 0) usleep($waitMs * 1000);
            try {
                self::$pdo = new PDO($dsn, $user, $pass, $opts);
                return self::$pdo;
            } catch (PDOException $e) {
                $ultimoErro = $e;
                error_log("[DbConnection] tentativa " . ($i + 1) . "/3 falhou: " . $e->getMessage());
            }
        }
        throw new RuntimeException(
            "DbConnection: não conectou após " . self::CONNECT_RETRIES . " tentativas. Último erro: " .
            ($ultimoErro ? $ultimoErro->getMessage() : '?')
        );
    }

    /**
     * Override pra testes — inject PDO custom (ex: SQLite ':memory:').
     */
    public static function setTestPdo(PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$isTest = true;
    }

    public static function isTest(): bool
    {
        return self::$isTest;
    }

    /**
     * Reset (pra testes). Limpa singleton — próxima chamada reconecta.
     */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$isTest = false;
    }

    /**
     * Helper de transaction com rollback automático em exception.
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        $alreadyInTx = $pdo->inTransaction();
        if (!$alreadyInTx) $pdo->beginTransaction();
        try {
            $r = $fn($pdo);
            if (!$alreadyInTx) $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if (!$alreadyInTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Execução de query com retry em deadlock (1213) ou lock timeout (1205).
     * Usar em writes que podem competir (cron paralelo).
     */
    public static function execComRetry(string $sql, array $bindings = [], int $maxRetries = 3): int
    {
        $pdo = self::pdo();
        $tentativas = 0;
        while (true) {
            try {
                $st = $pdo->prepare($sql);
                $st->execute($bindings);
                return $st->rowCount();
            } catch (PDOException $e) {
                $tentativas++;
                $code = (int)($e->errorInfo[1] ?? 0);
                $deadlock = ($code === 1213 || $code === 1205);
                if (!$deadlock || $tentativas >= $maxRetries) throw $e;
                usleep(($tentativas * 100) * 1000); // 100ms, 200ms, 300ms
            }
        }
    }
}
