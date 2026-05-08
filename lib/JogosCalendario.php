<?php
declare(strict_types=1);

/**
 * JogosCalendario — leitor do data/jogos_vitoria.json com queries semânticas pra pipelines.
 *
 * Diferente de scripts/atualizar_jogos.php (que ESCREVE no JSON), esta classe só LÊ
 * e expõe perguntas que pipelines fazem:
 *
 *   - Qual o próximo jogo do Vitória?
 *   - Estamos em janela de jogo (pré/live/pós)?
 *   - Qual foi o último jogo concluído (pra pós-jogo)?
 *   - Próximos N jogos?
 *
 * Janelas (defaults configuráveis via opts):
 *   PRE-JOGO     = T-3h até T (3h antes do apito inicial até o início)
 *   LIVE         = T até T+2h (início até ~110min — futebol é 90min + acréscimos)
 *   POS-JOGO     = T+2h até T+4h (apito final até 4h depois — janela de captura quente)
 *
 * Uso típico:
 *   $cal = new JogosCalendario(__DIR__ . '/../data/jogos_vitoria.json');
 *   if ($cal->estaEmJanela('live'))      ...  // está acontecendo agora
 *   if ($cal->estaEmJanela('pos-jogo'))  ...  // captura pós-jogo
 *   $proximo = $cal->proximoJogo();      ...  // ['data' => ..., 'adversario' => ...]
 */
class JogosCalendario
{
    private string $jsonPath;
    private ?array $dados = null;

    public const PRE_HORAS  = 3;     // janela pré-jogo: 3h antes do apito
    public const LIVE_HORAS = 2;     // janela live: até 2h após início (90min + acréscimos)
    public const POS_HORAS  = 4;     // janela pós-jogo: T+2h até T+4h+2h = 4h após início

    public function __construct(string $jsonPath)
    {
        $this->jsonPath = $jsonPath;
    }

    /** Lazy-load JSON. Retorna [] se arquivo não existe ou JSON inválido. */
    private function dados(): array
    {
        if ($this->dados !== null) return $this->dados;
        if (!file_exists($this->jsonPath)) { $this->dados = []; return []; }
        $raw = file_get_contents($this->jsonPath);
        $j = json_decode($raw ?: '{}', true);
        $this->dados = is_array($j) ? $j : [];
        return $this->dados;
    }

    /** Lista crua de jogos (sem ordenação). */
    public function jogos(): array
    {
        return (array)($this->dados()['jogos'] ?? []);
    }

    /** Próximo jogo agendado (data >= hoje, ordenado por data ASC). null se não houver. */
    public function proximoJogo(): ?array
    {
        $hoje = date('Y-m-d');
        $futuros = array_values(array_filter($this->jogos(), fn($j) =>
            ($j['data'] ?? '') >= $hoje && ($j['status'] ?? '') !== 'finalizado'
        ));
        usort($futuros, fn($a, $b) => $this->jogoTs($a) <=> $this->jogoTs($b));
        return $futuros[0] ?? null;
    }

    /** Último jogo finalizado (data < hoje, ordenado DESC). null se não houver. */
    public function ultimoJogo(): ?array
    {
        $agora = time();
        $passados = array_values(array_filter($this->jogos(), fn($j) =>
            $this->jogoTs($j) < $agora
        ));
        usort($passados, fn($a, $b) => $this->jogoTs($b) <=> $this->jogoTs($a));
        return $passados[0] ?? null;
    }

    /** Próximos N jogos (default 5). */
    public function proximosJogos(int $n = 5): array
    {
        $hoje = date('Y-m-d');
        $futuros = array_values(array_filter($this->jogos(), fn($j) =>
            ($j['data'] ?? '') >= $hoje && ($j['status'] ?? '') !== 'finalizado'
        ));
        usort($futuros, fn($a, $b) => $this->jogoTs($a) <=> $this->jogoTs($b));
        return array_slice($futuros, 0, $n);
    }

    /**
     * Está em janela de jogo? Tipos:
     *   'pre-jogo'   — 3h antes do apito até o início
     *   'live'       — apito inicial até ~110min depois
     *   'pos-jogo'   — apito final (T+2h) até T+4h após início
     *   'qualquer'   — qualquer uma das 3 acima
     *
     * Retorna o jogo + tipo da janela, ou null se fora de qualquer janela.
     */
    public function janelaAtual(): ?array
    {
        $agora = time();
        foreach ($this->jogos() as $jogo) {
            $ts = $this->jogoTs($jogo);
            if ($ts <= 0) continue;
            $tipoJanela = $this->classificarJanela($ts, $agora);
            if ($tipoJanela !== null) {
                return ['tipo' => $tipoJanela, 'jogo' => $jogo];
            }
        }
        return null;
    }

