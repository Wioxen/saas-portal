<?php
declare(strict_types=1);
/** cc — gera os CSVs de UPLOAD EM MASSA (Google Ads Editor) das 3 campanhas de afiliado pago,
 *  a partir das specs em docs/ads/CAMPANHA-*.md. NÃO toca na conta: só escreve arquivos.
 *
 *  php scripts/_cc_ads_bulk_csv.php            # valida e mostra o relatório
 *  php scripts/_cc_ads_bulk_csv.php --write    # valida e grava em docs/ads/bulk/
 *
 *  DUAS TRAVAS, e qualquer uma delas ABORTA sem gravar:
 *
 *  1. LIMITES que quebram importação: título >30, descrição >90, RSA com menos de 3 títulos ou
 *     2 descrições, path >15, sitelink >25, linha de sitelink >35, frase de destaque >25,
 *     valor de snippet >25, correspondência inválida.
 *
 *  2. LINT DE POLÍTICA: a rede já tomou 2 reprovações automáticas (manutenção de celular, 14/07),
 *     então termo de risco não passa. Promessa absoluta em produto de estética ("permanente",
 *     "garantido", "100%", "sem dor", "definitivo") e marca de terceiro que NÃO vendemos
 *     (Creed, Dior, Sauvage, Paco Rabanne, One Million, Bleu de Chanel) estão banidos do texto
 *     de anúncio. As grifes imitadas entram como NEGATIVAS, nunca como criativo.
 *
 *  CTR: 12 títulos e 4 descrições nos grupos que sobem ligados, 8 e 4 nos pausados, cobrindo
 *  ângulos distintos (keyword, benefício, objeção, prova, comparação, CTA) — títulos parecidos
 *  fazem o Google descartar combinações. SEM pinning: fixar posição corta as combinações e
 *  costuma derrubar o CTR. Mais 4 sitelinks com descrição, 6 frases de destaque e 1 snippet por
 *  campanha — extensão ocupa mais área no resultado e sobe CTR sem custo adicional.
 *
 *  Campanhas nascem PAUSADAS de propósito: a conta é pré-paga e está zerada; quando o saldo
 *  entrar, ninguém quer 3 campanhas não revisadas começando a gastar sozinhas no mesmo segundo.
 */
date_default_timezone_set('America/Sao_Paulo');
$ROOT = dirname(__DIR__);
$op = getopt('', ['write']);
$WRITE = isset($op['write']);
$OUT = $ROOT . '/docs/ads/bulk';

$UTM = 'utm_source=google&utm_medium=cpc&utm_campaign=%s&utm_content={adgroupid}&utm_term={keyword}';

// Valores monetários com VÍRGULA decimal. A conta é pt-BR e o uploader lê o número no idioma do
// arquivo: com "25.00" ele recusa ("não foi possível analisar o valor"). Confirmado na
// pré-visualização de 24/07. fputcsv cuida de aspear o campo por causa da vírgula.

/** termos que não podem aparecer em NENHUM texto de anúncio/extensão */
$BANIDOS = [
  'permanente','garantid','100%','sem dor','definitiv','milagr','cura ',
  'creed','dior','sauvage','paco rabanne','one million','bleu de chanel','aventus',
];

