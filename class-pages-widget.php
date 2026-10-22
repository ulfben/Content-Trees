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
        echo render_pages(array('title' => $title !== '' ? $title : __('Pages', 'content-trees'), 'show_title' => false));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned markup from register_sidebar().
        echo $args['after_widget'];
    }

    public function form($instance) {
        $stored_title = $instance['title'] ?? __('Pages', 'content-trees');
        $title = is_string($stored_title) ? $stored_title : __('Pages', 'content-trees');
        ?>
        <p><label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'content-trees'); ?></label>
        <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>"></p>
        <p class="description"><?php esc_html_e('Leave blank to hide the heading.', 'content-trees'); ?></p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $title = $new_instance['title'] ?? '';
        return array('title' => sanitize_text_field(is_string($title) ? $title : ''));
    }
}
