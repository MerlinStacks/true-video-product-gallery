<?php
/**
 * Video URL parser for True Video Product Gallery.
 *
 * @package TVPG
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TVPG_Video_Parser
 *
 * Parses video URLs from various providers (YouTube, Vimeo, TikTok, Instagram, direct files)
 * and extracts relevant information for embedding.
 *
 * @since 1.0.0
 * @since 1.2.0 Added TikTok and Instagram Reel support.
 */
class TVPG_Video_Parser {

	/**
	 * Get video information from URL.
	 *
	 * Parses a video URL and returns an array containing the video type
	 * and relevant identifiers for embedding.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added TikTok and Instagram support.
	 * @param string $url The video URL to parse.
	 * @return array|false Video info array with 'type' and 'id'/'url', or false if not recognized.
	 */
	public static function get_video_info( $url ) {
		if ( empty( $url ) ) {
			return false;
		}

		// YouTube.
		$youtube_id = self::get_youtube_id( $url );
		if ( $youtube_id ) {
			return array(
				'type' => 'youtube',
				'id'   => $youtube_id,
			);
		}

		// Vimeo.
		$vimeo_id = self::get_vimeo_id( $url );
		if ( $vimeo_id ) {
			return array(
				'type' => 'vimeo',
				'id'   => $vimeo_id,
			);
		}

		// TikTok.
		$tiktok_id = self::get_tiktok_id( $url );
		if ( $tiktok_id ) {
			return array(
				'type' => 'tiktok',
				'id'   => $tiktok_id,
				'url'  => $url,
			);
		}

		// Instagram Reel.
		$instagram_id = self::get_instagram_id( $url );
		if ( $instagram_id ) {
			return array(
				'type' => 'instagram',
				'id'   => $instagram_id,
				'url'  => $url,
			);
		}

		// Direct file (mp4, webm, etc).
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$ext  = $path ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';
		if ( in_array( $ext, array( 'mp4', 'webm', 'ogg' ), true ) ) {
			return array(
				'type' => 'file',
				'url'  => $url,
			);
		}

		return false;
	}

