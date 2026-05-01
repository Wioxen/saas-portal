<?php
/**
 * BackupOffsite — sync de data/ pra storage S3-compatible (AWS S3, B2, Spaces, R2, MinIO).
 *
 * Por que existe:
 *   JsonStore mantém 5 backups locais. Se VPS pega fogo / ransomware / disco corrompe,
 *   perde TUDO. Off-site backup é proteção real.
 *
 * Implementação:
 *   - PUT pra S3 via Signature V4 (sem AWS SDK)
 *   - Sobe arquivos críticos: discover_trends.json, fila/*.json, click_log/*.jsonl,
 *     post_performance/*.jsonl, predictor_state.json, _state.json, cluster_paused.json
 *   - Skipa cache descartável (data/cache/* = pode regenerar)
 *
 * Config (.env):
 *   BACKUP_OFFSITE_ENABLED=1
 *   BACKUP_S3_ENDPOINT=https://nyc3.digitaloceanspaces.com  (opcional pra non-AWS)
 *   BACKUP_S3_REGION=nyc3
 *   BACKUP_S3_BUCKET=clonais-backups
 *   BACKUP_S3_KEY=...
 *   BACKUP_S3_SECRET=...
 *   BACKUP_S3_PREFIX=clonais/                              (opcional, prefixa keys)
 *
 * Suporta:
 *   - AWS S3
 *   - DigitalOcean Spaces (S3-compatible)
 *   - Backblaze B2 (S3-compatible)
 *   - Cloudflare R2
 *   - MinIO self-hosted
 *
 * Uso (via cron daily):
 *   php scripts/backup_offsite.php --quiet
 */
class BackupOffsite
{
    /** Padrões de arquivos críticos pra backup. */
    public const PADROES_CRITICOS = [
        'discover_trends.json',
        'discover_trends_archive/*.json',
        'fila/*.json',
        'click_log/*.jsonl',
        'click_log/_state.json',
        'post_performance/*.jsonl',
        'predictor_state.json',
        'cluster_paused.json',
        'auto_refresh_state.json',
        'pingo_state.json',
        'pingo_filtros.json',
        'fontes_pingo.json',
        'spike_state.json',
        'afiliados.json',
        'afiliados_clicks.json',
        'cost_tracker/*.jsonl',
    ];

    /**
     * Lê config do env. Retorna null se backup desabilitado.
     */
    public static function configFromEnv(): ?array
    {
        require_once __DIR__ . '/Env.php';
        @Env::load(__DIR__ . '/../.env');

        if (!Env::get('BACKUP_OFFSITE_ENABLED', '')) return null;

        $cfg = [
            'endpoint' => (string)Env::get('BACKUP_S3_ENDPOINT', 'https://s3.amazonaws.com'),
            'region'   => (string)Env::get('BACKUP_S3_REGION', 'us-east-1'),
            'bucket'   => (string)Env::get('BACKUP_S3_BUCKET', ''),
            'key'      => (string)Env::get('BACKUP_S3_KEY', ''),
            'secret'   => (string)Env::get('BACKUP_S3_SECRET', ''),
            'prefix'   => trim((string)Env::get('BACKUP_S3_PREFIX', 'clonais/'), '/') . '/',
        ];
        if ($cfg['bucket'] === '' || $cfg['key'] === '' || $cfg['secret'] === '') {
            return null;
        }
        return $cfg;
    }

    /**
     * Lista arquivos críticos pra backup (existentes em data/).
     * @return string[] paths absolutos
     */
    public static function listarArquivos(?string $dataDir = null): array
    {
        $dataDir = $dataDir ?? __DIR__ . '/../data';
        $arquivos = [];
        foreach (self::PADROES_CRITICOS as $padrao) {
            $matches = glob($dataDir . '/' . $padrao);
            if ($matches) {
                foreach ($matches as $f) {
                    if (is_file($f)) $arquivos[] = $f;
                }
            }
        }
        return $arquivos;
    }

