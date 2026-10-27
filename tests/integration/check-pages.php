<?php
/** Focused Pages-tree regression checks. Never run outside the disposable fixture. */
if ( PHP_SAPI !== 'cli' ) {
	exit( 2 ); }
define( 'WP_INSTALLING', false );
require __DIR__ . '/bootstrap.php';
if ( ! CONTENT_TREES_DISPOSABLE_TEST_SITE || ! in_array( DB_NAME, array( 'content_trees_test', 'content_trees_minimum' ), true ) || DB_HOST !== '127.0.0.1:33080' ) {
	exit( 2 ); }
set_exception_handler(
	static function ( $exception ) {
		fwrite( STDERR, 'FAIL: ' . $exception->getMessage() . "\n" );
		exit( 1 );
	}
);

$phase = $argv[1] ?? 'all';
if ( ! in_array( $phase, array( 'ordering', 'dates', 'controls', 'root', 'scope', 'all' ), true ) ) {
	fwrite( STDERR, "Usage: check-pages.php [ordering|dates|controls|root|scope|all]\n" );
	exit( 2 );
}
$checks        = 0;
$fixture_ids   = array();
$fixture_users = array();
$old_query     = $GLOBALS['wp_query'] ?? null;
$old_post      = $GLOBALS['post'] ?? null;

