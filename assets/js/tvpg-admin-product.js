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

    $('#tvpg_clear_thumb_btn').on('click', function (event) {
        event.preventDefault();
        $('#tvpg_video_thumb_url').val('');
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
        // Prevent obvious XSS payloads like javascript: or data: alerts
        if (url.toLowerCase().indexOf('javascript:') === 0 || url.toLowerCase().indexOf('data:') === 0) {
            $previewContainer.html('<div class="tvpg-empty-state"><p>Invalid Protocol</p></div>');
            return;
        }

        if (url.indexOf('youtube') !== -1 || url.indexOf('youtu.be') !== -1) {
            var id = '';
            var match = url.match(/(youtu\.be\/|youtube\.com\/(watch\?(.*&)?v=|(embed|v)\/))([^\?&"\'>]+)/);
            if (match && match[5]) id = match[5];

            if (id) {
                // YT Params
                var params = 'rel=0&enablejsapi=1' +
                    '&controls=' + showControls +
                    '&loop=' + doLoop +
                    (doLoop ? '&playlist=' + id : '');

                if (isAutoplay) {
                    params += '&autoplay=1';
                    if (isMuted) params += '&mute=1';
                }

                html = '<iframe width="100%" height="100%" src="https://www.youtube.com/embed/' + id + '?' + params + '" frameborder="0" allowfullscreen style="object-fit:' + fit + '"></iframe>';
            }
        } else if (url.indexOf('vimeo') !== -1) {
            var id = url.replace(/[^0-9]/g, '');
            if (id) {
                var params = 'controls=' + showControls + '&loop=' + doLoop;
                if (isAutoplay) {
                    params += '&autoplay=1';
                    if (isMuted) params += '&muted=1';
                }
                html = '<iframe width="100%" height="100%" src="https://player.vimeo.com/video/' + id + '?' + params + '" frameborder="0" allowfullscreen style="object-fit:' + fit + '"></iframe>';
            }
        } else {
            // Self hosted
            // Sanitize URL for attribute
            var safeUrl = encodeURI(url);
            // Also escape double quotes just in case
            safeUrl = escapeAttribute(safeUrl);

            var attrs = 'style="width:100%; height:100%; object-fit:' + fit + ';"';
            if (showControls) attrs += ' controls';
            if (doLoop) attrs += ' loop';
            if (isAutoplay) {
                attrs += ' autoplay';
                if (isMuted) attrs += ' muted';
            }
            html = '<video src="' + safeUrl + '" ' + attrs + '></video>';
        }

        if (html) {
            $previewContainer.html(html);
        } else {
            $previewContainer.html('<div class="tvpg-empty-state"><span class="dashicons dashicons-video-alt3"></span><p>Invalid Video URL</p></div>');
        }
    }

    function updateThumbPreview(url, customThumb) {
        $thumbContainer.empty();

        // Priority 1: Custom Thumb (Static Image)
        if (customThumb) {
            $thumbContainer.html('<img src="' + customThumb + '" style="width:100%; height:100%; object-fit:cover;">');
            return;
        }

        if (!url) return;

        // Priority 2: Live Video Thumbnail (Mimic Frontend)
        // Frontend Logic: Autoplay, Loop, Muted, No Controls.
        var html = '';

        // Basic Sanitization
        url = url.trim();
        if (url.toLowerCase().indexOf('javascript:') === 0 || url.toLowerCase().indexOf('data:') === 0) {
            return;
        }

        if (url.indexOf('youtube') !== -1 || url.indexOf('youtu.be') !== -1) {
            var id = '';
            var match = url.match(/(youtu\.be\/|youtube\.com\/(watch\?(.*&)?v=|(embed|v)\/))([^\?&"\'>]+)/);
            if (match && match[5]) id = match[5];
            if (id) {
                // Autoplay, Mute, Loop, No Controls, Modest Branding
                var params = 'autoplay=1&mute=1&controls=0&loop=1&playlist=' + id + '&showinfo=0&modestbranding=1';
                html = '<iframe src="https://www.youtube.com/embed/' + id + '?' + params + '" frameborder="0" style="width:100%; height:100%; object-fit:cover; pointer-events:none;" tabindex="-1"></iframe>';
            }
        } else if (url.indexOf('vimeo') !== -1) {
            var id = url.replace(/[^0-9]/g, '');
            if (id) {
                // Background mode
                var params = 'background=1&autoplay=1&loop=1&byline=0&title=0&muted=1';
                html = '<iframe src="https://player.vimeo.com/video/' + id + '?' + params + '" frameborder="0" style="width:100%; height:100%; object-fit:cover; pointer-events:none;" tabindex="-1"></iframe>';
            }
        } else {
            // Self-hosted
            var safeUrl = encodeURI(url);
            safeUrl = escapeAttribute(safeUrl);
            html = '<video src="' + safeUrl + '" autoplay loop muted playsinline style="width:100%; height:100%; object-fit:cover;" tabindex="-1"></video>';
        }

        if (html) {
            $thumbContainer.html(html);
        }
    }

    $urlInput.on('blur change', updateAllPreviews);
    $thumbInput.on('blur change keyup', updateAllPreviews); // keyup for instant thumb feedback

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
    $(document).on('click', '.tvpg-upload-variation-video', function (event) {
        event.preventDefault();

        var $button = $(this);
        // Find input in the same table cell (new structure) or row.
        var $input = $button.closest('td').find('input[type="text"]');

        // Fallback to old structure if needed.
        if (!$input.length) {
            $input = $button.closest('.tvpg-input-row').find('input[type="text"]');
        }

        if (!$input.length) {
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

        frame.open();
    });

    // --- VARIATION THUMBNAIL UPLOAD LOGIC ---
    $(document).on('click', '.tvpg-upload-variation-thumb', function (event) {
        event.preventDefault();

        var $button = $(this);
        var $input = $button.closest('td').find('input[type="text"]');

        if (!$input.length) {
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

        frame.open();
    });

    // Initial load
    updateAllPreviews();
});
