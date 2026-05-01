<?php
/**
 * Helpers de prompt + overlay pra geração de imagem (DALL-E).
 *
 * Extraído de gerarpost.php pra que scripts CLI possam reutilizar
 * sem disparar o flow HTTP do gerarpost.
 *
 * Funções públicas:
 *   - clonais_persona_por_tema(keyword, contexto) → persona/cenário/objeto/device_text
 *   - clonais_split_overlay_sticker(overlay) → ['line1', 'line2']
 *   - clonais_extrair_sublabel(titulo, contexto) → string deadline ou ''
 *   - construirPromptImagem(titulo, keyword, contexto, claudeImagemPrompt, overlay) → string prompt
 *   - clonais_derivar_overlay(titulo, excerpt, metaDesc, overlayClaude) → string 6-8 palavras
 *   - limparTituloFonte(titulo) → string sem sufixo do portal
 */

if ( ! function_exists('clonais_persona_por_tema') ) {
    function clonais_persona_por_tema(string $keyword, string $contexto): array
    {
        $kw = mb_strtolower($keyword . ' ' . $contexto, 'UTF-8');

        if (preg_match('/inss|aposenta|13[ºo°]?\s*sal|previd[êe]nci|benef[íi]cio.*idoso/u', $kw)) {
            return [
                'person'      => 'a Brazilian senior citizen (around 65 years old, grey hair, warm relieved smile, casual polo shirt)',
                'scenario'    => 'a cozy Brazilian middle-class home living room with afternoon sunlight from a window',
                'object'      => 'a smartphone',
                'device_text' => 'BENEFÍCIO LIBERADO',
            ];
        }
        if (preg_match('/bolsa\s+fam[íi]lia|cad[úu]nico|aux[íi]lio\s+brasil/u', $kw)) {
            return [
                'person'      => 'a Brazilian working-class mother in her 30s with a warm relieved smile, simple casual outfit',
                'scenario'    => 'a simple Brazilian kitchen with afternoon sunlight, family photos visible',
                'object'      => 'a smartphone showing a banking app',
                'device_text' => 'CALENDÁRIO 2026',
            ];
        }
        if (preg_match('/p[ée]\s*-?\s*de\s*-?\s*meia|estudante\s+(ensino|escola)|ensino\s+m[ée]dio/u', $kw)) {
            return [
                'person'      => 'a Brazilian high-school student in their late teens (school-style outfit, hopeful confident expression)',
                'scenario'    => 'a Brazilian public school courtyard or modern classroom with peers blurred in soft bokeh',
                'object'      => 'a smartphone showing the gov.br interface',
                'device_text' => 'BENEFÍCIO ESTUDANTE',
            ];
        }
        if (preg_match('/enem|sisu|fies|prouni|fuvest|vestibular/u', $kw)) {
            return [
                'person'      => 'a Brazilian college-age student (18-22 years old, focused confident expression, casual student outfit)',
                'scenario'    => 'a modern Brazilian university campus or library with shelves blurred in bokeh',
                'object'      => 'a tablet showing course enrollment',
                'device_text' => 'INSCRIÇÃO ABERTA',
            ];
        }
        if (preg_match('/senac|sesi|sesc|senai|t[ée]cnic|profissionalizant/u', $kw)) {
            return [
                'person'      => 'a young Brazilian apprentice in their 20s wearing a clean professional uniform, focused and confident',
                'scenario'    => 'a modern professional training workshop or laboratory with equipment blurred in bokeh',
                'object'      => 'a tablet showing the course portal',
                'device_text' => 'CURSO GRÁTIS',
            ];
        }
        if (preg_match('/concurso|edital|prova\s+p[úu]blic|servidor\s+p[úu]blic/u', $kw)) {
            return [
                'person'      => 'a focused Brazilian adult professional in their 30s with an aspirational concentrated expression, smart-casual outfit',
                'scenario'    => 'a clean modern Brazilian home office with study materials and a bookshelf blurred in bokeh',
                'object'      => 'a tablet showing the official notice',
                'device_text' => 'EDITAL ABERTO',
            ];
        }
        if (preg_match('/fgts|saque|caixa\s+tem|fundo\s+de\s+garantia|pis\b|pasep/u', $kw)) {
            return [
                'person'      => 'a Brazilian working adult in their 40s with a relieved confident smile, casual polo or button-up shirt',
                'scenario'    => 'a clean modern Brazilian home with neutral tones and natural light from a window',
                'object'      => 'a smartphone showing the Caixa Tem app interface',
                'device_text' => 'SAQUE LIBERADO',
            ];
        }
        if (preg_match('/vagas?\b|emprego|contrata\w*|recrutament|sine\b/u', $kw)) {
            return [
                'person'      => 'a confident Brazilian professional in their 30s, smiling, smart-casual outfit',
                'scenario'    => 'a modern Brazilian office or coworking space with people blurred in soft bokeh background',
                'object'      => 'a smartphone',
                'device_text' => 'VAGAS ABERTAS',
            ];
        }
        if (preg_match('/sus\b|sa[úu]de|m[ée]dic|hospital|exame/u', $kw)) {
            return [
                'person'      => 'a Brazilian patient or healthcare worker (uniform, around 35 years old, kind professional expression)',
                'scenario'    => 'a clean modern Brazilian public health clinic environment with neutral tones',
                'object'      => 'a smartphone showing the Meu SUS Digital app',
                'device_text' => 'SUS DIGITAL',
            ];
        }
        $devText = strtoupper(mb_substr(trim($keyword) ?: 'CONFIRA', 0, 15, 'UTF-8'));
        return [
            'person'      => 'a Brazilian adult in their 30s with a warm confident expression, smart-casual outfit',
            'scenario'    => 'a clean modern Brazilian environment with soft natural light and elements relevant to the article topic, blurred in bokeh',
            'object'      => 'a smartphone',
            'device_text' => $devText,
        ];
    }
}

