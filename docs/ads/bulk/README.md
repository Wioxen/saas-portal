# Upload em massa — 3 campanhas de afiliado pago do comocomprar

Gerado por `scripts/_cc_ads_bulk_csv.php` a partir das specs `docs/ads/CAMPANHA-*.md`.
Para regerar depois de editar a spec: `php scripts/_cc_ads_bulk_csv.php --write`.

Conta de destino: **Sistema 1** (a mesma que já roda PéDeMeia/Concurso/jornada do estudante).

## 🚩 Bloqueio antes de qualquer coisa: a conta está zerada

```
Fundos disponíveis: R$ 0,00   —   "Os fundos se esgotaram"
Pagamento manual · Mastercard ••••7586 · último pagamento 8/jul R$ 40,00
```

A conta é **pré-paga**. Com saldo zero nenhuma campanha veicula, ativa ou não. Adicionar
fundos exige dados de pagamento e é ação sua — nenhum agente faz isso.

Para as 3 campanhas rodarem 10–14 dias a R$ 25/dia cada: **R$ 25 × 3 × 14 ≈ R$ 1.050**.
Um teste mais curto de 7 dias fecha em ~R$ 525.

## Ordem de importação (Google Ads Editor)

Os arquivos são numerados porque a ordem importa — grupo não existe sem campanha,
keyword não existe sem grupo:

| # | Arquivo | Cria |
|---|---|---|
| 01 | `01-campanhas.csv` | 3 campanhas de Pesquisa, R$ 25/dia, Brasil/PT, sem parceiros e sem Display |
| 02 | `02-grupos.csv` | 10 grupos de anúncios com teto de CPC |
| 03 | `03-palavras-chave.csv` | 57 palavras-chave (só frase e exata, nada de ampla) |
| 04 | `04-negativas.csv` | 75 negativas em nível de campanha |
| 05 | `05-anuncios-rsa.csv` | 10 anúncios responsivos (12 títulos nos grupos ligados, 8 nos pausados; 4 descrições) |
| 06 | `06-sitelinks.csv` | 12 sitelinks com duas linhas de descrição |
| 07 | `07-frases-destaque.csv` | 18 frases de destaque |
| 08 | `08-snippets.csv` | 3 snippets estruturados |

No Editor: **Conta → Importar → Do arquivo**, um de cada vez, conferindo o preview antes de
`Publicar`. Se algum nome de coluna não casar com a sua versão do Editor, o próprio importador
oferece o mapeamento manual — os cabeçalhos usados aqui são os padrão.

## O que foi feito por CTR (arquivos 05 a 08)

- **Volume de ativos no RSA.** 12 títulos e 4 descrições nos grupos que sobem ligados. Mais ativos
  = mais combinações que o Google testa = mais CTR, e é o que leva o Ad Strength a "Excelente".
- **Diversidade obrigatória.** Títulos que repetem a mesma ideia fazem o Google descartar
  combinações. Cada grupo cobre ângulos distintos: keyword exata, benefício, objeção, prova,
  comparação, ocasião e chamada para ação. O gerador recusa título repetido dentro do mesmo RSA.
- **Sem fixar posição (pinning).** Fixar título corta as combinações possíveis e costuma derrubar
  o CTR justamente onde se queria controlar a mensagem.
- **Extensões, que não existiam.** Sitelink com descrição, frase de destaque e snippet aumentam a
  área ocupada no resultado e sobem o CTR sem custo adicional. É a alavanca mais barata que havia
  sobrando.
- **Só frase e exata.** Correspondência ampla derruba CTR ao trazer busca que não é a sua.

## Lint de política (o gerador aborta)

A rede já tomou duas reprovações automáticas (campanha de manutenção de celular, 14/07), então o
gerador recusa gravar se algum termo de risco voltar ao texto de anúncio ou extensão:

- promessa absoluta em estética: `permanente`, `garantido`, `100%`, `sem dor`, `definitivo`;
- marca de terceiro que não vendemos: `Creed`, `Dior`, `Sauvage`, `Paco Rabanne`, `One Million`,
  `Bleu de Chanel`, `Aventus` — essas entram como **negativa**, nunca como criativo.

Marcas que **podem** aparecer, porque são os produtos anunciados: Lattafa, Afnan, Armaf, WAP,
Karcher, Vonder, Ulike e MLAY.

Testado: injetando "Resultado Permanente 100%" num título, o gerador aponta os dois termos e não
grava nenhum arquivo.

## Decisões embutidas nos arquivos

- **Campanhas nascem `Paused`.** Quando o saldo entrar, ninguém quer 3 campanhas ainda não
  revisadas começando a gastar no mesmo segundo. São 3 cliques para ligar.
- **AG1 e AG2 `Enabled`; AG3/AG4 `Paused`.** É a sequência das próprias specs: começar pelo
  núcleo BOFU + marca, medir 10–14 dias, só então abrir os grupos de benefício/ocasião.
- **Sem correspondência ampla.** Só frase e exata, como as specs pedem, para não queimar verba
  em clique-lixo antes de existir histórico.
- **Negativas como frase**, não exata: pega as variações sem precisar listar cada uma.
- **UTM via `Final URL Suffix`** por campanha, com `{adgroupid}` e `{keyword}`.
- **Sem trademark de terceiros** nos anúncios. Só as marcas que vendemos (Lattafa, Afnan, Armaf,
  WAP, Karcher, Vonder, Ulike, MLAY). As grifes imitadas entram como **negativas**.

## Ajustes editoriais feitos nas specs

O gerador valida os limites do Google Ads e abortaria se algo estourasse. Ao aplicar isso, cinco
trechos das specs precisaram mudar:

1. **Erro de copiar-colar na campanha de IPL.** A descrição do AG1 começava com
   *"Amadeirado? Não: sem dor com resfriamento…"* — "amadeirado" é vocabulário de perfume, veio
   da campanha de perfume árabe e não quer dizer nada para um depilador. Reescrita para
   *"Sem dor, com resfriamento na ponteira. Veja o depilador certo pra sua pele e pelo."*
2. **Três descrições passavam de 90 caracteres** e teriam sido recusadas na importação
   (perfume AG1 e AG2). Encurtadas preservando o argumento.
3. **Cinco grupos tinham só uma descrição** (perfume AG4, extratora AG2 e AG3, IPL AG2 e AG3).
   RSA exige no mínimo duas — escrevi a segunda para cada, no mesmo tom.

## Depois de ligar

- Revisar o **relatório de termos de pesquisa a cada 48h na primeira semana** e negativar o lixo.
  É a maior alavanca de economia no começo.
- Conversão já validada: **"CC Clique de saída"** (`AW-16696952717/4atHCOa-8NUcEI2P3Zk-`),
  disparando nos botões `.cc-amz-cta` das 3 LPs.
- Cruzar cliques do Ads com o relatório do Amazon Associates para achar a **receita real por
  grupo** — a métrica de decisão do A/B é custo por comissão (mídia gasta ÷ comissão gerada).
- As 3 LPs também capturam e-mail (popup de lead) de quem não clica, então mesmo o tráfego que
  não converte na Amazon vira lista.
