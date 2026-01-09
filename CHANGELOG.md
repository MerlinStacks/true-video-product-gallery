# Changelog

## [1.2.1] - 2026-01-09
### Changed
- Minor code quality improvements and optimizations
- Updated WordPress compatibility to 6.7

## [1.2.0] - 2026-01-09
### Added
- **TikTok & Instagram Reel Support**: Video parser now recognizes TikTok and Instagram Reel URLs.
- **Vimeo Thumbnail Auto-Fetch**: Thumbnails are now fetched via Vimeo's oEmbed API with 24-hour caching.
- **Lazy Loading Facade**: YouTube/Vimeo videos display a thumbnail with play button; iframe loads on click for improved Core Web Vitals.
- **Video Preload Strategy Setting**: Choose between Lazy (facade), Metadata, or Auto preloading.
- **Custom Thumbnail per Variation**: Each variation can now have its own custom video thumbnail.
- **Centralized Variation Videos**: Moved variation video management from individual variation panels to a unified table in the Product Video tab.
- **"Use Same Video for All Variations"**: New checkbox to apply the main product video to all variations.
- **Shortcode Enhancements**: `[tvpg_gallery product_id="123"]` now works outside of product pages.
- **REST API GET Endpoint**: `GET /wp-json/tvpg/v1/settings` returns current settings and plugin version.
- **WooCommerce Dependency Check**: Admin notice displayed if WooCommerce is not active.
- **HPOS Compatibility**: Declared compatible with WooCommerce High-Performance Order Storage.
- **Schema.org VideoObject**: Structured data output for SEO/rich snippets.
- **Accessibility Improvements**: ARIA labels on gallery, carousel roles, semantic button elements.
- **Minified Assets**: Production-ready `.min.css` and `.min.js` files for better performance.
- **PHPDoc Documentation**: Complete documentation for all PHP classes and methods.

### Changed
- **Centralized Settings Class**: New `TVPG_Settings` class provides single source of truth for defaults.
- **Template Singleton Pattern**: `product-image.php` now uses `TVPG_Loader::get_frontend()` instead of instantiating a new object.
- **Version Bump**: Updated to 1.2.0 with `Requires Plugins: woocommerce` header.

### Fixed
- **Security**: Added nonce verification to variation saves.
- **Security**: Replaced `sanitize_text_field()` with `esc_url_raw()` for URL inputs.
- **Security**: Added capability checks to meta save functions.
- **Security**: Implemented allowlist validation for select settings (sizing, position, preload).
- **Code Quality**: Added ABSPATH checks to all PHP files.
- **Code Quality**: Removed console.log statements from production JavaScript.
- **Code Quality**: Removed 53 lines of dead code (deprecated variation panel methods).

### Removed
- **Deprecated**: Variation video fields in individual variation panels (now in centralized tab).

## [1.1.0] - 2025-12-19
### Added
- **Flatsome Theme Compatibility**: Introduced strict "Pulse Check" state enforcement to prevent theme JavaScript from overwriting video slides.
- **Global Variation Listener**: Implemented a global `found_variation` event listener to ensure variation changes are detected even when themes suppress or relocate standard WooCommerce form events.
- **Shortcode Hijacking**: Added aggressive interception for `[ux_product_gallery]` shortcode to force the True Video Gallery in page builder layouts.
- **Variation Data Injection**: Added `woocommerce_available_variation` filter to explicitly inject video HTML data into frontend variation objects, resolving data transport issues.

### Fixed
- **Variation Video Persistence**: Fixed a critical issue where selecting a variation would revert the video slide to a static image in certain themes (Flatsome).
- **Video Playback**: Improved video state restoration logic to ensure videos resume playing if they are re-injected into the DOM.
- **Syntax**: Fixed a syntax error in the frontend JavaScript variation handler.

## [1.0.0] - 2025-12-18
### Initial Release
- Core video gallery functionality.
- Support for YouTube, Vimeo, and local MP4 videos.
- Variation-specific video support.
- Custom admin interface for video management.
- Responsive Swiper-based slider.
