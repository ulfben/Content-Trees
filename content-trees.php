<?php
/**
 * Plugin Name: Content Trees
 * Description: Collapsible navigation for your archives, pages, categories and tags.
 * Version: 0.9.5
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: content-trees
 */

namespace ContentTrees;

defined('ABSPATH') || exit;

const VERSION = '0.9.5';

function register_assets() {
    wp_register_style('content-trees', plugins_url('archive.css', __FILE__), array(), VERSION);
    wp_register_script('content-trees', plugins_url('archive.js', __FILE__), array(), VERSION, true);
}

add_action('init', static function () {
    register_assets();
    register_block_type(__DIR__ . '/block', array('render_callback' => __NAMESPACE__ . '\\render_archive_block'));
});

function enqueue_assets() {
    wp_enqueue_style('content-trees');
    wp_enqueue_script('content-trees');
}

// Widget output follows wp_head. Enqueue beforehand so theme styles load correctly.
add_action('wp_enqueue_scripts', static function () {
    global $wp_query;
    if (is_active_widget(false, false, 'ctrees_archive', true)) {
        enqueue_assets();
        return;
    }
    // Listing pages may render a shortcode in any queried post, not just the first.
    foreach ($wp_query->posts ?? array() as $post) {
        if ($post instanceof \WP_Post && (has_shortcode($post->post_content, 'content_trees_archive') || has_block('content-trees/archive', $post))) {
            enqueue_assets();
            return;
        }
    }
    // Block widgets render after the head, just like classic widgets.
    $settings = get_option('widget_block', array());
    foreach ($settings as $number => $widget) {
        if (isset($widget['content'])
            && has_block('content-trees/archive', $widget['content'])
            && is_active_widget(false, 'block-' . $number, 'block', true)) {
            enqueue_assets();
            return;
        }
    }
});

/** Shared boolean settings for blocks, classic widgets and shortcodes. */
function enabled($value) {
    return in_array($value, array(true, 1, '1', 'true', 'on'), true);
}

