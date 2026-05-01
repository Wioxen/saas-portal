<?php
/**
 * Helper compartilhado para refazer um título reprovado pelo DiscoverTituloValidator.
 * Uso: DiscoverGerador, DiscoverGeradorGPT e DiscoverReviewer chamam esta classe.
 */
require_once __DIR__ . '/DiscoverTituloValidator.php';
require_once __DIR__ . '/DiscoverPostProcess.php';

class DiscoverTituloRefazer
{
    /** Instruções legíveis pra cada tipo de falha do validator. */
    private static array $instrucoesFalha = [
        'comprimento'            => 'ajustar para 55-70 caracteres',
        'numero'                 => 'incluir um número concreto (valor, data, quantidade)',
        'ano_sazonal'            => 'incluir o ano (ex: 2026) se tema sazonal',
        'verbo_acao'             => 'usar verbo de ação/prazo (encerra, termina, libera, começa, paga, perde)',
        'consequencia'           => 'incluir a CONSEQUÊNCIA se o leitor não agir (quem perde, quem paga, quem fica de fora)',
        'separador'              => 'usar dois pontos (:), ponto-e-vírgula (;) ou parênteses (...) como separador',
        'tem_travessao'          => 'REMOVER travessão (—) e en-dash (–) — usar : ; ou () no lugar',
        'adjetivo_vazio'         => 'não começar com adjetivo vazio (incrível, imperdível, surpreendente)',
        'pergunta_generica'      => 'não usar pergunta genérica (Sabe como…? Você sabia…?)',
        'sem_substantivo_inicial'=> 'começar com o sujeito/keyword concreto do tema',
        'sem_diferenciacao'      => 'incluir um termo ESPECÍFICO de risco/gap/erro do gancho da fonte (CadÚnico, multa, pegadinha, etc) — não pode ser título portal-padrão só com data+keyword',
    ];

    /**
     * Tenta refazer o título via Claude. Retorna '' se falhar.
     *
     * @param Claude $claude       instância pronta
     * @param string $tituloRuim   título que falhou
     * @param array  $falhas       critérios falhos do validator
     * @param string $keyword      palavra-chave do artigo
     * @param string $tituloAntes  título original (contexto)
     * @param array  $ganchoPalavras palavras-chave do gancho da fonte (opcional — usadas como obrigatórias no novo título)
     * @param string $ganchoFrase  frase-exemplo do gancho (opcional — dá contexto ao LLM)
     */
    public static function viaClaude(
        Claude $claude,
        string $tituloRuim,
        array $falhas,
        string $keyword,
        string $tituloAntes,
        array $ganchoPalavras = [],
        string $ganchoFrase = ''
    ): string {
        $lista = [];
        foreach ($falhas as $f) {
            if (isset(self::$instrucoesFalha[$f])) $lista[] = '- ' . self::$instrucoesFalha[$f];
        }
        $correcoes = implode("\n", $lista);
        $ganchoBloco = '';
        if (!empty($ganchoPalavras)) {
            $ganchoBloco = "\nGANCHO OBRIGATÓRIO (pelo menos 1 destes termos deve aparecer no novo título): "
                         . implode(', ', array_slice($ganchoPalavras, 0, 5));
            if ($ganchoFrase !== '') {
                $ganchoBloco .= "\nFRASE DA FONTE que motiva o gancho: \"{$ganchoFrase}\"";
            }
            $ganchoBloco .= "\n";
        }

        $system = "Você é editor-chefe de portal viral brasileiro otimizado pro Google Discover.\n"
                . "TAREFA: reescrever APENAS o título de um artigo. Não invente fatos.\n\n"
                . "REGRAS OBRIGATÓRIAS:\n"
                . "- Entre 55 e 70 caracteres\n"
                . "- Fórmula campeã: [FATO/BENEFÍCIO] + separador (:, ; ou parênteses) + [CONSEQUÊNCIA se não agir]\n"
                . "- PROIBIDO: travessão (—), en-dash (–), adjetivos oco, pergunta genérica, clickbait\n"
                . "- Exemplo: 'Isenção do ENEM 2026: CadÚnico desatualizado derruba pedidos antes do dia 24'\n\n"
                . "FORMATO DE SAÍDA: JSON puro (sem code fences):\n"
                . '{"titulo": "novo título aqui"}';

        $user = "KEYWORD: {$keyword}\n"
              . "TÍTULO ATUAL (REPROVADO):\n{$tituloRuim}\n\n"
              . "TÍTULO ORIGINAL (referência):\n{$tituloAntes}\n"
              . $ganchoBloco
              . "\nCORREÇÕES NECESSÁRIAS:\n{$correcoes}\n\n"
              . "Gere 1 novo título aplicando TODAS as correções. Saída em JSON puro.";

        try {
            $resp = $claude->call([['role' => 'user', 'content' => $user]], $system);
            $texto = $resp['content'][0]['text'] ?? '';
            $j = Claude::parseJsonResponse($texto);
            if (!is_array($j) || empty($j['titulo'])) return '';
            return trim((string)$j['titulo']);
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Fluxo completo: valida → se reprovado → refaz via Claude → só aceita se melhorar score.
     * Retorna ['titulo' => final, 'score' => X, 'falhas' => [...], 'refeito' => bool]
     */
    public static function validarERefazer(
        Claude $claude,
        string $titulo,
        string $keyword,
        string $tituloAntes = '',
        array $ganchoPalavras = [],
        string $ganchoFrase = ''
    ): array {
        $titulo = DiscoverPostProcess::normalizarTitulo($titulo);
        $val = DiscoverTituloValidator::avaliar($titulo, $ganchoPalavras);
        if ($val['aprovado']) {
            return ['titulo' => $titulo, 'score' => $val['score'], 'falhas' => $val['falhas'], 'refeito' => false];
        }
        $refeito = self::viaClaude($claude, $titulo, $val['falhas'], $keyword, $tituloAntes ?: $titulo, $ganchoPalavras, $ganchoFrase);
        if ($refeito === '') {
            return ['titulo' => $titulo, 'score' => $val['score'], 'falhas' => $val['falhas'], 'refeito' => false];
        }
        $refeitoNorm = DiscoverPostProcess::normalizarTitulo($refeito);
        $val2 = DiscoverTituloValidator::avaliar($refeitoNorm, $ganchoPalavras);
        // Só aceita se melhorou o score
        if ($val2['score'] > $val['score']) {
            return ['titulo' => $refeitoNorm, 'score' => $val2['score'], 'falhas' => $val2['falhas'], 'refeito' => true];
        }
        return ['titulo' => $titulo, 'score' => $val['score'], 'falhas' => $val['falhas'], 'refeito' => false];
    }
}
