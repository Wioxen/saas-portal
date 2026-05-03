<?php
/**
 * Cliente Anthropic Messages API.
 * Gera posts em formatos diferentes (SEO, Discover, News, SERP), cada um
 * com suas próprias regras de título, intro, tom e schema.
 */
class Claude
{
    private string $apiKey;
    private string $model;

    /** Configurações por formato. */
    public static array $formatos = [
        'seo' => [
            'nome'   => 'SEO',
            'estilo' => 'Clareza + intenção de busca',
            'titulo' => 'Título objetivo com a palavra-chave exata no início. Máx 60 caracteres. Sem clickbait — foco em responder à intenção de busca.',
            'intro'  => 'Introdução direta ao ponto. As 2 primeiras linhas devem responder claramente à intenção por trás da query.',
            'tom'    => 'informativo, didático, claro, levemente formal',
            'schema' => 'Article',
            'extras' => 'Use H2/H3 escaneáveis com variações da palavra-chave. Inclua tabela comparativa se fizer sentido. Internal linking sugerido.',
        ],
        'discover' => [
            'nome'   => 'Google Discover',
            'estilo' => 'Urgência + benefício + curiosidade + fragments otimizados + linguagem brasileira direta',
            'titulo' =>
                'Título com MÁXIMO CTR combinando 4 pilares SIMULTANEAMENTE (máx 70 caracteres):\n' .
                '1) LACUNA DE INFORMAÇÃO (curiosidade ética): o leitor PRECISA clicar pra saber o detalhe. Ex: "Quem tem direito ao R$ 3.500 que ninguém avisou?"\n' .
                '2) ESPECIFICIDADE: sempre que possível inclua NÚMERO/VALOR/DATA concretos. "R$ 1.200 em abril", "começa dia 24", "3 regras novas", "7 grupos afetados".\n' .
                '3) SEGMENTAÇÃO do público (crítico pra CTR): quem é afetado aparece no título. "Nascidos em 1958", "Trabalhadores CLT", "Famílias com renda até R$ 218", "Aposentados do INSS", "Moradores de [cidade]".\n' .
                '4) ANO/MÊS ATUAL em temas sazonais: "Dia do Trabalhador 2026", "IR 2026", "Enem 2026" — dá frescor. NUNCA omita o ano em assunto recorrente.\n' .
                '5) TENSÃO OBRIGATÓRIA (crítico pra Discover): o título precisa carregar CONSEQUÊNCIA real ou GAP factual, não só descrição. ' .
                'Use dois pontos (:), ponto-e-vírgula (;) ou parênteses (...) pra juntar fato + consequência. ' .
                'Exemplos que VIRALIZAM: "Isenção do ENEM 2026 encerra dia 24: quem perder paga a taxa cheia", "Salário-família sobe R$ 61 em maio; só recebe quem atualizar um dado no INSS", "BPC libera 2ª parcela (4 milhões ficam de fora por erro de cadastro)". ' .
                'Exemplos FRACOS (só descreve, sem consequência): "Enem 2026: prazo das inscrições para isenção encerra dia 24". Toda vez que possível, injete o ERRO ou PERDA real que a fonte sustenta.\n' .
                '6) PONTUAÇÃO PROIBIDA NO TÍTULO: **NUNCA use travessão (—) nem em-dash (–) no título.** Use APENAS dois pontos (:), ponto-e-vírgula (;), vírgula, ou parênteses (...) como separadores. Ponto final também é OK.\n' .
                'SEM clickbait vazio — a consequência precisa estar LITERALMENTE na fonte. Mas se está, PRECISA aparecer no título.',
            'intro'  =>
                'REGRA DOS 3 SEGUNDOS: o PRIMEIRO parágrafo prende o leitor em ≤3 linhas. Sempre deve haver FATO específico + tensão/consequência.\n' .
                'MAS — não aplique a mesma fórmula em todo artigo. Escolha UM dos 6 padrões de lead abaixo, conforme o ÂNGULO DOMINANTE da fonte. Isso evita que todos os artigos soem iguais.\n\n' .
                'BIBLIOTECA DE LEADS (escolha 1 por artigo, varie ao longo do tempo):\n' .
                '1. COUNTDOWN — quando prazo é <72h: "Falta menos de 24 horas para [fato]. Depois de [data], [consequência concreta]. [Caminho de ação]."\n' .
                '2. GAP/INSIGHT — quando há pegadinha/detalhe que elimina: "Quem tem [condição X] pode ter [problema Y] sem nem saber. A [situação] só aparece em [data posterior], quando [já é tarde]. Ainda dá tempo de [ação preventiva]."\n' .
                '3. NÚMERO-FIRST — quando volumetria é o impacto: "[N] pessoas têm direito a [benefício], mas só [subconjunto menor] conseguem de fato receber. O gargalo está em [critério específico]. Confira se você se enquadra."\n' .
                '4. CONTRASTE — quando há dado contraintuitivo: "Parece [aparência]. Não é. Na verdade, [realidade da fonte]. O que muda é [consequência prática]."\n' .
                '5. CASE CONCRETO — quando a fonte traz exemplo real: "[Nome/perfil] recebeu [situação real da fonte]. O caso mostra como [regra geral] funciona na prática — e o que o leitor pode fazer."\n' .
                '6. DATA-CHAVE — quando uma data simbólica é o fato: "[Dia da semana], [data] muda [regra/benefício]. [Quem é afetado]. [Ação em 1 frase]."\n\n' .
                'DIRETRIZES COMUNS:\n' .
                '- ESCALA + OBSTÁCULO (fórmula estrutural, não frase fixa): quando a fonte oferece VOLUMETRIA (milhões, R$ bi, N mil pessoas, N% do público), o lead DEVE abrir com ela seguida de obstáculo específico. Estrutura: [ESCALA+ação positiva] + [MAS/PORÉM] + [detalhe que bloqueia fração]. Exemplos (estrutura, não copie):\n' .
                '  • "Milhões vão receber até R$ 1 mil sem declarar, mas detalhe no cadastro bancário impede o depósito."\n' .
                '  • "R$ 2,3 bilhões foram liberados para aposentados; só que 800 mil ainda ficaram fora do primeiro lote."\n' .
                '  • "3,5 mil vagas públicas com salário inicial de R$ 4,8 mil — e 40% dos candidatos são reprovados por uma regra de prazo."\n' .
                '  Isso transforma lead informativo em lead de impacto. Se a fonte tem ESCALA, use-a no topo.\n' .
                '- DIFERENCIAL NO TOPO: quando a fonte traz um INSIGHT ÚNICO (cashback automático, regra inédita, mudança aprovada agora, primeiro-de), esse diferencial precisa estar no TÍTULO ou no 1º parágrafo. Não enterre no meio como detalhe — é ele que separa seu artigo dos 50 outros.\n' .
                '- Sempre começa pelo SUJEITO do fato (não por adjetivo, não por "você", não por ambientação "hoje em dia", "no mundo atual").\n' .
                '- PROIBIDO abertura-manual: "Os [N] grupos", "As [N] regras", "Conheça os/as", "Veja quem tem direito", "Saiba quem pode", "Existem [N] perfis". Isso é tutorial, não Discover. Abra com RISCO/CONSEQUÊNCIA/PERDA, nunca com enumeração fria.\n' .
                '- PROIBIDA ABERTURA NEUTRA: "Se você se encaixa em algum desses perfis...", "Caso você tenha...", "Para quem busca...", "Aqueles que...", "Você que é...", "Fique atento". Essas frases são SOFT — o Discover premia framing de RISCO. Substitua por: "Muita gente vai perder [X] por causa de [Y]", "[N] candidatos costumam ser barrados por [detalhe concreto]", "O que elimina mais pedidos é [motivo específico]".\n' .
                '- Camada final NUNCA é frase-sentença genérica tipo "O erro é silencioso", "Sem direito a recurso", "Sem aviso prévio". Essas expressões são proibidas — use AÇÃO PRÁTICA ou DETALHE ESPECÍFICO da fonte.\n' .
                '- A última frase do lead prefira ser AÇÃO ("Ainda dá tempo de checar..."), dado ESPECÍFICO ("O site pede login Gov.br nível prata"), ou NÚMERO concreto — nunca aforismo vazio.\n' .
                '- Varie a estrutura entre artigos da mesma semana. Se o último artigo usou Countdown, o próximo não deve usar Countdown — rotacione.',
            'tom'    => 'útil, jornalístico com leve urgência, brasileiro coloquial (proximidade, não jargão). Parágrafos curtos de 3-4 linhas no máximo. Fala COM o leitor ("pra você", "olha só", "vem comigo"), não fala dele.',
            'schema' => 'none', // Rank Math gera Article/NewsArticle. Nós só geramos FAQPage + HowTo via pós-processamento.
            'extras' =>
                // DNA Discover — princípio norteador
                '═══ DNA DO DISCOVER ═══ "O Discover não busca o que o usuário DIGITA, entrega o que ele DESEJA". ' .
                'FILTRO DE CADA PARÁGRAFO: "isso resolve uma dúvida específica dele OU entrega benefício prático/financeiro imediato?". Se a resposta for NÃO → CORTE o parágrafo. ' .
                'Discover prioriza artigos que RESOLVEM ou que COLOCAM DINHEIRO NO BOLSO. Nada de texto decorativo.\n\n' .

                // TL;DR — bloco-resumo (Fragments pro Discover)
                'OBRIGATÓRIO: Logo APÓS o primeiro parágrafo, insira um <ul class="bloco-resumo"> com 2 ou 3 <li> curtos. ' .
                'Cada <li> = uma frase factual decisiva em <strong> (data, valor, regra, quem recebe). ' .
                'Esse bloco é o que o Google Discover puxa pro card — precisa ser EXTREMAMENTE direto.\n' .

                // Estrutura em pílulas
                'ESTRUTURA EM PÍLULAS (crítico pra retenção mobile): quebre o conteúdo em BLOCOS CURTOS intercalados. H2 ou H3 a cada 2-3 parágrafos NO MÁXIMO. ' .
                'Misture formatos pra o olho não cansar: 1 bloco <p>, 1 bloco <ul>/<ol>, 1 bloco <table>, outro <p>, outro <ul>. ' .
                'NUNCA entregar 4+ parágrafos seguidos sem quebra visual. Discover mede tempo de sessão — leitor precisa ir descendo sem fadiga.\n' .

                // Tabelas pro Discover (rich results)
                'TABELAS <table> SEMPRE QUE FIZER SENTIDO: calendários, valores por faixa, requisitos por grupo, comparações, prós/contras, datas por categoria → <table> com <thead><th>. ' .
                'O Google puxa tabelas DIRETO pro card do Discover e pra featured snippets. Mesma informação em tabela = 2x o CTR vs. parágrafo. ' .
                'Mínimo 2 linhas + 1 cabeçalho. Ex: <table><thead><tr><th>Faixa</th><th>Renda</th><th>Valor</th></tr></thead><tbody>...</tbody></table>.\n' .

                // Cobertura long-tail nos H2 (SEO interno + Discover)
                '═══ LONG-TAIL NOS H2 (OBRIGATÓRIO: ≥50%) ═══\n' .
                'Pelo menos METADE dos H2 do artigo DEVE cobrir uma intenção de busca long-tail da palavra-chave principal. Intenções-base (cobrir 4-6 diferentes):\n' .
                '1. ELEGIBILIDADE — "Quem tem direito a [X]", "Quem pode [X]"\n' .
                '2. PROCESSO — "Como pedir [X] passo a passo", "Como solicitar [X]"\n' .
                '3. PRAZO — "Prazo para [X]", "Até quando pedir [X]", "Calendário de [X]"\n' .
                '4. REQUISITOS — "Documentos necessários para [X]", "Requisitos para [X]"\n' .
                '5. VALOR — "[X] vale quanto", "Valor atualizado de [X]"\n' .
                '6. RESULTADO — "Quando sai o resultado de [X]", "Como acompanhar [X]"\n' .
                '7. NEGATIVA — "O que fazer se [X] for negada", "Erros que barram [X]"\n' .
                'REGRAS:\n' .
                '- NÃO usar a variação "pelada" — SEMPRE combinar com dado concreto da fonte. Ex: ruim "Quem tem direito" → bom "Quem tem direito: 4 perfis aprovados pelo Inep".\n' .
                '- Variações devem aparecer naturalmente. Se a raiz semântica não cabe num H2, usar sinônimo (ex: "isenção" → "dispensa", "gratuidade").\n' .
                '- A keyword principal aparece no mínimo em 50% dos H2; a outra metade pode trazer sinônimos/semantic search.\n' .

                // Primeiro <p> após cada H2 = ação/alerta/decisão (anti-conteúdo neutro)
                '═══ REGRA DO PRIMEIRO PARÁGRAFO DE CADA H2 ═══\n' .
                'O primeiro <p> DEPOIS de cada H2 NUNCA é meramente expositivo. Ele entrega AÇÃO, ALERTA ou DECISÃO. ' .
                'Leitor deve saber o que FAZER, o que MUDAR ou o que EVITAR logo que bate no novo H2.\n' .
                'Errado (expositivo): "O CadÚnico é um cadastro administrado pelo governo federal que concentra informações de famílias de baixa renda."\n' .
                'Certo (ação): "Quem não atualizou o CadÚnico nos últimos 24 meses deve correr — o sistema nega a isenção automaticamente."\n' .
                'Certo (alerta): "Dois documentos barram o pedido se tiverem nome trocado, mesmo por 1 letra: RG e comprovante de residência."\n' .
                'Certo (decisão): "Vale mais esperar 15 dias e atualizar tudo, ou pedir agora e correr o risco de rejeição? A fonte mostra que aprovação cai 60% quando o cadastro está vencido."\n' .
                'Parágrafos puramente definitórios ("X é Y gerido por Z") só podem aparecer DEPOIS do parágrafo de ação — nunca como abertura de seção.\n' .

                // H2/H3 informativos e específicos (sem metáforas vazias)
                'H2/H3 INFORMATIVOS E ESPECÍFICOS: cada subtítulo é uma MICRO-MANCHETE factual. Leva dado, número, prazo, nome ou ação concreta — nunca começa com metáfora genérica. ' .
                'SIM: "Primeira parcela cai dia 24 para quem nasceu em janeiro", "Consulta leva 30 segundos no app Caixa Tem", "Valor por faixa de renda: quem recebe R$ 1.412 e quem fica em R$ 706", "Os 3 erros que eliminam o candidato no ato da inscrição". ' .
                'NÃO usar "Veja as datas" sozinho (vazio). ' .
                'PROIBIDO começar H2/H3 com clichês/metáforas de prefixo: "Pulo do Gato:", "Sem Enrolação:", "Direto ao Ponto:", "Dinheiro no Bolso:", "No Papel:", "Na Prática:", "De Olho em:", "Dica de Ouro:", "Pé no Chão:". ' .
                'Essas expressões NÃO agregam informação e soam repetitivas entre artigos. Se quiser coloquialidade, embuta NO CORPO do parágrafo, jamais como prefixo de título.\n' .

                // Linguagem de proximidade
                'LINGUAGEM COLOQUIAL BRASILEIRA (crítico pra CTR): proximidade > formalidade. SUBSTITUA: "outrossim" → "além disso", "fazer-se necessário" → "precisa", "denota" → "mostra", "com efeito" → "na real", ' .
                '"supramencionado" → "que falamos antes", "deve-se observar" → "olha só". Use "você", "a gente", "pra", "tá", "dá" — conversacional brasileiro. Nunca infantilize — é coloquial adulto, jornalístico.\n' .

                // Escaneabilidade mobile
                'ESCANEABILIDADE MOBILE: parágrafos MÁX 3-4 linhas. Frases curtas. Informação factual primeiro, contexto depois. Destaque dados em <strong> (data, valor, número).\n' .

                // Boxes de destaque (elemento de quebra visual)
                'BOXES DE DESTAQUE (obrigatório 1-2 no artigo): insira <blockquote style="background:#fef9c3;border-left:4px solid #eab308;padding:12px 16px;margin:18px 0;border-radius:6px;font-size:15px"> com 1-2 frases curtas contendo um DADO CRÍTICO destacado — valor, prazo final, regra-chave ou alerta. ' .
                'Exemplo: <blockquote style="...">💰 <strong>R$ 1.200 a mais por mês</strong> para quem se encaixa no novo critério. O pagamento é retroativo a janeiro.</blockquote>. ' .
                'Objetivo: quebra visual + sinal factual destacado pro Discover extrair.\n' .

                // Passo a passo
                'PASSO A PASSO em <ol>: "como consultar", "como sacar", "como se inscrever" → sempre <ol> numerada com verbo imperativo no início de cada <li>.\n' .

                // CTA final de ação + ponte pro próximo conteúdo — PROIBIDO "manda pra alguém"
                'CTA FINAL (último parágrafo OBRIGATÓRIO): 2 frases editoriais. ' .
                'Frase 1 — AÇÃO DIRETA específica + PRAZO/CONSEQUÊNCIA. Ex: "Quem tem direito deve fazer o pedido ainda em abril; depois do dia 24 o candidato paga a taxa de inscrição cheia." ' .
                'Frase 2 — PONTE pro próximo conteúdo do site, usando um dos links internos disponíveis, com âncora contextual (2-5 palavras). Ex: "Se o objetivo é garantir uma vaga pública depois do Enem, vale conhecer <a href=\'/concursos-federais-2026\'>os concursos federais com edital previsto para 2026</a>." ' .
                'PROIBIDO na última frase: "manda pra alguém", "manda esse artigo", "manda pro grupo", "compartilhe", "passa o link", "se ajudou, repassa" e similares — soam informais demais pro padrão editorial. ' .
                'Se realmente quiser incluir sinal social, use construção editorial: "Informação que vale circular entre quem se inscreveu no Enem nos últimos anos." — nunca como imperativo direto.\n' .

                // Calibragem de promessa ampla — evita que Discover interprete como "benefício exagerado"
                '═══ CALIBRAGEM DE PROMESSA (crítico pra alcance no Discover) ═══\n' .
                'Discover REDUZ distribuição de conteúdo com promessas amplas/sem qualificador ("benefício exagerado"). ' .
                'Toda promessa/benefício que combina VALOR + PÚBLICO DE ESCALA precisa de QUALIFICADOR CONDICIONAL na mesma frase ou na seguinte.\n' .
                '❌ EXAGERADO (risco de corte de alcance): "Cashback automático de até R$ 1 mil para 4 milhões de brasileiros"\n' .
                '✅ CALIBRADO: "Cashback de até R$ 1 mil para brasileiros que se enquadram no critério X, grupo estimado em 4 milhões pela fonte"\n' .
                '✅ CALIBRADO: "Até R$ 1 mil podem cair em conta — mas só pra quem cumpriu Y até Z"\n' .
                '✅ CALIBRADO: "Bolsa Família libera R$ 900 para famílias com renda até R$ 218 per capita; fora desse critério, o valor não se aplica"\n' .
                'Fórmula: [VALOR MÁXIMO] + [PÚBLICO ESPECÍFICO COM CRITÉRIO] + [FONTE DO NÚMERO].\n' .
                'Palavras-chave de calibragem: "pra quem se enquadra", "conforme critério X", "apenas quem cumpre Y", "no grupo estimado em", "segundo a fonte oficial".\n' .
                'NÃO é para suavizar a atratividade — é para REMOVER ambiguidade sobre quem é elegível. Artigo com promessa calibrada tem CTR igual e distribuição maior.\n' .

                // Anti-repetição semântica — evita over-optimization detectável
                '═══ ANTI-REPETIÇÃO SEMÂNTICA (crítico — ≥4x mesma expressão = over-optimization) ═══\n' .
                'Uma MESMA expressão factual (data, valor, palavra-chave) NÃO pode aparecer mais de 3 vezes no artigo com wording idêntico. A partir da 4ª ocorrência, é obrigatório usar variação semântica.\n' .
                'Exemplos de variação:\n' .
                '- "24 de abril" → "nesta quinta-feira" / "no último dia do prazo" / "antes do feriado" / "até a data limite" / "na semana que vem".\n' .
                '- "R$ 85" → "o valor da taxa" / "a quantia" / "a cobrança" (a partir da 3ª menção).\n' .
                '- "isenção do Enem" → "benefício" / "dispensa da taxa" / "gratuidade" / "pedido de isenção".\n' .
                '- "CadÚnico" → "cadastro social" / "registro no programa" (depois de 2-3 menções da sigla).\n' .
                'Regra: repetição excessiva sinaliza SEO primitivo e tira pontos no Discover. Variação mostra riqueza editorial.\n' .

                // Anti-redundância + densidade editorial (corta ~15% de gordura)
                '═══ ANTI-REDUNDÂNCIA (CORTE DE GORDURA ~15%) ═══\n' .
                'Regra: o MESMO dado não pode aparecer em 3 lugares estruturais (título + TL;DR + H2 + intro). Distribua.\n' .
                '- Se o valor R$ 1.412 está no título, NÃO repita no <ul class="bloco-resumo"> — use outro ângulo (ex: critério de corte, público-alvo).\n' .
                '- Se a data 24 de abril está no H2, NÃO repita literalmente em 2 parágrafos seguidos — alterne entre "na próxima quinta", "antes do feriado", "até o dia do prazo".\n' .
                '- Frases-eco são banidas: "Como vimos,", "Conforme dito,", "Como já mencionamos". Cada informação aparece UMA vez e só volta se agregar ângulo novo.\n' .
                '- PROIBIDO construções redundantes: "confirmação oficial do governo" (oficial = do governo), "prazo final" (prazo já tem fim), "planejamento prévio" (planejamento já é prévio), "benefício gratuito" (benefício social já é gratuito), "totalmente grátis" (grátis basta).\n' .
                '- CORTE tudo que começa com "É importante", "Vale destacar", "Vale a pena", "Cabe ressaltar" — essas construções só encompridam sem agregar.\n' .
                'Objetivo: texto 15% mais curto que o primeiro rascunho natural do modelo, mesma informação.\n' .

                // Concretude — proibido termo abstrato sem cenário específico
                '═══ REGRA DE CONCRETUDE (anti-abstrato) ═══\n' .
                'Todo termo abstrato/técnico deve vir com CENÁRIO REAL — número, faixa temporal ou situação específica que o leitor reconhece.\n' .
                'Exemplos:\n' .
                '- ❌ "cadastro desatualizado" → ✅ "quem não atualiza o CadÚnico há mais de 2 anos costuma cair aqui" (se a fonte sustenta).\n' .
                '- ❌ "dados divergentes" → ✅ "quando a renda declarada não bate com o que consta no extrato da Caixa".\n' .
                '- ❌ "documentação incompleta" → ✅ "quem esquece de anexar o comprovante de matrícula do 3º ano é barrado automaticamente".\n' .
                '- ❌ "biometria irregular" → ✅ "quem não atualiza a foto do título de eleitor há 3 anos ou mais".\n' .
                'Regra: a cada adjetivo técnico (desatualizado, divergente, incompleto, irregular, pendente, inconsistente), anexar cenário de QUANTO TEMPO ou QUAL DADO ESPECÍFICO. Sem cenário = abstrato = perde CTR.\n' .

                // Expansão obrigatória de fatos neutros em consequência concreta
                '═══ EXPANSÃO DE FATO EM CONSEQUÊNCIA (anti-frieza) ═══\n' .
                'Todo fato/erro/detalhe técnico mencionado no texto precisa carregar a CONSEQUÊNCIA PRÁTICA pro leitor — não pode ficar só na camada descritiva. ' .
                'Fórmula: [FATO/ERRO/DETALHE] → [O QUE ACONTECE NA VIDA DO LEITOR]. ' .
                'Exemplos:\n' .
                '- FRACO: "erro nos dados bancários" → FORTE: "erro nos dados bancários impede o pagamento e atrasa a restituição em até 6 meses" (quando a fonte sustenta).\n' .
                '- FRACO: "falta de documento" → FORTE: "falta de um único documento elimina o candidato na hora, sem direito a recurso".\n' .
                '- FRACO: "exigência de renda" → FORTE: "se a renda per capita passa de R$ 218, o candidato é barrado automaticamente".\n' .
                '- FRACO: "cadastro desatualizado" → FORTE: "cadastro desatualizado faz o benefício ser bloqueado sem aviso prévio".\n' .
                'Regra: a CADA detalhe técnico, pergunte "o que isso significa na prática pro leitor?" e escreva a resposta concreta. ' .
                'Sem isso, o texto vira enciclopédia — o Discover distribui pra quem conta CONSEQUÊNCIA.\n' .

                // Consequência emocional de tripla presença
                '═══ CONSEQUÊNCIA EMOCIONAL (3 LUGARES) ═══\n' .
                'A CONSEQUÊNCIA (o que se perde, o que se paga, quem fica de fora) precisa aparecer — baseada na fonte — em 3 posições do artigo:\n' .
                '1) TÍTULO — após separador (:, ;, ou parênteses). Ex: "...: quem perder paga a taxa cheia".\n' .
                '2) LEAD (1º parágrafo, camada 2) — frase que descreve a perda concreta.\n' .
                '3) CTA FINAL (1ª frase) — ação + reiteração da consequência. Ex: "Quem tem direito deve fazer o pedido ainda em abril; depois do dia 24 o candidato paga a taxa cheia."\n' .
                'Banco de verbos de urgência real (use nestas 3 posições, sem repetir o mesmo): paga, perde, fica de fora, deixa de receber, é eliminado, é reprovado, passa a pagar, tem o pedido negado, não consegue sacar.\n' .
                'Urgência NUNCA é inventada. Se a fonte não sustenta consequência, EU NÃO CRIO uma — o artigo fica mais informativo e a tensão vai pro título só (ex: segmentação ou ineditismo).\n' .

                // Interpretação editorial — 1 leitura proprietária por artigo
                '═══ INTERPRETAÇÃO EDITORIAL (OBRIGATÓRIO: 1 frase de insight proprietário) ═══\n' .
                'Além de RELATAR o que a fonte diz, o artigo PRECISA trazer 1 interpretação editorial — uma leitura que conecta pontos que a fonte apresenta soltos OU contextualiza no cenário maior. É o que separa conteúdo genérico de conteúdo autoral.\n' .
                'DIFERENÇA vs. voz de especialista:\n' .
                '- VOZ DE ESPECIALISTA = observação prática ("o erro mais comum é X")\n' .
                '- INTERPRETAÇÃO EDITORIAL = leitura estratégica ("o que parece Y é, na prática, Z")\n' .
                'Exemplos:\n' .
                '- "O que parece um detalhe administrativo é, na prática, uma filtragem por cadastro regular — apenas quem mantém o CadÚnico atualizado passa pelo filtro."\n' .
                '- "A escolha pelo depósito automático revela a estratégia da Receita: reduzir custo operacional e aumentar adesão no mesmo movimento."\n' .
                '- "Na prática, quem usa a declaração pré-preenchida sem revisão responde por parcela significativa das retenções em malha — padrão observado entre contribuintes nos últimos anos."\n' .
                'REGRAS:\n' .
                '- A interpretação deve ser CONSISTENTE com os fatos da fonte, mas vai além da descrição pura.\n' .
                '- Nunca inventar estatística própria — pode citar PADRÕES ("padrão observado", "tendência recorrente") sem atribuir número.\n' .
                '- Posicionar após apresentar os dados (idealmente entre H2 #2 e #3), como camada de análise.\n' .
                '- Isso NÃO é opinião pessoal — é leitura profissional dos dados. Neutra, mas autoral.\n' .

                // Voz de especialista — 1 parágrafo observacional por artigo
                '═══ VOZ DE ESPECIALISTA (OBRIGATÓRIO: 1 por artigo) ═══\n' .
                'E-E-A-T pede voz de quem VIVE o problema, não só descreve. Em algum ponto do corpo (não no lead), insira 1 parágrafo curto (2-3 linhas) com TOM DE INSIDER — quem já viu o problema acontecer mil vezes.\n' .
                'Abrir com uma destas construções (ou equivalente) + observação DERIVADA da fonte:\n' .
                '  - "Na prática, o erro mais comum que leva a [PROBLEMA] é [DETALHE ESPECÍFICO da fonte]."\n' .
                '  - "Quem trabalha com isso sabe: [observação prática da fonte]."\n' .
                '  - "Do que se vê em campo, [padrão recorrente citado pela fonte]."\n' .
                '  - "O contador/advogado/engenheiro que lida com isso no dia a dia costuma apontar: [insight da fonte]."\n' .
                'REGRAS:\n' .
                '- Nunca inventar estatística interna ("atendemos 500 casos") — não somos instituição. Usar formulação impessoal ("casos mostram", "o padrão é", "o que se vê").\n' .
                '- O insight precisa vir da fonte ou ser consequência lógica do que a fonte descreve. Não especular.\n' .
                '- Posicionar ENTRE H2 2 e 3, ou logo antes da conclusão. Funciona como camada de "humano por trás do texto".\n' .

                // Micro-narrativa: 1 cenário real por artigo (aumenta CTR + tempo na página)
                '═══ MICRO-NARRATIVA (OBRIGATÓRIO: 1 por artigo) ═══\n' .
                'Em algum ponto do corpo (NÃO no lead, de preferência no meio), insira 1 parágrafo de CENÁRIO CONCRETO — alguém que viveu a situação, exemplo de erro comum, caso que a fonte descreve. Isso ativa gatilho emocional e tempo na página.\n' .
                'Formato: frase curta (1-3 linhas) que conecta regra abstrata com experiência real.\n' .
                'Exemplos:\n' .
                '- "Quem perdeu o Enem em 2025 agora precisa justificar — e muita gente esquece esse detalhe e paga a taxa sem precisar."\n' .
                '- "Pedidos de 2024 mostraram o padrão: só quem ajustou o cadastro no mesmo mês da inscrição conseguiu aprovação na 1ª tentativa."\n' .
                '- "No último pagamento, aposentados que não trocaram a agência ficaram 3 meses sem receber — a Caixa exige mudança em até 30 dias."\n' .
                'REGRAS:\n' .
                '- NUNCA inventar caso. O cenário deve ser plausível segundo a fonte (referência a "candidatos de 2024", "pedidos anteriores", "casos reportados").\n' .
                '- Linguagem direta, verbos no passado/presente. Não usar "Joana, 34 anos, aposentada…" — sem personagens fictícios.\n' .
                '- Conecta com o drama prático: "perdeu", "teve que pagar", "ficou sem", "descobriu tarde", "só conseguiu depois".\n' .
                '- Max 3 linhas. Não virar storytelling longo — é um flash.\n' .

                // Alerta forte dedicado — 1 box vermelho/âmbar com erro crítico
                '═══ ALERTA FORTE (OBRIGATÓRIO: 1 box destacado pro erro crítico) ═══\n' .
                'Além do blockquote amarelo informativo, insira 1 BLOCO DE ALERTA vermelho/âmbar destacando o ERRO CRÍTICO que elimina/barra/nega/invalida — extraído da fonte. Isso separa visualmente o maior risco do artigo e aumenta tempo na página (Discover lê como "conteúdo denso com utilidade").\n' .
                'ESTRUTURA OBRIGATÓRIA:\n' .
                "<div style='background:#fef2f2;border-left:4px solid #dc2626;padding:14px 18px;margin:24px 0;border-radius:6px;font-size:15px'>\n" .
                "<strong style='color:#991b1b;display:block;margin-bottom:6px'>⚠️ ATENÇÃO: [ERRO/RISCO CRÍTICO em 6-10 palavras]</strong>\n" .
                "<span style='color:#7f1d1d'>[1-2 frases descrevendo exatamente o que acontece e como evitar — baseado na fonte]</span>\n" .
                "</div>\n" .
                'REGRAS:\n' .
                '- Usar o GANCHO PRINCIPAL identificado na fonte como base do alerta. Se a fonte mostra que "CadÚnico desatualizado reprova pedidos", o alerta destaca exatamente isso.\n' .
                '- NÃO inventar risco. Só usar o que a fonte sustenta.\n' .
                '- Posicionar no MEIO do artigo (após 2º H2), em momento de tensão máxima.\n' .
                '- O blockquote amarelo informativo (se existir) continua em OUTRO ponto — NÃO substituir.\n' .
                '- Emoji permitido: ⚠️ (atenção) ou 🚨 (urgência). UM só, no início do <strong>.\n' .

                // Frase forte por seção — padrão jornalístico (micro-choque por H2)
                '═══ FRASE FORTE POR SEÇÃO (OBRIGATÓRIO: 1 por H2) ═══\n' .
                'Cada seção (delimitada por H2) termina com 1 FRASE CURTA (≤15 palavras) de alto impacto — um "micro-choque" que resume o ponto da seção. Padrão jornalístico clássico de fechamento.\n' .
                'Posição: ÚLTIMA frase do último <p> da seção (antes do próximo H2).\n' .
                'Estruturas válidas (adapte ao conteúdo, não copie):\n' .
                '  - "[Número] ignora esse detalhe e paga a conta."\n' .
                '  - "É o erro que mais trava [restituição/inscrição/pagamento]."\n' .
                '  - "Quem não confere, perde."\n' .
                '  - "Sem esse passo, o pedido volta no início."\n' .
                '  - "O cadastro certo muda tudo."\n' .
                'REGRAS:\n' .
                '- Frase única, ≤15 palavras, ponto final forte.\n' .
                '- DERIVADA do conteúdo da seção específica (não genérica repetível).\n' .
                '- NÃO usar as frases-template já banidas ("Parece simples. Não é.", "A vaga não espera.").\n' .
                '- Uma por seção, não uma por artigo. Meta: 4-6 frases fortes no artigo todo.\n' .

                // Ganchos invisíveis de scroll (aumenta profundidade de leitura)
                '═══ GANCHOS DE SCROLL (OBRIGATÓRIO: 1-2 no meio do texto) ═══\n' .
                'O Discover mede profundidade de scroll como sinal forte. Entre H2 #2 e H2 #4, insira 1 frase curta (max 12 palavras) que PUXE pra continuar lendo.\n' .
                'A frase deve ser CONTEXTUAL — derivada do tema específico deste artigo — e revelar que vem informação mais importante a seguir. NUNCA genérica.\n' .
                '❌ Genérico (proibido verbatim): "É aqui que a maioria erra." / "Parece simples. Não é." / "A vaga não espera."\n' .
                '✅ Contextual (deriva do assunto):\n' .
                '- Em artigo sobre CadÚnico: "É aqui que mais pedidos de isenção são negados silenciosamente."\n' .
                '- Em artigo sobre INSS: "Esse é o detalhe que mais reprova aposentadoria por idade."\n' .
                '- Em artigo sobre FGTS: "Quem confia no app sem checar a conta cai nessa primeiro."\n' .
                '- Em artigo sobre concurso: "O erro no envio do comprovante é o que mais elimina candidatos."\n' .
                'Estrutura: "É aqui que [ação/consequência específica do tema]" OU "[Termo do tema] é o que mais [verbo de risco/exclusão]" OU "Esse é o detalhe que [consequência específica]".\n' .
                'Posicionamento: dentro de <p> próprio, antes do bloco de informação mais denso. Funciona como cliffhanger.\n' .

                // Frases-template banidas (cara-de-IA que mata autenticidade)
                '═══ FRASES PROIBIDAS (cara-de-IA/template) ═══\n' .
                'NÃO use — nunca, em nenhum contexto — estas frases/padrões (são marcas registradas de conteúdo gerado por IA):\n' .
                '- "Se você ainda não [verbo], leia isso agora"\n' .
                '- "processo leva menos de N minutos" / "leva poucos minutos" / "é rapidinho" / "em menos de 10 minutos" — clichê banido. ' .
                'Se a fonte fornece tempo específico, cite com número exato + sujeito específico (ex: "o candidato gasta cerca de 5 minutos no cadastro, segundo o Inep"). Sem isso, NÃO mencione tempo.\n' .
                '- "Olha só cada um deles:" / "Olha só como funciona:" (introdução oca de lista)\n' .
                '- "Entenda tudo sobre" / "Saiba mais sobre" / "Confira a seguir"\n' .
                '- "Vale a pena ficar atento" / "Vale destacar que" / "É importante lembrar"\n' .
                '- "Descubra agora" / "Não perca essa oportunidade" (clickbait vazio)\n' .
                '- "Tudo o que você precisa saber sobre" (genérico)\n' .
                '- "Neste artigo, vamos falar sobre" / "Neste conteúdo" (meta-narração)\n' .
                '- "Continue lendo" / "A seguir, entenda" (quebra de ritmo)\n' .
                '- "Sem dúvidas" / "Com certeza" / "Certamente" (enchimento)\n' .
                '- "Manda pra quem", "manda esse artigo", "manda pro grupo", "passa o link" — informalidade demais pro padrão editorial.\n' .
                '- "O erro é silencioso", "sem exceção", "antes mesmo de você perceber", "sem direito a recurso", "sem aviso prévio", "descobre tarde demais", "passa batido" — aforismos genéricos de urgência. Substitua por AÇÃO prática ou DETALHE específico da fonte.\n' .
                'Em vez disso: vá direto ao fato, use dados específicos, substitua enchimento por valor.\n' .

                // Imperfeições estratégicas humanas (anti-template)
                'VARIAÇÃO HUMANA (princípio, não fórmula): o texto NÃO pode ser uniforme/previsível. Pelo menos 1 destes recursos deve aparecer, escolhido conforme o TOM do assunto — nunca copie exemplos fixos.\n' .
                '- Pausa curta em <p> próprio — 3-7 palavras relevantes ao assunto específico deste artigo. Ex errado (prescrição repetível): "A vaga não espera." Ex certo (contextual): "O cadastro ainda aceita edições." / "A regra já vale pra março." / "O benefício não retroage."\n' .
                '- Contraste inesperado — um fato que subverte expectativa COMUM da área. Se a fonte traz, use. Proibido a frase "Parece X. Não é." — substituir por reformulação específica (ex: "O portal aceita login sem CPF? Aceita, mas só com senha Gov.br nível prata").\n' .
                '- Exemplo específico ou caso real (quando a fonte fornece).\n' .
                'REGRA DE OURO: NENHUMA frase curta ou contrastiva pode virar recorrente entre artigos. Se a mesma frase de 3-7 palavras aparecer em 2 artigos diferentes, é sinal de template — reescreva.\n' .
                'PROIBIDO VERBATIM (NUNCA usar exatamente): "A vaga não espera.", "É aqui que a maioria erra.", "Parece simples. Não é.", "A maioria perde por isso.", "Quem chega depois, não entra.", "Fica a dica.", "Fica esperto.", "Simples assim."\n' .

                // Links de telefone/WhatsApp
                'TELEFONES E WHATSAPP: escreva o número natural — NÃO tente criar links. O pós-processamento adiciona <a href="tel:"> e <a href="https://wa.me/"> automaticamente.\n' .

                // Imagem
                'IMAGEM: descreva em hero_alt uma imagem com rosto humano + emoção + contraste (CTR duplicado no Discover).',
        ],
        'news' => [
            'nome'   => 'Google News',
            'estilo' => 'Factual + neutro',
            'titulo' => 'Título factual no estilo jornalístico. Quem-o-quê-quando-onde. Sem adjetivos opinativos, sem hype. Máx 80 caracteres.',
            'intro'  => 'Lead jornalístico clássico (pirâmide invertida): a informação MAIS importante na primeira frase. Quem fez o quê, quando, onde, por quê.',
            'tom'    => 'jornalístico, neutro, terceira pessoa, sem opiniões, sem "você", sem CTA comerciais',
            'schema' => 'NewsArticle',
            'extras' => 'Cite fontes ("segundo dados de...", "de acordo com..."). Inclua datas e números específicos. Não use linguagem promocional. NÃO inclua FAQ comercial — só perguntas factuais.',
        ],
        'serp' => [
            'nome'   => 'SERP (busca orgânica)',
            'estilo' => 'Mistura: benefício + clareza',
            'titulo' => 'Equilíbrio entre informativo e atrativo. Palavra-chave + benefício para o leitor. Máx 65 caracteres.',
            'intro'  => 'Apresenta o tema, promete o benefício específico e contextualiza em 2-3 linhas. Combina utilidade com leve persuasão.',
            'tom'    => 'equilibrado, informativo com toque de persuasão, segunda pessoa moderada',
            'schema' => 'Article',
            'extras' => 'Snippets prontos pra featured snippet do Google: parágrafos diretos com 40-60 palavras respondendo perguntas. Listas e tabelas estruturadas.',
        ],
    ];

