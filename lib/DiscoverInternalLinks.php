<?php
/**
 * Backlinks internos CONTEXTUAIS (diferentes do cluster).
 *
 * Lógica:
 *  1. Extrai os principais termos do artigo sendo gerado (termo seed + H2s + relacionados)
 *  2. Pra cada termo, busca posts já publicados no WP via buscarRelacionados
 *  3. Injeta links INLINE no corpo do texto (dentro de <p>), em ocorrências naturais
 *  4. Evita duplicar posts já linkados (cluster + "Veja também" + outros inline)
 *  5. Limita total de links extras pra não poluir (default: 3)
 *
 * Integração: chamar após DiscoverCluster::interligar (pra ter lista de já-linkados).
 */
class DiscoverInternalLinks
{
    private Wordpress $wp;
    private int $maxLinks;

    /** Posições (índices na lista de candidatos) já usadas — pra distribuir links pelo corpo. */
    private array $indicesUsados = [];

    /** Set de termos que vieram da lista SEMÂNTICA do cluster (pulam filtro anti-cross-nicho). */
    private array $termosSemanticosCluster = [];

    /** Verbos que, quando precedem o termo, viram parte da âncora (leitura editorial natural). */
    private static array $verbosAncora = [
        'evitar','cair','perder','receber','conseguir','pedir','solicitar','garantir','consultar',
        'acessar','fazer','seguir','verificar','checar','consultar','pagar','sacar','baixar',
        'aprender','entender','descobrir','ficar','cair\s+na','cair\s+no','escapar',
        'driblar','fugir','fugir\s+da','fugir\s+do','driblar\s+a','driblar\s+o',
    ];

    /** Stopwords que nunca viram âncora de link (muito genéricas). */
    private static array $stopwords = [
        'como','qual','quando','onde','quem','por','que','para','ainda','muito','também',
        'você','sua','seu','suas','seus','este','esta','estes','estas','esse','essa',
        'isso','aquilo','aqui','lá','mais','menos','agora','hoje','ontem','antes','depois',
        'sempre','nunca','jamais','porque','pois','entre','sobre','sem','com','em',
        'de','do','da','dos','das','no','na','nos','nas','pelo','pela','pelos','pelas',
        'um','uma','uns','umas','os','as','o','a','e','ou','mas','se','já',
    ];

    /** Palavras genéricas de portal — têm match fraco (não servem como âncora única). */
    private static array $genericos = [
        // shopping
        'comprar','vender','melhor','melhores','pior','piores','guia','dicas','tudo',
        'barato','baratos','caro','caros','top','ranking','avaliação','review','teste',
        'novo','nova','novos','novas','veja','confira','saiba','entenda','descubra',
        // datas
        '2024','2025','2026','2027','ano','mês','dia',
        // educação/governo (qualquer post sobre vagas/cursos/programas tem essas palavras —
        // sem qualificador específico = backlink cross-nicho. Bug 2026-04-27: termo "prazo"
        // sozinho linkava artigo Enem isenção pra "Vestibular de Gastronomia").
        'prazo','prazos','inscrição','inscrições','inscricao','inscricoes',
        'edital','editais','vagas','vaga','aberta','abertas','aberto','abertos',
        'oferta','ofertas','encerra','encerramento','encerram',
        'gratuito','gratuita','gratuitos','gratuitas','grátis','gratis',
        'processo','seletivo','seletiva','seletivos','seletivas',
        'candidato','candidatos','candidata','candidatas',
        'calendário','calendario','data','datas','período','periodo',
        'taxa','taxas','valor','valores','custo','custos','pagamento','pagamentos',
        'pedido','pedidos','solicitação','solicitacao','solicitações','solicitacoes',
        'documento','documentos','requisito','requisitos','passo','passos',
        'site','sites','portal','portais','página','pagina','páginas','paginas',
        'horário','horario','horas','minuto','minutos','semana','semanas',
        'oficial','oficiais','público','publico','públicos','publicos',
    ];

    /** Termo-seed do artigo atual (usado pra filtrar candidatos cross-nicho). Setado por setKeywordAncora(). */
    private string $keywordAncora = '';

