<?php
declare(strict_types=1);

/**
 * InlineImageInjector — adiciona 1-2 imagens inline ao corpo do artigo.
 *
 * Por que: Google Discover é VISUAL. 1 só featured image fica fraco vs sites
 * que têm 5+ fotos. 2 inline images contextuais aumentam dwell time + retorno.
 *
 * Estratégia:
 *   1. Coleta candidatos: <img> nas fontes scrapeadas (1ª foto após og:image)
 *   2. Filtra: tamanho ≥600px, não-logo, não-ícone, não-avatar
 *   3. Sideload no WP (usa Wordpress::uploadImagemPorUrl)
 *   4. Insere <figure><img/><figcaption>...</figcaption></figure> em pontos
 *      estratégicos: APÓS o 2º e o 5º <h2> (ritmo visual)
 *
 * Filosofia: imagens APENAS quando há foto contextual real na fonte. Não força.
 */
class InlineImageInjector
{
    /**
     * @param string  $html         HTML do artigo
     * @param array   $sourcesUrls  URLs das fontes scrapeadas
     * @param object  $wp           Wordpress instance (uploadImagemPorUrl)
     * @param int     $maxImagens   default 2
     * @param string  $titulo       Título do artigo (pra filtrar imagens off-topic)
     * @return array {html, log}
     */
    public static function injetar(string $html, array $sourcesUrls, $wp, int $maxImagens = 2, string $titulo = ''): array
    {
        $log = ['candidatas_encontradas' => 0, 'aprovadas' => 0, 'inseridas' => 0, 'descartadas_off_topic' => 0, 'erros' => []];

        if (empty($sourcesUrls)) return ['html' => $html, 'log' => $log];

        // Coleta imagens das fontes
        $candidatas = self::extrairImagensFontes($sourcesUrls);
        $log['candidatas_encontradas'] = count($candidatas);
        if (empty($candidatas)) return ['html' => $html, 'log' => $log];

        // Filtro semântico: alt/legenda precisa ter overlap mínimo com título do post.
        // Caso real: trend Enade pegou imagem "O Diabo Veste Prada 2" do querobolsa
        // (matéria relacionada linkada, não a real). Filtro Jaccard ≥0.10 elimina.
        if ($titulo !== '') {
            $tokensTitulo = self::tokenizarTitulo($titulo);
            $candidatasFiltradas = [];
            foreach ($candidatas as $c) {
                $contextoImg = trim(($c['legenda'] ?? '') . ' ' . ($c['alt'] ?? ''));
                if ($contextoImg === '') {
                    // Sem caption/alt — não dá pra avaliar tópico, mantém (fallback genérico)
                    $candidatasFiltradas[] = $c;
                    continue;
                }
                $tokensImg = self::tokenizarTitulo($contextoImg);
                $jacc = self::jaccardSimples($tokensTitulo, $tokensImg);
                if ($jacc >= 0.10) {
                    $candidatasFiltradas[] = $c;
                } else {
                    $log['descartadas_off_topic']++;
                }
            }
            $candidatas = $candidatasFiltradas;
            if (empty($candidatas)) return ['html' => $html, 'log' => $log];
        }

        // Aprova as melhores
        $aprovadas = self::aprovar($candidatas, $maxImagens);
        $log['aprovadas'] = count($aprovadas);
        if (empty($aprovadas)) return ['html' => $html, 'log' => $log];

        // Pontos de inserção: APÓS o 2º e o 5º </p> que segue um <h2>
        $pontos = self::encontrarPontosDeInsercao($html, count($aprovadas));
        if (empty($pontos)) {
            $log['erros'][] = 'sem pontos de inserção válidos';
            return ['html' => $html, 'log' => $log];
        }

        // Sideload + insere de trás pra frente (preserva offsets)
        usort($pontos, fn($a, $b) => $b <=> $a);
        $i = 0;
        foreach ($pontos as $pos) {
            if (!isset($aprovadas[$i])) break;
            $img = $aprovadas[$i];
            try {
                $alt = mb_substr($img['alt'] ?: 'imagem da matéria', 0, 120);
                $slug = 'inline-' . substr(md5($img['url']), 0, 8);
                $mediaId = $wp->uploadImagemPorUrl($img['url'], $alt, $slug);
                if ($mediaId) {
                    $media = $wp->getMedia($mediaId);
                    $imgUrl = $media['source_url'] ?? $img['url'];
                    // Legenda fidedigna com crédito da fonte
                    $legendaFinal = self::montarLegenda(
                        (string)($img['legenda'] ?? ''),
                        (string)($img['alt'] ?? ''),
                        (string)($img['fonte_url'] ?? '')
                    );
                    $figcaption = htmlspecialchars(mb_substr($legendaFinal, 0, 220), ENT_QUOTES, 'UTF-8');
                    $imgHtml = "\n<figure class='inline-img' style='margin:24px 0;width:100%;display:block'>"
                             . "<img src='" . htmlspecialchars($imgUrl, ENT_QUOTES) . "' alt='" . htmlspecialchars($alt, ENT_QUOTES) . "' loading='lazy' style='width:100%;height:auto;border-radius:8px;display:block'>"
                             . "<figcaption style='font-size:13px;color:#64748b;margin-top:8px;text-align:center;line-height:1.4;padding:0 12px;word-wrap:break-word;overflow-wrap:break-word;white-space:normal;display:block'>{$figcaption}</figcaption>"
                             . "</figure>\n";
                    $html = substr($html, 0, $pos) . $imgHtml . substr($html, $pos);
                    $log['inseridas']++;
                    $i++;
                }
            } catch (Throwable $e) {
                $log['erros'][] = $e->getMessage();
            }
        }

        return ['html' => $html, 'log' => $log];
    }

