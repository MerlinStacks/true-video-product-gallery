<?php
/** Isolated generator harness; intentionally independent of the shared frontend mocks. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['preview_root'] = sys_get_temp_dir() . '/tvpg-generator-tests-' . getmypid();
mkdir( $GLOBALS['preview_root'], 0700, true );
define( 'TVPG_FFMPEG_PATH', $GLOBALS['preview_root'] . '/encoder' );
file_put_contents( TVPG_FFMPEG_PATH, '#!' . PHP_BINARY . "\n" . file_get_contents( __DIR__ . '/encoder-fixture.php' ) );
chmod( TVPG_FFMPEG_PATH, 0700 );
register_shutdown_function( function () {
	$root = $GLOBALS['preview_root'];
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $file ) {
		$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $root );
} );
function __( $text, $domain = '' ) { return $text; }
function get_current_blog_id() { return $GLOBALS['preview_blog_id'] ?? 1; }
function add_action( $hook, $callback, $priority = 10, $accepted = 1 ) { $GLOBALS['preview_hooks'][ $hook ] = compact( 'callback', 'priority', 'accepted' ); }
function get_post_type( $id ) { return $GLOBALS['preview_types'][ $id ] ?? false; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['preview_meta'][ $id ][ $key ] ?? ''; }
function get_post_mime_type( $id ) { return $GLOBALS['preview_mimes'][ $id ] ?? ''; }
function get_attached_file( $id, $unfiltered = false ) { return $GLOBALS['preview_files'][ $id ] ?? ''; }
function attachment_url_to_postid( $url ) { $GLOBALS['preview_lookups'] = ( $GLOBALS['preview_lookups'] ?? 0 ) + 1; return array_search( $url, $GLOBALS['preview_urls'], true ) ?: 0; }
function wp_upload_dir( $time = null, $create = true ) { return array( 'basedir' => $GLOBALS['preview_root'] . '/uploads', 'baseurl' => 'https://example.test/uploads', 'error' => false ); }
function get_option( $key, $default = false ) {
	if ( isset( $GLOBALS['preview_cache']['alloptions'][ $key ] ) ) { return $GLOBALS['preview_cache']['alloptions'][ $key ]; }
	if ( isset( $GLOBALS['preview_cache']['notoptions'][ $key ] ) ) { return $default; }
	if ( isset( $GLOBALS['preview_cache'][ $key ] ) ) { return $GLOBALS['preview_cache'][ $key ]; }
	if ( isset( $GLOBALS['preview_options'][ $key ] ) ) { return $GLOBALS['preview_cache'][ $key ] = $GLOBALS['preview_options'][ $key ]; }
	$GLOBALS['preview_cache']['notoptions'][ $key ] = true;
	return $default;
}
function add_option( $key, $value, $deprecated = '', $autoload = false ) {
	// Model WP's cached existence check followed by INSERT ... ON DUPLICATE KEY UPDATE.
	if ( false !== get_option( $key ) ) { return false; }
	update_option( $key, $value );
	return true;
}
function update_option( $key, $value, $autoload = false ) { $GLOBALS['preview_options'][ $key ] = $value; wp_cache_delete( $key, 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( 'alloptions', 'options' ); return true; }
function delete_option( $key ) { unset( $GLOBALS['preview_options'][ $key ] ); wp_cache_delete( $key, 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( 'alloptions', 'options' ); return true; }
function get_transient( $key ) {
	if ( get_option( '_transient_timeout_' . $key, 0 ) <= time() ) { delete_transient( $key ); return false; }
	return get_option( '_transient_' . $key );
}
function set_transient( $key, $value, $ttl ) { update_option( '_transient_' . $key, $value ); update_option( '_transient_timeout_' . $key, time() + $ttl ); }
function delete_transient( $key ) { delete_option( '_transient_' . $key ); delete_option( '_transient_timeout_' . $key ); }
function maybe_serialize( $value ) { return serialize( $value ); }
function wp_cache_delete( $key, $group ) { unset( $GLOBALS['preview_cache'][ $key ] ); }
function clean_post_cache( $id ) {
	if ( isset( $GLOBALS['preview_cache_refresh'] ) ) {
		call_user_func( $GLOBALS['preview_cache_refresh'], $id );
	}
}
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0700, true ); }
function get_temp_dir() { return $GLOBALS['preview_root'] . '/'; }
function wp_next_scheduled( $hook, $args ) { return false; }
function wp_schedule_single_event( $time, $hook, $args ) {
	if ( ! empty( $GLOBALS['preview_schedule_fail'] ) ) { return false; }
	if ( isset( $GLOBALS['preview_before_schedule'] ) ) {
		$callback = $GLOBALS['preview_before_schedule']; unset( $GLOBALS['preview_before_schedule'] ); $callback( $args );
	}
	$GLOBALS['preview_jobs'][] = compact( 'time', 'hook', 'args' ); return true;
}
function wp_clear_scheduled_hook( $hook, $args ) {
	$GLOBALS['preview_jobs'] = array_filter( $GLOBALS['preview_jobs'], function ( $job ) use ( $args ) { return $job['args'] !== $args; } );
}
function wp_unschedule_hook( $hook ) { $GLOBALS['preview_jobs'] = array(); }
class TVPG_Preview_Test_DB {
	public $options = 'options';
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function query( $prepared ) {
		list( $query, $args ) = $prepared;
		if ( isset( $GLOBALS['preview_before_query'] ) ) {
			$callback = $GLOBALS['preview_before_query']; unset( $GLOBALS['preview_before_query'] ); $callback( $query, $args );
		}
		$GLOBALS['preview_sql'][] = $query;
		if ( 0 === strpos( $query, 'INSERT IGNORE' ) ) {
			if ( isset( $args[2] ) && ( ! isset( $GLOBALS['preview_options'][ $args[2] ] ) || serialize( $GLOBALS['preview_options'][ $args[2] ] ) !== $args[3] ) ) { return 0; }
			if ( isset( $GLOBALS['preview_options'][ $args[0] ] ) ) { return 0; }
			$GLOBALS['preview_options'][ $args[0] ] = unserialize( $args[1] ); return 1;
		}
		if ( 0 === strpos( $query, 'UPDATE' ) ) {
			if ( ! isset( $GLOBALS['preview_options'][ $args[1] ] ) || serialize( $GLOBALS['preview_options'][ $args[1] ] ) !== $args[2] || $args[0] === $args[2] ) { return 0; }
			$GLOBALS['preview_options'][ $args[1] ] = unserialize( $args[0] ); return 1;
		}
		if ( 0 !== strpos( $query, 'DELETE' ) ) { throw new RuntimeException( 'Unexpected SQL: ' . $query ); }
		if ( ! isset( $GLOBALS['preview_options'][ $args[0] ] ) || serialize( $GLOBALS['preview_options'][ $args[0] ] ) !== $args[1] ) { return 0; }
		unset( $GLOBALS['preview_options'][ $args[0] ] );
		return 1;
	}
	public function esc_like( $value ) { return $value; }
	public function get_col( $query ) {
		$GLOBALS['preview_scans'] = ( $GLOBALS['preview_scans'] ?? 0 ) + 1;
		$prefix = rtrim( $query[1][0], '%' );
		return array_values( array_filter( array_keys( $GLOBALS['preview_options'] ), function ( $name ) use ( $prefix ) { return 0 === strpos( $name, $prefix ); } ) );
	}
}
$GLOBALS['wpdb'] = new TVPG_Preview_Test_DB();
require_once dirname( __DIR__, 2 ) . '/includes/class-tvpg-preview-generator.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tvpg-frontend.php';
