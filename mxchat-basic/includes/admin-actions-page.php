<?php
/**
 * MxChat Actions Page - Redesigned with Sidebar Navigation
 *
 * @package MxChat
 * @since 2.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render the new sidebar-based Actions page
 */
function mxchat_render_actions_page($admin_instance, $page_data) {
    $is_activated = $admin_instance->is_activated();
    $plugin_url = plugin_dir_url(dirname(__FILE__));

    // Extract page data
    extract($page_data);
    ?>
    <div class="mxch-admin-wrapper mxch-actions-wrapper">
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
                <span class="mxch-mobile-menu-title"><?php esc_html_e('Actions', 'mxchat'); ?></span>
                <button type="button" class="mxch-mobile-menu-close" aria-label="<?php esc_attr_e('Close menu', 'mxchat'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <nav class="mxch-mobile-menu-nav">
                <!-- Actions umbrella: Dashboard, then AI Tools (recommended), then Trigger Phrases -->
                <div class="mxch-mobile-nav-section">
                    <div class="mxch-mobile-nav-section-title"><?php esc_html_e('Actions', 'mxchat'); ?></div>
                    <button class="mxch-mobile-nav-link <?php echo (isset($active_tab) && $active_tab === 'dashboard') ? 'active' : ''; ?>" data-target="dashboard">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                        <span><?php esc_html_e('Dashboard', 'mxchat'); ?></span>
                    </button>
                    <button class="mxch-mobile-nav-link <?php echo (isset($active_tab) && $active_tab === 'ai-tools') ? 'active' : ''; ?>" data-target="ai-tools">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        <span><?php esc_html_e('AI Tools', 'mxchat'); ?></span>
                    </button>
                    <button class="mxch-mobile-nav-link" data-target="all-actions">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        <span><?php esc_html_e('Trigger Phrases', 'mxchat'); ?></span>
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
                <!-- Actions umbrella: Dashboard, then AI Tools (recommended), then Trigger Phrases -->
                <div class="mxch-nav-section">
                    <div class="mxch-nav-section-title"><?php esc_html_e('Actions', 'mxchat'); ?></div>

                    <div class="mxch-nav-item" data-section="dashboard">
                        <button class="mxch-nav-link <?php echo (isset($active_tab) && $active_tab === 'dashboard') ? 'active' : ''; ?>" data-target="dashboard">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Dashboard', 'mxchat'); ?></span>
                        </button>
                    </div>

                    <div class="mxch-nav-item" data-section="ai-tools">
                        <button class="mxch-nav-link <?php echo (isset($active_tab) && $active_tab === 'ai-tools') ? 'active' : ''; ?>" data-target="ai-tools">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('AI Tools', 'mxchat'); ?></span>
                            <span class="mxch-nav-link-badge"><?php echo esc_html($total_tools); ?></span>
                        </button>
                    </div>

                    <div class="mxch-nav-item" data-section="all-actions">
                        <button class="mxch-nav-link" data-target="all-actions">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Trigger Phrases', 'mxchat'); ?></span>
                            <span class="mxch-nav-link-badge"><?php echo esc_html($total_actions); ?></span>
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
            <!-- Dashboard Section -->
            <div id="dashboard" class="mxch-section <?php echo (isset($active_tab) && $active_tab === 'dashboard') ? 'active' : ''; ?>">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Actions', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Give your chatbot the ability to do things — generate images, search the web, hand off to a human, capture an email, and more. There are two ways to set this up. The cards below explain when to use each.', 'mxchat'); ?></p>
                </div>

                <!-- Two ways to set up actions — landing explainer -->
                <div class="mxch-info-section">
                    <div class="mxch-info-section-header">
                        <div class="mxch-info-section-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        </div>
                        <h3 class="mxch-info-section-title"><?php esc_html_e('Two ways to set up actions', 'mxchat'); ?></h3>
                    </div>
                    <p class="mxch-info-section-desc">
                        <?php esc_html_e('Both let your chatbot take action during a conversation. Pick whichever fits how you want it to behave — or use both together.', 'mxchat'); ?>
                    </p>

                    <div class="mxch-approach-grid">
                        <!-- AI Tools (recommended) -->
                        <div class="mxch-approach-card mxch-approach-card-primary">
                            <div class="mxch-approach-card-head">
                                <div class="mxch-approach-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                                </div>
                                <div class="mxch-approach-titles">
                                    <h4 class="mxch-approach-title"><?php esc_html_e('AI Tools', 'mxchat'); ?></h4>
                                    <span class="mxch-approach-pill"><?php esc_html_e('Recommended', 'mxchat'); ?></span>
                                </div>
                            </div>
                            <p class="mxch-approach-desc"><?php esc_html_e('Add the tools your chatbot is allowed to use, and the AI decides when to use each one from the conversation. Easiest to set up — there are no phrases to write. Best for most sites.', 'mxchat'); ?></p>
                            <button type="button" class="mxch-btn mxch-btn-primary mxch-approach-btn" data-approach-target="ai-tools">
                                <?php esc_html_e('Set up AI Tools', 'mxchat'); ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                            </button>
                        </div>
                        <!-- Trigger Phrases -->
                        <div class="mxch-approach-card">
                            <div class="mxch-approach-card-head">
                                <div class="mxch-approach-icon mxch-approach-icon-muted">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                </div>
                                <div class="mxch-approach-titles">
                                    <h4 class="mxch-approach-title"><?php esc_html_e('Trigger Phrases', 'mxchat'); ?></h4>
                                </div>
                            </div>
                            <p class="mxch-approach-desc"><?php esc_html_e('You write the exact phrases that should fire a capability, with a match-strength slider. Precise and predictable — best when you want tight control over exactly when something happens.', 'mxchat'); ?></p>
                            <button type="button" class="mxch-btn mxch-btn-secondary mxch-approach-btn" id="mxch-add-action-dashboard-btn">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <?php esc_html_e('Add Trigger Phrase', 'mxchat'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="mxch-approach-note">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <span><strong><?php esc_html_e('Can I use both?', 'mxchat'); ?></strong> <?php esc_html_e('Yes. When a visitor\'s message matches one of your trigger phrases, that fires first; AI Tools cover everything else. Many sites run both together.', 'mxchat'); ?></span>
                    </div>
                </div>

            </div>

            <!-- All Actions Section - Split Panel Layout -->
            <div id="all-actions" class="mxch-section">
                <div class="mxch-split-panel">
                    <!-- Left Panel - Action List -->
                    <div class="mxch-action-list-panel">
                        <!-- Bulk Actions Toolbar -->
                        <div class="mxch-bulk-toolbar">
                            <label class="mxch-bulk-select-all">
                                <input type="checkbox" id="mxch-select-all-actions">
                                <span><?php esc_html_e('All', 'mxchat'); ?></span>
                            </label>
                            <span class="mxch-selected-count" id="mxch-selected-action-count">0</span>
                            <div class="mxch-bulk-actions">
                                <button type="button" class="mxch-bulk-btn mxch-bulk-delete" id="mxch-delete-selected-actions" disabled title="<?php esc_attr_e('Delete Selected', 'mxchat'); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="mxch-panel-header">
                            <div class="mxch-search-wrapper">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                <input type="text" id="mxch-search-actions" class="mxch-search-input" placeholder="<?php esc_attr_e('Search trigger phrases...', 'mxchat'); ?>">
                            </div>
                            <div class="mxch-panel-title-row">
                                <span class="mxch-panel-count" id="mxch-action-count">0 / 0 trigger phrases</span>
                                <div class="mxch-panel-actions">
                                    <button type="button" id="mxch-add-action-btn" class="mxch-btn mxch-btn-primary mxch-btn-sm" title="<?php esc_attr_e('Add Trigger Phrase', 'mxchat'); ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                        <?php esc_html_e('Add', 'mxchat'); ?>
                                    </button>
                                    <button type="button" id="mxch-refresh-actions" class="mxch-icon-btn" title="<?php esc_attr_e('Refresh', 'mxchat'); ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="mxch-action-list" id="mxch-action-list">
                            <!-- Action list items loaded via AJAX -->
                        </div>
                        <div class="mxch-panel-footer">
                            <div class="mxch-pagination-simple" id="mxch-actions-pagination">
                                <!-- Pagination controls -->
                            </div>
                        </div>
                    </div>

                    <!-- Right Panel - Action Details/Editor -->
                    <div class="mxch-action-detail-panel" id="mxch-action-detail-panel">
                        <!-- Empty State -->
                        <div class="mxch-action-empty" id="mxch-action-empty">
                            <div class="mxch-empty-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            </div>
                            <h3><?php esc_html_e('Select a trigger phrase', 'mxchat'); ?></h3>
                            <p><?php esc_html_e('Choose a trigger phrase from the list to view details and edit, or create a new one.', 'mxchat'); ?></p>
                            <button type="button" id="mxch-create-first-action-btn" class="mxch-btn mxch-btn-primary">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <?php esc_html_e('Create Trigger Phrase', 'mxchat'); ?>
                            </button>
                        </div>

                        <!-- Action Editor (hidden initially) -->
                        <div class="mxch-action-editor" id="mxch-action-editor" style="display: none;">
                            <!-- Step 1: Action Type Selection -->
                            <div id="mxch-editor-step-1" class="mxch-editor-step active">
                                <div class="mxch-editor-header">
                                    <button type="button" class="mxch-mobile-back-btn">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                                        <?php esc_html_e('Back', 'mxchat'); ?>
                                    </button>
                                    <h3 class="mxch-editor-title"><?php esc_html_e('Choose what it does', 'mxchat'); ?></h3>
                                    <button type="button" class="mxch-editor-close" id="mxch-close-editor">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>

                                <div class="mxch-type-search">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    <input type="text" id="mxch-type-search-input" placeholder="<?php esc_attr_e('Search types...', 'mxchat'); ?>">
                                </div>

                                <div class="mxch-type-categories">
                                    <button type="button" class="mxch-category-btn active" data-category="all"><?php esc_html_e('All', 'mxchat'); ?></button>
                                    <?php foreach ($callback_groups as $group_label => $group_callbacks): ?>
                                        <button type="button" class="mxch-category-btn" data-category="<?php echo esc_attr(sanitize_title($group_label)); ?>"><?php echo esc_html($group_label); ?></button>
                                    <?php endforeach; ?>
                                </div>

                                <div class="mxch-types-grid" id="mxch-types-grid">
                                    <?php foreach ($callback_groups as $group_label => $group_callbacks):
                                        $category_slug = sanitize_title($group_label);
                                        foreach ($group_callbacks as $function => $data):
                                            $label = $data['label'];
                                            $pro_only = $data['pro_only'];
                                            $icon = isset($data['icon']) ? $data['icon'] : 'admin-generic';
                                            $description = isset($data['description']) ? $data['description'] : '';
                                            $is_addon = isset($data['addon']) && $data['addon'] !== false;
                                            $addon_name = isset($data['addon_name']) ? $data['addon_name'] : '';
                                            $is_installed = isset($data['installed']) ? $data['installed'] : true;
                                    ?>
                                        <div class="mxch-type-card <?php echo (!$is_activated && $pro_only) ? 'mxch-type-pro' : ''; ?> <?php echo ($is_addon && !$is_installed) ? 'mxch-type-addon' : ''; ?>"
                                             data-category="<?php echo esc_attr($category_slug); ?>"
                                             data-value="<?php echo esc_attr($function); ?>"
                                             data-label="<?php echo esc_attr($label); ?>"
                                             data-pro="<?php echo $pro_only ? 'true' : 'false'; ?>"
                                             data-addon="<?php echo esc_attr($is_addon ? $data['addon'] : ''); ?>"
                                             data-installed="<?php echo $is_installed ? 'true' : 'false'; ?>">
                                            <div class="mxch-type-icon">
                                                <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
                                            </div>
                                            <div class="mxch-type-info">
                                                <h4><?php echo esc_html($label); ?></h4>
                                                <p><?php echo esc_html($description ?: sprintf(__('Use the %s action', 'mxchat'), $label)); ?></p>
                                                <?php if ($pro_only && !$is_activated): ?>
                                                    <span class="mxch-badge mxch-badge-pro"><?php esc_html_e('Pro', 'mxchat'); ?></span>
                                                <?php endif; ?>
                                                <?php if ($is_addon && !$is_installed): ?>
                                                    <span class="mxch-badge mxch-badge-addon"><?php echo esc_html(sprintf(__('Requires %s', 'mxchat'), $addon_name)); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; endforeach; ?>
                                </div>
                            </div>

                            <!-- Step 2: Action Configuration -->
                            <div id="mxch-editor-step-2" class="mxch-editor-step">
                                <div class="mxch-editor-header">
                                    <button type="button" class="mxch-back-btn" id="mxch-back-to-step-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                                        <?php esc_html_e('Back', 'mxchat'); ?>
                                    </button>
                                    <h3 class="mxch-editor-title" id="mxch-config-title"><?php esc_html_e('Configure Trigger Phrase', 'mxchat'); ?></h3>
                                    <button type="button" class="mxch-editor-close" id="mxch-close-editor-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>

                                <div class="mxch-selected-type">
                                    <div class="mxch-selected-type-icon" id="mxch-selected-type-icon">
                                        <span class="dashicons dashicons-admin-generic"></span>
                                    </div>
                                    <div class="mxch-selected-type-info">
                                        <h4 id="mxch-selected-type-label"><?php esc_html_e('Selected Type', 'mxchat'); ?></h4>
                                        <p id="mxch-selected-type-desc"><?php esc_html_e('Configure this trigger phrase for your chatbot', 'mxchat'); ?></p>
                                    </div>
                                </div>

                                <form id="mxch-action-form" method="post">
                                    <?php wp_nonce_field('mxchat_add_intent_nonce', 'mxch_action_nonce'); ?>
                                    <input type="hidden" name="action_id" id="mxch-action-id" value="">
                                    <input type="hidden" name="callback_function" id="mxch-callback-function" value="">
                                    <input type="hidden" name="form_mode" id="mxch-form-mode" value="add">

                                    <div class="mxch-form-group">
                                        <label for="mxch-action-label"><?php esc_html_e('Label', 'mxchat'); ?></label>
                                        <input type="text" id="mxch-action-label" name="intent_label" required placeholder="<?php esc_attr_e('e.g., Newsletter Signup', 'mxchat'); ?>">
                                        <p class="mxch-form-hint"><?php esc_html_e('A descriptive name for this trigger phrase (for your reference only).', 'mxchat'); ?></p>
                                    </div>

                                    <div class="mxch-form-group">
                                        <label><?php esc_html_e('Trigger Phrases', 'mxchat'); ?></label>
                                        <div class="mxch-phrase-input-wrapper">
                                            <div class="mxch-phrase-tags" id="mxch-phrase-tags">
                                                <!-- Phrase pills rendered dynamically -->
                                            </div>
                                            <div class="mxch-phrase-add-row">
                                                <input type="text" id="mxch-phrase-input" placeholder="<?php esc_attr_e('Type a trigger phrase and press Enter', 'mxchat'); ?>">
                                                <button type="button" class="mxch-btn mxch-btn-sm" id="mxch-add-phrase-btn"><?php esc_html_e('Add', 'mxchat'); ?></button>
                                            </div>
                                        </div>
                                        <p class="mxch-form-hint"><?php esc_html_e('Each phrase gets its own embedding vector for more accurate matching.', 'mxchat'); ?></p>
                                    </div>

                                    <div class="mxch-form-group">
                                        <label for="mxch-action-threshold">
                                            <?php esc_html_e('Similarity Threshold', 'mxchat'); ?>
                                            <span class="mxch-threshold-value" id="mxch-threshold-value">85%</span>
                                        </label>
                                        <input type="range" id="mxch-action-threshold" name="similarity_threshold" min="10" max="95" value="85">
                                        <p class="mxch-form-hint"><?php esc_html_e('Lower values (10-30) trigger more easily. Higher values (70-95) require more exact matches.', 'mxchat'); ?></p>
                                    </div>

                                    <div class="mxch-form-group">
                                        <label><?php esc_html_e('Enabled Bots', 'mxchat'); ?></label>
                                        <div class="mxch-bot-selector" id="mxch-bot-selector">
                                            <label class="mxch-checkbox-label">
                                                <input type="checkbox" name="enabled_bots[]" value="default" checked>
                                                <span class="mxch-checkmark"></span>
                                                <?php esc_html_e('Default Bot', 'mxchat'); ?>
                                            </label>
                                            <?php if (class_exists('MxChat_Multi_Bot_Core_Manager')):
                                                $multi_bot_manager = MxChat_Multi_Bot_Core_Manager::get_instance();
                                                $available_bots_list = $multi_bot_manager->get_available_bots();
                                                foreach ($available_bots_list as $bot_id => $bot_name):
                                                    if ($bot_id === 'default') continue;
                                            ?>
                                                <label class="mxch-checkbox-label">
                                                    <input type="checkbox" name="enabled_bots[]" value="<?php echo esc_attr($bot_id); ?>">
                                                    <span class="mxch-checkmark"></span>
                                                    <?php echo esc_html($bot_name); ?>
                                                </label>
                                            <?php endforeach; endif; ?>
                                        </div>
                                    </div>

                                    <div class="mxch-form-actions">
                                        <button type="button" class="mxch-btn mxch-btn-secondary" id="mxch-cancel-action">
                                            <?php esc_html_e('Cancel', 'mxchat'); ?>
                                        </button>
                                        <button type="submit" class="mxch-btn mxch-btn-primary" id="mxch-save-action">
                                            <?php esc_html_e('Save Trigger Phrase', 'mxchat'); ?>
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Action View (for viewing existing action details) -->
                            <div id="mxch-action-view" class="mxch-editor-step">
                                <div class="mxch-editor-header">
                                    <button type="button" class="mxch-mobile-back-btn">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                                        <?php esc_html_e('Back', 'mxchat'); ?>
                                    </button>
                                    <h3 class="mxch-editor-title" id="mxch-view-title"><?php esc_html_e('Trigger Phrase Details', 'mxchat'); ?></h3>
                                    <div class="mxch-header-actions">
                                        <label class="mxch-toggle-switch" title="<?php esc_attr_e('Toggle Enabled', 'mxchat'); ?>">
                                            <input type="checkbox" id="mxch-action-enabled-toggle" checked>
                                            <span class="mxch-toggle-slider"></span>
                                        </label>
                                        <button type="button" class="mxch-icon-btn" id="mxch-edit-action-btn" title="<?php esc_attr_e('Edit', 'mxchat'); ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                        <button type="button" class="mxch-icon-btn mxch-btn-danger-icon" id="mxch-delete-action-btn" title="<?php esc_attr_e('Delete', 'mxchat'); ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </div>
                                </div>

                                <div class="mxch-action-details">
                                    <div class="mxch-detail-card">
                                        <div class="mxch-detail-icon" id="mxch-view-icon">
                                            <span class="dashicons dashicons-admin-generic"></span>
                                        </div>
                                        <div class="mxch-detail-content">
                                            <h4 id="mxch-view-label"><?php esc_html_e('Label', 'mxchat'); ?></h4>
                                            <p class="mxch-detail-type" id="mxch-view-type"><?php esc_html_e('Type', 'mxchat'); ?></p>
                                        </div>
                                    </div>

                                    <div class="mxch-detail-section">
                                        <h5><?php esc_html_e('Trigger Phrases', 'mxchat'); ?></h5>
                                        <div class="mxch-phrases-list" id="mxch-view-phrases">
                                            <!-- Phrases loaded dynamically -->
                                        </div>
                                    </div>

                                    <div class="mxch-detail-section">
                                        <h5><?php esc_html_e('Settings', 'mxchat'); ?></h5>
                                        <div class="mxch-settings-grid">
                                            <div class="mxch-setting-item">
                                                <span class="mxch-setting-label"><?php esc_html_e('Similarity Threshold', 'mxchat'); ?></span>
                                                <span class="mxch-setting-value" id="mxch-view-threshold">85%</span>
                                            </div>
                                            <div class="mxch-setting-item">
                                                <span class="mxch-setting-label"><?php esc_html_e('Status', 'mxchat'); ?></span>
                                                <span class="mxch-setting-value mxch-status-badge" id="mxch-view-status"><?php esc_html_e('Enabled', 'mxchat'); ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mxch-detail-section">
                                        <h5><?php esc_html_e('Assigned Bots', 'mxchat'); ?></h5>
                                        <div class="mxch-bots-list" id="mxch-view-bots">
                                            <!-- Bots loaded dynamically -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AI Tools Section (native function calling) -->
            <!-- No content-header band here — the section starts directly at the
                 list+detail split panel, exactly like the Trigger Phrases section
                 (#all-actions) above, for pixel parity (plan 5f7409). The per-
                 approach explainer already lives on the Dashboard landing. -->
            <div id="ai-tools" class="mxch-section <?php echo (isset($active_tab) && $active_tab === 'ai-tools') ? 'active' : ''; ?>">
                <div id="mxch-fc-settings" data-fc-nonce="<?php echo esc_attr(wp_create_nonce('mxchat_fc_autosave')); ?>">

                    <!-- Model-not-capable notice (relocated from the removed master toggle).
                         Tools can still be added; they just won't fire until a tool-capable
                         model is selected — so a user on a non-tool model isn't met with silence. -->
                    <?php if (empty($fc_model_capable)): ?>
                    <div class="mxch-notice mxch-notice-warning" style="margin-bottom: 20px;">
                        <svg class="mxch-notice-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <div><?php printf(esc_html__('Your current chat model (%s) does not support tools. You can still add tools here, but they will not fire until you choose a tool-capable model in Settings.', 'mxchat'), '<strong>' . esc_html($fc_current_model) . '</strong>'); ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Brave-key-not-set notice (plan 183856). Web Search + Image Search
                         run on the Brave Search API; if either is enabled as a tool with no
                         brave_api_key configured, they silently won't fire — so the admin
                         gets a clear setup cue instead. Mirrors the model-not-capable notice. -->
                    <?php if (!empty($fc_brave_missing)): ?>
                    <div class="mxch-notice mxch-notice-warning" style="margin-bottom: 20px;">
                        <svg class="mxch-notice-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <div><?php printf(esc_html__('Web Search and Image Search are powered by Brave Search and need a Brave Search API key to work. Add your key under %s to enable them.', 'mxchat'), '<strong>' . esc_html__('Settings → Brave Search', 'mxchat') . '</strong>'); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (empty($fc_tools)): ?>
                        <div class="mxch-card">
                            <div class="mxch-card-body">
                                <p class="mxch-fc-intro"><?php esc_html_e('No tools available yet. Core search and image tools appear here, and activating add-ons like WooCommerce adds more.', 'mxchat'); ?></p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php
                        // Hidden store — the SINGLE source of truth for autosave. Each
                        // .mxch-fc-tool keeps its hidden marker + checkbox + usage-hint
                        // textarea exactly as the per-tool checklist did, so fcCollectAndSave()
                        // and the mxchat_fc_autosave endpoint are UNCHANGED (HARD: no data-model
                        // change). The master-detail list + detail panel below only toggle these
                        // inputs. A tool whose checkbox is checked = active (plan d450a7) — there
                        // is no global toggle and no per-tool enable checkbox in the UI anymore.
                        $fc_hint_max = (int) (class_exists('MxChat_Tool_Registry') ? MxChat_Tool_Registry::HINT_MAX : 500);
                        ?>
                        <div id="mxch-fc-tool-store" class="mxch-fc-tool-store" hidden aria-hidden="true">
                            <?php foreach ($fc_tools as $fc_tool):
                                $fc_cb   = $fc_tool['callback'];
                                $fc_on   = !empty($fc_tool['enabled']);
                                $fc_icon = isset($fc_tool['icon']) ? $fc_tool['icon'] : 'admin-generic';
                            ?>
                                <div class="mxch-fc-tool"
                                     data-fc-callback="<?php echo esc_attr($fc_cb); ?>"
                                     data-fc-label="<?php echo esc_attr($fc_tool['label']); ?>"
                                     data-fc-desc="<?php echo esc_attr($fc_tool['description']); ?>"
                                     data-fc-setup="<?php echo esc_attr(isset($fc_tool['setup_note']) ? $fc_tool['setup_note'] : ''); ?>"
                                     data-fc-icon="<?php echo esc_attr($fc_icon); ?>"
                                     data-fc-addon="<?php echo !empty($fc_tool['is_addon']) ? '1' : '0'; ?>"
                                     data-fc-cautious="<?php echo !empty($fc_tool['cautious']) ? '1' : '0'; ?>">
                                    <input type="hidden" name="mxchat_fc_all_tools[]" value="<?php echo esc_attr($fc_cb); ?>">
                                    <input type="checkbox" name="mxchat_fc_tools[]" value="<?php echo esc_attr($fc_cb); ?>" <?php checked($fc_on); ?>>
                                    <textarea class="mxch-fc-hint-input" data-fc-callback="<?php echo esc_attr($fc_cb); ?>" maxlength="<?php echo $fc_hint_max; ?>"><?php echo esc_textarea(isset($fc_tool['usage_hint']) ? $fc_tool['usage_hint'] : ''); ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Master-detail split panel — clones the Trigger Phrases list+detail
                             component (same mxch-* classes/CSS) so AI Tools reads as the same surface.
                             FC-specific IDs keep the Trigger Phrases JS off these elements. -->
                        <div class="mxch-split-panel"
                             id="mxch-fc-panel"
                             data-i18n-tools="<?php esc_attr_e('tools', 'mxchat'); ?>"
                             data-i18n-tool="<?php esc_attr_e('tool', 'mxchat'); ?>"
                             data-i18n-active="<?php esc_attr_e('Active', 'mxchat'); ?>"
                             data-i18n-addon="<?php esc_attr_e('Add-on', 'mxchat'); ?>"
                             data-i18n-sensitive="<?php esc_attr_e('Sensitive', 'mxchat'); ?>"
                             data-i18n-nohint="<?php esc_attr_e('No usage note — the AI decides on its own when to use this.', 'mxchat'); ?>">

                            <!-- Left: tool list -->
                            <div class="mxch-action-list-panel">
                                <!-- Bulk Actions Toolbar — exact clone of the Trigger Phrases
                                     #all-actions toolbar (All select-all + bulk-remove trash),
                                     FC-specific IDs so the Trigger Phrases JS stays off it (plan 5f7409). -->
                                <div class="mxch-bulk-toolbar">
                                    <label class="mxch-bulk-select-all">
                                        <input type="checkbox" id="mxch-fc-select-all">
                                        <span><?php esc_html_e('All', 'mxchat'); ?></span>
                                    </label>
                                    <span class="mxch-selected-count" id="mxch-fc-selected-count">0</span>
                                    <div class="mxch-bulk-actions">
                                        <button type="button" class="mxch-bulk-btn mxch-bulk-delete" id="mxch-fc-delete-selected" disabled title="<?php esc_attr_e('Remove Selected', 'mxchat'); ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </div>
                                </div>
                                <div class="mxch-panel-header">
                                    <div class="mxch-search-wrapper">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                        <input type="text" id="mxch-fc-search" class="mxch-search-input" placeholder="<?php esc_attr_e('Search tools...', 'mxchat'); ?>">
                                    </div>
                                    <div class="mxch-panel-title-row">
                                        <span class="mxch-panel-count" id="mxch-fc-count">0 <?php esc_html_e('tools', 'mxchat'); ?></span>
                                        <div class="mxch-panel-actions">
                                            <button type="button" id="mxch-fc-add-btn" class="mxch-btn mxch-btn-primary mxch-btn-sm js-mxch-fc-add" title="<?php esc_attr_e('Add Tool', 'mxchat'); ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                                <?php esc_html_e('Add', 'mxchat'); ?>
                                            </button>
                                            <button type="button" id="mxch-fc-refresh" class="mxch-icon-btn" title="<?php esc_attr_e('Refresh', 'mxchat'); ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="mxch-action-list" id="mxch-fc-list">
                                    <!-- Tool rows built by JS from the hidden store (enabled tools only) -->
                                </div>
                                <div class="mxch-panel-footer">
                                    <span id="mxch-fc-save-status" class="mxch-fc-save-status" role="status" aria-live="polite"></span>
                                </div>
                            </div>

                            <!-- Right: detail / view panel -->
                            <div class="mxch-action-detail-panel" id="mxch-fc-detail-panel">
                                <!-- Empty state -->
                                <div class="mxch-action-empty" id="mxch-fc-empty">
                                    <div class="mxch-empty-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                                    </div>
                                    <h3><?php esc_html_e('Select a tool', 'mxchat'); ?></h3>
                                    <p><?php esc_html_e('Choose a tool from the list to view details and edit when the AI should use it, or add a new one.', 'mxchat'); ?></p>
                                    <button type="button" class="mxch-btn mxch-btn-primary js-mxch-fc-add">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                        <?php esc_html_e('Add Tool', 'mxchat'); ?>
                                    </button>
                                </div>

                                <!-- Tool view (shown when a tool is selected) -->
                                <div class="mxch-action-editor" id="mxch-fc-view" style="display: none;">
                                    <div class="mxch-editor-header">
                                        <button type="button" class="mxch-mobile-back-btn">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                                            <?php esc_html_e('Back', 'mxchat'); ?>
                                        </button>
                                        <h3 class="mxch-editor-title"><?php esc_html_e('Tool Details', 'mxchat'); ?></h3>
                                        <div class="mxch-header-actions">
                                            <button type="button" class="mxch-icon-btn" id="mxch-fc-edit-btn" title="<?php esc_attr_e('Edit usage note', 'mxchat'); ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </button>
                                            <button type="button" class="mxch-icon-btn mxch-btn-danger-icon" id="mxch-fc-remove-btn" title="<?php esc_attr_e('Remove tool', 'mxchat'); ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="mxch-action-details">
                                        <div class="mxch-detail-card">
                                            <div class="mxch-detail-icon" id="mxch-fc-view-icon">
                                                <span class="dashicons dashicons-admin-generic"></span>
                                            </div>
                                            <div class="mxch-detail-content">
                                                <h4 id="mxch-fc-view-label"><?php esc_html_e('Tool', 'mxchat'); ?></h4>
                                                <p class="mxch-detail-type" id="mxch-fc-view-type"></p>
                                            </div>
                                        </div>

                                        <div class="mxch-detail-section">
                                            <h5><?php esc_html_e('What it does', 'mxchat'); ?></h5>
                                            <p class="mxch-fc-view-desc" id="mxch-fc-view-desc"></p>
                                        </div>

                                        <div class="mxch-detail-section">
                                            <h5><?php esc_html_e('When the assistant uses this', 'mxchat'); ?></h5>
                                            <p class="mxch-fc-view-hint" id="mxch-fc-view-hint"></p>
                                        </div>

                                        <!-- Setup requirement (plan 183856) — only shown for tools that
                                             declare a setup_note (e.g. Web/Image Search → Brave key). JS
                                             toggles this section based on the tool's data-fc-setup. -->
                                        <div class="mxch-detail-section" id="mxch-fc-view-setup-wrap" style="display:none;">
                                            <h5><?php esc_html_e('Setup', 'mxchat'); ?></h5>
                                            <p class="mxch-fc-view-setup" id="mxch-fc-view-setup"></p>
                                        </div>

                                        <div class="mxch-detail-section">
                                            <h5><?php esc_html_e('Status', 'mxchat'); ?></h5>
                                            <div class="mxch-settings-grid">
                                                <div class="mxch-setting-item">
                                                    <span class="mxch-setting-label"><?php esc_html_e('State', 'mxchat'); ?></span>
                                                    <span class="mxch-setting-value mxch-status-badge"><?php esc_html_e('Active', 'mxchat'); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Loading Overlay -->
    <div id="mxch-action-loading" class="mxch-loading-overlay" style="display: none;">
        <div class="mxch-loading-spinner"></div>
        <div class="mxch-loading-text"><?php esc_html_e('Saving, please wait...', 'mxchat'); ?></div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="mxch-delete-modal" class="mxch-modal-overlay" style="display: none;">
        <div class="mxch-modal-content mxch-modal-sm">
            <div class="mxch-modal-header">
                <h2><?php esc_html_e('Delete Trigger Phrase', 'mxchat'); ?></h2>
                <button type="button" class="mxch-modal-close">&times;</button>
            </div>
            <div class="mxch-modal-body">
                <p><?php esc_html_e('Are you sure you want to delete this trigger phrase? This cannot be undone.', 'mxchat'); ?></p>
            </div>
            <div class="mxch-modal-footer">
                <button type="button" class="mxch-btn mxch-btn-secondary" id="mxch-cancel-delete"><?php esc_html_e('Cancel', 'mxchat'); ?></button>
                <button type="button" class="mxch-btn mxch-btn-danger" id="mxch-confirm-delete"><?php esc_html_e('Delete', 'mxchat'); ?></button>
            </div>
        </div>
    </div>

    <!-- Add-Tool modal (AI Tools card flow, plan 8bbf98 part 4) -->
    <div id="mxch-fc-tool-modal" class="mxch-modal-overlay" style="display: none;">
        <div class="mxch-modal-content mxch-modal-lg">
            <!-- Step 1: pick a capability -->
            <div class="mxch-fc-modal-step" data-step="1">
                <div class="mxch-modal-header">
                    <h2><?php esc_html_e('Add a Tool', 'mxchat'); ?></h2>
                    <button type="button" class="mxch-modal-close" data-fc-modal-close>&times;</button>
                </div>
                <div class="mxch-modal-body">
                    <p class="mxch-fc-intro"><?php esc_html_e('Pick a capability the AI can call from the conversation.', 'mxchat'); ?></p>
                    <div class="mxch-types-grid mxch-fc-modal-grid" id="mxch-fc-modal-grid"></div>
                    <p class="mxch-fc-modal-allset" id="mxch-fc-modal-allset" style="display:none;"><?php esc_html_e('Every available tool is already added. Activate add-ons like WooCommerce to unlock more.', 'mxchat'); ?></p>
                </div>
            </div>
            <!-- Step 2: confirm + when-to-use -->
            <div class="mxch-fc-modal-step" data-step="2" style="display:none;">
                <div class="mxch-modal-header">
                    <button type="button" class="mxch-back-btn" id="mxch-fc-modal-back">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                        <?php esc_html_e('Back', 'mxchat'); ?>
                    </button>
                    <h2 id="mxch-fc-modal-step2-title" data-add-title="<?php esc_attr_e('Add Tool', 'mxchat'); ?>" data-edit-title="<?php esc_attr_e('Edit Tool', 'mxchat'); ?>"><?php esc_html_e('Add Tool', 'mxchat'); ?></h2>
                    <button type="button" class="mxch-modal-close" data-fc-modal-close>&times;</button>
                </div>
                <div class="mxch-modal-body">
                    <div class="mxch-selected-type">
                        <div class="mxch-selected-type-icon" id="mxch-fc-modal-selected-icon">
                            <span class="dashicons dashicons-admin-generic"></span>
                        </div>
                        <div class="mxch-selected-type-info">
                            <h4 id="mxch-fc-modal-selected-label"><?php esc_html_e('Selected tool', 'mxchat'); ?></h4>
                            <p id="mxch-fc-modal-selected-desc"></p>
                        </div>
                    </div>
                    <div class="mxch-form-group">
                        <label for="mxch-fc-modal-hint"><?php esc_html_e('When should the assistant use this?', 'mxchat'); ?></label>
                        <textarea id="mxch-fc-modal-hint" class="mxch-fc-modal-hint-input" rows="3" maxlength="<?php echo (int) (class_exists('MxChat_Tool_Registry') ? MxChat_Tool_Registry::HINT_MAX : 500); ?>" placeholder="<?php esc_attr_e('Optional — e.g. “Use only when the visitor asks about pricing or discounts.”', 'mxchat'); ?>"></textarea>
                        <p class="mxch-form-hint"><?php esc_html_e('Leave blank to let the AI decide on its own. Your note is added to what the model knows about this tool.', 'mxchat'); ?></p>
                    </div>
                    <div class="mxch-fc-modal-sensitive-note" id="mxch-fc-modal-sensitive" style="display:none;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <span><?php esc_html_e('Sensitive tool — it can spend money, expose customer data, or hand off to a human. Only add it if you want the AI to do that.', 'mxchat'); ?></span>
                    </div>
                </div>
                <div class="mxch-modal-footer">
                    <button type="button" class="mxch-btn mxch-btn-secondary" data-fc-modal-close><?php esc_html_e('Cancel', 'mxchat'); ?></button>
                    <button type="button" class="mxch-btn mxch-btn-primary" id="mxch-fc-modal-save" data-add-label="<?php esc_attr_e('Add Tool', 'mxchat'); ?>" data-save-label="<?php esc_attr_e('Save Changes', 'mxchat'); ?>"><?php esc_html_e('Add Tool', 'mxchat'); ?></button>
                </div>
            </div>
        </div>
    </div>

    <?php
}
