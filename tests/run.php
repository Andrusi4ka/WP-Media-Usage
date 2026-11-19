<?php
/**
 * Checks of WMU_Extract without WordPress: php tests/run.php (CI runs it on every push). Each case is how a real plugin or
 * the theme keeps a picture; one missing would mark a file in use as Unused, and its owner would delete it.
 */
define('ABSPATH', __DIR__.'/');
require __DIR__.'/../includes/class-wmu-extract.php';

$failed = 0;
function check($ok, $what) { global $failed; if (!$ok) { $failed++; fwrite(STDERR, "FAIL: $what\n"); } }
function same($a, $b) { sort($a); sort($b); return $a == $b; }
$ids = function ($text) { return WMU_Extract::ids_from_text($text); };
$paths = function ($text) { return WMU_Extract::paths_from_text($text); };

// Keys.
check(WMU_Extract::media_key('_thumbnail_id') && WMU_Extract::media_key('bgImage') && WMU_Extract::media_key('site_icon') && WMU_Extract::media_key('_product_image_gallery') && WMU_Extract::media_key('custom_logo') && WMU_Extract::media_key('images'), 'Media keys');
check(!WMU_Extract::media_key('thumbnail_size_w') && !WMU_Extract::media_key('image_width') && !WMU_Extract::media_key('el_id') && !WMU_Extract::media_key('_dp_original') && !WMU_Extract::media_key('page_on_front') && !WMU_Extract::media_key('posts_per_page'), 'Not media keys: sizes, other IDs');

// Text: what WordPress writes.
check(same($ids('<img class="alignnone size-medium wp-image-45" src="x.jpg"> [caption id="attachment_46"]x[/caption] <a rel="attachment wp-att-47">'), array(45, 46, 47)), 'wp-image-, attachment_, wp-att-');
check(same($ids('[gallery ids="1,2, 3" columns="4"] [gallery include="9"]'), array(1, 2, 3, 9)), 'Gallery ids and include, not columns');
check(same($ids('<!-- wp:image {"id":51,"sizeSlug":"large"} --> <!-- wp:gallery {"ids":[52,53]} --> <!-- wp:media-text {"mediaId":54,"mediaWidth":40} -->'), array(51, 52, 53, 54)), 'Blocks: id, ids, mediaId; not mediaWidth');
check($ids('<a href="?attachment_id=61">') === array(61), 'attachment_id=');
// WPBakery: shortcodes, Design Options backgrounds, param groups (URL-encoded JSON), raw HTML (base64).
check(same($ids('[vc_single_image image="70" img_size="300x200"][vc_gallery images="71,72"][woodmart_banner image="73" bg_image_id="74" el_id="999" width="300"]'), array(70, 71, 72, 73, 74)), 'WPBakery and WoodMart attributes, not el_id / width / img_size');
$css = '.vc_custom_1{background-image: url(https://site.test/wp-content/uploads/2026/11/bg.jpg?id=75) !important;}';
check($ids($css) === array(75) && $paths($css) === array('2026/11/bg.jpg'), 'Design Options: ?id= and the file');
check($ids('[woodmart_list items="%5B%7B%22image%22%3A%2276%22%2C%22title%22%3A%22A%22%7D%5D"]') === array(76), 'A param group (URL-encoded JSON)');
$raw = base64_encode(rawurlencode('<img src="/wp-content/uploads/2026/10/raw.png">'));
check($paths('[vc_raw_html]'.$raw.'[/vc_raw_html]') === array('2026/10/raw.png'), 'Raw HTML (base64, URL-encoded)');
check(!$ids('<div id="123" data-count="5" style="width:300px">') , 'Plain numbers in HTML: not IDs');

// Paths.
check(same($paths('<img src="https://site.test/wp-content/uploads/2026/11/Photo-300x200.JPG" srcset="https://site.test/wp-content/uploads/2026/11/Photo-1024x683.JPG 1024w, https://site.test/wp-content/uploads/2026/11/Photo.JPG 2000w">'),
    array('2026/11/photo-300x200.jpg', '2026/11/photo-1024x683.jpg', '2026/11/photo.jpg')), 'src and srcset, lower case');
check($paths('{"url":"https:\/\/site.test\/wp-content\/uploads\/2026\/11\/esc.png"}') === array('2026/11/esc.png'), 'JSON-escaped slashes');
check($paths('url("../uploads/sites/3/2026/11/My%20File.webp")') === array('2026/11/my file.webp'), 'Multisite folder dropped, %20 decoded');
check($paths('/wp-content/uploads/2026/11/a.jpg.webp') === array('2026/11/a.jpg'), 'An optimizer\'s .webp copy counts for its file');
check($paths('/wp-content/uploads/2026/11/') === array() && $paths('no uploads here.jpg') === array(), 'No file, no path');