if ( ! function_exists('clonais_split_overlay_sticker') ) {
    function clonais_split_overlay_sticker(string $overlay): array
    {
        $palavras = preg_split('/\s+/', trim(str_replace('·', '', $overlay)));
        $palavras = array_values(array_filter($palavras, fn($p) => $p !== ''));
        $n = count($palavras);

        if ($n === 0) {
            return ['line1' => 'OPORTUNIDADE', 'line2' => 'CONFIRA'];
        }
        if ($n === 1) {
            return ['line1' => mb_strtoupper($palavras[0], 'UTF-8'), 'line2' => 'CONFIRA'];
        }
        $cut = (int) ceil($n / 2);
        return [
            'line1' => mb_strtoupper(implode(' ', array_slice($palavras, 0, $cut)), 'UTF-8'),
            'line2' => mb_strtoupper(implode(' ', array_slice($palavras, $cut)), 'UTF-8'),
        ];
    }
}

if ( ! function_exists('clonais_extrair_sublabel') ) {
    function clonais_extrair_sublabel(string $titulo, string $contexto): string
    {
        $texto = $titulo . ' ' . $contexto;

        if (preg_match('/at[ée]\s+(\d{1,2})[\/.](\d{1,2})/iu', $texto, $m)) {
            return 'INSCRIÇÕES ATÉ ' . str_pad($m[1], 2, '0', STR_PAD_LEFT) . '/' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/at[ée]\s+(\d{1,2})\s+de\s+(janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)/iu', $texto, $m)) {
            return 'PRAZO: ' . $m[1] . ' DE ' . mb_strtoupper($m[2], 'UTF-8');
        }
        if (preg_match('/(?:encerra|fecha|termina|acaba|expira)\w*\s+(?:em\s+)?(\d+)\s+(dias?|horas?|semanas?)/iu', $texto, $m)) {
            return 'ÚLTIMOS ' . $m[1] . ' ' . mb_strtoupper($m[2], 'UTF-8');
        }
        if (preg_match('/[úu]ltim[ao]\s+(semana|chance|dia|hora|prazo)/iu', $texto, $m)) {
            return 'ÚLTIMA ' . mb_strtoupper($m[1], 'UTF-8');
        }
        return '';
    }
}

if ( ! function_exists('limparTituloFonte') ) {
    function limparTituloFonte(string $titulo): string
    {
        $titulo = trim($titulo);
        if ($titulo === '') return $titulo;
        $separadores = [' | ', ' - ', ' – ', ' — ', ' :: ', ' · '];
        foreach ($separadores as $sep) {
            $pos = mb_strrpos($titulo, $sep);
            if ($pos === false) continue;
            $antes = trim(mb_substr($titulo, 0, $pos));
            $depois = trim(mb_substr($titulo, $pos + mb_strlen($sep)));
            $lenDepois = mb_strlen($depois);
            if ($lenDepois < 2 || $lenDepois > 50) continue;
            if (preg_match('/[.!?]/', $depois)) continue;
            $palavrasAntes = count(preg_split('/\s+/', $antes) ?: []);
            if (mb_strlen($antes) < 20 && $palavrasAntes < 4) continue;
            return $antes;
        }
        return $titulo;
    }
}

