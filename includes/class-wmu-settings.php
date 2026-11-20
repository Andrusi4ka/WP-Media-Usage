<?php
/**
 * The plugin's checkboxes: Mark unused files (user's request, 2026-11-19: a checkbox as WP Banner's instead of the Check usage
 * button) and Mark duplicate files (2026-11-20: only exact copies, only marked). Ticked: that check runs at once and its marks
 * show (they stay until it is unticked). Unticked: none of its marks, filters or fields; what was found is kept. Each ticking
 * checks afresh.
 *
 * Options wmu_enabled and wmu_dupes (1 / 0; off until ticked).
 */
defined('ABSPATH') || exit;

class WMU_Settings {
    const PARTS = array('unused'=>'wmu_enabled', 'dupes'=>'wmu_dupes');

    public static function on($part = 'unused') {
        return isset(self::PARTS[$part]) && (bool) get_option(self::PARTS[$part], 0);
    }

    // Any of them ticked: the Media Library's parts are needed.
    public static function any() {
        return self::on('unused') || self::on('dupes');
    }

    public static function set($part, $on) {
        if (isset(self::PARTS[$part])) { update_option(self::PARTS[$part], $on ? 1 : 0); }
    }
}
