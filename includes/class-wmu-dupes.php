<?php
/**
 * The duplicates check (the checkbox Mark duplicate files): only exact copies, i.e. the same file uploaded again under any name.
 * In small steps, as WMU_Scan: sizes (each attachment's uploaded file, WMU_Same::source(), and its size), hash (an MD5 only of
 * the files whose size another one shares; kept in _wmu_hash as "size:mtime:md5", so an unchanged file is not read again),
 * write (_wmu_dup = the hash on each file that has a copy, removed from all others).
 *
 * Its state: phase, cursor, files [id => size], queue [ids to hash], hashes [id => md5], total, started.
 */
defined('ABSPATH') || exit;

class WMU_Dupes {
    const STATE = 'wmu_dupes_state';
    const LAST = 'wmu_dupes_last';
    const PHASES = array('sizes', 'hash', 'write');
    const BATCH = 200;

    public static function start() {
        return array('phase'=>'sizes', 'cursor'=>0, 'files'=>array(), 'queue'=>array(), 'hashes'=>array(), 'total'=>0, 'started'=>time());
    }

    // As WMU_Scan::run(); $write false: a dry run (tests/site-dupes-dry-run.php), nothing stored, the groups in $state['groups'].
    public static function run(&$state, $seconds = 3.0, $write = true) {
        $until = microtime(true) + $seconds;
        do { self::step($state, $write); } while ($state['phase'] !== 'done' && microtime(true) < $until);
        return $state;
    }

    public static function step(&$state, $write = true) {
        switch ($state['phase']) {
            case 'sizes': if (!self::sizes($state)) { self::queue($state); } return;
            case 'hash': if (!self::hash($state, $write)) { $state['phase'] = 'write'; } return;
            case 'write': self::write($state, $write); $state['phase'] = 'done'; return;
        }
    }

    // A batch of attachments: each one's uploaded file and its size; whether there are more.
    private static function sizes(&$state) {
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID LIMIT %d", $state['cursor'], self::BATCH)));
        if (!$ids) { return false; }
        foreach (self::paths($ids) as $id => $path) {
            $size = is_file($path) ? (int) filesize($path) : 0;
            if ($size > 0) { $state['files'][$id] = $size; $state['total']++; }
        }
        $state['cursor'] = end($ids);
        return count($ids) === self::BATCH;
    }
    // The files of the size another one has too: only they are read.
    private static function queue(&$state) {
        $queue = array();
        foreach (WMU_Same::groups($state['files']) as $ids) { $queue = array_merge($queue, $ids); }
        sort($queue);
        $state['queue'] = $queue;
        $state['files'] = array();
        $state['phase'] = 'hash';
        $state['cursor'] = 0;
    }

    // One file's hash; whether there are more.
    private static function hash(&$state, $write) {
        if ($state['cursor'] >= count($state['queue'])) { return false; }
        $id = (int) $state['queue'][$state['cursor']++];
        $paths = self::paths(array($id));
        $md5 = isset($paths[$id]) ? self::md5($id, $paths[$id], $write) : '';
        if ($md5 !== '') { $state['hashes'][$id] = $md5; }
        return true;
    }
    // From _wmu_hash while the file's size and time are the same; read again otherwise.
    private static function md5($id, $path, $write) {
        if (!is_file($path)) { return ''; }
        $stamp = filesize($path).':'.filemtime($path).':';
        $kept = (string) get_post_meta($id, '_wmu_hash', true);
        if (strpos($kept, $stamp) === 0 && strlen($kept) === strlen($stamp) + 32) { return substr($kept, -32); }
        $md5 = (string) md5_file($path);
        if ($write && $md5 !== '') { update_post_meta($id, '_wmu_hash', $stamp.$md5); }
        return $md5;
    }

    // The marks: _wmu_dup on each file that has a copy, none on the others.
    private static function write(&$state, $write) {
        $groups = WMU_Same::groups($state['hashes']);
        $state['groups'] = $groups;
        $state['copies'] = array_sum(array_map('count', $groups));
        $state['queue'] = $state['hashes'] = array();
        if (!$write) { return; }
        delete_metadata('post', 0, '_wmu_dup', '', true);
        foreach ($groups as $md5 => $ids) {
            foreach ($ids as $id) { update_post_meta($id, '_wmu_dup', $md5); }
        }
    }

    // Each attachment's uploaded file on disk.
    private static function paths($ids) {
        global $wpdb;
        $meta = array();
        $in = implode(',', array_map('intval', $ids));
        foreach ($wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes')") as $row) {
            $meta[$row->post_id][$row->meta_key] = $row->meta_value;
        }
        $base = trailingslashit(wp_get_upload_dir()['basedir']);
        $out = array();
        foreach ($meta as $id => $m) {
            $file = WMU_Same::source($m['_wp_attached_file'] ?? '', WMU_Extract::decode($m['_wp_attachment_metadata'] ?? ''), !empty($m['_wp_attachment_backup_sizes']));
            if ($file !== '') { $out[(int) $id] = $base.$file; }
        }
        return $out;
    }

    // Where it is, for the progress beside the checkbox.
    public static function progress($state) {
        return $state['phase'] === 'hash' ? $state['cursor'].' / '.count($state['queue']) : (string) $state['total'];
    }

    public static function finish($state) {
        delete_option(self::STATE);
        update_option(self::LAST, array('time'=>(int) ($state['started'] ?? time()), 'total'=>(int) $state['total'], 'groups'=>count($state['groups'] ?? array()), 'copies'=>(int) ($state['copies'] ?? 0)), false);
    }
}
