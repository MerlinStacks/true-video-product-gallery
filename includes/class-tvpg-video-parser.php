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
        $pattern = '/^.*(?:(?:youtu\.be\/|v\/|vi\/|u\/\w\/|embed\/|shorts\/)|(?:(?:watch)?\?v(?:i)?=|&v(?:i)?=))([^#&?"\'>]*).*/';
        preg_match( $pattern, $url, $matches );
        
        if ( isset( $matches[1] ) && ! empty( $matches[1] ) && preg_match( '/^[a-zA-Z0-9_\-]+$/', $matches[1] ) ) {
            return $matches[1];
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
        if ( preg_match( '/vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/(?:[^\/]*)\/videos\/|album\/(?:\d+)\/video\/|video\/|)(\d+)(?:$|\/|\?)/', $url, $matches ) ) {
            return $matches[1];
        }
        return false;
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
        // Match: tiktok.com/@username/video/1234567890123456789
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
        // Match: instagram.com/reel/ABC123 or instagram.com/p/ABC123
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

        if ( false !== $cached ) {
            return $cached;
        }

        $oembed_url = 'https://vimeo.com/api/oembed.json?url=' . rawurlencode( 'https://vimeo.com/' . $video_id );
        $response   = wp_remote_get( $oembed_url, array( 'timeout' => 5 ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! empty( $data['thumbnail_url'] ) ) {
            // Cache for 24 hours.
            set_transient( $cache_key, $data['thumbnail_url'], DAY_IN_SECONDS );
            return $data['thumbnail_url'];
        }

        return false;
    }
}

