<?php
/**
 * The scan: reads the whole site in small steps (each admin-ajax request runs a few seconds of them), gathering every
 * attachment ID and uploads path found with where it was found, then goes through the attachments and marks each one
 * used or unused (post meta _wmu_state, _wmu_where).
 *
 * Phases, in order: posts (content, excerpt and meta of every post of any type but attachments and revisions, any status but
 * auto-draft), attachments' own covers (_thumbnail_id of audio and video), term meta and descriptions, options, user meta,
 * the active theme's files, other plugins' tables (Signatur's signatures; filter wmu_tables), then resolve.
 *
 * Where a thing was found is kept as a short token: p:<post id>, t:<term id>, o:<option name>, u:<user id>, f:<theme file>,
 * x:<table label>:<row id> (WMU_Media::source_html() shows them).
 */
defined('ABSPATH') || exit;

class WMU_Scanner {
    const STATE = 'wmu_scan_state';
    const LAST = 'wmu_last_scan';
    const PHASES = array('posts', 'covers', 'terms', 'termdesc', 'options', 'users', 'theme', 'tables', 'resolve');
    // How many sources are kept for each attachment (the rest only counted).
    const KEEP = 5;
    // Rows read per step.
    const BATCH = array('posts'=>100, 'terms'=>500, 'options'=>200, 'users'=>500, 'resolve'=>200);
    // Meta and options never holding media, or not the site's content (WordPress's bookkeeping, caches, this plugin's own).
    const SKIP_META = array('_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time',
        '_wp_desired_post_slug', '_encloseme', '_pingme', '_wp_attachment_metadata', '_wp_attached_file', '_wp_attachment_backup_sizes', '_wmu_state', '_wmu_where', '_wmu_ignored');
    const SKIP_OPTIONS = array('cron', 'rewrite_rules', 'active_plugins', 'recently_edited', 'uninstall_plugins', 'recently_activated',
        'wp_user_roles', 'auto_updater.lock', 'can_compress_scripts', 'db_version', 'initial_db_version');
    const SKIP_USERMETA = array('session_tokens', 'wp_capabilities', 'wp_user_level', 'community-events-location', 'dismissed_wp_pointers');
    // Theme files read for paths: their kinds, and at most so large / so many.
    const THEME_EXT = array('php', 'css', 'scss', 'js', 'html', 'htm', 'json', 'twig', 'svg', 'txt');
    const THEME_MAX = array(2000000, 4000);

    // A new scan.
    public static function start() {
        return array('phase'=>'posts', 'cursor'=>0, 'ids'=>array(), 'paths'=>array(), 'started'=>time(), 'read'=>0, 'total'=>0, 'unused'=>0);
    }

    /*
     * Runs steps until $seconds have passed or the scan is done. $write: whether resolve writes the result into the attachments'
     * meta (false: a dry run, tests/site-dry-run.php; the result is then in $state['result']).
     */
    public static function run(&$state, $seconds = 3.0, $write = true) {
        $until = microtime(true) + $seconds;
        do { self::step($state, $write); } while ($state['phase'] !== 'done' && microtime(true) < $until);
        return $state;
    }

    // One step of the current phase; moves on to the next phase when it has read all.
    public static function step(&$state, $write = true) {
        $phase = $state['phase'];
        if ($phase === 'done') { return; }
        // Called by name so $state stays a reference (call_user_func would pass a copy).
        $method = 'phase_'.$phase;
        $more = self::$method($state, $write);
        if (!$more) {
            $next = array_search($phase, self::PHASES, true) + 1;
            $state['phase'] = self::PHASES[$next] ?? 'done';
            $state['cursor'] = 0;
        }
    }

    // A thing found, with where (each kept once, the first KEEP; then only counted).
    private static function found(&$state, $ids, $paths, $source) {
        foreach ($ids as $id) { self::note($state['ids'][(int) $id], $source); }
        foreach ($paths as $path) { self::note($state['paths'][$path], $source); }
    }
    private static function note(&$slot, $source) {
        if (!is_array($slot)) { $slot = array('n'=>0, 's'=>array()); }
        if (in_array($source, $slot['s'], true)) { return; }
        $slot['n']++;
        if (count($slot['s']) < self::KEEP) { $slot['s'][] = $source; }
    }
    private static function text(&$state, $text, $source) {
        self::found($state, WMU_Extract::ids_from_text($text), WMU_Extract::paths_from_text($text), $source);
    }
    private static function value(&$state, $value, $key, $source) {
        list($ids, $paths) = WMU_Extract::from_value($value, $key);
        self::found($state, $ids, $paths, $source);
    }

