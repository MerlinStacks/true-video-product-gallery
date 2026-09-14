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

    var YT_ORIGIN = 'https://www.youtube.com';
    var VM_ORIGIN = 'https://player.vimeo.com';
    var galleryInstances = [];
    var globalListenersBound = false;

    function registerGalleryInstance(instance) {
        galleryInstances.push(instance);
        if (globalListenersBound) return;
        globalListenersBound = true;

        function activeInstances() {
            galleryInstances = galleryInstances.filter(function (item) {
                return document.documentElement.contains(item.wrapper);
            });
            return galleryInstances;
        }

        window.addEventListener('message', function (event) {
            activeInstances().forEach(function (item) { item.handleMessage(event); });
        });

        document.addEventListener('visibilitychange', function () {
            activeInstances().forEach(function (item) { item.handleVisibilityChange(); });
        });
        window.addEventListener('blur', function () {
            activeInstances().forEach(function (item) { item.handleWindowFocus(false); });
        });
        window.addEventListener('focus', function () {
            activeInstances().forEach(function (item) { item.handleWindowFocus(true); });
        });

        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('found_variation', function (event, variation) {
                activeInstances().forEach(function (item) { item.handleVariationEvent(event, variation); });
            });
            jQuery(document).on('reset_image', 'form.variations_form', function (event) {
                activeInstances().forEach(function (item) { item.handleResetEvent(event); });
            });
        }
    }

    function initProductGallery(galleryWrapper) {
        var mainSliderEl = galleryWrapper.querySelector('.tvpg-main-slider');
        if (!mainSliderEl || mainSliderEl.__tvpgInitialized) return;

        mainSliderEl.__tvpgInitialized = true;
        var thumbSliderEl = galleryWrapper.querySelector('.tvpg-thumb-slider');

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

    var rawSettings = (typeof tvpgParams !== 'undefined' && tvpgParams.settings) || {};
    var motionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var reducedMotion = !!(motionQuery && motionQuery.matches);
    var userPaused = false;
    var focusPaused = galleryWrapper.contains(document.activeElement);
    var windowPaused = false;
    var pauseControl = null;
    var settings = {
        autoplay: toBool(rawSettings.autoplay),
        gallery_autoscroll: toBool(rawSettings.gallery_autoscroll),
        image_delay: parseInt(rawSettings.image_delay, 10) || 4,
        mute_autoplay: toBool(rawSettings.mute_autoplay),
        loop: toBool(rawSettings.loop),
        show_controls: toBool(rawSettings.show_controls),
        show_arrows: toBool(rawSettings.show_arrows),
        enable_lightbox: toBool(rawSettings.enable_lightbox),
        transition_effect: rawSettings.transition_effect || 'slide',
        video_sizing: rawSettings.video_sizing || 'contain',
        video_position: rawSettings.video_position || 'second',
        video_preload: rawSettings.video_preload || 'lazy'
    };
    if (settings.image_delay < 1) settings.image_delay = 1;
    if (settings.image_delay > 30) settings.image_delay = 30;
    var needsSlider = (typeof tvpgParams !== 'undefined') ? toBool(tvpgParams.needsSlider) : true;

    // ── Swiper Init ──────────────────────────────────────────────────────────
    var thumbSlider = null;
    var mainSlider = null;

    var GallerySwiper = window.TVPGSwiper || window.Swiper;
    if (needsSlider && typeof GallerySwiper === 'function') {
        mainSliderEl.setAttribute('tabindex', '0');
        if (galleryWrapper) galleryWrapper.classList.add('tvpg-swiper-initialised');

        if (thumbSliderEl) {
            thumbSlider = new GallerySwiper(thumbSliderEl, {
            speed: reducedMotion ? 0 : 300,
            spaceBetween: 10,
            slidesPerView: 4,
            freeMode: { enabled: true, momentum: !reducedMotion },
            watchSlidesProgress: true,
            breakpoints: {
                320: { slidesPerView: 3 },
                640: { slidesPerView: 4 },
                1024: { slidesPerView: 5 }
            }
            });
        }

        var requestedEffect = settings.transition_effect === 'fade' ? 'fade' : 'slide';
        var mainSliderConfig = {
            spaceBetween: 10,
            effect: requestedEffect,
            speed: reducedMotion ? 0 : 400,
            navigation: {
                nextEl: galleryWrapper.querySelector('.swiper-button-next'),
                prevEl: galleryWrapper.querySelector('.swiper-button-prev'),
            },
            thumbs: thumbSlider ? { swiper: thumbSlider } : undefined,
            // IMP-08: keyboard navigation.
            // Handle keys locally, rather than hijacking arrows elsewhere on the page.
            keyboard: { enabled: false },
            // IMP-09: touch events target wrapper to avoid video capture.
            touchEventsTarget: 'wrapper',
            fadeEffect: { crossFade: true },
        };

        try {
            mainSlider = new GallerySwiper(mainSliderEl, mainSliderConfig);
        } catch (err) {
            if (requestedEffect === 'fade') {
                mainSliderConfig.effect = 'slide';
                mainSlider = new GallerySwiper(mainSliderEl, mainSliderConfig);
            } else {
                throw err;
            }
        }
    }

    // ── Video Playback Helpers ───────────────────────────────────────────────
    function getActiveSlide() {
        return mainSlider ? mainSlider.slides[mainSlider.activeIndex] : mainSliderEl.querySelector('.swiper-slide:not([hidden])');
    }

    function getIframeProvider(iframe) {
        var src = iframe.getAttribute('src') || '';
        if (src.indexOf('youtube') !== -1) return 'youtube';
        if (src.indexOf('vimeo') !== -1) return 'vimeo';
        return null;
    }

    function playVideo(slide) {
        if (!slide || slide !== getActiveSlide() || slide.hidden || !settings.autoplay || reducedMotion || document.hidden || windowPaused || lightboxOverlay) return;

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
                iframe.contentWindow.postMessage('{"event":"command","func":"addEventListener","args":["onStateChange"]}', YT_ORIGIN);
                iframe.contentWindow.postMessage('{"event":"command","func":"playVideo","args":[]}', YT_ORIGIN);
            } else if (provider === 'vimeo') {
                if (settings.mute_autoplay) {
                    iframe.contentWindow.postMessage('{"method":"setVolume", "value":0}', VM_ORIGIN);
                }
                iframe.contentWindow.postMessage('{"method":"addEventListener","value":"ended"}', VM_ORIGIN);
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

    function refreshGallery() {
        if (mainSlider) mainSlider.update();
        if (thumbSlider) thumbSlider.update();
        var active = getActiveSlide();
        mainSliderEl.querySelectorAll('.swiper-slide').forEach(function (slide) {
            var video = slide.querySelector('video');
            if (video) video.removeAttribute('autoplay');
            if (slide !== active || slide.hidden || reducedMotion || document.hidden) pauseVideo(slide);
        });
        bindAllNativeVideoEnded();
        attachVideoErrorHandler(mainSliderEl);
        syncGalleryAccessibility();
        syncThumbVideos();
        playVideo(active);
        scheduleAutoAdvanceForActiveSlide();
    }

    // ── Auto Scroll State ───────────────────────────────────────────────────
    var autoScrollTimer = null;

    function canAutoAdvance() {
        return mainSlider && settings.gallery_autoscroll && !reducedMotion && !userPaused && !focusPaused && !windowPaused && !document.hidden && !lightboxOverlay && galleryWrapper.isConnected;
    }

    function clearAutoScrollTimer() {
        if (autoScrollTimer) {
            clearTimeout(autoScrollTimer);
            autoScrollTimer = null;
        }
    }

    function nextSlideAfterDelay(delayMs) {
        clearAutoScrollTimer();
        if (!canAutoAdvance()) return;
        autoScrollTimer = setTimeout(function () {
            if (!canAutoAdvance()) return;
            var slideCount = mainSlider.slides ? mainSlider.slides.length : 0;
            if (slideCount < 2) return;

            var nextIndex = mainSlider.activeIndex;
            for (var step = 0; step < slideCount; step++) {
                nextIndex = (nextIndex + 1) % slideCount;
                if (!mainSlider.slides[nextIndex].hidden) break;
            }

            mainSlider.slideTo(nextIndex);

            // Mobile fallback: keep image-only auto-scroll alive even if a
            // slideChange callback is missed during page scroll/touch gestures.
            var nextSlide = mainSlider.slides[nextIndex];
            if (nextSlide && !nextSlide.classList.contains('tvpg-video-slide')) {
                nextSlideAfterDelay(settings.image_delay * 1000);
            }
        }, delayMs);
    }

    function scheduleAutoAdvanceForActiveSlide() {
        if (!canAutoAdvance()) {
            clearAutoScrollTimer();
            return;
        }

        var active = mainSlider.slides[mainSlider.activeIndex];
        if (!active) return;

        clearAutoScrollTimer();

        // Image slide: move after configured delay.
        if (!active.classList.contains('tvpg-video-slide')) {
            nextSlideAfterDelay(settings.image_delay * 1000);
            return;
        }

        // Video slide: do nothing here; advance happens when video ends.
    }

    function onNativeVideoEnded(event) {
        if (!canAutoAdvance() || !getActiveSlide() || !getActiveSlide().contains(event.target)) return;
        nextSlideAfterDelay(150);
    }

    function bindNativeVideoEnded(slide) {
        if (!slide) return;
        var video = slide.querySelector('video');
        if (!video || video.__tvpgEndedBound) return;
        video.addEventListener('ended', onNativeVideoEnded);
        video.__tvpgEndedBound = true;
    }

    function bindAllNativeVideoEnded() {
        mainSliderEl.querySelectorAll('.swiper-slide.tvpg-video-slide').forEach(function (slide) {
            bindNativeVideoEnded(slide);
        });
    }

    function updatePauseControl() {
        if (!pauseControl) return;
        pauseControl.textContent = reducedMotion ? 'Gallery motion disabled' : (userPaused ? 'Resume slideshow' : 'Pause slideshow');
        pauseControl.disabled = reducedMotion;
    }

    function syncThumbVideos() {
        if (!thumbSliderEl) return;
        thumbSliderEl.querySelectorAll('video').forEach(function (video) {
            video.removeAttribute('autoplay');
            if (reducedMotion || document.hidden || windowPaused || focusPaused || userPaused || lightboxOverlay || video.closest('[hidden]')) {
                video.pause();
            } else {
                video.muted = true;
                var promise = video.play();
                if (promise) promise.catch(function () { /* browser policy */ });
            }
        });
    }

    if (mainSlider && settings.gallery_autoscroll) {
        pauseControl = document.createElement('button');
        pauseControl.type = 'button';
        pauseControl.className = 'tvpg-autoscroll-toggle';
        updatePauseControl();
        galleryWrapper.appendChild(pauseControl);
        pauseControl.addEventListener('click', function () {
            userPaused = !userPaused;
            updatePauseControl();
            syncThumbVideos();
            scheduleAutoAdvanceForActiveSlide();
        });
    }
    galleryWrapper.addEventListener('focusin', function () {
        focusPaused = true;
        clearAutoScrollTimer();
        syncThumbVideos();
    });
    galleryWrapper.addEventListener('focusout', function () {
        setTimeout(function () {
            focusPaused = galleryWrapper.contains(document.activeElement);
            syncThumbVideos();
            scheduleAutoAdvanceForActiveSlide();
        }, 0);
    });
    if (motionQuery) {
        var onMotionChange = function () {
            reducedMotion = motionQuery.matches;
            if (mainSlider) mainSlider.params.speed = reducedMotion ? 0 : 400;
            if (thumbSlider) {
                thumbSlider.params.speed = reducedMotion ? 0 : 300;
                thumbSlider.params.freeMode.momentum = !reducedMotion;
            }
            if (lightboxOverlay) lightboxOverlay.style.transition = reducedMotion ? 'none' : '';
            if (reducedMotion) pauseAllVideos();
            syncThumbVideos();
            updatePauseControl();
            scheduleAutoAdvanceForActiveSlide();
        };
        if (motionQuery.addEventListener) motionQuery.addEventListener('change', onMotionChange);
        else if (motionQuery.addListener) motionQuery.addListener(onMotionChange);
    }

    function onIframeVideoEnded(iframeWindow) {
        if (!mainSlider || !settings.gallery_autoscroll || !iframeWindow) return;
        var active = mainSlider.slides[mainSlider.activeIndex];
        if (!active) return;
        var iframe = active.querySelector('iframe');
        if (!iframe || iframe.contentWindow !== iframeWindow) return;
        nextSlideAfterDelay(150);
    }

    function handleProviderMessage(event) {
        if (event.origin === YT_ORIGIN) {
            var ytData = event.data;
            if (typeof ytData === 'string') {
                try {
                    ytData = JSON.parse(ytData);
                } catch (err) {
                    return;
                }
            }
            if (ytData && ytData.info === 0) {
                onIframeVideoEnded(event.source);
            }
            return;
        }

        if (event.origin === VM_ORIGIN) {
            var vmData = event.data;
            if (typeof vmData === 'string') {
                try {
                    vmData = JSON.parse(vmData);
                } catch (err2) {
                    return;
                }
            }
            if (vmData && vmData.event === 'ended') {
                onIframeVideoEnded(event.source);
            }
        }
    }

    // ── Video Error Handling ──────────────────────────────────────────────────
    function attachVideoErrorHandler(container) {
        if (!container) return;
        var videos = container.querySelectorAll('video');
        videos.forEach(function (video) {
            if (video.__tvpgErrorBound) return;
            video.addEventListener('error', function () {
                var wrapper = video.closest('.woocommerce-product-gallery__image') || video.parentNode;
                var errorEl = document.createElement('div');
                errorEl.className = 'tvpg-video-error';
                errorEl.innerHTML = '<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#94a3b8" stroke-width="1.5"><path d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z"/><line x1="3" y1="3" x2="21" y2="21" stroke="#94a3b8" stroke-width="1.5"/></svg><p>Video unavailable</p>';
                video.replaceWith(errorEl);
            });
            video.__tvpgErrorBound = true;
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
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');

        // IMP-11: Hide spinner when iframe finishes loading.
        iframe.addEventListener('load', function () {
            if (spinner.parentNode) spinner.parentNode.removeChild(spinner);
            var slide = facade.closest('.swiper-slide');
            if (!slide || !mainSliderEl.contains(slide)) return;
            if (slide !== getActiveSlide() || document.hidden || lightboxOverlay) pauseVideo(slide);
            else playVideo(slide);
            if (provider === 'youtube' && iframe.contentWindow) {
                iframe.contentWindow.postMessage('{"event":"listening","id":1,"channel":"widget"}', YT_ORIGIN);
            }
        });

        attachIframeTimeout(iframe, facade);

        if (provider === 'youtube') {
            iframe.contentWindow && iframe.contentWindow.postMessage('{"event":"listening","id":1,"channel":"widget"}', YT_ORIGIN);
        } else if (provider === 'vimeo') {
            iframe.addEventListener('load', function () {
                if (iframe.contentWindow) {
                    iframe.contentWindow.postMessage('{"method":"addEventListener","value":"ended"}', VM_ORIGIN);
                }
            });
        }

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
        function showEmbedError(msg) {
            clearSpinner();
            var err = document.createElement('div');
            err.className = 'tvpg-video-error';
            err.innerHTML = '<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#94a3b8" stroke-width="1.5"><path d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg><p>' + (msg || 'Video unavailable') + '</p>';
            facade.appendChild(err);
        }

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
            s.onerror = function () {
                showEmbedError('Failed to load ' + key + ' embed');
            };
            document.body.appendChild(s);
        }

        if (embedType === 'tiktok') {
            loadScript('https://www.tiktok.com/embed.js', 'tiktok', function () {
                // Re-render any new blockquotes.
                if (window.tiktokEmbed && window.tiktokEmbed.lib) {
                    window.tiktokEmbed.lib.render(facade.querySelectorAll('.tiktok-embed'));
                } else {
                    showEmbedError('TikTok embed not supported');
                }
            });
        } else if (embedType === 'instagram') {
            loadScript('https://www.instagram.com/embed.js', 'instagram', function () {
                if (window.instgrm && window.instgrm.Embeds) {
                    window.instgrm.Embeds.process();
                } else {
                    showEmbedError('Instagram embed not supported');
                }
            });
        }
    });

    // ── Slide Change Events ──────────────────────────────────────────────────
    function syncGalleryAccessibility() {
        var active = getActiveSlide();
        if (thumbSliderEl) {
            thumbSliderEl.querySelectorAll('.swiper-slide').forEach(function (thumb, index) {
                thumb.setAttribute('role', 'button');
                thumb.setAttribute('tabindex', thumb.hidden ? '-1' : '0');
                if (!thumb.hasAttribute('aria-label')) thumb.setAttribute('aria-label', 'Show ' + (thumb.classList.contains('tvpg-video-thumb-slide') ? 'video' : 'image') + ', slide ' + (index + 1));
                var slides = mainSliderEl.querySelectorAll('.swiper-slide');
                thumb.setAttribute('aria-pressed', String(slides[index] === active));
            });
        }
        if (settings.enable_lightbox) {
            mainSliderEl.querySelectorAll('.swiper-slide:not(.tvpg-video-slide) img').forEach(function (img) {
                var trigger = img.closest('a') || img;
                trigger.setAttribute('role', 'button');
                trigger.setAttribute('tabindex', img.closest('.swiper-slide') === active ? '0' : '-1');
                trigger.setAttribute('aria-haspopup', 'dialog');
                trigger.setAttribute('aria-label', 'Zoom image' + (img.alt ? ': ' + img.alt : ''));
            });
        }
    }

    if (thumbSliderEl) {
        thumbSliderEl.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ' && event.key !== 'Spacebar') return;
            var thumb = event.target.closest('.swiper-slide');
            if (!thumb || thumb.hidden || !mainSlider) return;
            event.preventDefault();
            mainSlider.slideTo(Array.from(thumbSliderEl.querySelectorAll('.swiper-slide')).indexOf(thumb));
        });
    }

    if (mainSlider) {
        mainSlider.on('slideChange', function () {
            clearAutoScrollTimer();
            pauseAllVideos();
            var active = mainSlider.slides[mainSlider.activeIndex];
            if (active && active.classList.contains('tvpg-video-slide')) {
                playVideo(active);
            }
            scheduleAutoAdvanceForActiveSlide();
            syncGalleryAccessibility();
        });

    }
    refreshGallery();

    // ── Keyboard: Spacebar Play/Pause ─────────────────────────────────────
    mainSliderEl.addEventListener('keydown', function (e) {
        if (e.defaultPrevented || e.target.closest('input, textarea, select, video, [contenteditable]')) return;
        if (mainSlider && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
            e.preventDefault();
            if ((e.key === 'ArrowRight') !== !!mainSlider.rtlTranslate) mainSlider.slideNext();
            else mainSlider.slidePrev();
            return;
        }
        if (e.target.closest('a, button, [role="button"]')) return;
        if (e.key !== ' ' && e.key !== 'Spacebar') return;
        // Don't hijack spacebar when focus is on a form element.
        var tag = document.activeElement ? document.activeElement.tagName : '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON') return;

        var active = getActiveSlide();
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
    var playingSlideBeforeHidden = null;

    function resumeVisibleVideo() {
        if (document.hidden || windowPaused) return;
        if (playingSlideBeforeHidden === getActiveSlide()) playVideo(playingSlideBeforeHidden);
        playingSlideBeforeHidden = null;
    }

    function handleVisibilityChange() {
        clearAutoScrollTimer();
        syncThumbVideos();
        if (document.hidden) {
            // Remember if a video was actively playing so we can resume.
            var active = getActiveSlide();
            playingSlideBeforeHidden = null;
            if (active && active.classList.contains('tvpg-video-slide')) {
                var vid = active.querySelector('video');
                var ifr = active.querySelector('iframe');
                if ((vid && !vid.paused) || ifr) playingSlideBeforeHidden = active;
            }
            pauseAllVideos();
        } else {
            resumeVisibleVideo();
        }
        scheduleAutoAdvanceForActiveSlide();
    }

    function handleWindowFocus(focused) {
        windowPaused = !focused;
        if (focused) resumeVisibleVideo();
        syncThumbVideos();
        scheduleAutoAdvanceForActiveSlide();
    }

    // ── Thumbnail Video Autoplay ────────────────────────────────────────────
    // Explicit .play() as a safety net — the HTML autoplay attribute can be
    // ignored by some browsers/policies even when the video is muted.
    syncThumbVideos();


    // ── Lightweight Image Lightbox ─────────────────────────────────────────
    // Replaces the WooCommerce zoom/lightbox that we disable. Only triggers
    // on image slides, never on video slides.
    var lightboxOverlay = null;
    var lightboxTrigger = null;
    var previousBodyOverflow = '';
    var lightboxBackground = [];

    function openLightbox(imgSrc, imgAlt, trigger) {
        if (lightboxOverlay) return;
        lightboxTrigger = trigger || document.activeElement;
        previousBodyOverflow = document.body.style.overflow;
        // SECURITY: sanitise src to prevent javascript: URLs in lightbox.
        var safeSrc = imgSrc ? String(imgSrc).replace(/[\x00-\x1F\x7F]/g, '') : '';
        if (/^(javascript|data|vbscript):/i.test(safeSrc)) {
            safeSrc = '';
        }
        lightboxOverlay = document.createElement('div');
        lightboxOverlay.className = 'tvpg-lightbox';
        lightboxOverlay.setAttribute('role', 'dialog');
        lightboxOverlay.setAttribute('aria-modal', 'true');
        lightboxOverlay.setAttribute('tabindex', '-1');
        lightboxOverlay.setAttribute('aria-label', imgAlt || 'Image zoom');
        if (reducedMotion) lightboxOverlay.style.transition = 'none';
        var img = document.createElement('img');
        img.src = safeSrc;
        img.alt = imgAlt || '';
        img.className = 'tvpg-lightbox-img';
        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'tvpg-lightbox-close';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.innerHTML = '&times;';
        lightboxOverlay.appendChild(closeBtn);
        lightboxOverlay.appendChild(img);
        document.body.appendChild(lightboxOverlay);
        lightboxBackground = Array.from(document.body.children).filter(function (node) { return node !== lightboxOverlay; }).map(function (node) {
            var wasInert = node.inert;
            node.inert = true;
            return { node: node, inert: wasInert };
        });
        document.body.style.overflow = 'hidden';
        clearAutoScrollTimer();
        pauseAllVideos();
        syncThumbVideos();
        closeBtn.focus();

        // Close handlers.
        lightboxOverlay.addEventListener('click', function (e) {
            if (e.target === lightboxOverlay || e.target.classList.contains('tvpg-lightbox-close')) {
                closeLightbox();
            }
        });
        document.addEventListener('keydown', lightboxKeyHandler, true);
        document.addEventListener('focusin', lightboxFocusHandler);
        // Animate in.
        var openedOverlay = lightboxOverlay;
        requestAnimationFrame(function () {
            if (lightboxOverlay === openedOverlay) openedOverlay.classList.add('tvpg-lightbox--open');
        });
    }

    function closeLightbox() {
        if (!lightboxOverlay) return;
        document.removeEventListener('keydown', lightboxKeyHandler, true);
        document.removeEventListener('focusin', lightboxFocusHandler);
        lightboxOverlay.remove();
        lightboxOverlay = null;
        lightboxBackground.forEach(function (item) { item.node.inert = item.inert; });
        lightboxBackground = [];
        document.body.style.overflow = previousBodyOverflow;
        if (lightboxTrigger && lightboxTrigger.isConnected) lightboxTrigger.focus();
        else {
            mainSliderEl.setAttribute('tabindex', '0');
            mainSliderEl.focus();
        }
        lightboxTrigger = null;
        syncThumbVideos();
        scheduleAutoAdvanceForActiveSlide();
    }

    function lightboxFocusHandler(event) {
        if (lightboxOverlay && !lightboxOverlay.contains(event.target)) lightboxOverlay.querySelector('button').focus();
    }

    function lightboxKeyHandler(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopImmediatePropagation();
            closeLightbox();
        } else if (e.key === 'Tab') {
            // The close button is the dialog's only interactive element.
            e.preventDefault();
            lightboxOverlay.querySelector('button').focus();
        }
    }

    // Click on image slides opens lightbox (not video slides).
    // Gated by the enable_lightbox setting so stores with third-party
    // lightbox plugins can disable ours.
    function activateLightbox(e) {
        if (!settings.enable_lightbox) return;
        var img = e.target.closest('.swiper-slide:not(.tvpg-video-slide) img');
        if (!img) {
            var link = e.target.closest('.swiper-slide:not(.tvpg-video-slide) a');
            img = link ? link.querySelector('img') : null;
        }
        if (!img) return;
        if (mainSlider && mainSlider.allowClick === false && e.type === 'click') return;
        e.preventDefault();
        // Use full-size src (data-large_image from WC, or src).
        var fullSrc = img.closest('a') ? img.closest('a').getAttribute('href') : null;
        if (!fullSrc) fullSrc = img.getAttribute('data-large_image') || img.getAttribute('data-src') || img.getAttribute('src');
        openLightbox(fullSrc, img.getAttribute('alt'), img.closest('a') || img);
    }
    mainSliderEl.addEventListener('click', activateLightbox);
    mainSliderEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') activateLightbox(e);
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

    var imageAttributes = ['src', 'srcset', 'sizes', 'alt', 'data-large_image', 'data-src'];
    function snapshotImage(image) {
        var attributes = {};
        imageAttributes.forEach(function (name) { attributes[name] = image ? image.getAttribute(name) : null; });
        return attributes;
    }
    function restoreImage(image, attributes) {
        if (!image) return;
        imageAttributes.forEach(function (name) {
            if (attributes[name] === null) image.removeAttribute(name);
            else image.setAttribute(name, attributes[name]);
        });
    }
    function firstImageThumb() {
        return thumbSliderEl ? thumbSliderEl.querySelector('.swiper-slide:not(.tvpg-video-thumb-slide) img') : null;
    }
    var originalImageAttributes = snapshotImage(firstImage);
    var originalThumbAttributes = snapshotImage(firstImageThumb());
    var originalImageHref = firstImage && firstImage.closest('a') ? firstImage.closest('a').getAttribute('href') : null;

    var videoSlide = mainSliderEl.querySelector('.swiper-slide.tvpg-video-slide');
    var galleryImageEl = videoSlide ? videoSlide.querySelector('.woocommerce-product-gallery__image') : null;
    var originalVideoHtml = galleryImageEl ? galleryImageEl.innerHTML : '';

    var videoThumbSlide = galleryWrapper.querySelector('.tvpg-thumb-slider .swiper-slide.tvpg-video-thumb-slide');
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
                refreshGallery();
                requestAnimationFrame(function () { isRestoring = false; });
            }
        });

        observer.observe(observerTarget, { childList: true, subtree: true });
    }

    // ── Variation Handling ───────────────────────────────────────────────────
    // Wait for WooCommerce to finish its DOM updates before we touch the gallery.
    // requestAnimationFrame fires after WC's synchronous jQuery handlers complete,
    // and the nested rAF ensures the browser has painted the WC changes first.
    var variationRevision = 0;
    function afterDomSettle(callback) {
        var revision = ++variationRevision;
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                if (revision === variationRevision && galleryWrapper.isConnected) callback();
            });
        });
    }

    function handleVariation(variation) {
        if (!variation) return;

        afterDomSettle(function () {
            clearAutoScrollTimer();
            pauseAllVideos();
            var curVideoSlide = mainSliderEl.querySelector('.swiper-slide.tvpg-video-slide');
            var curVideoThumbSlide = galleryWrapper.querySelector('.tvpg-thumb-slider .swiper-slide.tvpg-video-thumb-slide');
            var curFirstImageSlide = mainSliderEl.querySelector('.swiper-slide:not(.tvpg-video-slide)');
            var curFirstImage = curFirstImageSlide ? curFirstImageSlide.querySelector('img') : null;

            // 1. Handle Variation Video.
            if (variation.tvpg_video_html) {
                // BUG-H1 fix: sanitise before DOM injection.
                var safeVideoHtml = sanitiseVideoHtml(variation.tvpg_video_html);
                var safeThumbHtml = sanitiseVideoHtml(variation.tvpg_video_thumb_html);
                setExpectedVideoState(safeVideoHtml);

                if (curVideoSlide) {
					curVideoSlide.hidden = false;
					if (curVideoThumbSlide) curVideoThumbSlide.hidden = false;
                    if (mainSlider) mainSlider.update();
                    if (thumbSlider) thumbSlider.update();
                    var container = curVideoSlide.querySelector('.woocommerce-product-gallery__image');
                    if (container) container.innerHTML = safeVideoHtml;
                    bindNativeVideoEnded(curVideoSlide);
                    attachVideoErrorHandler(curVideoSlide);

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
                    var hasPlaceholder = !!galleryWrapper.querySelector('.tvpg-placeholder-slide');
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
                        bindNativeVideoEnded(mainSlider.slides[newIndex]);
                        attachVideoErrorHandler(mainSlider.slides[newIndex]);
                        playVideo(mainSlider.slides[newIndex]);
                    }
                }
            } else {
                clearExpectedVideoState();
                removeDynamicSlides();

                var curStaticVideoSlide = curVideoSlide || mainSliderEl.querySelector('.swiper-slide.tvpg-video-slide');
				if (variation.tvpg_has_video === false && curStaticVideoSlide && !curStaticVideoSlide.classList.contains('tvpg-dynamic-slide')) {
					pauseVideo(curStaticVideoSlide);
					curStaticVideoSlide.hidden = true;
					if (curVideoThumbSlide) curVideoThumbSlide.hidden = true;
					if (mainSlider) mainSlider.update();
					if (thumbSlider) thumbSlider.update();
				} else if (curStaticVideoSlide && originalVideoHtml && !curStaticVideoSlide.classList.contains('tvpg-dynamic-slide')) {
                    curStaticVideoSlide.hidden = false;
                    if (curVideoThumbSlide) curVideoThumbSlide.hidden = false;
                    var c = curStaticVideoSlide.querySelector('.woocommerce-product-gallery__image');
                    restoreCleanVideo(c);
                    if (curVideoThumbSlide && originalVideoThumbHtml) {
                        curVideoThumbSlide.innerHTML = originalVideoThumbHtml;
                    }
                    // Re-arm the MutationObserver to protect the restored content.
                    setExpectedVideoState(c ? c.innerHTML : originalVideoHtml);
                }
            }

            // 2. Handle Variation Image.
            if (variation && variation.image && variation.image.src && variation.image.src.length > 1) {
                if (curFirstImage) {
                    curFirstImage.setAttribute('src', variation.image.src);
                    curFirstImage.setAttribute('srcset', variation.image.srcset || '');
                    curFirstImage.setAttribute('sizes', variation.image.sizes || '');
                    curFirstImage.setAttribute('alt', variation.image.alt || '');
                    curFirstImage.setAttribute('data-large_image', variation.image.full_src || variation.image.src);
                    curFirstImage.setAttribute('data-src', variation.image.src);
                    if (curFirstImage.closest('a')) curFirstImage.closest('a').setAttribute('href', variation.image.full_src || variation.image.src);
                }

                var tImg = firstImageThumb();
                if (tImg) {
                    tImg.setAttribute('src', variation.image.gallery_thumbnail_src || variation.image.thumb_src || variation.image.src);
                    // WooCommerce's srcset/sizes describe the main image, not this thumbnail.
                    tImg.removeAttribute('srcset');
                    tImg.removeAttribute('sizes');
                    tImg.setAttribute('alt', variation.image.alt || '');
                }

                if (!variation.tvpg_video_html && mainSlider && mainSlider.slides) {
                    var idx = curFirstImageSlide ? Array.from(curFirstImageSlide.parentNode.children).indexOf(curFirstImageSlide) : 0;
                    mainSlider.slideTo(idx);
                }
            }
            refreshGallery();
        });
    }

    function removeDynamicSlides() {
        if (!galleryWrapper.querySelector('.tvpg-dynamic-slide')) return;

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

    function variationEventBelongsToGallery(event) {
        var productNode = galleryWrapper.closest('.product');
        return !!productNode && !!event.target && productNode.contains(event.target);
    }

    function handleVariationEvent(event, variation) {
        if (!variationEventBelongsToGallery(event)) return;
        handleVariation(variation);
    }

    function handleResetEvent(event) {
            if (!variationEventBelongsToGallery(event)) return;
            afterDomSettle(function () {
            clearAutoScrollTimer();
            pauseAllVideos();
            // IMP-9 fix: re-query the image element from the live DOM
            // in case the theme replaced the original element.
            var liveFirstSlide = mainSliderEl.querySelector(originalImage.slideSelector);
            var liveFirstImg = liveFirstSlide ? liveFirstSlide.querySelector('img') : null;
            if (liveFirstImg && originalImage.src) {
                restoreImage(liveFirstImg, originalImageAttributes);
                var link = liveFirstImg.closest('a');
                if (link && originalImageHref !== null) link.setAttribute('href', originalImageHref);
            }

            // Restore the thumbnail slider's first image as well.
            restoreImage(firstImageThumb(), originalThumbAttributes);

            removeDynamicSlides();

            // GHOST-BUG fix: restore video with clean facade state,
            // THEN re-arm the observer. Previous code cleared the observer
            // first, leaving a window where theme overwrites were unprotected.
            var curVideoSlide = mainSliderEl.querySelector('.swiper-slide.tvpg-video-slide');
            if (curVideoSlide && originalVideoHtml && !curVideoSlide.classList.contains('tvpg-dynamic-slide')) {
				curVideoSlide.hidden = false;
				if (videoThumbSlide) videoThumbSlide.hidden = false;
                if (videoThumbSlide && originalVideoThumbHtml) videoThumbSlide.innerHTML = originalVideoThumbHtml;
                var c = curVideoSlide.querySelector('.woocommerce-product-gallery__image');
                restoreCleanVideo(c);
                // Re-arm the observer AFTER restoring — not before.
                setExpectedVideoState(c ? c.innerHTML : originalVideoHtml);
            } else {
                clearExpectedVideoState();
            }

            if (mainSlider) {
                mainSlider.update();
                mainSlider.slideTo(originalImage.index);
            }
            refreshGallery();
            });
    }

	registerGalleryInstance({
		wrapper: galleryWrapper,
		handleMessage: handleProviderMessage,
		handleVisibilityChange: handleVisibilityChange,
		handleWindowFocus: handleWindowFocus,
		handleVariationEvent: handleVariationEvent,
		handleResetEvent: handleResetEvent
	});

    }

    function initAllProductGalleries() {
        document.querySelectorAll('.tvpg-gallery-wrapper').forEach(initProductGallery);
    }

    initAllProductGalleries();

    // Allows AJAX Quick-View popups to initialize newly inserted galleries.
    document.addEventListener('tvpg-init-gallery', function () {
        initAllProductGalleries();
        window.dispatchEvent(new Event('resize'));
    });

})();
