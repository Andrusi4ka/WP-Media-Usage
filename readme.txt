=== WP Media Usage ===
Contributors: andrusi4ka
Tags: media library, unused images, duplicate images, cleanup
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Shows in the Media Library which files are not used anywhere on the site, and which were uploaded more than once.

== Description ==

* **Mark unused files** (a checkbox at the top of the Media Library): tick it and the whole site is checked at once; every file found nowhere gets a red **Unused** label on its thumbnail. Untick it and the labels are gone. Tick it again for a fresh check. It never deletes anything.
* The media type filter gets **Unused (not found on the site)** and **Ignored in usage check**. Select the files with Bulk select and delete them as usual, or press **Ignore selected**.
* A file's details show where it is used (links to the pages, products, settings), and an **Ignore** box for a file used outside the site (newsletters, social media).
* List view: a Usage column, the same filters, and the bulk actions Ignore / Stop ignoring in usage check.
* A page or post saved: the files it uses lose their Unused label at once.
* **Mark duplicate files** (a second checkbox beside it): tick it and every file in the Media Library is compared with the others; each file uploaded more than once (the very same file, whatever its name) gets a **Duplicate** label. Only exact copies: a resized, cropped or re-saved picture is not one. The **Duplicates (the same file again)** filter shows each file next to its copies; a file's details and the list's Usage column show its copies and whether each is used. It never deletes anything.
* **Show duplicates** (a button beside it): a window with each group of copies, every copy's thumbnail beside the pages, products and settings that use it (links open in a new tab, to edit them by hand). Where they are used is found in the background, without ticking Mark unused files.

Where it looks: the content and settings of every page, post, product and other post type (any status but auto-drafts, not revisions); featured images; WooCommerce galleries and category images; WPBakery (images, galleries, Design Options backgrounds, param groups, raw HTML); WoodMart HTML blocks, theme settings and header; blocks of the block editor; the Customizer (logo, header, site icon); widgets; menus; user profiles; video covers; the active theme's files; WP Swiper, WP Banner and Signatur.

It cannot see files used outside the site or written into code it does not read. Look at a file before deleting it.

== Changelog ==

= 1.1.0 =
* Mark duplicate files: a second checkbox; exact copies (the same file uploaded again under any name) get a Duplicate label, a Duplicates filter, their copies in the details and the list.
* Show duplicates: a window with each group of copies, every copy's thumbnail beside where it is used (found in the background), and Delete permanently under it.

= 1.0.0 =
* First version: the Mark unused files checkbox, the Unused label, filters, Ignore, Used in.
