#!/usr/bin/env bash
# Restore a snapshot. Always takes a "pre-restore" safety snapshot first.
# Usage: restore.sh <site> <snapshot-name>
# Restores the database and wp-content/uploads ONLY. Theme and plugin files are
# never written: only the uploads directory is extracted from the archive.
set -euo pipefail
[[ $# -eq 2 ]] || { echo "Usage: restore.sh <site> <snapshot-name>  (theme/plugin restore is not supported)" >&2; exit 1; }
source "$(dirname "$0")/_env.sh" "$1"

NAME="$2"
SRC="$BACKUP_DIR/$NAME"
[[ -f "$SRC/db.sql.gz" && -f "$SRC/wp-content.tar.gz" ]] || { echo "Snapshot not found or incomplete: $SRC" >&2; exit 1; }

echo "== Safety snapshot of current state"
"$(dirname "$0")/snapshot.sh" "$1" "pre-restore" "Automatic backup before restoring $NAME" | tail -1

echo "== Restoring database from $NAME"
gunzip -c "$SRC/db.sql.gz" | "$MYSQL_BIN/mysql" --socket="$SOCKET" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" 2> >(grep -v "Using a password" >&2)

# Extract only uploads from the archive, so theme/plugin files can't be touched.
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
tar -xzf "$SRC/wp-content.tar.gz" -C "$TMP" wp-content/uploads
[[ -d "$TMP/wp-content/uploads" ]] || { echo "No uploads in snapshot archive" >&2; exit 1; }
echo "== Restoring wp-content/uploads"
rsync -a --delete "$TMP/wp-content/uploads/" "$WP_ROOT/wp-content/uploads/"

echo "Restored $NAME (database + uploads; themes and plugins untouched)."
