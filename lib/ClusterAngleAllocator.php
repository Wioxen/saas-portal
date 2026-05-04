<?php
/**
 * ClusterAngleAllocator — atribui DNA editorial único por item de cluster ANTES da geração.
 *
 * Objetivo: matar convergência semântica em 4 dimensões — ângulo, estrutura visual,
 * padrão de título e abertura. Em vez de Claude escolher reativamente, este allocator
 * analisa TODOS os items juntos com contexto da persona do site e pré-atribui:
 *   - angulo (1 dos 8 — exclusivo entre items)
 *   - intencao (ganho/perda — alterna)
 *   - estrutura (1 dos 5 — exclusiva entre items próximos)
 *   - title_pattern (1 dos 6 — exclusivo entre items próximos)
 *   - diferenciador / abertura_proibida / promessa (texto livre)
 *
 * Fluxo: 1 chamada Haiku batch (cheap, ~$0.005 por cluster de 8).
 * Fallback: rodízio determinístico de 8 ângulos × 5 estruturas × 6 padrões.
 *
 * Persona-aware: aceita siteCtx (nome/voz/audiencia/nicho/canibal) pra calibrar
 * diferenciadores e penalizar ângulos que sister site cobre.
 */
class ClusterAngleAllocator
{
    private Claude $claude;
    public array $log = [];

    /** Pool de ângulos (8) — cada item do cluster recebe um exclusivo se N≤8. */
    const ANGULOS = [
        'alerta_urgencia' => 'prazo curto ou ameaça concreta (último dia, acaba amanhã, risco iminente)',
        'erro_comum'      => 'problema que elimina/impede o leitor (o erro que deixa você de fora)',
        'oportunidade'    => 'ganho direto disponível (como garantir, quem tem direito agora)',
        'comparacao'      => 'opções ou caminhos confrontados (melhor rota, X vs Y)',
        'guia_pratico'    => 'passo a passo concreto (como fazer em X passos, roteiro)',
        'revelacao'       => 'dado pouco conhecido ou mal entendido (ninguém te conta, a verdade sobre)',
        'economia'        => 'dinheiro direto na mão (quanto dá pra economizar, valor real)',
        'timing'          => 'momento certo de agir (melhor dia, janela ideal, quando comprar/pedir)',
    ];

    /** Pool de estruturas visuais (5) — diversifica layout pra anti-template detection. */
    const ESTRUTURAS = [
        'table_heavy'     => 'predominantemente tabelas/dados estruturados (calendário, valores por estado, comparativo de planos)',
        'step_by_step'    => 'passos numerados sequenciais (ol > li com números grandes — Schema HowTo)',
        'q_and_a'         => 'pergunta/resposta sucessivos (FAQ-first, dúvidas reais ditando o flow)',
        'narrativa'       => 'fluxo contextual com história/caso (lead com cenário humano, não dado)',
        'lista_comparativa' => 'bullet points comparando opções/critérios (cards lado a lado)',
    ];

    /** Pool de padrões de título (6) — cada item recebe um diferente. */
    const TITLE_PATTERNS = [
        'pergunta_direta' => 'pergunta direta com resposta no corpo ("Qual o valor do PIS 2026?")',
        'numero_promessa' => 'número + promessa concreta ("5 erros que tiram seu PIS")',
        'alerta_prazo'    => 'urgência temporal ("PIS 2026: último dia para sacar é...")',
        'data_promessa'   => 'data + benefício ("PIS 2026: pagamento começa segunda (23)")',
        'comparativo'     => 'X vs Y / X ou Y ("PIS vs PASEP: qual é o seu?")',
        'revelacao'       => 'desvelamento ("O que ninguém te conta sobre o PIS 2026")',
    ];

