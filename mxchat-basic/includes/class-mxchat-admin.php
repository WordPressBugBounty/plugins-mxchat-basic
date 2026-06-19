<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class MxChat_Admin {
    private $options;
    private $chat_count;
    private $is_activated;
    private $knowledge_manager;

    /**
     * Get whether the license is activated
     * @return bool
     */
    public function is_activated() {
        return $this->is_activated;
    }

    /**
     * Get plugin options
     * @return array
     */
    public function get_options() {
        return $this->options;
    }

    public function __construct($knowledge_manager = null) {
        $this->options = get_option('mxchat_options');
        $this->chat_count = get_option('mxchat_chat_count', 0);
        $this->is_activated = $this->is_license_active();
        $this->knowledge_manager = $knowledge_manager;

        // Initialize default options if they are not set
        if (!$this->options) {
            $this->initialize_default_options();
        }

        // Add admin menu and initialize settings
        add_action('admin_menu', array($this, 'mxchat_add_plugin_page'));
        // Pro & Extensions registers at priority 30 so it always lands last in
        // the sidebar — below API Access (priority 20) and below any other
        // submenu that hooks at default priority 10.
        add_action('admin_menu', array($this, 'mxchat_add_pro_extensions_page'), 30);
        // Onboarding visibility — runs LAST so it can remove the submenu after every
        // other add_submenu_page() call. The page stays reachable by direct URL.
        add_action('admin_menu', array($this, 'mxchat_apply_onboarding_visibility'), 999);
        add_action('admin_init', array($this, 'mxchat_page_init'));
        add_action('admin_init', array($this, 'mxchat_prompts_page_init'));
        add_action('admin_enqueue_scripts', array($this, 'mxchat_enqueue_admin_assets'));
        // Add body class for the Onboarding wizard (plan-905439) so the
        // chrome-surgery CSS in admin-onboarding-wizard.css can scope its
        // WP-sidebar collapse to this page only.
        add_filter('admin_body_class', array($this, 'mxchat_add_onboarding_body_class'));
        add_action('wp_ajax_mxchat_delete_chat_history', array($this, 'mxchat_delete_chat_history'));
        add_action('admin_post_mxchat_delete_prompt', array($this, 'mxchat_handle_delete_prompt'));
        add_action('wp_ajax_mxchat_fetch_chat_history', array($this, 'mxchat_fetch_chat_history'));
        add_action('wp_ajax_nopriv_mxchat_fetch_chat_history', array($this, 'mxchat_fetch_chat_history'));
        add_action('wp_ajax_mxchat_fetch_conversation', array($this, 'mxchat_fetch_conversation'));
        add_action('wp_footer', array($this, 'mxchat_append_chatbot_to_body'));
        add_action('admin_head-mxchat-prompts', array($this, 'mxchat_enqueue_admin_assets'));
        add_action('admin_head-toplevel_page_mxchat-max', array($this, 'mxchat_enqueue_admin_assets'));
        add_action('admin_notices', array($this, 'mxchat_display_admin_notice'));
        add_action('admin_post_mxchat_delete_all_prompts', array($this, 'mxchat_handle_delete_all_prompts'));
        add_action('admin_post_mxchat_add_intent', array($this, 'mxchat_handle_add_intent'));
        add_action('admin_post_mxchat_delete_intent', array($this, 'mxchat_handle_delete_intent'));
        add_action('admin_post_mxchat_edit_intent', array($this, 'mxchat_handle_edit_intent'));
        add_action('wp_ajax_mxchat_export_transcripts', array($this, 'export_chat_transcripts'));

        // Leads tab (inside Transcripts)
        add_action('wp_ajax_mxchat_fetch_leads', array($this, 'mxchat_fetch_leads'));
        add_action('wp_ajax_mxchat_delete_leads', array($this, 'mxchat_delete_leads'));
        add_action('wp_ajax_mxchat_export_leads', array($this, 'mxchat_export_leads'));

        add_action('admin_init', array($this, 'mxchat_transcripts_page_init'));
        add_action('wp_ajax_dismiss_live_agent_notice', array($this, 'dismiss_live_agent_notice'));
        add_action('wp_ajax_dismiss_theme_migration_notice', array($this, 'dismiss_theme_migration_notice'));
        add_action('mxchat_cleanup_old_transcripts', array($this, 'cleanup_old_transcripts'));

         add_action('admin_init', array($this, 'register_pinecone_settings'));
         add_action('admin_init', array($this, 'register_openai_vectorstore_settings'));

        add_action('admin_notices', array($this, 'display_admin_notices'));

        add_action('wp_ajax_mxchat_test_streaming_actual', [$this, 'mxchat_handle_test_streaming_actual']);
        add_action('wp_ajax_mxchat_test_streaming', [$this, 'mxchat_handle_test_streaming']); // Keep existing as fallback
        add_action('wp_ajax_mxchat_test_vectorstore_connection', array($this, 'mxchat_test_vectorstore_connection'));

    	add_action('wp_ajax_mxchat_save_selected_bot', array($this, 'mxchat_save_selected_bot'));
        add_action('wp_ajax_mxchat_fetch_openrouter_models', array($this, 'fetch_openrouter_models'));
        add_action('wp_ajax_mxchat_get_rag_context', array($this, 'mxchat_get_rag_context'));

        // Actions page AJAX handlers
        add_action('wp_ajax_mxchat_fetch_actions_list', array($this, 'mxchat_fetch_actions_list'));
        add_action('wp_ajax_mxchat_toggle_action_status', array($this, 'mxchat_toggle_action_status'));
        add_action('wp_ajax_mxchat_bulk_delete_actions', array($this, 'mxchat_bulk_delete_actions'));
        add_action('wp_ajax_mxchat_add_intent_ajax', array($this, 'mxchat_add_intent_ajax'));
        add_action('wp_ajax_mxchat_edit_intent_ajax', array($this, 'mxchat_edit_intent_ajax'));
        add_action('wp_ajax_mxchat_delete_intent_ajax', array($this, 'mxchat_delete_intent_ajax'));
        add_action('wp_ajax_mxchat_add_phrase', array($this, 'mxchat_add_phrase_ajax'));
        add_action('wp_ajax_mxchat_delete_phrase', array($this, 'mxchat_delete_phrase_ajax'));
        add_action('wp_ajax_mxchat_get_phrases', array($this, 'mxchat_get_phrases_ajax'));
        add_action('wp_ajax_mxchat_delete_legacy_phrases', array($this, 'mxchat_delete_legacy_phrases_ajax'));

        // Slack test connection
        add_action('wp_ajax_mxchat_test_slack_connection', array($this, 'mxchat_test_slack_connection'));

        // Translation handlers
        add_action('wp_ajax_mxchat_translate_messages', array($this, 'mxchat_translate_messages'));
        add_action('wp_ajax_mxchat_get_transcript_translation', array($this, 'mxchat_get_transcript_translation'));

        // 3.2.3: Embedding model switch protection
        add_action('wp_ajax_mxchat_check_embedding_switch', array($this, 'mxchat_check_embedding_switch_ajax'));
        add_action('wp_ajax_mxchat_dismiss_embedding_mismatch', array($this, 'mxchat_dismiss_embedding_mismatch_ajax'));
        add_action('admin_notices', array($this, 'mxchat_embedding_mismatch_notice'));
    }

    /**
     * 3.2.3: Preflight check before allowing the embedding model dropdown to
     * switch. Pure option comparison — no counts, no DB queries beyond the
     * cached options. The dialog is shown whenever the user has previously
     * embedded with a different model than the one they're switching to.
     */
    public function mxchat_check_embedding_switch_ajax() {
        check_ajax_referer('mxchat_admin_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'mxchat'));
        }

        $new_model = isset($_POST['new_model']) ? sanitize_text_field(wp_unslash($_POST['new_model'])) : '';
        $active_model = MxChat_Utils::get_active_embedding_model();

        $is_mismatch = !empty($active_model) && !empty($new_model) && $active_model !== $new_model;

        $active_dims = MxChat_Utils::embedding_model_dimensions($active_model);
        $new_dims = MxChat_Utils::embedding_model_dimensions($new_model);

        wp_send_json_success(array(
            'is_mismatch'  => $is_mismatch,
            'active_model' => $active_model,
            'active_label' => MxChat_Utils::embedding_model_label($active_model),
            'new_model'    => $new_model,
            'new_label'    => MxChat_Utils::embedding_model_label($new_model),
            'dims_differ'  => ($active_dims > 0 && $new_dims > 0 && $active_dims !== $new_dims),
            'active_dims'  => $active_dims,
            'new_dims'     => $new_dims,
        ));
    }

    /**
     * 3.2.3: Dismiss the persistent mismatch banner. Tied to the active+selected
     * pair so the banner reappears on the next switch event.
     */
    public function mxchat_dismiss_embedding_mismatch_ajax() {
        check_ajax_referer('mxchat_admin_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'mxchat'));
        }

        $options = get_option('mxchat_options', array());
        $selected = $options['embedding_model'] ?? '';
        $active = MxChat_Utils::get_active_embedding_model();
        update_option('mxchat_dismissed_embedding_mismatch', $active . '|' . $selected, false);
        wp_send_json_success();
    }

    /**
     * 3.2.3: Persistent admin banner shown whenever the active embedding model
     * (last used to actually embed something) differs from the currently
     * selected model. Pure option comparison — no DB queries on every page
     * load. The banner auto-clears once both match again, i.e. after a delete
     * + re-embed cycle.
     */
    public function mxchat_embedding_mismatch_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = get_option('mxchat_options', array());
        $selected = $options['embedding_model'] ?? '';
        $active = MxChat_Utils::get_active_embedding_model();

        if (empty($active) || empty($selected) || $active === $selected) {
            return;
        }

        $dismissed = get_option('mxchat_dismissed_embedding_mismatch', '');
        if ($dismissed === $active . '|' . $selected) {
            return;
        }

        $active_label = MxChat_Utils::embedding_model_label($active);
        $selected_label = MxChat_Utils::embedding_model_label($selected);
        $active_dims = MxChat_Utils::embedding_model_dimensions($active);
        $selected_dims = MxChat_Utils::embedding_model_dimensions($selected);
        $dims_differ = ($active_dims > 0 && $selected_dims > 0 && $active_dims !== $selected_dims);

        $kb_url = admin_url('admin.php?page=mxchat-prompts');
        $actions_url = admin_url('admin.php?page=mxchat-actions');

        ?>
        <div class="notice notice-error is-dismissible mxchat-embedding-mismatch-notice"
             data-active="<?php echo esc_attr($active); ?>"
             data-selected="<?php echo esc_attr($selected); ?>">
            <p><strong><?php esc_html_e('MxChat: Embedding model mismatch detected', 'mxchat'); ?></strong></p>
            <p>
                <?php
                printf(
                    /* translators: 1: previously-used model name, 2: currently-selected model name */
                    esc_html__('Your knowledge base and actions were embedded with %1$s, but %2$s is now selected. Similarity matching will return inaccurate or empty results until you delete all existing embeddings and re-embed your content with the new model.', 'mxchat'),
                    '<code>' . esc_html($active_label) . '</code>',
                    '<code>' . esc_html($selected_label) . '</code>'
                );
                ?>
            </p>
            <?php if ($dims_differ) : ?>
                <p>
                    <strong><?php esc_html_e('Dimension mismatch:', 'mxchat'); ?></strong>
                    <?php
                    printf(
                        /* translators: 1: old dim count, 2: new dim count */
                        esc_html__('Existing vectors are %1$d-dimensional but the new model produces %2$d-dimensional vectors. If you use Pinecone, your index will reject queries entirely until re-embedded.', 'mxchat'),
                        (int) $active_dims,
                        (int) $selected_dims
                    );
                    ?>
                </p>
            <?php endif; ?>
            <p>
                <?php esc_html_e('To fix this:', 'mxchat'); ?>
                <a href="<?php echo esc_url($kb_url); ?>"><?php esc_html_e('Delete all knowledge base entries', 'mxchat'); ?></a> ·
                <a href="<?php echo esc_url($actions_url); ?>"><?php esc_html_e('Delete all actions', 'mxchat'); ?></a> ·
                <?php esc_html_e('then re-import / re-add them with the new model selected.', 'mxchat'); ?>
            </p>
        </div>
        <script>
        (function($){
            $(document).on('click', '.mxchat-embedding-mismatch-notice .notice-dismiss', function(){
                $.post(ajaxurl, {
                    action: 'mxchat_dismiss_embedding_mismatch',
                    security: '<?php echo esc_js(wp_create_nonce('mxchat_admin_nonce')); ?>'
                });
            });
        })(jQuery);
        </script>
        <?php
    }

private function is_license_active() {
    $license_status = get_option('mxchat_license_status', 'inactive');
    return ($license_status === 'active');
}

private function initialize_default_options() {
    $default_options = array(
        'api_key' => '',
        'xai_api_key' => '',
        'claude_api_key' => '',
        'deepseek_api_key' => '',
        'voyage_api_key' => '',
        'gemini_api_key' => '',
        'enable_streaming_toggle' => 'off',
        'enable_web_search' => 'off',
        'embedding_model' => 'text-embedding-ada-002',
        'system_prompt_instructions' => 'You are an AI Chatbot assistant for this website. Your main goal is to assist visitors with questions and provide helpful information. Here are your key guidelines:

        # Response Style - CRITICALLY IMPORTANT
        - MAXIMUM LENGTH: 1-3 short sentences per response
        - Ultra-concise: Get straight to the answer with no filler
        - No introductions like "Sure!" or "I\'d be happy to help"
        - No phrases like "based on my knowledge" or "according to information"
        - No explanatory text before giving the answer
        - No summaries or repetition
        - Hyperlink all URLs
        - Respond in user\'s language
        - Minor chit chat or conversation is okay, but try to keep it focused on [insert topic]

        # Knowledge Base Requirements - PREVENT HALLUCINATIONS
        - ONLY answer questions using information explicitly provided in OFFICIAL KNOWLEDGE DATABASE CONTENT sections marked with ===== delimiters
        - If required information is NOT in the knowledge database: "I don\'t have enough information in my knowledge base to answer that question accurately."
        - NEVER invent or hallucinate URLs, links, product specs, procedures, dates, statistics, names, contacts, or company information
        - When knowledge base information is unclear or contradictory, acknowledge the limitation rather than guessing
        - Better to admit insufficient information than provide inaccurate answers',
        'model' => esc_html__('gpt-5.1-chat-latest', 'mxchat'),
        'rate_limit_logged_out' => esc_html__('100', 'mxchat'),
        'role_rate_limits' => array(),
        'rate_limit_message' => esc_html__('Rate limit exceeded. Please try again later.', 'mxchat'),
        'enable_email_block' => '',
        'email_blocker_header_content' => __("<h2>Welcome to Our Chat!</h2>\n<p>Let's get started. Enter your email to begin chatting with us.</p>", 'mxchat'),
        'email_blocker_button_text' => esc_html__('Start Chat', 'mxchat'),
        'enable_name_field' => 'off', // NEW
        'name_field_placeholder' => esc_html__('Enter your name', 'mxchat'), // NEW
        'top_bar_title' => esc_html__('MxChat', 'mxchat'),
        'intro_message' => __('Hello! How can I assist you today?', 'mxchat'),
        'ai_agent_text' => esc_html__('AI Agent', 'mxchat'),
        'input_copy' => esc_html__('How can I assist?', 'mxchat'),
        'append_to_body' => esc_html__('off', 'mxchat'),
        'post_type_visibility_mode' => 'all', // 'all', 'include', 'exclude'
        'post_type_visibility_list' => array(), // Array of post type slugs
        'contextual_awareness_toggle' => 'off',
        'citation_links_toggle' => 'on',
        'satisfaction_rating_enabled' => 'off',
        'satisfaction_rating_idle_seconds' => 60,
        'satisfaction_rating_question' => '',
        'satisfaction_rating_thanks' => '',
        'satisfaction_rating_placeholder' => '',
        'satisfaction_rating_saved' => '',
        'close_button_color' => esc_html__('#fff', 'mxchat'),
        'chatbot_bg_color' => esc_html__('#fff', 'mxchat'),
        'user_message_bg_color' => esc_html__('#fff', 'mxchat'),
        'user_message_font_color' => esc_html__('#212121', 'mxchat'),
        'bot_message_bg_color' => esc_html__('#212121', 'mxchat'),
        'bot_message_font_color' => esc_html__('#fff', 'mxchat'),
        'top_bar_bg_color' => esc_html__('#212121', 'mxchat'),
        'send_button_font_color' => esc_html__('#212121', 'mxchat'),
        'chat_input_font_color' => esc_html__('#212121', 'mxchat'),
        'chatbot_background_color' => esc_html__('#212121', 'mxchat'),
        'icon_color' => esc_html__('#fff', 'mxchat'),
        'enable_woocommerce_integration' => esc_html__('0', 'mxchat'),
        'link_target_toggle' => esc_html__('off', 'mxchat'),
        'pre_chat_message' => esc_html__('Hey there! Ask me anything!', 'mxchat'),

        // New fields for Loops Integration
        'loops_api_key' => '',
        'loops_mailing_list' => '',
        'triggered_phrase_response' => __('Would you like to join our mailing list? Please provide your email below.', 'mxchat'),
        'email_capture_response' => __('Thank you for providing your email! You\'ve been added to our list.', 'mxchat'),
        'popular_question_1' => '',
        'popular_question_2' => '',
        'popular_question_3' => '',
        'pdf_intent_trigger_text' => __("Please provide the URL to the PDF you'd like to discuss.", 'mxchat'),
        'pdf_intent_success_text' => __("I've processed the PDF. What questions do you have about it?", 'mxchat'),
        'pdf_intent_error_text' => __("Sorry, I couldn't process the PDF. Please ensure it's a valid file.", 'mxchat'),
        'pdf_max_pages' => 69,
        'show_pdf_upload_button' => 'on',
        'show_word_upload_button' => 'on',

        // Live Agent Integration (Slack)
        'live_agent_webhook_url' => '',
        'live_agent_secret_key' => '',
        'live_agent_bot_token' => '',
        'live_agent_message_bg_color' => esc_html__('#ffffff', 'mxchat'),
        'live_agent_message_font_color' => esc_html__('#333333', 'mxchat'),

        // Telegram Integration
        'telegram_status' => 'off',
        'telegram_bot_token' => '',
        'telegram_group_id' => '',
        'telegram_webhook_secret' => '',
        'telegram_notification_message' => __("I've notified a support agent. Please allow a moment for them to respond. If you'd like to continue with AI, just type \"Switch to AI\" at any time.", 'mxchat'),
        'telegram_away_message' => __("I just checked, and it looks like our support team isn't personally available at the moment. If you'd like, you can leave your email address, and they'll get back to you as soon as possible. In the meantime, you can keep chatting with me — just let me know how I can help!", 'mxchat'),

        'chat_toolbar_toggle' => esc_html__('off', 'mxchat'),
        'mode_indicator_bg_color' => esc_html__('#767676', 'mxchat'),
        'mode_indicator_font_color' => esc_html__('#ffffff', 'mxchat'),
        'toolbar_icon_color' => esc_html__('#212121', 'mxchat'),

        // Optimization settings
        'script_loading_strategy' => 'default',

        // Debug settings
        'debug_mode' => 'off',
    );


        // Merge existing options with defaults
        $existing_options = get_option('mxchat_options', array());
        $merged_options = wp_parse_args($existing_options, $default_options);

        // Update the options if they have changed
        if ($existing_options !== $merged_options) {
            update_option('mxchat_options', $merged_options);
        }

            // Add default limits for each role
    $roles = wp_roles()->get_names();
    foreach ($roles as $role_id => $role_name) {
        $default_options['role_rate_limits'][$role_id] = esc_html__('100', 'mxchat');
    }

    return $default_options;

        // Update the $this->options property
        $this->options = $merged_options;
    }

public function mxchat_add_plugin_page() {
    // Onboarding lifecycle helpers (admin_init redirect + ajax handlers live in the file).
    require_once plugin_dir_path(__FILE__) . 'admin-onboarding-page.php';

    // Main menu page — `mxchat-max` remains the parent slug for every MxChat submenu
    // (Settings, Knowledge, Transcripts, …). Hitting `?page=mxchat-max` directly now
    // dispatches to the Onboarding page (or Settings if the user has dismissed onboarding).
    add_menu_page(
        esc_html__('MxChat', 'mxchat'),
        esc_html__('MxChat', 'mxchat'),
        'manage_options',
        'mxchat-max',
        array($this, 'mxchat_create_dashboard_page'),
        'dashicons-testimonial',
        6
    );

    // Onboarding submenu — first child under MxChat (plan-d14e89).
    // First registration uses menu_slug === parent slug 'mxchat-max' →
    // WP-canonical override of the auto-duplicate "MxChat" entry. Result: the
    // first child shows as "Onboarding" instead of a redundant pair.
    add_submenu_page(
        'mxchat-max',
        esc_html__('MxChat Onboarding', 'mxchat'),
        esc_html__('Onboarding', 'mxchat'),
        'manage_options',
        'mxchat-max',
        array($this, 'mxchat_create_dashboard_page')
    );
    // Hidden route for `?page=mxchat-onboarding` (Settings "Show again" link
    // + legacy redirects still target this slug). Parent === null keeps it
    // out of the menu while remaining accessible by URL.
    add_submenu_page(
        null,
        esc_html__('MxChat Onboarding', 'mxchat'),
        esc_html__('Onboarding', 'mxchat'),
        'manage_options',
        'mxchat-onboarding',
        array($this, 'mxchat_create_dashboard_page')
    );

    // Settings submenu — same callback as before, just at a new slug.
    add_submenu_page(
        'mxchat-max',
        esc_html__('MxChat Settings', 'mxchat'),
        esc_html__('Settings', 'mxchat'),
        'manage_options',
        'mxchat-settings',
        array($this, 'mxchat_create_admin_page')
    );

    // Submenu page for Knowledge
    add_submenu_page(
        'mxchat-max',
        esc_html__('Prompts', 'mxchat'),
        esc_html__('Knowledge', 'mxchat'),
        'manage_options',
        'mxchat-prompts',
        array($this, 'mxchat_create_prompts_page')
    );

    add_submenu_page(
        'mxchat-max',
        esc_html__('Chat Transcripts', 'mxchat'),
        esc_html__('Transcripts', 'mxchat'),
        'manage_options',
        'mxchat-transcripts',
        array($this, 'mxchat_create_transcripts_page')
    );

    add_submenu_page(
        'mxchat-max',
        esc_html__('MxChat Actions', 'mxchat'),
        esc_html__('Actions', 'mxchat'),
        'manage_options',
        'mxchat-actions',
        array($this, 'mxchat_actions_page_html')
    );

    // Content Generator page
    add_submenu_page(
        'mxchat-max',
        esc_html__('Content', 'mxchat'),
        esc_html__('Content', 'mxchat'),
        'manage_options',
        'mxchat-content',
        array($this, 'mxchat_create_content_page')
    );

}

/**
 * Register the Pro & Extensions submenu on a later admin_menu priority so it
 * always renders as the bottom-most item in the MxChat sidebar — below
 * configuration pages like API Access (priority 20).
 */
public function mxchat_add_pro_extensions_page() {
    add_submenu_page(
        'mxchat-max',
        esc_html__('Pro & Extensions', 'mxchat'),
        esc_html__('Pro & Extensions', 'mxchat'),
        'manage_options',
        'mxchat-activation',
        array($this, 'mxchat_create_activation_page')
    );
}

public function mxchat_create_addons_page() {
    require_once plugin_dir_path(__FILE__) . 'class-mxchat-addons.php';
    $addons_page = new MxChat_Addons();
    $addons_page->render_page();
}

/**
 * Render the Content Generator admin page
 */
public function mxchat_create_content_page() {
    require_once plugin_dir_path(__FILE__) . 'admin-content-page.php';
    mxchat_render_content_page($this);
}

/**
 * Test actual streaming functionality in the WordPress environment
 */
public function mxchat_handle_test_streaming_actual() {
    check_ajax_referer('mxchat_test_streaming_nonce', 'nonce');

    // Check if headers have already been sent
    if (headers_sent()) {
        wp_send_json_error(['message' => 'Headers already sent - streaming not possible']);
        return;
    }

    // Check for required functions
    if (!function_exists('curl_init')) {
        wp_send_json_error(['message' => 'cURL not available - streaming requires cURL']);
        return;
    }

    // Get user's selected model and API key
    $options = get_option('mxchat_options', []);
    $selected_model = $options['model'] ?? 'gpt-5.1-chat-latest';

    // Get the provider from the model
    $model_parts = explode('-', $selected_model);
    $provider = strtolower($model_parts[0]);

    // Get the appropriate API key
    $api_key = '';
    switch ($provider) {
        case 'gpt':
        case 'o1':
            $api_key = $options['api_key'] ?? '';
            break;
        case 'claude':
            $api_key = $options['claude_api_key'] ?? '';
            break;
        case 'grok':
            $api_key = $options['xai_api_key'] ?? '';
            break;
        case 'deepseek':
            $api_key = $options['deepseek_api_key'] ?? '';
            break;
        case 'gemini':
            $api_key = $options['gemini_api_key'] ?? '';
            break;
        default:
            // Default to OpenAI for unknown models
            $api_key = $options['api_key'] ?? '';
            $provider = 'gpt';
            break;
    }

    if (empty($api_key)) {
        wp_send_json_error(['message' => "API key not configured for {$provider} provider"]);
        return;
    }

    // Test streaming with the selected model and provider
    try {
        $this->perform_streaming_test($provider, $selected_model, $api_key);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Streaming test exception: ' . $e->getMessage()]);
    }
}

/**
 * Perform the actual streaming test
 */
private function perform_streaming_test($provider, $model, $api_key) {
    // Set streaming headers
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // Disable nginx buffering

    // Prepare test message
    $test_message = "Please respond with exactly: 'Streaming test successful!' - send this as a short response for testing.";

    // Configure API request based on provider
    $url = '';
    $headers = [];
    $body = [];

    switch ($provider) {
        case 'gpt':
        case 'o1':
            $url = 'https://api.openai.com/v1/chat/completions';
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key
            ];
            $body = [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $test_message]],
                'max_tokens' => 50,
                'temperature' => 0.3,
                'stream' => true
            ];
            break;

        case 'claude':
            $url = 'https://api.anthropic.com/v1/messages';
            $headers = [
                'Content-Type: application/json',
                'x-api-key: ' . $api_key,
                'anthropic-version: 2023-06-01'
            ];
            $body = [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $test_message]],
                'max_tokens' => 50,
                'temperature' => 0.3,
                'stream' => true
            ];
            break;

        case 'grok':
            $url = 'https://api.x.ai/v1/chat/completions';
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key
            ];
            $body = [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $test_message]],
                'max_tokens' => 50,
                'temperature' => 0.3,
                'stream' => true
            ];
            break;

        case 'deepseek':
            $url = 'https://api.deepseek.com/v1/chat/completions';
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key
            ];
            $body = [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $test_message]],
                'max_tokens' => 50,
                'temperature' => 0.3,
                'stream' => true
            ];
            break;

        default:
            echo "data: " . json_encode(['error' => 'Unsupported provider for streaming test: ' . $provider]) . "\n\n";
            flush();
            return;
    }

    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use ($provider) {
        return $this->process_streaming_test_data($data, $provider);
    });

    // Send initial test message
    echo "data: " . json_encode(['content' => '[Starting streaming test...]']) . "\n\n";
    flush();

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        echo "data: " . json_encode(['error' => 'cURL Error: ' . $curl_error]) . "\n\n";
        flush();
        return;
    }

    if ($http_code !== 200) {
        echo "data: " . json_encode(['error' => 'API returned HTTP ' . $http_code]) . "\n\n";
        flush();
        return;
    }

    // Send completion signal
    echo "data: [DONE]\n\n";
    flush();
}

/**
 * Process streaming data for the test
 */
private function process_streaming_test_data($data, $provider) {
    static $chunk_count = 0;

    $lines = explode("\n", $data);

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        // Handle different provider formats
        if ($provider === 'claude') {
            // Claude uses event: and data: format
            if (strpos($line, 'data: ') === 0) {
                $json_str = substr($line, 6);
                $json = json_decode($json_str, true);

                if (isset($json['type']) && $json['type'] === 'content_block_delta') {
                    if (isset($json['delta']['text'])) {
                        $chunk_count++;
                        echo "data: " . json_encode([
                            'content' => $json['delta']['text'],
                            'test_chunk' => $chunk_count
                        ]) . "\n\n";
                        flush();
                    }
                }
            }
        } else {
            // OpenAI, X.AI, DeepSeek format
            if (strpos($line, 'data: ') === 0) {
                $json_str = substr($line, 6);

                if ($json_str === '[DONE]') {
                    // Don't echo [DONE] here, let the main function handle it
                    continue;
                }

                $json = json_decode($json_str, true);
                if (isset($json['choices'][0]['delta']['content'])) {
                    $chunk_count++;
                    echo "data: " . json_encode([
                        'content' => $json['choices'][0]['delta']['content'],
                        'test_chunk' => $chunk_count
                    ]) . "\n\n";
                    flush();
                }
            }
        }
    }

    return strlen($data);
}

/**
 * Updated version of your existing test method (keep this as a fallback)
 */
public function mxchat_handle_test_streaming() {
    check_ajax_referer('mxchat_test_streaming_nonce', 'nonce');

    // Use the actual streaming test instead
    $this->mxchat_handle_test_streaming_actual();
}


public function register_pinecone_settings() {
    register_setting(
        'mxchat_pinecone_addon_options',
        'mxchat_pinecone_addon_options',
        array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_pinecone_settings'),
            'default' => array(
                'mxchat_use_pinecone' => '0',
                'mxchat_pinecone_api_key' => '',
                'mxchat_pinecone_host' => '',
                'mxchat_pinecone_index' => '',
                'mxchat_pinecone_environment' => ''
            )
        )
    );
}

public function sanitize_pinecone_settings($input) {
    $sanitized = array();

    $sanitized['mxchat_use_pinecone'] = isset($input['mxchat_use_pinecone']) ? '1' : '0';
    $sanitized['mxchat_pinecone_api_key'] = sanitize_text_field($input['mxchat_pinecone_api_key'] ?? '');
    $sanitized['mxchat_pinecone_host'] = sanitize_text_field($input['mxchat_pinecone_host'] ?? '');
    $sanitized['mxchat_pinecone_index'] = sanitize_text_field($input['mxchat_pinecone_index'] ?? '');
    $sanitized['mxchat_pinecone_environment'] = sanitize_text_field($input['mxchat_pinecone_environment'] ?? '');

    // Remove https:// from host if present
    $sanitized['mxchat_pinecone_host'] = str_replace(['https://', 'http://'], '', $sanitized['mxchat_pinecone_host']);

    return $sanitized;
}

public function register_openai_vectorstore_settings() {
    register_setting(
        'mxchat_openai_vectorstore_options',
        'mxchat_openai_vectorstore_options',
        array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_openai_vectorstore_settings'),
            'default' => array(
                'mxchat_use_openai_vectorstore' => '0',
                'mxchat_vectorstore_ids' => '',
                'mxchat_vectorstore_max_results' => 5
            )
        )
    );
}

public function sanitize_openai_vectorstore_settings($input) {
    $sanitized = array();

    $sanitized['mxchat_use_openai_vectorstore'] = isset($input['mxchat_use_openai_vectorstore']) ? '1' : '0';
    $sanitized['mxchat_vectorstore_ids'] = sanitize_text_field($input['mxchat_vectorstore_ids'] ?? '');
    $sanitized['mxchat_vectorstore_max_results'] = absint($input['mxchat_vectorstore_max_results'] ?? 5);

    // Ensure max results is within reasonable range
    if ($sanitized['mxchat_vectorstore_max_results'] < 1) {
        $sanitized['mxchat_vectorstore_max_results'] = 1;
    }
    if ($sanitized['mxchat_vectorstore_max_results'] > 20) {
        $sanitized['mxchat_vectorstore_max_results'] = 20;
    }

    return $sanitized;
}

/**
 * AJAX handler to test OpenAI Vector Store connection
 */
public function mxchat_test_vectorstore_connection() {
    check_ajax_referer('mxchat_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'mxchat')));
        return;
    }

    $vectorstore_options = get_option('mxchat_openai_vectorstore_options', array());
    $vectorstore_ids = $vectorstore_options['mxchat_vectorstore_ids'] ?? '';
    $mxchat_options = get_option('mxchat_options', array());
    $api_key = $mxchat_options['api_key'] ?? '';

    if (empty($api_key)) {
        wp_send_json_error(array('message' => __('OpenAI API key is not configured.', 'mxchat')));
        return;
    }

    if (empty($vectorstore_ids)) {
        wp_send_json_error(array('message' => __('No Vector Store ID configured.', 'mxchat')));
        return;
    }

    // Get the first Vector Store ID for testing
    $ids_array = array_map('trim', explode(',', $vectorstore_ids));
    $test_id = $ids_array[0];

    // Test by retrieving the Vector Store info
    $response = wp_remote_get(
        'https://api.openai.com/v1/vector_stores/' . $test_id,
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'OpenAI-Beta' => 'assistants=v2'
            ),
            'timeout' => 30
        )
    );

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => __('Connection failed: ', 'mxchat') . $response->get_error_message()));
        return;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($status_code === 200 && isset($body['id'])) {
        $file_count = $body['file_counts']['completed'] ?? 0;
        $name = $body['name'] ?? $test_id;
        wp_send_json_success(array(
            'message' => sprintf(
                __('Connected successfully! Vector Store: %s (%d files)', 'mxchat'),
                esc_html($name),
                $file_count
            )
        ));
    } elseif ($status_code === 404) {
        wp_send_json_error(array('message' => __('Vector Store not found. Please check the ID.', 'mxchat')));
    } elseif ($status_code === 401) {
        wp_send_json_error(array('message' => __('Invalid API key.', 'mxchat')));
    } else {
        $error_message = $body['error']['message'] ?? __('Unknown error occurred.', 'mxchat');
        wp_send_json_error(array('message' => $error_message));
    }
}

/**
 * AJAX handler to test Slack connection and validate scopes
 */
public function mxchat_test_slack_connection() {
    check_ajax_referer('mxchat_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'mxchat')));
        return;
    }

    $bot_token = $this->options['live_agent_bot_token'] ?? '';

    if (empty($bot_token)) {
        wp_send_json_error(array('message' => __('Slack Bot Token is not configured. Please enter your token and save settings first.', 'mxchat')));
        return;
    }

    // Test 1: Validate bot token with auth.test
    $auth_response = wp_remote_post('https://slack.com/api/auth.test', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $bot_token,
            'Content-Type' => 'application/json'
        ),
        'timeout' => 15
    ));

    if (is_wp_error($auth_response)) {
        wp_send_json_error(array('message' => __('Connection failed: ', 'mxchat') . $auth_response->get_error_message()));
        return;
    }

    $auth_body = json_decode(wp_remote_retrieve_body($auth_response), true);

    if (!isset($auth_body['ok']) || !$auth_body['ok']) {
        $error = $auth_body['error'] ?? 'unknown_error';
        $error_messages = array(
            'invalid_auth' => __('Invalid bot token. Please check your token starts with xoxb-', 'mxchat'),
            'not_authed' => __('No authentication token provided.', 'mxchat'),
            'account_inactive' => __('The Slack workspace has been deactivated.', 'mxchat'),
            'token_revoked' => __('The bot token has been revoked. Please generate a new one.', 'mxchat'),
        );
        $message = $error_messages[$error] ?? sprintf(__('Authentication failed: %s', 'mxchat'), $error);
        wp_send_json_error(array('message' => $message));
        return;
    }

    $team_name = $auth_body['team'] ?? 'Unknown Workspace';
    $bot_name = $auth_body['user'] ?? 'Unknown Bot';

    // Test 2: Check if we can list channels (tests channels:read scope)
    $channels_response = wp_remote_post('https://slack.com/api/conversations.list', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $bot_token,
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode(array('limit' => 1)),
        'timeout' => 15
    ));

    $channels_body = json_decode(wp_remote_retrieve_body($channels_response), true);
    $can_read_channels = isset($channels_body['ok']) && $channels_body['ok'];

    // Test 3: Check if we can create channels (tests channels:manage scope)
    // We'll just check the error message without actually creating
    $create_response = wp_remote_post('https://slack.com/api/conversations.create', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $bot_token,
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode(array('name' => 'mxchat-test-' . time(), 'is_private' => false)),
        'timeout' => 15
    ));

    $create_body = json_decode(wp_remote_retrieve_body($create_response), true);

    // If channel was created, delete it immediately
    if (isset($create_body['ok']) && $create_body['ok'] && isset($create_body['channel']['id'])) {
        wp_remote_post('https://slack.com/api/conversations.archive', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $bot_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array('channel' => $create_body['channel']['id'])),
            'timeout' => 15
        ));
        $can_create_channels = true;
    } else {
        $create_error = $create_body['error'] ?? '';
        // name_taken means we have permission but channel exists
        $can_create_channels = ($create_error === 'name_taken' || (isset($create_body['ok']) && $create_body['ok']));

        // Check for missing scope errors
        if ($create_error === 'missing_scope') {
            $can_create_channels = false;
        }
    }

    // Build result message
    $results = array();
    $results[] = sprintf(__('Workspace: %s', 'mxchat'), esc_html($team_name));
    $results[] = sprintf(__('Bot: %s', 'mxchat'), esc_html($bot_name));
    $results[] = '';
    $results[] = ($can_read_channels ? '✓' : '✗') . ' ' . __('channels:read - List channels', 'mxchat');
    $results[] = ($can_create_channels ? '✓' : '✗') . ' ' . __('channels:manage - Create channels', 'mxchat');

    $missing_scopes = array();
    if (!$can_read_channels) $missing_scopes[] = 'channels:read';
    if (!$can_create_channels) $missing_scopes[] = 'channels:manage';

    if (!empty($missing_scopes)) {
        wp_send_json_error(array(
            'message' => implode("\n", $results),
            'missing_scopes' => $missing_scopes,
            'partial' => true
        ));
    } else {
        wp_send_json_success(array(
            'message' => implode("\n", $results)
        ));
    }
}

public function mxchat_display_admin_notice() {
    // Success notice
    if ($message = get_transient('mxchat_admin_notice_success')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($message); ?></p>
            <button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php echo esc_html__('Dismiss this notice.', 'mxchat'); ?></span></button>
        </div>
        <?php
        delete_transient('mxchat_admin_notice_success'); // Clear the transient after displaying
    }

    // Error notice
    if ($message = get_transient('mxchat_admin_notice_error')) {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($message); ?></p>
            <button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php echo esc_html__('Dismiss this notice.', 'mxchat'); ?></span></button>
        </div>
        <?php
        delete_transient('mxchat_admin_notice_error'); // Clear the transient after displaying
    }
}

public function show_live_agent_disabled_banner() {
    $show_disabled_notice = get_option('mxchat_show_live_agent_disabled_notice', false);

    if ($show_disabled_notice) {
        ?>
        <div class="mxchat-live-agent-disabled-notice" id="mxchat-disabled-notice">
            <div class="mxchat-pro-notification">
                <button type="button" class="mxchat-dismiss-btn" onclick="dismissLiveAgentNotice()" aria-label="Dismiss notification">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                <div class="mxchat-live-agent-content">
                    <h3>🔧 Live Agent Integration Updated!</h3>
                    <p>We've temporarily disabled your Live Agent integration due to recent enhancements that have made it much better! You can easily turn it back on by going to <strong>Toolbar & Components → Live Agent Settings</strong> and reviewing the new configuration options.</p>
                </div>
            </div>
        </div>
        <?php
    }
}
/**
 * Render the Onboarding page. Delegates to the procedural renderer in
 * includes/admin-onboarding-page.php. Wired to both `?page=mxchat-max`
 * (legacy top-level URL) and `?page=mxchat-onboarding` (the canonical
 * Onboarding submenu).
 *
 * When the user has dismissed onboarding and lands on `mxchat-max` (the
 * legacy URL, since the Onboarding submenu has been removed), redirect to
 * Settings instead — the page is "graduated" and we shouldn't dump them
 * back onto it. They can still navigate here directly via the unhide link.
 */
public function mxchat_create_dashboard_page() {
    require_once plugin_dir_path(__FILE__) . 'admin-onboarding-page.php';

    $current = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if ($current === 'mxchat-max' && function_exists('mxchat_onboarding_is_dismissed') && mxchat_onboarding_is_dismissed()) {
        wp_safe_redirect(admin_url('admin.php?page=mxchat-settings'));
        exit;
    }

    if (function_exists('mxchat_render_onboarding_page')) {
        mxchat_render_onboarding_page();
        return;
    }
    // Defensive: if the include failed to load, fall back to the old Settings page
    // so the top-level menu never lands on an empty screen.
    $this->mxchat_create_admin_page();
}

/**
 * Hide the Onboarding submenu when the user has dismissed it (either
 * manually or via auto-graduation). The page itself remains routable so
 * the Settings "Show MxChat Onboarding again" link can navigate back to it.
 */
public function mxchat_apply_onboarding_visibility() {
    if (!function_exists('mxchat_onboarding_is_dismissed')) {
        return;
    }
    if (mxchat_onboarding_is_dismissed()) {
        // The first MxChat child is the same-slug-as-parent registration
        // (slug 'mxchat-max', labelled "Onboarding") added in plan-d14e89.
        // Remove it so the menu opens straight to Settings after dismiss.
        remove_submenu_page('mxchat-max', 'mxchat-max');
    }
}

public function mxchat_create_admin_page() {
    $this->add_live_agent_nonce();
    $this->add_theme_migration_nonce();

    // Include and render the new sidebar-based settings page
    require_once plugin_dir_path(__FILE__) . 'admin-settings-page.php';
    mxchat_render_settings_page($this);
}

public function dismiss_live_agent_notice() {
    // Add debugging
    //error_log('dismiss_live_agent_notice called');
    //error_log('POST data: ' . print_r($_POST, true));

    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'dismiss_live_agent_notice')) {
        //error_log('Nonce verification failed');
        wp_die('Security check failed');
    }

    // Remove the notice flag
    $deleted = delete_option('mxchat_show_live_agent_disabled_notice');
    //error_log('Option deleted: ' . ($deleted ? 'yes' : 'no'));

    wp_send_json_success();
}
public function add_live_agent_nonce() {
    if (get_option('mxchat_show_live_agent_disabled_notice', false)) {
        // Make sure your admin script is enqueued and localize the data
        wp_localize_script('mxchat-admin-js', 'mxchatLiveAgent', array(
            'nonce' => wp_create_nonce('dismiss_live_agent_notice'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
    }
}

/**
 * Show theme migration notice for Pro users with AI-generated themes
 * Only shown once - dismissible and stored in options
 */
public function show_theme_migration_banner() {
    // Only show if Pro is activated
    if (!$this->is_activated) {
        return;
    }

    // Check if notice should be shown
    $show_notice = get_option('mxchat_show_theme_migration_notice', false);

    if ($show_notice) {
        ?>
        <div class="mxchat-theme-migration-notice" id="mxchat-theme-migration-notice">
            <div class="mxchat-pro-notification">
                <button type="button" class="mxchat-dismiss-btn" onclick="dismissThemeMigrationNotice()" aria-label="Dismiss notification">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                <div class="mxchat-theme-migration-content">
                    <h3>🎨 AI Theme Migration Required</h3>
                    <p>If you're using an AI-generated chatbot theme, you'll need to migrate it to match the new CSS structure. Go to <strong>Theme Settings</strong>, select your theme from the sidebar, and click the <strong>Migrate</strong> button.</p>
                </div>
            </div>
        </div>
        <?php
    }
}

/**
 * Dismiss theme migration notice via AJAX
 */
public function dismiss_theme_migration_notice() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'dismiss_theme_migration_notice')) {
        wp_die('Security check failed');
    }

    // Remove the notice flag
    delete_option('mxchat_show_theme_migration_notice');

    wp_send_json_success();
}

/**
 * Add nonce for theme migration notice dismiss
 */
public function add_theme_migration_nonce() {
    if (get_option('mxchat_show_theme_migration_notice', false) && $this->is_activated) {
        wp_localize_script('mxchat-admin-js', 'mxchatThemeMigration', array(
            'nonce' => wp_create_nonce('dismiss_theme_migration_notice'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
    }
}

public function mxchat_create_transcripts_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_chat_transcripts';

    // Get basic stats
    $total_chats = $wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM $table_name") ?: 0;
    $total_messages = $wpdb->get_var("SELECT COUNT(*) FROM $table_name") ?: 0;

    // Count unique users with detailed breakdown
    $total_users = $wpdb->get_var("
        SELECT COUNT(DISTINCT
            CASE
                WHEN user_email != '' AND user_email IS NOT NULL THEN user_email
                WHEN user_id != 0 THEN CONCAT('user_', user_id)
                WHEN user_identifier NOT LIKE 'Tech-Savvy User'
                     AND user_identifier NOT LIKE 'Detail-Oriented User'
                     AND user_identifier NOT LIKE 'Language Learner'
                     AND user_identifier NOT LIKE 'Casual Browser'
                     AND user_identifier NOT LIKE 'Policy Enforcer'
                     AND user_identifier NOT LIKE 'Researcher'
                     AND user_identifier NOT LIKE 'Loyalty Member'
                     AND user_identifier NOT LIKE 'Gift Buyer'
                     AND user_identifier NOT LIKE 'Parent or Caregiver'
                THEN user_identifier
                ELSE session_id
            END
        )
        FROM $table_name
        WHERE role != 'assistant'
    ");

    // Get user type breakdown
    $registered_users = $wpdb->get_var("
        SELECT COUNT(DISTINCT user_email)
        FROM $table_name
        WHERE user_email != '' AND user_email IS NOT NULL
    ");

    $guest_users = $wpdb->get_var("
        SELECT COUNT(DISTINCT user_identifier)
        FROM $table_name
        WHERE (user_email = '' OR user_email IS NULL)
        AND role != 'assistant'
        AND user_identifier NOT LIKE 'Tech-Savvy User'
        AND user_identifier NOT LIKE 'Detail-Oriented User'
        AND user_identifier NOT LIKE 'Language Learner'
        AND user_identifier NOT LIKE 'Casual Browser'
        AND user_identifier NOT LIKE 'Policy Enforcer'
        AND user_identifier NOT LIKE 'Researcher'
        AND user_identifier NOT LIKE 'Loyalty Member'
        AND user_identifier NOT LIKE 'Gift Buyer'
        AND user_identifier NOT LIKE 'Parent or Caregiver'
    ");

    // Get agent test messages count
    $agent_tests = $wpdb->get_var("
        SELECT COUNT(DISTINCT session_id)
        FROM $table_name
        WHERE user_identifier IN (
            'Tech-Savvy User',
            'Detail-Oriented User',
            'Language Learner',
            'Casual Browser',
            'Policy Enforcer',
            'Researcher',
            'Loyalty Member',
            'Gift Buyer',
            'Parent or Caregiver'
        )
    ");
    // Get activity metrics
    $today_chats = $wpdb->get_var("
        SELECT COUNT(DISTINCT session_id)
        FROM $table_name
        WHERE DATE(timestamp) = CURDATE()
    ");
    
    $week_chats = $wpdb->get_var("
        SELECT COUNT(DISTINCT session_id)
        FROM $table_name
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    
    $month_chats = $wpdb->get_var("
        SELECT COUNT(DISTINCT session_id)
        FROM $table_name
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    
    // Get daily chat data for last 7 days
    $daily_stats = $wpdb->get_results("
        SELECT 
            DATE(timestamp) as date,
            COUNT(DISTINCT session_id) as chats,
            COUNT(*) as messages
        FROM $table_name
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(timestamp)
        ORDER BY date ASC
    ");
    
    // Get average messages per chat
    $avg_messages = $wpdb->get_var("
        SELECT AVG(message_count) 
        FROM (
            SELECT session_id, COUNT(*) as message_count
            FROM $table_name
            GROUP BY session_id
        ) as chat_counts
    ");
    $avg_messages = $avg_messages ? round($avg_messages, 1) : 0;
    
    // Get busiest hour
    $busiest_hour = $wpdb->get_row("
        SELECT HOUR(timestamp) as hour, COUNT(DISTINCT session_id) as chat_count
        FROM $table_name
        GROUP BY HOUR(timestamp)
        ORDER BY chat_count DESC
        LIMIT 1
    ");
    
    // Prepare chart data
    $chart_labels = array();
    $chart_chats = array();
    $chart_messages = array();
    
    // Fill last 7 days with data
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $day_name = date('D', strtotime("-$i days"));
        $chart_labels[] = $day_name;
        
        $found = false;
        foreach ($daily_stats as $stat) {
            if ($stat->date === $date) {
                $chart_chats[] = (int)$stat->chats;
                $chart_messages[] = (int)$stat->messages;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $chart_chats[] = 0;
            $chart_messages[] = 0;
        }
    }

    // Satisfaction rating rollup — last 30 days, grouped by bot (plan-a5b006).
    $satisfaction_stats = $this->get_satisfaction_rating_stats(30);

    // Prepare page data for the template
    $page_data = array(
        'total_chats' => $total_chats,
        'total_messages' => $total_messages,
        'total_users' => $total_users,
        'registered_users' => $registered_users,
        'guest_users' => $guest_users,
        'agent_tests' => $agent_tests,
        'today_chats' => $today_chats,
        'week_chats' => $week_chats,
        'month_chats' => $month_chats,
        'avg_messages' => $avg_messages,
        'busiest_hour' => $busiest_hour,
        'chart_labels' => $chart_labels,
        'chart_chats' => $chart_chats,
        'chart_messages' => $chart_messages,
        'satisfaction_stats' => $satisfaction_stats,
    );

    // Include and render the new template
    require_once plugin_dir_path(__FILE__) . 'admin-transcripts-page.php';
    mxchat_render_transcripts_page($this, $page_data);
}

/**
 * Per-bot satisfaction rating rollup over the last $days days. Used by the
 * Satisfaction card on the Transcripts dashboard (plan-a5b006).
 *
 * @param int $days Window in days.
 * @return array Each entry: ['bot_id', 'total', 'positive', 'negative', 'positive_pct', 'negative_pct'].
 */
public function get_satisfaction_rating_stats($days = 30) {
    global $wpdb;
    $table = $wpdb->prefix . 'mxchat_session_ratings';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
        return array();
    }
    $days = max(1, (int) $days);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT bot_id,
                COUNT(*) AS total,
                SUM(CASE WHEN rating_value = 1  THEN 1 ELSE 0 END) AS positive,
                SUM(CASE WHEN rating_value = -1 THEN 1 ELSE 0 END) AS negative
         FROM {$table}
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
         GROUP BY bot_id
         ORDER BY total DESC",
        $days
    ));
    $out = array();
    foreach ((array) $rows as $row) {
        $total    = (int) $row->total;
        $positive = (int) $row->positive;
        $negative = (int) $row->negative;
        $out[] = array(
            'bot_id'       => $row->bot_id ?: 'default',
            'total'        => $total,
            'positive'     => $positive,
            'negative'     => $negative,
            'positive_pct' => $total > 0 ? (int) round(($positive / $total) * 100) : 0,
            'negative_pct' => $total > 0 ? (int) round(($negative / $total) * 100) : 0,
        );
    }
    return $out;
}

/**
 * Get chart data for transcripts page
 * Used by both page render and script localization
 *
 * @return array Chart data with labels, chats, and messages arrays
 */
private function get_transcripts_chart_data() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_chat_transcripts';

    // Get daily chat data for last 7 days - same query as mxchat_transcripts_page()
    $daily_stats = $wpdb->get_results("
        SELECT
            DATE(timestamp) as date,
            COUNT(DISTINCT session_id) as chats,
            COUNT(*) as messages
        FROM $table_name
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(timestamp)
        ORDER BY date ASC
    ");

    // Prepare chart data
    $chart_labels = array();
    $chart_chats = array();
    $chart_messages = array();

    // Fill last 7 days with data - same logic as mxchat_transcripts_page()
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $day_name = date('D', strtotime("-$i days"));
        $chart_labels[] = $day_name;

        $found = false;
        if ($daily_stats) {
            foreach ($daily_stats as $stat) {
                if ($stat->date === $date) {
                    $chart_chats[] = (int)$stat->chats;
                    $chart_messages[] = (int)$stat->messages;
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            $chart_chats[] = 0;
            $chart_messages[] = 0;
        }
    }

    return array(
        'labels' => $chart_labels,
        'chats' => $chart_chats,
        'messages' => $chart_messages
    );
}

public function mxchat_transcripts_notification_section_callback() {
    echo '<p>' . esc_html__('Configure email notifications for new chat transcripts. You will receive an email notification when a new chat session begins.', 'mxchat') . '</p>';
}
public function mxchat_enable_notifications_callback() {
    $options = get_option('mxchat_transcripts_options', array());
    $enabled = isset($options['mxchat_enable_notifications']) ? $options['mxchat_enable_notifications'] : 0;
    ?>
    <label for="mxchat_enable_notifications">
        <input type="checkbox" id="mxchat_enable_notifications"
               name="mxchat_transcripts_options[mxchat_enable_notifications]"
               value="1" <?php checked(1, $enabled); ?>>
        <?php esc_html_e('Send email notification when a new chat session starts', 'mxchat'); ?>
    </label>
    <p class="description">
        <?php esc_html_e('Enable this option to receive email notifications for new chat sessions.', 'mxchat'); ?>
    </p>
    <?php
}
public function mxchat_notification_email_callback() {
    $options = get_option('mxchat_transcripts_options', array());
    $email = isset($options['mxchat_notification_email']) ? $options['mxchat_notification_email'] : get_option('admin_email');
    ?>
    <input type="email" id="mxchat_notification_email"
           name="mxchat_transcripts_options[mxchat_notification_email]"
           value="<?php echo esc_attr($email); ?>"
           class="regular-text">
    <p class="description">
        <?php esc_html_e('Enter the email address where notifications should be sent. Defaults to the admin email address.', 'mxchat'); ?>
    </p>
    <?php
}

public function mxchat_auto_delete_transcripts_callback() {
    $options = get_option('mxchat_transcripts_options', array());
    $interval = isset($options['mxchat_auto_delete_transcripts']) ? $options['mxchat_auto_delete_transcripts'] : 'never';
    ?>
    <select id="mxchat_auto_delete_transcripts" 
            name="mxchat_transcripts_options[mxchat_auto_delete_transcripts]">
        <option value="never" <?php selected($interval, 'never'); ?>>
            <?php esc_html_e('Never (Keep All Transcripts)', 'mxchat'); ?>
        </option>
        <option value="1week" <?php selected($interval, '1week'); ?>>
            <?php esc_html_e('After 1 Week', 'mxchat'); ?>
        </option>
        <option value="2weeks" <?php selected($interval, '2weeks'); ?>>
            <?php esc_html_e('After 2 Weeks', 'mxchat'); ?>
        </option>
        <option value="1month" <?php selected($interval, '1month'); ?>>
            <?php esc_html_e('After 1 Month', 'mxchat'); ?>
        </option>
    </select>
    <p class="description">
        <?php esc_html_e('Automatically delete old chat transcripts after the selected time period. This helps manage database size and privacy.', 'mxchat'); ?>
    </p>
    <?php
}

/**
 * Custom retention-days input. When > 0 it overrides the bucket dropdown above
 * and deletes transcripts older than the given number of days. Set to 0 to fall
 * back to the dropdown (or "Never" if the dropdown is also Never).
 *
 * Devs can override the final day count via the `mxchat_transcript_retention_days`
 * filter — runs in `cleanup_old_transcripts()` after this option is read.
 *
 * (plan-mxchat-20260509-9b80b1)
 */
public function mxchat_retention_days_callback() {
    $options = get_option('mxchat_transcripts_options', array());
    $days = isset($options['mxchat_retention_days']) ? (int) $options['mxchat_retention_days'] : 0;
    ?>
    <input type="number"
           id="mxchat_retention_days"
           name="mxchat_transcripts_options[mxchat_retention_days]"
           value="<?php echo esc_attr($days); ?>"
           min="0"
           max="3650"
           step="1"
           style="width: 90px;" />
    <p class="description">
        <?php esc_html_e('Number of days to retain transcripts. When set to a value greater than 0, this overrides the dropdown above. Set to 0 to use the dropdown. Maximum 3650 (10 years). A daily wp-cron task removes anything older, cascading to translations and click-tracking rows.', 'mxchat'); ?>
        <br>
        <code>apply_filters( 'mxchat_transcript_retention_days', $days )</code>
        <?php esc_html_e('lets developers override the final day count programmatically.', 'mxchat'); ?>
    </p>
    <?php
}

public function mxchat_auto_email_transcript_callback() {
    $options = get_option('mxchat_transcripts_options', array());
    $enabled = isset($options['mxchat_auto_email_transcript_enabled']) ? $options['mxchat_auto_email_transcript_enabled'] : 0;
    $delay = isset($options['mxchat_auto_email_transcript_delay']) ? $options['mxchat_auto_email_transcript_delay'] : '30';
    $require_contact = isset($options['mxchat_auto_email_transcript_require_contact']) ? $options['mxchat_auto_email_transcript_require_contact'] : 0;
    ?>
    <label>
        <input type="checkbox"
               id="mxchat_auto_email_transcript_enabled"
               name="mxchat_transcripts_options[mxchat_auto_email_transcript_enabled]"
               value="1"
               <?php checked($enabled, 1); ?>>
        <?php esc_html_e('Enable Auto-Email of Full Transcript', 'mxchat'); ?>
    </label>
    <br><br>
    <label for="mxchat_auto_email_transcript_delay">
        <?php esc_html_e('Send transcript after:', 'mxchat'); ?>
    </label>
    <select id="mxchat_auto_email_transcript_delay"
            name="mxchat_transcripts_options[mxchat_auto_email_transcript_delay]">
        <option value="15" <?php selected($delay, '15'); ?>>
            <?php esc_html_e('15 minutes', 'mxchat'); ?>
        </option>
        <option value="30" <?php selected($delay, '30'); ?>>
            <?php esc_html_e('30 minutes', 'mxchat'); ?>
        </option>
        <option value="60" <?php selected($delay, '60'); ?>>
            <?php esc_html_e('1 hour', 'mxchat'); ?>
        </option>
    </select>
    <br><br>
    <label>
        <input type="checkbox"
               id="mxchat_auto_email_transcript_require_contact"
               name="mxchat_transcripts_options[mxchat_auto_email_transcript_require_contact]"
               value="1"
               <?php checked($require_contact, 1); ?>>
        <?php esc_html_e('Only send if visitor provided contact info', 'mxchat'); ?>
    </label>
    <p class="description">
        <?php esc_html_e('Automatically email the full transcript as a .txt file after the specified time has passed since the last user message. The scheduled email will be cancelled if a new message is received.', 'mxchat'); ?>
        <br>
        <?php esc_html_e('When "Only send if visitor provided contact info" is enabled, transcripts will only be emailed if the visitor shared an email address or phone number (including WhatsApp) in the chat.', 'mxchat'); ?>
    </p>
    <?php
}



public function sanitize_transcripts_options($input) {
    $sanitized = array();

    $sanitized['mxchat_enable_notifications'] = isset($input['mxchat_enable_notifications']) ? 1 : 0;

    if (isset($input['mxchat_notification_email'])) {
        $sanitized['mxchat_notification_email'] = sanitize_email($input['mxchat_notification_email']);
        if (!is_email($sanitized['mxchat_notification_email'])) {
            add_settings_error(
                'mxchat_transcripts_options',
                'invalid_email',
                __('Please enter a valid email address for notifications.', 'mxchat'),
                'error'
            );
            $sanitized['mxchat_notification_email'] = get_option('admin_email');
        }
    }

    // Sanitize auto-delete setting
    $valid_intervals = array('never', '1week', '2weeks', '1month');
    if (isset($input['mxchat_auto_delete_transcripts'])) {
        $sanitized['mxchat_auto_delete_transcripts'] = in_array($input['mxchat_auto_delete_transcripts'], $valid_intervals) 
            ? $input['mxchat_auto_delete_transcripts'] 
            : 'never';
    } else {
        $sanitized['mxchat_auto_delete_transcripts'] = 'never';
    }

    // Sanitize custom retention-days override (plan-9b80b1).
    if (isset($input['mxchat_retention_days'])) {
        $days = (int) $input['mxchat_retention_days'];
        $sanitized['mxchat_retention_days'] = max(0, min(3650, $days));
    } else {
        $sanitized['mxchat_retention_days'] = 0;
    }

    // Get old values to check if auto-delete or retention-days changed.
    $old_options = get_option('mxchat_transcripts_options');
    $old_interval = isset($old_options['mxchat_auto_delete_transcripts']) ? $old_options['mxchat_auto_delete_transcripts'] : 'never';
    $old_retention = isset($old_options['mxchat_retention_days']) ? (int) $old_options['mxchat_retention_days'] : 0;

    // If either setting changed, reschedule the cron job. The schedule_transcript_cleanup
    // helper now treats "any active retention" (dropdown != never OR custom days > 0) as a
    // reason to keep the daily cron registered.
    $interval_changed  = ($old_interval !== $sanitized['mxchat_auto_delete_transcripts']);
    $retention_changed = ($old_retention !== $sanitized['mxchat_retention_days']);
    if ($interval_changed || $retention_changed) {
        $any_active = ($sanitized['mxchat_auto_delete_transcripts'] !== 'never') || ($sanitized['mxchat_retention_days'] > 0);
        $this->schedule_transcript_cleanup($any_active ? 'active' : 'never');
    }

    // Sanitize auto-email transcript settings
    $sanitized['mxchat_auto_email_transcript_enabled'] = isset($input['mxchat_auto_email_transcript_enabled']) ? 1 : 0;

    $valid_delays = array('15', '30', '60');
    if (isset($input['mxchat_auto_email_transcript_delay'])) {
        $sanitized['mxchat_auto_email_transcript_delay'] = in_array($input['mxchat_auto_email_transcript_delay'], $valid_delays)
            ? $input['mxchat_auto_email_transcript_delay']
            : '30';
    } else {
        $sanitized['mxchat_auto_email_transcript_delay'] = '30';
    }

    // Sanitize require contact info setting
    $sanitized['mxchat_auto_email_transcript_require_contact'] = isset($input['mxchat_auto_email_transcript_require_contact']) ? 1 : 0;

    return $sanitized;
}

/**
 * Schedule or unschedule the transcript cleanup cron job
 */
public function schedule_transcript_cleanup($interval) {
    // Clear any existing scheduled event
    $timestamp = wp_next_scheduled('mxchat_cleanup_old_transcripts');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'mxchat_cleanup_old_transcripts');
    }

    // Schedule new event whenever retention is active. "active" is the canonical
    // value passed by sanitize_transcripts_options when either the dropdown != never
    // OR the custom retention-days > 0; "never" turns the cron off. Any other value
    // (the legacy "1week" / "2weeks" / "1month" strings) is also treated as active.
    if ($interval !== 'never') {
        // Schedule to run daily at 3 AM
        $next_run = strtotime('tomorrow 3:00 AM');
        wp_schedule_event($next_run, 'daily', 'mxchat_cleanup_old_transcripts');
    }
}

/**
 * Delete old transcripts based on the configured interval
 */
public function cleanup_old_transcripts() {
    $options       = get_option('mxchat_transcripts_options', array());
    $interval      = isset($options['mxchat_auto_delete_transcripts']) ? $options['mxchat_auto_delete_transcripts'] : 'never';
    $custom_days   = isset($options['mxchat_retention_days']) ? (int) $options['mxchat_retention_days'] : 0;

    // Custom retention-days (plan-9b80b1) takes precedence over the bucket dropdown.
    $days = 0;
    if ($custom_days > 0) {
        $days = $custom_days;
    } else {
        switch ($interval) {
            case '1week':  $days = 7;  break;
            case '2weeks': $days = 14; break;
            case '1month': $days = 30; break;
            case 'never':
            default:
                $days = 0;
        }
    }

    // Devs can override the final day count programmatically.
    $days = (int) apply_filters('mxchat_transcript_retention_days', $days);

    if ($days <= 0) {
        return; // Retention disabled — bail.
    }

    global $wpdb;
    $transcripts_table  = $wpdb->prefix . 'mxchat_chat_transcripts';
    $translations_table = $wpdb->prefix . 'mxchat_transcript_translations';
    $url_clicks_table   = $wpdb->prefix . 'mxchat_url_clicks';

    // Defensive: if the main table doesn't exist (fresh-ish install), bail.
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $transcripts_table)) !== $transcripts_table) {
        return;
    }

    $cutoff_date = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

    // Cap at 5000 session_ids per run so a large unattended site doesn't OOM —
    // the cron will pick up where it left off on the next tick (within hours).
    $batch_cap = (int) apply_filters('mxchat_transcript_retention_batch_cap', 5000);

    $sessions_to_delete = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT session_id FROM {$transcripts_table} WHERE timestamp IS NOT NULL AND timestamp < %s LIMIT %d",
            $cutoff_date,
            $batch_cap
        )
    );

    if (empty($sessions_to_delete)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($sessions_to_delete), '%s'));

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    // $placeholders is a server-built list of literal "%s" tokens.
    $deleted_transcripts = (int) $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$transcripts_table} WHERE session_id IN ($placeholders)",
            $sessions_to_delete
        )
    );

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $translations_table)) === $translations_table) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$translations_table} WHERE session_id IN ($placeholders)",
                $sessions_to_delete
            )
        );
    }

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $url_clicks_table)) === $url_clicks_table) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$url_clicks_table} WHERE session_id IN ($placeholders)",
                $sessions_to_delete
            )
        );
    }
    // phpcs:enable

    update_option('mxchat_retention_last_swept_at', time(), false);
    update_option('mxchat_retention_rows_last_deleted', $deleted_transcripts, false);
}

public function export_chat_transcripts() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'mxchat'));
    }

    check_ajax_referer('mxchat_export_transcripts', 'security');

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_chat_transcripts';

    // Get all transcripts ordered by session and timestamp
    $results = $wpdb->get_results(
        "SELECT session_id, user_email, user_identifier, role, message, timestamp
        FROM {$table_name}
        ORDER BY session_id, timestamp ASC"
    );

    if (empty($results)) {
        wp_send_json_error(array('message' => 'No transcripts found.'));
        wp_die();
    }

    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="chat-transcripts-' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Create output stream
    $output = fopen('php://output', 'w');

    // Add UTF-8 BOM for proper Excel encoding
    fputs($output, "\xEF\xBB\xBF");

    // Add CSV headers
    fputcsv($output, array(
        'Session ID',
        'Email',
        'User Identifier',
        'Role',
        'Message',
        'Timestamp'
    ));

    // Add data rows
    foreach ($results as $row) {
        fputcsv($output, array(
            $row->session_id,
            $row->user_email,
            $row->user_identifier,
            $row->role,
            $row->message,
            $row->timestamp
        ));
    }

    fclose($output);
    wp_die();
}

// ============================================================================
// Leads tab (inside Transcripts)
//
// Leads are derived from existing data — no dedicated table. Primary source:
// wp_mxchat_chat_transcripts rows where user_email is populated. Secondary
// source: wp_options entries `mxchat_email_{session_id}` / `mxchat_name_{sid}`
// for "orphan" leads who submitted the pre-chat form but never chatted.
// ============================================================================

/**
 * Fetch leads: dedup-by-email rows, stats strip, and top pages in one call.
 */
public function mxchat_fetch_leads() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        wp_die();
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mxchat_chat_transcripts';

    $page           = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
    $per_page       = isset($_POST['per_page']) ? min(100, max(10, absint($_POST['per_page']))) : 25;
    $offset         = ($page - 1) * $per_page;
    $search         = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $date_range     = isset($_POST['date_range']) ? sanitize_key($_POST['date_range']) : 'all';
    $status         = isset($_POST['status']) ? sanitize_key($_POST['status']) : 'all';
    $page_filter    = isset($_POST['page_url']) ? esc_url_raw(wp_unslash($_POST['page_url'])) : '';
    $sort           = isset($_POST['sort']) ? sanitize_key($_POST['sort']) : 'last_seen';
    $sort_dir       = (isset($_POST['sort_dir']) && $_POST['sort_dir'] === 'asc') ? 'ASC' : 'DESC';

    $date_cutoff = self::mxchat_leads_date_cutoff($date_range);
    $has_page_url_column = !empty($wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'originating_page_url'"));

    // Base WHERE for transcripts leads.
    $where_clauses = ["user_email IS NOT NULL", "user_email != ''"];
    $where_params  = [];

    if ($date_cutoff) {
        $where_clauses[] = 'timestamp >= %s';
        $where_params[]  = $date_cutoff;
    }
    if ($page_filter && $has_page_url_column) {
        $where_clauses[] = 'originating_page_url = %s';
        $where_params[]  = $page_filter;
    }
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where_clauses[] = '(user_email LIKE %s OR user_name LIKE %s)';
        $where_params[]  = $like;
        $where_params[]  = $like;
    }
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

    // Aggregate query grouped by email.
    $select_sql = $has_page_url_column
        ? "SELECT user_email, MAX(timestamp) AS last_seen, MIN(timestamp) AS first_seen,
                  COUNT(DISTINCT session_id) AS conversation_count"
        : "SELECT user_email, MAX(timestamp) AS last_seen, MIN(timestamp) AS first_seen,
                  COUNT(DISTINCT session_id) AS conversation_count";

    $order_column = in_array($sort, ['last_seen', 'conversation_count', 'first_seen'], true) ? $sort : 'last_seen';
    $group_order_limit = " GROUP BY user_email ORDER BY {$order_column} {$sort_dir} LIMIT %d OFFSET %d";

    $transcripts_sql = $wpdb->prepare(
        "{$select_sql} FROM {$table} {$where_sql}{$group_order_limit}",
        array_merge($where_params, [$per_page, $offset])
    );
    $transcript_rows = $wpdb->get_results($transcripts_sql);

    // Count of unique transcript-based leads under the same filters.
    $count_sql = $wpdb->prepare(
        "SELECT COUNT(DISTINCT user_email) FROM {$table} {$where_sql}",
        $where_params
    );
    $transcripts_lead_count = (int) $wpdb->get_var($count_sql);

    // Hydrate each row: name, latest_session_id, top page.
    $leads = [];
    foreach ($transcript_rows as $row) {
        $detail = $has_page_url_column
            ? $wpdb->get_row($wpdb->prepare(
                "SELECT session_id, user_name, originating_page_url, originating_page_title
                 FROM {$table} WHERE user_email = %s ORDER BY timestamp DESC LIMIT 1",
                $row->user_email
            ))
            : $wpdb->get_row($wpdb->prepare(
                "SELECT session_id, user_name FROM {$table}
                 WHERE user_email = %s ORDER BY timestamp DESC LIMIT 1",
                $row->user_email
            ));

        $leads[] = [
            'email'              => $row->user_email,
            'name'               => isset($detail->user_name) ? (string) $detail->user_name : '',
            'conversation_count' => (int) $row->conversation_count,
            'last_seen'          => $row->last_seen,
            'last_seen_display'  => self::mxchat_leads_format_relative($row->last_seen),
            'first_seen'         => $row->first_seen,
            'latest_session_id'  => isset($detail->session_id) ? $detail->session_id : '',
            'top_page_url'       => isset($detail->originating_page_url) ? $detail->originating_page_url : '',
            'top_page_title'     => isset($detail->originating_page_title) ? $detail->originating_page_title : '',
            'is_orphan'          => false,
            'status'             => 'active',
        ];
    }

    // Non-transcript lead sources. Built once here, then filtered/merged based on the
    // status filter below. Deduplication priority when the same email appears in multiple
    // sources: transcripts > chat_deleted > orphan.
    $transcripts_emails_seen = array_flip(array_map(
        function ($r) { return strtolower($r['email']); },
        $leads
    ));

    // Chat-deleted leads: had a conversation that an admin removed. Preserved via
    // mxchat_lead_del_* options, with timestamps so they respect date filters.
    $chat_deleted_leads_all = [];
    if ($status === 'all' || $status === 'chat_deleted') {
        $chat_deleted_leads_all = self::mxchat_collect_chat_deleted_leads($search, $date_cutoff);
        // Dedup: drop any chat_deleted row whose email is already in the transcripts set.
        $chat_deleted_leads_all = array_values(array_filter(
            $chat_deleted_leads_all,
            function ($row) use ($transcripts_emails_seen) {
                return !isset($transcripts_emails_seen[strtolower($row['email'])]);
            }
        ));
        foreach ($chat_deleted_leads_all as $row) {
            $transcripts_emails_seen[strtolower($row['email'])] = true;
        }
    }

    // Orphans: pre-chat form captures with no conversation. No timestamp, so skipped
    // when a date filter is active.
    $orphan_leads_all = [];
    if (($status === 'all' || $status === 'orphan') && !$date_cutoff && !$page_filter) {
        $orphan_leads_all = self::mxchat_collect_orphan_leads($search);
        $orphan_leads_all = array_values(array_filter(
            $orphan_leads_all,
            function ($row) use ($transcripts_emails_seen) {
                return !isset($transcripts_emails_seen[strtolower($row['email'])]);
            }
        ));
    }

    // Apply status filter to the transcripts-derived list.
    if ($status === 'orphan' || $status === 'chat_deleted') {
        $leads = [];
        $transcripts_lead_count = 0;
    }

    // Stitch the current page from the three buckets in priority order.
    $total_count = $transcripts_lead_count + count($chat_deleted_leads_all) + count($orphan_leads_all);
    $remaining_slots = $per_page - count($leads);

    if ($remaining_slots > 0 && !empty($chat_deleted_leads_all)) {
        $start = max(0, ($page - 1) * $per_page - $transcripts_lead_count);
        if ($start < count($chat_deleted_leads_all)) {
            $leads = array_merge($leads, array_slice($chat_deleted_leads_all, $start, $remaining_slots));
            $remaining_slots = $per_page - count($leads);
        }
    }

    if ($remaining_slots > 0 && !empty($orphan_leads_all)) {
        $before = $transcripts_lead_count + count($chat_deleted_leads_all);
        $start = max(0, ($page - 1) * $per_page - $before);
        if ($start < count($orphan_leads_all)) {
            $leads = array_merge($leads, array_slice($orphan_leads_all, $start, $remaining_slots));
        }
    }

    $total_pages = $per_page > 0 ? (int) ceil($total_count / $per_page) : 1;

    // Stats strip: always computed over full dataset, unaffected by filters.
    $stats = self::mxchat_leads_stats($table, $has_page_url_column);

    // Top pages: top 5 by distinct emails captured.
    $top_pages = [];
    if ($has_page_url_column) {
        $top_pages_rows = $wpdb->get_results(
            "SELECT originating_page_url AS url,
                    MAX(originating_page_title) AS title,
                    COUNT(DISTINCT user_email) AS lead_count
             FROM {$table}
             WHERE user_email IS NOT NULL AND user_email != ''
               AND originating_page_url IS NOT NULL AND originating_page_url != ''
             GROUP BY originating_page_url
             ORDER BY lead_count DESC, url ASC
             LIMIT 5"
        );
        foreach ($top_pages_rows as $p) {
            $top_pages[] = [
                'url'        => $p->url,
                'title'      => $p->title ?: $p->url,
                'lead_count' => (int) $p->lead_count,
            ];
        }
    }

    wp_send_json([
        'success'        => true,
        'leads'          => $leads,
        'page'           => $page,
        'per_page'       => $per_page,
        'total_count'    => $total_count,
        'total_pages'    => $total_pages,
        'showing_start'  => $total_count === 0 ? 0 : ($offset + 1),
        'showing_end'    => min($offset + $per_page, $total_count),
        'stats'          => $stats,
        'top_pages'      => $top_pages,
    ]);
    wp_die();
}

/**
 * Delete one or more leads by email. Removes every transcripts row for that
 * email and cleans up related wp_options (mxchat_email_{sid}, mxchat_name_{sid},
 * mxchat_history_{sid}) and any orphan option entries matching the email.
 */
public function mxchat_delete_leads() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        wp_die();
    }
    check_ajax_referer('mxchat_delete_leads', 'security');

    $emails_raw = isset($_POST['emails']) ? (array) wp_unslash($_POST['emails']) : [];
    $emails = [];
    foreach ($emails_raw as $e) {
        $clean = sanitize_email((string) $e);
        if ($clean) {
            $emails[] = $clean;
        }
    }
    if (empty($emails)) {
        wp_send_json_error(['message' => 'No emails provided']);
        wp_die();
    }

    $summary = self::mxchat_wipe_leads_by_email($emails);

    wp_send_json([
        'success'           => true,
        'deleted_leads'     => count($emails),
        'deleted_sessions'  => $summary['deleted_sessions'],
        'deleted_rows'      => $summary['deleted_rows'],
    ]);
    wp_die();
}

/**
 * Fully wipe one or more leads by email: every transcripts row, every related wp_options
 * entry (history, pre-chat capture, chat_deleted preservation, agent name, translations).
 *
 * Shared between the Leads-tab Delete button and the transcript-delete opt-in checkbox.
 * Input emails must already be sanitized with sanitize_email().
 */
private static function mxchat_wipe_leads_by_email(array $emails) {
    global $wpdb;
    $table = $wpdb->prefix . 'mxchat_chat_transcripts';
    $translations_table = $wpdb->prefix . 'mxchat_transcript_translations';
    $has_translations = $wpdb->get_var("SHOW TABLES LIKE '$translations_table'") === $translations_table;

    $deleted_sessions = 0;
    $deleted_rows = 0;
    $emails_lc = array_map('strtolower', $emails);

    foreach ($emails as $email) {
        $session_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT session_id FROM {$table} WHERE user_email = %s",
            $email
        ));

        $rows_removed = $wpdb->delete($table, ['user_email' => $email], ['%s']);
        if ($rows_removed !== false) {
            $deleted_rows += (int) $rows_removed;
        }

        foreach ($session_ids as $sid) {
            $deleted_sessions++;
            wp_cache_delete('chat_session_' . $sid, 'mxchat_chat_sessions');
            delete_option('mxchat_history_' . $sid);
            delete_option('mxchat_email_' . $sid);
            delete_option('mxchat_name_' . $sid);
            delete_option('mxchat_agent_name_' . $sid);
            delete_option('mxchat_lead_del_email_' . $sid);
            delete_option('mxchat_lead_del_name_' . $sid);
            delete_option('mxchat_lead_del_ts_' . $sid);
            if ($has_translations) {
                $wpdb->delete($translations_table, ['session_id' => $sid], ['%s']);
            }
        }
    }

    // Clean up any lingering option entries (orphan pre-chat captures + chat_deleted
    // preservations) whose stored value matches one of the emails being wiped.
    $lingering = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'mxchat_email_%' OR option_name LIKE 'mxchat_lead_del_email_%'"
    );
    foreach ($lingering as $opt) {
        if (!in_array(strtolower(trim($opt->option_value)), $emails_lc, true)) {
            continue;
        }
        if (strpos($opt->option_name, 'mxchat_lead_del_email_') === 0) {
            $sid = substr($opt->option_name, strlen('mxchat_lead_del_email_'));
            delete_option('mxchat_lead_del_email_' . $sid);
            delete_option('mxchat_lead_del_name_' . $sid);
            delete_option('mxchat_lead_del_ts_' . $sid);
        } else {
            $sid = substr($opt->option_name, strlen('mxchat_email_'));
            delete_option('mxchat_email_' . $sid);
            delete_option('mxchat_name_' . $sid);
        }
    }

    wp_cache_delete('all_chat_sessions', 'mxchat_chat_sessions');

    return [
        'deleted_sessions' => $deleted_sessions,
        'deleted_rows'     => $deleted_rows,
    ];
}

/**
 * Stream a leads CSV. scope=all exports every lead under current filters is not
 * supported to keep semantics simple; caller either exports all leads or a
 * specific set of selected emails.
 */
public function mxchat_export_leads() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'mxchat'));
    }
    check_ajax_referer('mxchat_export_leads', 'security');

    $scope       = isset($_POST['scope']) ? sanitize_key($_POST['scope']) : 'all';
    $fields_mode = isset($_POST['fields']) ? sanitize_key($_POST['fields']) : 'email_and_name';
    $emails_in   = isset($_POST['emails']) ? (array) wp_unslash($_POST['emails']) : [];

    $emails_in_clean = [];
    foreach ($emails_in as $e) {
        $clean = sanitize_email((string) $e);
        if ($clean) {
            $emails_in_clean[] = $clean;
        }
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mxchat_chat_transcripts';
    $has_page_url_column = !empty($wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'originating_page_url'"));

    // Collect leads from transcripts.
    $transcripts_sql = "SELECT user_email AS email,
                               MAX(timestamp) AS last_seen,
                               COUNT(DISTINCT session_id) AS conversation_count
                        FROM {$table}
                        WHERE user_email IS NOT NULL AND user_email != ''";
    $params = [];
    if ($scope === 'selected' && !empty($emails_in_clean)) {
        $placeholders = implode(',', array_fill(0, count($emails_in_clean), '%s'));
        $transcripts_sql .= " AND user_email IN ({$placeholders})";
        $params = $emails_in_clean;
    }
    $transcripts_sql .= " GROUP BY user_email ORDER BY last_seen DESC";

    $rows = !empty($params)
        ? $wpdb->get_results($wpdb->prepare($transcripts_sql, $params))
        : $wpdb->get_results($transcripts_sql);

    // Hydrate each row with name + top page.
    $export_rows = [];
    foreach ($rows as $row) {
        $detail = $has_page_url_column
            ? $wpdb->get_row($wpdb->prepare(
                "SELECT user_name, originating_page_url FROM {$table}
                 WHERE user_email = %s ORDER BY timestamp DESC LIMIT 1",
                $row->email
            ))
            : $wpdb->get_row($wpdb->prepare(
                "SELECT user_name FROM {$table}
                 WHERE user_email = %s ORDER BY timestamp DESC LIMIT 1",
                $row->email
            ));
        $export_rows[] = [
            'email'              => $row->email,
            'name'               => isset($detail->user_name) ? (string) $detail->user_name : '',
            'conversation_count' => (int) $row->conversation_count,
            'last_seen'          => $row->last_seen,
            'top_page_url'       => isset($detail->originating_page_url) ? $detail->originating_page_url : '',
        ];
    }

    // Include orphan + chat_deleted leads when exporting all.
    if ($scope === 'all') {
        $transcripts_emails_lc = array_flip(array_map(
            function ($r) { return strtolower($r['email']); },
            $export_rows
        ));
        foreach (self::mxchat_collect_chat_deleted_leads('') as $cd) {
            if (isset($transcripts_emails_lc[strtolower($cd['email'])])) continue;
            $transcripts_emails_lc[strtolower($cd['email'])] = true;
            $export_rows[] = [
                'email'              => $cd['email'],
                'name'               => $cd['name'],
                'conversation_count' => 0,
                'last_seen'          => $cd['last_seen'],
                'top_page_url'       => '',
            ];
        }
        foreach (self::mxchat_collect_orphan_leads('') as $orphan) {
            if (isset($transcripts_emails_lc[strtolower($orphan['email'])])) continue;
            $transcripts_emails_lc[strtolower($orphan['email'])] = true;
            $export_rows[] = [
                'email'              => $orphan['email'],
                'name'               => $orphan['name'],
                'conversation_count' => 0,
                'last_seen'          => '',
                'top_page_url'       => '',
            ];
        }
    }

    if (empty($export_rows)) {
        wp_send_json_error(['message' => 'No leads to export.']);
        wp_die();
    }

    $filename = 'mxchat-leads-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    if ($fields_mode === 'email_only') {
        fputcsv($output, ['Email']);
        foreach ($export_rows as $r) {
            fputcsv($output, [$r['email']]);
        }
    } else {
        fputcsv($output, ['Email', 'Name', 'Conversations', 'Last seen', 'Top page']);
        foreach ($export_rows as $r) {
            fputcsv($output, [
                $r['email'],
                $r['name'],
                $r['conversation_count'],
                $r['last_seen'],
                $r['top_page_url'],
            ]);
        }
    }

    fclose($output);
    wp_die();
}

/**
 * Stats strip payload (independent of filters).
 */
private static function mxchat_leads_stats($table, $has_page_url_column) {
    global $wpdb;

    $total_transcripts_emails = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT user_email) FROM {$table}
         WHERE user_email IS NOT NULL AND user_email != ''"
    );

    $new_this_week = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM (
            SELECT user_email FROM {$table}
            WHERE user_email IS NOT NULL AND user_email != ''
            GROUP BY user_email
            HAVING MIN(timestamp) >= %s
         ) AS new_leads",
        gmdate('Y-m-d H:i:s', strtotime('-7 days'))
    ));

    $total_convos = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT session_id) FROM {$table}
         WHERE user_email IS NOT NULL AND user_email != ''"
    );

    $orphan_count = count(self::mxchat_collect_orphan_leads(''));
    $chat_deleted_count = self::mxchat_count_chat_deleted_leads();

    // Total leads = unique emails across all three sources (dedup priority: transcripts > chat_deleted > orphan
    // is already enforced at collection time in mxchat_fetch_leads; stats re-apply it here).
    $total_leads = $total_transcripts_emails + $chat_deleted_count + $orphan_count;

    $avg = $total_transcripts_emails > 0
        ? round($total_convos / $total_transcripts_emails, 1)
        : 0;

    // Orphan % reflects *true* orphans only (pre-chat dropoffs). Chat-deleted leads are
    // excluded so the metric stays meaningful — admins shouldn't see their cleanups
    // inflate this number.
    $orphan_pct = $total_leads > 0
        ? (int) round(($orphan_count / $total_leads) * 100)
        : 0;

    return [
        'total_leads'         => $total_leads,
        'new_this_week'       => $new_this_week,
        'avg_convos'          => $avg,
        'orphan_pct'          => $orphan_pct,
        'orphan_count'        => $orphan_count,
        'chat_deleted_count'  => $chat_deleted_count,
    ];
}

/**
 * Collect leads who had a conversation that an admin later deleted (preserved via
 * mxchat_lead_del_* options). Returns rows tagged status='chat_deleted' with the
 * original last-seen timestamp so they still sort and filter sensibly.
 *
 * @param string $search      Optional email/name substring filter.
 * @param string $date_cutoff Optional 'Y-m-d H:i:s' cutoff — only rows with last_ts >= cutoff.
 * @return array
 */
private static function mxchat_collect_chat_deleted_leads($search = '', $date_cutoff = '') {
    global $wpdb;
    $table = $wpdb->prefix . 'mxchat_chat_transcripts';

    $rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'mxchat_lead_del_email_%'"
    );
    if (empty($rows)) {
        return [];
    }

    // Emails that currently have transcripts rows should not appear as chat_deleted —
    // they've come back and chatted, so they're active leads again.
    $emails_in_transcripts = array_map(
        'strtolower',
        (array) $wpdb->get_col(
            "SELECT DISTINCT user_email FROM {$table}
             WHERE user_email IS NOT NULL AND user_email != ''"
        )
    );
    $emails_in_transcripts = array_flip($emails_in_transcripts);

    $needle = strtolower(trim((string) $search));
    $by_email = [];

    foreach ($rows as $opt) {
        $email = sanitize_email(trim((string) $opt->option_value));
        if (!$email) {
            continue;
        }
        if (isset($emails_in_transcripts[strtolower($email)])) {
            continue;
        }
        $sid = substr($opt->option_name, strlen('mxchat_lead_del_email_'));
        if (!$sid) {
            continue;
        }
        $name = (string) get_option('mxchat_lead_del_name_' . $sid, '');
        $ts   = (string) get_option('mxchat_lead_del_ts_' . $sid, '');

        if ($date_cutoff !== '' && ($ts === '' || $ts < $date_cutoff)) {
            continue;
        }
        if ($needle !== '') {
            $hay = strtolower($email . ' ' . $name);
            if (strpos($hay, $needle) === false) {
                continue;
            }
        }

        $key = strtolower($email);
        if (!isset($by_email[$key]) || (isset($by_email[$key]['last_seen']) && $ts > $by_email[$key]['last_seen'])) {
            $by_email[$key] = [
                'email'              => $email,
                'name'               => $name,
                'conversation_count' => 0,
                'last_seen'          => $ts,
                'last_seen_display'  => $ts ? self::mxchat_leads_format_relative($ts) : __('Chat deleted', 'mxchat'),
                'first_seen'         => $ts,
                'latest_session_id'  => '',
                'top_page_url'       => '',
                'top_page_title'     => '',
                'is_orphan'          => false,
                'status'             => 'chat_deleted',
            ];
        }
    }

    // Newest chat_deleted first.
    usort($by_email, function ($a, $b) {
        return strcmp((string) $b['last_seen'], (string) $a['last_seen']);
    });
    return array_values($by_email);
}

/**
 * Count unique emails preserved as "chat deleted" (for the stats strip).
 */
private static function mxchat_count_chat_deleted_leads() {
    global $wpdb;
    $table = $wpdb->prefix . 'mxchat_chat_transcripts';

    $emails = $wpdb->get_col(
        "SELECT DISTINCT option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'mxchat_lead_del_email_%'"
    );
    if (empty($emails)) {
        return 0;
    }

    $transcripts_emails = array_map(
        'strtolower',
        (array) $wpdb->get_col(
            "SELECT DISTINCT user_email FROM {$table}
             WHERE user_email IS NOT NULL AND user_email != ''"
        )
    );
    $transcripts_emails = array_flip($transcripts_emails);

    $count = 0;
    $seen = [];
    foreach ($emails as $raw) {
        $email = strtolower(trim((string) $raw));
        if (!$email || isset($seen[$email]) || isset($transcripts_emails[$email])) {
            continue;
        }
        $seen[$email] = true;
        $count++;
    }
    return $count;
}

/**
 * Find leads who submitted the pre-chat form but never produced a transcripts row.
 * Returned rows have no conversation_count, no timestamp.
 *
 * @param string $search  Optional email/name substring filter.
 * @return array
 */
private static function mxchat_collect_orphan_leads($search = '') {
    global $wpdb;
    $table = $wpdb->prefix . 'mxchat_chat_transcripts';

    $option_rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'mxchat_email_%'"
    );
    if (empty($option_rows)) {
        return [];
    }

    // Collect all session_ids that have real transcripts rows so we can exclude them.
    $session_ids_with_rows = $wpdb->get_col(
        "SELECT DISTINCT session_id FROM {$table}
         WHERE user_email IS NOT NULL AND user_email != ''"
    );
    $session_ids_with_rows = array_flip($session_ids_with_rows);

    // Seen emails in transcripts (so orphans only include truly never-chatted leads).
    $emails_in_transcripts = array_map(
        'strtolower',
        (array) $wpdb->get_col(
            "SELECT DISTINCT user_email FROM {$table}
             WHERE user_email IS NOT NULL AND user_email != ''"
        )
    );
    $emails_in_transcripts = array_flip($emails_in_transcripts);

    $orphans_by_email = [];
    $needle = strtolower(trim((string) $search));

    foreach ($option_rows as $opt) {
        $email = sanitize_email(trim((string) $opt->option_value));
        if (!$email) {
            continue;
        }
        $sid = substr($opt->option_name, strlen('mxchat_email_'));
        if (!$sid) {
            continue;
        }
        // Exclude leads who have any transcripts rows (they appear in the main list).
        if (isset($emails_in_transcripts[strtolower($email)])) {
            continue;
        }
        if (isset($session_ids_with_rows[$sid])) {
            continue;
        }

        $name_option = get_option('mxchat_name_' . $sid, '');
        $name = is_string($name_option) ? trim($name_option) : '';

        if ($needle !== '') {
            $hay = strtolower($email . ' ' . $name);
            if (strpos($hay, $needle) === false) {
                continue;
            }
        }

        $key = strtolower($email);
        if (!isset($orphans_by_email[$key])) {
            $orphans_by_email[$key] = [
                'email'              => $email,
                'name'               => $name,
                'conversation_count' => 0,
                'last_seen'          => '',
                'last_seen_display'  => __('No conversation yet', 'mxchat'),
                'first_seen'         => '',
                'latest_session_id'  => '',
                'top_page_url'       => '',
                'top_page_title'     => '',
                'is_orphan'          => true,
                'status'             => 'orphan',
            ];
        }
    }

    return array_values($orphans_by_email);
}

/**
 * Map a date_range key to a SQL-comparable cutoff string, or '' for all-time.
 */
private static function mxchat_leads_date_cutoff($date_range) {
    switch ($date_range) {
        case 'today':   return gmdate('Y-m-d H:i:s', strtotime('-24 hours'));
        case '7d':      return gmdate('Y-m-d H:i:s', strtotime('-7 days'));
        case '30d':     return gmdate('Y-m-d H:i:s', strtotime('-30 days'));
        case '90d':     return gmdate('Y-m-d H:i:s', strtotime('-90 days'));
        case 'all':
        default:        return '';
    }
}

/**
 * Turn a UTC timestamp into a short relative display like "2h ago" or "Apr 12".
 */
private static function mxchat_leads_format_relative($timestamp) {
    if (!$timestamp) {
        return '';
    }
    $ts = strtotime($timestamp . ' UTC');
    if (!$ts) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60)       return __('just now', 'mxchat');
    if ($diff < 3600)     return floor($diff / 60) . __('m ago', 'mxchat');
    if ($diff < 86400)    return floor($diff / 3600) . __('h ago', 'mxchat');
    if ($diff < 604800)   return floor($diff / 86400) . __('d ago', 'mxchat');
    return wp_date('M j', $ts);
}

/**
 * Handle translation of chat messages via AJAX
 */
public function mxchat_translate_messages() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['error' => 'Insufficient permissions']);
        wp_die();
    }

    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
    $target_lang = isset($_POST['target_lang']) ? sanitize_text_field($_POST['target_lang']) : 'en';
    $messages_json = isset($_POST['messages']) ? wp_unslash($_POST['messages']) : '[]';
    $messages = json_decode($messages_json, true);

    if (empty($session_id)) {
        wp_send_json_error(['error' => 'No session ID provided']);
        wp_die();
    }

    if (empty($messages) || !is_array($messages)) {
        wp_send_json_error(['error' => 'No messages to translate']);
        wp_die();
    }

    // Language names for prompting
    $languages = [
        'en' => 'English',
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'nl' => 'Dutch',
        'ru' => 'Russian',
        'zh' => 'Chinese',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'ar' => 'Arabic',
        'hi' => 'Hindi',
        'tr' => 'Turkish',
        'pl' => 'Polish',
        'vi' => 'Vietnamese',
        'th' => 'Thai',
        'id' => 'Indonesian',
        'sv' => 'Swedish',
        'da' => 'Danish'
    ];

    $target_lang_name = isset($languages[$target_lang]) ? $languages[$target_lang] : 'English';

    // Build combined text for translation (numbered for parsing)
    $numbered_messages = [];
    foreach ($messages as $i => $msg) {
        $content = isset($msg['content']) ? trim($msg['content']) : '';
        if (!empty($content)) {
            $numbered_messages[] = "[MSG" . $i . "]" . $content . "[/MSG" . $i . "]";
        }
    }

    if (empty($numbered_messages)) {
        wp_send_json_error(['error' => 'No valid messages to translate']);
        wp_die();
    }

    $combined_text = implode("\n\n", $numbered_messages);

    // Prepare the translation prompt
    $system_prompt = "You are a translator. Translate the following messages to {$target_lang_name}. Keep the [MSG#] and [/MSG#] tags exactly as they are - only translate the content between them. Maintain the original formatting, line breaks, and any HTML tags. Return ONLY the translated messages with the tags, no explanations.";

    // Get user's selected model and determine provider
    $options = get_option('mxchat_options', []);
    $selected_model = $options['model'] ?? 'gpt-5.1-chat-latest';

    // Check if using OpenRouter
    if ($selected_model === 'openrouter') {
        $provider = 'openrouter';
        $selected_model = $options['openrouter_selected_model'] ?? '';
        $api_key = $options['openrouter_api_key'] ?? '';

        if (empty($selected_model)) {
            wp_send_json_error(['error' => 'No OpenRouter model selected']);
            wp_die();
        }
    } else {
        // Determine provider from model name
        $model_parts = explode('-', $selected_model);
        $provider = strtolower($model_parts[0]);

        // Get the appropriate API key based on provider
        $api_key = '';
        switch ($provider) {
            case 'gpt':
            case 'o1':
                $api_key = $options['api_key'] ?? '';
                break;
            case 'claude':
                $api_key = $options['claude_api_key'] ?? '';
                break;
            case 'grok':
                $api_key = $options['xai_api_key'] ?? '';
                break;
            case 'deepseek':
                $api_key = $options['deepseek_api_key'] ?? '';
                break;
            case 'gemini':
                $api_key = $options['gemini_api_key'] ?? '';
                break;
            default:
                // Default to OpenAI
                $api_key = $options['api_key'] ?? '';
                $provider = 'gpt';
                break;
        }
    }

    if (empty($api_key)) {
        wp_send_json_error(['error' => 'No API key configured for ' . $provider]);
        wp_die();
    }

    // Make API request based on provider
    $response = $this->translate_with_provider($provider, $selected_model, $api_key, $system_prompt, $combined_text);

    if (is_wp_error($response)) {
        wp_send_json_error(['error' => $response->get_error_message()]);
        wp_die();
    }

    // Parse the response to extract translated messages
    $translations = [];
    foreach ($messages as $msg) {
        $index = $msg['index'];
        $pattern = '/\[MSG' . $index . '\](.*?)\[\/MSG' . $index . '\]/s';
        if (preg_match($pattern, $response, $matches)) {
            $translations[] = [
                'index' => $index,
                'translated' => trim($matches[1])
            ];
        }
    }

    // Save translations to database
    if (!empty($translations)) {
        $this->save_transcript_translation($session_id, $target_lang, $translations);
    }

    wp_send_json(['success' => true, 'translations' => $translations, 'language' => $target_lang]);
    wp_die();
}

/**
 * Save transcript translation to database
 */
private function save_transcript_translation($session_id, $language_code, $translations) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_transcript_translations';

    // Check if table exists, create if not
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
        mxchat_create_translations_table();
    }

    $now = current_time('mysql');
    $translations_json = wp_json_encode($translations);

    // Use REPLACE to insert or update
    $wpdb->query($wpdb->prepare(
        "REPLACE INTO $table_name (session_id, language_code, translations, created_at, updated_at)
         VALUES (%s, %s, %s, %s, %s)",
        $session_id,
        $language_code,
        $translations_json,
        $now,
        $now
    ));
}

/**
 * Get saved translation for a session
 */
public function mxchat_get_transcript_translation() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['error' => 'Insufficient permissions']);
        wp_die();
    }

    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

    if (empty($session_id)) {
        wp_send_json_error(['error' => 'No session ID provided']);
        wp_die();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_transcript_translations';

    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
        wp_send_json(['success' => true, 'has_translation' => false]);
        wp_die();
    }

    // Get the most recent translation for this session
    $result = $wpdb->get_row($wpdb->prepare(
        "SELECT language_code, translations FROM $table_name WHERE session_id = %s ORDER BY updated_at DESC LIMIT 1",
        $session_id
    ));

    if ($result) {
        $translations = json_decode($result->translations, true);
        wp_send_json([
            'success' => true,
            'has_translation' => true,
            'language' => $result->language_code,
            'translations' => $translations
        ]);
    } else {
        wp_send_json(['success' => true, 'has_translation' => false]);
    }
    wp_die();
}

/**
 * Translate text using the user's selected provider and model
 */
private function translate_with_provider($provider, $model, $api_key, $system_prompt, $text) {
    switch ($provider) {
        case 'claude':
            return $this->translate_with_claude($api_key, $model, $system_prompt, $text);
        case 'grok':
            return $this->translate_with_xai($api_key, $model, $system_prompt, $text);
        case 'deepseek':
            return $this->translate_with_deepseek($api_key, $model, $system_prompt, $text);
        case 'gemini':
            return $this->translate_with_gemini($api_key, $model, $system_prompt, $text);
        case 'openrouter':
            return $this->translate_with_openrouter($api_key, $model, $system_prompt, $text);
        case 'gpt':
        case 'o1':
        default:
            return $this->translate_with_openai($api_key, $model, $system_prompt, $text);
    }
}

/**
 * Translate text using OpenAI API
 */
private function translate_with_openai($api_key, $model, $system_prompt, $text) {
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => wp_json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $text]
            ],
            'temperature' => 0.3
        ])
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['error'])) {
        return new WP_Error('api_error', $body['error']['message']);
    }

    if (isset($body['choices'][0]['message']['content'])) {
        return $body['choices'][0]['message']['content'];
    }

    return new WP_Error('api_error', 'Invalid API response');
}

/**
 * Translate text using Claude API
 */
private function translate_with_claude($api_key, $model, $system_prompt, $text) {
    // Anthropic retired claude-opus-4-20250514 / claude-sonnet-4-20250514 on 2026-06-15.
    // Read-time rescue: remap a saved dead ID to the current equivalent before the API call.
    if ($model === 'claude-opus-4-20250514') { $model = 'claude-opus-4-8'; }
    elseif ($model === 'claude-sonnet-4-20250514') { $model = 'claude-sonnet-4-6'; }
    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 60,
        'headers' => [
            'x-api-key' => $api_key,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json'
        ],
        'body' => wp_json_encode([
            'model' => $model,
            'max_tokens' => 4096,
            'system' => $system_prompt,
            'messages' => [
                ['role' => 'user', 'content' => $text]
            ]
        ])
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['error'])) {
        return new WP_Error('api_error', $body['error']['message']);
    }

    if (isset($body['content'][0]['text'])) {
        return $body['content'][0]['text'];
    }

    return new WP_Error('api_error', 'Invalid API response');
}

/**
 * Translate text using xAI (Grok) API
 */
private function translate_with_xai($api_key, $model, $system_prompt, $text) {
    $response = wp_remote_post('https://api.x.ai/v1/chat/completions', [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => wp_json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $text]
            ],
            'temperature' => 0.3
        ])
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['error'])) {
        return new WP_Error('api_error', $body['error']['message']);
    }

    if (isset($body['choices'][0]['message']['content'])) {
        return $body['choices'][0]['message']['content'];
    }

    return new WP_Error('api_error', 'Invalid API response');
}

/**
 * Translate text using DeepSeek API
 */
private function translate_with_deepseek($api_key, $model, $system_prompt, $text) {
    $response = wp_remote_post('https://api.deepseek.com/v1/chat/completions', [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => wp_json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $text]
            ],
            'temperature' => 0.3
        ])
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['error'])) {
        return new WP_Error('api_error', $body['error']['message']);
    }

    if (isset($body['choices'][0]['message']['content'])) {
        return $body['choices'][0]['message']['content'];
    }

    return new WP_Error('api_error', 'Invalid API response');
}

/**
 * Translate text using Google Gemini API
 */
private function translate_with_gemini($api_key, $model, $system_prompt, $text) {
    if ($model === 'gemini-3-pro-preview') {
        $model = 'gemini-3.1-pro-preview';
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;

    $response = wp_remote_post($url, [
        'timeout' => 60,
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'body' => wp_json_encode([
            'contents' => [
                [
                    'parts' => [
                        ['text' => $system_prompt . "\n\n" . $text]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.3
            ]
        ])
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['error'])) {
        return new WP_Error('api_error', $body['error']['message']);
    }

    if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
        return $body['candidates'][0]['content']['parts'][0]['text'];
    }

    return new WP_Error('api_error', 'Invalid API response');
}

/**
 * Translate text using OpenRouter API
 */
private function translate_with_openrouter($api_key, $model, $system_prompt, $text) {
    $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
            'HTTP-Referer' => home_url(),
            'X-Title' => 'MxChat Translation'
        ],
        'body' => wp_json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $text]
            ],
            'temperature' => 0.3
        ])
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['error'])) {
        return new WP_Error('api_error', $body['error']['message']);
    }

    if (isset($body['choices'][0]['message']['content'])) {
        return $body['choices'][0]['message']['content'];
    }

    return new WP_Error('api_error', 'Invalid API response');
}

public function mxchat_fetch_chat_history() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_chat_transcripts';
    $url_clicks_table = $wpdb->prefix . 'mxchat_url_clicks';

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        wp_die();
    }

    $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
    $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 50;
    $offset = ($page - 1) * $per_page;
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $sort_raw = isset($_POST['sort_order']) ? sanitize_key($_POST['sort_order']) : 'desc';
    $allowed_sorts = array('asc', 'desc', 'rating_positive', 'rating_negative');
    if (!in_array($sort_raw, $allowed_sorts, true)) { $sort_raw = 'desc'; }
    $sort_order = ($sort_raw === 'asc') ? 'ASC' : 'DESC';
    $ratings_table = $wpdb->prefix . 'mxchat_session_ratings';
    $ratings_join = '';
    $rating_table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $ratings_table)) === $ratings_table);
    if ($rating_table_exists && ($sort_raw === 'rating_positive' || $sort_raw === 'rating_negative')) {
        // We sort by rating then recency. Wrap rating_value so NULL sorts last.
        $rating_dir = ($sort_raw === 'rating_positive') ? 'DESC' : 'ASC';
        $ratings_join = " LEFT JOIN {$ratings_table} r ON r.session_id = t.session_id ";
    }

    // Build search condition
    $search_condition = '';
    $search_params = [];
    if (!empty($search)) {
        $search_condition = "WHERE (
            session_id LIKE %s
            OR user_email LIKE %s
            OR user_name LIKE %s
            OR user_identifier LIKE %s
            OR message LIKE %s
        )";
        $search_params = array_fill(0, 5, '%' . $wpdb->esc_like($search) . '%');
    }

    // Get total count
    $count_query = !empty($search)
        ? $wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM {$table_name} {$search_condition}", $search_params)
        : "SELECT COUNT(DISTINCT session_id) FROM {$table_name}";
    $total_sessions = (int) $wpdb->get_var($count_query);

    // Get session IDs for current page. Default sort is recency; rating sorts join the ratings table
    // and order by rating value (NULLs last) with recency as tiebreaker.
    if ($ratings_join) {
        $order_by = "ORDER BY (r.rating_value IS NULL), r.rating_value {$rating_dir}, MAX(t.timestamp) DESC";
    } else {
        $order_by = "ORDER BY MAX(t.timestamp) {$sort_order}";
    }
    $session_query = !empty($search)
        ? $wpdb->prepare(
            "SELECT DISTINCT t.session_id FROM {$table_name} t {$ratings_join} {$search_condition}
             GROUP BY t.session_id {$order_by} LIMIT %d OFFSET %d",
            array_merge($search_params, [$per_page, $offset])
        )
        : $wpdb->prepare(
            "SELECT DISTINCT t.session_id FROM {$table_name} t {$ratings_join}
             GROUP BY t.session_id {$order_by} LIMIT %d OFFSET %d",
            $per_page, $offset
        );
    $session_ids = $wpdb->get_col($session_query);

    $total_pages = ceil($total_sessions / $per_page);

    if (empty($session_ids)) {
        wp_send_json([
            'success' => true,
            'sessions' => [],
            'page' => $page,
            'total_pages' => 0,
            'total_sessions' => 0,
            'showing_start' => 0,
            'showing_end' => 0
        ]);
        wp_die();
    }

    // Check for optional columns/tables
    $url_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$url_clicks_table'") === $url_clicks_table;
    $originating_columns_exist = !empty($wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'originating_page_url'"));

    // Batch-fetch session ratings for this page (plan-a5b006).
    $ratings_map = array();
    if ($rating_table_exists && !empty($session_ids)) {
        $placeholders = implode(',', array_fill(0, count($session_ids), '%s'));
        $rating_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT session_id, rating_value, rating_feedback FROM {$ratings_table} WHERE session_id IN ($placeholders)",
            $session_ids
        ));
        foreach ($rating_rows as $row) {
            $ratings_map[$row->session_id] = array(
                'value'    => (int) $row->rating_value,
                'feedback' => (string) $row->rating_feedback,
            );
        }
    }

    // Build session list data
    $sessions = [];
    foreach ($session_ids as $session_id) {
        // Get session metadata
        $session_data = $originating_columns_exist
            ? $wpdb->get_row($wpdb->prepare(
                "SELECT user_email, user_name, user_identifier, originating_page_url, originating_page_title, timestamp
                 FROM {$table_name} WHERE session_id = %s ORDER BY timestamp ASC LIMIT 1",
                $session_id
            ))
            : $wpdb->get_row($wpdb->prepare(
                "SELECT user_email, user_name, user_identifier, timestamp FROM {$table_name}
                 WHERE session_id = %s LIMIT 1",
                $session_id
            ));

        // Get message count and latest timestamp
        $message_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as count, MAX(timestamp) as latest FROM {$table_name} WHERE session_id = %s",
            $session_id
        ));

        // Get first user message as preview
        $first_user_msg = $wpdb->get_var($wpdb->prepare(
            "SELECT message FROM {$table_name} WHERE session_id = %s AND role = 'user' ORDER BY timestamp ASC LIMIT 1",
            $session_id
        ));
        $preview = $first_user_msg ? wp_trim_words(wp_strip_all_tags(stripslashes($first_user_msg)), 12, '...') : 'No messages';

        // Build display name
        $user_email = !empty($session_data->user_email) ? $session_data->user_email : '';
        $user_name = !empty($session_data->user_name) ? $session_data->user_name : '';
        $user_identifier = !empty($session_data->user_identifier) ? $session_data->user_identifier : 'Guest';

        $display_name = $user_name ?: ($user_email ? explode('@', $user_email)[0] : $user_identifier);
        $display_sub = $user_email ?: ('ID: ' . $user_identifier);

        // Format time - show relative for recent, date for older
        $timestamp = strtotime($message_stats->latest . ' UTC');
        $now = time();
        $diff = $now - $timestamp;
        if ($diff < 3600) {
            $time_display = floor($diff / 60) . 'm ago';
        } elseif ($diff < 86400) {
            $time_display = floor($diff / 3600) . 'h ago';
        } elseif ($diff < 604800) {
            $time_display = floor($diff / 86400) . 'd ago';
        } else {
            $time_display = wp_date('M j', $timestamp);
        }

        // Get initials for avatar
        $initials = strtoupper(substr($display_name, 0, 2));
        if (strlen($display_name) > 2 && strpos($display_name, ' ') !== false) {
            $parts = explode(' ', $display_name);
            $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
        }

        $rating_entry    = isset($ratings_map[$session_id]) ? $ratings_map[$session_id] : null;
        $rating_value    = $rating_entry ? $rating_entry['value'] : null;
        $rating_feedback = $rating_entry ? $rating_entry['feedback'] : '';

        $sessions[] = [
            'session_id' => $session_id,
            'display_name' => $display_name,
            'display_sub' => $display_sub,
            'initials' => $initials,
            'preview' => $preview,
            'message_count' => (int) $message_stats->count,
            'time_display' => $time_display,
            'timestamp' => $message_stats->latest,
            'rating_value' => $rating_value,
            'rating_feedback' => $rating_feedback,
        ];
    }

    wp_send_json([
        'success' => true,
        'sessions' => $sessions,
        'page' => $page,
        'total_pages' => $total_pages,
        'total_sessions' => $total_sessions,
        'showing_start' => $offset + 1,
        'showing_end' => min($offset + $per_page, $total_sessions)
    ]);
    wp_die();
}

/**
 * Fetch single conversation details for split-panel view
 */
public function mxchat_fetch_conversation() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_chat_transcripts';
    $url_clicks_table = $wpdb->prefix . 'mxchat_url_clicks';

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        wp_die();
    }

    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
    if (empty($session_id)) {
        wp_send_json_error(['message' => 'No session ID provided']);
        wp_die();
    }

    // Check for optional columns/tables
    $url_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$url_clicks_table'") === $url_clicks_table;
    $originating_columns_exist = !empty($wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'originating_page_url'"));

    // Get session metadata
    $session_data = $originating_columns_exist
        ? $wpdb->get_row($wpdb->prepare(
            "SELECT user_email, user_name, user_identifier, originating_page_url, originating_page_title, timestamp
             FROM {$table_name} WHERE session_id = %s ORDER BY timestamp ASC LIMIT 1",
            $session_id
        ))
        : $wpdb->get_row($wpdb->prepare(
            "SELECT user_email, user_name, user_identifier, timestamp FROM {$table_name}
             WHERE session_id = %s LIMIT 1",
            $session_id
        ));

    if (!$session_data) {
        wp_send_json_error(['message' => 'Session not found']);
        wp_die();
    }

    // Get clicked URLs
    $clicked_urls = [];
    if ($url_table_exists) {
        $url_clicks = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT clicked_url FROM {$url_clicks_table}
             WHERE session_id = %s ORDER BY click_timestamp ASC",
            $session_id
        ));
        foreach ($url_clicks as $click) {
            $clicked_urls[] = $click->clicked_url;
        }
    }

    // Get all messages
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE session_id = %s ORDER BY timestamp ASC",
        $session_id
    ));

    // Build user info
    $user_email = !empty($session_data->user_email) ? $session_data->user_email : '';
    $user_name = !empty($session_data->user_name) ? $session_data->user_name : '';
    $user_identifier = !empty($session_data->user_identifier) ? $session_data->user_identifier : 'Guest';

    $display_name = $user_name ?: ($user_email ? explode('@', $user_email)[0] : $user_identifier);
    $display_sub = $user_email ?: ('ID: ' . $user_identifier);

    // Get initials
    $initials = strtoupper(substr($display_name, 0, 2));
    if (strlen($display_name) > 2 && strpos($display_name, ' ') !== false) {
        $parts = explode(' ', $display_name);
        $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
    }

    // Page info
    $page_url = $originating_columns_exist && !empty($session_data->originating_page_url) ? $session_data->originating_page_url : '';
    $page_title = '';
    if ($page_url) {
        $page_title = !empty($session_data->originating_page_title) ? $session_data->originating_page_title : parse_url($page_url, PHP_URL_PATH);
    }

    // Format messages for output
    $formatted_messages = [];
    foreach ($messages as $msg) {
        $is_user = ($msg->role === 'user');
        $is_bot = ($msg->role === 'bot' || $msg->role === 'assistant');

        $content = wp_kses(
            stripslashes($msg->message),
            [
                'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [],
                'br' => [], 'p' => [], 'ul' => [], 'ol' => [], 'li' => [],
                'a' => ['href' => [], 'title' => [], 'target' => [], 'class' => []],
                'div' => ['class' => [], 'id' => [], 'data-nonce' => []],
                'img' => ['src' => [], 'alt' => [], 'class' => []],
                'h3' => ['class' => []],
                'h4' => ['class' => []],
                'button' => ['type' => [], 'class' => [], 'data-product-id' => [], 'data-nonce' => [], 'data-product-type' => [], 'data-original-text' => [], 'data-mxchat-action' => []],
                'select' => ['class' => [], 'data-attribute' => []],
                'option' => ['value' => []],
                'span' => ['class' => []],
                'del' => [], 'ins' => [],
            ]
        );
        $formatted_content = $this->format_transcript_message($content);

        $has_rag = $is_bot && !empty($msg->rag_context);

        $formatted_messages[] = [
            'id' => $msg->id,
            'role' => $msg->role,
            'is_user' => $is_user,
            'is_bot' => $is_bot,
            'content' => $formatted_content,
            'timestamp' => wp_date('g:i A', strtotime($msg->timestamp . ' UTC')),
            'full_timestamp' => wp_date('F j, Y g:i A', strtotime($msg->timestamp . ' UTC')),
            'has_rag' => $has_rag
        ];
    }

    // First message timestamp for "started" display
    $started = !empty($messages) ? wp_date('M j, Y g:i A', strtotime($messages[0]->timestamp . ' UTC')) : '-';

    // Pull rating_feedback for this session (if any) so the details drawer can show it.
    $rating_feedback   = '';
    $ratings_table_det = $wpdb->prefix . 'mxchat_session_ratings';
    if ($wpdb->get_var("SHOW TABLES LIKE '$ratings_table_det'") === $ratings_table_det) {
        $rating_feedback = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT rating_feedback FROM {$ratings_table_det} WHERE session_id = %s LIMIT 1",
            $session_id
        ));
    }

    wp_send_json([
        'success' => true,
        'session_id' => $session_id,
        'user' => [
            'name' => $display_name,
            'sub' => $display_sub,
            'initials' => $initials,
            'email' => $user_email,
            'identifier' => $user_identifier
        ],
        'page' => [
            'url' => $page_url,
            'title' => $page_title
        ],
        'clicked_urls' => $clicked_urls,
        'messages' => $formatted_messages,
        'message_count' => count($messages),
        'started' => $started,
        'rating_feedback' => $rating_feedback
    ]);
    wp_die();
}


/**
 * ALTERNATIVE: Simpler helper method using string replacement
 */
private function highlight_clicked_links($message_content, $clicked_urls) {
    if (empty($clicked_urls)) {
        return wp_kses(
            $message_content,
            [
                'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [],
                'br' => [], 'p' => [], 'ul' => [], 'ol' => [], 'li' => [],
                'a' => ['href' => [], 'title' => [], 'target' => [], 'class' => []]
            ]
        );
    }

    // First apply standard sanitization
    $message_content = wp_kses(
        $message_content,
        [
            'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [],
            'br' => [], 'p' => [], 'ul' => [], 'ol' => [], 'li' => [],
            'a' => ['href' => [], 'title' => [], 'target' => [], 'class' => []]
        ]
    );

    // Process each clicked URL
    foreach ($clicked_urls as $clicked_url) {
        // Try multiple patterns to catch different link formats
        $patterns = [
            // Standard link format
            '/<a([^>]*href=["\']' . preg_quote($clicked_url, '/') . '["\'][^>]*)>/i',
            // Link with trailing slash
            '/<a([^>]*href=["\']' . preg_quote(rtrim($clicked_url, '/'), '/') . '\/?["\'][^>]*)>/i',
            // Encoded entities version
            '/<a([^>]*href=["\']' . preg_quote(htmlentities($clicked_url), '/') . '["\'][^>]*)>/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message_content)) {
                $message_content = preg_replace_callback(
                    $pattern,
                    function($matches) {
                        $full_match = $matches[0];
                        $attributes = $matches[1];

                        // Check if it already has the class
                        if (strpos($full_match, 'mxchat-clicked-link') !== false) {
                            return $full_match;
                        }

                        // Check if class attribute exists
                        if (preg_match('/class=["\']([^"\']*)["\']/', $attributes, $class_matches)) {
                            // Add to existing class
                            $new_attributes = preg_replace(
                                '/class=["\']([^"\']*)["\']/',
                                'class="$1 mxchat-clicked-link"',
                                $attributes
                            );
                        } else {
                            // Add new class attribute
                            $new_attributes = $attributes . ' class="mxchat-clicked-link"';
                        }

                        // Add title if not present
                        if (strpos($new_attributes, 'title=') === false) {
                            $new_attributes .= ' title="User clicked this link"';
                        }

                        return '<a' . $new_attributes . '>';
                    },
                    $message_content
                );

                // If we found and replaced, break out of the patterns loop
                break;
            }
        }
    }

    return $message_content;
}

public function mxchat_create_prompts_page() {
    //error_log('=== DEBUG: mxchat_create_prompts_page started ===');

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_system_prompt_content';

    $knowledge_manager = MxChat_Knowledge_Manager::get_instance();
    $pinecone_manager = MxChat_Pinecone_Manager::get_instance();

    // Display success message if all prompts were deleted
    if (isset($_GET['all_deleted']) && $_GET['all_deleted'] === 'true') {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('All knowledge has been deleted successfully.', 'mxchat') . '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">' . esc_html__('Dismiss this notice.', 'mxchat') . '</span></button></div>';
    }

    // Set up pagination, search query, and content type filter
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';
    $search_query = (!empty($nonce) && wp_verify_nonce($nonce, 'mxchat_prompts_search_nonce') && isset($_GET['search'])) ? sanitize_text_field($_GET['search']) : '';
    $content_type_filter = isset($_GET['content_type']) ? sanitize_key($_GET['content_type']) : ''; // ADDED 2.5.6
    $current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
    $per_page = 25;

    //error_log('DEBUG: Search query: ' . $search_query);
    //error_log('DEBUG: Content type filter: ' . $content_type_filter);
    //error_log('DEBUG: Current page: ' . $current_page);
    //error_log('DEBUG: Per page: ' . $per_page);

    // ================================
    // MULTI-BOT CONFIGURATION
    // ================================
    
    // Check for multi-bot and set up bot selection
    if (class_exists('MxChat_Multi_Bot_Manager')) {
        $multi_bot_manager = MxChat_Multi_Bot_Core_Manager::get_instance();
        $available_bots = $multi_bot_manager->get_available_bots();
        
        // Get saved bot selection (user-specific first, then site-wide default)
        $user_id = get_current_user_id();
        $saved_bot_id = get_user_meta($user_id, 'mxchat_selected_knowledge_bot', true);
        if (empty($saved_bot_id)) {
            $saved_bot_id = get_option('mxchat_current_knowledge_bot', 'default');
        }
        
        // Allow URL override but default to saved selection
        $current_bot_id = isset($_GET['bot_id']) ? sanitize_key($_GET['bot_id']) : $saved_bot_id;
        $multibot_active = true;
    } else {
        $current_bot_id = 'default';
        $multibot_active = false;
    }

    // ================================
    // DATA SOURCE CONFIGURATION
    // ================================

    // Get bot-specific Pinecone settings
    $pinecone_options = $pinecone_manager->mxchat_get_bot_pinecone_options($current_bot_id);
    $use_pinecone = ($pinecone_options['mxchat_use_pinecone'] ?? '0') === '1';
    $pinecone_api_key = $pinecone_options['mxchat_pinecone_api_key'] ?? '';

    // Get OpenAI Vector Store settings
    $vectorstore_options = get_option('mxchat_openai_vectorstore_options', array());
    $use_vectorstore = ($vectorstore_options['mxchat_use_openai_vectorstore'] ?? '0') === '1';

    //error_log('DEBUG: Bot ' . $current_bot_id . ' - Use Pinecone: ' . ($use_pinecone ? 'YES' : 'NO'));
    //error_log('DEBUG: Bot ' . $current_bot_id . ' - Has API Key: ' . (!empty($pinecone_api_key) ? 'YES' : 'NO'));
    //error_log('DEBUG: Bot ' . $current_bot_id . ' - Host: ' . ($pinecone_options['mxchat_pinecone_host'] ?? 'NOT SET'));
    //error_log('DEBUG: Bot ' . $current_bot_id . ' - Namespace: ' . ($pinecone_options['mxchat_pinecone_namespace'] ?? 'NOT SET'));

    if ($use_pinecone && !empty($pinecone_api_key)) {
        //error_log('DEBUG: Using PINECONE data source with bot-specific config');
        // PINECONE DATA SOURCE
        $data_source = 'pinecone';
        
        // IMPORTANT: Pass the bot-specific $pinecone_options, not default options!
        // UPDATED 2.5.6: Added content_type_filter parameter
        $records = $pinecone_manager->mxchat_fetch_pinecone_records($pinecone_options, $search_query, $current_page, $per_page, $current_bot_id, $content_type_filter);

        // TEMPORARY DEBUG - Add this right after the fetch call
        //error_log('=== DEBUG: Bot switching issue ===');
        //error_log('Current bot ID: ' . $current_bot_id);
        //error_log('Use Pinecone: ' . ($use_pinecone ? 'YES' : 'NO'));
        //error_log('Records returned: ' . count($records['data'] ?? []));
        //error_log('Total records: ' . ($records['total'] ?? 0));

        // Check first few records to see their bot_id
        if (!empty($records['data'])) {
            foreach (array_slice($records['data'], 0, 3) as $i => $record) {
                $record_bot_id = $record->bot_id ?? 'NOT_SET';
                //error_log('Record ' . ($i+1) . ' bot_id: ' . $record_bot_id . ', content preview: ' . substr($record->article_content ?? '', 0, 30) . '...');
            }
        }
        //error_log('=== END DEBUG ===');

        $total_records = $records['total'] ?? 0;
        $prompts = $records['data'] ?? array();
        $total_in_database = $records['total_in_database'] ?? 0;
        $showing_recent_only = $records['showing_recent_only'] ?? false;

        $total_pages = ceil($total_records / $per_page);

    } else {
        //error_log('DEBUG: Using WORDPRESS DB data source');
        // WORDPRESS DB DATA SOURCE (your existing logic)
        $data_source = 'wordpress';

        // Initialize these variables for WordPress DB
        $total_in_database = 0;
        $showing_recent_only = false;

        $offset = ($current_page - 1) * $per_page;

        // UPDATED 2.5.6: Build WHERE clause for search and content type filtering
        $where_clauses = array();
        $where_values = array();

        if ($search_query) {
            $where_clauses[] = "article_content LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($search_query) . '%';
        }

        if ($content_type_filter) {
            $where_clauses[] = "content_type = %s";
            $where_values[] = $content_type_filter;
        }

        $sql_where = "";
        if (!empty($where_clauses)) {
            $sql_where = "WHERE " . implode(" AND ", $where_clauses);
        }

        // UPDATED 2.6.3: Count unique entries (by source_url) instead of individual rows
        // This ensures pagination shows X entries per page, not X chunks
        // Entries with empty source_url are counted individually
        if (!empty($where_values)) {
            // Count unique source_urls + count of rows with empty source_url
            $count_query = $wpdb->prepare(
                "SELECT (SELECT COUNT(DISTINCT source_url) FROM {$table_name} {$sql_where} AND source_url != '') +
                        (SELECT COUNT(*) FROM {$table_name} {$sql_where} AND (source_url = '' OR source_url IS NULL))",
                array_merge($where_values, $where_values)
            );
        } else {
            $count_query = "SELECT (SELECT COUNT(DISTINCT source_url) FROM {$table_name} WHERE source_url != '') +
                                  (SELECT COUNT(*) FROM {$table_name} WHERE source_url = '' OR source_url IS NULL)";
        }
        $total_records = $wpdb->get_var($count_query);
        $total_pages = ceil($total_records / $per_page);

        // UPDATED 2.6.3: Get unique source_urls for pagination, then fetch all their rows
        // Step 1: Get the source_urls for this page (distinct URLs ordered by latest timestamp)
        if (!empty($where_values)) {
            $urls_query = $wpdb->prepare(
                "SELECT source_url, MAX(timestamp) as latest_ts FROM {$table_name} {$sql_where}
                 GROUP BY source_url ORDER BY latest_ts DESC LIMIT %d OFFSET %d",
                array_merge($where_values, array($per_page, $offset))
            );
        } else {
            $urls_query = $wpdb->prepare(
                "SELECT source_url, MAX(timestamp) as latest_ts FROM {$table_name}
                 GROUP BY source_url ORDER BY latest_ts DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            );
        }
        $page_urls = $wpdb->get_results($urls_query);

        // Step 2: Fetch all rows for these source_urls
        $prompts = array();
        if (!empty($page_urls)) {
            // Build URL order map to preserve newest-first ordering from step 1
            $url_order_map = array();
            $url_list = array();
            $has_empty_url = false;
            $order_index = 0;
            foreach ($page_urls as $url_row) {
                if (empty($url_row->source_url)) {
                    $has_empty_url = true;
                    $url_order_map['__empty__'] = $order_index++;
                } else {
                    $url_list[] = $url_row->source_url;
                    $url_order_map[$url_row->source_url] = $order_index++;
                }
            }

            // Build query to fetch all rows for these URLs
            $url_conditions = array();
            $url_values = array();

            if (!empty($url_list)) {
                $placeholders = implode(',', array_fill(0, count($url_list), '%s'));
                $url_conditions[] = "source_url IN ($placeholders)";
                $url_values = array_merge($url_values, $url_list);
            }

            if ($has_empty_url) {
                $url_conditions[] = "(source_url = '' OR source_url IS NULL)";
            }

            if (!empty($url_conditions)) {
                $url_where = "WHERE (" . implode(" OR ", $url_conditions) . ")";

                // Add original filters back
                if (!empty($where_clauses)) {
                    $url_where .= " AND " . implode(" AND ", $where_clauses);
                    $url_values = array_merge($url_values, $where_values);
                }

                if (!empty($url_values)) {
                    $prompts_query = $wpdb->prepare(
                        "SELECT * FROM {$table_name} {$url_where} ORDER BY timestamp DESC",
                        $url_values
                    );
                } else {
                    $prompts_query = "SELECT * FROM {$table_name} {$url_where} ORDER BY timestamp DESC";
                }

                $prompts = $wpdb->get_results($prompts_query);

                // Sort prompts by the original URL order (newest first), then by timestamp within each URL group
                usort($prompts, function($a, $b) use ($url_order_map) {
                    $url_a = empty($a->source_url) ? '__empty__' : $a->source_url;
                    $url_b = empty($b->source_url) ? '__empty__' : $b->source_url;
                    $order_a = $url_order_map[$url_a] ?? PHP_INT_MAX;
                    $order_b = $url_order_map[$url_b] ?? PHP_INT_MAX;

                    // First sort by URL order (newest URLs first)
                    if ($order_a !== $order_b) {
                        return $order_a - $order_b;
                    }

                    // Within same URL, sort by timestamp DESC (newest chunks first)
                    return strtotime($b->timestamp) - strtotime($a->timestamp);
                });
            }
        }
    }

    // ================================
    // PAGINATION GENERATION
    // ================================
    
    // Generate pagination links
    if ($total_pages > 1) {
        // Build a clean base URL with only necessary parameters
        $pagination_args = array('page' => 'mxchat-prompts');

        // Preserve bot_id parameter if multi-bot is active
        if ($multibot_active && !empty($current_bot_id) && $current_bot_id !== 'default') {
            $pagination_args['bot_id'] = $current_bot_id;
        }

        // Preserve search query and nonce if present
        if ($search_query) {
            $pagination_args['search'] = $search_query;
            if (!empty($nonce)) {
                $pagination_args['_wpnonce'] = $nonce;
            }
        }

        // Preserve content type filter if present
        if ($content_type_filter) {
            $pagination_args['content_type'] = $content_type_filter;
        }

        // Build clean base URL
        $base_url = add_query_arg($pagination_args, admin_url('admin.php'));

        $page_links = paginate_links(array(
            'base' => $base_url . '%_%',
            'format' => '&paged=%#%',
            'prev_text' => __('&laquo; Previous', 'mxchat'),
            'next_text' => __('Next &raquo;', 'mxchat'),
            'total' => $total_pages,
            'current' => $current_page,
            'type' => 'plain',
        ));
    } else {
        $page_links = '';
    }

    // ================================
    // PROCESSING STATUS RETRIEVAL
    // ================================

    // Retrieve processing statuses using queue-based method
    $processing_statuses = $knowledge_manager->mxchat_get_processing_statuses();
    $pdf_status = $processing_statuses['pdf_status'];
    $sitemap_status = $processing_statuses['sitemap_status'];
    $is_processing = $processing_statuses['is_processing'];

    //error_log('=== DEBUG: mxchat_create_prompts_page data preparation completed ===');

    // ================================
    // RENDER PAGE WITH NEW SIDEBAR LAYOUT
    // ================================

    // Include the new knowledge page template
    require_once plugin_dir_path(__FILE__) . 'admin-knowledge-page.php';

    // Package all page data for the render function
    $page_data = array(
        'prompts' => $prompts,
        'total_records' => $total_records,
        'total_pages' => $total_pages,
        'current_page' => $current_page,
        'per_page' => $per_page,
        'page_links' => $page_links,
        'search_query' => $search_query,
        'content_type_filter' => $content_type_filter,
        'data_source' => $data_source,
        'use_pinecone' => $use_pinecone,
        'use_vectorstore' => $use_vectorstore,
        'multibot_active' => $multibot_active,
        'current_bot_id' => $current_bot_id,
        'pdf_status' => $pdf_status,
        'sitemap_status' => $sitemap_status,
        'is_processing' => $is_processing,
        'total_in_database' => $total_in_database ?? 0,
        'showing_recent_only' => $showing_recent_only ?? false,
    );

    // Render the new sidebar-based page
    mxchat_render_knowledge_page($this, $knowledge_manager, $page_data);
}

/**
 * Get bot-specific Pinecone configuration
 * Used in the knowledge retrieval functions
 */
private function get_bot_pinecone_config($bot_id = 'default') {
    //error_log("MXCHAT DEBUG: get_bot_pinecone_config called for bot: " . $bot_id);
    
    // If default bot or multi-bot add-on not active, use default Pinecone config
    if ($bot_id === 'default' || !class_exists('MxChat_Multi_Bot_Manager')) {
        //error_log("MXCHAT DEBUG: Using default Pinecone config (no multi-bot or bot is 'default')");
        $addon_options = get_option('mxchat_pinecone_addon_options', array());
        $config = array(
            'use_pinecone' => (isset($addon_options['mxchat_use_pinecone']) && $addon_options['mxchat_use_pinecone'] === '1'),
            'api_key' => $addon_options['mxchat_pinecone_api_key'] ?? '',
            'host' => $addon_options['mxchat_pinecone_host'] ?? '',
            'namespace' => $addon_options['mxchat_pinecone_namespace'] ?? ''
        );
        //error_log("MXCHAT DEBUG: Default config - use_pinecone: " . ($config['use_pinecone'] ? 'true' : 'false'));
        return $config;
    }
    
    //error_log("MXCHAT DEBUG: Calling filter 'mxchat_get_bot_pinecone_config' for bot: " . $bot_id);
    
    // Hook for multi-bot add-on to provide bot-specific Pinecone config
    $bot_pinecone_config = apply_filters('mxchat_get_bot_pinecone_config', array(), $bot_id);
    
    if (!empty($bot_pinecone_config)) {
        //error_log("MXCHAT DEBUG: Got bot-specific config from filter");
        //error_log("  - use_pinecone: " . (isset($bot_pinecone_config['use_pinecone']) ? ($bot_pinecone_config['use_pinecone'] ? 'true' : 'false') : 'not set'));
        //error_log("  - host: " . ($bot_pinecone_config['host'] ?? 'not set'));
        //error_log("  - namespace: " . ($bot_pinecone_config['namespace'] ?? 'not set'));
    } else {
        //error_log("MXCHAT DEBUG: Filter returned empty config!");
    }
    
    return is_array($bot_pinecone_config) ? $bot_pinecone_config : array();
}

public function mxchat_delete_chat_history() {
    if (!current_user_can('manage_options')) {
        echo wp_json_encode(['error' => esc_html__('You do not have sufficient permissions.', 'mxchat')]);
        wp_die();
    }
    check_ajax_referer('mxchat_delete_chat_history', 'security');

    if (!isset($_POST['delete_session_ids']) || !is_array($_POST['delete_session_ids'])) {
        echo wp_json_encode(['error' => esc_html__('No chat sessions selected for deletion.', 'mxchat')]);
        wp_die();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_chat_transcripts';
    $translations_table = $wpdb->prefix . 'mxchat_transcript_translations';
    $has_translations = $wpdb->get_var("SHOW TABLES LIKE '$translations_table'") === $translations_table;

    // When true, any lead attached to these sessions is fully wiped (all their sessions,
    // across the whole table). Default false: the chat rows go away but the lead is
    // preserved as a separate "chat deleted" lead in the Leads tab.
    $also_delete_lead = !empty($_POST['also_delete_lead']) && $_POST['also_delete_lead'] !== 'false';

    $deleted_count = 0;
    $preserved_as_deleted_leads = 0;
    $emails_to_fully_wipe = [];

    foreach ((array) $_POST['delete_session_ids'] as $session_id) {
        $session_id_sanitized = sanitize_text_field($session_id);
        if ($session_id_sanitized === '') {
            continue;
        }

        // Capture the lead info attached to this session *before* we delete the rows.
        $lead_row = $wpdb->get_row($wpdb->prepare(
            "SELECT user_email, user_name, MAX(timestamp) AS last_ts
             FROM {$table_name}
             WHERE session_id = %s AND user_email IS NOT NULL AND user_email != ''
             GROUP BY user_email, user_name
             ORDER BY last_ts DESC LIMIT 1",
            $session_id_sanitized
        ));

        wp_cache_delete('chat_session_' . $session_id_sanitized, 'mxchat_chat_sessions');
        $wpdb->delete($table_name, ['session_id' => $session_id_sanitized]);

        if ($has_translations) {
            $wpdb->delete($translations_table, ['session_id' => $session_id_sanitized]);
        }

        delete_option('mxchat_history_' . $session_id_sanitized);
        delete_option('mxchat_agent_name_' . $session_id_sanitized);

        if ($lead_row && !empty($lead_row->user_email)) {
            if ($also_delete_lead) {
                // Full-wipe requested — queue the email so that all their sessions and
                // related options get swept below. Also clear this session's pre-chat
                // capture options (they're no longer meaningful).
                $emails_to_fully_wipe[strtolower($lead_row->user_email)] = $lead_row->user_email;
                delete_option('mxchat_email_' . $session_id_sanitized);
                delete_option('mxchat_name_' . $session_id_sanitized);
            } else {
                // Preserve the lead in a "Chat deleted" state via distinct option keys so
                // they stay out of the orphan bucket (orphan = pre-chat form dropoff).
                update_option('mxchat_lead_del_email_' . $session_id_sanitized, $lead_row->user_email, false);
                if (!empty($lead_row->user_name)) {
                    update_option('mxchat_lead_del_name_' . $session_id_sanitized, $lead_row->user_name, false);
                }
                if (!empty($lead_row->last_ts)) {
                    update_option('mxchat_lead_del_ts_' . $session_id_sanitized, $lead_row->last_ts, false);
                }
                // Clean up pre-chat capture options for this session — chat_deleted supersedes.
                delete_option('mxchat_email_' . $session_id_sanitized);
                delete_option('mxchat_name_' . $session_id_sanitized);
                $preserved_as_deleted_leads++;
            }
        } else {
            // No lead attached — nothing to preserve. Clean up any orphan options anyway.
            delete_option('mxchat_email_' . $session_id_sanitized);
            delete_option('mxchat_name_' . $session_id_sanitized);
        }

        $deleted_count++;
    }

    // Opt-in full-lead wipe: sweep every remaining row + every option key (including
    // chat_deleted preservation) for each affected email. Reuses the same internal
    // helper as the Leads-tab Delete button for consistency.
    if (!empty($emails_to_fully_wipe)) {
        self::mxchat_wipe_leads_by_email(array_values($emails_to_fully_wipe));
    }

    wp_cache_delete('all_chat_sessions', 'mxchat_chat_sessions');

    echo wp_json_encode([
        'success' => sprintf(
            esc_html__('%d chat session(s) have been deleted.', 'mxchat'),
            $deleted_count
        ),
        'preserved_as_deleted_leads' => $preserved_as_deleted_leads,
        'leads_fully_wiped' => count($emails_to_fully_wipe),
    ]);
    wp_die();
}

/**
 * Format transcript message content with markdown processing
 * Converts markdown links, bold, italic, code blocks, and plain URLs to HTML
 */
private function format_transcript_message($text) {
    if (empty($text)) {
        return '';
    }

    // Normalize line endings and clean up excessive whitespace
    $text = str_replace("\r\n", "\n", $text);
    $text = str_replace("\r", "\n", $text);

    // Clean up existing <br> tags that may have been saved (legacy data)
    // Convert <br>, <br/>, <br /> back to newlines for consistent processing
    $text = preg_replace('/<br\s*\/?>\s*/i', "\n", $text);

    // Collapse 3+ consecutive newlines to just 2 (paragraph break)
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    // Process markdown headers (# Header)
    $text = preg_replace_callback('/^(#{1,6})\s+(.+)$/m', function($matches) {
        $level = strlen($matches[1]);
        $content = esc_html(trim($matches[2]));
        return "<h{$level}>{$content}</h{$level}>";
    }, $text);

    // Process code blocks with triple backticks
    $text = preg_replace_callback('/```(\w+)?\n?([\s\S]*?)```/', function($matches) {
        $language = !empty($matches[1]) ? ' class="language-' . esc_attr($matches[1]) . '"' : '';
        $code = esc_html($matches[2]);
        return "<pre><code{$language}>{$code}</code></pre>";
    }, $text);

    // Process inline code with single backticks
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

    // Process bold text **text** or __text__
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);

    // Process italic text *text* or _text_ (but not if part of URL)
    $text = preg_replace('/(?<![*_\w])\*([^*]+)\*(?![*\w])/', '<em>$1</em>', $text);
    $text = preg_replace('/(?<![*_\w])_([^_]+)_(?![*\w])/', '<em>$1</em>', $text);

    // Process markdown links [text](url)
    $text = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', function($matches) {
        $link_text = esc_html($matches[1]);
        $url = esc_url($matches[2]);
        return "<a href=\"{$url}\" target=\"_blank\" rel=\"noopener\">{$link_text}</a>";
    }, $text);

    // Process citation-style brackets [URL]
    $text = preg_replace_callback('/\[(https?:\/\/[^\]]+)\]/', function($matches) {
        $url = esc_url($matches[1]);
        return "<a href=\"{$url}\" target=\"_blank\" rel=\"noopener\">{$url}</a>";
    }, $text);

    // Process standalone URLs (not already in links or img src)
    $text = preg_replace_callback(
        '/(?<!href="|src="|">)(https?:\/\/[^\s<>"]+)(?![^<]*<\/a>)/',
        function($matches) {
            $url = esc_url($matches[1]);
            // Truncate display URL if too long
            $display = strlen($matches[1]) > 50 ? substr($matches[1], 0, 47) . '...' : $matches[1];
            return "<a href=\"{$url}\" target=\"_blank\" rel=\"noopener\">{$display}</a>";
        },
        $text
    );

    // Process mailto links
    $text = preg_replace_callback('/\[([^\]]+)\]\((mailto:[^)]+)\)/', function($matches) {
        $link_text = esc_html($matches[1]);
        $mailto = esc_url($matches[2]);
        return "<a href=\"{$mailto}\">{$link_text}</a>";
    }, $text);

    // Convert paragraphs: split by double newlines, wrap in <p> tags
    // This creates proper paragraph structure instead of excessive <br> tags
    $paragraphs = preg_split('/\n\n+/', $text);

    // Filter out empty paragraphs but preserve content like "0"
    $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), function($p) {
        return $p !== '';
    }));

    if (empty($paragraphs)) {
        // No content after filtering
        return '';
    } elseif (count($paragraphs) > 1) {
        // Multiple paragraphs - wrap each in <p> tags, convert single newlines to <br>
        $formatted_paragraphs = array_map(function($p) {
            return nl2br($p);
        }, $paragraphs);
        $text = '<p>' . implode('</p><p>', $formatted_paragraphs) . '</p>';
    } else {
        // Single paragraph - just convert newlines to <br>
        $text = nl2br($paragraphs[0]);
    }

    return $text;
}

/**
 * AJAX handler to fetch RAG context for a specific message
 * Used by the transcript viewer to show retrieved documents
 */
public function mxchat_get_rag_context() {
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => esc_html__('You do not have sufficient permissions.', 'mxchat')]);
        wp_die();
    }

    // Validate message ID
    if (!isset($_POST['message_id']) || empty($_POST['message_id'])) {
        wp_send_json_error(['message' => esc_html__('Message ID is required.', 'mxchat')]);
        wp_die();
    }

    $message_id = absint($_POST['message_id']);

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_chat_transcripts';

    // Fetch the RAG context for this message
    $result = $wpdb->get_row($wpdb->prepare(
        "SELECT rag_context FROM {$table_name} WHERE id = %d",
        $message_id
    ));

    if (!$result || empty($result->rag_context)) {
        wp_send_json_error(['message' => esc_html__('No RAG context found for this message.', 'mxchat')]);
        wp_die();
    }

    // Decode the JSON data
    $rag_context = json_decode($result->rag_context, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error(['message' => esc_html__('Invalid RAG context data.', 'mxchat')]);
        wp_die();
    }

    wp_send_json_success($rag_context);
    wp_die();
}

public function display_admin_notices() {
    // Check if we're on a MXChat admin page
    $screen = get_current_screen();
    if (!$screen || strpos($screen->base, 'mxchat') === false) {
        return;
    }

    $dismiss_button = '<button type="button" class="notice-dismiss"><span class="screen-reader-text">' . esc_html__('Dismiss this notice.', 'mxchat') . '</span></button>';

    // Check for error notices
    $error_notice = get_transient('mxchat_admin_notice_error');
    if ($error_notice) {
        echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post($error_notice) . '</p>' . $dismiss_button . '</div>';
        delete_transient('mxchat_admin_notice_error');
    }

    // Check for success notices
    $success_notice = get_transient('mxchat_admin_notice_success');
    if ($success_notice) {
        echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post($success_notice) . '</p>' . $dismiss_button . '</div>';
        delete_transient('mxchat_admin_notice_success');
    }

    // Check for info notices
    $info_notice = get_transient('mxchat_admin_notice_info');
    if ($info_notice) {
        echo '<div class="notice notice-info is-dismissible"><p>' . wp_kses_post($info_notice) . '</p>' . $dismiss_button . '</div>';
        delete_transient('mxchat_admin_notice_info');
    }
}


public function mxchat_create_activation_page() {
    // Include the new Pro & Extensions page template
    require_once plugin_dir_path(__FILE__) . 'admin-pro-page.php';

    // Get addons configuration from the MxChat_Addons class
    require_once plugin_dir_path(__FILE__) . 'class-mxchat-addons.php';
    $addons_instance = new MxChat_Addons();
    $addons_config = $addons_instance->get_addons_config();

    // Render the consolidated Pro & Extensions page
    mxchat_render_pro_page($this, $addons_config);
}

/**
 * Check if current activation is linked to a domain
 * This checks YOUR website's database, not the user's local database
 */
public function is_current_activation_linked($domain) {
    $license_key = get_option('mxchat_activation_key');
    $email = get_option('mxchat_pro_email');

    if (empty($license_key) || empty($email)) {
        return false;
    }

    // Check with YOUR website's API
    $response = wp_remote_post('https://mxchat.ai/mxchat-api/check-domain', array(
        'body' => array(
            'license_key' => $license_key,
            'email' => $email,
            'domain' => $domain
        ),
        'timeout' => 10,
        'sslverify' => false
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return isset($body['success']) && $body['success'] && isset($body['data']['linked']) && $body['data']['linked'];
}


public function mxchat_actions_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_intents';

    // Get stats for dashboard
    $total_actions = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $enabled_actions = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE enabled = 1");
    $disabled_actions = $total_actions - $enabled_actions;

    // Get unique action types count
    $action_types_count = $wpdb->get_var("SELECT COUNT(DISTINCT callback_function) FROM $table_name");

    // Get action type distribution
    $type_distribution_raw = $wpdb->get_results("SELECT callback_function, COUNT(*) as count FROM $table_name GROUP BY callback_function ORDER BY count DESC LIMIT 10");
    $available_callbacks = $this->mxchat_get_available_callbacks();
    $callback_groups = $this->mxchat_get_available_callbacks(true, true);

    $action_type_distribution = array();
    foreach ($type_distribution_raw as $row) {
        $label = isset($available_callbacks[$row->callback_function]['label'])
            ? $available_callbacks[$row->callback_function]['label']
            : $row->callback_function;
        $action_type_distribution[$label] = $row->count;
    }

    // Native function-calling (AI Tools) data — plan-mxchat-20260617-a41dee.
    // The AI Tools checklist reads from MxChat_Tool_Registry, the SAME single
    // source the chat-time function-calling loop reads, so the two never drift.
    if (!class_exists('MxChat_Tool_Registry')) {
        require_once plugin_dir_path(__FILE__) . 'class-mxchat-tool-registry.php';
    }
    if (!class_exists('MxChat_Model_Catalog')) {
        require_once plugin_dir_path(__FILE__) . 'class-mxchat-model-catalog.php';
    }
    $fc_options       = get_option('mxchat_options', array());
    $fc_current_model = isset($fc_options['model']) ? $fc_options['model'] : 'gpt-5.1-chat-latest';
    $fc_model_capable = class_exists('MxChat_Model_Catalog')
        ? MxChat_Model_Catalog::supports_tools($fc_current_model) : true;
    $active_tab = (isset($_GET['tab']) && $_GET['tab'] === 'ai-tools') ? 'ai-tools' : 'dashboard';

    // Enrich each AI Tool with the dashicon its callback already uses on the
    // Trigger Phrases "Choose what it does" grid, so the AI Tools cards/modal
    // share the same iconography (plan 8bbf98 part 4). View-layer only — the
    // registry's model-facing data is untouched. Default admin-generic.
    $fc_tools = MxChat_Tool_Registry::available_tools();
    foreach ($fc_tools as &$fc_tool_ref) {
        $fc_cb_ref = $fc_tool_ref['callback'];
        $fc_tool_ref['icon'] = isset($available_callbacks[$fc_cb_ref]['icon'])
            ? $available_callbacks[$fc_cb_ref]['icon']
            : 'admin-generic';
    }
    unset($fc_tool_ref);

    // Count of ACTIVE tools — drives the AI Tools sidebar nav badge, mirroring
    // $total_actions for Trigger Phrases. A tool in the list = active (plan
    // d450a7), so the badge shows the same number the list pane shows (plan 5f7409).
    $total_tools = count(array_filter($fc_tools, function ($t) {
        return !empty($t['enabled']);
    }));

    // Brave-key dependency check (plan 183856): Web Search + Image Search run on
    // the Brave Search API. If either is enabled as a tool but no brave_api_key is
    // configured, surface a graceful "key not set" notice so the admin isn't met
    // with a silently no-firing tool.
    $fc_brave_missing = false;
    $brave_key = isset($fc_options['brave_api_key']) ? trim($fc_options['brave_api_key']) : '';
    if ($brave_key === '') {
        foreach ($fc_tools as $fc_t) {
            if (!empty($fc_t['enabled']) && isset($fc_t['requires_key']) && $fc_t['requires_key'] === 'brave_api_key') {
                $fc_brave_missing = true;
                break;
            }
        }
    }

    // Prepare page data
    $page_data = array(
        'total_actions' => $total_actions,
        'enabled_actions' => $enabled_actions,
        'disabled_actions' => $disabled_actions,
        'action_types_count' => $action_types_count,
        'action_type_distribution' => $action_type_distribution,
        'available_callbacks' => $available_callbacks,
        'callback_groups' => $callback_groups,
        // AI Tools section
        'active_tab'        => $active_tab,
        'fc_enabled'        => MxChat_Tool_Registry::is_enabled(),
        'total_tools'       => $total_tools,
        'fc_tools'          => $fc_tools,
        'fc_current_model'  => $fc_current_model,
        'fc_model_capable'  => $fc_model_capable,
        'fc_brave_missing'  => $fc_brave_missing,
        'fc_saved'          => isset($_GET['mxchat_fc_saved']),
    );

    // Include and render the new template
    require_once plugin_dir_path(__FILE__) . 'admin-actions-page.php';
    mxchat_render_actions_page($this, $page_data);
}

/**
 * LEGACY HTML - Preserved below for reference, to be removed in future update
 */
function mxchat_actions_page_legacy_html() {
    // This function is deprecated and no longer used
    // The new template is in includes/admin-actions-page.php
    ?>
    <div class="wrap mxchat-wrapper">
        <!-- Hero Section -->
        <div class="mxchat-hero">
            <h1 class="mxchat-main-title">
                <span class="mxchat-gradient-text">Actions</span> Manager
            </h1>
            <p class="mxchat-hero-subtitle">
                <?php esc_html_e('Create and manage custom actions to enhance your chatbot\'s capabilities.', 'mxchat'); ?>
            </p>
        </div>

        <!-- Actions Header with Search and Filter -->
        <div class="mxchat-actions-header">
            <div class="mxchat-actions-filters">
                <form method="get" class="mxchat-search-form">
                    <input type="hidden" name="page" value="mxchat-actions">
                    <div class="mxchat-search-group">
                        <span class="dashicons dashicons-search"></span>
                        <input type="text" name="s" class="mxchat-search-input"
                               placeholder="<?php esc_attr_e('Search Actions', 'mxchat'); ?>"
                               value="<?php echo esc_attr($search_term); ?>">
                    </div>
                    <select name="callback_filter" class="mxchat-action-filter">
                        <option value=""><?php esc_html_e('All Action Types', 'mxchat'); ?></option>
                        <?php foreach ($available_callbacks as $function => $callback_data) :
                            $label = $callback_data['label']; ?>
                            <option value="<?php echo esc_attr($function); ?>"
                                    <?php selected($callback_filter, $function); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="mxchat-button-secondary">
                        <?php esc_html_e('Filter', 'mxchat'); ?>
                    </button>
                </form>
            </div>
            <div class="mxchat-actions-controls">
                <button type="button" id="mxchat-add-action-btn" class="mxchat-button-primary">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php esc_html_e('Add New Action', 'mxchat'); ?>
                </button>
            </div>
        </div>

        <!-- Actions Grid Layout - All actions in a single grid -->
        <div class="mxchat-actions-grid">
            <div class="mxchat-cards-container">
                <?php if (!empty($actions)) : ?>
                    <?php foreach ($actions as $action) :
                        $callback_function = $action->callback_function;
                        $callback_label = isset($available_callbacks[$callback_function]['label'])
                            ? $available_callbacks[$callback_function]['label']
                            : $callback_function;
                        $threshold_value = isset($action->similarity_threshold)
                            ? round($action->similarity_threshold * 100)
                            : 85;

                        // Check if this is a form action
                        $is_form_action = strpos($action->intent_label, 'Form ') === 0;

                        // Get action status (enabled/disabled) - default to true if column doesn't exist
                        $is_enabled = isset($action->enabled) ? (bool)$action->enabled : true;
                        
                        // Get enabled bots for display
                        $enabled_bots = [];
                        if (isset($action->enabled_bots) && !empty($action->enabled_bots)) {
                            $enabled_bots = json_decode($action->enabled_bots, true);
                            if (!is_array($enabled_bots)) {
                                $enabled_bots = ['default'];
                            }
                        } else {
                            $enabled_bots = ['default']; // Backward compatibility
                        }
                    ?>
                        <div class="mxchat-action-card <?php echo $is_form_action ? 'mxchat-form-action' : ''; ?>">
                            <div class="mxchat-card-header">
                                <div class="mxchat-card-title"><?php echo esc_html($action->intent_label); ?></div>
                                <div class="mxchat-card-toggle">
                                    <label class="mxchat-switch">
                                        <input type="checkbox" class="mxchat-action-toggle"
                                               data-action-id="<?php echo esc_attr($action->id); ?>"
                                               <?php checked($is_enabled); ?>>
                                        <span class="mxchat-slider round"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="mxchat-card-body">
                                <div class="mxchat-card-description">
                                    <strong><?php esc_html_e('Type:', 'mxchat'); ?></strong>
                                    <?php echo esc_html($callback_label); ?>
                                </div>

                                <div class="mxchat-card-phrases">
                                    <strong><?php esc_html_e('Trigger phrases:', 'mxchat'); ?></strong>
                                    <div class="mxchat-phrases-preview">
                                        <?php
                                        // Check if the helper function exists, otherwise use a simple substring
                                        if (method_exists($this, 'get_trimmed_phrases')) {
                                            echo esc_html($this->get_trimmed_phrases($action->phrases));
                                        } else {
                                            echo esc_html(strlen($action->phrases) > 100 ?
                                                substr($action->phrases, 0, 97) . '...' :
                                                $action->phrases);
                                        }
                                        ?>
                                    </div>
                                </div>

                                <div class="mxchat-threshold-control">
                                    <div class="mxchat-threshold-label">
                                        <?php esc_html_e('Similarity Threshold:', 'mxchat'); ?>
                                        <span class="mxchat-threshold-value"><?php echo esc_html($threshold_value); ?>%</span>
                                    </div>
                                </div>
                                
                                <!-- Bot Availability Display (Read-only) -->
                                <div class="mxchat-card-bots">
                                    <strong><?php esc_html_e('Assigned bots:', 'mxchat'); ?></strong>
                                    <div class="mxchat-bot-badges">
                                        <?php
                                        foreach ($enabled_bots as $bot_id) {
                                            if ($bot_id === 'default') {
                                                echo '<span class="mxchat-bot-badge">' . esc_html__('Default Bot', 'mxchat') . '</span>';
                                            } else {
                                                // Try to get bot name from multi-bot manager
                                                if (class_exists('MxChat_Multi_Bot_Core_Manager')) {
                                                    $multi_bot_manager = MxChat_Multi_Bot_Core_Manager::get_instance();
                                                    $available_bots = $multi_bot_manager->get_available_bots();
                                                    $bot_name = isset($available_bots[$bot_id]) ? $available_bots[$bot_id] : $bot_id;
                                                    echo '<span class="mxchat-bot-badge">' . esc_html($bot_name) . '</span>';
                                                } else {
                                                    echo '<span class="mxchat-bot-badge">' . esc_html($bot_id) . '</span>';
                                                }
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>

                            <div class="mxchat-card-footer">
                                <?php
                                // Check if it's a form action
                                $is_form_action = preg_match('/Form (\d+)/', $action->intent_label, $form_matches);

                                // Check if it's a recommendation flow action
                                $is_flow_action = preg_match('/Recommendation Flow (\d+)/', $action->intent_label, $flow_matches);

                                if ($is_form_action) {
                                    $form_id = isset($form_matches[1]) ? $form_matches[1] : '';
                                ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=mxchat-forms&action=edit&form_id=' . $form_id)); ?>"
                                       class="mxchat-button-primary">
                                        <span class="dashicons dashicons-feedback"></span>
                                        <?php esc_html_e('Edit Form', 'mxchat'); ?>
                                    </a>
                                <?php } elseif ($is_flow_action) {
                                ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=mxchat-smart-recommender')); ?>"
                                       class="mxchat-button-primary">
                                        <span class="dashicons dashicons-list-view"></span>
                                        <?php esc_html_e('Manage Flows', 'mxchat'); ?>
                                    </a>
                                <?php } else { ?>
                                    <button type="button"
                                            class="mxchat-button-secondary mxchat-edit-button"
                                            data-action-id="<?php echo esc_attr($action->id); ?>"
                                            data-phrases="<?php echo esc_attr($action->phrases); ?>"
                                            data-label="<?php echo esc_attr($action->intent_label); ?>"
                                            data-threshold="<?php echo esc_attr(round($action->similarity_threshold * 100)); ?>"
                                            data-callback-function="<?php echo esc_attr($action->callback_function); ?>"
                                            data-enabled-bots="<?php echo esc_attr(json_encode($enabled_bots)); ?>">
                                        <span class="dashicons dashicons-edit"></span>
                                        <?php esc_html_e('Edit', 'mxchat'); ?>
                                    </button>
                                    <form method="post"
                                          action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                          class="mxchat-delete-form"
                                          onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to delete this action?', 'mxchat'); ?>');">
                                        <?php wp_nonce_field('mxchat_delete_intent_nonce'); ?>
                                        <input type="hidden" name="action" value="mxchat_delete_intent">
                                        <input type="hidden" name="intent_id" value="<?php echo esc_attr($action->id); ?>">
                                        <button type="submit" class="mxchat-button-text mxchat-delete-button">
                                            <span class="dashicons dashicons-trash"></span>
                                            <?php esc_html_e('Delete', 'mxchat'); ?>
                                        </button>
                                    </form>
                                <?php } ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <!-- If no actions found -->
                    <div class="mxchat-no-actions">
                        <div class="mxchat-empty-state">
                            <span class="dashicons dashicons-format-chat"></span>
                            <h2><?php esc_html_e('No actions found', 'mxchat'); ?></h2>
                            <p><?php esc_html_e('Get started by creating your first action to enhance your chatbot.', 'mxchat'); ?></p>
                            <button type="button" id="mxchat-create-first-action" class="mxchat-button-primary">
                                <?php esc_html_e('Create Your First Action', 'mxchat'); ?>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($total_pages > 1) : ?>
            <div class="mxchat-pagination">
                <?php
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo; Previous', 'mxchat'),
                    'next_text' => __('Next &raquo;', 'mxchat'),
                    'total' => $total_pages,
                    'current' => $page
                ));
                ?>
            </div>
        <?php endif; ?>

<!-- Add/Edit Action Modal with Step-Based Approach -->
<!-- Complete Modal HTML with Defined Groups Variable -->
<div id="mxchat-action-modal" class="mxchat-modal" style="display: none;">
    <div class="mxchat-modal-content">
        <span class="mxchat-modal-close">&times;</span>

        <form id="mxchat-action-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <!-- Dynamic nonce field -->
            <div id="action-nonce-container">
                <?php wp_nonce_field('mxchat_add_intent_nonce', 'add_intent_nonce'); ?>
            </div>
            <input type="hidden" name="action" id="form_action_type" value="mxchat_add_intent">
            <input type="hidden" name="intent_id" id="edit_action_id" value="">
            <input type="hidden" name="callback_function" id="callback_function" value="">

            <!-- Step 1: Action Type Selection -->
            <div id="mxchat-action-step-1" class="mxchat-action-step active">
                <div class="mxchat-step-indicator">
                    <div class="mxchat-step-number">1</div>
                    <div class="mxchat-step-title"><?php esc_html_e('Select Action Type', 'mxchat'); ?></div>
                </div>

                <div id="mxchat-action-type-selector" class="mxchat-action-type-selector">
                    <div class="mxchat-action-type-search">
                        <span class="dashicons dashicons-search"></span>
                        <input type="text" id="action-type-search" placeholder="<?php esc_attr_e('Search action types...', 'mxchat'); ?>" class="mxchat-action-type-search-input">
                    </div>

                    <?php
                    // Get the callbacks - IMPORTANT: Define the $groups variable here
                    $groups = $this->mxchat_get_available_callbacks(true, true);
                    ?>

                    <div class="mxchat-action-type-categories">
                        <button type="button" class="mxchat-category-button active" data-category="all"><?php esc_html_e('All', 'mxchat'); ?></button>
                        <?php
                        // Get unique categories from the defined groups
                        foreach ($groups as $group_label => $group_callbacks) :
                            $category_slug = sanitize_title($group_label);
                        ?>
                            <button type="button" class="mxchat-category-button" data-category="<?php echo esc_attr($category_slug); ?>"><?php echo esc_html($group_label); ?></button>
                        <?php endforeach; ?>
                    </div>

                    <div class="mxchat-action-types-grid">
                        <?php
                        // Generate action cards from available callbacks
                        foreach ($groups as $group_label => $group_callbacks) :
                            $category_slug = sanitize_title($group_label);

                            foreach ($group_callbacks as $function => $data) :
                                $label = $data['label'];
                                $icon = isset($data['icon']) ? $data['icon'] : 'admin-generic';
                                $description = isset($data['description']) ? $data['description'] : '';
                                $is_addon = isset($data['addon']) && $data['addon'] !== false;
                                $addon_name = isset($data['addon_name']) ? $data['addon_name'] : '';
                                $is_installed = isset($data['installed']) ? $data['installed'] : true;
                                $is_promo = !empty($data['addon_promo']) && !$is_installed;

                                // Determine card status and styling
                                $card_class = 'mxchat-action-type-card';
                                $icon_class = 'mxchat-action-type-icon';
                                $status_badge = '';

                                if ($is_promo) {
                                    // Promotional card — not selectable, just informational
                                    $card_class .= ' not-installed mxchat-promo-card';
                                    $status_badge = '<span class="mxchat-addon-badge">' . esc_html__('Add-on Required', 'mxchat') . '</span>';
                                } elseif ($is_addon && !$is_installed) {
                                    // Add-on not installed
                                    $card_class .= ' not-installed';
                                    $status_badge .= '<span class="mxchat-addon-badge">' . esc_html__('Add-on Required', 'mxchat') . '</span>';
                                }

                                // Default description if none provided
                                if (empty($description)) {
                                    $description = sprintf(
                                        esc_html__('Use the %s action in your chatbot', 'mxchat'),
                                        $label
                                    );
                                }
                                ?>
                                <div class="<?php echo esc_attr($card_class); ?>"
                                     data-category="<?php echo esc_attr($category_slug); ?>"
                                     <?php if (!$is_promo) : ?>
                                     data-value="<?php echo esc_attr($function); ?>"
                                     data-label="<?php echo esc_attr($label); ?>"
                                     <?php endif; ?>
                                     data-pro="false"
                                     data-addon="<?php echo esc_attr($is_addon ? $data['addon'] : ''); ?>"
                                     data-installed="<?php echo $is_installed ? 'true' : 'false'; ?>"
                                     <?php if ($is_promo) : ?>data-promo="true"<?php endif; ?>>
                                    <div class="<?php echo esc_attr($icon_class); ?>">
                                        <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
                                    </div>
                                    <div class="mxchat-action-type-info">
                                        <h4><?php echo esc_html($label); ?></h4>
                                        <p><?php echo esc_html($description); ?></p>
                                        <?php if (!empty($status_badge)) : ?>
                                            <?php echo $status_badge; ?>
                                        <?php endif; ?>

                                        <?php if ($is_promo || ($is_addon && !$is_installed)) : ?>
                                            <div class="mxchat-addon-info">
                                                <?php echo esc_html(sprintf(
                                                    __('Requires %s', 'mxchat'),
                                                    $addon_name
                                                )); ?>
                                                — <a href="https://mxchat.ai/" target="_blank"><?php esc_html_e('Get Add-on', 'mxchat'); ?></a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach;
                        endforeach; ?>
                    </div>
                </div>

                <div class="mxchat-modal-actions">
                    <button type="button" class="mxchat-button-secondary mxchat-modal-cancel">
                        <?php esc_html_e('Cancel', 'mxchat'); ?>
                    </button>
                </div>
            </div>

            <!-- Step 2: Action Configuration -->
            <div id="mxchat-action-step-2" class="mxchat-action-step">
                <div class="mxchat-step-indicator">
                    <div class="mxchat-step-number">2</div>
                    <div class="mxchat-step-title"><?php esc_html_e('Configure Action', 'mxchat'); ?></div>
                </div>

                <div class="mxchat-selected-action">
                    <button type="button" class="mxchat-back-button" id="mxchat-back-to-step-1">
                        <span class="dashicons dashicons-arrow-left-alt"></span>
                        <?php esc_html_e('Back to Action Types', 'mxchat'); ?>
                    </button>
                    <div class="mxchat-selected-action-info">
                        <div id="selected-action-icon" class="mxchat-action-type-icon">
                            <span class="dashicons dashicons-admin-generic"></span>
                        </div>
                        <div class="mxchat-selected-action-details">
                            <h3 id="selected-action-title"><?php esc_html_e('Selected Action', 'mxchat'); ?></h3>
                            <p id="selected-action-description"><?php esc_html_e('Configure this action for your chatbot', 'mxchat'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mxchat-form-group">
                    <label for="intent_label">
                        <?php esc_html_e('Action Label (For your reference only)', 'mxchat'); ?>
                    </label>
                    <input name="intent_label" type="text" id="intent_label" required
                           class="mxchat-intent-input"
                           placeholder="<?php esc_attr_e('Example: Newsletter Signup', 'mxchat'); ?>">
                </div>

                <div class="mxchat-form-group">
                    <label for="phrases">
                        <?php esc_html_e('Trigger Phrases (comma-separated)', 'mxchat'); ?>
                    </label>
                    <textarea name="phrases" id="action_phrases" rows="5" required
                            class="mxchat-intent-textarea"
                            placeholder="<?php esc_attr_e('Example: sign me up, subscribe me, I want to join, add me to the newsletter', 'mxchat'); ?>"></textarea>
                </div>

                <div class="mxchat-form-group">
                    <label for="similarity_threshold">
                        <?php esc_html_e('Similarity Threshold', 'mxchat'); ?>
                        <span class="mxchat-threshold-value-display">85%</span>
                    </label>
                    <div class="mxchat-slider-group modal-slider">
                        <input type="range"
                               name="similarity_threshold"
                               id="similarity_threshold"
                               min="10"
                               max="95"
                               value="85"
                               class="mxchat-intent-slider"
                               oninput="document.querySelector('.mxchat-threshold-value-display').textContent = this.value + '%'">
                    </div>
                    <div class="mxchat-threshold-hint">
                        <?php esc_html_e('Lower values (10-30) make the action trigger more easily. Higher values (70-95) require more exact matches.', 'mxchat'); ?>
                    </div>
                </div>

                <!-- Bot Selection Section -->
                <div class="mxchat-form-group">
                    <label for="enabled_bots">
                        <?php esc_html_e('Which bots should we enable this action for?', 'mxchat'); ?>
                    </label>
                    <div class="mxchat-bot-selector">
                        <div class="mxchat-bot-option">
                            <label class="mxchat-checkbox-label">
                                <input type="checkbox" 
                                       name="enabled_bots[]" 
                                       value="default" 
                                       id="bot_default" 
                                       checked="checked">
                                <span class="mxchat-checkmark"></span>
                                <?php esc_html_e('Default Bot', 'mxchat'); ?>
                            </label>
                        </div>
                        
                        <?php if (class_exists('MxChat_Multi_Bot_Core_Manager')) : 
                            $multi_bot_manager = MxChat_Multi_Bot_Core_Manager::get_instance();
                            $available_bots = $multi_bot_manager->get_available_bots();
                            
                            foreach ($available_bots as $bot_id => $bot_name) :
                                if ($bot_id === 'default') continue; // Skip default, already shown above
                        ?>
                            <div class="mxchat-bot-option">
                                <label class="mxchat-checkbox-label">
                                    <input type="checkbox" 
                                           name="enabled_bots[]" 
                                           value="<?php echo esc_attr($bot_id); ?>" 
                                           id="bot_<?php echo esc_attr($bot_id); ?>">
                                    <span class="mxchat-checkmark"></span>
                                    <?php echo esc_html($bot_name); ?>
                                </label>
                            </div>
                        <?php 
                            endforeach;
                        endif; ?>
                    </div>
                </div>

                <div class="mxchat-modal-actions">
                    <button type="button" class="mxchat-button-secondary mxchat-modal-cancel">
                        <?php esc_html_e('Cancel', 'mxchat'); ?>
                    </button>
                    <button type="submit" class="mxchat-button-primary" id="mxchat-save-action-btn">
                        <?php esc_html_e('Save Action', 'mxchat'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="mxchat-action-loading" class="mxchat-action-loading" style="display: none;">
    <div class="mxchat-action-loading-spinner"></div>
    <div class="mxchat-action-loading-text">
        <?php esc_html_e('Saving action, please wait...', 'mxchat'); ?>
    </div>
</div>
    </div><!-- .mxchat-wrapper -->
    <?php
}
private function get_trimmed_phrases($phrases, $max_length = 100) {
    if (strlen($phrases) <= $max_length) {
        return $phrases;
    }

    $trimmed = substr($phrases, 0, $max_length);
    $last_comma = strrpos($trimmed, ',');

    if ($last_comma !== false) {
        $trimmed = substr($trimmed, 0, $last_comma);
    }

    return $trimmed . '...';
}
public function mxchat_add_enabled_column_to_intents() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_intents';

    // Check if the column already exists
    $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'enabled'");

    if (empty($columns)) {
        // Add the column with default value of 1 (enabled)
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1");
    }
}

public function mxchat_handle_delete_intent() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__('Unauthorized user', 'mxchat') );
    }

    check_admin_referer('mxchat_delete_intent_nonce');

    if (isset($_POST['intent_id'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mxchat_intents';
        $intent_id = intval($_POST['intent_id']);

        $wpdb->delete($table_name, ['id' => $intent_id], ['%d']);
    }

    wp_safe_redirect(admin_url('admin.php?page=mxchat-actions'));
    exit;
}
public function mxchat_handle_edit_intent() {
    // Security checks (nonce and permissions)
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized user', 'mxchat'));
    }
    check_admin_referer('mxchat_edit_intent');

    // Get POST data
    $intent_id = isset($_POST['intent_id']) ? absint($_POST['intent_id']) : 0;
    $intent_label = isset($_POST['intent_label']) ? sanitize_text_field($_POST['intent_label']) : '';
    $phrases_input = isset($_POST['phrases']) ? sanitize_textarea_field($_POST['phrases']) : '';
    $threshold_percentage = isset($_POST['similarity_threshold']) ? intval($_POST['similarity_threshold']) : 85;
    $similarity_threshold = min(95, max(10, $threshold_percentage)) / 100; // Convert to 0.10–0.95

    //  Handle enabled_bots
    $enabled_bots = isset($_POST['enabled_bots']) ? $_POST['enabled_bots'] : array('default');
    $enabled_bots = array_map('sanitize_text_field', $enabled_bots);
    
    // Ensure default is always included for backward compatibility
    if (!in_array('default', $enabled_bots)) {
        $enabled_bots[] = 'default';
    }
    
    $enabled_bots_json = json_encode($enabled_bots);

    // Validate inputs
    if (!$intent_id || empty($intent_label) || empty($phrases_input)) {
        $this->handle_embedding_error(__('Invalid input. Please ensure all fields are filled out.', 'mxchat'));
        return;
    }

    $phrases_array = array_map('sanitize_text_field', array_filter(array_map('trim', explode(',', $phrases_input))));
    if (empty($phrases_array)) {
        $this->handle_embedding_error(__('Please enter at least one valid phrase.', 'mxchat'));
        return;
    }

    // Generate embeddings with improved error handling
    $vectors = [];
    $failed_phrases = [];

    foreach ($phrases_array as $phrase) {
        $embedding_vector = $this->mxchat_generate_embedding($phrase, $this->options['api_key']);
        if (is_array($embedding_vector)) {
            $vectors[] = $embedding_vector;
        } else {
            $failed_phrases[] = $phrase;
        }
    }

    if (!empty($failed_phrases)) {
        $this->handle_embedding_error(
            sprintf(
                __('Error generating embeddings for phrases: %s. Check your embedding API.', 'mxchat'),
                implode(', ', $failed_phrases)
            )
        );
        return;
    }

    if (empty($vectors)) {
        $this->handle_embedding_error(__('No valid embeddings generated. Please check your phrases.', 'mxchat'));
        return;
    }

    $combined_vector = $this->mxchat_average_vectors($vectors);
    $serialized_vector = maybe_serialize($combined_vector);

    // Update the database
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_intents';

    $result = $wpdb->update(
        $table_name,
        array(
            'intent_label' => $intent_label,
            'phrases' => implode(', ', $phrases_array),
            'embedding_vector' => $serialized_vector,
            'similarity_threshold' => $similarity_threshold,
            'enabled_bots' => $enabled_bots_json,  //  Include enabled_bots in update
        ),
        array('id' => $intent_id),
        array('%s', '%s', '%s', '%f', '%s'), // Format: string, string, string, float, string
        array('%d')                          // Where format: integer
    );

    if (false === $result) {
        $this->handle_embedding_error(__('Failed to update action in database.', 'mxchat'));
        return;
    }

    // Set success message and redirect
    set_transient('mxchat_admin_notice_success', __('Intent updated successfully!', 'mxchat'), 60);

    $redirect_url = add_query_arg(
        array(
            'page' => 'mxchat-actions'
        ),
        admin_url('admin.php')
    );
    wp_safe_redirect($redirect_url);
    exit;
}
public function mxchat_handle_add_intent() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized user', 'mxchat'));
    }
    check_admin_referer('mxchat_add_intent_nonce');
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_intents';
    
    // Sanitize and get form data
    $intent_label = isset($_POST['intent_label']) ? sanitize_text_field($_POST['intent_label']) : '';
    $phrases_input = isset($_POST['phrases']) ? sanitize_textarea_field($_POST['phrases']) : '';
    $callback_function = isset($_POST['callback_function']) ? sanitize_text_field($_POST['callback_function']) : '';
    
    // Get similarity threshold from form (convert percentage to decimal)
    $similarity_threshold = isset($_POST['similarity_threshold']) ? floatval($_POST['similarity_threshold']) / 100 : 0.85;
    
    //  Handle enabled_bots
    $enabled_bots = isset($_POST['enabled_bots']) ? $_POST['enabled_bots'] : array('default');
    $enabled_bots = array_map('sanitize_text_field', $enabled_bots);
    
    // Ensure default is always included for backward compatibility with existing actions
    if (!in_array('default', $enabled_bots)) {
        $enabled_bots[] = 'default';
    }
    
    $enabled_bots_json = json_encode($enabled_bots);
    
    // Validate required fields
    if (empty($intent_label) || empty($callback_function) || empty($phrases_input)) {
        $this->handle_embedding_error(__('Invalid input. Please ensure all fields are filled out.', 'mxchat'));
        return;
    }
    
    // Validate callback function
    $available_callbacks = $this->mxchat_get_available_callbacks();
    if (!array_key_exists($callback_function, $available_callbacks)) {
        $this->handle_embedding_error(__('Invalid callback function selected.', 'mxchat'));
        return;
    }
    
    // Check if this is an add-on promotional placeholder (not a real action)
    if (!empty($available_callbacks[$callback_function]['addon_promo'])) {
        $addon_name = isset($available_callbacks[$callback_function]['addon_name']) ? $available_callbacks[$callback_function]['addon_name'] : __('an add-on', 'mxchat');
        $this->handle_embedding_error(sprintf(
            __('This action requires the %s to be installed and activated.', 'mxchat'),
            $addon_name
        ));
        return;
    }
    
    // Process phrases
    $phrases_array = array_map('sanitize_text_field', array_filter(array_map('trim', explode(',', $phrases_input))));
    if (empty($phrases_array)) {
        $this->handle_embedding_error(__('Please enter at least one valid phrase.', 'mxchat'));
        return;
    }
    
    // Generate embeddings with improved error handling
    $vectors = [];
    $failed_phrases = [];
    foreach ($phrases_array as $phrase) {
        $embedding_vector = $this->mxchat_generate_embedding($phrase, $this->options['api_key']);
        if (is_array($embedding_vector)) {
            $vectors[] = $embedding_vector;
        } else {
            $failed_phrases[] = $phrase;
        }
    }
    
    // Check for embedding failures
    if (!empty($failed_phrases)) {
        $this->handle_embedding_error(
            sprintf(
                __('Error generating embeddings for phrases: %s. Check your embedding API.', 'mxchat'),
                implode(', ', $failed_phrases)
            )
        );
        return;
    }
    
    if (empty($vectors)) {
        $this->handle_embedding_error(__('No valid embeddings generated. Please check your phrases.', 'mxchat'));
        return;
    }
    
    // Create combined vector and insert into database
    $combined_vector = $this->mxchat_average_vectors($vectors);
    $serialized_vector = maybe_serialize($combined_vector);
    
    $result = $wpdb->insert($table_name, [
        'intent_label'         => $intent_label,
        'phrases'              => implode(', ', $phrases_array),
        'embedding_vector'     => $serialized_vector,
        'callback_function'    => $callback_function,
        'similarity_threshold' => $similarity_threshold,
        'enabled_bots'         => $enabled_bots_json,  // NEW field
    ]);
    
    if ($result === false) {
        $this->handle_embedding_error(__('Database error: ', 'mxchat') . $wpdb->last_error);
        return;
    }
    
    // Set success message and redirect
    set_transient('mxchat_admin_notice_success', __('New intent added successfully!', 'mxchat'), 60);
    wp_safe_redirect(admin_url('admin.php?page=mxchat-actions'));
    exit;
}




private function handle_embedding_error($message, $redirect = true) {
    // Store the error message in the existing transient
    set_transient('mxchat_admin_notice_error', $message, 60);

    if ($redirect) {
        // Redirect back to the actions page
        $redirect_url = add_query_arg(
            array(
                'page' => 'mxchat-actions'
            ),
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect_url);
        exit;
    }
}

/**
 * AJAX handler to fetch actions list for the new split-panel UI
 */
public function mxchat_fetch_actions_list() {
    check_ajax_referer('mxchat_actions_nonce', 'security');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized', 'mxchat'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_intents';

    $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    $per_page = isset($_POST['per_page']) ? min(100, max(1, intval($_POST['per_page']))) : 50;
    $offset = ($page - 1) * $per_page;
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $callback_filter = isset($_POST['callback_filter']) ? sanitize_text_field($_POST['callback_filter']) : '';
    $sort_order = isset($_POST['sort_order']) && $_POST['sort_order'] === 'asc' ? 'ASC' : 'DESC';

    // Build WHERE clause
    $where = '1=1';
    $params = array();

    if ($search) {
        $search_like = '%' . $wpdb->esc_like($search) . '%';
        $where .= ' AND (intent_label LIKE %s OR phrases LIKE %s)';
        $params[] = $search_like;
        $params[] = $search_like;
    }

    if ($callback_filter) {
        $where .= ' AND callback_function = %s';
        $params[] = $callback_filter;
    }

    // Get total count
    $count_query = "SELECT COUNT(*) FROM $table_name WHERE $where";
    if (!empty($params)) {
        $count_query = $wpdb->prepare($count_query, $params);
    }
    $total_actions = $wpdb->get_var($count_query);

    // Get actions
    $query = "SELECT * FROM $table_name WHERE $where ORDER BY id $sort_order LIMIT %d OFFSET %d";
    $all_params = array_merge($params, array($per_page, $offset));
    $actions = $wpdb->get_results($wpdb->prepare($query, $all_params));

    // Get available callbacks for labels/icons
    $available_callbacks = $this->mxchat_get_available_callbacks();

    // Prefetch individual phrase counts for all fetched actions
    $phrases_table = $wpdb->prefix . 'mxchat_intent_phrases';
    $phrase_counts = array();
    if ($wpdb->get_var("SHOW TABLES LIKE '$phrases_table'") === $phrases_table) {
        $action_ids = wp_list_pluck($actions, 'id');
        if (!empty($action_ids)) {
            $id_placeholders = implode(',', array_fill(0, count($action_ids), '%d'));
            $count_results = $wpdb->get_results($wpdb->prepare(
                "SELECT intent_id, COUNT(*) as cnt FROM $phrases_table WHERE intent_id IN ($id_placeholders) GROUP BY intent_id",
                $action_ids
            ));
            foreach ($count_results as $row) {
                $phrase_counts[$row->intent_id] = intval($row->cnt);
            }
        }
    }

    // Format actions for response
    $formatted_actions = array();
    foreach ($actions as $action) {
        $callback_data = isset($available_callbacks[$action->callback_function])
            ? $available_callbacks[$action->callback_function]
            : array('label' => $action->callback_function, 'icon' => 'admin-generic');

        $enabled_bots = json_decode($action->enabled_bots, true);
        if (!is_array($enabled_bots)) {
            $enabled_bots = array('default');
        }

        $formatted_actions[] = array(
            'id' => intval($action->id),
            'label' => $action->intent_label,
            'phrases' => $action->phrases,
            'callback_function' => $action->callback_function,
            'callback_label' => $callback_data['label'],
            'icon' => isset($callback_data['icon']) ? $callback_data['icon'] : 'admin-generic',
            'threshold' => round($action->similarity_threshold * 100),
            'enabled' => (bool) $action->enabled,
            'enabled_bots' => $enabled_bots,
            'has_legacy_vector' => !empty($action->embedding_vector),
            'individual_phrase_count' => isset($phrase_counts[$action->id]) ? $phrase_counts[$action->id] : 0,
        );
    }

    $total_pages = ceil($total_actions / $per_page);
    $showing_start = $total_actions > 0 ? $offset + 1 : 0;
    $showing_end = min($offset + $per_page, $total_actions);

    wp_send_json_success(array(
        'actions' => $formatted_actions,
        'page' => $page,
        'per_page' => $per_page,
        'total_actions' => intval($total_actions),
        'total_pages' => $total_pages,
        'showing_start' => $showing_start,
        'showing_end' => $showing_end,
    ));
}

/**
 * AJAX handler to toggle action enabled status
 */
public function mxchat_toggle_action_status() {
    check_ajax_referer('mxchat_actions_nonce', 'security');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized', 'mxchat'));
    }

    $action_id = isset($_POST['action_id']) ? intval($_POST['action_id']) : 0;
    $enabled = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;

    if (!$action_id) {
        wp_send_json_error(__('Invalid action ID', 'mxchat'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_intents';

    $result = $wpdb->update(
        $table_name,
        array('enabled' => $enabled ? 1 : 0),
        array('id' => $action_id),
        array('%d'),
        array('%d')
    );

    if ($result === false) {
        wp_send_json_error(__('Failed to update action status', 'mxchat'));
    }

    wp_send_json_success(array('enabled' => (bool) $enabled));
}

/**
 * AJAX handler to bulk delete actions
 */
public function mxchat_bulk_delete_actions() {
    check_ajax_referer('mxchat_delete_intent_nonce', 'security');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized', 'mxchat'));
    }

    $action_ids = isset($_POST['action_ids']) ? array_map('intval', (array) $_POST['action_ids']) : array();

    if (empty($action_ids)) {
        wp_send_json_error(__('No actions selected', 'mxchat'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_intents';

    $placeholders = implode(',', array_fill(0, count($action_ids), '%d'));
    $query = $wpdb->prepare("DELETE FROM $table_name WHERE id IN ($placeholders)", $action_ids);
    $result = $wpdb->query($query);

    if ($result === false) {
        wp_send_json_error(__('Failed to delete actions', 'mxchat'));
    }

    // Also delete individual phrases for these intents
    $phrases_table = $wpdb->prefix . 'mxchat_intent_phrases';
    if ($wpdb->get_var("SHOW TABLES LIKE '$phrases_table'") === $phrases_table) {
        $wpdb->query($wpdb->prepare("DELETE FROM $phrases_table WHERE intent_id IN ($placeholders)", $action_ids));
    }

    wp_send_json_success(array('deleted' => $result));
}

/**
 * AJAX handler to add a new intent/action
 */
public function mxchat_add_intent_ajax() {
    check_ajax_referer('mxchat_add_intent_nonce', 'security');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized', 'mxchat'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_intents';

    // Sanitize input
    $intent_label = isset($_POST['intent_label']) ? sanitize_text_field($_POST['intent_label']) : '';
    $phrases_input = isset($_POST['phrases']) ? sanitize_textarea_field($_POST['phrases']) : '';
    $callback_function = isset($_POST['callback_function']) ? sanitize_text_field($_POST['callback_function']) : '';
    $similarity_threshold = isset($_POST['similarity_threshold']) ? floatval($_POST['similarity_threshold']) / 100 : 0.85;
    $enabled_bots = isset($_POST['enabled_bots']) ? array_map('sanitize_text_field', (array) $_POST['enabled_bots']) : array('default');

    // Validate
    // Check if using new individual phrases mode or legacy mode
    $individual_phrases = isset($_POST['individual_phrases']) ? array_filter(array_map('sanitize_text_field', (array) $_POST['individual_phrases'])) : array();
    $use_individual = !empty($individual_phrases);

    // Validate required fields (phrases not required when using individual mode)
    if (empty($intent_label) || empty($callback_function)) {
        wp_send_json_error(__('Please fill in all required fields.', 'mxchat'));
    }
    if (!$use_individual && empty($phrases_input)) {
        wp_send_json_error(__('Please fill in all required fields.', 'mxchat'));
    }

    // Ensure default bot is included
    if (!in_array('default', $enabled_bots)) {
        $enabled_bots[] = 'default';
    }
    $enabled_bots_json = json_encode($enabled_bots);

    if ($use_individual) {
        // New mode: individual phrases each get their own vector
        // Insert the intent row with empty legacy fields
        $result = $wpdb->insert(
            $table_name,
            array(
                'intent_label' => $intent_label,
                'phrases' => '',
                'embedding_vector' => '',
                'similarity_threshold' => $similarity_threshold,
                'callback_function' => $callback_function,
                'enabled' => 1,
                'enabled_bots' => $enabled_bots_json,
            )
        );

        if ($result === false) {
            wp_send_json_error(__('Failed to add action to database.', 'mxchat'));
        }

        $intent_id = $wpdb->insert_id;

        // Insert each phrase individually with its own embedding
        $phrases_table = $wpdb->prefix . 'mxchat_intent_phrases';
        $failed_phrases = array();
        foreach ($individual_phrases as $phrase) {
            $phrase = trim($phrase);
            if (empty($phrase)) continue;

            $embedding_vector = $this->mxchat_generate_embedding($phrase);
            if (is_wp_error($embedding_vector)) {
                $failed_phrases[] = $phrase;
                continue;
            }

            $wpdb->insert(
                $phrases_table,
                array(
                    'intent_id' => $intent_id,
                    'phrase' => $phrase,
                    'embedding_vector' => maybe_serialize($embedding_vector),
                )
            );
        }

        $response = array('id' => $intent_id);
        if (!empty($failed_phrases)) {
            $response['failed_phrases'] = $failed_phrases;
        }
        wp_send_json_success($response);

    } else {
        // Legacy mode: combine all phrases into one embedding (backwards compatible)
        $phrases_array = array_filter(array_map('trim', preg_split('/[\n,]+/', $phrases_input)));
        if (empty($phrases_array)) {
            wp_send_json_error(__('Please provide at least one trigger phrase.', 'mxchat'));
        }

        // Generate embedding (combine phrases into single string for embedding)
        $embedding_vector = $this->mxchat_generate_embedding(implode(' ', $phrases_array));
        if (is_wp_error($embedding_vector)) {
            // Fallback: store without embedding
            $serialized_vector = null;
        } else {
            $serialized_vector = maybe_serialize($embedding_vector);
        }

        // Insert
        $result = $wpdb->insert(
            $table_name,
            array(
                'intent_label' => $intent_label,
                'phrases' => implode(', ', $phrases_array),
                'embedding_vector' => $serialized_vector,
                'similarity_threshold' => $similarity_threshold,
                'callback_function' => $callback_function,
                'enabled' => 1,
                'enabled_bots' => $enabled_bots_json,
            )
        );

        if ($result === false) {
            wp_send_json_error(__('Failed to add action to database.', 'mxchat'));
        }

        wp_send_json_success(array('id' => $wpdb->insert_id));
    }
}

/**
 * AJAX handler to edit an existing intent/action
 */
public function mxchat_edit_intent_ajax() {
    check_ajax_referer('mxchat_edit_intent', 'security');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized', 'mxchat'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_intents';

    // Sanitize input
    $intent_id = isset($_POST['intent_id']) ? intval($_POST['intent_id']) : 0;
    $intent_label = isset($_POST['intent_label']) ? sanitize_text_field($_POST['intent_label']) : '';
    $phrases_input = isset($_POST['phrases']) ? sanitize_textarea_field($_POST['phrases']) : '';
    $callback_function = isset($_POST['callback_function']) ? sanitize_text_field($_POST['callback_function']) : '';
    $similarity_threshold = isset($_POST['similarity_threshold']) ? floatval($_POST['similarity_threshold']) / 100 : 0.85;
    $enabled_bots = isset($_POST['enabled_bots']) ? array_map('sanitize_text_field', (array) $_POST['enabled_bots']) : array('default');

    // Validate
    if (!$intent_id || empty($intent_label)) {
        wp_send_json_error(__('Please fill in all required fields.', 'mxchat'));
    }

    // Check if phrases are managed individually (empty phrases_input means individual mode)
    $uses_individual_phrases = empty($phrases_input);

    if ($uses_individual_phrases) {
        // Individual phrase mode: only update non-phrase fields, skip embedding regeneration
        $update_data = array(
            'intent_label' => $intent_label,
            'similarity_threshold' => $similarity_threshold,
            'callback_function' => $callback_function,
            'enabled_bots' => json_encode($enabled_bots),
        );
    } else {
        // Legacy mode: process phrases and regenerate embedding (backwards compatible for add-ons)
        $phrases_array = array_filter(array_map('trim', preg_split('/[\n,]+/', $phrases_input)));
        if (empty($phrases_array)) {
            wp_send_json_error(__('Please provide at least one trigger phrase.', 'mxchat'));
        }

        // Generate new embedding (combine phrases into single string for embedding)
        $embedding_vector = $this->mxchat_generate_embedding(implode(' ', $phrases_array));
        if (is_wp_error($embedding_vector)) {
            // Keep existing embedding
            $update_data = array(
                'intent_label' => $intent_label,
                'phrases' => implode(', ', $phrases_array),
                'similarity_threshold' => $similarity_threshold,
                'callback_function' => $callback_function,
                'enabled_bots' => json_encode($enabled_bots),
            );
        } else {
            $serialized_vector = maybe_serialize($embedding_vector);
            $update_data = array(
                'intent_label' => $intent_label,
                'phrases' => implode(', ', $phrases_array),
                'embedding_vector' => $serialized_vector,
                'similarity_threshold' => $similarity_threshold,
                'callback_function' => $callback_function,
                'enabled_bots' => json_encode($enabled_bots),
            );
        }
    }

    // Ensure default bot is included
    if (!in_array('default', $enabled_bots)) {
        $enabled_bots[] = 'default';
    }
    $update_data['enabled_bots'] = json_encode($enabled_bots);

    $result = $wpdb->update(
        $table_name,
        $update_data,
        array('id' => $intent_id),
        null,
        array('%d')
    );

    if ($result === false) {
        wp_send_json_error(__('Failed to update action.', 'mxchat'));
    }

    wp_send_json_success(array('updated' => true));
}

/**
 * AJAX handler to delete a single intent/action
 */
public function mxchat_delete_intent_ajax() {
    check_ajax_referer('mxchat_delete_intent_nonce', 'security');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized', 'mxchat'));
    }

    $intent_id = isset($_POST['intent_id']) ? intval($_POST['intent_id']) : 0;

    if (!$intent_id) {
        wp_send_json_error(__('Invalid action ID', 'mxchat'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_intents';

    $result = $wpdb->delete($table_name, array('id' => $intent_id), array('%d'));

    if ($result === false) {
        wp_send_json_error(__('Failed to delete action', 'mxchat'));
    }

    // Also delete individual phrases for this intent
    $phrases_table = $wpdb->prefix . 'mxchat_intent_phrases';
    if ($wpdb->get_var("SHOW TABLES LIKE '$phrases_table'") === $phrases_table) {
        $wpdb->delete($phrases_table, array('intent_id' => $intent_id), array('%d'));
    }

    wp_send_json_success(array('deleted' => true));
}

/**
 * AJAX handler to add a single phrase with its own embedding to an intent
 */
public function mxchat_add_phrase_ajax() {
    check_ajax_referer('mxchat_add_phrase_nonce', 'security');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized', 'mxchat'));
    }

    $intent_id = isset($_POST['intent_id']) ? intval($_POST['intent_id']) : 0;
    $phrase = isset($_POST['phrase']) ? sanitize_text_field($_POST['phrase']) : '';

    if (!$intent_id || empty($phrase)) {
        wp_send_json_error(__('Please provide an intent ID and phrase.', 'mxchat'));
    }

    // Verify the intent exists
    global $wpdb;
    $intents_table = $wpdb->prefix . 'mxchat_intents';
    $intent = $wpdb->get_row($wpdb->prepare("SELECT id FROM $intents_table WHERE id = %d", $intent_id));
    if (!$intent) {
        wp_send_json_error(__('Action not found.', 'mxchat'));
    }

    // Generate embedding for this single phrase
    $embedding_vector = $this->mxchat_generate_embedding($phrase);
    if (is_wp_error($embedding_vector)) {
        wp_send_json_error(__('Failed to generate embedding: ', 'mxchat') . $embedding_vector->get_error_message());
    }

    $phrases_table = $wpdb->prefix . 'mxchat_intent_phrases';
    $result = $wpdb->insert(
        $phrases_table,
        array(
            'intent_id' => $intent_id,
            'phrase' => $phrase,
            'embedding_vector' => maybe_serialize($embedding_vector),
        )
    );

    if ($result === false) {
        wp_send_json_error(__('Failed to add phrase.', 'mxchat'));
    }

    wp_send_json_success(array('id' => $wpdb->insert_id, 'phrase' => $phrase));
}

/**
 * AJAX handler to delete a single phrase from wp_mxchat_intent_phrases
 */
public function mxchat_delete_phrase_ajax() {
    check_ajax_referer('mxchat_delete_phrase_nonce', 'security');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized', 'mxchat'));
    }

    $phrase_id = isset($_POST['phrase_id']) ? intval($_POST['phrase_id']) : 0;
    if (!$phrase_id) {
        wp_send_json_error(__('Invalid phrase ID.', 'mxchat'));
    }

    global $wpdb;
    $phrases_table = $wpdb->prefix . 'mxchat_intent_phrases';
    $result = $wpdb->delete($phrases_table, array('id' => $phrase_id), array('%d'));

    if ($result === false) {
        wp_send_json_error(__('Failed to delete phrase.', 'mxchat'));
    }

    wp_send_json_success(array('deleted' => true));
}

/**
 * AJAX handler to fetch individual phrases for an intent
 */
public function mxchat_get_phrases_ajax() {
    check_ajax_referer('mxchat_get_phrases_nonce', 'security');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized', 'mxchat'));
    }

    $intent_id = isset($_POST['intent_id']) ? intval($_POST['intent_id']) : 0;
    if (!$intent_id) {
        wp_send_json_error(__('Invalid intent ID.', 'mxchat'));
    }

    global $wpdb;
    $phrases_table = $wpdb->prefix . 'mxchat_intent_phrases';

    $phrases = array();
    if ($wpdb->get_var("SHOW TABLES LIKE '$phrases_table'") === $phrases_table) {
        $phrases = $wpdb->get_results($wpdb->prepare(
            "SELECT id, phrase, created_at FROM $phrases_table WHERE intent_id = %d ORDER BY created_at ASC",
            $intent_id
        ));
    }

    wp_send_json_success(array('phrases' => $phrases));
}

/**
 * AJAX handler to clear legacy phrases and embedding from the main intents table
 */
public function mxchat_delete_legacy_phrases_ajax() {
    check_ajax_referer('mxchat_delete_legacy_nonce', 'security');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized', 'mxchat'));
    }

    $intent_id = isset($_POST['intent_id']) ? intval($_POST['intent_id']) : 0;
    if (!$intent_id) {
        wp_send_json_error(__('Invalid intent ID.', 'mxchat'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_intents';

    $result = $wpdb->update(
        $table_name,
        array('phrases' => '', 'embedding_vector' => ''),
        array('id' => $intent_id),
        array('%s', '%s'),
        array('%d')
    );

    if ($result === false) {
        wp_send_json_error(__('Failed to clear legacy phrases.', 'mxchat'));
    }

    wp_send_json_success(array('cleared' => true));
}

/**
 * Enhanced get_available_callbacks function with form action exclusion
 *
 * @param bool $grouped Whether to return callbacks grouped by category
 * @param bool $include_all Whether to include all potential actions (even if add-on not installed)
 * @return array Callbacks data with icons, descriptions and availability status
 */
private function mxchat_get_available_callbacks($grouped = false, $include_all = true) {
    // Load WordPress plugin functions if needed
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // Get active plugins
    $active_plugins = get_option('active_plugins', array());

    // Functions to exclude from the action selector only if Pro is activated
    // If user doesn't have Pro, show these so they can see what they're missing
    $excluded_when_pro_active_functions = array(
        'mxchat_handle_form_collection' // Forms add-on action
    );

    // Always excluded functions (regardless of Pro status)
    $always_excluded_functions = array();

    // Combine exclusion lists based on Pro activation status
    $excluded_functions = $always_excluded_functions;
    if ($this->is_activated) {
        // Only exclude add-on managed functions if Pro is active
        $excluded_functions = array_merge($excluded_functions, $excluded_when_pro_active_functions);
    }

    // Define add-on plugin files and their corresponding action functions
    $addon_plugins = array(
        'mxchat-woo/mxchat-woo.php' => array(
            'functions' => array(
                'mxchat_handle_product_recommendations',
                'mxchat_handle_order_history',
                'mxchat_show_product_card',
                'mxchat_add_to_cart',
                'mxchat_checkout_redirect',
                'mxchat_handle_featured_products'
            ),
            'name' => __('WooCommerce Add-on', 'mxchat'),
            'pro_required' => true
        ),
        'mxchat-perplexity/mxchat-perplexity.php' => array(
            'functions' => array('mxchat_perplexity_research'),
            'name' => __('Perplexity Add-on', 'mxchat'),
            'pro_required' => true
        ),
        'mxchat-forms/mxchat-forms.php' => array(
            'functions' => array('mxchat_handle_form_collection'),
            'name' => __('Forms Add-on', 'mxchat'),
            'pro_required' => true
        ),
        // Add other add-ons and their functions here
    );

    // Get the functions that are provided by active add-ons
    $addon_provided_functions = array();
    $addon_function_mapping = array(); // Maps functions to their add-on info

    // Check which add-ons are active
    foreach ($addon_plugins as $plugin_file => $addon_info) {
        $is_active = in_array($plugin_file, $active_plugins);

        // For each function in this addon
        foreach ($addon_info['functions'] as $function) {
            // Consider a function installed only if:
            // 1. The add-on is active AND
            // 2. Either it doesn't require Pro OR Pro is activated
            $is_installed = $is_active && (!$addon_info['pro_required'] || $this->is_activated);

            // If the add-on is installed, mark this function as provided by an add-on
            if ($is_installed) {
                $addon_provided_functions[] = $function;
            }

            // Store addon info for this function regardless of installation status
            $addon_function_mapping[$function] = array(
                'addon' => basename(dirname($plugin_file)),
                'addon_name' => $addon_info['name'],
                'pro_required' => $addon_info['pro_required'],
                'is_active' => $is_active,
                'is_installed' => $is_installed
            );
        }
    }

    // Core callbacks - always available in the base plugin
    $core_callbacks = array(
        'mxchat_handle_email_capture' => array(
            'label'       => __('Loops Email Capture', 'mxchat'),
            'pro_only'    => false,
            'group'       => __('Customer Engagement', 'mxchat'),
            'icon'        => 'email-alt',
            'description' => __('Collect visitor emails for your mailing list in Loops', 'mxchat'),
            'addon'       => false, // Not from an add-on
            'installed'   => true   // Always installed with base plugin
        ),
        'mxchat_handle_search_request' => array(
            'label'       => __('Brave Web Search', 'mxchat'),
            'pro_only'    => false,
            'group'       => __('Search Features', 'mxchat'),
            'icon'        => 'search',
            'description' => __('Let users search the web directly from the chat (requires a Brave Search API key)', 'mxchat'),
            'addon'       => false,
            'installed'   => true
        ),
        'mxchat_handle_image_search_request' => array(
            'label'       => __('Brave Image Search', 'mxchat'),
            'pro_only'    => false,
            'group'       => __('Search Features', 'mxchat'),
            'icon'        => 'format-image',
            'description' => __('Search and display images in the chat conversation (requires a Brave Search API key)', 'mxchat'),
            'addon'       => false,
            'installed'   => true
        ),
        // Pro core features - check is_activated property
        'mxchat_generate_image' => array(
            'label'       => __('Generate Image (OpenAI)', 'mxchat'),
            'pro_only'    => false,
            'group'       => __('Other Features', 'mxchat'),
            'icon'        => 'art',
            'description' => __('Create images with GPT Image from OpenAI (requires OpenAI API key)', 'mxchat'),
            'addon'       => false,
            'installed'   => true
        ),
        'mxchat_generate_gemini_image' => array(
            'label'       => __('Generate Image (Gemini)', 'mxchat'),
            'pro_only'    => false,
            'group'       => __('Other Features', 'mxchat'),
            'icon'        => 'art',
            'description' => __('Create images with Imagen from Google (requires Gemini API key)', 'mxchat'),
            'addon'       => false,
            'installed'   => true
        ),
        'mxchat_handle_pdf_discussion' => array(
            'label'       => __('Chat with PDF', 'mxchat'),
            'pro_only'    => false,
            'group'       => __('Other Features', 'mxchat'),
            'icon'        => 'media-document',
            'description' => __('Answer questions about uploaded PDF documents', 'mxchat'),
            'addon'       => false,
            'installed'   => true
        ),
        'mxchat_live_agent_handover' => array(
            'label'       => __('Slack Live Agent', 'mxchat'),
            'pro_only'    => false,
            'group'       => __('Customer Engagement', 'mxchat'),
            'icon'        => 'admin-users',
            'description' => __('Transfer conversation to a human support agent on Slack', 'mxchat'),
            'addon'       => false,
            'installed'   => true
        ),
        'mxchat_telegram_live_agent_handover' => array(
            'label'       => __('Telegram Live Agent', 'mxchat'),
            'pro_only'    => false,
            'group'       => __('Customer Engagement', 'mxchat'),
            'icon'        => 'format-chat',
            'description' => __('Transfer conversation to a human support agent on Telegram', 'mxchat'),
            'addon'       => false,
            'installed'   => true
        ),
        'mxchat_handle_switch_to_chatbot_intent' => array(
            'label'       => __('Back to Chatbot', 'mxchat'),
            'pro_only'    => false,
            'group'       => __('Customer Engagement', 'mxchat'),
            'icon'        => 'backup',
            'description' => __('Return from live agent mode to AI chatbot', 'mxchat'),
            'addon'       => false,
            'installed'   => true
        ),
    );

    // Add-on callbacks with placeholders - only include if the add-on is NOT active
    // These are promotional/informational only — not selectable as real actions
    $addon_callbacks = array(
        // WooCommerce Add-on
        'mxchat_handle_product_recommendations' => array(
            'label'       => __('Product Recommendations', 'mxchat'),
            'pro_only'    => false,
            'addon_promo' => true,
            'group'       => __('WooCommerce Features', 'mxchat'),
            'icon'        => 'cart',
            'description' => __('Suggest products based on customer preferences', 'mxchat'),
        ),
        'mxchat_handle_order_history' => array(
            'label'       => __('Order History', 'mxchat'),
            'pro_only'    => false,
            'addon_promo' => true,
            'group'       => __('WooCommerce Features', 'mxchat'),
            'icon'        => 'clipboard',
            'description' => __('Allow customers to check their order status', 'mxchat'),
        ),
        'mxchat_show_product_card' => array(
            'label'       => __('Show Product Card', 'mxchat'),
            'pro_only'    => false,
            'addon_promo' => true,
            'group'       => __('WooCommerce Features', 'mxchat'),
            'icon'        => 'products',
            'description' => __('Display product information in the chat', 'mxchat'),
        ),
        'mxchat_add_to_cart' => array(
            'label'       => __('Add to Cart', 'mxchat'),
            'pro_only'    => false,
            'addon_promo' => true,
            'group'       => __('WooCommerce Features', 'mxchat'),
            'icon'        => 'plus-alt',
            'description' => __('Add products to cart directly from chat', 'mxchat'),
        ),
        'mxchat_checkout_redirect' => array(
            'label'       => __('Proceed to Checkout', 'mxchat'),
            'pro_only'    => false,
            'addon_promo' => true,
            'group'       => __('WooCommerce Features', 'mxchat'),
            'icon'        => 'arrow-right-alt',
            'description' => __('Redirect customer to checkout page', 'mxchat'),
        ),
        'mxchat_handle_featured_products' => array(
            'label'       => __('Featured Products Showcase', 'mxchat'),
            'pro_only'    => false,
            'addon_promo' => true,
            'group'       => __('WooCommerce Features', 'mxchat'),
            'icon'        => 'star-filled',
            'description' => __('Display a curated selection of products with an AI-generated message', 'mxchat'),
        ),

        // Perplexity Add-on
        'mxchat_perplexity_research' => array(
            'label'       => __('Perplexity Research', 'mxchat'),
            'pro_only'    => false,
            'addon_promo' => true,
            'group'       => __('Search Features', 'mxchat'),
            'icon'        => 'book-alt',
            'description' => __('Allows the chatbot to search the web for accurate, up-to-date answers', 'mxchat'),
        ),

        // Forms Add-on
        'mxchat_handle_form_collection' => array(
            'label'       => __('Form Collection', 'mxchat'),
            'pro_only'    => false,
            'addon_promo' => true,
            'group'       => __('Form Features', 'mxchat'),
            'icon'        => 'feedback',
            'description' => __('Collect user information through custom forms in chat', 'mxchat'),
        ),
    );

    // Enhance add-on callbacks with installation status and addon info
    foreach ($addon_callbacks as $function => $data) {
        if (isset($addon_function_mapping[$function])) {
            $addon_info = $addon_function_mapping[$function];

            $addon_callbacks[$function]['addon'] = $addon_info['addon'];
            $addon_callbacks[$function]['addon_name'] = $addon_info['addon_name'];
            $addon_callbacks[$function]['installed'] = $addon_info['is_installed'];

            // Set pro_only based on add-on configuration
            $addon_callbacks[$function]['pro_only'] = $addon_info['pro_required'];
        } else {
            $addon_callbacks[$function]['addon'] = 'unknown';
            $addon_callbacks[$function]['addon_name'] = __('Unknown Add-on', 'mxchat');
            $addon_callbacks[$function]['installed'] = false;
        }
    }

    // Initialize callbacks with core features
    $callbacks = $core_callbacks;

    // Get callbacks from active add-ons
    $active_addon_callbacks = apply_filters('mxchat_available_callbacks', array());

    // Add placeholder callbacks only for add-ons that aren't active
    if ($include_all) {
        foreach ($addon_callbacks as $function => $data) {
            // Skip placeholders for functions provided by active add-ons
            if (in_array($function, $addon_provided_functions)) {
                continue;
            }

            // Skip excluded functions
            if (in_array($function, $excluded_functions)) {
                continue;
            }

            // Add the placeholder
            $callbacks[$function] = $data;
        }
    }

    // Add callbacks from active add-ons (will override placeholders)
    foreach ($active_addon_callbacks as $function => $data) {
        // Skip excluded functions
        if (in_array($function, $excluded_functions)) {
            continue;
        }

        // Always include callbacks from add-ons
        $callbacks[$function] = $data;

        // Ensure they have the proper add-on info
        if (isset($addon_function_mapping[$function])) {
            $addon_info = $addon_function_mapping[$function];
            $callbacks[$function]['addon'] = $addon_info['addon'];
            $callbacks[$function]['addon_name'] = $addon_info['addon_name'];
            $callbacks[$function]['installed'] = $addon_info['is_installed'];
            $callbacks[$function]['pro_only'] = $addon_info['pro_required'];
        }
    }

    // Just before returning callbacks, sort them to prioritize free features
    if (!$grouped) {
        // Create temporary arrays for sorting
        $free_callbacks = array();
        $pro_callbacks = array();

        // Split callbacks into free and pro
        foreach ($callbacks as $key => $data) {
            if (isset($data['pro_only']) && $data['pro_only']) {
                $pro_callbacks[$key] = $data;
            } else {
                $free_callbacks[$key] = $data;
            }
        }

        // Merge with free callbacks first
        $callbacks = array_merge($free_callbacks, $pro_callbacks);
    }

    // Return grouped structure if requested
    if ($grouped) {
        $grouped_callbacks = array();
        foreach ($callbacks as $key => $data) {
            $group_label = isset($data['group']) ? $data['group'] : __('Other Features', 'mxchat');

            // Ensure we carry forward all the new fields in grouped mode
            $callback_data = array(
                'label'       => $data['label'],
                'pro_only'    => isset($data['pro_only']) ? $data['pro_only'] : false,
                'icon'        => isset($data['icon']) ? $data['icon'] : 'admin-generic',
                'description' => isset($data['description']) ? $data['description'] : __('Custom action for your chatbot', 'mxchat'),
                'addon'       => isset($data['addon']) ? $data['addon'] : false,
                'addon_name'  => isset($data['addon_name']) ? $data['addon_name'] : '',
                'installed'   => isset($data['installed']) ? $data['installed'] : true
            );

            $grouped_callbacks[$group_label][$key] = $callback_data;
        }

        // Sort within each group to prioritize free features
        foreach ($grouped_callbacks as $group => $items) {
            $free_items = array();
            $pro_items = array();

            foreach ($items as $key => $data) {
                if (isset($data['pro_only']) && $data['pro_only']) {
                    $pro_items[$key] = $data;
                } else {
                    $free_items[$key] = $data;
                }
            }

            $grouped_callbacks[$group] = array_merge($free_items, $pro_items);
        }

        return $grouped_callbacks;
    }

    return $callbacks;
}

public function mxchat_page_init() {
    register_setting(
        'mxchat_option_group',
        'mxchat_options',
        array($this, 'mxchat_sanitize')
    );

    register_setting(
        'mxchat_option_group',
        'mxchat_similarity_threshold',
        array(
            'type' => 'number',
            'sanitize_callback' => function($value) {
                $value = absint($value);
                return min(max($value, 20), 95);
            },
            'default' => 80,
        )
    );

    // Chatbot Settings Section
    add_settings_section(
        'mxchat_chatbot_section',
        esc_html__('Chatbot Settings', 'mxchat'),
        null,
        'mxchat-chatbot'
    );

    // API Keys Settings Section
    add_settings_section(
        'mxchat_api_keys_section',
        esc_html__('API Keys', 'mxchat'),
        array($this, 'mxchat_api_keys_section_callback'),
        'mxchat-api-keys'
    );

    // OpenAI API Key
    add_settings_field(
        'api_key',
        esc_html__('OpenAI API Key', 'mxchat'),
        array($this, 'api_key_callback'),
        'mxchat-api-keys',
        'mxchat_api_keys_section'
    );

    // X.AI API Key
    add_settings_field(
        'xai_api_key',
        esc_html__('X.AI API Key', 'mxchat'),
        array($this, 'xai_api_key_callback'),
        'mxchat-api-keys',
        'mxchat_api_keys_section'
    );

    // Claude API Key
    add_settings_field(
        'claude_api_key',
        esc_html__('Claude API Key', 'mxchat'),
        array($this, 'claude_api_key_callback'),
        'mxchat-api-keys',
        'mxchat_api_keys_section'
    );

    // DeepSeek API Key
    add_settings_field(
        'deepseek_api_key',
        esc_html__('DeepSeek API Key', 'mxchat'),
        array($this, 'deepseek_api_key_callback'),
        'mxchat-api-keys',
        'mxchat_api_keys_section'
    );

    // Google Gemini API Key
    add_settings_field(
        'gemini_api_key',
        esc_html__('Google Gemini API Key', 'mxchat'),
        array($this, 'gemini_api_key_callback'),
        'mxchat-api-keys',
        'mxchat_api_keys_section'
    );

    // Voyage AI API Key
    add_settings_field(
        'voyage_api_key',
        esc_html__('Voyage AI API Key', 'mxchat'),
        array($this, 'voyage_api_key_callback'),
        'mxchat-api-keys',
        'mxchat_api_keys_section'
    );

    // OpenRouter API Key
    add_settings_field(
        'openrouter_api_key',
        esc_html__('OpenRouter API Key', 'mxchat'),
        array($this, 'openrouter_api_key_callback'),
        'mxchat-api-keys',
        'mxchat_api_keys_section'
    );

    // Custom (OpenAI-compatible) Provider — for Ollama, LM Studio, vLLM, llama.cpp, Azure OpenAI, etc.
    add_settings_field(
        'custom_provider',
        esc_html__('Custom Provider (OpenAI-compatible)', 'mxchat'),
        array($this, 'custom_provider_callback'),
        'mxchat-api-keys',
        'mxchat_api_keys_section'
    );

    // Loops API Key
    add_settings_field(
        'loops_api_key',
        esc_html__('Loops API Key', 'mxchat'),
        array($this, 'mxchat_loops_api_key_callback'),
        'mxchat-api-keys',
        'mxchat_api_keys_section'
    );

    // Brave Search API Key
    add_settings_field(
        'brave_api_key',
        __('Brave API Key', 'mxchat'),
        array($this, 'mxchat_brave_api_key_callback'),
        'mxchat-api-keys',
        'mxchat_api_keys_section'
    );

    // Similarity Threshold Slider
    add_settings_field(
        'similarity_threshold', // Field ID
        esc_html__('Similarity Threshold', 'mxchat'), // Field title
        array($this, 'mxchat_similarity_threshold_callback'), // Callback function
        'mxchat-chatbot', // Page
        'mxchat_chatbot_section' // Section
    );

    // RAG Sources Limit Slider
    add_settings_field(
        'rag_sources_limit', // Field ID
        esc_html__('RAG Sources Limit', 'mxchat'), // Field title
        array($this, 'mxchat_rag_sources_limit_callback'), // Callback function
        'mxchat-chatbot', // Page
        'mxchat_chatbot_section' // Section
    );

    // RAG Chunks Limit Slider
    add_settings_field(
        'rag_chunks_limit', // Field ID
        esc_html__('RAG Chunks Limit', 'mxchat'), // Field title
        array($this, 'mxchat_rag_chunks_limit_callback'), // Callback function
        'mxchat-chatbot', // Page
        'mxchat_chatbot_section' // Section
    );

    add_settings_field(
        'append_to_body',
        esc_html__('Auto-Display Chatbot', 'mxchat'),
        array($this, 'mxchat_append_to_body_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'contextual_awareness_toggle',
        esc_html__('Contextual Awareness', 'mxchat'),
        array($this, 'mxchat_contextual_awareness_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'citation_links_toggle',
        esc_html__('Citation Links', 'mxchat'),
        array($this, 'mxchat_citation_links_toggle_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    // Satisfaction rating toggle (plan-a5b006).
    add_settings_field(
        'satisfaction_rating_enabled',
        esc_html__('Satisfaction Rating Prompt', 'mxchat'),
        array($this, 'mxchat_satisfaction_rating_toggle_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    // Satisfaction rating customization (plan-141a12, plan-29caac):
    // the 5 customization fields are now inline-rendered inside
    // mxchat_satisfaction_rating_toggle_callback's sub-options wrapper.

    add_settings_field(
        'enable_streaming_toggle',
        esc_html__('Enable Streaming', 'mxchat'),
        array($this, 'enable_streaming_toggle_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section', // Same section as your working toggle
        array(
            'class' => 'mxchat-setting-row streaming-setting',
            'style' => 'display: none;' // Hidden by default, shown when OpenAI/Claude selected
        )
    );


    add_settings_field(
        'model',
        esc_html__('Chat Model', 'mxchat'),
        array($this, 'mxchat_model_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'embedding_model',
        esc_html__('Embedding Model', 'mxchat'),
        array($this, 'embedding_model_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'system_prompt_instructions',
        esc_html__('AI Instructions (Behavior)', 'mxchat'),
        array($this, 'system_prompt_instructions_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );


    add_settings_field(
        'top_bar_title',
        esc_html__('Top Bar Title', 'mxchat'),
        array($this, 'mxchat_top_bar_title_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'ai_agent_text',
        esc_html__('AI Agent Text', 'mxchat'),
        array($this, 'mxchat_ai_agent_text_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'enable_email_block',
        esc_html__('Require Email To Chat', 'mxchat'),
        array($this, 'enable_email_block_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'email_blocker_header_content',
        esc_html__('Require Email Chat Content', 'mxchat'),
        array($this, 'email_blocker_header_content_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'email_blocker_button_text',
        esc_html__('Require Email Chat Button Text', 'mxchat'),
        [$this, 'email_blocker_button_text_callback'],
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'enable_name_field',
        esc_html__('Require Name Field', 'mxchat'),
        array($this, 'enable_name_field_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'name_field_placeholder',
        esc_html__('Name Field Placeholder', 'mxchat'),
        array($this, 'name_field_placeholder_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'intro_message',
        esc_html__('Introductory Message', 'mxchat'),
        array($this, 'mxchat_intro_message_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'input_copy',
        esc_html__('Input Copy', 'mxchat'),
        array($this, 'mxchat_input_copy_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'pre_chat_message',
        esc_html__('Chat Teaser Pop-up', 'mxchat'),
        array($this, 'mxchat_pre_chat_message_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'privacy_toggle',
        esc_html__('Toggle Privacy Notice', 'mxchat'),
        array($this, 'mxchat_privacy_toggle_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'complianz_toggle',
        esc_html__('Enable Complianz', 'mxchat'),
        array($this, 'mxchat_complianz_toggle_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'link_target_toggle',
        esc_html__('Open Links in a New Tab', 'mxchat'),
        array($this, 'mxchat_link_target_toggle_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'chat_persistence_toggle',
        esc_html__('Enable Chat Persistence', 'mxchat'),
        array($this, 'mxchat_chat_persistence_toggle_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'print_button_enabled',
        esc_html__('Show Download Transcript Button', 'mxchat'),
        array($this, 'mxchat_print_button_toggle_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'reset_chat_enabled',
        esc_html__('Show Start-New-Chat Button', 'mxchat'),
        array($this, 'mxchat_reset_chat_toggle_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'reset_chat_label',
        esc_html__('Start-New-Chat Button Label', 'mxchat'),
        array($this, 'mxchat_reset_chat_label_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'popular_question_1',
        esc_html__('Quick Question 1', 'mxchat'),
        array($this, 'mxchat_popular_question_1_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'popular_question_2',
        esc_html__('Quick Question 2', 'mxchat'),
        array($this, 'mxchat_popular_question_2_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'popular_question_3',
        esc_html__('Quick Question 3', 'mxchat'),
        array($this, 'mxchat_popular_question_3_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    add_settings_field(
        'additional_popular_questions',
        esc_html__('Additional Quick Questions', 'mxchat'),
        array($this, 'mxchat_additional_popular_questions_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );


    add_settings_field(
        'rate_limits',
        __('Rate Limits Settings', 'mxchat'),
        array($this, 'mxchat_rate_limits_callback'),
        'mxchat-chatbot',
        'mxchat_chatbot_section'
    );

    // Loops Settings Section
    add_settings_section(
        'mxchat_loops_section',
        esc_html__('Loops Settings', 'mxchat'),
        null,
        'mxchat-embed'
    );

    // Loops Settings Fields (API Key moved to API Keys tab)
    add_settings_field(
        'loops_mailing_list',
        esc_html__('Loops Mailing List', 'mxchat'),
        array($this, 'mxchat_loops_mailing_list_callback'),
        'mxchat-embed',
        'mxchat_loops_section'
    );

    add_settings_field(
        'triggered_phrase_response',
        esc_html__('Triggered Phrase Response', 'mxchat'),
        array($this, 'mxchat_triggered_phrase_response_callback'),
        'mxchat-embed',
        'mxchat_loops_section'
    );

    add_settings_field(
        'email_capture_response',
        esc_html__('Email Capture Response', 'mxchat'),
        array($this, 'mxchat_email_capture_response_callback'),
        'mxchat-embed',
        'mxchat_loops_section'
    );

    // Brave Search Settings Fields
    add_settings_section(
        'mxchat_brave_section',
        __('Brave Search Settings', 'mxchat'),
        array($this, 'mxchat_brave_section_callback'),
        'mxchat-embed'
    );

    // Brave API Key moved to API Keys tab
    add_settings_field(
        'brave_image_count',
        __('Number of Images to Return', 'mxchat'),
        array($this, 'mxchat_brave_image_count_callback'),
        'mxchat-embed',
        'mxchat_brave_section'
    );

    add_settings_field(
        'brave_safe_search',
        __('Safe Search', 'mxchat'),
        array($this, 'mxchat_brave_safe_search_callback'),
        'mxchat-embed',
        'mxchat_brave_section'
    );

    add_settings_field(
        'brave_news_count',
        __('Number of News Articles', 'mxchat'),
        array($this, 'mxchat_brave_news_count_callback'),
        'mxchat-embed',
        'mxchat_brave_section'
    );

    add_settings_field(
        'brave_country',
        __('Country', 'mxchat'),
        array($this, 'mxchat_brave_country_callback'),
        'mxchat-embed',
        'mxchat_brave_section'
    );

    add_settings_field(
        'brave_language',
        __('Language', 'mxchat'),
        array($this, 'mxchat_brave_language_callback'),
        'mxchat-embed',
        'mxchat_brave_section'
    );

    // Chat with PDF Intent Settings Fields
    add_settings_section(
        'mxchat_pdf_intent_section',
        __('Toolbar Settings & Intents', 'mxchat'),
        array($this, 'mxchat_pdf_intent_section_callback'),
        'mxchat-embed'
    );

    add_settings_field(
        'chat_toolbar_toggle',
        __('Show Chat Toolbar', 'mxchat'),
        array($this, 'mxchat_chat_toolbar_toggle_callback'),
        'mxchat-embed',
        'mxchat_pdf_intent_section'
    );

    // PDF Upload Button Toggle
    add_settings_field(
        'show_pdf_upload_button',
        __('Show PDF Upload Button', 'mxchat'),
        array($this, 'mxchat_show_pdf_upload_button_callback'),
        'mxchat-embed',
        'mxchat_pdf_intent_section'
    );

    // Word Upload Button Toggle
    add_settings_field(
        'show_word_upload_button',
        __('Show Word Upload Button', 'mxchat'),
        array($this, 'mxchat_show_word_upload_button_callback'),
        'mxchat-embed',
        'mxchat_pdf_intent_section'
    );

    add_settings_field(
        'pdf_intent_trigger_text',
        __('Intent Trigger Text', 'mxchat'),
        array($this, 'mxchat_pdf_intent_trigger_text_callback'),
        'mxchat-embed',
        'mxchat_pdf_intent_section'
    );

    add_settings_field(
        'pdf_intent_success_text',
        __('Success Text', 'mxchat'),
        array($this, 'mxchat_pdf_intent_success_text_callback'),
        'mxchat-embed',
        'mxchat_pdf_intent_section'
    );

    add_settings_field(
        'pdf_intent_error_text',
        __('Error Text', 'mxchat'),
        array($this, 'mxchat_pdf_intent_error_text_callback'),
        'mxchat-embed',
        'mxchat_pdf_intent_section'
    );

    // Add PDF Maximum Pages Field
    add_settings_field(
        'pdf_max_pages',
        __('Maximum Document Pages', 'mxchat'),
        array($this, 'mxchat_pdf_max_pages_callback'),
        'mxchat-embed',
        'mxchat_pdf_intent_section'
    );

    // Live Agent Settings Fields
    add_settings_section(
        'mxchat_live_agent_section',
        __('Live Agent Settings', 'mxchat'),
        array($this, 'mxchat_live_agent_section_callback'),
        'mxchat-embed'
    );

    // Live Agent Status Fields (add at top of live agent settings)
    add_settings_field(
        'live_agent_status',
        __('Live Agent Status', 'mxchat'),
        array($this, 'mxchat_live_agent_status_callback'),
        'mxchat-embed',
        'mxchat_live_agent_section'
    );

    add_settings_field(
        'live_agent_notification_message',
        __('Notification Message', 'mxchat'),
        array($this, 'mxchat_live_agent_notification_message_callback'),
        'mxchat-embed',
        'mxchat_live_agent_section'
    );

    add_settings_field(
        'live_agent_away_message',
        __('Away Message', 'mxchat'),
        array($this, 'mxchat_live_agent_away_message_callback'),
        'mxchat-embed',
        'mxchat_live_agent_section'
    );

    add_settings_field(
        'live_agent_user_ids',
        __('Slack Agent User IDs', 'mxchat'),
        array($this, 'mxchat_live_agent_user_ids_callback'),
        'mxchat-embed',
        'mxchat_live_agent_section'
    );

    add_settings_field(
        'live_agent_webhook_url',
        __('Slack Webhook URL', 'mxchat'),
        array($this, 'mxchat_live_agent_webhook_url_callback'),
        'mxchat-embed',
        'mxchat_live_agent_section'
    );

    add_settings_field(
        'live_agent_secret_key',
        __('Slack Secret Key', 'mxchat'),
        array($this, 'mxchat_live_agent_secret_key_callback'),
        'mxchat-embed',
        'mxchat_live_agent_section'
    );

    // Live Agent Integration Fields
    add_settings_field(
        'live_agent_bot_token',
        __('Slack Bot OAuth Token', 'mxchat'),
        array($this, 'mxchat_live_agent_bot_token_callback'),
        'mxchat-embed',
        'mxchat_live_agent_section'
    );

    // Telegram Integration Section
    add_settings_section(
        'mxchat_telegram_section',
        __('Telegram Settings', 'mxchat'),
        array($this, 'mxchat_telegram_section_callback'),
        'mxchat-embed'
    );

    add_settings_field(
        'telegram_status',
        __('Live Agent Status', 'mxchat'),
        array($this, 'mxchat_telegram_status_callback'),
        'mxchat-embed',
        'mxchat_telegram_section'
    );

    add_settings_field(
        'telegram_notification_message',
        __('Notification Message', 'mxchat'),
        array($this, 'mxchat_telegram_notification_message_callback'),
        'mxchat-embed',
        'mxchat_telegram_section'
    );

    add_settings_field(
        'telegram_away_message',
        __('Away Message', 'mxchat'),
        array($this, 'mxchat_telegram_away_message_callback'),
        'mxchat-embed',
        'mxchat_telegram_section'
    );

    add_settings_field(
        'telegram_bot_token',
        __('Telegram Bot Token', 'mxchat'),
        array($this, 'mxchat_telegram_bot_token_callback'),
        'mxchat-embed',
        'mxchat_telegram_section'
    );

    add_settings_field(
        'telegram_group_id',
        __('Telegram Group ID', 'mxchat'),
        array($this, 'mxchat_telegram_group_id_callback'),
        'mxchat-embed',
        'mxchat_telegram_section'
    );

    add_settings_field(
        'telegram_webhook_secret',
        __('Webhook Secret Token', 'mxchat'),
        array($this, 'mxchat_telegram_webhook_secret_callback'),
        'mxchat-embed',
        'mxchat_telegram_section'
    );

    // General Settings Section
    add_settings_section(
        'mxchat_general_section',
        esc_html__('YouTube Tutorials', 'mxchat'),
        null,
        'mxchat-general'
    );
}

public function mxchat_prompts_page_init() {
        register_setting(
            'mxchat_prompts_options',
            'mxchat_prompts_options',
            array(
                'type' => 'array',
                'description' => __('MXChat Knowledge Base Settings', 'mxchat'),
                'default' => array(
                    'mxchat_auto_sync_posts' => 0,
                    'mxchat_auto_sync_pages' => 0,
                    'mxchat_use_pinecone' => 0,
                    'mxchat_pinecone_api_key' => '',
                    'mxchat_pinecone_environment' => '',
                    'mxchat_pinecone_index' => '',
                    'mxchat_pinecone_host' => '',
                ),
                'sanitize_callback' => array($this, 'sanitize_prompts_options'),
            )
        );

    add_action('admin_notices', array($this, 'sync_settings_notice'));
}

public function mxchat_transcripts_page_init() {
    register_setting(
        'mxchat_transcripts_options',
        'mxchat_transcripts_options',
        array(
            'type' => 'array',
            'description' => __('MXChat Transcripts Notification Settings', 'mxchat'),
            'default' => array(
                'mxchat_enable_notifications' => 0,
                'mxchat_notification_email' => get_option('admin_email'),
                'mxchat_auto_delete_transcripts' => 'never',
            ),
            'sanitize_callback' => array($this, 'sanitize_transcripts_options'),
        )
    );

    add_settings_section(
        'mxchat_transcripts_notification_section',
        esc_html__('Chat Notification Settings', 'mxchat'),
        array($this, 'mxchat_transcripts_notification_section_callback'),
        'mxchat-transcripts'
    );

    add_settings_field(
        'mxchat_enable_notifications',
        esc_html__('Enable Chat Notifications', 'mxchat'),
        array($this, 'mxchat_enable_notifications_callback'),
        'mxchat-transcripts',
        'mxchat_transcripts_notification_section'
    );

    add_settings_field(
        'mxchat_notification_email',
        esc_html__('Notification Email Address', 'mxchat'),
        array($this, 'mxchat_notification_email_callback'),
        'mxchat-transcripts',
        'mxchat_transcripts_notification_section'
    );

    add_settings_field(
        'mxchat_auto_delete_transcripts',
        esc_html__('Auto-Delete Old Transcripts', 'mxchat'),
        array($this, 'mxchat_auto_delete_transcripts_callback'),
        'mxchat-transcripts',
        'mxchat_transcripts_notification_section'
    );

    add_settings_field(
        'mxchat_retention_days',
        esc_html__('Custom Retention (Days)', 'mxchat'),
        array($this, 'mxchat_retention_days_callback'),
        'mxchat-transcripts',
        'mxchat_transcripts_notification_section'
    );

    add_settings_field(
        'mxchat_auto_email_transcript',
        esc_html__('Auto-Email Full Transcript', 'mxchat'),
        array($this, 'mxchat_auto_email_transcript_callback'),
        'mxchat-transcripts',
        'mxchat_transcripts_notification_section'
    );
}


/**
 * Sanitize all prompts options
 *
 * @param array $input The unsanitized options array
 * @return array The sanitized options array
 */
public function sanitize_prompts_options($input) {
    // Log the incoming input.
    //error_log('Sanitizing inputs: ' . print_r($input, true));

    $sanitized = array();

    // Boolean options
    $sanitized['mxchat_auto_sync_posts'] = isset($input['mxchat_auto_sync_posts']) ? 1 : 0;
    $sanitized['mxchat_auto_sync_pages'] = isset($input['mxchat_auto_sync_pages']) ? 1 : 0;
$sanitized['mxchat_use_pinecone'] = !empty($input['mxchat_use_pinecone']) ? 1 : 0;

    // API Key: if less than 32 characters, flag as invalid.
    $api_key = sanitize_text_field($input['mxchat_pinecone_api_key'] ?? '');
    if (!empty($api_key) && strlen($api_key) < 32) {
        add_settings_error(
            'mxchat_prompts_options',
            'invalid_api_key',
            __('The Pinecone API key appears to be invalid. Please check your API key.', 'mxchat')
        );
        $existing_options = get_option('mxchat_prompts_options', array());
        $sanitized['mxchat_pinecone_api_key'] = $existing_options['mxchat_pinecone_api_key'] ?? '';
    } else {
        $sanitized['mxchat_pinecone_api_key'] = $api_key;
    }

    // Environment and Index Name
    $sanitized['mxchat_pinecone_environment'] = sanitize_text_field($input['mxchat_pinecone_environment'] ?? '');
    $sanitized['mxchat_pinecone_index'] = sanitize_text_field($input['mxchat_pinecone_index'] ?? '');

    // Host: Remove protocol and validate format.
    $host = sanitize_text_field($input['mxchat_pinecone_host'] ?? '');
    $host = preg_replace('#^https?://#', '', $host);
    //error_log('Host after removing protocol: ' . $host);
    if (!empty($host)) {
        if (!preg_match('/^[\w-]+\.svc\.[\w-]+\.pinecone\.io$/', $host)) {
            add_settings_error(
                'mxchat_prompts_options',
                'invalid_host',
                __('The Pinecone host appears to be invalid. It should look like "mxchat-vectors-zrmsquq.svc.aped-4627-b74a.pinecone.io"', 'mxchat')
            );
            $existing_options = get_option('mxchat_prompts_options', array());
            $sanitized['mxchat_pinecone_host'] = $existing_options['mxchat_pinecone_host'] ?? '';
        } else {
            $sanitized['mxchat_pinecone_host'] = $host;
        }
    } else {
        $sanitized['mxchat_pinecone_host'] = '';
    }

    //error_log('Final sanitized array: ' . print_r($sanitized, true));

    return $sanitized;
}

public function sync_settings_notice() {
    // Only show notice on our plugin page
    if (!isset($_GET['page']) || $_GET['page'] !== 'mxchat-prompts') {
        return;
    }

    // Check if settings were updated
    if (isset($_GET['settings-updated'])) {

        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Sync settings updated successfully.', 'mxchat'); ?></p>
            <button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php esc_html_e('Dismiss this notice.', 'mxchat'); ?></span></button>
        </div>
        <?php

    }
}
// Add this sanitization function to your class
public function sanitize_sync_setting($input) {
    return (bool)$input ? __('1', 'mxchat') : __('', 'mxchat');
}

public function mxchat_rate_limits_callback() {
    $all_options = get_option('mxchat_options', []);

    // Define available rate limits
    $rate_limits = array('1', '3', '5', '10', '15', '20', '50', '100', 'unlimited');

    // Define available timeframes
    $timeframes = array(
        'hourly' => __('Per Hour', 'mxchat'),
        'daily' => __('Per Day', 'mxchat'),
        'weekly' => __('Per Week', 'mxchat'),
        'monthly' => __('Per Month', 'mxchat')
    );

    // Get all roles plus a "logged_out" pseudo-role
    $roles = wp_roles()->get_names();
    $roles['logged_out'] = __('Logged Out Users', 'mxchat');

    // Start the wrapper
    echo '<div class="pro-feature-wrapper active">';
    echo '<div class="mxchat-rate-limits-container">';

    echo '<p class="description" style="margin-bottom: 20px;">' .
         esc_html__('Set message limits for each user role and customize the experience when users reach those limits. You can use {limit}, {timeframe}, {count}, and {remaining} as placeholders.', 'mxchat') .
         '</p>';

    // Add markdown link documentation
    echo '<div class="notice notice-info inline" style="margin-bottom: 20px; padding: 10px;">';
    echo '<p><strong>' . esc_html__('Markdown Links Supported:', 'mxchat') . '</strong></p>';
    echo '<p>' . esc_html__('You can include clickable links in your custom messages using markdown syntax:', 'mxchat') . '</p>';
    echo '<ul style="margin-left: 20px;">';
    echo '<li><code>[Link text](https://example.com)</code> - Creates a clickable link</li>';
    echo '<li><code>[Visit our pricing](https://example.com/pricing)</code> - Link with custom text</li>';
    echo '<li><code>Plain URLs like https://example.com will also become clickable</code></li>';
    echo '</ul>';
    echo '</div>';

    // Output the controls for each role
    foreach ($roles as $role_id => $role_name) {
        // Get saved options or defaults
        $default_limit = ($role_id === 'logged_out') ? '10' : '100';
        $default_timeframe = 'daily';
        $default_message = __('Rate limit exceeded. Please try again later.', 'mxchat');

        $selected_limit = isset($all_options['rate_limits'][$role_id]['limit'])
            ? $all_options['rate_limits'][$role_id]['limit']
            : $default_limit;

        $selected_timeframe = isset($all_options['rate_limits'][$role_id]['timeframe'])
            ? $all_options['rate_limits'][$role_id]['timeframe']
            : $default_timeframe;

        $custom_message = isset($all_options['rate_limits'][$role_id]['message'])
            ? $all_options['rate_limits'][$role_id]['message']
            : $default_message;

        // Output the row
        echo '<div class="mxchat-rate-limit-row mxchat-autosave-section">';

        // Role label
        echo '<div class="mxchat-rate-limit-role">' . esc_html($role_name) . '</div>';

        // Controls section
        echo '<div class="mxchat-rate-limit-controls-wrapper">';

        // Rate limit and timeframe controls
        echo '<div class="mxchat-rate-limit-controls">';

        // Limit dropdown
        echo '<div>';
        echo '<label for="rate_limits_' . esc_attr($role_id) . '_limit">' . esc_html__('Limit:', 'mxchat') . '</label>';
        echo '<select
                id="rate_limits_' . esc_attr($role_id) . '_limit"
                name="mxchat_options[rate_limits][' . esc_attr($role_id) . '][limit]"
                class="mxchat-autosave-field">';
        foreach ($rate_limits as $limit) {
            echo '<option value="' . esc_attr($limit) . '" ' . selected($selected_limit, $limit, false) . '>' . esc_html($limit) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Timeframe dropdown
        echo '<div>';
        echo '<label for="rate_limits_' . esc_attr($role_id) . '_timeframe">' . esc_html__('Timeframe:', 'mxchat') . '</label>';
        echo '<select
                id="rate_limits_' . esc_attr($role_id) . '_timeframe"
                name="mxchat_options[rate_limits][' . esc_attr($role_id) . '][timeframe]"
                class="mxchat-autosave-field">';
        foreach ($timeframes as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected_timeframe, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '</div>'; // End controls

        // Custom message textarea
        echo '<div class="mxchat-rate-limit-message">';
        echo '<label for="rate_limits_' . esc_attr($role_id) . '_message">' . esc_html__('Custom Message:', 'mxchat') . '</label>';
        echo '<textarea
                id="rate_limits_' . esc_attr($role_id) . '_message"
                name="mxchat_options[rate_limits][' . esc_attr($role_id) . '][message]"
                class="mxchat-autosave-field"
                placeholder="' . esc_attr__('Enter custom message when rate limit is exceeded', 'mxchat') . '">' .
                esc_textarea($custom_message) .
              '</textarea>';
        echo '<p class="description">' .
             esc_html__('Example: Rate limit reached! [Visit our pricing page](https://example.com/pricing) to upgrade.', 'mxchat') .
             '</p>';
        echo '</div>'; // End message

        echo '</div>'; // End controls wrapper

        echo '</div>'; // End row
    }

    echo '</div>'; // End container

    echo '</div>'; // End pro-feature-wrapper
}

private function mxchat_add_option_field($id, $title, $callback = '') {
        add_settings_field(
            $id,
            __($title, 'mxchat'),
            $callback ? array($this, $callback) : array($this, $id . '_callback'),
            'mxchat-max',
            'mxchat_setting_section_id',
            $id === 'model' ? ['label_for' => 'model'] : []
        );
    }

// API Keys Section Callback
public function mxchat_api_keys_section_callback() {
    echo '<p>' . esc_html__('Manage all your API keys in one place. Add the API keys for the services you want to use with your chatbot.', 'mxchat') . '</p>';
}

// OpenAI API Key
public function api_key_callback() {
    $apiKey = isset($this->options['api_key']) ? esc_attr($this->options['api_key']) : '';
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="api-key-wrapper">';
    echo '<input type="text" id="api_key" name="api_key" value="' . $apiKey . '" class="regular-text mxchat-autosave-field mxchat-api-key-field" autocomplete="new-password" data-lpignore="true" data-form-type="other" data-nonce="' . $nonce . '" />';
    echo '<button type="button" id="toggleApiKeyVisibility">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '<p class="description">' . esc_html__('Required for OpenAI GPT models and OpenAI embeddings. Get your API key from OpenAI Platform.', 'mxchat') . '</p>';
    echo '</div>';
}

// X.AI API Key
public function xai_api_key_callback() {
    $xaiApiKey = isset($this->options['xai_api_key']) ? esc_attr($this->options['xai_api_key']) : '';
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="api-key-wrapper">';
    echo '<input type="text" id="xai_api_key" name="xai_api_key" value="' . $xaiApiKey . '" class="regular-text mxchat-autosave-field mxchat-api-key-field" autocomplete="new-password" data-lpignore="true" data-form-type="other" data-nonce="' . $nonce . '" />';
    echo '<button type="button" id="toggleXaiApiKeyVisibility">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '<p class="description">' . esc_html__('Required for X.AI Grok models. Get your API key from X.AI Console.', 'mxchat') . '</p>';
    echo '</div>';
}
// Claude API Key
public function claude_api_key_callback() {
    $claudeApiKey = isset($this->options['claude_api_key']) ? esc_attr($this->options['claude_api_key']) : '';
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="api-key-wrapper">';
    echo '<input type="text" id="claude_api_key" name="claude_api_key" value="' . $claudeApiKey . '" class="regular-text mxchat-autosave-field mxchat-api-key-field" autocomplete="new-password" data-lpignore="true" data-form-type="other" data-nonce="' . $nonce . '" />';
    echo '<button type="button" id="toggleClaudeApiKeyVisibility">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '<p class="description">' . esc_html__('Required for Anthropic Claude models. Get your API key from Anthropic Console.', 'mxchat') . '</p>';
    echo '</div>';
}

// DeepSeek API Key
public function deepseek_api_key_callback() {
    $apiKey = isset($this->options['deepseek_api_key']) ? esc_attr($this->options['deepseek_api_key']) : '';
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="api-key-wrapper">';
    echo '<input type="text" id="deepseek_api_key" name="deepseek_api_key" value="' . $apiKey . '" class="regular-text mxchat-autosave-field mxchat-api-key-field" autocomplete="new-password" data-lpignore="true" data-form-type="other" data-nonce="' . $nonce . '" />';
    echo '<button type="button" id="toggleDeepSeekApiKeyVisibility">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '<p class="description">' . esc_html__('Required for DeepSeek models. Get your API key from DeepSeek Platform.', 'mxchat') . '</p>';
    echo '</div>';
}

// Gemini API Key
public function gemini_api_key_callback() {
    $geminiApiKey = isset($this->options['gemini_api_key']) ? esc_attr($this->options['gemini_api_key']) : '';
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="api-key-wrapper">';
    echo '<input type="text" id="gemini_api_key" name="gemini_api_key" value="' . $geminiApiKey . '" class="regular-text mxchat-autosave-field mxchat-api-key-field" autocomplete="new-password" data-lpignore="true" data-form-type="other" data-nonce="' . $nonce . '" />';
    echo '<button type="button" id="toggleGeminiApiKeyVisibility">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '<p class="description">' . esc_html__('Required for Google Gemini models and embeddings. Get your API key from Google AI Studio.', 'mxchat') . '</p>';
    echo '</div>';
}


// OpenRouter API Key
public function openrouter_api_key_callback() {
    $openrouterApiKey = isset($this->options['openrouter_api_key']) ? esc_attr($this->options['openrouter_api_key']) : '';
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="api-key-wrapper">';
    echo '<input type="text" id="openrouter_api_key" name="openrouter_api_key" value="' . $openrouterApiKey . '" class="regular-text mxchat-autosave-field mxchat-api-key-field" autocomplete="new-password" data-lpignore="true" data-form-type="other" data-nonce="' . $nonce . '" />';
    echo '<button type="button" id="toggleOpenRouterApiKeyVisibility">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '<p class="description">' . esc_html__('Required for OpenRouter models. Get your API key from OpenRouter.ai', 'mxchat') . '</p>';
    echo '</div>';
}

// Custom (OpenAI-compatible) Provider — Ollama, LM Studio, vLLM, llama.cpp, Azure OpenAI, etc.
public function custom_provider_callback() {
    $base_url      = isset($this->options['custom_provider_base_url']) ? esc_attr($this->options['custom_provider_base_url']) : '';
    $api_key       = isset($this->options['custom_provider_api_key']) ? esc_attr($this->options['custom_provider_api_key']) : '';
    $model_name    = isset($this->options['custom_provider_model']) ? esc_attr($this->options['custom_provider_model']) : '';
    $auth_scheme   = isset($this->options['custom_provider_auth_scheme']) ? esc_attr($this->options['custom_provider_auth_scheme']) : 'bearer';
    $api_version   = isset($this->options['custom_provider_api_version']) ? esc_attr($this->options['custom_provider_api_version']) : '';
    $use_embed     = !empty($this->options['custom_provider_for_embeddings']) && $this->options['custom_provider_for_embeddings'] === 'on';
    $use_images    = !empty($this->options['custom_provider_for_images']) && $this->options['custom_provider_for_images'] === 'on';
    $embed_model   = isset($this->options['custom_provider_embedding_model']) ? esc_attr($this->options['custom_provider_embedding_model']) : '';
    $nonce         = wp_create_nonce('mxchat_autosave_nonce');
    $test_nonce    = wp_create_nonce('mxchat_test_custom_provider');

    echo '<style>
        .mxchat-cp { max-width: 680px; }
        .mxchat-cp .mxchat-cp-intro { margin: 0 0 16px; color: #50575e; font-size: 13px; line-height: 1.5; }
        .mxchat-cp .mxchat-cp-row { display: block; margin: 0 0 18px; }
        .mxchat-cp .mxchat-cp-row > label { display: block; font-weight: 600; margin: 0 0 6px; color: #1d2327; font-size: 13px; }
        .mxchat-cp .mxchat-cp-row > input[type="text"],
        .mxchat-cp .mxchat-cp-row > input[type="password"],
        .mxchat-cp .mxchat-cp-row > select { display: block; width: 100%; max-width: 480px; margin: 0; }
        .mxchat-cp .mxchat-cp-row > .description { display: block; margin: 6px 0 0; color: #646970; font-size: 12px; line-height: 1.5; max-width: 480px; }
        .mxchat-cp .mxchat-cp-test { margin-top: 4px; padding-top: 14px; border-top: 1px solid #e5e7eb; }
        .mxchat-cp .mxchat-cp-test .mxchat-cp-test-status { display: inline-block; margin-left: 10px; vertical-align: middle; font-size: 13px; }
        .mxchat-cp .mxchat-cp-azure { margin: 0 0 18px; padding: 12px 14px; background: #f6f7ff; border: 1px solid #dfe1f5; border-left: 3px solid #7873f5; border-radius: 6px; max-width: 480px; }
        .mxchat-cp .mxchat-cp-azure > .mxchat-cp-azure-title { display: block; font-weight: 600; color: #1d2327; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; margin: 0 0 8px; }
        .mxchat-cp .mxchat-cp-azure ol { margin: 0; padding: 0 0 0 18px; color: #50575e; font-size: 12px; line-height: 1.7; }
        .mxchat-cp .mxchat-cp-azure code { background: #eceefb; padding: 1px 5px; border-radius: 3px; font-size: 11px; }
    </style>';

    echo '<div class="api-key-wrapper mxchat-cp">';
    echo '<p class="mxchat-cp-intro">' . esc_html__('Point MxChat at any OpenAI-compatible /v1/chat/completions endpoint: Ollama, LM Studio, vLLM, llama.cpp, LocalAI, Azure OpenAI, etc. Then select "Custom (OpenAI-compatible)" in the model picker.', 'mxchat') . '</p>';

    // Azure OpenAI quick start — consolidates the 4-field Azure recipe in one scannable callout
    // so admins can configure Azure without piecing it together from each field's hint.
    echo '<div class="mxchat-cp-azure">';
    echo '<span class="mxchat-cp-azure-title">' . esc_html__('Azure OpenAI quick start', 'mxchat') . '</span>';
    echo '<ol>';
    echo '<li>' . wp_kses(__('<strong>Base URL</strong> → <code>https://&lt;resource&gt;.openai.azure.com/openai/deployments/&lt;deployment&gt;</code>', 'mxchat'), array('strong' => array(), 'code' => array())) . '</li>';
    echo '<li>' . wp_kses(__('<strong>API Key</strong> → your Azure OpenAI key (required)', 'mxchat'), array('strong' => array(), 'code' => array())) . '</li>';
    echo '<li>' . wp_kses(__('<strong>Auth Scheme</strong> → <code>api-key header (Azure OpenAI)</code>', 'mxchat'), array('strong' => array(), 'code' => array())) . '</li>';
    echo '<li>' . wp_kses(__('<strong>API Version</strong> → required for Azure, e.g. <code>2024-08-01-preview</code>', 'mxchat'), array('strong' => array(), 'code' => array())) . '</li>';
    echo '</ol>';
    echo '</div>';

    echo '<div class="mxchat-cp-row">';
    echo '<label for="custom_provider_base_url">' . esc_html__('Base URL', 'mxchat') . '</label>';
    echo '<input type="text" id="custom_provider_base_url" name="custom_provider_base_url" value="' . $base_url . '" class="regular-text mxchat-autosave-field" placeholder="http://localhost:11434/v1" autocomplete="off" data-lpignore="true" data-form-type="other" data-nonce="' . $nonce . '" />';
    echo '<p class="description">' . esc_html__('Examples: Ollama http://localhost:11434/v1  ·  LM Studio http://localhost:1234/v1  ·  vLLM http://gpu:8000/v1  ·  Azure https://<resource>.openai.azure.com/openai/deployments/<deployment>', 'mxchat') . '</p>';
    echo '</div>';

    echo '<div class="mxchat-cp-row">';
    echo '<label for="custom_provider_api_key">' . esc_html__('API Key (optional)', 'mxchat') . '</label>';
    echo '<input type="password" id="custom_provider_api_key" name="custom_provider_api_key" value="' . $api_key . '" class="regular-text mxchat-autosave-field mxchat-api-key-field" autocomplete="new-password" data-lpignore="true" data-form-type="other" data-nonce="' . $nonce . '" />';
    echo '<p class="description">' . esc_html__('Leave empty for unauthenticated local servers. Required for Azure / vLLM / hosted endpoints.', 'mxchat') . '</p>';
    echo '</div>';

    echo '<div class="mxchat-cp-row">';
    echo '<label for="custom_provider_model">' . esc_html__('Model Name', 'mxchat') . '</label>';
    echo '<input type="text" id="custom_provider_model" name="custom_provider_model" value="' . $model_name . '" class="regular-text mxchat-autosave-field" placeholder="llama3.2" autocomplete="off" data-lpignore="true" data-nonce="' . $nonce . '" />';
    echo '<p class="description">' . esc_html__('The model identifier the upstream server expects (e.g. llama3.2, mistral, gpt-oss). For Azure this is the deployment ID — leave empty if the Base URL already includes /deployments/<deployment>.', 'mxchat') . '</p>';
    echo '</div>';

    echo '<div class="mxchat-cp-row">';
    echo '<label for="custom_provider_auth_scheme">' . esc_html__('Auth Scheme', 'mxchat') . '</label>';
    echo '<select id="custom_provider_auth_scheme" name="custom_provider_auth_scheme" class="mxchat-autosave-field" data-nonce="' . $nonce . '">';
    echo '<option value="bearer"' . selected($auth_scheme, 'bearer', false) . '>' . esc_html__('Authorization: Bearer (OpenAI / Ollama / vLLM / LM Studio)', 'mxchat') . '</option>';
    echo '<option value="api-key"' . selected($auth_scheme, 'api-key', false) . '>' . esc_html__('api-key header (Azure OpenAI)', 'mxchat') . '</option>';
    echo '</select>';
    echo '<p class="description">' . esc_html__('Most OpenAI-compatible servers use Bearer. Azure OpenAI uses the api-key header.', 'mxchat') . '</p>';
    echo '</div>';

    echo '<div class="mxchat-cp-row">';
    echo '<label for="custom_provider_api_version">' . esc_html__('API Version (Azure only)', 'mxchat') . '</label>';
    echo '<input type="text" id="custom_provider_api_version" name="custom_provider_api_version" value="' . $api_version . '" class="regular-text mxchat-autosave-field" placeholder="2024-08-01-preview" autocomplete="off" data-lpignore="true" data-nonce="' . $nonce . '" />';
    echo '<p class="description">' . esc_html__('Appended as ?api-version=... on the request URL. Required for Azure OpenAI; leave empty for non-Azure providers.', 'mxchat') . '</p>';
    echo '</div>';

    // Extended-use checkboxes — opt-in routing of other dispatcher paths through the custom provider.
    echo '<div class="mxchat-cp-row">';
    echo '<label style="font-weight:600; display:block; margin:0 0 6px; color:#1d2327; font-size:13px;">' . esc_html__('Extended routing (opt-in)', 'mxchat') . '</label>';
    echo '<label style="display:block; margin:0 0 6px; font-weight:400;"><input type="checkbox" id="custom_provider_for_embeddings" name="custom_provider_for_embeddings" value="on"' . checked($use_embed, true, false) . ' class="mxchat-autosave-field" data-nonce="' . $nonce . '" /> ' . esc_html__('Use custom provider for embeddings', 'mxchat') . '</label>';
    echo '<label style="display:block; margin:0 0 0; font-weight:400;"><input type="checkbox" id="custom_provider_for_images" name="custom_provider_for_images" value="on"' . checked($use_images, true, false) . ' class="mxchat-autosave-field" data-nonce="' . $nonce . '" /> ' . esc_html__('Use custom provider for image generation', 'mxchat') . '</label>';
    echo '<p class="description">' . esc_html__('When off, embeddings and image generation continue to use OpenAI (current behavior). Turn on only if your endpoint exposes OpenAI-compatible /embeddings or /images/generations routes (e.g. Ollama, vLLM, LocalAI).', 'mxchat') . '</p>';
    echo '</div>';

    echo '<div class="mxchat-cp-row">';
    echo '<label for="custom_provider_embedding_model">' . esc_html__('Custom Embedding Model', 'mxchat') . '</label>';
    echo '<input type="text" id="custom_provider_embedding_model" name="custom_provider_embedding_model" value="' . $embed_model . '" class="regular-text mxchat-autosave-field" placeholder="nomic-embed-text" autocomplete="off" data-lpignore="true" data-nonce="' . $nonce . '" />';
    echo '<p class="description">' . esc_html__('Only used when "Use custom provider for embeddings" is on. The embedding model name is separate from the chat model name above (e.g. Ollama embedding models: nomic-embed-text, mxbai-embed-large). Leave blank to fall back to the chat model name.', 'mxchat') . '</p>';
    echo '</div>';

    echo '<div class="mxchat-cp-test">';
    echo '<button type="button" class="button" id="mxchat-test-custom-provider" data-nonce="' . $test_nonce . '">' . esc_html__('Test Connection', 'mxchat') . '</button>';
    echo '<span id="mxchat-test-custom-provider-result" class="mxchat-cp-test-status"></span>';
    echo '</div>';

    echo '<script>(function(){
        var btn = document.getElementById("mxchat-test-custom-provider");
        if (!btn || btn._wired) { return; } btn._wired = true;
        btn.addEventListener("click", function(){
            var out = document.getElementById("mxchat-test-custom-provider-result");
            out.textContent = "' . esc_js(__('Testing...', 'mxchat')) . '";
            out.style.color = "#646970";
            var fd = new FormData();
            fd.append("action", "mxchat_test_custom_provider");
            fd.append("_wpnonce", btn.getAttribute("data-nonce"));
            fetch(ajaxurl, { method:"POST", credentials:"same-origin", body: fd })
                .then(function(r){ return r.json(); })
                .then(function(j){
                    if (j && j.success) {
                        out.textContent = "✓ " + (j.data && j.data.message ? j.data.message : "' . esc_js(__('OK', 'mxchat')) . '");
                        out.style.color = "#00a32a";
                    } else {
                        out.textContent = "⚠ " + (j && j.data && j.data.message ? j.data.message : "' . esc_js(__('Failed', 'mxchat')) . '");
                        out.style.color = "#d63638";
                    }
                })
                .catch(function(){
                    out.textContent = "⚠ ' . esc_js(__('Request failed', 'mxchat')) . '";
                    out.style.color = "#d63638";
                });
        });
    })();</script>';

    echo '</div>';
}

// Voyage API Key
public function voyage_api_key_callback() {
    $apiKey = isset($this->options['voyage_api_key']) ? esc_attr($this->options['voyage_api_key']) : '';
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="api-key-wrapper">';
    echo '<input type="text" id="voyage_api_key" name="voyage_api_key" value="' . $apiKey . '" class="regular-text mxchat-autosave-field mxchat-api-key-field" autocomplete="new-password" data-lpignore="true" data-form-type="other" data-nonce="' . $nonce . '" />';
    echo '<button type="button" id="toggleVoyageAPIKeyVisibility">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '<p class="description">' . esc_html__('Required for Voyage AI embedding models. Get your API key from Voyage AI.', 'mxchat') . '</p>';
    echo '</div>';
}

public function mxchat_loops_api_key_callback() {
    $loops_api_key = isset($this->options['loops_api_key']) ? esc_attr($this->options['loops_api_key']) : '';
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="api-key-wrapper">';
    echo sprintf(
        '<input type="text" id="loops_api_key" name="loops_api_key" value="%s" class="regular-text mxchat-autosave-field mxchat-api-key-field" autocomplete="new-password" data-lpignore="true" data-form-type="other" data-nonce="%s" />',
        $loops_api_key,
        $nonce
    );
    echo '<button type="button" id="toggleLoopsApiKeyVisibility">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '</div>';
    echo '<p class="description">' . esc_html__('Required for Loops email integration. Get your API key from Loops.so', 'mxchat') . '</p>';
}
public function mxchat_loops_mailing_list_callback() {
    // Add error handling and type checking
    $loops_api_key = '';
    $selected_list = '';
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    // Safely get the API key
    if (isset($this->options['loops_api_key']) && is_string($this->options['loops_api_key'])) {
        $loops_api_key = $this->options['loops_api_key'];
    }

    // Safely get the selected list
    if (isset($this->options['loops_mailing_list']) && is_string($this->options['loops_mailing_list'])) {
        $selected_list = $this->options['loops_mailing_list'];
    }

    if (!empty($loops_api_key)) {
        $lists = $this->mxchat_fetch_loops_mailing_lists($loops_api_key);
        if (is_array($lists) && !empty($lists)) {
            echo '<div class="mxchat-field-wrapper">';
            echo '<select id="loops_mailing_list" name="loops_mailing_list" class="mxchat-autosave-field" data-nonce="' . $nonce . '">';

            // Add a default "Select a list" option
            echo '<option value="" ' . selected($selected_list, '', false) . '>' . esc_html__('Select a list', 'mxchat') . '</option>';

            foreach ($lists as $list) {
                if (is_array($list) && isset($list['id']) && isset($list['name'])) {
                    echo sprintf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($list['id']),
                        selected($selected_list, $list['id'], false),
                        esc_html($list['name'])
                    );
                }
            }
            echo '</select>';
            echo '</div>';
            echo '<p class="description">' . esc_html__('Please select a mailing list to use with Loops.', 'mxchat') . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('No lists found. Please verify your API Key.', 'mxchat') . '</p>';
        }
    } else {
        echo '<p class="description">' . esc_html__('Enter a valid Loops API Key to load mailing lists.', 'mxchat') . '</p>';
    }
}
public function mxchat_triggered_phrase_response_callback() {
    $default_response = __('Would you like to join our mailing list? Please provide your email below.', 'mxchat');
    $triggered_response = isset($this->options['triggered_phrase_response'])
        ? $this->options['triggered_phrase_response']
        : $default_response;
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="mxchat-field-wrapper">';
    echo sprintf(
        '<textarea id="triggered_phrase_response" name="triggered_phrase_response" rows="3" cols="50" class="mxchat-autosave-field" data-nonce="%s">%s</textarea>',
        $nonce,
        esc_textarea($triggered_response)
    );
    echo '</div>';
    echo '<p class="description">' . esc_html__('Enter the instruction for the AI when a trigger keyword is detected. The AI will use this as guidance to naturally ask for the user\'s email in a conversational way.', 'mxchat') . '</p>';
}

public function mxchat_email_capture_response_callback() {
    $default_response = __('Thank you for providing your email! You\'ve been added to our list.', 'mxchat');
    $email_capture_response = isset($this->options['email_capture_response'])
        ? $this->options['email_capture_response']
        : $default_response;
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="mxchat-field-wrapper">';
    echo sprintf(
        '<textarea id="email_capture_response" name="email_capture_response" rows="3" cols="50" class="mxchat-autosave-field" data-nonce="%s">%s</textarea>',
        $nonce,
        esc_textarea($email_capture_response)
    );
    echo '</div>';
    echo '<p class="description">' . esc_html__('Enter the instruction for the AI when a user provides their email. The AI will use this as guidance to naturally confirm the email capture in a conversational way.', 'mxchat') . '</p>';
}
public function mxchat_pre_chat_message_callback() {
    // Load the entire 'mxchat_options' array
    $all_options = get_option('mxchat_options', []);

    // Retrieve the saved message or use the default value
    $default_message = __('Hey there! Ask me anything!', 'mxchat');
    $pre_chat_message = isset($all_options['pre_chat_message']) ? $all_options['pre_chat_message'] : $default_message;

    // Output the textarea
    printf(
        '<textarea id="pre_chat_message" name="pre_chat_message" rows="5" cols="50">%s</textarea>',
        esc_textarea($pre_chat_message)
    );
}

// Callback for AI Instructions textarea
public function system_prompt_instructions_callback() {
    // Retrieve the current value of the system prompt instructions
    $instructions = isset($this->options['system_prompt_instructions']) ? esc_textarea($this->options['system_prompt_instructions']) : '';
    // Render the textarea field
    printf(
        '<textarea id="system_prompt_instructions" name="system_prompt_instructions" rows="5" cols="50">%s</textarea>',
        $instructions
    );
    // Personalization hint
    echo '<p class="description" style="margin-top: 8px;">';
    echo esc_html__('Use {visitor_name} to personalize AI responses when lead capture is enabled.', 'mxchat') . '<br>';
    echo '<code style="font-size: 12px;">' . esc_html__('Example: The visitor\'s name is {visitor_name}. Address them by name.', 'mxchat') . '</code>';
    echo '</p>';
    // Sample instructions button
    echo '<div class="mxchat-instructions-container">';
    echo '<button type="button" class="mxchat-instructions-btn" id="mxchatViewSampleBtn">';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
    echo '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>';
    echo '<path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>';
    echo '</svg>';
    echo esc_html__('View Sample Instructions', 'mxchat');
    echo '</button>';
    echo '</div>';

    // Add modal to WordPress admin footer instead of inline
    add_action('admin_footer', array($this, 'render_sample_instructions_modal'));
}

// New method to render modal in admin footer
public function render_sample_instructions_modal() {
    static $modal_rendered = false;
    if ($modal_rendered) return; // Prevent duplicate modals
    $modal_rendered = true;

    echo '<div class="mxchat-instructions-modal-overlay" id="mxchatSampleModal">';
    echo '<div class="mxchat-instructions-modal-content">';
    echo '<div class="mxchat-instructions-modal-header">';
    echo '<h3 class="mxchat-instructions-modal-title">';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
    echo '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>';
    echo '<path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>';
    echo '</svg>';
    echo esc_html__('Sample AI Instructions', 'mxchat');
    echo '</h3>';
    echo '<button type="button" class="mxchat-instructions-modal-close" id="mxchatModalClose">';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
    echo '<line x1="18" y1="6" x2="6" y2="18"/>';
    echo '<line x1="6" y1="6" x2="18" y2="18"/>';
    echo '</svg>';
    echo '</button>';
    echo '</div>';
    echo '<div class="mxchat-instructions-modal-body">';
    echo '<div class="mxchat-instructions-content">';
    echo esc_html('You are an AI Chatbot assistant for this website. Your main goal is to assist visitors with questions and provide helpful information. Here are your key guidelines:

# Response Style - CRITICALLY IMPORTANT
- MAXIMUM LENGTH: 1-3 short sentences per response
- Ultra-concise: Get straight to the answer with no filler
- No introductions like "Sure!" or "I\'d be happy to help"
- No phrases like "based on my knowledge" or "according to information"
- No explanatory text before giving the answer
- No summaries or repetition
- Hyperlink all URLs
- Respond in user\'s language
- Minor chit chat or conversation is okay, but try to keep it focused on [insert topic]

# Knowledge Base Requirements - PREVENT HALLUCINATIONS
- ONLY answer using information explicitly provided in OFFICIAL KNOWLEDGE DATABASE CONTENT sections marked with ===== delimiters
- If required information is NOT in the knowledge database: "I don\'t have enough information in my knowledge base to answer that question accurately."
- NEVER invent or hallucinate URLs, links, product specs, procedures, dates, statistics, names, contacts, or company information
- When knowledge base information is unclear or contradictory, acknowledge the limitation rather than guessing
- Better to admit insufficient information than provide inaccurate answers');
    echo '</div>';
    echo '<button type="button" class="mxchat-instructions-copy-btn" id="mxchatCopyBtn">';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
    echo '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>';
    echo '<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>';
    echo '</svg>';
    echo esc_html__('Copy Instructions', 'mxchat');
    echo '</button>';
    echo '</div>';
    echo '<div class="mxchat-instructions-modal-footer">';
    echo '<button type="button" class="mxchat-instructions-btn-secondary" id="mxchatCloseBtn">' . esc_html__('Close', 'mxchat') . '</button>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}


public function mxchat_model_callback() {
    // Catalog refactor (plan-d14e89): single source of truth lives in
    // includes/class-mxchat-model-catalog.php. Dropdown groups are the
    // provider labels; each group maps model_id => "Label" strings.
    if (!class_exists('MxChat_Model_Catalog')) {
        require_once plugin_dir_path(__FILE__) . 'class-mxchat-model-catalog.php';
    }
    $models = MxChat_Model_Catalog::settings_dropdown_groups();

    // Retrieve the currently selected model from saved options
    $selected_model = isset($this->options['model']) ? esc_attr($this->options['model']) : 'gpt-5.1-chat-latest';

    // Begin the select dropdown
    echo '<select id="model" name="model">';

    // Iterate over groups of models
    foreach ($models as $group_label => $group_models) {
        echo '<optgroup label="' . esc_attr($group_label) . '">';

        foreach ($group_models as $model_value => $model_label) {
            echo '<option value="' . esc_attr($model_value) . '" ' . selected($selected_model, $model_value, false) . '>' . esc_html($model_label) . '</option>';
        }

        echo '</optgroup>';
    }

    echo '</select>';

    // Add a note for OpenRouter
    echo '<p class="description" id="openrouter-model-note" style="display:none; color: #d63638; font-weight: 500;">';
    echo '<span class="dashicons dashicons-info" style="font-size: 16px; vertical-align: middle;"></span> ';
    echo esc_html__('After entering your OpenRouter API key above, click the button below to load available models.', 'mxchat');
    echo '</p>';

    // API Key Status Messages (hidden by default, shown by JS based on selected model)
    $has_openai_key = !empty($this->options['api_key']);
    $has_claude_key = !empty($this->options['claude_api_key']);
    $has_xai_key = !empty($this->options['xai_api_key']);
    $has_deepseek_key = !empty($this->options['deepseek_api_key']);
    $has_gemini_key = !empty($this->options['gemini_api_key']);
    $has_openrouter_key = !empty($this->options['openrouter_api_key']);

    // OpenAI/GPT models
    echo '<p class="mxchat-api-status" data-provider="openai" style="display:none;">';
    if ($has_openai_key) {
        echo '<span style="color: #00a32a;">✓ ' . esc_html__('API key for OpenAI detected', 'mxchat') . '</span>';
    } else {
        echo '<span style="color: #d63638;">⚠ ' . esc_html__('No API key for OpenAI detected. Please enter API key in API Keys tab.', 'mxchat') . '</span>';
    }
    echo '</p>';

    // Claude models
    echo '<p class="mxchat-api-status" data-provider="claude" style="display:none;">';
    if ($has_claude_key) {
        echo '<span style="color: #00a32a;">✓ ' . esc_html__('API key for Anthropic (Claude) detected', 'mxchat') . '</span>';
    } else {
        echo '<span style="color: #d63638;">⚠ ' . esc_html__('No API key for Anthropic (Claude) detected. Please enter API key in API Keys tab.', 'mxchat') . '</span>';
    }
    echo '</p>';

    // X.AI models
    echo '<p class="mxchat-api-status" data-provider="xai" style="display:none;">';
    if ($has_xai_key) {
        echo '<span style="color: #00a32a;">✓ ' . esc_html__('API key for X.AI (Grok) detected', 'mxchat') . '</span>';
    } else {
        echo '<span style="color: #d63638;">⚠ ' . esc_html__('No API key for X.AI (Grok) detected. Please enter API key in API Keys tab.', 'mxchat') . '</span>';
    }
    echo '</p>';

    // DeepSeek models
    echo '<p class="mxchat-api-status" data-provider="deepseek" style="display:none;">';
    if ($has_deepseek_key) {
        echo '<span style="color: #00a32a;">✓ ' . esc_html__('API key for DeepSeek detected', 'mxchat') . '</span>';
    } else {
        echo '<span style="color: #d63638;">⚠ ' . esc_html__('No API key for DeepSeek detected. Please enter API key in API Keys tab.', 'mxchat') . '</span>';
    }
    echo '</p>';

    // Gemini models
    echo '<p class="mxchat-api-status" data-provider="gemini" style="display:none;">';
    if ($has_gemini_key) {
        echo '<span style="color: #00a32a;">✓ ' . esc_html__('API key for Google Gemini detected', 'mxchat') . '</span>';
    } else {
        echo '<span style="color: #d63638;">⚠ ' . esc_html__('No API key for Google Gemini detected. Please enter API key in API Keys tab.', 'mxchat') . '</span>';
    }
    echo '</p>';

    // OpenRouter models
    echo '<p class="mxchat-api-status" data-provider="openrouter" style="display:none;">';
    if ($has_openrouter_key) {
        echo '<span style="color: #00a32a;">✓ ' . esc_html__('API key for OpenRouter detected', 'mxchat') . '</span>';
    } else {
        echo '<span style="color: #d63638;">⚠ ' . esc_html__('No API key for OpenRouter detected. Please enter API key in API Keys tab.', 'mxchat') . '</span>';
    }
    echo '</p>';

 // ADD THESE HIDDEN FIELDS RIGHT HERE:
    $openrouter_model = isset($this->options['openrouter_selected_model']) ? esc_attr($this->options['openrouter_selected_model']) : '';
    $openrouter_model_name = isset($this->options['openrouter_selected_model_name']) ? esc_attr($this->options['openrouter_selected_model_name']) : '';

    echo '<input type="hidden" id="openrouter_selected_model" name="openrouter_selected_model" value="' . $openrouter_model . '" />';
    echo '<input type="hidden" id="openrouter_selected_model_name" name="openrouter_selected_model_name" value="' . $openrouter_model_name . '" />';
}

// Update your existing callback method
public function enable_streaming_toggle_callback() {
    // Get value from options array, default to 'on'
    $enabled = isset($this->options['enable_streaming_toggle']) ? $this->options['enable_streaming_toggle'] : 'on';
    $checked = ($enabled === 'on') ? 'checked' : '';

    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="enable_streaming_toggle" name="enable_streaming_toggle" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';

    // Test button — branded .mxch-btn with inline SVG (IDs preserved for AJAX binding)
    echo '<div class="mxch-streaming-test-row">';
    echo '<button type="button" id="mxchat-test-streaming-btn" class="mxch-btn mxch-btn-secondary">';
    echo '<svg class="mxch-streaming-test-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>';
    echo esc_html__('Test Streaming Compatibility', 'mxchat');
    echo '</button>';
    echo '<p id="mxchat-test-streaming-result" class="mxch-streaming-test-result"></p>';
    echo '</div>';
}

// Web Search toggle callback
public function enable_web_search_toggle_callback() {
    // Get value from options array, default to 'off'
    $enabled = isset($this->options['enable_web_search']) ? $this->options['enable_web_search'] : 'off';
    $checked = ($enabled === 'on') ? 'checked' : '';

    // Get current model to determine if we should show/enable the toggle
    $current_model = isset($this->options['model']) ? $this->options['model'] : 'gpt-5.1-chat-latest';

    // Models that DON'T support web search — OpenAI-docs-driven exception list.
    // Keep hardcoded; the catalog can't infer "supports web search" per-model, so any
    // future OpenAI model that lacks Responses-API web_search support is added here.
    $unsupported_models = array('gpt-4.1-nano');

    // OpenAI chat-model allowlist for the Web Search toggle, derived from the central
    // model catalog (class-mxchat-model-catalog.php). When a new OpenAI chat model is
    // added there, the Web Search toggle picks it up automatically — no edit here.
    if (!class_exists('MxChat_Model_Catalog')) {
        require_once plugin_dir_path(__FILE__) . 'class-mxchat-model-catalog.php';
    }
    $chat_catalog = MxChat_Model_Catalog::chat_models();
    $openai_models = (isset($chat_catalog['openai']['models']) && is_array($chat_catalog['openai']['models']))
        ? array_keys($chat_catalog['openai']['models'])
        : array();

    $is_openai = in_array($current_model, $openai_models);
    $is_supported = $is_openai && !in_array($current_model, $unsupported_models);

    // Wrapper div with data attributes for JS to show/hide
    echo '<div id="web-search-toggle-wrapper" data-openai-models="' . esc_attr(implode(',', $openai_models)) . '" data-unsupported-models="' . esc_attr(implode(',', $unsupported_models)) . '"' . (!$is_supported ? ' style="display:none;"' : '') . '>';

    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="enable_web_search" name="enable_web_search" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';

    echo '</div>';

    // Message shown when non-OpenAI model is selected
    echo '<p id="web-search-unavailable-message" class="description" style="color: #666;' . ($is_supported ? ' display:none;' : '') . '">';
    echo '<span class="dashicons dashicons-info" style="font-size: 16px; vertical-align: middle; margin-right: 4px;"></span>';
    echo esc_html__('Web search is only available for OpenAI models.', 'mxchat');
    echo '</p>';
}

// AJAX handler to fetch OpenRouter models
public function fetch_openrouter_models() {
    check_ajax_referer('mxchat_fetch_openrouter_models', 'nonce');
    
    $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
    
    if (empty($api_key)) {
        wp_send_json_error(array('message' => 'API key is required'));
    }
    
    $response = wp_remote_get('https://openrouter.ai/api/v1/models', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'timeout' => 15,
    ));
    
    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (isset($data['data']) && is_array($data['data'])) {
        // Format the models for the frontend
        $models = array_map(function($model) {
            return array(
                'id' => $model['id'],
                'name' => $model['name'] ?? $model['id'],
                'description' => $model['description'] ?? '',
                'context_length' => $model['context_length'] ?? 0,
                'pricing' => array(
                    'prompt' => $model['pricing']['prompt'] ?? 0,
                    'completion' => $model['pricing']['completion'] ?? 0,
                ),
            );
        }, $data['data']);
        
        wp_send_json_success(array('models' => $models));
    } else {
        wp_send_json_error(array('message' => 'Invalid response from OpenRouter'));
    }
}

// Callback function for embedding model selection
public function embedding_model_callback() {
    $models = array(
        esc_html__('OpenAI Embeddings', 'mxchat') => array(
            'text-embedding-3-small' => esc_html__('TE3 Small (1536, Efficient)', 'mxchat'),
            'text-embedding-ada-002' => esc_html__('Ada 2 (1536, Recommended)', 'mxchat'),
            'text-embedding-3-large' => esc_html__('TE3 Large (3072, Powerful)', 'mxchat'),
        ),
        esc_html__('Voyage AI Embeddings', 'mxchat') => array(
            'voyage-3-large' => esc_html__('Voyage-3 Large (2048, Most Capable)', 'mxchat'),
        ),
        esc_html__('Google Gemini Embeddings', 'mxchat') => array(
            'gemini-embedding-001' => esc_html__('Gemini Embedding (1536, Stable)', 'mxchat'),
        )
    );
    $selected_model = isset($this->options['embedding_model']) ? esc_attr($this->options['embedding_model']) : 'text-embedding-ada-002';
    echo '<select id="embedding_model" name="embedding_model">';
    foreach ($models as $group_label => $group_models) {
        echo '<optgroup label="' . esc_attr($group_label) . '">';
        foreach ($group_models as $model_value => $model_label) {
            echo '<option value="' . esc_attr($model_value) . '" ' . selected($selected_model, $model_value, false) . '>' . esc_html($model_label) . '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select>';

    // API Key Status Messages for Embedding Models
    $has_openai_key = !empty($this->options['api_key']);
    $has_voyage_key = !empty($this->options['voyage_api_key']);
    $has_gemini_key = !empty($this->options['gemini_api_key']);

    // OpenAI Embeddings
    echo '<p class="mxchat-embedding-api-status" data-provider="openai" style="display:none;">';
    if ($has_openai_key) {
        echo '<span style="color: #00a32a;">✓ ' . esc_html__('API key for OpenAI detected', 'mxchat') . '</span>';
    } else {
        echo '<span style="color: #d63638;">⚠ ' . esc_html__('No API key for OpenAI detected. Please enter API key in API Keys tab.', 'mxchat') . '</span>';
    }
    echo '</p>';

    // Voyage AI Embeddings
    echo '<p class="mxchat-embedding-api-status" data-provider="voyage" style="display:none;">';
    if ($has_voyage_key) {
        echo '<span style="color: #00a32a;">✓ ' . esc_html__('API key for Voyage AI detected', 'mxchat') . '</span>';
    } else {
        echo '<span style="color: #d63638;">⚠ ' . esc_html__('No API key for Voyage AI detected. Please enter API key in API Keys tab.', 'mxchat') . '</span>';
    }
    echo '</p>';

    // Gemini Embeddings
    echo '<p class="mxchat-embedding-api-status" data-provider="gemini" style="display:none;">';
    if ($has_gemini_key) {
        echo '<span style="color: #00a32a;">✓ ' . esc_html__('API key for Google Gemini detected', 'mxchat') . '</span>';
    } else {
        echo '<span style="color: #d63638;">⚠ ' . esc_html__('No API key for Google Gemini detected. Please enter API key in API Keys tab.', 'mxchat') . '</span>';
    }
    echo '</p>';

}


public function mxchat_top_bar_title_callback() {
    // Retrieve the current value of the top bar title from saved options
    $top_bar_title = isset($this->options['top_bar_title']) ? esc_attr($this->options['top_bar_title']) : '';

    // Render the input field
    echo '<input type="text" id="top_bar_title" name="top_bar_title" value="' . $top_bar_title . '" />';
}
public function mxchat_ai_agent_text_callback() {
    // Retrieve the current value of the AI agent text from saved options
    $ai_agent_text = isset($this->options['ai_agent_text']) ? esc_attr($this->options['ai_agent_text']) : '';
    // Render the input field
    echo '<input type="text" id="ai_agent_text" name="ai_agent_text" value="' . $ai_agent_text . '" />';
}


public function enable_email_block_callback() {
    // Load full plugin options array
    $all_options = get_option('mxchat_options', []);

    // Get the value, default to 'off'
    $enable_email_block = isset($all_options['enable_email_block']) ? $all_options['enable_email_block'] : 'off';

    // Check if it's 'on'
    $checked = ($enable_email_block === 'on') ? 'checked' : '';

    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="enable_email_block" name="enable_email_block" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';
}

public function email_blocker_header_content_callback() {
    // Load the entire 'mxchat_options' array
    $all_options = get_option('mxchat_options', []);

    // Retrieve the saved content or default to empty
    $content = isset($all_options['email_blocker_header_content'])
        ? $all_options['email_blocker_header_content']
        : '';

    // Render the textarea - IMPORTANT: name should be just "email_blocker_header_content"
    echo '<textarea
            id="email_blocker_header_content"
            name="email_blocker_header_content"
            rows="5"
            cols="70"
            data-setting="email_blocker_header_content"
          >' . esc_textarea($content) . '</textarea>';
}

public function email_blocker_button_text_callback() {
    // Load the entire 'mxchat_options' array
    $all_options = get_option('mxchat_options', []);

    // Retrieve the saved button text or default to empty
    $button_text = isset($all_options['email_blocker_button_text'])
        ? $all_options['email_blocker_button_text']
        : '';

    // Use esc_attr to safely render the existing text
    echo '<input type="text" id="email_blocker_button_text" name="email_blocker_button_text" value="' . esc_attr($button_text) . '" style="width: 300px;" />';
}

//Enable name field callback
public function enable_name_field_callback() {
    // Load full plugin options array
    $all_options = get_option('mxchat_options', []);
    // Get the value, default to 'off'
    $enable_name_field = isset($all_options['enable_name_field']) ? $all_options['enable_name_field'] : 'off';
    // Check if it's 'on'
    $checked = ($enable_name_field === 'on') ? 'checked' : '';
    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="enable_name_field" name="enable_name_field" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';
}
//Name field placeholder callback
public function name_field_placeholder_callback() {
    $all_options = get_option('mxchat_options', []);
    $placeholder = isset($all_options['name_field_placeholder'])
        ? $all_options['name_field_placeholder']
        : esc_html__('Enter your name', 'mxchat');

    echo '<input type="text" id="name_field_placeholder" name="name_field_placeholder" value="' . esc_attr($placeholder) . '" style="width: 300px;" />';
}



public function mxchat_intro_message_callback() {
    // Load the entire 'mxchat_options' array
    $all_options = get_option('mxchat_options', []);
    // Retrieve the saved intro message or use the default
    $default_message = __('Hello! How can I assist you today?', 'mxchat');
    $saved_message = isset($all_options['intro_message']) ? $all_options['intro_message'] : $default_message;
    // Output the textarea with the saved value without escaping HTML
    ?>
    <textarea id="intro_message" name="intro_message" rows="5" cols="50"><?php echo $saved_message; ?></textarea>
    <p class="description" style="margin-top: 8px;">
        <?php esc_html_e('Use {visitor_name} to personalize greetings when lead capture is enabled.', 'mxchat'); ?><br>
        <code style="font-size: 12px;"><?php esc_html_e('Example: Hello {visitor_name}! How can I help you today?', 'mxchat'); ?></code>
    </p>
    <?php
}

public function mxchat_input_copy_callback() {
    // Load the entire 'mxchat_options' array
    $all_options = get_option('mxchat_options', []);

    // Retrieve the saved input copy or use the default value
    $default_copy = __('How can I assist?', 'mxchat');
    $input_copy = isset($all_options['input_copy']) ? $all_options['input_copy'] : $default_copy;

    // Output the input field with the saved value
    printf(
        '<input type="text" id="input_copy" name="input_copy" value="%s" placeholder="%s" />',
        esc_attr($input_copy),
        esc_attr__('How can I assist?', 'mxchat')
    );
}


public function mxchat_append_to_body_callback() {
    // Fetch fresh options to ensure we have the latest saved values
    $options = get_option('mxchat_options', array());

    // Get value from options array, default to 'off'
    $append_to_body = isset($options['append_to_body']) ? $options['append_to_body'] : 'off';
    $checked = ($append_to_body === 'on') ? 'checked' : '';

    // Get post type visibility settings
    $visibility_mode = isset($options['post_type_visibility_mode']) ? $options['post_type_visibility_mode'] : 'all';
    $visibility_list = isset($options['post_type_visibility_list']) ? $options['post_type_visibility_list'] : array();
    if (!is_array($visibility_list)) {
        $visibility_list = array();
    }

    echo '<div class="mxchat-autosave-section">';

    // Main toggle
    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="append_to_body" name="append_to_body" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';

    // Post Type Visibility Options (only visible when auto-display is ON)
    $display_style = ($append_to_body === 'on') ? '' : 'display: none;';
    echo '<div id="post-type-visibility-options" class="mxchat-sub-options" style="' . esc_attr($display_style) . '">';

    echo '<div class="mxchat-post-type-visibility-header">';
    echo '<h4>' . esc_html__('Post Type Visibility', 'mxchat') . '</h4>';
    echo '</div>';

    // Mode selector (radio buttons)
    echo '<div class="mxchat-visibility-mode">';

    echo '<label class="mxchat-radio-label">';
    echo '<input type="radio" name="post_type_visibility_mode" value="all" ' . checked($visibility_mode, 'all', false) . ' />';
    echo '<span>' . esc_html__('Show on all post types', 'mxchat') . '</span>';
    echo '</label>';

    echo '<label class="mxchat-radio-label">';
    echo '<input type="radio" name="post_type_visibility_mode" value="include" ' . checked($visibility_mode, 'include', false) . ' />';
    echo '<span>' . esc_html__('Only show on selected post types', 'mxchat') . '</span>';
    echo '</label>';

    echo '<label class="mxchat-radio-label">';
    echo '<input type="radio" name="post_type_visibility_mode" value="exclude" ' . checked($visibility_mode, 'exclude', false) . ' />';
    echo '<span>' . esc_html__('Hide on selected post types', 'mxchat') . '</span>';
    echo '</label>';

    echo '</div>';

    // Post type checkboxes (only visible when mode is include or exclude)
    $list_display = ($visibility_mode !== 'all') ? '' : 'display: none;';
    echo '<div id="post-type-list" class="mxchat-post-type-list" style="' . esc_attr($list_display) . '">';

    // Get all public post types
    $post_types = get_post_types(array('public' => true), 'objects');

    foreach ($post_types as $post_type) {
        // Skip attachments
        if ($post_type->name === 'attachment') {
            continue;
        }

        $is_checked = in_array($post_type->name, $visibility_list) ? 'checked' : '';

        echo '<label class="mxchat-checkbox-label">';
        echo '<input type="checkbox" name="post_type_visibility_list[]" value="' . esc_attr($post_type->name) . '" ' . $is_checked . ' />';
        echo '<span>' . esc_html($post_type->label) . '</span>';
        echo '</label>';
    }

    echo '</div>'; // End post-type-list
    echo '</div>'; // End post-type-visibility-options
    echo '</div>'; // End mxchat-autosave-section
}

public function mxchat_contextual_awareness_callback() {
    // Get value from options array, default to 'off'
    $contextual_awareness = isset($this->options['contextual_awareness_toggle']) ? $this->options['contextual_awareness_toggle'] : 'off';
    $checked = ($contextual_awareness === 'on') ? 'checked' : '';
    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="contextual_awareness_toggle" name="contextual_awareness_toggle" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';
}

public function mxchat_citation_links_toggle_callback() {
    // Get value from options array, default to 'on' (enabled by default)
    $citation_links = isset($this->options['citation_links_toggle']) ? $this->options['citation_links_toggle'] : 'on';
    $checked = ($citation_links === 'on') ? 'checked' : '';
    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="citation_links_toggle" name="citation_links_toggle" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';
}

/**
 * Toggle for the end-of-session satisfaction rating prompt (plan-a5b006).
 * Default ON. The widget reads this through the localized object; the
 * mxchat_satisfaction_rating_enabled filter still lets developers force
 * the value site-wide.
 *
 * The 5 customization fields (idle/question/thanks/placeholder/saved) are
 * rendered inline here inside a single wrapper div whose initial display
 * is set server-side from the toggle value (plan-29caac). Mirrors the
 * auto-display chatbot pattern at mxchat_append_to_body_callback — no
 * DOMContentLoaded race because rows exist as direct children of this
 * callback's output, and the wrapper's display: none is inline at render
 * time so refresh shows the correct state with no flash.
 */
public function mxchat_satisfaction_rating_toggle_callback() {
    $options = $this->options;
    $value   = isset($options['satisfaction_rating_enabled']) ? $options['satisfaction_rating_enabled'] : 'off';
    $checked = ($value === 'on') ? 'checked' : '';

    $idle = isset($options['satisfaction_rating_idle_seconds']) ? intval($options['satisfaction_rating_idle_seconds']) : 60;
    $idle = max(5, min(600, $idle));
    $question    = isset($options['satisfaction_rating_question'])    ? $options['satisfaction_rating_question']    : '';
    $thanks      = isset($options['satisfaction_rating_thanks'])      ? $options['satisfaction_rating_thanks']      : '';
    $placeholder = isset($options['satisfaction_rating_placeholder']) ? $options['satisfaction_rating_placeholder'] : '';
    $saved       = isset($options['satisfaction_rating_saved'])       ? $options['satisfaction_rating_saved']       : '';

    echo '<div class="mxchat-autosave-section">';

    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="satisfaction_rating_enabled" name="satisfaction_rating_enabled" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';

    ?>
    <style>
        .mxchat-sub-options-field { margin: 12px 0; }
        .mxchat-sub-options-field label { display: inline-block; margin-bottom: 4px; }
        #satisfaction-rating-sub-options h4 { margin: 16px 0 8px; }
    </style>
    <?php

    $display_style = ($value === 'on') ? '' : 'display: none;';
    echo '<div id="satisfaction-rating-sub-options" class="mxchat-sub-options" style="' . esc_attr($display_style) . '">';

    echo '<h4>' . esc_html__('Customize the prompt (optional)', 'mxchat') . '</h4>';

    echo '<div class="mxchat-sub-options-field">';
    echo '<label for="satisfaction_rating_idle_seconds"><strong>' . esc_html__('Idle Timeout', 'mxchat') . '</strong></label><br />';
    printf(
        '<input type="number" id="satisfaction_rating_idle_seconds" name="satisfaction_rating_idle_seconds" value="%d" min="5" max="600" step="1" class="small-text" /> <span class="description">%s</span>',
        (int) $idle,
        esc_html__('seconds of user inactivity before the prompt appears (5-600)', 'mxchat')
    );
    echo '</div>';

    echo '<div class="mxchat-sub-options-field">';
    echo '<label for="satisfaction_rating_question"><strong>' . esc_html__('Prompt Question', 'mxchat') . '</strong></label><br />';
    printf(
        '<input type="text" id="satisfaction_rating_question" name="satisfaction_rating_question" value="%s" maxlength="200" class="regular-text" placeholder="%s" />',
        esc_attr($question),
        esc_attr__('Was this helpful?', 'mxchat')
    );
    echo '<p class="description">' . esc_html__('Leave blank for the default. Shown above the thumbs up/down.', 'mxchat') . '</p>';
    echo '</div>';

    echo '<div class="mxchat-sub-options-field">';
    echo '<label for="satisfaction_rating_thanks"><strong>' . esc_html__('Thank-You Message', 'mxchat') . '</strong></label><br />';
    printf(
        '<input type="text" id="satisfaction_rating_thanks" name="satisfaction_rating_thanks" value="%s" maxlength="300" class="regular-text" placeholder="%s" />',
        esc_attr($thanks),
        esc_attr__('Thanks! Anything we should improve? (optional)', 'mxchat')
    );
    echo '<p class="description">' . esc_html__('Leave blank for the default. Shown after the user clicks a thumb.', 'mxchat') . '</p>';
    echo '</div>';

    echo '<div class="mxchat-sub-options-field">';
    echo '<label for="satisfaction_rating_placeholder"><strong>' . esc_html__('Feedback Placeholder', 'mxchat') . '</strong></label><br />';
    printf(
        '<input type="text" id="satisfaction_rating_placeholder" name="satisfaction_rating_placeholder" value="%s" maxlength="200" class="regular-text" placeholder="%s" />',
        esc_attr($placeholder),
        esc_attr__('Tell us what could be better…', 'mxchat')
    );
    echo '<p class="description">' . esc_html__('Leave blank for the default. Placeholder text inside the feedback textarea.', 'mxchat') . '</p>';
    echo '</div>';

    echo '<div class="mxchat-sub-options-field">';
    echo '<label for="satisfaction_rating_saved"><strong>' . esc_html__('Saved Confirmation', 'mxchat') . '</strong></label><br />';
    printf(
        '<input type="text" id="satisfaction_rating_saved" name="satisfaction_rating_saved" value="%s" maxlength="200" class="regular-text" placeholder="%s" />',
        esc_attr($saved),
        esc_attr__('Thanks for the feedback.', 'mxchat')
    );
    echo '<p class="description">' . esc_html__('Leave blank for the default. Shown after the feedback is sent.', 'mxchat') . '</p>';
    echo '</div>';

    echo '</div>'; // #satisfaction-rating-sub-options
    echo '</div>'; // .mxchat-autosave-section

    ?>
    <script>
    (function() {
        document.addEventListener('DOMContentLoaded', function() {
            var toggle = document.getElementById('satisfaction_rating_enabled');
            var subOptions = document.getElementById('satisfaction-rating-sub-options');
            if (!toggle || !subOptions) return;
            toggle.addEventListener('change', function() {
                subOptions.style.display = toggle.checked ? '' : 'none';
            });
        });
    })();
    </script>
    <?php
}

public function mxchat_privacy_toggle_callback() {
    // Load from mxchat_options array
    $options = get_option('mxchat_options', []);

    // Get privacy toggle value with fallback
    $privacy_toggle = isset($options['privacy_toggle']) ? $options['privacy_toggle'] : 'off';
    $checked = ($privacy_toggle === 'on') ? 'checked' : '';

    // Get privacy text with fallback
    $privacy_text = isset($options['privacy_text'])
        ? $options['privacy_text']
        : __('By chatting, you agree to our <a href="https://example.com/privacy-policy" target="_blank">privacy policy</a>.', 'mxchat');

    // Output the toggle switch
    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="privacy_toggle" name="privacy_toggle" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';

    // Output the custom text input field
    echo sprintf(
        '<textarea id="privacy_text" name="privacy_text" rows="5" cols="50" class="regular-text">%s</textarea>',
        esc_textarea($privacy_text)
    );
}


public function mxchat_complianz_toggle_callback() {
    // Load from mxchat_options array
    $options = get_option('mxchat_options', []);

    // Get complianz toggle value with fallback
    $complianz_toggle = isset($options['complianz_toggle']) ? $options['complianz_toggle'] : 'off';
    $checked = ($complianz_toggle === 'on') ? 'checked' : '';

    // Output the toggle switch
    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="complianz_toggle" name="complianz_toggle" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';
}

public function mxchat_link_target_toggle_callback() {
    // Load from mxchat_options array
    $options = get_option('mxchat_options', []);

    // Get link target toggle value with fallback
    $link_target_toggle = isset($options['link_target_toggle']) ? $options['link_target_toggle'] : 'off';
    $checked = ($link_target_toggle === 'on') ? 'checked' : '';

    // Output the toggle switch
    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="link_target_toggle" name="link_target_toggle" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';
}

public function mxchat_chat_persistence_toggle_callback() {
    // Load from mxchat_options array
    $options = get_option('mxchat_options', []);

    // Get chat persistence toggle value with fallback
    $chat_persistence_toggle = isset($options['chat_persistence_toggle']) ? $options['chat_persistence_toggle'] : 'off';
    $checked = ($chat_persistence_toggle === 'on') ? 'checked' : '';

    // Output the toggle switch
    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="chat_persistence_toggle" name="chat_persistence_toggle" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';
}

public function mxchat_print_button_toggle_callback() {
    // Load from mxchat_options array
    $options = get_option('mxchat_options', []);

    // Default ON — the option was previously unexposed and the button always showed.
    $print_button_enabled = isset($options['print_button_enabled']) ? $options['print_button_enabled'] : 'on';
    $checked = ($print_button_enabled === 'on') ? 'checked' : '';

    // Output the toggle switch
    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="print_button_enabled" name="print_button_enabled" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';
}

public function mxchat_reset_chat_toggle_callback() {
    // Load from mxchat_options array. plan ac2e81 — default OFF (new, opt-in).
    $options = get_option('mxchat_options', []);
    $reset_chat_enabled = isset($options['reset_chat_enabled']) ? $options['reset_chat_enabled'] : 'off';
    $checked = ($reset_chat_enabled === 'on') ? 'checked' : '';

    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="reset_chat_enabled" name="reset_chat_enabled" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';
}

public function mxchat_reset_chat_label_callback() {
    // Editable label for the "Start new chat" menu item. plan ac2e81.
    $options = get_option('mxchat_options', []);
    $reset_chat_label = isset($options['reset_chat_label']) ? $options['reset_chat_label'] : '';

    printf(
        '<input type="text" id="reset_chat_label" name="reset_chat_label" value="%s" placeholder="%s" class="regular-text" />',
        esc_attr($reset_chat_label),
        esc_attr__('Start new chat', 'mxchat')
    );
}

public function mxchat_popular_question_1_callback() {
    // Load the full plugin options array
    $all_options = get_option('mxchat_options', []);

    // Retrieve the specific option for popular_question_1
    $popular_question_1 = isset($all_options['popular_question_1']) ? $all_options['popular_question_1'] : '';

    // Render the input field
    printf(
        '<input type="text" id="popular_question_1" name="popular_question_1" value="%s" placeholder="%s" class="regular-text" />',
        esc_attr($popular_question_1),
        esc_attr__('Enter Quick Question 1', 'mxchat')
    );
}


public function mxchat_popular_question_2_callback() {
    // Load the full plugin options array
    $all_options = get_option('mxchat_options', []);

    // Retrieve the specific option for popular_question_2
    $popular_question_2 = isset($all_options['popular_question_2']) ? $all_options['popular_question_2'] : '';

    // Render the input field
    printf(
        '<input type="text" id="popular_question_2" name="popular_question_2" value="%s" placeholder="%s" class="regular-text" />',
        esc_attr($popular_question_2),
        esc_attr__('Enter Quick Question 2', 'mxchat')
    );
}


public function mxchat_popular_question_3_callback() {
   // Load the full plugin options array
   $all_options = get_option('mxchat_options', []);

   // Retrieve the specific option for popular_question_3
   $popular_question_3 = isset($all_options['popular_question_3']) ? $all_options['popular_question_3'] : '';

   // Render the input field
   printf(
       '<input type="text" id="popular_question_3" name="popular_question_3" value="%s" placeholder="%s" class="regular-text" />',
       esc_attr($popular_question_3),
       esc_attr(__('Enter Quick Question 3', 'mxchat'))
   );
}

public function mxchat_additional_popular_questions_callback() {
    $options = get_option('mxchat_options', []);
    $additional_questions = isset($options['additional_popular_questions'])
        ? $options['additional_popular_questions']
        : get_option('additional_popular_questions', array());

    echo '<div id="mxchat-additional-questions-container">';
    if (!empty($additional_questions)) {
        foreach ($additional_questions as $index => $question) {
            printf(
                '<div class="mxchat-question-row">
                    <input type="text" name="additional_popular_questions[]"
                           value="%s"
                           placeholder="%s"
                           class="regular-text mxchat-question-input"
                           data-question-index="%d" />
                    <button type="button" class="button mxchat-remove-question"
                            aria-label="%s">%s</button>
                </div>',
                esc_attr($question),
                esc_attr(sprintf(__('Enter Additional Quick Question %d', 'mxchat'), $index + 4)),
                $index,
                esc_attr(__('Remove question', 'mxchat')),
                esc_html__('Remove', 'mxchat')
            );
        }
    } else {
        printf(
            '<div class="mxchat-question-row">
                <input type="text" name="additional_popular_questions[]"
                       value=""
                       placeholder="%s"
                       class="regular-text mxchat-question-input"
                       data-question-index="0" />
                <button type="button" class="button mxchat-remove-question"
                        aria-label="%s">%s</button>
            </div>',
            esc_attr(__('Enter Additional Quick Question 4', 'mxchat')),
            esc_attr(__('Remove question', 'mxchat')),
            esc_html__('Remove', 'mxchat')
        );
    }
    echo '</div>';
    printf(
        '<button type="button" class="button mxchat-add-question" aria-label="%s">%s</button>',
        esc_attr(__('Add question', 'mxchat')),
        esc_html__('Add Question', 'mxchat')
    );
}

public function mxchat_brave_api_key_callback() {
    $brave_api_key = isset($this->options['brave_api_key']) ? esc_attr($this->options['brave_api_key']) : '';
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="api-key-wrapper">';
    echo sprintf(
        '<input type="text" id="brave_api_key" name="brave_api_key" value="%s" class="regular-text mxchat-autosave-field mxchat-api-key-field" autocomplete="new-password" data-lpignore="true" data-form-type="other" data-nonce="%s" />',
        $brave_api_key,
        $nonce
    );
    echo '<button type="button" id="toggleBraveApiKeyVisibility">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '</div>';
    echo '<p class="description">' . __('Required for Brave Search integration. Get your API key from Brave Search API.', 'mxchat') . '</p>';
}

public function mxchat_brave_image_count_callback() {
    $brave_image_count = isset($this->options['brave_image_count'])
        ? intval($this->options['brave_image_count'])
        : 4;
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="mxchat-field-wrapper">';
    echo sprintf(
        '<input type="number" id="brave_image_count" name="brave_image_count"
               value="%d" min="1" max="6" class="small-text mxchat-autosave-field" data-nonce="%s" />',
        $brave_image_count,
        $nonce
    );
    echo '</div>';
    echo '<p class="description">' . __('Select the number of images to return (1-6).', 'mxchat') . '</p>';
}

public function mxchat_brave_safe_search_callback() {
    $brave_safe_search = isset($this->options['brave_safe_search'])
        ? esc_attr($this->options['brave_safe_search'])
        : 'strict';
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="mxchat-field-wrapper">';
    echo '<select id="brave_safe_search" name="brave_safe_search" class="mxchat-autosave-field" data-nonce="' . $nonce . '">';
    echo sprintf(
        '<option value="strict" %s>%s</option>',
        selected($brave_safe_search, 'strict', false),
        __('Strict', 'mxchat')
    );
    echo sprintf(
        '<option value="off" %s>%s</option>',
        selected($brave_safe_search, 'off', false),
        __('Off', 'mxchat')
    );
    echo '</select>';
    echo '</div>';
    echo '<p class="description">' .
         esc_html__('Set the Safe Search level for image searches. Brave Search only supports "Strict" and "Off" options.', 'mxchat') .
         '</p>';
}

public function mxchat_brave_news_count_callback() {
    $brave_news_count = isset($this->options['brave_news_count'])
        ? intval($this->options['brave_news_count'])
        : 3;
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="mxchat-field-wrapper">';
    echo sprintf(
        '<input type="number" id="brave_news_count" name="brave_news_count"
               value="%d" min="1" max="10" class="small-text mxchat-autosave-field" data-nonce="%s" />',
        $brave_news_count,
        $nonce
    );
    echo '</div>';
    echo '<p class="description">' . esc_html__('Select the number of news articles to retrieve (1-10).', 'mxchat') . '</p>';
}

public function mxchat_brave_country_callback() {
    $brave_country = isset($this->options['brave_country'])
        ? esc_attr($this->options['brave_country'])
        : 'us';
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="mxchat-field-wrapper">';
    echo sprintf(
        '<input type="text" id="brave_country" name="brave_country"
               value="%s" maxlength="2" class="small-text mxchat-autosave-field" data-nonce="%s" />',
        $brave_country,
        $nonce
    );
    echo '</div>';
    echo '<p class="description">' . esc_html__('Enter the country code (e.g., "us" for United States).', 'mxchat') . '</p>';
}

public function mxchat_brave_language_callback() {
    $brave_language = isset($this->options['brave_language'])
        ? esc_attr($this->options['brave_language'])
        : 'en';
    $nonce = wp_create_nonce('mxchat_autosave_nonce');

    echo '<div class="mxchat-field-wrapper">';
    echo sprintf(
        '<input type="text" id="brave_language" name="brave_language"
               value="%s" maxlength="2" class="small-text mxchat-autosave-field" data-nonce="%s" />',
        $brave_language,
        $nonce
    );
    echo '</div>';
    echo '<p class="description">' . esc_html__('Enter the language code (e.g., "en" for English).', 'mxchat') . '</p>';
}





// Section Callback
public function mxchat_pdf_intent_section_callback() {
    echo '<p>' . esc_html__('Configure the intent settings for the Chat with PDF feature.', 'mxchat') . '</p>';
}

public function mxchat_chat_toolbar_toggle_callback() {
    // Get chat toolbar toggle value with fallback
    $chat_toolbar_toggle = isset($this->options['chat_toolbar_toggle']) ? $this->options['chat_toolbar_toggle'] : 'off';
    $checked = ($chat_toolbar_toggle === 'on') ? 'checked' : '';

    // Output the toggle switch
    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="chat_toolbar_toggle" name="chat_toolbar_toggle" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';

    echo '<p class="description">' . esc_html__('Enable to display the chat toolbar, adding two icons below the chatbot input field for uploading PDF and Word documents (default is hidden).', 'mxchat') . '</p>';
}

/**
 * Callback for PDF upload button toggle setting
 */
public function mxchat_show_pdf_upload_button_callback() {
    // Get toggle value with fallback
    $show_pdf_button = isset($this->options['show_pdf_upload_button']) ? $this->options['show_pdf_upload_button'] : 'on';
    $checked = ($show_pdf_button === 'on') ? 'checked' : '';

    // Output the toggle switch
    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="show_pdf_upload_button" name="show_pdf_upload_button" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';

    echo '<p class="description">' . esc_html__('Enable to show the PDF upload button in the chatbot toolbar. Disable to hide it.', 'mxchat') . '</p>';
}

/**
 * Callback for Word upload button toggle setting
 */
public function mxchat_show_word_upload_button_callback() {
    // Get toggle value with fallback
    $show_word_button = isset($this->options['show_word_upload_button']) ? $this->options['show_word_upload_button'] : 'on';
    $checked = ($show_word_button === 'on') ? 'checked' : '';

    // Output the toggle switch
    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="show_word_upload_button" name="show_word_upload_button" value="on" %s />',
        esc_attr($checked)
    );
    echo '<span class="slider"></span>';
    echo '</label>';

    echo '<p class="description">' . esc_html__('Enable to show the Word document upload button in the chatbot toolbar. Disable to hide it.', 'mxchat') . '</p>';
}

public function mxchat_pdf_intent_trigger_text_callback() {
    $default_text = __("Please provide the URL to the PDF you'd like to discuss.", 'mxchat');

    echo sprintf(
        '<textarea id="pdf_intent_trigger_text"
                  name="pdf_intent_trigger_text"
                  rows="3"
                  cols="50"
                  placeholder="%s">%s</textarea>',
        esc_attr__('Enter trigger text', 'mxchat'),
        isset($this->options['pdf_intent_trigger_text'])
            ? esc_textarea($this->options['pdf_intent_trigger_text'])
            : esc_textarea($default_text)
    );
    echo '<p class="description">' . esc_html__('Text displayed when the intent is triggered.', 'mxchat') . '</p>';
}

public function mxchat_pdf_intent_success_text_callback() {
    $default_text = __("I've processed the PDF. What questions do you have about it?", 'mxchat');

    echo sprintf(
        '<textarea id="pdf_intent_success_text"
                  name="pdf_intent_success_text"
                  rows="3"
                  cols="50"
                  placeholder="%s">%s</textarea>',
        esc_attr__('Enter success text', 'mxchat'),
        isset($this->options['pdf_intent_success_text'])
            ? esc_textarea($this->options['pdf_intent_success_text'])
            : esc_textarea($default_text)
    );
    echo '<p class="description">' . esc_html__('Text displayed when the intent is successful.', 'mxchat') . '</p>';
}

public function mxchat_pdf_intent_error_text_callback() {
    $default_text = __("Sorry, I couldn't process the PDF. Please ensure it's a valid file.", 'mxchat');

    echo sprintf(
        '<textarea id="pdf_intent_error_text"
                  name="pdf_intent_error_text"
                  rows="3"
                  cols="50"
                  placeholder="%s">%s</textarea>',
        esc_attr__('Enter error text', 'mxchat'),
        isset($this->options['pdf_intent_error_text'])
            ? esc_textarea($this->options['pdf_intent_error_text'])
            : esc_textarea($default_text)
    );
    echo '<p class="description">' . esc_html__('Text displayed when an error occurs during the intent.', 'mxchat') . '</p>';
}

public function mxchat_pdf_max_pages_callback() {
    $max_pages = isset($this->options['pdf_max_pages']) ? intval($this->options['pdf_max_pages']) : 69;

    echo sprintf(
        '<input type="range"
               id="pdf_max_pages"
               name="pdf_max_pages"
               min="1"
               max="69"
               value="%d"
               class="range-slider" />',
        esc_attr($max_pages)
    );
    echo '<span id="pdf_max_pages_output">' . esc_html($max_pages) . '</span>';
    echo '<p class="description">' . esc_html__('Set the maximum number of document pages users can upload for processing. (1-69 pages)', 'mxchat') . '</p>';
}

public function mxchat_live_agent_status_callback() {
    // Always get fresh options instead of using cached $this->options
    $fresh_options = get_option('mxchat_options');
    $status = isset($fresh_options['live_agent_status']) ? $fresh_options['live_agent_status'] : 'off';

    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="live_agent_status" name="live_agent_status" value="on" %s />',
        checked($status, 'on', false)
    );
    echo '<span class="slider"></span>';
    echo '</label>';
    echo '<label for="live_agent_status" class="mxchat-status-label">';
    echo '<span class="status-text">' . ($status === 'on' ? esc_html__('Online', 'mxchat') : esc_html__('Offline', 'mxchat')) . '</span>';
    echo '</label>';
}

public function mxchat_live_agent_away_message_callback() {
    $message = isset($this->options['live_agent_away_message'])
        ? $this->options['live_agent_away_message']
        : __('Sorry, live agents are currently unavailable. I can continue helping you as an AI assistant.', 'mxchat');

    printf(
        '<textarea id="live_agent_away_message" name="live_agent_away_message" rows="3" cols="50">%s</textarea>',
        esc_textarea($message)
    );
    echo '<p class="description">' . esc_html__('Message shown when live agents are offline.', 'mxchat') . '</p>';
}

public function mxchat_live_agent_notification_message_callback() {
    $message = isset($this->options['live_agent_notification_message'])
        ? $this->options['live_agent_notification_message']
        : __('Live agent has been notified.', 'mxchat');

    printf(
        '<textarea id="live_agent_notification_message" name="live_agent_notification_message" rows="3" cols="50">%s</textarea>',
        esc_textarea($message)
    );
    echo '<p class="description">' . esc_html__('Message shown when live transfer activated.', 'mxchat') . '</p>';
}

public function mxchat_live_agent_webhook_url_callback() {
    $webhook_url = isset($this->options['live_agent_webhook_url'])
        ? esc_url($this->options['live_agent_webhook_url'])
        : esc_url(get_option('live_agent_webhook_url', ''));

    printf(
        '<input type="password" id="live_agent_webhook_url" name="live_agent_webhook_url" value="%s" class="regular-text" />',
        $webhook_url
    );
    echo '<button type="button" id="toggleWebhookUrlVisibility">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '<p class="description">' . esc_html__('Enter your Slack webhook URL for live agent notifications.', 'mxchat') . '</p>';
}

public function mxchat_live_agent_secret_key_callback() {
    printf(
        '<input type="password" id="live_agent_secret_key" name="live_agent_secret_key" value="%s" class="regular-text" />',
        isset($this->options['live_agent_secret_key']) ? esc_attr($this->options['live_agent_secret_key']) : ''
    );
    echo '<button type="button" id="toggleSecretKeyVisibility">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '<p class="description">' . esc_html__('Secret key for validating Slack requests. Keep this secure.', 'mxchat') . '</p>';
}

public function mxchat_live_agent_bot_token_callback() {
    printf(
        '<input type="password" id="live_agent_bot_token" name="live_agent_bot_token" value="%s" class="regular-text" />',
        isset($this->options['live_agent_bot_token']) ? esc_attr($this->options['live_agent_bot_token']) : ''
    );
    echo '<button type="button" id="toggleBotTokenVisibility">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '<p class="description">' . esc_html__('Your Slack Bot OAuth Token (starts with xoxb-). Keep this secure.', 'mxchat') . '</p>';
}

public function mxchat_live_agent_user_ids_callback() {
    $user_ids = isset($this->options['live_agent_user_ids'])
        ? esc_textarea($this->options['live_agent_user_ids'])
        : '';

    printf(
        '<textarea id="live_agent_user_ids" name="live_agent_user_ids" rows="4" class="large-text">%s</textarea>',
        $user_ids
    );
    echo '<p class="description">' . esc_html__('Enter Slack User IDs of agents who should be automatically invited to chat channels (one per line). Find user IDs in Slack profiles under "More" → "Copy member ID". Example: U1234567890', 'mxchat') . '</p>';
}

/**
 * Telegram Integration Callbacks
 */
public function mxchat_telegram_section_callback() {
    echo '<p>' . esc_html__('Configure Telegram integration for live agent support.', 'mxchat') . '</p>';
}

public function mxchat_telegram_status_callback() {
    $fresh_options = get_option('mxchat_options');
    $status = isset($fresh_options['telegram_status']) ? $fresh_options['telegram_status'] : 'off';

    echo '<label class="toggle-switch">';
    echo sprintf(
        '<input type="checkbox" id="telegram_status" name="telegram_status" value="on" %s />',
        checked($status, 'on', false)
    );
    echo '<span class="slider"></span>';
    echo '</label>';
    echo '<label for="telegram_status" class="mxchat-status-label">';
    echo '<span class="status-text">' . ($status === 'on' ? esc_html__('Online', 'mxchat') : esc_html__('Offline', 'mxchat')) . '</span>';
    echo '</label>';
}

public function mxchat_telegram_notification_message_callback() {
    $message = isset($this->options['telegram_notification_message'])
        ? $this->options['telegram_notification_message']
        : __("I've notified a support agent. Please allow a moment for them to respond.", 'mxchat');

    printf(
        '<textarea id="telegram_notification_message" name="telegram_notification_message" rows="3" cols="50">%s</textarea>',
        esc_textarea($message)
    );
    echo '<p class="description">' . esc_html__('Message shown when live transfer activated.', 'mxchat') . '</p>';
}

public function mxchat_telegram_away_message_callback() {
    $message = isset($this->options['telegram_away_message'])
        ? $this->options['telegram_away_message']
        : __('Sorry, live agents are currently unavailable. I can continue helping you as an AI assistant.', 'mxchat');

    printf(
        '<textarea id="telegram_away_message" name="telegram_away_message" rows="3" cols="50">%s</textarea>',
        esc_textarea($message)
    );
    echo '<p class="description">' . esc_html__('Message shown when live agents are offline.', 'mxchat') . '</p>';
}

public function mxchat_telegram_bot_token_callback() {
    printf(
        '<input type="password" id="telegram_bot_token" name="telegram_bot_token" value="%s" class="regular-text" />',
        isset($this->options['telegram_bot_token']) ? esc_attr($this->options['telegram_bot_token']) : ''
    );
    echo '<button type="button" class="button button-secondary" onclick="var f=document.getElementById(\'telegram_bot_token\'); if(f.type===\'password\'){f.type=\'text\';this.textContent=\'' . esc_js(__('Hide', 'mxchat')) . '\';}else{f.type=\'password\';this.textContent=\'' . esc_js(__('Show', 'mxchat')) . '\';}">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '<p class="description">' . esc_html__('Your Telegram Bot Token from @BotFather (e.g., 123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ).', 'mxchat') . '</p>';
}

public function mxchat_telegram_group_id_callback() {
    printf(
        '<input type="text" id="telegram_group_id" name="telegram_group_id" value="%s" class="regular-text" />',
        isset($this->options['telegram_group_id']) ? esc_attr($this->options['telegram_group_id']) : ''
    );
    echo '<p class="description">' . esc_html__('Your Telegram supergroup ID (starts with -100). The group must have forum topics enabled.', 'mxchat') . '</p>';
}

public function mxchat_telegram_webhook_secret_callback() {
    $secret = isset($this->options['telegram_webhook_secret']) ? $this->options['telegram_webhook_secret'] : '';

    // Auto-generate secret if empty
    if (empty($secret)) {
        $secret = wp_generate_password(64, false, false);
        $options = get_option('mxchat_options', []);
        $options['telegram_webhook_secret'] = $secret;
        update_option('mxchat_options', $options);
        $this->options['telegram_webhook_secret'] = $secret;
    }

    printf(
        '<input type="password" id="telegram_webhook_secret" name="telegram_webhook_secret" value="%s" class="regular-text" />',
        esc_attr($secret)
    );
    echo '<button type="button" class="button button-secondary" onclick="var f=document.getElementById(\'telegram_webhook_secret\'); if(f.type===\'password\'){f.type=\'text\';this.textContent=\'' . esc_js(__('Hide', 'mxchat')) . '\';}else{f.type=\'password\';this.textContent=\'' . esc_js(__('Show', 'mxchat')) . '\';}">' . esc_html__('Show', 'mxchat') . '</button>';
    echo '<p class="description">' . esc_html__('Secret token for webhook verification. Use this when setting up your Telegram webhook with the secret_token parameter.', 'mxchat') . '</p>';
}

public function mxchat_similarity_threshold_callback() {
    // Load from mxchat_options array
    $options = get_option('mxchat_options', []);

    // Get value from options array with default of 35
    $threshold = isset($options['similarity_threshold']) ? $options['similarity_threshold'] : 35;

    echo '<div class="slider-container">';
    echo sprintf(
        '<input type="range"
               id="similarity_threshold"
               name="similarity_threshold"
               min="20"
               max="85"
               step="1"
               value="%s"
               class="range-slider" />',
        esc_attr($threshold)
    );
    echo sprintf(
        '<span id="threshold_value" class="range-value">%s</span>',
        esc_html($threshold)
    );
    echo '</div>';
}

public function mxchat_rag_sources_limit_callback() {
    // Load from mxchat_options array
    $options = get_option('mxchat_options', []);

    // Get value from options array with default of 3
    $rag_sources_limit = isset($options['rag_sources_limit']) ? intval($options['rag_sources_limit']) : 3;

    echo '<div class="slider-container">';
    echo sprintf(
        '<input type="range"
               id="rag_sources_limit"
               name="rag_sources_limit"
               min="3"
               max="10"
               step="1"
               value="%s"
               class="range-slider" />',
        esc_attr($rag_sources_limit)
    );
    echo sprintf(
        '<span id="rag_sources_limit_value" class="range-value">%s</span>',
        esc_html($rag_sources_limit)
    );
    echo '</div>';
}

public function mxchat_rag_chunks_limit_callback() {
    // Load from mxchat_options array
    $options = get_option('mxchat_options', []);

    // Get value from options array with default of 15
    $rag_chunks_limit = isset($options['rag_chunks_limit']) ? intval($options['rag_chunks_limit']) : 15;

    echo '<div class="slider-container">';
    echo sprintf(
        '<input type="range"
               id="rag_chunks_limit"
               name="rag_chunks_limit"
               min="8"
               max="20"
               step="1"
               value="%s"
               class="range-slider" />',
        esc_attr($rag_chunks_limit)
    );
    echo sprintf(
        '<span id="rag_chunks_limit_value" class="range-value">%s</span>',
        esc_html($rag_chunks_limit)
    );
    echo '</div>';
}

/**
 * Add body class on the Onboarding wizard page so CSS-only chrome surgery
 * (collapsing the WP admin sidebar) only applies on this page.
 * Plan: plan-mxchat-20260527-905439.
 */
public function mxchat_add_onboarding_body_class($classes) {
    if (isset($_GET['page']) && $_GET['page'] === 'mxchat-onboarding') {
        $classes .= ' mxchat-onboarding-focused';
    }
    return $classes;
}

public function mxchat_enqueue_admin_assets() {
    // Get plugin version
    $version = MXCHAT_VERSION;

    // Use file modification time for development (remove in production)
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $version = filemtime(plugin_dir_path(__FILE__) . '../mxchat-basic.php');
    }

    $current_page = isset($_GET['page']) ? $_GET['page'] : '';
    $plugin_url = plugin_dir_url(__FILE__) . '../';

    // Only load on MxChat pages
    if (strpos($current_page, 'mxchat') === false) {
        return;
    }

    // Always load these on all MxChat pages
    $this->enqueue_core_admin_assets($plugin_url, $version);
    $this->enqueue_page_specific_assets($current_page, $plugin_url, $version);
    $this->localize_admin_scripts($current_page);
}
private function enqueue_core_admin_assets($plugin_url, $version) {
    // Core admin styles
    wp_enqueue_style('mxchat-admin-css', $plugin_url . 'css/admin-style.css', array(), $version);
    wp_enqueue_style('mxchat-knowledge-css', $plugin_url . 'css/knowledge-style.css', array(), $version);

    // New sidebar navigation styles (for main settings page)
    wp_enqueue_style('mxchat-admin-sidebar-css', $plugin_url . 'css/admin-sidebar.css', array(), $version);

    // Core admin scripts
    wp_enqueue_script('mxchat-admin-js', $plugin_url . 'js/mxchat-admin.js', array('jquery'), $version, true);
}
private function enqueue_page_specific_assets($current_page, $plugin_url, $version) {
    switch ($current_page) {
        case 'mxchat-prompts':
            // Knowledge processing page assets
            wp_enqueue_style('mxchat-content-selector-css', $plugin_url . 'css/content-selector.css', array(), $version);
            wp_enqueue_script('mxchat-content-selector-js', $plugin_url . 'js/content-selector.js', array('jquery'), $version, true);
            //  Add the knowledge processing script (common script needed for WordPress dismiss functionality)
            wp_enqueue_script('mxchat-knowledge-processing', $plugin_url . 'js/knowledge-processing.js', array('jquery', 'common'), $version, true);
            break;

        case 'mxchat-transcripts':
            // Load admin sidebar CSS first (shared styles for sidebar navigation)
            wp_enqueue_style('mxchat-admin-sidebar-css', $plugin_url . 'css/admin-sidebar.css', array(), $version);
            // Load transcripts-specific styles
            wp_enqueue_style('mxchat-chat-transcripts-css', $plugin_url . 'css/chat-transcripts.css', array('mxchat-admin-sidebar-css'), $version);
            wp_enqueue_script('mxchat-transcripts-js', $plugin_url . 'js/mxchat_transcripts.js', array('jquery'), $version, true);
            break;

        case 'mxchat-actions':
            // Load admin sidebar CSS first (shared styles for sidebar navigation)
            wp_enqueue_style('mxchat-admin-sidebar-css', $plugin_url . 'css/admin-sidebar.css', array(), $version);
            // Load actions-specific styles
            wp_enqueue_style('mxchat-actions-css', $plugin_url . 'css/actions.css', array('mxchat-admin-sidebar-css'), $version);
            wp_enqueue_script('mxchat-actions-js', $plugin_url . 'js/mxchat_actions.js', array('jquery'), $version, true);

            // Localize script data for actions page
            $is_activated = get_option('mxchat_pro_license_status') === 'active';
            wp_localize_script('mxchat-actions-js', 'mxchActionsData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mxchat_actions_nonce'),
                'addNonce' => wp_create_nonce('mxchat_add_intent_nonce'),
                'editNonce' => wp_create_nonce('mxchat_edit_intent'),
                'deleteNonce' => wp_create_nonce('mxchat_delete_intent_nonce'),
                'toggleNonce' => wp_create_nonce('mxchat_actions_nonce'),
                'addPhraseNonce' => wp_create_nonce('mxchat_add_phrase_nonce'),
                'deletePhraseNonce' => wp_create_nonce('mxchat_delete_phrase_nonce'),
                'getPhrasesNonce' => wp_create_nonce('mxchat_get_phrases_nonce'),
                'deleteLegacyNonce' => wp_create_nonce('mxchat_delete_legacy_nonce'),
                'isActivated' => $is_activated,
                'i18n' => array(
                    'confirmDelete' => __('Are you sure you want to delete this trigger phrase?', 'mxchat'),
                    'confirmBulkDelete' => __('Are you sure you want to delete the selected trigger phrases?', 'mxchat'),
                    'saving' => __('Saving...', 'mxchat'),
                    'saved' => __('Saved successfully!', 'mxchat'),
                    'error' => __('An error occurred. Please try again.', 'mxchat'),
                    'proRequired' => __('This feature requires MxChat Pro.', 'mxchat'),
                    'addonRequired' => __('This feature requires an add-on.', 'mxchat')
                )
            ));
            break;

        case 'mxchat-activation':
            // Load admin sidebar CSS first (shared styles for sidebar navigation)
            wp_enqueue_style('mxchat-admin-sidebar-css', $plugin_url . 'css/admin-sidebar.css', array(), $version);
            // Load pro page-specific styles
            wp_enqueue_style('mxchat-pro-css', $plugin_url . 'css/admin-pro.css', array('mxchat-admin-sidebar-css'), $version);
            // Load pro page JavaScript
            wp_enqueue_script('mxchat-pro-js', $plugin_url . 'js/mxchat_pro.js', array('jquery'), $version, true);
            // Load activation script for license activation/deactivation
            wp_enqueue_script('mxchat-activation-js', $plugin_url . 'js/activation-script.js', array('jquery'), $version, true);
            break;

        case 'mxchat-content':
            // Load admin sidebar CSS (shared styles for sidebar navigation)
            wp_enqueue_style('mxchat-admin-sidebar-css', $plugin_url . 'css/admin-sidebar.css', array(), $version);
            // Load content page-specific styles
            wp_enqueue_style('mxchat-content-css', $plugin_url . 'css/admin-content.css', array('mxchat-admin-sidebar-css'), $version);
            // Load content page JavaScript
            wp_enqueue_script('mxchat-content-js', $plugin_url . 'js/mxchat-content.js', array('jquery'), $version, true);
            break;

        case 'mxchat-api-access':
            // Shared sidebar shell (CSS + JS for tab switching / mobile menu / copy buttons).
            wp_enqueue_style('mxchat-admin-sidebar-css', $plugin_url . 'css/admin-sidebar.css', array(), $version);
            wp_enqueue_script('mxchat-admin-sidebar-js', $plugin_url . 'js/admin-sidebar.js', array(), $version, true);
            wp_localize_script('mxchat-admin-sidebar-js', 'MxChatAdminSidebarI18n', array(
                'copied' => __('Copied', 'mxchat'),
            ));
            break;

        case 'mxchat-max':
        case 'mxchat-onboarding':
            // Onboarding page — uses the shared admin shell PLUS the wizard
            // overlay (plan-905439). admin-onboarding-wizard.css scopes the
            // WP-chrome surgery to body.mxchat-onboarding-focused so it only
            // applies on THIS page. The body class is added below via the
            // admin_body_class filter.
            wp_enqueue_style('mxchat-admin-sidebar-css', $plugin_url . 'css/admin-sidebar.css', array(), $version);
            wp_enqueue_script('mxchat-admin-sidebar-js', $plugin_url . 'js/admin-sidebar.js', array(), $version, true);
            wp_localize_script('mxchat-admin-sidebar-js', 'MxChatAdminSidebarI18n', array(
                'copied' => __('Copied', 'mxchat'),
            ));
            // admin-style.css provides the .mxchat-instructions-modal-* classes
            // the new Behavior step's "View Sample Instructions" modal needs.
            // Enqueued AFTER admin-sidebar.css but BEFORE the wizard overlay
            // so the wizard's own rules win where they collide. plan-a2e4d6.
            wp_enqueue_style('mxchat-admin-style-css', $plugin_url . 'css/admin-style.css', array('mxchat-admin-sidebar-css'), $version);
            wp_enqueue_style('mxchat-admin-onboarding-wizard-css', $plugin_url . 'css/admin-onboarding-wizard.css', array('mxchat-admin-sidebar-css', 'mxchat-admin-style-css'), $version);
            wp_enqueue_script('mxchat-admin-onboarding-wizard-js', $plugin_url . 'js/admin-onboarding-wizard.js', array(), $version, true);
            break;
       default:
            wp_enqueue_script(
                'mxchat-test-streaming-js',
                $plugin_url . 'js/mxchat-test-streaming.js',
                ['jquery'],
                $version,
                true
            );

            wp_localize_script('mxchat-test-streaming-js', 'mxchatTestStreamingAjax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mxchat_test_streaming_nonce'),
                'settings_nonce' => wp_create_nonce('mxchat_save_setting_nonce')
            ]);

            // Testing Tab: Load chatbot assets for the embedded testing chatbot
            wp_enqueue_style('mxchat-chat-css', $plugin_url . 'css/chat-style.css', array(), $version);
            wp_enqueue_style('mxchat-admin-testing-css', $plugin_url . 'css/admin-testing-tab.css', array('mxchat-admin-sidebar-css', 'mxchat-chat-css'), $version);

            wp_enqueue_script('mxchat-chat-js', $plugin_url . 'js/chat-script.js', array('jquery'), $version, true);
            wp_enqueue_script('mxchat-admin-testing-js', $plugin_url . 'js/admin-testing-tab.js', array('jquery', 'mxchat-chat-js'), $version, true);

            // Allow add-ons to enqueue their public CSS/JS for the testing chatbot
            do_action('mxchat_enqueue_testing_tab_assets');

            // Localize mxchatChat for the chatbot JS (same settings as frontend)
            $options = get_option('mxchat_options', array());
            $prompts_options = get_option('mxchat_prompts_options', array());
            $theme_options = get_option('mxchat_theme_options', array());
            $ai_theme_active = !empty($theme_options['active_ai_theme_css']);
            $has_bot_theme_assignments = !empty($theme_options['bot_theme_assignments']);
            $skip_inline_colors = $ai_theme_active || $has_bot_theme_assignments;

            wp_localize_script('mxchat-chat-js', 'mxchatChat', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mxchat_chat_nonce'),
                'model' => isset($options['model']) ? $options['model'] : 'gpt-5.1-chat-latest',
                'enable_streaming_toggle' => isset($options['enable_streaming_toggle']) ? $options['enable_streaming_toggle'] : 'on',
                'contextual_awareness_toggle' => isset($options['contextual_awareness_toggle']) ? $options['contextual_awareness_toggle'] : 'off',
                'link_target_toggle' => $options['link_target_toggle'] ?? 'off',
                'rate_limit_message' => $options['rate_limit_message'] ?? 'Rate limit exceeded. Please try again later.',
                'complianz_toggle' => isset($options['complianz_toggle']) && $options['complianz_toggle'] === 'on',
                'user_message_bg_color' => $options['user_message_bg_color'] ?? '#fff',
                'user_message_font_color' => $options['user_message_font_color'] ?? '#212121',
                'bot_message_bg_color' => $options['bot_message_bg_color'] ?? '#212121',
                'bot_message_font_color' => $options['bot_message_font_color'] ?? '#fff',
                'top_bar_bg_color' => $options['top_bar_bg_color'] ?? '#212121',
                'send_button_font_color' => $options['send_button_font_color'] ?? '#212121',
                'close_button_color' => $options['close_button_color'] ?? '#fff',
                'chatbot_background_color' => $options['chatbot_background_color'] ?? '#212121',
                'chatbot_bg_color' => $options['chatbot_bg_color'] ?? '#fff',
                'icon_color' => $options['icon_color'] ?? '#fff',
                'chat_input_font_color' => $options['chat_input_font_color'] ?? '#212121',
                'chat_persistence_toggle' => 'off', // Always off for testing chatbot
                'appendWidgetToBody' => 'off',
                'live_agent_message_bg_color' => $options['live_agent_message_bg_color'] ?? '#ffffff',
                'live_agent_message_font_color' => $options['live_agent_message_font_color'] ?? '#333333',
                'chat_toolbar_toggle' => $options['chat_toolbar_toggle'] ?? 'off',
                'mode_indicator_bg_color' => $options['mode_indicator_bg_color'] ?? '#767676',
                'mode_indicator_font_color' => $options['mode_indicator_font_color'] ?? '#ffffff',
                'toolbar_icon_color' => $options['toolbar_icon_color'] ?? '#212121',
                'use_pinecone' => $prompts_options['mxchat_use_pinecone'] ?? '0',
                'email_collection_enabled' => 'off', // No email collection in testing
                'initial_email_state' => null,
                'skip_email_check' => true,
                'pinecone_enabled' => isset($prompts_options['mxchat_use_pinecone']) && $prompts_options['mxchat_use_pinecone'] === '1',
                'skip_inline_colors' => $skip_inline_colors,
                'bot_theme_assignments' => $theme_options['bot_theme_assignments'] ?? array()
            ));

            // Localize testing tab script data
            wp_localize_script('mxchat-admin-testing-js', 'mxchatAdminTestData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mxchat_test_nonce'),
                'isAdmin' => true,
                'testingEnabled' => true
            ));

            // Add testing enabled flag for the chatbot to return debug data
            add_action('admin_footer', function() {
                echo '<script>window.mxchatTestingEnabled = true;</script>';
            });

            break;
    }
}
private function localize_admin_scripts($current_page) {
    // Base localization data for main admin script
    $base_data = array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('mxchat_admin_nonce'),
        'admin_url' => admin_url(),
        'license_nonce' => wp_create_nonce('mxchat_activate_license_nonce'),
        'inline_edit_nonce' => wp_create_nonce('mxchat_save_inline_nonce'),
        'setting_nonce' => wp_create_nonce('mxchat_save_setting_nonce'),
        'export_nonce' => wp_create_nonce('mxchat_export_transcripts'),
        'actions_nonce' => wp_create_nonce('mxchat_actions_nonce'),
        'add_intent_nonce' => wp_create_nonce('mxchat_add_intent_nonce'),
        'edit_intent_nonce' => wp_create_nonce('mxchat_edit_intent'),
        'add_phrase_nonce' => wp_create_nonce('mxchat_add_phrase_nonce'),
        'delete_phrase_nonce' => wp_create_nonce('mxchat_delete_phrase_nonce'),
        'get_phrases_nonce' => wp_create_nonce('mxchat_get_phrases_nonce'),
        'delete_legacy_nonce' => wp_create_nonce('mxchat_delete_legacy_nonce'),
        'toggle_action_nonce' => wp_create_nonce('mxchat_actions_nonce'),
        'fetch_openrouter_models_nonce' => wp_create_nonce('mxchat_fetch_openrouter_models'),
        'is_activated' => $this->is_activated ? '1' : '0',
        'status_refresh_interval' => 5000,
        'prompts_setting_nonce' => wp_create_nonce('mxchat_prompts_setting_nonce'),
        'ajaxurl' => admin_url('admin-ajax.php')
    );

    // Localize main admin script with base data
    wp_localize_script('mxchat-admin-js', 'mxchatAdmin', $base_data);

    // Canonical chat-model catalog for the modal picker grid
    // (plan-d14e89). Adding a model in class-mxchat-model-catalog.php
    // automatically appears here.
    if (!class_exists('MxChat_Model_Catalog')) {
        require_once plugin_dir_path(__FILE__) . 'class-mxchat-model-catalog.php';
    }
    wp_localize_script('mxchat-admin-js', 'mxchatChatModelCatalog', MxChat_Model_Catalog::js_picker_shape());

    // Page-specific localizations
    $this->localize_page_specific_scripts($current_page);
}
private function localize_page_specific_scripts($current_page) {
    switch ($current_page) {
        case 'mxchat-prompts':
            // Status updater localization
            wp_localize_script('mxchat-status-updater', 'mxchat_status_data', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mxchat_status_nonce')
            ));

            // Content selector localization
            $mxchat_options_for_selector = get_option('mxchat_options', array());
            $acf_pdf_extract_default = !empty($mxchat_options_for_selector['acf_pdf_extract_default']);
            wp_localize_script('mxchat-content-selector-js', 'mxchatSelector', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mxchat_content_selector_nonce'),
                'acfPdfExtractDefault' => $acf_pdf_extract_default ? 1 : 0,
                'i18n' => array(
                    'searchPlaceholder' => __('Search posts and pages...', 'mxchat'),
                    'selectAll' => __('Select All', 'mxchat'),
                    'process' => __('Process Selected', 'mxchat'),
                    'cancel' => __('Cancel', 'mxchat'),
                    'noResults' => __('No content found.', 'mxchat'),
                    'extractingPdfs' => __('extracting %d PDF(s)...', 'mxchat'),
                    'pdfExtractedSuffix' => __(' (%d PDF(s) extracted)', 'mxchat')
                )
            ));

            wp_localize_script('mxchat-knowledge-processing', 'mxchatAdmin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'status_nonce' => wp_create_nonce('mxchat_status_nonce'),
                'queue_nonce' => wp_create_nonce('mxchat_queue_nonce'),
                'stop_nonce' => wp_create_nonce('mxchat_stop_processing_action'),
                'settings_nonce' => wp_create_nonce('mxchat_prompts_setting_nonce'),
                'setting_nonce' => wp_create_nonce('mxchat_save_setting_nonce'),
                'entries_nonce' => wp_create_nonce('mxchat_entries_nonce'),
                'admin_url' => admin_url(),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'status_refresh_interval' => 2000,
                'bot_id' => isset($_GET['bot_id']) ? sanitize_text_field($_GET['bot_id']) : 'default'
            ));
            break;

        case 'mxchat-activation':
            wp_localize_script('mxchat-activation-js', 'mxchatAdmin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'license_nonce' => wp_create_nonce('mxchat_activate_license_nonce')
            ));
            break;

        case 'mxchat-content':
            wp_localize_script('mxchat-content-js', 'mxchatContent', array(
                'ajaxUrl'      => admin_url('admin-ajax.php'),
                'nonce'        => wp_create_nonce('mxchat_content_nonce'),
                'settingNonce' => wp_create_nonce('mxchat_save_setting_nonce'),
                'previewUrl'   => home_url('/?p='),
                'isActivated'  => $this->is_activated(),
                'hasAdvancedContent' => apply_filters('mxchat_content_pro_feature', false, 'seo_readability'),
                'hasGSC'             => apply_filters('mxchat_content_pro_feature', false, 'gsc_integration'),
                'seoOptimize'  => array(
                    'meta_description' => ($options['seo_optimize_meta_desc'] ?? 'on') === 'on',
                    'seo_title'        => ($options['seo_optimize_seo_title'] ?? 'on') === 'on',
                    'slug'             => ($options['seo_optimize_slug'] ?? 'on') === 'on',
                    'readability'      => ($options['seo_optimize_readability'] ?? 'on') === 'on',
                    'internal_links'   => ($options['seo_optimize_internal_links'] ?? 'on') === 'on',
                    'img_alt'          => ($options['seo_optimize_img_alt'] ?? 'on') === 'on',
                    'featured_img'     => ($options['seo_optimize_featured_img'] ?? 'on') === 'on',
                ),
                'i18n'         => array(
                    'generating'    => __('Generating...', 'mxchat'),
                    'planning'      => __('Planning content structure...', 'mxchat'),
                    'images'        => __('Generating images...', 'mxchat'),
                    'writing'       => __('Writing full content...', 'mxchat'),
                    'creating'      => __('Creating WordPress post...', 'mxchat'),
                    'done'          => __('Content generated successfully!', 'mxchat'),
                    'error'         => __('An error occurred. Please try again.', 'mxchat'),
                    'editSuccess'   => __('Content updated successfully.', 'mxchat'),
                    'promptEmpty'   => __('Please enter a prompt.', 'mxchat'),
                )
            ));
            break;

        case 'mxchat-transcripts':
            // Get chart data for localization
            $chart_data = $this->get_transcripts_chart_data();

            wp_localize_script('mxchat-transcripts-js', 'mxchatAdmin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'export_nonce' => wp_create_nonce('mxchat_export_transcripts'),
                'delete_nonce' => wp_create_nonce('mxchat_delete_chat_history'),
                'setting_nonce' => wp_create_nonce('mxchat_save_setting_nonce'),
                'translate_nonce' => wp_create_nonce('mxchat_translate_messages')
            ));

            // Localize chart data separately - use array_values to ensure proper JSON array encoding
            wp_localize_script('mxchat-transcripts-js', 'mxchatChartData', array(
                'labels' => array_values($chart_data['labels']),
                'chats' => array_values($chart_data['chats']),
                'messages' => array_values($chart_data['messages'])
            ));
            break;

        case 'mxchat-settings':
        default:
            // Color picker and settings localization
            wp_localize_script('mxchat-color-picker', 'mxchatStyleSettings', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'link_target_toggle' => $this->options['link_target_toggle'] ?? 'off',
                'user_message_bg_color' => $this->options['user_message_bg_color'] ?? '#fff',
                'user_message_font_color' => $this->options['user_message_font_color'] ?? '#212121',
                'bot_message_bg_color' => $this->options['bot_message_bg_color'] ?? '#212121',
                'bot_message_font_color' => $this->options['bot_message_font_color'] ?? '#fff',
                'top_bar_bg_color' => $this->options['top_bar_bg_color'] ?? '#212121',
                'send_button_font_color' => $this->options['send_button_font_color'] ?? '#212121',
                'close_button_color' => $this->options['close_button_color'] ?? '#fff',
                'chatbot_background_color' => $this->options['chatbot_background_color'] ?? '#212121',
                'icon_color' => $this->options['icon_color'] ?? '#fff',
                'chat_input_font_color' => $this->options['chat_input_font_color'] ?? '#212121',
                'pre_chat_message' => $this->options['pre_chat_message'] ?? esc_html__('Hey there! Ask me anything!', 'mxchat'),
                'rate_limit_message' => $this->options['rate_limit_message'] ?? esc_html__('Rate limit exceeded. Please try again later.', 'mxchat'),
                'loops_api_key' => $this->options['loops_api_key'] ?? '',
                'loops_mailing_list' => $this->options['loops_mailing_list'] ?? '',
                'triggered_phrase_response' => $this->options['triggered_phrase_response'] ?? esc_html__('Would you like to join our mailing list? Please provide your email below.', 'mxchat'),
                'email_capture_response' => $this->options['email_capture_response'] ?? esc_html__('Thank you for providing your email! You\'ve been added to our list.', 'mxchat'),
                'pdf_intent_trigger_text' => $this->options['pdf_intent_trigger_text'] ?? esc_html__("Please provide the URL to the PDF you'd like to discuss.", 'mxchat'),
                'pdf_intent_success_text' => $this->options['pdf_intent_success_text'] ?? esc_html__("I've processed the PDF. What questions do you have about it?", 'mxchat'),
                'pdf_intent_error_text' => $this->options['pdf_intent_error_text'] ?? esc_html__("Sorry, I couldn't process the PDF. Please ensure it's a valid file.", 'mxchat'),
                'pdf_max_pages' => $this->options['pdf_max_pages'] ?? 69,
                'live_agent_webhook_url' => $this->options['live_agent_webhook_url'] ?? '',
                'live_agent_secret_key' => $this->options['live_agent_secret_key'] ?? '',
                'live_agent_bot_token' => $this->options['live_agent_bot_token'] ?? '',
                'live_agent_message_bg_color' => $this->options['live_agent_message_bg_color'] ?? '#ffffff',
                'live_agent_message_font_color' => $this->options['live_agent_message_font_color'] ?? '#333333',
                'chat_toolbar_toggle' => $this->options['chat_toolbar_toggle'] ?? 'off',
                'show_pdf_upload_button' => $this->options['show_pdf_upload_button'] ?? 'on',
                'show_word_upload_button' => $this->options['show_word_upload_button'] ?? 'on',
                'mode_indicator_bg_color' => $this->options['mode_indicator_bg_color'] ?? '#767676',
                'mode_indicator_font_color' => $this->options['mode_indicator_font_color'] ?? '#ffffff',
                'toolbar_icon_color' => $this->options['toolbar_icon_color'] ?? '#212121',
            ));
            break;
    }

    // Additional localization that was in the original code
    wp_localize_script('mxchat-admin-js', 'mxchatPromptsAdmin', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'prompts_setting_nonce' => wp_create_nonce('mxchat_prompts_setting_nonce'),
    ));
}

public function mxchat_sanitize($input) {
    $new_input = array();

    if (isset($input['api_key'])) {
        $new_input['api_key'] = sanitize_text_field($input['api_key']);
    }

    if (isset($input['similarity_threshold'])) {
        $new_input['similarity_threshold'] = absint($input['similarity_threshold']); // Ensure it's an integer
        $new_input['similarity_threshold'] = min(max($new_input['similarity_threshold'], 20), 85); // Enforce range
    }

    if (isset($input['rag_sources_limit'])) {
        $new_input['rag_sources_limit'] = absint($input['rag_sources_limit']); // Ensure it's an integer
        $new_input['rag_sources_limit'] = min(max($new_input['rag_sources_limit'], 3), 10); // Enforce range 3-10
    }

    if (isset($input['rag_chunks_limit'])) {
        $new_input['rag_chunks_limit'] = absint($input['rag_chunks_limit']); // Ensure it's an integer
        $new_input['rag_chunks_limit'] = min(max($new_input['rag_chunks_limit'], 8), 20); // Enforce range 8-20
    }

    if (isset($input['xai_api_key'])) {
        $new_input['xai_api_key'] = sanitize_text_field($input['xai_api_key']);
    }

    if (isset($input['claude_api_key'])) {
        $new_input['claude_api_key'] = sanitize_text_field($input['claude_api_key']);
    }

    if (isset($input['enable_streaming_toggle'])) {
        $new_input['enable_streaming_toggle'] = ($input['enable_streaming_toggle'] === 'on') ? 'on' : 'off';
    } else {
        // If checkbox not checked, it won't be in $input, so set to 'off'
        $new_input['enable_streaming_toggle'] = 'off';
    }

    if (isset($input['enable_web_search'])) {
        $new_input['enable_web_search'] = ($input['enable_web_search'] === 'on') ? 'on' : 'off';
    } else {
        // If checkbox not checked, it won't be in $input, so set to 'off'
        $new_input['enable_web_search'] = 'off';
    }

    if (isset($input['deepseek_api_key'])) {
        $new_input['deepseek_api_key'] = sanitize_text_field($input['deepseek_api_key']);
    }

    if (isset($input['gemini_api_key'])) {
        $new_input['gemini_api_key'] = sanitize_text_field($input['gemini_api_key']);
    }

    if (isset($input['enable_woocommerce_integration'])) {
        $new_input['enable_woocommerce_integration'] = $input['enable_woocommerce_integration'] === 'on' ? 'on' : 'off';
    }

    if (isset($input['privacy_toggle'])) {
        $new_input['privacy_toggle'] = $input['privacy_toggle'];
    }

    if (isset($input['complianz_toggle'])) {
        $new_input['complianz_toggle'] = $input['complianz_toggle'];
    }

    // Handle custom privacy text input
    if (isset($input['privacy_text'])) {
        // Allow basic HTML for links
        $new_input['privacy_text'] = wp_kses_post($input['privacy_text']);
    }

    if (isset($input['system_prompt_instructions'])) {
        $new_input['system_prompt_instructions'] = sanitize_textarea_field($input['system_prompt_instructions']);
    }

    if (isset($input['mxchat_pro_email'])) {
        $new_input['mxchat_pro_email'] = sanitize_email($input['mxchat_pro_email']);
    }

    if (isset($input['mxchat_activation_key'])) {
        $new_input['mxchat_activation_key'] = sanitize_text_field($input['mxchat_activation_key']);
    }

    if (isset($input['append_to_body'])) {
        $new_input['append_to_body'] = $input['append_to_body'] === 'on' ? 'on' : 'off';
    }

    // Post type visibility settings
    if (isset($input['post_type_visibility_mode'])) {
        $allowed_modes = array('all', 'include', 'exclude');
        $new_input['post_type_visibility_mode'] = in_array($input['post_type_visibility_mode'], $allowed_modes)
            ? $input['post_type_visibility_mode']
            : 'all';
    }

    if (isset($input['post_type_visibility_list'])) {
        if (is_array($input['post_type_visibility_list'])) {
            $new_input['post_type_visibility_list'] = array_map('sanitize_key', $input['post_type_visibility_list']);
        } else {
            $new_input['post_type_visibility_list'] = array();
        }
    }

    if (isset($input['contextual_awareness_toggle'])) {
    $new_input['contextual_awareness_toggle'] = $input['contextual_awareness_toggle'] === 'on' ? 'on' : 'off';
}

    if (isset($input['citation_links_toggle'])) {
    $new_input['citation_links_toggle'] = $input['citation_links_toggle'] === 'on' ? 'on' : 'off';
}

    // Satisfaction rating prompt — defaults ON when unchecked (so first save
    // doesn't accidentally disable it). The form posts 'on' when checked;
    // unchecked checkboxes don't post a value at all, so we infer 'off' only
    // when the autosave/PHP request explicitly clears it via empty string.
    if (array_key_exists('satisfaction_rating_enabled', $input)) {
        $new_input['satisfaction_rating_enabled'] = $input['satisfaction_rating_enabled'] === 'on' ? 'on' : 'off';
    }

    // Satisfaction rating customization (plan-141a12). Idle is clamped 5-600;
    // the 4 text strings are sanitized + capped server-side. Blank values are
    // preserved so the integrator falls back to translated defaults.
    if (array_key_exists('satisfaction_rating_idle_seconds', $input)) {
        $new_input['satisfaction_rating_idle_seconds'] = max(5, min(600, intval($input['satisfaction_rating_idle_seconds'])));
    }
    if (array_key_exists('satisfaction_rating_question', $input)) {
        $new_input['satisfaction_rating_question'] = mb_substr(sanitize_text_field($input['satisfaction_rating_question']), 0, 200);
    }
    if (array_key_exists('satisfaction_rating_thanks', $input)) {
        $new_input['satisfaction_rating_thanks'] = mb_substr(sanitize_text_field($input['satisfaction_rating_thanks']), 0, 300);
    }
    if (array_key_exists('satisfaction_rating_placeholder', $input)) {
        $new_input['satisfaction_rating_placeholder'] = mb_substr(sanitize_text_field($input['satisfaction_rating_placeholder']), 0, 200);
    }
    if (array_key_exists('satisfaction_rating_saved', $input)) {
        $new_input['satisfaction_rating_saved'] = mb_substr(sanitize_text_field($input['satisfaction_rating_saved']), 0, 200);
    }

    if (isset($input['top_bar_title'])) {
        $new_input['top_bar_title'] = sanitize_text_field($input['top_bar_title']);
    }

    if (isset($input['ai_agent_text'])) {
            $new_input['ai_agent_text'] = sanitize_text_field($input['ai_agent_text']);
        }

    if (isset($input['enable_email_block'])) {
        $new_input['enable_email_block'] = sanitize_text_field($input['enable_email_block']);
    }

    if (isset($input['email_blocker_header_content'])) {
        // wp_kses_post() allows standard HTML tags permitted by WordPress
        $new_input['email_blocker_header_content'] = wp_kses_post($input['email_blocker_header_content']);
    }
    if (isset($input['email_blocker_button_text'])) {
        $new_input['email_blocker_button_text'] = sanitize_text_field($input['email_blocker_button_text']);
    }
    //  Sanitize name field toggle
    if (isset($input['enable_name_field'])) {
        $new_input['enable_name_field'] = ($input['enable_name_field'] === 'on') ? 'on' : 'off';
    } else {
        $new_input['enable_name_field'] = 'off';
    }
    //  Sanitize name field placeholder
    if (isset($input['name_field_placeholder'])) {
        $new_input['name_field_placeholder'] = sanitize_text_field($input['name_field_placeholder']);
    }
    if (isset($input['intro_message'])) {
        $new_input['intro_message'] = wp_kses_post($input['intro_message']);  // Use wp_kses_post instead
    }

    if (isset($input['input_copy'])) {
        $new_input['input_copy'] = sanitize_text_field($input['input_copy']);
    }

    if (isset($input['rate_limit_message'])) {
        $new_input['rate_limit_message'] = sanitize_text_field($input['rate_limit_message']);
    }

// Handle the new rate limits format
if (isset($input['rate_limits']) && is_array($input['rate_limits'])) {
    $new_input['rate_limits'] = array();
    $allowed_limits = array('1', '3', '5', '10', '15', '20', '50', '100', 'unlimited');
    $allowed_timeframes = array('hourly', 'daily', 'weekly', 'monthly');

    foreach ($input['rate_limits'] as $role_id => $settings) {
        $new_input['rate_limits'][$role_id] = array();

        // Sanitize limit
        if (isset($settings['limit'])) {
            $limit = sanitize_text_field($settings['limit']);
            // Accept presets, 'unlimited', the '__custom__' sentinel, OR any positive
            // integer (custom value) — mirrors the global branch (plan-2c02ea). Without
            // the custom path the per-role custom input was dropped and reset to the role
            // default on every save (plan-7e23e7).
            if (in_array($limit, $allowed_limits, true) || $limit === '__custom__' || (ctype_digit($limit) && (int) $limit >= 1)) {
                $new_input['rate_limits'][$role_id]['limit'] = $limit;
            } else {
                $new_input['rate_limits'][$role_id]['limit'] = ($role_id === 'logged_out') ? '10' : '100'; // Default
            }
        }

        // Preserve the per-role custom value (mirrors the global branch's limit_custom).
        if (isset($settings['limit_custom'])) {
            $new_input['rate_limits'][$role_id]['limit_custom'] = preg_replace('/[^0-9]/', '', (string) $settings['limit_custom']);
        }

        // Sanitize timeframe
        if (isset($settings['timeframe'])) {
            $timeframe = sanitize_text_field($settings['timeframe']);
            if (in_array($timeframe, $allowed_timeframes, true)) {
                $new_input['rate_limits'][$role_id]['timeframe'] = $timeframe;
            } else {
                $new_input['rate_limits'][$role_id]['timeframe'] = 'daily'; // Default
            }
        }

        // Sanitize message
        if (isset($settings['message'])) {
            $new_input['rate_limits'][$role_id]['message'] = sanitize_textarea_field($settings['message']);
        }
    }
}

// Handle the whole-chatbot global rate limit (plan-mxchat-20260603-2c02ea).
// Mirrors the per-role block above, adapted to the single global shape. Without
// this branch the whitelist-rebuild dropped rate_limits_global entirely on every
// save, so the global cap silently fell back to its 'unlimited' default.
// MUST accept arbitrary positive integers so the custom-value path (d55f65) is not regressed.
if (isset($input['rate_limits_global']) && is_array($input['rate_limits_global'])) {
    $g = $input['rate_limits_global'];
    $allowed_limits     = array('1', '3', '5', '10', '15', '20', '50', '100', 'unlimited');
    $allowed_timeframes = array('hourly', 'daily', 'weekly', 'monthly');
    $global_out = array();

    if (isset($g['limit'])) {
        $limit = sanitize_text_field($g['limit']);
        // Accept presets, 'unlimited', the '__custom__' sentinel (resolved by the
        // autosave handler / renderer), OR any positive integer (custom value).
        if (in_array($limit, $allowed_limits, true) || $limit === '__custom__' || (ctype_digit($limit) && (int) $limit >= 1)) {
            $global_out['limit'] = $limit;
        } else {
            $global_out['limit'] = 'unlimited';
        }
    }

    if (isset($g['limit_custom'])) {
        $global_out['limit_custom'] = preg_replace('/[^0-9]/', '', (string) $g['limit_custom']);
    }

    if (isset($g['timeframe'])) {
        $timeframe = sanitize_text_field($g['timeframe']);
        $global_out['timeframe'] = in_array($timeframe, $allowed_timeframes, true) ? $timeframe : 'daily';
    }

    if (isset($g['message'])) {
        $global_out['message'] = sanitize_textarea_field($g['message']);
    }

    $new_input['rate_limits_global'] = $global_out;
}

    if (isset($input['pre_chat_message'])) {
        $new_input['pre_chat_message'] = sanitize_textarea_field($input['pre_chat_message']);
    }

    if (isset($input['voyage_api_key'])) {
    $new_input['voyage_api_key'] = sanitize_text_field($input['voyage_api_key']);
    }

    // Add to your sanitize function
    if (isset($input['embedding_model'])) {
        // Catalog refactor (plan-d14e89): allowlist derived from the canonical
        // catalog in includes/class-mxchat-model-catalog.php.
        if (!class_exists('MxChat_Model_Catalog')) {
            require_once plugin_dir_path(__FILE__) . 'class-mxchat-model-catalog.php';
        }
        $allowed_models = MxChat_Model_Catalog::embedding_model_ids();
        if (in_array($input['embedding_model'], $allowed_models)) {
            $new_input['embedding_model'] = sanitize_text_field($input['embedding_model']);
        }
    }

if (isset($input['model'])) {
    if ($input['model'] === 'openrouter') {
        $new_input['model'] = 'openrouter';
    } else {
        if (!class_exists('MxChat_Model_Catalog')) {
            require_once plugin_dir_path(__FILE__) . 'class-mxchat-model-catalog.php';
        }
        $allowed_models = MxChat_Model_Catalog::chat_model_ids();

        if (in_array($input['model'], $allowed_models)) {
            $new_input['model'] = sanitize_text_field($input['model']);
        } else {
            // Fallback for any deprecated model
            $new_input['model'] = 'gpt-5.1-chat-latest';
        }
    }
}
    
if (isset($input['openrouter_selected_model'])) {
    // Just sanitize it, don't validate against a whitelist
    $new_input['openrouter_selected_model'] = sanitize_text_field($input['openrouter_selected_model']);
}

// ADD THIS:
if (isset($input['openrouter_selected_model_name'])) {
    $new_input['openrouter_selected_model_name'] = sanitize_text_field($input['openrouter_selected_model_name']);
}
    
    if (isset($input['openrouter_api_key'])) {
        $new_input['openrouter_api_key'] = sanitize_text_field($input['openrouter_api_key']);
    }

    // Custom (OpenAI-compatible) Provider — Ollama, LM Studio, vLLM, Azure OpenAI, etc.
    if (isset($input['custom_provider_base_url'])) {
        $new_input['custom_provider_base_url'] = esc_url_raw(rtrim(trim((string) $input['custom_provider_base_url']), '/'));
    }
    if (isset($input['custom_provider_api_key'])) {
        $new_input['custom_provider_api_key'] = sanitize_text_field($input['custom_provider_api_key']);
    }
    if (isset($input['custom_provider_model'])) {
        $new_input['custom_provider_model'] = sanitize_text_field($input['custom_provider_model']);
    }
    if (isset($input['custom_provider_auth_scheme'])) {
        $scheme = sanitize_text_field($input['custom_provider_auth_scheme']);
        $new_input['custom_provider_auth_scheme'] = in_array($scheme, array('bearer', 'api-key'), true) ? $scheme : 'bearer';
    }
    if (isset($input['custom_provider_for_embeddings'])) {
        $new_input['custom_provider_for_embeddings'] = ($input['custom_provider_for_embeddings'] === 'on') ? 'on' : 'off';
    }
    if (isset($input['custom_provider_for_images'])) {
        $new_input['custom_provider_for_images'] = ($input['custom_provider_for_images'] === 'on') ? 'on' : 'off';
    }
    if (isset($input['custom_provider_embedding_model'])) {
        $new_input['custom_provider_embedding_model'] = sanitize_text_field($input['custom_provider_embedding_model']);
    }
    if (isset($input['custom_provider_api_version'])) {
        $new_input['custom_provider_api_version'] = sanitize_text_field($input['custom_provider_api_version']);
    }



    if (isset($input['woocommerce_consumer_key'])) {
        $new_input['woocommerce_consumer_key'] = sanitize_text_field($input['woocommerce_consumer_key']);
    }

    if (isset($input['woocommerce_consumer_secret'])) {
        $new_input['woocommerce_consumer_secret'] = sanitize_text_field($input['woocommerce_consumer_secret']);
    }


    // Sanitize link_target_toggle
    if (isset($input['link_target_toggle'])) {
        $new_input['link_target_toggle'] = $input['link_target_toggle'] === 'on' ? 'on' : 'off';
    }

    // Sanitize Loops API Key
    if (isset($input['loops_api_key'])) {
        $new_input['loops_api_key'] = sanitize_text_field($input['loops_api_key']);
    }

    if (isset($input['chat_persistence_toggle'])) {
        $new_input['chat_persistence_toggle'] = $input['chat_persistence_toggle'] === 'on' ? 'on' : 'off';
    }

    // No else clause: an absent key stays absent, so the front-end default ('on') applies
    // and the rebuild never strips a saved 'off' (autosave passes the full options array back through here).
    if (isset($input['print_button_enabled'])) {
        $new_input['print_button_enabled'] = $input['print_button_enabled'] === 'on' ? 'on' : 'off';
    }

    // plan ac2e81 — "Start new chat" toggle (default OFF) + editable label.
    // No else on the toggle: an absent key stays absent so the front-end '?? off'
    // default applies, and the full-array autosave rebuild preserves a saved value.
    if (isset($input['reset_chat_enabled'])) {
        $new_input['reset_chat_enabled'] = $input['reset_chat_enabled'] === 'on' ? 'on' : 'off';
    }

    if (isset($input['reset_chat_label'])) {
        $new_input['reset_chat_label'] = sanitize_text_field($input['reset_chat_label']);
    }

    if (isset($input['popular_question_1'])) {
        $new_input['popular_question_1'] = sanitize_text_field($input['popular_question_1']);
    }

    if (isset($input['popular_question_2'])) {
        $new_input['popular_question_2'] = sanitize_text_field($input['popular_question_2']);
    }

    if (isset($input['popular_question_3'])) {
        $new_input['popular_question_3'] = sanitize_text_field($input['popular_question_3']);
    }

    if (isset($input['additional_popular_questions']) && is_array($input['additional_popular_questions'])) {
        $new_input['additional_popular_questions'] = array_map('sanitize_text_field', $input['additional_popular_questions']);
    }

    // Sanitize Loops Mailing List
    if (isset($input['loops_mailing_list'])) {
        $new_input['loops_mailing_list'] = sanitize_text_field($input['loops_mailing_list']);
    }

// Sanitize Triggered Phrase Response
    if (isset($input['triggered_phrase_response'])) {
        $new_input['triggered_phrase_response'] = wp_kses_post($input['triggered_phrase_response']);
    }
    if (isset($input['email_capture_response'])) {
        $new_input['email_capture_response'] = wp_kses_post($input['email_capture_response']);
    }

    // Sanitize Brave Search Settings
    if (isset($input['brave_api_key'])) {
        $new_input['brave_api_key'] = sanitize_text_field($input['brave_api_key']);
    }

    if (isset($input['brave_image_count'])) {
        $image_count = intval($input['brave_image_count']);
        $new_input['brave_image_count'] = ($image_count >=1 && $image_count <=6) ? $image_count : 4;
    }

    if (isset($input['brave_safe_search'])) {
        $allowed = array('strict', 'off');
        $new_input['brave_safe_search'] = in_array($input['brave_safe_search'], $allowed, true) ? $input['brave_safe_search'] : 'strict';
    }

    if (isset($input['brave_news_count'])) {
        $news_count = intval($input['brave_news_count']);
        $new_input['brave_news_count'] = ($news_count >=1 && $news_count <=10) ? $news_count : 3;
    }

    if (isset($input['brave_country'])) {
        $new_input['brave_country'] = sanitize_text_field($input['brave_country']);
    }

    if (isset($input['brave_language'])) {
        $new_input['brave_language'] = sanitize_text_field($input['brave_language']);
    }

    if (isset($input['chat_toolbar_toggle'])) {
        $new_input['chat_toolbar_toggle'] = $input['chat_toolbar_toggle'] === 'on' ? 'on' : 'off';
    }

     // Sanitize PDF upload button toggle
    if (isset($input['show_pdf_upload_button'])) {
        $new_input['show_pdf_upload_button'] = $input['show_pdf_upload_button'] === 'on' ? 'on' : 'off';
    } else {
        $new_input['show_pdf_upload_button'] = 'off'; // If checkbox is unchecked
    }

    // Sanitize Word upload button toggle
    if (isset($input['show_word_upload_button'])) {
        $new_input['show_word_upload_button'] = $input['show_word_upload_button'] === 'on' ? 'on' : 'off';
    } else {
        $new_input['show_word_upload_button'] = 'off'; // If checkbox is unchecked
    }

    if (isset($input['pdf_intent_trigger_text'])) {
        $new_input['pdf_intent_trigger_text'] = sanitize_text_field($input['pdf_intent_trigger_text']);
    }

    if (isset($input['pdf_intent_success_text'])) {
        $new_input['pdf_intent_success_text'] = sanitize_text_field($input['pdf_intent_success_text']);
    }

    if (isset($input['pdf_intent_error_text'])) {
        $new_input['pdf_intent_error_text'] = sanitize_text_field($input['pdf_intent_error_text']);
    }

    if (isset($input['pdf_max_pages'])) {
        $new_input['pdf_max_pages'] = intval($input['pdf_max_pages']);
        if ($new_input['pdf_max_pages'] < 1 || $new_input['pdf_max_pages'] > 69) {
            $new_input['pdf_max_pages'] = 69; // Default to 69 if out of range
        }
    }

    if (isset($input['live_agent_webhook_url'])) {
        $new_input['live_agent_webhook_url'] = esc_url_raw($input['live_agent_webhook_url']);
    }
    if (isset($input['live_agent_secret_key'])) {
        $new_input['live_agent_secret_key'] = sanitize_text_field($input['live_agent_secret_key']);
    }

    // Live Agent Integration
    if (isset($input['live_agent_bot_token'])) {
        $new_input['live_agent_bot_token'] = sanitize_text_field($input['live_agent_bot_token']);
    }

    if (isset($input['live_agent_user_ids'])) {
    $new_input['live_agent_user_ids'] = sanitize_textarea_field($input['live_agent_user_ids']);
    }

    if (isset($input['live_agent_status'])) {
        $new_input['live_agent_status'] = ($input['live_agent_status'] === 'on') ? 'on' : 'off';
    }
    if (isset($input['live_agent_away_message'])) {
        $new_input['live_agent_away_message'] = sanitize_textarea_field($input['live_agent_away_message']);
    }
    if (isset($input['live_agent_notification_message'])) {
        $new_input['live_agent_notification_message'] = sanitize_textarea_field($input['live_agent_notification_message']);
    }

    // Telegram Integration
    if (isset($input['telegram_status'])) {
        $new_input['telegram_status'] = ($input['telegram_status'] === 'on') ? 'on' : 'off';
    }
    if (isset($input['telegram_bot_token'])) {
        $new_input['telegram_bot_token'] = sanitize_text_field($input['telegram_bot_token']);
    }
    if (isset($input['telegram_group_id'])) {
        $new_input['telegram_group_id'] = sanitize_text_field($input['telegram_group_id']);
    }
    if (isset($input['telegram_webhook_secret'])) {
        $new_input['telegram_webhook_secret'] = sanitize_text_field($input['telegram_webhook_secret']);
    }
    if (isset($input['telegram_notification_message'])) {
        $new_input['telegram_notification_message'] = sanitize_textarea_field($input['telegram_notification_message']);
    }
    if (isset($input['telegram_away_message'])) {
        $new_input['telegram_away_message'] = sanitize_textarea_field($input['telegram_away_message']);
    }

    // Sanitize script loading strategy
    if (isset($input['script_loading_strategy'])) {
        $allowed_strategies = array('default', 'defer', 'delay_1s', 'delay_3s', 'delay_5s', 'on_interaction');
        $new_input['script_loading_strategy'] = in_array($input['script_loading_strategy'], $allowed_strategies)
            ? $input['script_loading_strategy']
            : 'default';
    }

    // Sanitize debug mode
    if (isset($input['debug_mode'])) {
        $new_input['debug_mode'] = ($input['debug_mode'] === 'on') ? 'on' : 'off';
    }

    // Content Generator Settings
    if (isset($input['content_model'])) {
        $new_input['content_model'] = sanitize_text_field($input['content_model']);
    }
    if (isset($input['content_image_model'])) {
        $new_input['content_image_model'] = sanitize_text_field($input['content_image_model']);
    }
    if (isset($input['content_image_quality'])) {
        $q = sanitize_text_field($input['content_image_quality']);
        $new_input['content_image_quality'] = in_array($q, array('auto', 'low', 'medium', 'high'), true) ? $q : 'auto';
    }
    if (isset($input['content_enable_images'])) {
        $new_input['content_enable_images'] = ($input['content_enable_images'] === 'on') ? 'on' : 'off';
    }
    if (isset($input['content_use_placeholders'])) {
        $new_input['content_use_placeholders'] = ($input['content_use_placeholders'] === 'on') ? 'on' : 'off';
    }
    if (isset($input['content_internal_linking'])) {
        $new_input['content_internal_linking'] = ($input['content_internal_linking'] === 'on') ? 'on' : 'off';
    }
    if (isset($input['content_tool_use'])) {
        $new_input['content_tool_use'] = ($input['content_tool_use'] === 'on') ? 'on' : 'off';
    }
    if (isset($input['content_image_count'])) {
        $new_input['content_image_count'] = (string) max(1, min(5, (int) $input['content_image_count']));
    }

    // SEO Optimize toggle fields
    foreach (array('seo_optimize_meta_desc', 'seo_optimize_seo_title', 'seo_optimize_slug', 'seo_optimize_readability', 'seo_optimize_internal_links', 'seo_optimize_img_alt', 'seo_optimize_featured_img') as $seo_key) {
        if (isset($input[$seo_key])) {
            $new_input[$seo_key] = ($input[$seo_key] === 'on') ? 'on' : 'off';
        }
    }

    // Preserve content generator settings when saving from main settings page
    // (where content fields are not in the form submission)
    $existing = get_option('mxchat_options', array());
    foreach (array('content_model', 'content_image_model', 'content_image_quality', 'content_image_count', 'content_enable_images', 'content_use_placeholders', 'content_internal_linking', 'content_tool_use', 'seo_optimize_meta_desc', 'seo_optimize_seo_title', 'seo_optimize_slug', 'seo_optimize_readability', 'seo_optimize_internal_links', 'seo_optimize_img_alt', 'seo_optimize_featured_img') as $key) {
        if (!isset($new_input[$key]) && isset($existing[$key])) {
            $new_input[$key] = $existing[$key];
        }
    }

    return $new_input;
}

/**
 * Log a debug message if debug mode is enabled
 *
 * @param string $type    The type of log entry (settings_save, api_error, activation, etc.)
 * @param string $message The log message
 * @param array  $data    Optional additional data to log
 * @return bool Whether the message was logged
 */
public static function mxchat_log_debug( $type, $message, $data = array() ) {
    $options = get_option( 'mxchat_options', array() );

    // Check if debug mode is enabled
    if ( ! isset( $options['debug_mode'] ) || $options['debug_mode'] !== 'on' ) {
        return false;
    }

    // Get current log
    $log = get_option( 'mxchat_debug_log', array() );
    if ( ! is_array( $log ) ) {
        $log = array();
    }

    // Add new entry
    $entry = array(
        'time'    => current_time( 'Y-m-d H:i:s' ),
        'type'    => sanitize_key( $type ),
        'message' => sanitize_text_field( $message ),
    );

    if ( ! empty( $data ) ) {
        $entry['data'] = $data;
    }

    // Add to beginning of array (newest first)
    array_unshift( $log, $entry );

    // Keep only last 100 entries
    if ( count( $log ) > 100 ) {
        $log = array_slice( $log, 0, 100 );
    }

    // Save log
    update_option( 'mxchat_debug_log', $log, false );

    return true;
}

/**
 * Get the debug log entries
 *
 * @return array The debug log entries
 */
public static function mxchat_get_debug_log() {
    $log = get_option( 'mxchat_debug_log', array() );
    return is_array( $log ) ? $log : array();
}

/**
 * Clear the debug log
 *
 * @return bool Whether the log was cleared
 */
public static function mxchat_clear_debug_log() {
    return delete_option( 'mxchat_debug_log' );
}

/**
 * Export settings as JSON with masked API keys
 *
 * @return array The sanitized settings array
 */
public static function mxchat_export_settings() {
    $options = get_option( 'mxchat_options', array() );

    if ( ! is_array( $options ) ) {
        return array();
    }

    // List of API key fields to mask
    $api_key_fields = array(
        'api_key',
        'xai_api_key',
        'claude_api_key',
        'deepseek_api_key',
        'voyage_api_key',
        'gemini_api_key',
        'openrouter_api_key',
        'loops_api_key',
        'brave_api_key',
        'live_agent_secret_key',
        'live_agent_bot_token',
        'telegram_bot_token',
        'telegram_webhook_secret',
        'woocommerce_consumer_key',
        'woocommerce_consumer_secret',
    );

    // Mask API keys (show only last 4 characters)
    foreach ( $api_key_fields as $field ) {
        if ( isset( $options[ $field ] ) && ! empty( $options[ $field ] ) ) {
            $value = $options[ $field ];
            if ( strlen( $value ) > 4 ) {
                $options[ $field ] = str_repeat( '*', strlen( $value ) - 4 ) . substr( $value, -4 );
            } else {
                $options[ $field ] = '****';
            }
        }
    }

    // Add metadata
    $export = array(
        'plugin_version' => defined( 'MXCHAT_VERSION' ) ? MXCHAT_VERSION : 'unknown',
        'export_date'    => current_time( 'Y-m-d H:i:s' ),
        'wordpress_version' => get_bloginfo( 'version' ),
        'php_version'    => phpversion(),
        'settings'       => $options,
    );

    return $export;
}

/**
 * Reset all settings to defaults
 *
 * @return bool Whether the reset was successful
 */
public static function mxchat_reset_all_settings() {
    // Delete main options
    $deleted = delete_option( 'mxchat_options' );

    // Also clear the debug log
    delete_option( 'mxchat_debug_log' );

    // Log the reset (will create new log since we just cleared it)
    // We need to temporarily enable debug mode to log this
    $temp_options = array( 'debug_mode' => 'on' );
    update_option( 'mxchat_options', $temp_options );

    self::mxchat_log_debug( 'reset', 'All settings have been reset to defaults' );

    // Now delete again to trigger re-initialization
    delete_option( 'mxchat_options' );

    return $deleted;
}

    // Method to append the chatbot to the body
    public function mxchat_append_chatbot_to_body() {
        $options = get_option('mxchat_options');
        if (isset($options['append_to_body']) && $options['append_to_body'] === 'on') {
            echo do_shortcode('[mxchat_chatbot floating="yes"]');
        }
    }





private function mxchat_fetch_loops_mailing_lists($api_key) {
    $url = 'https://app.loops.so/api/v1/lists';
    $response = wp_remote_get($url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        )
    ));

    if (is_wp_error($response)) {
        return array();
    }

    $body = wp_remote_retrieve_body($response);
    $lists = json_decode($body, true);

    return isset($lists) && is_array($lists) ? $lists : array();
}

function mxchat_calculate_cosine_similarity($vec1, $vec2) {
    if (empty($vec1) || empty($vec2)) {
        return 0.0;
    }

    $dot_product = 0.0;
    $norm_a = 0.0;
    $norm_b = 0.0;

    for ($i = 0; $i < count($vec1); $i++) {
        $dot_product += $vec1[$i] * $vec2[$i];
        $norm_a += pow($vec1[$i], 2);
        $norm_b += pow($vec2[$i], 2);
    }

    if ($norm_a == 0.0 || $norm_b == 0.0) {
        return 0.0;
    } else {
        return $dot_product / (sqrt($norm_a) * sqrt($norm_b));
    }
}

/**
 * Validates nonce and deletes all chat prompts
 */
public function mxchat_handle_delete_all_prompts() {
    //error_log('=== DELETE ALL DEBUG START ===');
    
    // Verify nonce
    if (!isset($_POST['mxchat_delete_all_prompts_nonce']) || !wp_verify_nonce($_POST['mxchat_delete_all_prompts_nonce'], 'mxchat_delete_all_prompts_action')) {
        //error_log('DEBUG: Nonce verification failed');
        wp_die(__('Nonce verification failed.', 'mxchat'));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        //error_log('DEBUG: Permission check failed');
        wp_die(__('You do not have sufficient permissions to delete all prompts.', 'mxchat'));
    }
    
    // Get bot_id and content type filter from POST data
    $bot_id = isset($_POST['bot_id']) ? sanitize_text_field($_POST['bot_id']) : 'default';
    $content_type_filter = isset($_POST['content_type_filter']) ? sanitize_text_field($_POST['content_type_filter']) : '';

    $success = true;
    $error_messages = array();

    // Get bot-specific Pinecone configuration
    $pinecone_manager = MxChat_Pinecone_Manager::get_instance();
    $pinecone_options = $pinecone_manager->mxchat_get_bot_pinecone_options($bot_id);

    $use_pinecone = ($pinecone_options['mxchat_use_pinecone'] ?? '0') === '1';

    if ($use_pinecone && !empty($pinecone_options['mxchat_pinecone_api_key'])) {
        // Delete from Pinecone (with optional content type filter)
        $result = $pinecone_manager->mxchat_delete_all_from_pinecone($pinecone_options, $content_type_filter);

        if (!$result['success']) {
            $success = false;
            $error_messages[] = $result['message'];
        }

    } else {
        // Delete from WordPress database
        global $wpdb;
        $table_name = $wpdb->prefix . 'mxchat_system_prompt_content';

        // Build WHERE conditions
        $where_clauses = array();
        $where_values = array();

        // Bot filter
        if ($bot_id !== 'default' && class_exists('MxChat_Multi_Bot_Manager')) {
            $where_clauses[] = 'bot_id = %s';
            $where_values[] = $bot_id;
        }

        // Content type filter
        if (!empty($content_type_filter)) {
            $where_clauses[] = 'content_type = %s';
            $where_values[] = $content_type_filter;
        }

        if (!empty($where_clauses)) {
            $where_sql = implode(' AND ', $where_clauses);
            $result = $wpdb->query($wpdb->prepare("DELETE FROM {$table_name} WHERE {$where_sql}", $where_values));
        } else {
            // No filters — delete all
            $result = $wpdb->query("DELETE FROM {$table_name}");
        }

        if ($result === false) {
            $success = false;
            $error_messages[] = 'Failed to delete from WordPress database';
        }
    }

    // Redirect back with a success message and bot_id
    $redirect_url = add_query_arg(array(
        'page' => 'mxchat-prompts',
        'bot_id' => $bot_id,
        'all_deleted' => $success ? 'true' : 'false'
    ), admin_url('admin.php'));
    
    wp_safe_redirect($redirect_url);
    exit;
}



    /**
     * Handles deletion prompt with nonce validation
     */
     public function mxchat_handle_delete_prompt() {
         // Sanitize and validate nonce
         $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
         if (empty($nonce) || !wp_verify_nonce($nonce, 'mxchat_delete_prompt_nonce')) {
             wp_die(esc_html__('Nonce verification failed.', 'mxchat'));
         }

         // Check permissions
         if (!current_user_can('manage_options')) {
             wp_die(esc_html__('You do not have sufficient permissions to delete prompts.', 'mxchat'));
         }

         // Get ID and source parameters
         $id = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';
         $source = isset($_GET['source']) ? sanitize_text_field($_GET['source']) : '';

         if (empty($id)) {
             wp_die(esc_html__('Invalid prompt ID.', 'mxchat'));
         }

         $success = false;
         $error_message = '';

         // Check if Pinecone is enabled and determine source automatically if not specified
         $pinecone_options = get_option('mxchat_pinecone_addon_options', array());
         $use_pinecone = ($pinecone_options['mxchat_use_pinecone'] ?? '0') === '1';

         // If source is not specified, determine based on Pinecone configuration
         if (empty($source)) {
             $source = $use_pinecone ? 'pinecone' : 'wordpress';
         }

         if ($source === 'pinecone' || $use_pinecone) {
             //error_log('[MXCHAT-DELETE] Deleting from Pinecone, ID: ' . $id);

             // Handle Pinecone deletion
             if (empty($pinecone_options['mxchat_pinecone_host']) ||
                 empty($pinecone_options['mxchat_pinecone_api_key'])) {
                 wp_die(esc_html__('Pinecone configuration is missing.', 'mxchat'));
             }

             // Delete from Pinecone using the vector ID directly
                $pinecone_manager = MxChat_Pinecone_Manager::get_instance();
                $result = $pinecone_manager->mxchat_delete_from_pinecone_by_vector_id(
                    $id,
                    $pinecone_options['mxchat_pinecone_api_key'],
                    $pinecone_options['mxchat_pinecone_host']
                );
             if ($result['success']) {
                 $success = true;

                 // Remove from vector cache
                    $pinecone_manager->mxchat_remove_from_pinecone_vector_cache($id);
                    $pinecone_manager->mxchat_remove_from_processed_content_caches($id);

                 set_transient('mxchat_admin_notice_success',
                     esc_html__('Vector deleted successfully from Pinecone.', 'mxchat'), 30);
             } else {
                 $error_message = $result['message'];
                 set_transient('mxchat_admin_notice_error',
                     esc_html__('Failed to delete from Pinecone: ', 'mxchat') . esc_html($error_message), 30);
             }
         } else {
             //error_log('[MXCHAT-DELETE] Deleting from WordPress database, ID: ' . $id);

             // Handle WordPress database deletion
             global $wpdb;
             $table_name = $wpdb->prefix . 'mxchat_system_prompt_content';

             // Clear cache and delete prompt
             wp_cache_delete('prompt_' . $id, 'mxchat_prompts');

             $result = $wpdb->delete(
                 $table_name,
                 array('id' => intval($id)),
                 array('%d')
             );

             if ($result !== false) {
                 $success = true;
                 set_transient('mxchat_admin_notice_success',
                     esc_html__('Entry deleted successfully.', 'mxchat'), 30);
             } else {
                 set_transient('mxchat_admin_notice_error',
                     esc_html__('Failed to delete entry from database.', 'mxchat'), 30);
             }
         }

         // Redirect back to the prompts page
         wp_safe_redirect(add_query_arg(
             array(
                 'page' => 'mxchat-prompts',
                 'deleted' => $success ? 'true' : 'false'
             ),
             admin_url('admin.php')
         ));
         exit;
     }




        /**
     * Averages multiple vectors into a single vector
     */
     private function mxchat_average_vectors($vectors) {
         $vector_length = count($vectors[0]);
         $sum_vector = array_fill(0, $vector_length, 0);

         foreach ($vectors as $vector) {
             for ($i = 0; $i < $vector_length; $i++) {
                 $sum_vector[$i] += $vector[$i];
             }
         }

         // Divide each component by the number of vectors to get the average
         $num_vectors = count($vectors);
         for ($i = 0; $i < $vector_length; $i++) {
             $sum_vector[$i] /= $num_vectors;
         }

         return $sum_vector;
     }




         /**
     * Generates embeddings from input text for MXChat
     */
     public function mxchat_generate_embedding($text) {
         // Enable detailed logging for debugging
         //error_log('[MXCHAT-EMBED] Starting embedding generation. Text length: ' . strlen($text) . ' bytes');
         //error_log('[MXCHAT-EMBED] Text preview: ' . substr($text, 0, 100) . '...');

         $options = get_option('mxchat_options');
         $selected_model = $options['embedding_model'] ?? 'text-embedding-ada-002';
         //error_log('[MXCHAT-EMBED] Selected embedding model: ' . $selected_model);

         // Determine provider and endpoint
         if (strpos($selected_model, 'voyage') === 0) {
             $api_key = $options['voyage_api_key'] ?? '';
             $endpoint = 'https://api.voyageai.com/v1/embeddings';
             $provider_name = 'Voyage AI';
             //error_log('[MXCHAT-EMBED] Using Voyage AI API');
         } elseif (strpos($selected_model, 'gemini-embedding') === 0) {
             $api_key = $options['gemini_api_key'] ?? '';
             $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $selected_model . ':embedContent';
             $provider_name = 'Google Gemini';
             //error_log('[MXCHAT-EMBED] Using Google Gemini API');
         } else {
             $api_key = $options['api_key'] ?? '';
             $endpoint = 'https://api.openai.com/v1/embeddings';
             $provider_name = 'OpenAI';
             //error_log('[MXCHAT-EMBED] Using OpenAI API');
         }

         //error_log('[MXCHAT-EMBED] Using endpoint: ' . $endpoint);

         if (empty($api_key)) {
             $error_message = sprintf('Missing %s API key. Please configure your API key in the MxChat settings.', $provider_name);
             //error_log('[MXCHAT-EMBED] Error: ' . $error_message);
             return $error_message;
         }

         // Check if text is too long (for OpenAI, roughly estimate tokens as words/0.75)
         $estimated_tokens = ceil(str_word_count($text) / 0.75);
         //error_log('[MXCHAT-EMBED] Estimated token count: ~' . $estimated_tokens);

         if ($estimated_tokens > 8000 && strpos($selected_model, 'voyage') === false && strpos($selected_model, 'gemini-embedding') === false) {
             //error_log('[MXCHAT-EMBED] Warning: Text may exceed OpenAI token limits (8K for most models)');
             // Consider truncating text here
         }

         // Prepare request body based on provider
         if (strpos($selected_model, 'gemini-embedding') === 0) {
             // Gemini API format
             $request_body = array(
                 'model' => 'models/' . $selected_model,
                 'content' => array(
                     'parts' => array(
                         array('text' => $text)
                     )
                 )
             );

             // Set output dimensionality to 1536 for consistency with other models
             $request_body['outputDimensionality'] = 1536;
         } else {
             // OpenAI/Voyage API format
             $request_body = array(
                 'model' => $selected_model,
                 'input' => $text
             );

             // Add output_dimension for voyage-3-large model
             if ($selected_model === 'voyage-3-large') {
                 $request_body['output_dimension'] = 2048;
             }
         }

         //error_log('[MXCHAT-EMBED] Request prepared with model: ' . $selected_model);

         // Prepare headers based on provider
         if (strpos($selected_model, 'gemini-embedding') === 0) {
             // Gemini uses API key as query parameter
             $endpoint .= '?key=' . $api_key;
             $headers = array(
                 'Content-Type' => 'application/json'
             );
         } else {
             // OpenAI/Voyage use Bearer token
             $headers = array(
                 'Authorization' => 'Bearer ' . $api_key,
                 'Content-Type' => 'application/json'
             );
         }

         // Make API request
         //error_log('[MXCHAT-EMBED] Sending API request to: ' . $endpoint);
         $response = wp_remote_post($endpoint, array(
             'body' => wp_json_encode($request_body),
             'headers' => $headers,
             'timeout' => 60 // Increased timeout for large inputs
         ));

         // Handle wp_remote_post errors
         if (is_wp_error($response)) {
             $error_message = $response->get_error_message();
             //error_log('[MXCHAT-EMBED] WP Remote Post Error: ' . $error_message);
             return 'Connection error: ' . $error_message;
         }

         // Get and check HTTP response code
         $http_code = wp_remote_retrieve_response_code($response);
         //error_log('[MXCHAT-EMBED] API Response Code: ' . $http_code);

         if ($http_code !== 200) {
             $error_body = wp_remote_retrieve_body($response);
             //error_log('[MXCHAT-EMBED] API Error Response Body: ' . $error_body);

             // Try to parse error for more details
             $error_json = json_decode($error_body, true);
             if (json_last_error() === JSON_ERROR_NONE && isset($error_json['error'])) {
                 $error_type = $error_json['error']['type'] ?? 'unknown';
                 $error_message = $error_json['error']['message'] ?? 'No message';
                 //error_log('[MXCHAT-EMBED] API Error Type: ' . $error_type);
                 //error_log('[MXCHAT-EMBED] API Error Message: ' . $error_message);

                 // Customize error message for common API errors
                 if ($error_type === 'invalid_request_error' && strpos($error_message, 'API key') !== false) {
                     $error_message = sprintf('Invalid %s API key. Please check your API key in the MxChat settings.', $provider_name);
                 } elseif ($error_type === 'authentication_error') {
                     $error_message = sprintf('%s authentication failed. Please verify your API key in the MxChat settings.', $provider_name);
                 }

                 //error_log('[MXCHAT-EMBED] Returning error: ' . $error_message);
                 return $error_message;
             }

             $error_message = sprintf("API Error (HTTP %d): Unable to generate embedding", $http_code);
             //error_log('[MXCHAT-EMBED] Returning error: ' . $error_message);
             return $error_message;
         }

         // Parse response body
         $response_body = wp_remote_retrieve_body($response);
         //error_log('[MXCHAT-EMBED] Received response length: ' . strlen($response_body) . ' bytes');

         $response_data = json_decode($response_body, true);

         if (json_last_error() !== JSON_ERROR_NONE) {
             $error = json_last_error_msg();
             //error_log('[MXCHAT-EMBED] JSON Parse Error: ' . $error);
             //error_log('[MXCHAT-EMBED] Response preview: ' . substr($response_body, 0, 200));
             return "Failed to parse API response: $error";
         }

         // Handle different response formats based on provider
         if (strpos($selected_model, 'gemini-embedding') === 0) {
             // Gemini API response format
             if (isset($response_data['embedding']['values'])) {
                 $embedding_dimensions = count($response_data['embedding']['values']);
                 //error_log('[MXCHAT-EMBED] Successfully extracted Gemini embedding with ' . $embedding_dimensions . ' dimensions');

                 // Check if embedding dimensions are as expected (should be 1536)
                 if ($embedding_dimensions !== 1536) {
                     //error_log('[MXCHAT-EMBED] Warning: Unexpected Gemini embedding dimensions: ' . $embedding_dimensions);
                 }

                 MxChat_Utils::stamp_active_embedding_model($selected_model);
                 return $response_data['embedding']['values'];
             } else {
                 //error_log('[MXCHAT-EMBED] Error: No embedding found in Gemini response');
                 //error_log('[MXCHAT-EMBED] Response structure: ' . wp_json_encode(array_keys($response_data)));

                 if (isset($response_data['error'])) {
                     $error_message = "Gemini API Error in response: " . wp_json_encode($response_data['error']);
                     //error_log('[MXCHAT-EMBED] ' . $error_message);
                     return $error_message;
                 }

                 $error_message = "Invalid Gemini API response format: No embedding found";
                 //error_log('[MXCHAT-EMBED] ' . $error_message);
                 return $error_message;
             }
         } else {
             // OpenAI/Voyage API response format
             if (isset($response_data['data'][0]['embedding'])) {
                 $embedding_dimensions = count($response_data['data'][0]['embedding']);
                 //error_log('[MXCHAT-EMBED] Successfully extracted embedding with ' . $embedding_dimensions . ' dimensions');

                 // Check if embedding dimensions are as expected
                 if (($selected_model === 'text-embedding-ada-002' && $embedding_dimensions !== 1536) ||
                     ($selected_model === 'voyage-3-large' && $embedding_dimensions !== 2048)) {
                     //error_log('[MXCHAT-EMBED] Warning: Unexpected embedding dimensions');
                 }

                 MxChat_Utils::stamp_active_embedding_model($selected_model);
                 return $response_data['data'][0]['embedding'];
             } else {
                 //error_log('[MXCHAT-EMBED] Error: No embedding found in response');
                 //error_log('[MXCHAT-EMBED] Response structure: ' . wp_json_encode(array_keys($response_data)));

                 if (isset($response_data['error'])) {
                     $error_message = "API Error in response: " . wp_json_encode($response_data['error']);
                     //error_log('[MXCHAT-EMBED] ' . $error_message);
                     return $error_message;
                 }

                 $error_message = "Invalid API response format: No embedding found";
                 //error_log('[MXCHAT-EMBED] ' . $error_message);
                 return $error_message;
             }
         }
     }



}
?>
