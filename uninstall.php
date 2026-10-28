<?php
/** Remove only the disposable taxonomy count caches on uninstall. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$content_trees_clear_counts = static function () {
	foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
		$key = 'ctrees_taxonomy_counts_' . $taxonomy;
		delete_transient( $key );
		// An object cache may have been enabled after the database transient was written.
		delete_option( '_transient_' . $key );
		delete_option( '_transient_timeout_' . $key );
	}
	delete_option( 'ctrees_taxonomy_count_revision' );
};

if ( is_multisite() ) {
	$content_trees_offset = 0;
	do {
		$content_trees_sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $content_trees_offset ) );
		foreach ( $content_trees_sites as $content_trees_site_id ) {
			switch_to_blog( $content_trees_site_id );
			try {
				$content_trees_clear_counts();
			} finally {
				restore_current_blog();
			}
		}
		$content_trees_offset += count( $content_trees_sites );
	} while ( 100 === count( $content_trees_sites ) );
} else {
	$content_trees_clear_counts();
}
