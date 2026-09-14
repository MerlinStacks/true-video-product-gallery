<?php
/**
 * Gallery renderer for True Video Product Gallery.
 *
 * Handles assembling slides (images + video) and rendering the Swiper-based
 * gallery with main slider and thumbnail strip.
 *
 * @package TVPG
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TVPG_Gallery_Renderer
 *
 * Assembles image and video slides into a Swiper gallery with thumbnails.
 *
 * @since 1.3.0
 */
class TVPG_Gallery_Renderer {

	/**
	 * Use the same media ordering/count as rendering, including native WC videos.
	 *
	 * @param WC_Product $product Product object.
	 * @return bool
	 */
	public static function needs_slider( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			return true;
		}
		return count(
			self::assemble_slides(
				$product->get_image_id(),
				$product->get_gallery_image_ids(),
				get_post_meta( $product->get_id(), '_tvpg_video_url', true ),
				TVPG_Settings::get( 'video_position' ),
				$product,
				self::get_core_media_items( $product )
			)
		) > 1;
	}

	/**
	 * Render the complete product gallery.
	 *
	 * Assembles slides from product images and video, renders the Swiper
	 * main slider and thumbnail slider, and outputs Schema.org data.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Moved from TVPG_Frontend to dedicated class.
	 * @return void
	 */
	public static function render() {
		global $product; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce uses the global $product object.
			$product = wc_get_product( get_the_ID() );
		}

		if ( ! $product ) {
			if ( defined( 'TVPG_DEBUG' ) && TVPG_DEBUG ) {
				echo '<!-- TVPG Error: No valid product found for gallery -->';
			}
			return;
		}

		$attachment_ids   = $product->get_gallery_image_ids();
		$main_image_id    = $product->get_image_id();
		$video_url        = get_post_meta( $product->get_id(), '_tvpg_video_url', true );
		$core_media_items = self::get_core_media_items( $product );

		if ( defined( 'TVPG_DEBUG' ) && TVPG_DEBUG ) {
			echo '<!-- TVPG Debug: Product ID ' . esc_html( $product->get_id() ) . ' | Main Img: ' . esc_html( $main_image_id ) . ' | Count: ' . count( (array) $attachment_ids ) . ' -->';
		}

		$settings    = TVPG_Settings::get_all();
		$bg_position = $settings['video_position'];

		$slides = self::assemble_slides( $main_image_id, $attachment_ids, $video_url, $bg_position, $product, $core_media_items );

		// Build conditional CSS classes for the wrapper.
		$columns         = (int) apply_filters( 'woocommerce_product_thumbnails_columns', 4 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserves the WooCommerce template API.
		$wrapper_classes = array(
			'tvpg-gallery-wrapper',
			'woocommerce-product-gallery',
			'woocommerce-product-gallery--with-images',
			'woocommerce-product-gallery--columns-' . absint( $columns ),
			'images',
		);
		if ( count( $slides ) <= 1 ) {
			$wrapper_classes[] = 'tvpg-single-slide';
		}
		$wrapper_classes = apply_filters( 'woocommerce_single_product_image_gallery_classes', $wrapper_classes ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserves the WooCommerce template API.
		$wrapper_classes = array_map( 'sanitize_html_class', (array) $wrapper_classes );
		// BUG-H3 fix: wrapper open/close lives here, not split across sub-methods.
		echo '<div class="' . esc_attr( implode( ' ', $wrapper_classes ) ) . '" data-columns="' . esc_attr( $columns ) . '" role="region" aria-label="' . esc_attr__( 'Product Gallery', 'true-video-product-gallery' ) . '" style="opacity: 1;">';
		self::render_main_slider( $slides );
		self::render_thumb_slider( $slides );
		echo '</div>';

		// BUG-H9 fix: output schema for variation video when parent has none.
		$schema_url = $video_url;
		if ( empty( $schema_url ) && $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$v_url = get_post_meta( $child_id, '_tvpg_video_url', true );
				if ( $v_url ) {
					$schema_url = $v_url;
					break;
				}
			}
		}
		if ( ! empty( $schema_url ) ) {
			TVPG_Schema::enqueue( $product, $schema_url );
		}
	}

	/**
	 * Get WooCommerce's ordered media gallery when its video gallery API exists.
	 *
	 * WooCommerce 11 introduced attachment videos in the core gallery. Using the
	 * core ordering here prevents this replacement template from dropping them.
	 * The fallback keeps the plugin compatible with its WooCommerce 8 minimum.
	 *
	 * @since 1.7.15
	 * @param WC_Product $product Product object.
	 * @return array|null Media items, or null when the API is unavailable.
	 */
	private static function get_core_media_items( $product ) {
		$class_name = '\\Automattic\\WooCommerce\\Internal\\ProductGallery\\ProductMediaGallery';

		if ( ! class_exists( $class_name ) || ! is_callable( array( $class_name, 'get_product_media_gallery_items_for_display' ) ) ) {
			return null;
		}

		try {
			return $class_name::get_product_media_gallery_items_for_display( $product );
		} catch ( Throwable $error ) {
			// The WooCommerce helper is internal, so fail safely if its contract changes.
			return null;
		}
	}

	/**
	 * Assemble the ordered array of slides (images + video).
	 *
	 * @param int        $main_image_id  Main product image attachment ID.
	 * @param array      $attachment_ids Gallery attachment IDs.
	 * @param string     $video_url      Video URL (may be empty).
	 * @param string     $position       Video position setting (first/second/last).
	 * @param WC_Product $product          The product object.
	 * @param array|null $core_media_items Ordered WooCommerce media items when available.
	 * @return array Ordered slide data.
	 */
	private static function assemble_slides( $main_image_id, $attachment_ids, $video_url, $position, $product, $core_media_items = null ) {
		$slides = array();

		if ( is_array( $core_media_items ) ) {
			foreach ( $core_media_items as $media_item ) {
				$media_type  = isset( $media_item['media_type'] ) ? $media_item['media_type'] : '';
				$source_type = isset( $media_item['source_type'] ) ? $media_item['source_type'] : 'attachment';

				if ( 'placeholder' === $source_type && empty( $video_url ) ) {
					$slides[] = array(
						'type'           => 'image',
						'id'             => 0,
						'is_placeholder' => true,
					);
				} elseif ( 'image' === $media_type && ! empty( $media_item['id'] ) ) {
					$slides[] = array(
						'type' => 'image',
						'id'   => absint( $media_item['id'] ),
					);
				} elseif ( 'video' === $media_type && ! empty( $media_item['id'] ) ) {
					$poster_id        = ! empty( $media_item['poster_id'] ) ? absint( $media_item['poster_id'] ) : $main_image_id;
					$native_video_url = wp_get_attachment_url( absint( $media_item['id'] ) );

					if ( $native_video_url ) {
						$slides[] = array(
							'type'      => 'video',
							'url'       => $native_video_url,
							'thumb_id'  => $poster_id,
							'thumb_url' => $poster_id ? wp_get_attachment_image_url( $poster_id, 'woocommerce_single' ) : '',
						);
					}
				}
			}
		} else {
			if ( $main_image_id ) {
				$slides[] = array(
					'type' => 'image',
					'id'   => $main_image_id,
				);
			}

			foreach ( $attachment_ids as $attachment_id ) {
				$slides[] = array(
					'type' => 'image',
					'id'   => $attachment_id,
				);
			}
		}

		// Guarantee at least one slide for Swiper initialisation.
		if ( empty( $slides ) && empty( $video_url ) ) {
			$slides[] = array(
				'type'           => 'image',
				'id'             => 0,
				'is_placeholder' => true,
			);
		}

		if ( ! empty( $video_url ) ) {
			$custom_thumb = get_post_meta( $product->get_id(), '_tvpg_video_thumb_url', true );
			$video_slide  = array(
				'type'      => 'video',
				'url'       => $video_url,
				'thumb_id'  => $main_image_id,
				'thumb_url' => $custom_thumb,
			);

			if ( 'first' === $position ) {
				array_unshift( $slides, $video_slide );
			} elseif ( 'last' === $position ) {
				array_push( $slides, $video_slide );
			} elseif ( count( $slides ) > 0 ) {
				array_splice( $slides, 1, 0, array( $video_slide ) );
			} else {
				$slides[] = $video_slide;
			}
		}

		return $slides;
	}

	/**
	 * Render the main Swiper slider with navigation.
	 *
	 * @param array $slides Ordered slide data.
	 * @return void
	 */
	private static function render_main_slider( $slides ) {
		$allowed_html = TVPG_Video_Embed::get_allowed_html();
		// BUG-H3 fix: wrapper div is now opened/closed in render().
		?>
			<div class="swiper tvpg-main-slider" role="group" aria-roledescription="<?php esc_attr_e( 'carousel', 'true-video-product-gallery' ); ?>">
				<div class="swiper-wrapper">
					<?php
					foreach ( $slides as $slide_index => $slide ) :
						$is_placeholder = ( isset( $slide['is_placeholder'] ) && $slide['is_placeholder'] );
						$slide_classes  = 'swiper-slide';
						if ( 'video' === $slide['type'] ) {
							$slide_classes .= ' tvpg-video-slide';
						}
						if ( $is_placeholder ) {
							$slide_classes .= ' tvpg-placeholder-slide';
						}
						?>
						<div class="<?php echo esc_attr( $slide_classes ); ?>" role="group" aria-roledescription="<?php esc_attr_e( 'slide', 'true-video-product-gallery' ); ?>">
							<div class="woocommerce-product-gallery__image">
							<?php
							if ( 'image' === $slide['type'] ) {
								if ( ! empty( $slide['is_placeholder'] ) || 0 === $slide['id'] ) {
									printf( '<img src="%s" alt="%s" />', esc_url( wc_placeholder_img_src( 'woocommerce_single' ) ), esc_attr__( 'Placeholder', 'true-video-product-gallery' ) );
								} else {
									// Prioritise only the visible slide, including video-first galleries.
									$img_attrs = array(
										'sizes'         => '(max-width: 768px) 100vw, (max-width: 1200px) 50vw, 600px',
										'loading'       => 'lazy',
										'fetchpriority' => 'low',
										'decoding'      => 'async',
									);
									if ( 0 === $slide_index ) {
										$img_attrs = array(
											'fetchpriority' => 'high',
											'loading'  => 'eager',
											'decoding' => 'sync',
											'sizes'    => '(max-width: 768px) 100vw, (max-width: 1200px) 50vw, 600px',
										);
									}
									echo wp_get_attachment_image( $slide['id'], 'woocommerce_single', false, $img_attrs );
								}
							} elseif ( 'video' === $slide['type'] ) {
								echo wp_kses( TVPG_Video_Embed::get_video_html( $slide['url'], isset( $slide['thumb_url'] ) ? $slide['thumb_url'] : '', 0 === $slide_index ), $allowed_html );
							}
							?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( count( $slides ) > 1 ) : ?>
				<button type="button" class="swiper-button-next" aria-label="<?php esc_attr_e( 'Next slide', 'true-video-product-gallery' ); ?>"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg></button>
				<button type="button" class="swiper-button-prev" aria-label="<?php esc_attr_e( 'Previous slide', 'true-video-product-gallery' ); ?>"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 19l-7-7 7-7"/></svg></button>
				<?php endif; ?>
			</div>
		<?php
	}

	/**
	 * Render the thumbnail Swiper slider.
	 *
	 * @since 1.5.0 Removed live iframe thumbnails — uses static images for YouTube/Vimeo.
	 * @param array $slides Ordered slide data.
	 * @return void
	 */
	private static function render_thumb_slider( $slides ) {
		$allowed_html = TVPG_Video_Embed::get_allowed_html();
		// Single-slide strips are hidden by CSS; avoid generating unused thumbnails.
		global $product;
		if ( count( $slides ) <= 1 && ! ( $product instanceof WC_Product && $product->is_type( 'variable' ) ) ) {
			return;
		}
		?>
			<div class="swiper tvpg-thumb-slider">
				<div class="swiper-wrapper">
					<?php
					foreach ( $slides as $slide_idx => $slide ) :
						$thumb_label = 'video' === $slide['type']
							? sprintf( /* translators: %d: slide number */ esc_attr__( 'Video thumbnail, slide %d', 'true-video-product-gallery' ), $slide_idx + 1 )
							: sprintf( /* translators: %d: slide number */ esc_attr__( 'Image thumbnail, slide %d', 'true-video-product-gallery' ), $slide_idx + 1 );
						?>
						<div class="swiper-slide <?php echo 'video' === $slide['type'] ? 'tvpg-video-thumb-slide' : ''; ?>" tabindex="0" aria-label="<?php echo esc_attr( $thumb_label ); ?>" role="button">
						<?php
						if ( 'image' === $slide['type'] ) {
							if ( ! empty( $slide['is_placeholder'] ) || 0 === $slide['id'] ) {
								printf( '<img src="%s" alt="%s" />', esc_url( wc_placeholder_img_src( 'woocommerce_thumbnail' ) ), esc_attr__( 'Placeholder', 'true-video-product-gallery' ) );
							} else {
								$thumb_attrs = array(
									'loading'       => 'lazy',
									'fetchpriority' => 'low',
									'decoding'      => 'async',
								);
								echo wp_get_attachment_image( $slide['id'], 'woocommerce_thumbnail', false, $thumb_attrs );
							}
						} elseif ( 'video' === $slide['type'] ) {
							$video_info = TVPG_Video_Parser::get_video_info( $slide['url'] );
							if ( $video_info && 'file' === $video_info['type'] ) {
								$poster_url = '';
								if ( ! empty( $slide['thumb_url'] ) ) {
									$poster_url = $slide['thumb_url'];
								} elseif ( ! empty( $slide['thumb_id'] ) ) {
									$poster_src = wp_get_attachment_image_url( $slide['thumb_id'], 'woocommerce_thumbnail' );
									if ( $poster_src ) {
										$poster_url = $poster_src;
									}
								}
								if ( $poster_url ) {
									echo '<img src="' . esc_url( $poster_url ) . '" alt="' . esc_attr__( 'Video Thumbnail', 'true-video-product-gallery' ) . '" loading="lazy" decoding="async">';
								} else {
									echo '<div class="tvpg-social-placeholder"></div>';
								}
								echo '<span class="tvpg-thumb-play-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="#fff" style="filter:drop-shadow(0 1px 2px rgba(0,0,0,.5))"><path d="M8 5v14l11-7z"/></svg></span>';
							} elseif ( ! empty( $slide['thumb_url'] ) ) {
								echo '<img src="' . esc_url( $slide['thumb_url'] ) . '" alt="' . esc_attr__( 'Video Thumbnail', 'true-video-product-gallery' ) . '" style="width:100%;height:100%;object-fit:cover;">';
							} else {
								echo wp_kses( TVPG_Video_Embed::get_video_thumb_html( $slide['url'] ), $allowed_html );
							}
						}
						?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php
	}
}
