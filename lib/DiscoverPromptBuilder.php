<?php
/**
 * DiscoverPromptBuilder — fonte única de verdade pros blocos de prompt compartilhados
 * entre Claude.php::gerarArtigo, DiscoverGeradorGPT::montarPrompt e DiscoverReviewer::chamarClaude.
 *
 * Antes: cada um desses 3 arquivos duplicava os mesmos blocos (regras temporais, regra de links,
 * blindagem anti-alucinação, proibição de metáforas em H2/H3, frases de IA proibidas). Edição em
 * um não refletia nos outros — drift garantido.
 *
 * Agora: cada bloco tem uma versão CANÔNICA aqui. Callers compõem via chamadas aos métodos.
 * Diferenças contextuais (gerar vs revisar) viram parâmetros, não duplicação.
 *
 * Os blocos mais LONGOS (ANTI-REDUNDÂNCIA, FRASES PROIBIDAS, etc.) vivem em
 * `prompts/manifesto_editorial.md` — manifesto editorial reusável, separado de CLAUDE.md
 * (que é só pra instruções do agente Claude Code). O manifesto é injetado via manifestoEditorial().
 */
class DiscoverPromptBuilder
{
    private static ?string $manifestoCache = null;

    /** Retorna data atual formatada BR + dia da semana. */
    public static function dataAtual(): array
    {
        $dias = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
        return [
            'hoje'       => date('d/m/Y'),
            'dia_semana' => $dias[(int)date('w')],
            'mes_nome'   => ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'][(int)date('n') - 1] ?? '',
            'ano'        => (int)date('Y'),
        ];
    }

    /**
     * Carrega o manifesto editorial uma vez por request, cacheado.
     * Retorna string vazia se o arquivo não existir.
     *
     * Histórico: até 2026-05-02 lia CLAUDE.md, mas esse arquivo virou ambíguo (instruções pro agente
     * Claude Code + manifesto editorial). Como CLAUDE.md tinha `## FORMATO DE ENTREGA` exigindo
     * Markdown, o pipeline JSON quebrava. Separado em `prompts/manifesto_editorial.md`.
     */
    public static function manifestoEditorial(): string
    {
        if (self::$manifestoCache !== null) return self::$manifestoCache;
        $path = dirname(__DIR__) . '/prompts/manifesto_editorial.md';
        self::$manifestoCache = is_file($path) ? (string)file_get_contents($path) : '';
        return self::$manifestoCache;
    }

    /**
     * Alias retrocompatível pra callers antigos. Deprecated — use manifestoEditorial().
     * @deprecated 2026-05-02
     */
    public static function manifestoClaudeMd(): string
    {
        return self::manifestoEditorial();
    }