$CAMPANHAS = [
  [
    'nome'      => 'CC | Perfume Árabe Masc | Search | BR',
    'lp'        => 'https://comocomprar.com.br/melhor-perfume-arabe-masculino/',
    'orcamento' => '25,00',
    'teto_cpc'  => '1,00',
    'utm'       => 'perfume_arabe',
    'path1'     => 'perfume-arabe',
    'path2'     => 'comparativo',
    'negativas' => ['feminino','para mulher','yara','decant','5ml','10ml','amostra','receita','como fazer',
                    'caseiro','formula','atacado','revenda','fornecedor','kit revenda','mercado livre','shopee',
                    'magalu','magazine','americanas','natura','boticario','o boticario','avon','creed','dior',
                    'sauvage','paco rabanne','one million','bleu de chanel','emprego','vaga','curso','letra','musica'],
    'sitelinks' => [
      ['Qual comprar por perfil','Amadeirado, doce ou fresco','Ache o seu em 2 minutos'],
      ['Fixação e projeção','Quais duram 8h ou mais','Comparados lado a lado'],
      ['Como aplicar pra durar','Pontos de pulso e roupa','Rende mais sem gastar mais'],
      ['É original mesmo?','Como identificar o genuíno','Antes de fechar a compra'],
    ],
    'destaques' => ['Fixação 8h+','Comparativo independente','74 mil avaliações','Guia 2026',
                    'Escolha por perfil','Sem enrolação'],
    'snippet'   => ['Tipos', ['Amadeirado','Doce','Fresco','Gourmand']],
    'grupos' => [
      ['nome'=>'AG1 - Generico BOFU','status'=>'Enabled','kws'=>[
          ['perfume arabe masculino','Phrase'],['perfume arabe masculino','Exact'],
          ['melhor perfume arabe masculino','Exact'],['perfume arabe masculino qual comprar','Exact'],
          ['perfume arabe masculino mais elogiado','Exact'],['perfume arabe masculino que fixa','Exact'],
          ['melhores perfumes arabes masculinos','Phrase'],
        ],
        'titulos'=>['Perfume Árabe Masculino 2026','Os 6 Mais Avaliados','Qual Comprar Sem Errar',
                    'Fixa o Dia Todo (8h+)','Guia Direto ao Ponto','Custo-Benefício Absurdo',
                    'Escolha por Perfil','Mais de 74 Mil Avaliações','Comparativo Honesto 2026',
                    'Amadeirado, Doce ou Fresco','Veja o Ranking Completo','Antes de Comprar, Compare'],
        'descricoes'=>[
          'Comparamos os 6 melhores árabes masculinos por fixação e perfil. Veja qual comprar.',
          'Fixação de 8h+, elogios e preço muito abaixo do importado. Guia completo 2026.',
          'Amadeirado, doce ou fresco? Descubra o árabe certo pro seu estilo em 2 minutos.',
          'Ranking por perfil olfativo, fixação e ocasião. Mais de 74 mil avaliações reais.',
        ]],
      ['nome'=>'AG2 - Marca arabe','status'=>'Enabled','kws'=>[
          ['lattafa asad','Exact'],['afnan 9pm','Exact'],['lattafa khamrah','Exact'],
          ['armaf club de nuit intense','Exact'],['perfume lattafa masculino','Phrase'],
          ['perfume afnan masculino','Phrase'],['perfume armaf masculino','Phrase'],
          ['club de nuit intense','Exact'],
        ],
        'titulos'=>['Lattafa, Afnan e Armaf','Asad, 9pm e Club de Nuit','Qual Árabe Comprar 2026',
                    'Comparativo Honesto','Fixação e Projeção Reais','Os Mais Vendidos',
                    'Khamrah, Asad ou 9pm?','Compare Antes de Comprar','Perfil Olfativo por Marca',
                    'Guia das Marcas Árabes','Veja o Ranking por Marca','Marca Certa Pro Seu Estilo'],
        'descricoes'=>[
          'Asad, 9pm, Khamrah e Club de Nuit comparados por perfil, fixação e ocasião.',
          'Guia dos árabes mais desejados por perfil olfativo e ocasião. Direto ao ponto.',
          'Qual marca árabe combina com você? Compare fixação, projeção e ocasião de uso.',
          'Lattafa, Afnan e Armaf lado a lado. Veja qual entrega mais pelo que custa.',
        ]],
      ['nome'=>'AG3 - Beneficio','status'=>'Paused','kws'=>[
          ['perfume masculino que fixa o dia todo','Exact'],['perfume masculino mais elogiado','Exact'],
          ['perfume masculino que dura muito','Exact'],['perfume masculino forte e barato','Exact'],
          ['perfume masculino fixacao 8 horas','Phrase'],
        ],
        'titulos'=>['Perfume Que Fixa 8h+','O Mais Elogiado de 2026','Marcante e Barato',
                    'Dura o Dia Inteiro','Elogio na Rua','Projeção de Verdade',
                    'Compare Fixação Real','Qual Dura Mais?'],
        'descricoes'=>[
          'Quer fixação de verdade e elogios? Veja os árabes masculinos que mais duram.',
          'Perfume marcante por uma fração do importado. Comparamos fixação e projeção.',
          'Comparativo de fixação e projeção com base em quem usou. Veja qual escolher.',
          'Fixação de 8h+ sem pagar preço de importado. Guia por perfil e ocasião.',
        ]],
      ['nome'=>'AG4 - Ocasiao','status'=>'Paused','kws'=>[
          ['perfume masculino para noite','Exact'],['perfume masculino amadeirado','Exact'],
          ['perfume masculino doce','Exact'],['melhor perfume arabe para o dia','Exact'],
        ],
        'titulos'=>['Árabe Certo Pra Cada Ocasião','Doce, Fresco ou Amadeirado','Pra Noite ou Pro Dia',
                    'Escolha Sem Errar','Perfume Pra Noite','Pro Dia a Dia no Trabalho',
                    'Qual Usar em Cada Momento','Guia por Ocasião 2026'],
        'descricoes'=>[
          'Amadeirado pra noite, fresco pro dia: veja o árabe ideal pra cada momento.',
          'Doce, fresco ou amadeirado: escolha o perfume certo pra cada ocasião.',
          'Trabalho, balada ou encontro? Veja qual árabe combina com cada situação.',
          'Guia por ocasião e perfil olfativo, com fixação comparada. Direto ao ponto.',
        ]],
    ],
  ],
  [
    'nome'      => 'CC | Extratora Estofado | Search | BR',
    'lp'        => 'https://comocomprar.com.br/comprar-extratora-de-estofado/',
    'orcamento' => '25,00',
    'teto_cpc'  => '1,20',
    'utm'       => 'extratora',
    'path1'     => 'extratora',
    'path2'     => 'comparativo',
    'negativas' => ['aluguel','alugar','locacao','higienizacao profissional','empresa de limpeza','curso',
                    'como fazer caseiro','receita','manual','mercado livre','shopee','magalu','casas bahia',
                    'usado','conserto','peca','mangueira','motor','lavadora de alta pressao','aspirador'],
    'sitelinks' => [
      ['Portátil ou barril','Qual formato serve pra você','Uso doméstico ou pesado'],
      ['Vale a pena comprar?','Compare com o preço do serviço','Se paga em poucos usos'],
      ['Como usar sem manchar','Passo a passo no sofá','Sem encharcar o estofado'],
      ['As mais avaliadas','Notas reais de quem comprou','WAP, Karcher e Vonder'],
    ],
    'destaques' => ['Se paga em poucos usos','Comparativo independente','371 avaliações',
                    'Sofá, colchão e carro','Portátil ou barril','Guia 2026'],
    'snippet'   => ['Tipos', ['Portátil','Barril','Para sofá','Para carro']],
    'grupos' => [
      ['nome'=>'AG1 - Compra direta','status'=>'Enabled','kws'=>[
          ['comprar extratora de estofado','Exact'],['extratora de estofado','Exact'],
          ['melhor extratora de estofado','Exact'],['extratora para sofa','Exact'],
          ['extratora de estofado qual comprar','Exact'],['extratora para limpar sofa','Phrase'],
        ],
        'titulos'=>['Extratora de Estofado 2026','Qual Comprar Sem Errar','Portátil ou Barril?',
                    'Higienize Sofá em Casa','Se Paga em 1 Limpeza','Guia Direto ao Ponto',
                    'Sofá, Colchão e Carro','Comparativo Honesto','Sucção e Reservatório',
                    'As Mais Avaliadas','Compare Antes de Comprar','Pare de Pagar Serviço'],
        'descricoes'=>[
          'Compare as melhores extratoras por sucção, reservatório e uso real. Veja qual comprar.',
          'Pare de pagar serviço: higienize sofá, colchão e carro em casa. Guia completo 2026.',
          'Portátil pra sofá e carro ou barril pra uso pesado? Veja qual atende você.',
          'Uma limpeza profissional custa caro. A extratora se paga em poucos usos.',
        ]],
      ['nome'=>'AG2 - Marca','status'=>'Enabled','kws'=>[
          ['wap spot cleaner','Exact'],['extratora wap','Exact'],['karcher puzzi','Exact'],
          ['extratora vonder','Exact'],['wap home cleaner','Phrase'],['extratora karcher','Exact'],
        ],
        'titulos'=>['WAP, Karcher e Vonder','Qual Extratora Comprar','Portátil x Barril',
                    'Comparativo Honesto','WAP Spot ou Home Cleaner','Karcher Puzzi Vale?',
                    'Extratora Vonder ELV','Compare as Marcas','Qual Marca Suga Mais',
                    'Guia das Marcas 2026','As Mais Avaliadas','Antes de Escolher, Compare'],
        'descricoes'=>[
          'WAP Spot Cleaner, Home Cleaner, Karcher e Vonder comparadas por uso real.',
          'Portátil para sofá e carro ou barril para uso pesado? Veja qual comprar.',
          'Comparamos sucção, reservatório e praticidade de cada marca. Veja a melhor.',
          'Qual marca entrega mais pelo que custa? Guia com avaliações reais de quem usou.',
        ]],
      ['nome'=>'AG3 - Dor e uso','status'=>'Paused','kws'=>[
          ['como limpar sofa encardido','Exact'],['higienizar sofa em casa','Exact'],
          ['extratora para carro','Exact'],['maquina de limpar estofado','Exact'],
          ['extratora portatil','Exact'],
        ],
        'titulos'=>['Limpe o Sofá Encardido','A Água Sai Preta','Extratora Para Carro',
                    'Higieniza de Verdade','Sofá Como Novo','Colchão e Banco de Carro',
                    'Veja Qual Comprar','O Aspirador Não Tira'],
        'descricoes'=>[
          'A extratora tira a sujeira que o aspirador não tira. Veja qual comprar.',
          'Sofá encardido, colchão e banco de carro: veja a extratora certa pra cada uso.',
          'Higienize em casa o que você pagaria caro pra terceirizar. Guia completo.',
          'Compare por sucção e reservatório e escolha a extratora certa pro seu uso.',
        ]],
    ],
  ],
  [
    'nome'      => 'CC | Depilador Luz Pulsada | Search | BR',
    'lp'        => 'https://comocomprar.com.br/melhor-depilador-de-luz-pulsada/',
    'orcamento' => '25,00',
    'teto_cpc'  => '1,20',
    'utm'       => 'depilador_ipl',
    'path1'     => 'depilador-ipl',
    'path2'     => 'comparativo',
    'negativas' => ['depilacao a laser','clinica','sessao','sessoes','preco sessao','quanto custa depilacao',
                    'perto de mim','agendar','funciona mesmo','dos','diy','faz mal','gravidez','mercado livre',
                    'shopee','magalu','natura','pelo loiro','pelo branco','pelo grisalho'],
    'sitelinks' => [
      ['Funciona mesmo?','O que esperar de resultado','Em quantas aplicações'],
      ['Dói? Como usar','Com resfriamento na ponteira','Passo a passo seguro'],
      ['Qual pele e pelo','Rende mais em pelo escuro','Veja se serve pro seu caso'],
      ['Ulike ou MLAY','Comparativo entre marcas','Conforto, potência e preço'],
    ],
    'destaques' => ['Custa menos que clínica','Comparativo independente','Avaliações reais',
                    'Corpo e rosto','Com resfriamento','Guia 2026'],
    'snippet'   => ['Tipos', ['Corpo','Rosto','Virilha','Axilas']],
    'grupos' => [
      ['nome'=>'AG1 - Compra de aparelho','status'=>'Enabled','kws'=>[
          ['depilador a laser caseiro','Exact'],['melhor depilador a laser caseiro','Exact'],
          ['depilador de luz pulsada','Exact'],['melhor depilador de luz pulsada','Exact'],
          ['depilador ipl','Exact'],['depilador a laser portatil','Exact'],
          ['comprar depilador de luz pulsada','Phrase'],
        ],
        'titulos'=>['Depilador Luz Pulsada 2026','IPL Para Casa','Reduz Pelo Com Conforto',
                    'Qual Comprar Sem Errar','Custa Menos Que a Clínica','Funciona? Veja a Verdade',
                    'Guia Direto ao Ponto','Comparativo Honesto 2026','Com Resfriamento na Ponta',
                    'Serve Pra Sua Pele?','Os Mais Avaliados','Compare Antes de Comprar'],
        'descricoes'=>[
          'Compare os IPL caseiros por conforto, resultado e preço. Veja qual comprar.',
          'Reduza os pelos em casa sem pagar preço de clínica. Comparativo honesto 2026.',
          'Com resfriamento na ponteira. Veja o aparelho certo pra sua pele e seu pelo.',
          'IPL rende mais em pele clara com pelo escuro. Veja se serve pro seu caso.',
        ]],
      ['nome'=>'AG2 - Marca','status'=>'Enabled','kws'=>[
          ['ulike','Exact'],['ulike air 10','Exact'],['mlay','Exact'],['mlay t4','Exact'],
          ['depilador ulike','Phrase'],['depilador mlay','Phrase'],
        ],
        'titulos'=>['Ulike ou MLAY?','Depilador IPL Premium','Qual Marca Comprar',
                    'Comparativo Honesto','Conforto e Resultado','Ulike Air 10 Vale?',
                    'MLAY T4 Comparado','Compare as Marcas IPL','Qual Entrega Mais',
                    'Guia das Marcas 2026','As Mais Avaliadas','Antes de Escolher, Veja'],
        'descricoes'=>[
          'Ulike, MLAY e mais comparados por conforto, resultado e preço. Veja o melhor.',
          'Compare os IPL de topo por conforto, potência e resultado. Veja qual vale.',
          'Qual marca entrega mais pelo que custa? Guia com avaliações reais de quem usou.',
          'Do intermediário ao topo de linha: veja qual aparelho combina com seu uso.',
        ]],
      ['nome'=>'AG3 - Depilador a laser','status'=>'Paused','kws'=>[
          ['depilador a laser','Phrase'],['depilador a laser para casa','Exact'],
          ['depilador a laser funciona','Exact'],
        ],
        'titulos'=>['Depilador a Laser Caseiro','Reduza os Pelos em Casa','Vale a Pena? Veja',
                    'Guia de Compra 2026','Funciona Mesmo?','Serve Pra Sua Pele?',
                    'Custa Menos Que Clínica','Compare os Modelos'],
        'descricoes'=>[
          'Depilador a laser caseiro funciona? Comparamos os melhores por pele e preço.',
          'Veja se o aparelho caseiro funciona no seu tipo de pele e pelo antes de comprar.',
          'Aparelho de casa custa menos que sessão de clínica. Compare os modelos.',
          'Guia com avaliações reais, adequação de pele e pelo, e o que esperar do uso.',
        ]],
    ],
  ],
];

