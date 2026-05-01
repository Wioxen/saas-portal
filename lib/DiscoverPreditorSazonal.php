<?php
/**
 * DiscoverPreditorSazonal — antecipa picos sazonais (Black Friday, IR, ENEM, etc).
 *
 * Discover premia conteúdo publicado **30-60min ANTES** do pico de busca. Hoje reagimos
 * ao trend; aqui antecipamos: a cada manhã, olhamos eventos previstos pra **3-7 dias à
 * frente** e enchemos fila com termos pré-aprovados PRA CADA SITE relevante.
 *
 * Fluxo:
 *   1. DiscoverCalendario::proximos(7) → eventos com status='acionavel' ou 'aproximando'
 *   2. Pra cada evento, expande em N termos sazonais (pré-definidos)
 *   3. Roteia pro site cujo cluster bate (sites.persona.clusters_foco)
 *   4. Score boost progressivo: dias até pico ≤3 → +5.0; 4-7 → +3.0; 8-14 → +1.5
 *   5. Cria trend `status='aprovado'` (pula pingo) com `origem='sazonal_preditivo:{nome}'`
 *   6. Idempotente — evita duplicar (verifica por origem + termo no DB)
 *
 * Resultado: 2-3 dias antes do Dia das Mães, fila tem 8-12 posts em produção. Quando o
 * pico chega, já tem conteúdo INDEXADO ranqueando — Discover detecta autoridade.
 */

require_once __DIR__ . '/DiscoverCalendario.php';
require_once __DIR__ . '/DiscoverDb.php';

class DiscoverPreditorSazonal
{
    /** Janela em dias pra olhar à frente. */
    public const JANELA_DIAS_DEFAULT = 7;

    /** Score boost por proximidade do pico (mais perto = score maior, prioridade Sonnet). */
    public const BOOST_SCORE = [
        ['max_dias' => 3,  'score' => 15.0],  // pico iminente — vai pro Sonnet AGORA
        ['max_dias' => 7,  'score' => 12.0],
        ['max_dias' => 14, 'score' => 9.0],
        ['max_dias' => 30, 'score' => 6.5],
    ];

    /**
     * Templates de termo por evento. Cada evento gera múltiplos termos
     * (ângulos diferentes pra cobrir intenções de busca).
     */
    /**
     * Mapeia evento (nome do DiscoverCalendario) → cluster_key do nosso sistema.
     * Eventos não mapeados são pulados.
     */
    public const EVENTO_PARA_CLUSTER = [
        'Dia das Mães'             => 'lifestyle_consumo',
        'Dia dos Pais'             => 'lifestyle_consumo',
        'Black Friday'             => 'lifestyle_consumo',
        'Cyber Monday'             => 'lifestyle_consumo',
        'Natal'                    => 'lifestyle_consumo',
        'Dia das Crianças'         => 'lifestyle_consumo',
        'Dia dos Namorados'        => 'lifestyle_consumo',
        'Dia do Consumidor'        => 'lifestyle_consumo',
        'Páscoa'                   => 'comidas_bebidas',
        'Carnaval'                 => 'entretenimento_cultura',
        'Independência do Brasil'  => 'noticias_info_critica',
        'Finados'                  => 'noticias_info_critica',
        'Dia do Trabalhador'       => 'noticias_info_critica',
        'Ano Novo'                 => 'lifestyle_consumo',
        'Dia Internacional da Mulher' => 'lifestyle_consumo',
        'ENEM'                     => 'noticias_info_critica',
        'IR'                       => 'negocios_financas',
        'Volta às aulas'           => 'lifestyle_consumo',
        'FGTS'                     => 'negocios_financas',
        'Bolsa Família'            => 'noticias_info_critica',
        'INSS'                     => 'noticias_info_critica',
    ];

