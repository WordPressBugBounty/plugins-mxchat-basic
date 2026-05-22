<?php
/**
 * MxChat → API Access admin page.
 *
 * Lets the site owner generate, view, and revoke the bearer token used by
 * the REST endpoints in class-rest-api.php. The token is stored in
 * wp_options under `mxchat_api_token` and is empty by default — until the
 * owner clicks "Generate Token", all REST endpoints refuse with 401.
 *
 * @package MxChat
 * @since   3.2.5
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('MXCHAT_API_TOKEN_OPTION')) {
    define('MXCHAT_API_TOKEN_OPTION', 'mxchat_api_token');
}

/**
 * Register the submenu under the existing MxChat menu.
 * Priority 20 so it lands after the menu is already set up.
 */
add_action('admin_menu', 'mxchat_register_api_admin_page', 20);
function mxchat_register_api_admin_page() {
    add_submenu_page(
        'mxchat-max',
        esc_html__('MxChat API Access', 'mxchat'),
        esc_html__('API Access', 'mxchat'),
        'manage_options',
        'mxchat-api-access',
        'mxchat_render_api_admin_page'
    );
}

/**
 * Generate a new token and store it.
 */
add_action('admin_post_mxchat_rotate_api_token', 'mxchat_handle_rotate_api_token');
function mxchat_handle_rotate_api_token() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'mxchat'));
    }
    check_admin_referer('mxchat_rotate_api_token');

    $token = wp_generate_password(48, false, false);
    update_option(MXCHAT_API_TOKEN_OPTION, $token, false);
    update_option('mxchat_api_token_rotated_at', time(), false);

    set_transient('mxchat_api_token_just_rotated', $token, 60);
    wp_safe_redirect(add_query_arg(array('page' => 'mxchat-api-access', 'rotated' => 1), admin_url('admin.php')));
    exit;
}

/**
 * Revoke the existing token (sets the option to empty string).
 */
add_action('admin_post_mxchat_revoke_api_token', 'mxchat_handle_revoke_api_token');
function mxchat_handle_revoke_api_token() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'mxchat'));
    }
    check_admin_referer('mxchat_revoke_api_token');

    update_option(MXCHAT_API_TOKEN_OPTION, '', false);
    delete_option('mxchat_api_token_rotated_at');

    wp_safe_redirect(add_query_arg(array('page' => 'mxchat-api-access', 'revoked' => 1), admin_url('admin.php')));
    exit;
}

/**
 * Render the API Access admin page.
 */
