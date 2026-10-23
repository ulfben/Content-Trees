<?php
namespace ContentTrees;

defined('ABSPATH') || exit;

/** Two fixed widgets share their taxonomy settings and normalization. */
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
        echo render_taxonomy($this->taxonomy, array_merge($options, array('title' => $title, 'show_title' => false)));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned closing wrapper from register_sidebar().
        echo $args['after_widget'];
    }

    public function form($instance) {
        $options = taxonomy_options($this->taxonomy, $instance);
        ?>
        <div class="ctrees-taxonomy-settings">
        <p><label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'content-trees'); ?></label>
        <input class="widefat" type="text" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" value="<?php echo esc_attr($options['title']); ?>"></p>
        <p class="description"><?php esc_html_e('Leave blank to hide the heading.', 'content-trees'); ?></p>
        <?php
        if ($this->taxonomy === 'category') { $this->selector('root', $options['root'] ? array($options['root']) : array(), true); }
        $this->toggle('show_posts', __('Show individual posts', 'content-trees'), $options, '', 'ctrees-taxonomy-show-posts');
        $this->ordering(false, $options);
        ?>
        <div class="ctrees-taxonomy-post-options" <?php if (!$options['show_posts']) : ?>hidden<?php endif; ?>>
            <?php $this->ordering(true, $options); ?>
        </div>
        <?php
        $this->toggle('show_counts', __('Show post counts', 'content-trees'), $options, $this->taxonomy === 'category'
            ? __('Count unique public posts in each category and its visible child categories.', 'content-trees')
            : __('Count public posts assigned directly to each tag.', 'content-trees'));
        $this->toggle('hide_empty', $this->taxonomy === 'category' ? __('Hide empty categories', 'content-trees') : __('Hide empty tags', 'content-trees'), $options);
        $this->toggle('show_controls', __('Show Open All / Close All', 'content-trees'), $options);
        $this->toggle('auto_open', __('Open the current branch automatically', 'content-trees'), $options, $this->taxonomy === 'category'
            ? __('Open matching branches when viewing a category or a post in this tree.', 'content-trees')
            : __('Open matching branches when viewing a tag or a post in this tree.', 'content-trees'));
        ?>
        <details><summary><?php esc_html_e('Advanced', 'content-trees'); ?></summary>
            <?php
            $this->toggle('close_siblings', __('Close other branches at the same level', 'content-trees'), $options,
                __('Opening a branch closes its siblings and their child branches. Open All still opens everything.', 'content-trees'));
            $this->selector('excluded_terms', $options['excluded_terms']);
            $this->selector('excluded_posts', $options['excluded_posts']);
            ?>
        </details>
        </div>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $defaults = array('title' => '', 'root' => 0, 'excluded_terms' => array(), 'excluded_posts' => array());
        foreach (array('show_posts', 'show_counts', 'hide_empty', 'show_controls', 'auto_open', 'close_siblings') as $flag) { $defaults[$flag] = false; }
        $options = taxonomy_options($this->taxonomy, array_merge($defaults, $new_instance));
        unset($options['show_title'], $options['open_to']);
        if ($this->taxonomy !== 'category') { unset($options['root']); }
        return $options;
    }

    private function toggle($field, $label, $options, $help = '', $class = '') {
        ?>
        <p><input type="checkbox" class="<?php echo esc_attr($class); ?>" id="<?php echo esc_attr($this->get_field_id($field)); ?>" name="<?php echo esc_attr($this->get_field_name($field)); ?>" value="1" <?php checked($options[$field]); ?>>
        <label for="<?php echo esc_attr($this->get_field_id($field)); ?>"><?php echo esc_html($label); ?></label></p>
        <?php if ($help !== '') : ?><p class="description"><?php echo esc_html($help); ?></p><?php endif; ?>
        <?php
    }

    private function ordering($posts, $options) {
        $sort = $posts ? 'post_sort_by' : 'sort_by';
        $order = $posts ? 'post_order' : 'order';
        $choices = $posts ? array('date' => __('Published date', 'content-trees'), 'modified' => __('Last updated', 'content-trees'), 'title' => __('Title', 'content-trees'))
            : array('name' => __('Name', 'content-trees'), 'count' => __('Post count', 'content-trees'));
        $alphabet = array(__('A–Z', 'content-trees'), __('Z–A', 'content-trees'));
        $dates = array(__('Oldest first', 'content-trees'), __('Newest first', 'content-trees'));
        $labels = $posts ? array('date' => $dates, 'modified' => $dates, 'title' => $alphabet)
            : array('name' => $alphabet, 'count' => array(__('Fewest first', 'content-trees'), __('Most first', 'content-trees')));
        ?>
        <div class="ctrees-taxonomy-ordering" data-order-labels="<?php echo esc_attr(wp_json_encode($labels)); ?>">
            <p><label for="<?php echo esc_attr($this->get_field_id($sort)); ?>"><?php echo esc_html($posts ? __('Sort posts by', 'content-trees') : __('Sort by', 'content-trees')); ?></label>
            <select class="ctrees-taxonomy-sort" id="<?php echo esc_attr($this->get_field_id($sort)); ?>" name="<?php echo esc_attr($this->get_field_name($sort)); ?>">
                <?php foreach ($choices as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($options[$sort], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
            </select></p>
            <p><label for="<?php echo esc_attr($this->get_field_id($order)); ?>"><?php echo esc_html($posts ? __('Post order', 'content-trees') : __('Order', 'content-trees')); ?></label>
            <select class="ctrees-taxonomy-order" id="<?php echo esc_attr($this->get_field_id($order)); ?>" name="<?php echo esc_attr($this->get_field_name($order)); ?>">
                <?php foreach (array('ASC', 'DESC') as $index => $value) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($options[$order], $value); ?>><?php echo esc_html($labels[$options[$sort]][$index]); ?></option><?php endforeach; ?>
            </select></p>
        </div>
        <?php
    }

    private function selector($field, $ids, $single = false) {
        $posts = $field === 'excluded_posts';
        $label = $single ? __('Start at category', 'content-trees') : ($posts ? __('Exclude posts', 'content-trees')
            : ($this->taxonomy === 'category' ? __('Exclude categories', 'content-trees') : __('Exclude tags', 'content-trees')));
        $help = $single ? __('Show the selected category and its child categories. Leave empty to show all categories.', 'content-trees')
            : ($posts ? __('Hide selected posts from every branch and count in this tree. Linked archives keep their full contents.', 'content-trees')
            : ($this->taxonomy === 'category' ? __('Hide selected categories and all their child categories from this tree. Linked archives keep their full contents.', 'content-trees')
                : __('Hide selected tags from this tree. Linked archives keep their full contents.', 'content-trees')));
        $labels = array();
        if ($posts) {
            foreach ($ids as $id) { $post = get_post($id); if (is_public_post($post)) { $labels[$id] = post_node($post)['label']; } }
        } elseif ($ids) {
            foreach (taxonomy_option_nodes($this->taxonomy, $ids) as $node) { $labels[$node['id']] = $node['label']; }
        }
        $endpoint = $posts ? '/content-trees/v1/post-options' : ($this->taxonomy === 'category' ? '/content-trees/v1/category-options' : '/content-trees/v1/tag-options');
        ?>
        <div class="ctrees-exclusions" data-endpoint="<?php echo esc_attr($endpoint); ?>" data-single="<?php echo $single ? '1' : '0'; ?>" data-remove="<?php esc_attr_e('Remove selection', 'content-trees'); ?>" data-error="<?php esc_attr_e('Search failed. Change the search to try again.', 'content-trees'); ?>" data-empty="<?php esc_attr_e('No matching public items.', 'content-trees'); ?>" data-found="<?php esc_attr_e('Select an item. Refine your search if needed.', 'content-trees'); ?>">
            <p><label for="<?php echo esc_attr($this->get_field_id($field . '_search')); ?>"><?php echo esc_html($label); ?></label>
            <input type="search" class="widefat ctrees-post-search" maxlength="200" id="<?php echo esc_attr($this->get_field_id($field . '_search')); ?>"></p>
            <p class="description"><?php echo esc_html($help); ?></p>
            <input type="hidden" class="ctrees-excluded-ids" name="<?php echo esc_attr($this->get_field_name($field)); ?>" value="<?php echo esc_attr(implode(',', $ids)); ?>">
            <ul class="ctrees-selected-posts"><?php foreach ($ids as $id) : ?>
                <li><button type="button" class="button-link" data-remove-id="<?php echo esc_attr($id); ?>"><?php echo esc_html(__('Remove selection', 'content-trees') . ': ' . ($labels[$id] ?? '#' . $id)); ?></button></li>
            <?php endforeach; ?></ul>
            <p class="ctrees-search-status" role="status"></p><ul class="ctrees-search-results"></ul>
        </div>
        <?php
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
