<?php
declare(strict_types=1);

/**
 * HubBuilder — gera páginas-hub (cluster topical authority) em batch.
 *
 * Pipeline pra cada hub:
 *   1. Serper search (queries específicas)
 *   2. DiscoverFontes coleta + ordena por SourceTrustScore (Tier S/A prioritário)
 *   3. SportsFactExtractor extrai fatos literais
 *   4. Sonnet gera conteúdo 1500-2500 palavras com prompt customizado por TIPO
 *   5. Validators (anti-IA, fidelidade, tier) auditam
 *   6. Checklist final "isso é fidedigno aos olhos do Google?"
 *   7. Cria página WP como rascunho
 *
 * Config de hub (array):
 *   slug:           'barradao' (vira /barradao/)
 *   tipo:           'estadio' | 'jogador' | 'tecnico' | 'presidente' | 'titulo' | 'classico' | 'identidade' | 'historia' | 'elenco'
 *   titulo_h1:      'Barradão (Estádio Manoel Barradas)'
 *   meta_title:     'Barradão: o estádio do Vitória — Salvador, 30.793 lugares' (max 60 chars)
 *   meta_desc:      Descrição curta pra SERP
 *   query_serper:   'Estádio Manoel Barradas Barradão Vitória Salvador'
 *   urls_oficiais:  ['https://pt.wikipedia.org/wiki/Estádio_Manoel_Barradas', ...]  (forçar inclusão)
 *   schema_type:    'Place' | 'Person' | 'SportsTeam' | 'SportsEvent'
 *   palavras_alvo:  500-2500 (default 1500)
 *
 * Uso:
 *   $hub = HubBuilder::gerar($cfg, $hubConfig, $services);
 *   if ($hub['ok']) echo "Página criada: " . $hub['post_id'];
 */
class HubBuilder
{
    /** Tipos suportados — cada um tem prompt customizado. */
    public const TIPOS = ['estadio', 'jogador', 'tecnico', 'presidente', 'titulo', 'classico', 'identidade', 'historia', 'elenco', 'competicao', 'base'];

    /**
     * Gera 1 hub completo.
     *
     * @param array $cfg          Config do site (sites.php aplicada)
     * @param array $hubConfig    Config do hub específico
     * @param array $services     {scraper, serper, claude, wp}
     * @param bool  $dryRun       Se true, não chama API nem cria post
     * @return array {ok, post_id, url, custo_estimado_usd, warnings, log}
     */
    public static function gerar(array $cfg, array $hubConfig, array $services, bool $dryRun = false): array
    {
        $log = [];
        $warnings = [];
        $slug   = trim((string)($hubConfig['slug'] ?? ''));
        $tipo   = trim((string)($hubConfig['tipo'] ?? ''));
        $titulo = trim((string)($hubConfig['titulo_h1'] ?? ''));
        $query  = trim((string)($hubConfig['query_serper'] ?? $titulo));
        $urlsOficiais = (array)($hubConfig['urls_oficiais'] ?? []);
        $palavrasAlvo = (int)($hubConfig['palavras_alvo'] ?? 1500);

        if ($slug === '' || $tipo === '' || $titulo === '') {
            return ['ok' => false, 'erro' => 'hub_config inválida: slug/tipo/titulo_h1 obrigatórios', 'log' => $log];
        }
        if (!in_array($tipo, self::TIPOS, true)) {
            return ['ok' => false, 'erro' => "tipo desconhecido: {$tipo}", 'log' => $log];
        }

        $log[] = "[{$slug}] tipo={$tipo} titulo='{$titulo}' query='{$query}'";

        if ($dryRun) {
            return [
                'ok'                 => true,
                'dry_run'            => true,
                'log'                => $log,
                'custo_estimado_usd' => 0.15,
            ];
        }

        // 1. Coleta fontes via Serper + URLs oficiais forçadas
        require_once __DIR__ . '/DiscoverFontes.php';
        require_once __DIR__ . '/SourceTrustScore.php';

        // URLs candidatas: oficiais primeiro, depois Serper organic
        $urlsCandidatas = $urlsOficiais;
        try {
            $serpResp = $services['serper']->search($query, 10);
            foreach (($serpResp['organic'] ?? []) as $r) {
                $u = (string)($r['link'] ?? '');
                if ($u !== '' && !in_array($u, $urlsCandidatas, true)) $urlsCandidatas[] = $u;
            }
        } catch (Throwable $e) {
            $warnings[] = 'Serper falhou: ' . $e->getMessage();
        }

        // Ordena por tier
        $urlsCandidatas = array_map(fn($u) => ['url' => $u], $urlsCandidatas);
        $urlsCandidatas = SourceTrustScore::ordenarPorTier($urlsCandidatas);
        $urlsCandidatas = array_column($urlsCandidatas, 'url');

        $log[] = "[{$slug}] urls candidatas: " . count($urlsCandidatas);

        // 2. Scrape até 4 fontes
        $fontesOk = [];
        $totalChars = 0;
        $maxFontes = 4;
        foreach ($urlsCandidatas as $url) {
            if (count($fontesOk) >= $maxFontes) break;
            try {
                $dados = $services['scraper']->fetch($url);
                $paras = $dados['content']['paragraphs'] ?? [];
                $textoLen = strlen(implode(' ', $paras));
                if ($textoLen < 800) continue;
                $tier = SourceTrustScore::tierUrl($url);
                $fontesOk[] = ['url' => $url, 'fonte' => $dados, 'tier' => $tier, 'chars' => $textoLen];
                $totalChars += $textoLen;
                $log[] = "[{$slug}] ✓ scrape Tier {$tier}: {$url} ({$textoLen} chars)";
            } catch (Throwable $e) {
                $log[] = "[{$slug}] ✗ scrape falhou: {$url} — " . $e->getMessage();
            }
        }

        if (empty($fontesOk)) {
            return ['ok' => false, 'erro' => 'nenhuma fonte aproveitável', 'log' => $log, 'warnings' => $warnings];
        }

        // 3. Extrai fatos esportivos
        require_once __DIR__ . '/SportsFactExtractor.php';
        $fatos = SportsFactExtractor::extrair($fontesOk);

        // 4. Monta prompt customizado por tipo
        $prompt = self::montarPrompt($tipo, $hubConfig, $fontesOk, $fatos, $palavrasAlvo);

        // 5. Chama Claude Sonnet
        try {
            $respClaude = $services['claude']->callPublic(
                [['role' => 'user', 'content' => $prompt['user']]],
                $prompt['system'],
                12000
            );
            $contentText = $respClaude['content'][0]['text'] ?? '';
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'Claude falhou: ' . $e->getMessage(), 'log' => $log, 'warnings' => $warnings];
        }

