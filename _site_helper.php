<?php
/**
 * Helper de multi-site: resolve o site ativo e mescla suas credenciais no $cfg.
 *
 * Uso típico em cada tela (antes de instanciar Wordpress/Claude):
 *
 *   require __DIR__ . '/_site_helper.php';
 *   $sites = sitesDisponiveis();
 *   $siteSlug = siteAtivoSlug($sites);
 *   aplicarSite($cfg, $sites, $siteSlug);
 */

/**
 * Carrega sites.php com 3 camadas de cache (mais rápida primeiro):
 *   1. Static-var (mesmo processo): zero overhead
 *   2. APCu (entre processos PHP-FPM/Apache): ~50µs por hit
 *   3. require sites.php: ~3-8ms (file IO + parse)
 *
 * Invalidação: TTL de 60s + filemtime de sites.php (se arquivo mudou, ignora cache).
 * Em desenvolvimento: definir CC_DISABLE_SITES_CACHE=1 no .env burla cache.
 */
function sitesDisponiveis(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $path = __DIR__ . '/sites.php';
    if (!file_exists($path)) { $cache = []; return []; }

    $bypass = !empty(getenv('CC_DISABLE_SITES_CACHE'));
    $apcuOk = !$bypass && function_exists('apcu_fetch') && function_exists('apcu_store');

    if ($apcuOk) {
        $mtime = @filemtime($path) ?: 0;
        $cacheKey = 'cc_sites_v1';
        $hit = apcu_fetch($cacheKey, $found);
        if ($found && is_array($hit) && isset($hit['mtime'], $hit['data']) && $hit['mtime'] === $mtime) {
            $cache = $hit['data'];
            return $cache;
        }
    }

    $s = require $path;
    $data = is_array($s) ? $s : [];
    $cache = $data;

    if ($apcuOk) {
        @apcu_store($cacheKey ?? 'cc_sites_v1', [
            'mtime' => @filemtime($path) ?: 0,
            'data'  => $data,
        ], 60);
    }
    return $data;
}

/**
 * Invalida cache estático + APCu de sitesDisponiveis(). Chamar quando sites.php é
 * modificado dentro do mesmo processo PHP (ex: testes que mexem em sites.php).
 */
function sitesCacheInvalidar(): void
{
    static $invalidouStatic = false;
    if (function_exists('apcu_delete')) @apcu_delete('cc_sites_v1');
    // static-var não dá pra resetar diretamente (sem reflection); chamadores que precisam
    // disso devem reiniciar o processo. Em produção, TTL APCu de 60s já cobre o caso.
}

/**
 * Lê o site selecionado com prioridade: GET > POST > COOKIE > primeiro da lista.
 * Persiste em cookie por 30 dias quando vem explicitamente de URL ou form —
 * assim sobrevive a navegação entre abas que não carregam o param na URL.
 */
