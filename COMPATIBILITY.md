# Plugin Compatibility & Troubleshooting Guide

## Known Potential Conflicts

### 1. Theme Compatibility (Hardcoded Galleries)
Some themes (e.g. Divi, Flatsome, or custom themes) do not use the standard WooCommerce hooks to output the product gallery.
*   **Symptom**: You see two galleries (ours and the theme's) or the gallery appears fast below the fold.
*   **Cause**: The theme ignores `remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 )`.
*   **Fix (General)**: You may need to use a Child Theme to manually unhook the theme's specific gallery function.
*   **Fix (Flatsome Specific)**: This plugin has built-in "High Priority" modes for Flatsome:
    *   **Shortcode Hijacking**: We force our gallery when `[ux_product_gallery]` is used.
    *   **Pulse & Enforcer**: We use a global state enforcer ("Pulse") that checks every 500ms to ensure the video content persists. This counteracts Flatsome's aggressive DOM rewriting.
    *   **Global Event Listeners**: We listen for variation changes globally on the document to bypass theme-specific form wrapping or event suppression.

### 2. Elementor Pro / Page Builders
If you use Elementor Pro's "Product Images" widget, it uses its own internal renderer and ignores standard WooCommerce gallery plugins.
*   **Fix**: Do not use the "Product Images" widget. Instead, use the "Product Content" widget or a specific "WooCommerce Hook" widget that renders the `woocommerce_before_single_product_summary` hook.

### 3. AJAX / Quick View Plugins
Our gallery initializes when the page loads (`$(document).ready`).
*   **Symptom**: The gallery looks broken or empty in a "Quick View" popup.
*   **Cause**: The popup content is loaded via AJAX after our script has already run.
*   **Fix**: You need to trigger the init code when the popup opens. (We listen for standard window resize events, so triggering a resize often fixes it).

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

