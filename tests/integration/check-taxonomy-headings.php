<?php
/** Read-only regression checks through WordPress's real block-default/preview paths. */
if ( PHP_SAPI !== 'cli' ) {
	exit( 2 ); }
$profile = $argv[1] ?? 'current';
if ( ! in_array( $profile, array( 'current', 'minimum' ), true ) ) {
	exit( 2 ); }
define( 'WP_INSTALLING', false );
require __DIR__ . '/bootstrap.php';
if ( ! defined( 'CONTENT_TREES_DISPOSABLE_TEST_SITE' ) || ! CONTENT_TREES_DISPOSABLE_TEST_SITE
	|| DB_NAME !== ( $profile === 'minimum' ? 'content_trees_minimum' : 'content_trees_test' )
	|| DB_HOST !== CONTENT_TREES_TEST_DB_HOST ) {
	exit( 2 ); }
set_exception_handler(
	static function ( $error ) {
		fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
		exit( 1 );
	}
);
$checks = 0;
function heading_check( $condition, $message ) {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
	++$checks;
	echo "PASS: $message\n";
}
$source = require __DIR__ . '/source.php';
heading_check( realpath( ( new ReflectionFunction( 'ContentTrees\\render_taxonomy' ) )->getFileName() ) === realpath( $source . '/taxonomy.php' ), 'Standalone taxonomy source is loaded.' );
$option_names = array( 'widget_ctrees_pages', 'widget_ctrees_categories', 'widget_ctrees_tags', 'widget_block', 'sidebars_widgets', 'stylesheet', 'template', 'WPLANG' );
$before       = array();
foreach ( $option_names as $name ) {
	$before[ $name ] = get_option( $name, '__missing_heading_option__' ); }
$old_user  = get_current_user_id();
$translate = static function ( $translation, $text, $domain ) {
	$strings = array(
		'Pages'      => 'Sidor',
		'Categories' => 'Kategorier',
		'Tags'       => 'Etiketter',
	);
	return $domain === 'content-trees' && isset( $strings[ $text ] ) ? $strings[ $text ] : $translation;
};
add_filter( 'gettext', $translate, 10, 3 );
try {
	wp_set_current_user( 1 );
	foreach ( array(
		'pages'      => array( 'Pages', 'Sidor' ),
		'categories' => array( 'Categories', 'Kategorier' ),
		'tags'       => array( 'Tags', 'Etiketter' ),
	) as $name => $titles ) {
		$block = 'content-trees/' . $name;
		$cases = array(
			'unset'         => array( array(), $titles[1], $titles[1] ),
			'blank'         => array( array( 'title' => '' ), null, $titles[1] ),
			'custom'        => array( array( 'title' => 'My collection' ), 'My collection', 'My collection' ),
			'saved English' => array( array( 'title' => $titles[0] ), $titles[0], $titles[0] ),
		);
		foreach ( $cases as $case => $settings ) {
			// Compact mode reads eligible memberships without preparing post presentation.
			$attributes = array_merge( $name === 'pages' ? array() : array( 'showPosts' => false ), $settings[0] );
			foreach ( array( 'frontend', 'REST preview' ) as $path ) {
				if ( $path === 'frontend' ) {
					$html = do_blocks( '<!-- wp:' . $block . ' ' . wp_json_encode( (object) $attributes ) . ' /-->' );
				} else {
					$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . $block );
					$request->set_param( 'context', 'edit' );
					$request->set_param( 'attributes', $attributes );
					$response = rest_do_request( $request );
					if ( $response->get_status() !== 200 ) {
						throw new RuntimeException( 'Block preview failed: ' . wp_json_encode( $response->get_data() ) ); }
					$html = $response->get_data()['rendered'];
				}
				$doc      = new DOMDocument();
				$previous = libxml_use_internal_errors( true );
				try {
					$doc->loadHTML( '<!doctype html><meta charset="utf-8">' . $html ); } finally {
					libxml_clear_errors();
					libxml_use_internal_errors( $previous ); }
					$xpath      = new DOMXPath( $doc );
					$headings   = $xpath->query( '//nav/h2' );
					$nav        = $xpath->query( '//nav' );
					$heading_ok = $settings[1] === null ? $headings->length === 0
					: $headings->length === 1 && $headings->item( 0 )->textContent === $settings[1];
					heading_check( $heading_ok && $nav->length === 1 && $nav->item( 0 )->getAttribute( 'aria-label' ) === $settings[2], "$name / $path / $case title and accessible label" );
			}
		}
		$registered = WP_Block_Type_Registry::get_instance()->get_registered( $block );
		heading_check( $registered && ! array_key_exists( 'default', $registered->attributes['title'] ), "$name title metadata has no English default." );
	}
} finally {
	remove_filter( 'gettext', $translate, 10 );
	wp_set_current_user( $old_user );
}
$after = array();
foreach ( $option_names as $name ) {
	$after[ $name ] = get_option( $name, '__missing_heading_option__' ); }
heading_check( $before === $after, 'Saved widgets, theme and locale options remain unchanged.' );
echo "$checks Pages/Category/Tag heading checks passed on $profile.\n";
