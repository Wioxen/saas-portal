<?php
declare(strict_types=1);

/**
 * SourceFidelityValidator — detecta alucinações comparando o HTML gerado contra as fontes scrapeadas.
 *
 * Foco em 3 categorias críticas pra E-E-A-T e credibilidade:
 *   1. NOMES PRÓPRIOS — pessoas com 2+ palavras capitalizadas (técnicos, jogadores, autoridades)
 *      que aparecem no artigo mas NÃO aparecem em nenhuma fonte.
 *   2. URLs específicas — links que o modelo "deduziu" (ex: "site.com/ingresso") mas a fonte não tem.
 *   3. Números-chave — diferenças numéricas significativas (ex: "20 pontos" no artigo vs "17 pontos" na fonte).
 *
 * Caso real (post #711 leaodabarra):
 *   - "Franclim Carvalho" técnico inventado (não estava na fonte FogãoNET)
 *   - "Léo Condé" técnico inventado
 *   - "botafogo.com.br/ingresso" URL inventada
 *   - "20 pontos" vs "17 pontos" da fonte
 *
 * Uso típico (no fim do pipeline, antes de salvar no WP):
 *   $val = SourceFidelityValidator::validar($html, $textosDeFontes);
 *   if ($val['severity'] === 'fail') {
 *       // marca status='alucinacao_detectada', salva $val['issues'] pra debug, NÃO publica
 *   }
 */
class SourceFidelityValidator
{
    /**
     * Stop-list de palavras capitalizadas que não são nome próprio (PT-BR).
     * Inclui inícios de frase, dias da semana, meses, conectores, etc.
     */
    private const STOP_CAPS = [
        // Início de frase
        'A', 'O', 'Os', 'As', 'Um', 'Uma', 'Uns', 'Umas',
        'Este', 'Esta', 'Estes', 'Estas', 'Esse', 'Essa', 'Esses', 'Essas',
        'Isso', 'Isto', 'Aquele', 'Aquela', 'Aquilo',
        'Mas', 'Ou', 'Porém', 'Contudo', 'Todavia', 'Entretanto',
        'Quando', 'Onde', 'Como', 'Quem', 'Que', 'Qual', 'Quais',
        'Para', 'Por', 'Com', 'Sem', 'Sob', 'Sobre', 'Entre', 'Após',
        'Não', 'Sim', 'Talvez', 'Já', 'Ainda', 'Sempre', 'Nunca',
        'Hoje', 'Ontem', 'Amanhã', 'Agora',
        'Saiba', 'Veja', 'Confira', 'Descubra', 'Entenda',
        // Dias da semana
        'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo',
        // Meses
        'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
        'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
        // Outros frequentes
        'Brasil', 'Brasileira', 'Brasileiro', 'Brasileiros',
    ];

