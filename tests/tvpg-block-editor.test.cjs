const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const vm = require('node:vm');

function setup(woocommerce) {
    let registration;
    let picker;
    const requests = [];
    const store = { value: woocommerce ? [
        { id: 10, key: '_tvpg_video_url', value: 'old.mp4' },
        { id: 11, key: 'other_plugin', value: 'untouched' }
    ] : { _tvpg_video_url: 'old.mp4', other_plugin: 'untouched' } };
    const wp = {
        element: { createElement(type, props, ...children) {
            return typeof type === 'function' ? type(props || {}) : { type, props: props || {}, children };
        } },
        components: { TextControl: 'TextControl', Button: 'Button', ToggleControl: 'ToggleControl' },
        coreData: { useEntityProp(...args) { requests.push(args); return [store.value, value => { store.value = value; }]; } },
        i18n: { __: text => text },
        media() {
            picker = { on(event, fn) { this.select = fn; }, open() {},
                state() { return { get() { return { first() { return { toJSON() { return { url: 'uploaded.mp4' }; } }; } }; } }; } };
            return picker;
        }
    };
    const wc = woocommerce ? {
        productEditor: { registerProductEditorBlockType(block) { registration = block; } },
        blockTemplates: { useWooBlockProps() { return { className: 'woo-block' }; } }
    } : undefined;
    if (!woocommerce) {
        wp.editor = { PluginDocumentSettingPanel: 'DocumentPanel' };
        wp.plugins = { registerPlugin(name, settings) { registration = settings; } };
    }
    vm.runInNewContext(readFileSync(join(__dirname, '../assets/js/tvpg-block-editor' + (process.env.TVPG_TEST_MINIFIED ? '.min' : '') + '.js'), 'utf8'), {
        wp, window: { wc }, tvpgEditorContext: { woocommerce }, URL
    });
    function render() { return woocommerce ? registration.settings.edit({ context: { postType: 'product' }, attributes: {} }) : registration.render(); }
    function find(node, type, label) {
        if (!node || typeof node !== 'object') return null;
        if (node.type === type && (!label || node.props.label === label || node.children.includes(label))) return node;
        return (node.children || []).map(child => find(child, type, label)).find(Boolean);
    }
    return { store, requests, render, find, picker: () => picker };
}

test('WooCommerce product-form block reads/writes meta_data and preserves metadata IDs and unrelated fields', () => {
    const app = setup(true);
    let view = app.render();
    assert.deepEqual(app.requests[0], ['postType', 'product', 'meta_data']);
    assert.equal(app.find(view, 'TextControl', 'Video URL').props.value, 'old.mp4');
    app.find(view, 'TextControl', 'Video URL').props.onChange('new.mp4');
    assert.equal(app.store.value[0].id, 10);
    assert.equal(app.store.value[0].value, 'new.mp4');
    assert.equal(app.store.value[1].value, 'untouched');
    view = app.render();
    app.find(view, 'TextControl', 'Custom Thumbnail URL').props.onChange('poster.jpg');
    assert.equal(app.store.value.find(item => item.key === '_tvpg_video_thumb_url').value, 'poster.jpg');
    app.find(app.render(), 'ToggleControl').props.onChange(true);
    assert.equal(app.store.value.find(item => item.key === '_tvpg_use_same_video').value, 'yes');
    app.find(app.render(), 'Button', 'Upload Video').props.onClick();
    app.picker().select();
    assert.equal(app.store.value[0].value, 'uploaded.mp4');
});

test('WordPress document sidebar continues using registered meta without WooCommerce editor globals', () => {
    const app = setup(false);
    const view = app.render();
    assert.equal(view.type, 'DocumentPanel');
    assert.deepEqual(app.requests[0], ['postType', 'product', 'meta']);
    app.find(view, 'TextControl', 'Video URL').props.onChange('new.mp4');
    assert.equal(app.store.value._tvpg_video_url, 'new.mp4');
    assert.equal(app.store.value.other_plugin, 'untouched');
});