    public function __construct(string $apiKey, string $model = 'claude-sonnet-4-6')
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    /**
     * @param string $keyword       palavra-chave alvo
     * @param array  $fontes        ['meta'=>..., 'content'=>...] do Scraper
     * @param string $formato       seo|discover|news|serp
     * @param array  $blocosCustom  blocos extras de instrução (vazios são ignorados)
     */
    public function gerarPost(string $keyword, array $fontes, string $formato = 'seo', array $blocosCustom = []): array
    {
        if (!isset(self::$formatos[$formato])) {
            throw new InvalidArgumentException("Formato desconhecido: $formato");
        }
        $f = self::$formatos[$formato];
        $briefing = $this->montarBriefing($fontes);

        $system = $this->montarSystem($f, $blocosCustom);
        $user   = $this->montarUser($keyword, $f, $briefing);

        $resp = $this->call([['role' => 'user', 'content' => $user]], $system);
        $texto = $resp['content'][0]['text'] ?? '';
        $json = $this->extractJson($texto);
        if (!$json) {
            $jsonErr = json_last_error_msg();
            // Detecta truncagem explícita (JSON começou mas não fechou)
            $parecerTruncado = (substr_count($texto, '{') > substr_count($texto, '}'))
                            || (substr_count($texto, '[') > substr_count($texto, ']'));
            $dica = $parecerTruncado
                ? ' PROVÁVEL TRUNCAGEM: resposta foi cortada antes de fechar. Aumente max_tokens ou reduza o prompt.'
                : '';
            throw new RuntimeException("Claude não retornou JSON válido ($formato). json_error: $jsonErr.{$dica} Primeiros 800 chars: " . substr($texto, 0, 800));
        }
        $json['_formato'] = $formato;
        $json['_formato_nome'] = $f['nome'];
        $json['schema_type'] = $json['schema_type'] ?? $f['schema'];
        return $json;
    }

