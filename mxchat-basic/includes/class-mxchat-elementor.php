<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Elementor integration for the chatbot placement widget (plan 95dd1e, part 2).
 *
 * Registration happens ONLY inside Elementor's own `elementor/widgets/register`
 * hook (Elementor >= 3.5): when Elementor is absent — or too old to fire that
 * hook — nothing further loads, so there is no fatal and no ghost widget. The
 * Widget_Base subclass lives in its own file required from the callback below,
 * which is why this always-loaded file must never reference an Elementor class.
 */
class MxChat_Elementor {

    public static function init() {
        add_action('elementor/widgets/register', array(__CLASS__, 'register_widget'));
    }

    /**
     * @param \Elementor\Widgets_Manager $widgets_manager Passed by Elementor.
     */
    public static function register_widget($widgets_manager) {
        require_once __DIR__ . '/class-mxchat-elementor-widget.php';
        $widgets_manager->register(new MxChat_Elementor_Widget());
    }
}
