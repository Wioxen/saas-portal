<?php
/**
 * AutoRefresh — detecta posts com queda de CTR/cliques e re-roteia pro DiscoverReviewer.
 *
 * Ciclo Discover é "spike + queda rápida". Posts decaem em 3-7 dias se ficam estáticos.
 * Solução: cron diário compara 2 janelas (últimos N dias vs N anteriores), detecta queda
 * estatisticamente significativa (≥minClicks na janela base + queda ≥ threshold%),
 * mapeia URL pública → trend_id local, chama DiscoverReviewer pra atualização editorial.
 *
 * Anti-loop: cada trend_id só pode ser refreshed 1x a cada COOLDOWN_DIAS dias.
 *
 * Uso típico (no cron `auto_refresh_posts.php`):
 *   $ar = new AutoRefresh($cfg, $db, $wp);
 *   $candidatos = $ar->detectarPostsEmQueda($siteUrlGsc, 7, 10, 20);
 *   foreach ($candidatos as $c) {
 *       $trendId = $ar->mapearUrlParaTrendId($c['url'], $siteSlug);
 *       if ($trendId && !$ar->jaRefreshou($trendId)) {
 *           $r = (new DiscoverReviewer($cfg, $db))->revisar($trendId);
 *           $ar->marcarRefresh($trendId, $siteSlug, $c, $r);
 *       }
 *   }
 */

require_once __DIR__ . '/DiscoverSearchConsole.php';
require_once __DIR__ . '/DiscoverDb.php';

class AutoRefresh
{
    private const COOLDOWN_DIAS  = 14;        // anti-loop: 1 refresh por post a cada 14d
    private const PATH_STATE_DEF = '/../data/auto_refresh_state.json';
    private const MAX_EVENTOS    = 5000;      // rotaciona log

    private array $cfg;
    private DiscoverDb $db;
    private Wordpress $wp;
    private DiscoverSearchConsole $gsc;
    private string $pathState;
    private array $cacheUrlPublica = [];     // postId → URL pública (evita N chamadas WP)

    public function __construct(array $cfg, DiscoverDb $db, Wordpress $wp, ?string $pathState = null)
    {
        $this->cfg = $cfg;
        $this->db  = $db;
        $this->wp  = $wp;
        $this->gsc = new DiscoverSearchConsole();
        $this->pathState = $pathState ?? __DIR__ . self::PATH_STATE_DEF;
    }

    /**
     * Detecta posts em queda comparando 2 janelas adjacentes do GSC.
     *
     * @param string $siteUrlGsc URL no formato GSC (ex: 'https://comocomprar.com.br/' ou 'sc-domain:...')
     * @param int    $diasJanela tamanho de cada janela (default 7)
     * @param int    $minClicks  cliques mínimos na janela ANTERIOR (filtro de ruído estatístico)
     * @param int    $threshold  queda mínima % pra entrar na lista (default 20 = -20%)
     * @param string $tipo       'web', 'discover' ou 'googleNews' (default 'discover' — foco do projeto)
     * @return array<int,array> [{url, clicks_anterior, clicks_atual, impressions_atual, ctr_atual, delta_clicks_pct}]
     *                          ordenado por delta crescente (queda mais forte primeiro)
     */
    public function detectarPostsEmQueda(string $siteUrlGsc, int $diasJanela = 7, int $minClicks = 10, int $threshold = 20, string $tipo = 'discover'): array
    {
        // Janela ATUAL: últimos $diasJanela dias (mais recentes 3 dias têm dado preliminar — usa offset 3)
        // Janela ANTERIOR: $diasJanela dias antes da atual
        $hoje = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $atualFim    = $hoje->modify('-3 days')->format('Y-m-d');
        $atualInicio = $hoje->modify('-' . (3 + $diasJanela - 1) . ' days')->format('Y-m-d');
        $anteriorFim    = $hoje->modify('-' . (3 + $diasJanela) . ' days')->format('Y-m-d');
        $anteriorInicio = $hoje->modify('-' . (3 + 2 * $diasJanela - 1) . ' days')->format('Y-m-d');

        $atual = $this->gsc->consultarPerformance($siteUrlGsc, $atualInicio, $atualFim, [
            'dimensoes' => ['page'], 'limite' => 1000, 'tipo' => $tipo,
        ]);
        $anterior = $this->gsc->consultarPerformance($siteUrlGsc, $anteriorInicio, $anteriorFim, [
            'dimensoes' => ['page'], 'limite' => 1000, 'tipo' => $tipo,
        ]);

        // Indexa por URL
        $mapAtual    = self::indexarPorPagina($atual['rows'] ?? []);
        $mapAnterior = self::indexarPorPagina($anterior['rows'] ?? []);

        $candidatos = [];
        foreach ($mapAnterior as $url => $metAnt) {
            $clicksAnt = (int)$metAnt['clicks'];
            if ($clicksAnt < $minClicks) continue;  // ruído estatístico

            $metAtu    = $mapAtual[$url] ?? ['clicks' => 0, 'impressions' => 0, 'ctr' => 0];
            $clicksAtu = (int)$metAtu['clicks'];
            $delta = (int)round((($clicksAtu - $clicksAnt) / max(1, $clicksAnt)) * 100);
            if ($delta > -$threshold) continue;  // queda < threshold → ignora

            $candidatos[] = [
                'url'                 => $url,
                'clicks_anterior'     => $clicksAnt,
                'clicks_atual'        => $clicksAtu,
                'impressions_atual'   => (int)($metAtu['impressions'] ?? 0),
                'ctr_atual'           => (float)($metAtu['ctr'] ?? 0),
                'delta_clicks_pct'    => $delta,
                'janela_anterior'     => "{$anteriorInicio} a {$anteriorFim}",
                'janela_atual'        => "{$atualInicio} a {$atualFim}",
            ];
        }

        // Ordena por queda mais forte primeiro
        usort($candidatos, fn($a, $b) => $a['delta_clicks_pct'] <=> $b['delta_clicks_pct']);
        return $candidatos;
    }

