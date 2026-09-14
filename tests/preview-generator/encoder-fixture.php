<?php
/** Fake executable exercises process boundaries; it is not a codec validation substitute. */
$input = stream_get_contents( STDIN );
if ( in_array( '-protocols', $argv, true ) ) {
	fwrite( STDOUT, 0 === strpos( $input, 'NOFD' ) ? "Input:\n pipe\nOutput:\n file\n" : "Input:\n fd\n pipe\nOutput:\n file\n" );
	exit( 0 );
}
if ( in_array( 'null', $argv, true ) ) {
	fwrite( STDOUT, "frame=192\nprogress=end\n" );
	exit( 'ftyp' === substr( $input, 4, 4 ) && false === strpos( $input, 'INVALID' ) ? 0 : 1 );
}
if ( 0 === strpos( $input, 'FAIL' ) ) { exit( 1 ); }
if ( 0 === strpos( $input, 'SLEEP' ) ) { sleep( 5 ); }
if ( preg_match( '/^CHANGE:([^\n]+)/', $input, $match ) ) { file_put_contents( $match[1], 'changed' ); }
if ( preg_match( '/^SAME:([^\n]+)/', $input, $match ) ) {
	$mtime = filemtime( $match[1] );
	file_put_contents( $match[1], str_repeat( 'r', strlen( $input ) ) );
	touch( $match[1], $mtime );
}
$output = end( $argv );
file_put_contents( $output, "\x00\x00\x00\x18ftypisom" . str_repeat( 'x', 100 ) . ( 0 === strpos( $input, 'INVALID' ) ? 'INVALID' : '' ) );
fwrite( STDOUT, 0 === strpos( $input, 'EMPTY' ) ? "frame=0\nprogress=end\n" : "frame=192\nprogress=end\n" );
exit( 0 );
