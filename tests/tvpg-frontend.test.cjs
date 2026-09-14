/**
 * DOM regressions: NODE_PATH=/path/to/test-deps/node_modules node --test tests/tvpg-frontend.test.cjs
 * Requires jsdom (test-only dependency; no production build required).
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { JSDOM } = require('jsdom');
const source = readFileSync(join(__dirname, '../assets/js/tvpg-frontend.js'), 'utf8');

function setup(t, { reduced = false, single = false, videoFirst = false, fallback = false, missingSwiper = false, beforeInit } = {}) {
    const imageSlide = '<div class="swiper-slide"><a href="original-full.jpg"><img src="original.jpg" srcset="original.jpg 800w" sizes="80vw" alt="Original"></a></div>';
    const videoSlide = '<div class="swiper-slide tvpg-video-slide"><div class="woocommerce-product-gallery__image"><video src="original.mp4" preload="none"></video></div></div>';
    const thumb = '<div class="swiper-slide"><img src="thumb.jpg" srcset="thumb.jpg 100w" sizes="100px" alt="Original thumb"></div>';
    const videoThumb = '<div class="swiper-slide tvpg-video-thumb-slide"><video class="tvpg-thumb-video" src="thumb.mp4"></video></div>';
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
        assert.equal(app.wrapper.querySelectorAll('.tvpg-autoscroll-toggle').length, 1);
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
    assert.equal(app.wrapper.querySelectorAll('.tvpg-autoscroll-toggle').length, 1);
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

test('autoscroll pauses for visibility, window blur, gallery focus and explicit pause', t => {
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
    const control = app.document.querySelector('.tvpg-autoscroll-toggle');
    control.click();
    assert.equal(scheduled(), false);
    assert.equal(control.textContent, 'Resume slideshow');
    app.visibility(true);
    app.visibility(false);
    assert.equal(scheduled(), false);
    control.click();
    assert.equal(scheduled(), true);
});

test('reduced motion suppresses autoplay and transitions and responds to preference changes', t => {
    const app = setup(t, { reduced: true, videoFirst: true });
    assert.equal(app.main.params.speed, 0);
    app.document.querySelectorAll('video').forEach(video => assert.equal(video.plays || 0, 0));
    assert.equal(app.timers.size, 0);
    assert.equal(app.document.querySelector('.tvpg-autoscroll-toggle').disabled, true);
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

test('real slim entry registers manipulation/fade without replacing theme Swiper', async t => {
    // Resolve the entry's actual imports, omitting only CSS execution (Node has no CSS loader).
    // Requires swiper alongside jsdom; this does not bundle or write any asset.
    const { pathToFileURL } = require('node:url');
    let entry = readFileSync(join(__dirname, '../assets/lib/swiper/swiper-slim.mjs'), 'utf8');
    entry = entry.replace(/import '(swiper\/css[^']*)';/g, (_, specifier) => {
        assert.ok(require.resolve(specifier));
        return '';
    }).replace(/from '(swiper[^']*)'/g, (_, specifier) => `from '${pathToFileURL(require.resolve(specifier)).href}'`);
    const dom = new JSDOM('<div class="swiper"><div class="swiper-wrapper"><div class="swiper-slide">Image</div><div class="swiper-slide">Video</div></div></div>', { pretendToBeVisual: true });
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
});
