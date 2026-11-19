<?php
/**
 * The scan's reading, one phase a method (WMU_Scan::PHASES but resolve): each reads a batch from its cursor, records what it
 * finds (WMU_Scan::text() / value()) and returns whether there is more.
 *
 * posts: content, excerpt and meta of every post of any type but attachments and revisions, any status but auto-draft. covers:
 * attachments' own meta (an audio or video file's cover, a video's poster). terms, termdesc: term meta and descriptions.
 * options. users: user meta. theme: the active theme's files (paths only). tables: other plugins' tables (WMU_Phases::tables()).
 */
defined('ABSPATH') || exit;

class WMU_Phases {
    // Rows read per step.
    const BATCH = array('posts'=>100, 'terms'=>500, 'options'=>200, 'users'=>500);
    // Meta never holding media, or not the site's content (WordPress's bookkeeping and own attachment data, this plugin's).
    const SKIP_META = array('_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time',
        '_wp_desired_post_slug', '_encloseme', '_pingme', '_wp_attachment_metadata', '_wp_attached_file', '_wp_attachment_backup_sizes',
        '_wp_attachment_image_alt', '_wmu_state', '_wmu_where', '_wmu_ignored');
    const SKIP_OPTIONS = array('cron', 'rewrite_rules', 'active_plugins', 'recently_edited', 'uninstall_plugins', 'recently_activated',
        'wp_user_roles', 'auto_updater.lock', 'can_compress_scripts', 'db_version', 'initial_db_version');
    const SKIP_USERMETA = array('session_tokens', 'community-events-location', 'dismissed_wp_pointers');
    // Theme files read for paths: their kinds, and at most so large / so many.
    const THEME_EXT = array('php', 'css', 'scss', 'js', 'html', 'htm', 'json', 'twig', 'svg', 'txt');
    const THEME_MAX = array(2000000, 4000);

    public static function step($phase, &$state) {
        $method = 'read_'.$phase;
        return self::$method($state);
    }

