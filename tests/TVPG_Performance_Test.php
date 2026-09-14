<?php
/** Performance regressions; optional TVPG_WP_TEST_PATH enables real HTML API tests.
 * @package TVPG
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/performance-support.php';
require_once TVPG_PATH . 'includes/class-tvpg-frontend.php';

class TVPG_Performance_Test extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_test_options'] = array();
		$GLOBALS['_test_transients'] = array();
		$GLOBALS['tvpg_test_events'] = array();
		$GLOBALS['tvpg_test_scripts'] = (object) array( 'registered' => array() );
		$GLOBALS['tvpg_test_styles'] = (object) array( 'registered' => array() );
		$GLOBALS['post'] = null;
		$GLOBALS['product'] = null;
		TVPG_Settings::get_all( true );
	}

	private function invoke( $object, $method, ...$args ) {
		$reflection = new ReflectionMethod( $object, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( is_object( $object ) ? $object : null, ...$args );
	}

	private function load_html_api(): void {
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return;
		}
		$path = getenv( 'TVPG_WP_TEST_PATH' );
		if ( ! $path ) {
			$this->markTestSkipped( 'Set TVPG_WP_TEST_PATH to a WordPress checkout for real HTML API tests.' );
		}
		foreach ( array( 'attribute-token', 'span', 'text-replacement', 'decoder', 'tag-processor' ) as $class ) {
			require_once $path . '/wp-includes/html-api/class-wp-html-' . $class . '.php';
		}
	}

	public function test_archive_assets_are_independent_and_disabled_swap_loads_nothing(): void {
		$frontend = new TVPG_Frontend();
		$frontend->enqueue_scripts();
		$this->assertSame( array( 'tvpg-archive' ), array_keys( wp_scripts()->registered ) );
		$this->assertSame( array(), wp_scripts()->registered['tvpg-archive']->deps );
		$this->assertSame( array( 'tvpg-archive' ), array_keys( wp_styles()->registered ) );
		wp_scripts()->registered = array();
		wp_styles()->registered = array();
		TVPG_Settings::update( array( 'archive_swap' => false ) );
		$frontend->enqueue_scripts();
		$this->assertSame( array(), wp_scripts()->registered );
		$this->assertSame( array(), wp_styles()->registered );
	}

	public function test_later_gallery_upgrades_registered_dependencies_and_never_downgrades(): void {
		$frontend = new TVPG_Frontend();
		foreach ( array( false, true, false ) as $needs_slider ) {
			$this->invoke( $frontend, 'enqueue_gallery_assets', $needs_slider );
		}
		$this->assertSame( array( 'tvpg-swiper' ), wp_scripts()->registered['tvpg-frontend']->deps );
		$this->assertSame( array( 'tvpg-swiper' ), wp_styles()->registered['tvpg-frontend']->deps );
		$this->assertTrue( $GLOBALS['tvpg_test_localized']['tvpgParams']['needsSlider'] );
	}

	public function test_vimeo_miss_schedules_once_without_synchronous_negative_cache(): void {
		new TVPG_Frontend();
		$this->assertFalse( TVPG_Video_Parser::get_vimeo_thumbnail( '123' ) );
		$this->assertFalse( TVPG_Video_Parser::get_vimeo_thumbnail( '123' ) );
		$this->assertCount( 1, $GLOBALS['tvpg_test_events'] );
		// The HTTP stub fails: a synchronous request would create the negative cache.
		$this->assertFalse( get_transient( 'tvpg_vimeo_thumb_123' ) );
		$this->assertTrue( has_action( 'tvpg_refresh_vimeo_thumbnail', array( 'TVPG_Video_Parser', 'refresh_vimeo_thumbnail' ) ) );
	}

	public function test_vimeo_failure_keeps_stale_image_and_backs_off(): void {
		set_transient( 'tvpg_vimeo_thumb_123_stale', 'https://example.com/stale.jpg', DAY_IN_SECONDS );
		TVPG_Video_Parser::refresh_vimeo_thumbnail( '123' );
		$this->assertSame( 'none', get_transient( 'tvpg_vimeo_thumb_123' ) );
		$this->assertSame( 'https://example.com/stale.jpg', TVPG_Video_Parser::get_vimeo_thumbnail( '123' ) );
		$this->assertSame( array(), $GLOBALS['tvpg_test_events'] );
		$this->assertFalse( get_option( 'tvpg_vimeo_fetch_123' ) );
	}

	public function test_native_archive_video_has_no_eager_source_or_poster(): void {
		$html = $this->invoke( new TVPG_Frontend(), 'get_loop_video_markup', 'https://example.com/preview.mp4', new WC_Product() );
		$this->assertStringContainsString( ' data-src="https://example.com/preview.mp4"', $html );
		$this->assertStringContainsString( ' data-poster=', $html );
		$this->assertStringNotContainsString( ' src=', $html );
		$this->assertStringNotContainsString( ' poster=', $html );
	}

	public function test_archive_vimeo_allows_simultaneous_players(): void {
		$html = $this->invoke( new TVPG_Frontend(), 'get_loop_video_markup', 'https://vimeo.com/123', new WC_Product() );
		$this->assertStringContainsString( 'autopause=0', $html );
		$this->assertStringContainsString( ' data-src=', $html );
		$this->assertStringNotContainsString( ' src=', $html );
	}

	public function test_shortcode_rejects_unreadable_and_password_protected_products_before_enqueue(): void {
		$frontend = new TVPG_Frontend();
		foreach ( array( array( 'private', '' ), array( 'draft', '' ), array( 'publish', 'secret' ) ) as $case ) {
			$GLOBALS['tvpg_test_post'] = (object) array( 'post_type' => 'product', 'post_status' => $case[0], 'post_password' => $case[1] );
			$this->assertSame( '<!-- TVPG: Product unavailable -->', $frontend->render_shortcode( array( 'product_id' => 123 ) ) );
		}
		$this->assertSame( array(), wp_scripts()->registered );
	}

	public function test_placeholder_thumbnail_closes_its_slide(): void {
		ob_start();
		$this->invoke( TVPG_Gallery_Renderer::class, 'render_thumb_slider', array(
			array( 'type' => 'image', 'id' => 0, 'is_placeholder' => true ),
			array( 'type' => 'image', 'id' => 10 ),
		) );
		$html = ob_get_clean();
		$this->assertSame( substr_count( $html, '<div ' ), substr_count( $html, '</div>' ) );
	}

	public function test_secondary_image_sources_are_deferred_without_losing_responsive_metadata(): void {
		$this->load_html_api();
		$html = $this->invoke( TVPG_Frontend::class, 'defer_loop_images', '<img src="small.jpg" srcset="small.jpg 300w, large.jpg 600w" sizes="50vw" width="300" alt="Preview">' );
		foreach ( array( 'src', 'srcset', 'sizes' ) as $attribute ) {
			$this->assertStringContainsString( ' data-' . $attribute . '=', $html );
			$this->assertStringNotContainsString( ' ' . $attribute . '=', $html );
		}
		$this->assertStringContainsString( 'large.jpg 600w', $html );
		$this->assertStringContainsString( 'width="300"', $html );
	}

	public function test_inactive_native_video_does_not_autoplay_or_preload(): void {
		$this->load_html_api();
		TVPG_Settings::update( array( 'autoplay' => true, 'video_preload' => 'auto' ) );
		$html = TVPG_Video_Embed::get_video_html( 'https://example.com/preview.mp4', '', false );
		$this->assertStringNotContainsString( ' autoplay', $html );
		$this->assertStringContainsString( 'preload="none"', $html );
	}

	public function test_visible_facade_gets_priority_and_direct_vimeo_needs_no_thumbnail(): void {
		$this->load_html_api();
		$html = TVPG_Video_Embed::get_video_html( 'https://vimeo.com/123', 'https://example.com/poster.jpg', true );
		$this->assertStringContainsString( 'loading="eager"', $html );
		$this->assertStringContainsString( 'fetchpriority="high"', $html );
		TVPG_Settings::update( array( 'video_preload' => 'auto' ) );
		TVPG_Video_Embed::get_video_html( 'https://vimeo.com/123' );
		$this->assertSame( array(), $GLOBALS['tvpg_test_events'] );
	}

	public function test_allowlist_preserves_responsive_and_deferred_attributes(): void {
		$allowed = TVPG_Video_Embed::get_allowed_html();
		foreach ( array( 'srcset', 'sizes', 'data-src', 'data-srcset', 'data-sizes' ) as $attribute ) {
			$this->assertArrayHasKey( $attribute, $allowed['img'] );
		}
		$this->assertArrayHasKey( 'data-src', $allowed['video'] );
		$this->assertArrayHasKey( 'data-poster', $allowed['video'] );
	}
}
