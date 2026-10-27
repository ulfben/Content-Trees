<?php
/** Guard regressions use fake configuration files and never connect to a database. */
$root = dirname( __DIR__ );
$directory = __DIR__ . '/results/bootstrap-guard-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
	throw new RuntimeException( 'Cannot create bootstrap guard fixture.' );
}
$previous_root = getenv( 'CONTENT_TREES_TEST_WP_ROOT' );
$previous_host = getenv( 'CONTENT_TREES_TEST_DB_HOST' );
$cases = array(
	array( '', '127.0.0.1:33080', '', 'Set CONTENT_TREES_TEST_WP_ROOT' ),
	array( $directory, 'example.invalid:3306', '', 'loopback address and valid port' ),
	array( $directory, '127.0.0.1:99999', '', 'loopback address and valid port' ),
	array( $directory, '127.0.0.1:33081', "define('CONTENT_TREES_DISPOSABLE_TEST_SITE', false); define('DB_NAME', 'content_trees_test'); define('DB_HOST', '127.0.0.1:33081');", 'Refusing to run outside' ),
	array( $directory, '127.0.0.1:33081', "define('CONTENT_TREES_DISPOSABLE_TEST_SITE', true); define('DB_NAME', 'production'); define('DB_HOST', '127.0.0.1:33081');", 'Refusing to run outside' ),
	array( $directory, '127.0.0.1:33081', "define('CONTENT_TREES_DISPOSABLE_TEST_SITE', true); define('DB_NAME', 'content_trees_test'); define('DB_HOST', '127.0.0.1:33082');", 'Refusing to run outside' ),
	array( $directory, '127.0.0.1:33081', "define('CONTENT_TREES_DISPOSABLE_TEST_SITE', true); define('DB_NAME', 'content_trees_test'); define('DB_HOST', '127.0.0.1:33081');", 'Activate Content Trees' ),
);
try {
	foreach ( $cases as $case ) {
		file_put_contents( $directory . '/wp-load.php', '<?php ' . $case[2] );
		putenv( 'CONTENT_TREES_TEST_WP_ROOT=' . $case[0] );
		putenv( 'CONTENT_TREES_TEST_DB_HOST=' . $case[1] );
		$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', $root . '/tests/integration/bootstrap.php' ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot execute bootstrap guard check.' ); }
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );
		if ( 0 === $status || false === strpos( $output, $case[3] ) ) {
			throw new RuntimeException( 'Unexpected bootstrap guard result: ' . $output );
		}
	}
} finally {
	putenv( false === $previous_root ? 'CONTENT_TREES_TEST_WP_ROOT' : 'CONTENT_TREES_TEST_WP_ROOT=' . $previous_root );
	putenv( false === $previous_host ? 'CONTENT_TREES_TEST_DB_HOST' : 'CONTENT_TREES_TEST_DB_HOST=' . $previous_host );
	unlink( $directory . '/wp-load.php' );
	rmdir( $directory );
}
echo "PASS: explicit installation, loopback port, database name and marker guards. No database connections made.\n";
