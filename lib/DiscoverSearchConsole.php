<?php
/**
 * DiscoverSearchConsole — client REST do Google Search Console via Service Account.
 *
 * Sem libs externas (JWT RS256 manual via openssl_sign).
 *
 * Fluxo OAuth2 service account:
 *   1. Constrói JWT com claim {iss, scope, aud, iat, exp}
 *   2. Assina com private_key RSA (RS256)
 *   3. POST /oauth2.googleapis.com/token → access_token (1h)
 *   4. Chama API Search Console com Authorization: Bearer {token}
 *
 * Endpoints:
 *   - GET /webmasters/v3/sites
 *   - POST /webmasters/v3/sites/{siteUrl}/searchAnalytics/query
 *
 * Scope: webmasters.readonly (suficiente para consultas).
 * Cache: access_token mantido em memória por 55min.
 */
class DiscoverSearchConsole
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE  = 'https://www.googleapis.com/webmasters/v3';
    private const SCOPE     = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const TOKEN_TTL = 3600 - 60; // 59 min — margem antes do expiry

    private ?array $credentials = null;
    private ?string $cachedToken = null;
    private int $cachedTokenExpira = 0;
    private string $credentialsPath;

    public function __construct(?string $credentialsPath = null)
    {
        $this->credentialsPath = $credentialsPath ?? __DIR__ . '/../data/google_credentials.json';
    }

    /** Carrega o JSON da service account (lazy). */
    private function carregarCredenciais(): array
    {
        if ($this->credentials !== null) return $this->credentials;
        if (!is_file($this->credentialsPath)) {
            throw new RuntimeException("Credenciais Google não encontradas em {$this->credentialsPath}. Salve o JSON da Service Account.");
        }
        $raw = (string)@file_get_contents($this->credentialsPath);
        $j = json_decode($raw, true);
        if (!is_array($j) || empty($j['client_email']) || empty($j['private_key'])) {
            throw new RuntimeException('JSON de credenciais inválido (esperado client_email + private_key).');
        }
        $this->credentials = $j;
        return $j;
    }

    /**
     * Retorna access token válido. Gera novo via JWT bearer flow se não houver cache.
     * @return string Bearer token
     */
    public function getAccessToken(): string
    {
        if ($this->cachedToken !== null && time() < $this->cachedTokenExpira) {
            return $this->cachedToken;
        }
        $cred = $this->carregarCredenciais();

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claim = [
            'iss'   => $cred['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'exp'   => $now + 3600,
            'iat'   => $now,
        ];

        $headerB64 = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $claimB64  = self::base64UrlEncode(json_encode($claim, JSON_UNESCAPED_SLASHES));
        $assinatura = '';
        $payload    = $headerB64 . '.' . $claimB64;

        $okSign = @openssl_sign($payload, $assinatura, $cred['private_key'], 'sha256WithRSAEncryption');
        if (!$okSign) {
            throw new RuntimeException('Falha ao assinar JWT — private_key inválida?');
        }
        $jwt = $payload . '.' . self::base64UrlEncode($assinatura);

        // Trade JWT → access_token
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) throw new RuntimeException("cURL token falhou: {$err}");
        $data = json_decode((string)$resp, true);
        if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
            $msgErro = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? 'desconhecido') : "HTTP {$code}";
            throw new RuntimeException("Falha obtendo access token: {$msgErro}");
        }

        $this->cachedToken = (string)$data['access_token'];
        $this->cachedTokenExpira = time() + min((int)($data['expires_in'] ?? 3600), self::TOKEN_TTL);
        return $this->cachedToken;
    }

    /**
     * Lista sites verificados/autorizados pra esta service account.
     * @return array list of {siteUrl, permissionLevel}
     */
    public function listarSites(): array
    {
        $resp = $this->apiGet('/sites');
        return $resp['siteEntry'] ?? [];
    }

    /**
     * Lista sitemaps já submetidos pra um site.
     * @return array sitemap entries [{path, lastSubmitted, isPending, errors, warnings, ...}]
     */
    public function listarSitemaps(string $siteUrl): array
    {
        $encoded = urlencode($siteUrl);
        $resp = $this->apiGet('/sites/' . $encoded . '/sitemaps');
        return $resp['sitemap'] ?? [];
    }

    /**
     * Submete um sitemap pra GSC. Idempotente (Google aceita re-submissão sem erro).
     * @return bool true se HTTP 200, false em falha
     */
    public function submeterSitemap(string $siteUrl, string $sitemapUrl): bool
    {
        $encoded = urlencode($siteUrl);
        $sitemapEncoded = urlencode($sitemapUrl);
        // PUT /sites/{siteUrl}/sitemaps/{feedpath}
        $token = $this->getAccessToken();
        $url = self::API_BASE . '/sites/' . $encoded . '/sitemaps/' . $sitemapEncoded;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        @curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

    /**
     * Consulta performance (queries, páginas, CTR, posição) de um site.
     *
     * @param string $siteUrl ex: 'https://cursosenacgratuito.com.br/' ou 'sc-domain:cursosenacgratuito.com.br'
     * @param string $dataInicio YYYY-MM-DD
     * @param string $dataFim    YYYY-MM-DD
     * @param array $opcoes ['dimensoes'=>['query'], 'limite'=>100, 'tipo'=>'web|discover|news']
     * @return array {rows: [{keys, clicks, impressions, ctr, position}], totals: {...}}
     */
    public function consultarPerformance(string $siteUrl, string $dataInicio, string $dataFim, array $opcoes = []): array
    {
        $dimensoes = $opcoes['dimensoes'] ?? ['query'];
        $limite    = max(1, min(25000, (int)($opcoes['limite'] ?? 100)));
        $tipo      = $opcoes['tipo'] ?? 'web';

        $payload = [
            'startDate'  => $dataInicio,
            'endDate'    => $dataFim,
            'dimensions' => $dimensoes,
            'rowLimit'   => $limite,
            'type'       => $tipo, // 'web' (busca normal), 'discover', 'googleNews'
        ];

        // siteUrl precisa estar URL-encoded no path
        $encoded = urlencode($siteUrl);
        $path = '/sites/' . $encoded . '/searchAnalytics/query';
        $resp = $this->apiPost($path, $payload);

        // Calcula totais agregados
        $totals = ['clicks' => 0, 'impressions' => 0];
        foreach (($resp['rows'] ?? []) as $r) {
            $totals['clicks'] += (int)($r['clicks'] ?? 0);
            $totals['impressions'] += (int)($r['impressions'] ?? 0);
        }
        $totals['ctr'] = $totals['impressions'] > 0
            ? round($totals['clicks'] / $totals['impressions'], 4)
            : 0;

        return [
            'rows'   => $resp['rows'] ?? [],
            'totals' => $totals,
            'site'   => $siteUrl,
            'periodo'=> ['inicio' => $dataInicio, 'fim' => $dataFim],
            'tipo'   => $tipo,
        ];
    }

    /**
     * Resolve o siteUrl correto pra usar no urlInspection.
     * Search Console tem 2 tipos de property: URL-prefix (https://...) e Domain (sc-domain:dominio).
     * O siteUrl no payload precisa ser EXATAMENTE como aparece em listarSites().
     *
     * @param string $domain Apenas o domínio (ex: "leaodabarra.com.br")
     * @return string|null   siteUrl exato (URL-prefix ou sc-domain) ou null se nenhuma property cobre
     */
    public function resolverSiteUrl(string $domain): ?string
    {
        // Normaliza: tira protocolo, www, trailing slash
        $d = strtolower(trim($domain));
        $d = preg_replace('#^https?://#', '', $d) ?? $d;
        $d = preg_replace('#^www\.#', '', $d) ?? $d;
        $d = rtrim($d, '/');

        try {
            $sites = $this->listarSites();
        } catch (Throwable $e) { return null; }

        // Preferência: URL-prefix exato → sc-domain → URL-prefix com www
        $matchUrlPrefix = null;
        $matchDomain = null;
        foreach ($sites as $s) {
            $u = (string)($s['siteUrl'] ?? '');
            if (str_starts_with($u, 'sc-domain:')) {
                $scDomain = substr($u, 10);
                if ($scDomain === $d) { $matchDomain = $u; }
            } else {
                // URL-prefix
                $hostFromUrl = preg_replace('#^https?://#', '', rtrim($u, '/'));
                $hostFromUrl = preg_replace('#^www\.#', '', $hostFromUrl);
                if ($hostFromUrl === $d) { $matchUrlPrefix = $u; }
            }
        }
        // URL-prefix tem prioridade (mais específico, melhor pra inspection)
        return $matchUrlPrefix ?? $matchDomain;
    }

    /**
     * URL Inspection — checa se URL específica está indexada pelo Google.
     *
     * Endpoint diferente da API base (webmasters/v3): usa searchconsole.googleapis.com/v1.
     * Quota: 2.000 chamadas/dia/site no Search Console API.
     *
     * @param string $siteUrl URL completa da property (ex: https://cursosenacgratuito.com.br/)
     * @param string $url     URL a inspecionar
     * @return array {coverageState, verdict, lastCrawlTime, indexed (bool), raw}
     */
    public function inspecionarUrl(string $siteUrl, string $url): array
    {
        $token = $this->getAccessToken();
        $endpoint = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
        $payload = [
            'inspectionUrl' => $url,
            'siteUrl'       => $siteUrl,
            'languageCode'  => 'pt-BR',
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code !== 200) {
            $err = '';
            $j = $resp ? json_decode((string)$resp, true) : null;
            if (is_array($j)) { $err = (string)($j['error']['message'] ?? ''); }
            return [
                'ok'             => false,
                'http_code'      => $code,
                'error'          => $err ?: "HTTP {$code}",
                'coverageState'  => null,
                'verdict'        => null,
                'lastCrawlTime'  => null,
                'indexed'        => null,
            ];
        }

        $data = json_decode((string)$resp, true);
        $idx = $data['inspectionResult']['indexStatusResult'] ?? [];
        $coverage = (string)($idx['coverageState'] ?? '');
        $verdict  = (string)($idx['verdict'] ?? '');

        // Detecta INDEXADO em PT-BR e EN (Search Console retorna no idioma do payload).
        // States indexados:
        //   EN: "Submitted and indexed" / "Indexed, not submitted in sitemap"
        //   PT: "Enviada e indexada" / "Indexada, mas não enviada pelo sitemap"
        // States NÃO-indexados (mais comuns):
        //   EN: "Discovered - currently not indexed" / "Crawled - currently not indexed"
        //       / "Page with redirect" / "Duplicate without user-selected canonical"
        //   PT: "Detectada, mas não indexada no momento" / "Rastreada, mas não indexada no momento"
        //       / "Página com redirecionamento" / "Duplicada sem URL canônica indicada pelo usuário"
        $covLow = mb_strtolower(trim($coverage), 'UTF-8');
        $isIndexed = false;
        if ($covLow !== '') {
            // Frases que SOZINHAS indicam indexado (PT/EN). Faz match parcial.
            $indexedNeedles = [
                'enviada e indexada', 'enviado e indexado',
                'submitted and indexed',
                'indexed, not submitted',
                'indexada, mas não enviada', 'indexada mas nao enviada',
            ];
            foreach ($indexedNeedles as $n) {
                if (str_contains($covLow, $n)) { $isIndexed = true; break; }
            }
            // Verdict PASS é mais um sinal — se vier explícito, confia
            if (!$isIndexed && strtoupper($verdict) === 'PASS') {
                $isIndexed = true;
            }
        }

        return [
            'ok'            => true,
            'http_code'     => 200,
            'coverageState' => $coverage,
            'verdict'       => $verdict,
            'lastCrawlTime' => $idx['lastCrawlTime'] ?? null,
            'indexed'       => $isIndexed,
            'raw'           => $idx,
        ];
    }

    /** Wrapper GET autenticado. */
    private function apiGet(string $path): array
    {
        $token = $this->getAccessToken();
        $ch = curl_init(self::API_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException('cURL GET falhou');
        $data = json_decode((string)$resp, true);
        if ($code !== 200) {
            $msg = is_array($data) ? ($data['error']['message'] ?? "HTTP {$code}") : "HTTP {$code}";
            throw new RuntimeException("GSC GET {$path}: {$msg}");
        }
        return is_array($data) ? $data : [];
    }

    /** Wrapper POST JSON autenticado. */
    private function apiPost(string $path, array $payload): array
    {
        $token = $this->getAccessToken();
        $ch = curl_init(self::API_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException('cURL POST falhou');
        $data = json_decode((string)$resp, true);
        if ($code !== 200) {
            // 400 "invalid argument" em searchAnalytics geralmente = site não tem dados pro tipo
            // (ex: site novo sem tráfego do Discover). Retorna vazio em vez de explodir.
            if ($code === 400 && str_contains($path, 'searchAnalytics')) {
                return ['rows' => [], '_sem_dados' => true];
            }
            $msg = is_array($data) ? ($data['error']['message'] ?? "HTTP {$code}") : "HTTP {$code}";
            throw new RuntimeException("GSC POST {$path}: {$msg}");
        }
        return is_array($data) ? $data : [];
    }

    /** Base64URL (RFC 7515): replace + with -, / with _, strip padding =. */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
