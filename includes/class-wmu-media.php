<?php
/**
 * The Media Library: an "Unused" mark on the grid's thumbnails, the filters Unused and Ignored (grid and list), a Usage column
 * in the list, "Used in" and Ignore in an attachment's details, Ignore / Stop ignoring for many at once, and the Check usage
 * button that runs the scan (WMU_Scanner) from the page's title.
 *
 * Kept in each attachment's meta: _wmu_state (used / unused; none: not checked yet), _wmu_where (where it was found:
 * ['n' => how many places, 's' => the first few, as WMU_Scanner's tokens]) and _wmu_ignored (1: never marked Unused).
 */
defined('ABSPATH') || exit;

class WMU_Media {
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_filter('wp_prepare_attachment_for_js', array(__CLASS__, 'for_js'), 10, 2);
        add_filter('ajax_query_attachments_args', array(__CLASS__, 'grid_query'));
        add_filter('attachment_fields_to_edit', array(__CLASS__, 'fields'), 10, 2);
        add_filter('attachment_fields_to_save', array(__CLASS__, 'save_fields'), 10, 2);
        add_filter('manage_media_columns', array(__CLASS__, 'columns'));
        add_action('manage_media_custom_column', array(__CLASS__, 'column'), 10, 2);
        add_action('restrict_manage_posts', array(__CLASS__, 'list_filter'));
        add_action('pre_get_posts', array(__CLASS__, 'list_query'));
        add_filter('bulk_actions-upload', array(__CLASS__, 'bulk_actions'));
        add_filter('handle_bulk_actions-upload', array(__CLASS__, 'bulk_handle'), 10, 3);
        add_action('admin_notices', array(__CLASS__, 'bulk_notice'));
        add_action('wp_ajax_wmu_scan', array(__CLASS__, 'ajax_scan'));
        add_action('wp_ajax_wmu_ignore', array(__CLASS__, 'ajax_ignore'));
    }

    // Who may run a scan (it reads the whole site).
    const CAP = 'manage_options';

    public static function assets($hook) {
        if ($hook !== 'upload.php') { return; }
        $deps = array('jquery');
        if (wp_script_is('media-grid', 'enqueued')) { $deps[] = 'media-grid'; }
        wp_enqueue_style('wmu-media', WMU_URL.'assets/css/media.css', array(), WMU_VERSION);
        wp_enqueue_script('wmu-media', WMU_URL.'assets/js/media.js', $deps, WMU_VERSION, true);
        wp_localize_script('wmu-media', 'wmuMedia', array(
            'ajax'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('wmu'),
            'canScan'=>current_user_can(self::CAP),
            'last'=>self::last_text(),
            'running'=>(bool) get_option(WMU_Scanner::STATE),
            'l10n'=>array(
                'unused'=>__('Unused', 'wp-media-usage'),
                'filterUnused'=>__('Unused (not found on the site)', 'wp-media-usage'),
                'filterIgnored'=>__('Ignored in usage check', 'wp-media-usage'),
                'check'=>__('Check usage', 'wp-media-usage'),
                'checking'=>__('Checking…', 'wp-media-usage'),
                'checkTip'=>__('Looks through the whole site for each file of the Media Library and marks those found nowhere as Unused. It does not delete anything.', 'wp-media-usage'),
                'ignore'=>__('Ignore selected', 'wp-media-usage'),
                'unignore'=>__('Stop ignoring selected', 'wp-media-usage'),
                'failed'=>__('The check stopped with an error. Try again.', 'wp-media-usage'),
                'phases'=>array(
                    'posts'=>__('Pages, posts and products', 'wp-media-usage'), 'covers'=>__('Audio and video covers', 'wp-media-usage'),
                    'terms'=>__('Categories', 'wp-media-usage'), 'termdesc'=>__('Categories', 'wp-media-usage'),
                    'options'=>__('Site and theme settings', 'wp-media-usage'), 'users'=>__('Users', 'wp-media-usage'),
                    'theme'=>__('Theme files', 'wp-media-usage'), 'tables'=>__('Other plugins', 'wp-media-usage'),
                    'resolve'=>__('Media Library', 'wp-media-usage'), 'done'=>__('Done', 'wp-media-usage'))),
        ));
    }

    // The last scan in words: when, how many files, how many unused.
    public static function last_text() {
        $last = get_option(WMU_Scanner::LAST);
        if (!is_array($last) || empty($last['time'])) { return __('Not checked yet.', 'wp-media-usage'); }
        /* translators: 1: date and time, 2: number of files, 3: number of unused files */
        return sprintf(__('Last checked %1$s: %2$d files, %3$d unused.', 'wp-media-usage'),
            wp_date(get_option('date_format').' '.get_option('time_format'), (int) $last['time']), (int) $last['total'], (int) $last['unused']);
    }

    // An attachment's mark: state (used, unused or '' not checked) and whether it is ignored.
    public static function mark($id) {
        $state = get_post_meta($id, '_wmu_state', true);
        return array('state'=>in_array($state, array('used', 'unused'), true) ? $state : '', 'ignored'=>(bool) get_post_meta($id, '_wmu_ignored', true));
    }
    public static function for_js($response, $attachment) {
        $response['wmu'] = self::mark($attachment->ID);
        return $response;
    }

    // The meta query of a filter: unused (and not ignored) or ignored.
    public static function meta_query($which) {
        if ($which === 'unused') {
            return array('relation'=>'AND', array('key'=>'_wmu_state', 'value'=>'unused'), array('key'=>'_wmu_ignored', 'compare'=>'NOT EXISTS'));
        }
        if ($which === 'ignored') { return array(array('key'=>'_wmu_ignored', 'compare'=>'EXISTS')); }
        return null;
    }
    private static function add_meta_query($existing, $which) {
        $mq = self::meta_query($which);
        if (!$mq) { return $existing; }
        return $existing ? array('relation'=>'AND', $existing, $mq) : $mq;
    }

    // The grid: its filter's choice comes as query[wmu].
    public static function grid_query($args) {
        $which = isset($_REQUEST['query']['wmu']) ? sanitize_key(wp_unslash($_REQUEST['query']['wmu'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- WordPress checks the media query's own permission.
        if ($which !== '') { $args['meta_query'] = self::add_meta_query($args['meta_query'] ?? array(), $which); }
        return $args;
    }

    // The list: its own select beside the date filter, read on the Media Library's main query.
    public static function list_filter($post_type) {
        if ($post_type !== 'attachment' && (get_current_screen()->id ?? '') !== 'upload') { return; }
        $which = isset($_GET['wmu']) ? sanitize_key(wp_unslash($_GET['wmu'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        echo '<label class="screen-reader-text" for="wmu-filter">'.esc_html__('Filter by usage', 'wp-media-usage').'</label>';
        echo '<select name="wmu" id="wmu-filter"><option value="">'.esc_html__('All usage', 'wp-media-usage').'</option>';
        foreach (array('unused'=>__('Unused (not found on the site)', 'wp-media-usage'), 'ignored'=>__('Ignored in usage check', 'wp-media-usage')) as $value => $label) {
            echo '<option value="'.esc_attr($value).'"'.selected($which, $value, false).'>'.esc_html($label).'</option>';
        }
        echo '</select>';
    }
    public static function list_query($query) {
        if (!is_admin() || !$query->is_main_query() || $GLOBALS['pagenow'] !== 'upload.php' || empty($_GET['wmu'])) { return; } // phpcs:ignore WordPress.Security.NonceVerification
        $query->set('meta_query', self::add_meta_query($query->get('meta_query') ?: array(), sanitize_key(wp_unslash($_GET['wmu'])))); // phpcs:ignore WordPress.Security.NonceVerification
    }

    // A place it was found, as a link where there is one.
    public static function source_html($token) {
        $parts = explode(':', (string) $token, 2);
        $kind = $parts[0]; $rest = $parts[1] ?? '';
        $link = function ($label, $url) { return $url ? '<a href="'.esc_url($url).'">'.esc_html($label).'</a>' : esc_html($label); };
        if ($kind === 'p') {
            $post = get_post((int) $rest);
            if (!$post) { return esc_html__('A deleted post', 'wp-media-usage'); }
            $type = get_post_type_object($post->post_type);
            $label = ($post->post_title !== '' ? $post->post_title : __('(no title)', 'wp-media-usage')).' ('.($type ? $type->labels->singular_name : $post->post_type).($post->post_status === 'trash' ? ', '.__('Trash', 'wp-media-usage') : '').')';
            return $link($label, get_edit_post_link($post->ID, 'raw'));
        }
        if ($kind === 't') {
            $term = get_term((int) $rest);
            if (!$term || is_wp_error($term)) { return esc_html__('A deleted category', 'wp-media-usage'); }
            $tax = get_taxonomy($term->taxonomy);
            return $link($term->name.' ('.($tax ? $tax->labels->singular_name : $term->taxonomy).')', get_edit_term_link($term));
        }
        if ($kind === 'u') {
            $user = get_userdata((int) $rest);
            return $user ? $link(sprintf(__('User %s', 'wp-media-usage'), $user->display_name), get_edit_user_link($user->ID)) : esc_html__('A deleted user', 'wp-media-usage');
        }
        if ($kind === 'o') {
            $known = array('site_icon'=>__('Site icon', 'wp-media-usage'), 'xts-woodmart-options'=>__('WoodMart theme settings', 'wp-media-usage'));
            if (isset($known[$rest])) { return esc_html($known[$rest]); }
            if (strpos($rest, 'theme_mods_') === 0) { return $link(__('Customizer (logo, header, background)', 'wp-media-usage'), admin_url('customize.php')); }
            if (strpos($rest, 'widget_') === 0 || $rest === 'sidebars_widgets') { return $link(__('Widgets', 'wp-media-usage'), admin_url('widgets.php')); }
            if (strpos($rest, 'whb_') === 0) { return esc_html__('WoodMart header', 'wp-media-usage'); }
            return esc_html(sprintf(__('Setting "%s"', 'wp-media-usage'), $rest));
        }
        if ($kind === 'f') { return esc_html(sprintf(__('Theme file %s', 'wp-media-usage'), $rest)); }
        if ($kind === 'x') {
            $x = explode(':', $rest, 2);
            return esc_html(sprintf(__('%1$s #%2$s', 'wp-media-usage'), $x[0], $x[1] ?? ''));
        }
        return esc_html($token);
    }
    // Where it is used, as a list of links (and how many more).
    public static function where_html($id) {
        $where = get_post_meta($id, '_wmu_where', true);
        if (!is_array($where) || empty($where['s'])) { return ''; }
        $items = array_map(array(__CLASS__, 'source_html'), $where['s']);
        $more = (int) ($where['n'] ?? 0) - count($where['s']);
        /* translators: %d: number of other places */
        return implode(', ', $items).($more > 0 ? ' '.sprintf(_n('and %d more place', 'and %d more places', $more, 'wp-media-usage'), $more) : '');
    }
    // Its usage in words: Unused / Ignored / Used in … / not checked.
    public static function usage_html($id) {
        $mark = self::mark($id);
        if ($mark['state'] === '') { return '<span class="wmu-muted">'.esc_html__('Not checked yet: use Check usage at the top of the Media Library.', 'wp-media-usage').'</span>'; }
        $ignored = $mark['ignored'] ? ' <span class="wmu-pill wmu-pill--ignored">'.esc_html__('Ignored', 'wp-media-usage').'</span>' : '';
        if ($mark['state'] === 'unused') { return '<span class="wmu-pill">'.esc_html__('Unused', 'wp-media-usage').'</span>'.$ignored.' <span class="wmu-muted">'.esc_html__('Not found anywhere on the site.', 'wp-media-usage').'</span>'; }
        $where = self::where_html($id);
        return esc_html__('Used in:', 'wp-media-usage').' '.($where !== '' ? $where : esc_html__('the site', 'wp-media-usage')).$ignored;
    }

    // An attachment's details (the grid's window and its edit screen): Usage, and Ignore.
    public static function fields($fields, $post) {
        $fields['wmu_usage'] = array('label'=>__('Usage', 'wp-media-usage'), 'input'=>'html', 'html'=>'<div class="wmu-usage">'.self::usage_html($post->ID).'</div>');
        $name = 'attachments['.$post->ID.']';
        $fields['wmu_ignore'] = array('label'=>__('Usage check', 'wp-media-usage'), 'input'=>'html',
            'html'=>'<input type="hidden" name="'.esc_attr($name.'[wmu_form]').'" value="1"><label><input type="checkbox" name="'.esc_attr($name.'[wmu_ignore]').'" value="1"'.checked(self::mark($post->ID)['ignored'], true, false).'> '
                .esc_html__('Ignore (never mark as Unused)', 'wp-media-usage').'</label>',
            'helps'=>__('For a file used outside the site, e.g. in newsletters or on social media.', 'wp-media-usage'));
        return $fields;
    }
    public static function save_fields($post, $data) {
        if (isset($data['wmu_form']) && current_user_can('edit_post', $post['ID'])) { self::ignore((int) $post['ID'], !empty($data['wmu_ignore'])); }
        return $post;
    }
    public static function ignore($id, $on) {
        if ($on) { update_post_meta($id, '_wmu_ignored', 1); } else { delete_post_meta($id, '_wmu_ignored'); }
    }

    // The list's Usage column.
    public static function columns($columns) {
        $columns['wmu_usage'] = __('Usage', 'wp-media-usage');
        return $columns;
    }
    public static function column($name, $id) {
        if ($name !== 'wmu_usage') { return; }
        $mark = self::mark($id);
        if ($mark['state'] === '') { echo '<span class="wmu-muted">—</span>'; return; }
        if ($mark['ignored']) { echo '<span class="wmu-pill wmu-pill--ignored">'.esc_html__('Ignored', 'wp-media-usage').'</span> '; }
        if ($mark['state'] === 'unused') { echo '<span class="wmu-pill">'.esc_html__('Unused', 'wp-media-usage').'</span>'; return; }
        echo self::where_html($id); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    // The list's bulk actions: Ignore / Stop ignoring.
    public static function bulk_actions($actions) {
        $actions['wmu_ignore'] = __('Ignore in usage check', 'wp-media-usage');
        $actions['wmu_unignore'] = __('Stop ignoring in usage check', 'wp-media-usage');
        return $actions;
    }
    public static function bulk_handle($redirect, $action, $ids) {
        if (!in_array($action, array('wmu_ignore', 'wmu_unignore'), true)) { return $redirect; }
        $done = 0;
        foreach ((array) $ids as $id) {
            if (get_post_type($id) === 'attachment' && current_user_can('edit_post', $id)) { self::ignore((int) $id, $action === 'wmu_ignore'); $done++; }
        }
        return add_query_arg(array('wmu_done'=>$done, 'wmu_action'=>$action === 'wmu_ignore' ? 'ignore' : 'unignore'), $redirect);
    }
    public static function bulk_notice() {
        if (!isset($_GET['wmu_done']) || (get_current_screen()->id ?? '') !== 'upload') { return; } // phpcs:ignore WordPress.Security.NonceVerification
        $n = (int) $_GET['wmu_done']; // phpcs:ignore WordPress.Security.NonceVerification
        $text = ($_GET['wmu_action'] ?? '') === 'unignore' // phpcs:ignore WordPress.Security.NonceVerification
            ? sprintf(_n('%d file is checked for usage again.', '%d files are checked for usage again.', $n, 'wp-media-usage'), $n)
            : sprintf(_n('%d file is ignored in usage check.', '%d files are ignored in usage check.', $n, 'wp-media-usage'), $n);
        echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($text).'</p></div>';
    }

    // The scan, a few seconds a request; start: a new one. Its state between requests in an option (not autoloaded).
    public static function ajax_scan() {
        check_ajax_referer('wmu', 'nonce');
        if (!current_user_can(self::CAP)) { wp_send_json_error(null, 403); }
        $state = !empty($_POST['start']) ? null : get_option(WMU_Scanner::STATE);
        if (!is_array($state) || empty($state['phase'])) { $state = WMU_Scanner::start(); }
        if (function_exists('set_time_limit')) { @set_time_limit(60); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
        WMU_Scanner::run($state, 3.0, true);
        if ($state['phase'] === 'done') {
            delete_option(WMU_Scanner::STATE);
            update_option(WMU_Scanner::LAST, array('time'=>time(), 'total'=>$state['total'], 'unused'=>$state['unused']), false);
            wp_send_json_success(array('done'=>true, 'last'=>self::last_text()));
        }
        update_option(WMU_Scanner::STATE, $state, false);
        wp_send_json_success(array('done'=>false, 'phase'=>$state['phase'], 'read'=>$state['read'], 'checked'=>$state['total']));
    }

    // Ignore / stop ignoring (the grid's button for the files selected): their marks as the grid shows them.
    public static function ajax_ignore() {
        check_ajax_referer('wmu', 'nonce');
        $on = !empty($_POST['ignore']);
        $out = array();
        foreach (array_slice(array_map('intval', (array) ($_POST['ids'] ?? array())), 0, 1000) as $id) {
            if ($id <= 0 || get_post_type($id) !== 'attachment' || !current_user_can('edit_post', $id)) { continue; }
            self::ignore($id, $on);
            $out[$id] = self::mark($id);
        }
        wp_send_json_success($out);
    }

    // A post saved after a scan: the files it names lose their Unused mark at once.
    public static function saved($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || !get_option(WMU_Scanner::LAST)) { return; }
        WMU_Scanner::mark_post($post_id);
    }
}
