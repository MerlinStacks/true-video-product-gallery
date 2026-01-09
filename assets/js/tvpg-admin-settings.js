(function (wp) {
    const { element, components, i18n, apiFetch } = wp;
    const { useState, useEffect } = element;
    const { ToggleControl, Button, SnackbarList } = components;
    const { __ } = i18n;

    const App = () => {
        const [settings, setSettings] = useState(tvpgSettings.settings);
        const [isSaving, setIsSaving] = useState(false);
        const [notices, setNotices] = useState([]);

        const updateSetting = (key, value) => {
            setSettings({ ...settings, [key]: value });
        };

        const saveSettings = () => {
            setIsSaving(true);
            apiFetch({
                path: '/tvpg/v1/settings',
                method: 'POST',
                data: settings,
                headers: { 'X-WP-Nonce': tvpgSettings.nonce }
            }).then((response) => {
                setIsSaving(false);
                setNotices([{ id: Date.now(), content: 'Settings saved successfully!', status: 'success' }]);
                // auto dismiss
                setTimeout(() => setNotices([]), 3000);
            }).catch((error) => {
                setIsSaving(false);
                setNotices([{ id: Date.now(), content: 'Error saving settings.', status: 'error' }]);
            });
        };

        return element.createElement('div', { className: 'tvpg-settings-wrapper' },
            // Header
            element.createElement('div', { className: 'tvpg-header' },
                element.createElement('h1', null, 'True Video Gallery'),
                element.createElement(Button, { isPrimary: true, isBusy: isSaving, onClick: saveSettings }, 'Save Changes')
            ),

            // Card
            element.createElement('div', { className: 'tvpg-card' },
                element.createElement('h2', null, 'Playback Settings'),
                element.createElement('p', { className: 'tvpg-card-desc' }, 'Control how your product videos behave.'),

                element.createElement(ToggleControl, {
                    label: 'Autoplay Video',
                    help: 'Start playing the video automatically when the slide is active.',
                    checked: settings.autoplay,
                    onChange: (val) => updateSetting('autoplay', val)
                }),

                element.createElement(ToggleControl, {
                    label: 'Mute Autoplay',
                    help: 'Required by most browsers for autoplay to work.',
                    checked: settings.mute_autoplay,
                    onChange: (val) => updateSetting('mute_autoplay', val),
                    disabled: !settings.autoplay
                }),

                element.createElement(ToggleControl, {
                    label: 'Loop Video',
                    help: 'Restart the video automatically after it finishes.',
                    checked: settings.loop,
                    onChange: (val) => updateSetting('loop', val)
                }),

                element.createElement(ToggleControl, {
                    label: 'Show Player Controls',
                    help: 'Show play/pause, volume, and timeline controls. (Note: YouTube/Vimeo have their own rules)',
                    checked: settings.show_controls,
                    onChange: (val) => updateSetting('show_controls', val)
                }),

                element.createElement('hr', { style: { margin: '24px 0', border: '0', borderTop: '1px solid #e2e8f0' } }),

                element.createElement('h3', { style: { fontSize: '16px', fontWeight: '600', marginBottom: '12px' } }, 'Display Settings'),

                element.createElement(components.RadioControl, {
                    label: 'Video Sizing',
                    help: 'Choose how videos should fit within the gallery slide.',
                    selected: settings.video_sizing || 'contain',
                    options: [
                        { label: 'Fit to Screen (Contain) - Ensures the whole video is visible.', value: 'contain' },
                        { label: 'Fill Screen (Cover) - Crops video to fill the slide.', value: 'cover' },
                    ],
                    onChange: (val) => updateSetting('video_sizing', val)
                }),

                element.createElement('div', { style: { height: '16px' } }), // Spacer

                element.createElement(components.RadioControl, {
                    label: 'Video Position',
                    help: 'Choose where the video slide appears in the gallery.',
                    selected: settings.video_position || 'second',
                    options: [
                        { label: 'First Slide', value: 'first' },
                        { label: 'Second Slide', value: 'second' },
                        { label: 'Last Slide', value: 'last' },
                    ],
                    onChange: (val) => updateSetting('video_position', val)
                }),

                element.createElement('div', { style: { height: '16px' } }), // Spacer

                element.createElement(components.RadioControl, {
                    label: 'Video Preload Strategy',
                    help: 'Control how videos are loaded for performance.',
                    selected: settings.video_preload || 'lazy',
                    options: [
                        { label: 'Lazy (Facade) - Best performance. Shows thumbnail until clicked.', value: 'lazy' },
                        { label: 'Metadata - Loads video info but not content until played.', value: 'metadata' },
                        { label: 'Auto - Preload entire video. May impact page speed.', value: 'auto' },
                    ],
                    onChange: (val) => updateSetting('video_preload', val)
                })
            ),

            // Notifications
            // Check if SnackbarList exists, otherwise fallback to simple div
            SnackbarList ? element.createElement(SnackbarList, {
                notices: notices,
                className: 'tvpg-notices',
                onRemove: () => setNotices([])
            }) : null
        );
    };

    const root = document.getElementById('tvpg-admin-app');
    if (root) {
        if (element.createRoot) {
            element.createRoot(root).render(element.createElement(App));
        } else {
            element.render(element.createElement(App), root);
        }
    }

})(window.wp);
