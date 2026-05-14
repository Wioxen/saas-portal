# Playbook Vitória x Flamengo — 14/05/2026 21:30

Engenharia local pro evento de amanhã, **sem chamadas LLM API**. Tudo escrito por Opus em sessão Claude Code.

## Estado atual (13/05 noite)

| Item | Estado |
|---|---|
| Post aniversário 127 anos | ✅ Publicado #1379 |
| Post pré-jogo Vitória x Flamengo | ✅ Atualizado #1110 (10178 chars, transmissão real, FAQ, schema BroadcastEvent c/ broadcaster) |
| Calendar `data/jogos_vitoria.json` | ✅ vit-fla com transmissão SporTV/Premiere + placar_ida 1-2 + fase corrigida |
| Pós-jogo standby | ✅ `scripts/_pos_jogo_vitfla_standby.php` pronto |
| Trend #18568 no DB remoto | ⏳ Pendente UPDATE → publicado=1379 (SSH quando quiser) |
| Trend pré-jogo no DB | N/A (gerado antes via maquina/cron) |
| Trend pós-jogo no DB | N/A (vamos gerar sem trend) |

## Cenário do confronto (recapitulação)

- **Ida (22/04)**: Flamengo 2 x 1 Vitória no Maracanã
- **Volta (14/05 21:30)**: no Barradão
- **Pra Vitória classificar direto**: vencer por 2+ de diferença
- **Pra ir aos pênaltis**: vencer por 1 de diferença
- **Empate ou derrota**: eliminado
- **Transmissão**: SporTV (TV fechada) + Premiere (pay-per-view). Globo aberta NÃO transmite.
- **Técnicos**: Jair Ventura (VIT) · Leonardo Jardim (FLA)

## Fluxo do dia 14/05

### Manhã (08:00-12:00) — opcional, refresh de escalação

Se sair coletiva ou time provável confirmado pelas fontes, é possível atualizar o post #1110 com a escalação real (substituindo o trecho "escalação provável"). Não é obrigatório — o conteúdo atual já está sólido.

Comando: pedir pro Opus em chat — eu edito o trecho via WP REST.

### Durante o jogo (21:30-23:30) — manual opcional

Se quiser uma "atualização live" no post #1110 (placar + gol marcado), é manual em WP admin. Sem schema LiveBlogPosting (pipeline não tem).

### Pós-apito (~23:30) — gerar pós-jogo

1. **Scrape** dos fatos:
   ```powershell
   & "C:\xampp\php\php.exe" "C:\xampp\htdocs\apiclaudephp\scripts\_pos_jogo_vitfla_standby.php" --scrape
   ```
   Salva `data/pos_jogo_vitfla_scraped.json` com placar, og:image, body excerpt do ge.globo.

2. **Abrir Claude Code**, me passar o JSON. Eu (Opus) escrevo o HTML do pós-jogo direto no script.

3. **Publicar**:
   ```powershell
   & "C:\xampp\php\php.exe" "C:\xampp\htdocs\apiclaudephp\scripts\_pos_jogo_vitfla_standby.php" --publish
   ```
   Cria draft em leaodabarra, atualiza calendar JSON com placar final + post_id.

4. **Revisar e publicar** no WP admin (link aparece no output).

### Próximo dia (15/05) — opcional repercussão

Pós-jogo + 1 dia: coletiva técnico + reação ESPN/ge.globo/Bahia Notícias. Gerar como post separado se quiser (mesmo padrão manual).

## Scripts locais usados

- `scripts/atualizar_jogos.php` — scrape ge.globo, atualiza calendar. Roda local sem LLM. 
- `scripts/_refresh_pre_jogo_vitfla.php` — refresh post #1110 (rodado em 13/05 noite)
- `scripts/_pos_jogo_vitfla_standby.php` — pós-jogo (rodar 14/05 23:30+)
- `scripts/_gerar_post_aniversario_vitoria_127.php` — aniversário (rodado em 13/05)

## Quando LLM voltar

- Religar crons `pos_jogo_auto.php` e `atualizar_jogos.php --so-se-perto` no servidor (não estão ativos hoje).
- Substituir essa engenharia manual pela automação padrão.
- Memorial `feedback_fonte_rss_typos_temporais.md` continua valendo (cross-check weekday).
