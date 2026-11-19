<?php
/**
 * The Media Library's list view: a Usage column, and the bulk actions Ignore / Stop ignoring in usage check with their notice.
 */
defined('ABSPATH') || exit;

class WMU_List {
    const ACTIONS = array('wmu_ignore'=>true, 'wmu_unignore'=>false);

    public static function init() {
        add_filter('manage_media_columns', array(__CLASS__, 'columns'));
        add_action('manage_media_custom_column', array(__CLASS__, 'column'), 10, 2);
        add_filter('bulk_actions-upload', array(__CLASS__, 'bulk_actions'));
        add_filter('handle_bulk_actions-upload', array(__CLASS__, 'bulk_handle'), 10, 3);
        add_action('admin_notices', array(__CLASS__, 'bulk_notice'));
    }

    public static function columns($columns) {
        $columns['wmu_usage'] = __('Usage', 'wp-media-usage');
        return $columns;
    }
    public static function column($name, $id) {
        if ($name !== 'wmu_usage') { return; }
        $mark = WMU_Marks::mark($id);
        if ($mark['state'] === '') { echo '<span class="wmu-muted">—</span>'; return; }
        // phpcs:disable WordPress.Security.EscapeOutput -- built from escaped parts (WMU_Places).
        if ($mark['ignored']) { echo WMU_Places::pill(__('Ignored', 'wp-media-usage'), true).' '; }
        echo $mark['state'] === 'unused' ? WMU_Places::pill(__('Unused', 'wp-media-usage')) : WMU_Places::where_html($id);
        // phpcs:enable
    }

    public static function bulk_actions($actions) {
        $actions['wmu_ignore'] = __('Ignore in usage check', 'wp-media-usage');
        $actions['wmu_unignore'] = __('Stop ignoring in usage check', 'wp-media-usage');
        return $actions;
    }
    public static function bulk_handle($redirect, $action, $ids) {
        if (!isset(self::ACTIONS[$action])) { return $redirect; }
        $done = 0;
        foreach ((array) $ids as $id) {
            if (get_post_type($id) === 'attachment' && current_user_can('edit_post', $id)) { WMU_Marks::ignore((int) $id, self::ACTIONS[$action]); $done++; }
        }
        return add_query_arg(array('wmu_done'=>$done, 'wmu_action'=>self::ACTIONS[$action] ? 'ignore' : 'unignore'), $redirect);
    }
    public static function bulk_notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!isset($_GET['wmu_done']) || !$screen || $screen->id !== 'upload') { return; } // phpcs:ignore WordPress.Security.NonceVerification
        $n = (int) $_GET['wmu_done']; // phpcs:ignore WordPress.Security.NonceVerification
        /* translators: %d: number of files */
        $text = ($_GET['wmu_action'] ?? '') === 'unignore' // phpcs:ignore WordPress.Security.NonceVerification
            ? sprintf(_n('%d file is checked for usage again.', '%d files are checked for usage again.', $n, 'wp-media-usage'), $n)
            : sprintf(_n('%d file is ignored in usage check.', '%d files are ignored in usage check.', $n, 'wp-media-usage'), $n);
        echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($text).'</p></div>';
    }
}
