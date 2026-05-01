<?php
/**
 * Persistência dos padrões de título (1-6) já usados recentemente por site.
 * Objetivo: evitar redundância entre artigos publicados em seguida (cluster, RSS batch ou single).
 *
 * Regras:
 * - Escopo por host do wp_url (cada site tem rotação própria).
 * - TTL: um padrão "esfria" após N horas sem uso.
 * - Janela: retorna últimos N padrões únicos, do mais recente pro mais antigo.
 */
class PadroesTitulo
{
    private string $dir;
    private int $ttlSeg;
    private int $janela;

    public function __construct(string $dir, int $ttlHoras = 24, int $janela = 5)
    {
        $this->dir = rtrim($dir, '/\\');
        $this->ttlSeg = max(1, $ttlHoras) * 3600;
        $this->janela = max(1, min(6, $janela));
        if (!is_dir($this->dir)) @mkdir($this->dir, 0777, true);
    }

    private function arquivo(string $wpUrl): string
    {
        $host = strtolower((string)parse_url($wpUrl, PHP_URL_HOST) ?: 'default');
        $host = preg_replace('/[^a-z0-9.-]/', '_', $host) ?? 'default';
        return $this->dir . '/' . $host . '.json';
    }

    /**
     * Retorna lista de padrões (1-6) usados dentro do TTL, sem duplicatas, mais recentes primeiro.
     * @return int[]
     */
    public function carregar(string $wpUrl): array
    {
        $f = $this->arquivo($wpUrl);
        if (!file_exists($f)) return [];
        $raw = @file_get_contents($f);
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) return [];

        $agora = time();
        $validos = [];
        foreach ($data as $e) {
            if (!isset($e['ts'], $e['p'])) continue;
            if (($agora - (int)$e['ts']) >= $this->ttlSeg) continue;
            $validos[] = $e;
        }
        usort($validos, fn($a, $b) => (int)$b['ts'] - (int)$a['ts']);

        $out = [];
        foreach ($validos as $e) {
            $p = (int)$e['p'];
            if ($p >= 1 && $p <= 6 && !in_array($p, $out, true)) $out[] = $p;
            if (count($out) >= $this->janela) break;
        }
        return $out;
    }

    /**
     * Retorna os últimos N títulos publicados (dentro do TTL), do mais recente pro mais antigo.
     * @return string[]
     */
    public function carregarTitulos(string $wpUrl, int $quantos = 5): array
    {
        $f = $this->arquivo($wpUrl);
        if (!file_exists($f)) return [];
        $raw = @file_get_contents($f);
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) return [];

        $agora = time();
        $validos = [];
        foreach ($data as $e) {
            if (!isset($e['ts'])) continue;
            if (($agora - (int)$e['ts']) >= $this->ttlSeg) continue;
            $t = trim((string)($e['titulo'] ?? ''));
            if ($t !== '') $validos[] = ['t' => $t, 'ts' => (int)$e['ts']];
        }
        usort($validos, fn($a, $b) => $b['ts'] - $a['ts']);
        return array_map(fn($e) => $e['t'], array_slice($validos, 0, max(1, $quantos)));
    }

    /** Registra padrão + título usado agora. Mantém apenas as últimas 20 entradas por arquivo. */
    public function registrar(string $wpUrl, int $padrao, string $titulo = ''): void
    {
        if ($padrao < 1 || $padrao > 6) return;
        $f = $this->arquivo($wpUrl);
        $data = [];
        if (file_exists($f)) {
            $raw = @file_get_contents($f);
            $tmp = json_decode((string)$raw, true);
            if (is_array($tmp)) $data = $tmp;
        }
        $entry = ['p' => $padrao, 'ts' => time()];
        $titulo = trim($titulo);
        if ($titulo !== '') $entry['titulo'] = mb_substr($titulo, 0, 200);
        $data[] = $entry;
        if (count($data) > 20) $data = array_slice($data, -20);
        @file_put_contents($f, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }
}
