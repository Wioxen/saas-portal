<?php
declare(strict_types=1);

/**
 * AntiAIPostProcessor — limpeza determinística pós-Sonnet/Haiku.
 *
 * Remove frases banidas, travessões e excesso de reticências via find-replace literal.
 * Garante 0% de padrões IA conhecidos no output (zera o que LLM ignora).
 *
 * Ordem no pipeline:
 *   Sonnet → AntiAI valida → se fail, Haiku revisa → AntiAI re-valida → AntiAIPostProcessor
 *   → AntiAI valida final → gate publish/draft
 *
 * Filosofia: LLM pode ser persuadido (prompt) mas vai vazar 3-5% das vezes.
 * Regex find-replace literal vaza 0%. Esta é a rede de segurança.
 *
 * Uso:
 *   $r = AntiAIPostProcessor::limpar($html);
 *   echo $r['html'];   // HTML limpo
 *   var_dump($r['log']); // o que foi alterado
 */
class AntiAIPostProcessor
{
    /**
     * @param string $html      HTML do artigo
     * @param string $titulo    Título do artigo (usado pra decidir se msg-card é legítimo)
     * @return array {html, log, mudou}
     */
    public static function limpar(string $html, string $titulo = ''): array
    {
        $original = $html;
        $log = [
            'phrases' => [],
            'travessoes' => 0,
            'reticencias_extras' => 0,
            'msg_cards_removidos' => 0,
        ];

        // 0. Decodifica escape literais \n \r \t — caso real #4995:
        // HTML salvo no WP tinha bytes 0x5C 0x6E (\n literal) entre </p> e <h3>
        // em vez de newline real. Causa raiz: Sonnet retorna `\\n` (DOUBLE-backslash
        // + n) no JSON source. Após json_decode, fica `\` + `n` literal na string PHP,
        // que vai bruto pro WP. Detectores de FAQ/h3 falham porque \s* não casa.
        //
        // Cuidado: NÃO decodar dentro de atributos HTML (href, src, class, style).
        // Solução: aplica só FORA de tags, processando token a token.
        $escapes_decoded = 0;
        $htmlNovo = preg_replace_callback(
            '#(<[^>]*>)|([^<]+)#',
            function ($m) use (&$escapes_decoded) {
                if (!empty($m[1])) return $m[1]; // tag — não toca
                $txt = $m[2];
                $count = substr_count($txt, '\\n') + substr_count($txt, '\\r') + substr_count($txt, '\\t');
                if ($count === 0) return $txt;
                $escapes_decoded += $count;
                return str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $txt);
            },
            $html
        );
        if ($htmlNovo !== null && $escapes_decoded > 0) {
            $html = $htmlNovo;
            $log['escapes_literais_decodificados'] = $escapes_decoded;
        }

        // Dedup semântico: remove perguntas do FAQ <details> que duplicam tema dos h2s do corpo.
        // Caso real 04/05 #4727: h2 "Plataforma Aprenda Mais ou AVAMEC: qual acessar?" + FAQ
        // pergunta similar = redundância semântica. Google penaliza repetição.
        $resDedup = self::dedupFaqVsH2($html);
        $html = $resDedup['html'];
        $log['faq_perguntas_removidas'] = $resDedup['removidas'];

        // Dedup FAQ h3+p vs <details>: caso real #4747 — Sonnet gerou DUAS versões do FAQ
        // (uma h3+p e outra h2+details/summary com mesmas perguntas). Remove a versão h3+p
        // mantendo a <details> (mais semântica + Google FAQPage schema).
        $resH3 = self::dedupFaqH3VsDetails($html);
        $html = $resH3['html'];
        $log['faq_h3_removidos'] = $resH3['removidos'];

        // Reposicionar "Leia também" pra logo após introdução (após snippet+1ºH2).
        // Caso real #4747: leia-tambem ficava perto do FAQ no fim. Posição correta é
        // depois da intro pra reforçar engagement+linkagem cedo.
        $resReposicionar = self::repositionarLeiaTambem($html);
        $html = $resReposicionar['html'];
        $log['leia_tambem_reposicionado'] = $resReposicionar['movido'];

        // Merge h3-perguntas órfãs dentro do FAQ formal.
        // Caso real #4755: h3 "Por que ENCCEJA está em alta?" + h3 "ENCCEJA é importante?"
        // antes do h2 "Perguntas frequentes" + 3 details = 5 perguntas em 2 blocos visuais.
        // Solução: converter h3+p em <details><summary>h3</summary><p>p</p></details> e mover
        // pra dentro do bloco FAQ formal. Resultado: 1 bloco unificado.
        $resMerge = self::mergeOrphanQuestionsIntoFaq($html);
        $html = $resMerge['html'];
        $log['h3_perguntas_movidas_pro_faq'] = $resMerge['movidas'];

        // Merge perguntas em <strong>Pergunta?</strong> + <p>Resposta</p> pro FAQ.
        // Caso real #4982: Sonnet usou 4 strong-perguntas no corpo que duplicavam com
        // details FAQ (palavra-chave "É seguro comprimir PDF...", "Como comprimir...").
        // Detector h3 não pegou porque era <strong>, não <h3>.
        $resStrong = self::mergeStrongPerguntasIntoFaq($html);
        $html = $resStrong['html'];
        $log['strong_perguntas_movidas_pro_faq'] = $resStrong['movidas'];

        // Remove citações a portais concorrentes do body. Caso real #5047:
        // "segundo levantamento do portal Click Petróleo e Gás" — Sonnet citou a fonte
        // scrapeada pelo nome. User feedback 06/05: substituir por "nossa redação".
        // Cobre padrões: (segundo|conforme|de acordo com|para|via|informa|aponta)
        //                + (portal|site|veículo|publicação|levantamento|reportagem|matéria)
        //                + nome do portal
        // ATENÇÃO: precisa tolerar tags HTML dentro do nome (auto-linker às vezes injeta
        // <a> no meio do nome do portal — caso #5047: "Click <a>Petróleo e Gás</a>").
        $portais_blacklist = [
            'Click Petr[óo]leo e G[áa]s', 'Click Petr[óo]leo', 'CPG ',
            'Metr[óo]poles', 'CNN Brasil', 'G1\b', 'O Globo\b', 'Globo\.com',
            'Folha de S\.?\s*Paulo', 'Folha de SP', 'Folha\b(?! Online)',
            'Estad[ãa]o\b', 'Estado de SP', 'Estado de S\.\s*Paulo',
            'Catraca Livre', 'Conecta Professores', 'Xataka', 'Canaltech',
            'Revista Oeste', 'Terra\.com', 'Portal Terra', 'Veja\b(?!ndo)',
            'Valor Econ[ôo]mico', 'ESPN', 'Correio Braziliense',
            'UOL\b', 'Lance!?\b', 'Trivela', 'Bahia Not[ií]cias',
            'Metr[óo] 1\b', 'Metro 1\b', 'Hora Brasil', 'Baguete',
            'Quero Bolsa', 'querobolsa\.com',
            'InfoEduca[çc][ãa]o', 'PEB-?SP',
            'R[ãa]dio Itati[áa]ia', 'Estad[ãa]o\.com',
            'Jornal Correio', 'Al[ôo] Al[ôo] Bahia',
        ];
        $citacoes_removidas = 0;
        // Estratégia: pra cada portal, transforma whitespace literal em "(?:\s|<tag>)+"
        // pra tolerar tags HTML que o auto-linker às vezes injeta dentro do nome.
        // Ex: "Click Petróleo e Gás" → "Click(?:\s|<[^>]*>|</[^>]*>)+Petróleo..."
        $tagInBetween = '(?:\s|<[^>]+>)+';
        foreach ($portais_blacklist as $portal) {
            // Substitui TODO espaço literal no padrão por tagInBetween
            $portalTolerante = preg_replace('/ +/', $tagInBetween, $portal);
            // Padrão 1: cláusula completa "(verbo) (do/no/da/pelo) (portal/site/...)? X"
            // Captura (verbo) e descarta o resto, prefixando com "da" pra ficar natural
            // ("segundo levantamento da nossa redação", "informou nossa redação", etc).
            $pat = '#(segundo|conforme|de\s+acordo\s+com|para|via|informa|informou|aponta|apontou|noticia|noticiou|destaca|destacou|analisou|levantamento)(\s+(?:d[oae]|n[oa]|pel[oa])\s+(?:portal|site|ve[íi]culo|publica[çc][ãa]o|reportagem|mat[ée]ria|jornal|revista)\s+|\s+(?:d[oae]|n[oa]|pel[oa])\s+|\s+(?:portal|site|ve[íi]culo)\s+|\s+)' . $portalTolerante . '\b(?:</a>)?(\.\s*com\.br)?#iu';
            $html = preg_replace_callback($pat, function ($m) use (&$citacoes_removidas) {
                $citacoes_removidas++;
                $verbo = strtolower($m[1]);
                // "levantamento" pede "da": "levantamento da nossa redação"
                // Outros verbos sem complemento: "informou nossa redação"
                if ($verbo === 'levantamento' || $verbo === 'segundo' || $verbo === 'conforme' || str_starts_with($verbo, 'de acordo')) {
                    return $m[1] . ' da nossa redação';
                }
                return $m[1] . ' nossa redação';
            }, $html) ?? $html;
        }
        // Cleanup: <a> órfão sem fechamento + texto vazio "<a ...></a>" → remove
        $html = preg_replace('#<a\s[^>]*>\s*</a>#i', '', $html) ?? $html;
        if ($citacoes_removidas > 0) $log['citacoes_concorrentes_removidas'] = $citacoes_removidas;

