/** Run with Node: node --test tests/tvpg-archive.test.cjs (requires jsdom). */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { JSDOM } = require('jsdom');
const source = readFileSync(require('node:path').join(__dirname, '../assets/js/tvpg-archive.js'), 'utf8');

// Small browser boundary double: real controller, deterministic events/timers/media APIs.
class Element {
    constructor(tag = 'div', attrs = {}) {
        this.tagName = tag.toUpperCase();
        this.nodeType = 1;
        this.attrs = { ...attrs };
        this.events = new Map();
        this.queries = {};
        this.isConnected = true;
        this.values = {};
        this.style = { setProperty: (key, value) => { this.values[key] = value; } };
        const classes = new Set();
        this.classList = {
            add: key => classes.add(key), remove: key => classes.delete(key),
            contains: key => classes.has(key),
            toggle: (key, active) => active ? classes.add(key) : classes.delete(key)
        };
    }
    getAttribute(key) { return this.attrs[key] ?? null; }
    setAttribute(key, value) { this.attrs[key] = value; }
    removeAttribute(key) { delete this.attrs[key]; }
    querySelectorAll(selector) { return this.queries[selector] || []; }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    matches(selector) { return selector === '.tvpg-loop-media' && this.isCard; }
    closest() { return this.host || null; }
    contains(element) { return element === this || element === this.media; }
    getBoundingClientRect() { return { top: 0, left: 0, bottom: 100, right: 100, width: 100, height: 100 }; }
    addEventListener(event, callback) {
        if (!this.events.has(event)) this.events.set(event, new Set());
        this.events.get(event).add(callback);
    }
    removeEventListener(event, callback) { this.events.get(event)?.delete(callback); }
    emit(event) { this.events.get(event)?.forEach(callback => callback()); }
}

function card(kind = 'video', eager = false) {
    const card = new Element();
    card.isCard = true;
    card.host = new Element();
    card.primary = new Element();
    card.secondary = new Element();
    card.media = new Element(kind, { [eager ? 'src' : 'data-src']: kind === 'iframe' ? 'https://www.youtube.com/embed/test?autoplay=1' : 'media.mp4' });
    card.queries['.tvpg-loop-primary-media'] = [card.primary];
    card.queries['.tvpg-loop-secondary-media'] = [card.secondary];
    card.secondary.queries['video, iframe, img'] = [card.media];
    card.media.plays = 0;
    card.media.pauses = 0;
    card.media.loads = 0;
    card.media.play = () => { card.media.plays++; };
    card.media.pause = () => { card.media.pauses++; };
    card.media.load = () => { card.media.loads++; };
    card.media.messages = [];
    card.media.contentWindow = { postMessage: message => card.media.messages.push(JSON.parse(message)) };
    return card;
}

function setup(cards, { desktop = false, reduced = false, observer = true, products = [] } = {}) {
    const document = new Element();
    document.nodeType = 9;
    document.readyState = 'complete';
    document.baseURI = 'https://shop.example/';
    document.body = new Element();
    document.queries['.tvpg-loop-media'] = cards;
    document.queries['.product, .product-small'] = products;
    const window = new Element();
    window.innerWidth = window.innerHeight = 1000;
    window.matchMedia = query => ({ matches: query.includes('reduced') ? reduced : desktop });
    window.requestAnimationFrame = callback => callback();
    let intersection, mutation;
    const observed = new Set();
    if (observer) window.IntersectionObserver = class {
        constructor(callback) { intersection = callback; }
        observe(card) { observed.add(card); }
        unobserve(card) { observed.delete(card); }
    };
    window.MutationObserver = class {
        constructor(callback) { mutation = callback; }
        observe() {}
    };
    let now = 0, id = 0;
    const timers = new Map();
    vm.runInNewContext(source, {
        window, document, navigator: {}, URL,
        IntersectionObserver: window.IntersectionObserver, MutationObserver: window.MutationObserver,
        setTimeout: (callback, delay) => { timers.set(++id, { callback, at: now + delay }); return id; },
        clearTimeout: id => timers.delete(id)
    });
    if (observer) intersection(cards.map(target => ({ target, isIntersecting: true, intersectionRatio: 1 })));
    return {
        window, document, observed, timers,
        visible(card, visible) { intersection([{ target: card, isIntersecting: visible, intersectionRatio: visible ? 1 : 0 }]); },
        mutate(addedNodes = [], removedNodes = []) { mutation([{ addedNodes, removedNodes }]); },
        advance(ms) {
            const end = now + ms;
            while (true) {
                const next = [...timers.entries()].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
                if (!next) break;
                now = next[1].at;
                timers.delete(next[0]);
                next[1].callback();
            }
            now = end;
        }
    };
}

