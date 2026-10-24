<?php
namespace ContentTrees;

defined('ABSPATH') || exit;

/** Only the two built-in navigation providers are supported. */
function taxonomy_title($taxonomy) {
    return $taxonomy === 'category' ? __('Categories', 'content-trees') : __('Tags', 'content-trees');
}

function taxonomy_options($taxonomy, $args = array()) {
    $defaults = array('title' => taxonomy_title($taxonomy), 'show_title' => true, 'show_posts' => true,
        'root' => 0, 'excluded_terms' => array(), 'excluded_posts' => array(), 'sort_by' => 'name', 'order' => 'ASC',
        'post_sort_by' => 'date', 'post_order' => 'DESC', 'show_counts' => true, 'hide_empty' => true,
        'auto_open' => true, 'open_to' => 0, 'show_controls' => false, 'close_siblings' => false);
    $args = array_intersect_key(wp_parse_args($args, $defaults), $defaults);
    $args['title'] = is_string($args['title']) ? sanitize_text_field($args['title']) : $defaults['title'];
    $args['show_title'] = enabled($args['show_title']) && $args['title'] !== '';
    foreach (array('show_posts', 'show_counts', 'hide_empty', 'auto_open', 'show_controls', 'close_siblings') as $flag) {
        $args[$flag] = enabled($args[$flag]);
    }
    $args['root'] = $taxonomy !== 'category' || empty($args['root']) ? 0 : (exclusion_ids(array($args['root']))[0] ?? -1);
    $args['open_to'] = exclusion_ids(array($args['open_to']))[0] ?? 0;
    foreach (array('excluded_terms', 'excluded_posts') as $field) { $args[$field] = exclusion_ids($args[$field]); }
    $args['sort_by'] = in_array($args['sort_by'], array('name', 'count'), true) ? $args['sort_by'] : 'name';
    $args['post_sort_by'] = in_array($args['post_sort_by'], array('title', 'date', 'modified'), true) ? $args['post_sort_by'] : 'date';
    foreach (array('order', 'post_order') as $field) { $args[$field] = in_array($args[$field], array('ASC', 'DESC'), true) ? $args[$field] : $defaults[$field]; }
    return $args;
}