    /**
     * Gera um artigo ORIGINAL em texto puro (sem review de produtos, sem cards,
     * sem decision_block). Focado em conteúdo editorial: artigo informativo,
     * notícia, guia, análise. O retorno é content_html pronto para publicar.
     *
     * @param string $keyword palavra-chave principal
     * @param string $termos  termos extras/secundários (texto livre, instruções do usuário)
     * @param array  $fontes  fontes do Scraper (opcional)
     * @param string $formato seo|discover|news|serp
     * @param array  $blocosCustom 8 blocos de instrução (opcional)
     */
    public function gerarArtigo(string $keyword, string $termos, array $fontes, string $formato = 'seo', array $blocosCustom = [], array $linksInternos = []): array
    {
        if (!isset(self::$formatos[$formato])) {
            throw new InvalidArgumentException("Formato desconhecido: $formato");
        }
        $f = self::$formatos[$formato];
        $briefing = $this->montarBriefing($fontes);

        // Manifesto e regras temporais agora vêm do DiscoverPromptBuilder (fonte única).
        // $hojeStr/$diaSemana seguem aqui porque o user prompt cita a data explicitamente.
        $diasSemana = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
        $hojeStr    = date('d/m/Y');
        $diaSemana  = $diasSemana[(int)date('w')];

        // Tenta extrair data de publicação da fonte para cálculo de temporalidade relativa
        $fontePubInfo = '';
        if (!empty($fontes[0]['meta']['published'])) {
            $pub = $fontes[0]['meta']['published'];
            try {
                $dtPub = new DateTime($pub);
                $dtHoje = new DateTime('today');
                $diffDays = (int)$dtHoje->diff($dtPub)->format('%r%a');
                $fontePubInfo = "DATA DE PUBLICAÇÃO DA FONTE: {$dtPub->format('d/m/Y')} ({$diffDays} dias em relação a hoje — use isso para calcular expressões temporais relativas).";
            } catch (Throwable $e) {}
        }

        // Extrai localidade mencionada na fonte (cidade/estado/bairro) via regex simples
        $localHint = '';
        if (!empty($fontes)) {
            $textoCompleto = '';
            foreach ($fontes as $fonte) {
                if (!empty($fonte['content']['paragraphs'])) {
                    $textoCompleto .= ' ' . implode(' ', array_slice($fonte['content']['paragraphs'], 0, 10));
                }
            }
            $possiveis = [];
            // Estados BR
            if (preg_match_all('/\b(?:no|na|em)\s+([A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç]+(?:\s+[A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç]+)?)/u', $textoCompleto, $m)) {
                $possiveis = array_slice(array_unique($m[1]), 0, 10);
            }
            if (!empty($possiveis)) {
                $localHint = 'LOCAIS MENCIONADOS NA FONTE (use na geografia do artigo): ' . implode(', ', $possiveis);
            }
        }

        require_once __DIR__ . '/DiscoverPromptBuilder.php';
        $system = DiscoverPromptBuilder::blocoManifesto();
        $system .= $this->montarSystem($f, $blocosCustom)
            . "\n\nMODO: ARTIGO EDITORIAL (texto puro)\n"
            . "- NÃO é um review de produtos. NÃO monte cards de produto, tabelas comparativas, decision_block ou vs_comparisons.\n"
            . "- O campo \"products\" DEVE vir VAZIO ([]).\n"
            . "- Todo o conteúdo do artigo vai dentro de content_html em HTML semântico válido (h2, h3, p, ul, ol, strong, blockquote).\n"
            . "- Foque em profundidade do tema, dados, contexto, explicações. Escreva como jornalista/especialista do nicho.\n"
            . "- Mínimo 1200 palavras de conteúdo real dentro de content_html.\n"
            . "- Incorpore os termos/instruções extras fornecidas pelo usuário.\n"
            . "\nREGRAS CRÍTICAS DE JSON (content_html é o maior campo — não pode quebrar o parse):\n"
            . "- Em atributos HTML dentro de content_html use SOMENTE aspas simples: <a href='...'>, <p class='x'>. NUNCA aspas duplas.\n"
            . "- Se precisar de aspas duplas no texto visível, use as curvas: \u{201C}texto\u{201D}.\n"
            . "- NUNCA coloque quebra de linha literal dentro de content_html. Toda a string fica em UMA linha.\n"
            . "- Nunca use \\\"...\\\" escapado também; prefira aspas simples em HTML.\n"
            . "\nANTI-PLÁGIO (OBRIGATÓRIO):\n"
            . "- JAMAIS copie frases inteiras da fonte. Reescreva TUDO com outras palavras, mudando ordem e estrutura.\n"
            . "- Extraia os DADOS (números, datas, nomes próprios, valores) e use-os em parágrafos originais.\n"
            . "- Identifique os TERMOS-CHAVE que aparecem nas buscas virais do nicho e use-os naturalmente (sem stuffing).\n"
            . "- Adicione ao menos UMA análise/contexto que a fonte NÃO tem (amplia valor, justifica republicação).\n"
            // Seção DISCOVER VIRALIZAÇÃO vive em prompts/manifesto_editorial.md (fonte única de verdade editorial)
            // Se manifesto não existir, regras ficam nos blocos customizados do usuário
            . "\n"
            // (Regras Discover não duplicadas aqui — vêm do manifesto via blocoManifesto() acima)
            . DiscoverPromptBuilder::regrasTemporais('gerar');

        // GANCHO DE ALTO CTR — extrai o risco/gap mais forte da fonte antes de gerar
        require_once __DIR__ . '/DiscoverGanchoExtrator.php';
        require_once __DIR__ . '/DiscoverKeywordLongTail.php';
        require_once __DIR__ . '/DiscoverPainClassifier.php';
        $gancho = DiscoverGanchoExtrator::extrair($fontes);
        $ganchoInstrucao = DiscoverGanchoExtrator::instrucaoProPrompt($gancho);
        $longTailInstrucao = DiscoverKeywordLongTail::instrucaoProPrompt($keyword);
        $prazoProximo = DiscoverGanchoExtrator::detectarPrazoProximo($fontes);

        // DOR DOMINANTE — classifica termo em urgência/medo/dinheiro/oportunidade
        // Calibra tom editorial pro tipo de gatilho emocional do trend
        $contextoDor = (string)($gancho['frase'] ?? '') . ' ' . ($gancho['diferencial']['frase'] ?? '');
        $dor = DiscoverPainClassifier::classificar($keyword, $contextoDor);
        $dorInstrucao = DiscoverPainClassifier::instrucaoProPrompt($dor);

        $user = "PALAVRA-CHAVE: {$keyword}\n";
        $user .= "DATA DE HOJE: {$hojeStr} ({$diaSemana})\n";
        if ($fontePubInfo !== '') $user .= $fontePubInfo . "\n";
        if ($localHint !== '')   $user .= $localHint . "\n";
        if ($prazoProximo !== null) {
            $user .= "\n═══ COUNTDOWN OBRIGATÓRIO (prazo em {$prazoProximo['dias_restantes']} dias) ═══\n"
                   . "A fonte informa prazo em {$prazoProximo['data']}, a {$prazoProximo['dias_restantes']} dias de hoje. "
                   . "O LEAD DEVE usar o padrão COUNTDOWN (não outro): abrir com 'Faltam {$prazoProximo['dias_restantes']} dias para [ação]' "
                   . "seguido de obstáculo/consequência específica. NÃO use Gap/Insight, Contraste ou Case — use Countdown.\n"
                   . "═══ FIM COUNTDOWN ═══\n";
        }
        if ($ganchoInstrucao !== '') $user .= $ganchoInstrucao;
        if ($dorInstrucao !== '')    $user .= $dorInstrucao;
        if ($longTailInstrucao !== '') $user .= $longTailInstrucao;
        if (trim($termos) !== '') {
            $user .= "\nTERMOS / CONTEXTO DO USUÁRIO (use obrigatoriamente):\n" . trim($termos) . "\n";
        }
        if (!empty($fontes)) {
            $user .= "\nBRIEFING DAS FONTES:\n" . $briefing . "\n";
        }
        $user .= "\n" . DiscoverPromptBuilder::regraLinksInternos((string)($this->cfg['wp_url'] ?? ''), 'gerar');
        $user .= "\n" . DiscoverPromptBuilder::blocoHumanoEspecialista();
        $user .= "\n" . DiscoverPromptBuilder::blocoCTACompartilhamento();
        $user .= "\n" . DiscoverPromptBuilder::blocoLinksAfiliado();
        $user .= "\nFORMATO: {$f['nome']} ({$f['estilo']})\n"
            . "TOM: {$f['tom']}\n"
            . "REGRA DE TÍTULO: {$f['titulo']}\n"
            . "REGRA DE INTRO: {$f['intro']}\n"
            . "EXTRAS DO FORMATO: {$f['extras']}\n\n"
            . "TAREFA: Escreva um artigo editorial COMPLETO em content_html. Não é review de produtos.\n\n"
            . "Responda APENAS com JSON (sem markdown, sem ```), neste schema:\n"
            . '{"title":"...","slug":"slug-url-amigavel","excerpt":"máx 160 chars","meta_title":"título SEO","meta_description":"150-160 chars","focus_keyword":"' . $keyword . '","secondary_keywords":["5-8"],"intro_paragraphs":["p1","p2","p3"],"content_html":"HTML COMPLETO DO ARTIGO EM UMA LINHA (escape quebras como \\n)","products":[],"faq":[{"q":"","a":""}],"tags":["5 tags"],"categories":["1 categoria"],"hero_alt":"alt","imagem":{"alt_text":"frase descritiva com keyword, 5-15 palavras","legenda":"caption visível sob a imagem no WP, 1 frase curta com contexto do artigo","descricao":"descrição completa para acessibilidade e SEO, 1-2 frases detalhando o que a imagem representa"},"schema_type":"' . $f['schema'] . '"}';

        $resp = $this->call([['role' => 'user', 'content' => $user]], $system);
        $texto = $resp['content'][0]['text'] ?? '';
        $json = $this->extractJson($texto);
        if (!$json) {
            $jsonErr = json_last_error_msg();
            throw new RuntimeException("Claude não retornou JSON válido (artigo/$formato). json_error: $jsonErr. Primeiros 600 chars: " . substr($texto, 0, 600));
        }
        $json['products']    = []; // força vazio
        $json['_formato']    = $formato;
        $json['_formato_nome'] = $f['nome'];
        $json['schema_type'] = $json['schema_type'] ?? $f['schema'];
        return $json;
    }

