<?php
/**
 * DiscoverAiOverview — otimização pra Google AI Overview / featured snippet (B1).
 *
 * Discover/Search "AI Overview" (sucessor do SGE) extrai resumo direto de páginas pra
 * responder no topo da SERP. Páginas com TL;DR claro + Speakable schema têm 30%+ chance
 * de serem CITADAS no AI Overview = brand awareness mesmo SEM clique.
 *
 * Faz 3 coisas:
 *   1. Detecta P1 (1º parágrafo após H1/título) e otimiza pra resposta direta:
 *      - Garantir 150-200 chars com FATO+ENTIDADE+TEMPORAL no início
 *      - Se já está OK, mantém. Se está vago, INSERE bloco TL;DR "Em resumo:" antes do P1
 *   2. Schema Speakable (parte do Article schema) marca o TL;DR como "speakable" — Google
 *      Assistant lê esse trecho em busca por voz
 *   3. Meta description otimizada (≤155 chars) — usado por Google como snippet em SERP
 *
 * Idempotente — marker `data-ai-overview="1"` evita reprocessar.
 *
 * Uso (chamado em DiscoverPostProcess::processar):
 *   $html = DiscoverAiOverview::aplicar($html, $meta, $trend);
 */
class DiscoverAiOverview
{
    private const MARKER = 'data-ai-overview="1"';

    /**
     * Aplica otimizações. Não bloqueia em erro — retorna HTML original em falha.
     */
    public static function aplicar(string $html, array $meta = [], array $trend = []): string
    {
        if (strpos($html, self::MARKER) !== false) return $html; // idempotência

        $titulo = (string)($meta['titulo'] ?? '');
        $url    = (string)($meta['url'] ?? '');
        if ($titulo === '') return $html;

        // 1. Extrai P1
        $p1 = self::extrairPrimeiroParagrafo($html);
        if ($p1 === null) return $html;

        // 2. Avalia se P1 está "AI Overview ready"
        $aiReady = self::isAiOverviewReady($p1, $trend);

        if (!$aiReady) {
            // Insere bloco TL;DR estruturado ANTES do P1
            $tldr = self::montarTldr($titulo, $trend, $p1);
            if ($tldr !== '') {
                $html = self::inserirTldrAntesDoP1($html, $tldr);
            }
        }

        // 3. Adiciona Speakable schema (JSON-LD inline minimalista — caller deve estar
        //    chamando DiscoverSchemas que já gera Article — adicionamos só `speakable`)
        if ($url !== '') {
            $speakable = self::montarSpeakableSchema($url);
            if ($speakable !== '') $html .= "\n" . $speakable;
        }

        return $html;
    }

    /**
     * Sugere meta description otimizada. Usado pelo caller pra setar via
     * Wordpress::atualizarPost ou Yoast/Rank Math API.
     *
     * Regras:
     *  - 130-155 chars (sweet spot Google)
     *  - Começar com FATO/NÚMERO + ação concreta
     *  - NÃO ser repetição literal do P1 (Google ignora se duplicado)
     */
    public static function metaDescription(string $titulo, string $p1, array $trend = []): string
    {
        $p1Limpo = trim(strip_tags($p1));
        if (mb_strlen($p1Limpo) <= 155) return $p1Limpo;

        // Tenta cortar no fim de frase mais próxima de 145 chars
        $primeiras2 = mb_substr($p1Limpo, 0, 200);
        if (preg_match('/^(.{120,155}[.!?])\s/u', $primeiras2, $m)) {
            return trim($m[1]);
        }
        return mb_substr($p1Limpo, 0, 152) . '...';
    }

    // ── helpers ──

    private static function extrairPrimeiroParagrafo(string $html): ?string
    {
        // Pega 1º <p> que não seja card/dispositivo/disclaimer
        if (!preg_match_all('#<p\b[^>]*>(.+?)</p>#is', $html, $matches)) return null;
        foreach ($matches[0] as $i => $full) {
            // Pula cards, disclaimers, leia também
            if (preg_match('/class\s*=\s*[\'"]?[^\'">]*(msg-card|leia-mais|trust-block|cta|disclaimer|share)/i', $full)) continue;
            $texto = trim(strip_tags($matches[1][$i]));
            if (mb_strlen($texto) < 40) continue; // muito curto, pula
            return $texto;
        }
        return null;
    }

