<?php
/** Focused review regressions; temporary content is removed in finally. */
if ( PHP_SAPI !== 'cli' ) {
	exit( 2 ); }
$profile = $argv[1] ?? 'current';
if ( ! in_array( $profile, array( 'current', 'minimum' ), true ) ) {
	exit( 2 ); }
define( 'WP_INSTALLING', false );
require __DIR__ . '/bootstrap.php';
if ( ! defined( 'CONTENT_TREES_DISPOSABLE_TEST_SITE' ) || ! CONTENT_TREES_DISPOSABLE_TEST_SITE
	|| DB_NAME !== ( $profile === 'minimum' ? 'content_trees_minimum' : 'content_trees_test' )
	|| DB_HOST !== '127.0.0.1:33080' ) {
	exit( 2 ); }
set_exception_handler(
	static function ( $error ) {
		fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
		exit( 1 );
	}
);
$checks = 0;
function review_check( $ok, $message ) {
	global $checks;
	if ( ! $ok ) {
		throw new RuntimeException( $message ); }
	++$checks;
	echo "PASS: $message\n";
}
function review_dom( $html ) {
	$doc      = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	try {
		$doc->loadHTML( '<!doctype html><meta charset="utf-8">' . $html ); } finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous ); }
		return new DOMXPath( $doc );
}
$source = require __DIR__ . '/source.php';
review_check( realpath( ( new ReflectionFunction( 'ContentTrees\\render' ) )->getFileName() ) === realpath( $source . '/archive.php' ), 'Archive rendering loads from archive.php.' );
review_check( realpath( ( new ReflectionFunction( 'ContentTrees\\post_node' ) )->getFileName() ) === realpath( $source . '/tree.php' ), 'Taxonomy and archive post helpers remain shared.' );
$editor = wp_scripts()->registered['content-trees-taxonomy-editor'];
review_check( $editor->textdomain === 'content-trees' && in_array( 'wp-i18n', $editor->deps, true ), 'The taxonomy editor registers its translation domain and dependency.' );
foreach ( array( 'categories', 'tags' ) as $provider ) {
	$block = WP_Block_Type_Registry::get_instance()->get_registered( 'content-trees/' . $provider );
	review_check( $block->editor_script_handles === array( 'content-trees-taxonomy-editor' ), "$provider uses the translated editor handle." );
}
$old_query = $GLOBALS['wp_query'];
$old_user  = get_current_user_id();
$ids       = array();
$options   = array( 'widget_ctrees_archive', 'widget_ctrees_pages', 'widget_ctrees_categories', 'widget_ctrees_tags', 'widget_block', 'sidebars_widgets', 'stylesheet', 'template', 'WPLANG' );
$before    = array();
foreach ( $options as $name ) {
	$before[ $name ] = get_option( $name, '__missing_review_option__' ); }
