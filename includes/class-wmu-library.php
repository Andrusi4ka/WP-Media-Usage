<?php
/**
 * The Media Library's queries: each attachment's marks in the grid's data (`wmu`), and the filters Unused / Ignored (while
 * Mark unused files is ticked) and Duplicates (while Mark duplicate files is ticked: each file next to its copies), in the
 * grid (query[wmu], from grid.js) and in the list (?wmu=, its own select beside the date filter).
 */
defined('ABSPATH') || exit;

class WMU_Library {
    const FILTERS = array('unused'=>'unused', 'ignored'=>'unused', 'duplicates'=>'dupes');

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

    // The filter chosen (its part ticked): unused, ignored, duplicates or ''.
    private static function chosen($value) {
        $value = sanitize_key(wp_unslash((string) $value));
        return isset(self::FILTERS[$value]) && WMU_Settings::on(self::FILTERS[$value]) ? $value : '';
    }
    // A query's meta query and order for the filter.
    private static function apply($which, $meta_query, $orderby) {
        if ($which === 'duplicates') { return array(WMU_Copies::meta_query($meta_query), WMU_Copies::ORDER); }
        return array(WMU_Marks::meta_query($meta_query, $which), $orderby);
    }

    public static function grid_query($args) {
        $which = self::chosen($_REQUEST['query']['wmu'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification -- WordPress checks the media query's own permission.
        if ($which !== '') { list($args['meta_query'], $args['orderby']) = self::apply($which, $args['meta_query'] ?? array(), $args['orderby'] ?? 'date'); }
        return $args;
    }

    public static function list_filter($post_type) {
        if ($post_type !== 'attachment') { return; }
        $which = self::chosen($_GET['wmu'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
        $labels = array();
        if (WMU_Settings::on('unused')) { $labels += array('unused'=>__('Unused (not found on the site)', 'wp-media-usage'), 'ignored'=>__('Ignored in usage check', 'wp-media-usage')); }
        if (WMU_Settings::on('dupes')) { $labels['duplicates'] = __('Duplicates (the same file again)', 'wp-media-usage'); }
        echo '<label class="screen-reader-text" for="wmu-filter">'.esc_html__('Filter by usage', 'wp-media-usage').'</label>';
        echo '<select name="wmu" id="wmu-filter"><option value="">'.esc_html__('All usage', 'wp-media-usage').'</option>';
        foreach ($labels as $value => $label) { echo '<option value="'.esc_attr($value).'"'.selected($which, $value, false).'>'.esc_html($label).'</option>'; }
        echo '</select>';
    }

    public static function list_query($query) {
        if (!is_admin() || !$query->is_main_query() || ($GLOBALS['pagenow'] ?? '') !== 'upload.php') { return; }
        $which = self::chosen($_GET['wmu'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
        if ($which === '') { return; }
        list($meta_query, $orderby) = self::apply($which, $query->get('meta_query') ?: array(), $query->get('orderby'));
        $query->set('meta_query', $meta_query);
        // The list's own sorting (a column clicked) wins.
        if ($which === 'duplicates' && empty($_GET['orderby'])) { $query->set('orderby', $orderby); } // phpcs:ignore WordPress.Security.NonceVerification
    }
}
