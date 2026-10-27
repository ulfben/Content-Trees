<?php
/** Check exact translation IDs, rendered widget text, and Archive browser fixtures. */
require __DIR__ . '/bootstrap.php';

function interface_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS: ' . $message . PHP_EOL;
}
function interface_dom( $html ) {
	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	try {
		$document->loadHTML( '<!doctype html><meta charset="utf-8">' . $html );
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}
	return new DOMXPath( $document );
}

$posts = array();
$term_id = 0;
$old_query = $GLOBALS['wp_query'];
$old_user = get_current_user_id();
$strings = array(
	"Loading posts\u{2026}", "Loading archive posts\u{2026}", "Loading tree posts\u{2026}",
	"A\u{2013}Z", "Z\u{2013}A",
);
$translate = static function ( $translation, $text, $domain ) use ( $strings ) {
	return 'content-trees' === $domain && in_array( $text, $strings, true ) ? 'Translated ' . $text : $translation;
};
try {
	wp_set_current_user( 1 );
	$term = wp_insert_term( 'Interface fixture ' . wp_generate_password( 8, false ), 'category' );
	if ( is_wp_error( $term ) ) { throw new RuntimeException( $term->get_error_message() ); }
	$term_id = (int) $term['term_id'];
	foreach ( array( '1987-01-01', '1987-01-02', '1987-02-01', '1988-01-01' ) as $date ) {
		$id = wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'Interface archive ' . $date,
			'post_date' => $date . ' 12:00:00', 'post_date_gmt' => $date . ' 12:00:00', 'post_author' => 1 ), true );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$posts[] = (int) $id;
		wp_set_object_terms( $id, array( $term_id ), 'category' );
	}
	$GLOBALS['wp_query'] = new WP_Query();
	add_filter( 'gettext', $translate, 10, 3 );
	try {
		$archive = interface_dom( ContentTrees\render( array( 'auto_open' => false ) ) );
		$messages = json_decode( $archive->query( '//nav' )->item( 0 )->getAttribute( 'data-messages' ), true );
		interface_check( 'Translated ' . $strings[0] === $messages['loading'] && 'Translated ' . $strings[1] === $messages['bulkLoading'], 'Archive loading announcements retain their original translated msgids.' );
		$taxonomy = interface_dom( ContentTrees\render_taxonomy( 'category', array( 'root' => $term_id, 'auto_open' => false ) ) );
		$messages = json_decode( $taxonomy->query( '//nav' )->item( 0 )->getAttribute( 'data-taxonomy-messages' ), true );
		interface_check( 'Translated ' . $strings[0] === $messages['loading'] && 'Translated ' . $strings[2] === $messages['bulkLoading'], 'Taxonomy loading announcements retain their original translated msgids.' );
		foreach ( array( 'Pages_Widget', 'Categories_Widget', 'Tags_Widget' ) as $name ) {
			$widget = $GLOBALS['wp_widget_factory']->widgets['ContentTrees\\' . $name];
			ob_start();
			$widget->form( array( 'sort_by' => 'title' ) );
			$form = interface_dom( ob_get_clean() );
			$options = $form->query( '//option' );
			$labels = array();
			foreach ( $options as $option ) { $labels[] = $option->textContent; }
			interface_check( in_array( 'Translated ' . $strings[3], $labels, true ) && in_array( 'Translated ' . $strings[4], $labels, true ), $name . ' uses the original alphabetical-order translation IDs.' );
			if ( 'Pages_Widget' === $name ) {
				$select = $form->query( '//select[@data-title-asc]' )->item( 0 );
				interface_check( 'Translated ' . $strings[3] === $select->getAttribute( 'data-title-asc' ) && 'Translated ' . $strings[4] === $select->getAttribute( 'data-title-desc' ), 'Pages dynamic order labels preserve translated en dashes.' );
			}
		}
	} finally {
		remove_filter( 'gettext', $translate, 10, 3 );
	}
	foreach ( array( 'archive' => 'content-trees', 'pages' => 'content-trees-pages' ) as $provider => $unused ) {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'content-trees/' . $provider );
		$script = wp_scripts()->registered[$block->editor_script_handles[0]];
		interface_check( ContentTrees\VERSION === $script->ver, $provider . ' registers the current editor script version.' );
	}
	$html = '<!doctype html><meta charset="utf-8"><main>';
	foreach ( array( 'Grouped archive' => array(), 'Flat archive' => array( 'group_years' => false ), 'Compact archive' => array( 'show_posts' => false ) ) as $title => $options ) {
		$html .= ContentTrees\render( array_merge( array( 'title' => $title, 'auto_open' => false, 'show_controls' => true, 'close_siblings' => true ), $options ) );
	}
	$html .= '</main>';
	$responses = array();
	foreach ( array( array( 1987, 1 ), array( 1987, 2 ), array( 1988, 1 ) ) as $month ) {
		$responses[sprintf( '%04d-%02d', $month[0], $month[1] )] = ContentTrees\month_posts( $month[0], $month[1] );
	}
	file_put_contents( dirname( __DIR__ ) . '/results/archive-render.html', $html );
	file_put_contents( dirname( __DIR__ ) . '/results/archive-posts.json', wp_json_encode( $responses ) );
} finally {
	remove_filter( 'gettext', $translate, 10, 3 );
	foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
	if ( $term_id ) { wp_delete_term( $term_id, 'category' ); }
	$GLOBALS['wp_query'] = $old_query;
	wp_set_current_user( $old_user );
}
echo "PASS: interface text, actual editor registrations and Archive fixtures; temporary content removed.\n";
