<?php
/**
 * Dados dos 8 blocos universais de prompt — labels + defaults.
 * Usado por _blocos_inputs.php (renderiza form) e maquina.php (reaproveita
 * os defaults na aba Global/per-formato).
 */
$blocoLabels = [
    1 => ['Tom e voz',         'persona, linguagem'],
    2 => ['Estrutura',         'seções, formato'],
    3 => ['Regras SEO',        'keywords, otimização'],
    4 => ['Anti-IA',           'naturalidade'],
    5 => ['Extras',            'diferenciais'],
    6 => ['Intenção de busca', 'o que o usuário quer'],
    7 => ['Conversão',         'gatilhos, CTAs'],
    8 => ['Prova E-E-A-T',     'credibilidade'],
];

$blocoDefaults = [
    1 => <<<TXT
Escreva como um especialista com mais de 10 anos de experiência testando, usando e avaliando este tipo de produto.

Use linguagem natural, direta e confiável — como alguém que realmente testou, não como uma IA. Evite tom robótico ou corporativo.

Fale de forma simples, mas com autoridade. Misture explicação técnica com opinião prática.

Use expressões naturais como:
- "na prática"
- "vale a pena"
- "o que ninguém te conta"
- "pra quem quer economizar"

Evite exageros, promessas irreais e adjetivos vazios ("incrível", "simplesmente perfeito").
TXT,

    2 => <<<TXT
Estruture o artigo assim:

1. Título forte com ano atual
2. Introdução curta com gancho de curiosidade (sem enrolação)
3. Bloco rápido de decisão (sem emojis nos títulos/labels):
   - Melhor geral
   - Melhor custo-benefício
   - Melhor barato / mais em conta

4. Tabela comparativa (OBRIGATÓRIO)

5. Lista TOP 5 a 8 produtos. Cada produto com:
   - Nome (H3)
   - Nota 0-10
   - Para quem é indicado
   - Prós e contras
   - Mini review real (2-4 linhas)
   - CTA

6. Seção "Qual vale mais a pena?" com veredicto claro

7. Guia rápido de compra (curto, escaneável)

8. FAQ com dúvidas reais que as pessoas têm

9. Conclusão com recomendação direta (sem muro de texto)
TXT,

    3 => <<<TXT
Regras SEO (otimizado para Google + Discover):

- Inserir a palavra-chave principal no primeiro parágrafo
- Usar a keyword em pelo menos 2 subtítulos (H2)
- Usar variações naturais: sinônimos, long tail, perguntas reais
- Evitar keyword stuffing (repetição forçada)
- Escrever naturalmente para humanos primeiro, robôs depois
- H1 único, H2 hierárquico, H3 dentro de H2
- Títulos com números, ano e benefício quando possível
TXT,

    4 => <<<TXT
Evite qualquer cheiro de linguagem de IA.

Use:
- Frases mais humanas, com ritmo variado
- Opiniões diretas ("gostei", "não curti", "achei exagerado")
- Pequenas imperfeições naturais (frases curtas, "pra", "né")
- Perguntas retóricas: "vale mesmo a pena?", "será que compensa?"
- Comparações simples do dia a dia

Exemplo: "Na prática, ele entrega mais do que o preço sugere."

Evite frases-morte típicas de IA:
- "em suma"
- "conclui-se que"
- "é importante ressaltar"
- "no mundo atual"
- "cabe destacar"
TXT,

    5 => <<<TXT
Diferenciais que elevam o artigo:

- Considerar preços atualizados para o ano atual
- Usar "faixa de preço" em vez de valor fixo (preços variam)
- Criar seção: "O que ninguém te conta sobre [esta categoria]"
- Criar seção: "Vale esperar promoção / Black Friday?"
- Destacar limitações reais dos produtos (seja honesto — gera confiança)
- Mencionar quem NÃO deve comprar cada produto
TXT,

    6 => <<<TXT
A intenção de busca é transacional/comercial — o leitor quer COMPRAR.

Foque em:
- Comparação entre opções
- Decisão de compra
- Melhor escolha por perfil de uso

Evite conteúdo teórico, acadêmico ou histórico.

Priorize:
- Indicar o melhor para cada perfil
- Mostrar alternativas claras
- Ajudar a decidir em menos de 1 minuto
TXT,

    7 => <<<TXT
Use gatilhos de conversão com sutileza (sem parecer anúncio):

- Urgência leve: "um dos mais vendidos atualmente"
- Prova social: "bem avaliado pelos usuários", "aprovado por quem testou"
- Clareza: "vale a pena se você quer X"

CTAs (adapte à loja real):
- "Ver preço na Amazon"
- "Ver oferta na Shopee"
- "Conferir desconto agora"

Posicionar CTA:
- Após cada produto
- Uma vez no topo (depois do bloco de decisão)
- Uma vez no final

Tom natural, nunca agressivo.
TXT,

    8 => <<<TXT
Inclua elementos de credibilidade (E-E-A-T):

- "Analisamos dezenas de modelos/opções"
- "Baseado em avaliações reais de usuários"
- "Atualizado em [mês/ano atual]"

Defina critérios claros de análise, adaptados ao tipo de produto. Exemplos:
- Eletrônicos: desempenho, bateria, build, custo-benefício
- Perfumes: projeção, fixação, sillage, ocasião
- Roupas: caimento, tecido, durabilidade, preço
- Suplementos: pureza, dosagem, absorção, selo/certificação

Mostrar que existe método por trás do ranking — não é palpite.
TXT,
];
