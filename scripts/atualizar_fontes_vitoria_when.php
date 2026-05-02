<?php
/**
 * scripts/atualizar_fontes_vitoria_when.php
 *
 * Atualiza as 6 fontes Vitória existentes (IDs 55-60 ou similares) pra incluir `when:7d`
 * na query Google News. Sem isso, Google News retorna notícias antigas (2018-2025) misturadas
 * com recentes — caso #742 leaodabarra (2026-05-02): post foi gerado a partir de notícia
 * de 2025 sobre setor visitante.
 *
 * Idempotente: pula fontes que já têm `when%3A7d` na URL.
 *
 * Uso (servidor EasyPanel):
 *   php /app/scripts/atualizar_fontes_vitoria_when.php
 */

$file = __DIR__ . '/../data/fontes_pingo.json';
if (!is_file($file)) { fwrite(STDERR, "fontes_pingo.json não encontrado\n"); exit(1); }

$j = json_decode(file_get_contents($file), true);
if (!is_array($j) || empty($j['fontes'])) {
    fwrite(STDERR, "JSON inválido ou sem array 'fontes'\n");
    exit(2);
}

// Mapa: domínio (substring) → URL nova com when:7d
$mapa = [
    'site:bahianoticias.com.br' =>
        'https://news.google.com/rss/search?q=site:bahianoticias.com.br+%22Vit%C3%B3ria%22+leao+when%3A7d&hl=pt-BR&gl=BR&ceid=BR:pt-419',
    'site:bnews.com.br' =>
        'https://news.google.com/rss/search?q=site:bnews.com.br+%22Vit%C3%B3ria%22+leao+when%3A7d&hl=pt-BR&gl=BR&ceid=BR:pt-419',
    'site:arenarubronegra.com' =>
        'https://news.google.com/rss/search?q=site:arenarubronegra.com+when%3A7d&hl=pt-BR&gl=BR&ceid=BR:pt-419',
    'site:meuvitoria.com.br' =>
        'https://news.google.com/rss/search?q=site:meuvitoria.com.br+when%3A7d&hl=pt-BR&gl=BR&ceid=BR:pt-419',
    'site:correio24horas.com.br' =>
        'https://news.google.com/rss/search?q=site:correio24horas.com.br+%22Vit%C3%B3ria%22+rubro-negro+when%3A7d&hl=pt-BR&gl=BR&ceid=BR:pt-419',
    'site:terra.com.br+%22Esporte+Clube+Vit%C3%B3ria%22' =>
        'https://news.google.com/rss/search?q=site:terra.com.br+%22Esporte+Clube+Vit%C3%B3ria%22+when%3A7d&hl=pt-BR&gl=BR&ceid=BR:pt-419',
];

$atualizadas = 0;
$puladas = 0;

foreach ($j['fontes'] as &$f) {
    $url = (string)($f['url_rss'] ?? '');
    if ($url === '') continue;

    foreach ($mapa as $marker => $novaUrl) {
        if (str_contains($url, $marker) && !str_contains($url, 'when%3A7d')) {
            // Encontrei fonte que precisa update
            $idAntigo = (int)($f['id'] ?? 0);
            $nomeAntigo = (string)($f['nome'] ?? '?');
            echo "UPDATE #{$idAntigo} ({$nomeAntigo}):\n  ANTES:  {$url}\n  DEPOIS: {$novaUrl}\n\n";
            $f['url_rss'] = $novaUrl;
            $f['notas'] = ($f['notas'] ?? '') . ' | URL atualizada 2026-05-02 com when:7d (force recência).';
            $atualizadas++;
            break;
        }
        if (str_contains($url, $marker) && str_contains($url, 'when%3A7d')) {
            $puladas++;
        }
    }
}
unset($f);

if ($atualizadas > 0) {
    file_put_contents($file, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

echo "--- RESUMO ---\n";
echo "Atualizadas: {$atualizadas}\n";
echo "Já tinham when:7d: {$puladas}\n";
echo "Total fontes no JSON: " . count($j['fontes']) . "\n";

if ($atualizadas > 0) {
    echo "\n✓ Salvo. Próximo pingo vai usar URLs novas com filtro de 7 dias na origem.\n";
} else {
    echo "\nNada a fazer.\n";
}
