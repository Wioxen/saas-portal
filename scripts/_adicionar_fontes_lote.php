<?php
/**
 * [TEMPORÁRIO — pode apagar depois] Adiciona em lote 30 fontes novas validadas
 * em data/fontes_pingo.json. Idempotente: pula se já existe (por url_rss).
 *
 * Origem: auditoria 2026-04-27 — validação de 30 portais BR sugeridos pelo user.
 * 23 OK direto + 6 WARN com threshold alto + 1 confirmado depois (Estado de Minas).
 * Skipados: Tua Saúde (YMYL), Estudo de Minas (domínio não responde).
 */

$file = __DIR__ . '/../data/fontes_pingo.json';
$j = json_decode(file_get_contents($file), true);

$novas = [
    // ─── RSS DIRETO VALIDADO (5 títulos reais inspecionados) ───────────────
    ['nome' => 'Diário do Comércio (MG)', 'url_rss' => 'https://diariodocomercio.com.br/feed/', 'tipo' => 'rss', 'cluster_hint' => 'negocios_financas', 'site_target' => 'vagasebeneficios', 'intervalo_min' => 30, 'auto_aprovar_score_min' => 7.5, 'notas' => 'Forte em economia/política federal e MG; bom para gov/INSS.'],
    ['nome' => 'Folha de Boa Vista', 'url_rss' => 'https://folhabv.com.br/feed/', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'auto', 'intervalo_min' => 60, 'auto_aprovar_score_min' => 7.0, 'notas' => 'Regional RR; mistura entretenimento + serviço.'],
    ['nome' => 'Olhar Digital', 'url_rss' => 'https://olhardigital.com.br/feed/', 'tipo' => 'rss', 'cluster_hint' => 'tecnologia', 'site_target' => 'comocomprar', 'intervalo_min' => 30, 'auto_aprovar_score_min' => 7.5, 'notas' => 'Tech consumer-friendly; ângulo de produto/curiosidade.'],
    ['nome' => 'G1 Economia', 'url_rss' => 'https://g1.globo.com/rss/g1/economia/', 'tipo' => 'rss', 'cluster_hint' => 'negocios_financas', 'site_target' => 'vagasebeneficios', 'intervalo_min' => 15, 'auto_aprovar_score_min' => 8.0, 'notas' => 'FGTS/Desenrola/INSS aparecem aqui — alvo principal vagasebeneficios.'],
    ['nome' => 'G1 Educação', 'url_rss' => 'https://g1.globo.com/rss/g1/educacao/', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'cursosenac', 'intervalo_min' => 30, 'auto_aprovar_score_min' => 8.0, 'notas' => 'ENEM, MEC, EAD, vestibulares.'],
    ['nome' => 'Portal 6 (Anápolis/GO)', 'url_rss' => 'https://www.portal6.com.br/feed/', 'tipo' => 'rss', 'cluster_hint' => 'lifestyle_consumo', 'site_target' => 'ondecompraragora', 'intervalo_min' => 60, 'auto_aprovar_score_min' => 8.0, 'notas' => 'WARN: clickbait/lifestyle local; threshold alto pra filtrar.'],
    ['nome' => 'Exame', 'url_rss' => 'https://exame.com/feed/', 'tipo' => 'rss', 'cluster_hint' => 'negocios_financas', 'site_target' => 'vagasebeneficios', 'intervalo_min' => 15, 'auto_aprovar_score_min' => 8.0, 'notas' => 'Alta autoridade economia/carreira/concursos.'],
    ['nome' => 'Metrópoles', 'url_rss' => 'https://www.metropoles.com/feed', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'auto', 'intervalo_min' => 15, 'auto_aprovar_score_min' => 8.0, 'notas' => 'Volume altíssimo (esporte+política+geral). Threshold alto.'],
    ['nome' => 'CNN Brasil', 'url_rss' => 'https://admin.cnnbrasil.com.br/feed/', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'auto', 'intervalo_min' => 15, 'auto_aprovar_score_min' => 8.0, 'notas' => 'URL canônica é admin.cnnbrasil.com.br (não www).'],
    ['nome' => 'Conecta Professores', 'url_rss' => 'https://conectaprofessores.com/feed/', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'cursosenac', 'intervalo_min' => 30, 'auto_aprovar_score_min' => 7.0, 'notas' => 'MATCH PERFEITO cursosenac (cursos EAD gratuitos, MEC, IF).'],
    ['nome' => 'Xataka Brasil', 'url_rss' => 'https://www.xataka.com.br/feedburner.xml', 'tipo' => 'rss', 'cluster_hint' => 'tecnologia', 'site_target' => 'comocomprar', 'intervalo_min' => 30, 'auto_aprovar_score_min' => 7.5, 'notas' => 'Tech/ciência com ângulo de curiosidade.'],
    ['nome' => 'CPG Click Petróleo e Gás', 'url_rss' => 'https://clickpetroleoegas.com.br/feed/', 'tipo' => 'rss', 'cluster_hint' => 'curiosidades_geral', 'site_target' => 'auto', 'intervalo_min' => 60, 'auto_aprovar_score_min' => 8.0, 'notas' => 'WARN: clickbait. Apesar do nome, é portal de curiosidades + gov.'],
    ['nome' => 'Estado de Minas', 'url_rss' => 'https://www.em.com.br/feed/', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'auto', 'intervalo_min' => 30, 'auto_aprovar_score_min' => 7.5, 'notas' => 'Regional MG forte; cobre PM, gov, esporte. Validado 2026-04-27.'],
    ['nome' => 'Canaltech', 'url_rss' => 'https://canaltech.com.br/rss/', 'tipo' => 'rss', 'cluster_hint' => 'tecnologia', 'site_target' => 'comocomprar', 'intervalo_min' => 30, 'auto_aprovar_score_min' => 7.5, 'notas' => 'Tech + ângulo preço/produto = match comocomprar.'],
    ['nome' => 'Jornal Correio (BA)', 'url_rss' => 'https://www.correio24horas.com.br/rss', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'auto', 'intervalo_min' => 60, 'auto_aprovar_score_min' => 7.0, 'notas' => 'Regional BA; mistura horoscopo+esporte+entretenimento.'],
    ['nome' => 'Folha S.Paulo - Mercado', 'url_rss' => 'https://feeds.folha.uol.com.br/mercado/rss091.xml', 'tipo' => 'rss', 'cluster_hint' => 'negocios_financas', 'site_target' => 'vagasebeneficios', 'intervalo_min' => 15, 'auto_aprovar_score_min' => 8.0, 'notas' => 'Paywall pesado — usar pra captar gancho. RSS direto deve funcionar em prod.'],
    ['nome' => 'Catraca Livre', 'url_rss' => 'https://catracalivre.com.br/feed/', 'tipo' => 'rss', 'cluster_hint' => 'lifestyle_consumo', 'site_target' => 'ondecompraragora', 'intervalo_min' => 60, 'auto_aprovar_score_min' => 8.0, 'notas' => 'WARN: lifestyle+truques, títulos clickbait. Threshold alto.'],
    ['nome' => 'Hora Brasil', 'url_rss' => 'https://horabrasil.com.br/feed/', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'cursosenac', 'intervalo_min' => 30, 'auto_aprovar_score_min' => 7.5, 'notas' => 'EXCELENTE para cursosenac (cursos+vagas EAD+IF).'],
    ['nome' => 'Baguete', 'url_rss' => 'https://baguete.com.br/rss/noticias/feed', 'tipo' => 'rss', 'cluster_hint' => 'tecnologia', 'site_target' => 'vagasebeneficios', 'intervalo_min' => 60, 'auto_aprovar_score_min' => 7.5, 'notas' => 'B2B tech+layoffs+contratações TI. Gancho oportunidade.'],
    ['nome' => 'Alô Alô Bahia', 'url_rss' => 'https://aloalobahia.com/feed/', 'tipo' => 'rss', 'cluster_hint' => 'entretenimento', 'site_target' => 'auto', 'intervalo_min' => 60, 'auto_aprovar_score_min' => 7.0, 'notas' => 'Celebridades+social BA. Domínio SEM www (cert TLS).'],
    ['nome' => 'Revista Oeste', 'url_rss' => 'https://revistaoeste.com/feed/', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'auto', 'intervalo_min' => 30, 'auto_aprovar_score_min' => 8.5, 'notas' => 'WARN: linha editorial polarizada (direita). Threshold alto pra evitar viés.'],

    // ─── VIA GOOGLE NEWS FALLBACK (paywall ou bloqueio CDN) ────────────────
    ['nome' => 'Terra (via Google News)', 'url_rss' => 'https://news.google.com/rss/search?q=site:terra.com.br&hl=pt-BR&gl=BR&ceid=BR:pt-419', 'tipo' => 'rss', 'cluster_hint' => 'entretenimento', 'site_target' => 'auto', 'intervalo_min' => 15, 'auto_aprovar_score_min' => 8.0, 'notas' => 'Terra não expõe RSS público; Google News fallback.'],
    ['nome' => 'Veja (via Google News)', 'url_rss' => 'https://news.google.com/rss/search?q=site:veja.abril.com.br&hl=pt-BR&gl=BR&ceid=BR:pt-419', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'auto', 'intervalo_min' => 15, 'auto_aprovar_score_min' => 8.0, 'notas' => 'Veja sem /feed/ aberto; Google News fallback.'],
    ['nome' => 'UOL Esporte', 'url_rss' => 'https://news.google.com/rss/search?q=site:uol.com.br/esporte&hl=pt-BR&gl=BR&ceid=BR:pt-419', 'tipo' => 'rss', 'cluster_hint' => 'esportes', 'site_target' => 'leaodabarra', 'intervalo_min' => 15, 'auto_aprovar_score_min' => 8.0, 'notas' => 'MATCH ALVO leaodabarra. Tentar rss.bol.uol.com.br/noticias/esporte/rss.xml em prod.'],
    ['nome' => 'Valor Econômico (via Google News)', 'url_rss' => 'https://news.google.com/rss/search?q=site:valor.globo.com&hl=pt-BR&gl=BR&ceid=BR:pt-419', 'tipo' => 'rss', 'cluster_hint' => 'negocios_financas', 'site_target' => 'vagasebeneficios', 'intervalo_min' => 15, 'auto_aprovar_score_min' => 8.0, 'notas' => 'Paywall pesado — só pra captar gancho.'],
    ['nome' => 'Correio Braziliense (via Google News)', 'url_rss' => 'https://news.google.com/rss/search?q=site:correiobraziliense.com.br&hl=pt-BR&gl=BR&ceid=BR:pt-419', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'vagasebeneficios', 'intervalo_min' => 30, 'auto_aprovar_score_min' => 8.0, 'notas' => 'Forte em política federal/DF (Esplanada, INSS).'],
    ['nome' => 'Rádio Itatiaia (MG, via Google News)', 'url_rss' => 'https://news.google.com/rss/search?q=site:itatiaia.com.br&hl=pt-BR&gl=BR&ceid=BR:pt-419', 'tipo' => 'rss', 'cluster_hint' => 'esportes', 'site_target' => 'leaodabarra', 'intervalo_min' => 30, 'auto_aprovar_score_min' => 7.5, 'notas' => 'Atlético-MG/Cruzeiro/América-MG; complemento regional leaodabarra.'],
    ['nome' => 'Estadão (via Google News)', 'url_rss' => 'https://news.google.com/rss/search?q=site:estadao.com.br&hl=pt-BR&gl=BR&ceid=BR:pt-419', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'vagasebeneficios', 'intervalo_min' => 15, 'auto_aprovar_score_min' => 8.0, 'notas' => 'Paywall maioria — captar gancho. Em prod testar /rss/economia.xml.'],
    ['nome' => 'O Globo (via Google News)', 'url_rss' => 'https://news.google.com/rss/search?q=site:oglobo.globo.com&hl=pt-BR&gl=BR&ceid=BR:pt-419', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'vagasebeneficios', 'intervalo_min' => 15, 'auto_aprovar_score_min' => 8.0, 'notas' => 'Paywall em parte. Em prod testar /rss/oglobo direto.'],
    ['nome' => 'Metrô 1 (BA, via Google News)', 'url_rss' => 'https://news.google.com/rss/search?q=site:metro1.com.br&hl=pt-BR&gl=BR&ceid=BR:pt-419', 'tipo' => 'rss', 'cluster_hint' => 'noticias_info_critica', 'site_target' => 'auto', 'intervalo_min' => 60, 'auto_aprovar_score_min' => 8.0, 'notas' => 'WARN: regional BA; sem RSS direto.'],
];

$existentes = array_column($j['fontes'], 'url_rss');
$next = (int)$j['next_id'];
$adicionadas = 0;
$puladas = 0;

foreach ($novas as $f) {
    if (in_array($f['url_rss'], $existentes, true)) {
        echo "SKIP (já existe): {$f['nome']}\n";
        $puladas++;
        continue;
    }
    $entry = array_merge(['id' => $next, 'ativo' => true], $f, [
        'max_itens_por_fetch' => 30,
    ]);
    $j['fontes'][] = $entry;
    echo sprintf("ADD #%d  %-40s  → %s\n", $next, $f['nome'], $f['site_target']);
    $next++;
    $adicionadas++;
}

$j['next_id'] = $next;

file_put_contents($file, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "\n--- RESUMO ---\n";
echo "Adicionadas: {$adicionadas}\n";
echo "Já existiam: {$puladas}\n";
echo "Total fontes agora: " . count($j['fontes']) . "\n";
echo "Próximo id: {$next}\n";
