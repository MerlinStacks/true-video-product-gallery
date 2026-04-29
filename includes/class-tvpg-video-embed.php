<?php
/**
 * Video embed HTML generation for True Video Product Gallery.
 *
 * Handles rendering of video players (YouTube, Vimeo, TikTok, Instagram, files)
 * with lazy loading facades and thumbnail generation.
 *
 * @package TVPG
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TVPG_Video_Embed
 *
 * Generates HTML markup for video embeds across all supported providers.
 * Supports lazy facades for YouTube/Vimeo to improve Core Web Vitals.
 *
 * @since 1.3.0
 */
class TVPG_Video_Embed {



	/**
	 * Generate video HTML with lazy loading facade for YouTube/Vimeo.
	 *
	 * For YouTube and Vimeo, renders a thumbnail with play button overlay.
	 * The actual iframe is loaded only when the user clicks, improving
	 * Core Web Vitals (LCP, TBT) significantly.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added lazy facade support.
	 * @since 1.3.0 Moved from TVPG_Frontend to dedicated class.
	 * @param string $url    The video URL.
	 * @param string $poster Optional custom poster image URL.
	 * @return string HTML markup for the video.
	 */
	public static function get_video_html( $url, $poster = '' ) {
		$settings = TVPG_Settings::get_all();

		$info = TVPG_Video_Parser::get_video_info( $url );
		if ( ! $info ) {
			return '';
		}

		$type         = $info['type'];
		$sizing_class = 'tvpg-video-' . ( isset( $settings['video_sizing'] ) ? $settings['video_sizing'] : 'contain' );
		$aria_label   = esc_attr__( 'Product Video', 'true-video-product-gallery' );
		$preload_mode = isset( $settings['video_preload'] ) ? $settings['video_preload'] : 'lazy';

		switch ( $type ) {
			case 'youtube':
				return self::get_youtube_html( $info, $settings, $sizing_class, $aria_label, $preload_mode, $poster );

			case 'vimeo':
				return self::get_vimeo_html( $info, $settings, $sizing_class, $aria_label, $preload_mode, $poster );

			case 'tiktok':
				return self::get_tiktok_html( $info, $sizing_class );

			case 'instagram':
				return self::get_instagram_html( $info, $sizing_class );

			case 'file':
				return self::get_file_html( $info, $settings, $sizing_class, $aria_label, $preload_mode, $poster );

			default:
				return '';
		}
	}

	/**
	 * Generate YouTube embed HTML.
	 *
	 * @param array  $info         Parsed video info.
	 * @param array  $settings     Plugin settings.
	 * @param string $sizing_class CSS class for sizing.
	 * @param string $aria_label   Accessible label.
	 * @param string $preload_mode Preload strategy.
	 * @param string $poster       Custom poster URL.
	 * @return string HTML markup.
	 */
	private static function get_youtube_html( $info, $settings, $sizing_class, $aria_label, $preload_mode, $poster ) {
		$id           = $info['id'];
		$use_autoplay = ( 'lazy' === $preload_mode ) ? 1 : ( $settings['autoplay'] ? 1 : 0 );
		$params       = array(
			'enablejsapi' => 1,
			'rel'         => 0,
			'autoplay'    => $use_autoplay,
			'controls'    => $settings['show_controls'] ? 1 : 0,
			'loop'        => $settings['loop'] ? 1 : 0,
			'playlist'    => $settings['loop'] ? $id : '',
		);
		if ( $use_autoplay && $settings['mute_autoplay'] ) {
			$params['mute'] = 1;
		}
		$query     = http_build_query( $params );
		$embed_url = 'https://www.youtube.com/embed/' . esc_attr( $id ) . '?' . $query;
		$thumb_url = $poster ? $poster : 'https://img.youtube.com/vi/' . esc_attr( $id ) . '/maxresdefault.jpg';

		if ( 'lazy' === $preload_mode ) {
			return self::get_lazy_facade_html( $embed_url, $thumb_url, $sizing_class, $aria_label, 'youtube' );
		}

		return '<div class="tvpg-responsive-video ' . esc_attr( $sizing_class ) . '"><iframe src="' . esc_url( $embed_url ) . '" style="border:none;" allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" loading="lazy" fetchpriority="low" title="' . $aria_label . '"></iframe></div>';
	}