// ---------- validação ----------
$erros = [];
$lint = function(string $txt, string $ctx) use ($BANIDOS, &$erros) {
  $low = mb_strtolower($txt);
  foreach ($BANIDOS as $b) {
    if (mb_strpos($low, $b) !== false) $erros[] = "POLÍTICA — $ctx: termo banido '$b' em \"$txt\"";
  }
};
$lim = function(string $txt, int $max, string $ctx) use (&$erros) {
  $n = mb_strlen($txt);
  if ($n > $max) $erros[] = "LIMITE — $ctx: $n chars (max $max) -> \"$txt\"";
};

foreach ($CAMPANHAS as $c) {
  $lim($c['path1'], 15, "{$c['nome']} path1");
  $lim($c['path2'], 15, "{$c['nome']} path2");
  foreach ($c['sitelinks'] as $s) {
    $lim($s[0], 25, "{$c['nome']} sitelink"); $lint($s[0], "{$c['nome']} sitelink");
    $lim($s[1], 35, "{$c['nome']} sitelink desc1"); $lint($s[1], "{$c['nome']} sitelink desc1");
    $lim($s[2], 35, "{$c['nome']} sitelink desc2"); $lint($s[2], "{$c['nome']} sitelink desc2");
  }
  foreach ($c['destaques'] as $d) { $lim($d, 25, "{$c['nome']} destaque"); $lint($d, "{$c['nome']} destaque"); }
  foreach ($c['snippet'][1] as $v) { $lim($v, 25, "{$c['nome']} snippet"); $lint($v, "{$c['nome']} snippet"); }
  if (count($c['snippet'][1]) < 3) $erros[] = "{$c['nome']}: snippet precisa de no mínimo 3 valores";

  foreach ($c['grupos'] as $g) {
    $ctx = "{$c['nome']} / {$g['nome']}";
    if (count($g['titulos']) < 3)    $erros[] = "$ctx: RSA com ".count($g['titulos'])." títulos (mínimo 3)";
    if (count($g['descricoes']) < 2) $erros[] = "$ctx: RSA com ".count($g['descricoes'])." descrições (mínimo 2)";
    if (count(array_unique($g['titulos'])) !== count($g['titulos'])) $erros[] = "$ctx: título repetido no RSA";
    foreach ($g['titulos'] as $t)    { $lim($t, 30, "$ctx título"); $lint($t, "$ctx título"); }
    foreach ($g['descricoes'] as $d) { $lim($d, 90, "$ctx descrição"); $lint($d, "$ctx descrição"); }
    foreach ($g['kws'] as $k) {
      if (trim($k[0]) === '') $erros[] = "$ctx: keyword vazia";
      if (!in_array($k[1], ['Exact','Phrase','Broad'], true)) $erros[] = "$ctx: correspondência inválida '{$k[1]}'";
    }
  }
}