function pages_check( $ok, $message ) {
	global $checks;
	if ( ! $ok ) {
		throw new RuntimeException( $message ); }
	++$checks;
	echo "PASS: $message\n";
}
function pages_dom( $html ) {
	$doc      = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	try {
		$doc->loadHTML( '<!doctype html><meta charset="utf-8">' . $html ); } finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous ); }
		return new DOMXPath( $doc );
}
function pages_ids( $args = array() ) {
	global $fixture_ids;
	$id = wp_insert_post(
		array_merge(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Pages fixture',
				'post_content' => '',
				'post_parent'  => 0,
				'menu_order'   => 0,
				'post_author'  => 1,
			),
			$args
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Could not create fixture page: ' . $id->get_error_message() ); }
	$fixture_ids[] = (int) $id;
	return (int) $id;
}
function pages_root_labels( $html ) {
	$xpath  = pages_dom( $html );
	$labels = array();
	foreach ( $xpath->query( '//nav[contains(@class,"ctrees-pages-tree")]/ul[contains(@class,"ctrees-pages")]/li' ) as $li ) {
		$link = $xpath->query( './a[contains(@class,"ctrees-page-link")] | ./details/summary/a[contains(@class,"ctrees-page-link")]', $li )->item( 0 );
		if ( $link ) {
			$labels[] = $link->textContent; }
	}
	return array_values(
		array_filter(
			$labels,
			static function ( $label ) {
				return strpos( $label, 'Pages ' ) === 0;
			}
		)
	);
}
function pages_nav_signature( $html ) {
	$xpath = pages_dom( $html );
	$nav   = $xpath->query( '//nav[contains(@class,"ctrees-pages-tree")]' )->item( 0 );
	if ( ! $nav ) {
		return array(); }
	$links = array();
	foreach ( $xpath->query( './/a[contains(@class,"ctrees-page-link")]', $nav ) as $link ) {
		$links[] = array( $link->textContent, $link->getAttribute( 'href' ), $link->getAttribute( 'aria-current' ) );
	}
	$buttons = array();
	foreach ( $xpath->query( './/*[contains(@class,"ctrees-controls")]//button', $nav ) as $button ) {
		$buttons[] = $button->textContent; }
	return array(
		'label'          => $nav->getAttribute( 'aria-label' ),
		'close_siblings' => $nav->getAttribute( 'data-close-siblings' ),
		'links'          => $links,
		'open_branches'  => $xpath->query( './/details[@open]', $nav )->length,
		'controls'       => $buttons,
	);
}
function pages_has_title( $html, $title ) {
	return pages_dom( $html )->query( '//a[contains(concat(" ",normalize-space(@class)," ")," ctrees-page-link ")]' )->length > 0
		&& strpos( $html, esc_html( $title ) ) !== false;
}
function pages_set_current( $id ) {
	$query                    = new WP_Query( array( 'page_id' => (int) $id ) );
	$query->is_page           = true;
	$query->is_singular       = true;
	$query->queried_object    = get_post( $id );
	$query->queried_object_id = (int) $id;
	$GLOBALS['wp_query']      = $query;
	$GLOBALS['post']          = get_post( $id );
}
function pages_tree( $args = array() ) {
	return ContentTrees\render_pages( $args ); }

try {
	// Shared deterministic hierarchy for each phase. All IDs are tracked and removed in finally.
	$root_a       = pages_ids(
		array(
			'post_title' => 'Pages Alpha',
			'menu_order' => 10,
		)
	);
	$root_b       = pages_ids(
		array(
			'post_title' => 'Pages Beta',
			'menu_order' => 10,
		)
	);
	$root_c       = pages_ids(
		array(
			'post_title' => 'Pages Gamma',
			'menu_order' => 20,
		)
	);
	$child_a      = pages_ids(
		array(
			'post_title'  => 'Pages Alpha child',
			'post_parent' => $root_a,
			'menu_order'  => 0,
		)
	);
	$grandchild_a = pages_ids(
		array(
			'post_title'  => 'Pages Alpha grandchild',
			'post_parent' => $child_a,
			'menu_order'  => 0,
		)
	);
	$child_b      = pages_ids(
		array(
			'post_title'  => 'Pages Beta child',
			'post_parent' => $root_b,
			'menu_order'  => 0,
		)
	);
	$grandchild_b = pages_ids(
		array(
			'post_title'  => 'Pages Beta grandchild',
			'post_parent' => $child_b,
			'menu_order'  => 0,
		)
	);

	if ( $phase === 'dates' ) {
		global $wpdb;
		foreach ( array( $root_a, $root_b, $root_c ) as $index => $id ) {
			// Independent dates on these temporary fixtures test both sort fields.
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_date'     => ( $index ? '2020' : '2021' ) . '-01-01 12:00:00',
					'post_modified' => ( $index ? '2022' : '2020' ) . '-01-01 12:00:00',
				),
				array( 'ID' => $id )
			);
			clean_post_cache( $id );
		}
		foreach ( array( 'date', 'modified' ) as $sort ) {
			foreach ( array( 'ASC', 'DESC' ) as $direction ) {
				$expected = ( $sort === 'date' ) === ( $direction === 'ASC' )
					? array( 'Pages Beta', 'Pages Gamma', 'Pages Alpha' ) : array( 'Pages Alpha', 'Pages Beta', 'Pages Gamma' );
				$args     = array(
					'sort_by'   => $sort,
					'order'     => $direction,
					'auto_open' => false,
				);
				pages_check( pages_root_labels( pages_tree( $args ) ) === $expected, "$sort $direction orders siblings with ID ASC ties." );
				$hierarchy = ContentTrees\pages_hierarchy( $args );
				pages_check(
					$hierarchy['parents'][ $child_a ] === $root_a && $hierarchy['parents'][ $grandchild_a ] === $child_a,
					"$sort $direction preserves parentage."
				);
			}
		}
		$widget = new ContentTrees\Pages_Widget();
		pages_check( $widget->update( array( 'sort_by' => 'modified' ), array() )['sort_by'] === 'modified', 'Classic widget saves date sort choices.' );
		ob_start();
		$widget->form( array( 'sort_by' => 'date' ) );
		$form = ob_get_clean();
		pages_check( strpos( $form, 'Oldest first' ) !== false && strpos( $form, 'Newest first' ) !== false, 'Classic form uses date direction labels.' );
		file_put_contents( dirname( __DIR__, 2 ) . '/tests/results/page-date-widget.html', '<form>' . $form . '</form>' );
		$expected = array( 'Pages Alpha', 'Pages Beta', 'Pages Gamma' );
		pages_check(
			pages_root_labels(
				ContentTrees\render_pages_block(
					array(
						'sortBy' => 'date',
						'order'  => 'DESC',
					)
				)
			) === $expected
			&& pages_root_labels( do_shortcode( '[content_trees_pages sort_by="modified" order="ASC"]' ) ) === $expected,
			'Block and shortcode accept both date sort fields.'
		);
		$enum = WP_Block_Type_Registry::get_instance()->get_registered( 'content-trees/pages' )->attributes['sortBy']['enum'];
		pages_check( in_array( 'date', $enum, true ) && in_array( 'modified', $enum, true ), 'Block schema accepts the new sort fields.' );
		wp_update_post(
			array(
				'ID'        => $root_a,
				'post_date' => '1999-01-01 12:00:00',
			)
		);
		foreach ( array( 'date', 'modified' ) as $sort ) {
			foreach ( array( 'ASC', 'DESC' ) as $direction ) {
				pages_check( get_transient( 'ctrees_pages_v1_' . $sort . '_' . $direction ) === false, "$sort $direction cache invalidates after page updates." );
			}
		}
		pages_check(
			pages_root_labels(
				pages_tree(
					array(
						'sort_by' => 'date',
						'order'   => 'ASC',
					)
				)
			) === $expected
			&& pages_root_labels(
				pages_tree(
					array(
						'sort_by' => 'modified',
						'order'   => 'DESC',
					)
				)
			) === $expected,
			'Updated publication and modification dates reorder warmed trees.'
		);
	}

	if ( $phase === 'root' ) {
		// Root scoping is checked without controls, sort overrides, or exclusions.
		$rooted = pages_tree(
			array(
				'root'      => $root_a,
				'auto_open' => false,
			)
		);
		pages_check(
			pages_root_labels( $rooted ) === array( 'Pages Alpha' )
			&& pages_has_title( $rooted, 'Pages Alpha child' ) && pages_has_title( $rooted, 'Pages Alpha grandchild' )
			&& ! pages_has_title( $rooted, 'Pages Beta' ) && ! pages_has_title( $rooted, 'Pages Gamma' ),
			'A valid root includes itself and descendants while excluding outside pages.'
		);

		$hidden              = pages_ids(
			array(
				'post_title'  => 'Pages root hidden draft',
				'post_status' => 'draft',
			)
		);
		$hidden_child        = pages_ids(
			array(
				'post_title'  => 'Pages root hidden child',
				'post_parent' => $hidden,
			)
		);
		$ancestor            = pages_ids( array( 'post_title' => 'Pages root public ancestor' ) );
		$intermediate        = pages_ids(
			array(
				'post_title'  => 'Pages root hidden intermediate',
				'post_status' => 'draft',
				'post_parent' => $ancestor,
			)
		);
		$promoted            = pages_ids(
			array(
				'post_title'  => 'Pages root promoted child',
				'post_parent' => $intermediate,
			)
		);
		$promoted_descendant = pages_ids(
			array(
				'post_title'  => 'Pages root promoted descendant',
				'post_parent' => $promoted,
			)
		);
		$through_hidden      = pages_tree(
			array(
				'root'      => $ancestor,
				'auto_open' => false,
			)
		);
		pages_check(
			pages_root_labels( $through_hidden ) === array( 'Pages root public ancestor' )
			&& pages_has_title( $through_hidden, 'Pages root promoted child' )
			&& pages_has_title( $through_hidden, 'Pages root promoted descendant' ),
			'Root scope includes public descendants through their hidden original ancestor.'
		);
		pages_check(
			pages_dom(
				pages_tree(
					array(
						'root'      => $hidden,
						'auto_open' => false,
					)
				)
			)->query( '//nav[contains(@class,"ctrees-pages-tree")]/ul/li' )->length === 0,
			'A hidden root renders an empty tree even when it has public descendants.'
		);

		$deleted_root = pages_ids( array( 'post_title' => 'Pages root deleted' ) );
		pages_ids(
			array(
				'post_title'  => 'Pages root deleted child',
				'post_parent' => $deleted_root,
			)
		);
		wp_delete_post( $deleted_root, true );
		pages_check(
			pages_dom(
				pages_tree(
					array(
						'root'      => $deleted_root,
						'auto_open' => false,
					)
				)
			)->query( '//nav[contains(@class,"ctrees-pages-tree")]/ul/li' )->length === 0,
			'A deleted root ID renders an empty tree.'
		);
		foreach ( array( 'not-a-page', -1, '999999999' ) as $invalid_root ) {
			pages_check(
				pages_dom(
					pages_tree(
						array(
							'root'      => $invalid_root,
							'auto_open' => false,
						)
					)
				)->query( '//nav[contains(@class,"ctrees-pages-tree")]/ul/li' )->length === 0,
				'A malformed or unavailable root ID renders an empty tree.'
			);
		}

		pages_set_current( $grandchild_b );
		$outside_current = pages_tree(
			array(
				'root'      => $root_a,
				'auto_open' => false,
			)
		);
		pages_check(
			pages_dom( $outside_current )->query( '//a[@aria-current="page"]' )->length === 0,
			'A current page outside the selected root is not highlighted.'
		);

		// The three adapters normalize the same root without depending on controls/exclusions.
		$expected = pages_tree(
			array(
				'root'      => $root_a,
				'auto_open' => false,
			)
		);
		$block    = ContentTrees\render_pages_block(
			array(
				'title'    => 'Root adapter',
				'root'     => $root_a,
				'autoOpen' => false,
			)
		);
		pages_check(
			pages_dom( $block )->query( '//nav[contains(@class,"ctrees-pages-tree")]//a[contains(@class,"ctrees-page-link")]' )->length
			=== pages_dom( $expected )->query( '//nav[contains(@class,"ctrees-pages-tree")]//a[contains(@class,"ctrees-page-link")]' )->length,
			'The Pages block adapter applies the selected root.'
		);
		$shortcode = ContentTrees\render_pages_shortcode(
			array(
				'title'     => 'Root adapter',
				'root'      => $root_a,
				'auto_open' => false,
			)
		);
		pages_check(
			pages_dom( $shortcode )->query( '//nav[contains(@class,"ctrees-pages-tree")]//a[contains(@class,"ctrees-page-link")]' )->length
			=== pages_dom( $expected )->query( '//nav[contains(@class,"ctrees-pages-tree")]//a[contains(@class,"ctrees-page-link")]' )->length,
			'The Pages shortcode adapter applies the selected root.'
		);
		$widget = new ContentTrees\Pages_Widget();
		ob_start();
		$widget->widget(
			array(
				'before_widget' => '',
				'after_widget'  => '',
				'before_title'  => '',
				'after_title'   => '',
			),
			array(
				'title'     => '',
				'root'      => $root_a,
				'auto_open' => false,
			)
		);
		$widget_html = ob_get_clean();
		pages_check(
			pages_dom( $widget_html )->query( '//nav[contains(@class,"ctrees-pages-tree")]//a[contains(@class,"ctrees-page-link")]' )->length
			=== pages_dom( $expected )->query( '//nav[contains(@class,"ctrees-pages-tree")]//a[contains(@class,"ctrees-page-link")]' )->length,
			'The Pages widget adapter applies the selected root.'
		);

		// The editor-only selector must authorize editing users and return public page data only.
		$public_title       = 'Pages visible public selector fixture';
		$private_title      = 'Pages secret private selector fixture';
		$public_selector    = pages_ids( array( 'post_title' => $public_title ) );
		$private_selector   = pages_ids(
			array(
				'post_title'  => $private_title,
				'post_status' => 'private',
			)
		);
		$draft_selector     = pages_ids(
			array(
				'post_title'  => 'Pages secret draft selector fixture',
				'post_status' => 'draft',
			)
		);
		$protected_selector = pages_ids(
			array(
				'post_title'    => 'Pages secret protected selector fixture',
				'post_password' => 'fixture-secret',
			)
		);
		$search             = new WP_REST_Request( 'GET', '/content-trees/v1/page-options' );
		$search->set_param( 'search', $private_title );
		wp_set_current_user( 0 );
		pages_check( rest_do_request( $search )->get_status() === 401, 'Anonymous visitors cannot query page selector options.' );
		$subscriber = wp_insert_user(
			array(
				'user_login' => 'ctrees-pages-root-subscriber',
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $subscriber ) ) {
			throw new RuntimeException( 'Could not create root-phase subscriber.' ); }
		$fixture_users[] = (int) $subscriber;
		wp_set_current_user( $subscriber );
		pages_check( rest_do_request( $search )->get_status() === 403, 'Subscribers without either editing capability cannot query page options.' );
		$editor = wp_insert_user(
			array(
				'user_login' => 'ctrees-pages-root-editor',
				'user_pass'  => wp_generate_password(),
				'role'       => 'editor',
			)
		);
		if ( is_wp_error( $editor ) ) {
			throw new RuntimeException( 'Could not create root-phase editor.' ); }
		$fixture_users[] = (int) $editor;
		wp_set_current_user( $editor );
		$public_search = new WP_REST_Request( 'GET', '/content-trees/v1/page-options' );
		$public_search->set_param( 'search', $public_title );
		$public_results = rest_do_request( $public_search )->get_data();
		pages_check(
			isset( $public_results['items'] ) && count( $public_results['items'] ) === 1
			&& (int) $public_results['items'][0]['id'] === $public_selector
			&& strpos( wp_json_encode( $public_results ), $private_title ) === false,
			'Authorized search finds the public page and exposes no private titles.'
		);
		$private_search = new WP_REST_Request( 'GET', '/content-trees/v1/page-options' );
		$private_search->set_param( 'search', $private_title );
		pages_check(
			( rest_do_request( $private_search )->get_data()['items'] ?? array() ) === array(),
			'Search never returns private page titles.'
		);
		$include = new WP_REST_Request( 'GET', '/content-trees/v1/page-options' );
		$include->set_param( 'include', implode( ',', array( $public_selector, $private_selector, $draft_selector, $protected_selector ) ) );
		$included     = rest_do_request( $include )->get_data();
		$included_ids = array_map(
			static function ( $item ) {
				return (int) $item['id'];
			},
			$included['items'] ?? array()
		);
		pages_check(
			in_array( $public_selector, $included_ids, true ) && ! in_array( $private_selector, $included_ids, true )
			&& ! in_array( $draft_selector, $included_ids, true ) && ! in_array( $protected_selector, $included_ids, true ),
			'Explicit page-option includes return only published, unprotected public pages.'
		);
	}

	if ( in_array( $phase, array( 'ordering', 'controls', 'all' ), true ) ) {
		// Equal menu_order ties stay deterministic (ID ASC), including DESC ordering.
		pages_check( pages_root_labels( pages_tree( array( 'auto_open' => false ) ) ) === array( 'Pages Alpha', 'Pages Beta', 'Pages Gamma' ), 'Default menu order sorts siblings by menu_order then stable ID.' );
		pages_check(
			pages_root_labels(
				pages_tree(
					array(
						'sort_by'   => 'menu_order',
						'order'     => 'DESC',
						'auto_open' => false,
					)
				)
			) === array(
				'Pages Gamma',
				'Pages Alpha',
				'Pages Beta',
			),
			'Descending menu order reverses primary values while preserving ID ASC ties.'
		);
		pages_check(
			pages_root_labels(
				pages_tree(
					array(
						'sort_by'   => 'title',
						'order'     => 'ASC',
						'auto_open' => false,
					)
				)
			) === array(
				'Pages Alpha',
				'Pages Beta',
				'Pages Gamma',
			),
			'Title sorting accepts ASC.'
		);
		pages_check(
			pages_root_labels(
				pages_tree(
					array(
						'sort_by'   => 'title',
						'order'     => 'DESC',
						'auto_open' => false,
					)
				)
			) === array(
				'Pages Gamma',
				'Pages Beta',
				'Pages Alpha',
			),
			'Title sorting accepts DESC.'
		);
		$title_tie_one = pages_ids(
			array(
				'post_title' => 'Pages title tie',
				'menu_order' => 90,
			)
		);
		$title_tie_two = pages_ids(
			array(
				'post_title' => 'Pages title tie',
				'menu_order' => -90,
			)
		);
		foreach ( array( 'ASC', 'DESC' ) as $direction ) {
			$labels = pages_root_labels(
				pages_tree(
					array(
						'sort_by'   => 'title',
						'order'     => $direction,
						'auto_open' => false,
					)
				)
			);
			$first  = array_search( 'Pages title tie', $labels, true );
			pages_check( $first !== false && $labels[ $first + 1 ] === 'Pages title tie', 'Equal titles remain adjacent under ' . $direction . ' title ordering.' );
			// Their rendered labels match; IDs are checked through hierarchy sibling order.
			$hierarchy     = ContentTrees\pages_hierarchy(
				array(
					'sort_by' => 'title',
					'order'   => $direction,
				)
			);
			$tie_positions = array_search( $title_tie_one, $hierarchy['children'][0], true ) < array_search( $title_tie_two, $hierarchy['children'][0], true );
			pages_check( $tie_positions, 'Equal-title tie order stays ID ASC under ' . $direction . ' sorting.' );
		}

		pages_set_current( $grandchild_b );
		$current_closed = pages_tree( array( 'auto_open' => false ) );
		pages_check(
			pages_dom( $current_closed )->query( '//a[@aria-current="page"]' )->length === 1
			&& pages_dom( $current_closed )->query( '//details[@open]' )->length === 0,
			'Current page is highlighted when auto_open is false, with all branches closed.'
		);
		$current_default = pages_tree();
		pages_check( pages_dom( $current_default )->query( '//details[@open]' )->length >= 2, 'Default auto_open opens the actual current page ancestry.' );
		$targeted       = pages_tree(
			array(
				'auto_open' => false,
				'open_to'   => $child_a,
			)
		);
		$targeted_xpath = pages_dom( $targeted );
		pages_check(
			$targeted_xpath->query( '//details[@open]' )->length === 1
			&& $targeted_xpath->query( '//details[@open]/summary/a[contains(.,"Pages Alpha")]' )->length === 1
			&& $targeted_xpath->query( '//a[@aria-current="page"]' )->length === 1,
			'open_to opens only the target ancestry and preserves the separate current-page highlight.'
		);
		$invalid_target = pages_tree(
			array(
				'auto_open' => true,
				'open_to'   => 987654321,
			)
		);
		pages_check(
			pages_dom( $invalid_target )->query( '//details[@open]' )->length >= 2,
			'An invalid open_to falls back to the enabled automatic current-page opening.'
		);
	}

	if ( $phase === 'controls' || $phase === 'all' ) {
		$leaf = pages_ids( array( 'post_title' => 'Pages leaf only' ) );
		$html = pages_tree(
			array(
				'root'          => $leaf,
				'show_controls' => true,
			)
		);
		pages_check( pages_dom( $html )->query( '//nav[contains(@class,"ctrees-pages-tree")]//details' )->length === 0, 'A leaf-only tree has no disclosures.' );
		pages_check( pages_dom( $html )->query( '//nav[contains(@class,"ctrees-pages-tree")]//*[contains(@class,"ctrees-controls")]' )->length === 0, 'Controls are omitted when the selected tree has no branches.' );
		$html = pages_tree(
			array(
				'root'           => $root_a,
				'show_controls'  => true,
				'close_siblings' => true,
				'auto_open'      => false,
			)
		);
		pages_check( pages_dom( $html )->query( '//nav[contains(@class,"ctrees-pages-tree")]//details[contains(@class,"ctrees-page-branch")]' )->length === 2, 'The selected scope renders its parent and deeper branch as native disclosures.' );
		pages_check( pages_dom( $html )->query( '//nav[contains(@class,"ctrees-pages-tree")]//*[contains(@class,"ctrees-controls")]//button' )->length === 2, 'Open/Close controls appear for a tree with a branch when requested.' );
		pages_check(
			pages_dom( $html )->query( '//*[@aria-live or @role="status"] | //*[contains(concat(" ",normalize-space(@class)," ")," ctrees-announcement ")]' )->length === 0,
			'Pages server output contains no announcement live region before JavaScript enhancement.'
		);
	}

	if ( $phase === 'scope' || $phase === 'all' ) {
		$hidden               = pages_ids(
			array(
				'post_title'  => 'Pages hidden draft ancestor',
				'post_status' => 'draft',
			)
		);
		$promoted             = pages_ids(
			array(
				'post_title'  => 'Pages promoted child',
				'post_parent' => $hidden,
			)
		);
		$promoted_child       = pages_ids(
			array(
				'post_title'  => 'Pages promoted grandchild',
				'post_parent' => $promoted,
			)
		);
		$excluded_open_target = pages_ids(
			array(
				'post_title'  => 'Pages excluded open target',
				'post_parent' => $root_b,
			)
		);
		$private              = pages_ids(
			array(
				'post_title'  => 'Pages private page',
				'post_status' => 'private',
			)
		);
		$protected            = pages_ids(
			array(
				'post_title'    => 'Pages protected page',
				'post_password' => 'fixture-secret',
			)
		);
		$deleted_parent       = pages_ids( array( 'post_title' => 'Pages deleted parent' ) );
		$deleted_child        = pages_ids(
			array(
				'post_title'  => 'Pages deleted child',
				'post_parent' => $deleted_parent,
			)
		);

		$all = pages_tree( array( 'auto_open' => false ) );
		pages_check( ! pages_has_title( $all, 'Pages hidden draft ancestor' ) && ! pages_has_title( $all, 'Pages private page' ) && ! pages_has_title( $all, 'Pages protected page' ), 'Draft, private and password-protected pages never appear.' );
		pages_check( pages_has_title( $all, 'Pages promoted child' ) && pages_has_title( $all, 'Pages promoted grandchild' ), 'Public descendants are promoted around hidden ancestors.' );
		pages_check(
			pages_has_title(
				pages_tree(
					array(
						'excluded_pages' => array( $hidden ),
						'auto_open'      => false,
					)
				),
				'Pages promoted child'
			) === false,
			'Excluding a hidden ancestor excludes public descendants through the original ancestry.'
		);
		pages_check(
			pages_has_title(
				pages_tree(
					array(
						'excluded_pages' => (string) $root_b,
						'auto_open'      => false,
					)
				),
				'Pages Beta'
			) === false
			&& pages_has_title(
				pages_tree(
					array(
						'excluded_pages' => (string) $root_b,
						'auto_open'      => false,
					)
				),
				'Pages Beta grandchild'
			) === false,
			'CSV exclusion removes a selected page and every descendant.'
		);
		$rooted = pages_tree(
			array(
				'root'      => $root_b,
				'auto_open' => false,
			)
		);
		pages_check( pages_root_labels( $rooted ) === array( 'Pages Beta' ) && pages_has_title( $rooted, 'Pages Beta grandchild' ), 'A positive root is included and limits output to its subtree.' );
		pages_check(
			pages_dom(
				pages_tree(
					array(
						'root'           => $root_b,
						'excluded_pages' => array( $root_b ),
						'auto_open'      => false,
					)
				)
			)->query( '//nav[contains(@class,"ctrees-pages-tree")]/ul/li' )->length === 0,
			'Excluding the selected root itself produces an empty tree.'
		);
		pages_check(
			pages_dom(
				pages_tree(
					array(
						'root'           => $promoted,
						'excluded_pages' => array( $hidden ),
						'auto_open'      => false,
					)
				)
			)->query( '//nav[contains(@class,"ctrees-pages-tree")]/ul/li' )->length === 0,
			'A selected root excluded through its hidden original ancestor produces an empty tree.'
		);
		$whitespace_csv = pages_tree(
			array(
				'excluded_pages' => "  $root_a,  $root_b  ",
				'auto_open'      => false,
			)
		);
		pages_check(
			! pages_has_title( $whitespace_csv, 'Pages Alpha' ) && ! pages_has_title( $whitespace_csv, 'Pages Beta' ),
			'Whitespace-separated CSV exclusions normalize around commas.'
		);
		$malformed = pages_tree(
			array(
				'excluded_pages' => array( array( $root_a ), 'bad-id', -2, $root_b ),
				'auto_open'      => false,
			)
		);
		pages_check(
			pages_has_title( $malformed, 'Pages Alpha' ) && ! pages_has_title( $malformed, 'Pages Beta' ),
			'Malformed and nested exclusion settings are ignored while valid IDs still apply.'
		);
		foreach ( array( $private, $protected, 987654321 ) as $invalid_root ) {
			pages_check( pages_dom( pages_tree( array( 'root' => $invalid_root ) ) )->query( '//nav[contains(@class,"ctrees-pages-tree")]/ul/li' )->length === 0, 'Invalid, private, or unavailable selected roots render an empty tree.' );
		}
		wp_delete_post( $deleted_parent, true );
		pages_check( pages_has_title( pages_tree( array( 'auto_open' => false ) ), 'Pages deleted child' ), 'A child whose parent is deleted is safely promoted to the public root.' );

		// Current-page state is independent from the requested opening behavior.
		pages_set_current( $grandchild_b );
		$current_closed = pages_tree(
			array(
				'root'      => $root_b,
				'auto_open' => false,
			)
		);
		pages_check( pages_dom( $current_closed )->query( '//a[@aria-current="page"]' )->length === 1, 'Current page is highlighted even when automatic opening is disabled.' );
		pages_check( pages_dom( $current_closed )->query( '//details[@open]' )->length === 0, 'auto_open=false keeps current ancestors closed.' );
		$current_open = pages_tree( array( 'root' => $root_b ) );
		pages_check( pages_dom( $current_open )->query( '//details[@open]' )->length >= 2, 'Default auto_open opens the current page ancestry.' );
		$outside_target_open = pages_tree(
			array(
				'root'      => $root_b,
				'open_to'   => $child_a,
				'auto_open' => true,
			)
		);
		pages_check(
			pages_dom( $outside_target_open )->query( '//details[@open]' )->length >= 2
			&& pages_dom( $outside_target_open )->query( '//a[@aria-current="page"]' )->length === 1,
			'A valid open_to outside the selected root falls back to opening the actual current ancestry.'
		);
		$outside_target_closed = pages_tree(
			array(
				'root'      => $root_b,
				'open_to'   => $child_a,
				'auto_open' => false,
			)
		);
		pages_check(
			pages_dom( $outside_target_closed )->query( '//details[@open]' )->length === 0
			&& pages_dom( $outside_target_closed )->query( '//a[@aria-current="page"]' )->length === 1,
			'An out-of-root open_to stays closed when auto_open is false and keeps the current highlight.'
		);
		$excluded_target_open = pages_tree(
			array(
				'root'           => $root_b,
				'open_to'        => $excluded_open_target,
				'excluded_pages' => (string) $excluded_open_target,
				'auto_open'      => true,
			)
		);
		pages_check(
			pages_dom( $excluded_target_open )->query( '//details[@open]' )->length >= 2
			&& pages_dom( $excluded_target_open )->query( '//a[@aria-current="page"]' )->length === 1,
			'An excluded open_to falls back to the actual current ancestry when auto_open is enabled.'
		);
		$excluded_target_closed = pages_tree(
			array(
				'root'           => $root_b,
				'open_to'        => $excluded_open_target,
				'excluded_pages' => (string) $excluded_open_target,
				'auto_open'      => false,
			)
		);
		pages_check(
			pages_dom( $excluded_target_closed )->query( '//details[@open]' )->length === 0
			&& pages_dom( $excluded_target_closed )->query( '//a[@aria-current="page"]' )->length === 1,
			'An excluded open_to stays closed with auto_open disabled while the current page remains marked.'
		);
		$excluded_current = pages_tree(
			array(
				'root'           => $root_b,
				'excluded_pages' => array( $grandchild_b ),
				'auto_open'      => true,
			)
		);
		pages_check(
			pages_dom( $excluded_current )->query( '//a[@aria-current="page"]' )->length === 0
			&& pages_dom( $excluded_current )->query( '//details[@open]' )->length === 0,
			'An excluded current page is not highlighted or used as an automatic opening target.'
		);
		$override = pages_tree(
			array(
				'root'      => $root_a,
				'auto_open' => false,
				'open_to'   => $child_a,
			)
		);
		pages_check( pages_dom( $override )->query( '//details[@open]' )->length >= 1, 'open_to opens its branch without changing the selected tree scope.' );
		pages_check( pages_dom( $override )->query( '//a[@aria-current="page"]' )->length === 0, 'An open_to target does not become the current page.' );
		$outside = pages_tree(
			array(
				'root'      => $root_a,
				'auto_open' => false,
			)
		);
		pages_check( pages_dom( $outside )->query( '//a[@aria-current="page"]' )->length === 0 && pages_dom( $outside )->query( '//details[@open]' )->length === 0, 'A current page outside the selected root is neither highlighted nor opened.' );

		// Settings adapters must normalize into the same public render API.
		$common   = array(
			'title'          => 'Adapter title',
			'sort_by'        => 'title',
			'order'          => 'DESC',
			'auto_open'      => false,
			'show_controls'  => true,
			'close_siblings' => true,
			'root'           => 0,
			'excluded_pages' => array( $root_b ),
		);
		$expected = pages_tree( $common );
		$block    = ContentTrees\render_pages_block(
			array(
				'title'         => 'Adapter title',
				'sortBy'        => 'title',
				'order'         => 'DESC',
				'autoOpen'      => false,
				'showControls'  => true,
				'closeSiblings' => true,
				'root'          => 0,
				'exclude'       => array( $root_b ),
			)
		);
		pages_check( pages_nav_signature( $block ) === pages_nav_signature( $expected ), 'Block attributes normalize sort, order, opening, controls, sibling closing, and exclusions.' );
		$shortcode = ContentTrees\render_pages_shortcode(
			array(
				'title'          => 'Adapter title',
				'sort_by'        => 'title',
				'order'          => 'DESC',
				'auto_open'      => false,
				'show_controls'  => true,
				'close_siblings' => true,
				'root'           => 0,
				'excluded_pages' => (string) $root_b,
			)
		);
		pages_check( pages_nav_signature( $shortcode ) === pages_nav_signature( $expected ), 'Shortcode arguments normalize sort, order, opening, controls, sibling closing, and CSV exclusions.' );
		$widget = new ContentTrees\Pages_Widget();
		ob_start();
		$widget->widget(
			array(
				'before_widget' => '',
				'after_widget'  => '',
				'before_title'  => '',
				'after_title'   => '',
			),
			array_merge( $common, array( 'show_title' => false ) )
		);
		$widget_html = ob_get_clean();
		pages_check( pages_nav_signature( $widget_html ) === pages_nav_signature( $expected ), 'Widget settings normalize to the same sort, order, opening, controls, sibling closing, and exclusions.' );
		pages_check(
			count( pages_nav_signature( $expected )['controls'] ) === 2
			&& pages_nav_signature( $expected )['close_siblings'] === '1'
			&& ! pages_has_title( $expected, 'Pages Beta' ),
			'Adapter output retains controls and close-siblings while excluding the selected subtree.'
		);

		// Editor options are private metadata: only authorized users may query them.
		$search = new WP_REST_Request( 'GET', '/content-trees/v1/page-options' );
		$search->set_param( 'search', 'Pages private page' );
		wp_set_current_user( 0 );
		pages_check( rest_do_request( $search )->get_status() === 401, 'Anonymous visitors cannot query page selector options.' );
		$subscriber = wp_insert_user(
			array(
				'user_login' => 'ctrees-pages-subscriber-test',
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $subscriber ) ) {
			throw new RuntimeException( 'Could not create permissions fixture user.' ); }
		$fixture_users[] = (int) $subscriber;
		wp_set_current_user( $subscriber );
		pages_check( rest_do_request( $search )->get_status() === 403, 'A subscriber without either editing capability cannot query page options.' );
		$editor = wp_insert_user(
			array(
				'user_login' => 'ctrees-pages-editor-test',
				'user_pass'  => wp_generate_password(),
				'role'       => 'editor',
			)
		);
		if ( is_wp_error( $editor ) ) {
			throw new RuntimeException( 'Could not create editor fixture user.' ); }
		$fixture_users[] = (int) $editor;
		wp_set_current_user( $editor );
		$public_request = new WP_REST_Request( 'GET', '/content-trees/v1/page-options' );
		$public_request->set_param( 'search', 'Pages Beta' );
		$public_data = rest_do_request( $public_request )->get_data();
		pages_check( isset( $public_data['items'] ) && count( $public_data['items'] ) > 0 && strpos( wp_json_encode( $public_data ), 'Pages private page' ) === false, 'Authorized page-option search returns public titles only.' );
		$include = new WP_REST_Request( 'GET', '/content-trees/v1/page-options' );
		$include->set_param( 'include', implode( ',', array( $root_a, $private, $protected ) ) );
		$included     = rest_do_request( $include )->get_data();
		$included_ids = array_map(
			static function ( $item ) {
				return (int) $item['id'];
			},
			$included['items'] ?? array()
		);
		pages_check( in_array( $root_a, $included_ids, true ) && ! in_array( $private, $included_ids, true ) && ! in_array( $protected, $included_ids, true ), 'Explicit editor includes resolve only public pages, never private or protected titles.' );

		$theme_editor = wp_insert_user(
			array(
				'user_login' => 'ctrees-pages-theme-editor-test',
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $theme_editor ) ) {
			throw new RuntimeException( 'Could not create theme-editor fixture user.' ); }
		$fixture_users[]   = (int) $theme_editor;
		$theme_editor_user = new WP_User( $theme_editor );
		$theme_editor_user->add_cap( 'edit_theme_options' );
		wp_set_current_user( $theme_editor );
		pages_check(
			! current_user_can( 'edit_pages' ) && current_user_can( 'edit_theme_options' )
			&& rest_do_request( new WP_REST_Request( 'GET', '/content-trees/v1/page-options' ) )->get_status() === 200,
			'A user with only edit_theme_options may query page options.'
		);

		// Mutation reflection after any warmed tree query. No cache implementation assumptions.
		pages_tree();
		$mutated = pages_ids(
			array(
				'post_title' => 'Pages mutation initial',
				'menu_order' => 500,
			)
		);
		pages_check( pages_has_title( pages_tree( array( 'auto_open' => false ) ), 'Pages mutation initial' ), 'Creating a page after a warmed hierarchy makes it visible.' );
		wp_update_post(
			array(
				'ID'         => $mutated,
				'post_title' => 'Pages mutation renamed',
				'menu_order' => -500,
			)
		);
		clean_post_cache( $mutated );
		$renamed = pages_tree( array( 'auto_open' => false ) );
		pages_check( pages_has_title( $renamed, 'Pages mutation renamed' ) && pages_root_labels( $renamed )[0] === 'Pages mutation renamed', 'A title and order update is reflected after a prior hierarchy render.' );
		wp_update_post(
			array(
				'ID'          => $mutated,
				'post_parent' => $root_a,
			)
		);
		clean_post_cache( $mutated );
		pages_check( pages_dom( pages_tree( array( 'auto_open' => false ) ) )->query( '//details/ul/li/a[contains(.,"Pages mutation renamed")]' )->length === 1, 'A reparent operation is reflected after a prior hierarchy render.' );
		wp_update_post(
			array(
				'ID'          => $mutated,
				'post_status' => 'draft',
			)
		);
		clean_post_cache( $mutated );
		pages_check( ! pages_has_title( pages_tree( array( 'auto_open' => false ) ), 'Pages mutation renamed' ), 'Changing a page to draft removes it after a prior hierarchy render.' );
		wp_update_post(
			array(
				'ID'            => $mutated,
				'post_status'   => 'publish',
				'post_password' => 'fixture-secret',
			)
		);
		clean_post_cache( $mutated );
		pages_check( ! pages_has_title( pages_tree( array( 'auto_open' => false ) ), 'Pages mutation renamed' ), 'Adding a password removes a page after a prior hierarchy render.' );
		wp_update_post(
			array(
				'ID'            => $mutated,
				'post_password' => '',
			)
		);
		clean_post_cache( $mutated );
		wp_delete_post( $mutated, true );
		clean_post_cache( $mutated );
		pages_check( ! pages_has_title( pages_tree( array( 'auto_open' => false ) ), 'Pages mutation renamed' ), 'Deleting a page is reflected after a prior hierarchy render.' );

		// Preserve a self-contained visual test document before fixture cleanup.
		wp_set_current_user( 1 );
		$sample_one  = pages_tree(
			array(
				'title'          => 'Pages one',
				'show_title'     => true,
				'root'           => 0,
				'show_controls'  => true,
				'close_siblings' => true,
				'auto_open'      => false,
			)
		);
		$sample_two  = pages_tree(
			array(
				'title'          => 'Pages two',
				'show_title'     => true,
				'root'           => 0,
				'show_controls'  => true,
				'close_siblings' => true,
				'auto_open'      => false,
			)
		);
		$archive     = ContentTrees\render(
			array(
				'title'          => 'Archive',
				'show_controls'  => true,
				'close_siblings' => true,
				'auto_open'      => false,
			)
		);
		$sample_path = dirname( __DIR__, 2 ) . '/tests/results/pages-render.html';
		if ( ! is_dir( dirname( $sample_path ) ) && ! mkdir( dirname( $sample_path ), 0777, true ) && ! is_dir( dirname( $sample_path ) ) ) {
			throw new RuntimeException( 'Could not create Pages browser sample folder.' );
		}
		$sample = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>Content Trees Pages browser sample</title><main><h1>Pages browser sample</h1>'
			. $sample_one . $sample_two . $archive . '</main></html>';
		if ( file_put_contents( $sample_path, $sample ) === false ) {
			throw new RuntimeException( 'Could not save Pages browser sample.' ); }
		pages_check( strpos( $sample, 'Pages one' ) !== false && strpos( $sample, 'Pages two' ) !== false && strpos( $sample, 'Archive' ) !== false, 'Saved isolated Pages and Archive browser sample before cleanup.' );
	}
} finally {
	foreach ( array_reverse( $fixture_ids ) as $id ) {
		wp_delete_post( $id, true ); }
	if ( $fixture_users ) {
		require_once ABSPATH . 'wp-admin/includes/user.php'; }
	foreach ( array_reverse( $fixture_users ) as $user_id ) {
		wp_delete_user( $user_id ); }
	if ( $old_query ) {
		$GLOBALS['wp_query'] = $old_query; }
	if ( $old_post ) {
		$GLOBALS['post'] = $old_post; }
	wp_set_current_user( 0 );
}
echo "PASS: $checks Pages checks ($phase); fixtures removed in finally.\n";
