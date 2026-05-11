<?php
/**
 * Minimal WordPress stub functions for PHPUnit.
 *
 * @package TVPG
 */

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_attr__( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

function esc_attr( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
}

function esc_url_raw( $url ) {
	return esc_url( $url );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function get_option( $option, $default = false ) {
	global $_test_options;
	return $_test_options[ $option ] ?? $default;
}

function update_option( $option, $value ) {
	global $_test_options;
	$_test_options[ $option ] = $value;
	return true;
}

function delete_option( $option ) {
	global $_test_options;
	unset( $_test_options[ $option ] );
	return true;
}

function wp_parse_args( $args, $defaults = '' ) {
	if ( is_object( $args ) ) {
		$parsed_args = get_object_vars( $args );
	} elseif ( is_array( $args ) ) {
		$parsed_args = $args;
	} else {
		wp_parse_str( $args, $parsed_args );
	}

	if ( is_array( $defaults ) ) {
		return array_merge( $defaults, $parsed_args );
	}
	return $parsed_args;
}

function wp_parse_str( $string, &$array ) {
	parse_str( $string, $array );
}

function wp_remote_get( $url, $args = array() ) {
	return new WP_Error( 'http_request_failed', 'HTTP requests disabled in tests.' );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_remote_retrieve_body( $response ) {
	return '';
}

function set_transient( $transient, $value, $expiration = 0 ) {
	global $_test_transients;
	$_test_transients[ $transient ] = array( 'value' => $value, 'expires' => time() + $expiration );
	return true;
}

function get_transient( $transient ) {
	global $_test_transients;
	if ( ! isset( $_test_transients[ $transient ] ) ) {
		return false;
	}
	if ( $_test_transients[ $transient ]['expires'] < time() ) {
		unset( $_test_transients[ $transient ] );
		return false;
	}
	return $_test_transients[ $transient ]['value'];
}

function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options );
}

function wp_kses( $string, $allowed_html ) {
	return strip_tags( $string );
}

function rest_ensure_response( $data, $status = 200 ) {
	return $data;
}

class WP_Error {
	public $errors = array();
	public $error_data = array();

	public function __construct( $code, $message = '', $data = '' ) {
		$this->errors[ $code ][] = $message;
		$this->error_data[ $code ] = $data;
	}
}

class WP_REST_Request {
	private $params = array();
	public function set_param( $key, $value ) {
		$this->params[ $key ] = $value;
	}
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

class WC_Product {
	private $id = 1;
	public function get_id() { return $this->id; }
	public function get_name() { return 'Test Product'; }
	public function get_short_description() { return 'Short desc'; }
	public function get_description() { return 'Long desc'; }
	public function get_image_id() { return 0; }
	public function is_type( $type ) { return 'simple' === $type; }
	public function get_children() { return array(); }
}

function get_the_date( $format, $post_id ) {
	return date( $format );
}

function get_post_meta( $post_id, $key, $single = false ) {
	return '';
}

function wp_get_attachment_image( $attachment_id, $size, $icon, $attr ) {
	return '';
}

function wp_get_attachment_image_url( $attachment_id, $size ) {
	return 'https://example.com/image.jpg';
}

function wp_get_attachment_url( $id ) {
	return 'https://example.com/image.jpg';
}

function get_current_user_id() {
	return 1;
}

function wp_get_attachment_image_src( $attachment_id, $size, $icon ) {
	return array( 'https://example.com/image.jpg', 600, 600, false );
}