    public const TEMPLATES_TERMO = [
        'Dia das Mães'         => ['presentes para o dia das mães {ano}', 'o que comprar para mãe no dia das mães {ano}', 'mensagem para o dia das mães {ano}', 'promoções dia das mães {ano}'],
        'Dia dos Pais'         => ['presentes para o dia dos pais {ano}', 'mensagem para o dia dos pais {ano}', 'promoções dia dos pais {ano}'],
        'Black Friday'         => ['black friday {ano} ofertas', 'melhores descontos black friday {ano}', 'cupons black friday {ano}', 'eletrônicos black friday {ano}'],
        'Cyber Monday'         => ['cyber monday {ano} ofertas', 'descontos cyber monday {ano}'],
        'Natal'                => ['presente de natal {ano}', 'amigo secreto até R$ 50 natal {ano}', 'ceia de natal {ano} barata'],
        'Páscoa'               => ['ovos de páscoa {ano} promoção', 'almoço de páscoa {ano} receita', 'mensagem de páscoa {ano}'],
        'Carnaval'             => ['fantasias carnaval {ano}', 'blocos de carnaval {ano}', 'feriado carnaval {ano}'],
        'ENEM'                 => ['inscrição ENEM {ano}', 'isenção ENEM {ano}', 'cronograma ENEM {ano}', 'simulado ENEM {ano}'],
        'IR'                   => ['declaração IR {ano} prazo', 'como declarar IR {ano}', 'restituição IR {ano}'],
        'Volta às aulas'       => ['lista de material escolar {ano}', 'mochila volta às aulas {ano}', 'uniforme escolar {ano}'],
        'Dia do Trabalhador'   => ['feriado dia do trabalhador {ano}', 'direitos do trabalhador {ano}'],
        'Dia das Crianças'     => ['presentes dia das crianças {ano}', 'brinquedos dia das crianças {ano}'],
        'Dia dos Namorados'    => ['presentes dia dos namorados {ano}', 'mensagem dia dos namorados {ano}'],
        'Independência do Brasil' => ['feriado 7 de setembro {ano}', 'desfile 7 de setembro {ano}'],
        'Finados'              => ['feriado finados {ano}', 'velas finados {ano}'],
        'FGTS'                 => ['saque-aniversário FGTS {ano}', 'calendário FGTS {ano}'],
        'Bolsa Família'        => ['calendário Bolsa Família {ano}', 'pagamento Bolsa Família {ano}'],
        'INSS'                 => ['calendário INSS {ano}', 'aposentadoria INSS {ano}'],
    ];

    private DiscoverDb $db;

    public function __construct(?DiscoverDb $db = null)
    {
        $this->db = $db ?? new DiscoverDb();
    }