if ( ! function_exists('clonais_derivar_overlay') ) {
    /**
     * Gera overlay 6-8 palavras COMPLEMENTAR ao título (não duplica).
     *
     * Estratégia:
     *   1. Claude prioritário (se 6-8 palavras, usa)
     *   2. Detecta tipo do conteúdo (curso_concluido, inscricoes, vagas, beneficio, concurso)
     *   3. Extrai palavras-chave do TÍTULO a EVITAR (acrônimos, números, locais)
     *   4. Tenta achar dado COMPLEMENTAR no contexto (deadline/valor/escala que título NÃO tem)
     *   5. Combina dado + CTA de tipo, garantindo zero conflito com palavras do título
     *   6. Fallback: template CTA puro de tipo
     */
    function clonais_derivar_overlay(string $titulo, string $excerpt = '', string $metaDesc = '', string $overlayClaude = ''): string
    {
        $contarPalavras = static function (string $s): int {
            $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
            if ($s === '') return 0;
            return count(preg_split('/\s+/', $s) ?: []);
        };

        // 1) Claude prioritário
        $claude = trim($overlayClaude);
        if ($claude !== '') {
            $cw = $contarPalavras($claude);
            if ($cw >= 6 && $cw <= 8) {
                return mb_strtoupper($claude, 'UTF-8');
            }
        }

        $titulo = trim($titulo);
        $contextoTotal = trim($titulo . ' ' . $excerpt . ' ' . $metaDesc);
        if ($contextoTotal === '') return '';

        // 2) Detecta tipo do conteúdo
        $tipo = clonais_detectar_tipo_overlay($titulo, $excerpt . ' ' . $metaDesc);

        // 3) Extrai palavras a EVITAR (já no título)
        $stopwords = ['o','a','os','as','um','uma','de','do','da','dos','das','no','na','nos','nas','em','para','pra','por','com','sem','que','e','ou','é','são','foi','vai','tem','até','este','esse','esta','essa','aqui','ali'];
        $stopMap = array_flip($stopwords);
        $palavrasTitulo = preg_split('/\s+/', preg_replace('/[":;|.!?·\(\)]/u', ' ', limparTituloFonte($titulo)) ?? '');
        $palavrasEvitar = [];
        foreach ($palavrasTitulo as $p) {
            $p = trim($p);
            if ($p === '' || mb_strlen($p, 'UTF-8') < 3) continue;
            $low = mb_strtolower($p, 'UTF-8');
            if (isset($stopMap[$low])) continue;
            // Normaliza: tira acentos e símbolos pra comparação
            $norm = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $p)) ?: $p);
            if ($norm !== '') $palavrasEvitar[] = $norm;
        }

        // 4) Tenta extrair dado COMPLEMENTAR ao título (do excerpt + metaDesc apenas)
        $contextoExtra = trim($excerpt . ' ' . $metaDesc);
        $angulos = [];

        // Detecta o que o TÍTULO já tem
        $tituloTemValor   = (bool) preg_match('/R\$\s*[\d.,]+|sal[áa]rio/iu', $titulo);
        $tituloTemPrazo   = (bool) preg_match('/at[ée]\s+\d|encerra|fecha\b|\d+\s+dias?|prazo|deadline|esta\s+(semana|sexta)/iu', $titulo);
        $tituloTemEscala  = (bool) preg_match('/\d+\s*(mil|milh|bi|vagas|estudantes|beneficiári|alunos)/iu', $titulo);

        // Deadline (só se título não tem)
        if (!$tituloTemPrazo) {
            if (preg_match('/at[ée]\s+(\d{1,2})[\/.](\d{1,2})/iu', $contextoExtra, $m)) {
                $angulos['deadline'] = 'ATÉ ' . str_pad($m[1], 2, '0', STR_PAD_LEFT) . '/' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
            } elseif (preg_match('/at[ée]\s+(\d{1,2})\s+de\s+(janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)/iu', $contextoExtra, $m)) {
                $angulos['deadline'] = 'ATÉ ' . $m[1] . ' DE ' . mb_strtoupper($m[2], 'UTF-8');
            } elseif (preg_match('/[úu]ltim[ao]\s+(semana|chance|dia|hora|prazo)/iu', $contextoExtra, $m)) {
                $angulos['deadline'] = 'ÚLTIMA ' . mb_strtoupper($m[1], 'UTF-8');
            }
        }

        // Valor (só se título não tem)
        if (!$tituloTemValor) {
            if (preg_match('/R\$\s*([\d][\d.,]*)\s*(mil|milh[õo]es?|bi)?/iu', $contextoExtra, $m)) {
                $unidade = isset($m[2]) && $m[2] !== '' ? ' ' . mb_strtoupper(substr($m[2], 0, 3), 'UTF-8') : '';
                $angulos['valor'] = 'R$ ' . $m[1] . $unidade;
            }
        }

        // Escala (só se título não tem)
        if (!$tituloTemEscala) {
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(mil|milh[õo]es?)?\s+(vagas?|estudantes?|beneficiári\w+|alunos?)/iu', $contextoExtra, $m)) {
                $num = $m[1];
                $escala = isset($m[2]) && $m[2] !== '' ? ' ' . mb_strtoupper(substr($m[2], 0, 3), 'UTF-8') : '';
                $sub = mb_strtoupper($m[3], 'UTF-8');
                $angulos['escala'] = $num . $escala . ' ' . $sub;
            }
        }

        // 5) Constrói overlay: [DADO COMPLEMENTAR] + [CTA TEMPLATE TIPO]
        $cta = clonais_overlay_cta_template($tipo, $palavrasEvitar);
        $partes = [];

        if (!empty($angulos['deadline'])) $partes[] = $angulos['deadline'];
        elseif (!empty($angulos['valor'])) $partes[] = $angulos['valor'];
        elseif (!empty($angulos['escala'])) $partes[] = $angulos['escala'];

        if ($cta !== '') $partes[] = $cta;

        $combinado = implode(' · ', $partes);
        $cw = $contarPalavras($combinado);

        // Garante 6-8 palavras
        if ($cw < 6) {
            // Adiciona keyword complementar do contexto (não-conflitante)
            $kwComplemento = clonais_keyword_complementar($contextoExtra, $palavrasEvitar);
            if ($kwComplemento !== '') {
                $combinado = trim($combinado . ' ' . $kwComplemento);
                $cw = $contarPalavras($combinado);
            }
        }

        // Trim a 8 palavras
        if ($cw > 8) {
            $palavras = preg_split('/\s+/', $combinado);
            $combinado = implode(' ', array_slice($palavras, 0, 8));
        }

        // Último recurso
        if ($contarPalavras($combinado) < 6) {
            $combinado = $cta !== '' ? $cta : 'CONFIRA O CONTEÚDO · ENTENDA AGORA';
        }

        return mb_strtoupper($combinado, 'UTF-8');
    }
}

