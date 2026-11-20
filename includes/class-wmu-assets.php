<?php
/**
 * The Media Library page's script and styles (upload.php only): assets/js/scan.js (the checkboxes and their checks) and, in the
 * grid, assets/js/grid.js (the Unused and Duplicate marks, the filters, Ignore selected); their strings in window.wmuMedia
 * (parts: unused, dupes; each ticked or not, its last check, its tip, its phases).
 */
defined('ABSPATH') || exit;

class WMU_Assets {
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
    }

    public static function enqueue($hook) {
        if ($hook !== 'upload.php') { return; }
        wp_enqueue_style('wmu-media', WMU_URL.'assets/css/media.css', array(), WMU_VERSION);
        wp_enqueue_script('wmu-scan', WMU_URL.'assets/js/scan.js', array('jquery', 'underscore'), WMU_VERSION, true);
        wp_localize_script('wmu-scan', 'wmuMedia', self::data());
        // The Duplicates window (its button beside Mark duplicate files).
        if (WMU_Settings::on('dupes')) { wp_enqueue_script('wmu-modal', WMU_URL.'assets/js/modal.js', array('wmu-scan'), WMU_VERSION, true); }
        // The grid (WordPress's media-grid on this page): its parts after WordPress's own (the switches always, the marks while on).
        if (wp_script_is('media-grid', 'enqueued')) { wp_enqueue_script('wmu-grid', WMU_URL.'assets/js/grid.js', array('wmu-scan', 'media-grid'), WMU_VERSION, true); }
    }

    private static function data() {
        return array(
            'ajax'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('wmu'),
            'canSwitch'=>current_user_can(WMU_Ajax::SWITCH_CAP),
            'canScan'=>current_user_can(WMU_Ajax::SCAN_CAP),
            // checked: a check finished at least once (else a ticked box finishes its check as the page opens).
            'parts'=>array(
                'unused'=>array('on'=>WMU_Settings::on('unused'), 'checked'=>is_array(get_option(WMU_Scan::LAST)), 'last'=>WMU_Marks::last_text(),
                    'label'=>__('Mark unused files', 'wp-media-usage'), 'tip'=>self::tip(), 'phases'=>self::phases()),
                // usageFresh: the usage check ran after the duplicates check (else it runs in the background); groups: the button's number.
                'dupes'=>array('on'=>WMU_Settings::on('dupes'), 'checked'=>is_array(get_option(WMU_Dupes::LAST)), 'last'=>WMU_Copies::last_text(),
                    'usageFresh'=>self::usage_fresh(), 'groups'=>WMU_Settings::on('dupes') ? WMU_Modal::count() : 0,
                    'label'=>__('Mark duplicate files', 'wp-media-usage'), 'tip'=>self::dupes_tip(),
                    'phases'=>array('sizes'=>__('File sizes', 'wp-media-usage'), 'hash'=>__('Comparing files', 'wp-media-usage'), 'write'=>__('Media Library', 'wp-media-usage'))),
            ),
            'l10n'=>self::strings(),
        );
    }

    private static function usage_fresh() {
        $usage = get_option(WMU_Scan::LAST);
        $dupes = get_option(WMU_Dupes::LAST);
        return is_array($usage) && (!is_array($dupes) || (int) $usage['time'] >= (int) $dupes['time']);
    }

    // The "i" beside the checkbox: what it does, where it looks and what it cannot see (user's request, 2026-11-19).
    private static function tip() {
        return implode("\n\n", array(
            __('Ticked: the whole site is checked now and each file found nowhere gets an Unused mark. Unticked: no marks. Tick it again for a fresh check. It never deletes anything.', 'wp-media-usage'),
            __('Looks in: pages, posts, products and all other content, also drafts and the trash; featured images; WooCommerce galleries and category images; WPBakery and WoodMart elements, HTML blocks, theme settings and header; the block editor; the Customizer (logo, site icon); widgets and menus; video covers; the active theme\'s files; WP Swiper, WP Banner and Signatur.', 'wp-media-usage'),
            __('Cannot see: files used outside the site (newsletters, social media, other sites); files named only in the code of other plugins; old revisions. Such a file is marked Unused: tick Ignore for it.', 'wp-media-usage'),
        ));
    }
    private static function dupes_tip() {
        return implode("\n\n", array(
            __('Ticked: every file in the Media Library is compared with the others now, and each file uploaded more than once (the very same file, whatever its name) gets a Duplicate mark. Unticked: no marks. Tick it again for a fresh check. It never deletes anything.', 'wp-media-usage'),
            __('Only exact copies: the same picture resized, cropped or saved again in another format is not a copy. WordPress\'s own sizes of an image are not counted.', 'wp-media-usage'),
            __('The Duplicates filter shows each file next to its copies; a file\'s details show its copies and whether each is used. Keep the one in use, delete the others.', 'wp-media-usage'),
        ));
    }
    private static function phases() {
        return array(
            'posts'=>__('Pages, posts and products', 'wp-media-usage'), 'covers'=>__('Audio and video covers', 'wp-media-usage'),
            'terms'=>__('Categories', 'wp-media-usage'), 'termdesc'=>__('Categories', 'wp-media-usage'),
            'options'=>__('Site and theme settings', 'wp-media-usage'), 'users'=>__('Users', 'wp-media-usage'),
            'theme'=>__('Theme files', 'wp-media-usage'), 'tables'=>__('Other plugins', 'wp-media-usage'),
            'resolve'=>__('Media Library', 'wp-media-usage'));
    }

    private static function strings() {
        return array(
            'unused'=>__('Unused', 'wp-media-usage'),
            'duplicate'=>__('Duplicate', 'wp-media-usage'),
            'filterUnused'=>__('Unused (not found on the site)', 'wp-media-usage'),
            'filterIgnored'=>__('Ignored in usage check', 'wp-media-usage'),
            'filterDuplicates'=>__('Duplicates (the same file again)', 'wp-media-usage'),
            'checking'=>__('Checking…', 'wp-media-usage'),
            'ignore'=>__('Ignore selected', 'wp-media-usage'),
            'unignore'=>__('Stop ignoring selected', 'wp-media-usage'),
            'failed'=>__('The check stopped with an error. Try again.', 'wp-media-usage'),
            /* translators: %d: number of groups of duplicates */
            'showDuplicates'=>__('Show duplicates (%d)', 'wp-media-usage'),
            'modalTitle'=>__('Duplicates', 'wp-media-usage'),
            'modalHelp'=>__('Each group is one file uploaded more than once. Edit the places that use a copy (they open in a new tab), then delete the copies nobody uses.', 'wp-media-usage'),
            'refresh'=>__('Check where they are used again', 'wp-media-usage'),
            'findingUsage'=>__('Finding where they are used…', 'wp-media-usage'),
            'loading'=>__('Loading…', 'wp-media-usage'),
            'close'=>__('Close', 'wp-media-usage'),
            'confirmDelete'=>__("You are about to permanently delete this file from your site.\nThis action cannot be undone.\n'Cancel' to stop, 'OK' to delete.", 'wp-media-usage'),
            'confirmDeleteUsed'=>__("This copy is used on the site: those places will show a missing image.\nYou are about to permanently delete it. This action cannot be undone.\n'Cancel' to stop, 'OK' to delete.", 'wp-media-usage'),
            'deleteFailed'=>__('The file could not be deleted.', 'wp-media-usage'),
        );
    }
}
