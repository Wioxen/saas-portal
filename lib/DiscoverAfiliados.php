<?php
/**
 * DiscoverAfiliados — matchmaker de ofertas de afiliado por trend.
 *
 * Fluxo:
 *  1. $ofertas = DiscoverAfiliados::listar()  — ler catálogo
 *  2. $oferta  = DiscoverAfiliados::matchear($trend)  — achar melhor match por cluster+dor+keywords
 *  3. gerar HTML do bloco CTA → injetar após H2 principal do artigo
 *  4. usuário clica → /go.php?s={slug} → DiscoverAfiliados::rastrearClique() → redireciona
 *
 * Matching (pesos aditivos):
 *  - cluster bate:        +10
 *  - dor dominante bate:   +5
 *  - keyword bate termo:   +2 cada (máx +6)
 *  - keyword bate relac.:  +1 cada (máx +3)
 *  - oferta inativa:       excluída
 *
 * Threshold mínimo: 5. Abaixo disso retorna null (prefere omitir CTA a injetar lixo).
 */

require_once __DIR__ . '/TrendsTaxonomia.php';

class DiscoverAfiliados
{
    private const MATCH_MIN = 5;
    private const MAX_CLIQUES_ARMAZENADOS = 5000;  // rotaciona logs pra não crescer infinito

    private static ?string $pathOfertas = null;
    private static ?string $pathClicks  = null;

    /** Inicialização implícita — aceita override pra testes. */
    public static function configurar(?string $pathOfertas = null, ?string $pathClicks = null): void
    {
        self::$pathOfertas = $pathOfertas ?? __DIR__ . '/../data/afiliados.json';
        self::$pathClicks  = $pathClicks  ?? __DIR__ . '/../data/afiliados_clicks.json';
    }

    private static function path(string $tipo): string
    {
        if (self::$pathOfertas === null) self::configurar();
        return $tipo === 'ofertas' ? self::$pathOfertas : self::$pathClicks;
    }

