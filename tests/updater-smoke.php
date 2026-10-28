<?php
/** Standalone bootstrap smoke test with WP API doubles; no live HTTP requests. */
define( 'ABSPATH', __DIR__ . '/mocks/wordpress/' );
define( 'WP_DEBUG', true );
define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) );
define( 'WPMU_PLUGIN_DIR', WP_PLUGIN_DIR . '/mu-plugins' );
$hooks = array();
function add_filter( $name, $callback, ...$args ) { $GLOBALS['hooks'][ $name ][] = $callback; }
function add_action( $name, $callback, ...$args ) { add_filter( $name, $callback ); }
function apply_filters( $name, $value, ...$args ) { return $value; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/plugins/' . basename( dirname( $file ) ) . '/'; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_option( $name, $default = false ) { return $default; }
function get_site_option( $name, $default = false ) { return $default; }
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_event( $time, $schedule, $hook ) { $GLOBALS['schedules'][ $hook ] = $schedule; }
function register_deactivation_hook( $file, $callback ) {}
function register_activation_hook( $file, $callback ) {}
function is_admin() { return false; }
function did_action( $hook ) { return 0; }
function get_plugin_data( $file, ...$args ) {
	preg_match( '/^ \* Version: (.+)$/m', file_get_contents( $file ), $matches );
	return array( 'Version' => $matches[1], 'Name' => 'True Video Product Gallery' );
}
function check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

// Simulate another plugin loading the same bundled PUC version first.
$library = dirname( __DIR__ ) . '/includes/plugin-update-checker/';
require $library . 'plugin-update-checker.php';
// A separate copy uses require internally, so explicitly exercise repeated loading.
require $library . 'plugin-update-checker.php';
$other = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://example.test/other/info.json',
	dirname( __DIR__ ) . '/true-video-product-gallery.php',
	'other-plugin'
);
require dirname( __DIR__ ) . '/true-video-product-gallery.php';
tvpg_init(); // WooCommerce is deliberately unavailable.
$checkers = array_filter( $hooks['plugins_api'], function ( $callback ) {
	return is_array( $callback ) && $callback[0] instanceof \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\UpdateChecker
		&& $callback[0]->slug === 'true-video-product-gallery';
} );
check( count( $checkers ) === 1, 'Expected one plugin-information handler' );
$checker = array_values( $checkers )[0][0];
check( $checker->metadataUrl === 'https://sldevs.com.au/updates/true-video-product-gallery/info.json', 'Incorrect feed' );
check( $checker->slug === 'true-video-product-gallery', 'Incorrect slug' );
check( $checker->optionName !== $other->optionName, 'Plugin update caches must be isolated' );
check( $checker->pluginFile === 'true-video-product-gallery/true-video-product-gallery.php', 'Incorrect plugin basename' );
check( $checker->getInstalledVersion() === TVPG_VERSION, 'Installed version mismatch' );
check( isset( $hooks['admin_notices'] ), 'Missing WooCommerce dependency notice' );
check( isset( $hooks['site_transient_update_plugins'] ), 'Missing update transient hook' );
check( ! isset( $hooks['auto_update_plugin'] ), 'Must not force automatic updates' );
check( in_array( 'twicedaily', $GLOBALS['schedules'], true ), 'Default cached schedule missing' );
require $library . 'vendor/Parsedown.php';
check( strpos( ( new Parsedown() )->text( '**Release**' ), '<strong>Release</strong>' ) !== false, 'Missing Markdown dependency' );
echo "Updater bootstrap, co-loading, dependency-independent hooks and bundled Markdown: passed.\n";