    /**
     * PROMPT 1 — Filtra os melhores títulos do RSS para Discover (rigoroso).
     * Retorna array de até 4 títulos aprovados.
     */
    public function filtrarTitulosDiscover(array $titulosRss): array
    {
        if (empty($titulosRss)) return [];
        $lista = implode("\n", array_map(fn($t, $i) => ($i+1) . '. ' . $t, $titulosRss, array_keys($titulosRss)));

        $system = <<<SYS
Você é um editor-chefe especialista em Google Discover para o nicho de educação, emprego, benefícios e cursos no Brasil.

Sua tarefa: analisar títulos de notícias e selecionar APENAS os que têm potencial real de viralizar no Google Discover.

Seja MUITO RIGOROSO. Selecione no máximo 4 títulos.

APROVAÇÃO (precisa de pelo menos 3 critérios):
- Palavra "gratuito/grátis" + número expressivo de vagas
- Instituição conhecida (SENAI, SENAC, MEC, CEDERJ, universidades federais grandes, INSS, Caixa, Governo)
- Apelo universal (não nicho restrito)
- Urgência ("inscrições abertas", "últimos dias", "prazo", "liberado")
- Área popular (tecnologia, administração, saúde, concursos, benefícios, salário)
- Valor/dinheiro mencionado (R$, salário, auxílio, bolsa)

REJEIÇÃO AUTOMÁTICA:
- Seleção interna de tutores/professores/coordenadores
- Siglas desconhecidas sem contexto
- Tema ultra-nichado (ex: "paleontologia marinha")
- Títulos com mais de 100 caracteres
- Eventos locais muito pequenos sem alcance nacional
- Matérias sobre premiações internas de instituições

Responda APENAS com JSON: {"aprovados":[{"indice":N,"titulo":"...","motivo":"1 frase do porquê"}]}
SYS;

        $user = "Analise estes títulos de RSS e selecione os melhores para Discover:\n\n" . $lista;
        $resp = $this->call([['role' => 'user', 'content' => $user]], $system, 1500);
        $json = $this->extractJson($resp['content'][0]['text'] ?? '');
        return $json['aprovados'] ?? [];
    }

