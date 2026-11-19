<?php
/**
 * The scan's result: each attachment is used if its ID or one of its files was found (WMU_Scan's ids and paths), unused
 * otherwise; written into its meta (_wmu_state, _wmu_where). And a post saved after a scan: what it names loses Unused at once.
 */
defined('ABSPATH') || exit;

class WMU_Resolve {
    const BATCH = 200;

    // A batch of attachments; whether there are more.
    public static function step(&$state, $write) {
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID LIMIT %d", $state['cursor'], self::BATCH)));
        if (!$ids) { return false; }
        $files = self::files($ids);
        foreach ($ids as $id) {
            $where = self::where($state, $id, $files[$id] ?? array());
            $state['total']++;
            if (!$where['n']) { $state['unused']++; }
            if ($write) { self::save($id, $where['n'] > 0, $where); } else { $state['result'][$id] = $where['n'] ? $where : false; }
        }
        $state['cursor'] = end($ids);
        return count($ids) === self::BATCH;
    }

    // Each attachment's files (WMU_Extract::files_of()), from its meta read at once.
    private static function files($ids) {
        global $wpdb;
        $meta = array();
        $in = implode(',', $ids);
        foreach ($wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes')") as $row) {
            $meta[$row->post_id][$row->meta_key] = $row->meta_value;
        }
        $out = array();
        foreach ($meta as $id => $m) {
            $backup = WMU_Extract::decode($m['_wp_attachment_backup_sizes'] ?? '');
            $out[$id] = WMU_Extract::files_of($m['_wp_attached_file'] ?? '', WMU_Extract::decode($m['_wp_attachment_metadata'] ?? ''), is_array($backup) ? $backup : array());
        }
        return $out;
    }

    // Where an attachment was found: by its ID and by each of its files, each place once; not in itself.
    private static function where($state, $id, $files) {
        $where = array('n'=>0, 's'=>array());
        $slots = array($state['ids'][$id] ?? null);
        foreach ($files as $file) { $slots[] = $state['paths'][$file] ?? null; }
        foreach (array_filter($slots) as $slot) {
            foreach ($slot['s'] as $s) {
                if ($s === 'p:'.$id || in_array($s, $where['s'], true)) { continue; }
                $where['n']++;
                if (count($where['s']) < WMU_Scan::KEEP) { $where['s'][] = $s; }
            }
            $where['n'] += max(0, $slot['n'] - count($slot['s']));
        }
        return $where;
    }

    public static function save($id, $used, $where) {
        update_post_meta($id, '_wmu_state', $used ? 'used' : 'unused');
        if ($used) { update_post_meta($id, '_wmu_where', $where); } else { delete_post_meta($id, '_wmu_where'); }
    }

    /*
     * A post saved after a scan: what it names now counts as used (an image just put on a page loses its Unused mark). What it
     * no longer names is found by the next scan.
     */
    public static function saved($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || !get_option(WMU_Scan::LAST)) { return; }
        if (!$post || in_array($post->post_type, array('attachment', 'revision'), true) || $post->post_status === 'auto-draft') { return; }
        foreach (self::named($post) as $id) {
            if ($id !== (int) $post_id && get_post_meta($id, '_wmu_state', true) === 'unused' && get_post_type($id) === 'attachment') {
                self::save($id, true, array('n'=>1, 's'=>array('p:'.$post_id)));
            }
        }
    }
    // The attachments a post names: by ID, and by file (matched to their attachments).
    private static function named($post) {
        $state = array('ids'=>array(), 'paths'=>array());
        WMU_Scan::text($state, $post->post_content.' '.$post->post_excerpt, 'p:'.$post->ID);
        foreach ((array) get_post_meta($post->ID) as $key => $values) {
            if (in_array($key, WMU_Phases::SKIP_META, true)) { continue; }
            foreach ($values as $v) { WMU_Scan::value($state, $v, $key, 'p:'.$post->ID); }
        }
        return array_unique(array_map('intval', array_merge(array_keys($state['ids']), self::by_files(array_keys($state['paths'])))));
    }
    // Attachments by their files: a size (photo-300x200.jpg) or a scaled one is kept under the file it was uploaded as.
    private static function by_files($paths) {
        global $wpdb;
        if (!$paths) { return array(); }
        $names = array();
        foreach ($paths as $p) {
            $base = preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/', '$1', $p);
            array_push($names, $p, $base, preg_replace('/(\.[a-z0-9]+)$/', '-scaled$1', $base));
        }
        $names = array_slice(array_values(array_unique($names)), 0, 500);
        $holders = implode(',', array_fill(0, count($names), '%s'));
        return $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND LOWER(meta_value) IN ({$holders})", $names));
    }
}
