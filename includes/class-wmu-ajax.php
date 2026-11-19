<?php
/**
 * admin-ajax: wmu_switch (the checkbox), wmu_scan (a few seconds of the check a request; its state between requests in an
 * option, not autoloaded) and wmu_ignore (the grid's Ignore selected / Stop ignoring selected).
 */
defined('ABSPATH') || exit;

class WMU_Ajax {
    // Seconds of scanning a request.
    const SECONDS = 3.0;
    // Who may tick the checkbox; who may run the check (a ticked box finishes an unfinished one for anyone using the library).
    const SWITCH_CAP = 'manage_options';
    const SCAN_CAP = 'upload_files';
    // A check whose state was saved this recently is still running (another tab, another user): joined, not started again.
    const RUNNING = 30;

    public static function init() {
        add_action('wp_ajax_wmu_switch', array(__CLASS__, 'switch_on'));
        add_action('wp_ajax_wmu_scan', array(__CLASS__, 'scan'));
        add_action('wp_ajax_wmu_ignore', array(__CLASS__, 'ignore'));
    }

    public static function switch_on() {
        check_ajax_referer('wmu', 'nonce');
        if (!current_user_can(self::SWITCH_CAP)) { wp_send_json_error(null, 403); }
        WMU_Settings::set(!empty($_POST['on']));
        wp_send_json_success();
    }

    public static function scan() {
        check_ajax_referer('wmu', 'nonce');
        if (!current_user_can(self::SCAN_CAP) || !WMU_Settings::on()) { wp_send_json_error(null, 403); }
        $state = self::state(!empty($_POST['start']));
        if (function_exists('set_time_limit')) { @set_time_limit(60); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
        WMU_Scan::run($state, self::SECONDS, true);
        if ($state['phase'] === 'done') {
            WMU_Scan::finish($state);
            wp_send_json_success(array('done'=>true, 'last'=>WMU_Marks::last_text()));
        }
        $state['touched'] = time();
        update_option(WMU_Scan::STATE, $state, false);
        wp_send_json_success(array('done'=>false, 'phase'=>$state['phase'], 'checked'=>$state['total']));
    }
    // The check to go on with: the one saved, unless a new one is asked for and none is running now.
    private static function state($start) {
        $state = get_option(WMU_Scan::STATE);
        $running = is_array($state) && !empty($state['phase']) && time() - (int) ($state['touched'] ?? 0) < self::RUNNING;
        return ($start && !$running) || !is_array($state) || empty($state['phase']) ? WMU_Scan::start() : $state;
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
