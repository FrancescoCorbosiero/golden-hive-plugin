#!/usr/bin/env bash
# Pull-rebuild-restart helper for the production VPS.
#
# Run from the project root on the VPS:
#   bash docker/scripts/deploy.sh
#
# Assumes the host has docker compose v2 and a populated docker/.env.prod.
set -euo pipefail

cd "$(dirname "$0")/../.."

COMPOSE=(docker compose -f docker/docker-compose.prod.yml --env-file docker/.env.prod)

echo "[deploy] fetching latest code"
git fetch --prune
git pull --ff-only

echo "[deploy] rebuilding wordpress image"
"${COMPOSE[@]}" build --pull wordpress

echo "[deploy] applying stack"
"${COMPOSE[@]}" up -d --remove-orphans

echo "[deploy] pruning dangling images"
docker image prune -f

echo "[deploy] running container status"
"${COMPOSE[@]}" ps
