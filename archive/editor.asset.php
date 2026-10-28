<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- WordPress requires this metadata filename.
/**
 * Block editor asset dependencies.
 *
 * @package ContentTrees
 */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render', 'content-trees-editor-selector' ),
	'version'      => \ContentTrees\VERSION,
);
