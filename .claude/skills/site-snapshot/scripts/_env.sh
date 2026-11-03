#!/usr/bin/env bash
# Shared setup for site-snapshot scripts. Source with the site as $1:
#   source _env.sh "<site name or folder, e.g. 'Post Migrator 2' or post-migrator-2>"
# Sets SITE_DIR, WP_ROOT, BACKUP_DIR, SOCKET, MYSQL_BIN, DB_*, TABLE_PREFIX,
# and the mysql_cmd / mysqldump_cmd helpers.
set -euo pipefail

SITE="${1:?Missing site name, e.g. \"Post Migrator 1\" or post-migrator-2}"
LOCAL_APP="$HOME/Library/Application Support/Local"

# Resolve the Local site id, path, and MySQL version by name or folder name.
read -r SITE_ID MYSQL_VERSION SITE_DIR < <(python3 - "$LOCAL_APP/sites.json" "$SITE" <<'PY'
import json, os, sys
sites, q = json.load(open(sys.argv[1])), sys.argv[2].lower()
for sid, s in sites.items():
    p = os.path.expanduser(s.get("path", ""))
    if s.get("name", "").lower() == q or os.path.basename(p).lower() == q:
        print(sid, s["services"]["mysql"]["version"], p); break
PY
) || true
[[ -n "${SITE_ID:-}" ]] || { echo "No Local site named '$SITE'" >&2; exit 1; }

WP_ROOT="$SITE_DIR/app/public"
BACKUP_DIR="$SITE_DIR/backups"
[[ -f "$WP_ROOT/wp-config.php" ]] || { echo "No wp-config.php under $WP_ROOT" >&2; exit 1; }

SOCKET="$LOCAL_APP/run/$SITE_ID/mysql/mysqld.sock"
[[ -S "$SOCKET" ]] || { echo "Site '$SITE' is not running in Local. Start it first." >&2; exit 1; }

ARCH="darwin-arm64"; [[ "$(uname -m)" == "arm64" ]] || ARCH="darwin"
MYSQL_BIN="$(ls -d "$LOCAL_APP"/lightning-services/mysql-"$MYSQL_VERSION"*/bin/"$ARCH"/bin 2>/dev/null | head -1)"
[[ -x "$MYSQL_BIN/mysqldump" ]] || { echo "mysqldump not found for MySQL $MYSQL_VERSION" >&2; exit 1; }

# DB credentials and table prefix from wp-config.php.
cfg() { sed -nE "s/.*define\( *'$1', *'([^']*)'.*/\1/p" "$WP_ROOT/wp-config.php" | head -1; }
DB_NAME="$(cfg DB_NAME)"; DB_USER="$(cfg DB_USER)"; DB_PASS="$(cfg DB_PASSWORD)"
TABLE_PREFIX="$(sed -nE "s/^\\\$table_prefix *= *'([^']*)'.*/\1/p" "$WP_ROOT/wp-config.php" | head -1)"
TABLE_PREFIX="${TABLE_PREFIX:-wp_}"

mysql_cmd()     { "$MYSQL_BIN/mysql"     --socket="$SOCKET" -u"$DB_USER" -p"$DB_PASS" "$@" 2>&1 | grep -v "Using a password" || true; }
mysqldump_cmd() { "$MYSQL_BIN/mysqldump" --socket="$SOCKET" -u"$DB_USER" -p"$DB_PASS" "$@" 2> >(grep -v "Using a password" >&2); }