    /**
     * Bloco "REGRAS TEMPORAIS ABSOLUTAS" — canonical 4 regras + 1 extra pra revisão.
     *
     * @param string $contexto 'gerar' (default) ou 'revisar'
     */
    public static function regrasTemporais(string $contexto = 'gerar'): string
    {
        $d = self::dataAtual();
        $hoje = $d['hoje']; $diaSemana = $d['dia_semana'];

        $out  = "═══ DATA DE HOJE (ÚNICA VERDADE TEMPORAL) ═══\n";
        $out .= "DATA DE HOJE: {$hoje} ({$diaSemana}), {$d['mes_nome']} de {$d['ano']}\n\n";
        $out .= "REGRAS TEMPORAIS ABSOLUTAS:\n";
        $out .= "1. ANTES de escrever 'hoje', 'nesta {$diaSemana}', 'mesma data de hoje', 'encerra hoje', 'acontece hoje' — confira se a data citada é LITERALMENTE {$hoje}. Se não for, use a data real (ex: '24 de abril') SEM dizer que é hoje.\n";
        $out .= "2. 'Amanhã' só se for {$hoje} + 1 dia. 'Ontem' só se for {$hoje} - 1 dia. Caso contrário, use a data exata.\n";
        $out .= "3. PROIBIDO escrever 'prazo encerra hoje', 'último dia é hoje', 'acontece hoje' se a data do evento não for EXATAMENTE {$hoje}.\n";
        $out .= "4. Para datas futuras, calcule diferença em dias contra {$hoje} antes de qualificar. Ex: prazo 24/04 + hoje 22/04 = 'em 2 dias' ou 'nesta sexta-feira', NÃO 'hoje'.\n";
        $out .= "5. Para datas futuras próximas (3-7 dias), prefira 'em [N] dias' ou 'na próxima [dia_semana]' em vez de 'em breve'.\n";
        $out .= "6. Se a fonte cita uma data (ex: '24 de abril'), COMPUTE a diferença em dias até hoje ({$hoje}) antes de qualificar com expressões relativas.\n";
        $out .= "7. Dia da semana de uma data SÓ se a fonte informar OU se conseguir calcular com certeza. Em dúvida, escreva só a data.\n";
        $out .= "8. TEMPO VERBAL DE DATAS PASSADAS: se a data citada na fonte é ANTERIOR a {$hoje}, use VERBO NO PASSADO — 'encerrou', 'expirou', 'venceu', 'terminou', 'fechou'. PROIBIDO 'encerra dia X', 'termina em X', 'acontece em X' se X já passou em relação a {$hoje}. Se a oportunidade JÁ PASSOU, mude o ângulo: foco vira CONSEQUÊNCIA pra quem perdeu o prazo, NÃO 'aproveite agora' (que seria mentira).\n";

        if ($contexto === 'revisar') {
            $out .= "9. Confira o conteúdo ORIGINAL: cada 'hoje' citado precisa bater com {$hoje}. Se o artigo original tinha 'hoje' incorreto, CORRIJA no output final.\n";
        }

        $out .= "═══ FIM REGRAS TEMPORAIS ═══\n";
        return $out;
    }

    /**
     * Bloco "REGRA ABSOLUTA DE LINKS" — proibição de URL interna inventada.
     *
     * @param string $wpUrl domínio base do WordPress (ex: https://site.com)
     * @param string $contexto 'gerar' (proibir criar) ou 'revisar' (proibir criar/manter)
     */
    public static function regraLinksInternos(string $wpUrl, string $contexto = 'gerar'): string
    {
        $dominio = rtrim($wpUrl, '/');
        $verbo = $contexto === 'revisar' ? 'criar/manter' : 'criar';

        $out  = "═══ REGRA ABSOLUTA DE LINKS (PROIBIÇÃO DE URL INVENTADA) ═══\n";
        $out .= "PROIBIDO {$verbo} <a href> apontando pro próprio site ({$dominio}). URLs internas são injetadas pelo sistema depois, com posts REAIS do WordPress.\n";
        if ($contexto === 'revisar') {
            $out .= "Se o artigo original tem <a href='/algum-slug'> apontando pro próprio domínio, REMOVA o <a> (preserve o texto). O sistema reinjeta depois.\n";
        }
        $out .= "Permitido: mencionar temas em texto puro; citar órgãos (Receita, INSS) em texto — sistema linka automaticamente; <a href='https://...'> apenas pra domínios externos factuais que estejam LITERALMENTE nas fontes.\n";
        $out .= "Proibido: <a href='/slug'>, <a href='{$dominio}/qualquer-coisa'>. Sistema detecta e remove URLs inventadas.\n";
        $out .= "═══ FIM REGRA DE LINKS ═══\n";
        return $out;
    }