        // Remove "?" final de H2 do corpo (proibido pelo prompt mas Sonnet ignora às vezes).
        // Caso real #5021: H2 "O que é o Mestrado Profissional em Educação da UFFS?".
        // Estratégia conservadora: tira só o "?" final em H2s do CORPO (preserva H2 do FAQ
        // que naturalmente usa "Perguntas frequentes" sem "?").
        $h2_perg_fixed = 0;
        $html = preg_replace_callback(
            '#<h2([^>]*)>([^<]+)\?(\s*)</h2>#i',
            function ($m) use (&$h2_perg_fixed) {
                $txt = trim($m[2]);
                // Pula h2 do FAQ (Perguntas frequentes, FAQ, Dúvidas...) — ali ? é raro mas
                // se aparecer a pergunta vai pra summary; aqui tô só limpando h2-pergunta-no-corpo.
                if (preg_match('/perguntas?\s*frequentes?|^FAQ\b|d[úu]vidas?/iu', $txt)) return $m[0];
                $h2_perg_fixed++;
                return '<h2' . $m[1] . '>' . $txt . '</h2>';
            },
            $html
        ) ?? $html;
        if ($h2_perg_fixed > 0) $log['h2_pergunta_corrigido'] = $h2_perg_fixed;

        // Detector msg-card indevido — só permitido se título contém trigger keyword.
        // Caso real 04/05: posts #4680/#4688 (curso de IA p/ professores) ganharam msg-card
        // num número de WhatsApp/contato indevido. Sonnet ignorou regra do prompt.
        if ($titulo !== '' && self::contemMsgCard($html) && !self::tituloPermiteMsgCard($titulo)) {
            $count = 0;
            $html = preg_replace_callback(
                '#<div\b[^>]*\bclass=[\'"][^\'"]*\bmsg-card\b[^\'"]*[\'"][^>]*>.*?</div>\s*</div>#is',
                function () use (&$count) { $count++; return ''; },
                $html
            ) ?? $html;
            // Pattern alternativo (fallback se aninhamento der ruim) — remove qualquer div com msg-card
            $html = preg_replace_callback(
                '#<div\b[^>]*\bclass=[\'"][^\'"]*\bmsg-card\b[^\'"]*[\'"][^>]*>(?:(?!</div>).)*?</div>#is',
                function () use (&$count) { $count++; return ''; },
                $html
            ) ?? $html;
            $log['msg_cards_removidos'] = $count;
        }

        // 1. Preservar blocos <script>, <style>, <code> — não tocar conteúdo
        $preservados = [];
        $html = preg_replace_callback(
            '#<(script|style|code)\b[^>]*>.*?</\1>#is',
            function ($m) use (&$preservados) {
                $token = '@@@PRESERVED_' . count($preservados) . '@@@';
                $preservados[$token] = $m[0];
                return $token;
            },
            $html
        ) ?: $html;

        // 2. Reticências: contar e manter só a 1ª. `.{3,}` ou `…` viram `.`
        $count = 0;
        $html = preg_replace_callback(
            '/\.{3,}|…/u',
            function ($m) use (&$count, &$log) {
                $count++;
                if ($count === 1) return $m[0];
                $log['reticencias_extras']++;
                return '.';
            },
            $html
        ) ?? $html;

        // 3. Frases banidas — find-replace literal preservando capitalização inicial
        foreach (self::mapaSubstituicoes() as $banida => $sub) {
            $pattern = '/(?<![\w\-])' . preg_quote($banida, '/') . '(?![\w\-])/iu';
            $alterado = 0;
            $html = preg_replace_callback($pattern, function ($m) use ($sub) {
                if ($sub === '') return '';
                $orig = $m[0];
                // Preserva capitalização do início se original começa maiúsculo
                if (preg_match('/^[A-ZÁÉÍÓÚÂÊÔÀÃÕÇ]/u', $orig)) {
                    return mb_strtoupper(mb_substr($sub, 0, 1)) . mb_substr($sub, 1);
                }
                return $sub;
            }, $html, -1, $alterado) ?? $html;
            if ($alterado > 0) {
                $log['phrases'][$banida] = ['sub' => $sub, 'count' => $alterado];
            }
        }

        // 4. Travessões `—` e en-dash `–` → vírgula. Aplica em TODO o HTML (h1 incluso).
        $travessoesAntes = substr_count($html, '—') + substr_count($html, '–');
        if ($travessoesAntes > 0) {
            $html = str_replace(['—', '–'], ',', $html);
            $log['travessoes'] = $travessoesAntes;
        }

        // 4.4. Promove links gov.br genéricos pra específicos.
        // Caso real #4911/#4878/#4805: Sonnet gera "<a href='https://www.gov.br/'>" no
        // rodapé "🏛️ Portal Gov.br" (genérico) mesmo quando o post fala de Inep/MEC/etc.
        // Estratégia: se há gov.br/X específico no post, remove o genérico (redundante).
        $html = self::corrigirLinksGenericosGovBr($html);

