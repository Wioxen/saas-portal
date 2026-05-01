<?php
/**
 * [TEMPORÁRIO] Busca em cursosenac trends 'novo' coerentes com educação.
 */
require_once __DIR__ . '/../lib/DiscoverDb.php';
$db = new DiscoverDb();

$site = 'cursosenac';
$todos = $db->all(['site' => $site, 'status' => 'novo']);

$kwEdu = [
    'enem','sisu','prouni','fies','vestibular','curso','cursos','escola',
    'senac','senai','sebrae','faculdade','universidade','graduação',
    'aula','aulas','professor','aluno','estudante','estudo','estudar',
    'matrícula','inscrição','inscrições','edital','concurso','vagas',
    'bolsa','bolsas','gratuito','grátis','online','ead','presencial',
    'qualificação','profissionalizante','certificado','diploma',
    'mec','inep','enade','vestibulando','prova','redação',
    'aprendiz','jovem aprendiz','estágio','treinamento','capacitação',
    'idioma','inglês','espanhol','informática','administração','contabilidade',
    'cabeleireiro','barbeiro','manicure','estética','culinária','padeiro','design',
];

function scoreEdu(array $r, array $kw): int {
    $termo = mb_strtolower((string)($r['termo'] ?? ''), 'UTF-8');
    $cat   = mb_strtolower((string)($r['categoria'] ?? ''), 'UTF-8');
    $s = 0;
    foreach ($kw as $k) {
        if (str_contains($termo, $k)) $s += 5;
        if (str_contains($cat, $k))   $s += 1;
    }
    if (str_contains($cat, 'empregos') || str_contains($cat, 'educação')) $s += 1;
    return $s;
}

foreach ($todos as &$r) $r['_edu'] = scoreEdu($r, $kwEdu);
unset($r);

usort($todos, function($a, $b) {
    $diff = $b['_edu'] <=> $a['_edu'];
    return $diff !== 0 ? $diff : (($b['score_discover'] ?? 0) <=> ($a['score_discover'] ?? 0));
});

$top = array_slice($todos, 0, 12);

echo "Top 12 trends 'novo' em cursosenac mais coerentes com educação:\n";
echo "─────────────────────────────────────────────────────────────────────────\n";
echo sprintf("%-6s %-6s %-5s %-50s %-20s\n", "ID", "Score", "EDU", "Termo", "Categoria");
echo "─────────────────────────────────────────────────────────────────────────\n";
foreach ($top as $t) {
    printf("#%-5d %-6.1f %-5d %-50s %-20s\n",
        $t['id'],
        (float)($t['score_discover'] ?? 0),
        $t['_edu'],
        mb_substr($t['termo'] ?? '?', 0, 48),
        mb_substr($t['categoria'] ?? '-', 0, 18)
    );
}
echo "─────────────────────────────────────────────────────────────────────────\n";
echo "EDU = pontuação de coerência com educação (palavras-chave + categoria)\n";
