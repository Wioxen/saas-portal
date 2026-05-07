<?php
declare(strict_types=1);

/**
 * UpdateDetector — detecta mudança de fato em fontes de posts publicados e dispara
 * refresh cirúrgico via Claude->atualizarPost. Onda 2 do llm-wiki.
 *
 * Pipeline (por candidato):
 *   1. Lista trends publicados com pingo_link e idade entre 7-30 dias
 *   2. Re-scrapeia URL fonte
 *   3. Extrai entidades-chave (datas, valores, números, status)
 *   4. Compara com baseline em data/update_detector/{site}_{trend_id}.json
 *   5. Se houver mudança crítica → flag pra refresh
 *   6. Atualiza baseline
 *
 * 1ª execução só cria baseline (sem refresh). 2ª+ detecta diff.
 */
class UpdateDetector
{
    private const CACHE_DIR = __DIR__ . '/../data/update_detector';
    private const PALAVRAS_STATUS = [
        'cancelado', 'cancelada', 'suspenso', 'suspensa',
        'prorrogado', 'prorrogada', 'adiado', 'adiada',
        'encerrado', 'encerrada', 'finalizado', 'finalizada',
        'reaberto', 'reaberta', 'retificado', 'retificada',
    ];

    private object $db;
    private object $scraper;
    private string $site;
    private array $log = [];

    public function __construct(object $db, object $scraper, string $site)
    {
        $this->db = $db;
        $this->scraper = $scraper;
        $this->site = $site;
        if (!is_dir(self::CACHE_DIR)) @mkdir(self::CACHE_DIR, 0775, true);
    }