	/**
	 * Generate Vimeo embed HTML.
	 *
	 * @param array  $info         Parsed video info.
	 * @param array  $settings     Plugin settings.
	 * @param string $sizing_class CSS class for sizing.
	 * @param string $aria_label   Accessible label.
	 * @param string $preload_mode Preload strategy.
	 * @param string $poster       Custom poster URL.
	 * @return string HTML markup.
	 */
	private static function get_vimeo_html( $info, $settings, $sizing_class, $aria_label, $preload_mode, $poster ) {
		$id           = $info['id'];
		$use_autoplay = ( 'lazy' === $preload_mode ) ? 1 : ( $settings['autoplay'] ? 1 : 0 );
		$params       = array(
			'autoplay'  => $use_autoplay,
			'loop'      => $settings['loop'] ? 1 : 0,
			'controls'  => $settings['show_controls'] ? 1 : 0,
		);
		if ( $use_autoplay && $settings['mute_autoplay'] ) {
			$params['muted'] = 1;
		}
		$query     = http_build_query( $params );
		$embed_url = 'https://player.vimeo.com/video/' . esc_attr( $id ) . '?' . $query;
		$thumb_url = $poster ? $poster : TVPG_Video_Parser::get_vimeo_thumbnail( $id );

		if ( 'lazy' === $preload_mode ) {
			return self::get_lazy_facade_html( $embed_url, $thumb_url, $sizing_class, $aria_label, 'vimeo' );
		}

		return '<div class="tvpg-responsive-video ' . esc_attr( $sizing_class ) . '"><iframe src="' . esc_url( $embed_url ) . '" style="border:none;" allowfullscreen loading="lazy" fetchpriority="low" title="' . $aria_label . '"></iframe></div>';
	}

