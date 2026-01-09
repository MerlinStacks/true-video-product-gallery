<?php
/**
 * Plugin loader for True Video Product Gallery.
 *
 * @package TVPG
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class TVPG_Loader
 *
 * Handles plugin initialization, loading dependencies, and registering hooks.
 *
 * @since 1.0.0
 */
class TVPG_Loader {

    /**
     * Frontend instance for singleton access.
     *
     * @since 1.2.0
     * @var TVPG_Frontend|null
     */
    public static $frontend = null;

    /**
     * Run the plugin.
     *
     * Loads dependencies and registers all hooks for admin and frontend.
     *
     * @since 1.0.0
     * @return void
     */
    public function run() {
        require_once TVPG_PATH . 'includes/class-tvpg-settings.php';
        require_once TVPG_PATH . 'includes/class-tvpg-video-parser.php';
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    /**
     * Register admin hooks.
     *
     * Loads and initializes the admin class.
     *
     * @since 1.0.0
     * @return void
     */
    private function define_admin_hooks() {
        require_once TVPG_PATH . 'includes/class-tvpg-admin.php';
        new TVPG_Admin();
    }

    /**
     * Register public-facing hooks.
     *
     * Loads the frontend class and registers all public hooks including
     * script enqueuing, gallery rendering, and variation video support.
     *
     * @since 1.0.0
     * @return void
     */
    private function define_public_hooks() {
        require_once TVPG_PATH . 'includes/class-tvpg-frontend.php';
        self::$frontend = new TVPG_Frontend();

        add_action( 'wp_enqueue_scripts', array( self::$frontend, 'enqueue_scripts' ) );
        
        // Remove default WooCommerce gallery hooks.
        add_action( 'after_setup_theme', array( self::$frontend, 'remove_default_gallery_support' ), 100 );
        
        // Add our custom gallery.
        add_filter( 'woocommerce_single_product_image_thumbnail_html', array( self::$frontend, 'custom_thumbnail_html' ), 10, 2 );
        add_action( 'woocommerce_before_single_product_summary', array( self::$frontend, 'render_gallery' ), 20 );
        add_action( 'init', array( self::$frontend, 'register_shortcode' ) );
        add_filter( 'woocommerce_available_variation', array( self::$frontend, 'add_variation_video_data' ), 10, 3 );
    }

    /**
     * Get the frontend instance.
     *
     * @since 1.2.0
     * @return TVPG_Frontend|null The frontend instance.
     */
    public static function get_frontend() {
        return self::$frontend;
    }
}

