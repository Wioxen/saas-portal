<?php
/**
 * Ângulo editorial por trend — Etapa 4 do portal.md.
 *
 * Mapeia categoria (Trends) + intenção (calculada) → briefing editorial:
 *   - grupo editorial (PRODUTO/NOTÍCIA/ESPORTES/...)
 *   - ângulo principal (1 da lista do portal.md daquele grupo)
 *   - ângulo universal (promessa vs realidade / detalhe oculto)
 *   - gancho do P1
 *   - estrutura H3 sugerida
 *   - palavras-chave
 *   - FAQ base
 *
 * É só PROPOSTA estruturada — o redator (Claude) refina na geração.
 */
require_once __DIR__ . '/TrendsTaxonomia.php';

class DiscoverAngulo
{
    // grupoPorCluster e grupoPorCategoriaId vivem em TrendsTaxonomia agora.
    // Acesso: TrendsTaxonomia::grupoEditorial($clusterKey)
    //         TrendsTaxonomia::grupoPorCategoriaGoogle($catId)

    /** Ângulos principais por grupo (portal.md seção ETAPA 4). */
    private static array $angulosPorGrupo = [
        'PRODUTO'        => ['custo-benefício', 'vale a pena', 'barato vs caro', 'erro do consumidor'],
        'TECNOLOGIA'     => ['promessa vs realidade', 'hype vs entrega', 'novidade', 'comparação'],
        'NOTÍCIA'        => ['o que mudou', 'impacto', 'consequência', 'reação'],
        'ENTRETENIMENTO' => ['surpresa', 'bastidores', 'reação do público', 'polêmica leve'],
        'ESPORTES'       => ['resultado', 'impacto', 'virada', 'expectativa vs realidade'],
        'EDUCAÇÃO'       => ['oportunidade', 'urgência', 'como participar', 'erro comum'],
        'FINANÇAS'       => ['ganho/perda', 'oportunidade', 'risco', 'decisão'],
        'GERAL'          => ['detalhe oculto', 'impacto'],
    ];

    // angulosUniversais → TrendsTaxonomia::ANGULOS_UNIVERSAIS

    /** Intenção → prioriza certo ângulo do grupo. */
    private static array $angulosPorIntencao = [
        'ao-vivo'       => ['onde assistir', 'urgência', 'o que está em jogo'],
        'resultado'     => ['resultado', 'virada', 'reação'],
        'tutorial'      => ['como participar', 'passo a passo', 'erro comum'],
        'transacional'  => ['custo-benefício', 'vale a pena', 'erro do consumidor'],
        'comparativo'   => ['barato vs caro', 'comparação'],
        'informacional' => ['o que mudou', 'detalhe oculto'],
    ];

    /** Gera o briefing completo de um trend. */
    public static function gerarBriefing(array $t): array
    {
        $grupo    = self::detectarGrupo($t);
        $intencao = (string)($t['intencao'] ?? 'geral');
        $anguloPrincipal  = self::escolherAngulo($grupo, $intencao);
        $anguloUniversal  = TrendsTaxonomia::ANGULOS_UNIVERSAIS[($t['volume_num'] ?? 0) % 2];
        $gancho   = self::montarGancho($t, $intencao);
        $h3s      = self::sugerirH3($t, $intencao);
        $keywords = self::extrairKeywords($t);
        $faq      = self::sugerirFaq($t);
        $titulo   = self::sugerirTitulo($t, $anguloPrincipal, $intencao);

        return [
            'grupo_editorial'  => $grupo,
            'angulo_principal' => $anguloPrincipal,
            'angulo_universal' => $anguloUniversal,
            'gancho_p1'        => $gancho,
            'titulo_sugerido'  => $titulo,
            'h3_sugeridos'     => $h3s,
            'palavras_chave'   => $keywords,
            'faq_sugerido'     => $faq,
            'intencao'         => $intencao,
        ];
    }

    private static function detectarGrupo(array $t): string
    {
        // Fonte primária: DiscoverClusterMatcher (keyword + relacionados + categoria_ids).
        // Google mislabeliza categoria_ids — por isso o matcher é necessário.
        require_once __DIR__ . '/DiscoverClusterMatcher.php';
        $c = DiscoverClusterMatcher::detectar([
            'termo'         => (string)($t['termo'] ?? ''),
            'categoria_ids' => $t['categoria_ids'] ?? [],
            'categorias'    => $t['categorias'] ?? [],
            'relacionados'  => $t['relacionados'] ?? [],
        ]);
        return TrendsTaxonomia::grupoEditorial($c['key'] ?? 'curiosidades_geral');
    }

    private static function escolherAngulo(string $grupo, string $intencao): string
    {
        $angulosGrupo = self::$angulosPorGrupo[$grupo] ?? self::$angulosPorGrupo['GERAL'];
        $preferidosIntencao = self::$angulosPorIntencao[$intencao] ?? [];

        // Se há sobreposição entre ângulos do grupo e preferidos da intenção → usa essa
        foreach ($preferidosIntencao as $pref) {
            if (in_array($pref, $angulosGrupo, true)) return $pref;
        }
        // Fallback: se intenção tem preferido forte e grupo é GERAL, usa o da intenção mesmo
        if ($grupo === 'GERAL' && !empty($preferidosIntencao)) return $preferidosIntencao[0];
        // Fallback: primeiro ângulo do grupo
        return $angulosGrupo[0];
    }

