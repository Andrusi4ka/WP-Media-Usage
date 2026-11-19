<?php
/**
 * The Media Library page's script and styles (upload.php only): assets/js/scan.js (the switch and the check) and, in the grid,
 * assets/js/grid.js (the Unused mark, the filters, Ignore selected); their strings in window.wmuMedia.
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
        // The grid (WordPress's media-grid on this page): its parts after WordPress's own (the switch always, the marks while on).
        if (wp_script_is('media-grid', 'enqueued')) { wp_enqueue_script('wmu-grid', WMU_URL.'assets/js/grid.js', array('wmu-scan', 'media-grid'), WMU_VERSION, true); }
    }

    private static function data() {
        return array(
            'ajax'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('wmu'),
            'on'=>WMU_Settings::on(),
            // A check finished at least once (else a ticked box finishes its check as the page opens).
            'checked'=>is_array(get_option(WMU_Scan::LAST)),
            'canSwitch'=>current_user_can(WMU_Ajax::SWITCH_CAP),
            'canScan'=>current_user_can(WMU_Ajax::SCAN_CAP),
            'last'=>WMU_Marks::last_text(),
            'l10n'=>self::strings(),
        );
    }

    // The "i" beside the checkbox: what it does, where it looks and what it cannot see (user's request, 2026-11-19).
    private static function tip() {
        return implode("\n\n", array(
            __('Ticked: the whole site is checked now and each file found nowhere gets an Unused mark. Unticked: no marks. Tick it again for a fresh check. It never deletes anything.', 'wp-media-usage'),
            __('Looks in: pages, posts, products and all other content, also drafts and the trash; featured images; WooCommerce galleries and category images; WPBakery and WoodMart elements, HTML blocks, theme settings and header; the block editor; the Customizer (logo, site icon); widgets and menus; video covers; the active theme\'s files; WP Swiper, WP Banner and Signatur.', 'wp-media-usage'),
            __('Cannot see: files used outside the site (newsletters, social media, other sites); files named only in the code of other plugins; old revisions. Such a file is marked Unused: tick Ignore for it.', 'wp-media-usage'),
        ));
    }

    private static function strings() {
        return array(
            'unused'=>__('Unused', 'wp-media-usage'),
            'filterUnused'=>__('Unused (not found on the site)', 'wp-media-usage'),
            'filterIgnored'=>__('Ignored in usage check', 'wp-media-usage'),
            'switch'=>__('Mark unused files', 'wp-media-usage'),
            'switchTip'=>self::tip(),
            'checking'=>__('Checking…', 'wp-media-usage'),
            'ignore'=>__('Ignore selected', 'wp-media-usage'),
            'unignore'=>__('Stop ignoring selected', 'wp-media-usage'),
            'failed'=>__('The check stopped with an error. Try again.', 'wp-media-usage'),
            'phases'=>array(
                'posts'=>__('Pages, posts and products', 'wp-media-usage'), 'covers'=>__('Audio and video covers', 'wp-media-usage'),
                'terms'=>__('Categories', 'wp-media-usage'), 'termdesc'=>__('Categories', 'wp-media-usage'),
                'options'=>__('Site and theme settings', 'wp-media-usage'), 'users'=>__('Users', 'wp-media-usage'),
                'theme'=>__('Theme files', 'wp-media-usage'), 'tables'=>__('Other plugins', 'wp-media-usage'),
                'resolve'=>__('Media Library', 'wp-media-usage')),
        );
    }
}
