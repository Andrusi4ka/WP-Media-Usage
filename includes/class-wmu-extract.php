<?php
/**
 * Finding media in what the site stores: attachment IDs and file paths in text (post content, shortcodes,
 * blocks, CSS, HTML, JSON) and in structured values (post meta, options, term meta: arrays as WordPress
 * unserializes them, JSON).
 *
 * No WordPress needed here (tests/run.php checks it alone). When unsure, it counts a thing as used: an image
 * marked Unused that is in use would be deleted by its owner, one marked used that is not only stays.
 */
defined('ABSPATH') || exit;

class WMU_Extract {
    // The words of a key that name a picture or a file (a key is split into words at _ - and camelCase).
    const MEDIA_WORDS = array('image', 'images', 'img', 'imgs', 'photo', 'photos', 'picture', 'pictures', 'pic', 'thumb', 'thumbnail',
        'thumbnails', 'logo', 'logos', 'icon', 'icons', 'favicon', 'bg', 'background', 'backgrounds', 'media', 'attachment', 'attachments',
        'gallery', 'galleries', 'poster', 'avatar', 'banner', 'cover', 'video', 'videos', 'audio', 'file', 'files', 'ids', 'include',
        'slide', 'slides', 'svg', 'marker', 'pdf', 'document', 'download', 'mp4', 'webm');
    // A key ending in one of these is a size or a setting of a picture, not the picture: image_width, thumbnail_size_w, logo_height.
    const SETTING_WORDS = array('size', 'sizes', 'width', 'height', 'w', 'h', 'crop', 'count', 'quality', 'opacity', 'position', 'pos', 'x', 'y',
        'radius', 'color', 'colour', 'ratio', 'max', 'min', 'limit', 'per', 'columns', 'cols', 'speed', 'delay', 'duration', 'blur', 'zoom',
        'spacing', 'gap', 'margin', 'padding', 'style', 'align', 'type', 'fit', 'scale', 'rotate', 'offset', 'number', 'num', 'order',
        'index', 'lazy', 'loading', 'border', 'shadow', 'repeat', 'attachment_type', 'version', 'time', 'date', 'status', 'mode', 'per_page');
    // An array holding an "id" beside one of these keys is a picture field (WP Swiper's slides: id, caption, poster; Redux and
    // WoodMart media fields: id, url, width, height, thumbnail; Elementor: id, url).
    const ID_SIBLINGS = array('url', 'src', 'caption', 'alt', 'poster', 'sizes', 'filename', 'thumbnail', 'image', 'mime', 'mime_type', 'source');
    // A file's extension: what may follow the last dot of a path found.
    const EXT = '[a-z0-9]{2,5}';

