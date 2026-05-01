<?php
/**
 * Env — parser mínimo de .env (sem dependência externa).
 *
 * Uso:
 *   Env::load(__DIR__ . '/../.env');
 *   $key = Env::get('OPENAI_API_KEY', 'default_opcional');
 *
 * Formato aceito em .env:
 *   # comentário
 *   CHAVE=valor
 *   CHAVE="valor com espaço"
 *   CHAVE='valor literal'
 *
 * Linhas em branco e # são ignoradas. Aspas são removidas. \n, \t são interpretadas.
 * Idempotente: chamar load() duas vezes não sobrescreve vars já definidas.
 */
class Env
{
    private static bool $carregado = false;
    private static array $vars = [];

    /** Carrega .env se existir. Silencioso se arquivo ausente (usa defaults/getenv). */
    public static function load(string $path): void
    {
        if (self::$carregado) return;
        self::$carregado = true;

        if (!is_file($path) || !is_readable($path)) return;

        $linhas = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '' || $linha[0] === '#') continue;
            if (!str_contains($linha, '=')) continue;

            [$k, $v] = explode('=', $linha, 2);
            $k = trim($k);
            $v = trim($v);

            // Remove aspas externas matching
            if (strlen($v) >= 2) {
                $first = $v[0]; $last = $v[strlen($v) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $v = substr($v, 1, -1);
                    if ($first === '"') {
                        $v = str_replace(['\\n', '\\t', '\\r', '\\\\'], ["\n", "\t", "\r", '\\'], $v);
                    }
                }
            }

            self::$vars[$k] = $v;
            // Também popula $_ENV / getenv() para libs que leem de lá
            if (getenv($k) === false) {
                putenv("{$k}={$v}");
                $_ENV[$k] = $v;
            }
        }
    }

    /** Lê var, priorizando .env > getenv > default. */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$vars)) return self::$vars[$key];
        $env = getenv($key);
        if ($env !== false) return $env;
        return $default;
    }

    /** Lê var obrigatória — lança exceção se ausente. */
    public static function required(string $key): string
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            throw new RuntimeException("Variável de ambiente obrigatória ausente: {$key}. Verifique o arquivo .env.");
        }
        return (string)$v;
    }

    /** Para debug — lista chaves carregadas (sem expor valores). */
    public static function chavesCarregadas(): array
    {
        return array_keys(self::$vars);
    }
}
