<?php
/**
 * Category and Tag tree data, options and rendering.
 *
 * @package ContentTrees
 */

namespace ContentTrees;

defined( 'ABSPATH' ) || exit;

/**
 * Only the two built-in navigation providers are supported.
 *
 * @param string $taxonomy Built-in taxonomy name.
 */
function taxonomy_title( $taxonomy ) {
	return 'category' === $taxonomy ? __( 'Categories', 'content-trees' ) : __( 'Tags', 'content-trees' );
}

/**
 * Normalize options for the selected built-in taxonomy.
 *
 * @param string $taxonomy Built-in taxonomy name.
 * @param array  $args Tree options or theme widget wrappers.
 */
function taxonomy_options( $taxonomy, $args = array() ) {
	$defaults           = array(
		'title'          => taxonomy_title( $taxonomy ),
		'show_title'     => true,
		'show_posts'     => true,
		'root'           => 0,
		'excluded_terms' => array(),
		'excluded_posts' => array(),
		'sort_by'        => 'name',
		'order'          => 'ASC',
		'post_sort_by'   => 'date',
		'post_order'     => 'DESC',
		'show_counts'    => true,
		'hide_empty'     => true,
		'auto_open'      => true,
		'open_to'        => 0,
		'show_controls'  => false,
		'close_siblings' => false,
	);
	$args               = array_intersect_key( wp_parse_args( $args, $defaults ), $defaults );
	$args['title']      = is_string( $args['title'] ) ? sanitize_text_field( $args['title'] ) : $defaults['title'];
	$args['show_title'] = enabled( $args['show_title'] ) && '' !== $args['title'];
	foreach ( array( 'show_posts', 'show_counts', 'hide_empty', 'auto_open', 'show_controls', 'close_siblings' ) as $flag ) {
		$args[ $flag ] = enabled( $args[ $flag ] );
	}
	$args['root']    = 'category' !== $taxonomy || empty( $args['root'] ) ? 0 : ( exclusion_ids( array( $args['root'] ) )[0] ?? -1 );
	$args['open_to'] = exclusion_ids( array( $args['open_to'] ) )[0] ?? 0;
	foreach ( array( 'excluded_terms', 'excluded_posts' ) as $field ) {
		$args[ $field ] = exclusion_ids( $args[ $field ] ); }
	$args['sort_by']      = in_array( $args['sort_by'], array( 'name', 'count' ), true ) ? $args['sort_by'] : 'name';
	$args['post_sort_by'] = in_array( $args['post_sort_by'], array( 'title', 'date', 'modified' ), true ) ? $args['post_sort_by'] : 'date';
	foreach ( array( 'order', 'post_order' ) as $field ) {
		$args[ $field ] = in_array( $args[ $field ], array( 'ASC', 'DESC' ), true ) ? $args[ $field ] : $defaults[ $field ]; }
	return $args;
}

/**
 * Resolve public term choices, including empty terms, without content counts.
 *
 * @param string $taxonomy Built-in taxonomy name.
 * @param int[]  $included_ids IDs to resolve, or an empty array to search.
 * @param string $search Search text.
 */
