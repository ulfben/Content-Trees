<?php
/** Lazy taxonomy, public route and ID-only outline regressions on either runtime. */
require __DIR__ . '/check-taxonomy-features.php';
$created_posts = $created_terms = array();
$checks        = 0;
try {
	$root      = taxonomy_fixture_term( 'category', 'Loading root' );
	$child     = taxonomy_fixture_term( 'category', 'Loading child', $root );
	$sibling   = taxonomy_fixture_term( 'category', 'Loading sibling', $root );
	$empty     = taxonomy_fixture_term( 'category', 'Loading empty', $root );
	$tag       = taxonomy_fixture_term( 'post_tag', 'Loading tag' );
	$other_tag = taxonomy_fixture_term( 'post_tag', 'Loading other' );
	$sort_tag  = taxonomy_fixture_term( 'post_tag', 'Loading sort' );
	$p         = taxonomy_fixture_post( 'Loading Zulu', array( $root, $child ), array( $tag, $sort_tag ), array( 'post_date' => '2020-01-03 12:00:00' ) );
	$q         = taxonomy_fixture_post( 'Loading Alpha', array( $child ), array( $tag, $sort_tag ), array( 'post_date' => '2020-01-01 12:00:00' ) );
	$z         = taxonomy_fixture_post( 'Loading Middle', array( $root ), array( $other_tag, $sort_tag ), array( 'post_date' => '2020-01-02 12:00:00' ) );
	$s         = taxonomy_fixture_post( 'Loading unopened sibling', array( $sibling ), array( $other_tag ) );
	$eligible  = array( $p, $q );
	for ( $index = 0; $index < 75; ++$index ) {
		$eligible[] = taxonomy_fixture_post( 'Loading complete ' . $index, array( $child ), array( $tag ) ); }
	$private   = taxonomy_fixture_post( 'Loading private', array( $child ), array( $tag ), array( 'post_status' => 'private' ) );
	$protected = taxonomy_fixture_post( 'Loading protected', array( $child ), array( $tag ), array( 'post_password' => 'temporary' ) );
	$draft     = taxonomy_fixture_post( 'Loading draft', array( $child ), array( $tag ), array( 'post_status' => 'draft' ) );
	$page      = taxonomy_fixture_post( 'Loading page', array(), array(), array( 'post_type' => 'page' ) );
	wp_set_object_terms( $page, array( $child ), 'category' );
	wp_set_object_terms( $page, array( $tag ), 'post_tag' );
	$GLOBALS['wp_query'] = new WP_Query();
	$queries             = array();
	$query_probe         = static function ( $query ) use ( &$queries ) {
		if ( $query->get( 'tax_query' ) ) {
			$queries[] = $query->get( 'fields' ); }
	};
	$title_calls         = 0;
	$title_probe         = static function ( $title ) use ( &$title_calls ) {
		++$title_calls;
		return $title;
	};
	add_action( 'pre_get_posts', $query_probe );
	add_filter( 'the_title', $title_probe );
	try {
		$html = ContentTrees\render_taxonomy(
			'category',
			array(
				'root'      => $root,
				'auto_open' => false,
			)
		); } finally {
		remove_action( 'pre_get_posts', $query_probe );
		remove_filter( 'the_title', $title_probe ); }
		$xpath = taxonomy_dom( $html );
		taxonomy_check( $queries === array( 'ids' ) && $title_calls === 0 && strpos( $html, 'data-post-id=' ) === false, 'Closed taxonomy outlines allocate IDs and prepare no post titles.' );
		taxonomy_check( $xpath->query( '//a[@class="ctrees-term-link"]' )->length === 3 && $xpath->query( '//details[@data-has-posts="1"]' )->length === 3, 'The complete nonempty hierarchy and linked branch names are server-rendered.' );
		$data = ContentTrees\taxonomy_data( 'category', array( 'root' => $root ) );
		taxonomy_check( $data['counts'][ $root ] === 79 && $data['counts'][ $child ] === 77 && $data['posts'] === array(), 'Lightweight counts deduplicate shared parent/child assignments without presentation objects.' );

		$queries = array();
		add_action( 'pre_get_posts', $query_probe );
		try {
			$fast = ContentTrees\render_taxonomy(
				'category',
				array(
					'root'        => $root,
					'show_posts'  => false,
					'show_counts' => false,
					'hide_empty'  => false,
					'auto_open'   => false,
				)
			); } finally {
			remove_action( 'pre_get_posts', $query_probe ); }
			taxonomy_check( $queries === array() && taxonomy_dom( $fast )->query( '//a[@class="ctrees-term-link"]' )->length === 4, 'Compact name-only outlines skip all post queries while retaining empty terms.' );
			taxonomy_current_post( $p );
			$queries = array();
			add_action( 'pre_get_posts', $query_probe );
			try {
				$fast_open = ContentTrees\render_taxonomy(
					'category',
					array(
						'root'        => $root,
						'show_posts'  => false,
						'show_counts' => false,
						'hide_empty'  => false,
					)
				); } finally {
					remove_action( 'pre_get_posts', $query_probe ); }
				taxonomy_check( $queries === array() && taxonomy_dom( $fast_open )->query( '//details[@data-term-id="' . $root . '" and @open]' )->length === 1, 'Compact auto-opening needs only the current post memberships, not a full post query.' );
				$open       = ContentTrees\render_taxonomy( 'category', array( 'root' => $root ) );
				$open_xpath = taxonomy_dom( $open );
				taxonomy_check(
					$open_xpath->query( '//a[@data-post-id="' . $p . '"][@aria-current="page"]' )->length === 2
					&& $open_xpath->query( '//details[@open and @data-loaded="1"]' )->length === 2,
					'Initially open branches include their complete post lists and current highlights on the server.'
				);
	taxonomy_check( strpos( $open, 'Loading unopened sibling' ) === false && strpos( $open, 'data-term-id="' . $sibling . '"' ) !== false, 'Closed sibling terms stay in the hierarchy without preparing their post lists.' );
	$explicit = taxonomy_dom(
		ContentTrees\render_taxonomy(
			'category',
			array(
				'root'      => $root,
				'open_to'   => $sibling,
				'auto_open' => false,
			)
		)
	);
	taxonomy_check( $explicit->query( '//details[@data-term-id="' . $sibling . '" and @open]//a[@data-post-id="' . $s . '"]' )->length === 1, 'Explicit opening server-renders the selected direct list.' );
	$GLOBALS['wp_query'] = new WP_Query();

	foreach ( array(
		'category' => $child,
		'post_tag' => $tag,
	) as $taxonomy => $term_id ) {
		foreach ( array( 0, 1 ) as $user ) {
			wp_set_current_user( $user );
			$request = new WP_REST_Request( 'GET', '/content-trees/v1/terms/' . $taxonomy . '/' . $term_id . '/posts' );
			$request->set_param( 'post_status', 'private' );
			$request->set_param( 'post_type', 'page' );
			$request->set_param( 'per_page', 1 );
			$response = rest_do_request( $request );
			$items    = $response->get_data()['items'];
			$ids      = array_column( $items, 'id' );
			$expected = $eligible;
			sort( $ids );
			sort( $expected );
			taxonomy_check( $response->get_status() === 200 && $ids === $expected, "$taxonomy complete public list is identical for user $user; caller cannot widen visibility or truncate it." );
			taxonomy_check( array_keys( $items[0] ) === array( 'id', 'label', 'url' ), "$taxonomy response contains only public post link data." );
			$request->set_param( 'excluded_posts', "$p,$q" );
			$filtered = rest_do_request( $request )->get_data()['items'];
			taxonomy_check( count( $filtered ) === 75 && ! array_intersect( array( $p, $q ), array_column( $filtered, 'id' ) ), "$taxonomy excludes every requested post without pagination." );
			foreach ( array(
				'sort_by'        => 'sql',
				'order'          => 'random',
				'excluded_posts' => array( $p ),
			) as $parameter => $invalid ) {
				$bad = clone $request;
				$bad->set_param( $parameter, $invalid );
				taxonomy_check( rest_do_request( $bad )->get_status() === 400, "$taxonomy rejects malformed $parameter." );
			}
		}
	}
	wp_set_current_user( 0 );
	$direct = rest_do_request( new WP_REST_Request( 'GET', '/content-trees/v1/terms/category/' . $root . '/posts' ) );
	taxonomy_check( count( $direct->get_data()['items'] ) === 2, 'Parent post lists contain direct assignments, not descendant posts.' );
	$empty_response = rest_do_request( new WP_REST_Request( 'GET', '/content-trees/v1/terms/category/' . $empty . '/posts' ) );
	taxonomy_check( $empty_response->get_status() === 200 && $empty_response->get_data() === array( 'items' => array() ), 'An emptied term returns a successful empty list.' );
	foreach ( array( '/category/999999999/posts', '/post_tag/' . $root . '/posts', '/custom/' . $child . '/posts' ) as $path ) {
		taxonomy_check( rest_do_request( new WP_REST_Request( 'GET', '/content-trees/v1/terms' . $path ) )->get_status() === 404, 'Unavailable or wrong-taxonomy term requests are rejected.' );
	}
	foreach ( array( 'title', 'date' ) as $sort ) {
		$request = new WP_REST_Request( 'GET', '/content-trees/v1/terms/post_tag/' . $sort_tag . '/posts' );
		$request->set_param( 'sort_by', $sort );
		$request->set_param( 'order', 'ASC' );
		taxonomy_check( array_column( rest_do_request( $request )->get_data()['items'], 'id' ) === array( $q, $z, $p ), 'Public loader preserves ASC ' . $sort . ' order.' );
		$request->set_param( 'order', 'DESC' );
		taxonomy_check( array_column( rest_do_request( $request )->get_data()['items'], 'id' ) === array( $p, $z, $q ), 'Public loader preserves DESC ' . $sort . ' order.' );
	}
	$inject = static function ( $posts, $query ) use ( $p, $private, $protected, $page ) {
		return $query->get( 'fields' ) === 'ids' && $query->get( 'tax_query' ) ? array( $p, $private, $protected, $page ) : $posts;
	};
	add_filter( 'posts_pre_query', $inject, 10, 2 );
	try {
		$guarded = ContentTrees\taxonomy_data( 'category', array( 'root' => $root ) ); } finally {
		remove_filter( 'posts_pre_query', $inject, 10 ); }
		taxonomy_check( $guarded['counts'][ $root ] === 1 && $guarded['counts'][ $child ] === 1, 'Lean membership reads independently enforce public eligibility even if post-query filters return hidden IDs.' );
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
echo "PASS: $checks taxonomy lazy-loading/REST checks; all temporary content removed.\n";