        // 4.6. Sanitiza <a> sem href, com href vazio, "#" ou javascript:.
        // Caso real reportado pelo user 03/05: "links na paginas sem href" — pode ser
        // <a> sobrando após algum step de limpeza, ou Sonnet gerou âncora truncada.
        // Estratégia: descasca o <a> mantendo o texto interno (link inválido vira texto).
        $a_sanitized = 0;
        $html = preg_replace_callback(
            '#<a\b([^>]*)>([\s\S]*?)</a>#i',
            function ($m) use (&$a_sanitized) {
                $attrs = (string)$m[1];
                $inner = (string)$m[2];
                // Captura href se existir
                $temHref = preg_match('/\bhref\s*=\s*([\'"])([^\'"]*)\1/i', $attrs, $hm);
                if (!$temHref) {
                    $a_sanitized++;
                    return $inner; // <a> sem href: descasca
                }
                $href = trim($hm[2]);
                // href vazio, só "#", javascript:, "void(0)" — todos inválidos
                if ($href === '' || $href === '#' || stripos($href, 'javascript:') === 0
                    || stripos($href, 'void(0)') !== false) {
                    $a_sanitized++;
                    return $inner;
                }
                return $m[0]; // link válido, mantém
            },
            $html
        ) ?? $html;
        if ($a_sanitized > 0) $log['links_sem_href_removidos'] = $a_sanitized;

        // 4.7-pre. Tenta completar URLs truncadas usando ocorrências completas no MESMO post.
        // Caso real #4995: "vestibular.fatec.sp." (sem .gov.br) no passo 1 mas o post tem
        // 8x "vestibular.fatec.sp.gov.br" em outros lugares. Detecta truncamento + completa
        // a partir do contexto. NÃO INVENTA — só usa o que já está escrito no post.
        $urls_completadas = 0;
        // Coleta todas as URLs completas (com TLD válido) presentes no HTML
        $urlsCompletas = [];
        if (preg_match_all('#\b([a-z][a-z0-9-]*(?:\.[a-z0-9-]+){2,4})(?:/[^\s<>"\')]*)?#iu', $html, $um)) {
            foreach ($um[1] as $u) {
                $u = strtolower($u);
                // Só considera URLs com TLD final válido (.com, .br, .gov, etc)
                if (preg_match('#\.(?:com|net|org|br|edu|gov|io|co|info|app|tech|me|pt|dev|biz|store|online|site|blog|news|tv|fm|jobs)(?:\.[a-z]{2,3})?$#u', $u)) {
                    $urlsCompletas[$u] = true;
                }
            }
        }
        if (!empty($urlsCompletas)) {
            // Busca padrões truncados: "dom.sub.tld." seguido de espaço/parênteses/pontuação não-letra
            $html = preg_replace_callback(
                '#(?<![\w/-])([a-z][a-z0-9-]*(?:\.[a-z0-9-]+){2,3})\.(?=[\s)\]])#iu',
                function ($m) use ($urlsCompletas, &$urls_completadas) {
                    $truncado = strtolower($m[1]);
                    // Busca completa no mesmo post: "trunc.algo" onde algo=gov.br/com/etc
                    foreach (array_keys($urlsCompletas) as $completa) {
                        if (str_starts_with($completa, $truncado . '.') && $completa !== $truncado) {
                            // Tem versão completa — substitui
                            $urls_completadas++;
                            return $completa;
                        }
                    }
                    return $m[0]; // sem versão completa, mantém
                },
                $html
            ) ?? $html;
        }
        if ($urls_completadas > 0) $log['urls_truncadas_completadas'] = $urls_completadas;

        // 4.7. Auto-link de URLs em texto puro.
        // Caso real #4982 reportado pelo user: passos do tutorial tinham
        //   "Acesse smallpdf.com/pt/comprimir-pdf no navegador"
        //   "<strong>pdf.wps.com/pt/comprimir-pdf/</strong>"
        // como TEXTO (não <a>), parecendo links mas não-clicáveis.
        // Estratégia: encontra URL bruta ou em <strong>, converte em <a>.
        // CUIDADO: pula conteúdo dentro de aviso editorial (alucinado/fidelity)
        // e dentro de <a>/<code>/<pre> (já preservados acima).
        $auto_linked = 0;
        // Marca o bloco de aviso editorial pra não auto-linkar dentro
        $avisoBlocos = [];
        $html = preg_replace_callback(
            '#<div\b[^>]*\bcc-(?:fidelity|alucinado|nao-confirmado|aviso)\b[^>]*>[\s\S]*?</div>(?:\s*</div>)?#i',
            function ($m) use (&$avisoBlocos) {
                $tk = '@@@AVISO_' . count($avisoBlocos) . '@@@';
                $avisoBlocos[$tk] = $m[0];
                return $tk;
            },
            $html
        ) ?? $html;
        // Fallback: bloco com cor de aviso (#7c2d12 / #92400e / fundo #fef3c7)
        $html = preg_replace_callback(
            '~<div\b[^>]*\bstyle=[\'"][^\'"]*(?:\#7c2d12|\#92400e|\#fef3c7)[^>]*>[\s\S]*?</div>\s*</div>?~i',
            function ($m) use (&$avisoBlocos) {
                $tk = '@@@AVISO_' . count($avisoBlocos) . '@@@';
                $avisoBlocos[$tk] = $m[0];
                return $tk;
            },
            $html
        ) ?? $html;

        // TLDs aceitos (ampla — qualquer domínio do post deve virar link).
        // Regra: precisa ter ao menos 1 TLD reconhecível pra evitar falso positivo.
        $tldRegex = '(?:com|net|org|br|edu|gov|io|co|info|app|tech|me|pt|us|uk|de|fr|es|jobs|news|tv|fm|tk|biz|store|online|site|blog|dev|cloud|email|pro|club|space|website|xyz|live|world|today|life|works|tools|guru|expert|center|services|solutions|digital|systems|design|agency|media|studio|press|review|art|news|host|fans|fund|games|gift|gold|green|group|guide|host|inc|ink|link|lol|love|ltd|men|mom|page|party|run|run|sale|shop|show|site|sport|store|tax|team|tech|top|trade|tube|vip|vote|win|wine|world|wow|wtf|xyz|yoga|zone)';

        // (a) URLs em <strong>...URL...</strong> → <a href>...</a>
        $html = preg_replace_callback(
            '#<strong>\s*((?:https?://)?[a-z][a-z0-9-]*(?:\.[a-z0-9-]+){1,4}(?:/[^\s<>"\']*)?)\s*</strong>#iu',
            function ($m) use (&$auto_linked, $tldRegex) {
                $url = trim($m[1]);
                if (strlen($url) < 5) return $m[0];
                if (!preg_match('#\.' . $tldRegex . '(?:\.[a-z]{2,3})?(?:[/\s]|$)#iu', $url)) return $m[0];
                $href = (stripos($url, 'http') === 0) ? $url : ('https://' . $url);
                $auto_linked++;
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" rel="noopener nofollow" target="_blank">' . $url . '</a>';
            },
            $html
        ) ?? $html;

        // (b) URLs no body inteiro fora de <a>/<code>/<pre>/<script>/<style> e fora de avisos
        // (avisos editoriais já mascarados em $avisoBlocos acima).
        // Mascara <a>/<code>/<pre>/<script>/<style> com tokens, processa, restaura.
        $masksBlock = [];
        $htmlMasked = preg_replace_callback(
            '#<(a|code|pre|script|style)\b[^>]*>[\s\S]*?</\1>#i',
            function ($mm) use (&$masksBlock) {
                $tk = '@@@MK_' . count($masksBlock) . '@@@';
                $masksBlock[$tk] = $mm[0];
                return $tk;
            },
            $html
        ) ?? $html;

