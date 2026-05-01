<?php
/**
 * Fake DB — simula a tabela wp_discover_trends em JSON.
 * Schema idêntico ao portal.md → migração futura pra MySQL é 1:1.
 *
 * Storage: data/discover_trends.json
 *
 * Uso:
 *   $db = new DiscoverDb();
 *   $db->upsert($registro);
 *   $db->all(['status' => 'aprovado', 'site' => 'comocomprar']);
 *   $db->get($id);
 *   $db->updateStatus($id, 'publicado', ['url_post' => '...']);
 */
class DiscoverDb
{
    /**
     * Janela default de carregamento (dias). Records mais antigos que isso E em status
     * terminal (publicado/rejeitado/expirado) NÃO são carregados na memória.
     * Pra ler histórico completo, instanciar com `$janelaDiasLoad=0` (carrega tudo).
     */
    public const JANELA_DIAS_DEFAULT = 60;

    /**
     * Status "ativos" — SEMPRE carregam, ignorando janela. Pipeline em andamento não
     * pode ser cortado por TTL.
     */
    private const STATUS_ATIVOS = [
        'novo', 'aprovado', 'processando', 'gerando', 'revisando', 'aguardando_llm',
    ];

    /**
     * Status "terminais" — só carregam dentro da janela. Records publicados/rejeitados
     * já cumpriram seu papel; só relevantes pra dedupe e relatório.
     */
    private const STATUS_TERMINAIS = [
        'publicado', 'rejeitado', 'rejeitado_lint', 'expirado', 'duplicado_alto',
    ];

    private string $file;
    private string $archiveDir;
    private array $data = ['next_id' => 1, 'records' => []];
    private int $janelaDiasLoad;

    /** Driver MySQL (não-null se DB_DRIVER=mysql). Quando setado, todos os métodos públicos delegam. */
    private ?DiscoverDbMysql $mysqlDriver = null;

    /**
     * @param string|null $file caminho do JSON (legacy/json driver)
     * @param int  $janelaDiasLoad janela de carregamento em memória (default 60d)
     * @param string|null $forceDriver 'json'|'mysql'|null (null = lê DB_DRIVER do env, default 'json')
     */
    public function __construct(?string $file = null, int $janelaDiasLoad = self::JANELA_DIAS_DEFAULT, ?string $forceDriver = null)
    {
        // Decide driver: param explícito > env > default 'json'
        $driver = $forceDriver;
        if ($driver === null) {
            require_once __DIR__ . '/Env.php';
            @Env::load(__DIR__ . '/../.env');
            $driver = strtolower((string)Env::get('DB_DRIVER', 'json'));
        }

        if ($driver === 'mysql') {
            require_once __DIR__ . '/DiscoverDbMysql.php';
            $this->mysqlDriver = new DiscoverDbMysql();
            // Campos JSON ainda inicializados (defesa caso código legacy acesse)
            $this->file = '';
            $this->archiveDir = '';
            $this->janelaDiasLoad = 0;
            return;
        }

        // Driver JSON (default — preserva comportamento atual)
        $this->file = $file ?: __DIR__ . '/../data/discover_trends.json';
        $dir = dirname($this->file);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $this->archiveDir = $dir . '/discover_trends_archive';
        $this->janelaDiasLoad = max(0, $janelaDiasLoad);
        $this->load();
    }

    /** Indica se está rodando em MySQL. Útil pra callers que querem otimizar. */
    public function isMysql(): bool
    {
        return $this->mysqlDriver !== null;
    }

    private function load(): void
    {
        if (!is_file($this->file)) return;
        // Read tolera corrupção: tenta backup mais recente automaticamente se JSON inválido.
        require_once __DIR__ . '/JsonStore.php';
        $d = JsonStore::read($this->file, null);
        if (!is_array($d) || !isset($d['records'])) return;

        // Filtro de janela: terminais com data_detectada > janela são FILTRADOS na carga
        // (continuam no disco — ficam disponíveis pra arquivamento no próximo cron).
        // Status ativos sempre carregam.
        if ($this->janelaDiasLoad > 0) {
            $cutoff = strtotime("-{$this->janelaDiasLoad} days");
            $filtered = [];
            foreach ($d['records'] as $r) {
                $st = (string)($r['status'] ?? 'novo');
                if (in_array($st, self::STATUS_ATIVOS, true)) {
                    $filtered[] = $r;
                    continue;
                }
                $detectado = strtotime((string)($r['data_detectada'] ?? '')) ?: 0;
                if ($detectado === 0 || $detectado >= $cutoff) {
                    $filtered[] = $r;
                }
                // else: terminal antigo — não carrega (mas continua em disco)
            }
            $d['records'] = $filtered;
        }
        $this->data = $d;
    }