// ---------- relatório ----------
echo "=== VALIDAÇÃO ===\n";
$maxT=0; $maxD=0; $nKw=0; $nNeg=0; $nAds=0; $nGrp=0; $minT=99;
foreach ($CAMPANHAS as $c) {
  $nNeg += count($c['negativas']);
  foreach ($c['grupos'] as $g) {
    $nGrp++; $nKw += count($g['kws']); $nAds++;
    $minT = min($minT, count($g['titulos']));
    foreach ($g['titulos'] as $t)    $maxT = max($maxT, mb_strlen($t));
    foreach ($g['descricoes'] as $d) $maxD = max($maxD, mb_strlen($d));
  }
}
echo "campanhas: ".count($CAMPANHAS)." | grupos: $nGrp | keywords: $nKw | negativas: $nNeg | RSAs: $nAds\n";
echo "títulos por RSA: mínimo $minT | maior título: {$maxT}/30 | maior descrição: {$maxD}/90\n";
echo "extensões: ".(count($CAMPANHAS)*4)." sitelinks, ".(count($CAMPANHAS)*6)." frases de destaque, ".count($CAMPANHAS)." snippets\n";
if ($erros) {
  echo "\n✗ ".count($erros)." VIOLAÇÃO(ÕES) — nada foi gravado:\n";
  foreach ($erros as $e) echo "  - $e\n";
  exit(1);
}
echo "✓ sem violações de limite nem de política\n";

