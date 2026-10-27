<?php
/** Only load a separately installed, explicitly marked disposable WordPress site. */
if ( PHP_SAPI !== 'cli' ) {
	exit( 2 ); }
defined( 'WP_INSTALLING' ) || define( 'WP_INSTALLING', false );
$wp_root = getenv( 'CONTENT_TREES_TEST_WP_ROOT' );
if ( $wp_root ) {
	if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
		throw new RuntimeException( 'Missing disposable WordPress installation.' ); }
	require $wp_root . '/wp-load.php';
} else {
	$workspace = dirname( __DIR__, 4 );
	if ( ( $argv[1] ?? '' ) === 'minimum' ) {
		require $workspace . '/maintenance/check-results/content-trees/compatibility/wp63/wordpress/wp-load.php';
	} else {
		$bootstrap = $workspace . '/tools/content-trees/bootstrap.php';
		if ( ! is_file( $bootstrap ) ) {
			throw new RuntimeException( 'Set CONTENT_TREES_TEST_WP_ROOT to a marked disposable WordPress installation.' ); }
		require $bootstrap;
	}
}
if ( ! defined( 'CONTENT_TREES_DISPOSABLE_TEST_SITE' ) || ! CONTENT_TREES_DISPOSABLE_TEST_SITE
	|| ! in_array( DB_NAME, array( 'content_trees_test', 'content_trees_minimum' ), true )
	|| DB_HOST !== '127.0.0.1:33080' ) {
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
