# CLAUDE.md — Instruções para Claude Code agente

Este arquivo é lido **só pelo Claude Code** (agente de coding) quando você abre este projeto. **NÃO** é injetado em prompts de produção. Para regras editoriais reusáveis em prompts, ver `prompts/manifesto_editorial.md`.

---

## SOBRE ESTE PROJETO

**SaaS Portal Clonais** — gerador automatizado de artigos Google Discover para uma rede de portais editoriais (vagasebeneficios, cursosenac, guiadoscursos, comocomprar, ondecompraragora, leaodabarra). Pipeline: pingo (RSS scrape + scoring) → triagem → DebateBuilder/DiscoverGerador (Claude/GPT) → WordPress (REST). Monetização: afiliado (PrettyLinks), AdSense em ramp-up, JoinAds para LPs.

Deploy: EasyPanel (`https://sistema3-saasportal.o8a7pc.easypanel.host`). DB MariaDB. Repo: `Wioxen/saas-portal` (privado).

---

## DOCS VIVAS — LER ANTES DE TOCAR EM CADA MÓDULO

| Módulo | Doc principal |
|---|---|
| **Manifesto editorial** | `prompts/manifesto_editorial.md` (regras editoriais reusáveis injetadas em prompts de produção — DNA Discover, E-E-A-T, anti-IA, blocos magnéticos, blindagem) |
| **portal.php** | `docs/portal/INDEX.md` |
| **pingo (DiscoverPingo)** | `docs/pingo/INDEX.md` |
| **maquina (orquestrador)** | `docs/maquina/INDEX.md` |
| **Deploy / produção** | memory `reference_deploy_easypanel.md` |
| **Roadmap LPs afiliado** | `docs/ads/ROADMAP-AFILIADO-LPS.md` |

Sempre registrar mudanças no `CHANGELOG.md` do módulo respectivo.

---

## CONVENÇÕES DE CÓDIGO

### HTML
- **Aspas simples em todos os atributos HTML**: `class='exemplo'`, nunca `class="exemplo"`. Exceção: código existente que usa `"` — não normalizar em massa (custo alto, ganho zero), só seguir a convenção em código novo.
- **Acentuação portuguesa completa obrigatória**, inclusive dentro de JSON-LD de schemas (FAQPage, HowTo). NUNCA: "Nao", "formacao", "voce". SEMPRE: "Não", "formação", "você".
- **JSON-LD ao final do HTML** (após rodapé), nunca no meio do conteúdo visível.

### PHP
- PHP 8+ no servidor (XAMPP local pode ser 8.0+, EasyPanel é PHP 8.1+).
- Lib em `lib/` — cada classe em arquivo próprio com `class Foo`.
- Scripts CLI em `scripts/` — usar `--site=SLUG`, `--id=N`, `--confirm` como padrão de flags.
- **Fronteira de módulos:** `lib/X.php` pertence ao módulo dono (gera/escreve), não a todo arquivo que faz `require`. Ver memória `feedback_module_boundaries.md`.
- **Timezone:** sempre setar `date_default_timezone_set('America/Sao_Paulo')` no bootstrap. XAMPP/Windows herda Europe/Berlin e quebra `date()` depois das 19h BR.

### Markdown e prompts
- **Manifesto editorial** (`prompts/manifesto_editorial.md`): regras reusáveis injetadas em prompts de produção. NÃO especificar formato de saída (JSON/Markdown/HTML) aqui — quem chama define.
- **prompt.md**: system prompt completo do `DebateBuilder` (Sonnet). Tem placeholders `{{DATA_HOJE}}`, `{{TITULO}}`, `{{CONTEUDO}}`, `{{DNA_SECTION}}`, `{{ENTIDADES_REAIS}}`, `{{BACKLINKS_SECTION}}`. Esse SIM define formato (JSON).

---

## REGRAS DE OURO PRO AGENTE

