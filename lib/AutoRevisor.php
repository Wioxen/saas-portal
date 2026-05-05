<?php
declare(strict_types=1);

require_once __DIR__ . '/Claude.php';
require_once __DIR__ . '/AntiAIValidator.php';

/**
 * AutoRevisor — segunda passada com Haiku 4.5 pra remover padrões IA detectados.
 *
 * Pipeline:
 *   1. AntiAIValidator roda no HTML gerado por Sonnet (1ª passada)
 *   2. Se severity != 'ok', pega TODAS as violations + structural issues
 *   3. Manda HTML + lista de problemas pra Haiku 4.5 reescrever PARÁGRAFO A
 *      PARÁGRAFO os trechos com problema, mantendo:
 *       - Persona do site (voz Maria Gusmão / Equipe Leão da Barra etc.)
 *       - Fatos literais das fontes (sem inventar)
 *       - Estrutura HTML (h2/h3/p/ul/li/strong)
 *   4. Re-roda AntiAIValidator no resultado
 *   5. Se ainda fail, retorna versão revisada com warning
 *
 * Custo: Haiku 4.5 = ~10x mais barato que Sonnet → 2ª passada custa ~$0.02
 * Tempo: ~5-8s extra na geração (vale a qualidade)
 *
 * Filosofia: "Cada parágrafo deve passar nos 2 testes —
 *   1. Tem padrão de IA? Se sim, refazer.
 *   2. Tem autoridade pro Google? Se não, refazer."
 */
class AutoRevisor
{
    private Claude $haiku;
    private AntiAIValidator $validator;

    public function __construct(string $apiKey)
    {
        $this->haiku = new Claude($apiKey, 'claude-haiku-4-5');
        $this->validator = new AntiAIValidator();
    }

