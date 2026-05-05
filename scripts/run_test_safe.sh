#!/bin/bash
# Wrapper SEGURO pra rodar testes que requerem PIPELINE_PAUSED desativado.
#
# Garante restauração do pause SEMPRE — mesmo que PHP crashe, timeout, sigkill, etc.
# Usa `trap` em EXIT, INT, TERM. Idempotent: se pause já estava desativado, nada faz.
#
# Uso:
#   bash /app/scripts/run_test_safe.sh _e2e_validacao.php 219
#   bash /app/scripts/run_test_safe.sh _e2e_validacao.php 287

set -uo pipefail

ENV_FILE=/app/.env
PAUSE_PATTERN='^PIPELINE_PAUSED='

# Salva se estava pausado ANTES de mexer
WAS_PAUSED=0
if grep -q "$PAUSE_PATTERN" "$ENV_FILE"; then
    WAS_PAUSED=1
fi

# Função de restauração — chamada via trap
restore_pause() {
    if [ "$WAS_PAUSED" = "1" ]; then
        # Só re-adiciona se NÃO está mais lá (evita duplicação)
        if ! grep -q "$PAUSE_PATTERN" "$ENV_FILE"; then
            echo "" >> "$ENV_FILE"
            echo "# Re-pause restaurado pelo wrapper $(date +%H:%M:%S)" >> "$ENV_FILE"
            echo "PIPELINE_PAUSED=1" >> "$ENV_FILE"
            echo "[run_test_safe] PIPELINE_PAUSED restaurado" >&2
        fi
    fi
}
# Trap em todos os exit codes
trap restore_pause EXIT INT TERM HUP

# Remove pause pra teste
sed -i "/$PAUSE_PATTERN/d" "$ENV_FILE"
echo "[run_test_safe] pause removido temporariamente" >&2

# Executa script PHP
SCRIPT="${1:-}"
shift || true
if [ -z "$SCRIPT" ]; then
    echo "uso: bash $0 <script.php> [args...]" >&2
    exit 2
fi
php "/app/scripts/$SCRIPT" "$@"
EXIT_CODE=$?

# trap vai chamar restore_pause automaticamente
exit $EXIT_CODE