    /**
     * Mapeia URL pública (vinda do GSC) → trend_id local.
     * Estratégia: lista records do site, pra cada um extrai postId do url_post (URL de edição WP),
     * busca slug via WP, monta URL pública e compara com a do GSC.
     *
     * @return int|null trend_id ou null se não bate
     */
    public function mapearUrlParaTrendId(string $urlPublica, string $siteSlug): ?int
    {
        $urlNorm = $this->normalizarUrl($urlPublica);
        $records = $this->db->all(['site' => $siteSlug, 'status' => 'published']);

        foreach ($records as $r) {
            $editUrl = (string)($r['url_post'] ?? '');
            if ($editUrl === '' || !preg_match('/post=(\d+)/', $editUrl, $m)) continue;
            $postId = (int)$m[1];

            $publicaWp = $this->urlPublicaWp($postId);
            if ($publicaWp === '') continue;
            if ($this->normalizarUrl($publicaWp) === $urlNorm) {
                return (int)$r['id'];
            }
        }
        return null;
    }

    /** Verifica se trend_id já foi refreshado nos últimos COOLDOWN_DIAS dias. */
    public function jaRefreshou(int $trendId): bool
    {
        $state = $this->lerState();
        $cutoff = time() - (self::COOLDOWN_DIAS * 86400);
        foreach ($state['events'] as $ev) {
            if ((int)($ev['trend_id'] ?? 0) !== $trendId) continue;
            $ts = strtotime((string)($ev['refreshed_at'] ?? '1970-01-01'));
            if ($ts !== false && $ts >= $cutoff) return true;
        }
        return false;
    }

    /** Persiste evento de refresh em state file. */
    public function marcarRefresh(int $trendId, string $siteSlug, array $contextoQueda, array $resultadoReviewer): void
    {
        $state = $this->lerState();
        $state['events'][] = [
            'trend_id'         => $trendId,
            'site'             => $siteSlug,
            'url'              => $contextoQueda['url'] ?? '',
            'clicks_anterior'  => $contextoQueda['clicks_anterior'] ?? 0,
            'clicks_atual'     => $contextoQueda['clicks_atual'] ?? 0,
            'delta_clicks_pct' => $contextoQueda['delta_clicks_pct'] ?? 0,
            'refreshed_at'     => date('c'),
            'reviewer_ok'      => !empty($resultadoReviewer['ok']),
            'reviewer_erro'    => $resultadoReviewer['erro'] ?? null,
            'titulo_antes'     => $resultadoReviewer['titulo_antes']  ?? null,
            'titulo_depois'    => $resultadoReviewer['titulo_depois'] ?? null,
        ];
        if (count($state['events']) > self::MAX_EVENTOS) {
            $state['events'] = array_slice($state['events'], -self::MAX_EVENTOS);
        }
        $state['ultima_execucao'] = date('c');
        $this->salvarState($state);
    }

    /** Histórico de refreshes (debug/admin). */
    public function listarHistorico(?int $ultimosDias = null): array
    {
        $state = $this->lerState();
        $eventos = $state['events'] ?? [];
        if ($ultimosDias !== null) {
            $cutoff = time() - ($ultimosDias * 86400);
            $eventos = array_values(array_filter($eventos, fn($e) => strtotime($e['refreshed_at'] ?? '') >= $cutoff));
        }
        return $eventos;
    }

    // ─────────── INTERNOS ───────────

    /** Agrega rows do GSC por URL (pode haver duplicatas com query/date dimensions). */
    private static function indexarPorPagina(array $rows): array
    {
        $map = [];
        foreach ($rows as $r) {
            $url = (string)($r['keys'][0] ?? '');
            if ($url === '') continue;
            if (!isset($map[$url])) {
                $map[$url] = ['clicks' => 0, 'impressions' => 0, 'ctr' => 0];
            }
            $map[$url]['clicks']      += (int)($r['clicks'] ?? 0);
            $map[$url]['impressions'] += (int)($r['impressions'] ?? 0);
        }
        // Recalcula CTR
        foreach ($map as $url => &$m) {
            $m['ctr'] = $m['impressions'] > 0 ? round($m['clicks'] / $m['impressions'], 4) : 0;
        }
        return $map;
    }

    private function urlPublicaWp(int $postId): string
    {
        if (isset($this->cacheUrlPublica[$postId])) return $this->cacheUrlPublica[$postId];
        try {
            $p = $this->wp->getPost($postId);
            $url = (string)($p['link'] ?? '');
            $this->cacheUrlPublica[$postId] = $url;
            return $url;
        } catch (Throwable $e) {
            $this->cacheUrlPublica[$postId] = '';
            return '';
        }
    }

    private function normalizarUrl(string $url): string
    {
        $url = trim(strtolower($url));
        $url = preg_replace('#^https?://#', '', $url) ?? $url;
        $url = rtrim($url, '/');
        // Remove query string
        if (($pos = strpos($url, '?')) !== false) $url = substr($url, 0, $pos);
        return $url;
    }

    private function lerState(): array
    {
        if (!is_file($this->pathState)) {
            return ['events' => [], 'criado_em' => date('c')];
        }
        $data = json_decode((string)@file_get_contents($this->pathState), true);
        if (!is_array($data)) return ['events' => []];
        if (!isset($data['events']) || !is_array($data['events'])) $data['events'] = [];
        return $data;
    }

    private function salvarState(array $data): void
    {
        $tmp = $this->pathState . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $this->pathState);
        }
    }
}
