<?php
declare(strict_types=1);

require_once __DIR__ . '/Claude.php';

/**
 * DiscoverProfundidade — passo Sonnet pós-geração que enriquece o artigo com
 * análise comparativa + implicação prática não-óbvia.
 *
 * Por que existe:
 *   Posts gerados pelo pipeline atual REESCREVEM a fonte. Google premia
 *   conteúdo que AGREGA insight, não que parafraseia. Esse passo:
 *   1. Compara com edição anterior do programa (se fonte sustenta)
 *   2. Identifica implicação prática pra perfil específico (família X, etc.)
 *   3. Insere bloco h2 + 2-3 parágrafos perto do final do desenvolvimento
 *
 * Source-bound: só usa dados literais das fontes anexadas. Zero invenção.
 * Fidelity gate (já existente) pega se Sonnet alucinar.
 *
 * Custo: ~$0.04-0.06 por chamada (Sonnet 8K input, 1K output). Vale a qualidade.
 *
 * Filosofia: "Editor analítico" — não opinativo, voltado pra mobile escaneador.
 */
class DiscoverProfundidade
{
    private Claude $sonnet;

    public function __construct(string $apiKey, string $model = 'claude-sonnet-4-6')
    {
        $this->sonnet = new Claude($apiKey, $model);
    }

    /**
     * @param string $html       HTML do artigo já gerado (após Haiku)
     * @param array  $sources    Texto bruto de cada fonte (paragraphs concatenados)
     * @param array  $cfg        {site_name, persona, ...}
     * @return array {html, ok, log}
     */
    public function agregar(string $html, array $sources, array $cfg): array
    {
        if (empty($sources)) {
            return ['html' => $html, 'ok' => false, 'log' => 'sem fontes pra analisar'];
        }

        $sourcesText = '';
        foreach ($sources as $i => $src) {
            $sourcesText .= "═══ FONTE " . ($i + 1) . " ═══\n" . mb_substr((string)$src, 0, 4000) . "\n\n";
        }
        $sourcesText = mb_substr($sourcesText, 0, 16000);

        $persona = (string)($cfg['persona']['autor'] ?? 'Equipe ' . ($cfg['site_name'] ?? '?'));
        $voz = (string)($cfg['persona']['voz'] ?? 'jornalística direta');
        $nicho = (string)($cfg['subtipo_nicho'] ?? '');

        $system = <<<SYS
Você é editor analítico de portal jornalístico ({$persona}, voz {$voz}, nicho: {$nicho}).
Recebe um ARTIGO já redigido + as FONTES scrapeadas. Sua tarefa: produzir
UMA SEÇÃO ANALÍTICA NOVA que enriquece o artigo com insight não-óbvio.

═══ O QUE PRODUZIR ═══

UM bloco com:
- 1 h2 em SENTENCE CASE PT-BR (só primeira letra maiúscula + nomes próprios), 5-10 palavras, COM DADO CONCRETO se a fonte permite. PROIBIDO Title Case inglês ("o Que Isso Garante"). Use "O que isso garante" (sentence case).
- 2-3 parágrafos curtos (cada ≤40 palavras)
- Total: 200-280 palavras

A seção pode ser de UM dos 3 tipos (escolha o que a fonte sustenta melhor):

**Tipo A — Comparação histórica:** edição anterior do mesmo programa.
   Ex: "Edital de 2025 ofertava 350 vagas; atual passa pra 500 vagas (+43%)
   e estende prazo em 5 dias úteis."

**Tipo B — Implicação prática não-óbvia:** o que muda pra um perfil concreto.
   Ex: "Pra quem já tem CadÚnico ativo, o sistema preenche os campos
   automaticamente — economiza ~15 minutos vs cadastro novo. Quem não tem
   espera 5 dias úteis pra validação CPF."

**Tipo C — Contexto editorial:** relação com política mais ampla.
   Ex: "O Sisu+ se soma ao Pé-de-Meia (anunciado em janeiro) e ao programa
   Mais Médicos, formando o tripé federal de inclusão educacional 2026."

═══ REGRAS CRÍTICAS ═══

1. **SOURCE-BOUND ABSOLUTO:** USAR SOMENTE dados que estão LITERALMENTE nas
   FONTES anexadas. ZERO invenção. Se a fonte não dá número/comparação,
   USE TIPO B (implicação prática) com cenário concreto sustentado pela fonte.

2. **VOZ:** editor analítico (não opinativo). NÃO usar "eu acho", "parece",
   "talvez". Use "a regra implica", "a expansão sinaliza", "o cronograma
   sugere".

3. **MOBILE-FIRST:** frases ≤25 palavras. Parágrafos ≤40 palavras.

4. **ZERO FRASES BATIDAS:**
   ❌ vale destacar / vale ressaltar / vale lembrar
   ❌ é importante destacar / é fundamental
   ❌ diante disso / diante desse cenário
   ❌ em suma / em síntese / em resumo / em contrapartida
   ❌ nesse contexto / nesse sentido / dessa forma
   ❌ a verdade é que / o que ninguém te conta
   ❌ na prática / na real / no fim das contas
   ❌ esse detalhe / esse ponto / o erro que / o detalhe que
   ❌ portanto / ademais / outrossim
   ❌ tem gente que / quem espera / fica de fora

5. **ZERO TRAVESSÕES:** usar vírgula, parênteses ou ponto. Banido —/–.

6. **ZERO META-NARRATIVA:** nada de "como vamos ver", "este artigo mostra",
   "continue lendo".

═══ OUTPUT OBRIGATÓRIO ═══

JSON puro (sem comentário fora):
{
  "tipo": "A|B|C",
  "h2_titulo": "string (com dado concreto se possível)",
  "paragrafos_html": "<p>...</p>\n<p>...</p>\n<p>...</p>",
  "palavras_total": int (estimado),
  "fonte_usada": "trecho literal da fonte que sustenta a análise (até 200 chars)"
}
SYS;

        $user = <<<USR
═══ ARTIGO ATUAL ═══
{$html}

═══ FONTES SCRAPEADAS ═══
{$sourcesText}

═══ INSTRUÇÃO ═══
Gere UM bloco analítico (200-280 palavras) que agregue insight ao artigo.
Tipo (A/B/C) escolha o melhor sustentado pelas fontes.
Retorne JSON puro: {tipo, h2_titulo, paragrafos_html, palavras_total, fonte_usada}.
USR;

        try {
            $resp = $this->sonnet->callPublic([['role' => 'user', 'content' => $user]], $system, 1500);
            $texto = $resp['content'][0]['text'] ?? '';
            $json = Claude::parseJsonResponse($texto);

            if (!$json || empty($json['h2_titulo']) || empty($json['paragrafos_html'])) {
                return ['html' => $html, 'ok' => false, 'log' => 'Sonnet não retornou JSON válido'];
            }

            $bloco = "\n\n<h2>" . htmlspecialchars((string)$json['h2_titulo'], ENT_QUOTES, 'UTF-8') . "</h2>\n"
                   . (string)$json['paragrafos_html'] . "\n\n";

            // Insere ANTES da última seção do artigo (FAQ, resposta-direta ou fechamento).
            // Procura nessa ordem: <h2> contendo "Pergunta" / "FAQ", <div class='resposta-direta'>,
            // <p class='resposta-direta'>, ou simplesmente último h2.
            $htmlNovo = self::inserirNoFinalDoDesenvolvimento($html, $bloco);

            return [
                'html' => $htmlNovo,
                'ok' => true,
                'log' => [
                    'tipo' => (string)($json['tipo'] ?? '?'),
                    'h2_titulo' => (string)$json['h2_titulo'],
                    'palavras_total' => (int)($json['palavras_total'] ?? 0),
                    'fonte_usada' => mb_substr((string)($json['fonte_usada'] ?? ''), 0, 200),
                ],
            ];
        } catch (Throwable $e) {
            return ['html' => $html, 'ok' => false, 'log' => 'erro Sonnet: ' . $e->getMessage()];
        }
    }