        // (b1) URLs com protocolo: https?:// — sempre linkar (qualquer TLD válido)
        $htmlMasked = preg_replace_callback(
            '#(?<![\'"=>])(https?://[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:/[^\s<>"\')]*)?)#iu',
            function ($m) use (&$auto_linked) {
                $url = rtrim($m[1], '.,;:!?)');
                $tail = substr($m[1], strlen($url));
                $auto_linked++;
                return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" rel="noopener nofollow" target="_blank">' . $url . '</a>' . $tail;
            },
            $htmlMasked
        ) ?? $htmlMasked;

        // (b2) Domínios bare COM PATH (mais específicos primeiro):
        //   - dominio.tld/path  → linkar com https://
        $htmlMasked = preg_replace_callback(
            '#(?<![\'"=>/@\w.-])([a-z][a-z0-9-]*(?:\.[a-z0-9-]+){1,3}/[a-z0-9/_.\-]+)#iu',
            function ($m) use (&$auto_linked, $tldRegex) {
                $url = rtrim($m[1], '.,;:!?)');
                $tail = substr($m[1], strlen($url));
                // Valida TLD entre as partes (ex: vestibular.fatec.sp.gov.br/...)
                if (!preg_match('#\.' . $tldRegex . '(?:\.[a-z]{2,3})?(?=/)#iu', $url)) return $m[0];
                $auto_linked++;
                return '<a href="https://' . htmlspecialchars($url, ENT_QUOTES) . '" rel="noopener nofollow" target="_blank">' . $url . '</a>' . $tail;
            },
            $htmlMasked
        ) ?? $htmlMasked;

        // (b3) Domínios bare SEM path (ex: "vestibular.fatec.sp.gov.br") — só linka se
        //   for domínio multi-segmento que termina em TLD válido (.gov.br, .edu.br, .com.br).
        //   Evita linkar coisas tipo "Pé.de.Meia.Bolsa" que não são URLs.
        //   Lookahead: termina ao ver whitespace, pontuação SENTENCIAL (ponto seguido de espaço/fim),
        //   vírgula, parêntese, ou fim de string. NÃO termina antes de outro `.letra` (que indica
        //   continuação do domínio).
        $htmlMasked = preg_replace_callback(
            '#(?<![\'"=>/@\w.-])([a-z][a-z0-9-]+(?:\.[a-z0-9-]+){2,4})(?=[\s,;:!?)]|\.\s|\.$|\.</|$)#iu',
            function ($m) use (&$auto_linked, $tldRegex) {
                $url = $m[1];
                // Precisa terminar em TLD reconhecível
                if (!preg_match('#\.' . $tldRegex . '(?:\.[a-z]{2,3})?$#iu', $url)) return $m[0];
                // Pula se já é um caminho ou se parece com versão de software (1.2.3.4)
                if (preg_match('#^\d#u', $url) || preg_match('#\.\d+\.#u', $url)) return $m[0];
                $auto_linked++;
                return '<a href="https://' . htmlspecialchars($url, ENT_QUOTES) . '" rel="noopener nofollow" target="_blank">' . $url . '</a>';
            },
            $htmlMasked
        ) ?? $htmlMasked;

        // Restaura blocos mascarados
        foreach ($masksBlock as $tk => $orig) $htmlMasked = str_replace($tk, $orig, $htmlMasked);
        $html = $htmlMasked;

        // Restaura blocos de aviso (sem auto-link interno)
        foreach ($avisoBlocos as $tk => $bloco) $html = str_replace($tk, $bloco, $html);
        if ($auto_linked > 0) $log['urls_auto_linkadas'] = $auto_linked;

        // 4.5. Fix HTML escapado: tags aparecem como `&lt;strong&gt;` no texto visível.
        //      Caso real #4805: "strong&gt; com quatro modalidades" — Sonnet ou pipeline
        //      escapou duplicado. Detecta entidades de tag em contexto NÃO-atributo e decodifica.
        $html = preg_replace_callback(
            '/&lt;(\/?)(strong|em|b|i|u|p|br)([^&]{0,40})&gt;/i',
            fn($m) => '<' . $m[1] . $m[2] . html_entity_decode($m[3], ENT_QUOTES) . '>',
            $html
        ) ?? $html;
        // Caso só fechamento órfão — remove
        $html = preg_replace('/(?<![<\w])(strong|em|b|i|u)&gt;/i', '', $html) ?? $html;

        // 5. Cleanup de artefatos da remoção de frases:
        //    - vírgulas duplicadas → vírgula
        //    - ponto + vírgula sequência → ponto
        //    - espaços múltiplos → 1 espaço
        //    - vírgula no início de parágrafo (depois de `<p>`)
        //    - `, ` antes de `.` ou `!` ou `?`
        $html = preg_replace('/,\s*,+/u', ',', $html) ?? $html;
        $html = preg_replace('/\.\s*,/u', '.', $html) ?? $html;
        $html = preg_replace('/,\s*([.!?])/u', '$1', $html) ?? $html;
        $html = preg_replace('/(<p[^>]*>)\s*,\s*/u', '$1', $html) ?? $html;
        $html = preg_replace('/(<h[1-6][^>]*>)\s*,\s*/u', '$1', $html) ?? $html;
        $html = preg_replace('/(<li[^>]*>)\s*,\s*/u', '$1', $html) ?? $html;
        // Espaço duplo (não em pre/code que estão preservados)
        $html = preg_replace('/[ \t]+/u', ' ', $html) ?? $html;
        // Capitalizar 1ª letra de parágrafo se ficou minúscula após remoção
        $html = preg_replace_callback(
            '/(<p[^>]*>)\s*([a-záéíóúâêôàãõç])/u',
            fn($m) => $m[1] . mb_strtoupper($m[2]),
            $html
        ) ?? $html;

        // 6. Restaurar blocos preservados
        foreach ($preservados as $token => $bloco) {
            $html = str_replace($token, $bloco, $html);
        }

