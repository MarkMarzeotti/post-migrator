#!/bin/zsh
# Reset a Local (by Flywheel) WordPress site's content, keeping header and footer.
# Usage: reset.sh <site name or folder, e.g. "Post Migrator 2" or post-migrator-2> [--apply]
# Must run outside the Claude Code sandbox (it blocks Local's MySQL socket).
set -e
SITE="$1"; MODE=dry; [[ "$2" == "--apply" ]] && MODE=apply
[[ -z "$SITE" ]] && { echo "Usage: reset.sh <site> [--apply]"; exit 1; }

LOCAL="$HOME/Library/Application Support/Local"
HERE="${0:A:h}"

# Resolve the Local site id and path from sites.json.
read -r ID SITE_PATH <<< "$(php -r '
  $s = json_decode(file_get_contents($argv[1]), true); $q = strtolower($argv[2]);
  foreach ($s as $id => $x) {
    $p = str_replace("~", getenv("HOME"), $x["path"]);
    if (strtolower($x["name"]) === $q || strtolower(basename($p)) === $q) { echo "$id $p"; exit; }
  }' "$LOCAL/sites.json" "$SITE")"
[[ -z "$ID" ]] && { echo "No Local site named '$SITE'"; exit 1; }
WP_ROOT="$SITE_PATH/app/public"
SOCK="$LOCAL/run/$ID/mysql/mysqld.sock"

MYSQLD="$(ps -axo command | grep -- "run/$ID/conf/mysql/my.cnf" | grep -v grep | head -1 | sed 's| --defaults-file.*||')"
[[ -z "$MYSQLD" ]] && { echo "Site '$SITE' is not running in Local. Start it first."; exit 1; }
MYSQLDUMP="${MYSQLD:h}/mysqldump"

INI_DIR="$(mktemp -d)"
printf 'mysqli.default_socket="%s"\nerror_reporting=0\ndisplay_errors=0\n' "$SOCK" > "$INI_DIR/zz-local.ini"
export PHP_INI_SCAN_DIR="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;'):$INI_DIR"

BACKUP=""
if [[ $MODE == apply ]]; then
  BACKUP="$SITE_PATH/backups/reset-$(date +%Y%m%d-%H%M%S)"
  mkdir -p "$BACKUP"
  "$MYSQLDUMP" --socket="$SOCK" -uroot -proot local > "$BACKUP/db.sql" 2>/dev/null
  tail -1 "$BACKUP/db.sql" | grep -q "Dump completed" || { echo "Database backup failed; aborting."; exit 1; }
  cp -R "$WP_ROOT/wp-content/uploads" "$BACKUP/uploads"
  echo "Backup: $BACKUP"
fi

php "$HERE/reset.php" "$WP_ROOT" "$MODE" "$BACKUP" 2>&1 | grep -v 'mcp-server: ability'
rm -rf "$INI_DIR"
