<?php
// Deleting the plugin removes what it stored: each attachment's marks (used / unused, where, ignored, its hash, duplicate) and the last checks. No file is touched.
defined('WP_UNINSTALL_PLUGIN') || exit;
foreach (array('_wmu_state', '_wmu_where', '_wmu_ignored', '_wmu_hash', '_wmu_dup') as $key) { delete_metadata('post', 0, $key, '', true); }
foreach (array('wmu_scan_state', 'wmu_last_scan', 'wmu_enabled', 'wmu_dupes_state', 'wmu_dupes_last', 'wmu_dupes') as $option) { delete_option($option); }