// Stored values.
list($i, $p) = WMU_Extract::from_value('45', '_thumbnail_id');
check($i === array(45), 'A featured image');
list($i) = WMU_Extract::from_value('1,2,3', '_product_image_gallery');
check(same($i, array(1, 2, 3)), 'A WooCommerce gallery');
list($i) = WMU_Extract::from_value('150', 'thumbnail_size_w');
check($i === array(), 'A size option is not an ID');
// WP Swiper's slides: id with caption / poster.
$slides = serialize(array(array('id'=>98, 'caption'=>'', 'link'=>'', 'new_tab'=>false, 'poster'=>0), array('id'=>97, 'caption'=>'', 'poster'=>12)));
list($i) = WMU_Extract::from_value($slides, '_wp_swiper_slides');
check(same($i, array(98, 97, 12)), 'WP Swiper slides and a poster');
// WP Banner's scene: an image layer, a Shape object's image.
$scene = serialize(array('height'=>600, 'layers'=>array(array('type'=>'image', 'image'=>1652, 'x'=>50, 'scale'=>100), array('type'=>'shape', 'shape'=>900, 'shape_objects'=>array(array('key'=>'o1', 'image'=>77))))));
list($i) = WMU_Extract::from_value($scene, '_wpbn_scene');
check(same($i, array(1652, 77)), 'WP Banner scene: image layers and Shape objects ('.implode(',', $i).')');
// Redux / WoodMart media field, Customizer, a widget, Elementor, header_image_data.
$opts = serialize(array('logo'=>array('url'=>'https://site.test/wp-content/uploads/2026/01/logo.svg', 'id'=>'81', 'width'=>'200'), 'sticky_logo'=>array('id'=>82, 'url'=>''), 'header_bg'=>array('background-image'=>'https://site.test/wp-content/uploads/2026/01/hb.jpg', 'media'=>array('id'=>'83'))));
list($i, $p) = WMU_Extract::from_value($opts, 'xts-woodmart-options');
check(same($i, array(81, 82, 83)) && same($p, array('2026/01/logo.svg', '2026/01/hb.jpg')), 'Theme settings: media fields and files');
list($i) = WMU_Extract::from_value(serialize(array('custom_logo'=>84, 'header_image_data'=>(object) array('attachment_id'=>85, 'url'=>'x'))), 'theme_mods_woodmart-child');
check(same($i, array(84, 85)), 'Customizer: logo and header image');
list($i) = WMU_Extract::from_value(serialize(array(2=>array('attachment_id'=>86, 'size'=>'full'), '_multiwidget'=>1)), 'widget_media_image');
check($i === array(86), 'An image widget');
list($i) = WMU_Extract::from_value('[{"id":"a1","elements":[{"settings":{"image":{"url":"x.jpg","id":87}}}]}]', '_elementor_data');
check($i === array(87), 'Elementor (JSON)');
// Signatur: an image element by URL in JSON.
list($i, $p) = WMU_Extract::from_value('{"rows":[[[1,1,{"type":"image","url":"https:\/\/site.test\/wp-content\/uploads\/2026\/11\/sig.png","width":200}]]]}', '');
check($p === array('2026/11/sig.png'), 'Signatur: an image by URL');
check(WMU_Extract::decode('O:8:"stdClass":1:{s:1:"a";i:1;}') instanceof __PHP_Incomplete_Class, 'A serialized object is never built');

// An attachment's files.
$files = WMU_Extract::files_of('2026/11/big-scaled.jpg', array('file'=>'2026/11/big-scaled.jpg', 'original_image'=>'big.jpg', 'sizes'=>array('thumbnail'=>array('file'=>'big-150x150.jpg'), 'medium'=>array('file'=>'big-300x200.jpg'))), array('full-orig'=>array('file'=>'big-e1700000000.jpg')));
check(same($files, array('2026/11/big-scaled.jpg', '2026/11/big.jpg', '2026/11/big-150x150.jpg', '2026/11/big-300x200.jpg', '2026/11/big-e1700000000.jpg')), 'Files: scaled, original, sizes, backups');
check(WMU_Extract::files_of('flat.png', false) === array('flat.png'), 'A file outside year folders');

if ($failed) { fwrite(STDERR, "$failed check(s) failed\n"); exit(1); }
echo "WP Media Usage checks passed.\n";
