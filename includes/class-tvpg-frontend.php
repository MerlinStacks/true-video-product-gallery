<?php
/**
 * Frontend functionality for True Video Product Gallery.
 *
 * @package TVPG
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class TVPG_Frontend
 *
 * Handles frontend rendering including script enqueuing, gallery display,
 * video embedding with lazy loading, and WooCommerce integration.
 *
 * @since 1.0.0
 */
class TVPG_Frontend {
    /**
     * Enqueue frontend scripts and styles.
     *
     * @since 1.0.0
     * @since 1.2.0 Uses TVPG_Settings for configuration, minified assets in production.
     * @return void
     */
    public function enqueue_scripts() {
        if ( ! is_product() ) {
            return;
        }

        // Use minified assets in production.
        $suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

        // Swiper.
        wp_enqueue_style( 'tvpg-swiper', TVPG_URL . 'assets/lib/swiper/swiper-bundle.min.css', array(), '11.0.0' );
        wp_enqueue_script( 'tvpg-swiper', TVPG_URL . 'assets/lib/swiper/swiper-bundle.min.js', array( 'jquery' ), '11.0.0', true );

        // Custom Styles & Scripts.
        wp_enqueue_style( 'tvpg-frontend', TVPG_URL . 'assets/css/tvpg-frontend' . $suffix . '.css', array( 'tvpg-swiper' ), TVPG_VERSION );
        wp_enqueue_script( 'tvpg-frontend', TVPG_URL . 'assets/js/tvpg-frontend' . $suffix . '.js', array( 'jquery', 'tvpg-swiper' ), TVPG_VERSION, true );
        
        // Get settings from centralized class.
        $settings = TVPG_Settings::get_all();

        wp_localize_script( 'tvpg-frontend', 'tvpgParams', array(
            'settings' => $settings,
        ) );

        // Add dynamic CSS for sizing.
        $fit = ( 'cover' === TVPG_Settings::get( 'video_sizing' ) ) ? 'cover' : 'contain';
        $custom_css = "
            .tvpg-responsive-video video { object-fit: {$fit} !important; }
            .tvpg-responsive-video iframe { object-fit: {$fit} !important; }
        ";
        wp_add_inline_style( 'tvpg-frontend', $custom_css );
    }

    public function remove_default_gallery_support() {
        remove_theme_support( 'wc-product-gallery-zoom' );
        remove_theme_support( 'wc-product-gallery-lightbox' );
        remove_theme_support( 'wc-product-gallery-slider' );
        
        // Remove the default template hook
        remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );

        // Flatsome Compatibility
        // Flatsome uses 'woocommerce_before_single_product_summary' with priority 10 or 20 usually.
        // It might also use 'flatsome_product_image' function.
        // We attempt to remove standard Flatsome hooks if they exist.
        remove_action( 'woocommerce_before_single_product_summary', 'flatsome_woocommerce_show_product_images', 20 );
        
        // Some Flatsome versions wrap it in a function called 'woocommerce_show_product_images_flatsome'
        remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images_flatsome', 20 );

        // Force our priority to be robust?
        // If theme adds their hook at priority 10, our remove_action (running at init/after_setup) works if we know the priority.
        // But render_gallery runs at 20. If Flatsome runs at 10, it prints first.
        // We should add a listener to remove hooks just before rendering to catch them all?
        // No, 'after_setup_theme' 100 is early enough to remove hooks added in theme 'functions.php'.
        
        // However, if Flatsome adds hooks in 'wp' or 'template_redirect', we might miss them.
        add_action( 'wp', array( $this, 'remove_flatsome_late_hooks' ), 100 );

        add_filter( 'woocommerce_locate_template', array( $this, 'override_gallery_template' ), 100, 3 );
        