    /**
     * Roda 1 ciclo: detecta eventos próximos, expande em termos, roteia pra sites,
     * cria trends pré-aprovadas no DB.
     *
     * @param array $sites    sites.php carregado (pra roteamento por cluster)
     * @param int $janelaDias quantos dias olhar à frente
     * @param bool $dryRun    não persiste — só lista o que faria
     * @return array {eventos, termos_criados, sites_atingidos, ja_existiam, detalhes}
     */
    public function rodar(array $sites, int $janelaDias = self::JANELA_DIAS_DEFAULT, bool $dryRun = false): array
    {
        $eventos = DiscoverCalendario::proximos($janelaDias);
        if (empty($eventos)) {
            return ['eventos' => 0, 'termos_criados' => 0, 'sites_atingidos' => [], 'ja_existiam' => 0, 'detalhes' => []];
        }

        $publicados = $this->db->all();
        $origensExistentes = [];
        foreach ($publicados as $p) {
            $origem = (string)($p['origem'] ?? '');
            if (strpos($origem, 'sazonal_preditivo:') !== 0) continue;
            // chave dedup: site|origem|termo
            $chave = ($p['site'] ?? '') . '|' . $origem . '|' . mb_strtolower((string)($p['termo'] ?? ''), 'UTF-8');
            $origensExistentes[$chave] = true;
        }

        $criados = 0;
        $jaExistiam = 0;
        $sitesAtingidos = [];
        $detalhes = [];

        foreach ($eventos as $ev) {
            $nome      = (string)$ev['nome'];
            $diasAte   = (int)$ev['dias_ate'];
            // 'cluster' no Calendario é lista de TÍTULOS sugeridos (não cluster_key).
            // Mapear via EVENTO_PARA_CLUSTER. Eventos não mapeados → pular.
            $cluster   = self::EVENTO_PARA_CLUSTER[$nome] ?? '';
            if ($cluster === '') {
                $detalhes[] = ['evento' => $nome, 'pulado' => 'sem mapeamento cluster_key'];
                continue;
            }
            $ano       = (int)substr((string)$ev['data_pico'], 0, 4);
            $score     = self::scorePorProximidade($diasAte);

            // Templates de termo pro evento
            $templates = self::TEMPLATES_TERMO[$nome] ?? ["{$nome} {ano}"];
            $termos = array_map(fn($t) => str_replace('{ano}', (string)$ano, $t), $templates);

            // Roteia pra sites com cluster matching
            $sitesAlvo = self::sitesParaCluster($cluster, $sites);
            if (empty($sitesAlvo)) {
                $detalhes[] = ['evento' => $nome, 'pulado' => "nenhum site cobre cluster '{$cluster}'"];
                continue;
            }

            foreach ($sitesAlvo as $siteSlug) {
                foreach ($termos as $termo) {
                    $chave = $siteSlug . '|sazonal_preditivo:' . $nome . '|' . mb_strtolower($termo, 'UTF-8');
                    if (isset($origensExistentes[$chave])) {
                        $jaExistiam++;
                        continue;
                    }

                    if ($dryRun) {
                        $detalhes[] = [
                            'evento' => $nome, 'site' => $siteSlug, 'termo' => $termo,
                            'score' => $score, 'dias_ate_pico' => $diasAte,
                        ];
                        $criados++;
                        $sitesAtingidos[$siteSlug] = ($sitesAtingidos[$siteSlug] ?? 0) + 1;
                        continue;
                    }

                    try {
                        $this->db->upsert([
                            'site'           => $siteSlug,
                            'termo'          => $termo,
                            'status'         => 'aprovado',
                            'score_discover' => $score,
                            'origem'         => 'sazonal_preditivo:' . $nome,
                            'categoria'      => 'Sazonal · ' . $nome,
                            'cluster_detect' => ['key' => $cluster, 'nome' => $cluster, 'score' => 5],
                            'data_detectada' => date('Y-m-d H:i:s'),
                            'intencao'       => 'sazonal',
                            'angulo'         => "Antecipação {$diasAte}d antes de {$nome} (pico {$ev['data_pico']})",
                            'pingo_link'     => '',
                            'evento_fonte'   => $nome,
                            'data_pico'      => $ev['data_pico'],
                        ]);
                        $criados++;
                        $sitesAtingidos[$siteSlug] = ($sitesAtingidos[$siteSlug] ?? 0) + 1;
                    } catch (Throwable $e) {
                        $detalhes[] = ['evento' => $nome, 'site' => $siteSlug, 'termo' => $termo, 'erro' => $e->getMessage()];
                    }
                }
            }
        }

        return [
            'eventos'         => count($eventos),
            'termos_criados'  => $criados,
            'sites_atingidos' => $sitesAtingidos,
            'ja_existiam'     => $jaExistiam,
            'detalhes'        => $dryRun ? $detalhes : [],
        ];
    }

    /** Score boost por proximidade. */
    public static function scorePorProximidade(int $diasAtePico): float
    {
        foreach (self::BOOST_SCORE as $faixa) {
            if ($diasAtePico <= $faixa['max_dias']) return (float)$faixa['score'];
        }
        return 5.0;
    }

    /**
     * Roteia evento pra sites baseado em cluster_foco da persona.
     * Sites cujo `persona.clusters_foco` contém o cluster do evento são candidatos.
     */
    private static function sitesParaCluster(string $cluster, array $sites): array
    {
        $alvos = [];
        foreach ($sites as $slug => $cfg) {
            $clustersFoco = $cfg['persona']['clusters_foco'] ?? [];
            if (!is_array($clustersFoco)) continue;
            if (in_array($cluster, $clustersFoco, true)) $alvos[] = (string)$slug;
        }
        return $alvos;
    }
}
