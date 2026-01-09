<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package TVPG
 * @since   1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// 1. Delete Plugin Options.
delete_option( 'tvpg_options' );

// 2. Delete Post Meta (Cleanup video data from products).
// Using delete_metadata with delete_all=true cleans up keys globally.

// Delete video URLs.
delete_metadata( 'post', 0, '_tvpg_video_url', '', true );

// Delete custom thumbnails.
delete_metadata( 'post', 0, '_tvpg_video_thumb_url', '', true );

// Delete "use same video for all" flag.
delete_metadata( 'post', 0, '_tvpg_use_same_video', '', true );

// 3. Delete Vimeo thumbnail transients.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tvpg_vimeo_thumb_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_tvpg_vimeo_thumb_%'" );

