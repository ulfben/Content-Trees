<?php
/** Check scoped cache cleanup on a guarded disposable WordPress site. */
require __DIR__ . '/bootstrap.php';
if ( is_multisite() || wp_using_ext_object_cache() ) {
	throw new RuntimeException( 'This check needs a single-site fixture with the default object cache.' );
}
$names = array( 'ctrees_taxonomy_count_revision', 'ctrees_uninstall_test_sentinel' );
foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
	$names[] = '_transient_ctrees_taxonomy_counts_' . $taxonomy;
	$names[] = '_transient_timeout_ctrees_taxonomy_counts_' . $taxonomy;
}
$missing = '__ctrees_option_does_not_exist__';
$before = array();
foreach ( $names as $name ) {
	$before[ $name ] = get_option( $name, $missing );
}
define( 'WP_UNINSTALL_PLUGIN', 'content-trees/content-trees.php' );
try {
	foreach ( array( false, true ) as $external ) {
		wp_using_ext_object_cache( false );
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$key = 'ctrees_taxonomy_counts_' . $taxonomy;
			set_transient( $key, array( 'fixture' => true ), HOUR_IN_SECONDS );
			if ( (int) get_option( '_transient_timeout_' . $key ) <= time() ) {
				throw new RuntimeException( 'The snapshot needs an expiry.' );
			}
		}
		update_option( 'ctrees_taxonomy_count_revision', 'fixture-revision', false );
		update_option( 'ctrees_uninstall_test_sentinel', 'preserved', false );
		wp_using_ext_object_cache( $external );
		require dirname( __DIR__, 2 ) . '/uninstall.php';
		wp_using_ext_object_cache( false );
		foreach ( $names as $name ) {
			if ( 'ctrees_uninstall_test_sentinel' !== $name && $missing !== get_option( $name, $missing ) ) {
				throw new RuntimeException( 'Uninstall left cache option ' . $name );
			}
		}
		if ( 'preserved' !== get_option( 'ctrees_uninstall_test_sentinel' ) ) {
			throw new RuntimeException( 'Uninstall removed an unrelated option.' );
		}
		echo 'PASS: cache cleanup with ' . ( $external ? 'external' : 'default' ) . " object-cache mode.\n";
	}
} finally {
	wp_using_ext_object_cache( false );
	foreach ( $before as $name => $value ) {
		if ( $missing === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value, false );
		}
	}
}
