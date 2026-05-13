<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package TVPG
 */

define( 'ABSPATH', dirname( dirname( __FILE__ ) ) . '/tests/mocks/wordpress/' );
define( 'TVPG_PATH', dirname( dirname( __FILE__ ) ) . '/' );
define( 'TVPG_URL', 'https://example.com/wp-content/plugins/true-video-product-gallery/' );
define( 'TVPG_VERSION', '1.7.10' );

// Load WordPress stub functions.
require dirname( __DIR__ ) . '/tests/mocks/wordpress/functions.php';

// Autoload the plugin classes under test.
spl_autoload_register( function ( $class ) {
    $map = array(
        'TVPG_Settings'     => TVPG_PATH . 'includes/class-tvpg-settings.php',
        'TVPG_Video_Parser' => TVPG_PATH . 'includes/class-tvpg-video-parser.php',
        'TVPG_Video_Embed'  => TVPG_PATH . 'includes/class-tvpg-video-embed.php',
        'TVPG_Schema'       => TVPG_PATH . 'includes/class-tvpg-schema.php',
    );
    if ( isset( $map[ $class ] ) ) {
        require_once $map[ $class ];
    }
} );