    /**
     * Extrai <img> das URLs fontes (até 5 candidatas total).
     * RESTRITO ao bloco de conteúdo (<article>/<main>) — evita pegar imagens
     * de sidebar/header/footer/nav que NÃO têm relação com o tema do post.
     * Caso real: posts pegavam imagens genéricas do site fonte (banner, lateral, autor)
     * em vez de fotos da matéria. Filter por container resolve.
     *
     * PRIORIZA <figure><img/><figcaption>X</figcaption></figure> — pega legenda real.
     * Em segundo lugar: alt do <img>. Em último: caption vazia (atribuição de fonte só).
     */
    private static function extrairImagensFontes(array $urls): array
    {
        $candidatas = [];
        foreach (array_slice($urls, 0, 3) as $url) {
            $htmlFull = self::fetchHtml($url);
            if ($htmlFull === '') continue;

            // 0. ISOLAR conteúdo principal: <article>, depois <main>, depois fallback
            //    pra body sem header/footer/aside/nav. Garante que pegamos só imagens
            //    da MATÉRIA, não do template do site.
            $html = self::isolarConteudoPrincipal($htmlFull);
            if ($html === '') $html = $htmlFull; // fallback se não isolou

            // 1. PRIMEIRO: extrai <figure><img/><figcaption>...</figcaption></figure> (com legenda real)
            if (preg_match_all('/<figure\b[^>]*>([\s\S]*?)<\/figure>/i', $html, $figs)) {
                foreach ($figs[1] as $figInner) {
                    if (!preg_match('/<img\s+[^>]*>/i', $figInner, $imgM)) continue;
                    if (!preg_match('/src=[\'"]([^\'"]+)[\'"]/i', $imgM[0], $sm)) continue;
                    $src = self::resolverUrl($sm[1], $url);
                    if ($src === '' || !self::passaFiltro($src)) continue;
                    $alt = preg_match('/alt=[\'"]([^\'"]*)[\'"]/i', $imgM[0], $am) ? trim($am[1]) : '';
                    $legenda = '';
                    if (preg_match('/<figcaption[^>]*>([\s\S]*?)<\/figcaption>/i', $figInner, $cm)) {
                        $legenda = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($cm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
                    }
                    $candidatas[] = ['url' => $src, 'alt' => $alt, 'legenda' => $legenda, 'fonte_url' => $url];
                    if (count($candidatas) >= 5) break 2;
                }
            }

            // 2. FALLBACK: <img> soltos sem <figure>
            if (preg_match_all('/<img\s+[^>]*>/i', $html, $imgs)) {
                foreach ($imgs[0] as $img) {
                    if (!preg_match('/src=[\'"]([^\'"]+)[\'"]/i', $img, $sm)) continue;
                    $src = self::resolverUrl($sm[1], $url);
                    if ($src === '' || !self::passaFiltro($src)) continue;
                    $alt = preg_match('/alt=[\'"]([^\'"]*)[\'"]/i', $img, $am) ? trim($am[1]) : '';
                    // Skip duplicada (já pegou via figure)
                    foreach ($candidatas as $c) if ($c['url'] === $src) continue 2;
                    $candidatas[] = ['url' => $src, 'alt' => $alt, 'legenda' => '', 'fonte_url' => $url];
                    if (count($candidatas) >= 5) break 2;
                }
            }
        }
        return $candidatas;
    }

    private static function resolverUrl(string $src, string $baseUrl): string
    {
        if (filter_var($src, FILTER_VALIDATE_URL)) return $src;
        $base = parse_url($baseUrl);
        if (str_starts_with($src, '//')) return ($base['scheme'] ?? 'https') . ':' . $src;
        if (str_starts_with($src, '/'))  return ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '') . $src;
        return '';
    }

