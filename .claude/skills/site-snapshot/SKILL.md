---
name: site-snapshot
description: Save, list, and restore point-in-time snapshots (database + media uploads) of a Local WordPress site such as Post Migrator 1 or Post Migrator 2. Use when asked to back up, snapshot, checkpoint, or save a site's current state, to list saved snapshots, or to roll back / restore / "go back to" an earlier state. Restores never touch theme or plugin files.
---

# Site snapshot

Point-in-time snapshots of a Local (Flywheel) WordPress site, so you can return to a known
state while building. The scripts live in this skill folder,
`.claude/skills/site-snapshot/scripts/` of the post-migrator plugin. They find the site in
Local's `sites.json` by its name ("Post Migrator 1") or folder (`post-migrator-2`), and connect
through the site's MySQL socket.

Each snapshot is a folder at `~/Local Sites/<site>/backups/<YYYYMMDD-HHMMSS>-<label-slug>/`
holding:

- `db.sql.gz`: full `mysqldump` of the site database, checked for "Dump completed"
- `wp-content.tar.gz`: all of `wp-content`, without `node_modules`, `.git`, caches, or `*.zip`
- `manifest.txt`: label, timestamp, page/post/media counts, sizes, and notes

The same `backups/` folder also holds `reset-*` backups from `reset-site-content`. Those have
no manifest, so `list.sh` skips them.

Run the commands from the plugin root, with `dangerouslyDisableSandbox: true`, because the
sandbox blocks the MySQL socket. Snapshot and restore need the site to be running in Local.
Listing works while the site is stopped.

## Snapshot

```
.claude/skills/site-snapshot/scripts/snapshot.sh "Post Migrator 1" "<short label>" "<notes>"
```

Give it a label that names the milestone ("homepage + blog", "before pull test"). Write notes
that someone coming back weeks later can use to recognize the state. Report the snapshot name
from the script's last line, plus the counts.

## List

```
.claude/skills/site-snapshot/scripts/list.sh "Post Migrator 1"
```

When the user says something like "go back to when the blog was done", match their words
against the labels and notes, and confirm which snapshot you picked.

## Restore

Restoring overwrites the site's content, so **confirm the site and snapshot name with the user
first.**

```
.claude/skills/site-snapshot/scripts/restore.sh "Post Migrator 1" <snapshot-name>
```

- A restore covers the **database and `wp-content/uploads` only**. Theme and plugin files,
  including this plugin's working tree, are never overwritten. The script pulls only `uploads`
  out of the archive and refuses extra arguments. Don't work around this with tar, rsync, or
  cp, even when asked to roll back code. Code is managed through git. The themes and plugins
  in the archive are a read-only reference copy.
- Every restore first saves a `pre-restore` snapshot of the current state, so a restore can
  itself be undone. Tell the user its name.
- After a restore, check the site: `curl` the front page and a couple of key URLs, and expect
  200s.

## Housekeeping

Snapshots are mostly uploads, often tens of MB each. If `backups/` grows large, list the
snapshots and offer to delete old `pre-restore-*` ones. Never delete a snapshot without the
user's go-ahead.
