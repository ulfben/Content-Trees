<?php
namespace ContentTrees;

defined('ABSPATH') || exit;

/** New widget ID deliberately coexists with the published plugin's widgets. */
class Archive_Widget extends \WP_Widget {
    public function __construct() {
        parent::__construct('ctrees_archive', __('Content Trees: Archive', 'content-trees'), array(
            'description' => __('Experimental archive with on-demand post titles.', 'content-trees'),
        ));
    }

    public function widget($args, $instance) {
        $title = isset($instance['title']) ? $instance['title'] : __('Archives', 'content-trees');
        $title = apply_filters('widget_title', $title, $instance, $this->id_base);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned HTML from register_sidebar()/dynamic_sidebar(); not a saved widget field.
        echo $args['before_widget'];
        if ($title !== '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned opening title markup; the filtered title is escaped separately below.
            echo $args['before_title'];
            echo esc_html($title);
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned closing title markup from the registered sidebar.
            echo $args['after_title'];
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() returns HTML with text, attributes, JSON attributes and URLs escaped at construction.
        echo render(array('title' => $title ?: __('Archives', 'content-trees'), 'show_title' => false,
            'show_counts' => $instance['show_counts'] ?? true, 'show_archive_links' => $instance['show_archive_links'] ?? true, 'show_controls' => $instance['show_controls'] ?? false, 'auto_open' => $instance['auto_open'] ?? true,
            'show_posts' => $instance['show_posts'] ?? true, 'group_years' => $instance['group_years'] ?? true,
            'close_siblings' => $instance['close_siblings'] ?? false, 'order' => $instance['order'] ?? 'DESC',
            'excluded_posts' => $instance['excluded_posts'] ?? array()));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned closing widget markup from the registered sidebar.
        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = isset($instance['title']) ? $instance['title'] : __('Archives', 'content-trees');
        ?>
        <p><label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'content-trees'); ?></label>
        <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>"></p>
        <p class="description"><?php esc_html_e('Leave blank to hide the heading.', 'content-trees'); ?></p>
        <?php foreach (array('show_posts' => __('Show individual posts', 'content-trees'), 'group_years' => __('Group months by year', 'content-trees')) as $key => $label) : ?>
        <p><input type="checkbox" id="<?php echo esc_attr($this->get_field_id($key)); ?>" name="<?php echo esc_attr($this->get_field_name($key)); ?>" value="1" <?php checked(enabled($instance[$key] ?? true)); ?>>
        <label for="<?php echo esc_attr($this->get_field_id($key)); ?>"><?php echo esc_html($label); ?></label></p>
        <?php endforeach; ?>
        <p><label for="<?php echo esc_attr($this->get_field_id('order')); ?>"><?php esc_html_e('Order', 'content-trees'); ?></label>
        <select id="<?php echo esc_attr($this->get_field_id('order')); ?>" name="<?php echo esc_attr($this->get_field_name('order')); ?>">
            <option value="DESC" <?php selected(archive_order($instance['order'] ?? 'DESC'), 'DESC'); ?>><?php esc_html_e('Newest first', 'content-trees'); ?></option>
            <option value="ASC" <?php selected(archive_order($instance['order'] ?? 'DESC'), 'ASC'); ?>><?php esc_html_e('Oldest first', 'content-trees'); ?></option>
        </select></p>
        <p><input type="checkbox" id="<?php echo esc_attr($this->get_field_id('show_counts')); ?>" name="<?php echo esc_attr($this->get_field_name('show_counts')); ?>" value="1" <?php checked(enabled($instance['show_counts'] ?? true)); ?>>
        <label for="<?php echo esc_attr($this->get_field_id('show_counts')); ?>"><?php esc_html_e('Show post counts', 'content-trees'); ?></label></p>
        <p><input type="checkbox" id="<?php echo esc_attr($this->get_field_id('show_archive_links')); ?>" name="<?php echo esc_attr($this->get_field_name('show_archive_links')); ?>" value="1" <?php checked(enabled($instance['show_archive_links'] ?? true)); ?>>
        <label for="<?php echo esc_attr($this->get_field_id('show_archive_links')); ?>"><?php esc_html_e('Link year and month names', 'content-trees'); ?></label></p>
        <p class="description"><?php esc_html_e('Open the archive by selecting its year or month name. Links remain available when individual posts are hidden.', 'content-trees'); ?></p>
        <p><input type="checkbox" id="<?php echo esc_attr($this->get_field_id('show_controls')); ?>" name="<?php echo esc_attr($this->get_field_name('show_controls')); ?>" value="1" <?php checked(enabled($instance['show_controls'] ?? false)); ?>>
        <label for="<?php echo esc_attr($this->get_field_id('show_controls')); ?>"><?php esc_html_e('Show Open All / Close All', 'content-trees'); ?></label></p>
        <p><input type="checkbox" id="<?php echo esc_attr($this->get_field_id('auto_open')); ?>" name="<?php echo esc_attr($this->get_field_name('auto_open')); ?>" value="1" <?php checked(enabled($instance['auto_open'] ?? true)); ?>>
        <label for="<?php echo esc_attr($this->get_field_id('auto_open')); ?>"><?php esc_html_e('Open the current branch automatically', 'content-trees'); ?></label></p>
        <p class="description"><?php esc_html_e('Open the matching year and month when viewing a post or date archive.', 'content-trees'); ?></p>
        <details><summary><?php esc_html_e('Advanced', 'content-trees'); ?></summary>
        <p><input type="checkbox" id="<?php echo esc_attr($this->get_field_id('close_siblings')); ?>" name="<?php echo esc_attr($this->get_field_name('close_siblings')); ?>" value="1" <?php checked(enabled($instance['close_siblings'] ?? false)); ?>>
        <label for="<?php echo esc_attr($this->get_field_id('close_siblings')); ?>"><?php esc_html_e('Close other branches at the same level', 'content-trees'); ?></label></p>
        <p class="description"><?php esc_html_e('Opening a month closes the other months in that year. Opening a year closes the other years. Open All still opens everything.', 'content-trees'); ?></p>
        <div class="ctrees-exclusions" data-remove="<?php esc_attr_e('Remove exclusion', 'content-trees'); ?>" data-error="<?php esc_attr_e('Search failed. Change the search to try again.', 'content-trees'); ?>" data-empty="<?php esc_attr_e('No matching public posts.', 'content-trees'); ?>" data-found="<?php esc_attr_e('Select a post to exclude. Refine your search if needed.', 'content-trees'); ?>">
            <p><label for="<?php echo esc_attr($this->get_field_id('post_search')); ?>"><?php esc_html_e('Exclude posts', 'content-trees'); ?></label>
            <input type="search" class="widefat ctrees-post-search" id="<?php echo esc_attr($this->get_field_id('post_search')); ?>" maxlength="200"></p>
            <p class="description"><?php esc_html_e('Search by title. Exclusions affect this tree and its counts; linked archive pages still show the full archive.', 'content-trees'); ?></p>
            <?php $excluded = exclusion_ids($instance['excluded_posts'] ?? array()); ?>
            <input type="hidden" class="ctrees-excluded-ids" name="<?php echo esc_attr($this->get_field_name('excluded_posts')); ?>" value="<?php echo esc_attr(implode(',', $excluded)); ?>">
            <ul class="ctrees-selected-posts">
                <?php foreach ($excluded as $id) :
                    $post = get_post($id);
                    $label = is_public_post($post) ? post_node($post)['label'] : '#' . $id;
                    ?>
                    <li><button type="button" class="button-link" data-remove-id="<?php echo esc_attr($id); ?>"><?php echo esc_html(__('Remove exclusion', 'content-trees') . ': ' . $label . ' (#' . $id . ')'); ?></button></li>
                <?php endforeach; ?>
            </ul>
            <p class="ctrees-search-status" role="status"></p>
            <ul class="ctrees-search-results"></ul>
        </div></details>
        <?php
    }

    public function update($new_instance, $old_instance) {
        return array('title' => sanitize_text_field($new_instance['title'] ?? ''),
            'show_counts' => enabled($new_instance['show_counts'] ?? false),
            'show_archive_links' => enabled($new_instance['show_archive_links'] ?? false),
            'show_controls' => enabled($new_instance['show_controls'] ?? false),
            'auto_open' => enabled($new_instance['auto_open'] ?? false),
            'show_posts' => enabled($new_instance['show_posts'] ?? false),
            'group_years' => enabled($new_instance['group_years'] ?? false),
            'close_siblings' => enabled($new_instance['close_siblings'] ?? false),
            'order' => archive_order($new_instance['order'] ?? 'DESC'),
            'excluded_posts' => exclusion_ids($new_instance['excluded_posts'] ?? array()));
    }
}
