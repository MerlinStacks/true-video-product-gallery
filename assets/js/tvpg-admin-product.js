jQuery(document).ready(function ($) {
    // Media Uploader for Video
    var video_frame;
    var image_frame;

    // --- VIDEO ---
    $('#tvpg_upload_video_btn').on('click', function (event) {
        event.preventDefault();
        if (video_frame) {
            video_frame.open();
            return;
        }
        video_frame = wp.media.frames.video_frame = wp.media({
            title: 'Select Video',
            button: { text: 'Use this video' },
            library: { type: 'video' },
            multiple: false
        });
        video_frame.on('select', function () {
            var attachment = video_frame.state().get('selection').first().toJSON();
            $('#tvpg_video_url').val(attachment.url).trigger('change');
        });
        video_frame.open();
    });

    $('#tvpg_clear_video_btn').on('click', function (event) {
        event.preventDefault();
        $('#tvpg_video_url').val('').trigger('change');
    });


    // --- THUMBNAIL ---
    $('#tvpg_upload_thumb_btn').on('click', function (event) {
        event.preventDefault();
        if (image_frame) {
            image_frame.open();
            return;
        }
        image_frame = wp.media.frames.image_frame = wp.media({
            title: 'Select Custom Video Thumbnail',
            button: { text: 'Use this image' },
            library: { type: 'image' },
            multiple: false
        });
        image_frame.on('select', function () {
            var attachment = image_frame.state().get('selection').first().toJSON();
            $('#tvpg_video_thumb_url').val(attachment.url);
        });
        image_frame.open();
    });

    // BUG-H7 fix: trigger change so the thumbnail preview updates immediately.
    $('#tvpg_clear_thumb_btn').on('click', function (event) {
        event.preventDefault();
        $('#tvpg_video_thumb_url').val('').trigger('change');
    });


    // --- PREVIEW LOGIC ---
    var $previewContainer = $('#tvpg_video_preview');
    var $thumbContainer = $('#tvpg_thumb_preview');
    var $urlInput = $('#tvpg_video_url');
    var $thumbInput = $('#tvpg_video_thumb_url');

    // Default settings if undefined (fallback)
    var settings = (typeof tvpgGlobalSettings !== 'undefined') ? tvpgGlobalSettings : {
        autoplay: false,
        loop: false,
        show_controls: true,
        mute_autoplay: true,
        video_sizing: 'contain'
    };

    function updateAllPreviews() {
        var url = $urlInput.val();
        var customThumb = $thumbInput.val();

        updateMainPreview(url);
        updateThumbPreview(url, customThumb);
    }

    function escapeAttribute(str) {
        return str.replace(/"/g, '&quot;');
    }

    function updateMainPreview(url) {
        $previewContainer.empty();
        if (!url) {
            $previewContainer.html('<div class="tvpg-empty-state"><span class="dashicons dashicons-video-alt3"></span><p>No video selected</p></div>');
            return;
        }

        var html = '';
        var fit = settings.video_sizing === 'cover' ? 'cover' : 'contain';

        // Ensure booleans (WP localizes as strings sometimes, but good to be safe)
        var showControls = (settings.show_controls == "1" || settings.show_controls === true) ? 1 : 0;
        var doLoop = (settings.loop == "1" || settings.loop === true) ? 1 : 0;

        var isAutoplay = (settings.autoplay == "1" || settings.autoplay === true);
        var isMuted = (settings.mute_autoplay == "1" || settings.mute_autoplay === true);

        // Basic Sanitization
        url = url.trim();
        if (url.toLowerCase().indexOf('javascript:') === 0 || url.toLowerCase().indexOf('data:') === 0) {
            $previewContainer.html('<div class="tvpg-empty-state"><p>Invalid Protocol</p></div>');
            return;
        }

        // IMP-03: Delegate parsing to server-side TVPG_Video_Parser via REST.
        // This eliminates duplicated regex and enables IMP-13 (TikTok/IG preview).
        if (typeof tvpgGlobalSettings !== 'undefined' && tvpgGlobalSettings.parseUrl) {
            $.ajax({
                url: tvpgGlobalSettings.parseUrl,
                method: 'POST',
                headers: { 'X-WP-Nonce': tvpgGlobalSettings.nonce },
                contentType: 'application/json',
                data: JSON.stringify({ url: url }),
                success: function (resp) {
                    if (!resp.success) {
                        $previewContainer.html('<div class="tvpg-empty-state"><span class="dashicons dashicons-video-alt3"></span><p>Invalid Video URL</p></div>');
                        return;
                    }

                    var html = '';
                    if (resp.type === 'file') {
                        var safeUrl = escapeAttribute(encodeURI(resp.embed_url));
                        var attrs = 'style="width:100%; height:100%; object-fit:' + fit + ';"';
                        if (showControls) attrs += ' controls';
                        if (doLoop) attrs += ' loop';
                        if (isAutoplay) { attrs += ' autoplay'; if (isMuted) attrs += ' muted'; }
                        html = '<video src="' + safeUrl + '" ' + attrs + '></video>';
                    } else if (resp.type === 'tiktok') {
                        // IMP-13: Show TikTok embed preview.
                        html = '<iframe src="' + escapeAttribute(resp.embed_url) + '" width="100%" height="100%" style="border:none;" style="object-fit:' + fit + '"></iframe>';
                    } else if (resp.type === 'instagram') {
                        // IMP-13: Show Instagram embed preview.
                        html = '<iframe src="' + escapeAttribute(resp.embed_url) + '" width="100%" height="100%" style="border:none;" style="object-fit:' + fit + '"></iframe>';
                    } else {
                        // YouTube / Vimeo — use the embed_url from server.
                        html = '<iframe width="100%" height="100%" src="' + escapeAttribute(resp.embed_url) + '" style="border:none;" allowfullscreen style="object-fit:' + fit + '"></iframe>';
                    }

                    $previewContainer.html(html);
                },
                error: function () {
                    $previewContainer.html('<div class="tvpg-empty-state"><span class="dashicons dashicons-video-alt3"></span><p>Failed to parse URL</p></div>');
                }
            });
        } else {
            $previewContainer.html('<div class="tvpg-empty-state"><span class="dashicons dashicons-video-alt3"></span><p>Parser unavailable</p></div>');
        }
    }

    function updateThumbPreview(url, customThumb) {
        $thumbContainer.empty();

        // Priority 1: Custom Thumb (Static Image)
        if (customThumb) {
            // BUG-11 fix: escape the custom thumbnail URL to prevent XSS.
            var safeThumb = escapeAttribute(encodeURI(customThumb));
            $thumbContainer.html('<img src="' + safeThumb + '" style="width:100%; height:100%; object-fit:cover;">');
            return;
        }

        if (!url) return;

        // Priority 2: Live Video Thumbnail via REST (IMP-03).
        url = url.trim();
        if (url.toLowerCase().indexOf('javascript:') === 0 || url.toLowerCase().indexOf('data:') === 0) {
            return;
        }

        if (typeof tvpgGlobalSettings !== 'undefined' && tvpgGlobalSettings.parseUrl) {
            $.ajax({
                url: tvpgGlobalSettings.parseUrl,
                method: 'POST',
                headers: { 'X-WP-Nonce': tvpgGlobalSettings.nonce },
                contentType: 'application/json',
                data: JSON.stringify({ url: url }),
                success: function (resp) {
                    if (!resp.success) return;

                    var html = '';
                    if (resp.type === 'file') {
                        var safeUrl = escapeAttribute(encodeURI(resp.embed_url));
                        html = '<video src="' + safeUrl + '" autoplay loop muted playsinline style="width:100%; height:100%; object-fit:cover;" tabindex="-1"></video>';
                    } else if (resp.type === 'youtube') {
                        var params = 'autoplay=1&mute=1&controls=0&loop=1&playlist=' + escapeAttribute(resp.id) + '&showinfo=0&modestbranding=1';
                        html = '<iframe src="https://www.youtube.com/embed/' + escapeAttribute(resp.id) + '?' + params + '" style="border:none;" style="width:100%; height:100%; object-fit:cover; pointer-events:none;" tabindex="-1"></iframe>';
                    } else if (resp.type === 'vimeo') {
                        var params = 'background=1&autoplay=1&loop=1&byline=0&title=0&muted=1';
                        html = '<iframe src="https://player.vimeo.com/video/' + escapeAttribute(resp.id) + '?' + params + '" style="border:none;" style="width:100%; height:100%; object-fit:cover; pointer-events:none;" tabindex="-1"></iframe>';
                    } else if (resp.thumb_url) {
                        html = '<img src="' + escapeAttribute(resp.thumb_url) + '" style="width:100%; height:100%; object-fit:cover;">';
                    } else {
                        html = '<div class="tvpg-empty-state"><span class="dashicons dashicons-video-alt3"></span></div>';
                    }

                    if (html) $thumbContainer.html(html);
                }
            });
        }
    }

    // IMP-10 fix: debounce to avoid double AJAX on blur+change.
    var previewDebounceTimer = null;
    function debouncedUpdateAllPreviews() {
        clearTimeout(previewDebounceTimer);
        previewDebounceTimer = setTimeout(updateAllPreviews, 150);
    }

    $urlInput.on('blur change', debouncedUpdateAllPreviews);
    $thumbInput.on('blur change keyup', debouncedUpdateAllPreviews); // keyup for instant thumb feedback

    // --- "USE SAME VIDEO FOR ALL" CHECKBOX TOGGLE ---
    $('#tvpg_use_same_video').on('change', function () {
        if ($(this).is(':checked')) {
            $('#tvpg-variation-videos').slideUp(200);
        } else {
            $('#tvpg-variation-videos').slideDown(200);
        }
    });

    // --- VARIATION FIELD UPLOAD LOGIC ---
    // Handles upload buttons in the Product Video tab's variation table.
    // IMP-2 fix: cache wp.media frames per variation to prevent leaks.
    var variationVideoFrames = {};

    $(document).on('click', '.tvpg-upload-variation-video', function (event) {
        event.preventDefault();

        var $button = $(this);
        var $input = $button.closest('td').find('input[type="text"]');

        if (!$input.length) {
            $input = $button.closest('.tvpg-input-row').find('input[type="text"]');
        }

        if (!$input.length) {
            return;
        }

        var cacheKey = $input.attr('name') || $input.attr('id') || '';
        if (cacheKey && variationVideoFrames[cacheKey]) {
            variationVideoFrames[cacheKey].open();
            return;
        }

        var frame = wp.media({
            title: 'Select Video',
            button: { text: 'Use this video' },
            library: { type: 'video' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $input.val(attachment.url).trigger('change');
        });

        if (cacheKey) variationVideoFrames[cacheKey] = frame;
        frame.open();
    });

    // --- VARIATION THUMBNAIL UPLOAD LOGIC ---
    // IMP-2 fix: cache wp.media frames per variation thumbnail.
    var variationThumbFrames = {};

    $(document).on('click', '.tvpg-upload-variation-thumb', function (event) {
        event.preventDefault();

        var $button = $(this);
        var $input = $button.closest('td').find('input[type="text"]');

        if (!$input.length) {
            return;
        }

        var cacheKey = $input.attr('name') || $input.attr('id') || '';
        if (cacheKey && variationThumbFrames[cacheKey]) {
            variationThumbFrames[cacheKey].open();
            return;
        }

        var frame = wp.media({
            title: 'Select Thumbnail',
            button: { text: 'Use this image' },
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $input.val(attachment.url).trigger('change');
        });

        if (cacheKey) variationThumbFrames[cacheKey] = frame;
        frame.open();
    });

    // Initial load
    updateAllPreviews();
});
