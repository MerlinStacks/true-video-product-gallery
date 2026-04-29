<?php
/**
 * Plugin Name: True Video Product Gallery
 * Description: A powerful product gallery plugin for WooCommerce with video support, zoom, and customizable layouts.
 * Version: 1.6.0
 * Author: SLDevs
 * Author URI: https://sldevs.com
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 9.8
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: true-video-product-gallery
 * Domain Path: /languages
 *
 * @package TVPG
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants.
define( 'TVPG_VERSION', '1.6.0' );
define( 'TVPG_PATH', plugin_dir_path( __FILE__ ) );
define( 'TVPG_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 *
 * @since 1.2.0
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );

/**
 * Check if WooCommerce is active.
 *
 * @return bool
 */
function tvpg_is_woocommerce_active() {
    return class_exists( 'WooCommerce' );
}

/**
 * Display admin notice if WooCommerce is not active.
 */
function tvpg_woocommerce_missing_notice() {
    $message = sprintf(
        '<strong>%s</strong> %s <a href="%s">%s</a>',
        esc_html__( 'True Video Product Gallery', 'true-video-product-gallery' ),
        esc_html__( 'requires WooCommerce to be installed and active.', 'true-video-product-gallery' ),
        esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ),
        esc_html__( 'Install WooCommerce', 'true-video-product-gallery' )
    );
    wp_admin_notice( $message, array( 'type' => 'error', 'paragraph_wrap' => true ) );
}

// Include the main class.
if ( ! class_exists( 'TVPG_Loader' ) ) {
    require_once TVPG_PATH . 'includes/class-tvpg-loader.php';
}

/**
 * Initialize the plugin.
 */
function tvpg_init() {
    // Check for WooCommerce.
    if ( ! tvpg_is_woocommerce_active() ) {
        add_action( 'admin_notices', 'tvpg_woocommerce_missing_notice' );
        return;
    }

    load_plugin_textdomain( 'true-video-product-gallery', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    $plugin = new TVPG_Loader();
    $plugin->run();
}
add_action( 'plugins_loaded', 'tvpg_init' );


