<?php
/**
 * PostMatcher — detecta posts existentes no WP que dão match com items novos do cluster.
 *
 * Objetivo: evitar criação de duplicatas. Em temas recorrentes (Bolsa Família, PIS, INSS,
 * Pé-de-Meia — todos atualizam mensalmente), o site provavelmente já tem post desse tema
 * de mês passado. Refresh do post antigo (preserva URL + autoridade) > criar novo URL.
 *
 * Usa Wordpress::buscarRelacionados() (WP REST API ?search=) + similar_text() pra calcular
 * similaridade title-vs-keyword. Bônus pra palavras-gatilho compartilhadas (bolsa familia,
 * pis, pasep, calendário, consulta, etc).
 *
 * Threshold default 55% — calibrado pra capturar "Calendário Bolsa Família 2026" matchando
 * "Bolsa Família dezembro: calendário e valores" sem cair em falsos positivos.
 *
 * Falha silenciosa: se WP REST der timeout/erro, retorna null (item segue como novo).
 */
require_once __DIR__ . '/Wordpress.php';

class PostMatcher
{
    private Wordpress $wp;
    private float $threshold;

    public function __construct(Wordpress $wp, float $threshold = 55.0)
    {
        $this->wp = $wp;
        $this->threshold = $threshold;
    }

    /**
     * Busca match para uma keyword. Retorna [id, title, link, image, similarity] ou null.
     */
    public function encontrarMatch(string $keyword, int $limitBusca = 6): ?array
    {
        $kw = trim($keyword);
        if ($kw === '' || mb_strlen($kw) < 4) return null;

        try {
            $candidatos = $this->wp->buscarRelacionados($kw, $limitBusca);
        } catch (Throwable $e) {
            // WP indisponível, timeout, 401 — silencia, item segue como criação nova
            return null;
        }
        if (empty($candidatos)) return null;

        $kwNorm = $this->normalizar($kw);
        $melhor = null;
        $melhorScore = 0.0;

        foreach ($candidatos as $c) {
            $titulo = (string)($c['title'] ?? '');
            if ($titulo === '') continue;
            $titNorm = $this->normalizar($titulo);

            // similar_text retorna 0-100 (% de chars comuns)
            similar_text($kwNorm, $titNorm, $score);
            // Bônus pra palavras-gatilho compartilhadas (até +12)
            $score += $this->bonusGatilhos($kwNorm, $titNorm);
            $score = min(100.0, $score);

            if ($score > $melhorScore) {
                $melhorScore = $score;
                $melhor = array_merge($c, ['similarity' => round($score, 1)]);
            }
        }

        if ($melhor === null || $melhorScore < $this->threshold) return null;
        return $melhor;
    }

    /**
     * Batch: pra cada item retorna match (ou null).
     * Roda sync — N HTTP calls (cada ~200-400ms). 15 items = ~5s.
     *
     * @param array $keywords Array de strings indexadas pela posição do item
     * @return array [i => match-array | null]
     */
    public function encontrarMatchBatch(array $keywords): array
    {
        $out = [];
        foreach ($keywords as $i => $kw) {
            $out[$i] = $this->encontrarMatch((string)$kw);
        }
        return $out;
    }

    /** Lowercase + tira pontuação + normaliza espaços. Acentos preservados (importante PT-BR). */
    private function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * Soma bônus quando A e B compartilham termos-gatilho do nicho governamental BR.
     * Esses termos são fortes indicadores de que dois títulos são sobre o MESMO tema.
     * Cap em 12 pontos pra não inflar artificialmente.
     */
    private function bonusGatilhos(string $a, string $b): float
    {
        $gatilhos = [
            // Programas/benefícios
            'bolsa familia', 'bolsa família', 'pis', 'pasep', 'fgts', 'inss', 'enem',
            'senai', 'senac', 'sebrae', 'auxilio', 'auxílio', 'bpc', 'mei',
            'pé-de-meia', 'pe-de-meia', 'pé de meia', 'pe de meia',
            'consignado', 'aposentadoria', 'previdencia', 'previdência',
            // Verbos de ação
            'calendario', 'calendário', 'pagamento', 'consulta', 'consultar',
            'edital', 'concurso', 'vagas', 'inscricao', 'inscrição',
            'saque', 'liberacao', 'liberação',
            // Identidade
            'cpf', 'nis', 'cnpj',
        ];
        $bonus = 0.0;
        foreach ($gatilhos as $g) {
            if (mb_strpos($a, $g) !== false && mb_strpos($b, $g) !== false) {
                $bonus += 3.5;
                if ($bonus >= 12.0) return 12.0;
            }
        }
        return $bonus;
    }
}
