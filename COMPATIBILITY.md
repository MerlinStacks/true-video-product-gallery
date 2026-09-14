# Plugin Compatibility & Troubleshooting Guide

## Known Potential Conflicts

### 1. Theme Compatibility (Hardcoded Galleries)
Some themes (e.g. Divi, Flatsome, or custom themes) do not use the standard WooCommerce hooks to output the product gallery.
*   **Symptom**: You see two galleries (ours and the theme's) or the gallery appears fast below the fold.
*   **Cause**: The theme ignores `remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 )`.
*   **Fix (General)**: You may need to use a Child Theme to manually unhook the theme's specific gallery function.
*   **Fix (Flatsome Specific)**: This plugin has built-in "High Priority" modes for Flatsome:
    *   **Shortcode Hijacking**: We force our gallery when `[ux_product_gallery]` is used.
    *   **MutationObserver Enforcer**: We use a MutationObserver to detect and restore video content when Flatsome's aggressive DOM rewriting overwrites the gallery.
    *   **Global Event Listeners**: We listen for variation changes globally on the document to bypass theme-specific form wrapping or event suppression.

### 1.1 Flatsome Product Category / Shop Cards (Archive Hover Video)
Flatsome loop cards can bypass standard WooCommerce thumbnail filters.
*   **Implementation**: This plugin uses a dual strategy for archive media swap:
    *   WooCommerce image filters (`post_thumbnail_html`, `woocommerce_product_get_image`) where available.
    *   A per-card fallback payload (`.tvpg-loop-secondary-template`) that frontend JS injects into Flatsome's visible image container.
*   **Result**: Hover video and mobile in-view preview work in category/shop cards even when Flatsome rewrites card markup.

### 2. Elementor Pro / Page Builders
If you use Elementor Pro's "Product Images" widget, it uses its own internal renderer and ignores standard WooCommerce gallery plugins.
*   **Fix**: Do not use the "Product Images" widget. Instead, use the "Product Content" widget or a specific "WooCommerce Hook" widget that renders the `woocommerce_before_single_product_summary` hook.

### 3. AJAX / Quick View Plugins
Our gallery initializes when the page loads (via deferred vanilla JS).
*   **Symptom**: The gallery looks broken or empty in a "Quick View" popup.
*   **Cause**: The popup content is loaded via AJAX after our script has already run, so Swiper never gets initialised for the injected DOM.
*   **Fix**: After the popup content is inserted, trigger a global re-init by dispatching a custom event:
    ```javascript
    document.dispatchEvent( new Event( 'tvpg-init-gallery' ) );
    ```
    This event initializes existing markup; it does not download missing assets. Quick-view integrations must enqueue the gallery CSS, frontend controller and Swiper when needed. Ordinary category pages intentionally load only archive assets. With gallery CSS present but Swiper unavailable, the gallery uses its basic CSS fallback.

Archive cards inserted into an existing product grid are initialized automatically. For a separately inserted grid, call `window.tvpgInitArchive(rootElement)` after insertion; initialization is idempotent.

### 4. Personalization Plugins (e.g. Zakeke, PPOM, Personalise It)
*   **Status**: **Compatible**.
*   We have added the standard `.woocommerce-product-gallery` and `.woocommerce-product-gallery__image` classes to our structure. These plugins should correctly identify the active slide image and overlay their preview canvas on top of it.

### 5. Zoom & Lightbox Plugins
*   **Note**: This plugin **disables** the default WooCommerce Zoom and Lightbox.
*   **Reason**: Video players inside a zoom lens or lightbox are complex and often buggy. To ensure a smooth video experience, we replace the default interaction with our own slider.
*   **Conflict**: If you have another plugin explicitly for "Image Zoom", it will likely not work or conflict with our swipe behavior.

### 6. Reel It - Video Slider Plugin
*   **Status**: **CRITICAL CONFLICT**.
*   **Conflict**: If you have the "Reel It" plugin installed, it will conflict with this plugin as both attempt to overwrite the main product gallery.
*   **Fix**: Disable "Reel It" when using True Video Product Gallery.

## Performance & Caching Notes

### LiteSpeed Cache / WP Rocket
*   **Issue**: "Delay JavaScript Execution" or "Defer JS".
*   **Symptom**: The gallery layout might shift or the "Click to Pause" video feature might not work immediately.
*   **Fix**: Exclude `tvpg-frontend.js` from "Delay execution" lists if you experience interactivity issues.

### Archive Video Performance (SEO / Core Web Vitals)
#### Automatic category preview videos
Upload/select the product video once in **Product Video** settings and save the product. No separate category upload is required. With archive swapping enabled, shop/category cards automatically use a generated lightweight preview when ready, while product pages retain the original video. Existing products can queue generation when they appear in an archive. The previous manual preview field has been removed and its stored value is ignored.

Generation uses the first **up to eight seconds**, removes audio, limits the longest side to **480 pixels** without upscaling, and produces a **24 fps H.264 MP4** with faststart. Only a successfully decoded output smaller than the original is used. All eligible visible videos may continue playing together. Processing is asynchronous, never run as part of rendering a category page.

**Hosting requirements:** PHP `proc_open`, writable uploads and temporary directories, working Action Scheduler or WP-Cron, and FFmpeg with `libx264` and the seekable **`fd` input protocol**. FFmpeg 7.0.2 was tested; an older or differently built binary without the required capabilities will retain the original. The plugin does not install FFmpeg. A hosting administrator can point to a trusted executable in `wp-config.php`:

```php
define( 'TVPG_FFMPEG_PATH', '/usr/local/bin/ffmpeg' );
```

Only verified local WordPress video attachments physically inside uploads are processed (maximum 512 MB; MP4/M4V/MOV/WebM/MKV/AVI containers). YouTube, Vimeo, remote/offloaded-only media and unsupported files continue using the original playback path. The original also remains in use while queued, when output is not smaller, or if hosting/processing is unavailable. The classic editor displays the saved video's processing status; refresh after the job runs. Save/update the product again to retry a terminal failure after repairing hosting configuration.

One encoder runs at a time per filesystem lock, with bounded runtime, threads, output size and retries. Multi-host workers must use the same effective lock file: both the temporary directory and `ABSPATH` must match, because the filename hashes `ABSPATH`. Derivatives are stored under `uploads/tvpg-previews/`, reused for a shared source, pruned after replacement generation, and removed on attachment deletion/uninstall. Deactivation cancels jobs but retains completed previews. Hosting page caches may continue serving the original until refreshed; avoid aggressive global cache purges for each completed preview.

URL-to-attachment mappings are cached for 24 hours for resolved IDs and one hour for misses. Expired mappings temporarily use the original while background resolution runs, even if a completed derivative exists. Attachment URL lookup is not performed while rendering category cards.

#### Loading and caching
*   **Deferred media**: Secondary image, native video and poster URLs are assigned only when a preview is activated. The primary product image remains visible while the preview loads. Playback still downloads media; `preload="none"` is not a bandwidth cap.
*   **Simultaneous previews**: Touch devices retain simultaneous playback for sufficiently visible cards, with no video concurrency limit. Desktop retains hover playback. Offscreen cards and hidden tabs pause playback, but pausing does not necessarily cancel provider buffering.
*   **Reduced motion**: Automatic viewport previews respect reduced-motion preferences; intentional interactions remain available.
*   **Slow network heuristic**: Archive media swap is automatically disabled when the browser reports Data Saver enabled (`navigator.connection.saveData`) or slow connection classes (`slow-2g`, `2g`, `3g`).
*   **Vimeo thumbnails**: Cache misses use a fallback and schedule a background refresh. WP-Cron must run for thumbnails to refresh; existing successful values can be served while refreshing.
*   **Deployment**: Purge full-page and optimization/CDN caches after updating so deferred markup and the matching versioned scripts are delivered together.

### Regression checks
Run `npm ci`, `npm run build`, `npm run test:js` and `composer test`. The Composer command runs the normal PHP suite and the separately bootstrapped generator suite. Set `TVPG_WP_TEST_PATH` to a WordPress checkout to also exercise its real HTML tag processor, and `TVPG_TEST_REAL_FFMPEG` to a trusted absolute FFmpeg path to enable real encoding tests (matching `ffprobe` beside it is required). Without those tools, integration checks are explicitly skipped. Browser tests use DOM/media doubles, not live provider playback. Before release, verify category scrolling/hover, background tabs, AJAX filtering, variable products and keyboard/lightbox behaviour on the actual storefront, and compare cold/warm network waterfalls.
