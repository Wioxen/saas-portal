<?php
/**
 * HealthWebhook — alertas reativos em eventos críticos do pipeline.
 *
 * Sem isso, cron falha 3h da madrugada e descobrimos só depois de revisar logs manualmente.
 * Com isso, recebemos notificação imediata em Discord/Telegram em:
 *   - cron falhou (script saiu com erro)
 *   - tick_filas ficou >30min sem processar (silently stuck)
 *   - quality_score médio caiu abaixo de 7.5 nas últimas 5 gerações
 *   - custo Sonnet diário acima de R$ N
 *   - GSC API falha consecutiva
 *
 * Suporta Discord (webhook URL) E Telegram (bot token + chat_id).
 * Config via .env: DISCORD_WEBHOOK_URL ou TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID.
 *
 * Falha silenciosa (rede off, webhook revogado): não bloqueia execução do cron.
 *
 * Uso:
 *   HealthWebhook::alertar('error', 'tick_filas falhou', ['site' => 'comocomprar']);
 *   HealthWebhook::alertar('warning', 'Quality score baixo', ['scores' => [6.2, 5.8]]);
 *   HealthWebhook::alertar('info', 'Backup OK', ['arquivos' => 7]);
 *
 * Throttle: mesma chave (subject+severity) só dispara 1x a cada 30min.
 */
class HealthWebhook
{
    private const THROTTLE_SEC = 1800; // 30 min — evita flood
    private const STATE_PATH   = '/../data/health_webhook_state.json';

    public const ERROR   = 'error';
    public const WARNING = 'warning';
    public const INFO    = 'info';

    /**
     * Dispara alerta. Retorna true se enviou (ou seria enviado), false se throttled/desativado.
     *
     * @param string $severity error|warning|info
     * @param string $subject  título curto (vira chave de throttle)
     * @param array  $contexto dados estruturados (vão pro corpo)
     */
    public static function alertar(string $severity, string $subject, array $contexto = []): bool
    {
        $cfg = self::cfg();
        if (!$cfg['enabled']) return false;
        if (!self::passouThrottle($subject, $severity)) return false;

        $msg = self::montarMensagem($severity, $subject, $contexto);
        $ok = false;

        if (!empty($cfg['discord_webhook_url'])) {
            $ok = self::enviarDiscord($cfg['discord_webhook_url'], $msg, $severity) || $ok;
        }
        if (!empty($cfg['telegram_bot_token']) && !empty($cfg['telegram_chat_id'])) {
            $ok = self::enviarTelegram($cfg['telegram_bot_token'], $cfg['telegram_chat_id'], $msg) || $ok;
        }
        return $ok;
    }

    /** Conveniência. */
    public static function erro(string $subject, array $ctx = []): bool   { return self::alertar(self::ERROR, $subject, $ctx); }
    public static function aviso(string $subject, array $ctx = []): bool  { return self::alertar(self::WARNING, $subject, $ctx); }
    public static function info(string $subject, array $ctx = []): bool   { return self::alertar(self::INFO, $subject, $ctx); }

    // ─────────── INTERNOS ───────────

    private static function cfg(): array
    {
        return [
            'enabled' => (bool)(getenv('HEALTH_WEBHOOK_ENABLED') ?: 0),
            'discord_webhook_url' => (string)(getenv('DISCORD_WEBHOOK_URL') ?: ''),
            'telegram_bot_token'  => (string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''),
            'telegram_chat_id'    => (string)(getenv('TELEGRAM_CHAT_ID') ?: ''),
        ];
    }

    private static function statePath(): string
    {
        return __DIR__ . self::STATE_PATH;
    }

    /** Throttle: só permite passar se última chamada com mesma chave foi há > THROTTLE_SEC. */
    private static function passouThrottle(string $subject, string $severity): bool
    {
        $key = $severity . ':' . sha1($subject);
        $path = self::statePath();
        $state = is_file($path) ? (json_decode((string)@file_get_contents($path), true) ?: []) : [];
        $ultimo = (int)($state[$key] ?? 0);
        if ($ultimo > 0 && (time() - $ultimo) < self::THROTTLE_SEC) return false;
        $state[$key] = time();
        // Limpa entradas antigas (>1d) pra arquivo não crescer infinito
        $cutoff = time() - 86400;
        $state = array_filter($state, fn($t) => (int)$t >= $cutoff);
        @file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
        return true;
    }

    private static function montarMensagem(string $severity, string $subject, array $contexto): string
    {
        $emoji = ['error' => '🔴', 'warning' => '🟡', 'info' => '🟢'][$severity] ?? '⚪';
        $msg = "{$emoji} **{$subject}**\n";
        $msg .= "_" . date('Y-m-d H:i:s') . "_\n";
        if (!empty($contexto)) {
            foreach ($contexto as $k => $v) {
                if (is_array($v) || is_object($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                $vStr = (string)$v;
                if (mb_strlen($vStr) > 200) $vStr = mb_substr($vStr, 0, 200) . '…';
                $msg .= "**{$k}:** {$vStr}\n";
            }
        }
        return $msg;
    }

    private static function enviarDiscord(string $webhookUrl, string $msg, string $severity): bool
    {
        $color = ['error' => 15158332, 'warning' => 16776960, 'info' => 5763719][$severity] ?? 8421504;
        $payload = [
            'embeds' => [[
                'description' => $msg,
                'color'       => $color,
                'timestamp'   => date('c'),
            ]],
        ];
        return self::post($webhookUrl, json_encode($payload, JSON_UNESCAPED_UNICODE), 'application/json');
    }

    private static function enviarTelegram(string $botToken, string $chatId, string $msg): bool
    {
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $payload = http_build_query([
            'chat_id' => $chatId,
            'text'    => $msg,
            'parse_mode' => 'Markdown',
        ]);
        return self::post($url, $payload, 'application/x-www-form-urlencoded');
    }

    private static function post(string $url, string $body, string $contentType): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: ' . $contentType],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        @curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }
}
