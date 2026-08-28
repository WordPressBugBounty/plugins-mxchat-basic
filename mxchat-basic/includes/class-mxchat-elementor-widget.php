<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Elementor chatbot widget (plan 95dd1e, part 2). A thin wrapper over the
 * [mxchat_chatbot] shortcode via MxChat_Block::render() — one rendering path,
 * so per-page bot settings, consent gating and the floating/Auto-Display
 * collision rule are all inherited here, never re-decided.
 *
 * The editor canvas and the preview iframe get a STATIC placeholder only: a
 * live chatbot inside the builder would fire the front-end script, could start
 * a chat session for the person editing, and would re-render on every property
 * change. There is deliberately no content_template() — Elementor then asks
 * the server for the widget markup, which lands in render() below where the
 * edit-mode branch holds.
 *
 * This file is required only from MxChat_Elementor::register_widget(), inside
 * the elementor/widgets/register hook — never load it unconditionally, the
 * extends clause fatals without Elementor.
 */
class MxChat_Elementor_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'mxchat_chatbot';
    }

    public function get_title() {
        return __('MxChat Chatbot', 'mxchat');
    }

    public function get_icon() {
        return 'eicon-comments';
    }

    public function get_categories() {
        return array('general');
    }

    public function get_keywords() {
        return array('chat', 'chatbot', 'ai', 'mxchat');
    }

    protected function register_controls() {
        $this->start_controls_section('mxchat_placement', array(
            'label' => __('Placement', 'mxchat'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('display_mode', array(
            'label'       => __('Display mode', 'mxchat'),
            'type'        => \Elementor\Controls_Manager::SELECT,
            'default'     => 'inline',
            'options'     => array(
                'inline'   => __('Inline (in the page)', 'mxchat'),
                'floating' => __('Floating bubble', 'mxchat'),
            ),
            'description' => __('Inline renders the chat box where the widget sits. Floating renders the familiar corner launcher; if the chatbot also displays automatically on this page, the usual single-widget rules apply.', 'mxchat'),
        ));

        // Bot picker only while Multi-Bot is active — same rule as the block:
        // no free-text bot id anywhere (a typo would mean a silently wrong
        // bot). Elementor SELECT keys can't be the empty string, so 'default'
        // is the sentinel and maps to an omitted bot_id at render, making an
        // untouched widget follow the page-level bot setting like a bare
        // shortcode.
        $bots = MxChat_Block::get_bot_choices();
        if (!empty($bots)) {
            $options = array();
            foreach ($bots as $bot) {
                $key = ($bot['value'] === '') ? 'default' : $bot['value'];
                $options[$key] = $bot['label'];
            }
            $this->add_control('bot_id', array(
                'label'       => __('Bot', 'mxchat'),
                'type'        => \Elementor\Controls_Manager::SELECT,
                'default'     => 'default',
                'options'     => $options,
                'description' => __('Default Bot follows this page&#8217;s chatbot settings.', 'mxchat'),
            ));
        }

        $this->end_controls_section();
    }

    protected function render() {
        $settings     = $this->get_settings_for_display();
        $display_mode = (isset($settings['display_mode']) && $settings['display_mode'] === 'floating') ? 'floating' : 'inline';
        $bot_id       = isset($settings['bot_id']) ? sanitize_key($settings['bot_id']) : '';
        if ($bot_id === 'default') {
            $bot_id = '';
        }

        // Editor canvas / preview iframe: static placeholder, never the live
        // widget — the edit-mode trap this plan is built around.
        $elementor = \Elementor\Plugin::$instance;
        $in_editor = ($elementor->editor && $elementor->editor->is_edit_mode())
            || ($elementor->preview && $elementor->preview->is_preview_mode());
        if ($in_editor) {
            $this->render_placeholder($display_mode, $bot_id);
            return;
        }

        echo MxChat_Block::render(array(
            'displayMode' => $display_mode,
            'botId'       => $bot_id,
        ));
    }

    /**
     * Static canvas placeholder — same look as the Gutenberg block's
     * (assets/block/chatbot-block-editor.css), self-contained here because
     * that stylesheet never loads in the Elementor canvas. The style block is
     * emitted once per request; repeats are harmless dedupe misses across the
     * editor's separate AJAX renders.
     */
    private function render_placeholder($display_mode, $bot_id) {
        static $css_emitted = false;

        $mode_text = ($display_mode === 'floating')
            ? __('Floating bubble', 'mxchat')
            : __('Inline (in the page)', 'mxchat');

        $bots      = MxChat_Block::get_bot_choices();
        $meta_text = $mode_text;
        if (!empty($bots)) {
            $bot_label = __('Default Bot', 'mxchat');
            foreach ($bots as $bot) {
                if ($bot['value'] === $bot_id && $bot_id !== '') {
                    $bot_label = $bot['label'];
                    break;
                }
            }
            $meta_text = $mode_text . ' · ' . $bot_label;
        }

        if (!$css_emitted) {
            $css_emitted = true;
            echo '<style>'
                . '.mxchat-elementor-placeholder{display:flex;align-items:center;gap:12px;padding:20px 24px;border:1px dashed #949494;border-radius:4px;background:#f0f0f0;color:#1e1e1e;}'
                . '.mxchat-elementor-placeholder-icon{display:flex;flex-shrink:0;width:48px;height:48px;color:#212121;}'
                . '.mxchat-elementor-placeholder-icon svg{width:100%;height:100%;}'
                . '.mxchat-elementor-placeholder-text{display:flex;flex-direction:column;gap:2px;min-width:0;}'
                . '.mxchat-elementor-placeholder-text strong{font-size:14px;line-height:1.4;}'
                . '.mxchat-elementor-placeholder-meta{font-size:12px;line-height:1.4;color:#555d66;}'
                . '</style>';
        }

        // Same brand icon as the Gutenberg block (chatbot-block.js).
        echo '<div class="mxchat-elementor-placeholder">'
            . '<span class="mxchat-elementor-placeholder-icon">'
            . '<svg viewBox="0 0 1120 1120" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
            . '<path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="M252 434C252 372.144 302.144 322 364 322H770C831.856 322 882 372.144 882 434V614.459L804.595 585.816C802.551 585.06 800.94 583.449 800.184 581.405L763.003 480.924C760.597 474.424 751.403 474.424 748.997 480.924L711.816 581.405C711.06 583.449 709.449 585.06 707.405 585.816L606.924 622.997C600.424 625.403 600.424 634.597 606.924 637.003L707.405 674.184C709.449 674.94 711.06 676.551 711.816 678.595L740.459 756H629.927C629.648 756.476 629.337 756.945 628.993 757.404L578.197 825.082C572.597 832.543 561.403 832.543 555.803 825.082L505.007 757.404C504.663 756.945 504.352 756.476 504.073 756H364C302.144 756 252 705.856 252 644V434ZM633.501 471.462C632.299 468.212 627.701 468.212 626.499 471.462L619.252 491.046C618.874 492.068 618.068 492.874 617.046 493.252L597.462 500.499C594.212 501.701 594.212 506.299 597.462 507.501L617.046 514.748C618.068 515.126 618.874 515.932 619.252 516.954L626.499 536.538C627.701 539.788 632.299 539.788 633.501 536.538L640.748 516.954C641.126 515.932 641.932 515.126 642.954 514.748L662.538 507.501C665.788 506.299 665.788 501.701 662.538 500.499L642.954 493.252C641.932 492.874 641.126 492.068 640.748 491.046L633.501 471.462Z"/>'
            . '<path fill="currentColor" d="M771.545 755.99C832.175 755.17 881.17 706.175 881.99 645.545L804.595 674.184C802.551 674.94 800.94 676.551 800.184 678.595L771.545 755.99Z"/>'
            . '</svg>'
            . '</span>'
            . '<span class="mxchat-elementor-placeholder-text">'
            . '<strong>' . esc_html__('MxChat Chatbot', 'mxchat') . '</strong>'
            . '<span class="mxchat-elementor-placeholder-meta">' . esc_html($meta_text) . '</span>'
            . '</span>'
            . '</div>';
    }
}
