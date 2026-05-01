# 07 — Web Story (somente leitura) (linhas 1477, 1503, 1613, 1987-2001)

> **Escopo:** este arquivo só descreve onde portal.php **lê** info de web story para renderizar UI. **Geração da story pertence ao módulo `maquina`, não ao portal.** Bug de cenas não aparecerem ao editar é responsabilidade da doc do módulo `maquina` (a documentar em `docs/maquina/`).

## Onde portal.php toca em web story

### 1. Agregador 7d — linha 1477-1502

Dentro do bloco "Salvos" (`?view=saved`), portal itera `$salvosAll` filtrando por `publicado_em` nos últimos 7 dias. Para cada `$rr`:

```php
$wsInfo = $rr['web_story_info'] ?? null;
if (is_array($wsInfo)) {
    if (!empty($wsInfo['ok']))      $wsOk++;
    elseif (!empty($wsInfo['pulado'])) $wsPulado++;
    else                             $wsErro++;
}
```

Também conta por cluster:
```php
$wsPorCluster[$ck] = ($wsPorCluster[$ck] ?? 0) + 1;  // só nos casos 'ok'
```

**Schema implícito de `$rr['web_story_info']`** (deduzido das leituras):

```
{
  ok:         bool,            // sucesso de criação
  pulado:     bool,             // pulada (ROI baixo, desabilitada)
  erro:       string?,          // mensagem se falhou
  story_id:   int?,             // ID do post web-story no WP
  scenes:     int?,             // número de cenas
  tempo_ms:   int?,             // tempo de geração
  view_url:   string?,          // URL pública da story
}
```

> **Estes são os campos que o portal LÊ.** Se o módulo `maquina` mudar o schema, portal precisa ser atualizado. Manter compatibilidade.

### 2. URL admin do plugin — linha 1503

```php
$wsAdminUrl = rtrim((string)($cfg['wp_url'] ?? ''), '/') . '/wp-admin/edit.php?post_type=web-story';
```

Constrói link pro admin do WP onde o usuário pode listar/editar web stories. Usado no widget (linha 1616) como botão "Ver Stories no WP ↗".

### 3. Widget Web Stories 7d — linha 1579-1617

Renderiza caixa com:
- Total criadas / falharam / puladas
- Taxa de sucesso percentual (verde ≥ 90, amarelo ≥ 70, vermelho abaixo)
- Top 3 clusters por # de stories (com emoji ROI + label curto via `TrendsTaxonomia`)
- Mensagem alternativa se total = 0:
  - Se `webstory_enabled` não setado: "Ative com `WEBSTORY_ENABLED=1` no .env"
  - Se setado: "Gera quando cluster tem ROI ≥ `webstory_roi_min`" (default 5.0)
- Botão "Ver Stories no WP ↗" → abre `$wsAdminUrl` em nova aba

> Linha 1613 é a única referência a `$cfg['webstory_enabled']` e `$cfg['webstory_roi_min']` no portal. Configs são lidas mas não escritas — ficam em `.env`/`config.php`.

### 4. Badge na tabela de salvos — linha 1987-2001

Para cada linha da tabela de salvos:

```php
$wsI = $r['web_story_info'] ?? null;
if (is_array($wsI)):
    if (!empty($wsI['ok'])):
        // badge "📽️ Story" link pra view_url (ou span se sem URL)
    elseif (!empty($wsI['pulado'])):
        // badge "📽️ —" cinza
    elseif (isset($wsI['erro'])):
        // badge "📽️✗" vermelho com tooltip
    endif;
endif;
```

Mostra o status da story diretamente na linha do post. Tooltip do badge "ok" mostra: `Web Story #{story_id} · {scenes} cenas · {tempo_ms}ms`.

## Configurações lidas pelo portal

| Chave                   | Default | Uso                                          |
|-------------------------|---------|----------------------------------------------|
| `webstory_enabled`      | falsy   | gate global — texto da mensagem em widget vazio |
| `webstory_roi_min`      | 5.0     | mostrado em mensagem informativa             |
| `wp_url`                | -       | base pra montar `$wsAdminUrl`                |

> Portal **não decide** se gera story; só consulta o resultado da decisão tomada pelo módulo `maquina` ao gerar artigo.

## O que portal **não** faz

- ❌ NÃO gera web story
- ❌ NÃO chama o plugin `wp-web-stories-ai`
- ❌ NÃO escreve `web_story_info` no DB
- ❌ NÃO valida `story_data` ou cenas
- ❌ NÃO tem endpoint AJAX pra interagir com stories

## Implicações pro bug "cenas não aparecem ao editar"

Esse bug NÃO é do portal. Quando for investigar (no módulo `maquina`):

1. Olhar `lib/Maquina.php` — ponto onde gera story.
2. Olhar plugin `wp-content/plugins/wp-web-stories-ai/` — schema e meta keys esperados.
3. **Possíveis pontos onde portal poderia ajudar a diagnosticar:**
   - Adicionar coluna no badge mostrando se `scenes > 0` (hoje só sabemos que `ok=true`)
   - Adicionar warning no widget se `wsOk` mas zero scenes
   - Mas só fazer isso DEPOIS de identificar a raiz no módulo `maquina`.

## Pontos de extensão (no portal)

- **Adicionar mais detalhe no badge?** Atualizar 1987-2001 com novos campos do schema. Cuidar pra não quebrar render se campo ausente.
- **Novo widget de KPI de stories?** Replicar padrão do widget 1579-1617 com nova métrica.
- **Filtrar tabela por "tem story / não tem"?** Adicionar `data-has-story` no `<tr>` (módulo 05) + filtro em `aplicar()` (módulo 06).

## Notas

- A doc do bug fica em `docs/maquina/KNOWN_ISSUES.md` quando essa pasta existir. KNOWN_ISSUES.md do portal só registra o **rastro de leitura** aqui descrito.
- Schema de `web_story_info` é "documentação por leitura" — sem contrato formal. Mudanças no `lib/Maquina.php` precisam manter retrocompatibilidade ou atualizar este arquivo + 4 pontos de leitura no portal.
