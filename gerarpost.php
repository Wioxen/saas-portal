<?php
/**
 * Gerador de post EDITORIAL — artigo puro, sem review de produtos.
 *
 * Entrada:
 *  - Palavra-chave
 *  - Termos / contexto (textarea livre)
 *  - URL para scraping (opcional)
 *  - 8 blocos universais de prompt
 *  - Formato(s): SEO, Discover, News, SERP
 *  - Site de destino
 *
 * Fluxo:
 *  1. Scrape da URL (se fornecida) → fontes
 *  2. Claude->gerarArtigo() com keyword + termos + fontes + blocos → content_html
 *  3. Pretty Links rewriter inline (se houver <a href> externos)
 *  4. WP criarPost() como draft
 *  5. Indexação automática (opcional)
 */
// 2026-05-07: invalida cache opcache do PIPELINE crítico antes de cada request.
// Bug observado: edits em gerarpost.php / autoFix*.php às vezes ficam em opcache stale,
// causando autoFixForcarP3 não dividir intro 2P→3P apesar do código estar correto no disco.
// Custo: ~1ms por request. Apenas em CLI/web com opcache ativo.
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
    foreach (['lib/AntiAIValidator.php','lib/RankMathSeoValidator.php','lib/DebateBuilder.php','lib/Scraper.php','lib/AngulosH2Final.php','lib/PostHtmlSanitizers.php'] as $f) {
        @opcache_invalidate(__DIR__ . '/' . $f, true);
    }
}

require_once __DIR__ . '/lib/Serper.php';
require_once __DIR__ . '/lib/Scraper.php';
require_once __DIR__ . '/lib/Claude.php';
require_once __DIR__ . '/lib/Wordpress.php';
require_once __DIR__ . '/lib/PrettyLinks.php';
require_once __DIR__ . '/lib/InstantIndexing.php';
require_once __DIR__ . '/lib/LandingBuilder.php';
require_once __DIR__ . '/lib/Meta.php';
require_once __DIR__ . '/lib/CarrosselGenerator.php';
require_once __DIR__ . '/lib/GoogleTrends.php';
require_once __DIR__ . '/lib/GoogleNewsRss.php';
require_once __DIR__ . '/lib/OpenAI.php';
require_once __DIR__ . '/lib/DebateBuilder.php';
require_once __DIR__ . '/lib/PadroesTitulo.php';
require_once __DIR__ . '/lib/ClusterAngleAllocator.php';
require_once __DIR__ . '/lib/DataCoerenciaValidator.php';
require_once __DIR__ . '/lib/RankMathSeoValidator.php';
require_once __DIR__ . '/lib/OpenAILlm.php';

/**
 * Factory: retorna instância LLM (Claude ou OpenAILlm) conforme config.
 * Seleção (em ordem): $cfg['llm_provider'] (per-site) → Env::get('LLM_PROVIDER') → 'claude' (default).
 * Pra usar GPT-5: setar `LLM_PROVIDER=openai` no .env OU `'llm_provider' => 'openai'` no sites.php.
 *
 * Decisão 2026-05-05: GPT-5 segue ORDEM FIXA (3p+snippet+H2+RD-fechamento) MUITO melhor
 * que Sonnet 4.6 mesmo após DNA OVERRIDE removido + bloco INVIOLÁVEIS + autoFix programático.
 * Comparativo #2201/#2202 (GPT-5 severity=ok/warn) vs #2204/#2206 (Claude severity=fail).
 */
function clonais_make_llm(array $cfg, string $providerOverride = '')
{
    // Prioridade: POST (UI) > sites.php > .env > 'claude' (default)
    $provider = $providerOverride !== ''
        ? strtolower($providerOverride)
        : strtolower((string)($cfg['llm_provider'] ?? Env::get('LLM_PROVIDER', 'claude')));
    if ($provider === 'openai' || $provider === 'gpt' || $provider === 'gpt-5') {
        $model = (string)($cfg['llm_model'] ?? Env::get('OPENAI_MODEL_PRIMARY', 'gpt-5'));
        return new OpenAILlm((string)($cfg['openai_api_key'] ?? Env::get('OPENAI_API_KEY', '')), $model);
    }
    return new Claude((string)$cfg['anthropic_api_key'], (string)$cfg['anthropic_model']);
}

/** Extrai slides do content_html a partir de H2s + primeiro parágrafo de cada seção. */
function extrairSlidesDeHtml(string $html, int $max = 4): array
{
    $slides = [];
    $pattern = '#<h2[^>]*>(.*?)</h2>\s*(<p[^>]*>(.*?)</p>)?#is';
    if (preg_match_all($pattern, $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $titulo = trim(strip_tags($match[1] ?? ''));
            $corpo  = trim(strip_tags($match[3] ?? ''));
            if ($titulo === '') continue;
            $slides[] = ['type' => 'topic', 'title' => $titulo, 'body' => $corpo];
            if (count($slides) >= $max) break;
        }
    }
    return $slides;
}

/**
 * Extrai os N termos mais frequentes do texto (tokens + bi-grams), removendo stopwords PT-BR.
 * Usado pra fallback de backlinks internos quando keyword progressiva não acha o suficiente.
 * Funciona em qualquer nicho (não é educacional-only como o array hardcoded anterior).
 */
function extrairTermosPrincipais(string $texto, int $max = 6): array
{
    $limpo = mb_strtolower(strip_tags($texto), 'UTF-8');
    // Mantém letras (com acentos) e espaços, troca resto por espaço
    $limpo = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $limpo) ?? $limpo;
    $tokens = preg_split('/\s+/', trim($limpo)) ?: [];
    // Stopwords PT-BR comuns
    static $stop = [
        'a','o','as','os','um','uma','uns','umas','de','do','da','dos','das','em','no','na','nos','nas',
        'por','pra','para','com','sem','sob','sobre','ate','até','entre','contra','desde','após','apos',
        'e','ou','mas','porem','porém','porque','que','se','como','quando','onde','quem','qual','quais',
        'este','esta','estes','estas','isto','esse','essa','esses','essas','isso','aquele','aquela','aqueles','aquelas','aquilo',
        'meu','minha','teu','tua','seu','sua','seus','suas','nosso','nossa','dele','dela','deles','delas',
        'eu','tu','ele','ela','nos','vos','eles','elas','me','te','lhe','lhes','nao','não','sim',
        'é','foi','são','sao','foram','será','sera','seria','ser','estar','tem','ter','tinha','há','ha','havia',
        'ir','vai','foi','fazer','faz','fez','já','ja','muito','pouco','mais','menos','só','so',
        'também','tambem','ainda','agora','aqui','ali','lá','la','pois','então','entao','assim',
        'todos','todas','todo','toda','cada','mesmo','mesma','mesmos','mesmas','algum','alguma','alguns','algumas',
        'nenhum','nenhuma','tudo','nada','qualquer','outros','outras','outro','outra','vários','várias','varios','varias',
        'dia','dias','ano','anos','mês','mes','meses','hoje','amanhã','amanha','ontem','semana','semanas',
        'pode','podem','podia','poderia','deve','devem','será','sera','vão','vao','está','esta','estão','estao','estava','estavam',
    ];
    $stopSet = array_flip($stop);
    // Conta unigrams (>= 4 chars, não stopword, não só dígitos)
    $freq = [];
    $validos = [];
    foreach ($tokens as $t) {
        if (mb_strlen($t) < 4) { $validos[] = null; continue; }
        if (isset($stopSet[$t])) { $validos[] = null; continue; }
        if (ctype_digit($t)) { $validos[] = null; continue; }
        $freq[$t] = ($freq[$t] ?? 0) + 1;
        $validos[] = $t;
    }
    // Bi-grams (2 palavras válidas consecutivas)
    $bigrams = [];
    for ($i = 0; $i < count($validos) - 1; $i++) {
        if ($validos[$i] === null || $validos[$i + 1] === null) continue;
        $bg = $validos[$i] . ' ' . $validos[$i + 1];
        $bigrams[$bg] = ($bigrams[$bg] ?? 0) + 1;
    }
    // Prioriza bi-grams (contexto mais específico) que apareceram 2+ vezes
    $bigramsSig = array_filter($bigrams, fn($c) => $c >= 2);
    arsort($bigramsSig);
    arsort($freq);
    $topBi = array_slice(array_keys($bigramsSig), 0, intdiv($max, 2));
    $topUni = array_slice(array_keys($freq), 0, $max);
    // Merge: bi-grams primeiro (mais contextuais), depois unigrams pra completar
    $saida = $topBi;
    foreach ($topUni as $u) {
        if (count($saida) >= $max) break;
        // Evita unigram que já compõe algum bi-gram selecionado
        $jaCoberto = false;
        foreach ($topBi as $bg) {
            if (stripos($bg, $u) !== false) { $jaCoberto = true; break; }
        }
        if (!$jaCoberto) $saida[] = $u;
    }
    return array_slice($saida, 0, $max);
}

/**
 * Quebra <p> com mais de N palavras em múltiplos <p> na fronteira de frase (ponto + espaço + maiúscula).
 * Safety net: o prompt já instrui Claude a respeitar 40 palavras/p, mas quando escorrega, esse helper corrige.
 * Pula <p> que contêm estrutura complexa interna (tabela, lista, imagem, blockquote) pra não quebrar layout.
 */
function quebrarParagrafosLongos(string $html, int $maxPalavras = 70): string
{
    $out = preg_replace_callback('#<p(\s[^>]*)?>(.*?)</p>#is', function($m) use ($maxPalavras) {
        $attrs = $m[1] ?? '';
        $texto = trim($m[2]);
        // Pula se tem estrutura interna complexa
        if (preg_match('#<(div|img|table|ul|ol|figure|blockquote|iframe|video|audio)\b#i', $texto)) return $m[0];
        // Conta palavras em texto plano
        $plano = trim(strip_tags($texto));
        if ($plano === '') return $m[0];
        $palavras = preg_match_all('/\S+/u', $plano);
        if ($palavras <= $maxPalavras) return $m[0];
        // Divide em frases (ponto/exclamação/interrogação + espaço + maiúscula com acento)
        $frases = preg_split('/(?<=[.!?])\s+(?=[A-ZÁÀÃÂÄÉÈÊËÍÌÎÏÓÒÕÔÖÚÙÛÜÇÑ])/u', $texto);
        if (!is_array($frases) || count($frases) < 2) return $m[0]; // 1 frase só: não quebra
        // Agrupa frases em buckets respeitando o limite
        $buckets = [[]];
        $idx = 0;
        $count = 0;
        foreach ($frases as $f) {
            $fw = preg_match_all('/\S+/u', strip_tags($f));
            if ($count + $fw > $maxPalavras && !empty($buckets[$idx])) {
                $idx++;
                $buckets[$idx] = [];
                $count = 0;
            }
            $buckets[$idx][] = $f;
            $count += $fw;
        }
        if (count($buckets) < 2) return $m[0];
        // Reconstrói como N parágrafos separados
        $reconstruido = '';
        foreach ($buckets as $b) {
            if (empty($b)) continue;
            $reconstruido .= '<p' . $attrs . '>' . trim(implode(' ', $b)) . "</p>\n";
        }
        return rtrim($reconstruido, "\n");
    }, $html);
    return $out ?? $html;
}

/**
 * Injeta CSS auto-contido do bloco .alerta-critico (pattern interrupt visual pra "erro fatal"
 * ou aviso crítico). Só roda se o HTML contiver a classe.
 */
function injetarAlertaCriticoAssets(string $html): string
{
    if (stripos($html, 'alerta-critico') === false) return $html;
    $css = "<style>"
        . ".alerta-critico{background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:8px;padding:16px 20px;margin:20px 0;box-shadow:0 1px 2px rgba(0,0,0,.04)}"
        . ".alerta-critico__titulo{font-size:15px;font-weight:700;color:#991b1b;margin:0 0 8px;display:flex;align-items:center;gap:8px;text-transform:uppercase;letter-spacing:.5px}"
        . ".alerta-critico__titulo::before{content:'!';display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;background:#dc2626;color:#fff;border-radius:50%;font-weight:900;font-size:14px;flex-shrink:0}"
        . ".alerta-critico__texto{font-size:15px;line-height:1.55;color:#222;margin:0}"
        . ".alerta-critico__texto strong{color:#991b1b}"
        . "</style>";
    $pos = stripos($html, '<script type=');
    if ($pos !== false) return substr($html, 0, $pos) . $css . substr($html, $pos);
    return $html . $css;
}

/** Injeta CSS+JS auto-contido dos msg-cards (Copiar + WhatsApp) se o HTML contiver a classe. */
function injetarMsgCardsAssets(string $html): string
{
    if (stripos($html, 'msg-card') === false) return $html;
    $css = "<style>"
        . ".msg-card{background:#fff;border:1px solid #e5e7eb;border-left:4px solid #25d366;border-radius:8px;padding:16px 20px;margin:16px 0;box-shadow:0 1px 3px rgba(0,0,0,.04)}"
        . ".msg-text{font-size:16px;line-height:1.6;color:#222;margin:0 0 12px;white-space:pre-wrap}"
        . ".msg-actions{display:flex;gap:8px;flex-wrap:wrap}"
        . ".msg-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:6px;border:1px solid #e5e7eb;background:#f8f9fa;color:#333;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s,color .15s}"
        . ".msg-btn:hover{background:#eef2ff}"
        . ".msg-wa{background:#25d366;color:#fff;border-color:#25d366}"
        . ".msg-wa:hover{background:#1faa52;color:#fff}"
        . ".msg-copy.is-copied{background:#d1fae5;color:#065f46;border-color:#10b981}"
        . "</style>";
    $js = "<script>(function(){document.addEventListener('click',function(e){var b=e.target.closest('.msg-copy');if(!b)return;var c=b.closest('.msg-card');if(!c)return;var t=c.querySelector('.msg-text');if(!t)return;var s=t.innerText||t.textContent||'';var o=b.textContent;function d(){b.textContent='Copiado!';b.classList.add('is-copied');setTimeout(function(){b.textContent=o;b.classList.remove('is-copied')},1800)}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(s).then(d).catch(function(){})}else{var a=document.createElement('textarea');a.value=s;document.body.appendChild(a);a.select();try{document.execCommand('copy')}catch(e){}a.remove();d()}})})();</script>";
    // Insere ANTES dos <script type='application/ld+json'> (JSON-LD é a última coisa, LCP)
    $pos = stripos($html, '<script type=');
    if ($pos !== false) {
        return substr($html, 0, $pos) . $css . $js . substr($html, $pos);
    }
    return $html . $css . $js;
}

/**
 * Monta prompt seguro pra geração de imagem via OpenAI (dall-e-3).
 * Prioridade de fonte do tema visual:
 *   1. $imagemPromptClaude — descrição VISUAL gerada pelo Claude (melhor: é tradução editorial → cena)
 *   2. $keyword — tema abstrato (bom fallback)
 *   3. $titulo limpo de números/cifrões/comparativos — último recurso
 *
 * Motivo: passar título literal (ex: "R$ 397 vs R$ 2.497") faz o dall-e-3 desenhar placas
 * com esses números, poluindo a imagem. O imagem_prompt do Claude descreve a cena, não o título.
 */
/**
 * Mapeia tema do artigo para PESSOA + CENÁRIO + OBJETO + TEXTO_DISPOSITIVO
 * conforme template Universal High-CTR.
 */
function clonais_persona_por_tema(string $keyword, string $contexto): array
{
    $kw = mb_strtolower($keyword . ' ' . $contexto, 'UTF-8');

    // INSS / aposentadoria / 13º
    if (preg_match('/inss|aposenta|13[ºo°]?\s*sal|previd[êe]nci|benef[íi]cio.*idoso/u', $kw)) {
        return [
            'person'      => 'a Brazilian senior citizen (around 65 years old, grey hair, warm relieved smile, casual polo shirt)',
            'scenario'    => 'a cozy Brazilian middle-class home living room with afternoon sunlight from a window',
            'object'      => 'a smartphone',
            'device_text' => 'BENEFÍCIO LIBERADO',
        ];
    }
    // Bolsa Família / CadÚnico / Auxílio Brasil
    if (preg_match('/bolsa\s+fam[íi]lia|cad[úu]nico|aux[íi]lio\s+brasil/u', $kw)) {
        return [
            'person'      => 'a Brazilian working-class mother in her 30s with a warm relieved smile, simple casual outfit',
            'scenario'    => 'a simple Brazilian kitchen with afternoon sunlight, family photos visible',
            'object'      => 'a smartphone showing a banking app',
            'device_text' => 'CALENDÁRIO 2026',
        ];
    }
    // Pé-de-Meia / estudante ensino médio
    if (preg_match('/p[ée]\s*-?\s*de\s*-?\s*meia|estudante\s+(ensino|escola)|ensino\s+m[ée]dio/u', $kw)) {
        return [
            'person'      => 'a Brazilian high-school student in their late teens (school-style outfit, hopeful confident expression)',
            'scenario'    => 'a Brazilian public school courtyard or modern classroom with peers blurred in soft bokeh',
            'object'      => 'a smartphone showing the gov.br interface',
            'device_text' => 'BENEFÍCIO ESTUDANTE',
        ];
    }
    // ENEM / SISU / FIES / Prouni
    if (preg_match('/enem|sisu|fies|prouni|fuvest|vestibular/u', $kw)) {
        return [
            'person'      => 'a Brazilian college-age student (18-22 years old, focused confident expression, casual student outfit)',
            'scenario'    => 'a modern Brazilian university campus or library with shelves blurred in bokeh',
            'object'      => 'a tablet showing course enrollment',
            'device_text' => 'INSCRIÇÃO ABERTA',
        ];
    }
    // SENAC / SESI / SESC / SENAI / curso técnico
    if (preg_match('/senac|sesi|sesc|senai|t[ée]cnic|profissionalizant/u', $kw)) {
        return [
            'person'      => 'a young Brazilian apprentice in their 20s wearing a clean professional uniform, focused and confident',
            'scenario'    => 'a modern professional training workshop or laboratory with equipment blurred in bokeh',
            'object'      => 'a tablet showing the course portal',
            'device_text' => 'CURSO GRÁTIS',
        ];
    }
    // Concurso público / edital / servidor
    if (preg_match('/concurso|edital|prova\s+p[úu]blic|servidor\s+p[úu]blic/u', $kw)) {
        return [
            'person'      => 'a focused Brazilian adult professional in their 30s with an aspirational concentrated expression, smart-casual outfit',
            'scenario'    => 'a clean modern Brazilian home office with study materials and a bookshelf blurred in bokeh',
            'object'      => 'a tablet showing the official notice',
            'device_text' => 'EDITAL ABERTO',
        ];
    }
    // FGTS / Caixa Tem / saque
    if (preg_match('/fgts|saque|caixa\s+tem|fundo\s+de\s+garantia|pis\b|pasep/u', $kw)) {
        return [
            'person'      => 'a Brazilian working adult in their 40s with a relieved confident smile, casual polo or button-up shirt',
            'scenario'    => 'a clean modern Brazilian home with neutral tones and natural light from a window',
            'object'      => 'a smartphone showing the Caixa Tem app interface',
            'device_text' => 'SAQUE LIBERADO',
        ];
    }
    // Vagas / emprego / contratação
    if (preg_match('/vagas?\b|emprego|contrata\w*|recrutament|sine\b/u', $kw)) {
        return [
            'person'      => 'a confident Brazilian professional in their 30s, smiling, smart-casual outfit',
            'scenario'    => 'a modern Brazilian office or coworking space with people blurred in soft bokeh background',
            'object'      => 'a smartphone',
            'device_text' => 'VAGAS ABERTAS',
        ];
    }
    // Saúde / SUS
    if (preg_match('/sus\b|sa[úu]de|m[ée]dic|hospital|exame/u', $kw)) {
        return [
            'person'      => 'a Brazilian patient or healthcare worker (uniform, around 35 years old, kind professional expression)',
            'scenario'    => 'a clean modern Brazilian public health clinic environment with neutral tones',
            'object'      => 'a smartphone showing the Meu SUS Digital app',
            'device_text' => 'SUS DIGITAL',
        ];
    }
    // Default: adulto brasileiro contextual
    $devText = strtoupper(mb_substr(trim($keyword) ?: 'CONFIRA', 0, 15, 'UTF-8'));
    return [
        'person'      => 'a Brazilian adult in their 30s with a warm confident expression, smart-casual outfit',
        'scenario'    => 'a clean modern Brazilian environment with soft natural light and elements relevant to the article topic, blurred in bokeh',
        'object'      => 'a smartphone',
        'device_text' => $devText,
    ];
}

/**
 * Divide o overlay (6-8 palavras) em 2 linhas balanceadas pro sticker amarelo.
 * Linha 1 = punch / valor / benefício. Linha 2 = ação / urgência.
 */
function clonais_split_overlay_sticker(string $overlay): array
{
    $palavras = preg_split('/\s+/', trim(str_replace('·', '', $overlay)));
    $palavras = array_values(array_filter($palavras, fn($p) => $p !== ''));
    $n = count($palavras);

    if ($n === 0) {
        return ['line1' => 'OPORTUNIDADE', 'line2' => 'CONFIRA'];
    }
    if ($n === 1) {
        return ['line1' => mb_strtoupper($palavras[0], 'UTF-8'), 'line2' => 'CONFIRA'];
    }
    // Split balanceado (metade pra cima, metade pra baixo)
    $cut = (int) ceil($n / 2);
    return [
        'line1' => mb_strtoupper(implode(' ', array_slice($palavras, 0, $cut)), 'UTF-8'),
        'line2' => mb_strtoupper(implode(' ', array_slice($palavras, $cut)), 'UTF-8'),
    ];
}

/**
 * Extrai sub-label (deadline) do título + contexto do artigo.
 * Retorna texto em CAIXA ALTA pronto pro bar preto, ou '' se não detectar.
 */
function clonais_extrair_sublabel(string $titulo, string $contexto): string
{
    $texto = $titulo . ' ' . $contexto;

    if (preg_match('/at[ée]\s+(\d{1,2})[\/.](\d{1,2})/iu', $texto, $m)) {
        return 'INSCRIÇÕES ATÉ ' . str_pad($m[1], 2, '0', STR_PAD_LEFT) . '/' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
    }
    if (preg_match('/at[ée]\s+(\d{1,2})\s+de\s+(janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)/iu', $texto, $m)) {
        return 'PRAZO: ' . $m[1] . ' DE ' . mb_strtoupper($m[2], 'UTF-8');
    }
    if (preg_match('/(?:encerra|fecha|termina|acaba|expira)\w*\s+(?:em\s+)?(\d+)\s+(dias?|horas?|semanas?)/iu', $texto, $m)) {
        return 'ÚLTIMOS ' . $m[1] . ' ' . mb_strtoupper($m[2], 'UTF-8');
    }
    if (preg_match('/[úu]ltim[ao]\s+(semana|chance|dia|hora|prazo)/iu', $texto, $m)) {
        return 'ÚLTIMA ' . mb_strtoupper($m[1], 'UTF-8');
    }
    return '';
}

function construirPromptImagem(string $titulo, string $keyword = '', string $contexto = '', string $imagemPromptClaude = '', string $overlayChamativo = ''): string
{
    // Limpa título de números/cifrões que viram letreiros na imagem gerada
    $tituloLimpo = $titulo;
    $tituloLimpo = preg_replace('/\bR\$\s*[\d.,]+/iu', '', $tituloLimpo) ?? $tituloLimpo;
    $tituloLimpo = preg_replace('/\b\d{1,3}(?:\.\d{3})*(?:,\d+)?\b/u', '', $tituloLimpo) ?? $tituloLimpo;
    $tituloLimpo = preg_replace('/\b\d+\b/u', '', $tituloLimpo) ?? $tituloLimpo;
    $tituloLimpo = preg_replace('/\bvs\b/iu', '', $tituloLimpo) ?? $tituloLimpo;
    $tituloLimpo = preg_replace('/[":;|]/', '', $tituloLimpo) ?? $tituloLimpo;
    $tituloLimpo = preg_replace('/ {2,}/', ' ', $tituloLimpo) ?? $tituloLimpo;
    $tituloLimpo = trim($tituloLimpo);

    // Cena visual (preferência: descrição do Claude → keyword → título limpo)
    $imagemPromptClaude = trim($imagemPromptClaude);
    if ($imagemPromptClaude !== '' && mb_strlen($imagemPromptClaude) >= 10) {
        $cenaVisual = $imagemPromptClaude;
    } elseif ($keyword !== '') {
        $cenaVisual = 'a Brazilian person in a context related to ' . $keyword;
    } else {
        $cenaVisual = $tituloLimpo;
    }

    // Resumo editorial (1ª frase do meta_description) — ancora o tema pra DALL-E
    $resumoEditorial = '';
    $contextoLimpo = trim(preg_replace('/\s+/', ' ', $contexto) ?? '');
    if ($contextoLimpo !== '') {
        $primeiraFrase = preg_split('/(?<=[.!?])\s+/', $contextoLimpo)[0] ?? $contextoLimpo;
        $resumoEditorial = $primeiraFrase;
    }

    // ============================================================
    // PROMPT UNIVERSAL HIGH-CTR (template fornecido pelo usuário)
    //
    // Layout fixo: persona à direita + cenário em bokeh + dispositivo com texto +
    // SELO AMARELO no top-left + sub-label preto. Otimizado pra CTR no Discover.
    //
    // ATENÇÃO: a imagem GERADA inclui texto (sticker, sub-label, tela do device).
    // DALL-E 3 pode renderizar texto com erros — esperar isso.
    // ============================================================

    // Persona + cenário + objeto baseado no tema do artigo
    $persona = clonais_persona_por_tema($keyword, $contexto);

    // Sticker text — usa overlay_chamativo se vier do Claude/PHP, senão deriva agora
    if (trim($overlayChamativo) === '') {
        $overlayChamativo = clonais_derivar_overlay($titulo, '', $contexto, '');
    }
    $stickerLines = clonais_split_overlay_sticker($overlayChamativo);

    // Sub-label (deadline) — opcional, só aparece se detectar prazo no conteúdo
    $subLabel = clonais_extrair_sublabel($titulo, $contexto);

    // Texto do dispositivo — pode ser substituído pelo keyword se mais forte
    $deviceText = $persona['device_text'];

    $prompt  = "Create a professional 16:9 wide-angle photograph for a Brazilian news portal cover, optimized for high CTR on the Google Discover feed.\n\n";

    $prompt .= "COMPOSITION:\n";
    $prompt .= "- Main subject: {$persona['person']}, looking happy and confident, positioned on the RIGHT side of the frame (about 60-65% from the left edge). Facing slightly toward the camera with eye contact.\n";
    $prompt .= "- Background: {$persona['scenario']}; soft bokeh (out-of-focus blur) for depth.\n";
    $prompt .= "- Proof element: the subject is holding a {$persona['object']} angled to face the camera. The screen shows a clean simple icon and the visible text: \"{$deviceText}\".\n\n";

    $prompt .= "DESIGN ELEMENTS (Scroll-Stop Layer):\n";
    $prompt .= "- Headline Sticker: in the TOP-LEFT corner of the image, place a large bright YELLOW (#FFD400) rectangular sticker with rounded corners, slightly tilted like a real adhesive label, with a soft drop shadow.\n";
    $prompt .= "- Sticker text in BOLD BLACK sans-serif font, two lines, perfectly legible, large size (occupies most of the sticker area):\n";
    $prompt .= "  - Line 1: \"{$stickerLines['line1']}\"\n";
    $prompt .= "  - Line 2: \"{$stickerLines['line2']}\"\n";
    if ($subLabel !== '') {
        $prompt .= "- Sub-label: a small BLACK horizontal bar directly BELOW the yellow sticker, with crisp WHITE sans-serif text: \"{$subLabel}\".\n";
    }
    $prompt .= "\n";

    $prompt .= "TECHNICAL SPECIFICATIONS:\n";
    $prompt .= "- Lighting: bright natural morning light, vibrant saturated colors, high contrast (image must be readable as a 100x56px thumbnail).\n";
    $prompt .= "- Style: clean editorial photography, realistic, sharp focus on subject, high resolution.\n";
    $prompt .= "- Aspect ratio: 16:9 landscape.\n";
    $prompt .= "- Safe margin: keep 10% of empty/breathing space on all four edges; no element (text, logo, person) touches the borders.\n\n";

    $prompt .= "EDITORIAL CONTEXT (visual coherence anchor): article titled \"{$titulo}\"";
    if ($resumoEditorial !== '') {
        $prompt .= " — covers: {$resumoEditorial}";
    }
    if ($keyword !== '') {
        $prompt .= " (topic: {$keyword})";
    }
    $prompt .= ".\n\n";

    $prompt .= "SAFETY: regular Brazilian adult only (NOT a real celebrity, NOT a minor under 18); no violence, no sexual or discriminatory content; no partisan flags or party symbols; no recognizable logos of private companies (unless that company is the article subject).";

    return $prompt;
}