    private function persist(): void
    {
        // Atomic write + backup rotativo (5 versões). Crítico: este JSON é a fonte de
        // verdade do pipeline (trends/posts/status). Corrupção = perda de histórico.
        //
        // IMPORTANTE: persist() escreve $this->data (que pode ter sido FILTRADO no load).
        // Pra evitar perda de records terminais antigos quando janela é aplicada, fazemos
        // MERGE com o disco: lê arquivo atual, sobrescreve apenas os IDs em $this->data,
        // preserva o resto. Cuidadoso pra produção: quem quer realmente apagar terminais
        // antigos deve usar `arquivarTerminais()` explicitamente (cron mensal).
        require_once __DIR__ . '/JsonStore.php';

        if ($this->janelaDiasLoad > 0 && is_file($this->file)) {
            $diskRaw = JsonStore::read($this->file, null);
            if (is_array($diskRaw) && isset($diskRaw['records'])) {
                $idsEmMem = [];
                foreach ($this->data['records'] as $r) {
                    if (isset($r['id'])) $idsEmMem[(int)$r['id']] = true;
                }
                // Pega registros do disco que NÃO estão em memória (terminais antigos)
                $preservados = [];
                foreach ($diskRaw['records'] as $r) {
                    $rid = (int)($r['id'] ?? 0);
                    if ($rid > 0 && !isset($idsEmMem[$rid])) {
                        $preservados[] = $r;
                    }
                }
                $merged = [
                    'next_id' => max((int)($this->data['next_id'] ?? 1), (int)($diskRaw['next_id'] ?? 1)),
                    'records' => array_merge($this->data['records'], $preservados),
                ];
                JsonStore::write($this->file, $merged, JsonStore::KEEP_BACKUPS_DEFAULT, true);
                return;
            }
        }

        JsonStore::write($this->file, $this->data, JsonStore::KEEP_BACKUPS_DEFAULT, true);
    }

    /**
     * Arquiva records terminais com data_detectada >= $cutoffMonths atrás. Move pra
     * `data/discover_trends_archive/{YYYY-MM}.json` agrupado por mês de detecção.
     * Reduz tamanho do arquivo principal sem perder histórico.
     *
     * Uso (cron mensal):
     *   $db = new DiscoverDb(null, 0);  // janela=0 = carrega tudo
     *   $r = $db->arquivarTerminais(6); // arquiva o que tem >6 meses
     *
     * @param int $cutoffMonths idade mínima em meses pra arquivar (default 6)
     * @return array {arquivados, particoes_criadas, bytes_principais_antes, bytes_principais_depois}
     */
    public function arquivarTerminais(int $cutoffMonths = 6): array
    {
        if ($this->mysqlDriver) return $this->mysqlDriver->arquivarTerminais($cutoffMonths);
        require_once __DIR__ . '/JsonStore.php';
        $cutoff = strtotime("-{$cutoffMonths} months");
        if (!is_dir($this->archiveDir)) @mkdir($this->archiveDir, 0777, true);

        $bytesAntes = is_file($this->file) ? @filesize($this->file) : 0;
        $manter = [];
        $arquivar = []; // mes => [records...]

        foreach ($this->data['records'] as $r) {
            $st = (string)($r['status'] ?? 'novo');
            if (in_array($st, self::STATUS_ATIVOS, true)) {
                $manter[] = $r;
                continue;
            }
            $detectado = strtotime((string)($r['data_detectada'] ?? '')) ?: 0;
            if ($detectado === 0 || $detectado >= $cutoff) {
                $manter[] = $r;
                continue;
            }
            $mes = date('Y-m', $detectado);
            $arquivar[$mes] ??= [];
            $arquivar[$mes][] = $r;
        }

        // Append em cada partição mensal (preserva conteúdo prévio)
        $particoes = 0;
        $arquivados = 0;
        foreach ($arquivar as $mes => $recs) {
            $path = $this->archiveDir . '/' . $mes . '.json';
            $existente = JsonStore::read($path, ['records' => []]);
            $existente['records'] = array_merge($existente['records'] ?? [], $recs);
            JsonStore::write($path, $existente, 2, true);
            $particoes++;
            $arquivados += count($recs);
        }

        if ($arquivados > 0) {
            $this->data['records'] = $manter;
            $this->persist();
        }

        $bytesDepois = is_file($this->file) ? @filesize($this->file) : 0;
        return [
            'arquivados'                  => $arquivados,
            'particoes_criadas'           => $particoes,
            'bytes_principais_antes'      => $bytesAntes,
            'bytes_principais_depois'     => $bytesDepois,
            'bytes_liberados_principais'  => $bytesAntes - $bytesDepois,
        ];
    }

