<?php
declare(strict_types=1);

/**
 * SourceTrustScore — score de confiabilidade jornalística por fonte.
 *
 * Pipeline editorial frequentemente recebe fontes com QUALIDADES diferentes
 * (oficial vs jornal profissional vs fan-site). Quando há divergência factual
 * entre fontes (ex: 1 diz canal X, outra diz Y), precisa hierarquizar.
 *
 * Caso real #772/#776 leaodabarra (2026-05-02):
 *   - Vitória x Coritiba (Brasileirão Série A)
 *   - MeuVitória (fan-site) reportou: "Onde assistir: TV Aratu"
 *   - Revista Placar (imprensa profissional) reportou: Premiere
 *   - Pipeline pegou ambos e Claude escreveu "TV Aratu E Premiere"
 *   - REALIDADE: Premiere é o canal padrão pra Brasileirão; MeuVitória estava errado
 *
 * Solução: tier por domínio + função de resolução de conflitos.
 *
 * TIERS:
 *   S (10) — Fonte oficial (clube, federação, órgão governamental esportivo)
 *   A (8)  — Imprensa profissional grande (Globo, UOL, ESPN, GE, Placar, Folha, Lance)
 *   B (6)  — Imprensa regional/profissional média (Bahia Notícias, Correio 24h, Itatiaia, BNews)
 *   C (4)  — Fan-sites e blogs especializados (MeuVitória, Arena Rubro-Negra, fogaonet)
 *   D (2)  — Outros sites (genéricos, agregadores)
 *
 * Uso:
 *   $score = SourceTrustScore::scoreUrl('https://placar.com.br/...');  // 8
 *   $tier = SourceTrustScore::tierUrl('https://meuvitoria.com.br/...'); // 'C'
 *   $vencedor = SourceTrustScore::resolverConflito([
 *       ['valor' => 'TV Aratu', 'fonte' => 'meuvitoria.com.br'],
 *       ['valor' => 'Premiere', 'fonte' => 'placar.com.br'],
 *   ]); // ['valor' => 'Premiere', 'tier' => 'A']
 */
class SourceTrustScore
{
    /**
     * Mapa domínio → tier. Match por substring (host contém o key).
     * Ordem importa: matches mais específicos PRIMEIRO.
     */
    private const TIER_S_OFICIAL = [
        // Federações / órgãos
        'cbf.com.br', 'fbf.org.br', 'stjd.org.br', 'conmebol.com', 'fifa.com',
        'uefa.com', 'fia.com', 'formula1.com', 'olympics.com', 'cob.org.br',
        'fivb.com', 'cbv.com.br', 'cbb.com.br', 'fiba.com', 'nba.com', 'ufc.com',
        // Clubes oficiais brasileiros
        'ecvitoria.com.br', 'ecbahia.com', 'flamengo.com.br', 'palmeiras.com.br',
        'corinthians.com.br', 'saopaulofc.net', 'atletico.com.br', 'cruzeiro.com.br',
        'cam.com.br', 'gremio.net', 'internacional.com.br', 'fluminense.com.br',
        'botafogo.com.br', 'vasco.com.br', 'sportrecife.com.br', 'fortalezaec.net',
        'cearasc.com', 'bahia.com.br', 'remo.com.br', 'paysandu.com.br',
        // Governamental
        'gov.br', 'edu.br', 'jus.br',
    ];

    private const TIER_A_GRANDES = [
        // Imprensa esportiva nacional grande
        'globo.com', 'globoplay.globo.com', 'ge.globo.com', 'sportv.globo.com',
        'globoesporte.globo.com', 'espn.com.br', 'espn.com', 'uol.com.br/esporte',
        'placar.com.br', 'placar.abril.com.br', 'lance.com.br', 'lancenet.com.br',
        'gazetaesportiva.com', 'folha.uol.com.br', 'folha.com.br',
        // Generalistas com cobertura esportiva forte
        'oglobo.globo.com', 'estadao.com.br', 'r7.com', 'band.uol.com.br',
        'cnnbrasil.com.br', 'metropoles.com', 'veja.abril.com.br',
        // Estatística / dados
        'sofascore.com', 'transfermarkt.com.br', 'transfermarkt.com',
    ];

    private const TIER_B_REGIONAIS = [
        // Bahia (importante pro leaodabarra)
        'bahianoticias.com.br', 'bnews.com.br', 'correio24horas.com.br',
        'metro1.com.br', 'aratuon.com.br', 'tribunadabahia.com.br',
        'alo.alo.com.br', 'alemdofuteboL.com.br',
        // Outras regionais profissionais
        'itatiaia.com.br', 'em.com.br', 'gauchazh.clicrbs.com.br',
        'gazetadopovo.com.br', 'agazeta.com.br', 'correiobraziliense.com.br',
        'jornaldebrasilia.com.br', 'diariodepernambuco.com.br',
        'noataque.com.br', 'umdoisesportes.com.br', 'fogonet.net',
    ];

