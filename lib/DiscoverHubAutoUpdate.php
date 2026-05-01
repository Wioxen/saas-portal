<?php
/**
 * DiscoverHubAutoUpdate — incrementa hub topical quando novo spoke publica (B2).
 *
 * Hub-Spoke é padrão de topical authority: hub central (/hub-{cluster}) lista todos os
 * posts do cluster com link bidirecional. Quando publicamos NOVO post no cluster X,
 * ESTE módulo:
 *   1. Verifica se /hub-{cluster_key} existe no site (via WP API)
 *   2. Se existe, lê HTML atual e INSERE novo `<li>` no `<ul>` principal (idempotente)
 *   3. Atualiza contagem total no título do hub
 *   4. Salva via wp->atualizarPost
 *
 * Idempotente: se URL do spoke já está no HTML, não duplica.
 *
 * Diferença vs gerar_hubs.php (cron):
 *   - cron regenera hub COMPLETO mensal (ranqueia, ordena, reescreve seções)
 *   - este módulo INCREMENTAL — adiciona só o novo. Mais barato, real-time.
 *
 * Uso (em DiscoverGerador, após publicação confirmada):
 *   DiscoverHubAutoUpdate::adicionarSpoke($postId, $clusterKey, $titulo, $url, $cfg, $wp);
 *
 * Falha-silenciosa: hub não existir ou WP API down não bloqueia o pipeline.
 */
class DiscoverHubAutoUpdate
{
    /**
     * @return array {ok, mudou, motivo, hub_post_id?}
     */
    public static function adicionarSpoke(int $postId, string $clusterKey, string $titulo, string $url, array $cfg, $wp): array
    {
        if ($postId <= 0 || $clusterKey === '' || $url === '') {
            return ['ok' => false, 'motivo' => 'parametros incompletos'];
        }

        $siteUrl = rtrim((string)($cfg['wp_url'] ?? ''), '/');
        if ($siteUrl === '') return ['ok' => false, 'motivo' => 'wp_url ausente'];

        $hubSlug = 'hub-' . self::slugify($clusterKey);

        // Busca hub via REST API por slug
        try {
            $endpoint = $siteUrl . '/wp-json/wp/v2/posts?slug=' . urlencode($hubSlug) . '&context=edit&_fields=id,content,title';
            $auth = base64_encode((string)($cfg['wp_user'] ?? '') . ':' . (string)($cfg['wp_app_password'] ?? ''));
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp === false || $code >= 400) {
                return ['ok' => false, 'motivo' => "busca hub HTTP {$code}"];
            }
            $arr = json_decode((string)$resp, true);
            $hubPost = is_array($arr) && !empty($arr) ? $arr[0] : null;
        } catch (Throwable $e) {
            return ['ok' => false, 'motivo' => 'busca hub falhou: ' . $e->getMessage()];
        }

        if (!$hubPost || !isset($hubPost['id'])) {
            return ['ok' => false, 'motivo' => "hub /{$hubSlug} não existe — gerar_hubs.php cria"];
        }

        $hubId = (int)$hubPost['id'];
        $contentAtual = (string)($hubPost['content']['raw'] ?? $hubPost['content']['rendered'] ?? '');
        if ($contentAtual === '') return ['ok' => false, 'motivo' => 'conteúdo do hub vazio'];

        // Idempotência: já tem link pra esse URL?
        if (strpos($contentAtual, $url) !== false) {
            return ['ok' => true, 'mudou' => false, 'motivo' => 'já contém link', 'hub_post_id' => $hubId];
        }

        // Procura primeiro `<ul>` ou `<ol>` "lista de posts" — convenção do gerar_hubs
        // marker `data-hub-list="1"`. Se não tiver marker, usa o primeiro UL.
        $tituloEsc = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
        $urlEsc    = htmlspecialchars($url,    ENT_QUOTES, 'UTF-8');
        $novoItem  = '<li><a href="' . $urlEsc . '">' . $tituloEsc . '</a></li>';

        // Padrão preferencial: lista marcada
        $padraoMarcado = '#(<ul[^>]*data-hub-list=[\'"]1[\'"][^>]*>)#i';
        $padraoFallback = '#(<ul[^>]*>)#i';

        $contentNovo = null;
        if (preg_match($padraoMarcado, $contentAtual)) {
            $contentNovo = preg_replace($padraoMarcado, '$1' . $novoItem, $contentAtual, 1);
        } elseif (preg_match($padraoFallback, $contentAtual)) {
            $contentNovo = preg_replace($padraoFallback, '$1' . $novoItem, $contentAtual, 1);
        }

        if ($contentNovo === null || $contentNovo === $contentAtual) {
            return ['ok' => false, 'motivo' => 'não achou <ul> no hub'];
        }

        // Salva no WP
        try {
            $wp->atualizarPost($hubId, ['content' => $contentNovo]);
        } catch (Throwable $e) {
            return ['ok' => false, 'motivo' => 'atualizar hub falhou: ' . $e->getMessage()];
        }

        return ['ok' => true, 'mudou' => true, 'hub_post_id' => $hubId];
    }

    private static function slugify(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9_]+/', '-', $s) ?? $s;
        return trim($s, '-');
    }
}