    /**
     * PROMPT 2 — Gera o título FINAL para Discover cruzando título anterior + conteúdo scrapeado.
     * Retorna string com o título vencedor (texto puro, 55-68 chars).
     */
    public function gerarTituloDiscover(string $tituloAnterior, string $conteudoCompleto): string
    {
        $mesAtual = $this->mesAtual();
        $anoAtual = date('Y');
        $hojeStr  = date('d/m/Y');
        $diasSemana = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
        $diaSemana  = $diasSemana[(int)date('w')];

        if (mb_strlen($conteudoCompleto) > 8000) $conteudoCompleto = mb_substr($conteudoCompleto, 0, 8000) . '…';

        // Carrega regras do manifesto editorial (separado de CLAUDE.md desde 2026-05-02)
        $manifestoPath = dirname(__DIR__) . '/prompts/manifesto_editorial.md';
        $manifesto = file_exists($manifestoPath) ? (string)file_get_contents($manifestoPath) : '';
        $manifestoSection = '';
        // Extrai a seção de título do manifesto (aceita ambos os formatos)
        if (preg_match('/### TÍTULO.*?(?=\n## |\n---|\z)/s', $manifesto, $m)) {
            $manifestoSection = "REGRAS ABSOLUTAS DE TÍTULO (prioridade máxima):\n" . trim($m[0]);
        }

        $system = <<<SYS
{$manifestoSection}

Você é Engenheiro de CTR para Google Discover. Data: {$hojeStr} ({$diaSemana}), {$mesAtual} de {$anoAtual}.

MISSÃO: criar o título mais MAGNÉTICO possível. Scroll-stop. O dedo do usuário PARA.

## PASSO 1 — MINERAÇÃO (antes de tudo)
Do conteúdo, extraia:
- O DADO MAIS IMPACTANTE (número, valor, prazo, mudança)
- Entidade principal + localidade
- O TWIST (detalhe surpresa, contradição, revelação inesperada)
- Benefício tangível pro leitor

## PASSO 2 — REJEIÇÃO DO TÍTULO ANTERIOR
O título "{$tituloAnterior}" provavelmente é FRACO. Teste:
- Gera reação "ah, legal"? → LIXO, reescrever do zero
- Parece release de imprensa/notícia local? → LIXO
- Descreve sem provocar? → LIXO
- Cria pergunta na cabeça? → pode aproveitar
Se for fraco (quase sempre é), IGNORE e crie do zero baseado no conteúdo.

## PASSO 3 — FÓRMULA: BENEFÍCIO + AUTORIDADE + URGÊNCIA
O título DEVE combinar os 3 elementos. Gere 3 variações:

VARIAÇÃO A (BENEFÍCIO lidera):
   "[Benefício concreto] + [entidade que valida] + [sinal temporal]"
   "Curso gratuito do Senac ensina a lucrar com chocolate em 3 dias"
   "15 mulheres de Pinheiros ganham formação gratuita do Senac com foco em renda"

VARIAÇÃO B (IMPACTO lidera):
   "[O que aconteceu + escala] + [quem fez] + [consequência]"
   "Senac leva curso inédito a comunidade rural e muda rotina de 15 mulheres"
   "Comunidade de Pinheiros recebe curso do Senac que já forma com preço de venda"

VARIAÇÃO C (TWIST lidera):
   "[Dado + contexto] + mas/e + [detalhe inesperado]"
   "15 mulheres aprenderam a fazer chocolate, e o diferencial está na precificação"
   "Curso de 3 dias do Senac não ensina só receita: o foco é lucrar"

ESCOLHA: qual combina MELHOR benefício + autoridade + urgência em 55-68 chars.

## PASSO 4 — REGRAS TÉCNICAS
- 55-68 caracteres (reescrever se ultrapassar)
- NUNCA travessão (—) ou ponto e vírgula (;). Use vírgula, dois pontos, "e", "mas", "só que"
- Impacto nas PRIMEIRAS 5 PALAVRAS
- Cada dado DEVE existir no conteúdo
- ZERO emojis, ZERO adjetivos vazios, ZERO "veja como", "saiba mais", "confira"
- NÃO crave data fixa (envelhece). Use "nesta semana", "nos próximos dias"

## PASSO 5 — GERAR 3 + ESCOLHER 1
Gere 3 variações com ângulos DIFERENTES. Escolha a que:
- Provoca "sério? quero saber" (não "ah, legal")
- Tem gap mental claro (leitor precisa abrir pra descobrir)
- Funciona em tela mobile (info principal não trunca)
- Soa como manchete provocativa, NÃO como release

## RETORNO
Retorne APENAS o título vencedor. Texto puro. Nada mais.
SYS;

        $user = "TÍTULO ANTERIOR: {$tituloAnterior}\n\nCONTEÚDO COMPLETO:\n{$conteudoCompleto}";
        $resp = $this->call([['role' => 'user', 'content' => $user]], $system, 500);
        $titulo = trim($resp['content'][0]['text'] ?? '');
        // Remove aspas/markdown se Claude envolver
        $titulo = trim($titulo, "\"'`\n\r ");
        return $titulo;
    }

    /**
     * Extrai termos virais de um briefing scrapeado — para cluster SEO.
     * Retorna array de {termo, tipo, sugestao_uso}.
     * Chamada leve (~500 tokens).
     */
    public function extrairTermosVirais(string $keyword, string $briefing): array
    {
        $system = "Você é analista SEO especializado em Google Discover e viralização. Responda APENAS com JSON válido.";
        $user = "KEYWORD: {$keyword}\n\nBRIEFING SCRAPEADO:\n" . mb_substr($briefing, 0, 5000)
            . "\n\nEXTRAIA os 10 termos mais poderosos deste conteúdo para viralizar no Google Discover e buscas.\n"
            . "Classifique cada um e sugira como usar no artigo.\n\n"
            . 'Retorne JSON: {"termos":[{"termo":"...","tipo":"urgencia|longtail|discover|pergunta|entidade|emocional","sugestao":"como usar no artigo (1 frase)"}]}';
        $resp = $this->call([['role' => 'user', 'content' => $user]], $system, 1500);
        $texto = $resp['content'][0]['text'] ?? '';
        $json = $this->extractJson($texto);
        return $json['termos'] ?? [];
    }

    /**
     * Gera APENAS tags para um post existente (sem rewrite do conteúdo).
     * Muito mais leve/barato que gerarPost/atualizarPost — só uma chamada curta.
     * Retorna um array de strings (5-8 tags).
     */
    public function gerarTags(string $titulo, string $htmlConteudo, string $keywordExtra = ''): array
    {
        $texto = trim(strip_tags($htmlConteudo));
        if (mb_strlen($texto) > 6000) $texto = mb_substr($texto, 0, 6000) . '…';

        $system = 'Você é um especialista em SEO brasileiro. Sua tarefa é gerar tags precisas e relevantes para posts de blog. Responda APENAS com JSON válido, sem markdown, sem explicações.';

        $user = "TÍTULO: {$titulo}\n"
            . ($keywordExtra ? "CONTEXTO: {$keywordExtra}\n" : '')
            . "\nCONTEÚDO DO POST:\n{$texto}\n\n"
            . "TAREFA: Gere de 5 a 8 tags curtas (1-3 palavras cada) que representem o tema do post.\n"
            . "Regras:\n"
            . "- Tags devem ser específicas ao conteúdo, não genéricas (\"notícias\", \"blog\" NÃO)\n"
            . "- Português brasileiro, minúsculas, sem acentos nas tags\n"
            . "- Misture: 1-2 tags da categoria macro, 2-3 do subtópico, 1-2 long-tail\n"
            . "- NUNCA use emojis\n"
            . "- Evite plural desnecessário\n\n"
            . "Responda apenas com JSON neste formato exato:\n"
            . '{"tags":["tag1","tag2","tag3","tag4","tag5"]}';

        $resp = $this->call([['role' => 'user', 'content' => $user]], $system, 1000);
        $textoResp = $resp['content'][0]['text'] ?? '';
        $json = $this->extractJson($textoResp);
        if (!$json || empty($json['tags']) || !is_array($json['tags'])) {
            throw new RuntimeException('Claude não retornou tags válidas: ' . substr($textoResp, 0, 400));
        }
        // Normaliza: strings, trim, sem vazias, dedupe
        $tags = [];
        $seen = [];
        foreach ($json['tags'] as $t) {
            $t = trim((string)$t);
            if ($t === '') continue;
            $k = mb_strtolower($t);
            if (isset($seen[$k])) continue;
            $seen[$k] = 1;
            $tags[] = $t;
        }
        return $tags;
    }

