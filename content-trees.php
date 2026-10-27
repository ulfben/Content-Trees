<?php
/**
 * Plugin Name: Content Trees
 * Description: Collapsible navigation for your archives, pages, categories and tags.
 * Version: 0.9.10
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: content-trees
 *
 * @package ContentTrees
 */

namespace ContentTrees;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.9.10';

require_once __DIR__ . '/tree.php';

/**
 * Register shared frontend and editor assets.
 */
function register_assets() {
	wp_register_style( 'content-trees', plugins_url( 'archive.css', __FILE__ ), array(), VERSION );
	wp_register_script( 'content-trees-post-loader', plugins_url( 'post-loader.js', __FILE__ ), array(), VERSION, true );
	wp_register_script( 'content-trees-tree', plugins_url( 'tree.js', __FILE__ ), array( 'content-trees-post-loader' ), VERSION, true );
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

/**
 * Enqueue selectors on the two classic-widget editing screens.
 *
 * @param string $hook Current admin screen hook.
 */
function enqueue_settings( $hook ) {
	if ( in_array( $hook, array( 'widgets.php', 'customize.php' ), true ) ) {
		wp_enqueue_script( 'ctrees-settings', plugins_url( 'settings.js', __FILE__ ), array( 'wp-api-fetch', 'jquery' ), VERSION, true );
	}
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_settings' );

/**
 * Keep the dedicated blocks as the single choices in the block inserter.
 *
 * @param string[] $types Hidden legacy widget IDs.
 */
function hide_legacy_widgets( $types ) {
	foreach ( array( 'archive', 'pages', 'categories', 'tags' ) as $name ) {
		$types[] = 'ctrees_' . $name;
	}
	return array_values( array_unique( $types ) );
}
add_filter( 'widget_types_to_hide_from_legacy_widget_block', __NAMESPACE__ . '\\hide_legacy_widgets' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_detected_trees' );

require_once __DIR__ . '/archive.php';
require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/taxonomy.php';