if (!$WRITE) { echo "\nDRY. Rode com --write para gravar em docs/ads/bulk/.\n"; exit(0); }

// ---------- escrita ----------
if (!is_dir($OUT)) mkdir($OUT, 0777, true);
$bom = "\xEF\xBB\xBF";
$put = function(string $arquivo, array $linhas) use ($OUT, $bom) {
  $fh = fopen("$OUT/$arquivo", 'wb');
  fwrite($fh, $bom);
  foreach ($linhas as $l) fputcsv($fh, $l);
  fclose($fh);
  echo "  ✓ $arquivo (".(count($linhas)-1)." linhas)\n";
};

echo "\n=== GRAVANDO em docs/ads/bulk/ ===\n";

// Cabeçalhos ajustados pelo que o uploader web REALMENTE aceitou na pré-visualização de 24/07
// (3 alterações, 3 erros, nenhuma aplicada):
//  · "Campaign Daily Budget" NÃO foi mapeado -> o Ads acusou "Está faltando um valor em Orçamento".
//    O nome que ele reconhece é "Budget".
//  · "Language: Portuguese" foi recusado ("um idioma não é reconhecido"). Coluna REMOVIDA: idioma
//    não é obrigatório e sem ela a campanha nasce em todos os idiomas, o que para busca no Brasil
//    não atrapalha. Melhor isso do que adivinhar o rótulo aceito.
//  · "Anúncios políticos na UE" é campo OBRIGATÓRIO novo e não existia no arquivo.
// Colunas desconhecidas são ignoradas em silêncio (o arquivo tinha várias e só estes 3 erros
// apareceram), então as de rede seguem no arquivo.
$rows = [['Campaign','Campaign Type','Campaign Status','Budget','Budget Type',
          'Bid Strategy Type','Maximum CPC Bid Limit','Search Network','Search Partners','Display Network',
          'Location','EU political ads','Final URL Suffix']];
