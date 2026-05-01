<?php
/**
 * CircuitBreaker — protege contra cascata de falhas em APIs externas.
 *
 * Problema que resolve:
 *   - Se a API do Claude está fora (5xx em sequência), pipeline atual continua tentando
 *     cada trend → cada um gasta 180s timeout × 2 retries = 6min de processo travado.
 *     N trends na fila × 6min = horas perdidas + gasto de cota retry.
 *   - Pior: se Claude E OpenAI cair junto (incidente Cloudflare global), a fila inteira
 *     trava e UI mostra "running" eterno.
 *
 * Estratégia (3 estados clássicos):
 *   - CLOSED  (normal): chamadas passam, falhas contam dentro de uma janela curta.
 *   - OPEN    (cooldown): chamadas REJEITAM imediatamente sem chamar a API. Sai do estado
 *               quando passa o cooldown.
 *   - HALF-OPEN (trial): após cooldown, próxima chamada é "experimental". Sucesso → CLOSED.
 *               Falha → volta a OPEN com cooldown novo (talvez maior).
 *
 * Configuração padrão:
 *   - threshold = 3 falhas dentro de 60s → abre o circuit
 *   - cooldown = 300s (5min) — tempo de recuperação de incidentes Anthropic
 *
 * Estado persistido em data/circuit/{nome}.json — sobrevive entre processos PHP.
 *
 * Uso:
 *   $cb = new CircuitBreaker('anthropic');
 *   $cb->guarda();             // throw CircuitOpenException se OPEN
 *   try {
 *       $resposta = $http->call(...);
 *       $cb->sucesso();
 *   } catch (Throwable $e) {
 *       $cb->falha($e->getMessage());
 *       throw $e;
 *   }
 *
 * Caller (DiscoverGerador) captura CircuitOpenException → marca trend `aguardando_llm`
 * e retorna sem queimar Sonnet/GPT — fila pega na próxima rodada quando circuit fechar.
 */
class CircuitBreaker
{
    public const ESTADO_CLOSED    = 'closed';
    public const ESTADO_OPEN      = 'open';
    public const ESTADO_HALF_OPEN = 'half_open';

    /**
     * Multiplicador progressivo do cooldown a cada abertura consecutiva (sem recovery
     * pleno entre elas). Ex: cooldown base 300s → [300, 900, 1800, 3600] máximo.
     * Reset ao primeiro sucesso após HALF-OPEN.
     */
    public const BACKOFF_MULTIPLICADORES = [1, 3, 6, 12]; // ×base, cap em 12× (= 60min se base=300s)

    private string $nome;
    private int $threshold;
    private int $window;
    private int $cooldownBase;
    private string $statePath;

    public function __construct(
        string $nome,
        int $threshold = 3,
        int $window = 60,
        int $cooldown = 300
    ) {
        if (!preg_match('/^[a-z0-9_-]+$/i', $nome)) {
            throw new InvalidArgumentException("Nome de circuit inválido: '{$nome}'");
        }
        $this->nome = $nome;
        $this->threshold = max(1, $threshold);
        $this->window = max(1, $window);
        // Mínimo absoluto 1s pra permitir testes/casos especiais; produção deve usar 60s+
        $this->cooldownBase = max(1, $cooldown);
        $dir = __DIR__ . '/../data/circuit';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $this->statePath = $dir . '/' . preg_replace('/[^a-z0-9_-]+/i', '_', $nome) . '.json';
    }

    /**
     * Verifica se circuit está aberto. Lança CircuitOpenException se OPEN.
     * Em HALF-OPEN, deixa passar (chamada experimental).
     */
    public function guarda(): void
    {
        $estado = $this->estadoAtual();
        if ($estado['estado'] === self::ESTADO_OPEN) {
            $reabre = max(0, ($estado['open_until'] ?? 0) - time());
            throw new CircuitOpenException(
                "circuit '{$this->nome}' ABERTO; reabre em {$reabre}s",
                $this->nome,
                $reabre
            );
        }
        // CLOSED ou HALF-OPEN: deixa passar
    }