/**
 * Substitui travessão (em-dash — U+2014) e en-dash (– U+2013) por vírgula no TEXTO do HTML.
 * Preserva tags, atributos (href, class, style), conteúdo dentro de <code>/<pre> e URLs.
 * Motivo: travessão em prosa não-literária é assinatura forte de texto gerado por IA.
 * Em PT-BR editorial, vírgula/parênteses/ponto são mais naturais.
 */
function sanitizarTravessoes(string $html): string
{
    // preg_replace_callback com alternância: ou a captura é uma tag HTML inteira (preserva),
    // ou é texto puro entre tags (processa).
    $out = preg_replace_callback(
        '#(<pre\b[^>]*>.*?</pre>)|(<code\b[^>]*>.*?</code>)|(<[^>]+>)|([^<]+)#is',
        function ($m) {
            // Grupos 1 e 2: <pre>/<code> inteiros — preserva sem tocar
            if (!empty($m[1])) return $m[1];
            if (!empty($m[2])) return $m[2];
            // Grupo 3: tag de abertura/fechamento — preserva
            if (!empty($m[3])) return $m[3];
            // Grupo 4: texto real entre tags — processa
            $txt = $m[4] ?? '';
            // Em-dash e en-dash com espaços ao redor → vírgula
            $txt = preg_replace('/\s*—\s*/u', ', ', $txt) ?? $txt;
            $txt = preg_replace('/\s*–\s*/u', ', ', $txt) ?? $txt;
            // 2026-05-06 #1839: caso residual sem espaço (palavra—palavra) — também remove
            $txt = preg_replace('/[—–]/u', ', ', $txt) ?? $txt;
            // Normaliza possíveis vírgulas consecutivas geradas pela substituição
            $txt = preg_replace('/,\s*,+/', ',', $txt) ?? $txt;
            // Normaliza espaço duplo
            $txt = preg_replace('/ {2,}/', ' ', $txt) ?? $txt;
            return $txt;
        },
        $html
    );
    return $out ?? $html;
}

/**
 * Auto-fix programático: reduz a INTRO pra exatos 3 parágrafos sem class antes do 1º <h2>.
 * Parágrafos extras (P4+) são realocados pra DEPOIS do 1º <h2>. Snippet (<ul class='snippet-resumo'>)
 * é preservado na intro. Disparado quando Sonnet 4.6 ignora regen mesmo com instruções literais.
 */
function autoFixIntroInflada(string $html): string
{
    $posH2 = stripos($html, '<h2');
    if ($posH2 === false) return $html;

    $intro = substr($html, 0, $posH2);
    $resto = substr($html, $posH2);

    // Captura todos os <p> da intro com offsets
    if (!preg_match_all('/<p\b([^>]*)>(.*?)<\/p>/is', $intro, $ps, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        return $html;
    }

    // Conta apenas P sem class especial (intro real, não snippet/RD/leia-mais)
    $intros = []; // [['full' => str, 'offset' => int, 'len' => int], ...]
    foreach ($ps as $row) {
        $atribs = $row[1][0];
        $temClassEspecial = preg_match('/class\s*=\s*[\'"][^\'"]*(?:resposta-direta|snippet-resumo|leia-mais|leia-tambem|alerta-critico|fonte-rodape)[^\'"]*[\'"]/i', $atribs);
        if ($temClassEspecial) continue;
        $intros[] = [
            'full' => $row[0][0],
            'offset' => $row[0][1],
            'len' => strlen($row[0][0]),
        ];
    }

    if (count($intros) <= 3) return $html; // já tá ok

    // Pega P4+ (do 4º em diante) e move pra depois do 1º <h2>
    $extras = array_slice($intros, 3);
    $movidos = '';
    // Remove os extras da intro (de trás pra frente pra não bagunçar offsets)
    foreach (array_reverse($extras) as $ex) {
        $movidos = $ex['full'] . "\n" . $movidos;
        $intro = substr($intro, 0, $ex['offset']) . substr($intro, $ex['offset'] + $ex['len']);
    }

    // Acha o fim da tag <h2>...</h2> no resto e injeta os movidos depois
    if (preg_match('/<h2\b[^>]*>.*?<\/h2>/is', $resto, $h2m, PREG_OFFSET_CAPTURE)) {
        $endH2 = $h2m[0][1] + strlen($h2m[0][0]);
        $resto = substr($resto, 0, $endH2) . "\n" . trim($movidos) . "\n" . substr($resto, $endH2);
    }

    return $intro . $resto;
}

/**
 * 2026-05-07: Extrai TODOS os scripts JSON-LD do HTML, valida, retorna array de JSON
 * pronto pra salvar em post meta. Remove os scripts do HTML.
 *
 * Resposta ao bug GSC "Tipo de valor incorreto": schemas no corpo são vulneráveis
 * ao wpautop e editores visuais. Schemas no `<head>` (via mu-plugin que lê post meta)
 * são imunes — exatamente como RankMath e Yoast fazem.
 *
 * @return array{0: string, 1: array<int, string>} [htmlLimpo, [json1, json2, ...]]
 */
function autoFixExtrairSchemasParaMeta(string $html): array
{
    $schemas = [];
    $htmlLimpo = preg_replace_callback(
        '#(?:<p>\s*)?<script\s+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>(?:\s*</p>)?#is',
        function ($m) use (&$schemas) {
            $conteudo = $m[1];

            // Mesmas limpezas do autoFixSanitizarJsonLd
            $conteudo = preg_replace('#<br\s*/?>#i', '', $conteudo) ?? $conteudo;
            $conteudo = preg_replace('#</?p\s*[^>]*>#i', '', $conteudo) ?? $conteudo;
            $conteudo = preg_replace('#<!--.*?-->#s', '', $conteudo) ?? $conteudo;
            $conteudo = html_entity_decode($conteudo, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $conteudo = str_replace(
                ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}", "\u{00AB}", "\u{00BB}"],
                ['"', '"', "'", "'", '"', '"'],
                $conteudo
            );
            $conteudo = preg_replace('/[\x{FEFF}\x{200B}\x{200C}\x{200D}\x{00A0}]/u', ' ', $conteudo) ?? $conteudo;
            $conteudo = trim($conteudo);

            // Valida JSON. Se inválido, descarta.
            $obj = json_decode($conteudo, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($obj)) {
                return ''; // remove sem registrar
            }

            // Re-encode pra garantir formato compacto e limpo
            $jsonLimpo = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonLimpo === false) return '';

            $schemas[] = $jsonLimpo;
            return ''; // remove do HTML
        },
        $html
    ) ?? $html;

    // Limpa quebras de linha vazias deixadas pela remoção
    $htmlLimpo = preg_replace('/\n{3,}/', "\n\n", $htmlLimpo) ?? $htmlLimpo;

    return [$htmlLimpo, $schemas];
}

/**
 * Auto-fix programático: sanitiza scripts <script type="application/ld+json">.
 *
 * 2026-05-07: GSC reporta "Tipo de valor incorreto" porque WordPress (Gutenberg/wpautop)
 * injeta <br />, <p>, </p> automaticamente DENTRO ou ENTRE os <script> JSON-LD.
 * O Google parser falha em ler o JSON quando encontra HTML tags.
 *
 * Operações:
 *   1. Remove <br>, <br/>, <br />, <p>, </p> DENTRO do conteúdo do <script>
 *   2. Normaliza aspas inteligentes (curvas) → retas
 *   3. Remove caracteres invisíveis (BOM, ZWSP, NBSP)
 *   4. Valida com json_decode — se inválido após limpeza, REMOVE o bloco inteiro
 *      (melhor sem schema do que com schema quebrado contando como erro no GSC)
 *   5. Remove <p> e <br> IMEDIATAMENTE antes ou depois do <script>
 */
function autoFixSanitizarJsonLd(string $html): string
{
    // 1. Remove <p> e <br> que envolvem o <script> (wpautop injection)
    $html = preg_replace('#<p>\s*(<script\s+type=["\']application/ld\+json["\'][^>]*>.*?</script>)\s*</p>#is', '$1', $html) ?? $html;
    $html = preg_replace('#<br\s*/?>\s*(<script\s+type=["\']application/ld\+json["\'][^>]*>)#is', '$1', $html) ?? $html;
    $html = preg_replace('#(</script>)\s*<br\s*/?>#is', '$1', $html) ?? $html;

    // 2. Limpa o conteúdo de cada <script> JSON-LD
    return preg_replace_callback(
        '#(<script\s+type=["\']application/ld\+json["\'][^>]*>)(.*?)(</script>)#is',
        function ($m) {
            $abertura = $m[1];
            $conteudo = $m[2];
            $fechamento = $m[3];

            // Remove HTML injetado pelo wpautop
            $conteudo = preg_replace('#<br\s*/?>#i', '', $conteudo) ?? $conteudo;
            $conteudo = preg_replace('#</?p\s*[^>]*>#i', '', $conteudo) ?? $conteudo;
            $conteudo = preg_replace('#<!--.*?-->#s', '', $conteudo) ?? $conteudo;
            // Decodifica entidades HTML (&quot; → ", &amp; → &)
            $conteudo = html_entity_decode($conteudo, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Normaliza aspas inteligentes → retas (JSON exige " e ')
            $conteudo = str_replace(
                ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}", "\u{00AB}", "\u{00BB}"],
                ['"', '"', "'", "'", '"', '"'],
                $conteudo
            );
            // Remove caracteres invisíveis (BOM, ZWSP, NBSP)
            $conteudo = preg_replace('/[\x{FEFF}\x{200B}\x{200C}\x{200D}\x{00A0}]/u', ' ', $conteudo) ?? $conteudo;
            $conteudo = trim($conteudo);

            // Valida JSON. Se inválido, remove o bloco inteiro (não polui GSC com erro)
            json_decode($conteudo);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ''; // Schema corrompido — melhor remover
            }

            return $abertura . $conteudo . $fechamento;
        },
        $html
    ) ?? $html;
}

/**
 * Auto-fix programático: substitui "nesta/neste {dia_semana}" desatualizado pelo
 * referencial correto (ontem / no dia X / amanhã) baseado em date('w') do servidor.
 *
 * 2026-05-07 #1862: Sonnet copia "nesta quarta-feira" da fonte publicada ontem,
 * mesmo com REGRA TEMPORAL ABSOLUTA + sentinel forca-regen. Detector AntiAI flagga
 * mas Sonnet teima — autoFix corrige determinístico aqui.
 *
 * Não toca em "nesta segunda" se hoje É segunda. Só atua quando há divergência.
 */
function autoFixDiaSemanaInconsistente(string $html): string
{
    $hojeW = (int)date('w'); // 0=dom, 1=seg, ..., 6=sab
    $mapaDias = [
        'domingo' => 0,
        'segunda' => 1, 'segunda-feira' => 1,
        'terça'   => 2, 'terça-feira'   => 2, 'terca' => 2, 'terca-feira' => 2,
        'quarta'  => 3, 'quarta-feira'  => 3,
        'quinta'  => 4, 'quinta-feira'  => 4,
        'sexta'   => 5, 'sexta-feira'   => 5,
        'sábado'  => 6, 'sabado' => 6,
    ];
    return preg_replace_callback(
        '/\b(nest[ae])\s+(domingo|segunda(?:[\s-]feira)?|terça(?:[\s-]feira)?|terca(?:[\s-]feira)?|quarta(?:[\s-]feira)?|quinta(?:[\s-]feira)?|sexta(?:[\s-]feira)?|sábado|sabado)\b/iu',
        function ($m) use ($hojeW, $mapaDias) {
            $dia = mb_strtolower(trim(str_replace(['-feira',' feira'], '', $m[2])), 'UTF-8');
            $diaNum = $mapaDias[$dia] ?? null;
            if ($diaNum === null) return $m[0];
            if ($diaNum === $hojeW) return $m[0]; // tá certo
            // Diferença em dias (mod 7)
            $diff = ($diaNum - $hojeW + 7) % 7;
            if ($diff === 6) return 'ontem';                                                           // 1 dia atrás
            if ($diff === 1) return 'amanhã';                                                          // 1 dia à frente
            // 2-6 dias: usa "no dia DD" baseado na data atual + offset (negativo se diff>3, positivo senão)
            $offsetDias = ($diff <= 3) ? $diff : ($diff - 7); // -1,-2,-3 = passado | 1,2,3 = futuro
            $ts = strtotime("{$offsetDias} day");
            if ($ts === false) return $m[0];
            $dia = (int)date('j', $ts);
            $mes = (int)date('n', $ts);
            $meses = ['','janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
            return "no dia {$dia} de {$meses[$mes]}";
        },
        $html
    ) ?? $html;
}

/**
 * Auto-fix programático: força INTRO ter 3 parágrafos quando Sonnet emitiu apenas 2.
 * 2026-05-06 #1839: mesmo após 2 regens com forca-regen, Sonnet teima em 2P.
 * Estratégia conservadora: se intro tem 2P sem class semântica, divide o MAIOR
 * dos 2 em duas partes na primeira fronteira de frase (ponto seguido de Maiúscula).
 * Não inventa conteúdo — só reorganiza o que Sonnet já gerou.
 */
function autoFixForcarP3(string $html): string
{
    $posH2 = stripos($html, '<h2');
    if ($posH2 === false) return $html;

    $intro = substr($html, 0, $posH2);
    $resto = substr($html, $posH2);

    if (!preg_match_all('/<p\b([^>]*)>(.*?)<\/p>/is', $intro, $ps, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        return $html;
    }

    // P sem class semântica (intro real)
    $intros = [];
    foreach ($ps as $row) {
        $atribs = $row[1][0];
        if (preg_match('/class\s*=\s*[\'"][^\'"]*(?:resposta-direta|snippet-resumo|leia-mais|leia-tambem|alerta-critico|fonte-rodape)[^\'"]*[\'"]/i', $atribs)) continue;
        $clean = trim(strip_tags($row[2][0]));
        if (str_word_count($clean) < 6) continue;
        $intros[] = [
            'full'   => $row[0][0],
            'inner'  => $row[2][0],
            'offset' => $row[0][1],
            'len'    => strlen($row[0][0]),
            'words'  => str_word_count($clean),
        ];
    }

    // Atua só quando há exatamente 2 P textuais
    if (count($intros) !== 2) return $html;

    // Escolhe o P com mais frases pra dividir; se empate, o maior em palavras
    $idxAlvo = -1; $melhorScore = 0;
    foreach ($intros as $i => $info) {
        $frases = preg_match_all('/[\.\!\?]\s+[A-ZÀ-Ú]/u', $info['inner']);
        $score = $frases * 1000 + $info['words'];
        if ($frases >= 1 && $score > $melhorScore) {
            $melhorScore = $score;
            $idxAlvo = $i;
        }
    }
    if ($idxAlvo < 0) return $html; // nenhum P tem 2+ frases pra dividir

    $alvo = $intros[$idxAlvo];
    // Divide na PRIMEIRA fronteira de frase (preserva HTML inline)
    $innerNorm = preg_replace('/\s+/u', ' ', $alvo['inner']) ?? $alvo['inner'];
    if (!preg_match('/^(.+?[\.\!\?])\s+([A-ZÀ-Ú].+)$/u', $innerNorm, $sm)) return $html;

    $parte1 = trim($sm[1]);
    $parte2 = trim($sm[2]);
    // Não divide se parte 1 ficar muito curta (<8 palavras) ou parte 2 muito curta (<6)
    if (str_word_count(strip_tags($parte1)) < 8 || str_word_count(strip_tags($parte2)) < 6) return $html;

    $novoBloco = "<p>{$parte1}</p>\n<p>{$parte2}</p>";
    $intro = substr($intro, 0, $alvo['offset'])
           . $novoBloco
           . substr($intro, $alvo['offset'] + $alvo['len']);

    return $intro . $resto;
}

/**
 * Auto-fix programático: garante EXATAMENTE 1 <p class='resposta-direta'> no HTML, posicionada
 * ANTES do <p>Fonte:</p>. Se Sonnet emitir múltiplas (intro + fechamento, ou variações), mantém
 * apenas a ÚLTIMA do documento (mais próxima do fechamento — geralmente a mais polida) e remove
 * as outras. Bug observado #2187 (2 RDs duplicadas com texto similar).
 */
function autoFixRdParaFechamento(string $html): string
{
    // 1) Coleta todas RDs com offsets
    if (!preg_match_all('/<p\b[^>]*class\s*=\s*[\'"][^\'"]*resposta-direta[^\'"]*[\'"][^>]*>.*?<\/p>/is', $html, $rds, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        return $html;
    }

    // 2) Decide qual manter: a ÚLTIMA do documento (mais próxima do fechamento)
    $manter = end($rds)[0][0];

    // 3) Remove TODAS as RDs (de trás pra frente pra preservar offsets)
    foreach (array_reverse($rds) as $rd) {
        $offset = $rd[0][1];
        $len = strlen($rd[0][0]);
        $html = substr($html, 0, $offset) . substr($html, $offset + $len);
    }

    // 4) Reinsere a única RD ANTES do rodapé autoral (ou "<p>Fonte:" legado, ou no fim se não houver)
    // Regex pega: rodapé novo "Conteúdo elaborado" OU rodapé antigo "Fonte:"
    // FLAG `u` OBRIGATÓRIO pra Unicode (ú/é/ó multi-byte). Sem `u`, [úu] não casa em UTF-8.
    if (preg_match('/<p\b[^>]*>\s*(?:Conte[úu]do\s+elaborado|Fonte\s*:)/isu', $html, $fm, PREG_OFFSET_CAPTURE)) {
        $insertAt = $fm[0][1];
        $html = substr($html, 0, $insertAt) . trim($manter) . "\n\n" . substr($html, $insertAt);
    } else {
        $html = rtrim($html) . "\n\n" . trim($manter) . "\n";
    }

    return $html;
}

/**
 * Auto-fix programático: remove atribuições a veículos de imprensa do CORPO do post.
 * Mantém atribuições a INSTITUIÇÕES/ÓRGÃOS/EMPRESAS (Senai, INEP, Caixa, Fundação X).
 *
 * Estratégia conservadora — apenas remove SUFIXOS e PREFIXOS atribuitivos sem reescrever
 * sintaxe complexa. Caso real #2261: "Segundo o portal G1 Bahia, a iniciativa..." →
 * "A iniciativa..." (recapitalizando primeira letra).
 */
function autoFixRemoverAtribuicaoVeiculo(string $html): string
{
    $veiculosImprensa = '\b(?:G1(?:\s+Bahia|\s+SP|\s+RJ|\s+AM|\s+\w+)?|UOL|Folha\s+de\s+S\.?\s*Paulo|Folha|Estad[ãa]o|R7|BBC(?:\s+Brasil)?|CNN(?:\s+Brasil)?|Globo|GloboNews|Veja|Exame|Valor|O\s+Globo|Metr[óo]poles|Terra|IG|Extra|Jovem\s+Pan|Band|SBT|Record|TNH1|Portal\s+Tempo\s+Novo|A\s+Gazeta|Jornal\s+Nacional|Carta\s+Capital|Gazeta\s+do\s+Povo)';

    // Substitutos sem aspas pra texto puro (não dentro de tags) — usa preg_replace_callback
    $out = preg_replace_callback(
        '#(<pre\b[^>]*>.*?</pre>)|(<code\b[^>]*>.*?</code>)|(<[^>]+>)|([^<]+)#is',
        function ($m) use ($veiculosImprensa) {
            if (!empty($m[1])) return $m[1];
            if (!empty($m[2])) return $m[2];
            if (!empty($m[3])) return $m[3];
            $txt = $m[4] ?? '';

            // 1. Início de frase: "Segundo o portal G1 Bahia, " → ""
            $txt = preg_replace('/(?<=[.!?]\s|^)(?:Segundo|De acordo com|Conforme|Publicado pelo|Divulgado pelo|Noticiado pelo)\s+(?:o|a|os|as)?\s*(?:portal|site|s[íi]tio|jornal|blog|publicaç[ãa]o|reportagem|veículo|veiculo|matéria|materia|notícia|noticia)\s+' . $veiculosImprensa . '\s*[,;]\s*/iu', '', $txt) ?? $txt;

            // 2. Início de frase: "Segundo o G1 Bahia, " → ""
            $txt = preg_replace('/(?<=[.!?]\s|^)(?:Segundo|De acordo com|Conforme|Publicado pelo|Divulgado pelo|Noticiado pelo)\s+(?:o|a|os|as)?\s*' . $veiculosImprensa . '\s*[,;]\s*/iu', '', $txt) ?? $txt;

            // 3. Voz passiva no MEIO ou início: "foi divulgada pelo G1 Bahia em 27 de abril e abrange..."
            //    → remove "foi divulgada pelo G1 Bahia" mas preserva "em 27 de abril"
            $txt = preg_replace('/\b(?:foi|foram|sera|será|serão|estava)\s+(?:divulgad|publicad|noticiad|anunciad|informad|reportad)[ao]s?\s+(?:pelo|pela|no|na)\s+' . $veiculosImprensa . '\s*/iu', '', $txt) ?? $txt;

            // 4. Vírgula intermediária: ", segundo o portal G1 Bahia," → ","
            $txt = preg_replace('/[,;]\s*(?:segundo|de acordo com|conforme)\s+(?:o|a|os|as)?\s*(?:portal|site|s[íi]tio|jornal|blog|publicaç[ãa]o|reportagem|veículo|veiculo)?\s*' . $veiculosImprensa . '[^,.;]*?(?=[,.;])/iu', '', $txt) ?? $txt;

            // 5. "publicado em DD de MMMM pelo G1 Bahia" → "publicado em DD de MMMM"
            $txt = preg_replace('/\s+(?:pelo|pela)\s+' . $veiculosImprensa . '/iu', '', $txt) ?? $txt;

            // 6. "O G1 Bahia informou que..." / "A Folha publicou que..." → "" (remove até "que ")
            $txt = preg_replace('/(?<=^|[.!?]\s)(?:O|A)\s+' . $veiculosImprensa . '\s+(?:informou|informa|publicou|publica|noticiou|noticia|divulgou|divulga|reportou|reporta|anunciou|anuncia|destacou|destaca|abordou|aborda|mostrou|mostra)\s+que\s+/iu', '', $txt) ?? $txt;

            // 8. GENÉRICO INÍCIO DE FRASE — "Segundo o portal EstágioTrainee," → ""
            //    Caso #2277: pattern 1 só pegava veículo conhecido; agora pega QUALQUER nome próprio capitalizado.
            //    Filtra "Segundo o portal de inscrição/oficial" pra não dar falso positivo.
            $txt = preg_replace('/(?<=[.!?]\s|^)(?:Segundo|De acordo com|Conforme|Publicado pelo|Divulgado pelo|Noticiado pelo)\s+(?:o|a|os|as)?\s*(?:portal|site|s[íi]tio|jornal|blog|publicaç[ãa]o|reportagem|veículo|veiculo|matéria|materia|notícia|noticia|reda[çc][ãa]o)\s+(?!(?:de|do|da|dos|das|oficial|nacional|principal|interno|brasileiro)\b)[A-Z][\w\.\-]{2,40}(?:\s+[A-Z][\w\.\-]{2,40}){0,3}\s*[,;]?\s*/u', '', $txt) ?? $txt;

            // 9. GENÉRICO MEIO DE FRASE — ", segundo o portal EstágioTrainee," → ","
            $txt = preg_replace('/[,;]\s*(?:segundo|de acordo com|conforme)\s+(?:o|a|os|as)?\s*(?:portal|site|s[íi]tio|jornal|blog|publicaç[ãa]o|reportagem|veículo|veiculo|reda[çc][ãa]o)\s+(?!(?:de|do|da|dos|das|oficial|nacional|principal|interno|brasileiro)\b)[A-Z][\w\.\-]{2,40}(?:\s+[A-Z][\w\.\-]{2,40}){0,3}/u', '', $txt) ?? $txt;

            // 7. GENÉRICO — "O portal EstágioTrainee.com analisou 4 opções" → "Foram analisadas 4 opções"
            //    Pega "O/A {portal|site|redação|blog|veículo} {Nome próprio com .com/.br opcional} {verbo}" e substitui pelo verbo no infinitivo.
            //    Exclui "O portal de/do/da" + "site oficial/nacional".
            $verbosCanonicos = ['analisou' => 'analisadas', 'analisa' => 'analisadas', 'informou' => 'informadas', 'publicou' => 'publicadas', 'noticiou' => 'noticiadas', 'divulgou' => 'divulgadas', 'reportou' => 'reportadas', 'destacou' => 'destacadas', 'mostrou' => 'apresentadas', 'menciona' => 'mencionadas', 'cita' => 'citadas', 'registra' => 'registradas', 'registrou' => 'registradas'];
            $txt = preg_replace_callback(
                '/(?<=^|[.!?]\s)(?:O|A)\s+(?:portal|site|jornal|blog|ve[íi]culo|reda[çc][ãa]o|publica[çc][ãa]o|im?prensa)\s+(?!(?:de|do|da|dos|das|oficial|nacional|principal|interno|brasileiro)\b)[A-Z][\w\.\-]{2,40}(?:\s+[A-Z][\w\.\-]{2,40}){0,3}\s+(analisou|analisa|informou|informa|publicou|publica|noticiou|noticia|divulgou|divulga|reportou|reporta|destacou|destaca|mostrou|mostra|menciona|cita|registra|registrou)\s+/iu',
                function ($m) use ($verbosCanonicos) {
                    $verbo = mb_strtolower($m[1]);
                    $foramX = $verbosCanonicos[$verbo] ?? 'analisadas';
                    return 'Foram ' . $foramX . ' ';
                },
                $txt
            ) ?? $txt;

            // Limpa pontuação dupla criada pelas remoções
            $txt = preg_replace('/[ ]+/', ' ', $txt) ?? $txt;
            $txt = preg_replace('/\s+([,.;:!?])/', '$1', $txt) ?? $txt;
            $txt = preg_replace('/([,;])\s*([,.;])/', '$2', $txt) ?? $txt;
            // Recapitaliza primeira letra de frase quando perdeu maiúscula no início (após remover prefixo)
            $txt = preg_replace_callback('/(?<=^|[.!?]\s)([a-záéíóúâêôãõç])/u', fn($mm) => mb_strtoupper($mm[1], 'UTF-8'), $txt) ?? $txt;

            return $txt;
        },
        $html
    );
    return $out ?? $html;
}

/**
 * Auto-fix programático: limita reticências (...) a no máximo 1 ocorrência no HTML.
 * Reticências em excesso são assinatura de IA (tom dramático/edital). Substitui por ponto.
 */
function autoFixReticenciasExcessivas(string $html): string
{
    // Conta ocorrências de "..." OU "…" no texto
    $count = 0;
    $out = preg_replace_callback(
        '#(<pre\b[^>]*>.*?</pre>)|(<code\b[^>]*>.*?</code>)|(<[^>]+>)|([^<]+)#is',
        function ($m) use (&$count) {
            if (!empty($m[1])) return $m[1];
            if (!empty($m[2])) return $m[2];
            if (!empty($m[3])) return $m[3];
            $txt = $m[4] ?? '';
            // Substitui "..." e "…" por ponto se já passou de 1 ocorrência
            $txt = preg_replace_callback('/(\.{3}|…)/u', function ($mm) use (&$count) {
                $count++;
                return $count <= 1 ? $mm[0] : '.';
            }, $txt) ?? $txt;
            return $txt;
        },
        $html
    );
    return $out ?? $html;
}

/**
 * Remove o sufixo do portal do título pré-fetched (ex: "Notícia X - Portal Y", "Título | Site Z").
 * Usado pra que a keyword/slug derivada do scrape não carregue o nome do veículo — o que polui
 * o "Leia também" dos irmãos do cluster com algo que parece "site da fonte".
 */
function limparTituloFonte(string $titulo): string
{
    $titulo = trim($titulo);
    if ($titulo === '') return $titulo;
    // Separadores comuns de sufixo (incluindo travessão unicode)
    $separadores = [' | ', ' - ', ' – ', ' — ', ' :: ', ' · '];
    foreach ($separadores as $sep) {
        $pos = mb_strrpos($titulo, $sep);
        if ($pos === false) continue;
        $antes = trim(mb_substr($titulo, 0, $pos));
        $depois = trim(mb_substr($titulo, $pos + mb_strlen($sep)));
        $lenDepois = mb_strlen($depois);
        // A parte após o separador é geralmente o nome do veículo: curta, sem pontuação de sentença
        // Mín 2 chars (cobre siglas G1, R7, UOL); máx 50 (cobre nomes compostos)
        if ($lenDepois < 2 || $lenDepois > 50) continue;
        if (preg_match('/[.!?]/', $depois)) continue; // tem pontuação de sentença → não é sufixo de portal
        // A parte antes tem que ter substância (>=20 chars OU >=4 palavras) pra não cortar título curto
        $palavrasAntes = count(preg_split('/\s+/', $antes) ?: []);
        if (mb_strlen($antes) < 20 && $palavrasAntes < 4) continue;
        return $antes;
    }
    return $titulo;
}

/**
 * Deriva o overlay (selo) chamativo da imagem — 6 a 8 palavras.
 *
 * ESTRATÉGIA:
 *   1. Claude prioritário (campo imagem.overlay_chamativo) — se vier com 6-8p, usa
 *   2. Senão: COMBINA 2-3 ângulos (deadline + escala, valor + público, etc) pra
 *      construir uma frase de 6-8 palavras com info COMPLEMENTAR ao título
 *   3. Se não atingir 6 palavras, complementa com palavras impactantes do título
 *
 * Cada ângulo retorna 2-3 palavras. Combinando 2-3 ângulos chegamos em 6-8.
 */
function clonais_derivar_overlay(string $titulo, string $excerpt = '', string $metaDesc = '', string $overlayClaude = ''): string
{
    $contarPalavras = static function (string $s): int {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        if ($s === '') return 0;
        return count(preg_split('/\s+/', $s) ?: []);
    };

    // 1) Claude prioritário se respeitou 6-8 palavras
    $claude = trim($overlayClaude);
    if ($claude !== '') {
        $cw = $contarPalavras($claude);
        if ($cw >= 6 && $cw <= 8) {
            return mb_strtoupper($claude, 'UTF-8');
        }
        // Se Claude veio curto ou longo, ainda podemos USAR como base e ajustar
    }

    $titulo = trim($titulo);
    $contextoTotal = trim($titulo . ' ' . $excerpt . ' ' . $metaDesc);
    if ($contextoTotal === '') return '';

    // ============================================================
    // EXTRAI ÂNGULOS — cada um retorna 2-3 palavras úteis
    // ============================================================
    $angulos = [];

    // DEADLINE
    if (preg_match('/at[ée]\s+(\d{1,2})[\/.](\d{1,2})/iu', $contextoTotal, $m)) {
        $angulos['deadline'] = 'ATÉ ' . str_pad($m[1], 2, '0', STR_PAD_LEFT) . '/' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
    } elseif (preg_match('/at[ée]\s+(\d{1,2})\s+de\s+(janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)/iu', $contextoTotal, $m)) {
        $angulos['deadline'] = 'ATÉ ' . $m[1] . ' DE ' . mb_strtoupper($m[2], 'UTF-8');
    } elseif (preg_match('/(?:encerra|fecha|termina|acaba|expira)\w*\s+(?:em\s+)?(\d+)\s*(dias?|horas?|semanas?)/iu', $contextoTotal, $m)) {
        $angulos['deadline'] = $m[1] . ' ' . mb_strtoupper($m[2], 'UTF-8');
    } elseif (preg_match('/[úu]ltim[ao]\s+(semana|chance|dia|hora|prazo)/iu', $contextoTotal, $m)) {
        $angulos['deadline'] = 'ÚLTIMA ' . mb_strtoupper($m[1], 'UTF-8');
    } elseif (preg_match('/(\d+)\s+(dias?|horas?)\s+(?:para|antes|restantes?)/iu', $contextoTotal, $m)) {
        $angulos['deadline'] = $m[1] . ' ' . mb_strtoupper($m[2], 'UTF-8');
    }

    // VALOR
    if (preg_match('/R\$\s*([\d][\d.,]*)\s*(mil|milh[õo]es?|bi|bilh[õo]es?)?/iu', $contextoTotal, $m)) {
        $unidade = isset($m[2]) && $m[2] !== '' ? ' ' . mb_strtoupper(substr($m[2], 0, 3), 'UTF-8') : '';
        $angulos['valor'] = 'R$ ' . $m[1] . $unidade;
    } elseif (preg_match('/sal[áa]rio\s+(?:de\s+)?R\$\s*([\d.,]+)/iu', $contextoTotal, $m)) {
        $angulos['valor'] = 'SALÁRIO R$ ' . $m[1];
    }

    // ESCALA / PÚBLICO
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(mil|milh[õo]es?|bi|bilh[õo]es?)?\s+(vagas?|estudantes?|beneficiári\w+|pessoas?|alunos?|aposentad\w+|trabalhadores?)/iu', $contextoTotal, $m)) {
        $num = $m[1];
        $escala = isset($m[2]) && $m[2] !== '' ? ' ' . mb_strtoupper(substr($m[2], 0, 3), 'UTF-8') : '';
        $sub = mb_strtoupper($m[3], 'UTF-8');
        $angulos['escala'] = $num . $escala . ' ' . $sub;
    }

    // PERCENTUAL
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $contextoTotal, $m)) {
        $angulos['percentual'] = $m[1] . '%';
    }

    // NOVIDADE
    if (preg_match('/\b(novo|nova|in[ée]dito|in[ée]dita|primeir[ao])\s+(\w+)/iu', $contextoTotal, $m)) {
        $angulos['novidade'] = mb_strtoupper($m[1] . ' ' . $m[2], 'UTF-8');
    }

    // AÇÃO URGENTE
    if (preg_match('/\b(corra|garanta|aproveite|n[ãa]o\s+perca)\b/iu', $contextoTotal, $m)) {
        $angulos['acao'] = mb_strtoupper($m[1], 'UTF-8') . '!';
    }

    // ============================================================
    // COMBINA ÂNGULOS — prioridade: deadline > valor > escala > %
    // ============================================================
    $ordemPrioridade = ['deadline', 'valor', 'escala', 'percentual', 'novidade', 'acao'];
    $partes = [];
    foreach ($ordemPrioridade as $k) {
        if (isset($angulos[$k])) {
            $partes[] = $angulos[$k];
        }
    }

    $combinado = implode(' · ', $partes); // separador visual entre ângulos
    $cw = $contarPalavras($combinado);

    // Se não chegou a 6 palavras, complementa com keywords do título
    if ($cw < 6) {
        $stopwords = ['o','a','os','as','um','uma','de','do','da','dos','das','no','na','nos','nas','em','para','pra','por','com','sem','que','e','ou','é','são','foi','vai','tem','sem','até'];
        $stopMap = array_flip($stopwords);
        $palavras = preg_split('/\s+/', preg_replace('/[":;|.!?·]/u', '', limparTituloFonte($titulo)) ?? '');
        foreach ($palavras as $p) {
            if ($p === '') continue;
            $low = mb_strtolower($p, 'UTF-8');
            if (isset($stopMap[$low])) continue;
            $combinado = trim($combinado . ' ' . $p);
            $cw = $contarPalavras($combinado);
            if ($cw >= 6) break;
        }
    }

    // Se passou de 8 palavras, corta
    if ($cw > 8) {
        $palavras = preg_split('/\s+/', $combinado);
        $combinado = implode(' ', array_slice($palavras, 0, 8));
    }

    // Se ainda assim < 6 (artigo sem dado nenhum), usa o título inteiro encurtado
    if ($contarPalavras($combinado) < 6) {
        $palavras = preg_split('/\s+/', preg_replace('/[":;|.!?]/u', '', limparTituloFonte($titulo)) ?? '');
        $combinado = implode(' ', array_slice($palavras, 0, 7));
    }

    return mb_strtoupper($combinado, 'UTF-8');
}

