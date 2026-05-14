<?php
declare(strict_types=1);
/**
 * Adiciona P2 (+ P3 quando necessário) na intro dos posts com intro-encurtada
 * detectado pelo AntiAIValidator. Manifesto editorial exige 3 P antes do 1º H2.
 *
 * Conteúdo escrito por Opus em sessão Claude Code (sem chamada LLM API).
 * Insere o(s) novo(s) parágrafo(s) imediatamente após o primeiro <p> existente.
 *
 * Uso:
 *   php scripts/_fix_intro_encurtada.php
 *   php scripts/_fix_intro_encurtada.php --dry-run
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();
$opts = getopt('', ['dry-run']);
$dryRun = isset($opts['dry-run']);

// Mapeamento post_id → HTML novo a inserir após o 1º <p>
// Linhas terminam com \n pra preservar formatação no editor WP
$alvos = [
    // ─── cursosenac ───
    'cursosenac' => [
        5841 => "\n<p>A formação é voltada à preservação e ao ensino da cultura popular brasileira, com aulas online ao vivo e produção acadêmica supervisionada pelos docentes da URCA e do Pontão BEATOS.</p>\n<p>Quem concluir as 360 horas recebe certificado de especialização reconhecido pelo MEC, e a turma reúne professores, gestores culturais e pesquisadores de diferentes estados.</p>\n",
            // 1 P → +2

        5834 => "\n<p>As opções incluem mestrados profissionais, doutorados acadêmicos e especializações em educação, com modalidades presenciais e a distância distribuídas em universidades federais, estaduais e institutos federais.</p>\n<p>A maioria dos editais aceita inscrição até o fim de maio, com seleção por análise de currículo, projeto de pesquisa ou prova específica conforme o programa indicado no Portal Mais Professores.</p>\n",
            // 1 P → +2

        5826 => "\n<p>O minicurso reúne fundamentos de análise de dados, ferramentas de inteligência artificial generativa e aplicações práticas no marketing digital, com carga horária total de 6 horas distribuídas nas duas datas.</p>\n",
            // 2 P → +1

        5802 => "\n<p>O curso técnico subsequente atende quem já tem ensino médio completo e quer entrar no mercado de design com formação reconhecida pelo MEC. A carga horária inclui aulas presenciais e atividades práticas em laboratório.</p>\n<p>As inscrições são gratuitas e a seleção considera análise de histórico escolar, sem prova específica, com previsão de início das aulas no segundo semestre letivo de 2026.</p>\n",
            // 1 P → +2

        5774 => "\n<p>O Edital PRENAE nº 30/2026 prevê sorteio eletrônico como critério único, sem cobrança de taxa, com inscrições abertas a candidatos que concluíram o ensino médio em qualquer rede.</p>\n",
            // 2 P → +1

        5769 => "\n<p>Os cursos são distribuídos entre Educação, Administração Pública e áreas afins, com encontros presenciais quinzenais aos fins de semana e atividades online supervisionadas durante a semana pelos tutores da Universidade Aberta do Brasil.</p>\n",
            // 2 P → +1

        5752 => "\n<p>As principais opções incluem cursos do Senac, da UFSC, do INES e de plataformas como Coursera e Unesp Aberta, com cargas horárias que variam entre 20 e 100 horas e níveis do básico ao avançado.</p>\n<p>Para reconhecimento profissional, vale optar por cursos com certificação registrada pela instituição emissora, especialmente os ofertados por universidades federais e centros de referência em educação inclusiva.</p>\n",
            // 1 P → +2

        5735 => "\n<p>A pós-graduação lato sensu é ofertada pelo Campus Suzano em modalidade presencial e atende profissionais que querem aprofundar a atuação em sustentabilidade, meio ambiente e práticas pedagógicas socioambientais.</p>\n<p>As inscrições seguem cronograma do edital específico, com previsão de início das aulas no segundo semestre letivo de 2026 e estrutura concentrada em encontros aos fins de semana.</p>\n",
            // 1 P → +2
    ],

    // ─── leaodabarra ───
    'leaodabarra' => [
        1358 => "\n<p>A janela de transferências brasileira abre em julho, mas clubes da Arábia Saudita costumam fechar contratações antes do meio do ano fiscal saudita, em maio. A combinação de calendários pressiona o Vitória a definir cláusulas e valores em curto prazo.</p>\n",
            // 2 P → +1

        1318 => "\n<p>Os setores Norte e Sul têm preços diferenciados, com descontos para crianças, idosos e estudantes em ingressos específicos. O pagamento aceita Pix, cartão de crédito com parcelamento e boleto, dependendo da plataforma escolhida.</p>\n",
            // 2 P → +1

        1313 => "\n<p>A transmissão começa às 21h com o pré-jogo direto do Barradão pelo SporTV, e o Premiere abre acesso online para assinantes em smartphones, tablets e smart TVs com aplicativo do Globoplay.</p>\n",
            // 2 P → +1

        1301 => "\n<p>A decisão foi unânime entre os desembargadores do TRT-5, que rejeitaram tanto o Mandado de Segurança quanto a tese de hipersuficiência apresentada pelo Vitória contra a liminar da 18ª Vara do Trabalho de Salvador.</p>\n<p>O atacante já foi alvo do Fortaleza no início do ano e agora pode definir o futuro na janela de transferências do meio do ano, sem precisar de anuência do clube baiano para assinar contrato com outro time.</p>\n",
            // 1 P → +2
    ],

    // ─── comocomprar ───
    'comocomprar' => [
        3198 => "\n<p>Os 2 modelos estão disponíveis em varejistas online com frete grátis para CEPs selecionados, e o desconto vale enquanto durar o estoque indicado pela loja. Vale comparar prazo de entrega e política de garantia antes de fechar.</p>\n",
            // 2 P → +1

        3146 => "\n<p>Marcas como Sony, Bose, JBL, Edifier e Anker dominam a faixa, com diferenças marcantes em qualidade de cancelamento ativo de ruído, aplicativos de equalização e suporte a multipoint para 2 dispositivos simultâneos.</p>\n",
            // 2 P → +1
    ],
];

function inserirAposPrimeiroP(string $html, string $blocoNovo): array {
    // Encontra o final do primeiro <p>...</p> textual (não comentário, não <p></p>)
    if (preg_match('/<p[^>]*>(?:(?!<\/p>).)+?<\/p>/s', $html, $m, PREG_OFFSET_CAPTURE)) {
        $endPos = $m[0][1] + strlen($m[0][0]);
        $novo = substr($html, 0, $endPos) . $blocoNovo . substr($html, $endPos);
        return ['html' => $novo, 'inserido' => true];
    }
    return ['html' => $html, 'inserido' => false];
}

$total = 0; $okCount = 0;
foreach ($alvos as $slugSite => $mapa) {
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $slugSite);
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);
    echo "\n════ {$slugSite} ════\n";
    foreach ($mapa as $pid => $blocoNovo) {
        $total++;
        try {
            $p = $wp->getPost($pid);
            $raw = (string)($p['content']['raw'] ?? '');
            if ($raw === '') { echo "  #{$pid}: vazio\n"; continue; }
            $res = inserirAposPrimeiroP($raw, $blocoNovo);
            if (!$res['inserido']) { echo "  ⚠ #{$pid}: 1º <p> não encontrado, skip\n"; continue; }
            // Conta novas linhas <p>
            $novos = substr_count($blocoNovo, '<p>');
            if ($dryRun) {
                echo "  [DRY] #{$pid}: inseriria {$novos} parágrafo(s)\n";
                continue;
            }
            $r = $wp->atualizarPost($pid, ['content' => $res['html']]);
            echo "  ✅ #{$pid}: +{$novos} P inseridos (status: " . ($r['status'] ?? '?') . ")\n";
            $okCount++;
        } catch (Throwable $e) {
            echo "  ❌ #{$pid}: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n═════ RESUMO ═════\n";
echo "Posts processados: {$total}\n";
echo "Posts atualizados: {$okCount}\n";
if ($dryRun) echo "[DRY-RUN]\n";
