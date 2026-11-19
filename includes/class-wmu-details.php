<?php
/**
 * An attachment's details (the grid's window and its edit screen): Usage (where it is used, or Unused) and the Ignore box.
 * A hidden wmu_form field tells an unchecked box (not sent) from a form without it.
 */
defined('ABSPATH') || exit;

class WMU_Details {
    public static function init() {
        add_filter('attachment_fields_to_edit', array(__CLASS__, 'fields'), 10, 2);
        add_filter('attachment_fields_to_save', array(__CLASS__, 'save'), 10, 2);
    }

    public static function fields($fields, $post) {
        $fields['wmu_usage'] = array('label'=>__('Usage', 'wp-media-usage'), 'input'=>'html', 'html'=>'<div class="wmu-usage">'.WMU_Places::usage_html($post->ID).'</div>');
        $fields['wmu_ignore'] = array('label'=>__('Usage check', 'wp-media-usage'), 'input'=>'html', 'html'=>self::ignore_box($post->ID),
            'helps'=>__('For a file used outside the site, e.g. in newsletters or on social media.', 'wp-media-usage'));
        return $fields;
    }
    private static function ignore_box($id) {
        $name = 'attachments['.$id.']';
        return '<input type="hidden" name="'.esc_attr($name.'[wmu_form]').'" value="1">'
            .'<label><input type="checkbox" name="'.esc_attr($name.'[wmu_ignore]').'" value="1"'.checked(WMU_Marks::mark($id)['ignored'], true, false).'> '
            .esc_html__('Ignore (never mark as Unused)', 'wp-media-usage').'</label>';
    }

    public static function save($post, $data) {
        if (isset($data['wmu_form']) && current_user_can('edit_post', $post['ID'])) { WMU_Marks::ignore((int) $post['ID'], !empty($data['wmu_ignore'])); }
        return $post;
    }
}