    private static function read_posts(&$state) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ('attachment', 'revision') AND post_status <> 'auto-draft' ORDER BY ID LIMIT %d",
            $state['cursor'], self::BATCH['posts']));
        if (!$rows) { return false; }
        foreach ($rows as $row) { WMU_Scan::text($state, $row->post_content.' '.$row->post_excerpt, 'p:'.$row->ID); }
        self::posts_meta($state, array_map('intval', wp_list_pluck($rows, 'ID')));
        return self::advance($state, (int) end($rows)->ID, count($rows), 'posts');
    }
    private static function posts_meta(&$state, $ids) {
        global $wpdb;
        $in = implode(',', $ids);
        foreach ($wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in})") as $meta) {
            if (!in_array($meta->meta_key, self::SKIP_META, true)) { WMU_Scan::value($state, $meta->meta_value, $meta->meta_key, 'p:'.$meta->post_id); }
        }
    }

    // Files belonging to another file, named in its meta: _thumbnail_id of audio / video, Smooth Row Video's _srv_poster_id.
    private static function read_covers(&$state) {
        global $wpdb;
        $skip = "'".implode("','", array_map('esc_sql', self::SKIP_META))."'";
        $rows = $wpdb->get_results("SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'attachment' AND pm.meta_key NOT IN ({$skip})");
        foreach ($rows as $row) { WMU_Scan::value($state, $row->meta_value, $row->meta_key, 'p:'.$row->post_id); }
        return false;
    }

    // A category's image (WooCommerce's thumbnail_id), a brand's logo.
    private static function read_terms(&$state) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_id > %d ORDER BY meta_id LIMIT %d", $state['cursor'], self::BATCH['terms']));
        if (!$rows) { return false; }
        foreach ($rows as $row) { WMU_Scan::value($state, $row->meta_value, $row->meta_key, 't:'.$row->term_id); }
        return self::advance($state, (int) end($rows)->meta_id, count($rows), 'terms');
    }
    private static function read_termdesc(&$state) {
        global $wpdb;
        foreach ($wpdb->get_results("SELECT term_id, description FROM {$wpdb->term_taxonomy} WHERE description <> ''") as $row) {
            WMU_Scan::text($state, $row->description, 't:'.$row->term_id);
        }
        return false;
    }

    // The site icon, the Customizer (logo, header), widgets, the theme's settings (WoodMart), other plugins'.
    private static function read_options(&$state) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d ORDER BY option_id LIMIT %d", $state['cursor'], self::BATCH['options']));
        if (!$rows) { return false; }
        foreach ($rows as $row) {
            if (self::read_option($row->option_name)) { WMU_Scan::value($state, $row->option_value, $row->option_name, 'o:'.$row->option_name); }
        }
        return self::advance($state, (int) end($rows)->option_id, count($rows), 'options');
    }
    private static function read_option($name) {
        return !in_array($name, self::SKIP_OPTIONS, true) && strpos($name, '_transient') !== 0 && strpos($name, '_site_transient') !== 0 && strpos($name, 'wmu_') !== 0;
    }

    // A profile picture chosen from the library.
    private static function read_users(&$state) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE umeta_id > %d ORDER BY umeta_id LIMIT %d", $state['cursor'], self::BATCH['users']));
        if (!$rows) { return false; }
        foreach ($rows as $row) {
            if (self::read_usermeta($row->meta_key)) { WMU_Scan::value($state, $row->meta_value, $row->meta_key, 'u:'.$row->user_id); }
        }
        return self::advance($state, (int) end($rows)->umeta_id, count($rows), 'users');
    }
    private static function read_usermeta($key) {
        return !in_array($key, self::SKIP_USERMETA, true) && substr($key, -13) !== '_capabilities' && substr($key, -11) !== '_user_level';
    }

    // The active theme (and its parent): a file of the uploads folder named in its code or styles.
    private static function read_theme(&$state) {
        $base = wp_normalize_path(get_theme_root());
        $seen = 0;
        foreach (array_unique(array(get_stylesheet_directory(), get_template_directory())) as $root) {
            foreach (self::theme_files($root) as $path) {
                if (++$seen > self::THEME_MAX[1]) { return false; }
                $text = (string) file_get_contents($path);
                if (stripos($text, 'uploads') !== false) { WMU_Scan::found($state, array(), WMU_Extract::paths_from_text($text), 'f:'.ltrim(str_replace($base, '', $path), '/')); }
            }
        }
        return false;
    }
    // A theme's files worth reading: its code and styles, not too large, not its libraries.
    private static function theme_files($root) {
        if (!is_dir($root)) { return; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getSize() > self::THEME_MAX[0] || !in_array(strtolower($file->getExtension()), self::THEME_EXT, true)) { continue; }
            $path = wp_normalize_path($file->getPathname());
            if (strpos($path, '/node_modules/') === false && strpos($path, '/vendor/') === false) { yield $path; }
        }
    }

    /*
     * Plugins keeping their content in tables of their own: [label, table (without the prefix), id column, content column].
     * Signatur (its signatures' images by URL); others through the filter wmu_tables.
     */
    public static function tables() {
        return (array) apply_filters('wmu_tables', array(array('Signatur', 'signatur_signatures', 'id', 'settings')));
    }
    private static function read_tables(&$state) {
        foreach (self::tables() as $t) {
            if (is_array($t) && count($t) >= 4) { self::read_table($state, $t[0], $t[1], $t[2], $t[3]); }
        }
        return false;
    }
    private static function read_table(&$state, $label, $table, $id_col, $col) {
        global $wpdb;
        $clean = function ($name) { return preg_replace('/[^A-Za-z0-9_]/', '', (string) $name); };
        $table = $wpdb->prefix.$clean($table); $id_col = $clean($id_col); $col = $clean($col);
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) { return; }
        foreach ($wpdb->get_results("SELECT `{$id_col}` AS id, `{$col}` AS content FROM `{$table}`") as $row) {
            WMU_Scan::value($state, $row->content, '', 'x:'.$label.':'.$row->id);
        }
    }

    // A batch read: the cursor after it; more to come when it was full.
    private static function advance(&$state, $cursor, $count, $phase) {
        $state['cursor'] = $cursor;
        $state['read'] += $count;
        return $count === self::BATCH[$phase];
    }
}
