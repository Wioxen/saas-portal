<?php
/**
 * ImagemLayoutHighCTR — desenha layout high-CTR completo via GD.
 *
 * Estilo: portal de notícias (G1/Globo) com:
 *   - Sticker amarelo top-left (texto preto bold, 2 linhas)
 *   - Sub-label preto abaixo do sticker (deadline, 1 linha branca)
 *   - Até 3 info-badges verdes na esquerda (cápsulas com bullet circular + 2 linhas)
 *   - Banner vermelho urgência bottom-left (se deadline ≤ 30 dias)
 *
 * Uso:
 *   $layout = new ImagemLayoutHighCTR();
 *   $bin2 = $layout->aplicar($binJpg, [
 *     'sticker_l1'    => 'NOVA TURMA 800H',
 *     'sticker_l2'    => 'EAD SENAC ERECHIM',
 *     'sub_label'     => 'INSCRIÇÕES ATÉ 15/05',
 *     'badges'        => [...3 itens com label/value/icon],
 *     'urgent_dias'   => 17,         // ou null pra esconder banner
 *   ]);
 *
 * Cores: amarelo #FFE600, preto #1a1a1a, verde #22c55e, vermelho #DC2626, branco #FFFFFF.
 *
 * Requer GD com freetype (imagettftext). Fallback gracioso pra imagestring se TTF indisponível.
 */
class ImagemLayoutHighCTR
{
    /** Cores hex usadas no layout */
    private const COR_AMARELO = '#FFE600';
    private const COR_PRETO   = '#1a1a1a';
    private const COR_VERDE   = '#22c55e';
    private const COR_VERMELHO= '#DC2626';
    private const COR_BRANCO  = '#FFFFFF';

    /**
     * Método principal — aplica todo o layout.
     *
     * @param string $bin   Binário da imagem original (JPG/PNG)
     * @param array  $dados Vide schema acima
     * @param int    $jpegQuality Qualidade JPEG saída (default 88)
     * @return string|null  Binário JPEG com layout aplicado, null se falhou
     */
    public function aplicar(string $bin, array $dados, int $jpegQuality = 88): ?string
    {
        if (!function_exists('imagecreatefromstring')) return null;

        $img = @imagecreatefromstring($bin);
        if ($img === false) return null;
        $img = $this->achatarAlpha($img);

        $w = imagesx($img);
        $h = imagesy($img);
        if (!$w || !$h) { imagedestroy($img); return null; }

        imagealphablending($img, true);

        // 1. STICKER amarelo top-left (com sub-label preto se houver)
        $stickerHeight = 0;
        $stickerY = 0;
        if (!empty($dados['sticker_l1']) || !empty($dados['sticker_l2'])) {
            [$stickerY, $stickerHeight] = $this->drawSticker(
                $img, $w, $h,
                (string)($dados['sticker_l1'] ?? ''),
                (string)($dados['sticker_l2'] ?? '')
            );
        }

        $cursorY = $stickerY + $stickerHeight + (int) round($h * 0.014);

        // 2. SUB-LABEL preto (logo abaixo do sticker)
        if (!empty($dados['sub_label'])) {
            $subH = $this->drawSubLabel($img, $w, $h, $cursorY, (string)$dados['sub_label']);
            $cursorY += $subH + (int) round($h * 0.025);
        }

        // 3. INFO-BADGES verdes (cápsulas brancas empilhadas verticalmente)
        if (!empty($dados['badges']) && is_array($dados['badges'])) {
            $cursorY += (int) round($h * 0.012);
            foreach (array_slice($dados['badges'], 0, 3) as $badge) {
                $badgeH = $this->drawInfoBadge($img, $w, $h, $cursorY, $badge);
                $cursorY += $badgeH + (int) round($h * 0.012);
            }
        }

        // 4. BANNER vermelho urgência (bottom-left)
        if (!empty($dados['urgent_dias']) && (int)$dados['urgent_dias'] > 0 && (int)$dados['urgent_dias'] <= 30) {
            $this->drawUrgentBanner($img, $w, $h, (int)$dados['urgent_dias']);
        }

        // Encoda
        ob_start();
        $ok = imagejpeg($img, null, max(1, min(100, $jpegQuality)));
        $out = ob_get_clean();
        imagedestroy($img);
        return $ok ? $out : null;
    }

