<?php
/**
 * Tests for TVPG_Video_Parser.
 *
 * @package TVPG
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Test suite for video URL parsing.
 */
class TVPG_Video_Parser_Test extends TestCase {

	/**
	 * Data provider for valid YouTube URLs.
	 */
	public static function youtube_url_provider(): array {
		return array(
			'watch?v='         => array( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ' ),
			'short youtu.be'   => array( 'https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ' ),
			'embed/'           => array( 'https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ' ),
			'shorts/'          => array( 'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ' ),
		);
	}

	#[DataProvider( 'youtube_url_provider' )]
	public function test_get_youtube_id_valid( string $url, string $expected_id ): void {
		$info = TVPG_Video_Parser::get_video_info( $url );
		$this->assertIsArray( $info );
		$this->assertSame( 'youtube', $info['type'] );
		$this->assertSame( $expected_id, $info['id'] );
	}

	/**
	 * Data provider for valid Vimeo URLs.
	 */
	public static function vimeo_url_provider(): array {
		return array(
			'standard' => array( 'https://vimeo.com/123456789', '123456789' ),
			'channels' => array( 'https://vimeo.com/channels/staffpicks/123456789', '123456789' ),
		);
	}

	#[DataProvider( 'vimeo_url_provider' )]
	public function test_get_vimeo_id_valid( string $url, string $expected_id ): void {
		$info = TVPG_Video_Parser::get_video_info( $url );
		$this->assertIsArray( $info );
		$this->assertSame( 'vimeo', $info['type'] );
		$this->assertSame( $expected_id, $info['id'] );
	}

	public function test_empty_url_returns_false(): void {
		$this->assertFalse( TVPG_Video_Parser::get_video_info( '' ) );
	}

	public function test_unsupported_url_returns_false(): void {
		$this->assertFalse( TVPG_Video_Parser::get_video_info( 'https://example.com/video' ) );
	}

	public function test_provider_paths_on_other_domains_are_not_matched(): void {
		$this->assertFalse( TVPG_Video_Parser::get_video_info( 'https://example.com/embed/dQw4w9WgXcQ' ) );
		$this->assertFalse( TVPG_Video_Parser::get_video_info( 'https://example.com/watch?v=dQw4w9WgXcQ' ) );
		$this->assertFalse( TVPG_Video_Parser::get_video_info( 'https://example.com/video/123456789' ) );
	}

	public function test_direct_mp4_file(): void {
		$info = TVPG_Video_Parser::get_video_info( 'https://example.com/video.mp4' );
		$this->assertIsArray( $info );
		$this->assertSame( 'file', $info['type'] );
		$this->assertSame( 'https://example.com/video.mp4', $info['url'] );
	}

	public function test_tiktok_url(): void {
		$info = TVPG_Video_Parser::get_video_info( 'https://www.tiktok.com/@user/video/1234567890123456789' );
		$this->assertIsArray( $info );
		$this->assertSame( 'tiktok', $info['type'] );
	}

	public function test_instagram_url(): void {
		$info = TVPG_Video_Parser::get_video_info( 'https://www.instagram.com/reel/AbC123/' );
		$this->assertIsArray( $info );
		$this->assertSame( 'instagram', $info['type'] );
	}
}
