#!/bin/bash
# scripts/pingo_sequencial.sh
#
# Roda pingo em sequência pra todos os sites ativos. Evita rodar em paralelo
# (consumiria DB connections + Anthropic rate limit). Sleep entre cada um pra
# não saturar Serper API.
#
# Cron sugerido: */10 * * * * root /app/scripts/pingo_sequencial.sh >> /var/log/pingo.log 2>&1
#
# Pra adicionar/remover site, editar o array SITES abaixo.

set -uo pipefail
cd /app

SITES=(vagasebeneficios leaodabarra cursosenac)

for site in "${SITES[@]}"; do
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] === pingo --site=$site ==="
  php /app/scripts/pingo.php --site="$site" --quiet
  ec=$?
  if [ $ec -ne 0 ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ⚠️ pingo --site=$site exit=$ec"
  fi
  sleep 15
done

echo "[$(date '+%Y-%m-%d %H:%M:%S')] === fim ($(echo ${SITES[@]} | wc -w) sites processados) ==="