    /**
     * Refresh de post existente — preserva URL/slug, aproveita conteúdo antigo
     * como base histórica e INCORPORA fontes novas para gerar uma versão
     * atualizada (datas, preços, modelos, dados novos do ano vigente).
     */
    public function atualizarPost(string $keyword, string $htmlAntigo, array $fontesNovas, string $formato = 'seo', array $blocosCustom = []): array
    {
        if (!isset(self::$formatos[$formato])) {
            throw new InvalidArgumentException("Formato desconhecido: $formato");
        }
        $f = self::$formatos[$formato];
        $briefing = $this->montarBriefing($fontesNovas);
        // Preserva tabelas/listas completas — estrutura tabular é crítica para cronogramas (NIS, vagas, calendários)
        $antigo = trim(strip_tags($htmlAntigo, '<h2><h3><h4><p><strong><em><ul><ol><li><a><table><thead><tbody><tfoot><tr><th><td><caption>'));
        if (mb_strlen($antigo) > 14000) $antigo = mb_substr($antigo, 0, 14000) . '…';

        // Conta linhas da tabela do post antigo para exigir preservação integral
        $linhasTabela = 0;
        if (preg_match_all('#<tr\b[^>]*>.*?</tr>#is', $htmlAntigo, $mTr)) $linhasTabela = count($mTr[0]);
        $itensLista = 0;
        if (preg_match_all('#<li\b[^>]*>.*?</li>#is', $htmlAntigo, $mLi)) $itensLista = count($mLi[0]);

        // Contexto temporal absoluto — base para re-tensão de verbos
        $diasSemana = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
        $hojeStr    = date('d/m/Y');
        $diaNum     = (int)date('d');
        $diaSemana  = $diasSemana[(int)date('w')];
        $mesAtual   = $this->mesAtual();
        $anoAtual   = (int)date('Y');
        $ontemStr   = date('d/m/Y', strtotime('-1 day'));
        $amanhaStr  = date('d/m/Y', strtotime('+1 day'));

        $system = $this->montarSystem($f, $blocosCustom)
            . "\n\nMODO: REFRESH DE POST EXISTENTE\n"
            . "DATA DE HOJE: {$hojeStr} ({$diaSemana}) — dia {$diaNum} de {$mesAtual} de {$anoAtual}.\n"
            . "ONTEM: {$ontemStr} | AMANHÃ: {$amanhaStr}\n\n"
            . "REGRA DE TEMPORALIDADE (CRÍTICA — E-E-A-T e credibilidade):\n"
            . "Para CADA data mencionada no conteúdo antigo, comparar com hoje ({$hojeStr}) e ajustar tempo verbal:\n"
            . "- Data ANTERIOR a hoje → PASSADO (\"começaram\", \"foram pagos\", \"aconteceu\"). Se for o dia anterior, preferir \"ontem, dia {$ontemStr}\".\n"
            . "- Data IGUAL a hoje → PRESENTE (\"hoje, {$hojeStr}\", \"nesta {$diaSemana}\", \"começa hoje\").\n"
            . "- Data POSTERIOR a hoje → FUTURO (\"começam\", \"serão pagos\", \"acontecerá\"). Se for o próximo dia, preferir \"amanhã, dia {$amanhaStr}\".\n"
            . "- Calendários/cronogramas: marcar quais linhas JÁ ocorreram (passado) e quais AINDA vão ocorrer (futuro) — nunca deixar tudo no presente/futuro indistinto.\n"
            . "- Exemplo: se o artigo antigo diz \"pagamentos começam no dia 16\" e hoje é 17, reescrever para \"pagamentos começaram ontem, dia 16\" (nunca manter no futuro).\n"
            . "- Nada pode ficar datado do passado sem marcação temporal clara. Nada pode anunciar como futuro um evento que já aconteceu.\n\n"
            . "REGRA DE INTEGRIDADE (CRÍTICA — fidelidade factual):\n"
            . "- O post antigo tem " . ($linhasTabela > 0 ? "uma tabela com {$linhasTabela} linhas (<tr>). REPRODUZA TODAS — jamais omita linhas." : "sem tabela detectada.") . "\n"
            . "- O post antigo tem " . ($itensLista > 0 ? "{$itensLista} itens de lista (<li>). REPRODUZA TODOS — jamais omita itens." : "sem listas detectadas.") . "\n"
            . "- Cronogramas de pagamento (NIS, benefícios, calendários) DEVEM aparecer na íntegra: se o original lista NIS final 1 até NIS final 0, o novo também lista de 1 a 0, sem pular nenhum.\n"
            . "- Listagens de vagas/cursos/cargos/benefícios: preservar TODOS os itens — se sumir um, o refresh está ERRADO.\n"
            . "- Antes de finalizar, conte os itens da tabela/lista do seu rascunho e compare com o original — se faltar algum, volte e inclua.\n\n"
            . "OUTRAS REGRAS DE REFRESH:\n"
            . "- Você está ATUALIZANDO um artigo já publicado, não criando do zero.\n"
            . "- Preserve a ESSÊNCIA, a estrutura de seções e o tom que já funcionou.\n"
            . "- Troque preços antigos, estatísticas e dados temporais pelos valores vigentes nas fontes novas.\n"
            . "- Adicione pelo menos UMA seção nova (novidade/tendência deste mês/ano vigente).\n"
            . "- Reforce a atualização: inclua \"Atualizado em {$hojeStr}\" no primeiro parágrafo.\n"
            . "- No título, se houver referência temporal, trazer para o momento atual (mês/ano vigente) sem distorcer o fato.\n"
            . "- NUNCA mude o slug (vai ser preservado no WP).\n"
            . "- Objetivo: Google precisa perceber que é conteúdo FRESCO, factualmente correto no dia de hoje, com tempos verbais coerentes.";

        $userExtra = "DATA DE HOJE: {$hojeStr} ({$diaSemana})\n"
            . "Ao reescrever, confira CADA data citada no conteúdo antigo contra hoje e ajuste o tempo verbal (passado/presente/futuro).\n\n"
            . "CONTEÚDO ATUAL DO POST (para referência — use como base, não copie literal):\n\n"
            . $antigo
            . "\n\n---\n\nFONTES NOVAS DE PESQUISA (use para atualizar dados/tendências):\n\n"
            . $briefing;

        $user = $this->montarUser($keyword, $f, $userExtra);

        $resp = $this->call([['role' => 'user', 'content' => $user]], $system);
        $texto = $resp['content'][0]['text'] ?? '';
        $json = $this->extractJson($texto);
        if (!$json) {
            $jsonErr = json_last_error_msg();
            throw new RuntimeException("Claude não retornou JSON válido (refresh/$formato). json_error: $jsonErr. Primeiros 800 chars: " . substr($texto, 0, 800));
        }
        $json['_formato'] = $formato;
        $json['_formato_nome'] = $f['nome'];
        $json['_refresh'] = true;
        $json['schema_type'] = $json['schema_type'] ?? $f['schema'];
        return $json;
    }

    private function montarSystem(array $f, array $blocosCustom): string
    {
        $mesAtual = $this->mesAtual();
        $anoAtual = date('Y');

        $base = <<<TXT
Você é um redator brasileiro especialista em SEO, Google Discover e Google News.
Sua missão: criar um artigo MELHOR que todos os concorrentes da primeira página do Google.

DATA ATUAL: {$mesAtual} de {$anoAtual}
REGRA DE DATA: Use SOMENTE {$mesAtual} de {$anoAtual} como referência. NUNCA copie datas das fontes scrapeadas. "Atualizado em {$mesAtual} de {$anoAtual}".

FORMATO ALVO: {$f['nome']}
ESTILO: {$f['estilo']}
TOM: {$f['tom']}

REGRA DE TÍTULO: {$f['titulo']}
REGRA DE INTRO: {$f['intro']}
EXTRAS DO FORMATO: {$f['extras']}

Regras universais:
- Português brasileiro natural, tom humano (não parecer IA)
- Conteúdo ORIGINAL — use as fontes como referência factual, NUNCA copie frases
- Cite dados, números, comparações (E-E-A-T)
- HTML semântico válido (h2, h3, p, ul, ol, strong, table) — sem markdown
- NÃO inclua h1 (o WordPress renderiza o título)
- Mínimo 1200 palavras de conteúdo real
- NÃO use emojis em títulos, subtítulos, labels, parágrafos ou listas. Emojis SÓ são permitidos em CTAs (textos de botões de compra — cta_text), nunca em outros lugares.
TXT;

        // Anexa blocos customizados não vazios
        $blocosLimpos = array_values(array_filter(array_map('trim', $blocosCustom), fn($b) => $b !== ''));
        if (!empty($blocosLimpos)) {
            $base .= "\n\nINSTRUÇÕES ADICIONAIS DO USUÁRIO:\n";
            foreach ($blocosLimpos as $i => $b) {
                $n = $i + 1;
                $base .= "\n[Bloco $n]\n$b\n";
            }
        }

        return $base;
    }

