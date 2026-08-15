<?php
/**
 * MxChat Admin Settings Page - Redesigned with Sidebar Navigation
 *
 * @package MxChat
 * @since 2.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render the new sidebar-based admin settings page
 */
function mxchat_render_settings_page($admin_instance) {
    $is_activated = $admin_instance->is_activated();
    $options = get_option('mxchat_options', array());
    $plugin_url = plugin_dir_url(dirname(__FILE__));
    ?>
    <div class="mxch-admin-wrapper">
        <!-- Mobile Header -->
        <header class="mxch-mobile-header">
            <a href="#" class="mxch-mobile-logo">
                <div class="mxch-mobile-logo-icon">
                    <img src="<?php echo esc_url($plugin_url . 'images/icon-128x128.png'); ?>" alt="MxChat">
                </div>
                <span class="mxch-mobile-logo-text">MxChat</span>
            </a>
            <button type="button" class="mxch-mobile-menu-btn" aria-label="<?php esc_attr_e('Open menu', 'mxchat'); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
            </button>
        </header>

        <!-- Mobile Menu Overlay -->
        <div class="mxch-mobile-overlay"></div>

        <!-- Mobile Menu Modal -->
        <div class="mxch-mobile-menu">
            <div class="mxch-mobile-menu-header">
                <span class="mxch-mobile-menu-title"><?php esc_html_e('Settings', 'mxchat'); ?></span>
                <button type="button" class="mxch-mobile-menu-close" aria-label="<?php esc_attr_e('Close menu', 'mxchat'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <nav class="mxch-mobile-menu-nav">
                <!-- Configuration Section -->
                <div class="mxch-mobile-nav-section">
                    <div class="mxch-mobile-nav-section-title"><?php esc_html_e('Configuration', 'mxchat'); ?></div>
                    <button class="mxch-mobile-nav-link" data-parent="chatbot">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                        <span><?php esc_html_e('Chatbot', 'mxchat'); ?></span>
                        <svg class="mxch-mobile-nav-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </button>
                    <div class="mxch-mobile-nav-sub" data-parent="chatbot">
                        <button class="mxch-mobile-nav-sub-link active" data-target="chatbot-ai-models"><?php esc_html_e('AI Models', 'mxchat'); ?></button>
                        <button class="mxch-mobile-nav-sub-link" data-target="chatbot-behavior"><?php esc_html_e('Behavior', 'mxchat'); ?></button>
                        <button class="mxch-mobile-nav-sub-link" data-target="chatbot-display"><?php esc_html_e('Display', 'mxchat'); ?></button>
                        <button class="mxch-mobile-nav-sub-link" data-target="chatbot-lead-capture"><?php esc_html_e('Lead Capture', 'mxchat'); ?></button>
                        <button class="mxch-mobile-nav-sub-link" data-target="chatbot-quick-questions"><?php esc_html_e('Quick Questions', 'mxchat'); ?></button>
                        <button class="mxch-mobile-nav-sub-link" data-target="chatbot-rate-limits"><?php esc_html_e('Rate Limits', 'mxchat'); ?></button>
                    </div>
                    <button class="mxch-mobile-nav-link" data-target="api-keys">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 2-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                        <span><?php esc_html_e('API Keys', 'mxchat'); ?></span>
                    </button>
                    <button class="mxch-mobile-nav-link" data-target="optimization">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                        <span><?php esc_html_e('Optimization', 'mxchat'); ?></span>
                    </button>
                    <button class="mxch-mobile-nav-link" data-target="testing">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v6"/><path d="M15 2v6"/><path d="M12 17v5"/><path d="M5 8h14"/><path d="M6 11v-1a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"/><path d="m9 14 3 3 3-3"/></svg>
                        <span><?php esc_html_e('Testing', 'mxchat'); ?></span>
                    </button>
                </div>
                <!-- Integrations Section -->
                <div class="mxch-mobile-nav-section">
                    <div class="mxch-mobile-nav-section-title"><?php esc_html_e('Integrations', 'mxchat'); ?></div>
                    <button class="mxch-mobile-nav-link" data-parent="integrations">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        <span><?php esc_html_e('Integrations', 'mxchat'); ?></span>
                        <svg class="mxch-mobile-nav-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </button>
                    <div class="mxch-mobile-nav-sub" data-parent="integrations">
                        <button class="mxch-mobile-nav-sub-link" data-target="integrations-toolbar"><?php esc_html_e('Toolbar', 'mxchat'); ?></button>
                        <button class="mxch-mobile-nav-sub-link" data-target="integrations-loops"><?php esc_html_e('Loops', 'mxchat'); ?></button>
                        <button class="mxch-mobile-nav-sub-link" data-target="integrations-brave"><?php esc_html_e('Brave Search', 'mxchat'); ?></button>
                        <button class="mxch-mobile-nav-sub-link" data-target="integrations-slack"><?php esc_html_e('Slack', 'mxchat'); ?></button>
                        <button class="mxch-mobile-nav-sub-link" data-target="integrations-telegram"><?php esc_html_e('Telegram', 'mxchat'); ?></button>
                    </div>
                </div>
                <!-- Resources Section -->
                <div class="mxch-mobile-nav-section">
                    <div class="mxch-mobile-nav-section-title"><?php esc_html_e('Resources', 'mxchat'); ?></div>
                    <button class="mxch-mobile-nav-link" data-target="tutorials">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                        <span><?php esc_html_e('Tutorials', 'mxchat'); ?></span>
                    </button>
                </div>
                <?php if (!$is_activated): ?>
                <div class="mxch-mobile-menu-footer">
                    <a href="https://mxchat.ai/" target="_blank" class="mxch-mobile-upgrade-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                        <?php esc_html_e('Upgrade to Pro', 'mxchat'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </nav>
        </div>

        <!-- Sidebar Navigation -->
        <aside class="mxch-sidebar">
            <div class="mxch-sidebar-header">
                <a href="#" class="mxch-sidebar-logo">
                    <div class="mxch-sidebar-logo-icon">
                        <img src="<?php echo esc_url($plugin_url . 'images/icon-128x128.png'); ?>" alt="MxChat">
                    </div>
                    <span class="mxch-sidebar-logo-text">MxChat</span>
                    <span class="mxch-sidebar-version">v<?php echo esc_html(MXCHAT_VERSION ?? '2.7.0'); ?></span>
                </a>
            </div>

            <nav class="mxch-sidebar-nav">
                <!-- Chatbot Section -->
                <div class="mxch-nav-section">
                    <div class="mxch-nav-section-title"><?php esc_html_e('Configuration', 'mxchat'); ?></div>

                    <div class="mxch-nav-item expanded" data-section="chatbot">
                        <button class="mxch-nav-link active">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Chatbot', 'mxchat'); ?></span>
                            <svg class="mxch-nav-link-arrow" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </button>
                        <div class="mxch-nav-sub">
                            <button class="mxch-nav-sub-link active" data-target="chatbot-ai-models"><?php esc_html_e('AI Models', 'mxchat'); ?></button>
                            <button class="mxch-nav-sub-link" data-target="chatbot-behavior"><?php esc_html_e('Behavior', 'mxchat'); ?></button>
                            <button class="mxch-nav-sub-link" data-target="chatbot-display"><?php esc_html_e('Display', 'mxchat'); ?></button>
                            <button class="mxch-nav-sub-link" data-target="chatbot-lead-capture"><?php esc_html_e('Lead Capture', 'mxchat'); ?></button>
                            <button class="mxch-nav-sub-link" data-target="chatbot-quick-questions"><?php esc_html_e('Quick Questions', 'mxchat'); ?></button>
                            <button class="mxch-nav-sub-link" data-target="chatbot-rate-limits"><?php esc_html_e('Rate Limits', 'mxchat'); ?></button>
                        </div>
                    </div>

                    <div class="mxch-nav-item" data-section="api-keys">
                        <button class="mxch-nav-link" data-target="api-keys">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 2-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('API Keys', 'mxchat'); ?></span>
                        </button>
                    </div>

                    <div class="mxch-nav-item" data-section="optimization">
                        <button class="mxch-nav-link" data-target="optimization">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Optimization', 'mxchat'); ?></span>
                        </button>
                    </div>

                    <div class="mxch-nav-item" data-section="testing">
                        <button class="mxch-nav-link" data-target="testing">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v6"/><path d="M15 2v6"/><path d="M12 17v5"/><path d="M5 8h14"/><path d="M6 11v-1a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"/><path d="m9 14 3 3 3-3"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Testing', 'mxchat'); ?></span>
                        </button>
                    </div>
                </div>

                <!-- Integrations Section -->
                <div class="mxch-nav-section">
                    <div class="mxch-nav-section-title"><?php esc_html_e('Integrations', 'mxchat'); ?></div>

                    <div class="mxch-nav-item" data-section="integrations">
                        <button class="mxch-nav-link">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Integrations', 'mxchat'); ?></span>
                            <svg class="mxch-nav-link-arrow" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </button>
                        <div class="mxch-nav-sub">
                            <button class="mxch-nav-sub-link" data-target="integrations-toolbar"><?php esc_html_e('Toolbar', 'mxchat'); ?></button>
                            <button class="mxch-nav-sub-link" data-target="integrations-loops"><?php esc_html_e('Loops', 'mxchat'); ?></button>
                            <button class="mxch-nav-sub-link" data-target="integrations-brave"><?php esc_html_e('Brave Search', 'mxchat'); ?></button>
                            <button class="mxch-nav-sub-link" data-target="integrations-slack"><?php esc_html_e('Slack', 'mxchat'); ?></button>
                            <button class="mxch-nav-sub-link" data-target="integrations-telegram"><?php esc_html_e('Telegram', 'mxchat'); ?></button>
                        </div>
                    </div>
                </div>

                <!-- Resources Section -->
                <div class="mxch-nav-section">
                    <div class="mxch-nav-section-title"><?php esc_html_e('Resources', 'mxchat'); ?></div>

                    <div class="mxch-nav-item" data-section="tutorials">
                        <button class="mxch-nav-link" data-target="tutorials">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Tutorials', 'mxchat'); ?></span>
                        </button>
                    </div>
                </div>

            </nav>

            <?php if (!$is_activated): ?>
            <div class="mxch-sidebar-footer">
                <a href="https://mxchat.ai/" target="_blank" class="mxch-sidebar-upgrade-v2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <?php esc_html_e('Pro Upgrade', 'mxchat'); ?>
                </a>
            </div>
            <?php endif; ?>
        </aside>

        <!-- Main Content Area -->
        <main class="mxch-content">
            <?php if (!$is_activated): ?>
            <div class="mxch-pro-banner">
                <div class="mxch-pro-banner-content">
                    <h3 class="mxch-pro-banner-title"><?php esc_html_e('Stop paying $50–200/month for AI chatbot tools.', 'mxchat'); ?></h3>
                    <p class="mxch-pro-banner-text"><?php esc_html_e('One payment unlocks every MxChat Pro add-on for life — MCP server access for Claude / ChatGPT / Claude Code, WooCommerce sales, Google Search Console insights, AI-generated chatbot themes, lead-capture forms, unlimited bots, image analysis, and more. No renewals. No surprise bills.', 'mxchat'); ?></p>
                </div>
                <a href="https://mxchat.ai/" target="_blank" class="mxch-pro-banner-btn"><span><?php esc_html_e('Get Lifetime Access →', 'mxchat'); ?></span></a>
            </div>
            <?php endif; ?>

            <?php
            // Show theme migration notice for Pro users
            $admin_instance->show_theme_migration_banner();
            ?>

            <!-- ========================================
                 CHATBOT - AI MODELS
                 ======================================== -->
            <div id="chatbot-ai-models" class="mxch-section active">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('AI Models', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Configure the AI models your chatbot will use for conversations and embeddings.', 'mxchat'); ?></p>
                </div>

                <?php
                // Model-retirement warning (b65e8d). Rendered HERE rather than via
                // admin_notices because the branded shell paints over WP's notice
                // region — a core notice on this screen is invisible. No-ops unless
                // the daily liveness check has flagged the configured model.
                if (class_exists('MxChat_Model_Liveness')) {
                    MxChat_Model_Liveness::render_inline_notice();
                }
                ?>

                <div class="mxch-card">
                    <div class="mxch-card-body mxchat-autosave-section">
                        <?php
                        // Chat Model
                        mxchat_render_field_wrapper('model', __('Chat Model', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_model_callback();
                        }, __('Select the AI model your chatbot will use for conversations.', 'mxchat'));

                        // Embedding Model
                        mxchat_render_field_wrapper('embedding_model', __('Embedding Model', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->embedding_model_callback();
                        }, __('Vector embedding model for knowledge base matching. Changing models requires reconfiguring knowledge data.', 'mxchat'));

                        // Enable Streaming
                        mxchat_render_field_wrapper('enable_streaming', __('Enable Streaming', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->enable_streaming_toggle_callback();
                        }, __('Enable real-time streaming responses for supported models (OpenAI, Claude, DeepSeek, Grok).', 'mxchat'));

                        // Enable Web Search (OpenAI & Gemini)
                        mxchat_render_field_wrapper('enable_web_search', __('Enable Web Search', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->enable_web_search_toggle_callback();
                        }, __('Allow the chatbot to search the web for current information (OpenAI and Gemini models).', 'mxchat'));
                        ?>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 CHATBOT - BEHAVIOR
                 ======================================== -->
            <div id="chatbot-behavior" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Behavior', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Control how your AI chatbot responds and processes queries.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-body mxchat-autosave-section">
                        <?php
                        // AI Instructions
                        mxchat_render_field_wrapper('system_prompt_instructions', __('AI Instructions (Behavior)', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->system_prompt_instructions_callback();
                        }, __('Provide system-level instructions to guide the AI\'s behavior and responses.', 'mxchat'));

                        // Similarity Threshold
                        mxchat_render_field_wrapper('similarity_threshold', __('Similarity Threshold', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_similarity_threshold_callback();
                        }, __('Adjust threshold for content matching. Lower values = more matches, higher values = stricter matching.', 'mxchat'));

                        // RAG Sources Limit
                        mxchat_render_field_wrapper('rag_sources_limit', __('RAG Sources Limit', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_rag_sources_limit_callback();
                        }, __('Number of knowledge base sources (pages/documents) to include in AI context. Higher values provide more context but use more tokens.', 'mxchat'));

                        // RAG Chunks Limit
                        mxchat_render_field_wrapper('rag_chunks_limit', __('RAG Chunks Limit', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_rag_chunks_limit_callback();
                        }, __('Maximum total content chunks sent to the AI across all sources. Higher values provide more context but increase token usage and cost.', 'mxchat'));

                        // Hybrid keyword boost MOVED to Knowledge → Chunking &
                        // Retrieval (plan 11720c) — it is KB retrieval tuning, not
                        // conversation behavior. Same option key over there. The
                        // breadcrumb below is a one-release courtesy: drop it in
                        // the release after 3.2.17.
                        echo '<p class="mxch-field-description">';
                        printf(
                            /* translators: %s: link to the Chunking & Retrieval settings tab. */
                            esc_html__('Hybrid keyword boost has moved to %s.', 'mxchat'),
                            '<a href="' . esc_url(admin_url('admin.php?page=mxchat-prompts#chunking')) . '">' . esc_html__('Knowledge, Chunking & Retrieval', 'mxchat') . '</a>'
                        );
                        echo '</p>';

                        // Contextual Awareness
                        mxchat_render_field_wrapper('contextual_awareness', __('Contextual Awareness', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_contextual_awareness_callback();
                        }, __('Allow the chatbot to understand and reference the current page content for more relevant responses.', 'mxchat'));

                        // Citation Links
                        mxchat_render_field_wrapper('citation_links', __('Citation Links', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_citation_links_toggle_callback();
                        }, __('Allow the AI to include citation links from your knowledge database in responses. If disabled, ensure your AI Behavior settings do not mention links or the AI may fabricate URLs.', 'mxchat'));

                        // Satisfaction Rating Prompt
                        // The toggle callback inline-renders the 5 customization
                        // fields inside a single sub-options wrapper (plan-29caac).
                        // No separate customization group needed — the wrapper handles
                        // its own server-side display state based on the toggle value.
                        mxchat_render_field_wrapper('satisfaction_rating', __('Satisfaction Rating Prompt', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_satisfaction_rating_toggle_callback();
                        }, __('Show a 👍 / 👎 prompt after a conversation has had a couple of bot replies and the visitor has been idle for a moment. Disable to hide the prompt site-wide.', 'mxchat'));

                        // Editor Assistant toggle moved to Content → Settings (plan-f7df40):
                        // it's a content feature, so it lives on the screen that owns it.
                        // Rendering is in admin-content-page.php; option + save path unchanged.
                        ?>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 CHATBOT - DISPLAY
                 ======================================== -->
            <div id="chatbot-display" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Display Settings', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Customize how and where your chatbot appears on your website.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                            <?php esc_html_e('Visibility', 'mxchat'); ?>
                        </h3>
                    </div>
                    <div class="mxch-card-body mxchat-autosave-section">
                        <?php
                        // Auto-Display
                        mxchat_render_field_wrapper('auto_display', __('Auto-Display Chatbot', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_append_to_body_callback();
                        }, __('Show chatbot automatically on all pages. Disable to use shortcode [mxchat_chatbot floating="yes"] for floating widget or [mxchat_chatbot floating="no"] for embedded chat.', 'mxchat'));

                        // Open Links in New Tab
                        mxchat_render_field_wrapper('link_target', __('Open Links in New Tab', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_link_target_toggle_callback();
                        }, __('Open links clicked in chat responses in a new browser tab.', 'mxchat'));

                        // Chat Persistence
                        mxchat_render_field_wrapper('chat_persistence', __('Chat Persistence', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_chat_persistence_toggle_callback();
                        }, __('Keep chat history when users navigate or return within 24 hours.', 'mxchat'));

                        // Download Transcript button
                        mxchat_render_field_wrapper('print_button', __('Show Download Transcript Button', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_print_button_toggle_callback();
                        }, __('Show the "Download Transcript" item in the chat window menu.', 'mxchat'));

                        // Start-New-Chat button (plan ac2e81) — lets a visitor reset the
                        // conversation without the owner disabling chat persistence globally.
                        mxchat_render_field_wrapper('reset_chat_enabled', __('Show Start-New-Chat Button', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_reset_chat_toggle_callback();
                        }, __('Show a "Start new chat" item in the chat window menu that clears the current conversation and begins a fresh session.', 'mxchat'));

                        mxchat_render_field_wrapper('reset_chat_label', __('Start-New-Chat Button Label', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_reset_chat_label_callback();
                        }, __('Label for the Start-New-Chat menu item. Leave blank to use "Start new chat".', 'mxchat'));
                        ?>
                    </div>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            <?php esc_html_e('Text & Labels', 'mxchat'); ?>
                        </h3>
                    </div>
                    <div class="mxch-card-body mxchat-autosave-section">
                        <?php
                        // Top Bar Title
                        mxchat_render_field_wrapper('top_bar_title', __('Top Bar Title', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_top_bar_title_callback();
                        }, __('Title text shown in the chatbot header.', 'mxchat'));

                        // AI Agent Text
                        mxchat_render_field_wrapper('ai_agent_text', __('AI Agent Text', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_ai_agent_text_callback();
                        }, __('Text shown for AI agents in the status indicator.', 'mxchat'));

                        // Intro Message
                        mxchat_render_field_wrapper('intro_message', __('Introductory Message', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_intro_message_callback();
                        }, __('First message shown when chat opens. HTML allowed.', 'mxchat'));

                        // Input Placeholder
                        mxchat_render_field_wrapper('input_copy', __('Input Placeholder', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_input_copy_callback();
                        }, __('Placeholder text in the chat input field.', 'mxchat'));

                        // Max Input Length (characters) — plan a3fae2 part C
                        mxchat_render_field_wrapper('max_input_length', __('Max Input Length (characters)', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_max_input_length_callback();
                        }, __('Cap how many characters a visitor can type or paste into a single message. Set to 0 for unlimited (default).', 'mxchat'));

                        // Chat Teaser
                        mxchat_render_field_wrapper('pre_chat_message', __('Chat Teaser Pop-up', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_pre_chat_message_callback();
                        }, __('Message shown before users start chatting.', 'mxchat'));
                        ?>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 CHATBOT - LEAD CAPTURE
                 ======================================== -->
            <div id="chatbot-lead-capture" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Lead Capture', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Collect user information and manage privacy settings.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                            <?php esc_html_e('Email Capture', 'mxchat'); ?>
                        </h3>
                    </div>
                    <div class="mxch-card-body mxchat-autosave-section">
                        <?php
                        // Require Email
                        mxchat_render_field_wrapper('enable_email_block', __('Require Email to Chat', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->enable_email_block_callback();
                        }, __('Require users to enter their email before chatting. Shows for non-logged-in users.', 'mxchat'));

                        // Email Form Content
                        mxchat_render_field_wrapper('email_blocker_header', __('Email Form Content', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->email_blocker_header_content_callback();
                        }, __('HTML content shown above the email form.', 'mxchat'));

                        // Email Button Text
                        mxchat_render_field_wrapper('email_blocker_button', __('Submit Button Text', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->email_blocker_button_text_callback();
                        }, __('Text for the email form submit button.', 'mxchat'));

                        // Require Name
                        mxchat_render_field_wrapper('enable_name_field', __('Also Require Name', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->enable_name_field_callback();
                        }, __('Also require users to enter their name with email.', 'mxchat'));

                        // Name Placeholder
                        mxchat_render_field_wrapper('name_placeholder', __('Name Field Placeholder', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->name_field_placeholder_callback();
                        }, __('Placeholder text for the name input field.', 'mxchat'));
                        ?>
                    </div>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            <?php esc_html_e('Privacy & Compliance', 'mxchat'); ?>
                        </h3>
                    </div>
                    <div class="mxch-card-body mxchat-autosave-section">
                        <?php
                        // Privacy Notice
                        mxchat_render_field_wrapper('privacy_toggle', __('Privacy Notice', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_privacy_toggle_callback();
                        }, __('Show privacy notice below the chat widget.', 'mxchat'));

                        // Complianz
                        mxchat_render_field_wrapper('complianz_toggle', __('Complianz Integration', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_complianz_toggle_callback();
                        }, __('Apply Complianz consent logic to the chatbot (requires Complianz plugin).', 'mxchat'));
                        ?>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 CHATBOT - QUICK QUESTIONS
                 ======================================== -->
            <div id="chatbot-quick-questions" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Quick Questions', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Pre-defined questions displayed above the chat input to help users get started.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-body mxchat-autosave-section">
                        <?php
                        // Quick Question 1
                        mxchat_render_field_wrapper('popular_question_1', __('Quick Question 1', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_popular_question_1_callback();
                        });

                        // Quick Question 2
                        mxchat_render_field_wrapper('popular_question_2', __('Quick Question 2', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_popular_question_2_callback();
                        });

                        // Quick Question 3
                        mxchat_render_field_wrapper('popular_question_3', __('Quick Question 3', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_popular_question_3_callback();
                        });

                        // Additional Questions
                        mxchat_render_field_wrapper('additional_questions', __('Additional Questions', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_additional_popular_questions_callback();
                        }, __('Add as many quick questions as you need.', 'mxchat'));
                        ?>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 CHATBOT - RATE LIMITS
                 ======================================== -->
            <div id="chatbot-rate-limits" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Rate Limits', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Set message limits for each user role to manage API usage.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-notice mxch-notice-info" style="margin-bottom: 24px;">
                    <svg class="mxch-notice-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <div>
                        <strong><?php esc_html_e('Placeholders available:', 'mxchat'); ?></strong>
                        <code>{limit}</code>, <code>{timeframe}</code>, <code>{count}</code>, <code>{remaining}</code>
                        <br>
                        <strong><?php esc_html_e('Markdown links supported:', 'mxchat'); ?></strong>
                        <code>[Link text](https://example.com)</code>
                    </div>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-body mxchat-autosave-section">
                        <?php
                        // Render rate limits with accordion
                        mxchat_render_rate_limits_accordion($admin_instance);
                        ?>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 API KEYS
                 ======================================== -->
            <div id="api-keys" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('API Keys', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Manage API keys for AI providers and integrations.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                            <?php esc_html_e('AI Providers', 'mxchat'); ?>
                        </h3>
                    </div>
                    <div class="mxch-card-body mxchat-autosave-section">
                        <table class="form-table">
                            <?php do_settings_fields('mxchat-api-keys', 'mxchat_api_keys_section'); ?>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 OPTIMIZATION
                 ======================================== -->
            <div id="optimization" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Optimization & Diagnostics', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Optimize performance, debug issues, and manage plugin settings.', 'mxchat'); ?></p>
                </div>

                <!-- Script Loading Card -->
                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                            <?php esc_html_e('Script Loading', 'mxchat'); ?>
                        </h3>
                    </div>
                    <div class="mxch-card-body mxchat-autosave-section">
                        <?php
                        $script_loading_strategy = isset($options['script_loading_strategy']) ? $options['script_loading_strategy'] : 'default';
                        ?>
                        <div class="mxch-field">
                            <label class="mxch-field-label"><?php esc_html_e('Script Loading Strategy', 'mxchat'); ?></label>
                            <div class="mxch-field-control">
                                <select name="mxchat_options[script_loading_strategy]" id="script_loading_strategy" class="mxch-select mxchat-autosave-field">
                                    <option value="default" <?php selected($script_loading_strategy, 'default'); ?>><?php esc_html_e('Default (Immediate)', 'mxchat'); ?></option>
                                    <option value="defer" <?php selected($script_loading_strategy, 'defer'); ?>><?php esc_html_e('Deferred', 'mxchat'); ?></option>
                                    <option value="delay_1s" <?php selected($script_loading_strategy, 'delay_1s'); ?>><?php esc_html_e('Delay 1 Second', 'mxchat'); ?></option>
                                    <option value="delay_3s" <?php selected($script_loading_strategy, 'delay_3s'); ?>><?php esc_html_e('Delay 3 Seconds', 'mxchat'); ?></option>
                                    <option value="delay_5s" <?php selected($script_loading_strategy, 'delay_5s'); ?>><?php esc_html_e('Delay 5 Seconds', 'mxchat'); ?></option>
                                    <option value="on_interaction" <?php selected($script_loading_strategy, 'on_interaction'); ?>><?php esc_html_e('On User Interaction', 'mxchat'); ?></option>
                                </select>
                            </div>
                            <p class="mxch-field-description"><?php esc_html_e('Control when the chatbot script loads to improve page performance.', 'mxchat'); ?></p>
                        </div>

                        <?php
                        // Smart asset loading (plan-915355; rebuilt on the house toggle
                        // pattern in plan-64d34f). Standalone option — never part of
                        // mxchat_options, so mxchat_sanitize() can't strip it.
                        mxchat_render_field_wrapper('mxchat_smart_asset_loading', __('Only load chatbot files on pages where the chatbot appears', 'mxchat'), function() use ($admin_instance) {
                            $admin_instance->mxchat_smart_asset_loading_toggle_callback();
                        }, __('Skips the chatbot\'s CSS and JavaScript on pages where the widget is hidden (per-page visibility, post type include/exclude, or auto-display disabled). Pages using the chatbot shortcode still load everything automatically. Helps with "reduce unused CSS/JS" audit findings.', 'mxchat'),
                           __('If your theme or page builder inserts the chatbot in an unusual way and it stops appearing, turn this off — or force-load assets with the mxchat_should_load_assets filter.', 'mxchat'));
                        ?>

                        <div class="mxch-notice mxch-notice-info" style="margin-top: 16px;">
                            <svg class="mxch-notice-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                            <div>
                                <strong><?php esc_html_e('Loading Strategies:', 'mxchat'); ?></strong>
                                <ul style="margin: 8px 0 0 0; padding-left: 20px;">
                                    <li><strong><?php esc_html_e('Default:', 'mxchat'); ?></strong> <?php esc_html_e('Script loads immediately in the footer. Best for sites where chat is critical.', 'mxchat'); ?></li>
                                    <li><strong><?php esc_html_e('Deferred:', 'mxchat'); ?></strong> <?php esc_html_e('Script loads after HTML parsing completes. Good balance of performance and availability.', 'mxchat'); ?></li>
                                    <li><strong><?php esc_html_e('Delay:', 'mxchat'); ?></strong> <?php esc_html_e('Script loads after a set delay. Helps improve LCP scores.', 'mxchat'); ?></li>
                                    <li><strong><?php esc_html_e('On User Interaction:', 'mxchat'); ?></strong> <?php esc_html_e('Script loads when user scrolls, moves mouse, or touches screen. Best for Core Web Vitals but chat appears with slight delay.', 'mxchat'); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Page Cache Compatibility Card -->
                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                            <?php esc_html_e('Page Cache Compatibility', 'mxchat'); ?>
                        </h3>
                    </div>
                    <div class="mxch-card-body">
                        <p class="mxch-field-description" style="margin-top: 0;"><?php esc_html_e('MxChat\'s chat send (and stream send, plus PDF / Word upload) all POST to /wp-admin/admin-ajax.php. Page-cache plugins occasionally cache those responses, which breaks the per-session nonce flow on the first message. MxChat auto-tells the five most common cache plugins to never cache chat AJAX via their own filter APIs — no setup needed for those. For other cache plugins (or Cloudflare APO), copy the manual exclusion string into your cache plugin\'s exclusion list.', 'mxchat'); ?></p>

                        <?php
                        $mxch_cache_rows = array(
                            array('name' => 'WP Rocket',        'state' => 'auto', 'exclusion' => '/wp-admin/admin-ajax\\.php\\?action=mxchat_.*'),
                            array('name' => 'LiteSpeed Cache',  'state' => 'auto', 'exclusion' => '/wp-admin/admin-ajax.php?action=mxchat_*'),
                            array('name' => 'W3 Total Cache',   'state' => 'auto', 'exclusion' => '/wp-admin/admin-ajax.php?action=mxchat_*'),
                            array('name' => 'FlyingPress',      'state' => 'auto', 'exclusion' => '/wp-admin/admin-ajax.php?action=mxchat_*'),
                            array('name' => 'WP Super Cache',   'state' => 'auto', 'exclusion' => 'wp-admin/admin-ajax.php'),
                            array('name' => 'Cloudflare APO',   'state' => 'manual', 'exclusion' => 'Page Rule / Cache Rule: URI Path contains "/wp-admin/admin-ajax.php" → Bypass Cache'),
                        );
                        ?>

                        <div class="mxch-cache-compat-grid" style="display: grid; grid-template-columns: 1fr; gap: 12px; margin-top: 16px;">
                            <?php foreach ($mxch_cache_rows as $row) :
                                $is_auto = ($row['state'] === 'auto');
                                $pill_class = $is_auto ? 'mxch-status-pill-active' : 'mxch-status-pill-warning';
                                $pill_label = $is_auto ? __('Auto-handled', 'mxchat') : __('Manual', 'mxchat');
                                ?>
                                <div class="mxch-cache-compat-row" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; background: #f8fafc; border: 1px solid var(--mxch-card-border); border-radius: var(--mxch-radius-md);">
                                    <div style="display: flex; align-items: center; gap: 10px; min-width: 0; flex: 0 0 auto;">
                                        <span style="font-weight: 500;"><?php echo esc_html($row['name']); ?></span>
                                        <span class="mxch-status-pill <?php echo esc_attr($pill_class); ?>">
                                            <span class="mxch-status-dot"></span>
                                            <?php echo esc_html($pill_label); ?>
                                        </span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 8px; flex: 1 1 auto; min-width: 0;">
                                        <code style="background: #fff; border: 1px solid var(--mxch-card-border); padding: 4px 8px; border-radius: var(--mxch-radius-sm); font-size: 12px; color: var(--mxch-text-secondary); flex: 1 1 auto; overflow-x: auto; white-space: nowrap; min-width: 0;"><?php echo esc_html($row['exclusion']); ?></code>
                                        <button type="button" class="mxch-btn mxch-btn-secondary mxch-btn-sm" data-mxch-copy="<?php echo esc_attr($row['exclusion']); ?>" style="flex: 0 0 auto;">
                                            <?php esc_html_e('Copy', 'mxchat'); ?>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mxch-notice mxch-notice-info" style="margin-top: 16px;">
                            <svg class="mxch-notice-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                            <span><?php esc_html_e('Auto-handled plugins receive a filter from MxChat that excludes chat AJAX from their cache. Even so, having the exclusion in your own exclusion list is harmless and acts as a belt-and-suspenders guarantee.', 'mxchat'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Provider Reliability Card -->
                <?php
                $auto_retry_enabled = !isset($options['auto_retry_on_transient_error']) ||
                                      (string) $options['auto_retry_on_transient_error'] !== '0';
                ?>
                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/><polyline points="21 4 21 10 15 10"/></svg>
                            <?php esc_html_e('Provider Reliability', 'mxchat'); ?>
                        </h3>
                        <span class="mxch-status-pill <?php echo $auto_retry_enabled ? 'mxch-status-pill-active' : 'mxch-status-pill-disabled'; ?>">
                            <span class="mxch-status-dot"></span>
                            <?php echo $auto_retry_enabled ? esc_html__('Active', 'mxchat') : esc_html__('Disabled', 'mxchat'); ?>
                        </span>
                    </div>
                    <div class="mxch-card-body mxchat-autosave-section">
                        <div class="mxch-field">
                            <label class="mxch-field-label" for="auto_retry_on_transient_error">
                                <?php esc_html_e('Auto-retry on provider overload', 'mxchat'); ?>
                            </label>
                            <div class="mxch-field-control">
                                <label class="mxch-toggle">
                                    <input type="checkbox"
                                           id="auto_retry_on_transient_error"
                                           name="mxchat_options[auto_retry_on_transient_error]"
                                           class="mxchat-autosave-field"
                                           value="1"
                                           <?php checked($auto_retry_enabled); ?> />
                                    <span class="mxch-toggle-slider"></span>
                                </label>
                            </div>
                            <p class="mxch-field-description"><?php esc_html_e('When the AI provider returns a transient error (rate-limit / overload / 429 / 503 / Gemini "model overloaded"), MxChat retries the chat-send up to twice before surfacing the error. Visitors see a brief pause instead of a raw provider error in the bot bubble.', 'mxchat'); ?></p>
                            <p class="mxch-field-hint"><?php esc_html_e('Configuration errors (invalid API key, 401/403/404/422) still surface immediately — never retried.', 'mxchat'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Debug Mode Card -->
                <?php
                $debug_mode = isset($options['debug_mode']) ? $options['debug_mode'] : 'off';
                $debug_active = ($debug_mode === 'on');
                ?>
                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="m9 12 2 2 4-4"/></svg>
                            <?php esc_html_e('Debug Mode', 'mxchat'); ?>
                        </h3>
                    </div>
                    <div class="mxch-card-body">
                        <?php // Deliberate exception to the house .toggle-switch pattern
                              // (audited in plan-64d34f): this card-body is NOT an
                              // autosave section — the toggle saves through its own
                              // dedicated handler (mxchat_toggle_debug_mode, wired at
                              // mxchat-admin.js #mxchat_debug_mode) which appends its own
                              // save indicator inside this label. Converting the markup
                              // would orphan that indicator without touching the JS. ?>
                        <div class="mxch-field">
                            <label class="mxch-toggle">
                                <input type="checkbox" class="mxch-toggle-input" id="mxchat_debug_mode" name="mxchat_options[debug_mode]" value="on" <?php checked($debug_active); ?>>
                                <span class="mxch-toggle-switch"></span>
                                <span class="mxch-toggle-label"><?php esc_html_e('Enable Debug Logging', 'mxchat'); ?></span>
                            </label>
                            <p class="mxch-field-description"><?php esc_html_e('Tracks settings changes, knowledge base errors, API issues, and embedding failures. Enable only when troubleshooting. Max 100 entries stored.', 'mxchat'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Debug Log Viewer Card -->
                <div class="mxch-card">
                    <div class="mxch-card-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            <?php esc_html_e('Debug Log', 'mxchat'); ?>
                        </h3>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <span id="mxchat-log-count" class="mxch-badge" style="display: none;"></span>
                            <button type="button" class="mxch-btn mxch-btn-secondary mxch-btn-sm" id="mxchat-refresh-log">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                                <?php esc_html_e('Refresh', 'mxchat'); ?>
                            </button>
                            <button type="button" class="mxch-btn mxch-btn-secondary mxch-btn-sm" id="mxchat-clear-log">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                <?php esc_html_e('Clear', 'mxchat'); ?>
                            </button>
                        </div>
                    </div>
                    <div class="mxch-card-body">
                        <div id="mxchat-debug-log" class="mxchat-debug-log-viewer">
                            <div class="mxchat-debug-log-empty">
                                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.3;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <p><?php esc_html_e('No log entries yet. Enable debug mode to start logging.', 'mxchat'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Settings Tools Card -->
                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                            <?php esc_html_e('Settings Tools', 'mxchat'); ?>
                        </h3>
                    </div>
                    <div class="mxch-card-body">
                        <div class="mxch-tools-grid">
                            <!-- Export Settings -->
                            <div class="mxch-tool-item">
                                <div class="mxch-tool-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                </div>
                                <div class="mxch-tool-content">
                                    <h4><?php esc_html_e('Export Settings', 'mxchat'); ?></h4>
                                    <p><?php esc_html_e('Download a JSON file of your current settings for backup or support. API keys are masked for security.', 'mxchat'); ?></p>
                                    <button type="button" class="mxch-btn mxch-btn-secondary" id="mxchat-export-settings">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        <?php esc_html_e('Export Settings', 'mxchat'); ?>
                                    </button>
                                </div>
                            </div>

                            <!-- Reset All Settings -->
                            <div class="mxch-tool-item mxch-tool-item-danger">
                                <div class="mxch-tool-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                </div>
                                <div class="mxch-tool-content">
                                    <h4><?php esc_html_e('Reset All Settings', 'mxchat'); ?></h4>
                                    <p><?php esc_html_e('Reset all MxChat settings to their default values. This action cannot be undone.', 'mxchat'); ?></p>
                                    <button type="button" class="mxch-btn mxch-btn-danger" id="mxchat-reset-settings">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/></svg>
                                        <?php esc_html_e('Reset All Settings', 'mxchat'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reset Confirmation Modal -->
                <div id="mxchat-reset-modal" class="mxch-modal" style="display: none;">
                    <div class="mxch-modal-backdrop"></div>
                    <div class="mxch-modal-content">
                        <div class="mxch-modal-header">
                            <h3><?php esc_html_e('Reset All Settings', 'mxchat'); ?></h3>
                            <button type="button" class="mxch-modal-close" id="mxchat-reset-modal-close">&times;</button>
                        </div>
                        <div class="mxch-modal-body">
                            <div class="mxch-notice mxch-notice-warning">
                                <svg class="mxch-notice-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <div>
                                    <strong><?php esc_html_e('Warning: This action cannot be undone!', 'mxchat'); ?></strong>
                                    <p><?php esc_html_e('All your MxChat settings including API keys, customizations, and configurations will be permanently deleted.', 'mxchat'); ?></p>
                                </div>
                            </div>
                            <div class="mxch-field" style="margin-top: 20px;">
                                <label class="mxch-field-label"><?php esc_html_e('Type RESET to confirm:', 'mxchat'); ?></label>
                                <input type="text" id="mxchat-reset-confirmation" class="mxch-input" placeholder="RESET" autocomplete="off">
                            </div>
                        </div>
                        <div class="mxch-modal-footer">
                            <button type="button" class="mxch-btn mxch-btn-secondary" id="mxchat-reset-cancel"><?php esc_html_e('Cancel', 'mxchat'); ?></button>
                            <button type="button" class="mxch-btn mxch-btn-danger" id="mxchat-reset-confirm" disabled><?php esc_html_e('Reset All Settings', 'mxchat'); ?></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 INTEGRATIONS - TOOLBAR
                 ======================================== -->
            <div id="integrations-toolbar" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Toolbar Settings', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Configure the chat toolbar and document upload features.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-body mxchat-autosave-section">
                        <table class="form-table">
                            <?php do_settings_fields('mxchat-embed', 'mxchat_pdf_intent_section'); ?>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 INTEGRATIONS - LOOPS
                 ======================================== -->
            <div id="integrations-loops" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Loops Integration', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Configure email capture with Loops for mailing list growth.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-body mxchat-autosave-section">
                        <table class="form-table">
                            <?php do_settings_fields('mxchat-embed', 'mxchat_loops_section'); ?>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 INTEGRATIONS - BRAVE SEARCH
                 ======================================== -->
            <div id="integrations-brave" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Brave Search', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Configure Brave Search API for web search capabilities.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-body mxchat-autosave-section">
                        <table class="form-table">
                            <?php do_settings_fields('mxchat-embed', 'mxchat_brave_section'); ?>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 INTEGRATIONS - SLACK
                 ======================================== -->
            <div id="integrations-slack" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Slack', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Connect your chatbot to Slack for live human support.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-notice mxch-notice-info" style="margin-bottom: 24px;">
                    <svg class="mxch-notice-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <div>
                        <?php printf(
                            esc_html__('Visit our %s for setup instructions.', 'mxchat'),
                            '<a href="https://mxchat.ai/documentation/mxchat-core#slack-live-agent-handoff" target="_blank">' . esc_html__('documentation page', 'mxchat') . '</a>'
                        ); ?>
                    </div>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-body mxchat-autosave-section">
                        <table class="form-table">
                            <?php do_settings_fields('mxchat-embed', 'mxchat_live_agent_section'); ?>
                        </table>
                    </div>
                </div>

                <div class="mxch-card" style="margin-top: 24px;">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <?php esc_html_e('Test Connection', 'mxchat'); ?>
                        </h3>
                    </div>
                    <div class="mxch-card-body">
                        <p class="mxch-field-description" style="margin-bottom: 16px;">
                            <?php esc_html_e('Test your Slack bot token to verify it has the required permissions. This will check if the bot can authenticate and create channels.', 'mxchat'); ?>
                        </p>
                        <button type="button" id="mxchat-test-slack-connection" class="mxch-btn mxch-btn-secondary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <?php esc_html_e('Test Slack Connection', 'mxchat'); ?>
                        </button>
                        <div id="mxchat-slack-test-result" style="margin-top: 16px; display: none;"></div>
                    </div>
                </div>

                <div class="mxch-card" style="margin-top: 24px;">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                            <?php esc_html_e('Required OAuth Scopes', 'mxchat'); ?>
                        </h3>
                    </div>
                    <div class="mxch-card-body">
                        <p class="mxch-field-description" style="margin-bottom: 12px;">
                            <?php esc_html_e('Your Slack app needs these Bot Token Scopes (OAuth & Permissions > Scopes):', 'mxchat'); ?>
                        </p>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; background: #f8f9fa; padding: 16px; border-radius: 8px; font-family: monospace; font-size: 13px;">
                            <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px;">channels:manage</code>
                            <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px;">channels:read</code>
                            <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px;">chat:write</code>
                            <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px;">chat:write.public</code>
                            <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px;">groups:write</code>
                            <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px;">users:read</code>
                        </div>
                        <p class="mxch-field-description" style="margin-top: 12px;">
                            <?php esc_html_e('After adding scopes, reinstall the app to your workspace.', 'mxchat'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 INTEGRATIONS - TELEGRAM
                 ======================================== -->
            <div id="integrations-telegram" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Telegram', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Connect your chatbot to Telegram for live human support.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-notice mxch-notice-info" style="margin-bottom: 24px;">
                    <svg class="mxch-notice-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <div>
                        <?php esc_html_e('Telegram Live Agent requires a Telegram bot and a supergroup with forum topics enabled.', 'mxchat'); ?>
                        <a href="https://mxchat.ai/documentation-bot/" target="_blank" style="margin-left: 5px;"><?php esc_html_e('Chat with our advanced doc bot for assistance setting up.', 'mxchat'); ?></a>
                    </div>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-body mxchat-autosave-section">
                        <table class="form-table">
                            <?php do_settings_fields('mxchat-embed', 'mxchat_telegram_section'); ?>
                        </table>
                    </div>
                </div>

                <div class="mxch-card" style="margin-top: 24px;">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                            <?php esc_html_e('Webhook Setup', 'mxchat'); ?>
                        </h3>
                    </div>
                    <div class="mxch-card-body">
                        <?php
                        $telegram_bot_token = $options['telegram_bot_token'] ?? '';
                        $telegram_webhook_secret = $options['telegram_webhook_secret'] ?? '';
                        $webhook_url = rest_url('mxchat/v1/telegram-webhook');
                        ?>

                        <p class="mxch-field-description" style="margin-bottom: 8px;">
                            <strong><?php esc_html_e('Your Webhook URL:', 'mxchat'); ?></strong>
                        </p>
                        <div class="mxch-webhook-url-display" style="margin-bottom: 20px;">
                            <code id="mxch-telegram-webhook-url"><?php echo esc_url($webhook_url); ?></code>
                            <button type="button" class="mxch-btn mxch-btn-secondary mxch-btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('mxch-telegram-webhook-url').textContent); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">
                                <?php esc_html_e('Copy', 'mxchat'); ?>
                            </button>
                        </div>

                        <?php if (!empty($telegram_bot_token)) : ?>
                            <p class="mxch-field-description" style="margin-bottom: 8px;">
                                <strong><?php esc_html_e('Register Webhook URL:', 'mxchat'); ?></strong><br>
                                <?php esc_html_e('Copy and paste this URL into your browser to register the webhook with Telegram:', 'mxchat'); ?>
                            </p>
                            <?php
                            $registration_url = 'https://api.telegram.org/bot' . $telegram_bot_token . '/setWebhook?url=' . urlencode($webhook_url);
                            if (!empty($telegram_webhook_secret)) {
                                $registration_url .= '&secret_token=' . urlencode($telegram_webhook_secret);
                            }
                            ?>
                            <div class="mxch-webhook-url-display" style="margin-bottom: 12px;">
                                <code id="mxch-telegram-registration-url" style="word-break: break-all; font-size: 12px;"><?php echo esc_url($registration_url); ?></code>
                                <button type="button" class="mxch-btn mxch-btn-secondary mxch-btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('mxch-telegram-registration-url').textContent); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">
                                    <?php esc_html_e('Copy', 'mxchat'); ?>
                                </button>
                            </div>
                            <p class="mxch-field-description" style="color: #22c55e;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 4px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                <?php esc_html_e('You should see {"ok":true,"result":true,"description":"Webhook was set"} after visiting the URL.', 'mxchat'); ?>
                            </p>
                        <?php else : ?>
                            <p class="mxch-field-description" style="color: #f59e0b;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 4px;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                                <?php esc_html_e('Enter your Bot Token above and save to generate the registration URL.', 'mxchat'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 TUTORIALS
                 ======================================== -->
            <div id="tutorials" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Video Tutorials', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Learn how to get the most out of MxChat with our video guides.', 'mxchat'); ?></p>
                </div>

                <?php
                // Setup Guide — reopen the onboarding wizard (plan-347916, moved here from Display tab).
                // Only shows once the user has dismissed/graduated from the Onboarding page.
                if (function_exists('mxchat_onboarding_is_dismissed') && mxchat_onboarding_is_dismissed()):
                    $auto_grad = function_exists('mxchat_onboarding_is_auto_graduated') && mxchat_onboarding_is_auto_graduated();
                ?>
                <div class="mxch-card mxch-onboarding-unhide-card" style="margin-bottom: 24px;">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 3 3 6 3s6-1 6-3v-5"/></svg>
                            <?php esc_html_e('Setup Guide', 'mxchat'); ?>
                        </h3>
                    </div>
                    <div class="mxch-card-body">
                        <p style="margin:0 0 12px;color:var(--mxch-text-secondary);font-size:13px;line-height:1.5;">
                            <?php if ($auto_grad): ?>
                                <?php esc_html_e('You finished the setup guide. Reopen it any time to revisit the steps or change a setup choice.', 'mxchat'); ?>
                            <?php else: ?>
                                <?php esc_html_e('You hid the setup guide from the MxChat menu. Reopen it any time to revisit the steps.', 'mxchat'); ?>
                            <?php endif; ?>
                        </p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                            <?php wp_nonce_field('mxchat_unhide_onboarding'); ?>
                            <input type="hidden" name="action" value="mxchat_unhide_onboarding" />
                            <button type="submit" class="mxch-btn mxch-btn-secondary mxch-btn-sm">
                                <?php esc_html_e('Reopen setup guide', 'mxchat'); ?>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mxch-card" style="margin-bottom: 24px;">
                    <div class="mxch-card-body">
                        <div style="display: flex; align-items: flex-start; gap: 16px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--mxch-warning); flex-shrink: 0;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                            <div>
                                <h3 style="margin: 0 0 8px 0; font-size: 16px; font-weight: 600;"><?php esc_html_e('Need Help?', 'mxchat'); ?></h3>
                                <p style="margin: 0 0 12px 0; color: var(--mxch-text-secondary);"><?php esc_html_e('If you\'re having trouble or believe something is not working as expected, please submit a support ticket.', 'mxchat'); ?></p>
                                <a href="https://wordpress.org/support/plugin/mxchat-basic/" target="_blank" class="mxch-btn mxch-btn-secondary mxch-btn-sm">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9a2 2 0 0 1-2 2H6l-4 4V4c0-1.1.9-2 2-2h8a2 2 0 0 1 2 2v5Z"/><path d="M18 9h2a2 2 0 0 1 2 2v11l-4-4h-6a2 2 0 0 1-2-2v-1"/></svg>
                                    <?php esc_html_e('Submit Support Ticket', 'mxchat'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mxch-tutorials-grid">
                    <?php
                    $tutorials = array(
                        array(
                            'title' => __('Quick Setup with MxChat', 'mxchat'),
                            'description' => __('Learn how to quickly setup and understand how your chatbot works.', 'mxchat'),
                            'url' => 'https://www.youtube.com/watch?v=3BqoiyWaQiM&t'
                        ),
                        array(
                            'title' => __('AI Content Generation', 'mxchat'),
                            'description' => __('Generate full blog posts and landing pages with AI—from prompt to publish in minutes.', 'mxchat'),
                            'url' => 'https://www.youtube.com/watch?v=n8TeEeHpxs4'
                        ),
                        array(
                            'title' => __('Chat With Your GSC Data', 'mxchat'),
                            'description' => __('Find content gaps, quick win keywords, and SEO opportunities by chatting with your Google Search Console data using the Admin Assistant add-on.', 'mxchat'),
                            'url' => 'https://www.youtube.com/watch?v=ytgMou_7SZA&t=2s'
                        ),
                        array(
                            'title' => __('AI Theme Generator', 'mxchat'),
                            'description' => __('Learn how to instantly restyle your chatbot using plain English prompts with real-time previews.', 'mxchat'),
                            'url' => 'https://www.youtube.com/watch?v=rSQDW2qbtRU&t'
                        ),
                        array(
                            'title' => __('MxChat Forms', 'mxchat'),
                            'description' => __('Create and manage smart forms that automatically trigger during chat conversations.', 'mxchat'),
                            'url' => 'https://www.youtube.com/watch?v=3MrWy5dRalA'
                        ),
                        array(
                            'title' => __('Admin Assistant Add-on', 'mxchat'),
                            'description' => __('Bring a ChatGPT-like experience directly inside your WordPress dashboard.', 'mxchat'),
                            'url' => 'https://youtu.be/AdEA1k-UCFM'
                        ),
                        array(
                            'title' => __('Chat Themes', 'mxchat'),
                            'description' => __('Customize appearance with real-time previews to match your brand.', 'mxchat'),
                            'url' => 'https://youtu.be/MfbB9mZi6ag'
                        ),
                        array(
                            'title' => __('WooCommerce Integration', 'mxchat'),
                            'description' => __('Provide product recommendations and shopping assistance to customers.', 'mxchat'),
                            'url' => 'https://www.youtube.com/watch?v=WsqAppHRGdA'
                        ),
                        array(
                            'title' => __('Knowledge Base Setup', 'mxchat'),
                            'description' => __('Set up your knowledge base using PDFs, sitemaps, and manual entries.', 'mxchat'),
                            'url' => 'https://www.youtube.com/watch?v=8Ztjs66-VTo'
                        ),
                        array(
                            'title' => __('Document Chat', 'mxchat'),
                            'description' => __('Chat with PDF and Word documents for enhanced document analysis.', 'mxchat'),
                            'url' => 'https://www.youtube.com/watch?v=j_c45WWCTG0'
                        ),
                        array(
                            'title' => __('Perplexity Integration', 'mxchat'),
                            'description' => __('Enable real-time web search capabilities for your chatbot.', 'mxchat'),
                            'url' => 'https://youtu.be/wpKkbt24-bo'
                        ),
                        array(
                            'title' => __('Brave Search Intent', 'mxchat'),
                            'description' => __('Leverage Brave Search for improved query understanding.', 'mxchat'),
                            'url' => 'https://www.youtube.com/watch?v=7vDL5H7vToc'
                        ),
                        array(
                            'title' => __('Loops Email Capture', 'mxchat'),
                            'description' => __('Set up email capture with Loops to grow your mailing list.', 'mxchat'),
                            'url' => 'https://www.youtube.com/watch?v=CNgm5TYDyTc'
                        ),
                        array(
                            'title' => __('AI Agent Testing', 'mxchat'),
                            'description' => __('Evaluate and improve your chatbot\'s performance and accuracy.', 'mxchat'),
                            'url' => 'https://www.youtube.com/watch?v=A0jowbpyX54'
                        ),
                    );

                    foreach ($tutorials as $tutorial): ?>
                    <div class="mxch-tutorial-card">
                        <div class="mxch-tutorial-content">
                            <h3 class="mxch-tutorial-title"><?php echo esc_html($tutorial['title']); ?></h3>
                            <p class="mxch-tutorial-description"><?php echo esc_html($tutorial['description']); ?></p>
                            <a href="<?php echo esc_url($tutorial['url']); ?>" target="_blank" rel="noopener" class="mxch-tutorial-link">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                <?php esc_html_e('Watch Tutorial', 'mxchat'); ?>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- =====================================================
                 TESTING SECTION
                 ===================================================== -->
            <div id="testing" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Testing', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Test your chatbot and inspect debug data in real time.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-testing-layout">
                    <!-- Left Column: Debug Panel -->
                    <div class="mxch-testing-debug-panel">
                        <!-- Quick Actions -->
                        <div class="mxch-card">
                            <div class="mxch-card-body">
                                <h3 class="mxch-testing-section-title"><?php esc_html_e('Quick Actions', 'mxchat'); ?></h3>
                                <div class="mxch-testing-actions-row">
                                    <button type="button" class="mxch-testing-btn mxch-testing-btn-danger" id="mxch-testing-clear-session">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                        <?php esc_html_e('Clear Chat Session', 'mxchat'); ?>
                                    </button>
                                    <button type="button" class="mxch-testing-btn mxch-testing-btn-secondary" id="mxch-testing-clear-debug">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M2 12h20"/></svg>
                                        <?php esc_html_e('Clear Debug Log', 'mxchat'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Last Query Analysis -->
                        <div class="mxch-card">
                            <div class="mxch-card-body">
                                <h3 class="mxch-testing-section-title"><?php esc_html_e('Last Query Analysis', 'mxchat'); ?></h3>

                                <div class="mxch-testing-field">
                                    <label><?php esc_html_e('Similarity Threshold', 'mxchat'); ?></label>
                                    <span id="mxch-testing-threshold"><?php esc_html_e('Loading...', 'mxchat'); ?></span>
                                </div>

                                <div class="mxch-testing-field">
                                    <label><?php esc_html_e('User Query', 'mxchat'); ?></label>
                                    <div id="mxch-testing-last-query" class="mxch-testing-query-display"><?php esc_html_e('Waiting for next query...', 'mxchat'); ?></div>
                                </div>

                                <div class="mxch-testing-field">
                                    <label><?php esc_html_e('Approved URLs for Citations', 'mxchat'); ?></label>
                                    <div id="mxch-testing-approved-urls" class="mxch-testing-results">
                                        <div class="mxch-testing-no-data"><?php esc_html_e('No URL data yet', 'mxchat'); ?></div>
                                    </div>
                                </div>

                                <div class="mxch-testing-field">
                                    <label><?php esc_html_e('Document Matches', 'mxchat'); ?></label>
                                    <div id="mxch-testing-similarity-scores" class="mxch-testing-results">
                                        <div class="mxch-testing-no-data"><?php esc_html_e('No query data yet', 'mxchat'); ?></div>
                                    </div>
                                </div>

                                <div class="mxch-testing-field">
                                    <label><?php esc_html_e('Actions Triggered', 'mxchat'); ?></label>
                                    <div id="mxch-testing-action-scores" class="mxch-testing-results">
                                        <div class="mxch-testing-no-data"><?php esc_html_e('No action data yet', 'mxchat'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- System Information -->
                        <div class="mxch-card">
                            <div class="mxch-card-body">
                                <h3 class="mxch-testing-section-title"><?php esc_html_e('System Information', 'mxchat'); ?></h3>

                                <div class="mxch-testing-field">
                                    <label><?php esc_html_e('System Prompt', 'mxchat'); ?></label>
                                    <div id="mxch-testing-system-prompt" class="mxch-testing-system-prompt"><?php esc_html_e('Loading...', 'mxchat'); ?></div>
                                </div>

                                <div class="mxch-testing-field">
                                    <label><?php esc_html_e('Knowledge Base', 'mxchat'); ?></label>
                                    <span id="mxch-testing-kb-status"><?php esc_html_e('Checking...', 'mxchat'); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Debug Log -->
                        <div class="mxch-card">
                            <div class="mxch-card-body">
                                <h3 class="mxch-testing-section-title"><?php esc_html_e('Debug Log', 'mxchat'); ?></h3>
                                <div id="mxch-testing-debug-console" class="mxch-testing-debug-console">
                                    <div class="mxch-testing-debug-entry"><?php esc_html_e('Debug panel ready - send a message to begin...', 'mxchat'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Chatbot Replica -->
                    <div class="mxch-testing-chatbot-column">
                        <div class="mxch-testing-chatbot-scope">
                            <?php
                            // Render the chatbot inline for testing
                            echo do_shortcode('[mxchat_chatbot floating="no" bot_id="testing"]');
                            ?>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Sidebar/tab navigation, accordion groups, ?tab=/#hash deep-linking
        // and the mobile menu all live in the shared shell script
        // (js/admin-sidebar.js) — consolidated by plan c192a4. Only the
        // page-local widgets below remain inline.

        // Rate limit accordion
        const rateLimitHeaders = document.querySelectorAll('.mxch-rate-limit-header');
        rateLimitHeaders.forEach(header => {
            header.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const item = this.closest('.mxch-rate-limit-item');
                item.classList.toggle('expanded');
            });
        });

        // Rate-limit "Custom…" toggle: show/hide the integer input below each
        // limit <select> based on whether __custom__ is the active option.
        document.querySelectorAll('.mxch-rate-limit-limit-select').forEach(sel => {
            const targetId = sel.getAttribute('data-mxch-custom-target');
            if (!targetId) return;
            const field = document.querySelector('[data-mxch-custom-for="' + targetId + '"]');
            if (!field) return;
            const sync = () => {
                if (sel.value === '__custom__') {
                    field.hidden = false;
                } else {
                    field.hidden = true;
                }
            };
            sel.addEventListener('change', sync);
            sync();
        });
    });
    </script>
    <?php
}

/**
 * Helper function to render a field with consistent wrapper
 *
 * $hint renders as a .mxch-field-hint line under the description — the
 * softer "caveats / fine print" slot (added for plan-64d34f so hint-bearing
 * fields don't have to hand-roll the wrapper markup).
 */
function mxchat_render_field_wrapper($id, $label, $callback, $description = '', $hint = '') {
    ?>
    <div class="mxch-field">
        <label class="mxch-field-label"><?php echo esc_html($label); ?></label>
        <div class="mxch-field-control">
            <?php $callback(); ?>
        </div>
        <?php if ($description): ?>
        <p class="mxch-field-description"><?php echo esc_html($description); ?></p>
        <?php endif; ?>
        <?php if ($hint): ?>
        <p class="mxch-field-hint"><?php echo esc_html($hint); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render rate limits as collapsible accordion
 */
function mxchat_render_rate_limits_accordion($admin_instance) {
    $all_options = get_option('mxchat_options', []);
    $rate_limits = array('1', '3', '5', '10', '15', '20', '50', '100', 'unlimited');
    $timeframes = array(
        'hourly' => __('Per Hour', 'mxchat'),
        'daily' => __('Per Day', 'mxchat'),
        'weekly' => __('Per Week', 'mxchat'),
        'monthly' => __('Per Month', 'mxchat')
    );

    $roles = wp_roles()->get_names();
    $roles['logged_out'] = __('Logged Out Users', 'mxchat');

    // Whole-chatbot global cap (sits above per-role; defaults to unlimited so
    // existing installs are unchanged). Stored under mxchat_options['rate_limits_global'].
    $global_cfg = isset($all_options['rate_limits_global']) && is_array($all_options['rate_limits_global'])
        ? $all_options['rate_limits_global']
        : array();
    $global_limit_raw     = isset($global_cfg['limit']) ? (string) $global_cfg['limit'] : 'unlimited';
    $global_timeframe     = isset($global_cfg['timeframe']) ? (string) $global_cfg['timeframe'] : 'daily';
    $global_is_unlimited  = ($global_limit_raw === '' || $global_limit_raw === 'unlimited');
    $global_is_preset     = !$global_is_unlimited && in_array($global_limit_raw, $rate_limits, true);
    $global_select_value  = $global_is_unlimited ? 'unlimited' : ($global_is_preset ? $global_limit_raw : '__custom__');
    $global_custom_value  = (!$global_is_unlimited && !$global_is_preset && ctype_digit($global_limit_raw))
        ? $global_limit_raw
        : '';

    echo '<div class="mxch-rate-limits">';

    // --- Global cap card -------------------------------------------------
    ?>
    <div class="mxch-rate-limit-item mxch-rate-limit-global mxchat-autosave-section">
        <div class="mxch-rate-limit-header">
            <span class="mxch-rate-limit-role"><?php esc_html_e('Total chatbot message limit', 'mxchat'); ?></span>
            <div class="mxch-rate-limit-summary">
                <span class="mxch-rate-limit-badge mxch-rate-limit-global-badge">
                    <?php
                    if ($global_is_unlimited) {
                        esc_html_e('Unlimited', 'mxchat');
                    } else {
                        $tf_label = isset($timeframes[$global_timeframe]) ? $timeframes[$global_timeframe] : $global_timeframe;
                        echo esc_html(($global_custom_value !== '' ? $global_custom_value : $global_limit_raw) . ' / ' . $tf_label);
                    }
                    ?>
                </span>
                <svg class="mxch-rate-limit-arrow" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
        </div>
        <div class="mxch-rate-limit-body">
            <p class="mxch-field-description" style="margin: 0 0 12px;">
                <?php esc_html_e('A single ceiling across all users and all roles, per timeframe. Independent of the per-role limits below. When both a per-role limit and this global cap are configured, whichever is hit first stops the conversation. Default is Unlimited — existing installs are unchanged.', 'mxchat'); ?>
            </p>
            <div class="mxch-rate-limit-controls">
                <div class="mxch-field">
                    <label class="mxch-field-label" for="rate_limits_global_limit"><?php esc_html_e('Limit', 'mxchat'); ?></label>
                    <select id="rate_limits_global_limit"
                            name="mxchat_options[rate_limits_global][limit]"
                            class="mxch-select mxchat-autosave-field mxch-rate-limit-limit-select"
                            data-mxch-custom-target="rate_limits_global_limit_custom">
                        <?php foreach ($rate_limits as $limit): ?>
                            <option value="<?php echo esc_attr($limit); ?>" <?php selected($global_select_value, $limit); ?>>
                                <?php echo esc_html($limit === 'unlimited' ? __('Unlimited', 'mxchat') : $limit); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="__custom__" <?php selected($global_select_value, '__custom__'); ?>><?php esc_html_e('Custom…', 'mxchat'); ?></option>
                    </select>
                </div>
                <div class="mxch-field mxch-rate-limit-custom-field" data-mxch-custom-for="rate_limits_global_limit_custom" <?php echo $global_select_value === '__custom__' ? '' : 'hidden'; ?>>
                    <label class="mxch-field-label" for="rate_limits_global_limit_custom"><?php esc_html_e('Custom limit', 'mxchat'); ?></label>
                    <input type="number"
                           min="1"
                           step="1"
                           id="rate_limits_global_limit_custom"
                           name="mxchat_options[rate_limits_global][limit_custom]"
                           class="mxch-input mxchat-autosave-field mxch-rate-limit-custom-input"
                           placeholder="e.g. 250"
                           value="<?php echo esc_attr($global_custom_value); ?>" />
                </div>
                <div class="mxch-field">
                    <label class="mxch-field-label" for="rate_limits_global_timeframe"><?php esc_html_e('Timeframe', 'mxchat'); ?></label>
                    <select id="rate_limits_global_timeframe"
                            name="mxchat_options[rate_limits_global][timeframe]"
                            class="mxch-select mxchat-autosave-field">
                        <?php foreach ($timeframes as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($global_timeframe, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php
            // --- Current usage readout (read-only; mirrors the integrator's
            // window-reset math at READ time — never writes the counter here).
            // v1 surfaces the DEFAULT bot's pool. TODO: per-bot selector if asked.
            $global_usage_limit_int = (!$global_is_unlimited && ctype_digit($global_limit_raw))
                ? (int) $global_limit_raw
                : 0;
            $usage_raw   = get_option('mxchat_chat_limit_default_global', array('count' => 0, 'timestamp' => time()));
            $usage_count = isset($usage_raw['count']) ? (int) $usage_raw['count'] : 0;
            $usage_ts    = isset($usage_raw['timestamp']) ? (int) $usage_raw['timestamp'] : time();
            $usage_windows = array('hourly' => 3600, 'daily' => 86400, 'weekly' => 604800, 'monthly' => 2592000);
            $usage_window  = isset($usage_windows[$global_timeframe]) ? $usage_windows[$global_timeframe] : 86400;
            $usage_now = time();
            if (($usage_now - $usage_ts) >= $usage_window) {
                // Window elapsed since the stored timestamp → effective count is 0.
                $usage_count = 0;
                $usage_reset_at = $usage_now + $usage_window;
            } else {
                $usage_reset_at = $usage_ts + $usage_window;
            }
            $usage_left = max(0, $global_usage_limit_int - $usage_count);
            $usage_pct  = ($global_usage_limit_int > 0)
                ? min(100, (int) round(($usage_count / $global_usage_limit_int) * 100))
                : 0;
            $usage_reset_nonce = wp_create_nonce('mxchat_reset_global_usage');
            ?>
            <div class="mxch-rate-limit-usage"
                 id="mxch-global-usage"
                 data-bot-id="default"
                 data-reset-nonce="<?php echo esc_attr($usage_reset_nonce); ?>"
                 <?php echo $global_is_unlimited ? 'hidden' : ''; ?>>
                <div class="mxch-rate-limit-usage-head">
                    <span class="mxch-field-label" style="margin:0;"><?php esc_html_e('Current usage', 'mxchat'); ?></span>
                    <button type="button" class="mxch-btn mxch-btn-secondary mxch-btn-sm" id="mxch-global-usage-reset"><?php esc_html_e('Reset counter', 'mxchat'); ?></button>
                </div>
                <div class="mxch-progress-bar">
                    <div class="mxch-progress-bar-fill" id="mxch-global-usage-fill" style="width: <?php echo esc_attr($usage_pct); ?>%;"></div>
                </div>
                <p class="mxch-progress-label" id="mxch-global-usage-text" style="margin:0;">
                    <?php
                    printf(
                        /* translators: 1: used count, 2: limit, 3: remaining, 4: human-readable time until reset */
                        esc_html__('%1$s of %2$s used · %3$s left · resets in %4$s', 'mxchat'),
                        esc_html(number_format_i18n($usage_count)),
                        esc_html(number_format_i18n($global_usage_limit_int)),
                        esc_html(number_format_i18n($usage_left)),
                        esc_html(human_time_diff($usage_now, $usage_reset_at))
                    );
                    ?>
                </p>
            </div>
            <p class="mxch-field-description mxch-rate-limit-usage-unlimited"
               id="mxch-global-usage-unlimited"
               style="margin: 12px 0 0;"
               <?php echo $global_is_unlimited ? '' : 'hidden'; ?>>
                <?php esc_html_e('Unlimited — no cap. Usage tracking applies only when a numeric limit is set.', 'mxchat'); ?>
            </p>
        </div>
    </div>
    <?php
    // ---------------------------------------------------------------------

    foreach ($roles as $role_id => $role_name) {
        $default_limit = ($role_id === 'logged_out') ? '10' : '100';
        $default_timeframe = 'daily';
        $default_message = __('Rate limit exceeded. Please try again later.', 'mxchat');

        $selected_limit = isset($all_options['rate_limits'][$role_id]['limit'])
            ? (string) $all_options['rate_limits'][$role_id]['limit']
            : $default_limit;

        $selected_timeframe = isset($all_options['rate_limits'][$role_id]['timeframe'])
            ? $all_options['rate_limits'][$role_id]['timeframe']
            : $default_timeframe;

        $custom_message = isset($all_options['rate_limits'][$role_id]['message'])
            ? $all_options['rate_limits'][$role_id]['message']
            : $default_message;

        $timeframe_label = isset($timeframes[$selected_timeframe]) ? $timeframes[$selected_timeframe] : $selected_timeframe;

        // Custom-value handling: if the stored limit is not in the preset list
        // and not 'unlimited', it's a custom integer. The select shows __custom__
        // and the number input below carries the actual value.
        $is_preset_limit = in_array($selected_limit, $rate_limits, true);
        $role_select_value = $is_preset_limit ? $selected_limit : '__custom__';
        $role_custom_value = (!$is_preset_limit && ctype_digit($selected_limit)) ? $selected_limit : '';
        $custom_field_id   = 'rate_limits_' . $role_id . '_limit_custom';
        ?>
        <div class="mxch-rate-limit-item">
            <div class="mxch-rate-limit-header">
                <span class="mxch-rate-limit-role"><?php echo esc_html($role_name); ?></span>
                <div class="mxch-rate-limit-summary">
                    <span class="mxch-rate-limit-badge"><?php echo esc_html($selected_limit . ' / ' . $timeframe_label); ?></span>
                    <svg class="mxch-rate-limit-arrow" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </div>
            </div>
            <div class="mxch-rate-limit-body mxchat-autosave-section">
                <div class="mxch-rate-limit-controls">
                    <div class="mxch-field">
                        <label class="mxch-field-label" for="rate_limits_<?php echo esc_attr($role_id); ?>_limit"><?php esc_html_e('Limit', 'mxchat'); ?></label>
                        <select id="rate_limits_<?php echo esc_attr($role_id); ?>_limit"
                                name="mxchat_options[rate_limits][<?php echo esc_attr($role_id); ?>][limit]"
                                class="mxch-select mxchat-autosave-field mxch-rate-limit-limit-select"
                                data-mxch-custom-target="<?php echo esc_attr($custom_field_id); ?>">
                            <?php foreach ($rate_limits as $limit): ?>
                            <option value="<?php echo esc_attr($limit); ?>" <?php selected($role_select_value, $limit); ?>>
                                <?php echo esc_html($limit === 'unlimited' ? __('Unlimited', 'mxchat') : $limit); ?>
                            </option>
                            <?php endforeach; ?>
                            <option value="__custom__" <?php selected($role_select_value, '__custom__'); ?>><?php esc_html_e('Custom…', 'mxchat'); ?></option>
                        </select>
                    </div>
                    <div class="mxch-field mxch-rate-limit-custom-field" data-mxch-custom-for="<?php echo esc_attr($custom_field_id); ?>" <?php echo $role_select_value === '__custom__' ? '' : 'hidden'; ?>>
                        <label class="mxch-field-label" for="<?php echo esc_attr($custom_field_id); ?>"><?php esc_html_e('Custom limit', 'mxchat'); ?></label>
                        <input type="number"
                               min="1"
                               step="1"
                               id="<?php echo esc_attr($custom_field_id); ?>"
                               name="mxchat_options[rate_limits][<?php echo esc_attr($role_id); ?>][limit_custom]"
                               class="mxch-input mxchat-autosave-field mxch-rate-limit-custom-input"
                               placeholder="e.g. 250"
                               value="<?php echo esc_attr($role_custom_value); ?>" />
                    </div>
                    <div class="mxch-field">
                        <label class="mxch-field-label" for="rate_limits_<?php echo esc_attr($role_id); ?>_timeframe"><?php esc_html_e('Timeframe', 'mxchat'); ?></label>
                        <select id="rate_limits_<?php echo esc_attr($role_id); ?>_timeframe"
                                name="mxchat_options[rate_limits][<?php echo esc_attr($role_id); ?>][timeframe]"
                                class="mxch-select mxchat-autosave-field">
                            <?php foreach ($timeframes as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_timeframe, $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mxch-field">
                    <label class="mxch-field-label" for="rate_limits_<?php echo esc_attr($role_id); ?>_message"><?php esc_html_e('Custom Message', 'mxchat'); ?></label>
                    <textarea id="rate_limits_<?php echo esc_attr($role_id); ?>_message"
                              name="mxchat_options[rate_limits][<?php echo esc_attr($role_id); ?>][message]"
                              class="mxch-textarea mxchat-autosave-field"
                              rows="2"
                              placeholder="<?php esc_attr_e('Message shown when rate limit is reached', 'mxchat'); ?>"><?php echo esc_textarea($custom_message); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    echo '</div>';
}
