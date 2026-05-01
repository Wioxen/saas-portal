<?php
/**
 * Progresso granular da geração — arquivo JSON por trend_id que o UI polla.
 *
 * Uso:
 *   $p = new DiscoverProgress($trendId);
 *   $p->reportar(1, 'listando', 'Buscando artigos no Google News');
 *   $p->reportar(2, 'scraping', 'Scrape 2/5 — gov.br');
 *   ...
 *   $p->concluido();
 */
class DiscoverProgress
{
    public const STEPS = [
        'listando'         => ['idx' => 1, 'label' => '🔎 Listando artigos'],
        'scraping'         => ['idx' => 2, 'label' => '📥 Fazendo scrape das fontes'],
        'enriquecendo'     => ['idx' => 3, 'label' => '✨ Enrichment (JSON-LD, AMP, meta)'],
        'montando_prompt'  => ['idx' => 4, 'label' => '🧠 Montando prompt'],
        'chamando_llm'     => ['idx' => 5, 'label' => '🤖 Chamando LLM (aguarde)'],
        'parseando'        => ['idx' => 6, 'label' => '📝 Parseando resposta'],
        'publicando'       => ['idx' => 7, 'label' => '📤 Publicando no WordPress'],
        'pos_processing'   => ['idx' => 8, 'label' => '🔗 Pós-processamento (cards, schemas, cluster)'],
        'auditando'        => ['idx' => 9, 'label' => '🛡️ Auditoria anti-alucinação'],
        'concluido'        => ['idx' => 10, 'label' => '✅ Concluído'],
        'erro'             => ['idx' => 0, 'label' => '❌ Erro'],
    ];
    public const TOTAL = 10;

    private string $file;
    private int $startedAt;
    private int $trendId;

    public function __construct(int $trendId)
    {
        $this->trendId = $trendId;
        $dir = __DIR__ . '/../data/progress';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $this->file = $dir . '/trend_' . $trendId . '.json';
        $this->startedAt = (int)(microtime(true) * 1000);
        $this->reportar('listando', 'Iniciando...');
    }

    public function reportar(string $step, string $detail = ''): void
    {
        $meta = self::STEPS[$step] ?? ['idx' => 0, 'label' => $step];
        $now = (int)(microtime(true) * 1000);
        $data = [
            'trend_id'   => $this->trendId,
            'step'       => $step,
            'step_idx'   => $meta['idx'],
            'step_total' => self::TOTAL,
            'label'      => $meta['label'],
            'detail'     => $detail,
            'elapsed_ms' => $now - $this->startedAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        @file_put_contents($this->file, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function concluido(): void
    {
        $this->reportar('concluido', 'Post criado com sucesso');
        // Mantém o arquivo por 30s pro UI capturar o estado final, depois apaga
        // Usa shutdown function pra cleanup não bloqueante
        register_shutdown_function(function() {
            @file_put_contents($this->file . '.done', '1');
        });
    }

    public function erro(string $msg): void
    {
        $this->reportar('erro', $msg);
    }

    /** Lê o progresso de um trend (chamado pelo endpoint AJAX). */
    public static function ler(int $trendId): ?array
    {
        $file = __DIR__ . '/../data/progress/trend_' . $trendId . '.json';
        if (!is_file($file)) return null;
        // Cleanup de arquivos antigos (>1 hora): evita lixo acumular
        if (time() - filemtime($file) > 3600) {
            @unlink($file);
            @unlink($file . '.done');
            return null;
        }
        $d = json_decode((string)@file_get_contents($file), true);
        return is_array($d) ? $d : null;
    }

    public static function limparAntigos(int $horasLimite = 1): int
    {
        $dir = __DIR__ . '/../data/progress';
        if (!is_dir($dir)) return 0;
        $limite = time() - ($horasLimite * 3600);
        $n = 0;
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (filemtime($f) < $limite) {
                @unlink($f);
                @unlink($f . '.done');
                $n++;
            }
        }
        return $n;
    }
}
