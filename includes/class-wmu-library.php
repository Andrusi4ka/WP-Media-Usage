<?php
/**
 * The Media Library's queries: each attachment's mark in the grid's data (`wmu`), and the filters Unused / Ignored, in the
 * grid (query[wmu], from grid.js) and in the list (?wmu=, its own select beside the date filter).
 */
defined('ABSPATH') || exit;

class WMU_Library {
    const FILTERS = array('unused', 'ignored');

    public static function init() {
        add_filter('wp_prepare_attachment_for_js', array(__CLASS__, 'for_js'), 10, 2);
        add_filter('ajax_query_attachments_args', array(__CLASS__, 'grid_query'));
        add_action('restrict_manage_posts', array(__CLASS__, 'list_filter'));
        add_action('pre_get_posts', array(__CLASS__, 'list_query'));
    }

    public static function for_js($response, $attachment) {
        $response['wmu'] = WMU_Marks::mark($attachment->ID);
        return $response;
    }

    // The filter chosen: unused, ignored or ''.
    private static function chosen($value) {
        $value = sanitize_key(wp_unslash((string) $value));
        return in_array($value, self::FILTERS, true) ? $value : '';
    }

    public static function grid_query($args) {
        $which = self::chosen($_REQUEST['query']['wmu'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification -- WordPress checks the media query's own permission.
        if ($which !== '') { $args['meta_query'] = WMU_Marks::meta_query($args['meta_query'] ?? array(), $which); }
        return $args;
    }

    public static function list_filter($post_type) {
        if ($post_type !== 'attachment') { return; }
        $which = self::chosen($_GET['wmu'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
        $labels = array('unused'=>__('Unused (not found on the site)', 'wp-media-usage'), 'ignored'=>__('Ignored in usage check', 'wp-media-usage'));
        echo '<label class="screen-reader-text" for="wmu-filter">'.esc_html__('Filter by usage', 'wp-media-usage').'</label>';
        echo '<select name="wmu" id="wmu-filter"><option value="">'.esc_html__('All usage', 'wp-media-usage').'</option>';
        foreach ($labels as $value => $label) { echo '<option value="'.esc_attr($value).'"'.selected($which, $value, false).'>'.esc_html($label).'</option>'; }
        echo '</select>';
    }

    public static function list_query($query) {
        if (!is_admin() || !$query->is_main_query() || ($GLOBALS['pagenow'] ?? '') !== 'upload.php') { return; }
        $which = self::chosen($_GET['wmu'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
        if ($which !== '') { $query->set('meta_query', WMU_Marks::meta_query($query->get('meta_query') ?: array(), $which)); }
    }
}