    /**
     * Calcula cooldown atual considerando backoff progressivo.
     * `consecutivasAberturas` é o número total (já incrementado): 1 = 1ª abertura.
     * Índice no array MULTIPLICADORES = consecutivas - 1.
     *   - 1ª abertura: idx 0 (mult 1, cooldown base)
     *   - 2ª: idx 1 (mult 3)
     *   - 3ª: idx 2 (mult 6)
     *   - 4ª+: idx 3 (mult 12, cap)
     */
    private function cooldownProgressivo(int $consecutivasAberturas): int
    {
        $idx = max(0, $consecutivasAberturas - 1);
        $idx = min($idx, count(self::BACKOFF_MULTIPLICADORES) - 1);
        $mult = self::BACKOFF_MULTIPLICADORES[$idx];
        return $this->cooldownBase * $mult;
    }

    /** Marca uma falha. Pode disparar transição CLOSED→OPEN ou HALF-OPEN→OPEN. */
    public function falha(string $motivo = ''): void
    {
        $state = $this->loadState();
        $now = time();

        // Limpa falhas fora da janela
        $state['falhas'] = array_values(array_filter(
            $state['falhas'] ?? [],
            fn($f) => is_array($f) && (($f['t'] ?? 0) > $now - $this->window)
        ));
        $state['falhas'][] = ['t' => $now, 'motivo' => mb_substr($motivo, 0, 200)];

        // Em HALF-OPEN, qualquer falha re-abre IMEDIATAMENTE com backoff progressivo
        if (($state['estado'] ?? self::ESTADO_CLOSED) === self::ESTADO_HALF_OPEN) {
            $state['consecutivas_aberturas'] = (int)($state['consecutivas_aberturas'] ?? 0) + 1;
            $cd = $this->cooldownProgressivo($state['consecutivas_aberturas']);
            $state['estado'] = self::ESTADO_OPEN;
            $state['open_until'] = $now + $cd;
            $state['ultima_abertura'] = date('c');
            $state['cooldown_aplicado_s'] = $cd;
            $state['motivo_abertura'] = 'falha_em_half_open: ' . mb_substr($motivo, 0, 100);
            $this->saveState($state);
            $this->alertarAbertura($motivo, $cd, $state['consecutivas_aberturas']);
            return;
        }

        // CLOSED: se atingiu threshold, abre
        $abriuAgora = false;
        if (count($state['falhas']) >= $this->threshold) {
            $state['consecutivas_aberturas'] = (int)($state['consecutivas_aberturas'] ?? 0) + 1;
            $cd = $this->cooldownProgressivo($state['consecutivas_aberturas']);
            $state['estado'] = self::ESTADO_OPEN;
            $state['open_until'] = $now + $cd;
            $state['ultima_abertura'] = date('c');
            $state['cooldown_aplicado_s'] = $cd;
            $state['motivo_abertura'] = "{$this->threshold} falhas em {$this->window}s";
            $abriuAgora = true;
        }
        $this->saveState($state);

        if ($abriuAgora) {
            $this->alertarAbertura($motivo, $state['cooldown_aplicado_s'] ?? $this->cooldownBase, $state['consecutivas_aberturas']);
        }
    }

    /** Alerta unificado pra abertura de circuit. HealthWebhook tem throttle 30min. */
    private function alertarAbertura(string $motivo, int $cooldownS, int $consecutivas): void
    {
        $hwPath = __DIR__ . '/HealthWebhook.php';
        if (!is_file($hwPath)) return;
        require_once $hwPath;
        HealthWebhook::erro("Circuit breaker '{$this->nome}' ABERTO" . ($consecutivas > 1 ? " (×{$consecutivas} consecutivas)" : ''), [
            'cooldown_s' => $cooldownS,
            'consecutivas_aberturas' => $consecutivas,
            'ultimo_motivo' => mb_substr($motivo, 0, 200),
        ]);
    }

    /** Marca sucesso. HALF-OPEN→CLOSED. Limpa contador de falhas E reseta backoff. */
    public function sucesso(): void
    {
        $state = $this->loadState();
        $estadoAnterior = $state['estado'] ?? self::ESTADO_CLOSED;
        $state['estado'] = self::ESTADO_CLOSED;
        $state['falhas'] = [];
        $state['open_until'] = 0;
        if ($estadoAnterior === self::ESTADO_HALF_OPEN) {
            $state['ultimo_recovery'] = date('c');
            // Recovery pleno: zera contador de aberturas consecutivas
            // (próxima abertura volta ao cooldown base, sem backoff progressivo)
            $state['consecutivas_aberturas'] = 0;
        }
        $this->saveState($state);
    }