/** Normalize saved/shortcode exclusions without accepting nested or malformed input. */
function exclusion_ids($value) {
    $values = is_array($value) ? $value : (is_string($value) ? preg_split('/[\s,]+/', $value) : array());
    $ids = array();
    foreach ($values as $id) {
        if ((is_int($id) || is_string($id)) && ctype_digit((string) $id) && (int) $id > 0) {
            $ids[] = (int) $id;
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function archive_order($value) {
    return $value === 'ASC' ? 'ASC' : 'DESC';
}

function render_archive_block($attributes) {
    $title = isset($attributes['title']) ? sanitize_text_field($attributes['title']) : __('Archives', 'content-trees');
    return render(array(
        'title' => $title !== '' ? $title : __('Archives', 'content-trees'),
        'show_title' => $title !== '',
        'show_counts' => $attributes['showCounts'] ?? true,
        'show_archive_links' => $attributes['showArchiveLinks'] ?? true,
        'show_controls' => $attributes['showControls'] ?? false,
        'auto_open' => $attributes['autoOpen'] ?? true,
        'show_posts' => $attributes['showPosts'] ?? true,
        'group_years' => $attributes['groupYears'] ?? true,
        'close_siblings' => $attributes['closeSiblings'] ?? false,
        'order' => $attributes['order'] ?? 'DESC',
        'excluded_posts' => $attributes['exclude'] ?? array(),
    ));
}

/** Reuse the single cached outline; subtract only excluded public posts, with no cache variants. */
function filtered_outline($exclude, $order) {
    $months = outline();
    if ($exclude) {
        $query = new \WP_Query(array(
            'post_type' => 'post', 'post_status' => 'publish', 'has_password' => false,
            'post__in' => $exclude, 'posts_per_page' => -1, 'no_found_rows' => true,
            'update_post_meta_cache' => false, 'update_post_term_cache' => false,
        ));
        $removed = array();
        foreach ($query->posts as $post) {
            $key = substr($post->post_date, 0, 7);
            $removed[$key] = ($removed[$key] ?? 0) + 1;
        }
        foreach ($months as &$month) {
            $key = sprintf('%04d-%02d', $month['year'], $month['month']);
            $month['count'] -= $removed[$key] ?? 0;
        }
        unset($month);
        $months = array_values(array_filter($months, static function ($month) { return $month['count'] > 0; }));
    }
    return $order === 'ASC' ? array_reverse($months) : $months;
}

/** Cache plain date/count data, independently of the current visitor or page. */
function outline() {
    global $wpdb;
    $cached = get_transient('ctrees_month_index_v1');
    if (is_array($cached)) {
        return $cached;
    }
    // wp_get_archives() returns formatted output, not structured month/count rows.
    // The prepared query uses only core's posts table and fixed public-post criteria.
    // Cached above/below in ctrees_month_index_v1 for 12 hours; clean_post_cache invalidates it.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Isolated prepared aggregation; transient cache and invalidation are implemented here.
    $months = $wpdb->get_results($wpdb->prepare(
        "SELECT YEAR(post_date) AS year, MONTH(post_date) AS month, COUNT(*) AS count
         FROM %i WHERE post_type = %s AND post_status = %s AND post_password = ''
         AND post_date >= '1000-01-01 00:00:00'
         GROUP BY YEAR(post_date), MONTH(post_date) ORDER BY year DESC, month DESC",
        $wpdb->posts, 'post', 'publish'
    ), ARRAY_A);
    $months = array_map(static function ($row) {
        return array_map('intval', $row);
    }, $months ?: array());
    set_transient('ctrees_month_index_v1', $months, 12 * HOUR_IN_SECONDS);
    return $months;
}

// Transients persist even without an external object cache. Invalidate on post changes.
add_action('clean_post_cache', static function () {
    delete_transient('ctrees_month_index_v1');
});

function is_public_post($post) {
    return $post instanceof \WP_Post && $post->post_type === 'post'
        && $post->post_status === 'publish' && $post->post_password === '';
}

function post_node($post) {
    $label = html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return array(
        'id' => $post->ID,
        'label' => $label !== '' ? $label : __('(Untitled)', 'content-trees'),
        'url' => esc_url_raw(get_permalink($post)),
    );
}

/** Fetch the complete public month, shared by the initial HTML and the REST route. */
function month_posts($year, $month, $order = 'DESC', $exclude = array()) {
    $order = archive_order($order);
    $query = new \WP_Query(array(
        'post_type' => 'post', 'post_status' => 'publish', 'has_password' => false,
        'year' => (int) $year, 'monthnum' => (int) $month,
        'posts_per_page' => -1, 'no_found_rows' => true,
        'orderby' => array('date' => $order, 'ID' => $order),
        'ignore_sticky_posts' => true, 'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ));
    // The complete month is already fetched; filter its IDs here instead of
    // adding a potentially expensive NOT IN clause to the date query.
    $excluded = array_fill_keys(exclusion_ids($exclude), true);
    $posts = array_filter($query->posts, static function ($post) use ($excluded) {
        return !isset($excluded[$post->ID]);
    });
    return array('items' => array_values(array_map(__NAMESPACE__ . '\\post_node', $posts)));
}

add_action('rest_api_init', static function () {
    register_rest_route('content-trees/v1', '/months/(?P<year>\d{4})/(?P<month>\d{1,2})', array(
        'methods' => 'GET',
        // Intentionally public: the provider only exposes published, unprotected posts.
        'permission_callback' => '__return_true',
        'args' => array(
            'year' => array('type' => 'integer', 'minimum' => 1000, 'maximum' => 9999, 'required' => true),
            'month' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'required' => true),
            'order' => array('type' => 'string', 'enum' => array('ASC', 'DESC'), 'default' => 'DESC'),
            'excluded_posts' => array('type' => 'string', 'pattern' => '^[0-9,]*$', 'default' => ''),
        ),
        'callback' => static function ($request) {
            $result = month_posts((int) $request['year'], (int) $request['month'], $request['order'], exclusion_ids($request['excluded_posts']));
            return rest_ensure_response($result);
        },
    ));
    register_rest_route('content-trees/v1', '/post-options', array(
        'methods' => 'GET',
        'permission_callback' => static function () {
            return current_user_can('edit_posts') || current_user_can('edit_theme_options');
        },
        'args' => array(
            'search' => array('type' => 'string', 'default' => '', 'maxLength' => 200),
            'include' => array('type' => 'string', 'pattern' => '^[0-9,]*$', 'default' => ''),
        ),
        'callback' => static function ($request) {
            $args = array(
                'post_type' => 'post', 'post_status' => 'publish', 'has_password' => false,
                'posts_per_page' => 20, 'no_found_rows' => true,
                'orderby' => array('date' => 'DESC', 'ID' => 'DESC'),
                'update_post_meta_cache' => false, 'update_post_term_cache' => false,
            );
            if ($request['include'] !== '') {
                $args['post__in'] = exclusion_ids($request['include']);
                if (!$args['post__in']) { return rest_ensure_response(array('items' => array())); }
                $args['posts_per_page'] = -1;
            } else {
                $args['s'] = $request['search'];
                $args['search_columns'] = array('post_title');
            }
            $query = new \WP_Query($args);
            return rest_ensure_response(array('items' => array_map(__NAMESPACE__ . '\\post_node', $query->posts)));
        },
    ));
});

add_action('admin_enqueue_scripts', static function ($hook) {
    if ($hook === 'widgets.php' || $hook === 'customize.php') {
        wp_enqueue_script('ctrees-settings', plugins_url('settings.js', __FILE__), array('wp-api-fetch'), VERSION, true);
    }
});

function post_link($node, $current_id) {
    return '<li><a data-post-id="' . esc_attr($node['id']) . '" href="' . esc_url($node['url']) . '"'
        . ($node['id'] === $current_id ? ' aria-current="page"' : '') . '>' . esc_html($node['label']) . '</a></li>';
}

/** Output a date-name link; the native summary keeps its disclosure action. */
function archive_link($year, $month, $count, $full_date = false, $filtered = false) {
    global $wp_locale;
    $date = (string) $year;
    if ($month) {
        /* translators: 1: month name, 2: four-digit year. */
        $date = sprintf(_x('%1$s %2$s', 'archive month and year', 'content-trees'), $wp_locale->get_month($month), $year);
    }
    /* translators: 1: number of posts, 2: month and year, or year alone. */
    $label = sprintf(_n('Browse %1$s post from %2$s', 'Browse all %1$s posts from %2$s', $count, 'content-trees'), number_format_i18n($count), $date);
    if ($filtered) {
        /* translators: %s: month and year, or year alone. */
        $label = sprintf(__('Browse the full archive for %s', 'content-trees'), $date);
    }
    ?>
    <a class="ctrees-archive" href="<?php echo esc_url($month ? get_month_link($year, $month) : get_year_link($year)); ?>" aria-label="<?php echo esc_attr($label); ?>" title="<?php echo esc_attr($label); ?>"><?php echo esc_html($full_date ? $date : ($month ? $wp_locale->get_month($month) : $year)); ?></a>
    <?php
}

/** Label the disclosure separately from the date link inside it. */
function disclosure_label($year, $month = 0) {
    global $wp_locale;
    /* translators: 1: month name, 2: four-digit year. */
    $date = $month ? sprintf(_x('%1$s %2$s', 'archive month and year', 'content-trees'), $wp_locale->get_month($month), $year) : (string) $year;
    /* translators: %s: month and year, or year alone. */
    return sprintf(__('Expand or collapse %s', 'content-trees'), $date);
}

/** Explicit open_to IDs and the queried post use the same ancestry path. */
function render($args = array()) {
    global $wp_locale;
    $args = wp_parse_args($args, array('title' => __('Explore the archive', 'content-trees'), 'open_to' => 0, 'show_title' => true, 'show_counts' => true, 'show_archive_links' => true, 'show_controls' => false, 'auto_open' => true, 'show_posts' => true, 'group_years' => true, 'close_siblings' => false, 'order' => 'DESC', 'excluded_posts' => array()));
    foreach (array('show_title', 'show_counts', 'show_archive_links', 'show_controls', 'auto_open', 'show_posts', 'group_years', 'close_siblings') as $flag) { $args[$flag] = enabled($args[$flag]); }
    $args['excluded_posts'] = exclusion_ids($args['excluded_posts']);
    $args['order'] = archive_order($args['order']);
    if (!$args['show_posts']) { $args['show_archive_links'] = true; }
    $current = is_singular('post') ? get_queried_object() : null;
    if ($current && in_array($current->ID, $args['excluded_posts'], true)) { $current = null; }
    $current_id = is_public_post($current) ? $current->ID : 0;
    $automatic_target = $args['auto_open'] ? $current : null;
    $target = !empty($args['open_to']) ? get_post(absint($args['open_to'])) : $automatic_target;
    if ($target && in_array($target->ID, $args['excluded_posts'], true)) { $target = null; }
    // An unavailable explicit target must not hide the current post's ancestry.
    $target = is_public_post($target) ? $target : (is_public_post($automatic_target) ? $automatic_target : null);
    $target_month = $target ? substr($target->post_date, 0, 7) : '';
    // Plain archive permalinks use ?m=YYYYMM rather than year/monthnum query vars.
    $archive_date = (string) get_query_var('m');
    $archive_year = (int) (get_query_var('year') ?: substr($archive_date, 0, 4));
    $archive_month = (int) (get_query_var('monthnum') ?: substr($archive_date, 4, 2));
    $open_year = $target ? (int) substr($target_month, 0, 4) : ($args['auto_open'] && is_date() ? $archive_year : 0);
    if (!$target && $args['auto_open'] && (is_month() || is_day())) {
        $target_month = sprintf('%04d-%02d', $open_year, $archive_month);
    }
    $years = array();
    foreach (filtered_outline($args['excluded_posts'], $args['order']) as $month) {
        $years[$month['year']][] = $month;
    }
    enqueue_assets();
    $messages = array(
        'loading' => __('Loading posts…', 'content-trees'),
        'loaded' => __('Posts loaded.', 'content-trees'),
        'bulkLoading' => __('Loading archive posts…', 'content-trees'),
        'bulkComplete' => __('Archive loading finished.', 'content-trees'),
        'bulkError' => __('Archive loading finished. Some months could not be loaded. Use Try again in those months.', 'content-trees'),
        'bulkOpened' => __('Archive expanded.', 'content-trees'),
        'bulkClosed' => __('Archive collapsed.', 'content-trees'),
        'empty' => __('No posts in this month.', 'content-trees'),
        'error' => $args['show_archive_links'] ? __('Posts could not be loaded. Try again or visit the month archive.', 'content-trees') : __('Posts could not be loaded. Try again.', 'content-trees'),
        'retry' => __('Try again', 'content-trees'),
    );
    ob_start();
    ?>
    <nav class="ctrees" aria-label="<?php echo esc_attr($args['title']); ?>" data-current-id="<?php echo esc_attr($current_id); ?>" data-endpoint="<?php echo esc_url(rest_url('content-trees/v1/months')); ?>" data-order="<?php echo esc_attr($args['order']); ?>" data-exclude="<?php echo esc_attr(implode(',', $args['excluded_posts'])); ?>" data-close-siblings="<?php echo $args['close_siblings'] ? '1' : '0'; ?>" data-messages="<?php echo esc_attr(wp_json_encode($messages)); ?>">
        <?php if ($args['show_title']) : ?><h2><?php echo esc_html($args['title']); ?></h2><?php endif; ?>
        <?php if (!$years) : ?><p><?php esc_html_e('No published posts yet.', 'content-trees'); ?></p><?php endif; ?>
        <?php if ($years && $args['show_controls'] && ($args['group_years'] || $args['show_posts'])) : ?>
            <div class="ctrees-controls" role="group" aria-label="<?php esc_attr_e('Archive controls', 'content-trees'); ?>" hidden>
                <button type="button" data-ctrees-action="open"><?php esc_html_e('Open All', 'content-trees'); ?></button><span aria-hidden="true"> | </span><button type="button" data-ctrees-action="close"><?php esc_html_e('Close All', 'content-trees'); ?></button>
            </div>
        <?php endif; ?>
        <?php // Empty aria-description prevents a nested archive link from becoming a second disclosure description. ?>
        <?php foreach ($years as $year => $months) : ?>
            <?php if ($args['group_years']) : ?>
            <details class="ctrees-year" data-year="<?php echo esc_attr($year); ?>" <?php echo $open_year === $year ? 'open' : ''; ?>>
                <summary aria-label="<?php echo esc_attr(disclosure_label($year)); ?>" aria-description=""><span class="ctrees-toggle" aria-hidden="true"></span><?php if ($args['show_archive_links']) { archive_link($year, 0, array_sum(array_column($months, 'count')), false, (bool) $args['excluded_posts']); } else { ?><span><?php echo esc_html($year); ?></span><?php } ?><?php if ($args['show_counts']) : ?> <span class="ctrees-count">(<?php echo esc_html(array_sum(array_column($months, 'count'))); ?>)</span><?php endif; ?></summary>
            <?php endif; ?>
                <?php foreach ($months as $month) :
                    $key = sprintf('%04d-%02d', $year, $month['month']);
                    $open = $key === $target_month;
                    if (!$args['show_posts']) :
                    ?>
                        <p class="ctrees-month-link"><?php archive_link($year, $month['month'], $month['count'], !$args['group_years'], (bool) $args['excluded_posts']); ?><?php if ($args['show_counts']) : ?> <span class="ctrees-count">(<?php echo esc_html($month['count']); ?>)</span><?php endif; ?></p>
                    <?php continue; endif;
                    /* translators: 1: month name, 2: four-digit year. */
                    $month_label = $args['group_years'] ? $wp_locale->get_month($month['month']) : sprintf(_x('%1$s %2$s', 'archive month and year', 'content-trees'), $wp_locale->get_month($month['month']), $year);
                    $posts = $open ? month_posts($year, $month['month'], $args['order'], $args['excluded_posts']) : array('items' => array());
                    ?>
                    <details class="ctrees-month" data-month="<?php echo esc_attr($key); ?>"<?php if ($open) : ?> data-loaded="1" open<?php endif; ?>>
                        <summary aria-label="<?php echo esc_attr(disclosure_label($year, $month['month'])); ?>" aria-description=""><span class="ctrees-toggle" aria-hidden="true"></span><?php if ($args['show_archive_links']) { archive_link($year, $month['month'], $month['count'], !$args['group_years'], (bool) $args['excluded_posts']); } else { ?><span><?php echo esc_html($month_label); ?></span><?php } ?><?php if ($args['show_counts']) : ?> <span class="ctrees-count">(<?php echo esc_html($month['count']); ?>)</span><?php endif; ?></summary>
                        <?php if ($open) : ?><ul class="ctrees-posts"><?php foreach ($posts['items'] as $node) {
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- post_link() escapes ID attributes, URLs and label text; remaining markup is constant.
                            echo post_link($node, $current_id);
                        } ?></ul><?php endif; ?>
                    </details>
                <?php endforeach; ?>
            <?php if ($args['group_years']) : ?></details><?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php
    // Only remove template whitespace between tags, never whitespace inside labels.
    return trim(preg_replace('/>\s+</', '><', ob_get_clean()));
}

add_shortcode('content_trees_archive', __NAMESPACE__ . '\\render');

require_once __DIR__ . '/class-archive-widget.php';
add_action('widgets_init', static function () {
    register_widget(__NAMESPACE__ . '\\Archive_Widget');
});
// Show one clear choice in the block inserter; saved legacy widgets still render.
add_filter('widget_types_to_hide_from_legacy_widget_block', static function ($types) {
    $types[] = 'ctrees_archive';
    $types[] = 'ctrees_pages';
    return $types;
});

require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/taxonomy.php';
