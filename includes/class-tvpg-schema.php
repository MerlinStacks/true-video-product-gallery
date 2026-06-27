<?php
/**
 * Schema.org VideoObject structured data for True Video Product Gallery.
 *
 * Outputs JSON-LD markup for SEO / rich snippets when products have videos.
 *
 * @package TVPG
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TVPG_Schema
 *
 * Generates Schema.org VideoObject JSON-LD for product video SEO.
 *
 * @since 1.3.0
 */
class TVPG_Schema {

	/**
	 * Pending schema outputs to be deferred to wp_footer.
	 *
	 * @var array
	 */
	private static $pending = array();

	/**
	 * Enqueue Schema.org VideoObject data to be output in the footer.
	 *
	 * Why defer: the inline <script> tag blocks the HTML parser. Moving it
	 * to wp_footer removes this render-blocking cost with zero SEO downside
	 * because search engines parse JSON-LD regardless of position.
	 *
	 * @since 1.6.0
	 * @param WC_Product $product   The product object.
	 * @param string     $video_url The video URL.
	 * @return void
	 */
	public static function enqueue( $product, $video_url ) {
		if ( empty( $video_url ) ) {
			return;
		}

		self::$pending[] = array(
			'product'   => $product,
			'video_url' => $video_url,
		);

		if ( ! has_action( 'wp_footer', array( __CLASS__, 'print_all' ) ) ) {
			add_action( 'wp_footer', array( __CLASS__, 'print_all' ), 1 );
		}
	}

	/**
	 * Output all pending Schema.org VideoObject markup.
	 *
	 * @since 1.6.0
	 * @return void
	 */
	public static function print_all() {
		foreach ( self::$pending as $item ) {
			self::output( $item['product'], $item['video_url'] );
		}
	}

	/**
	 * Output Schema.org VideoObject structured data immediately.
	 *
	 * Use this directly when footer output is not possible (e.g., shortcodes).
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Moved from TVPG_Frontend to dedicated class.
	 * @param WC_Product $product   The product object.
	 * @param string     $video_url The video URL.
	 * @return void
	 */
	public static function output( $product, $video_url ) {
		$video_info = TVPG_Video_Parser::get_video_info( $video_url );
		if ( ! $video_info ) {
			return;
		}

		$product_name = $product->get_name();
		$product_desc = $product->get_short_description();
		if ( ! $product_desc ) {
			$product_desc = $product->get_description();
		}
		$product_desc = wp_strip_all_tags( $product_desc );
		$upload_date  = get_the_date( 'c', $product->get_id() );

		$thumbnail_url = self::resolve_thumbnail( $product, $video_info );
		$embed_url     = self::resolve_embed_url( $video_info, $video_url );

		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'VideoObject',
			'name'        => sprintf(
				/* translators: %s: product name */
				__( '%s - Product Video', 'true-video-product-gallery' ),
				$product_name
			),
			'description' => $product_desc ? $product_desc : $product_name,
			'uploadDate'  => $upload_date,
			'contentUrl'  => $embed_url,
			'embedUrl'    => $embed_url,
		);

		if ( $thumbnail_url ) {
			$schema['thumbnailUrl'] = $thumbnail_url;
		}

		?>
		<script type="application/ld+json">
		<?php echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>
		</script>
		<?php
	}

	/**
	 * Resolve thumbnail URL from custom meta, provider API, or product image.
	 *
	 * @param WC_Product $product    The product object.
	 * @param array      $video_info Parsed video info.
	 * @return string Thumbnail URL or empty string.
	 */
	private static function resolve_thumbnail( $product, $video_info ) {
		$custom_thumb = get_post_meta( $product->get_id(), '_tvpg_video_thumb_url', true );
		if ( $custom_thumb ) {
			return $custom_thumb;
		}

		if ( 'youtube' === $video_info['type'] ) {
			return 'https://img.youtube.com/vi_webp/' . $video_info['id'] . '/maxresdefault.webp';
		}

		if ( 'vimeo' === $video_info['type'] ) {
			$thumbnail_url = TVPG_Video_Parser::get_vimeo_thumbnail( $video_info['id'] );
			return false === $thumbnail_url ? '' : $thumbnail_url;
		}

		if ( $product->get_image_id() ) {
			return wp_get_attachment_url( $product->get_image_id() );
		}

		return '';
	}

	/**
	 * Resolve the embed URL for schema markup.
	 *
	 * @param array  $video_info Parsed video info.
	 * @param string $video_url  Original video URL.
	 * @return string Embed URL.
	 */
	private static function resolve_embed_url( $video_info, $video_url ) {
		if ( 'youtube' === $video_info['type'] ) {
			return 'https://www.youtube.com/embed/' . $video_info['id'];
		}

		if ( 'vimeo' === $video_info['type'] ) {
			return 'https://player.vimeo.com/video/' . $video_info['id'];
		}

		if ( 'file' === $video_info['type'] ) {
			return $video_url;
		}

		if ( 'tiktok' === $video_info['type'] || 'instagram' === $video_info['type'] ) {
			return $video_url;
		}

		return '';
	}
}