        // Parse JSON da resposta
        require_once __DIR__ . '/Claude.php';
        $jsonResp = Claude::parseJsonResponse($contentText);
        if (!is_array($jsonResp) || empty($jsonResp['html'])) {
            // Salva raw pra debug
            $debugPath = __DIR__ . '/../data/debug/hub_fail_' . $slug . '_' . date('Ymd_His') . '.txt';
            @mkdir(dirname($debugPath), 0775, true);
            @file_put_contents($debugPath, $contentText);
            return ['ok' => false, 'erro' => 'Claude não retornou JSON válido (raw em ' . basename($debugPath) . ')', 'log' => $log, 'warnings' => $warnings];
        }

        $htmlConteudo = (string)$jsonResp['html'];
        $metaTitle    = (string)($jsonResp['meta_title']       ?? $titulo);
        $metaDesc     = (string)($jsonResp['meta_description'] ?? '');

        // 6. Validators — auditoria final ("isso é autoridade pro Google?")
        $auditoria = self::auditar($htmlConteudo, $fontesOk, $fatos, $cfg);
        if ($auditoria['severity'] === 'fail') {
            return [
                'ok'        => false,
                'erro'      => 'auditoria reprovou hub',
                'auditoria' => $auditoria,
                'log'       => $log,
                'warnings'  => $warnings,
                'html_rejeitado' => $htmlConteudo,
            ];
        }
        $log[] = "[{$slug}] auditoria: severity={$auditoria['severity']} (issues=" . count($auditoria['issues']) . ")";

        // 7. Aplica glossário de backlinks internos
        if (!empty($cfg['internal_link_glossary'])) {
            require_once __DIR__ . '/InternalLinkGlossary.php';
            $glossarioRet = InternalLinkGlossary::aplicar($htmlConteudo, [
                'wp_url'      => (string)($cfg['wp_url'] ?? ''),
                'current_url' => '/' . $slug . '/',  // Self-link guard
                'glossario'   => $cfg['internal_link_glossary'],
            ]);
            if (!empty($glossarioRet['html'])) {
                $htmlConteudo = $glossarioRet['html'];
            }
        }

