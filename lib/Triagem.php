<?php
/**
 * Triagem — pontua manchetes RSS 0-10 antes de gastar Sonnet.
 *
 * Usa Haiku (modelo barato Anthropic, ~$0.001 por batch de 15 items) pra avaliar
 * potencial viral de cada manchete em 1 chamada batch. Critérios cruzam:
 *   - palavras-gatilho de Discover BR (calendário, R$, liberado, prazo, hoje...)
 *   - benefício direto/utilidade (sacar, receber, antecipar)
 *   - match com nicho do site alvo (subtipo_nicho + persona.audiencia)
 *   - termos canibal (penaliza assuntos que sister site cobre)
 *
 * Retorna [['nota'=>0-10, 'motivo'=>'≤8 palavras'], ...] indexado pela posição
 * do item no array de entrada. Se Haiku falhar, retorna scores neutros (5)
 * pra não bloquear o fluxo — UI segue funcional sem badges.
 */
require_once __DIR__ . '/Claude.php';

class Triagem
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'claude-haiku-4-5')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    /**
     * Pontua um batch de items. Cada item precisa ter pelo menos 'title'; 'source' e
     * 'description' opcionais entram no contexto se presentes.
     *
     * @param array $items   Lista de items RSS (com 'title', opcionalmente 'source'/'description')
     * @param array $siteCtx ['nome'=>..., 'nicho'=>..., 'audiencia'=>..., 'canibal'=>[...]]
     * @return array Indexado por posição: [i => ['nota'=>int, 'motivo'=>string]]
     */
    public function pontuar(array $items, array $siteCtx = []): array
    {
        if (empty($items)) return [];

        $system = $this->buildSystemPrompt();
        $userMsg = $this->buildUserMessage($items, $siteCtx);

        try {
            $claude = new Claude($this->apiKey, $this->model);
            // max_tokens 1500 cobre 15-20 items × ~70 tokens cada de output
            $resp = $claude->callPublic(
                [['role' => 'user', 'content' => $userMsg]],
                $system,
                1500
            );
            $content = $resp['content'][0]['text'] ?? '';
            return $this->parseScores($content, count($items));
        } catch (Throwable $e) {
            error_log('[Triagem] falha Haiku: ' . $e->getMessage());
            return $this->scoresNeutros(count($items));
        }
    }

    private function buildSystemPrompt(): string
    {
        return <<<SYS
Você é avaliador de potencial viral de manchetes para Google Discover BR (operação de arbitragem de tráfego).

Para cada manchete, atribua nota 0-10 baseada nestes critérios (base 5, ajusta ±):

+1 cada palavra-gatilho de Discover BR: calendário, valor, R\$, liberado, novo, prazo, hoje, esta semana, CPF, antecipa, confirma, anuncia
+2 se há benefício monetário direto (sacar/receber/ganhar/economizar com valor)
+1 se público amplo (Bolsa Família, INSS, FGTS, PIS, MEI, concurso, edital, BPC)
+1 se recência clara (data atual ou mês corrente explícito)
+1 se match com nicho do site alvo
-2 se conflita com termos CANIBAL (sister site cobre, evitar)
-2 se off-topic do nicho do site
-1 se genérico ("dicas pra...", "o que é...", "o que fazer quando...")
-1 se evergreen sem urgência (definições, conceitos, listas de "melhores")

Mínimo 0, máximo 10. Use o range completo — não centralize tudo em 5-6.

Responda EXCLUSIVAMENTE com JSON array, sem markdown, sem texto extra:
[{"i":0,"nota":N,"motivo":"≤6 palavras explicando"},{"i":1,"nota":N,"motivo":"..."},...]
SYS;
    }

    private function buildUserMessage(array $items, array $siteCtx): string
    {
        $lines = [];
        $lines[] = 'Site alvo: ' . ($siteCtx['nome'] ?? 'desconhecido');
        if (!empty($siteCtx['nicho']))     $lines[] = 'Nicho: ' . $siteCtx['nicho'];
        if (!empty($siteCtx['audiencia'])) $lines[] = 'Audiência: ' . $siteCtx['audiencia'];
        if (!empty($siteCtx['canibal']) && is_array($siteCtx['canibal'])) {
            $canibal = array_slice($siteCtx['canibal'], 0, 14);
            $lines[] = 'Termos CANIBAL (penalizar -2): ' . implode(', ', $canibal);
        }
        $lines[] = '';
        $lines[] = 'Manchetes:';
        foreach ($items as $i => $it) {
            $titulo = trim((string)($it['title'] ?? ''));
            if ($titulo === '') $titulo = '(sem título)';
            $titulo = mb_substr($titulo, 0, 180);
            $fonte = trim((string)($it['source'] ?? ''));
            $linha = "[{$i}] {$titulo}";
            if ($fonte !== '') $linha .= " — {$fonte}";
            $lines[] = $linha;
        }
        return implode("\n", $lines);
    }

    private function parseScores(string $content, int $expectedCount): array
    {
        // Limpa fences markdown se Haiku escapar do "sem markdown"
        $content = preg_replace('/```(?:json)?\s*|\s*```/', '', $content);
        $content = trim($content);

        $arr = json_decode($content, true);
        if (!is_array($arr) && preg_match('/\[\s*\{.*\}\s*\]/s', $content, $m)) {
            $arr = json_decode($m[0], true);
        }

        $out = [];
        if (is_array($arr)) {
            foreach ($arr as $row) {
                if (!is_array($row) || !isset($row['i'])) continue;
                $i = (int)$row['i'];
                $out[$i] = [
                    'nota'   => max(0, min(10, (int)($row['nota'] ?? 5))),
                    'motivo' => mb_substr(trim((string)($row['motivo'] ?? '')), 0, 80),
                ];
            }
        }

        // Preenche faltantes com neutro
        for ($i = 0; $i < $expectedCount; $i++) {
            if (!isset($out[$i])) {
                $out[$i] = ['nota' => 5, 'motivo' => 'sem avaliação'];
            }
        }
        ksort($out);
        return $out;
    }

    private function scoresNeutros(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[$i] = ['nota' => 5, 'motivo' => 'triagem indisponível'];
        }
        return $out;
    }
}
