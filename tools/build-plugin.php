<?php
/** Copy only installable files; development dependencies and tests never ship. */
$root        = dirname( __DIR__ );
$destination = $argv[1] ?? $root . '/dist/content-trees';
if ( file_exists( $destination ) ) {
	throw new RuntimeException( 'Choose an empty destination; existing deliveries are immutable.' ); }
foreach ( json_decode( file_get_contents( __DIR__ . '/plugin-files.json' ), true, 512, JSON_THROW_ON_ERROR ) as $relative ) {
	$target = $destination . '/' . $relative;
	if ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0777, true ) && ! is_dir( dirname( $target ) ) ) {
		throw new RuntimeException( 'Cannot create delivery directory.' ); }
	if ( ! copy( $root . '/' . $relative, $target ) || hash_file( 'sha256', $target ) !== hash_file( 'sha256', $root . '/' . $relative ) ) {
		throw new RuntimeException( 'Copy failed: ' . $relative ); }
}
echo $destination . PHP_EOL;
