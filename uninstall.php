<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * IMP-15: Uses $wpdb->prepare() with esc_like() for SQL hardening.
 *
 * @package TVPG
 * @since   1.0.0
 * @since   1.3.0 IMP-15: SQL hardening with wpdb->prepare().
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// 1. Delete Plugin Options.
delete_option( 'tvpg_options' );

// 2. Delete Post Meta (Cleanup video data from products).
delete_metadata( 'post', 0, '_tvpg_video_url', '', true );
delete_metadata( 'post', 0, '_tvpg_video_thumb_url', '', true );
delete_metadata( 'post', 0, '_tvpg_use_same_video', '', true );

// 3. Delete Vimeo thumbnail transients (IMP-15: prepared statements).
global $wpdb;
$like_transient = $wpdb->esc_like( '_transient_tvpg_vimeo_thumb_' ) . '%';
$like_timeout   = $wpdb->esc_like( '_transient_timeout_tvpg_vimeo_thumb_' ) . '%';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_transient ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) );
