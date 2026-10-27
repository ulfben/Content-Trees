<?php
/**
 * Shared tree data and frontend asset detection.
 *
 * @package ContentTrees
 */

namespace ContentTrees;

defined( 'ABSPATH' ) || exit;

/**
 * Shared boolean settings for blocks, classic widgets and shortcodes.
 *
 * @param mixed $value Value to normalize.
 */
function enabled( $value ) {
	return in_array( $value, array( true, 1, '1', 'true', 'on' ), true );
}

/**
 * Normalize saved/shortcode exclusions without accepting nested or malformed input.
 *
 * @param mixed $value Value to normalize.
 */
function exclusion_ids( $value ) {
	$values = is_array( $value ) ? $value : ( is_string( $value ) ? preg_split( '/[\s,]+/', $value ) : array() );
	$ids    = array();
	foreach ( $values as $id ) {
		if ( ( is_int( $id ) || is_string( $id ) ) && ctype_digit( (string) $id ) && (int) $id > 0 ) {
			$ids[] = (int) $id;
		}
	}
	$ids = array_values( array_unique( $ids ) );
	sort( $ids, SORT_NUMERIC );
	return $ids;
}

/**
 * Check the public-post visibility boundary.
 *
 * @param \WP_Post|null $post Post candidate.
 */
function is_public_post( $post ) {
	return $post instanceof \WP_Post && 'post' === $post->post_type
		&& 'publish' === $post->post_status && '' === $post->post_password;
}

/**
 * Resolve an escaped public link node without content fields.
 *
 * @param \WP_Post|null $post Post candidate.
 */
function post_node( $post ) {
	$label = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return array(
		'id'    => $post->ID,
		'label' => '' !== $label ? $label : __( '(Untitled)', 'content-trees' ),
		'url'   => esc_url_raw( get_permalink( $post ) ),
	);
}

/**
 * Find descendants through original parent edges, including the roots.
 *
 * @param int[] $roots Root IDs.
 * @param int[] $parents Map of node IDs to original parent IDs.
 * @return array<int, bool> Set of matching IDs.
 */
function descendants( $roots, $parents ) {
	$children = array();
	$result   = array();
	foreach ( $parents as $id => $parent_id ) {
		$children[ $parent_id ][] = $id;
	}
	while ( $roots ) {
		$id = array_pop( $roots );
		if ( isset( $result[ $id ] ) ) {
			continue;
		}
		$result[ $id ] = true;
		foreach ( $children[ $id ] ?? array() as $child ) {
			$roots[] = $child;
		}
	}
	return $result;
}

/**
 * Break the smallest-ID edge in each cycle, independent of display order.
 *
 * @param int[] $parents Map of visible IDs to parent IDs.
 * @return int[] Acyclic parent map.
 */
function acyclic_parents( $parents ) {
	$ids = array_keys( $parents );
	sort( $ids, SORT_NUMERIC );
	$visited = array();
	foreach ( $ids as $id ) {
		$path      = array();
		$positions = array();
		while ( $id && isset( $parents[ $id ] ) && ! isset( $visited[ $id ] ) ) {
			if ( isset( $positions[ $id ] ) ) {
				$parents[ min( array_slice( $path, $positions[ $id ] ) ) ] = 0;
				break;
			}
			$positions[ $id ] = count( $path );
			$path[]           = $id;
			$id               = $parents[ $id ];
		}
		foreach ( $path as $node ) {
			$visited[ $node ] = true;
		}
	}
	return $parents;
}

/** Scan queried content and active block widgets once before wp_head completes. */
function enqueue_detected_trees() {
	global $wp_query;
	$handles  = array(
		'archive'    => 'content-trees',
		'pages'      => 'content-trees-pages',
		'categories' => 'content-trees-taxonomy',
		'tags'       => 'content-trees-taxonomy',
	);
	$detected = array();
	foreach ( $handles as $name => $handle ) {
		if ( is_active_widget( false, false, 'ctrees_' . $name, true ) ) {
			$detected[ $name ] = true;
		}
	}
	$contents = array();
	foreach ( $wp_query->posts ?? array() as $post ) {
		if ( $post instanceof \WP_Post ) {
			$contents[] = $post->post_content;
		}
	}
	foreach ( get_option( 'widget_block', array() ) as $number => $widget ) {
		if ( isset( $widget['content'] ) && is_active_widget( false, 'block-' . $number, 'block', true ) ) {
			$contents[] = $widget['content'];
		}
	}
	foreach ( $contents as $content ) {
		foreach ( $handles as $name => $handle ) {
			if ( ! isset( $detected[ $name ] ) && ( has_shortcode( $content, 'content_trees_' . $name ) || has_block( 'content-trees/' . $name, $content ) ) ) {
				$detected[ $name ] = true;
			}
		}
	}
	foreach ( array_keys( $detected ) as $name ) {
		wp_enqueue_style( 'content-trees' );
		wp_enqueue_script( $handles[ $name ] );
	}
}
