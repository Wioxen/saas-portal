<?php
declare(strict_types=1);

require_once __DIR__ . '/JogosCalendario.php';

/**
 * JogoClusterLinker — agrupa posts de um mesmo jogo num cluster temático.
 *
 * Engenharia:
 * 1. Cada jogo (jogos_vitoria.json) tem posts_gerados[pre_jogo, pos_jogo, ...]
 * 2. Quando um novo post é publicado, o linker:
 *    a. Injeta bloco "Mais sobre <jogo>" apontando pros outros posts do cluster
 *    b. Injeta Schema isPartOf no Article schema do post
 *    c. Re-injeta o bloco nos posts irmãos (que estavam sem o link novo)
 * 3. Cluster vira UMA UNIDADE TEMÁTICA pro Google → autoridade tópica.
 *
 * Tipos suportados (em ordem editorial):
 *   pre_jogo        → "Onde assistir + escalação provável" (D-2)
 *   preview_tatico  → "Como Vitória pode jogar / lições do último jogo" (D-1, opcional)
 *   pos_jogo        → "Resultado + gols + ficha técnica" (T+30min)
 *   analise_pos     → "Análise tática + estatísticas" (D+1, opcional)
 *   repercussao     → "Coletiva + reações torcida" (D+1, opcional)
 */
class JogoClusterLinker
{
    private const TIPO_LABELS = [
        'pre_jogo'       => 'Pré-jogo: onde assistir e escalação',
        'preview_tatico' => 'Preview tático',
        'pos_jogo'       => 'Pós-jogo: resultado e ficha técnica',
        'analise_pos'    => 'Análise pós-jogo',
        'repercussao'    => 'Repercussão',
    ];

    private const ORDEM_EDITORIAL = ['pre_jogo', 'preview_tatico', 'pos_jogo', 'analise_pos', 'repercussao'];

    private const MARKER_OPEN = '<!-- cluster-jogo-links:open -->';
    private const MARKER_CLOSE = '<!-- cluster-jogo-links:close -->';

    private JogosCalendario $cal;
    private string $jsonPath;

    public function __construct(string $jsonPath)
    {
        $this->jsonPath = $jsonPath;
        $this->cal = new JogosCalendario($jsonPath);
    }

    /**
     * Injeta bloco de links + Schema isPartOf no HTML do post.
     *
     * @param array $jogo dados do jogo
     * @param string $tipoAtual tipo do post atual (pre_jogo, pos_jogo, etc)
     * @param string $html HTML cru do post atual (pre-publish)
     * @param Wordpress $wp instância pra resolver links dos irmãos
     * @return string HTML modificado
     */
    public function injetarNoPost(array $jogo, string $tipoAtual, string $html, $wp): string
    {
        $irmaos = $this->coletarIrmaos($jogo, $tipoAtual, $wp);
        if (empty($irmaos)) return $html; // primeiro post do cluster

        $bloco = $this->renderizarBloco($jogo, $tipoAtual, $irmaos);
        $schemaPatch = $this->renderizarIsPartOf($jogo, $irmaos);

        // Remove bloco anterior se existir (idempotência)
        $html = preg_replace(
            '/' . preg_quote(self::MARKER_OPEN, '/') . '.*?' . preg_quote(self::MARKER_CLOSE, '/') . '/s',
            '',
            $html
        ) ?? $html;

        // Injeta antes do </script> da BroadcastEvent (se houver) ou no fim
        if (preg_match('/<script[^>]*data-broadcast-event/', $html)) {
            $html = preg_replace(
                '/(<script[^>]*data-broadcast-event)/',
                $bloco . "\n" . $schemaPatch . "\n$1",
                $html,
                1
            ) ?? $html;
        } else {
            $html .= "\n" . $bloco . "\n" . $schemaPatch;
        }

        return $html;
    }

