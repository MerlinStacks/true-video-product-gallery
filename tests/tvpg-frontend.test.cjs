/**
 * DOM regressions: NODE_PATH=/path/to/test-deps/node_modules node --test tests/tvpg-frontend.test.cjs
 * Requires jsdom (test-only dependency; no production build required).
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { JSDOM } = require('jsdom');
const source = readFileSync(join(__dirname, '../assets/js/tvpg-frontend' + (process.env.TVPG_TEST_MINIFIED ? '.min' : '') + '.js'), 'utf8');

function setup(t, { reduced = false, single = false, videoFirst = false, fallback = false, missingSwiper = false, beforeInit } = {}) {
    const imageSlide = '<div class="swiper-slide"><a href="original-full.jpg"><img src="original.jpg" srcset="original.jpg 800w" sizes="80vw" alt="Original"></a></div>';
    const videoSlide = '<div class="swiper-slide tvpg-video-slide tvpg-product-video-slide"><div class="woocommerce-product-gallery__image"><video src="original.mp4" preload="none"></video></div></div>';
    const thumb = '<div class="swiper-slide"><img src="thumb.jpg" srcset="thumb.jpg 100w" sizes="100px" alt="Original thumb"></div>';
    const videoThumb = '<div class="swiper-slide tvpg-video-thumb-slide tvpg-product-video-thumb-slide"><video class="tvpg-thumb-video" src="thumb.mp4"></video></div>';
    const dom = new JSDOM(`<body><div class="product"><form class="variations_form"></form><div class="tvpg-gallery-wrapper"><div class="tvpg-main-slider"><div class="swiper-wrapper">${single ? videoSlide : (videoFirst ? videoSlide + imageSlide : imageSlide + videoSlide)}</div></div>${single ? '' : `<div class="tvpg-thumb-slider"><div class="swiper-wrapper">${videoFirst ? videoThumb + thumb : thumb + videoThumb}</div></div>`}</div></div><button id="outside">Outside</button></body>`, { url: 'https://shop.test/', runScripts: 'outside-only' });
    t.after(() => dom.window.close());
    const { window } = dom;
    const { document } = window;
    const media = window.HTMLMediaElement.prototype;
    media.play = function () { this.plays = (this.plays || 0) + 1; this.playing = true; return Promise.resolve(); };
    media.pause = function () { this.playing = false; };
    Object.defineProperty(media, 'paused', { get() { return !this.playing; } });
    let hidden = false;
    Object.defineProperty(document, 'hidden', { get: () => hidden });
    const motion = { matches: reduced, addEventListener(type, fn) { this.change = fn; } };
    window.matchMedia = () => motion;
    const frames = [];
    window.requestAnimationFrame = fn => frames.push(fn);
    const timers = new Map();
    let timerId = 0;
    window.setTimeout = (fn, delay) => { timers.set(++timerId, { fn, delay }); return timerId; };
    window.clearTimeout = id => timers.delete(id);
    const handlers = {};
    window.jQuery = () => ({ on(name, selector, fn) { handlers[name] = fn || selector; } });
    const instances = [];
    class Slider {
        constructor(el, params) {
            this.el = el;
            el.swiper = this;
            this.params = params;
            this.activeIndex = 0;
            this.events = {};
            this.update();
            instances.push(this);
        }
        update() { this.slides = Array.from(this.el.querySelector('.swiper-wrapper').children); }
        on(name, fn) { this.events[name] = fn; }
        slideTo(index) {
            const changed = this.activeIndex !== index;
            this.activeIndex = index;
            if (changed) this.events.slideChange?.();
        }
        slideNext() { this.slideTo(Math.min(this.activeIndex + 1, this.slides.length - 1)); }
        slidePrev() { this.slideTo(Math.max(this.activeIndex - 1, 0)); }
        appendSlide(html) { this.el.querySelector('.swiper-wrapper').insertAdjacentHTML('beforeend', html); this.update(); }
        prependSlide(html) { this.el.querySelector('.swiper-wrapper').insertAdjacentHTML('afterbegin', html); this.update(); }
        removeSlide(index) { this.slides[index].remove(); this.update(); this.activeIndex = Math.min(this.activeIndex, this.slides.length - 1); }
    }
    const themeSwiper = fallback ? Slider : function () { throw new Error('Theme constructor must not be used'); };
    window.Swiper = themeSwiper;
    if (!fallback) window.TVPGSwiper = Slider;
    if (missingSwiper) {
        delete window.Swiper;
        delete window.TVPGSwiper;
    }
    window.tvpgParams = { needsSlider: !single, settings: { autoplay: true, mute_autoplay: true, gallery_autoscroll: true, enable_lightbox: true, transition_effect: 'fade' } };
    if (beforeInit) beforeInit(window);
    window.eval(source);
    const wrapper = document.querySelector('.tvpg-gallery-wrapper');
    const main = instances.find(item => item.el.classList.contains('tvpg-main-slider'));
    return {
        window, document, wrapper, main, timers, motion, themeSwiper, Slider, instances,
        key(target, key, shiftKey = false) { const event = new window.KeyboardEvent('keydown', { key, shiftKey, bubbles: true, cancelable: true }); target.dispatchEvent(event); return event; },
        flushFrames() { while (frames.length) frames.splice(0).forEach(fn => fn()); },
        runTimers(delay) { [...timers].filter(([, item]) => item.delay === delay).forEach(([id, item]) => { timers.delete(id); item.fn(); }); },
        visibility(value) { hidden = value; document.dispatchEvent(new window.Event('visibilitychange')); },
        variation(value) { handlers.found_variation({ target: document.querySelector('form') }, value); },
        reset() { handlers.reset_image({ target: document.querySelector('form') }); }
    };
}

test('legacy slideshow controls stay hidden in source and production styles', t => {
    const app = setup(t);
    const control = app.document.createElement('button');
    control.className = 'tvpg-autoscroll-toggle';
    control.textContent = 'Pause slideshow';
    app.wrapper.appendChild(control);
    for (const suffix of ['', '.min']) {
        const style = app.document.createElement('style');
        style.textContent = readFileSync(join(__dirname, `../assets/css/tvpg-frontend${suffix}.css`), 'utf8');
        app.document.head.appendChild(style);
        assert.equal(app.window.getComputedStyle(control).display, 'none');
        style.remove();
    }
});

test('plugin constructor wins; compatible legacy global still initializes', t => {
    const app = setup(t);
    assert.equal(app.window.Swiper, app.themeSwiper);
    assert.equal(app.main.params.effect, 'fade');
    assert.ok(setup(t, { fallback: true }).main);
});

test('missing Swiper leaves setup untouched; retry emits readiness exactly once after setup', t => {
    let ready = 0;
    const app = setup(t, { missingSwiper: true });
    const el = app.wrapper.querySelector('.tvpg-main-slider');
    const originalMarkup = app.wrapper.outerHTML;
    const retry = () => app.document.dispatchEvent(new app.window.Event('tvpg-init-gallery'));
    app.document.addEventListener('tvpg-gallery-ready', event => {
        ready++;
        assert.equal(event.target, app.wrapper);
        assert.ok(event instanceof app.window.CustomEvent);
        assert.equal(el.__tvpgInitialized, true);
        assert.equal(app.wrapper.querySelectorAll('.tvpg-autoscroll-toggle').length, 0);
        // Synchronous handoff exercises slide handlers and the late lightbox setup.
        const slider = el.swiper;
        slider.appendSlide('<div class="swiper-slide oc-live-preview-slide"><img src="preview.jpg"></div>');
        slider.slideTo(2);
        slider.slides[2].querySelector('img').click();
        assert.ok(app.document.querySelector('.tvpg-lightbox'));
        retry();
    });
    retry();
    assert.equal(ready, 0);
    assert.equal(el.__tvpgInitialized, undefined);
    assert.equal(app.wrapper.outerHTML, originalMarkup);
    assert.equal(app.timers.size, 0);
    assert.equal(app.motion.change, undefined);
    assert.equal(app.window.__tvpg_loaded, undefined);
    assert.equal(app.document.querySelector('video').plays, undefined);
    app.window.TVPGSwiper = app.Slider;
    retry();
    retry();
    assert.equal(ready, 1);
    assert.equal(app.instances.length, 2);
    assert.equal(el.swiper.activeIndex, 2);
    assert.equal(app.wrapper.querySelectorAll('.tvpg-autoscroll-toggle').length, 0);
    app.key(app.document.activeElement, 'Escape');
    assert.equal(app.document.querySelector('.tvpg-lightbox'), null);
});

test('static gallery emits bubbling readiness once without Swiper', t => {
    let ready = 0;
    const app = setup(t, { single: true, missingSwiper: true, beforeInit(window) {
        window.document.addEventListener('tvpg-gallery-ready', event => {
            ready++;
            const el = event.target.querySelector('.tvpg-main-slider');
            assert.equal(el.__tvpgInitialized, true);
            assert.equal(el.swiper, undefined);
            assert.equal(el.querySelector('video').playing, true);
        });
    } });
    app.document.dispatchEvent(new app.window.Event('tvpg-init-gallery'));
    assert.equal(ready, 1);
});

test('preview selection pauses private autoscroll and ordinary slides resume it', t => {
    const app = setup(t);
    const scheduled = () => [...app.timers.values()].some(timer => timer.delay === 4000);
    app.main.appendSlide('<div class="swiper-slide oc-live-preview-slide"></div>');
    app.main.slideTo(2);
    assert.equal(scheduled(), false);
    app.runTimers(4000);
    assert.equal(app.main.activeIndex, 2);
    app.visibility(true);
    app.visibility(false);
    assert.equal(scheduled(), false);
    app.main.slideTo(0);
    assert.equal(scheduled(), true);
    app.runTimers(4000);
    assert.equal(app.main.activeIndex, 1);
    // A preview becoming active without slideChange must also stop a pending timer.
    app.main.slideTo(0);
    app.main.activeIndex = 2;
    app.runTimers(4000);
    assert.equal(app.main.activeIndex, 2);
    app.main.slideTo(0);
    assert.equal(scheduled(), true);
});

test('only active native slide plays, including no-slider galleries', t => {
    const app = setup(t);
    const video = app.main.slides[1].querySelector('video');
    assert.equal(video.plays || 0, 0);
    app.main.slideTo(1);
    assert.equal(video.playing, true);
    app.main.slideTo(0);
    assert.equal(video.playing, false);
    video.dispatchEvent(new app.window.Event('ended'));
    assert.equal([...app.timers.values()].some(timer => timer.delay === 150), false);
    const single = setup(t, { single: true });
    assert.equal(single.document.querySelector('.tvpg-main-slider video').playing, true);
});

test('video resumes after visibility/focus ordering, but never resumes a stale slide', t => {
    const app = setup(t, { videoFirst: true });
    const video = app.main.slides[0].querySelector('video');
    app.window.dispatchEvent(new app.window.Event('blur'));
    app.visibility(true);
    assert.equal(video.playing, false);
    app.visibility(false);
    assert.equal(video.playing, false);
    app.window.dispatchEvent(new app.window.Event('focus'));
    assert.equal(video.playing, true);
    app.visibility(true);
    app.main.slideTo(1);
    app.visibility(false);
    assert.equal(video.playing, false);
});

test('lightbox Enter/Space activation, modal focus trap, Escape and exact restoration', t => {
    const app = setup(t);
    const trigger = app.document.querySelector('.tvpg-main-slider a');
    trigger.focus();
    app.document.body.style.overflow = 'scroll';
    const product = app.document.querySelector('.product');
    for (const key of ['Enter', ' ']) {
        assert.equal(app.key(trigger, key).defaultPrevented, true);
        const dialog = app.document.querySelector('[role="dialog"]');
        assert.equal(dialog.getAttribute('aria-modal'), 'true');
        assert.equal(app.document.activeElement, dialog.querySelector('button'));
        assert.equal(product.inert, true);
        app.key(app.document.activeElement, 'Tab', true);
        assert.equal(app.document.activeElement, dialog.querySelector('button'));
        app.document.querySelector('#outside').focus();
        assert.equal(app.document.activeElement, dialog.querySelector('button'));
        app.key(app.document.activeElement, 'Escape');
        app.flushFrames(); // Closing before the opening animation must be harmless.
        assert.equal(app.document.querySelector('[role="dialog"]'), null);
        assert.equal(app.document.activeElement, trigger);
        assert.equal(app.document.body.style.overflow, 'scroll');
        assert.ok(!product.inert);
    }
});

test('static and dynamically inserted thumbnails support keyboard activation and pressed state', t => {
    const app = setup(t);
    const thumbs = app.document.querySelectorAll('.tvpg-thumb-slider .swiper-slide');
    assert.equal(thumbs[0].getAttribute('aria-pressed'), 'true');
    app.key(thumbs[1], ' ');
    assert.equal(app.main.activeIndex, 1);
    assert.equal(thumbs[1].getAttribute('aria-pressed'), 'true');
    app.main.slides[1].remove();
    thumbs[1].remove();
    app.main.update();
    app.main.slideTo(0);
    app.variation({ tvpg_video_html: '<video src="new.mp4"></video>', tvpg_video_thumb_html: '<img src="new-thumb.jpg">' });
    app.flushFrames();
    const dynamic = app.document.querySelector('.tvpg-thumb-slider .tvpg-dynamic-slide');
    assert.equal(dynamic.getAttribute('role'), 'button');
    assert.equal(dynamic.getAttribute('tabindex'), '0');
    app.main.slideTo(0);
    app.key(dynamic, 'Enter');
    assert.equal(app.main.activeIndex, 1);
    assert.equal(dynamic.getAttribute('aria-pressed'), 'true');
    assert.equal(app.main.slides[1].querySelector('video').playing, true);
});

test('autoscroll pauses for visibility, window blur and gallery focus without a slideshow control', t => {
    const app = setup(t);
    const scheduled = () => [...app.timers.values()].some(timer => timer.delay === 4000);
    assert.equal(scheduled(), true);
    app.visibility(true);
    assert.equal(scheduled(), false);
    app.visibility(false);
    assert.equal(scheduled(), true);
    app.window.dispatchEvent(new app.window.Event('blur'));
    assert.equal(scheduled(), false);
    app.window.dispatchEvent(new app.window.Event('focus'));
    assert.equal(scheduled(), true);
    app.document.querySelector('.tvpg-main-slider a').focus();
    assert.equal(scheduled(), false);
    app.document.querySelector('#outside').focus();
    app.runTimers(0);
    assert.equal(scheduled(), true);
    assert.equal(app.document.querySelector('.tvpg-autoscroll-toggle'), null);
});

test('reduced motion suppresses autoplay and transitions and responds to preference changes', t => {
    const app = setup(t, { reduced: true, videoFirst: true });
    assert.equal(app.main.params.speed, 0);
    app.document.querySelectorAll('video').forEach(video => assert.equal(video.plays || 0, 0));
    assert.equal(app.timers.size, 0);
    assert.equal(app.document.querySelector('.tvpg-autoscroll-toggle'), null);
    app.motion.matches = false;
    app.motion.change();
    app.main.slideTo(1);
    assert.equal(app.main.params.speed, 400);
    assert.ok(app.timers.size > 0);
    app.motion.matches = true;
    app.motion.change();
    assert.equal(app.timers.size, 0);
    app.document.querySelectorAll('video').forEach(video => assert.equal(video.playing, false));
});

for (const prefix of ['data-', 'data-lazy-']) {
    test(`initial variation reset restores real images rather than ${prefix} placeholders`, t => {
        const app = setup(t, { beforeInit(window) {
            window.document.querySelectorAll('.tvpg-main-slider img, .tvpg-thumb-slider img').forEach(image => {
                for (const name of ['src', 'srcset', 'sizes']) {
                    image.setAttribute(prefix + name, image.getAttribute(name));
                    image.removeAttribute(name);
                }
                image.setAttribute('src', 'data:image/gif;base64,R0lGODlhAQABAAAAACw=');
                image.setAttribute('loading', 'lazy');
            });
        } });
        const image = app.document.querySelector('.tvpg-main-slider img');
        const thumb = app.document.querySelector('.tvpg-thumb-slider img');
        assert.equal(image.getAttribute('src'), 'original.jpg');
        assert.equal(thumb.getAttribute('src'), 'thumb.jpg');
        assert.equal(image.getAttribute('loading'), 'eager');
        app.reset();
        app.flushFrames();
        assert.equal(image.getAttribute('src'), 'original.jpg');
        assert.equal(thumb.getAttribute('src'), 'thumb.jpg');
        app.variation({ image: { src: 'variant.jpg', gallery_thumbnail_src: 'variant-thumb.jpg' } });
        app.flushFrames();
        app.reset();
        app.flushFrames();
        assert.equal(image.getAttribute('srcset'), 'original.jpg 800w');
        assert.equal(image.getAttribute('sizes'), '80vw');
        assert.equal(thumb.getAttribute('srcset'), 'thumb.jpg 100w');
        assert.equal(thumb.getAttribute('src'), 'thumb.jpg');
    });
}

test('variation image/thumbnail responsive attributes and zoom target reset independently', t => {
    const app = setup(t);
    const image = app.document.querySelector('.tvpg-main-slider img');
    const thumb = app.document.querySelector('.tvpg-thumb-slider img');
    app.variation({ image: { src: 'variant.jpg', full_src: 'variant-full.jpg', srcset: 'variant.jpg 900w', sizes: '90vw', gallery_thumbnail_src: 'variant-thumb.jpg' } });
    app.flushFrames();
    assert.equal(image.getAttribute('sizes'), '90vw');
    assert.equal(image.closest('a').getAttribute('href'), 'variant-full.jpg');
    assert.equal(thumb.getAttribute('src'), 'variant-thumb.jpg');
    assert.equal(thumb.getAttribute('srcset'), null);
    assert.equal(thumb.getAttribute('sizes'), null);
    app.reset();
    app.flushFrames();
    assert.equal(image.getAttribute('src'), 'original.jpg');
    assert.equal(image.getAttribute('sizes'), '80vw');
    assert.equal(image.closest('a').getAttribute('href'), 'original-full.jpg');
    assert.equal(thumb.getAttribute('src'), 'thumb.jpg');
    assert.equal(thumb.getAttribute('srcset'), 'thumb.jpg 100w');
    assert.equal(thumb.getAttribute('sizes'), '100px');
});

test('queued variation work is invalidated by newer variations and reset', t => {
    const app = setup(t);
    const image = app.document.querySelector('.tvpg-main-slider img');
    app.variation({ image: { src: 'stale.jpg' } });
    app.reset();
    app.flushFrames();
    assert.equal(image.getAttribute('src'), 'original.jpg');
    app.variation({ image: { src: 'older.jpg' } });
    app.variation({ image: { src: 'latest.jpg' } });
    app.flushFrames();
    assert.equal(image.getAttribute('src'), 'latest.jpg');
});

test('TikTok entity-decoded URLs stay data when the facade is clicked', t => {
    const url = 'https://www.tiktok.com/@user/video/123?x=&quot;&gt;&lt;img&#032;src=x&#032;onerror=window.tvpgProbe=1&gt;';
    const app = setup(t, { beforeInit(window) {
        window.document.querySelector('.tvpg-video-slide .woocommerce-product-gallery__image').innerHTML =
            '<div class="tvpg-social-facade" data-embed-type="tiktok" data-video-id="123" data-video-url="' + url + '"><button>Play</button></div>';
    } });
    const facade = app.document.querySelector('.tvpg-social-facade');
    const decoded = facade.getAttribute('data-video-url');
    facade.querySelector('button').click();
    assert.equal(facade.querySelector('blockquote').getAttribute('cite'), decoded);
    assert.equal(facade.querySelector('img, [onerror]'), null);
    assert.equal(facade.querySelectorAll('blockquote section').length, 1);
});

test('variation removal keeps Swiper indices contiguous, preserves native media and restores parent video', t => {
    const app = setup(t, { beforeInit(window) {
        window.document.querySelector('.tvpg-main-slider .swiper-wrapper').insertAdjacentHTML('afterbegin',
            '<div class="swiper-slide tvpg-video-slide"><div class="woocommerce-product-gallery__image"><video src="native.mp4"></video></div></div>');
        window.document.querySelector('.tvpg-thumb-slider .swiper-wrapper').insertAdjacentHTML('afterbegin',
            '<div class="swiper-slide tvpg-video-thumb-slide"><img src="native.jpg"></div>');
    } });
    const native = app.document.querySelector('video[src="native.mp4"]');
    app.variation({ tvpg_has_video: true, tvpg_video_html: '<video src="variant.mp4"></video>', tvpg_video_thumb_html: '<img src="variant.jpg">' });
    app.flushFrames();
    assert.equal(native.isConnected, true);
    assert.equal(app.document.querySelector('.tvpg-product-video-slide video').getAttribute('src'), 'variant.mp4');
    app.variation({ tvpg_has_video: false, image: { src: 'variant.jpg' } });
    app.flushFrames();
    assert.equal(app.main.slides.length, 2);
    assert.equal(app.document.querySelector('.tvpg-product-video-slide'), null);
    assert.equal(app.document.querySelector('.tvpg-product-video-thumb-slide'), null);
    assert.equal(native.isConnected, true);
    app.main.slideTo(0);
    app.main.slideNext();
    assert.ok(app.main.slides[app.main.activeIndex].querySelector('img'));
    app.reset();
    app.flushFrames();
    assert.equal(app.main.slides.length, 3);
    assert.equal(app.document.querySelector('.tvpg-product-video-slide video').getAttribute('src'), 'original.mp4');
    assert.equal(native.isConnected, true);
});

test('one-image variable galleries reveal thumbnail navigation when a video is added and hide it on reset', t => {
    const app = setup(t, { beforeInit(window) {
        window.document.querySelector('.tvpg-product-video-slide').remove();
        window.document.querySelector('.tvpg-product-video-thumb-slide').remove();
        window.document.querySelector('.tvpg-gallery-wrapper').classList.add('tvpg-single-slide');
        const style = window.document.createElement('style');
        style.textContent = readFileSync(join(__dirname, '../assets/css/tvpg-frontend.css'), 'utf8');
        window.document.head.append(style);
    } });
    app.variation({ tvpg_has_video: true, tvpg_video_html: '<video src="variant.mp4"></video>', tvpg_video_thumb_html: '<img src="variant.jpg">' });
    app.flushFrames();
    assert.equal(app.main.slides.length, 2);
    assert.equal(app.wrapper.classList.contains('tvpg-single-slide'), false);
    assert.notEqual(app.window.getComputedStyle(app.document.querySelector('.tvpg-thumb-slider')).display, 'none');
    app.key(app.document.querySelector('.tvpg-thumb-slider .swiper-slide'), 'Enter');
    assert.equal(app.main.activeIndex, 0);
    app.reset();
    app.flushFrames();
    assert.equal(app.main.slides.length, 1);
    assert.equal(app.wrapper.classList.contains('tvpg-single-slide'), true);
});

for (const provider of ['youtube', 'vimeo']) {
    test(`direct ${provider} waits for its own readiness and obeys active-slide and motion guards`, t => {
        const origin = provider === 'youtube' ? 'https://www.youtube.com' : 'https://player.vimeo.com';
        const app = setup(t, { beforeInit(window) {
            window.document.querySelector('.tvpg-product-video-slide .woocommerce-product-gallery__image').innerHTML =
                `<iframe src="${origin}/${provider === 'youtube' ? 'embed' : 'video'}/123?autoplay=0"></iframe>`;
        } });
        const frame = app.document.querySelector('iframe');
        const messages = [];
        frame.contentWindow.postMessage = message => messages.push(JSON.parse(message));
        const ready = (source = frame.contentWindow) => app.window.dispatchEvent(new app.window.MessageEvent('message', {
            origin, source, data: JSON.stringify(provider === 'youtube' ? { event: 'onReady' } : { method: 'ping', value: true })
        }));
        const plays = () => messages.filter(message => message.func === 'playVideo' || message.method === 'play');
        ready(); // Ready while inactive must not play.
        assert.equal(plays().length, 0);
        frame.dispatchEvent(new app.window.Event('load')); // New document must become ready again.
        app.main.slideTo(1);
        assert.equal(plays().length, 0);
        ready(app.window); // A forged source must not mark the frame ready.
        assert.equal(plays().length, 0);
        ready();
        assert.equal(plays().length, 1);
        app.motion.matches = true;
        app.motion.change();
        ready();
        assert.equal(plays().length, 1);
        app.motion.matches = false;
        app.motion.change();
        app.visibility(true);
        ready();
        assert.equal(plays().length, 1);
    });
}

test('real slim entry registers manipulation/fade without replacing theme Swiper', async t => {
    // Resolve the entry's actual imports, omitting only CSS execution (Node has no CSS loader).
    // Requires swiper alongside jsdom; this does not bundle or write any asset.
    const { pathToFileURL } = require('node:url');
    let entry = readFileSync(join(__dirname, '../assets/lib/swiper/swiper-slim.mjs'), 'utf8');
    entry = entry.replace(/import '(swiper\/css[^']*)';/g, (_, specifier) => {
        assert.ok(require.resolve(specifier));
        return '';
    }).replace(/from '(swiper[^']*)'/g, (_, specifier) => `from '${pathToFileURL(require.resolve(specifier)).href}'`);
    const dom = new JSDOM('<div class="swiper"><div class="swiper-wrapper"><div class="swiper-slide">Image</div><div class="swiper-slide">Video</div></div></div>', { pretendToBeVisual: true, runScripts: 'outside-only' });
    const globals = ['window', 'document', 'HTMLElement', 'HTMLSlotElement', 'requestAnimationFrame', 'cancelAnimationFrame', 'getComputedStyle'];
    const previous = new Map(globals.map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
    for (const key of globals) globalThis[key] = typeof dom.window[key] === 'function' && !key.startsWith('HTML') ? dom.window[key].bind(dom.window) : dom.window[key];
    t.after(() => {
        for (const key of globals) {
            if (previous.get(key)) Object.defineProperty(globalThis, key, previous.get(key));
            else delete globalThis[key];
        }
        dom.window.close();
    });
    const theme = function ThemeSwiper() {};
    dom.window.Swiper = theme;
    await import('data:text/javascript;base64,' + Buffer.from(entry).toString('base64'));
    assert.equal(dom.window.Swiper, theme);
    const slider = new dom.window.TVPGSwiper(dom.window.document.querySelector('.swiper'), { width: 600, height: 400, effect: 'fade', speed: 0 });
    assert.ok(slider.el.classList.contains('swiper-fade'));
    slider.appendSlide('<div class="swiper-slide">Variation</div>');
    slider.prependSlide('<div class="swiper-slide">Placeholder replacement</div>');
    assert.equal(slider.slides.length, 4);
    slider.slideTo(2);
    assert.equal(slider.activeIndex, 2);
    slider.removeSlide(3);
    assert.equal(slider.slides.length, 3);
    slider.destroy();

    // Exercise the controller with the real Swiper grids as well as the fast doubles above.
    dom.window.document.body.innerHTML = '<div class="product"><form class="variations_form"></form><div class="tvpg-gallery-wrapper tvpg-single-slide"><div class="swiper tvpg-main-slider"><div class="swiper-wrapper"><div class="swiper-slide"><img src="image.jpg"></div></div><button class="swiper-button-prev"></button><button class="swiper-button-next"></button></div><div class="swiper tvpg-thumb-slider"><div class="swiper-wrapper"><div class="swiper-slide"><img src="thumb.jpg"></div></div></div></div></div>';
    // Evaluate the browser build in its own realm, as on a storefront.
    dom.window.eval(readFileSync(join(__dirname, '../assets/lib/swiper/swiper-slim.min.js'), 'utf8'));
    const RealSwiper = dom.window.TVPGSwiper;
    dom.window.matchMedia = () => ({ matches: false, addEventListener() {} });
    dom.window.TVPGSwiper = function (el, params) {
        params.width = 600; params.height = 400; params.speed = 0;
        return new RealSwiper(el, params);
    };
    dom.window.HTMLMediaElement.prototype.play = () => Promise.resolve();
    dom.window.HTMLMediaElement.prototype.pause = () => {};
    const frames = [];
    dom.window.requestAnimationFrame = fn => frames.push(fn);
    const flushFrames = () => { while (frames.length) frames.splice(0).forEach(fn => fn()); };
    const handlers = {};
    dom.window.jQuery = () => ({ on(event, selector, fn) { handlers[event] = fn || selector; } });
    dom.window.tvpgParams = { needsSlider: true, settings: { video_position: 'second' } };
    dom.window.eval(source);
    const main = dom.window.document.querySelector('.tvpg-main-slider').swiper;
    const thumbs = dom.window.document.querySelector('.tvpg-thumb-slider').swiper;
    const event = { target: dom.window.document.querySelector('form') };
    handlers.found_variation(event, { tvpg_has_video: true, tvpg_video_html: '<video src="variant.mp4"></video>' });
    flushFrames();
    assert.equal(main.slides.length, 2);
    assert.equal(main.slidesGrid.length, 2);
    assert.equal(main.activeIndex, 1);
    main.slidePrev(0);
    assert.equal(main.activeIndex, 0);
    main.slideNext(0);
    assert.equal(main.activeIndex, 1);
    handlers.found_variation(event, { tvpg_has_video: false });
    flushFrames();
    assert.equal(main.slides.length, 1);
    assert.equal(main.slidesGrid.length, 1);
    assert.equal(thumbs.slides.length, 1);
    assert.equal(main.activeIndex, 0);
    main.destroy();
    thumbs.destroy();
});
