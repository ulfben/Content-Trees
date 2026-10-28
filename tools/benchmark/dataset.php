<?php
/** Create or remove only the marked benchmark dataset on a guarded test site. */
if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}
require dirname( __DIR__, 2 ) . '/tests/integration/bootstrap.php';

$operation = $argv[1] ?? '';
$size = $argv[2] ?? '';
if ( ! in_array( $operation, array( 'create', 'remove' ), true ) || ( 'create' === $operation && ! in_array( $size, array( 'small', 'large' ), true ) ) ) {
	fwrite( STDERR, "Usage: php tools/benchmark/dataset.php create small|large OR remove\n" );
	exit( 2 );
}
// A separate installation is essential: all standard posts and terms otherwise
// contribute to the public query and distort both sizes and timings.
if ( ! defined( 'CONTENT_TREES_BENCHMARK_SITE' ) || true !== CONTENT_TREES_BENCHMARK_SITE ) {
	throw new RuntimeException( 'A separately marked benchmark installation is required.' );
}
global $wpdb;
$marker = 'ctrees_benchmark_dataset_v1';
$prefix = 'ctbench-v1-';
$state = get_option( $marker, false );
$find_posts = static function () use ( $wpdb, $prefix ) {
	return $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
};
$find_terms = static function ( $taxonomy ) use ( $wpdb, $prefix ) {
	return $wpdb->get_col( $wpdb->prepare( "SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy=%s AND t.slug LIKE %s", $taxonomy, $wpdb->esc_like( $prefix ) . '%' ) );
};
if ( 'remove' === $operation ) {
	if ( ! is_array( $state ) || ( $state['prefix'] ?? '' ) !== $prefix ) {
		throw new RuntimeException( 'No matching benchmark marker; refusing cleanup.' );
	}
	wp_defer_term_counting( true );
	try {
		$removed = 0;
		foreach ( $find_posts() as $id ) {
			if ( ! wp_delete_post( (int) $id, true ) ) {
				throw new RuntimeException( 'Could not remove benchmark post ' . $id );
			}
			++$removed;
			if ( 0 === $removed % 1000 ) {
				wp_defer_term_counting( false );
				wp_defer_term_counting( true );
				if ( ! wp_using_ext_object_cache() ) {
					wp_cache_flush();
				}
				fwrite( STDERR, $removed . " posts removed\n" );
			}
		}
		foreach ( array( 'post_tag', 'category' ) as $taxonomy ) {
			foreach ( array_reverse( $find_terms( $taxonomy ) ) as $id ) {
				$result = wp_delete_term( (int) $id, $taxonomy );
				if ( is_wp_error( $result ) || ! $result ) {
					throw new RuntimeException( 'Could not remove benchmark term ' . $id );
				}
			}
		}
	} finally {
		wp_defer_term_counting( false );
	}
	delete_option( $marker );
	echo "Removed marked benchmark data.\n";
	exit;
}
if ( $state || $find_posts() || $find_terms( 'category' ) || $find_terms( 'post_tag' ) ) {
	throw new RuntimeException( 'A marker or matching data already exists; inspect it before retrying.' );
}
$other_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post'" );
$other_terms = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('category','post_tag')" );
if ( $other_posts || $other_terms > 1 ) {
	throw new RuntimeException( 'Use an otherwise empty installation with only the default category.' );
}
$spec = 'large' === $size ? array( 20000, 300, 3000 ) : array( 500, 20, 100 );
$state = array( 'prefix' => $prefix, 'size' => $size, 'seed' => 1729, 'complete' => false );
if ( ! add_option( $marker, $state, '', false ) ) {
	throw new RuntimeException( 'Unable to establish dataset ownership marker.' );
}
mt_srand( 1729 );
$categories = $tags = array();
$distribution = array( 'published' => 0, 'draft' => 0, 'password' => 0, 'category_memberships' => 0, 'tag_memberships' => 0 );
wp_defer_term_counting( true );
try {
	foreach ( array( 'category' => $spec[1], 'post_tag' => $spec[2] ) as $taxonomy => $count ) {
		for ( $i = 0; $i < $count; ++$i ) {
			$parent = 0;
			if ( 'category' === $taxonomy && $i > 0 ) {
				// One six-level chain plus uneven, shallow branches.
				$parent = $i < 6 ? $categories[ $i - 1 ] : $categories[ ( $i - 6 ) % min( 6, count( $categories ) ) ];
			}
			$slug = $prefix . ( 'category' === $taxonomy ? 'category-' : 'tag-' ) . $i;
			$result = wp_insert_term( 'Benchmark ' . $taxonomy . ' ' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT ), $taxonomy, array( 'slug' => $slug, 'parent' => $parent ) );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}
			if ( 'category' === $taxonomy ) {
				$categories[] = (int) $result['term_id'];
			} else {
				$tags[] = (int) $result['term_id'];
			}
		}
	}
	for ( $i = 0; $i < $spec[0]; ++$i ) {
		$status = 0 === $i % 50 ? 'draft' : 'publish';
		$password = 1 === $i % 50 ? 'benchmark' : '';
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => $status, 'post_password' => $password, 'post_title' => 'Benchmark post ' . $i, 'post_name' => $prefix . 'post-' . $i ), true );
		if ( is_wp_error( $id ) || ! $id ) {
			throw new RuntimeException( 'Could not create benchmark post ' . $i );
		}
		++$distribution[ 'draft' === $status ? 'draft' : ( '' !== $password ? 'password' : 'published' ) ];
		// Reserve the final ten percent of terms as empty. Most posts are
		// concentrated in a small set of terms, with overlapping assignments.
		$cat_limit = (int) floor( count( $categories ) * 0.9 );
		$tag_limit = (int) floor( count( $tags ) * 0.9 );
		$cats = array_unique( array( $categories[ $i % 10 < 8 ? mt_rand( 0, min( 5, $cat_limit - 1 ) ) : mt_rand( 0, $cat_limit - 1 ) ], $categories[ mt_rand( 0, $cat_limit - 1 ) ] ) );
		$post_tags = array_unique( array( $tags[ $i % 10 < 8 ? mt_rand( 0, min( 9, $tag_limit - 1 ) ) : mt_rand( 0, $tag_limit - 1 ) ], $tags[ mt_rand( 0, $tag_limit - 1 ) ], $tags[ mt_rand( 0, $tag_limit - 1 ) ] ) );
		foreach ( array( 'category' => $cats, 'post_tag' => $post_tags ) as $taxonomy => $assigned ) {
			$result = wp_set_object_terms( $id, array_values( $assigned ), $taxonomy, false );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}
		}
		$distribution['category_memberships'] += count( $cats );
		$distribution['tag_memberships'] += count( $post_tags );
		if ( 0 === ( $i + 1 ) % 1000 ) {
			wp_defer_term_counting( false );
			wp_defer_term_counting( true );
			if ( ! wp_using_ext_object_cache() ) {
				wp_cache_flush();
			}
		}
		if ( 0 === ( $i + 1 ) % 1000 ) {
			fwrite( STDERR, ( $i + 1 ) . " posts created\n" );
		}
	}
} finally {
	wp_defer_term_counting( false );
}
$state += array( 'posts' => $spec[0], 'categories' => $spec[1], 'tags' => $spec[2], 'distribution' => $distribution, 'category_ids' => $categories, 'tag_ids' => $tags );
$state['complete'] = true;
update_option( $marker, $state, false );
echo wp_json_encode( $state, JSON_PRETTY_PRINT ) . "\n";