    public function estaEmJanela(string $tipo = 'qualquer'): bool
    {
        $janela = $this->janelaAtual();
        if ($janela === null) return false;
        if ($tipo === 'qualquer') return true;
        return $janela['tipo'] === $tipo;
    }

    /** Diff em horas até o próximo jogo. null se não houver. */
    public function horasAteProximoJogo(): ?float
    {
        $proximo = $this->proximoJogo();
        if (!$proximo) return null;
        $ts = $this->jogoTs($proximo);
        return ($ts - time()) / 3600.0;
    }

    /** Cadência sugerida do Pingo baseado em janela atual. */
    /**
     * Persiste posts_gerados[$tipo] = $postId pra um jogo específico.
     * Recarrega o JSON do disco antes de salvar (evita race em runs simultâneos).
     *
     * @param string $jogoId ID do jogo (e.g. '2026-05-09-vit-flu')
     * @param string $tipo 'pre_jogo' | 'pos_jogo' | 'preview_tatico' | 'analise_pos' | 'repercussao'
     * @param int $postId ID do post WP
     * @return bool true se atualizou; false se jogo não existe
     */
    public function registrarPostGerado(string $jogoId, string $tipo, int $postId): bool
    {
        if (!file_exists($this->jsonPath)) return false;
        $raw = (string)file_get_contents($this->jsonPath);
        $db = json_decode($raw, true);
        if (!is_array($db) || empty($db['jogos'])) return false;

        $changed = false;
        foreach ($db['jogos'] as &$j) {
            if (($j['id'] ?? '') !== $jogoId) continue;
            if (!isset($j['posts_gerados']) || !is_array($j['posts_gerados'])) {
                $j['posts_gerados'] = [];
            }
            $j['posts_gerados'][$tipo] = $postId;
            $changed = true;
            break;
        }
        unset($j);
        if (!$changed) return false;

        $db['_meta']['posts_gerados_atualizado_em'] = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('c');
        file_put_contents(
            $this->jsonPath,
            json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        $this->dados = $db;
        return true;
    }

    public function cadenciaPingoMinutos(int $cadenciaNormal = 15): int
    {
        $janela = $this->janelaAtual();
        if ($janela === null) {
            // Fora de janela — checa se está em D-1 (próximas 24h) → acelera
            $h = $this->horasAteProximoJogo();
            if ($h !== null && $h <= 24) return max(5, intdiv($cadenciaNormal, 3));
            return $cadenciaNormal;
        }
        return match ($janela['tipo']) {
            'pre-jogo' => 3,   // 3min: tabela e escalação saem agora
            'live'     => 5,   // 5min: gols e cartões
            'pos-jogo' => 3,   // 3min: coletivas, repercussão
            default    => $cadenciaNormal,
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────

    /** Converte data+hora+timezone do jogo em timestamp UTC. 0 se inválido. */
    private function jogoTs(array $jogo): int
    {
        $data = (string)($jogo['data'] ?? '');
        $hora = (string)($jogo['hora'] ?? '21:30');
        $tz   = (string)($jogo['timezone'] ?? 'America/Sao_Paulo');
        if ($data === '') return 0;
        try {
            $dt = new DateTime("{$data} {$hora}", new DateTimeZone($tz));
            return $dt->getTimestamp();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Classifica em qual janela o ts do jogo está em relação a agora. null se fora. */
    private function classificarJanela(int $tsJogo, int $agora): ?string
    {
        $diff = $agora - $tsJogo;  // negativo = jogo no futuro; positivo = no passado
        $h = $diff / 3600.0;

        // Pre-jogo: -3h até 0
        if ($h < 0 && $h >= -self::PRE_HORAS) return 'pre-jogo';
        // Live: 0 até +2h
        if ($h >= 0 && $h <= self::LIVE_HORAS) return 'live';
        // Pos-jogo: +2h até +4h (LIVE_HORAS até POS_HORAS)
        if ($h > self::LIVE_HORAS && $h <= self::POS_HORAS) return 'pos-jogo';

        return null;
    }
}
