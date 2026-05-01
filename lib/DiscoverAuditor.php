<?php
/**
 * Auditor anti-alucinação.
 * Compara nomes próprios do artigo gerado vs texto das fontes.
 * Flagra nomes "suspeitos" (aparecem no artigo mas NÃO aparecem em nenhuma fonte).
 */
class DiscoverAuditor
{
    /** Palavras capitalizadas comuns que não são nomes próprios de pessoas. */
    private static array $stopwords = [
        // Meses
        'Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro',
        // Dias
        'Segunda','Terça','Quarta','Quinta','Sexta','Sábado','Domingo',
        // Começos de frase comuns
        'Aqui','Lá','Então','Agora','Hoje','Ontem','Amanhã','Também','Isso','Isto','Ele','Ela','Eles','Elas','Quando','Onde','Como','Quem',
        'Não','Sim','Mas','Sem','Com','Por','Para','Entre','Sobre','Após','Antes','Durante','Segundo','Conforme','Veja','Leia','Saiba',
        // Siglas/comum gerais
        'Brasil','Governo','Estado','Copa','Grupo','Fase','Sul','Norte','Leste','Oeste','Nacional','Federal','Municipal','Estadual',
        // Vocabulário editorial
        'Leia','Também','Confira','Saiba','Entenda','Veja','Assista','Acompanhe','Fonte','Segundo','Conforme',
    ];

    /**
     * Analisa o HTML gerado contra os textos das fontes.
     *
     * @param string $htmlGerado
     * @param array  $textosFontes  array de strings — texto completo de cada fonte scrapeada
     * @return array [
     *   'ok' => bool,
     *   'nomes_suspeitos' => [['nome'=>string, 'contexto'=>string]],
     *   'nomes_confirmados' => int,
     *   'nomes_checados'    => int,
     * ]
     */
    public static function auditar(string $htmlGerado, array $textosFontes): array
    {
        $texto = html_entity_decode(strip_tags($htmlGerado), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $fonteAll = self::normalizar(implode("\n", $textosFontes));

        // Quebra em sentenças (pontuação + quebras de linha) pra não atravessar fronteiras
        $sentencas = preg_split('/(?<=[.!?:;])\s+|\n+/u', $texto) ?: [];

        // Extrai nomes próprios dentro de cada sentença isoladamente
        $candidatos = [];
        foreach ($sentencas as $s) {
            $s = trim(preg_replace('/\s+/u', ' ', $s));
            if ($s === '') continue;
            if (preg_match_all('/\b((?:[A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç]+ ){1,3}[A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç]+)\b/u', $s, $m)) {
                foreach ($m[1] as $n) $candidatos[] = trim($n);
            }
        }
        $candidatos = array_values(array_unique($candidatos));

        $checados = 0;
        $confirmados = 0;
        $suspeitos = [];

        foreach ($candidatos as $nome) {
            // filtro: primeira palavra não pode ser stopword
            $partes = explode(' ', $nome);
            if (in_array($partes[0], self::$stopwords, true)) continue;
            // filtro: precisa ter ao menos 2 palavras de 4+ letras cada (heurística pra pessoa)
            $palavrasLongas = array_filter($partes, fn($p) => mb_strlen($p) >= 4);
            if (count($palavrasLongas) < 2) continue;

            $checados++;
            $nomeNorm = self::normalizar($nome);
            if (mb_stripos($fonteAll, $nomeNorm) !== false) {
                $confirmados++;
                continue;
            }
            // tentar também só sobrenome (pode estar como "o Pereira disse" na fonte)
            $ultimo = end($partes);
            if (mb_strlen($ultimo) >= 5 && mb_stripos($fonteAll, self::normalizar($ultimo)) !== false) {
                $confirmados++;
                continue;
            }

            // Captura contexto (80 chars antes e depois)
            $ctx = '';
            $pos = mb_stripos($texto, $nome);
            if ($pos !== false) {
                $ini = max(0, $pos - 70);
                $ctx = mb_substr($texto, $ini, mb_strlen($nome) + 140);
            }
            $suspeitos[] = ['nome' => $nome, 'contexto' => trim(preg_replace('/\s+/', ' ', $ctx))];
        }

        $ok = empty($suspeitos);
        return [
            'ok'                 => $ok,
            'nomes_suspeitos'    => $suspeitos,
            'nomes_confirmados'  => $confirmados,
            'nomes_checados'     => $checados,
        ];
    }

    /** Normaliza string: lowercase + sem acento, pra match resiliente. */
    private static function normalizar(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $from = ['á','à','â','ã','é','ê','í','ó','ô','õ','ú','ç'];
        $to   = ['a','a','a','a','e','e','i','o','o','o','u','c'];
        return str_replace($from, $to, $s);
    }
}
