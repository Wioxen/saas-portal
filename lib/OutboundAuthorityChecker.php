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
        // Órgãos federais
        'MEC' => ['mec.gov.br', 'gov.br/mec'],
        'Inep' => ['inep.gov.br', 'gov.br/inep'],
        'INSS' => ['inss.gov.br', 'meu.inss.gov.br', 'gov.br/inss'],
        'Caixa' => ['caixa.gov.br'],
        'Receita Federal' => ['gov.br', 'receita.fazenda.gov.br'],
        'CapES' => ['gov.br/capes', 'capes.gov.br'],
        'Capes' => ['gov.br/capes', 'capes.gov.br'],
        'CNPq' => ['gov.br/cnpq', 'cnpq.br'],
        'Detran' => ['gov.br'],
        // Sistema S
        'Senac' => ['senac.br'],
        'Senai' => ['senai.br'],
        'Sebrae' => ['sebrae.com.br'],
        'Sesi' => ['sesi.org.br'],
        'Sesc' => ['sesc.com.br'],
        // Programas educacionais
        'Fies' => ['mec.gov.br', 'gov.br/mec', 'sisfiesweb.mec.gov.br'],
        'ProUni' => ['mec.gov.br', 'prouniportal.mec.gov.br'],
        'Prouni' => ['mec.gov.br', 'prouniportal.mec.gov.br'],
        'Sisu' => ['mec.gov.br', 'sisu.mec.gov.br'],
        'Enem' => ['inep.gov.br', 'gov.br/inep'],
        'ENEM' => ['inep.gov.br', 'gov.br/inep'],
        'Encceja' => ['gov.br/inep'],
        'ENCCEJA' => ['gov.br/inep'],
        'Enade' => ['inep.gov.br', 'gov.br/inep'],
        'ENADE' => ['inep.gov.br', 'gov.br/inep'],
        'Pé-de-Meia' => ['mec.gov.br', 'gov.br/mec'],
        'Bolsa Família' => ['gov.br', 'caixa.gov.br'],
        'CadÚnico' => ['gov.br', 'cadunico.gov.br'],
        'CNH' => ['gov.br', 'denatran.gov.br'],
        'CPF' => ['gov.br', 'receita.fazenda.gov.br'],
        'Pronatec' => ['mec.gov.br', 'gov.br/mec'],
        // Institutos Federais (todos .edu.br próprio)
        'IFAC' => ['ifac.edu.br'],
        'IFAL' => ['ifal.edu.br'],
        'IFAM' => ['ifam.edu.br'],
        'IFAP' => ['ifap.edu.br'],
        'IFB' => ['ifb.edu.br'],
        'IFBA' => ['ifba.edu.br'],
        'IFC' => ['ifc.edu.br'],
        'IFCE' => ['ifce.edu.br'],
        'IFES' => ['ifes.edu.br'],
        'IFF' => ['iff.edu.br'],
        'IFFar' => ['iffarroupilha.edu.br'],
        'IFG' => ['ifg.edu.br'],
        'IFMA' => ['ifma.edu.br'],
        'IFMG' => ['ifmg.edu.br'],
        'IFMT' => ['ifmt.edu.br'],
        'IFNMG' => ['ifnmg.edu.br'],
        'IFPA' => ['ifpa.edu.br'],
        'IFPB' => ['ifpb.edu.br'],
        'IFPE' => ['ifpe.edu.br'],
        'IFPI' => ['ifpi.edu.br'],
        'IFPR' => ['ifpr.edu.br'],
        'IFRJ' => ['ifrj.edu.br'],
        'IFRN' => ['ifrn.edu.br'],
        'IFRO' => ['ifro.edu.br'],
        'IFRR' => ['ifrr.edu.br'],
        'IFRS' => ['ifrs.edu.br'],
        'IFS' => ['ifs.edu.br'],
        'IFSC' => ['ifsc.edu.br'],
        'IFSP' => ['ifsp.edu.br'],
        'IFSudesteMG' => ['ifsudestemg.edu.br'],
        'IFSul' => ['ifsul.edu.br'],
        'IFSULDEMINAS' => ['ifsuldeminas.edu.br'],
        'IFSuldeminas' => ['ifsuldeminas.edu.br'],
        'IFTM' => ['iftm.edu.br'],
        'IFTO' => ['ifto.edu.br'],
        'CEFET' => ['cefet-rj.br', 'cefetmg.br'],
        'CEFET-RJ' => ['cefet-rj.br'],
        'CEFET-MG' => ['cefetmg.br'],
        // Universidades federais principais
        'UFRJ' => ['ufrj.br'],
        'UFMG' => ['ufmg.br'],
        'UFBA' => ['ufba.br'],
        'UFPE' => ['ufpe.br'],
        'UFRGS' => ['ufrgs.br'],
        'UFSC' => ['ufsc.br'],
        'UFPR' => ['ufpr.br'],
        'UFC' => ['ufc.br'],
        'UFG' => ['ufg.br'],
        'UFES' => ['ufes.br'],
        'UFF' => ['uff.br'],
        'UFPB' => ['ufpb.br'],
        'UFRPE' => ['ufrpe.br'],
        'UnB' => ['unb.br'],
        'UTFPR' => ['utfpr.edu.br'],
        'UFSCar' => ['ufscar.br'],
        // Universidades estaduais SP
        'USP' => ['usp.br'],
        'Unicamp' => ['unicamp.br'],
        'Unesp' => ['unesp.br'],
        // Outras estaduais
        'UERJ' => ['uerj.br'],
        'UEL' => ['uel.br'],
        'UEM' => ['uem.br'],
        'UEPG' => ['uepg.br'],
        'UEPB' => ['uepb.edu.br'],
        // Secretarias estaduais educação (algumas comuns)
        'Sedu' => ['sedu.es.gov.br'],
        'Seduc' => ['seduc.gov.br'],
        // Esportes/lifestyle
        'STJD' => ['stjd.org.br'],
        'CBF' => ['cbf.com.br'],
        'IFood' => ['ifood.com.br'],
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

    /**
     * Insere AUTO-LINK na 1ª menção de cada entidade sugerida (dentro de <p>).
     * Limita a $maxLinks links por post (evita poluição).
     *
     * @param string $html
     * @param array  $sugestoes  array de {entidade, dominio_sugerido, mencoes} (do analisar())
     * @param int    $maxLinks   default 2 (links demais reduz CTR + parece SEO spammy)
     * @return array {html, aplicados: array<string>}
     */
    public static function injetar(string $html, array $sugestoes, int $maxLinks = 2): array
    {
        $aplicados = [];
        // Ordena por menções desc — entidades mais citadas ganham link primeiro
        usort($sugestoes, fn($a, $b) => (int)($b['mencoes'] ?? 0) <=> (int)($a['mencoes'] ?? 0));

        foreach ($sugestoes as $s) {
            if (count($aplicados) >= $maxLinks) break;
            $entidade = (string)($s['entidade'] ?? '');
            if ($entidade === '' || strlen($entidade) < 2) continue;
            $url = 'https://www.' . (string)($s['dominio_sugerido'] ?? '');

            // Pattern: entidade dentro de <p>...</p>, fora de <a>...</a>
            $pattern = '/(<p\b[^>]*>)((?:(?!<\/p>).)*?)(?<![\w])(' . preg_quote($entidade, '/') . ')(?![\w])/iu';
            $count = 0;
            $html = preg_replace_callback($pattern, function ($m) use ($url, &$count) {
                if ($count >= 1) return $m[0];
                // Skip se já está dentro de <a> aberto em $m[2]
                $abre = substr_count(mb_strtolower($m[2]), '<a ');
                $fecha = substr_count(mb_strtolower($m[2]), '</a>');
                if ($abre > $fecha) return $m[0];
                // Skip se há sibling link pra mesma URL no mesmo paragráfo (já tem)
                if (stripos($m[2], $url) !== false) return $m[0];
                $count++;
                return $m[1] . $m[2] . '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener">' . $m[3] . '</a>';
            }, $html, 1) ?? $html;

            if ($count > 0) $aplicados[] = $entidade;
        }

        return ['html' => $html, 'aplicados' => $aplicados];
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
