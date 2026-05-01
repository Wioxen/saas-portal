<?php
/**
 * DiscoverImagemViral — pipeline de processamento de imagem pra aumentar CTR no Discover.
 *
 * Aplica:
 *   - Saturação +12% (mais vibrante mobile)
 *   - Contraste +5% (destaca rosto/texto)
 *   - Tarja dinâmica colorida no canto sup. esquerdo, baseada na dor dominante:
 *     * urgencia      → vermelho "URGENTE"
 *     * medo          → laranja "ATENÇÃO"
 *     * dinheiro      → verde "LIBERADO"
 *     * oportunidade  → amarelo "NOVO HOJE"
 *     * (custom)      → texto livre passado pelo caller
 *   - Branding sutil (canto inf. dir, opcional — handle do site)
 *   - Output WebP qualidade 85 (formato leve, retém qualidade)
 *
 * Usa apenas extensão GD nativa (sem Imagick — funciona em qualquer XAMPP/Linux).
 *
 * Compliance: Google Discover ama imagens vibrantes, mas tarjas com TEXTO sobreposto
 * são DESACONSELHADAS pelo CLAUDE.md ("NUNCA usar imagens com texto sobreposto").
 * Por isso a TARJA é OPT-IN (parâmetro $tarjaTexto). Por default só ajusta saturação/contraste.
 *
 * Uso típico (sem tarja, alinhado com Google):
 *   $bytes = DiscoverImagemViral::processar($urlOriginal);
 *   file_put_contents('/tmp/processada.webp', $bytes);
 *
 * Uso com tarja (cuidado — só pra A/B com posts de promoção):
 *   $bytes = DiscoverImagemViral::processar($urlOriginal, ['tarja_texto' => 'LIBERADO HOJE', 'tarja_cor' => 'verde']);
 */
class DiscoverImagemViral
{
    private const TARGET_W = 1200;  // largura mínima Discover
    private const TARGET_H = 675;   // 16:9
    private const QUALIDADE_WEBP = 85;

    private const CORES_TARJA = [
        'vermelho' => [220, 38, 38],     // urgência
        'laranja'  => [234, 88, 12],     // medo
        'verde'    => [22, 163, 74],     // dinheiro
        'amarelo'  => [202, 138, 4],     // oportunidade
        'azul'     => [29, 78, 216],     // info/educacional
        'roxo'     => [126, 34, 206],    // premium
    ];

    /**
     * @param string $urlOriginal URL HTTPS da imagem-fonte
     * @param array  $opcoes      ['tarja_texto'?: string, 'tarja_cor'?: string,
     *                             'saturacao'?: int (default +12), 'contraste'?: int (default 5),
     *                             'redimensionar'?: bool (default true)]
     * @return string|null bytes da imagem WebP processada, ou null em falha
     */
    public static function processar(string $urlOriginal, array $opcoes = []): ?string
    {
        if (!extension_loaded('gd')) return null;
        if ($urlOriginal === '' || !preg_match('#^https?://#i', $urlOriginal)) return null;

        // 1) Download
        $bytes = self::baixar($urlOriginal);
        if ($bytes === null) return null;

        // 2) Carrega via GD (auto-detecta JPG/PNG/WebP)
        $img = @imagecreatefromstring($bytes);
        if ($img === false) return null;

        try {
            $w = imagesx($img);
            $h = imagesy($img);

            // 3) Redimensionar pra 1200×675 (16:9 Discover) se for muito grande
            $redimensionar = $opcoes['redimensionar'] ?? true;
            if ($redimensionar && ($w > self::TARGET_W || $h > self::TARGET_H)) {
                $img = self::redimensionarCrop($img, self::TARGET_W, self::TARGET_H);
                $w = imagesx($img);
                $h = imagesy($img);
            }

            // 4) Saturação (via filter colorize negativo no canal — aproximação)
            // GD não tem saturação direta; usa COLORIZE + CONTRAST como proxy
            $satBoost = (int)($opcoes['saturacao'] ?? 12);
            if ($satBoost > 0) {
                // Pequeno boost de cores quentes (R+G) — efeito "saturação"
                @imagefilter($img, IMG_FILTER_COLORIZE, $satBoost / 4, $satBoost / 8, 0, 0);
            }

            // 5) Contraste (-100 a +100; positivo = mais contraste)
            $contraste = (int)($opcoes['contraste'] ?? 5);
            if ($contraste !== 0) {
                // GD: IMG_FILTER_CONTRAST usa escala invertida (negativo aumenta contraste)
                @imagefilter($img, IMG_FILTER_CONTRAST, -$contraste);
            }

            // 6) Tarja (OPT-IN — só se tarja_texto setado)
            if (!empty($opcoes['tarja_texto'])) {
                $cor = (string)($opcoes['tarja_cor'] ?? 'vermelho');
                self::aplicarTarja($img, (string)$opcoes['tarja_texto'], $cor);
            }

            // 7) Exporta WebP (ou JPG fallback)
            ob_start();
            if (function_exists('imagewebp')) {
                @imagewebp($img, null, self::QUALIDADE_WEBP);
            } else {
                @imagejpeg($img, null, self::QUALIDADE_WEBP);
            }
            $out = ob_get_clean();
            return $out !== false && $out !== '' ? $out : null;
        } finally {
            imagedestroy($img);
        }
    }

