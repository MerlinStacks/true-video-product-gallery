# SLDevs updates

Identity: `true-video-product-gallery/true-video-product-gallery.php` (unchanged).
Feed: https://sldevs.com.au/updates/true-video-product-gallery/info.json

## Build

Run `npm ci`, then `npm run release` (Node/npm and Python 3 required).
This rebuilds production assets and produces
`dist/true-video-product-gallery-<version>.zip`. The Python packager verifies
version consistency, the pinned upstream library hashes, required runtime files,
and ZIP layout/content. It uses an explicit production directory allowlist;
root Composer dependencies are development-only and are excluded.
`includes/plugin-update-checker/vendor/` is a production dependency and is included.

PUC is pinned to v5.7, commit `275a96a2a18d03c34c87f35cb68673c8c49ac3b1`.
`tools/plugin-update-checker.lock.json` records source provenance and SHA-256 hashes.
The upstream files are unmodified. The Parsedown 1.6.0 MIT license is additionally
included from https://github.com/erusev/parsedown/blob/1.6.0/LICENSE.txt.
Its versioned factory and
autoloaders support coexistence with other PUC v5 copies. Third-party files are
excluded from first-party coding-style checks, but included in PHP syntax checks.

## Publish and deploy (separate site tooling)

1. On staging, install the previous release, then upload this ZIP as a replacement.
   Confirm activation, gallery behaviour, plugin information, and update checks,
   also with WooCommerce inactive and another PUC v5 plugin active.
2. On the publisher host run its existing tool (not included in this repository):
   `python3 tools/publish-update.py true-video-product-gallery /absolute/path/to/dist/true-video-product-gallery-1.7.22.zip --changelog /absolute/path/to/release-notes/1.7.22.txt`
3. Upload the publisher's versioned ZIP **first**, then `info.json`, under
   `domains/sldevs.com.au/public_html/updates/true-video-product-gallery/`.
4. Verify an eligible older updater-enabled staging installation receives and
   installs the offered update. Check the ZIP URL and feed over HTTPS.

Existing installations need this updater-enabled release installed once manually
or through their current update mechanism. The integration does not force-enable
automatic updates. Building locally neither publishes nor deploys anything.
