<?php
/**
 * Gerador de artigos SEO com sistema de variações determinístico.
 *
 * Mesmo input (categoria+preco+ano) sempre gera a mesma saída — assim você pode
 * regerar 1000 páginas e nada quebra. Mas inputs diferentes geram textos
 * suficientemente distintos para evitar conteúdo duplicado.
 *
 * Estratégia anti-duplicado:
 *  - Pools de variações para cada bloco (intro, transição, descrição, CTA, conclusão)
 *  - Seleção via mt_srand($seed) onde $seed = crc32("$categoria|$preco|$ano")
 *  - Sinônimos rotativos para a categoria (celular/smartphone/aparelho)
 */
class Gerador
{
    private array $produtos;
    private string $categoria;
    private string $categoriaSing; // singular
    private int $preco;
    private int $ano;
    private int $seed;

    /** @var string[] sinônimos da categoria, descobertos heuristicamente */
    private array $sinonimos;

    public function __construct(string $categoria, int $preco, int $ano, array $produtos)
    {
        $this->categoria     = $categoria;
        $this->categoriaSing = $this->singularizar($categoria);
        $this->preco         = $preco;
        $this->ano           = $ano;
        $this->produtos      = $produtos;
        $this->seed          = crc32("$categoria|$preco|$ano");
        $this->sinonimos     = $this->descobrirSinonimos($categoria);

        mt_srand($this->seed);
    }

    public function gerar(): array
    {
        $titulo      = "Melhores {$this->categoria} até R\${$this->preco} em {$this->ano}";
        $descricao   = $this->metaDescricao();
        $introducao  = $this->introducao();
        $listaHtml   = $this->listaProdutosHtml();
        $dicasHtml   = $this->dicasHtml();
        $conclusao   = $this->conclusaoHtml();
        $faqHtml     = $this->faqHtml();
        $faqSchema   = $this->faqSchema();

        return [
            'titulo'     => $titulo,
            'descricao'  => $descricao,
            'introducao' => $introducao,
            'lista'      => $listaHtml,
            'dicas'      => $dicasHtml,
            'conclusao'  => $conclusao,
            'faq'        => $faqHtml,
            'faq_schema' => $faqSchema,
            'produtos'   => $this->produtos,
            'slug'       => $this->slug(),
        ];
    }

    public function slug(): string
    {
        $base = "melhores-{$this->categoria}-ate-{$this->preco}-em-{$this->ano}";
        return $this->slugify($base);
    }

    /* ---------- Blocos de conteúdo ---------- */

    private function metaDescricao(): string
    {
        $opcoes = [
            "Selecionamos os melhores {$this->categoria} até R\${$this->preco} em {$this->ano}. Confira preços, prós, contras e qual escolher hoje.",
            "Veja o ranking dos {$this->categoria} mais vendidos por até R\${$this->preco} em {$this->ano} — comparativo honesto e atualizado.",
            "Comparativo {$this->ano}: os melhores {$this->categoria} custo-benefício até R\${$this->preco}. Análise prática feita por quem testa.",
            "Quer comprar um bom {$this->categoriaSing} sem gastar mais que R\${$this->preco}? Reunimos as melhores opções de {$this->ano}.",
        ];
        return $this->pick($opcoes);
    }

    private function introducao(): string
    {
        $abertura = $this->pick([
            "Encontrar o {$this->categoriaSing} ideal por até R\${$this->preco} em {$this->ano} virou um desafio.",
            "O mercado de {$this->categoria} mudou muito em {$this->ano}, e por até R\${$this->preco} dá pra encontrar opções que surpreendem.",
            "Comprar {$this->categoria} bons sem estourar o orçamento de R\${$this->preco} parece difícil — mas não precisa ser.",
            "Se você tem até R\${$this->preco} pra investir em um {$this->categoriaSing} em {$this->ano}, este guia é pra você.",
        ]);

        $contexto = $this->pick([
            "Testamos, comparamos especificações e olhamos avaliações reais de quem já comprou.",
            "Reunimos os modelos mais bem avaliados, comparamos ficha técnica e preço médio atual.",
            "Cruzamos dados de lojas, opiniões de usuários e benchmarks pra montar esta lista.",
            "Garimpamos as melhores ofertas do momento e filtramos só o que realmente entrega.",
        ]);

        $promessa = $this->pick([
            "No fim, você vai saber exatamente qual escolher — sem se arrepender.",
            "A ideia é simples: te ajudar a gastar bem, não só gastar pouco.",
            "Aqui você encontra o que vale o investimento e o que é melhor evitar.",
            "Sem enrolação: vamos direto ao ponto pra você decidir hoje mesmo.",
        ]);

        $urgencia = $this->pick([
            "Os preços mudam toda semana, então confira as ofertas antes que acabem.",
            "Algumas opções desta lista estão com estoque baixo — vale conferir agora.",
            "Promoções relâmpago são comuns nessa faixa, então não deixe pra depois.",
            "As melhores ofertas costumam sumir rápido. Confira ainda hoje.",
        ]);

        return "<p>{$abertura} {$contexto}</p><p>{$promessa} {$urgencia}</p>";
    }