if ( ! function_exists('clonais_detectar_tipo_overlay') ) {
    /**
     * Detecta o "tipo" do conteúdo pra escolher template de CTA.
     * Tipos: curso_concluido, curso_inscricoes, vagas, beneficio, concurso, default.
     */
    function clonais_detectar_tipo_overlay(string $titulo, string $contexto): string
    {
        $texto = mb_strtolower($titulo . ' ' . $contexto, 'UTF-8');

        if (preg_match('/\b(termina(?:m|ram)?|conclui(?:ram)?|conclu[ií]ram|formaram|formatura|encerraram|graduaram|trainee\s+formado)/u', $texto)) {
            return 'curso_concluido';
        }
        if (preg_match('/\b(inscri[çc][ãa]o|inscri[çc][õo]es|edital\s+aberto|matr[íi]cula|abrem|abriram|nova\s+turma|turma\s+nova)/u', $texto)) {
            return 'curso_inscricoes';
        }
        if (preg_match('/\b(vagas?\s+aberta|contratam|recrutam|emprego|sine\b)/u', $texto)) {
            return 'vagas';
        }
        if (preg_match('/\b(libera\s+|paga\s+|recebem|calend[áa]rio|saque|13[ºo°]?\s*sal|inss\b|fgts\b|caixa\s+tem)/u', $texto)) {
            return 'beneficio';
        }
        if (preg_match('/\bconcurso|edital\s+(?:de|do|da)\s+|servidor\s+p[úu]blic/u', $texto)) {
            return 'concurso';
        }
        return 'default';
    }
}

if ( ! function_exists('clonais_overlay_cta_template') ) {
    /**
     * Templates de CTA por tipo. Escolhe primeiro que NÃO conflita com palavras do título.
     */
    function clonais_overlay_cta_template(string $tipo, array $palavrasEvitar): string
    {
        $templates = [
            'curso_concluido' => [
                'CARREIRA NO MERCADO',
                'NOVA TURMA EM BREVE',
                'PRÁTICA REAL · APRENDA COMO',
                'FORMAÇÃO COMPLETA · ENTRE NA PRÓXIMA',
                'MERCADO PRONTO · DESCUBRA O CURSO',
            ],
            'curso_inscricoes' => [
                'GARANTA SUA VAGA AGORA',
                'NÃO DEIXE PASSAR · MATRICULE-SE',
                'CURSO GRATUITO · ENTRE HOJE',
                'ABERTO · INSCRIÇÕES LIMITADAS',
            ],
            'vagas' => [
                'ENVIE CV AGORA',
                'OPORTUNIDADE ABERTA · GARANTA',
                'CONTRATAÇÃO RÁPIDA · APLIQUE-SE',
            ],
            'beneficio' => [
                'PAGAMENTO LIBERADO',
                'CONFIRA NO APP OFICIAL',
                'CALENDÁRIO 2026 · NÃO PERCA',
            ],
            'concurso' => [
                'EDITAL ABERTO · PREPARE-SE AGORA',
                'CARREIRA PÚBLICA · SALÁRIO ESTÁVEL',
                'PROVA EM BREVE · ESTUDE JÁ',
            ],
            'default' => [
                'CONFIRA AGORA · ENTENDA TUDO',
                'NÃO DEIXE PARA DEPOIS',
            ],
        ];

        $list = $templates[$tipo] ?? $templates['default'];
        foreach ($list as $opcao) {
            $palavrasOpcao = preg_split('/\s+/', $opcao);
            $conflito = false;
            foreach ($palavrasOpcao as $w) {
                $norm = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $w)) ?: $w);
                if ($norm !== '' && mb_strlen($norm) >= 4 && in_array($norm, $palavrasEvitar, true)) {
                    $conflito = true; break;
                }
            }
            if (!$conflito) return $opcao;
        }
        return $list[0];
    }
}

