<?php
/** Clear only an explicitly dedicated Redis database for cold samples. */
if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}
require dirname( __DIR__, 2 ) . '/tests/integration/bootstrap.php';
if ( ! defined( 'CONTENT_TREES_BENCHMARK_SITE' ) || true !== CONTENT_TREES_BENCHMARK_SITE
	|| ! defined( 'CONTENT_TREES_BENCHMARK_DEDICATED_REDIS' ) || true !== CONTENT_TREES_BENCHMARK_DEDICATED_REDIS
	|| ! defined( 'WP_REDIS_HOST' ) || '127.0.0.1' !== WP_REDIS_HOST
	|| ! defined( 'WP_REDIS_DATABASE' ) || ! is_int( WP_REDIS_DATABASE ) || WP_REDIS_DATABASE < 1
	|| ! wp_using_ext_object_cache() ) {
	throw new RuntimeException( 'A marked benchmark site and explicitly dedicated loopback Redis database are required.' );
}
foreach ( array( 'WP_REDIS_SERVERS', 'WP_REDIS_CLUSTER', 'WP_REDIS_SHARDS', 'WP_REDIS_SENTINEL', 'WP_REDIS_PATH' ) as $unsafe_connection ) {
	if ( defined( $unsafe_connection ) ) {
		throw new RuntimeException( 'Only a single explicitly configured loopback Redis server is supported.' );
	}
}
if ( defined( 'WP_REDIS_SCHEME' ) && 'tcp' !== WP_REDIS_SCHEME ) {
	throw new RuntimeException( 'Only a loopback TCP Redis connection is supported.' );
}
$action = $argv[1] ?? '';
if ( 'flush' === $action ) {
	if ( ! wp_cache_flush() ) {
		throw new RuntimeException( 'Redis cache flush failed.' );
	}
} elseif ( 'status' !== $action ) {
	throw new RuntimeException( 'Expected status or flush.' );
}
echo wp_json_encode( array( 'external_object_cache' => true, 'class' => get_class( $GLOBALS['wp_object_cache'] ), 'redis_database' => WP_REDIS_DATABASE, 'action' => $action ) ) . "\n";