    private function listaProdutosHtml(): string
    {
        if (empty($this->produtos)) {
            return '<p><em>Nenhum produto encontrado para esta busca.</em></p>';
        }

        $html = '';
        $i = 0;
        foreach ($this->produtos as $p) {
            $i++;
            $titulo = htmlspecialchars($p['title'] ?? 'Produto');
            $preco  = $p['price'] ?? '';
            $img    = htmlspecialchars($p['imageUrl'] ?? $p['thumbnail'] ?? '');
            $link   = htmlspecialchars($p['link'] ?? '#');
            $fonte  = htmlspecialchars($p['source'] ?? '');

            $descricao = $this->descricaoProduto($i, $titulo);
            $cta       = $this->ctaProduto();

            $html .= <<<HTML
<article class="produto" itemscope itemtype="https://schema.org/Product">
  <h3>{$i}. <span itemprop="name">{$titulo}</span></h3>
  <div class="produto-grid">
    <img src="{$img}" alt="{$titulo}" loading="lazy" itemprop="image" width="300" height="300">
    <div class="produto-body">
      <p class="produto-desc" itemprop="description">{$descricao}</p>
      <p class="produto-preco">
        <strong>Preço:</strong>
        <span itemprop="offers" itemscope itemtype="https://schema.org/Offer">
          <span itemprop="price">{$preco}</span>
          <meta itemprop="priceCurrency" content="BRL">
          <meta itemprop="availability" content="https://schema.org/InStock">
        </span>
        <small>(em {$fonte})</small>
      </p>
      <a class="cta" href="{$link}" target="_blank" rel="nofollow sponsored noopener">{$cta}</a>
    </div>
  </div>
</article>
HTML;
        }
        return $html;
    }

    private function descricaoProduto(int $pos, string $titulo): string
    {
        $abertura = $this->pick([
            "Uma das opções mais equilibradas da nossa lista,",
            "Esse modelo chamou atenção pelo conjunto,",
            "Quem busca custo-benefício real precisa olhar este aqui:",
            "Entre os mais procurados da faixa,",
            "Um destaque que merece atenção especial:",
            "Pra quem quer qualidade sem pagar caro,",
        ]);

        $beneficio = $this->pick([
            "ele entrega desempenho consistente no dia a dia.",
            "a relação entre o que custa e o que oferece é difícil de bater.",
            "tem boas avaliações de quem realmente usa, não só de review pago.",
            "agrada tanto quem quer durabilidade quanto quem prioriza performance.",
            "vem com recursos que normalmente só aparecem em modelos mais caros.",
            "se mostra confiável em testes de longa duração.",
        ]);

        $extra = $this->pick([
            "Vale conferir antes que o preço suba.",
            "É uma escolha segura pra {$this->ano}.",
            "Um dos favoritos dos compradores recentes.",
            "Indicado pra quem não quer arriscar.",
            "Compra que dificilmente decepciona.",
        ]);

        return "{$abertura} {$beneficio} {$extra}";
    }

    private function ctaProduto(): string
    {
        return $this->pick([
            'Ver oferta atualizada →',
            'Conferir preço hoje →',
            'Ver disponibilidade →',
            'Pegar essa oferta →',
            'Ver melhor preço →',
        ]);
    }

    private function dicasHtml(): string
    {
        $titulo = $this->pick([
            'Como escolher sem errar',
            'O que olhar antes de comprar',
            'Dicas pra não cair em furada',
            'Checklist antes da compra',
        ]);

        $dicas = [
            $this->pick([
                "<strong>Defina prioridades:</strong> liste o que é essencial pra você e o que é só desejo. Isso filtra metade das opções.",
                "<strong>Saiba o que você precisa:</strong> nem todo recurso caro vai te servir. Foque no que vai usar de verdade.",
            ]),
            $this->pick([
                "<strong>Compare avaliações reais:</strong> leia comentários negativos primeiro, eles revelam mais que os elogios.",
                "<strong>Olhe quem já comprou:</strong> avaliações com fotos e detalhes valem mais que estrelas isoladas.",
            ]),
            $this->pick([
                "<strong>Cuidado com o frete:</strong> um preço baixo pode esconder um frete absurdo. Sempre cheque o total.",
                "<strong>Considere o custo total:</strong> some frete, parcelamento e garantia estendida antes de decidir.",
            ]),
            $this->pick([
                "<strong>Garantia conta:</strong> prefira lojas conhecidas com política clara de troca e devolução.",
                "<strong>Verifique a loja:</strong> reputação no Reclame Aqui e tempo de mercado evitam dor de cabeça.",
            ]),
            $this->pick([
                "<strong>Aproveite o momento:</strong> os melhores preços costumam aparecer em datas como Black Friday e Prime Day.",
                "<strong>Acompanhe o histórico:</strong> ferramentas como Zoom mostram a variação do preço — não caia em desconto falso.",
            ]),
        ];

        $lis = '<li>' . implode('</li><li>', $dicas) . '</li>';
        return "<h2>{$titulo}</h2><ul class=\"dicas\">{$lis}</ul>";
    }