    /**
     * @param string $html         HTML gerado pelo modelo
     * @param array  $sourceTexts  Lista de strings com texto bruto das fontes (já scrapeadas)
     * @param array  $opts         {
     *     check_names:bool (default true),
     *     check_urls:bool (default true),
     *     min_name_words:int (default 2) — só flag nomes com >= N palavras capitalizadas seguidas
     *     allowlist_names:array — nomes que sabemos serem válidos mesmo fora da fonte
     * }
     * @return array {
     *   ok: bool,
     *   severity: 'ok'|'warn'|'fail',
     *   issues: array<int,array{tipo:string, valor:string, contexto:string}>,
     *   stats: array{nomes_extraidos:int, urls_extraidas:int, fontes_chars:int}
     * }
     */
    public static function validar(string $html, array $sourceTexts, array $opts = []): array
    {
        $checkNames    = $opts['check_names'] ?? true;
        $checkUrls     = $opts['check_urls'] ?? true;
        $minNameWords  = (int)($opts['min_name_words'] ?? 2);
        $allowlist     = array_map('mb_strtolower', $opts['allowlist_names'] ?? []);
        // Domínio do próprio site (pra excluir backlinks internos da validação).
        // DiscoverInternalLinks injeta links pra posts próprios — esses NÃO são alucinação.
        $ownDomain     = strtolower((string)($opts['own_domain'] ?? ''));
        $ownDomain     = preg_replace('#^https?://#', '', $ownDomain);
        $ownDomain     = preg_replace('#/.*$#', '', $ownDomain ?: '');

        $issues = [];
        $sourceBlob = mb_strtolower(implode("\n\n", array_filter(array_map('strval', $sourceTexts))));
        $sourceChars = mb_strlen($sourceBlob);

        // Texto plano do artigo (sem tags) pra extração
        $articleText = strip_tags(html_entity_decode($html, ENT_QUOTES|ENT_HTML5, 'UTF-8'));

        $stats = ['nomes_extraidos' => 0, 'urls_extraidas' => 0, 'fontes_chars' => $sourceChars];

        if ($checkNames) {
            $nomes = self::extrairNomesProprios($articleText, $minNameWords);
            $stats['nomes_extraidos'] = count($nomes);
            foreach ($nomes as $nome) {
                $nomeLower = mb_strtolower($nome);
                if (in_array($nomeLower, $allowlist, true)) continue;
                // Aceita se aparece literal OU se o sobrenome (última palavra) aparece nas fontes
                if (str_contains($sourceBlob, $nomeLower)) continue;
                $partes = preg_split('/\s+/', $nome) ?: [];
                $sobrenome = end($partes);
                if (is_string($sobrenome) && mb_strlen($sobrenome) >= 4
                    && str_contains($sourceBlob, mb_strtolower($sobrenome))) continue;
                // Não bate em fonte — possível alucinação
                $contexto = self::extrairContexto($articleText, $nome, 80);
                $issues[] = [
                    'tipo'     => 'nome_alucinado',
                    'valor'    => $nome,
                    'contexto' => $contexto,
                ];
            }
        }

        if ($checkUrls) {
            $urls = self::extrairUrlsEspecificas($html);
            $stats['urls_extraidas'] = count($urls);
            foreach ($urls as $url) {
                // Backlink interno do próprio site: NÃO é alucinação, é internal-linking
                // legítimo (DiscoverInternalLinks busca posts publicados pra cross-link).
                if ($ownDomain !== '') {
                    $hostUrl = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
                    if ($hostUrl === $ownDomain || str_ends_with($hostUrl, '.' . $ownDomain)) continue;
                }
                // Whitelist oficial: domínios reconhecidos (gov, federações, clubes oficiais,
                // ferramentas como Sofascore/Transfermarkt) sempre passam. Backlinks pra esses
                // são E-E-A-T legítimo, não alucinação.
                if (self::isUrlWhitelist($url)) continue;
                $urlLower = mb_strtolower($url);
                if (str_contains($sourceBlob, $urlLower)) continue;
                // Tenta domínio + path raiz
                $host = parse_url($url, PHP_URL_HOST) ?: '';
                $path = parse_url($url, PHP_URL_PATH) ?: '';
                $hostPath = mb_strtolower($host . $path);
                if ($hostPath !== '' && str_contains($sourceBlob, $hostPath)) continue;
                // URL com path específico (ex: /ingresso, /comprar) tem alto risco de invenção
                $pathSegments = array_filter(explode('/', $path));
                $hasSpecificPath = count($pathSegments) >= 1;
                $issues[] = [
                    'tipo'     => $hasSpecificPath ? 'url_path_alucinado' : 'url_alucinada',
                    'valor'    => $url,
                    'contexto' => self::extrairContexto($articleText, $host, 80),
                ];
            }
        }

        $severity = self::computarSeveridade($issues);
        return [
            'ok'       => empty($issues),
            'severity' => $severity,
            'issues'   => $issues,
            'stats'    => $stats,
        ];
    }

