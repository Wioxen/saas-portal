<?php
/**
 * DiscoverProductRanker — quando o termo da trend pede LISTA DE PRODUTOS,
 * busca os top vendidos REAIS na Amazon BR e injeta no contexto do Sonnet.
 *
 * Diferencial G6 do roadmap: substitui "Sonnet inventa 10 produtos" por
 * "Sonnet escreve em volta de 10 produtos REAIS com nome+preço+imagem corretos".
 *
 * Fluxo:
 *  1. detectarIntent($termo, $clusterKey)
 *      → null (não é trend de lista de produtos) → ranker NÃO atua
 *      → array {categoria, limite} → ranker atua
 *  2. obter($intent) → fetch via AmazonScraper, retorna {produtos, categoria}
 *  3. paraPromptContext($produtos) → bloco texto pro prompt do LLM
 *     + placeholder <!-- DISCOVER_TABELA_PRODUTOS --> que o LLM insere no HTML
 *  4. (pós-geração) paraTabelaHtml($produtos) → tabela HTML rica
 *     + substituirPlaceholder($html, $tabela) → injeta no lugar certo
 *
 * IMPORTANTE: detecção CONSERVADORA. Só dispara em termos explícitos de lista
 * de produtos. Falso positivo (artigo INSS virar tabela de presentes) é desastre.
 */

require_once __DIR__ . '/AmazonScraper.php';

class DiscoverProductRanker
{
    /** Placeholder que o LLM coloca no HTML; substituído pós-geração pela tabela real. */
    public const PLACEHOLDER = '<!-- DISCOVER_TABELA_PRODUTOS -->';

    /**
     * URL Amazon padrão pra fallback CTA quando ranker NÃO bate (memória feedback_produtos_amazon_afiliado).
     * Ranker em si gera URLs individuais (https://amazon.com.br/dp/{ASIN}).
     */
    public const FALLBACK_FIXO = 'https://amzn.to/4ckOgUc';

    /**
     * Detecta se o termo pede lista de produtos. CONSERVADOR — só em termos explícitos.
     * Retorna null (não atua) ou array {categoria, limite}.
     *
     * @param string $termo termo da trend (ex: "10 ideias de presente dia das mães")
     * @param string $clusterKey cluster_detect.key (ex: "lifestyle_consumo")
     */
    public static function detectarIntent(string $termo, string $clusterKey = ''): ?array
    {
        $t = mb_strtolower($termo, 'UTF-8');

        // (1) PRÉ-CHECK: gatilho de lista. Se nada bater aqui, NÃO ATUA.
        $gatilhos = [
            '/\b(top|melhores?|mais\s+vendidos?|ranking)\s+\d+\b/u',  // "top 10", "5 melhores", "mais vendidos 20"
            '/\b\d+\s+(melhores?|ideias?|kits?|opc[oõ]es)\b/u',        // "10 melhores", "5 ideias", "7 kits"
            '/\b\d+\s+(?:\S+\s+){1,3}(mais\s+vendidos?|barat[ao]s?|melhores?)\b/u', // "8 brinquedos mais vendidos", "5 fones bluetooth mais vendidos", "10 cadeiras baratas"
            '/\b(presentes?|ideias?\s+de\s+presente|kits?)\s+(de|para|pra)\b/u', // "presentes para dia das mães"
            '/\b(o\s+que\s+comprar|comprar\s+(no|para|pra)\s+)/u',     // "o que comprar dia dos pais"
            '/\b(at[eé]\s+r?\$?\s*\d+|abaixo\s+de\s+r?\$?\s*\d+|menos\s+de\s+r?\$?\s*\d+)\b/u', // "até R$ 100"
            '/\b(produtos?\s+mais\s+vendidos?|achados?\s+da\s+amazon|achados?\s+amazon)\b/u',
        ];
        $bateu = false;
        foreach ($gatilhos as $rx) {
            if (preg_match($rx, $t)) { $bateu = true; break; }
        }
        if (!$bateu) return null;

        // (2) Cluster restritivo — só clusters de SHOPPING podem virar tabela.
        // Bloqueia termos como "10 melhores presidentes da história" (curiosidades_geral fora).
        $clustersPermitidos = ['lifestyle_consumo', 'comidas_bebidas', 'tecnologia', 'esportes'];
        if ($clusterKey !== '' && !in_array($clusterKey, $clustersPermitidos, true)) {
            return null;
        }

        // (3) Mapeamento termo → categoria Amazon. Ordem de prioridade: keyword específica.
        $categoria = self::mapearCategoria($t, $clusterKey);
        if ($categoria === null) return null;

        // (4) Limite — extrai do termo se mencionar número, default 10
        $limite = 10;
        if (preg_match('/\b(\d{1,2})\s+(melhores?|ideias?|kits?|opc[oõ]es|mais\s+vendidos?)\b/u', $t, $m)) {
            $n = (int)$m[1];
            if ($n >= 3 && $n <= 15) $limite = $n;
        }

        return [
            'categoria' => $categoria,
            'limite'    => $limite,
            'termo'     => $termo,
            'cluster'   => $clusterKey,
        ];
    }