function taxonomy_option_nodes( $taxonomy, $included_ids = array(), $search = '' ) {
	if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
		return array(); }
	$ids = exclusion_ids( $included_ids );
	if ( $included_ids && ! $ids ) {
		return array(); }
	$args = array(
		'taxonomy'               => $taxonomy,
		'hide_empty'             => false,
		'hierarchical'           => false,
		'number'                 => $included_ids ? 0 : 20,
		'orderby'                => 'name',
		'order'                  => 'ASC',
		'ctrees_name_order'      => 'ASC',
		'update_term_meta_cache' => false,
	);
	if ( $ids ) {
		$args['include'] = $ids;
	} else {
		$args['search'] = $search; }
	$terms = get_terms( $args );
	if ( is_wp_error( $terms ) ) {
		return array(); }
	$items = array();
	foreach ( $terms as $term ) {
		if ( ! $term instanceof \WP_Term || $term->taxonomy !== $taxonomy ) {
			continue; }
		$url = get_term_link( $term, $taxonomy );
		if ( is_wp_error( $url ) ) {
			continue; }
		$label   = html_entity_decode( wp_strip_all_tags( $term->name, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$items[] = array(
			'id'    => (int) $term->term_id,
			'label' => '' !== $label ? $label : __( '(Untitled)', 'content-trees' ),
			'url'   => esc_url_raw( $url ),
		);
	}
	return $items;
}

add_action(
	'rest_api_init',
	static function () {
		foreach ( array(
			'category-options' => 'category',
			'tag-options'      => 'post_tag',
		) as $route => $taxonomy ) {
			register_rest_route(
				'content-trees/v1',
				'/' . $route,
				array(
					'methods'             => 'GET',
					'permission_callback' => static function () {
						return current_user_can( 'edit_posts' ) || current_user_can( 'edit_theme_options' ); },
					'args'                => array(
						'search'  => array(
							'type'      => 'string',
							'maxLength' => 200,
							'default'   => '',
						),
						'include' => array(
							'type'    => 'string',
							'pattern' => '^[0-9,]*$',
							'default' => '',
						),
					),
					'callback'            => static function ( $request ) use ( $taxonomy ) {
						$ids = exclusion_ids( $request['include'] );
						if ( '' !== $request['include'] && ! $ids ) {
							return rest_ensure_response( array( 'items' => array() ) ); }
						return rest_ensure_response( array( 'items' => taxonomy_option_nodes( $taxonomy, $ids, $request['search'] ) ) );
					},
				)
			);
		}
	}
);

/**
 * Keep the block adapter explicit; saved attributes retain defaults.
 *
 * @param string $taxonomy Built-in taxonomy name.
 * @param array  $attributes Saved block attributes.
 */
function taxonomy_block_options( $taxonomy, $attributes ) {
	$map  = array(
		'title'         => 'title',
		'root'          => 'root',
		'showPosts'     => 'show_posts',
		'sortBy'        => 'sort_by',
		'order'         => 'order',
		'postSortBy'    => 'post_sort_by',
		'postOrder'     => 'post_order',
		'showCounts'    => 'show_counts',
		'hideEmpty'     => 'hide_empty',
		'autoOpen'      => 'auto_open',
		'showControls'  => 'show_controls',
		'closeSiblings' => 'close_siblings',
		'exclude'       => 'excluded_terms',
		'excludePosts'  => 'excluded_posts',
	);
	$args = array();
	foreach ( $map as $attribute => $option ) {
		if ( array_key_exists( $attribute, $attributes ) ) {
			$args[ $option ] = $attributes[ $attribute ]; }
	}
	return taxonomy_options( $taxonomy, $args );
}

/**
 * Preserve database name collation and use a stable ID tie for these queries.
 *
 * @param string $orderby Existing order expression.
 * @param array  $args Term query arguments.
 */
function taxonomy_name_orderby( $orderby, $args ) {
	return ! empty( $args['ctrees_name_order'] ) ? $orderby . ( 'DESC' === $args['ctrees_name_order'] ? ' DESC' : ' ASC' ) . ', t.term_id' : $orderby;
}
add_filter( 'get_terms_orderby', __NAMESPACE__ . '\\taxonomy_name_orderby', 10, 2 );

/**
 * Resolve original category ancestry without disclosing filtered parent names.
 *
 * @param string $taxonomy Built-in taxonomy name.
 */
function taxonomy_original_parents( $taxonomy ) {
	global $wpdb;
	$original = array();
	if ( 'category' === $taxonomy ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Request-local ID/parent edges preserve ancestry around filtered terms; labels and links use filtered core APIs, with no persistent snapshot.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT term_id, parent FROM %i WHERE taxonomy = %s', $wpdb->term_taxonomy, $taxonomy ) );
		foreach ( $rows as $row ) {
			$original[ (int) $row->term_id ] = (int) $row->parent; }
	}
	return $original;
}

/**
 * Promote visible terms through missing ancestors and break cycles.
 *
 * @param string     $taxonomy Built-in taxonomy name.
 * @param array      $terms Visible terms.
 * @param array|null $original Original parent edges, or null to resolve them.
 * @param int        $root Scoped root ID, or zero.
 */
function taxonomy_parents( $taxonomy, $terms, $original = null, $root = 0 ) {
	if ( null === $original ) {
		$original = taxonomy_original_parents( $taxonomy ); }
	$parents = array();
	foreach ( $terms as $id => $term ) {
		$parent_id = 'category' === $taxonomy && $id !== $root ? (int) $term['term']->parent : 0;
		$seen      = array( $id => true );
		while ( $parent_id && ! isset( $terms[ $parent_id ] ) ) {
			if ( isset( $seen[ $parent_id ] ) || ! isset( $original[ $parent_id ] ) ) {
				$parent_id = 0;
				break; }
			$seen[ $parent_id ] = true;
			$parent_id          = $original[ $parent_id ];
		}
		$parents[ $id ] = $parent_id;
	}
	$parents = acyclic_parents( $parents );
	return $parents;
}

/** Invalidate the two bounded count snapshots after relevant changes. */
function taxonomy_invalidate_count_cache() {
	update_option( 'ctrees_taxonomy_count_revision', wp_generate_uuid4(), false );
	delete_transient( 'ctrees_taxonomy_counts_category' );
	delete_transient( 'ctrees_taxonomy_counts_post_tag' );
}

/**
 * Whether a post contributes to public taxonomy trees.
 *
 * @param mixed $post Post to check.
 * @return bool Whether the post is eligible.
 */
function taxonomy_cache_post_eligible( $post ) {
	return $post instanceof \WP_Post && 'post' === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password;
}

/**
 * Invalidate when a post changes membership in the eligible population.
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $after Updated post.
 * @param \WP_Post $before Previous post.
 */
function taxonomy_cache_post_updated( $post_id, $after, $before ) {
	if ( taxonomy_cache_post_eligible( $before ) !== taxonomy_cache_post_eligible( $after ) ) {
		taxonomy_invalidate_count_cache();
	}
}
add_action( 'post_updated', __NAMESPACE__ . '\\taxonomy_cache_post_updated', 10, 3 );

/**
 * Cover direct publication paths, including scheduled publication.
 *
 * @param string   $new_status New status.
 * @param string   $old_status Previous status.
 * @param \WP_Post $post Post being transitioned.
 */
function taxonomy_cache_status_changed( $new_status, $old_status, $post ) {
	if ( $post instanceof \WP_Post && 'post' === $post->post_type && '' === $post->post_password
		&& ( ( 'publish' === $old_status ) !== ( 'publish' === $new_status ) ) ) {
		taxonomy_invalidate_count_cache();
	}
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\taxonomy_cache_status_changed', 10, 3 );

/**
 * A deleted eligible post no longer contributes to any tree.
 *
 * @param int      $post_id Deleted post ID.
 * @param \WP_Post $post Deleted post.
 */
function taxonomy_cache_post_deleted( $post_id, $post ) {
	if ( taxonomy_cache_post_eligible( $post ) ) {
		taxonomy_invalidate_count_cache();
	}
}
add_action( 'deleted_post', __NAMESPACE__ . '\\taxonomy_cache_post_deleted', 10, 2 );

/**
 * Only relationships of eligible posts in supported taxonomies matter.
 *
 * @param int       $object_id Post ID.
 * @param int|int[] $term_taxonomy_ids Changed relationship IDs.
 * @param string    $taxonomy Taxonomy name.
 */
function taxonomy_cache_relationship_changed( $object_id, $term_taxonomy_ids, $taxonomy ) {
	if ( in_array( $taxonomy, array( 'category', 'post_tag' ), true ) && $term_taxonomy_ids && taxonomy_cache_post_eligible( get_post( $object_id ) ) ) {
		taxonomy_invalidate_count_cache();
	}
}
add_action( 'added_term_relationship', __NAMESPACE__ . '\\taxonomy_cache_relationship_changed', 0, 3 );
add_action( 'deleted_term_relationships', __NAMESPACE__ . '\\taxonomy_cache_relationship_changed', 0, 3 );

/**
 * Term structure and visibility changes affect only these two taxonomies.
 *
 * @param int    $term_id Term ID.
 * @param int    $term_taxonomy_id Term-taxonomy ID.
 * @param string $taxonomy Taxonomy name.
 */
function taxonomy_cache_term_changed( $term_id, $term_taxonomy_id, $taxonomy ) {
	if ( in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
		taxonomy_invalidate_count_cache();
	}
}
add_action( 'edited_term', __NAMESPACE__ . '\\taxonomy_cache_term_changed', 10, 3 );
add_action( 'delete_term', __NAMESPACE__ . '\\taxonomy_cache_term_changed', 10, 3 );

/**
 * Capture the old state for core's set_post_type(), which has no update action.
 * The SQL is returned unchanged; all other database queries pass through.
 *
 * @param string $sql Database query.
 * @return string Unchanged query.
 */
function taxonomy_cache_observe_type_update( $sql ) {
	global $wpdb;
	if ( get_class( $wpdb ) !== 'wpdb' || 0 !== stripos( ltrim( $sql ), 'UPDATE' )
		|| ! preg_match( '/^\\s*UPDATE\\s+`?' . preg_quote( $wpdb->posts, '/' ) . '`?\\s+SET\\s+`?post_type`?\\s*=/i', $sql ) ) {
		return $sql;
	}
	// The core caller's current, sanitized post type is the value being written.
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace,PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.NeedsInspection -- Core exposes no action for set_post_type; inspect its active arguments only for a matching UPDATE.
	foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT, 12 ) as $frame ) {
		if ( 'set_post_type' !== ( $frame['function'] ?? '' ) || isset( $frame['class'] ) ) {
			continue;
		}
		$post_id  = (int) ( $frame['args'][0] ?? 0 );
		$new_type = (string) ( $frame['args'][1] ?? 'post' );
		if ( ! $post_id ) {
			break;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The pre-update value must not come from a stale post cache.
		$before = $wpdb->get_row( $wpdb->prepare( 'SELECT post_type, post_status, post_password FROM %i WHERE ID = %d', $wpdb->posts, $post_id ) );
		if ( $before && $before->post_type !== $new_type && 'publish' === $before->post_status && '' === $before->post_password
			&& ( 'post' === $before->post_type || 'post' === $new_type ) ) {
			$GLOBALS['ctrees_taxonomy_type_changes'][ $post_id ] = ( 'post' === $before->post_type );
		}
		break;
	}
	return $sql;
}
add_filter( 'query', __NAMESPACE__ . '\\taxonomy_cache_observe_type_update', 1 );

/**
 * Finish the exceptional set_post_type() invalidation after core's update.
 *
 * @param int $post_id Updated post ID.
 */
function taxonomy_cache_type_updated( $post_id ) {
	global $wpdb;
	if ( ! isset( $GLOBALS['ctrees_taxonomy_type_changes'][ $post_id ] ) ) {
		return;
	}
	$was_eligible = $GLOBALS['ctrees_taxonomy_type_changes'][ $post_id ];
	unset( $GLOBALS['ctrees_taxonomy_type_changes'][ $post_id ] );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify the database state after core's update.
	$after       = $wpdb->get_row( $wpdb->prepare( 'SELECT post_type, post_status, post_password FROM %i WHERE ID = %d', $wpdb->posts, $post_id ) );
	$is_eligible = $after && 'post' === $after->post_type && 'publish' === $after->post_status && '' === $after->post_password;
	if ( (bool) $was_eligible !== (bool) $is_eligible ) {
		taxonomy_invalidate_count_cache();
	}
}
add_action( 'clean_post_cache', __NAMESPACE__ . '\\taxonomy_cache_type_updated', 0 );

/**
 * Use a count snapshot only when the normal query has no external hooks.
 *
 * @param string $taxonomy Built-in taxonomy name.
 * @param array  $args Normalized tree options.
 */
function taxonomy_count_cache_safe( $taxonomy, $args ) {
	global $wpdb, $wp_filter;
	if ( wp_using_ext_object_cache() || is_user_logged_in() || get_class( $wpdb ) !== 'wpdb' || $args['open_to']
		|| ( $args['auto_open'] && ( is_singular( 'post' ) || ( 'category' === $taxonomy && is_category() ) || ( 'post_tag' === $taxonomy && is_tag() ) ) ) ) {
		return false;
	}
	$hooks     = array(
		'all',
		'parse_query',
		'pre_get_posts',
		'posts_pre_query',
		'posts_where',
		'posts_join',
		'posts_where_paged',
		'posts_groupby',
		'posts_join_paged',
		'posts_orderby',
		'posts_distinct',
		'post_limits',
		'posts_fields',
		'posts_clauses',
		'posts_where_request',
		'posts_groupby_request',
		'posts_join_request',
		'posts_orderby_request',
		'posts_distinct_request',
		'posts_fields_request',
		'post_limits_request',
		'posts_clauses_request',
		'posts_request',
		'query',
		'pre_get_terms',
		'get_terms_defaults',
		'get_terms_args',
		'list_terms_exclusions',
		'get_terms_fields',
		'terms_clauses',
		'terms_pre_query',
		'get_terms',
		'get_terms_orderby',
		'get_term',
		'get_category',
		'get_post_tag',
		'get_term_metadata',
		'term_link',
		'wp_generate_uuid4',
		'pre_option_ctrees_taxonomy_count_revision',
		'option_ctrees_taxonomy_count_revision',
		'default_option_ctrees_taxonomy_count_revision',
	);
	$transient = 'ctrees_taxonomy_counts_' . $taxonomy;
	foreach ( array( 'pre_transient_', 'transient_', 'pre_set_transient_', 'expiration_of_transient_' ) as $prefix ) {
		$hooks[] = $prefix . $transient;
	}
	foreach ( $hooks as $hook ) {
		if ( empty( $wp_filter[ $hook ] ) ) {
			continue;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( 'query' === $hook && is_array( $function ) && $function[0] === $wpdb && 'remove_placeholder_escape' === $function[1] ) {
					continue;
				}
				if ( 'query' === $hook && __NAMESPACE__ . '\\taxonomy_cache_observe_type_update' === $function ) {
					continue;
				}
				if ( 'get_terms' === $hook && '_post_format_get_terms' === $function ) {
					continue;
				}
				if ( 'get_term_metadata' === $hook && 'wp_check_term_meta_support_prefilter' === $function ) {
					continue;
				}
				if ( 'get_term_metadata' === $hook && is_array( $function ) && $function[0] instanceof \WP_Metadata_Lazyloader && 'lazyload_meta_callback' === $function[1] ) {
					continue;
				}
				if ( 'term_link' === $hook && '_post_format_link' === $function ) {
					continue;
				}
				if ( 'get_terms_orderby' === $hook && __NAMESPACE__ . '\\taxonomy_name_orderby' === $function ) {
					continue;
				}
				return false;
			}
		}
	}
	return true;
}

