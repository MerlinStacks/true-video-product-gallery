
# Changelog

## [1.6.0] - 2026-04-07
### Compatibility
- Updated for WordPress 6.8+ and WooCommerce 9.x
- Declared WooCommerce Cart/Checkout Blocks feature compatibility
- Block editor dependency updated from deprecated `wp-edit-post` to `wp-editor` (WP 6.6+)
- `PluginDocumentSettingPanel` import uses `wp.editor` with `wp.editPost` fallback

### Improved
- Script deferral now uses native WP 6.3+ `strategy` API instead of `script_loader_tag` filter
- Admin notices use `wp_admin_notice()` API (WP 6.4+)
- Replaced deprecated HTML `frameborder` attribute with CSS `border:none` on all iframes
- Settings page Button uses `variant: 'primary'` instead of deprecated `isPrimary` prop

### Changed
- Minimum PHP version bumped to 8.0 (PHP 7.4 reached EOL Nov 2022)
- Minimum WordPress version bumped to 6.4
- Minimum WooCommerce version bumped to 8.0

## [1.5.5] - 2026-03-05
### Fixed
- **Thumbnail Playback**: Restored live video playback in thumbnails using canvas mirroring — draws frames from the main `<video>` via `requestAnimationFrame` with zero extra bandwidth.
- **Thumbnail Sharpness**: Upgraded image thumbnail source from `woocommerce_gallery_thumbnail` (100×100) to `woocommerce_thumbnail` (~300×300) for retina displays; YouTube thumbnails bumped from `mqdefault` to `hqdefault`.
- **Navigation Arrows**: Swiper's default `::after` pseudo-element text hidden; inline SVG arrows display correctly.
- **Single-Slide Navigation**: Navigation arrows and thumbnail strip hidden when a product has only one gallery slide.
- **Zoom Cursor**: `cursor: zoom-in` on gallery images only applied when lightbox is enabled.
- **Video fetchpriority**: `fetchpriority` added to `<video>` tag `wp_kses` whitelist so the attribute is no longer stripped.

## [1.5.4] - 2026-03-05
### Fixed
- **Thumbnail Playback**: Restored live video playback in thumbnails using canvas mirroring — draws frames from the main `<video>` via `requestAnimationFrame` with zero extra bandwidth.
- **Thumbnail Blurriness**: Upgraded image thumbnails from `woocommerce_gallery_thumbnail` (100×100) to `woocommerce_thumbnail` (~300×300) for sharp rendering on retina displays.
- **YouTube Thumbnail Resolution**: Upgraded YouTube thumbnail images from `mqdefault` (320×180) to `hqdefault` (480×360).

## [1.5.3] - 2026-03-05
### Fixed
- **Navigation Arrows**: Swiper's default `::after` pseudo-element text ("next"/"prev") now hidden; inline SVG arrows display correctly.
- **Single-Slide Navigation**: Navigation arrows and thumbnail strip are now hidden when a product has only one gallery slide.
- **Zoom Cursor**: `cursor: zoom-in` on gallery images is now conditional — only applied when the lightbox setting is enabled.
- **Video fetchpriority**: Added `fetchpriority` to the `<video>` tag `wp_kses` whitelist so the `fetchpriority="low"` attribute is no longer silently stripped.

## [1.5.2] - 2026-02-27
### Improved
- **Page Speed — Deferred Scripts**: Swiper and frontend JS now load with `defer`, removing ~90KB of render-blocking JavaScript (improves FCP and TBT).
- **Page Speed — Preconnect Hints**: Upgraded `dns-prefetch` to `preconnect` for YouTube thumbnail CDN; added Vimeo CDN (`i.vimeocdn.com`). Warms DNS + TCP + TLS ahead of time.
- **Page Speed — LCP Image Priority**: First gallery image now carries `fetchpriority="high"`, `loading="eager"`, and `decoding="sync"` to accelerate Largest Contentful Paint.
- **Page Speed — Eager Thumbnails**: First thumbnail loads eagerly instead of lazy to prevent above-fold delay.
- **Page Speed — Video Deprioritisation**: Self-hosted `<video>` elements now carry `fetchpriority="low"` so the browser downloads product images first.

## [1.5.1] - 2026-02-18
### Fixed
- **Build Tooling**: Fixed plugin zip archive using Windows backslash paths which caused "Plugin file does not exist" errors on Linux servers.

## [1.5.0] - 2026-02-15
### Changed
- Version bump for deployment with all 1.4.0 improvements.

## [1.4.0] - 2026-02-15
### Added
- **WooCommerce Block Editor (Gutenberg) Integration**: Video panel now works in the new block-based product editor via registered meta fields and a custom block type.
- **Progressive Web-Optimised Embeds**: Lazy facade pattern extended to TikTok and Instagram with thumbnail-first loading.
- **DNS Prefetch Resource Hints**: Automatic `dns-prefetch` for video provider thumbnail domains (zero-cost connection warming).
- **Admin UI Tabs**: Fast-loading tabbed interface for settings to improve navigation.

### Changed
- **Admin CSS/JS Scoping**: All admin assets now scoped to prevent bleed into other WordPress admin pages.
- **Responsive Admin Breakpoints**: Improved mobile and tablet display for the settings interface.

### Fixed
- Multiple bugs identified and resolved during comprehensive code audit.

## [1.3.0] - 2026-02-13
### Added
- **REST API Video Parser**: New `POST /wp-json/tvpg/v1/parse-video` endpoint eliminates duplicated regex patterns in admin JavaScript.
- **Conditional Swiper Loading**: Swiper library only loads when the gallery contains more than one slide (IMP-05).

### Changed
- **Frontend Class Decomposition**: Monolithic `TVPG_Frontend` decomposed into `TVPG_Gallery_Renderer`, `TVPG_Video_Embed`, and `TVPG_Schema` classes.
- **Template Rendering Fix**: Removed direct `render_gallery` action hook that caused double rendering on standard WC template themes (BUG-02).

### Fixed
- **Lazy Facade**: Fixed broken lazy loading facade that failed to display play button overlay.
- **Vimeo ID Parsing**: Corrected regex that failed on certain Vimeo URL formats.
- **Variation Video Persistence**: Fixed edge cases where variation video data was lost during saves.
- **Security**: Additional nonce verification and capability check hardening.

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