test('touch viewport activates all six videos without a concurrency cap, only after dwell', () => {
    const cards = Array.from({ length: 6 }, () => card());
    cards[0].media.setAttribute('data-poster', 'poster.jpg');
    const app = setup(cards);
    assert.equal(cards[0].media.getAttribute('src'), null);
    app.advance(220);
    cards.forEach(item => {
        assert.equal(item.media.plays, 1);
        assert.equal(item.primary.values.opacity, '1');
        item.media.emit('playing');
        assert.equal(item.primary.values.opacity, '0');
    });
    assert.equal(cards[0].media.getAttribute('poster'), 'poster.jpg');
    app.document.hidden = true;
    app.document.emit('visibilitychange');
    cards.forEach(item => assert.equal(item.primary.values.opacity, '1'));
});

test('desktop hover and reduced motion still pause offscreen and while hidden', () => {
    const item = card('video', true);
    const app = setup([item], { desktop: true, reduced: true });
    app.advance(500);
    assert.equal(item.media.plays, 0);
    item.host.emit('mouseenter');
    item.media.emit('playing');
    app.visible(item, false);
    assert.equal(item.primary.values.opacity, '1');
    app.visible(item, true);
    app.advance(500);
    assert.equal(item.media.plays, 1);
    item.host.emit('mouseenter');
    app.document.hidden = true;
    app.document.emit('visibilitychange');
    app.document.hidden = false;
    app.document.emit('visibilitychange');
    assert.equal(item.media.plays, 2);
});

test('late iframe load cannot reactivate after hover ends; next hover reuses it', () => {
    const item = card('iframe');
    setup([item], { desktop: true });
    item.host.emit('mouseenter');
    assert.equal(new URL(item.media.getAttribute('src')).searchParams.get('autoplay'), '0');
    assert.equal(item.primary.values.opacity, '1');
    item.host.emit('mouseleave');
    item.media.emit('load');
    assert.equal(item.media.messages.at(-1).func, 'pauseVideo');
    assert.equal(item.primary.values.opacity, '1');
    item.host.emit('mouseenter');
    assert.equal(item.media.messages.at(-1).func, 'playVideo');
    assert.equal(item.primary.values.opacity, '0');
});

test('deferred responsive image waits for load and restores primary on error', () => {
    const item = card('img');
    item.media.setAttribute('data-srcset', 'small.jpg 400w, large.jpg 800w');
    item.media.setAttribute('data-sizes', '50vw');
    setup([item], { desktop: true });
    item.host.emit('mouseenter');
    assert.equal(item.media.getAttribute('sizes'), '50vw');
    assert.equal(item.media.getAttribute('srcset'), 'small.jpg 400w, large.jpg 800w');
    assert.equal(item.primary.values.opacity, '1');
    item.media.naturalWidth = 800;
    item.media.emit('load');
    assert.equal(item.primary.values.opacity, '0');
    item.media.emit('error');
    assert.equal(item.primary.values.opacity, '1');
});

test('idempotent scoped AJAX init and removal clean observers, timers and handlers', () => {
    const item = card();
    const app = setup([item]);
    app.window.tvpgInitArchive(item);
    assert.equal(item.host.events.get('touchstart').size, 1);
    assert.equal(app.observed.size, 1);
    const added = card();
    // Simulate an inserted card without a product ancestor.
    added.closest = () => null;
    app.mutate([added]);
    assert.equal(app.observed.size, 2);
    app.visible(added, true);
    app.advance(220);
    assert.equal(added.media.plays, 1);
    item.isConnected = false;
    app.mutate([], [item]);
    assert.equal(app.observed.has(item), false);
    assert.equal(item.host.events.get('touchstart').size, 0);
    assert.equal(item.primary.values.opacity, '1');
});

test('late native play promise and readiness event cannot undo pause', async () => {
    const item = card();
    let resolve;
    item.media.play = () => new Promise(done => { resolve = done; });
    setup([item], { desktop: true });
    item.host.emit('mouseenter');
    item.host.emit('mouseleave');
    const pauses = item.media.pauses;
    resolve();
    await Promise.resolve();
    item.media.emit('playing');
    assert.ok(item.media.pauses > pauses);
    assert.equal(item.primary.values.opacity, '1');
});

test('readiness timeout preserves primary and pauses late iframe load', () => {
    const item = card('iframe');
    const app = setup([item]);
    app.advance(15220);
    item.media.emit('load');
    assert.equal(item.primary.values.opacity, '1');
    assert.equal(item.media.messages.at(-1).func, 'pauseVideo');
});