    /**
     * Wrapper conveniente: guarda → executa → sucesso/falha. Re-lança a exceção do callable.
     */
    public function executar(callable $fn)
    {
        $this->guarda();
        try {
            $resultado = $fn();
            $this->sucesso();
            return $resultado;
        } catch (CircuitOpenException $e) {
            // não conta como falha (já estava aberto)
            throw $e;
        } catch (Throwable $e) {
            $this->falha($e->getMessage());
            throw $e;
        }
    }

    /** Status atual (pra dashboard/health-check). */
    public function status(): array
    {
        $state = $this->loadState();
        $now = time();
        $estado = $state['estado'] ?? self::ESTADO_CLOSED;
        $openUntil = (int)($state['open_until'] ?? 0);
        // Auto-promove OPEN→HALF-OPEN quando cooldown expira (sem precisar de chamada nova)
        if ($estado === self::ESTADO_OPEN && $openUntil > 0 && $now >= $openUntil) {
            $estado = self::ESTADO_HALF_OPEN;
            $state['estado'] = $estado;
            $this->saveState($state);
        }

        $falhasRecentes = array_filter(
            $state['falhas'] ?? [],
            fn($f) => is_array($f) && (($f['t'] ?? 0) > $now - $this->window)
        );

        return [
            'nome'                  => $this->nome,
            'estado'                => $estado,
            'falhas_recentes'       => count($falhasRecentes),
            'janela_s'              => $this->window,
            'threshold'             => $this->threshold,
            'open_until'            => $openUntil > 0 ? date('c', $openUntil) : null,
            'reabre_em_s'           => $openUntil > 0 ? max(0, $openUntil - $now) : 0,
            'ultima_abertura'       => $state['ultima_abertura'] ?? null,
            'ultimo_recovery'       => $state['ultimo_recovery'] ?? null,
            'motivo_abertura'       => $state['motivo_abertura'] ?? null,
            'consecutivas_aberturas'=> (int)($state['consecutivas_aberturas'] ?? 0),
            'cooldown_aplicado_s'   => (int)($state['cooldown_aplicado_s'] ?? $this->cooldownBase),
        ];
    }

    /** Reset manual (operação de emergência) — também zera backoff progressivo. */
    public function reset(): void
    {
        $this->saveState([
            'estado' => self::ESTADO_CLOSED,
            'falhas' => [],
            'open_until' => 0,
            'consecutivas_aberturas' => 0,
        ]);
    }

    // ── helpers ──

    /** Avalia estado considerando cooldown expirado (CLOSED, OPEN ou HALF-OPEN). */
    private function estadoAtual(): array
    {
        $state = $this->loadState();
        $now = time();
        $estado = $state['estado'] ?? self::ESTADO_CLOSED;
        $openUntil = (int)($state['open_until'] ?? 0);
        if ($estado === self::ESTADO_OPEN && $now >= $openUntil) {
            // Cooldown expirou — promove pra HALF-OPEN. Próxima chamada testa.
            $state['estado'] = self::ESTADO_HALF_OPEN;
            $estado = self::ESTADO_HALF_OPEN;
            $this->saveState($state);
        }
        $state['estado'] = $estado;
        return $state;
    }

    private function loadState(): array
    {
        if (!is_file($this->statePath)) {
            return ['estado' => self::ESTADO_CLOSED, 'falhas' => [], 'open_until' => 0];
        }
        $raw = @file_get_contents($this->statePath);
        $data = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        return $data + ['estado' => self::ESTADO_CLOSED, 'falhas' => [], 'open_until' => 0];
    }

    private function saveState(array $state): void
    {
        require_once __DIR__ . '/JsonStore.php';
        // Sem backup: estado de circuit é descartável (auto-recupera no primeiro sucesso)
        JsonStore::write($this->statePath, $state, 0, false);
    }
}

/**
 * Lançada quando circuit está OPEN. Caller deve capturar e tratar (não vira HTTP error,
 * é decisão local: pular trend / marcar `aguardando_llm` / aguardar recovery).
 */
class CircuitOpenException extends RuntimeException
{
    public string $circuitNome;
    public int $reabreEmSegundos;

    public function __construct(string $message, string $circuitNome = '', int $reabreEmSegundos = 0)
    {
        parent::__construct($message);
        $this->circuitNome = $circuitNome;
        $this->reabreEmSegundos = $reabreEmSegundos;
    }
}
