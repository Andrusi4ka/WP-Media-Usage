<?php
/**
 * The scan's course: reads the whole site in small steps (each admin-ajax request runs a few seconds of them) and keeps what it
 * found between requests. The reading is in WMU_Phases, the result per attachment in WMU_Resolve.
 *
 * Its state: phase, cursor (the last row read in the phase), ids [id => slot], paths [path => slot] (a slot: ['n' => how many
 * places, 's' => the first KEEP of them as tokens]), read (rows), total and unused (attachments), started.
 * Tokens: p:<post id>, t:<term id>, o:<option name>, u:<user id>, f:<theme file>, x:<label>:<row id> (WMU_Places shows them).
 */
defined('ABSPATH') || exit;

class WMU_Scan {
    const STATE = 'wmu_scan_state';
    const LAST = 'wmu_last_scan';
    const PHASES = array('posts', 'covers', 'terms', 'termdesc', 'options', 'users', 'theme', 'tables', 'resolve');
    // How many places are kept for each attachment (the rest only counted).
    const KEEP = 5;

    public static function start() {
        return array('phase'=>'posts', 'cursor'=>0, 'ids'=>array(), 'paths'=>array(), 'started'=>time(), 'read'=>0, 'total'=>0, 'unused'=>0);
    }

    /*
     * Runs steps until $seconds have passed or the scan is done. $write: whether the result goes into the attachments' meta
     * (false: a dry run, tests/site-dry-run.php; the result is then in $state['result']).
     */
    public static function run(&$state, $seconds = 3.0, $write = true) {
        $until = microtime(true) + $seconds;
        do { self::step($state, $write); } while ($state['phase'] !== 'done' && microtime(true) < $until);
        return $state;
    }

    // One step of the current phase; the next phase once it has read all.
    public static function step(&$state, $write = true) {
        $phase = $state['phase'];
        if ($phase === 'done') { return; }
        $more = $phase === 'resolve' ? WMU_Resolve::step($state, $write) : WMU_Phases::step($phase, $state);
        if (!$more) { self::next($state); }
    }
    private static function next(&$state) {
        $at = array_search($state['phase'], self::PHASES, true);
        $state['phase'] = self::PHASES[$at + 1] ?? 'done';
        $state['cursor'] = 0;
    }

    // What a text or a stored value names, with where.
    public static function text(&$state, $text, $source) {
        self::found($state, WMU_Extract::ids_from_text($text), WMU_Extract::paths_from_text($text), $source);
    }
    public static function value(&$state, $value, $key, $source) {
        list($ids, $paths) = WMU_Extract::from_value($value, $key);
        self::found($state, $ids, $paths, $source);
    }
    public static function found(&$state, $ids, $paths, $source) {
        foreach ($ids as $id) { self::note($state['ids'][(int) $id], $source); }
        foreach ($paths as $path) { self::note($state['paths'][$path], $source); }
    }
    // A place added to a slot once; the first KEEP kept, the rest counted.
    private static function note(&$slot, $source) {
        if (!is_array($slot)) { $slot = array('n'=>0, 's'=>array()); }
        if (in_array($source, $slot['s'], true)) { return; }
        $slot['n']++;
        if (count($slot['s']) < self::KEEP) { $slot['s'][] = $source; }
    }

    // Where it is, for the progress beside the checkbox: the files checked (resolve).
    public static function progress($state) {
        return (string) $state['total'];
    }

    // The scan done: its numbers kept (timed from its start), its state removed.
    public static function finish($state) {
        delete_option(self::STATE);
        update_option(self::LAST, array('time'=>(int) ($state['started'] ?? time()), 'total'=>$state['total'], 'unused'=>$state['unused']), false);
    }
}
