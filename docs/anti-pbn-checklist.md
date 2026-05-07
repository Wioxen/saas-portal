# Anti-PBN Checklist — Mitigações que dependem de você

Auditoria criada 07/05/2026 noite. Lista de ações **manuais** (não posso fazer pelo código) pra reduzir footprint de rede artificial junto ao Google.

---

## 🔴 ALTA PRIORIDADE

### 1. Separar Facebook Pages compartilhadas

**Problema atual:** vagasebeneficios e cursosenac usam a MESMA `fb_page_id` (101766412913237 — "Maria Gusmão"). Configurado em `sites.php`. Footprint evidente: 2 sites = 1 entidade gestora.

**Sinal pro Google:** posts publicados na mesma Facebook Page → Open Graph aponta pra mesma entidade → Google reconhece como rede.

**Como resolver:**
1. Criar Facebook Pages separadas:
   - vagasebeneficios → "Vagas e Benefícios" (Page nova)
   - cursosenac → manter atual "Maria Gusmão" OU criar "Curso Senac Gratuito"
2. Em cada Page, gerar Page Access Token (precisa Business Manager ou Meta Developer)
3. Atualizar `.env` com tokens novos:
   - `FB_PAGE_TOKEN_VAGASEBENEFICIOS`
   - `FB_PAGE_TOKEN_CURSOSENAC` (já existe `FB_PAGE_TOKEN_MARIA` — renomear)
4. Atualizar `sites.php`: `fb_page_id` distinto em cada site
5. Mesmo procedimento pro Instagram (vinculado à FB Page)

**Status:** pendente, faça quando puder.

---

### 2. Criar `wp_user` distintos por site

**Problema atual:** `'wp_user' => 'admin'` em 5 dos 6 sites (sites.php). Footprint técnico.

**Sinal pro Google:** Rank Math expõe Author no JSON-LD baseado no usuário WP. Se os 6 sites têm Author "admin" idêntico, Schema.org já entrega evidência.

**Como resolver (em cada wp-admin):**
1. Login no wp-admin de cada site
2. Usuários → Adicionar Novo
3. Criar usuário com:
   - **Username** distinto (ex: `redacao_cursosenac`, `equipe_vagas`, `guia_redacao`, `como_comprar`, `oferta_express`, `leao_redacao`)
   - **Nome de exibição** distinto (alinha com `persona.autor` em sites.php)
   - **Função:** Editor (não Administrator) — princípio do menor privilégio
4. Gerar Application Password novo pra cada usuário
5. Atualizar `.env` (`WP_PASS_*`) e `sites.php` (`wp_user`) com credenciais novas
6. Aposentar usuário `admin` (ou só remover acesso REST)

**Sites afetados:** comocomprar, vagasebeneficios, cursosenac, guiadoscursos, ondecompraragora (todos com `wp_user=admin`). leaodabarra está OK.

---

### 3. Auditar Google Analytics + Search Console

**Verificar:**
- [ ] Cada um dos 6 sites tem **GA4 Property** própria (não 1 property cobrindo todos)
- [ ] Cada site tem **GSC Property** registrada separadamente
- [ ] Idealmente: emails distintos como "owner" no GSC pra cada editora (Sistema 2 vs Sistema 3)
- [ ] No mínimo: NÃO ter o mesmo email como "Owner" das 6 propriedades — usar Manager ou User
- [ ] **Search Console NUNCA conectar 2 propriedades de editoras diferentes** num mesmo Set

**Como verificar (manual):**
1. Login Google Analytics → Admin → Account Settings
2. Login Google Search Console → Settings → Users and permissions
3. Verificar emails listados em cada site

---

## 🟡 MÉDIA PRIORIDADE

### 4. Variar `pretty_links_prefix` (ATENÇÃO: destrutivo)

**Problema atual:** `'pretty_links_prefix' => 'go'` em 5 sites. Footprint técnico secundário.

**RISCO:** mudar prefix QUEBRA todos os PrettyLinks já cadastrados em cada site (links de afiliado em produção). NÃO mude antes de:
1. Auditar quantos PrettyLinks existem por site
2. Configurar redirect 301 dos antigos `/go/X/` pros novos `/ir/X/` (etc.)
3. Atualizar links em todos os posts publicados (script de massa)

**Sugestão de prefixos:**
- comocomprar → `comprar`
- vagasebeneficios → `inscrever`
- cursosenac → `curso`
- guiadoscursos → `acesso`
- ondecompraragora → `oferta`
- leaodabarra → `time`

**Recomendação:** pular essa por agora. ROI baixo vs risco de quebrar links em produção.

---

### 5. CNPJs em `empresa.cnpj`

**Problema atual:** `'cnpj' => ''` em todos os blocos `empresa` no sites.php.

**Por quê:** Schema.org `Organization` aceita `taxID` (CNPJ no Brasil). Declarar CNPJ distinto por editora = sinal de entidade legal real, não rede fictícia.

**Como resolver:**
- Se Sistema 2 e Sistema 3 são CNPJs distintos: preencher
- Se for o mesmo CNPJ: NÃO preencher (declarar mesmo CNPJ em 2 editoras com nichos distintos é footprint pior do que vazio)

**Status:** depende da estrutura legal real do negócio.

---

### 6. SSL e Cloudflare

**Verificar:**
- [ ] Cada domínio tem certificado SSL próprio (não 1 cert SAN cobrindo múltiplos)
- [ ] Cloudflare zones separadas (já tem `cloudflare_zone_id` distinto por site em sites.php — bom)
- [ ] Considerar: 2 contas Cloudflare distintas (1 por editora) — mais elaborado mas reforça separação

---

### 7. Adicionar fotos via Gravatar (E-E-A-T)

**Por quê:** Author sem foto = ghost author = sinal mais fraco. PBN tipicamente não usa fotos reais.

**Como fazer (5min cada):**
1. Acesse https://gravatar.com/
2. Cadastre os emails dos autores:
   - `contato@cursosenacgratuito.com.br` (Paloma Gusmão — foto LinkedIn)
   - `contato@guiadoscursos.com` (Ivan Alves — foto LinkedIn)
   - `contato@vagasebeneficios.com` (Igor Gusmão — foto LinkedIn)
3. Verificar email + upload foto 512×512 quadrada
4. Aguardar 15-30min pra propagar

WP/Rank Math expõem automaticamente baseado no email — sem mudança no código.

**Status:** pendente, faça quando puder.

---

## ✅ JÁ ESTÁ OK (não precisa mexer)

- 2 editoras `empresa.nome` distintas
- 6 personas editoriais com `voz`/`tom`/`autor` distintos
- Templates/cores/prefixos PHP/localStorage próprios (`veb_*`, `gdc_*`)
- `termos_canibal` separa nichos no pingo
- Cross-site dedup >60% no pipeline
- Cross-Site KG limita-se à mesma editora
- Hubs duplicados (Vestibular/ENEM/Sisu/ProUni/Fies/Pós) já removidos do cursosenac
- Cron schedule variado (07/05/2026 noite — sem padrão `:00 :05 :10`)

---

## Como reportar progresso pro Claude

Quando completar uma das tasks acima, me avise pra:
1. Atualizar `sites.php` com IDs/tokens novos
2. Atualizar memória registrando o que foi feito
3. Verificar se há outros pontos a ajustar
