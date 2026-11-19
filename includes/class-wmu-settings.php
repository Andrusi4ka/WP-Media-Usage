<?php
/**
 * The plugin's checkbox, Mark unused files (user's request, 2026-11-19: a checkbox as WP Banner's instead of the Check usage
 * button). Ticked: the site is checked at once and the marks show (they stay until it is unticked). Unticked: no marks,
 * filters, column or fields anywhere; what was found and the Ignored files are kept. Each ticking checks the site afresh.
 *
 * Option wmu_enabled (1 / 0; off until ticked).
 */
defined('ABSPATH') || exit;

class WMU_Settings {
    const ENABLED = 'wmu_enabled';

    public static function on() {
        return (bool) get_option(self::ENABLED, 0);
    }

    public static function set($on) {
        update_option(self::ENABLED, $on ? 1 : 0);
    }
}
