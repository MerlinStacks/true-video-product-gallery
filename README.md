# True Video Product Gallery

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-purple.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Replace the default WooCommerce product gallery with a powerful video-capable slider supporting YouTube, Vimeo, TikTok, Instagram Reels, and self-hosted videos.

## ✨ Features

- **Multi-Platform Video Support** – YouTube, Vimeo, TikTok, Instagram Reels, and self-hosted MP4/WebM/OGG
- **Lazy Loading Facade** – Displays thumbnail with play button; iframe loads on click for best Core Web Vitals
- **Automatic Vimeo Thumbnails** – Fetches thumbnails via oEmbed API with 24-hour caching
- **Variation Video Support** – Assign different videos to each product variation
- **"Use Same Video for All"** – One checkbox to apply main video to all variations
- **Custom Thumbnails** – Override auto-generated thumbnails with your own images
- **Video Preload Strategy** – Choose Lazy, Metadata, or Auto preloading
- **Shortcode Support** – `[tvpg_gallery product_id="123"]` works anywhere
- **REST API** – GET and POST endpoints for settings management
- **Theme Compatibility** – Works with Flatsome and most WooCommerce themes
- **HPOS Compatible** – Supports WooCommerce High-Performance Order Storage
- **SEO Ready** – Schema.org VideoObject structured data for rich snippets

## 📋 Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+

## 🚀 Installation

### From GitHub

1. Download the latest release
2. Upload to `/wp-content/plugins/true-video-product-gallery`
3. Activate via **Plugins** screen
4. Configure at **True Video Gallery** in the admin menu

### From WordPress Admin

1. Go to **Plugins → Add New**
2. Search for "True Video Product Gallery"
3. Click **Install Now** and **Activate**

## 📖 Usage

### Adding Videos to Products

1. Edit any WooCommerce product
2. Navigate to the **Product Video** tab
3. Paste your video URL (YouTube, Vimeo, TikTok, Instagram, or direct file)
4. Optionally upload a custom thumbnail
5. Save the product

### Variable Products

- Assign unique videos to each variation in the **Product Video** tab
- Or check **"Use same video for all variations"** to apply the main video everywhere

### Shortcode

```
[tvpg_gallery product_id="123"]
```

Use this shortcode to display a product's video gallery anywhere on your site.

## ⚙️ Settings

Navigate to **True Video Gallery** in the admin menu to configure:

- **Gallery Position** – Above or below product summary
- **Gallery Sizing** – Standard, Large, Full Width
- **Video Preload** – Lazy (facade), Metadata, or Auto
- **Autoplay/Mute** – Control default video behavior

## 🔌 REST API

### Get Settings
```
GET /wp-json/tvpg/v1/settings
```

### Update Settings
```
POST /wp-json/tvpg/v1/settings
```

## 📝 Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## 📄 License

This project is licensed under the GPLv2 or later - see the [LICENSE](LICENSE) file for details.

## 🆘 Support

- [GitHub Issues](https://github.com/MerlinStacks/true-video-product-gallery/issues) – Bug reports and feature requests
- [Documentation](https://sldevs.com/docs/true-video-product-gallery) – Full documentation

---

Made with ❤️ by [SLDevs](https://sldevs.com)
