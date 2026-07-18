<?php
/**
 * Editor Assistant loader (folded into core by plan-8cb0cb).
 *
 * Magic-wand-style AI actions inside the Gutenberg block editor — select a
 * block, pick an action (rewrite / summarize / translate / continue / fix
 * grammar), accept or discard. Reuses whichever chat provider the site has
 * configured in mxchat-basic.
 *
 * FREE core feature, OFF by default. The whole surface (REST route, streaming
 * AJAX action, and the block-editor sidebar assets) is gated on the standalone
 * option `mxchat_editor_assistant_enabled` === 'on'. When off, NOTHING here is
 * registered — zero editor footprint, no REST route, no AJAX action.
 *
 * Was the standalone plugin mxchat-editor-assistant (never released); folded in
 * per Maxwell direction. The PRO license gate + update-checker + dependency
 * notices from the standalone are intentionally dropped in the fold.
 *
 * @package MxChat
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Editor_Assistant {

    const OPTION = 'mxchat_editor_assistant_enabled';

    /**
     * True when the owner has turned the feature on. Default OFF — a fresh or
     * upgraded install has the block-editor assistant disabled until enabled.
     */
    public static function is_enabled() {
        return get_option(self::OPTION, 'off') === 'on';
    }

    /**
     * Wire the feature — only when enabled. Called from mxchat_include_classes()
     * during plugins_loaded, before rest_api_init / wp_ajax / enqueue fire.
     */
    public static function init() {
        if (!self::is_enabled()) {
            return;
        }

        require_once __DIR__ . '/class-mxchat-editor-assistant-rest.php';
        require_once __DIR__ . '/class-mxchat-editor-assistant-stream.php';

        MxChat_Editor_Assistant_REST::register();
        MxChat_Editor_Assistant_Stream::register();

        add_action('enqueue_block_editor_assets', array(__CLASS__, 'enqueue_sidebar'));
    }

    /**
     * Enqueue the Gutenberg sidebar bundle inside the block editor. Only for
     * users who can edit posts, and only in the block editor.
     *
     * The sidebar uses wp.element.createElement directly (no JSX, no build step).
     * Assets ship from mxchat-basic/assets/editor-assistant/.
     */
    public static function enqueue_sidebar() {
        if (!current_user_can('edit_posts')) {
            return;
        }

        $main_file = dirname(__DIR__) . '/mxchat-basic.php';
        $ver       = defined('MXCHAT_VERSION') ? MXCHAT_VERSION : '1.0';

        wp_enqueue_script(
            'mxchat-editor-assistant-sidebar',
            plugins_url('assets/editor-assistant/sidebar.js', $main_file),
            array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-blocks'),
            $ver,
            true
        );

        wp_enqueue_style(
            'mxchat-editor-assistant-sidebar',
            plugins_url('assets/editor-assistant/sidebar.css', $main_file),
            array('wp-edit-blocks'),
            $ver
        );

        wp_localize_script(
            'mxchat-editor-assistant-sidebar',
            'MxChatEditorAssistant',
            array(
                'restUrl'         => esc_url_raw(rest_url('mxchat-editor/v1/transform')),
                'nonce'           => wp_create_nonce('wp_rest'),
                'ajaxUrl'         => esc_url_raw(admin_url('admin-ajax.php')),
                'streamAction'    => MxChat_Editor_Assistant_Stream::AJAX_ACTION,
                'streamNonce'     => wp_create_nonce(MxChat_Editor_Assistant_Stream::NONCE),
                // Free core feature — no license gate. Kept as a constant true so any
                // older cached asset that still reads it stays functional.
                'licenseActive'   => true,
                'actions'         => MxChat_Editor_Assistant_REST::get_actions_for_client(),
                'supportedBlocks' => array('core/paragraph', 'core/heading', 'core/list-item', 'core/quote'),
                'locales'         => array(
                    array('value' => 'en', 'label' => __('English', 'mxchat')),
                    array('value' => 'es', 'label' => __('Spanish', 'mxchat')),
                    array('value' => 'fr', 'label' => __('French', 'mxchat')),
                    array('value' => 'de', 'label' => __('German', 'mxchat')),
                    array('value' => 'ja', 'label' => __('Japanese', 'mxchat')),
                    array('value' => 'pt', 'label' => __('Portuguese', 'mxchat')),
                ),
                'i18n' => array(
                    'panelTitle'         => __('MxChat Editor Assistant', 'mxchat'),
                    'sidebarHeading'     => __('Block actions', 'mxchat'),
                    'selectBlockNotice'  => __('Select a paragraph, heading, list item, or quote block to use the assistant.', 'mxchat'),
                    'unsupportedNotice'  => __('This action works on paragraph, heading, list item, and quote blocks. Select one of those to continue.', 'mxchat'),
                    'workingLabel'       => __('Working…', 'mxchat'),
                    'resultHeading'      => __('Result', 'mxchat'),
                    'accept'             => __('Accept', 'mxchat'),
                    'discard'            => __('Discard', 'mxchat'),
                    'tryAgain'           => __('Try again', 'mxchat'),
                    'translateTo'        => __('Translate to', 'mxchat'),
                    'licenseRequired'    => __('MxChat PRO license required. Actions are disabled.', 'mxchat'),
                    'errorPrefix'        => __('Editor Assistant error:', 'mxchat'),
                    'emptyContent'       => __('The selected block has no text content.', 'mxchat'),
                    'streamingLabel'     => __('Streaming…', 'mxchat'),
                    'acceptAll'          => __('Accept all', 'mxchat'),
                    'pending'            => __('Waiting…', 'mxchat'),
                    'done'               => __('Done', 'mxchat'),
                    'failed'             => __('Failed', 'mxchat'),
                    'skipped'            => __('Skipped — unsupported block', 'mxchat'),
                    /* translators: %1$d: applied count, %2$d: total selected */
                    'appliedSummary'     => __('Applied to %1$d of %2$d selected blocks.', 'mxchat'),
                    /* translators: %d: number of blocks */
                    'blocksLabelSuffix'  => __('(%d blocks)', 'mxchat'),
                    'blockLabel'         => __('Block', 'mxchat'),
                ),
            )
        );

        wp_set_script_translations('mxchat-editor-assistant-sidebar', 'mxchat');
    }
}