foreach ($CAMPANHAS as $c) {
  $rows[] = [$c['nome'],'Search','Paused',$c['orcamento'],'Daily','Maximize clicks',$c['teto_cpc'],
             'Enabled','Disabled','Disabled','Brazil','No',sprintf($UTM,$c['utm'])];
}
$put('01-campanhas.csv', $rows);

$rows = [['Campaign','Ad Group','Ad Group Status','Max CPC']];
foreach ($CAMPANHAS as $c) foreach ($c['grupos'] as $g) $rows[] = [$c['nome'],$g['nome'],$g['status'],$c['teto_cpc']];
$put('02-grupos.csv', $rows);

// O uploader web é LOCALIZADO: recusou "Phrase" com "O valor 'Phrase' na coluna 'Criterion Type'
// é inválido". Internamente seguimos usando os nomes em inglês (a validação depende deles);
// a tradução acontece só na hora de escrever o CSV.
$CORRESP = ['Exact'=>'Exata', 'Phrase'=>'Frase', 'Broad'=>'Ampla'];

$rows = [['Campaign','Ad Group','Keyword','Criterion Type','Final URL']];
foreach ($CAMPANHAS as $c) foreach ($c['grupos'] as $g) foreach ($g['kws'] as $k)
  $rows[] = [$c['nome'],$g['nome'],$k[0],$CORRESP[$k[1]],$c['lp']];