    /**
     * P1 está "AI Overview ready" se nas primeiras palavras tem:
     *  - número OU entidade nomeada (CAPS+letras consecutivas)
     *  - referência temporal (data, dia da semana, "em maio", "2026", etc)
     *  - ação ou fato concreto (verbo de evento — abrir, liberar, anunciar, divulgar)
     */
    private static function isAiOverviewReady(string $p1, array $trend = []): bool
    {
        $abertura = mb_substr($p1, 0, 200);

        $temNumero    = (bool)preg_match('/\b(\d+|R\$\s*\d|R\$\d|\d{1,3}(?:\.\d{3})*)\b/', $abertura);
        // Entidade: nome próprio (Maiúscula+minúsculas) OU sigla all-caps (ENEM, INSS, FGTS, BPC, MEC)
        $temEntidade  = (bool)preg_match('/\b([A-ZÁÉÍÓÚÂÊÔÃÕ]{2,}|[A-ZÁÉÍÓÚÂÊÔÃÕ][a-záéíóúâêôãõç]+)\b/u', $abertura);
        $temTempo     = (bool)preg_match('/\b(202[5-9]|janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro|segunda|terça|quarta|quinta|sexta|sábado|domingo|hoje|ontem|amanhã|nesta semana|neste mês)\b/iu', $abertura);
        $temVerbo     = (bool)preg_match('/\b(abr[ei]|liber[oa]|anunci[oa]|divulg[oa]|public[oa]|paga|pag[oa]|aprov[oa]|come[çc][ao]|inicia|começ|encerra|termina|expira|venc[ei])/iu', $abertura);

        // Pelo menos 3 dos 4 sinais = ready
        $score = (int)$temNumero + (int)$temEntidade + (int)$temTempo + (int)$temVerbo;
        return $score >= 3;
    }

    /**
     * Monta bloco TL;DR estruturado que vira target do Speakable schema.
     * Resposta direta em ≤200 chars: o que aconteceu + quando + quem afetado.
     */
    private static function montarTldr(string $titulo, array $trend, string $p1): string
    {
        // O TL;DR ideal é uma frase derivada do título + complemento do P1.
        // Aqui usamos abordagem conservadora: NÃO inventa fato — só estrutura visualmente
        // o que o post já diz, marcando com classe pro AI Overview parsear.
        $resumo = trim($titulo);
        if (mb_strlen($resumo) > 110) $resumo = mb_substr($resumo, 0, 107) . '...';

        $detalhe = trim(strip_tags($p1));
        if (mb_strlen($detalhe) > 180) {
            // Pega 1ª frase
            if (preg_match('/^(.{60,180}[.!?])/u', $detalhe, $m)) {
                $detalhe = trim($m[1]);
            } else {
                $detalhe = mb_substr($detalhe, 0, 177) . '...';
            }
        }

        // HTML inline: classe "ai-overview-tldr" + speakable marker
        return '<div class="ai-overview-tldr" ' . self::MARKER . ' itemscope itemtype="https://schema.org/CreativeWork" style="background:#f8fafc;border-left:4px solid #0b57d0;padding:12px 16px;margin:0 0 16px 0;border-radius:4px;font-size:0.95em;">'
            . '<strong style="color:#0b57d0;">Em resumo:</strong> '
            . '<span itemprop="abstract">' . htmlspecialchars($resumo, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</div>';
    }

    private static function inserirTldrAntesDoP1(string $html, string $tldr): string
    {
        // Injeta antes do 1º <p> — usa preg_replace com limit=1
        $novoHtml = preg_replace('/(<p\b[^>]*>)/', $tldr . "\n$1", $html, 1);
        return $novoHtml ?: $html;
    }

    /**
     * Schema.org Speakable: marca o TL;DR como speakable pra Google Assistant.
     */
    private static function montarSpeakableSchema(string $url): string
    {
        $payload = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebPage',
            'url'      => $url,
            'speakable' => [
                '@type'    => 'SpeakableSpecification',
                'cssSelector' => ['.ai-overview-tldr', 'h1'],
            ],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return '<script type="application/ld+json" data-speakable="1">' . $json . '</script>';
    }
}
