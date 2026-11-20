<?php
/**
 * An attachment's mark, kept in its meta: _wmu_state (used / unused; none: not checked yet), _wmu_where (where it was found,
 * WMU_Scan's slot) and _wmu_ignored (1: never shown as Unused); dup: it has an exact copy (WMU_Copies). And the queries of
 * the filters Unused and Ignored.
 */
defined('ABSPATH') || exit;

class WMU_Marks {
    public static function mark($id) {
        $state = get_post_meta($id, '_wmu_state', true);
        return array('state'=>in_array($state, array('used', 'unused'), true) ? $state : '', 'ignored'=>(bool) get_post_meta($id, '_wmu_ignored', true), 'dup'=>WMU_Copies::has($id));
    }

    public static function ignore($id, $on) {
        if ($on) { update_post_meta($id, '_wmu_ignored', 1); } else { delete_post_meta($id, '_wmu_ignored'); }
    }

    // A filter's meta query (unused: and not ignored), added to the query's own.
    public static function meta_query($existing, $which) {
        $mq = null;
        if ($which === 'unused') { $mq = array('relation'=>'AND', array('key'=>'_wmu_state', 'value'=>'unused'), array('key'=>'_wmu_ignored', 'compare'=>'NOT EXISTS')); }
        if ($which === 'ignored') { $mq = array(array('key'=>'_wmu_ignored', 'compare'=>'EXISTS')); }
        if (!$mq) { return $existing; }
        return $existing ? array('relation'=>'AND', $existing, $mq) : $mq;
    }

    // The last scan in words: when, how many files, how many unused.
    public static function last_text() {
        $last = get_option(WMU_Scan::LAST);
        if (!is_array($last) || empty($last['time'])) { return __('Not checked yet.', 'wp-media-usage'); }
        /* translators: 1: date and time, 2: number of files, 3: number of unused files */
        return sprintf(__('Last checked %1$s: %2$d files, %3$d unused.', 'wp-media-usage'),
            wp_date(get_option('date_format').' '.get_option('time_format'), (int) $last['time']), (int) $last['total'], (int) $last['unused']);
    }
}
