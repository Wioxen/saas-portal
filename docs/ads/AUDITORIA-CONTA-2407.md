# Auditoria da conta Google Ads "Sistema 1" — 24/07/2026

Levantada direto no painel. O que está aqui foi **visto**; onde não consegui confirmar, está dito.

## 1. 🔴 Os orçamentos não são guardas de nada

Dez campanhas, todas com indicador verde de ativa, somando **R$ 246.330,53/dia** de orçamento:

| Campanha | Orçamento/dia | Tipo |
|---|---|---|
| Auto_01_s1_Pouso | R$ 180.435,49 | Pesquisa |
| Auto_01_s1_Pouso_AN | R$ 20.435,49 | Pesquisa |
| Auto_01_s1_Pouso #2 | R$ 20.435,49 | Pesquisa |
| Beneficio - PeDeMeia | R$ 10.000,00 | Pesquisa |
| Leads-Display-4 | R$ 10.000,00 | **Display** |
| Aux Gas e Emergencial | R$ 730,00 | Pesquisa |
| Beneficio - jornada do estudante | R$ 730,00 | Pesquisa |
| Concurso | R$ 450,00 | Pesquisa |
| Vaga de Emprego | R$ 350,00 | Pesquisa |
| Prouni | R$ 180,00 | Pesquisa |

Mais **4 rascunhos em andamento**.

**O gasto real é minúsculo perto disso.** Pagamentos de 2026 na conta: janeiro R$ 0, fevereiro
R$ 280, março R$ 240, abril R$ 520, maio R$ 40, junho R$ 560, julho R$ 40. Ou seja: as campanhas
não gastam o orçamento porque são limitadas por volume/lance, não pelo teto.

O problema não é o gasto de hoje, é a **ausência de freio**. Um orçamento de R$ 180 mil/dia não
limita nada. Se qualquer coisa mudar — uma correspondência ampla nova, um lance automático que
resolve subir, uma keyword de cauda que engata — não existe nenhum limite prático segurando.

**Consequência direta para o plano das 3 campanhas novas:** em conta pré-paga o saldo é
**da conta, não da campanha**. Ao adicionar os ~R$ 1.050 do teste, essas 10 campanhas ativas
competem pelo mesmo dinheiro e podem consumir parte antes das novas verem tráfego.

> **Recomendação:** antes de adicionar saldo, pausar o que não está em uso — no mínimo as três
> `Auto_01_s1_Pouso*` (R$ 221 mil/dia somadas, nomes de automação, sem dono claro) e a
> `Leads-Display-4`. O que ficar ativo, colocar um orçamento condizente com o que de fato se
> pretende gastar. Isso é decisão sua; não mexi em nada.

## 2. 🟡 Uma campanha de Display dividindo o mesmo caixa

`Leads-Display-4`, R$ 10.000/dia. Display entrega impressão barata e clique de baixa intenção;
num caixa pré-papago compartilhado, é a campanha com maior potencial de drenar saldo sem
retorno mensurável. Se ela não tem meta ativa e medida agora, o lugar dela é pausada enquanto
o teste de afiliado roda.

## 3. 🟡 Não foi possível auditar por dentro

A interface do Ads congelou o renderer três vezes durante o levantamento (mesmo sintoma já
registrado em sessões anteriores nessa tela). **Não confirmei**, por isso, dentro de cada
campanha existente: correspondências usadas, cobertura de negativas, quantidade de títulos por
RSA, extensões ativas, Ad Strength e eventuais reprovações de política.

O caminho confiável para isso é **download de relatório** (o mesmo padrão que já funcionou no
Planejador de Palavras-chave): Campanhas → Download → CSV, e o relatório de termos de pesquisa.
Peça e eu analiso os arquivos — o analisador `scripts/_ads_kw_analyze.php` já lê esse formato.

## 4. O que muda nas 3 campanhas novas (aplicado)

A auditoria revelou lacunas no que eu mesmo tinha gerado. Corrigidas:

### 4.1 RSA no tamanho certo
Estavam com 4 a 8 títulos e 2 a 3 descrições. Agora **12 títulos e 4 descrições** nos grupos que
sobem ligados, **8 e 4** nos pausados. Mais ativos = mais combinações que o Google testa = mais
CTR, e é o que leva o Ad Strength para "Excelente". **Sem fixar posição (pinning)**: fixar título
corta as combinações possíveis e costuma derrubar o CTR justamente onde se queria controlar.

### 4.2 Extensões — a maior alavanca de CTR que estava faltando
Não havia nenhuma. Extensão aumenta a área ocupada no resultado e o CTR sem custo adicional.
Adicionados por campanha: **4 sitelinks com descrição**, **6 frases de destaque**, **1 snippet
estruturado**.

### 4.3 Diversidade de títulos
Títulos que repetem a mesma ideia fazem o Google descartar combinações. Cada grupo agora cobre
ângulos distintos: keyword exata, benefício, objeção, prova, comparação, ocasião e CTA.

## 5. Política — o que foi ajustado para não tomar reprovação

O histórico da rede já tem duas reprovações automáticas (campanha de manutenção de celular,
14/07), então vale ser conservador. Um lint de política roda agora dentro do gerador e **aborta**
se algum termo proibido voltar:

- **Superlativo puro** ("o melhor") só se a página sustentar com dado de terceiro. As LPs mostram
  nota e volume de avaliações da Amazon, o que sustenta — mas reduzi a dependência disso e troquei
  boa parte por "os mais avaliados", "comparativo", "qual comprar", que são verificáveis e não
  dependem de interpretação do revisor.
- **Promessa absoluta em produto de saúde/estética** é o risco maior no depilador IPL. Fora:
  "permanente", "garantido", "100%", "sem dor", "definitivo", "resultado garantido". O texto fala
  em *redução duradoura* e *com resfriamento*, que é o que a LP sustenta.
- **Marca de terceiro que não vendemos** continua proibida no anúncio e vai como negativa
  (Creed, Dior, Sauvage, Paco Rabanne, One Million, Bleu de Chanel). Lattafa, Afnan, Armaf, WAP,
  Karcher, Vonder, Ulike e MLAY podem: são os produtos anunciados.
- **Preço no anúncio**: nenhum. As LPs não exibem preço fixo de propósito, e anúncio com preço que
  não bate com a página é reprovação certa.
- **Afiliado**: as LPs têm 1.395 a 1.728 palavras, comparativo, FAQ e disclosure — é o que separa
  conteúdo de valor de "thin affiliate" aos olhos da política.

## 6. Depois de ligar

Revisar o **relatório de termos de pesquisa a cada 48h na primeira semana**. É a maior alavanca
de CTR e de economia no início: cada termo irrelevante que vira negativa sobe o CTR da campanha
e devolve verba para os termos que convertem.
