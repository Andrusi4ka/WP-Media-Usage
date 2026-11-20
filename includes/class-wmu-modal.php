<?php
/**
 * The Duplicates window's content (user's request, 2026-11-20: a modal, each copy's thumbnail beside where it is used, to edit
 * those places by hand): the groups of exact copies (_wmu_dup), those with more than one copy in use first (they need editing),
 * then the biggest; each copy with its thumbnail, file, ID, upload date, size and its usage (WMU_Scan's _wmu_where, found by
 * the usage check: in the background while only Mark duplicate files is ticked).
 */
defined('ABSPATH') || exit;

class WMU_Modal {
    // Groups shown at most.
    const MAX = 300;

    // How many groups there are now (the button's number).
    public static function count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(DISTINCT meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_wmu_dup'");
    }

    // [md5 => ids], in order: more copies in use first, then bigger files.
    private static function groups() {
        global $wpdb;
        $groups = array();
        foreach ($wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wmu_dup' ORDER BY post_id") as $row) {
            $groups[$row->meta_value][] = (int) $row->post_id;
        }
        $groups = array_filter($groups, function ($ids) { return count($ids) > 1; });
        $rank = array();
        foreach ($groups as $md5 => $ids) {
            $used = count(array_filter($ids, function ($id) { return get_post_meta($id, '_wmu_state', true) === 'used'; }));
            $rank[$md5] = array($used, self::size($ids[0]));
        }
        uksort($groups, function ($a, $b) use ($rank) { return $rank[$b] <=> $rank[$a]; });
        return array_slice($groups, 0, self::MAX, true);
    }

    private static function size($id) {
        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta) && !empty($meta['filesize'])) { return (int) $meta['filesize']; }
        $file = get_attached_file($id);
        return $file && is_file($file) ? (int) filesize($file) : 0;
    }

    // The window's body.
    public static function html() {
        $groups = self::groups();
        if (!$groups) { return '<p class="wmu-muted">'.esc_html__('No duplicates found.', 'wp-media-usage').'</p>'; }
        $out = '';
        $n = 0;
        foreach ($groups as $ids) {
            $n++;
            /* translators: 1: group number, 2: number of copies, 3: file size */
            $title = sprintf(__('Group %1$d · %2$d copies · %3$s', 'wp-media-usage'), $n, count($ids), size_format(self::size($ids[0])));
            $out .= '<section class="wmu-group"><h3>'.esc_html($title).'</h3>'.implode('', array_map(array(__CLASS__, 'copy'), $ids)).'</section>';
        }
        return $out;
    }

    // One copy: thumbnail, file, ID, date, its edit screen; where it is used.
    private static function copy($id) {
        $file = basename((string) get_post_meta($id, '_wp_attached_file', true));
        $edit = get_edit_post_link($id, 'raw');
        $thumb = wp_get_attachment_image($id, array(80, 80), true, array('loading'=>'lazy', 'alt'=>''));
        $name = esc_html($file !== '' ? $file : get_the_title($id)).' <span class="wmu-muted">#'.(int) $id.' · '.esc_html(get_the_date('', $id)).'</span>';
        return '<div class="wmu-copy"><div class="wmu-copy__thumb">'.$thumb.'</div><div class="wmu-copy__info">'
            .'<div class="wmu-copy__name">'.($edit ? '<a href="'.esc_url($edit).'">'.$name.'</a>' : $name).'</div>'
            .'<div class="wmu-copy__usage">'.self::usage($id).'</div>'.self::delete_button($id).'</div></div>';
    }
    // Under its usage (user's request, 2026-11-20), for those who may delete it; data-used: the confirmation warns.
    private static function delete_button($id) {
        if (!current_user_can('delete_post', $id)) { return ''; }
        $used = get_post_meta($id, '_wmu_state', true) === 'used' ? '1' : '0';
        return '<button type="button" class="button-link button-link-delete wmu-delete" data-id="'.(int) $id.'" data-used="'.$used.'">'.esc_html__('Delete permanently', 'wp-media-usage').'</button>';
    }
    private static function usage($id) {
        $state = get_post_meta($id, '_wmu_state', true);
        if ($state === 'unused') { return WMU_Places::pill(__('Unused', 'wp-media-usage')).' <span class="wmu-muted">'.esc_html__('Not found anywhere on the site.', 'wp-media-usage').'</span>'; }
        if ($state !== 'used') { return '<span class="wmu-muted">'.esc_html__('Where it is used: not checked yet.', 'wp-media-usage').'</span>'; }
        $where = WMU_Places::where_html($id);
        return esc_html__('Used in:', 'wp-media-usage').' '.($where !== '' ? $where : esc_html__('the site', 'wp-media-usage'));
    }
}
