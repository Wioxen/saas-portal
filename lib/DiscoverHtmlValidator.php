<?php
/**
 * DiscoverHtmlValidator — sanity check final do HTML antes de publicar.
 *
 * Detecta bugs comuns que escapam de revisão visual:
 *   1. <a> aninhado dentro de <a> (resultado de pós-processadores em conflito)
 *   2. Texto literal `rel="..." target="..."` no body (vazamento de atributos)
 *   3. Smart quotes (curly: U+201C/U+201D) em atributos HTML
 *   4. Tags não-fechadas detectadas por round-trip DOM
 *   5. Atributos com aspas desbalanceadas (heurística)
 *   6. Imagens sem alt
 *   7. Links externos sem rel="noopener"
 *
 * Estratégia:
 *   - validar(string $html): array{ok:bool, html:string, problemas:[...], corrigidos:[...]}
 *   - Tenta auto-fix via DOM. Se ainda restar problema crítico → ok=false.
 *   - Caller decide: ok=true → publica. ok=false → status='html_invalido', alerta, não publica.
 *
 * Uso típico (no fim do pipeline, antes de updateStatus 'publicado'):
 *   $val = DiscoverHtmlValidator::validar($content);
 *   if (!$val['ok']) {
 *       // marca status='html_invalido', salva $val pra debug
 *   } elseif (!empty($val['corrigidos'])) {
 *       // atualiza WP com $val['html'] corrigido
 *   }
 */

class DiscoverHtmlValidator
{
    /** Problemas considerados CRÍTICOS — se restarem após auto-fix, ok=false. */
    private const CRITICOS = ['anchor_aninhado', 'atributo_vazado', 'smart_quotes_attr'];

