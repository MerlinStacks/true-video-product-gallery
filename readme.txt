=== True Video Product Gallery ===
Contributors: sldevs
Donate link: https://sldevs.com
Tags: woocommerce, video, product gallery, youtube, vimeo, tiktok, instagram
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.1
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

* WordPress 6.0+
* WooCommerce 7.0+
* PHP 7.4+

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