        return [
            'html' => $html,
            'log' => $log,
            'mudou' => ($html !== $original),
        ];
    }

    private static function contemMsgCard(string $html): bool
    {
        return stripos($html, 'msg-card') !== false;
    }

    /**
     * Promove links gov.br genéricos pra específicos.
     * Caso real: Sonnet gera "<a href='https://www.gov.br/'>Portal Gov.br</a>" no rodapé,
     * mas o post fala de Inep/MEC/Caixa específicos (que JÁ TÊM link específico em outro
     * lugar). Esse genérico é redundante e fraco pra autoridade.
     *
     * Estratégia:
     *   1. Se há gov.br/X específicos no post → o gov.br/ genérico é REDUNDANTE → remove
     *   2. Senão (só genérico existe) → tenta promover pro órgão mais mencionado no texto
     */
    private static function corrigirLinksGenericosGovBr(string $html): string
    {
        // 1. Detecta se há links gov.br ESPECÍFICOS (com path)
        $temEspecifico = preg_match('#href=[\'"]https?://(?:www\.)?gov\.br/[^\'"\s/]+/?[\'"]#i', $html) === 1;
        // Ou gov.br/inep, gov.br/mec, etc.
        if (!$temEspecifico) {
            $temEspecifico = preg_match('#href=[\'"]https?://(?:www\.)?(?:inep|mec|capes|cnpq|caixa|inss)\.gov\.br#i', $html) === 1;
        }
        if (!$temEspecifico) return $html; // não há específico, não toca no genérico

        // 2. Tem específicos — então o gov.br/ genérico vira redundante. Remove o <a> genérico
        //    mantendo o texto interno (não destrói leitura).
        $html = preg_replace_callback(
            '#<a\s+[^>]*href=[\'"]https?://(?:www\.)?gov\.br/?[\'"][^>]*>(.*?)</a>#is',
            function ($m) {
                $texto = $m[1];
                // Se texto é genérico tipo "Portal Gov.br" / "gov.br" → remove tudo (link + texto)
                $textoLimpo = trim(strip_tags($texto));
                if (preg_match('/^(?:🏛️\s*)?(portal\s+)?gov\.br/iu', $textoLimpo)
                    || mb_strlen($textoLimpo) < 12) {
                    return ''; // remove inteiro
                }
                // Texto descritivo — preserva texto, descarta link
                return $texto;
            },
            $html
        ) ?? $html;

        // Cleanup de <li> ou item de lista vazio que possa ter sobrado
        $html = preg_replace('#<li[^>]*>\s*</li>#is', '', $html) ?? $html;
        $html = preg_replace('#<p[^>]*>\s*</p>#is', '', $html) ?? $html;

        return $html;
    }

    /**
     * Dedup semântico FAQ vs H2 do corpo.
     * Para cada <details><summary>Pergunta</summary> dentro de seção FAQ, verifica se
     * algum h2 do desenvolvimento (não-FAQ) cobre o MESMO tema (Jaccard de palavras-chave
     * >= 0.5). Se sim, remove o <details> redundante.
     */
    private static function dedupFaqVsH2(string $html): array
    {
        $removidas = 0;
        // Coleta h2s do CORPO (excluindo h2 de FAQ)
        $h2sCorpo = [];
        if (preg_match_all('/<h2[^>]*>([\s\S]*?)<\/h2>/i', $html, $m)) {
            foreach ($m[1] as $h2Inner) {
                $txt = mb_strtolower(trim(strip_tags(html_entity_decode($h2Inner, ENT_QUOTES, 'UTF-8'))));
                // Pula se for h2 de FAQ
                if (preg_match('/perguntas?\s*frequentes?|faq|d[úu]vidas?/iu', $txt)) continue;
                $h2sCorpo[] = self::tokenizar($txt);
            }
        }
        if (empty($h2sCorpo)) return ['html' => $html, 'removidas' => 0];

        // Para cada <details><summary>Pergunta</summary>...</details>, comparar
        $html = preg_replace_callback(
            '#<details\b[^>]*>\s*<summary[^>]*>(.+?)</summary>([\s\S]*?)</details>#i',
            function ($match) use ($h2sCorpo, &$removidas) {
                $pergunta = mb_strtolower(trim(strip_tags(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'))));
                $tokensP = self::tokenizar($pergunta);
                if (count($tokensP) < 2) return $match[0]; // pergunta muito curta, manter

                foreach ($h2sCorpo as $tokensH2) {
                    if (count($tokensH2) < 2) continue;
                    $jacc = self::jaccard($tokensP, $tokensH2);
                    if ($jacc >= 0.5) {
                        $removidas++;
                        return ''; // remove esse <details>
                    }
                }
                return $match[0];
            },
            $html
        ) ?? $html;

        return ['html' => $html, 'removidas' => $removidas];
    }

    /**
     * Quando há FAQ formal (<details><summary>Pergunta?</summary>...</details>) no artigo,
     * remove h3-perguntas + p anteriores que duplicam essas perguntas.
     * Caso real #4747: Sonnet gerou h3+p E details/summary com mesmo conteúdo.
     * Critério: Jaccard ≥ 0.6 entre h3 e summary.
     */
    private static function dedupFaqH3VsDetails(string $html): array
    {
        $removidos = 0;
        // Coleta perguntas dos <details><summary>
        $perguntas = [];
        if (preg_match_all('#<details\b[^>]*>\s*<summary[^>]*>(.+?)</summary>#is', $html, $m)) {
            foreach ($m[1] as $s) {
                $perguntas[] = self::tokenizar(mb_strtolower(trim(strip_tags(html_entity_decode($s, ENT_QUOTES, 'UTF-8')))));
            }
        }
        if (empty($perguntas)) return ['html' => $html, 'removidos' => 0];

        // Para cada h3, verificar se duplica alguma pergunta dos details. Se sim, remove h3 + <p> seguinte.
        $html = preg_replace_callback(
            '#<h3\b[^>]*>(.*?)</h3>(\s*<p\b[^>]*>(?:(?!</p>).)*?</p>)?#is',
            function ($match) use ($perguntas, &$removidos) {
                $h3Text = mb_strtolower(trim(strip_tags(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'))));
                if (mb_strlen($h3Text) < 6) return $match[0];
                $tokensH3 = self::tokenizar($h3Text);
                if (count($tokensH3) < 2) return $match[0];

                foreach ($perguntas as $tokensP) {
                    if (count($tokensP) < 2) continue;
                    $jacc = self::jaccard($tokensH3, $tokensP);
                    if ($jacc >= 0.6) {
                        $removidos++;
                        return ''; // remove h3 + p resposta
                    }
                }
                return $match[0];
            },
            $html
        ) ?? $html;

        return ['html' => $html, 'removidos' => $removidos];
    }

    /**
     * Detecta perguntas em <strong>Pergunta?</strong> (Sonnet às vezes usa em vez de h3)
     * + parágrafo seguinte como resposta. Promove pra <details> dentro do FAQ.
     * Caso real #4982: 4 perguntas em <strong> duplicavam com details FAQ.
     */
    private static function mergeStrongPerguntasIntoFaq(string $html): array
    {
        // Acha h2 FAQ
        if (!preg_match('/<h2[^>]*>\s*(?:perguntas?\s*frequentes?|FAQ|d[úu]vidas?[^<]*)\s*<\/h2>/iu', $html, $hm, PREG_OFFSET_CAPTURE)) {
            return ['html' => $html, 'movidas' => 0];
        }
        $faqH2Pos = $hm[0][1];
        $faqH2End = $hm[0][1] + strlen($hm[0][0]);
        $janelaInicio = max(0, $faqH2Pos - 8000);
        $bloco = substr($html, $janelaInicio, $faqH2Pos - $janelaInicio);

        // 3 padrões reais que Sonnet usa:
        //   A) <p><strong>P?</strong><br>R</p>   ← caso real #4982 (Sonnet usa esse)
        //   B) <p><strong>P?</strong></p><p>R</p>
        //   C) <strong>P?</strong><p>R</p>
        // Captura tudo num só regex usando alternativas. Grupos:
        //   (A) inline: pergunta=g1, respostaInline=g2
        //   (B/C) split: pergunta=g3, respostaP=g4
        $padrao = '#(?:<p\b[^>]*>\s*<strong>([^<]*\?)</strong>\s*<br\s*/?>\s*((?:(?!</p>).)+?)</p>)|(?:(?:<p\b[^>]*>\s*<strong>([^<]*\?)</strong>\s*</p>|<strong>([^<]*\?)</strong>)\s*(<p\b[^>]*>(?:(?!</p>).)*?</p>))#is';
        if (!preg_match_all($padrao, $bloco, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return ['html' => $html, 'movidas' => 0];
        }

        $movidas = 0;
        $detailsParaInserir = '';
        $remocoes = [];
        foreach ($mm as $match) {
            // Tipo A (inline com <br>)
            if (!empty($match[1][0])) {
                $perguntaTexto = trim((string)$match[1][0]);
                $respostaHtml = '<p>' . trim((string)$match[2][0]) . '</p>';
            } else {
                $perguntaTexto = trim((string)($match[3][0] ?: $match[4][0]));
                $respostaHtml = (string)($match[5][0] ?? '');
            }
            if ($perguntaTexto === '' || mb_strlen($perguntaTexto) < 10) continue;
            // Skip se já existe pergunta similar nos details
            if (self::perguntaJaExisteNoFaq($html, $perguntaTexto)) {
                // Só remove o duplicado do corpo, não migra
                $absStart = $janelaInicio + $match[0][1];
                $absLen = strlen($match[0][0]);
                $remocoes[] = [$absStart, $absLen];
                $movidas++;
                continue;
            }
            // Migra pro FAQ
            $detailsParaInserir .= "\n<details><summary>" . htmlspecialchars($perguntaTexto, ENT_QUOTES, 'UTF-8') . "</summary>" . trim($respostaHtml) . "</details>";
            $absStart = $janelaInicio + $match[0][1];
            $absLen = strlen($match[0][0]);
            $remocoes[] = [$absStart, $absLen];
            $movidas++;
        }

        if ($movidas === 0) return ['html' => $html, 'movidas' => 0];

        // Insere details novos APÓS h2 FAQ
        if ($detailsParaInserir !== '') {
            $html = substr($html, 0, $faqH2End) . $detailsParaInserir . substr($html, $faqH2End);
        }
        // Remove originais (de trás pra frente)
        usort($remocoes, fn($a, $b) => $b[0] <=> $a[0]);
        foreach ($remocoes as $rem) {
            [$start, $len] = $rem;
            $html = substr($html, 0, $start) . substr($html, $start + $len);
        }
        return ['html' => $html, 'movidas' => $movidas];
    }

    /** Verifica se pergunta similar já está nos <details> do FAQ (Jaccard 0.5). */
    private static function perguntaJaExisteNoFaq(string $html, string $pergunta): bool
    {
        if (!preg_match_all('#<details\b[^>]*>\s*<summary[^>]*>(.+?)</summary>#is', $html, $m)) return false;
        $tokensP = self::tokenizar(mb_strtolower($pergunta));
        if (count($tokensP) < 2) return false;
        foreach ($m[1] as $sumHtml) {
            $sumTxt = mb_strtolower(trim(strip_tags(html_entity_decode($sumHtml, ENT_QUOTES, 'UTF-8'))));
            $tokensS = self::tokenizar($sumTxt);
            if (count($tokensS) < 2) continue;
            if (self::jaccard($tokensP, $tokensS) >= 0.5) return true;
        }
        return false;
    }

    /**
     * Promove h3-perguntas órfãs próximas ao FAQ formal a <details>, dentro do bloco FAQ.
     * Janela: h3-perguntas dentro dos últimos 4000 chars antes do h2 "Perguntas frequentes".
     * Critério pra h3 ser pergunta: ter "?" OU começar com Quem/Qual/Como/Onde/Quando/Por que/O que.
     */
    private static function mergeOrphanQuestionsIntoFaq(string $html): array
    {
        // Acha o h2 FAQ
        if (!preg_match('/<h2[^>]*>\s*(?:perguntas?\s*frequentes?|FAQ|d[úu]vidas?[^<]*)\s*<\/h2>/iu', $html, $hm, PREG_OFFSET_CAPTURE)) {
            return ['html' => $html, 'movidas' => 0];
        }
        $faqH2Pos = $hm[0][1];
        $faqH2End = $hm[0][1] + strlen($hm[0][0]);
        $janelaInicio = max(0, $faqH2Pos - 4000);

        // Busca h3 + p IMEDIATAMENTE seguinte na janela
        $bloco = substr($html, $janelaInicio, $faqH2Pos - $janelaInicio);
        $padrao = '#<h3\b[^>]*>(.*?)</h3>(\s*<p\b[^>]*>(?:(?!</p>).)*?</p>)#is';
        if (!preg_match_all($padrao, $bloco, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return ['html' => $html, 'movidas' => 0];
        }

        $movidas = 0;
        $detailsParaInserir = '';
        $remocoes = [];
        foreach ($mm as $match) {
            $h3Inner = $match[1][0];
            $pHtml = $match[2][0];
            $h3Text = trim(strip_tags(html_entity_decode($h3Inner, ENT_QUOTES, 'UTF-8')));
            $ehPergunta = (
                substr_count($h3Text, '?') > 0 ||
                preg_match('/^(quem|qual|como|onde|quando|por\s+que|o\s+que)\b/iu', $h3Text)
            );
            if (!$ehPergunta) continue;
            if (substr_count($h3Text, '?') === 0) $h3Text .= '?';

            $pTexto = trim($pHtml);
            $detailsParaInserir .= "\n<details><summary>" . htmlspecialchars($h3Text, ENT_QUOTES, 'UTF-8') . "</summary>" . $pTexto . "</details>";
            $absStart = $janelaInicio + $match[0][1];
            $absLen = strlen($match[0][0]);
            $remocoes[] = [$absStart, $absLen];
            $movidas++;
        }

        if ($movidas === 0) return ['html' => $html, 'movidas' => 0];

        // Insere details APÓS o h2 FAQ
        $html = substr($html, 0, $faqH2End) . $detailsParaInserir . substr($html, $faqH2End);
        // Remove h3+p originais (de trás pra frente — antes do faqH2End que ficou intacto)
        usort($remocoes, fn($a, $b) => $b[0] <=> $a[0]);
        foreach ($remocoes as $rem) {
            [$start, $len] = $rem;
            $html = substr($html, 0, $start) . substr($html, $start + $len);
        }

        return ['html' => $html, 'movidas' => $movidas];
    }

    /**
     * Move bloco <!-- leia-tambem --> pra logo após o 1º <h2> do artigo (= depois da introdução).
     * Caso real #4747: leia-tambem ficava perto do FAQ. Posição correta é cedo no artigo
     * (engagement + linkagem antes do leitor abandonar).
     */
    private static function repositionarLeiaTambem(string $html): array
    {
        $movido = false;
        // Detecta bloco com markers HTML comment (preferido) ou div sem marker
        if (preg_match('/<!-- leia-tambem -->[\s\S]*?<!-- \/leia-tambem -->/', $html, $m, PREG_OFFSET_CAPTURE)) {
            $bloco = $m[0][0];
            $posBloco = $m[0][1];
        } elseif (preg_match('/<div\s+class=[\'"][^\'"]*\bleia-tambem\b[^\'"]*[\'"][^>]*>(?:(?!<\/div>).)*?<\/div>/is', $html, $m, PREG_OFFSET_CAPTURE)) {
            $bloco = $m[0][0];
            $posBloco = $m[0][1];
        } else {
            return ['html' => $html, 'movido' => false];
        }

        // Posição alvo: APÓS o fechamento do 1º <h2> + seu 1º parágrafo (deixa intro respirar antes do leia-também)
        // Ou seja: encontrar o 1º </h2>, então o próximo </p> depois dele
        if (!preg_match('/<h2[^>]*>.*?<\/h2>/is', $html, $h2m, PREG_OFFSET_CAPTURE)) {
            return ['html' => $html, 'movido' => false];
        }
        $posAposH2 = $h2m[0][1] + strlen($h2m[0][0]);
        // Pega 1º </p> APÓS o 1º h2
        if (!preg_match('/<\/p>/i', $html, $pm, PREG_OFFSET_CAPTURE, $posAposH2)) {
            // Se não tem p depois, insere logo após h2
            $posInsercao = $posAposH2;
        } else {
            $posInsercao = $pm[0][1] + strlen($pm[0][0]);
        }

        // Já está no lugar certo? (tolerância 200 chars)
        if (abs($posBloco - $posInsercao) < 200) {
            return ['html' => $html, 'movido' => false];
        }

        // Remove bloco da posição atual
        $htmlSemBloco = substr($html, 0, $posBloco) . substr($html, $posBloco + strlen($bloco));
        // Recalcula posInsercao se ele estava DEPOIS do bloco removido
        if ($posInsercao > $posBloco) $posInsercao -= strlen($bloco);
        // Insere
        $resultado = substr($htmlSemBloco, 0, $posInsercao) . "\n" . $bloco . "\n" . substr($htmlSemBloco, $posInsercao);
        return ['html' => $resultado, 'movido' => true];
    }

    private static function tokenizar(string $s): array
    {
        $s = trim($s);
        if ($s === '') return [];
        $s = strtr($s, 'áéíóúâêôàãõçÁÉÍÓÚÂÊÔÀÃÕÇ', 'aeiouaeoaaocAEIOUAEOAAOC');
        $s = preg_replace('/[^\w\s]/u', ' ', $s);
        if (!is_string($s)) return [];
        $parts = preg_split('/\s+/u', trim($s));
        if (!is_array($parts)) return [];
        $stopwords = ['de','da','do','das','dos','o','a','os','as','um','uma','e','ou','que','com','para','por','no','na','nos','nas','em','é','ser','estar','ter','tem','seu','sua','seus','suas','isso','esse','essa','este','esta','qual','como','onde','quando','por','que','quem','não','sim'];
        $tokens = array_filter(
            array_map('mb_strtolower', $parts),
            fn($t) => $t && mb_strlen($t) > 2 && !in_array($t, $stopwords, true)
        );
        return array_unique(array_values($tokens));
    }

    private static function jaccard(array $a, array $b): float
    {
        if (empty($a) || empty($b)) return 0.0;
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? $inter / $union : 0.0;
    }

    /**
     * Título permite msg-card APENAS se contém trigger de "lista de mensagens copiáveis".
     * Critério: artigos de Dia das Mães/Pais, parabéns, frases prontas, citações.
     */
    private static function tituloPermiteMsgCard(string $titulo): bool
    {
        $low = mb_strtolower($titulo, 'UTF-8');
        $triggers = [
            'mensagem', 'mensagens', 'frase', 'frases', 'citação', 'citações', 'citacao', 'citacoes',
            'homenagem', 'homenagens', 'declaração', 'declaracoes', 'declarações',
            'texto pronto', 'textos prontos', 'parabéns', 'parabens',
            'feliz aniversário', 'feliz aniversario', 'feliz natal', 'feliz ano novo',
            'dia das mães', 'dia das maes', 'dia dos pais', 'dia dos professores',
            'recado', 'recados', 'versículo', 'versiculos', 'versículos',
            'oração', 'oracao', 'orações', 'oracoes',
        ];
        foreach ($triggers as $t) {
            if (mb_strpos($low, $t) !== false) return true;
        }
        return false;
    }

    /**
     * Mapa frase banida → substituto (ou '' pra remover).
     * Mantém vivo só substitutos que não ativam outros gatilhos do AntiAI.
     */
    private static function mapaSubstituicoes(): array
    {
        return [
            // ── Conectores robóticos ──
            'em resumo' => 'resumindo',
            'em síntese' => 'resumindo',
            'em conclusão' => 'pra fechar',
            'em última análise' => '',
            'em última instância' => '',
            'em contrapartida' => 'em troca',
            'em contrapartida a isso' => 'em troca',
            'diante disso' => '',
            'diante desse cenário' => '',
            'diante desse contexto' => '',
            'diante de tudo isso' => '',
            'diante do exposto' => '',
            'vale destacar' => '',
            'vale ressaltar' => '',
            'vale lembrar' => '',
            'vale mencionar' => '',
            'vale notar' => '',
            'vale observar' => '',
            'vale a pena destacar' => '',
            'vale a pena mencionar' => '',
            'vale a pena lembrar' => '',
            'vale dizer' => '',
            'vale comentar' => '',
            'cabe destacar' => '',
            'cabe ressaltar' => '',
            'cabe mencionar' => '',
            'cabe lembrar' => '',
            'cabe pontuar' => '',
            'é importante destacar' => '',
            'é importante ressaltar' => '',
            'é importante mencionar' => '',
            'é importante lembrar' => '',
            'é importante notar' => '',
            'é importante observar' => '',
            'é fundamental destacar' => '',
            'é fundamental ressaltar' => '',
            'é fundamental lembrar' => '',
            'é essencial destacar' => '',
            'é essencial ressaltar' => '',
            'nesse contexto' => '',
            'neste contexto' => '',
            'nesse sentido' => '',
            'neste sentido' => '',
            'nesse cenário' => '',
            'neste cenário' => '',
            'nesse aspecto' => '',
            'neste aspecto' => '',
            'sob esse prisma' => '',
            'sob essa ótica' => '',
            'sob essa perspectiva' => '',
            'dessa forma' => 'assim',
            'dessa maneira' => 'assim',
            'desse modo' => 'assim',
            'desse jeito' => 'assim',
            'dessa feita' => 'assim',
            'portanto' => 'por isso',
            'por conseguinte' => 'por isso',
            'por essa razão' => 'por isso',
            'por esse motivo' => 'por isso',
            'ademais' => 'também',
            'outrossim' => 'também',
            'dito isso' => '',
            'isto posto' => '',

            // ── Clichês de abertura ──
            'a verdade é que' => '',
            'mas tem um detalhe que quase ninguém' => '',
            'mas tem um detalhe que muita gente' => '',
            'mas tem um detalhe que ninguém' => '',
            'e é aqui que muita gente erra' => '',
            'é aqui que a maioria erra' => '',
            'a maioria descobre tarde demais' => '',
            'só que isso muda tudo' => '',
            'simples assim' => '',
            // Mais clichês "o que X / X percebe" — frequentes em geração 04/05
            'o que ninguém te conta' => '',
            'o que ninguem te conta' => '',
            'o que ninguém imagina' => '',
            'o que ninguem imagina' => '',
            'o que quase ninguém percebe' => '',
            'o que quase ninguem percebe' => '',
            'o que poucos sabem' => '',
            'o que poucos percebem' => '',
            'o que muita gente desconhece' => '',
            'o que muita gente ignora' => '',
            'o que pouca gente sabe' => '',
            'o que pouca gente percebe' => '',
            'isso que ninguém' => 'isso que',
            'isso que poucos' => 'isso que',

            // ── Template narrativo LLM ──
            'tem gente que' => '',
            'tem gente em' => '',
            'fica de fora' => 'perde a vaga',
            'ficou de fora' => 'perdeu a vaga',
            'fiquem de fora' => 'percam a vaga',
            'quem tenta' => '',
            'quem busca' => '',
            'quem espera' => '',
            'quem precisa' => '',
            'quem chega' => '',
            'descobre rapidamente' => 'descobre',
            'descobre logo' => 'descobre',
            'animada com a vaga' => '',
            'empolgado com a vaga' => '',
            'esperançoso com' => '',

            // ── Teasers isolados ──
            'mas tem um detalhe' => '',
            'tem um porém' => '',
            'tem um detalhe importante' => '',
            'mas atenção' => '',
            'mas calma' => '',
            'mas espera' => '',
            'mas tem mais' => '',
            'aí entra o problema' => '',
            'aí está o ponto' => '',
            'aí está o detalhe' => '',
            'e não para por aí' => '',
            'e não acaba aí' => '',
            'e tem mais' => '',
            'mas a história não termina' => '',
            'a história não para' => '',
            'eis o ponto' => '',
            'eis a questão' => '',
            'eis o detalhe' => '',

            // ── Fillers narrativos ──
            'na prática' => '',
            'na real' => '',
            'no fim das contas' => '',
            'no final das contas' => '',
            'logo de cara' => '',
            'já de cara' => '',
            'rapidamente' => '',
            'mesmo assim' => '',
            'sem perceber' => '',
            'sem nem perceber' => '',
            'acaba descobrindo' => 'descobre',
            'acabam descobrindo' => 'descobrem',

            // ── Promessa vaga ──
            'esse detalhe' => 'essa parte',
            'esse erro' => 'esse engano',
            'esse ponto' => 'essa parte',
            'esse problema' => 'isso',
            'esse critério' => 'esse requisito',
            'esse filtro' => 'essa exigência',

            // ── Jargão acadêmico desnecessário (PhD-USP é simples + preciso) ──
            // Caso real #4805: Sonnet caiu em academiquês qd pediram tom PhD-USP.
            // Substitutos mantêm precisão sem fricção cognitiva.
            'no âmbito de' => 'em',
            'no âmbito do' => 'no',
            'no âmbito da' => 'na',
            'no escopo de' => 'em',
            'no escopo do' => 'no',
            'à luz de' => 'segundo',
            'à luz do' => 'segundo o',
            'à luz da' => 'segundo a',
            'em conformidade com' => 'segundo',
            'tem-se que' => '',
            'faz-se necessário' => 'é preciso',
            'convém ressaltar' => '',
            'convém destacar' => '',
            'convém mencionar' => '',
            'no que tange' => 'sobre',
            'no que tange a' => 'sobre',
            'no que se refere a' => 'sobre',
            'no que diz respeito a' => 'sobre',
            'cumpre destacar' => '',
            'cumpre mencionar' => '',
            'cumpre ressaltar' => '',
            'mister se faz' => 'é preciso',
            'configura uma inflexão' => 'é uma mudança',
            'configura inflexão' => 'é mudança',
            'constitui um avanço estruturante' => 'é avanço',
            'constitui marco' => 'é marco',
            'cria uma assimetria' => 'cria desequilíbrio',
            'cria assimetria' => 'desequilibra',
            'implica necessidade de' => 'obriga a',
            'implica obrigatoriedade de' => 'obriga a',
            'regime jurídico de exceção' => 'regra excepcional',
            'instrumento de política' => 'ferramenta de política',
            'como instrumento de' => 'como ferramenta de',
            'enquanto instrumento' => 'como ferramenta',

            // ── Clickbait title comum (escapa h1 muitas vezes) ──
            'a resposta que muda tudo' => '',
            'a resposta surpreende' => '',
            'que muda tudo em' => 'em',
            'que muda tudo para' => 'para',

            // ── Tom edital institucional → tom guia (jornalismo) ──
            // Sonnet copia linguagem do edital quando fonte é gov.br/mec.
            'será divulgada pelo' => 'sai pelo',
            'será divulgado pelo' => 'sai pelo',
            'será divulgada pela' => 'sai pela',
            'será divulgado pela' => 'sai pela',
            'será publicada no diário oficial' => 'sai no Diário Oficial',
            'será publicado no diário oficial' => 'sai no Diário Oficial',
            'no momento oportuno' => '',
            'em data a ser definida' => 'em data ainda não definida',
            'oportunamente será' => 'será',
            'serão informadas oportunamente' => 'saem mais pra frente',
            'serão informados oportunamente' => 'saem mais pra frente',

            // ── Verbos eliminação genéricos (fingerprint LLM em educação/benefícios) ──
            // CASO real #4796: padrão "pode barrar"/"elimina"/"impede" sem qualificador
            // concreto vira marca de IA aggressive/genérica em conteúdos de
            // vagas/concursos/programas sociais. Se há entidade específica (art. 4.2,
            // idade <18, CadÚnico irregular), Sonnet reescreve com isso. Aqui só os vagos.
            'pode barrar candidatos' => 'afeta candidatos',
            'pode barrar inscrições' => 'afeta inscrições',
            'pode barrar a inscrição' => 'afeta a inscrição',
            'pode barrar pessoas' => 'afeta pessoas',
            'pode barrar' => 'afeta',
            'pode eliminar candidatos' => 'afeta candidatos',
            'pode eliminar a inscrição' => 'afeta a inscrição',
            'pode eliminar inscrições' => '',
            'pode impedir candidatos' => '',
            'pode impedir inscrições' => '',
            'pode impedir a inscrição' => '',
            'pode impedir o candidato' => '',
            'que barra candidatos' => 'que afeta candidatos',
            'que barra inscrições' => 'que afeta inscrições',
            'que barra a inscrição' => 'que afeta a inscrição',
            'que elimina candidatos' => '',
            'que elimina inscrições' => '',
            'que elimina a inscrição' => '',
            'que impede candidatos' => '',
            'que impede inscrições' => '',
            'que impede a inscrição' => '',
            'critério que pode' => 'critério',
            'regra que pode' => 'regra',
            'regra que impede' => 'regra de',
            'erro que elimina' => 'erro:',
            'erro que barra' => 'erro:',
            'filtro que barra' => 'filtro:',
            'filtro que elimina' => 'filtro:',
            'detalhe que elimina' => 'detalhe:',
            'detalhe que barra' => 'detalhe:',
            // Padrões "o X que / um X que" — vague_promise sem qualificador concreto
            'o erro que' => 'o engano que',
            'um erro que' => 'um engano que',
            'o detalhe que' => 'a parte que',
            'um detalhe que' => 'a parte que',
            'o problema que' => 'a questão que',
            'um problema que' => 'a questão que',
            'o ponto que' => 'a parte que',
            'um ponto que' => 'a parte que',
            'o critério que' => 'o requisito que',
            'um critério que' => 'um requisito que',
            'o filtro que' => 'a exigência que',
            'um filtro que' => 'uma exigência que',

            // ── Adjetivos vazios isolados ──
            'imperdível' => '',
            'incrível' => '',
            'revolucionário' => '',
            'surpreendente' => '',
            'transformador' => '',
            'magnífico' => '',
            'extraordinário' => '',
            'memorável' => '',

            // ── Self-reference ──
            'veja a seguir' => '',
            'veja abaixo' => '',
            'veja agora' => '',
            'confira a seguir' => '',
            'confira abaixo' => '',
            'confira agora' => '',
            'leia a seguir' => '',
            'leia abaixo' => '',
            'leia adiante' => '',
            'descubra a seguir' => '',
            'descubra abaixo' => '',
            'descubra agora' => '',
            'clique aqui' => '',
            'clique abaixo' => '',
            'continue lendo abaixo' => '',
            'continue a leitura' => '',
            'siga lendo' => '',

            // ── Gerundismo ──
            'estar fazendo' => 'fazer',
            'estarão recebendo' => 'vão receber',
            'estarão analisando' => 'vão analisar',
            'estará buscando' => 'vai buscar',
            'estará realizando' => 'vai realizar',
            'vai estar acompanhando' => 'vai acompanhar',
            'vai estar verificando' => 'vai verificar',

            // ── Pomposo ──
            'outrora' => 'antes',
            'doravante' => 'a partir de agora',
            'destarte' => 'assim',

            // ── Filler genérico ──
            'de forma geral' => '',
            'de modo geral' => '',
            'de qualquer maneira' => '',
            'de qualquer forma' => '',
            'seja como for' => '',
            'em todo caso' => '',
            'em todo o caso' => '',

            // ── Meta-narrativa ──
            'continue lendo' => '',
            'continue acompanhando' => '',
            'antes de mais nada' => '',
            'fica a dica' => '',
        ];
    }
}
