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
		$prop->setValue( array() );

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

	public function test_enqueue_returns_early_for_empty_url_again(): void {
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
}
