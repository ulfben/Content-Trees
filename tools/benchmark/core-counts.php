<?php
/** Compare core's stored direct counts with this plugin's eligibility rules. */
require dirname( __DIR__, 2 ) . '/tests/integration/bootstrap.php';
if ( ! defined( 'CONTENT_TREES_BENCHMARK_SITE' ) || true !== CONTENT_TREES_BENCHMARK_SITE ) {
	throw new RuntimeException( 'A separately marked benchmark installation is required.' );
}
$state = get_option( 'ctrees_benchmark_dataset_v1' );
if ( ! is_array( $state ) || empty( $state['complete'] ) ) {
	throw new RuntimeException( 'A complete dataset is required.' );
}
global $wpdb;
$result = array( 'dataset' => $state['size'], 'taxonomies' => array() );
foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
	$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'hierarchical' => false, 'number' => 0 ) );
	$data = ContentTrees\taxonomy_data( $taxonomy, array( 'auto_open' => false, 'hide_empty' => false ) );
	$counts = $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_id, COUNT(DISTINCT p.ID) AS n FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id=tt.term_taxonomy_id JOIN {$wpdb->posts} p ON p.ID=tr.object_id WHERE tt.taxonomy=%s AND p.post_type='post' AND p.post_status='publish' AND p.post_password='' GROUP BY tt.term_id", $taxonomy ), OBJECT_K );
	$direct_mismatches = $subtree_mismatches = 0;
	$examples = array();
	foreach ( $terms as $term ) {
		$direct = isset( $counts[ $term->term_id ] ) ? (int) $counts[ $term->term_id ]->n : 0;
		$subtree = $data['counts'][ $term->term_id ];
		if ( (int) $term->count !== $direct ) {
			++$direct_mismatches;
		}
		if ( (int) $term->count !== $subtree ) {
			++$subtree_mismatches;
		}
		if ( count( $examples ) < 5 && ( (int) $term->count !== $direct || (int) $term->count !== $subtree ) ) {
			$examples[] = array( 'term_id' => $term->term_id, 'core_direct' => (int) $term->count, 'eligible_direct' => $direct, 'plugin_count' => $subtree );
		}
	}
	$result['taxonomies'][ $taxonomy ] = array( 'terms' => count( $terms ), 'direct_mismatches' => $direct_mismatches, 'plugin_count_mismatches' => $subtree_mismatches, 'examples' => $examples );
}
echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . "\n";
