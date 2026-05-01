<?php
/**
 * Gera alt_text, legenda e descrição SEO-expert para featured image.
 *
 * Uso:
 *   $meta = DiscoverImagemSEO::gerar($titulo, $keyword, $gancho, $metaExistente);
 *   // $meta = ['alt_text' => ..., 'legenda' => ..., 'descricao' => ..., 'preenchido_automatico' => bool]
 *
 * Estratégia:
 *  1. Se LLM já retornou campo válido (texto significativo, >N chars), preserva.
 *  2. Se vazio/curto, gera versão contextual baseada em título + keyword + gancho.
 */
class DiscoverImagemSEO
{
    /** Minimums pra considerar preenchido (evita LLM retornando "N/A", "imagem", etc). */
    private const MIN_ALT = 15;
    private const MIN_LEGENDA = 25;
    private const MIN_DESCRICAO = 40;

    /**
     * @param string $titulo    Título final do artigo
     * @param string $keyword   Keyword principal (termo-seed)
     * @param string $gancho    Frase de gancho da fonte (opcional)
     * @param array  $existente Meta já preenchida pelo LLM (pode ser vazio/incompleto)
     * @return array Meta completa com fallbacks
     */
    public static function gerar(string $titulo, string $keyword, string $gancho = '', array $existente = [], string $imageUrl = '', array $cfg = []): array
    {
        $alt       = (string)($existente['alt_text']  ?? '');
        $legenda   = (string)($existente['legenda']   ?? '');
        $descricao = (string)($existente['descricao'] ?? '');

        $preenchidoAutomatico = false;
        $altViaVision = false;

        if (mb_strlen(trim($alt), 'UTF-8') < self::MIN_ALT) {
            // 1ª tentativa: Vision (rica, contextual). Custa ~$0.001/imagem.
            if ($imageUrl !== '' && !empty($cfg['openai_api_key'])) {
                try {
                    require_once __DIR__ . '/DiscoverVisionAlt.php';
                    $altVision = DiscoverVisionAlt::gerar($imageUrl, $titulo, $cfg);
                    if ($altVision !== null && mb_strlen($altVision, 'UTF-8') >= self::MIN_ALT) {
                        $alt = $altVision;
                        $altViaVision = true;
                    }
                } catch (Throwable $e) { /* fallback abaixo */ }
            }
            // 2ª tentativa (fallback): geração baseada em título/keyword
            if (!$altViaVision || $alt === '') {
                $alt = self::gerarAltText($titulo, $keyword);
            }
            $preenchidoAutomatico = true;
        }
        if (mb_strlen(trim($legenda), 'UTF-8') < self::MIN_LEGENDA) {
            $legenda = self::gerarLegenda($titulo, $gancho);
            $preenchidoAutomatico = true;
        }
        if (mb_strlen(trim($descricao), 'UTF-8') < self::MIN_DESCRICAO) {
            $descricao = self::gerarDescricao($titulo, $keyword, $gancho);
            $preenchidoAutomatico = true;
        }

        return [
            'alt_text'              => $alt,
            'legenda'               => $legenda,
            'descricao'             => $descricao,
            'preenchido_automatico' => $preenchidoAutomatico,
            'alt_via_vision'        => $altViaVision,
        ];
    }

    /**
     * Alt text: 8-12 palavras, contém keyword, descreve a cena/conceito.
     * Bom pra acessibilidade + SEO imagens.
     */
    private static function gerarAltText(string $titulo, string $keyword): string
    {
        $kw = self::normalizarKeyword($keyword);
        $sujeito = self::extrairSujeitoDoTitulo($titulo);

        // Templates contextuais (escolhe conforme disponível)
        if ($kw && $sujeito && mb_strtolower($kw) !== mb_strtolower($sujeito)) {
            return "Ilustração de {$sujeito} relacionada a {$kw}";
        }
        if ($kw) {
            return "Imagem ilustrativa sobre {$kw}";
        }
        if ($sujeito) {
            return "Ilustração do tema {$sujeito}";
        }
        return 'Imagem ilustrativa do artigo';
    }

    /**
     * Legenda: 1 frase curta com contexto, aparece sob a imagem no WP.
     */
    private static function gerarLegenda(string $titulo, string $gancho): string
    {
        $tituloCurto = self::encurtarTitulo($titulo, 80);
        if ($gancho !== '' && mb_strlen($gancho, 'UTF-8') < 120) {
            $ganchoLimpo = rtrim(trim($gancho), '.!?');
            return $ganchoLimpo . '.';
        }
        return $tituloCurto;
    }

    /**
     * Descrição: 2-3 frases, mais detalhada, pra acessibilidade + SEO profundo.
     */
    private static function gerarDescricao(string $titulo, string $keyword, string $gancho): string
    {
        $kw = self::normalizarKeyword($keyword);
        $tituloCurto = self::encurtarTitulo($titulo, 90);
        $partes = [];
        $partes[] = 'Imagem ilustrativa do artigo sobre ' . ($kw ?: 'o tema em destaque') . '.';
        $partes[] = 'Representa visualmente o conteúdo de "' . $tituloCurto . '".';
        if ($gancho !== '' && mb_strlen($gancho, 'UTF-8') < 150) {
            $ganchoLimpo = rtrim(trim($gancho), '.!?');
            $partes[] = 'Contexto: ' . $ganchoLimpo . '.';
        }
        return implode(' ', $partes);
    }

    /** Normaliza keyword (remove prefixos vazios, trim). */
    private static function normalizarKeyword(string $kw): string
    {
        $kw = trim($kw);
        if ($kw === '') return '';
        // Remove prefixos genéricos
        $kw = preg_replace('/^(sobre|o|a|os|as|de|do|da)\s+/iu', '', $kw) ?? $kw;
        return $kw;
    }

    /** Extrai sujeito principal do título (ignora artigos/prefixos). */
    private static function extrairSujeitoDoTitulo(string $titulo): string
    {
        $t = trim($titulo);
        if ($t === '') return '';
        // Corta na 1ª pontuação forte (:, ;, ?) se houver
        if (preg_match('/^([^:;?]+)/u', $t, $m)) {
            $t = trim($m[1]);
        }
        // Limita a 6 palavras
        $palavras = preg_split('/\s+/', $t);
        if (count($palavras) > 6) {
            $t = implode(' ', array_slice($palavras, 0, 6));
        }
        return $t;
    }

    /** Encurta o título pra caber em 80/90 chars. */
    private static function encurtarTitulo(string $titulo, int $max): string
    {
        $t = trim($titulo);
        if (mb_strlen($t, 'UTF-8') <= $max) return $t;
        // Corta na última palavra completa antes do limite
        $t = mb_substr($t, 0, $max - 3, 'UTF-8');
        $t = preg_replace('/\s+\S*$/', '', $t) ?? $t;
        return $t . '...';
    }
}
