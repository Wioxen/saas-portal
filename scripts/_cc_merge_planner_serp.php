<?php
declare(strict_types=1);
/** cc — cruza o CSV do Planejador de Palavras-chave (volume, YoY, concorrência, lance de topo)
 *  com a varredura de SERP (`_cc_serp_oportunidade.json`) e com a comissão Amazon da categoria.
 *
 *  php scripts/_cc_merge_planner_serp.php --csv="C:/Users/Ivan/Downloads/Keyword Stats ....csv"
 *
 *  O CSV do Planejador vem em UTF-16 e separado por TAB, com 2 linhas de título antes do cabeçalho
 *  e 2 linhas de totais depois — por isso o parse é manual, não fgetcsv direto.
 *
 *  Saída: tabela ordenada por RECEITA POTENCIAL = volume × comissão, cruzada com a dificuldade
 *  real da 1ª página. Volume alto com comissão baixa cai sozinho no ranking, que é o ponto.
 */
date_default_timezone_set('America/Sao_Paulo');
$ROOT = dirname(__DIR__);

$op = getopt('', ['csv:']);
$CSV = $op['csv'] ?? '';
if ($CSV === '' || !is_file($CSV)) { fwrite(STDERR, "uso: --csv=\"caminho/Keyword Stats....csv\"\n"); exit(1); }

$serpPath = $ROOT.'/data/_cc_serp_oportunidade.json';
$SERP = is_file($serpPath) ? (json_decode((string)file_get_contents($serpPath), true) ?: []) : [];

// UTF-16 -> UTF-8
$raw = (string)file_get_contents($CSV);
$txt = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
$txt = str_replace("\xEF\xBB\xBF", '', $txt);
$linhas = preg_split('/\r\n|\n|\r/', $txt);

// acha a linha de cabeçalho (a que começa com "Keyword\t")
$hi = -1;
foreach ($linhas as $i => $l) { if (str_starts_with($l, "Keyword\t")) { $hi = $i; break; } }
if ($hi < 0) { fwrite(STDERR, "cabeçalho não encontrado no CSV\n"); exit(1); }
$cols = explode("\t", $linhas[$hi]);
$idx = array_flip($cols);

function col(array $r, array $idx, string $nome) { return isset($idx[$nome]) ? ($r[$idx[$nome]] ?? '') : ''; }

/** ⚠️ O CSV MISTURA DOIS FORMATOS NUMÉRICOS na mesma linha:
 *   · volume vem em formato americano e SEM aspas -> "12100.0" (o ponto é DECIMAL);
 *   · CPC vem em formato brasileiro e ENTRE ASPAS -> "\"0,16\"" (a vírgula é decimal).
 *  Tratar os dois igual erra feio: se o ponto do volume for lido como separador de milhar,
 *  12100.0 vira 121000 e todo o estudo sai 10x inflado. Por isso duas funções separadas. */
function numVol(string $v): float { return (float)trim($v, " \t\"'"); }
function numBrl(string $v): float { return (float)str_replace(',', '.', trim($v, " \t\"'")); }

$dados = [];
for ($i = $hi+1; $i < count($linhas); $i++) {
  $l = $linhas[$i];
  if (trim($l) === '') continue;
  $r = explode("\t", $l);
  $kw = trim($r[0] ?? '');
  if ($kw === '') continue;                       // linhas de total (Todos/Brasil) vêm sem keyword
  $vol  = numVol(col($r,$idx,'Avg. monthly searches'));
  $yoy  = trim(col($r,$idx,'YoY change'), " \t\"'");
  $tri  = trim(col($r,$idx,'Three month change'), " \t\"'");
  $comp = trim(col($r,$idx,'Competition'), " \t\"'");
  $low  = numBrl(col($r,$idx,'Top of page bid (low range)'));
  $high = numBrl(col($r,$idx,'Top of page bid (high range)'));
  $dados[$kw] = ['vol'=>$vol,'yoy'=>$yoy,'tri'=>$tri,'comp'=>$comp,'cpc_low'=>$low,'cpc_high'=>$high];
}

/** casa a keyword do Planejador (sem acento) com a do estudo de SERP (com acento) */
function norm(string $s): string {
  $s = mb_strtolower(trim($s));
  $de = ['á','à','ã','â','é','ê','í','ó','ô','õ','ú','ç'];
  $pa = ['a','a','a','a','e','e','i','o','o','o','u','c'];
  return str_replace($de, $pa, $s);
}
$serpNorm = [];
foreach ($SERP as $k => $v) $serpNorm[norm($k)] = $v;

$linhasOut = [];
foreach ($dados as $kw => $d) {
  $s = $serpNorm[norm($kw)] ?? null;
  $com = $s['comissao_pct'] ?? 0;
  $nicho = $s ? ($s['top10']['nicho'] ?? 0) : 0;
  $midia = $s ? ($s['top10']['midia'] ?? 0) : 0;
  $mkt   = $s ? ($s['top10']['marketplace'] ?? 0) : 0;
  // receita potencial relativa: volume × comissão. Não é R$, é um índice de comparação.
  $score = $d['vol'] * ($com / 100);
  $linhasOut[] = [
    'kw'=>$kw, 'vol'=>$d['vol'], 'yoy'=>$d['yoy'], 'tri'=>$d['tri'], 'comp'=>$d['comp'],
    'cpc_low'=>$d['cpc_low'], 'cpc_high'=>$d['cpc_high'],
    'cpc'=>number_format($d['cpc_low'],2,',','').'-'.number_format($d['cpc_high'],2,',',''), 'com'=>$com,
    'nicho'=>$nicho, 'midia'=>$midia, 'mkt'=>$mkt, 'score'=>$score,
  ];
}
usort($linhasOut, fn($a,$b) => $b['score'] <=> $a['score']);

printf("%-50s %8s %8s %12s %-7s %5s %5s %s\n",
  'TERMO','VOL/MÊS','YoY','CPC topo R$','CONC.','COM%','ÍNDICE','SERP(n/m/mk)');
echo str_repeat('-', 122)."\n";
foreach ($linhasOut as $r) {
  printf("%-50s %8s %8s %12s %-7s %4d%% %5d  %d/%d/%d\n",
    mb_substr($r['kw'],0,50),
    number_format($r['vol'],0,',','.'),
    ($r['yoy']!==''? $r['yoy'] : '-'),
    $r['cpc'],
    mb_substr($r['comp'],0,7),
    $r['com'],
    (int)round($r['score']),
    $r['nicho'], $r['midia'], $r['mkt']);
}

$out = $ROOT.'/data/_cc_planner_serp_merge.json';
file_put_contents($out, json_encode($linhasOut, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "\n".count($linhasOut)." termos cruzados -> ".basename($out)."\n";
echo "ÍNDICE = volume × comissão (comparativo, não R\$). SERP = nicho/mídia/marketplace no top 10.\n";
