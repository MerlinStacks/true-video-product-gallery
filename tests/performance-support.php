<?php
/** Additional lightweight WordPress doubles for performance regressions.
 * @package TVPG
 */

function add_option( $key, $value, $deprecated = '', $autoload = true ) {
	global $_test_options;
	if ( isset( $_test_options[ $key ] ) ) {
		return false;
	}
	$_test_options[ $key ] = $value;
	return true;
}

function wp_next_scheduled( $hook, $args ) {
	return $GLOBALS['tvpg_test_events'][ $hook . serialize( $args ) ] ?? false;
}

function wp_schedule_single_event( $time, $hook, $args, $wp_error = false ) {
	$GLOBALS['tvpg_test_events'][ $hook . serialize( $args ) ] = $time;
	return true;
}

function wp_scripts() { return $GLOBALS['tvpg_test_scripts']; }
function wp_styles() { return $GLOBALS['tvpg_test_styles']; }
function wp_script_is( $handle, $status = 'enqueued' ) { return isset( wp_scripts()->registered[ $handle ] ); }
function wp_style_is( $handle, $status = 'enqueued' ) { return isset( wp_styles()->registered[ $handle ] ); }
function wp_enqueue_script( $handle, $src, $deps = array(), $version = false, $args = false ) {
	// WordPress deliberately ignores new registration arguments for existing handles.
	wp_scripts()->registered[ $handle ] ??= (object) array( 'src' => $src, 'deps' => $deps );
}
function wp_enqueue_style( $handle, $src, $deps = array(), $version = false ) {
	wp_styles()->registered[ $handle ] ??= (object) array( 'src' => $src, 'deps' => $deps );
}
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['tvpg_test_localized'][ $name ] = $data; }
function wp_add_inline_style( $handle, $css ) {}
function is_product() { return false; }
function is_shop() { return true; }
function is_product_taxonomy() { return false; }
function is_product_category() { return false; }
function is_product_tag() { return false; }
function home_url() { return 'https://example.com'; }
function shortcode_atts( $defaults, $atts, $shortcode = '' ) { return array_merge( $defaults, $atts ); }
function get_post( $id ) { return $GLOBALS['tvpg_test_post'] ?? null; }
function current_user_can( $capability, ...$args ) { return $GLOBALS['tvpg_test_caps'][ $capability ] ?? false; }
function post_password_required( $post ) { return ! empty( $post->post_password ); }
function esc_attr_e( $text, $domain = 'default' ) { echo esc_attr( $text ); }
function wc_placeholder_img_src( $size ) { return 'https://example.com/placeholder.jpg'; }

// Needed by the real WordPress HTML API when loaded independently of WordPress.
function wp_kses_uri_attributes() { return array( 'src', 'href', 'poster' ); }
function wp_kses_bad_protocol( $value, $protocols ) { return $value; }
function wp_allowed_protocols() { return array( 'http', 'https' ); }