    private const TIER_C_FAN_SITES = [
        // Vitória
        'meuvitoria.com.br', 'arenarubronegra.com', 'leaodabarra.com.br',
        // Outros clubes (referência)
        'esportenet.uol.com.br', 'flamengoinforma.com.br', 'palmeirasonline.com.br',
        'corinthianspedia.com', 'tricolor.com.br', 'fanaticospelobotafogo.com.br',
        // Blogs gerais sobre futebol BR
        'trivela.com.br', 'futebolinterior.com.br', 'maisfutebol.com',
    ];

    /**
     * Tier numérico (10/8/6/4/2). Quanto maior, mais confiável.
     */
    public static function scoreUrl(string $url): int
    {
        return self::tierToScore(self::tierUrl($url));
    }

    /**
     * Tier letra (S/A/B/C/D). 'D' = não classificado.
     */
    public static function tierUrl(string $url): string
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') return 'D';

        foreach (self::TIER_S_OFICIAL as $dom) {
            if (self::hostBate($host, $dom)) return 'S';
        }
        foreach (self::TIER_A_GRANDES as $dom) {
            if (self::hostBate($host, $dom)) return 'A';
        }
        foreach (self::TIER_B_REGIONAIS as $dom) {
            if (self::hostBate($host, $dom)) return 'B';
        }
        foreach (self::TIER_C_FAN_SITES as $dom) {
            if (self::hostBate($host, $dom)) return 'C';
        }
        return 'D';
    }

    private static function hostBate(string $host, string $dominio): bool
    {
        // Match por substring no host: dominio "globo.com" bate em "ge.globo.com"
        return str_contains($host, $dominio);
    }

    public static function tierToScore(string $tier): int
    {
        return match ($tier) {
            'S' => 10,
            'A' => 8,
            'B' => 6,
            'C' => 4,
            default => 2,
        };
    }

    /**
     * Ordena lista de fontes (cada uma com 'url') por score decrescente.
     * Use antes de scrape pra priorizar fontes confiáveis.
     */
    public static function ordenarPorTier(array $fontes): array
    {
        usort($fontes, function($a, $b) {
            $sa = self::scoreUrl((string)($a['url'] ?? ''));
            $sb = self::scoreUrl((string)($b['url'] ?? ''));
            return $sb <=> $sa;
        });
        return $fontes;
    }

    /**
     * Resolve conflito entre fatos divergentes citados por fontes diferentes.
     *
     * Input: [
     *   ['valor' => 'TV Aratu',  'fonte' => 'https://meuvitoria.com.br/...'],
     *   ['valor' => 'Premiere',  'fonte' => 'https://placar.com.br/...'],
     * ]
     *
     * Output: ['valor' => 'Premiere', 'tier' => 'A', 'score' => 8]
     *
     * Regra: ganha o de MAIOR tier. Empate → ganha primeiro da lista.
     * Se DIFERENÇA de tier ≥ 2 (S vs B, A vs D), descarta o de menor.
     * Se DIFERENÇA de tier ≤ 1 (A vs B), MARCA como divergência (caller decide).
     */
    public static function resolverConflito(array $candidatos): array
    {
        if (empty($candidatos)) return ['valor' => null, 'tier' => 'D', 'score' => 0];

        $rankeados = [];
        foreach ($candidatos as $c) {
            $url = (string)($c['fonte'] ?? '');
            $tier = self::tierUrl($url);
            $score = self::tierToScore($tier);
            $rankeados[] = [
                'valor' => $c['valor'] ?? null,
                'fonte' => $url,
                'tier'  => $tier,
                'score' => $score,
            ];
        }
        usort($rankeados, fn($a, $b) => $b['score'] <=> $a['score']);

        $top = $rankeados[0];

        // Se tem outros candidatos com score próximo (diff ≤ 1), marca divergência
        $top['divergencia'] = false;
        foreach (array_slice($rankeados, 1) as $r) {
            if ($r['valor'] !== $top['valor'] && abs($r['score'] - $top['score']) <= 1) {
                $top['divergencia'] = true;
                $top['alternativas'] = array_slice($rankeados, 1);
                break;
            }
        }
        return $top;
    }

    /**
     * Lista todas as fontes com seu tier — debug/log.
     */
    public static function diagnostico(array $urls): array
    {
        $out = [];
        foreach ($urls as $u) {
            $tier = self::tierUrl($u);
            $out[] = [
                'url'   => $u,
                'tier'  => $tier,
                'score' => self::tierToScore($tier),
                'host'  => parse_url($u, PHP_URL_HOST),
            ];
        }
        return $out;
    }
}