    /**
     * @param string $html        HTML gerado pelo Sonnet
     * @param array  $contexto    {site_name, persona_autor, persona_voz, persona_tom, subtipo_nicho, sources_text}
     * @return array {html, ok, severity, antes, depois, custo_estimado_usd}
     */
    public function revisar(string $html, array $contexto): array
    {
        $antes = $this->validator->validate($html);

        if ($antes['severity'] === 'ok') {
            return [
                'html'     => $html,
                'ok'       => true,
                'severity' => 'ok',
                'antes'    => $antes,
                'depois'   => $antes,
                'reescreveu' => false,
                'custo_estimado_usd' => 0,
            ];
        }

        // Compila lista de problemas pra mostrar pro Haiku
        $problemasFormatados = $this->formatarProblemas($antes);

        // Persona + nicho pra Haiku manter voz
        $siteName = $contexto['site_name'] ?? '?';
        $persona = $contexto['persona_autor'] ?? "Equipe {$siteName}";
        $voz = $contexto['persona_voz'] ?? 'jornalística direta';
        $tom = $contexto['persona_tom'] ?? 'direto e factual';
        $nicho = $contexto['subtipo_nicho'] ?? '';
        $fontesPlain = $contexto['sources_text'] ?? '';

        $system = <<<SYS
Você é editor de revisão. Recebe um HTML de artigo + lista de PADRÕES DE IA
detectados nele. Sua tarefa: reescrever os trechos com problema preservando:

1. **Voz e persona**: artigo é de {$persona} ({$voz}, tom {$tom}, nicho: {$nicho})
2. **Fatos das fontes**: NUNCA inventar dados — só usar o que já está na prosa
   (você ESTÁ revisando, não pesquisando)
3. **Estrutura HTML**: manter h2/h3/p/ul/li/strong/table como vieram

═══ LISTA NEGRA LITERAL — REMOVER QUALQUER OCORRÊNCIA ═══
Faça find-and-replace mental destas frases. Cada ocorrência reprova o artigo.

CONECTORES ROBÔ: "em suma", "em síntese", "em conclusão", "em resumo",
"em última análise", "em última instância", "em contrapartida", "diante disso",
"diante desse cenário", "diante do exposto", "vale destacar", "vale ressaltar",
"vale lembrar", "vale mencionar", "cabe destacar", "cabe ressaltar",
"é importante destacar", "é importante ressaltar", "é fundamental destacar",
"nesse contexto", "neste contexto", "nesse sentido", "nesse cenário",
"sob esse prisma", "sob essa ótica", "dessa forma", "dessa maneira", "desse modo",
"portanto", "por conseguinte", "ademais", "outrossim", "dito isso", "isto posto"

CLICHÊS ABERTURA: "a verdade é que", "o que ninguém te conta",
"o que quase ninguém percebe", "vale a pena agora", "só que isso muda tudo",
"mas tem um detalhe que quase ninguém", "e é aqui que muita gente erra",
"a maioria descobre tarde demais", "a vaga não espera", "fica a dica",
"simples assim", "parece simples, não é"

TEMPLATE NARRATIVO: "tem gente que", "tem gente em", "quem tenta", "quem busca",
"quem espera", "quem precisa", "ficou de fora", "fica de fora", "fiquem de fora",
"animada com a vaga", "esperançoso com"

FILLERS NARRATIVOS: "rapidamente", "mesmo assim", "na prática", "na real",
"no fim das contas", "logo de cara", "já de cara", "acaba descobrindo"

PROMESSA VAGA: "o filtro que", "esse filtro", "o erro que", "esse erro",
"o detalhe que", "esse detalhe", "o problema que", "esse problema", "o ponto que",
"esse ponto", "o critério que", "esse critério", "o que muita gente desconhece"

ADJETIVOS VAZIOS: "imperdível", "incrível", "revolucionário", "surpreendente",
"transformador", "magnífico", "extraordinário", "memorável" (isolados, sem
qualificação concreta)

SELF-REFERENCE: "veja a seguir", "confira a seguir", "leia abaixo",
"descubra a seguir", "clique aqui", "continue lendo abaixo"

GERUNDISMO: "estar fazendo", "estarão recebendo", "estará buscando",
"vai estar acompanhando"

POMPOSO: "outrora", "doravante", "destarte", "far-se-á", "tem-se que"

═══ ESTRUTURAIS QUE VOCÊ DEVE CORRIGIR ═══
- Frase com 30+ palavras: QUEBRAR em 2 frases curtas
- Travessões "—" no corpo: SUBSTITUIR por vírgula, parênteses ou ponto
- Reticências "..." mais de 1x: REMOVER as extras
- Listas com EXATAMENTE 3 itens: expandir pra 4-5 OU embutir no parágrafo
- Mesmo conector >1x ("Além disso" 2x): trocar a 2ª por outro
- 4+ parágrafos antes do 1º H2: COMPRIMIR pra exatos 3 (P1+P2+P3)
- Tom-edital ("Segundo o Edital nº X"): trocar por tom guia ("pela divulgação oficial",
  "vale juntar", "costuma travar")

═══ PRINCÍPIOS DE REESCRITA ═══
1. Em vez de teaser, FATO CONCRETO: "Mas tem um detalhe" → "A regra tem uma
   exceção: famílias com renda acima de R\$ 1.518"
2. Em vez de H2 vago, H2 com dado único: "O que ninguém te conta" → "Renda até
   R\$ 1.518 garante isenção automática"
3. Cada parágrafo termina com FATO ou pausa natural, nunca teaser-clichê
4. Quando remover frase batida, INSERIR no lugar uma frase factual da prosa
   original (não deletar simplesmente — manter densidade informacional)

═══ TESTE INTERNO ANTES DE CADA PARÁGRAFO ═══

🎓 **TESTE PhD-USP (NOVO, prioridade máxima):**
"Esta frase passaria na revisão da Folha de SP / Nexo Jornal?"
Se a resposta é NÃO (parece marketing afiliado) → REESCREVA.

1. "Esse parágrafo tem padrão de IA?" Se sim → refazer.
2. "Esse parágrafo tem autoridade pro Google?" Se não → refazer.
3. "Parece que {$persona} (jornalista acadêmica PhD-USP) escreveu, ou parece marketing afiliado?" Se marketing → refazer.

═══ VOCABULÁRIO BANIDO — VERBOS DE ELIMINAÇÃO GENÉRICOS ═══

Construções tipo "X que [verbo eliminação]" SEM qualificador concreto:
❌ "filtro que barra candidatos"
❌ "critério que pode barrar"
❌ "regra que impede"
❌ "erro que elimina"
❌ "detalhe que derruba"
❌ "ponto que pode tirar a vaga"

REESCREVER com fato:
- "filtro que barra candidatos" → "Edital nº 4 exige idade ≤ 65 anos"
- "regra que impede" → "art. 12 da Lei 14.144 exige CadÚnico atualizado"
- "erro que elimina" → "23% dos candidatos esquecem a declaração assinada"

A diferença é entre **suspense barato** (marketing) e **fato analítico** (jornalismo acadêmico).

═══ ⚠️ PhD-USP NÃO É ACADEMIQUÊS — É SIMPLES + PRECISO ═══

A autora é doutora USP MAS escreve pra leitor mobile. Jornalismo PhD bom = vocabulário simples + precisão factual. Você costuma cair em academiquês quando reescreve "elevando o tom".

❌ ACADEMIQUÊS (cortar):
- "extensão ao ingressante cria assimetria de sistema"
- "implica necessidade de comunicar dois fluxos"
- "configura inflexão na política pública"
- "constitui avanço estruturante"
- "regime jurídico de exceção temporária"
- "no âmbito de", "à luz de", "em conformidade com"
- "tem-se que", "faz-se necessário", "convém ressaltar"

✅ JORNALISMO CLARO (usar):
- "muda como o ingressante entra no sistema"
- "obriga as instituições a orientar 2 processos"
- "é a maior mudança desde 2018"
- "vale só por enquanto, pode mudar"

TESTE: "Minha mãe (não-acadêmica) entenderia ESSA frase?" Se precisar reler 2x → SIMPLIFIQUE com palavra comum mantendo precisão factual.

SAÍDA OBRIGATÓRIA: JSON com campo 'html' contendo o artigo revisado completo.
NÃO incluir explicação fora do JSON. Apenas o JSON.
SYS;

        $user = <<<USR
═══ HTML A REVISAR ═══
{$html}

═══ PADRÕES DE IA DETECTADOS ═══
{$problemasFormatados}

═══ INSTRUÇÃO ═══
Reescreva os trechos com problema (mantendo TODA a estrutura, fatos e persona).
Retorne JSON: { "html": "<artigo completo revisado>" }
USR;

        try {
            $resp = $this->haiku->callPublic([['role' => 'user', 'content' => $user]], $system, 12000);
            $texto = $resp['content'][0]['text'] ?? '';
            $json = Claude::parseJsonResponse($texto);

            if (!$json || empty($json['html'])) {
                return [
                    'html' => $html,
                    'ok' => false,
                    'severity' => $antes['severity'],
                    'antes' => $antes,
                    'depois' => $antes,
                    'reescreveu' => false,
                    'erro' => 'Haiku não retornou JSON válido',
                    'custo_estimado_usd' => 0.02,
                ];
            }

            $htmlRevisado = (string)$json['html'];
            $depois = $this->validator->validate($htmlRevisado);

            return [
                'html'     => $htmlRevisado,
                'ok'       => $depois['severity'] === 'ok',
                'severity' => $depois['severity'],
                'antes'    => $antes,
                'depois'   => $depois,
                'reescreveu' => true,
                'custo_estimado_usd' => 0.02,
            ];
        } catch (Throwable $e) {
            return [
                'html' => $html,
                'ok' => false,
                'severity' => $antes['severity'],
                'antes' => $antes,
                'depois' => $antes,
                'reescreveu' => false,
                'erro' => 'AutoRevisor falhou: ' . $e->getMessage(),
                'custo_estimado_usd' => 0,
            ];
        }
    }