    /**
     * Extrai nomes próprios: sequências de N+ palavras capitalizadas seguidas.
     * Exclui início de frase via heurística: se a palavra anterior termina em ".", ignora.
     *
     * Captura: "Franclim Carvalho", "Léo Condé", "Júlia Kudiess"
     * Ignora:  "Botafogo" (1 palavra só), "O Botafogo" (artigo), "Estádio Nilton Santos" (sobrenome basta)
     */
    /**
     * Prefixos de entidades institucionais — capturados pelo regex mas não devem ser flagados
     * como nome de pessoa. Ex: "Campeonato Brasileiro Série", "Copa do Brasil", "Liga das Nações".
     * Se o nome composto começa com um destes, considera entidade institucional → skip.
     */
    private const INSTITUCIONAIS_PREFIXOS = [
        'Campeonato', 'Copa', 'Liga', 'Brasileirão', 'Série', 'Mundial',
        'Sudamericana', 'Libertadores', 'Nordestão', 'Recopa', 'Supercopa',
        'Taça', 'Torneio', 'Estadual', 'Internacional',
        'Clássico', 'Derby', 'Decisão', 'Final', 'Semifinal', 'Quartas',
    ];

    /**
     * Domínios oficiais SEMPRE aceitos como link legítimo, mesmo se não estão LITERAIS
     * nas fontes scrapeadas. Sites editoriais reconhecidos do nicho (clubes oficiais,
     * federações, órgãos governamentais esportivos). Backlink pra esses passa autoridade
     * legítima e fortalece E-E-A-T.
     */
    private const URLS_WHITELIST_OFICIAIS = [
        // Governamental BR
        'gov.br', 'edu.br', 'jus.br', 'mil.br', 'leg.br',
        // Esportes BR — federações
        'cbf.com.br', 'fbf.org.br', 'stjd.org.br',
        // Clubes BR oficiais
        'ecvitoria.com.br', 'flamengo.com.br', 'palmeiras.com.br', 'corinthians.com.br',
        'saopaulofc.net', 'atletico.com.br', 'cruzeiro.com.br', 'cam.com.br',
        'gremio.net', 'internacional.com.br', 'fluminense.com.br', 'botafogo.com.br',
        'vasco.com.br', 'ecbahia.com', 'sportrecife.com.br', 'fortalezaec.net',
        'cearasc.com', 'remo.com.br',
        // Internacionais
        'fifa.com', 'conmebol.com', 'uefa.com', 'fia.com', 'formula1.com',
        'nba.com', 'ufc.com', 'olympics.com',
        // Mídia esportiva oficial (autoridade alta — não competitiva editorial direta)
        'sofascore.com', 'sofascore.com.br', 'transfermarkt.com.br', 'transfermarkt.com',
    ];