    /**
     * Insere bloco analítico ANTES da seção final do artigo.
     * Busca em ordem: FAQ h2, div.resposta-direta, p.resposta-direta, último h2.
     * Fallback: append no final.
     */
    private static function inserirNoFinalDoDesenvolvimento(string $html, string $bloco): string
    {
        // 1. <h2> de FAQ ("perguntas", "FAQ", "dúvidas")
        if (preg_match('/(<h2[^>]*>\s*(?:perguntas?|faq|d[úu]vidas?))/iu', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            return mb_substr($html, 0, $pos) . $bloco . mb_substr($html, $pos);
        }
        // 2. <div class='resposta-direta'>
        if (preg_match('/(<div[^>]*class=[\'"][^\'"]*\bresposta-direta\b)/iu', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            return mb_substr($html, 0, $pos) . $bloco . mb_substr($html, $pos);
        }
        // 3. <p class='resposta-direta'>
        if (preg_match('/(<p[^>]*class=[\'"][^\'"]*\bresposta-direta\b)/iu', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            return mb_substr($html, 0, $pos) . $bloco . mb_substr($html, $pos);
        }
        // 4. Insere ANTES do último <h2> (que costuma ser fechamento ou FAQ)
        if (preg_match_all('/<h2[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $matches = $m[0];
            if (count($matches) >= 2) {
                $ultimaPos = $matches[count($matches) - 1][1];
                return mb_substr($html, 0, $ultimaPos) . $bloco . mb_substr($html, $ultimaPos);
            }
        }
        // 5. Fallback: append no final
        return $html . $bloco;
    }
}
