(function() {
    if (typeof wp !== 'undefined' && wp.editPost && wp.components && wp.element) {
        var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
        var RadioControl = wp.components.RadioControl;
        var SelectControl = wp.components.SelectControl;
        var PanelRow = wp.components.PanelRow;
        var Notice = wp.components.Notice;
        var useSelect = wp.data.useSelect;
        var useDispatch = wp.data.useDispatch;

        function MxChatSettingsPanel() {
            var meta = useSelect(function(select) {
                return select('core/editor').getEditedPostAttribute('meta') || {};
            });

            var editPost = useDispatch('core/editor').editPost;

            // Determine effective visibility with backward compat
            var visibility = meta._mxchat_page_visibility || '';
            if (!visibility && meta._mxchat_hide_chatbot === '1') {
                visibility = 'hide';
            }

            var selectedBot = meta._mxchat_selected_bot || '';

            var elements = [];

            // Visibility radio control
            elements.push(
                wp.element.createElement(
                    PanelRow,
                    null,
                    wp.element.createElement(RadioControl, {
                        label: mxchatMetaBox.strings.visibilityLabel,
                        selected: visibility,
                        options: [
                            { label: mxchatMetaBox.strings.useGlobalSetting, value: '' },
                            { label: mxchatMetaBox.strings.showChatbot, value: 'show' },
                            { label: mxchatMetaBox.strings.hideChatbot, value: 'hide' }
                        ],
                        onChange: function(value) {
                            var newMeta = Object.assign({}, meta, {
                                _mxchat_page_visibility: value,
                                // Sync legacy field
                                _mxchat_hide_chatbot: value === 'hide' ? '1' : ''
                            });
                            editPost({ meta: newMeta });
                        }
                    })
                )
            );

            // Info notice about global setting
            elements.push(
                wp.element.createElement(
                    Notice,
                    {
                        status: 'info',
                        isDismissible: false,
                        className: 'mxchat-info-notice'
                    },
                    mxchatMetaBox.globalAutoshow
                        ? mxchatMetaBox.strings.globalAutoshowOn
                        : mxchatMetaBox.strings.globalAutoshowOff
                )
            );

            // Bot selection if multi-bot is available
            if (mxchatMetaBox.hasMultibot && mxchatMetaBox.availableBots) {
                var botOptions = [
                    { label: mxchatMetaBox.strings.useDefaultBot, value: '' }
                ];

                Object.keys(mxchatMetaBox.availableBots).forEach(function(botId) {
                    botOptions.push({
                        label: mxchatMetaBox.availableBots[botId],
                        value: botId
                    });
                });

                if (botOptions.length > 1) {
                    elements.push(
                        wp.element.createElement(
                            PanelRow,
                            null,
                            wp.element.createElement(SelectControl, {
                                label: mxchatMetaBox.strings.selectBot,
                                value: selectedBot,
                                options: botOptions,
                                onChange: function(value) {
                                    editPost({
                                        meta: Object.assign({}, meta, {
                                            _mxchat_selected_bot: value
                                        })
                                    });
                                }
                            })
                        )
                    );
                }
            }

            return wp.element.createElement(
                PluginDocumentSettingPanel,
                {
                    name: 'mxchat-settings',
                    title: mxchatMetaBox.strings.panelTitle,
                    className: 'mxchat-settings-panel'
                },
                elements
            );
        }

        wp.plugins.registerPlugin('mxchat-settings', {
            render: MxChatSettingsPanel
        });
    }
})();
