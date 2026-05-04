#!/bin/bash
# /usr/local/bin/run-in-app — wrapper pra cron rodar comandos dentro do
# container sistema3_saasportal (PHP/deps vivem só no container, não no host).
# Resolve o ID atual via filtro por nome (estável entre deploys).
CONTAINER=$(docker ps --format "{{.ID}}" --filter name=sistema3_saasportal | head -1)
if [ -z "$CONTAINER" ]; then
  echo "[run-in-app] ERRO: container sistema3_saasportal não encontrado" >&2
  exit 1
fi
exec docker exec "$CONTAINER" "$@"
