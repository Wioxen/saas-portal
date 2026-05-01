<?php
/**
 * Gerador de slides de carrossel do Instagram em PHP GD.
 * Formato 1080x1350 JPG (4:5, padrão carrossel IG).
 *
 * Não depende de Playwright/headless browser — desenha direto com imagettftext.
 * Inspirado em skillinsta.md mas adaptado para pipeline headless.
 */
class CarrosselGenerator
{
    private int $width = 1080;
    private int $height = 1350;
    private string $outputDir;
    private string $fontRegular;
    private string $fontBold;
    private array $brand;

    /**
     * @param string $outputDir Pasta onde salvar os JPGs (ex: sys_get_temp_dir())
     * @param array  $brand     ['name'=>'Site', 'primary'=>'#0ea5e9', 'handle'=>'@site']
     */
    public function __construct(string $outputDir, array $brand = [])
    {
        if (!is_dir($outputDir)) @mkdir($outputDir, 0777, true);
        $this->outputDir = rtrim($outputDir, '/\\');

        // Fontes do sistema — Windows (XAMPP) ou Linux
        $candidatosRegular = [
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/calibri.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
        ];
        $candidatosBold = [
            'C:/Windows/Fonts/arialbd.ttf',
            'C:/Windows/Fonts/calibrib.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
        ];
        $this->fontRegular = $this->acharFonte($candidatosRegular);
        $this->fontBold    = $this->acharFonte($candidatosBold) ?: $this->fontRegular;

        $this->brand = array_merge([
            'name'    => 'Site',
            'handle'  => '',
            'primary' => '#0ea5e9',
        ], $brand);
    }

    private function acharFonte(array $cands): string
    {
        foreach ($cands as $p) { if (file_exists($p)) return $p; }
        throw new RuntimeException('Nenhuma fonte TTF encontrada no sistema. Instale DejaVu ou use Windows.');
    }

    /**
     * Gera o carrossel inteiro.
     * @param array $slides Array de {type, title, body?}. type: hero|topic|cta
     * @return array Array de paths absolutos dos JPGs gerados
     */
    public function gerar(array $slides): array
    {
        $paths = [];
        $total = count($slides);
        foreach ($slides as $i => $slide) {
            $type  = $slide['type'] ?? 'topic';
            $light = ($i % 2 === 0); // alternância
            $img = imagecreatetruecolor($this->width, $this->height);
            imageantialias($img, true);

            if ($type === 'cta') {
                $this->desenharFundoGradient($img);
                $this->desenharSlideCta($img, $slide, $i + 1, $total);
            } elseif ($type === 'hero') {
                $this->desenharFundo($img, $light);
                $this->desenharSlideHero($img, $slide, $i + 1, $total);
            } else {
                $this->desenharFundo($img, $light);
                $this->desenharSlideTopic($img, $slide, $i + 1, $total, $light);
            }

            $path = $this->outputDir . DIRECTORY_SEPARATOR . 'slide_' . uniqid() . '_' . ($i + 1) . '.jpg';
            imagejpeg($img, $path, 92);
            imagedestroy($img);
            $paths[] = $path;
        }
        return $paths;
    }

    private function desenharFundo($img, bool $light): void
    {
        $cor = $light ? [250, 248, 245] : [20, 20, 25];
        $c = imagecolorallocate($img, ...$cor);
        imagefilledrectangle($img, 0, 0, $this->width, $this->height, $c);
    }

    private function desenharFundoGradient($img): void
    {
        [$r, $g, $b] = $this->hex2rgb($this->brand['primary']);
        // Gradient diagonal — escurece de cima pra baixo
        for ($y = 0; $y < $this->height; $y++) {
            $t = $y / $this->height;
            $rr = (int)($r * (1 - $t * 0.5));
            $gg = (int)($g * (1 - $t * 0.5));
            $bb = (int)($b * (1 - $t * 0.5));
            $c = imagecolorallocate($img, $rr, $gg, $bb);
            imageline($img, 0, $y, $this->width, $y, $c);
        }
    }

