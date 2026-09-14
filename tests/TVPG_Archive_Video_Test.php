<?php
/** Automatic archive preview and editor regressions.
 * @package TVPG
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/performance-support.php';
require_once TVPG_PATH . 'includes/class-tvpg-admin.php';
require_once TVPG_PATH . 'includes/class-tvpg-block-editor.php';
require_once TVPG_PATH . 'includes/class-tvpg-frontend.php';
require_once TVPG_PATH . 'includes/class-tvpg-preview-generator.php';

function get_post_type( $id ) { return $GLOBALS['tvpg_test_post_type'] ?? 'product'; }
function is_admin() { return false; }
function wp_unslash( $value ) { return stripslashes( $value ); }
function sanitize_key( $value ) { return strtolower( $value ); }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce && 'tvpg_save_video_meta' === $action; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['tvpg_test_meta'][ $id ][ $key ] = $value; }
function wc_get_product( $id ) { return new WC_Product(); }
function register_post_meta( $type, $key, $args ) { $GLOBALS['tvpg_test_registered_meta'][ $type ][ $key ] = $args; }
function wp_nonce_field( $action, $name ) {}
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $text, $domain = 'default' ) { echo esc_html( __( $text, $domain ) ); }
// Remote test URLs cannot resolve to local media or launch background work.
function wp_upload_dir( $time = null, $create_dir = true ) {
	return array( 'baseurl' => 'https://example.com/wp-content/uploads', 'basedir' => '/nonexistent-tvpg-test-uploads', 'error' => false );
}

class TVPG_Archive_Video_Test extends TestCase {
	protected function setUp(): void {
		$GLOBALS['tvpg_test_meta'] = array();
		$GLOBALS['tvpg_test_caps'] = array( 'edit_post' => true, 'edit_products' => true );
		$GLOBALS['tvpg_test_registered_meta'] = array();
		$GLOBALS['tvpg_test_events'] = array();
		$_POST = array( 'tvpg_video_nonce' => 'valid' );
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['tvpg_test_meta'], $GLOBALS['tvpg_test_caps'], $GLOBALS['tvpg_test_post_type'], $GLOBALS['product'], $GLOBALS['post'], $GLOBALS['tvpg_test_translations'], $GLOBALS['tvpg_test_registered_meta'], $GLOBALS['tvpg_test_events'] );
	}

	public function test_automatic_fallback_ignores_legacy_preview_without_changing_gallery_meta(): void {
		$method = new ReflectionMethod( TVPG_Frontend::class, 'get_archive_video_url' );
		$method->setAccessible( true );
		$frontend = new TVPG_Frontend();
		update_post_meta( 1, '_tvpg_video_url', 'https://example.com/main.mp4' );
		foreach ( array( '', 'invalid', 'https://example.com/preview.webm' ) as $preview ) {
			update_post_meta( 1, '_tvpg_archive_video_url', $preview );
			$this->assertSame( 'https://example.com/main.mp4', $method->invoke( $frontend, new WC_Product() ) );
			$this->assertSame( 'https://example.com/main.mp4', get_post_meta( 1, '_tvpg_video_url', true ) );
		}
		update_post_meta( 1, '_tvpg_video_url', '' );
		$this->assertSame( '', $method->invoke( $frontend, new WC_Product() ) );
		$this->assertSame( array(), $GLOBALS['tvpg_test_events'] );
	}

	public function test_save_leaves_legacy_metadata_dormant_even_when_submitted(): void {
		$admin = new TVPG_Admin();
		update_post_meta( 1, '_tvpg_archive_video_url', 'https://example.com/old.mp4' );
		$admin->save_video_meta_box( 1 );
		$this->assertSame( 'https://example.com/old.mp4', get_post_meta( 1, '_tvpg_archive_video_url', true ) );
		foreach ( array( 'https://example.com/new.webm', '', 'https://example.com/page' ) as $input ) {
			$_POST['tvpg_archive_video_url'] = $input;
			$admin->save_video_meta_box( 1 );
			$this->assertSame( 'https://example.com/old.mp4', get_post_meta( 1, '_tvpg_archive_video_url', true ) );
		}
		$_POST['tvpg_video_url'] = 'https://example.com/main.mp4';
		$admin->save_video_meta_box( 1 );
		$this->assertSame( $_POST['tvpg_video_url'], get_post_meta( 1, '_tvpg_video_url', true ) );
		$admin->save_video_meta_box( 2 );
		$this->assertSame( '', get_post_meta( 2, '_tvpg_archive_video_url', true ) );
	}

	public function test_save_requires_nonce_product_and_permission(): void {
		$admin = new TVPG_Admin();
		$_POST['tvpg_video_url'] = 'https://example.com/new.mp4';
		foreach ( array( 'nonce', 'capability', 'post_type' ) as $failure ) {
			$_POST['tvpg_video_nonce'] = 'nonce' === $failure ? 'invalid' : 'valid';
			$GLOBALS['tvpg_test_caps']['edit_post'] = 'capability' !== $failure;
			$GLOBALS['tvpg_test_post_type'] = 'post_type' === $failure ? 'product_variation' : 'product';
			$admin->save_video_meta_box( 1 );
			$this->assertSame( '', get_post_meta( 1, '_tvpg_video_url', true ) );
		}
	}

	public function test_rest_registration_excludes_manual_preview_and_keeps_video_editor_permission(): void {
		(new TVPG_Block_Editor())->register_meta();
		$this->assertSame( array( '_tvpg_video_url', '_tvpg_video_thumb_url', '_tvpg_use_same_video' ), array_keys( $GLOBALS['tvpg_test_registered_meta']['product'] ) );
		$args = $GLOBALS['tvpg_test_registered_meta']['product']['_tvpg_video_url'];
		$this->assertTrue( $args['show_in_rest'] );
		$this->assertTrue( $args['single'] );
		$this->assertSame( 'https://example.com/clip.mp4', call_user_func( $args['sanitize_callback'], 'https://example.com/clip.mp4' ) );
		$this->assertTrue( call_user_func( $args['auth_callback'] ) );
		$GLOBALS['tvpg_test_caps']['edit_products'] = false;
		$this->assertFalse( call_user_func( $args['auth_callback'] ) );
	}

	public function test_classic_panel_explains_automatic_generation_and_escapes_saved_status(): void {
		$GLOBALS['post'] = (object) array( 'ID' => 1 );
		update_post_meta( 1, '_tvpg_video_url', 'https://example.com/main.mp4' );
		$status = TVPG_Preview_Generator::get_status( 1 );
		$this->assertSame( 'original', $status['state'] );
		$GLOBALS['tvpg_test_translations'][ $status['message'] ] = '<script>alert("status")</script> & original';
		ob_start();
		(new TVPG_Admin())->render_product_data_panel();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Automatic Category Preview', $html );
		$this->assertStringContainsString( 'No second upload is needed', $html );
		$this->assertStringContainsString( 'FFmpeg and background processing', $html );
		$this->assertStringContainsString( 'local WordPress video uploads only', $html );
		$this->assertStringContainsString( 'Simultaneous video playback is unaffected', $html );
		$this->assertStringContainsString( esc_html( '<script>alert("status")</script> & original' ), $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'tvpg_archive_video_url', $html );
		$this->assertStringNotContainsString( 'tvpg_upload_archive_video_btn', $html );
		$this->assertStringContainsString( 'name="tvpg_video_url"', $html );
		$this->assertSame( array(), $GLOBALS['tvpg_test_events'] );
	}

	public function test_editor_sources_have_no_manual_preview_controls_or_localization(): void {
		foreach ( array( 'assets/js/tvpg-admin-product.js', 'assets/js/tvpg-block-editor.js', 'includes/class-tvpg-admin.php', 'includes/class-tvpg-block-editor.php' ) as $file ) {
			$source = file_get_contents( TVPG_PATH . $file );
			$this->assertStringNotContainsString( 'tvpg_archive_video_url', $source );
			$this->assertStringNotContainsString( 'archiveVideoTitle', $source );
			$this->assertStringNotContainsString( 'archiveVideoButton', $source );
		}
		$block = file_get_contents( TVPG_PATH . 'assets/js/tvpg-block-editor.js' );
		$this->assertStringContainsString( 'No second upload is needed', $block );
		$this->assertStringContainsString( 'FFmpeg and background processing', $block );
		$this->assertStringContainsString( 'The original video is used until a preview is ready', $block );
	}

	public function test_archive_validation_only_accepts_playable_provider_types(): void {
		foreach ( array( 'https://example.com/clip.mp4', 'https://youtu.be/dQw4w9WgXcQ', 'https://vimeo.com/123456789' ) as $url ) {
			$this->assertSame( $url, TVPG_Video_Parser::sanitize_archive_video_url( $url ) );
		}
		foreach ( array( 'https://www.tiktok.com/@example/video/123456789', 'https://www.instagram.com/reel/ABC123/' ) as $url ) {
			$this->assertNotFalse( TVPG_Video_Parser::get_video_info( $url ) );
			$this->assertSame( '', TVPG_Video_Parser::sanitize_archive_video_url( $url ) );
		}
	}

	public function test_all_archive_paths_use_preview_and_fallback_with_deferred_sources(): void {
		$path = getenv( 'TVPG_WP_TEST_PATH' );
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			if ( ! $path ) {
				$this->markTestSkipped( 'Set TVPG_WP_TEST_PATH for real HTML API tests.' );
			}
			foreach ( array( 'attribute-token', 'span', 'text-replacement', 'decoder', 'tag-processor' ) as $class ) {
				require_once $path . '/wp-includes/html-api/class-wp-html-' . $class . '.php';
			}
		}
		TVPG_Settings::update( array( 'archive_swap' => true ) );
		$GLOBALS['product'] = new WC_Product();
		foreach ( array( 'legacy', 'invalid', 'blank', 'tiktok', 'instagram' ) as $scenario ) {
			$preview = 'https://example.com/preview.webm';
			$main = 'https://example.com/main.mp4';
			update_post_meta( 1, '_tvpg_archive_video_url', 'invalid' === $scenario ? 'invalid' : ( 'blank' === $scenario ? '' : $preview ) );
			$unsupported = array(
				'tiktok' => 'https://www.tiktok.com/@example/video/123456789',
				'instagram' => 'https://www.instagram.com/reel/ABC123/',
			);
			if ( isset( $unsupported[ $scenario ] ) ) {
				update_post_meta( 1, '_tvpg_archive_video_url', $unsupported[ $scenario ] );
			}
			update_post_meta( 1, '_tvpg_video_url', $main );
			$expected = $main;
			$html = '<img src="https://example.com/product.jpg">';
			$outputs = array(
				(new TVPG_Frontend())->filter_loop_product_thumbnail( $html, 1, 0, '', array() ),
				(new TVPG_Frontend())->filter_loop_wc_product_image( $html, $GLOBALS['product'], '', array(), true, $html ),
			);
			ob_start();
			(new TVPG_Frontend())->render_loop_media_payload();
			ob_end_clean();
			$outputs[] = $GLOBALS['tvpg_test_kses_input'];
			foreach ( $outputs as $output ) {
				$this->assertStringContainsString( 'data-src="' . $expected . '"', $output );
				$this->assertStringNotContainsString( ' src="' . $expected . '"', $output );
				$this->assertStringContainsString( 'preload="none"', $output );
			}
		}
	}
}