$put('03-palavras-chave.csv', $rows);

// Idem para a negativa de campanha: "Campaign Negative Phrase" foi recusado pelo mesmo motivo
// que "Phrase". O valor localizado é "Frase negativa da campanha".
$rows = [['Campaign','Keyword','Criterion Type']];
foreach ($CAMPANHAS as $c) foreach ($c['negativas'] as $n)
  $rows[] = [$c['nome'],$n,'Frase negativa da campanha'];
$put('04-negativas.csv', $rows);

$maxTit = 15; $maxDesc = 4;
$head = ['Campaign','Ad Group','Ad Type'];
for ($i=1;$i<=$maxTit;$i++)  $head[] = "Headline $i";
for ($i=1;$i<=$maxDesc;$i++) $head[] = "Description $i";
array_push($head, 'Final URL', 'Path 1', 'Path 2');
$rows = [$head];
foreach ($CAMPANHAS as $c) foreach ($c['grupos'] as $g) {
  $r = [$c['nome'],$g['nome'],'Responsive search ad'];
  for ($i=0;$i<$maxTit;$i++)  $r[] = $g['titulos'][$i]    ?? '';
  for ($i=0;$i<$maxDesc;$i++) $r[] = $g['descricoes'][$i] ?? '';
  array_push($r, $c['lp'], $c['path1'], $c['path2']);
  $rows[] = $r;
}
$put('05-anuncios-rsa.csv', $rows);

// Extensões são "recursos" (assets). Sem dizer o contrário, o uploader assume "Ação do recurso:
// Usar existente" e falha com «Valores incompatíveis em "Ação do recurso: Usar existente" e
// "ID do item: null"» — ele procura um recurso que ainda não existe. A coluna "Asset action" com
// valor "Criar novo" instrui a criar. Padrão já observado: nome de coluna em inglês, VALOR em pt-BR.
$ACAO_RECURSO = 'Create new';

$rows = [['Campaign','Asset action','Sitelink Text','Sitelink Description 1','Sitelink Description 2','Final URL']];
foreach ($CAMPANHAS as $c) foreach ($c['sitelinks'] as $s)
  $rows[] = [$c['nome'],$ACAO_RECURSO,$s[0],$s[1],$s[2],$c['lp']];
$put('06-sitelinks.csv', $rows);

$rows = [['Campaign','Asset action','Callout Text']];
foreach ($CAMPANHAS as $c) foreach ($c['destaques'] as $d) $rows[] = [$c['nome'],$ACAO_RECURSO,$d];
$put('07-frases-destaque.csv', $rows);

// "Header"/"Snippet Values" NÃO foram reconhecidos: a pré-visualização voltou 0 alterações e
// 0 erros — falha silenciosa, o pior tipo. Os nomes específicos do recurso são
// "Structured snippet header" e "Structured snippet values".
$rows = [['Campaign','Asset action','Structured snippet header','Structured snippet values']];
foreach ($CAMPANHAS as $c) $rows[] = [$c['nome'],$ACAO_RECURSO,$c['snippet'][0],implode('; ',$c['snippet'][1])];
$put('08-snippets.csv', $rows);

echo "\nPronto. Revise os arquivos antes de importar no Google Ads Editor.\n";
