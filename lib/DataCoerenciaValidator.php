<?php
/**
 * DataCoerenciaValidator — verifica se datas do título batem com as do corpo e da fonte.
 *
 * Compliance crítico: Google Discover penaliza preview desalinhado (título promete X, conteúdo entrega Y).
 * Se o título menciona uma data que não aparece no corpo nem na fonte → divergência → retry forçado.
 *
 * Também computa contagem regressiva: quantos dias faltam pro prazo mais próximo detectado.
 */
class DataCoerenciaValidator
{
    const MESES = [
        'janeiro'   => 1,
        'fevereiro' => 2,
        'marco'     => 3, 'março' => 3,
        'abril'     => 4,
        'maio'      => 5,
        'junho'     => 6,
        'julho'     => 7,
        'agosto'    => 8,
        'setembro'  => 9,
        'outubro'   => 10,
        'novembro'  => 11,
        'dezembro'  => 12,
    ];

    /**
     * Extrai todas as datas identificáveis do texto. Retorna array de 'YYYY-MM-DD'.
     * Cobre: "29 de abril", "1º de junho", "29 de abril de 2026", "29/04", "29/04/2026".
     */
    public function extrairDatas(string $texto): array
    {
        $datas = [];
        $baixo = mb_strtolower($texto, 'UTF-8');
        $anoAtual = (int)date('Y');

        // Padrão 1: "29 de abril" / "1º de junho" / "29 de abril de 2026"
        $pattern1 = '/\b(\d{1,2})\s*(?:º|o|\.)?\s*de\s+(janeiro|fevereiro|marco|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)(?:\s+de\s+(\d{4}))?\b/u';
        if (preg_match_all($pattern1, $baixo, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $dia = (int)$match[1];
                $mesNome = $match[2];
                $ano = isset($match[3]) && $match[3] !== '' ? (int)$match[3] : $anoAtual;
                $mes = self::MESES[$mesNome] ?? null;
                if (!$mes || $dia < 1 || $dia > 31) continue;
                if (!checkdate($mes, $dia, $ano)) continue;
                $datas[] = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
            }
        }

        // Padrão 2: "29/04" / "29/04/2026" / "29/04/26"
        $pattern2 = '/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/';
        if (preg_match_all($pattern2, $baixo, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $dia = (int)$match[1];
                $mes = (int)$match[2];
                $anoRaw = isset($match[3]) && $match[3] !== '' ? (int)$match[3] : $anoAtual;
                $ano = $anoRaw < 100 ? 2000 + $anoRaw : $anoRaw;
                if ($dia < 1 || $dia > 31 || $mes < 1 || $mes > 12 || $ano < 2020 || $ano > 2099) continue;
                if (!checkdate($mes, $dia, $ano)) continue;
                $datas[] = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
            }
        }

        return array_values(array_unique($datas));
    }

