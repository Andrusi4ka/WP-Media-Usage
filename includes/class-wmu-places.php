<?php
/**
 * Where a file is used, in words: each of WMU_Scan's tokens as a link to what uses it (a page, a category, a user, the
 * Customizer, the widgets), and an attachment's usage as the details and the list show it.
 */
defined('ABSPATH') || exit;

class WMU_Places {
    // A token as HTML: p:<post>, t:<term>, u:<user>, o:<option>, f:<theme file>, x:<label>:<row>.
    public static function html($token) {
        $parts = explode(':', (string) $token, 2);
        $rest = $parts[1] ?? '';
        switch ($parts[0]) {
            case 'p': return self::post((int) $rest);
            case 't': return self::term((int) $rest);
            case 'u': return self::user((int) $rest);
            case 'o': return self::option($rest);
            /* translators: %s: a file of the theme */
            case 'f': return esc_html(sprintf(__('Theme file %s', 'wp-media-usage'), $rest));
            case 'x': $x = explode(':', $rest, 2); return esc_html($x[0].' #'.($x[1] ?? ''));
        }
        return esc_html($token);
    }
    private static function link($label, $url) {
        return $url ? '<a href="'.esc_url($url).'">'.esc_html($label).'</a>' : esc_html($label);
    }
    private static function post($id) {
        $post = get_post($id);
        if (!$post) { return esc_html__('A deleted post', 'wp-media-usage'); }
        $type = get_post_type_object($post->post_type);
        $title = $post->post_title !== '' ? $post->post_title : __('(no title)', 'wp-media-usage');
        $kind = ($type ? $type->labels->singular_name : $post->post_type).($post->post_status === 'trash' ? ', '.__('Trash', 'wp-media-usage') : '');
        return self::link($title.' ('.$kind.')', get_edit_post_link($post->ID, 'raw'));
    }
    private static function term($id) {
        $term = get_term($id);
        if (!$term || is_wp_error($term)) { return esc_html__('A deleted category', 'wp-media-usage'); }
        $tax = get_taxonomy($term->taxonomy);
        return self::link($term->name.' ('.($tax ? $tax->labels->singular_name : $term->taxonomy).')', get_edit_term_link($term));
    }
    private static function user($id) {
        $user = get_userdata($id);
        /* translators: %s: a user's name */
        return $user ? self::link(sprintf(__('User %s', 'wp-media-usage'), $user->display_name), get_edit_user_link($user->ID)) : esc_html__('A deleted user', 'wp-media-usage');
    }
    private static function option($name) {
        $known = array('site_icon'=>__('Site icon', 'wp-media-usage'), 'xts-woodmart-options'=>__('WoodMart theme settings', 'wp-media-usage'));
        if (isset($known[$name])) { return esc_html($known[$name]); }
        if (strpos($name, 'theme_mods_') === 0) { return self::link(__('Customizer (logo, header, background)', 'wp-media-usage'), admin_url('customize.php')); }
        if (strpos($name, 'widget_') === 0 || $name === 'sidebars_widgets') { return self::link(__('Widgets', 'wp-media-usage'), admin_url('widgets.php')); }
        if (strpos($name, 'whb_') === 0) { return esc_html__('WoodMart header', 'wp-media-usage'); }
        /* translators: %s: an option's name */
        return esc_html(sprintf(__('Setting "%s"', 'wp-media-usage'), $name));
    }

    // Where it is used, as links (and how many more).
    public static function where_html($id) {
        $where = get_post_meta($id, '_wmu_where', true);
        if (!is_array($where) || empty($where['s'])) { return ''; }
        $more = (int) ($where['n'] ?? 0) - count($where['s']);
        /* translators: %d: number of other places */
        return implode(', ', array_map(array(__CLASS__, 'html'), $where['s'])).($more > 0 ? ' '.esc_html(sprintf(_n('and %d more place', 'and %d more places', $more, 'wp-media-usage'), $more)) : '');
    }

    // Its usage in words (the details): not checked / Unused / Used in …, and Ignored.
    public static function usage_html($id) {
        $mark = WMU_Marks::mark($id);
        if ($mark['state'] === '') { return '<span class="wmu-muted">'.esc_html__('Not checked yet: the Media Library checks it when it opens.', 'wp-media-usage').'</span>'; }
        $ignored = $mark['ignored'] ? ' '.self::pill(__('Ignored', 'wp-media-usage'), true) : '';
        if ($mark['state'] === 'unused') { return self::pill(__('Unused', 'wp-media-usage')).$ignored.' <span class="wmu-muted">'.esc_html__('Not found anywhere on the site.', 'wp-media-usage').'</span>'; }
        $where = self::where_html($id);
        return esc_html__('Used in:', 'wp-media-usage').' '.($where !== '' ? $where : esc_html__('the site', 'wp-media-usage')).$ignored;
    }
    public static function pill($text, $grey = false) {
        return '<span class="wmu-pill'.($grey ? ' wmu-pill--ignored' : '').'">'.esc_html($text).'</span>';
    }
}
