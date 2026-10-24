<?php
/**
 * Plugin Name: Content Trees
 * Description: Collapsible navigation for your archives, pages, categories and tags.
 * Version: 0.9.7
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: content-trees
 */

namespace ContentTrees;

defined('ABSPATH') || exit;

const VERSION = '0.9.7';

function register_assets() {
    wp_register_style('content-trees', plugins_url('archive.css', __FILE__), array(), VERSION);
	wp_register_script(
		'content-trees-editor-selector',
		plugins_url( 'editor-selector.js', __FILE__ ),
		array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ),
		VERSION,
		true
	);
	wp_set_script_translations( 'content-trees-editor-selector', 'content-trees' );
}

add_action( 'init', __NAMESPACE__ . '\register_assets' );

/** Shared boolean settings for blocks, classic widgets and shortcodes. */
function enabled($value) {
    return in_array($value, array(true, 1, '1', 'true', 'on'), true);
}

/** Normalize saved/shortcode exclusions without accepting nested or malformed input. */
function exclusion_ids($value) {
    $values = is_array($value) ? $value : (is_string($value) ? preg_split('/[\s,]+/', $value) : array());
    $ids = array();
    foreach ($values as $id) {
        if ((is_int($id) || is_string($id)) && ctype_digit((string) $id) && (int) $id > 0) {
            $ids[] = (int) $id;
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function is_public_post($post) {
    return $post instanceof \WP_Post && $post->post_type === 'post'
        && $post->post_status === 'publish' && $post->post_password === '';
}

function post_node($post) {
    $label = html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return array(
        'id' => $post->ID,
        'label' => $label !== '' ? $label : __('(Untitled)', 'content-trees'),
        'url' => esc_url_raw(get_permalink($post)),
    );
}

add_action('admin_enqueue_scripts', static function ($hook) {
    if ($hook === 'widgets.php' || $hook === 'customize.php') {
        wp_enqueue_script('ctrees-settings', plugins_url('settings.js', __FILE__), array('wp-api-fetch', 'jquery'), VERSION, true);
    }
});

// Show one clear choice in the block inserter; saved legacy widgets still render.
add_filter('widget_types_to_hide_from_legacy_widget_block', static function ($types) {
    $types[] = 'ctrees_archive';
    $types[] = 'ctrees_pages';
    return $types;
});

require_once __DIR__ . '/archive.php';
require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/taxonomy.php';
