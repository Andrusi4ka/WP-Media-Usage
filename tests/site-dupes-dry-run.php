<?php
/**
 * A dry run of the duplicates check on a real site: reads the files, writes nothing (WMU_Dupes::run(…, false): no _wmu_hash,
 * no _wmu_dup), lists the groups of exact copies. php tests/site-dupes-dry-run.php <path to the site's wp-load.php>
 * The plugin need not be active.
 */
if (PHP_SAPI !== 'cli') { exit; }
define('WP_USE_THEMES', false);
require $argv[1] ?? __DIR__.'/../../../../wp-load.php';
if (!class_exists('WMU_Dupes')) {
    foreach (array('extract', 'same', 'dupes') as $part) { require_once __DIR__.'/../includes/class-wmu-'.$part.'.php'; }
}
$state = WMU_Dupes::start();
$t = microtime(true);
while ($state['phase'] !== 'done') { WMU_Dupes::step($state, false); }
printf("Compared %d files in %.2fs: %d duplicates in %d groups\n\n", $state['total'], microtime(true) - $t, $state['copies'], count($state['groups']));
foreach ($state['groups'] as $md5 => $ids) {
    echo $md5."\n";
    foreach ($ids as $id) { echo "  #$id ".get_post_meta($id, '_wp_attached_file', true)."\n"; }
}
