<?php
/**
 * CloudflareCachePurge — purga URLs específicas no edge Cloudflare após
 * publish/swap pra que mudança apareça imediatamente (sem esperar TTL).
 *
 * Por que: quando Title/P1/Meta Swapper troca um campo via WP REST, sem purge
 * a versão antiga continua servida do edge cache até TTL (4h+ se Cache Everything).
 * Resultado: A/B testing fica cego pelo período de cache. Purge resolve.
 *
 * 100% defensivo:
 *   - Sem CLOUDFLARE_API_TOKEN no .env → no-op silencioso
 *   - Sem cloudflare_zone_id no cfg do site → no-op silencioso
 *   - Erro de API (token revogado, zone errada) → loga em data/cf_purge.log e segue
 *
 * Configuração mínima na Cloudflare (5 min total):
 *   1. .env: CLOUDFLARE_API_TOKEN=xxx (criar em Cloudflare → My Profile → API Tokens
 *      com escopo "Zone:Cache Purge:Edit" pras zonas relevantes)
 *   2. sites.php por site: 'cloudflare_zone_id' => 'abc123...' (pegar da home da
 *      zona em Cloudflare dashboard)
 *
 * Uso (em Wordpress::atualizarPost / criarPost — wire automático):
 *   CloudflareCachePurge::purgeUrls($cfg, [$url1, $url2]);
 *
 * Limites: API Cloudflare aceita até 30 URLs por chamada free, 1000 chamadas/dia.
 * Pra nosso volume (~30 posts/dia × 6 sites × 4 swaps = 720 ops/dia), ok.
 */

require_once __DIR__ . '/Env.php';

class CloudflareCachePurge
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4/zones';
    private const LOG_FILE = '/../data/cf_purge.log';
    private const TIMEOUT_SEC = 5;

    /**
     * Purga lista de URLs no edge. Retorna {ok, purged, motivo}.
     *
     * @param array $cfg cfg do site (precisa de cloudflare_zone_id)
     * @param array<string> $urls URLs absolutas pra purgar
     */
    public static function purgeUrls(array $cfg, array $urls): array
    {
        $urls = array_values(array_filter(array_unique($urls), fn($u) => is_string($u) && trim($u) !== ''));
        if (empty($urls)) return ['ok' => true, 'purged' => 0, 'motivo' => 'sem URLs'];

        $token = self::getToken();
        if ($token === '') {
            return ['ok' => true, 'purged' => 0, 'motivo' => 'sem CLOUDFLARE_API_TOKEN (no-op)'];
        }
        $zoneId = trim((string)($cfg['cloudflare_zone_id'] ?? ''));
        if ($zoneId === '') {
            return ['ok' => true, 'purged' => 0, 'motivo' => 'sem cloudflare_zone_id no cfg (no-op)'];
        }

        // API aceita 30 URLs por chamada; chunka se passar
        $totalPurged = 0;
        foreach (array_chunk($urls, 30) as $chunk) {
            $r = self::callApi($zoneId, $token, ['files' => $chunk]);
            if (!$r['ok']) {
                self::logar('FAIL', $zoneId, $chunk, (string)($r['motivo'] ?? '?'));
                return $r + ['purged' => $totalPurged];
            }
            $totalPurged += count($chunk);
        }

        self::logar('OK', $zoneId, $urls, "purged={$totalPurged}");
        return ['ok' => true, 'purged' => $totalPurged, 'motivo' => "purge ok"];
    }

    /** Atalho pra purgar 1 URL (caso de Title/P1/Meta swap). */
    public static function purgeUrl(array $cfg, string $url): array
    {
        return self::purgeUrls($cfg, [$url]);
    }

    /** Purga zona inteira. NÃO USAR rotina — só pra emergência (deploy de tema, etc). */
    public static function purgeEverything(array $cfg): array
    {
        $token = self::getToken();
        if ($token === '') return ['ok' => true, 'purged' => 0, 'motivo' => 'sem token (no-op)'];
        $zoneId = trim((string)($cfg['cloudflare_zone_id'] ?? ''));
        if ($zoneId === '') return ['ok' => true, 'purged' => 0, 'motivo' => 'sem zone_id (no-op)'];

        $r = self::callApi($zoneId, $token, ['purge_everything' => true]);
        self::logar($r['ok'] ? 'OK' : 'FAIL', $zoneId, ['ALL'], (string)($r['motivo'] ?? '?'));
        return $r;
    }

    public static function configurado(array $cfg): bool
    {
        return self::getToken() !== '' && trim((string)($cfg['cloudflare_zone_id'] ?? '')) !== '';
    }

    // ─────────── HELPERS ───────────

    private static function getToken(): string
    {
        @Env::load(__DIR__ . '/../.env');
        return trim((string)Env::get('CLOUDFLARE_API_TOKEN', ''));
    }

    private static function callApi(string $zoneId, string $token, array $body): array
    {
        $url = self::API_BASE . '/' . urlencode($zoneId) . '/purge_cache';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $out = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($out === false || $httpCode === 0) {
            return ['ok' => false, 'motivo' => "curl error: {$err}", 'http' => 0];
        }
        $resp = json_decode((string)$out, true);
        if (!is_array($resp)) {
            return ['ok' => false, 'motivo' => "JSON inválido (http={$httpCode})", 'http' => $httpCode];
        }
        $sucesso = (bool)($resp['success'] ?? false);
        if (!$sucesso) {
            $erros = is_array($resp['errors'] ?? null) ? json_encode($resp['errors']) : '';
            return ['ok' => false, 'motivo' => "API retornou success=false: {$erros}", 'http' => $httpCode];
        }
        return ['ok' => true, 'http' => $httpCode];
    }

    private static function logar(string $status, string $zoneId, array $urls, string $detalhe): void
    {
        try {
            $logFile = __DIR__ . self::LOG_FILE;
            $dir = dirname($logFile);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $linha = sprintf("[%s] %s zone=%s urls=%d %s\n",
                date('Y-m-d H:i:s'), $status, $zoneId, count($urls), $detalhe);
            @file_put_contents($logFile, $linha, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) { /* log opcional */ }
    }
}