    // ==========================================================
    // STICKER amarelo top-left (rounded rect + 2 linhas)
    // ==========================================================
    private function drawSticker($img, int $w, int $h, string $line1, string $line2): array
    {
        $line1 = mb_strtoupper(trim($line1), 'UTF-8');
        $line2 = mb_strtoupper(trim($line2), 'UTF-8');

        $padTop  = (int) round($h * 0.030);
        $padLeft = (int) round($w * 0.020);
        $padX    = (int) round($w * 0.020);
        $padY    = (int) round($h * 0.024);
        $radius  = (int) round($h * 0.020);
        $fontSize= (int) round($h * 0.060); // bem grande, alto impacto
        $lineGap = (int) round($fontSize * 0.18);

        $fontPath = $this->resolverFonte();
        [$w1, $h1] = $this->medirTexto($line1, $fontSize, $fontPath);
        [$w2, $h2] = $line2 !== '' ? $this->medirTexto($line2, $fontSize, $fontPath) : [0, 0];

        $textW = max($w1, $w2);
        $textH = $h1 + ($line2 !== '' ? ($lineGap + $h2) : 0);

        $stickerW = $textW + ($padX * 2);
        $stickerH = $textH + ($padY * 2);
        $maxW = (int) round($w * 0.55);
        if ($stickerW > $maxW) $stickerW = $maxW;

        $x = $padLeft;
        $y = $padTop;

        $amarelo = $this->corHex($img, self::COR_AMARELO);
        $preto   = $this->corHex($img, self::COR_PRETO);
        // Sombra leve
        $sombra  = imagecolorallocatealpha($img, 0, 0, 0, 90);
        $this->roundedRect($img, $x + 4, $y + 6, $stickerW, $stickerH, $radius, $sombra);
        $this->roundedRect($img, $x, $y, $stickerW, $stickerH, $radius, $amarelo);

        // Texto
        $textX = $x + $padX;
        $textY = $y + $padY + $h1;
        $this->desenharTexto($img, $line1, $fontSize, $textX, $textY, $preto, $fontPath);
        if ($line2 !== '') {
            $textY += $lineGap + $h2;
            $this->desenharTexto($img, $line2, $fontSize, $textX, $textY, $preto, $fontPath);
        }

        return [$y, $stickerH];
    }

    // ==========================================================
    // SUB-LABEL preto
    // ==========================================================
    private function drawSubLabel($img, int $w, int $h, int $startY, string $text): int
    {
        $text = mb_strtoupper(trim($text), 'UTF-8');
        $padLeft = (int) round($w * 0.020);
        $padX    = (int) round($w * 0.018);
        $padY    = (int) round($h * 0.015);
        $radius  = (int) round($h * 0.014);
        $fontSize= (int) round($h * 0.034);

        $fontPath = $this->resolverFonte();
        [$tw, $th] = $this->medirTexto($text, $fontSize, $fontPath);

        $bgW = $tw + ($padX * 2);
        $bgH = $th + ($padY * 2);
        $maxW = (int) round($w * 0.50);
        if ($bgW > $maxW) $bgW = $maxW;

        $preto = $this->corHex($img, self::COR_PRETO);
        $branco = $this->corHex($img, self::COR_BRANCO);
        $this->roundedRect($img, $padLeft, $startY, $bgW, $bgH, $radius, $preto);
        $this->desenharTexto($img, $text, $fontSize, $padLeft + $padX, $startY + $padY + $th, $branco, $fontPath);

        return $bgH;
    }

