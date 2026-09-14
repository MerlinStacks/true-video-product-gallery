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
	 * Sanitize an optional preview to providers playable by the archive renderer.
	 *
	 * @param mixed $url Submitted URL.
	 * @return string Valid video URL, or an empty string.
	 */
	public static function sanitize_archive_video_url( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$url  = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		$info = self::get_video_info( $url );
		return $info && in_array( $info['type'], array( 'file', 'youtube', 'vimeo' ), true ) ? $url : '';
	}

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
		$host = self::get_normalized_host( $url );
		if ( 'tiktok.com' !== $host && ! str_ends_with( $host, '.tiktok.com' ) ) {
			return false;
		}

		// TikTok short URLs are redirect tokens, not embeddable video IDs.
		if ( 'vm.tiktok.com' === $host || 'vt.tiktok.com' === $host ) {
			return false;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#^/@[^/]+/video/(\d+)(?:/|$)#', $path, $matches ) ) {
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
		$host = self::get_normalized_host( $url );
		if ( 'instagram.com' !== $host && ! str_ends_with( $host, '.instagram.com' ) ) {
			return false;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#^/(?:reel|p)/([a-zA-Z0-9_-]+)(?:/|$)#', $path, $matches ) ) {
			return $matches[1];
		}
		return false;
	}

	/**
	 * Read a Vimeo thumbnail without blocking rendering on the provider.
	 *
	 * Results are cached using WordPress transients for 24 hours.
	 *
	 * @since 1.2.0
	 * @param string $video_id The Vimeo video ID.
	 * @return string|false Thumbnail URL, or false on failure.
	 */
	public static function get_vimeo_thumbnail( $video_id ) {
		if ( ! preg_match( '/^\d+$/', (string) $video_id ) ) {
			return false;
		}

		$cache_key = 'tvpg_vimeo_thumb_' . $video_id;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return 'none' === $cached ? get_transient( $cache_key . '_stale' ) : $cached;
		}

		$args = array( (string) $video_id );
		if ( ! wp_next_scheduled( 'tvpg_refresh_vimeo_thumbnail', $args ) && self::acquire_thumbnail_lock( $video_id, 'schedule' ) ) {
			$result = wp_schedule_single_event( time() + 1, 'tvpg_refresh_vimeo_thumbnail', $args, true );
			if ( is_wp_error( $result ) || ! $result ) {
				delete_option( 'tvpg_vimeo_schedule_' . $video_id );
			}
		}

		return get_transient( $cache_key . '_stale' );
	}

	/**
	 * Acquire an atomic, expiring lock, including with persistent object caches.
	 *
	 * @param string $video_id Vimeo ID.
	 * @param string $purpose Lock purpose.
	 * @return bool
	 */
	private static function acquire_thumbnail_lock( $video_id, $purpose ) {
		$key     = 'tvpg_vimeo_' . $purpose . '_' . $video_id;
		$expires = get_option( $key );
		if ( $expires && (int) $expires < time() ) {
			delete_option( $key );
		}
		return add_option( $key, time() + 5 * MINUTE_IN_SECONDS, '', false );
	}

	/**
	 * Refresh thumbnails in WP-Cron; keep stale images during provider outages.
	 *
	 * @param string $video_id Vimeo ID.
	 * @return void
	 */
	public static function refresh_vimeo_thumbnail( $video_id ) {
		if ( ! preg_match( '/^\d+$/', (string) $video_id ) || ! self::acquire_thumbnail_lock( $video_id, 'fetch' ) ) {
			return;
		}
		$cache_key = 'tvpg_vimeo_thumb_' . $video_id;
		try {
			if ( false !== get_transient( $cache_key ) ) {
				return;
			}
			$oembed_url = 'https://vimeo.com/api/oembed.json?url=' . rawurlencode( 'https://vimeo.com/' . $video_id );
			$response   = wp_remote_get(
				$oembed_url,
				array(
					'timeout'             => 5,
					'limit_response_size' => 65536,
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				set_transient( $cache_key, 'none', HOUR_IN_SECONDS );
				return;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! empty( $data['thumbnail_url'] ) && is_string( $data['thumbnail_url'] ) ) {
				$thumbnail = esc_url_raw( $data['thumbnail_url'], array( 'http', 'https' ) );
				if ( $thumbnail ) {
					set_transient( $cache_key, $thumbnail, DAY_IN_SECONDS );
					set_transient( $cache_key . '_stale', $thumbnail, 30 * DAY_IN_SECONDS );
					return;
				}
			}

			// Back off for an hour without discarding the last usable thumbnail.
			set_transient( $cache_key, 'none', HOUR_IN_SECONDS );
		} finally {
			delete_option( 'tvpg_vimeo_fetch_' . $video_id );
			delete_option( 'tvpg_vimeo_schedule_' . $video_id );
		}
	}
}
