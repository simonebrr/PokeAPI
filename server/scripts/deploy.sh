#!/usr/bin/env bash
# Aggiorna il server di produzione: backup → git pull → rebuild.
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE="docker compose -f docker-compose.yaml -f docker-compose.prod.yml"
if $COMPOSE ps --status running db 2>/dev/null | grep -q db; then
  ./scripts/backup.sh
fi
git pull --ff-only
$COMPOSE up -d --build --remove-orphans
$COMPOSE ps