    /**
     * Valida coerência entre título, corpo e fonte.
     *
     * @return array {
     *   coerente: bool,
     *   datas_titulo: string[],
     *   datas_corpo: string[],
     *   datas_fonte: string[],
     *   divergencias: string[],   // datas do título que não aparecem em corpo nem fonte
     *   proxima_data: string|null, // 'YYYY-MM-DD' da data futura mais próxima detectada
     *   dias_restantes: int|null,
     *   resumo: string             // mensagem pra log
     * }
     */
    public function verificar(string $titulo, string $corpoHtml, string $fonteTexto = ''): array
    {
        $dTitulo = $this->extrairDatas($titulo);
        // Corpo: só primeiros 5 parágrafos (onde ficam as datas-âncora — P1/P2/P3/resposta/snippet)
        $primeirosP = $this->extrairPrimeirosP($corpoHtml, 5);
        $dCorpo = $this->extrairDatas(strip_tags($primeirosP));
        $dFonte = $this->extrairDatas($fonteTexto);

        // Divergências: data no título que não aparece NO CORPO nem NA FONTE
        $divergencias = [];
        foreach ($dTitulo as $dt) {
            if (!in_array($dt, $dCorpo, true) && !in_array($dt, $dFonte, true)) {
                $divergencias[] = $dt;
            }
        }

        // Próxima data futura (ou hoje) detectada em título+corpo
        $hoje = date('Y-m-d');
        $proxima = null;
        foreach (array_merge($dTitulo, $dCorpo) as $d) {
            if ($d >= $hoje) {
                if ($proxima === null || $d < $proxima) $proxima = $d;
            }
        }
        $diasRestantes = null;
        if ($proxima !== null) {
            try {
                $d1 = new DateTime($hoje);
                $d2 = new DateTime($proxima);
                $diasRestantes = (int)$d1->diff($d2)->format('%a');
            } catch (Throwable $e) { $diasRestantes = null; }
        }

        // Valida marcadores temporais ("hoje", "amanhã", "esta noite") contra dias_restantes real
        $temporalViolacoes = $this->verificarMarcadoresTemporais($titulo, $primeirosP, $diasRestantes);

        $coerenteDatas = empty($divergencias);
        $coerenteTemporal = empty($temporalViolacoes);
        $coerente = $coerenteDatas && $coerenteTemporal;

        $resumo = $coerente
            ? "coerente (tit=" . count($dTitulo) . ",corpo=" . count($dCorpo) . ",fonte=" . count($dFonte) . ($diasRestantes !== null ? ",faltam={$diasRestantes}d" : '') . ")"
            : (!$coerenteDatas
                ? "DIVERGÊNCIA DATA: tit=[" . implode(',', $dTitulo) . "] não casa com corpo=[" . implode(',', $dCorpo) . "] nem fonte=[" . implode(',', array_slice($dFonte, 0, 5)) . "]"
                : "URGÊNCIA INVENTADA: " . implode('; ', $temporalViolacoes));

        return [
            'coerente'              => $coerente,
            'datas_titulo'          => $dTitulo,
            'datas_corpo'           => $dCorpo,
            'datas_fonte'           => $dFonte,
            'divergencias'          => $divergencias,
            'proxima_data'          => $proxima,
            'dias_restantes'        => $diasRestantes,
            'temporal_violacoes'    => $temporalViolacoes,
            'resumo'                => $resumo,
        ];
    }

    /**
     * Valida marcadores temporais ("hoje", "amanhã", "esta noite") contra a contagem regressiva real.
     * Se Claude usou "hoje" mas dias_restantes > 0 → urgência inventada → retry forçado.
     *
     * @param string   $titulo
     * @param string   $corpoPs     HTML dos primeiros parágrafos (já extraídos)
     * @param int|null $diasRestantes
     * @return array Lista de violações (vazia se OK)
     */
    public function verificarMarcadoresTemporais(string $titulo, string $corpoPs, ?int $diasRestantes): array
    {
        // Se não conseguiu detectar prazo na fonte/corpo, não valida (sem baseline)
        if ($diasRestantes === null) return [];

        $texto = mb_strtolower($titulo . ' ' . strip_tags($corpoPs), 'UTF-8');
        // Remove acentos pra casar variações ("amanhã", "amanha")
        $textoNorm = strtr($texto, [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c'
        ]);
        $violacoes = [];

        // Marcadores de HOJE — só válidos se dias_restantes == 0
        $marcHoje = ['hoje a noite', 'hoje mesmo', 'ainda hoje', 'esta noite', 'agora a noite', 'nas proximas horas', 'ate o fim do dia', 'hoje'];
        if ($diasRestantes > 0) {
            foreach ($marcHoje as $m) {
                // Regex com word boundary pra evitar falso positivo ("estahoje" etc)
                $pat = '/\b' . preg_quote($m, '/') . '\b/u';
                if (preg_match($pat, $textoNorm)) {
                    $violacoes[] = "marcador \"{$m}\" encontrado, mas prazo está a {$diasRestantes} dia(s) — não é hoje";
                    break; // 1 violação já basta, evita ruído
                }
            }
        }

        // Marcadores de AMANHÃ — só válidos se dias_restantes == 1
        $marcAmanha = ['amanha a noite', 'amanha', 'nas proximas 24 horas'];
        if ($diasRestantes !== 1) {
            foreach ($marcAmanha as $m) {
                $pat = '/\b' . preg_quote($m, '/') . '\b/u';
                if (preg_match($pat, $textoNorm)) {
                    $violacoes[] = "marcador \"{$m}\" encontrado, mas prazo está a {$diasRestantes} dia(s) — não é amanhã";
                    break;
                }
            }
        }

        return $violacoes;
    }

    private function extrairPrimeirosP(string $html, int $max = 5): string
    {
        preg_match_all('#<p[^>]*>(.*?)</p>#is', $html, $m);
        $ps = $m[1] ?? [];
        return implode(' ', array_slice($ps, 0, $max));
    }
}
