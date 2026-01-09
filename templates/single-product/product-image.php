<?php
/**
 * Custom Product Image Template for True Video Product Gallery.
 *
 * This template overrides the default WooCommerce product-image.php
 * to ensure our gallery renders instead of the theme's.
 *
 * @package TVPG
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Use the singleton frontend instance to avoid creating duplicate instances.
$frontend = TVPG_Loader::get_frontend();
if ( $frontend ) {
    $frontend->render_gallery();
}

