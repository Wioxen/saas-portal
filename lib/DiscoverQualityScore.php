<?php
/**
 * Score de qualidade Discover — avalia o HTML gerado contra o checklist de 10 pontos.
 *
 * Retorna:
 *  - score final (0-10, média ponderada)
 *  - breakdown por critério
 *  - sugestões de melhoria por critério que falhou
 *
 * Uso:
 *   $r = DiscoverQualityScore::avaliar($titulo, $contentHtml);
 *   // $r['score'] = 8.5, $r['detalhes'] = [...], $r['melhorias'] = [...]
 */
class DiscoverQualityScore
{
    /** Pesos por critério (soma = 10). */
    private static array $pesos = [
        'titulo_especificidade' => 1.2,
        'titulo_segmentacao'    => 1.2,
        'titulo_atualidade'     => 0.6,
        'intro_3segundos'       => 1.4,
        'estrutura_tldr'        => 1.0,
        'estrutura_pilulas'     => 1.0,
        'estrutura_subtitulos'  => 0.8,
        'quebra_elementos'      => 1.0,
        'cta_final'             => 1.0,
        'dados_destacados'      => 0.8,
    ];

    public static function avaliar(string $titulo, string $html): array
    {
        $det = [];
        $det['titulo_especificidade'] = self::avaliarTituloEspecificidade($titulo);
        $det['titulo_segmentacao']    = self::avaliarTituloSegmentacao($titulo);
        $det['titulo_atualidade']     = self::avaliarTituloAtualidade($titulo);
        $det['intro_3segundos']       = self::avaliarIntro3s($html);
        $det['estrutura_tldr']        = self::avaliarTldr($html);
        $det['estrutura_pilulas']     = self::avaliarPilulas($html);
        $det['estrutura_subtitulos']  = self::avaliarSubtitulos($html);
        $det['quebra_elementos']      = self::avaliarElementosQuebra($html);
        $det['cta_final']             = self::avaliarCtaFinal($html);
        $det['dados_destacados']      = self::avaliarDadosDestacados($html);

        // Score ponderado
        $soma = 0.0; $pesoTotal = 0.0;
        foreach ($det as $k => $d) {
            $p = self::$pesos[$k] ?? 1.0;
            $soma     += ($d['nota'] ?? 0) * $p;
            $pesoTotal += $p;
        }
        $score = $pesoTotal > 0 ? round($soma / $pesoTotal, 2) : 0.0;

        // Lista de melhorias sugeridas (só dos que falharam)
        $melhorias = [];
        foreach ($det as $k => $d) {
            if (empty($d['ok']) && !empty($d['sugestao'])) {
                $melhorias[] = ['criterio' => $k, 'sugestao' => $d['sugestao']];
            }
        }

        return [
            'score'     => $score,
            'status'    => $score >= 8.5 ? 'excelente' : ($score >= 7 ? 'bom' : ($score >= 5 ? 'medio' : 'fraco')),
            'detalhes'  => $det,
            'melhorias' => $melhorias,
        ];
    }

    // ═══ CRITÉRIOS ═══

    private static function avaliarTituloEspecificidade(string $t): array
    {
        $temNumero = (bool)preg_match('/\d/', $t);
        $temValor  = (bool)preg_match('/R\$\s*[\d.,]+|\$\s*\d+/i', $t);
        $temData   = (bool)preg_match('/\b(janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\b|\b\d{1,2}[\/\-]\d{1,2}\b/i', $t);
        $ok = $temNumero || $temValor || $temData;
        return [
            'nota'     => $ok ? 10 : 3,
            'ok'       => $ok,
            'sugestao' => $ok ? null : 'Adicione número, valor (R$ ...) ou data específica no título (ex: "R$ 1.200", "24 de abril", "3 regras").',
        ];
    }

    private static function avaliarTituloSegmentacao(string $t): array
    {
        // Padrões que indicam público-alvo
        $patterns = [
            '/\b(nascidos?|aposentados?|trabalhadores?|fam[íi]lias|alunos?|pais|m[ãa]es|benefici[áa]rios?|idosos?|jovens|estudantes?|CLT|MEI|MCMV|INSS|autistas?|microempreendedores?)\b/ui',
            '/\bpara\s+(quem|voc[êe]|todos?|os|as|pessoas|as?\s+)/ui',
            '/\bquem\s+(tem|ganha|trabalha|nasceu|recebe|se inscreveu|participa)\b/ui',
            '/\b(grupo|faixa|categoria|turma)\s*\d/ui',
            '/\bm[ãa]es?\s+solteir/ui',
            '/\b(homens|mulheres)\s+(acima|com|de)\b/ui',
        ];
        $temSeg = false;
        foreach ($patterns as $p) {
            if (preg_match($p, $t)) { $temSeg = true; break; }
        }
        return [
            'nota'     => $temSeg ? 10 : 4,
            'ok'       => $temSeg,
            'sugestao' => $temSeg ? null : 'Adicione público-alvo no título (ex: "Trabalhadores CLT", "Famílias com renda até R$ 218", "Nascidos em 1958", "Aposentados do INSS").',
        ];
    }

