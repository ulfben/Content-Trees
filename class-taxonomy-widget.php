<?php
namespace ContentTrees;

defined('ABSPATH') || exit;

/** The shared form has only Title and Show individual posts in this first slice. */
class Taxonomy_Widget extends \WP_Widget {
    protected $taxonomy;

    public function widget($args, $instance) {
        $options = taxonomy_options($this->taxonomy, $instance);
        $title = apply_filters('widget_title', $options['title'], $instance, $this->id_base);
        $title = is_string($title) ? $title : '';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned register_sidebar() wrappers; title and tree output are escaped separately.
        echo $args['before_widget'];
        if ($title !== '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned title wrappers; filtered title is escaped at output.
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_taxonomy() escapes all labels, URLs and attributes at construction.
        echo render_taxonomy($this->taxonomy, array('title' => $title, 'show_title' => false, 'show_posts' => $options['show_posts']));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned closing wrapper from register_sidebar().
        echo $args['after_widget'];
    }

    public function form($instance) {
        $options = taxonomy_options($this->taxonomy, $instance);
        ?>
        <p><label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'content-trees'); ?></label>
        <input class="widefat" type="text" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" value="<?php echo esc_attr($options['title']); ?>"></p>
        <p class="description"><?php esc_html_e('Leave blank to hide the heading.', 'content-trees'); ?></p>
        <p><input type="checkbox" id="<?php echo esc_attr($this->get_field_id('show_posts')); ?>" name="<?php echo esc_attr($this->get_field_name('show_posts')); ?>" value="1" <?php checked($options['show_posts']); ?>>
        <label for="<?php echo esc_attr($this->get_field_id('show_posts')); ?>"><?php esc_html_e('Show individual posts', 'content-trees'); ?></label></p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $options = taxonomy_options($this->taxonomy, array_merge(array('title' => '', 'show_posts' => false), $new_instance));
        return array('title' => $options['title'], 'show_posts' => $options['show_posts']);
    }
}

class Categories_Widget extends Taxonomy_Widget {
    public function __construct() {
        $this->taxonomy = 'category';
        parent::__construct('ctrees_categories', __('Content Trees: Categories', 'content-trees'), array(
            'description' => __('A collapsible tree of categories and their posts.', 'content-trees')));
    }
}

class Tags_Widget extends Taxonomy_Widget {
    public function __construct() {
        $this->taxonomy = 'post_tag';
        parent::__construct('ctrees_tags', __('Content Trees: Tags', 'content-trees'), array(
            'description' => __('A collapsible list of tags and their posts.', 'content-trees')));
    }
}