    /**
     * Mapeia termo → categoria Amazon BR.
     * Heurística por keyword. Se nada bater, fallback por cluster.
     */
    private static function mapearCategoria(string $termoLow, string $clusterKey): ?string
    {
        $regras = [
            // toys: presentes infantis, brinquedos
            'toys' => ['/\b(crian[çc]a|crian[çc]as|filho|filha|infantil|brinquedo|brinquedos|beb[eê]|kids?|menino|menina|dia\s+das\s+crian[çc]as)\b/u'],

            // beauty: perfume, maquiagem, skincare
            'beauty' => ['/\b(perfumes?|maquiagens?|skincare|beleza|cosm[eé]tic[oa]s?|kits?\s+beleza|presentes?\s+(para|pra)\s+(mulher|namorada|m[ãa]e))\b/u'],

            // electronics: tech, eletrônicos
            'electronics' => ['/\b(tecnologia|eletr[oô]nico|fone|fones|notebook|celular|smartphone|smartwatch|tablet|tv|televis[ãa]o|game|gamer|console|controle)\b/u'],

            // home: cozinha, casa, decoração
            'home' => ['/\b(cozinha|casa|decora[çc][ãa]o|panela|panelas|m[óo]veis|sala|quarto|utens[íi]lios|presente\s+(para|pra)\s+(m[ãa]e|esposa|sogra)|dia\s+das\s+m[ãa]es)\b/u'],

            // sports: fitness, academia, esportes
            'sports' => ['/\b(esporte|esportes|fitness|academia|musculac[ãa]o|corrida|tenis|t[eê]nis|camisa(\s+(de\s+time|do\s+(flamengo|corinthians|palmeiras|s[ãa]o\s+paulo|santos|gr[eê]mio|atl[eé]tico|cruzeiro|fluminense|botafogo)))?|brasileir[ãa]o)\b/u'],

            // books: leitura
            'books' => ['/\b(livro|livros|leitura|romance|literatura|autoajuda|auto-ajuda)\b/u'],
        ];

        foreach ($regras as $cat => $regexes) {
            foreach ($regexes as $rx) {
                if (preg_match($rx, $termoLow)) return $cat;
            }
        }

        // Fallback por cluster (mais largo)
        $porCluster = [
            'lifestyle_consumo' => 'home',
            'comidas_bebidas'   => 'home',
            'tecnologia'        => 'electronics',
            'esportes'          => 'sports',
        ];
        return $porCluster[$clusterKey] ?? null;
    }