    // ==========================================================
    // INFO-BADGE verde (cápsula branca + bullet verde + 2 linhas)
    // ==========================================================
    private function drawInfoBadge($img, int $w, int $h, int $startY, array $badge): int
    {
        $label = mb_strtoupper(trim((string)($badge['label'] ?? '')), 'UTF-8');
        $value = mb_strtoupper(trim((string)($badge['value'] ?? '')), 'UTF-8');
        if ($label === '' && $value === '') return 0;

        $padLeft = (int) round($w * 0.020);
        $bulletSize = (int) round($h * 0.058); // diâmetro do círculo verde
        $gap = (int) round($w * 0.012);
        $padX = (int) round($w * 0.016);
        $padY = (int) round($h * 0.014);
        $radius = (int) round($h * 0.022);
        $fontSizeLabel = (int) round($h * 0.030);
        $fontSizeValue = (int) round($h * 0.026);
        $lineGap = (int) round($h * 0.005);

        $fontPath = $this->resolverFonte();
        [$wL, $hL] = $this->medirTexto($label, $fontSizeLabel, $fontPath);
        [$wV, $hV] = $value !== '' ? $this->medirTexto($value, $fontSizeValue, $fontPath) : [0, 0];

        $textW = max($wL, $wV);
        $textH = $hL + ($value !== '' ? $lineGap + $hV : 0);

        $contentH = max($bulletSize, $textH);
        $bgW = $bulletSize + $gap + $textW + ($padX * 2);
        $bgH = $contentH + ($padY * 2);
        $maxW = (int) round($w * 0.46);
        if ($bgW > $maxW) $bgW = $maxW;

        $branco = $this->corHex($img, self::COR_BRANCO);
        $verde  = $this->corHex($img, self::COR_VERDE);
        $preto  = $this->corHex($img, self::COR_PRETO);

        // Sombra muito sutil
        $sombra = imagecolorallocatealpha($img, 0, 0, 0, 110);
        $this->roundedRect($img, $padLeft + 3, $startY + 4, $bgW, $bgH, $radius, $sombra);
        // Cápsula branca
        $this->roundedRect($img, $padLeft, $startY, $bgW, $bgH, $radius, $branco);

        // Bullet verde
        $bulletCx = $padLeft + ($padX / 2) + ($bulletSize / 2);
        $bulletCy = $startY + ($bgH / 2);
        imagefilledellipse($img, (int)$bulletCx, (int)$bulletCy, $bulletSize, $bulletSize, $verde);

        // Ícone simples no centro do bullet (caractere unicode em fonte normal)
        $iconChar = $this->iconParaChar((string)($badge['icon'] ?? ''));
        if ($iconChar !== '' && $fontPath !== null) {
            $iconSize = (int) round($bulletSize * 0.55);
            [$iw, $ih] = $this->medirTexto($iconChar, $iconSize, $fontPath);
            $ix = (int) ($bulletCx - $iw / 2);
            $iy = (int) ($bulletCy + $ih / 2 - 2);
            $this->desenharTexto($img, $iconChar, $iconSize, $ix, $iy, $branco, $fontPath);
        }

        // Texto (2 linhas) à direita do bullet
        $textX = $padLeft + $bulletSize + $gap + $padX;
        $blockTopY = (int) ($startY + ($bgH - $textH) / 2);
        $textY = $blockTopY + $hL;
        $this->desenharTexto($img, $label, $fontSizeLabel, $textX, $textY, $preto, $fontPath);
        if ($value !== '') {
            $textY += $lineGap + $hV;
            $cinza = imagecolorallocate($img, 90, 100, 90); // verde escuro pra value
            $this->desenharTexto($img, $value, $fontSizeValue, $textX, $textY, $verde, $fontPath);
        }

        return $bgH;
    }