    private function montarUser(string $keyword, array $f, string $briefing): string
    {
        $schema = <<<JSON
{
  "title": "título conforme regra do formato",
  "slug": "slug-url-amigavel-curto",
  "excerpt": "resumo máx 160 chars",
  "meta_title": "title SEO máx 60 chars",
  "meta_description": "meta desc 150-160 chars",
  "focus_keyword": "keyword exata",
  "secondary_keywords": ["5-8 secundárias"],
  "intro_paragraphs": ["parágrafo 1 (hook)", "parágrafo 2 (EEAT)", "parágrafo 3 (promessa)"],
  "products": [
    {
      "name": "Nome do produto",
      "brand": "Marca",
      "badge": "Melhor geral | Melhor custo-benefício | etc",
      "image": "URL imagem (da fonte scrapeada)",
      "description": "1-2 frases",
      "price": 1299.90,
      "price_display": "R$ 1.299",
      "currency": "BRL",
      "rating": 9.0,
      "affiliate_url": "URL encontrada na fonte",
      "video_url": "URL de vídeo oficial (YouTube/Vimeo/MP4) do produto, se encontrar nas fontes — senão string vazia",
      "store": "Loja",
      "for_whom": "Pra quem é",
      "review_text": "2-3 parágrafos de review",
      "pros": ["pro1", "pro2", "pro3"],
      "cons": ["contra1", "contra2"],
      "why_recommend": "Por que recomendamos",
      "cta_text": "Ver preço do X na Y →",
      "specs": {}
    }
  ],
  "content_html": "SE NÃO for review de produtos, coloque o artigo completo aqui em HTML. SE for review, deixe vazio.",
  "decision_block": {
    "title": "Resumo rápido: qual escolher",
    "picks": [
      {"label": "Melhor geral:", "product_name": "nome", "reason": "1 linha do porquê", "cta_text": "💰 Ver preço →", "affiliate_url": "URL"},
      {"label": "Custo-benefício:", "product_name": "nome", "reason": "1 linha", "cta_text": "💰 Ver oferta →", "affiliate_url": "URL"},
      {"label": "Mais barato:", "product_name": "nome", "reason": "1 linha", "cta_text": "💰 Ver preço →", "affiliate_url": "URL"}
    ]
  },
  "vs_comparisons": [
    {"title": "Produto A vs Produto B", "text": "parágrafos comparando (pode ter \\n)", "winner": "quem ganha e pra quem"}
  ],
  "buying_guide": "HTML com dicas de compra (h3, p, ul) — sem h1/h2",
  "faq": [{"q": "pergunta", "a": "resposta"}],
  "tags": ["5 tags"],
  "categories": ["1 categoria"],
  "hero_alt": "alt da imagem",
  "schema_type": "{$f['schema']}",
  "is_howto": false,
  "howto_steps": []
}
JSON;

        return <<<TXT
PALAVRA-CHAVE: {$keyword}
FORMATO: {$f['nome']} ({$f['estilo']})

BRIEFING DAS FONTES (top 5 do Google):
{$briefing}

TAREFA:
1. Analise as fontes — identifique gaps de conteúdo
2. Crie artigo SUPERIOR no formato {$f['nome']}
3. Se as fontes contêm listas de produtos/reviews: retorne products[] com dados estruturados (o PHP monta o HTML)
4. Se NÃO for review de produtos: retorne content_html com o artigo completo
5. SEMPRE retorne intro_paragraphs com 3 parágrafos curtos
6. SEMPRE retorne decision_block (resumo rápido no topo com 3 picks: melhor geral, custo-benefício, mais barato) — OBRIGATÓRIO em qualquer artigo que mencione produtos
7. SEMPRE retorne vs_comparisons com 2-3 comparações diretas "X vs Y" — OBRIGATÓRIO quando houver 2+ produtos concorrentes
8. FAQ com perguntas reais do "People also ask"
7. Se encontrar preços nas fontes, use price como número (ex: 1299.90)
8. Se encontrar links de afiliado/lojas, use em affiliate_url
9. IMAGENS: use as URLs de imagem (IMG:) das fontes no campo "image" de cada produto. Associe pelo nome/alt do produto. Se não achar imagem específica, use a og:image da fonte. NUNCA deixe "image" vazio.

REGRAS CRÍTICAS DE FORMATAÇÃO:
- Responda APENAS com JSON válido (sem markdown, sem ```, sem explicação)
- content_html em UMA LINHA (escape quebras como \\n, aspas como \\")
- NÃO use quebras de linha literais dentro de valores de string

Schema:
{$schema}
TXT;
    }

    private function montarBriefing(array $fontes): string
    {
        $out = '';
        foreach ($fontes as $i => $f) {
            $n = $i + 1;
            $m = $f['meta'];
            $c = $f['content'];

            $out .= "=== FONTE {$n}: {$m['site_name']} ===\n";
            $out .= "URL: {$m['url']}\n";
            $out .= "Título: {$m['title']}\n";
            if ($m['description']) $out .= "Descrição: {$m['description']}\n";
            if (!empty($m['og_image'])) $out .= "og:image: {$m['og_image']}\n";

            if (!empty($c['headings'])) {
                $out .= "\nEstrutura:\n";
                foreach (array_slice($c['headings'], 0, 15) as $h) {
                    $out .= "- [{$h['tag']}] {$h['text']}\n";
                }
            }
            if (!empty($c['paragraphs'])) {
                $out .= "\nConteúdo:\n";
                foreach (array_slice($c['paragraphs'], 0, 25) as $p) {
                    $out .= "• " . mb_substr($p, 0, 400) . "\n";
                }
            }
            if (!empty($c['lists'])) {
                $out .= "\nListas:\n";
                foreach (array_slice($c['lists'], 0, 3) as $l) {
                    foreach (array_slice($l, 0, 8) as $item) {
                        $out .= "  - " . mb_substr($item, 0, 200) . "\n";
                    }
                }
            }
            if (!empty($c['images'])) {
                $out .= "\nImagens encontradas:\n";
                foreach (array_slice($c['images'], 0, 15) as $img) {
                    $src = $img['src'] ?? '';
                    $alt = $img['alt'] ?? '';
                    if ($src) $out .= "  IMG: {$src}" . ($alt ? " (alt: {$alt})" : '') . "\n";
                }
            }
            $out .= "\n\n";
        }
        return $out;
    }

    /** Versão pública do call — usada pelo DebateBuilder */
    public function callPublic(array $messages, string $system, int $maxTokens = 16000): array
    {
        return $this->call($messages, $system, $maxTokens);
    }

    // max_tokens 16k é suficiente pra artigo de 2000 palavras + JSON completo.
    // 48k estava inflado — Claude leva tempo proporcional ao reservado.
    private function call(array $messages, string $system, int $maxTokens = 16000): array
    {
        // PROMPT CACHING (Anthropic) — reduz custo input em ~90% pra blocos repetidos.
        //
        // Estratégia em 3 níveis:
        //  (a) Marker explícito `<!--CACHE_BREAK-->` no system: split em 2 blocos —
        //      ANTES = cacheado (ephemeral 5min), DEPOIS = sem cache (varia por trend).
        //      Maximiza cache hit em rajadas de geração que compartilham o manifesto
        //      editorial, mas têm briefing/persona específicos.
        //  (b) System grande (>=2000 chars) sem marker → cacheia bloco único.
        //  (c) System pequeno → vai como string (não-cacheado, comportamento default).
        $payload = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'system'     => self::montarSystemPayload($system),
            'messages'   => $messages,
        ];

        // Circuit breaker: se Anthropic teve 3 falhas em 60s, próximas chamadas falham
        // rápido com CircuitOpenException (DiscoverGerador captura → marca aguardando_llm).
        require_once __DIR__ . '/CircuitBreaker.php';
        $cb = new CircuitBreaker('anthropic');
        $cb->guarda();

        // HttpClient: timeout 180s + retry 2 tentativas em 5xx/timeout (LLM falha transitória).
        require_once __DIR__ . '/HttpClient.php';
        $r = HttpClient::request('POST', 'https://api.anthropic.com/v1/messages', [
            'json'    => $payload,
            'headers' => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            'timeout' => 180,
            'tries'   => 2,
            'backoff' => [0, 5],  // 5s entre tentativas pra rate limit não disparar de novo
        ]);

        if (!$r['ok']) {
            $msg = $r['error'] ?? 'falha desconhecida';
            // Conta como falha SOMENTE se for transitório (5xx, 429, timeout). Erros 4xx
            // permanentes (401/403/400) não devem abrir circuit (problema de config, não API down).
            if (self::ehFalhaTransitoria((int)$r['http_code'])) {
                $cb->falha("HTTP {$r['http_code']}: {$msg}");
            }
            if ($r['http_code'] === 0) throw new RuntimeException("Anthropic timeout/network: {$msg}");
            throw new RuntimeException("Anthropic HTTP {$r['http_code']}: " . substr($r['body'], 0, 500));
        }
        $data = $r['json'];
        if (!$data) {
            $cb->falha('JSON inválido na resposta');
            throw new RuntimeException('Anthropic JSON inválido');
        }

        // Checa se resposta foi truncada por max_tokens
        $stopReason = $data['stop_reason'] ?? '';
        if ($stopReason === 'max_tokens') {
            // NÃO conta como falha de API (resposta chegou; problema é de prompt) — não abre circuit.
            throw new RuntimeException('Resposta do Claude foi truncada (max_tokens atingido). O conteúdo é grande demais pra uma chamada. Tente com menos fontes ou prompt mais curto.');
        }

        // Log usage stats pra CostTracker
        try {
            if (!empty($data['usage']) && is_array($data['usage'])) {
                self::logCacheStats($data['usage']);
            }
        } catch (Throwable $e) { /* log é opcional */ }

        $cb->sucesso();
        return $data;
    }

    /** HTTP code que justifica abrir circuit (API genuinamente em problema). */
    private static function ehFalhaTransitoria(int $code): bool
    {
        // 0 = timeout/network. 408 = request timeout. 429 = rate limit. 5xx = server error.
        return $code === 0 || $code === 408 || $code === 429 || ($code >= 500 && $code <= 599);
    }

    /**
     * Monta o `system` pra Anthropic API conforme presença de marker e tamanho.
     * Permite cache_control granular sem mudar callers existentes.
     *
     * @return string|array string (sem cache) ou array de blocos (com/sem cache_control)
     */
    public static function montarSystemPayload(string $system)
    {
        $marker = '<!--CACHE_BREAK-->';
        // (a) Split por marker explícito
        if (strpos($system, $marker) !== false) {
            [$cacheado, $variavel] = explode($marker, $system, 2);
            $blocos = [];
            if (trim($cacheado) !== '') {
                $blocos[] = [
                    'type'          => 'text',
                    'text'          => trim($cacheado),
                    'cache_control' => ['type' => 'ephemeral'],
                ];
            }
            if (trim($variavel) !== '') {
                $blocos[] = ['type' => 'text', 'text' => trim($variavel)];
            }
            return !empty($blocos) ? $blocos : $system;
        }
        // (b) System grande sem marker → bloco único cacheado
        if (strlen($system) >= 2000) {
            return [[
                'type'          => 'text',
                'text'          => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        }
        // (c) Pequeno → string direta
        return $system;
    }

    /**
     * Loga métricas de cache do response Anthropic em data/cache_stats.jsonl.
     * Anthropic retorna em `usage`: input_tokens, cache_creation_input_tokens,
     * cache_read_input_tokens, output_tokens. Permite CostTracker calcular savings.
     */
    public static function logCacheStats(array $usage): void
    {
        $cacheRead     = (int)($usage['cache_read_input_tokens'] ?? 0);
        $cacheCreation = (int)($usage['cache_creation_input_tokens'] ?? 0);
        $input         = (int)($usage['input_tokens'] ?? 0);
        $output        = (int)($usage['output_tokens'] ?? 0);

        $linha = json_encode([
            'ts'              => date('c'),
            'input_tokens'    => $input,
            'cache_creation'  => $cacheCreation,
            'cache_read'      => $cacheRead,
            'output_tokens'   => $output,
            'cache_hit_ratio' => ($input + $cacheRead) > 0
                ? round($cacheRead / ($input + $cacheRead), 3)
                : 0,
        ], JSON_UNESCAPED_UNICODE);

        $dir = __DIR__ . '/../data/cost_tracker';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $file = $dir . '/llm_calls.jsonl';
        @file_put_contents($file, $linha . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Gera uma landing page de alta conversão para tráfego pago.
     *
     * @param string $keyword        ex: "melhores celulares ate 1500"
     * @param array  $produtosJson   produtos manuais (do JSON colado pelo usuário)
     * @param array  $fontes         fontes scrapeadas (do Serper+Scraper) — pode ser []
     * @param array  $blocosCustom   blocos extras de instrução
     * @return array                 landing page estruturada pronta pro WP
     */
    public function gerarLanding(string $keyword, array $produtosJson, array $fontes = [], array $blocosCustom = []): array
    {
        $system = $this->systemLanding($blocosCustom);
        $user   = $this->userLanding($keyword, $produtosJson, $fontes);

        $resp = $this->call([['role' => 'user', 'content' => $user]], $system, 16000);
        $texto = $resp['content'][0]['text'] ?? '';
        $json = $this->extractJson($texto);
        if (!$json) {
            $jsonErr = json_last_error_msg();
            throw new RuntimeException("Claude não retornou JSON válido (landing). json_error: $jsonErr. Primeiros 800 chars: " . substr($texto, 0, 800));
        }
        return $json;
    }

    private function systemLanding(array $blocosCustom): string
    {
        $mesAtual = $this->mesAtual();
        $anoAtual = date('Y');

        $base = <<<TXT
Você é um especialista brasileiro em reviews de produtos com experiência prática.
Você NÃO é neutro. Você DECIDE pelo usuário. Você RECOMENDA com convicção.
Referências: melhorfonedeouvido.com.br, top.tudotestado.com, thewirecutter.com

DATA: {$mesAtual} de {$anoAtual}. NUNCA use datas das fontes.

RETORNE DADOS ESTRUTURADOS em JSON. O PHP monta o HTML.

REGRAS DE TOM:
- Opinião CLARA — sem neutralidade. Diga qual é o melhor e por quê.
- Linguagem natural: "pra", "né", "na prática", "vale muito a pena"
- Parágrafos curtos (máx 3 linhas). Frases diretas.
- PROIBIDO: "em suma", "conclui-se", "no entanto", linguagem genérica
- Use perguntas retóricas: "Mas será que vale mesmo?"
- Cada review deve ter 1 frase de decisão: "Vale a pena se você…"

REGRAS DE CONTEÚDO:
- Conteúdo ORIGINAL — fontes são referência, NUNCA copie
- E-E-A-T: demonstre experiência prática pelo JEITO de escrever (detalhes de uso real, trade-offs específicos, "o que incomoda no dia a dia") — NÃO invente estatísticas nem cite "X modelos analisados", "Xh de pesquisa" ou "+X avaliações". Nunca use números de revisão/pesquisa fabricados.
- Prós honestos (3-5), contras honestos (2-3) — confiança > venda
- Preço numérico (ex: 299.90) pra schema Google
- Rating de 0 a 10

REGRAS DE CONVERSÃO:
- Cada produto TEM que ter CTA forte e específico (nome do produto + loja)
- NÃO MOSTRAR PREÇO EXATO nos CTAs nem no topo do card. Preço mata clique. Curiosidade gera clique.
- CTA principal de CURIOSIDADE: "🔥 Ver oferta de hoje", "👉 Conferir preço atual", "Ver oferta na [Loja] →"
- 1 botão principal (Amazon) + até 2 alternativas: ML="Comprar no Mercado Livre", Shopee="Comprar na Shopee"
- SEMPRE em português. NUNCA usar "deal", "check", "view" ou qualquer termo em inglês nos CTAs
- Gatilhos: "mais vendido #1", "+2.000 avaliações", "nota 4.7", "pode esgotar"
- price_display no JSON: usar faixa ("R$ 100–150") ou valor aproximado — aparece SÓ no meio do review, não no topo
- NUNCA CTA genérico ("compre agora", "saiba mais", "clique aqui")

BLOCOS OBRIGATÓRIOS NO JSON:
1. decision_block: picks rápidos no topo (melhor geral, custo-benefício, barato) com motivo + CTA
2. vs_comparisons: comparações diretas "X vs Y" com veredicto claro (quem deve escolher qual)
3. common_mistake: "O erro que muita gente comete ao comprar [categoria]" (2-3 parágrafos)
4. final_recommendation: grade final (quer economizar → X, quer equilíbrio → Y, quer o melhor → Z)
5. products: com alt_stores (lojas alternativas com label diferenciado)
TXT;

        $blocosLimpos = array_values(array_filter(array_map('trim', $blocosCustom), fn($b) => $b !== ''));
        if (!empty($blocosLimpos)) {
            $base .= "\n\nINSTRUÇÕES ADICIONAIS:\n";
            foreach ($blocosLimpos as $i => $b) {
                $n = $i + 1;
                $base .= "\n[Bloco $n]\n$b\n";
            }
        }

        return $base;
    }

    private function userLanding(string $keyword, array $produtosJson, array $fontes): string
    {
        $schema = <<<'JSON'
{
  "title": "título magnético, máx 70 chars",
  "slug": "slug-url",
  "meta_title": "title SEO, máx 60 chars",
  "meta_description": "meta desc 150-160 chars",
  "focus_keyword": "keyword principal",
  "intro_paragraphs": ["parágrafo 1 (hook forte)", "parágrafo 2 (EEAT)", "parágrafo 3 (promessa)"],
  "decision_block": {
    "title": "🔥 Qual [produto] vale mais a pena em 2026?",
    "picks": [
      {"label": "🏆 Melhor geral", "product_name": "Nome", "reason": "1 frase forte", "cta_text": "Ver preço na Amazon →", "affiliate_url": "url"},
      {"label": "💰 Melhor custo-benefício", "product_name": "Nome", "reason": "1 frase", "cta_text": "Ver oferta →", "affiliate_url": "url"},
      {"label": "🔥 Melhor barato", "product_name": "Nome", "reason": "1 frase", "cta_text": "Ver preço →", "affiliate_url": "url"}
    ]
  },
  "products": [
    {
      "name": "Nome",
      "brand": "Marca",
      "badge": "Melhor geral",
      "image": "URL imagem",
      "description": "1-2 frases",
      "price": 2699.90,
      "price_display": "R$ 2.699",
      "currency": "BRL",
      "rating": 9.2,
      "review_count": 3000,
      "sku": "ASIN ou código do produto (ex: B0BTY976CH)",
      "affiliate_url": "URL principal (melhor loja)",
      "video_url": "URL de vídeo oficial do produto (YouTube/Vimeo/MP4) se encontrar nas fontes — senão vazio",
      "store": "Amazon",
      "cta_text": "🔥 Ver preço com desconto na Amazon",
      "alt_stores": [
        {"store": "Mercado Livre", "url": "url ou vazio", "label": "Comprar no Mercado Livre"},
        {"store": "Shopee", "url": "url ou vazio", "label": "Comprar na Shopee"}
      ],
      "for_whom": "Pra quem é (1 frase)",
      "review_text": "2-3 parágrafos, opinião clara, decisão",
      "decision_line": "Vale a pena se você… (1 frase de fechamento)",
      "pros": ["pro1", "pro2", "pro3"],
      "cons": ["contra1", "contra2"],
      "why_recommend": "Por que recomendamos",
      "specs": {}
    }
  ],
  "vs_comparisons": [
    {"title": "Sony vs AirPods: qual escolher?", "text": "2-3 parágrafos comparando. Veredicto claro: quem deve escolher qual.", "winner": "Nome do vencedor"}
  ],
  "common_mistake": "2-3 parágrafos sobre o erro que muita gente comete ao comprar [categoria]",
  "cross_sell": {
    "title": "Quem comprou também montou",
    "intro": "1 frase explicando o contexto (ex: pessoas trocando de cozinha, montando home office, etc.)",
    "items": [
      {"category": "Geladeira", "reason": "pra combinar com o fogão", "keyword_search": "melhores geladeiras 2026"},
      {"category": "Airfryer", "reason": "pra complementar o preparo", "keyword_search": "melhores airfryers 2026"},
      {"category": "Panela de pressão elétrica", "reason": "praticidade no dia a dia", "keyword_search": "melhores panelas de pressão elétricas"}
    ]
  },
  "final_recommendation": {
    "title": "🎯 Qual vale mais a pena pra você?",
    "budget": {"product": "Nome", "reason": "1 frase"},
    "balanced": {"product": "Nome", "reason": "1 frase"},
    "premium": {"product": "Nome", "reason": "1 frase"}
  },
  "buying_guide": "HTML (h3, p, ul) com dicas — SEM h1/h2",
  "faq": [{"q": "pergunta", "a": "resposta"}],
  "hero_alt": "alt",
  "excerpt": "resumo"
}
JSON;

        $produtosTxt = '';
        if (!empty($produtosJson)) {
            $produtosTxt = "PRODUTOS (dados do usuário — use preços, links e specs exatamente como fornecidos):\n" . json_encode($produtosJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        }

        $fontesTxt = '';
        if (!empty($fontes)) {
            $fontesTxt = "FONTES SCRAPEADAS (analise e crie algo melhor):\n";
            foreach ($fontes as $i => $f) {
                $n = $i + 1;
                $m = $f['meta'];
                $c = $f['content'];
                $fontesTxt .= "=== FONTE $n: {$m['site_name']} ===\nURL: {$m['url']}\nTítulo: {$m['title']}\n";
                if ($m['description']) $fontesTxt .= "Desc: {$m['description']}\n";
                if (!empty($m['og_image'])) $fontesTxt .= "og:image: {$m['og_image']}\n";
                if (!empty($c['headings'])) {
                    foreach (array_slice($c['headings'], 0, 15) as $h) {
                        $fontesTxt .= "  [{$h['tag']}] {$h['text']}\n";
                    }
                }
                if (!empty($c['paragraphs'])) {
                    foreach (array_slice($c['paragraphs'], 0, 25) as $p) {
                        $fontesTxt .= "• " . mb_substr($p, 0, 400) . "\n";
                    }
                }
                if (!empty($c['images'])) {
                    $fontesTxt .= "Imagens:\n";
                    foreach (array_slice($c['images'], 0, 15) as $img) {
                        $src = $img['src'] ?? '';
                        $alt = $img['alt'] ?? '';
                        if ($src) $fontesTxt .= "  IMG: {$src}" . ($alt ? " (alt: {$alt})" : '') . "\n";
                    }
                }
                $fontesTxt .= "\n";
            }
        }

        $keyTxt = $keyword !== '' ? "KEYWORD ALVO: {$keyword}" : "KEYWORD: Infira a keyword principal a partir do conteúdo das fontes.";

        return <<<TXT
{$keyTxt}

{$produtosTxt}{$fontesTxt}

TAREFA:
Retorne DADOS ESTRUTURADOS (não HTML). O PHP monta o HTML e schemas.
- intro_paragraphs: 3 parágrafos curtos (hook → EEAT → promessa)
- products: array com TODOS os produtos encontrados/fornecidos, dados completos
- buying_guide: HTML semântico com dicas (h3, p, ul — sem h1/h2)
- faq: 4-6 perguntas reais

Se os dados do usuário tiverem links/preços, use exatamente. Se só houver fontes scrapeadas, extraia os produtos delas.

REGRAS DE FORMATAÇÃO:
- Responda APENAS com JSON válido (sem markdown, sem ```)
- Strings com quebra de linha: escape como \\n
- buying_guide em UMA LINHA (escape \\n e \\")

Schema de resposta:
{$schema}
TXT;
    }

    /** Wrapper público pra reuso do parser robusto por outras classes (DiscoverReviewer etc). */
    public static function parseJsonResponse(string $texto): ?array
    {
        $c = new self('dummy-key'); // instância temporária só pra usar os métodos de fix
        return $c->extractJson($texto);
    }

    private function extractJson(string $texto): ?array
    {
        $texto = trim($texto);

        // Remove bloco ```json ... ``` (multiline)
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/', $texto, $m)) {
            $candidate = $m[1];
        } else {
            // Remove cercas soltas
            $candidate = preg_replace('/^```(?:json)?\s*/m', '', $texto);
            $candidate = preg_replace('/\s*```\s*$/m', '', $candidate);
            $candidate = trim($candidate);
        }

        // Extrai do primeiro { ao último } se houver lixo ao redor
        $start = strpos($candidate, '{');
        $end   = strrpos($candidate, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($candidate, $start, $end - $start + 1);
        }

        // Tentativa 1: direto (se Claude já escapou tudo certo)
        $j = json_decode($candidate, true);
        if ($j) return $j;

        // Tentativa 2: fix com regex (escapa \n\r\t dentro de strings)
        $fixed = $this->fixJsonString($candidate);
        $j = json_decode($fixed, true);
        if ($j) return $j;

        // Tentativa 3: fallback bruto — substitui TODOS \n/\r/\t por escapes
        $bruto = str_replace(["\r\n", "\r", "\n", "\t"], ["\\n", "\\n", "\\n", "\\t"], $candidate);
        $j = json_decode($bruto, true);
        if ($j) return $j;

        // Tentativa 4: escape cirúrgico do valor de content_html
        // Delimita o valor pela próxima chave JSON conhecida e escapa tudo dentro.
        $fixHtml = $this->escaparContentHtml($candidate);
        if ($fixHtml !== $candidate) {
            $j = json_decode($fixHtml, true);
            if ($j) return $j;
        }

        // Tentativa 5: reparo de truncagem — resposta foi cortada no meio.
        // Fecha strings abertas e balanceia chaves/colchetes.
        $repaired = $this->repararTruncado($candidate);
        if ($repaired !== $candidate) {
            $j = json_decode($repaired, true);
            if ($j) return $j;
            // Tenta também combinar reparo + fix string
            $j = json_decode($this->fixJsonString($repaired), true);
            if ($j) return $j;
        }

        return null;
    }