1. **Ler documentação viva antes de mexer em código de módulo** (pingo, maquina, portal). Sem isso vira bagunça.
2. **NUNCA inventar dados, paths ou nomes de função.** Verificar com Read/Grep antes.
3. **Memória de produção** (`memory/MEMORY.md`) tem contexto crítico (estado do deploy, decisões editoriais, calibrações). Ler no início de sessão nova.
4. **Antes de rodar scripts que gastam API** (Anthropic/OpenAI), confirmar com user.
5. **Antes de mudanças destrutivas** (reset, drop table, delete em massa), confirmar com user.
6. **Ao mudar código que afeta múltiplos sites**, validar em 1 site antes de propagar.
7. **Saída de gerador deve ser JSON** (DebateBuilder, DiscoverGerador, DiscoverGeradorGPT). Se modelo devolver Markdown, é bug — checar se prompts/manifesto_editorial.md está sendo injetado corretamente.

---

## ESTRUTURA DO REPO

```
apiclaudephp/
├── CLAUDE.md                              ← este arquivo (instruções pro agente)
├── prompts/
│   └── manifesto_editorial.md             ← regras editoriais reusáveis
├── prompt.md                              ← system prompt do DebateBuilder (com placeholders)
├── config.php
├── sites.php                              ← config por site (Env::get)
├── _site_helper.php                       ← aplicarSite(), sitesDisponiveis()
├── gerarpost.php                          ← entry HTTP de geração manual
├── portal.php                             ← dashboard (ler docs/portal/INDEX.md)
├── lib/
│   ├── Claude.php, OpenAI.php             ← clientes LLM
│   ├── DebateBuilder.php                  ← gerador via prompt.md
│   ├── DiscoverGerador.php, DiscoverGeradorGPT.php  ← geradores via blocos custom + manifesto
│   ├── DiscoverPromptBuilder.php          ← carrega manifesto editorial + blocos canonicais
│   ├── DiscoverPingo.php                  ← scraping RSS + scoring
│   └── Discover*.php                      ← submódulos (PostProcess, Schemas, Validators…)
├── scripts/
│   ├── pingo.php, tick_filas.php
│   ├── gerar_teste_e2e.php                ← geração CLI 1 trend (--site, --id, --confirm)
│   └── _smoke_*.php                       ← suítes de teste
├── data/
│   ├── fontes_pingo.json                  ← config de fontes (volátil em redeploy)
│   └── debug/gpt_fail_*.txt               ← logs de falha de parse JSON
├── docs/
│   ├── portal/INDEX.md, CHANGELOG.md
│   ├── pingo/INDEX.md, CHANGELOG.md
│   ├── maquina/INDEX.md, CHANGELOG.md
│   └── ads/ROADMAP-AFILIADO-LPS.md
└── memory/                                ← auto-memory persistente entre sessões
    └── MEMORY.md                          ← índice
```

---

## FLUXOS COMUNS

### Gerar 1 artigo de teste (CLI)
```bash
php scripts/gerar_teste_e2e.php --site=leaodabarra --id=1325           # preview
php scripts/gerar_teste_e2e.php --site=leaodabarra --id=1325 --confirm # gasta API
```

### Rodar pingo manual
```bash
nohup php scripts/pingo.php --site=leaodabarra --force > /tmp/pingo.log 2>&1 &
tail -f /tmp/pingo.log
```

### Inspecionar trends
```bash
php scripts/inspect_scores.php --site=leaodabarra
```

### Recovery de fontes_pingo.json (some em redeploy do EasyPanel)
```bash
php scripts/seed_config_pingo.php
php scripts/_adicionar_fontes_esportes.php
```

---

## HISTÓRICO

- **2026-05-02**: separados CLAUDE.md (instruções pro agente) e `prompts/manifesto_editorial.md` (regras editoriais). Antes era 1 arquivo só, com a seção `## FORMATO DE ENTREGA` em Markdown que conflitava com o pipeline JSON. Causa raiz dos `gpt_fail_*.txt` desde 2026-05-01 noite.
