<?php
/** Regressions for shared hierarchy, Archive adapters and frontend detection. */
require __DIR__ . '/bootstrap.php';

function consolidation_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS: ' . $message . PHP_EOL;
}

$defaults = ContentTrees\archive_options();
consolidation_check( 'Archives' === $defaults['title'], 'Archive entry points share the same default heading.' );
foreach ( array( ContentTrees\render_archive_block( array() ), do_shortcode( '[content_trees_archive]' ) ) as $html ) {
	consolidation_check( false !== strpos( $html, '<h2>Archives</h2>' ), 'Block and shortcode use the normalized Archive heading.' );
}
foreach ( array( ContentTrees\render_archive_block( array( 'title' => '' ) ), do_shortcode( '[content_trees_archive title=""]' ) ) as $html ) {
	consolidation_check( false === strpos( $html, '<h2>' ) && false !== strpos( $html, 'aria-label="Archives"' ), 'Blank headings retain a named Archive landmark.' );
}
$called = 0;
$filter = static function ( $attributes ) use ( &$called ) {
	++$called;
	$attributes['title'] = 'Filtered archive';
	$attributes['show_posts'] = '0';
	return $attributes;
};
add_filter( 'shortcode_atts_content_trees_archive', $filter );
try {
	$html = do_shortcode( '[content_trees_archive]' );
	consolidation_check( 1 === $called && false !== strpos( $html, '<h2>Filtered archive</h2>' ), 'The Archive shortcode runs its standard attribute filter.' );
} finally {
	remove_filter( 'shortcode_atts_content_trees_archive', $filter );
}
$edges = array( 9 => 4, 4 => 7, 7 => 4, 3 => 0, 2 => 2 );
consolidation_check( array( 4 => true, 7 => true, 9 => true ) == ContentTrees\descendants( array( 4 ), $edges ), 'Descendant traversal terminates through malformed cycles.' );
$fixed = ContentTrees\acyclic_parents( $edges );
consolidation_check( 0 === $fixed[4] && 0 === $fixed[2] && 4 === $fixed[9], 'Shared cycle repair breaks the smallest edge and retains descendants.' );
consolidation_check( $fixed == ContentTrees\acyclic_parents( array_reverse( $edges, true ) ), 'Cycle repair is independent of sibling display order.' );

$old_query = $GLOBALS['wp_query'];
$old_widgets = get_option( 'widget_block', false );
$old_sidebars = get_option( 'sidebars_widgets', array() );
$old_sidebar_cache = $GLOBALS['_wp_sidebars_widgets'] ?? null;
$old_registered_widgets = $GLOBALS['wp_registered_widgets'];
$handles = array( 'content-trees', 'content-trees-pages', 'content-trees-taxonomy' );
$old_scripts = wp_scripts()->queue;
$old_styles = wp_styles()->queue;
try {
	update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => array(), 'array_version' => 3 ) );
	unset( $GLOBALS['_wp_sidebars_widgets'] );
	foreach ( $handles as $handle ) { wp_dequeue_script( $handle ); }
	wp_dequeue_style( 'content-trees' );
	$GLOBALS['wp_query'] = new WP_Query();
	$GLOBALS['wp_query']->posts = array(
		new WP_Post( (object) array( 'ID' => 0, 'post_content' => 'Unrelated content' ) ),
		new WP_Post( (object) array( 'ID' => 0, 'post_content' => '[content_trees_archive] <!-- wp:content-trees/pages /--> [content_trees_tags]' ) ),
	);
	ContentTrees\enqueue_detected_trees();
	foreach ( $handles as $handle ) {
		consolidation_check( wp_script_is( $handle, 'enqueued' ), 'Later queried posts enqueue ' . $handle . '.' );
	}
	foreach ( $handles as $handle ) { wp_dequeue_script( $handle ); }
	$GLOBALS['wp_query']->posts = array();
	update_option( 'widget_block', array( 991 => array( 'content' => '<!-- wp:content-trees/categories /-->' ), 992 => array( 'content' => '[content_trees_pages]' ) ) );
	$GLOBALS['wp_registered_widgets']['block-991'] = array( 'id' => 'block-991' );
	$GLOBALS['wp_registered_widgets']['block-992'] = array( 'id' => 'block-992' );
	update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => array( 'block-992' ), 'ctrees-test' => array( 'block-991' ), 'array_version' => 3 ) );
	unset( $GLOBALS['_wp_sidebars_widgets'] );
	ContentTrees\enqueue_detected_trees();
	consolidation_check( wp_script_is( 'content-trees-taxonomy', 'enqueued' ) && ! wp_script_is( 'content-trees-pages', 'enqueued' ), 'Only active block widgets contribute frontend assets.' );
} finally {
	if ( false === $old_widgets ) {
		delete_option( 'widget_block' );
	} else {
		update_option( 'widget_block', $old_widgets );
	}
	update_option( 'sidebars_widgets', $old_sidebars );
	$GLOBALS['_wp_sidebars_widgets'] = $old_sidebar_cache;
	$GLOBALS['wp_registered_widgets'] = $old_registered_widgets;
	$GLOBALS['wp_query'] = $old_query;
	wp_scripts()->queue = $old_scripts;
	wp_styles()->queue = $old_styles;
}
echo "PASS: consolidation regressions; existing widget settings restored.\n";
