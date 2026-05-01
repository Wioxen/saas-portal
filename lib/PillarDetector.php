<?php
/**
 * PillarDetector — detecta tópico umbrella do cluster e procura pillar existente no WP.
 *
 * Topical Authority: estratégia SEO onde 1 post pillar (guia abrangente) é referenciado
 * por N posts do cluster (subtemas específicos). Google interpreta como "site é fonte de
 * autoridade no tópico". Aqui detectamos automaticamente quando um cluster pode se beneficiar:
 *
 * 1. Haiku analisa títulos do cluster → retorna tópico umbrella ("Bolsa Família", "Pé-de-Meia",
 *    "concursos federais") ou null se cluster heterogêneo.
 * 2. Se tem tópico umbrella, busca no WP via Wordpress::buscarRelacionados() e filtra
 *    candidatos a pillar por padrões de título: "guia", "tudo sobre", "completo", "definitivo",
 *    "passo a passo", "como funciona o".
 * 3. Retorna [topico, pillar | null]. Pillar tem id, title, link.
 *
 * Falha silenciosa: sem chave Anthropic OU sem WP credentials → retorna null (cluster
 * segue sem pillar linking, fluxo intacto).
 */
require_once __DIR__ . '/Claude.php';
require_once __DIR__ . '/Wordpress.php';

class PillarDetector
{
    private string $apiKey;
    private Wordpress $wp;
    private string $model;

    /** Padrões em títulos que sinalizam post pillar (vs post de notícia/atualização). */
    const PILLAR_PATTERNS = [
        'guia completo', 'guia definitivo', 'guia prático',
        'tudo sobre', 'tudo o que',
        'completo de', 'completo do', 'completo da',
        'definitivo de', 'definitivo do',
        'como funciona o', 'como funciona a',
        'manual de', 'manual do', 'manual da',
        'enciclopédia', 'tudo que voce precisa', 'tudo que você precisa',
    ];

    public function __construct(string $apiKey, Wordpress $wp, string $model = 'claude-haiku-4-5')
    {
        $this->apiKey = $apiKey;
        $this->wp = $wp;
        $this->model = $model;
    }

    /**
     * Analisa items + busca pillar.
     *
     * @param array $items Lista de items com 'title' e opcional 'description'
     * @return array ['topico' => string|null, 'pillar' => array|null]
     *   pillar = ['id', 'title', 'link', 'similarity', 'pillar_score']
     */
    public function detectar(array $items): array
    {
        if (count($items) < 2) {
            return ['topico' => null, 'pillar' => null];
        }

        // 1. Detecta tópico umbrella via Haiku
        $topico = null;
        try {
            $topico = $this->detectarTopicoViaHaiku($items);
        } catch (Throwable $e) {
            // Fallback: tenta extrair via heurística (palavra mais frequente nos títulos)
            $topico = $this->detectarTopicoHeuristica($items);
        }
        if (empty($topico)) {
            return ['topico' => null, 'pillar' => null];
        }

        // 2. Busca pillar existente
        $pillar = $this->buscarPillar($topico);

        return ['topico' => $topico, 'pillar' => $pillar];
    }

    private function detectarTopicoViaHaiku(array $items): ?string
    {
        $lista = '';
        foreach ($items as $i => $it) {
            $lista .= "[" . ($i + 1) . "] " . mb_substr((string)($it['title'] ?? ''), 0, 140) . "\n";
        }

        $system = 'Você analisa clusters de manchetes pra detectar tópico umbrella (assunto comum).'
            . ' Responda APENAS com o tópico em 1-4 palavras (PT-BR, sem aspas, sem markdown), OU "null" se cluster heterogêneo.'
            . ' Exemplos: "Bolsa Família", "Pé-de-Meia 2026", "concursos federais", "INSS aposentadoria".'
            . ' Mínimo 60% das manchetes precisam compartilhar o tópico.';

        $user = "Manchetes do cluster:\n{$lista}\nTópico umbrella (1-4 palavras OU \"null\"):";

        $claude = new Claude($this->apiKey, $this->model);
        $resp = $claude->callPublic(
            [['role' => 'user', 'content' => $user]],
            $system,
            100
        );
        $texto = trim((string)($resp['content'][0]['text'] ?? ''));
        // Limpa markdown/aspas/pontuação
        $texto = trim($texto, " \t\n\r\x00\x0B\"'.");
        if ($texto === '' || mb_strtolower($texto) === 'null' || mb_strlen($texto) > 60) {
            return null;
        }
        return $texto;
    }

    private function detectarTopicoHeuristica(array $items): ?string
    {
        // Conta palavras significativas (≥4 chars, não-stopword) entre todos os títulos
        $stopwords = ['para','com','sobre','este','esta','isso','essa','esse','aqui','ali','mais','menos','muito','pouco','bem','mal','sem','seu','sua','seus','suas','meu','minha','dia','ano','pelo','pela','pelos','pelas'];
        $contagem = [];
        foreach ($items as $it) {
            $t = mb_strtolower((string)($it['title'] ?? ''));
            $t = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $t);
            $palavras = preg_split('/\s+/', $t, -1, PREG_SPLIT_NO_EMPTY);
            $vistas = [];
            foreach ($palavras as $p) {
                if (mb_strlen($p) < 4 || in_array($p, $stopwords, true)) continue;
                if (isset($vistas[$p])) continue; // 1× por título (não inflar)
                $vistas[$p] = true;
                $contagem[$p] = ($contagem[$p] ?? 0) + 1;
            }
        }
        if (empty($contagem)) return null;
        arsort($contagem);
        $top = array_key_first($contagem);
        // Precisa aparecer em ≥60% dos itens pra ser tópico umbrella
        if ($contagem[$top] < ceil(count($items) * 0.6)) return null;
        return $top;
    }

    private function buscarPillar(string $topico): ?array
    {
        try {
            $candidatos = $this->wp->buscarRelacionados($topico, 8);
        } catch (Throwable $e) {
            return null;
        }
        if (empty($candidatos)) return null;

        $melhor = null;
        $melhorScore = 0.0;
        foreach ($candidatos as $c) {
            $titulo = (string)($c['title'] ?? '');
            if ($titulo === '') continue;
            $tituloNorm = mb_strtolower($titulo);

            // Score base: similar_text com tópico
            similar_text(mb_strtolower($topico), $tituloNorm, $simBase);

            // Bônus PILLAR: padrões de título característicos de guia abrangente
            $bonusPillar = 0.0;
            foreach (self::PILLAR_PATTERNS as $pat) {
                if (mb_strpos($tituloNorm, $pat) !== false) {
                    $bonusPillar += 25.0; // grande peso — pattern match é decisivo
                    break; // só conta 1 padrão (não inflar)
                }
            }

            $score = $simBase + $bonusPillar;
            if ($score > $melhorScore) {
                $melhorScore = $score;
                $melhor = array_merge($c, [
                    'similarity'   => round($simBase, 1),
                    'pillar_score' => round($score, 1),
                    'is_pillar_pattern' => $bonusPillar > 0,
                ]);
            }
        }

        // Threshold: precisa ser pelo menos 50 (similarity razoável + idealmente padrão pillar)
        if ($melhor === null || $melhorScore < 50.0) return null;
        return $melhor;
    }
}