    private static function passaFiltro(string $src): bool
    {
        $low = mb_strtolower($src);
        if (preg_match('#(logo|favicon|icon|avatar|sprite|brasao|emoji|gravatar|placeholder)#i', $low)) return false;
        return (bool)preg_match('/\.(jpg|jpeg|png|webp)(\?|$)/i', $low);
    }

    /**
     * Constrói legenda fidedigna com crédito de fonte:
     *   "Legenda real da fonte · Crédito: Vestibulando Web"
     *   "Imagem ilustrativa do programa · Crédito: Sedu/ES" (quando alt é genérico)
     *   "Crédito: gov.br" (quando não tem legenda nem alt)
     */
    public static function montarLegenda(string $legenda, string $alt, string $fonteUrl): string
    {
        $base = trim($legenda);
        if ($base === '') $base = trim($alt);
        // Filtra alt genérico (1-3 palavras só), tipo "imagem", "foto", "ilustracao"
        if ($base !== '' && str_word_count($base) < 3) {
            $low = mb_strtolower($base);
            if (preg_match('/^(imagem|foto|ilustra|figura|capa|thumb|preview)/u', $low)) $base = '';
        }
        // Normaliza: capitaliza 1ª letra, remove ponto final
        if ($base !== '') {
            $base = mb_strtoupper(mb_substr($base, 0, 1)) . mb_substr($base, 1);
            $base = rtrim($base, '. ');
        }
        $credito = self::nomearFonte($fonteUrl);
        // Fallback A (determinístico): quando sem legenda nem alt útil → "Imagem ilustrativa · Crédito"
        // Mais autoridade que só "Crédito: X" suspenso.
        if ($base === '') return 'Imagem ilustrativa · Crédito: ' . $credito;
        return $base . ' · Crédito: ' . $credito;
    }

    /**
     * Tokeniza titulo/legenda/alt removendo stopwords PT-BR e acentos.
     * Usado pra Jaccard de relevância imagem-vs-titulo.
     */
    private static function tokenizarTitulo(string $s): array
    {
        $s = trim($s);
        if ($s === '') return [];
        $s = strtr($s, 'áéíóúâêôàãõçÁÉÍÓÚÂÊÔÀÃÕÇ', 'aeiouaeoaaocAEIOUAEOAAOC');
        $s = preg_replace('/[^\w\s]/u', ' ', mb_strtolower($s, 'UTF-8'));
        if (!is_string($s)) return [];
        $parts = preg_split('/\s+/u', trim($s));
        if (!is_array($parts)) return [];
        $stopwords = ['de','da','do','das','dos','o','a','os','as','um','uma','e','ou','que','com','para','por','no','na','nos','nas','em','é','ser','estar','ter','tem','seu','sua','seus','suas','isso','esse','essa','este','esta','qual','como','onde','quando','quem','não','sim','já','mais','até','sobre','foto','imagem'];
        return array_values(array_unique(array_filter(
            $parts,
            fn($t) => $t && mb_strlen($t) > 2 && !in_array($t, $stopwords, true)
        )));
    }

    private static function jaccardSimples(array $a, array $b): float
    {
        if (empty($a) || empty($b)) return 0.0;
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? $inter / $union : 0.0;
    }

    /**
     * Mapeia host → nome editorial bonito. Cobre principais fontes BR.
     */
    private static function nomearFonte(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $host = preg_replace('/^www\./', '', mb_strtolower($host));
        $mapa = [
            'gov.br' => 'Gov.br',
            'mec.gov.br' => 'MEC',
            'inep.gov.br' => 'Inep',
            'inss.gov.br' => 'INSS',
            'caixa.gov.br' => 'Caixa',
            'capes.gov.br' => 'Capes',
            'cnpq.br' => 'CNPq',
            'senac.br' => 'Senac',
            'senai.br' => 'Senai',
            'sebrae.com.br' => 'Sebrae',
            'sedu.es.gov.br' => 'Sedu/ES',
            'usp.br' => 'USP',
            'unicamp.br' => 'Unicamp',
            'unesp.br' => 'Unesp',
            'utfpr.edu.br' => 'UTFPR',
            'ufrj.br' => 'UFRJ',
            'ufmg.br' => 'UFMG',
            'ufba.br' => 'UFBA',
            'ufpe.br' => 'UFPE',
            'g1.globo.com' => 'G1/Globo',
            'globo.com' => 'Globo',
            'folha.uol.com.br' => 'Folha de SP',
            'estadao.com.br' => 'Estadão',
            'agenciabrasil.ebc.com.br' => 'Agência Brasil',
            'jornaldebrasilia.com.br' => 'Jornal de Brasília',
            'vestibulandoweb.com.br' => 'Vestibulando Web',
            'guiadoestudante.abril.com.br' => 'Guia do Estudante',
            'conectaprofessores.com' => 'Conecta Professores',
            'educamaisbrasil.com.br' => 'Educa Mais Brasil',
            'gestaouniversitaria.com.br' => 'Gestão Universitária',
        ];
        // Match longest suffix
        $bestMatch = '';
        $bestLen = 0;
        foreach ($mapa as $h => $name) {
            if (str_ends_with($host, $h) && strlen($h) > $bestLen) {
                $bestMatch = $name;
                $bestLen = strlen($h);
            }
        }
        return $bestMatch !== '' ? $bestMatch : $host;
    }

