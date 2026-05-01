<?php
/**
 * DiscoverFactChecker — verifica claims do post contra as fontes (anti-alucinação).
 *
 * Discover penaliza conteúdo com fatos inventados. Hoje confiamos no Claude/Sonnet pra
 * não alucinar (CLAUDE.md tem regras), mas SEM verificação independente.
 *
 * Este módulo:
 *   1. Extrai claims FACTUAIS do post (frases com números, datas, nomes próprios)
 *   2. Pede pro GPT-mini (barato) avaliar: "este fato está nas fontes?"
 *   3. Marca claims `unverified` ou `contradicted`
 *   4. Decisão: > 30% de claims sem lastro → reprovação (devolve pra Reviewer ou marca alerta)
 *
 * Custo: ~$0.001 por verificação (gpt-4o-mini, ~3k tokens).
 *
 * Saída:
 *   - claims_total
 *   - claims_verificados (com lastro)
 *   - claims_unverified
 *   - claims_contradicted (lasceou — contradiz fonte)
 *   - aprovado: bool
 *   - detalhes: lista de issues
 *
 * Uso (em DiscoverGerador depois da geração):
 *   $check = DiscoverFactChecker::verificar($html, $textosFontes, $openai);
 *   if (!$check['aprovado']) {
 *       // re-rodar Reviewer ou marcar como suspeito
 *   }
 */
class DiscoverFactChecker
{
    public const MAX_CLAIMS_VERIFICAR = 6;
    public const PCT_MIN_VERIFICADOS = 70; // %

    /**
     * @param string $html        HTML do post
     * @param array  $textosFontes array de strings (textos das fontes scrapeadas)
     * @param object $openai       cliente OpenAI (precisa method `chat($system, $user, $maxTokens)`)
     * @return array {claims_total, claims_verificados, ..., aprovado, detalhes}
     */
    public static function verificar(string $html, array $textosFontes, $openai): array
    {
        // 1. Extrai claims candidatos
        $claims = self::extrairClaims($html);
        if (empty($claims)) {
            return [
                'claims_total'        => 0,
                'claims_verificados'  => 0,
                'claims_unverified'   => 0,
                'claims_contradicted' => 0,
                'aprovado'            => true,
                'detalhes'            => [],
                'nota'                => 'sem claims factuais detectáveis (post pode ser opinativo)',
            ];
        }

        $claimsTrunc = array_slice($claims, 0, self::MAX_CLAIMS_VERIFICAR);

        // 2. Concat fontes (truncado pra caber na janela de contexto barata)
        $fontesTexto = '';
        foreach ($textosFontes as $t) {
            $fontesTexto .= "\n--- FONTE ---\n" . mb_substr((string)$t, 0, 2500);
        }
        if ($fontesTexto === '') {
            return [
                'claims_total'   => count($claimsTrunc),
                'aprovado'       => true,
                'nota'           => 'sem fontes pra verificar (skip)',
            ];
        }

        // 3. Pede ao GPT-mini classificar cada claim
        $system = 'Você é um fact-checker editorial. Recebe CLAIMS (frases factuais) e FONTES (textos originais). '
                . 'Pra cada claim, classifica:'
                . "\n- VERIFICADO: o fato está LITERALMENTE nas fontes (mesmo que parafraseado)"
                . "\n- UNVERIFIED: a fonte NÃO contém o fato (não diz nem o contrário, simplesmente está ausente)"
                . "\n- CONTRADICTED: a fonte diz o CONTRÁRIO do claim (ex: claim diz '2 mil vagas', fonte diz '500 vagas')"
                . "\n\nResponda APENAS um JSON: {\"claims\":[{\"i\":N,\"status\":\"VERIFICADO|UNVERIFIED|CONTRADICTED\",\"motivo\":\"breve\"}]}";

        $user = "FONTES:\n" . mb_substr($fontesTexto, 0, 8000) . "\n\nCLAIMS pra verificar:\n";
        foreach ($claimsTrunc as $i => $c) {
            $user .= ($i + 1) . ". " . mb_substr($c, 0, 250) . "\n";
        }

        try {
            $resp = $openai->chat($system, $user, 800);
            $json = self::extrairJson($resp);
        } catch (Throwable $e) {
            return [
                'claims_total' => count($claimsTrunc),
                'aprovado'     => true,
                'erro'         => 'fact-check falhou: ' . $e->getMessage(),
                'nota'         => 'falha na verificação — não bloqueia post (fail-open)',
            ];
        }

        $resultados = is_array($json['claims'] ?? null) ? $json['claims'] : [];
        $verificados = $unverified = $contradicted = 0;
        $detalhes = [];

        foreach ($resultados as $r) {
            $i = (int)($r['i'] ?? 0) - 1;
            $status = strtoupper((string)($r['status'] ?? ''));
            $motivo = (string)($r['motivo'] ?? '');
            $claim = $claimsTrunc[$i] ?? '';
            switch ($status) {
                case 'VERIFICADO': $verificados++; break;
                case 'UNVERIFIED': $unverified++;
                    $detalhes[] = ['claim' => mb_substr($claim, 0, 150), 'status' => 'UNVERIFIED', 'motivo' => $motivo];
                    break;
                case 'CONTRADICTED': $contradicted++;
                    $detalhes[] = ['claim' => mb_substr($claim, 0, 150), 'status' => 'CONTRADICTED', 'motivo' => $motivo];
                    break;
            }
        }

        $total = count($claimsTrunc);
        $pctVerificados = $total > 0 ? round(100 * $verificados / $total, 1) : 0;

        // Decisão: aprovado se ≥70% verificados E zero contradictions
        $aprovado = ($pctVerificados >= self::PCT_MIN_VERIFICADOS) && ($contradicted === 0);

        return [
            'claims_total'        => $total,
            'claims_verificados'  => $verificados,
            'claims_unverified'   => $unverified,
            'claims_contradicted' => $contradicted,
            'pct_verificados'     => $pctVerificados,
            'aprovado'            => $aprovado,
            'motivo'              => !$aprovado ? ($contradicted > 0 ? 'contradicted' : 'pct_verificados_baixo') : 'ok',
            'detalhes'            => $detalhes,
        ];
    }