function mxchat_render_api_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mxchat'));
    }

    $stored_token  = (string) get_option(MXCHAT_API_TOKEN_OPTION, '');
    $token_set     = $stored_token !== '';
    $just_rotated  = get_transient('mxchat_api_token_just_rotated');
    delete_transient('mxchat_api_token_just_rotated');
    $rotated_query = isset($_GET['rotated']) ? (bool) $_GET['rotated'] : false;
    $revoked_query = isset($_GET['revoked']) ? (bool) $_GET['revoked'] : false;
    $rotated_at    = (int) get_option('mxchat_api_token_rotated_at', 0);

    $base_url      = trailingslashit(get_rest_url(null, 'mxchat/v1'));
    $health_url    = $base_url . 'health';
    $transcripts_u = $base_url . 'transcripts';
    $knowledge_url = $base_url . 'knowledge';

    $plugin_url    = plugin_dir_url(dirname(__FILE__));
    $masked        = $token_set
        ? substr($stored_token, 0, 4) . str_repeat('•', 24) . substr($stored_token, -4)
        : '';
    $rotated_human = $rotated_at > 0
        ? sprintf(
            /* translators: %s: human-readable elapsed time */
            esc_html__('%s ago', 'mxchat'),
            human_time_diff($rotated_at, current_time('timestamp'))
        )
        : esc_html__('Never', 'mxchat');
    ?>
    <div class="mxch-admin-wrapper mxch-api-wrapper">

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
                <span class="mxch-mobile-menu-title"><?php esc_html_e('API Access', 'mxchat'); ?></span>
                <button type="button" class="mxch-mobile-menu-close" aria-label="<?php esc_attr_e('Close menu', 'mxchat'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <nav class="mxch-mobile-menu-nav">
                <div class="mxch-mobile-nav-section">
                    <div class="mxch-mobile-nav-section-title"><?php esc_html_e('REST API', 'mxchat'); ?></div>
                    <button class="mxch-mobile-nav-link active" data-target="api-token">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 2-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                        <span><?php esc_html_e('Token', 'mxchat'); ?></span>
                    </button>
                    <button class="mxch-mobile-nav-link" data-target="api-endpoints">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                        <span><?php esc_html_e('Endpoints', 'mxchat'); ?></span>
                    </button>
                    <button class="mxch-mobile-nav-link" data-target="api-examples">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
                        <span><?php esc_html_e('Examples', 'mxchat'); ?></span>
                    </button>
                    <button class="mxch-mobile-nav-link" data-target="api-privacy">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <span><?php esc_html_e('Privacy', 'mxchat'); ?></span>
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
                    <span class="mxch-sidebar-version">v<?php echo esc_html(MXCHAT_VERSION ?? '2.7.0'); ?></span>
                </a>
            </div>

            <nav class="mxch-sidebar-nav">
                <div class="mxch-nav-section">
                    <div class="mxch-nav-section-title"><?php esc_html_e('REST API', 'mxchat'); ?></div>

                    <div class="mxch-nav-item" data-section="api-token">
                        <button class="mxch-nav-link active" data-target="api-token">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 2-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Token', 'mxchat'); ?></span>
                            <?php if ($token_set): ?>
                                <span class="mxch-nav-link-badge mxch-active-badge"><?php esc_html_e('Active', 'mxchat'); ?></span>
                            <?php endif; ?>
                        </button>
                    </div>

                    <div class="mxch-nav-item" data-section="api-endpoints">
                        <button class="mxch-nav-link" data-target="api-endpoints">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Endpoints', 'mxchat'); ?></span>
                        </button>
                    </div>

                    <div class="mxch-nav-item" data-section="api-examples">
                        <button class="mxch-nav-link" data-target="api-examples">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Examples', 'mxchat'); ?></span>
                        </button>
                    </div>

                    <div class="mxch-nav-item" data-section="api-privacy">
                        <button class="mxch-nav-link" data-target="api-privacy">
                            <span class="mxch-nav-link-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            </span>
                            <span class="mxch-nav-link-text"><?php esc_html_e('Privacy', 'mxchat'); ?></span>
                        </button>
                    </div>
                </div>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="mxch-content">

            <?php if ($rotated_query && $just_rotated): ?>
                <div class="notice notice-success is-dismissible mxch-api-flash">
                    <p>
                        <strong><?php esc_html_e('New API token generated.', 'mxchat'); ?></strong>
                        <?php esc_html_e('Copy it now — for security, the full value will not be shown again after you leave this page.', 'mxchat'); ?>
                    </p>
                    <p>
                        <code class="mxch-api-token-reveal" data-mxch-copy-target><?php echo esc_html($just_rotated); ?></code>
                        <button type="button" class="mxch-btn mxch-btn-secondary mxch-btn-sm mxch-api-copy-btn" data-mxch-copy="<?php echo esc_attr($just_rotated); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            <span><?php esc_html_e('Copy', 'mxchat'); ?></span>
                        </button>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($revoked_query): ?>
                <div class="notice notice-warning is-dismissible mxch-api-flash">
                    <p>
                        <strong><?php esc_html_e('API token revoked.', 'mxchat'); ?></strong>
                        <?php esc_html_e('All REST endpoints will return 401 until a new token is generated.', 'mxchat'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Token Section -->
            <div id="api-token" class="mxch-section active">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('REST API', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle">
                        <?php esc_html_e('Bearer-token-authenticated endpoints for reading transcripts and pushing content into the knowledge base. Disabled until you generate a token below.', 'mxchat'); ?>
                    </p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title">
                            <svg class="mxch-card-title-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 2-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                            <?php esc_html_e('Token', 'mxchat'); ?>
                        </h3>
                        <?php if ($token_set): ?>
                            <span class="mxch-status-pill mxch-status-pill-active">
                                <span class="mxch-status-dot"></span>
                                <?php esc_html_e('Active', 'mxchat'); ?>
                            </span>
                        <?php else: ?>
                            <span class="mxch-status-pill mxch-status-pill-disabled">
                                <span class="mxch-status-dot"></span>
                                <?php esc_html_e('Disabled', 'mxchat'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="mxch-card-body">
                        <?php if ($token_set): ?>
                            <div class="mxch-field">
                                <div class="mxch-field-label"><?php esc_html_e('Current token', 'mxchat'); ?></div>
                                <code class="mxch-api-token-masked"><?php echo esc_html($masked); ?></code>
                                <p class="mxch-field-description">
                                    <?php esc_html_e('Last rotated:', 'mxchat'); ?>
                                    <strong><?php echo esc_html($rotated_human); ?></strong>
                                </p>
                            </div>
                        <?php else: ?>
                            <p class="mxch-field-description">
                                <?php esc_html_e('No token is currently set. Generate one below to enable the authenticated REST endpoints.', 'mxchat'); ?>
                            </p>
                        <?php endif; ?>

                        <div class="mxch-api-actions">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mxch-api-form">
                                <input type="hidden" name="action" value="mxchat_rotate_api_token" />
                                <?php wp_nonce_field('mxchat_rotate_api_token'); ?>
                                <button type="submit" class="mxch-btn mxch-btn-primary">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                                    <?php echo $token_set ? esc_html__('Regenerate Token', 'mxchat') : esc_html__('Generate Token', 'mxchat'); ?>
                                </button>
                            </form>

                            <?php if ($token_set): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mxch-api-form"
                                      onsubmit="return confirm('<?php echo esc_js(__('Revoke the API token? Any external tool using the current token will stop working immediately.', 'mxchat')); ?>');">
                                    <input type="hidden" name="action" value="mxchat_revoke_api_token" />
                                    <?php wp_nonce_field('mxchat_revoke_api_token'); ?>
                                    <button type="submit" class="mxch-btn mxch-btn-danger">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        <?php esc_html_e('Revoke Token', 'mxchat'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <p class="mxch-field-hint">
                            <?php esc_html_e('Regenerating immediately invalidates the old token. Save the new value somewhere safe (password manager) — only the masked version is shown after you leave this page.', 'mxchat'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Endpoints Section -->
            <div id="api-endpoints" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Endpoints', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('All endpoints live under the WordPress REST namespace mxchat/v1.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-body">
                        <table class="mxch-api-endpoints-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Method', 'mxchat'); ?></th>
                                    <th><?php esc_html_e('URL', 'mxchat'); ?></th>
                                    <th><?php esc_html_e('Auth', 'mxchat'); ?></th>
                                    <th><?php esc_html_e('Purpose', 'mxchat'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><span class="mxch-api-method mxch-api-method-get">GET</span></td>
                                    <td><code><?php echo esc_html($health_url); ?></code></td>
                                    <td><span class="mxch-api-auth mxch-api-auth-none"><?php esc_html_e('None', 'mxchat'); ?></span></td>
                                    <td><?php esc_html_e('Connectivity check; reports plugin version and whether a token is set.', 'mxchat'); ?></td>
                                </tr>
                                <tr>
                                    <td><span class="mxch-api-method mxch-api-method-get">GET</span></td>
                                    <td><code><?php echo esc_html($transcripts_u); ?></code></td>
                                    <td><span class="mxch-api-auth mxch-api-auth-bearer"><?php esc_html_e('Bearer', 'mxchat'); ?></span></td>
                                    <td><?php esc_html_e('Read chat transcripts. Filterable by since, until, session_id, role, has_rag_context; supports limit + offset pagination.', 'mxchat'); ?></td>
                                </tr>
                                <tr>
                                    <td><span class="mxch-api-method mxch-api-method-post">POST</span></td>
                                    <td><code><?php echo esc_html($knowledge_url); ?></code></td>
                                    <td><span class="mxch-api-auth mxch-api-auth-bearer"><?php esc_html_e('Bearer', 'mxchat'); ?></span></td>
                                    <td><?php esc_html_e('Push content into the knowledge base. Body: content, source_url, optional bot_id and content_type.', 'mxchat'); ?></td>
                                </tr>
                                <tr>
                                    <td><span class="mxch-api-method mxch-api-method-delete">DELETE</span></td>
                                    <td><code><?php echo esc_html($transcripts_u); ?></code></td>
                                    <td><span class="mxch-api-auth mxch-api-auth-bearer"><?php esc_html_e('Bearer', 'mxchat'); ?></span></td>
                                    <td><?php esc_html_e('Bulk-delete chat sessions by session_id, with optional cascade to translations and click-tracking. Capped at 1000 per call.', 'mxchat'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Examples Section -->
            <div id="api-examples" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Examples', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('Drop-in cURL snippets — replace YOUR_TOKEN with the value from the Token tab.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title"><?php esc_html_e('Health check', 'mxchat'); ?></h3>
                        <span class="mxch-card-subtitle"><?php esc_html_e('No auth required', 'mxchat'); ?></span>
                    </div>
                    <div class="mxch-card-body">
                        <pre class="mxch-api-codeblock"><code>curl <?php echo esc_html($health_url); ?></code></pre>
                    </div>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title"><?php esc_html_e('Find transcripts where the bot had no knowledge to ground answers', 'mxchat'); ?></h3>
                    </div>
                    <div class="mxch-card-body">
                        <pre class="mxch-api-codeblock"><code>curl -H "Authorization: Bearer YOUR_TOKEN" \
  "<?php echo esc_html($transcripts_u); ?>?role=user&amp;has_rag_context=no&amp;since=2026-05-01&amp;limit=50"</code></pre>
                    </div>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title"><?php esc_html_e('Push a Q&A entry into the knowledge base', 'mxchat'); ?></h3>
                    </div>
                    <div class="mxch-card-body">
                        <pre class="mxch-api-codeblock"><code>curl -X POST -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"content":"Q: How do I enable streaming?\nA: ...","source_url":"https://example.com/faq#streaming","content_type":"faq"}' \
  "<?php echo esc_html($knowledge_url); ?>"</code></pre>
                    </div>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-header">
                        <h3 class="mxch-card-title"><?php esc_html_e('Bulk-delete two chat sessions', 'mxchat'); ?></h3>
                    </div>
                    <div class="mxch-card-body">
                        <pre class="mxch-api-codeblock"><code>curl -X DELETE -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"session_ids":["sess_abc","sess_def"],"cascade":true}' \
  "<?php echo esc_html($transcripts_u); ?>"</code></pre>
                    </div>
                </div>
            </div>

            <!-- Privacy Section -->
            <div id="api-privacy" class="mxch-section">
                <div class="mxch-content-header">
                    <h1 class="mxch-content-title"><?php esc_html_e('Privacy & Safety', 'mxchat'); ?></h1>
                    <p class="mxch-content-subtitle"><?php esc_html_e('What the API exposes — and how to keep it safe.', 'mxchat'); ?></p>
                </div>

                <div class="mxch-card">
                    <div class="mxch-card-body">
                        <ul class="mxch-api-privacy-list">
                            <li>
                                <svg class="mxch-api-bullet" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                <span><?php esc_html_e('No data leaves your site unsolicited. Endpoints only respond to requests carrying your token.', 'mxchat'); ?></span>
                            </li>
                            <li>
                                <svg class="mxch-api-bullet" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <span><?php esc_html_e('The /transcripts endpoint may return user-submitted chat data including emails and names — treat your API token as sensitive.', 'mxchat'); ?></span>
                            </li>
                            <li>
                                <svg class="mxch-api-bullet" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <span><?php esc_html_e('Use HTTPS only. Do not include the token in URL query strings.', 'mxchat'); ?></span>
                            </li>
                            <li>
                                <svg class="mxch-api-bullet" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                <span><?php esc_html_e('Rotate the token if you suspect it was leaked. Old token is invalidated immediately.', 'mxchat'); ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <style>
        /* Page-specific touches that build on admin-sidebar.css */
        .mxch-api-flash { margin: 0 0 var(--mxch-spacing-lg, 16px) 0; }
        .mxch-api-token-reveal {
            display: inline-block;
            padding: 8px 12px;
            background: var(--mxch-primary-lighter, #f0f6fc);
            border: 1px solid var(--mxch-card-border, #c3c4c7);
            border-radius: var(--mxch-radius-md, 6px);
            font-size: 13px;
            user-select: all;
            word-break: break-all;
            margin-right: 8px;
        }
        .mxch-api-token-masked {
            display: inline-block;
            padding: 6px 10px;
            background: #f6f7f7;
            border: 1px solid var(--mxch-card-border, #e2e4e9);
            border-radius: var(--mxch-radius-sm, 4px);
            font-size: 13px;
            color: var(--mxch-text-primary, #1d2327);
        }
        .mxch-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .mxch-status-pill .mxch-status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        .mxch-status-pill-active { background: #e6f4ea; color: #1d7a3a; }
        .mxch-status-pill-active .mxch-status-dot { background: #1d7a3a; }
        .mxch-status-pill-disabled { background: #fef3e2; color: #a04a00; }
        .mxch-status-pill-disabled .mxch-status-dot { background: #a04a00; }

        .mxch-api-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
        .mxch-api-form { display: inline; margin: 0; }

        .mxch-api-endpoints-table { width: 100%; border-collapse: collapse; }
        .mxch-api-endpoints-table th,
        .mxch-api-endpoints-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--mxch-card-border, #e2e4e9);
            vertical-align: top;
            font-size: 13px;
        }
        .mxch-api-endpoints-table th {
            font-weight: 600;
            color: var(--mxch-text-secondary, #50575e);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 11px;
            background: #fafbfc;
        }
        .mxch-api-endpoints-table tr:last-child td { border-bottom: none; }
        .mxch-api-endpoints-table code {
            background: #f6f7f7;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }

        .mxch-api-method {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .mxch-api-method-get    { background: #e7f0fb; color: #1a56b4; }
        .mxch-api-method-post   { background: #e6f4ea; color: #1d7a3a; }
        .mxch-api-method-delete { background: #fce4e4; color: #b32020; }

        .mxch-api-auth {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .mxch-api-auth-none   { background: #f0f0f0; color: #50575e; }
        .mxch-api-auth-bearer { background: #fff4e0; color: #8a5a00; }

        .mxch-api-codeblock {
            margin: 0;
            background: #1f2328;
            color: #e6edf3;
            padding: 14px 16px;
            border-radius: var(--mxch-radius-md, 6px);
            overflow-x: auto;
            font-size: 12.5px;
            line-height: 1.55;
        }
        .mxch-api-codeblock code { background: transparent; color: inherit; padding: 0; font-size: inherit; }

        .mxch-api-privacy-list { list-style: none; margin: 0; padding: 0; }
        .mxch-api-privacy-list li {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 10px 0;
            font-size: 14px;
            color: var(--mxch-text-primary, #1d2327);
            line-height: 1.5;
        }
        .mxch-api-privacy-list li + li { border-top: 1px solid var(--mxch-card-border, #e2e4e9); }
        .mxch-api-bullet { flex-shrink: 0; margin-top: 2px; color: var(--mxch-primary, #7873f5); }

        .mxch-card-subtitle {
            font-size: 12px;
            color: var(--mxch-text-secondary, #50575e);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
    </style>
    <?php
    // Tab switcher / mobile menu / copy-to-clipboard are wired by the shared
    // mxchat-basic/js/admin-sidebar.js, enqueued in class-mxchat-admin.php for
    // the mxchat-api-access screen.
}