/** Slug PT-BR: lowercase, sem acentos, hifens. */
function slugifyPt(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
    ]);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/**
 * Auto-fix mecânico RankMath antes de mandar pro WP — última linha de defesa.
 *
 * O DebateBuilder já tenta acertar via prompt + regen, mas se um campo escapou
 * (ex: meta_description sem keyword, alt sem keyword, focus_keyword vazio),
 * essa função corrige sem tocar no body. Foca nos checks que o RankMath
 * valida em campos editáveis fora do HTML do post.
 *
 * Modifica $artigo IN-PLACE e retorna a focus_keyword final (string única, 2-4 palavras)
 * pra ser usada em rank_math_focus_keyword.
 */
function aplicarRankMathSeoFix(array &$artigo, string $titulo, string $kwFallback = ''): string
{
    /* 1. focus_keyword string única (não array, não título inteiro) */
    $kw = trim((string)($artigo['focus_keyword'] ?? ''));
    if ($kw === '' && $kwFallback !== '') $kw = trim($kwFallback);
    if ($kw === '' || str_word_count($kw) > 5 || str_word_count($kw) < 1) {
        $kw = RankMathSeoValidator::derivarKeywordDoTitulo($titulo);
    }
    /* Se ainda assim ficou vazio (título sem palavras válidas), usa as 3 primeiras palavras do título cruas */
    if ($kw === '') {
        $palavras = preg_split('/\s+/u', mb_strtolower(strip_tags($titulo), 'UTF-8')) ?: [];
        $kw = trim(implode(' ', array_slice($palavras, 0, 3)));
    }
    $artigo['focus_keyword'] = $kw;

    /* 2. meta_description: se não contém a keyword, prefixa com ela (mantendo ≤155c) */
    $metaDesc = trim((string)($artigo['meta_description'] ?? $artigo['excerpt'] ?? ''));
    if ($kw !== '' && $metaDesc !== '' && mb_stripos($metaDesc, $kw) === false) {
        /* Capitaliza primeira letra da keyword pra prefixo */
        $kwCap = mb_strtoupper(mb_substr($kw, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($kw, 1, null, 'UTF-8');
        $candidato = $kwCap . ': ' . $metaDesc;
        if (mb_strlen($candidato, 'UTF-8') > 155) {
            $candidato = mb_substr($candidato, 0, 152, 'UTF-8') . '...';
        }
        $artigo['meta_description'] = $candidato;
        $artigo['excerpt'] = $candidato;
    } elseif ($metaDesc === '' && $kw !== '') {
        /* Sem meta — gera uma básica (fallback raro, mas evita check failed) */
        $artigo['meta_description'] = "Veja tudo sobre {$kw}: detalhes, prazos e como aproveitar.";
        $artigo['excerpt'] = $artigo['meta_description'];
    }

    /* 3. imagem.alt_text: garante keyword literal */
    if (!isset($artigo['imagem']) || !is_array($artigo['imagem'])) $artigo['imagem'] = [];
    $alt = trim((string)($artigo['imagem']['alt_text'] ?? ''));
    if ($kw !== '' && ($alt === '' || mb_stripos($alt, $kw) === false)) {
        if ($alt === '') {
            $artigo['imagem']['alt_text'] = "Imagem ilustrativa sobre {$kw}";
        } else {
            /* Anexa a keyword ao final, mantendo ≤120c */
            $candidato = rtrim($alt, " .,;:") . " — {$kw}";
            if (mb_strlen($candidato, 'UTF-8') > 120) {
                $candidato = mb_substr($candidato, 0, 117, 'UTF-8') . '...';
            }
            $artigo['imagem']['alt_text'] = $candidato;
        }
    }
    /* hero_alt acompanha alt_text (usado em featured upload) */
    $artigo['hero_alt'] = $artigo['imagem']['alt_text'] ?? ($artigo['hero_alt'] ?? $titulo);

    return $kw;
}

/** Normaliza um termo em hashtag válida (PascalCase sem espaços). Opção de manter ou remover acentos. */
function _normalizarHashtag(string $termo, bool $manterAcento): string
{
    $termo = trim($termo);
    if ($termo === '') return '';

    if (!$manterAcento) {
        $termo = strtr($termo, [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'E','Ê'=>'E',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'I',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','Ó'=>'O','Ô'=>'O','Õ'=>'O',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'U',
            'ç'=>'c','ñ'=>'n','Ç'=>'C','Ñ'=>'N',
        ]);
    }

    // Remove tudo exceto letras unicode, números e espaços
    $termo = preg_replace('/[^\p{L}\p{N} ]/u', '', $termo) ?? $termo;
    $termo = preg_replace('/\s+/', ' ', $termo) ?? $termo;
    $termo = trim($termo);
    if ($termo === '') return '';

    // Title case multibyte, remove espaços → PascalCase
    $termo = mb_convert_case(mb_strtolower($termo, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    $termo = str_replace(' ', '', $termo);

    if (mb_strlen($termo, 'UTF-8') < 3) return '';

    // Permite ano 4 dígitos (2024-2030); rejeita outras strings puramente numéricas
    if (ctype_digit($termo)) {
        if (mb_strlen($termo) === 4 && (int)$termo >= 2020 && (int)$termo <= 2035) return $termo;
        return '';
    }

    return $termo;
}

/**
 * Gera conjunto rico de hashtags (mínimo 10) combinando múltiplas fontes:
 * - Tags do Claude
 * - focus_keyword + keyword
 * - Top bi-grams + unigrams extraídos do HTML (via extrairTermosPrincipais)
 * - Ano atual se mencionado
 * Gera 2 versões de cada termo (com e sem acento) e deduplica.
 */
function gerarHashtagsCompletas(array $artigo, string $keyword, string $htmlPost, int $maxTotal = 20): string
{
    $seeds = [];

    // 1. Tags do Claude (prioridade máxima)
    if (!empty($artigo['tags']) && is_array($artigo['tags'])) {
        foreach ($artigo['tags'] as $t) $seeds[] = (string)$t;
    }

    // 2. focus_keyword + keyword + title (contexto central)
    if (!empty($artigo['focus_keyword'])) $seeds[] = (string)$artigo['focus_keyword'];
    if ($keyword !== '') $seeds[] = $keyword;

    // 3. Top termos do conteúdo (bi-grams + unigrams)
    if (function_exists('extrairTermosPrincipais') && $htmlPost !== '') {
        $texto = strip_tags($htmlPost);
        $top = extrairTermosPrincipais($texto, 18);
        foreach ($top as $t) $seeds[] = $t;
    }

    // 4. Ano atual se aparece no texto
    $anoAtual = date('Y');
    if ($htmlPost !== '' && strpos($htmlPost, $anoAtual) !== false) $seeds[] = $anoAtual;

    // Dedup: normaliza com acento + sem acento, rejeita duplicatas
    $hashtags = [];
    $visto = [];
    foreach ($seeds as $seed) {
        $seed = trim((string)$seed);
        if ($seed === '') continue;

        $comAcento = _normalizarHashtag($seed, true);
        if ($comAcento) {
            $key = mb_strtolower($comAcento, 'UTF-8');
            if (!isset($visto[$key])) {
                $hashtags[] = '#' . $comAcento;
                $visto[$key] = true;
            }
        }
        $semAcento = _normalizarHashtag($seed, false);
        if ($semAcento) {
            $key = mb_strtolower($semAcento, 'UTF-8');
            if (!isset($visto[$key])) {
                $hashtags[] = '#' . $semAcento;
                $visto[$key] = true;
            }
        }

        if (count($hashtags) >= $maxTotal) break;
    }

    return implode(' ', array_slice($hashtags, 0, $maxTotal));
}

/** Gera hashtags formatadas a partir do array de tags do artigo. LEGACY — usar gerarHashtagsCompletas. */
function tagsParaHashtags(array $tags, int $max = 15): string
{
    $artigo = ['tags' => $tags];
    return gerarHashtagsCompletas($artigo, '', '', $max);
}

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

/** Monta "Leia também" como lista enxuta de títulos (sem imagens). */
function montarLeiaTambem(array $posts): string
{
    if (empty($posts)) return '';
    $html = '<h2>Leia também</h2><ul class="cc-related-list">';
    foreach ($posts as $p) {
        $titulo = htmlspecialchars($p['title']);
        $link   = htmlspecialchars($p['link']);
        $html  .= "<li><a href=\"{$link}\">{$titulo}</a></li>";
    }
    $html .= '</ul>';
    return $html;
}

$resultados = [];
$erro       = null;
$processado = false;
$trends     = [];
$rssItems   = [];

// Ação AJAX: gerar título Discover (retorna JSON, não HTML)
if (($_POST['action'] ?? '') === 'gerar_titulo') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(120);
    try {
        $tituloAnterior = trim($_POST['titulo_anterior'] ?? '');
        $urlScrape      = trim($_POST['url_scrape'] ?? '');
        $keyword        = trim($_POST['keyword'] ?? '');
        if ($tituloAnterior === '' && $keyword === '') throw new RuntimeException('Keyword ou título anterior obrigatório');

        $scraper = new Scraper($cfg['user_agent'], $cfg['scrape_timeout']);
        $claude  = clonais_make_llm($cfg, (string)($_POST['llm_provider'] ?? '')); // POST > config > env > claude

        // Scrapeia pra ter conteúdo completo
        $conteudo = '';
        if ($urlScrape !== '' && preg_match('#^https?://#', $urlScrape)) {
            try {
                $dados = $scraper->fetch($urlScrape);
                $conteudo = implode("\n", $dados['content']['paragraphs'] ?? []);
                if ($tituloAnterior === '') $tituloAnterior = $dados['meta']['title'] ?? $keyword;
            } catch (Throwable $e) {}
        }
        // Serper top 1 como fallback
        if ($conteudo === '' && $keyword !== '') {
            try {
                $serper = new Serper($cfg['serper_api_key']);
                $serp = $serper->search($keyword, 5);
                foreach (($serp['organic'] ?? []) as $org) {
                    $u = $org['link'] ?? '';
                    if (!$u) continue;
                    try { $d = $scraper->fetch($u); $conteudo = implode("\n", $d['content']['paragraphs'] ?? []); if ($conteudo !== '') break; } catch (Throwable $e) {}
                }
            } catch (Throwable $e) {}
        }

        if ($tituloAnterior === '') $tituloAnterior = $keyword;
        $titulo = $claude->gerarTituloDiscover($tituloAnterior, $conteudo ?: $keyword);
        echo json_encode(['ok' => true, 'titulo' => $titulo]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// Ação: listar Google Trends
if (($_GET['action'] ?? '') === 'trends') {
    try {
        $gt = new GoogleTrends($cfg['user_agent'] ?? '');
        $cat = $_GET['cat'] ?? 'all';
        $trends = $gt->buscar('BR', $cat);
    } catch (Throwable $e) { $erro = 'Erro ao listar trends: ' . $e->getMessage(); }
}

// Ação: listar itens de um RSS do Google News (antes de gerar)
if (($_POST['action'] ?? '') === 'load_rss') {
    try {
        $rssUrl = trim($_POST['rss_url'] ?? '');
        if ($rssUrl === '') throw new RuntimeException('URL do RSS vazia');
        // Instancia Serper para resolver URLs reais via busca por título
        $serperRss = null;
        try { $serperRss = new Serper($cfg['serper_api_key']); } catch (Throwable $e) {}
        $gn = new GoogleNewsRss($cfg['user_agent'] ?? '', 20, $serperRss);
        $rssItems = $gn->parseRss($rssUrl, 15);
    } catch (Throwable $e) { $erro = 'Erro ao ler RSS: ' . $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'load_rss') {
    $processado = true;
    set_time_limit(0);
    ini_set('memory_limit', '512M');

    $termosGlobal         = trim($_POST['termos'] ?? '');
    $imageUrlManualGlobal = trim($_POST['image_url'] ?? '');
    $formatos  = $_POST['formatos'] ?? ['discover'];
    // Default ON pra Instant Indexing (Discover precisa de indexação rápida pra viralizar).
    // User desliga explicitamente com auto_index=0 se quiser pular.
    $autoIdx   = !isset($_POST['auto_index']) ? true : ($_POST['auto_index'] !== '0' && !empty($_POST['auto_index']));
    $leiaTbm   = !empty($_POST['leia_tambem']);
    $gerarImgIA = !empty($_POST['gerar_imagem_ia']);
    $queimarOverlay = !empty($_POST['queimar_overlay']);
    $gerarWebstory = !empty($_POST['gerar_webstory']);
    $leiaQtde  = max(1, min(10, (int)($_POST['leia_qtde'] ?? 6)));
    $postFB       = !empty($_POST['post_fb']);
    $postIG       = !empty($_POST['post_ig']);
    $igFeedUnico  = !empty($_POST['ig_feed_unico']);  // novo: IG feed post único
    $igCarrossel  = !empty($_POST['ig_carrossel']);
    $carrosselEstilo = in_array(($_POST['carrossel_estilo'] ?? 'fotografico'), ['tipografico','fotografico'], true)
                       ? $_POST['carrossel_estilo'] : 'fotografico';

    $blocos = [];
    for ($b = 1; $b <= 8; $b++) $blocos[] = trim($_POST["bloco{$b}"] ?? '');

    // Monta lista de itens a processar (1 no modo single, N no batch_rss, N no cluster)
    $itensParaProcessar = [];
    $modoCluster = false;
    $action = $_POST['action'] ?? '';

    if ($action === 'cluster') {
        // MODO CLUSTER: cada item tem site + keyword + formato próprio
        $modoCluster = true;
        $clusterItems = json_decode($_POST['cluster_items_json'] ?? '[]', true) ?: [];
        // Pré-fetch: se keyword vazia e URL presente, extrai título pra derivar keyword/slug
        // ANTES do loop principal, pra que os backlinks cruzados (slugifyPt da keyword) batam com o slug real publicado.
        $scraperPre = null;
        foreach ($clusterItems as $ci) {
            $urlIt = trim((string)($ci['url'] ?? ''));
            $kwIt  = trim((string)($ci['keyword'] ?? ''));
            $fetched = null;
            if ($kwIt === '' && $urlIt !== '' && preg_match('#^https?://#', $urlIt)) {
                try {
                    $scraperPre = $scraperPre ?? new Scraper($cfg['user_agent'], $cfg['scrape_timeout']);
                    $fetched = $scraperPre->fetch($urlIt);
                    $tit = trim((string)($fetched['meta']['title'] ?? ''));
                    if ($tit !== '') $kwIt = limparTituloFonte($tit);
                } catch (Throwable $e) { /* segue: loop principal tentará de novo ou marcará erro */ }
            }
            $itensParaProcessar[] = [
                'keyword'        => $kwIt,
                'termos'         => $termosGlobal,
                'url'            => $urlIt,
                'image'          => '',
                'site_slug'      => trim((string)($ci['site'] ?? '')),
                'formato'        => trim((string)($ci['formato'] ?? 'discover')),
                '_prefetched'    => $fetched,
                'update_post_id' => (int)($ci['update_post_id'] ?? 0), // > 0 = MODO REFRESH
            ];
        }
        // Pillar topical: link compartilhado pra todos os itens do cluster.
        // Vem do PillarDetector no cluster.php — user pode optar por skip via toggle.
        $clusterPillar = null;
        if (!isset($_POST['skip_pillar_link'])) {
            $pl  = trim((string)($_POST['cluster_pillar_link']  ?? ''));
            $pt  = trim((string)($_POST['cluster_pillar_title'] ?? ''));
            $pTo = trim((string)($_POST['cluster_pillar_topico'] ?? ''));
            $pId = (int)($_POST['cluster_pillar_id'] ?? 0);
            if ($pl !== '' && $pt !== '') {
                $clusterPillar = ['link' => $pl, 'title' => $pt, 'topico' => $pTo, 'id' => $pId];
            }
        }
    } elseif ($action === 'batch_rss') {
        $sel = json_decode($_POST['selected_items_json'] ?? '[]', true) ?: [];
        foreach ($sel as $s) {
            $itensParaProcessar[] = [
                'keyword' => trim((string)($s['title'] ?? '')),
                'termos'  => trim((string)($s['description'] ?? '')) ?: $termosGlobal,
                'url'     => trim((string)($s['link_resolvido'] ?? $s['link'] ?? '')),
                'image'   => '',
            ];
        }
    } else {
        $keywordForm = trim($_POST['keyword'] ?? '');
        $urlForm     = trim($_POST['url'] ?? '');
        $rssLinkResolvido = trim($_POST['rss_link_resolvido'] ?? '');
        $rssTitleItem     = trim($_POST['rss_title_item'] ?? '');
        if ($rssLinkResolvido !== '') {
            $urlForm = $rssLinkResolvido;
            if ($keywordForm === '' && $rssTitleItem !== '') $keywordForm = $rssTitleItem;
        }
        $itensParaProcessar[] = [
            'keyword' => $keywordForm,
            'termos'  => $termosGlobal,
            'url'     => $urlForm,
            'image'   => $imageUrlManualGlobal,
        ];
    }

    if (empty($itensParaProcessar)) {
        $erro = 'Nenhum item selecionado.';
    } elseif (empty($formatos)) {
        $erro = 'Selecione pelo menos um formato.';
    } else {
        // CLUSTER: DNA editorial pre-alocado (ângulo + intenção + estrutura + title_pattern + diferenciador únicos por item)
        // Antes do loop principal, 1 chamada Haiku (cheap, ~R$ 0,03 por cluster) analisa TODOS os items
        // juntos com a persona do site alvo e atribui DNA sem repetição em 4 dimensões.
        // Fallback: rodízio determinístico dos 8 ângulos × 5 estruturas × 6 title_patterns.
        if ($modoCluster && count($itensParaProcessar) >= 2) {
            try {
                // Haiku 4.5 pra alocação (classification task — Sonnet seria desperdício de 8x)
                $allocClaude = new Claude($cfg['anthropic_api_key'], 'claude-haiku-4-5');
                $allocator = new ClusterAngleAllocator($allocClaude);
                // siteCtx: deriva da persona do PRIMEIRO site do cluster (na maioria 100% itens
                // do cluster vão pro mesmo site — vide cluster.php que usa rss_site_global).
                // Cluster heterogêneo (sites diferentes) ainda recebe siteCtx — fica enviesado
                // pro primeiro site, mas isso é mais útil que vazio.
                $siteCtxAlloc = [];
                $primeiroSlug = $itensParaProcessar[0]['site_slug'] ?? '';
                if ($primeiroSlug !== '' && isset($sites[$primeiroSlug])) {
                    $sP = $sites[$primeiroSlug];
                    $siteCtxAlloc = [
                        'nome'      => $sP['name']           ?? $primeiroSlug,
                        'nicho'     => $sP['subtipo_nicho']  ?? '',
                        'voz'       => $sP['persona']['voz']       ?? '',
                        'audiencia' => $sP['persona']['audiencia'] ?? '',
                        'canibal'   => $sP['termos_canibal'] ?? [],
                    ];
                }
                $itensAloc = [];
                foreach ($itensParaProcessar as $it) {
                    $snippet = '';
                    if (!empty($it['_prefetched']['content']['paragraphs'])) {
                        $snippet = implode(' ', array_slice($it['_prefetched']['content']['paragraphs'], 0, 4));
                    }
                    $itensAloc[] = [
                        'url'            => $it['url'] ?? '',
                        'title'          => $it['keyword'] ?: ($it['_prefetched']['meta']['title'] ?? ''),
                        'contentSnippet' => $snippet,
                    ];
                }
                $dnaList = $allocator->alocar($itensAloc, $siteCtxAlloc);
                foreach ($dnaList as $i => $dna) {
                    $itensParaProcessar[$i]['_dna'] = [
                        'angulo'            => $dna['angulo']            ?? '',
                        'intencao'          => $dna['intencao']          ?? '',
                        'estrutura'         => $dna['estrutura']         ?? '',
                        'title_pattern'     => $dna['title_pattern']     ?? '',
                        'intro_format'      => $dna['intro_format']      ?? '',
                        'num_h2'            => isset($dna['num_h2']) ? (int)$dna['num_h2'] : 0,
                        'diferenciador'     => $dna['diferenciador']     ?? '',
                        'abertura_proibida' => $dna['abertura_proibida'] ?? '',
                        'promessa'          => $dna['promessa']          ?? '',
                    ];
                }
            } catch (Throwable $e) { /* sem DNA: segue fluxo normal com {{DNA_SECTION}} vazio */ }
        }

        // Log: quantos itens vão ser processados (visível no resultado como header)
        $modoLog = (($_POST['action'] ?? '') === 'batch_rss') ? 'batch_rss' : 'single';
        // Guarda posts publicados nesta rodada → para interligação entre siblings no final
        $postsDaRodada = [];
        // Rastreia padrões de título usados nesta rodada + carrega histórico persistido por site
        // (evita redundância entre irmãos no cluster E entre runs sucessivos de RSS/single)
        $padroesUsadosCluster = [];
        $padroesStore = new PadroesTitulo(__DIR__ . '/data/padroes_titulo', 24, 5);
        // 2026-05-07 #1862: rastreia ângulos do H2 final (1-16) usados recentemente por site
        require_once __DIR__ . '/lib/AngulosH2Final.php';
        $angulosStore = new AngulosH2Final(__DIR__ . '/data/angulos_h2_final', 48, 3);
        try {
            $scraper = new Scraper($cfg['user_agent'], $cfg['scrape_timeout']);
            $claude  = clonais_make_llm($cfg, (string)($_POST['llm_provider'] ?? '')); // POST > config > env > claude
            $wp      = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
            $builder = new LandingBuilder($cfg['site_name'] ?? 'Como Comprar', $cfg['wp_url'] ?? '', [
                'number'    => $cfg['whatsapp_number'] ?? '',
                'group_url' => $cfg['whatsapp_group_url'] ?? '',
                'cta_text'  => $cfg['whatsapp_cta_text'] ?? '',
            ]);
            $idxApi  = $autoIdx ? new InstantIndexing($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']) : null;
            $meta    = ($postFB || $postIG) ? new Meta($cfg['fb_page_id'] ?? '', $cfg['fb_page_token'] ?? '', $cfg['ig_user_id'] ?? '', $cfg['ig_access_token'] ?? '') : null;

            // Pretty Links (inicializa se config permitir)
            $plInstance = null;
            if (!empty($cfg['pretty_links'])) {
                try { $plInstance = new PrettyLinks($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']); } catch (Throwable $e) {}
            }

            // ── PILLAR CRIAÇÃO (Fase B — Topical Authority) ──
            // Antes do loop principal: se user marcou "Criar pillar" no cluster.php, gera o pillar
            // primeiro, publica no WP do site, captura URL e seta como $clusterPillar pra todos os
            // cluster items linkarem pra ele. Falha = continua sem pillar (degrada limpo, log no error_log).
            if ($modoCluster && !empty($_POST['create_pillar']) && !empty($_POST['cluster_pillar_topico'])) {
                $topicoPillar = trim((string)$_POST['cluster_pillar_topico']);
                // Site alvo do pillar = primeiro item do cluster (no fluxo cluster.php todos vão pro mesmo site)
                $primeiroSlug = $itensParaProcessar[0]['site_slug'] ?? '';
                $cfgPillar = $cfg;
                if ($primeiroSlug !== '' && isset($sites[$primeiroSlug])) {
                    aplicarSite($cfgPillar, $sites, $primeiroSlug);
                }
                try {
                    require_once __DIR__ . '/lib/PillarBuilder.php';
                    // Pré-instancia WP do site pra passar ao PillarBuilder (busca posts existentes pra internal links contextuais)
                    $wpPillar = new Wordpress($cfgPillar['wp_url'], $cfgPillar['wp_user'], $cfgPillar['wp_app_password']);
                    $pillarBuilder = new PillarBuilder($claude, $wpPillar);
                    $sitePersonaPillar = [
                        'voz'       => $cfgPillar['persona']['voz']       ?? '',
                        'audiencia' => $cfgPillar['persona']['audiencia'] ?? '',
                        'nicho'     => $cfgPillar['persona']['especialidade'] ?? ($sites[$primeiroSlug]['subtipo_nicho'] ?? ''),
                        'tom'       => $cfgPillar['persona']['tom']       ?? '',
                    ];
                    $pillarGerado = $pillarBuilder->gerar(
                        $topicoPillar,
                        $itensParaProcessar,
                        $sitePersonaPillar,
                        $cfgPillar['site_name'] ?? ''
                    );

                    // Safety net mobile-first: quebra parágrafos > 40 palavras (Sonnet ocasionalmente
                    // ignora regra de "max 3 linhas" — função do gerarpost.php garante)
                    $pillarGerado['content_html'] = quebrarParagrafosLongos((string)$pillarGerado['content_html'], 999);
                    // Sanitiza travessões (—/–) — assinatura de IA, vira vírgula
                    $pillarGerado['content_html'] = sanitizarTravessoes((string)$pillarGerado['content_html']);
                    // Auto-fix programático: estrutura intro + RD única + reticências
                    $pillarGerado['content_html'] = autoFixIntroInflada((string)$pillarGerado['content_html']);
                    $pillarGerado['content_html'] = autoFixRdParaFechamento((string)$pillarGerado['content_html']);
                    $pillarGerado['content_html'] = autoFixReticenciasExcessivas((string)$pillarGerado['content_html']);
                    $payloadPillar = [
                        'title'   => $pillarGerado['title'],
                        'slug'    => $pillarGerado['slug'],
                        'content' => $pillarGerado['content_html'],
                        'status'  => $cfgPillar['wp_default_status'] ?? 'publish',
                        'excerpt' => $pillarGerado['meta_description'],
                    ];
                    $respPillar = $wpPillar->criarPost($payloadPillar);

                    if (!empty($respPillar['id']) && !empty($respPillar['link'])) {
                        // Sobrescreve $clusterPillar pra que loop principal use o pillar recém-criado
                        $clusterPillar = [
                            'link'   => $respPillar['link'],
                            'title'  => $pillarGerado['title'],
                            'topico' => $topicoPillar,
                            'id'     => (int)$respPillar['id'],
                        ];
                        // Indexing API
                        $idxPillar = $autoIdx ? new InstantIndexing($cfgPillar['wp_url'], $cfgPillar['wp_user'], $cfgPillar['wp_app_password']) : null;
                        if ($idxPillar) {
                            try { $idxPillar->indexar($respPillar['link'], 'URL_UPDATED'); } catch (Throwable $eIP) {}
                        }
                        $resultados[] = [
                            'formato' => 'pillar',
                            'ok'      => true,
                            'id'      => (int)$respPillar['id'],
                            'edit'    => rtrim($cfgPillar['wp_url'], '/') . "/wp-admin/post.php?post={$respPillar['id']}&action=edit",
                            'link'    => $respPillar['link'],
                            'titulo'  => $pillarGerado['title'],
                            'msg'     => '✨ PILLAR criado #' . (int)$respPillar['id'] . " (FAQ: {$pillarGerado['faq_count']}q · " . str_word_count(strip_tags($pillarGerado['content_html'])) . 'w)',
                        ];
                        $postsDaRodada[] = ['id' => (int)$respPillar['id'], 'link' => $respPillar['link'], 'link_planejado' => $respPillar['link'], 'titulo' => $pillarGerado['title']];
                    } else {
                        throw new RuntimeException('WP criarPost retornou sem id/link');
                    }
                } catch (Throwable $eP) {
                    error_log('[gerarpost] Pillar criação falhou: ' . $eP->getMessage());
                    $resultados[] = [
                        'formato' => 'pillar',
                        'ok'      => false,
                        'id'      => null,
                        'edit'    => '',
                        'link'    => '',
                        'titulo'  => "pillar de {$topicoPillar}",
                        'msg'     => 'Pillar falhou: ' . $eP->getMessage() . ' — cluster segue sem pillar linking',
                    ];
                    // $clusterPillar já é null nesse ponto, fluxo segue normal
                }
            }

            // LOOP ITENS: processa cada item (1 no single, N no batch_rss, N no cluster)
            foreach ($itensParaProcessar as $itemIdx => $item) {
                $keyword        = $item['keyword'];
                $termos         = $item['termos'];
                $url            = $item['url'];
                $imageUrlManual = $item['image'] ?? '';

                // CLUSTER: troca site config por item (cada item publica em site diferente)
                $cfgItem = $cfg; // cópia pra não poluir global
                if ($modoCluster && !empty($item['site_slug']) && isset($sites[$item['site_slug']])) {
                    $cfgItem = $cfg; // reset do base
                    aplicarSite($cfgItem, $sites, $item['site_slug']);
                    // Reinstancia services pro site correto
                    $wp      = new Wordpress($cfgItem['wp_url'], $cfgItem['wp_user'], $cfgItem['wp_app_password']);
                    $builder = new LandingBuilder($cfgItem['site_name'] ?? 'Site', $cfgItem['wp_url'] ?? '', [
                        'number' => $cfgItem['whatsapp_number'] ?? '', 'group_url' => $cfgItem['whatsapp_group_url'] ?? '', 'cta_text' => $cfgItem['whatsapp_cta_text'] ?? '',
                    ]);
                    $plInstance = null;
                    if (!empty($cfgItem['pretty_links'])) {
                        try { $plInstance = new PrettyLinks($cfgItem['wp_url'], $cfgItem['wp_user'], $cfgItem['wp_app_password']); } catch (Throwable $e) {}
                    }
                    $idxApi = $autoIdx ? new InstantIndexing($cfgItem['wp_url'], $cfgItem['wp_user'], $cfgItem['wp_app_password']) : null;
                    $meta = ($postFB || $postIG) ? new Meta($cfgItem['fb_page_id'] ?? '', $cfgItem['fb_page_token'] ?? '', $cfgItem['ig_user_id'] ?? '', $cfgItem['ig_access_token'] ?? '') : null;
                }

                // ── REFRESH MODE (cluster c/ post existente detectado pelo PostMatcher) ──
                // Se item tem update_post_id > 0, atualiza post existente em vez de criar novo:
                //   1. Busca post antigo via WP REST (getPost retorna content raw editável)
                //   2. Scrape do URL atual pra fontes novas (datas/valores atualizados)
                //   3. Claude::atualizarPost gera HTML preservando estrutura+tabelas+listas, ajusta tempos verbais
                //   4. wp.atualizarPost faz PUT no mesmo ID — preserva URL+slug+autoridade
                //   5. Indexing API: URL_UPDATED (sinaliza ao Google "olha, mudou")
                // Falha = SKIP do item (NÃO faz fallback pra criar novo — evita duplicar conteúdo).
                $updatePostId = (int)($item['update_post_id'] ?? 0);
                if ($updatePostId > 0) {
                    $cfgUseRefresh = $modoCluster ? ($cfgItem ?? $cfg) : $cfg;
                    try {
                        $postAntigo = $wp->getPost($updatePostId);
                        $htmlAntigo = (string)($postAntigo['content']['raw'] ?? $postAntigo['content']['rendered'] ?? '');
                        $titAntigo  = (string)($postAntigo['title']['raw']   ?? $postAntigo['title']['rendered']  ?? '');
                        $linkAntigo = (string)($postAntigo['link'] ?? '');
                        if ($htmlAntigo === '') throw new RuntimeException('Post antigo retornou content vazio');

                        // Scrape do URL novo pra ter fontes atualizadas (datas/valores/dados frescos).
                        // Reusa _prefetched (do cluster.php) se existir — evita 2º scrape.
                        $fonteNova = !empty($item['_prefetched']) ? $item['_prefetched'] : null;
                        if ($fonteNova === null && !empty($url) && preg_match('#^https?://#', $url)) {
                            try {
                                $scraperRefresh = $scraper ?? new Scraper($cfg['user_agent'], $cfg['scrape_timeout']);
                                $fonteNova = $scraperRefresh->fetch($url);
                            } catch (Throwable $eS) { /* sem fonte nova: passa só meta-info do scrape antigo */ }
                        }
                        if ($fonteNova === null) throw new RuntimeException('Sem URL válida nem fonte cacheada pra alimentar refresh');

                        $atualizado = $claude->atualizarPost($titAntigo, $htmlAntigo, [$fonteNova], 'discover');
                        $novoHtml   = (string)($atualizado['content_html'] ?? '');
                        $novoTitulo = (string)($atualizado['title'] ?? $titAntigo);
                        if ($novoHtml === '') throw new RuntimeException('Claude::atualizarPost retornou HTML vazio');

                        // Sanitiza travessões + quebra parágrafos longos (mesmo padrão do fluxo normal)
                        $novoHtml = quebrarParagrafosLongos($novoHtml, 999);
                        $novoHtml = sanitizarTravessoes($novoHtml);
                        // Auto-fix programático: estrutura intro + RD única + reticências
                        $novoHtml = autoFixIntroInflada($novoHtml);
                        $novoHtml = autoFixRdParaFechamento($novoHtml);
                        $novoHtml = autoFixReticenciasExcessivas($novoHtml);

                        $payloadUpd = [
                            'title'   => $novoTitulo,
                            'content' => $novoHtml,
                        ];
                        $cfgPurge = !empty($cfgUseRefresh['cloudflare_zone_id'])
                            ? ['cloudflare_zone_id' => $cfgUseRefresh['cloudflare_zone_id'], 'urls' => array_filter([$linkAntigo])]
                            : [];
                        $wp->atualizarPost($updatePostId, $payloadUpd, $cfgPurge);

                        // Indexing API: URL_UPDATED (não URL_INSERTED — Google entende "atualizou")
                        if ($idxApi && $linkAntigo !== '') {
                            try { $idxApi->indexar($linkAntigo, 'URL_UPDATED'); } catch (Throwable $eI) {}
                        }

                        $postsDaRodada[] = ['id' => $updatePostId, 'link' => $linkAntigo, 'link_planejado' => $linkAntigo, 'titulo' => $novoTitulo];
                        $msgRefresh = "🔄 REFRESH post #{$updatePostId} (" . str_word_count(strip_tags($novoHtml)) . "w) — URL preservada";
                        $resultados[] = [
                            'formato' => 'refresh',
                            'ok'      => true,
                            'id'      => $updatePostId,
                            'edit'    => rtrim($cfgUseRefresh['wp_url'] ?? '', '/') . "/wp-admin/post.php?post={$updatePostId}&action=edit",
                            'link'    => $linkAntigo,
                            'titulo'  => $novoTitulo,
                            'msg'     => $msgRefresh,
                        ];
                        continue; // pula resto do loop (sem Web Story / cluster cross-links — preserva os existentes)
                    } catch (Throwable $eR) {
                        // Falha no refresh = SKIP do item (NÃO criar novo, evita duplicata)
                        $resultados[] = [
                            'formato' => 'refresh',
                            'ok'      => false,
                            'id'      => null,
                            'edit'    => '',
                            'link'    => '',
                            'titulo'  => "post #{$updatePostId}",
                            'msg'     => 'REFRESH falhou: ' . $eR->getMessage() . ' — item pulado (sem fallback pra criar novo)',
                        ];
                        continue;
                    }
                }

                // CLUSTER: cross-links pros OUTROS sites do cluster (serão os backlinks internos)
                if ($modoCluster) {
                    $linksInternos = [];
                    foreach ($itensParaProcessar as $otherIdx => $other) {
                        if ($otherIdx === $itemIdx) continue;
                        $otherSite = $sites[$other['site_slug'] ?? ''] ?? [];
                        $otherUrl  = rtrim((string)($otherSite['wp_url'] ?? ''), '/');
                        $otherKw   = $other['keyword'] ?? '';
                        if ($otherUrl && $otherKw) {
                            $otherSlug = slugifyPt($otherKw);
                            // Mesmo corte de 60 chars aplicado no slug publicado → garante casamento
                            if (mb_strlen($otherSlug) > 60) $otherSlug = rtrim(mb_substr($otherSlug, 0, 60), '-');
                            $linksInternos[] = ['title' => $otherKw, 'link' => $otherUrl . '/' . $otherSlug . '/'];
                        }
                    }
                }

                // 1. Scrape — cache do pré-fetch (cluster) OU URL direta OU Serper top 5 automático
                $fontes = [];
                if (!empty($item['_prefetched'])) {
                    // Reaproveita scrape feito no pré-fetch do modo cluster
                    $fontes[] = $item['_prefetched'];
                } elseif ($url !== '' && preg_match('#^https?://#', $url)) {
                    // URL fornecida → scrapeia direto
                    try {
                        $dados = $scraper->fetch($url);
                        if (!empty($dados['meta']['title']) || !empty($dados['meta']['og_image']) || count($dados['content']['paragraphs']) >= 1) {
                            $fontes[] = $dados;
                        }
                    } catch (Throwable $e) {
                        $resultados[] = ['formato' => '—', 'ok' => false, 'msg' => "Scrape falhou: " . $e->getMessage(), 'id' => null, 'edit' => '', 'link' => '', 'titulo' => ($keyword ?: $url)];
                        continue;
                    }
                } elseif ($keyword !== '') {
                    // SEM URL → Serper top 5 automático (como o manifesto editorial exige)
                    try {
                        $serp = $serper->search($keyword, $cfg['scrape_max_try'] ?? 10);
                        $alvo = $cfg['scrape_top_n'] ?? 5;
                        foreach (($serp['organic'] ?? []) as $org) {
                            if (count($fontes) >= $alvo) break;
                            $u = $org['link'] ?? '';
                            if (!$u) continue;
                            try {
                                $d = $scraper->fetch($u);
                                if (!empty($d['meta']['title']) || count($d['content']['paragraphs']) >= 2) $fontes[] = $d;
                            } catch (Throwable $e) {}
                        }
                    } catch (Throwable $e) {}
                }

                // Se keyword vazia, deriva do título scrapeado
                if ($keyword === '' && !empty($fontes[0]['meta']['title'])) {
                    $keyword = limparTituloFonte(trim($fontes[0]['meta']['title']));
                }

                // Se ainda vazia e não veio URL, é item inválido
                if ($keyword === '') {
                    $resultados[] = ['formato' => '—', 'ok' => false, 'msg' => 'Item sem keyword e sem URL para derivar', 'id' => null, 'edit' => '', 'link' => '', 'titulo' => ''];
                    continue;
                }

                // Se termos vazios, deriva da description do scrape (resumo da fonte)
                if ($termos === '' && !empty($fontes[0]['meta']['description'])) {
                    $termos = trim($fontes[0]['meta']['description']);
                }

                // Busca posts relacionados no WP — keyword progressiva + fallback genérico
                if (!$modoCluster) $linksInternos = [];
                try {
                    $palavras = preg_split('/\s+/', trim($keyword));
                    $encontrados = [];

                    // Fase 1: keyword progressiva (remove 1 palavra do final até achar)
                    while (count($palavras) > 0 && count($encontrados) < 6) {
                        $termoBusca = implode(' ', $palavras);
                        $rel = $wp->buscarRelacionados($termoBusca, 8);
                        foreach ($rel as $p) {
                            if (!empty($p['title']) && !empty($p['link'])) {
                                $chave = $p['link'];
                                if (!isset($encontrados[$chave])) {
                                    $encontrados[$chave] = ['title' => $p['title'], 'link' => $p['link']];
                                }
                            }
                        }
                        array_pop($palavras);
                    }

                    // Fase 2: se achou < 6, extrai termos principais do CONTEÚDO (bi-grams + unigrams)
                    // e busca por eles — funciona em qualquer nicho (não mais hardcoded educacional).
                    if (count($encontrados) < 6 && !empty($fontes)) {
                        $conteudoParaTermos = '';
                        foreach ($fontes as $f) {
                            $conteudoParaTermos .= ($f['meta']['title'] ?? '') . ' '
                                . ($f['meta']['description'] ?? '') . ' '
                                . implode(' ', $f['content']['paragraphs'] ?? []) . ' ';
                        }
                        $termosExtraidos = extrairTermosPrincipais($conteudoParaTermos, 8);
                        foreach ($termosExtraidos as $termo) {
                            if (count($encontrados) >= 6) break;
                            try {
                                $rel = $wp->buscarRelacionados($termo, 4);
                            } catch (Throwable $e) { continue; }
                            foreach ($rel as $p) {
                                if (count($encontrados) >= 6) break;
                                if (!empty($p['title']) && !empty($p['link'])) {
                                    $chave = $p['link'];
                                    if (!isset($encontrados[$chave])) {
                                        $encontrados[$chave] = ['title' => $p['title'], 'link' => $p['link']];
                                    }
                                }
                            }
                        }
                    }

                    $linksInternos = array_merge($linksInternos, array_values($encontrados));
                } catch (Throwable $e) {}

                // ETAPA 2: Extrai termos virais + pergunta como viralizar
                $termosVirais = '';
                $briefingStr = '';
                if (!empty($fontes)) {
                    foreach ($fontes as $f) {
                        $briefingStr .= ($f['meta']['title'] ?? '') . '. ' . implode('. ', $f['content']['paragraphs'] ?? []) . ' ';
                    }
                    try {
                        $tvArr = $claude->extrairTermosVirais($keyword, $briefingStr);
                        if (!empty($tvArr)) {
                            $tvLines = [];
                            foreach ($tvArr as $tv) {
                                $tvLines[] = "- {$tv['termo']} ({$tv['tipo']}): {$tv['sugestao']}";
                            }
                            $termosVirais = "\n\nTERMOS VIRAIS EXTRAÍDOS DO SCRAPING (use obrigatoriamente no artigo):\n" . implode("\n", $tvLines);
                        }
                    } catch (Throwable $e) {}
                }

                // ETAPA 3+4: Geração
                $fmtsDoItem = $modoCluster && !empty($item['formato']) ? [$item['formato']] : $formatos;
                $isDiscover = in_array('discover', $fmtsDoItem);
                $tituloLog = '';
                $conteudoLog = '';

                // === MODO DISCOVER: prompt.md único via Sonnet ===
                if ($isDiscover) {
                    try {
                        $debate = new DebateBuilder($claude);
                        $debate->setOwnDomain((string)($cfg['wp_url'] ?? ''));
                        // Nome da redação pro rodapé autoral (decisão editorial 2026-05-05)
                        $cfgSiteAtual = ($modoCluster && isset($cfgItem)) ? $cfgItem : $cfg;
                        $debate->setSiteName((string)($cfgSiteAtual['name'] ?? $cfgSiteAtual['site_name'] ?? ''));

                        // Monta conteúdo scrapeado como texto limpo + links inline
                        $conteudoScrapeado = '';
                        $linksFonte = [];
                        foreach ($fontes as $f) {
                            $conteudoScrapeado .= ($f['meta']['title'] ?? '') . "\n";
                            if (!empty($f['content']['headings'])) {
                                foreach ($f['content']['headings'] as $h) $conteudoScrapeado .= "[{$h['tag']}] {$h['text']}\n";
                            }
                            $conteudoScrapeado .= implode("\n", $f['content']['paragraphs'] ?? []) . "\n";
                            if (!empty($f['content']['lists'])) {
                                foreach ($f['content']['lists'] as $list) {
                                    foreach ($list as $li) $conteudoScrapeado .= "- {$li}\n";
                                }
                            }
                            if (!empty($f['content']['links'])) {
                                foreach ($f['content']['links'] as $lk) $linksFonte[] = $lk;
                            }
                        }
                        // Anexa LINKS DA FONTE (URLs extraídas dos <a href> dos parágrafos)
                        // pra LLM usar em frases tipo "Inscrição em [link]" em vez de "acesse o portal genérico".
                        if (!empty($linksFonte)) {
                            $unicosLinks = [];
                            foreach ($linksFonte as $lk) {
                                $unicosLinks[$lk['href']] = $lk;
                            }
                            $linksFonte = array_values(array_slice($unicosLinks, 0, 8));
                            $conteudoScrapeado .= "\n═══ LINKS OFICIAIS DA FONTE (use como href EXATO em frases de ação como 'Inscreva-se em [link]', 'Edital em [link]'. NÃO mencione 'site oficial' sem incluir o link real abaixo) ═══\n";
                            foreach ($linksFonte as $lk) {
                                $conteudoScrapeado .= "- ANCHOR: \"{$lk['anchor']}\" | HREF: {$lk['href']} | HOST: {$lk['host']}\n";
                            }
                        }

                        // CITAÇÕES DIRETAS — falas de autoridade extraídas das aspas da fonte
                        $quotesFonte = [];
                        foreach ($fontes as $f) {
                            if (!empty($f['content']['quotes'])) {
                                foreach ($f['content']['quotes'] as $q) $quotesFonte[] = $q;
                            }
                        }
                        if (!empty($quotesFonte)) {
                            $conteudoScrapeado .= "\n═══ CITAÇÕES DIRETAS DA FONTE (use como <blockquote> com <cite> quando autoridade da declaração agregar valor — ministros, presidentes, especialistas. NÃO inventar nem parafrasear — manter LITERAL com aspas) ═══\n";
                            foreach (array_slice($quotesFonte, 0, 5) as $q) {
                                $atrib = $q['atribuicao'] !== '' ? " | ATRIBUIÇÃO: {$q['atribuicao']}" : '';
                                $conteudoScrapeado .= "- TEXTO: \"{$q['texto']}\"{$atrib}\n";
                            }
                        }

                        $tituloRss = $keyword;
                        $fonteUrl = $url ?: ($fontes[0]['meta']['url'] ?? '');
                        // Passa TODOS os backlinks (DebateBuilder separa: 3 pro P1/P2/P3 + 3 pro Leia Também)
                        $blParaLeia = array_map(fn($b) => ['title'=>$b['title']??'','link'=>$b['link']??'','slug'=>''], array_slice($linksInternos, 0, 8));

                        // Padrões a evitar = persistidos (últimas 24h deste site) + já usados nesta rodada
                        $cfgLocalPad = $modoCluster ? ($cfgItem ?? $cfg) : $cfg;
                        $wpUrlPad = $cfgLocalPad['wp_url'] ?? '';
                        $padroesPersistidos = $padroesStore->carregar($wpUrlPad);
                        $padroesEvitar = array_values(array_unique(array_merge($padroesPersistidos, $padroesUsadosCluster)));
                        // 2026-05-07 #1862: ângulos do H2 final (1-16) já usados recentemente neste site (anti-footprint editorial)
                        $angulosRecentes = $angulosStore->carregar($wpUrlPad);
                        // Títulos recentes do site + títulos já gerados nesta rodada (cluster/batch)
                        $titulosRecentes = $padroesStore->carregarTitulos($wpUrlPad, 5);
                        foreach ($postsDaRodada as $pr) {
                            $t = trim((string)($pr['titulo'] ?? ''));
                            if ($t !== '' && !in_array($t, $titulosRecentes, true)) array_unshift($titulosRecentes, $t);
                        }
                        $titulosRecentes = array_slice($titulosRecentes, 0, 5);

                        // SERP analysis: top 10 títulos da 1ª página do Google pra esta keyword
                        // (referência de autoridade). Filtra contextuais (remove site:, link:, query operators
                        // residuais). Falha silenciosa se Serper indisponível ou sem saldo.
                        $titulosSerp = [];
                        try {
                            if (!empty($cfg['serper_api_key'])) {
                                $serperLocal = new Serper($cfg['serper_api_key']);
                                $queryServ = trim($keyword) !== '' ? trim($keyword) : trim($tituloRss);
                                if ($queryServ !== '') {
                                    $resp = $serperLocal->search($queryServ, 10);
                                    foreach (($resp['organic'] ?? []) as $org) {
                                        $t = trim((string)($org['title'] ?? ''));
                                        if ($t === '' || mb_strlen($t) < 15 || mb_strlen($t) > 130) continue;
                                        // Remove sufixos comuns de portal ("- Portal X", "| Site Y")
                                        $t = preg_replace('/\s*[\|\-–—]\s*[A-Z][\w\s\.]{2,40}$/u', '', $t) ?? $t;
                                        // Filtra títulos com query operators residuais
                                        if (preg_match('/\b(site:|link:|inurl:|intitle:)/i', $t)) continue;
                                        $titulosSerp[] = $t;
                                    }
                                    $titulosSerp = array_values(array_unique(array_slice($titulosSerp, 0, 10)));
                                }
                            }
                        } catch (Throwable $e) { /* sem SERP: prompt usa fallback "Nenhum" */ }

                        // Retry com ESCALAÇÃO (otimizado 2026-05-06: 32K era gordura — artigos saem ~1500-2000 palavras = ~4K tokens output).
                        // 1ª=12K tokens/12K chars (cobertura confortável), 2ª=16K/8K (caso artigo gigante tipo "58 mensagens").
                        // Reduz tempo de geração + buffer alocado, mantém qualidade.
                        $tentativasConfig = [
                            ['maxTokens' => 12000, 'conteudoLimit' => 12000],
                            ['maxTokens' => 16000, 'conteudoLimit' => 8000],
                        ];
                        $maxTentativas = count($tentativasConfig);
                        $artigo = null;
                        $ultimoErro = '';
                        $validatorDatas = new DataCoerenciaValidator();
                        $coerencia = null;
                        for ($tentativa = 1; $tentativa <= $maxTentativas; $tentativa++) {
                            $cfgT = $tentativasConfig[$tentativa - 1];
                            try {
                                $artigo = $debate->gerar(
                                    $tituloRss, $conteudoScrapeado, $blParaLeia, $fonteUrl,
                                    $padroesEvitar, $titulosRecentes,
                                    (string)($cfgLocalPad['amazon_affiliate_url'] ?? ''),
                                    $cfgT['maxTokens'], $cfgT['conteudoLimit'],
                                    (array)($item['_dna'] ?? []),
                                    $modoCluster ? ($clusterPillar ?? null) : null,
                                    $titulosSerp,
                                    $angulosRecentes
                                );
                                if (empty($artigo['content_html'])) {
                                    $ultimoErro = 'HTML vazio';
                                    if ($tentativa < $maxTentativas) sleep(2);
                                    continue;
                                }
                                // Validação de coerência de datas (compliance Discover)
                                $coerencia = $validatorDatas->verificar(
                                    (string)($artigo['title'] ?? ''),
                                    (string)$artigo['content_html'],
                                    (string)$conteudoScrapeado
                                );
                                if ($coerencia['coerente']) {
                                    $tituloLog .= ' [datas:' . $coerencia['resumo'] . ']';
                                    break; // sucesso real
                                }
                                // Divergência: força retry se ainda tem tentativa; senão publica com flag
                                $ultimoErro = 'Divergência de datas: ' . implode(',', $coerencia['divergencias']) . ' no título não casa com corpo/fonte';
                                if ($tentativa < $maxTentativas) {
                                    $tituloLog .= ' [datas-div→retry]';
                                    $artigo = null;
                                    sleep(2);
                                    continue;
                                }
                                $tituloLog .= ' [⚠️datas-div-publicado]';
                                break;
                            } catch (Throwable $e) {
                                $ultimoErro = $e->getMessage();
                                if ($tentativa < $maxTentativas) sleep(2);
                            }
                        }
                        if (!$artigo || empty($artigo['content_html'])) {
                            throw new RuntimeException("DebateBuilder falhou após {$maxTentativas} tentativas: {$ultimoErro}");
                        }
                        $html = $artigo['content_html'];

                        /* TRACING PERMANENTE — 2026-05-07 auditoria autoFixForcarP3 #2369-2373:
                         * Registra contagem de P na intro APÓS cada autoFix, num arquivo único por
                         * site/dia. Permite identificar pós-mortem qual etapa derruba 3P→2P.
                         * Custo: 6 arquivo_appends por geração (~5KB/dia). Pode ser desativado
                         * setando ENV CLONAIS_TRACE_AUTOFIX=0. */
                        $clonaisTrace = !($_ENV['CLONAIS_TRACE_AUTOFIX'] ?? '') === '0';
                        $contarPIntro = function($h) {
                            if (!preg_match('/<h2/i', $h, $m, PREG_OFFSET_CAPTURE)) return -1;
                            $intro = preg_replace('/<div[^>]*class=["\'][^"\']*(?:aviso-bloqueado-gate|leia-mais-box)[^"\']*["\'][^>]*>.*?<\/div>/is', '', substr($h, 0, $m[0][1]));
                            preg_match_all('/<p\b([^>]*)>(.*?)<\/p>/is', $intro, $pp, PREG_SET_ORDER);
                            $n = 0;
                            foreach ($pp as $row) {
                                if (preg_match('/class\s*=\s*[\'"][^\'"]*(?:resposta-direta|snippet-resumo|leia-mais|leia-tambem|alerta-critico|fonte-rodape)[^\'"]*[\'"]/i', $row[1])) continue;
                                if (str_word_count(trim(strip_tags($row[2]))) < 6) continue;
                                $n++;
                            }
                            return $n;
                        };
                        $traceAutoFix = function($etapa) use (&$html, $contarPIntro, $clonaisTrace) {
                            if (!$clonaisTrace) return;
                            $n = $contarPIntro($html);
                            $logF = __DIR__ . '/data/debug/autofix_trace_' . date('Y-m-d') . '.log';
                            @file_put_contents($logF, '[' . date('H:i:s') . "] {$etapa}: intro_p={$n}\n", FILE_APPEND);
                        };

                        $traceAutoFix('00_sonnet_original');

                        // Safety net: quebra <p> com >40 palavras em múltiplos parágrafos na fronteira de frase
                        $html = quebrarParagrafosLongos($html, 999);
                        $traceAutoFix('01_quebrar');
                        // Sanitiza travessões (—/–) no texto — assinatura de IA; vira vírgula
                        $html = sanitizarTravessoes($html);
                        $traceAutoFix('02_sanitizar');

                        /* AUTO-FIX PROGRAMÁTICO — correções mecânicas DETERMINÍSTICAS:
                         *   1. Mover P4+ (sem class) pra DEPOIS do 1º <h2>
                         *   2. Forçar P3 quando intro tem 2P (divide P maior em duas frases)
                         *   3. Mover <p class='resposta-direta'> da intro pra ANTES do rodapé
                         *   4. Remover reticências excessivas (>1 ocorrência) */
                        $html = autoFixIntroInflada($html);
                        $traceAutoFix('03_autoFixIntroInflada');
                        $html = autoFixForcarP3($html);
                        $traceAutoFix('04_autoFixForcarP3');
                        $html = autoFixDiaSemanaInconsistente($html);
                        $traceAutoFix('05_autoFixDiaSemana');
                        $html = autoFixRdParaFechamento($html);
                        $traceAutoFix('06_RdFechamento');
                        $html = autoFixReticenciasExcessivas($html);
                        $traceAutoFix('07_Reticencias');
                        $html = autoFixRemoverAtribuicaoVeiculo($html);
                        $traceAutoFix('08_RemoverAtribuicao');
                        $html = autoFixSanitizarJsonLd($html); // 2026-05-07 #2371: GSC "Tipo de valor incorreto" — limpa <br>/<p> dentro de scripts JSON-LD
                        $traceAutoFix('08b_sanitizarJsonLd');
                        // AutoFix: remove rodapé legado "<p>Fonte: ...</p>" (decisão 2026-05-05: só rodapé autoral).
                        $html = preg_replace('#<p\b[^>]*>\s*Fonte\s*:.*?</p>#is', '', $html) ?? $html;
                        // 2026-05-06: pass extra de autoFix RD APÓS toda manipulação anterior.
                        // Garante RD ANTES do rodapé autoral (caso #1819: scripts JSON-LD ou outras injeções
                        // empurram RD pro final). Idempotente — não muda nada se já está correto.
                        $html = autoFixRdParaFechamento($html);

                        /* GATE PÓS-PROCESSAMENTO — bug observado 2026-05-04 #2176:
                         * quebrarParagrafosLongos pode transformar 3p (OK no AntiAI dentro do
                         * DebateBuilder) em 4p+ (intro-inflada). E a RD na intro vinda do Sonnet
                         * sobrevive se o `melhorou` aceitar regen baseado só em phrase violations.
                         * Re-validamos AQUI no HTML FINAL e injetamos aviso vermelho se sentinel
                         * forca-regen ainda presente. */
                        try {
                            if (!class_exists('AntiAIValidator')) require_once __DIR__ . '/lib/AntiAIValidator.php';
                            $valFinal = new AntiAIValidator();
                            $reportFinal = $valFinal->validate($html);
                            $temForcaRegenFinal = false;
                            $issuesCriticosFinal = [];
                            foreach ((array)($reportFinal['structural'] ?? []) as $iss) {
                                if (!is_string($iss)) continue;
                                if (str_contains($iss, '-forca-regen') || str_contains($iss, '-forca-fail')) {
                                    $temForcaRegenFinal = true;
                                } elseif (preg_match('/^(intro-inflada|intro-redundancia|prompt-leak|redundancia-p[0-9]?-resposta-direta|redundancia-p1-p3|gatilho-batido|paragrafo-paredao|tom-edital|rd-na-intro|frase-composta-pesada)/i', $iss)) {
                                    $issuesCriticosFinal[] = $iss;
                                }
                            }
                            if ($temForcaRegenFinal && ($reportFinal['severity'] ?? '') === 'fail') {
                                $marcador = 'RASCUNHO BLOQUEADO PELO ANTIAIVALIDATOR (PÓS-PROCESSAMENTO)';
                                if (stripos($html, $marcador) === false && stripos($html, 'RASCUNHO BLOQUEADO') === false) {
                                    $aviso = "<div class='aviso-bloqueado-gate' style='background:#fef2f2;border:2px solid #dc2626;border-left:6px solid #b91c1c;border-radius:8px;padding:14px 18px;margin:0 0 18px;'>"
                                          . "<strong style='color:#991b1b;font-size:15px'>🚨 {$marcador}</strong>"
                                          . "<p style='margin:6px 0 0;color:#7f1d1d;font-size:13px'>Issues críticos detectados APÓS quebrar/sanitizar (validador interno do DebateBuilder pode não ter visto). REVISE MANUALMENTE antes de publicar:</p>"
                                          . "<ul style='margin:8px 0 0;color:#7f1d1d;font-size:13px;padding-left:22px'>";
                                    foreach (array_slice($issuesCriticosFinal, 0, 5) as $iss) {
                                        $aviso .= '<li>' . htmlspecialchars($iss) . '</li>';
                                    }
                                    $aviso .= '</ul></div>';
                                    $html = $aviso . $html;
                                    $tituloLog .= ' [🚨gate-pos-proc]';
                                }
                            }
                        } catch (Throwable $e) { /* gate pós-proc não bloqueia geração */ }

                        /* GATE NUMÉRICO PRÉ-PUBLISH (2026-05-07 #2371): valida 5 critérios mínimos
                         * de autoridade antes de salvar. NUNCA bloqueia o save em draft (Sonnet já gastou),
                         * mas marca com flags claras pra revisão manual. NÃO permite publish automático
                         * quando crítico. */
                        try {
                            if (!class_exists('RankMathSeoValidator')) require_once __DIR__ . '/lib/RankMathSeoValidator.php';
                            $kwGate = RankMathSeoValidator::derivarKeywordDoTitulo((string)($artigo['title'] ?? $keyword));
                            $kwGateRefinada = RankMathSeoValidator::escolherMelhorKeyword($kwGate, $html);
                            $rGate = RankMathSeoValidator::validar($html, [
                                'titulo' => (string)($artigo['title'] ?? $keyword),
                                'meta_title' => (string)($artigo['title'] ?? ''),
                                'meta_desc' => (string)($artigo['meta_description'] ?? ''),
                                'slug' => (string)($artigo['slug'] ?? ''),
                                'focus_keyword' => $kwGateRefinada,
                                'featured_alt' => '',
                                'own_domain' => $cfgLocalPad['wp_url'] ?? '',
                            ]);
                            // Conta P na intro
                            $pIntroFinal = $contarPIntro($html);
                            // Critérios CRÍTICOS (qualquer um = post não vai pra publish auto)
                            $criticosNum = [];
                            if ($pIntroFinal !== -1 && $pIntroFinal !== 3) $criticosNum[] = "intro_p={$pIntroFinal} (esperado 3)";
                            if (($rGate['score'] ?? 0) < 60) $criticosNum[] = "rankmath_score=" . ($rGate['score'] ?? 0) . " (mínimo 60)";
                            if (($rGate['densidade'] ?? 0) < 0.3) $criticosNum[] = "kw_densidade=" . ($rGate['densidade'] ?? 0) . "% (mínimo 0.3%)";
                            if (($rGate['densidade'] ?? 0) > 3.0) $criticosNum[] = "kw_densidade=" . ($rGate['densidade'] ?? 0) . "% (máximo 3% — stuffing)";
                            // Footprint H2 final
                            if (preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $html, $hsG) && !empty($hsG[1])) {
                                $ulth2 = trim(strip_tags(end($hsG[1])));
                                if (preg_match('/^\s*o\s+que\s+.{2,80}\s+(sinaliza|muda|mostra|indica|representa|significa|revela)/iu', $ulth2)) {
                                    $criticosNum[] = "footprint_h2_final='{$ulth2}'";
                                }
                            }
                            if (!empty($criticosNum)) {
                                $tituloLog .= ' [🚨CRITICO:' . count($criticosNum) . ']';
                                @file_put_contents(__DIR__ . '/data/debug/gate_critico_' . date('Y-m-d') . '.log',
                                    '[' . date('H:i:s') . '] kw=' . $kwGateRefinada . ' | issues=' . implode(' | ', $criticosNum) . "\n", FILE_APPEND);
                                if (stripos($html, 'aviso-bloqueado-gate') === false) {
                                    $avisoNum = "<div class='aviso-bloqueado-gate' style='background:#fef2f2;border:2px solid #dc2626;border-left:6px solid #b91c1c;border-radius:8px;padding:14px 18px;margin:0 0 18px;'>"
                                        . "<strong style='color:#991b1b;font-size:15px'>🚨 GATE NUMÉRICO BLOQUEOU PUBLICAÇÃO AUTOMÁTICA</strong>"
                                        . "<p style='margin:6px 0 0;color:#7f1d1d;font-size:13px'>" . count($criticosNum) . " critério(s) crítico(s) abaixo do mínimo. Revise antes de mudar status pra publish:</p>"
                                        . "<ul style='margin:8px 0 0;color:#7f1d1d;font-size:13px;padding-left:22px'>";
                                    foreach ($criticosNum as $cN) $avisoNum .= '<li>' . htmlspecialchars($cN) . '</li>';
                                    $avisoNum .= '</ul></div>';
                                    $html = $avisoNum . $html;
                                }
                            }
                        } catch (Throwable $e) { /* gate numérico não bloqueia geração */ }

                        foreach ($artigo['_debate_log'] ?? [] as $dl) $tituloLog .= " [{$dl}]";
                        $keyword = $artigo['title'] ?? $keyword;

                        // 2ª CAMADA: OpenAI (GPT) revisa APENAS o título contra o conteúdo completo.
                        // Se nota < 10 E existe sugestão → substitui o título (ataca concordância, ortografia,
                        // front-loading, tempo verbal). OpenAI aqui usa o mesmo v10.6 internalmente em avaliarTitulo.
                        if (!empty($cfg['openai_api_key']) && !empty($artigo['title'])) {
                            try {
                                $openaiTit = new OpenAI($cfg['openai_api_key']);
                                $av = $openaiTit->avaliarTitulo((string)$artigo['title'], $conteudoScrapeado, (string)$tituloRss);
                                $tituloLog .= " [T-GPT:{$av['nota']}/10]";
                                if ((int)$av['nota'] < 10 && !empty($av['sugestao'])) {
                                    $novoTit = trim((string)$av['sugestao']);
                                    $len = mb_strlen($novoTit);
                                    if ($len >= 45 && $len <= 75) {
                                        $artigo['title']            = $novoTit;
                                        $artigo['meta_title']       = $novoTit;
                                        $artigo['hero_alt']         = $novoTit;
                                        $keyword                    = $novoTit;
                                        $tituloLog .= " [T→GPT]";
                                    }
                                }
                            } catch (Throwable $e) { /* silencia: falhou GPT → segue com título do Claude */ }
                        }

                        // Registra padrão + título usado em TODOS os modos: in-memory + persistência por site
                        if (!empty($artigo['padrao_titulo'])) {
                            $pt = (int)$artigo['padrao_titulo'];
                            if ($pt >= 1 && $pt <= 6) {
                                if (!in_array($pt, $padroesUsadosCluster, true)) $padroesUsadosCluster[] = $pt;
                                $padroesStore->registrar($wpUrlPad, $pt, (string)($artigo['title'] ?? ''));
                                $evitadosLog = !empty($padroesEvitar) ? ' evit:' . implode(',', $padroesEvitar) : '';
                                $tituloLog .= " [P{$pt}{$evitadosLog}]";
                            }
                        }

                        // 2026-05-07 #1862: identifica e registra o ângulo do H2 final usado neste artigo (anti-footprint)
                        if (preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $html, $h2sFinal) && !empty($h2sFinal[1])) {
                            $ultimoH2 = trim(strip_tags(end($h2sFinal[1])));
                            $anguloId = AngulosH2Final::inferirAngulo($ultimoH2);
                            if ($anguloId > 0) {
                                $angulosStore->registrar($wpUrlPad, $anguloId, $ultimoH2);
                                $tituloLog .= " [A{$anguloId}]";
                            }
                        }

                        // Coleta hosts INTERNOS: próprio site + todos os sites do cluster (se modo cluster)
                        $hostsInternos = [];
                        $cfgLocal = $modoCluster ? ($cfgItem ?? $cfg) : $cfg;
                        $hp = strtolower(parse_url($cfgLocal['wp_url'] ?? '', PHP_URL_HOST) ?: '');
                        if ($hp) $hostsInternos[] = $hp;
                        if ($modoCluster) {
                            foreach ($itensParaProcessar as $it) {
                                $s = $sites[$it['site_slug'] ?? ''] ?? [];
                                $h = strtolower(parse_url($s['wp_url'] ?? '', PHP_URL_HOST) ?: '');
                                if ($h && !in_array($h, $hostsInternos, true)) $hostsInternos[] = $h;
                            }
                        }
                        // Backlinks internos (próprio + cluster): mesma janela, sem target=_blank
                        // Externos comerciais (Amazon/ML/Magalu/etc): PrettyLinks + rel=sponsored nofollow
                        // Externos institucionais (gov/senac/inss): dofollow + target=_blank
                        $prefixPL = $cfgLocalPad['pretty_links_prefix'] ?? 'go';
                        $html = preg_replace_callback('#<a\s+([^>]*?)href=(["\'])(https?://[^"\']+)\2([^>]*)>(.*?)</a>#is', function($m) use ($hostsInternos, $plInstance, $prefixPL) {
                            $host = strtolower(parse_url($m[3], PHP_URL_HOST) ?: '');
                            $isInterno = false;
                            foreach ($hostsInternos as $hi) {
                                if ($hi && stripos($host, $hi) !== false) { $isInterno = true; break; }
                            }
                            if ($isInterno) {
                                $a = preg_replace('#\s*target\s*=\s*["\'][^"\']*["\']#i', '', $m[1] . $m[4]);
                                return '<a ' . trim($a) . ' href=' . $m[2] . $m[3] . $m[2] . '>' . $m[5] . '</a>';
                            }
                            $hostsComerciais = ['amzn.to','amazon.','mercadolivre.','mercadolibre.','magazineluiza.','magazinevoce.','shopee.','casasbahia.','kabum.','aliexpress.','shein.','americanas.','submarino.','extra.com','pontofrio.','girafa.'];
                            $isComercial = false;
                            foreach ($hostsComerciais as $hc) { if (stripos($host, $hc) !== false) { $isComercial = true; break; } }
                            $anchor = trim(strip_tags($m[5]));
                            $destino = $m[3];
                            if ($isComercial && $plInstance) {
                                $slugBase = $anchor !== '' ? $anchor : $host;
                                $slug = PrettyLinks::slugify($slugBase, $prefixPL);
                                try {
                                    $pretty = $plInstance->criarOuBuscar($m[3], $slug, $anchor ?: $host, true, '301');
                                    if ($pretty) $destino = $pretty;
                                } catch (Throwable $e) {}
                            }
                            $rel = $isComercial ? 'sponsored nofollow noopener' : 'dofollow noopener';
                            $a = preg_replace('#\s*rel\s*=\s*["\'][^"\']*["\']#i', '', $m[1].$m[4]);
                            $a = preg_replace('#\s*target\s*=\s*["\'][^"\']*["\']#i', '', $a);
                            return '<a '.trim($a).' href='.$m[2].htmlspecialchars($destino, ENT_QUOTES).$m[2].' rel="'.$rel.'" target="_blank">'.$m[5].'</a>';
                        }, $html) ?: $html;

                        // Msg-cards: injeta CSS+JS se o HTML contiver .msg-card (Copiar + WhatsApp)
                        $html = injetarMsgCardsAssets($html);
                        // Alerta crítico: injeta CSS se HTML contiver .alerta-critico (pattern interrupt visual)
                        $html = injetarAlertaCriticoAssets($html);

                        // Leia também após 3º </p>
                        // Leia Também agora é gerado pelo Claude via prompt.md (não injetar via PHP)

                        // FAQ + schemas — Claude já inclui no HTML via prompt.md (não duplicar)

                        $titulo = $artigo['title'] ?? $keyword;
                        // No modo cluster o slug É FORÇADO a bater com slugifyPt do keyword ORIGINAL pré-fetched
                        // ($item['keyword'], NÃO $keyword local — Claude reescreve $keyword em linha 444 via $artigo['title']).
                        // Isso garante que slugFinal == slug usado em linksInternos dos irmãos → backlinks válidos.
                        $kwParaSlug = $modoCluster && !empty($item['keyword']) ? $item['keyword'] : $keyword;
                        $slugFinal = $modoCluster ? slugifyPt($kwParaSlug) : slugifyPt($artigo['slug'] ?: $keyword);
                        if (mb_strlen($slugFinal)>60) $slugFinal = rtrim(mb_substr($slugFinal,0,60),'-');
                        // Frase chamativa pra overlay (HTML/CSS no tema OU queimada no pixel se queimar_overlay=ON)
                        // Prioriza imagem.overlay_chamativo do Claude → fallback PHP smart com excerpt+meta
                        $overlayChamativo = clonais_derivar_overlay(
                            $titulo,
                            (string)($artigo['meta_description'] ?? ''),
                            (string)($artigo['imagem']['descricao'] ?? ''),
                            (string)($artigo['imagem']['overlay_chamativo'] ?? '')
                        );

                        /* Auto-fix RankMath: focus_keyword única + meta_desc com kw + alt com kw (corrige o que o builder pode ter deixado escapar) */
                        $rmKw = aplicarRankMathSeoFix($artigo, $titulo, (string)$keyword);
                        $metaDescFinal = (string)($artigo['meta_description'] ?? '');
                        $tituloLog .= " [rmKw:{$rmKw}]";

                        $traceAutoFix('99_payload_final');
                        // 2026-05-07: SAFETY NET — se intro caiu pra <3 P até este ponto, força um split
                        // antes do save. Última oportunidade de salvar 3P sem regen Sonnet (custo zero).
                        if (function_exists('autoFixForcarP3')) {
                            $intP = $contarPIntro($html);
                            if ($intP === 2) {
                                $htmlBefore = $html;
                                $html = autoFixForcarP3($html);
                                $intPDepois = $contarPIntro($html);
                                if ($html !== $htmlBefore) {
                                    @file_put_contents(__DIR__ . '/data/debug/autofix_trace_' . date('Y-m-d') . '.log',
                                        '[' . date('H:i:s') . "] 99b_safety_split: intro_p={$intPDepois} (forçado pré-payload)\n", FILE_APPEND);
                                    $tituloLog .= ' [P3-forced]';
                                }
                            }
                        }
                        // 2026-05-07: extrai schemas JSON-LD do HTML pra post meta (mu-plugin injeta no <head>).
                        // Resolve bug GSC "Tipo de valor incorreto" — corpo é vulnerável a wpautop/editores visuais.
                        [$html, $clonaisSchemas] = autoFixExtrairSchemasParaMeta($html);
                        $clonaisSchemasJson = !empty($clonaisSchemas) ? json_encode($clonaisSchemas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
                        if ($clonaisSchemasJson !== '') $tituloLog .= ' [schemas:' . count($clonaisSchemas) . '→meta]';
                        $traceAutoFix('99c_schemas_para_meta');

                        $payload = ['title'=>$titulo,'slug'=>$slugFinal,'content'=>$html,'excerpt'=>$metaDescFinal,'status'=>'draft',
                            'meta'=>['rank_math_title'=>$titulo,'rank_math_description'=>$metaDescFinal,'rank_math_focus_keyword'=>$rmKw,
                                     'rank_math_facebook_title'=>$titulo,'rank_math_facebook_description'=>$metaDescFinal,
                                     'rank_math_twitter_title'=>$titulo,'rank_math_twitter_description'=>$metaDescFinal,
                                     'rank_math_rich_snippet'=>'off',
                                     '_clonais_image_overlay'=>$overlayChamativo,
                                     '_clonais_schemas'=>$clonaisSchemasJson]];
                        if (!empty($artigo['tags'])) { try { $payload['tags']=$wp->resolverTags($artigo['tags']); } catch(Throwable $e){} }
                        if (!empty($artigo['categories'])) {
                            try {
                                require_once __DIR__ . '/lib/CategoryMatcher.php';
                                $catMatcher = new CategoryMatcher($wp, 70.0);
                                $payload['categories'] = $catMatcher->resolverComMatch($artigo['categories']);
                                if (!empty($catMatcher->log)) $tituloLog .= ' [cat:' . count($catMatcher->log) . 'res]';
                            } catch(Throwable $e) {
                                // Fallback pro fluxo antigo se matcher falhar
                                try { $payload['categories']=$wp->resolverCategorias($artigo['categories']); } catch(Throwable $e2){}
                            }
                        }

                        // Featured
                        $heroUrl=''; $featuredInfo=''; $featuredWpUrl='';
                        $imagemGeradaIA = false;
                        // Se toggle "gerar imagem IA" ativo + tem API key → gera via OpenAI (16:9 dall-e-3 1792x1024)
                        // Senão, usa og:image da fonte (fluxo original)
                        if ($gerarImgIA && !empty($cfg['openai_api_key'])) {
                            try {
                                $openaiImg = new OpenAI($cfg['openai_api_key']);
                                // Pré-deriva overlay (mesma frase usada no sticker amarelo do prompt + opcional burn pós-geração)
                                $overlayChamativoPre = clonais_derivar_overlay(
                                    (string)($artigo['title'] ?? $keyword),
                                    (string)($artigo['meta_description'] ?? ''),
                                    (string)($artigo['imagem']['descricao'] ?? ''),
                                    (string)($artigo['imagem']['overlay_chamativo'] ?? '')
                                );
                                $promptImg = construirPromptImagem(
                                    (string)($artigo['title'] ?? $keyword),
                                    (string)$keyword,
                                    (string)($artigo['meta_description'] ?? ''),
                                    (string)($artigo['imagem']['imagem_prompt'] ?? ''),
                                    $overlayChamativoPre
                                );

                                // Prefixo anti-rewriting (oficial OpenAI): pede pro DALL-E
                                // RESPEITAR o prompt sem reescrever agressivamente. Sem isso,
                                // o prompt sai diferente do que cola direto no ChatGPT.
                                $promptImg = "I NEED to test how the tool works with extremely specific prompts. DO NOT add any detail, just use it AS-IS:\n\n" . $promptImg;

                                // style 'vivid' (cores saturadas, contraste alto, look high-CTR)
                                // que é o default do ChatGPT — `natural` deixava look documental sério
                                $resImg = $openaiImg->gerarImagemDetalhado($promptImg, '1792x1024', 'hd', 'vivid', 'dall-e-3');
                                if ($resImg && !empty($resImg['url'])) {
                                    $heroUrl = $resImg['url'];
                                    $imagemGeradaIA = true;
                                    $featuredInfo .= ' · 🎨 IA';
                                    // Loga o revised_prompt (o que DALL-E REALMENTE usou) — debugar diferenças
                                    if (!empty($resImg['revised_prompt'])) {
                                        $revPreview = mb_substr($resImg['revised_prompt'], 0, 120, 'UTF-8') . '…';
                                        $featuredInfo .= ' · rev:' . $revPreview;
                                    }
                                }
                            } catch (Throwable $e) { /* falha silenciosa → cai no fallback og:image */ }
                        }

                        // Garante que os 3 campos de metadados da imagem existem e são DISTINTOS.
                        // Fallbacks educados quando Claude omitiu. Sufixo "- Foto: Dall-e-3" na legenda quando IA gerou.
                        if (!isset($artigo['imagem']) || !is_array($artigo['imagem'])) $artigo['imagem'] = [];
                        $tituloArt = (string)($artigo['title'] ?? $keyword);
                        $metaDescArt = (string)($artigo['meta_description'] ?? '');
                        // alt_text (acessibilidade + image search): descreve visualmente; começa por substantivo
                        if (empty($artigo['imagem']['alt_text'])) {
                            $artigo['imagem']['alt_text'] = 'Imagem ilustrativa sobre ' . mb_strtolower($keyword ?: $tituloArt, 'UTF-8');
                        }
                        // legenda (visível sob a imagem): foco editorial, curta
                        if (empty($artigo['imagem']['legenda'])) {
                            $artigo['imagem']['legenda'] = rtrim($tituloArt, '.');
                        }
                        // Sufixo crédito quando imagem foi gerada por dall-e-3
                        if ($imagemGeradaIA) {
                            // Remove crédito duplicado se já existir
                            $artigo['imagem']['legenda'] = preg_replace('/\s*[,.\-—–|]\s*Foto:.+$/iu', '', (string)$artigo['imagem']['legenda']) ?? $artigo['imagem']['legenda'];
                            $artigo['imagem']['legenda'] = rtrim((string)$artigo['imagem']['legenda'], " .,;:") . '. - Foto: Dall-e-3';
                        }
                        // descricao (acessibilidade longa): rica, inclui contexto do tema
                        if (empty($artigo['imagem']['descricao'])) {
                            $base = $metaDescArt !== '' ? $metaDescArt : $tituloArt;
                            $artigo['imagem']['descricao'] = 'Imagem editorial ilustrando ' . mb_strtolower($keyword ?: $tituloArt, 'UTF-8') . '. ' . rtrim($base, '.') . '.';
                        }
                        if ($heroUrl === '') {
                            foreach ($fontes as $f) { if (!empty($f['meta']['og_image'])) { $heroUrl=$f['meta']['og_image']; break; } }
                        }
                        if ($heroUrl && preg_match('#^https?://#', $heroUrl)) {
                            try {
                                $imgAlt = $artigo['imagem']['alt_text'] ?? $titulo;
                                $mid = null;

                                // Caminho A — queimar overlay no pixel: baixa, queima, sobe binário
                                if ($queimarOverlay) {
                                    require_once __DIR__ . '/lib/ImagemOptimizer.php';
                                    $opt = new ImagemOptimizer();
                                    $ch = curl_init($heroUrl);
                                    curl_setopt_array($ch, [
                                        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                                        CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'Mozilla/5.0',
                                        CURLOPT_SSL_VERIFYPEER => false,
                                    ]);
                                    $bin = curl_exec($ch);
                                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                    curl_close($ch);

                                    if ($bin !== false && $code < 400) {
                                        $binComOverlay = $opt->aplicarOverlayNetflix($bin, $overlayChamativo, '#c4170c', 88);
                                        if ($binComOverlay) {
                                            $mid = $wp->uploadImagemBinario($binComOverlay, $slugFinal, $imgAlt, 'jpg');
                                            if ($mid) { $featuredInfo .= ' · 🔥 overlay'; }
                                        }
                                    }
                                }

                                // Caminho B — fluxo padrão (sem overlay no pixel)
                                if (!$mid) {
                                    $mid = $wp->uploadImagemPorUrl($heroUrl, $imgAlt, $slugFinal);
                                }

                                if($mid){
                                    $payload['featured_media']=$mid;$featuredInfo=" · 🖼️ #{$mid}".$featuredInfo;
                                    if ($queimarOverlay) {
                                        // Sinaliza pro tema não duplicar o overlay via CSS
                                        $payload['meta']['_clonais_image_overlay_burned'] = '1';
                                    }
                                    // Atualiza legenda, descrição e título da imagem
                                    $imgMeta = [];
                                    if (!empty($artigo['imagem']['legenda']))   $imgMeta['caption']     = $artigo['imagem']['legenda'];
                                    if (!empty($artigo['imagem']['descricao'])) $imgMeta['description'] = $artigo['imagem']['descricao'];
                                    if (!empty($artigo['imagem']['alt_text']))  $imgMeta['title']       = $artigo['imagem']['alt_text'];
                                    if (!empty($imgMeta)) {
                                        try { $wp->atualizarMedia($mid, $imgMeta); $featuredInfo .= ' · 📝 meta'; }
                                        catch(Throwable $e){ $featuredInfo .= ' · ⚠️ meta: ' . $e->getMessage(); }
                                    }
                                    try{$mm=$wp->getMedia($mid);$featuredWpUrl=$mm['source_url']??'';}catch(Throwable $e){}
                                }
                            } catch(Throwable $e){}
                        }
                        // NewsArticle schema removido — Rank Math gera automaticamente

                        // Autor padrão do site (anti-PBN: cada site declara autor distinto via persona)
                        $cfgUse = $modoCluster ? ($cfgItem ?? $cfg) : $cfg;
                        if (!empty($cfgUse['default_post_author_id'])) {
                            $payload['author'] = (int)$cfgUse['default_post_author_id'];
                        }
                        $resp = $wp->criarPost($payload);
                        // link_planejado = baseado em slugFinal (o que os irmãos já embutiram como backlink)
                        // link_real = reconstruído com $resp['slug'] (slug REAL salvo pelo WP) — evita ?p=ID de drafts
                        $slugReal = (!empty($resp['slug'])) ? $resp['slug'] : $slugFinal;
                        $linkPlanejado = rtrim($cfgUse['wp_url'],'/').'/'.$slugFinal.'/';
                        $linkReal      = rtrim($cfgUse['wp_url'],'/').'/'.$slugReal.'/';
                        $r = ['formato'=>'discover','ok'=>true,'id'=>$resp['id']??null,'edit'=>rtrim($cfgUse['wp_url'],'/').'/'.$slugReal.'/','link'=>$linkReal,'titulo'=>$titulo,
                              'msg'=>'Post #'.($resp['id']??'?').' ('.str_word_count(strip_tags($html)).'w)'.$featuredInfo.$tituloLog];
                        $r['edit'] = rtrim($cfgUse['wp_url'],'/')."/wp-admin/post.php?post={$r['id']}&action=edit";
                        if ($r['id']) $postsDaRodada[] = ['id'=>$r['id'],'link'=>$linkReal,'link_planejado'=>$linkPlanejado,'titulo'=>$titulo];

                        // Web Story: cria automaticamente após cada post do cluster Discover
                        // Chama endpoint REST do plugin wp-web-stories-ai. Silent failure: erro não quebra cluster.
                        if ($r['id'] && $gerarWebstory && !empty($cfgUse['webstory_enabled'])) {
                            // Extrai resposta-direta do HTML (bloco factual GEO) pra passar como contexto
                            $respostaDireta = '';
                            if (preg_match('#<p\s+class=[\'"]resposta-direta[\'"][^>]*>(.*?)</p>#is', $html, $mrd)) {
                                $respostaDireta = trim(strip_tags($mrd[1]));
                            }
                            $wsPayload = [
                                'post_id'           => (int)$r['id'],
                                'min_scenes'        => (int)($cfgUse['webstory_min_scenes'] ?? 5),
                                'max_scenes'        => (int)($cfgUse['webstory_max_scenes'] ?? 9),
                                // Contexto editorial rico (Pacote C) — eleva qualidade das cenas
                                'keyword'           => (string)$keyword,
                                'meta_description'  => (string)($artigo['meta_description'] ?? ''),
                                'resposta_direta'   => $respostaDireta,
                                'imagem_prompt'     => (string)($artigo['imagem']['imagem_prompt'] ?? ''),
                                'dna'               => (array)($item['_dna'] ?? []),
                            ];
                            // 2 formatos de URL (pretty permalinks vs plain ?rest_route=)
                            $wsUrls = [
                                rtrim($cfgUse['wp_url'],'/') . '/wp-json/wp-wsai/v1/create-story',
                                rtrim($cfgUse['wp_url'],'/') . '/?rest_route=/wp-wsai/v1/create-story',
                            ];
                            $wsSuccess = false;
                            $wsCodeLast = 0;
                            foreach ($wsUrls as $wsUrl) {
                                $wsCh = curl_init($wsUrl);
                                curl_setopt_array($wsCh, [
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_POST           => true,
                                    CURLOPT_POSTFIELDS     => json_encode($wsPayload, JSON_UNESCAPED_UNICODE),
                                    CURLOPT_HTTPHEADER     => [
                                        'Content-Type: application/json',
                                        'Authorization: Basic ' . base64_encode($cfgUse['wp_user'].':'.$cfgUse['wp_app_password']),
                                    ],
                                    CURLOPT_TIMEOUT        => 90,
                                    CURLOPT_SSL_VERIFYPEER => false,
                                ]);
                                $wsResp = curl_exec($wsCh);
                                $wsCodeLast = curl_getinfo($wsCh, CURLINFO_HTTP_CODE);
                                curl_close($wsCh);
                                if ($wsCodeLast === 200 && $wsResp) {
                                    $wsData = json_decode($wsResp, true);
                                    if (!empty($wsData['success']) && !empty($wsData['story_id'])) {
                                        $r['msg'] .= ' · 📽️ WS #' . (int)$wsData['story_id'] . ' (' . (int)$wsData['scenes'] . ' cenas)';
                                        // Indexação Instant do Web Story: notifica Google em tempo real (bypassa crawl delay)
                                        if ($idxApi && !empty($wsData['view_url']) && $autoIdx) {
                                            try {
                                                $wsIx = $idxApi->indexar((string)$wsData['view_url'], 'URL_UPDATED');
                                                $r['msg'] .= !empty($wsIx['success']) ? ' · 📤WS' : ' · ⚠️WS-idx';
                                            } catch (Throwable $e) { /* silent */ }
                                        }
                                        $wsSuccess = true;
                                        break;
                                    }
                                }
                                // Se não foi 404 de "rota não existe", não adianta tentar formato alternativo
                                if ($wsCodeLast !== 404) break;
                            }
                            if (!$wsSuccess) {
                                $r['msg'] .= ' · ⚠️ WS: HTTP ' . $wsCodeLast;
                            }
                        }

                        // Social
                        if ($idxApi && $r['link']) { try { $ix=$idxApi->indexar($r['link'],'URL_UPDATED'); $r['msg'].=$ix['success']?' · 📤':''; } catch(Throwable $e){} }
                        if ($meta && $r['link']) {
                            $cap = $artigo['meta_description'] ?? $titulo;
                            $ht  = gerarHashtagsCompletas($artigo, (string)$keyword, (string)$html, 20);
                            $cIg = trim($cap . ($ht ? "\n\n".$ht : '') . "\n\nLink na bio: " . $r['link']);

                            // Baixa a featured localmente UMA VEZ (reuso em FB Page + IG Feed + Carrossel fotográfico)
                            $heroLocal = null;
                            if ($heroUrl && preg_match('#^https?://#', $heroUrl) && ($postFB || $igFeedUnico || ($postIG && $igCarrossel && $carrosselEstilo === 'fotografico'))) {
                                try {
                                    $heroBin = @file_get_contents($heroUrl);
                                    if ($heroBin) {
                                        $heroLocal = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hero_' . uniqid() . '.jpg';
                                        file_put_contents($heroLocal, $heroBin);
                                    }
                                } catch (Throwable $e) { $heroLocal = null; }
                            }

                            // 1. Facebook Page (foto + link)
                            if ($postFB && $heroUrl) {
                                try { $fb = $meta->postarFacebookFoto($heroUrl, $cap, $r['link']); $r['msg'] .= $fb['success'] ? ' · 📘' : ''; } catch (Throwable $e) {}
                            }

                            // 2. Instagram Feed UNICO (foto única, não carrossel) — reusa featured
                            if ($igFeedUnico && $heroUrl) {
                                try { $igF = $meta->postarInstagramFeed($heroUrl, $cIg); $r['msg'] .= !empty($igF['success']) ? ' · 📷' : ''; } catch (Throwable $e) {}
                            }

                            // 3. Instagram Carrossel (estilo tipográfico ou fotográfico)
                            if ($postIG && $igCarrossel) {
                                try {
                                    $sl = extrairSlidesDeHtml($html, 4);
                                    $sls = [['type' => 'hero', 'title' => $titulo, 'body' => $cap]];
                                    foreach ($sl as $t) $sls[] = $t;
                                    $sls[] = ['type' => 'cta', 'title' => 'Leia mais', 'body' => parse_url($r['link'], PHP_URL_HOST)];

                                    if (count($sls) >= 2) {
                                        $g = new CarrosselGenerator(
                                            sys_get_temp_dir() . '/c_' . uniqid(),
                                            ['name' => $cfgUse['site_name'] ?? '', 'handle' => '', 'primary' => '#0ea5e9']
                                        );
                                        // Escolhe estilo: fotográfico usa $heroLocal como fundo (todas as slides), tipográfico usa fundo liso
                                        if ($carrosselEstilo === 'fotografico' && $heroLocal && file_exists($heroLocal)) {
                                            $ps = $g->gerarFotografico($sls, $heroLocal);
                                            $r['msg'] .= ' · 🎨';
                                        } else {
                                            $ps = $g->gerar($sls);
                                        }
                                        $us = [];
                                        foreach ($ps as $i2 => $p) {
                                            $up = $wp->uploadImagemLocalJpg($p, 'Slide ' . ($i2 + 1));
                                            if ($up) $us[] = $up['source_url'];
                                        }
                                        foreach ($ps as $p) @unlink($p);
                                        if (count($us) >= 2) {
                                            $ig = $meta->postarInstagramCarrossel($us, $cIg);
                                            $r['msg'] .= $ig['success'] ? ' · 🎠' : '';
                                        }
                                    }
                                } catch (Throwable $e) {}
                            }

                            // Cleanup da hero local
                            if ($heroLocal && file_exists($heroLocal)) @unlink($heroLocal);
                        }
                        $resultados[] = $r;
                    } catch (Throwable $e) { $resultados[] = ['formato'=>'discover','ok'=>false,'msg'=>'Debate: '.$e->getMessage(),'id'=>null,'edit'=>'','link'=>'','titulo'=>$keyword]; }
                    if (count($itensParaProcessar) > 1 && $itemIdx < count($itensParaProcessar) - 1) sleep(3);
                    continue; // pula o foreach de formatos antigo
                }

                // Título Discover agora é gerado pelo Sonnet junto com o artigo (prompt.md)

                // ETAPA 4: Gerar artigo pra cada formato
                foreach ($fmtsDoItem as $fmt) {
                    $r = ['formato' => $fmt, 'ok' => false, 'msg' => '', 'id' => null, 'edit' => '', 'link' => '', 'titulo' => ''];
                    try {
                        // Claude gera artigo (com backlinks internos + termos virais + título discover no prompt)
                        $artigo = $claude->gerarArtigo($keyword, $termos . $termosVirais, $fontes, $fmt, $blocos, $linksInternos);
                        $html = $artigo['content_html'] ?? '';
                        if ($html === '') throw new RuntimeException('Claude retornou content_html vazio');
                        // Safety net: quebra <p> com >40 palavras em múltiplos parágrafos
                        $html = quebrarParagrafosLongos($html, 999);
                        // Sanitiza travessões (—/–) no texto — assinatura de IA; vira vírgula
                        $html = sanitizarTravessoes($html);
                        // Auto-fix programático: estrutura intro + RD única + reticências
                        $html = autoFixIntroInflada($html);
                        $html = autoFixForcarP3($html); // 2026-05-06 #1839: divide P2 em P2+P3 quando intro tem só 2P
                        $html = autoFixDiaSemanaInconsistente($html); // 2026-05-07 #1862: "nesta quarta" → "ontem"/"no dia X"
                        $html = autoFixRdParaFechamento($html);
                        $html = autoFixReticenciasExcessivas($html);
                        $html = autoFixRemoverAtribuicaoVeiculo($html);

                        // DEBATE CONTEÚDO: GPT avalia → se <10, Claude refina (máx 2 rounds)
                        $conteudoLog = '';
                        if ($isDiscover && !empty($cfg['openai_api_key'])) {
                            try {
                                $openaiC = new OpenAI($cfg['openai_api_key']);
                                $melhorHtml = $html;
                                $melhorNotaC = 0;
                                for ($cRound = 1; $cRound <= 3; $cRound++) {
                                    $avArt = $openaiC->avaliarArtigo($html, $keyword, $keyword);
                                    $conteudoLog .= " [C{$cRound}: {$avArt['nota']}/10 ({$avArt['palavras']}w)]";

                                    if ($avArt['nota'] > $melhorNotaC) { $melhorNotaC = $avArt['nota']; $melhorHtml = $html; }

                                    // Aceita ≥8 OU para se nota caiu
                                    if ($avArt['aprovado'] || $avArt['nota'] >= 8) break;
                                    if ($cRound > 1 && $avArt['nota'] <= $melhorNotaC && $avArt['nota'] < 8) {
                                        $html = $melhorHtml;
                                        $conteudoLog .= ' [revert→best]';
                                        break;
                                    }

                                    // GPT reprovou → Claude refina o artigo
                                    $feedbackContent = "AVALIAÇÃO DO EDITOR: {$avArt['nota']}/10\n"
                                        . "PROBLEMAS: " . implode(', ', $avArt['problemas']) . "\n"
                                        . "FEEDBACK: {$avArt['feedback']}\n"
                                        . "PALAVRAS ATUAIS: {$avArt['palavras']}\n\n"
                                        . "CORRIJA o artigo incorporando o feedback acima. Mantenha o título \"{$keyword}\".\n"
                                        . "REGRA: 600-700 palavras, máx 2 frases de pressão, dados com atribuição.";

                                    $artigoRefinado = $claude->gerarArtigo(
                                        $keyword,
                                        $termos . $termosVirais . "\n\n" . $feedbackContent,
                                        $fontes, $fmt, $blocos, $linksInternos
                                    );
                                    $htmlNovo = $artigoRefinado['content_html'] ?? '';
                                    if ($htmlNovo !== '') {
                                        $html = $htmlNovo;
                                        $artigo = $artigoRefinado;
                                    } else {
                                        break;
                                    }
                                }
                            } catch (Throwable $e) {
                                $conteudoLog .= ' [erro avaliação: ' . $e->getMessage() . ']';
                            }
                        }

                        // Coleta hosts INTERNOS: próprio site + todos os sites do cluster (se modo cluster)
                        $hostsInternos = [];
                        $cfgLocal = $modoCluster ? ($cfgItem ?? $cfg) : $cfg;
                        $hp = strtolower(parse_url($cfgLocal['wp_url'] ?? '', PHP_URL_HOST) ?: '');
                        if ($hp) $hostsInternos[] = $hp;
                        if ($modoCluster) {
                            foreach ($itensParaProcessar as $it) {
                                $s = $sites[$it['site_slug'] ?? ''] ?? [];
                                $h = strtolower(parse_url($s['wp_url'] ?? '', PHP_URL_HOST) ?: '');
                                if ($h && !in_array($h, $hostsInternos, true)) $hostsInternos[] = $h;
                            }
                        }
                        // Backlinks internos (próprio + cluster): mesma janela
                        // Externos comerciais (Amazon/ML/Magalu/etc): PrettyLinks + rel=sponsored nofollow
                        // Externos institucionais: dofollow + target=_blank
                        $prefixPL = $cfgLocal['pretty_links_prefix'] ?? 'go';
                        $html = preg_replace_callback(
                            '#<a\s+([^>]*?)href=(["\'])(https?://[^"\']+)\2([^>]*)>(.*?)</a>#is',
                            function ($m) use ($hostsInternos, $plInstance, $prefixPL) {
                                $host = strtolower(parse_url($m[3], PHP_URL_HOST) ?: '');
                                $isInterno = false;
                                foreach ($hostsInternos as $hi) {
                                    if ($hi && stripos($host, $hi) !== false) { $isInterno = true; break; }
                                }
                                if ($isInterno) {
                                    $a = preg_replace('#\s*target\s*=\s*["\'][^"\']*["\']#i', '', $m[1] . $m[4]);
                                    return '<a ' . trim($a) . ' href=' . $m[2] . $m[3] . $m[2] . '>' . $m[5] . '</a>';
                                }
                                $hostsComerciais = ['amzn.to','amazon.','mercadolivre.','mercadolibre.','magazineluiza.','magazinevoce.','shopee.','casasbahia.','kabum.','aliexpress.','shein.','americanas.','submarino.','extra.com','pontofrio.','girafa.'];
                                $isComercial = false;
                                foreach ($hostsComerciais as $hc) { if (stripos($host, $hc) !== false) { $isComercial = true; break; } }
                                $anchor = trim(strip_tags($m[5]));
                                $destino = $m[3];
                                if ($isComercial && $plInstance) {
                                    $slugBase = $anchor !== '' ? $anchor : $host;
                                    $slug = PrettyLinks::slugify($slugBase, $prefixPL);
                                    try {
                                        $pretty = $plInstance->criarOuBuscar($m[3], $slug, $anchor ?: $host, true, '301');
                                        if ($pretty) $destino = $pretty;
                                    } catch (Throwable $e) {}
                                }
                                $rel = $isComercial ? 'sponsored nofollow noopener' : 'dofollow noopener';
                                $a = preg_replace('#\s*rel\s*=\s*["\'][^"\']*["\']#i', '', $m[1] . $m[4]);
                                $a = preg_replace('#\s*target\s*=\s*["\'][^"\']*["\']#i', '', $a);
                                return '<a ' . trim($a) . ' href=' . $m[2] . htmlspecialchars($destino, ENT_QUOTES) . $m[2] . ' rel="' . $rel . '" target="_blank">' . $m[5] . '</a>';
                            },
                            $html
                        ) ?: $html;

                        // Msg-cards: injeta CSS+JS se o HTML contiver .msg-card (Copiar + WhatsApp)
                        $html = injetarMsgCardsAssets($html);
                        // Alerta crítico: injeta CSS se HTML contiver .alerta-critico (pattern interrupt visual)
                        $html = injetarAlertaCriticoAssets($html);

                        // 4a. Leia também — injetado APÓS o 3º parágrafo via posição manual
                        if ($leiaTbm) {
                            $searchTerm = $artigo['focus_keyword'] ?? $keyword;
                            if ($searchTerm !== '') {
                                try {
                                    $relacionados = $wp->buscarRelacionados($searchTerm, $leiaQtde);
                                    if (!empty($relacionados)) {
                                        $leiaHtml = montarLeiaTambem($relacionados);
                                        // Acha o 3º </p> por posição (não regex com limit que tem bug)
                                        $pos = 0;
                                        for ($pCount = 0; $pCount < 3; $pCount++) {
                                            $found = strpos($html, '</p>', $pos);
                                            if ($found === false) break;
                                            $pos = $found + 4; // avança depois do </p>
                                        }
                                        if ($pCount === 3 && $pos > 0) {
                                            $html = substr($html, 0, $pos) . $leiaHtml . substr($html, $pos);
                                        } else {
                                            // Fallback: se não tem 3 parágrafos, coloca antes da FAQ
                                            $faqPos = strpos($html, '<h2>Perguntas frequentes');
                                            if ($faqPos !== false) {
                                                $html = substr($html, 0, $faqPos) . $leiaHtml . substr($html, $faqPos);
                                            }
                                        }
                                    }
                                } catch (Throwable $e) {}
                            }
                        }

                        // FAQ + NewsArticle — NÃO injetar aqui (Claude já inclui FAQ no HTML, Rank Math faz NewsArticle)

                        // 5. Payload e publish
                        $titulo = $artigo['title'] ?? $keyword;

                        // Slug com keyword garantida — slugify da keyword se Claude não incluir
                        $slugClaude = trim((string)($artigo['slug'] ?? ''));
                        // Cluster: usa keyword ORIGINAL pré-fetched ($item['keyword']), nunca $keyword local
                        // — pra bater com slug usado em linksInternos dos irmãos.
                        $kwParaSlug = $modoCluster && !empty($item['keyword']) ? $item['keyword'] : ($keyword ?? '');
                        $slugKw = slugifyPt($kwParaSlug);
                        if ($modoCluster) {
                            $slugFinal = $slugKw;
                        } else {
                            // Se o slug do Claude não contém palavras da keyword, substitui
                            $kwTerms = array_filter(preg_split('/\s+/', $slugKw), fn($t) => mb_strlen($t) > 3);
                            $slugFinal = $slugClaude;
                            if ($slugClaude === '' || empty(array_filter($kwTerms, fn($t) => str_contains($slugClaude, $t)))) {
                                $slugFinal = $slugKw;
                            }
                        }
                        // Limite 60 chars para SEO
                        if (mb_strlen($slugFinal) > 60) $slugFinal = rtrim(mb_substr($slugFinal, 0, 60), '-');

                        /* Auto-fix RankMath: focus_keyword única + meta_desc com kw + alt com kw */
                        $rmKw = aplicarRankMathSeoFix($artigo, $titulo, (string)$keyword);
                        $metaDescFinal = (string)($artigo['meta_description'] ?? '');

                        $payload = [
                            'title'   => $titulo,
                            'slug'    => $slugFinal,
                            'content' => $html,
                            'excerpt' => $metaDescFinal,
                            'status'  => 'draft',
                            'meta'    => [
                                'rank_math_title'                => $artigo['meta_title'] ?? $titulo,
                                'rank_math_description'          => $metaDescFinal,
                                'rank_math_focus_keyword'        => $rmKw,
                                'rank_math_facebook_title'       => $artigo['meta_title'] ?? $titulo,
                                'rank_math_facebook_description' => $metaDescFinal,
                                'rank_math_twitter_title'        => $artigo['meta_title'] ?? $titulo,
                                'rank_math_twitter_description'  => $metaDescFinal,
                                'rank_math_rich_snippet'         => 'off',
                            ],
                        ];
                        // Tags e categorias
                        if (!empty($artigo['tags']))       { try { $payload['tags']       = $wp->resolverTags($artigo['tags']); } catch (Throwable $e) {} }
                        if (!empty($artigo['categories'])) {
                            try {
                                require_once __DIR__ . '/lib/CategoryMatcher.php';
                                $catMatcher = new CategoryMatcher($wp, 70.0);
                                $payload['categories'] = $catMatcher->resolverComMatch($artigo['categories']);
                                if (!empty($catMatcher->log)) $tituloLog .= ' [cat:' . count($catMatcher->log) . 'res]';
                            } catch (Throwable $e) {
                                // Fallback pro fluxo antigo se matcher falhar
                                try { $payload['categories'] = $wp->resolverCategorias($artigo['categories']); } catch (Throwable $e2) {}
                            }
                        }

                        // Imagem: prioridade 1) manual do form, 2) og:image da fonte
                        $heroUrl = '';
                        $imgDebug = '';
                        if ($imageUrlManual !== '' && preg_match('#^https?://#', $imageUrlManual)) {
                            $heroUrl = $imageUrlManual;
                            $imgDebug = 'manual';
                        } else {
                            foreach ($fontes as $f) {
                                if (!empty($f['meta']['og_image'])) { $heroUrl = $f['meta']['og_image']; $imgDebug = 'og:image'; break; }
                            }
                        }
                        // Featured image no WP (passa por conversão WebP automática)
                        $featuredInfo = '';
                        $featuredWpUrl = '';
                        if ($heroUrl && preg_match('#^https?://#', $heroUrl)) {
                            try {
                                $alt = $artigo['imagem']['alt_text'] ?? $artigo['hero_alt'] ?? ($artigo['title'] ?? $keyword);
                                $mediaId = $wp->uploadImagemPorUrl($heroUrl, $alt, $slugFinal);
                                if ($mediaId) {
                                    $payload['featured_media'] = $mediaId;
                                    $featuredInfo = " · 🖼️ featured #{$mediaId} ({$imgDebug})";
                                    // Atualiza legenda, descrição e título (alt_text já foi setado no upload)
                                    $imgMeta = [];
                                    if (!empty($artigo['imagem']['legenda']))   $imgMeta['caption']     = $artigo['imagem']['legenda'];
                                    if (!empty($artigo['imagem']['descricao'])) $imgMeta['description'] = $artigo['imagem']['descricao'];
                                    if (!empty($artigo['imagem']['alt_text']))  $imgMeta['title']       = $artigo['imagem']['alt_text'];
                                    if (!empty($imgMeta)) {
                                        try { $wp->atualizarMedia($mediaId, $imgMeta); $featuredInfo .= ' · 📝 meta'; }
                                        catch (Throwable $e) { $featuredInfo .= ' · ⚠️ meta: ' . $e->getMessage(); }
                                    }
                                    try { $m = $wp->getMedia($mediaId); $featuredWpUrl = $m['source_url'] ?? ''; } catch (Throwable $e) {}
                                } else {
                                    $featuredInfo = " · ⚠️ upload imagem retornou null";
                                }
                            } catch (Throwable $e) {
                                $featuredInfo = ' · ⚠️ upload imagem: ' . $e->getMessage();
                            }
                        } else {
                            $featuredInfo = ' · ⚠️ sem og:image na fonte';
                        }

                        // NewsArticle removido — Rank Math gera via WP

                        // Autor padrão do site (anti-PBN: cada site declara autor distinto via persona)
                        $cfgUse = $modoCluster ? ($cfgItem ?? $cfg) : $cfg;
                        if (!empty($cfgUse['default_post_author_id'])) {
                            $payload['author'] = (int)$cfgUse['default_post_author_id'];
                        }
                        $resp = $wp->criarPost($payload);
                        $r['id']     = $resp['id'] ?? null;
                        // slug_real vem de $resp['slug'] (slug REAL salvo pelo WP) — evita ?p=ID de drafts
                        $slugReal      = (!empty($resp['slug'])) ? $resp['slug'] : $slugFinal;
                        $linkPlanejado = rtrim($cfgUse['wp_url'], '/') . '/' . $slugFinal . '/';
                        $r['edit']   = rtrim($cfgUse['wp_url'], '/') . "/wp-admin/post.php?post={$r['id']}&action=edit";
                        $r['link']   = rtrim($cfgUse['wp_url'], '/') . '/' . $slugReal . '/';
                        $r['titulo'] = $titulo;
                        $r['ok']     = true;
                        $r['msg']    = 'Post #' . $r['id'] . ' criado (' . str_word_count(strip_tags($html)) . ' palavras)' . $featuredInfo . $tituloLog . $conteudoLog;

                        // Guarda pra interligação de siblings no fim da rodada
                        if ($r['id'] && $r['link']) {
                            $postsDaRodada[] = ['id' => $r['id'], 'link' => $r['link'], 'link_planejado' => $linkPlanejado, 'titulo' => $titulo];
                        }

                        // 6. Indexação
                        if ($idxApi && $r['link']) {
                            try {
                                $ix = $idxApi->indexar($r['link'], 'URL_UPDATED');
                                $r['msg'] .= $ix['success'] ? ' · 📤 indexado (' . ($ix['method'] ?? '?') . ')' : ' · ⚠️ index falhou';
                            } catch (Throwable $e) { $r['msg'] .= ' · ⚠️ index: ' . $e->getMessage(); }
                        }

                        // 7. Posta no Facebook Page e/ou Instagram (se configurado)
                        if ($meta && $r['link']) {
                            $resumo = ($artigo['excerpt'] ?? $r['titulo']) ?: $r['titulo'];
                            // Hashtags: tags do Claude + keyword + top termos do HTML (com e sem acento)
                            $hashtags = gerarHashtagsCompletas($artigo, (string)$keyword, (string)($artigo['content_html'] ?? ''), 20);
                            $captionIg = trim($resumo . ($hashtags ? "\n\n" . $hashtags : '') . "\n\nLink na bio: " . $r['link']);
                            $captionFb = $resumo;

                            if ($postFB) {
                                // FB Photo: upload direto da imagem (não depende de og:image do draft)
                                $fbImg = '';
                                if ($heroUrl && preg_match('#^https://#', $heroUrl) && !preg_match('#\.webp(\?|$)#i', $heroUrl)) {
                                    $fbImg = $heroUrl;
                                } elseif ($featuredWpUrl && preg_match('#^https://#', $featuredWpUrl) && !preg_match('#\.webp(\?|$)#i', $featuredWpUrl)) {
                                    $fbImg = $featuredWpUrl;
                                }
                                try {
                                    if ($fbImg !== '') {
                                        $fb = $meta->postarFacebookFoto($fbImg, $captionFb, $r['link']);
                                        $r['msg'] .= $fb['success'] ? ' · 📘 FB foto #' . $fb['id'] : ' · ⚠️ FB: ' . ($fb['error'] ?? '?');
                                    } else {
                                        // Fallback: link post tradicional
                                        $fb = $meta->postarFacebookPage($r['link'], $captionFb);
                                        $r['msg'] .= $fb['success'] ? ' · 📘 FB link #' . $fb['id'] : ' · ⚠️ FB: ' . ($fb['error'] ?? '?');
                                    }
                                } catch (Throwable $e) { $r['msg'] .= ' · ⚠️ FB: ' . $e->getMessage(); }
                            }
                            if ($postIG) {
                                if ($igCarrossel) {
                                    // CARROSSEL: gera 6 slides (hero + 4 topics + CTA), upload JPG ao WP, publica
                                    try {
                                        $topicos = extrairSlidesDeHtml($artigo['content_html'] ?? '', 4);
                                        $slides = [];
                                        $slides[] = [
                                            'type'  => 'hero',
                                            'title' => $artigo['title'] ?? $keyword,
                                            'body'  => $artigo['excerpt'] ?? '',
                                        ];
                                        foreach ($topicos as $t) $slides[] = $t;
                                        $slides[] = [
                                            'type'  => 'cta',
                                            'title' => 'Leia o artigo completo',
                                            'body'  => 'Link na bio · ' . parse_url($r['link'], PHP_URL_HOST),
                                        ];

                                        if (count($slides) < 2) throw new RuntimeException('Conteúdo insuficiente para carrossel (precisa ≥2 slides)');

                                        $brand = [
                                            'name'    => $cfg['site_name'] ?? $cfg['_site_name'] ?? 'Site',
                                            'handle'  => '',
                                            'primary' => '#0ea5e9',
                                        ];
                                        $tmpDir = sys_get_temp_dir() . '/carrossel_' . uniqid();
                                        $gen = new CarrosselGenerator($tmpDir, $brand);
                                        $paths = $gen->gerar($slides);

                                        // Upload cada slide ao WP como JPG (sem WebP)
                                        $urls = [];
                                        foreach ($paths as $idx => $p) {
                                            $up = $wp->uploadImagemLocalJpg($p, "Slide " . ($idx + 1) . " — " . ($artigo['title'] ?? $keyword));
                                            if ($up && !empty($up['source_url'])) $urls[] = $up['source_url'];
                                        }

                                        // Limpa arquivos locais
                                        foreach ($paths as $p) @unlink($p);
                                        @rmdir($tmpDir);

                                        if (count($urls) < 2) throw new RuntimeException('Falha ao subir slides no WP');

                                        $ig = $meta->postarInstagramCarrossel($urls, $captionIg);
                                        $r['msg'] .= $ig['success'] ? ' · 🎠 IG carrossel #' . $ig['id'] . ' (' . count($urls) . ' slides)' : ' · ⚠️ IG: ' . ($ig['error'] ?? '?');
                                    } catch (Throwable $e) { $r['msg'] .= ' · ⚠️ IG carrossel: ' . $e->getMessage(); }
                                } else {
                                    // Imagem única (fluxo antigo)
                                    $igImg = '';
                                    if ($heroUrl && preg_match('#^https://#', $heroUrl) && !preg_match('#\.webp(\?|$)#i', $heroUrl)) {
                                        $igImg = $heroUrl;
                                    } elseif (!empty($payload['featured_media'])) {
                                        try { $m = $wp->getMedia((int)$payload['featured_media']); $maybe = $m['source_url'] ?? ''; if (!preg_match('#\.webp(\?|$)#i', $maybe)) $igImg = $maybe; } catch (Throwable $e) {}
                                    }
                                    if ($igImg === '') {
                                        $r['msg'] .= ' · ⚠️ IG: sem imagem compatível — use carrossel ou passe URL JPG';
                                    } else {
                                        try {
                                            $ig = $meta->postarInstagramFeed($igImg, $captionIg);
                                            $r['msg'] .= $ig['success'] ? ' · 📷 IG #' . $ig['id'] : ' · ⚠️ IG: ' . ($ig['error'] ?? '?');
                                        } catch (Throwable $e) { $r['msg'] .= ' · ⚠️ IG: ' . $e->getMessage(); }
                                    }
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        $r['msg'] = $e->getMessage();
                    }
                    $resultados[] = $r;
                    if (count($formatos) > 1) sleep(2);
                }
                // delay entre itens do batch
                if (count($itensParaProcessar) > 1 && $itemIdx < count($itensParaProcessar) - 1) sleep(3);
            } // fim foreach itensParaProcessar

            // CLUSTER: reconcilia backlinks — substitui link_planejado → link real (quando WP mudou o slug)
            if ($modoCluster && count($postsDaRodada) >= 1) {
                $substituicoes = [];
                foreach ($postsDaRodada as $p) {
                    $lp = rtrim((string)($p['link_planejado'] ?? ''), '/');
                    $lr = rtrim((string)($p['link'] ?? ''), '/');
                    if ($lp === '' || $lr === '' || $lp === $lr) continue;
                    // Cobre variantes com e sem barra final
                    $substituicoes[$lp . '/'] = $lr . '/';
                    $substituicoes[$lp]       = $lr;
                }
                if (!empty($substituicoes)) {
                    foreach ($postsDaRodada as $p) {
                        try {
                            $pd = $wp->getPost((int)$p['id']);
                            $html = $pd['content']['raw'] ?? $pd['content']['rendered'] ?? '';
                            if ($html === '') continue;
                            $novo = strtr($html, $substituicoes);
                            if ($novo !== $html) $wp->atualizarPost((int)$p['id'], ['content' => $novo]);
                        } catch (Throwable $e) {}
                    }
                }
            }

            // CLUSTER: limpa backlinks órfãos (itens que falharam na geração deixaram link quebrado nos irmãos)
            if ($modoCluster && count($itensParaProcessar) > 1 && count($postsDaRodada) >= 1) {
                // Links que foram PLANEJADOS como backlinks cruzados no loop
                $linksPlanejados = [];
                foreach ($itensParaProcessar as $it) {
                    $s = $sites[$it['site_slug'] ?? ''] ?? [];
                    $u = rtrim((string)($s['wp_url'] ?? ''), '/');
                    $kw = $it['keyword'] ?? '';
                    if ($u === '' || $kw === '') continue;
                    $slugP = slugifyPt($kw);
                    if (mb_strlen($slugP) > 60) $slugP = rtrim(mb_substr($slugP, 0, 60), '-');
                    $linksPlanejados[] = $u . '/' . $slugP . '/';
                }
                // Planejados DOS PUBLICADOS (o que cada post bem-sucedido tinha como link_planejado)
                // Comparar aqui — NÃO contra $p['link'] real — porque o link_planejado é o que foi INJETADO
                // no HTML dos irmãos. Se compararmos contra o real, divergências legítimas (colisão de slug)
                // fariam links válidos aparecerem como órfãos e seriam removidos indevidamente.
                $linksPublicadosPlanejados = [];
                foreach ($postsDaRodada as $p) {
                    $lp = rtrim((string)($p['link_planejado'] ?? ''), '/');
                    if ($lp !== '') $linksPublicadosPlanejados[] = $lp . '/';
                }
                // Órfãos = planejados dos itens que FALHARAM na geração
                $linksOrfaos = array_values(array_diff($linksPlanejados, $linksPublicadosPlanejados));

                if (!empty($linksOrfaos)) {
                    foreach ($postsDaRodada as $p) {
                        try {
                            $pd = $wp->getPost((int)$p['id']);
                            $html = $pd['content']['raw'] ?? $pd['content']['rendered'] ?? '';
                            if ($html === '') continue;
                            $novo = $html;
                            foreach ($linksOrfaos as $lo) {
                                // <a href="LINK_ORFAO" ...>texto</a> → texto (mantém texto, remove link quebrado)
                                $patt = '#<a\s+[^>]*href=(["\'])' . preg_quote($lo, '#') . '\1[^>]*>(.*?)</a>#is';
                                $novo = preg_replace($patt, '$2', $novo) ?? $novo;
                                // Variante sem barra final (defensivo)
                                $loSemBarra = rtrim($lo, '/');
                                $patt2 = '#<a\s+[^>]*href=(["\'])' . preg_quote($loSemBarra, '#') . '\1[^>]*>(.*?)</a>#is';
                                $novo = preg_replace($patt2, '$2', $novo) ?? $novo;
                            }
                            if ($novo !== $html) {
                                $wp->atualizarPost((int)$p['id'], ['content' => $novo]);
                            }
                        } catch (Throwable $e) {}
                    }
                }
            }

            // ── PILLAR UPDATE — Fase 4C (Topical Authority bidirecional) ──
            // Após cluster publicar, atualiza pillar adicionando "Veja também" com os cluster posts.
            // Fecha o ciclo: pillar → cluster (via Veja também) + cluster → pillar (via Fase A linking).
            // Idempotente: usa marker comments <!-- VTC --> pra detectar/substituir bloco existente em re-runs.
            if ($modoCluster && !empty($clusterPillar) && !empty($clusterPillar['id']) && count($postsDaRodada) >= 1) {
                $pillarId = (int)$clusterPillar['id'];
                // Filtra postsDaRodada pra excluir o próprio pillar (não auto-linkar)
                $postsCluster = array_values(array_filter($postsDaRodada, fn($p) => (int)($p['id'] ?? 0) !== $pillarId));
                if (count($postsCluster) >= 2) {
                    try {
                        // CRÍTICO: derivar cfg do SITE DONO DO PILLAR (não usar $cfg global nem $wp do loop).
                        // Match por host da URL pública do pillar contra todos os sites cadastrados.
                        $cfgPillarUpd = $cfg;
                        $pillarHost = strtolower(parse_url((string)$clusterPillar['link'], PHP_URL_HOST) ?: '');
                        $slugPillar = '';
                        if ($pillarHost !== '') {
                            foreach ($sites as $sSlug => $sCfg) {
                                $sHost = strtolower(parse_url((string)($sCfg['wp_url'] ?? ''), PHP_URL_HOST) ?: '');
                                if ($sHost !== '' && $sHost === $pillarHost) {
                                    aplicarSite($cfgPillarUpd, $sites, $sSlug);
                                    $slugPillar = $sSlug;
                                    break;
                                }
                            }
                        }
                        // Instância WP dedicada — NÃO reusa $wp do loop (que pode estar em outro site)
                        $wpPillarUpd = new Wordpress($cfgPillarUpd['wp_url'], $cfgPillarUpd['wp_user'], $cfgPillarUpd['wp_app_password']);
                        $pillarPost = $wpPillarUpd->getPost($pillarId);
                        $htmlPillar = (string)($pillarPost['content']['raw'] ?? $pillarPost['content']['rendered'] ?? '');
                        if ($htmlPillar !== '') {
                            // Constrói bloco "Veja também"
                            $itensHtml = [];
                            foreach (array_slice($postsCluster, 0, 12) as $pc) {
                                $tit = htmlspecialchars((string)($pc['titulo'] ?? ''), ENT_QUOTES);
                                $lnk = htmlspecialchars((string)($pc['link']    ?? ''), ENT_QUOTES);
                                if ($tit && $lnk) $itensHtml[] = "  <li><a href='{$lnk}'>{$tit}</a></li>";
                            }
                            $blocoNovo = "<!-- VTC -->\n<div class='veja-tambem-cluster' style='background:#f5f7fa;border-left:4px solid #6366f1;padding:18px 22px;margin:24px 0;border-radius:0 8px 8px 0'>\n"
                                       . "<h2 style='margin:0 0 10px;font-size:1.15rem;color:#1e293b'>Veja também — leituras complementares</h2>\n"
                                       . "<ul style='margin:0;padding-left:22px;line-height:1.7'>\n"
                                       . implode("\n", $itensHtml) . "\n"
                                       . "</ul>\n</div>\n<!-- /VTC -->";

                            // Substitui bloco existente (re-run) ou injeta antes do FAQ/schema/fim
                            if (preg_match('/<!--\s*VTC\s*-->.*?<!--\s*\/VTC\s*-->/s', $htmlPillar)) {
                                $htmlAtualizado = preg_replace('/<!--\s*VTC\s*-->.*?<!--\s*\/VTC\s*-->/s', $blocoNovo, $htmlPillar) ?? $htmlPillar;
                            } elseif (preg_match('/(<details|<script\s+type=[\'"]application\/ld\+json)/i', $htmlPillar, $m, PREG_OFFSET_CAPTURE)) {
                                $pos = (int)$m[0][1];
                                $htmlAtualizado = substr($htmlPillar, 0, $pos) . $blocoNovo . "\n\n" . substr($htmlPillar, $pos);
                            } else {
                                $htmlAtualizado = $htmlPillar . "\n\n" . $blocoNovo;
                            }

                            if ($htmlAtualizado !== $htmlPillar) {
                                $wpPillarUpd->atualizarPost($pillarId, ['content' => $htmlAtualizado]);
                                // Re-pinga Indexing API com URL_UPDATED no SITE CORRETO (não no global)
                                try {
                                    $idxPillarUpd = new InstantIndexing($cfgPillarUpd['wp_url'], $cfgPillarUpd['wp_user'], $cfgPillarUpd['wp_app_password']);
                                    $idxPillarUpd->indexar((string)$clusterPillar['link'], 'URL_UPDATED');
                                } catch (Throwable $eIU) {}
                                $resultados[] = [
                                    'formato' => 'pillar-update',
                                    'ok'      => true,
                                    'id'      => $pillarId,
                                    'edit'    => rtrim((string)$cfgPillarUpd['wp_url'], '/') . "/wp-admin/post.php?post={$pillarId}&action=edit",
                                    'link'    => (string)$clusterPillar['link'],
                                    'titulo'  => (string)$clusterPillar['title'],
                                    'msg'     => 'Pillar atualizado com Veja também: ' . count($itensHtml) . ' links cluster inseridos' . ($slugPillar !== '' ? " (site: {$slugPillar})" : ''),
                                ];
                            }
                        }
                    } catch (Throwable $eVT) {
                        error_log('[gerarpost] Pillar Veja Também update falhou: ' . $eVT->getMessage());
                    }
                }
            }

            // "Leia também" pós-loop removido — DebateBuilder (Discover/prompt.md) já insere a caixa
            // dentro do artigo, e formatos não-Discover usam a flag $leiaTbm + buscarRelacionados no loop.
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    }
}

$fmtInfo = Claude::$formatos;
$totalOk = count(array_filter($resultados, fn($r) => $r['ok']));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Gerar Post — artigo editorial</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:1000px;margin:0 auto}
h1{color:#fff;margin:0 0 4px}
.sub{color:#666;margin-bottom:20px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:22px;border-radius:10px;margin-bottom:16px}
.box h2{margin-top:0;font-size:18px}
label{display:block;font-weight:600;margin:10px 0 6px;font-size:13px;color:#bbb}
input[type=text],input[type=url]{width:100%;padding:13px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:15px}
textarea{width:100%;padding:12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#ddd;font-size:13px;font-family:inherit;min-height:100px;resize:vertical}
input:focus,textarea:focus{outline:none;border-color:#6366f1}
button{padding:16px 28px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:700;cursor:pointer;width:100%;margin-top:14px}
button:hover{opacity:.9}
.erro{background:#3b1818;border-left:4px solid #ef4444;padding:14px;border-radius:6px;margin-bottom:16px;color:#fca5a5}
.hint{font-size:11px;color:#444;margin-top:4px}
.formatos-bar{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
.fmt-check{display:flex;align-items:center;gap:6px;background:#0f1115;border:2px solid #2a2e38;border-radius:8px;padding:10px 16px;cursor:pointer}
.fmt-check input{accent-color:#6366f1}
.fmt-check span{font-size:13px;color:#ccc;font-weight:600}
.result{background:#111318;border:1px solid #2a2e38;border-radius:8px;padding:12px 16px;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;font-size:13px}
.result.ok{border-left:4px solid #22c55e}
.result.fail{border-left:4px solid #ef4444}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="container">
  <h1>📝 Gerar Post — artigo editorial</h1>
  <p class="sub">Artigo puro (texto), sem cards de produto nem tabela comparativa. Ideal para notícias, guias, análises e conteúdo informativo.</p>

  <?php if ($erro): ?><div class="erro"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

  <?php if ($processado && !empty($resultados)): ?>
    <div class="box">
      <h2>Resultado (<?= $totalOk ?>/<?= count($resultados) ?>) <?php if (isset($modoLog)): ?><small style="font-weight:normal;color:#666;font-size:12px">· modo: <?= htmlspecialchars($modoLog) ?> · <?= count($itensParaProcessar ?? []) ?> item(s) · <?= count($formatos ?? []) ?> formato(s)</small><?php endif; ?></h2>
      <?php foreach ($resultados as $r): ?>
        <div class="result <?= $r['ok'] ? 'ok' : 'fail' ?>">
          <div style="flex:1;min-width:0">
            <strong><?= strtoupper(htmlspecialchars($r['formato'])) ?></strong>
            <?php if ($r['titulo']): ?> — <?= htmlspecialchars($r['titulo']) ?><?php endif; ?>
            <div style="font-size:11px;color:#888;margin-top:2px"><?= htmlspecialchars($r['msg']) ?></div>
          </div>
          <?php if ($r['ok'] && $r['edit']): ?><a href="<?= htmlspecialchars($r['edit']) ?>" target="_blank">Editar →</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Seção: Google Trends (carregado via GET) -->
  <?php if (!empty($trends)): ?>
    <div class="box">
      <h2>🔥 Google Trends BR — clique para preencher o campo keyword abaixo</h2>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px;margin-top:10px">
        <?php foreach ($trends as $t): ?>
          <a href="#" onclick="preencherKeyword(<?= htmlspecialchars(json_encode($t['title']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($t['news_url'] ?? ''), ENT_QUOTES) ?>);return false" style="background:#0f1115;border:1px solid #2a2e38;border-radius:8px;padding:10px 12px;font-size:13px;color:#e0e0e0;text-decoration:none;display:block">
            <strong style="display:block;color:#fff"><?= htmlspecialchars($t['title']) ?></strong>
            <span style="font-size:11px;color:#666"><?= htmlspecialchars($t['traffic'] ?? '') ?> · <?= htmlspecialchars($t['news_source'] ?? '') ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <p class="hint"><a href="gerarpost.php">← Fechar trends</a></p>
    </div>
  <?php endif; ?>

  <!-- Seção: items do RSS (carregado via POST action=load_rss) -->
  <?php if (!empty($rssItems)):
    // Resolve todos os links via Serper (busca pelo título exato — caminho mais confiável)
    $serperResolver = null;
    try { $serperResolver = new Serper($cfg['serper_api_key']); } catch (Throwable $e) {}
    $gnResolver = new GoogleNewsRss($cfg['user_agent'] ?? '', 20, $serperResolver);
    $itemsResolvidos = [];
    foreach ($rssItems as $item) {
      $resolvido = null;
      // 1ª via: busca via Serper pelo título exato
      if ($serperResolver !== null) {
        $resolvido = $gnResolver->resolverViaTitulo($item['title'], $item['source'] ?? '');
      }
      // 2ª via: batchexecute / HTML parsing (fallback)
      if ($resolvido === null) {
        $resolvido = $gnResolver->resolverLink($item['link']);
      }
      // 3ª via: link original (última opção)
      if ($resolvido === null) $resolvido = $item['link'];
      $itemsResolvidos[] = array_merge($item, ['link_resolvido' => $resolvido]);
    }
  ?>
    <div class="box">
      <h2>📰 <?= count($itemsResolvidos) ?> items do RSS — marque os que quiser processar em lote</h2>
      <div class="check-ctrl" style="display:flex;gap:8px;margin:8px 0">
        <button type="button" onclick="rssToggleAll(true)" style="padding:6px 12px;font-size:12px;background:#1e2230;border:1px solid #2a2e38;border-radius:6px;color:#ccc;width:auto;margin:0">Marcar todos</button>
        <button type="button" onclick="rssToggleAll(false)" style="padding:6px 12px;font-size:12px;background:#1e2230;border:1px solid #2a2e38;border-radius:6px;color:#ccc;width:auto;margin:0">Desmarcar</button>
        <span id="rss-count" style="margin-left:auto;font-size:12px;color:#888;align-self:center">0 selecionados</span>
      </div>
      <div style="max-height:500px;overflow-y:auto;margin-top:10px">
        <?php foreach ($itemsResolvidos as $i => $item): ?>
          <label style="display:flex;gap:12px;align-items:flex-start;background:#0f1115;border:1px solid #2a2e38;border-radius:8px;padding:12px 14px;margin-bottom:6px;cursor:pointer">
            <input type="checkbox" class="rss-check" data-idx="<?= $i ?>" onchange="rssUpdateCount()" style="margin-top:4px;width:18px;height:18px;accent-color:#6366f1">
            <div style="flex:1;min-width:0">
              <div style="font-weight:700;color:#fff;font-size:14px;margin-bottom:4px"><?= htmlspecialchars($item['title']) ?></div>
              <div style="font-size:11px;color:#666;margin-bottom:6px"><?= htmlspecialchars($item['source']) ?> · <?= htmlspecialchars($item['pubDate']) ?></div>
              <div style="font-size:12px;color:#888;margin-bottom:6px"><?= htmlspecialchars(mb_substr($item['description'], 0, 200)) ?>…</div>
              <a href="<?= htmlspecialchars($item['link_resolvido']) ?>" target="_blank" style="font-size:11px;color:#a78bfa;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($item['link_resolvido']) ?> ↗</a>
            </div>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="hint">Marque os itens e role até o fim pra clicar em <strong>✍️ Gerar artigo(s)</strong>. O título Discover é gerado automaticamente pelo Sonnet junto com o artigo (com base no conteúdo completo).</p>
      <p class="hint"><a href="gerarpost.php">← Fechar lista</a></p>
    </div>
    <script>
      const rssData = <?= json_encode($itemsResolvidos, JSON_UNESCAPED_UNICODE) ?>;
      function rssToggleAll(v) { document.querySelectorAll('.rss-check').forEach(cb => cb.checked = v); rssUpdateCount(); }
      function rssUpdateCount() {
        const n = document.querySelectorAll('.rss-check:checked').length;
        document.getElementById('rss-count').textContent = n + ' selecionado' + (n===1?'':'s');
        // Mostra botão de títulos Discover se Discover marcado
        const btnT = document.getElementById('btn-titulos-rss');
        const discoverOn = document.querySelector('input[name="formatos[]"][value="discover"]')?.checked;
        if (btnT) btnT.style.display = (n > 0 && discoverOn) ? 'inline-block' : 'none';
      }

      // Gera títulos Discover pra cada item RSS marcado (sequencial AJAX)
      async function gerarTitulosRssBatch() {
        const checks = document.querySelectorAll('.rss-check:checked');
        if (checks.length === 0) { alert('Marque ao menos 1 item'); return; }
        const btn = document.getElementById('btn-titulos-rss');
        const statusEl = document.getElementById('rss-titulo-status');
        btn.disabled = true;
        let done = 0;
        for (const cb of checks) {
          const idx = parseInt(cb.dataset.idx);
          const item = rssData[idx];
          if (!item) continue;
          statusEl.textContent = `Gerando título ${++done}/${checks.length}: ${item.title.substring(0,40)}...`;
          try {
            const fd = new FormData();
            fd.append('action', 'gerar_titulo');
            fd.append('titulo_anterior', item.title);
            fd.append('url_scrape', item.link_resolvido || '');
            fd.append('keyword', item.title);
            const resp = await fetch('gerarpost.php', {method:'POST', body: fd});
            const data = await resp.json();
            if (data.ok && data.titulo) {
              rssData[idx].title = data.titulo; // atualiza no array JS
              // Atualiza visualmente na lista
              const titleDiv = cb.closest('label')?.querySelector('div div:first-child');
              if (titleDiv) { titleDiv.innerHTML = '<span style="color:#10b981">✅</span> ' + data.titulo; }
            }
          } catch(e) {}
        }
        statusEl.textContent = `✅ ${done} título(s) Discover gerado(s)`;
        btn.disabled = false;
      }
      function prepararBatchRss() {
        const sel = [];
        document.querySelectorAll('.rss-check:checked').forEach(cb => {
          const idx = parseInt(cb.dataset.idx);
          const it = rssData[idx];
          sel.push({title: it.title, description: it.description, link: it.link, link_resolvido: it.link_resolvido});
        });
        if (sel.length === 0) { alert('Marque pelo menos 1 item'); return false; }
        document.getElementById('selected_items_json').value = JSON.stringify(sel);
        document.getElementById('action_field').value = 'batch_rss';
        document.getElementById('mainForm').submit();
        return true;
      }
    </script>
  <?php endif; ?>

  <form method="POST" id="mainForm">
    <?php include __DIR__ . '/_site_select.php'; ?>
    <input type="hidden" name="rss_link_resolvido" id="rss_link_resolvido" value="">
    <input type="hidden" name="rss_title_item" id="rss_title_item" value="">
    <input type="hidden" name="selected_items_json" id="selected_items_json" value="">
    <input type="hidden" name="action" id="action_field" value="">

    <!-- Atalhos: Trends + RSS -->
    <div class="box" style="padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <a href="gerarpost.php?action=trends&cat=all" class="btn" style="padding:9px 16px;font-size:12px;background:linear-gradient(135deg,#f59e0b,#ef4444);text-decoration:none">🔥 Google Trends BR</a>
      <span style="flex:1;min-width:200px;display:flex;gap:6px;align-items:center">
        <input type="text" name="rss_url" value="<?= htmlspecialchars($_POST['rss_url'] ?? '') ?>" placeholder="https://news.google.com/rss/search?q=SEU+TERMO&hl=pt-BR&gl=BR" style="flex:1;padding:10px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:12px">
        <button type="submit" name="action" value="load_rss" formnovalidate style="padding:10px 14px;margin:0;font-size:12px;width:auto">📰 Ler RSS</button>
      </span>
    </div>

    <!-- CLUSTER SEO: adiciona pares site+keyword pra cross-linking entre sites -->
    <div class="box">
      <h2>🔗 Cluster SEO <small style="font-weight:normal;color:#555;font-size:12px">(opcional — gera artigos interligados entre sites)</small></h2>
      <p class="hint" style="margin-bottom:10px">Adicione pares site+keyword. Cada par gera um artigo no WP respectivo com backlinks naturais pros outros sites do cluster.</p>
      <div id="cluster-list"></div>
      <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;align-items:end">
        <select id="cluster-site-sel" style="padding:10px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:13px;flex:1;min-width:180px">
          <?php foreach ($sites as $slug => $s): ?>
            <option value="<?= htmlspecialchars((string)$slug) ?>"><?= htmlspecialchars($s['name'] ?? $slug) ?></option>
          <?php endforeach; ?>
        </select>
        <input id="cluster-kw-inp" type="text" placeholder="Keyword" style="flex:2;min-width:180px;padding:10px;font-size:13px">
        <input id="cluster-url-inp" type="text" placeholder="URL (opcional — sem URL faz Serper top 5)" style="flex:2;min-width:180px;padding:10px;font-size:13px">
        <select id="cluster-fmt-sel" style="padding:10px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:12px;min-width:100px">
          <?php foreach (Claude::$formatos as $key => $f): ?>
            <option value="<?= $key ?>" <?= $key === 'discover' ? 'selected' : '' ?>><?= $f['nome'] ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" onclick="addClusterItem()" style="width:auto;padding:10px 16px;margin:0;font-size:13px;background:#6366f1">+ Adicionar</button>
      </div>
      <input type="hidden" name="cluster_items_json" id="cluster_items_json" value="">
    </div>

    <div class="box">
      <h2>1. Palavra-chave e contexto</h2>
      <label>Palavra-chave / Título (opcional no batch RSS/Cluster — usa título de cada item)</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="text" name="keyword" id="keyword-input" placeholder="ex: salário mínimo 2026, cursos gratuitos senac..." value="<?= htmlspecialchars($_POST['keyword'] ?? '') ?>" style="flex:1">
        <!-- Título Discover é gerado automaticamente junto com o artigo -->
      </div>
      <p class="hint" id="titulo-status" style="display:none;color:#10b981"></p>

      <label style="margin-top:14px">Termos / contexto / instruções livres</label>
      <textarea name="termos" placeholder="Liste termos secundários, dados que devem aparecer, ângulo editorial, fontes a citar, tom específico, datas relevantes...&#10;&#10;Ex:&#10;- Valor R$ 1.518&#10;- Aumento de 6,8% vs 2025&#10;- Citar INPC como referência&#10;- Contexto PLR 2026&#10;- Tom explicativo, ajudar quem recebe salário mínimo a entender"><?= htmlspecialchars($_POST['termos'] ?? '') ?></textarea>
      <p class="hint">Qualquer coisa aqui vira contexto obrigatório no prompt do Claude — garante que dados específicos entrem no artigo.</p>

      <label style="margin-top:14px">URL para scraping (opcional)</label>
      <input type="url" name="url" placeholder="https://fonte-confiavel.com/materia-original" value="<?= htmlspecialchars($_POST['url'] ?? '') ?>">
      <p class="hint">Se fornecida, scrapeia título, parágrafos e headings para alimentar o Claude como referência factual.</p>

      <label style="margin-top:14px">URL da imagem (opcional — featured + Instagram)</label>
      <input type="url" name="image_url" placeholder="https://exemplo.com/imagem.jpg" value="<?= htmlspecialchars($_POST['image_url'] ?? '') ?>">
      <p class="hint">JPG/PNG em HTTPS. Se vazio, usa o <code>og:image</code> da fonte do scraping. <strong>Instagram exige JPG/PNG</strong> — não aceita WebP.</p>
    </div>

    <div class="box">
      <h2>2. Formato(s)</h2>
      <div class="formatos-bar">
        <?php foreach ($fmtInfo as $key => $f): ?>
          <label class="fmt-check">
            <input type="checkbox" name="formatos[]" value="<?= $key ?>" <?= in_array($key, $_POST['formatos'] ?? ['discover']) ? 'checked' : '' ?>>
            <span><?= $f['nome'] ?> <small style="color:#666">— <?= $f['estilo'] ?></small></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="hint">Cada formato marcado gera 1 post independente. O scraping roda uma vez só (economia).</p>

      <label style="margin-top:14px;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="auto_index" value="1" checked>
        <span style="font-weight:600;color:#ccc">📤 Solicitar indexação automática após publicar</span>
      </label>

<label style="margin-top:10px;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="gerar_imagem_ia" value="1" <?= !empty($_POST['gerar_imagem_ia']) ? 'checked' : '' ?>>
        <span style="font-weight:600;color:#ccc">🎨 Gerar imagem destacada via OpenAI (dall-e-3, 1792x1024 ≈ 16:9)</span>
      </label>
      <p class="hint">Quando ligado: ignora og:image da fonte e gera imagem editorial custom (~$0.12/artigo). Se a geração falhar, cai silenciosamente no og:image original.</p>

      <label style="margin-top:10px;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="queimar_overlay" value="1" <?= !empty($_POST['queimar_overlay']) ? 'checked' : '' ?>>
        <span style="font-weight:600;color:#ccc">🔥 Queimar frase chamativa SOBRE a imagem (estilo Netflix, no pixel)</span>
      </label>
      <p class="hint">Texto fica gravado na imagem (og:image também). Funciona em qualquer tema. <strong>Atenção:</strong> Discover não recomenda imagens com texto — usar em testes ou onde branding visual &gt; preview limpo.</p>

      <label style="margin-top:10px;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="gerar_webstory" value="1" <?= !empty($_POST['gerar_webstory']) ? 'checked' : '' ?>>
        <span style="font-weight:600;color:#ccc">📽️ Criar Web Story automaticamente (5-9 cenas, Pexels + CTA no plugin)</span>
      </label>
      <p class="hint">Após cada post publicar, chama endpoint REST <code>/wp-json/wp-wsai/v1/create-story</code> do plugin <strong>wp-web-stories-ai</strong>. GPT-4o-mini monta narrativa (Hook → Desenvolvimento → Ação Final), Pexels busca imagens. Silent failure.</p>

      <label style="margin-top:10px;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="post_fb" value="1">
        <span style="font-weight:600;color:#ccc">📘 Postar no Facebook Page (foto featured + link)</span>
      </label>

      <label style="margin-top:6px;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="ig_feed_unico" value="1">
        <span style="font-weight:600;color:#ccc">📷 Postar no Instagram Feed — imagem única (reusa featured)</span>
      </label>

      <label style="margin-top:6px;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="post_ig" value="1">
        <span style="font-weight:600;color:#ccc">🎠 Postar Carrossel no Instagram (tópicos do artigo)</span>
      </label>
      <div style="margin:4px 0 0 26px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="ig_carrossel" value="1" checked>
          <span style="color:#bbb;font-size:13px">Ativar geração de slides (6 slides: Hero + 4 tópicos + CTA)</span>
        </label>
        <div style="margin-top:6px;display:flex;gap:14px;align-items:center">
          <span style="font-size:12px;color:#888">Estilo:</span>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:#ccc">
            <input type="radio" name="carrossel_estilo" value="fotografico" <?= ($_POST['carrossel_estilo'] ?? 'fotografico') === 'fotografico' ? 'checked' : '' ?>>
            <span><strong>🖼️ Fotográfico</strong> (featured como fundo + bottom gradient + texto CAIXA ALTA)</span>
          </label>
        </div>
        <div style="margin-top:4px;display:flex;gap:14px;align-items:center">
          <span style="font-size:12px;color:#888">&nbsp;</span>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:#ccc">
            <input type="radio" name="carrossel_estilo" value="tipografico" <?= ($_POST['carrossel_estilo'] ?? '') === 'tipografico' ? 'checked' : '' ?>>
            <span>📰 Tipográfico (fundo liso + texto + número — estilo anterior)</span>
          </label>
        </div>
      </div>
      <p class="hint"><strong>Tags do artigo viram hashtags</strong> na legenda. Fotográfico economiza: reusa a mesma featured em todos os slides com overlay. Os 3 toggles são independentes (pode marcar só FB, só IG feed, ou combinar).</p>

      <label style="margin-top:10px;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="leia_tambem" value="1" <?= isset($_POST['leia_tambem']) || !$processado ? 'checked' : '' ?>>
        <span style="font-weight:600;color:#ccc">📚 Inserir bloco "Leia também" com posts relacionados</span>
      </label>
      <div style="display:flex;align-items:center;gap:10px;margin:4px 0 0 26px">
        <span style="font-size:11px;color:#666">Quantos posts relacionados:</span>
        <input type="number" name="leia_qtde" min="1" max="10" value="<?= htmlspecialchars((string)($_POST['leia_qtde'] ?? 6)) ?>" style="width:70px;padding:6px 8px;background:#0f1115;border:1px solid #2a2e38;border-radius:4px;color:#fff;font-size:12px">
      </div>
      <p class="hint">Busca no WP posts pela <code>focus_keyword</code> e injeta o bloco <code>Leia também</code> (estilo cc-card horizontal) antes da FAQ.</p>
    </div>

    <?php
      // O manifesto editorial (prompts/manifesto_editorial.md) já tem TODAS as regras —
      // blocos vazios para não duplicar/conflitar. Se o manifesto não existir, carrega
      // blocos Discover como fallback.
      $manifestoPath = __DIR__ . '/prompts/manifesto_editorial.md';
      $manifestoExists = file_exists($manifestoPath) && filesize($manifestoPath) > 500;

      if ($manifestoExists) {
        // Blocos vazios — manifesto editorial é a fonte única de verdade
        require __DIR__ . '/_blocos_data.php';
        $blocoDefaults = array_fill(1, 8, '');
        $blocoDefaults[1] = '(Manifesto editorial ativo — regras carregadas automaticamente de prompts/manifesto_editorial.md. Use este bloco só para instruções EXTRAS específicas deste artigo, se necessário.)';
      } else {
        $discoverDefaults = require __DIR__ . '/_blocos_discover.php';
        require __DIR__  . '/_blocos_data.php';
        $formatosAtuais = $_POST['formatos'] ?? ['discover'];
        $blocoDefaults = in_array('discover', $formatosAtuais) ? $discoverDefaults : $blocoDefaults;
      }
    ?>

    <?php include __DIR__ . '/_blocos_inputs.php'; ?>

    <script>
      // Alterna blocos + botão título automaticamente ao marcar/desmarcar Discover
      const discoverBlocks = <?= json_encode($discoverDefaults, JSON_UNESCAPED_UNICODE) ?>;
      const universalBlocks = <?= json_encode($universalDefaults, JSON_UNESCAPED_UNICODE) ?>;
      document.addEventListener('DOMContentLoaded', function() {
        const discoverCb = document.querySelector('input[name="formatos[]"][value="discover"]');
        const btnTitulo  = document.getElementById('btn-gerar-titulo');
        function syncDiscover() {
          if (!discoverCb) return;
          const on = discoverCb.checked;
          const blocks = on ? discoverBlocks : universalBlocks;
          for (let i = 1; i <= 8; i++) {
            const ta = document.querySelector('textarea[name="bloco' + i + '"]');
            if (ta) ta.value = blocks[i] || '';
          }
          if (btnTitulo) btnTitulo.style.display = on ? 'inline-block' : 'none';
        }
        if (discoverCb) {
          discoverCb.addEventListener('change', syncDiscover);
          syncDiscover(); // aplica no load
        }
      });

      // AJAX: gerar título Discover sem recarregar
      async function gerarTituloDiscover() {
        const kwInput   = document.getElementById('keyword-input');
        const urlInput  = document.querySelector('input[name="url"]');
        const statusEl  = document.getElementById('titulo-status');
        const btn       = document.getElementById('btn-gerar-titulo');
        const kw  = kwInput ? kwInput.value.trim() : '';
        const url = urlInput ? urlInput.value.trim() : '';
        if (kw === '' && url === '') { alert('Preencha keyword ou URL primeiro'); return; }
        btn.disabled = true; btn.textContent = '⏳ Gerando...';
        statusEl.style.display = 'block'; statusEl.textContent = 'Analisando conteúdo e gerando título Discover...';
        statusEl.style.color = '#f59e0b';
        try {
          const fd = new FormData();
          fd.append('action', 'gerar_titulo');
          fd.append('titulo_anterior', kw);
          fd.append('url_scrape', url);
          fd.append('keyword', kw);
          fd.append('site', document.querySelector('select[name="site"]')?.value || '');
          const resp = await fetch('gerarpost.php', {method:'POST', body: fd});
          const data = await resp.json();
          if (data.ok && data.titulo) {
            kwInput.value = data.titulo;
            statusEl.textContent = '✅ Título gerado: ' + data.titulo.length + ' chars';
            statusEl.style.color = '#10b981';
          } else {
            statusEl.textContent = '⚠️ ' + (data.erro || 'Falha');
            statusEl.style.color = '#ef4444';
          }
        } catch (e) {
          statusEl.textContent = '⚠️ Erro: ' + e.message;
          statusEl.style.color = '#ef4444';
        }
        btn.disabled = false; btn.textContent = '🎯 Gerar título Discover';
      }
    </script>

    <button type="submit" onclick="return antesDeSubmeter()">✍️ Gerar artigo(s)</button>
    <p class="hint" style="text-align:center;margin-top:8px">~30-60s por formato · Se houver itens do RSS marcados, gera 1 artigo por item (batch mode). Publica como <strong>draft</strong>.</p>
  </form>

  <script>
    // CLUSTER JS
    const clusterItems = [];
    function addClusterItem() {
      const siteSel = document.getElementById('cluster-site-sel');
      const kwInp   = document.getElementById('cluster-kw-inp');
      const urlInp  = document.getElementById('cluster-url-inp');
      const fmtSel  = document.getElementById('cluster-fmt-sel');
      const site = siteSel.value;
      const kw = kwInp.value.trim();
      if (!kw) { alert('Preencha a keyword'); return; }
      clusterItems.push({
        site, keyword: kw, url: urlInp.value.trim(),
        formato: fmtSel.value, formatoNome: fmtSel.options[fmtSel.selectedIndex].text
      });
      kwInp.value = ''; urlInp.value = '';
      renderCluster();
    }
    function removeClusterItem(idx) { clusterItems.splice(idx, 1); renderCluster(); }
    function renderCluster() {
      const list = document.getElementById('cluster-list');
      if (clusterItems.length === 0) { list.innerHTML = '<p style="color:#555;font-size:12px">Nenhum par adicionado. Use o modo single/RSS ou adicione pares acima.</p>'; return; }
      let h = '<table style="width:100%;font-size:13px;border-collapse:collapse">';
      h += '<tr style="color:#888;font-size:11px"><th style="text-align:left;padding:4px">Site</th><th style="text-align:left;padding:4px">Keyword</th><th style="text-align:left;padding:4px">Formato</th><th style="text-align:left;padding:4px">URL</th><th></th></tr>';
      clusterItems.forEach((c, i) => {
        h += '<tr style="border-bottom:1px solid #1e2230"><td style="padding:6px;color:#a78bfa;font-weight:700">' + c.site +
             '</td><td style="padding:6px;color:#fff">' + c.keyword +
             '</td><td style="padding:6px;color:#10b981;font-size:11px;font-weight:700">' + c.formatoNome.toUpperCase() +
             '</td><td style="padding:6px;color:#666;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + (c.url || 'Serper auto') +
             '</td><td style="padding:6px"><a href="#" onclick="removeClusterItem(' + i + ');return false" style="color:#ef4444;font-size:11px">X</a></td></tr>';
      });
      h += '</table>';
      list.innerHTML = h;
    }
    renderCluster();

    // Intercepta o submit principal: se houver itens RSS marcados OU cluster, ativa modo correto
    function antesDeSubmeter() {
      // Modo CLUSTER (prioridade)
      if (clusterItems.length > 0) {
        document.getElementById('cluster_items_json').value = JSON.stringify(clusterItems);
        document.getElementById('action_field').value = 'cluster';
        return true;
      }
      // Modo BATCH RSS
      const checks = document.querySelectorAll('.rss-check:checked');
      if (checks.length > 0) {
        const sel = [];
        checks.forEach(cb => {
          const idx = parseInt(cb.dataset.idx);
          const it = typeof rssData !== 'undefined' ? rssData[idx] : null;
          if (it) sel.push({title: it.title, description: it.description, link: it.link, link_resolvido: it.link_resolvido});
        });
        document.getElementById('selected_items_json').value = JSON.stringify(sel);
        document.getElementById('action_field').value = 'batch_rss';
        return true;
      }
      // Modo SINGLE — valida keyword OU url
      const kw  = document.querySelector('input[name="keyword"]').value.trim();
      const url = document.querySelector('input[name="url"]').value.trim();
      if (kw === '' && url === '') {
        alert('Preencha keyword OU URL, marque itens do RSS, ou adicione pares no Cluster.');
        return false;
      }
      return true;
    }
  </script>

  <script>
    function preencherKeyword(titulo, newsUrl) {
      const kw = document.querySelector('input[name="keyword"]');
      const urlInput = document.querySelector('input[name="url"]');
      if (kw) kw.value = titulo;
      if (urlInput && newsUrl) urlInput.value = newsUrl;
      window.scrollTo({top: document.querySelector('form').offsetTop - 20, behavior: 'smooth'});
    }
    function usarRssItem(titulo, linkResolvido) {
      const kw = document.querySelector('input[name="keyword"]');
      const urlInput = document.querySelector('input[name="url"]');
      const hLink = document.getElementById('rss_link_resolvido');
      const hTitle = document.getElementById('rss_title_item');
      if (kw) kw.value = titulo;
      if (urlInput) urlInput.value = linkResolvido;
      if (hLink) hLink.value = linkResolvido;
      if (hTitle) hTitle.value = titulo;
      window.scrollTo({top: document.querySelector('form').offsetTop - 20, behavior: 'smooth'});
    }
  </script>

  <p style="text-align:center;color:#333;font-size:12px;margin-top:24px">
    <a href="landing.php">Landing</a> · <a href="maquina.php">Máquina</a> · <a href="massa.php">Em massa</a> · <a href="categorias.php">Categorias</a> · <a href="trending.php">Trending</a> · <a href="atualizar.php">Atualizar</a> · <a href="gerarimagem.php">Imagem</a> · <a href="indexar.php">Indexar</a>
  </p>
</div>
</body>
</html>
