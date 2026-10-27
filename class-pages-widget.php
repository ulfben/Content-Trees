<?php
/**
 * Pages classic widget.
 *
 * @package ContentTrees
 */

namespace ContentTrees;

defined( 'ABSPATH' ) || exit;

/** Classic widget for the Pages tree. */
class Pages_Widget extends \WP_Widget {
	/**
	 * Register the widget identity and description.
	 */
	public function __construct() {
		parent::__construct(
			'ctrees_pages',
			__( 'Content Trees: Pages', 'content-trees' ),
			array(
				'description' => __( 'A collapsible tree of your pages.', 'content-trees' ),
			)
		);
	}

	/**
	 * Render the widget using theme wrappers.
	 *
	 * @param array $args Tree options or theme widget wrappers.
	 * @param array $instance Saved widget settings.
	 */
	public function widget( $args, $instance ) {
		$stored_title = $instance['title'] ?? __( 'Pages', 'content-trees' );
		$title        = is_string( $stored_title ) ? sanitize_text_field( $stored_title ) : __( 'Pages', 'content-trees' );
		$title        = apply_filters( 'widget_title', $title, $instance, $this->id_base );
		$title        = is_string( $title ) ? $title : '';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned markup from register_sidebar().
		echo $args['before_widget'];
		if ( '' !== $title ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned title wrappers; filtered title is escaped separately.
			echo $args['before_title'];
			echo esc_html( $title );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned title wrappers from register_sidebar().
			echo $args['after_title'];
		}
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_pages() escapes labels, URLs and attributes at construction.
		echo render_pages(
			array_merge(
				$instance,
				array(
					'title'      => '' !== $title ? $title : __( 'Pages', 'content-trees' ),
					'show_title' => false,
				)
			)
		);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned markup from register_sidebar().
		echo $args['after_widget'];
	}