if ( ! function_exists('clonais_keyword_complementar') ) {
    /**
     * Pega 1-2 palavras de IMPACTO do contexto (excerpt+metaDesc) que NÃO estão
     * nas palavras-evitar. Usado pra completar overlay quando ângulos vazios.
     */
    function clonais_keyword_complementar(string $contexto, array $palavrasEvitar): string
    {
        $stopwords = ['o','a','os','as','um','uma','de','do','da','dos','das','no','na','nos','nas','em','para','pra','por','com','sem','que','e','ou','é','são','foi','vai','tem','sem','até','também','já','ainda','mais','muito','desta','deste','sobre','quando','também','será','ser','este','esta'];
        $stopMap = array_flip($stopwords);
        $palavras = preg_split('/\s+/', preg_replace('/[":;|.!?·\(\)]/u', ' ', $contexto) ?? '');
        $picks = [];
        foreach ($palavras as $p) {
            $p = trim($p);
            if (mb_strlen($p, 'UTF-8') < 4) continue;
            $low = mb_strtolower($p, 'UTF-8');
            if (isset($stopMap[$low])) continue;
            $norm = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $p)) ?: $p);
            if (in_array($norm, $palavrasEvitar, true)) continue;
            $picks[] = mb_strtoupper($p, 'UTF-8');
            if (count($picks) >= 2) break;
        }
        return implode(' ', $picks);
    }
}

