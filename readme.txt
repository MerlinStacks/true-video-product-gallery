=== True Video Product Gallery ===
Contributors: sldevs
Donate link: https://sldevs.com
Tags: woocommerce, video, product gallery, youtube, vimeo, tiktok, instagram
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Replace the default WooCommerce product gallery with a powerful video-capable slider supporting YouTube, Vimeo, TikTok, Instagram Reels, and self-hosted videos.

== Description ==

**True Video Product Gallery** modernizes your WooCommerce product pages by replacing the default gallery with a robust, video-capable slider.

= Key Features =

* **Multi-Platform Video Support** – YouTube, Vimeo, TikTok, Instagram Reels, and self-hosted MP4/WebM/OGG
* **Lazy Loading Facade** – Displays thumbnail with play button; iframe loads on click for best Core Web Vitals
* **Automatic Vimeo Thumbnails** – Fetches thumbnails via oEmbed API with 24-hour caching
* **Variation Video Support** – Assign different videos to each product variation
* **"Use Same Video for All"** – One checkbox to apply main video to all variations
* **Custom Thumbnails** – Override auto-generated thumbnails with your own images
* **Video Preload Strategy** – Choose Lazy, Metadata, or Auto preloading
* **Shortcode with product_id** – `[tvpg_gallery product_id="123"]` works anywhere
* **REST API** – GET and POST endpoints for settings management
* **Theme Compatibility** – Works with Flatsome and most WooCommerce themes
* **Modern Admin UI** – React-based settings dashboard

= Requirements =

* WordPress 6.4+
* WooCommerce 8.0+
* PHP 8.0+

== Installation ==

1. Upload the plugin to `/wp-content/plugins/true-video-product-gallery` or install via WordPress Plugins screen.
2. Activate the plugin through the 'Plugins' screen.
3. Go to **True Video Gallery** in the admin menu to configure settings.
4. Edit any WooCommerce product and use the **Product Video** tab to add videos.

== Frequently Asked Questions ==

= Does this work with variable products? =
Yes! You can assign videos to individual variations or use the "Use same video for all variations" checkbox.

= What video platforms are supported? =
YouTube, Vimeo, TikTok, Instagram Reels, and self-hosted MP4/WebM/OGG files.

= Can I use the gallery outside of product pages? =
Yes, use the shortcode `[tvpg_gallery product_id="123"]` anywhere.

= Does this affect page speed? =
No! The lazy loading facade ensures YouTube/Vimeo iframes only load when clicked, improving Core Web Vitals.

= Is WooCommerce required? =
Yes, this plugin requires WooCommerce to be installed and active.

== Screenshots ==

1. Frontend gallery with video slide
2. Product Video tab in WooCommerce product editor
3. Variation videos table with custom thumbnails
4. Global settings page with preload options

== Changelog ==

= 1.6.0 =
* COMPATIBILITY: Updated for WordPress 6.8+ and WooCommerce 9.x
* COMPATIBILITY: Declared WooCommerce Cart/Checkout Blocks compatibility
* IMPROVED: Script deferral now uses native WP 6.3+ strategy API instead of script_loader_tag filter
* IMPROVED: Block editor dependency updated from deprecated wp-edit-post to wp-editor (WP 6.6+)
* IMPROVED: Admin notices use wp_admin_notice() API (WP 6.4+)
* IMPROVED: Replaced deprecated HTML frameborder attribute with CSS border:none on iframes
* CHANGED: Minimum PHP version bumped to 8.0 (PHP 7.4 reached EOL Nov 2022)
* CHANGED: Minimum WordPress version bumped to 6.4
* CHANGED: Minimum WooCommerce version bumped to 8.0

= 1.5.3 =
* FIXED: Swiper navigation arrows showing "next"/"prev" text instead of SVG icons
* FIXED: Navigation arrows and thumbnail strip visible on single-image products
* FIXED: Zoom cursor shown on gallery images even when lightbox is disabled
* FIXED: fetchpriority attribute silently stripped from video tags by wp_kses

= 1.5.2 =
* IMPROVED: Deferred Swiper + frontend JS to remove ~90KB of render-blocking scripts
* IMPROVED: Upgraded dns-prefetch to preconnect for YouTube/Vimeo thumbnail CDNs
* IMPROVED: First gallery image marked as LCP candidate with fetchpriority="high" and eager loading
* IMPROVED: First thumbnail loads eagerly for above-fold rendering
* IMPROVED: Self-hosted video elements deprioritised with fetchpriority="low"

= 1.5.1 =
* FIXED: Plugin zip archive now uses forward-slash paths, fixing "Plugin file does not exist" on Linux servers

= 1.5.0 =
* Version bump for deployment with all 1.4.0 improvements

= 1.4.0 =
* NEW: WooCommerce Block Editor (Gutenberg) integration — video panel works in both classic and block editors
* NEW: Progressive web-optimised embeds with lazy facades for YouTube, Vimeo, TikTok, Instagram
* NEW: DNS prefetch resource hints for video provider thumbnail domains
* IMPROVED: Admin UI with fast-loading tabs and scoped CSS/JS to prevent bleed into other pages
* IMPROVED: Responsive admin breakpoints for mobile and tablet
* FIXED: Multiple bug fixes from comprehensive code audit

= 1.3.0 =
* NEW: REST API video URL parsing endpoint (eliminates duplicated regex in admin JS)
* IMPROVED: Decomposed monolithic frontend class into dedicated Gallery Renderer, Video Embed, and Schema classes
* IMPROVED: Conditional Swiper loading — only loads when gallery has more than one slide
* IMPROVED: CSS scoping and asset isolation for admin pages
* SECURITY: Additional nonce and capability hardening
* FIXED: Double gallery rendering on standard WC template themes
* FIXED: Broken lazy facade, incorrect Vimeo ID parsing
* FIXED: Variation video persistence edge cases

= 1.2.1 =
* Minor code quality improvements and optimizations
* Updated WordPress compatibility to 6.7

= 1.2.0 =
* NEW: TikTok and Instagram Reel video support
* NEW: Vimeo thumbnail auto-fetch via oEmbed API
* NEW: Lazy loading facade for YouTube/Vimeo (better performance)
* NEW: Video preload strategy setting (Lazy/Metadata/Auto)
* NEW: Custom thumbnail per variation
* NEW: Centralized variation videos in Product Video tab
* NEW: "Use same video for all variations" checkbox
* NEW: Shortcode product_id attribute support
* NEW: REST API GET endpoint
* NEW: WooCommerce dependency check with admin notice
* SECURITY: Added nonce verification, capability checks, URL validation
* IMPROVED: Centralized settings class, reduced code duplication
* IMPROVED: Complete PHPDoc documentation

= 1.1.0 =
* ADDED: Flatsome Theme Compatibility
* ADDED: Shortcode support and hijacking
* FIXED: Variation video persistence issues

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.2.0 =
Major update with TikTok/Instagram support, performance improvements, security fixes, and new settings. Recommended for all users.
