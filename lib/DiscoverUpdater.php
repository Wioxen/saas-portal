<?php
/**
 * Updater inteligente — Etapa 10 do portal.md.
 *
 * Executar diariamente para posts com +48h:
 *   - Sanear termos temporais ("hoje", "ontem", "esta semana")
 *   - Adicionar seção "O que mudou recentemente" (2-3 parágrafos)
 *   - Opcional: ajustar título se intenção mudou
 *   - Atualizar ultimo_update no DB
 */
class DiscoverUpdater
{
    private array $cfg;
    private DiscoverDb $db;
    private Wordpress $wp;
    private Scraper $scraper;
    private TrendsArticles $artigos;
    private Serper $serper;
    private string $claudeApiKey;
    private string $claudeModel;

    public function __construct(array $cfg, DiscoverDb $db)
    {
        $this->cfg     = $cfg;
        $this->db      = $db;
        $this->wp      = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        $this->scraper = new Scraper($cfg['user_agent'], $cfg['scrape_timeout'] ?? 15);
        $this->serper  = new Serper($cfg['serper_api_key']);
        $this->artigos = new TrendsArticles($this->serper, $this->scraper, $cfg['user_agent']);
        $this->claudeApiKey = $cfg['anthropic_api_key'];
        $this->claudeModel  = $cfg['anthropic_model'];
    }

    /**
     * Lista registros publicados elegíveis para update.
     * @param int $horasMin Tempo mínimo desde a última atualização
     * @return array
     */
    public function elegiveis(string $site, int $horasMin = 48, int $limite = 20): array
    {
        $records = $this->db->all(['site' => $site, 'status' => 'publicado']);
        $agora = time();
        $out = [];
        foreach ($records as $r) {
            $ref = $r['ultimo_update'] ?? $r['publicado_em'] ?? null;
            if (!$ref) continue;
            $ts = strtotime($ref);
            if ($ts === false) continue;
            $horas = ($agora - $ts) / 3600;
            if ($horas >= $horasMin) {
                $r['_idade_horas'] = (int)round($horas);
                $out[] = $r;
            }
        }
        usort($out, fn($a,$b) => ($b['_idade_horas'] ?? 0) <=> ($a['_idade_horas'] ?? 0));
        return array_slice($out, 0, $limite);
    }

