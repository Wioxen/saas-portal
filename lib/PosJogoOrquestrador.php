<?php
declare(strict_types=1);

require_once __DIR__ . '/JogosCalendario.php';
require_once __DIR__ . '/Serper.php';
require_once __DIR__ . '/SourceTrustScore.php';

/**
 * PosJogoOrquestrador — disparado pelo cron a cada 5 min.
 *
 * Lê JogosCalendario, detecta janela atual (pre-jogo / pos-jogo / live), checa
 * se já foi gerado post pra esse jogo+tipo (idempotência via posts_gerados no
 * JSON), e dispara o gerador apropriado:
 *
 *   pre-jogo (T-3h até T):  gerar_noticia.php "onde assistir + escalação"
 *   live     (HT, T até T+2h):  TODO — não implementado nesta versão
 *   pos-jogo (T+2h até T+4h):  gerar_pos_jogo.php
 *   repercussao (separado):    TODO — coletiva técnico
 *
 * Lock file evita double-fire se cron rodar 2x antes do anterior terminar.
 */
class PosJogoOrquestrador
{
    private string $jsonPath;
    private string $siteSlug;
    private array $cfg;
    private string $lockPath;
    private string $scriptsDir;
    private bool $verbose;

    public function __construct(string $jsonPath, string $siteSlug, array $cfg, bool $verbose = false)
    {
        $this->jsonPath = $jsonPath;
        $this->siteSlug = $siteSlug;
        $this->cfg = $cfg;
        $this->verbose = $verbose;
        $this->lockPath = sys_get_temp_dir() . "/pos_jogo_auto_{$siteSlug}.lock";
        $this->scriptsDir = realpath(__DIR__ . '/../scripts') ?: (__DIR__ . '/../scripts');
    }