    private function desenharSlideHero($img, array $slide, int $idx, int $total): void
    {
        $light = ($idx - 1) % 2 === 0;
        $fg = $light ? imagecolorallocate($img, 15, 18, 25) : imagecolorallocate($img, 250, 248, 245);
        $accent = imagecolorallocate($img, ...$this->hex2rgb($this->brand['primary']));
        $muted = $light ? imagecolorallocate($img, 120, 120, 128) : imagecolorallocate($img, 160, 160, 170);

        // Tag no topo
        $this->escreverTexto($img, mb_strtoupper($this->brand['name'], 'UTF-8'), 80, 120, 22, $this->fontBold, $accent);

        // Título grande centralizado vertical
        $titulo = $slide['title'] ?? '';
        $linhas = $this->quebrarTexto($titulo, $this->fontBold, 72, $this->width - 160);
        $y = 400;
        foreach ($linhas as $l) {
            $this->escreverTexto($img, $l, 80, $y, 72, $this->fontBold, $fg);
            $y += 90;
        }

        // Subtítulo/hook
        if (!empty($slide['body'])) {
            $sublinhas = $this->quebrarTexto($slide['body'], $this->fontRegular, 32, $this->width - 160);
            $y += 30;
            foreach (array_slice($sublinhas, 0, 3) as $l) {
                $this->escreverTexto($img, $l, 80, $y, 32, $this->fontRegular, $muted);
                $y += 44;
            }
        }

        // Arrow "arraste" no canto inferior direito
        $arrowCor = $light ? imagecolorallocate($img, 80, 80, 90) : imagecolorallocate($img, 200, 200, 210);
        $this->escreverTexto($img, 'arraste  →', $this->width - 260, $this->height - 100, 26, $this->fontBold, $arrowCor);

        $this->desenharProgress($img, $idx, $total, $light);
    }

    private function desenharSlideTopic($img, array $slide, int $idx, int $total, bool $light): void
    {
        $fg = $light ? imagecolorallocate($img, 15, 18, 25) : imagecolorallocate($img, 250, 248, 245);
        $accent = imagecolorallocate($img, ...$this->hex2rgb($this->brand['primary']));
        $muted = $light ? imagecolorallocate($img, 90, 90, 100) : imagecolorallocate($img, 170, 170, 180);

        // Número grande no topo esquerdo
        $num = sprintf('%02d', $idx - 1);
        $this->escreverTexto($img, $num, 80, 170, 140, $this->fontBold, $accent);

        // Título
        $titulo = $slide['title'] ?? '';
        $linhas = $this->quebrarTexto($titulo, $this->fontBold, 58, $this->width - 160);
        $y = 360;
        foreach ($linhas as $l) {
            $this->escreverTexto($img, $l, 80, $y, 58, $this->fontBold, $fg);
            $y += 76;
        }

        // Corpo
        $y += 40;
        if (!empty($slide['body'])) {
            $sublinhas = $this->quebrarTexto($slide['body'], $this->fontRegular, 34, $this->width - 160);
            foreach (array_slice($sublinhas, 0, 10) as $l) {
                $this->escreverTexto($img, $l, 80, $y, 34, $this->fontRegular, $muted);
                $y += 48;
                if ($y > $this->height - 200) break;
            }
        }

        // Arrow
        $arrowCor = $light ? imagecolorallocate($img, 80, 80, 90) : imagecolorallocate($img, 200, 200, 210);
        $this->escreverTexto($img, '→', $this->width - 130, $this->height - 100, 48, $this->fontBold, $arrowCor);

        $this->desenharProgress($img, $idx, $total, $light);
    }

    private function desenharSlideCta($img, array $slide, int $idx, int $total): void
    {
        $fg = imagecolorallocate($img, 255, 255, 255);
        $muted = imagecolorallocate($img, 230, 230, 240);

        // Título centralizado
        $titulo = $slide['title'] ?? 'Quer saber mais?';
        $linhas = $this->quebrarTexto($titulo, $this->fontBold, 68, $this->width - 160);
        $y = 500;
        foreach ($linhas as $l) {
            $this->escreverTexto($img, $l, 80, $y, 68, $this->fontBold, $fg);
            $y += 86;
        }

        // Body
        if (!empty($slide['body'])) {
            $sublinhas = $this->quebrarTexto($slide['body'], $this->fontRegular, 34, $this->width - 160);
            $y += 40;
            foreach (array_slice($sublinhas, 0, 3) as $l) {
                $this->escreverTexto($img, $l, 80, $y, 34, $this->fontRegular, $muted);
                $y += 48;
            }
        }

        // Brand no rodapé
        $this->escreverTexto($img, mb_strtoupper($this->brand['name'], 'UTF-8'), 80, $this->height - 130, 30, $this->fontBold, $fg);
        if (!empty($this->brand['handle'])) {
            $this->escreverTexto($img, $this->brand['handle'], 80, $this->height - 90, 24, $this->fontRegular, $muted);
        }

        $this->desenharProgress($img, $idx, $total, false);
    }

