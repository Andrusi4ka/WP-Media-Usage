<?php
/**
 * Plugin Name:       WP Media Usage
 * Plugin URI:        https://relevant.no/
 * Description:       Shows in the Media Library which files are not used anywhere on the site (an Unused mark, a filter, Ignore) and which were uploaded more than once (a Duplicate mark, a filter, a window with each copy and where it is used).
 * Version:           1.1.0
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

define('WMU_VERSION', '1.1.0');
define('WMU_FILE', __FILE__);
define('WMU_DIR', plugin_dir_path(__FILE__));
define('WMU_URL', plugin_dir_url(__FILE__));

// Finding media (no WordPress), the scan (its course, its reading, its result), exact copies (no WordPress, their check, the
// copies shown, their window), the Media Library (marks, places, its parts).
foreach (array('extract', 'settings', 'scan', 'phases', 'resolve', 'marks', 'same', 'dupes', 'copies', 'modal', 'places', 'assets', 'library', 'list', 'details', 'ajax', 'updates') as $wmu_part) {
    require_once WMU_DIR.'includes/class-wmu-'.$wmu_part.'.php';
}
unset($wmu_part);

WMU_Updates::init();
add_action('init', function () { load_plugin_textdomain('wp-media-usage', false, dirname(plugin_basename(WMU_FILE)).'/languages'); });
// The checkboxes and the checks: always. The marks in the Media Library: only while one is ticked (each class shows its part's).
if (is_admin()) {
    $wmu_classes = WMU_Settings::any() ? array('WMU_Assets', 'WMU_Ajax', 'WMU_Library', 'WMU_List', 'WMU_Details') : array('WMU_Assets', 'WMU_Ajax');
    foreach ($wmu_classes as $wmu_class) { $wmu_class::init(); }
    unset($wmu_classes, $wmu_class);
}
// A post saved while a checkbox is on (the duplicates use the usage too): what it names loses its Unused mark at once.
if (WMU_Settings::any()) { add_action('save_post', array('WMU_Resolve', 'saved'), 20, 2); }
// A file deleted: a copy left alone loses its Duplicate mark (always, so the marks stay right while unticked too).
add_action('delete_attachment', array('WMU_Copies', 'forget'));
