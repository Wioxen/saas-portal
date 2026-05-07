# Rank Math Author Schema — Checklist manual pra enriquecer JSON-LD

Smoke (07/05/2026) confirmou que Rank Math hoje só expõe `name` no Author. Falta `url`, `sameAs`, `description`, `image`. Este checklist ativa todos.

---

## Por site afetado (3 sites Sistema 2):

- ✅ **cursosenac** (cursosenacgratuito.com.br) — autora: Paloma Gusmão #2
- ✅ **guiadoscursos** (guiadoscursos.com) — autor: Ivan Alves #3
- ✅ **vagasebeneficios** (vagasebeneficios.com) — autor: Igor Gusmão #3

---

## Em CADA site (~5min):

### Etapa 1 — Preencher Perfis Sociais do user

1. Acesse `https://{site}.com/wp-admin/users.php`
2. Clique no autor (Paloma / Ivan / Igor) > **Editar**
3. Desça até a seção **Perfis sociais** (adicionada pelo Rank Math)
4. Preencha:
   - **Facebook** (se houver Page do autor — opcional)
   - **Twitter Username** (se houver — opcional)
   - **LinkedIn** (cole a URL completa):
     - Paloma: `https://www.linkedin.com/in/paloma-gusm%C3%A3o-0aa56a189`
     - Ivan: `https://www.linkedin.com/in/ivan-alves-05a185176/`
     - Igor: `https://www.linkedin.com/in/igor-gusmão-1a0155409`
   - **Instagram** (opcional — só se houver)
5. **Atualizar perfil**

> Esses campos viram automaticamente `sameAs` no Author Schema.

### Etapa 2 — Confirmar Bio + URL (já preenchidos via REST)

1. Mesmo perfil, role pra cima
2. **Informações biográficas** = bio que setei via REST (não mude, está correta)
3. **Website** = URL LinkedIn (já preenchida via REST)

### Etapa 3 — Habilitar Author Schema rico (Rank Math)

1. Acesse `https://{site}.com/wp-admin/admin.php?page=rank-math`
2. Menu Rank Math > **Configurações do Título** (ou "Titles & Meta")
3. Aba **Autores** (ou "Authors")
4. Confirmar:
   - **"Mostrar URLs de Autor"** = ATIVADO
   - **"Schema do Autor"** = `Person` (não Organization)
5. Salvar

### Etapa 4 — Avatar (depende de Gravatar — já documentado em anti-pbn-checklist.md item #7)

- Cadastre Gravatar com email do user (já listado no outro checklist)

### Etapa 5 — Testar JSON-LD

1. Abra um post recente (ex: post mais novo do site)
2. Cole a URL no **Rich Results Test**: `https://search.google.com/test/rich-results`
3. Procure pelo bloco "Article" > "author"
4. Deve aparecer agora:
   - `name` ✓
   - `url` ✓ (LinkedIn)
   - `sameAs` ✓ (perfis sociais preenchidos)
   - `description` ✓ (bio)
   - `image` ✓ (se Gravatar configurado)

---

## Quando concluir, me avise

Eu rodo `php scripts/_smoke_author_schema.php` de novo pra confirmar que:
- url ✓
- sameAs ✓
- description ✓
- image ✓ (se Gravatar)

Aparecem nos 3 sites.

---

## Nota

Se na Etapa 1 não aparecer a seção "Perfis sociais", verifique:
- Rank Math está ativado? `Plugins > Rank Math SEO`
- Versão Rank Math (FREE 1.0+ tem essa feature)

Se na Etapa 3 não aparecer aba "Autores":
- `Rank Math > Painel > Recursos` — habilitar módulo "Local SEO" (algumas versões antigas atrelam)

Em último caso, me avise que injeto Person Schema custom via pipeline (cobre posts novos).