$baseline = ContentTrees\outline();
register_post_type( 'ctrees_review', array( 'public' => false ) );
try {
	wp_set_current_user( 1 );
	$insert = static function ( $type ) use ( &$ids ) {
		$id = wp_insert_post(
			array(
				'post_type'     => $type,
				'post_status'   => 'publish',
				'post_title'    => 'Review cache fixture',
				'post_date'     => '1993-02-03 12:00:00',
				'post_date_gmt' => '1993-02-03 12:00:00',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() ); }
		$ids[] = $id;
		return $id;
	};
	foreach ( array( 'page', 'ctrees_review' ) as $type ) {
		$id = $insert( $type );
		review_check( get_transient( 'ctrees_month_index_v1' ) === $baseline, "$type creation preserves the warmed archive index." );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Updated review fixture',
			)
		);
		review_check( get_transient( 'ctrees_month_index_v1' ) === $baseline, "$type updates preserve the warmed archive index." );
		wp_delete_post( $id, true );
		review_check( get_transient( 'ctrees_month_index_v1' ) === $baseline, "$type deletion preserves the warmed archive index." );
	}
	$post = $insert( 'post' );
	review_check( get_transient( 'ctrees_month_index_v1' ) === false, 'Post creation invalidates the warmed archive index.' );
	$with_post = ContentTrees\outline();
	review_check( in_array( $post, array_column( ContentTrees\month_posts( 1993, 2 )['items'], 'id' ), true ) && $with_post !== $baseline, 'The rebuilt index and month endpoint include the new post.' );
	wp_update_post(
		array(
			'ID'        => $post,
			'post_type' => 'page',
		)
	);
	review_check( get_transient( 'ctrees_month_index_v1' ) === false && ContentTrees\outline() === $baseline, 'Converting a post to a page removes it from the warmed archive index.' );
	wp_update_post(
		array(
			'ID'        => $post,
			'post_type' => 'post',
		)
	);
	review_check( get_transient( 'ctrees_month_index_v1' ) === false && ContentTrees\outline() === $with_post, 'Converting a page to a post refreshes the warmed archive index.' );
	set_post_type( $post, 'page' );
	review_check( get_transient( 'ctrees_month_index_v1' ) === false && ContentTrees\outline() === $baseline, 'Core set_post_type also invalidates a post-to-page conversion.' );
	set_post_type( $post, 'post' );
	review_check( get_transient( 'ctrees_month_index_v1' ) === false && ContentTrees\outline() === $with_post, 'Core set_post_type also invalidates a page-to-post conversion.' );
	wp_delete_post( $post, true );
	review_check( get_transient( 'ctrees_month_index_v1' ) === false && ContentTrees\outline() === $baseline, 'Post deletion refreshes the warmed archive index.' );

	$GLOBALS['wp_query'] = new WP_Query();
	$sample              = '';
	foreach ( array( true, false ) as $grouped ) {
		$args     = array(
			'title'              => $grouped ? 'Grouped fallback' : 'Flat fallback',
			'show_archive_links' => false,
			'group_years'        => $grouped,
		);
		$html     = ContentTrees\render( $args );
		$xpath    = review_dom( $html );
		$branches = $xpath->query( '//details[@class="ctrees-month"]' );
		review_check(
			$branches->length > 0 && $xpath->query( '//details[@class="ctrees-month"]/noscript/p/a' )->length === $branches->length,
			( $grouped ? 'Grouped' : 'Flat' ) . ' collapsed months have no-JavaScript archive links.'
		);
		review_check( $xpath->query( '//a[not(ancestor::noscript)]' )->length === 0, 'Hidden archive links stay hidden in the enhanced interface.' );
		review_check( strpos( $html, 'ctrees-posts' ) === false, 'The fallback keeps collapsed month post loading lazy.' );
		$sample .= $html;

		$default = review_dom( ContentTrees\render( array( 'group_years' => $grouped ) ) );
		review_check( $default->query( '//noscript' )->length === 0 && $default->query( '//details[@class="ctrees-month"]/summary/a' )->length > 0, 'Enabled archive links need no duplicate fallback.' );
		$compact = review_dom(
			ContentTrees\render(
				array(
					'group_years'        => $grouped,
					'show_posts'         => false,
					'show_archive_links' => false,
				)
			)
		);
		review_check( $compact->query( '//details[@class="ctrees-month"]|//noscript' )->length === 0 && $compact->query( '//p[@class="ctrees-month-link"]/a' )->length > 0, 'Compact mode keeps its ordinary archive links.' );
	}
	$sample_path = dirname( __DIR__, 2 ) . '/tests/results/review-fixes-' . $profile . '.html';
	if ( file_put_contents( $sample_path, $sample ) === false ) {
		throw new RuntimeException( 'Cannot save no-JavaScript browser sample.' ); }
	$month   = $baseline[0];
	$items   = ContentTrees\month_posts( $month['year'], $month['month'] )['items'];
	$current = (int) $items[0]['id'];
	$open    = review_dom(
		ContentTrees\render(
			array(
				'show_archive_links' => false,
				'open_to'            => $current,
			)
		)
	);
	review_check(
		$open->query( '//details[@class="ctrees-month" and @open]/noscript' )->length === 0
		&& $open->query( '//details[@class="ctrees-month" and @open]/ul/li/a' )->length === count( $items ),
		'Initially open months keep complete server-rendered posts without a redundant fallback.'
	);
	$filtered = review_dom(
		ContentTrees\render(
			array(
				'show_archive_links' => false,
				'excluded_posts'     => array( $current ),
			)
		)
	);
	review_check( $filtered->query( '//noscript//a[starts-with(@aria-label,"Browse the full archive for ")]' )->length > 0, 'Filtered fallbacks describe the full WordPress archive accurately.' );
	$hostile_url = static function () {
		return 'javascript:alert(1)';
	};
	add_filter( 'month_link', $hostile_url );
	try {
		$safe = review_dom( ContentTrees\render( array( 'show_archive_links' => false ) ) );
		review_check( $safe->query( '//noscript//a[starts-with(@href,"javascript:")]' )->length === 0, 'Fallback links reject unsafe filtered URLs.' );
	} finally {
		remove_filter( 'month_link', $hostile_url ); }
} finally {
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true ); }
	unregister_post_type( 'ctrees_review' );
	$GLOBALS['wp_query'] = $old_query;
	wp_set_current_user( $old_user );
}
$after = array();
foreach ( $options as $name ) {
	$after[ $name ] = get_option( $name, '__missing_review_option__' ); }
review_check( $before === $after && ContentTrees\outline() === $baseline, 'Saved widgets, theme, locale and baseline archive content remain unchanged.' );
echo "$checks review checks passed on $profile.\n";
