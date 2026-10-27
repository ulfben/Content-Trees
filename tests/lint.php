<?php
/** Syntax-check tracked PHP sources and regression scripts without dependencies. */
$root  = dirname( __DIR__ );
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
foreach ( $files as $file ) {
	$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
	if ( $file->getExtension() !== 'php' || preg_match( '~^(?:vendor|node_modules|dist|\.git|tests/results)/~', $relative ) ) {
		continue; }
	passthru( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file->getPathname() ), $status );
	if ( $status ) {
		exit( $status ); }
}