    private static function montarGancho(array $t, string $intencao): string
    {
        $termo = (string)($t['termo'] ?? '');
        $vol   = (string)($t['volume_label'] ?? '');
        $grow  = (int)($t['growth_pct'] ?? 0);

        return match ($intencao) {
            'ao-vivo'       => "Buscas por onde assistir {$termo} saltaram " . ($grow ? "+{$grow}% " : '') . "nas últimas horas — {$vol} já pesquisaram.",
            'resultado'     => "O que aconteceu em {$termo} levou {$vol} pessoas ao Google em poucas horas.",
            'tutorial'      => "{$vol} já pesquisam como participar de {$termo} — e a maioria comete o mesmo erro.",
            'transacional'  => "{$termo} entrou no radar de {$vol} buscas e já apareceu " . ($grow ? "com +{$grow}% de crescimento" : 'com alta forte') . ".",
            'comparativo'   => "{$termo}: {$vol} pessoas querem saber qual opção vale mais a pena.",
            'informacional' => "{$termo} explodiu em buscas (" . ($grow ? "+{$grow}%" : 'alta súbita') . ") e poucos sabem do que se trata.",
            default         => "{$termo} teve " . ($grow ? "+{$grow}% de crescimento" : 'alta') . " em buscas, alcançando {$vol} pessoas.",
        };
    }

    private static function sugerirTitulo(array $t, string $angulo, string $intencao): string
    {
        $termo = (string)($t['termo'] ?? '');
        $vol   = (string)($t['volume_label'] ?? '');

        return match ($intencao) {
            'ao-vivo'       => "{$termo}: onde assistir, horário e escalação (atualizado)",
            'resultado'     => "{$termo}: o que aconteceu e por que {$vol} estão buscando",
            'tutorial'      => "Como participar de {$termo}: passo a passo sem erro",
            'transacional'  => "{$termo} vale a pena? Comparativo completo antes de comprar",
            'comparativo'   => "{$termo}: qual opção compensa mais agora",
            'informacional' => "{$termo}: o que é e por que está em alta agora",
            default         => "{$termo}: o que saber agora sobre o assunto em alta",
        };
    }

    private static function sugerirH3(array $t, string $intencao): array
    {
        $termo = (string)($t['termo'] ?? '');
        $relacionados = array_slice($t['relacionados'] ?? [], 0, 3);

        $base = match ($intencao) {
            'ao-vivo'       => ['Onde assistir', 'Que horas começa', 'Escalação provável', 'O que está em jogo'],
            'resultado'     => ['O que aconteceu', 'O lance decisivo', 'O que muda depois disso', 'Reação nas redes'],
            'tutorial'      => ['Quem pode participar', 'Passo a passo da inscrição', 'Documentos necessários', 'Erro comum que elimina'],
            'transacional'  => ['Preço médio hoje', 'Vale a pena agora?', 'Alternativas mais baratas', 'O que verificar antes de comprar'],
            'comparativo'   => ['Diferenças técnicas', 'Quem se beneficia de cada opção', 'Preço x benefício', 'Veredito'],
            'informacional' => ['O que é e como funciona', 'Por que está em alta agora', 'Quem é afetado', 'Próximos passos'],
            default         => ['O que é', 'Por que está em alta', 'O que muda para você', 'Próximos passos'],
        };

        // Adiciona H3s derivados de queries relacionadas reais
        foreach ($relacionados as $r) {
            if (mb_strlen($r) > 3 && mb_strlen($r) < 60) {
                $base[] = ucfirst($r);
            }
        }
        return array_slice(array_unique($base), 0, 6);
    }

    private static function extrairKeywords(array $t): array
    {
        $termo = (string)($t['termo'] ?? '');
        $rel   = array_slice($t['relacionados'] ?? [], 0, 8);
        $kws = array_merge([$termo], $rel);
        $kws = array_map(fn($k) => mb_strtolower(trim((string)$k), 'UTF-8'), $kws);
        $kws = array_values(array_unique(array_filter($kws, fn($k) => $k !== '' && mb_strlen($k) > 2)));
        return array_slice($kws, 0, 8);
    }

    private static function sugerirFaq(array $t): array
    {
        $termo = (string)($t['termo'] ?? '');
        $relacionados = $t['relacionados'] ?? [];

        $faq = [];
        foreach ($relacionados as $r) {
            if (preg_match('/^(o que|como|quando|onde|quem|por que|qual|quanto|que horas)\b/iu', $r)) {
                $faq[] = ucfirst($r) . '?';
            }
            if (count($faq) >= 5) break;
        }

        if (empty($faq)) {
            $faq = [
                "O que é {$termo}?",
                "Por que {$termo} está em alta agora?",
                "Como isso afeta quem acompanha o tema?",
            ];
        }
        return $faq;
    }
}
