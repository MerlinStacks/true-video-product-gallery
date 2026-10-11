<?php
/**
 * WooCommerce Block Editor (Gutenberg) integration for True Video Product Gallery.
 *
 * Registers video meta fields for REST visibility and a custom block type
 * so the video panel appears in the new WooCommerce product block editor
 * while remaining fully backward-compatible with the classic editor.
 *
 * @package TVPG
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TVPG_Block_Editor
 *
 * Bridges TVPG product video data into the WooCommerce block-based product editor.
 *
 * @since 1.4.0
 */
class TVPG_Block_Editor {

	/**
	 * Initialise hooks.
	 *
	 * @since 1.4.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'init', array( $this, 'register_block' ), 20 );
		add_action( 'woocommerce_block_template_area_product-form_after_add_block_general', array( $this, 'add_product_editor_section' ) );
		if ( is_admin() ) {
			add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_assets' ), 20 );
		}
	}

	/**
	 * Expose product video meta fields to the REST API.
	 *
	 * Required for useEntityProp() to read/write these values
	 * inside the block editor without custom REST endpoints.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public function register_meta() {
		$meta_fields = array(
			'_tvpg_video_url'       => array(
				'type'              => 'string',
				'description'       => __( 'Product video URL', 'true-video-product-gallery' ),
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => true,
				'auth_callback'     => function () {
					return current_user_can( 'edit_products' );
				},
			),
			'_tvpg_video_thumb_url' => array(
				'type'              => 'string',
				'description'       => __( 'Custom video thumbnail URL', 'true-video-product-gallery' ),
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => true,
				'auth_callback'     => function () {
					return current_user_can( 'edit_products' );
				},
			),
			'_tvpg_use_same_video'  => array(
				'type'              => 'string',
				'description'       => __( 'Use same video for all variations', 'true-video-product-gallery' ),
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'auth_callback'     => function () {
					return current_user_can( 'edit_products' );
				},
			),
		);

		foreach ( $meta_fields as $key => $args ) {
			register_post_meta( 'product', $key, $args );
		}
	}

	/**
	 * Register the product-video block type from block.json.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public function register_block() {
		$block_dir = TVPG_PATH . 'blocks/tvpg-product-video';

		if ( ! file_exists( $block_dir . '/block.json' ) ) {
			return;
		}

		$registry = '\Automattic\WooCommerce\Admin\Features\ProductBlockEditor\BlockRegistry';
		if ( class_exists( $registry ) ) {
			$registry::get_instance()->register_block_type_from_metadata( $block_dir );
		} else {
			register_block_type( $block_dir );
		}
	}

	/**
	 * Insert the video controls into WooCommerce's product-form template, including REST requests.
	 *
	 * @param object $general General group in the product editor template.
	 */
	public function add_product_editor_section( $general ) {
		if ( ! is_callable( array( $general, 'add_section' ) ) ) {
			return;
		}
		$section = $general->add_section(
			array(
				'id'         => 'tvpg-product-video-section',
				'order'      => 45,
				'attributes' => array( 'title' => __( 'Product Video', 'true-video-product-gallery' ) ),
			)
		);
		$section->add_block(
			array(
				'id'        => 'tvpg-product-video',
				'blockName' => 'tvpg/product-video',
				'order'     => 10,
			)
		);
	}

	/**
	 * Enqueue editor-only script on the product edit screen.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public function enqueue_editor_assets() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$page_controller = '\Automattic\WooCommerce\Admin\PageController';
		$features        = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		$is_woo_editor   = class_exists( $page_controller ) && class_exists( $features )
			&& $features::feature_is_enabled( 'product_block_editor' )
			&& $page_controller::is_admin_or_embed_page();
		$is_post_editor  = 'product' === $screen->post_type && $screen->is_block_editor();
		if ( ! $is_woo_editor && ! $is_post_editor ) {
			return;
		}

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_media();

		$dependencies = array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-i18n' );
		if ( $is_woo_editor ) {
			$dependencies = array( 'wp-element', 'wp-components', 'wp-core-data', 'wp-i18n', 'wp-blocks', 'wc-product-editor', 'wc-block-templates' );
		}
		wp_enqueue_script(
			'tvpg-block-editor',
			TVPG_URL . 'assets/js/tvpg-block-editor' . $suffix . '.js',
			$dependencies,
			TVPG_VERSION,
			true
		);
		wp_set_script_translations( 'tvpg-block-editor', 'true-video-product-gallery' );
		wp_localize_script( 'tvpg-block-editor', 'tvpgEditorContext', array( 'woocommerce' => $is_woo_editor ) );

		wp_enqueue_style( 'tvpg-admin', TVPG_URL . 'assets/css/tvpg-admin' . $suffix . '.css', array(), TVPG_VERSION );
	}
}