        // 8. Schema.org markup
        $schema = self::montarSchema($hubConfig, $fatos, $cfg);
        if ($schema !== '') {
            $htmlConteudo .= "\n" . $schema;
        }

        // 9. Cria página WP como rascunho
        try {
            $pagePayload = [
                'title'   => $titulo,
                'content' => $htmlConteudo,
                'slug'    => $slug,
                'status'  => 'draft',
                'meta'    => [
                    'rank_math_title'       => $metaTitle,
                    'rank_math_description' => $metaDesc,
                    'rank_math_focus_keyword' => self::extrairFocusKeyword($titulo),
                ],
            ];
            $pagina = $services['wp']->criarPagina($pagePayload);
            $pageId = (int)($pagina['id'] ?? 0);
            $log[] = "[{$slug}] ✓ página criada WP id={$pageId}";

            return [
                'ok'        => true,
                'post_id'   => $pageId,
                'url'       => ($cfg['wp_url'] ?? '') . '/' . $slug . '/',
                'edit_url'  => ($cfg['wp_url'] ?? '') . '/wp-admin/post.php?post=' . $pageId . '&action=edit',
                'auditoria' => $auditoria,
                'log'       => $log,
                'warnings'  => $warnings,
                'tier_max'  => self::tierMaxFontes($fontesOk),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha criar página WP: ' . $e->getMessage(), 'log' => $log, 'warnings' => $warnings];
        }
    }

    /** Monta prompt sistema+user customizado por TIPO de hub. */
    private static function montarPrompt(string $tipo, array $hubConfig, array $fontesOk, array $fatos, int $palavrasAlvo): array
    {
        require_once __DIR__ . '/DiscoverPromptBuilder.php';
        $manifesto = DiscoverPromptBuilder::blocoManifesto();
        $blocoFatos = SportsFactExtractor::paraPrompt($fatos);

        // Monta texto consolidado das fontes (limitado por tier — Tier S/A primeiro)
        $textoFontes = '';
        foreach ($fontesOk as $i => $f) {
            $url = $f['url'] ?? '';
            $tier = $f['tier'] ?? 'D';
            $titulo = $f['fonte']['meta']['title'] ?? '';
            $paras = implode("\n", array_slice($f['fonte']['content']['paragraphs'] ?? [], 0, 30));
            $textoFontes .= "\n\n══ FONTE " . ($i+1) . " · Tier {$tier} · {$url} ══\n{$titulo}\n\n{$paras}\n";
        }

        $instrucoesPorTipo = self::instrucoesPorTipo($tipo, $hubConfig);

        $system = <<<SYS
{$manifesto}

═══ MISSÃO ═══
Você é redator-chefe gerando uma PÁGINA-HUB DE AUTORIDADE pra um site nicho.
Esta página vai receber backlinks internos de TODOS os posts do site sobre o
tópico — é a fonte canônica. Por isso precisa ser DEFINITIVA, FIDEDIGNA e
INFORMATIVA. Não é notícia do dia, é hub enciclopédico.

═══ TIPO DESTE HUB: {$tipo} ═══
{$instrucoesPorTipo}

═══ FATOS EXTRAÍDOS DAS FONTES ═══
{$blocoFatos}

═══ TAMANHO ALVO ═══
{$palavrasAlvo} palavras (variação aceitável: ±300).

═══ SAÍDA OBRIGATÓRIA — JSON COM ESTES CAMPOS ═══
{
  "html": "<conteúdo HTML completo, com h2/h3/p/ul/table/blockquote — começando direto sem h1 (WP renderiza h1 do título)>",
  "meta_title": "<título SEO 50-60 chars>",
  "meta_description": "<descrição SERP 140-160 chars>",
  "focus_keyword": "<keyword principal 2-4 palavras>"
}
SYS;

        $user = <<<USR
═══ FONTES SCRAPADAS — USE APENAS O CONTEÚDO DESTAS FONTES ═══
{$textoFontes}

═══ SOLICITAÇÃO ═══
Gere a PÁGINA-HUB completa em HTML semântico (h2, h3, p, ul, ol, table, blockquote)
respeitando todas as regras do manifesto editorial e os fatos extraídos.

REGRA INVIOLÁVEL: cada nome próprio, número, data, URL ou citação no conteúdo
DEVE estar nas fontes acima. Sem invenção. Sem inferência por treinamento.

Responda APENAS com o JSON solicitado.
USR;

        return ['system' => $system, 'user' => $user];
    }

