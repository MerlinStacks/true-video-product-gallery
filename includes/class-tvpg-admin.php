<?php
/**
 * Admin functionality for True Video Product Gallery.
 *
 * @package TVPG
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class TVPG_Admin
 *
 * Handles admin settings page, product video meta boxes, REST API endpoints,
 * and WooCommerce product data integration.
 *
 * @since 1.0.0
 */
class TVPG_Admin {

    /**
     * Constructor.
     *
     * Registers all admin hooks for settings, meta boxes, and REST API.
     *
     * @since 1.0.0
     */

    public function __construct() {
        add_action( 'save_post', array( $this, 'save_video_meta_box' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // WC Tabs.
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_data_tab' ) );
        add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_data_panel' ) );
    }

    /**
     * Register the admin settings page.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_settings_page() {
        add_menu_page(
            __( 'True Video Gallery', 'true-video-product-gallery' ),
            __( 'True Video Gallery', 'true-video-product-gallery' ),
            'manage_options',
            'tvpg-settings',
            array( $this, 'render_settings_page' ),
            'dashicons-video-alt3',
            58
        );
    }

    /**
     * Enqueue admin scripts and styles.
     *
     * @since 1.0.0
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_tvpg-settings' === $hook ) {
            wp_enqueue_style( 'tvpg-admin-css', TVPG_URL . 'assets/css/tvpg-admin.css', array(), TVPG_VERSION );
            wp_enqueue_script( 'tvpg-admin-js', TVPG_URL . 'assets/js/tvpg-admin-settings.js', array( 'wp-element', 'wp-i18n', 'wp-components', 'wp-api-fetch' ), TVPG_VERSION, true );
            wp_localize_script( 'tvpg-admin-js', 'tvpgSettings', array(
                'root' => esc_url_raw( rest_url() ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'settings' => $this->get_settings(),
            ) );
        }

        // Product Page Assets
        $screen = get_current_screen();
        if ( $screen && 'product' === $screen->id ) {
            wp_enqueue_media();
            wp_enqueue_style( 'tvpg-admin-css', TVPG_URL . 'assets/css/tvpg-admin.css', array(), TVPG_VERSION ); // Added CSS
            wp_enqueue_script( 'tvpg-admin-product-js', TVPG_URL . 'assets/js/tvpg-admin-product.js', array( 'jquery' ), TVPG_VERSION, true );
            
            // Pass global settings to the product page script
            wp_localize_script( 'tvpg-admin-product-js', 'tvpgGlobalSettings', $this->get_settings() );
        }
    }

    /**
     * Register REST API routes.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_rest_routes() {
        // GET settings.
        register_rest_route( 'tvpg/v1', '/settings', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_settings_rest' ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ) );

        // POST (update) settings.
        register_rest_route( 'tvpg/v1', '/settings', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_settings' ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ) );
    }

    /**
     * Get settings via REST API.
     *
     * @since 1.2.0
     * @return WP_REST_Response Response with settings.
     */
    public function get_settings_rest() {
        return rest_ensure_response( array(
            'success'  => true,
            'settings' => TVPG_Settings::get_all(),
            'version'  => TVPG_VERSION,
        ) );
    }

    /**
     * Get plugin settings with defaults.
     *
     * @since 1.0.0
     * @since 1.2.0 Delegates to TVPG_Settings.
     * @return array Plugin settings.
     */
    public function get_settings() {
        return TVPG_Settings::get_all();
    }

    /**
     * Update plugin settings via REST API.
     *
     * @since 1.0.0
     * @since 1.2.0 Uses TVPG_Settings for validation.
     * @param WP_REST_Request $request REST request object.
     * @return WP_REST_Response Response with success status and settings.
     */
    public function update_settings( $request ) {
        $params  = $request->get_json_params();
        $current = TVPG_Settings::get_all();
        
        // Get valid values from centralized settings.
        $valid_sizing   = TVPG_Settings::get_valid_values( 'video_sizing' );
        $valid_position = TVPG_Settings::get_valid_values( 'video_position' );
        $valid_preload  = TVPG_Settings::get_valid_values( 'video_preload' );

        $new_settings = array(
            'autoplay'       => isset( $params['autoplay'] ) ? (bool) $params['autoplay'] : $current['autoplay'],
            'loop'           => isset( $params['loop'] ) ? (bool) $params['loop'] : $current['loop'],
            'show_controls'  => isset( $params['show_controls'] ) ? (bool) $params['show_controls'] : $current['show_controls'],
            'mute_autoplay'  => isset( $params['mute_autoplay'] ) ? (bool) $params['mute_autoplay'] : $current['mute_autoplay'],
            'video_sizing'   => isset( $params['video_sizing'] ) && in_array( $params['video_sizing'], $valid_sizing, true )
                ? $params['video_sizing']
                : $current['video_sizing'],
            'video_position' => isset( $params['video_position'] ) && in_array( $params['video_position'], $valid_position, true )
                ? $params['video_position']
                : $current['video_position'],
            'video_preload'  => isset( $params['video_preload'] ) && in_array( $params['video_preload'], $valid_preload, true )
                ? $params['video_preload']
                : $current['video_preload'],
        );

        TVPG_Settings::update( $new_settings );

        return rest_ensure_response( array( 'success' => true, 'settings' => $new_settings ) );
    }

    /**
     * Render the settings page container.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_settings_page() {
        echo '<div id="tvpg-admin-app"></div>';
    }

    /**
     * Add video tab to WooCommerce product data tabs.
     *
     * @since 1.0.0
     * @param array $tabs Existing product data tabs.
     * @return array Modified tabs array.
     */
    public function add_product_data_tab( $tabs ) {
        $tabs['tvpg_video'] = array(
            'label'    => __( 'Product Video', 'true-video-product-gallery' ),
            'target'   => 'tvpg_video_options',
            'class'    => array(), // Show for all product types.
            'priority' => 25,
        );
        return $tabs;
    }

    /**
     * Render the video options panel in product data.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_product_data_panel() {
        global $post;
        $video_url = get_post_meta( $post->ID, '_tvpg_video_url', true );
        wp_nonce_field( 'tvpg_save_video_meta', 'tvpg_video_nonce' );
        ?>
        <div id="tvpg_video_options" class="panel woocommerce_options_panel tvpg-modern-panel">
            <div class="tvpg-product-grid">
                
                <!-- Left Column: Settings -->
                <div class="tvpg-col-inputs">
                    <div class="tvpg-section-header">
                        <h3><?php esc_html_e( 'Video Configuration', 'true-video-product-gallery' ); ?></h3>
                        <p><?php esc_html_e( 'Add a video to your product gallery regardless of theme.', 'true-video-product-gallery' ); ?></p>
                    </div>

                    <!-- Video Source -->
                    <div class="tvpg-form-group">
                        <label for="tvpg_video_url"><?php esc_html_e( 'Video Source (URL or File)', 'true-video-product-gallery' ); ?></label>
                        <div class="tvpg-input-row">
                            <input type="text" name="tvpg_video_url" id="tvpg_video_url" value="<?php echo esc_attr( $video_url ); ?>" placeholder="<?php esc_attr_e('https://youtube.com/watch?v=...', 'true-video-product-gallery'); ?>">
                        </div>
                        <div class="tvpg-actions-row">
                            <button type="button" class="button" id="tvpg_upload_video_btn">
                                <span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Upload / Select', 'true-video-product-gallery' ); ?>
                            </button>
                            <button type="button" class="button link-delete" id="tvpg_clear_video_btn"><?php esc_html_e( 'Remove', 'true-video-product-gallery' ); ?></button>
                        </div>
                        <p class="description"><?php esc_html_e( 'Supports YouTube, Vimeo, MP4, WebM, and OGG.', 'true-video-product-gallery' ); ?></p>
                    </div>

                    <!-- Thumbnail -->
                    <?php $video_thumb = get_post_meta( $post->ID, '_tvpg_video_thumb_url', true ); ?>
                    <div class="tvpg-form-group">
                        <label for="tvpg_video_thumb_url"><?php esc_html_e( 'Custom Thumbnail', 'true-video-product-gallery' ); ?></label>
                        <div class="tvpg-input-row">
                            <input type="text" name="tvpg_video_thumb_url" id="tvpg_video_thumb_url" value="<?php echo esc_attr( $video_thumb ); ?>" placeholder="<?php esc_attr_e('Select image...', 'true-video-product-gallery'); ?>">
                        </div>
                        <div class="tvpg-actions-row">
                            <button type="button" class="button" id="tvpg_upload_thumb_btn">
                                <span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Choose Image', 'true-video-product-gallery' ); ?>
                            </button>
                            <button type="button" class="button link-delete" id="tvpg_clear_thumb_btn"><?php esc_html_e( 'Remove', 'true-video-product-gallery' ); ?></button>
                        </div>
                        <p class="description"><?php esc_html_e( 'Overrides the main product image for the video slide.', 'true-video-product-gallery' ); ?></p>
                    </div>
                </div>

                <!-- Right Column: Preview -->
                <div class="tvpg-col-preview">
                    <h3><?php esc_html_e( 'Live Preview', 'true-video-product-gallery' ); ?></h3>
                    
                    <!-- Main Video -->
                    <div class="tvpg-preview-card">
                        <div id="tvpg_video_preview">
                            <!-- JS injects here -->
                            <div class="tvpg-empty-state">
                                <span class="dashicons dashicons-video-alt3"></span>
                                <p><?php esc_html_e( 'No video selected', 'true-video-product-gallery' ); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Thumb Preview -->
                    <h4 style="margin-top: 16px; margin-bottom: 8px; font-size: 11px; text-transform: uppercase; color: #64748b;"><?php esc_html_e( 'Thumbnail Preview', 'true-video-product-gallery' ); ?></h4>
                    <div class="tvpg-preview-card tvpg-thumb-card" style="width: 80px; height: 80px; aspect-ratio: 1/1;">
                        <div id="tvpg_thumb_preview">
                             <!-- JS injects thumb here -->
                        </div>
                    </div>
                </div>

            </div>

            <?php
            // Variation Video Section (only for variable products).
            $product = wc_get_product( $post->ID );
            if ( $product && $product->is_type( 'variable' ) ) :
                $variations = $product->get_children();
                $use_same_for_all = get_post_meta( $post->ID, '_tvpg_use_same_video', true );
            ?>
            <div class="tvpg-variation-section" style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #e2e8f0;">
                <div class="tvpg-section-header">
                    <h3><?php esc_html_e( 'Variation Videos', 'true-video-product-gallery' ); ?></h3>
                    <p><?php esc_html_e( 'Assign videos to specific variations, or use the main product video for all.', 'true-video-product-gallery' ); ?></p>
                </div>

                <div class="tvpg-form-group" style="margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="tvpg_use_same_video" id="tvpg_use_same_video" value="yes" <?php checked( $use_same_for_all, 'yes' ); ?>>
                        <strong><?php esc_html_e( 'Use main product video for all variations', 'true-video-product-gallery' ); ?></strong>
                    </label>
                    <p class="description" style="margin-left: 24px;"><?php esc_html_e( 'When enabled, all variations will display the main product video above.', 'true-video-product-gallery' ); ?></p>
                </div>

                <div id="tvpg-variation-videos" style="<?php echo ( 'yes' === $use_same_for_all ) ? 'display: none;' : ''; ?>">
                    <?php if ( ! empty( $variations ) ) : ?>
                    <table class="wp-list-table widefat fixed striped" style="margin-top: 16px;">
                        <thead>
                            <tr>
                                <th style="width: 30%;"><?php esc_html_e( 'Variation', 'true-video-product-gallery' ); ?></th>
                                <th style="width: 35%;"><?php esc_html_e( 'Video URL', 'true-video-product-gallery' ); ?></th>
                                <th style="width: 35%;"><?php esc_html_e( 'Custom Thumbnail', 'true-video-product-gallery' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $variations as $variation_id ) :
                                $variation = wc_get_product( $variation_id );
                                if ( ! $variation ) continue;
                                
                                $variation_name  = $variation->get_name();
                                $variation_video = get_post_meta( $variation_id, '_tvpg_video_url', true );
                                $variation_thumb = get_post_meta( $variation_id, '_tvpg_video_thumb_url', true );
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $variation_name ); ?></strong></td>
                                <td>
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <input type="text" 
                                               name="tvpg_variation_videos[<?php echo esc_attr( $variation_id ); ?>]" 
                                               value="<?php echo esc_attr( $variation_video ); ?>" 
                                               placeholder="<?php esc_attr_e( 'https://youtube.com/...', 'true-video-product-gallery' ); ?>"
                                               style="flex: 1;">
                                        <button type="button" class="button tvpg-upload-variation-video" data-variation-id="<?php echo esc_attr( $variation_id ); ?>">
                                            <span class="dashicons dashicons-upload" style="margin-top: 3px;"></span>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <input type="text" 
                                               name="tvpg_variation_thumbs[<?php echo esc_attr( $variation_id ); ?>]" 
                                               value="<?php echo esc_attr( $variation_thumb ); ?>" 
                                               placeholder="<?php esc_attr_e( 'Select image...', 'true-video-product-gallery' ); ?>"
                                               style="flex: 1;">
                                        <button type="button" class="button tvpg-upload-variation-thumb" data-variation-id="<?php echo esc_attr( $variation_id ); ?>">
                                            <span class="dashicons dashicons-format-image" style="margin-top: 3px;"></span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else : ?>
                    <p class="description"><?php esc_html_e( 'No variations found. Add variations in the "Variations" tab first.', 'true-video-product-gallery' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    /**
     * Save video meta box data.
     *
     * Saves video URL and thumbnail URL to product post meta.
     *
     * @since 1.0.0
     * @param int $post_id Post ID being saved.
     * @return void
     */
    public function save_video_meta_box( $post_id ) {
        if ( ! isset( $_POST['tvpg_video_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tvpg_video_nonce'] ) ), 'tvpg_save_video_meta' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Main product video.
        if ( isset( $_POST['tvpg_video_url'] ) ) {
            $video_url = esc_url_raw( wp_unslash( $_POST['tvpg_video_url'] ) );
            update_post_meta( $post_id, '_tvpg_video_url', $video_url );
        }
        if ( isset( $_POST['tvpg_video_thumb_url'] ) ) {
            $thumb_url = esc_url_raw( wp_unslash( $_POST['tvpg_video_thumb_url'] ) );
            update_post_meta( $post_id, '_tvpg_video_thumb_url', $thumb_url );
        }

        // "Use same video for all variations" checkbox.
        $use_same = isset( $_POST['tvpg_use_same_video'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_tvpg_use_same_video', $use_same );

        // Variation videos (from centralized Product Video tab).
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
        if ( isset( $_POST['tvpg_variation_videos'] ) && is_array( $_POST['tvpg_variation_videos'] ) ) {
            foreach ( $_POST['tvpg_variation_videos'] as $variation_id => $video_url ) {
                $variation_id = absint( $variation_id );
                $video_url    = esc_url_raw( wp_unslash( $video_url ) );
                update_post_meta( $variation_id, '_tvpg_video_url', $video_url );
            }
        }

        // Variation thumbnails.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
        if ( isset( $_POST['tvpg_variation_thumbs'] ) && is_array( $_POST['tvpg_variation_thumbs'] ) ) {
            foreach ( $_POST['tvpg_variation_thumbs'] as $variation_id => $thumb_url ) {
                $variation_id = absint( $variation_id );
                $thumb_url    = esc_url_raw( wp_unslash( $thumb_url ) );
                update_post_meta( $variation_id, '_tvpg_video_thumb_url', $thumb_url );
            }
        }
    }
}
