(function (wp) {
    const { element, components, i18n, apiFetch } = wp;
    const { useState, useMemo } = element;
    const { ToggleControl, Button, SnackbarList, TextControl } = components;
    const { __ } = i18n;
    const el = element.createElement;
    const ACTIVE_TAB_KEY = 'tvpg_settings_active_tab';

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
    const TabBar = ({ activeTab, onSelect }) => {
        const onTabKeyDown = (event, index) => {
            const key = event.key;
            if (key !== 'ArrowRight' && key !== 'ArrowLeft' && key !== 'Home' && key !== 'End') {
                return;
            }

            event.preventDefault();

            let nextIndex = index;
            if (key === 'ArrowRight') {
                nextIndex = (index + 1) % TABS.length;
            } else if (key === 'ArrowLeft') {
                nextIndex = (index - 1 + TABS.length) % TABS.length;
            } else if (key === 'Home') {
                nextIndex = 0;
            } else if (key === 'End') {
                nextIndex = TABS.length - 1;
            }

            const nextTab = TABS[nextIndex];
            if (!nextTab) return;
            onSelect(nextTab.id);

            requestAnimationFrame(() => {
                const btn = document.querySelector('[data-tvpg-tab="' + nextTab.id + '"]');
                if (btn) btn.focus();
            });
        };

        return el('div', { className: 'tvpg-tab-bar', role: 'tablist', 'aria-label': __('Settings sections', 'true-video-product-gallery') },
            TABS.map((tab) =>
                el('button', {
                    key: tab.id,
                    type: 'button',
                    role: 'tab',
                    id: 'tvpg-tab-' + tab.id,
                    'data-tvpg-tab': tab.id,
                    tabIndex: activeTab === tab.id ? 0 : -1,
                    className: 'tvpg-tab-btn' + (activeTab === tab.id ? ' tvpg-tab-btn--active' : ''),
                    'aria-selected': activeTab === tab.id,
                    'aria-controls': 'tvpg-panel-' + tab.id,
                    onClick: () => onSelect(tab.id),
                    onKeyDown: (event) => onTabKeyDown(event, TABS.findIndex((item) => item.id === tab.id)),
                },
                    el('span', { className: 'dashicons dashicons-' + tab.icon }),
                    tab.label
                )
            )
        );
    };

    /**
     * Renders the Playback settings panel.
     */
    const PlaybackPanel = ({ settings, updateSetting }) =>
        el('div', { className: 'tvpg-card', role: 'tabpanel', id: 'tvpg-panel-playback', 'aria-labelledby': 'tvpg-tab-playback' },
            el('h2', null, __('Playback Settings', 'true-video-product-gallery')),
            el('p', { className: 'tvpg-card-desc' }, __('Control how your product videos behave.', 'true-video-product-gallery')),

            el(ToggleControl, {
                label: __('Autoplay Video', 'true-video-product-gallery'),
                help: __('Start playing the video automatically when the slide is active.', 'true-video-product-gallery'),
                checked: settings.autoplay,
                onChange: (val) => updateSetting('autoplay', val),
            }),
            el(ToggleControl, {
                label: __('Auto-scroll Gallery', 'true-video-product-gallery'),
                help: __('Automatically move through gallery slides. Image slides use the delay below; video slides wait until playback ends.', 'true-video-product-gallery'),
                checked: settings.gallery_autoscroll,
                onChange: (val) => updateSetting('gallery_autoscroll', val),
            }),
            settings.gallery_autoscroll ? el(TextControl, {
                type: 'number',
                min: 1,
                max: 30,
                step: 1,
                label: __('Image Slide Delay (seconds)', 'true-video-product-gallery'),
                help: __('How long each image stays visible before advancing (1-30 seconds).', 'true-video-product-gallery'),
                value: settings.image_delay || 4,
                onChange: (val) => {
                    const parsed = parseInt(val, 10);
                    if (Number.isNaN(parsed)) {
                        updateSetting('image_delay', 4);
                        return;
                    }
                    updateSetting('image_delay', Math.min(30, Math.max(1, parsed)));
                },
            }) : null,
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
        el('div', { className: 'tvpg-card', role: 'tabpanel', id: 'tvpg-panel-display', 'aria-labelledby': 'tvpg-tab-display' },
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
        el('div', { className: 'tvpg-card', role: 'tabpanel', id: 'tvpg-panel-gallery', 'aria-labelledby': 'tvpg-tab-gallery' },
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
            }),
            el(ToggleControl, {
                label: __('Enable Archive Hover Swap', 'true-video-product-gallery'),
                help: __('Show secondary image/video on shop and category product-card hover. Disable this if your theme conflicts with archive media output.', 'true-video-product-gallery'),
                checked: settings.archive_swap,
                onChange: (val) => updateSetting('archive_swap', val),
            }),
            el('div', { style: { height: '16px' } }),
            el(components.RadioControl, {
                label: __('Slide Transition', 'true-video-product-gallery'),
                help: __('Choose how slides move between each other. Slide is the default lightweight option. If fade is not supported by your Swiper build, it will fall back to slide automatically.', 'true-video-product-gallery'),
                selected: settings.transition_effect || 'slide',
                options: [
                    { label: __('Slide (Default)', 'true-video-product-gallery'), value: 'slide' },
                    { label: __('Fade', 'true-video-product-gallery'), value: 'fade' },
                ],
                onChange: (val) => updateSetting('transition_effect', val),
            })
        );

    /** Maps tab id → panel component. */
    const PANELS = {
        playback: PlaybackPanel,
        display: DisplayPanel,
        gallery: GalleryPanel,
    };

    const TAB_DESCRIPTIONS = {
        playback: __('Autoplay and slide progression behavior.', 'true-video-product-gallery'),
        display: __('Video size, placement, and preload strategy.', 'true-video-product-gallery'),
        gallery: __('Navigation, lightbox, and transition style.', 'true-video-product-gallery'),
    };

    /**
     * Root settings application.
     * Manages shared state (settings, saving, notices) and delegates
     * rendering to the active tab panel.
     */
    const App = () => {
        const [settings, setSettings] = useState(tvpgSettings.settings);
        const [savedSettings, setSavedSettings] = useState(tvpgSettings.settings);
        const [isSaving, setIsSaving] = useState(false);
        const [notices, setNotices] = useState([]);
        const [activeTab, setActiveTab] = useState(() => {
            try {
                const stored = window.localStorage.getItem(ACTIVE_TAB_KEY);
                if (stored && TABS.some((tab) => tab.id === stored)) {
                    return stored;
                }
            } catch (err) {
                // Ignore storage errors.
            }
            return 'playback';
        });

        const hasChanges = useMemo(() => JSON.stringify(settings) !== JSON.stringify(savedSettings), [settings, savedSettings]);

        const enabledCount = useMemo(() => {
            const toggles = [
                'autoplay',
                'gallery_autoscroll',
                'mute_autoplay',
                'loop',
                'show_controls',
                'show_arrows',
                'enable_lightbox',
                'archive_swap',
            ];
            return toggles.reduce((sum, key) => sum + (settings[key] ? 1 : 0), 0);
        }, [settings]);

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
            }).then((response) => {
                setIsSaving(false);
                const latest = response && response.settings ? response.settings : settings;
                setSettings(latest);
                setSavedSettings(latest);
                setNotices([{ id: Date.now(), content: __('Settings saved successfully!', 'true-video-product-gallery'), status: 'success' }]);
                setTimeout(() => setNotices([]), 3000);
            }).catch(() => {
                setIsSaving(false);
                setNotices([{ id: Date.now(), content: __('Error saving settings.', 'true-video-product-gallery'), status: 'error' }]);
            });
        };

        const resetChanges = () => {
            setSettings(savedSettings);
            setNotices([{ id: Date.now(), content: __('Unsaved changes were discarded.', 'true-video-product-gallery'), status: 'info' }]);
            setTimeout(() => setNotices([]), 2500);
        };

        const selectTab = (tabId) => {
            setActiveTab(tabId);
            try {
                window.localStorage.setItem(ACTIVE_TAB_KEY, tabId);
            } catch (err) {
                // Ignore storage errors.
            }
        };

        const Panel = PANELS[activeTab];

        return el('div', { className: 'tvpg-settings-wrapper' + (hasChanges ? ' tvpg-settings-dirty' : '') },
            el('div', { className: 'tvpg-header' },
                el('div', { className: 'tvpg-header-copy' },
                    el('h1', null, __('True Video Gallery', 'true-video-product-gallery')),
                    el('p', null, __('Fine-tune playback, transitions, and gallery behavior in one place.', 'true-video-product-gallery'))
                ),
                el('div', { className: 'tvpg-header-actions' },
                    el('span', { className: 'tvpg-status-chip' + (hasChanges ? ' is-dirty' : ' is-synced') },
                        hasChanges ? __('Unsaved changes', 'true-video-product-gallery') : __('All changes saved', 'true-video-product-gallery')
                    ),
                    hasChanges ? el(Button, { variant: 'tertiary', onClick: resetChanges }, __('Reset', 'true-video-product-gallery')) : null,
                    el(Button, {
                        variant: 'primary',
                        isBusy: isSaving,
                        onClick: saveSettings,
                        disabled: isSaving || !hasChanges,
                    }, isSaving ? __('Saving…', 'true-video-product-gallery') : __('Save Changes', 'true-video-product-gallery'))
                )
            ),
            el('div', { className: 'tvpg-settings-meta' },
                el('span', { className: 'tvpg-meta-pill' }, __('Version', 'true-video-product-gallery') + ' ' + (tvpgSettings.version || '1.x')),
                el('span', { className: 'tvpg-meta-pill' }, __('Active Toggles', 'true-video-product-gallery') + ': ' + enabledCount),
                el('span', { className: 'tvpg-meta-pill' }, __('Current Section', 'true-video-product-gallery') + ': ' + TABS.find((tab) => tab.id === activeTab).label)
            ),
            el('div', { className: 'tvpg-settings-main' },
                el('div', { className: 'tvpg-settings-primary' },
                    el(TabBar, { activeTab: activeTab, onSelect: selectTab }),

                    el('p', { className: 'tvpg-tab-description' }, TAB_DESCRIPTIONS[activeTab]),
                    el('div', { className: 'tvpg-panel-shell', key: activeTab },
                        el(Panel, { settings: settings, updateSetting: updateSetting })
                    )
                ),
                el('aside', { className: 'tvpg-settings-aside' },
                    el('div', { className: 'tvpg-aside-card' },
                        el('h3', null, __('Quick Notes', 'true-video-product-gallery')),
                        el('ul', null,
                            el('li', null, __('Use Slide transition for the lightest frontend footprint.', 'true-video-product-gallery')),
                            el('li', null, __('Auto-scroll always waits for video completion before advancing.', 'true-video-product-gallery')),
                            el('li', null, __('Use lazy preload when page speed is a priority.', 'true-video-product-gallery'))
                        )
                    )
                )
            ),
            hasChanges ? el('div', { className: 'tvpg-sticky-savebar', role: 'region', 'aria-label': __('Unsaved changes actions', 'true-video-product-gallery') },
                el('div', { className: 'tvpg-sticky-savebar-copy' },
                    el('strong', null, __('You have unsaved changes', 'true-video-product-gallery')),
                    el('span', null, __('Save now to apply these settings to your storefront gallery.', 'true-video-product-gallery'))
                ),
                el('div', { className: 'tvpg-sticky-savebar-actions' },
                    el(Button, { variant: 'tertiary', onClick: resetChanges }, __('Discard', 'true-video-product-gallery')),
                    el(Button, {
                        variant: 'primary',
                        isBusy: isSaving,
                        onClick: saveSettings,
                        disabled: isSaving,
                    }, isSaving ? __('Saving…', 'true-video-product-gallery') : __('Save Changes', 'true-video-product-gallery'))
                )
            ) : null,
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
