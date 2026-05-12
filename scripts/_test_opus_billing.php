<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Claude.php';

$cfg = require __DIR__ . '/../config.php';
echo "Testando claude-opus-4-7...\n";

$c = new Claude($cfg['anthropic_api_key'], 'claude-opus-4-7');
try {
    $r = $c->callPublic(
        [['role' => 'user', 'content' => 'Responda apenas: TESTE OPUS OK em 4 palavras']],
        'Voce e um modelo de teste.',
        50
    );
    echo "OK: " . ($r['content'][0]['text'] ?? 'vazio') . "\n";
} catch (Throwable $e) {
    echo "ERR: " . $e->getMessage() . "\n";
}