    /**
     * Lista candidatos: posts publicados com pingo_link, idade 7-30d.
     */
    public function listarCandidatos(int $limite = 50, int $minIdadeDias = 7, int $maxIdadeDias = 30): array
    {
        $pdo = DbConnection::pdo();
        $sql = "SELECT id, post_id, pingo_link, titulo, termo, url_post, publicado_em
                FROM trends
                WHERE site = :s
                  AND status = 'publicado'
                  AND post_id IS NOT NULL
                  AND post_id > 0
                  AND pingo_link <> ''
                  AND publicado_em IS NOT NULL
                  AND publicado_em < (NOW() - INTERVAL :min DAY)
                  AND publicado_em > (NOW() - INTERVAL :max DAY)
                ORDER BY publicado_em DESC
                LIMIT :lim";
        $st = $pdo->prepare($sql);
        $st->bindValue(':s', $this->site);
        $st->bindValue(':min', $minIdadeDias, PDO::PARAM_INT);
        $st->bindValue(':max', $maxIdadeDias, PDO::PARAM_INT);
        $st->bindValue(':lim', $limite, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Re-scrapeia URL fonte e compara com baseline. Retorna:
     *   ['mudou' => bool, 'baseline_existia' => bool, 'diff' => array, 'scrape' => array, 'motivos' => array]
     * Se baseline não existia, mudou=false e cria baseline (1ª passada).
     */
    public function detectarMudancas(array $candidato): array
    {
        $trendId = (int)$candidato['id'];
        $url = (string)$candidato['pingo_link'];
        if ($url === '' || !preg_match('#^https?://#', $url)) {
            return ['mudou' => false, 'baseline_existia' => false, 'erro' => 'pingo_link inválido', 'scrape' => null, 'diff' => [], 'motivos' => []];
        }

        try {
            $scrape = $this->scraper->fetch($url);
        } catch (Throwable $e) {
            return ['mudou' => false, 'baseline_existia' => false, 'erro' => 'scrape falhou: ' . $e->getMessage(), 'scrape' => null, 'diff' => [], 'motivos' => []];
        }

        $textoNovo = $this->extrairTextoFlat($scrape);
        if (mb_strlen($textoNovo) < 300) {
            return ['mudou' => false, 'baseline_existia' => false, 'erro' => 'texto muito curto (fonte morreu?)', 'scrape' => null, 'diff' => [], 'motivos' => []];
        }

        $entidadesNovas = EntityExtractor::extract($textoNovo, $scrape['meta']['title'] ?? null);
        $cache = $this->carregarCache($trendId);

        if (empty($cache) || empty($cache['entidades'])) {
            $this->salvarCache($trendId, $candidato, $entidadesNovas, [
                'data' => date('c'),
                'acao' => 'baseline_criado',
            ]);
            return ['mudou' => false, 'baseline_existia' => false, 'scrape' => $scrape, 'diff' => [], 'motivos' => ['baseline criado pela 1ª vez']];
        }

        $diff = $this->compararEntidades($cache['entidades'], $entidadesNovas, $textoNovo);
        $mudou = !empty($diff['critico']);

        $this->salvarCache($trendId, $candidato, $entidadesNovas, [
            'data' => date('c'),
            'acao' => $mudou ? 'mudanca_detectada' : 'sem_mudanca',
            'diff' => $diff,
        ]);

        return [
            'mudou' => $mudou,
            'baseline_existia' => true,
            'scrape' => $scrape,
            'diff' => $diff,
            'motivos' => $diff['motivos'] ?? [],
        ];
    }

    /**
     * Compara entidades antigas vs novas. Retorna:
     *   ['critico' => [campos_que_mudaram], 'informativo' => [...], 'motivos' => [strs descritivas]]
     * Críticas: datas_prazos, valores_dinheiro, numeros_chave, status (palavras cancel/prorrog/etc)
     * Informativas: programas, cargos
     */
    private function compararEntidades(array $antigas, array $novas, string $textoNovo): array
    {
        $critico = [];
        $informativo = [];
        $motivos = [];

        $camposCriticos = ['datas_prazos', 'valores_dinheiro', 'numeros_chave'];
        $camposInformativos = ['programas_eventos', 'cargos_profissoes'];

        foreach ($camposCriticos as $campo) {
            $a = array_map('mb_strtolower', $antigas[$campo] ?? []);
            $n = array_map('mb_strtolower', $novas[$campo] ?? []);
            $adicionados = array_values(array_diff($n, $a));
            $removidos = array_values(array_diff($a, $n));
            if (!empty($adicionados) || !empty($removidos)) {
                $critico[$campo] = ['adicionados' => $adicionados, 'removidos' => $removidos];
                if (!empty($adicionados)) $motivos[] = "$campo: novos {" . implode(', ', array_slice($adicionados, 0, 3)) . "}";
                if (!empty($removidos)) $motivos[] = "$campo: removidos {" . implode(', ', array_slice($removidos, 0, 3)) . "}";
            }
        }

        foreach ($camposInformativos as $campo) {
            $a = array_map('mb_strtolower', $antigas[$campo] ?? []);
            $n = array_map('mb_strtolower', $novas[$campo] ?? []);
            $adicionados = array_values(array_diff($n, $a));
            if (!empty($adicionados)) {
                $informativo[$campo] = ['adicionados' => $adicionados];
            }
        }

        // Status: palavras de mudança que apareceram no texto novo mas não no antigo
        $statusNovos = $this->palavrasStatusEncontradas($textoNovo);
        $textoAntigo = $this->reconstruirTextoEntidades($antigas);
        $statusAntigos = $this->palavrasStatusEncontradas($textoAntigo);
        $statusAdic = array_values(array_diff($statusNovos, $statusAntigos));
        if (!empty($statusAdic)) {
            $critico['status'] = ['adicionados' => $statusAdic];
            $motivos[] = 'status: ' . implode(', ', $statusAdic);
        }

        return ['critico' => $critico, 'informativo' => $informativo, 'motivos' => $motivos];
    }

    private function palavrasStatusEncontradas(string $texto): array
    {
        $found = [];
        $lower = mb_strtolower($texto);
        foreach (self::PALAVRAS_STATUS as $p) {
            if (mb_strpos($lower, $p) !== false) $found[] = $p;
        }
        return array_values(array_unique($found));
    }

    private function reconstruirTextoEntidades(array $entidades): string
    {
        $partes = [];
        foreach ($entidades as $valores) {
            if (is_array($valores)) $partes = array_merge($partes, $valores);
        }
        return mb_strtolower(implode(' ', $partes));
    }

    private function extrairTextoFlat(array $scrape): string
    {
        $partes = [];
        $partes[] = (string)($scrape['meta']['title'] ?? '');
        $partes[] = (string)($scrape['meta']['description'] ?? '');
        foreach (($scrape['content']['paragraphs'] ?? []) as $p) $partes[] = (string)$p;
        foreach (($scrape['content']['headings'] ?? []) as $h) {
            if (is_array($h)) $partes[] = (string)($h['text'] ?? '');
            else $partes[] = (string)$h;
        }
        foreach (($scrape['content']['lists'] ?? []) as $l) {
            if (is_array($l)) {
                foreach (($l['items'] ?? []) as $i) $partes[] = (string)$i;
            }
        }
        return trim(implode("\n", array_filter($partes)));
    }

    private function carregarCache(int $trendId): array
    {
        $path = $this->cachePath($trendId);
        if (!file_exists($path)) return [];
        $raw = (string)file_get_contents($path);
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }

    private function salvarCache(int $trendId, array $candidato, array $entidades, array $eventoHistorico): void
    {
        $atual = $this->carregarCache($trendId);
        $historico = $atual['historico'] ?? [];
        $historico[] = $eventoHistorico;
        // Mantém só últimas 20 entradas
        if (count($historico) > 20) $historico = array_slice($historico, -20);

        $payload = [
            'trend_id' => $trendId,
            'post_id' => (int)($candidato['post_id'] ?? 0),
            'site' => $this->site,
            'pingo_link' => (string)($candidato['pingo_link'] ?? ''),
            'titulo' => (string)($candidato['titulo'] ?? $candidato['termo'] ?? ''),
            'url_post' => (string)($candidato['url_post'] ?? ''),
            'ultima_verificacao' => date('c'),
            'entidades' => $entidades,
            'ultimo_refresh' => $atual['ultimo_refresh'] ?? null,
            'historico' => $historico,
        ];
        file_put_contents($this->cachePath($trendId), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function marcarRefreshExecutado(int $trendId, array $info = []): void
    {
        $atual = $this->carregarCache($trendId);
        if (empty($atual)) return;
        $atual['ultimo_refresh'] = date('c');
        $atual['historico'][] = array_merge(['data' => date('c'), 'acao' => 'refresh_executado'], $info);
        file_put_contents($this->cachePath($trendId), json_encode($atual, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function cachePath(int $trendId): string
    {
        return self::CACHE_DIR . "/{$this->site}_{$trendId}.json";
    }

    public function log(string $msg): void { $this->log[] = $msg; }
    public function getLog(): array { return $this->log; }
}