/** Resolve public term choices, including empty terms, without content counts. */
function taxonomy_option_nodes($taxonomy, $include = array(), $search = '') {
    if (!in_array($taxonomy, array('category', 'post_tag'), true)) { return array(); }
    $ids = exclusion_ids($include);
    if ($include && !$ids) { return array(); }
    $args = array('taxonomy' => $taxonomy, 'hide_empty' => false, 'hierarchical' => false,
        'number' => $include ? 0 : 20, 'orderby' => 'name', 'order' => 'ASC',
        'ctrees_name_order' => 'ASC', 'update_term_meta_cache' => false);
    if ($ids) { $args['include'] = $ids; } else { $args['search'] = $search; }
    $terms = get_terms($args);
    if (is_wp_error($terms)) { return array(); }
    $items = array();
    foreach ($terms as $term) {
        if (!$term instanceof \WP_Term || $term->taxonomy !== $taxonomy) { continue; }
        $url = get_term_link($term, $taxonomy);
        if (is_wp_error($url)) { continue; }
        $label = html_entity_decode(wp_strip_all_tags($term->name, true), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $items[] = array('id' => (int) $term->term_id, 'label' => $label !== '' ? $label : __('(Untitled)', 'content-trees'), 'url' => esc_url_raw($url));
    }
    return $items;
}

add_action('rest_api_init', static function () {
    foreach (array('category-options' => 'category', 'tag-options' => 'post_tag') as $route => $taxonomy) {
        register_rest_route('content-trees/v1', '/' . $route, array(
            'methods' => 'GET',
            'permission_callback' => static function () { return current_user_can('edit_posts') || current_user_can('edit_theme_options'); },
            'args' => array('search' => array('type' => 'string', 'maxLength' => 200, 'default' => ''),
                'include' => array('type' => 'string', 'pattern' => '^[0-9,]*$', 'default' => '')),
            'callback' => static function ($request) use ($taxonomy) {
                $ids = exclusion_ids($request['include']);
                if ($request['include'] !== '' && !$ids) { return rest_ensure_response(array('items' => array())); }
                return rest_ensure_response(array('items' => taxonomy_option_nodes($taxonomy, $ids, $request['search'])));
            },
        ));
    }
});

/** Keep the block adapter explicit; saved first-slice attributes retain defaults. */
function taxonomy_block_options($taxonomy, $attributes) {
    $map = array('title' => 'title', 'root' => 'root', 'showPosts' => 'show_posts', 'sortBy' => 'sort_by', 'order' => 'order',
        'postSortBy' => 'post_sort_by', 'postOrder' => 'post_order', 'showCounts' => 'show_counts', 'hideEmpty' => 'hide_empty',
        'autoOpen' => 'auto_open', 'showControls' => 'show_controls', 'closeSiblings' => 'close_siblings',
        'exclude' => 'excluded_terms', 'excludePosts' => 'excluded_posts');
    $args = array();
    foreach ($map as $attribute => $option) { if (array_key_exists($attribute, $attributes)) { $args[$option] = $attributes[$attribute]; } }
    return taxonomy_options($taxonomy, $args);
}

// Preserve database name collation, with a stable ID tie for these queries only.
add_filter('get_terms_orderby', static function ($orderby, $args) {
    return !empty($args['ctrees_name_order']) ? $orderby . ($args['ctrees_name_order'] === 'DESC' ? ' DESC' : ' ASC') . ', t.term_id' : $orderby;
}, 10, 2);

/** Resolve original category ancestry without disclosing filtered parent names. */
function taxonomy_original_parents($taxonomy) {
    global $wpdb;
    $original = array();
    if ($taxonomy === 'category') {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Request-local ID/parent edges preserve ancestry around filtered terms; labels and links use filtered core APIs, with no persistent snapshot.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT term_id, parent FROM %i WHERE taxonomy = %s", $wpdb->term_taxonomy, $taxonomy));
        foreach ($rows as $row) { $original[(int) $row->term_id] = (int) $row->parent; }
    }
    return $original;
}

function taxonomy_descendants($roots, $parents) {
    $children = $result = array();
    foreach ($parents as $id => $parent) { $children[$parent][] = $id; }
    while ($roots) {
        $id = array_pop($roots);
        if (isset($result[$id])) { continue; }
        $result[$id] = true;
        foreach ($children[$id] ?? array() as $child) { $roots[] = $child; }
    }
    return $result;
}

function taxonomy_parents($taxonomy, $terms, $original = null, $root = 0) {
    if ($original === null) { $original = taxonomy_original_parents($taxonomy); }
    $parents = array();
    foreach ($terms as $id => $term) {
        $parent = $taxonomy === 'category' && $id !== $root ? (int) $term['term']->parent : 0;
        $seen = array($id => true);
        while ($parent && !isset($terms[$parent])) {
            if (isset($seen[$parent]) || !isset($original[$parent])) { $parent = 0; break; }
            $seen[$parent] = true;
            $parent = $original[$parent];
        }
        $parents[$id] = $parent;
    }
    // Break the smallest-ID edge in malformed cycles, independent of name order.
    $ids = array_keys($parents);
    sort($ids, SORT_NUMERIC);
    $visited = array();
    foreach ($ids as $id) {
        $path = array();
        $positions = array();
        while ($id && isset($parents[$id]) && !isset($visited[$id])) {
            if (isset($positions[$id])) {
                $parents[min(array_slice($path, $positions[$id]))] = 0;
                break;
            }
            $positions[$id] = count($path);
            $path[] = $id;
            $id = $parents[$id];
        }
        foreach ($path as $node) { $visited[$node] = true; }
    }
    return $parents;
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

/** Complete term structure and lightweight memberships; no post presentation. */
function taxonomy_data($taxonomy, $args = array()) {
    $data = array('terms' => array(), 'parents' => array(), 'children' => array(0 => array()),
        'memberships' => array(), 'counts' => array(), 'posts' => array());
    if (!in_array($taxonomy, array('category', 'post_tag'), true)) { return $data; }
    // Retain the first slice's internal boolean convenience for existing checks.
    $args = taxonomy_options($taxonomy, is_bool($args) ? array('show_posts' => $args) : $args);
    $results = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false, 'hierarchical' => false,
        'number' => 0, 'orderby' => 'name', 'order' => 'ASC', 'ctrees_name_order' => $args['sort_by'] === 'name' ? $args['order'] : 'ASC', 'update_term_meta_cache' => false));
    if (is_wp_error($results)) { return $data; }
    foreach ($results as $term) {
        if (!$term instanceof \WP_Term || $term->taxonomy !== $taxonomy) { continue; }
        $url = get_term_link($term, $taxonomy);
        if (is_wp_error($url)) { continue; }
        $label = html_entity_decode(wp_strip_all_tags($term->name, true), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $data['terms'][(int) $term->term_id] = array('term' => $term, 'url' => $url,
            'label' => $label !== '' ? $label : __('(Untitled)', 'content-trees'));
    }
    if (!$data['terms']) { return $data; }
    $original = taxonomy_original_parents($taxonomy);
    if ($args['excluded_terms']) {
        $excluded = $taxonomy === 'category' ? taxonomy_descendants($args['excluded_terms'], $original) : array_fill_keys($args['excluded_terms'], true);
        $data['terms'] = array_diff_key($data['terms'], $excluded);
    }
    if ($args['root']) {
        if (!isset($data['terms'][$args['root']])) { $data['terms'] = array(); return $data; }
        $data['terms'] = array_intersect_key($data['terms'], taxonomy_descendants(array($args['root']), $original));
    }
    if (!$data['terms']) { return $data; }
    $data['parents'] = taxonomy_parents($taxonomy, $data['terms'], $original, $args['root']);
    $needs_memberships = $args['show_posts'] || $args['show_counts'] || $args['hide_empty'] || $args['sort_by'] === 'count';
    $post_ids = array();
    if ($needs_memberships) {
        $query = taxonomy_post_query($taxonomy, array_keys($data['terms']), $args, true);
        $post_ids = array_values(array_diff(exclusion_ids($query->posts), $args['excluded_posts']));
    } elseif ($args['auto_open'] && is_singular('post') && is_public_post(get_queried_object())) {
        $current = (int) get_queried_object_id();
        if (!in_array($current, $args['excluded_posts'], true)) { $post_ids = array($current); }
    }
    foreach (taxonomy_membership_rows($taxonomy, array_keys($data['terms']), $post_ids) as $row) {
        $data['memberships'][(int) $row->term_id][(int) $row->object_id] = true;
    }
    $unique = array();
    foreach ($data['memberships'] as $term_id => $posts) {
        foreach ($posts as $id => $unused) {
            $parent = $term_id;
            while ($parent) {
                $unique[$parent][$id] = true;
                $parent = $data['parents'][$parent];
            }
        }
    }
    foreach ($data['terms'] as $id => $term) {
        if ($args['hide_empty'] && empty($unique[$id])) { unset($data['terms'][$id], $data['parents'][$id]); continue; }
        $data['counts'][$id] = count($unique[$id] ?? array());
        $data['children'][$data['parents'][$id]][] = $id;
    }
    if ($args['sort_by'] === 'count') {
        $counts = $data['counts'];
        foreach ($data['children'] as &$siblings) {
            usort($siblings, static function ($a, $b) use ($counts, $args) {
                $comparison = $counts[$a] <=> $counts[$b];
                return $comparison ? ($args['order'] === 'DESC' ? -$comparison : $comparison) : ($a <=> $b);
            });
        }
        unset($siblings);
    }
    return $data;
}

function render_taxonomy_items($parent, $data, $current_term, $current_post, $open, $args) {
    $html = '';
    foreach ($data['children'][$parent] ?? array() as $id) {
        $term = $data['terms'][$id];
        $link = '<a class="ctrees-term-link" href="' . esc_url($term['url']) . '"'
            . ($id === $current_term ? ' aria-current="page"' : '') . '>' . esc_html($term['label']) . '</a>';
        if ($args['show_counts']) { $link .= '<span class="ctrees-count">(' . esc_html(number_format_i18n($data['counts'][$id])) . ')</span>'; }
        $children = render_taxonomy_items($id, $data, $current_term, $current_post, $open, $args);
        $post_list = '';
        $has_posts = $args['show_posts'] && !empty($data['memberships'][$id]);
        if ($has_posts && isset($open[$id])) {
            foreach ($data['post_lists'][$id] ?? array() as $post_id) {
                if (isset($data['posts'][$post_id])) { $post_list .= post_link($data['posts'][$post_id], $current_post); }
            }
        }
        if ($children || $has_posts) {
            /* translators: %s: category or tag name. */
            $label = sprintf(__('Expand or collapse %s', 'content-trees'), $term['label']);
            $html .= '<li><details class="ctrees-term-branch" data-term-id="' . esc_attr($id) . '"'
                . ' data-has-posts="' . ($has_posts ? '1' : '0') . '"' . ($has_posts && isset($open[$id]) ? ' data-loaded="1"' : '')
                . (isset($open[$id]) ? ' open' : '') . '><summary aria-label="' . esc_attr($label)
                . '" aria-description=""><span class="ctrees-toggle" aria-hidden="true"></span>' . $link . '</summary>'
                . ($children ? '<ul class="ctrees-terms">' . $children . '</ul>' : '')
                . ($post_list ? '<ul class="ctrees-posts">' . $post_list . '</ul>' : '') . '</details></li>';
        } else {
            $html .= '<li>' . $link . '</li>';
        }
    }
    return $html;
}

function render_taxonomy($taxonomy, $args = array()) {
    if (!in_array($taxonomy, array('category', 'post_tag'), true)) { return ''; }
    $args = taxonomy_options($taxonomy, $args);
    $data = taxonomy_data($taxonomy, $args);
    $queried = get_queried_object();
    $current_term = (($taxonomy === 'category' && is_category()) || ($taxonomy === 'post_tag' && is_tag()))
        && $queried instanceof \WP_Term && $queried->taxonomy === $taxonomy ? (int) $queried->term_id : 0;
    $current_post = is_singular('post') && is_public_post($queried) ? (int) $queried->ID : 0;
    $open = array();
    $explicit = isset($data['terms'][$args['open_to']]) ? $args['open_to'] : 0;
    foreach ($data['terms'] as $id => $term) {
        if ($explicit ? $id !== $explicit : (!$args['auto_open'] || ($id !== $current_term && !isset($data['memberships'][$id][$current_post])))) { continue; }
        while ($id && !isset($open[$id])) { $open[$id] = true; $id = $data['parents'][$id]; }
    }
    // Prepare only initially open direct lists, using one union query for all paths.
    $wanted = $open_terms = array();
    if ($args['show_posts']) {
        foreach ($open as $id => $unused) {
            if (empty($data['memberships'][$id])) { continue; }
            $open_terms[] = $id;
            foreach ($data['memberships'][$id] as $post_id => $unused_post) { $wanted[$post_id][] = $id; }
        }
    }
    if ($open_terms) {
        $query = taxonomy_post_query($taxonomy, $open_terms, $args);
        foreach ($query->posts as $post) {
            if (!is_public_post($post) || !isset($wanted[$post->ID])) { continue; }
            $data['posts'][$post->ID] = post_node($post);
            foreach ($wanted[$post->ID] as $term_id) { $data['post_lists'][$term_id][] = $post->ID; }
        }
    }
    wp_enqueue_style('content-trees');
    wp_enqueue_script('content-trees-taxonomy');
    $label = $args['title'] !== '' ? $args['title'] : taxonomy_title($taxonomy);
    $messages = $taxonomy === 'category'
        ? array('expanded' => __('All category branches expanded.', 'content-trees'), 'collapsed' => __('All category branches collapsed.', 'content-trees'))
        : array('expanded' => __('All tag branches expanded.', 'content-trees'), 'collapsed' => __('All tag branches collapsed.', 'content-trees'));
    $messages += array('loading' => __('Loading posts…', 'content-trees'), 'loaded' => __('Posts loaded.', 'content-trees'),
        'empty' => __('No posts in this term.', 'content-trees'), 'error' => __('Posts could not be loaded. Try again or visit the term archive.', 'content-trees'),
        'retry' => __('Try again', 'content-trees'), 'bulkLoading' => __('Loading tree posts…', 'content-trees'),
        'bulkComplete' => __('Tree loading finished.', 'content-trees'), 'bulkError' => __('Tree loading finished. Some branches could not be loaded. Use Try again in those branches.', 'content-trees'));
    $html = '<nav class="ctrees ctrees-taxonomy-tree" data-taxonomy="' . esc_attr($taxonomy) . '" aria-label="' . esc_attr($label)
        . '" data-taxonomy-endpoint="' . esc_url(rest_url('content-trees/v1/terms/' . $taxonomy))
        . '" data-current-id="' . esc_attr($current_post) . '" data-post-sort="' . esc_attr($args['post_sort_by'])
        . '" data-post-order="' . esc_attr($args['post_order']) . '" data-exclude="' . esc_attr(implode(',', $args['excluded_posts']))
        . '" data-close-siblings="' . ($args['close_siblings'] ? '1' : '0') . '" data-taxonomy-messages="' . esc_attr(wp_json_encode($messages)) . '">';
    if ($args['show_title']) { $html .= '<h2>' . esc_html($args['title']) . '</h2>'; }
    $has_branches = false;
    foreach ($data['terms'] as $id => $term) {
        if (!empty($data['children'][$id]) || ($args['show_posts'] && !empty($data['memberships'][$id]))) { $has_branches = true; break; }
    }
    if ($args['show_controls'] && $has_branches) {
        $html .= '<div class="ctrees-controls" role="group" aria-label="' . esc_attr($taxonomy === 'category'
            ? __('Category controls', 'content-trees') : __('Tag controls', 'content-trees')) . '" hidden>'
            . '<button type="button" data-ctrees-action="open">' . esc_html__('Open All', 'content-trees') . '</button><span aria-hidden="true"> | </span>'
            . '<button type="button" data-ctrees-action="close">' . esc_html__('Close All', 'content-trees') . '</button></div>';
    }
    if ($data['terms']) {
        $html .= '<ul class="ctrees-terms">' . render_taxonomy_items(0, $data, $current_term, $current_post, $open, $args) . '</ul>';
    } else {
        $filtered = $args['root'] || $args['excluded_terms'] || $args['excluded_posts'];
        $html .= '<p>' . esc_html($filtered ? __('No terms match these settings.', 'content-trees') : ($taxonomy === 'category'
            ? __('No categories with published posts yet.', 'content-trees') : __('No tags with published posts yet.', 'content-trees'))) . '</p>';
    }
    return $html . '</nav>';
}

/** Focused entry points share only these fixed-taxonomy adapters. */
function register_taxonomy_tree($taxonomy, $name, $widget) {
    add_action('init', static function () use ($taxonomy, $name) {
        register_block_type(__DIR__ . '/' . $name, array('render_callback' => static function ($attributes) use ($taxonomy) {
            return render_taxonomy($taxonomy, taxonomy_block_options($taxonomy, $attributes));
        }));
    });
    add_shortcode('content_trees_' . $name, static function ($attributes) use ($taxonomy, $name) {
        return render_taxonomy($taxonomy, shortcode_atts(taxonomy_options($taxonomy), (array) $attributes, 'content_trees_' . $name));
    });
    add_action('widgets_init', static function () use ($widget) { register_widget($widget); });
    add_filter('widget_types_to_hide_from_legacy_widget_block', static function ($types) use ($name) {
        $types[] = 'ctrees_' . $name;
        return $types;
    });
}

add_action('init', static function () {
    wp_register_script('content-trees-taxonomy', plugins_url('taxonomy.js', __FILE__), array('content-trees-post-loader'), VERSION, true);
    wp_register_script('content-trees-taxonomy-editor', plugins_url('taxonomy-editor.js', __FILE__),
        array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render', 'content-trees-editor-selector'), VERSION, true);
	wp_set_script_translations( 'content-trees-taxonomy-editor', 'content-trees' );
});

register_taxonomy_tree('category', 'categories', __NAMESPACE__ . '\\Categories_Widget');
register_taxonomy_tree('post_tag', 'tags', __NAMESPACE__ . '\\Tags_Widget');

// Classic/block widgets can render after wp_head, as do existing providers.
add_action('wp_enqueue_scripts', static function () {
    global $wp_query;
    foreach (array('categories', 'tags') as $name) {
        $has_tree = is_active_widget(false, false, 'ctrees_' . $name, true);
        foreach ($wp_query->posts ?? array() as $post) {
            if ($post instanceof \WP_Post && (has_shortcode($post->post_content, 'content_trees_' . $name)
                || has_block('content-trees/' . $name, $post))) { $has_tree = true; break; }
        }
        foreach (get_option('widget_block', array()) as $number => $widget) {
            if (isset($widget['content']) && has_block('content-trees/' . $name, $widget['content'])
                && is_active_widget(false, 'block-' . $number, 'block', true)) { $has_tree = true; break; }
        }
        if ($has_tree) { wp_enqueue_style('content-trees'); wp_enqueue_script('content-trees-taxonomy'); return; }
    }
});

require_once __DIR__ . '/class-taxonomy-widget.php';
