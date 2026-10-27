<?php
/**
 * Categories classic widget.
 *
 * @package ContentTrees
 */

namespace ContentTrees;

defined( 'ABSPATH' ) || exit;

/** Classic widget for the categories tree. */
class Categories_Widget extends Taxonomy_Widget {
	/**
	 * Register the widget identity and description.
	 */
	public function __construct() {
		$this->taxonomy = 'category';
		parent::__construct(
			'ctrees_categories',
			__( 'Content Trees: Categories', 'content-trees' ),
			array(
				'description' => __(
					'A collapsible tree of categories and their posts.',
					'content-trees'
				),
			)
		);
	}
}
