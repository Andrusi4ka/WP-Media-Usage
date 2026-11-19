<?php
/**
 * Plugin Name:       WP Media Usage
 * Plugin URI:        https://relevant.no/
 * Description:       Shows in the Media Library which files are not used anywhere on the site: an Unused mark on each, a filter, Ignore. Delete them as usual.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Relevant AS
 * Author URI:        https://relevant.no/
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-media-usage
 * Domain Path:       /languages
 * Update URI:        https://github.com/Andrusi4ka/WP-Media-Usage
 */
// "Update URI": updates come from this plugin's GitHub releases (WMU_Updates), never from wordpress.org.
defined('ABSPATH') || exit;

define('WMU_VERSION', '1.0.0');
define('WMU_FILE', __FILE__);
define('WMU_DIR', plugin_dir_path(__FILE__));
define('WMU_URL', plugin_dir_url(__FILE__));

// Finding media (no WordPress), the scan (its course, its reading, its result), the Media Library (marks, places, its parts).
foreach (array('extract', 'settings', 'scan', 'phases', 'resolve', 'marks', 'places', 'assets', 'library', 'list', 'details', 'ajax', 'updates') as $wmu_part) {
    require_once WMU_DIR.'includes/class-wmu-'.$wmu_part.'.php';
}
unset($wmu_part);

WMU_Updates::init();
add_action('init', function () { load_plugin_textdomain('wp-media-usage', false, dirname(plugin_basename(WMU_FILE)).'/languages'); });
// The checkbox and the check: always. The marks in the Media Library: only while it is ticked.
if (is_admin()) {
    $wmu_classes = WMU_Settings::on() ? array('WMU_Assets', 'WMU_Ajax', 'WMU_Library', 'WMU_List', 'WMU_Details') : array('WMU_Assets', 'WMU_Ajax');
    foreach ($wmu_classes as $wmu_class) { $wmu_class::init(); }
    unset($wmu_classes, $wmu_class);
}
// A post saved while it is on: what it names loses its Unused mark at once.
if (WMU_Settings::on()) { add_action('save_post', array('WMU_Resolve', 'saved'), 20, 2); }
