<?php
/**
 * Auto-link de órgãos de autoridade (gov.br, instituições oficiais).
 *
 * Detecta menções a entidades oficiais no HTML e cria <a href="url-oficial"> na
 * PRIMEIRA ocorrência de cada uma. Objetivo: E-E-A-T + sinal de confiança pro
 * Discover (cita fonte, linka fonte).
 *
 * Regras:
 *  - Só linka 1x por órgão (primeira ocorrência)
 *  - Não linka dentro de <a>, <script>, <style>, <h2>, <h3>, <h4>, blocos reservados
 *  - Prefere sigla OU nome completo (ambos casam o mesmo link)
 *  - Usa rel="noopener nofollow" target="_blank"
 */
class DiscoverAuthorityLinks
{
    /**
     * Mapa: nome/alias → URL oficial.
     * Ordem IMPORTA: padrões mais específicos (com acentos) vêm antes dos genéricos.
     * Cada entrada: ['aliases' => [...], 'url' => '...', 'titulo' => '...'].
     */
    private static array $orgaos = [
        // Federais — finanças/tributos
        ['aliases' => ['Receita Federal', 'Secretaria da Receita Federal'], 'url' => 'https://www.gov.br/receitafederal/', 'titulo' => 'Site oficial da Receita Federal'],
        ['aliases' => ['Ministério da Fazenda'], 'url' => 'https://www.gov.br/fazenda/', 'titulo' => 'Ministério da Fazenda'],
        ['aliases' => ['Ministério do Planejamento'], 'url' => 'https://www.gov.br/planejamento/', 'titulo' => 'Ministério do Planejamento'],
        ['aliases' => ['Banco Central do Brasil', 'Banco Central'], 'url' => 'https://www.bcb.gov.br/', 'titulo' => 'Banco Central do Brasil'],
        ['aliases' => ['BNDES'], 'url' => 'https://www.bndes.gov.br/', 'titulo' => 'BNDES'],
        ['aliases' => ['CVM', 'Comissão de Valores Mobiliários'], 'url' => 'https://www.gov.br/cvm/', 'titulo' => 'CVM'],

        // Previdência/trabalho
        ['aliases' => ['INSS', 'Instituto Nacional do Seguro Social'], 'url' => 'https://www.gov.br/inss/', 'titulo' => 'INSS'],
        ['aliases' => ['Ministério do Trabalho e Emprego', 'Ministério do Trabalho'], 'url' => 'https://www.gov.br/trabalho-e-emprego/', 'titulo' => 'Ministério do Trabalho e Emprego'],
        ['aliases' => ['MTE'], 'url' => 'https://www.gov.br/trabalho-e-emprego/', 'titulo' => 'MTE'],

        // Desenvolvimento social / benefícios
        ['aliases' => ['Ministério do Desenvolvimento Social', 'MDS'], 'url' => 'https://www.gov.br/mds/', 'titulo' => 'Ministério do Desenvolvimento Social'],
        ['aliases' => ['Caixa Econômica Federal', 'Caixa'], 'url' => 'https://www.caixa.gov.br/', 'titulo' => 'Caixa Econômica Federal'],
        ['aliases' => ['Banco do Brasil'], 'url' => 'https://www.bb.com.br/', 'titulo' => 'Banco do Brasil'],

        // Saúde
        ['aliases' => ['Ministério da Saúde'], 'url' => 'https://www.gov.br/saude/', 'titulo' => 'Ministério da Saúde'],
        ['aliases' => ['ANVISA', 'Agência Nacional de Vigilância Sanitária'], 'url' => 'https://www.gov.br/anvisa/', 'titulo' => 'Anvisa'],
        ['aliases' => ['OMS', 'Organização Mundial da Saúde'], 'url' => 'https://www.who.int/', 'titulo' => 'Organização Mundial da Saúde'],
        ['aliases' => ['SUS'], 'url' => 'https://www.gov.br/saude/pt-br/assuntos/saude-de-a-a-z/s/sus', 'titulo' => 'SUS'],

        // Educação
        ['aliases' => ['Ministério da Educação', 'MEC'], 'url' => 'https://www.gov.br/mec/', 'titulo' => 'Ministério da Educação'],
        ['aliases' => ['Inep', 'INEP'], 'url' => 'https://www.gov.br/inep/', 'titulo' => 'Inep'],
        ['aliases' => ['CAPES'], 'url' => 'https://www.gov.br/capes/', 'titulo' => 'CAPES'],
        ['aliases' => ['CNPq'], 'url' => 'https://www.gov.br/cnpq/', 'titulo' => 'CNPq'],

        // Justiça/eleitoral
        ['aliases' => ['STF', 'Supremo Tribunal Federal'], 'url' => 'https://portal.stf.jus.br/', 'titulo' => 'Supremo Tribunal Federal'],
        ['aliases' => ['TSE', 'Tribunal Superior Eleitoral'], 'url' => 'https://www.tse.jus.br/', 'titulo' => 'Tribunal Superior Eleitoral'],
        ['aliases' => ['STJ', 'Superior Tribunal de Justiça'], 'url' => 'https://www.stj.jus.br/', 'titulo' => 'Superior Tribunal de Justiça'],
        ['aliases' => ['TST', 'Tribunal Superior do Trabalho'], 'url' => 'https://www.tst.jus.br/', 'titulo' => 'Tribunal Superior do Trabalho'],
        ['aliases' => ['Ministério Público Federal', 'MPF'], 'url' => 'https://www.gov.br/mpf/', 'titulo' => 'Ministério Público Federal'],

        // Meio ambiente / estatística
        ['aliases' => ['IBAMA'], 'url' => 'https://www.gov.br/ibama/', 'titulo' => 'Ibama'],
        ['aliases' => ['ICMBio'], 'url' => 'https://www.gov.br/icmbio/', 'titulo' => 'ICMBio'],
        ['aliases' => ['IBGE', 'Instituto Brasileiro de Geografia e Estatística'], 'url' => 'https://www.ibge.gov.br/', 'titulo' => 'IBGE'],

        // Trânsito / infra
        ['aliases' => ['DENATRAN'], 'url' => 'https://www.gov.br/pt-br/orgaos/departamento-nacional-de-transito', 'titulo' => 'Denatran'],
        ['aliases' => ['CONTRAN', 'Conselho Nacional de Trânsito'], 'url' => 'https://www.gov.br/pt-br/orgaos/conselho-nacional-de-transito', 'titulo' => 'Contran'],
        ['aliases' => ['ANTT'], 'url' => 'https://www.gov.br/antt/', 'titulo' => 'ANTT'],
        ['aliases' => ['ANAC'], 'url' => 'https://www.gov.br/anac/', 'titulo' => 'Anac'],

        // Turismo/exterior
        ['aliases' => ['Itamaraty', 'Ministério das Relações Exteriores'], 'url' => 'https://www.gov.br/mre/', 'titulo' => 'Itamaraty'],
        ['aliases' => ['Embratur'], 'url' => 'https://www.embratur.com.br/', 'titulo' => 'Embratur'],

        // Telecom/mídia
        ['aliases' => ['ANATEL'], 'url' => 'https://www.gov.br/anatel/', 'titulo' => 'Anatel'],
        ['aliases' => ['Conselho Nacional de Justiça', 'CNJ'], 'url' => 'https://www.cnj.jus.br/', 'titulo' => 'CNJ'],

        // Defesa civil / clima
        ['aliases' => ['INMET', 'Instituto Nacional de Meteorologia'], 'url' => 'https://portal.inmet.gov.br/', 'titulo' => 'Inmet'],
        ['aliases' => ['Defesa Civil'], 'url' => 'https://www.gov.br/mdr/pt-br/assuntos/protecao-e-defesa-civil', 'titulo' => 'Defesa Civil'],

        // Defesa do consumidor
        ['aliases' => ['Procon'], 'url' => 'https://www.gov.br/mj/pt-br/assuntos/seus-direitos/consumidor', 'titulo' => 'Procon / Senacon'],

        // Portal principal gov.br (login único, busca geral de serviços federais)
        ['aliases' => ['portal gov.br', 'Portal Gov.br', 'Gov.br', 'gov.br'], 'url' => 'https://www.gov.br/', 'titulo' => 'Portal gov.br — serviços do governo federal'],
        ['aliases' => ['conta Gov.br', 'Conta gov.br', 'login Gov.br', 'login gov.br'], 'url' => 'https://www.gov.br/governodigital/pt-br/conta-gov-br', 'titulo' => 'Conta gov.br'],

        // ═══ PROGRAMAS SOCIAIS E BENEFÍCIOS ═══
        ['aliases' => ['Bolsa Família', 'Bolsa-Família'], 'url' => 'https://www.gov.br/mds/pt-br/acoes-e-programas/bolsa-familia', 'titulo' => 'Bolsa Família'],
        ['aliases' => ['Auxílio Brasil'], 'url' => 'https://www.gov.br/mds/pt-br', 'titulo' => 'Auxílio Brasil'],
        ['aliases' => ['Pé-de-Meia', 'Pé de Meia'], 'url' => 'https://www.gov.br/mec/pt-br/pe-de-meia', 'titulo' => 'Pé-de-Meia'],
        ['aliases' => ['BPC', 'Benefício de Prestação Continuada'], 'url' => 'https://www.gov.br/inss/pt-br/saiba-mais/auxilios/bpc', 'titulo' => 'BPC'],
        ['aliases' => ['LOAS'], 'url' => 'https://www.gov.br/inss/pt-br/saiba-mais/auxilios/bpc', 'titulo' => 'LOAS'],
        ['aliases' => ['Minha Casa Minha Vida'], 'url' => 'https://www.gov.br/mcidades/pt-br/acesso-a-informacao/institucional/snh/secretaria-nacional-da-habitacao/pmcmv', 'titulo' => 'Minha Casa Minha Vida'],
        ['aliases' => ['CadÚnico', 'CadUnico', 'Cadastro Único'], 'url' => 'https://cadastrounico.dataprev.gov.br/', 'titulo' => 'CadÚnico'],
        ['aliases' => ['FGTS', 'Fundo de Garantia'], 'url' => 'https://www.caixa.gov.br/beneficios-trabalhador/fgts/', 'titulo' => 'FGTS'],
        ['aliases' => ['PIS/Pasep', 'PIS-Pasep', 'Abono Salarial'], 'url' => 'https://www.gov.br/trabalho-e-emprego/pt-br/servicos/trabalhador/abono-salarial', 'titulo' => 'PIS/Pasep'],
        ['aliases' => ['Seguro-Desemprego', 'Seguro Desemprego'], 'url' => 'https://www.gov.br/trabalho-e-emprego/pt-br/servicos/trabalhador/seguro-desemprego', 'titulo' => 'Seguro-Desemprego'],
        ['aliases' => ['Auxílio-Gás', 'Auxílio Gás'], 'url' => 'https://www.gov.br/mds/pt-br/acoes-e-programas/auxilio-gas', 'titulo' => 'Auxílio-Gás'],
        ['aliases' => ['Salário-Família', 'Salário Família'], 'url' => 'https://www.gov.br/inss/pt-br/saiba-mais/auxilios/salario-familia', 'titulo' => 'Salário-Família'],
        ['aliases' => ['13º salário', '13° salário', 'décimo terceiro'], 'url' => 'https://www.gov.br/trabalho-e-emprego/pt-br/assuntos/trabalhador/13o-salario', 'titulo' => '13º salário'],

        // ═══ EXAMES E PROGRAMAS EDUCACIONAIS ═══
        ['aliases' => ['Enem', 'ENEM', 'Exame Nacional do Ensino Médio'], 'url' => 'https://www.gov.br/inep/pt-br/areas-de-atuacao/avaliacao-e-exames-educacionais/enem', 'titulo' => 'Enem'],
        ['aliases' => ['Sisu'], 'url' => 'https://acessounico.mec.gov.br/sisu', 'titulo' => 'Sisu'],
        ['aliases' => ['ProUni'], 'url' => 'https://acessounico.mec.gov.br/prouni', 'titulo' => 'ProUni'],
        ['aliases' => ['Fies', 'FIES'], 'url' => 'https://acessounico.mec.gov.br/fies', 'titulo' => 'Fies'],
        ['aliases' => ['Pronatec'], 'url' => 'https://www.gov.br/mec/pt-br/pronatec', 'titulo' => 'Pronatec'],

        // ═══ SAÚDE — Programas/Exames ═══
        ['aliases' => ['Mais Médicos'], 'url' => 'https://maismedicos.gov.br/', 'titulo' => 'Mais Médicos'],
        ['aliases' => ['Farmácia Popular'], 'url' => 'https://www.gov.br/saude/pt-br/acesso-a-informacao/acoes-e-programas/farmacia-popular', 'titulo' => 'Farmácia Popular'],

        // ═══ IMPOSTOS/RECEITA ═══
        ['aliases' => ['Imposto de Renda', 'IRPF', 'DIRPF'], 'url' => 'https://www.gov.br/receitafederal/pt-br/assuntos/meu-imposto-de-renda', 'titulo' => 'Imposto de Renda'],
        ['aliases' => ['MEI', 'Microempreendedor Individual'], 'url' => 'https://www.gov.br/empresas-e-negocios/pt-br/empreendedor', 'titulo' => 'MEI'],
        ['aliases' => ['DAS', 'Simples Nacional'], 'url' => 'https://www8.receita.fazenda.gov.br/SimplesNacional/', 'titulo' => 'Simples Nacional'],

        // ═══ APOSENTADORIA ═══
        ['aliases' => ['Meu INSS'], 'url' => 'https://meu.inss.gov.br/', 'titulo' => 'Meu INSS'],
        ['aliases' => ['Prova de Vida', 'prova de vida digital'], 'url' => 'https://www.gov.br/inss/pt-br/servicos-do-inss/prova-de-vida', 'titulo' => 'Prova de Vida INSS'],
    ];