    /**
     * Insere ou atualiza por (termo + site). Se já existe: atualiza volume, growth, timestamps, score.
     * @return int id do registro
     */
    public function upsert(array $row): int
    {
        if ($this->mysqlDriver) return $this->mysqlDriver->upsert($row);
        $site  = (string)($row['site'] ?? '');
        $termo = trim((string)($row['termo'] ?? ''));
        if ($termo === '') throw new InvalidArgumentException('termo vazio');

        $chaveKey = $site . '::' . mb_strtolower($termo, 'UTF-8');

        // Busca existente
        foreach ($this->data['records'] as $i => $r) {
            $rk = ($r['site'] ?? '') . '::' . mb_strtolower((string)($r['termo'] ?? ''), 'UTF-8');
            if ($rk === $chaveKey) {
                // update
                $merged = array_merge($r, $row);
                $merged['id'] = $r['id'];
                $merged['data_detectada'] = $r['data_detectada'] ?? date('Y-m-d H:i:s');
                $merged['ultimo_update']  = date('Y-m-d H:i:s');
                $this->data['records'][$i] = $merged;
                $this->persist();
                return (int)$r['id'];
            }
        }

        // insert
        $id = (int)$this->data['next_id'];
        $this->data['next_id'] = $id + 1;

        $new = array_merge([
            'id'              => $id,
            'site'            => $site,
            'termo'           => $termo,
            'categoria'       => '',
            'categoria_ids'   => [],
            'volume_busca'    => 0,
            'volume_label'    => '',
            'growth_pct'      => 0,
            'data_detectada'  => date('Y-m-d H:i:s'),
            'origem'          => '168h',
            'status'          => 'novo',
            'score_discover'  => 0.0,
            'score_detalhado' => [],
            'intencao'        => '',
            'angulo'          => '',
            'titulo'          => null,
            'url_post'        => null,
            'publicado_em'    => null,
            'ultimo_update'   => date('Y-m-d H:i:s'),
            'ativo'           => 1,
            'noticias_qtd'    => 0,
            'relacionados'    => [],
        ], $row);
        $new['id'] = $id;
        $this->data['records'][] = $new;
        $this->persist();
        return $id;
    }

