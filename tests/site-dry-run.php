<?php
/**
 * A dry run of the scan on a real site: reads everything, writes nothing (WMU_Scan::run(…, false)), lists the attachments it
 * would mark Unused and where the others were found. php tests/site-dry-run.php <path to the site's wp-load.php>
 * The plugin need not be active.
 */
if (PHP_SAPI !== 'cli') { exit; }
define('WP_USE_THEMES', false);
require $argv[1] ?? __DIR__.'/../../../../wp-load.php';
if (!class_exists('WMU_Scan')) {
    foreach (array('extract', 'scan', 'phases', 'resolve') as $part) { require_once __DIR__.'/../includes/class-wmu-'.$part.'.php'; }
}
$state = WMU_Scan::start();
$t = microtime(true);
while ($state['phase'] !== 'done') { WMU_Scan::step($state, false); }
printf("Checked %d files in %.2fs, %d unused (rows read: %d)\n\n", $state['total'], microtime(true) - $t, $state['unused'], $state['read']);
foreach ($state['result'] as $id => $where) {
    $file = get_post_meta($id, '_wp_attached_file', true);
    if ($where === false) { echo "UNUSED  #$id $file\n"; continue; }
    echo "used    #$id $file  <- ".implode(', ', $where['s']).($where['n'] > count($where['s']) ? ' +'.($where['n'] - count($where['s'])) : '')."\n";
}
