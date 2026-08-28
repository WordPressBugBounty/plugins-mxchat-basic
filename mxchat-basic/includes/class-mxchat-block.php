<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Gutenberg block for placing the chatbot (plan 95dd1e).
 *
 * A thin wrapper over the [mxchat_chatbot] shortcode — the block never
 * reimplements rendering, so it inherits every shortcode behavior including
 * the floating/Auto-Display collision handling and per-page bot settings.
 *
 * The editor canvas gets a STATIC placeholder only (see chatbot-block.js):
 * a live chatbot in the editor would fire the front-end script and could
 * start a chat session for the person editing. That is why this block does
 * NOT use ServerSideRender — the render callback below runs on the front
 * end only.
 */
class MxChat_Block {

    public static function init() {
        add_action('init', array(__CLASS__, 'register_block'));
    }

    public static function register_block() {
        // WP < 5.0 (readme floor) — no block editor, quiet no-op.
        if (!function_exists('register_block_type')) {
            return;
        }

        $main_file = dirname(__DIR__) . '/mxchat-basic.php';
        $ver       = defined('MXCHAT_VERSION') ? MXCHAT_VERSION : '1.0';

        wp_register_script(
            'mxchat-chatbot-block',
            plugins_url('assets/block/chatbot-block.js', $main_file),
            array('wp-blocks', 'wp-element', 'wp-components', 'wp-i18n'),
            $ver,
            true
        );

        wp_register_style(
            'mxchat-chatbot-block-editor',
            plugins_url('assets/block/chatbot-block-editor.css', $main_file),
            array(),
            $ver
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('mxchat-chatbot-block', 'mxchat', dirname(__DIR__) . '/languages');
        }

        wp_localize_script('mxchat-chatbot-block', 'MxChatBlockData', array(
            // Non-empty only when Multi-Bot is active: the bot select is hidden
            // otherwise. 'default' maps to '' so an untouched block behaves like
            // a bare [mxchat_chatbot] — it follows the per-page bot setting
            // instead of force-pinning the default bot.
            'bots' => self::get_bot_choices(),
        ));

        register_block_type('mxchat/chatbot', array(
            'api_version'     => 2,
            'editor_script'   => 'mxchat-chatbot-block',
            'editor_style'    => 'mxchat-chatbot-block-editor',
            'render_callback' => array(__CLASS__, 'render'),
            'attributes'      => array(
                'displayMode' => array('type' => 'string', 'default' => 'inline'),
                'botId'       => array('type' => 'string', 'default' => ''),
            ),
        ));
    }

    /**
     * Bot choices for the editor's select. Empty array when Multi-Bot is
     * inactive — the JS hides the control entirely rather than showing a
     * one-entry dropdown. Guard mirrors class-mxchat-public.php:869.
     *
     * Public: the Elementor widget (class-mxchat-elementor-widget.php) builds
     * its Bot control from this same list — one source, never a second copy.
     */
    public static function get_bot_choices() {
        if (!class_exists('MxChat_Multi_Bot_Manager') || !class_exists('MxChat_Multi_Bot_Core_Manager')) {
            return array();
        }

        $manager = MxChat_Multi_Bot_Core_Manager::get_instance();
        if (!method_exists($manager, 'get_available_bots')) {
            return array();
        }

        $choices = array();
        foreach ($manager->get_available_bots() as $bot_id => $bot_name) {
            $choices[] = array(
                'value' => $bot_id === 'default' ? '' : (string) $bot_id,
                'label' => (string) $bot_name,
            );
        }
        return $choices;
    }

    /**
     * Front-end render: delegate to the shortcode.
     *
     * Consent mirrors append_chatbot_to_body() exactly (class-mxchat-public.php)
     * so a block placement cannot bypass a Complianz gate the footer path
     * respects. Everything else — bot resolution, per-page settings, the
     * floating/Auto-Display collision — is the shortcode's existing logic.
     */
    public static function render($attributes) {
        $floating = isset($attributes['displayMode']) && $attributes['displayMode'] === 'floating';
        $bot_id   = isset($attributes['botId']) ? sanitize_key($attributes['botId']) : '';

        $options     = get_option('mxchat_options', array());
        $has_consent = true;
        if (is_array($options)
            && isset($options['complianz_toggle'])
            && $options['complianz_toggle'] === 'on'
            && function_exists('cmplz_has_consent')
        ) {
            $has_consent = cmplz_has_consent('marketing');
        }

        $shortcode = sprintf(
            '[mxchat_chatbot floating="%s" has_consent="%s"',
            $floating ? 'yes' : 'no',
            $has_consent ? 'yes' : 'no'
        );
        // Omit bot_id when unset: determine_bot_for_shortcode() then honors the
        // page-level bot selection, same as a bare shortcode.
        if ($bot_id !== '' && $bot_id !== 'default') {
            $shortcode .= ' bot_id="' . esc_attr($bot_id) . '"';
        }
        $shortcode .= ']';

        return do_shortcode($shortcode);
    }
}
