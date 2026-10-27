<?php
/** Reject invalid UTF-8 and common encoding corruption in tracked text sources. */
function encoding_problem( $text ) {
	if ( 1 !== preg_match( '//u', $text ) ) {
		return 'invalid UTF-8';
	}
	if ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) {
		return 'unexpected UTF-8 BOM';
	}
	if ( preg_match( '/\x{00C3}|\x{00C2}|\x{00E2}\x{20AC}|\x{FFFD}/u', $text, $match, PREG_OFFSET_CAPTURE ) ) {
		return 'possible mojibake on line ' . ( 1 + substr_count( substr( $text, 0, $match[0][1] ), "\n" ) );
	}
	return null;
}

// Verify the detector without putting corrupted characters into this source file.
foreach ( array(
	array( "Caf\xC3\xA9, A\xE2\x80\x93Z and loading\xE2\x80\xA6", false ),
	array( "Broken \xC3\x83\xC2\xA9", true ),
	array( "Broken \xC3\xA2\xE2\x82\xAC\xC2\xA6", true ),
	array( "Broken \xFF", true ),
	array( "\xEF\xBB\xBF<?php", true ),
) as $case ) {
	if ( ( null !== encoding_problem( $case[0] ) ) !== $case[1] ) {
		throw new RuntimeException( 'Encoding detector self-check failed.' );
	}
}

$root = dirname( __DIR__ );
$files = array_slice( $argv, 1 );
if ( ! $files ) {
	$process = proc_open(
		array( 'git', '-c', 'safe.directory=' . $root, '-C', $root, 'ls-files', '--cached', '--others', '--exclude-standard', '-z' ),
		array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
		$pipes
	);
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Cannot enumerate tracked source files.' );
	}
	$output = stream_get_contents( $pipes[1] );
	$error = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	if ( 0 !== proc_close( $process ) ) {
		throw new RuntimeException( $error );
	}
	$files = explode( "\0", $output );
}
$failed = false;
$checked = 0;
foreach ( $files as $file ) {
	if ( '' === $file || ! preg_match( '/(?:\.(?:php|js|mjs|css|json|yml|yaml|xml|md|txt)|(?:^|\/)\.(?:gitignore|git-blame-ignore-revs|editorconfig))$/', $file ) ) {
		continue;
	}
	$text = file_get_contents( $root . '/' . $file );
	if ( false === $text ) {
		throw new RuntimeException( 'Cannot read ' . $file );
	}
	++$checked;
	$problem = encoding_problem( $text );
	if ( null !== $problem ) {
		fwrite( STDERR, $file . ': ' . $problem . PHP_EOL );
		$failed = true;
	}
}
if ( $failed ) {
	exit( 1 );
}
echo 'PASS: UTF-8 encoding and mojibake checks for ' . $checked . ' text files.' . PHP_EOL;
