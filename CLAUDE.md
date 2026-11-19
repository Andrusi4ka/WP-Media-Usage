# WP Media Usage — notes for the next agent

The user's 6th plugin (2026-11-19). Read this, then `readme.txt`.

## What it is

Shows in the **standard Media Library** which files are used nowhere on the site. The user's wish (2026-11-19): no separate list, no quarantine, no page of its own: a mark on the thumbnails, then select and delete (WordPress's own Bulk select → Delete permanently) or ignore. Keep it so.

## Where things are

- `wp-media-usage.php` — loader (`WMU_VERSION`, `WMU_DIR`, `WMU_URL`); `save_post` hooked for every request (REST, AJAX, admin).
- `includes/class-wmu-extract.php` — `WMU_Extract`, no WordPress: IDs and uploads paths in text (`ids_from_text()`, `paths_from_text()`) and in stored values (`from_value()`: walks unserialized / JSON data), an attachment's files (`files_of()`). Rules: a key is a media key when one of its words (split at `_ - camelCase`) is in `MEDIA_WORDS` and its last word is not in `SETTING_WORDS`; an array's `id` counts beside `ID_SIBLINGS` keys or under a media key; in text: `wp-image-N`, `attachment_N`, `wp-att-N`, `attachment_id=N`, `uploads/…?id=N` (WPBakery Design Options), media-key attributes, JSON pairs (`"id"`, media keys); WPBakery param groups (URL-encoded) and `vc_raw_html` / `vc_raw_js` (base64) decoded. Paths: after `uploads/`, `sites/N/` dropped, lower case, `.jpg.webp` → `.jpg`. **When in doubt it counts as used** (a wrong Unused gets a used file deleted).
- `includes/class-wmu-scanner.php` — `WMU_Scanner`: phases `posts`, `covers` (attachment meta: video covers, Smooth Row Video posters), `terms`, `termdesc`, `options`, `users`, `theme` (active theme + parent files: paths only), `tables` (Signatur's `signatur_signatures.settings`; filter `wmu_tables`), `resolve` (each attachment: used if its ID or any of its files was found; meta `_wmu_state` used / unused, `_wmu_where` ['n', 's' first 5 tokens]). Sources as tokens `p:ID`, `t:ID`, `o:name`, `u:ID`, `f:path`, `x:Label:ID`. State between requests in option `wmu_scan_state` (not autoloaded), the last result in `wmu_last_scan`. `mark_post()`: a saved post's files lose Unused at once (nothing becomes Unused until the next scan).
- `includes/class-wmu-media.php` — `WMU_Media`: assets on `upload.php` only; `wmu` in each attachment's JS (`for_js()`); the grid's `query[wmu]` (`grid_query()`) and the list's `?wmu=` → meta queries (unused and not ignored / ignored); compat fields Usage + Ignore (`_wmu_ignored`; a hidden `wmu_form` tells an unchecked box from a form without it); list column, filter select, bulk actions; AJAX `wmu_scan` (`manage_options`, 3 s a request) and `wmu_ignore` (edit_post per file).
- `assets/js/media.js` — Check usage button after the page title (reloads when done); the grid: `Attachment.Library` prototype render/initialize patched for the badge (redrawn on `change:wmu`), `AttachmentFilters.All.createFilters` adds `wmu_unused` / `wmu_ignored` (every other filter gets `wmu: null`), `AttachmentsBrowser.createToolbar` adds `IgnoreButton` (shown in Bulk select, Stop ignoring under the Ignored filter, requeries under our filters). WordPress's own classes are patched on their prototypes: they are captured by reference in core's defaults, replacing them would not work.
- `tests/run.php` — WMU_Extract checks (each a real plugin's way of keeping a picture); CI runs it. `tests/site-dry-run.php <wp-load.php>` — a read-only scan of a real site, lists what would be Unused (checked on creativity.local 2026-11-19: 60 files, 26 unused, each confirmed by searching the database).

## The user's rules

- Replies in Ukrainian; UI strings in English.
- Questions are discussion only; code after an explicit go-ahead. Commit / push / release only when asked; no Claude co-author.
- Never write to the site database in tests (the dry run only reads; the plugin was not activated by the agent).

## Not done yet

- GitHub repository and updates (Plugin Update Checker, release.yml) — when the user creates the repo.
- Translations (.pot).