    /** Instruções específicas por tipo de hub. */
    private static function instrucoesPorTipo(string $tipo, array $hubConfig): string
    {
        return match ($tipo) {
            'estadio' => "Página enciclopédica do estádio. Estrutura sugerida:\n- Apresentação (nome oficial + apelido + cidade + capacidade)\n- História da construção (ano, arquiteto, custo se houver)\n- Reformas e modernizações\n- Maiores públicos\n- Jogos memoráveis\n- Como chegar / setores\n- Curiosidades",
            'jogador' => "Bio do atleta. Estrutura sugerida:\n- Apresentação (nome completo, posição, idade, número da camisa)\n- Carreira anterior\n- Chegada ao Vitória\n- Estatísticas no clube\n- Momentos marcantes / gols importantes\n- Características técnicas\n- Vida pessoal (se a fonte traz)",
            'tecnico' => "Bio do treinador. Estrutura sugerida:\n- Apresentação (nome, idade, nacionalidade)\n- Carreira como jogador (se aplicável)\n- Carreira como técnico\n- Chegada ao Vitória\n- Métodos / estilo tático\n- Resultados no clube\n- Próximos desafios",
            'presidente' => "Bio do dirigente. Estrutura sugerida:\n- Apresentação\n- Trajetória profissional fora do clube\n- Chegada à diretoria\n- Mandatos e datas\n- Principais decisões\n- Eleições e resultado eleitoral",
            'titulo' => "Cobertura histórica da conquista. Estrutura sugerida:\n- Resumo da campanha\n- Caminho até o título (jogo a jogo principais)\n- Final ou jogo decisivo (gols, tempo, públicos)\n- Elenco campeão (lista jogadores)\n- Comissão técnica\n- Repercussão / festa\n- Importância histórica",
            'classico' => "História completa da rivalidade. Estrutura sugerida:\n- Apresentação dos clubes envolvidos\n- Origem da rivalidade\n- Estatísticas gerais (jogos, vitórias, empates)\n- Jogos marcantes da história\n- Maiores artilheiros do clássico\n- Curiosidades\n- Próximo confronto se aplicável",
            'identidade' => "História do elemento simbólico (escudo, uniforme, mascote, hino). Estrutura sugerida:\n- Origem\n- Evolução (desenhos, mudanças)\n- Significado simbólico\n- Designer / autor (se conhecido)\n- Curiosidades",
            'historia' => "Recorte histórico do clube. Estrutura sugerida:\n- Contexto da época\n- Eventos principais\n- Nomes envolvidos\n- Impacto no clube\n- Conexão com o presente",
            'elenco' => "Elenco atual completo. Estrutura sugerida:\n- Apresentação do grupo (técnico + número de atletas)\n- Por posição: goleiros, zagueiros, laterais, volantes, meias, atacantes\n- Para cada jogador: nome, idade, número, status (titular / reserva / lesionado)\n- Tabela resumo\n- Movimentações recentes (chegadas/saídas)",
            'competicao' => "Hub de competição. Estrutura sugerida:\n- Apresentação da competição\n- Histórico do Vitória nesta competição\n- Títulos conquistados\n- Próximos jogos\n- Tabela atual",
            'base' => "Categoria de base do clube. Estrutura sugerida:\n- Apresentação das categorias\n- Atletas formados na base que chegaram ao profissional\n- Títulos da base\n- Estrutura física e técnica",
            default => "Estrutura padrão: apresentação → contexto histórico → fatos relevantes → curiosidades.",
        };
    }

