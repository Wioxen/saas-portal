<?php
/**
 * tests/pingo.php
 *
 * Testes do DiscoverPingo — parser RSS + dedup + normalização.
 * Não faz HTTP real (usa fixtures inline).
 */

require_once __DIR__ . '/../lib/PingoRssParser.php';

$casos = 0;
$passa = 0;
$falhas = [];

function ok(string $desc, bool $condicao): void {
    global $casos, $passa, $falhas;
    $casos++;
    if ($condicao) { $passa++; return; }
    $falhas[] = $desc;
}

// ── Parser RSS 2.0 ───────────────────────────────────────────────
$rss2 = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Teste</title>
    <item>
      <title>Primeiro item</title>
      <link>https://example.com/a</link>
      <guid>guid-a</guid>
      <description>Texto descritivo do item 1.</description>
      <pubDate>Mon, 24 Apr 2026 10:30:00 -0300</pubDate>
      <category>politica</category>
      <category>economia</category>
    </item>
    <item>
      <title>Segundo item</title>
      <link>https://example.com/b</link>
      <guid>guid-b</guid>
      <description>Descrição 2.</description>
    </item>
  </channel>
</rss>
XML;

$items = PingoRssParser::parse($rss2, 10);
ok('RSS 2.0 parse 2 items', count($items) === 2);
ok('RSS 2.0 primeiro title correto', ($items[0]['title'] ?? '') === 'Primeiro item');
ok('RSS 2.0 primeiro link correto', ($items[0]['link'] ?? '') === 'https://example.com/a');
ok('RSS 2.0 pubDate parseada', ($items[0]['pub_ts'] ?? 0) > 0);
ok('RSS 2.0 categorias extraídas', count($items[0]['categorias'] ?? []) === 2);
ok('RSS 2.0 segundo item sem pubDate → ts 0', ($items[1]['pub_ts'] ?? -1) === 0);

// ── Parser Atom ──────────────────────────────────────────────────
$atom = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Teste Atom</title>
  <entry>
    <title>Entry 1</title>
    <link href="https://example.com/atom1" rel="alternate"/>
    <id>urn:uuid:123</id>
    <summary>Resumo 1</summary>
    <updated>2026-04-24T10:30:00Z</updated>
    <category term="tech"/>
  </entry>
</feed>
XML;

$itemsAtom = PingoRssParser::parse($atom, 10);
ok('Atom parse 1 entry', count($itemsAtom) === 1);
ok('Atom link extraído corretamente', ($itemsAtom[0]['link'] ?? '') === 'https://example.com/atom1');
ok('Atom category via term attribute', in_array('tech', $itemsAtom[0]['categorias'] ?? [], true));

// ── Parser tolerante ao XML inválido ─────────────────────────────
$itemsInvalid = PingoRssParser::parse('<not xml at all', 10);
ok('XML inválido retorna []', is_array($itemsInvalid) && empty($itemsInvalid));

$itemsEmpty = PingoRssParser::parse('', 10);
ok('string vazia retorna []', $itemsEmpty === []);

// ── maxItems respeitado ──────────────────────────────────────────
$itemsLimit = PingoRssParser::parse($rss2, 1);
ok('maxItems=1 limita a 1 item', count($itemsLimit) === 1);

// ── Encoding Latin-1 → UTF-8 (forçar conversão) ─────────────────
// Constrói XML diretamente em Windows-1252 com bytes brutos pro "Eleição"
$latin1 = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\n"
        . "<rss version=\"2.0\"><channel><item><title>Elei" . chr(0xe7) . chr(0xe3) . "o</title><link>http://x</link></item></channel></rss>";
$itemsLatin = PingoRssParser::parse($latin1, 5);
ok('Latin-1 → UTF-8 título preserva acentos', isset($itemsLatin[0]['title']) && mb_strpos($itemsLatin[0]['title'], 'Eleição') !== false);

// ── HTML entities decoded ────────────────────────────────────────
$rssHtml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item><title>&amp;aacute;gua &amp; barro</title><link>http://x</link></item>
    <item><title>R$ 1.500 &gt; R$ 1.000</title><link>http://y</link></item>
  </channel>
</rss>
XML;
$itemsHtml = PingoRssParser::parse($rssHtml, 5);
ok('Entity &gt; decoded', isset($itemsHtml[1]['title']) && str_contains($itemsHtml[1]['title'], '>'));

echo "═══════════════════════════════════════════════════════════════\n";
echo "  tests/pingo.php — {$casos} casos\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

if (!empty($falhas)) {
    echo "─── FALHAS ─────────────────────────────────────────────────────\n";
    foreach ($falhas as $f) echo "  ✗ {$f}\n";
    echo "\n";
}

printf("  Passaram: %d / %d (%.1f%%)\n", $passa, $casos, ($passa / $casos) * 100);

if ($passa === $casos) {
    echo "  ✓ Todos os casos passaram.\n";
    exit(0);
}
exit(1);
