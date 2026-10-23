<?php
namespace ContentTrees;

defined('ABSPATH') || exit;

add_action('init', static function () {
    register_block_type(__DIR__ . '/pages', array('render_callback' => __NAMESPACE__ . '\\render_pages_block'));
});

// Pages need the shared presentation only; Archive's script is loaded when an Archive is present.
add_action('wp_enqueue_scripts', static function () {
    global $wp_query;
    $has_pages = is_active_widget(false, false, 'ctrees_pages', true);
    foreach ($wp_query->posts ?? array() as $post) {
        if ($post instanceof \WP_Post) {
            $has_pages = $has_pages || has_shortcode($post->post_content, 'content_trees_pages') || has_block('content-trees/pages', $post);
        }
        if ($has_pages) { break; }
    }
    if (!$has_pages) {
        $settings = get_option('widget_block', array());
        foreach ($settings as $number => $widget) {
            if (isset($widget['content']) && has_block('content-trees/pages', $widget['content'])
                && is_active_widget(false, 'block-' . $number, 'block', true)) {
                $has_pages = true;
                break;
            }
        }
    }
    if ($has_pages) { wp_enqueue_style('content-trees'); }
});

function is_public_page($page) {
    return $page instanceof \WP_Post && $page->post_type === 'page'
        && $page->post_status === 'publish' && $page->post_password === '';
}

/** Keep block, widget and shortcode defaults and normalization together. */
function pages_options($args = array()) {
    $defaults = array('title' => __('Pages', 'content-trees'), 'show_title' => true,
        'sort_by' => 'menu_order', 'order' => 'ASC', 'auto_open' => true, 'open_to' => 0, 'root' => 0);
    $args = array_intersect_key(wp_parse_args($args, $defaults), $defaults);
    $args['title'] = is_string($args['title']) ? sanitize_text_field($args['title']) : $defaults['title'];
    $args['show_title'] = enabled($args['show_title']) && $args['title'] !== '';
    $args['sort_by'] = $args['sort_by'] === 'title' ? 'title' : 'menu_order';
    $args['order'] = $args['order'] === 'DESC' ? 'DESC' : 'ASC';
    $args['auto_open'] = enabled($args['auto_open']);
    $args['open_to'] = exclusion_ids(array($args['open_to']))[0] ?? 0;
    // A malformed saved root must not silently widen a scoped tree to all pages.
    $args['root'] = empty($args['root']) ? 0 : (exclusion_ids(array($args['root']))[0] ?? -1);
    return $args;
}

/** Traverse original ancestry, including hidden nodes, with cycle protection. */
function page_descendants($roots, $all) {
    $children = array();
    foreach ($all as $id => $page) { $children[(int) $page->post_parent][] = $id; }
    $descendants = array();
    $pending = $roots;
    while ($pending) {
        $id = array_pop($pending);
        if (isset($descendants[$id])) { continue; }
        $descendants[$id] = true;
        foreach ($children[$id] ?? array() as $child) { $pending[] = $child; }
    }
    return $descendants;
}

/**
 * Return the complete public page hierarchy, promoting eligible pages around
 * ineligible or missing ancestors without including those ancestors as nodes.
 */
function pages_hierarchy($args = array()) {
    $args = pages_options($args);
    $query = new \WP_Query(array(
        'post_type' => 'page',
        'post_status' => array_keys(get_post_stati()),
        'posts_per_page' => -1,
        'no_found_rows' => true,
        'orderby' => array($args['sort_by'] => $args['order'], 'ID' => 'ASC'),
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ));

    $all = array();
    $pages = array();
    foreach ($query->posts as $page) {
        if (!$page instanceof \WP_Post || $page->post_type !== 'page') { continue; }
        $all[$page->ID] = $page;
        if (is_public_page($page)) { $pages[$page->ID] = $page; }
    }

    if ($args['root']) {
        if (!isset($pages[$args['root']])) { return array('pages' => array(), 'parents' => array(), 'children' => array(0 => array())); }
        $scope = page_descendants(array($args['root']), $all);
        $pages = array_intersect_key($pages, $scope);
    }

    $parents = array();
    foreach ($pages as $id => $page) {
        $parent = $id === $args['root'] ? 0 : (int) $page->post_parent;
        $seen = array((int) $id => true);
        while ($parent > 0 && !isset($pages[$parent])) {
            if (isset($seen[$parent]) || !isset($all[$parent])) {
                $parent = 0;
                break;
            }
            $seen[$parent] = true;
            $parent = (int) $all[$parent]->post_parent;
        }
        $parents[$id] = $parent;
    }

    // Malformed eligible-parent cycles cannot be represented as a tree. Break
    // one edge deterministically so recursive rendering always terminates.
    $ids = array_keys($parents);
    sort($ids, SORT_NUMERIC);
    $visited = array();
    foreach ($ids as $id) {
        $path = array();
        $positions = array();
        $node = $id;
        while ($node > 0 && isset($parents[$node])) {
            if (isset($positions[$node])) {
                $cycle = array_slice($path, $positions[$node]);
                $parents[min($cycle)] = 0;
                break;
            }
            if (isset($visited[$node])) { break; }
            $positions[$node] = count($path);
            $path[] = $node;
            $node = $parents[$node];
        }
        foreach ($path as $path_id) { $visited[$path_id] = true; }
    }

    $children = array(0 => array());
    foreach ($pages as $id => $page) {
        $parent = $parents[$id];
        // A child's menu order can put it before its parent in the query. Do
        // not replace a child list already populated by earlier descendants.
        if (!isset($children[$id])) { $children[$id] = array(); }
        if (!isset($children[$parent])) { $children[$parent] = array(); }
        $children[$parent][] = $id;
    }
    // Query order is retained within each sibling list, including promoted
    // nodes. WordPress/database collation supplies title order and ID breaks ties.

    return array('pages' => $pages, 'parents' => $parents, 'children' => $children);
}

