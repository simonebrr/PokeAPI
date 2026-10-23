#!/usr/bin/env bash
# Backup del database. Uso: ./scripts/backup.sh
# Automatico ogni notte alle 3: crontab -e  →  0 3 * * * /percorso/server/scripts/backup.sh >> /percorso/server/backups/backup.log 2>&1
# Copia esterna (consigliata): installa rclone, configura un remote e metti BACKUP_REMOTE=nome:cartella nel .env
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] && set -a && . ./.env && set +a

KEEP_DAYS="${BACKUP_KEEP_DAYS:-30}"
DIR="${BACKUP_DIR:-backups}"
mkdir -p "$DIR"
FILE="$DIR/app_db-$(date +%Y%m%d-%H%M%S).sql.gz"

docker compose exec -T db sh -c 'exec mysqldump --single-transaction --routines -u root -p"$MYSQL_ROOT_PASSWORD" app_db' \
  | gzip > "$FILE.tmp"
mv "$FILE.tmp" "$FILE"

# Foto caricate dagli utenti
if docker compose exec -T php test -d /var/www/html/storage; then
  docker compose exec -T php tar -C /var/www/html -czf - storage > "$DIR/storage-$(date +%Y%m%d-%H%M%S).tar.gz"
fi

find "$DIR" -name 'app_db-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
find "$DIR" -name 'storage-*.tar.gz' -mtime +"$KEEP_DAYS" -delete

if [ -n "${BACKUP_REMOTE:-}" ] && command -v rclone >/dev/null; then
  rclone copy "$DIR" "$BACKUP_REMOTE" --include '*.gz'
fi
echo "$(date '+%F %T') backup ok: $FILE ($(du -h "$FILE" | cut -f1))"
