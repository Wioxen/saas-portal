<?php
declare(strict_types=1);

/**
 * OutboundAuthorityChecker — análise informativa de links autoritativos no artigo.
 *
 * NÃO é gate — só ANALISA e devolve info. A decisão de inserir link é editorial:
 * só insere se HÁ CONTEXTO (entidade citada com dado factual). Esse checker apenas
 * reporta lacunas pra revisor humano avaliar.
 *
 * Uso típico:
 *   $r = OutboundAuthorityChecker::analisar($html);
 *   $validationReport['outbound_authority'] = $r;
 *
 * Filosofia: links autoritativos reforçam E-E-A-T (Trust) quando bem colocados.
 * Forçar links onde não há contexto = anchor text artificial, contraproducente.
 */
class OutboundAuthorityChecker
{
    /** Mapeamento entidade → domínios oficiais aceitos. */
    private const MAPA = [
        'MEC' => ['mec.gov.br', 'gov.br/mec'],
        'Inep' => ['inep.gov.br', 'gov.br/inep'],
        'INSS' => ['inss.gov.br', 'meu.inss.gov.br', 'gov.br/inss'],
        'Caixa' => ['caixa.gov.br'],
        'Senac' => ['senac.br'],
        'Senai' => ['senai.br'],
        'Sebrae' => ['sebrae.com.br'],
        'Fies' => ['mec.gov.br', 'gov.br/mec', 'sisfiesweb.mec.gov.br'],
        'ProUni' => ['mec.gov.br', 'prouniportal.mec.gov.br'],
        'Sisu' => ['mec.gov.br', 'sisu.mec.gov.br'],
        'Enem' => ['inep.gov.br', 'gov.br/inep'],
        'Encceja' => ['gov.br/inep'],
        'Pé-de-Meia' => ['mec.gov.br', 'gov.br/mec'],
        'Bolsa Família' => ['gov.br', 'caixa.gov.br'],
        'CadÚnico' => ['gov.br', 'cadunico.gov.br'],
        'CNH' => ['gov.br', 'denatran.gov.br'],
        'CPF' => ['gov.br', 'receita.fazenda.gov.br'],
        'Receita Federal' => ['gov.br', 'receita.fazenda.gov.br'],
        'Detran' => ['gov.br'],
        'STJD' => ['stjd.org.br'],
        'CBF' => ['cbf.com.br'],
        'IFood' => ['ifood.com.br'],
        'Sedu' => ['sedu.es.gov.br'],
        'UTFPR' => ['utfpr.edu.br'],
        'UFRJ' => ['ufrj.br'],
        'USP' => ['usp.br'],
        'Unicamp' => ['unicamp.br'],
    ];

    /**
     * @return array {
     *   ok: bool,                         // true se ZERO entidades sem link OU artigo sem entidades
     *   total_entidades_mencionadas: int,
     *   entidades_com_link: int,
     *   entidades_sem_link: int,
     *   sugestoes: array — [{entidade, mencoes, dominio_oficial_sugerido}]
     * }
     */
    public static function analisar(string $html): array
    {
        $clean = preg_replace('~<(script|style|code)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;
        $text = strip_tags(html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // Coleta hosts dos links externos no HTML
        $hostsExternos = [];
        if (preg_match_all('/<a\s+[^>]*href=[\'"]https?:\/\/([^\/\'"]+)/i', $clean, $mm)) {
            foreach ($mm[1] as $h) $hostsExternos[mb_strtolower($h)] = true;
        }

        $entidadesMencionadas = 0;
        $entidadesComLink = 0;
        $sugestoes = [];

        foreach (self::MAPA as $entidade => $dominios) {
            // Match palavra inteira (case insensitive em PT-BR comum, MAS preserva caixa)
            $pattern = '/(?<![\w])' . preg_quote($entidade, '/') . '(?![\w])/iu';
            $count = preg_match_all($pattern, $text);
            if ($count < 2) continue; // exige 2+ menções pra contar como "entidade central"

            $entidadesMencionadas++;
            $temLink = false;
            foreach ($dominios as $d) {
                foreach ($hostsExternos as $h => $_) {
                    if (str_ends_with($h, $d) || str_contains($h, $d)) {
                        $temLink = true;
                        break 2;
                    }
                }
            }
            if ($temLink) {
                $entidadesComLink++;
            } else {
                $sugestoes[] = [
                    'entidade' => $entidade,
                    'mencoes' => $count,
                    'dominio_sugerido' => $dominios[0],
                ];
            }
        }

        return [
            'ok' => empty($sugestoes),
            'total_entidades_mencionadas' => $entidadesMencionadas,
            'entidades_com_link' => $entidadesComLink,
            'entidades_sem_link' => count($sugestoes),
            'sugestoes' => $sugestoes,
        ];
    }

    public static function reportToLogLine(array $r): string
    {
        $tot = (int)($r['total_entidades_mencionadas'] ?? 0);
        $com = (int)($r['entidades_com_link'] ?? 0);
        $sem = (int)($r['entidades_sem_link'] ?? 0);
        $top = '';
        if ($sem > 0 && !empty($r['sugestoes'][0]['entidade'])) {
            $top = ' (top: ' . $r['sugestoes'][0]['entidade'] . ' x' . $r['sugestoes'][0]['mencoes'] . ')';
        }
        return "Outbound: {$com}/{$tot} entidades têm link; {$sem} sem{$top}";
    }
}
