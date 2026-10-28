<?php
/** Compare the recorded pair of SELECTs with a subquery on a disposable site. */
if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}
require dirname( __DIR__, 2 ) . '/tests/integration/bootstrap.php';
if ( ! defined( 'CONTENT_TREES_BENCHMARK_SITE' ) || true !== CONTENT_TREES_BENCHMARK_SITE ) {
	throw new RuntimeException( 'A separately marked benchmark installation is required.' );
}
$file = realpath( $argv[1] ?? '' );
$results = realpath( dirname( __DIR__, 2 ) . '/tests/results/benchmark' );
if ( ! $file || ! $results || 0 !== strpos( $file, $results . DIRECTORY_SEPARATOR ) || ! preg_match( '/^(small|large)-none-.*\.json$/', basename( $file ) ) ) {
	throw new RuntimeException( 'Supply a no-cache raw result inside tests/results/benchmark.' );
}
$run = json_decode( file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
$state = get_option( 'ctrees_benchmark_dataset_v1' );
if ( ! is_array( $state ) || empty( $state['complete'] ) ) {
	throw new RuntimeException( 'A complete dataset is required.' );
}
global $wpdb;
$out = array( 'dataset' => $state['size'], 'database' => $wpdb->db_version(), 'warmups' => 5, 'samples' => 20, 'scenarios' => array() );
foreach ( array( 'category-closed', 'tag-closed' ) as $scenario ) {
	$diagnostics = array_values( array_filter( $run['diagnostics'], static function ( $d ) use ( $scenario, $state ) {
		return $d['metrics']['scenario'] === $scenario && $d['metrics']['dataset'] === $state['size'];
	} ) );
	if ( ! $diagnostics ) {
		throw new RuntimeException( 'Missing matching diagnostics for ' . $scenario );
	}
	$ids_sql = $membership_sql = null;
	foreach ( $diagnostics[0]['queries'] as $query ) {
		if ( preg_match( '/WP_Query->get_posts$/', $query['caller'] ) && preg_match( '/^SELECT\s+' . preg_quote( $wpdb->posts, '/' ) . '\.ID\s+FROM\s/i', $query['sql'] ) ) {
			$ids_sql = $query['sql'];
		}
		if ( preg_match( '/ContentTrees\\\\taxonomy_membership_rows$/', $query['caller'] ) ) {
			$membership_sql = $query['sql'];
		}
	}
	if ( ! $ids_sql || ! $membership_sql || preg_match( '/;|\/\*|--|\b(?:INTO|SLEEP|BENCHMARK|LOAD_FILE)\b/i', $ids_sql . $membership_sql ) ) {
		throw new RuntimeException( 'Unexpected recorded SQL; refusing to execute it.' );
	}
	$term_ids = 'category-closed' === $scenario ? $state['category_ids'] : $state['tag_ids'];
	if ( ! preg_match( '/tt\.term_id IN \(([0-9,\s]+)\)/', $membership_sql, $matches )
		|| ! in_array( (int) $term_ids[0], array_map( 'intval', explode( ',', $matches[1] ) ), true ) ) {
		throw new RuntimeException( 'Recorded SQL belongs to a different generated dataset.' );
	}
	$candidate = preg_replace( '/tr\.object_id IN \([0-9,\s]+\)/', 'tr.object_id IN (' . $ids_sql . ')', $membership_sql, 1, $replacements );
	if ( 1 !== $replacements ) {
		throw new RuntimeException( 'Could not identify the membership ID list.' );
	}
	$run_query = static function ( $sql ) use ( $wpdb ) {
		$rows = $wpdb->get_results( $sql, ARRAY_N );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( $wpdb->last_error );
		}
		return $rows;
	};
	$baseline_rows = $run_query( $membership_sql );
	$candidate_rows = $run_query( $candidate );
	sort( $baseline_rows );
	sort( $candidate_rows );
	if ( $baseline_rows !== $candidate_rows ) {
		throw new RuntimeException( 'Subquery changed membership rows for ' . $scenario );
	}
	$count = count( $baseline_rows );
	if ( 0 === $count ) {
		throw new RuntimeException( 'The recorded queries returned no memberships; check the dataset.' );
	}
	unset( $baseline_rows, $candidate_rows );
	$timings = array( 'baseline_ms' => array(), 'candidate_ms' => array() );
	for ( $i = 0; $i < 25; ++$i ) {
		$order = 0 === $i % 2 ? array( 'baseline', 'candidate' ) : array( 'candidate', 'baseline' );
		foreach ( $order as $variant ) {
			$start = microtime( true );
			if ( 'baseline' === $variant ) {
				$wpdb->get_col( $ids_sql );
				$run_query( $membership_sql );
			} else {
				$run_query( $candidate );
			}
			if ( $i >= 5 ) {
				$timings[ $variant . '_ms' ][] = ( microtime( true ) - $start ) * 1000;
			}
		}
	}
	$out['scenarios'][ $scenario ] = array( 'equal_rows' => $count, 'baseline_sql_bytes' => strlen( $ids_sql ) + strlen( $membership_sql ), 'candidate_sql_bytes' => strlen( $candidate ), 'baseline_id_plan' => $run_query( 'EXPLAIN ' . $ids_sql ), 'baseline_membership_plan' => $run_query( 'EXPLAIN ' . $membership_sql ), 'candidate_plan' => $run_query( 'EXPLAIN ' . $candidate ), 'timings' => $timings );
}
echo wp_json_encode( $out, JSON_PRETTY_PRINT ) . "\n";
