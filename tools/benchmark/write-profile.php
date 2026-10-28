<?php
/** Isolate database-backed transient write and serialization costs. */
require dirname( __DIR__, 2 ) . '/tests/integration/bootstrap.php';
if ( ! defined( 'CONTENT_TREES_BENCHMARK_SITE' ) || true !== CONTENT_TREES_BENCHMARK_SITE || wp_using_ext_object_cache() ) {
	throw new RuntimeException( 'A marked benchmark site without an external object cache is required.' );
}
$out = array();
foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
	$value = get_transient( 'ctrees_taxonomy_counts_' . $taxonomy );
	$key = 'ctrees_benchmark_write_probe_' . $taxonomy;
	if ( false === $value || false !== get_transient( $key ) ) {
		throw new RuntimeException( 'Warm the snapshots first and remove any abandoned probe explicitly.' );
	}
	$writes = array();
	$serializations = array();
	try {
		for ( $i = 0; $i < 55; ++$i ) {
			delete_transient( $key );
			$start = hrtime( true );
			$serialized = serialize( $value );
			$serialization_ms = ( hrtime( true ) - $start ) / 1e6;
			$start = hrtime( true );
			$ok = set_transient( $key, $value, HOUR_IN_SECONDS );
			$write_ms = ( hrtime( true ) - $start ) / 1e6;
			if ( ! $ok ) {
				throw new RuntimeException( 'Probe write failed.' );
			}
			if ( $i >= 5 ) {
				$writes[] = $write_ms;
				$serializations[] = $serialization_ms;
			}
		}
		$out[ $taxonomy ] = array( 'serialized_bytes' => strlen( $serialized ), 'write_ms' => $writes, 'serialize_ms' => $serializations );
	} finally {
		delete_transient( $key );
	}
}
echo wp_json_encode( $out, JSON_PRETTY_PRINT ) . "\n";