	/**
	 * Parse YouTube ID from various URL formats.
	 *
	 * Handles: standard, short (youtu.be), embed, shorts, and timestamped URLs.
	 *
	 * @since 1.0.0
	 * @param string $url The YouTube URL to parse.
	 * @return string|false The video ID, or false if not found.
	 */
	private static function get_youtube_id( $url ) {
		$host = self::get_normalized_host( $url );
		if ( ! self::is_youtube_host( $host ) ) {
			return false;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( 'youtu.be' === $host ) {
			$id = trim( $path, '/' );
			return self::is_valid_provider_id( $id ) ? $id : false;
		}

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( $query ) {
			$params = array();
			wp_parse_str( $query, $params );
			foreach ( array( 'v', 'vi' ) as $key ) {
				if ( ! empty( $params[ $key ] ) && self::is_valid_provider_id( $params[ $key ] ) ) {
					return $params[ $key ];
				}
			}
		}

		if ( preg_match( '#/(?:embed|shorts|v|vi)/([a-zA-Z0-9_-]+)#', $path, $matches ) ) {
			return self::is_valid_provider_id( $matches[1] ) ? $matches[1] : false;
		}

		if ( preg_match( '#/u/\w/([a-zA-Z0-9_-]+)#', $path, $matches ) ) {
			return self::is_valid_provider_id( $matches[1] ) ? $matches[1] : false;
		}

		return false;
	}

	/**
	 * Parse Vimeo ID from URL.
	 *
	 * Handles: vimeo.com/ID, channels, groups, and album formats.
	 *
	 * @since 1.0.0
	 * @param string $url The Vimeo URL to parse.
	 * @return string|false The video ID, or false if not found.
	 */
	private static function get_vimeo_id( $url ) {
		$host = self::get_normalized_host( $url );
		if ( ! self::is_vimeo_host( $host ) ) {
			return false;
		}

		if ( preg_match( '/vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/(?:[^\/]*)\/videos\/|album\/(?:\d+)\/video\/|video\/|)(\d+)(?:$|\/|\?)/', $url, $matches ) ) {
			return $matches[1];
		}
		return false;
	}

	/**
	 * Normalize a URL host for provider matching.
	 *
	 * @param string $url The URL to inspect.
	 * @return string Normalized host.
	 */
	private static function get_normalized_host( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Determine if a host is a YouTube host.
	 *
	 * @param string $host Normalized host.
	 * @return bool
	 */
	private static function is_youtube_host( $host ) {
		return 'youtu.be' === $host
			|| 'youtube.com' === $host
			|| str_ends_with( $host, '.youtube.com' )
			|| 'youtube-nocookie.com' === $host
			|| str_ends_with( $host, '.youtube-nocookie.com' );
	}

	/**
	 * Determine if a host is a Vimeo host.
	 *
	 * @param string $host Normalized host.
	 * @return bool
	 */
	private static function is_vimeo_host( $host ) {
		return 'vimeo.com' === $host || str_ends_with( $host, '.vimeo.com' );
	}

	/**
	 * Validate an extracted provider ID.
	 *
	 * @param string $id Provider video ID.
	 * @return bool
	 */
	private static function is_valid_provider_id( $id ) {
		return is_string( $id ) && '' !== $id && (bool) preg_match( '/^[a-zA-Z0-9_-]+$/', $id );
	}

	/**
	 * Parse TikTok video ID from URL.
	 *
	 * Handles: tiktok.com/@user/video/ID formats.
	 *
	 * @since 1.2.0
	 * @param string $url The TikTok URL to parse.
	 * @return string|false The video ID, or false if not found.
	 */
	private static function get_tiktok_id( $url ) {
		// Match: tiktok.com/@username/video/1234567890123456789.
		if ( preg_match( '/tiktok\.com\/@[^\/]+\/video\/(\d+)/', $url, $matches ) ) {
			return $matches[1];
		}
		// Match: vm.tiktok.com/ABC123 (short URL - return full URL as ID).
		if ( preg_match( '/vm\.tiktok\.com\/([a-zA-Z0-9]+)/', $url, $matches ) ) {
			return $matches[1];
		}
		return false;
	}

	/**
	 * Parse Instagram Reel/Post ID from URL.
	 *
	 * Handles: instagram.com/reel/ID and instagram.com/p/ID formats.
	 *
	 * @since 1.2.0
	 * @param string $url The Instagram URL to parse.
	 * @return string|false The post ID, or false if not found.
	 */
	private static function get_instagram_id( $url ) {
		// Match: instagram.com/reel/ABC123 or instagram.com/p/ABC123.
		if ( preg_match( '/instagram\.com\/(?:reel|p)\/([a-zA-Z0-9_-]+)/', $url, $matches ) ) {
			return $matches[1];
		}
		return false;
	}

	/**
	 * Fetch Vimeo thumbnail via oEmbed API.
	 *
	 * Results are cached using WordPress transients for 24 hours.
	 *
	 * @since 1.2.0
	 * @param string $video_id The Vimeo video ID.
	 * @return string|false Thumbnail URL, or false on failure.
	 */
	public static function get_vimeo_thumbnail( $video_id ) {
		if ( empty( $video_id ) ) {
			return false;
		}

		$cache_key = 'tvpg_vimeo_thumb_' . $video_id;
		$cached    = get_transient( $cache_key );

		// IMP-14: Return false immediately for negative cache sentinel.
		if ( 'none' === $cached ) {
			return false;
		}

		if ( false !== $cached ) {
			return $cached;
		}

		$oembed_url = 'https://vimeo.com/api/oembed.json?url=' . rawurlencode( 'https://vimeo.com/' . $video_id );
		$response   = wp_remote_get( $oembed_url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			// IMP-14: Cache failure for 1 hour to prevent repeated outbound requests.
			set_transient( $cache_key, 'none', HOUR_IN_SECONDS );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! empty( $data['thumbnail_url'] ) ) {
			set_transient( $cache_key, $data['thumbnail_url'], DAY_IN_SECONDS );
			return $data['thumbnail_url'];
		}

		// IMP-14: Cache failure for 1 hour.
		set_transient( $cache_key, 'none', HOUR_IN_SECONDS );
		return false;
	}
}
