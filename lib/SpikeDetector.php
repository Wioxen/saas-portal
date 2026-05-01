<?php
/**
 * SpikeDetector — capta trends que ESTÃO bombando AGORA via Google Trends realtime RSS.
 *
 * Feed: https://trends.google.com/trending/rss?geo=BR
 * Atualização: ~10 min na fonte. Cada item tem termo + approx_traffic + 3-5 notícias relacionadas.
 *
 * Diferente do pingo (que monitora 50+ feeds por nicho), o SpikeDetector pega o ZEITGEIST
 * brasileiro em tempo real. Discover premia conteúdo publicado nos primeiros 30-60min de
 * um pico — esse módulo dá esse timing.
 *
 * Filtros (anti-lixo):
 *   - traffic mínimo configurável (default 1000+)
 *   - blocklist editorial: morte, política partidária, fofoca, loteria, religião extremista
 *   - dedupe por termo+dia (mesmo termo aparece várias vezes ao longo do dia, capta só 1x)
 *
 * Output: cria entry no DiscoverDb com:
 *   - origem = 'spike:trends-realtime'
 *   - score_discover = 10.0 (manda pro Sonnet pelo Trend-Scoring Gate)
 *   - status = 'aprovado'
 *   - site = roteamento por cluster (DiscoverPingo::roteamentoPorCluster)
 *
 * Uso:
 *   php scripts/spike_detect.php                # roda 1 ciclo
 *   php scripts/spike_detect.php --dry-run      # detecta mas não persiste
 *   php scripts/spike_detect.php --min=500      # threshold de traffic baixado
 *
 * Cron sugerido (a cada 10 min — Google atualiza nessa cadência):
 *   *\/10 * * * * /usr/bin/php /var/www/clonais/scripts/spike_detect.php --quiet >> /var/log/clonais/spike.log 2>&1
 */

require_once __DIR__ . '/DiscoverDb.php';
require_once __DIR__ . '/DiscoverClusterMatcher.php';
require_once __DIR__ . '/DiscoverPingo.php';
require_once __DIR__ . '/DiscoverPainClassifier.php';

class SpikeDetector
{
    private const FEED_URL = 'https://trends.google.com/trending/rss?geo=BR';
    private const STATE_PATH = '/../data/spike_state.json';
    private const TIMEOUT = 12;

    /** Termos que NÃO viram post (mesmo com alto traffic). Anti-lixo editorial. */
    private const BLOCKLIST_PATTERNS = [
        '/\bmorre|morreu|mortes?|falec\w+|luto\b/iu',
        '/\bbbb|big brother|reality\b/iu',
        '/\bxuxa|anitta|gusttavo|simone|simaria|maraisa\b/iu',  // celebridades fofoca
        '/\bbolsonaro|lula|moraes|stf|congresso|cpi\b/iu',  // política polarizada
        '/\bloter\w+|mega-?sena|quina|lotofácil|timemania\b/iu',
        '/\b(igreja|pastor|rcc|dízimo|santuário|aparição)\b/iu',
        '/\b(tiroteio|atirador|massacre|estupro|sequestro)\b/iu',
        '/\b(divórcio|traição|amante|romance|namora)\b/iu',
    ];

    /** Tráfego mínimo pra entrar — Google reporta como "1000+", "500+", etc. */
    public static function thresholdPadrao(): int { return 1000; }