    /**
     * Bloco "BLINDAGEM ANTI-ALUCINAÇÃO" — versão canônica expandida (baseada em DiscoverGerador,
     * que era a mais completa). Uso geral em gerar + revisar.
     */
    public static function blindagemAntiAlucinacao(): string
    {
        return "═══ BLINDAGEM ANTI-ALUCINAÇÃO ═══\n"
             . "PROIBIÇÕES ABSOLUTAS (violar = conteúdo inaceitável):\n"
             . "1) NÃO cite NOMES DE PESSOAS (técnicos, políticos, celebridades, autoridades) que NÃO apareçam LITERALMENTE nas fontes.\n"
             . "2) NÃO liste escalações, times, elencos, membros, concorrentes, participantes sem lista COMPLETA e explícita na fonte.\n"
             . "3) NÃO traga DATAS, VALORES, ESTATÍSTICAS, PERCENTUAIS que não estejam explícitos nas fontes.\n"
             . "4) NÃO afirme RESULTADOS de eventos (placar, vencedor) se a fonte for pré-evento.\n"
             . "5) Em tema esportivo/eventos: se a fonte é sobre pré-jogo, o artigo é sobre pré-jogo. NÃO especule resultado.\n"
             . "6) Se a fonte é escassa/curta sobre determinado ponto, você OMITE esse ponto — não completa com conhecimento geral.\n"
             . "REGRA DE OURO: em dúvida sobre um fato específico, OMITA. Omissão OK, invenção NÃO.\n"
             . "Cite a fonte pelo nome do veículo quando citar dado institucional.\n"
             . "═══ FIM BLINDAGEM ═══\n";
    }

    /**
     * Schema JSON de saída para GERAÇÃO de artigo novo.
     */
    public static function schemaGerar(string $keyword, string $schemaType = 'Article'): string
    {
        return "SAÍDA OBRIGATÓRIA: JSON puro (sem code fences), schema:\n"
             . '{"title":"...", "slug":"...", "excerpt":"máx 160 chars", "meta_title":"...", "meta_description":"150-160 chars", "focus_keyword":"' . $keyword . '", "secondary_keywords":["5-8"], "content_html":"HTML COMPLETO em UMA linha, aspas simples nos atributos, min 1000 palavras", "tags":["5"], "categories":["1"], "hero_alt":"alt", "imagem":{"alt_text":"...", "legenda":"...", "descricao":"..."}, "schema_type":"' . $schemaType . '"}';
    }

    /**
     * Schema JSON de saída para REVISÃO de artigo publicado (inclui alternativas).
     */
    public static function schemaRevisar(): string
    {
        return "═══ SAÍDA OBRIGATÓRIA ═══\n"
             . "Responda APENAS com JSON válido (sem markdown code fences):\n"
             . "{\n"
             . "  \"titulo_final\": \"título principal otimizado (CTR alto, 55-70 chars, com número/data/público-alvo quando possível)\",\n"
             . "  \"content_html\": \"HTML completo revisado em UMA linha, com TODOS os blocos do sistema preservados verbatim\",\n"
             . "  \"meta_description\": \"até 160 chars, focada em CTR\",\n"
             . "  \"slug\": \"slug-curto-otimizado\",\n"
             . "  \"titulos_alternativos\": [\"curiosidade\", \"urgencia\", \"lista\", \"beneficio\", \"misto\"],\n"
             . "  \"aberturas_alternativas\": [\"lead 1 pro Discover\", \"lead 2\", \"lead 3\"],\n"
             . "  \"frases_impacto\": [\"frase forte 1\", \"frase forte 2\", \"frase 3\", \"frase 4\", \"frase 5\"]\n"
             . "}";
    }

