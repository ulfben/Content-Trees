<?php
/** Clear only the two prototype snapshots on the marked benchmark site. */
require dirname( __DIR__, 2 ) . '/tests/integration/bootstrap.php';
if ( ! defined( 'CONTENT_TREES_BENCHMARK_SITE' ) || true !== CONTENT_TREES_BENCHMARK_SITE || wp_using_ext_object_cache() ) {
	throw new RuntimeException( 'A marked benchmark site without an external object cache is required.' );
}
delete_transient( 'ctrees_taxonomy_counts_category' );
delete_transient( 'ctrees_taxonomy_counts_post_tag' );
echo "cleared\n";