    /** Modo strict: exige overlap entre título do candidato e keyword âncora.
     * Default true (sites multi-nicho). Em sites mono-nicho (cursosenac, leaodabarra)
     * setar false: TODOS os posts são do mesmo nicho, filtro extra elimina links bons.
     * Caso real #4995: site mono-nicho educação, post sobre Fatec não tinha siblings com
     * "Fatec" mas tinha 5 sobre EAD/Senac/IFSertão — ambos altamente relevantes pro leitor.
     */
    private bool $strictAnchor = true;

    public function __construct(Wordpress $wp, int $maxLinks = 3)
    {
        $this->wp = $wp;
        $this->maxLinks = $maxLinks;
    }

    /** Liga/desliga validação de anchor estrita (default true). */
    public function setStrictAnchor(bool $strict): void
    {
        $this->strictAnchor = $strict;
    }

    /** Define a palavra-chave principal do artigo — candidatos sem overlap com ela são rejeitados. */
    public function setKeywordAncora(string $kw): void
    {
        $this->keywordAncora = trim($kw);
    }

    /** Set os termos "semânticos do cluster" — eles PULAM o filtro de keyword-âncora específica. */
    public function setTermosSemanticos(array $termos): void
    {
        $norm = [];
        foreach ($termos as $t) $norm[mb_strtolower(trim((string)$t), 'UTF-8')] = true;
        $this->termosSemanticosCluster = $norm;
    }

    /**
     * Injeta até $maxLinks backlinks contextuais no HTML.
     *
     * @param string $html          HTML do post
     * @param array  $termos        Termos principais do artigo (seed + relacionados + H2)
     * @param array  $excluirPostIds IDs já linkados (cluster/veja também)
     * @param int    $currentPostId ID do post atual (exclui auto-link)
     * @return array ['html' => string, 'aplicados' => int, 'termos_linkados' => [...]]
     */
    public function injetar(string $html, array $termos, array $excluirPostIds, int $currentPostId): array
    {
        $termos = $this->limparTermos($termos);
        if (empty($termos)) return ['html' => $html, 'aplicados' => 0, 'termos_linkados' => []];

        $aplicados = 0;
        $termosLinkados = [];
        $excluir = array_merge([$currentPostId], $excluirPostIds);

        // Dedupe de URL: pré-carrega TODAS as URLs já presentes no HTML.
        // Evita linkar mesma URL 2x (inclui authority-links pré-existentes).
        $urlsJaLinkadas = [];
        if (preg_match_all('/<a\s[^>]*href=[\'"]([^\'"]+)[\'"]/i', $html, $hm)) {
            foreach ($hm[1] as $u) $urlsJaLinkadas[$u] = true;
        }

        // HARD LIMITS contra travamento (cada buscarRelacionados pode levar 5-30s)
        $MAX_TERMOS_TESTAR = 20;   // nunca mais de 20 chamadas WP
        $TIMEOUT_BUSCA     = 45;   // segundos TOTAL na fase de busca
        $inicioLoop = time();
        $termosTentados = 0;

        foreach ($termos as $termo) {
            if ($aplicados >= $this->maxLinks) break;
            if ($termosTentados >= $MAX_TERMOS_TESTAR) break;
            if ((time() - $inicioLoop) > $TIMEOUT_BUSCA) break; // protege contra WP lento

            // Termos semânticos do cluster (pré-validados por contexto editorial) pulam filtro.
            // Outros termos (de H2, strong, etc.) precisam bater com a keyword-âncora.
            // Em modo NÃO-strict (sites mono-nicho), pula filtro de anchor — qualquer termo
            // do post é considerado relevante pro nicho.
            $termoNorm = mb_strtolower(trim($termo), 'UTF-8');
            $ehSemantico = isset($this->termosSemanticosCluster[$termoNorm]) || !$this->strictAnchor;
            if (!$ehSemantico && $this->keywordAncora !== '' && !$this->termoCasaComAncora($termo)) continue;

            // Busca WP por este termo
            $termosTentados++;
            try {
                $candidatos = $this->wp->buscarRelacionados($termo, 3, $currentPostId);
            } catch (Throwable $e) { continue; }
            if (empty($candidatos)) continue;

            foreach ($candidatos as $c) {
                $pid   = (int)($c['id'] ?? 0);
                $link  = (string)($c['link'] ?? '');
                $title = (string)($c['title'] ?? '');
                if ($pid <= 0 || $link === '' || in_array($pid, $excluir, true)) continue;
                // Dedupe: URL já linkada no HTML (inclui authority-links ou prévia geração)
                if (isset($urlsJaLinkadas[$link])) continue;

                // VALIDAÇÃO DE RELEVÂNCIA 1: título do candidato casa com o termo buscado.
                // Em modo NÃO-strict (mono-nicho), confiar no fuzzy match do WP REST search:
                // se WP retornou, é relevante o suficiente. Validação textual elimina links
                // bons quando termo (ex: "Estadual de Educação Tecnológica") tem 0 overlap
                // com candidatos válidos do mesmo nicho ("SP Escola Superior de Teatro").
                if ($this->strictAnchor && !$this->tituloRelevante($title, $termo)) continue;

                // VALIDAÇÃO DE RELEVÂNCIA 2 (só aplica a termos NÃO-semânticos E em strict mode):
                // título do candidato também casa com a keyword-âncora do artigo.
                // Termos semânticos (do cluster ou n-gramas do próprio texto) pulam
                // esse filtro porque já foram validados por contexto editorial/textual.
                // Em sites mono-nicho (strictAnchor=false), TODOS os posts compartilham
                // o nicho — esse filtro elimina links bons (caso #4995 cursosenac).
                if (!$ehSemantico && $this->strictAnchor && $this->keywordAncora !== '' && !$this->tituloRelevante($title, $this->keywordAncora)) continue;

                // Tenta injetar: busca o termo no HTML (ocorrência natural em <p>, fora de <a>)
                $novoHtml = $this->injetarLinkNoBody($html, $termo, $link, $title);
                if ($novoHtml === null) continue;

                $html = $novoHtml;
                $excluir[] = $pid;
                $urlsJaLinkadas[$link] = true; // bloqueia futura duplicação
                $termosLinkados[] = [
                    'termo' => $termo, 'post_id' => $pid, 'titulo' => $title, 'link' => $link,
                ];
                $aplicados++;
                break; // só 1 link por termo
            }
        }

        return ['html' => $html, 'aplicados' => $aplicados, 'termos_linkados' => $termosLinkados];
    }