    // Posts of every type but attachments and revisions: their text and their meta.
    private static function phase_posts(&$state) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ('attachment', 'revision') AND post_status <> 'auto-draft' ORDER BY ID LIMIT %d",
            $state['cursor'], self::BATCH['posts']));
        if (!$rows) { return false; }
        $ids = array();
        foreach ($rows as $row) {
            $ids[] = (int) $row->ID;
            self::text($state, $row->post_content.' '.$row->post_excerpt, 'p:'.$row->ID);
        }
        $in = implode(',', $ids);
        foreach ($wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in})") as $meta) {
            if (in_array($meta->meta_key, self::SKIP_META, true)) { continue; }
            self::value($state, $meta->meta_value, $meta->meta_key, 'p:'.$meta->post_id);
        }
        $state['cursor'] = end($ids);
        $state['read'] += count($rows);
        return count($rows) === self::BATCH['posts'];
    }

    /*
     * Files that belong to another file, named in that one's meta: an audio or video file's cover (_thumbnail_id), a video's
     * poster (Smooth Row Video: _srv_poster_id, _srv_video's poster_id). Every attachment meta but WordPress's own and ours.
     */
    private static function phase_covers(&$state) {
        global $wpdb;
        $skip = "'".implode("','", array_map('esc_sql', self::SKIP_META))."'";
        $rows = $wpdb->get_results("SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'attachment' AND pm.meta_key NOT IN ({$skip}) AND pm.meta_key <> '_wp_attachment_image_alt'");
        foreach ($rows as $row) { self::value($state, $row->meta_value, $row->meta_key, 'p:'.$row->post_id); }
        return false;
    }

    // Term meta: a category's image (WooCommerce's thumbnail_id), a brand's logo.
    private static function phase_terms(&$state) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_id > %d ORDER BY meta_id LIMIT %d", $state['cursor'], self::BATCH['terms']));
        if (!$rows) { return false; }
        foreach ($rows as $row) { self::value($state, $row->meta_value, $row->meta_key, 't:'.$row->term_id); }
        $state['cursor'] = (int) end($rows)->meta_id;
        $state['read'] += count($rows);
        return count($rows) === self::BATCH['terms'];
    }
    private static function phase_termdesc(&$state) {
        global $wpdb;
        foreach ($wpdb->get_results("SELECT term_id, description FROM {$wpdb->term_taxonomy} WHERE description <> ''") as $row) {
            self::text($state, $row->description, 't:'.$row->term_id);
        }
        return false;
    }

    // Options: the site icon, the Customizer (logo, header), widgets, the theme's settings (WoodMart), other plugins'.
    private static function phase_options(&$state) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d ORDER BY option_id LIMIT %d", $state['cursor'], self::BATCH['options']));
        if (!$rows) { return false; }
        foreach ($rows as $row) {
            $name = $row->option_name;
            if (in_array($name, self::SKIP_OPTIONS, true) || strpos($name, '_transient') === 0 || strpos($name, '_site_transient') === 0 || strpos($name, 'wmu_') === 0) { continue; }
            self::value($state, $row->option_value, $name, 'o:'.$name);
        }
        $state['cursor'] = (int) end($rows)->option_id;
        $state['read'] += count($rows);
        return count($rows) === self::BATCH['options'];
    }

    // User meta: a profile picture chosen from the library.
    private static function phase_users(&$state) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE umeta_id > %d ORDER BY umeta_id LIMIT %d", $state['cursor'], self::BATCH['users']));
        if (!$rows) { return false; }
        foreach ($rows as $row) {
            if (in_array($row->meta_key, self::SKIP_USERMETA, true) || substr($row->meta_key, -13) === '_capabilities' || substr($row->meta_key, -11) === '_user_level') { continue; }
            self::value($state, $row->meta_value, $row->meta_key, 'u:'.$row->user_id);
        }
        $state['cursor'] = (int) end($rows)->umeta_id;
        $state['read'] += count($rows);
        return count($rows) === self::BATCH['users'];
    }

    // The active theme (and its parent): a file of the uploads folder named in its code or styles.
    private static function phase_theme(&$state) {
        $roots = array_unique(array(get_stylesheet_directory(), get_template_directory()));
        $base = wp_normalize_path(get_theme_root());
        $seen = 0;
        foreach ($roots as $root) {
            if (!is_dir($root)) { continue; }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (++$seen > self::THEME_MAX[1]) { break 2; }
                if (!$file->isFile() || $file->getSize() > self::THEME_MAX[0] || !in_array(strtolower($file->getExtension()), self::THEME_EXT, true)) { continue; }
                $path = wp_normalize_path($file->getPathname());
                if (strpos($path, '/node_modules/') !== false || strpos($path, '/vendor/') !== false) { continue; }
                $text = (string) file_get_contents($path);
                if (stripos($text, 'uploads') === false) { continue; }
                self::found($state, array(), WMU_Extract::paths_from_text($text), 'f:'.ltrim(str_replace($base, '', $path), '/'));
            }
        }
        return false;
    }

    /*
     * Plugins keeping their content in tables of their own: [label, table (without the prefix), id column, content column].
     * Signatur (its signatures' images by URL). Others through the filter wmu_tables.
     */
    public static function tables() {
        return (array) apply_filters('wmu_tables', array(array('Signatur', 'signatur_signatures', 'id', 'settings')));
    }
    private static function phase_tables(&$state) {
        global $wpdb;
        foreach (self::tables() as $t) {
            if (!is_array($t) || count($t) < 4) { continue; }
            list($label, $table, $id_col, $col) = $t;
            $table = $wpdb->prefix.preg_replace('/[^A-Za-z0-9_]/', '', $table);
            $id_col = preg_replace('/[^A-Za-z0-9_]/', '', $id_col); $col = preg_replace('/[^A-Za-z0-9_]/', '', $col);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) { continue; }
            foreach ($wpdb->get_results("SELECT `{$id_col}` AS id, `{$col}` AS content FROM `{$table}`") as $row) {
                self::value($state, $row->content, '', 'x:'.$label.':'.$row->id);
            }
        }
        return false;
    }

    // Each attachment: used if its ID or one of its files was found. Written into its meta (or kept in $state['result']).
    private static function phase_resolve(&$state, $write) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID LIMIT %d", $state['cursor'], self::BATCH['resolve']));
        if (!$ids) { return false; }
        $ids = array_map('intval', $ids);
        $in = implode(',', $ids);
        $meta = array();
        foreach ($wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes')") as $row) {
            $meta[$row->post_id][$row->meta_key] = $row->meta_value;
        }
        foreach ($ids as $id) {
            $m = $meta[$id] ?? array();
            $files = WMU_Extract::files_of($m['_wp_attached_file'] ?? '', WMU_Extract::decode($m['_wp_attachment_metadata'] ?? ''), WMU_Extract::decode($m['_wp_attachment_backup_sizes'] ?? '') ?: array());
            $where = array('n'=>0, 's'=>array());
            foreach (array_merge(array($state['ids'][$id] ?? null), array_map(function ($f) use ($state) { return $state['paths'][$f] ?? null; }, $files)) as $slot) {
                if (!$slot) { continue; }
                foreach ($slot['s'] as $s) {
                    // An attachment found only in itself does not count (e.g. a path in its own caption).
                    if ($s === 'p:'.$id || in_array($s, $where['s'], true)) { continue; }
                    $where['n']++;
                    if (count($where['s']) < self::KEEP) { $where['s'][] = $s; }
                }
                $where['n'] += max(0, $slot['n'] - count($slot['s']));
            }
            $used = $where['n'] > 0;
            $state['total']++;
            if (!$used) { $state['unused']++; }
            if ($write) { self::save($id, $used, $where); } else { $state['result'][$id] = $used ? $where : false; }
        }
        $state['cursor'] = end($ids);
        return count($ids) === self::BATCH['resolve'];
    }

    // An attachment's result.
    public static function save($id, $used, $where) {
        update_post_meta($id, '_wmu_state', $used ? 'used' : 'unused');
        if ($used) { update_post_meta($id, '_wmu_where', $where); } else { delete_post_meta($id, '_wmu_where'); }
    }

    /*
     * A post saved after a scan: what it names now counts as used at once (an image just put on a page loses its Unused mark).
     * What it no longer names is found by the next scan. Its text and meta; paths matched to their attachments.
     */
    public static function mark_post($post_id) {
        global $wpdb;
        $post = get_post($post_id);
        if (!$post || in_array($post->post_type, array('attachment', 'revision'), true) || $post->post_status === 'auto-draft') { return; }
        $state = array('ids'=>array(), 'paths'=>array());
        self::text($state, $post->post_content.' '.$post->post_excerpt, 'p:'.$post_id);
        foreach ((array) get_post_meta($post_id) as $key => $values) {
            if (in_array($key, self::SKIP_META, true)) { continue; }
            foreach ($values as $v) { self::value($state, $v, $key, 'p:'.$post_id); }
        }
        $ids = array_keys($state['ids']);
        if ($state['paths']) {
            // A size's file (photo-300x200.jpg) or a scaled one: its attachment is kept under the file it was uploaded as.
            $names = array();
            foreach (array_keys($state['paths']) as $p) {
                $names[] = $p;
                $names[] = preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/', '$1', $p);
                $names[] = preg_replace('/(\.[a-z0-9]+)$/', '-scaled$1', preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/', '$1', $p));
            }
            $names = array_slice(array_unique($names), 0, 500);
            $holders = implode(',', array_fill(0, count($names), '%s'));
            $ids = array_merge($ids, $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND LOWER(meta_value) IN ({$holders})", $names)));
        }
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id === (int) $post_id || get_post_meta($id, '_wmu_state', true) !== 'unused' || get_post_type($id) !== 'attachment') { continue; }
            self::save($id, true, array('n'=>1, 's'=>array('p:'.$post_id)));
        }
    }
}
