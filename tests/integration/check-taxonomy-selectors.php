<?php
/** Focused selector boundary checks; no existing terms, content or settings changed. */
if ( PHP_SAPI !== 'cli' ) {
	exit( 2 ); }
define( 'WP_INSTALLING', false );
$minimum = ( $argv[1] ?? '' ) === 'minimum';
require __DIR__ . '/bootstrap.php';
if ( ! CONTENT_TREES_DISPOSABLE_TEST_SITE || DB_NAME !== ( $minimum ? 'content_trees_minimum' : 'content_trees_test' ) || DB_HOST !== '127.0.0.1:33080' ) {
	exit( 2 ); }
$created  = array();
$user     = 0;
$checks   = 0;
$old_user = get_current_user_id();
function taxonomy_selector_check( $ok, $message ) {
	global $checks;
	if ( ! $ok ) {
		throw new RuntimeException( $message ); }
	++$checks;
	echo "PASS: $message\n";
}
try {
	$user = wp_insert_user(
		array(
			'user_login' => 'taxonomy-selector-' . wp_generate_password( 8, false ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);
	if ( is_wp_error( $user ) ) {
		throw new RuntimeException( $user->get_error_message() ); }
	wp_set_current_user( $user );
	foreach ( array(
		'category-options' => 'category',
		'tag-options'      => 'post_tag',
	) as $route => $taxonomy ) {
		$request = new WP_REST_Request( 'GET', '/content-trees/v1/' . $route );
		taxonomy_selector_check( rest_do_request( $request )->get_status() === 403, 'Subscriber cannot access ' . $route );
	}
	$account = new WP_User( $user );
	$account->add_cap( 'edit_theme_options' );
	wp_set_current_user( 0 );
	wp_set_current_user( $user );
	foreach ( array(
		'category-options' => 'category',
		'tag-options'      => 'post_tag',
	) as $route => $taxonomy ) {
		$ids = array();
		for ( $i = 0; $i < 25; ++$i ) {
			$term = wp_insert_term( 'Selector boundary ' . $i . ' ' . wp_generate_password( 6, false ), $taxonomy );
			if ( is_wp_error( $term ) ) {
				throw new RuntimeException( $term->get_error_message() ); }
			$ids[]     = (int) $term['term_id'];
			$created[] = array( $taxonomy, (int) $term['term_id'] );
		}
		$request = new WP_REST_Request( 'GET', '/content-trees/v1/' . $route );
		$request->set_param( 'search', 'Selector boundary' );
		$response = rest_do_request( $request );
		taxonomy_selector_check( $response->get_status() === 200 && count( $response->get_data()['items'] ) === 20, 'Theme-only editor may search, with a search-only limit: ' . $route );
		$request->set_param( 'include', implode( ',', $ids ) );
		taxonomy_selector_check( count( rest_do_request( $request )->get_data()['items'] ) === 25, 'All selected IDs resolve beyond the search limit: ' . $route );
		$request->set_param( 'include', '0' );
		taxonomy_selector_check( rest_do_request( $request )->get_data()['items'] === array(), 'An unavailable include cannot widen to a full search: ' . $route );
		$request->set_param( 'include', '1;DROP' );
		taxonomy_selector_check( rest_do_request( $request )->get_status() === 400, 'Malformed includes fail validation: ' . $route );
		$request->set_param( 'include', '' );
		$request->set_param( 'search', str_repeat( 'x', 201 ) );
		taxonomy_selector_check( rest_do_request( $request )->get_status() === 400, 'Oversized search fails validation: ' . $route );
	}
} finally {
	foreach ( $created as $term ) {
		wp_delete_term( $term[1], $term[0] ); }
	wp_set_current_user( $old_user );
	if ( $user && ! is_wp_error( $user ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user ); }
}
echo "PASS: $checks taxonomy selector boundary checks; temporary terms/user removed.\n";