    /** Download HTTPS com timeout e User-Agent. */
    private static function baixar(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 ClonaisImagemViral/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING       => '',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200 || strlen((string)$body) < 1024) return null;
        return (string)$body;
    }

    /** Redimensiona com center-crop pra preservar foco central. */
    private static function redimensionarCrop($src, int $tw, int $th)
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $srcRatio = $sw / $sh;
        $dstRatio = $tw / $th;
        // Calcula região do source pra crop centralizado
        if ($srcRatio > $dstRatio) {
            $cropH = $sh;
            $cropW = (int)($sh * $dstRatio);
            $cropX = (int)(($sw - $cropW) / 2);
            $cropY = 0;
        } else {
            $cropW = $sw;
            $cropH = (int)($sw / $dstRatio);
            $cropX = 0;
            $cropY = (int)(($sh - $cropH) / 2);
        }
        $dst = imagecreatetruecolor($tw, $th);
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $tw, $th, $cropW, $cropH);
        imagedestroy($src);
        return $dst;
    }

    /** Aplica tarja colorida no canto sup. esquerdo com texto branco. */
    private static function aplicarTarja($img, string $texto, string $cor): void
    {
        $rgb = self::CORES_TARJA[$cor] ?? self::CORES_TARJA['vermelho'];
        $w = imagesx($img);
        $h = imagesy($img);

        // Tarja: 25% da largura × 12% da altura, canto sup. esq.
        $tarjaW = (int)($w * 0.30);
        $tarjaH = (int)($h * 0.12);
        $padX = (int)($w * 0.025);
        $padY = (int)($h * 0.04);

        // Background colorido
        $corBg = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledrectangle($img, $padX, $padY, $padX + $tarjaW, $padY + $tarjaH, $corBg);

        // Texto branco (font built-in GD — máximo size 5)
        $corTxt = imagecolorallocate($img, 255, 255, 255);
        $fontSize = 5; // max size built-in font
        $fontW = imagefontwidth($fontSize);
        $fontH = imagefontheight($fontSize);
        $textoUp = mb_strtoupper($texto, 'UTF-8');
        $textoLen = mb_strlen($textoUp);
        // Centraliza
        $tx = $padX + (int)(($tarjaW - $textoLen * $fontW) / 2);
        $ty = $padY + (int)(($tarjaH - $fontH) / 2);
        imagestring($img, $fontSize, $tx, $ty, $textoUp, $corTxt);
    }

    /**
     * Gera variante 1080×1350 (4:5 portrait) pra Instagram Feed.
     * IG só aceita aspect ratio entre 1:1 (1080×1080) e 4:5 (1080×1350); com 16:9 falha silenciosamente.
     * Center-crop preserva o foco da imagem original. Output JPG (IG não aceita WebP).
     *
     * @param string $urlOriginal URL HTTPS da imagem original (qualquer dimensão, normalmente 1200×675 16:9)
     * @return string|null bytes JPEG ou null em falha
     */
    public static function variante1080x1350(string $urlOriginal): ?string
    {
        if (!extension_loaded('gd')) return null;
        if ($urlOriginal === '' || !preg_match('#^https?://#i', $urlOriginal)) return null;

        $bytes = self::baixar($urlOriginal);
        if ($bytes === null) return null;

        $img = @imagecreatefromstring($bytes);
        if ($img === false) return null;

        try {
            // Center-crop pra 1080×1350 (4:5 portrait)
            $img = self::redimensionarCrop($img, 1080, 1350);

            // Saturação leve pra IG (mobile-first como Discover)
            @imagefilter($img, IMG_FILTER_COLORIZE, 3, 1, 0, 0);

            // Progressive JPEG — IG rejeita JPEG baseline com erro
            // "Only photo or video can be accepted as media type" (descoberto 2026-04-27).
            @imageinterlace($img, true);

            // Output JPG qualidade 88 (IG aceita JPG/PNG, não WebP)
            ob_start();
            @imagejpeg($img, null, 88);
            $out = ob_get_clean();
            return $out !== false && $out !== '' ? $out : null;
        } finally {
            imagedestroy($img);
        }
    }

    /**
     * Helper: dado o `pain` (output do DiscoverPainClassifier), retorna config padrão de tarja.
     * Pode ser usado como atalho pelo caller.
     */
    public static function tarjaPorDor(?array $pain): array
    {
        if (!is_array($pain) || empty($pain['dominante'])) return [];
        $mapa = [
            'urgencia'      => ['texto' => 'URGENTE',     'cor' => 'vermelho'],
            'medo'          => ['texto' => 'ATENCAO',     'cor' => 'laranja'],
            'dinheiro'      => ['texto' => 'LIBERADO',    'cor' => 'verde'],
            'oportunidade'  => ['texto' => 'NOVO HOJE',   'cor' => 'amarelo'],
        ];
        $cfg = $mapa[$pain['dominante']] ?? null;
        if (!$cfg) return [];
        return ['tarja_texto' => $cfg['texto'], 'tarja_cor' => $cfg['cor']];
    }
}
