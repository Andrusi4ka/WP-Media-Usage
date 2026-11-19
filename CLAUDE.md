# WP Media Usage — notes for the next agent

The user's 6th plugin (2026-11-19). Read this, then `readme.txt`.

## What it is

Shows in the **standard Media Library** which files are used nowhere on the site. The user's wish (2026-11-19): no separate list, no quarantine, no page of its own: a mark on the thumbnails, then select and delete (WordPress's own Bulk select → Delete permanently) or ignore. Keep it so.

## Where things are

Small files, one job each (user's request, 2026-11-19: no large files or functions; keep it so — a new job gets a new file).

- `wp-media-usage.php` — header (Author Relevant AS, https://relevant.no/: the Plugins page shows it, no page of its own: the user's choice), constants, loads `includes/class-wmu-<part>.php` and calls each admin class's `init()`; `save_post` → `WMU_Resolve::saved()` on every request.
- `class-wmu-extract.php` — `WMU_Extract`, no WordPress: IDs (`ids_from_text()` = `ids_marked()` + `ids_in_attributes()` + `ids_in_json()`) and uploads paths (`paths_from_text()`) in text, the same in stored values (`from_value()` walks unserialized / JSON data), an attachment's files (`files_of()`). A key is a media key when one of its words (split at `_ - camelCase`) is in `MEDIA_WORDS` and its last word is not in `SETTING_WORDS`; an array's `id` counts beside `ID_SIBLINGS` keys or under a media key; WPBakery param groups (URL-encoded) and `vc_raw_html` / `vc_raw_js` (base64) are decoded; paths: after `uploads/`, `sites/N/` dropped, lower case, `.jpg.webp` → `.jpg`. **When in doubt it counts as used** (a wrong Unused gets a used file deleted).
- `class-wmu-scan.php` — `WMU_Scan`: the scan's course (`start()`, `run()`, `step()`, `finish()`), recording (`text()`, `value()`, `found()`: slots ['n', 's' first `KEEP` tokens]). Tokens `p:ID`, `t:ID`, `o:name`, `u:ID`, `f:path`, `x:Label:ID`. Options `wmu_scan_state` (between requests, not autoloaded), `wmu_last_scan`.
- `class-wmu-phases.php` — `WMU_Phases`: one `read_<phase>()` per phase: posts (+ their meta), covers (attachments' own meta: video covers, Smooth Row Video posters), terms, termdesc, options, users, theme (active theme + parent: paths only), tables (Signatur's `signatur_signatures.settings`; filter `wmu_tables`).
- `class-wmu-resolve.php` — `WMU_Resolve`: each attachment used if its ID or any of its files was found (`_wmu_state`, `_wmu_where`); `saved()`: a saved post's files lose Unused at once (nothing becomes Unused until the next scan).
- `class-wmu-settings.php` — `WMU_Settings`: the checkbox **Mark unused files** (user's request, 2026-11-19, instead of the Check usage button; option `wmu_enabled`, off until ticked). The user's model, keep it: tick → the site is checked at once (scan.js `turn()` → `check()`, progress beside it) → the page reloads with the marks; work; untick → reload, no marks. No automatic checks (a version that re-checked by itself when the site changed was rejected the same day). Unticked, only the checkbox shows (the main file inits `WMU_Library`, `WMU_List`, `WMU_Details` and `WMU_Resolve::saved` only while ticked). Ticking needs manage_options; the check upload_files (and the box ticked); a check saved < 30 s ago is joined, not restarted (`WMU_Ajax::state()`); a ticked box whose check never finished finishes it as the page opens (`checked`).
- `class-wmu-marks.php` — `WMU_Marks`: `mark()`, `ignore()` (`_wmu_ignored`), the filters' `meta_query()`, `last_text()`.
- `class-wmu-places.php` — `WMU_Places`: a token as a link (`html()`), `where_html()`, `usage_html()`, `pill()`.
- `class-wmu-assets.php` — `WMU_Assets`: `upload.php` only: `scan.js` always, `grid.js` in the grid (after `media-grid`); `window.wmuMedia`.
- `class-wmu-library.php` — `WMU_Library`: `wmu` in each attachment's JS, the grid's `query[wmu]` and the list's `?wmu=` filters.
- `class-wmu-list.php` — `WMU_List`: list column, bulk Ignore / Stop ignoring, notice.
- `class-wmu-details.php` — `WMU_Details`: compat fields Usage + Ignore (a hidden `wmu_form` tells an unchecked box from a form without it).
- `class-wmu-ajax.php` — `WMU_Ajax`: `wmu_switch` (`SWITCH_CAP`), `wmu_scan` (`SCAN_CAP` upload_files, only while on, `SECONDS` 3 a request), `wmu_ignore` (edit_post per file).
- `uninstall.php` — deleting the plugin removes its meta and options; media files are never touched.
- `assets/js/scan.js` — the checkbox (WP Banner's switch look, `.wmu-switch`), the last check and the check (`window.wmuSwitchBox()`), in the list after the page title. `assets/js/grid.js` — the grid: `markThumbnails()` (Attachment.Library prototype render / initialize, redrawn on `change:wmu`), `addFilters()` (AttachmentFilters.All, others get `wmu: null`), `IgnoreButton`, `addToolbarItems()` (Ignore at -79 beside delete, only while on; the checkbox at -65 after Bulk select, user's request: not a media-button, so Bulk select hides it). WordPress's views are patched on their prototypes: core keeps references to them in its defaults.
- `tests/run.php` — WMU_Extract checks (each a real plugin's way of keeping a picture); CI runs it. `tests/site-dry-run.php <wp-load.php>` — a read-only scan of a real site (creativity.local 2026-11-19: 60 files, 26 unused, each confirmed by searching the database).

## WordPress's grid toolbar: two traps (found 2026-11-19)

- It hides **every `<label>`** in it but the search's (`.media-frame.mode-grid .media-toolbar label:not(.media-search-input-label)`: 1px, clipped; its filters' labels are for screen readers). The checkbox is a label: media.css shows it again with a stronger selector. The user saw no checkbox until then.
- It has `overflow:auto`: anything sticking out (the "i" tooltip) is cut off. media.css lets it overflow only while the tip shows (`:has(.wmu-tip:hover)`).
- Check such things by rendering the toolbar's markup with WordPress's own CSS (buttons, media-views, wp-admin media, dashicons) in Chrome; the agent cannot log in to the admin.

The "i" beside the checkbox (`WMU_Assets::tip()`, user's request): what it does, where it looks, what it cannot see; `.wmu-tip` as WP Banner's tip (text on #1d2327, pre-line).

## The user's rules

- Replies in Ukrainian; UI strings in English.
- Git author: Andrii Boiko (repo config user.name), never Claude.
- Questions are discussion only; code after an explicit go-ahead. Commit / push / release only when asked; no Claude co-author.
- Never write to the site database in tests (the dry run only reads; the user activated the plugin).

## Releases

Repository https://github.com/Andrusi4ka/WP-Media-Usage (public), branch main. Updates: `class-wmu-updates.php` (Plugin Update Checker in `vendor/`, release asset `wp-media-usage.zip`), header `Update URI`. A release: the same version in the header, `WMU_VERSION` and readme.txt Stable tag (+ a Changelog entry), then tag vX.Y.Z: release.yml runs tests.yml, checks the three match and builds the zip (git archive, export-ignore). Only on the user's command. Released: v1.0.0 (2026-11-19). The local folder is the git working copy: never update it through WordPress here (that wipes .git, as it did WP Swiper's).

## Not done yet

- Translations (.pot).
