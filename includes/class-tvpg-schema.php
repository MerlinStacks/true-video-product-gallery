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
	 * Output Schema.org VideoObject structured data.
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
		$product_desc = $product->get_short_description() ?: $product->get_description();
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
			'description' => $product_desc ?: $product_name,
			'uploadDate'  => $upload_date,
			'contentUrl'  => $embed_url,
			'embedUrl'    => $embed_url,
		);

		if ( $thumbnail_url ) {
			$schema['thumbnailUrl'] = $thumbnail_url;
		}

		?>
		<script type="application/ld+json">
		<?php echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?>
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
			return 'https://img.youtube.com/vi/' . $video_info['id'] . '/maxresdefault.jpg';
		}

		if ( 'vimeo' === $video_info['type'] ) {
			return TVPG_Video_Parser::get_vimeo_thumbnail( $video_info['id'] ) ?: '';
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

		return '';
	}
}