    /** Auditoria final: roda validators + checklist. */
    private static function auditar(string $html, array $fontesOk, array $fatos, array $cfg): array
    {
        $issues = [];
        $severity = 'ok';

        // 1. AntiAIValidator
        require_once __DIR__ . '/AntiAIValidator.php';
        $aiVal = new AntiAIValidator();
        $aiReport = $aiVal->validate($html);
        if (!empty($aiReport['violations'])) {
            foreach (array_slice($aiReport['violations'], 0, 5) as $v) {
                $issues[] = "anti-ai: {$v['phrase']} x{$v['count']}";
            }
        }

        // 2. SourceFidelityValidator (se tem fontes)
        require_once __DIR__ . '/SourceFidelityValidator.php';
        $textosFontes = [];
        foreach ($fontesOk as $f) {
            $textosFontes[] = implode("\n", $f['fonte']['content']['paragraphs'] ?? []);
        }
        $fidReport = SourceFidelityValidator::validar($html, $textosFontes, [
            'own_domain' => $cfg['wp_url'] ?? '',
        ]);
        if (!empty($fidReport['issues'])) {
            $criticas = 0;
            foreach ($fidReport['issues'] as $i) {
                if ($i['tipo'] === 'nome_alucinado') $criticas++;
                $issues[] = "fidelity: [{$i['tipo']}] {$i['valor']}";
            }
            if ($criticas >= 2) $severity = 'fail';
        }

        // 3. Checklist final ("isso é autoridade pro Google?")
        $checklist = self::checklistAutoridade($html);
        foreach ($checklist as $check) {
            if (!$check['ok']) {
                $issues[] = "checklist: {$check['descricao']}";
                if ($check['critico']) $severity = 'fail';
            }
        }

        return [
            'severity' => $severity,
            'issues'   => $issues,
            'ai'       => $aiReport,
            'fidelity' => $fidReport,
            'checklist'=> $checklist,
        ];
    }

    /** Checklist final estilo "isso é autoridade pro Google?" */
    private static function checklistAutoridade(string $html): array
    {
        $palavras = str_word_count(strip_tags($html));
        $h2Count  = preg_match_all('/<h2\b/i', $html);
        $h3Count  = preg_match_all('/<h3\b/i', $html);
        $tabelas  = preg_match_all('/<table\b/i', $html);
        $listas   = preg_match_all('/<(ul|ol)\b/i', $html);

        return [
            ['ok' => $palavras >= 800,  'critico' => true,  'descricao' => "tamanho mínimo 800 palavras (atual: {$palavras})"],
            ['ok' => $palavras <= 3500, 'critico' => false, 'descricao' => "tamanho máximo 3500 palavras (atual: {$palavras})"],
            ['ok' => $h2Count >= 3,     'critico' => true,  'descricao' => "mínimo 3 H2s pra estrutura clara (atual: {$h2Count})"],
            ['ok' => $h3Count >= 2,     'critico' => false, 'descricao' => "ideal 2+ H3s (atual: {$h3Count})"],
            ['ok' => $tabelas >= 1 || $listas >= 2, 'critico' => false, 'descricao' => "≥1 tabela OU ≥2 listas pra escaneabilidade"],
            ['ok' => !preg_match('/lorem ipsum/i', $html), 'critico' => true, 'descricao' => "sem lorem ipsum"],
            ['ok' => !preg_match('/\[INSIRA|\[PREENCHA|\[TODO/i', $html), 'critico' => true, 'descricao' => "sem placeholders [INSIRA]/[TODO]"],
        ];
    }

    /** Schema.org markup por tipo. */
    private static function montarSchema(array $hubConfig, array $fatos, array $cfg): string
    {
        $tipo = $hubConfig['tipo'] ?? '';
        $titulo = $hubConfig['titulo_h1'] ?? '';
        $descricao = $hubConfig['meta_desc'] ?? '';
        $url = ($cfg['wp_url'] ?? '') . '/' . ($hubConfig['slug'] ?? '') . '/';

        $tipoSchema = match ($tipo) {
            'estadio'    => 'StadiumOrArena',
            'jogador'    => 'Person',
            'tecnico'    => 'Person',
            'presidente' => 'Person',
            'classico'   => 'SportsEvent',
            'titulo'     => 'SportsEvent',
            'elenco'     => 'SportsTeam',
            'competicao' => 'SportsOrganization',
            default      => 'WebPage',
        };

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => $tipoSchema,
            'name'        => $titulo,
            'url'         => $url,
            'description' => $descricao,
        ];

        return "\n<script type=\"application/ld+json\">" . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>";
    }

    private static function extrairFocusKeyword(string $titulo): string
    {
        // Pega 2-4 primeiras palavras significativas
        $palavras = preg_split('/\s+/u', strip_tags($titulo)) ?: [];
        $palavras = array_filter($palavras, fn($w) => mb_strlen($w) >= 3);
        return implode(' ', array_slice($palavras, 0, 4));
    }

    private static function tierMaxFontes(array $fontesOk): string
    {
        $maxScore = 0;
        $tier = 'D';
        require_once __DIR__ . '/SourceTrustScore.php';
        foreach ($fontesOk as $f) {
            $s = SourceTrustScore::scoreUrl($f['url'] ?? '');
            if ($s > $maxScore) { $maxScore = $s; $tier = $f['tier'] ?? 'D'; }
        }
        return $tier;
    }
}