    /** Upsert em lote com 1 persist no final. */
    public function upsertMany(array $rows): array
    {
        if ($this->mysqlDriver) return $this->mysqlDriver->upsertMany($rows);
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $this->upsertNoPersist($row);
        }
        $this->persist();
        return $ids;
    }

    private function upsertNoPersist(array $row): int
    {
        $site  = (string)($row['site'] ?? '');
        $termo = trim((string)($row['termo'] ?? ''));
        if ($termo === '') return 0;
        $chaveKey = $site . '::' . mb_strtolower($termo, 'UTF-8');
        foreach ($this->data['records'] as $i => $r) {
            $rk = ($r['site'] ?? '') . '::' . mb_strtolower((string)($r['termo'] ?? ''), 'UTF-8');
            if ($rk === $chaveKey) {
                $merged = array_merge($r, $row);
                $merged['id'] = $r['id'];
                $merged['data_detectada'] = $r['data_detectada'] ?? date('Y-m-d H:i:s');
                $merged['ultimo_update']  = date('Y-m-d H:i:s');
                $this->data['records'][$i] = $merged;
                return (int)$r['id'];
            }
        }
        $id = (int)$this->data['next_id'];
        $this->data['next_id'] = $id + 1;
        $new = array_merge([
            'id' => $id, 'site' => $site, 'termo' => $termo,
            'data_detectada' => date('Y-m-d H:i:s'),
            'ultimo_update'  => date('Y-m-d H:i:s'),
            'status' => 'novo', 'ativo' => 1,
        ], $row);
        $new['id'] = $id;
        $this->data['records'][] = $new;
        return $id;
    }

    public function get(int $id): ?array
    {
        if ($this->mysqlDriver) return $this->mysqlDriver->get($id);
        foreach ($this->data['records'] as $r) {
            if ((int)$r['id'] === $id) return $r;
        }
        return null;
    }

    /**
     * Lista com filtros opcionais. Aceita o mesmo conjunto de filtros do MySQL driver:
     *   status, site, origem, cluster_key, score_min, publicado_apos, data_apos,
     *   post_id_not_null, order_by ('id_asc'|'id_desc'|'score_desc'|'publicado_desc'|'data_desc'),
     *   limit, offset.
     *
     * Em modo JSON tudo roda em memória, mas API igual permite que callers escrevam
     * uma query só e funcione em ambos os drivers.
     */
    public function all(array $filters = []): array
    {
        if ($this->mysqlDriver) return $this->mysqlDriver->all($filters);
        $out = $this->data['records'];
        if (isset($filters['status'])) {
            $out = array_filter($out, fn($r) => ($r['status'] ?? '') === $filters['status']);
        }
        if (isset($filters['site'])) {
            $out = array_filter($out, fn($r) => ($r['site'] ?? '') === $filters['site']);
        }
        if (isset($filters['origem'])) {
            $out = array_filter($out, fn($r) => ($r['origem'] ?? '') === $filters['origem']);
        }
        if (isset($filters['cluster_key'])) {
            $ck = (string)$filters['cluster_key'];
            $out = array_filter($out, fn($r) => (string)($r['cluster_detect']['key'] ?? $r['cluster_key'] ?? '') === $ck);
        }
        if (isset($filters['score_min'])) {
            $out = array_filter($out, fn($r) => (float)($r['score_discover'] ?? 0) >= (float)$filters['score_min']);
        }
        if (isset($filters['publicado_apos'])) {
            $cut = self::tsFilter($filters['publicado_apos']);
            $out = array_filter($out, function ($r) use ($cut) {
                $t = strtotime((string)($r['publicado_em'] ?? '')) ?: 0;
                return $t >= $cut;
            });
        }
        if (isset($filters['data_apos'])) {
            $cut = self::tsFilter($filters['data_apos']);
            $out = array_filter($out, function ($r) use ($cut) {
                $t = strtotime((string)($r['data_detectada'] ?? '')) ?: 0;
                return $t >= $cut;
            });
        }
        if (!empty($filters['post_id_not_null'])) {
            $out = array_filter($out, fn($r) => !empty($r['post_id']));
        }
        $out = array_values($out);

        $orderBy = $filters['order_by'] ?? 'id_asc';
        switch ($orderBy) {
            case 'id_desc':
                usort($out, fn($a, $b) => ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0))); break;
            case 'score_desc':
                usort($out, function ($a, $b) {
                    $s = ((float)($b['score_discover'] ?? 0)) <=> ((float)($a['score_discover'] ?? 0));
                    return $s !== 0 ? $s : ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
                }); break;
            case 'publicado_desc':
                usort($out, fn($a, $b) => (strtotime((string)($b['publicado_em'] ?? '')) ?: 0) <=> (strtotime((string)($a['publicado_em'] ?? '')) ?: 0)); break;
            case 'data_desc':
                usort($out, fn($a, $b) => (strtotime((string)($b['data_detectada'] ?? '')) ?: 0) <=> (strtotime((string)($a['data_detectada'] ?? '')) ?: 0)); break;
            // 'id_asc' é o default (records já vêm ordenados por id)
        }

        if (isset($filters['offset'])) $out = array_slice($out, (int)$filters['offset']);
        if (isset($filters['limit']))  $out = array_slice($out, 0, (int)$filters['limit']);
        return $out;
    }

    /** Normaliza valor de filtro datetime pra unix timestamp. */
    private static function tsFilter($v): int
    {
        if (is_int($v)) return $v;
        if ($v instanceof DateTimeInterface) return $v->getTimestamp();
        $s = trim((string)$v);
        if ($s === '') return 0;
        $ts = strtotime($s);
        return $ts ?: 0;
    }

    public function count(array $filters = []): int
    {
        if ($this->mysqlDriver) return $this->mysqlDriver->count($filters);
        return count($this->all($filters));
    }

    public function updateStatus(int $id, string $status, array $extra = []): bool
    {
        if ($this->mysqlDriver) return $this->mysqlDriver->updateStatus($id, $status, $extra);
        foreach ($this->data['records'] as $i => $r) {
            if ((int)$r['id'] === $id) {
                $this->data['records'][$i]['status']        = $status;
                $this->data['records'][$i]['ultimo_update'] = date('Y-m-d H:i:s');
                foreach ($extra as $k => $v) $this->data['records'][$i][$k] = $v;
                $this->persist();
                return true;
            }
        }
        return false;
    }

    public function delete(int $id): bool
    {
        if ($this->mysqlDriver) return $this->mysqlDriver->delete($id);
        foreach ($this->data['records'] as $i => $r) {
            if ((int)$r['id'] === $id) {
                array_splice($this->data['records'], $i, 1);
                $this->persist();
                return true;
            }
        }
        return false;
    }

    public function truncate(): void
    {
        if ($this->mysqlDriver) { $this->mysqlDriver->truncate(); return; }
        $this->data = ['next_id' => 1, 'records' => []];
        $this->persist();
    }

    /**
     * Move registros entre sites (tipicamente quando o usuário salvou no site errado).
     * Filtra por evento_fonte se fornecido. Não move se houver colisão (termo já existe no destino).
     * @return array ['movidos' => int, 'colisoes' => int, 'detalhes' => [...]]
     */
    public function migrarSite(string $fromSite, string $toSite, ?string $eventoFonte = null): array
    {
        if ($this->mysqlDriver) return $this->mysqlDriver->migrarSite($fromSite, $toSite, $eventoFonte);
        if ($fromSite === $toSite) return ['movidos' => 0, 'colisoes' => 0];
        $movidos = 0; $colisoes = 0; $detalhes = [];

        // Index de termos no destino pra detectar colisão rapidamente
        $destTermos = [];
        foreach ($this->data['records'] as $r) {
            if (($r['site'] ?? '') === $toSite) {
                $destTermos[mb_strtolower((string)($r['termo'] ?? ''), 'UTF-8')] = true;
            }
        }

        foreach ($this->data['records'] as &$r) {
            if (($r['site'] ?? '') !== $fromSite) continue;
            if ($eventoFonte !== null && ($r['evento_fonte'] ?? '') !== $eventoFonte) continue;
            $termoLow = mb_strtolower((string)($r['termo'] ?? ''), 'UTF-8');
            if (isset($destTermos[$termoLow])) {
                $colisoes++;
                $detalhes[] = ['id' => $r['id'], 'termo' => $r['termo'], 'erro' => 'colisao no destino'];
                continue;
            }
            $r['site'] = $toSite;
            $r['ultimo_update'] = date('Y-m-d H:i:s');
            $destTermos[$termoLow] = true;
            $movidos++;
            $detalhes[] = ['id' => $r['id'], 'termo' => $r['termo'], 'movido' => true];
        }
        unset($r);
        if ($movidos > 0) $this->persist();

        return ['movidos' => $movidos, 'colisoes' => $colisoes, 'detalhes' => $detalhes];
    }
}
