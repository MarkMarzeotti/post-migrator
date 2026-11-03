#!/usr/bin/env bash
# Create a snapshot: database dump + wp-content archive + manifest.
# Usage: snapshot.sh <site> "<label>" ["notes"]
set -euo pipefail
source "$(dirname "$0")/_env.sh" "${1:-}"

LABEL="${2:?Usage: snapshot.sh <site> \"<label>\" [\"notes\"]}"
NOTES="${3:-}"
SLUG="$(echo "$LABEL" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-|-$//g')"
NAME="$(date +%Y%m%d-%H%M%S)-$SLUG"
DEST="$BACKUP_DIR/$NAME"
mkdir -p "$DEST"

echo "Dumping database $DB_NAME..."
mysqldump_cmd --single-transaction --routines --triggers --add-drop-table "$DB_NAME" | gzip > "$DEST/db.sql.gz"
gunzip -c "$DEST/db.sql.gz" | tail -1 | grep -q "Dump completed" || { echo "Database dump incomplete; aborting." >&2; rm -rf "$DEST"; exit 1; }

echo "Archiving wp-content (skipping node_modules, .git, caches)..."
tar -czf "$DEST/wp-content.tar.gz" -C "$WP_ROOT" \
  --exclude='node_modules' --exclude='.git' --exclude='wp-content/cache' \
  --exclude='wp-content/upgrade' --exclude='*.zip' wp-content

count() { mysql_cmd -N -e "SELECT COUNT(*) FROM ${TABLE_PREFIX}posts WHERE post_type='$1' AND post_status IN ('publish','draft','private','inherit')" "$DB_NAME"; }
{
  echo "label: $LABEL"
  echo "created: $(date '+%Y-%m-%d %H:%M:%S %Z')"
  echo "site: $SITE_DIR"
  echo "pages: $(count page)"
  echo "posts: $(count post)"
  echo "media: $(count attachment)"
  echo "db_size: $(du -h "$DEST/db.sql.gz" | cut -f1)"
  echo "files_size: $(du -h "$DEST/wp-content.tar.gz" | cut -f1)"
  [[ -n "$NOTES" ]] && echo "notes: $NOTES"
} > "$DEST/manifest.txt"

cat "$DEST/manifest.txt"
echo "snapshot: $NAME"
