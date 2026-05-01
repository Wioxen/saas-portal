<?php
/**
 * Fila de geração em lote — per-tick AJAX.
 *
 * Arquivo: data/fila/<site>.json
 *
 * Fluxo:
 *  1. UI chama fila_iniciar com lista de trend_ids → cria arquivo da fila
 *  2. UI chama fila_tick em loop → cada chamada pega o próximo pendente, processa, persiste
 *  3. UI chama fila_status periodicamente pra renderizar progresso
 *
 * Lockfile evita concorrência (2 abas rodando tick ao mesmo tempo).
 */
class DiscoverFila
{
    private string $site;
    private string $file;
    private string $lockFile;
    private array  $state = ['items' => [], 'total' => 0];

    public function __construct(string $site)
    {
        $this->site     = $site;
        $dir            = __DIR__ . '/../data/fila';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $this->file     = $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $site) . '.json';
        $this->lockFile = $this->file . '.lock';
        $this->load();
    }

    private function load(): void
    {
        if (!is_file($this->file)) return;
        // JsonStore faz auto-recovery se JSON corrompido (lê backup mais recente)
        require_once __DIR__ . '/JsonStore.php';
        $d = JsonStore::read($this->file, null);
        if (is_array($d) && isset($d['items'])) $this->state = $d;
    }

    private function persist(): void
    {
        // Atomic write + backup rotativo. Fila é crítica: se trava no meio da escrita,
        // batch fica preso (status running pra sempre, "fila fantasma" sem como destravar).
        require_once __DIR__ . '/JsonStore.php';
        JsonStore::write($this->file, $this->state, JsonStore::KEEP_BACKUPS_DEFAULT, true);
    }

    /** Cria nova fila — sobrescreve qualquer existente. */
    public function criar(array $trends, string $formato = 'discover'): array
    {
        $items = [];
        $i = 1;
        foreach ($trends as $t) {
            if (empty($t['termo'])) continue;
            $items[] = [
                'id'            => $i++,
                'trend_id'      => (int)($t['id'] ?? 0),
                'termo'         => (string)$t['termo'],
                'score'         => (float)($t['score_discover'] ?? 0),
                'status'        => 'pending',
                'post_id'       => null,
                'titulo'        => null,
                'edit_url'      => null,
                'erro'          => null,
                'auditoria_ok'  => null,
                'chars_fontes'  => null,
                'started_at'    => null,
                'finished_at'   => null,
                'tempo_s'       => null,
            ];
        }

        $this->state = [
            'batch_id'   => 'batch-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)),
            'site'       => $this->site,
            'formato'    => $formato,
            'created_at' => date('Y-m-d H:i:s'),
            'total'      => count($items),
            'items'      => $items,
            'cancelado'  => false,
        ];
        $this->persist();
        return $this->state;
    }

    public function status(): array
    {
        $items = $this->state['items'] ?? [];
        $counts = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'canceled' => 0];
        foreach ($items as $it) {
            $s = $it['status'] ?? 'pending';
            if (!isset($counts[$s])) $counts[$s] = 0;
            $counts[$s]++;
        }
        return [
            'batch_id'   => $this->state['batch_id'] ?? null,
            'created_at' => $this->state['created_at'] ?? null,
            'formato'    => $this->state['formato'] ?? 'discover',
            'total'      => $this->state['total'] ?? 0,
            'counts'     => $counts,
            'items'      => $items,
            'cancelado'  => !empty($this->state['cancelado']),
            'existe'     => !empty($items),
        ];
    }

    /** Pega e marca como "running" o próximo pendente. Retorna null se não há. */
    public function proximoComLock(): ?array
    {
        // lock simples por arquivo
        $fp = @fopen($this->lockFile, 'c');
        if (!$fp) return null;
        if (!flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); return null; }

        try {
            $this->load(); // re-lê do disco (outra aba pode ter mudado)
            if (!empty($this->state['cancelado'])) return null;
            foreach ($this->state['items'] as &$it) {
                if (($it['status'] ?? 'pending') === 'pending') {
                    $it['status']     = 'running';
                    $it['started_at'] = date('Y-m-d H:i:s');
                    $this->persist();
                    return $it;
                }
            }
            return null;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function marcarResultado(int $id, array $resultado): void
    {
        $this->load();
        foreach ($this->state['items'] as &$it) {
            if ((int)$it['id'] !== $id) continue;
            $ok = !empty($resultado['ok']);
            $it['status']        = $ok ? 'done' : 'failed';
            $it['post_id']       = $resultado['post_id'] ?? null;
            $it['titulo']        = $resultado['titulo'] ?? null;
            $it['edit_url']      = $resultado['edit_url'] ?? null;
            $it['erro']          = $resultado['erro'] ?? null;
            $it['auditoria_ok']  = isset($resultado['auditoria']['ok']) ? (bool)$resultado['auditoria']['ok'] : null;
            $it['chars_fontes']  = $resultado['chars_fontes'] ?? null;
            $it['finished_at']   = date('Y-m-d H:i:s');
            if (!empty($it['started_at'])) {
                $it['tempo_s'] = strtotime($it['finished_at']) - strtotime($it['started_at']);
            }
            break;
        }
        unset($it);
        $this->persist();
    }

    public function cancelar(): void
    {
        $this->load();
        $this->state['cancelado'] = true;
        foreach ($this->state['items'] as &$it) {
            if (($it['status'] ?? '') === 'pending') $it['status'] = 'canceled';
        }
        unset($it);
        $this->persist();
    }

    public function limpar(): void
    {
        $this->state = ['items' => [], 'total' => 0];
        if (is_file($this->file)) @unlink($this->file);
    }
}