    // The words of a key, lower case: "bgImage" → bg, image; "_thumbnail_id" → thumbnail, id.
    public static function words($key) {
        $key = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string) $key);
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($key)), 'strlen'));
    }

    // Whether a key holds a picture (its ID or IDs): a media word in it, not ending in a setting word.
    public static function media_key($key) {
        $words = self::words($key);
        if (!$words || !array_intersect($words, self::MEDIA_WORDS)) { return false; }
        $last = end($words);
        return !in_array($last, self::SETTING_WORDS, true);
    }

    // IDs in a value: a number, a numeric string or a list "1, 2,3" (positive whole numbers only).
    public static function id_list($value) {
        if (is_int($value)) { return $value > 0 ? array($value) : array(); }
        if (!is_string($value) || !preg_match('/^\s*\d+(\s*,\s*\d+)*\s*$/', $value)) { return array(); }
        return array_values(array_filter(array_map('intval', explode(',', $value))));
    }

    /*
     * A path inside the uploads folder as attachments keep it (_wp_attached_file: "2026/11/photo.jpg"), lower case, without a
     * multisite's sites/N/ and without an optimizer's second extension (photo.jpg.webp → photo.jpg).
     */
    public static function normalize_path($path) {
        $path = strtolower(str_replace('\\', '/', rawurldecode((string) $path)));
        $path = ltrim(preg_replace('~^sites/\d+/~', '', ltrim($path, '/')), '/');
        return preg_replace('~\.(jpe?g|png|gif|bmp|tiff?)\.(webp|avif)$~', '.$1', $path);
    }

    // Text as the scans read it: JSON's escaped slashes and HTML's quotes made plain.
    private static function plain($text) {
        return str_replace(array('\\/', '&quot;', '&#34;', '&#039;', '&#39;', '&amp;'), array('/', '"', '"', "'", "'", '&'), $text);
    }

    // The texts to scan: the text itself, its URL-decoded copy (WPBakery's param groups) and the content of WPBakery's raw HTML / JS (base64).
    private static function layers($text) {
        $out = array(self::plain($text));
        if (strpos($text, '%22') !== false || strpos($text, '%2F') !== false) { $out[] = self::plain(rawurldecode($text)); }
        if (preg_match_all('~\[vc_raw_(?:html|js)[^\]]*\]([A-Za-z0-9+/=\s%]+)\[/vc_raw_(?:html|js)\]~', $text, $m)) {
            foreach ($m[1] as $encoded) {
                $decoded = base64_decode(preg_replace('/\s+/', '', $encoded), true);
                if ($decoded !== false && $decoded !== '') { $out[] = self::plain(rawurldecode($decoded)); }
            }
        }
        return $out;
    }

    // Files in the uploads folder named in a text: [normalized path, …].
    public static function paths_from_text($text) {
        $text = (string) $text;
        // Nothing to find (an encoded one may hide the word: WPBakery's raw HTML, URL-encoded param groups).
        if (stripos($text, 'uploads') === false && strpos($text, 'vc_raw_') === false && stripos($text, '%2F') === false) { return array(); }
        $found = array();
        foreach (self::layers($text) as $t) {
            if (preg_match_all('~uploads/((?:sites/\d+/)?[^\s"\'<>()\\\\?#,;|*{}\[\]]+?\.'.self::EXT.')(?=[\s"\'<>()\\\\?#,;|*{}\[\]&]|$)~i', $t, $m)) {
                foreach ($m[1] as $path) { $found[self::normalize_path($path)] = true; }
            }
        }
        return array_keys($found);
    }

    // Attachment IDs named in a text (HTML, shortcodes, blocks, CSS): [id, …].
    public static function ids_from_text($text) {
        $ids = array();
        foreach (self::layers((string) $text) as $t) {
            foreach (array_merge(self::ids_marked($t), self::ids_in_attributes($t), self::ids_in_json($t)) as $id) {
                if ($id > 0) { $ids[(int) $id] = true; }
            }
        }
        return array_keys($ids);
    }
    // What WordPress writes: wp-image-12, attachment_12 (a caption's id), wp-att-12 (an image link's rel), attachment_id=12 in a
    // link; and ?id=12 after a file in the uploads folder (WPBakery's Design Options backgrounds).
    private static function ids_marked($t) {
        $out = array();
        foreach (array('/\b(?:wp-image-|attachment_|wp-att-)(\d+)\b/', '/[?&]attachment_id=(\d+)/', '~uploads/[^\s"\'<>()]*?\?id=(\d+)~i') as $re) {
            if (preg_match_all($re, $t, $m)) { $out = array_merge($out, array_map('intval', $m[1])); }
        }
        return $out;
    }
    // An attribute: image="12", images='1,2,3', bg_image="12", data-id="12" (shortcodes and HTML).
    private static function ids_in_attributes($t) {
        $out = array();
        if (!preg_match_all('/([A-Za-z][A-Za-z0-9_\-]*)\s*=\s*(["\'])\s*(\d+(?:\s*,\s*\d+)*)\s*\2/', $t, $m, PREG_SET_ORDER)) { return $out; }
        foreach ($m as $a) {
            if (self::media_key($a[1]) || in_array(strtolower($a[1]), array('data-id', 'data-attachment-id', 'data-image-id'), true)) { $out = array_merge($out, self::id_list($a[3])); }
        }
        return $out;
    }
    // A JSON pair: "id":12 (a block's picture), "mediaId":12, "ids":[1,2], "image":"12".
    private static function ids_in_json($t) {
        $out = array();
        if (!preg_match_all('/"([A-Za-z][A-Za-z0-9_\-]*)"\s*:\s*(\[\s*\d+(?:\s*,\s*\d+)*\s*\]|"\d+(?:\s*,\s*\d+)*"|\d+)/', $t, $m, PREG_SET_ORDER)) { return $out; }
        foreach ($m as $a) {
            if ($a[1] === 'id' || self::media_key($a[1])) { $out = array_merge($out, self::id_list(trim($a[2], '[]"'))); }
        }
        return $out;
    }

    // A serialized value or JSON read as data (no objects made from a serialized one); else the value as it was.
    public static function decode($value) {
        if (!is_string($value)) { return $value; }
        $t = trim($value);
        if ($t === '') { return $value; }
        if (preg_match('/^(a|O|s|i|d|b):[0-9:]/', $t) || $t === 'N;') {
            $data = @unserialize($t, array('allowed_classes'=>false));
            if ($data !== false || $t === 'b:0;') { return $data; }
        }
        if (($t[0] === '{' || $t[0] === '[') && strlen($t) < 5000000) {
            $data = json_decode($t, true);
            if (is_array($data)) { return $data; }
        }
        return $value;
    }

    /*
     * Everything a stored value names: [ids, paths]. $key: the name it is stored under (a meta key, an option's name), so a
     * plain number under _thumbnail_id or site_icon counts. Arrays are walked: a media key's number or list, an "id" beside a
     * picture's other keys, and every string as text.
     */
    public static function from_value($value, $key = '') {
        $ids = array(); $paths = array();
        self::walk(self::decode($value), (string) $key, $ids, $paths, 0);
        return array(array_keys($ids), array_keys($paths));
    }
    private static function walk($value, $key, &$ids, &$paths, $depth) {
        if ($depth > 40) { return; }
        if (is_object($value)) { $value = get_object_vars($value); }
        if (is_array($value)) {
            // An "id" beside a picture's other keys, or alone under a media key (media => [id => 12]).
            if (isset($value['id']) && (($key !== '' && self::media_key($key)) || array_intersect(array_map('strval', array_keys($value)), self::ID_SIBLINGS))) {
                foreach (self::id_list(is_string($value['id']) ? trim($value['id']) : $value['id']) as $id) { $ids[$id] = true; }
            }
            foreach ($value as $k => $v) {
                // A list under a media key (gallery => [1, 2]) keeps that key for its items.
                self::walk(self::decode($v), is_int($k) && $key !== '' && self::media_key($key) ? $key : (string) $k, $ids, $paths, $depth + 1);
            }
            return;
        }
        if (is_int($value) || is_string($value)) {
            if ($key !== '' && self::media_key($key)) { foreach (self::id_list($value) as $id) { $ids[$id] = true; } }
            if (is_string($value) && strlen($value) > 3) {
                foreach (self::ids_from_text($value) as $id) { $ids[$id] = true; }
                foreach (self::paths_from_text($value) as $path) { $paths[$path] = true; }
            }
        }
    }

    /*
     * An attachment's files, as paths in the uploads folder: its file, each size's, the original of a scaled image and the
     * sizes kept from before an edit. $file: _wp_attached_file; $meta: _wp_attachment_metadata; $backup: _wp_attachment_backup_sizes.
     */
    public static function files_of($file, $meta, $backup = array()) {
        $file = (string) $file;
        if ($file === '') { return array(); }
        $dir = strpos($file, '/') !== false ? substr($file, 0, strrpos($file, '/') + 1) : '';
        $out = array(self::normalize_path($file) => true);
        if (is_array($meta)) {
            if (!empty($meta['file']) && is_string($meta['file'])) { $out[self::normalize_path($meta['file'])] = true; }
            if (!empty($meta['original_image']) && is_string($meta['original_image'])) { $out[self::normalize_path($dir.$meta['original_image'])] = true; }
            foreach ((array) ($meta['sizes'] ?? array()) as $size) {
                if (is_array($size) && !empty($size['file']) && is_string($size['file'])) { $out[self::normalize_path($dir.$size['file'])] = true; }
            }
        }
        foreach ((array) $backup as $size) {
            if (is_array($size) && !empty($size['file']) && is_string($size['file'])) { $out[self::normalize_path($dir.$size['file'])] = true; }
        }
        return array_keys($out);
    }
}
