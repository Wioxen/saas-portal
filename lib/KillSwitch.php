<?php
/**
 * KillSwitch — pause global do pipeline via .env. Defesa de emergência.
 *
 * Quando ativado, todos os entry-points críticos retornam early sem trabalho:
 *   - DiscoverGerador (não gera/publica)
 *   - tick_filas.php (sai imediato)
 *   - pingo.php (sai imediato)
 *   - gsc_aprender.php (sai imediato — opcional, pode rodar leitura sem aplicar)
 *
 * Quando ativar:
 *   - Vi gasto de API fora da curva
 *   - WP comprometido
 *   - Notícia delicada (tragédia, eleição) — pausa publicação até bater editorial
 *   - Bug crítico recém-descoberto
 *
 * Como ativar:
 *   .env: PIPELINE_PAUSED=1
 *   (NÃO precisa restart — cada execução re-lê .env)
 *
 * Não bloqueia: leitura de status, dashboards, logs, smoke tests, manutenção.
 *
 * Uso:
 *   if (KillSwitch::ativo()) { return ['ok' => false, 'erro' => 'pipeline pausado']; }
 *   if (KillSwitch::ativo()) { exit(0); }  // em scripts cron
 */

require_once __DIR__ . '/Env.php';

class KillSwitch
{
    /**
     * Pipeline está pausado?
     */
    public static function ativo(): bool
    {
        @Env::load(__DIR__ . '/../.env');
        $v = (string)Env::get('PIPELINE_PAUSED', '0');
        return $v === '1' || strtolower($v) === 'true';
    }

    /**
     * Motivo declarado da pausa (PIPELINE_PAUSED_REASON em .env).
     * Vai pro log/erro retornado.
     */
    public static function motivo(): string
    {
        @Env::load(__DIR__ . '/../.env');
        $r = trim((string)Env::get('PIPELINE_PAUSED_REASON', ''));
        return $r !== '' ? $r : 'pausa manual via PIPELINE_PAUSED';
    }

    /**
     * Estrutura de retorno padronizada pra DiscoverGerador-like.
     */
    public static function retornoErro(): array
    {
        return [
            'ok'       => false,
            'erro'     => 'pipeline pausado: ' . self::motivo(),
            'paused'   => true,
            'kill_at'  => date('c'),
        ];
    }
}