if ( ! function_exists('clonais_extrair_info_badges') ) {
    /**
     * Extrai até 3 info-badges do título + contexto pra desenhar nas cápsulas verdes.
     * Cada badge: ['label' => 'TÍTULO', 'value' => 'subtítulo']
     *
     * Prioridade de detecção: tipo de curso > duração > taxa > local > período > vagas
     */
    function clonais_extrair_info_badges(string $titulo, string $contexto): array
    {
        $texto = $titulo . ' ' . $contexto;
        $badges = [];

        // === DURAÇÃO + CARGA HORÁRIA ===
        $duracao = '';
        $carga = '';
        if (preg_match('/(\d+)\s*(meses?|anos?|semanas?)/iu', $texto, $m)) {
            $duracao = "DURAÇÃO: {$m[1]} " . mb_strtoupper($m[2], 'UTF-8');
        }
        if (preg_match('/(\d+)\s*h(?:oras?)?\b/iu', $texto, $m)) {
            $carga = $m[1] . ' HORAS';
        } elseif (preg_match('/carga\s+hor[áa]ria\s+de\s+(\d+)/iu', $texto, $m)) {
            $carga = $m[1] . ' HORAS';
        }
        $periodo = '';
        if (preg_match('/\b(noturno|matutino|vespertino|integral|ead|on-?line|h[íi]brido)\b/iu', $texto, $m)) {
            $periodo = mb_strtoupper($m[1], 'UTF-8');
        }
        if ($duracao !== '' || $carga !== '') {
            $valor = trim(($carga !== '' ? $carga : '') . ($carga !== '' && $periodo !== '' ? ' · ' : '') . ($periodo !== '' ? $periodo : ''));
            if ($valor === '') { $valor = $periodo ?: 'CONFIRA'; }
            $badges['duracao'] = [
                'label' => $duracao !== '' ? $duracao : 'DURAÇÃO',
                'value' => $valor !== '' ? $valor : 'CONFIRA',
                'icon'  => 'clock',
            ];
        }

        // === TAXA + ISENÇÃO ===
        if (preg_match('/(?:taxa|valor|investimento|R\$)[\s:]*(?:de\s+)?R?\$?\s*([\d.,]+)/iu', $texto, $m)) {
            $valor = 'R$ ' . trim($m[1], '.,');
            $sub = '';
            if (preg_match('/isen[çc][ãa]o\s+at[ée]\s+(\d{1,2}[\/\.\-]\d{1,2})/iu', $texto, $m2)) {
                $sub = 'ISENÇÃO ATÉ ' . str_replace(['.','-'], '/', $m2[1]);
            } elseif (stripos($texto, 'gratuit') !== false || stripos($texto, 'grátis') !== false) {
                $valor = 'GRATUITO';
                $sub = 'SEM CUSTO';
            }
            $badges['taxa'] = [
                'label' => "TAXA: {$valor}",
                'value' => $sub ?: 'CONFIRA EDITAL',
                'icon'  => 'money',
            ];
        } elseif (stripos($texto, 'gratuit') !== false || stripos($texto, 'grátis') !== false) {
            $badges['taxa'] = [
                'label' => 'GRATUITO',
                'value' => 'SEM TAXA DE INSCRIÇÃO',
                'icon'  => 'money',
            ];
        }

        // === TIPO DE CURSO + LOCAL ===
        $tipo = '';
        if (preg_match('/\b(especializa[çc][ãa]o|p[óo]s[\s\-]gradua[çc][ãa]o|gradua[çc][ãa]o|t[ée]cnico|profissionalizant\w+|qualifica[çc][ãa]o|aperfei[çc]oament\w+|extens[ãa]o|capacita[çc][ãa]o)\b/iu', $texto, $m)) {
            $tipo = mb_strtoupper($m[1], 'UTF-8');
        }
        $area = '';
        // Captura curso COMPLETO com até 4 palavras + conectores (de|do|da|em|e)
        // Ex: "Técnico em Guia de Turismo", "Especialização em Gestão de Negócios"
        $padroesCurso = [
            '/\b(t[ée]cnico\s+em\s+[\wÀ-ÿ]+(?:\s+(?:de|do|da|em|e)\s+[\wÀ-ÿ]+){0,3})/iu',
            '/\b(gest[ãa]o\s+(?:de|em|do|da)\s+[\wÀ-ÿ]+(?:\s+(?:de|do|da|e)\s+[\wÀ-ÿ]+){0,2})/iu',
            '/\b(especializa[çc][ãa]o\s+em\s+[\wÀ-ÿ]+(?:\s+(?:de|do|da|e)\s+[\wÀ-ÿ]+){0,3})/iu',
            '/\b(p[óo]s[\s\-]gradua[çc][ãa]o\s+em\s+[\wÀ-ÿ]+(?:\s+(?:de|do|da|e)\s+[\wÀ-ÿ]+){0,3})/iu',
            '/\b(gradua[çc][ãa]o\s+em\s+[\wÀ-ÿ]+(?:\s+(?:de|do|da|e)\s+[\wÀ-ÿ]+){0,2})/iu',
            '/\b(curso\s+(?:de|em)\s+[\wÀ-ÿ]+(?:\s+(?:de|do|da|e)\s+[\wÀ-ÿ]+){0,3})/iu',
            '/\b(guia\s+de\s+[\wÀ-ÿ]+(?:\s+(?:de|do|da)\s+[\wÀ-ÿ]+)?)/iu',
            '/\b(administra[çc][ãa]o|enfermagem|inform[áa]tica|log[íi]stica|conta?bil(?:idade)?|seguran[çc]a|nutri[çc][ãa]o|design)\b/iu',
        ];
        foreach ($padroesCurso as $p) {
            if (preg_match($p, $texto, $m)) {
                $area = mb_strtoupper(trim($m[1]), 'UTF-8');
                break;
            }
        }
        $campus = '';
        if (preg_match('/\bcampus\s+([\wÀ-ÿ]+(?:\s+[\wÀ-ÿ]+)?)/iu', $texto, $m)) {
            $campus = 'CAMPUS ' . mb_strtoupper(trim($m[1]), 'UTF-8');
        } elseif (preg_match('/\b(?:em|de)\s+(Erechim|Ouro Preto|S[ãa]o Paulo|Rio de Janeiro|Bras[íi]lia|Salvador|Belo Horizonte|Fortaleza|Recife|Curitiba)/iu', $texto, $m)) {
            $campus = mb_strtoupper($m[1], 'UTF-8');
        }
        if ($tipo !== '' || $area !== '') {
            $label = $area !== '' ? $area : $tipo;
            $value = $campus !== '' ? $campus : ($tipo !== '' && $area !== '' ? $tipo : 'CONFIRA EDITAL');
            $badges['curso'] = [
                'label' => $label,
                'value' => $value,
                'icon'  => 'cap',
            ];
        }

        // === VAGAS (se nada acima detectado) ===
        if (count($badges) < 3 && preg_match('/(\d+(?:[.,]\d+)?)\s*(mil)?\s*vagas?\b/iu', $texto, $m)) {
            $valor = $m[1] . (isset($m[2]) && $m[2] !== '' ? ' ' . mb_strtoupper($m[2], 'UTF-8') : '') . ' VAGAS';
            $sub = $campus ?: ($area ?: 'PROCESSO ABERTO');
            $badges['vagas'] = [
                'label' => $valor,
                'value' => $sub,
                'icon'  => 'cap',
            ];
        }

        // Limita a 3 e prioriza: curso > duracao > taxa > vagas
        $ordem = ['curso', 'duracao', 'taxa', 'vagas'];
        $final = [];
        foreach ($ordem as $k) {
            if (isset($badges[$k]) && count($final) < 3) {
                $final[] = $badges[$k];
            }
        }
        return $final;
    }
}

