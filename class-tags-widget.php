<?php
/**
 * Tags classic widget.
 *
 * @package ContentTrees
 */

namespace ContentTrees;

defined( 'ABSPATH' ) || exit;

/** Classic widget for the tags tree. */
class Tags_Widget extends Taxonomy_Widget {
	/**
	 * Register the widget identity and description.
	 */
	public function __construct() {
		$this->taxonomy = 'post_tag';
		parent::__construct(
			'ctrees_tags',
			__( 'Content Trees: Tags', 'content-trees' ),
			array(
				'description' => __(
					'A collapsible list of tags and their posts.',
					'content-trees'
				),
			)
		);
	}
}
