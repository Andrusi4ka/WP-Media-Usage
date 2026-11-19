<?php
/**
 * Plugin Name:       WP Media Usage
 * Description:       Shows in the Media Library which files are not used anywhere on the site: an Unused mark on each, a filter, Ignore. Delete them as usual.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Andrusi4ka
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-media-usage
 * Domain Path:       /languages
 */
defined('ABSPATH') || exit;

define('WMU_VERSION', '1.0.0');
define('WMU_FILE', __FILE__);
define('WMU_DIR', plugin_dir_path(__FILE__));
define('WMU_URL', plugin_dir_url(__FILE__));

require_once WMU_DIR.'includes/class-wmu-extract.php';
require_once WMU_DIR.'includes/class-wmu-scanner.php';
require_once WMU_DIR.'includes/class-wmu-media.php';

add_action('init', function () { load_plugin_textdomain('wp-media-usage', false, dirname(plugin_basename(__FILE__)).'/languages'); });
if (is_admin()) { WMU_Media::init(); }
// A post saved anywhere (the editor, the REST API of the block editor, WPBakery's frontend editor): what it names loses its Unused mark.
add_action('save_post', array('WMU_Media', 'saved'), 20, 2);
