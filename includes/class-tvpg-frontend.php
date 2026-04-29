<?php
/**
 * Frontend functionality for True Video Product Gallery.
 *
 * Orchestrates script enqueuing, WooCommerce hook management,
 * shortcode registration, and variation video data injection.
 *
 * @package TVPG
 * @since   1.0.0
 * @since   1.3.0 Decomposed into orchestrator; rendering delegated to TVPG_Gallery_Renderer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TVPG_Frontend
 *
 * Slim orchestrator that manages hook registration and delegates rendering
 * to TVPG_Gallery_Renderer and TVPG_Video_Embed.
 *
 * @since 1.0.0
 * @since 1.3.0 Decomposed — rendering, embeds, and schema moved to dedicated classes.
 */
class TVPG_Frontend {

	/**
	 * Enqueue frontend scripts and styles.
	 *
	 * IMP-05: Conditionally loads Swiper only when the gallery has >1 slide.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Conditional Swiper loading, jQuery-free frontend script.
	 * @return void
	 */
	public function enqueue_scripts() {
		if ( ! is_product() ) {
			return;
		}

		$suffix   = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$settings = TVPG_Settings::get_all();

		// IMP-05: Determine if Swiper is needed (>1 slide or has video).
		global $product;
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			$product = wc_get_product( get_the_ID() );
		}

		$needs_slider = true;
		if ( $product ) {
			$gallery_count = count( $product->get_gallery_image_ids() );
			$has_main      = (bool) $product->get_image_id();
			$has_video     = (bool) get_post_meta( $product->get_id(), '_tvpg_video_url', true );
			$is_variable   = $product->is_type( 'variable' );
			$total_slides  = ( $has_main ? 1 : 0 ) + $gallery_count + ( $has_video ? 1 : 0 );

			// Variable products always need the slider for variation video injection.
			$needs_slider = ( $total_slides > 1 ) || $is_variable;
		}

		if ( $needs_slider ) {
			wp_enqueue_style( 'tvpg-swiper', TVPG_URL . 'assets/lib/swiper/swiper-slim.min.css', array(), TVPG_VERSION );
			wp_enqueue_script( 'tvpg-swiper', TVPG_URL . 'assets/lib/swiper/swiper-slim.min.js', array(), TVPG_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
		}

		// Core styles always load for layout stability.
		$style_deps = $needs_slider ? array( 'tvpg-swiper' ) : array();
		wp_enqueue_style( 'tvpg-frontend', TVPG_URL . 'assets/css/tvpg-frontend' . $suffix . '.css', $style_deps, TVPG_VERSION );

		// IMP-06: Frontend JS no longer depends on jQuery.
		$script_deps = $needs_slider ? array( 'tvpg-swiper' ) : array();
		wp_enqueue_script( 'tvpg-frontend', TVPG_URL . 'assets/js/tvpg-frontend' . $suffix . '.js', $script_deps, TVPG_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );

		wp_localize_script( 'tvpg-frontend', 'tvpgParams', array(
			'settings'    => $settings,
			'needsSlider' => $needs_slider,
		) );

		// Dynamic CSS for video sizing.
		$fit        = ( 'cover' === TVPG_Settings::get( 'video_sizing' ) ) ? 'cover' : 'contain';
		$custom_css = "
			.tvpg-responsive-video video { object-fit: {$fit} !important; }
			.tvpg-responsive-video iframe { object-fit: {$fit} !important; }
		";

		// Hide navigation arrows when disabled.
		if ( ! TVPG_Settings::get( 'show_arrows' ) ) {
			$custom_css .= '
				.tvpg-main-slider .swiper-button-next,
				.tvpg-main-slider .swiper-button-prev { display: none !important; }
			';
		}

		// Hide zoom cursor when lightbox is disabled.
		if ( ! TVPG_Settings::get( 'enable_lightbox' ) ) {
			$custom_css .= '
				.tvpg-main-slider .swiper-slide:not(.tvpg-video-slide) img { cursor: default; }
			';
		}

		wp_add_inline_style( 'tvpg-frontend', $custom_css );
	}

	/**
	 * Emit preconnect hints for video provider thumbnail domains.
	 *
	 * Why preconnect over dns-prefetch: preconnect warms DNS + TCP + TLS,
	 * saving ~100–200ms on the first facade image fetch vs DNS-only.
	 *
	 * @since 1.4.0
	 * @since 1.5.1 Upgraded from dns-prefetch to preconnect; added Vimeo CDN.
	 * @param array  $urls          Existing prefetch URLs.
	 * @param string $relation_type The relation type (dns-prefetch, preconnect, etc.).
	 * @return array Modified URLs array.
	 */
	public function add_resource_hints( $urls, $relation_type ) {
		if ( 'preconnect' === $relation_type && is_product() ) {
			$urls[] = array(
				'href'        => 'https://img.youtube.com',
				'crossorigin' => 'anonymous',
			);
			$urls[] = array(
				'href'        => 'https://i.vimeocdn.com',
				'crossorigin' => 'anonymous',
			);
		}
		return $urls;
	}

