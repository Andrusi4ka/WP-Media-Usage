=== WP Media Usage ===
Contributors: andrusi4ka
Tags: media library, unused images, cleanup
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Shows in the Media Library which files are not used anywhere on the site.

== Description ==

* **Check usage** at the top of the Media Library looks through the whole site and marks every file found nowhere with a red **Unused** label on its thumbnail. It never deletes anything.
* The media type filter gets **Unused (not found on the site)** and **Ignored in usage check**. Select the files with Bulk select and delete them as usual, or press **Ignore selected**.
* A file's details show where it is used (links to the pages, products, settings), and an **Ignore** box for a file used outside the site (newsletters, social media).
* List view: a Usage column, the same filters, and the bulk actions Ignore / Stop ignoring in usage check.
* A page or post saved after a check: the files it uses lose their Unused label at once. Run Check usage again to find files that are no longer used.

Where it looks: the content and settings of every page, post, product and other post type (any status but auto-drafts, not revisions); featured images; WooCommerce galleries and category images; WPBakery (images, galleries, Design Options backgrounds, param groups, raw HTML); WoodMart HTML blocks, theme settings and header; blocks of the block editor; the Customizer (logo, header, site icon); widgets; menus; user profiles; video covers; the active theme's files; WP Swiper, WP Banner and Signatur.

It cannot see files used outside the site or written into code it does not read. Look at a file before deleting it.

== Changelog ==

= 1.0.0 =
* First version: Check usage, the Unused label, filters, Ignore, Used in.
