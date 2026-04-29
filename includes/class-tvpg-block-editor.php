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
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
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
			'_tvpg_video_url'      => array(
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
			'_tvpg_use_same_video' => array(
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

		register_block_type( $block_dir );
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

		// Why: only load on product edit screens to avoid polluting other editors.
		$is_product_screen = ( 'product' === $screen->post_type );
		if ( ! $is_product_screen ) {
			return;
		}

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		wp_enqueue_script(
			'tvpg-block-editor',
			TVPG_URL . 'assets/js/tvpg-block-editor' . $suffix . '.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-i18n' ),
			TVPG_VERSION,
			true
		);

		wp_enqueue_style( 'tvpg-admin', TVPG_URL . 'assets/css/tvpg-admin.css', array(), TVPG_VERSION );
	}
}