    // ==========================================================
    // BANNER vermelho urgência (bottom-left)
    // ==========================================================
    private function drawUrgentBanner($img, int $w, int $h, int $diasRestantes): void
    {
        $padLeft = (int) round($w * 0.020);
        $padBottom = (int) round($h * 0.030);
        $padX = (int) round($w * 0.020);
        $padY = (int) round($h * 0.020);
        $radius = (int) round($h * 0.018);
        $fontSize1 = (int) round($h * 0.046);
        $fontSize2 = (int) round($h * 0.024);
        $lineGap = (int) round($h * 0.005);

        $line1 = "SÓ {$diasRestantes} DIAS!";
        $line2 = "NÃO DEIXE PARA A ÚLTIMA HORA";

        $fontPath = $this->resolverFonte();
        [$w1, $h1] = $this->medirTexto($line1, $fontSize1, $fontPath);
        [$w2, $h2] = $this->medirTexto($line2, $fontSize2, $fontPath);

        // Ícone alarm clock à esquerda — usaremos um círculo branco com "⏰" ou letra
        $iconSize = (int) round($h * 0.060);
        $gap = (int) round($w * 0.014);

        $textW = max($w1, $w2);
        $textH = $h1 + $lineGap + $h2;
        $contentH = max($iconSize, $textH);
        $bgW = $iconSize + $gap + $textW + ($padX * 2);
        $bgH = $contentH + ($padY * 2);
        $maxW = (int) round($w * 0.46);
        if ($bgW > $maxW) $bgW = $maxW;

        $x = $padLeft;
        $y = $h - $padBottom - $bgH;

        $vermelho = $this->corHex($img, self::COR_VERMELHO);
        $branco   = $this->corHex($img, self::COR_BRANCO);
        $sombra   = imagecolorallocatealpha($img, 0, 0, 0, 80);

        $this->roundedRect($img, $x + 4, $y + 6, $bgW, $bgH, $radius, $sombra);
        $this->roundedRect($img, $x, $y, $bgW, $bgH, $radius, $vermelho);

        // "Ícone" — círculo branco com símbolo de relógio
        $iconCx = $x + ($padX / 2) + ($iconSize / 2);
        $iconCy = $y + ($bgH / 2);
        imagefilledellipse($img, (int)$iconCx, (int)$iconCy, $iconSize, $iconSize, $branco);
        if ($fontPath !== null) {
            // Desenha "!" grande dentro do círculo
            $charSize = (int) round($iconSize * 0.55);
            [$cw, $ch] = $this->medirTexto('!', $charSize, $fontPath);
            $cx = (int) ($iconCx - $cw / 2);
            $cy = (int) ($iconCy + $ch / 2 - 2);
            $this->desenharTexto($img, '!', $charSize, $cx, $cy, $vermelho, $fontPath);
        }

        // Textos
        $textX = $x + $iconSize + $gap + $padX;
        $blockTopY = (int) ($y + ($bgH - $textH) / 2);
        $textY = $blockTopY + $h1;
        $this->desenharTexto($img, $line1, $fontSize1, $textX, $textY, $branco, $fontPath);
        $textY += $lineGap + $h2;
        $this->desenharTexto($img, $line2, $fontSize2, $textX, $textY, $branco, $fontPath);
    }

    // ==========================================================
    // HELPERS GD
    // ==========================================================

    /** Retângulo com cantos arredondados via 4 ellipses + 2 retângulos. */
    private function roundedRect($img, int $x, int $y, int $w, int $h, int $r, int $color): void
    {
        $r = max(0, min($r, (int)floor(min($w, $h) / 2)));
        if ($r === 0) {
            imagefilledrectangle($img, $x, $y, $x + $w, $y + $h, $color);
            return;
        }
        // 4 cantos
        imagefilledellipse($img, $x + $r, $y + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x + $w - $r, $y + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x + $r, $y + $h - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x + $w - $r, $y + $h - $r, $r * 2, $r * 2, $color);
        // 2 retângulos preenchendo
        imagefilledrectangle($img, $x + $r, $y, $x + $w - $r, $y + $h, $color);
        imagefilledrectangle($img, $x, $y + $r, $x + $w, $y + $h - $r, $color);
    }

    private function corHex($img, string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return imagecolorallocate($img, $r, $g, $b);
    }

    /** Mede texto e retorna [largura, altura]. Usa TTF se disponível. */
    private function medirTexto(string $texto, int $fontSize, ?string $fontPath): array
    {
        if ($fontPath !== null && function_exists('imagettfbbox')) {
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $texto);
            return [abs($bbox[2] - $bbox[0]), abs($bbox[7] - $bbox[1])];
        }
        return [strlen($texto) * 9, 18];
    }

    /** Desenha texto na imagem (TTF se disponível, fallback imagestring). */
    private function desenharTexto($img, string $texto, int $fontSize, int $x, int $y, int $color, ?string $fontPath): void
    {
        if ($fontPath !== null && function_exists('imagettftext')) {
            imagettftext($img, $fontSize, 0, $x, $y, $color, $fontPath, $texto);
        } else {
            imagestring($img, 5, $x, $y - 18, $texto, $color);
        }
    }

    /** Procura TTF utilizável. */
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

    /** Achata canal alpha em fundo branco — necessário pra JPEG. */
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

    /** Mapeia ícone semântico pra caractere de fonte (mais portável que SVG). */
    private function iconParaChar(string $icon): string
    {
        // Caracteres simples que rendem em qualquer TTF padrão
        $map = [
            'cap'      => 'T',  // graduation/curso
            'clock'    => 'H',  // hora/duração
            'money'    => '$',  // taxa
            'check'    => 'V',  // confirmado
            'star'     => '*',  // destaque
        ];
        return $map[$icon] ?? '*';
    }
}
