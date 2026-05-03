<?php
declare(strict_types=1);

require_once __DIR__ . '/Claude.php';
require_once __DIR__ . '/AntiAIValidator.php';

/**
 * AutoRevisor — segunda passada com Haiku 4.5 pra remover padrões IA detectados.
 *
 * Pipeline:
 *   1. AntiAIValidator roda no HTML gerado por Sonnet (1ª passada)
 *   2. Se severity != 'ok', pega TODAS as violations + structural issues
 *   3. Manda HTML + lista de problemas pra Haiku 4.5 reescrever PARÁGRAFO A
 *      PARÁGRAFO os trechos com problema, mantendo:
 *       - Persona do site (voz Maria Gusmão / Equipe Leão da Barra etc.)
 *       - Fatos literais das fontes (sem inventar)
 *       - Estrutura HTML (h2/h3/p/ul/li/strong)
 *   4. Re-roda AntiAIValidator no resultado
 *   5. Se ainda fail, retorna versão revisada com warning
 *
 * Custo: Haiku 4.5 = ~10x mais barato que Sonnet → 2ª passada custa ~$0.02
 * Tempo: ~5-8s extra na geração (vale a qualidade)
 *
 * Filosofia: "Cada parágrafo deve passar nos 2 testes —
 *   1. Tem padrão de IA? Se sim, refazer.
 *   2. Tem autoridade pro Google? Se não, refazer."
 */
class AutoRevisor
{
    private Claude $haiku;
    private AntiAIValidator $validator;

    public function __construct(string $apiKey)
    {
        $this->haiku = new Claude($apiKey, 'claude-haiku-4-5');
        $this->validator = new AntiAIValidator();
    }

    /**
     * @param string $html        HTML gerado pelo Sonnet
     * @param array  $contexto    {site_name, persona_autor, persona_voz, persona_tom, subtipo_nicho, sources_text}
     * @return array {html, ok, severity, antes, depois, custo_estimado_usd}
     */
    public function revisar(string $html, array $contexto): array
    {
        $antes = $this->validator->validate($html);

        if ($antes['severity'] === 'ok') {
            return [
                'html'     => $html,
                'ok'       => true,
                'severity' => 'ok',
                'antes'    => $antes,
                'depois'   => $antes,
                'reescreveu' => false,
                'custo_estimado_usd' => 0,
            ];
        }

        // Compila lista de problemas pra mostrar pro Haiku
        $problemasFormatados = $this->formatarProblemas($antes);

        // Persona + nicho pra Haiku manter voz
        $siteName = $contexto['site_name'] ?? '?';
        $persona = $contexto['persona_autor'] ?? "Equipe {$siteName}";
        $voz = $contexto['persona_voz'] ?? 'jornalística direta';
        $tom = $contexto['persona_tom'] ?? 'direto e factual';
        $nicho = $contexto['subtipo_nicho'] ?? '';
        $fontesPlain = $contexto['sources_text'] ?? '';

        $system = <<<SYS
Você é editor de revisão. Recebe um HTML de artigo + lista de PADRÕES DE IA
detectados nele. Sua tarefa: reescrever os trechos com problema preservando:

1. **Voz e persona**: artigo é de {$persona} ({$voz}, tom {$tom}, nicho: {$nicho})
2. **Fatos das fontes**: NUNCA inventar dados — só usar o que já está na prosa
   (você ESTÁ revisando, não pesquisando)
3. **Estrutura HTML**: manter h2/h3/p/ul/li/strong/table como vieram

PADRÕES DE IA QUE VOCÊ DEVE REMOVER (causa de penalização Google):
- Frases-catálogo: "Mas tem um detalhe", "E é aqui que muita gente erra",
  "O problema? A maioria descobre tarde demais", "Só que isso muda tudo",
  "Vale destacar", "É importante", "Diante disso", "Em suma", "Nesse contexto"
- Teasers isolados em parágrafo único curto: <p>Mas tem um detalhe.</p>
- Self-reference: "Veja a seguir", "Confira abaixo", "Continue lendo"
- H2/H3 templete: "O que ninguém te conta", "O que quase ninguém percebe",
  "Vale a pena agora?"
- Listas com EXATAMENTE 3 itens (LLM ama trio)
- Mesmo conector >2x ("nesse contexto" 3 vezes no artigo)
- Adjetivos vazios isolados: "incrível", "fundamental", "essencial"

PRINCÍPIOS DE REESCRITA:
1. Em vez de teaser, usar FATO CONCRETO da prosa: "Mas tem um detalhe" → "A regra
   tem uma exceção: famílias com renda acima de R\$ 1.518"
2. Em vez de H2 "O que ninguém te conta" → H2 com dado único: "Renda até R\$ 1.518
   garante isenção automática"
3. Em vez de lista de 3 itens, expandir pra 4-5 OU embutir no parágrafo
4. Cada parágrafo termina com FATO ou pausa natural — nunca com teaser-clichê

═══ TESTE INTERNO ANTES DE CADA PARÁGRAFO ═══
1. "Esse parágrafo tem padrão de IA?" Se sim → refazer.
2. "Esse parágrafo tem autoridade pro Google?" Se não → refazer.
3. "Parece que {$persona} escreveu, ou parece IA?" Se IA → refazer.

SAÍDA OBRIGATÓRIA: JSON com campo 'html' contendo o artigo revisado completo.
NÃO incluir explicação fora do JSON. Apenas o JSON.
SYS;

        $user = <<<USR
═══ HTML A REVISAR ═══
{$html}

═══ PADRÕES DE IA DETECTADOS ═══
{$problemasFormatados}

═══ INSTRUÇÃO ═══
Reescreva os trechos com problema (mantendo TODA a estrutura, fatos e persona).
Retorne JSON: { "html": "<artigo completo revisado>" }
USR;

        try {
            $resp = $this->haiku->callPublic([['role' => 'user', 'content' => $user]], $system, 12000);
            $texto = $resp['content'][0]['text'] ?? '';
            $json = Claude::parseJsonResponse($texto);

            if (!$json || empty($json['html'])) {
                return [
                    'html' => $html,
                    'ok' => false,
                    'severity' => $antes['severity'],
                    'antes' => $antes,
                    'depois' => $antes,
                    'reescreveu' => false,
                    'erro' => 'Haiku não retornou JSON válido',
                    'custo_estimado_usd' => 0.02,
                ];
            }

            $htmlRevisado = (string)$json['html'];
            $depois = $this->validator->validate($htmlRevisado);

            return [
                'html'     => $htmlRevisado,
                'ok'       => $depois['severity'] === 'ok',
                'severity' => $depois['severity'],
                'antes'    => $antes,
                'depois'   => $depois,
                'reescreveu' => true,
                'custo_estimado_usd' => 0.02,
            ];
        } catch (Throwable $e) {
            return [
                'html' => $html,
                'ok' => false,
                'severity' => $antes['severity'],
                'antes' => $antes,
                'depois' => $antes,
                'reescreveu' => false,
                'erro' => 'AutoRevisor falhou: ' . $e->getMessage(),
                'custo_estimado_usd' => 0,
            ];
        }
    }