    /** Formata violations + structural issues numa lista textual pro prompt do Haiku.
     *  Instruções LITERAIS por tipo (travessão → vírgula; teaser → fato; etc). */
    private function formatarProblemas(array $report): string
    {
        $linhas = [];
        foreach (($report['violations'] ?? []) as $v) {
            $linhas[] = "- [{$v['category']}] '{$v['phrase']}' aparece {$v['count']}x — REMOVER ou substituir por fato concreto da fonte";
        }
        foreach (($report['structural'] ?? []) as $s) {
            $instrucao = $this->instrucaoPorTipo($s);
            $linhas[] = "- [estrutural] {$s}\n    → AÇÃO: {$instrucao}";
        }
        return implode("\n", $linhas) ?: '(nenhum problema específico — apenas validar consistência)';
    }

    /** Mapa de instruções literais por tipo de problema estrutural. */
    private function instrucaoPorTipo(string $issue): string
    {
        $issueLower = mb_strtolower($issue);
        if (str_contains($issueLower, 'travessões')) {
            return "TROCAR TODOS os travessões '—' e en-dashes '–' por vírgula ', ' (ou ponto-e-vírgula '; ' ou dois-pontos ': ' conforme contexto). NENHUM travessão pode permanecer no corpo do artigo.";
        }
        if (str_contains($issueLower, 'reticências')) {
            return "TROCAR todas as reticências '...' e '…' por ponto final '.' ou continuar a frase. Máximo 1 ocorrência permitida.";
        }
        if (str_contains($issueLower, 'teaser-paragrafo-isolado')) {
            return "REMOVER o parágrafo isolado de suspense (ex: 'Mas tem um detalhe.'). Embuti-lo dentro do parágrafo seguinte usando um FATO CONCRETO da fonte (ex: 'A regra tem uma exceção: famílias com renda acima de R\$ 1.518').";
        }
        if (str_contains($issueLower, 'listas-trio-perfeito')) {
            return "QUEBRAR a lista de exatos 3 itens — expandir pra 4-5 itens com dados adicionais da fonte OU fundir 2 itens em uma frase corrida no parágrafo.";
        }
        if (str_contains($issueLower, 'densidade-conector')) {
            return "VARIAR o conector repetido — usar conectores naturais BR ('Aí', 'E', 'Só que', 'Acontece que', 'Na prática', 'Por enquanto') OU pausa em ponto final.";
        }
        if (str_contains($issueLower, 'parágrafos uniformes')) {
            return "VARIAR comprimento dos parágrafos — alguns curtos (1-2 linhas com fato direto), alguns médios (3-4 linhas com contexto). Quebra ritmo robótico.";
        }
        if (str_contains($issueLower, "'além disso'") || str_contains($issueLower, "'no entanto'")) {
            return "TROCAR todas as ocorrências extras pelo equivalente natural ('E', 'Plus', 'Outro ponto:', 'Acontece que'). Máximo 1x cada.";
        }
        if (str_contains($issueLower, 'h2s repetem palavra')) {
            return "REESCREVER os H2s pra começarem com palavras diferentes. Cada H2 deve refletir um aspecto único da fonte.";
        }
        if (str_contains($issueLower, 'intro-inflada')) {
            return "REDUZIR pra EXATOS 3 parágrafos `<p>` SEM atributo class antes do 1º `<h2>` (ORDEM FIXA: P1+P2+P3). NÃO excluir info — mover os parágrafos extras pra DEPOIS do 1º H2 (eles viram primeiros parágrafos do desenvolvimento). Preservar `<p class='resposta-direta'>` e `<ul class='snippet-resumo'>` que já estiverem no lugar (eles NÃO contam como intro). Se houver 2 `<p class='resposta-direta'>`, consolidar num só.";
        }
        if (str_contains($issueLower, 'intro-redundancia')) {
            return "ELIMINAR paráfrase entre parágrafos da intro. Cada parágrafo (P1, P2, P3) deve trazer um ÂNGULO ÚNICO: P1=lead/barreira, P2=autoridade+atribuição, P3=salto factual NOVO (consequência, contraste histórico, detalhe restritivo, ou contexto local). Se 2 parágrafos repetem entidade+prazo+canal, FUNDIR num só e usar o espaço pra trazer dado novo.";
        }
        if (str_contains($issueLower, 'redundancia-p1-p3')) {
            return "REESCREVER o P3 (3º parágrafo antes do 1º H2). PROIBIDO repetir entidade, prazo ou canal de acesso que já saíram em P1. Trazer UMA das 4 opções: (a) consequência prática, (b) contraste histórico/comparativo, (c) detalhe restritivo, (d) contexto local específico — sempre com dado concreto da fonte.";
        }
        if (str_contains($issueLower, 'prompt-leak-erro-fatal')) {
            return "REESCREVER o H2 nomeando o CRITÉRIO REAL no próprio título (não usar fórmula vaga 'O erro que elimina/derruba/barra'). Ex: 'Comprovação de renda no CadÚnico reprova X% das inscrições do Fies'. Se o tema NÃO tem critério eliminatório real (curso por ordem de chegada, oferta livre), REMOVER o H2 inteiro e fundir conteúdo num H2 factual.";
        }
        if (str_contains($issueLower, 'prompt-leak-alerta-critico')) {
            return "REESCREVER o `<p class='alerta-critico__titulo'>` nomeando o critério eliminatório específico do edital/fonte. PROIBIDO copiar 'Erro que derruba a inscrição' (é exemplo do prompt). Se não há critério eliminatório real → REMOVER o `<div class='alerta-critico'>` inteiro.";
        }
        if (str_contains($issueLower, 'frase-composta-pesada')) {
            return "QUEBRAR a frase composta em 2 frases curtas. Sinal: frase ≥22 palavras com conectores ' e como ', ' e quando ', ' e o que ', ' mas ', ' porém ' que ligam 2 clausulas com verbo próprio. Regra: '2 ideias fortes = 2 partes'. Cada frase carrega UMA ideia, máx 18 palavras. Exemplo: 'vale entender qual erro derruba e como o cálculo muda' → 'vale entender qual erro derruba mais. O cálculo de aproveitamento também muda depois do recurso.'";
        }
        if (str_contains($issueLower, 'paragrafo-paredao')) {
            return "QUEBRAR a frase longa (≥30 palavras) em 2 frases curtas, máx 20 palavras cada. Cada frase termina em ponto. Distribuir ideias: a 1ª frase carrega o FATO/dado, a 2ª frase abre LOOP ou contextualiza. Mobile lê em 2 linhas; paredão = scroll fora.";
        }
        if (str_contains($issueLower, 'tom-edital')) {
            return "SUBSTITUIR tom institucional/edital por tom de guia amigo. Trocas obrigatórias: 'Segundo o edital divulgado' → 'Pela divulgação oficial'; 'conforme o anexo' → 'segundo o documento da seleção'; 'interessados deverão' → 'quem quiser concorrer precisa'; 'candidatos preparem documentação' → 'vale juntar documento de identidade, comprovante de residência e histórico escolar antes da etapa'; 'recomenda-se que' → 'a recomendação é'; 'será divulgado posteriormente' → 'a próxima atualização sai'. Tom: jornalista contando pra leigo, não órgão anunciando.";
        }
        if (str_contains($issueLower, 'gatilho-batido-discover')) {
            return "REESCREVER o P1 inteiro removendo clichê de urgência ('perde quem deixa pra última hora', 'vagas voam', 'última chamada'). SUBSTITUIR por ângulo ESPECÍFICO extraído da fonte: (a) ocupação/critério/cidade RARA citada no texto; (b) mecânica única do programa (jornada dupla, restrição geográfica, etapa atípica); (c) contraste numérico forte (X% de eliminação, Y vs Z disponíveis); (d) data/restrição geográfica que só leitor local entende. Se a fonte não dá nenhum diferencial: melhor abrir com FATO seco e específico do que com clichê.";
        }
        if (str_contains($issueLower, 'vague') || str_contains($issueLower, 'vago:')) {
            return "REESCREVER o heading nomeando ESPECIFICAMENTE de que filtro/erro/detalhe/critério se trata, com dado da fonte. Ex: 'O filtro que barra' → 'O filtro de cargo da Polícia-RS que barra candidatos sem CNH-D'.";
        }
        return "Reformular o trecho/parágrafo/lista afetado conforme orientação do manifesto editorial.";
    }
}
