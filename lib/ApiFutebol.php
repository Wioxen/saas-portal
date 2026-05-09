<?php
declare(strict_types=1);

/**
 * ApiFutebol — cliente PHP pra api-futebol.com.br (v1).
 *
 * Espelha os helpers que JÁ EXISTEM no tema leao-da-barra/inc/api-futebol.php
 * mas isolado pra usar nos scripts da pasta scripts/.
 *
 * Endpoints relevantes (todos GET, autenticados via Bearer):
 *   /campeonatos
 *   /campeonatos/{id}/tabela
 *   /campeonatos/{id}/rodadas[/{n}]
 *   /campeonatos/{id}/fases
 *   /campeonatos/{id}/artilharia
 *   /ao-vivo
 *   /partidas/{id}        ← chave pra pré/pós-jogo (escalação, eventos, transmissão)
 *   /times/{id}           ← perfil + escalação base do time
 *
 * Cache: arquivo local em /app/data/api_futebol_cache/{md5}.json (TTL configurável).
 * Pra economizar requests do plano free (100/dia), default cache 600s (10min) salvo
 * pra endpoints "ao-vivo" e "partidas/{id}" durante jogo (60s).
 *
 * Uso:
 *   $api = new ApiFutebol($cfg['api_futebol_key'] ?? null); // aceita null e tenta env API_FUTEBOL_KEY
 *   $partida = $api->getPartida(123456);
 *   $tabela  = $api->getTabela(10);
 *   $time    = $api->getTime(50);  // 50 = Vitória
 */
class ApiFutebol
{
    private const BASE = 'https://api.api-futebol.com.br/v1';
    private string $apiKey;
    private string $cacheDir;

    public function __construct(?string $apiKey = null, ?string $cacheDir = null)
    {
        $key = $apiKey ?: (string)getenv('API_FUTEBOL_KEY');
        if ($key === '') {
            throw new RuntimeException('API key da api-futebol não configurada (passe no construtor ou export API_FUTEBOL_KEY)');
        }
        $this->apiKey = $key;
        $this->cacheDir = $cacheDir ?: '/app/data/api_futebol_cache';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /** GET genérico com cache. $ttl=0 desativa cache. */
    public function get(string $endpoint, int $ttl = 600): array
    {
        $endpoint = '/' . ltrim($endpoint, '/');
        $cacheFile = $this->cacheDir . '/' . md5($endpoint) . '.json';
        if ($ttl > 0 && is_readable($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = @json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }

        $ch = curl_init(self::BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Em erro (incluindo 429 limite diário), retorna cache stale se houver
        if ($code !== 200) {
            if (is_readable($cacheFile)) {
                $stale = @json_decode((string)file_get_contents($cacheFile), true);
                if (is_array($stale)) return $stale + ['_stale' => true, '_http_code' => $code];
            }
            throw new RuntimeException("api-futebol HTTP {$code}: " . substr((string)$body, 0, 200));
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new RuntimeException('api-futebol resposta inválida: ' . substr((string)$body, 0, 200));
        }
        if ($ttl > 0) @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $data;
    }

    /** Detalhes da partida (escalação, eventos, transmissão, árbitros). Cache 5min. */
    public function getPartida(int $id, int $ttl = 300): array
    {
        return $this->get("/partidas/{$id}", $ttl);
    }

    /** Dados do time (elenco, info). Cache 24h por padrão. */
    public function getTime(int $id, int $ttl = 86400): array
    {
        return $this->get("/times/{$id}", $ttl);
    }

    /** Lista de campeonatos. */
    public function getCampeonatos(int $ttl = 3600): array
    {
        return $this->get('/campeonatos', $ttl);
    }

    /** Tabela de classificação. */
    public function getTabela(int $campeonatoId, int $ttl = 600): array
    {
        return $this->get("/campeonatos/{$campeonatoId}/tabela", $ttl);
    }

    /** Rodada específica (jogos da rodada). */
    public function getRodada(int $campeonatoId, ?int $rodada = null, int $ttl = 600): array
    {
        $ep = "/campeonatos/{$campeonatoId}/rodadas";
        if ($rodada !== null) $ep .= "/{$rodada}";
        return $this->get($ep, $ttl);
    }

    /** Jogos ao vivo agora. Cache 60s pra polling durante partida. */
    public function getAoVivo(int $ttl = 60): array
    {
        return $this->get('/ao-vivo', $ttl);
    }

    /** Artilharia. */
    public function getArtilharia(int $campeonatoId, int $ttl = 1800): array
    {
        return $this->get("/campeonatos/{$campeonatoId}/artilharia", $ttl);
    }

    /** Fases de mata-mata. */
    public function getFases(int $campeonatoId, int $ttl = 3600): array
    {
        return $this->get("/campeonatos/{$campeonatoId}/fases", $ttl);
    }

    /**
     * Encontra partida_id pelo confronto + data (varre rodadas do campeonato).
     * Útil pra mapear data/jogos_vitoria.json → IDs da api-futebol.
     *
     * @param int $campeonatoId
     * @param string $dataAlvo YYYY-MM-DD
     * @param int $timeIdAlvo (ex: 50 Vitória)
     * @return int|null partida_id ou null se não achou
     */
    public function buscarPartidaIdPorData(int $campeonatoId, string $dataAlvo, int $timeIdAlvo): ?int
    {
        $rodadas = $this->getRodada($campeonatoId, null);
        $rodadasArr = is_array($rodadas) ? $rodadas : [];
        foreach ($rodadasArr as $rodInfo) {
            $rodNum = (int)($rodInfo['rodada'] ?? 0);
            if ($rodNum === 0) continue;
            try {
                $det = $this->getRodada($campeonatoId, $rodNum);
                $partidas = $det['partidas'] ?? [];
                foreach ($partidas as $p) {
                    $dt = substr((string)($p['data_realizacao_iso'] ?? $p['data_realizacao'] ?? ''), 0, 10);
                    $mid = (int)($p['time_mandante']['time_id'] ?? 0);
                    $vid = (int)($p['time_visitante']['time_id'] ?? 0);
                    if ($dt === $dataAlvo && ($mid === $timeIdAlvo || $vid === $timeIdAlvo)) {
                        return (int)($p['partida_id'] ?? 0) ?: null;
                    }
                }
            } catch (Throwable $e) {
                // continua próxima rodada
            }
        }
        return null;
    }
}
