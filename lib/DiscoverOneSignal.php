<?php
/**
 * DiscoverOneSignal — client REST para push notifications via OneSignal.
 *
 * Endpoint: POST https://api.onesignal.com/notifications  (v2 API)
 * Auth: "Key {REST_API_KEY}" ou "Basic {REST_API_KEY}" — ambos aceitos pelo OneSignal.
 *
 * Estratégia editorial (diferente do plugin WP que manda pra TODO post):
 *   - Só dispara se cluster_ROI >= onesignal_roi_min (default 5.0)
 *   - Só dispara se site do trend == onesignal_site_target (padrão "cursosenac")
 *   - Respeita onesignal_enabled==1
 *   - Segmenta pra "Subscribed Users" (todos subscribers ativos)
 *
 * Payload mínimo:
 *   { app_id, headings{pt}, contents{pt}, url, chrome_web_icon, included_segments }
 */

require_once __DIR__ . '/TrendsTaxonomia.php';

class DiscoverOneSignal
{
    private const ENDPOINT = 'https://api.onesignal.com/notifications';
    private const TIMEOUT_SEC = 15;

    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * Decide se push deve ser enviado pra este trend.
     *   - onesignal_enabled == 1
     *   - cluster ROI >= limite
     *   - site do trend == onesignal_site_target (se especificado)
     *
     * @param array $cfg config (já com site aplicado)
     * @param string $clusterKey chave TrendsTaxonomia
     * @param string $siteAtual slug do site em que o trend está sendo publicado
     */
    public static function deveEnviar(array $cfg, string $clusterKey, string $siteAtual = ''): bool
    {
        if ((int)($cfg['onesignal_enabled'] ?? 0) !== 1) return false;
        if (empty($cfg['onesignal_app_id']) || empty($cfg['onesignal_rest_api_key'])) return false;

        // Site target: só dispara no site onde OneSignal está instalado (ex: cursosenac)
        $siteTarget = trim((string)($cfg['onesignal_site_target'] ?? ''));
        if ($siteTarget !== '' && $siteAtual !== '' && $siteTarget !== $siteAtual) {
            return false;
        }

        // ROI gate — evita queimar audiência com clusters de baixo RPM
        $roi = TrendsTaxonomia::roiEditorial($clusterKey);
        $min = max(0.1, (float)($cfg['onesignal_roi_min'] ?? 5.0));
        return $roi >= $min;
    }