test('without IntersectionObserver scroll still pauses reduced-motion manual playback', () => {
    const item = card();
    const app = setup([item], { reduced: true, observer: false });
    item.host.emit('touchstart');
    item.media.emit('playing');
    item.getBoundingClientRect = () => ({ top: 2000, bottom: 2100, left: 0, right: 100, width: 100, height: 100 });
    app.window.emit('scroll');
    assert.equal(item.primary.values.opacity, '1');
    assert.equal(item.media.plays, 1);
});

for (const variant of ['mixed images', 'mixed picture', 'image-only Flatsome']) {
    test(`real DOM fallback preserves details and suppresses relocated back image: ${variant}`, () => {
        const front = variant === 'mixed picture'
            ? '<picture id="picture"><source srcset="front.webp" type="image/webp"><img id="front" src="front.jpg"></picture>'
            : '<img id="front" src="front.jpg">';
        const images = `${front}<img id="back" class="back-image" src="back.jpg">`;
        const media = variant === 'image-only Flatsome' ? `<div class="box-image"><div class="image-fade_in_back">${images}</div></div>` : images;
        const dom = new JSDOM(`<!doctype html><html><head></head><body>
            <div class="products equalize-box"><div class="product">
                <a class="woocommerce-LoopProduct-link" href="/product">
                    <span id="badge">Sale</span>${media}<h2 id="title">Product title</h2><span id="price">$20</span>
                </a>
                <template class="tvpg-loop-secondary-template"><img data-src="secondary.jpg"></template>
            </div></div><img id="unrelated" class="back-image" src="other.jpg">
            </body></html>`, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://shop.example/' });
        const { window } = dom;
        const { document } = window;
        try {
            const style = document.createElement('style');
            style.textContent = readFileSync(require('node:path').join(__dirname, '../assets/css/tvpg-archive.css'), 'utf8');
            document.head.appendChild(style);
            window.matchMedia = query => ({ matches: query.includes('hover: hover') });
            window.IntersectionObserver = class { observe() {} unobserve() {} };
            window.HTMLElement.prototype.getBoundingClientRect = () => ({ top: 0, left: 0, right: 100, bottom: 100, width: 100, height: 100 });
            const link = document.querySelector('a');
            const retained = ['badge', 'title', 'price', 'front', 'back'].map(id => document.getElementById(id));
            window.eval(source);
            document.dispatchEvent(new window.Event('DOMContentLoaded'));
            window.tvpgInitArchive(document);
            const card = document.querySelector('.tvpg-loop-media');
            assert.ok(card, 'preview wrapper is created');
            assert.equal(document.querySelectorAll('.tvpg-loop-media').length, 1);
            retained.forEach(node => assert.equal(document.getElementById(node.id), node, 'original node identity survives'));
            for (const id of ['badge', 'title', 'price']) {
                assert.equal(document.getElementById(id).parentNode, link);
                assert.equal(card.contains(document.getElementById(id)), false);
            }
            const primary = card.querySelector('.tvpg-loop-primary-media');
            assert.ok(primary.contains(document.getElementById('front')));
            assert.ok(primary.contains(document.getElementById('back')));
            if (variant === 'mixed picture') assert.equal(document.querySelector('#picture source').getAttribute('srcset'), 'front.webp');
            assert.equal(window.getComputedStyle(document.getElementById('back')).display, 'none');
            assert.notEqual(window.getComputedStyle(document.getElementById('front')).display, 'none');
            assert.notEqual(window.getComputedStyle(document.getElementById('unrelated')).display, 'none');
            assert.ok(document.querySelector('.products').classList.contains('equalize-box'));
            assert.equal(link.getAttribute('href'), '/product');
            const secondary = card.querySelector('.tvpg-loop-secondary-media img');
            assert.equal(secondary.getAttribute('src'), null);
            document.querySelector('.product').dispatchEvent(new window.Event('mouseenter'));
            assert.equal(secondary.getAttribute('src'), 'secondary.jpg', 'fallback preview activates on hover');
            assert.equal(primary.style.opacity, '1', 'front remains visible until ready');
            Object.defineProperty(secondary, 'naturalWidth', { value: 100 });
            secondary.dispatchEvent(new window.Event('load'));
            assert.ok(card.classList.contains('tvpg-loop-active'));
            document.querySelector('.product').dispatchEvent(new window.Event('mouseleave'));
            assert.equal(primary.style.opacity, '1');
        } finally {
            window.close();
        }
    });
}

test('autoplay rejection keeps primary visible and allows a later manual retry', async () => {
    const item = card();
    item.media.play = () => Promise.reject(new Error('NotAllowedError'));
    setup([item], { desktop: true });
    item.host.emit('mouseenter');
    await Promise.resolve();
    assert.equal(item.primary.values.opacity, '1');
    item.media.play = () => Promise.resolve();
    item.host.emit('mouseleave');
    item.host.emit('mouseenter');
    await Promise.resolve();
    assert.equal(item.primary.values.opacity, '0');
});
