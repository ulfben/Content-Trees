<?php
/** Local-only benchmark endpoint; run with PHP's built-in HTTP server. */
if ( 'cli-server' !== PHP_SAPI || ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true ) ) {
	http_response_code( 403 );
	exit;
}
$root = getenv( 'CONTENT_TREES_TEST_WP_ROOT' );
$host = getenv( 'CONTENT_TREES_TEST_DB_HOST' ) ?: '127.0.0.1:33080';
if ( ! $root || ! is_file( $root . '/wp-load.php' ) || ! preg_match( '/^127\.0\.0\.1:([1-9][0-9]{0,4})$/', $host, $match ) || (int) $match[1] > 65535 ) {
	throw new RuntimeException( 'Invalid benchmark site or loopback database host.' );
}
$diagnostic = isset( $_GET['diagnostic'] ) && '1' === $_GET['diagnostic'];
if ( $diagnostic ) {
	define( 'SAVEQUERIES', true );
}
define( 'WP_USE_THEMES', false );
require $root . '/wp-load.php';
if ( ! defined( 'CONTENT_TREES_DISPOSABLE_TEST_SITE' ) || true !== CONTENT_TREES_DISPOSABLE_TEST_SITE
	|| ! defined( 'CONTENT_TREES_BENCHMARK_SITE' ) || true !== CONTENT_TREES_BENCHMARK_SITE
	|| ! in_array( DB_NAME, array( 'content_trees_test', 'content_trees_minimum' ), true ) || DB_HOST !== $host
	|| ! function_exists( 'ContentTrees\\render_taxonomy' )
	|| realpath( ( new ReflectionFunction( 'ContentTrees\\render_taxonomy' ) )->getFileName() ) !== realpath( dirname( __DIR__, 2 ) . '/taxonomy.php' ) ) {
	throw new RuntimeException( 'Refusing an unguarded site or a different plugin checkout.' );
}
if ( ( defined( 'WP_CACHE' ) && WP_CACHE ) || is_file( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
	throw new RuntimeException( 'Disable full-page caching before benchmarking.' );
}
foreach ( get_option( 'active_plugins', array() ) as $plugin ) {
	if ( ! in_array( $plugin, array( 'content-trees/content-trees.php', 'redis-cache/redis-cache.php' ), true ) ) {
		throw new RuntimeException( 'Unexpected active plugin: ' . $plugin );
	}
}
$dataset = get_option( 'ctrees_benchmark_dataset_v1' );
if ( ! is_array( $dataset ) || empty( $dataset['complete'] ) ) {
	throw new RuntimeException( 'The marked dataset is not complete.' );
}
wp_set_current_user( 0 );
$scenario = $_GET['scenario'] ?? '';
$valid = array( 'category-closed', 'tag-closed', 'both-closed', 'category-names', 'tag-names', 'both-names', 'category-open', 'tag-open', 'both-open', 'category-rest', 'tag-rest' );
if ( ! in_array( $scenario, $valid, true ) ) {
	http_response_code( 400 );
	exit( 'Unknown scenario.' );
}
$taxonomy = 0 === strpos( $scenario, 'tag-' ) ? 'post_tag' : 'category';
$mode = substr( $scenario, strrpos( $scenario, '-' ) + 1 );
$options = array( 'auto_open' => false, 'show_posts' => true, 'show_counts' => true, 'hide_empty' => true );
if ( 'names' === $mode ) {
	$options['show_posts'] = $options['show_counts'] = $options['hide_empty'] = false;
}
$before_queries = $wpdb->num_queries;
$started = microtime( true );
if ( 'rest' === $mode ) {
	$id = 'category' === $taxonomy ? $dataset['category_ids'][0] : $dataset['tag_ids'][0];
	$route = '/content-trees/v1/terms/' . $taxonomy . '/' . $id . '/posts';
	$callback_started = null;
	$callback_elapsed = null;
	add_filter( 'rest_request_before_callbacks', static function ( $response, $handler, $request ) use ( $route, &$callback_started ) {
		if ( $request->get_route() === $route ) {
			$callback_started = microtime( true );
		}
		return $response;
	}, 10, 3 );
	add_filter( 'rest_request_after_callbacks', static function ( $response, $handler, $request ) use ( $route, &$callback_started, &$callback_elapsed ) {
		if ( $request->get_route() === $route && null !== $callback_started ) {
			$callback_elapsed = microtime( true ) - $callback_started;
		}
		return $response;
	}, 10, 3 );
	ob_start();
	rest_get_server()->serve_request( $route );
	$body = ob_get_clean();
	if ( 200 !== http_response_code() || null === $callback_elapsed ) {
		throw new RuntimeException( 'REST branch request did not complete successfully.' );
	}
} else {
	$taxonomies = 0 === strpos( $scenario, 'both-' ) ? array( 'category', 'post_tag' ) : array( $taxonomy );
	$body = '';
	foreach ( $taxonomies as $tax ) {
		$current_options = $options;
		if ( 'open' === $mode ) {
			$current_options['open_to'] = 'category' === $tax ? $dataset['category_ids'][0] : $dataset['tag_ids'][0];
		}
		$body .= ContentTrees\render_taxonomy( $tax, $current_options );
	}
	header( 'Content-Type: text/html; charset=UTF-8' );
}
$elapsed = 'rest' === $mode ? $callback_elapsed : microtime( true ) - $started;
$metrics = array( 'scenario' => $scenario, 'dataset' => $dataset['size'], 'plugin_seconds' => $elapsed, 'plugin_queries' => $wpdb->num_queries - $before_queries, 'queries' => $wpdb->num_queries, 'peak_bytes' => memory_get_peak_usage( true ), 'response_bytes' => strlen( $body ), 'external_object_cache' => (bool) wp_using_ext_object_cache() );
header( 'Cache-Control: no-store' );
if ( $diagnostic ) {
	$queries = array();
	foreach ( $wpdb->queries as $query ) {
		$queries[] = array( 'sql' => $query[0], 'seconds' => $query[1], 'caller' => $query[2] );
	}
	$settings = array();
	foreach ( 0 === strpos( $scenario, 'both-' ) ? array( 'category', 'post_tag' ) : array( $taxonomy ) as $tax ) {
		$current_options = $options;
		if ( 'open' === $mode ) {
			$current_options['open_to'] = 'category' === $tax ? $dataset['category_ids'][0] : $dataset['tag_ids'][0];
		}
		$settings[ $tax ] = 'rest' === $mode ? array( 'sort_by' => 'date', 'order' => 'DESC', 'excluded_posts' => '', 'term_id' => $id ) : ContentTrees\taxonomy_options( $tax, $current_options );
	}
	header( 'Content-Type: application/json; charset=UTF-8' );
	echo wp_json_encode( array( 'metrics' => $metrics, 'settings' => $settings, 'queries' => $queries, 'environment' => array( 'wordpress' => $GLOBALS['wp_version'], 'plugin' => ContentTrees\VERSION, 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'database' => $wpdb->db_version(), 'memory_limit' => ini_get( 'memory_limit' ), 'opcache_cli' => ini_get( 'opcache.enable_cli' ), 'object_cache_class' => get_class( $GLOBALS['wp_object_cache'] ) ) ) );
} else {
	header( 'X-Content-Trees-Benchmark: ' . base64_encode( wp_json_encode( $metrics ) ) );
	echo $body;
}
