#!/usr/bin/env bash
# Ripristina un backup. Uso: ./scripts/restore.sh backups/app_db-AAAAMMGG-HHMMSS.sql.gz
# ATTENZIONE: sostituisce i dati attuali (prima ne viene fatto un backup).
set -euo pipefail
cd "$(dirname "$0")/.."
FILE="${1:?Indica il file di backup da ripristinare}"
[ -f "$FILE" ] || { echo "File non trovato: $FILE"; exit 1; }
read -r -p "Sostituire i dati attuali con $FILE? [si/no] " ok
[ "$ok" = "si" ] || { echo "Annullato"; exit 1; }
./scripts/backup.sh
gunzip -c "$FILE" | docker compose exec -T db sh -c 'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" app_db'
echo "Ripristino completato."
