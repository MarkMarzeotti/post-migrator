---
name: reset-site-content
description: Wipe a Local WordPress site's content (all posts, pages, CPTs and revisions, plus every media item and its upload files) while leaving the header and footer intact. Use when asked to clear, reset or empty Post Migrator 1 or Post Migrator 2 (or another Local site), "delete all posts", or "delete all images not used in the header/footer".
---

# Reset site content

Clears a Local (Flywheel) WordPress site to just its header and footer. `scripts/reset.sh` (in this skill folder, `.claude/skills/reset-site-content/` of the post-migrator plugin) does all the work. It finds the site in Local's `sites.json`, connects through the site's MySQL socket, and loads WordPress directly. WP-CLI on this machine has no bundled commands, so the script doesn't use it.

## What it does

- **Deletes** every post of every content post type: posts, pages, custom post types, drafts, auto-drafts, trash and revisions.
- **Keeps** config post types: `nav_menu_item`, `wp_global_styles`, `wp_navigation`, block templates and template parts, fonts, custom CSS, and ACF definitions.
- **Converts menu items into custom links.** Each item that links to a post or page keeps its title and URL. Without this, WordPress deletes a page's menu items along with the page, which would empty the header and footer menus. The links 404 until the pages exist again.
- **Keeps the header/footer images.** It reads the `get_field('…', 'options')` calls in the active theme's `header.php` and `footer.php`, plus `site_icon` and `custom_logo`. Any attachment ID those fields reference is kept, including inside repeaters.
- **Deletes every other image**, including its files in `uploads`: the original, every resized copy, and the pre-`-scaled` original. Then it checks that each of those files is gone. It never touches other files in `uploads`.
- **Fixes the homepage settings.** If `page_on_front` or `page_for_posts` points at a deleted page, it resets them and sets "Your homepage displays" to "Latest posts".
- **Backs up before applying**, to `~/Local Sites/<site>/backups/reset-<timestamp>/`. The backup holds `db.sql`, a copy of `uploads/`, and `deleted-files.txt`.

## Steps

The site must be running in Local. Run commands from the plugin root, with `dangerouslyDisableSandbox: true`, because the sandbox blocks the MySQL socket.

1. **Dry run.** Shows what would be deleted and kept, and changes nothing:
   ```
   .claude/skills/reset-site-content/scripts/reset.sh "Post Migrator 2"
   ```
   Check the "Images kept" list. If it's empty, or misses an image the header or footer shows, stop and tell the user. The header or footer may read images some other way, such as a hardcoded ID or a block. Don't apply until the list is right.
2. **Apply.**
   ```
   .claude/skills/reset-site-content/scripts/reset.sh "Post Migrator 2" --apply
   ```
3. **Verify.**
   - The output should show `Files of deleted attachments still on disk: 0`, and every menu should keep its item count.
   - Load the homepage with `curl -s http://<site>.local/`. Confirm it returns 200 and still includes the logo filename and menu items.
4. **Report** the counts, the images kept, the backup path, and the two side effects: menu links now 404, and the homepage setting changed.

If asked to do this to "both sites", run it once for Post Migrator 1 and once for Post Migrator 2.
