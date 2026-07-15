<?php
/**
 * Tests for TVPG_Schema.
 *
 * @package TVPG
 */

use PHPUnit\Framework\TestCase;

/**
 * Test suite for Schema.org VideoObject generation.
 */
class TVPG_Schema_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$ref = new ReflectionClass( TVPG_Schema::class );
		$prop = $ref->getProperty( 'pending' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );

		global $_test_actions;
		$_test_actions = array();
	}

	public function test_enqueue_collects_pending(): void {
		$product = new WC_Product();
		TVPG_Schema::enqueue( $product, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );

		$ref = new ReflectionClass( TVPG_Schema::class );
		$prop = $ref->getProperty( 'pending' );
		$prop->setAccessible( true );
		$pending = $prop->getValue();

		$this->assertCount( 1, $pending );
		$this->assertSame( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', $pending[0]['video_url'] );
	}

	public function test_enqueue_returns_early_for_empty_url(): void {
		$product = new WC_Product();
		TVPG_Schema::enqueue( $product, '' );

		$ref = new ReflectionClass( TVPG_Schema::class );
		$prop = $ref->getProperty( 'pending' );
		$prop->setAccessible( true );
		$pending = $prop->getValue();

		$this->assertCount( 0, $pending );
	}

	public function test_output_returns_early_for_invalid_url(): void {
		$product = new WC_Product();
		ob_start();
		TVPG_Schema::output( $product, 'not-a-url' );
		$html = ob_get_clean();
		$this->assertEmpty( $html );
	}

	public function test_hosted_video_schema_uses_embed_url_without_content_url(): void {
		$product = new WC_Product();
		ob_start();
		TVPG_Schema::output( $product, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );
		$html = ob_get_clean();

		$this->assertStringContainsString( '"embedUrl":"https://www.youtube.com/embed/dQw4w9WgXcQ"', $html );
		$this->assertStringNotContainsString( '"contentUrl"', $html );
	}

	public function test_file_video_schema_has_content_and_embed_urls(): void {
		$product = new WC_Product();
		ob_start();
		TVPG_Schema::output( $product, 'https://example.com/video.mp4' );
		$html = ob_get_clean();

		$this->assertStringContainsString( '"embedUrl":"https://example.com/video.mp4"', $html );
		$this->assertStringContainsString( '"contentUrl":"https://example.com/video.mp4"', $html );
	}
}
