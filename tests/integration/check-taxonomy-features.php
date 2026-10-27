<?php
/** Includes the existing self-cleaning baseline and reuses its guarded test helpers. */
require __DIR__ . '/check-taxonomy-baseline.php';
$created_posts = $created_terms = array();
$checks        = 0;
try {
	$a       = taxonomy_fixture_term( 'category', 'Features Alpha' );
	$hidden  = taxonomy_fixture_term( 'category', 'Features middle', $a );
	$b       = taxonomy_fixture_term( 'category', 'Features Beta', $hidden );
	$c       = taxonomy_fixture_term( 'category', 'Features Charlie', $a );
	$empty   = taxonomy_fixture_term( 'category', 'Features empty', $a );
	$outside = taxonomy_fixture_term( 'category', 'Features outside' );
	$x       = taxonomy_fixture_term( 'post_tag', 'Features X' );
	$y       = taxonomy_fixture_term( 'post_tag', 'Features Y' );
	$p       = taxonomy_fixture_post( 'Features Zulu', array( $a, $b, $outside ), array( $x, $y ), array( 'post_date' => '2020-01-03 12:00:00' ) );
	$q       = taxonomy_fixture_post( 'Features Alpha', array( $b ), array( $y ), array( 'post_date' => '2020-01-01 12:00:00' ) );
	$r       = taxonomy_fixture_post( 'Features Middle', array( $c ), array( $x ), array( 'post_date' => '2020-01-02 12:00:00' ) );
	$get     = static function ( $args = array() ) use ( $a ) {
		return ContentTrees\taxonomy_data( 'category', array_merge( array( 'root' => $a ), $args ) );
	};
	$base    = $get();
	taxonomy_check(
		array_keys( $base['terms'] ) === array_values(
			array_filter(
				array_keys( $base['terms'] ),
				static function ( $id ) use ( $outside ) {
					return $id !== $outside;
				}
			)
		) && $base['parents'][ $a ] === 0,
		'Visible fixed root is included and excludes unrelated branches.'
	);
	taxonomy_check( $base['counts'][ $a ] === 3 && $base['counts'][ $b ] === 2, 'Scoped category subtree counts union all eligible assignments.' );
	$excluded = $get( array( 'excluded_terms' => array( $b ) ) );
	taxonomy_check( ! isset( $excluded['terms'][ $b ] ) && $excluded['counts'][ $a ] === 2 && isset( $excluded['memberships'][ $a ][ $p ] ), 'Category subtree exclusion removes child-only posts but retains surviving direct assignments.' );
	$post_excluded = $get( array( 'excluded_posts' => array( $p ) ) );
	taxonomy_check( $post_excluded['counts'][ $a ] === 2 && $post_excluded['counts'][ $b ] === 1 && ! isset( $post_excluded['memberships'][ $a ][ $p ], $post_excluded['memberships'][ $b ][ $p ] ), 'Post exclusion removes all copies and updates every ancestor count.' );
	foreach ( array( array( 'root' => $x ), array( 'root' => 99999999 ), array( 'root' => array( 'bad' ) ), array( 'excluded_terms' => array( $a ) ), array( 'root' => $empty ) ) as $args ) {
		taxonomy_check( $get( $args )['terms'] === array(), 'Invalid, wrong-taxonomy, excluded or empty fixed roots do not widen scope.' );
	}
	$empty_shown = $get( array( 'hide_empty' => false ) );
	taxonomy_check( isset( $empty_shown['terms'][ $empty ] ) && $empty_shown['counts'][ $empty ] === 0, 'Hide-empty off includes zero-count terms.' );
	$only_empty = $get(
		array(
			'root'       => $empty,
			'hide_empty' => false,
		)
	);
	taxonomy_check( count( $only_empty['terms'] ) === 1 && $only_empty['parents'][ $empty ] === 0, 'An empty root is visible when empty terms are allowed.' );
	$empty_html = ContentTrees\render_taxonomy(
		'category',
		array(
			'root'       => $empty,
			'hide_empty' => false,
		)
	);
	taxonomy_check( taxonomy_dom( $empty_html )->query( '//details' )->length === 0, 'Empty terms never create an empty disclosure.' );
	$tag_excluded = ContentTrees\taxonomy_data(
		'post_tag',
		array(
			'excluded_terms' => array( $x ),
			'excluded_posts' => array( $q ),
		)
	);
	taxonomy_check( ! isset( $tag_excluded['terms'][ $x ] ) && $tag_excluded['counts'][ $y ] === 1 && isset( $tag_excluded['memberships'][ $y ][ $p ] ), 'Tag exclusion is exact and a multi-tag post survives another excluded term.' );

	$filter = static function ( $terms, $taxonomies, $args ) use ( $hidden ) {
		return ( $args['fields'] ?? '' ) === 'all' ? array_values(
			array_filter(
				$terms,
				static function ( $term ) use ( $hidden ) {
					return $term->term_id !== $hidden;
				}
			)
		) : $terms;
	};
	add_filter( 'get_terms', $filter, 10, 3 );
	try {
		$promoted = $get();
		$removed  = $get( array( 'excluded_terms' => array( $hidden ) ) );
	} finally {
		remove_filter( 'get_terms', $filter, 10 ); }
	taxonomy_check( $promoted['parents'][ $b ] === $a, 'A filtered intermediate parent is bypassed inside scope.' );
	taxonomy_check( ! isset( $removed['terms'][ $b ] ) && $removed['counts'][ $a ] === 2, 'Exclusion follows original ancestry before promotion through a filtered parent.' );
	foreach ( array( 'ASC', 'DESC' ) as $direction ) {
		$sorted   = $get(
			array(
				'sort_by'    => 'count',
				'order'      => $direction,
				'hide_empty' => false,
			)
		);
		$siblings = $sorted['children'][ $a ];
		$counts   = array_map(
			static function ( $id ) use ( $sorted ) {
				return $sorted['counts'][ $id ];
			},
			$siblings
		);
		$expected = $counts;
		if ( $direction === 'ASC' ) {
			sort( $expected );
		} else {
			rsort( $expected ); }
		taxonomy_check( $counts === $expected, 'Count ordering uses filtered subtree totals: ' . $direction );
		$names    = $get(
			array(
				'sort_by'    => 'name',
				'order'      => $direction,
				'hide_empty' => false,
			)
		);
		$labels   = array_map(
			static function ( $id ) use ( $names ) {
				return $names['terms'][ $id ]['label'];
			},
			$names['children'][ $a ]
		);
		$expected = $labels;
		if ( $direction === 'ASC' ) {
			sort( $expected, SORT_NATURAL | SORT_FLAG_CASE );
		} else {
			rsort( $expected, SORT_NATURAL | SORT_FLAG_CASE ); }
		taxonomy_check( $labels === $expected, 'Name ordering changes siblings, never hierarchy: ' . $direction );
	}
	foreach ( array(
		'title' => array( $q, $p ),
		'date'  => array( $q, $p ),
	) as $sort => $expected ) {
		taxonomy_check(
			array_column(
				ContentTrees\taxonomy_term_posts(
					'category',
					$b,
					array(
						'post_sort_by' => $sort,
						'post_order'   => 'ASC',
					)
				)['items'],
				'id'
			) === $expected,
			'Post sorting ASC: ' . $sort
		);
		taxonomy_check(
			array_column(
				ContentTrees\taxonomy_term_posts(
					'category',
					$b,
					array(
						'post_sort_by' => $sort,
						'post_order'   => 'DESC',
					)
				)['items'],
				'id'
			) === array_reverse( $expected ),
			'Post sorting DESC: ' . $sort
		);
	}
	global $wpdb;
	$wpdb->update( $wpdb->posts, array( 'post_modified' => '2021-01-01 00:00:00' ), array( 'ID' => $p ) );
	$wpdb->update( $wpdb->posts, array( 'post_modified' => '2022-01-01 00:00:00' ), array( 'ID' => $q ) );
	clean_post_cache( $p );
	clean_post_cache( $q );
	taxonomy_check(
		array_column(
			ContentTrees\taxonomy_term_posts(
				'category',
				$b,
				array(
					'post_sort_by' => 'modified',
					'post_order'   => 'DESC',
				)
			)['items'],
			'id'
		) === array(
			$q,
			$p,
		),
		'Last-updated sorting uses modification dates.'
	);

	taxonomy_current_post( $p );
	$render = static function ( $args = array() ) use ( $a ) {
		return taxonomy_dom( ContentTrees\render_taxonomy( 'category', array_merge( array( 'root' => $a ), $args ) ) );
	};
	taxonomy_check( $render( array( 'auto_open' => false ) )->query( '//details[@open]|//a[@data-post-id]' )->length === 0 && $render( array( 'auto_open' => false ) )->query( '//nav[@data-current-id="' . $p . '"]' )->length === 1, 'Disabling auto-open keeps lists unloaded and retains the current post for loaded-link highlighting.' );
	$explicit = $render(
		array(
			'open_to'   => $c,
			'auto_open' => false,
		)
	);
	taxonomy_check( $explicit->query( '//details[@data-term-id="' . $c . '"][@open]' )->length === 1 && $explicit->query( '//details[@data-term-id="' . $b . '"][@open]' )->length === 0, 'Valid explicit term opening takes precedence even with auto-open off.' );
	taxonomy_check( $render( array( 'open_to' => $outside ) )->query( '//details[@data-term-id="' . $b . '"][@open]' )->length === 1, 'Out-of-scope explicit term falls back to the automatic current path.' );
	taxonomy_check(
		$render(
			array(
				'open_to'        => $b,
				'excluded_terms' => array( $b ),
			)
		)->query( '//details[@data-term-id="' . $a . '"][@open]' )->length === 1,
		'Excluded explicit term falls back to remaining current memberships.'
	);
	taxonomy_check( $render( array( 'show_counts' => false ) )->query( '//span[@class="ctrees-count"]' )->length === 0, 'Count visibility does not alter membership or branch shape.' );
	taxonomy_check( $get( array( 'show_posts' => false ) )['counts'] === $base['counts'], 'Post order and presentation do not change compact counts.' );
	$normalized = ContentTrees\taxonomy_options(
		'category',
		array(
			'sort_by'        => 'SQL',
			'post_sort_by'   => array( 'date' ),
			'root'           => 'bad',
			'excluded_terms' => array( '2', -1, array( 3 ) ),
			'auto_open'      => '0',
		)
	);
	taxonomy_check( $normalized['sort_by'] === 'name' && $normalized['post_sort_by'] === 'date' && $normalized['root'] === -1 && $normalized['excluded_terms'] === array( 2 ) && ! $normalized['auto_open'], 'Malformed options normalize safely without widening a saved root.' );
	taxonomy_check( ContentTrees\taxonomy_options( 'post_tag', array( 'root' => $a ) )['root'] === 0, 'Tag settings have no root mode.' );
	$shortcode = do_shortcode( '[content_trees_categories root="' . $a . '" excluded_terms="' . $b . '" excluded_posts="' . $p . '" show_counts="0" auto_open="0"]' );
	taxonomy_check( strpos( $shortcode, 'data-post-id="' . $p . '"' ) === false && strpos( $shortcode, 'ctrees-count' ) === false && strpos( $shortcode, ' open' ) === false, 'Shortcode adapters retain the full normalized settings.' );
	$with_controls = ContentTrees\render_taxonomy(
		'category',
		array(
			'root'           => $a,
			'show_controls'  => true,
			'close_siblings' => true,
			'auto_open'      => false,
		)
	);
	taxonomy_check( taxonomy_dom( $with_controls )->query( '//div[@class="ctrees-controls"][@hidden]' )->length === 1, 'Useful bulk controls start hidden for progressive enhancement.' );
	taxonomy_check(
		taxonomy_dom(
			ContentTrees\render_taxonomy(
				'post_tag',
				array(
					'show_posts'    => false,
					'show_controls' => true,
				)
			)
		)->query( '//div[@class="ctrees-controls"]' )->length === 0,
		'Compact flat Tags omits useless bulk controls.'
	);
	taxonomy_check( taxonomy_dom( $with_controls )->query( '//*[@role="status"]' )->length === 0, 'Server output has no spurious loading announcements.' );
	foreach ( array(
		'categories' => 'category',
		'tags'       => 'post_tag',
	) as $name => $taxonomy ) {
		$class  = $taxonomy === 'category' ? 'ContentTrees\\Categories_Widget' : 'ContentTrees\\Tags_Widget';
		$widget = $GLOBALS['wp_widget_factory']->widgets[ $class ];
		$saved  = $widget->update(
			array(
				'title'          => 'Features widget',
				'show_posts'     => 1,
				'root'           => $a,
				'sort_by'        => 'count',
				'order'          => 'DESC',
				'post_sort_by'   => 'title',
				'post_order'     => 'ASC',
				'show_counts'    => 1,
				'hide_empty'     => 1,
				'auto_open'      => 1,
				'show_controls'  => 1,
				'close_siblings' => 1,
				'excluded_terms' => (string) $b,
				'excluded_posts' => (string) $p,
			),
			array()
		);
		taxonomy_check( $saved['excluded_terms'] === array( $b ) && $saved['excluded_posts'] === array( $p ) && $saved['sort_by'] === 'count' && $saved['post_sort_by'] === 'title' && $saved['show_controls'] && $saved['close_siblings'], 'Classic widget saves all approved settings: ' . $name );
		taxonomy_check( $taxonomy === 'category' ? $saved['root'] === $a : ! isset( $saved['root'] ), 'Only Categories persist root selection: ' . $name );
		ob_start();
		$widget->form( $saved );
		$form       = ob_get_clean();
		$form_xpath = taxonomy_dom( $form );
		taxonomy_check( $form_xpath->query( '//details/summary[text()="Advanced"]' )->length === 1 && $form_xpath->query( '//div[contains(@class,"ctrees-exclusions")]' )->length === ( $taxonomy === 'category' ? 3 : 2 ), 'Classic widget has one Advanced group and the appropriate selectors: ' . $name );
		$attributes = array(
			'title'         => 'Features block',
			'root'          => $a,
			'showPosts'     => false,
			'sortBy'        => 'count',
			'order'         => 'DESC',
			'postSortBy'    => 'modified',
			'postOrder'     => 'ASC',
			'showCounts'    => false,
			'hideEmpty'     => false,
			'autoOpen'      => false,
			'showControls'  => true,
			'closeSiblings' => true,
			'exclude'       => array( $b ),
			'excludePosts'  => array( $p ),
		);
		$adapted    = ContentTrees\taxonomy_block_options( $taxonomy, $attributes );
		taxonomy_check( ! $adapted['show_posts'] && ! $adapted['hide_empty'] && ! $adapted['show_counts'] && ! $adapted['auto_open'] && $adapted['excluded_posts'] === array( $p ) && $adapted['close_siblings'], 'Block adapter retains all settings and false values: ' . $name );
		$route = '/content-trees/v1/' . ( $taxonomy === 'category' ? 'category-options' : 'tag-options' );
		wp_set_current_user( 0 );
		taxonomy_check( rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status() === 401, 'Anonymous term-selector reads are denied: ' . $name );
		wp_set_current_user( 1 );
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'include', $taxonomy === 'category' ? "$a,$empty,$x" : "$x,$y,$a" );
		$response = rest_do_request( $request );
		$returned = array_column( $response->get_data()['items'], 'id' );
		taxonomy_check( $response->get_status() === 200 && in_array( $taxonomy === 'category' ? $empty : $y, $returned, true ) && ! in_array( $taxonomy === 'category' ? $x : $a, $returned, true ), 'Selector includes empty public terms but rejects another taxonomy: ' . $name );
		taxonomy_check( array_keys( $response->get_data()['items'][0] ) === array( 'id', 'label', 'url' ), 'Selector returns no post contents or membership counts: ' . $name );
		$request->set_param( 'include', array( '1' ) );
		taxonomy_check( rest_do_request( $request )->get_status() === 400, 'Selector rejects malformed includes: ' . $name );
		wp_set_current_user( 0 );
	}
	if ( ! $minimum ) {
		$excluded_tags = array_values(
			array_diff(
				get_terms(
					array(
						'taxonomy'   => 'post_tag',
						'fields'     => 'ids',
						'hide_empty' => false,
					)
				),
				array( $x, $y )
			)
		);
		$artifact      = '<!doctype html><meta charset="utf-8"><main>' . $with_controls
			. ContentTrees\render_taxonomy(
				'category',
				array(
					'root'          => $a,
					'show_controls' => true,
					'auto_open'     => false,
				)
			)
			. ContentTrees\render_taxonomy(
				'post_tag',
				array(
					'excluded_terms' => $excluded_tags,
					'show_controls'  => true,
					'close_siblings' => true,
					'auto_open'      => false,
				)
			)
			. ContentTrees\render( array( 'show_posts' => false ) ) . ContentTrees\render_pages() . '</main>';
		file_put_contents( dirname( __DIR__, 2 ) . '/tests/results/taxonomy-controls-render.html', $artifact );
		file_put_contents(
			dirname( __DIR__, 2 ) . '/tests/results/taxonomy-controls-ids.json',
			json_encode(
				array(
					'a'      => $a,
					'middle' => $hidden,
					'b'      => $b,
					'c'      => $c,
				)
			)
		);
		$responses = array();
		foreach ( array(
			'category' => array( $a, $hidden, $b, $c ),
			'post_tag' => array( $x, $y ),
		) as $taxonomy => $term_ids ) {
			foreach ( $term_ids as $term_id ) {
				$responses[ $taxonomy . '/' . $term_id ] = ContentTrees\taxonomy_term_posts( $taxonomy, $term_id ); }
		}
		file_put_contents( dirname( __DIR__, 2 ) . '/tests/results/taxonomy-controls-posts.json', wp_json_encode( $responses ) );
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
echo "PASS: $checks taxonomy feature checks; temporary content removed and settings retained.\n";
