jQuery(document).ready(function ($) {
    if (!$('.tvpg-main-slider').length) {
        return;
    }

    var thumbSlider = new Swiper(".tvpg-thumb-slider", {
        spaceBetween: 10,
        slidesPerView: 4,
        freeMode: true,
        watchSlidesProgress: true,
        breakpoints: {
            // responsive thumbnails
            320: { slidesPerView: 3 },
            640: { slidesPerView: 4 },
            1024: { slidesPerView: 5 }
        }
    });

    var mainSlider = new Swiper(".tvpg-main-slider", {
        spaceBetween: 10,
        navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
        },
        thumbs: {
            swiper: thumbSlider,
        },
    });

    // Video Control Logic
    var settings = (typeof tvpgParams !== "undefined") ? tvpgParams.settings : { autoplay: false, mute_autoplay: true };

    function playVideo(slide) {
        if (!settings.autoplay) return;

        var video = $(slide).find('video').get(0);
        var iframe = $(slide).find('iframe');

        if (video) {
            if (settings.mute_autoplay) video.muted = true;
            var playPromise = video.play();
            if (playPromise !== undefined) {
                playPromise.catch(function (error) {
                    // Autoplay prevented by browser policy - silent fail.
                });
            }
        } else if (iframe.length) {
            // ... (iframe logic remains same) ...
            var src = iframe.attr('src') || iframe.attr('data-src') || '';
            // PostMessage for Autoplay
            if (src.indexOf('youtube') !== -1) {
                // Determine mute command if needed? YT handles mute via param mostly, 
                // but can send 'mute' command.
                if (settings.mute_autoplay) {
                    iframe.get(0).contentWindow.postMessage('{"event":"command","func":"mute","args":""}', '*');
                }
                iframe.get(0).contentWindow.postMessage('{"event":"command","func":"playVideo","args":""}', '*');
            } else if (src.indexOf('vimeo') !== -1) {
                if (settings.mute_autoplay) {
                    iframe.get(0).contentWindow.postMessage('{"method":"setVolume", "value":0}', '*');
                }
                iframe.get(0).contentWindow.postMessage('{"method":"play"}', '*');
            }
        }
    }

    function pauseVideo(slide) {
        var video = $(slide).find('video').get(0);
        var iframe = $(slide).find('iframe');

        if (video) {
            video.pause();
        } else if (iframe.length) {
            var src = iframe.attr('src') || iframe.attr('data-src') || '';
            if (src.indexOf('youtube') !== -1) {
                iframe.get(0).contentWindow.postMessage('{"event":"command","func":"pauseVideo","args":""}', '*');
            } else if (src.indexOf('vimeo') !== -1) {
                iframe.get(0).contentWindow.postMessage('{"method":"pause"}', '*');
            }
        }
    }

    // Toggle Play/Pause on click (for local video)
    $('.tvpg-main-slider').on('click', '.tvpg-video-slide video', function (e) {
        if (this.controls) return;
        if (this.paused) {
            this.play();
        } else {
            this.pause();
        }
    });

    /**
     * Lazy Loading Facade Handler
     * 
     * When user clicks the play button on a facade, inject the actual iframe.
     * This defers loading YouTube/Vimeo scripts until user interaction,
     * significantly improving Core Web Vitals (LCP, TBT).
     */
    $('.tvpg-main-slider').on('click', '.tvpg-lazy-facade', function (e) {
        var $facade = $(this);

        // Prevent double-loading
        if ($facade.hasClass('tvpg-loaded')) {
            return;
        }

        var embedUrl = $facade.data('embed-url');
        var provider = $facade.data('provider');

        if (!embedUrl) {
            return;
        }

        // Create and inject iframe
        var iframe = document.createElement('iframe');
        iframe.src = embedUrl;
        iframe.setAttribute('frameborder', '0');
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');

        if (provider === 'youtube') {
            iframe.setAttribute('title', 'YouTube video player');
        } else if (provider === 'vimeo') {
            iframe.setAttribute('title', 'Vimeo video player');
        }

        $facade.append(iframe);
        $facade.addClass('tvpg-loaded');
    });

    mainSlider.on('slideChange', function () {
        $('.tvpg-main-slider .swiper-slide').each(function () {
            pauseVideo(this);
        });

        var activeSlide = mainSlider.slides[mainSlider.activeIndex];
        if ($(activeSlide).hasClass('tvpg-video-slide')) {
            playVideo(activeSlide);
        }
    });

    var initialSlide = mainSlider.slides[mainSlider.activeIndex];
    if ($(initialSlide).hasClass('tvpg-video-slide')) {
        playVideo(initialSlide);
    }

    // --- Init Stores ---
    var $firstImageSlide = $('.tvpg-main-slider .swiper-slide:not(.tvpg-video-slide)').first();
    var $firstImage = $firstImageSlide.find('img');
    var originalImage = {
        src: $firstImage.attr('src'),
        srcset: $firstImage.attr('srcset'),
        alt: $firstImage.attr('alt'),
        index: $firstImageSlide.index()
    };

    var $videoSlide = $('.tvpg-main-slider .swiper-slide.tvpg-video-slide').first();
    var originalVideoHtml = $videoSlide.length ? $videoSlide.find('.woocommerce-product-gallery__image').html() : '';

    var $videoThumbSlide = $('.tvpg-thumb-slider .swiper-slide.tvpg-video-thumb-slide').first();
    var originalVideoThumbHtml = $videoThumbSlide.length ? $videoThumbSlide.html() : '';


    // --- STRATEGY: PULSE CHECKER (For Flatsome/Themes) ---
    window.tvpgCurrentVideoState = null; // Can be string HTML, or null if no video.

    function setExpectedVideoState(html) {
        window.tvpgCurrentVideoState = html;
    }
    function clearExpectedVideoState() {
        window.tvpgCurrentVideoState = null;
    }

    // The Pulse: runs continuously to enforce state against theme overwrites
    if (!window.tvpgPulse) {
        window.tvpgPulse = setInterval(function () {
            if (window.tvpgCurrentVideoState) {
                // We expect a video. Find the video slide.
                var $slide = $('.tvpg-main-slider .swiper-slide.tvpg-video-slide').first();

                if ($slide.length) {
                    // Check if it has been overwritten
                    var hasVideo = $slide.find('video, iframe').length > 0;
                    var hasImage = $slide.find('img.wp-post-image').length > 0;
                    var isEmpty = $slide.find('.woocommerce-product-gallery__image').is(':empty');

                    // If it has an image AND no video, OR it looks empty/broken relative to our expectation
                    if (!hasVideo && (hasImage || isEmpty)) {
                        // Restore
                        if ($slide.find('.woocommerce-product-gallery__image').length === 0) {
                            $slide.html('<div class="woocommerce-product-gallery__image">' + window.tvpgCurrentVideoState + '</div>');
                        } else {
                            $slide.find('.woocommerce-product-gallery__image').html(window.tvpgCurrentVideoState);
                        }
                        playVideo($slide);
                    }
                }
            }
        }, 500); // Check every 500ms
    }



    // Listen GLOBALLY for found_variation
    $(document).on('found_variation', function (event, variation) {
        handleVariation(variation);
    });

    var handleVariation = function (variation) {
        if (!variation) return;

        setTimeout(function () {
            // Re-query elements
            var $currentVideoSlide = $('.tvpg-main-slider .swiper-slide.tvpg-video-slide').first();
            var $currentVideoThumbSlide = $('.tvpg-thumb-slider .swiper-slide.tvpg-video-thumb-slide').first();
            var $currentFirstImageSlide = $('.tvpg-main-slider .swiper-slide:not(.tvpg-video-slide)').first();
            var $currentFirstImage = $currentFirstImageSlide.find('img');

            // --- 1. Handle Variation Video ---
            if (variation.tvpg_video_html) {
                // Variation has a specific video
                setExpectedVideoState(variation.tvpg_video_html);

                if ($currentVideoSlide.length) {
                    // Case A: Existing Video Slide
                    $currentVideoSlide.find('.woocommerce-product-gallery__image').html(variation.tvpg_video_html);

                    if (mainSlider && mainSlider.slides) {
                        mainSlider.slideTo($currentVideoSlide.index());
                    }
                    playVideo($currentVideoSlide);

                    if ($currentVideoThumbSlide.length && variation.tvpg_video_thumb_html) {
                        $currentVideoThumbSlide.html(variation.tvpg_video_thumb_html);
                    }
                } else {
                    // Case B: Dynamic Injection
                    var newSlideHtml = '<div class="swiper-slide tvpg-video-slide tvpg-dynamic-slide"><div class="woocommerce-product-gallery__image">' + variation.tvpg_video_html + '</div></div>';
                    var newThumbHtml = '<div class="swiper-slide tvpg-video-thumb-slide tvpg-dynamic-slide">' + (variation.tvpg_video_thumb_html ? variation.tvpg_video_thumb_html : '<span class="tvpg-play-icon"></span>') + '</div>';
                    var hasPlaceholder = $('.tvpg-placeholder-slide').length > 0;
                    var newIndex;

                    if (hasPlaceholder) {
                        if (mainSlider) { mainSlider.prependSlide(newSlideHtml); mainSlider.update(); }
                        if (thumbSlider) { thumbSlider.prependSlide(newThumbHtml); thumbSlider.update(); }
                        newIndex = 0;
                    } else {
                        if (mainSlider) { mainSlider.appendSlide(newSlideHtml); mainSlider.update(); }
                        if (thumbSlider) { thumbSlider.appendSlide(newThumbHtml); thumbSlider.update(); }
                        newIndex = (mainSlider && mainSlider.slides) ? mainSlider.slides.length - 1 : 0;
                    }

                    if (mainSlider && mainSlider.slides) {
                        mainSlider.slideTo(newIndex);
                        playVideo($(mainSlider.slides[newIndex]));
                    }
                }
            } else {
                // Variation has NO video
                clearExpectedVideoState();

                // Remove dynamic slides
                if ($('.tvpg-dynamic-slide').length) {
                    if (mainSlider) {
                        var indices = [];
                        $(mainSlider.slides).each(function (i, s) { if ($(s).hasClass('tvpg-dynamic-slide')) indices.push(i); });
                        indices.sort((a, b) => b - a);
                        indices.forEach(i => mainSlider.removeSlide(i));
                        mainSlider.update();
                    }
                    if (thumbSlider) {
                        var indicesT = [];
                        $(thumbSlider.slides).each(function (i, s) { if ($(s).hasClass('tvpg-dynamic-slide')) indicesT.push(i); });
                        indicesT.sort((a, b) => b - a);
                        indicesT.forEach(i => thumbSlider.removeSlide(i));
                        thumbSlider.update();
                    }
                }

                // Restore main video
                if ($currentVideoSlide.length && originalVideoHtml && !$currentVideoSlide.hasClass('tvpg-dynamic-slide')) {
                    $currentVideoSlide.find('.woocommerce-product-gallery__image').html(originalVideoHtml);
                    if ($currentVideoThumbSlide.length && originalVideoThumbHtml) {
                        $currentVideoThumbSlide.html(originalVideoThumbHtml);
                    }
                }
            }

            // --- 2. Handle Variation Image ---
            if (variation && variation.image && variation.image.src && variation.image.src.length > 1) {
                if ($currentFirstImage.length) {
                    $currentFirstImage.attr('src', variation.image.full_src || variation.image.src);
                    $currentFirstImage.attr('srcset', variation.image.srcset || '');
                    $currentFirstImage.attr('alt', variation.image.alt || '');
                }

                if (thumbSlider && thumbSlider.slides) {
                    if (originalImage.index >= 0 && thumbSlider.slides[originalImage.index]) {
                        var $thumbSlide = $(thumbSlider.slides[originalImage.index]);
                        var $thumbImg = $thumbSlide.find('img');
                        if ($thumbImg.length) {
                            $thumbImg.attr('src', variation.image.gallery_thumbnail_src || variation.image.thumb_src || variation.image.src);
                        }
                    }
                }

                if (!variation.tvpg_video_html) {
                    if (mainSlider && mainSlider.slides) {
                        mainSlider.slideTo($currentFirstImageSlide.index());
                    }
                }
            }
        }, 50);
    };

    $(document).on('reset_image', 'form.variations_form', function () {
        clearExpectedVideoState();

        if ($firstImage.length && originalImage.src) {
            $firstImage.attr('src', originalImage.src);
            $firstImage.attr('srcset', originalImage.srcset || '');
            $firstImage.attr('alt', originalImage.alt || '');
        }

        var mainSlider = $('.tvpg-main-slider')[0].swiper;
        var thumbSlider = $('.tvpg-thumb-slider')[0].swiper;

        if ($('.tvpg-dynamic-slide').length) {
            if (mainSlider) {
                var indices = [];
                $(mainSlider.slides).each(function (i, s) { if ($(s).hasClass('tvpg-dynamic-slide')) indices.push(i); });
                indices.sort((a, b) => b - a);
                indices.forEach(i => mainSlider.removeSlide(i));
                mainSlider.update();
            }
            if (thumbSlider) {
                var indicesT = [];
                $(thumbSlider.slides).each(function (i, s) { if ($(s).hasClass('tvpg-dynamic-slide')) indicesT.push(i); });
                indicesT.sort((a, b) => b - a);
                indicesT.forEach(i => thumbSlider.removeSlide(i));
                thumbSlider.update();
            }
        }

        var $currentVideoSlide = $('.tvpg-main-slider .swiper-slide.tvpg-video-slide').first();
        if ($currentVideoSlide.length && originalVideoHtml && !$currentVideoSlide.hasClass('tvpg-dynamic-slide')) {
            $currentVideoSlide.find('.woocommerce-product-gallery__image').html(originalVideoHtml);
        }

        if (mainSlider) mainSlider.slideTo(originalImage.index);
    });

});