    /**
     * Busca produtos REAIS via AmazonScraper.
     * @return array {produtos: [...], categoria: string, ok: bool, erro?: string}
     */
    public function obter(array $intent): array
    {
        $categoria = (string)($intent['categoria'] ?? '');
        $limite    = (int)($intent['limite'] ?? 10);

        try {
            $scraper = new AmazonScraper();
            $produtos = $scraper->obterBestsellers($categoria, $limite);
            if (count($produtos) < 3) {
                return ['ok' => false, 'erro' => 'amazon_scrape_insuficiente', 'count' => count($produtos), 'categoria' => $categoria];
            }
            return ['ok' => true, 'produtos' => $produtos, 'categoria' => $categoria];
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => $e->getMessage(), 'categoria' => $categoria];
        }
    }

    /**
     * Bloco textual pro prompt do LLM. Força o modelo a usar NOMES REAIS dos produtos
     * e inserir o placeholder no HTML pro pós-processamento substituir por tabela.
     */
    public static function paraPromptContext(array $produtos, string $categoria): string
    {
        $linhas = [];
        foreach ($produtos as $i => $p) {
            $pos = $i + 1;
            $nome = (string)($p['nome'] ?? '');
            $preco = (string)($p['preco_brl'] ?? '');
            $linhas[] = "  {$pos}. {$nome}" . ($preco !== '' ? "  ({$preco})" : '');
        }
        $listaTxt = implode("\n", $linhas);
        $catLabel = self::categoriaLabel($categoria);
        $hoje = date('d/m/Y');

        return "═══ PRODUTOS REAIS — Top {$catLabel} mais vendidos na Amazon Brasil ({$hoje}) ═══\n\n"
             . "Estes são os produtos REAIS extraídos hoje da Amazon BR. Você DEVE escrever o artigo\n"
             . "em volta deles. NÃO INVENTE produtos, marcas ou modelos diferentes destes:\n\n"
             . $listaTxt . "\n\n"
             . "REGRAS OBRIGATÓRIAS pra usar a lista:\n"
             . "1. Cada produto da lista vira um <h3> próprio com o NOME EXATO (sem encurtar marca/modelo).\n"
             . "2. Em cada item escreva 2-4 frases: pra quem serve, qual problema resolve, quando vale a pena.\n"
             . "3. NÃO mencione PREÇO NO TEXTO — preço aparece em tabela visual gerada à parte.\n"
             . "4. NÃO ENVENTE links — não escreva <a href> apontando pra Amazon. A tabela visual\n"
             . "   já tem botão de compra individual pra cada produto.\n"
             . "5. INSIRA EXATAMENTE este placeholder UMA vez no HTML, logo APÓS o <h2> principal\n"
             . "   (antes dos H3s individuais dos produtos):\n"
             . "       " . self::PLACEHOLDER . "\n"
             . "   Esse marker vai ser substituído por uma tabela com imagem, preço e botão.\n"
             . "6. O artigo deve respeitar a contagem 600-700 palavras incluindo a lista.\n";
    }

    /**
     * Tabela HTML rica pra substituir o placeholder.
     * Aspas simples nos atributos (CLAUDE.md regra). Design responsivo inline.
     *
     * @param ?PrettyLinks $prettyLinks Se fornecido, cada produto vira `/go/produto-{ASIN}`
     *                                   apontando pra Amazon. Quando user cadastrar tag de afiliado,
     *                                   basta editar Pretty Links no plugin WP (sem reescrever posts).
     *                                   Em falha pra um produto, fallback é FALLBACK_FIXO.
     */
    public static function paraTabelaHtml(array $produtos, string $categoria, ?PrettyLinks $prettyLinks = null, ?string $tagAfiliado = null): string
    {
        $catLabel = self::categoriaLabel($categoria);
        $hoje = date('d/m/Y');
        $linhasHtml = [];

        foreach ($produtos as $i => $p) {
            $pos = $i + 1;
            $asin = (string)($p['asin'] ?? '');
            $nome = htmlspecialchars((string)($p['nome'] ?? ''), ENT_QUOTES, 'UTF-8');
            $img  = htmlspecialchars((string)($p['img'] ?? ''), ENT_QUOTES, 'UTF-8');
            $preco = htmlspecialchars((string)($p['preco_brl'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $url  = self::resolverUrl($asin, (string)($p['nome'] ?? ''), $prettyLinks, $tagAfiliado);
            $url  = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

            $linhasHtml[] = "<tr>"
                . "<td style='text-align:center;font-weight:700;font-size:18px;color:#f59e0b;padding:12px 8px;width:48px'>#{$pos}</td>"
                . "<td style='padding:12px 8px;width:90px'><img src='{$img}' alt='{$nome}' style='width:80px;height:80px;object-fit:contain;display:block;background:#fff;border-radius:6px' loading='lazy'></td>"
                . "<td style='padding:12px 12px;font-size:14px;line-height:1.4;color:#111'>{$nome}</td>"
                . "<td style='padding:12px 8px;text-align:right;font-weight:700;color:#15803d;white-space:nowrap;font-size:15px'>{$preco}</td>"
                . "<td style='padding:12px 8px;text-align:center'><a href='{$url}' target='_blank' rel='nofollow sponsored noopener' style='display:inline-block;padding:8px 14px;background:#f59e0b;color:#fff;text-decoration:none;border-radius:6px;font-weight:700;font-size:13px;white-space:nowrap'>Ver na Amazon</a></td>"
                . "</tr>";
        }

        $linhas = implode("\n", $linhasHtml);

        return "<div class='discover-product-ranker' style='margin:24px 0;padding:18px 16px;background:#fff;border:2px solid #fde68a;border-radius:12px;box-shadow:0 2px 12px rgba(245,158,11,.08);font-family:sans-serif'>"
            . "<div style='display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px'>"
            . "<strong style='font-size:16px;color:#111'>Top {$catLabel} mais vendidos na Amazon Brasil</strong>"
            . "<span style='font-size:12px;color:#666'>Atualizado em {$hoje}</span>"
            . "</div>"
            . "<div style='overflow-x:auto'>"
            . "<table style='width:100%;border-collapse:collapse;font-family:inherit'>"
            . "<thead><tr style='background:#fef3c7;color:#78350f'>"
            . "<th style='padding:10px 8px;text-align:center;font-size:13px'>Pos.</th>"
            . "<th style='padding:10px 8px;text-align:left;font-size:13px'>Foto</th>"
            . "<th style='padding:10px 12px;text-align:left;font-size:13px'>Produto</th>"
            . "<th style='padding:10px 8px;text-align:right;font-size:13px'>Preço</th>"
            . "<th style='padding:10px 8px;text-align:center;font-size:13px'>Comprar</th>"
            . "</tr></thead>"
            . "<tbody>{$linhas}</tbody>"
            . "</table>"
            . "</div>"
            . "<p style='margin:10px 0 0;font-size:11px;color:#666;text-align:center'>"
            . "Preços e disponibilidade sujeitos a alteração. Como participante do programa de afiliados, "
            . "podemos receber comissão sobre compras qualificadas."
            . "</p>"
            . "</div>";
    }

    /**
     * Substitui o placeholder no HTML do artigo. Se LLM não inseriu o placeholder
     * (descumpriu a regra do prompt), insere após o primeiro </h2> como fallback.
     * Retorna ['html' => string, 'metodo' => 'placeholder|fallback_h2|nao_injetou'].
     */
    public static function substituirPlaceholder(string $html, string $tabelaHtml): array
    {
        if ($tabelaHtml === '') return ['html' => $html, 'metodo' => 'nao_injetou'];

        // Caminho feliz: placeholder existe
        if (strpos($html, self::PLACEHOLDER) !== false) {
            $novo = str_replace(self::PLACEHOLDER, $tabelaHtml, $html);
            return ['html' => $novo, 'metodo' => 'placeholder'];
        }

        // Fallback: insere após primeiro </h2>
        if (preg_match('#</h2>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            $novo = substr($html, 0, $pos) . "\n" . $tabelaHtml . "\n" . substr($html, $pos);
            return ['html' => $novo, 'metodo' => 'fallback_h2'];
        }

        // Pior caso: prepende
        return ['html' => $tabelaHtml . "\n" . $html, 'metodo' => 'fallback_topo'];
    }

    /**
     * URL Amazon individual com tag de afiliado (se fornecida).
     * Caller usa direto quando NÃO usa PrettyLinks (caminho desativado por decisão do user 2026-04-27).
     */
    public static function montarUrl(string $asin, ?string $tagAfiliado = null): string
    {
        if ($asin === '' || !preg_match('/^[A-Z0-9]{10}$/', $asin)) return self::FALLBACK_FIXO;
        $base = 'https://www.amazon.com.br/dp/' . $asin;
        if ($tagAfiliado !== null && $tagAfiliado !== '') {
            $base .= '?tag=' . urlencode($tagAfiliado);
        }
        return $base;
    }

    /**
     * Resolve URL final pro botão da tabela.
     *  - Se PrettyLinks fornecido (caminho default): tenta criar/buscar `/go/produto-{ASIN}` apontando
     *    pra Amazon. Quando user cadastrar Associates BR, edita Pretty Links no WP — sem reescrever posts.
     *  - Se PrettyLinks falhar (plugin off, REST 5xx) ou não fornecido: fallback FALLBACK_FIXO.
     */
    public static function resolverUrl(string $asin, string $nome, ?PrettyLinks $prettyLinks, ?string $tagAfiliado = null): string
    {
        if ($asin === '' || !preg_match('/^[A-Z0-9]{10}$/', $asin)) return self::FALLBACK_FIXO;

        $target = self::montarUrl($asin, $tagAfiliado);
        if ($prettyLinks === null) return self::FALLBACK_FIXO;

        $slug = 'produto-' . strtolower($asin);
        try {
            $pretty = $prettyLinks->criarOuBuscar($target, $slug, mb_substr($nome, 0, 80));
            if (is_string($pretty) && $pretty !== '') return $pretty;
        } catch (Throwable $e) {
            // Plugin Pretty Links offline ou REST falha — cai no fallback
        }
        return self::FALLBACK_FIXO;
    }

    private static function categoriaLabel(string $cat): string
    {
        return [
            'electronics' => 'eletrônicos',
            'home'        => 'produtos pra casa e cozinha',
            'toys'        => 'brinquedos infantis',
            'beauty'      => 'beleza e perfumaria',
            'sports'      => 'esportes e fitness',
            'books'       => 'livros',
        ][$cat] ?? $cat;
    }
}
