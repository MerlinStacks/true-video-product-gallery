/**
 * True Video Product Gallery — Frontend Script
 *
 * Vanilla JS (no jQuery dependency). Handles Swiper initialisation,
 * video playback, lazy facade loading, variation video swapping,
 * MutationObserver state enforcement, keyboard navigation, and loading spinner.
 *
 * @package TVPG
 * @since   1.0.0
 * @since   1.3.0 Rewritten: jQuery removed (IMP-06), MutationObserver (IMP-02),
 *                keyboard nav (IMP-08), touch-swipe fix (IMP-09),
 *                lightbox (IMP-10), loading spinner (IMP-11).
 */
(function () {
    'use strict';

    var mainSliderEl = document.querySelector('.tvpg-main-slider');
    if (!mainSliderEl) return;

    // BUG-H1 fix: sanitise HTML before innerHTML to prevent XSS from tampered responses.
    function sanitiseVideoHtml(html) {
        if (!html) return '';
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        // Remove all script tags.
        doc.querySelectorAll('script').forEach(function (s) { s.remove(); });
        // Strip event-handler attributes from all elements.
        doc.body.querySelectorAll('*').forEach(function (el) {
            Array.from(el.attributes).forEach(function (attr) {
                if (attr.name.toLowerCase().indexOf('on') === 0) {
                    el.removeAttribute(attr.name);
                }
            });
        });
        return doc.body.innerHTML;
    }

    // ── Connection Prefetch via Intersection Observer ────────────────────────
    // Warms DNS + TLS for video providers when lazy facades approach viewport,
    // so the iframe loads faster on click.
    var prefetchedOrigins = {};
    var providerOrigins = {
        youtube: 'https://www.youtube.com',
        vimeo: 'https://player.vimeo.com',
        tiktok: 'https://www.tiktok.com',
        instagram: 'https://www.instagram.com'
    };

    if ('IntersectionObserver' in window) {
        var prefetchObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var provider = entry.target.getAttribute('data-provider');
                var origin = providerOrigins[provider];
                if (!origin || prefetchedOrigins[origin]) return;

                var link = document.createElement('link');
                link.rel = 'preconnect';
                link.href = origin;
                link.crossOrigin = '';
                document.head.appendChild(link);
                prefetchedOrigins[origin] = true;
                prefetchObserver.unobserve(entry.target);
            });
        }, { rootMargin: '300px' });

        mainSliderEl.querySelectorAll('.tvpg-lazy-facade').forEach(function (el) {
            prefetchObserver.observe(el);
        });
    }

    // Normalize settings — wp_localize_script casts booleans to "1"/"" strings.
    function toBool(val) { return val === true || val === '1' || val === 1; }

    var rawSettings = (typeof tvpgParams !== 'undefined') ? tvpgParams.settings : {};
    var settings = {
        autoplay: toBool(rawSettings.autoplay),
        mute_autoplay: toBool(rawSettings.mute_autoplay),
        loop: toBool(rawSettings.loop),
        show_controls: toBool(rawSettings.show_controls),
        show_arrows: toBool(rawSettings.show_arrows),
        enable_lightbox: toBool(rawSettings.enable_lightbox),
        video_sizing: rawSettings.video_sizing || 'contain',
        video_position: rawSettings.video_position || 'second',
        video_preload: rawSettings.video_preload || 'lazy'
    };
    var needsSlider = (typeof tvpgParams !== 'undefined') ? toBool(tvpgParams.needsSlider) : true;

    // ── Swiper Init ──────────────────────────────────────────────────────────
    var thumbSlider = null;
    var mainSlider = null;

    if (needsSlider && typeof Swiper !== 'undefined') {
        thumbSlider = new Swiper('.tvpg-thumb-slider', {
            spaceBetween: 10,
            slidesPerView: 4,
            freeMode: true,
            watchSlidesProgress: true,
            breakpoints: {
                320: { slidesPerView: 3 },
                640: { slidesPerView: 4 },
                1024: { slidesPerView: 5 }
            }
        });

        mainSlider = new Swiper('.tvpg-main-slider', {
            spaceBetween: 10,
            navigation: {
                nextEl: '.swiper-button-next',
                prevEl: '.swiper-button-prev',
            },
            thumbs: { swiper: thumbSlider },
            // IMP-08: keyboard navigation.
            keyboard: { enabled: true, onlyInViewport: true },
            // IMP-09: touch events target wrapper to avoid video capture.
            touchEventsTarget: 'wrapper',
        });
    }

    // ── Video Playback Helpers ───────────────────────────────────────────────
    // Specific origins for postMessage — never use '*' to prevent leaking
    // commands to unrelated iframes on the same page.
    var YT_ORIGIN = 'https://www.youtube.com';
    var VM_ORIGIN = 'https://player.vimeo.com';

    function getIframeProvider(iframe) {
        var src = iframe.getAttribute('src') || '';
        if (src.indexOf('youtube') !== -1) return 'youtube';
        if (src.indexOf('vimeo') !== -1) return 'vimeo';
        return null;
    }

    function playVideo(slide) {
        if (!settings.autoplay) return;

        var video = slide.querySelector('video');
        var iframe = slide.querySelector('iframe');

        if (video) {
            if (settings.mute_autoplay) video.muted = true;
            var p = video.play();
            if (p !== undefined) p.catch(function () { /* browser policy */ });
        } else if (iframe && iframe.contentWindow) {
            var provider = getIframeProvider(iframe);
            if (provider === 'youtube') {
                if (settings.mute_autoplay) {
                    iframe.contentWindow.postMessage('{"event":"command","func":"mute","args":[]}', YT_ORIGIN);
                }
                iframe.contentWindow.postMessage('{"event":"command","func":"playVideo","args":[]}', YT_ORIGIN);
            } else if (provider === 'vimeo') {
                if (settings.mute_autoplay) {
                    iframe.contentWindow.postMessage('{"method":"setVolume", "value":0}', VM_ORIGIN);
                }
                iframe.contentWindow.postMessage('{"method":"play"}', VM_ORIGIN);
            }
        }
    }

    function pauseVideo(slide) {
        var video = slide.querySelector('video');
        var iframe = slide.querySelector('iframe');

        if (video) {
            video.pause();
        } else if (iframe && iframe.contentWindow) {
            var provider = getIframeProvider(iframe);
            if (provider === 'youtube') {
                iframe.contentWindow.postMessage('{"event":"command","func":"pauseVideo","args":[]}', YT_ORIGIN);
            } else if (provider === 'vimeo') {
                iframe.contentWindow.postMessage('{"method":"pause"}', VM_ORIGIN);
            }
        }
    }

    function pauseAllVideos() {
        mainSliderEl.querySelectorAll('.swiper-slide').forEach(function (s) { pauseVideo(s); });
    }

    // ── Video Error Handling ──────────────────────────────────────────────────
    function attachVideoErrorHandler(container) {
        if (!container) return;
        var videos = container.querySelectorAll('video');
        videos.forEach(function (video) {
            video.addEventListener('error', function () {
                var wrapper = video.closest('.woocommerce-product-gallery__image') || video.parentNode;
                var errorEl = document.createElement('div');
                errorEl.className = 'tvpg-video-error';
                errorEl.innerHTML = '<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#94a3b8" stroke-width="1.5"><path d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z"/><line x1="3" y1="3" x2="21" y2="21" stroke="#94a3b8" stroke-width="1.5"/></svg><p>Video unavailable</p>';
                video.replaceWith(errorEl);
            });
        });
    }

    // Attach to all initial video elements.
    attachVideoErrorHandler(mainSliderEl);

    // Also handle iframe load timeouts for lazy facades.
    var IFRAME_TIMEOUT = 15000;
    function attachIframeTimeout(iframe, facade) {
        var timer = setTimeout(function () {
            if (facade.querySelector('.tvpg-loading-spinner')) {
                var spinner = facade.querySelector('.tvpg-loading-spinner');
                if (spinner) spinner.remove();
                var errorEl = document.createElement('div');
                errorEl.className = 'tvpg-video-error';
                errorEl.innerHTML = '<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#94a3b8" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><p>Failed to load video</p>';
                facade.appendChild(errorEl);
            }
        }, IFRAME_TIMEOUT);
        iframe.addEventListener('load', function () { clearTimeout(timer); });
    }

    // ── Click-to-Toggle for native video ─────────────────────────────────────
    mainSliderEl.addEventListener('click', function (e) {
        var video = e.target;
        if (video.tagName !== 'VIDEO') return;
        if (video.controls) return;
        if (video.paused) { video.play(); } else { video.pause(); }
    });

    // ── Lazy Facade Handler (IMP-11: spinner) ────────────────────────────────
    mainSliderEl.addEventListener('click', function (e) {
        var facade = e.target.closest('.tvpg-lazy-facade');
        if (!facade || facade.classList.contains('tvpg-loaded')) return;

        var embedUrl = facade.getAttribute('data-embed-url');
        var provider = facade.getAttribute('data-provider');
        if (!embedUrl) return;

        // IMP-11: Show spinner before iframe loads.
        var spinner = document.createElement('div');
        spinner.className = 'tvpg-loading-spinner';
        facade.appendChild(spinner);

        var iframe = document.createElement('iframe');
        iframe.src = embedUrl;
        iframe.style.border = 'none';
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
        iframe.setAttribute('loading', 'lazy');
        iframe.setAttribute('title', provider === 'youtube' ? 'YouTube video player' : 'Vimeo video player');

        // IMP-11: Hide spinner when iframe finishes loading.
        iframe.addEventListener('load', function () {
            if (spinner.parentNode) spinner.parentNode.removeChild(spinner);
        });

        attachIframeTimeout(iframe, facade);
        facade.appendChild(iframe);
        facade.classList.add('tvpg-loaded');
    });

    // ── Social Embed Facade Handler (TikTok / Instagram) ─────────────────────
    // PSI-02: Scripts load only on user interaction, not on page load.
    window.__tvpg_loaded = window.__tvpg_loaded || {};

    mainSliderEl.addEventListener('click', function (e) {
        var facade = e.target.closest('.tvpg-social-facade');
        if (!facade || facade.classList.contains('tvpg-loaded')) return;

        var embedType = facade.getAttribute('data-embed-type');
        var videoId = facade.getAttribute('data-video-id');
        var videoUrl = facade.getAttribute('data-video-url');
        if (!embedType || !videoId) return;

        // Show spinner.
        var spinner = document.createElement('div');
        spinner.className = 'tvpg-loading-spinner';
        facade.appendChild(spinner);
        function clearSpinner() {
            if (spinner.parentNode) spinner.parentNode.removeChild(spinner);
        }

        // Inject the real embed markup.
        var embedContainer = document.createElement('div');
        embedContainer.className = 'tvpg-social-embed-inner';

        if (embedType === 'tiktok') {
            embedContainer.innerHTML = '<blockquote class="tiktok-embed" cite="' + videoUrl + '" data-video-id="' + videoId + '" style="max-width:605px;min-width:325px;"><section></section></blockquote>';
        } else if (embedType === 'instagram') {
            embedContainer.innerHTML = '<blockquote class="instagram-media" data-instgrm-permalink="' + videoUrl + '" data-instgrm-version="14" style="max-width:540px;width:100%;"></blockquote>';
        }

        facade.appendChild(embedContainer);
        facade.classList.add('tvpg-loaded');

        // Load the embed script (once per provider per page).
        function loadScript(src, key, onLoad) {
            if (window.__tvpg_loaded[key]) {
                clearSpinner();
                if (onLoad) onLoad();
                return;
            }
            var s = document.createElement('script');
            s.async = true;
            s.src = src;
            s.onload = function () {
                window.__tvpg_loaded[key] = true;
                clearSpinner();
                if (onLoad) onLoad();
            };
            s.onerror = clearSpinner;
            document.body.appendChild(s);
        }

        if (embedType === 'tiktok') {
            loadScript('https://www.tiktok.com/embed.js', 'tiktok', function () {
                // Re-render any new blockquotes.
                if (window.tiktokEmbed && window.tiktokEmbed.lib) {
                    window.tiktokEmbed.lib.render(facade.querySelectorAll('.tiktok-embed'));
                }
            });
        } else if (embedType === 'instagram') {
            loadScript('https://www.instagram.com/embed.js', 'instagram', function () {
                if (window.instgrm && window.instgrm.Embeds) {
                    window.instgrm.Embeds.process();
                }
            });
        }
    });

    // ── Slide Change Events ──────────────────────────────────────────────────
    if (mainSlider) {
        mainSlider.on('slideChange', function () {
            pauseAllVideos();
            var active = mainSlider.slides[mainSlider.activeIndex];
            if (active && active.classList.contains('tvpg-video-slide')) {
                playVideo(active);
            }
        });

        // Play initial video if active.
        var initialSlide = mainSlider.slides[mainSlider.activeIndex];
        if (initialSlide && initialSlide.classList.contains('tvpg-video-slide')) {
            playVideo(initialSlide);
        }
    }

    // ── Keyboard: Spacebar Play/Pause ─────────────────────────────────────
    mainSliderEl.addEventListener('keydown', function (e) {
        if (e.key !== ' ' && e.key !== 'Spacebar') return;
        // Don't hijack spacebar when focus is on a form element.
        var tag = document.activeElement ? document.activeElement.tagName : '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON') return;

        var active = mainSlider ? mainSlider.slides[mainSlider.activeIndex] : null;
        if (!active || !active.classList.contains('tvpg-video-slide')) return;

        e.preventDefault();
        var video = active.querySelector('video');
        if (video) {
            if (video.paused) { video.play(); } else { video.pause(); }
            return;
        }
        var iframe = active.querySelector('iframe');
        if (iframe && iframe.contentWindow) {
            var src = iframe.getAttribute('src') || '';
            if (src.indexOf('youtube') !== -1) {
                // YouTube toggles via pauseVideo/playVideo — we send pause since
                // there's no toggle command. The next spacebar sends play.
                iframe.contentWindow.postMessage('{"event":"command","func":"pauseVideo","args":[]}', 'https://www.youtube.com');
            } else if (src.indexOf('vimeo') !== -1) {
                iframe.contentWindow.postMessage('{"method":"pause"}', 'https://player.vimeo.com');
            }
        }
    });

    // ── Page Visibility API — pause when tab is hidden ──────────────────────
    var wasPlayingBeforeHidden = false;

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            // Remember if a video was actively playing so we can resume.
            var active = mainSlider ? mainSlider.slides[mainSlider.activeIndex] : null;
            if (active && active.classList.contains('tvpg-video-slide')) {
                var vid = active.querySelector('video');
                var ifr = active.querySelector('iframe');
                wasPlayingBeforeHidden = !!(vid && !vid.paused) || !!ifr;
            }
            pauseAllVideos();
        } else if (wasPlayingBeforeHidden && settings.autoplay) {
            var active = mainSlider ? mainSlider.slides[mainSlider.activeIndex] : null;
            if (active && active.classList.contains('tvpg-video-slide')) {
                playVideo(active);
            }
            wasPlayingBeforeHidden = false;
        }
    });

    // ── Thumbnail Video Autoplay ────────────────────────────────────────────
    // Explicit .play() as a safety net — the HTML autoplay attribute can be
    // ignored by some browsers/policies even when the video is muted.
    var thumbVideo = document.querySelector('.tvpg-thumb-slider .tvpg-thumb-video');
    if (thumbVideo) {
        // Ensure the video stays muted (required for autoplay policy).
        thumbVideo.muted = true;
        var tp = thumbVideo.play();
        if (tp !== undefined) tp.catch(function () { /* browser blocked */ });
    }


    // ── Lightweight Image Lightbox ─────────────────────────────────────────
    // Replaces the WooCommerce zoom/lightbox that we disable. Only triggers
    // on image slides, never on video slides.
    var lightboxOverlay = null;

    function openLightbox(imgSrc, imgAlt) {
        if (lightboxOverlay) return;
        lightboxOverlay = document.createElement('div');
        lightboxOverlay.className = 'tvpg-lightbox';
        lightboxOverlay.setAttribute('role', 'dialog');
        lightboxOverlay.setAttribute('aria-label', imgAlt || 'Image zoom');
        lightboxOverlay.innerHTML =
            '<button class="tvpg-lightbox-close" aria-label="Close">&times;</button>' +
            '<img src="' + imgSrc + '" alt="' + (imgAlt || '').replace(/"/g, '&quot;') + '" class="tvpg-lightbox-img">';
        document.body.appendChild(lightboxOverlay);
        document.body.style.overflow = 'hidden';

        // Close handlers.
        lightboxOverlay.addEventListener('click', function (e) {
            if (e.target === lightboxOverlay || e.target.classList.contains('tvpg-lightbox-close')) {
                closeLightbox();
            }
        });
        document.addEventListener('keydown', lightboxKeyHandler);
        // Animate in.
        requestAnimationFrame(function () { lightboxOverlay.classList.add('tvpg-lightbox--open'); });
    }

    function closeLightbox() {
        if (!lightboxOverlay) return;
        document.removeEventListener('keydown', lightboxKeyHandler);
        lightboxOverlay.classList.remove('tvpg-lightbox--open');
        lightboxOverlay.addEventListener('transitionend', function () {
            if (lightboxOverlay && lightboxOverlay.parentNode) {
                lightboxOverlay.parentNode.removeChild(lightboxOverlay);
            }
            lightboxOverlay = null;
            document.body.style.overflow = '';
        }, { once: true });
    }

    function lightboxKeyHandler(e) {
        if (e.key === 'Escape') closeLightbox();
    }

    // Click on image slides opens lightbox (not video slides).
    // Gated by the enable_lightbox setting so stores with third-party
    // lightbox plugins can disable ours.
    mainSliderEl.addEventListener('click', function (e) {
        if (!settings.enable_lightbox) return;
        var img = e.target.closest('.swiper-slide:not(.tvpg-video-slide) img');
        if (!img) return;
        // Use full-size src (data-large_image from WC, or src).
        var fullSrc = img.closest('a') ? img.closest('a').getAttribute('href') : null;
        if (!fullSrc) fullSrc = img.getAttribute('data-large_image') || img.getAttribute('data-src') || img.getAttribute('src');
        openLightbox(fullSrc, img.getAttribute('alt'));
    });

    // ── Store Original State ─────────────────────────────────────────────────
    // IMP-9 fix: store image data not the DOM reference, and re-query during reset
    // so we don't write to a detached node if the theme replaced the element.
    var firstImageSlide = mainSliderEl.querySelector('.swiper-slide:not(.tvpg-video-slide)');
    var firstImage = firstImageSlide ? firstImageSlide.querySelector('img') : null;
    var originalImage = {
        src: firstImage ? firstImage.getAttribute('src') : '',
        srcset: firstImage ? firstImage.getAttribute('srcset') : '',
        alt: firstImage ? firstImage.getAttribute('alt') : '',
        index: firstImageSlide ? Array.from(firstImageSlide.parentNode.children).indexOf(firstImageSlide) : 0,
        slideSelector: '.swiper-slide:not(.tvpg-video-slide)'
    };

    var videoSlide = mainSliderEl.querySelector('.swiper-slide.tvpg-video-slide');
    var galleryImageEl = videoSlide ? videoSlide.querySelector('.woocommerce-product-gallery__image') : null;
    var originalVideoHtml = galleryImageEl ? galleryImageEl.innerHTML : '';

    var videoThumbSlide = document.querySelector('.tvpg-thumb-slider .swiper-slide.tvpg-video-thumb-slide');
    var originalVideoThumbHtml = videoThumbSlide ? videoThumbSlide.innerHTML : '';

    /**
     * GHOST-BUG fix: restore original video HTML and clean stale facade state.
     *
     * Why: originalVideoHtml captures the lazy facade snapshot from page load.
     * If the user clicked the facade (activating it into a live iframe), then
     * a variation change restores the facade HTML — but with stale iframes and
     * the tvpg-loaded class. This helper strips that stale state so the facade
     * resets to its clean, clickable form.
     */
    function restoreCleanVideo(container) {
        if (!container || !originalVideoHtml) return;
        container.innerHTML = originalVideoHtml;
        var restoredFacade = container.querySelector('.tvpg-lazy-facade');
        if (restoredFacade) {
            restoredFacade.classList.remove('tvpg-loaded');
            var staleIframe = restoredFacade.querySelector('iframe');
            if (staleIframe) staleIframe.remove();
            var staleSpinner = restoredFacade.querySelector('.tvpg-loading-spinner');
            if (staleSpinner) staleSpinner.remove();
            var staleEmbed = restoredFacade.querySelector('.tvpg-social-embed-inner');
            if (staleEmbed) staleEmbed.remove();
        }
    }

    // ── IMP-02: MutationObserver (replaces 500ms polling) ────────────────────
    // Watches the video slide for theme overwrites and restores our content.
    var currentVideoState = null;
    // BUG-H5 fix: guard against MutationObserver re-entry.
    var isRestoring = false;

    function setExpectedVideoState(html) { currentVideoState = html; }
    function clearExpectedVideoState() { currentVideoState = null; }

    if (videoSlide) {
        var observerTarget = videoSlide.querySelector('.woocommerce-product-gallery__image') || videoSlide;
        var observer = new MutationObserver(function () {
            if (!currentVideoState) return;
            if (isRestoring) return;

            var hasVideo = videoSlide.querySelector('video, iframe');
            var hasThemeImage = videoSlide.querySelector('img.wp-post-image');
            var container = videoSlide.querySelector('.woocommerce-product-gallery__image');
            var isEmpty = container && container.innerHTML.trim() === '';

            if (!hasVideo && (hasThemeImage || isEmpty)) {
                isRestoring = true;
                if (!container) {
                    videoSlide.innerHTML = '<div class="woocommerce-product-gallery__image">' + currentVideoState + '</div>';
                } else {
                    container.innerHTML = currentVideoState;
                }
                playVideo(videoSlide);
                requestAnimationFrame(function () { isRestoring = false; });
            }
        });

        observer.observe(observerTarget, { childList: true, subtree: true });
    }

    // ── Variation Handling ───────────────────────────────────────────────────
    // Wait for WooCommerce to finish its DOM updates before we touch the gallery.
    // requestAnimationFrame fires after WC's synchronous jQuery handlers complete,
    // and the nested rAF ensures the browser has painted the WC changes first.
    function afterDomSettle(callback) {
        requestAnimationFrame(function () {
            requestAnimationFrame(callback);
        });
    }

    function handleVariation(variation) {
        if (!variation) return;

        afterDomSettle(function () {
            var curVideoSlide = mainSliderEl.querySelector('.swiper-slide.tvpg-video-slide');
            var curVideoThumbSlide = document.querySelector('.tvpg-thumb-slider .swiper-slide.tvpg-video-thumb-slide');
            var curFirstImageSlide = mainSliderEl.querySelector('.swiper-slide:not(.tvpg-video-slide)');
            var curFirstImage = curFirstImageSlide ? curFirstImageSlide.querySelector('img') : null;

            // 1. Handle Variation Video.
            if (variation.tvpg_video_html) {
                // BUG-H1 fix: sanitise before DOM injection.
                var safeVideoHtml = sanitiseVideoHtml(variation.tvpg_video_html);
                var safeThumbHtml = sanitiseVideoHtml(variation.tvpg_video_thumb_html);
                setExpectedVideoState(safeVideoHtml);

                if (curVideoSlide) {
                    var container = curVideoSlide.querySelector('.woocommerce-product-gallery__image');
                    if (container) container.innerHTML = safeVideoHtml;

                    if (mainSlider && mainSlider.slides) {
                        mainSlider.slideTo(Array.from(curVideoSlide.parentNode.children).indexOf(curVideoSlide));
                    }
                    playVideo(curVideoSlide);

                    if (curVideoThumbSlide && safeThumbHtml) {
                        curVideoThumbSlide.innerHTML = safeThumbHtml;
                    }
                } else {
                    // Dynamic injection.
                    var newSlideHtml = '<div class="swiper-slide tvpg-video-slide tvpg-dynamic-slide"><div class="woocommerce-product-gallery__image">' + safeVideoHtml + '</div></div>';
                    var newThumbHtml = '<div class="swiper-slide tvpg-video-thumb-slide tvpg-dynamic-slide">' + (safeThumbHtml || '<span class="tvpg-play-icon"></span>') + '</div>';
                    var hasPlaceholder = !!document.querySelector('.tvpg-placeholder-slide');
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
                        playVideo(mainSlider.slides[newIndex]);
                    }
                }
            } else {
                clearExpectedVideoState();
                removeDynamicSlides();

                // GHOST-BUG fix: use the clean restore helper instead of raw innerHTML.
                var curStaticVideoSlide = curVideoSlide || mainSliderEl.querySelector('.swiper-slide.tvpg-video-slide');
                if (curStaticVideoSlide && originalVideoHtml && !curStaticVideoSlide.classList.contains('tvpg-dynamic-slide')) {
                    var c = curStaticVideoSlide.querySelector('.woocommerce-product-gallery__image');
                    restoreCleanVideo(c);
                    if (curVideoThumbSlide && originalVideoThumbHtml) {
                        curVideoThumbSlide.innerHTML = originalVideoThumbHtml;
                        // Re-trigger autoplay after innerHTML injection — the
                        // restored <video> element loses its playing state.
                        var restoredThumb = curVideoThumbSlide.querySelector('.tvpg-thumb-video');
                        if (restoredThumb) {
                            restoredThumb.muted = true;
                            var rp = restoredThumb.play();
                            if (rp !== undefined) rp.catch(function () { });
                        }
                    }
                    // Re-arm the MutationObserver to protect the restored content.
                    setExpectedVideoState(c ? c.innerHTML : originalVideoHtml);
                }
            }

            // 2. Handle Variation Image.
            if (variation && variation.image && variation.image.src && variation.image.src.length > 1) {
                if (curFirstImage) {
                    curFirstImage.setAttribute('src', variation.image.full_src || variation.image.src);
                    curFirstImage.setAttribute('srcset', variation.image.srcset || '');
                    curFirstImage.setAttribute('alt', variation.image.alt || '');
                }

                if (thumbSlider && thumbSlider.slides && originalImage.index >= 0 && thumbSlider.slides[originalImage.index]) {
                    var tImg = thumbSlider.slides[originalImage.index].querySelector('img');
                    if (tImg) {
                        tImg.setAttribute('src', variation.image.gallery_thumbnail_src || variation.image.thumb_src || variation.image.src);
                    }
                }

                if (!variation.tvpg_video_html && mainSlider && mainSlider.slides) {
                    var idx = curFirstImageSlide ? Array.from(curFirstImageSlide.parentNode.children).indexOf(curFirstImageSlide) : 0;
                    mainSlider.slideTo(idx);
                }
            }
        });
    }

    function removeDynamicSlides() {
        if (!document.querySelector('.tvpg-dynamic-slide')) return;

        if (mainSlider) {
            var indices = [];
            Array.from(mainSlider.slides).forEach(function (s, i) {
                if (s.classList.contains('tvpg-dynamic-slide')) indices.push(i);
            });
            indices.sort(function (a, b) { return b - a; });
            indices.forEach(function (i) { mainSlider.removeSlide(i); });
            mainSlider.update();
        }
        if (thumbSlider) {
            var indicesT = [];
            Array.from(thumbSlider.slides).forEach(function (s, i) {
                if (s.classList.contains('tvpg-dynamic-slide')) indicesT.push(i);
            });
            indicesT.sort(function (a, b) { return b - a; });
            indicesT.forEach(function (i) { thumbSlider.removeSlide(i); });
            thumbSlider.update();
        }
    }

    // BUG-H6 fix: removed duplicate vanilla listener for found_variation.
    // WooCommerce triggers via jQuery which also dispatches a native event
    // in jQuery 3.x+, causing handleVariation to fire twice.

    // jQuery bridge — WC triggers jQuery events, so we listen via jQuery if available.
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('found_variation', function (event, variation) {
            handleVariation(variation);
        });

        jQuery(document).on('reset_image', 'form.variations_form', function () {
            // IMP-9 fix: re-query the image element from the live DOM
            // in case the theme replaced the original element.
            var liveFirstSlide = mainSliderEl.querySelector(originalImage.slideSelector);
            var liveFirstImg = liveFirstSlide ? liveFirstSlide.querySelector('img') : null;
            if (liveFirstImg && originalImage.src) {
                liveFirstImg.setAttribute('src', originalImage.src);
                liveFirstImg.setAttribute('srcset', originalImage.srcset || '');
                liveFirstImg.setAttribute('alt', originalImage.alt || '');
            }

            removeDynamicSlides();

            // GHOST-BUG fix: restore video with clean facade state,
            // THEN re-arm the observer. Previous code cleared the observer
            // first, leaving a window where theme overwrites were unprotected.
            var curVideoSlide = mainSliderEl.querySelector('.swiper-slide.tvpg-video-slide');
            if (curVideoSlide && originalVideoHtml && !curVideoSlide.classList.contains('tvpg-dynamic-slide')) {
                var c = curVideoSlide.querySelector('.woocommerce-product-gallery__image');
                restoreCleanVideo(c);
                // Re-arm the observer AFTER restoring — not before.
                setExpectedVideoState(c ? c.innerHTML : originalVideoHtml);
            } else {
                clearExpectedVideoState();
            }

            if (mainSlider) mainSlider.slideTo(originalImage.index);
        });
    }

})();
