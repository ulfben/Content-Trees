<?php
/** Count-cache parity, invalidation and context isolation on a disposable site. */
require __DIR__ . '/bootstrap.php';
if ( wp_using_ext_object_cache() ) {
	throw new RuntimeException( 'These tests require the request-local object cache.' );
}
$revision_before = get_option( 'ctrees_taxonomy_count_revision', '__ctrees_missing__' );
$saved_snapshots = array();
foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
	$key = 'ctrees_taxonomy_counts_' . $taxonomy;
	$saved_snapshots[ $key ] = array( 'value' => get_transient( $key ), 'expires' => (int) get_option( '_transient_timeout_' . $key, 0 ) );
}
$terms = $posts = array();
$user = 0;
$checks = 0;
$small_fixture_threshold = static function () { return 0; };
add_filter( 'content_trees_taxonomy_cache_min_posts', $small_fixture_threshold );
function count_cache_check( $condition, $message ) {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
	echo "PASS: $message\n";
}
function count_cache_compare( $taxonomy, $args ) {
	$noop = static function () {};
	add_action( 'pre_get_posts', $noop );
	try {
		$uncached = ContentTrees\render_taxonomy( $taxonomy, $args );
	} finally {
		remove_action( 'pre_get_posts', $noop );
	}
	$cold = ContentTrees\render_taxonomy( $taxonomy, $args );
	$snapshot = get_transient( 'ctrees_taxonomy_counts_' . $taxonomy );
	count_cache_check( is_array( $snapshot ) && ! empty( $snapshot['entries'] ), "$taxonomy count snapshot was created" );
	$warm = ContentTrees\render_taxonomy( $taxonomy, $args );
	count_cache_check( $uncached === $cold && $cold === $warm, "$taxonomy cold and warm HTML match the uncached output" );
}
function count_cache_mutate( $name, $callback, $category_args, $tag_args ) {
	count_cache_compare( 'category', $category_args );
	count_cache_compare( 'post_tag', $tag_args );
	$revision = get_option( 'ctrees_taxonomy_count_revision' );
	$callback();
	count_cache_check( get_option( 'ctrees_taxonomy_count_revision' ) !== $revision, "$name changes the durable revision" );
	count_cache_check( false === get_transient( 'ctrees_taxonomy_counts_category' ) && false === get_transient( 'ctrees_taxonomy_counts_post_tag' ), "$name removes both old snapshots" );
	count_cache_compare( 'category', $category_args );
	count_cache_compare( 'post_tag', $tag_args );
}
function count_cache_unchanged( $name, $callback, $category_args, $tag_args ) {
	count_cache_compare( 'category', $category_args );
	count_cache_compare( 'post_tag', $tag_args );
	$revision = get_option( 'ctrees_taxonomy_count_revision' );
	$category = get_transient( 'ctrees_taxonomy_counts_category' );
	$tag = get_transient( 'ctrees_taxonomy_counts_post_tag' );
	$callback();
	count_cache_check( get_option( 'ctrees_taxonomy_count_revision' ) === $revision, "$name leaves the durable revision unchanged" );
	count_cache_check( get_transient( 'ctrees_taxonomy_counts_category' ) === $category && get_transient( 'ctrees_taxonomy_counts_post_tag' ) === $tag, "$name preserves both snapshots" );
}
try {
	foreach ( array( array( 'category', 'Cache root', 0 ), array( 'category', 'Cache child', 0 ), array( 'category', 'Cache other root', 0 ), array( 'post_tag', 'Cache tag', 0 ), array( 'post_tag', 'Cache other tag', 0 ) ) as $term ) {
		$result = wp_insert_term( $term[1] . ' ' . wp_generate_uuid4(), $term[0], array( 'parent' => $term[2] ) );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
		$terms[] = array( $term[0], (int) $result['term_id'] );
	}
	list( $root, $child, $other, $tag, $other_tag ) = array_column( $terms, 1 );
	wp_update_term( $child, 'category', array( 'parent' => $root ) );
	foreach ( array( 'publish', 'draft' ) as $status ) {
		$id = wp_insert_post( array( 'post_title' => 'Count cache fixture ' . wp_generate_uuid4(), 'post_type' => 'post', 'post_status' => $status ), true );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		$posts[] = (int) $id;
		wp_set_object_terms( $id, array( $child ), 'category' );
		wp_set_object_terms( $id, array( $tag ), 'post_tag' );
	}
	list( $published, $draft ) = $posts;
	$page = wp_insert_post( array( 'post_title' => 'Count cache page ' . wp_generate_uuid4(), 'post_type' => 'page', 'post_status' => 'publish' ), true );
	if ( is_wp_error( $page ) ) {
		throw new RuntimeException( $page->get_error_message() );
	}
	$posts[] = (int) $page;
	$category_args = array( 'auto_open' => false, 'root' => $root, 'show_counts' => true, 'hide_empty' => true );
	$tag_args = array( 'auto_open' => false, 'show_counts' => true, 'hide_empty' => true );
	remove_filter( 'content_trees_taxonomy_cache_min_posts', $small_fixture_threshold );
	ContentTrees\render_taxonomy( 'category', $category_args );
	count_cache_check( false === get_transient( 'ctrees_taxonomy_counts_category' ), 'A small query does not create a count snapshot by default' );
	add_filter( 'content_trees_taxonomy_cache_min_posts', $small_fixture_threshold );
	count_cache_compare( 'category', array_merge( $category_args, array( 'excluded_posts' => array( $published ), 'sort_by' => 'count', 'order' => 'DESC' ) ) );
	count_cache_compare( 'post_tag', array_merge( $tag_args, array( 'excluded_terms' => array( $other_tag ), 'excluded_posts' => array( $published ) ) ) );
	count_cache_compare( 'category', $category_args );
	count_cache_check( count( get_transient( 'ctrees_taxonomy_counts_category' )['entries'] ) >= 2, 'Distinct option sets retain separate category snapshots' );
	count_cache_compare( 'post_tag', $tag_args );
	count_cache_check( count( get_transient( 'ctrees_taxonomy_counts_post_tag' )['entries'] ) >= 2, 'Distinct option sets retain separate tag snapshots' );
	$writes = 0;
	$observe_writes = static function () use ( &$writes ) { ++$writes; };
	add_action( 'set_transient_ctrees_taxonomy_counts_category', $observe_writes );
	try {
		ContentTrees\render_taxonomy( 'category', array_merge( $category_args, array( 'excluded_posts' => array( $published ), 'sort_by' => 'count', 'order' => 'DESC' ) ) );
		ContentTrees\render_taxonomy( 'category', $category_args );
		count_cache_check( 0 === $writes, 'Alternating cached options does not rebuild either snapshot' );
		for ( $i = 0; $i < 6; ++$i ) {
			ContentTrees\render_taxonomy( 'category', array_merge( $category_args, array( 'title' => 'Cache variant ' . $i ) ) );
		}
		count_cache_check( 4 === count( get_transient( 'ctrees_taxonomy_counts_category' )['entries'] ), 'Option variants are bounded to four snapshots per taxonomy' );
		$writes = 0;
		for ( $cycle = 0; $cycle < 2; ++$cycle ) {
			for ( $i = 0; $i < 5; ++$i ) {
				$variant = array_merge( $category_args, array( 'title' => 'Eviction variant ' . $i ) );
				$noop = static function () {};
				add_action( 'pre_get_posts', $noop );
				try {
					$expected = ContentTrees\render_taxonomy( 'category', $variant );
				} finally {
					remove_action( 'pre_get_posts', $noop );
				}
				count_cache_check( $expected === ContentTrees\render_taxonomy( 'category', $variant ), 'Five alternating category configurations preserve uncached output' );
			}
		}
		count_cache_check( 10 === $writes && 4 === count( get_transient( 'ctrees_taxonomy_counts_category' )['entries'] ), 'Five configurations safely rebuild on each eviction' );
	} finally {
		remove_action( 'set_transient_ctrees_taxonomy_counts_category', $observe_writes );
	}
	// A second database connection models a stalled rebuild in another request.
	$host_parts = explode( ':', CONTENT_TREES_TEST_DB_HOST );
	$other_connection = new mysqli( $host_parts[0], DB_USER, DB_PASSWORD, DB_NAME, (int) $host_parts[1] );
	$lock_name = 'ctrees-' . md5( DB_NAME . '|' . $wpdb->prefix . '|category' );
	try {
		$result = $other_connection->query( "SELECT GET_LOCK('" . $other_connection->real_escape_string( $lock_name ) . "', 0)" );
		count_cache_check( $result && '1' === (string) $result->fetch_row()[0], 'A separate request can hold the category rebuild lock' );
		delete_transient( 'ctrees_taxonomy_counts_category' );
		$noop = static function () {};
		add_action( 'pre_get_posts', $noop );
		try {
			$expected = ContentTrees\render_taxonomy( 'category', $category_args );
		} finally {
			remove_action( 'pre_get_posts', $noop );
		}
		$fallback = ContentTrees\render_taxonomy( 'category', $category_args );
		count_cache_check( $expected === $fallback && false === get_transient( 'ctrees_taxonomy_counts_category' ), 'Lock timeout returns uncached output without a competing write' );
		$attempt_one = ContentTrees\taxonomy_count_cache_lock( 'category' );
		$attempt_two = ContentTrees\taxonomy_count_cache_lock( 'category' );
		count_cache_check( '' === $attempt_one && '' === $attempt_two && preg_match( '/,\s*1\s*\)$/', $wpdb->last_query ), 'A continuously held lock limits every acquisition attempt to one second' );
		$other_connection->query( "SELECT RELEASE_LOCK('" . $other_connection->real_escape_string( $lock_name ) . "')" );
		$raise = static function () { throw new RuntimeException( 'Intentional cache write failure' ); };
		add_action( 'set_transient_ctrees_taxonomy_counts_category', $raise );
		try {
			ContentTrees\render_taxonomy( 'category', $category_args );
			throw new RuntimeException( 'The test exception did not fire.' );
		} catch ( RuntimeException $error ) {
			if ( 'Intentional cache write failure' !== $error->getMessage() ) {
				throw $error;
			}
		} finally {
			remove_action( 'set_transient_ctrees_taxonomy_counts_category', $raise );
		}
		$result = $other_connection->query( "SELECT GET_LOCK('" . $other_connection->real_escape_string( $lock_name ) . "', 0)" );
		count_cache_check( $result && '1' === (string) $result->fetch_row()[0], 'An exception releases the rebuild lock' );
		$other_connection->query( "SELECT RELEASE_LOCK('" . $other_connection->real_escape_string( $lock_name ) . "')" );
	} finally {
		$other_connection->close();
	}
	// A backend that rejects lock functions must fail silently and restore wpdb state.
	$reject_lock = static function ( $sql ) {
		return 0 === strpos( $sql, 'SELECT GET_LOCK(' ) || 0 === strpos( $sql, 'SELECT RELEASE_LOCK(' ) ? 'SELECT ctrees_missing_lock_function()' : $sql;
	};
	$old_show = $wpdb->show_errors( true );
	$old_suppress = $wpdb->suppress_errors( false );
	$old_error_log = ini_get( 'error_log' );
	$old_log_errors = ini_get( 'log_errors' );
	$error_log = dirname( __DIR__ ) . '/results/count-cache-lock-errors.log';
	if ( file_exists( $error_log ) ) {
		throw new RuntimeException( 'Refusing to overwrite a lock-test error log.' );
	}
	ini_set( 'error_log', $error_log );
	ini_set( 'log_errors', '1' );
	add_filter( 'query', $reject_lock, 0 );
	ob_start();
	try {
		$failed_lock = ContentTrees\taxonomy_count_cache_lock( 'category' );
		ContentTrees\taxonomy_count_cache_unlock( 'ctrees-test-invalid' );
		$output = ob_get_contents();
		count_cache_check( '' === $failed_lock && '' === $output && ! file_exists( $error_log ), 'Unsupported lock functions fail without output or error log' );
		count_cache_check( false === $wpdb->suppress_errors && true === $wpdb->show_errors, 'Lock failure restores wpdb error settings' );
	} finally {
		ob_end_clean();
		remove_filter( 'query', $reject_lock, 0 );
		$wpdb->show_errors( $old_show );
		$wpdb->suppress_errors( $old_suppress );
		ini_set( 'error_log', $old_error_log );
		ini_set( 'log_errors', $old_log_errors );
	}
	$dropin = WP_CONTENT_DIR . '/db.php';
	if ( file_exists( $dropin ) ) {
		throw new RuntimeException( 'Refusing to replace an existing database drop-in.' );
	}
	try {
		if ( false === file_put_contents( $dropin, "<?php // Disposable guard fixture.\n" ) ) {
			throw new RuntimeException( 'Could not create the temporary database drop-in marker.' );
		}
		clearstatcache( true, $dropin );
		count_cache_check( ! ContentTrees\taxonomy_count_cache_safe( 'category', ContentTrees\taxonomy_options( 'category', $category_args ) ), 'A database drop-in bypasses snapshots even with the core wpdb class' );
	} finally {
		if ( file_exists( $dropin ) ) {
			unlink( $dropin );
		}
		clearstatcache( true, $dropin );
	}
	count_cache_check( ! ContentTrees\taxonomy_count_cache_safe( 'category', ContentTrees\taxonomy_options( 'category', array_merge( $category_args, array( 'open_to' => $child ) ) ) ), 'Explicitly open branches bypass the count snapshot' );
	count_cache_unchanged( 'draft save', static function () use ( $draft ) { wp_update_post( array( 'ID' => $draft, 'post_title' => 'Draft edit ' . wp_generate_uuid4() ) ); }, $category_args, $tag_args );
	count_cache_unchanged( 'draft term assignment', static function () use ( $draft, $other_tag ) { wp_set_object_terms( $draft, array( $other_tag ), 'post_tag' ); }, $category_args, $tag_args );
	count_cache_unchanged( 'page save', static function () use ( $page ) { wp_update_post( array( 'ID' => $page, 'post_title' => 'Page edit ' . wp_generate_uuid4() ) ); }, $category_args, $tag_args );
	count_cache_unchanged( 'published title edit', static function () use ( $published ) { wp_update_post( array( 'ID' => $published, 'post_title' => 'Title edit ' . wp_generate_uuid4() ) ); }, $category_args, $tag_args );
	count_cache_unchanged( 'ordinary post cache cleaning', static function () use ( $published ) { clean_post_cache( $published ); }, $category_args, $tag_args );
	count_cache_unchanged( 'autosave', static function () use ( $published ) {
		$autosave = _wp_put_post_revision( $published, true );
		if ( ! $autosave || is_wp_error( $autosave ) || ! wp_is_post_autosave( $autosave ) ) {
			throw new RuntimeException( 'Could not create the autosave fixture.' );
		}
	}, $category_args, $tag_args );
	count_cache_mutate( 'publication', static function () use ( $draft ) { wp_update_post( array( 'ID' => $draft, 'post_status' => 'publish' ) ); }, $category_args, $tag_args );
	count_cache_mutate( 'status change', static function () use ( $draft ) { wp_update_post( array( 'ID' => $draft, 'post_status' => 'private' ) ); }, $category_args, $tag_args );
	count_cache_mutate( 'direct publication', static function () use ( $draft ) { wp_publish_post( $draft ); }, $category_args, $tag_args );
	count_cache_mutate( 'directly published status change', static function () use ( $draft ) { wp_update_post( array( 'ID' => $draft, 'post_status' => 'private' ) ); }, $category_args, $tag_args );
	count_cache_mutate( 'password change', static function () use ( $published ) { wp_update_post( array( 'ID' => $published, 'post_password' => 'protected' ) ); }, $category_args, $tag_args );
	count_cache_mutate( 'password removal', static function () use ( $published ) { wp_update_post( array( 'ID' => $published, 'post_password' => '' ) ); }, $category_args, $tag_args );
	count_cache_mutate( 'combined status and password change', static function () use ( $published ) { wp_update_post( array( 'ID' => $published, 'post_status' => 'private', 'post_password' => 'protected' ) ); }, $category_args, $tag_args );
	count_cache_mutate( 'combined status and password restoration', static function () use ( $published ) { wp_update_post( array( 'ID' => $published, 'post_status' => 'publish', 'post_password' => '' ) ); }, $category_args, $tag_args );
	count_cache_mutate( 'post type change', static function () use ( $published ) { wp_cache_delete( $published, 'posts' ); set_post_type( $published, 'page' ); }, $category_args, $tag_args );
	count_cache_mutate( 'post type restoration', static function () use ( $published ) { wp_cache_delete( $published, 'posts' ); set_post_type( $published, 'post' ); }, $category_args, $tag_args );
	count_cache_mutate( 'direct term removal', static function () use ( $published, $tag ) { wp_remove_object_terms( $published, $tag, 'post_tag' ); }, $category_args, $tag_args );
	count_cache_mutate( 'direct term restoration', static function () use ( $published, $tag ) { wp_add_object_terms( $published, $tag, 'post_tag' ); }, $category_args, $tag_args );
	count_cache_mutate( 'term reassignment', static function () use ( $published, $other, $other_tag ) { wp_set_object_terms( $published, array( $other ), 'category' ); wp_set_object_terms( $published, array( $other_tag ), 'post_tag' ); }, $category_args, $tag_args );
	count_cache_mutate( 'term edit', static function () use ( $tag ) { wp_update_term( $tag, 'post_tag', array( 'name' => 'Renamed cache tag ' . wp_generate_uuid4() ) ); }, $category_args, $tag_args );
	count_cache_mutate( 'category reparenting', static function () use ( $child, $other ) { wp_update_term( $child, 'category', array( 'parent' => $other ) ); }, $category_args, $tag_args );
	count_cache_mutate( 'term deletion', static function () use ( $tag ) { wp_delete_term( $tag, 'post_tag' ); }, $category_args, $tag_args );
	count_cache_mutate( 'post deletion', static function () use ( $published ) { wp_delete_post( $published, true ); }, $category_args, $tag_args );
	$posts = array( $draft, $page );

	// Warm the anonymous cache, then change the query context.
	wp_update_post( array( 'ID' => $draft, 'post_status' => 'publish' ) );
	wp_set_object_terms( $draft, array( $other ), 'category' );
	$context_args = array( 'auto_open' => false, 'root' => $other, 'show_counts' => true, 'hide_empty' => true );
	count_cache_compare( 'category', $context_args );
	$visible = ContentTrees\render_taxonomy( 'category', $context_args );
	global $wpdb;
	$where = static function ( $sql ) use ( $wpdb, $draft ) { return $sql . $wpdb->prepare( " AND {$wpdb->posts}.ID <> %d", $draft ); };
	add_filter( 'posts_where', $where );
	try {
		count_cache_check( ! ContentTrees\taxonomy_count_cache_safe( 'category', ContentTrees\taxonomy_options( 'category', $context_args ) ), 'SQL-filtered query bypasses the snapshot' );
		$filtered = ContentTrees\render_taxonomy( 'category', $context_args );
		count_cache_check( $filtered !== $visible, 'A warmed anonymous snapshot cannot override SQL-filtered visibility' );
	} finally {
		remove_filter( 'posts_where', $where );
	}
	$database_filter = static function ( $sql ) { return $sql; };
	add_filter( 'query', $database_filter );
	try {
		count_cache_check( ! ContentTrees\taxonomy_count_cache_safe( 'category', ContentTrees\taxonomy_options( 'category', $context_args ) ), 'A database query filter bypasses the snapshot' );
	} finally {
		remove_filter( 'query', $database_filter );
	}
	$term_filter = static function ( $term_results ) { return $term_results; };
	add_filter( 'get_terms', $term_filter );
	try {
		count_cache_check( ! ContentTrees\taxonomy_count_cache_safe( 'category', ContentTrees\taxonomy_options( 'category', $context_args ) ), 'A custom term filter bypasses the snapshot' );
	} finally {
		remove_filter( 'get_terms', $term_filter );
	}
	$pre = static function ( $result, $query ) { return 'ids' === $query->get( 'fields' ) && $query->get( 'tax_query' ) ? array() : $result; };
	add_filter( 'posts_pre_query', $pre, 10, 2 );
	try {
		count_cache_check( ! ContentTrees\taxonomy_count_cache_safe( 'category', ContentTrees\taxonomy_options( 'category', $context_args ) ), 'posts_pre_query bypasses the snapshot' );
		count_cache_check( ContentTrees\render_taxonomy( 'category', $context_args ) !== $visible, 'A short-circuited query retains its own results' );
	} finally {
		remove_filter( 'posts_pre_query', $pre, 10 );
	}
	wp_cache_flush();
	$user = wp_insert_user( array( 'user_login' => 'ctrees-cache-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	if ( is_wp_error( $user ) ) {
		throw new RuntimeException( $user->get_error_message() );
	}
	wp_set_current_user( $user );
	count_cache_check( ! ContentTrees\taxonomy_count_cache_safe( 'category', ContentTrees\taxonomy_options( 'category', $context_args ) ), 'Logged-in context bypasses the anonymous snapshot' );
	$noop = static function () {};
	add_action( 'pre_get_posts', $noop );
	try {
		$user_expected = ContentTrees\render_taxonomy( 'category', $context_args );
	} finally {
		remove_action( 'pre_get_posts', $noop );
	}
	count_cache_check( ContentTrees\render_taxonomy( 'category', $context_args ) === $user_expected, 'Logged-in context preserves its own uncached output' );
	wp_set_current_user( 0 );
	count_cache_check( get_option( '_transient_timeout_ctrees_taxonomy_counts_category' ) > time(), 'The database transient has an expiry' );
} finally {
	wp_set_current_user( 0 );
	remove_filter( 'content_trees_taxonomy_cache_min_posts', $small_fixture_threshold );
	foreach ( $posts as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( array_reverse( $terms ) as $term ) {
		if ( term_exists( $term[1], $term[0] ) ) {
			wp_delete_term( $term[1], $term[0] );
		}
	}
	if ( $user && ! is_wp_error( $user ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user );
	}
	delete_transient( 'ctrees_taxonomy_counts_category' );
	delete_transient( 'ctrees_taxonomy_counts_post_tag' );
	if ( '__ctrees_missing__' === $revision_before ) {
		delete_option( 'ctrees_taxonomy_count_revision' );
	} else {
		update_option( 'ctrees_taxonomy_count_revision', $revision_before, false );
	}
	foreach ( $saved_snapshots as $key => $saved ) {
		if ( false !== $saved['value'] && $saved['expires'] > time() ) {
			set_transient( $key, $saved['value'], $saved['expires'] - time() );
		}
	}
}
echo "PASS: $checks count-cache checks; temporary fixture removed and prior snapshots restored.\n";