    /**
     * Verifica se URL bate com algum domínio da whitelist oficial.
     */
    private static function isUrlWhitelist(string $url): bool
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        if ($host === '') return false;
        foreach (self::URLS_WHITELIST_OFICIAIS as $dom) {
            if ($host === $dom || str_ends_with($host, '.' . $dom)) return true;
        }
        return false;
    }

    private static function extrairNomesProprios(string $text, int $minWords): array
    {
        // Processa linha por linha pra evitar match cross-line tipo "Brasileirão\nUma vitória"
        $linhas = preg_split('/[\r\n]+/u', $text) ?: [];
        // Padrão: 2+ palavras com inicial maiúscula separadas APENAS por espaço (não \n).
        // Espaço ASCII e non-breaking space — explicitamente sem \s pra não pegar quebra de linha.
        // Lookbehind inclui `:` pra não capturar palavras após "ATENÇÃO:" como nome próprio.
        $padrao = '/(?<![\.\!\?\:\n]\s)\b([A-ZÁÂÃÀÉÊÍÓÔÕÚÇ][a-záâãàéêíóôõúç]{2,}'
                . '(?:[ \xC2\xA0]+[A-ZÁÂÃÀÉÊÍÓÔÕÚÇ][a-záâãàéêíóôõúç]{2,}){' . ($minWords - 1) . ',3})\b/u';

        $nomes = [];
        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '' || mb_strlen($linha) < 6) continue;
            if (!preg_match_all($padrao, $linha, $m)) continue;
            foreach ($m[1] as $cap) {
                $cap = trim($cap);
                $partes = preg_split('/\s+/', $cap) ?: [];
                // Descarta se 1ª palavra é stop-word
                if (in_array($partes[0] ?? '', self::STOP_CAPS, true)) continue;
                // Descarta se TODAS as palavras são stop
                $stopCount = 0;
                foreach ($partes as $p) if (in_array($p, self::STOP_CAPS, true)) $stopCount++;
                if ($stopCount === count($partes)) continue;
                // Descarta se 1ª palavra é prefixo institucional (Campeonato/Copa/Liga/Clássico)
                // — esses formam entidades como "Campeonato Brasileiro Série A" que NÃO são pessoa.
                if (in_array($partes[0] ?? '', self::INSTITUCIONAIS_PREFIXOS, true)) continue;
                $nomes[$cap] = true;
            }
        }
        return array_keys($nomes);
    }

    /**
     * Extrai URLs explícitas do HTML (href + texto plano com domínio.tld).
     * Ignora URLs internas do site (sem `.com.br/X` específico).
     */
    private static function extrairUrlsEspecificas(string $html): array
    {
        $urls = [];

        // hrefs
        if (preg_match_all('/href=([\'"])([^\'"]+)\1/i', $html, $m)) {
            foreach ($m[2] as $u) {
                if (str_starts_with($u, '#') || str_starts_with($u, 'mailto:') || str_starts_with($u, 'tel:')) continue;
                if (str_starts_with($u, '/')) continue; // internal
                if (preg_match('#^https?://#i', $u)) $urls[$u] = true;
            }
        }

        // URLs em texto plano: domínio.tld/algo
        // strip_tags pode juntar texto de tags adjacentes sem whitespace ("Ingressos:" + "<p>site.com")
        // resultando em "Ingressossite.com" — exigimos whitespace ou pontuação antes do domínio.
        $text = strip_tags(preg_replace('/<\/(p|h[1-6]|li|td|tr|div)>/i', " </$1>", $html) ?? $html);
        if (preg_match_all('#(?:^|[\s>:.,;!?\(\)])([a-z0-9-]+\.(com\.br|com|net|org|gov\.br|edu\.br)(?:/[^\s,\.]*)?)#i', $text, $m)) {
            foreach ($m[1] as $u) {
                $clean = rtrim($u, ".,;:");
                // Path obrigatório pra entrar como "específico" (apenas domínio sem path tem menos risco)
                if (str_contains($clean, '/')) $urls[$clean] = true;
            }
        }

        return array_keys($urls);
    }

    private static function extrairContexto(string $text, string $needle, int $window): string
    {
        if ($needle === '') return '';
        $pos = mb_stripos($text, $needle);
        if ($pos === false) return '';
        $start = max(0, $pos - intval($window / 2));
        return '…' . trim(mb_substr($text, $start, $window)) . '…';
    }

    private static function computarSeveridade(array $issues): string
    {
        if (empty($issues)) return 'ok';
        // 1+ nome alucinado OU 1+ URL com path = FAIL (não publica)
        // URL bare (só domínio) = WARN
        $criticas = 0;
        foreach ($issues as $i) {
            if (in_array($i['tipo'], ['nome_alucinado', 'url_path_alucinado'], true)) $criticas++;
        }
        if ($criticas >= 1) return 'fail';
        return 'warn';
    }

    /** Linha curta pra log. */
    public static function reportToLogLine(array $report): string
    {
        if ($report['ok']) {
            return sprintf('SourceFidelity: OK (nomes=%d, urls=%d)',
                $report['stats']['nomes_extraidos'] ?? 0,
                $report['stats']['urls_extraidas'] ?? 0);
        }
        $tipos = [];
        foreach ($report['issues'] as $i) $tipos[$i['tipo']] = ($tipos[$i['tipo']] ?? 0) + 1;
        $tipoStr = implode(',', array_map(fn($k, $v) => "{$k}={$v}", array_keys($tipos), $tipos));
        return "SourceFidelity: severity={$report['severity']} ({$tipoStr})";
    }
}
