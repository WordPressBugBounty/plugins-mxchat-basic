<?php
/**
 * MxChat Content Generator Page
 *
 * Admin page template with sidebar navigation for content generation.
 *
 * @package MxChat
 * @since 3.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

function mxchat_render_content_page($admin_instance) {
    $is_activated = $admin_instance->is_activated();
    $options = get_option('mxchat_options', array());
    $plugin_url = plugin_dir_url(dirname(__FILE__));

    // Pro feature checks — add-on plugins hook into this filter to unlock
    $pro_internal_linking  = apply_filters('mxchat_content_pro_feature', false, 'internal_linking');
    $pro_tool_use          = apply_filters('mxchat_content_pro_feature', false, 'tool_use');
    $pro_image_management  = apply_filters('mxchat_content_pro_feature', false, 'image_management');
    $pro_meta_management   = apply_filters('mxchat_content_pro_feature', false, 'meta_management');
    $pro_gsc_integration   = apply_filters('mxchat_content_pro_feature', false, 'gsc_integration');
    $pro_content_calendar  = apply_filters('mxchat_content_pro_feature', false, 'content_calendar');
    $pro_template_generator = apply_filters('mxchat_content_pro_feature', false, 'template_generator');
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
                <span class="mxch-mobile-menu-title"><?php esc_html_e('Content', 'mxchat'); ?></span>
                <button type="button" class="mxch-mobile-menu-close" aria-label="<?php esc_attr_e('Close menu', 'mxchat'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <nav class="mxch-mobile-menu-nav">
                <div class="mxch-mobile-nav-section">
                    <div class="mxch-mobile-nav-section-title"><?php esc_html_e('Content', 'mxchat'); ?></div>
                    <button class="mxch-mobile-nav-link active" data-target="content-generate">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>
                        <span><?php esc_html_e('Generate', 'mxchat'); ?></span>
                    </button>
                    <button class="mxch-mobile-nav-link" data-target="content-history">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span><?php esc_html_e('History', 'mxchat'); ?></span>
                    </button>
                    <button class="mxch-mobile-nav-link" data-target="content-settings">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                        <span><?php esc_html_e('Settings', 'mxchat'); ?></span>
                    </button>
                    <button class="mxch-mobile-nav-link" data-target="content-seo">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg>
                        <span><?php esc_html_e('SEO', 'mxchat'); ?></span>
                    </button>
                    <button class="mxch-mobile-nav-link" data-target="content-calendar">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span><?php esc_html_e('Calendar', 'mxchat'); ?></span>
                    </button>
                    <button class="mxch-mobile-nav-link" data-target="content-templates">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                        <span><?php esc_html_e('Templates', 'mxchat'); ?></span>
                    </button>
                </div>
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
                    <span class="mxch-sidebar-version">v<?php echo esc_html(MXCHAT_VERSION ?? '3.1.0'); ?></span>
                </a>
            </div>

            <nav class="mxch-sidebar-nav">
                <div class="mxch-nav-section">
                    <div class="mxch-nav-section-title"><?php esc_html_e('Content', 'mxchat'); ?></div>

                    <div class="mxch-nav-item" data-section="content-generate">
                        <button class="mxch-nav-link active" data-target="content-generate">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Generate', 'mxchat'); ?></span>
                        </button>
                    </div>

                    <div class="mxch-nav-item" data-section="content-history">
                        <button class="mxch-nav-link" data-target="content-history">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('History', 'mxchat'); ?></span>
                        </button>
                    </div>

                    <div class="mxch-nav-item" data-section="content-settings">
                        <button class="mxch-nav-link" data-target="content-settings">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Settings', 'mxchat'); ?></span>
                        </button>
                    </div>

                    <div class="mxch-nav-item" data-section="content-seo">
                        <button class="mxch-nav-link" data-target="content-seo">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('SEO', 'mxchat'); ?></span>
                        </button>
                    </div>

                    <div class="mxch-nav-item" data-section="content-calendar">
                        <button class="mxch-nav-link" data-target="content-calendar">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Calendar', 'mxchat'); ?></span>
                        </button>
                    </div>

                    <div class="mxch-nav-item" data-section="content-templates">
                        <button class="mxch-nav-link" data-target="content-templates">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Templates', 'mxchat'); ?></span>
                        </button>
                    </div>
                </div>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="mxch-content">

            <!-- ========================================
                 GENERATE
                 ======================================== -->
            <div id="content-generate" class="mxch-section active">

                <!-- Toolbar -->
                <div class="mxch-cg-toolbar mxch-cg-toolbar-minimal">
                    <div class="mxch-cg-toolbar-left">
                        <button type="button" id="mxch-cg-new-btn" class="mxch-cg-generate-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            <span><?php esc_html_e('Generate', 'mxchat'); ?></span>
                        </button>
                        <h2 id="mxch-cg-preview-title" class="mxch-cg-toolbar-title"><?php esc_html_e('Content Generator', 'mxchat'); ?></h2>
                        <div id="mxch-cg-status-dropdown" class="mxch-cg-status-dropdown" style="display:none;">
                            <button type="button" id="mxch-cg-status-badge" class="mxch-cg-status-badge" title="<?php esc_attr_e('Change status', 'mxchat'); ?>">
                                <span class="mxch-cg-status-badge-text"></span>
                                <svg class="mxch-cg-status-chevron" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <div class="mxch-cg-status-menu" style="display:none;">
                                <button type="button" class="mxch-cg-status-option" data-status="draft">
                                    <span class="mxch-cg-status-dot mxch-cg-dot-draft"></span>
                                    <?php esc_html_e('Draft', 'mxchat'); ?>
                                </button>
                                <button type="button" class="mxch-cg-status-option" data-status="publish">
                                    <span class="mxch-cg-status-dot mxch-cg-dot-publish"></span>
                                    <?php esc_html_e('Published', 'mxchat'); ?>
                                </button>
                                <button type="button" class="mxch-cg-status-option" data-status="future">
                                    <span class="mxch-cg-status-dot mxch-cg-dot-future"></span>
                                    <?php esc_html_e('Scheduled', 'mxchat'); ?>
                                </button>
                                <div class="mxch-cg-status-schedule-row" style="display:none;">
                                    <input type="datetime-local" id="mxch-cg-status-schedule-input" class="mxch-cg-input mxch-cg-status-schedule-input">
                                    <button type="button" id="mxch-cg-status-schedule-confirm" class="mxch-cg-status-schedule-confirm">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mxch-cg-toolbar-right" style="display:none;">
                        <div class="mxch-cg-viewport-toggle">
                            <button type="button" class="mxch-cg-viewport-btn active" data-viewport="desktop" title="<?php esc_attr_e('Desktop', 'mxchat'); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                            </button>
                            <button type="button" class="mxch-cg-viewport-btn" data-viewport="mobile" title="<?php esc_attr_e('Mobile', 'mxchat'); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                            </button>
                        </div>
                        <a id="mxch-cg-view-post" href="#" target="_blank" class="mxch-cg-action-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                            <?php esc_html_e('View', 'mxchat'); ?>
                        </a>
                        <button type="button" id="mxch-cg-chat-toggle" class="mxch-cg-action-btn" title="<?php esc_attr_e('Edit with AI', 'mxchat'); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            <?php esc_html_e('AI Edit', 'mxchat'); ?>
                        </button>
                    </div>
                </div>

                <!-- Full-Width Preview -->
                <div class="mxch-cg-preview-wrap">
                    <div id="mxch-cg-preview-container" class="mxch-cg-preview-container">
                        <!-- Inline Generation Form -->
                        <div id="mxch-cg-inline-form" class="mxch-cg-inline-form">
                            <div class="mxch-cg-inline-form-inner">
                                <div class="mxch-cg-inline-form-header">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                    <div>
                                        <h3><?php esc_html_e('Generate Content', 'mxchat'); ?></h3>
                                        <p><?php esc_html_e('Describe what you want and let AI create it for you.', 'mxchat'); ?></p>
                                    </div>
                                </div>
                                <div class="mxch-cg-form">
                                    <textarea id="mxch-cg-prompt" class="mxch-cg-prompt" rows="3" placeholder="<?php esc_attr_e('Describe the content you want to generate...', 'mxchat'); ?>"></textarea>
                                    <button type="button" id="mxch-cg-edit-prompt-btn" class="mxch-cg-edit-prompt-btn">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        <?php esc_html_e('Edit Default Prompt', 'mxchat'); ?>
                                    </button>
                                    <div class="mxch-cg-options">
                                        <div class="mxch-cg-option">
                                            <label class="mxch-cg-option-label" for="mxch-cg-type"><?php esc_html_e('Type', 'mxchat'); ?></label>
                                            <select id="mxch-cg-type" class="mxch-cg-select">
                                                <option value="post"><?php esc_html_e('Blog Post', 'mxchat'); ?></option>
                                                <option value="page"><?php esc_html_e('Landing Page', 'mxchat'); ?></option>
                                            </select>
                                        </div>
                                        <div class="mxch-cg-option">
                                            <label class="mxch-cg-option-label" for="mxch-cg-status"><?php esc_html_e('Status', 'mxchat'); ?></label>
                                            <select id="mxch-cg-status" class="mxch-cg-select">
                                                <option value="draft"><?php esc_html_e('Draft', 'mxchat'); ?></option>
                                                <option value="future"><?php esc_html_e('Scheduled', 'mxchat'); ?></option>
                                                <option value="publish"><?php esc_html_e('Publish', 'mxchat'); ?></option>
                                            </select>
                                        </div>
                                        <div class="mxch-cg-option mxch-cg-schedule-wrap" style="display:none;">
                                            <label class="mxch-cg-option-label" for="mxch-cg-schedule"><?php esc_html_e('Schedule', 'mxchat'); ?></label>
                                            <input type="datetime-local" id="mxch-cg-schedule" class="mxch-cg-input">
                                        </div>
                                    </div>
                                    <div class="mxch-cg-options">
                                        <div class="mxch-cg-option">
                                            <label class="mxch-cg-option-label" for="mxch-cg-layout"><?php esc_html_e('Layout', 'mxchat'); ?></label>
                                            <select id="mxch-cg-layout" class="mxch-cg-select">
                                                <option value="default"><?php esc_html_e('Theme Default', 'mxchat'); ?></option>
                                                <option value="fullwidth" selected><?php esc_html_e('Fullwidth (No Padding)', 'mxchat'); ?></option>
                                            </select>
                                        </div>
                                        <div class="mxch-cg-option">
                                            <label class="mxch-cg-option-label" for="mxch-cg-title-display"><?php esc_html_e('Post Title', 'mxchat'); ?></label>
                                            <select id="mxch-cg-title-display" class="mxch-cg-select">
                                                <option value="show"><?php esc_html_e('Show', 'mxchat'); ?></option>
                                                <option value="hide" selected><?php esc_html_e('Hide (title in content)', 'mxchat'); ?></option>
                                            </select>
                                        </div>
                                        <div class="mxch-cg-option">
                                            <label class="mxch-cg-option-label" for="mxch-cg-template-mode"><?php esc_html_e('Template Mode', 'mxchat'); ?></label>
                                            <select id="mxch-cg-template-mode" class="mxch-cg-select">
                                                <option value="off" selected><?php esc_html_e('Off', 'mxchat'); ?></option>
                                                <option value="on"><?php esc_html_e('On (Spintax)', 'mxchat'); ?></option>
                                            </select>
                                        </div>
                                    </div>
                                    <button type="button" id="mxch-cg-generate-btn" class="mxch-cg-generate-btn mxch-cg-generate-btn-full">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                        <span><?php esc_html_e('Generate Content', 'mxchat'); ?></span>
                                    </button>
                                </div>
                                <!-- Progress (shown during generation) -->
                                <div id="mxch-cg-progress" class="mxch-cg-progress" style="display:none;">
                                    <div class="mxch-cg-progress-bar">
                                        <div class="mxch-cg-progress-fill" style="width:0%"></div>
                                    </div>
                                    <p class="mxch-cg-progress-text"><?php esc_html_e('Starting...', 'mxchat'); ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- Edit Default Prompt Modal -->
                        <div id="mxch-cg-prompt-modal" class="mxch-cg-prompt-modal" style="display:none;">
                            <div class="mxch-cg-prompt-modal-overlay"></div>
                            <div class="mxch-cg-prompt-modal-content">
                                <div class="mxch-cg-prompt-modal-header">
                                    <h3><?php esc_html_e('Edit Default System Prompt', 'mxchat'); ?></h3>
                                    <button type="button" id="mxch-cg-prompt-modal-close" class="mxch-cg-prompt-modal-close">&times;</button>
                                </div>
                                <div class="mxch-cg-prompt-modal-body">
                                    <p class="mxch-cg-prompt-modal-desc"><?php esc_html_e('This is the system prompt sent to the AI when generating content. Edit it to customize how your content is generated.', 'mxchat'); ?></p>
                                    <textarea id="mxch-cg-system-prompt-editor" class="mxch-cg-system-prompt-editor" rows="20"></textarea>
                                </div>
                                <div class="mxch-cg-prompt-modal-footer">
                                    <button type="button" id="mxch-cg-prompt-reset" class="button"><?php esc_html_e('Reset to Default', 'mxchat'); ?></button>
                                    <div class="mxch-cg-prompt-modal-footer-right">
                                        <button type="button" id="mxch-cg-prompt-cancel" class="button"><?php esc_html_e('Cancel', 'mxchat'); ?></button>
                                        <button type="button" id="mxch-cg-prompt-save" class="button button-primary"><?php esc_html_e('Save Changes', 'mxchat'); ?></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Loading indicator (shown during async generation) -->
                        <div id="mxch-cg-loading-indicator" class="mxch-cg-loading-indicator" style="display:none;">
                            <div class="mxch-cg-loading-spinner">
                                <div class="mxch-cg-loading-spinner-ring"></div>
                                <div class="mxch-cg-loading-spinner-ring"></div>
                                <div class="mxch-cg-loading-spinner-ring"></div>
                                <svg class="mxch-cg-loading-spinner-icon" xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            </div>
                            <p id="mxch-cg-loading-phrase" class="mxch-cg-loading-phrase"></p>
                            <div class="mxch-cg-loading-progress-mini">
                                <div class="mxch-cg-loading-progress-mini-bar">
                                    <div id="mxch-cg-loading-progress-fill" class="mxch-cg-loading-progress-mini-fill" style="width:0%"></div>
                                </div>
                                <p id="mxch-cg-loading-progress-text" class="mxch-cg-loading-progress-mini-text"><?php esc_html_e('Starting...', 'mxchat'); ?></p>
                            </div>
                        </div>
                        <iframe id="mxch-cg-preview-iframe" class="mxch-cg-preview-iframe" style="display:none;" sandbox="allow-same-origin allow-scripts"></iframe>
                    </div>
                </div>

                <!-- Mini Chat Panel (slides up from bottom, hidden by default) -->
                <div id="mxch-cg-chat" class="mxch-cg-chat-panel mxch-cg-chat-panel--with-images" style="display:none;">

                    <!-- Left Column: Images + Meta (Tabbed) -->
                    <div class="mxch-cg-images-col<?php echo $pro_image_management ? '' : ' mxch-cg-pro-locked'; ?>">
                        <!-- Tab Navigation -->
                        <div class="mxch-cg-left-tabs">
                            <button type="button" class="mxch-cg-left-tab active" data-tab="images">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                <?php esc_html_e('Images', 'mxchat'); ?>
                                <?php if (!$pro_image_management): ?>
                                    <span class="mxch-cg-pro-badge"><?php echo $is_activated ? esc_html__('ADD-ON', 'mxchat') : esc_html__('PRO', 'mxchat'); ?></span>
                                <?php endif; ?>
                            </button>
                            <button type="button" class="mxch-cg-left-tab" data-tab="meta">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/></svg>
                                <?php esc_html_e('Meta', 'mxchat'); ?>
                                <?php if (!$pro_meta_management): ?>
                                    <span class="mxch-cg-pro-badge"><?php echo $is_activated ? esc_html__('ADD-ON', 'mxchat') : esc_html__('PRO', 'mxchat'); ?></span>
                                <?php endif; ?>
                            </button>
                            <button type="button" class="mxch-cg-left-tab" data-tab="seo">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                <?php esc_html_e('SEO', 'mxchat'); ?>
                            </button>
                        </div>

                        <!-- Images Panel (default visible) -->
                        <div class="mxch-cg-left-panel active" id="mxch-cg-panel-images">
                            <div class="mxch-cg-images-grid" id="mxch-cg-images-grid">
                                <div class="mxch-cg-images-empty" id="mxch-cg-images-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                    <span><?php esc_html_e('Images will appear here after generation', 'mxchat'); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Meta Panel (hidden by default) -->
                        <div class="mxch-cg-left-panel" id="mxch-cg-panel-meta" style="position:relative;">
                            <div class="mxch-cg-meta-form<?php echo $pro_meta_management ? '' : ' mxch-cg-meta-locked'; ?>">
                                <div class="mxch-cg-meta-field">
                                    <label for="mxch-cg-meta-title"><?php esc_html_e('Title', 'mxchat'); ?></label>
                                    <input type="text" id="mxch-cg-meta-title" class="mxch-cg-meta-input" placeholder="<?php esc_attr_e('Post title...', 'mxchat'); ?>"<?php echo $pro_meta_management ? '' : ' disabled readonly'; ?>>
                                </div>
                                <div class="mxch-cg-meta-field">
                                    <label for="mxch-cg-meta-description"><?php esc_html_e('Meta Description', 'mxchat'); ?></label>
                                    <textarea id="mxch-cg-meta-description" class="mxch-cg-meta-input" rows="3" placeholder="<?php esc_attr_e('Meta description...', 'mxchat'); ?>"<?php echo $pro_meta_management ? '' : ' disabled readonly'; ?>></textarea>
                                    <span class="mxch-cg-meta-charcount">0 / 160</span>
                                </div>
                                <div class="mxch-cg-meta-field">
                                    <label for="mxch-cg-meta-keyword"><?php esc_html_e('Focus Keyword', 'mxchat'); ?></label>
                                    <input type="text" id="mxch-cg-meta-keyword" class="mxch-cg-meta-input" placeholder="<?php esc_attr_e('Primary keyword...', 'mxchat'); ?>"<?php echo $pro_meta_management ? '' : ' disabled readonly'; ?>>
                                </div>
                                <div class="mxch-cg-meta-field">
                                    <label for="mxch-cg-meta-excerpt"><?php esc_html_e('Excerpt', 'mxchat'); ?></label>
                                    <textarea id="mxch-cg-meta-excerpt" class="mxch-cg-meta-input" rows="3" placeholder="<?php esc_attr_e('Post excerpt...', 'mxchat'); ?>"<?php echo $pro_meta_management ? '' : ' disabled readonly'; ?>></textarea>
                                </div>
                            </div>
                            <?php if (!$pro_meta_management): ?>
                            <div class="mxch-cg-meta-lock-overlay">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <span><?php echo $is_activated ? esc_html__('Install Add-on', 'mxchat') : esc_html__('Upgrade to Pro', 'mxchat'); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- SEO Panel -->
                        <div class="mxch-cg-left-panel" id="mxch-cg-panel-seo">
                            <div class="mxch-seo-panel">
                                <!-- Score Header -->
                                <div class="mxch-seo-score-header">
                                    <div class="mxch-seo-score-ring">
                                        <svg viewBox="0 0 60 60">
                                            <circle class="mxch-seo-ring-bg" cx="30" cy="30" r="26" />
                                            <circle class="mxch-seo-ring-fill" id="mxch-seo-ring" cx="30" cy="30" r="26" stroke-dasharray="163.36" stroke-dashoffset="163.36" />
                                        </svg>
                                        <span class="mxch-seo-score-num" id="mxch-seo-score">&mdash;</span>
                                    </div>
                                    <div class="mxch-seo-score-info">
                                        <span class="mxch-seo-score-label" id="mxch-seo-score-label"><?php esc_html_e('SEO Score', 'mxchat'); ?></span>
                                        <span class="mxch-seo-score-summary" id="mxch-seo-score-summary"><?php esc_html_e('Generate content to analyze', 'mxchat'); ?></span>
                                    </div>
                                    <button type="button" class="mxch-seo-analyze-btn" id="mxch-seo-analyze" title="<?php esc_attr_e('Analyze', 'mxchat'); ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                                    </button>
                                </div>
                                <!-- Checklist -->
                                <div class="mxch-seo-checklist" id="mxch-seo-checklist">
                                    <div class="mxch-seo-empty" id="mxch-seo-empty">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                        <span><?php esc_html_e('SEO analysis will appear here after content is generated', 'mxchat'); ?></span>
                                    </div>
                                </div>
                                <!-- AI Optimize -->
                                <div class="mxch-seo-actions" id="mxch-seo-actions" style="display:none;">
                                    <button type="button" class="mxch-seo-fix-btn" id="mxch-seo-ai-optimize" title="<?php esc_attr_e('AI optimizes all failing SEO checks', 'mxchat'); ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.287 1.288L3 12l5.8 1.9a2 2 0 0 1 1.288 1.287L12 21l1.9-5.8a2 2 0 0 1 1.287-1.288L21 12l-5.8-1.9a2 2 0 0 1-1.288-1.287Z"/></svg>
                                        <?php esc_html_e('Optimize All', 'mxchat'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Chat Column -->
                    <div class="mxch-cg-chat-col">
                        <div class="mxch-cg-chat-panel-header">
                            <span class="mxch-cg-chat-panel-title"><?php esc_html_e('Edit with AI', 'mxchat'); ?></span>
                            <button type="button" id="mxch-cg-chat-close" class="mxch-cg-chat-panel-close" title="<?php esc_attr_e('Close', 'mxchat'); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                        <div id="mxch-cg-chat-messages" class="mxch-cg-chat-messages"></div>
                        <div class="mxch-cg-chat-input-wrap">
                            <textarea id="mxch-cg-chat-input" class="mxch-cg-chat-input" rows="2" placeholder="<?php esc_attr_e('e.g. Change the hero heading to...', 'mxchat'); ?>"></textarea>
                            <button type="button" id="mxch-cg-chat-send" class="mxch-cg-chat-send" disabled>
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 HISTORY
                 ======================================== -->
            <div id="content-history" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Content History', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Browse and edit your AI-generated content.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-body">
                        <!-- Loading state -->
                        <div id="mxch-cg-history-loading" class="mxch-cg-history-loading">
                            <div class="mxch-cg-history-spinner"></div>
                            <p><?php esc_html_e('Loading history...', 'mxchat'); ?></p>
                        </div>

                        <!-- Empty state -->
                        <div id="mxch-cg-history-empty" class="mxch-cg-history-empty" style="display:none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <p><?php esc_html_e('No AI-generated content yet. Use the Generate tab to create your first post.', 'mxchat'); ?></p>
                        </div>

                        <!-- History list (rendered by JS) -->
                        <div id="mxch-cg-history-list" class="mxch-cg-history-list" style="display:none;"></div>

                        <!-- Pagination (rendered by JS) -->
                        <div id="mxch-cg-history-pagination" class="mxch-cg-history-pagination" style="display:none;"></div>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 SETTINGS
                 ======================================== -->
            <div id="content-settings" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Settings', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Configure content generation and SEO optimization.', 'mxchat'); ?></p>
                </div>

                <!-- ── Content Settings ── -->
                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title"><?php esc_html_e('Content', 'mxchat'); ?></h3>
                    </div>
                    <div class="mxch-card-body">

                        <!-- Content Model -->
                        <div class="mxch-field">
                            <label class="mxch-field-label" for="mxch-content-model"><?php esc_html_e('Content Model', 'mxchat'); ?></label>
                            <div class="mxch-field-control">
                                <select id="mxch-content-model" data-field="content_model" class="mxch-cg-select">
                                    <?php
                                    $content_model = $options['content_model'] ?? ($options['model'] ?? 'gpt-5.1-chat-latest');
                                    $models = array(
                                        'OpenAI' => array(
                                            'gpt-5.5'              => 'GPT-5.5',
                                            'gpt-5.4'              => 'GPT-5.4',
                                            'gpt-5.2'              => 'GPT-5.2',
                                            'gpt-5.1-chat-latest'  => 'GPT-5.1',
                                            'gpt-5'                => 'GPT-5',
                                        ),
                                        'Anthropic' => array(
                                            'claude-fable-5'               => 'Claude Fable 5',
                                            'claude-opus-4-8'              => 'Claude Opus 4.8',
                                            'claude-opus-4-7'              => 'Claude Opus 4.7',
                                            'claude-opus-4-6'              => 'Claude Opus 4.6',
                                            'claude-sonnet-5'              => 'Claude Sonnet 5',
                                            'claude-sonnet-4-6'            => 'Claude Sonnet 4.6',
                                            'claude-haiku-4-5-20251001'    => 'Claude Haiku 4.5',
                                        ),
                                        'Google' => array(
                                            'gemini-3.5-flash'              => 'Gemini 3.5 Flash',
                                            'gemini-3.1-pro-preview'        => 'Gemini 3.1 Pro',
                                            'gemini-3-flash-preview'        => 'Gemini 3 Flash',
                                            'gemini-3.1-flash-lite'         => 'Gemini 3.1 Flash-Lite',
                                            'gemini-3.1-flash-lite-preview' => 'Gemini 3.1 Flash-Lite (Preview)',
                                            'gemini-2.5-pro'                => 'Gemini 2.5 Pro',
                                            'gemini-2.5-flash'              => 'Gemini 2.5 Flash',
                                            'gemini-2.5-flash-lite'         => 'Gemini 2.5 Flash-Lite',
                                        ),
                                        'xAI' => array(
                                            'grok-4-0709'                  => 'Grok 4',
                                            'grok-4-1-fast-non-reasoning'  => 'Grok 4.1 Fast',
                                            'grok-code-fast-1'             => 'Grok Code Fast',
                                        ),
                                    );
                                    foreach ($models as $group => $group_models) {
                                        echo '<optgroup label="' . esc_attr($group) . '">';
                                        foreach ($group_models as $value => $label) {
                                            $selected = selected($content_model, $value, false);
                                            echo '<option value="' . esc_attr($value) . '" ' . $selected . '>' . esc_html($label) . '</option>';
                                        }
                                        echo '</optgroup>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <p class="mxch-field-description"><?php esc_html_e('Select the AI model used for generating content. Uses the API key configured in your main MxChat settings.', 'mxchat'); ?></p>
                        </div>

                        <!-- Image Model -->
                        <div class="mxch-field">
                            <label class="mxch-field-label" for="mxch-image-model"><?php esc_html_e('Image Model', 'mxchat'); ?></label>
                            <div class="mxch-field-control">
                                <?php $image_model = $options['content_image_model'] ?? 'gpt-image-1.5'; ?>
                                <select id="mxch-image-model" data-field="content_image_model" class="mxch-cg-select">
                                    <optgroup label="<?php esc_attr_e('OpenAI', 'mxchat'); ?>">
                                        <option value="gpt-image-2" <?php selected($image_model, 'gpt-image-2'); ?>><?php esc_html_e('GPT Image 2 (Latest)', 'mxchat'); ?></option>
                                        <option value="gpt-image-1.5" <?php selected($image_model, 'gpt-image-1.5'); ?>><?php esc_html_e('GPT Image 1.5', 'mxchat'); ?></option>
                                    </optgroup>
                                    <optgroup label="<?php esc_attr_e('xAI', 'mxchat'); ?>">
                                        <option value="grok-imagine-image-pro" <?php selected($image_model, 'grok-imagine-image-pro'); ?>><?php esc_html_e('Grok Imagine Pro', 'mxchat'); ?></option>
                                        <option value="grok-imagine-image" <?php selected($image_model, 'grok-imagine-image'); ?>><?php esc_html_e('Grok Imagine', 'mxchat'); ?></option>
                                    </optgroup>
                                    <optgroup label="<?php esc_attr_e('Google', 'mxchat'); ?>">
                                        <option value="gemini-2.5-flash-image" <?php selected($image_model, 'gemini-2.5-flash-image'); ?>><?php esc_html_e('Nano Banana (Gemini 2.5 Flash)', 'mxchat'); ?></option>
                                        <option value="gemini-3-pro-image-preview" <?php selected($image_model, 'gemini-3-pro-image-preview'); ?>><?php esc_html_e('Nano Banana Pro (Gemini 3 Pro)', 'mxchat'); ?></option>
                                    </optgroup>
                                </select>
                            </div>
                            <p class="mxch-field-description"><?php esc_html_e('Select the AI model used for generating images within your content.', 'mxchat'); ?></p>
                        </div>

                        <!-- Image Quality (GPT Image only) -->
                        <div class="mxch-field">
                            <label class="mxch-field-label" for="mxch-image-quality"><?php esc_html_e('Image Quality', 'mxchat'); ?></label>
                            <div class="mxch-field-control">
                                <?php $image_quality = $options['content_image_quality'] ?? 'auto'; ?>
                                <?php $quality_supported = (strpos($image_model, 'gpt-image') === 0); ?>
                                <select id="mxch-image-quality" data-field="content_image_quality" class="mxch-cg-select"<?php echo $quality_supported ? '' : ' disabled'; ?>>
                                    <option value="auto" <?php selected($image_quality, 'auto'); ?>><?php esc_html_e('Auto (default)', 'mxchat'); ?></option>
                                    <option value="low" <?php selected($image_quality, 'low'); ?>><?php esc_html_e('Low (fastest, cheapest)', 'mxchat'); ?></option>
                                    <option value="medium" <?php selected($image_quality, 'medium'); ?>><?php esc_html_e('Medium', 'mxchat'); ?></option>
                                    <option value="high" <?php selected($image_quality, 'high'); ?>><?php esc_html_e('High (best, slowest)', 'mxchat'); ?></option>
                                </select>
                            </div>
                            <p class="mxch-field-description"><?php esc_html_e('Quality control for OpenAI GPT Image models only. Grok Imagine and Gemini image models ignore this setting (their APIs do not expose an equivalent parameter).', 'mxchat'); ?></p>
                        </div>
                        <script>(function(){
                            var m = document.getElementById('mxch-image-model');
                            var q = document.getElementById('mxch-image-quality');
                            if (!m || !q || q._wired) { return; } q._wired = true;
                            function sync(){ q.disabled = (m.value || '').indexOf('gpt-image') !== 0; }
                            m.addEventListener('change', sync);
                            sync();
                        })();</script>

                        <!-- Enable Image Generation -->
                        <div class="mxch-field">
                            <label class="mxch-toggle">
                                <?php $enable_images = $options['content_enable_images'] ?? 'on'; ?>
                                <input type="checkbox" class="mxch-toggle-input" data-field="content_enable_images" value="on" <?php checked($enable_images, 'on'); ?>>
                                <span class="mxch-toggle-switch"></span>
                                <span class="mxch-toggle-label"><?php esc_html_e('Enable Image Generation', 'mxchat'); ?></span>
                            </label>
                            <p class="mxch-field-description"><?php esc_html_e('When enabled, AI will generate and place images throughout your content. When disabled, pages are generated with text-only layouts.', 'mxchat'); ?></p>
                        </div>

                        <!-- Images per Article -->
                        <div class="mxch-field">
                            <label class="mxch-field-label" for="mxch-image-count"><?php esc_html_e('Images per Article', 'mxchat'); ?></label>
                            <div class="mxch-field-control">
                                <?php $image_count = max(1, min(5, (int) ($options['content_image_count'] ?? 3))); ?>
                                <?php $images_on = ($options['content_enable_images'] ?? 'on') === 'on'; ?>
                                <div class="mxch-cg-slider-row">
                                    <input type="range" min="1" max="5" step="1" value="<?php echo esc_attr($image_count); ?>"
                                           data-field="content_image_count" id="mxch-image-count" class="mxch-cg-slider"<?php echo $images_on ? '' : ' disabled'; ?>>
                                    <span id="mxch-image-count-val" class="mxch-cg-slider-value"><?php echo esc_html($image_count); ?></span>
                                </div>
                            </div>
                            <p class="mxch-field-description"><?php esc_html_e('How many images to generate and place in each article (1–5). Default 3. The AI may generate fewer if the article has few sections suited to imagery.', 'mxchat'); ?></p>
                        </div>
                        <script>(function(){
                            var s = document.getElementById('mxch-image-count');
                            var v = document.getElementById('mxch-image-count-val');
                            if (!s || !v || s._wired) { return; } s._wired = true;
                            s.addEventListener('input', function(){ v.textContent = s.value; });
                            var e = document.querySelector('[data-field="content_enable_images"]');
                            if (e) {
                                var syncCount = function(){ s.disabled = !e.checked; };
                                e.addEventListener('change', syncCount);
                                syncCount();
                            }
                        })();</script>

                        <!-- Internal Linking (Pro) -->
                        <div class="mxch-field mxch-cg-pro-field<?php echo $pro_internal_linking ? '' : ' mxch-cg-pro-locked'; ?>">
                            <label class="mxch-toggle">
                                <?php $internal_linking_val = $options['content_internal_linking'] ?? 'off'; ?>
                                <input type="checkbox" class="mxch-toggle-input" data-field="content_internal_linking" value="on" <?php checked($internal_linking_val, 'on'); ?><?php echo $pro_internal_linking ? '' : ' disabled'; ?>>
                                <span class="mxch-toggle-switch"></span>
                                <span class="mxch-toggle-label">
                                    <?php esc_html_e('Internal Linking', 'mxchat'); ?>
                                    <?php if (!$pro_internal_linking): ?>
                                        <span class="mxch-cg-pro-badge"><?php echo $is_activated ? esc_html__('ADD-ON', 'mxchat') : esc_html__('PRO', 'mxchat'); ?></span>
                                    <?php endif; ?>
                                </span>
                            </label>
                            <p class="mxch-field-description"><?php esc_html_e('Automatically find and link to existing blog posts on your site within generated content.', 'mxchat'); ?></p>
                            <?php if (!$pro_internal_linking): ?>
                                <?php if ($is_activated): ?>
                                    <a href="https://mxchat.ai/" target="_blank" class="mxch-cg-pro-upgrade-link"><?php esc_html_e('Install Add-on', 'mxchat'); ?></a>
                                <?php else: ?>
                                    <a href="https://mxchat.ai/" target="_blank" class="mxch-cg-pro-upgrade-link"><?php esc_html_e('Upgrade to Pro', 'mxchat'); ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Tool Use for AI Editing (Pro) -->
                        <div class="mxch-field mxch-cg-pro-field<?php echo $pro_tool_use ? '' : ' mxch-cg-pro-locked'; ?>">
                            <label class="mxch-toggle">
                                <?php $tool_use_val = $options['content_tool_use'] ?? 'off'; ?>
                                <input type="checkbox" class="mxch-toggle-input" data-field="content_tool_use" value="on" <?php checked($tool_use_val, 'on'); ?><?php echo $pro_tool_use ? '' : ' disabled'; ?>>
                                <span class="mxch-toggle-switch"></span>
                                <span class="mxch-toggle-label">
                                    <?php esc_html_e('Tool Use for AI Editing', 'mxchat'); ?>
                                    <?php if (!$pro_tool_use): ?>
                                        <span class="mxch-cg-pro-badge"><?php echo $is_activated ? esc_html__('ADD-ON', 'mxchat') : esc_html__('PRO', 'mxchat'); ?></span>
                                    <?php endif; ?>
                                </span>
                            </label>
                            <p class="mxch-field-description"><?php esc_html_e('Enable precise editing with tool calling — the AI makes targeted changes instead of rewriting the entire page, improving accuracy and token efficiency. Supports Claude, OpenAI, Gemini, and xAI models.', 'mxchat'); ?></p>
                            <?php if (!$pro_tool_use): ?>
                                <?php if ($is_activated): ?>
                                    <a href="https://mxchat.ai/" target="_blank" class="mxch-cg-pro-upgrade-link"><?php esc_html_e('Install Add-on', 'mxchat'); ?></a>
                                <?php else: ?>
                                    <a href="https://mxchat.ai/" target="_blank" class="mxch-cg-pro-upgrade-link"><?php esc_html_e('Upgrade to Pro', 'mxchat'); ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>

                <!-- ── SEO Settings ── -->
                <div class="mxch-card" style="margin-top: 16px;">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title"><?php esc_html_e('SEO', 'mxchat'); ?></h3>
                    </div>
                    <div class="mxch-card-body">

                        <?php if (!$pro_gsc_integration): ?>
                        <!-- Google Search Console (Pro/Add-on) -->
                        <div class="mxch-field mxch-gsc-settings-locked">
                            <div class="mxch-gsc-settings-locked-header">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                                <span><?php esc_html_e('Google Search Console', 'mxchat'); ?></span>
                                <span class="mxch-cg-pro-badge"><?php echo $is_activated ? esc_html__('ADD-ON', 'mxchat') : esc_html__('PRO', 'mxchat'); ?></span>
                            </div>
                            <p class="mxch-field-description"><?php esc_html_e('Connect Google Search Console to see real search performance data — clicks, impressions, CTR, and keyword rankings — directly in your SEO dashboard. Chat with your GSC data using the AI Admin Assistant add-on.', 'mxchat'); ?></p>
                            <button type="button" class="mxch-gsc-connect-btn" disabled>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <?php esc_html_e('Connect Google Search Console', 'mxchat'); ?>
                            </button>
                            <?php if ($is_activated): ?>
                                <a href="https://mxchat.ai/" target="_blank" class="mxch-cg-pro-upgrade-link"><?php esc_html_e('Install Add-on', 'mxchat'); ?></a>
                            <?php else: ?>
                                <a href="https://mxchat.ai/" target="_blank" class="mxch-cg-pro-upgrade-link"><?php esc_html_e('Upgrade to Pro', 'mxchat'); ?></a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- AI Optimize Fields -->
                        <?php
                        $seo_opt_meta   = $options['seo_optimize_meta_desc']      ?? 'on';
                        $seo_opt_title  = $options['seo_optimize_seo_title']      ?? 'on';
                        $seo_opt_slug   = $options['seo_optimize_slug']           ?? 'on';
                        $seo_opt_read   = $options['seo_optimize_readability']    ?? 'on';
                        $seo_opt_links  = $options['seo_optimize_internal_links'] ?? 'on';
                        $seo_opt_imgalt = $options['seo_optimize_img_alt']        ?? 'on';
                        $seo_opt_feat   = $options['seo_optimize_featured_img']   ?? 'on';
                        $has_addon = $pro_internal_linking; // any addon feature unlocked means addon is present
                        ?>
                        <div class="mxch-field">
                            <label class="mxch-field-label"><?php esc_html_e('AI Optimize', 'mxchat'); ?></label>
                            <p class="mxch-field-description" style="margin-bottom: 10px;"><?php esc_html_e('Choose which checks AI Optimize will fix when run.', 'mxchat'); ?></p>
                            <div class="mxch-seo-opt-grid">
                                <label class="mxch-seo-opt-item">
                                    <input type="checkbox" class="mxch-seo-opt-check" data-field="seo_optimize_meta_desc" <?php checked($seo_opt_meta, 'on'); ?>>
                                    <span><?php esc_html_e('Meta Description', 'mxchat'); ?></span>
                                </label>
                                <label class="mxch-seo-opt-item">
                                    <input type="checkbox" class="mxch-seo-opt-check" data-field="seo_optimize_seo_title" <?php checked($seo_opt_title, 'on'); ?>>
                                    <span><?php esc_html_e('SEO Title', 'mxchat'); ?></span>
                                </label>
                                <label class="mxch-seo-opt-item">
                                    <input type="checkbox" class="mxch-seo-opt-check" data-field="seo_optimize_slug" <?php checked($seo_opt_slug, 'on'); ?>>
                                    <span><?php esc_html_e('URL Slug', 'mxchat'); ?></span>
                                </label>
                                <label class="mxch-seo-opt-item<?php echo $has_addon ? '' : ' mxch-seo-opt-locked'; ?>">
                                    <input type="checkbox" class="mxch-seo-opt-check" data-field="seo_optimize_readability" <?php checked($seo_opt_read, 'on'); ?><?php echo $has_addon ? '' : ' disabled'; ?>>
                                    <span><?php esc_html_e('Readability', 'mxchat'); ?></span>
                                    <?php if (!$has_addon): ?>
                                        <span class="mxch-cg-pro-badge"><?php echo $is_activated ? esc_html__('ADD-ON', 'mxchat') : esc_html__('PRO', 'mxchat'); ?></span>
                                    <?php endif; ?>
                                </label>
                                <label class="mxch-seo-opt-item<?php echo $has_addon ? '' : ' mxch-seo-opt-locked'; ?>">
                                    <input type="checkbox" class="mxch-seo-opt-check" data-field="seo_optimize_internal_links" <?php checked($seo_opt_links, 'on'); ?><?php echo $has_addon ? '' : ' disabled'; ?>>
                                    <span><?php esc_html_e('Internal Links', 'mxchat'); ?></span>
                                    <?php if (!$has_addon): ?>
                                        <span class="mxch-cg-pro-badge"><?php echo $is_activated ? esc_html__('ADD-ON', 'mxchat') : esc_html__('PRO', 'mxchat'); ?></span>
                                    <?php endif; ?>
                                </label>
                                <label class="mxch-seo-opt-item<?php echo $has_addon ? '' : ' mxch-seo-opt-locked'; ?>">
                                    <input type="checkbox" class="mxch-seo-opt-check" data-field="seo_optimize_img_alt" <?php checked($seo_opt_imgalt, 'on'); ?><?php echo $has_addon ? '' : ' disabled'; ?>>
                                    <span><?php esc_html_e('Image ALT Text', 'mxchat'); ?></span>
                                    <?php if (!$has_addon): ?>
                                        <span class="mxch-cg-pro-badge"><?php echo $is_activated ? esc_html__('ADD-ON', 'mxchat') : esc_html__('PRO', 'mxchat'); ?></span>
                                    <?php endif; ?>
                                </label>
                                <label class="mxch-seo-opt-item<?php echo $has_addon ? '' : ' mxch-seo-opt-locked'; ?>">
                                    <input type="checkbox" class="mxch-seo-opt-check" data-field="seo_optimize_featured_img" <?php checked($seo_opt_feat, 'on'); ?><?php echo $has_addon ? '' : ' disabled'; ?>>
                                    <span><?php esc_html_e('Featured Image', 'mxchat'); ?></span>
                                    <?php if (!$has_addon): ?>
                                        <span class="mxch-cg-pro-badge"><?php echo $is_activated ? esc_html__('ADD-ON', 'mxchat') : esc_html__('PRO', 'mxchat'); ?></span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ========================================
                 SEO
                 ======================================== -->
            <div id="content-seo" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('SEO Analysis', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Analyze and optimize SEO for all your content.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-body" style="padding:0;">
                        <!-- Filter Bar -->
                        <div class="mxch-seod-filters">
                            <div class="mxch-seod-filters-left">
                                <select id="mxch-seod-post-type" class="mxch-seod-select">
                                    <option value="any"><?php esc_html_e('All Types', 'mxchat'); ?></option>
                                    <option value="post"><?php esc_html_e('Posts', 'mxchat'); ?></option>
                                    <option value="page"><?php esc_html_e('Pages', 'mxchat'); ?></option>
                                    <?php
                                    $extra_types = get_post_types(array('public' => true, '_builtin' => false), 'objects');
                                    foreach ($extra_types as $pt) {
                                        if (in_array($pt->name, array('attachment', 'elementor_library', 'e-landing-page'), true)) continue;
                                        printf('<option value="%s">%s</option>', esc_attr($pt->name), esc_html($pt->labels->name));
                                    }
                                    ?>
                                </select>
                                <div class="mxch-seod-filter-pills">
                                    <button type="button" class="mxch-seod-pill active" data-filter="all"><?php esc_html_e('All', 'mxchat'); ?></button>
                                    <button type="button" class="mxch-seod-pill" data-filter="issues"><?php esc_html_e('Issues', 'mxchat'); ?></button>
                                    <button type="button" class="mxch-seod-pill" data-filter="good"><?php esc_html_e('Good', 'mxchat'); ?></button>
                                    <button type="button" class="mxch-seod-pill" data-filter="unscored"><?php esc_html_e('Not Scanned', 'mxchat'); ?></button>
                                </div>
                            </div>
                            <div class="mxch-seod-filters-right">
                                <?php if (!$has_addon): ?>
                                    <span class="mxch-seod-bulk-note" id="mxch-seod-bulk-note" style="display:none;"><?php echo $is_activated ? esc_html__('Bulk optimize requires the Advanced Content Editor add-on', 'mxchat') : esc_html__('Bulk optimize requires MxChat Pro', 'mxchat'); ?></span>
                                <?php endif; ?>
                                <button type="button" class="mxch-seod-scan-btn mxch-seod-optimize-selected-btn<?php echo $has_addon ? '' : ' mxch-seod-bulk-locked'; ?>" id="mxch-seod-optimize-selected" style="display:none;"<?php echo $has_addon ? '' : ' disabled title="' . esc_attr__('Pro required for bulk optimization', 'mxchat') . '"'; ?>>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.287 1.288L3 12l5.8 1.9a2 2 0 0 1 1.288 1.287L12 21l1.9-5.8a2 2 0 0 1 1.287-1.288L21 12l-5.8-1.9a2 2 0 0 1-1.288-1.287Z"/></svg>
                                    <span><?php esc_html_e('Optimize Selected', 'mxchat'); ?></span>
                                    <?php if (!$has_addon): ?>
                                        <span class="mxch-cg-pro-badge"><?php echo $is_activated ? esc_html__('ADD-ON', 'mxchat') : esc_html__('PRO', 'mxchat'); ?></span>
                                    <?php endif; ?>
                                </button>
                                <button type="button" class="mxch-seod-scan-btn mxch-seod-scan-stop-btn" id="mxch-seod-optimize-stop" style="display:none;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
                                    <span><?php esc_html_e('Stop', 'mxchat'); ?></span>
                                </button>
                                <div class="mxch-seod-search-wrap">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    <input type="text" id="mxch-seod-search" class="mxch-seod-search" placeholder="<?php esc_attr_e('Search posts...', 'mxchat'); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Posts Table -->
                        <div class="mxch-seod-table-wrap">
                            <div id="mxch-seod-table" class="mxch-seod-table">
                                <!-- Rendered by JS -->
                            </div>
                            <div id="mxch-seod-loading" class="mxch-seod-loading">
                                <div class="mxch-cg-history-spinner"></div>
                                <p><?php esc_html_e('Loading posts...', 'mxchat'); ?></p>
                            </div>
                            <div id="mxch-seod-empty" class="mxch-seod-empty" style="display:none;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg>
                                <p><?php esc_html_e('No posts found matching your filters.', 'mxchat'); ?></p>
                            </div>
                        </div>

                        <!-- Footer: Scan + Pagination -->
                        <div class="mxch-seod-footer" id="mxch-seod-footer" style="display:none;">
                            <div class="mxch-seod-footer-left">
                                <button type="button" class="mxch-seod-scan-btn" id="mxch-seod-scan-all">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg>
                                    <span><?php esc_html_e('Scan Unscored', 'mxchat'); ?></span>
                                </button>
                                <span class="mxch-seod-scan-status" id="mxch-seod-scan-status"></span>
                            </div>
                            <div class="mxch-seod-pagination" id="mxch-seod-pagination">
                                <!-- Rendered by JS -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 CALENDAR
                 ======================================== -->
            <div id="content-calendar" class="mxch-section">
                <?php if ($pro_content_calendar) : ?>
                    <!-- Add-on is active — container for calendar JS to populate -->
                    <div class="mxcal-container" id="mxcal-container"></div>
                <?php else : ?>
                    <!-- Add-on not installed — show blurred demo calendar -->
                    <style>
                        .mxcal-demo-wrap { position: relative; }
                        .mxcal-demo-blur { filter: blur(3px); pointer-events: none; user-select: none; opacity: 0.7; }
                        .mxcal-demo-overlay {
                            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
                            z-index: 10; text-align: center; background: rgba(255,255,255,0.95);
                            border-radius: 16px; padding: 40px 48px; box-shadow: 0 8px 40px rgba(120,115,245,0.15);
                            border: 1px solid rgba(120,115,245,0.12); max-width: 460px; width: 90%;
                        }
                        .mxcal-demo-overlay h2 {
                            font-size: 22px; font-weight: 700; margin: 0 0 8px; color: #1A202C;
                        }
                        .mxcal-demo-overlay p { font-size: 14px; color: #718096; line-height: 1.6; margin: 0 0 20px; }
                        .mxcal-demo-overlay .mxcal-demo-badge {
                            display: inline-block; background: linear-gradient(135deg, #fa73e6, #7873f5);
                            color: #fff; font-size: 11px; font-weight: 700; padding: 4px 12px;
                            border-radius: 20px; margin-bottom: 16px; letter-spacing: 0.5px;
                        }
                        .mxcal-demo-overlay a.mxcal-demo-btn {
                            display: inline-block; background: linear-gradient(135deg, #fa73e6, #7873f5);
                            color: #fff; font-size: 14px; font-weight: 600; padding: 12px 28px;
                            border-radius: 10px; text-decoration: none; transition: all 0.2s;
                            box-shadow: 0 4px 14px rgba(120,115,245,0.25);
                        }
                        .mxcal-demo-overlay a.mxcal-demo-btn:hover {
                            box-shadow: 0 6px 20px rgba(120,115,245,0.35); transform: translateY(-1px);
                        }
                        .mxcal-demo-grid {
                            display: grid; grid-template-columns: repeat(7, 1fr);
                            border: 1px solid #E2E8F0; border-radius: 12px; overflow: hidden; background: #fff;
                        }
                        .mxcal-demo-hdr {
                            background: #F7FAFC; padding: 10px 6px; text-align: center;
                            font-size: 11px; font-weight: 600; color: #A0AEC0; text-transform: uppercase;
                            border-bottom: 1px solid #E2E8F0;
                        }
                        .mxcal-demo-day {
                            min-height: 90px; padding: 8px; border-right: 1px solid #F0F0F5;
                            border-bottom: 1px solid #F0F0F5; display: flex; flex-direction: column; gap: 4px;
                        }
                        .mxcal-demo-day:nth-child(7n) { border-right: none; }
                        .mxcal-demo-num { font-size: 12px; font-weight: 600; color: #A0AEC0; }
                        .mxcal-demo-kw { font-size: 10px; font-weight: 600; color: #2D3748; line-height: 1.2; }
                        .mxcal-demo-stats { display: flex; gap: 4px; margin-top: auto; }
                        .mxcal-demo-vol { font-size: 9px; background: #EDF2F7; padding: 1px 5px; border-radius: 3px; color: #718096; }
                        .mxcal-demo-kd { font-size: 9px; padding: 1px 5px; border-radius: 3px; font-weight: 700; }
                        .mxcal-demo-easy { background: rgba(16,185,129,0.1); color: #059669; }
                        .mxcal-demo-med { background: rgba(245,158,11,0.1); color: #d97706; }
                        .mxcal-demo-hard { background: rgba(239,68,68,0.1); color: #dc2626; }
                        .mxcal-demo-planned { border-left: 3px solid #CBD5E0; }
                        .mxcal-demo-done { border-left: 3px solid #10b981; }
                        .mxcal-demo-empty { background: #FAFAFA; }
                        .mxcal-demo-header {
                            display: flex; justify-content: space-between; align-items: center;
                            margin-bottom: 16px; flex-wrap: wrap; gap: 12px;
                        }
                        .mxcal-demo-header h3 { font-size: 18px; font-weight: 700; color: #1A202C; margin: 0; }
                        .mxcal-demo-month {
                            display: flex; align-items: center; gap: 16px; justify-content: center; margin-bottom: 14px;
                        }
                        .mxcal-demo-month span { font-size: 16px; font-weight: 700; color: #2D3748; }
                        .mxcal-demo-month-btn {
                            background: none; border: 1px solid #E2E8F0; border-radius: 6px;
                            padding: 4px 12px; font-size: 12px; color: #718096;
                        }
                    </style>
                    <div class="mxcal-demo-wrap">
                        <div class="mxcal-demo-blur">
                            <div class="mxcal-demo-header">
                                <h3>Content Calendar</h3>
                                <div style="display:flex;gap:8px;">
                                    <span style="background:#EDF2F7;padding:4px 12px;border-radius:20px;font-size:12px;color:#4A5568;">mxchat.ai</span>
                                    <span style="background:linear-gradient(135deg,#fa73e6,#7873f5);padding:4px 12px;border-radius:20px;font-size:11px;color:#fff;font-weight:600;">AI Chatbot Plugin</span>
                                </div>
                            </div>
                            <div class="mxcal-demo-month">
                                <button class="mxcal-demo-month-btn">&larr;</button>
                                <span><?php echo esc_html(wp_date('F Y')); ?></span>
                                <button class="mxcal-demo-month-btn">&rarr;</button>
                            </div>
                            <div class="mxcal-demo-grid">
                                <?php
                                $days = array('Sun','Mon','Tue','Wed','Thu','Fri','Sat');
                                foreach ($days as $d) echo '<div class="mxcal-demo-hdr">' . $d . '</div>';

                                $demo_posts = array(
                                    array('kw' => 'wordpress chatbot plugin', 'vol' => '2,400', 'kd' => 'KD 28', 'cls' => 'easy', 'st' => 'done'),
                                    array('kw' => 'ai customer support', 'vol' => '1,900', 'kd' => 'KD 35', 'cls' => 'med', 'st' => 'done'),
                                    array('kw' => 'chatbot for ecommerce', 'vol' => '1,600', 'kd' => 'KD 22', 'cls' => 'easy', 'st' => 'done'),
                                    array('kw' => 'gpt wordpress integration', 'vol' => '1,100', 'kd' => 'KD 41', 'cls' => 'med', 'st' => 'planned'),
                                    array('kw' => 'live chat vs chatbot', 'vol' => '3,200', 'kd' => 'KD 18', 'cls' => 'easy', 'st' => 'planned'),
                                    array('kw' => 'ai lead generation', 'vol' => '2,800', 'kd' => 'KD 52', 'cls' => 'med', 'st' => 'planned'),
                                    array('kw' => 'reduce support tickets ai', 'vol' => '880', 'kd' => 'KD 15', 'cls' => 'easy', 'st' => 'planned'),
                                    array('kw' => 'best chatbot plugins 2026', 'vol' => '4,100', 'kd' => 'KD 62', 'cls' => 'hard', 'st' => 'planned'),
                                    array('kw' => 'woocommerce chatbot', 'vol' => '1,400', 'kd' => 'KD 30', 'cls' => 'med', 'st' => 'planned'),
                                    array('kw' => 'automate faq wordpress', 'vol' => '720', 'kd' => 'KD 12', 'cls' => 'easy', 'st' => 'planned'),
                                    array('kw' => 'claude vs chatgpt wordpress', 'vol' => '2,100', 'kd' => 'KD 38', 'cls' => 'med', 'st' => 'planned'),
                                    array('kw' => 'ai content generation', 'vol' => '5,400', 'kd' => 'KD 68', 'cls' => 'hard', 'st' => 'planned'),
                                );

                                $first_day = (int) wp_date('w', strtotime('first day of this month'));
                                $days_in_month = (int) wp_date('t');
                                $post_idx = 0;

                                for ($i = 0; $i < $first_day; $i++) echo '<div class="mxcal-demo-day mxcal-demo-empty"></div>';

                                for ($d = 1; $d <= $days_in_month; $d++) {
                                    if ($post_idx < count($demo_posts)) {
                                        $p = $demo_posts[$post_idx];
                                        $st_cls = $p['st'] === 'done' ? 'mxcal-demo-done' : 'mxcal-demo-planned';
                                        echo '<div class="mxcal-demo-day ' . $st_cls . '">';
                                        echo '<span class="mxcal-demo-num">' . $d . '</span>';
                                        echo '<span class="mxcal-demo-kw">' . esc_html($p['kw']) . '</span>';
                                        echo '<div class="mxcal-demo-stats">';
                                        echo '<span class="mxcal-demo-vol">' . $p['vol'] . '</span>';
                                        echo '<span class="mxcal-demo-kd mxcal-demo-' . $p['cls'] . '">' . $p['kd'] . '</span>';
                                        echo '</div></div>';
                                        $post_idx++;
                                    } else {
                                        echo '<div class="mxcal-demo-day mxcal-demo-empty"><span class="mxcal-demo-num">' . $d . '</span></div>';
                                    }
                                }

                                $total = $first_day + $days_in_month;
                                $remaining = $total % 7 === 0 ? 0 : 7 - ($total % 7);
                                for ($i = 0; $i < $remaining; $i++) echo '<div class="mxcal-demo-day mxcal-demo-empty"></div>';
                                ?>
                            </div>
                        </div>
                        <div class="mxcal-demo-overlay">
                            <span class="mxcal-demo-badge">PRO ADD-ON</span>
                            <h2>AI Content Calendar</h2>
                            <p>Import SEO data and let AI generate a 30-day blog plan with targeted keywords, difficulty scores, and automated post scheduling.</p>
                            <a href="https://mxchat.ai/advanced-content-editor/" target="_blank" class="mxcal-demo-btn">Get Advanced Content Editor</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ========================================
                 TEMPLATES
                 ======================================== -->
            <div id="content-templates" class="mxch-section">
                <?php if ($pro_template_generator) : ?>
                    <div id="mxtpl-container"></div>
                <?php else : ?>
                    <style>
                        .mxtpl-demo-wrap { position: relative; }
                        .mxtpl-demo-blur { filter: blur(3px); pointer-events: none; user-select: none; opacity: 0.7; padding: 24px; }
                        .mxtpl-demo-overlay {
                            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
                            z-index: 10; text-align: center; background: rgba(255,255,255,0.95);
                            border-radius: 16px; padding: 40px 48px; box-shadow: 0 8px 40px rgba(120,115,245,0.15);
                            border: 1px solid rgba(120,115,245,0.12); max-width: 460px; width: 90%;
                        }
                        .mxtpl-demo-overlay h2 { font-size: 22px; font-weight: 700; margin: 0 0 8px; color: #1A202C; }
                        .mxtpl-demo-overlay p { font-size: 14px; color: #718096; line-height: 1.6; margin: 0 0 20px; }
                        .mxtpl-demo-badge { display: inline-block; background: linear-gradient(135deg, #fa73e6, #7873f5); color: #fff; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; margin-bottom: 16px; letter-spacing: 0.5px; }
                        .mxtpl-demo-btn { display: inline-block; background: linear-gradient(135deg, #fa73e6, #7873f5); color: #fff; font-size: 14px; font-weight: 600; padding: 12px 28px; border-radius: 10px; text-decoration: none; box-shadow: 0 4px 14px rgba(120,115,245,0.25); }
                        .mxtpl-demo-section { margin-bottom: 20px; }
                        .mxtpl-demo-label { font-size: 14px; font-weight: 700; color: #2D3748; margin-bottom: 8px; }
                        .mxtpl-demo-textarea { width: 100%; padding: 12px; border: 2px solid #E2E8F0; border-radius: 8px; font-size: 13px; color: #4A5568; background: #F7FAFC; resize: none; font-family: inherit; }
                        .mxtpl-demo-pills { display: flex; gap: 6px; margin-bottom: 12px; flex-wrap: wrap; }
                        .mxtpl-demo-pill { background: rgba(120,115,245,0.08); color: #7873f5; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; font-family: monospace; }
                        .mxtpl-demo-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
                        .mxtpl-demo-field { display: flex; flex-direction: column; gap: 4px; }
                        .mxtpl-demo-field label { font-size: 12px; font-weight: 600; color: #718096; }
                        .mxtpl-demo-field select, .mxtpl-demo-field input { padding: 8px 10px; border: 2px solid #E2E8F0; border-radius: 6px; font-size: 13px; background: #F7FAFC; }
                    </style>
                    <div class="mxtpl-demo-wrap">
                        <div class="mxtpl-demo-blur">
                            <div class="mxtpl-demo-section">
                                <div class="mxtpl-demo-label">1. Upload Data</div>
                                <button style="padding:8px 18px;border:1px solid #E2E8F0;border-radius:8px;background:#F7FAFC;color:#718096;font-size:13px;cursor:default;">Upload CSV</button>
                                <span style="font-size:12px;color:#10b981;margin-left:8px;">24 rows loaded</span>
                            </div>
                            <div class="mxtpl-demo-section">
                                <div class="mxtpl-demo-label">2. Content Source</div>
                                <div class="mxtpl-demo-pills">
                                    <span class="mxtpl-demo-pill">{city}</span>
                                    <span class="mxtpl-demo-pill">{state}</span>
                                    <span class="mxtpl-demo-pill">{service}</span>
                                    <span class="mxtpl-demo-pill">{phone}</span>
                                </div>
                                <textarea class="mxtpl-demo-textarea" rows="3" readonly>Write a {comprehensive|detailed|in-depth} service page about {service} in {city}, {state}. Highlight our expertise and include a call to action with {phone}.</textarea>
                            </div>
                            <div class="mxtpl-demo-section">
                                <div class="mxtpl-demo-label">3. Settings</div>
                                <div class="mxtpl-demo-grid">
                                    <div class="mxtpl-demo-field"><label>Content Type</label><select><option>Page</option></select></div>
                                    <div class="mxtpl-demo-field"><label>Status</label><select><option>Draft</option></select></div>
                                    <div class="mxtpl-demo-field"><label>Slug Template</label><input type="text" value="{service}-in-{city}" readonly /></div>
                                </div>
                            </div>
                        </div>
                        <div class="mxtpl-demo-overlay">
                            <span class="mxtpl-demo-badge">PRO ADD-ON</span>
                            <h2>Spintax & Template Generator</h2>
                            <p>Generate bulk pages from CSV data with spintax variations. Create hundreds of unique, AI-written city pages, service pages, and landing pages.</p>
                            <a href="https://mxchat.ai/advanced-content-editor/" target="_blank" class="mxtpl-demo-btn">Get Advanced Content Editor</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
    <?php
}