    /**
     * Bloco "HUMANO-ESPECIALISTA" — diretivas E-E-A-T pra prompt do gerador.
     *
     * Aplicado em DiscoverGerador, DiscoverGeradorGPT e DiscoverReviewer (3 callers).
     * Reforça sinais que Google premia em Helpful Content Update + ranking E-E-A-T:
     *  1) Voz de Autoridade contextual
     *  2) "Pulo do Gato" técnico obrigatório (utilidade não-óbvia)
     *  3) Estrutura com perguntas que o leitor faz
     *  4) Transparência da fonte (data/origem)
     *
     * NÃO conflita com persona específica do site (que vem em outro bloco) — esse aqui é
     * universal pro tom "humano-especialista" independente do nicho.
     */
    public static function blocoHumanoEspecialista(): string
    {
        $d = self::dataAtual();
        return "═══ TOM HUMANO-ESPECIALISTA (E-E-A-T OBRIGATÓRIO) ═══\n"
             . "Você não é um agregador de notícias — você é um EDITOR-ESPECIALISTA com mãos sujas no tema. "
             . "O Google distingue conteúdo 'genérico de IA' de conteúdo 'humano com expertise real'. Aplique:\n\n"
             . "1) VOZ DE AUTORIDADE CONTEXTUAL (use 1-2x ao longo do artigo, não no lead):\n"
             . "   • 'Ao analisar o edital oficial, [observação técnica]...'\n"
             . "   • 'Em casos similares anteriores, observamos que [padrão]...'\n"
             . "   • 'O erro mais comum entre [público] é [erro específico]...'\n"
             . "   • 'Pela leitura do [decreto/portaria/calendário oficial], fica claro que [fato técnico]...'\n"
             . "   PROIBIDO: 'Como especialista posso afirmar', 'Eu pessoalmente', 'Na minha opinião' (autopromoção falsa).\n\n"
             . "2) PULO DO GATO TÉCNICO OBRIGATÓRIO (1 dica não-óbvia em algum H3):\n"
             . "   Adicione uma observação que SÓ alguém com prática no tema saberia. Exemplos:\n"
             . "   • [Vagas/Auxílio]: 'Se o portal travar, abra no Chrome em modo anônimo — costuma dar erro de cache.'\n"
             . "   • [Compras]: 'Verifique o histórico de preço — alguns produtos caem 15% nas terças.'\n"
             . "   • [IR/Tributário]: 'Se errou a declaração, retificadora pode ser entregue até a data X sem multa.'\n"
             . "   • [Concurso/Educação]: 'Anote o número de inscrição já no comprovante — perdê-lo trava recurso.'\n"
             . "   IMPORTANTE: a dica precisa ser PLAUSÍVEL (alinhada com a fonte) — não invente.\n\n"
             . "3) ESTRUTURA COM PERGUNTAS REAIS (H2/H3):\n"
             . "   Use perguntas que o leitor REALMENTE digita: 'Quem tem direito?', 'Como consultar pelo CPF?',\n"
             . "   'Até quando posso fazer?', 'O que muda na prática?'. Evite H2 declarativo genérico ('Sobre o tema').\n\n"
             . "4) TRANSPARÊNCIA DE FONTE (no final do artigo, antes do fechamento):\n"
             . "   Inclua frase tipo: 'Este conteúdo foi baseado em [Fonte oficial citada nas fontes — DOU, INSS, MEC, Receita…] "
             . "verificada em {$d['hoje']}.' Sempre cite o veículo da fonte.\n"
             . "═══ FIM TOM HUMANO-ESPECIALISTA ═══\n";
    }