    /** Carrega o catálogo do disco. Lança se arquivo não existe ou é inválido. */
    private static function carregar(): array
    {
        $path = self::path('ofertas');
        if (!is_file($path)) {
            throw new RuntimeException("Catálogo de afiliados não encontrado em {$path}");
        }
        $raw = @file_get_contents($path);
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("JSON inválido em {$path}");
        }
        if (!isset($data['ofertas']) || !is_array($data['ofertas'])) {
            $data['ofertas'] = [];
        }
        if (!isset($data['next_id'])) {
            $data['next_id'] = count($data['ofertas']) + 1;
        }
        return $data;
    }

    /** Persiste catálogo com lock (evita race em requests concorrentes). */
    private static function salvar(array $data): void
    {
        $path = self::path('ofertas');
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('Falha a serializar JSON');
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException("Falha gravando {$tmp}");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Falha movendo {$tmp} → {$path}");
        }
    }

    /** Lista todas as ofertas (ativas + inativas). Ordem preservada do JSON. */
    public static function listar(bool $somenteAtivas = false): array
    {
        $data = self::carregar();
        $lista = $data['ofertas'];
        if ($somenteAtivas) {
            $lista = array_values(array_filter($lista, fn($o) => !empty($o['ativo'])));
        }
        return $lista;
    }

    /** Busca por id. Null se não achar. */
    public static function porId(int $id): ?array
    {
        foreach (self::listar() as $o) {
            if ((int)$o['id'] === $id) return $o;
        }
        return null;
    }

    /** Busca por slug. Null se não achar. */
    public static function porSlug(string $slug): ?array
    {
        foreach (self::listar() as $o) {
            if ($o['slug'] === $slug) return $o;
        }
        return null;
    }

    /**
     * Adiciona nova oferta. Gera id automático, normaliza slug (lowercase, sem acentos).
     * Retorna a oferta salva.
     */
    public static function adicionar(array $oferta): array
    {
        $data = self::carregar();
        $id = (int)($data['next_id'] ?? (count($data['ofertas']) + 1));
        $slug = self::slugify((string)($oferta['slug'] ?? $oferta['nome'] ?? "oferta-{$id}"));
        // Garante slug único
        $existentes = array_column($data['ofertas'], 'slug');
        $slugFinal = $slug;
        $i = 2;
        while (in_array($slugFinal, $existentes, true)) {
            $slugFinal = $slug . '-' . $i++;
        }

        $hoje = date('Y-m-d');
        $novo = [
            'id'              => $id,
            'slug'            => $slugFinal,
            'nome'            => trim((string)($oferta['nome'] ?? '')),
            'descricao_curta' => trim((string)($oferta['descricao_curta'] ?? '')),
            'url_afiliado'    => trim((string)($oferta['url_afiliado'] ?? '')),
            'plataforma'      => (string)($oferta['plataforma'] ?? 'manual'),
            'cluster'         => (string)($oferta['cluster'] ?? 'curiosidades_geral'),
            'dor_alvo'        => (array)($oferta['dor_alvo'] ?? ['qualquer']),
            'sites'           => array_values(array_filter((array)($oferta['sites'] ?? []))),  // vazio = todos
            'keywords_match'  => array_values(array_filter(array_map('trim', (array)($oferta['keywords_match'] ?? [])))),
            'cta_texto'       => trim((string)($oferta['cta_texto'] ?? 'Ver oferta')),
            'cta_emoji'       => (string)($oferta['cta_emoji'] ?? '👉'),
            'comissao_pct'    => (float)($oferta['comissao_pct'] ?? 0),
            'ticket_medio_brl'=> (float)($oferta['ticket_medio_brl'] ?? 0),
            'ativo'           => (bool)($oferta['ativo'] ?? true),
            'criado_em'       => $hoje,
            'atualizado_em'   => $hoje,
        ];
        $data['ofertas'][] = $novo;
        $data['next_id']   = $id + 1;
        self::salvar($data);
        return $novo;
    }

    /** Atualiza oferta existente por id. Campos não fornecidos preservados. */
    public static function atualizar(int $id, array $mudancas): ?array
    {
        $data = self::carregar();
        foreach ($data['ofertas'] as $idx => $o) {
            if ((int)$o['id'] !== $id) continue;
            // Preserva id + criado_em
            $mudancas['id'] = $id;
            unset($mudancas['criado_em']);
            $mudancas['atualizado_em'] = date('Y-m-d');
            // Normaliza slug se mudou
            if (isset($mudancas['slug'])) {
                $mudancas['slug'] = self::slugify($mudancas['slug']);
            }
            $data['ofertas'][$idx] = array_merge($o, $mudancas);
            self::salvar($data);
            return $data['ofertas'][$idx];
        }
        return null;
    }

    /** Remove oferta por id. Retorna true se removeu. */
    public static function remover(int $id): bool
    {
        $data = self::carregar();
        $antes = count($data['ofertas']);
        $data['ofertas'] = array_values(array_filter($data['ofertas'], fn($o) => (int)$o['id'] !== $id));
        if (count($data['ofertas']) === $antes) return false;
        self::salvar($data);
        return true;
    }

    /**
     * Encontra melhor oferta para um trend.
     * Retorna ['oferta' => array, 'score' => int, 'motivos' => string[]] ou null se nada bate.
     *
     * @param array $trend aceita ['termo'=>..., 'relacionados'=>[], 'cluster_detect'=>['key'=>..], 'pain'=>['dominante'=>..], 'site'=>...]
     *
     * Multi-site: se a oferta declara campo `sites: [...]`, só é elegível quando
     * o site do trend estiver na lista. Se `sites` ausente/vazio, oferta serve todos.
     */
    public static function matchear(array $trend): ?array
    {
        $termo = mb_strtolower((string)($trend['termo'] ?? ''), 'UTF-8');
        $relacionados = mb_strtolower(implode(' ', $trend['relacionados'] ?? []), 'UTF-8');
        $clusterKey = (string)($trend['cluster_detect']['key'] ?? $trend['cluster_key'] ?? '');
        $dorDominante = (string)($trend['pain']['dominante'] ?? '');
        $siteTrend = (string)($trend['site'] ?? '');

        $melhor = null;
        $melhorScore = 0;
        $melhorMotivos = [];

        foreach (self::listar(true) as $oferta) {
            // Filtro multi-site: se oferta declara 'sites' não vazio, só serve esses.
            $sitesOferta = (array)($oferta['sites'] ?? []);
            if (!empty($sitesOferta) && $siteTrend !== '' && !in_array($siteTrend, $sitesOferta, true)) {
                continue;
            }

            $score = 0;
            $motivos = [];

            // (1) Cluster match — peso 10
            if ($clusterKey !== '' && $oferta['cluster'] === $clusterKey) {
                $score += 10;
                $motivos[] = "cluster=" . TrendsTaxonomia::labelCurto($clusterKey);
            }

            // (2) Dor alvo — peso 5 (também aceita 'qualquer' como coringa fraco +1)
            $dorAlvo = (array)($oferta['dor_alvo'] ?? []);
            if ($dorDominante !== '' && in_array($dorDominante, $dorAlvo, true)) {
                $score += 5;
                $motivos[] = "dor={$dorDominante}";
            } elseif (in_array('qualquer', $dorAlvo, true)) {
                $score += 1;
                $motivos[] = "dor=qualquer(coringa)";
            }

            // (3) Keywords — peso 2 no termo (máx 6), peso 1 em relacionados (máx 3)
            $kwTermoHits = 0;
            $kwRelacHits = 0;
            foreach ((array)($oferta['keywords_match'] ?? []) as $kw) {
                $kwLow = mb_strtolower((string)$kw, 'UTF-8');
                if ($kwLow === '') continue;
                if (preg_match('/\b' . preg_quote($kwLow, '/') . '\b/iu', $termo)) {
                    $kwTermoHits++;
                } elseif ($relacionados !== '' && preg_match('/\b' . preg_quote($kwLow, '/') . '\b/iu', $relacionados)) {
                    $kwRelacHits++;
                }
            }
            $pontosTermo = min(6, $kwTermoHits * 2);
            $pontosRelac = min(3, $kwRelacHits);
            if ($pontosTermo > 0) { $score += $pontosTermo; $motivos[] = "kw_termo={$kwTermoHits}"; }
            if ($pontosRelac > 0) { $score += $pontosRelac; $motivos[] = "kw_rel={$kwRelacHits}"; }

            if ($score > $melhorScore) {
                $melhorScore = $score;
                $melhor = $oferta;
                $melhorMotivos = $motivos;
            }
        }

        if ($melhor === null || $melhorScore < self::MATCH_MIN) return null;
        return [
            'oferta'  => $melhor,
            'score'   => $melhorScore,
            'motivos' => $melhorMotivos,
        ];
    }

    /**
     * Registra um clique no rastreamento. Anonimiza o IP via sha1(ip+ua+salt).
     * Rotaciona se passar de MAX_CLIQUES_ARMAZENADOS (mantém só os últimos).
     */
    public static function rastrearClique(string $slug, array $context = []): void
    {
        $path = self::path('clicks');
        if (!is_file($path)) {
            $data = ['eventos' => []];
        } else {
            $data = json_decode((string)@file_get_contents($path), true) ?: ['eventos' => []];
            if (!isset($data['eventos']) || !is_array($data['eventos'])) $data['eventos'] = [];
        }

        $ip = $context['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = $context['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $salt = 'discover_afiliados_2026';
        $ipHash = substr(sha1($ip . '|' . $ua . '|' . $salt), 0, 12);

        $data['eventos'][] = [
            'slug'     => $slug,
            'ts'       => date('c'),
            'ip_hash'  => $ipHash,
            'referer'  => (string)($context['referer'] ?? ($_SERVER['HTTP_REFERER'] ?? '')),
            'trend_id' => (int)($context['trend_id'] ?? 0),
            'post_id'  => (int)($context['post_id'] ?? 0),
        ];

        // Rotação: mantém só os últimos N
        if (count($data['eventos']) > self::MAX_CLIQUES_ARMAZENADOS) {
            $data['eventos'] = array_slice($data['eventos'], -self::MAX_CLIQUES_ARMAZENADOS);
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @rename($tmp, $path);
    }

    /** Lê eventos de clique (opcionalmente filtrados por slug ou janela de dias). */
    public static function listarCliques(?string $slugFiltro = null, ?int $ultimosDias = null): array
    {
        $path = self::path('clicks');
        if (!is_file($path)) return [];
        $data = json_decode((string)@file_get_contents($path), true);
        $eventos = $data['eventos'] ?? [];

        if ($slugFiltro !== null) {
            $eventos = array_values(array_filter($eventos, fn($e) => ($e['slug'] ?? '') === $slugFiltro));
        }
        if ($ultimosDias !== null) {
            $corte = time() - ($ultimosDias * 86400);
            $eventos = array_values(array_filter($eventos, fn($e) => strtotime($e['ts'] ?? '') >= $corte));
        }
        return $eventos;
    }

    /** Agrega cliques por slug nos últimos N dias. Retorna [slug => total]. */
    public static function cliquesPorOferta(int $ultimosDias = 7): array
    {
        $eventos = self::listarCliques(null, $ultimosDias);
        $contagem = [];
        foreach ($eventos as $e) {
            $slug = $e['slug'] ?? '';
            if ($slug === '') continue;
            $contagem[$slug] = ($contagem[$slug] ?? 0) + 1;
        }
        arsort($contagem);
        return $contagem;
    }

    /** Normaliza string para slug. */
    private static function slugify(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/[áàâã]/u', 'a', $s) ?? $s;
        $s = preg_replace('/[éêè]/u', 'e', $s) ?? $s;
        $s = preg_replace('/[íîì]/u', 'i', $s) ?? $s;
        $s = preg_replace('/[óôõò]/u', 'o', $s) ?? $s;
        $s = preg_replace('/[úûù]/u', 'u', $s) ?? $s;
        $s = preg_replace('/[ç]/u', 'c', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        return trim($s, '-') ?: ('oferta-' . bin2hex(random_bytes(3)));
    }
}