    /**
     * Extrai frases que parecem CLAIMS factuais:
     *  - Tem número, data, ou entidade nomeada
     *  - Tem verbo concreto (não opinião)
     *
     * Filtra: trust blocks, citations, FAQ schemas, listas/tabelas (são facts já marcados).
     */
    private static function extrairClaims(string $html): array
    {
        // Texto puro (remove tags + scripts + styles). Adiciona espaço entre tags
        // pra strip_tags não colar palavras de tags adjacentes (`</h1><p>` → "Título O").
        $texto = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
        $texto = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $texto);
        $texto = preg_replace('/></', '> <', $texto); // separa tags adjacentes
        $texto = strip_tags($texto);
        $texto = html_entity_decode($texto, ENT_QUOTES, 'UTF-8');
        $texto = preg_replace('/\s+/u', ' ', $texto);

        // Split em sentenças. `[.!?]+` seguido de espaço OU letra maiúscula direta
        // (cobre "esperados.O" que strip_tags pode produzir).
        $sentencas = preg_split('/[.!?]+(?:\s+|(?=[A-ZÁÉÍÓÚÂÊÔÃÕ]))/u', (string)$texto) ?: [];

        $claims = [];
        foreach ($sentencas as $s) {
            $s = trim($s);
            if (mb_strlen($s) < 30 || mb_strlen($s) > 400) continue;

            // Sinal: tem NÚMERO ou DATA ou R$ ou nome próprio CAPS
            $temNumero = (bool)preg_match('/\b\d+\b/', $s);
            $temData   = (bool)preg_match('/\b(202[0-9]|janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro|segunda|terça|quarta|quinta|sexta|sábado|domingo)\b/iu', $s);
            $temEntidade = (bool)preg_match('/\b([A-ZÁÉÍÓÚÂÊÔÃÕ]{3,}|[A-ZÁÉÍÓÚÂÊÔÃÕ][a-záéíóúâêôãõç]{2,}(?:\s+[A-ZÁÉÍÓÚÂÊÔÃÕa-záéíóúâêôãõç]+){0,2})\b/u', $s);
            $temReais = (bool)preg_match('/R\$\s*\d/', $s);

            $score = (int)$temNumero + (int)$temData + (int)$temEntidade + (int)$temReais;
            if ($score < 2) continue; // precisa pelo menos 2 sinais

            // Não-opinião (filtra "achamos que", "talvez", "parece")
            if (preg_match('/\b(acho|talvez|provavelmente|parece|deve\s+(ser|ter)|pode\s+(ser|ter))\b/iu', $s)) continue;

            $claims[] = $s;
        }
        return $claims;
    }

    private static function extrairJson(string $texto): array
    {
        if (preg_match('/\{[\s\S]*\}/', $texto, $m)) {
            $j = json_decode($m[0], true);
            if (is_array($j)) return $j;
        }
        return [];
    }
}
