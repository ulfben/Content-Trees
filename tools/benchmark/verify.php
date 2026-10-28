<?php
/** Independently compare generated memberships and subtree counts with SQL. */
if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}
require dirname( __DIR__, 2 ) . '/tests/integration/bootstrap.php';
if ( ! defined( 'CONTENT_TREES_BENCHMARK_SITE' ) || true !== CONTENT_TREES_BENCHMARK_SITE ) {
	throw new RuntimeException( 'A separately marked benchmark installation is required.' );
}
$state = get_option( 'ctrees_benchmark_dataset_v1' );
if ( ! is_array( $state ) || empty( $state['complete'] ) ) {
	throw new RuntimeException( 'No complete benchmark dataset.' );
}
global $wpdb;
$post_totals = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(post_status='publish' AND post_password='') AS eligible, SUM(post_status='draft') AS drafts, SUM(post_status='publish' AND post_password<>'') AS password_protected FROM {$wpdb->posts} WHERE post_type='post'", ARRAY_A );
foreach ( $post_totals as &$value ) {
	$value = (int) $value;
}
unset( $value );
if ( $post_totals['total'] !== $state['posts'] || $post_totals['eligible'] !== $state['distribution']['published'] || $post_totals['drafts'] !== $state['distribution']['draft'] || $post_totals['password_protected'] !== $state['distribution']['password'] ) {
	throw new RuntimeException( 'Actual post totals do not match the generator manifest.' );
}
$report = array( 'posts' => $post_totals );
foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
	$data = ContentTrees\taxonomy_data( $taxonomy, array( 'auto_open' => false, 'hide_empty' => false ) );
	$expected_terms = 'category' === $taxonomy ? $state['categories'] + 1 : $state['tags'];
	if ( count( $data['terms'] ) !== $expected_terms ) {
		throw new RuntimeException( 'Actual term totals do not match the generator manifest.' );
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_id, tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN {$wpdb->posts} p ON p.ID=tr.object_id WHERE tt.taxonomy=%s AND p.post_type='post' AND p.post_status='publish' AND p.post_password=''", $taxonomy ) );
	$direct = $unique = array();
	foreach ( $rows as $row ) {
		$term = (int) $row->term_id;
		$post = (int) $row->object_id;
		$direct[ $term ][ $post ] = true;
		while ( $term ) {
			$unique[ $term ][ $post ] = true;
			$term = $data['parents'][ $term ];
		}
	}
	foreach ( $data['terms'] as $term => $unused ) {
		$expected = $direct[ $term ] ?? array();
		$actual = $data['memberships'][ $term ] ?? array();
		if ( array_diff_key( $expected, $actual ) || array_diff_key( $actual, $expected ) || count( $unique[ $term ] ?? array() ) !== $data['counts'][ $term ] ) {
			throw new RuntimeException( 'Membership or unique count mismatch for ' . $taxonomy . ' term ' . $term );
		}
	}
	$direct_counts = array_map( 'count', $direct );
	rsort( $direct_counts );
	$all_counts = array();
	foreach ( $data['terms'] as $term => $unused ) {
		$all_counts[] = count( $direct[ $term ] ?? array() );
	}
	sort( $all_counts );
	$report[ $taxonomy ] = array( 'visible_terms' => count( $data['terms'] ), 'eligible_direct_memberships' => count( $rows ), 'empty_direct_terms' => count( $data['terms'] ) - count( $direct ), 'direct_p50' => $all_counts[ (int) floor( ( count( $all_counts ) - 1 ) * 0.50 ) ], 'direct_p95' => $all_counts[ (int) floor( ( count( $all_counts ) - 1 ) * 0.95 ) ], 'largest_direct_memberships' => array_slice( $direct_counts, 0, 10 ), 'root_unique_counts' => array() );
	foreach ( $data['children'][0] as $root ) {
		$report[ $taxonomy ]['root_unique_counts'][ $root ] = $data['counts'][ $root ];
	}
	$branch = 'category' === $taxonomy ? $state['category_ids'][0] : $state['tag_ids'][0];
	$response = rest_do_request( new WP_REST_Request( 'GET', '/content-trees/v1/terms/' . $taxonomy . '/' . $branch . '/posts' ) );
	$actual_ids = array_map( 'intval', array_column( $response->get_data()['items'], 'id' ) );
	$expected_ids = array_keys( $direct[ $branch ] ?? array() );
	sort( $actual_ids );
	sort( $expected_ids );
	if ( 200 !== $response->get_status() || $actual_ids !== $expected_ids ) {
		throw new RuntimeException( 'REST branch membership mismatch for ' . $taxonomy );
	}
	$report[ $taxonomy ]['rest_branch_items'] = count( $actual_ids );
}
echo wp_json_encode( $report, JSON_PRETTY_PRINT ) . "\n";
