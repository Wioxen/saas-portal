<?php
/**
 * PillarBuilder — gera POST PILLAR (guia abrangente, evergreen) sobre um tópico do cluster.
 *
 * Diferente do DebateBuilder (que gera notícia/atualização Discover), o pillar é:
 *   - EVERGREEN: tom atemporal, útil em qualquer mês do ano
 *   - ABRANGENTE: 2000-3500 palavras cobrindo todo o tópico
 *   - ESTRUTURADO: H1 + intro + ToC + 6-10 H2 + FAQ 8-12q + Schema Article+FAQPage
 *   - MOBILE-FIRST: parágrafos máximo 3 linhas (~50 palavras), zero blocos densos
 *   - LINKADO: 3-5 backlinks externos (fontes oficiais gov.br) + 4-8 internos (posts existentes)
 *   - SEM EMOJI: zero emojis em H1/H2/body/FAQ — texto puro autoritativo
 *
 * Função: ser o post central de autoridade do site sobre o tópico, referenciado por N
 * cluster posts. Após criação, o link do pillar é injetado em todos os cluster items
 * pelo DebateBuilder via {{PILLAR_SECTION}}.
 *
 * Source de dados: snippets dos cluster items (frescos) + posts internos via WP REST
 * (pra linkar contextualmente). Não faz scrape externo — mantém custo previsível.
 *
 * Custo: ~$0.30 por pillar (Sonnet 4.6 com cache, ~3000 palavras output).
 */
require_once __DIR__ . '/Claude.php';
require_once __DIR__ . '/Wordpress.php';

class PillarBuilder
{
    private Claude $claude;
    private ?Wordpress $wp;
    public array $log = [];

    public function __construct(Claude $claude, ?Wordpress $wp = null)
    {
        $this->claude = $claude;
        $this->wp = $wp;
    }

