<?php
/** Only load a separately installed, explicitly marked disposable WordPress site. */
if ( PHP_SAPI !== 'cli' ) {
	exit( 2 ); }
defined( 'WP_INSTALLING' ) || define( 'WP_INSTALLING', false );
$wp_root = getenv( 'CONTENT_TREES_TEST_WP_ROOT' );
if ( ! $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	throw new RuntimeException( 'Set CONTENT_TREES_TEST_WP_ROOT to a marked disposable WordPress installation.' );
}
$expected_host = getenv( 'CONTENT_TREES_TEST_DB_HOST' ) ?: '127.0.0.1:33080';
if ( ! preg_match( '/^127\.0\.0\.1:([1-9][0-9]{0,4})$/', $expected_host, $port ) || (int) $port[1] > 65535 ) {
	throw new RuntimeException( 'CONTENT_TREES_TEST_DB_HOST must name a loopback address and valid port.' );
}
define( 'CONTENT_TREES_TEST_DB_HOST', $expected_host );
require $wp_root . '/wp-load.php';
if ( ! defined( 'CONTENT_TREES_DISPOSABLE_TEST_SITE' ) || ! CONTENT_TREES_DISPOSABLE_TEST_SITE
	|| ! in_array( DB_NAME, array( 'content_trees_test', 'content_trees_minimum' ), true )
	|| DB_HOST !== CONTENT_TREES_TEST_DB_HOST ) {
	throw new RuntimeException( 'Refusing to run outside the isolated Content Trees test database.' );
}
if ( ! function_exists( 'ContentTrees\\render' ) ) {
	throw new RuntimeException( 'Activate Content Trees on the disposable site first.' ); }
$source = dirname( __DIR__, 2 );
if ( realpath( ( new ReflectionFunction( 'ContentTrees\\render' ) )->getFileName() ) !== realpath( $source . '/archive.php' ) ) {
	throw new RuntimeException( 'The fixture loaded a different Content Trees checkout.' );
}
$results = dirname( __DIR__ ) . '/results';
if ( ! is_dir( $results ) && ! mkdir( $results, 0777, true ) && ! is_dir( $results ) ) {
	throw new RuntimeException( 'Cannot create test artifacts directory.' ); }
wp_set_current_user( 0 );
