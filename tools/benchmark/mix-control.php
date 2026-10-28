<?php
/** Mutate and restore one owned post on the guarded benchmark fixture. */
require dirname( __DIR__, 2 ) . '/tests/integration/bootstrap.php';
if ( ! defined( 'CONTENT_TREES_BENCHMARK_SITE' ) || true !== CONTENT_TREES_BENCHMARK_SITE || wp_using_ext_object_cache() ) {
	throw new RuntimeException( 'A marked benchmark site without an external object cache is required.' );
}
$command = $argv[1] ?? '';
$key = 'ctrees_benchmark_mix_mutation';
$marker = get_option( $key, false );
if ( 'start' === $command ) {
	if ( false !== $marker ) {
		throw new RuntimeException( 'A previous benchmark mutation needs restoration.' );
	}
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND post_password='' AND post_name LIKE %s ORDER BY ID LIMIT 1", $wpdb->esc_like( 'ctbench-v1-post-' ) . '%' ) );
	if ( ! $id || ! add_option( $key, array( 'id' => $id, 'password' => '' ), '', false ) ) {
		throw new RuntimeException( 'Cannot reserve a benchmark-owned post.' );
	}
	echo "started\n";
} elseif ( in_array( $command, array( 'toggle', 'restore' ), true ) ) {
	if ( ! is_array( $marker ) || empty( $marker['id'] ) || ! isset( $marker['password'] ) ) {
		throw new RuntimeException( 'Benchmark mutation marker missing.' );
	}
	$post = get_post( (int) $marker['id'] );
	if ( ! $post || 0 !== strpos( $post->post_name, 'ctbench-v1-post-' ) || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		throw new RuntimeException( 'The reserved benchmark post changed unexpectedly.' );
	}
	$password = 'restore' === $command ? $marker['password'] : ( '' === $post->post_password ? 'ctbench-mix' : '' );
	if ( $password !== $post->post_password ) {
		$before = get_option( 'ctrees_taxonomy_count_revision', false );
		$result = wp_update_post( array( 'ID' => $post->ID, 'post_password' => $password ), true );
		if ( is_wp_error( $result ) || ! $result || $before === get_option( 'ctrees_taxonomy_count_revision', false ) ) {
			throw new RuntimeException( 'The eligible-post mutation did not invalidate the cache.' );
		}
	}
	if ( 'restore' === $command ) {
		delete_option( $key );
	}
	echo $command . "d\n";
} else {
	throw new RuntimeException( 'Use start, toggle, or restore.' );
}