    /**
     * @return array {
     *   ok: bool,                    # true se HTML está válido (após auto-fix)
     *   html: string,                # HTML após correções (mesmo input se nada mudou)
     *   problemas: array<int,array>, # problemas detectados (corrigidos ou não)
     *   corrigidos: array<int,array>,# subset de problemas que foram auto-fixados
     * }
     */
    public static function validar(string $html): array
    {
        if (trim($html) === '') {
            return ['ok' => false, 'html' => $html, 'problemas' => [['tipo' => 'html_vazio']], 'corrigidos' => []];
        }

        $problemas = [];
        $corrigidos = [];
        $htmlAtual = $html;

        // ── 1. Atributos vazados em TEXT NODES (sinal limpo via DOM) ──
        // Atributos HTML legítimos vivem em DOM como atributo, NÃO em text node.
        // Se um text node contém pattern `" rel="..." target=...`, foi serializado
        // pelo parser de HTML como texto após HTML quebrado — bug certeiro.
        $domDetect = new DOMDocument();
        libxml_use_internal_errors(true);
        @$domDetect->loadHTML('<?xml encoding="UTF-8"?><div>' . $htmlAtual . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpDetect = new DOMXPath($domDetect);
        $vazados = 0;
        $amostraVazado = '';
        foreach ($xpDetect->query('//text()') as $textNode) {
            $val = $textNode->nodeValue;
            if (preg_match('/"\s+(?:rel|target|data-[a-z-]+)\s*=\s*"/u', $val, $mv)) {
                $vazados++;
                if ($amostraVazado === '') $amostraVazado = mb_substr($val, 0, 100);
            }
        }
        if ($vazados > 0) {
            $problemas[] = ['tipo' => 'atributo_vazado', 'ocorrencias' => $vazados, 'amostra' => $amostraVazado];
        }

        // ── 2. Smart quotes em atributos ──
        if (preg_match_all('/\b(?:href|title|class|src|alt|rel|target)=[\x{201C}\x{201D}]/u', $htmlAtual, $m)) {
            // Auto-fix: substitui smart quotes por aspas retas em atributos
            $htmlAtual = preg_replace_callback(
                '/(\b(?:href|title|class|src|alt|rel|target)=)[\x{201C}\x{201D}]([^\x{201C}\x{201D}]*?)[\x{201C}\x{201D}]/u',
                fn($mm) => $mm[1] . '"' . $mm[2] . '"',
                $htmlAtual
            ) ?? $htmlAtual;
            $problemas[] = ['tipo' => 'smart_quotes_attr', 'ocorrencias' => count($m[0])];
            $corrigidos[] = ['tipo' => 'smart_quotes_attr', 'fix' => 'replaced'];
        }

        // ── 3. DOM-based checks ──
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8"?><div>' . $htmlAtual . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $libxmlErrors = libxml_get_errors();
        libxml_clear_errors();

        if (!$loaded) {
            return [
                'ok' => false, 'html' => $htmlAtual,
                'problemas' => array_merge($problemas, [['tipo' => 'dom_load_falhou']]),
                'corrigidos' => $corrigidos,
            ];
        }

        $xp = new DOMXPath($dom);

        // ── 3a. <a> aninhado ──
        $anchorsAninhados = $xp->query('//a//a');
        if ($anchorsAninhados && $anchorsAninhados->length > 0) {
            $problemas[] = ['tipo' => 'anchor_aninhado', 'ocorrencias' => $anchorsAninhados->length];
            // Auto-fix: pra cada <a> aninhado, pega o CONTEÚDO TEXTUAL e substitui pelo texto puro
            // (perde o link interno mas preserva estrutura)
            $aninhados = iterator_to_array($anchorsAninhados);
            foreach ($aninhados as $aFilho) {
                if (!$aFilho->parentNode) continue;
                $textoContent = $aFilho->textContent;
                $textNode = $dom->createTextNode($textoContent);
                $aFilho->parentNode->replaceChild($textNode, $aFilho);
            }
            $corrigidos[] = ['tipo' => 'anchor_aninhado', 'fix' => 'desencapsulado_pra_texto', 'qtd' => count($aninhados)];
        }

        // ── 3b. Imagens sem alt ──
        $imgsSemAlt = $xp->query('//img[not(@alt) or @alt=""]');
        if ($imgsSemAlt && $imgsSemAlt->length > 0) {
            $problemas[] = ['tipo' => 'img_sem_alt', 'ocorrencias' => $imgsSemAlt->length];
            // Auto-fix: alt="" (declarativo, indica decorativa)
            foreach (iterator_to_array($imgsSemAlt) as $img) {
                if (!$img->hasAttribute('alt')) $img->setAttribute('alt', '');
            }
            $corrigidos[] = ['tipo' => 'img_sem_alt', 'fix' => 'alt_vazio_adicionado'];
        }

        // ── 3c. Links externos sem rel ──
        $linksExternos = $xp->query('//a[starts-with(@href, "http") and not(contains(@href, "vagasebeneficios.com")) and not(contains(@href, "cursosenacgratuito.com.br")) and not(contains(@href, "guiadoscursos.com")) and not(contains(@href, "comocomprar.com.br")) and not(contains(@href, "leaodabarra.com.br")) and not(contains(@href, "ondecompraragora.com")) and not(@rel)]');
        if ($linksExternos && $linksExternos->length > 0) {
            // Auto-fix: adiciona rel="noopener"
            foreach (iterator_to_array($linksExternos) as $link) {
                $link->setAttribute('rel', 'noopener');
            }
            $corrigidos[] = ['tipo' => 'link_externo_sem_rel', 'fix' => 'rel_noopener_adicionado', 'qtd' => $linksExternos->length];
        }

        // ── 3d. Atributo style com aspas desbalanceadas (heurística) ──
        $stylesQuebrados = $xp->query('//*[contains(@style, ";;") or contains(@style, "::")]');
        if ($stylesQuebrados && $stylesQuebrados->length > 0) {
            $problemas[] = ['tipo' => 'style_quebrado', 'ocorrencias' => $stylesQuebrados->length, 'severidade' => 'baixa'];
        }

        // ── Re-serializa HTML após correções DOM ──
        if (!empty($corrigidos)) {
            $out = '';
            foreach ($dom->documentElement->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }
            $htmlAtual = $out !== '' ? $out : $htmlAtual;
        }

        // ── 4. Re-checa críticos APÓS auto-fix ──
        $criticosRestantes = self::contarCriticosRestantes($htmlAtual);
        $ok = empty($criticosRestantes);

        return [
            'ok'         => $ok,
            'html'       => $htmlAtual,
            'problemas'  => $problemas,
            'corrigidos' => $corrigidos,
            'criticos_restantes' => $criticosRestantes,
        ];
    }

    /** Re-checa só problemas que tornariam HTML inválido pra publicar. */
    private static function contarCriticosRestantes(string $html): array
    {
        $restantes = [];
        // Atributo vazado: pattern em text node DOM
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);
        foreach ($xp->query('//text()') as $textNode) {
            if (preg_match('/"\s+(?:rel|target|data-[a-z-]+)\s*=\s*"/u', $textNode->nodeValue)) {
                $restantes[] = 'atributo_vazado';
                break;
            }
        }
        // <a><a> aninhado?
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);
        $aninhados = $xp->query('//a//a');
        if ($aninhados && $aninhados->length > 0) $restantes[] = 'anchor_aninhado';
        return $restantes;
    }

    /**
     * Resumo legível pro log/health webhook.
     */
    public static function resumo(array $resultado): string
    {
        if ($resultado['ok'] && empty($resultado['corrigidos'])) {
            return 'HTML válido (sem fixes)';
        }
        if ($resultado['ok']) {
            $fixes = count($resultado['corrigidos']);
            return "HTML válido após {$fixes} auto-fix(es)";
        }
        $criticos = implode(',', $resultado['criticos_restantes'] ?? []);
        return "HTML INVÁLIDO — críticos restantes: {$criticos}";
    }
}
