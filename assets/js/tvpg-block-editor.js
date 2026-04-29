/**
 * True Video Product Gallery — Block Editor Panel
 *
 * Renders a "Product Video" sidebar panel in the WooCommerce product
 * block editor using wp.plugins.registerPlugin and useEntityProp
 * for meta read/write. Fully backward-compatible with classic editor data.
 *
 * @package TVPG
 * @since   1.4.0
 */
(function () {
    'use strict';

    var registerPlugin = wp.plugins.registerPlugin;
    var PluginDocumentSettingPanel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || wp.editPost.PluginDocumentSettingPanel;
    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEntityProp = wp.coreData.useEntityProp;
    var TextControl = wp.components.TextControl;
    var Button = wp.components.Button;
    var __ = wp.i18n.__;

    /**
     * Why: wp.media integration requires a callback approach because
     * the media modal is jQuery-based and doesn't natively bridge to React.
     */
    function openMediaPicker(type, onSelect) {
        var frame = wp.media({
            title: type === 'video'
                ? __('Select Video', 'true-video-product-gallery')
                : __('Select Thumbnail', 'true-video-product-gallery'),
            button: {
                text: type === 'video'
                    ? __('Use this video', 'true-video-product-gallery')
                    : __('Use this image', 'true-video-product-gallery')
            },
            library: { type: type },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            onSelect(attachment.url);
        });

        frame.open();
    }

    /**
     * Detects the video provider from a URL for preview rendering.
     *
     * @param {string} url - Video URL.
     * @returns {{ type: string, id?: string }} Parsed provider info.
     */
    function parseProvider(url) {
        if (!url) return { type: 'none' };

        var ytMatch = url.match(/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|shorts\/|watch\?v=))([a-zA-Z0-9_-]+)/);
        if (ytMatch) return { type: 'youtube', id: ytMatch[1] };

        var vmMatch = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
        if (vmMatch) return { type: 'vimeo', id: vmMatch[1] };

        var ext = url.split('?')[0].split('.').pop().toLowerCase();
        if (['mp4', 'webm', 'ogg'].indexOf(ext) !== -1) return { type: 'file' };

        if (url.indexOf('tiktok.com') !== -1) return { type: 'tiktok' };
        if (url.indexOf('instagram.com') !== -1) return { type: 'instagram' };

        return { type: 'unknown' };
    }

    /**
     * IMP-1 fix: validate embed URLs against known-safe origins before rendering iframes.
     * Prevents rendering untrusted third-party iframes in the editor preview.
     */
    var SAFE_ORIGINS = [
        'https://www.youtube.com',
        'https://player.vimeo.com'
    ];

    function isSafeEmbedUrl(url) {
        try {
            var parsed = new URL(url);
            return SAFE_ORIGINS.some(function (origin) {
                return parsed.origin === new URL(origin).origin;
            });
        } catch (e) {
            return false;
        }
    }

    /**
     * Renders a small live preview of the video URL.
     */
    function VideoPreview(props) {
        var url = props.url;
        var info = parseProvider(url);

        if (info.type === 'none' || info.type === 'unknown') {
            return el('div', { className: 'tvpg-block-preview-empty' },
                el('span', { className: 'dashicons dashicons-video-alt3' }),
                el('p', null, info.type === 'none'
                    ? __('No video selected', 'true-video-product-gallery')
                    : __('Unrecognised URL', 'true-video-product-gallery')
                )
            );
        }

        if (info.type === 'youtube') {
            var ytUrl = 'https://www.youtube.com/embed/' + info.id + '?controls=1&rel=0';
            if (!isSafeEmbedUrl(ytUrl)) return null;
            return el('iframe', {
                src: ytUrl,
                style: { width: '100%', aspectRatio: '16/9', border: 'none', borderRadius: '6px' },
                title: __('YouTube preview', 'true-video-product-gallery'),
                loading: 'lazy',
                allowFullScreen: true
            });
        }

        if (info.type === 'vimeo') {
            var vmUrl = 'https://player.vimeo.com/video/' + info.id;
            if (!isSafeEmbedUrl(vmUrl)) return null;
            return el('iframe', {
                src: vmUrl,
                style: { width: '100%', aspectRatio: '16/9', border: 'none', borderRadius: '6px' },
                title: __('Vimeo preview', 'true-video-product-gallery'),
                loading: 'lazy',
                allowFullScreen: true
            });
        }

        if (info.type === 'file') {
            return el('video', {
                src: url,
                controls: true,
                style: { width: '100%', borderRadius: '6px' }
            });
        }

        return el('p', { style: { color: '#64748b', fontStyle: 'italic', fontSize: '13px' } },
            __('Preview not available for this provider.', 'true-video-product-gallery')
        );
    }

    /**
     * Main panel component rendered inside the product editor sidebar.
     */
    function ProductVideoPanel() {
        var entityPropArgs = ['postType', 'product'];
        var videoUrl = useEntityProp(entityPropArgs[0], entityPropArgs[1], '_tvpg_video_url');
        var thumbUrl = useEntityProp(entityPropArgs[0], entityPropArgs[1], '_tvpg_video_thumb_url');

        // Why: useEntityProp returns [value, setter, fullValue].
        var videoValue = videoUrl[0] || '';
        var setVideoValue = videoUrl[1];
        var thumbValue = thumbUrl[0] || '';
        var setThumbValue = thumbUrl[1];

        return el(PluginDocumentSettingPanel, {
            name: 'tvpg-product-video',
            title: __('Product Video', 'true-video-product-gallery'),
            icon: 'video-alt3',
            className: 'tvpg-block-panel'
        },
            // Video URL input.
            el(TextControl, {
                label: __('Video URL', 'true-video-product-gallery'),
                help: __('YouTube, Vimeo, TikTok, Instagram, or self-hosted MP4/WebM/OGG', 'true-video-product-gallery'),
                value: videoValue,
                onChange: setVideoValue,
                placeholder: 'https://'
            }),
            el('div', { style: { display: 'flex', gap: '8px', marginBottom: '16px' } },
                el(Button, {
                    variant: 'secondary',
                    size: 'compact',
                    onClick: function () {
                        openMediaPicker('video', setVideoValue);
                    }
                }, __('Upload Video', 'true-video-product-gallery')),
                videoValue && el(Button, {
                    variant: 'tertiary',
                    size: 'compact',
                    isDestructive: true,
                    onClick: function () { setVideoValue(''); }
                }, __('Clear', 'true-video-product-gallery'))
            ),

            // Thumbnail URL input.
            el(TextControl, {
                label: __('Custom Thumbnail URL', 'true-video-product-gallery'),
                help: __('Optional — overrides auto-generated thumbnail', 'true-video-product-gallery'),
                value: thumbValue,
                onChange: setThumbValue,
                placeholder: 'https://'
            }),
            el('div', { style: { display: 'flex', gap: '8px', marginBottom: '16px' } },
                el(Button, {
                    variant: 'secondary',
                    size: 'compact',
                    onClick: function () {
                        openMediaPicker('image', setThumbValue);
                    }
                }, __('Upload Thumbnail', 'true-video-product-gallery')),
                thumbValue && el(Button, {
                    variant: 'tertiary',
                    size: 'compact',
                    isDestructive: true,
                    onClick: function () { setThumbValue(''); }
                }, __('Clear', 'true-video-product-gallery'))
            ),

            // Live preview.
            videoValue && el('div', { style: { marginTop: '8px' } },
                el('p', {
                    style: {
                        fontSize: '11px',
                        textTransform: 'uppercase',
                        letterSpacing: '0.05em',
                        color: '#64748b',
                        fontWeight: '700',
                        marginBottom: '8px'
                    }
                }, __('Preview', 'true-video-product-gallery')),
                el(VideoPreview, { url: videoValue })
            )
        );
    }

    registerPlugin('tvpg-product-video', {
        render: function () {
            return el(ProductVideoPanel);
        },
        icon: 'video-alt3'
    });
})();