    /** Pool de formatos de introdução (5) — varia o ESQUELETO FÍSICO do topo do artigo (anti-template). */
    const INTRO_FORMATS = [
        'classico_3p_resposta_snippet' => 'P1 (gancho) + P2 (autoridade) + P3 (SALTO pra dado novo, NUNCA paráfrase do P1) + Resposta Direta + snippet <ul> + H2',
        'lead_curto_2p_h2_imediato'    => 'apenas P1 (lead denso) + Resposta Direta + H2 imediato (sem P2/P3, snippet opcional)',
        'narrativa_p_unico_denso'      => 'P único de 50-70 palavras com cenário humano + Resposta Direta + H2 (sem snippet, sem P2/P3)',
        'pergunta_lead_resposta'       => 'P1 termina em pergunta + Resposta Direta responde + 1 P de contexto + H2 (snippet opcional)',
        'dado_solo_h2'                 => 'P1 só com o dado bruto (até 25 palavras) + snippet com 3 bullets + H2 (sem P2/P3, sem Resposta Direta padrão — o snippet ocupa o papel)',
    ];

    /** Pool de quantidade de H2s (4) — varia profundidade visual entre artigos do cluster. */
    const NUM_H2_OPTIONS = [3, 4, 5, 6];

    public function __construct(Claude $claude)
    {
        $this->claude = $claude;
    }

    /**
     * Aloca DNA editorial para cada item do cluster.
     *
     * @param array $items   [{url, title, contentSnippet}, ...]
     * @param array $siteCtx Opcional: ['nome'=>..., 'voz'=>..., 'audiencia'=>..., 'nicho'=>..., 'canibal'=>[...]]
     * @return array Itens enriquecidos com DNA completo
     */
    public function alocar(array $items, array $siteCtx = []): array
    {
        $n = count($items);
        if ($n === 0) return [];
        if ($n === 1) return [array_merge($items[0], $this->emptyDna())];

        try {
            $aloc = $this->alocarViaClaude($items, $siteCtx);
            if (count($aloc) === $n) {
                $this->log[] = "Allocator: {$n} items alocados via Claude";
                return $this->mergeItems($items, $aloc);
            }
            $this->log[] = "Allocator: Claude retornou " . count($aloc) . " items (esperado {$n}) — fallback determinístico";
        } catch (Throwable $e) {
            $this->log[] = 'Allocator: Claude falhou (' . $e->getMessage() . ') — fallback determinístico';
        }

        return $this->alocarFallback($items);
    }

