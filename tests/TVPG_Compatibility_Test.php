<?php
/**
 * Compatibility metadata tests.
 *
 * @package TVPG
 */

use PHPUnit\Framework\TestCase;

/**
 * Test suite for release compatibility declarations.
 */
class TVPG_Compatibility_Test extends TestCase {

	public function test_template_declares_current_woocommerce_version(): void {
		$template = file_get_contents( TVPG_PATH . 'templates/single-product/product-image.php' );

		$this->assertIsString( $template );
		$this->assertStringContainsString( '@version 11.1.0', $template );
	}

	public function test_iframe_allowlist_preserves_referrer_policy(): void {
		$allowed_html = TVPG_Video_Embed::get_allowed_html();

		$this->assertArrayHasKey( 'referrerpolicy', $allowed_html['iframe'] );
	}

	public function test_renderer_preserves_native_woocommerce_video_order(): void {
		$product = new class() {
			public function get_id(): int {
				return 123;
			}
		};
		$media_items = array(
			array(
				'media_type'  => 'image',
				'source_type' => 'attachment',
				'id'          => 10,
			),
			array(
				'media_type'  => 'video',
				'source_type' => 'attachment',
				'id'          => 20,
				'poster_id'   => 30,
			),
			array(
				'media_type'  => 'image',
				'source_type' => 'attachment',
				'id'          => 11,
			),
		);

		$method = new ReflectionMethod( TVPG_Gallery_Renderer::class, 'assemble_slides' );
		$method->setAccessible( true );
		$slides = $method->invoke( null, 10, array( 11 ), '', 'second', $product, $media_items );

		$this->assertSame( array( 'image', 'video', 'image' ), array_column( $slides, 'type' ) );
		$this->assertSame( 30, $slides[1]['thumb_id'] );
	}
}
