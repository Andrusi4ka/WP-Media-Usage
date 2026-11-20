<?php
/**
 * Exact copies, without WordPress (tests/run.php checks it): which file of an attachment is compared, and the groups of
 * attachments with the same key (the same size, then the same hash).
 */
defined('ABSPATH') || exit;

class WMU_Same {
    /*
     * The file an attachment was uploaded as, relative to uploads: for a big photo WordPress keeps the upload (original_image)
     * beside its -scaled copy, and the upload is what two copies share. An edited image ($edited: it has backup sizes) is
     * compared as it is now.
     */
    public static function source($attached, $meta, $edited = false) {
        $attached = ltrim(str_replace('\\', '/', (string) $attached), '/');
        if ($attached === '') { return ''; }
        if ($edited || !is_array($meta) || empty($meta['original_image']) || !is_string($meta['original_image'])) { return $attached; }
        $dir = strpos($attached, '/') !== false ? substr($attached, 0, strrpos($attached, '/') + 1) : '';
        return $dir.basename(str_replace('\\', '/', $meta['original_image']));
    }

    // [id => key] as [key => ids] for the keys two or more attachments share (the IDs in order).
    public static function groups($keys) {
        $by = array();
        foreach ($keys as $id => $key) { $by[(string) $key][] = (int) $id; }
        $out = array();
        foreach ($by as $key => $ids) {
            if (count($ids) < 2) { continue; }
            sort($ids);
            $out[$key] = $ids;
        }
        return $out;
    }
}
