#!/usr/bin/env bash
# List snapshots, newest first, with their manifest summary. Works while the site is stopped.
# Usage: list.sh <site>
set -euo pipefail
SITE="${1:?Usage: list.sh <site>}"
SITE_DIR="$(python3 - "$HOME/Library/Application Support/Local/sites.json" "$SITE" <<'PY'
import json, os, sys
sites, q = json.load(open(sys.argv[1])), sys.argv[2].lower()
for s in sites.values():
    p = os.path.expanduser(s.get("path", ""))
    if s.get("name", "").lower() == q or os.path.basename(p).lower() == q:
        print(p); break
PY
)"
[[ -n "$SITE_DIR" ]] || { echo "No Local site named '$SITE'" >&2; exit 1; }
BACKUP_DIR="$SITE_DIR/backups"

found=0
if [[ -d "$BACKUP_DIR" ]]; then
  for s in $(ls -1r "$BACKUP_DIR"); do
    m="$BACKUP_DIR/$s/manifest.txt"
    [[ -f "$m" ]] || continue   # skips reset-site-content backups, which have no manifest
    found=1
    printf '%s\n' "$s"
    grep -E '^(label|created|pages|posts|media|notes):' "$m" | sed 's/^/    /'
  done
fi
[[ $found -eq 1 ]] || echo "No snapshots in $BACKUP_DIR"