        // Flatsome Compatibility:
        // Intercept the UX Builder 'Product Gallery' shortcode to ensure our gallery renders
        // even in custom layouts.
        add_action( 'init', array( $this, 'intercept_flatsome_shortcodes' ), 999 );
    }

    public function intercept_flatsome_shortcodes() {
        if ( shortcode_exists( 'ux_product_gallery' ) ) {
            remove_shortcode( 'ux_product_gallery' );
            add_shortcode( 'ux_product_gallery', array( $this, 'render_shortcode' ) );
        }
        if ( shortcode_exists( 'product_gallery' ) ) {
            remove_shortcode( 'product_gallery' );
            add_shortcode( 'product_gallery', array( $this, 'render_shortcode' ) );
        }
    }

    public function override_gallery_template( $template, $template_name, $template_path ) {
        // Intercept standard and all Flatsome variation templates (vertical, top, stacked, etc.)
        // We look for 'product-image' anywhere in the name to catch 'single-product/product-image-vertical.php' etc.
        if ( strpos( $template_name, 'product-image' ) !== false ) {
            $plugin_template = TVPG_PATH . 'templates/single-product/product-image.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }
        return $template;
    }

    public function remove_flatsome_late_hooks() {
        // Late removal for stubborn themes
        remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 10 );
        remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
        
        // Flatsome Specifics
        remove_action( 'woocommerce_before_single_product_summary', 'flatsome_woocommerce_show_product_images', 20 );
        remove_action( 'woocommerce_before_single_product_summary', 'flatsome_product_image_tools', 20 );
        
        // Flatsome Layout specific hooks (just in case they are hooked directly)
        remove_action( 'woocommerce_before_single_product_summary', 'flatsome_product_image_vertical', 20 );
        remove_action( 'woocommerce_before_single_product_summary', 'flatsome_product_image_top', 20 );
        remove_action( 'woocommerce_before_single_product_summary', 'flatsome_product_image_stacked', 20 );
    }

    public function render_gallery() {
        global $product;

        // Ensure we have a valid product object
        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
            $product = wc_get_product( get_the_ID() );
        }

        if ( ! $product ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                echo '<!-- TVPG Error: No valid product found for gallery -->';
            }
            return;
        }

        $attachment_ids = $product->get_gallery_image_ids();
        $main_image_id = $product->get_image_id();
        $video_url = get_post_meta( $product->get_id(), '_tvpg_video_url', true );

        // Debug info (hidden in production).
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            echo '<!-- TVPG Debug: Product ID ' . esc_html( $product->get_id() ) . ' | Main Img: ' . esc_html( $main_image_id ) . ' | Count: ' . count( (array) $attachment_ids ) . ' -->';
        }

        // Fetch settings for position
        $defaults = array(
            'video_position' => 'second',
        );
        $settings = wp_parse_args( get_option( 'tvpg_options', array() ), $defaults );
        $bg_position = isset($settings['video_position']) ? $settings['video_position'] : 'second';

        // 1. Collect Image Slides
        $slides = array();

        if ( $main_image_id ) {
            $slides[] = array(
                'type' => 'image',
                'id' => $main_image_id,
            );
        }

        foreach ( $attachment_ids as $attachment_id ) {
            $slides[] = array(
                'type' => 'image',
                'id' => $attachment_id,
            );
        }

        // 1.5 Ensure at least one slide exists (Placeholder)
        // If no main image, no gallery, and no video, we must render the WooCommerce placeholder
        // so that the Swiper initializes. This allows JS to dynamically append variation videos later.
        if ( empty( $slides ) && empty( $video_url ) ) {
            $slides[] = array(
                'type' => 'image',
                'id'   => 0, // 0 triggers placeholder logic
                'is_placeholder' => true,
            );
        }

        // 2. Insert Video
        if ( ! empty( $video_url ) ) {
            $custom_thumb = get_post_meta( $product->get_id(), '_tvpg_video_thumb_url', true );
            $video_slide = array(
                'type'      => 'video',
                'url'       => $video_url,
                'thumb_id'  => $main_image_id, 
                'thumb_url' => $custom_thumb,
            );

            if ( 'first' === $bg_position ) {
                array_unshift( $slides, $video_slide );
            } elseif ( 'last' === $bg_position ) {
                array_push( $slides, $video_slide );
            } else {
                // Default 'second'
                // If there are slides, insert at index 1.
                // If slides is empty, it becomes index 0 naturally.
                // If 1 slide, index 1 is end (same as last).
                if ( count( $slides ) > 0 ) {
                    array_splice( $slides, 1, 0, array( $video_slide ) );
                } else {
                    $slides[] = $video_slide;
                }
            }
        }

        // Basic wrapper with Swiper structure
        // Added standard WC classes for compatibility with other plugins (e.g. Personalize It)
        ?>
        <div class="tvpg-gallery-wrapper woocommerce-product-gallery woocommerce-product-gallery--with-images images" role="region" aria-label="<?php esc_attr_e( 'Product Gallery', 'true-video-product-gallery' ); ?>" style="opacity: 1;">
            <!-- Main Slider -->
            <div class="swiper tvpg-main-slider" role="group" aria-roledescription="<?php esc_attr_e( 'carousel', 'true-video-product-gallery' ); ?>">
                <div class="swiper-wrapper">
                    <?php foreach ( $slides as $slide ) : 
                        $is_placeholder = ( isset( $slide['is_placeholder'] ) && $slide['is_placeholder'] );
                        $slide_classes = 'swiper-slide';
                        if ( 'video' === $slide['type'] ) {
                            $slide_classes .= ' tvpg-video-slide';
                        }
                        if ( $is_placeholder ) {
                            $slide_classes .= ' tvpg-placeholder-slide';
                        }
                    ?>
                        <div class="<?php echo esc_attr( $slide_classes ); ?>" role="group" aria-roledescription="<?php esc_attr_e( 'slide', 'true-video-product-gallery' ); ?>">
                            <div class="woocommerce-product-gallery__image">
                            <?php 
                            if ( 'image' === $slide['type'] ) {
                                if ( ! empty( $slide['is_placeholder'] ) || $slide['id'] === 0 ) {
                                    echo apply_filters( 'woocommerce_single_product_image_html', sprintf( '<img src="%s" alt="%s" />', wc_placeholder_img_src( 'woocommerce_single' ), __( 'Placeholder', 'woocommerce' ) ), $slide['id'] );
                                } else {
                                    echo wp_get_attachment_image( $slide['id'], 'woocommerce_single' );
                                }
                            } elseif ( 'video' === $slide['type'] ) {
                                echo wp_kses( $this->get_video_html( $slide['url'], isset($slide['thumb_url']) ? $slide['thumb_url'] : '' ), $this->get_allowed_html() );
                            }
                            ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="swiper-button-next" aria-label="<?php esc_attr_e( 'Next slide', 'true-video-product-gallery' ); ?>"></button>
                <button class="swiper-button-prev" aria-label="<?php esc_attr_e( 'Previous slide', 'true-video-product-gallery' ); ?>"></button>
            </div>

            <!-- Thumbnail Slider -->
            <div class="swiper tvpg-thumb-slider">
                <div class="swiper-wrapper">
                    <?php foreach ( $slides as $index => $slide ) : ?>
                        <div class="swiper-slide <?php echo 'video' === $slide['type'] ? 'tvpg-video-thumb-slide' : ''; ?>">
                            <?php 
                            if ( 'image' === $slide['type'] ) {
                                echo wp_get_attachment_image( $slide['id'], 'woocommerce_gallery_thumbnail' );
                            } elseif ( 'video' === $slide['type'] ) {
                                // Static Thumb (Background)
                                if ( ! empty( $slide['thumb_url'] ) ) {
                                    echo '<img src="' . esc_url( $slide['thumb_url'] ) . '" alt="' . esc_attr__( 'Video Thumbnail', 'true-video-product-gallery' ) . '" style="width:100%;height:100%;object-fit:cover;">';
                                } else {
                                    echo wp_get_attachment_image( $slide['thumb_id'], 'woocommerce_gallery_thumbnail' );
                                }
                                
                                // Live Video Thumbnail
                                // Live Video Thumbnail
                                echo '<div class="tvpg-live-thumb">';
                                echo wp_kses( $this->get_video_thumb_html( $slide['url'] ), $this->get_allowed_html() );
                                echo '</div>';
                                // echo '<span class="tvpg-play-icon"></span>'; // Optional: keep or remove icon if video is playing
                            }
                            ?>
                        </div>
                    <?php endforeach; ?>
            </div>
            </div>
        </div>
        <?php
        // Output Schema.org VideoObject structured data for SEO.
        if ( ! empty( $video_url ) ) {
            $this->output_video_schema( $product, $video_url );
        }
    }

    /**
     * Output Schema.org VideoObject structured data.
     *
     * @since 1.2.0
     * @param WC_Product $product   The product object.
     * @param string     $video_url The video URL.
     * @return void
     */
    private function output_video_schema( $product, $video_url ) {
        $video_info = TVPG_Video_Parser::get_video_info( $video_url );
        if ( ! $video_info ) {
            return;
        }

        $product_name = $product->get_name();
        $product_desc = $product->get_short_description() ?: $product->get_description();
        $product_desc = wp_strip_all_tags( $product_desc );
        $upload_date  = get_the_date( 'c', $product->get_id() );

        // Determine thumbnail URL.
        $thumbnail_url = '';
        $custom_thumb  = get_post_meta( $product->get_id(), '_tvpg_video_thumb_url', true );
        if ( $custom_thumb ) {
            $thumbnail_url = $custom_thumb;
        } elseif ( 'youtube' === $video_info['type'] ) {
            $thumbnail_url = 'https://img.youtube.com/vi/' . $video_info['id'] . '/maxresdefault.jpg';
        } elseif ( 'vimeo' === $video_info['type'] ) {
            $thumbnail_url = TVPG_Video_Parser::get_vimeo_thumbnail( $video_info['id'] );
        } elseif ( $product->get_image_id() ) {
            $thumbnail_url = wp_get_attachment_url( $product->get_image_id() );
        }

        // Build embed URL.
        $embed_url = '';
        if ( 'youtube' === $video_info['type'] ) {
            $embed_url = 'https://www.youtube.com/embed/' . $video_info['id'];
        } elseif ( 'vimeo' === $video_info['type'] ) {
            $embed_url = 'https://player.vimeo.com/video/' . $video_info['id'];
        } elseif ( 'file' === $video_info['type'] ) {
            $embed_url = $video_url;
        }

        $schema = array(
            '@context'     => 'https://schema.org',
            '@type'        => 'VideoObject',
            'name'         => sprintf(
                /* translators: %s: product name */
                __( '%s - Product Video', 'true-video-product-gallery' ),
                $product_name
            ),
            'description'  => $product_desc ?: $product_name,
            'uploadDate'   => $upload_date,
            'contentUrl'   => $embed_url,
            'embedUrl'     => $embed_url,
        );

        if ( $thumbnail_url ) {
            $schema['thumbnailUrl'] = $thumbnail_url;
        }

        ?>
        <script type="application/ld+json">
        <?php echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?>
        </script>
        <?php
    }

    /**
     * Generate video HTML with lazy loading facade for YouTube/Vimeo.
     *
     * For YouTube and Vimeo, renders a thumbnail with play button overlay.
     * The actual iframe is loaded only when the user clicks, improving
     * Core Web Vitals (LCP, TBT) significantly.
     *
     * @since 1.2.0
     * @param string $url    The video URL.
     * @param string $poster Optional custom poster image URL.
     * @return string HTML markup for the video.
     */
    private function get_video_html( $url, $poster = '' ) {
        $defaults = array(
            'autoplay'      => false,
            'loop'          => false,
            'show_controls' => true,
            'mute_autoplay' => true,
            'video_sizing'  => 'contain',
        );
        $settings = wp_parse_args( get_option( 'tvpg_options', array() ), $defaults );

        $info = TVPG_Video_Parser::get_video_info( $url );
        if ( ! $info ) {
            return '';
        }

        $type         = $info['type'];
        $sizing_class = 'tvpg-video-' . ( isset( $settings['video_sizing'] ) ? $settings['video_sizing'] : 'contain' );
        $aria_label   = esc_attr__( 'Product Video', 'true-video-product-gallery' );

        if ( 'youtube' === $type ) {
            $id     = $info['id'];
            $params = array(
                'enablejsapi' => 1,
                'rel'         => 0,
                'autoplay'    => 1,
                'controls'    => $settings['show_controls'] ? 1 : 0,
                'loop'        => $settings['loop'] ? 1 : 0,
                'playlist'    => $settings['loop'] ? $id : '',
            );
            $query     = http_build_query( $params );
            $embed_url = 'https://www.youtube.com/embed/' . esc_attr( $id ) . '?' . $query;
            $thumb_url = $poster ? $poster : 'https://img.youtube.com/vi/' . esc_attr( $id ) . '/maxresdefault.jpg';

            return $this->get_lazy_facade_html( $embed_url, $thumb_url, $sizing_class, $aria_label, 'youtube' );

        } elseif ( 'vimeo' === $type ) {
            $id     = $info['id'];
            $params = array(
                'autoplay'  => 1,
                'loop'      => $settings['loop'] ? 1 : 0,
                'controls'  => $settings['show_controls'] ? 1 : 0,
            );
            $query     = http_build_query( $params );
            $embed_url = 'https://player.vimeo.com/video/' . esc_attr( $id ) . '?' . $query;
            
            // Fetch Vimeo thumbnail via oEmbed API (with caching).
            $thumb_url = $poster ? $poster : TVPG_Video_Parser::get_vimeo_thumbnail( $id );

            return $this->get_lazy_facade_html( $embed_url, $thumb_url, $sizing_class, $aria_label, 'vimeo' );

        } elseif ( 'tiktok' === $type ) {
            // TikTok uses oEmbed - render as blockquote that TikTok's embed.js will process.
            $video_url = esc_url( $info['url'] );
            return '<div class="tvpg-responsive-video tvpg-tiktok ' . esc_attr( $sizing_class ) . '">
                <blockquote class="tiktok-embed" cite="' . $video_url . '" data-video-id="' . esc_attr( $info['id'] ) . '" style="max-width: 605px; min-width: 325px;">
                    <section></section>
                </blockquote>
                <script async src="https://www.tiktok.com/embed.js"></script>
            </div>';

        } elseif ( 'instagram' === $type ) {
            // Instagram uses oEmbed - render as blockquote.
            $instagram_url = 'https://www.instagram.com/p/' . esc_attr( $info['id'] ) . '/';
            return '<div class="tvpg-responsive-video tvpg-instagram ' . esc_attr( $sizing_class ) . '">
                <blockquote class="instagram-media" data-instgrm-permalink="' . esc_url( $instagram_url ) . '" data-instgrm-version="14" style="max-width:540px; width:100%;">
                </blockquote>
                <script async src="//www.instagram.com/embed.js"></script>
            </div>';

        } elseif ( 'file' === $type ) {
            $controls    = $settings['show_controls'] ? 'controls' : '';
            $loop        = $settings['loop'] ? 'loop' : '';
            $muted       = ( $settings['autoplay'] && $settings['mute_autoplay'] ) ? 'muted' : '';
            $poster_attr = $poster ? 'poster="' . esc_url( $poster ) . '"' : '';
            $object_fit  = $settings['video_sizing'] === 'cover' ? 'cover' : 'contain';

            return '<video ' . $controls . ' ' . $loop . ' ' . $muted . ' ' . $poster_attr . ' src="' . esc_url( $info['url'] ) . '" style="width:100%;height:100%;object-fit:' . esc_attr( $object_fit ) . '" aria-label="' . $aria_label . '"></video>';
        }

        return '';
    }

    /**
     * Generate a lazy loading facade (thumbnail + play button).
     *
     * The actual iframe is injected via JavaScript when the user clicks.
     * This defers loading of third-party scripts until user interaction.
     *
     * @since 1.2.0
     * @param string $embed_url   The full embed URL with parameters.
     * @param string $thumb_url   URL to the thumbnail image.
     * @param string $sizing_class CSS class for video sizing.
     * @param string $aria_label  Accessible label for the video.
     * @param string $provider    Video provider identifier (youtube/vimeo).
     * @return string HTML markup for the lazy facade.
     */
    private function get_lazy_facade_html( $embed_url, $thumb_url, $sizing_class, $aria_label, $provider ) {
        $play_label = esc_attr__( 'Play Video', 'true-video-product-gallery' );

        $html  = '<div class="tvpg-responsive-video tvpg-lazy-facade ' . esc_attr( $sizing_class ) . '" data-embed-url="' . esc_url( $embed_url ) . '" data-provider="' . esc_attr( $provider ) . '">';
        
        if ( $thumb_url ) {
            $html .= '<img src="' . esc_url( $thumb_url ) . '" alt="' . $aria_label . '" class="tvpg-facade-thumb" loading="lazy">';
        } else {
            // Fallback gradient background for Vimeo without poster.
            $html .= '<div class="tvpg-facade-placeholder"></div>';
        }
        
        $html .= '<button type="button" class="tvpg-play-button" aria-label="' . $play_label . '">';
        $html .= '<svg viewBox="0 0 68 48" width="68" height="48"><path class="tvpg-play-bg" d="M66.52,7.74c-0.78-2.93-2.49-5.41-5.42-6.19C55.79,.13,34,0,34,0S12.21,.13,6.9,1.55 C3.97,2.33,2.27,4.81,1.48,7.74C0.06,13.05,0,24,0,24s0.06,10.95,1.48,16.26c0.78,2.93,2.49,5.41,5.42,6.19 C12.21,47.87,34,48,34,48s21.79-0.13,27.1-1.55c2.93-0.78,4.64-3.26,5.42-6.19C67.94,34.95,68,24,68,24S67.94,13.05,66.52,7.74z" fill="#212121" fill-opacity="0.8"></path><path d="M 45,24 27,14 27,34" fill="#fff"></path></svg>';
        $html .= '</button>';
        $html .= '</div>';

        return $html;
    }

    private function get_video_thumb_html( $url ) {
        $info = TVPG_Video_Parser::get_video_info( $url );
        if ( ! $info ) return '';

        $type = $info['type'];
        $aria_label = esc_attr__( 'Video Thumbnail', 'true-video-product-gallery' );

        if ( 'youtube' === $type ) {
            $id = $info['id'];
            // playlist=ID is required for loop to work on single video
            return '<iframe src="https://www.youtube.com/embed/' . esc_attr( $id ) . '?autoplay=1&mute=1&controls=0&loop=1&playlist=' . esc_attr( $id ) . '&end=9999&showinfo=0&modestbranding=1" frameborder="0" loading="lazy" title="' . $aria_label . '" tabindex="-1" aria-hidden="true"></iframe>';
        }

        if ( 'vimeo' === $type ) {
            $id = $info['id'];
            return '<iframe src="https://player.vimeo.com/video/' . esc_attr( $id ) . '?background=1&autoplay=1&loop=1&byline=0&title=0&muted=1" frameborder="0" loading="lazy" title="' . $aria_label . '" tabindex="-1" aria-hidden="true"></iframe>';
        }

        if ( 'file' === $type ) {
            return '<video src="' . esc_url( $info['url'] ) . '" autoplay loop muted playsinline style="width:100%;height:100%;object-fit:cover;" aria-label="' . $aria_label . '" tabindex="-1" aria-hidden="true"></video>';
        }

        return '';
    }

    public function custom_thumbnail_html( $html, $attachment_id ) {
        // Optional: Filter default HTML if we were using the default template. 
        // Since we replaced the template, we might not need this unless other plugins use it.
        return $html;
    }

    private function get_allowed_html() {
        return array(
            'div' => array(
                'class' => array(),
                'style' => array(),
            ),
            'iframe' => array(
                'src' => array(),
                'width' => array(),
                'height' => array(),
                'frameborder' => array(),
                'allowfullscreen' => array(),
                'style' => array(),
                'title' => array(),
                'class' => array(),
                'loading' => array(),
            ),
            'video' => array(
                'src' => array(),
                'poster' => array(),
                'width' => array(),
                'height' => array(),
                'style' => array(),
                'controls' => array(),
                'loop' => array(),
                'muted' => array(),
                'autoplay' => array(),
                'playsinline' => array(),
                'class' => array(),
                'aria-label' => array(),
                'aria-hidden' => array(),
                'tabindex' => array(),
            ),
        );
    }

    /**
     * Register the shortcode.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_shortcode() {
        add_shortcode( 'tvpg_gallery', array( $this, 'render_shortcode' ) );
    }

    /**
     * Render the shortcode.
     *
     * @since 1.0.0
     * @since 1.2.0 Added product_id attribute support.
     * @param array $atts Shortcode attributes.
     * @return string Gallery HTML.
     */
    public function render_shortcode( $atts = array() ) {
        $atts = shortcode_atts(
            array(
                'product_id' => 0,
            ),
            $atts,
            'tvpg_gallery'
        );

        $product_id = absint( $atts['product_id'] );

        // If product_id is specified, temporarily set up the product context.
        if ( $product_id > 0 ) {
            global $post, $product;
            $original_post    = $post;
            $original_product = $product;

            $post    = get_post( $product_id );
            $product = wc_get_product( $product_id );

            if ( ! $product ) {
                return '<!-- TVPG: Invalid product ID -->';
            }

            setup_postdata( $post );
        }

        ob_start();
        $this->render_gallery();
        $output = ob_get_clean();

        // Restore original context if we changed it.
        if ( $product_id > 0 ) {
            $post    = $original_post;
            $product = $original_product;
            if ( $original_post ) {
                setup_postdata( $original_post );
            }
        }

        return $output;
    }

    /**
     * Add video data to variation data for frontend.
     *
     * Checks if "use same video for all" is enabled - if so, uses the main
     * product video. Otherwise, uses the variation-specific video if set.
     *
     * @since 1.0.0
     * @since 1.2.0 Added support for "use same video for all" setting.
     * @param array      $data      Variation data array.
     * @param WC_Product $product   Parent product object.
     * @param WC_Product $variation Variation product object.
     * @return array Modified variation data.
     */
    public function add_variation_video_data( $data, $product, $variation ) {
        // Check if "use same video for all" is enabled on the parent product.
        $use_same_for_all = get_post_meta( $product->get_id(), '_tvpg_use_same_video', true );
        
        if ( 'yes' === $use_same_for_all ) {
            // Use the main product video and thumbnail for all variations.
            $video_url = get_post_meta( $product->get_id(), '_tvpg_video_url', true );
            $thumb_url = get_post_meta( $product->get_id(), '_tvpg_video_thumb_url', true );
        } else {
            // Use variation-specific video and thumbnail if set.
            $video_url = get_post_meta( $variation->get_id(), '_tvpg_video_url', true );
            $thumb_url = get_post_meta( $variation->get_id(), '_tvpg_video_thumb_url', true );
            
            // Fall back to main product thumbnail if variation has no custom thumbnail.
            if ( empty( $thumb_url ) ) {
                $thumb_url = get_post_meta( $product->get_id(), '_tvpg_video_thumb_url', true );
            }
        }

        if ( ! empty( $video_url ) ) {
            $data['tvpg_video_html']       = wp_kses( $this->get_video_html( $video_url, $thumb_url ), $this->get_allowed_html() );
            $data['tvpg_video_thumb_html'] = wp_kses( $this->get_video_thumb_html( $video_url ), $this->get_allowed_html() );
        }
        return $data;
    }
}