    /**
     * Atualiza um post: saneia temporais, acrescenta "O que mudou recentemente", atualiza DB.
     */
    public function atualizar(array $trend): array
    {
        $id      = (int)($trend['id'] ?? 0);
        $termo   = (string)($trend['termo'] ?? '');
        $postUrl = (string)($trend['url_post'] ?? '');
        if ($termo === '' || $postUrl === '') {
            return ['ok' => false, 'erro' => 'Termo ou URL do post ausente'];
        }

        // Extrai post_id do edit_url
        if (!preg_match('/post=(\d+)/', $postUrl, $m)) {
            return ['ok' => false, 'erro' => 'post_id não extraído da url_post'];
        }
        $postId = (int)$m[1];

        // 1) Busca post atual no WP
        try {
            $post = $this->wp->getPost($postId);
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao ler post: ' . $e->getMessage()];
        }
        $tituloAtual  = $post['title']['raw'] ?? $post['title']['rendered'] ?? '';
        $contentAtual = $post['content']['raw'] ?? $post['content']['rendered'] ?? '';
        if ($contentAtual === '') return ['ok' => false, 'erro' => 'Post sem conteúdo'];

        // 2) Busca notícias frescas (cache TTL do TrendsArticles = 1h — provavelmente expirou)
        $lista = $this->artigos->listar($termo, 5);
        $urlsFrescas = array_values(array_filter(array_column($lista, 'url_real')));
        if (empty($urlsFrescas)) {
            return ['ok' => false, 'erro' => 'Sem notícias frescas disponíveis para o termo'];
        }

        // 3) Scrape defensivo — precisa ≥ 2 com conteúdo real pra saber O QUE MUDOU
        $trechosFrescos = [];
        foreach ($urlsFrescas as $url) {
            try {
                $f = $this->scraper->fetch($url);
                if (!empty($f['content']['paragraphs'])) {
                    $texto = implode("\n\n", array_slice($f['content']['paragraphs'], 0, 8));
                    if (strlen($texto) >= 400) {
                        $trechosFrescos[] = [
                            'url'    => $url,
                            'titulo' => $f['meta']['title'] ?? '',
                            'fonte'  => $f['meta']['site_name'] ?? parse_url($url, PHP_URL_HOST),
                            'data'   => $f['meta']['published'] ?? '',
                            'texto'  => $texto,
                        ];
                    }
                }
            } catch (Throwable $e) {}
            if (count($trechosFrescos) >= 3) break;
        }

        if (count($trechosFrescos) < 2) {
            return ['ok' => false, 'erro' => 'Fontes frescas insuficientes (' . count($trechosFrescos) . ')'];
        }

        // 4) Pede ao Claude o HTML atualizado
        $resultado = $this->chamarClaude($termo, $tituloAtual, $contentAtual, $trechosFrescos);
        if (!$resultado['ok']) return $resultado;

        $novoTitulo  = trim($resultado['titulo'] ?? $tituloAtual);
        $novoContent = trim($resultado['content_html'] ?? '');
        if ($novoContent === '' || strlen($novoContent) < strlen($contentAtual) * 0.8) {
            return ['ok' => false, 'erro' => 'Content retornado muito curto — possivelmente truncado'];
        }

        // 5) Atualiza no WP
        try {
            $this->wp->atualizarPost($postId, [
                'title'   => $novoTitulo,
                'content' => $novoContent,
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao salvar no WP: ' . $e->getMessage()];
        }

        // 6) DB update
        if ($id > 0) {
            $this->db->updateStatus($id, 'publicado', [
                'titulo' => $novoTitulo,
                'ultimo_update' => date('Y-m-d H:i:s'),
            ]);
        }

        return [
            'ok'        => true,
            'post_id'   => $postId,
            'titulo'    => $novoTitulo,
            'fontes'    => count($trechosFrescos),
            'tamanho_antes' => strlen($contentAtual),
            'tamanho_depois'=> strlen($novoContent),
        ];
    }

    /**
     * Chama Claude diretamente com prompt focado em atualização.
     */
    private function chamarClaude(string $termo, string $tituloAtual, string $contentAtual, array $trechosFrescos): array
    {
        $hoje = date('d/m/Y');
        $dias = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
        $diaSemana = $dias[(int)date('w')];

        $fontesBloco = '';
        foreach ($trechosFrescos as $i => $f) {
            $fontesBloco .= "\n--- FONTE " . ($i+1) . " (" . $f['fonte'] . ") ---\n";
            if ($f['data']) $fontesBloco .= "Publicado: {$f['data']}\n";
            if ($f['titulo']) $fontesBloco .= "Título: {$f['titulo']}\n";
            $fontesBloco .= "Texto:\n" . mb_substr($f['texto'], 0, 2500) . "\n";
        }

        $manifestoPath = dirname(__DIR__) . '/prompts/manifesto_editorial.md';
        $manifesto = is_file($manifestoPath) ? (string)file_get_contents($manifestoPath) : '';

        $system = ($manifesto !== '' ? "═══ MANIFESTO EDITORIAL ═══\n{$manifesto}\n═══ FIM ═══\n\n" : '')
            . "MODO: ATUALIZAÇÃO INTELIGENTE DE POST PUBLICADO\n\n"
            . "Tarefas obrigatórias:\n"
            . "1) Remover/reescrever termos temporais desatualizados no texto existente:\n"
            . "   - 'hoje', 'ontem', 'esta semana', 'agora há pouco', 'nesta data', 'nas últimas horas'\n"
            . "   - Substituir por referência factual (data absoluta quando couber) ou remover\n"
            . "2) Adicionar uma NOVA seção <h2>O que mudou recentemente</h2> antes do FAQ (ou no fim, se não houver FAQ):\n"
            . "   - 2-3 parágrafos factuais, baseados ESTRITAMENTE nas fontes frescas fornecidas\n"
            . "   - Cite fonte ao introduzir fato novo ('segundo a [fonte]...')\n"
            . "   - Zero conjectura, zero adjetivo vazio\n"
            . "3) Se a intenção do título ficou desatualizada (ex: 'onde assistir' pra evento que já passou), ajuste para intenção atual (ex: 'resultado e o que muda')\n"
            . "4) Preservar TODO o resto do conteúdo original — NÃO reescrever parágrafos antigos salvo para saneamento temporal\n"
            . "5) Content HTML válido com aspas SIMPLES em atributos (padrão do sistema)\n\n"
            . "DATA DE HOJE: {$hoje} ({$diaSemana})\n\n"
            . "Responda APENAS com JSON válido:\n"
            . '{"titulo": "<título atualizado>", "content_html": "<html completo atualizado em UMA linha>"}';

        $user = "TERMO-BASE: {$termo}\n\n"
              . "TÍTULO ATUAL:\n{$tituloAtual}\n\n"
              . "CONTEÚDO ATUAL (HTML):\n{$contentAtual}\n\n"
              . "FONTES FRESCAS (use para a seção 'O que mudou recentemente'):\n{$fontesBloco}";

        $payload = [
            'model' => $this->claudeModel,
            'max_tokens' => 8000,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->claudeApiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            return ['ok' => false, 'erro' => "Claude HTTP {$code}: " . mb_substr((string)$resp, 0, 400)];
        }
        $data = json_decode((string)$resp, true);
        $texto = $data['content'][0]['text'] ?? '';
        // Extrai o primeiro JSON
        if (preg_match('/\{[\s\S]*\}/', $texto, $m)) {
            $j = json_decode($m[0], true);
            if (is_array($j) && isset($j['content_html'])) {
                return ['ok' => true, 'titulo' => $j['titulo'] ?? '', 'content_html' => $j['content_html']];
            }
        }
        return ['ok' => false, 'erro' => 'JSON não extraído da resposta do Claude'];
    }
}
