<?php
declare(strict_types=1);

/**
 * LengthGate — valida se o artigo está no sweet spot de palavras pra Google authority.
 *
 * Faixa default: 1200-2000 palavras. Fora disso:
 *   - <800 palavras → fail (curto demais, sinal de pobreza)
 *   - 800-1199 → warn (aceitável mas frágil)
 *   - 1200-2000 → ok (sweet spot Discover/SEO)
 *   - 2001-2800 → warn (longo, risco bounce mobile)
 *   - >2800 → fail (longo demais, leitor mobile abandona)
 *
 * Conta APENAS texto editorial (strip tags, ignora script/style/code/avisos).
 *
 * Uso:
 *   $r = LengthGate::avaliar($html);
 *   if ($r['severity'] === 'fail') { ... }
 */
class LengthGate
{
    public const MIN_OK = 1200;
    public const MAX_OK = 2000;
    public const MIN_WARN = 800;
    public const MAX_WARN = 2800;

    /**
     * @return array {ok, severity, palavras, motivo}
     */
    public static function avaliar(string $html): array
    {
        // Remove script/style/code (não conta como conteúdo editorial)
        $clean = preg_replace('~<(script|style|code)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;
        // Remove avisos do gate (RASCUNHO BLOQUEADO / REVISÃO MANUAL) — não contam como conteúdo editorial.
        // Match por texto-marcador (mais robusto que hex color que conflitava com delimitador #).
        $clean = preg_replace('~<div\b[^>]*>(?:(?!</div>).)*?(?:RASCUNHO BLOQUEADO|REVISÃO MANUAL OBRIGATÓRIA)(?:(?!</div>).)*?</div>~is', '', $clean) ?? $clean;
        $text = strip_tags(html_entity_decode($clean, ENT_QUOTES|ENT_HTML5, 'UTF-8'));
        $palavras = str_word_count($text, 0, 'áéíóúâêôàãõçÁÉÍÓÚÂÊÔÀÃÕÇ-');

        $severity = 'ok';
        $motivo = '';
        if ($palavras < self::MIN_WARN) {
            $severity = 'fail';
            $motivo = "muito curto ({$palavras} palavras < " . self::MIN_WARN . ")";
        } elseif ($palavras < self::MIN_OK) {
            $severity = 'warn';
            $motivo = "abaixo do ideal ({$palavras} palavras < " . self::MIN_OK . ")";
        } elseif ($palavras > self::MAX_WARN) {
            $severity = 'fail';
            $motivo = "muito longo ({$palavras} palavras > " . self::MAX_WARN . ")";
        } elseif ($palavras > self::MAX_OK) {
            $severity = 'warn';
            $motivo = "acima do ideal ({$palavras} palavras > " . self::MAX_OK . ")";
        }

        return [
            'ok' => $severity === 'ok',
            'severity' => $severity,
            'palavras' => $palavras,
            'motivo' => $motivo,
        ];
    }

    public static function reportToLogLine(array $r): string
    {
        return "LengthGate: severity={$r['severity']} palavras={$r['palavras']}" . ($r['motivo'] ? " ({$r['motivo']})" : '');
    }
}