function page_label($page) {
    $label = html_entity_decode(wp_strip_all_tags(get_the_title($page), true), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $label !== '' ? $label : __('(Untitled)', 'content-trees');
}

function render_page_items($parent, $hierarchy, $current_id, $open_ancestors) {
    $html = '';
    foreach ($hierarchy['children'][$parent] ?? array() as $id) {
        $page = $hierarchy['pages'][$id];
        $label = page_label($page);
        $children = $hierarchy['children'][$id] ?? array();
        $link = '<a class="ctrees-page-link" href="' . esc_url(get_permalink($page)) . '"'
            . ($id === $current_id ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        if ($children) {
            /* translators: %s: page title. */
            $disclosure_label = sprintf(__('Expand or collapse %s', 'content-trees'), $label);
            $html .= '<li><details class="ctrees-page-branch"' . (isset($open_ancestors[$id]) ? ' open' : '') . '>'
                . '<summary aria-label="' . esc_attr($disclosure_label) . '" aria-description="">'
                . '<span class="ctrees-toggle" aria-hidden="true"></span>' . $link . '</summary>'
                . '<ul class="ctrees-pages">' . render_page_items($id, $hierarchy, $current_id, $open_ancestors) . '</ul>'
                . '</details></li>';
        } else {
            $html .= '<li>' . $link . '</li>';
        }
    }
    return $html;
}

function render_pages($args = array()) {
    $args = pages_options($args);
    $title = $args['title'];
    $show_title = $args['show_title'];
    $label = $title !== '' ? $title : __('Pages', 'content-trees');
    $hierarchy = pages_hierarchy($args);
    $current_id = is_page() ? (int) get_queried_object_id() : 0;
    if (!isset($hierarchy['pages'][$current_id])) { $current_id = 0; }

    $open_ancestors = array();
    $target = isset($hierarchy['pages'][$args['open_to']]) ? $args['open_to'] : ($args['auto_open'] ? $current_id : 0);
    $parent = $target ? $hierarchy['parents'][$target] : 0;
    while ($parent > 0 && !isset($open_ancestors[$parent])) {
        $open_ancestors[$parent] = true;
        $parent = $hierarchy['parents'][$parent] ?? 0;
    }

    wp_enqueue_style('content-trees');
    ob_start();
    ?>
    <nav class="ctrees ctrees-pages-tree" aria-label="<?php echo esc_attr($label); ?>">
        <?php if ($show_title) : ?><h2><?php echo esc_html($title); ?></h2><?php endif; ?>
        <?php if (!$hierarchy['pages']) : ?><p><?php echo esc_html($args['root'] ? __('No pages match these settings.', 'content-trees') : __('No published pages yet.', 'content-trees')); ?></p>
        <?php else : ?><ul class="ctrees-pages"><?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_page_items() escapes labels, attributes and URLs at construction; remaining markup is constant.
            echo render_page_items(0, $hierarchy, $current_id, $open_ancestors);
        ?></ul><?php endif; ?>
    </nav>
    <?php
    return trim(preg_replace('/>\s+</', '><', ob_get_clean()));
}

function render_pages_block($attributes) {
    $title = isset($attributes['title']) && is_string($attributes['title'])
        ? sanitize_text_field($attributes['title'])
        : __('Pages', 'content-trees');
    return render_pages(array('title' => $title, 'show_title' => $title !== '',
        'sort_by' => $attributes['sortBy'] ?? 'menu_order', 'order' => $attributes['order'] ?? 'ASC',
        'auto_open' => $attributes['autoOpen'] ?? true, 'root' => $attributes['root'] ?? 0));
}

function render_pages_shortcode($attributes) {
    return render_pages(shortcode_atts(pages_options(), (array) $attributes, 'content_trees_pages'));
}

add_shortcode('content_trees_pages', __NAMESPACE__ . '\\render_pages_shortcode');

add_action('rest_api_init', static function () {
    register_rest_route('content-trees/v1', '/page-options', array(
        'methods' => 'GET',
        'permission_callback' => static function () {
            return current_user_can('edit_pages') || current_user_can('edit_theme_options');
        },
        'args' => array(
            'search' => array('type' => 'string', 'default' => '', 'maxLength' => 200),
            'include' => array('type' => 'string', 'pattern' => '^[0-9,]*$', 'default' => ''),
        ),
        'callback' => static function ($request) {
            $args = array('post_type' => 'page', 'post_status' => 'publish', 'has_password' => false,
                'posts_per_page' => 20, 'no_found_rows' => true, 'orderby' => array('title' => 'ASC', 'ID' => 'ASC'),
                'update_post_meta_cache' => false, 'update_post_term_cache' => false);
            if ($request['include'] !== '') {
                $args['post__in'] = exclusion_ids($request['include']);
                if (!$args['post__in']) { return rest_ensure_response(array('items' => array())); }
                $args['posts_per_page'] = -1;
            } else {
                $args['s'] = $request['search'];
                $args['search_columns'] = array('post_title');
            }
            $query = new \WP_Query($args);
            return rest_ensure_response(array('items' => array_map(static function ($page) {
                return array('id' => $page->ID, 'label' => page_label($page), 'url' => esc_url_raw(get_permalink($page)));
            }, array_values(array_filter($query->posts, __NAMESPACE__ . '\\is_public_page')))));
        },
    ));
});

require_once __DIR__ . '/class-pages-widget.php';
add_action('widgets_init', static function () {
    register_widget(__NAMESPACE__ . '\\Pages_Widget');
});
