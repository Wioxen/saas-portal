# 01 — Bootstrap (linhas 1-61)

**Propósito:** preparar o ambiente PHP antes de qualquer handler ou render. Garante que endpoints AJAX devolvam JSON limpo (sem warning leak), carrega 25 libs do projeto, resolve config, site ativo e LLM ativo.

## Linhas

| Range  | Bloco                                                              |
|--------|--------------------------------------------------------------------|
| 1-6    | Docblock do arquivo (cabeçalho descritivo)                         |
| 8-13   | **AJAX guard**: se `?ajax=*` está na URL, suprime `display_errors` e abre `ob_start()` |
| 15-39  | **25 `require_once`** de `lib/` (todas as libs do pipeline Discover)|
| 41-50  | `jsonOut()` — emissor de JSON limpo                                |
| 52     | `$cfg = require __DIR__ . '/config.php';`                          |
| 53-56  | `_site_helper.php` + `sitesDisponiveis()` + `siteAtivoSlug()` + `aplicarSite()` |
| 58-60  | `$cfg['default_llm'] = llmAtivo();` (Claude/OpenAI via cookie/URL) |
| 61     | `$db = new DiscoverDb();` — instância única do DB usada por todos os handlers |

## Funções definidas neste bloco

- **`jsonOut(array $data): void`** — `portal.php:45`
  - Esvazia todos os níveis de output buffer (`ob_get_level()`/`ob_end_clean()` em loop), envia header `Content-Type: application/json; charset=utf-8` se ainda não enviado, faz `json_encode` com `JSON_UNESCAPED_UNICODE`, e dá `exit`.
  - **Por que existe:** AJAX endpoints PHP costumam vazar warnings/notices no buffer de saída antes do `echo`. Isso quebra o JSON no frontend (`SyntaxError: Unexpected token <`). `jsonOut()` é a saída segura — todo handler AJAX deve usar.

## Por que o AJAX guard é crítico

Linha 8-13:

```php
if (isset($_GET['ajax'])) {
    @ini_set('display_errors', '0');
    @error_reporting(E_ERROR | E_PARSE);
    ob_start();
}
```

Sem isso:
- Um `Notice: Undefined index` em qualquer lib seria impresso ANTES do JSON.
- O frontend recebe `<br /><b>Notice...` + JSON e falha no `JSON.parse`.
- Já mordeu o projeto antes — o comentário da linha 8 ("AJAX endpoints NUNCA podem emitir HTML") é a cicatriz.

Quando este guard é ativado:
- Apenas `E_ERROR | E_PARSE` ainda passa (fatais que matariam o script de qualquer jeito).
- Output é bufferizado — handler decide o que sai (via `jsonOut()`).

> **Regra implícita:** todo novo handler AJAX que adicionar ao portal.php DEVE terminar com `jsonOut()` ou `echo json_encode + exit`. Nada de `echo "html"` em ramo AJAX.

## Dependências externas (require_once em ordem)

Linhas 15-39, na ordem de carregamento:

1. `lib/TrendsScraperWeb.php`
2. `lib/DiscoverScore.php`
3. `lib/DiscoverDb.php`
4. `lib/DiscoverAngulo.php`
5. `lib/Serper.php`
6. `lib/Scraper.php`
7. `lib/GoogleNewsRss.php`
8. `lib/TrendsArticles.php`
9. `lib/Claude.php`
10. `lib/Wordpress.php`
11. `lib/Maquina.php` ← **pertence ao módulo maquina**, não ao portal
12. `lib/DiscoverGerador.php`
13. `lib/DiscoverUpdater.php`
14. `lib/DiscoverFila.php`
15. `lib/DiscoverCalendario.php`
16. `lib/DiscoverCluster.php`
17. `lib/DiscoverQualityScore.php`
18. `lib/DiscoverReviewer.php`
19. `lib/OpenAI.php`
20. `lib/DiscoverGeradorGPT.php`
21. `lib/DiscoverProgress.php`
22. `lib/DiscoverPainClassifier.php`
23. `lib/DiscoverRPM.php`
24. `lib/DiscoverClusterMatcher.php`
25. `lib/DiscoverSinaisEditoriais.php`

> Ver `INDEX.md` (tabela "Dependências externas") pra coluna "Módulo dono".

**Carregamento eager:** todas as 25 são carregadas em todo request, AJAX ou não. **Custo:** dezenas de includes mesmo quando o handler não precisa. **Lazy include único:** `lib/DiscoverAfiliados.php` na linha 1455 (dentro do render).

## Config + Site + LLM

```php
$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);
$cfg['default_llm'] = llmAtivo();
```

- **`$cfg`** vira o array global de configuração (chaves usadas no portal: `wp_url`, `serper_api_key`, `user_agent`, `default_llm`, `webstory_enabled`, `webstory_roi_min`, etc.).
- **`aplicarSite()`** muta `$cfg` baseado no site ativo (cada site tem credenciais WP próprias).
- **`llmAtivo()`** decide entre Claude e OpenAI por cookie/URL. Sobrescreve `$cfg['default_llm']`.

## Estado global criado neste bloco

Variáveis disponíveis pra todos os handlers e o render abaixo:

| Variável     | Origem                              | Tipo            | Uso                                  |
|--------------|-------------------------------------|-----------------|--------------------------------------|
| `$cfg`       | `config.php` + `aplicarSite()`      | array           | wp_url, wp_user, wp_app_password, serper_api_key, user_agent, default_llm, webstory_*  |
| `$sites`     | `sitesDisponiveis()`                | array           | mapa de sites disponíveis (slug → config) |
| `$siteSlug`  | `siteAtivoSlug($sites)`             | string          | site ativo na sessão                  |
| `$db`        | `new DiscoverDb()` (linha 61)       | DiscoverDb      | acesso ao DB (get/all/upsert/updateStatus/delete/migrarSite) |

> Todos os handlers AJAX dependem desse estado. Nada é re-instanciado por handler.

## Pontos de extensão

- Adicionar nova lib? → `require_once` na faixa 15-39, e adicionar entrada na tabela do INDEX.md (com módulo dono).
- Adicionar handler AJAX? → ele DEVE estar fluxo abaixo do guard (linha 8-13), e usar `jsonOut()`.
- Adicionar nova chave em `$cfg`? → idealmente vem de `config.php` (não hardcode aqui). Se for específica de site, deve ser populada por `aplicarSite()`.

## Notas / cheiros

- **25 includes eager** é caro. Talvez valha um audit futuro: quais handlers AJAX realmente precisam de quais libs? Lazy load por handler reduziria bootstrap. Não fazer agora — out of scope.
- **`@` no `ini_set` e `error_reporting`** suprime warning se a função estiver desabilitada — defensivo, ok.
- **`headers_sent()` check em `jsonOut()`** evita "headers already sent" se algo já tiver impresso. Boa.
- `JSON_UNESCAPED_UNICODE` é correto pra PT-BR (acentos não viram `\uXXXX`).

## O que NÃO faz

- Não roteia, não despacha, não autentica. Os handlers AJAX vêm logo depois (módulo 02).
- Não inicia sessão (`session_start` não aparece). Site ativo / LLM são por cookie lido em `_site_helper.php`.
- Não faz ACL — qualquer um que acessa portal.php tem acesso a tudo.