    /** Chamada Claude com prompt estruturado. Throw em falha/JSON inválido → cai no fallback. */
    private function alocarViaClaude(array $items, array $siteCtx = []): array
    {
        $n = count($items);
        $lista = '';
        foreach ($items as $i => $it) {
            $lista .= "\n## Item " . ($i + 1) . "\n";
            $lista .= 'URL: ' . ($it['url'] ?? '') . "\n";
            $lista .= 'Título: ' . ($it['title'] ?? '') . "\n";
            $lista .= 'Trecho: ' . mb_substr($it['contentSnippet'] ?? '', 0, 400) . "\n";
        }

        $angulosList = '';
        foreach (self::ANGULOS as $k => $desc) {
            $angulosList .= "- \"{$k}\" — {$desc}\n";
        }
        $estruturasList = '';
        foreach (self::ESTRUTURAS as $k => $desc) {
            $estruturasList .= "- \"{$k}\" — {$desc}\n";
        }
        $titlePatternsList = '';
        foreach (self::TITLE_PATTERNS as $k => $desc) {
            $titlePatternsList .= "- \"{$k}\" — {$desc}\n";
        }
        $introFormatsList = '';
        foreach (self::INTRO_FORMATS as $k => $desc) {
            $introFormatsList .= "- \"{$k}\" — {$desc}\n";
        }

        // Bloco de contexto do site (persona-aware)
        $ctxSection = '';
        if (!empty($siteCtx)) {
            $ctxLines = [];
            if (!empty($siteCtx['nome']))      $ctxLines[] = 'Site: ' . $siteCtx['nome'];
            if (!empty($siteCtx['nicho']))     $ctxLines[] = 'Nicho: ' . $siteCtx['nicho'];
            if (!empty($siteCtx['voz']))       $ctxLines[] = 'Voz editorial: ' . $siteCtx['voz'];
            if (!empty($siteCtx['audiencia'])) $ctxLines[] = 'Audiência: ' . $siteCtx['audiencia'];
            if (!empty($siteCtx['canibal']) && is_array($siteCtx['canibal'])) {
                $can = array_slice($siteCtx['canibal'], 0, 14);
                $ctxLines[] = 'Termos CANIBAL (sister sites cobrem — DEVE evitar como ângulo principal): ' . implode(', ', $can);
            }
            if (!empty($ctxLines)) {
                $ctxSection = "\nCONTEXTO DO SITE-ALVO (calibre diferenciadores e promessas pra esse público):\n" . implode("\n", $ctxLines) . "\n";
            }
        }

        $prompt = <<<PROMPT
Você é o editor-chefe de um cluster de {$n} artigos para Google Discover. Missão: ZERO convergência em 4 dimensões — ângulo, estrutura visual, padrão de título e abertura — entre os artigos do cluster.
{$ctxSection}
ITEMS DO CLUSTER (em ordem):
{$lista}

TAREFA: para CADA item, atribuir 9 campos do DNA editorial:

1. **angulo** — UM dos abaixo (NUNCA repetir entre items do mesmo cluster, salvo se N>8):
{$angulosList}

2. **intencao** — "ganho" OU "perda". ALTERNAR estritamente na ordem (item 1 = perda, item 2 = ganho, item 3 = perda, ...).

3. **estrutura** — UMA das abaixo (NÃO repetir em items consecutivos):
{$estruturasList}

4. **title_pattern** — UM dos abaixo (NÃO repetir entre items do cluster, salvo se N>6):
{$titlePatternsList}

5. **intro_format** — UM dos abaixo (NÃO repetir em items consecutivos — força esqueleto físico DIFERENTE):
{$introFormatsList}

6. **num_h2** — número INTEIRO de H2s (3, 4, 5 ou 6). Varia entre items pra evitar fingerprint estrutural. Se item é mais raso → 3-4. Se mais profundo → 5-6.

7. **diferenciador** — UMA frase em até 22 palavras descrevendo o DADO ESPECÍFICO ou DIMENSÃO que SÓ esse item vai cobrir. Calibre pra audiência do site (descrita no contexto acima).

8. **abertura_proibida** — fórmula de abertura óbvia que os OUTROS items provavelmente usariam. ESSE item NÃO pode usar essa abertura.

9. **promessa** — ganho concreto para o leitor em até 15 palavras. NUNCA genérico ("saiba mais", "entenda tudo" — banidos). Calibre pra audiência.

REGRAS DURAS:
- angulo NUNCA se repete entre items (até N=8)
- title_pattern NUNCA se repete entre items (até N=6)
- estrutura NÃO repete em items CONSECUTIVOS
- intro_format NÃO repete em items CONSECUTIVOS (anti-template fingerprint)
- num_h2 deve VARIAR pelo menos entre items consecutivos (não pode ser 4 em todos)
- intencao alterna estritamente
- Escolha o angulo/estrutura/title_pattern/intro_format que MELHOR se encaixam no CONTEÚDO REAL do item
- Se contexto do site tem termos CANIBAL, o angulo escolhido NÃO pode ter foco principal nesses termos — sister site cobre
- Se 2 items têm conteúdo parecido → diferenciadores MUITO específicos e opostos
- Tudo em português BR, minúsculas nos valores enum (angulo, intencao, estrutura, title_pattern, intro_format)

SAÍDA — JSON VÁLIDO (nada antes/depois, sem markdown, sem ```):
{
  "items": [
    {"angulo": "...", "intencao": "...", "estrutura": "...", "title_pattern": "...", "intro_format": "...", "num_h2": 4, "diferenciador": "...", "abertura_proibida": "...", "promessa": "..."}
  ]
}

Total de items no JSON: {$n} (EXATAMENTE).
PROMPT;

        $resp = $this->claude->callPublic(
            [['role' => 'user', 'content' => $prompt]],
            'Você é editor-chefe planejando cluster editorial. Retorne APENAS JSON válido.',
            4000
        );

        $texto = trim($resp['content'][0]['text'] ?? '');
        $texto = preg_replace('/^```(?:json)?\s*/m', '', $texto) ?? $texto;
        $texto = preg_replace('/\s*```\s*$/m', '', $texto) ?? $texto;
        $texto = trim($texto);

        $json = json_decode($texto, true);
        if (!is_array($json) || !isset($json['items']) || !is_array($json['items'])) {
            throw new RuntimeException('JSON inválido ou sem campo "items"');
        }
        return $json['items'];
    }

    /**
     * Fallback determinístico: rodízio de 8 ângulos × 5 estruturas × 6 padrões + intenção alternada.
     * Garante variação mesmo sem LLM — usa offset por índice pra distribuir uniformemente.
     */
    private function alocarFallback(array $items): array
    {
        $angulos        = array_keys(self::ANGULOS);
        $estruturas     = array_keys(self::ESTRUTURAS);
        $titlePatterns  = array_keys(self::TITLE_PATTERNS);
        $introFormats   = array_keys(self::INTRO_FORMATS);
        $numH2Pool      = self::NUM_H2_OPTIONS;
        $out = [];
        foreach ($items as $i => $it) {
            $out[] = array_merge($it, [
                'angulo'            => $angulos[$i % count($angulos)],
                'intencao'          => $i % 2 === 0 ? 'perda' : 'ganho',
                'estrutura'         => $estruturas[$i % count($estruturas)],
                'title_pattern'     => $titlePatterns[$i % count($titlePatterns)],
                'intro_format'      => $introFormats[$i % count($introFormats)],
                'num_h2'            => $numH2Pool[$i % count($numH2Pool)],
                'diferenciador'     => '',
                'abertura_proibida' => '',
                'promessa'          => '',
            ]);
        }
        return $out;
    }

    private function mergeItems(array $items, array $alloc): array
    {
        $out = [];
        foreach ($items as $i => $it) {
            $a = $alloc[$i] ?? [];
            $out[] = array_merge($it, [
                'angulo'            => isset($a['angulo'])            ? trim((string)$a['angulo'])            : '',
                'intencao'          => isset($a['intencao'])          ? trim((string)$a['intencao'])          : '',
                'estrutura'         => isset($a['estrutura'])         ? trim((string)$a['estrutura'])         : '',
                'title_pattern'     => isset($a['title_pattern'])     ? trim((string)$a['title_pattern'])     : '',
                'intro_format'      => isset($a['intro_format'])      ? trim((string)$a['intro_format'])      : '',
                'num_h2'            => isset($a['num_h2'])            ? max(3, min(6, (int)$a['num_h2']))     : 4,
                'diferenciador'     => isset($a['diferenciador'])     ? trim((string)$a['diferenciador'])     : '',
                'abertura_proibida' => isset($a['abertura_proibida']) ? trim((string)$a['abertura_proibida']) : '',
                'promessa'          => isset($a['promessa'])          ? trim((string)$a['promessa'])          : '',
            ]);
        }
        return $out;
    }

    private function emptyDna(): array
    {
        return [
            'angulo'            => '',
            'intencao'          => '',
            'estrutura'         => '',
            'title_pattern'     => '',
            'intro_format'      => '',
            'num_h2'            => 4,
            'diferenciador'     => '',
            'abertura_proibida' => '',
            'promessa'          => '',
        ];
    }
}