    /**
     * Aplica auto-linking no HTML.
     * @return array ['html' => string, 'linkados' => [ ['orgao', 'url'], ... ]]
     */
    public static function aplicar(string $html): array
    {
        if (trim($html) === '') return ['html' => $html, 'linkados' => []];

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xp = new DOMXPath($dom);
        // Query de text-nodes válidos — re-executada a cada órgão (replaceChild torna nodes stale)
        $queryTextNodes = function() use ($xp) {
            return iterator_to_array($xp->query(
                '//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)'
                . ' and not(ancestor::h2) and not(ancestor::h3) and not(ancestor::h4)'
                . ' and not(ancestor::details) and not(ancestor::summary)'
                . ' and not(ancestor::*[contains(@class, "cluster-box") or contains(@class, "leia-tambem")'
                . ' or contains(@class, "msg-card") or contains(@class, "bloco-resumo") or contains(@class, "post-share")])]'
            ));
        };

        $linkados = [];
        $jaAplicadoEstaPassagem = [];

        // Pré-carrega URLs que JÁ TÊM <a> no HTML (passagens anteriores) —
        // evita linkar a mesma URL 2x quando Reformat roda no post.
        foreach (iterator_to_array($xp->query('//a/@href')) as $hrefAttr) {
            $jaAplicadoEstaPassagem[(string)$hrefAttr->nodeValue] = true;
        }

        foreach (self::$orgaos as $orgao) {
            $aliases = $orgao['aliases'];
            $url     = $orgao['url'];
            $titulo  = $orgao['titulo'];

            if (isset($jaAplicadoEstaPassagem[$url])) continue;

            // Re-query text nodes (podem ter mudado após replaceChild anterior)
            $textNodes = $queryTextNodes();

            foreach ($aliases as $alias) {
                $aliasRe = '/\b' . preg_quote($alias, '/') . '\b/u';
                $aplicado = false;

                // Estratégia: preferir ocorrências do MEIO do artigo (após o 1º H2)
                // sobre a primeira ocorrência (que pode estar no final).
                // 1ª passada: só nodes após o 1º H2. 2ª passada: qualquer lugar.
                $queueCandidatos = self::ordenarNodesPorPrioridade($textNodes, $xp, $aliasRe);

                foreach ($queueCandidatos as $node) {
                    if (!$node->parentNode) continue;
                    $text = $node->nodeValue;
                    if (!preg_match($aliasRe, $text, $m, PREG_OFFSET_CAPTURE)) continue;

                    $matchStart = $m[0][1];
                    $matchStr   = $m[0][0];
                    $antes  = substr($text, 0, $matchStart);
                    $depois = substr($text, $matchStart + strlen($matchStr));

                    $frag = $dom->createDocumentFragment();
                    if ($antes !== '')  $frag->appendChild($dom->createTextNode($antes));
                    $a = $dom->createElement('a', htmlspecialchars_decode($matchStr));
                    $a->setAttribute('href', $url);
                    $a->setAttribute('title', $titulo);
                    $a->setAttribute('rel', 'noopener nofollow');
                    $a->setAttribute('target', '_blank');
                    $a->setAttribute('data-authority-link', '1');
                    $frag->appendChild($a);
                    if ($depois !== '') $frag->appendChild($dom->createTextNode($depois));

                    $node->parentNode->replaceChild($frag, $node);
                    $linkados[] = ['orgao' => $matchStr, 'url' => $url];
                    $jaAplicadoEstaPassagem[$url] = true;
                    $aplicado = true;
                    break;
                }
                if ($aplicado) break; // próximo órgão
            }
        }

        if (empty($linkados)) return ['html' => $html, 'linkados' => []];

        $out = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return ['html' => $out !== '' ? $out : $html, 'linkados' => $linkados];
    }

