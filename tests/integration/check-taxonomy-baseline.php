<?php
/** Baseline-only checks; all created content is removed and existing options preserved. */
if ( PHP_SAPI !== 'cli' ) {
	exit( 2 ); }
$minimum = ( $argv[1] ?? '' ) === 'minimum';
define( 'WP_INSTALLING', false );
require __DIR__ . '/bootstrap.php';
if ( ! defined( 'CONTENT_TREES_DISPOSABLE_TEST_SITE' ) || ! CONTENT_TREES_DISPOSABLE_TEST_SITE
	|| DB_NAME !== ( $minimum ? 'content_trees_minimum' : 'content_trees_test' ) || DB_HOST !== CONTENT_TREES_TEST_DB_HOST ) {
	exit( 2 ); }
set_exception_handler(
	static function ( $error ) {
		fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
		exit( 1 );
	}
);
$checks         = 0;
$created_posts  = array();
$created_terms  = array();
$old_query      = $GLOBALS['wp_query'];
$old_post       = $GLOBALS['post'] ?? null;
$old_user       = get_current_user_id();
$option_names   = array(
	'widget_ctrees_archive',
	'widget_ctrees_pages',
	'widget_ctrees_categories',
	'widget_ctrees_tags',
	'widget_block',
	'sidebars_widgets',
	'stylesheet',
	'template',
	'permalink_structure',
	'active_plugins',
);
$options_before = array();
foreach ( $option_names as $name ) {
	$options_before[ $name ] = get_option( $name, '__missing_taxonomy_option__' ); }

function taxonomy_check( $condition, $message ) {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
	++$checks;
	echo "PASS: $message\n";
}
function taxonomy_fixture_term( $taxonomy, $name, $parent = 0 ) {
	global $created_terms;
	$term = wp_insert_term( $name . ' ' . wp_generate_password( 8, false ), $taxonomy, array( 'parent' => $parent ) );
	if ( is_wp_error( $term ) ) {
		throw new RuntimeException( $term->get_error_message() ); }
	$created_terms[] = array( $taxonomy, (int) $term['term_id'] );
	return (int) $term['term_id'];
}
function taxonomy_fixture_post( $name, $categories, $tags, $extra = array() ) {
	global $created_posts;
	$id = wp_insert_post(
		array_merge(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_content' => '',
				'post_date'    => '2020-01-01 12:00:00',
				'post_author'  => 1,
			),
			$extra
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() ); }
	$created_posts[] = (int) $id;
	wp_set_object_terms( $id, $categories, 'category' );
	wp_set_object_terms( $id, $tags, 'post_tag' );
	return (int) $id;
}
function taxonomy_dom( $html ) {
	$doc      = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	try {
		$doc->loadHTML( '<!doctype html><meta charset="utf-8">' . $html ); } finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous ); }
		return new DOMXPath( $doc );
}
function taxonomy_current_post( $id ) {
	$query                    = new WP_Query( array( 'p' => $id ) );
	$query->queried_object    = get_post( $id );
	$query->queried_object_id = $id;
	$query->is_singular       = true;
	$query->is_single         = true;
	$query->is_category       = $query->is_tag = false;
	$GLOBALS['wp_query']      = $query;
}
function taxonomy_current_term( $taxonomy, $id ) {
	$query                    = new WP_Query();
	$query->queried_object    = get_term( $id, $taxonomy );
	$query->queried_object_id = $id;
	$query->is_category       = $taxonomy === 'category';
	$query->is_tag            = $taxonomy === 'post_tag';
	$GLOBALS['wp_query']      = $query;
}

