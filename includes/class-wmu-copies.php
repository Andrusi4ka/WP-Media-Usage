<?php
/**
 * A file's copies as the Media Library shows them (WMU_Dupes found them): the others with the same _wmu_dup, as links with
 * their usage; the Duplicates filter's query (the copies side by side); a deleted file's last copy loses its mark; the last
 * check in words.
 */
defined('ABSPATH') || exit;

class WMU_Copies {
    public static function has($id) {
        return get_post_meta($id, '_wmu_dup', true) !== '';
    }

    // The other attachments with the same file.
    public static function others($id) {
        global $wpdb;
        $md5 = (string) get_post_meta($id, '_wmu_dup', true);
        if ($md5 === '') { return array(); }
        return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wmu_dup' AND meta_value = %s AND post_id <> %d ORDER BY post_id", $md5, $id)));
    }

    // "Same file as: <link> (Unused), …".
    public static function html($id) {
        $out = array();
        foreach (self::others($id) as $other) {
            $title = get_the_title($other);
            $label = ($title !== '' ? $title : basename((string) get_post_meta($other, '_wp_attached_file', true))).' #'.$other;
            $url = get_edit_post_link($other, 'raw');
            $link = $url ? '<a href="'.esc_url($url).'">'.esc_html($label).'</a>' : esc_html($label);
            $state = WMU_Settings::on('unused') ? WMU_Marks::mark($other)['state'] : '';
            if ($state === 'unused') { $link .= ' '.WMU_Places::pill(__('Unused', 'wp-media-usage')); }
            if ($state === 'used') { $link .= ' <span class="wmu-muted">('.esc_html__('used', 'wp-media-usage').')</span>'; }
            $out[] = $link;
        }
        return $out ? esc_html__('Same file as:', 'wp-media-usage').' '.implode(', ', $out) : '';
    }

    // The filter's meta query, named so the list can be sorted by it (each file next to its copies).
    public static function meta_query($existing) {
        $mq = array('wmu_dup'=>array('key'=>'_wmu_dup', 'compare'=>'EXISTS'));
        return $existing ? array('relation'=>'AND', $existing, $mq) : $mq;
    }
    const ORDER = array('wmu_dup'=>'ASC', 'ID'=>'ASC');

    // A file deleted: a copy left alone is no longer a duplicate.
    public static function forget($id) {
        $others = self::others($id);
        if (count($others) === 1) { delete_post_meta($others[0], '_wmu_dup'); }
    }

    public static function last_text() {
        $last = get_option(WMU_Dupes::LAST);
        if (!is_array($last) || empty($last['time'])) { return __('Not checked yet.', 'wp-media-usage'); }
        /* translators: 1: date and time, 2: number of files, 3: number of duplicate files, 4: number of groups */
        return sprintf(__('Last checked %1$s: %2$d files, %3$d duplicates in %4$d groups.', 'wp-media-usage'),
            wp_date(get_option('date_format').' '.get_option('time_format'), (int) $last['time']), (int) $last['total'], (int) $last['copies'], (int) $last['groups']);
    }
}
