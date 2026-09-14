/**
 * True Video Product Gallery - Archive product-card media swap.
 *
 * @package TVPG
 */
(function () {
    'use strict';

    var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    var effectiveType = connection && connection.effectiveType ? String(connection.effectiveType).toLowerCase() : '';
    if ((connection && connection.saveData) || /^(slow-2g|2g|3g)$/.test(effectiveType)) return;

    var states = new Map();
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var desktopHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    var imageDelay = 4000;
    if (typeof tvpgArchiveParams !== 'undefined' && tvpgArchiveParams.settings) {
        var delay = parseInt(tvpgArchiveParams.settings.image_delay, 10);
        if (delay >= 1 && delay <= 30) imageDelay = delay * 1000;
    }

    function styles(element, values) {
        Object.keys(values).forEach(function (key) {
            element.style.setProperty(key, values[key], 'important');
        });
    }

    function within(root, selector) {
        var matches = Array.prototype.slice.call(root.querySelectorAll(selector));
        if (root.nodeType === 1 && root.matches(selector)) matches.unshift(root);
        return matches;
    }

    // A loop link may also contain the title, price and badges. Never wrap those.
    function imageOnly(element) {
        return !!element.querySelector('img') && Array.prototype.every.call(element.childNodes, function (node) {
            if (node.nodeType === 3) return !node.textContent.trim();
            if (node.nodeType === 8) return true;
            if (node.nodeType !== 1) return false;
            if (node.matches('img, source')) return true;
            return node.matches('a, picture, div, span') && imageOnly(node);
        });
    }

    function wrapFallback(root) {
        within(root, '.product, .product-small').forEach(function (product) {
            if (product.querySelector('.tvpg-loop-media')) return;
            var template = product.querySelector('.tvpg-loop-secondary-template');
            if (!template || !template.innerHTML.trim()) return;
            var targets = product.querySelectorAll('.box-image .image-fade_in_back, .box-image a, .woocommerce-LoopProduct-link');
            var target = Array.prototype.find.call(targets, imageOnly);
            var imageRoot = null;
            if (!target) {
                // Standard WooCommerce links often enclose both media and product details.
                // Find the front image, retaining its picture sources/image-only wrapper.
                Array.prototype.some.call(targets, function (candidate) {
                    var image = candidate.querySelector('img:not(.back-image)');
                    if (!image) return false;
                    target = candidate;
                    imageRoot = image.closest('picture') || image;
                    while (imageRoot.parentElement !== target && imageRoot.parentElement && imageOnly(imageRoot.parentElement)) {
                        imageRoot = imageRoot.parentElement;
                    }
                    return true;
                });
            }
            if (!target) return;
            var primary = document.createElement('div');
            primary.className = 'tvpg-loop-primary-media';
            var secondary = document.createElement('div');
            secondary.className = 'tvpg-loop-secondary-media';
            secondary.setAttribute('aria-hidden', 'true');
            secondary.innerHTML = template.innerHTML;
            var card = document.createElement('div');
            card.className = 'tvpg-loop-media';
            card.setAttribute('data-tvpg-loop-media', '1');
            card.appendChild(primary);
            card.appendChild(secondary);
            if (imageRoot) {
                // Keep the original media position and every title/price/badge node intact.
                imageRoot.parentNode.insertBefore(card, imageRoot);
                primary.appendChild(imageRoot);
                // Flatsome can put its alternate image alongside the front image.
                var backImage = card.nextElementSibling;
                while (backImage && (backImage.matches('img.back-image') ||
                    (backImage.matches('picture') && imageOnly(backImage) && !backImage.querySelector('img:not(.back-image)')))) {
                    primary.appendChild(backImage);
                    backImage = card.nextElementSibling;
                }
            } else {
                while (target.firstChild) primary.appendChild(target.firstChild);
                target.appendChild(card);
            }
        });
    }

    function eligible(state) {
        return !state.destroyed && state.card.isConnected && !document.hidden && state.visible;
    }

    function reveal(state, active) {
        state.card.classList.toggle('tvpg-loop-active', active);
        state.host.classList.toggle('tvpg-loop-active', active);
        styles(state.primary, { opacity: active ? '0' : '1', visibility: active ? 'hidden' : 'visible' });
        styles(state.secondary, { opacity: active ? '1' : '0', visibility: active ? 'visible' : 'hidden' });
    }

    function iframeCommand(state, playing) {
        var frame = state.media;
        if (!frame.contentWindow || !frame.getAttribute('src')) return;
        var url;
        try { url = new URL(frame.getAttribute('src'), document.baseURI); } catch (error) { return; }
        var youtube = /(^|\.)youtube(?:-nocookie)?\.com$/.test(url.hostname);
        var vimeo = url.hostname === 'player.vimeo.com';
        if (youtube) {
            if (playing) frame.contentWindow.postMessage('{"event":"command","func":"mute","args":[]}', url.origin);
            frame.contentWindow.postMessage(JSON.stringify({ event: 'command', func: playing ? 'playVideo' : 'pauseVideo', args: [] }), url.origin);
        } else if (vimeo) {
            if (playing) frame.contentWindow.postMessage('{"method":"setVolume","value":0}', url.origin);
            frame.contentWindow.postMessage(JSON.stringify({ method: playing ? 'play' : 'pause' }), url.origin);
        }
    }

    function pause(state) {
        state.desired = false;
        state.request++;
        clearTimeout(state.readyTimer);
        reveal(state, false);
        if (state.kind === 'video') state.media.pause();
        if (state.kind === 'iframe') iframeCommand(state, false);
    }

    function fail(state) {
        state.failed = true;
        stop(state);
    }

    function promote(element, attributes) {
        var changed = false;
        attributes.forEach(function (attribute) {
            var value = element.getAttribute('data-' + attribute);
            if (value !== null) {
                element.setAttribute(attribute, value);
                element.removeAttribute('data-' + attribute);
                changed = true;
            }
        });
        return changed;
    }

    function activate(state) {
        if (state.activated) return;
        state.activated = true;
        var media = state.media;
        if (state.kind === 'video') {
            // Existing eager markup remains supported, but playback belongs to this controller.
            media.autoplay = false;
            media.removeAttribute('autoplay');
            media.muted = true;
            media.playsInline = true;
            var changed = promote(media, ['poster', 'src']);
            media.querySelectorAll('source').forEach(function (source) {
                changed = promote(source, ['src']) || changed;
            });
            if (changed) media.load();
        } else if (state.kind === 'img') {
            // sizes precedes srcset/src to avoid an unnecessary default-size request.
            state.secondary.querySelectorAll('picture source').forEach(function (source) {
                promote(source, ['sizes', 'srcset']);
            });
            promote(media, ['sizes', 'srcset', 'src']);
        } else if (!media.getAttribute('src') && media.getAttribute('data-src')) {
            var url = new URL(media.getAttribute('data-src'), document.baseURI);
            // Late navigation must not autoplay independently of our desired state.
            url.searchParams.set('autoplay', '0');
            media.setAttribute('src', url.href);
            media.removeAttribute('data-src');
        }
    }

    function ready(state) {
        if (!state.desired || !eligible(state) || state.failed) return;
        clearTimeout(state.readyTimer);
        reveal(state, true);
    }

    function play(state) {
        if (!eligible(state) || state.failed || state.desired) return;
        state.desired = true;
        var request = ++state.request;
        state.readyTimer = setTimeout(function () {
            if (state.desired && state.request === request) fail(state);
        }, 15000);
        try {
            activate(state);
            if (state.kind === 'video') {
                var promise = state.media.play();
                if (promise && promise.then) {
                    promise.then(function () {
                        if (!state.desired || !eligible(state)) state.media.pause();
                        else if (state.request === request) ready(state);
                    }, function () {
                        // Autoplay rejection can be retried by a later hover/touch.
                        if (state.request === request) pause(state);
                    });
                }
            } else if (state.kind === 'img') {
                if (state.media.complete && state.media.naturalWidth > 0) ready(state);
            } else if (state.loaded) {
                iframeCommand(state, true);
                ready(state);
            }
        } catch (error) {
            fail(state);
        }
    }

    function stop(state) {
        clearTimeout(state.enterTimer);
        clearTimeout(state.cycleTimer);
        state.enterTimer = null;
        state.cycleTimer = null;
        pause(state);
    }

    function cycle(state) {
        state.cycleTimer = setTimeout(function tick() {
            if (!eligible(state) || state.failed) { stop(state); return; }
            if (state.desired) pause(state);
            else play(state);
            state.cycleTimer = setTimeout(tick, imageDelay);
        }, imageDelay);
    }

    function scheduleVisible() {
        var imageCycles = 0;
        states.forEach(function (state) {
            if (state.kind === 'img' && (state.cycleTimer || state.enterTimer)) imageCycles++;
        });
        states.forEach(function (state) {
            if (!eligible(state) || state.failed || state.enterTimer) return;
            if (desktopHover || reducedMotion) return;
            if (state.kind === 'img') {
                if (state.cycleTimer || imageCycles >= 3) return;
                imageCycles++;
            } else if (state.desired) return;
            // Only image cycles are limited; every visible video may play at once.
            state.enterTimer = setTimeout(function () {
                state.enterTimer = null;
                if (!eligible(state)) return;
                if (state.kind === 'img') cycle(state);
                else play(state);
            }, 220);
        });
    }

    function updateVisibility(state, visible) {
        state.visible = visible;
        if (!visible) stop(state);
    }

    var observer = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            var state = states.get(entry.target);
            if (state) updateVisibility(state, entry.isIntersecting && entry.intersectionRatio >= 0.6);
        });
        scheduleVisible();
    }, { threshold: [0, 0.6] }) : null;

    function inViewport(card) {
        var rect = card.getBoundingClientRect();
        var width = Math.max(0, Math.min(rect.right, window.innerWidth) - Math.max(rect.left, 0));
        var height = Math.max(0, Math.min(rect.bottom, window.innerHeight) - Math.max(rect.top, 0));
        return rect.width > 0 && rect.height > 0 && width * height / (rect.width * rect.height) >= 0.6;
    }

    function listen(state, element, event, callback) {
        element.addEventListener(event, callback, { passive: true });
        state.cleanup.push(function () { element.removeEventListener(event, callback); });
    }

    function initArchive(root) {
        root = root || document;
        wrapFallback(root);
        within(root, '.tvpg-loop-media').forEach(function (card) {
            if (states.has(card)) return;
            var primary = card.querySelector('.tvpg-loop-primary-media');
            var secondary = card.querySelector('.tvpg-loop-secondary-media');
            var media = secondary && secondary.querySelector('video, iframe, img');
            if (!primary || !media) return;
            var host = card.closest('.product-small, .product') || card;
            var state = {
                card: card, primary: primary, secondary: secondary, media: media, host: host,
                kind: media.tagName.toLowerCase(), desired: false, request: 0,
                visible: observer ? false : inViewport(card), cleanup: [],
                loaded: media.tagName.toLowerCase() === 'iframe' && !!media.getAttribute('src')
            };
            states.set(card, state);
            host.classList.add('tvpg-has-loop-media');
            // Restrict layout repair to our own image-only media, never the grid/theme boxes.
            if (imageOnly(primary)) {
                styles(card, { display: 'block', position: 'relative', width: '100%' });
                styles(primary, { display: 'block', position: 'relative', 'z-index': '2' });
                primary.querySelectorAll('img:not(.back-image)').forEach(function (img) {
                    styles(img, { display: 'block', opacity: '1', visibility: 'visible', position: 'relative', 'z-index': '2', width: '100%', height: 'auto' });
                });
            }
            styles(secondary, { position: 'absolute', inset: '0', 'z-index': '3' });
            listen(state, media, 'error', function () { fail(state); });
            if (state.kind === 'video') {
                media.autoplay = false;
                media.removeAttribute('autoplay');
                listen(state, media, 'playing', function () {
                    if (!state.desired || !eligible(state)) media.pause();
                    else ready(state);
                });
            } else {
                listen(state, media, 'load', function () {
                    if (state.kind === 'iframe') {
                        state.loaded = true;
                        iframeCommand(state, state.desired && eligible(state) && !state.failed);
                    }
                    if (state.kind !== 'img' || media.naturalWidth > 0) ready(state);
                });
            }
            function manualPlay() {
                updateVisibility(state, inViewport(card));
                play(state);
            }
            listen(state, host, 'mouseenter', function () { if (desktopHover) manualPlay(); });
            listen(state, host, 'mouseleave', function () { if (desktopHover) stop(state); });
            if (!desktopHover) listen(state, host, 'touchstart', manualPlay);
            pause(state);
            if (observer) observer.observe(card);
        });
        scheduleVisible();
    }

    function destroy(state) {
        state.destroyed = true;
        stop(state);
        if (observer) observer.unobserve(state.card);
        state.cleanup.forEach(function (cleanup) { cleanup(); });
        states.delete(state.card);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) states.forEach(stop);
        else {
            states.forEach(function (state) { updateVisibility(state, inViewport(state.card)); });
            scheduleVisible();
        }
    });
    window.addEventListener('pagehide', function () { states.forEach(stop); });
    window.addEventListener('pageshow', function () {
        states.forEach(function (state) { updateVisibility(state, inViewport(state.card)); });
        scheduleVisible();
    });

    // Older browsers still need offscreen cleanup, including on desktop/reduced motion.
    if (!observer) {
        var scrollPending = false;
        var checkViewport = function () {
            if (scrollPending) return;
            scrollPending = true;
            window.requestAnimationFrame(function () {
                scrollPending = false;
                states.forEach(function (state) { updateVisibility(state, inViewport(state.card)); });
                scheduleVisible();
            });
        };
        window.addEventListener('scroll', checkViewport, { passive: true, capture: true });
        window.addEventListener('resize', checkViewport, { passive: true });
    }

    // Public, idempotent entry point for integrations that insert a known subtree.
    window.tvpgInitArchive = initArchive;
    function boot() {
        initArchive(document);
        if (!('MutationObserver' in window) || !document.body) return;
        new MutationObserver(function (records) {
            var roots = new Set();
            var removed = false;
            records.forEach(function (record) {
                if (record.removedNodes.length) removed = true;
                record.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1 || !node.isConnected) return;
                    // Template-only insertions need their product host considered too.
                    roots.add(node.closest('.product, .product-small') || node);
                });
            });
            if (removed) states.forEach(function (state) {
                if (!state.card.isConnected || !state.card.contains(state.media)) destroy(state);
            });
            roots.forEach(function (root) {
                if (!Array.from(roots).some(function (other) { return other !== root && other.contains(root); })) initArchive(root);
            });
            if (removed) scheduleVisible();
        }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
})();