/** Stable across requests even without an external object cache. */
function taxonomy_count_cache_revision() {
	$revision = get_option( 'ctrees_taxonomy_count_revision', '' );
	if ( ! $revision ) {
		add_option( 'ctrees_taxonomy_count_revision', wp_generate_uuid4(), '', false );
		$revision = get_option( 'ctrees_taxonomy_count_revision', '' );
	}
	return $revision;
}

/**
 * Try a bounded, connection-scoped database lock for one taxonomy rebuild.
 *
 * @param string $taxonomy Taxonomy name.
 * @return string Lock name, or an empty string on timeout or failure.
 */
function taxonomy_count_cache_lock( $taxonomy ) {
	global $wpdb;
	$name = 'ctrees-' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . $taxonomy );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped coordination cannot use the object cache.
	return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 1 ) ) ? $name : '';
}

/**
 * Release a lock held by this database connection.
 *
 * @param string $name Lock name.
 */
function taxonomy_count_cache_unlock( $name ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the connection-scoped lock even on failure.
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
}

/**
 * Fetch complete public posts, or only their IDs when building the outline.
 *
 * @param string $taxonomy Built-in taxonomy name.
 * @param int[]  $terms Eligible term IDs.
 * @param array  $args Normalized tree options.
 * @param bool   $ids_only Whether to return IDs without post objects.
 * @return \WP_Query
 */