    /**
     * Bloco "CTA DE COMPARTILHAMENTO CONTEXTUAL" — instrui o modelo a incluir
     * UMA chamada pra compartilhar baseada na DOR específica do post.
     *
     * Por que: tráfego direto via WhatsApp/Telegram (compartilhamento orgânico)
     * é sinal forte pro Google Discover. CTA genérico ("compartilhe!") é spam;
     * CTA contextual ("envie pra quem está pedindo o auxílio") é utilidade.
     *
     * NÃO é bot — é texto convidativo que o leitor humano executa por escolha.
     * Compatível com policies (não promete resultado, não simula urgência falsa).
     */
    public static function blocoCTACompartilhamento(): string
    {
        return "═══ CTA DE COMPARTILHAMENTO CONTEXTUAL (1 vez por artigo) ═══\n"
             . "Inclua UMA chamada pra compartilhamento próximo ao fim do artigo. NÃO antes do meio. NÃO como banner — como frase do próprio fluxo editorial.\n\n"
             . "REGRAS:\n"
             . "1) Conectar à DOR REAL do post: identifique no conteúdo QUEM se beneficia desta informação e mencione esse perfil.\n"
             . "   • Auxílio/INSS: 'Conhece alguém que recebe [benefício]? Vale a pena passar essa informação adiante.'\n"
             . "   • Educação/edital: 'Manda pra quem tá tentando passar e ainda não viu o prazo.'\n"
             . "   • Compras/desconto: 'Se você conhece alguém procurando [produto], envie antes do preço subir.'\n"
             . "   • Direito/regra nova: 'Boa hora pra avisar quem [perfil] — muita gente perde por desinformação.'\n\n"
             . "2) NÃO usar imperativos vazios ('Compartilhe!', 'Manda pro grupo!', 'Marca os amigos!').\n"
             . "3) NÃO prometer benefício pelo compartilhamento ('Compartilhe e seja abençoado').\n"
             . "4) NÃO simular urgência falsa ('Última chance, manda agora antes que apague').\n"
             . "5) UMA frase, no fluxo do parágrafo. Sem botões, sem CTA visual de marketing.\n\n"
             . "POSIÇÃO: penúltimo ou antepenúltimo parágrafo (depois do conteúdo principal, antes do fechamento).\n"
             . "═══ FIM CTA COMPARTILHAMENTO ═══\n";
    }

    /**
     * REGRA ANTI-LINK-INVENTADO — Sonnet/GPT JAMAIS deve criar URL de marketplace bruta.
     *
     * Por que: anexar `?tag=`/`?partner_id=`/`?af_siteid=` em URL original Magalu/ML/Shopee
     * NÃO atribui comissão (programa BR exige deeplink gerado na plataforma). Mesmo Amazon
     * sem API: link inventado pode ter ASIN errado/expirado. Resultado: clique perdido.
     *
     * Único formato aceito: `/go/{slug}` (PrettyLinks já cadastrados pelo operador).
     * Se produto não tem PrettyLink cadastrado, mencionar nome SEM link clicável.
     */
    public static function blocoLinksAfiliado(): string
    {
        return "═══ LINKS DE PRODUTO / AFILIADO — REGRA RÍGIDA ═══\n"
             . "JAMAIS invente URL de marketplace (Amazon, Magazine Luiza, Magalu, Mercado Livre,\n"
             . "Shopee, AliExpress, Americanas etc). Anexar tag em URL original NÃO ATRIBUI\n"
             . "comissão nesses programas (exigem deeplink gerado pela plataforma).\n\n"
             . "REGRAS:\n"
             . "1. Use APENAS URLs `/go/{slug}` (PrettyLinks já cadastrados — vêm via ProductRanker\n"
             . "   ou são listados no contexto da fonte).\n"
             . "2. Se mencionar produto SEM PrettyLink disponível: cite o nome em <strong> mas\n"
             . "   NUNCA crie tag <a href> apontando pra amazon.com.br/* magazineluiza.com.br/*\n"
             . "   mercadolivre.com.br/* shopee.com.br/*. Texto puro é melhor que link sem comissão.\n"
             . "3. Links oficiais (gov.br, edu.br, sites de empresas/instituições mencionadas\n"
             . "   na fonte) são PERMITIDOS — só marketplace-de-afiliado é proibido inventar.\n"
             . "4. ProductRanker (quando rodou) traz tabela com preços + URLs `/go/...` prontas.\n"
             . "   Use exatamente como veio. Não modifique.\n"
             . "═══ FIM LINKS AFILIADO ═══\n";
    }

    /**
     * Injeta o manifesto editorial delimitado por cabeçalho/rodapé. Uso em TODOS os 3 prompt-builders.
     * Lê de prompts/manifesto_editorial.md.
     */
    public static function blocoManifesto(): string
    {
        $m = self::manifestoEditorial();
        if ($m === '') return '';
        return "═══ MANIFESTO EDITORIAL — REGRAS ABSOLUTAS ═══\n"
             . $m . "\n"
             . "═══ FIM MANIFESTO EDITORIAL ═══\n\n";
    }
}
