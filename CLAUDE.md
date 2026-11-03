# Post Migrator

## Two-site sync (important)

This plugin is developed against two local WordPress installs for cross-site testing: this one ("Post Migrator 1") and a second, independent checkout at `../../../../../../post-migrator-2/app/public/wp-content/plugins/post-migrator` ("Post Migrator 2"), both pointing at the same GitHub remote (`git@github.com:MarkMarzeotti/post-migrator.git`). **Post Migrator 2 is a separate git checkout, not a symlink** — it does not automatically pick up changes made here.

**Whenever you change code in this plugin, mirror the same change to Post Migrator 2 before considering the task done** — don't wait until a live cross-site test is requested. Mechanically:

1. Check Post Migrator 2 for drift: `git log --oneline -1` and `git status --short` in its plugin directory.
2. If behind: `git stash push -u` (if it has uncommitted work), `git fetch && git merge origin/main --ff-only`.
3. Copy over every file touched this session (including new, uncommitted files) with a plain `cp`.
4. Rebuild (`npm run build`) and re-lint (`npm run test`) on Post Migrator 2 too.
5. Confirm `diff -rq` between the two sites' `includes/` and `src/` trees is empty.

A stale Post Migrator 2 produces misleading test results (a new feature silently doesn't run, or partial data is missing from a REST response) that look like a logic bug but are actually just a sync gap. This has happened twice already (the `post_parent` feature, and the content-diffing "Changes" column) — treat this as a standing requirement, not an occasional cleanup step.