    /**
     * Reordena text nodes para priorizar METADE PRO FIM do artigo.
     * Prioridade: após H2 #N/2 (metade) > após 1º H2 (meio) > antes de qualquer H2 (intro).
     * Autoridade perto da conclusão = reforço de confiança no momento de decisão do leitor.
     */
    private static function ordenarNodesPorPrioridade(array $textNodes, DOMXPath $xp, string $aliasRe): array
    {
        $h2s = iterator_to_array($xp->query('//h2'));
        if (empty($h2s)) return $textNodes;

        $totalH2 = count($h2s);
        // H2 da metade: se tem 5 H2, pegar o 3º (index 2); se tem 4, pegar o 2º (index 1)
        $idxMetade = (int)floor($totalH2 / 2);
        $h2Metade = $h2s[min($idxMetade, $totalH2 - 1)];
        $h2Primeiro = $h2s[0];

        $metadePraFim = []; // após H2 da metade — PRIORIDADE MÁXIMA
        $meio         = []; // entre 1º H2 e H2 da metade
        $intro        = []; // antes do 1º H2

        foreach ($textNodes as $n) {
            if (self::nodeDepois($n, $h2Metade)) $metadePraFim[] = $n;
            elseif (self::nodeDepois($n, $h2Primeiro)) $meio[] = $n;
            else $intro[] = $n;
        }
        return array_merge($metadePraFim, $meio, $intro);
    }

    /** Verifica se $node aparece DEPOIS de $ref na árvore DOM em ordem de documento. */
    private static function nodeDepois(DOMNode $node, DOMNode $ref): bool
    {
        // Percorre siblings+parents do $ref pra frente; se encontrar $node ou seu ancestor, é "depois"
        $atual = $ref;
        while ($atual) {
            // Vai pra próxima sibling
            $next = $atual->nextSibling;
            while ($next === null && $atual->parentNode) {
                $atual = $atual->parentNode;
                $next = $atual->nextSibling;
            }
            if ($next === null) return false;
            // Checa se $node está dentro de $next (ou É $next)
            if (self::contemOuEhNode($next, $node)) return true;
            $atual = $next;
        }
        return false;
    }

    private static function contemOuEhNode(DOMNode $container, DOMNode $alvo): bool
    {
        if ($container === $alvo) return true;
        $atual = $alvo;
        while ($atual = $atual->parentNode) {
            if ($atual === $container) return true;
        }
        return false;
    }
}
