<?php
/**
 * DiscoverUpdateBadge — sinaliza visualmente "atualizado em X" pra Discover/leitor (B3).
 *
 * Discover prioriza conteúdo "fresh". Posts que recebem AutoRefresh (DiscoverReviewer)
 * ganham `<time itemprop="dateModified">` no Schema, MAS visualmente nada muda.
 *
 * Este módulo:
 *   1. Inserir badge "Atualizado em DD/MM" logo após o H1 (visível pro leitor)
 *   2. Marker `data-update-badge="<timestamp>"` pra idempotência
 *   3. Schema NewsArticle.dateModified já é gerado por DiscoverSchemas — sincroniza
 *
 * Aplicação:
 *   - Quando AutoRefresh roda em post → DiscoverReviewer chama DiscoverUpdateBadge::aplicar
 *   - DiscoverPostProcess pode aplicar opcionalmente em meta['updated_at']
 *
 * Idempotente: sobrescreve badge anterior se já existe.
 */
class DiscoverUpdateBadge
{
    private const MARKER_PREFIX = 'data-update-badge=';

    /**
     * Aplica badge no HTML. Se já existe um, atualiza.
     *
     * @param string $html  HTML do post (com H1)
     * @param int|null $tsAtualizacao timestamp Unix (default: agora)
     * @param string|null $motivo opcional ("revisão editorial", "AutoRefresh CTR")
     * @return string HTML com badge
     */
    public static function aplicar(string $html, ?int $tsAtualizacao = null, ?string $motivo = null): string
    {
        $ts = $tsAtualizacao ?? time();
        $dataPt = self::formatarDataPt($ts);
        $isoTs  = date('c', $ts);

        $motivoHtml = $motivo ? ' · <small style="color:#64748b">' . htmlspecialchars($motivo, ENT_QUOTES, 'UTF-8') . '</small>' : '';

        $badge = sprintf(
            '<div %s"%d" class="cc-update-badge" style="display:inline-block;background:linear-gradient(135deg,#fef3c7,#fde68a);color:#78350f;padding:6px 12px;border-radius:16px;font-size:0.85em;font-weight:600;margin:8px 0 16px 0;border:1px solid #f59e0b;"><span aria-hidden="true">↻</span> Atualizado em <time datetime="%s" itemprop="dateModified">%s</time>%s</div>',
            self::MARKER_PREFIX, $ts, $isoTs, $dataPt, $motivoHtml
        );

        // Remove badge antigo se existe (idempotência: substitui sem duplicar)
        $padraoAntigo = '#<div\s+' . preg_quote(self::MARKER_PREFIX, '#') . '"[^"]+"\s+class="cc-update-badge"[^>]*>.*?</div>#is';
        if (preg_match($padraoAntigo, $html)) {
            return preg_replace($padraoAntigo, $badge, $html, 1) ?? $html;
        }

        // Insere DEPOIS do H1 (1º <h1>...</h1>) — fica abaixo do título
        $padraoH1 = '#(</h1>)#i';
        if (preg_match($padraoH1, $html)) {
            return preg_replace($padraoH1, '$1' . "\n" . $badge, $html, 1) ?? $html;
        }

        // Sem H1: insere antes do 1º <p>
        $padraoP1 = '#(<p\b)#i';
        if (preg_match($padraoP1, $html)) {
            return preg_replace($padraoP1, $badge . "\n$1", $html, 1) ?? $html;
        }

        // Última opção: prepend
        return $badge . "\n" . $html;
    }

    /** Remove badge (caso queiramos limpar antes de re-aplicar). */
    public static function remover(string $html): string
    {
        $padrao = '#<div\s+' . preg_quote(self::MARKER_PREFIX, '#') . '"[^"]+"\s+class="cc-update-badge"[^>]*>.*?</div>\s*#is';
        return preg_replace($padrao, '', $html) ?? $html;
    }

    /**
     * Detecta se post tem badge atual (>= cutoff em segundos atrás).
     * Útil pra decidir se vale re-aplicar.
     */
    public static function badgeRecente(string $html, int $cutoffSegundos = 86400): bool
    {
        if (!preg_match('#' . preg_quote(self::MARKER_PREFIX, '#') . '"(\d+)"#', $html, $m)) return false;
        $ts = (int)$m[1];
        return $ts >= (time() - $cutoffSegundos);
    }

    private static function formatarDataPt(int $ts): string
    {
        $meses = [1=>'jan',2=>'fev',3=>'mar',4=>'abr',5=>'mai',6=>'jun',
                  7=>'jul',8=>'ago',9=>'set',10=>'out',11=>'nov',12=>'dez'];
        $d = (int)date('j', $ts);
        $m = $meses[(int)date('n', $ts)];
        $y = date('Y', $ts);
        return "{$d} de {$m} de {$y}";
    }
}