    /**
     * Conserta JSON truncado: fecha strings, arrays e objetos abertos.
     * Estratégia: percorre o texto contando delimitadores, se terminar com estrutura
     * aberta adiciona fechamentos; se terminar dentro de uma string não-terminada, corta
     * até o último campo completo e fecha.
     */
    private function repararTruncado(string $json): string
    {
        $len = strlen($json);
        if ($len === 0) return $json;

        // Primeiro: detecta se estamos dentro de string (escaneamento char-a-char)
        $emString = false;
        $escape = false;
        $openBraces = 0;
        $openBrackets = 0;
        $ultimoFechamentoSeguro = -1; // posição após a última vírgula/fechamento válido fora de string

        for ($i = 0; $i < $len; $i++) {
            $c = $json[$i];
            if ($escape) { $escape = false; continue; }
            if ($c === '\\') { $escape = true; continue; }
            if ($c === '"') {
                $emString = !$emString;
                if (!$emString) $ultimoFechamentoSeguro = $i;
                continue;
            }
            if ($emString) continue;
            if ($c === '{') $openBraces++;
            elseif ($c === '}') { $openBraces--; $ultimoFechamentoSeguro = $i; }
            elseif ($c === '[') $openBrackets++;
            elseif ($c === ']') { $openBrackets--; $ultimoFechamentoSeguro = $i; }
            elseif ($c === ',') $ultimoFechamentoSeguro = $i;
        }

        // Se terminou dentro de string: corta até o último ponto seguro e remove trailing comma
        if ($emString && $ultimoFechamentoSeguro > 0) {
            $json = substr($json, 0, $ultimoFechamentoSeguro + 1);
            // Re-conta após o corte
            $emString = false;
            $openBraces = 0;
            $openBrackets = 0;
            $escape = false;
            $lenAfter = strlen($json);
            for ($i = 0; $i < $lenAfter; $i++) {
                $c = $json[$i];
                if ($escape) { $escape = false; continue; }
                if ($c === '\\') { $escape = true; continue; }
                if ($c === '"') { $emString = !$emString; continue; }
                if ($emString) continue;
                if ($c === '{') $openBraces++;
                elseif ($c === '}') $openBraces--;
                elseif ($c === '[') $openBrackets++;
                elseif ($c === ']') $openBrackets--;
            }
        }

        // Remove trailing comma se houver
        $json = rtrim($json);
        if (substr($json, -1) === ',') $json = substr($json, 0, -1);

        // Fecha colchetes e chaves pendentes
        for ($i = 0; $i < $openBrackets; $i++) $json .= ']';
        for ($i = 0; $i < $openBraces;   $i++) $json .= '}';

        return $json;
    }

    /**
     * Localiza o valor de "content_html" no JSON bruto (mesmo que tenha aspas
     * não escapadas dentro) e re-escapa tudo corretamente. Usa as chaves
     * conhecidas do schema como delimitadores de fim.
     */
    private function escaparContentHtml(string $json): string
    {
        // Procura por múltiplos markers comuns ("content_html", "html", "body")
        // — o primeiro encontrado vira o foco. Fix gerar_noticia.php que usa "html".
        $markers = ['"content_html":', '"html":', '"body":', '"content":'];
        $marker = null;
        $start = false;
        foreach ($markers as $m) {
            $p = strpos($json, $m);
            if ($p !== false) { $marker = $m; $start = $p; break; }
        }
        if ($start === false) return $json;

        // Posição da aspa de abertura do valor
        $valStart = strpos($json, '"', $start + strlen($marker));
        if ($valStart === false) return $json;
        $valStart++; // avança pra dentro da string

        // Chaves conhecidas dos schemas (gerarArtigo, Reviewer, Updater, etc).
        // Usadas como delimitadores prioritários.
        $chavesPosteriores = [
            'products','faq','tags','categories','buying_guide','decision_block',
            'vs_comparisons','common_mistake','final_recommendation','hero_alt',
            'schema_type','is_howto','howto_steps','cross_sell','secondary_keywords',
            'meta_title','meta_description','focus_keyword','excerpt','slug','title',
            'intro_paragraphs','titulo_final','titulos_alternativos',
            'aberturas_alternativas','frases_impacto','meta','alternativas',
        ];

        // Procura o fim do valor usando regex TOLERANTE a whitespace entre campos
        // Match: "  ↓ quebra linha + indent + próxima chave   → aceita `",\n  "key":` também
        $endPos = -1;
        foreach ($chavesPosteriores as $k) {
            $pattern = '/"\s*,\s*"' . preg_quote($k, '/') . '"\s*:/';
            if (preg_match($pattern, $json, $m, PREG_OFFSET_CAPTURE, $valStart)) {
                $pos = $m[0][1];
                if ($endPos === -1 || $pos < $endPos) $endPos = $pos;
            }
        }

        // Fallback genérico: qualquer `"  ,  "<chave_snake>":` — pega novos schemas sem hardcoding
        if ($endPos === -1) {
            if (preg_match('/"\s*,\s*"[a-z_][a-z0-9_]{1,40}"\s*:/i', $json, $m, PREG_OFFSET_CAPTURE, $valStart)) {
                $endPos = $m[0][1];
            }
        }

        // Último recurso: fechamento final do objeto — tolera whitespace "}"
        if ($endPos === -1) {
            if (preg_match('/"\s*}\s*$/', $json, $m, PREG_OFFSET_CAPTURE, $valStart)) {
                $endPos = $m[0][1];
            }
        }
        if ($endPos === -1) return $json;

        $value = substr($json, $valStart, $endPos - $valStart);

        // Escape manual: backslash primeiro, depois aspas duplas, depois controle
        $escaped = strtr($value, [
            '\\' => '\\\\',
            '"'  => '\\"',
            "\n" => '\\n',
            "\r" => '\\n',
            "\t" => '\\t',
        ]);

        return substr($json, 0, $valStart) . $escaped . substr($json, $endPos);
    }

    /**
     * Corrige JSON com caracteres de controle (newlines literais dentro de strings).
     *
     * Abordagem em 3 camadas:
     *  1. Remove chars de controle ilegais (0x00-0x08, 0x0B, 0x0C, 0x0E-0x1F)
     *  2. Regex: encontra strings JSON e escapa \n \r \t dentro delas
     *  3. Fallback bruto se regex falhar: substitui TODOS \n/\r/\t por escapes
     */
    private function fixJsonString(string $s): string
    {
        // Camada 1: remove chars que nunca são válidos
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);

        // Camada 2: regex — encontra cada string JSON e escapa controle chars dentro
        $fixed = preg_replace_callback(
            '/"((?:[^"\\\\]|\\\\.)*)"/s',
            function ($m) {
                $inner = $m[1];
                $inner = str_replace("\r\n", "\\n", $inner);
                $inner = str_replace("\r", "\\n", $inner);
                $inner = str_replace("\n", "\\n", $inner);
                $inner = str_replace("\t", "\\t", $inner);
                return '"' . $inner . '"';
            },
            $s
        );

        if ($fixed !== null) {
            $test = json_decode($fixed, true);
            if ($test !== null) return $fixed;
        }

        // Camada 3: fallback bruto — tenta substituir tudo
        $bruto = str_replace(["\r\n", "\r", "\n", "\t"], ["\\n", "\\n", "\\n", "\\t"], $s);
        return $bruto;
    }

    /** Retorna mês atual em português. */
    private function mesAtual(): string
    {
        $meses = [
            1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
            5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
            9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
        ];
        return $meses[(int)date('n')];
    }
}
