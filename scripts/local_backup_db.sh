#!/usr/bin/env bash
# scripts/local_backup_db.sh - local dumps of the GPBM databases on MountZion.
# rev 2026-10-03 (item H41): no password in this file - it reads MY_SECRET (the nz password) and passes it
#   to mysqldump by environment (MYSQL_PWD), never on a command line; it refuses when MY_SECRET is unset.
#   No upload: the scp to the gpbm.uk account on 20i is removed (Alan, Q6 of 3 Oct 2026: off-site backup is
#   rsync.net, set up separately). gplan_main must never reach a production host (GPBM §4, §6).
# Usage:  MY_SECRET set in this terminal, then:  bash /var/www/gpbm/scripts/local_backup_db.sh
set -euo pipefail

[ -n "${MY_SECRET:-}" ] || { echo "MY_SECRET is not set (the nz password). Nothing done." >&2; exit 1; }

BACKUP_DIR="/var/www/gpbm/sql_manual"
LOG_FILE="${BACKUP_DIR}/backup_${HOSTNAME}_$(date +'%Y-%m-%d').log"
DATE="$(date +'%Y-%m-%d_%H-%M-%S')"
MYSQLDUMP_BIN="${MYSQLDUMP_BIN:-mysqldump}"
DB_USER="nz"
DATABASES=(gplan_dev gplan_main gplan_pbm)

COMMON_OPTS=(
  --single-transaction
  --quick
  --skip-lock-tables
  --default-character-set=utf8mb4
)

mkdir -p "$BACKUP_DIR"
touch "$LOG_FILE"
log() { echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }

for NAME in "${DATABASES[@]}"; do
  OUT="${BACKUP_DIR}/${NAME}_${DATE}.sql.gz"
  ERR_TMP="$(mktemp)"
  log "Backing up ${NAME}..."
  set +e
  MYSQL_PWD="$MY_SECRET" "$MYSQLDUMP_BIN" --user="$DB_USER" "${COMMON_OPTS[@]}" \
    --routines --triggers --events "$NAME" 2>"$ERR_TMP" | gzip -c > "$OUT"
  RC=${PIPESTATUS[0]}
  set -e
  if [[ $RC -eq 0 ]]; then
    log "  OK: $OUT"
  else
    log "  FAILED for ${NAME} (mysqldump exit $RC): $(tr '\n' ' ' < "$ERR_TMP" | cut -c1-300)"
    rm -f "$OUT"
  fi
  rm -f "$ERR_TMP"
done

RETENTION_DAYS=30
log "Retention: deleting backups and logs older than ${RETENTION_DAYS} days from ${BACKUP_DIR} (top level only)..."
find "$BACKUP_DIR" -maxdepth 1 -type f -name "*.sql.gz" -mtime +"$RETENTION_DAYS" -print -delete \
  | while read -r f; do log "  Deleted: $f"; done
find "$BACKUP_DIR" -maxdepth 1 -type f -name "backup_*.log" -mtime +"$RETENTION_DAYS" -print -delete \
  | while read -r f; do log "  Deleted: $f"; done
log "Finished. Log: $LOG_FILE"