function siteAtivoSlug(array $sites): string
{
    $fromGet    = $_GET['site']           ?? '';
    $fromPost   = $_POST['site']          ?? '';
    $fromCookie = $_COOKIE['portal_site'] ?? '';

    $candidato = '';
    foreach ([$fromGet, $fromPost, $fromCookie] as $v) {
        if ($v !== '' && isset($sites[$v])) { $candidato = $v; break; }
    }
    if ($candidato === '') return (string)array_key_first($sites);

    // Persiste em cookie quando a fonte foi explícita (URL ou form)
    $explicito = ($fromGet === $candidato) || ($fromPost === $candidato);
    if ($explicito && $fromCookie !== $candidato && !headers_sent()) {
        @setcookie('portal_site', $candidato, [
            'expires'  => time() + 86400 * 30,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);
        $_COOKIE['portal_site'] = $candidato;
    }
    return $candidato;
}

/**
 * Resolve qual LLM usar na geração (claude ou openai).
 * Prioridade: GET > POST > COOKIE > default 'claude'.
 * Persiste em cookie por 30 dias quando explicitamente alterado.
 */
function llmAtivo(): string
{
    $allow     = ['claude', 'openai'];
    $fromGet   = $_GET['llm']           ?? '';
    $fromPost  = $_POST['llm']          ?? '';
    $fromCookie= $_COOKIE['portal_llm'] ?? '';

    $candidato = '';
    foreach ([$fromGet, $fromPost, $fromCookie] as $v) {
        if (in_array($v, $allow, true)) { $candidato = $v; break; }
    }
    if ($candidato === '') return 'claude';

    $explicito = ($fromGet === $candidato) || ($fromPost === $candidato);
    if ($explicito && $fromCookie !== $candidato && !headers_sent()) {
        @setcookie('portal_llm', $candidato, [
            'expires'  => time() + 86400 * 30,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);
        $_COOKIE['portal_llm'] = $candidato;
    }
    return $candidato;
}

/**
 * Mescla credenciais/config do site escolhido dentro de $cfg (por referência).
 * Valores do sites.php têm prioridade; chaves ausentes mantêm o que já vinha do config.php.
 */
function aplicarSite(array &$cfg, array $sites, string $slug): void
{
    if (!isset($sites[$slug])) return;
    $s = $sites[$slug];
    $campos = [
        'wp_url', 'wp_user', 'wp_app_password',
        'site_name',
        'whatsapp_number', 'whatsapp_group_url', 'whatsapp_cta_text',
        'pretty_links_prefix',
        'amazon_affiliate_url',
        // Meta (Facebook Page + Instagram Business)
        'fb_page_id', 'fb_page_token', 'ig_user_id', 'ig_access_token',
        // Imagem featured override por site (pexels_first | dalle_first | og_only)
        'imagem_featured_estrategia',
        // GSC URL custom (ex: sc-domain:dominio.com.br) — fallback wp_url + '/'
        'gsc_site_url',
        // Amazon Associates BR tag — pra ProductRanker incluir ?tag={X}
        'amazon_associates_tag',
        // Flags de UI — se tema WP / RankMath já renderizam, evita duplicação
        'author_box_inline',
        'breadcrumb_inline',
        'rankmath_handles_schemas',
        // Trend Scoring Gate — score abaixo do threshold desvia de Claude pra GPT-mini.
        // Default global = 7.0 (em DiscoverGerador::gerar). Override per-site quando o range
        // típico de score do nicho fica abaixo (ex: esportes raramente passa de 7).
        'trend_scoring_threshold',
        'trend_scoring_enabled',
        // Thresholds de scraping (DiscoverFontes::thresh) — default global é conservador
        // (1200/3000/4000/4). Override pra nichos com texto naturalmente curto (esportes:
        // notícias de jogo/mercado costumam ter 1500-2500 chars).
        'fontes_min_por_fonte',
        'fontes_min_agregado',
        'fontes_min_fonte_solo',
        'fontes_max_fontes',
        // Filtro de nicho — trend só é aprovado pelo Pingo se contém 1+ termo desta lista.
        // Trends fora do nicho ficam status='fora_escopo_<site>'. Usado em sites mono-nicho
        // (ex: leaodabarra cobre SÓ Esporte Clube Vitória — pivot 2026-05-02).
        'nicho_required_terms',
        // Glossário de backlinks internos: mapa termo → URL canônica do site.
        // Aplicado pelo InternalLinkGlossary no PostProcess pra construir cluster topical
        // authority (cada termo aponta sempre pra mesma URL hub do nicho).
        'internal_link_glossary',
        // Modo de validação de anchor pra backlinks internos. Default true (sites
        // multi-nicho — exige overlap entre título do candidato e keyword âncora).
        // Sites mono-nicho (cursosenac, leaodabarra) setam false: TODOS os posts
        // são do mesmo nicho, filtro extra elimina links bons.
        'internal_links_strict_anchor',
    ];
    foreach ($campos as $k) {
        // Booleanos false são valores válidos — só pula se ausente OU string vazia
        if (isset($s[$k]) && $s[$k] !== '') $cfg[$k] = $s[$k];
    }
    $cfg['_site_slug'] = $slug;
    $cfg['_site_name'] = $s['name'] ?? $slug;

    // Persona editorial do site — usada pelo DiscoverGerador pra injetar voz no prompt.
    // Se ausente ou mal-formada, pipeline segue sem persona (voz genérica do CLAUDE.md).
    if (isset($s['persona']) && is_array($s['persona'])) {
        $cfg['persona'] = $s['persona'];
    }
}

/**
 * Valida schema mínimo de uma persona. Retorna lista de problemas (vazia = ok).
 * Usado em tests e pode ser chamado defensivamente antes de injetar no prompt.
 */
function validarPersona(?array $p): array {
    if (!is_array($p) || empty($p)) return ['persona ausente ou vazia'];
    $campos = ['autor', 'voz', 'especialidade', 'audiencia', 'tom'];
    $problemas = [];
    foreach ($campos as $c) {
        if (!isset($p[$c]) || trim((string)$p[$c]) === '') {
            $problemas[] = "campo '{$c}' ausente ou vazio";
        }
    }
    return $problemas;
}
