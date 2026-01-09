<?php
/**
 * Centralized settings management for True Video Product Gallery.
 *
 * @package TVPG
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class TVPG_Settings
 *
 * Provides a single source of truth for plugin settings.
 * Avoids duplication of default values across classes.
 *
 * @since 1.2.0
 */
class TVPG_Settings {

    /**
     * Option name in the database.
     *
     * @var string
     */
    const OPTION_NAME = 'tvpg_options';

    /**
     * Default settings values.
     *
     * @var array
     */
    private static $defaults = array(
        'autoplay'       => false,
        'loop'           => false,
        'show_controls'  => true,
        'mute_autoplay'  => true,
        'video_sizing'   => 'contain', // 'contain' (fit) or 'cover' (crop).
        'video_position' => 'second',  // 'first', 'second', 'last'.
        'video_preload'  => 'lazy',    // 'lazy' (facade), 'metadata', 'auto'.
    );

    /**
     * Cached settings instance.
     *
     * @var array|null
     */
    private static $settings = null;

    /**
     * Get all settings with defaults applied.
     *
     * @since 1.2.0
     * @param bool $force_refresh Whether to bypass cache.
     * @return array Plugin settings.
     */
    public static function get_all( $force_refresh = false ) {
        if ( null === self::$settings || $force_refresh ) {
            self::$settings = wp_parse_args(
                get_option( self::OPTION_NAME, array() ),
                self::$defaults
            );
        }
        return self::$settings;
    }

    /**
     * Get a single setting value.
     *
     * @since 1.2.0
     * @param string $key     Setting key.
     * @param mixed  $default Optional default if key doesn't exist.
     * @return mixed Setting value.
     */
    public static function get( $key, $default = null ) {
        $settings = self::get_all();
        if ( isset( $settings[ $key ] ) ) {
            return $settings[ $key ];
        }
        return $default ?? ( self::$defaults[ $key ] ?? null );
    }

    /**
     * Update settings.
     *
     * @since 1.2.0
     * @param array $new_settings Settings to update (merged with existing).
     * @return bool Whether the update was successful.
     */
    public static function update( $new_settings ) {
        $current  = self::get_all( true );
        $merged   = array_merge( $current, $new_settings );
        $success  = update_option( self::OPTION_NAME, $merged );
        
        // Clear cache.
        self::$settings = null;
        
        return $success;
    }

    /**
     * Get default settings.
     *
     * @since 1.2.0
     * @return array Default settings array.
     */
    public static function get_defaults() {
        return self::$defaults;
    }

    /**
     * Get valid values for select options (for validation).
     *
     * @since 1.2.0
     * @param string $key Setting key.
     * @return array Valid values, or empty array if not a select.
     */
    public static function get_valid_values( $key ) {
        $valid = array(
            'video_sizing'   => array( 'contain', 'cover' ),
            'video_position' => array( 'first', 'second', 'last' ),
            'video_preload'  => array( 'lazy', 'metadata', 'auto' ),
        );
        return $valid[ $key ] ?? array();
    }
}
