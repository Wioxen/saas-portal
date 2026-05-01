<?php
/**
 * ImagemOptimizer — re-encoding local via GD.
 *
 * USO:
 *   $opt = new ImagemOptimizer();
 *   $bin = $opt->reencode($binarioPng, 'jpeg', 85); // retorna binário JPEG
 *
 * EFEITO:
 *   - Decodifica a imagem em pixels e re-encoda do zero
 *   - Resultado natural: TODA metadata original é descartada (EXIF, XMP, IPTC, C2PA)
 *     porque GD imagem-puro só lida com pixels
 *   - Comprime no quality alvo (JPEG 80-90% é o ponto doce pra web)
 *
 * ESTE NÃO É UM TRUQUE PRA "ESCONDER IA":
 *   - É a mesma operação que TODO CDN/CMS faz pra otimizar imagens
 *   - C2PA é descartado como side-effect natural do re-encoding (não como objetivo)
 *   - Watermarks invisíveis em pixel level (ex: SynthID do Google) NÃO são removidos
 *   - Visual AI tells (mãos estranhas, simetria perfeita) NÃO são removidos
 *
 * Requisitos: extensão GD (vem padrão no XAMPP/PHP).
 */
class ImagemOptimizer
{
    /**
     * Re-encoda binário de imagem.
     *
     * @param string $bin     Binário da imagem original (PNG, JPEG, WebP, GIF)
     * @param string $formato Saída desejada: 'jpeg', 'png', 'webp'
     * @param int    $quality 1-100. Padrão 85 (web sweet spot).
     * @return string|null Binário re-encodado, ou null se falhou.
     */
    public function reencode(string $bin, string $formato = 'jpeg', int $quality = 85): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $img = @imagecreatefromstring($bin);
        if ($img === false) {
            return null;
        }

        // Converte alpha pra branco se for JPEG (que não suporta transparência)
        if ($formato === 'jpeg') {
            $img = $this->achatarAlpha($img);
        }