	/**
	 * Remove default gallery support from WooCommerce and themes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function remove_default_gallery_support() {
		remove_theme_support( 'wc-product-gallery-zoom' );
		remove_theme_support( 'wc-product-gallery-lightbox' );
		remove_theme_support( 'wc-product-gallery-slider' );

		remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );

		// Flatsome compatibility.
		remove_action( 'woocommerce_before_single_product_summary', 'flatsome_woocommerce_show_product_images', 20 );
		remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images_flatsome', 20 );

		add_action( 'wp', array( $this, 'remove_flatsome_late_hooks' ), 100 );
		add_filter( 'woocommerce_locate_template', array( $this, 'override_gallery_template' ), 100, 3 );
		add_action( 'init', array( $this, 'intercept_flatsome_shortcodes' ), 999 );
	}

	/**
	 * Intercept Flatsome gallery shortcodes to use our renderer.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function intercept_flatsome_shortcodes() {
		if ( shortcode_exists( 'ux_product_gallery' ) ) {
			remove_shortcode( 'ux_product_gallery' );
			add_shortcode( 'ux_product_gallery', array( $this, 'render_shortcode' ) );
		}
		if ( shortcode_exists( 'product_gallery' ) ) {
			remove_shortcode( 'product_gallery' );
			add_shortcode( 'product_gallery', array( $this, 'render_shortcode' ) );
		}
	}

	/**
	 * Override the WooCommerce gallery template with ours.
	 *
	 * @since 1.0.0
	 * @param string $template      Current template path.
	 * @param string $template_name Template name being loaded.
	 * @param string $template_path Template search path.
	 * @return string Modified template path.
	 */
	public function override_gallery_template( $template, $template_name, $template_path ) {
		if ( strpos( $template_name, 'product-image' ) !== false ) {
			$plugin_template = TVPG_PATH . 'templates/single-product/product-image.php';
			if ( file_exists( $plugin_template ) ) {
				return $plugin_template;
			}
		}
		return $template;
	}

	/**
	 * Remove theme gallery hooks that fire late (after after_setup_theme).
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function remove_flatsome_late_hooks() {
		remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 10 );
		remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
		remove_action( 'woocommerce_before_single_product_summary', 'flatsome_woocommerce_show_product_images', 20 );
		remove_action( 'woocommerce_before_single_product_summary', 'flatsome_product_image_tools', 20 );
		remove_action( 'woocommerce_before_single_product_summary', 'flatsome_product_image_vertical', 20 );
		remove_action( 'woocommerce_before_single_product_summary', 'flatsome_product_image_top', 20 );
		remove_action( 'woocommerce_before_single_product_summary', 'flatsome_product_image_stacked', 20 );
	}

	/**
	 * Delegate gallery rendering to the Gallery Renderer.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Delegates to TVPG_Gallery_Renderer.
	 * @return void
	 */
	public function render_gallery() {
		TVPG_Gallery_Renderer::render();
	}

	/**
	 * Passthrough for WooCommerce thumbnail HTML filter.
	 *
	 * @since 1.0.0
	 * @param string $html          Default thumbnail HTML.
	 * @param int    $attachment_id Attachment ID.
	 * @return string Unmodified HTML.
	 */
	public function custom_thumbnail_html( $html, $attachment_id ) {
		return $html;
	}

	/**
	 * Register the shortcode.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_shortcode() {
		add_shortcode( 'tvpg_gallery', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render the gallery via shortcode.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added product_id attribute support.
	 * @param array $atts Shortcode attributes.
	 * @return string Gallery HTML.
	 */
	public function render_shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array( 'product_id' => 0 ),
			$atts,
			'tvpg_gallery'
		);

		$product_id = absint( $atts['product_id'] );
		$original_post    = null;
		$original_product = null;

		if ( $product_id > 0 ) {
			global $post, $product;
			$target_post    = get_post( $product_id );
			$target_product = wc_get_product( $product_id );

			if ( ! $target_post || ! $target_product ) {
				return '<!-- TVPG: Invalid product ID -->';
			}

			$original_post    = $post;
			$original_product = $product;

			$post    = $target_post;
			$product = $target_product;
			setup_postdata( $post );
		}

		ob_start();
		try {
			$this->render_gallery();
			$output = ob_get_clean();
		} finally {
			if ( $product_id > 0 ) {
				$post    = $original_post;
				$product = $original_product;
				if ( $original_post ) {
					setup_postdata( $original_post );
				} else {
					wp_reset_postdata();
				}
			}
		}

		return $output;
	}

	/**
	 * Inject video data into WooCommerce variation response objects.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added "use same video for all" support.
	 * @param array      $data      Variation data array.
	 * @param WC_Product $product   Parent product object.
	 * @param WC_Product $variation Variation product object.
	 * @return array Modified variation data.
	 */
	public function add_variation_video_data( $data, $product, $variation ) {
		$use_same_for_all = get_post_meta( $product->get_id(), '_tvpg_use_same_video', true );
		$allowed_html     = TVPG_Video_Embed::get_allowed_html();

		if ( 'yes' === $use_same_for_all ) {
			$video_url = get_post_meta( $product->get_id(), '_tvpg_video_url', true );
			$thumb_url = get_post_meta( $product->get_id(), '_tvpg_video_thumb_url', true );
		} else {
			$video_url = get_post_meta( $variation->get_id(), '_tvpg_video_url', true );
			$thumb_url = get_post_meta( $variation->get_id(), '_tvpg_video_thumb_url', true );

			if ( empty( $thumb_url ) ) {
				$thumb_url = get_post_meta( $product->get_id(), '_tvpg_video_thumb_url', true );
			}
		}

		if ( ! empty( $video_url ) ) {
			$data['tvpg_video_html']       = wp_kses( TVPG_Video_Embed::get_video_html( $video_url, $thumb_url ), $allowed_html );
			$data['tvpg_video_thumb_html'] = wp_kses( TVPG_Video_Embed::get_video_thumb_html( $video_url ), $allowed_html );
		}

		return $data;
	}
}
