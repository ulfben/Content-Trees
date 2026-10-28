<?php
/** Verify release metadata and evaluate the actual editor asset version values. */
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/content-trees.php' );
if ( ! preg_match( '/^ \* Version: (\S+)$/m', $main, $header )
	|| ! preg_match( "/const VERSION = '([^']+)'/", $main, $constant )
	|| $header[1] !== $constant[1] ) {
	throw new RuntimeException( 'Plugin header and asset version do not match.' );
}
$version = $header[1];
define( 'ContentTrees\VERSION', $version );
foreach ( array( 'archive', 'pages', 'categories', 'tags' ) as $provider ) {
	$block = json_decode( file_get_contents( $root . '/' . $provider . '/block.json' ), true, 512, JSON_THROW_ON_ERROR );
	if ( $version !== $block['version'] ) {
		throw new RuntimeException( 'Block version differs for ' . $provider );
	}
	if ( in_array( $provider, array( 'archive', 'pages' ), true ) ) {
		$asset = require $root . '/' . $provider . '/editor.asset.php';
		if ( $version !== $asset['version'] ) {
			throw new RuntimeException( 'Editor asset version differs for ' . $provider );
		}
	}
}
if ( ! preg_match( '/^Stable tag: (\S+)$/m', file_get_contents( $root . '/readme.txt' ), $stable ) || $stable[1] !== $version ) {
	throw new RuntimeException( 'Readme stable tag differs from the plugin version.' );
}
foreach ( json_decode( file_get_contents( $root . '/tools/plugin-files.json' ), true, 512, JSON_THROW_ON_ERROR ) as $file ) {
	if ( ! is_file( $root . '/' . $file ) ) {
		throw new RuntimeException( 'Missing installable file: ' . $file );
	}
}
echo 'PASS: Plugin, block, editor asset and readme versions match ' . $version . '.' . PHP_EOL;