if ( ! function_exists('clonais_dias_restantes') ) {
    /**
     * Calcula dias restantes até o deadline detectado no texto.
     * Retorna int (dias) ou null se não detectar deadline ou já passou.
     */
    function clonais_dias_restantes(string $titulo, string $contexto): ?int
    {
        $texto = $titulo . ' ' . $contexto;
        $hoje = new DateTime('today');
        $anoAtual = (int) $hoje->format('Y');

        // Ex: "até 04/05" ou "até 04/05/2026"
        if (preg_match('/at[ée]\s+(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{2,4}))?/iu', $texto, $m)) {
            $d = (int) $m[1];
            $mo = (int) $m[2];
            $y = isset($m[3]) ? (int) $m[3] : $anoAtual;
            if ($y < 100) { $y += 2000; }
            try {
                $deadline = new DateTime("$y-" . str_pad($mo,2,'0',STR_PAD_LEFT) . "-" . str_pad($d,2,'0',STR_PAD_LEFT));
                $diff = (int) $hoje->diff($deadline)->format('%r%a');
                return $diff > 0 ? $diff : null;
            } catch (Exception $e) { return null; }
        }

        // "até X de [mês]"
        $meses = ['janeiro'=>1,'fevereiro'=>2,'março'=>3,'marco'=>3,'abril'=>4,'maio'=>5,'junho'=>6,'julho'=>7,'agosto'=>8,'setembro'=>9,'outubro'=>10,'novembro'=>11,'dezembro'=>12];
        if (preg_match('/at[ée]\s+(\d{1,2})\s+de\s+(janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)/iu', $texto, $m)) {
            $d = (int) $m[1];
            $mNome = mb_strtolower(str_replace(['ç','ã'], ['c','a'], $m[2]), 'UTF-8');
            $mo = $meses[$mNome] ?? null;
            if ($mo === null) return null;
            try {
                $deadline = new DateTime("$anoAtual-" . str_pad($mo,2,'0',STR_PAD_LEFT) . "-" . str_pad($d,2,'0',STR_PAD_LEFT));
                if ($deadline < $hoje) {
                    $deadline = new DateTime(($anoAtual+1) . "-" . str_pad($mo,2,'0',STR_PAD_LEFT) . "-" . str_pad($d,2,'0',STR_PAD_LEFT));
                }
                $diff = (int) $hoje->diff($deadline)->format('%r%a');
                return $diff > 0 ? $diff : null;
            } catch (Exception $e) { return null; }
        }

        // "X dias" / "X horas restantes"
        if (preg_match('/(\d+)\s+dias?\s+(?:restantes?|para)/iu', $texto, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}

if ( ! function_exists('clonais_cena_contextual') ) {
    /**
     * Extrai cenário ESPECÍFICO do conteúdo do artigo pra DALL-E desenhar uma cena
     * que casa com o tema (não só persona genérica).
     *
     * Ex: título "simulação real de aeroporto" → "modern airport check-in counter
     *     setup, with luggage scales, gate signs, and tourism workshop materials"
     */
    function clonais_cena_contextual(string $titulo, string $contexto, array $personaDefault): string
    {
        $texto = mb_strtolower($titulo . ' ' . $contexto, 'UTF-8');

        // Cenas específicas detectadas no conteúdo
        if (preg_match('/aeroporto|check-?in|companhia\s+a[ée]rea|terminal|voo|embarque/u', $texto)) {
            return 'a modern airport terminal training simulator with check-in counters, luggage scales, gate signs and tourism workshop materials, slightly out of focus';
        }
        if (preg_match('/hotel|recep[çc][ãa]o|hospedagem|turismo\s+receptivo/u', $texto)) {
            return 'a clean modern hotel reception desk setup with travel materials, slightly out of focus';
        }
        if (preg_match('/cozinha|gastronomia|culin[áa]ri|chef|gastron[oô]mic/u', $texto)) {
            return 'a modern professional kitchen with chef equipment, gleaming countertops and ingredients, slightly out of focus';
        }
        if (preg_match('/sa[úu]de|enfermag|hospital|cl[íi]nic|m[ée]dic/u', $texto)) {
            return 'a clean modern healthcare clinic environment with neutral tones and medical equipment, slightly out of focus';
        }
        if (preg_match('/inform[áa]tic|programa[çc][ãa]o|software|tecnologia|ti\b/u', $texto)) {
            return 'a modern tech workspace with laptops, multiple screens and clean desks, slightly out of focus';
        }
        if (preg_match('/log[íi]stic|armaz[ée]m|transporte|estoque/u', $texto)) {
            return 'a modern logistics warehouse with shelves, forklifts and shipment boxes, slightly out of focus';
        }
        if (preg_match('/agropecu[áa]ri|agricultur|fazenda|rural/u', $texto)) {
            return 'a Brazilian rural farm setting with crops, soil and farm equipment, slightly out of focus';
        }
        if (preg_match('/constru[çc][ãa]o\s+civil|engenhar|obra|canteiro/u', $texto)) {
            return 'a clean professional construction site with safety equipment and architectural plans, slightly out of focus';
        }
        if (preg_match('/beleza|est[ée]tic|cabeleireir|barbeiro|sal[ãa]o/u', $texto)) {
            return 'a modern beauty salon with mirrors, professional products and styling tools, slightly out of focus';
        }
        if (preg_match('/administra[çc][ãa]o|gest[ãa]o|neg[óo]cio/u', $texto)) {
            return 'a modern Brazilian office with computers, planners and business charts, slightly out of focus';
        }
        if (preg_match('/seguran[çc]a\s+do\s+trabalho|epi/u', $texto)) {
            return 'an industrial safety training environment with helmets, harnesses and signage, slightly out of focus';
        }

        // Fallback: usa o cenário default da persona
        return $personaDefault['scenario'] ?? 'a clean modern Brazilian environment with soft natural light, slightly out of focus';
    }
}

if ( ! function_exists('construirPromptImagem') ) {
    /**
     * MODO HYBRID — DALL-E gera SÓ a foto base limpa (sem texto, sem sticker).
     * Todos os elementos editoriais (sticker amarelo, sub-label, info-badges,
     * banner urgência) são desenhados depois pelo PHP/GD via ImagemLayoutHighCTR.
     *
     * O PROMPT pede explicitamente que o LADO ESQUERDO da imagem seja
     * VISUALMENTE LIMPO — sem objetos, sem texto, sem gráficos — pra deixar
     * espaço pros overlays editoriais que serão queimados depois.
     */
    function construirPromptImagem(string $titulo, string $keyword = '', string $contexto = '', string $imagemPromptClaude = '', string $overlayChamativo = ''): string
    {
        $persona = clonais_persona_por_tema($keyword, $contexto);

        // Cena ESPECÍFICA ao conteúdo (em vez do scenario genérico da persona)
        $cenaEspecifica = clonais_cena_contextual($titulo, $contexto, $persona);

        $prompt  = "A candid documentary photograph of {$persona['person']}, ";
        $prompt .= "with a genuine unposed expression and a relaxed natural smile, ";
        $prompt .= "positioned on the RIGHT side of a 16:9 frame (about 60-70% from the left edge), making eye contact with the camera. ";
        $prompt .= "Subject is holding {$persona['object']} with a completely BLANK clean screen (no text, no app interface, no logos, no graphics). ";
        $prompt .= "Background: {$cenaEspecifica}. ";

        // Crítico pro hybrid: lado esquerdo precisa estar limpo
        $prompt .= "CRITICAL: the LEFT THIRD of the image must remain VISUALLY UNCLUTTERED — no objects, no text, no signs, no graphics, no people — so the area can host editorial overlays added later. ";

        // Especificações de câmera real (princípios fotográficos)
        $prompt .= "Photographic specs: shot on a 35mm full-frame camera at f/2.8, ISO 400, ";
        $prompt .= "shallow depth of field with natural background bokeh, soft natural window light from the side, ";
        $prompt .= "subtle film grain visible in shadows, true-to-life color reproduction (NOT oversaturated). ";

        // Realismo humano (anti-AI tells)
        $prompt .= "Realism details: visible natural skin texture with pores and small imperfections, ";
        $prompt .= "asymmetric facial features, realistic hair with stray strands, ";
        $prompt .= "subtle blush on cheeks, natural eye reflection, hands in a relaxed natural pose. ";
        $prompt .= "Style: documentary photojournalism, lived-in everyday look, not glossy retouched, not stock photography. ";
        $prompt .= "Avoid: plastic or porcelain skin, perfectly symmetric face, flawless teeth, oversaturated HDR colors, dramatic studio rim light, glamour retouching, 3D render look, text or graphic overlays. ";

        $prompt .= "16:9 landscape orientation. Brazilian context.";

        return $prompt;
    }
}
