(function (wp) {
    const { element, components, i18n, apiFetch } = wp;
    const { useState } = element;
    const { ToggleControl, Button, SnackbarList } = components;
    const { __ } = i18n;
    const el = element.createElement;

    /**
     * Tab definitions — order here determines render order.
     * Each tab maps to a render function below.
     */
    const TABS = [
        { id: 'playback', label: __('Playback', 'true-video-product-gallery'), icon: 'controls-play' },
        { id: 'display', label: __('Display', 'true-video-product-gallery'), icon: 'visibility' },
        { id: 'gallery', label: __('Gallery', 'true-video-product-gallery'), icon: 'format-gallery' },
    ];

    /**
     * Renders the horizontal tab bar.
     *
     * @param {string}   activeTab   Currently active tab id.
     * @param {Function} onSelect    Callback when a tab is clicked.
     * @returns {Element} Tab bar element.
     */
    const TabBar = ({ activeTab, onSelect }) =>
        el('div', { className: 'tvpg-tab-bar', role: 'tablist' },
            TABS.map((tab) =>
                el('button', {
                    key: tab.id,
                    type: 'button',
                    role: 'tab',
                    className: 'tvpg-tab-btn' + (activeTab === tab.id ? ' tvpg-tab-btn--active' : ''),
                    'aria-selected': activeTab === tab.id,
                    onClick: () => onSelect(tab.id),
                },
                    el('span', { className: 'dashicons dashicons-' + tab.icon }),
                    tab.label
                )
            )
        );

    /**
     * Renders the Playback settings panel.
     */
    const PlaybackPanel = ({ settings, updateSetting }) =>
        el('div', { className: 'tvpg-card', role: 'tabpanel' },
            el('h2', null, __('Playback Settings', 'true-video-product-gallery')),
            el('p', { className: 'tvpg-card-desc' }, __('Control how your product videos behave.', 'true-video-product-gallery')),

            el(ToggleControl, {
                label: __('Autoplay Video', 'true-video-product-gallery'),
                help: __('Start playing the video automatically when the slide is active.', 'true-video-product-gallery'),
                checked: settings.autoplay,
                onChange: (val) => updateSetting('autoplay', val),
            }),
            el(ToggleControl, {
                label: __('Mute Autoplay', 'true-video-product-gallery'),
                help: __('Required by most browsers for autoplay to work.', 'true-video-product-gallery'),
                checked: settings.mute_autoplay,
                onChange: (val) => updateSetting('mute_autoplay', val),
                disabled: !settings.autoplay,
            }),
            el(ToggleControl, {
                label: __('Loop Video', 'true-video-product-gallery'),
                help: __('Restart the video automatically after it finishes.', 'true-video-product-gallery'),
                checked: settings.loop,
                onChange: (val) => updateSetting('loop', val),
            }),
            el(ToggleControl, {
                label: __('Show Player Controls', 'true-video-product-gallery'),
                help: __('Show play/pause, volume, and timeline controls. (Note: YouTube/Vimeo have their own rules)', 'true-video-product-gallery'),
                checked: settings.show_controls,
                onChange: (val) => updateSetting('show_controls', val),
            })
        );

    /**
     * Renders the Display settings panel.
     */
    const DisplayPanel = ({ settings, updateSetting }) =>
        el('div', { className: 'tvpg-card', role: 'tabpanel' },
            el('h2', null, __('Display Settings', 'true-video-product-gallery')),
            el('p', { className: 'tvpg-card-desc' }, __('Choose how videos appear in the product gallery.', 'true-video-product-gallery')),

            el(components.RadioControl, {
                label: __('Video Sizing', 'true-video-product-gallery'),
                help: __('Choose how videos should fit within the gallery slide.', 'true-video-product-gallery'),
                selected: settings.video_sizing || 'contain',
                options: [
                    { label: __('Fit to Screen (Contain) - Ensures the whole video is visible.', 'true-video-product-gallery'), value: 'contain' },
                    { label: __('Fill Screen (Cover) - Crops video to fill the slide.', 'true-video-product-gallery'), value: 'cover' },
                ],
                onChange: (val) => updateSetting('video_sizing', val),
            }),
            el('div', { style: { height: '16px' } }),
            el(components.RadioControl, {
                label: __('Video Position', 'true-video-product-gallery'),
                help: __('Choose where the video slide appears in the gallery.', 'true-video-product-gallery'),
                selected: settings.video_position || 'second',
                options: [
                    { label: __('First Slide', 'true-video-product-gallery'), value: 'first' },
                    { label: __('Second Slide', 'true-video-product-gallery'), value: 'second' },
                    { label: __('Last Slide', 'true-video-product-gallery'), value: 'last' },
                ],
                onChange: (val) => updateSetting('video_position', val),
            }),
            el('div', { style: { height: '16px' } }),
            el(components.RadioControl, {
                label: __('Video Preload Strategy', 'true-video-product-gallery'),
                help: __('Control how videos are loaded for performance.', 'true-video-product-gallery'),
                selected: settings.video_preload || 'lazy',
                options: [
                    { label: __('Lazy (Facade) - Best performance. Shows thumbnail until clicked.', 'true-video-product-gallery'), value: 'lazy' },
                    { label: __('Metadata - Loads video info but not content until played.', 'true-video-product-gallery'), value: 'metadata' },
                    { label: __('Auto - Preload entire video. May impact page speed.', 'true-video-product-gallery'), value: 'auto' },
                ],
                onChange: (val) => updateSetting('video_preload', val),
            })
        );

    /**
     * Renders the Gallery settings panel.
     * Surfaces the show_arrows option that
     * exists in the backend but was previously hidden from the UI.
     */
    const GalleryPanel = ({ settings, updateSetting }) =>
        el('div', { className: 'tvpg-card', role: 'tabpanel' },
            el('h2', null, __('Gallery Settings', 'true-video-product-gallery')),
            el('p', { className: 'tvpg-card-desc' }, __('Customise the product gallery experience.', 'true-video-product-gallery')),

            el(ToggleControl, {
                label: __('Show Navigation Arrows', 'true-video-product-gallery'),
                help: __('Display previous / next arrows on the gallery slider.', 'true-video-product-gallery'),
                checked: settings.show_arrows,
                onChange: (val) => updateSetting('show_arrows', val),
            }),
            el(ToggleControl, {
                label: __('Enable Image Lightbox', 'true-video-product-gallery'),
                help: __('Allow customers to click product images to view a full-size zoom overlay. Disable if using a third-party lightbox plugin.', 'true-video-product-gallery'),
                checked: settings.enable_lightbox,
                onChange: (val) => updateSetting('enable_lightbox', val),
            })
        );

    /** Maps tab id → panel component. */
    const PANELS = {
        playback: PlaybackPanel,
        display: DisplayPanel,
        gallery: GalleryPanel,
    };

    /**
     * Root settings application.
     * Manages shared state (settings, saving, notices) and delegates
     * rendering to the active tab panel.
     */
    const App = () => {
        const [settings, setSettings] = useState(tvpgSettings.settings);
        const [isSaving, setIsSaving] = useState(false);
        const [notices, setNotices] = useState([]);
        const [activeTab, setActiveTab] = useState('playback');

        const updateSetting = (key, value) => {
            setSettings({ ...settings, [key]: value });
        };

        const saveSettings = () => {
            setIsSaving(true);
            apiFetch({
                path: '/tvpg/v1/settings',
                method: 'POST',
                data: settings,
                headers: { 'X-WP-Nonce': tvpgSettings.nonce },
            }).then(() => {
                setIsSaving(false);
                setNotices([{ id: Date.now(), content: __('Settings saved successfully!', 'true-video-product-gallery'), status: 'success' }]);
                setTimeout(() => setNotices([]), 3000);
            }).catch(() => {
                setIsSaving(false);
                setNotices([{ id: Date.now(), content: __('Error saving settings.', 'true-video-product-gallery'), status: 'error' }]);
            });
        };

        const Panel = PANELS[activeTab];

        return el('div', { className: 'tvpg-settings-wrapper' },
            el('div', { className: 'tvpg-header' },
                el('h1', null, __('True Video Gallery', 'true-video-product-gallery')),
                el(Button, { variant: 'primary', isBusy: isSaving, onClick: saveSettings }, __('Save Changes', 'true-video-product-gallery'))
            ),
            el(TabBar, { activeTab: activeTab, onSelect: setActiveTab }),
            el(Panel, { settings: settings, updateSetting: updateSetting }),
            SnackbarList ? el(SnackbarList, {
                notices: notices,
                className: 'tvpg-notices',
                onRemove: () => setNotices([]),
            }) : null
        );
    };

    const root = document.getElementById('tvpg-admin-app');
    if (root) {
        if (element.createRoot) {
            element.createRoot(root).render(el(App));
        } else {
            element.render(el(App), root);
        }
    }
})(window.wp);