    /**
     * Sync arquivos críticos pra S3. Retorna relatório.
     *
     * @param array $cfg result de configFromEnv() ou custom
     * @param ?string $dataDir override (default lib/../data)
     * @param bool $dryRun
     * @return array {ok, enviados, falhas, bytes_total, detalhes}
     */
    public static function sync(array $cfg, ?string $dataDir = null, bool $dryRun = false): array
    {
        $arquivos = self::listarArquivos($dataDir);
        if (empty($arquivos)) {
            return ['ok' => true, 'enviados' => 0, 'falhas' => 0, 'bytes_total' => 0, 'nota' => 'sem arquivos'];
        }

        $dataDir = realpath($dataDir ?? __DIR__ . '/../data');
        $enviados = 0;
        $falhas = 0;
        $bytesTotal = 0;
        $detalhes = [];

        foreach ($arquivos as $f) {
            $relPath = ltrim(str_replace($dataDir, '', $f), '/\\');
            $key = $cfg['prefix'] . str_replace('\\', '/', $relPath);
            $bytes = (int)@filesize($f);

            if ($dryRun) {
                $enviados++;
                $bytesTotal += $bytes;
                $detalhes[] = ['arquivo' => $relPath, 'key' => $key, 'bytes' => $bytes, 'dry' => true];
                continue;
            }

            try {
                $r = self::putObject($cfg, $key, file_get_contents($f), self::contentType($f));
                if ($r['ok']) {
                    $enviados++;
                    $bytesTotal += $bytes;
                    $detalhes[] = ['arquivo' => $relPath, 'bytes' => $bytes, 'http' => $r['http_code']];
                } else {
                    $falhas++;
                    $detalhes[] = ['arquivo' => $relPath, 'erro' => $r['erro'] ?? '?', 'http' => $r['http_code'] ?? 0];
                }
            } catch (Throwable $e) {
                $falhas++;
                $detalhes[] = ['arquivo' => $relPath, 'erro' => $e->getMessage()];
            }
        }

        return [
            'ok'          => $falhas === 0,
            'enviados'    => $enviados,
            'falhas'      => $falhas,
            'bytes_total' => $bytesTotal,
            'detalhes'    => $detalhes,
        ];
    }

    /**
     * PUT object via Signature V4 (assina request manualmente — sem AWS SDK).
     */
    private static function putObject(array $cfg, string $key, string $body, string $contentType = 'application/octet-stream'): array
    {
        $endpoint = rtrim($cfg['endpoint'], '/');
        $url = $endpoint . '/' . $cfg['bucket'] . '/' . str_replace('%2F', '/', rawurlencode($key));
        $host = parse_url($endpoint, PHP_URL_HOST);

        $now = time();
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $dateStamp = gmdate('Ymd', $now);
        $payloadHash = hash('sha256', $body);

        // Canonical request
        $canonicalUri = '/' . $cfg['bucket'] . '/' . str_replace('%2F', '/', rawurlencode($key));
        $headers = [
            'host'                 => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzDate,
        ];
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . $v . "\n";
            $signedHeaders .= $k . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $canonicalRequest = "PUT\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        // String to sign
        $service = 's3';
        $credentialScope = "{$dateStamp}/{$cfg['region']}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        // Signing key
        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $cfg['secret'], true);
        $kRegion  = hash_hmac('sha256', $cfg['region'], $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader = "AWS4-HMAC-SHA256 Credential={$cfg['key']}/{$credentialScope}, "
                    . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        $reqHeaders = [
            'Authorization: ' . $authHeader,
            'x-amz-date: ' . $amzDate,
            'x-amz-content-sha256: ' . $payloadHash,
            'Host: ' . $host,
            'Content-Type: ' . $contentType,
            'Content-Length: ' . strlen($body),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $reqHeaders,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) return ['ok' => false, 'erro' => $err, 'http_code' => 0];
        return [
            'ok'        => $code >= 200 && $code < 300,
            'http_code' => $code,
            'response'  => $code >= 400 ? substr((string)$resp, 0, 500) : null,
            'erro'      => $code >= 400 ? "HTTP {$code}" : null,
        ];
    }

    private static function contentType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'json'  => 'application/json',
            'jsonl' => 'application/x-ndjson',
            'xml'   => 'application/xml',
            'txt'   => 'text/plain',
            default => 'application/octet-stream',
        };
    }
}