try {
	$a         = taxonomy_fixture_term( 'category', 'Taxonomy A' );
	$middle    = taxonomy_fixture_term( 'category', 'Taxonomy middle', $a );
	$b         = taxonomy_fixture_term( 'category', 'Taxonomy B', $middle );
	$empty     = taxonomy_fixture_term( 'category', 'Taxonomy empty' );
	$secret    = taxonomy_fixture_term( 'category', 'Taxonomy hidden-only' );
	$x         = taxonomy_fixture_term( 'post_tag', 'Taxonomy X' );
	$y         = taxonomy_fixture_term( 'post_tag', 'Taxonomy Y' );
	$tag_empty = taxonomy_fixture_term( 'post_tag', 'Taxonomy empty' );
	$p         = taxonomy_fixture_post( 'Taxonomy Public P & <em>literal</em>', array( $a, $b ), array( $x, $y ) );
	$q         = taxonomy_fixture_post( 'Taxonomy Public Q', array( $b ), array( $y ) );
	$private   = taxonomy_fixture_post( 'Taxonomy Secret Private', array( $secret ), array( $tag_empty ), array( 'post_status' => 'private' ) );
	$protected = taxonomy_fixture_post( 'Taxonomy Secret Password', array( $secret ), array( $tag_empty ), array( 'post_password' => 'test-only' ) );
	$draft     = taxonomy_fixture_post( 'Taxonomy Secret Draft', array( $secret ), array( $tag_empty ), array( 'post_status' => 'draft' ) );
	$page      = taxonomy_fixture_post( 'Taxonomy Secret Page', array(), array(), array( 'post_type' => 'page' ) );
	wp_set_object_terms( $page, array( $secret ), 'category' );
	$category = ContentTrees\taxonomy_data( 'category' );
	taxonomy_check( $category['parents'][ $b ] === $middle && $category['parents'][ $middle ] === $a, 'Category hierarchy includes empty direct parents with eligible descendants.' );
	taxonomy_check( $category['counts'][ $a ] === 2 && $category['counts'][ $middle ] === 2 && $category['counts'][ $b ] === 2, 'Category counts union parent/child memberships without double counting.' );
	taxonomy_check( array_keys( $category['memberships'][ $a ] ) === array( $p ) && array_keys( $category['memberships'][ $b ] ) === array( $p, $q ), 'Posts appear only under direct assignments; equal dates tie by post ID.' );
	taxonomy_check( ! isset( $category['terms'][ $empty ] ) && ! isset( $category['terms'][ $secret ] ), 'Empty and hidden-only categories are absent.' );
	$public_members = array_unique( array_merge( ...array_map( 'array_keys', $category['memberships'] ) ) );
	foreach ( array( $private, $protected, $draft, $page ) as $id ) {
		taxonomy_check( ! in_array( $id, $public_members, true ), 'Ineligible post/page is absent from taxonomy memberships: ' . $id ); }
	$tags = ContentTrees\taxonomy_data( 'post_tag' );
	taxonomy_check( $tags['parents'][ $x ] === 0 && $tags['parents'][ $y ] === 0 && $tags['counts'][ $x ] === 1 && $tags['counts'][ $y ] === 2, 'Tags stay flat and count only directly assigned public posts.' );
	taxonomy_check( ! isset( $tags['terms'][ $tag_empty ] ), 'A tag assigned only hidden posts is absent.' );
	taxonomy_check( ContentTrees\taxonomy_data( 'unregistered' )['terms'] === array(), 'Unsupported taxonomy input returns no data.' );

	$cycle_a         = clone get_term( $a, 'category' );
	$cycle_b         = clone get_term( $b, 'category' );
	$cycle_a->parent = $b;
	$cycle_b->parent = $a;
	$repaired        = ContentTrees\taxonomy_parents(
		'category',
		array(
			$a => array( 'term' => $cycle_a ),
			$b => array( 'term' => $cycle_b ),
		)
	);
	taxonomy_check( $repaired[ min( $a, $b ) ] === 0, 'Malformed cycles break deterministically without altering stored terms.' );
	wp_set_current_user( 1 );
	$admin_data = ContentTrees\taxonomy_data( 'category', array( 'show_posts' => false ) );
	taxonomy_check( ! isset( $admin_data['terms'][ $secret ] ) && $admin_data['counts'][ $a ] === 2, 'Logged-in administrators receive the same public-only counts.' );
	wp_set_current_user( 0 );

	$hide_middle = static function ( $terms, $taxonomies, $args ) use ( $middle ) {
		if ( ( $args['fields'] ?? '' ) !== 'all' ) {
			return $terms; }
		return array_values(
			array_filter(
				$terms,
				static function ( $term ) use ( $middle ) {
					return ! is_object( $term ) || $term->term_id !== $middle;
				}
			)
		);
	};
	add_filter( 'get_terms', $hide_middle, 10, 3 );
	try {
		$promoted = ContentTrees\taxonomy_data( 'category', array( 'show_posts' => false ) ); } finally {
		remove_filter( 'get_terms', $hide_middle, 10 ); }
		taxonomy_check( $promoted['parents'][ $b ] === $a && ! isset( $promoted['terms'][ $middle ] ), 'Filtered intermediate names stay absent while public children reach the nearest visible ancestor.' );

		taxonomy_current_post( $p );
		$html  = ContentTrees\render_taxonomy( 'category' );
		$xpath = taxonomy_dom( $html );
		taxonomy_check( $xpath->query( '//a[@data-post-id="' . $p . '"][@aria-current="page"]' )->length === 2, 'Every direct copy of the current post is highlighted.' );
		taxonomy_check( $xpath->query( '//details[@data-term-id="' . $a . '" or @data-term-id="' . $middle . '" or @data-term-id="' . $b . '"][@open]' )->length === 3, 'Current-post opening reveals all category assignment paths.' );
		taxonomy_check( $xpath->query( '//script|//em|//img' )->length === 0 && strpos( $html, 'Taxonomy Public P &amp; literal' ) !== false, 'Labels are escaped text with no executable or formatting injection.' );

		$title_calls     = 0;
		$permalink_calls = 0;
		$title_probe     = static function ( $title ) use ( &$title_calls ) {
			++$title_calls;
			return $title;
		};
	$link_probe          = static function ( $url ) use ( &$permalink_calls ) {
		++$permalink_calls;
		return $url;
	};
	add_filter( 'the_title', $title_probe );
	add_filter( 'post_link', $link_probe );
	try {
		$compact      = ContentTrees\taxonomy_data( 'category', array( 'show_posts' => false ) );
		$compact_html = ContentTrees\render_taxonomy( 'category', array( 'show_posts' => false ) );
		$compact_tags = ContentTrees\render_taxonomy( 'post_tag', array( 'show_posts' => false ) );
	} finally {
		remove_filter( 'the_title', $title_probe );
		remove_filter( 'post_link', $link_probe ); }
	taxonomy_check( $title_calls === 0 && $permalink_calls === 0 && $compact['posts'] === array(), 'Posts-off prepares zero post labels, permalinks or presentation nodes.' );
	taxonomy_check( $compact['counts'] === $category['counts'] && array_keys( $compact['terms'] ) === array_keys( $category['terms'] ), 'Posts-off preserves counts and visible terms.' );
	$compact_xpath = taxonomy_dom( $compact_html );
	taxonomy_check( $compact_xpath->query( '//ul[@class="ctrees-posts"]|//a[@data-post-id]' )->length === 0, 'Posts-off renders no post lists or links.' );
	taxonomy_check( $compact_xpath->query( '//details[@open]' )->length >= 2 && taxonomy_dom( $compact_tags )->query( '//details' )->length === 0, 'Posts-off retains current Category ancestry while flat Tags have no empty disclosures.' );

	foreach ( array(
		'category' => $a,
		'post_tag' => $x,
	) as $taxonomy => $id ) {
		taxonomy_current_term( $taxonomy, $id );
		$term_xpath = taxonomy_dom( ContentTrees\render_taxonomy( $taxonomy ) );
		taxonomy_check( $term_xpath->query( '//a[@aria-current="page"]' )->length === 1 && $term_xpath->query( '//details[@data-term-id="' . $id . '"][@open]' )->length === 1, 'Matching term archive marks its term and opens its own branch: ' . $taxonomy );
	}

	foreach ( array(
		'categories' => 'category',
		'tags'       => 'post_tag',
	) as $name => $taxonomy ) {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'content-trees/' . $name );
		taxonomy_check( $block && $block->attributes['showPosts']['default'] === true && $block->editor_script_handles === array( 'content-trees-taxonomy-editor' ), 'Focused block is registered with shared editor/defaults: ' . $name );
		$class  = $name === 'categories' ? 'ContentTrees\\Categories_Widget' : 'ContentTrees\\Tags_Widget';
		$widget = $GLOBALS['wp_widget_factory']->widgets[ $class ] ?? null;
		taxonomy_check( $widget instanceof WP_Widget && $widget->id_base === 'ctrees_' . $name, 'Classic widget is registered before widgets_init completes: ' . $name );
		$saved = $widget->update(
			array(
				'title'      => '',
				'show_posts' => '0',
				'root'       => 42,
			),
			array()
		);
		taxonomy_check( $saved['title'] === '' && $saved['show_posts'] === false && ! isset( $saved['open_to'], $saved['show_title'] ), 'Widget preserves baseline title/posts-off settings without developer-only fields: ' . $name );
		$saved = maybe_unserialize( maybe_serialize( $saved ) );
		ob_start();
		$widget->widget(
			array(
				'before_widget' => '<section>',
				'after_widget'  => '</section>',
				'before_title'  => '<h3>',
				'after_title'   => '</h3>',
			),
			$saved
		);
		$widget_html = ob_get_clean();
		taxonomy_check( strpos( $widget_html, '<h3>' ) === false && strpos( $widget_html, 'aria-label="' . ContentTrees\taxonomy_title( $taxonomy ) . '"' ) !== false && strpos( $widget_html, 'data-post-id=' ) === false, 'Saved compact widget retains a landmark with a blank heading: ' . $name );
		$shortcode = do_shortcode( '[content_trees_' . $name . ' title="" show_posts="0"]' );
		taxonomy_check( strpos( $shortcode, '<h2>' ) === false && strpos( $shortcode, 'data-post-id=' ) === false && strpos( $shortcode, 'ctrees-taxonomy-tree' ) !== false, 'Shortcode uses baseline boolean/title settings: ' . $name );
		wp_set_current_user( 1 );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/content-trees/' . $name );
		$request->set_param( 'context', 'edit' );
		$request->set_param(
			'attributes',
			array(
				'title'     => '',
				'showPosts' => false,
			)
		);
		$response = rest_do_request( $request );
		taxonomy_check( $response->get_status() === 200 && strpos( $response->get_data()['rendered'], 'ctrees-taxonomy-tree' ) !== false && strpos( $response->get_data()['rendered'], 'data-post-id=' ) === false, 'Real server-rendered block preview honors compact settings: ' . $name );
		wp_set_current_user( 0 );
	}

	wp_update_post(
		array(
			'ID'            => $p,
			'post_password' => 'now-protected',
		)
	);
	$changed = ContentTrees\taxonomy_data( 'category', array( 'show_posts' => false ) );
	taxonomy_check( $changed['counts'][ $a ] === 1 && ! isset( $changed['memberships'][ $a ] ), 'Visibility changes refresh membership/counts without a persistent taxonomy snapshot.' );
	wp_update_post(
		array(
			'ID'            => $p,
			'post_password' => '',
		)
	);
	wp_set_object_terms( $q, array( $a ), 'category' );
	$moved = ContentTrees\taxonomy_data( 'category', array( 'show_posts' => false ) );
	taxonomy_check( $moved['counts'][ $a ] === 2 && $moved['counts'][ $b ] === 1 && isset( $moved['memberships'][ $a ][ $q ] ), 'Reassignment updates placement even when the parent total is unchanged.' );
	wp_set_object_terms( $q, array( $b ), 'category' );

	// Complete-branch check larger than WP's usual result/search defaults.
	for ( $i = 0; $i < 76; ++$i ) {
		taxonomy_fixture_post( 'Taxonomy Complete ' . $i, array( $b ), array( $x ) ); }
	taxonomy_current_post( $p );
	$large = ContentTrees\taxonomy_data( 'category' );
	taxonomy_check( count( $large['memberships'][ $b ] ) === 78 && $large['counts'][ $a ] === 78, 'All 78 eligible posts in a branch are present without an arbitrary limit.' );
	$tag_large = ContentTrees\taxonomy_data( 'post_tag' );
	taxonomy_check( count( $tag_large['memberships'][ $x ] ) === 77, 'Tag branch also returns its complete eligible membership.' );
	$artifact = '<!doctype html><meta charset="utf-8"><main>'
		. ContentTrees\render_taxonomy( 'category', array( 'title' => 'Categories full' ) )
		. ContentTrees\render_taxonomy(
			'category',
			array(
				'title'      => 'Categories compact',
				'show_posts' => false,
			)
		)
		. ContentTrees\render_taxonomy( 'post_tag', array( 'title' => 'Tags full' ) )
		. ContentTrees\render_taxonomy(
			'post_tag',
			array(
				'title'      => 'Tags compact',
				'show_posts' => false,
			)
		)
		. ContentTrees\render( array( 'show_posts' => false ) ) . ContentTrees\render_pages() . '</main>';
	if ( ! $minimum ) {
		file_put_contents( dirname( __DIR__, 2 ) . '/tests/results/taxonomy-baseline-render.html', $artifact );
		file_put_contents(
			dirname( __DIR__, 2 ) . '/tests/results/taxonomy-baseline-ids.json',
			json_encode(
				array(
					'a' => $a,
					'b' => $b,
					'x' => $x,
					'p' => $p,
				)
			)
		);
	}
} finally {
	foreach ( $created_posts as $id ) {
		wp_delete_post( $id, true ); }
	foreach ( array_reverse( $created_terms ) as $term ) {
		wp_delete_term( $term[1], $term[0] ); }
	$GLOBALS['wp_query'] = $old_query;
	$GLOBALS['post']     = $old_post;
	wp_set_current_user( $old_user );
	foreach ( $options_before as $name => $value ) {
		if ( get_option( $name, '__missing_taxonomy_option__' ) !== $value ) {
			throw new RuntimeException( 'Existing fixture option changed: ' . $name ); }
	}
}
echo 'PASS: ' . $checks . ' taxonomy baseline checks on WordPress ' . $wp_version . ' / PHP ' . PHP_VERSION . "; existing options preserved, temporary content removed.\n";
