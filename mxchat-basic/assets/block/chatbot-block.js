/**
 * MxChat Chatbot block (plan 95dd1e). No build step — plain wp.element calls,
 * same idiom as assets/editor-assistant/sidebar.js.
 *
 * The editor canvas renders a STATIC placeholder only. Never render the live
 * chatbot here: it would fire the front-end script inside the editor and could
 * start a chat session for the editor. The real widget renders server-side via
 * the block's render_callback (includes/class-mxchat-block.php).
 */
(function (wp) {
    'use strict';

    if (!wp || !wp.blocks || !wp.element) {
        return;
    }

    var el = wp.element.createElement;
    var __ = wp.i18n.__;
    var blockEditor = wp.blockEditor || wp.editor;
    var InspectorControls = blockEditor && blockEditor.InspectorControls;
    var useBlockProps = blockEditor && blockEditor.useBlockProps;
    var PanelBody = wp.components.PanelBody;
    var RadioControl = wp.components.RadioControl;
    var SelectControl = wp.components.SelectControl;

    // Localized by MxChat_Block::register_block(). Non-empty only when the
    // Multi-Bot add-on is active; '' is the "Default Bot" (follow page settings).
    var bots = (window.MxChatBlockData && window.MxChatBlockData.bots) || [];

    function botLabel(botId) {
        for (var i = 0; i < bots.length; i++) {
            if (bots[i].value === botId) {
                return bots[i].label;
            }
        }
        return __('Default Bot', 'mxchat');
    }

    var chatIcon = el(
        'svg',
        { viewBox: '0 0 1120 1120', xmlns: 'http://www.w3.org/2000/svg', 'aria-hidden': 'true', focusable: 'false' },
        el('path', {
            fillRule: 'evenodd',
            clipRule: 'evenodd',
            fill: 'currentColor',
            d: 'M252 434C252 372.144 302.144 322 364 322H770C831.856 322 882 372.144 882 434V614.459L804.595 585.816C802.551 585.06 800.94 583.449 800.184 581.405L763.003 480.924C760.597 474.424 751.403 474.424 748.997 480.924L711.816 581.405C711.06 583.449 709.449 585.06 707.405 585.816L606.924 622.997C600.424 625.403 600.424 634.597 606.924 637.003L707.405 674.184C709.449 674.94 711.06 676.551 711.816 678.595L740.459 756H629.927C629.648 756.476 629.337 756.945 628.993 757.404L578.197 825.082C572.597 832.543 561.403 832.543 555.803 825.082L505.007 757.404C504.663 756.945 504.352 756.476 504.073 756H364C302.144 756 252 705.856 252 644V434ZM633.501 471.462C632.299 468.212 627.701 468.212 626.499 471.462L619.252 491.046C618.874 492.068 618.068 492.874 617.046 493.252L597.462 500.499C594.212 501.701 594.212 506.299 597.462 507.501L617.046 514.748C618.068 515.126 618.874 515.932 619.252 516.954L626.499 536.538C627.701 539.788 632.299 539.788 633.501 536.538L640.748 516.954C641.126 515.932 641.932 515.126 642.954 514.748L662.538 507.501C665.788 506.299 665.788 501.701 662.538 500.499L642.954 493.252C641.932 492.874 641.126 492.068 640.748 491.046L633.501 471.462Z'
        }),
        el('path', {
            fill: 'currentColor',
            d: 'M771.545 755.99C832.175 755.17 881.17 706.175 881.99 645.545L804.595 674.184C802.551 674.94 800.94 676.551 800.184 678.595L771.545 755.99Z'
        })
    );

    wp.blocks.registerBlockType('mxchat/chatbot', {
        title: __('MxChat Chatbot', 'mxchat'),
        description: __('Place the chatbot on this page — inline in the content, or as a floating bubble.', 'mxchat'),
        icon: chatIcon,
        category: 'widgets',
        keywords: [__('chat', 'mxchat'), __('chatbot', 'mxchat'), __('ai', 'mxchat')],
        supports: { html: false },
        attributes: {
            displayMode: { type: 'string', default: 'inline' },
            botId: { type: 'string', default: '' }
        },
        example: {},

        edit: function (props) {
            var displayMode = props.attributes.displayMode || 'inline';
            var botId = props.attributes.botId || '';

            var modeText = displayMode === 'floating'
                ? __('Floating bubble', 'mxchat')
                : __('Inline (in the page)', 'mxchat');
            var metaText = bots.length > 0
                ? modeText + ' · ' + botLabel(botId)
                : modeText;

            var controls = [
                el(RadioControl, {
                    key: 'mode',
                    label: __('Display mode', 'mxchat'),
                    selected: displayMode,
                    options: [
                        { value: 'inline', label: __('Inline (in the page)', 'mxchat') },
                        { value: 'floating', label: __('Floating bubble', 'mxchat') }
                    ],
                    help: displayMode === 'floating'
                        ? __('Renders the familiar corner launcher. If the chatbot is also set to display automatically on this page, the usual single-widget rules apply.', 'mxchat')
                        : __('The chat box renders right here in the content.', 'mxchat'),
                    onChange: function (value) {
                        props.setAttributes({ displayMode: value === 'floating' ? 'floating' : 'inline' });
                    }
                })
            ];

            // Bot picker only when Multi-Bot is active — no free-text bot id
            // anywhere (a typo would mean a silently wrong bot).
            if (bots.length > 0) {
                controls.push(el(SelectControl, {
                    key: 'bot',
                    label: __('Bot', 'mxchat'),
                    value: botId,
                    options: bots,
                    help: __('Default Bot follows this page’s chatbot settings.', 'mxchat'),
                    onChange: function (value) {
                        props.setAttributes({ botId: value || '' });
                    }
                }));
            }

            var placeholderProps = { className: 'mxchat-block-placeholder' };
            if (useBlockProps) {
                placeholderProps = useBlockProps(placeholderProps);
            }

            return el(
                wp.element.Fragment,
                null,
                InspectorControls
                    ? el(InspectorControls, null, el(PanelBody, { title: __('Placement', 'mxchat'), initialOpen: true }, controls))
                    : null,
                el(
                    'div',
                    placeholderProps,
                    el('span', { className: 'mxchat-block-placeholder-icon' }, chatIcon),
                    el(
                        'span',
                        { className: 'mxchat-block-placeholder-text' },
                        el('strong', null, __('MxChat Chatbot', 'mxchat')),
                        el('span', { className: 'mxchat-block-placeholder-meta' }, metaText)
                    )
                )
            );
        },

        // Dynamic block: markup comes from the render_callback on the front end.
        save: function () {
            return null;
        }
    });
}(window.wp));