	/**
	 * Render the classic widget settings.
	 *
	 * @param array $instance Saved widget settings.
	 */
	public function form( $instance ) {
		$instance  = pages_options( $instance );
		$title     = $instance['title'];
		$date_sort = in_array( $instance['sort_by'], array( 'date', 'modified' ), true );
		?>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'content-trees' ); ?></label>
		<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>"></p>
		<p class="description"><?php esc_html_e( 'Leave blank to hide the heading.', 'content-trees' ); ?></p>
		<?php $this->page_selector( 'root', $instance['root'] ? array( $instance['root'] ) : array(), true ); ?>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'sort_by' ) ); ?>"><?php esc_html_e( 'Sort by', 'content-trees' ); ?></label>
		<select class="ctrees-page-sort" id="<?php echo esc_attr( $this->get_field_id( 'sort_by' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'sort_by' ) ); ?>">
			<option value="menu_order" <?php selected( $instance['sort_by'], 'menu_order' ); ?>><?php esc_html_e( 'Menu order', 'content-trees' ); ?></option>
			<option value="title" <?php selected( $instance['sort_by'], 'title' ); ?>><?php esc_html_e( 'Title', 'content-trees' ); ?></option>
			<option value="date" <?php selected( $instance['sort_by'], 'date' ); ?>><?php esc_html_e( 'Published date', 'content-trees' ); ?></option>
			<option value="modified" <?php selected( $instance['sort_by'], 'modified' ); ?>><?php esc_html_e( 'Last updated', 'content-trees' ); ?></option>
		</select></p>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'order' ) ); ?>"><?php esc_html_e( 'Order', 'content-trees' ); ?></label>
		<select class="ctrees-page-order" id="<?php echo esc_attr( $this->get_field_id( 'order' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'order' ) ); ?>" data-asc="<?php esc_attr_e( 'Ascending', 'content-trees' ); ?>" data-desc="<?php esc_attr_e( 'Descending', 'content-trees' ); ?>" data-title-asc="<?php esc_attr_e( 'AÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“Z', 'content-trees' ); ?>" data-title-desc="<?php esc_attr_e( 'ZÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“A', 'content-trees' ); ?>" data-date-asc="<?php esc_attr_e( 'Oldest first', 'content-trees' ); ?>" data-date-desc="<?php esc_attr_e( 'Newest first', 'content-trees' ); ?>">
			<option value="ASC" <?php selected( $instance['order'], 'ASC' ); ?>><?php echo esc_html( 'title' === $instance['sort_by'] ? __( 'AÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“Z', 'content-trees' ) : ( $date_sort ? __( 'Oldest first', 'content-trees' ) : __( 'Ascending', 'content-trees' ) ) ); ?></option>
			<option value="DESC" <?php selected( $instance['order'], 'DESC' ); ?>><?php echo esc_html( 'title' === $instance['sort_by'] ? __( 'ZÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“A', 'content-trees' ) : ( $date_sort ? __( 'Newest first', 'content-trees' ) : __( 'Descending', 'content-trees' ) ) ); ?></option>
		</select></p>
		<p><input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_controls' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_controls' ) ); ?>" value="1" <?php checked( $instance['show_controls'] ); ?>>
		<label for="<?php echo esc_attr( $this->get_field_id( 'show_controls' ) ); ?>"><?php esc_html_e( 'Show Open All / Close All', 'content-trees' ); ?></label></p>
		<p><input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'auto_open' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'auto_open' ) ); ?>" value="1" <?php checked( $instance['auto_open'] ); ?>>
		<label for="<?php echo esc_attr( $this->get_field_id( 'auto_open' ) ); ?>"><?php esc_html_e( 'Open the current branch automatically', 'content-trees' ); ?></label></p>
		<p class="description"><?php esc_html_e( 'Open the parent branches when viewing a page in this tree.', 'content-trees' ); ?></p>
		<details><summary><?php esc_html_e( 'Advanced', 'content-trees' ); ?></summary>
			<p><input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'close_siblings' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'close_siblings' ) ); ?>" value="1" <?php checked( $instance['close_siblings'] ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'close_siblings' ) ); ?>"><?php esc_html_e( 'Close other branches at the same level', 'content-trees' ); ?></label></p>
			<p class="description"><?php esc_html_e( 'Opening a branch closes its siblings and their child branches. Open All still opens everything.', 'content-trees' ); ?></p>
			<?php $this->page_selector( 'excluded_pages', $instance['excluded_pages'] ); ?>
		</details>
		<?php
	}

	/**
	 * Normalize saved widget settings.
	 *
	 * @param array $new_instance Submitted widget settings.
	 * @param array $old_instance Previous widget settings.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = pages_options(
			array_merge(
				array(
					'title'          => '',
					'root'           => 0,
					'auto_open'      => false,
					'excluded_pages' => array(),
					'show_controls'  => false,
					'close_siblings' => false,
				),
				$new_instance
			)
		);
		return array_intersect_key( $instance, array_flip( array( 'title', 'root', 'sort_by', 'order', 'auto_open', 'excluded_pages', 'show_controls', 'close_siblings' ) ) );
	}

	/**
	 * Render the shared searchable selector with page-specific labels.
	 *
	 * @param string $field Setting name.
	 * @param int[]  $ids Selected IDs.
	 * @param bool   $single Whether only one selection is allowed.
	 */
	private function page_selector( $field, $ids, $single = false ) {
		$remove = $single ? __( 'Clear start page', 'content-trees' ) : __( 'Remove page', 'content-trees' );
		?>
		<div class="ctrees-exclusions" data-endpoint="/content-trees/v1/page-options" data-single="<?php echo $single ? '1' : '0'; ?>" data-remove="<?php echo esc_attr( $remove ); ?>" data-error="<?php esc_attr_e( 'Search failed. Change the search to try again.', 'content-trees' ); ?>" data-empty="<?php esc_attr_e( 'No matching public pages.', 'content-trees' ); ?>" data-found="<?php esc_attr_e( 'Select a page. Refine your search if needed.', 'content-trees' ); ?>">
			<p><label for="<?php echo esc_attr( $this->get_field_id( $field . '_search' ) ); ?>"><?php echo esc_html( $single ? __( 'Start at page', 'content-trees' ) : __( 'Exclude pages', 'content-trees' ) ); ?></label>
			<input type="search" class="widefat ctrees-post-search" id="<?php echo esc_attr( $this->get_field_id( $field . '_search' ) ); ?>" maxlength="200"></p>
			<p class="description"><?php echo esc_html( $single ? __( 'Show the selected page and its child pages. Leave empty to show all pages.', 'content-trees' ) : __( 'Hide selected pages and all their child pages from this tree.', 'content-trees' ) ); ?></p>
			<input type="hidden" class="ctrees-excluded-ids" name="<?php echo esc_attr( $this->get_field_name( $field ) ); ?>" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
			<ul class="ctrees-selected-posts">
				<?php
				foreach ( $ids as $id ) :
					$page  = $id > 0 ? get_post( $id ) : null;
					$label = is_public_page( $page ) ? page_label( $page ) . ' (#' . $id . ')' : '#' . $id;
					?>
				<li><button type="button" class="button-link" data-remove-id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $remove . ': ' . $label ); ?></button></li>
				<?php endforeach; ?>
			</ul>
			<p class="ctrees-search-status" role="status"></p>
			<ul class="ctrees-search-results"></ul>
		</div>
		<?php
	}
}