    private static function avaliarTituloAtualidade(string $t): array
    {
        $temAno   = (bool)preg_match('/\b(202[5-9]|203[0-9])\b/', $t);
        $sazonal  = (bool)preg_match('/\b(natal|p[áa]scoa|carnaval|enem|imposto|black\s+friday|dia\s+das\s+m[ãa]es|dia\s+dos\s+pais|dia\s+do\s+trabalhador|independ[êe]ncia|finados|ano\s+novo|r[ée]veillon|bolsa\s+fam[íi]lia|mcmv|13[°º]?|minha\s+casa|f[ée]rias|vestibular|sisu|prouni|fies|tiradentes)\b/iu', $t);

        if (!$sazonal) {
            // Não aplicável — pontua cheio (critério opcional)
            return ['nota' => 10, 'ok' => true, 'sugestao' => null];
        }
        return [
            'nota'     => $temAno ? 10 : 5,
            'ok'       => $temAno,
            'sugestao' => $temAno ? null : 'Tema sazonal detectado sem ano no título — adicione "2026" pra dar frescor (ex: "Dia do Trabalhador 2026").',
        ];
    }

    private static function avaliarIntro3s(string $html): array
    {
        if (!preg_match('/<p[^>]*>([\s\S]*?)<\/p>/i', $html, $m)) {
            return ['nota' => 0, 'ok' => false, 'sugestao' => 'Artigo sem primeiro <p>. Adicione um lead magnético.'];
        }
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($m[1])));
        $chars = mb_strlen($plain, 'UTF-8');
        // Ideal: 80-320 chars
        if ($chars < 50) {
            return ['nota' => 4, 'ok' => false, 'sugestao' => "1º parágrafo tem {$chars} chars — muito curto. Mínimo 50 chars com fato + benefício."];
        }
        if ($chars > 350) {
            return ['nota' => 5, 'ok' => false, 'sugestao' => "1º parágrafo tem {$chars} chars — muito longo. Máximo 320 chars pra passar na regra dos 3 segundos."];
        }
        return ['nota' => 10, 'ok' => true, 'sugestao' => null];
    }

    private static function avaliarTldr(string $html): array
    {
        $temTldr = strpos($html, 'bloco-resumo') !== false;
        return [
            'nota'     => $temTldr ? 10 : 3,
            'ok'       => $temTldr,
            'sugestao' => $temTldr ? null : 'Falta o <ul class="bloco-resumo"> logo após o 1º parágrafo — é o que o Discover puxa pro card.',
        ];
    }

    private static function avaliarPilulas(string $html): array
    {
        preg_match_all('/<p[^>]*>([\s\S]*?)<\/p>/i', $html, $m);
        $maxLen = 0;
        foreach ($m[1] as $p) {
            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($p)));
            $maxLen = max($maxLen, mb_strlen($plain, 'UTF-8'));
        }
        if ($maxLen === 0) return ['nota' => 0, 'ok' => false, 'sugestao' => 'Sem parágrafos detectados.'];
        if ($maxLen > 600) {
            return ['nota' => 4, 'ok' => false, 'sugestao' => "Maior parágrafo tem {$maxLen} chars — quebre em blocos menores (ideal ≤ 400)."];
        }
        if ($maxLen > 450) {
            return ['nota' => 7, 'ok' => false, 'sugestao' => "Maior parágrafo tem {$maxLen} chars — pode quebrar mais (ideal ≤ 400)."];
        }
        return ['nota' => 10, 'ok' => true, 'sugestao' => null];
    }

    private static function avaliarSubtitulos(string $html): array
    {
        $plain = strip_tags($html);
        $palavras = str_word_count($plain, 0, 'áéíóúâêôãõçÁÉÍÓÚÂÊÔÃÕÇ');
        preg_match_all('/<h[23][^>]*>/i', $html, $m);
        $subtitulos = count($m[0]);
        if ($subtitulos === 0) {
            return ['nota' => 2, 'ok' => false, 'sugestao' => 'Nenhum H2/H3. Adicione subtítulos a cada 2-3 parágrafos.'];
        }
        $palPorSubt = $subtitulos > 0 ? ($palavras / $subtitulos) : 9999;
        if ($palPorSubt > 500) {
            return ['nota' => 5, 'ok' => false, 'sugestao' => "H2/H3 esparsos (" . round($palPorSubt) . " palavras por subtítulo). Adicione mais pra facilitar escaneabilidade."];
        }
        return ['nota' => 10, 'ok' => true, 'sugestao' => null];
    }

    private static function avaliarElementosQuebra(string $html): array
    {
        $temLista      = (bool)preg_match('/<ul[^>]*>|<ol[^>]*>/i', $html);
        $temTabela     = (bool)preg_match('/<table[^>]*>/i', $html);
        $temBlockquote = (bool)preg_match('/<blockquote[^>]*>/i', $html);
        $tipos = (int)$temLista + (int)$temTabela + (int)$temBlockquote;

        $faltando = [];
        if (!$temLista)      $faltando[] = 'lista (<ul>/<ol>)';
        if (!$temTabela)     $faltando[] = 'tabela (<table>)';
        if (!$temBlockquote) $faltando[] = 'box de destaque (<blockquote>)';

        if ($tipos >= 3) return ['nota' => 10, 'ok' => true, 'sugestao' => null];
        if ($tipos === 2) return ['nota' => 8,  'ok' => true, 'sugestao' => 'Adicione também: ' . implode(', ', $faltando) . '.'];
        if ($tipos === 1) return ['nota' => 5,  'ok' => false, 'sugestao' => 'Só 1 tipo de quebra visual. Adicione: ' . implode(', ', $faltando) . '.'];
        return ['nota' => 1, 'ok' => false, 'sugestao' => 'Sem quebras visuais. Adicione lista, tabela e blockquote pra retenção.'];
    }

    private static function avaliarCtaFinal(string $html): array
    {
        $plain = strip_tags($html);
        // Pega últimos 800 chars do texto (normalmente cobre os 2-3 últimos parágrafos)
        $ultimo = mb_substr($plain, -800, null, 'UTF-8');
        $patterns = [
            '/compartilh/iu',
            '/\bmanda\b/iu',
            '/\bpassa\b.{0,30}(?:link|post|adiante|pro|pra)/iu',
            '/envie\s+(?:para|pra)/iu',
            '/marque\s+algu[ée]m/iu',
            '/\brepasse\b/iu',
            '/quem\s+precisa\s+(?:saber|ver|ler)/iu',
            '/grupo\s+(?:do|da|de)\s+whats/iu',
            '/\bsalva\b.{0,15}(?:post|conte[úu]do|favorit)/iu',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $ultimo)) {
                return ['nota' => 10, 'ok' => true, 'sugestao' => null];
            }
        }
        return [
            'nota'     => 3,
            'ok'       => false,
            'sugestao' => 'CTA final de compartilhamento ausente. Termine com frase tipo "Manda pra quem precisa saber disso".',
        ];
    }

    private static function avaliarDadosDestacados(string $html): array
    {
        preg_match_all('/<strong[^>]*>([\s\S]*?)<\/strong>/i', $html, $m);
        $n = count($m[0]);
        if ($n >= 5) return ['nota' => 10, 'ok' => true, 'sugestao' => null];
        if ($n >= 3) return ['nota' => 8,  'ok' => true, 'sugestao' => null];
        if ($n >= 1) return ['nota' => 5,  'ok' => false, 'sugestao' => "Só {$n} <strong>. Destaque mais dados críticos (valores, datas, nomes de programas)."];
        return ['nota' => 2, 'ok' => false, 'sugestao' => 'Nenhum <strong>. Destaque os dados decisivos (valor, data, regra).'];
    }

    /** Labels amigáveis por critério (pra UI). */
    public static function labels(): array
    {
        return [
            'titulo_especificidade' => 'Título: número/valor/data',
            'titulo_segmentacao'    => 'Título: público-alvo',
            'titulo_atualidade'     => 'Título: ano/mês atual',
            'intro_3segundos'       => 'Intro: regra dos 3s',
            'estrutura_tldr'        => 'Bloco-resumo (TL;DR)',
            'estrutura_pilulas'     => 'Parágrafos em pílula',
            'estrutura_subtitulos'  => 'H2/H3 frequentes',
            'quebra_elementos'      => 'Lista + tabela + box',
            'cta_final'             => 'CTA de compartilhamento',
            'dados_destacados'      => 'Dados em <strong>',
        ];
    }
}
