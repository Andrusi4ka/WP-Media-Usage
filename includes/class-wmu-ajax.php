<?php
/**
 * admin-ajax: wmu_switch (a checkbox: `part` unused / dupes), wmu_scan (a few seconds of that part's check a request; its state
 * between requests in an option, not autoloaded; the usage check also while only Mark duplicate files is ticked: in the
 * background, for the Duplicates window), wmu_ignore (the grid's Ignore selected / Stop ignoring selected) and wmu_dupes_list
 * (the Duplicates window's content).
 */
defined('ABSPATH') || exit;

class WMU_Ajax {
    // Seconds of scanning a request.
    const SECONDS = 3.0;
    // Who may tick a checkbox; who may run a check (a ticked box finishes an unfinished one for anyone using the library).
    const SWITCH_CAP = 'manage_options';
    const SCAN_CAP = 'upload_files';
    // A check whose state was saved this recently is still running (another tab, another user): joined, not started again.
    const RUNNING = 30;
    // Each part's check: its course (start, run, finish, STATE) and its last check in words.
    const CHECKS = array('unused'=>array('WMU_Scan', array('WMU_Marks', 'last_text')), 'dupes'=>array('WMU_Dupes', array('WMU_Copies', 'last_text')));

    public static function init() {
        add_action('wp_ajax_wmu_switch', array(__CLASS__, 'switch_on'));
        add_action('wp_ajax_wmu_scan', array(__CLASS__, 'scan'));
        add_action('wp_ajax_wmu_ignore', array(__CLASS__, 'ignore'));
        add_action('wp_ajax_wmu_dupes_list', array(__CLASS__, 'dupes_list'));
        add_action('wp_ajax_wmu_delete', array(__CLASS__, 'delete'));
    }

    // A copy deleted from the Duplicates window, permanently, as the Media Library's Delete permanently (its last copy then
    // loses its mark: WMU_Copies::forget()).
    public static function delete() {
        check_ajax_referer('wmu', 'nonce');
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0 || get_post_type($id) !== 'attachment' || !current_user_can('delete_post', $id) || !WMU_Settings::on('dupes')) { wp_send_json_error(null, 403); }
        if (!wp_delete_attachment($id, true)) { wp_send_json_error(); }
        wp_send_json_success(array('html'=>WMU_Modal::html(), 'groups'=>WMU_Modal::count()));
    }

    // The Duplicates window's content.
    public static function dupes_list() {
        check_ajax_referer('wmu', 'nonce');
        if (!current_user_can(self::SCAN_CAP) || !WMU_Settings::on('dupes')) { wp_send_json_error(null, 403); }
        wp_send_json_success(array('html'=>WMU_Modal::html(), 'groups'=>WMU_Modal::count()));
    }

    private static function part() {
        $part = sanitize_key(wp_unslash((string) ($_POST['part'] ?? 'unused'))); // phpcs:ignore WordPress.Security.NonceVerification -- checked by the caller.
        return isset(self::CHECKS[$part]) ? $part : 'unused';
    }

    public static function switch_on() {
        check_ajax_referer('wmu', 'nonce');
        if (!current_user_can(self::SWITCH_CAP)) { wp_send_json_error(null, 403); }
        WMU_Settings::set(self::part(), !empty($_POST['on']));
        wp_send_json_success();
    }

    public static function scan() {
        check_ajax_referer('wmu', 'nonce');
        $part = self::part();
        // The usage check also runs in the background for the duplicates (where each copy is used), its own checkbox unticked.
        $allowed = WMU_Settings::on($part) || ($part === 'unused' && WMU_Settings::on('dupes'));
        if (!current_user_can(self::SCAN_CAP) || !$allowed) { wp_send_json_error(null, 403); }
        list($class, $last) = self::CHECKS[$part];
        $state = self::state($class, !empty($_POST['start']));
        if (function_exists('set_time_limit')) { @set_time_limit(60); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $class::run($state, self::SECONDS, true);
        if ($state['phase'] === 'done') {
            $class::finish($state);
            wp_send_json_success(array('done'=>true, 'last'=>call_user_func($last)));
        }
        $state['touched'] = time();
        update_option($class::STATE, $state, false);
        wp_send_json_success(array('done'=>false, 'phase'=>$state['phase'], 'checked'=>$class::progress($state)));
    }
    // The check to go on with: the one saved, unless a new one is asked for and none is running now.
    private static function state($class, $start) {
        $state = get_option($class::STATE);
        $running = is_array($state) && !empty($state['phase']) && time() - (int) ($state['touched'] ?? 0) < self::RUNNING;
        return ($start && !$running) || !is_array($state) || empty($state['phase']) ? $class::start() : $state;
    }

    // Each file's new mark, as the grid shows it.
    public static function ignore() {
        check_ajax_referer('wmu', 'nonce');
        $on = !empty($_POST['ignore']);
        $out = array();
        foreach (array_slice(array_map('intval', (array) ($_POST['ids'] ?? array())), 0, 1000) as $id) {
            if ($id <= 0 || get_post_type($id) !== 'attachment' || !current_user_can('edit_post', $id)) { continue; }
            WMU_Marks::ignore($id, $on);
            $out[$id] = WMU_Marks::mark($id);
        }
        wp_send_json_success($out);
    }
}