    /** Formata violations + structural issues numa lista textual pro prompt do Haiku.
     *  Instruções LITERAIS por tipo (travessão → vírgula; teaser → fato; etc). */
    private function formatarProblemas(array $report): string
    {
        $linhas = [];
        foreach (($report['violations'] ?? []) as $v) {
            $linhas[] = "- [{$v['category']}] '{$v['phrase']}' aparece {$v['count']}x — REMOVER ou substituir por fato concreto da fonte";
        }
        foreach (($report['structural'] ?? []) as $s) {
            $instrucao = $this->instrucaoPorTipo($s);
            $linhas[] = "- [estrutural] {$s}\n    → AÇÃO: {$instrucao}";
        }
        return implode("\n", $linhas) ?: '(nenhum problema específico — apenas validar consistência)';
    }

    /** Mapa de instruções literais por tipo de problema estrutural. */
    private function instrucaoPorTipo(string $issue): string
    {
        $issueLower = mb_strtolower($issue);
        if (str_contains($issueLower, 'travessões')) {
            return "TROCAR TODOS os travessões '—' e en-dashes '–' por vírgula ', ' (ou ponto-e-vírgula '; ' ou dois-pontos ': ' conforme contexto). NENHUM travessão pode permanecer no corpo do artigo.";
        }
        if (str_contains($issueLower, 'reticências')) {
            return "TROCAR todas as reticências '...' e '…' por ponto final '.' ou continuar a frase. Máximo 1 ocorrência permitida.";
        }
        if (str_contains($issueLower, 'teaser-paragrafo-isolado')) {
            return "REMOVER o parágrafo isolado de suspense (ex: 'Mas tem um detalhe.'). Embuti-lo dentro do parágrafo seguinte usando um FATO CONCRETO da fonte (ex: 'A regra tem uma exceção: famílias com renda acima de R\$ 1.518').";
        }
        if (str_contains($issueLower, 'listas-trio-perfeito')) {
            return "QUEBRAR a lista de exatos 3 itens — expandir pra 4-5 itens com dados adicionais da fonte OU fundir 2 itens em uma frase corrida no parágrafo.";
        }
        if (str_contains($issueLower, 'densidade-conector')) {
            return "VARIAR o conector repetido — usar conectores naturais BR ('Aí', 'E', 'Só que', 'Acontece que', 'Na prática', 'Por enquanto') OU pausa em ponto final.";
        }
        if (str_contains($issueLower, 'parágrafos uniformes')) {
            return "VARIAR comprimento dos parágrafos — alguns curtos (1-2 linhas com fato direto), alguns médios (3-4 linhas com contexto). Quebra ritmo robótico.";
        }
        if (str_contains($issueLower, "'além disso'") || str_contains($issueLower, "'no entanto'")) {
            return "TROCAR todas as ocorrências extras pelo equivalente natural ('E', 'Plus', 'Outro ponto:', 'Acontece que'). Máximo 1x cada.";
        }
        if (str_contains($issueLower, 'h2s repetem palavra')) {
            return "REESCREVER os H2s pra começarem com palavras diferentes. Cada H2 deve refletir um aspecto único da fonte.";
        }
        return "Reformular o trecho/parágrafo/lista afetado conforme orientação do manifesto editorial.";
    }
}
