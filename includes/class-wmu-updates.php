<?php
/**
 * Updates from GitHub, as WP Banner's: a release (tag vX.Y.Z with the wp-media-usage.zip built by .github/workflows/release.yml)
 * shows up under Plugins like any other update, with "View details" (from readme.txt) and "Check for updates".
 * Plugin Update Checker (vendor/plugin-update-checker, YahnisElsts v5).
 */
defined('ABSPATH') || exit;

class WMU_Updates {
    const REPOSITORY = 'https://github.com/Andrusi4ka/WP-Media-Usage/';

    public static function init() {
        add_action('plugins_loaded', array(__CLASS__, 'checker'));
    }

    public static function checker() {
        $library = WMU_DIR.'vendor/plugin-update-checker/plugin-update-checker.php';
        if ((!is_admin() && !wp_doing_cron()) || !file_exists($library)) { return; }
        require_once $library;
        $checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(self::REPOSITORY, WMU_FILE, 'wp-media-usage');
        // The release's zip, which holds just the plugin (not the repository's dev files).
        $checker->getVcsApi()->enableReleaseAssets('/wp-media-usage\.zip($|[?&#])/i');
        $checker->setBranch('main');
    }
}