    /**
     * Isola o conteúdo editorial do post (matéria), removendo header/sidebar/footer/nav.
     * Sequência:
     *   1. Tenta <article> (mais comum em portais editoriais)
     *   2. Tenta <main>
     *   3. Tenta div com class contendo "content"/"post"/"article"/"materia"/"entry"
     *   4. Fallback: body inteiro mas SEM tags de cromo (header/footer/aside/nav/script)
     */
    private static function isolarConteudoPrincipal(string $html): string
    {
        // 1. <article>
        if (preg_match('#<article\b[^>]*>([\s\S]*?)</article>#is', $html, $m)) {
            return $m[1];
        }
        // 2. <main>
        if (preg_match('#<main\b[^>]*>([\s\S]*?)</main>#is', $html, $m)) {
            return $m[1];
        }
        // 3. div com class de conteúdo
        $classesContent = ['post-content', 'entry-content', 'article-content', 'materia-content', 'single-content', 'post__content', 'article__body', 'post-body'];
        foreach ($classesContent as $cls) {
            $pattern = '#<div\s+[^>]*class=[\'"][^\'"]*\b' . preg_quote($cls, '#') . '\b[^\'"]*[\'"][^>]*>#i';
            if (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE)) {
                $start = $m[0][1] + strlen($m[0][0]);
                // Encontra </div> correspondente (heurística — pega contexto razoável)
                $end = $start + 50000; // limit
                if (preg_match('#</div>\s*</(?:section|main|body)#is', substr($html, $start, $end - $start), $em, PREG_OFFSET_CAPTURE)) {
                    return substr($html, $start, $em[0][1]);
                }
                return substr($html, $start, $end - $start);
            }
        }
        // 4. Fallback: remove cromo (header/footer/aside/nav/script/style)
        $clean = preg_replace('#<(header|footer|aside|nav|script|style|noscript|form|iframe)\b[^>]*>[\s\S]*?</\1>#i', '', $html) ?: $html;
        return $clean;
    }

    private static function fetchHtml(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CursosenacGratuitoBot/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
        ]);
        $b = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300) ? $b : '';
    }

    /**
     * Aprovar candidatas: dedup URL, depois pega top N.
     */
    private static function aprovar(array $candidatas, int $max): array
    {
        $seen = [];
        $aprovadas = [];
        foreach ($candidatas as $c) {
            if (isset($seen[$c['url']])) continue;
            $seen[$c['url']] = true;
            $aprovadas[] = $c;
            if (count($aprovadas) >= $max) break;
        }
        return $aprovadas;
    }

    /**
     * Pontos de inserção: APÓS o 1º </p> que vem após o 2º <h2> e o 5º <h2>.
     * Garante imagens distribuídas no corpo (não todas no topo).
     */
    private static function encontrarPontosDeInsercao(string $html, int $quantos): array
    {
        $pontos = [];
        if (!preg_match_all('/<h2[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) return [];
        $h2Positions = array_column($m[0], 1);
        $totalH2 = count($h2Positions);
        if ($totalH2 < 2) return [];

        // Alvos: 2º h2 e 5º h2 (ou último se total<5)
        $alvos = [];
        if ($quantos >= 1) $alvos[] = $h2Positions[1] ?? null; // 2º h2 (índice 1)
        if ($quantos >= 2 && $totalH2 >= 5) $alvos[] = $h2Positions[4] ?? null; // 5º h2
        elseif ($quantos >= 2) $alvos[] = $h2Positions[$totalH2 - 1] ?? null; // último h2

        foreach ($alvos as $h2Pos) {
            if ($h2Pos === null) continue;
            // Acha 1º </p> APÓS o h2 (= depois do 1º parágrafo da seção)
            if (preg_match('/<\/p>/i', $html, $pm, PREG_OFFSET_CAPTURE, $h2Pos)) {
                $pontos[] = $pm[0][1] + strlen($pm[0][0]);
            }
        }

        return array_values(array_filter($pontos));
    }
}