    /**
     * Extrai termos principais de um artigo (HTML + metadados do trend).
     * Combina: termo seed, relacionados, H2s, termos com <strong> longos, termos semânticos do cluster.
     *
     * @param array $trend    Metadados do trend (termo, relacionados, cluster_key opcional)
     */
    public static function extrairTermos(string $html, array $trend = []): array
    {
        $termos = [];
        // 1. Termo seed do trend
        if (!empty($trend['termo'])) $termos[] = (string)$trend['termo'];

        // 2. N-GRAMAS LONG-TAIL do corpo (PRIORIDADE MÁXIMA)
        //    Frases específicas do texto: "Gás do Povo", "Calendário do Bolsa Família", "Auxílio-Gás".
        //    Vão primeiro porque são ESPECÍFICAS e batem exatamente no texto do artigo.
        foreach (self::extrairNgramasSignificativos($html) as $ng) {
            $termos[] = $ng;
        }

        // 3. Palavras-chave significativas do termo-seed
        //    Ex: "Botijão de gás sobe pela 5ª semana" → ['botijão', 'gás', 'semana']
        if (!empty($trend['termo'])) {
            foreach (self::palavrasChaveDoTermo((string)$trend['termo']) as $pk) {
                $termos[] = $pk;
            }
        }

        // 4. Relacionados do trend
        foreach ((array)($trend['relacionados'] ?? []) as $r) {
            if (is_string($r) && trim($r) !== '') $termos[] = trim($r);
        }

        // 5. Termos semânticos do cluster editorial — fallback genérico
        if (!empty($trend['cluster_key'])) {
            require_once __DIR__ . '/DiscoverClusterMatcher.php';
            foreach (DiscoverClusterMatcher::termosSemanticos((string)$trend['cluster_key']) as $ts) {
                $termos[] = $ts;
            }
        }

        // 3. H2/H3 — pega primeiras 2-4 palavras que são substantivos prováveis
        if (preg_match_all('/<h[23][^>]*>([\s\S]*?)<\/h[23]>/i', $html, $m)) {
            foreach ($m[1] as $h) {
                $plain = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($h))));
                if ($plain === '') continue;
                // Remove "Pulo do Gato:", "Calendário:" etc. (metáforas iniciais)
                $plain = preg_replace('/^[^:]{0,30}:\s*/', '', $plain);
                // Pega primeiras 3-4 palavras
                $words = explode(' ', $plain);
                if (count($words) >= 2) {
                    $termos[] = trim(implode(' ', array_slice($words, 0, 4)));
                }
            }
        }
        // 4. Strong longos (conceitos, não números)
        if (preg_match_all('/<strong[^>]*>([\s\S]*?)<\/strong>/i', $html, $m)) {
            foreach ($m[1] as $s) {
                $plain = trim(strip_tags(html_entity_decode($s)));
                if ($plain === '') continue;
                // Ignora se começa com dígito (valor/data) ou é muito curto/longo
                if (preg_match('/^\d/', $plain) || mb_strlen($plain) < 8 || mb_strlen($plain) > 40) continue;
                $termos[] = $plain;
            }
        }
        return $termos;
    }

    /**
     * Confere se o TÍTULO do post candidato é relevante ao termo buscado.
     *
     * Exige: ≥ 1 palavra ESPECÍFICA (não stopword E não genérica de portal) do termo
     * aparecer no título. Palavras genéricas ("comprar", "melhor", "guia") não bastam
     * sozinhas — precisa de pelo menos 1 "substantivo-chave".
     */
    private function tituloRelevante(string $titulo, string $termo): bool
    {
        $norm = function(string $s): string {
            $s = mb_strtolower($s, 'UTF-8');
            $from = ['á','à','â','ã','é','ê','í','ó','ô','õ','ú','ç'];
            $to   = ['a','a','a','a','e','e','i','o','o','o','u','c'];
            return str_replace($from, $to, $s);
        };
        $palavras = preg_split('/\s+/', $norm($termo));
        // Separa palavras específicas (substantivos-chave) das genéricas
        $especificas = [];
        foreach ($palavras as $w) {
            if (mb_strlen($w) < 4) continue;
            if (in_array($w, self::$stopwords, true)) continue;
            if (in_array($w, self::$genericos, true)) continue;
            $especificas[] = $w;
        }
        // Termo só com palavras genéricas → rejeita (muito ambíguo pra backlinkar)
        if (empty($especificas)) return false;
        $tituloNorm = $norm($titulo);
        // Match: pelo menos 1 palavra ESPECÍFICA precisa estar no título COMO PALAVRA INTEIRA.
        // Antes usava strpos (substring) — bug observado: termo 'espera' matchava 'esperado'.
        // Caso real #733: anchor 'espera' virou backlink pra 'Jamerson e Neris... antes do
        // esperado no Vitória' (post sem relação com Cruzeiro x Atlético).
        // Word boundary com \b respeita início/fim de palavra. Aceita também variação singular/
        // plural via prefixo comum de 6+ chars (escola/escolas, dengue/dengues).
        foreach ($especificas as $w) {
            $wEsc = preg_quote($w, '/');
            if (preg_match('/\b' . $wEsc . '\b/u', $tituloNorm)) return true;
            // Variação plural/singular: aceita se palavra do título compartilha prefixo de 6+ chars
            // com o termo específico (ex: termo='ingresso' casa com 'ingressos')
            if (mb_strlen($w) >= 6) {
                $prefix = mb_substr($w, 0, 6);
                if (preg_match('/\b' . preg_quote($prefix, '/') . '\w*\b/u', $tituloNorm, $m)) {
                    // Aceita só se a palavra encontrada é "compatível" (não muito mais longa)
                    if (mb_strlen($m[0]) <= mb_strlen($w) + 4) return true;
                }
            }
        }
        return false;
    }

    /**
     * Verifica se o termo buscado compartilha pelo menos 1 palavra significativa com a keyword-âncora.
     * Impede search por H2 "Como se inscrever" puxar posts cross-nicho.
     */
    private function termoCasaComAncora(string $termo): bool
    {
        $norm = function(string $s): string {
            $s = mb_strtolower($s, 'UTF-8');
            $from = ['á','à','â','ã','é','ê','í','ó','ô','õ','ú','ç'];
            $to   = ['a','a','a','a','e','e','i','o','o','o','u','c'];
            return str_replace($from, $to, $s);
        };
        $especificas = function(string $s) use ($norm): array {
            $palavras = preg_split('/\s+/', $norm($s));
            $out = [];
            foreach ($palavras as $w) {
                if (mb_strlen($w) < 5) continue; // 5+ chars pra ser específico
                if (in_array($w, self::$stopwords, true)) continue;
                if (in_array($w, self::$genericos, true)) continue;
                $out[] = $w;
            }
            return $out;
        };
        $ancora = $especificas($this->keywordAncora);
        $busca  = $especificas($termo);
        if (empty($ancora) || empty($busca)) return false;
        // Se há qualquer palavra significativa em comum → casa
        foreach ($busca as $b) {
            foreach ($ancora as $a) {
                if ($b === $a) return true;
                // Também aceita prefixo comum de 6+ chars (singular/plural, etc)
                if (mb_strlen($a) >= 6 && mb_strlen($b) >= 6 && substr($a, 0, 6) === substr($b, 0, 6)) return true;
            }
        }
        return false;
    }

    /**
     * Extrai N-GRAMAS (2-4 palavras) significativos do corpo do artigo.
     * Foca em frases nominais compostas com palavras em maiúscula ou após preposição "do/da/de".
     * Ex: "Calendário do Bolsa Família", "Gás do Povo", "Auxílio-Gás 2026", "Conta gov.br".
     * Retorna até 15 candidatos ordenados por frequência.
     * Público pra que callers possam marcá-los como "seguros" (pulam filtro anti-cross-nicho).
     */
    public static function extrairNgramasSignificativos(string $html): array
    {
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace('/\s+/u', ' ', $texto);

        $candidatos = [];

        // Padrão 1: Capital + (de/do/da/dos/das) + Capital (+ Capital opcional) + [4 dígitos opcional]
        // Pega "Gás do Povo", "Calendário do Bolsa Família", "Auxílio Brasil 2026"
        if (preg_match_all('/\b(?:[A-ZÁÉÍÓÚÂÊÔÃÕÇ][\wá-ÿ]+)(?:\s+(?:de|do|da|dos|das|e)\s+[A-ZÁÉÍÓÚÂÊÔÃÕÇ][\wá-ÿ]+)+(?:\s+[A-ZÁÉÍÓÚÂÊÔÃÕÇ][\wá-ÿ]+)?(?:\s+\d{4})?/u', $texto, $mm)) {
            foreach ($mm[0] as $frase) {
                $frase = trim($frase);
                if (mb_strlen($frase, 'UTF-8') < 10 || mb_strlen($frase, 'UTF-8') > 60) continue;
                $words = preg_split('/\s+/', $frase);
                if (count($words) < 2 || count($words) > 5) continue;
                $key = mb_strtolower($frase, 'UTF-8');
                $candidatos[$key] = ($candidatos[$key] ?? 0) + 1;
                if (!isset($candidatosOriginais[$key])) $candidatosOriginais[$key] = $frase;
            }
        }

        // Padrão 2: Substantivo-composto hifenizado ("Auxílio-Gás", "Pé-de-Meia", "13º salário")
        if (preg_match_all('/\b[A-ZÁÉÍÓÚÂÊÔÃÕÇ][\wá-ÿ]+(?:-[A-ZÁÉÍÓÚÂÊÔÃÕÇ]?[\wá-ÿ]+)+/u', $texto, $mm2)) {
            foreach ($mm2[0] as $frase) {
                $frase = trim($frase);
                if (mb_strlen($frase, 'UTF-8') < 6 || mb_strlen($frase, 'UTF-8') > 40) continue;
                $key = mb_strtolower($frase, 'UTF-8');
                $candidatos[$key] = ($candidatos[$key] ?? 0) + 1;
                if (!isset($candidatosOriginais[$key])) $candidatosOriginais[$key] = $frase;
            }
        }

        // Ordena por frequência (mais frequentes = mais relevantes pro artigo)
        arsort($candidatos);
        $out = [];
        foreach (array_keys($candidatos) as $key) {
            $out[] = $candidatosOriginais[$key] ?? $key;
            if (count($out) >= 15) break;
        }
        return $out;
    }

    /**
     * Extrai palavras-chave significativas do termo-seed (≥4 chars, não-stopword, não-genérica).
     * Ex: "Botijão de gás sobe pela 5ª semana" → ['botijão', 'gás', 'semana']
     * Ex: "Gás do Povo: Programa federal" → ['gás', 'povo', 'programa', 'federal']
     */
    private static function palavrasChaveDoTermo(string $termo): array
    {
        // Remove pontuação e fragmenta
        $limpo = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $termo) ?? $termo;
        $palavras = preg_split('/\s+/', trim($limpo));
        $out = [];
        $vistos = [];
        foreach ($palavras as $p) {
            $pLower = mb_strtolower($p, 'UTF-8');
            if (mb_strlen($p, 'UTF-8') < 4) continue;
            if (in_array($pLower, self::$stopwords, true)) continue;
            if (in_array($pLower, self::$genericos, true)) continue;
            if (preg_match('/^\d+$/', $p)) continue; // puro número
            if (isset($vistos[$pLower])) continue;
            $vistos[$pLower] = true;
            $out[] = $p;
            if (count($out) >= 5) break;
        }
        return $out;
    }

    /** Filtra termos inválidos / duplicados / stopwords. */
    private function limparTermos(array $termos): array
    {
        $limpos = [];
        $vistos = [];
        foreach ($termos as $t) {
            $t = trim(preg_replace('/\s+/', ' ', (string)$t));
            if ($t === '') continue;
            $norm = mb_strtolower($t, 'UTF-8');
            if (isset($vistos[$norm])) continue;
            if (mb_strlen($t, 'UTF-8') < 4 || mb_strlen($t, 'UTF-8') > 50) continue;
            // Ignora se é só stopwords
            $words = preg_split('/\s+/', $norm);
            $words = array_filter($words, fn($w) => !in_array($w, self::$stopwords, true));
            if (count($words) < 1) continue;
            $vistos[$norm] = true;
            $limpos[] = $t;
        }
        return $limpos;
    }

    /**
     * Injeta <a href> na primeira ocorrência do termo em um <p> que NÃO esteja já num link.
     * Usa DOMDocument pra garantir que não quebra tags/atributos.
     * Retorna o HTML modificado OU null se não achou lugar pra injetar.
     */
    private function injetarLinkNoBody(string $html, string $termo, string $url, string $tituloLink): ?string
    {
        // Wrapper com encoding UTF-8
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xp = new DOMXPath($dom);
        // Text nodes dentro de <p> OU <li class='bloco-resumo'>, NÃO dentro de <a>/<details>/<summary>/blocos de navegação
        // TL;DR (bloco-resumo) PERMITIDO — user quer backlinks logo no início (UL + primeiros P's)
        $textNodes = $xp->query('//p//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::blockquote) and not(ancestor::details) and not(ancestor::summary) and not(ancestor::*[contains(@class, "cluster-box") or contains(@class, "leia-tambem") or contains(@class, "msg-card") or contains(@class, "post-share")])] | //ul[contains(@class, "bloco-resumo")]//text()[not(ancestor::a) and not(ancestor::strong/a)]');

        $regex = '/\b' . preg_quote($termo, '/') . '\b/iu';

        // Monta lista de candidatos em ORDEM DE DOCUMENTO.
        // TL;DR (bloco-resumo) é aceito mesmo antes do 1º H2 — user pediu backlinks logo no início (UL).
        // Intro em <p> simples antes do H2 também é aceita (1º parágrafo natural).
        $candidatos = [];
        $todos = $xp->query('//p//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::blockquote) and not(ancestor::details) and not(ancestor::summary) and not(ancestor::*[contains(@class, "cluster-box") or contains(@class, "leia-tambem") or contains(@class, "msg-card") or contains(@class, "post-share")])] | //ul[contains(@class, "bloco-resumo")]//text()[not(ancestor::a)]');
        foreach ($todos as $n) {
            if ($n->nodeType !== XML_TEXT_NODE) continue;
            $text = $n->nodeValue;
            if (!preg_match($regex, $text, $mm, PREG_OFFSET_CAPTURE)) continue;
            $candidatos[] = [
                'node'       => $n,
                'matchStart' => $mm[0][1],
                'matchStr'   => $mm[0][0],
            ];
        }

        if (empty($candidatos)) {
            // Fallback: aceita qualquer <p> mesmo sem H2 antes (artigo curto/atípico)
            foreach ($textNodes as $n) {
                $text = $n->nodeValue;
                if (!preg_match($regex, $text, $mm, PREG_OFFSET_CAPTURE)) continue;
                $candidatos[] = ['node' => $n, 'matchStart' => $mm[0][1], 'matchStr' => $mm[0][0]];
            }
        }
        if (empty($candidatos)) return null;

        // ESTRATÉGIA: primeira ocorrência natural do termo no corpo.
        // Se "Gás do Povo" aparece na 2ª linha do artigo, o link fica na 2ª linha — não é distribuído
        // artificialmente por percentual. Isso corresponde ao comportamento editorial humano.
        // Garantia: 60% máximo (últimas ocorrências dentro do corpo antes da FAQ são descartadas).
        $totalC = count($candidatos);
        $idxEscolhido = 0;
        // Procura a primeira ocorrência que esteja nos 60% iniciais da lista
        $limiteMaxIdx = (int)floor($totalC * 0.60);
        for ($i = 0; $i < $totalC; $i++) {
            if ($i > $limiteMaxIdx) break;
            $idxEscolhido = $i;
            break;
        }
        $this->indicesUsados[] = $idxEscolhido;

        $c = $candidatos[$idxEscolhido];
        $node = $c['node'];
        $text = $node->nodeValue;
        $matchStart = $c['matchStart'];
        $matchStr   = $c['matchStr'];

        // PHRASE EXPANSION — tenta estender a âncora pra incluir verbo+determinante antes do termo.
        // Ex: "para evitar a malha fina" → âncora = "evitar a malha fina" (não só "malha fina")
        [$matchStart, $matchStr] = $this->expandirAncoraPhrase($text, $matchStart, $matchStr);

        $antes  = substr($text, 0, $matchStart);
        $depois = substr($text, $matchStart + strlen($matchStr));

        $frag = $dom->createDocumentFragment();
        if ($antes !== '')  $frag->appendChild($dom->createTextNode($antes));
        $a = $dom->createElement('a', htmlspecialchars_decode($matchStr));
        $a->setAttribute('href', $url);
        $a->setAttribute('title', $tituloLink);
        $a->setAttribute('data-internal-link', '1');
        $frag->appendChild($a);
        if ($depois !== '') $frag->appendChild($dom->createTextNode($depois));
        $node->parentNode->replaceChild($frag, $node);

        // Extrai HTML do wrapper <div>
        $out = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out !== '' ? $out : null;
    }

    /**
     * Expande a âncora pra trás pra incluir verbo + determinante quando faz sentido.
     * Ex: "para evitar a malha fina" → se o termo é "malha fina", âncora expandida = "evitar a malha fina".
     *
     * @return array [nova_matchStart, nova_matchStr]
     */
    private function expandirAncoraPhrase(string $text, int $matchStart, string $matchStr): array
    {
        // Pega até 30 chars antes do match
        $antes = substr($text, max(0, $matchStart - 30), min(30, $matchStart));
        // Regex: (verbo)(\s+(?:o|a|os|as|na|no|em|de|da|do|dos|das|uma?|pelo|pela))?\s+$
        // Lista de verbos com word-boundary e alternativas
        $verbos = implode('|', self::$verbosAncora);
        $regex = '/\b(' . $verbos . ')(\s+(?:o|a|os|as|na|no|em|de|da|do|dos|das|um|uma|pelo|pela))?\s+$/iu';
        if (!preg_match($regex, $antes, $mm, PREG_OFFSET_CAPTURE)) {
            return [$matchStart, $matchStr];
        }
        $prefixo = $mm[0][0]; // ex: "evitar a "
        $offsetPrefixoAbs = max(0, $matchStart - 30) + $mm[0][1];
        // Quantidade total de palavras (prefixo + match) — limita a 5 palavras
        $totalWords = count(preg_split('/\s+/', trim($prefixo . $matchStr)));
        if ($totalWords > 5) return [$matchStart, $matchStr];
        // Combina
        return [$offsetPrefixoAbs, $prefixo . $matchStr];
    }

    /** Retorna o índice mais próximo de $alvo que não esteja em $this->indicesUsados. */
    private function acharIndiceLivre(int $alvo, int $total): int
    {
        if (!in_array($alvo, $this->indicesUsados, true)) return max(0, min($alvo, $total - 1));
        // Busca em espiral (±1, ±2, ...)
        for ($d = 1; $d < $total; $d++) {
            foreach ([$alvo - $d, $alvo + $d] as $cand) {
                if ($cand < 0 || $cand >= $total) continue;
                if (!in_array($cand, $this->indicesUsados, true)) return $cand;
            }
        }
        return 0;
    }
}
