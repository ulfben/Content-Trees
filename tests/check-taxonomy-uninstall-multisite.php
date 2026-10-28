<?php
/** Test batched uninstall traversal without touching a real multisite network. */
define( 'WP_UNINSTALL_PLUGIN', 'content-trees/content-trees.php' );
$fixture_sites = range( 1, 205 );
$current_site = 1;
$site_stack = array();
$deleted = array();
function is_multisite() { return true; }
function get_sites( $args ) {
	global $fixture_sites;
	return array_slice( $fixture_sites, $args['offset'], $args['number'] );
}
function switch_to_blog( $id ) {
	global $current_site, $site_stack;
	$site_stack[] = $current_site;
	$current_site = $id;
}
function restore_current_blog() {
	global $current_site, $site_stack;
	$current_site = array_pop( $site_stack );
}
function delete_transient( $key ) {
	global $current_site, $deleted;
	$deleted[ $current_site ][] = 'transient:' . $key;
}
function delete_option( $key ) {
	global $current_site, $deleted;
	$deleted[ $current_site ][] = 'option:' . $key;
}
require dirname( __DIR__ ) . '/uninstall.php';
$expected = array(
	'transient:ctrees_taxonomy_counts_category',
	'option:_transient_ctrees_taxonomy_counts_category',
	'option:_transient_timeout_ctrees_taxonomy_counts_category',
	'transient:ctrees_taxonomy_counts_post_tag',
	'option:_transient_ctrees_taxonomy_counts_post_tag',
	'option:_transient_timeout_ctrees_taxonomy_counts_post_tag',
	'option:ctrees_taxonomy_count_revision',
);
foreach ( $fixture_sites as $id ) {
	if ( ( $deleted[ $id ] ?? null ) !== $expected ) {
		throw new RuntimeException( 'Uninstall missed a site or removed an unexpected option.' );
	}
}
if ( $site_stack || 1 !== $current_site || count( $deleted ) !== count( $fixture_sites ) ) {
	throw new RuntimeException( 'Uninstall did not restore the multisite context.' );
}
echo "PASS: multisite uninstall removes only the intended keys on all 205 sites and restores context.\n";
