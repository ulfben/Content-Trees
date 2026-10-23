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
    foreach (array('order', 'post_order') as $field) { $args[$field] = $args[$field] === 'DESC' ? 'DESC' : 'ASC'; }
    return $args;
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

/** Complete direct memberships; post presentation is optional and prepared last. */
function taxonomy_data($taxonomy, $args = array()) {
    $data = array('terms' => array(), 'parents' => array(), 'children' => array(0 => array()),
        'memberships' => array(), 'counts' => array(), 'posts' => array());
    if (!in_array($taxonomy, array('category', 'post_tag'), true)) { return $data; }
    // Retain the first slice's internal boolean convenience for existing checks.
    $args = taxonomy_options($taxonomy, is_bool($args) ? array('show_posts' => $args) : $args);
    $show_posts = $args['show_posts'];
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
    $query = new \WP_Query(array('post_type' => 'post', 'post_status' => 'publish', 'has_password' => false,
        'posts_per_page' => -1, 'no_found_rows' => true, 'ignore_sticky_posts' => true,
        'orderby' => $show_posts ? array($args['post_sort_by'] => $args['post_order'], 'ID' => 'ASC') : array('ID' => 'ASC'), 'update_post_meta_cache' => false,
        'update_post_term_cache' => $show_posts,
        'tax_query' => array(array('taxonomy' => $taxonomy, 'terms' => array_keys($data['terms']),
            'field' => 'term_id', 'include_children' => false))));
    $posts = array();
    $excluded_posts = array_fill_keys($args['excluded_posts'], true);
    foreach ($query->posts as $post) {
        if (is_public_post($post) && !isset($excluded_posts[$post->ID])) { $posts[(int) $post->ID] = $post; }
    }
    $direct = array();
    if ($posts) {
        $memberships = wp_get_object_terms(array_keys($posts), $taxonomy,
            array('fields' => 'all_with_object_id', 'orderby' => 'none', 'update_term_meta_cache' => false));
        if (!is_wp_error($memberships)) {
            foreach ($memberships as $term) {
                $post_id = (int) $term->object_id;
                $term_id = (int) $term->term_id;
                if ($term->taxonomy === $taxonomy && isset($posts[$post_id], $data['terms'][$term_id])) {
                    $direct[$post_id][$term_id] = true;
                }
            }
        }
    }
    $unique = array();
    // Query order supplies publication-date ordering within each direct post list.
    foreach ($posts as $id => $post) {
        foreach ($direct[$id] ?? array() as $term_id => $unused) {
            $data['memberships'][$term_id][$id] = true;
            $parent = $term_id;
            while ($parent) {
                $unique[$parent][$id] = true;
                $parent = $data['parents'][$parent];
            }
        }
        // Counts and visibility need memberships, never post titles or permalinks.
        if ($show_posts && isset($direct[$id])) { $data['posts'][$id] = post_node($post); }
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
        if ($args['show_posts']) {
            foreach ($data['memberships'][$id] ?? array() as $post_id => $unused) {
                $post_list .= post_link($data['posts'][$post_id], $current_post);
            }
        }
        if ($children || $post_list) {
            /* translators: %s: category or tag name. */
            $label = sprintf(__('Expand or collapse %s', 'content-trees'), $term['label']);
            $html .= '<li><details class="ctrees-term-branch" data-term-id="' . esc_attr($id) . '"'
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
    wp_enqueue_style('content-trees');
    wp_enqueue_script('content-trees-taxonomy');
    $label = $args['title'] !== '' ? $args['title'] : taxonomy_title($taxonomy);
    $messages = $taxonomy === 'category'
        ? array('expanded' => __('All category branches expanded.', 'content-trees'), 'collapsed' => __('All category branches collapsed.', 'content-trees'))
        : array('expanded' => __('All tag branches expanded.', 'content-trees'), 'collapsed' => __('All tag branches collapsed.', 'content-trees'));
    $html = '<nav class="ctrees ctrees-taxonomy-tree" data-taxonomy="' . esc_attr($taxonomy) . '" aria-label="' . esc_attr($label)
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
            return render_taxonomy($taxonomy, array('title' => $attributes['title'] ?? taxonomy_title($taxonomy),
                'show_posts' => $attributes['showPosts'] ?? true));
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
    wp_register_script('content-trees-taxonomy', plugins_url('taxonomy.js', __FILE__), array(), VERSION, true);
    wp_register_script('content-trees-taxonomy-editor', plugins_url('taxonomy-editor.js', __FILE__),
        array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render'), VERSION, true);
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