    /** Executa 1 ciclo. Retorna {action, post_id?, jogo_id?, motivo?}. */
    public function executar(): array
    {
        // Lock pra prevenir double-fire
        $fp = @fopen($this->lockPath, 'c');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            return ['action' => 'skip', 'motivo' => 'lock_held (outra execução em andamento)'];
        }
        try {
            return $this->run();
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    private function run(): array
    {
        $cal = new JogosCalendario($this->jsonPath);
        $janela = $cal->janelaAtual();

        if ($janela === null) {
            return ['action' => 'skip', 'motivo' => 'fora_de_janela'];
        }

        $jogo = $janela['jogo'];
        $tipoJanela = $janela['tipo']; // 'pre-jogo' | 'live' | 'pos-jogo'
        $tipoPost = $this->tipoPostParaJanela($tipoJanela);

        if ($tipoPost === null) {
            return ['action' => 'skip', 'motivo' => "tipo_janela_{$tipoJanela}_nao_implementado", 'jogo_id' => $jogo['id']];
        }

        // Idempotência: já gerou esse tipo pra esse jogo?
        $jaGerado = $jogo['posts_gerados'][$tipoPost] ?? null;
        if (!empty($jaGerado)) {
            return ['action' => 'skip', 'motivo' => 'ja_gerado', 'jogo_id' => $jogo['id'], 'tipo' => $tipoPost, 'post_id' => $jaGerado];
        }

        $this->log("janela={$tipoJanela} jogo={$jogo['id']} tipo_post={$tipoPost}");

        // Coleta fontes via Serper
        $urls = $this->buscarFontes($jogo, $tipoPost);
        if (count($urls) < 2) {
            return ['action' => 'skip', 'motivo' => 'fontes_insuficientes', 'jogo_id' => $jogo['id'], 'urls_encontradas' => count($urls)];
        }

        // Dispara gerador
        $resultado = match ($tipoPost) {
            'pre_jogo'  => $this->gerarPreJogo($jogo, $urls),
            'pos_jogo'  => $this->gerarPosJogo($jogo, $urls),
            default     => ['ok' => false, 'erro' => "tipo {$tipoPost} sem handler"],
        };

        if (!empty($resultado['post_id'])) {
            $this->marcarGerado($jogo['id'], $tipoPost, (int)$resultado['post_id']);
            return ['action' => 'gerado', 'jogo_id' => $jogo['id'], 'tipo' => $tipoPost, 'post_id' => $resultado['post_id'], 'fontes_usadas' => count($urls)];
        }

        return ['action' => 'erro', 'jogo_id' => $jogo['id'], 'tipo' => $tipoPost, 'detalhe' => $resultado];
    }

    /** Mapa tipo de janela → tipo de post. Janelas extras (ex: live) podem retornar null. */
    private function tipoPostParaJanela(string $tipoJanela): ?string
    {
        return match ($tipoJanela) {
            'pre-jogo' => 'pre_jogo',
            'pos-jogo' => 'pos_jogo',
            'live'     => null,  // TODO: HT post (precisa scrape ao vivo)
            default    => null,
        };
    }

    /** Busca URLs de fontes via Serper, ordenadas por SourceTrustScore. */
    private function buscarFontes(array $jogo, string $tipoPost): array
    {
        $advNome = $jogo['adversario']['nome'];
        $query = match ($tipoPost) {
            'pre_jogo' => "Vitória x {$advNome} onde assistir escalação " . ($jogo['competicao'] ?? ''),
            'pos_jogo' => "Vitória {$jogo['placar']['vitoria']} x {$jogo['placar']['adversario']} {$advNome}",
            default    => "Vitória {$advNome}",
        };
        $this->log("serper query: {$query}");

        $serper = new Serper($this->cfg['serper_api_key']);
        try {
            $resp = $serper->search($query, 12);
        } catch (Throwable $e) {
            $this->log("serper falhou: " . $e->getMessage());
            return [];
        }

        $urls = [];
        foreach (($resp['organic'] ?? []) as $r) {
            $u = (string)($r['link'] ?? '');
            if ($u !== '') $urls[] = ['url' => $u];
        }
        $urls = SourceTrustScore::ordenarPorTier($urls);

        // Pega top 4 (Tier S/A primeiro), filtra exclusivamente fontes brasileiras de esporte
        $top = array_slice(array_column($urls, 'url'), 0, 4);
        return $top;
    }

    private function gerarPreJogo(array $jogo, array $urls): array
    {
        $advNome = $jogo['adversario']['nome'];
        $competicao = $jogo['competicao'] ?? '';
        $titulo = "Vitória x {$advNome}: onde assistir, horário e escalação ({$competicao})";

        $cmd = sprintf(
            'php %s --site=%s --urls=%s --titulo-hint=%s 2>&1',
            escapeshellarg($this->scriptsDir . '/gerar_noticia.php'),
            escapeshellarg($this->siteSlug),
            escapeshellarg(implode('|', $urls)),  // pipe pra preservar URLs com vírgula
            escapeshellarg($titulo)
        );
        return $this->executarShellEParsearPostId($cmd);
    }

    private function gerarPosJogo(array $jogo, array $urls): array
    {
        $cmd = sprintf(
            'php %s --site=%s --jogo-id=%s --urls=%s 2>&1',
            escapeshellarg($this->scriptsDir . '/gerar_pos_jogo.php'),
            escapeshellarg($this->siteSlug),
            escapeshellarg($jogo['id']),
            escapeshellarg(implode(',', $urls))
        );
        return $this->executarShellEParsearPostId($cmd);
    }

    /** Executa comando + extrai "POST CRIADO id=N" do output. */
    private function executarShellEParsearPostId(string $cmd): array
    {
        $this->log("exec: " . $cmd);
        $output = (string)shell_exec($cmd);
        $this->log("output (head): " . substr($output, 0, 400));

        if (preg_match('/POST CRIADO id=(\d+)/', $output, $m)) {
            return ['ok' => true, 'post_id' => (int)$m[1], 'output' => $output];
        }
        return ['ok' => false, 'output' => $output];
    }

    /** Atualiza posts_gerados[tipo] = post_id no JSON do jogo. */
    private function marcarGerado(string $jogoId, string $tipo, int $postId): void
    {
        $dados = json_decode((string)file_get_contents($this->jsonPath), true) ?: [];
        $jogos = (array)($dados['jogos'] ?? []);
        foreach ($jogos as $i => $j) {
            if (($j['id'] ?? '') === $jogoId) {
                if (!isset($jogos[$i]['posts_gerados']) || !is_array($jogos[$i]['posts_gerados'])) {
                    $jogos[$i]['posts_gerados'] = ['pre_jogo' => null, 'pos_jogo' => null, 'repercussao' => null];
                }
                $jogos[$i]['posts_gerados'][$tipo] = $postId;
                $dados['jogos'] = $jogos;
                file_put_contents(
                    $this->jsonPath,
                    json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
                );
                return;
            }
        }
    }

    private function log(string $msg): void
    {
        if ($this->verbose) echo "[" . date('H:i:s') . "] {$msg}\n";
    }
}