function taxonomy_post_query( $taxonomy, $terms, $args, $ids_only = false ) {
	$query = array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'has_password'           => false,
		'posts_per_page'         => -1,
		'no_found_rows'          => true,
		'ignore_sticky_posts'    => true,
		'orderby'                => $ids_only ? array( 'ID' => 'ASC' ) : array(
			$args['post_sort_by'] => $args['post_order'],
			'ID'                  => 'ASC',
		),
		'update_post_meta_cache' => false,
		'update_post_term_cache' => ! $ids_only,
		'tax_query'              => array(
			array(
				'taxonomy'         => $taxonomy,
				'terms'            => $terms,
				'field'            => 'term_id',
				'include_children' => false,
			),
		),
	);
	if ( $ids_only ) {
		$query['fields'] = 'ids'; }
	return new \WP_Query( $query );
}

/**
 * Read ID pairs after resolving term visibility through core APIs.
 *
 * @param string $taxonomy Built-in taxonomy name.
 * @param int[]  $terms Eligible term IDs.
 * @param int[]  $posts Eligible post IDs.
 * @return object[] Public post and term ID pairs.
 */
function taxonomy_membership_rows( $taxonomy, $terms, $posts ) {
	global $wpdb;
	if ( ! $terms || ! $posts ) {
		return array(); }
	$term_placeholders = implode( ',', array_fill( 0, count( $terms ), '%d' ) );
	$post_placeholders = implode( ',', array_fill( 0, count( $posts ), '%d' ) );
	// Both interpolated strings contain only generated integer placeholders.
	// Recheck public eligibility independently of any post-query filters.
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Generated %d lists contain no input values; the prepare argument array has four fixed values plus one integer per generated placeholder.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared request-local ID pairs; no post/term objects or persistent membership snapshot.
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT tr.object_id, tt.term_id FROM %i AS tr
        INNER JOIN %i AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
        INNER JOIN %i AS p ON p.ID = tr.object_id
        WHERE tt.taxonomy = %s AND tt.term_id IN ($term_placeholders) AND tr.object_id IN ($post_placeholders)
        AND p.post_type = 'post' AND p.post_status = 'publish' AND p.post_password = ''",
			array_merge( array( $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->posts, $taxonomy ), array_values( $terms ), array_values( $posts ) )
		)
	);
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
}