	/**
	 * Generate TikTok facade HTML for click-to-load embedding.
	 *
	 * The embed script is deferred until user interaction to avoid
	 * third-party network cost on page load.
	 *
	 * @since 1.5.0 Converted to lazy facade (was inline script).
	 * @param array  $info         Parsed video info.
	 * @param string $sizing_class CSS class for sizing.
	 * @return string HTML markup.
	 */
	private static function get_tiktok_html( $info, $sizing_class ) {
		$video_url  = esc_url( $info['url'] );
		$video_id   = esc_attr( $info['id'] );
		$play_label = esc_attr__( 'Play TikTok Video', 'true-video-product-gallery' );

		$html  = '<div class="tvpg-responsive-video tvpg-social-facade ' . esc_attr( $sizing_class ) . '" data-embed-type="tiktok" data-video-id="' . $video_id . '" data-video-url="' . $video_url . '">';
		$html .= '<div class="tvpg-social-placeholder tvpg-tiktok-placeholder"></div>';
		$html .= '<button type="button" class="tvpg-play-button" aria-label="' . $play_label . '">';
		$html .= '<svg viewBox="0 0 68 48" width="68" height="48"><path class="tvpg-play-bg" d="M66.52,7.74c-0.78-2.93-2.49-5.41-5.42-6.19C55.79,.13,34,0,34,0S12.21,.13,6.9,1.55 C3.97,2.33,2.27,4.81,1.48,7.74C0.06,13.05,0,24,0,24s0.06,10.95,1.48,16.26c0.78,2.93,2.49,5.41,5.42,6.19 C12.21,47.87,34,48,34,48s21.79-0.13,27.1-1.55c2.93-0.78,4.64-3.26,5.42-6.19C67.94,34.95,68,24,68,24S67.94,13.05,66.52,7.74z" fill="#010101" fill-opacity="0.8"></path><path d="M 45,24 27,14 27,34" fill="#fff"></path></svg>';
		$html .= '</button>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Generate Instagram facade HTML for click-to-load embedding.
	 *
	 * The embed script is deferred until user interaction to avoid
	 * third-party network cost on page load.
	 *
	 * @since 1.5.0 Converted to lazy facade (was inline script).
	 * @param array  $info         Parsed video info.
	 * @param string $sizing_class CSS class for sizing.
	 * @return string HTML markup.
	 */
	private static function get_instagram_html( $info, $sizing_class ) {
		$instagram_url = 'https://www.instagram.com/p/' . esc_attr( $info['id'] ) . '/';
		$play_label    = esc_attr__( 'Play Instagram Video', 'true-video-product-gallery' );

		$html  = '<div class="tvpg-responsive-video tvpg-social-facade ' . esc_attr( $sizing_class ) . '" data-embed-type="instagram" data-video-id="' . esc_attr( $info['id'] ) . '" data-video-url="' . esc_url( $instagram_url ) . '">';
		$html .= '<div class="tvpg-social-placeholder tvpg-instagram-placeholder"></div>';
		$html .= '<button type="button" class="tvpg-play-button" aria-label="' . $play_label . '">';
		$html .= '<svg viewBox="0 0 68 48" width="68" height="48"><path class="tvpg-play-bg" d="M66.52,7.74c-0.78-2.93-2.49-5.41-5.42-6.19C55.79,.13,34,0,34,0S12.21,.13,6.9,1.55 C3.97,2.33,2.27,4.81,1.48,7.74C0.06,13.05,0,24,0,24s0.06,10.95,1.48,16.26c0.78,2.93,2.49,5.41,5.42,6.19 C12.21,47.87,34,48,34,48s21.79-0.13,27.1-1.55c2.93-0.78,4.64-3.26,5.42-6.19C67.94,34.95,68,24,68,24S67.94,13.05,66.52,7.74z" fill="#C13584" fill-opacity="0.8"></path><path d="M 45,24 27,14 27,34" fill="#fff"></path></svg>';
		$html .= '</button>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Generate self-hosted video HTML.
	 *
	 * @param array  $info         Parsed video info.
	 * @param array  $settings     Plugin settings.
	 * @param string $sizing_class CSS class for sizing.
	 * @param string $aria_label   Accessible label.
	 * @param string $preload_mode Preload strategy.
	 * @param string $poster       Custom poster URL.
	 * @return string HTML markup.
	 */
	private static function get_file_html( $info, $settings, $sizing_class, $aria_label, $preload_mode, $poster ) {
		$controls   = $settings['show_controls'] ? 'controls' : '';
		$loop       = $settings['loop'] ? 'loop' : '';
		$autoplay   = $settings['autoplay'] ? 'autoplay' : '';
		$muted      = ( $settings['autoplay'] && $settings['mute_autoplay'] ) ? 'muted' : '';
		$object_fit = $settings['video_sizing'] === 'cover' ? 'cover' : 'contain';

		// IMP-11 fix: honour the user's preload setting including 'auto'.
		if ( ! $settings['autoplay'] && 'auto' !== $preload_mode ) {
			$preload_val = 'none';
		} elseif ( 'auto' === $preload_mode ) {
			$preload_val = 'auto';
		} else {
			$preload_val = 'metadata';
		}

		// Fall back to the product's featured image when no custom poster is set.
		if ( empty( $poster ) ) {
			global $product;
			if ( $product && is_a( $product, 'WC_Product' ) && $product->get_image_id() ) {
				$poster_src = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_single' );
				if ( $poster_src ) {
					$poster = $poster_src;
				}
			}
		}
		$poster_attr = $poster ? 'poster="' . esc_url( $poster ) . '"' : '';

		return '<video ' . $controls . ' ' . $loop . ' ' . $autoplay . ' ' . $muted . ' playsinline ' . $poster_attr . ' preload="' . esc_attr( $preload_val ) . '" fetchpriority="low" src="' . esc_url( $info['url'] ) . '" style="width:100%;height:100%;object-fit:' . esc_attr( $object_fit ) . '" aria-label="' . $aria_label . '"></video>';
	}

	/**
	 * Generate a lazy loading facade (thumbnail + play button).
	 *
	 * The actual iframe is injected via JavaScript when the user clicks.
	 * This defers loading of third-party scripts until user interaction.
	 *
	 * @since 1.2.0
	 * @param string $embed_url   The full embed URL with parameters.
	 * @param string $thumb_url   URL to the thumbnail image.
	 * @param string $sizing_class CSS class for video sizing.
	 * @param string $aria_label  Accessible label for the video.
	 * @param string $provider    Video provider identifier (youtube/vimeo).
	 * @return string HTML markup for the lazy facade.
	 */
	public static function get_lazy_facade_html( $embed_url, $thumb_url, $sizing_class, $aria_label, $provider ) {
		$play_label = esc_attr__( 'Play Video', 'true-video-product-gallery' );

		$html  = '<div class="tvpg-responsive-video tvpg-lazy-facade ' . esc_attr( $sizing_class ) . '" data-embed-url="' . esc_url( $embed_url ) . '" data-provider="' . esc_attr( $provider ) . '">';

		if ( $thumb_url ) {
			// PSI-05: Explicit dimensions give the browser an aspect-ratio hint before CSS loads, preventing CLS.
			$html .= '<img src="' . esc_url( $thumb_url ) . '" alt="' . $aria_label . '" class="tvpg-facade-thumb" width="960" height="540" loading="lazy" decoding="async">';
		} else {
			$html .= '<div class="tvpg-facade-placeholder"></div>';
		}

		$html .= '<button type="button" class="tvpg-play-button" aria-label="' . $play_label . '">';
		$html .= '<svg viewBox="0 0 68 48" width="68" height="48"><path class="tvpg-play-bg" d="M66.52,7.74c-0.78-2.93-2.49-5.41-5.42-6.19C55.79,.13,34,0,34,0S12.21,.13,6.9,1.55 C3.97,2.33,2.27,4.81,1.48,7.74C0.06,13.05,0,24,0,24s0.06,10.95,1.48,16.26c0.78,2.93,2.49,5.41,5.42,6.19 C12.21,47.87,34,48,34,48s21.79-0.13,27.1-1.55c2.93-0.78,4.64-3.26,5.42-6.19C67.94,34.95,68,24,68,24S67.94,13.05,66.52,7.74z" fill="#212121" fill-opacity="0.8"></path><path d="M 45,24 27,14 27,34" fill="#fff"></path></svg>';
		$html .= '</button>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Generate a video thumbnail HTML for the thumbnail slider.
	 *
	 * Renders a small auto-playing, muted, looping version of the video
	 * suitable for use in thumbnail strips.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Moved from TVPG_Frontend.
	 * @param string $url The video URL.
	 * @return string HTML markup for thumbnail video.
	 */
	public static function get_video_thumb_html( $url ) {
		$info = TVPG_Video_Parser::get_video_info( $url );
		if ( ! $info ) {
			return '';
		}

		$type       = $info['type'];
		$aria_label = esc_attr__( 'Video Thumbnail', 'true-video-product-gallery' );

		// PSI-03: Static images replace live iframes for YouTube/Vimeo thumbs.
		if ( 'youtube' === $type ) {
			$id        = esc_attr( $info['id'] );
			$thumb_src = 'https://img.youtube.com/vi/' . $id . '/hqdefault.jpg';
			return '<img src="' . esc_url( $thumb_src ) . '" alt="' . $aria_label . '" style="width:100%;height:100%;object-fit:cover;" loading="lazy" decoding="async" tabindex="-1" aria-hidden="true">' . self::get_thumb_play_icon();
		}

		if ( 'vimeo' === $type ) {
			$thumb_src = TVPG_Video_Parser::get_vimeo_thumbnail( $info['id'] );
			if ( $thumb_src ) {
				return '<img src="' . esc_url( $thumb_src ) . '" alt="' . $aria_label . '" style="width:100%;height:100%;object-fit:cover;" loading="lazy" decoding="async" tabindex="-1" aria-hidden="true">' . self::get_thumb_play_icon();
			}
			// Fallback: no thumbnail available.
			return '<div class="tvpg-social-placeholder" style="width:100%;height:100%;"></div>' . self::get_thumb_play_icon();
		}

		// Inline autoplay video thumbnail — plays independently of the main
		// slider. Browsers cache the video URL so it won't download twice.
		if ( 'file' === $type ) {
			global $product;
			$poster_attr = '';
			if ( $product && is_a( $product, 'WC_Product' ) && $product->get_image_id() ) {
				$poster_src = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
				if ( $poster_src ) {
					$poster_attr = ' poster="' . esc_url( $poster_src ) . '"';
				}
			}
			return '<video class="tvpg-thumb-video" autoplay muted loop playsinline preload="auto"' . $poster_attr . ' src="' . esc_url( $info['url'] ) . '" style="width:100%;height:100%;object-fit:cover;" tabindex="-1" aria-hidden="true"></video>' . self::get_thumb_play_icon();
		}

		return '';
	}

	/**
	 * Generate a small play icon overlay for static video thumbnails.
	 *
	 * @since 1.5.0
	 * @return string SVG play icon HTML.
	 */
	private static function get_thumb_play_icon() {
		return '<span class="tvpg-thumb-play-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="#fff" style="filter:drop-shadow(0 1px 2px rgba(0,0,0,.5))"><path d="M8 5v14l11-7z"/></svg></span>';
	}

	/**
	 * Get the allowed HTML tags/attributes for wp_kses().
	 *
	 * Provides a comprehensive whitelist for all video embed markup
	 * generated by this class.
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Moved from TVPG_Frontend.
	 * @return array Allowed HTML array for wp_kses().
	 */
	public static function get_allowed_html() {
		return array(
			'div'        => array(
				'class'            => array(),
				'style'            => array(),
				'data-embed-url'   => array(),
				'data-provider'    => array(),
				'data-embed-type'  => array(),
				'data-video-id'    => array(),
				'data-video-url'   => array(),
				'aria-label'       => array(),
			),
			'img'        => array(
				'src'           => array(),
				'alt'           => array(),
				'class'         => array(),
				'width'         => array(),
				'height'        => array(),
				'loading'       => array(),
				'decoding'      => array(),
				'fetchpriority' => array(),
				'style'         => array(),
				'tabindex'      => array(),
				'aria-hidden'   => array(),
			),
			'button'     => array(
				'type'       => array(),
				'class'      => array(),
				'aria-label' => array(),
			),
			'svg'        => array(
				'viewBox' => array(),
				'width'   => array(),
				'height'  => array(),
				'class'   => array(),
				'fill'    => array(),
				'style'   => array(),
			),
			'path'       => array(
				'class'        => array(),
				'd'            => array(),
				'fill'         => array(),
				'fill-opacity' => array(),
			),
			'iframe'     => array(
				'src'             => array(),
				'width'           => array(),
				'height'          => array(),
				'allowfullscreen' => array(),
				'allow'           => array(),
				'style'           => array(),
				'title'           => array(),
				'class'           => array(),
				'loading'         => array(),
				'fetchpriority'   => array(),
				'tabindex'        => array(),
				'aria-hidden'     => array(),
			),
			'video'      => array(
				'src'           => array(),
				'poster'        => array(),
				'preload'       => array(),
				'fetchpriority' => array(),
				'width'         => array(),
				'height'        => array(),
				'style'         => array(),
				'controls'      => array(),
				'loop'          => array(),
				'muted'         => array(),
				'autoplay'      => array(),
				'playsinline'   => array(),
				'class'         => array(),
				'aria-label'    => array(),
				'aria-hidden'   => array(),
				'tabindex'      => array(),
			),
			'blockquote' => array(
				'class'                  => array(),
				'cite'                   => array(),
				'data-video-id'          => array(),
				'data-instgrm-permalink' => array(),
				'data-instgrm-version'   => array(),
				'style'                  => array(),
			),
			'section'    => array(),
			'span'       => array(
				'class' => array(),
			),
		);
	}
}