    /**
     * Executa 1 ciclo de detecção.
     *
     * @return array {trends_total, trends_aprovados, trends_blocklist, trends_duplicados, criados: [{id, site, termo, traffic}]}
     */
    public static function detectar(int $thresholdMin = 1000, bool $dryRun = false): array
    {
        $xml = self::baixarFeed();
        if ($xml === null) {
            return ['ok' => false, 'erro' => 'feed_indisponivel', 'criados' => []];
        }

        $items = self::parseFeed($xml);
        $state = self::lerState();
        $hoje = date('Y-m-d');

        // Pingo preditivo: classifica items em new/rising/stable/declining ANTES do threshold
        // pra que termos `rising` ganhem boost de score (entram no Sonnet com prioridade).
        // Falha-silenciosa: se preditor não disponível, segue sem labels.
        $itemsClassificados = $items;
        $preditor = null;
        try {
            require_once __DIR__ . '/PingoPreditor.php';
            $preditor = new PingoPreditor();
            $itemsClassificados = $preditor->classificar(array_map(
                fn($it) => ['termo' => $it['termo'], 'traffic' => (int)$it['traffic'], 'ts' => time()],
                $items
            ));
            // Re-anexa label/momentum aos items originais (mesma ordem)
            foreach ($items as $i => &$it) {
                $it['predictor_label']   = $itemsClassificados[$i]['predictor_label'] ?? 'unknown';
                $it['predictor_momentum']= $itemsClassificados[$i]['momentum_pct'] ?? 0;
            }
            unset($it);
        } catch (Throwable $e) { /* preditor opcional */ }

        $criados = []; $blocklist = 0; $duplicados = 0; $abaixoThreshold = 0;
        $rising = 0;
        $db = new DiscoverDb();

        foreach ($items as $item) {
            $termo = trim($item['termo']);
            $traffic = $item['traffic'];
            if ($termo === '') continue;

            // Threshold de tráfego
            if ($traffic < $thresholdMin) { $abaixoThreshold++; continue; }

            // Blocklist editorial
            if (self::estaBlocked($termo)) { $blocklist++; continue; }

            // Dedupe diário (mesmo termo aparece várias vezes na lista realtime)
            $chave = $hoje . '|' . mb_strtolower($termo, 'UTF-8');
            if (isset($state['processados'][$chave])) { $duplicados++; continue; }

            // Cluster → roteamento → site
            // Enrichment: usar títulos das notícias do feed Google Trends como `relacionados`.
            // Sem isso, "gregore" (jogador Cruzeiro) cai em curiosidades_geral; com isso,
            // "Cruzeiro contratações" no relacionado faz cluster=esportes corretamente.
            $tituloNoticias = array_filter(array_column($item['noticias'] ?? [], 'titulo'));
            $cluster = DiscoverClusterMatcher::detectar([
                'termo'         => $termo,
                'categoria_ids' => [],
                'relacionados'  => array_slice($tituloNoticias, 0, 5),
            ]);
            $clusterKey = (string)($cluster['key'] ?? 'curiosidades_geral');
            $site = DiscoverPingo::roteamentoPorCluster($clusterKey);

            // Contexto pro PainClassifier — usa titulos relacionados também
            $contextoPain = implode(' ', $tituloNoticias);
            $pain = DiscoverPainClassifier::classificar($termo, $contextoPain);

            // Score base 10.0; se preditor classificou como `rising`, sobe pra 12.0
            // (passa antes pelo Trend-Scoring Gate, prioridade sobre outros aprovados).
            $label = $item['predictor_label'] ?? 'unknown';
            $momentum = (float)($item['predictor_momentum'] ?? 0);
            $scoreFinal = PingoPreditor::boostScoreDiscover($label, 10.0);
            if ($label === 'rising') $rising++;
            $origemSufixo = $label === 'rising' ? '+rising' : '';

            // Construir registro pra DB
            $registro = [
                'site' => $site,
                'termo' => $termo,
                'status' => 'aprovado',
                'score_discover' => $scoreFinal,
                'data_detectada' => date('Y-m-d H:i:s'),
                'origem' => 'spike:trends-realtime' . $origemSufixo,
                'predictor_label' => $label,
                'predictor_momentum_pct' => $momentum,
                'categoria' => 'Trending Brasil',
                'categoria_ids' => [],
                'volume_busca' => $traffic,
                'volume_label' => $item['traffic_raw'] ?? ($traffic . '+'),
                'growth_pct' => 0,
                'intencao' => 'curiosidade',
                'noticias_qtd' => count($item['noticias'] ?? []),
                'relacionados' => array_slice(array_column($item['noticias'] ?? [], 'titulo'), 0, 5),
                'pain' => $pain,
                'cluster_detect' => ['key' => $cluster['key'] ?? null, 'nome' => $cluster['nome'] ?? null],
                'ativo' => 1,
                'pingo_link' => $item['noticias'][0]['url'] ?? '',
            ];

            if ($dryRun) {
                $criados[] = ['id' => 0, 'site' => $site, 'termo' => $termo, 'traffic' => $traffic, 'cluster' => $clusterKey, 'dry' => true];
                continue;
            }

            try {
                $id = $db->upsert($registro);
                $state['processados'][$chave] = ['id' => $id, 'ts' => date('c'), 'site' => $site];
                $criados[] = ['id' => $id, 'site' => $site, 'termo' => $termo, 'traffic' => $traffic, 'cluster' => $clusterKey];
            } catch (Throwable $e) {
                // Não bloqueia outros — segue
            }
        }

        // Limpa state — mantém só últimos 7 dias
        $cutoff = strtotime('-7 days');
        $state['processados'] = array_filter($state['processados'] ?? [], function($v) use ($cutoff) {
            return is_array($v) && strtotime($v['ts'] ?? 'now') >= $cutoff;
        });
        if (!$dryRun) self::salvarState($state);

        return [
            'ok' => true,
            'trends_total' => count($items),
            'criados' => $criados,
            'blocklist' => $blocklist,
            'duplicados' => $duplicados,
            'abaixo_threshold' => $abaixoThreshold,
            'rising' => $rising,
        ];
    }

    // ─────────── INTERNOS ───────────

    private static function baixarFeed(): ?string
    {
        $ch = curl_init(self::FEED_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/rss+xml, application/xml'],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code !== 200 || strlen((string)$resp) < 500) return null;
        return (string)$resp;
    }

    private static function parseFeed(string $xml): array
    {
        libxml_use_internal_errors(true);
        $doc = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        if ($doc === false) return [];

        $items = [];
        foreach ($doc->channel->item ?? [] as $item) {
            $ht = $item->children('ht', true);
            $traffic = (string)($ht->approx_traffic ?? '');
            $trafficNum = (int)preg_replace('/[^\d]/', '', $traffic);

            $noticias = [];
            foreach ($ht->news_item ?? [] as $ni) {
                $nht = $ni->children('ht', true);
                $noticias[] = [
                    'titulo' => trim((string)($nht->news_item_title ?? '')),
                    'url'    => trim((string)($nht->news_item_url ?? '')),
                    'fonte'  => trim((string)($nht->news_item_source ?? '')),
                ];
            }

            $items[] = [
                'termo'        => trim((string)$item->title),
                'pub_date'     => (string)$item->pubDate,
                'traffic'      => $trafficNum,
                'traffic_raw'  => $traffic,
                'noticias'     => $noticias,
            ];
        }
        return $items;
    }

    private static function estaBlocked(string $termo): bool
    {
        foreach (self::BLOCKLIST_PATTERNS as $rx) {
            if (preg_match($rx, $termo)) return true;
        }
        return false;
    }

    private static function lerState(): array
    {
        $path = __DIR__ . self::STATE_PATH;
        if (!is_file($path)) return ['processados' => []];
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data)) return ['processados' => []];
        if (!isset($data['processados'])) $data['processados'] = [];
        return $data;
    }

    private static function salvarState(array $data): void
    {
        $path = __DIR__ . self::STATE_PATH;
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) return;
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $path);
        }
    }
}