    private function desenharProgress($img, int $idx, int $total, bool $light): void
    {
        $trackCor = $light ? imagecolorallocate($img, 220, 220, 225) : imagecolorallocate($img, 60, 60, 70);
        $fillCor  = imagecolorallocate($img, ...$this->hex2rgb($this->brand['primary']));
        $textCor  = $light ? imagecolorallocate($img, 120, 120, 128) : imagecolorallocate($img, 170, 170, 180);

        $barY = $this->height - 60;
        $barX1 = 80; $barX2 = $this->width - 180;
        imagefilledrectangle($img, $barX1, $barY, $barX2, $barY + 4, $trackCor);
        $fillW = (int)(($barX2 - $barX1) * ($idx / $total));
        imagefilledrectangle($img, $barX1, $barY, $barX1 + $fillW, $barY + 4, $fillCor);

        $this->escreverTexto($img, "{$idx}/{$total}", $this->width - 150, $barY + 20, 22, $this->fontBold, $textCor);
    }

    /** Escreve texto com TTF (GD). Coordenada y é baseline. */
    private function escreverTexto($img, string $texto, int $x, int $y, int $size, string $font, int $color): void
    {
        if (!function_exists('imagettftext')) return;
        // imagettftext recebe y como baseline, compensamos pro topo
        imagettftext($img, $size, 0, $x, $y + $size, $color, $font, $texto);
    }

    /** Quebra texto em linhas para caber em uma largura máxima. */
    private function quebrarTexto(string $texto, string $font, int $size, int $maxW): array
    {
        $texto = trim($texto);
        if ($texto === '') return [];
        $palavras = preg_split('/\s+/', $texto);
        $linhas = [];
        $atual = '';
        foreach ($palavras as $p) {
            $teste = $atual === '' ? $p : $atual . ' ' . $p;
            $box = imagettfbbox($size, 0, $font, $teste);
            $w = $box[2] - $box[0];
            if ($w > $maxW && $atual !== '') {
                $linhas[] = $atual;
                $atual = $p;
            } else {
                $atual = $teste;
            }
        }
        if ($atual !== '') $linhas[] = $atual;
        return $linhas;
    }

    /**
     * Gera slides FOTOGRÁFICOS: imagem featured como fundo em todos os slides,
     * overlay bottom gradient (transparente → preto), texto bold CAIXA ALTA em cima.
     *
     * @param array  $slides      Array de {title, body?} — type é ignorado (todos usam mesmo layout)
     * @param string $bgImagePath Caminho LOCAL da imagem JPG/PNG pra usar como fundo
     * @return array              Paths absolutos dos JPGs gerados
     */
    public function gerarFotografico(array $slides, string $bgImagePath): array
    {
        if (!file_exists($bgImagePath)) {
            throw new RuntimeException("Imagem de fundo não encontrada: {$bgImagePath}");
        }
        $bg = $this->carregarImagem($bgImagePath);
        if (!$bg) throw new RuntimeException('Falha ao carregar imagem de fundo: ' . $bgImagePath);

        $paths = [];
        $total = count($slides);

        foreach ($slides as $i => $slide) {
            $idx = $i + 1;
            $img = imagecreatetruecolor($this->width, $this->height);
            imageantialias($img, true);

            // 1. Fundo: redimensiona e centraliza a featured image preenchendo todo o slide (cover)
            $this->desenharFundoFotografico($img, $bg);

            // 2. Overlay bottom gradient (~60% da altura): transparente no topo, preto sólido embaixo
            $this->desenharBottomGradient($img);

            // 3. Texto bold CAIXA ALTA + indicador de slide
            $this->desenharTextoFotografico($img, $slide, $idx, $total);

            $path = $this->outputDir . DIRECTORY_SEPARATOR . 'foto_' . uniqid() . '_' . $idx . '.jpg';
            imagejpeg($img, $path, 92);
            imagedestroy($img);
            $paths[] = $path;
        }

        imagedestroy($bg);
        return $paths;
    }