    private function conclusaoHtml(): string
    {
        $titulo = $this->pick(['Conclusão', 'Veredito final', 'Resumo da nossa análise', 'Vale a pena?']);
        $texto = $this->pick([
            "Escolher um {$this->categoriaSing} até R\${$this->preco} em {$this->ano} não precisa ser uma loteria. Com as opções desta lista, você cobre praticamente todos os perfis — do mais básico ao quase intermediário.",
            "Por até R\${$this->preco} dá pra levar um {$this->categoriaSing} muito acima do que se imagina. Os modelos selecionados aqui já provaram que entregam o que prometem.",
            "Em {$this->ano}, a faixa de R\${$this->preco} ficou competitiva. As opções listadas equilibram preço, qualidade e durabilidade de forma honesta.",
            "Se você chegou até aqui, já tem informação suficiente pra decidir. Qualquer uma das opções acima é uma compra inteligente dentro do orçamento.",
        ]);
        $cta = $this->pick([
            "Aproveite enquanto os preços ainda estão nesse patamar — eles raramente ficam parados por muito tempo.",
            "Não esqueça: estoque baixo = preço subindo. Quem espera demais paga mais caro.",
            "A melhor compra é a que cabe no bolso e atende suas necessidades. Boa escolha!",
        ]);
        return "<h2>{$titulo}</h2><p>{$texto}</p><p>{$cta}</p>";
    }

    private function faqHtml(): string
    {
        $perguntas = $this->faqPerguntas();
        $html = '<h2>Perguntas frequentes</h2><div class="faq">';
        foreach ($perguntas as $q) {
            $html .= "<details><summary>{$q['p']}</summary><p>{$q['r']}</p></details>";
        }
        $html .= '</div>';
        return $html;
    }

    private function faqSchema(): string
    {
        $perguntas = $this->faqPerguntas();
        $items = [];
        foreach ($perguntas as $q) {
            $items[] = [
                '@type' => 'Question',
                'name'  => $q['p'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $q['r'],
                ],
            ];
        }
        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $items,
        ];
        return json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function faqPerguntas(): array
    {
        return [
            [
                'p' => "Vale a pena comprar {$this->categoria} por até R\${$this->preco}?",
                'r' => "Sim. Em {$this->ano}, essa faixa de preço já entrega opções equilibradas de marcas conhecidas, com boa durabilidade e desempenho satisfatório pro uso comum.",
            ],
            [
                'p' => "Qual é o melhor {$this->categoriaSing} dessa faixa?",
                'r' => "Depende do seu uso. A nossa lista cobre os perfis mais comuns — do mais básico ao mais completo dentro do orçamento de R\${$this->preco}.",
            ],
            [
                'p' => "Onde encontrar os melhores preços?",
                'r' => "Lojas grandes como as listadas costumam ter as melhores ofertas, especialmente em datas promocionais. Sempre compare antes de fechar a compra.",
            ],
            [
                'p' => "Como saber se a loja é confiável?",
                'r' => "Verifique reputação no Reclame Aqui, tempo de mercado, política de troca e se aceita meios de pagamento seguros.",
            ],
        ];
    }

    /* ---------- Utilidades ---------- */

    private function pick(array $opcoes): string
    {
        return $opcoes[mt_rand(0, count($opcoes) - 1)];
    }

    private function singularizar(string $palavra): string
    {
        // Heurística simples PT-BR
        if (preg_match('/ões$/u', $palavra)) return preg_replace('/ões$/u', 'ão', $palavra);
        if (preg_match('/ais$/u', $palavra)) return preg_replace('/ais$/u', 'al', $palavra);
        if (preg_match('/eis$/u', $palavra)) return preg_replace('/eis$/u', 'el', $palavra);
        if (preg_match('/ns$/u', $palavra))  return preg_replace('/ns$/u', 'm', $palavra);
        if (preg_match('/s$/u', $palavra) && !preg_match('/[aeiou]s$/u', $palavra)) {
            return preg_replace('/s$/u', '', $palavra);
        }
        return rtrim($palavra, 's');
    }

    private function descobrirSinonimos(string $cat): array
    {
        $map = [
            'celular'    => ['celular', 'smartphone', 'aparelho'],
            'celulares'  => ['celulares', 'smartphones', 'aparelhos'],
            'notebook'   => ['notebook', 'laptop', 'computador portátil'],
            'notebooks'  => ['notebooks', 'laptops'],
            'perfume'    => ['perfume', 'fragrância'],
            'perfumes'   => ['perfumes', 'fragrâncias'],
        ];
        $key = mb_strtolower($cat);
        return $map[$key] ?? [$cat];
    }

    private function slugify(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[áàãâä]/u', 'a', $s);
        $s = preg_replace('/[éèêë]/u', 'e', $s);
        $s = preg_replace('/[íìîï]/u', 'i', $s);
        $s = preg_replace('/[óòõôö]/u', 'o', $s);
        $s = preg_replace('/[úùûü]/u', 'u', $s);
        $s = preg_replace('/[ç]/u', 'c', $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    }
}
