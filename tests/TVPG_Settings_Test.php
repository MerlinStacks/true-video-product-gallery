<?php
/**
 * Tests for TVPG_Settings.
 *
 * @package TVPG
 */

use PHPUnit\Framework\TestCase;

/**
 * Test suite for centralized settings management.
 */
class TVPG_Settings_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Reset internal static state between tests.
		$ref = new ReflectionClass( TVPG_Settings::class );
		$prop = $ref->getProperty( 'settings' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		global $_test_options;
		$_test_options = array();
	}

	public function test_get_all_returns_defaults_when_empty(): void {
		$settings = TVPG_Settings::get_all();
		$this->assertFalse( $settings['autoplay'] );
		$this->assertFalse( $settings['gallery_autoscroll'] );
		$this->assertSame( 4, $settings['image_delay'] );
		$this->assertTrue( $settings['show_controls'] );
		$this->assertSame( 'contain', $settings['video_sizing'] );
		$this->assertSame( 'lazy', $settings['video_preload'] );
		$this->assertSame( 'slide', $settings['transition_effect'] );
	}

	public function test_get_single_setting(): void {
		$this->assertFalse( TVPG_Settings::get( 'autoplay' ) );
		$this->assertTrue( TVPG_Settings::get( 'mute_autoplay' ) );
		$this->assertSame( 'second', TVPG_Settings::get( 'video_position' ) );
	}

	public function test_update_persists_values(): void {
		TVPG_Settings::update( array( 'autoplay' => true ) );
		$settings = TVPG_Settings::get_all();
		$this->assertTrue( $settings['autoplay'] );
		$this->assertTrue( $settings['show_controls'] ); // unchanged default.
	}

	public function test_invalid_key_returns_null(): void {
		$this->assertNull( TVPG_Settings::get( 'nonexistent_key' ) );
	}

	public function test_get_valid_values(): void {
		$this->assertSame( array( 'contain', 'cover' ), TVPG_Settings::get_valid_values( 'video_sizing' ) );
		$this->assertSame( array( 'first', 'second', 'last' ), TVPG_Settings::get_valid_values( 'video_position' ) );
		$this->assertSame( array( 'lazy', 'metadata', 'auto' ), TVPG_Settings::get_valid_values( 'video_preload' ) );
		$this->assertSame( array( 'slide', 'fade' ), TVPG_Settings::get_valid_values( 'transition_effect' ) );
	}
}