    private function carregarImagem(string $path)
    {
        $info = @getimagesize($path);
        if (!$info) return null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG: return @imagecreatefromjpeg($path);
            case IMAGETYPE_PNG:  return @imagecreatefrompng($path);
            case IMAGETYPE_WEBP: return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
            case IMAGETYPE_GIF:  return @imagecreatefromgif($path);
        }
        return null;
    }

    /** Desenha imagem de fundo em cover mode (preenche todo o canvas mantendo proporção). */
    private function desenharFundoFotografico($img, $bg): void
    {
        $bgW = imagesx($bg);
        $bgH = imagesy($bg);
        $ratioCanvas = $this->width / $this->height;
        $ratioBg = $bgW / $bgH;

        if ($ratioBg > $ratioCanvas) {
            // BG mais larga: ajusta pela altura, corta lateral
            $newH = $this->height;
            $newW = (int)($bgW * ($this->height / $bgH));
            $dstX = (int)(($this->width - $newW) / 2);
            $dstY = 0;
        } else {
            // BG mais alta: ajusta pela largura, corta em cima/embaixo
            $newW = $this->width;
            $newH = (int)($bgH * ($this->width / $bgW));
            $dstX = 0;
            $dstY = (int)(($this->height - $newH) / 2);
        }
        imagecopyresampled($img, $bg, $dstX, $dstY, 0, 0, $newW, $newH, $bgW, $bgH);
    }

    /** Overlay gradient de preto transparente (topo) pra preto 90% (base) cobrindo ~65% inferior. */
    private function desenharBottomGradient($img): void
    {
        $gradStartY = (int)($this->height * 0.35); // começa em 35% da altura
        $gradEndY = $this->height;
        $gradH = $gradEndY - $gradStartY;

        for ($y = $gradStartY; $y < $gradEndY; $y++) {
            $t = ($y - $gradStartY) / $gradH; // 0.0 → 1.0
            // Alpha: 0 (transparente) → 100 (escala GD é 0-127 onde 0=opaco, 127=transparente)
            // Queremos: topo do gradient transparente (127), base opaco (quase 0)
            $alpha = (int)(127 * (1 - $t * 0.95)); // min ~6 (quase opaco), max 127
            $c = imagecolorallocatealpha($img, 0, 0, 0, $alpha);
            imagefilledrectangle($img, 0, $y, $this->width, $y, $c);
        }

        // Reforço: faixa inferior totalmente opaca pros últimos 10% pra legibilidade
        $opaco = imagecolorallocatealpha($img, 0, 0, 0, 40); // 40 = levemente transparente pro gradient ficar suave
        imagefilledrectangle($img, 0, (int)($this->height * 0.90), $this->width, $this->height, $opaco);
    }

    /** Texto bold CAIXA ALTA na parte inferior (sobre o gradient) + tag de slide no topo. */
    private function desenharTextoFotografico($img, array $slide, int $idx, int $total): void
    {
        $branco = imagecolorallocate($img, 255, 255, 255);
        $accent = imagecolorallocate($img, ...$this->hex2rgb($this->brand['primary']));
        $sombra = imagecolorallocatealpha($img, 0, 0, 0, 20);

        // Indicador "1 / 7" no topo direito
        $indicador = $idx . ' / ' . $total;
        $box = imagettfbbox(26, 0, $this->fontBold, $indicador);
        $wInd = $box[2] - $box[0];
        $this->escreverTexto($img, $indicador, $this->width - $wInd - 60, 50, 26, $this->fontBold, $branco);

        // Tag brand no topo esquerdo
        if (!empty($this->brand['name'])) {
            $nomeTag = mb_strtoupper($this->brand['name'], 'UTF-8');
            $this->escreverTexto($img, $nomeTag, 60, 50, 24, $this->fontBold, $accent);
        }

        // Texto principal: sempre em CAIXA ALTA, bold, ~60 pontos, quebra linhas, ancorado ao fundo
        $titulo = mb_strtoupper(trim($slide['title'] ?? ''), 'UTF-8');
        if ($titulo === '') return;

        $fontSize = 58;
        $lineH = 74;
        $margin = 60;
        $linhas = $this->quebrarTexto($titulo, $this->fontBold, $fontSize, $this->width - ($margin * 2));

        // Se muitas linhas, reduz font gradualmente
        while (count($linhas) > 6 && $fontSize > 40) {
            $fontSize -= 4;
            $lineH = (int)($fontSize * 1.28);
            $linhas = $this->quebrarTexto($titulo, $this->fontBold, $fontSize, $this->width - ($margin * 2));
        }

        // Calcula Y inicial: ancora texto na base, deixando ~120px de margem inferior
        $totalTextoH = count($linhas) * $lineH;
        $baseY = $this->height - 120;
        $y = $baseY - $totalTextoH;

        foreach ($linhas as $l) {
            // Sombra sutil atrás do texto pra legibilidade extra
            $this->escreverTexto($img, $l, $margin + 2, $y + 2, $fontSize, $this->fontBold, $sombra);
            $this->escreverTexto($img, $l, $margin, $y, $fontSize, $this->fontBold, $branco);
            $y += $lineH;
        }

        // Body opcional (subtítulo/frase curta) em fonte menor, ainda CAIXA ALTA, cor accent
        if (!empty($slide['body'])) {
            $body = mb_strtoupper(trim($slide['body']), 'UTF-8');
            $bodyLinhas = $this->quebrarTexto($body, $this->fontRegular, 26, $this->width - ($margin * 2));
            $yBody = $baseY + 18;
            foreach (array_slice($bodyLinhas, 0, 2) as $l) {
                $this->escreverTexto($img, $l, $margin, $yBody, 26, $this->fontRegular, $accent);
                $yBody += 36;
            }
        }
    }

    private function hex2rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