        ob_start();
        $ok = false;
        switch ($formato) {
            case 'jpeg':
                $ok = imagejpeg($img, null, max(1, min(100, $quality)));
                break;
            case 'png':
                // PNG quality é 0-9 invertido. Mapeia 85→2 (compressão alta sem perda perceptível).
                $pngLevel = (int) round((100 - $quality) / 11);
                $ok = imagepng($img, null, max(0, min(9, $pngLevel)));
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    $ok = imagewebp($img, null, max(1, min(100, $quality)));
                }
                break;
        }
        $out = ob_get_clean();
        imagedestroy($img);

        return $ok ? $out : null;
    }

    /**
     * Sufixo de qualidade aplicada no upscaling sutil — re-amostra pra dimensão alvo.
     * Útil pra normalizar tamanhos do DALL-E (1792x1024) pra alvos editoriais (ex: 1200x675).
     */
    public function reencodeRedimensionado(string $bin, int $largura, int $altura, string $formato = 'jpeg', int $quality = 85): ?string
    {
        if (!function_exists('imagecreatefromstring')) return null;

        $src = @imagecreatefromstring($bin);
        if ($src === false) return null;

        $w = imagesx($src);
        $h = imagesy($src);
        if (!$w || !$h) { imagedestroy($src); return null; }

        $dst = imagecreatetruecolor($largura, $altura);
        if ($formato === 'jpeg') {
            $branco = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $largura, $altura, $branco);
        } else {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $largura, $altura, $w, $h);
        imagedestroy($src);

        ob_start();
        $ok = false;
        switch ($formato) {
            case 'jpeg':
                $ok = imagejpeg($dst, null, max(1, min(100, $quality)));
                break;
            case 'png':
                $pngLevel = (int) round((100 - $quality) / 11);
                $ok = imagepng($dst, null, max(0, min(9, $pngLevel)));
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    $ok = imagewebp($dst, null, max(1, min(100, $quality)));
                }
                break;
        }
        $out = ob_get_clean();
        imagedestroy($dst);

        return $ok ? $out : null;
    }

    /**
     * Achata canal alpha em fundo branco — necessário pra JPEG.
     */
    private function achatarAlpha($img)
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $flat = imagecreatetruecolor($w, $h);
        $branco = imagecolorallocate($flat, 255, 255, 255);
        imagefilledrectangle($flat, 0, 0, $w, $h, $branco);
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        return $flat;
    }

    /**
     * Confere se o binário tem metadata C2PA (informativo, não obrigatório).
     * Retorna true se o marcador C2PA é detectado (DALL-E 3 adiciona desde fev/2024).
     */
    public function temC2PA(string $bin): bool
    {
        // C2PA usa marcador 'jumb' no início + 'c2pa' como tipo
        return strpos($bin, "c2pa") !== false || strpos($bin, "jumb") !== false;
    }

    /**
     * Queima overlay estilo Netflix no pixel: bar preta translúcida no top-left,
     * stripe colorido na esquerda, texto branco caixa alta.
     *
     * Procura TTF em ordem: lib/fonts/overlay.ttf → Impact (Win) → DejaVuSans-Bold (Linux).
     * Sem TTF disponível: usa imagestring built-in (texto tosco mas funcional).
     *
     * @param string $bin     Binário da imagem original
     * @param string $texto   Frase a queimar (será uppercased)
     * @param string $corHex  Cor do stripe lateral (default vermelho)
     * @param int    $quality Qualidade JPEG saída
     * @return string|null    Binário JPEG modificado, null se falhou
     */
    public function aplicarOverlayNetflix(string $bin, string $texto, string $corHex = '#c4170c', int $quality = 88): ?string
    {
        if (!function_exists('imagecreatefromstring')) return null;
        $texto = trim($texto);
        if ($texto === '') return $this->reencode($bin, 'jpeg', $quality);

        $img = @imagecreatefromstring($bin);
        if ($img === false) return null;
        $img = $this->achatarAlpha($img);

        $w = imagesx($img); $h = imagesy($img);
        if (!$w || !$h) { imagedestroy($img); return null; }

        $textoUpper = mb_strtoupper($texto, 'UTF-8');
        $linhas = $this->dividirEmLinhas($textoUpper);  // 1 a 3 linhas, max 6 palavras

        // === Dimensões proporcionais — SELO compacto multi-linha ===
        $padTop    = (int) round($h * 0.030);          // margem do topo
        $padLeft   = (int) round($w * 0.020);          // margem da esquerda
        $padBarX   = (int) round($w * 0.016);          // padding interno horizontal (um toque maior)
        $padBarY   = (int) round($h * 0.020);          // padding interno vertical
        $stripeW   = max(4, (int) round($w * 0.0028)); // stripe lateral fino
        $fontSize  = (int) round($h * 0.045);          // ~46px em 1024 — um pouco maior
        $lineGap   = 0.18;                              // espaçamento entre linhas como % da altura do glyph

        $fontPath = $this->resolverFonte();

        // Mede largura/altura de cada linha
        $larguraMax = 0;
        $alturaUnitaria = 0;
        foreach ($linhas as $linha) {
            if ($fontPath !== null && function_exists('imagettfbbox')) {
                $bbox = imagettfbbox($fontSize, 0, $fontPath, $linha);
                $lw = abs($bbox[2] - $bbox[0]);
                $lh = abs($bbox[7] - $bbox[1]);
            } else {
                $lw = strlen($linha) * 9;
                $lh = 18;
            }
            if ($lw > $larguraMax) { $larguraMax = $lw; }
            if ($lh > $alturaUnitaria) { $alturaUnitaria = $lh; }
        }

        // Selo nunca passa de 50% da largura (texto maior, mas ainda respira)
        $maxSeloW = (int) round($w * 0.50);
        $seloW    = min($maxSeloW, $stripeW + $padBarX + $larguraMax + $padBarX);

        // Altura: soma das linhas + gaps + paddings
        $totalLinhas = count($linhas);
        $stepY = (int) round($alturaUnitaria * (1 + $lineGap));
        $seloH = (int) ($totalLinhas * $alturaUnitaria + ($totalLinhas - 1) * round($alturaUnitaria * $lineGap)) + ($padBarY * 2);

        $seloX = $padLeft;
        $seloY = $padTop;

        $preto  = imagecolorallocatealpha($img, 0, 0, 0, 28); // ~78% opaca
        $branco = imagecolorallocate($img, 255, 255, 255);
        [$r, $g, $b] = $this->hexToRgb($corHex);
        $acento = imagecolorallocate($img, $r, $g, $b);

        imagealphablending($img, true);
        imagefilledrectangle($img, $seloX, $seloY, $seloX + $seloW, $seloY + $seloH, $preto);
        imagefilledrectangle($img, $seloX, $seloY, $seloX + $stripeW, $seloY + $seloH, $acento);

        $textoX = $seloX + $stripeW + $padBarX;

        if ($fontPath !== null && function_exists('imagettftext')) {
            $sombra = imagecolorallocatealpha($img, 0, 0, 0, 64);
            // y baseline da primeira linha
            $cursorY = $seloY + $padBarY + $alturaUnitaria;
            foreach ($linhas as $linha) {
                imagettftext($img, $fontSize, 0, $textoX + 2, $cursorY + 2, $sombra, $fontPath, $linha);
                imagettftext($img, $fontSize, 0, $textoX, $cursorY, $branco, $fontPath, $linha);
                $cursorY += $stepY;
            }
        } else {
            $cursorY = $seloY + $padBarY;
            foreach ($linhas as $linha) {
                imagestring($img, 5, $textoX, $cursorY, $linha, $branco);
                $cursorY += $stepY;
            }
        }

        ob_start();
        $ok = imagejpeg($img, null, max(1, min(100, $quality)));
        $out = ob_get_clean();
        imagedestroy($img);
        return $ok ? $out : null;
    }

    /**
     * Procura TTF utilizável. Retorna null se nada encontrado (caller usa imagestring).
     * Pra qualidade premium: dropar Bebas Neue / Anton / Oswald em lib/fonts/overlay.ttf.
     */
    private function resolverFonte(): ?string
    {
        $candidatos = [
            __DIR__ . '/fonts/overlay.ttf',
            __DIR__ . '/fonts/Anton-Regular.ttf',
            __DIR__ . '/fonts/BebasNeue-Bold.ttf',
            'C:\\Windows\\Fonts\\impact.ttf',
            'C:\\Windows\\Fonts\\arialbd.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        ];
        foreach ($candidatos as $f) {
            if (@is_readable($f)) return $f;
        }
        return null;
    }

    /**
     * Divide texto do overlay em linhas (máx 3 palavras por linha, máx 3 linhas total).
     *
     * Regras:
     *   1-3 palavras → 1 linha
     *   4 palavras   → 2 linhas (2+2)
     *   5 palavras   → 2 linhas (3+2)
     *   6 palavras   → 2 linhas (3+3)
     *   7 palavras   → 3 linhas (3+2+2)
     *   8 palavras   → 3 linhas (3+3+2)
     *   9+           → corta nas 8 primeiras, divide 3+3+2
     */
    private function dividirEmLinhas(string $texto): array
    {
        $palavras = preg_split('/\s+/', trim($texto));
        $palavras = array_values(array_filter($palavras, fn($p) => $p !== '' && $p !== '·'));
        $n = count($palavras);

        if ($n === 0) return [''];
        if ($n <= 3) return [implode(' ', $palavras)];

        if ($n === 4) {
            return [
                implode(' ', array_slice($palavras, 0, 2)),
                implode(' ', array_slice($palavras, 2)),
            ];
        }
        if ($n === 5) {
            return [
                implode(' ', array_slice($palavras, 0, 3)),
                implode(' ', array_slice($palavras, 3)),
            ];
        }
        if ($n === 6) {
            return [
                implode(' ', array_slice($palavras, 0, 3)),
                implode(' ', array_slice($palavras, 3)),
            ];
        }
        if ($n === 7) {
            return [
                implode(' ', array_slice($palavras, 0, 3)),
                implode(' ', array_slice($palavras, 3, 2)),
                implode(' ', array_slice($palavras, 5)),
            ];
        }
        // 8+ → corta em 8, divide 3+3+2
        $palavras = array_slice($palavras, 0, 8);
        return [
            implode(' ', array_slice($palavras, 0, 3)),
            implode(' ', array_slice($palavras, 3, 3)),
            implode(' ', array_slice($palavras, 6)),
        ];
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return [196, 23, 12];
        return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
    }

    /**
     * Conveniência: baixa URL e re-encoda.
     */
    public function processarUrl(string $url, string $formato = 'jpeg', int $quality = 85): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $bin = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($bin === false || $code >= 400) return null;
        return $this->reencode($bin, $formato, $quality);
    }
}
