<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * IMP-15: Uses $wpdb->prepare() with esc_like() for SQL hardening.
 * Multisite: Loops all blogs so data is cleaned network-wide.
 *
 * @package TVPG
 * @since   1.0.0
 * @since   1.3.0 IMP-15: SQL hardening with wpdb->prepare().
 * @since   1.6.0 Multisite support — cleans data on every blog in the network.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( ! function_exists( 'tvpg_cleanup_site' ) ) {
    /**
     * Clean up all TVPG data for a single site.
     *
     * @since 1.6.0
     */
    function tvpg_cleanup_site() {
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
    }
}

// Multisite: loop every blog and clean up.
if ( is_multisite() ) {
    $site_ids = get_sites( array( 'fields' => 'ids' ) );
    foreach ( $site_ids as $site_id ) {
        switch_to_blog( $site_id );
        tvpg_cleanup_site();
        restore_current_blog();
    }
} else {
    tvpg_cleanup_site();
}
