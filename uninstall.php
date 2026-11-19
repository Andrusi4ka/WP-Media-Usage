<?php
// Deleting the plugin removes what it stored: each attachment's mark (used / unused, where, ignored) and the last scan. No file is touched.
defined('WP_UNINSTALL_PLUGIN') || exit;
foreach (array('_wmu_state', '_wmu_where', '_wmu_ignored') as $key) { delete_metadata('post', 0, $key, '', true); }
delete_option('wmu_scan_state');
delete_option('wmu_last_scan');
