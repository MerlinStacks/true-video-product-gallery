/**
 * True Video Product Gallery - Archive product-card media swap.
 *
 * @package TVPG
 */
(function () {
    'use strict';

    function initArchiveMediaSwap() {
        var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        var saveDataEnabled = !!(connection && connection.saveData);
        var effectiveType = connection && connection.effectiveType ? String(connection.effectiveType).toLowerCase() : '';
        var slowConnection = effectiveType === 'slow-2g' || effectiveType === '2g' || effectiveType === '3g';

        if (saveDataEnabled || slowConnection) {
            return;
        }

        document.querySelectorAll('.product, .product-small').forEach(function (productCard) {
            var existing = productCard.querySelector('.tvpg-loop-media');
            if (existing) return;

            var template = productCard.querySelector('.tvpg-loop-secondary-template');
            if (!template || !template.innerHTML.trim()) return;

            var imageTarget = productCard.querySelector('.box-image .image-fade_in_back, .box-image a, .woocommerce-LoopProduct-link, a.woocommerce-LoopProduct-link');
            if (!imageTarget) return;

            var primaryWrap = document.createElement('div');
            primaryWrap.className = 'tvpg-loop-primary-media';
            while (imageTarget.firstChild) {
                primaryWrap.appendChild(imageTarget.firstChild);
            }

            if (!primaryWrap.firstChild) return;

            var secondaryWrap = document.createElement('div');
            secondaryWrap.className = 'tvpg-loop-secondary-media';
            secondaryWrap.setAttribute('aria-hidden', 'true');
            secondaryWrap.innerHTML = template.innerHTML;

            var container = document.createElement('div');
            container.className = 'tvpg-loop-media';
            container.setAttribute('data-tvpg-loop-media', '1');
            container.appendChild(primaryWrap);
            container.appendChild(secondaryWrap);

            imageTarget.appendChild(container);
        });

        var cards = document.querySelectorAll('.tvpg-loop-media');
        if (!cards.length) return;

        function setImportantStyles(el, styles) {
            if (!el) return;
            Object.keys(styles).forEach(function (prop) {
                el.style.setProperty(prop, styles[prop], 'important');
            });
        }

        function disableThemeEqualize(grid) {
            if (!grid || !grid.querySelector('.tvpg-has-loop-media')) return;
            grid.classList.remove('equalize-box');
            grid.classList.remove('has-equal-box-heights');

            grid.querySelectorAll('.col-inner, .product-small.box').forEach(function (el) {
                el.style.setProperty('height', 'auto', 'important');
                el.style.setProperty('min-height', '0', 'important');
            });
        }

        function runEqualizeCleanup() {
            document.querySelectorAll('.products').forEach(function (grid) {
                disableThemeEqualize(grid);
            });
        }

        runEqualizeCleanup();
        window.addEventListener('load', runEqualizeCleanup, { passive: true });

        cards.forEach(function (card) {
            var productCard = card.closest('.product, .product-small');
            if (productCard) {
                productCard.classList.add('tvpg-has-loop-media');
            }

            var primaryMedia = card.querySelector('.tvpg-loop-primary-media');
            var secondaryMedia = card.querySelector('.tvpg-loop-secondary-media');
            if (primaryMedia) {
                setImportantStyles(card, {
                    display: 'block',
                    position: 'relative',
                    width: '100%'
                });
                setImportantStyles(primaryMedia, {
                    opacity: '1',
                    visibility: 'visible',
                    display: 'block',
                    position: 'relative',
                    'z-index': '2'
                });
                primaryMedia.querySelectorAll('img').forEach(function (img) {
                    setImportantStyles(img, {
                        display: 'block',
                        opacity: '1',
                        visibility: 'visible',
                        position: 'relative',
                        'z-index': '2',
                        width: '100%',
                        height: 'auto'
                    });
                });
            }
            if (secondaryMedia) {
                setImportantStyles(secondaryMedia, {
                    opacity: '0',
                    visibility: 'hidden',
                    position: 'absolute',
                    inset: '0',
                    'z-index': '3'
                });
            }

            var imageWrap = card.parentElement;
            if (imageWrap) {
                imageWrap.querySelectorAll('img.back-image').forEach(function (img) {
                    if (img && img.parentNode) {
                        img.parentNode.removeChild(img);
                    }
                });
            }
        });

        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var supportsDesktopHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        var archiveCycleTimers = new WeakMap();
        var archiveCycleState = new WeakMap();
        var archiveFadeOutTimers = new WeakMap();
        var archiveEnterTimers = new WeakMap();
        var visibleImageCards = new Set();
        var maxConcurrentImageCycles = 3;
        var archiveEnterDelay = 220;
        var archiveImageDelay = 4000;
        if (typeof tvpgArchiveParams !== 'undefined' && tvpgArchiveParams.settings && tvpgArchiveParams.settings.image_delay) {
            var parsedDelay = parseInt(tvpgArchiveParams.settings.image_delay, 10);
            if (!isNaN(parsedDelay) && parsedDelay >= 1 && parsedDelay <= 30) {
                archiveImageDelay = parsedDelay * 1000;
            }
        }

        function getProviderFromIframe(iframe) {
            var src = iframe.getAttribute('src') || iframe.getAttribute('data-src') || '';
            if (src.indexOf('youtube') !== -1) return 'youtube';
            if (src.indexOf('vimeo') !== -1) return 'vimeo';
            return null;
        }

        function playMedia(card) {
            var mediaWrap = card.querySelector('.tvpg-loop-secondary-media');
            var primaryMedia = card.querySelector('.tvpg-loop-primary-media');
            if (!mediaWrap || !primaryMedia) return;

            var pendingFadeOut = archiveFadeOutTimers.get(card);
            if (pendingFadeOut) {
                clearTimeout(pendingFadeOut);
                archiveFadeOutTimers.delete(card);
            }

            card.classList.add('tvpg-loop-active');
            var host = card.closest('.product-small, .product, li.product');
            if (host) host.classList.add('tvpg-loop-active');
            setImportantStyles(primaryMedia, {
                opacity: '0',
                visibility: 'hidden'
            });
            setImportantStyles(mediaWrap, {
                opacity: '1',
                visibility: 'visible'
            });
            var video = mediaWrap.querySelector('video');
            var iframe = mediaWrap.querySelector('iframe');

            if (video) {
                var p = video.play();
                if (p && p.catch) p.catch(function () { });
                return;
            }

            if (iframe && iframe.contentWindow) {
                var provider = getProviderFromIframe(iframe);

                if (!iframe.getAttribute('src') && iframe.getAttribute('data-src')) {
                    iframe.addEventListener('load', function () {
                        playMedia(card);
                    }, { once: true });
                    iframe.setAttribute('src', iframe.getAttribute('data-src'));
                    iframe.removeAttribute('data-src');
                    return;
                }

                if (provider === 'youtube') {
                    iframe.contentWindow.postMessage('{"event":"command","func":"mute","args":[]}', 'https://www.youtube.com');
                    iframe.contentWindow.postMessage('{"event":"command","func":"playVideo","args":[]}', 'https://www.youtube.com');
                } else if (provider === 'vimeo') {
                    iframe.contentWindow.postMessage('{"method":"setVolume", "value":0}', 'https://player.vimeo.com');
                    iframe.contentWindow.postMessage('{"method":"play"}', 'https://player.vimeo.com');
                }
            }
        }

        function pauseMedia(card) {
            var mediaWrap = card.querySelector('.tvpg-loop-secondary-media');
            var primaryMedia = card.querySelector('.tvpg-loop-primary-media');
            if (!mediaWrap || !primaryMedia) return;

            card.classList.remove('tvpg-loop-active');
            var host = card.closest('.product-small, .product, li.product');
            if (host) host.classList.remove('tvpg-loop-active');
            setImportantStyles(primaryMedia, {
                opacity: '1',
                visibility: 'visible'
            });
            setImportantStyles(mediaWrap, {
                opacity: '0',
                visibility: 'visible'
            });
            var fadeOutTimer = setTimeout(function () {
                if (!card.classList.contains('tvpg-loop-active')) {
                    setImportantStyles(mediaWrap, {
                        visibility: 'hidden'
                    });
                }
                archiveFadeOutTimers.delete(card);
            }, 380);
            archiveFadeOutTimers.set(card, fadeOutTimer);
            var video = mediaWrap.querySelector('video');
            var iframe = mediaWrap.querySelector('iframe');

            if (video) {
                video.pause();
                return;
            }

            if (iframe && iframe.contentWindow) {
                var provider = getProviderFromIframe(iframe);
                if (provider === 'youtube') {
                    iframe.contentWindow.postMessage('{"event":"command","func":"pauseVideo","args":[]}', 'https://www.youtube.com');
                } else if (provider === 'vimeo') {
                    iframe.contentWindow.postMessage('{"method":"pause"}', 'https://player.vimeo.com');
                }
            }
        }

        function hasSecondaryVideoMedia(card) {
            var mediaWrap = card.querySelector('.tvpg-loop-secondary-media');
            if (!mediaWrap) return false;
            return !!mediaWrap.querySelector('video, iframe');
        }

        function stopArchiveImageCycle(card) {
            var timer = archiveCycleTimers.get(card);
            if (timer) {
                clearTimeout(timer);
                archiveCycleTimers.delete(card);
            }
            archiveCycleState.delete(card);
            pauseMedia(card);
        }

        function startArchiveImageCycle(card) {
            if (archiveCycleTimers.get(card)) return;

            archiveCycleState.set(card, false);
            pauseMedia(card);

            function tick() {
                if (!document.body.contains(card)) {
                    stopArchiveImageCycle(card);
                    return;
                }

                var showSecondary = archiveCycleState.get(card);
                if (showSecondary) {
                    pauseMedia(card);
                    archiveCycleState.set(card, false);
                } else {
                    playMedia(card);
                    archiveCycleState.set(card, true);
                }

                archiveCycleTimers.set(card, setTimeout(tick, archiveImageDelay));
            }

            archiveCycleTimers.set(card, setTimeout(tick, archiveImageDelay));
        }

        function rebalanceArchiveImageCycles() {
            var running = 0;
            visibleImageCards.forEach(function (card) {
                if (archiveCycleTimers.get(card)) {
                    running++;
                }
            });

            if (running > maxConcurrentImageCycles) {
                var toStop = running - maxConcurrentImageCycles;
                visibleImageCards.forEach(function (card) {
                    if (toStop <= 0) return;
                    if (archiveCycleTimers.get(card)) {
                        stopArchiveImageCycle(card);
                        toStop--;
                    }
                });
                return;
            }

            if (running < maxConcurrentImageCycles) {
                var capacity = maxConcurrentImageCycles - running;
                visibleImageCards.forEach(function (card) {
                    if (capacity <= 0) return;
                    if (!archiveCycleTimers.get(card)) {
                        startArchiveImageCycle(card);
                        capacity--;
                    }
                });
            }
        }

        function clearArchiveEnterTimer(card) {
            var timer = archiveEnterTimers.get(card);
            if (timer) {
                clearTimeout(timer);
                archiveEnterTimers.delete(card);
            }
        }

        cards.forEach(function (card) {
            var mediaWrap = card.querySelector('.tvpg-loop-secondary-media');
            var primaryMedia = card.querySelector('.tvpg-loop-primary-media');
            if (!mediaWrap || !primaryMedia) return;

            var hoverTarget = card.closest('.product-small, .product, li.product') || card;
            card.classList.remove('tvpg-loop-active');
            hoverTarget.classList.remove('tvpg-loop-active');

            hoverTarget.addEventListener('mouseenter', function () {
                playMedia(card);
            });

            hoverTarget.addEventListener('mouseleave', function () {
                pauseMedia(card);
            });

            if (!supportsDesktopHover && hasSecondaryVideoMedia(card)) {
                hoverTarget.addEventListener('touchstart', function () {
                    playMedia(card);
                }, { passive: true });

                hoverTarget.addEventListener('touchend', function () {
                    // Keep current state; viewport observer controls pause/reset.
                }, { passive: true });
            }

            pauseMedia(card);
        });

        if (reducedMotion || supportsDesktopHover || !('IntersectionObserver' in window)) return;

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting && entry.intersectionRatio >= 0.6) {
                    if (hasSecondaryVideoMedia(entry.target)) {
                        clearArchiveEnterTimer(entry.target);
                        archiveEnterTimers.set(entry.target, setTimeout(function () {
                            playMedia(entry.target);
                            archiveEnterTimers.delete(entry.target);
                        }, archiveEnterDelay));
                    } else {
                        visibleImageCards.add(entry.target);
                        clearArchiveEnterTimer(entry.target);
                        archiveEnterTimers.set(entry.target, setTimeout(function () {
                            rebalanceArchiveImageCycles();
                            archiveEnterTimers.delete(entry.target);
                        }, archiveEnterDelay));
                    }
                } else {
                    clearArchiveEnterTimer(entry.target);
                    if (hasSecondaryVideoMedia(entry.target)) {
                        pauseMedia(entry.target);
                    } else {
                        visibleImageCards.delete(entry.target);
                        stopArchiveImageCycle(entry.target);
                        rebalanceArchiveImageCycles();
                    }
                }
            });
        }, { threshold: [0, 0.6] });

        cards.forEach(function (card) {
            observer.observe(card);
        });
    }

    initArchiveMediaSwap();
})();
