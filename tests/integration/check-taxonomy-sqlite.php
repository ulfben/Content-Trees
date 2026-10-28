<?php
/** Exercise the actual SQLite integration on this repository's isolated fixture. */
$root = getenv( 'CONTENT_TREES_SQLITE_WP_ROOT' );
$expected_root = dirname( __DIR__ ) . '/results/sqlite-site';
if ( PHP_SAPI !== 'cli' || ! $root || realpath( $root ) !== realpath( $expected_root ) || ! is_file( $root . '/wp-load.php' ) ) {
	throw new RuntimeException( 'Use the repository-owned disposable SQLite site.' );
}
define( 'SAVEQUERIES', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8791';
require $root . '/wp-load.php';
if ( ! defined( 'CONTENT_TREES_SQLITE_DISPOSABLE' ) || ! CONTENT_TREES_SQLITE_DISPOSABLE || ! defined( 'DB_ENGINE' ) || 'sqlite' !== DB_ENGINE
	|| ! function_exists( 'ContentTrees\\render_taxonomy' ) || get_class( $wpdb ) === 'wpdb' ) {
	throw new RuntimeException( 'The guarded SQLite integration and Content Trees must be active.' );
}
$source = dirname( __DIR__, 2 ) . '/taxonomy/taxonomy.php';
if ( hash_file( 'sha256', $source ) !== hash_file( 'sha256', ( new ReflectionFunction( 'ContentTrees\\render_taxonomy' ) )->getFileName() ) ) {
	throw new RuntimeException( 'The installed taxonomy code differs from this checkout.' );
}
wp_set_current_user( 0 );
$terms = array();
$post_id = 0;
$notices = array();
$missing_revision = '__ctrees_sqlite_missing_revision__';
$revision_before = get_option( 'ctrees_taxonomy_count_revision', $missing_revision );
$threshold = static function () { return 0; };
set_error_handler( static function ( $severity, $message ) use ( &$notices ) { $notices[] = array( $severity, $message ); return true; } );
try {
	foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
		$term = wp_insert_term( 'SQLite cache fixture ' . wp_generate_uuid4(), $taxonomy );
		if ( is_wp_error( $term ) ) {
			throw new RuntimeException( $term->get_error_message() );
		}
		$terms[ $taxonomy ] = (int) $term['term_id'];
	}
	$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'SQLite cache fixture' ), true );
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		throw new RuntimeException( 'Could not create the SQLite test post.' );
	}
	foreach ( $terms as $taxonomy => $term_id ) {
		wp_set_object_terms( $post_id, array( $term_id ), $taxonomy );
	}
	add_filter( 'content_trees_taxonomy_cache_min_posts', $threshold );
	$args = array( 'auto_open' => false, 'show_posts' => true, 'show_counts' => true, 'hide_empty' => true );
	ob_start();
	try {
		foreach ( $terms as $taxonomy => $term_id ) {
			if ( ContentTrees\taxonomy_count_cache_safe( $taxonomy, ContentTrees\taxonomy_options( $taxonomy, $args ) ) ) {
				throw new RuntimeException( 'SQLite must bypass the snapshot even with the threshold lowered.' );
			}
			$first = ContentTrees\render_taxonomy( $taxonomy, $args );
			$second = ContentTrees\render_taxonomy( $taxonomy, $args );
			if ( $first !== $second || false === strpos( $first, 'SQLite cache fixture' ) || false !== get_transient( 'ctrees_taxonomy_counts_' . $taxonomy ) ) {
				throw new RuntimeException( 'SQLite render or cache bypass is incorrect.' );
			}
		}
		$output = ob_get_contents();
	} finally {
		ob_end_clean();
	}
	foreach ( $wpdb->queries as $query ) {
		if ( preg_match( '/\b(?:GET_LOCK|RELEASE_LOCK)\s*\(/i', $query[0] ) ) {
			throw new RuntimeException( 'SQLite received an unsupported lock query.' );
		}
	}
	if ( '' !== $output || $notices || $wpdb->last_error ) {
		throw new RuntimeException( 'SQLite rendering emitted output, a PHP notice, or a database error: ' . wp_json_encode( $notices ) );
	}
	echo 'PASS: ' . get_class( $wpdb ) . " rendered both trees twice with no snapshots, lock queries, notices or database errors.\n";
} finally {
	remove_filter( 'content_trees_taxonomy_cache_min_posts', $threshold );
	if ( $post_id && ! is_wp_error( $post_id ) ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( $terms as $taxonomy => $term_id ) {
		wp_delete_term( $term_id, $taxonomy );
	}
	if ( $missing_revision === $revision_before ) {
		delete_option( 'ctrees_taxonomy_count_revision' );
	} else {
		update_option( 'ctrees_taxonomy_count_revision', $revision_before, false );
	}
	restore_error_handler();
}
