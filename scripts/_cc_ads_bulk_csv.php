<?php
declare(strict_types=1);
/** cc — gera os CSVs de UPLOAD EM MASSA (Google Ads Editor) das 3 campanhas de afiliado pago,
 *  a partir das specs em docs/ads/CAMPANHA-*.md. NÃO toca na conta: só escreve arquivos.
 *
 *  php scripts/_cc_ads_bulk_csv.php            # valida e mostra o relatório
 *  php scripts/_cc_ads_bulk_csv.php --write    # valida e grava em docs/ads/bulk/
 *
 *  Valida o que quebra importação de verdade: título >30, descrição >90, RSA com menos de
 *  3 títulos ou 2 descrições, path >15, keyword vazia. Se houver violação, ABORTA sem gravar.
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

$CAMPANHAS = [
  [
    'nome'      => 'CC | Perfume Árabe Masc | Search | BR',
    'lp'        => 'https://comocomprar.com.br/melhor-perfume-arabe-masculino/',
    'orcamento' => '25.00',
    'teto_cpc'  => '1.00',
    'utm'       => 'perfume_arabe',
    'path1'     => 'perfume-arabe',
    'path2'     => 'comparativo',
    'negativas' => ['feminino','para mulher','yara','decant','5ml','10ml','amostra','receita','como fazer',
                    'caseiro','formula','atacado','revenda','fornecedor','kit revenda','mercado livre','shopee',
                    'magalu','magazine','americanas','natura','boticario','o boticario','avon','creed','dior',
                    'sauvage','paco rabanne','one million','bleu de chanel','emprego','vaga','curso','letra','musica'],
    'grupos' => [
      ['nome'=>'AG1 - Generico BOFU','status'=>'Enabled','kws'=>[
          ['perfume arabe masculino','Phrase'],
          ['perfume arabe masculino','Exact'],
          ['melhor perfume arabe masculino','Exact'],
          ['perfume arabe masculino qual comprar','Exact'],
          ['perfume arabe masculino mais elogiado','Exact'],
          ['perfume arabe masculino que fixa','Exact'],
          ['melhores perfumes arabes masculinos','Phrase'],
        ],
        'titulos'=>['Perfume Árabe Masculino 2026','Os 6 Mais Elogiados','Qual Comprar Sem Errar',
                    'Fixa o Dia Todo (8h+)','Guia Direto ao Ponto','Custo-Benefício Absurdo',
                    'Escolha por Perfil','Mais de 74 Mil Avaliações'],
        'descricoes'=>[
          'Comparamos os 6 melhores árabes masculinos por fixação e perfil. Veja qual comprar.',
          'Fixação de 8h+, elogios e preço muito abaixo do importado. Guia completo 2026.',
          'Amadeirado, doce ou fresco? Descubra o árabe certo pro seu estilo em 2 minutos.',
        ]],
      ['nome'=>'AG2 - Marca arabe','status'=>'Enabled','kws'=>[
          ['lattafa asad','Exact'],
          ['afnan 9pm','Exact'],
          ['lattafa khamrah','Exact'],
          ['armaf club de nuit intense','Exact'],
          ['perfume lattafa masculino','Phrase'],
          ['perfume afnan masculino','Phrase'],
          ['perfume armaf masculino','Phrase'],
          ['club de nuit intense','Exact'],
        ],
        'titulos'=>['Lattafa, Afnan e Armaf','Asad, 9pm e Club de Nuit','Qual Árabe Comprar 2026',
                    'Comparativo Honesto','Fixação e Projeção Reais','Os Mais Vendidos'],
        'descricoes'=>[
          'Asad, 9pm, Khamrah e Club de Nuit comparados por perfil, fixação e ocasião.',
          'Guia dos árabes mais desejados por perfil olfativo e ocasião. Direto ao ponto.',
        ]],
      ['nome'=>'AG3 - Beneficio','status'=>'Paused','kws'=>[
          ['perfume masculino que fixa o dia todo','Exact'],
          ['perfume masculino mais elogiado','Exact'],
          ['perfume masculino que dura muito','Exact'],
          ['perfume masculino forte e barato','Exact'],
          ['perfume masculino fixacao 8 horas','Phrase'],
        ],
        'titulos'=>['Perfume Que Fixa 8h+','O Mais Elogiado de 2026','Marcante e Barato',
                    'Dura o Dia Inteiro','Elogio Garantido'],
        'descricoes'=>[
          'Quer fixação de verdade e elogios? Veja os árabes masculinos que mais duram.',
          'Perfume marcante por uma fração do importado. Comparamos fixação e projeção.',
        ]],
      ['nome'=>'AG4 - Ocasiao','status'=>'Paused','kws'=>[
          ['perfume masculino para noite','Exact'],
          ['perfume masculino amadeirado','Exact'],
          ['perfume masculino doce','Exact'],
          ['melhor perfume arabe para o dia','Exact'],
        ],
        'titulos'=>['Árabe Certo Pra Cada Ocasião','Doce, Fresco ou Amadeirado','Pra Noite ou Pro Dia',
                    'Escolha Sem Errar'],
        'descricoes'=>[
          'Amadeirado pra noite, fresco pro dia: veja o árabe ideal pra cada momento.',
          'Doce, fresco ou amadeirado: escolha o perfume certo pra cada ocasião.',
        ]],
    ],
  ],
  [
    'nome'      => 'CC | Extratora Estofado | Search | BR',
    'lp'        => 'https://comocomprar.com.br/comprar-extratora-de-estofado/',
    'orcamento' => '25.00',
    'teto_cpc'  => '1.20',
    'utm'       => 'extratora',
    'path1'     => 'extratora',
    'path2'     => 'comparativo',
    'negativas' => ['aluguel','alugar','locacao','higienizacao profissional','empresa de limpeza','curso',
                    'como fazer caseiro','receita','manual','mercado livre','shopee','magalu','casas bahia',
                    'usado','conserto','peca','mangueira','motor','lavadora de alta pressao','aspirador'],
    'grupos' => [
      ['nome'=>'AG1 - Compra direta','status'=>'Enabled','kws'=>[
          ['comprar extratora de estofado','Exact'],
          ['extratora de estofado','Exact'],
          ['melhor extratora de estofado','Exact'],
          ['extratora para sofa','Exact'],
          ['extratora de estofado qual comprar','Exact'],
          ['extratora para limpar sofa','Phrase'],
        ],
        'titulos'=>['Melhor Extratora de Estofado','Qual Comprar Sem Errar','Portátil ou Barril?',
                    'Higienize Sofá em Casa','Se Paga em 1 Limpeza','Guia Direto ao Ponto',
                    'Sofá, Colchão e Carro'],
        'descricoes'=>[
          'Compare as melhores extratoras por sucção, reservatório e uso real. Veja qual comprar.',
          'Pare de pagar serviço: higienize sofá, colchão e carro em casa. Guia completo 2026.',
        ]],
      ['nome'=>'AG2 - Marca','status'=>'Enabled','kws'=>[
          ['wap spot cleaner','Exact'],
          ['extratora wap','Exact'],
          ['karcher puzzi','Exact'],
          ['extratora vonder','Exact'],
          ['wap home cleaner','Phrase'],
          ['extratora karcher','Exact'],
        ],
        'titulos'=>['WAP, Karcher e Vonder','Qual Extratora Comprar','Portátil x Barril',
                    'Comparativo Honesto'],
        'descricoes'=>[
          'WAP Spot Cleaner, Home Cleaner, Karcher e Vonder comparadas por uso real.',
          'Portátil para sofá e carro ou barril para uso pesado? Veja qual comprar.',
        ]],
      ['nome'=>'AG3 - Dor e uso','status'=>'Paused','kws'=>[
          ['como limpar sofa encardido','Exact'],
          ['higienizar sofa em casa','Exact'],
          ['extratora para carro','Exact'],
          ['maquina de limpar estofado','Exact'],
          ['extratora portatil','Exact'],
        ],
        'titulos'=>['Limpe o Sofá Encardido','A Água Sai Preta','Extratora Para Carro Também',
                    'Higieniza de Verdade'],
        'descricoes'=>[
          'A extratora tira a sujeira que o aspirador não tira. Veja qual comprar.',
          'Sofá encardido, colchão e banco de carro: veja a extratora certa pra cada uso.',
        ]],
    ],
  ],
  [
    'nome'      => 'CC | Depilador Luz Pulsada | Search | BR',
    'lp'        => 'https://comocomprar.com.br/melhor-depilador-de-luz-pulsada/',
    'orcamento' => '25.00',
    'teto_cpc'  => '1.20',
    'utm'       => 'depilador_ipl',
    'path1'     => 'depilador-ipl',
    'path2'     => 'comparativo',
    'negativas' => ['depilacao a laser','clinica','sessao','sessoes','preco sessao','quanto custa depilacao',
                    'perto de mim','agendar','funciona mesmo','dos','diy','faz mal','gravidez','mercado livre',
                    'shopee','magalu','natura','pelo loiro','pelo branco','pelo grisalho'],
    'grupos' => [
      ['nome'=>'AG1 - Compra de aparelho','status'=>'Enabled','kws'=>[
          ['depilador a laser caseiro','Exact'],
          ['melhor depilador a laser caseiro','Exact'],
          ['depilador de luz pulsada','Exact'],
          ['melhor depilador de luz pulsada','Exact'],
          ['depilador ipl','Exact'],
          ['depilador a laser portatil','Exact'],
          ['comprar depilador de luz pulsada','Phrase'],
        ],
        'titulos'=>['Melhor Depilador Luz Pulsada','IPL Para Casa 2026','Reduz Pelo Sem Dor',
                    'Qual Comprar Sem Errar','Mais Barato Que a Clínica','Funciona? Veja a Verdade',
                    'Guia Direto ao Ponto'],
        'descricoes'=>[
          'Compare os melhores IPL caseiros por conforto, resultado e preço. Veja qual comprar.',
          'Reduza os pelos em casa sem sair caro como a clínica. Comparativo honesto 2026.',
          'Sem dor, com resfriamento na ponteira. Veja o depilador certo pra sua pele e pelo.',
        ]],
      ['nome'=>'AG2 - Marca','status'=>'Enabled','kws'=>[
          ['ulike','Exact'],
          ['ulike air 10','Exact'],
          ['mlay','Exact'],
          ['mlay t4','Exact'],
          ['depilador ulike','Phrase'],
          ['depilador mlay','Phrase'],
        ],
        'titulos'=>['Ulike ou MLAY?','Depilador IPL Premium','Qual Marca Comprar',
                    'Comparativo Honesto','Conforto e Resultado'],
        'descricoes'=>[
          'Ulike, MLAY e mais comparados por conforto, resultado e preço. Veja o melhor.',
          'Compare os IPL de topo por conforto, potência e resultado. Veja qual vale o preço.',
        ]],
      ['nome'=>'AG3 - Depilador a laser','status'=>'Paused','kws'=>[
          ['depilador a laser','Phrase'],
          ['depilador a laser para casa','Exact'],
          ['depilador a laser funciona','Exact'],
        ],
        'titulos'=>['Depilador a Laser Para Casa','Reduza os Pelos em Casa','Vale a Pena? Veja',
                    'Guia de Compra 2026'],
        'descricoes'=>[
          'Depilador a laser caseiro funciona? Comparamos os melhores por pele e preço.',
          'Veja se o aparelho caseiro funciona no seu tipo de pele e pelo antes de comprar.',
        ]],
    ],
  ],
];

// ---------- validação ----------
$erros = [];
foreach ($CAMPANHAS as $c) {
  foreach (['path1','path2'] as $p) {
    if (mb_strlen($c[$p]) > 15) $erros[] = "{$c['nome']}: {$p} '{$c[$p]}' tem ".mb_strlen($c[$p])." chars (max 15)";
  }
  foreach ($c['grupos'] as $g) {
    $ctx = "{$c['nome']} / {$g['nome']}";
    if (count($g['titulos']) < 3)    $erros[] = "$ctx: RSA com ".count($g['titulos'])." títulos (mínimo 3)";
    if (count($g['descricoes']) < 2) $erros[] = "$ctx: RSA com ".count($g['descricoes'])." descrições (mínimo 2)";
    foreach ($g['titulos'] as $t) {
      $n = mb_strlen($t);
      if ($n > 30) $erros[] = "$ctx: título com $n chars (max 30) -> \"$t\"";
    }
    foreach ($g['descricoes'] as $d) {
      $n = mb_strlen($d);
      if ($n > 90) $erros[] = "$ctx: descrição com $n chars (max 90) -> \"$d\"";
    }
    foreach ($g['kws'] as $k) {
      if (trim($k[0]) === '') $erros[] = "$ctx: keyword vazia";
      if (!in_array($k[1], ['Exact','Phrase','Broad'], true)) $erros[] = "$ctx: correspondência inválida '{$k[1]}'";
    }
  }
}

// ---------- relatório ----------
echo "=== VALIDAÇÃO ===\n";
$maxT = 0; $maxD = 0; $nKw = 0; $nNeg = 0; $nAds = 0;
foreach ($CAMPANHAS as $c) {
  $nNeg += count($c['negativas']);
  foreach ($c['grupos'] as $g) {
    $nKw += count($g['kws']); $nAds++;
    foreach ($g['titulos'] as $t)    $maxT = max($maxT, mb_strlen($t));
    foreach ($g['descricoes'] as $d) $maxD = max($maxD, mb_strlen($d));
  }
}
echo "campanhas: ".count($CAMPANHAS)." | grupos: ".array_sum(array_map(fn($c)=>count($c['grupos']),$CAMPANHAS))
   ." | keywords: $nKw | negativas: $nNeg | RSAs: $nAds\n";
echo "maior título: {$maxT}/30 | maior descrição: {$maxD}/90\n";
if ($erros) {
  echo "\n✗ ".count($erros)." VIOLAÇÃO(ÕES) — nada foi gravado:\n";
  foreach ($erros as $e) echo "  - $e\n";
  exit(1);
}
echo "✓ sem violações de limite\n";

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

// 1. campanhas
$rows = [['Campaign','Campaign Type','Campaign Status','Campaign Daily Budget','Budget Type',
          'Bid Strategy Type','Maximum CPC Bid Limit','Search Network','Search Partners','Display Network',
          'Location','Language','Final URL Suffix']];
foreach ($CAMPANHAS as $c) {
  $rows[] = [$c['nome'],'Search','Paused',$c['orcamento'],'Daily','Maximize clicks',$c['teto_cpc'],
             'Enabled','Disabled','Disabled','Brazil','Portuguese',sprintf($UTM,$c['utm'])];
}
$put('01-campanhas.csv', $rows);

// 2. grupos
$rows = [['Campaign','Ad Group','Ad Group Status','Max CPC']];
foreach ($CAMPANHAS as $c) foreach ($c['grupos'] as $g) {
  $rows[] = [$c['nome'],$g['nome'],$g['status'],$c['teto_cpc']];
}
$put('02-grupos.csv', $rows);

// 3. keywords
$rows = [['Campaign','Ad Group','Keyword','Criterion Type','Final URL']];
foreach ($CAMPANHAS as $c) foreach ($c['grupos'] as $g) foreach ($g['kws'] as $k) {
  $rows[] = [$c['nome'],$g['nome'],$k[0],$k[1],$c['lp']];
}
$put('03-palavras-chave.csv', $rows);

// 4. negativas de campanha
$rows = [['Campaign','Keyword','Criterion Type']];
foreach ($CAMPANHAS as $c) foreach ($c['negativas'] as $n) {
  $rows[] = [$c['nome'],$n,'Campaign Negative Phrase'];
}
$put('04-negativas.csv', $rows);

// 5. RSAs
$maxTit = 15; $maxDesc = 4;
$head = ['Campaign','Ad Group','Ad Type'];
for ($i=1;$i<=$maxTit;$i++)  $head[] = "Headline $i";
for ($i=1;$i<=$maxDesc;$i++) $head[] = "Description $i";
$head[] = 'Final URL'; $head[] = 'Path 1'; $head[] = 'Path 2';
$rows = [$head];
foreach ($CAMPANHAS as $c) foreach ($c['grupos'] as $g) {
  $r = [$c['nome'],$g['nome'],'Responsive search ad'];
  for ($i=0;$i<$maxTit;$i++)  $r[] = $g['titulos'][$i]    ?? '';
  for ($i=0;$i<$maxDesc;$i++) $r[] = $g['descricoes'][$i] ?? '';
  $r[] = $c['lp']; $r[] = $c['path1']; $r[] = $c['path2'];
  $rows[] = $r;
}
$put('05-anuncios-rsa.csv', $rows);

echo "\nPronto. Revise os arquivos antes de importar no Google Ads Editor.\n";