    /**
     * Envia push notification para todos subscribers do app.
     *
     * @param string $titulo (máx 100 chars recomendado — desktop mostra mais, mobile menos)
     * @param string $url URL completa do artigo (para onde o clique leva)
     * @param array $opcoes ['descricao'=>, 'icone_url'=>, 'imagem_url'=>, 'segmentos'=>[], 'dry_run'=>false]
     * @return array ['ok'=>bool, 'notification_id'=>?, 'recipients'=>?, 'erro'=>?, 'http_code'=>, 'tempo_ms'=>]
     */
    public function enviar(string $titulo, string $url, array $opcoes = []): array
    {
        $t0 = microtime(true);
        $base = [
            'ok' => false, 'notification_id' => null, 'recipients' => null,
            'erro' => null, 'http_code' => 0, 'tempo_ms' => 0,
        ];

        $appId = (string)($this->cfg['onesignal_app_id'] ?? '');
        $apiKey = (string)($this->cfg['onesignal_rest_api_key'] ?? '');
        if ($appId === '' || $apiKey === '') {
            return array_merge($base, ['erro' => 'credenciais OneSignal ausentes']);
        }
        if (trim($titulo) === '' || trim($url) === '') {
            return array_merge($base, ['erro' => 'titulo ou url vazios']);
        }

        $descricao = trim((string)($opcoes['descricao'] ?? ''));
        $iconeUrl  = trim((string)($opcoes['icone_url'] ?? ''));
        $imgUrl    = trim((string)($opcoes['imagem_url'] ?? ''));
        $segmentos = (array)($opcoes['segmentos'] ?? ['Subscribed Users']);
        $dryRun    = !empty($opcoes['dry_run']);

        // Limita título pra caber em push mobile (~65 chars)
        $tituloShort = mb_strimwidth($titulo, 0, 85, '…', 'UTF-8');

        $payload = [
            'app_id'            => $appId,
            'included_segments' => $segmentos,
            'headings'          => ['en' => $tituloShort, 'pt' => $tituloShort],
            'url'               => $url,
            // Abre em nova aba ao clicar (default do OneSignal). Inclui UTM pra rastreio.
        ];
        if ($descricao !== '') {
            $descShort = mb_strimwidth($descricao, 0, 140, '…', 'UTF-8');
            $payload['contents'] = ['en' => $descShort, 'pt' => $descShort];
        } else {
            // OneSignal exige 'contents' — usa o próprio título como fallback curto
            $payload['contents'] = ['en' => $tituloShort, 'pt' => $tituloShort];
        }
        if ($iconeUrl !== '') {
            $payload['chrome_web_icon']  = $iconeUrl;
            $payload['firefox_icon']     = $iconeUrl;
            $payload['small_icon']       = $iconeUrl;
        }
        if ($imgUrl !== '') {
            $payload['big_picture']      = $imgUrl;   // mobile
            $payload['chrome_web_image'] = $imgUrl;   // desktop
        }

        if ($dryRun) {
            return array_merge($base, [
                'ok' => true,
                'dry_run' => true,
                'payload' => $payload,
                'tempo_ms' => (int)((microtime(true) - $t0) * 1000),
            ]);
        }

        // POST
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
                // OneSignal v2 aceita ambos — "Key" é o formato novo recomendado
                'Authorization: Key ' . $apiKey,
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $tempo = (int)((microtime(true) - $t0) * 1000);

        if ($body === false) {
            return array_merge($base, ['erro' => 'curl: ' . $err, 'http_code' => $http, 'tempo_ms' => $tempo]);
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            return array_merge($base, ['erro' => 'resposta não-JSON', 'http_code' => $http, 'tempo_ms' => $tempo]);
        }

        // Sucesso (HTTP 200) — mas OneSignal retorna "errors" mesmo com 200 em alguns casos
        if ($http === 200 && !empty($data['id'])) {
            return [
                'ok'              => true,
                'notification_id' => (string)$data['id'],
                'recipients'      => (int)($data['recipients'] ?? 0),
                'erro'            => null,
                'http_code'       => $http,
                'tempo_ms'        => $tempo,
            ];
        }

        // 400/401/403: credencial inválida ou payload errado
        // 200 com "errors": ex "All included players are not subscribed" (sem subscribers)
        $erroMsg = '';
        if (!empty($data['errors'])) {
            $erroMsg = is_array($data['errors']) ? implode(' · ', array_map('strval', $data['errors'])) : (string)$data['errors'];
        } elseif ($http !== 200) {
            $erroMsg = "HTTP {$http}";
        } else {
            $erroMsg = 'resposta inesperada';
        }

        return [
            'ok'              => false,
            'notification_id' => $data['id'] ?? null,
            'recipients'      => (int)($data['recipients'] ?? 0),
            'erro'            => $erroMsg,
            'http_code'       => $http,
            'tempo_ms'        => $tempo,
        ];
    }

    /**
     * Valida credenciais via GET /apps/{app_id} — sem enviar nada.
     * Útil pra testar setup antes de rodar em produção.
     */
    public function pingar(): array
    {
        $appId = (string)($this->cfg['onesignal_app_id'] ?? '');
        $apiKey = (string)($this->cfg['onesignal_rest_api_key'] ?? '');
        if ($appId === '' || $apiKey === '') {
            return ['ok' => false, 'erro' => 'credenciais ausentes'];
        }
        $ch = curl_init('https://api.onesignal.com/apps/' . urlencode($appId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Key ' . $apiKey, 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string)$body, true);
        if ($http === 200 && is_array($data) && !empty($data['id'])) {
            return [
                'ok'           => true,
                'app_name'     => (string)($data['name'] ?? ''),
                'players'      => (int)($data['players'] ?? 0),
                'subscribers'  => (int)($data['messagable_players'] ?? 0),
            ];
        }
        return ['ok' => false, 'http_code' => $http, 'erro' => is_array($data) ? ($data['errors'][0] ?? 'erro') : 'resposta inválida'];
    }
}