/**
 * Fetch a complete direct post list for the public route.
 *
 * @param string $taxonomy Built-in taxonomy name.
 * @param int    $term_id Requested term ID.
 * @param array  $args Tree options controlling post sorting and exclusions.
 * @return array|\WP_Error Public link nodes or an unavailable-term error.
 */
function taxonomy_term_posts( $taxonomy, $term_id, $args = array() ) {
	if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
		return new \WP_Error( 'ctrees_invalid_taxonomy', __( 'Unknown tree type.', 'content-trees' ), array( 'status' => 404 ) ); }
	$term = get_term( $term_id, $taxonomy );
	if ( ! $term instanceof \WP_Term || $term->taxonomy !== $taxonomy ) {
		return new \WP_Error( 'ctrees_term_not_found', __( 'This category or tag is no longer available.', 'content-trees' ), array( 'status' => 404 ) ); }
	$args     = taxonomy_options( $taxonomy, $args );
	$query    = taxonomy_post_query( $taxonomy, array( (int) $term_id ), $args );
	$excluded = array_fill_keys( $args['excluded_posts'], true );
	$items    = array();
	foreach ( $query->posts as $post ) {
		if ( is_public_post( $post ) && ! isset( $excluded[ $post->ID ] ) ) {
			$items[] = post_node( $post ); }
	}
	return array( 'items' => $items );
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'content-trees/v1',
			'/terms/(?P<taxonomy>category|post_tag)/(?P<term_id>\d+)/posts',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'taxonomy'       => array(
						'type'     => 'string',
						'enum'     => array( 'category', 'post_tag' ),
						'required' => true,
					),
					'term_id'        => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'sort_by'        => array(
						'type'    => 'string',
						'enum'    => array( 'title', 'date', 'modified' ),
						'default' => 'date',
					),
					'order'          => array(
						'type'    => 'string',
						'enum'    => array( 'ASC', 'DESC' ),
						'default' => 'DESC',
					),
					'excluded_posts' => array(
						'type'    => 'string',
						'pattern' => '^[0-9,]*$',
						'default' => '',
					),
				),
				'callback'            => static function ( $request ) {
					return rest_ensure_response(
						taxonomy_term_posts(
							$request['taxonomy'],
							(int) $request['term_id'],
							array(
								'post_sort_by'   => $request['sort_by'],
								'post_order'     => $request['order'],
								'excluded_posts' => exclusion_ids( $request['excluded_posts'] ),
							)
						)
					);
				},
			)
		);
	}
);

/**
 * Complete term structure and lightweight memberships; no post presentation.
 *
 * @param string $taxonomy Built-in taxonomy name.
 * @param array  $args Tree options or theme widget wrappers.
 * @param bool   $closed_outline Whether the caller needs only closed branches.
 */
