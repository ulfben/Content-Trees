<?php
namespace ContentTrees;

defined('ABSPATH') || exit;

class Pages_Widget extends \WP_Widget {
    public function __construct() {
        parent::__construct('ctrees_pages', __('Content Trees: Pages', 'content-trees'), array(
            'description' => __('A collapsible tree of your pages.', 'content-trees'),
        ));
    }

    public function widget($args, $instance) {
        $stored_title = $instance['title'] ?? __('Pages', 'content-trees');
        $title = is_string($stored_title) ? sanitize_text_field($stored_title) : __('Pages', 'content-trees');
        $title = apply_filters('widget_title', $title, $instance, $this->id_base);
        $title = is_string($title) ? $title : '';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned markup from register_sidebar().
        echo $args['before_widget'];
        if ($title !== '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned title wrappers; filtered title is escaped separately.
            echo $args['before_title'];
            echo esc_html($title);
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned title wrappers from register_sidebar().
            echo $args['after_title'];
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_pages() escapes labels, URLs and attributes at construction.
        echo render_pages(array_merge($instance, array('title' => $title !== '' ? $title : __('Pages', 'content-trees'), 'show_title' => false)));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned markup from register_sidebar().
        echo $args['after_widget'];
    }

    public function form($instance) {
        $instance = pages_options($instance);
        $title = $instance['title'];
        ?>
        <p><label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'content-trees'); ?></label>
        <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>"></p>
        <p class="description"><?php esc_html_e('Leave blank to hide the heading.', 'content-trees'); ?></p>
        <p><label for="<?php echo esc_attr($this->get_field_id('sort_by')); ?>"><?php esc_html_e('Sort by', 'content-trees'); ?></label>
        <select class="ctrees-page-sort" id="<?php echo esc_attr($this->get_field_id('sort_by')); ?>" name="<?php echo esc_attr($this->get_field_name('sort_by')); ?>">
            <option value="menu_order" <?php selected($instance['sort_by'], 'menu_order'); ?>><?php esc_html_e('Menu order', 'content-trees'); ?></option>
            <option value="title" <?php selected($instance['sort_by'], 'title'); ?>><?php esc_html_e('Title', 'content-trees'); ?></option>
        </select></p>
        <p><label for="<?php echo esc_attr($this->get_field_id('order')); ?>"><?php esc_html_e('Order', 'content-trees'); ?></label>
        <select class="ctrees-page-order" id="<?php echo esc_attr($this->get_field_id('order')); ?>" name="<?php echo esc_attr($this->get_field_name('order')); ?>" data-asc="<?php esc_attr_e('Ascending', 'content-trees'); ?>" data-desc="<?php esc_attr_e('Descending', 'content-trees'); ?>" data-title-asc="<?php esc_attr_e('A–Z', 'content-trees'); ?>" data-title-desc="<?php esc_attr_e('Z–A', 'content-trees'); ?>">
            <option value="ASC" <?php selected($instance['order'], 'ASC'); ?>><?php echo esc_html($instance['sort_by'] === 'title' ? __('A–Z', 'content-trees') : __('Ascending', 'content-trees')); ?></option>
            <option value="DESC" <?php selected($instance['order'], 'DESC'); ?>><?php echo esc_html($instance['sort_by'] === 'title' ? __('Z–A', 'content-trees') : __('Descending', 'content-trees')); ?></option>
        </select></p>
        <p><input type="checkbox" id="<?php echo esc_attr($this->get_field_id('auto_open')); ?>" name="<?php echo esc_attr($this->get_field_name('auto_open')); ?>" value="1" <?php checked($instance['auto_open']); ?>>
        <label for="<?php echo esc_attr($this->get_field_id('auto_open')); ?>"><?php esc_html_e('Open the current branch automatically', 'content-trees'); ?></label></p>
        <p class="description"><?php esc_html_e('Open the parent branches when viewing a page in this tree.', 'content-trees'); ?></p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = pages_options(array_merge(array('title' => '', 'auto_open' => false), $new_instance));
        return array_intersect_key($instance, array_flip(array('title', 'sort_by', 'order', 'auto_open')));
    }
}