    /**
     * Gera pillar HTML.
     *
     * @param string $topico        Tópico umbrella detectado pelo PillarDetector
     * @param array  $clusterItems  Items do cluster com 'title', 'description', '_prefetched' opcional
     * @param array  $sitePersona   Persona do site (voz/audiencia/nicho) — calibra tom
     * @param string $siteName      Nome do site (pra autor/publisher no schema)
     * @return array {title, slug, content_html, meta_description, faq_count, _log}
     */
    public function gerar(string $topico, array $clusterItems, array $sitePersona = [], string $siteName = ''): array
    {
        // Coleta snippets dos cluster items (fonte de dados frescos pra calibrar pillar)
        $snippets = $this->coletarSnippets($clusterItems, 6);

        // Busca posts internos existentes pra linkagem contextual no body
        $internalLinks = $this->buscarInternalLinks($topico, 12);
        $internalLinksSection = $this->renderizarInternalLinks($internalLinks);

        // Sugestões de fontes oficiais por tipo de tópico (pra Sonnet escolher 3-5 relevantes)
        $fontesOficiais = $this->sugerirFontesOficiais($topico);

        // Persona section
        $personaSection = '';
        if (!empty($sitePersona)) {
            $personaSection = "\nPERSONA DO SITE:\n";
            if (!empty($sitePersona['voz']))       $personaSection .= "- Voz: {$sitePersona['voz']}\n";
            if (!empty($sitePersona['audiencia'])) $personaSection .= "- Audiência: {$sitePersona['audiencia']}\n";
            if (!empty($sitePersona['nicho']))     $personaSection .= "- Nicho: {$sitePersona['nicho']}\n";
            if (!empty($sitePersona['tom']))       $personaSection .= "- Tom: {$sitePersona['tom']}\n";
        }

        $anoAtual = (int)date('Y');
        $mesAtual = $this->mesAtualPt();

        $system = <<<SYS
Você é editor sênior gerando um POST PILLAR (guia abrangente, evergreen) em HTML sem emojis.

DIFERENÇA vs notícia:
- Pillar é EVERGREEN: útil em janeiro como em dezembro
- Pillar é ABRANGENTE: cobre todo o tópico, não 1 ângulo
- Pillar é AUTORITATIVO: tom de manual oficial, não viral
- Pillar é ESTRUTURADO: H1 + intro + ToC + 6-10 H2 + FAQ + Schema
- Pillar é MOBILE-FIRST: parágrafos curtos, zero blocos densos
- Pillar tem AUTORIDADE: cita fontes oficiais (links externos) e internas (links internos contextuais)

OBJETIVO SEO: ser o post central de autoridade do site sobre o tópico, referenciado por N cluster posts.

ZERO clickbait, ZERO urgência sazonal no título/H1 (sem "ÚLTIMO DIA", "AGORA" etc).
Datas específicas só em seções dedicadas ("Calendário {$anoAtual}" ou "Atualizações de {$mesAtual}/{$anoAtual}").

Retorne APENAS JSON:
{
  "title": "Guia Completo de [tópico] [ano]" ou variante natural (50-70 chars),
  "slug": "guia-completo-[topico-slug]" (URL-friendly, lowercase, hifens, sem acentos, ≤60 chars),
  "meta_description": "Descrição evergreen 140-160 chars",
  "content_html": "HTML completo do pillar",
  "faq_count": número de perguntas no FAQ
}
SYS;

        $estruturaH2 = $this->montarEstruturaSugerida($topico);

        $prompt = <<<PROMPT
TÓPICO: {$topico}
ANO ATUAL: {$anoAtual}
MÊS ATUAL: {$mesAtual} de {$anoAtual}
{$personaSection}
SNIPPETS DE FONTES (use como dados — números, regras, datas — mas escreva em tom evergreen):

{$snippets}

INTERNAL LINKS DISPONÍVEIS (posts/categorias do MESMO site — use 4-8 contextualmente no corpo do artigo):

{$internalLinksSection}

FONTES OFICIAIS SUGERIDAS (use 3-5 como links externos pra dar autoridade — escolha as relevantes ao tópico):

{$fontesOficiais}

ESTRUTURA OBRIGATÓRIA do content_html:

1. **Intro** (2 parágrafos curtos, máximo 3 linhas cada):
   - O que é {$topico} (definição clara)
   - Por que importa (impacto/relevância — sem urgência sazonal)

2. **O que você vai aprender** (lista linkando às H2 abaixo, anchor links #-secao):
   - Bullets <ul> com as 6-10 seções abaixo
   - SEM emoji no título da seção

3. **6-10 seções H2** cobrindo (escolha as relevantes ao tópico):
{$estruturaH2}

4. **Perguntas Frequentes** (8-12 perguntas reais que pessoas pesquisam):
   - Use <details><summary>Pergunta?</summary><div>Resposta</div></details>
   - Perguntas curtas (≤60 chars), respostas diretas (1-3 frases curtas)
   - SEM emoji no título da seção

5. **Conclusão** (1 parágrafo curto, evergreen, sem CTA explícito):
   - Reforça que o site é fonte de referência sobre {$topico}

6. **Schema JSON-LD** no final do HTML (script type='application/ld+json'):
   - @type: Article + FAQPage combinados (use @graph)
   - headline, description, datePublished, dateModified, author={$siteName}
   - mainEntity = array de Question/Answer extraído do FAQ acima

REGRAS DE QUALIDADE (CRÍTICAS — violar = artigo reprovado):

A. **MOBILE FIRST — PARÁGRAFOS CURTOS:**
   - Cada <p> NO MÁXIMO 3 linhas em mobile (~40-50 palavras)
   - Parágrafo longo = QUEBRAR em vários <p> curtos
   - Idealmente 2-3 frases por parágrafo
   - Densidade alta = leitor abandona, Google rebaixa

B. **BACKLINKS EXTERNOS — AUTORIDADE OFICIAL (3-5 mínimo):**
   - Inserir 3-5 links <a href='https://gov.br/...' rel='noopener nofollow' target='_blank'>...</a> pra fontes OFICIAIS
   - Use a lista de fontes sugeridas acima — escolha as relevantes
   - Posicione no contexto onde a regra/dado é citado (não num bloco "fontes" no fim)
   - Anchor text natural ("conforme o Ministério da Educação", "segundo o portal oficial do INSS")
   - ZERO links pra blogs concorrentes ou Wikipedia

C. **BACKLINKS INTERNOS — CONTEXTUAIS NO CORPO (4-8 mínimo):**
   - Inserir 4-8 links <a href='/url-interna/'>...</a> pra posts/categorias da lista de internal links acima
   - Espalhar pelo corpo do artigo, não concentrar em uma seção só
   - Usar quando faz sentido contextual (ex: "veja também os critérios completos no nosso guia de [link]")
   - Anchor text descritivo (não "clique aqui", não "saiba mais")
   - Mínimo 1 link interno em cada terço do artigo (início/meio/fim)

D. **ZERO EMOJI — TEXTO PURO AUTORITATIVO:**
   - NENHUM emoji em qualquer lugar: nem em H1, nem em H2, nem no body, nem em FAQ, nem em ToC
   - Pillar é manual oficial — emoji desautoriza
   - Use TEXTO ao invés de simbolo: "Perguntas Frequentes" não "❓ Perguntas Frequentes"
   - Use TEXTO ao invés de simbolo: "O que você vai aprender" não "📚 O que você vai aprender"

E. **OUTRAS REGRAS DE QUALIDADE:**
   - 2000-3500 palavras (conta strip_tags do content_html)
   - Tabelas <table> em seções de calendário/valores/comparativo
   - Listas <ul>/<ol> em passos/critérios/documentos
   - <strong> em dados críticos (números, prazos, valores)
   - Aspas SIMPLES em todos os atributos HTML
   - Acentuação portuguesa completa
   - Linguagem condicional em temas legais ("conforme a lei", "segundo o critério")
   - NUNCA promete resultado/cura/aprovação garantida
   - Cite "fonte: [órgão oficial]" quando apresentar números/regras críticos

ANTI-IA — NÃO USE:
- "vale destacar", "é importante", "diante disso", "em suma", "nesse contexto", "cabe ressaltar", "por fim", "sendo assim"

ESTRUTURA DE LINKS — CHECKLIST FINAL ANTES DE FECHAR JSON:
- Contou 3-5 links externos pra .gov.br ou similar oficial? Sim/Não
- Contou 4-8 links internos pra URLs da lista fornecida? Sim/Não
- Cada terço do artigo tem ao menos 1 link interno? Sim/Não
- Verificou que NÃO tem emoji em lugar nenhum? Sim/Não
- Verificou que cada parágrafo tem ≤3 linhas mobile (~50 palavras)? Sim/Não

Se algum NÃO, refaça antes de retornar.
PROMPT;

        $resp = $this->claude->callPublic(
            [['role' => 'user', 'content' => $prompt]],
            $system,
            32000
        );

        $texto = trim((string)($resp['content'][0]['text'] ?? ''));
        $texto = preg_replace('/^```(?:json)?\s*/m', '', $texto) ?? $texto;
        $texto = preg_replace('/\s*```\s*$/m', '', $texto) ?? $texto;
        $texto = trim($texto);

        // Tenta extrair primeiro objeto JSON
        $json = null;
        if (preg_match('/\{[\s\S]*\}/s', $texto, $m)) {
            $json = json_decode($m[0], true);
        }
        if (!is_array($json) || empty($json['content_html'])) {
            throw new RuntimeException('PillarBuilder: JSON inválido ou content_html vazio');
        }

        // Sanitiza outputs
        $title = trim((string)($json['title'] ?? "Guia Completo de {$topico} {$anoAtual}"));
        $slug  = trim((string)($json['slug']  ?? ''));
        if ($slug === '') $slug = $this->slugify("guia-completo-{$topico}");
        $slug = mb_substr($slug, 0, 60);
        $meta  = trim((string)($json['meta_description'] ?? ''));
        if (mb_strlen($meta) > 160) $meta = mb_substr($meta, 0, 157) . '...';

        // Safety net pós-Sonnet: remove emojis residuais (Sonnet ocasionalmente teima)
        $contentHtml = $this->stripEmojis((string)$json['content_html']);

        $this->log[] = "pillar: gerado título='{$title}', slug='{$slug}'";

        return [
            'title'            => $this->stripEmojis($title),
            'slug'             => $slug,
            'content_html'     => $contentHtml,
            'meta_description' => $this->stripEmojis($meta),
            'faq_count'        => (int)($json['faq_count'] ?? 0),
            '_log'             => $this->log,
        ];
    }

    /**
     * Busca posts existentes do site pra usar como internal links contextuais no pillar.
     * Retorna até $limit candidatos (id/title/link).
     */
    private function buscarInternalLinks(string $topico, int $limit = 12): array
    {
        if ($this->wp === null) return [];
        try {
            // Busca por posts relacionados ao tópico (relevance + recentes)
            $posts = $this->wp->buscarRelacionados($topico, $limit);
            return is_array($posts) ? $posts : [];
        } catch (Throwable $e) {
            $this->log[] = 'internal links: busca WP falhou (' . $e->getMessage() . ')';
            return [];
        }
    }

    private function renderizarInternalLinks(array $links): string
    {
        if (empty($links)) {
            return "(nenhum post interno encontrado — gere o pillar sem links internos contextuais; use só os externos oficiais)";
        }
        $linhas = [];
        foreach ($links as $i => $l) {
            $tit = trim((string)($l['title'] ?? ''));
            $url = trim((string)($l['link']  ?? ''));
            if ($tit === '' || $url === '') continue;
            $linhas[] = "[" . ($i + 1) . "] \"{$tit}\" → {$url}";
        }
        return implode("\n", $linhas);
    }

    /**
     * Sugere fontes oficiais relevantes ao tópico (escolha de Sonnet entre as listadas).
     */
    private function sugerirFontesOficiais(string $topico): string
    {
        $tLow = mb_strtolower($topico);
        $padrao = [
            '- gov.br/mec/pt-br (Ministério da Educação)',
            '- gov.br/inss/pt-br (INSS)',
            '- gov.br/cidadania (Ministério do Desenvolvimento Social)',
            '- gov.br/secretariageralpresidencia',
            '- caixa.gov.br (Caixa Econômica Federal)',
            '- gov.br/cgu (Controladoria-Geral da União)',
            '- in.gov.br (Diário Oficial da União)',
            '- gov.br/falabr (Ouvidoria Geral)',
        ];
        // Adiciona fontes específicas se tópico bate
        if (strpos($tLow, 'bolsa') !== false || strpos($tLow, 'familia') !== false || strpos($tLow, 'auxílio') !== false) {
            $padrao[] = '- gov.br/cidadania/pt-br/acoes-e-programas/bolsa-familia';
        }
        if (strpos($tLow, 'pis') !== false || strpos($tLow, 'pasep') !== false || strpos($tLow, 'fgts') !== false) {
            $padrao[] = '- caixa.gov.br/beneficios-trabalhador';
        }
        if (strpos($tLow, 'pé-de-meia') !== false || strpos($tLow, 'pe-de-meia') !== false || strpos($tLow, 'estudante') !== false) {
            $padrao[] = '- gov.br/mec/pt-br/pe-de-meia';
            $padrao[] = '- estudante.pedemeia.mec.gov.br';
        }
        if (strpos($tLow, 'concurso') !== false || strpos($tLow, 'edital') !== false) {
            $padrao[] = '- gov.br/servidor';
        }
        if (strpos($tLow, 'enem') !== false) {
            $padrao[] = '- enem.inep.gov.br';
        }
        if (strpos($tLow, 'inss') !== false || strpos($tLow, 'aposentad') !== false || strpos($tLow, 'previdência') !== false) {
            $padrao[] = '- meu.inss.gov.br';
        }
        return implode("\n", $padrao);
    }

    private function coletarSnippets(array $items, int $max = 6): string
    {
        $linhas = [];
        $count = 0;
        foreach ($items as $it) {
            if ($count >= $max) break;
            $titulo = trim((string)($it['title'] ?? ''));
            if ($titulo === '') continue;
            $count++;
            $linhas[] = "## Fonte {$count}: {$titulo}";

            // Pega corpo do scrape se existir
            if (!empty($it['_prefetched']['content']['paragraphs'])) {
                $paras = array_slice($it['_prefetched']['content']['paragraphs'], 0, 5);
                $linhas[] = implode("\n", $paras);
            } elseif (!empty($it['description'])) {
                $linhas[] = mb_substr((string)$it['description'], 0, 600);
            }
            $linhas[] = '';
        }
        return implode("\n", $linhas);
    }

    private function montarEstruturaSugerida(string $topico): string
    {
        // Sugestões temáticas — Sonnet escolhe as relevantes ao tópico
        return <<<EST
   - "O que é {$topico}" — definição completa, base legal/origem
   - "Quem tem direito" — critérios, requisitos, faixa elegível
   - "Como acessar" — passo a passo (use <ol>)
   - "Calendário e datas" — quando, frequência, prazos (use <table>)
   - "Valores ou benefícios" — quanto, faixas, cálculos (se aplicável, use <table>)
   - "Documentos necessários" — checklist completo (use <ul>)
   - "Como consultar" — canais oficiais, app, portal, telefone 0800
   - "Erros comuns" — o que dá problema + como evitar
   - "Atualizações deste ano" — mudanças relevantes em {$topico}
EST;
    }

    private function mesAtualPt(): string
    {
        $meses = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
                  'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        return $meses[(int)date('n')] ?? 'janeiro';
    }

    private function slugify(string $s): string
    {
        $s = mb_strtolower(trim($s));
        // Remove acentos
        $unicode = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
                    'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
                    'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
        $s = strtr($s, $unicode);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        return trim($s, '-');
    }

    /**
     * Remove emojis (pictogramas, símbolos, ideogramas, dingbats).
     * Range Unicode coberto: U+1F300-1FAFF + U+2600-27BF + U+1F1E6-1F1FF + alguns BMP.
     */
    private function stripEmojis(string $s): string
    {
        // Regex que cobre os principais blocos Unicode de emoji
        $pattern = '/[\x{1F300}-\x{1F9FF}\x{1FA00}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F1E6}-\x{1F1FF}\x{2300}-\x{23FF}\x{2B00}-\x{2BFF}\x{2700}-\x{27BF}\x{FE00}-\x{FE0F}\x{1F000}-\x{1F02F}\x{1F0A0}-\x{1F0FF}]/u';
        $s = preg_replace($pattern, '', $s) ?? $s;
        // Limpa espaços duplicados que podem ter ficado
        $s = preg_replace('/  +/', ' ', $s) ?? $s;
        return $s;
    }
}