function taxonomy_data( $taxonomy, $args = array(), $closed_outline = false ) {
	$data = array(
		'terms'       => array(),
		'parents'     => array(),
		'children'    => array( 0 => array() ),
		'memberships' => array(),
		'counts'      => array(),
		'posts'       => array(),
	);
	if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
		return $data; }
	$args    = taxonomy_options( $taxonomy, $args );
	$results = get_terms(
		array(
			'taxonomy'               => $taxonomy,
			'hide_empty'             => false,
			'hierarchical'           => false,
			'number'                 => 0,
			'orderby'                => 'name',
			'order'                  => 'ASC',
			'ctrees_name_order'      => 'name' === $args['sort_by'] ? $args['order'] : 'ASC',
			'update_term_meta_cache' => false,
		)
	);
	if ( is_wp_error( $results ) ) {
		return $data; }
	foreach ( $results as $term ) {
		if ( ! $term instanceof \WP_Term || $term->taxonomy !== $taxonomy ) {
			continue; }
		$url = get_term_link( $term, $taxonomy );
		if ( is_wp_error( $url ) ) {
			continue; }
		$label                                 = html_entity_decode( wp_strip_all_tags( $term->name, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$data['terms'][ (int) $term->term_id ] = array(
			'term'  => $term,
			'url'   => $url,
			'label' => '' !== $label ? $label : __( '(Untitled)', 'content-trees' ),
		);
	}
	if ( ! $data['terms'] ) {
		return $data; }
	$original = taxonomy_original_parents( $taxonomy );
	if ( $args['excluded_terms'] ) {
		$excluded      = 'category' === $taxonomy ? descendants( $args['excluded_terms'], $original ) : array_fill_keys( $args['excluded_terms'], true );
		$data['terms'] = array_diff_key( $data['terms'], $excluded );
	}
	if ( $args['root'] ) {
		if ( ! isset( $data['terms'][ $args['root'] ] ) ) {
			$data['terms'] = array();
			return $data; }
		$data['terms'] = array_intersect_key( $data['terms'], descendants( array( $args['root'] ), $original ) );
	}
	if ( ! $data['terms'] ) {
		return $data; }
	$data['parents']     = taxonomy_parents( $taxonomy, $data['terms'], $original, $args['root'] );
	$needs_memberships   = $args['show_posts'] || $args['show_counts'] || $args['hide_empty'] || 'count' === $args['sort_by'];
	$cache_safe          = $closed_outline && $needs_memberships && taxonomy_count_cache_safe( $taxonomy, $args );
	$minimum_cache_posts = $cache_safe ? max( 0, (int) apply_filters( 'content_trees_taxonomy_cache_min_posts', 1000 ) ) : 0;
	if ( $cache_safe && $minimum_cache_posts ) {
		// Stored counts are only a cheap cache-worthiness hint, never displayed.
		$possible_memberships = 0;
		foreach ( $data['terms'] as $term ) {
			$possible_memberships += max( 0, (int) $term['term']->count );
			if ( $possible_memberships >= $minimum_cache_posts ) {
				break;
			}
		}
		if ( $possible_memberships < $minimum_cache_posts ) {
			$cache_safe = false;
		}
	}
	$cache_key = 'ctrees_taxonomy_counts_' . $taxonomy;
	$revision  = $cache_safe ? taxonomy_count_cache_revision() : '';
	$signature = $cache_safe ? md5( wp_json_encode( array( $taxonomy, $args, array_keys( $data['terms'] ), $data['parents'], $minimum_cache_posts ) ) ) : '';
	$snapshot  = $cache_safe && $revision ? get_transient( $cache_key ) : false;
	$entries   = is_array( $snapshot ) && ( $snapshot['revision'] ?? '' ) === $revision && isset( $snapshot['entries'] ) && is_array( $snapshot['entries'] ) ? $snapshot['entries'] : array();
	$cached    = $entries[ $signature ] ?? false;
	if ( ! is_array( $cached ) || ! isset( $cached['counts'], $cached['direct'] ) || ! is_array( $cached['counts'] ) || ! is_array( $cached['direct'] ) ) {
		$cached = false;
	}
	$lock = '';
	try {
		if ( $cache_safe && $revision && ! $cached ) {
			$lock = taxonomy_count_cache_lock( $taxonomy );
			if ( $lock ) {
				// Another request may have rebuilt while this connection waited.
				wp_cache_delete( 'ctrees_taxonomy_count_revision', 'options' );
				wp_cache_delete( '_transient_' . $cache_key, 'options' );
				wp_cache_delete( '_transient_timeout_' . $cache_key, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				$revision = taxonomy_count_cache_revision();
				$snapshot = get_transient( $cache_key );
				$entries  = is_array( $snapshot ) && ( $snapshot['revision'] ?? '' ) === $revision && isset( $snapshot['entries'] ) && is_array( $snapshot['entries'] ) ? $snapshot['entries'] : array();
				$cached   = $entries[ $signature ] ?? false;
				if ( ! is_array( $cached ) || ! isset( $cached['counts'], $cached['direct'] ) || ! is_array( $cached['counts'] ) || ! is_array( $cached['direct'] ) ) {
					$cached = false;
				}
			} else {
				// Never wait indefinitely or write a competing snapshot on timeout.
				$cache_safe = false;
			}
		}
		$post_ids = array();
		if ( $needs_memberships && ! $cached ) {
			$query    = taxonomy_post_query( $taxonomy, array_keys( $data['terms'] ), $args, true );
			$post_ids = array_values( array_diff( exclusion_ids( $query->posts ), $args['excluded_posts'] ) );
		} elseif ( ! $needs_memberships && $args['auto_open'] && is_singular( 'post' ) && is_public_post( get_queried_object() ) ) {
			$current = (int) get_queried_object_id();
			if ( ! in_array( $current, $args['excluded_posts'], true ) ) {
				$post_ids = array( $current ); }
		}
		foreach ( taxonomy_membership_rows( $taxonomy, array_keys( $data['terms'] ), $post_ids ) as $row ) {
			$data['memberships'][ (int) $row->term_id ][ (int) $row->object_id ] = true;
		}
		$unique = array();
		foreach ( $data['memberships'] as $term_id => $posts ) {
			foreach ( $posts as $id => $unused ) {
				$parent_id = $term_id;
				while ( $parent_id ) {
					$unique[ $parent_id ][ $id ] = true;
					$parent_id                   = $data['parents'][ $parent_id ];
				}
			}
		}
		$counts = array();
		$direct = array();
		if ( $cached ) {
			$counts = $cached['counts'];
			$direct = $cached['direct'];
		} else {
			foreach ( $data['terms'] as $id => $term ) {
				$counts[ $id ] = count( $unique[ $id ] ?? array() );
				$direct[ $id ] = count( $data['memberships'][ $id ] ?? array() );
			}
			if ( $cache_safe && $revision ) {
				// The extra transient writes can cost more than this query on small sites.
				if ( count( $post_ids ) >= $minimum_cache_posts ) {
					// Bound storage while allowing different widget option sets to coexist.
					if ( count( $entries ) >= 4 && ! isset( $entries[ $signature ] ) ) {
						array_shift( $entries );
					}
					$entries[ $signature ] = array(
						'counts' => $counts,
						'direct' => $direct,
					);
					set_transient(
						$cache_key,
						array(
							'revision' => $revision,
							'entries'  => $entries,
						),
						HOUR_IN_SECONDS
					);
				}
			}
		}
	} finally {
		if ( $lock ) {
			taxonomy_count_cache_unlock( $lock );
		}
	}
	if ( $closed_outline ) {
		$data['direct_counts'] = $direct;
	}
	foreach ( $data['terms'] as $id => $term ) {
		if ( $args['hide_empty'] && empty( $counts[ $id ] ) ) {
			unset( $data['terms'][ $id ], $data['parents'][ $id ] );
			continue; }
		$data['counts'][ $id ]                         = $counts[ $id ];
		$data['children'][ $data['parents'][ $id ] ][] = $id;
	}
	if ( 'count' === $args['sort_by'] ) {
		$counts = $data['counts'];
		foreach ( $data['children'] as &$siblings ) {
			usort(
				$siblings,
				static function ( $a, $b ) use ( $counts, $args ) {
					$comparison = $counts[ $a ] <=> $counts[ $b ];
					return $comparison ? ( 'DESC' === $args['order'] ? -$comparison : $comparison ) : ( $a <=> $b );
				}
			);
		}
		unset( $siblings );
	}
	return $data;
}

/**
 * Render nested terms and initially open post lists.
 *
 * @param int   $parent_id Parent node ID.
 * @param array $data Visible terms, memberships and counts.
 * @param int   $current_term Current term ID.
 * @param int   $current_post Current post ID.
 * @param array $open Set of initially open branches.
 * @param array $args Tree options or theme widget wrappers.
 */
function render_taxonomy_items( $parent_id, $data, $current_term, $current_post, $open, $args ) {
	$html = '';
	foreach ( $data['children'][ $parent_id ] ?? array() as $id ) {
		$term = $data['terms'][ $id ];
		$link = '<a class="ctrees-term-link" href="' . esc_url( $term['url'] ) . '"'
			. ( $id === $current_term ? ' aria-current="page"' : '' ) . '>' . esc_html( $term['label'] ) . '</a>';
		if ( $args['show_counts'] ) {
			$link .= '<span class="ctrees-count">(' . esc_html( number_format_i18n( $data['counts'][ $id ] ) ) . ')</span>'; }
		$children  = render_taxonomy_items( $id, $data, $current_term, $current_post, $open, $args );
		$post_list = '';
		$has_posts = $args['show_posts'] && ( ! empty( $data['memberships'][ $id ] ) || ! empty( $data['direct_counts'][ $id ] ) );
		if ( $has_posts && isset( $open[ $id ] ) ) {
			foreach ( $data['post_lists'][ $id ] ?? array() as $post_id ) {
				if ( isset( $data['posts'][ $post_id ] ) ) {
					$post_list .= post_link( $data['posts'][ $post_id ], $current_post ); }
			}
		}
		if ( $children || $has_posts ) {
			/* translators: %s: category or tag name. */
			$label = sprintf( __( 'Expand or collapse %s', 'content-trees' ), $term['label'] );
			$html .= '<li><details class="ctrees-term-branch" data-term-id="' . esc_attr( $id ) . '"'
				. ' data-has-posts="' . ( $has_posts ? '1' : '0' ) . '"' . ( $has_posts && isset( $open[ $id ] ) ? ' data-loaded="1"' : '' )
				. ( isset( $open[ $id ] ) ? ' open' : '' ) . '><summary aria-label="' . esc_attr( $label )
				. '" aria-description=""><span class="ctrees-toggle" aria-hidden="true"></span>' . $link . '</summary>'
				. ( $children ? '<ul class="ctrees-terms">' . $children . '</ul>' : '' )
				. ( $post_list ? '<ul class="ctrees-posts">' . $post_list . '</ul>' : '' ) . '</details></li>';
		} else {
			$html .= '<li>' . $link . '</li>';
		}
	}
	return $html;
}

/**
 * Render a Category or Tag tree.
 *
 * @param string $taxonomy Built-in taxonomy name.
 * @param array  $args Tree options or theme widget wrappers.
 */
function render_taxonomy( $taxonomy, $args = array() ) {
	if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
		return ''; }
	$args         = taxonomy_options( $taxonomy, $args );
	$data         = taxonomy_data( $taxonomy, $args, true );
	$queried      = get_queried_object();
	$current_term = ( ( 'category' === $taxonomy && is_category() ) || ( 'post_tag' === $taxonomy && is_tag() ) )
		&& $queried instanceof \WP_Term && $queried->taxonomy === $taxonomy ? (int) $queried->term_id : 0;
	$current_post = is_singular( 'post' ) && is_public_post( $queried ) ? (int) $queried->ID : 0;
	$open         = array();
	$explicit     = isset( $data['terms'][ $args['open_to'] ] ) ? $args['open_to'] : 0;
	foreach ( $data['terms'] as $id => $term ) {
		if ( $explicit ? $id !== $explicit : ( ! $args['auto_open'] || ( $id !== $current_term && ! isset( $data['memberships'][ $id ][ $current_post ] ) ) ) ) {
			continue; }
		while ( $id && ! isset( $open[ $id ] ) ) {
			$open[ $id ] = true;
			$id          = $data['parents'][ $id ]; }
	}
	// Prepare only initially open direct lists, using one union query for all paths.
	$wanted     = array();
	$open_terms = array();
	if ( $args['show_posts'] ) {
		foreach ( $open as $id => $unused ) {
			if ( empty( $data['memberships'][ $id ] ) ) {
				continue; }
			$open_terms[] = $id;
			foreach ( $data['memberships'][ $id ] as $post_id => $unused_post ) {
				$wanted[ $post_id ][] = $id; }
		}
	}
	if ( $open_terms ) {
		$query = taxonomy_post_query( $taxonomy, $open_terms, $args );
		foreach ( $query->posts as $post ) {
			if ( ! is_public_post( $post ) || ! isset( $wanted[ $post->ID ] ) ) {
				continue; }
			$data['posts'][ $post->ID ] = post_node( $post );
			foreach ( $wanted[ $post->ID ] as $term_id ) {
				$data['post_lists'][ $term_id ][] = $post->ID; }
		}
	}
	wp_enqueue_style( 'content-trees' );
	wp_enqueue_script( 'content-trees-taxonomy' );
	$label     = '' !== $args['title'] ? $args['title'] : taxonomy_title( $taxonomy );
	$messages  = 'category' === $taxonomy
		? array(
			'expanded'  => __( 'All category branches expanded.', 'content-trees' ),
			'collapsed' => __( 'All category branches collapsed.', 'content-trees' ),
		)
		: array(
			'expanded'  => __( 'All tag branches expanded.', 'content-trees' ),
			'collapsed' => __( 'All tag branches collapsed.', 'content-trees' ),
		);
	$messages += array(
		'loading'      => __( 'Loading posts…', 'content-trees' ),
		'loaded'       => __( 'Posts loaded.', 'content-trees' ),
		'empty'        => __( 'No posts in this term.', 'content-trees' ),
		'error'        => __( 'Posts could not be loaded. Try again or visit the term archive.', 'content-trees' ),
		'retry'        => __( 'Try again', 'content-trees' ),
		'bulkLoading'  => __( 'Loading tree posts…', 'content-trees' ),
		'bulkComplete' => __( 'Tree loading finished.', 'content-trees' ),
		'bulkError'    => __( 'Tree loading finished. Some branches could not be loaded. Use Try again in those branches.', 'content-trees' ),
	);
	$html      = '<nav class="ctrees ctrees-taxonomy-tree" data-taxonomy="' . esc_attr( $taxonomy ) . '" aria-label="' . esc_attr( $label )
		. '" data-taxonomy-endpoint="' . esc_url( rest_url( 'content-trees/v1/terms/' . $taxonomy ) )
		. '" data-current-id="' . esc_attr( $current_post ) . '" data-post-sort="' . esc_attr( $args['post_sort_by'] )
		. '" data-post-order="' . esc_attr( $args['post_order'] ) . '" data-exclude="' . esc_attr( implode( ',', $args['excluded_posts'] ) )
		. '" data-close-siblings="' . ( $args['close_siblings'] ? '1' : '0' ) . '" data-taxonomy-messages="' . esc_attr( wp_json_encode( $messages ) ) . '">';
	if ( $args['show_title'] ) {
		$html .= '<h2>' . esc_html( $args['title'] ) . '</h2>'; }
	$has_branches = false;
	foreach ( $data['terms'] as $id => $term ) {
		if ( ! empty( $data['children'][ $id ] ) || ( $args['show_posts'] && ( ! empty( $data['memberships'][ $id ] ) || ! empty( $data['direct_counts'][ $id ] ) ) ) ) {
			$has_branches = true;
			break; }
	}
	if ( $args['show_controls'] && $has_branches ) {
		$html .= '<div class="ctrees-controls" role="group" aria-label="' . esc_attr(
			'category' === $taxonomy
			? __( 'Category controls', 'content-trees' ) : __( 'Tag controls', 'content-trees' )
		) . '" hidden>'
			. '<button type="button" data-ctrees-action="open">' . esc_html__( 'Open All', 'content-trees' ) . '</button><span aria-hidden="true"> | </span>'
			. '<button type="button" data-ctrees-action="close">' . esc_html__( 'Close All', 'content-trees' ) . '</button></div>';
	}
	if ( $data['terms'] ) {
		$html .= '<ul class="ctrees-terms">' . render_taxonomy_items( 0, $data, $current_term, $current_post, $open, $args ) . '</ul>';
	} else {
		$filtered = $args['root'] || $args['excluded_terms'] || $args['excluded_posts'];
		$html    .= '<p>' . esc_html(
			$filtered ? __( 'No terms match these settings.', 'content-trees' ) : ( 'category' === $taxonomy
			? __( 'No categories with published posts yet.', 'content-trees' ) : __( 'No tags with published posts yet.', 'content-trees' ) )
		) . '</p>';
	}
	return $html . '</nav>';
}

/**
 * Focused entry points share only these fixed-taxonomy adapters.
 *
 * @param string $taxonomy Built-in taxonomy name.
 * @param string $name Provider name.
 * @param string $widget Widget class name.
 */
function register_taxonomy_tree( $taxonomy, $name, $widget ) {
	add_action(
		'init',
		static function () use ( $taxonomy, $name ) {
			register_block_type(
				__DIR__ . '/' . $name,
				array(
					'render_callback' => static function ( $attributes ) use ( $taxonomy ) {
							return render_taxonomy( $taxonomy, taxonomy_block_options( $taxonomy, $attributes ) );
					},
				)
			);
		}
	);
	add_shortcode(
		'content_trees_' . $name,
		static function ( $attributes ) use ( $taxonomy, $name ) {
			return render_taxonomy( $taxonomy, shortcode_atts( taxonomy_options( $taxonomy ), (array) $attributes, 'content_trees_' . $name ) );
		}
	);
	add_action(
		'widgets_init',
		static function () use ( $widget ) {
			register_widget( $widget );
		}
	);
}

add_action(
	'init',
	static function () {
		wp_register_script( 'content-trees-taxonomy', plugins_url( 'taxonomy.js', __FILE__ ), array( 'content-trees-tree' ), VERSION, true );
		wp_register_script(
			'content-trees-taxonomy-editor',
			plugins_url( 'taxonomy-editor.js', __FILE__ ),
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render', 'content-trees-editor-selector' ),
			VERSION,
			true
		);
		wp_set_script_translations( 'content-trees-taxonomy-editor', 'content-trees' );
	}
);

register_taxonomy_tree( 'category', 'categories', __NAMESPACE__ . '\\Categories_Widget' );
register_taxonomy_tree( 'post_tag', 'tags', __NAMESPACE__ . '\\Tags_Widget' );

require_once __DIR__ . '/class-taxonomy-widget.php';