    /**
     * Após o post atual ter sido publicado, atualiza os IRMÃOS pra incluir link pro novo.
     * Roda em best-effort: erros não impedem o flow principal.
     *
     * @param array $jogo
     * @param string $tipoAtual tipo recém-publicado
     * @param int $postIdAtual ID do post recém-publicado
     * @param Wordpress $wp
     * @return array ['atualizados' => int, 'erros' => array]
     */
    public function backfillIrmaos(array $jogo, string $tipoAtual, int $postIdAtual, $wp): array
    {
        $atualizados = 0;
        $erros = [];

        $postsGerados = $jogo['posts_gerados'] ?? [];
        // Adiciona o atual ao conjunto (caso ainda não tenha sido persistido)
        $postsGerados[$tipoAtual] = $postIdAtual;

        foreach ($postsGerados as $tipoIrmao => $idIrmao) {
            if ($tipoIrmao === $tipoAtual) continue;
            if (empty($idIrmao) || (int)$idIrmao <= 0) continue;

            try {
                $postIrmao = $wp->getPost((int)$idIrmao);
                $htmlAtual = $postIrmao['content']['raw'] ?? '';
                if ($htmlAtual === '') continue;

                $jogoComAtual = $jogo;
                $jogoComAtual['posts_gerados'] = $postsGerados;

                $novoHtml = $this->injetarNoPost($jogoComAtual, $tipoIrmao, $htmlAtual, $wp);
                if ($novoHtml !== $htmlAtual) {
                    $wp->atualizarPost((int)$idIrmao, ['content' => $novoHtml]);
                    $atualizados++;
                }
            } catch (Throwable $e) {
                $erros[] = "post {$idIrmao}: " . $e->getMessage();
            }
        }

        return ['atualizados' => $atualizados, 'erros' => $erros];
    }

    /** Coleta dados (id, link, tipo, label) dos posts irmãos publicados. */
    private function coletarIrmaos(array $jogo, string $tipoAtual, $wp): array
    {
        $irmaos = [];
        $postsGerados = $jogo['posts_gerados'] ?? [];
        foreach (self::ORDEM_EDITORIAL as $tipo) {
            if ($tipo === $tipoAtual) continue;
            $id = (int)($postsGerados[$tipo] ?? 0);
            if ($id <= 0) continue;
            try {
                $p = $wp->getPost($id);
                $link = $p['link'] ?? '';
                $titulo = $p['title']['rendered'] ?? $p['title']['raw'] ?? '';
                if ($link === '' || $titulo === '') continue;
                $irmaos[] = [
                    'tipo' => $tipo,
                    'id' => $id,
                    'link' => $link,
                    'titulo' => html_entity_decode((string)$titulo, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'label' => self::TIPO_LABELS[$tipo] ?? $tipo,
                ];
            } catch (Throwable $e) {
                // post deletado/trash — ignora
            }
        }
        return $irmaos;
    }

    private function renderizarBloco(array $jogo, string $tipoAtual, array $irmaos): string
    {
        $advNome = $jogo['adversario']['nome'] ?? 'Adversário';
        $confronto = ($jogo['mando'] ?? '') === 'casa'
            ? "Vitória x {$advNome}"
            : "{$advNome} x Vitória";

        $html = self::MARKER_OPEN . "\n";
        $html .= "<aside class='cluster-jogo-links' aria-label='Mais conteúdo sobre {$confronto}'>\n";
        $html .= "  <h2>Mais sobre {$confronto}</h2>\n";
        $html .= "  <ul>\n";
        foreach ($irmaos as $irmao) {
            $titulo = htmlspecialchars($irmao['titulo'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $link = htmlspecialchars($irmao['link'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = htmlspecialchars($irmao['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html .= "    <li><strong>{$label}:</strong> <a href='{$link}'>{$titulo}</a></li>\n";
        }
        $html .= "  </ul>\n";
        $html .= "</aside>\n";
        $html .= self::MARKER_CLOSE;
        return $html;
    }

    /** Schema isPartOf apontando pros irmãos (relacionamento de cluster). */
    private function renderizarIsPartOf(array $jogo, array $irmaos): string
    {
        $advNome = $jogo['adversario']['nome'] ?? 'Adversário';
        $mandoCasa = ($jogo['mando'] ?? '') === 'casa';
        $homeName = $mandoCasa ? 'Esporte Clube Vitória' : $advNome;
        $awayName = $mandoCasa ? $advNome : 'Esporte Clube Vitória';

        $hasParts = [];
        foreach ($irmaos as $irmao) {
            $hasParts[] = [
                '@type' => 'NewsArticle',
                'name' => $irmao['titulo'],
                'url' => $irmao['link'],
            ];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Series',
            'name' => "Cobertura completa: {$homeName} x {$awayName} — " . ($jogo['competicao'] ?? ''),
            'description' => "Conjunto de matérias do Leão da Barra sobre o confronto, do pré-jogo à análise pós-jogo.",
            'hasPart' => $hasParts,
        ];

        return "\n<script type=\"application/ld+json\" data-cluster-jogo=\"1\">\n"
             . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
             . "\n</script>\n";
    }
}
