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

/**
 * Return the complete public page hierarchy, promoting eligible pages around
 * ineligible or missing ancestors without including those ancestors as nodes.
 */
function pages_hierarchy() {
    $query = new \WP_Query(array(
        'post_type' => 'page',
        'post_status' => array_keys(get_post_stati()),
        'posts_per_page' => -1,
        'no_found_rows' => true,
        'orderby' => array('menu_order' => 'ASC', 'ID' => 'ASC'),
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

    $parents = array();
    foreach ($pages as $id => $page) {
        $parent = (int) $page->post_parent;
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
    foreach ($children as &$siblings) {
        usort($siblings, static function ($left, $right) use ($pages) {
            $order = (int) $pages[$left]->menu_order <=> (int) $pages[$right]->menu_order;
            return $order !== 0 ? $order : ((int) $left <=> (int) $right);
        });
    }
    unset($siblings);

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
    $args = wp_parse_args($args, array('title' => __('Pages', 'content-trees'), 'show_title' => true));
    $title = is_string($args['title']) ? sanitize_text_field($args['title']) : __('Pages', 'content-trees');
    $show_title = enabled($args['show_title']) && $title !== '';
    $label = $title !== '' ? $title : __('Pages', 'content-trees');
    $hierarchy = pages_hierarchy();
    $current_id = is_page() ? (int) get_queried_object_id() : 0;
    if (!isset($hierarchy['pages'][$current_id])) { $current_id = 0; }

    $open_ancestors = array();
    $parent = $current_id ? $hierarchy['parents'][$current_id] : 0;
    while ($parent > 0 && !isset($open_ancestors[$parent])) {
        $open_ancestors[$parent] = true;
        $parent = $hierarchy['parents'][$parent] ?? 0;
    }

    wp_enqueue_style('content-trees');
    ob_start();
    ?>
    <nav class="ctrees ctrees-pages-tree" aria-label="<?php echo esc_attr($label); ?>">
        <?php if ($show_title) : ?><h2><?php echo esc_html($title); ?></h2><?php endif; ?>
        <?php if (!$hierarchy['pages']) : ?><p><?php esc_html_e('No published pages yet.', 'content-trees'); ?></p>
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
    return render_pages(array('title' => $title, 'show_title' => $title !== ''));
}

function render_pages_shortcode($attributes) {
    $attributes = shortcode_atts(array('title' => __('Pages', 'content-trees')), (array) $attributes, 'content_trees_pages');
    $title = is_string($attributes['title']) ? sanitize_text_field($attributes['title']) : __('Pages', 'content-trees');
    return render_pages(array('title' => $title, 'show_title' => $title !== ''));
}

add_shortcode('content_trees_pages', __NAMESPACE__ . '\\render_pages_shortcode');

require_once __DIR__ . '/class-pages-widget.php';
add_action('widgets_init', static function () {
    register_widget(__NAMESPACE__ . '\\Pages_Widget');
});
